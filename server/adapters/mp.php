<?php
declare(strict_types=1);

const QD_MP_EXPECTED_VERSION = '0.1.30';
const QD_MP_LEDGER_MAX_BYTES = 65536;
const QD_MP_RELATIONSHIP_MAX_BYTES = 16384;
const QD_MP_IDENTITY_CATALOG_MAX = 65536;
const QD_MP_EXPECTED_RELATIONSHIP_MANAGER_SHA256 = '4497443c9480c7d7ff5d5deabcec9ad5ab4cdec6f28f1fd044f89594d71e06ab';
const QD_MP_EXPECTED_SOURCE_HASHES = [
    'manifest.json' => 'd2ac602e075768399b1495795c7de9735615e82e2d5047da6b99e69aac6c50da',
    'dashboard_data.php' => 'e693cd74200783792c385b5288253631f168a59f759e28fd2a600227a2a747e9',
    'store.php' => '3d91e0756559cb892d218cace864b93e57200f2a4e53bfbb7ff9504afd93ba78',
    'influence.php' => 'ff13458c3051b3654558466c0e63954679836cbd824f239cdb28893c352bf741',
    'intimacy.php' => 'eebec2379641b7e4eb7a626b9e4cdd139c15db95421b57ca7bdcb3b1dc65bd60',
    'logging.php' => '2f8e7f75cf9de12fa1757bc381100d48512156995a95842749ce3e69e4180d43',
    'restore_policy.php' => 'ae8fa5f5e694b45667a7eb4da7fd6da101244c231bbefe094dcdb8dadb10058a',
];

function qd_mp_source_root(): string
{
    $configured = getenv('QD_MP_ROOT');
    if (!is_string($configured) || $configured === '' || str_contains($configured, "\0")) {
        throw new RuntimeException('The trusted MP source root is not configured.');
    }
    $root = realpath($configured);
    if (!is_string($root) || !is_dir($root) || is_link($configured)) {
        throw new RuntimeException('The trusted MP source root is unavailable.');
    }
    return $root;
}

function qd_mp_source_files(string $root): array
{
    return [
        'manifest.json' => $root . DIRECTORY_SEPARATOR . 'manifest.json',
        'dashboard_data.php' => $root . DIRECTORY_SEPARATOR . 'dashboard_data.php',
        'store.php' => $root . DIRECTORY_SEPARATOR . 'store.php',
        'influence.php' => $root . DIRECTORY_SEPARATOR . 'influence.php',
        'intimacy.php' => $root . DIRECTORY_SEPARATOR . 'intimacy.php',
        'logging.php' => $root . DIRECTORY_SEPARATOR . 'logging.php',
        'restore_policy.php' => $root . DIRECTORY_SEPARATOR . 'restore_policy.php',
    ];
}

function qd_mp_manifest_ready(string $root): bool
{
    $files = qd_mp_source_files($root);
    foreach (QD_MP_EXPECTED_SOURCE_HASHES as $file => $expectedHash) {
        $path = $files[$file] ?? null;
        if (!is_string($path) || !is_file($path) || is_link($path)) {
            return false;
        }
        $actualHash = @hash_file('sha256', $path);
        if (!is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) return false;
    }
    $manifestPath = $root . DIRECTORY_SEPARATOR . 'manifest.json';
    $size = @filesize($manifestPath);
    if (!is_int($size) || $size < 1 || $size > 8192) {
        return false;
    }
    $manifest = json_decode((string)@file_get_contents($manifestPath), true, 8);
    return is_array($manifest)
        && ($manifest['name'] ?? null) === 'mind_poisoning'
        && ($manifest['version'] ?? null) === QD_MP_EXPECTED_VERSION;
}

function qd_mp_load_relationship_manager(): void
{
    if (!function_exists('qd_runtime_core_root')) {
        throw new RuntimeException('The trusted CHIM source root is unavailable.');
    }
    $root = qd_runtime_core_root();
    $path = $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'relationship_manager.php';
    if (!is_file($path) || is_link($path)
        || !hash_equals(QD_MP_EXPECTED_RELATIONSHIP_MANAGER_SHA256, strtolower((string)@hash_file('sha256', $path)))) {
        throw new RuntimeException('The pinned CHIM relationship helper is unavailable.');
    }
    $expectedSource = realpath($path);
    if (!is_string($expectedSource)) {
        throw new RuntimeException('The pinned CHIM relationship helper is unavailable.');
    }
    if (!class_exists(\RelationshipManager::class, false)) {
        require_once $path;
    }
    if (!class_exists(\RelationshipManager::class, false)
        || realpath((new ReflectionClass(\RelationshipManager::class))->getFileName() ?: '') !== $expectedSource) {
        throw new RuntimeException('The CHIM relationship helper loaded from an unexpected source root.');
    }
}

function qd_mp_load_owner_sources(): string
{
    static $loadedRoot = null;
    $root = qd_mp_source_root();
    if ($loadedRoot !== null) {
        if ($loadedRoot !== $root) {
            throw new RuntimeException('A different MP source root is already loaded.');
        }
        if (function_exists('qd_runtime_core_root')) {
            qd_mp_load_relationship_manager();
        }
        return $root;
    }
    if (!qd_mp_manifest_ready($root)) {
        throw new RuntimeException('The pinned MP source contract is unavailable.');
    }
    require_once $root . DIRECTORY_SEPARATOR . 'dashboard_data.php';
    require_once $root . DIRECTORY_SEPARATOR . 'intimacy.php';
    foreach ([
        'ChimMindPoisoning\\dashboardReadLogs' => 'dashboard_data.php',
        'ChimMindPoisoning\\dashboardBuildInteractions' => 'dashboard_data.php',
        'ChimMindPoisoning\\dashboardOpinionOwnerId' => 'dashboard_data.php',
        'ChimMindPoisoning\\storedLedgerNamespace' => 'store.php',
        'ChimMindPoisoning\\mindPoisoningReadIntimacyFromRow' => 'intimacy.php',
    ] as $function => $file) {
        if (!function_exists($function)) {
            throw new RuntimeException('The pinned MP reader contract is unavailable.');
        }
        $source = realpath((new ReflectionFunction($function))->getFileName() ?: '');
        if ($source !== realpath($root . DIRECTORY_SEPARATOR . $file)) {
            throw new RuntimeException('MP reader loaded from an unexpected source root.');
        }
    }
    if (function_exists('qd_runtime_core_root')) {
        qd_mp_load_relationship_manager();
    }
    $loadedRoot = $root;
    return $root;
}

