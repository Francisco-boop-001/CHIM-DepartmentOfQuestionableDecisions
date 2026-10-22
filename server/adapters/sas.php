<?php
declare(strict_types=1);

const QD_SAS_EXPECTED_VERSION = '0.1.12';
const QD_SAS_LEDGER_MAX_BYTES = 65536;
const QD_SAS_EXPECTED_SOURCE_HASHES = [
    'manifest.json' => 'cca252133723d6667d65b93034d6c3b6173fb6a71a3f18b41e4ec322dcd96743',
    'ledger.php' => 'cb59355c40fc74a8bd7119d810de2c82457a5b8e6b6453923e226900e4a80375',
    'substance.php' => '30c4086c46a280009c11cfc22f3964f1ed9d72d8a6779938d6790d5057021852',
    'vocab.php' => 'a24b6d116d304adbf504eafc1be29899dbc15b89e4a3d4b9ed0fdf458bec5d5b',
    'written_evidence.php' => '63d26c22684068195d640be79af830e10978d3a0a1b684e90865f1ca3bac3f51',
    'rows.php' => 'e91816c80c4e7b70c303ce2131669de01de75e23a93a5e2da181242ebfe24c38',
];

function qd_sas_source_root(): string
{
    $configured = getenv('QD_SAS_ROOT');
    if (!is_string($configured) || $configured === '' || str_contains($configured, "\0")) {
        throw new RuntimeException('The trusted S&S source root is not configured.');
    }
    $root = realpath($configured);
    if (!is_string($root) || !is_dir($root) || is_link($configured)) {
        throw new RuntimeException('The trusted S&S source root is unavailable.');
    }
    return $root;
}

function qd_sas_source_files(string $root): array
{
    return [
        'manifest.json' => $root . DIRECTORY_SEPARATOR . 'manifest.json',
        'ledger.php' => $root . DIRECTORY_SEPARATOR . 'ledger.php',
        'substance.php' => $root . DIRECTORY_SEPARATOR . 'substance.php',
        'vocab.php' => $root . DIRECTORY_SEPARATOR . 'vocab.php',
        'written_evidence.php' => $root . DIRECTORY_SEPARATOR . 'written_evidence.php',
        'rows.php' => $root . DIRECTORY_SEPARATOR . 'rows.php',
    ];
}

function qd_sas_manifest_ready(string $root): bool
{
    $files = qd_sas_source_files($root);
    foreach (QD_SAS_EXPECTED_SOURCE_HASHES as $file => $expectedHash) {
        $path = $files[$file] ?? null;
        if (!is_string($path) || !is_file($path) || is_link($path)) return false;
        $actualHash = @hash_file('sha256', $path);
        if (!is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) return false;
    }
    $manifest = $root . DIRECTORY_SEPARATOR . 'manifest.json';
    $size = @filesize($manifest);
    if (!is_int($size) || $size < 1 || $size > 8192) return false;
    $data = json_decode((string)@file_get_contents($manifest), true, 8);
    return is_array($data) && ($data['name'] ?? null) === 'sworn_and_scorned'
        && ($data['version'] ?? null) === QD_SAS_EXPECTED_VERSION;
}

function qd_sas_load_owner_sources(): string
{
    static $loadedRoot = null;
    $root = qd_sas_source_root();
    if ($loadedRoot !== null) {
        if ($loadedRoot !== $root) throw new RuntimeException('A different S&S source root is already loaded.');
        return $root;
    }
    if (!qd_sas_manifest_ready($root)) throw new RuntimeException('The pinned S&S source contract is unavailable.');
    require_once $root . DIRECTORY_SEPARATOR . 'ledger.php';
    $function = 'ChimSwornAndScorned\\normalizeLedger';
    if (!function_exists($function)
        || realpath((new ReflectionFunction($function))->getFileName() ?: '') !== realpath($root . DIRECTORY_SEPARATOR . 'ledger.php')) {
        throw new RuntimeException('S&S ledger normalization loaded from an unexpected source root.');
    }
    $loadedRoot = $root;
    return $root;
}

function qd_sas_runtime_operations(): array
{
    return ['sas.issues.read' => static fn(stdClass $payload, array $context): array => qd_sas_issues_read($payload, $context)];
}

function qd_sas_runtime_ready(): bool
{
    try {
        return extension_loaded('mbstring') && qd_sas_manifest_ready(qd_sas_source_root());
    } catch (Throwable) {
        return false;
    }
}

