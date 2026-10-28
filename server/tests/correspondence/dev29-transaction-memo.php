<?php
declare(strict_types=1);

// Loads the frozen dev29 service for in-memory actor projection checks; source-pin acceptance is in dev29-source-contract.php.

$ownerRoot = '';
$adapterPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'correspondence.php';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--owner-root' && isset($argv[$i + 1])) {
        $ownerRoot = $argv[++$i];
    } elseif ($argv[$i] === '--adapter' && isset($argv[$i + 1])) {
        $adapterPath = $argv[++$i];
    } else {
        fwrite(STDERR, "usage: php -n dev29-transaction-memo.php --owner-root OWNER_CORRESPONDENCE_ROOT [--adapter PATH]\n");
        exit(2);
    }
}
$ownerRoot = realpath($ownerRoot) ?: '';
if ($ownerRoot === '' || !is_file($ownerRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime.php')) {
    fwrite(STDERR, "A frozen correspondence owner source root is required.\n");
    exit(2);
}
$ownerManifest = json_decode((string)@file_get_contents($ownerRoot . DIRECTORY_SEPARATOR . 'manifest.json'), true);
if (!is_array($ownerManifest) || ($ownerManifest['version'] ?? null) !== '0.1.0-dev.29') {
    fwrite(STDERR, "The real-service memo test requires the frozen dev.29 source root.\n");
    exit(2);
}

function memoExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$GLOBALS['qdDev29MemoQueries'] = [];
if (!function_exists('pg_query')) {
    function pg_query(mixed $connection, string $query): object|false
    {
        $GLOBALS['qdDev29MemoQueries'][] = $query;
        if (str_contains($query, 'core_npc_master')) {
            $rows = [['id' => 42, 'npc_name' => 'Lydia', 'refid' => '0100002A', 'profile_id' => '1']];
            return (object)['rows' => $rows, 'fields' => count($rows[0])];
        }
        throw new RuntimeException('Unexpected unbound fixture query: ' . $query);
    }
}
if (!function_exists('pg_query_params')) {
    function pg_query_params(mixed $connection, string $query, array $parameters): object|false
    {
        $GLOBALS['qdDev29MemoQueries'][] = [$query, $parameters];
        if (str_contains($query, 'core_player') && $parameters === ['player_name']) {
            $rows = [['value' => 'Dragonborn']];
            return (object)['rows' => $rows, 'fields' => count($rows[0])];
        }
        throw new RuntimeException('Unexpected parameterized fixture query: ' . $query);
    }
}
if (!function_exists('pg_num_fields')) {
    function pg_num_fields(mixed $result): int { return is_object($result) ? $result->fields : 0; }
}
if (!function_exists('pg_fetch_all')) {
    function pg_fetch_all(mixed $result): array|false { return is_object($result) ? $result->rows : false; }
}
if (!function_exists('pg_free_result')) {
    function pg_free_result(mixed $result): bool { return true; }
}

require_once $ownerRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime.php';
require_once $ownerRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'store.php';
require_once $ownerRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'boundaries.php';
require_once $adapterPath;

$store = qd_correspondence_read_store('fixture-connection', ['profileId' => 41]);
memoExpect(method_exists($store, 'transactionMemo'), 'The read-only adapter must expose transactionMemo for the newer service interface.');

$outsideCalls = 0;
$outsideOne = $store->transactionMemo('outside', static function () use (&$outsideCalls): int { return ++$outsideCalls; });
$outsideTwo = $store->transactionMemo('outside', static function () use (&$outsideCalls): int { return ++$outsideCalls; });
memoExpect($outsideOne === 1 && $outsideTwo === 2 && $outsideCalls === 2, 'Calls without an owned transaction callback must not retain memo values.');

$nullBuilds = 0;
$falseBuilds = 0;
$store->readTransaction(static function () use ($store, &$nullBuilds, &$falseBuilds): void {
    $nullOne = $store->transactionMemo('null', static function () use (&$nullBuilds): mixed { $nullBuilds++; return null; });
    $nullTwo = $store->transactionMemo('null', static function () use (&$nullBuilds): mixed { $nullBuilds++; return 'wrong'; });
    $falseOne = $store->transactionMemo('false', static function () use (&$falseBuilds): mixed { $falseBuilds++; return false; });
    $falseTwo = $store->transactionMemo('false', static function () use (&$falseBuilds): mixed { $falseBuilds++; return true; });
    memoExpect($nullOne === null && $nullTwo === null && $falseOne === false && $falseTwo === false, 'Null and false values must be memoized as present values.');
});
memoExpect($nullBuilds === 1 && $falseBuilds === 1, 'Each cached key must build once in the active callback.');

$nestedBuilds = 0;
$nestedValues = $store->readTransaction(static function () use ($store, &$nestedBuilds): array {
    $outer = $store->transactionMemo('nested', static function () use (&$nestedBuilds): int { return ++$nestedBuilds; });
    $inner = $store->transaction(static function () use ($store, &$nestedBuilds): int {
        return $store->transactionMemo('nested', static function () use (&$nestedBuilds): int { return ++$nestedBuilds; });
    });
    return [$outer, $inner];
});
memoExpect($nestedValues === [1, 1] && $nestedBuilds === 1, 'Nested callbacks within the same owned snapshot must reuse its memo value.');

$retryBuilds = 0;
$retryResult = $store->transaction(static function () use ($store, &$retryBuilds): string {
    try {
        $store->transactionMemo('retry', static function () use (&$retryBuilds): never { $retryBuilds++; throw new RuntimeException('builder failed'); });
        throw new RuntimeException('The failing builder did not throw.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'builder failed') throw $error;
    }
    $first = $store->transactionMemo('retry', static function () use (&$retryBuilds): string { $retryBuilds++; return 'ok'; });
    $second = $store->transactionMemo('retry', static fn(): string => 'wrong');
    return $first . ':' . $second;
});
memoExpect($retryResult === 'ok:ok' && $retryBuilds === 2, 'Builder exceptions must propagate and leave the key available for retry.');

$scopeBuilds = 0;
$firstScope = $store->readTransaction(static function () use ($store, &$scopeBuilds): int { return $store->transactionMemo('scope', static function () use (&$scopeBuilds): int { return ++$scopeBuilds; }); });
$secondScope = $store->readTransaction(static function () use ($store, &$scopeBuilds): int { return $store->transactionMemo('scope', static function () use (&$scopeBuilds): int { return ++$scopeBuilds; }); });
memoExpect($firstScope === 1 && $secondScope === 2, 'A completed callback must clear memo values before the next snapshot callback.');

try {
    $store->readTransaction(static function () use ($store, &$scopeBuilds): void {
        $store->transactionMemo('rollback', static function () use (&$scopeBuilds): int { return ++$scopeBuilds; });
        throw new RuntimeException('callback failed');
    });
    throw new RuntimeException('The failing transaction callback did not throw.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'callback failed') throw $error;
}
$afterFailure = $store->readTransaction(static function () use ($store, &$scopeBuilds): int { return $store->transactionMemo('rollback', static function () use (&$scopeBuilds): int { return ++$scopeBuilds; }); });
memoExpect($afterFailure === 4, 'An exceptional callback must clear memo values before the next callback.');

$otherStore = qd_correspondence_read_store('fixture-connection', ['profileId' => 41]);
$oneStoreBuilds = 0;
$otherStoreBuilds = 0;
$objectValues = $store->readTransaction(static function () use ($store, $otherStore, &$oneStoreBuilds, &$otherStoreBuilds): array {
    $firstStore = $store->transactionMemo('object', static function () use (&$oneStoreBuilds): int { return ++$oneStoreBuilds; });
    $secondDirectOne = $otherStore->transactionMemo('object', static function () use (&$otherStoreBuilds): int { return ++$otherStoreBuilds; });
    $secondDirectTwo = $otherStore->transactionMemo('object', static function () use (&$otherStoreBuilds): int { return ++$otherStoreBuilds; });
    $secondStore = $otherStore->readTransaction(static function () use ($otherStore, &$otherStoreBuilds): array {
        $first = $otherStore->transactionMemo('object', static function () use (&$otherStoreBuilds): int { return ++$otherStoreBuilds; });
        $cached = $otherStore->transactionMemo('object', static fn(): int => 99);
        return [$first, $cached];
    });
    $secondDirectAfter = $otherStore->transactionMemo('object', static function () use (&$otherStoreBuilds): int { return ++$otherStoreBuilds; });
    $firstStoreCached = $store->transactionMemo('object', static fn(): int => 99);
    return [$firstStore, $secondDirectOne, $secondDirectTwo, $secondStore, $secondDirectAfter, $firstStoreCached];
});
memoExpect($objectValues === [1, 1, 2, [3, 3], 4, 1] && $oneStoreBuilds === 1 && $otherStoreBuilds === 4,
    'Overlapping adapter objects must keep separate callback caches, and direct calls on the second object must not retain values.');

$commitEntries = 0;
$delegatedKeys = [];
$base = new class($delegatedKeys) {
    public array $keys;
    public function __construct(array &$keys) { $this->keys =& $keys; }
    public function transactionMemo(string $key, callable $build): mixed { $this->keys[] = $key; return $build(); }
};
$wrapped = qd_correspondence_commit_boundary_store($base, static function () use (&$commitEntries): void { $commitEntries++; });
$delegatedValue = $wrapped->transactionMemo('delegate', static fn(): string => 'delegated');
memoExpect($delegatedValue === 'delegated' && $delegatedKeys === ['delegate'] && $commitEntries === 0, 'The commit-boundary wrapper must delegate transactionMemo without entering commit.');

memoExpect($GLOBALS['qdDev29MemoQueries'] === [], 'Memo operations must not issue SQL or open/close database transactions.');

$service = new CorrespondenceService($store);
$actorMethod = new ReflectionMethod(CorrespondenceService::class, 'actor');
$actorMethod->setAccessible(true);
$memoProperty = (new ReflectionObject($store))->getProperty('transactionMemoValues');
$serviceQueryStart = count($GLOBALS['qdDev29MemoQueries']);
$serviceEvidence = $store->readTransaction(static function () use ($service, $store, $actorMethod, $memoProperty): array {
    $projection = $service->actors();
    $projectionAgain = $service->actors();
    $projectionMemo = $memoProperty->getValue($store);
    $selected = $actorMethod->invoke($service, 'npc:42');
    $lookupMemo = $memoProperty->getValue($store);
    try {
        $actorMethod->invoke($service, 'npc:999');
        $missing = false;
    } catch (InvalidArgumentException $error) {
        $missing = $error->getMessage() === 'actor_unavailable';
    }
    return [$projection, $projectionAgain, $projectionMemo, $selected, $lookupMemo, $missing];
});
$expectedProjection = [
    ['key' => 'player', 'kind' => 'player', 'id' => null, 'name' => 'Dragonborn'],
    ['key' => 'npc:42', 'kind' => 'npc', 'id' => 42, 'name' => 'Lydia'],
];
$expectedLookup = [
    'player' => ['kind' => 'player', 'id' => null, 'name' => 'Dragonborn'],
    'npc:42' => ['kind' => 'npc', 'id' => 42, 'name' => 'Lydia'],
];
memoExpect($serviceEvidence[0] === $expectedProjection && $serviceEvidence[1] === $expectedProjection
    && $serviceEvidence[2] === ['correspondence.actor_projection' => $expectedProjection]
    && $serviceEvidence[3] === $expectedLookup['npc:42']
    && $serviceEvidence[4] === ['correspondence.actor_projection' => $expectedProjection, 'correspondence.actor_lookup' => $expectedLookup]
    && $serviceEvidence[5] === true, 'The real dev.29 service must memoize its projection, perform keyed actor lookup, and reject a missing key.');
$serviceQueries = array_slice($GLOBALS['qdDev29MemoQueries'], $serviceQueryStart);
memoExpect(count($serviceQueries) === 2, 'The real-service actor fixture must issue only its two bounded actor/player SELECTs.');
foreach ($serviceQueries as $query) {
    $sql = is_array($query) ? ($query[0] ?? '') : $query;
    memoExpect(is_string($sql) && preg_match('/\A\s*SELECT\b/i', $sql) === 1
        && preg_match('/\b(BEGIN|COMMIT|ROLLBACK|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\b/i', $sql) !== 1,
        'Real-service memo access must not open a transaction or enter a write/commit path.');
}

fwrite(STDOUT, "PASS: dev29 real-service actor memo/key lookup and adapter memo lifetime/delegation\n");