function qd_mp_runtime_operations(): array
{
    return ['mp.relationships.read' => static fn(stdClass $payload, array $context): array => qd_mp_relationships_read($payload, $context)];
}

function qd_mp_runtime_ready(): bool
{
    try {
        return extension_loaded('mbstring') && qd_mp_manifest_ready(qd_mp_source_root());
    } catch (Throwable) {
        return false;
    }
}

function qd_mp_runtime_perspectives(string $scopeKey): array
{
    try {
        qd_mp_load_owner_sources();
    $result = qd_runtime_with_core_db(static function ($connection) use ($scopeKey): array {
            qd_mp_begin_read($connection);
            try {
                $active = qd_runtime_active_playthrough($connection);
                if (!hash_equals((string)$active['scopeKey'], $scopeKey)) {
                    throw new RuntimeException('The authenticated CHIM scope changed.');
                }
                $profileId = (string)$active['profileId'];
                $rows = qd_mp_pg_rows($connection,
                    "SELECT npc.id::text AS id, npc.npc_name,
                            CASE WHEN octet_length((npc.plugin_extended_data::jsonb->'mind_poisoning')::text) <= " . QD_MP_LEDGER_MAX_BYTES
                    . " THEN (npc.plugin_extended_data::jsonb->'mind_poisoning')::text ELSE NULL END AS ledger,
                            octet_length((npc.plugin_extended_data::jsonb->'mind_poisoning')::text)::text AS ledger_bytes,
                            (SELECT count(*) FROM public.core_npc_master duplicate WHERE duplicate.npc_name = npc.npc_name)::text AS name_count
                     FROM public.core_npc_master npc
                     WHERE npc.plugin_extended_data::jsonb->'mind_poisoning'->>'playthrough_id' = $1
                     ORDER BY npc.id LIMIT 65",
                    [$profileId]);
                $limited = count($rows) > 64;
                $rows = array_slice($rows, 0, 64);
                $choices = [];
                foreach ($rows as $row) {
                    if (!is_string($row['ledger'] ?? null) || (int)($row['ledger_bytes'] ?? 0) > QD_MP_LEDGER_MAX_BYTES) {
                        $limited = true;
                        continue;
                    }
                    $plugins = (object)['mind_poisoning' => json_decode($row['ledger'], false, 64, JSON_THROW_ON_ERROR)];
                    if (\ChimMindPoisoning\storedLedgerNamespace($plugins) === null) {
                        $limited = true;
                        continue;
                    }
                    $id = qd_mp_positive_id($row['id'] ?? null);
                    if ($id === null) {
                        $limited = true;
                        continue;
                    }
                    $name = qd_inspector_clip_bytes(is_string($row['npc_name'] ?? null) ? trim($row['npc_name']) : 'NPC #' . $id, 192, $limited);
                    if ($name === null || $name === '') {
                        $name = 'NPC #' . $id;
                    }
                    if ((int)($row['name_count'] ?? 0) > 1) {
                        $name .= ' (#' . $id . ')';
                    }
                    $choices[] = ['actorKey' => 'npc:' . $id, 'label' => $name];
                }
                qd_mp_commit($connection);
                return ['choices' => $choices, 'limited' => $limited];
            } catch (Throwable $error) {
                qd_mp_rollback($connection);
                throw $error;
            }
        });
        return $result;
    } catch (Throwable) {
        return ['choices' => [], 'limited' => true];
    }
}

