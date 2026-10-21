<?php
declare(strict_types=1);

$ownerRoot = '';
$adapterPath = dirname(__DIR__, 2) . '/adapters/correspondence.php';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--owner-root' && isset($argv[$i + 1])) {
        $ownerRoot = $argv[++$i];
    } elseif ($argv[$i] === '--adapter' && isset($argv[$i + 1])) {
        $adapterPath = $argv[++$i];
    } else {
        fwrite(STDERR, "usage: php -n dev18-physical-mapping.php --owner-root OWNER_SERVER_DIR_OR_PLUGIN_ROOT [--adapter PATH]\n");
        exit(2);
    }
}

function run9Fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}
function run9Require(bool $condition, string $message): void
{
    if (!$condition) run9Fail($message);
}
function run9Normalize(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    foreach ($value as &$entry) $entry = run9Normalize($entry);
    unset($entry);
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    return $value;
}
function run9Actor(int $id, string $name): array
{
    return ['kind' => 'npc', 'id' => $id, 'name' => $name];
}
function run9ResetFakePg(array $plugins, array $npcRefids, object $connection): void
{
    $GLOBALS['RUN9_FAKE_PG'] = [
        'plugins' => $plugins,
        'npcRefids' => $npcRefids,
        'connection' => $connection,
        'queries' => [],
        'nonSelect' => [],
        'unexpected' => [],
    ];
}
function run9FakeQuery(mixed $connection, string $sql, array $parameters): Run9FakePgResult|false
{
    $state =& $GLOBALS['RUN9_FAKE_PG'];
    $state['queries'][] = ['sql' => $sql, 'parameters' => $parameters];
    if ($connection !== $state['connection']) {
        $state['unexpected'][] = 'query used a connection other than the supplied fake connection';
        return false;
    }
    if (preg_match('/\A\s*SELECT\b/i', $sql) !== 1) {
        $state['nonSelect'][] = $sql;
        return false;
    }

    $lower = strtolower($sql);
    $hasActors = str_contains($lower, 'public.core_npc_master');
    $hasPlugins = str_contains($lower, 'public.game_plugins');
    if ($hasActors && $hasPlugins) {
        $id = isset($parameters[0]) ? (string)$parameters[0] : '';
        $refid = $state['npcRefids'][$id] ?? null;
        if (!is_string($refid)) return new Run9FakePgResult([]);
        return new Run9FakePgResult(array_map(
            static fn(array $plugin): array => ['refid' => $refid] + $plugin,
            $state['plugins'],
        ));
    }
    if ($hasActors) {
        $id = isset($parameters[0]) ? (string)$parameters[0] : '';
        $refid = $state['npcRefids'][$id] ?? null;
        return new Run9FakePgResult(is_string($refid) ? [['refid' => $refid]] : []);
    }
    if ($hasPlugins) {
        $rows = $state['plugins'];
        if (count($parameters) === 1) {
            $whereOffset = strpos($lower, ' where ');
            $predicate = $whereOffset === false ? '' : substr($lower, $whereOffset + 7);
            if (str_contains($predicate, 'plugin_name')) {
                $name = (string)$parameters[0];
                $rows = array_values(array_filter($rows, static fn(array $row): bool => strcasecmp((string)$row['plugin_name'], $name) === 0));
            } elseif (str_contains($predicate, 'formid_prefix')) {
                $prefix = (string)$parameters[0];
                $rows = array_values(array_filter($rows, static fn(array $row): bool => strcasecmp((string)$row['formid_prefix'], $prefix) === 0));
            }
        }
        return new Run9FakePgResult($rows);
    }

    $state['unexpected'][] = $sql;
    return false;
}
function pg_query(mixed $connection, string $sql): Run9FakePgResult|false
{
    return run9FakeQuery($connection, $sql, []);
}
function pg_query_params(mixed $connection, string $sql, array $parameters): Run9FakePgResult|false
{
    return run9FakeQuery($connection, $sql, $parameters);
}
function pg_num_fields(Run9FakePgResult $result): int
{
    return $result->rows === [] ? 0 : count($result->rows[0]);
}
function pg_fetch_all(Run9FakePgResult $result): array
{
    return $result->rows;
}
function pg_free_result(Run9FakePgResult $result): bool
{
    return true;
}
function run9CheckSqlBoundary(string $label, array $requiredTables): void
{
    $state = $GLOBALS['RUN9_FAKE_PG'];
    run9Require($state['nonSelect'] === [], "{$label}: non-SELECT query reached fake PostgreSQL");
    run9Require($state['unexpected'] === [], "{$label}: unexpected query reached fake PostgreSQL");
    run9Require($state['queries'] !== [], "{$label}: mapping did not read PostgreSQL inputs");
    foreach ($state['queries'] as $query) {
        run9Require(preg_match('/\A\s*SELECT\b/i', $query['sql']) === 1, "{$label}: non-SELECT SQL observed");
        run9Require(preg_match('/\b(FOR\s+(?:UPDATE|SHARE)|INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|LOCK|BEGIN|COMMIT|ROLLBACK|pg_advisory)\b/i', $query['sql']) !== 1, "{$label}: write, lock, or nested transaction SQL observed");
    }
    $allSql = implode("\n", array_column($state['queries'], 'sql'));
    foreach ($requiredTables as $table) run9Require(str_contains($allSql, $table), "{$label}: expected read of {$table} was absent");
}
function run9MapCase(string $label, array $plugins, array $npcRefids, callable $map, ?array $expected, ?string $expectedError, array $requiredTables): void
{
    $connection = new stdClass();
    run9ResetFakePg($plugins, $npcRefids, $connection);
    $store = qd_correspondence_read_store($connection, ['profileId' => 'run9-fixture-profile']);
    try {
        $actual = $map($store);
        if ($expectedError !== null) run9Fail("{$label}: expected DomainException {$expectedError}");
        run9Require(run9Normalize($actual) === run9Normalize($expected), "{$label}: physical mapping differs from the literal expected value");
    } catch (DomainException $error) {
        if ($expectedError === null || $error->getMessage() !== $expectedError) {
            run9Fail("{$label}: unexpected DomainException {$error->getMessage()}");
        }
    } catch (Throwable $error) {
        run9Fail("{$label}: unexpected " . get_class($error) . ': ' . $error->getMessage());
    }
    run9CheckSqlBoundary($label, $requiredTables);
    fwrite(STDOUT, "PASS: {$label}\n");
}

