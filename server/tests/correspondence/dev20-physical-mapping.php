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
        fwrite(STDERR, "usage: php -n dev20-physical-mapping.php --owner-root OWNER_SERVER_DIR_OR_PLUGIN_ROOT [--adapter PATH]\n");
        exit(2);
    }
}

function run20Fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}
function run20Require(bool $condition, string $message): void
{
    if (!$condition) run20Fail($message);
}
function run20Normalize(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    foreach ($value as &$entry) $entry = run20Normalize($entry);
    unset($entry);
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    return $value;
}
function run20Actor(int $id, string $name): array
{
    return ['kind' => 'npc', 'id' => $id, 'name' => $name];
}
function run20ResetFakePg(array $plugins, array $npcRefids, object $connection): void
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
function run20FakeQuery(mixed $connection, string $sql, array $parameters): Run20FakePgResult|false
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
        if (!is_string($refid)) return new Run20FakePgResult([]);
        return new Run20FakePgResult(array_map(
            static fn(array $plugin): array => ['refid' => $refid] + $plugin,
            $state['plugins'],
        ));
    }
    if ($hasActors) {
        $id = isset($parameters[0]) ? (string)$parameters[0] : '';
        $refid = $state['npcRefids'][$id] ?? null;
        return new Run20FakePgResult(is_string($refid) ? [['refid' => $refid]] : []);
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
        return new Run20FakePgResult($rows);
    }

    $state['unexpected'][] = $sql;
    return false;
}
function pg_query(mixed $connection, string $sql): Run20FakePgResult|false
{
    return run20FakeQuery($connection, $sql, []);
}
function pg_query_params(mixed $connection, string $sql, array $parameters): Run20FakePgResult|false
{
    return run20FakeQuery($connection, $sql, $parameters);
}
function pg_num_fields(Run20FakePgResult $result): int
{
    return $result->rows === [] ? 0 : count($result->rows[0]);
}
function pg_fetch_all(Run20FakePgResult $result): array
{
    return $result->rows;
}
function pg_free_result(Run20FakePgResult $result): bool
{
    return true;
}
function run20CheckSqlBoundary(string $label, array $requiredTables): void
{
    $state = $GLOBALS['RUN9_FAKE_PG'];
    run20Require($state['nonSelect'] === [], "{$label}: non-SELECT query reached fake PostgreSQL");
    run20Require($state['unexpected'] === [], "{$label}: unexpected query reached fake PostgreSQL");
    run20Require($state['queries'] !== [], "{$label}: mapping did not read PostgreSQL inputs");
    foreach ($state['queries'] as $query) {
        run20Require(preg_match('/\A\s*SELECT\b/i', $query['sql']) === 1, "{$label}: non-SELECT SQL observed");
        run20Require(preg_match('/\b(FOR\s+(?:UPDATE|SHARE)|INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|LOCK|BEGIN|COMMIT|ROLLBACK|pg_advisory)\b/i', $query['sql']) !== 1, "{$label}: write, lock, or nested transaction SQL observed");
    }
    $allSql = implode("\n", array_column($state['queries'], 'sql'));
    foreach ($requiredTables as $table) run20Require(str_contains($allSql, $table), "{$label}: expected read of {$table} was absent");
}
function run20MapCase(string $label, array $plugins, array $npcRefids, callable $map, ?array $expected, ?string $expectedError, array $requiredTables): void
{
    $connection = new stdClass();
    run20ResetFakePg($plugins, $npcRefids, $connection);
    $store = qd_correspondence_read_store($connection, ['profileId' => 'run20-fixture-profile']);
    try {
        $actual = $map($store);
        if ($expectedError !== null) run20Fail("{$label}: expected DomainException {$expectedError}");
        run20Require(run20Normalize($actual) === run20Normalize($expected), "{$label}: physical mapping differs from the literal expected value");
    } catch (DomainException $error) {
        if ($expectedError === null || $error->getMessage() !== $expectedError) {
            run20Fail("{$label}: unexpected DomainException {$error->getMessage()}");
        }
    } catch (Throwable $error) {
        run20Fail("{$label}: unexpected " . get_class($error) . ': ' . $error->getMessage());
    }
    run20CheckSqlBoundary($label, $requiredTables);
    fwrite(STDOUT, "PASS: {$label}\n");
}

final class Run20FakePgResult
{
    public function __construct(public array $rows) {}
}