function qd_sas_runtime_perspectives(string $scopeKey): array
{
    try {
        qd_sas_load_owner_sources();
        return qd_runtime_with_core_db(static function ($connection) use ($scopeKey): array {
            qd_sas_begin_read($connection);
            try {
                $active = qd_runtime_active_playthrough($connection);
                if (!hash_equals((string)$active['scopeKey'], $scopeKey)) {
                    throw new RuntimeException('The authenticated CHIM scope changed.');
                }
                $profileId = (string)$active['profileId'];
                $rows = qd_sas_pg_rows($connection,
                    "SELECT npc.id::text AS id, npc.npc_name,
                            CASE WHEN octet_length((npc.plugin_extended_data::jsonb->'sworn_and_scorned')::text) <= " . QD_SAS_LEDGER_MAX_BYTES
                    . " THEN (npc.plugin_extended_data::jsonb->'sworn_and_scorned')::text ELSE NULL END AS ledger,
                            octet_length((npc.plugin_extended_data::jsonb->'sworn_and_scorned')::text)::text AS ledger_bytes,
                            (SELECT count(*) FROM public.core_npc_master duplicate WHERE duplicate.npc_name = npc.npc_name)::text AS name_count
                     FROM public.core_npc_master npc
                     WHERE npc.plugin_extended_data::jsonb->'sworn_and_scorned'->>'playthrough_id' = $1
                     ORDER BY npc.id LIMIT 65",
                    [$profileId]);
                $limited = count($rows) > 64;
                $choices = [];
                foreach (array_slice($rows, 0, 64) as $row) {
                    if (!is_string($row['ledger'] ?? null) || (int)($row['ledger_bytes'] ?? 0) > QD_SAS_LEDGER_MAX_BYTES) {
                        $limited = true;
                        continue;
                    }
                    try {
                        \ChimSwornAndScorned\normalizeLedger(json_decode($row['ledger'], true, 64, JSON_THROW_ON_ERROR), $profileId);
                    } catch (Throwable) {
                        $limited = true;
                        continue;
                    }
                    $id = qd_sas_positive_id($row['id'] ?? null);
                    if ($id === null) {
                        $limited = true;
                        continue;
                    }
                    $name = qd_inspector_clip_bytes(is_string($row['npc_name'] ?? null) ? trim($row['npc_name']) : 'NPC #' . $id, 192, $limited);
                    if ($name === null || $name === '') $name = 'NPC #' . $id;
                    if ((int)($row['name_count'] ?? 0) > 1) $name .= ' (#' . $id . ')';
                    $choices[] = ['actorKey' => 'npc:' . $id, 'label' => $name];
                }
                qd_sas_commit($connection);
                return ['choices' => $choices, 'limited' => $limited];
            } catch (Throwable $error) {
                qd_sas_rollback($connection);
                throw $error;
            }
        });
    } catch (Throwable) {
        return ['choices' => [], 'limited' => true];
    }
}