function qd_mp_relationships_read(stdClass $payload, array $context): array
{
    $assertCurrent = $context['assertCurrent'] ?? null;
    if (!is_callable($assertCurrent)) {
        throw new QDServerFault('unavailable');
    }
    $perspectiveKey = $payload->perspectiveActorKey ?? null;
    $targetKey = $payload->targetActorKey ?? null;
    $limit = $payload->limit ?? 50;
    $cursor = $payload->cursor ?? null;
    if (array_diff(array_keys(get_object_vars($payload)), ['perspectiveActorKey', 'targetActorKey', 'limit', 'cursor']) !== []
        || !is_string($perspectiveKey) || preg_match('/\Anpc:([1-9][0-9]{0,18})\z/D', $perspectiveKey) !== 1
        || ($targetKey !== null && (!is_string($targetKey) || ($targetKey !== 'player' && preg_match('/\Anpc:[1-9][0-9]{0,18}\z/D', $targetKey) !== 1)))
        || !is_int($limit) || $limit < 1 || $limit > 100
        || ($cursor !== null && (!is_string($cursor) || strlen($cursor) > 512))) {
        throw new QDServerFault('invalid_request');
    }
    $assertCurrent();
    qd_mp_load_owner_sources();
    $scopeKey = $context['scopeKey'] ?? null;
    if (!is_string($scopeKey) || preg_match('/\A[a-f0-9]{64}\z/D', $scopeKey) !== 1) {
        throw new QDServerFault('unavailable');
    }

    $read = qd_runtime_with_core_db(static function ($connection) use ($perspectiveKey, $targetKey, $scopeKey): array {
        $ownerId = substr($perspectiveKey, 4);
        qd_mp_begin_read($connection);
        try {
            $active = qd_runtime_active_playthrough($connection);
            if (!hash_equals((string)$active['scopeKey'], $scopeKey)) {
                throw new QDServerFault('stale_context');
            }
            $profileId = (string)$active['profileId'];
            $row = qd_mp_selected_owner_row($connection, $ownerId);
            if ($row === null) {
                throw new QDServerFault('not_found');
            }
            if (($row['ledger_oversized'] ?? false) === true) {
                throw new QDServerFault('unavailable');
            }
            $ownerSourceName = is_string($row['npc_name'] ?? null) ? $row['npc_name'] : '';
            if (trim($ownerSourceName) === '') $ownerSourceName = 'NPC #' . $ownerId;
            $ownerNameLimited = false;
            $ownerName = qd_mp_public_owner_name($ownerSourceName, $ownerId, $ownerNameLimited);
            $limited = $ownerNameLimited;
            $plugins = [];
            $ledgerRaw = $row['ledger'] ?? null;
            try {
                if (is_string($ledgerRaw)) {
                    $plugins['mind_poisoning'] = json_decode($ledgerRaw, false, 64, JSON_THROW_ON_ERROR);
                }
            } catch (JsonException) {
                throw new QDServerFault('unavailable');
            }
            $pluginExtended = (object)$plugins;
            $validNamespace = \ChimMindPoisoning\storedLedgerNamespace($pluginExtended);
            if ($validNamespace === null) {
                throw new QDServerFault('unavailable');
            }
            $logs = ['available' => false, 'limited' => true, 'records' => []];
            try {
                $logs = \ChimMindPoisoning\dashboardReadLogs(qd_runtime_core_root());
            } catch (Throwable) {
                // The validated ledger can still produce rows when the bounded log tail is unavailable.
            }
            $filteredLogs = array_values(array_filter($logs['records'] ?? [], static fn(array $record): bool =>
                \ChimMindPoisoning\dashboardOpinionOwnerId($record) === $ownerId));
            $relatedIdsLimited = false;
            $subjectIds = qd_mp_related_ids($validNamespace, $filteredLogs, $ownerId, $relatedIdsLimited);
            $identityResult = qd_mp_read_relevant_identities($connection, $subjectIds);
            $limited = $ownerNameLimited || ($logs['limited'] ?? false) !== false || ($logs['available'] ?? false) !== true
                || ($identityResult['limited'] ?? false) === true || $relatedIdsLimited;
            $active['playerName'] = qd_mp_player_name($connection);
            $identityRows = $identityResult['rows'];
            $names = [];
            $identityList = [];
            $identitySeen = [];
            $identityComplete = ($identityResult['complete'] ?? false) === true;
            $ambiguous = qd_mp_incomplete_identity_subjects($subjectIds, $identityComplete, $limited);
            foreach ($identityRows as $identity) {
                $id = qd_mp_positive_id($identity['id'] ?? null);
                $name = is_string($identity['npc_name'] ?? null) ? $identity['npc_name'] : '';
                if ($id === null || (string)(int)$id !== $id || trim($name) === '' || preg_match('//u', $name) !== 1) {
                    $limited = true;
                    continue;
                }
                $names[$id] = $name;
                if (!isset($identitySeen[$id])) {
                    $identityList[] = ['id' => (int)$id, 'npc_name' => $name];
                    $identitySeen[$id] = true;
                }
                if ((int)($identity['name_count'] ?? 0) > 1) {
                    $ambiguous['npc:' . $id] = true;
                    $limited = true;
                    $duplicateId = qd_mp_positive_id($identity['duplicate_id'] ?? null);
                    $duplicateName = is_string($identity['duplicate_name'] ?? null) ? $identity['duplicate_name'] : $name;
                    if ($duplicateId === null || (string)(int)$duplicateId !== $duplicateId) {
                        $limited = true;
                    } elseif (!isset($identitySeen[$duplicateId])) {
                        $identityList[] = ['id' => (int)$duplicateId, 'npc_name' => $duplicateName];
                        $identitySeen[$duplicateId] = true;
                    }
                }
            }
            $names[$ownerId] = $ownerSourceName;
            $relationshipJson = $row['relationships'] ?? null;
            $relationshipTooLarge = ($row['relationships_oversized'] ?? false) === true;
            try {
                $relationships = $relationshipTooLarge ? '__qd_oversized__'
                    : (is_string($relationshipJson) ? json_decode($relationshipJson, false, 32, JSON_THROW_ON_ERROR) : new stdClass());
            } catch (JsonException) {
                throw new QDServerFault('unavailable');
            }
            $sourceRow = [
                'id' => $ownerId,
                'npc_name' => $ownerSourceName,
                'plugin_extended_data' => $pluginExtended,
                'extended_data' => (object)['relationships' => $relationships],
            ];
            $limited = $limited || $relationshipTooLarge;
            $normalizerLimited = false;
            $sourceRecords = \ChimMindPoisoning\dashboardBuildInteractions([
                'active_playthrough' => $profileId,
                'player_name' => $active['playerName'],
                'identities' => $names,
                'rows' => [$sourceRow],
            ], $filteredLogs, $normalizerLimited);
            $limited = $limited || $normalizerLimited;
            if ($targetKey !== null) {
                $sourceRecords = qd_mp_filter_target($sourceRecords, $targetKey);
            }
            $confirmation = qd_mp_confirmation_states($profileId, $ownerId, $sourceRecords, $filteredLogs);
            $arrangementTargets = qd_mp_arrangement_targets($sourceRecords);
            if (count($arrangementTargets) > 64) {
                $arrangementTargets = array_slice($arrangementTargets, 0, 64);
                $limited = true;
            }
            $arrangements = [];
            if (!$relationshipTooLarge && $arrangementTargets !== []) {
                $sourceRowCache = qd_mp_preload_arrangement_source_cache($connection, $validNamespace, $arrangementTargets, $limited);
                $npcRows = [];
                foreach ($identityList as $identity) {
                    $npcRows[$identity['id']] = ['id' => $identity['id'], 'npc_name' => $identity['npc_name']];
                }
                $store = qd_mp_readonly_inspector_store($npcRows, $sourceRowCache);
                $currentRead = qd_mp_read_current_arrangements($store, $active, $sourceRow, $identityList, $arrangementTargets, $sourceRowCache);
                $revision = qd_mp_source_revision($row, $identityRows, $filteredLogs, $active['playerName'], $sourceRowCache);
                qd_mp_commit($connection);
                return [
                    'ownerId' => $ownerId, 'ownerName' => $ownerName, 'sourceRecords' => $sourceRecords,
                    'limited' => $limited || $currentRead['limited'], 'revision' => $revision,
                    'currentAsOf' => $currentRead['asOf'], 'arrangements' => $currentRead['values'],
                    'confirmation' => $confirmation, 'ambiguous' => $ambiguous,
                    'logs' => $filteredLogs, 'targetKey' => null,
                ];
            }
            $revision = qd_mp_source_revision($row, $identityRows, $filteredLogs, $active['playerName']);
            qd_mp_commit($connection);
            return [
                'ownerId' => $ownerId, 'ownerName' => $ownerName, 'sourceRecords' => $sourceRecords,
                'limited' => $limited, 'revision' => $revision,
                'currentAsOf' => gmdate('Y-m-d\TH:i:s\Z'), 'arrangements' => $arrangements,
                'confirmation' => $confirmation, 'ambiguous' => $ambiguous,
                'logs' => $filteredLogs, 'targetKey' => null,
            ];
        } catch (Throwable $error) {
            qd_mp_rollback($connection);
            throw $error;
        }
    });

    $assertCurrent();
    if ($read['currentAsOf'] === null) {
        $read['currentAsOf'] = gmdate('Y-m-d\TH:i:s\Z');
    }
    $sourceRecords = $read['sourceRecords'];
    $limited = $read['limited'];
    $records = qd_mp_project_interactions(
        $sourceRecords,
        $read['ownerId'],
        $read['ownerName'],
        $read['currentAsOf'],
        $read['arrangements'],
        $limited,
        $read['confirmation'],
        $read['ambiguous'],
    );
    $binding = [
        'module' => 'mp', 'operation' => 'mp.relationships.read',
        'actorKey' => $perspectiveKey, 'targetActorKey' => $targetKey, 'scopeKey' => $scopeKey,
    ];
    return qd_inspector_page($records, $limit, $cursor, $binding, $read['revision'], $limited, 'records', ['perspectiveActorKey' => $perspectiveKey]);
}

