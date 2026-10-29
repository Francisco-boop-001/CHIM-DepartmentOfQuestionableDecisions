<?php
declare(strict_types=1);

$ownerRoot = '';
$adapterPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'correspondence.php';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--owner-root' && isset($argv[$i + 1])) {
        $ownerRoot = $argv[++$i];
    } elseif ($argv[$i] === '--adapter' && isset($argv[$i + 1])) {
        $adapterPath = $argv[++$i];
    } else {
        fwrite(STDERR, "usage: php -n dev30-adapter-interface.php --owner-root OWNER_SERVER_DIR [--adapter PATH]\n");
        exit(2);
    }
}

$ownerRoot = realpath($ownerRoot) ?: '';
$adapterPath = realpath($adapterPath) ?: '';
$manifest = $ownerRoot !== '' ? json_decode((string)@file_get_contents($ownerRoot . DIRECTORY_SEPARATOR . 'manifest.json'), true) : null;
if ($ownerRoot === '' || !is_array($manifest) || ($manifest['version'] ?? null) !== '0.1.0-dev.30' || $adapterPath === '') {
    fwrite(STDERR, "A frozen dev.30 owner source root and adapter are required.\n");
    exit(2);
}
if (function_exists('pg_query')) {
    fwrite(STDERR, "Run with php -n so the adapter fixture cannot access PostgreSQL.\n");
    exit(2);
}

function dev30Expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$GLOBALS['qdDev30AdapterQueries'] = [];
if (!function_exists('pg_query')) {
    function pg_query(mixed $connection, string $query): object|false
    {
        $GLOBALS['qdDev30AdapterQueries'][] = $query;
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
        $GLOBALS['qdDev30AdapterQueries'][] = [$query, $parameters];
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
dev30Expect(qd_correspondence_manifest_ready($ownerRoot), 'The read/commit interface fixture requires the exact pinned dev.30 closure.');

$expectedActors = [
    ['key' => 'player', 'kind' => 'player', 'id' => null, 'name' => 'Dragonborn'],
    ['key' => 'npc:42', 'kind' => 'npc', 'id' => 42, 'name' => 'Lydia'],
];
$reader = qd_correspondence_read_store('fixture-connection', ['profileId' => 41]);
dev30Expect($reader instanceof CorrespondenceStore, 'The actual dev.30 read adapter must implement the owner store interface.');
$readerService = new CorrespondenceService($reader);
$readerActors = $reader->readTransaction(static function () use ($reader, $readerService): array {
    $first = $readerService->actors();
    $second = $readerService->actors();
    $nested = $reader->transaction(static fn(): array => $readerService->actors());
    return [$first, $second, $nested];
});
dev30Expect($readerActors === [$expectedActors, $expectedActors, $expectedActors], 'The actual dev.30 service must work through nested read-store callbacks.');

$commitEntries = 0;
$commitBaseStore = qd_correspondence_read_store('fixture-connection', ['profileId' => 41]);
$commitStore = qd_correspondence_commit_boundary_store($commitBaseStore, static function () use (&$commitEntries): void { $commitEntries++; });
dev30Expect($commitStore instanceof CorrespondenceStore, 'The commit-boundary adapter must implement the owner store interface.');
$commitService = new CorrespondenceService($commitStore);
$commitActors = $commitStore->readTransaction(static function () use ($commitStore, $commitService): array {
    $first = $commitService->actors();
    $nested = $commitStore->transaction(static fn(): array => $commitService->actors());
    return [$first, $nested];
});
dev30Expect($commitActors === [$expectedActors, $expectedActors], 'The actual dev.30 service must work through nested commit-wrapper read callbacks.');
dev30Expect($commitEntries === 0, 'Read-only service callbacks must not enter the commit boundary.');

$queries = $GLOBALS['qdDev30AdapterQueries'];
dev30Expect(count($queries) === 4, 'The reader and commit wrapper should issue two actor/player selects apiece.');
foreach ($queries as $query) {
    $sql = is_array($query) ? ($query[0] ?? '') : $query;
    dev30Expect(is_string($sql) && preg_match('/\A\s*SELECT\b/i', $sql) === 1
        && preg_match('/\b(BEGIN|COMMIT|ROLLBACK|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\b/i', $sql) !== 1,
        'Adapter interface compatibility must not add nested transaction or write SQL.');
}

fwrite(STDOUT, "PASS: dev.30 service with read/commit adapters; nested callbacks preserve memo behavior without transaction SQL\n");