final class Run9FakePgResult
{
    public function __construct(public array $rows) {}
}

run9Require(is_string($ownerRoot) && $ownerRoot !== '', 'pass --owner-root with the pinned owner server directory or plugin root');
$ownerReal = realpath($ownerRoot);
$adapterReal = realpath($adapterPath);
run9Require(is_string($ownerReal) && is_dir($ownerReal), 'owner root is unavailable');
run9Require(is_string($adapterReal) && is_file($adapterReal), 'adapter file is unavailable');
$ownerServerRoot = is_file($ownerReal . '/lib/runtime.php') ? $ownerReal : $ownerReal . '/server';
run9Require(is_file($ownerServerRoot . '/lib/runtime.php'), '--owner-root must be the owner server directory or plugin root');
$manifestPath = $ownerReal . '/server/manifest.json';
if (is_file($ownerServerRoot . '/manifest.json')) $manifestPath = $ownerServerRoot . '/manifest.json';
$manifest = json_decode((string)@file_get_contents($manifestPath), true);
run9Require(is_array($manifest) && ($manifest['name'] ?? null) === 'correspondence' && ($manifest['version'] ?? null) === '0.1.0-dev.18', 'owner root is not the pinned dev.18 source');
foreach ([
    'lib/runtime.php' => '3d71593fe70b3a64fac7de25f142fc860a9d9a79575fdefc1ddd957fde8d9af6',
    'lib/store.php' => '5a91e6d0722ef81c10b5cbebc11d26773da8bbd9b06da0ffc7a3c734fe17fefa',
] as $relative => $expectedHash) {
    $path = $ownerServerRoot . '/' . $relative;
    run9Require(is_file($path) && !is_link($path), "pinned owner file unavailable: {$relative}");
    run9Require(hash_equals($expectedHash, strtolower((string)hash_file('sha256', $path))), "pinned owner file hash mismatch: {$relative}");
}
$ownerRuntime = $ownerServerRoot . '/lib/runtime.php';
require_once $ownerRuntime;
require $adapterReal;

$basePlugins = [
    ['plugin_name' => 'Skyrim.esm', 'formid_prefix' => '00'],
    ['plugin_name' => 'Update.esm', 'formid_prefix' => '01'],
    ['plugin_name' => 'High.esm', 'formid_prefix' => 'F0'],
    ['plugin_name' => 'Light.esp', 'formid_prefix' => 'FE123'],
    ['plugin_name' => 'Dynamic.esp', 'formid_prefix' => 'FF'],
];
$npcRefids = [
    '1' => '0x01001234',
    '2' => 'F00ABCDE',
    '3' => 'FE123ABC',
    '4' => 'FF123456',
    '5' => '01000000',
    '6' => 'FE123000',
];