function qd_mp_selected_owner_row(mixed $connection, string $ownerId): ?array
{
    $rows = qd_mp_pg_rows($connection,
        "SELECT id::text AS id, npc_name, xmin::text AS row_version,
                (plugin_extended_data::jsonb ? 'mind_poisoning') AS has_ledger,
                (plugin_extended_data::jsonb->'mind_poisoning' IS NOT NULL) AS ledger_present,
                octet_length((plugin_extended_data::jsonb->'mind_poisoning')::text)::text AS ledger_bytes,
                CASE WHEN octet_length((plugin_extended_data::jsonb->'mind_poisoning')::text) <= " . QD_MP_LEDGER_MAX_BYTES
        . " THEN (plugin_extended_data::jsonb->'mind_poisoning')::text ELSE NULL END AS ledger,
                (extended_data::jsonb->'relationships' IS NOT NULL) AS relationships_present,
                octet_length((extended_data::jsonb->'relationships')::text)::text AS relationships_bytes,
                CASE WHEN octet_length((extended_data::jsonb->'relationships')::text) <= " . QD_MP_RELATIONSHIP_MAX_BYTES
        . " THEN (extended_data::jsonb->'relationships')::text ELSE NULL END AS relationships
         FROM public.core_npc_master WHERE id = $1 LIMIT 2",
        [$ownerId]);
    if (count($rows) > 1) {
        throw new QDServerFault('unavailable');
    }
    if ($rows === []) {
        return null;
    }
    $row = $rows[0];
    if (($row['ledger_present'] ?? 'f') === 't' && !is_string($row['ledger'] ?? null)) {
        $row['ledger_oversized'] = true;
    }
    if (($row['relationships_present'] ?? 'f') === 't' && !is_string($row['relationships'] ?? null)) {
        $row['relationships_oversized'] = true;
    }
    return $row;
}

function qd_mp_related_ids(array $ledger, array $logs, string $ownerId, bool &$limited): array
{
    $ids = [$ownerId => true];
    $add = static function (mixed $subject) use (&$ids): void {
        if (is_string($subject) && preg_match('/\Anpc:([1-9][0-9]{0,18})\z/D', $subject, $matches) === 1) {
            $ids[$matches[1]] = true;
        }
    };
    foreach ($ledger['events'] ?? [] as $entry) {
        foreach (['judgments', 'type_decisions', 'type_changes', 'arrangement_changes'] as $field) {
            foreach (is_array($entry[$field] ?? null) ? $entry[$field] : [] as $value) {
                if (is_array($value)) $add($value['subject'] ?? null);
            }
        }
    }
    foreach ($logs as $record) {
        foreach (['speaker_id', 'listener_id', 'opinion_owner_id', 'recipient_id', 'addressed_listener_id'] as $field) {
            $id = qd_mp_positive_id($record[$field] ?? null);
            if ($id !== null) $ids[$id] = true;
        }
        foreach (['subject', 'changes', 'type_decisions', 'type_changes', 'arrangement_changes'] as $field) {
            $values = $record[$field] ?? null;
            if ($field === 'subject') $add($values);
            foreach (is_array($values) ? $values : [] as $value) {
                if (is_array($value)) $add($value['subject'] ?? null);
            }
        }
    }
    if (count($ids) > 2048) {
        $ids = array_slice($ids, 0, 2048, true);
        $limited = true;
    }
    return array_keys($ids);
}

function qd_mp_read_relevant_identities(mixed $connection, array $ids): array
{
    $ids = array_values(array_filter(array_unique(array_map('strval', $ids)), static fn(string $id): bool => preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) === 1));
    if ($ids === []) {
        return ['rows' => [], 'limited' => false, 'complete' => true, 'catalogRowsRead' => 0];
    }
    if (!function_exists('mb_strtolower') || !function_exists('ChimMindPoisoning\\sameActorName')) {
        throw new RuntimeException('The canonical MP identity comparison is unavailable.');
    }
    $rows = qd_mp_pg_rows($connection,
        'SELECT npc.id::text AS id, npc.npc_name FROM public.core_npc_master npc ORDER BY npc.id LIMIT $1::integer',
        [(string)(QD_MP_IDENTITY_CATALOG_MAX + 1)]);
    $catalogLimited = count($rows) > QD_MP_IDENTITY_CATALOG_MAX;
    $catalogRows = array_slice($rows, 0, QD_MP_IDENTITY_CATALOG_MAX);
    $byId = [];
    $byName = [];
    $complete = !$catalogLimited;
    foreach ($catalogRows as $row) {
        $id = qd_mp_positive_id($row['id'] ?? null);
        $name = $row['npc_name'] ?? null;
        if ($id === null || (string)(int)$id !== $id || !is_string($name) || trim($name) === '' || preg_match('//u', $name) !== 1) {
            $complete = false;
            continue;
        }
        $identity = ['id' => $id, 'npc_name' => $name];
        $byId[$id] = $identity;
        $nameKey = mb_strtolower(trim($name), 'UTF-8');
        $byName[$nameKey][] = $identity;
    }
    $selected = [];
    foreach ($ids as $id) {
        $identity = $byId[$id] ?? null;
        if ($identity === null) {
            $complete = false;
            continue;
        }
        $matches = [];
        $nameKey = mb_strtolower(trim($identity['npc_name']), 'UTF-8');
        foreach ($byName[$nameKey] ?? [] as $candidate) {
            if (\ChimMindPoisoning\sameActorName($identity['npc_name'], $candidate['npc_name'])) {
                $matches[] = $candidate;
            }
        }
        $duplicate = null;
        foreach ($matches as $candidate) {
            if ($candidate['id'] !== $id) {
                $duplicate = $candidate;
                break;
            }
        }
        $selected[] = $identity + [
            'name_count' => count($matches),
            'duplicate_id' => $duplicate['id'] ?? null,
            'duplicate_name' => $duplicate['npc_name'] ?? null,
        ];
    }
    return [
        'rows' => $selected,
        'limited' => !$complete,
        'complete' => $complete,
        'catalogRowsRead' => count($catalogRows),
    ];
}

