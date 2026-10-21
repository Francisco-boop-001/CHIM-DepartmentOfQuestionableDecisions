<?php
declare(strict_types=1);

/**
 * Source-backed dev14 compatibility probe. Load the exact owner interface and
 * construct the adapter's real read-only store to catch interface drift before
 * any fixture DB work is attempted.
 */
$GLOBALS['qdDev14Queries'] = [];
$GLOBALS['qdDev14Documents'] = [
    ['document_id' => 'to-seven', 'playthrough_id' => '41', 'recipient' => ['kind' => 'npc', 'id' => 7]],
    ['document_id' => 'to-eight', 'playthrough_id' => '41', 'recipient' => ['kind' => 'npc', 'id' => 8]],
    ['document_id' => 'other-scope', 'playthrough_id' => '42', 'recipient' => ['kind' => 'npc', 'id' => 7]],
    ['document_id' => 'player-recipient', 'playthrough_id' => '41', 'recipient' => ['kind' => 'player', 'id' => null]],
];

function qd_dev14_pg_result(array $rows): object
{
    return (object)['rows' => $rows, 'fields' => $rows === [] ? 0 : count($rows[0])];
}

if (!function_exists('pg_query')) {
    function pg_query(mixed $connection, string $query): object|false
    {
        $GLOBALS['qdDev14Queries'][] = [$query, []];
        if (preg_match('/\A\s*SELECT\b/i', $query) !== 1) throw new RuntimeException('Read store issued a non-SELECT query.');
        return qd_dev14_pg_result([]);
    }
}
if (!function_exists('pg_query_params')) {
    function pg_query_params(mixed $connection, string $query, array $parameters): object|false
    {
        $GLOBALS['qdDev14Queries'][] = [$query, $parameters];
        if (preg_match('/\A\s*SELECT\b/i', $query) !== 1) throw new RuntimeException('Read store issued a non-SELECT query.');
        if (count($parameters) !== 2 || !str_contains($query, "payload->'recipient'->>'kind'='npc'")
            || !str_contains($query, "payload->'recipient'->>'id'=\$2")) {
            throw new RuntimeException('Read-store documents() did not issue the expected bounded recipient filter.');
        }
        $rows = [];
        foreach ($GLOBALS['qdDev14Documents'] as $document) {
            if (($document['playthrough_id'] ?? null) === $parameters[0]
                && ($document['recipient']['kind'] ?? null) === 'npc'
                && (string)($document['recipient']['id'] ?? '') === $parameters[1]) {
                $rows[] = ['payload' => json_encode($document, JSON_THROW_ON_ERROR)];
            }
        }
        return qd_dev14_pg_result($rows);
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

$sourceRoot = __DIR__ . '/fixtures/installed-dev14-core/ext/correspondence';
require_once $sourceRoot . '/lib/runtime.php';
require_once $sourceRoot . '/lib/store.php';
require_once $sourceRoot . '/lib/boundaries.php';
require_once dirname(__DIR__, 2) . '/adapters/correspondence.php';

try {
    $store = qd_correspondence_read_store('fixture-connection', ['profileId' => 41]);
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: read-only adapter store does not satisfy the dev14 interface: ' . get_class($error) . ': ' . $error->getMessage() . "\n");
    exit(1);
}

if (!$store instanceof CorrespondenceStore) throw new RuntimeException('Adapter read store did not implement the actual dev14 interface.');
$queriesBeforeReadTransaction = count($GLOBALS['qdDev14Queries']);
$readCalls = 0;
$value = $store->readTransaction(static function () use (&$readCalls): string { $readCalls++; return 'snapshot-value'; });
if ($value !== 'snapshot-value' || $readCalls !== 1) throw new RuntimeException('readTransaction must execute exactly once inside the caller-owned read-only snapshot.');
if (count($GLOBALS['qdDev14Queries']) !== $queriesBeforeReadTransaction) throw new RuntimeException('readTransaction must not open or commit a second transaction.');

$beforeLocks = count($GLOBALS['qdDev14Queries']);
try {
    $store->lockActorRows([7], true);
    throw new RuntimeException('Read-only store must reject actor locks reserved for effect-bearing writers.');
} catch (LogicException) {
}
if (count($GLOBALS['qdDev14Queries']) !== $beforeLocks) throw new RuntimeException('Read-only store must not issue writer-lock SQL.');

$selected = $store->documents('41', 7);
if (count($selected) !== 1 || ($selected[0]['document_id'] ?? null) !== 'to-seven') {
    throw new RuntimeException('documents(scope, recipientId) must return only the exact current-scope NPC recipient.');
}
$lastQuery = $GLOBALS['qdDev14Queries'][array_key_last($GLOBALS['qdDev14Queries'])];
if ($lastQuery[1] !== ['41', '7']) throw new RuntimeException('Recipient and scope filters must be bound parameters.');
$beforeWrongScope = count($GLOBALS['qdDev14Queries']);
if ($store->documents('42', 7) !== []) throw new RuntimeException('A wrong-scope document request must return no rows.');
if (count($GLOBALS['qdDev14Queries']) !== $beforeWrongScope) throw new RuntimeException('A wrong-scope document request must not query outside the snapshot.');
$beforeInvalidRecipient = count($GLOBALS['qdDev14Queries']);
try {
    $store->documents('41', 0);
    throw new RuntimeException('A nonpositive recipient must be rejected.');
} catch (InvalidArgumentException) {
}
if (count($GLOBALS['qdDev14Queries']) !== $beforeInvalidRecipient) throw new RuntimeException('An invalid recipient must be rejected before database access.');
$beforeUnbounded = count($GLOBALS['qdDev14Queries']);
try {
    $store->documents('41');
    throw new RuntimeException('Read-only store must continue to reject unbounded document scans.');
} catch (LogicException) {
}
if (count($GLOBALS['qdDev14Queries']) !== $beforeUnbounded) throw new RuntimeException('Unbounded scan rejection must happen before database access.');

fwrite(STDOUT, "PASS: dev14 read-store interface, snapshot delegation, fail-closed writer lock, and recipient filter\n");