function qd_sas_issues_read(stdClass $payload, array $context): array
{
    $assertCurrent = $context['assertCurrent'] ?? null;
    if (!is_callable($assertCurrent)) throw new QDServerFault('unavailable');
    $perspectiveKey = $payload->perspectiveActorKey ?? null;
    $limit = $payload->limit ?? 50;
    $cursor = $payload->cursor ?? null;
    if (array_diff(array_keys(get_object_vars($payload)), ['perspectiveActorKey', 'limit', 'cursor']) !== []
        || !is_string($perspectiveKey) || preg_match('/\Anpc:([1-9][0-9]{0,18})\z/D', $perspectiveKey) !== 1
        || !is_int($limit) || $limit < 1 || $limit > 100
        || ($cursor !== null && (!is_string($cursor) || strlen($cursor) > 512))) {
        throw new QDServerFault('invalid_request');
    }
    $assertCurrent();
    qd_sas_load_owner_sources();
    $scopeKey = $context['scopeKey'] ?? null;
    if (!is_string($scopeKey) || preg_match('/\A[a-f0-9]{64}\z/D', $scopeKey) !== 1) {
        throw new QDServerFault('unavailable');
    }
    $read = qd_runtime_with_core_db(static function ($connection) use ($perspectiveKey, $scopeKey): array {
        qd_sas_begin_read($connection);
        try {
            $active = qd_runtime_active_playthrough($connection);
            if (!hash_equals((string)$active['scopeKey'], $scopeKey)) throw new QDServerFault('stale_context');
            $profileId = (string)$active['profileId'];
            $ownerId = substr($perspectiveKey, 4);
            $rows = qd_sas_pg_rows($connection,
                "SELECT id::text AS id, npc_name, xmin::text AS row_version,
                        (plugin_extended_data::jsonb->'sworn_and_scorned' IS NOT NULL) AS ledger_present,
                        octet_length((plugin_extended_data::jsonb->'sworn_and_scorned')::text)::text AS ledger_bytes,
                        CASE WHEN octet_length((plugin_extended_data::jsonb->'sworn_and_scorned')::text) <= " . QD_SAS_LEDGER_MAX_BYTES
                . " THEN (plugin_extended_data::jsonb->'sworn_and_scorned')::text ELSE NULL END AS ledger
                 FROM public.core_npc_master WHERE id = $1 LIMIT 2",
                [$ownerId]);
            if (count($rows) > 1) throw new QDServerFault('unavailable');
            if ($rows === []) throw new QDServerFault('not_found');
            $row = $rows[0];
            if (($row['ledger_present'] ?? 'f') === 't' && !is_string($row['ledger'] ?? null)) throw new QDServerFault('unavailable');
            $ledgerRaw = $row['ledger'] ?? null;
            try {
                $stored = is_string($ledgerRaw) ? json_decode($ledgerRaw, true, 64, JSON_THROW_ON_ERROR) : null;
                $normalized = \ChimSwornAndScorned\normalizeLedger($stored, $profileId);
            } catch (Throwable) {
                throw new QDServerFault('unavailable');
            }
            $ownerName = is_string($row['npc_name'] ?? null) ? trim($row['npc_name']) : '';
            $limited = false;
            $ownerName = qd_inspector_clip_bytes($ownerName === '' ? 'NPC #' . $ownerId : $ownerName, 192, $limited) ?? 'NPC #' . $ownerId;
            $counterpartIds = [];
            foreach ($normalized['issues'] as $issue) {
                if (is_string($issue['counterpart'] ?? null) && preg_match('/\Anpc:([1-9][0-9]*)\z/D', $issue['counterpart'], $matches) === 1) {
                    $counterpartId = qd_sas_positive_id($matches[1]);
                    if ($counterpartId !== null) {
                        $counterpartIds[$counterpartId] = true;
                    } else {
                        $limited = true;
                    }
                }
            }
            $identity = qd_sas_read_counterparts($connection, array_keys($counterpartIds));
            $limited = $limited || $identity['limited'];
            $playerName = qd_sas_player_name($connection);
            $issues = qd_sas_project_issues($normalized, (int)$ownerId, $ownerName, $playerName, $identity['labels'], $limited);
            $revision = hash('sha256', implode("\0", [
                (string)($row['row_version'] ?? ''), (string)($row['ledger'] ?? ''),
                json_encode($identity['revision'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $playerName,
            ]));
            qd_sas_commit($connection);
            return [
                'ownerName' => $ownerName, 'issues' => $issues, 'limited' => $limited,
                'revision' => $revision, 'playerName' => $playerName,
            ];
        } catch (Throwable $error) {
            qd_sas_rollback($connection);
            throw $error;
        }
    });
    $assertCurrent();
    return qd_inspector_page($read['issues'], $limit, $cursor, [
        'module' => 'sas', 'operation' => 'sas.issues.read', 'actorKey' => $perspectiveKey,
        'targetActorKey' => null, 'scopeKey' => $scopeKey,
    ], $read['revision'], $read['limited'], 'issues', ['perspectiveActorKey' => $perspectiveKey]);
}

function qd_sas_project_issues(array $ledger, int $ownerId, string $ownerName, string $playerName, array $npcNames, bool &$limited): array
{
    $result = [];
    foreach ($ledger['issues'] ?? [] as $issue) {
        if (!is_array($issue)) continue;
        $id = is_string($issue['id'] ?? null) ? $issue['id'] : '';
        $kind = in_array($issue['kind'] ?? null, ['grievance', 'promise'], true) ? $issue['kind'] : null;
        $counterpartKey = is_string($issue['counterpart'] ?? null) ? $issue['counterpart'] : '';
        if ($id === '' || $kind === null || $counterpartKey === '') continue;
        if (strlen($id) <= 128 && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $id) === 1) {
            $publicId = $id;
        } else {
            $publicId = 'sas:' . hash('sha256', $ownerId . "\0" . $id . "\0" . $counterpartKey . "\0" . $kind);
        }
        if ($counterpartKey === 'player') {
            $counterpart = $playerName;
        } elseif (preg_match('/\Anpc:([1-9][0-9]*)\z/D', $counterpartKey, $matches) === 1) {
            $counterpartId = $matches[1];
            $counterpart = $npcNames[$counterpartId] ?? 'NPC #' . $counterpartId;
        } else {
            $counterpart = $counterpartKey;
        }
        $substance = is_array($issue['substance'] ?? null) ? $issue['substance'] : null;
        $matter = is_array($substance['matter'] ?? null) && is_string($substance['matter']['text'] ?? null)
            ? qd_inspector_clip_bytes($substance['matter']['text'], 2000, $limited) : null;
        $nature = is_string($substance['nature'] ?? null) ? $substance['nature'] : 'unknown';
        $nature = qd_inspector_clip_bytes($nature, 512, $limited) ?? 'unknown';
        $settlement = 'unknown';
        if (($substance['settlement']['mode'] ?? null) === 'complete_obligation') {
            $settlement = 'complete_obligation';
        } elseif (($substance['settlement']['mode'] ?? null) === 'explicit'
            && is_string($substance['settlement']['source']['text'] ?? null)) {
            $settlement = qd_inspector_clip_bytes($substance['settlement']['source']['text'], 256, $limited) ?? 'unknown';
        }
        $result[] = [
            'id' => $publicId,
            'kind' => $kind,
            'owner' => $ownerName,
            'counterpart' => qd_inspector_clip_bytes($counterpart, 256, $limited) ?? 'Unknown',
            'direction' => $kind === 'promise' && is_string($issue['direction'] ?? null) ? $issue['direction'] : null,
            'matter' => $matter,
            'nature' => $nature,
            'status' => is_string($issue['status'] ?? null) ? $issue['status'] : null,
            'settlement' => $settlement,
        ];
    }
    return $result;
}

function qd_sas_read_counterparts(mixed $connection, array $ids): array
{
    $labels = [];
    $revision = [];
    if ($ids === []) return ['labels' => [], 'revision' => [], 'limited' => false];
    $rows = qd_sas_pg_rows($connection,
        "SELECT npc.id::text AS id, npc.npc_name,
                (SELECT count(*) FROM public.core_npc_master duplicate WHERE duplicate.npc_name = npc.npc_name)::text AS name_count
         FROM public.core_npc_master npc WHERE npc.id = ANY($1::bigint[]) ORDER BY npc.id LIMIT 65",
        ['{' . implode(',', $ids) . '}']);
    $limited = count($rows) > 64;
    foreach (array_slice($rows, 0, 64) as $row) {
        $id = qd_sas_positive_id($row['id'] ?? null);
        if ($id === null) continue;
        $name = is_string($row['npc_name'] ?? null) ? trim($row['npc_name']) : '';
        if ($name === '') $name = 'NPC #' . $id;
        if ((int)($row['name_count'] ?? 0) > 1) $name .= ' (#' . $id . ')';
        $nameLimited = false;
        $labels[$id] = qd_inspector_clip_bytes($name, 256, $nameLimited) ?? 'NPC #' . $id;
        $limited = $limited || $nameLimited;
        $revision[$id] = [$row['npc_name'] ?? null, $row['name_count'] ?? null];
    }
    return ['labels' => $labels, 'revision' => $revision, 'limited' => $limited];
}

function qd_sas_player_name(mixed $connection): string
{
    $rows = qd_sas_pg_rows($connection,
        "SELECT value AS player_name FROM public.core_player WHERE id = 'player_name' LIMIT 2");
    if (count($rows) !== 1 || !is_string($rows[0]['player_name'] ?? null)) return 'Player';
    $limited = false;
    return qd_inspector_clip_bytes($rows[0]['player_name'], 192, $limited) ?: 'Player';
}

function qd_sas_positive_id(mixed $value): ?string
{
    if ((!is_int($value) && !is_string($value)) || preg_match('/\A[1-9][0-9]*\z/D', (string)$value) !== 1) return null;
    $digits = (string)$value;
    $maximum = '9223372036854775807';
    if (strlen($digits) > strlen($maximum)
        || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) return null;
    return $digits;
}

function qd_sas_begin_read(mixed $connection): void
{
    if (!function_exists('pg_transaction_status') || !function_exists('pg_query') || !function_exists('pg_query_params')
        || pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
        throw new RuntimeException('The CHIM read connection is unavailable.');
    }
    $result = @pg_query($connection, 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
    if ($result === false) throw new RuntimeException('The S&S read transaction is unavailable.');
    @pg_free_result($result);
    $timeout = @pg_query($connection, "SET LOCAL statement_timeout = '3000ms'");
    if ($timeout === false) throw new RuntimeException('The S&S read timeout could not be set.');
    @pg_free_result($timeout);
}

function qd_sas_commit(mixed $connection): void
{
    $result = @pg_query($connection, 'COMMIT');
    if ($result === false) throw new RuntimeException('The S&S read could not be completed.');
    @pg_free_result($result);
}

function qd_sas_rollback(mixed $connection): void
{
    if (function_exists('pg_transaction_status') && pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
        $result = @pg_query($connection, 'ROLLBACK');
        if ($result !== false) @pg_free_result($result);
    }
}

function qd_sas_pg_rows(mixed $connection, string $sql, array $params = []): array
{
    $result = $params === [] ? @pg_query($connection, $sql) : @pg_query_params($connection, $sql, $params);
    if ($result === false) throw new RuntimeException('The bounded S&S read failed.');
    $rows = [];
    try {
        while (($row = pg_fetch_assoc($result)) !== false) {
            if (!is_array($row)) throw new RuntimeException('The bounded S&S read returned an invalid row.');
            $rows[] = $row;
        }
    } finally {
        @pg_free_result($result);
    }
    return $rows;
}