function qd_mp_incomplete_identity_subjects(array $ids, bool $complete, bool &$limited): array
{
    if ($complete) return [];
    $limited = true;
    $subjects = [];
    foreach ($ids as $id) {
        if ((is_int($id) || is_string($id)) && preg_match('/\A[1-9][0-9]{0,18}\z/D', (string)$id) === 1) {
            $subjects['npc:' . (string)$id] = true;
        }
    }
    return $subjects;
}

function qd_mp_public_owner_name(string $sourceName, string $ownerId, bool &$limited): string
{
    $name = qd_inspector_clip_bytes(trim($sourceName), 192, $limited);
    return $name === null || $name === '' ? 'NPC #' . $ownerId : $name;
}

function qd_mp_confirmation_states(string $profileId, string $ownerId, array $interactions, array $logs): array
{
    $index = \ChimMindPoisoning\dashboardIndexRecords($logs);
    $result = [];
    foreach ($interactions as $interaction) {
        $eventId = is_string($interaction['event_id'] ?? null) ? $interaction['event_id'] : null;
        $utterance = is_string($interaction['utterance_id'] ?? null) ? $interaction['utterance_id'] : null;
        if ($eventId === null || $utterance === null) continue;
        $key = ($interaction['source_kind'] ?? null) === 'written_evidence'
            ? \ChimMindPoisoning\dashboardWrittenInteractionKey($profileId, $eventId, $ownerId)
            : \ChimMindPoisoning\dashboardInteractionKey($profileId, $eventId, $utterance, $ownerId);
        [, $state] = \ChimMindPoisoning\dashboardPersistenceForGroup($index[$key] ?? []);
        $result[$eventId . "\0" . $utterance] = match ($state) {
            'confirmed' => 'confirmed', 'unconfirmed' => 'unconfirmed', default => null,
        };
    }
    return $result;
}

function qd_mp_arrangement_targets(array $interactions): array
{
    $targets = [];
    foreach ($interactions as $interaction) {
        foreach ($interaction['arrangement_changes'] ?? [] as $change) {
            $subject = $change['subject'] ?? null;
            if (is_string($subject) && ($subject === 'player' || preg_match('/\Anpc:[1-9][0-9]{0,18}\z/D', $subject) === 1)) {
                $targets[$subject] = true;
            }
        }
    }
    return array_keys($targets);
}