run20Require(is_string($ownerRoot) && $ownerRoot !== '', 'pass --owner-root with the pinned owner server directory or plugin root');
$ownerReal = realpath($ownerRoot);
$adapterReal = realpath($adapterPath);
run20Require(is_string($ownerReal) && is_dir($ownerReal), 'owner root is unavailable');
run20Require(is_string($adapterReal) && is_file($adapterReal), 'adapter file is unavailable');
$ownerServerRoot = is_file($ownerReal . '/lib/runtime.php') ? $ownerReal : $ownerReal . '/server';
run20Require(is_file($ownerServerRoot . '/lib/runtime.php'), '--owner-root must be the owner server directory or plugin root');
$manifestPath = $ownerReal . '/server/manifest.json';
if (is_file($ownerServerRoot . '/manifest.json')) $manifestPath = $ownerServerRoot . '/manifest.json';
$manifest = json_decode((string)@file_get_contents($manifestPath), true);
run20Require(is_array($manifest) && ($manifest['name'] ?? null) === 'correspondence' && ($manifest['version'] ?? null) === '0.1.0-dev.20', 'owner root is not the pinned dev.20 source');
foreach ([
    'lib/runtime.php' => 'fc95fde88d422a70c5eb3caafcfdba5f3089cf05d4d5ec73d98faeea4e6b2c15',
    'lib/store.php' => '84d0b6b3e9719dba8ddd0dc724a1c1e10bf14d3f0897c81edb758f8deaf5ee8c',
] as $relative => $expectedHash) {
    $path = $ownerServerRoot . '/' . $relative;
    run20Require(is_file($path) && !is_link($path), "pinned owner file unavailable: {$relative}");
    run20Require(hash_equals($expectedHash, strtolower((string)hash_file('sha256', $path))), "pinned owner file hash mismatch: {$relative}");
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

run20MapCase('player_standard_mapping', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalActor(['kind' => 'player', 'id' => null, 'name' => 'Dragonborn']),
    ['refid' => 20, 'plugin' => 'Skyrim.esm', 'local_id' => 20], null, ['public.game_plugins']);
run20MapCase('npc_standard_mapping', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalActor(run20Actor(1, 'Lydia')),
    ['refid' => 16781876, 'plugin' => 'Update.esm', 'local_id' => 4660], null, ['public.core_npc_master', 'public.game_plugins']);
run20MapCase('npc_high_bit_signed_mapping', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalActor(run20Actor(2, 'High Form')),
    ['refid' => -267731746, 'plugin' => 'High.esm', 'local_id' => 703710], null, ['public.core_npc_master', 'public.game_plugins']);
run20MapCase('npc_light_fe_mapping', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalActor(run20Actor(3, 'Light Form')),
    ['refid' => -32359748, 'plugin' => 'Light.esp', 'local_id' => 2748], null, ['public.core_npc_master', 'public.game_plugins']);
run20MapCase('npc_ff_dynamic_refused', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalActor(run20Actor(4, 'Dynamic Form')),
    null, 'dynamic_form_unsupported', ['public.core_npc_master']);
run20MapCase('npc_zero_local_refused', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalActor(run20Actor(5, 'Zero Form')),
    null, 'invalid_local_form', ['public.core_npc_master', 'public.game_plugins']);
run20MapCase('npc_light_zero_local_refused', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalActor(run20Actor(6, 'Light Zero')),
    null, 'invalid_local_form', ['public.core_npc_master', 'public.game_plugins']);

run20MapCase('item_standard_mapping', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Update.esm|00001234', 'quantity' => 2]),
    ['kind' => 'item', 'form_id' => 16781876, 'plugin' => 'Update.esm', 'local_id' => 4660, 'quantity' => 2], null, ['public.game_plugins']);
run20MapCase('item_high_bit_signed_mapping', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'High.esm|000ABCDE', 'quantity' => 1]),
    ['kind' => 'item', 'form_id' => -267731746, 'plugin' => 'High.esm', 'local_id' => 703710, 'quantity' => 1], null, ['public.game_plugins']);
run20MapCase('item_light_fe_upper_boundary', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Light.esp|00000FFF', 'quantity' => 3]),
    ['kind' => 'item', 'form_id' => -32358401, 'plugin' => 'Light.esp', 'local_id' => 4095, 'quantity' => 3], null, ['public.game_plugins']);
run20MapCase('item_standard_zero_local_refused', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Update.esm|00000000', 'quantity' => 1]),
    null, 'invalid_local_form', ['public.game_plugins']);
run20MapCase('item_standard_overflow_refused', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Update.esm|01000000', 'quantity' => 1]),
    null, 'invalid_local_form', ['public.game_plugins']);
run20MapCase('item_light_zero_local_refused', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Light.esp|00000000', 'quantity' => 1]),
    null, 'invalid_local_form', ['public.game_plugins']);
run20MapCase('item_light_overflow_refused', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Light.esp|00001000', 'quantity' => 1]),
    null, 'invalid_local_form', ['public.game_plugins']);
run20MapCase('item_ff_prefix_refused', $basePlugins, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Dynamic.esp|00000001', 'quantity' => 1]),
    null, 'invalid_local_form', ['public.game_plugins']);

$duplicateActorPrefix = $basePlugins;
$duplicateActorPrefix[] = ['plugin_name' => 'Shadow.esp', 'formid_prefix' => '01'];
run20MapCase('npc_duplicate_prefix_refused', $duplicateActorPrefix, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalActor(run20Actor(1, 'Lydia')),
    null, 'plugin_mapping_unavailable', ['public.core_npc_master', 'public.game_plugins']);

$caseVariantName = $basePlugins;
$caseVariantName[] = ['plugin_name' => 'light.ESP', 'formid_prefix' => 'FE124'];
run20MapCase('item_case_variant_plugin_name_refused', $caseVariantName, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Light.esp|00000001', 'quantity' => 1]),
    null, 'plugin_mapping_unavailable', ['public.game_plugins']);

$duplicateItemPrefix = $basePlugins;
$duplicateItemPrefix[] = ['plugin_name' => 'OtherLight.esm', 'formid_prefix' => 'FE123'];
run20MapCase('item_duplicate_prefix_keeps_owner_name_mapping', $duplicateItemPrefix, $npcRefids,
    static fn(CorrespondenceStore $store): array => $store->physicalItem(['kind' => 'item', 'form_id' => 'Light.esp|00000123', 'quantity' => 1]),
    ['kind' => 'item', 'form_id' => -32362205, 'plugin' => 'Light.esp', 'local_id' => 291, 'quantity' => 1], null, ['public.game_plugins']);

fwrite(STDOUT, "PASS: all 18 physical mapping cases used only SELECTs on the supplied fake connection\n");