run9MapCase('player_standard_mapping', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalActor(['kind' => 'player', 'id' => null, 'name' => 'Dragonborn']),
    ['refid' => 20, 'plugin' => 'Skyrim.esm', 'local_id' => 20], null, ['public.game_plugins']);
run9MapCase('npc_standard_mapping', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalActor(run9Actor(1, 'Lydia')),
    ['refid' => 16781876, 'plugin' => 'Update.esm', 'local_id' => 4660], null, ['public.core_npc_master', 'public.game_plugins']);
run9MapCase('npc_high_bit_signed_mapping', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalActor(run9Actor(2, 'High Form')),
    ['refid' => -267731746, 'plugin' => 'High.esm', 'local_id' => 703710], null, ['public.core_npc_master', 'public.game_plugins']);
run9MapCase('npc_light_fe_mapping', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalActor(run9Actor(3, 'Light Form')),
    ['refid' => -32359748, 'plugin' => 'Light.esp', 'local_id' => 2748], null, ['public.core_npc_master', 'public.game_plugins']);
run9MapCase('npc_ff_dynamic_refused', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalActor(run9Actor(4, 'Dynamic Form')),
    null, 'dynamic_form_unsupported', ['public.core_npc_master']);
run9MapCase('npc_zero_local_refused', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalActor(run9Actor(5, 'Zero Form')),
    null, 'invalid_local_form', ['public.core_npc_master', 'public.game_plugins']);
run9MapCase('npc_light_zero_local_refused', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalActor(run9Actor(6, 'Light Zero')),
    null, 'invalid_local_form', ['public.core_npc_master', 'public.game_plugins']);

run9MapCase('item_standard_mapping', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Update.esm|00001234', 'quantity' => 2]),
    ['kind' => 'item', 'form_id' => 16781876, 'plugin' => 'Update.esm', 'local_id' => 4660, 'quantity' => 2], null, ['public.game_plugins']);
run9MapCase('item_high_bit_signed_mapping', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'High.esm|000ABCDE', 'quantity' => 1]),
    ['kind' => 'item', 'form_id' => -267731746, 'plugin' => 'High.esm', 'local_id' => 703710, 'quantity' => 1], null, ['public.game_plugins']);
run9MapCase('item_light_fe_upper_boundary', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Light.esp|00000FFF', 'quantity' => 3]),
    ['kind' => 'item', 'form_id' => -32358401, 'plugin' => 'Light.esp', 'local_id' => 4095, 'quantity' => 3], null, ['public.game_plugins']);
run9MapCase('item_standard_zero_local_refused', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Update.esm|00000000', 'quantity' => 1]),
    null, 'invalid_local_form', ['public.game_plugins']);
run9MapCase('item_standard_overflow_refused', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Update.esm|01000000', 'quantity' => 1]),
    null, 'invalid_local_form', ['public.game_plugins']);
run9MapCase('item_light_zero_local_refused', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Light.esp|00000000', 'quantity' => 1]),
    null, 'invalid_local_form', ['public.game_plugins']);
run9MapCase('item_light_overflow_refused', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Light.esp|00001000', 'quantity' => 1]),
    null, 'invalid_local_form', ['public.game_plugins']);
run9MapCase('item_ff_prefix_refused', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Dynamic.esp|00000001', 'quantity' => 1]),
    null, 'invalid_local_form', ['public.game_plugins']);

$duplicateActorPrefix = $basePlugins;
$duplicateActorPrefix[] = ['plugin_name' => 'Shadow.esp', 'formid_prefix' => '01'];
run9MapCase('npc_duplicate_prefix_refused', $duplicateActorPrefix, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalActor(run9Actor(1, 'Lydia')),
    null, 'plugin_mapping_unavailable', ['public.core_npc_master', 'public.game_plugins']);

$caseVariantName = $basePlugins;
$caseVariantName[] = ['plugin_name' => 'light.ESP', 'formid_prefix' => 'FE124'];
run9MapCase('item_case_variant_plugin_name_refused', $caseVariantName, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Light.esp|00000001', 'quantity' => 1]),
    null, 'plugin_mapping_unavailable', ['public.game_plugins']);

$duplicateItemPrefix = $basePlugins;
$duplicateItemPrefix[] = ['plugin_name' => 'OtherLight.esm', 'formid_prefix' => 'FE123'];
run9MapCase('item_duplicate_prefix_keeps_owner_name_mapping', $duplicateItemPrefix, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Light.esp|00000123', 'quantity' => 1]),
    ['kind' => 'item', 'form_id' => -32362205, 'plugin' => 'Light.esp', 'local_id' => 291, 'quantity' => 1], null, ['public.game_plugins']);

fwrite(STDOUT, "PASS: all 18 physical mapping cases used only SELECTs on the supplied fake connection\n");