function qd_mp_source_revision(array $ownerRow, array $identityRows, array $logs, string $playerName, array $sourceRowCache = []): string
{
    ksort($sourceRowCache, SORT_STRING);
    return hash('sha256', implode("\0", [
        (string)($ownerRow['row_version'] ?? ''),
        (string)($ownerRow['ledger'] ?? ''),
        (string)($ownerRow['relationships'] ?? ''),
        json_encode($identityRows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($logs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $playerName,
        hash('sha256', serialize($sourceRowCache)),
    ]));
}

function qd_mp_preload_arrangement_source_cache(mixed $connection, array $ledger, array $targets, bool &$limited): array
{
    $cache = [];
    $requested = [];
    $arrangements = is_array($ledger['arrangements'] ?? null) ? $ledger['arrangements'] : [];
    foreach ($targets as $target) {
        if (!array_key_exists($target, $arrangements)) continue;
        $record = $arrangements[$target];
        if (!is_array($record) || !is_array($record['sources'] ?? null)) {
            $limited = true;
            continue;
        }
        foreach ($record['sources'] as $source) {
            if (!is_array($source)) {
                $limited = true;
                continue;
            }
            $eventId = $source['event_id'] ?? null;
            $utteranceId = $source['utterance_id'] ?? null;
            if (!is_int($eventId) || $eventId < 1 || !is_string($utteranceId)
                || preg_match('/\\Autt_[A-Za-z0-9_-]{8,128}\\z/D', $utteranceId) !== 1) {
                $limited = true;
                continue;
            }
            $key = $eventId . ':' . $utteranceId;
            $cache[$key] = null;
            $requested[$key] = [$eventId, $utteranceId];
        }
    }
    if ($requested === []) return $cache;

    $eventIds = [];
    $utteranceIds = [];
    foreach ($requested as [$eventId, $utteranceId]) {
        $eventIds[] = (string)$eventId;
        $utteranceIds[] = $utteranceId;
    }
    $rows = qd_mp_pg_rows($connection,
        "WITH requested(event_id, utterance_id) AS (
             SELECT * FROM unnest($1::bigint[], $2::text[])
         )
         SELECT e.rowid, e.type, e.utterance_id, e.delivery_state, e.gamets, e.data
         FROM requested source
         JOIN eventlog e ON e.rowid = source.event_id AND e.type = 'chat' AND e.utterance_id = source.utterance_id
         WHERE (SELECT count(*) FROM eventlog exact_event
                WHERE exact_event.type = 'chat' AND exact_event.utterance_id = source.utterance_id) = 1
         ORDER BY e.rowid",
        ['{' . implode(',', $eventIds) . '}', '{' . implode(',', $utteranceIds) . '}']);
    foreach ($rows as $row) {
        $event = \ChimMindPoisoning\normalizeEventRow($row);
        if (!is_array($event)) continue;
        $key = $event['event_id'] . ':' . $event['utterance_id'];
        if (array_key_exists($key, $cache)) $cache[$key] = $event;
    }
    foreach ($cache as $event) {
        if ($event === null) $limited = true;
    }
    return $cache;
}

function qd_mp_readonly_inspector_store(array $npcRows, array $sourceRowCache): \ChimMindPoisoning\StoreDb
{
    return new class($npcRows, $sourceRowCache) implements \ChimMindPoisoning\StoreDb {
        public function __construct(private readonly array $npcRows, private readonly array $sourceRows) {}

        public function activePlaythrough(): ?array { throw new LogicException('Unneeded MP inspector store method.'); }
        public function acknowledgedEvent(string $utteranceId): ?array { throw new LogicException('Unneeded MP inspector store method.'); }
        public function playerInputEvent(array $source): ?array { throw new LogicException('Unneeded MP inspector store method.'); }
        public function eventById(int $eventId, string $utteranceId): ?array
        {
            return $this->sourceRows[$eventId . ':' . $utteranceId] ?? null;
        }
        public function npcIdentities(): array { throw new LogicException('Unneeded MP inspector store method.'); }
        public function npcById(int $npcId, bool $forUpdate = false): ?array
        {
            if ($forUpdate) throw new LogicException('The MP inspector store is read only.');
            return $this->npcRows[$npcId] ?? null;
        }
        public function reflectionHistory(string $actorName, int $beforeEventId): array { throw new LogicException('Unneeded MP inspector store method.'); }
        public function beginForListener(int $listenerId): bool { throw new LogicException('The MP inspector store is read only.'); }
        public function writeNpc(int $npcId, array $relationshipEdges, object $mindPoisoningData, float $gamets, array $relationshipKeysToRemove = []): bool
        {
            throw new LogicException('The MP inspector store is read only.');
        }
        public function backupAndVerify(int $npcId, array $expected): bool { throw new LogicException('The MP inspector store is read only.'); }
        public function commit(): bool { throw new LogicException('The MP inspector store is read only.'); }
        public function rollback(): void { throw new LogicException('The MP inspector store is read only.'); }
        public function release(): void { throw new LogicException('The MP inspector store is read only.'); }
    };
}

function qd_mp_read_current_arrangements(\ChimMindPoisoning\StoreDb $store, array $active, array $ownerRow, array $identities, array $targets, array &$sourceRowCache): array
{
    $values = [];
    $limited = false;
    $asOf = gmdate('Y-m-d\TH:i:s\Z');
    try {
        $activeSource = ['id' => (string)$active['profileId'], 'player_name' => (string)($active['playerName'] ?? ''), 'character_id' => $active['characterId']];
        foreach ($targets as $target) {
            $ownerId = (int)$ownerRow['id'];
            $read = \ChimMindPoisoning\mindPoisoningReadIntimacyFromRow($ownerId, $target, $ownerRow, $activeSource, $identities, $store, $sourceRowCache);
            $state = $read['state'] ?? null;
            if (!in_array($state, ['none', 'active', 'ended'], true)) {
                $values[$target] = null;
                $limited = true;
                continue;
            }
            $values[$target] = $state === 'active' && in_array($read['kind'] ?? null, ['casual', 'romantic'], true)
                ? 'active (' . $read['kind'] . ')' : $state;
        }
    } catch (Throwable) {
        foreach ($targets as $target) $values[$target] = null;
        $limited = true;
    }
    return ['values' => $values, 'limited' => $limited, 'asOf' => $asOf];
}

function qd_mp_player_name(mixed $connection): string
{
    $rows = qd_mp_pg_rows($connection,
        "SELECT value AS player_name FROM public.core_player WHERE id = 'player_name' LIMIT 2");
    if (count($rows) !== 1 || !is_string($rows[0]['player_name'] ?? null)) {
        return '';
    }
    return $rows[0]['player_name'];
}

function qd_mp_filter_target(array $interactions, string $target): array
{
    $filtered = [];
    foreach ($interactions as $interaction) {
        foreach (['changes', 'type_changes', 'type_decisions', 'arrangement_changes'] as $field) {
            $interaction[$field] = array_values(array_filter($interaction[$field] ?? [], static fn(array $row): bool => ($row['subject'] ?? null) === $target));
        }
        if ($interaction['changes'] !== [] || $interaction['type_changes'] !== [] || $interaction['type_decisions'] !== [] || $interaction['arrangement_changes'] !== []) {
            $filtered[] = $interaction;
        }
    }
    return $filtered;
}

function qd_mp_project_interactions(
    array $interactions,
    string $ownerId,
    string $ownerName,
    string $currentAsOf,
    array $currentArrangements,
    bool &$limited,
    array $confirmationByEvent = [],
    array $ambiguousSubjects = [],
): array {
    $records = [];
    foreach ($interactions as $interaction) {
        if (!is_array($interaction)) continue;
        $eventId = is_string($interaction['event_id'] ?? null) ? $interaction['event_id'] : '';
        $utterance = is_string($interaction['utterance_id'] ?? null) ? $interaction['utterance_id'] : '';
        $eventKey = $eventId . "\0" . $utterance;
        $confirmation = $confirmationByEvent[$eventKey] ?? null;
        if (!in_array($confirmation, ['confirmed', 'unconfirmed'], true)) $confirmation = null;
        $sourceKind = in_array($interaction['source_kind'] ?? null, ['reflection', 'intimacy', 'written_evidence'], true)
            ? $interaction['source_kind'] : null;
        $at = qd_inspector_clip_bytes(is_string($interaction['timestamp'] ?? null) ? $interaction['timestamp'] : null, 256, $limited);
        $reason = qd_mp_fixed_reason($interaction['reason'] ?? null);
        foreach ($interaction['changes'] ?? [] as $change) {
            if (!is_array($change) || !qd_mp_subject($change['subject'] ?? null)) continue;
            $subject = $change['subject'];
            $target = qd_inspector_clip_bytes(is_string($change['label'] ?? null) ? $change['label'] : 'Unknown', 256, $limited) ?? 'Unknown';
            $historicalBefore = qd_mp_number($change['before'] ?? null);
            $historicalAfter = qd_mp_number($change['after'] ?? null);
            $current = isset($ambiguousSubjects[$subject]) ? null : qd_mp_number($change['current'] ?? null);
            $currentSet = !isset($ambiguousSubjects[$subject]) && ($change['current_state'] ?? null) === 'set' && $current !== null;
            $records[] = qd_mp_record(
                $ownerId, $ownerName, $target, $at,
                is_int($change['proposed'] ?? null) ? $change['proposed'] : null,
                is_int($change['applied'] ?? null) || is_float($change['applied'] ?? null) ? $change['applied'] : null,
                $historicalBefore, $historicalAfter,
                $currentSet ? $current : null, $currentSet ? $currentAsOf : null,
                $sourceKind, $reason, 'affinity', $confirmation,
                $eventId, $utterance, $subject, 'affinity',
            );
            if (is_string($change['type_before'] ?? null) && is_string($change['type_after'] ?? null)
                && ($change['type_event_state'] ?? null) === 'set') {
                $canonicalTypeChange = false;
                foreach ($interaction['type_changes'] ?? [] as $typeChange) {
                    if (is_array($typeChange)
                        && ($typeChange['subject'] ?? null) === $subject
                        && ($typeChange['before'] ?? null) === $change['type_before']
                        && ($typeChange['after'] ?? null) === $change['type_after']) {
                        $canonicalTypeChange = true;
                        break;
                    }
                }
                if (!$canonicalTypeChange) {
                    $typeCurrent = isset($ambiguousSubjects[$subject]) ? null
                        : qd_inspector_clip_bytes(is_string($change['current_type'] ?? null) ? $change['current_type'] : null, 256, $limited);
                    $records[] = qd_mp_record(
                        $ownerId, $ownerName, $target, $at, null, null,
                        qd_inspector_clip_bytes($change['type_before'], 256, $limited),
                        qd_inspector_clip_bytes($change['type_after'], 256, $limited),
                        $typeCurrent, $typeCurrent === null ? null : $currentAsOf,
                        $sourceKind, $reason, 'type', $confirmation,
                        $eventId, $utterance, $subject, 'type-change',
                    );
                }
            }
        }
        foreach ($interaction['type_changes'] ?? [] as $change) {
            if (!is_array($change) || !qd_mp_subject($change['subject'] ?? null)) continue;
            $subject = $change['subject'];
            $target = qd_inspector_clip_bytes(is_string($change['label'] ?? null) ? $change['label'] : 'Unknown', 256, $limited) ?? 'Unknown';
            $before = qd_inspector_clip_bytes(is_string($change['before'] ?? null) ? $change['before'] : null, 256, $limited);
            $after = qd_inspector_clip_bytes(is_string($change['after'] ?? null) ? $change['after'] : null, 256, $limited);
            $current = isset($ambiguousSubjects[$subject]) ? null : qd_inspector_clip_bytes(is_string($change['current_type'] ?? null) ? $change['current_type'] : null, 256, $limited);
            $decisionReason = null;
            foreach ($interaction['type_decisions'] ?? [] as $decision) {
                if (is_array($decision) && ($decision['subject'] ?? null) === $subject) {
                    $decisionReason = qd_mp_fixed_reason($decision['reason'] ?? null);
                    break;
                }
            }
            $records[] = qd_mp_record(
                $ownerId, $ownerName, $target, $at, null, null, $before, $after,
                $current, $current === null ? null : $currentAsOf,
                $sourceKind, $decisionReason ?? $reason, 'type', $confirmation,
                $eventId, $utterance, $subject, 'type-change',
            );
        }
        foreach ($interaction['arrangement_changes'] ?? [] as $change) {
            if (!is_array($change) || !qd_mp_subject($change['subject'] ?? null)) continue;
            $subject = $change['subject'];
            $target = qd_inspector_clip_bytes(is_string($change['label'] ?? null) ? $change['label'] : 'Unknown', 256, $limited) ?? 'Unknown';
            $before = qd_mp_arrangement_value($change['before'] ?? null);
            $after = qd_mp_arrangement_value($change['after'] ?? null);
            $current = isset($ambiguousSubjects[$subject]) ? null : qd_mp_arrangement_value($currentArrangements[$subject] ?? null);
            $outcome = qd_mp_fixed_reason($change['outcome'] ?? null);
            $records[] = qd_mp_record(
                $ownerId, $ownerName, $target, $at, null, null, $before, $after,
                $current, $current === null ? null : $currentAsOf,
                $sourceKind, $outcome, 'arrangement', $confirmation,
                $eventId, $utterance, $subject, 'arrangement',
            );
        }
    }
    return $records;
}

function qd_mp_record(string $ownerId, string $owner, string $target, ?string $at, int|float|null $proposed, int|float|null $applied,
    int|float|string|null $before, int|float|string|null $after, int|float|string|null $current, ?string $currentAsOf,
    ?string $sourceKind, ?string $fixedReason, string $kind, ?string $confirmation,
    string $eventId, string $utterance, string $subject, string $identityKind): array
{
    return [
        'id' => 'mp:' . hash('sha256', implode("\0", [$ownerId, $eventId, $utterance, $subject, $identityKind, (string)$before, (string)$after])),
        'owner' => $owner,
        'target' => $target,
        'at' => $at,
        'proposedDelta' => $proposed,
        'appliedDelta' => $applied,
        'historicalBefore' => $before,
        'historicalAfter' => $after,
        'currentValue' => $current,
        'currentAsOf' => $currentAsOf,
        'sourceKind' => $sourceKind,
        'fixedReason' => $fixedReason,
        'kind' => $kind,
        'confirmation' => $confirmation,
    ];
}

function qd_mp_fixed_reason(mixed $value): ?string
{
    static $allowed = [
        'ledger_recorded', 'change_eligible', 'same_type', 'protected_type_preserved', 'custom_type_preserved',
        'applied', 'ended', 'none', 'reaffirmed', 'older', 'capacity', 'no_claim', 'unconfirmed',
        'paused', 'locked', 'below-floor', 'duplicate', 'stale', 'busy', 'invalid', 'model-invalid',
        'event-mismatch', 'event-unmatched', 'target-ambiguous', 'speaker-ambiguous', 'speaker-locked',
        'interaction-stale', 'restore-policy', 'committed', 'zero-change', 'skipped', 'rejected', 'failed',
        'arrangement_absent', 'arrangement_active', 'arrangement_ended', 'arrangement_none',
    ];
    return is_string($value) && in_array($value, $allowed, true) ? $value : null;
}

function qd_mp_subject(mixed $subject): bool
{
    return $subject === 'player' || (is_string($subject) && preg_match('/\Anpc:[1-9][0-9]{0,18}\z/D', $subject) === 1);
}

function qd_mp_number(mixed $value): int|float|null
{
    return (is_int($value) || is_float($value)) && is_finite((float)$value) ? $value : null;
}

function qd_mp_arrangement_value(mixed $value): ?string
{
    return is_string($value) && in_array($value, ['casual', 'romantic', 'none', 'active', 'active (casual)', 'active (romantic)', 'ended', 'reaffirmed'], true) ? $value : null;
}

function qd_mp_positive_id(mixed $value): ?string
{
    if ((!is_int($value) && !is_string($value)) || preg_match('/\A[1-9][0-9]{0,18}\z/D', (string)$value) !== 1) return null;
    return (string)$value;
}

function qd_mp_begin_read(mixed $connection): void
{
    if (!function_exists('pg_transaction_status') || !function_exists('pg_query') || !function_exists('pg_query_params')
        || pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
        throw new RuntimeException('The CHIM read connection is unavailable.');
    }
    $result = @pg_query($connection, 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
    if ($result === false) throw new RuntimeException('The CHIM read transaction is unavailable.');
    @pg_free_result($result);
    $timeout = @pg_query($connection, "SET LOCAL statement_timeout = '3000ms'");
    if ($timeout === false) throw new RuntimeException('The CHIM read timeout could not be set.');
    @pg_free_result($timeout);
}

function qd_mp_commit(mixed $connection): void
{
    $result = @pg_query($connection, 'COMMIT');
    if ($result === false) throw new RuntimeException('The CHIM read could not be completed.');
    @pg_free_result($result);
}

function qd_mp_rollback(mixed $connection): void
{
    if (function_exists('pg_transaction_status') && pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
        $result = @pg_query($connection, 'ROLLBACK');
        if ($result !== false) @pg_free_result($result);
    }
}

function qd_mp_pg_rows(mixed $connection, string $sql, array $params = []): array
{
    $result = $params === [] ? @pg_query($connection, $sql) : @pg_query_params($connection, $sql, $params);
    if ($result === false) throw new RuntimeException('The bounded CHIM read failed.');
    $rows = [];
    try {
        while (($row = pg_fetch_assoc($result)) !== false) {
            if (!is_array($row)) throw new RuntimeException('The bounded CHIM read returned an invalid row.');
            $rows[] = $row;
        }
    } finally {
        @pg_free_result($result);
    }
    return $rows;
}

function qd_inspector_clip_bytes(?string $value, int $maxBytes, bool &$limited): ?string
{
    if ($value === null) return null;
    if (preg_match('//u', $value) !== 1) {
        $limited = true;
        return null;
    }
    $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    if (!is_string($clean)) {
        $limited = true;
        return null;
    }
    if ($clean !== $value) $limited = true;
    if (strlen($clean) <= $maxBytes) return $clean;
    $limited = true;
    $clean = substr($clean, 0, $maxBytes);
    while ($clean !== '' && preg_match('//u', $clean) !== 1) $clean = substr($clean, 0, -1);
    return $clean;
}

function qd_inspector_page(array $records, int $limit, ?string $cursor, array $binding, string $revision, bool &$limited,
    string $recordKey = 'records', array $fixed = []): array
{
    if ($limit < 1 || $limit > 100 || strlen((string)$cursor) > 512
        || preg_match('/\A[a-f0-9]{64}\z/D', $revision) !== 1) {
        throw new QDServerFault('invalid_request');
    }
    $offset = 0;
    if ($cursor !== null) {
        $decoded = qd_inspector_cursor_decode($cursor);
        foreach (['module', 'operation', 'actorKey', 'targetActorKey', 'scopeKey'] as $key) {
            if (($decoded[$key] ?? null) !== ($binding[$key] ?? null)) {
                throw new QDServerFault($key === 'scopeKey' ? 'stale_context' : 'invalid_request');
            }
        }
        if (($decoded['revision'] ?? null) !== $revision || !is_int($decoded['expires'] ?? null) || $decoded['expires'] < time()) {
            throw new QDServerFault('stale_revision');
        }
        if (!is_int($decoded['offset'] ?? null) || $decoded['offset'] < 0 || $decoded['offset'] > count($records)) {
            throw new QDServerFault('stale_revision');
        }
        $offset = $decoded['offset'];
    }
    $page = [];
    $byteLimited = false;
    $end = min(count($records), $offset + $limit);
    for ($index = $offset; $index < $end; $index++) {
        $trial = [...$page, $records[$index]];
        $next = $index + 1 < count($records)
            ? qd_inspector_cursor_encode($binding, $revision, $index + 1, time() + 600)
            : null;
        $body = $fixed + [$recordKey => $trial, 'limited' => $limited, 'nextCursor' => $next];
        if (strlen(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 60000) {
            if ($page === []) throw new QDServerFault('unavailable');
            $byteLimited = true;
            break;
        }
        $page = $trial;
    }
    $nextOffset = $offset + count($page);
    $hasMore = $nextOffset < count($records);
    if ($hasMore && $page === []) throw new QDServerFault('unavailable');
    $nextCursor = $hasMore ? qd_inspector_cursor_encode($binding, $revision, $nextOffset, time() + 600) : null;
    if ($byteLimited) $limited = true;
    $body = $fixed + [$recordKey => $page, 'limited' => $limited, 'nextCursor' => $nextCursor];
    if (strlen(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 60000) {
        throw new QDServerFault('unavailable');
    }
    return $body;
}

function qd_inspector_cursor_encode(array $binding, string $revision, int $offset, int $expires): string
{
    $json = json_encode([
        'module' => $binding['module'], 'operation' => $binding['operation'], 'actorKey' => $binding['actorKey'],
        'targetActorKey' => $binding['targetActorKey'] ?? null, 'scopeKey' => $binding['scopeKey'],
        'revision' => $revision, 'offset' => $offset, 'expires' => $expires,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $token = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    if (strlen($token) > 512) throw new QDServerFault('unavailable');
    return $token;
}

function qd_inspector_cursor_decode(string $token): array
{
    if ($token === '' || preg_match('/\A[A-Za-z0-9_-]{1,512}\z/D', $token) !== 1) throw new QDServerFault('invalid_request');
    $json = base64_decode(strtr($token, '-_', '+/') . str_repeat('=', (4 - strlen($token) % 4) % 4), true);
    $decoded = is_string($json) ? json_decode($json, true, 8) : null;
    if (!is_array($decoded) || array_diff(array_keys($decoded), ['module','operation','actorKey','targetActorKey','scopeKey','revision','offset','expires']) !== []) {
        throw new QDServerFault('invalid_request');
    }
    return $decoded;
}
