<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'companion_cases.php';

/**
 * Private PostgreSQL scenario runner for the real Department API facade.
 * This file never starts or stops PostgreSQL; the parent runner owns that cluster.
 */

final class Dev18ScenarioFailure extends RuntimeException {}

function dev20_require(bool $condition, string $evidence): void
{
    if (!$condition) throw new Dev18ScenarioFailure($evidence);
}

function dev20_fail(string $code): never
{
    fwrite(STDERR, 'dev20-pg-scenarios-failure:' . $code . PHP_EOL);
    exit(2);
}

function dev20_safe_failure_detail(Throwable $error): string
{
    $message = $error->getMessage();
    if (preg_match('/\\A[a-z][a-z0-9_]{0,63}\\z/iD', $message) !== 1) return 'detail_unavailable';
    return strtolower($message);
}

function dev20_json_write(string $path, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('evidence_write_failed');
}

function dev20_json_line(string $path, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX) === false) throw new RuntimeException('evidence_append_failed');
}

function dev20_real_dir(mixed $value, string $label): string
{
    if (!is_string($value) || $value === '' || is_link($value)) throw new InvalidArgumentException($label . '_invalid');
    $real = realpath($value);
    if (!is_string($real) || !is_dir($real)) throw new InvalidArgumentException($label . '_missing');
    return $real;
}

function dev20_resolve_runuser(): string
{
    foreach (['/usr/sbin/runuser', '/usr/bin/runuser'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate) && !is_link($candidate)) return $candidate;
    }
    throw new RuntimeException('runuser_not_found_in_supported_system_paths');
}

function dev20_conninfo(string $socket, string $database, string $role): string
{
    $quote = static fn(string $value): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    return 'host=' . $quote($socket) . ' port=5432 dbname=' . $quote($database) . ' user=' . $quote($role) . ' connect_timeout=3';
}

function dev20_pg(mixed $connection, string $sql, array $params = []): array
{
    if (!is_object($connection)) throw new RuntimeException('database_connection_missing');
    if (stripos($sql, 'pg_stat_activity') !== false) {
        $snapshot = @pg_query($connection, 'SELECT pg_stat_clear_snapshot()');
        if ($snapshot === false) throw new RuntimeException('database_stats_snapshot_clear_failed');
        @pg_free_result($snapshot);
    }
    $result = $params === [] ? @pg_query($connection, $sql) : @pg_query_params($connection, $sql, $params);
    if ($result === false) throw new RuntimeException('database_query_failed');
    try {
        if (pg_num_fields($result) === 0) return [];
        return pg_fetch_all($result) ?: [];
    } finally {
        @pg_free_result($result);
    }
}

function dev20_pg_scalar(mixed $connection, string $sql, array $params = []): mixed
{
    $rows = dev20_pg($connection, $sql, $params);
    if (count($rows) !== 1 || $rows[0] === []) return null;
    return reset($rows[0]);
}

function dev20_admin_connect(string $socket, string $database, string $role): mixed
{
    $connection = @pg_connect(dev20_conninfo($socket, $database, $role), PGSQL_CONNECT_FORCE_NEW);
    if ($connection === false || $connection === null) throw new RuntimeException('admin_connection_failed');
    return $connection;
}

function dev20_owner_snapshot(mixed $connection): array
{
    $tables = dev20_pg($connection,
        "SELECT n.nspname,c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace "
        . "WHERE c.relkind IN ('r','p') AND n.nspname IN ('plugins','public','chim_meta','chim_profile_dev20') "
        . 'ORDER BY n.nspname,c.relname');
    $counts = [];
    $contentHashes = [];
    foreach ($tables as $table) {
        $schema = (string)$table['nspname'];
        $name = (string)$table['relname'];
        $qualified = pg_escape_identifier($connection, $schema) . '.' . pg_escape_identifier($connection, $name);
        $count = dev20_pg_scalar($connection, 'SELECT count(*)::text FROM ' . $qualified);
        $counts[$schema . '.' . $name] = is_string($count) ? $count : (string)$count;
        $rows = dev20_pg($connection, 'SELECT to_jsonb(t)::text AS row_json FROM ' . $qualified . ' AS t ORDER BY 1');
        $serialized = array_map(static fn(array $row): string => (string)($row['row_json'] ?? ''), $rows);
        $contentHashes[$schema . '.' . $name] = [
            'rows' => count($serialized),
            'sha256' => hash('sha256', implode("\n", $serialized)),
        ];
    }
    $schemaPresent = dev20_pg_scalar($connection, "SELECT to_regnamespace('plugins') IS NOT NULL");
    $stateRows = [];
    if ($schemaPresent === 't' || $schemaPresent === true) {
        $statePresent = dev20_pg_scalar($connection, "SELECT to_regclass('plugins.correspondence_restore_state') IS NOT NULL");
        if ($statePresent === 't' || $statePresent === true) {
            $stateRows = dev20_pg($connection,
                'SELECT epoch::text AS epoch,pending::text AS pending,native_ts_floor::text AS native_ts_floor '
                . 'FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
        }
    }
    return ['pluginsSchemaPresent' => in_array($schemaPresent, ['t', true], true), 'counts' => $counts,
        'contentHashes' => $contentHashes, 'restoreState' => $stateRows];
}

function dev20_row_delta(array $before, array $after): array
{
    $keys = array_unique(array_merge(array_keys($before['counts'] ?? []), array_keys($after['counts'] ?? [])));
    sort($keys, SORT_STRING);
    $delta = [];
    foreach ($keys as $key) {
        $old = (int)($before['counts'][$key] ?? 0);
        $new = (int)($after['counts'][$key] ?? 0);
        if ($old !== $new) $delta[$key] = $new - $old;
    }
    return $delta;
}

function dev20_unchanged(array $before, array $after, array $ignoredTables = []): bool
{
    if (($before['pluginsSchemaPresent'] ?? null) !== ($after['pluginsSchemaPresent'] ?? null)) return false;
    if (($before['counts'] ?? null) !== ($after['counts'] ?? null)) return false;
    $beforeHashes = $before['contentHashes'] ?? [];
    $afterHashes = $after['contentHashes'] ?? [];
    foreach (array_unique(array_merge(array_keys($beforeHashes), array_keys($afterHashes))) as $table) {
        if (in_array($table, $ignoredTables, true)) continue;
        if (($beforeHashes[$table] ?? null) !== ($afterHashes[$table] ?? null)) return false;
    }
    if (!in_array('plugins.correspondence_restore_state', $ignoredTables, true)
        && ($before['restoreState'] ?? null) !== ($after['restoreState'] ?? null)) return false;
    return true;
}

function dev20_set_www_data_file(string $path, int $mode): void
{
    if (!is_file($path) || is_link($path)) throw new RuntimeException('private_file_path_invalid');
    if (function_exists('posix_geteuid') && posix_geteuid() === 0
        && (!@chown($path, 'www-data') || !@chgrp($path, 'www-data'))) {
        throw new RuntimeException('private_file_owner_failed');
    }
    if (!@chmod($path, $mode)) throw new RuntimeException('private_file_mode_failed');
}

function dev20_prepare_private_output_dir(string $coreRoot, string $relative): string
{
    if (!in_array($relative, ['log/playthrough_runtime', 'data/books'], true)) throw new RuntimeException('private_output_path_not_allowlisted');
    $path = $coreRoot;
    foreach (explode('/', $relative) as $component) {
        $path .= DIRECTORY_SEPARATOR . $component;
        if (is_link($path)) throw new RuntimeException('private_output_path_redirected');
    }
    if (!is_dir($path) && !@mkdir($path, 0770, true) && !is_dir($path)) throw new RuntimeException('private_output_directory_create_failed');
    $real = realpath($path);
    if (!is_string($real) || !str_starts_with($real . DIRECTORY_SEPARATOR, rtrim($coreRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('private_output_path_escape');
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0
        && (!@chown($real, 'www-data') || !@chgrp($real, 'www-data'))) {
        throw new RuntimeException('private_output_owner_failed');
    }
    if (!@chmod($real, 02770)) throw new RuntimeException('private_output_mode_failed');
    return $real;
}

function dev20_owner_file_pins(string $coreRoot, string $ownerRoot, string $testRoot): array
{
    $pinPath = $testRoot . DIRECTORY_SEPARATOR . 'owner-runtime-pins.json';
    $raw = @file_get_contents($pinPath);
    $manifest = is_string($raw) ? json_decode($raw, true, 16) : null;
    if (!is_array($manifest) || ($manifest['schema'] ?? null) !== 'department.dev23.owner-runtime-pins.v1'
        || ($manifest['ownerRoot'] ?? null) !== 'K:\\ActorwrightExchange\\artifacts\\chim-sealed-fates-release-dev14-20261020\\source'
        || ($manifest['ownerVersion'] ?? null) !== '0.1.0-dev.23'
        || ($manifest['ownerCommit'] ?? null) !== '01d62982d9b10ca4fce040af9d97fe7111231ba3'
        || ($manifest['ownerClosureCount'] ?? null) !== 19
        || ($manifest['fullPayloadCount'] ?? null) !== 30
        || !is_array($manifest['files'] ?? null) || count($manifest['files']) !== 19
        || !is_array($manifest['payloadFiles'] ?? null) || count($manifest['payloadFiles']) !== 11) {
        throw new RuntimeException('owner_pin_manifest_invalid');
    }
    $candidate = $ownerRoot . DIRECTORY_SEPARATOR . 'server';
    $staged = $coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'correspondence';
    foreach ([$candidate . DIRECTORY_SEPARATOR . 'manifest.json', $staged . DIRECTORY_SEPARATOR . 'manifest.json'] as $serverManifestPath) {
        if (is_link($serverManifestPath) || !is_file($serverManifestPath)) throw new RuntimeException('owner_server_manifest_missing');
        $serverManifest = json_decode((string)file_get_contents($serverManifestPath), true, 8);
        if (!is_array($serverManifest) || ($serverManifest['name'] ?? null) !== 'correspondence'
            || ($serverManifest['version'] ?? null) !== '0.1.0-dev.23') throw new RuntimeException('owner_server_manifest_identity_invalid');
    }
    $pins = [];
    foreach ($manifest['files'] as $entry) {
        $relative = $entry['path'] ?? null;
        if (!is_string($relative) || !str_starts_with($relative, 'server/') || str_contains($relative, '..')) {
            throw new RuntimeException('owner_pin_path_invalid');
        }
        $ownerRelative = substr($relative, strlen('server/'));
        $expected = strtolower((string)($entry['sha256'] ?? ''));
        $expectedBytes = $entry['bytes'] ?? null;
        if (!is_int($expectedBytes) || $expectedBytes < 0 || preg_match('/\A[a-f0-9]{64}\z/D', $expected) !== 1) {
            throw new RuntimeException('owner_pin_entry_invalid');
        }
        foreach ([$candidate, $staged] as $root) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ownerRelative);
            if (is_link($path) || !is_file($path) || filesize($path) !== $expectedBytes
                || !hash_equals($expected, strtolower((string)hash_file('sha256', $path)))) {
                throw new RuntimeException('owner_pin_mismatch');
            }
        }
        $pins[$ownerRelative] = $expected;
    }
    return $pins;
}

function dev20_random_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function dev20_compose_payload(?string $operationId = null, array $overrides = []): array
{
    return array_replace([
        'operationId' => $operationId ?? dev20_random_uuid(),
        'writerActorKey' => 'player',
        'recipientActorKey' => 'npc:900003',
        'body' => 'A synthetic private letter for dev20 facade verification.',
        'inventoryRevision' => 'synthetic-dev20-v1',
        'attachments' => [['form_id' => 'Skyrim.esm|00000001', 'quantity' => 2]],
        'goldAmount' => 0,
    ], $overrides);
}

function dev20_process_identity(int $pid): ?array
{
    if ($pid < 1) return null;
    $stat = @file_get_contents('/proc/' . $pid . '/stat');
    $close = is_string($stat) ? strrpos($stat, ')') : false;
    if ($close === false) return null;
    $fields = preg_split('/\s+/', trim(substr($stat, $close + 1)));
    if (!is_array($fields) || !isset($fields[0], $fields[2], $fields[3], $fields[19])
        || !ctype_digit($fields[2]) || !ctype_digit($fields[3]) || !ctype_digit($fields[19])) return null;
    $directory = '/proc/' . $pid;
    clearstatcache(true, $directory);
    $uid = @fileowner($directory);
    $executable = @readlink($directory . '/exe');
    if (!is_string($executable) || $executable === '') {
        $commandLine = @file_get_contents($directory . '/cmdline');
        $executable = is_string($commandLine) && $commandLine !== '' ? explode("\0", $commandLine, 2)[0] : '';
    }
    $command = $executable === '' ? '' : basename(preg_replace('/ \(deleted\)\z/', '', $executable));
    return ['pid' => $pid, 'state' => $fields[0], 'groupId' => (int)$fields[2], 'sessionId' => (int)$fields[3],
        'startTime' => $fields[19], 'uid' => is_int($uid) ? $uid : null, 'command' => $command];
}

function dev20_process_start_time(int $pid): ?string
{
    return dev20_process_identity($pid)['startTime'] ?? null;
}

function dev20_process_group_members(array $child): array
{
    $groupId = (int)($child['pgid'] ?? 0);
    $sessionId = (int)($child['sid'] ?? 0);
    if ($groupId < 1 || $sessionId !== $groupId || $groupId !== (int)($child['pid'] ?? 0)) return [];
    $members = [];
    foreach (glob('/proc/[0-9]*/stat') ?: [] as $statPath) {
        if (preg_match('~\A/proc/([0-9]+)/stat\z~D', $statPath, $match) !== 1) continue;
        $identity = dev20_process_identity((int)$match[1]);
        if ($identity === null || $identity['groupId'] !== $groupId || $identity['sessionId'] !== $sessionId) continue;
        $members[] = $identity;
    }
    return $members;
}

function dev20_process_group_owned(array $child): bool
{
    $pid = (int)($child['pid'] ?? 0);
    if ($pid < 1 || ($child['groupVerified'] ?? false) !== true || ($child['startTime'] ?? null) === null
        || (int)($child['pgid'] ?? 0) !== $pid || (int)($child['sid'] ?? 0) !== $pid
        || !@posix_kill(-$pid, 0)) return false;
    $leader = dev20_process_identity($pid);
    if ($leader !== null && ($leader['startTime'] !== $child['startTime'] || $leader['groupId'] !== $pid || $leader['sessionId'] !== $pid)) {
        return false;
    }
    $allowedUids = $child['allowedUids'] ?? [];
    $allowedCommands = $child['allowedCommands'] ?? [];
    $members = dev20_process_group_members($child);
    if ($members === []) return false;
    foreach ($members as $member) {
        if (!is_int($member['uid']) || !in_array($member['uid'], $allowedUids, true)) return false;
        if ($member['pid'] === $pid && $member['startTime'] !== $child['startTime']) return false;
        if ($member['state'] !== 'Z' && !in_array($member['command'], $allowedCommands, true)) return false;
    }
    return true;
}

function dev20_process_group_owned_or_gone(array $child, string $failure): bool
{
    $pid = (int)($child['pid'] ?? 0);
    if ($pid <= 1 || ($child['groupVerified'] ?? false) !== true
        || (int)($child['pgid'] ?? 0) !== $pid || (int)($child['sid'] ?? 0) !== $pid) {
        throw new RuntimeException('child_process_group_identity_unverified');
    }
    for ($attempt = 0; $attempt < 4; $attempt++) {
        if (dev20_process_group_owned($child)) return true;
        if (!@posix_kill(-$pid, 0)) return false;
        usleep(5000);
    }
    throw new RuntimeException($failure);
}

function dev20_process_group_matches(array $child): bool
{
    $pid = (int)($child['pid'] ?? 0);
    if ($pid < 1 || ($child['groupVerified'] ?? false) !== true || ($child['startTime'] ?? null) === null) return false;
    return dev20_process_start_time($pid) === $child['startTime']
        && @posix_getpgid($pid) === $pid
        && @posix_getsid($pid) === $pid;
}

function dev20_signal_owned_process_group(array $child, int $signal): bool
{
    $pid = (int)($child['pid'] ?? 0);
    if (!dev20_process_group_owned_or_gone($child, 'child_process_group_identity_unverified_before_signal')) return false;
    if (!dev20_process_group_owned_or_gone($child, 'child_process_group_identity_changed_before_signal')) return false;
    if (!@posix_kill(-$pid, $signal)) {
        if (!dev20_process_group_owned_or_gone($child, 'child_process_group_identity_changed_after_signal_failure')) return false;
        throw new RuntimeException('child_process_group_signal_failed');
    }
    return true;
}

function dev20_stop_owned_process_group(array &$child): int
{
    $pid = (int)($child['pid'] ?? 0);
    if (!dev20_process_group_owned_or_gone($child, 'child_process_group_identity_unverified_before_stop')) return 0;
    $memberCount = count(dev20_process_group_members($child));
    if (!dev20_signal_owned_process_group($child, SIGTERM)) return $memberCount;
    $termDeadline = microtime(true) + 0.25;
    do {
        dev20_drain_output($child);
        if (!dev20_process_group_owned_or_gone($child, 'child_process_group_identity_changed_during_term_grace')) return $memberCount;
        usleep(20000);
    } while (microtime(true) < $termDeadline);
    if (dev20_process_group_owned_or_gone($child, 'child_process_group_identity_changed_before_kill')) {
        dev20_signal_owned_process_group($child, SIGKILL);
    }
    return $memberCount;
}

function dev20_wait_owned_process_group_empty(array $child, int $timeoutMilliseconds = 3000): void
{
    $pid = (int)($child['pid'] ?? 0);
    $deadline = microtime(true) + ($timeoutMilliseconds / 1000);
    while (dev20_process_group_owned_or_gone($child, 'child_process_group_identity_changed_while_waiting_empty')) {
        if (microtime(true) >= $deadline) throw new RuntimeException('child_process_group_reap_timeout');
        usleep(20000);
    }
}

function dev20_spawn_process(array $argv, ?string $stdin, array $environment = []): array
{
    if (!is_file('/usr/bin/setsid') || !is_executable('/usr/bin/setsid') || is_link('/usr/bin/setsid')
        || !function_exists('posix_getpgid') || !function_exists('posix_getsid') || !function_exists('posix_kill')
        || !function_exists('posix_geteuid') || !function_exists('posix_getpwnam')) {
        throw new RuntimeException('owned_process_group_prerequisite_missing');
    }
    $pipes = [];
    $command = array_merge(['/usr/bin/setsid'], $argv);
    $proc = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment, ['bypass_shell' => true]);
    if (!is_resource($proc)) throw new RuntimeException('child_process_start_failed');
    $status = @proc_get_status($proc);
    $pid = is_array($status) ? (int)($status['pid'] ?? 0) : 0;
    $startTime = null;
    $identityDeadline = microtime(true) + 1;
    do {
        $startTime = dev20_process_start_time($pid);
        if ($startTime !== null && @posix_getpgid($pid) === $pid && @posix_getsid($pid) === $pid) break;
        if (!is_array($status) || !($status['running'] ?? false)) break;
        usleep(1000);
        $status = @proc_get_status($proc);
    } while (microtime(true) < $identityDeadline);
    $groupVerified = $startTime !== null && is_array($status) && (int)($status['pid'] ?? 0) === $pid
        && @posix_getpgid($pid) === $pid && @posix_getsid($pid) === $pid;
    $allowedUids = [posix_geteuid()];
    $wwwData = posix_getpwnam('www-data');
    if (is_array($wwwData) && is_int($wwwData['uid'] ?? null)) $allowedUids[] = $wwwData['uid'];
    $allowedCommands = ['setsid', 'runuser', 'env', 'php', basename(PHP_BINARY), 'sh', 'dash', 'sleep', basename((string)($argv[0] ?? ''))];
    $child = ['proc' => $proc, 'pipes' => $pipes, 'stdout' => '', 'stderr' => '', 'exitCode' => null,
        'pid' => $pid, 'pgid' => $pid, 'sid' => $pid, 'startTime' => $groupVerified ? $startTime : null,
        'groupVerified' => $groupVerified, 'allowedUids' => array_values(array_unique($allowedUids)),
        'allowedCommands' => array_values(array_unique(array_filter($allowedCommands))),
        'startedAt' => microtime(true)];
    if (is_array($status) && ($status['running'] ?? false) && !$groupVerified) {
        @proc_terminate($proc, 9);
        foreach ([0, 1, 2] as $index) if (is_resource($pipes[$index] ?? null)) @fclose($pipes[$index]);
        @proc_close($proc);
        throw new RuntimeException('child_process_group_setup_failed');
    }
    if ($stdin !== null) {
        $written = fwrite($pipes[0], $stdin);
        if ($written === false || $written !== strlen($stdin)) {
            if ($groupVerified) {
                try { dev20_signal_owned_process_group($child, SIGKILL); } catch (Throwable) { @proc_terminate($proc, 9); }
            } else {
                @proc_terminate($proc, 9);
            }
            foreach ([0, 1, 2] as $index) if (is_resource($pipes[$index] ?? null)) @fclose($pipes[$index]);
            @proc_close($proc);
            throw new RuntimeException('child_input_write_failed');
        }
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return $child;
}

function dev20_drain_output(array &$child): void
{
    foreach ([1 => 'stdout', 2 => 'stderr'] as $index => $field) {
        if (is_resource($child['pipes'][$index] ?? null)) {
            $chunk = stream_get_contents($child['pipes'][$index]);
            if (is_string($chunk) && $chunk !== '') $child[$field] .= $chunk;
        }
    }
}

function dev20_drain_process(array &$child): bool
{
    dev20_drain_output($child);
    $status = proc_get_status($child['proc']);
    if (!$status['running']) $child['exitCode'] = $status['exitcode'];
    return !$status['running'];
}

function dev20_finish_process(array &$child, int $timeoutSeconds = 120): array
{
    $deadline = microtime(true) + $timeoutSeconds;
    $timedOut = false;
    do {
        $done = dev20_drain_process($child);
        if ($done) break;
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            dev20_signal_owned_process_group($child, SIGTERM);
            $graceDeadline = microtime(true) + 0.25;
            do {
                dev20_drain_output($child);
                usleep(20000);
            } while (microtime(true) < $graceDeadline);
            if (@posix_kill(-(int)$child['pid'], 0)) dev20_signal_owned_process_group($child, SIGKILL);
            $reapDeadline = microtime(true) + 3;
            do {
                $done = dev20_drain_process($child);
                if ($done) break;
                usleep(20000);
            } while (microtime(true) < $reapDeadline);
            if (!$done) throw new RuntimeException('child_process_group_reap_timeout');
            break;
        }
        usleep(20000);
    } while (true);
    $groupCleanupMembers = dev20_stop_owned_process_group($child);
    dev20_drain_output($child);
    foreach ([1, 2] as $index) if (is_resource($child['pipes'][$index] ?? null)) fclose($child['pipes'][$index]);
    $closed = @proc_close($child['proc']);
    $child['proc'] = null;
    dev20_wait_owned_process_group_empty($child);
    $code = is_int($child['exitCode']) && $child['exitCode'] >= 0 ? $child['exitCode'] : $closed;
    return ['exitCode' => $code, 'stdout' => $child['stdout'], 'stderr' => $child['stderr'], 'timedOut' => $timedOut,
        'groupCleanupMembers' => $groupCleanupMembers];
}

function dev20_reap_process(array &$child, int $timeoutSeconds = 5): void
{
    try {
        dev20_finish_process($child, $timeoutSeconds);
        return;
    } catch (Throwable) {
        $process = $child['proc'] ?? null;
        if (is_resource($process)) {
            if (($child['groupVerified'] ?? false) === true) {
                try { dev20_signal_owned_process_group($child, SIGKILL); } catch (Throwable) { @proc_terminate($process, 9); }
            } else {
                @proc_terminate($process, 9);
            }
            $deadline = microtime(true) + 2;
            do {
                $status = @proc_get_status($process);
                if (!is_array($status) || !($status['running'] ?? false)) break;
                usleep(20000);
            } while (microtime(true) < $deadline);
            foreach ([1, 2] as $index) if (is_resource($child['pipes'][$index] ?? null)) @fclose($child['pipes'][$index]);
            @proc_close($process);
        }
    }
}

function dev20_persist_process_output_files(string $directory, array $result): void
{
    if (is_link($directory) || !is_dir($directory) || realpath($directory) !== $directory) {
        throw new RuntimeException('process_output_directory_invalid');
    }
    foreach (['api.stdout.jsonl' => 'stdout', 'api.stderr.log' => 'stderr'] as $name => $field) {
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_link($path)) throw new RuntimeException('process_output_path_redirected');
        $bytes = $result[$field] ?? null;
        if (!is_string($bytes) || file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('process_output_persist_failed');
        }
    }
}

function dev20_process_group_self_test(): array
{
    if (PHP_OS_FAMILY !== 'Linux') throw new RuntimeException('process_group_self_test_linux_required');
    dev20_require(dev20_safe_failure_detail(new RuntimeException('artifact_directory_run_mismatch')) === 'artifact_directory_run_mismatch',
        'safe_preflight_detail_not_retained');
    dev20_require(dev20_safe_failure_detail(new RuntimeException('/mnt/private/source/path')) === 'detail_unavailable',
        'unsafe_preflight_detail_was_not_redacted');
    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dev20-process-group-' . bin2hex(random_bytes(8));
    if (file_exists($directory) || is_link($directory) || !@mkdir($directory, 0700)) throw new RuntimeException('process_group_self_test_directory_failed');
    $childScript = $directory . DIRECTORY_SEPARATOR . 'child.php';
    $pidPath = $directory . DIRECTORY_SEPARATOR . 'pids.json';
    $earlyPidPath = $directory . DIRECTORY_SEPARATOR . 'early-descendant.pid';
    $earlyReleasePath = $directory . DIRECTORY_SEPARATOR . 'early-release';
    $program = <<<'PHP'
<?php
$descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']];
$grandchild = proc_open([PHP_BINARY, '-r', 'usleep(30000000);'], $descriptors, $pipes, null, [] , ['bypass_shell' => true]);
if (!is_resource($grandchild)) exit(31);
$status = proc_get_status($grandchild);
$record = ['childPid' => getmypid(), 'grandchildPid' => (int)($status['pid'] ?? 0)];
if ($record['grandchildPid'] < 1 || file_put_contents($argv[1], json_encode($record, JSON_THROW_ON_ERROR)) === false) exit(32);
echo "timeout-stdout\n";
fwrite(STDERR, "timeout-stderr\n");
fflush(STDOUT);
fflush(STDERR);
sleep(30);
PHP;
    if (file_put_contents($childScript, $program, LOCK_EX) === false) throw new RuntimeException('process_group_self_test_script_failed');
    $child = null;
    $earlyChild = null;
    try {
        $child = dev20_spawn_process([PHP_BINARY, $childScript, $pidPath], null, ['PATH' => '/usr/bin:/bin']);
        dev20_require($child['groupVerified'] === true && dev20_process_group_matches($child), 'process_group_self_test_leader_unverified');
        $deadline = microtime(true) + 5;
        do {
            $recordBytes = @file_get_contents($pidPath);
            $record = is_string($recordBytes) ? json_decode($recordBytes, true) : null;
            if (is_array($record) && ($record['childPid'] ?? null) === $child['pid']) break;
            if (microtime(true) >= $deadline) throw new RuntimeException('process_group_self_test_grandchild_timeout');
            usleep(20000);
        } while (true);
        $grandchildPid = (int)$record['grandchildPid'];
        dev20_require($grandchildPid > 0 && @posix_getpgid($grandchildPid) === $child['pid']
            && @posix_getsid($grandchildPid) === $child['pid'], 'process_group_self_test_descendant_outside_session');
        $result = dev20_finish_process($child, 1);
        $child = null;
        dev20_require(($result['timedOut'] ?? false) === true, 'process_group_self_test_did_not_timeout');
        dev20_require(($result['stdout'] ?? null) === "timeout-stdout\n" && ($result['stderr'] ?? null) === "timeout-stderr\n",
            'process_group_self_test_output_capture_failed');
        dev20_persist_process_output_files($directory, $result);
        dev20_require(file_get_contents($directory . DIRECTORY_SEPARATOR . 'api.stdout.jsonl') === "timeout-stdout\n"
            && file_get_contents($directory . DIRECTORY_SEPARATOR . 'api.stderr.log') === "timeout-stderr\n",
            'process_group_self_test_timeout_artifacts_missing');
        $goneDeadline = microtime(true) + 5;
        do {
            if (dev20_process_start_time((int)$record['childPid']) === null
                && dev20_process_start_time($grandchildPid) === null) break;
            if (microtime(true) >= $goneDeadline) throw new RuntimeException('process_group_self_test_orphan_remains');
            usleep(20000);
        } while (true);
        $earlyChild = dev20_spawn_process(['/bin/sh', '-c',
            'sleep 30 >/dev/null 2>&1 & echo $! > "$1"; while [ ! -f "$2" ]; do sleep 0.02; done; exit 0',
            'dev20-early-exit', $earlyPidPath, $earlyReleasePath],
            null, ['PATH' => '/usr/bin:/bin']);
        dev20_require($earlyChild['groupVerified'] === true, 'process_group_self_test_early_leader_unverified');
        $earlyDeadline = microtime(true) + 5;
        do {
            $descendantBytes = @file_get_contents($earlyPidPath);
            $descendantPid = is_string($descendantBytes) && ctype_digit(trim($descendantBytes))
                ? (int)trim($descendantBytes) : 0;
            $descendantIdentity = $descendantPid > 0 ? dev20_process_identity($descendantPid) : null;
            if ($descendantIdentity !== null && $descendantIdentity['state'] !== 'Z'
                && $descendantIdentity['groupId'] === $earlyChild['pid']
                && $descendantIdentity['sessionId'] === $earlyChild['pid']) break;
            if (microtime(true) >= $earlyDeadline) throw new RuntimeException('process_group_self_test_early_exit_timeout');
            usleep(20000);
        } while (true);
        dev20_require(dev20_process_group_owned($earlyChild), 'process_group_self_test_early_descendant_unverified');
        if (file_put_contents($earlyReleasePath, 'release' . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('process_group_self_test_early_release_failed');
        }
        $leaderDeadline = microtime(true) + 5;
        do {
            $leaderIdentity = dev20_process_identity((int)$earlyChild['pid']);
            if ($leaderIdentity === null || $leaderIdentity['state'] === 'Z') break;
            if (microtime(true) >= $leaderDeadline) throw new RuntimeException('process_group_self_test_early_leader_did_not_exit');
            usleep(20000);
        } while (true);
        $descendantIdentity = dev20_process_identity($descendantPid);
        dev20_require($descendantIdentity !== null && $descendantIdentity['state'] !== 'Z'
            && $descendantIdentity['groupId'] === $earlyChild['pid']
            && $descendantIdentity['sessionId'] === $earlyChild['pid']
            && dev20_process_group_owned($earlyChild), 'process_group_self_test_early_descendant_not_surviving');
        $earlyResult = dev20_finish_process($earlyChild, 5);
        $earlyChild = null;
        dev20_require(($earlyResult['timedOut'] ?? true) === false
            && (int)($earlyResult['groupCleanupMembers'] ?? 0) > 0,
            'process_group_self_test_early_exit_not_cleaned');
        $earlyGoneDeadline = microtime(true) + 5;
        do {
            if (dev20_process_start_time($descendantPid) === null) break;
            if (microtime(true) >= $earlyGoneDeadline) throw new RuntimeException('process_group_self_test_early_orphan_remains');
            usleep(20000);
        } while (true);
        return ['passed' => true, 'childAndGrandchildStopped' => true, 'verifiedSessionLeader' => (int)$record['childPid'],
            'earlyExitLeader' => true, 'earlyExitDescendantStopped' => true,
            'stdoutArtifact' => 'api.stdout.jsonl', 'stderrArtifact' => 'api.stderr.log', 'timeoutOutputPreserved' => true];
    } finally {
        if (is_array($child)) dev20_reap_process($child, 3);
        if (is_array($earlyChild)) dev20_reap_process($earlyChild, 3);
        foreach ([$childScript, $pidPath, $earlyPidPath, $earlyReleasePath, $directory . DIRECTORY_SEPARATOR . 'api.stdout.jsonl', $directory . DIRECTORY_SEPARATOR . 'api.stderr.log'] as $path) {
            if (is_link($path) || (file_exists($path) && !@unlink($path))) throw new RuntimeException('process_group_self_test_cleanup_failed');
        }
        if (is_link($directory) || !@rmdir($directory)) throw new RuntimeException('process_group_self_test_cleanup_failed');
    }
}

function dev20_api_start(array $ctx, string $caseId, string $operation, array $payload, array $options = []): array
{
    $casesDirectory = $ctx['artifactDir'] . DIRECTORY_SEPARATOR . 'cases';
    if (!is_dir($casesDirectory) && !@mkdir($casesDirectory, 0770, true) && !is_dir($casesDirectory)) throw new RuntimeException('case_directory_create_failed');
    $caseDir = $casesDirectory . DIRECTORY_SEPARATOR . $caseId;
    if (!is_dir($caseDir) && !@mkdir($caseDir, 0770) && !is_dir($caseDir)) throw new RuntimeException('case_directory_create_failed');
    if (!@chmod($casesDirectory, 0770) || !@chmod($caseDir, 0770)) throw new RuntimeException('api_case_directory_mode_failed');
    $expectedFixtureParent = $ctx['scratchRoot'] . DIRECTORY_SEPARATOR . 'api-fixtures';
    if (($ctx['apiFixtureParent'] ?? null) !== $expectedFixtureParent || is_link($expectedFixtureParent)
        || !is_dir($expectedFixtureParent) || realpath($expectedFixtureParent) !== $expectedFixtureParent) {
        throw new RuntimeException('api_fixture_parent_identity_invalid');
    }
    $fixture = $expectedFixtureParent . DIRECTORY_SEPARATOR . $caseId;
    if (file_exists($fixture) || is_link($fixture)) throw new RuntimeException('fixture_path_not_fresh');
    $request = ['caseId' => $caseId, 'operation' => $operation, 'payload' => (object)$payload] + $options;
    $body = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $app = 'dev20-' . substr(hash('sha256', $ctx['runId'] . ':' . $caseId . ':' . bin2hex(random_bytes(5))), 0, 22);
    $env = [
        'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin',
        'HOME' => '/tmp',
        'LC_ALL' => 'C',
        'TZ' => 'UTC',
        'QD_CHIM_ROOT' => $ctx['coreRoot'],
        'QD_DEV20_RUN_ID' => $ctx['runId'],
        'QD_DEV20_SCRATCH_ROOT' => $ctx['scratchRoot'],
        'QD_DEV20_FIXTURE_MARKER' => $ctx['fixtureMarker'],
        'PGAPPNAME' => $app,
    ];
    $command = [$ctx['runuser'], '-u', 'www-data', '--', '/usr/bin/env', '-i'];
    foreach ($env as $name => $value) $command[] = $name . '=' . $value;
    array_push($command, $ctx['php'], $ctx['testRoot'] . DIRECTORY_SEPARATOR . 'api_driver.php', '--fixture-root', $fixture);
    $sqlOffset = dev20_case_log_start($ctx);
    $child = dev20_spawn_process($command, $body, []);
    return ['child' => $child, 'caseDir' => $caseDir, 'fixtureRoot' => $fixture, 'app' => $app,
        'ctx' => $ctx, 'sqlOffset' => $sqlOffset, 'operation' => $operation, 'payload' => $payload];
}

function dev20_preserve_journal_evidence(array $api): void
{
    $source = $api['fixtureRoot'] . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'state.json.journal';
    if (!is_dir($source)) return;
    if (is_link($source)) throw new RuntimeException('fixture_journal_redirected');
    $target = $api['caseDir'] . DIRECTORY_SEPARATOR . 'state.json.journal';
    if (is_link($target) || (file_exists($target) && !is_dir($target))) throw new RuntimeException('artifact_journal_path_invalid');
    if (!is_dir($target) && !@mkdir($target, 0770)) throw new RuntimeException('artifact_journal_create_failed');
    foreach (glob($source . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        if (is_link($path) || !is_file($path)) throw new RuntimeException('fixture_journal_file_invalid');
        $destination = $target . DIRECTORY_SEPARATOR . basename($path);
        if (file_exists($destination) || is_link($destination)) throw new RuntimeException('artifact_journal_file_not_fresh');
        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || file_put_contents($destination, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('artifact_journal_copy_failed');
        }
    }
}

function dev20_api_finish(array &$api, int $timeout = 120): array
{
    if (($api['finalized'] ?? false) === true) {
        if (isset($api['finishError'])) throw new Dev18ScenarioFailure($api['finishError']);
        return $api['finishedResult'];
    }
    $processFailure = null;
    try {
        $result = dev20_finish_process($api['child'], $timeout);
    } catch (Throwable $error) {
        $processFailure = $error;
        dev20_drain_output($api['child']);
        dev20_reap_process($api['child'], 3);
        dev20_drain_output($api['child']);
        $result = ['exitCode' => null, 'stdout' => $api['child']['stdout'] ?? '', 'stderr' => $api['child']['stderr'] ?? '', 'timedOut' => true];
    }
    $artifactFailure = null;
    try { dev20_preserve_journal_evidence($api); } catch (Throwable $error) { $artifactFailure = $error; }
    try { dev20_persist_process_output_files($api['caseDir'], $result); } catch (Throwable $error) { $artifactFailure ??= $error; }
    $events = [];
    foreach (preg_split('/\r?\n/', trim($result['stdout'])) ?: [] as $line) {
        if ($line === '') continue;
        $value = json_decode($line, true);
        if (is_array($value)) $events[] = $value;
    }
    $result['events'] = $events;
    $result['app'] = $api['app'];
    $result['fixtureRoot'] = $api['fixtureRoot'];
    $result['sqlStatements'] = dev20_log_statements_for_app($api['ctx'], $api['app'], $api['sqlOffset']);
    $api['finalized'] = true;
    $api['finishedResult'] = $result;
    if ($processFailure !== null || $artifactFailure !== null || ($result['timedOut'] ?? false) === true) {
        $api['finishError'] = ($result['timedOut'] ?? false) === true ? 'api_child_process_timeout' : 'api_child_process_finish_failed';
        throw new Dev18ScenarioFailure($api['finishError']);
    }
    return $result;
}

function dev20_api_call(array $ctx, string $caseId, string $operation, array $payload, array $options = [], int $timeout = 120): array
{
    $api = dev20_api_start($ctx, $caseId, $operation, $payload, $options);
    return dev20_api_finish($api, $timeout);
}

function dev20_api_responses(array $result): array
{
    return array_values(array_filter($result['events'] ?? [], static fn(array $event): bool => ($event['event'] ?? null) === 'api_response'));
}

function dev20_last_response(array $result, string $phase = 'operation'): array
{
    foreach (array_reverse(dev20_api_responses($result)) as $response) {
        if (($response['phase'] ?? null) === $phase) return $response;
    }
    return [];
}

function dev20_case_log_start(array $ctx): int
{
    clearstatcache(true, $ctx['pgLogPath']);
    $size = @filesize($ctx['pgLogPath']);
    return is_int($size) ? $size : 0;
}

function dev20_log_statements_for_app(array $ctx, string $app, int $offset): array
{
    $raw = @file_get_contents($ctx['pgLogPath']);
    if (!is_string($raw)) return [];
    $tail = substr($raw, min($offset, strlen($raw)));
    return dev20_log_statements_from_text($tail, $app);
}

function dev20_log_statements_from_text(string $raw, string $app): array
{
    $statements = [];
    $active = null;
    foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
        if (preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d+ /', $line) === 1) {
            if (is_array($active) && trim($active['sql']) !== '') $statements[] = trim($active['sql']);
            $active = null;
            if (preg_match('/(?:^|[ ,])app=' . preg_quote($app, '/') . '(?:[ ,]|$)/', $line) !== 1) continue;
            if (strpos($line, ' LOG: ') === false) continue;
            $marker = strpos($line, 'statement:');
            if ($marker !== false) {
                $sql = trim(substr($line, $marker + strlen('statement:')));
            } else {
                $execute = strpos($line, 'execute ');
                $colon = $execute === false ? false : strpos($line, ':', $execute);
                if ($colon === false) continue;
                $sql = trim(substr($line, $colon + 1));
            }
            $active = ['sql' => $sql];
        } elseif (is_array($active) && trim($line) !== '') {
            $active['sql'] .= "\n" . rtrim($line);
        }
    }
    if (is_array($active) && trim($active['sql']) !== '') $statements[] = trim($active['sql']);
    return $statements;
}

function dev20_owner_mutation_statements(array $statements, bool $includeRestoreState = true): array
{
    $mutations = [];
    foreach ($statements as $sql) {
        if (!is_string($sql) || preg_match('/\A\s*(?:INSERT|UPDATE|DELETE|TRUNCATE|CREATE|ALTER|DROP|GRANT|REVOKE)\b/i', $sql) !== 1) continue;
        if (preg_match('/\b(?:plugins\.|public\.)/i', $sql) !== 1
            && preg_match('/\ACREATE\s+SCHEMA\s+(?:IF\s+NOT\s+EXISTS\s+)?plugins\b/i', $sql) !== 1) continue;
        if (!$includeRestoreState && preg_match('/\bplugins\.correspondence_restore_state\b/i', $sql) === 1) continue;
        $mutations[] = $sql;
    }
    return $mutations;
}

function dev20_require_no_owner_mutation_sql(array $result, string $failure, bool $includeRestoreState = true): void
{
    $mutations = dev20_owner_mutation_statements($result['sqlStatements'] ?? [], $includeRestoreState);
    dev20_require($mutations === [], $failure);
}

function dev20_read_fence_evidence(array $ctx, string $app, int $offset): array
{
    $raw = @file_get_contents($ctx['pgLogPath']);
    if (!is_string($raw)) throw new RuntimeException('postgres_log_unreadable');
    $tail = substr($raw, min($offset, strlen($raw)));
    $lines = preg_split('/\r?\n/', $tail) ?: [];
    $statements = [];
    foreach ($lines as $lineNumber => $line) {
        if (preg_match('/(?:^|[ ,])app=' . preg_quote($app, '/') . '(?:[ ,]|$)/', $line) !== 1
            || preg_match('/\[(\d+)\]/', $line, $pidMatch) !== 1) continue;
        $marker = strpos($line, 'statement:');
        if ($marker !== false) {
            $sql = substr($line, $marker + strlen('statement:'));
        } else {
            $execute = strpos($line, 'execute ');
            $colon = $execute === false ? false : strpos($line, ':', $execute);
            if ($colon === false) continue;
            $sql = substr($line, $colon + 1);
        }
        $normalized = trim($sql);
        $kind = null;
        if (stripos($normalized, 'pg_advisory_unlock_shared') !== false) $kind = 'shared_unlock';
        elseif (stripos($normalized, 'pg_advisory_lock_shared') !== false) $kind = 'shared_lock';
        elseif (preg_match('/\bBEGIN\s+ISOLATION\s+LEVEL\s+REPEATABLE\s+READ\s+READ\s+ONLY\b/i', $normalized) === 1) $kind = 'rr_begin';
        elseif (preg_match('/\ACOMMIT(?:\s+AND\s+(?:NO\s+)?CHAIN)?\s*;?\z/i', $normalized) === 1) $kind = 'commit';
        elseif (preg_match('/\AROLLBACK(?:\s+AND\s+(?:NO\s+)?CHAIN)?\s*;?\z/i', $normalized) === 1) $kind = 'rollback';
        elseif (stripos($normalized, 'FROM plugins.correspondence_restore_state') !== false) $kind = 'restore_state';
        elseif (preg_match('/\ASELECT\b(?:(?!\bFROM\b).)*\brefid\b(?:(?!\bFROM\b).)*\bFROM\s+public\.core_npc_master\b/is', $normalized) === 1) $kind = 'core_actor_read';
        elseif (preg_match('/\ASELECT\s+plugin_name\s*,\s*formid_prefix\s+FROM\s+public\.game_plugins\b/is', $normalized) === 1) $kind = 'plugin_map_read';
        if ($kind !== null) $statements[] = ['line' => $lineNumber, 'pid' => (int)$pidMatch[1], 'kind' => $kind, 'sql' => $normalized];
    }
    foreach ($statements as $shared) {
        if ($shared['kind'] !== 'shared_lock') continue;
        $begin = null;
        $state = null;
        $terminal = null;
        $unlock = null;
        foreach ($statements as $candidate) {
            if ($candidate['pid'] !== $shared['pid'] || $candidate['line'] <= $shared['line']) continue;
            if ($begin === null && $candidate['kind'] === 'rr_begin') { $begin = $candidate; continue; }
            if ($begin !== null && $state === null && $candidate['kind'] === 'restore_state') { $state = $candidate; continue; }
            if ($state !== null && $terminal === null && in_array($candidate['kind'], ['commit', 'rollback'], true)) { $terminal = $candidate; continue; }
            if ($terminal !== null && $candidate['kind'] === 'shared_unlock') { $unlock = $candidate; break; }
        }
        if ($begin === null || $state === null || $terminal === null || $unlock === null
            || !($shared['line'] < $begin['line'] && $begin['line'] < $state['line']
                && $state['line'] < $terminal['line'] && $terminal['line'] < $unlock['line'])) continue;
        $backend = array_values(array_filter($statements, static fn(array $statement): bool => $statement['pid'] === $shared['pid']));
        $countKind = static function (array $items, string $kind): int {
            return count(array_filter($items, static fn(array $item): bool => $item['kind'] === $kind));
        };
        $transactionCounts = [
            'sharedLock' => $countKind($backend, 'shared_lock'),
            'rrBegin' => $countKind($backend, 'rr_begin'),
            'commit' => $countKind($backend, 'commit'),
            'sharedUnlock' => $countKind($backend, 'shared_unlock'),
            'rollback' => $countKind($backend, 'rollback'),
        ];
        $mappingCounts = ['coreActorRead' => 0, 'pluginRead' => 0];
        $mappingLines = [];
        foreach ($backend as $statement) {
            if ($statement['line'] <= $state['line'] || $statement['line'] >= $terminal['line']) continue;
            if ($statement['kind'] === 'core_actor_read') {
                $mappingCounts['coreActorRead']++;
                $mappingLines[] = $statement['line'];
            } elseif ($statement['kind'] === 'plugin_map_read') {
                $mappingCounts['pluginRead']++;
                $mappingLines[] = $statement['line'];
            }
        }
        return [
            'backendPid' => $shared['pid'], 'sharedLockLine' => $shared['line'], 'rrBeginLine' => $begin['line'],
            'restoreStateLine' => $state['line'], 'commitLine' => $terminal['kind'] === 'commit' ? $terminal['line'] : null,
            'terminalKind' => $terminal['kind'], 'terminalLine' => $terminal['line'], 'sharedUnlockLine' => $unlock['line'],
            'transactionCounts' => $transactionCounts, 'physicalMappingCounts' => $mappingCounts,
            'physicalMappingLinesWithinSnapshot' => $mappingLines,
            'statementWitnesses' => array_map(static fn(array $statement): array => [
                'line' => $statement['line'], 'kind' => $statement['kind'], 'sql' => $statement['sql'],
            ], $backend),
            'sql' => [$shared['sql'], $begin['sql'], $state['sql'], $terminal['sql'], $unlock['sql']],
        ];
    }
    throw new Dev18ScenarioFailure('read_fence_sql_order_not_observed_for_' . $app);
}

function dev20_assert_read_transaction_shape(array $fence): void
{
    $expectedCounts = ['sharedLock' => 1, 'rrBegin' => 1, 'commit' => 1, 'sharedUnlock' => 1, 'rollback' => 0];
    dev20_require(($fence['transactionCounts'] ?? null) === $expectedCounts, 'unknown_reconcile_read_fence_transaction_shape_invalid');
}

function dev20_assert_refused_read_transaction_shape(array $fence): void
{
    $expectedCounts = ['sharedLock' => 1, 'rrBegin' => 1, 'commit' => 0, 'sharedUnlock' => 1, 'rollback' => 1];
    dev20_require(($fence['transactionCounts'] ?? null) === $expectedCounts
        && ($fence['terminalKind'] ?? null) === 'rollback' && ($fence['commitLine'] ?? null) === null
        && isset($fence['sharedLockLine'], $fence['rrBeginLine'], $fence['restoreStateLine'], $fence['terminalLine'], $fence['sharedUnlockLine'])
        && $fence['sharedLockLine'] < $fence['rrBeginLine'] && $fence['rrBeginLine'] < $fence['restoreStateLine']
        && $fence['restoreStateLine'] < $fence['terminalLine'] && $fence['terminalLine'] < $fence['sharedUnlockLine'],
        'refused_read_rollback_fence_shape_invalid');
}

function dev20_assert_single_read_fence(array $fence): void
{
    dev20_assert_read_transaction_shape($fence);
    $mappings = $fence['physicalMappingCounts'] ?? [];
    dev20_require(($mappings['coreActorRead'] ?? 0) >= 1 && ($mappings['pluginRead'] ?? 0) >= 1,
        'unknown_reconcile_physical_mapping_selects_missing');
    dev20_require(count($fence['physicalMappingLinesWithinSnapshot'] ?? []) >= 2
        && (int)($fence['restoreStateLine'] ?? 0) < min($fence['physicalMappingLinesWithinSnapshot'])
        && max($fence['physicalMappingLinesWithinSnapshot']) < (int)($fence['commitLine'] ?? 0),
        'unknown_reconcile_physical_mapping_outside_snapshot');
}

function dev20_save_sql_delta(array $ctx, string $caseId, int $offset): array
{
    clearstatcache(true, $ctx['pgLogPath']);
    $raw = @file_get_contents($ctx['pgLogPath']);
    $delta = is_string($raw) ? substr($raw, min($offset, strlen($raw))) : '';
    $path = $ctx['artifactDir'] . DIRECTORY_SEPARATOR . 'cases' . DIRECTORY_SEPARATOR . $caseId . DIRECTORY_SEPARATOR . 'postgres-sql.log';
    file_put_contents($path, $delta, LOCK_EX);
    return ['bytes' => strlen($delta), 'sha256' => hash('sha256', $delta), 'path' => $path];
}

function dev20_case_record(array &$ctx, string $id, callable $work, mixed $admin): bool
{
    $caseDirectory = $ctx['artifactDir'] . DIRECTORY_SEPARATOR . 'cases' . DIRECTORY_SEPARATOR . $id;
    if (!is_dir($caseDirectory) && !@mkdir($caseDirectory, 0770, true) && !is_dir($caseDirectory)) {
        throw new RuntimeException('case_directory_create_failed');
    }
    $before = dev20_owner_snapshot($admin);
    $offset = dev20_case_log_start($ctx);
    $started = microtime(true);
    $passed = false;
    $facts = [];
    $failure = null;
    try {
        $facts = $work($before);
        if (!is_array($facts)) throw new RuntimeException('case_result_invalid');
        $passed = true;
    } catch (Throwable $error) {
        $failure = $error instanceof Dev18ScenarioFailure ? $error->getMessage() : 'exception_' . strtolower((new ReflectionClass($error))->getShortName());
    }
    $after = dev20_owner_snapshot($admin);
    $log = dev20_save_sql_delta($ctx, $id, $offset);
    $entry = [
        'schema' => 'department.dev20.pg-case-result.v1',
        'id' => $id,
        'status' => $passed ? 'passed' : 'failed',
        'durationMs' => (int)round((microtime(true) - $started) * 1000),
        'assertions' => $facts,
        'before' => $before,
        'after' => $after,
        'rowDelta' => dev20_row_delta($before, $after),
        'postgresSql' => ['bytes' => $log['bytes'], 'sha256' => $log['sha256'], 'relativePath' => 'cases/' . $id . '/postgres-sql.log'],
    ];
    if ($failure !== null) $entry['failure'] = $failure;
    dev20_json_line($ctx['jsonl'], $entry);
    $ctx['results'][] = ['id' => $id, 'status' => $entry['status']];
    return $passed;
}

function dev20_simple_assert_response(array $result, bool $ok, ?string $error = null): array
{
    dev20_require(($result['exitCode'] ?? null) === 0, 'api_process_exit');
    $response = dev20_last_response($result);
    dev20_require($response !== [] && ($response['ok'] ?? null) === $ok, 'api_response_expectation');
    if ($error !== null) dev20_require(($response['errorCode'] ?? null) === $error, 'api_error_code_expectation');
    dev20_require(($response['globalStateRestored'] ?? true) === true, 'request_globals_not_restored');
    if (!$ok) dev20_require_no_owner_mutation_sql($result, 'failed_request_emitted_owner_ddl_or_dml');
    return $response;
}

function dev20_run_owner_fixture(array $ctx, string $mode, array $extra = [], bool $async = false): array
{
    $command = [$ctx['php'], $ctx['testRoot'] . DIRECTORY_SEPARATOR . 'owner_fixture.php', '--mode', $mode,
        '--core-root', $ctx['coreRoot'], '--pg-socket', $ctx['pgSocket']];
    foreach ($extra as $name => $value) { $command[] = '--' . $name; $command[] = (string)$value; }
    $app = 'dev20-owner-' . substr(hash('sha256', $ctx['runId'] . ':' . $mode . ':' . bin2hex(random_bytes(5))), 0, 24);
    $env = [
        'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME' => '/tmp', 'LC_ALL' => 'C', 'TZ' => 'UTC',
        'QD_CHIM_ROOT' => $ctx['coreRoot'], 'QD_DEV20_RUN_ID' => $ctx['runId'],
        'QD_DEV20_SCRATCH_ROOT' => $ctx['scratchRoot'], 'QD_DEV20_FIXTURE_MARKER' => $ctx['fixtureMarker'], 'PGAPPNAME' => $app,
    ];
    if ($async) {
        $child = dev20_spawn_process($command, null, $env);
        $child['app'] = $app;
        return $child;
    }
    $child = dev20_spawn_process($command, null, $env);
    $processFailure = null;
    try {
        $result = dev20_finish_process($child, 90);
    } catch (Throwable $error) {
        $processFailure = $error;
        dev20_drain_output($child);
        dev20_reap_process($child, 3);
        dev20_drain_output($child);
        $result = ['exitCode' => null, 'stdout' => $child['stdout'] ?? '', 'stderr' => $child['stderr'] ?? '', 'timedOut' => true];
    }
    $decoded = json_decode(trim((string)$result['stdout']), true);
    if ($processFailure !== null || ($result['exitCode'] ?? 1) !== 0 || !is_array($decoded)
        || ($decoded['ready'] ?? null) !== true || ($decoded['effectiveOwnerRole'] ?? null) !== 'dwemer') {
        $evidenceDirectory = $ctx['artifactDir'] . DIRECTORY_SEPARATOR . 'fixture-processes' . DIRECTORY_SEPARATOR . 'owner-fixture-' . $mode;
        if (is_link(dirname($evidenceDirectory)) || (file_exists(dirname($evidenceDirectory)) && !is_dir(dirname($evidenceDirectory)))
            || (!is_dir(dirname($evidenceDirectory)) && !@mkdir(dirname($evidenceDirectory), 0770, true))) {
            throw new RuntimeException('owner_fixture_evidence_parent_failed');
        }
        if (is_link($evidenceDirectory) || (!is_dir($evidenceDirectory) && !@mkdir($evidenceDirectory, 0770))) {
            throw new RuntimeException('owner_fixture_evidence_directory_failed');
        }
        dev20_persist_process_output_files($evidenceDirectory, $result);
        throw new Dev18ScenarioFailure('owner_fixture_failed_' . $mode);
    }
    return $decoded;
}

function dev20_finish_owner_fixture(array $ctx, string $caseId, string $mode, array &$child, int $timeout = 90): array
{
    $failure = null;
    try {
        $result = dev20_finish_process($child, $timeout);
    } catch (Throwable $error) {
        $failure = $error;
        dev20_drain_process($child);
        dev20_reap_process($child, 5);
        dev20_drain_process($child);
        $result = ['exitCode' => null, 'stdout' => $child['stdout'] ?? '', 'stderr' => $child['stderr'] ?? '', 'timedOut' => true];
    }
    $directory = $ctx['artifactDir'] . DIRECTORY_SEPARATOR . 'cases' . DIRECTORY_SEPARATOR . $caseId
        . DIRECTORY_SEPARATOR . 'owner-fixtures' . DIRECTORY_SEPARATOR . $mode;
    if (is_link(dirname($directory)) || (!is_dir(dirname($directory)) && !@mkdir(dirname($directory), 0770, true))) {
        throw new RuntimeException('owner_fixture_evidence_parent_failed');
    }
    if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0770))) throw new RuntimeException('owner_fixture_evidence_directory_failed');
    dev20_persist_process_output_files($directory, $result);
    $rows = [];
    foreach (preg_split('/\r?\n/', trim((string)$result['stdout'])) ?: [] as $line) {
        if ($line === '') continue;
        $row = json_decode($line, true);
        if (is_array($row)) $rows[] = $row;
    }
    $receipt = $rows === [] ? [] : $rows[array_key_last($rows)];
    if ($failure !== null || ($result['exitCode'] ?? 1) !== 0 || ($receipt['ready'] ?? null) !== true
        || ($receipt['effectiveOwnerRole'] ?? null) !== 'dwemer') {
        throw new Dev18ScenarioFailure('owner_fixture_async_failed_' . $mode);
    }
    return ['receipt' => $receipt, 'stdout' => $result['stdout'] ?? '', 'stderr' => $result['stderr'] ?? '',
        'exitCode' => $result['exitCode'] ?? null, 'evidenceDirectory' => 'cases/' . $caseId . '/owner-fixtures/' . $mode];
}

function dev20_wait_for(array $ctx, callable $predicate, int $timeoutSeconds = 20): array
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        $value = $predicate();
        if (is_array($value) && $value !== []) return $value;
        if (microtime(true) >= $deadline) break;
        usleep(30000);
    } while (true);
    throw new RuntimeException('database_barrier_timeout');
}

function dev20_process_holds_file(int $pid, string $expectedPath): bool
{
    if ($pid < 1 || is_link($expectedPath)) return false;
    $expected = realpath($expectedPath);
    if (!is_string($expected)) return false;
    foreach (glob('/proc/' . $pid . '/fd/*') ?: [] as $fd) {
        $target = @readlink($fd);
        if (!is_string($target) || str_ends_with($target, ' (deleted)')) continue;
        if (realpath($target) === $expected) return true;
    }
    return false;
}

function dev20_app_sessions(mixed $admin, string $app): array
{
    return dev20_pg($admin,
        'SELECT pid::text AS pid,usename,application_name,state,wait_event_type,wait_event,query '
        . 'FROM pg_stat_activity WHERE application_name=$1 ORDER BY pid', [$app]);
}

function dev20_lock_rows(mixed $admin, string $app): array
{
    return dev20_pg($admin,
        "SELECT l.pid::text AS pid,l.locktype,l.mode,l.granted,l.classid::text AS classid,"
        . "l.objid::text AS objid,l.objsubid::text AS objsubid,a.query,a.wait_event_type,a.wait_event "
        . "FROM pg_locks l LEFT JOIN pg_stat_activity a ON a.pid=l.pid "
        . 'WHERE a.application_name=$1 ORDER BY l.pid,l.locktype,l.mode', [$app]);
}

function dev20_journal_records(string $fixtureRoot): array
{
    $directory = $fixtureRoot . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'state.json.journal';
    if (!is_dir($directory) || is_link($directory)) return [];
    $records = [];
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        if (is_link($path) || !is_file($path)) continue;
        $raw = @file_get_contents($path);
        $row = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($row) && array_key_exists('commitEntered', $row)) {
            $records[] = ['name' => basename($path), 'operation' => $row['operation'] ?? null,
                'operationId' => $row['operationId'] ?? null, 'state' => $row['state'] ?? null,
                'commitEntered' => $row['commitEntered']];
        }
    }
    return $records;
}

function dev20_no_commit_entered(string $fixtureRoot): bool
{
    foreach (dev20_journal_records($fixtureRoot) as $record) if ($record['commitEntered'] === true) return false;
    return true;
}

function dev20_has_commit_entered(string $fixtureRoot, string $operationId): bool
{
    foreach (dev20_journal_records($fixtureRoot) as $record) {
        if (($record['operationId'] ?? null) === $operationId && ($record['commitEntered'] ?? null) === true) return true;
    }
    return false;
}

function dev20_read_sentinel(string $path): array
{
    if (is_link($path)) throw new RuntimeException('sentinel_path_redirected');
    $bytes = @file_get_contents($path);
    if (!is_string($bytes)) throw new RuntimeException('sentinel_read_failed');
    $value = json_decode($bytes, true);
    if (!is_array($value) || ($value['schema'] ?? null) !== 'correspondence.restore.v1') throw new RuntimeException('sentinel_json_invalid');
    return ['bytes' => $bytes, 'value' => $value];
}

function dev20_write_sentinel(string $path, string $state, string $epoch): void
{
    if (!in_array($state, ['clear', 'pending'], true) || preg_match('/\A[0-9a-f-]{36}\z/D', $epoch) !== 1 || is_link($path)) {
        throw new RuntimeException('sentinel_test_input_invalid');
    }
    $bytes = json_encode(['schema' => 'correspondence.restore.v1', 'state' => $state, 'epoch' => $epoch], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $bytes, LOCK_EX) === false) throw new RuntimeException('sentinel_fixture_write_failed');
    dev20_set_www_data_file($path, 0660);
}

function dev20_write_generation(string $path, string $bytes, int $mode = 0660): void
{
    if (is_link($path) || file_put_contents($path, $bytes, LOCK_EX) === false) throw new RuntimeException('generation_fixture_write_failed');
    dev20_set_www_data_file($path, $mode);
}

function dev20_restore_state(mixed $admin): array
{
    return dev20_pg($admin, 'SELECT epoch::text AS epoch,pending,native_ts_floor::text AS native_ts_floor,updated_at::text AS updated_at FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
}

function dev20_api_wait_marker(array &$api, string $markerPath, int $timeoutSeconds = 25): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    do {
        dev20_drain_process($api['child']);
        if (is_file($markerPath)) return;
        if ($api['child']['exitCode'] !== null) throw new RuntimeException('api_exited_before_barrier');
        if (microtime(true) >= $deadline) throw new RuntimeException('api_marker_timeout');
        usleep(20000);
    } while (true);
}

function dev20_write_release(string $fixtureRoot): void
{
    $control = $fixtureRoot . DIRECTORY_SEPARATOR . 'control';
    if (!is_dir($control) || is_link($control)) throw new RuntimeException('fixture_control_missing');
    if (file_put_contents($control . DIRECTORY_SEPARATOR . 'continue-request', 'release' . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('fixture_release_write_failed');
    }
}

function dev20_snapshot_json(string $path, array $snapshot): void
{
    dev20_json_write($path, $snapshot);
}

$options = getopt('', [
    'process-group-self-test',
    'run-id:', 'artifact-dir:', 'core-root:', 'server-root:', 'owner-root:', 'test-root:', 'runtime-root:', 'php:',
    'scratch-root:', 'fixture-marker:', 'pg-socket:', 'pg-host:', 'pg-port:', 'pg-database:', 'pg-admin-role:', 'pg-app-role:', 'pg-log-path:',
]);
if (!is_array($options)) dev20_fail('arguments_invalid');
if (array_key_exists('process-group-self-test', $options)) {
    try {
        echo json_encode(dev20_process_group_self_test(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, 'dev20-process-group-self-test-failure:' . preg_replace('/[^a-z0-9_]+/i', '_', $error->getMessage()) . PHP_EOL);
        exit(3);
    }
}
foreach (['run-id', 'artifact-dir', 'core-root', 'server-root', 'owner-root', 'test-root', 'runtime-root', 'php', 'scratch-root', 'fixture-marker', 'pg-socket', 'pg-host', 'pg-port', 'pg-database', 'pg-admin-role', 'pg-app-role', 'pg-log-path'] as $required) {
    if (!is_string($options[$required] ?? null) || $options[$required] === '') dev20_fail('argument_' . str_replace('-', '_', $required) . '_required');
}
$runId = (string)$options['run-id'];
if (preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}\z/D', $runId) !== 1) dev20_fail('run_id_invalid');
if (($options['pg-host'] ?? null) !== '127.0.0.1' || (string)$options['pg-port'] !== '5432'
    || ($options['pg-database'] ?? null) !== 'dwemer' || ($options['pg-admin-role'] ?? null) !== 'postgres'
    || ($options['pg-app-role'] ?? null) !== 'dwemer') dev20_fail('database_identity_invalid');
if (!extension_loaded('pgsql') || PHP_VERSION_ID < 80100) dev20_fail('php_pgsql_required');

try {
    $ctx = [
        'runId' => $runId,
        'artifactDir' => dev20_real_dir($options['artifact-dir'], 'artifact_dir'),
        'coreRoot' => dev20_real_dir($options['core-root'], 'core_root'),
        'scratchRoot' => dev20_real_dir($options['scratch-root'], 'scratch_root'),
        'fixtureMarker' => (string)$options['fixture-marker'],
        'serverRoot' => dev20_real_dir($options['server-root'], 'server_root'),
        'ownerRoot' => dev20_real_dir($options['owner-root'], 'owner_root'),
        'testRoot' => dev20_real_dir($options['test-root'], 'test_root'),
        'runtimeRoot' => dev20_real_dir($options['runtime-root'], 'runtime_root'),
        'runuser' => dev20_resolve_runuser(),
        'php' => realpath((string)$options['php']) ?: (string)$options['php'],
        'pgSocket' => dev20_real_dir($options['pg-socket'], 'pg_socket'),
        'pgLogPath' => (string)$options['pg-log-path'],
        'database' => (string)$options['pg-database'],
        'adminRole' => (string)$options['pg-admin-role'],
        'appRole' => (string)$options['pg-app-role'],
        'results' => [],
    ];
    if (realpath($ctx['testRoot'] . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..') !== $ctx['serverRoot']) {
        throw new RuntimeException('test_server_layout_invalid');
    }
    if (!is_file($ctx['php']) || !is_file($ctx['pgLogPath'])) throw new RuntimeException('runner_input_missing');
    $expectedScratch = '/var/tmp/department-dev23-' . $runId;
    $expectedCore = $expectedScratch . '/stage/core';
    $expectedMarker = $expectedScratch . '/fixture-marker.json';
    if ($ctx['scratchRoot'] !== $expectedScratch || $ctx['coreRoot'] !== $expectedCore
        || $ctx['fixtureMarker'] !== $expectedMarker || is_link($ctx['fixtureMarker'])
        || !is_file($ctx['fixtureMarker'])) throw new RuntimeException('private_fixture_identity_invalid');
    putenv('QD_DEV20_RUN_ID=' . $runId);
    putenv('QD_DEV20_SCRATCH_ROOT=' . $ctx['scratchRoot']);
    putenv('QD_DEV20_FIXTURE_MARKER=' . $ctx['fixtureMarker']);
    putenv('QD_CHIM_ROOT=' . $ctx['coreRoot']);
    require_once $ctx['testRoot'] . DIRECTORY_SEPARATOR . 'fixture_guard.php';
    $fixtureIdentity = qd_dev20_assert_private_fixture();
    if (($fixtureIdentity['runId'] ?? null) !== $runId || ($fixtureIdentity['coreRoot'] ?? null) !== $expectedCore) {
        throw new RuntimeException('private_fixture_identity_mismatch');
    }
    $ctx['apiFixtureParent'] = $ctx['scratchRoot'] . DIRECTORY_SEPARATOR . 'api-fixtures';
    if (file_exists($ctx['apiFixtureParent']) || is_link($ctx['apiFixtureParent']) || !@mkdir($ctx['apiFixtureParent'], 0770)) {
        throw new RuntimeException('api_fixture_parent_not_fresh');
    }
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0 || !function_exists('posix_getpwnam')) {
        throw new RuntimeException('api_fixture_parent_owner_unavailable');
    }
    $wwwData = posix_getpwnam('www-data');
    if (!is_array($wwwData) || !isset($wwwData['uid'], $wwwData['gid'])
        || !@chown($ctx['apiFixtureParent'], 'www-data') || !@chgrp($ctx['apiFixtureParent'], 'www-data')
        || !@chmod($ctx['apiFixtureParent'], 0770)
        || fileowner($ctx['apiFixtureParent']) !== $wwwData['uid']
        || filegroup($ctx['apiFixtureParent']) !== $wwwData['gid']) {
        throw new RuntimeException('api_fixture_parent_owner_failed');
    }
    $expectedTestRoot = $expectedCore . '/ext/questionable_decisions/tests/dev23-pg';
    if ($ctx['testRoot'] !== $expectedTestRoot) throw new RuntimeException('staged_test_root_invalid');
    dev20_prepare_private_output_dir($ctx['coreRoot'], 'log/playthrough_runtime');
    dev20_prepare_private_output_dir($ctx['coreRoot'], 'data/books');
    $expectedRunParent = realpath(dirname($ctx['pgSocket']));
    if (!is_string($expectedRunParent) || basename($expectedRunParent) !== 'department-dev23-' . $runId) throw new RuntimeException('pg_socket_outside_run_root');
    if ($ctx['artifactDir'] !== realpath($ctx['runtimeRoot'] . DIRECTORY_SEPARATOR . 'runs' . DIRECTORY_SEPARATOR . $runId)) {
        throw new RuntimeException('artifact_directory_run_mismatch');
    }
    $pinHashes = dev20_owner_file_pins($ctx['coreRoot'], $ctx['ownerRoot'], $ctx['testRoot']);
    $fixtureFiles = ['api_driver.php', 'owner_fixture.php', 'seed_unknown_receipt.php', 'fixture_guard.php', 'cases.json', 'companion_cases.php'];
    foreach ($fixtureFiles as $file) if (!is_file($ctx['testRoot'] . DIRECTORY_SEPARATOR . $file) || is_link($ctx['testRoot'] . DIRECTORY_SEPARATOR . $file)) throw new RuntimeException('fixture_source_missing');
    $caseManifest = json_decode((string)file_get_contents($ctx['testRoot'] . DIRECTORY_SEPARATOR . 'cases.json'), true, 16);
    if (!is_array($caseManifest) || ($caseManifest['schema'] ?? null) !== 'department.dev20.public-facade-cases.v3'
        || !is_array($caseManifest['cases'] ?? null) || count($caseManifest['cases']) !== 33) throw new RuntimeException('case_manifest_invalid');
    if (!is_dir($ctx['artifactDir'] . DIRECTORY_SEPARATOR . 'cases')
        && !@mkdir($ctx['artifactDir'] . DIRECTORY_SEPARATOR . 'cases', 0770)) throw new RuntimeException('case_output_directory_create_failed');
    $ctx['jsonl'] = $ctx['artifactDir'] . DIRECTORY_SEPARATOR . 'linux.scenarios.jsonl';
    if (file_exists($ctx['jsonl']) || is_link($ctx['jsonl'])) throw new RuntimeException('result_path_not_fresh');
    $admin = dev20_admin_connect($ctx['pgSocket'], $ctx['database'], $ctx['adminRole']);
    $ctx['admin'] = $admin;
    $ctx['companionActorId'] = 900003;
    $ctx['pins'] = $pinHashes;
    $ctx['appPidEvidence'] = [];
} catch (Throwable $error) {
    $kind = $error instanceof InvalidArgumentException
        ? 'input'
        : 'preflight_' . strtolower((new ReflectionClass($error))->getShortName());
    dev20_fail($kind . '_' . dev20_safe_failure_detail($error));
}

$overallPassed = true;
$ownerInitialized = false;
$presenceSeeded = false;
$presenceExecutor = null;
$presenceSeedFailure = null;
$latestCompose = null;
$latestComposeResponse = null;
$readSeedOperationId = null;
$sentinelPath = $ctx['coreRoot'] . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'playthrough_runtime' . DIRECTORY_SEPARATOR . 'correspondence.restore.json';

// Case 1: authenticated public bootstrap recognizes the actual pinned source closure.
$overallPassed = dev20_case_record($ctx, 'bootstrap_exact_pins', static function () use ($ctx, $admin, $pinHashes): array {
    $before = dev20_owner_snapshot($admin);
    $result = dev20_api_call($ctx, 'bootstrap_exact_pins', 'bootstrap', []);
    dev20_require(($result['exitCode'] ?? null) === 0, 'bootstrap_api_process_exit');
    $boot = dev20_last_response($result, 'bootstrap');
    dev20_require($boot !== [] && ($boot['ok'] ?? null) === true, 'bootstrap_response_expectation');
    dev20_require(($boot['data']['authenticated'] ?? null) === true, 'bootstrap_not_authenticated');
    dev20_require(($boot['data']['capabilities']['correspondence'] ?? null) === true, 'pinned_correspondence_not_ready');
    dev20_require(count($pinHashes) === 19, 'owner_closure_pin_count');
    return ['publicFacade' => 'qd_api_handle', 'authenticated' => true, 'correspondenceReady' => true,
        'ownerClosureFiles' => count($pinHashes), 'operationResponse' => $boot];
}, $admin) && $overallPassed;

// Case 2: the fresh database refuses the read without initializing owner tables.
$overallPassed = dev20_case_record($ctx, 'read_does_not_initialize', static function () use ($ctx, $admin): array {
    $before = dev20_owner_snapshot($admin);
    dev20_require($before['pluginsSchemaPresent'] === false, 'fresh_db_already_has_owner_schema');
    $result = dev20_api_call($ctx, 'read_does_not_initialize', 'correspondence.mailbox.read', ['recipientActorKey' => 'npc:900003']);
    $response = dev20_simple_assert_response($result, false);
    $after = dev20_owner_snapshot($admin);
    dev20_require($after['pluginsSchemaPresent'] === false && $after['counts'] === $before['counts'], 'read_initialized_owner_schema');
    dev20_require(dev20_unchanged($before, $after), 'read_changed_fixture_rows');
    return ['refused' => true, 'errorCode' => $response['errorCode'] ?? null, 'ownerSchemaStillAbsent' => true,
        'ownerSql' => 'sql-trace-preserved-per-case'];
}, $admin) && $overallPassed;

// Case 3: a private staged source drift is refused by the real bootstrap and restored byte-for-byte.
$overallPassed = dev20_case_record($ctx, 'source_drift_refuses_facade', static function () use ($ctx, $admin): array {
    $before = dev20_owner_snapshot($admin);
    $target = $ctx['coreRoot'] . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'correspondence'
        . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime.php';
    $expectedDirectory = realpath($ctx['coreRoot'] . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'correspondence'
        . DIRECTORY_SEPARATOR . 'lib');
    if (is_link($target) || !is_file($target) || !is_string($expectedDirectory) || realpath(dirname($target)) !== $expectedDirectory) {
        throw new RuntimeException('source_drift_private_target_invalid');
    }
    $targetOwner = fileowner($target);
    $targetGroup = filegroup($target);
    $targetMode = fileperms($target) & 07777;
    $targetBytes = @file_get_contents($target);
    $targetHash = hash_file('sha256', $target);
    if (!is_string($targetBytes) || !is_string($targetHash)
        || !hash_equals(hash('sha256', $targetBytes), $targetHash)
        || !is_int($targetOwner) || !is_int($targetGroup) || !is_int($targetMode)
        || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        throw new RuntimeException('source_drift_private_target_metadata_unavailable');
    }
    $result = null;
    try {
        dev20_set_www_data_file($target, 0660);
        $result = dev20_api_call($ctx, 'source_drift_refuses_facade', 'bootstrap', [], ['sourceDrift' => true]);
    } finally {
        clearstatcache(true, $target);
        $bytesRestoreCount = @file_put_contents($target, $targetBytes, LOCK_EX);
        clearstatcache(true, $target);
        if ((fileowner($target) !== $targetOwner && !@chown($target, $targetOwner))
            || (filegroup($target) !== $targetGroup && !@chgrp($target, $targetGroup))
            || !@chmod($target, $targetMode)) {
            throw new RuntimeException('source_drift_private_target_metadata_restore_failed');
        }
        clearstatcache(true, $target);
        if ($bytesRestoreCount !== strlen($targetBytes)
            || fileowner($target) !== $targetOwner || filegroup($target) !== $targetGroup
            || (fileperms($target) & 07777) !== $targetMode || hash_file('sha256', $target) !== $targetHash) {
            throw new RuntimeException('source_drift_private_target_restore_not_exact');
        }
    }
    if (!is_array($result)) throw new RuntimeException('source_drift_facade_result_missing');
    dev20_require(($result['exitCode'] ?? null) === 0, 'source_drift_bootstrap_process_exit');
    $boot = dev20_last_response($result, 'bootstrap');
    $events = dev20_api_responses($result);
    $drift = $events[0]['sourceDrift'] ?? null;
    dev20_require(($boot['ok'] ?? null) === true && ($boot['data']['authenticated'] ?? null) === true,
        'source_drift_bootstrap_not_authenticated');
    dev20_require(($boot['data']['capabilities']['correspondence'] ?? null) === false,
        'source_drift_facade_capability_not_refused');
    dev20_require(is_array($drift) && ($drift['target'] ?? null) === 'ext/correspondence/lib/runtime.php'
        && is_string($drift['beforeSha256'] ?? null) && is_string($drift['driftSha256'] ?? null)
        && is_string($drift['restoredSha256'] ?? null) && $drift['beforeSha256'] !== $drift['driftSha256']
        && $drift['beforeSha256'] === $drift['restoredSha256'] && ($drift['restoredByteExact'] ?? false) === true,
        'source_drift_stage_restore_not_byte_exact');
    $expectedOperations = ['correspondence.catalog.read', 'correspondence.mailbox.read', 'correspondence.detail.read',
        'correspondence.compose', 'correspondence.reconcile'];
    $operationEvidence = $drift['operations'] ?? null;
    dev20_require(is_array($operationEvidence) && count($operationEvidence) === count($expectedOperations),
        'source_drift_operation_probe_count_invalid');
    foreach ($expectedOperations as $index => $operation) {
        $probe = $operationEvidence[$index] ?? null;
        dev20_require(is_array($probe) && ($probe['operation'] ?? null) === $operation
            && ($probe['phase'] ?? null) === 'source_drift_operation'
            && ($probe['payloadValidated'] ?? null) === true
            && ($probe['authenticatedContextProvided'] ?? null) === true
            && ($probe['ok'] ?? null) === false && ($probe['errorCode'] ?? null) === 'unavailable'
            && ($probe['httpStatus'] ?? null) === 503,
            'source_drift_operation_not_refused_' . str_replace('.', '_', $operation));
    }
    dev20_require_no_owner_mutation_sql($result, 'source_drift_emitted_owner_mutation_sql');
    $attemptedSqlStatements = $result['sqlStatements'] ?? [];
    dev20_require(is_array($attemptedSqlStatements), 'source_drift_sql_trace_invalid');
    dev20_require(dev20_unchanged($before, dev20_owner_snapshot($admin)), 'source_drift_changed_database_rows');
    return ['publicFacade' => 'qd_api_handle bootstrap plus all five correspondence operations', 'capabilityRefused' => true,
        'stagedInput' => $drift, 'attemptedSqlStatementCount' => count($attemptedSqlStatements),
        'ownerMutationStatements' => 0, 'databaseRowDelta' => []];
}, $admin) && $overallPassed;

try {
    dev20_run_owner_fixture($ctx, 'initialize');
    $ownerInitialized = true;
    $presenceReceipt = dev20_run_owner_fixture($ctx, 'seed-presence');
    $presenceExecutor = $presenceReceipt['presenceExecutor'] ?? null;
    if ($presenceExecutor !== 'Lydia') throw new Dev18ScenarioFailure('presence_seed_executor_not_verified');
    $presenceSeeded = true;
} catch (Throwable $error) {
    $presenceSeedFailure = $error instanceof Dev18ScenarioFailure ? $error->getMessage() : 'owner_fixture_setup_failed';
    $overallPassed = false;
}

// Case 4: marker failure quarantines authority; a newer init repairs it and supersedes a stale overlapping init.
$overallPassed = dev20_case_record($ctx, 'restore_init_marker_repair_and_overlap', static function () use ($ctx, $admin, $sentinelPath): array {
    $before = dev20_owner_snapshot($admin);
    $baselineState = dev20_restore_state($admin);
    $baselineSentinel = dev20_read_sentinel($sentinelPath);
    dev20_require(count($baselineState) === 1 && ($baselineState[0]['pending'] ?? null) === 'f'
        && ($baselineSentinel['value']['state'] ?? null) === 'clear'
        && ($baselineState[0]['epoch'] ?? null) === ($baselineSentinel['value']['epoch'] ?? null), 'init_baseline_not_clear');
    $lockPath = $sentinelPath . '.lock';
    if (is_link($lockPath)) throw new RuntimeException('init_marker_lock_redirected');
    $markerLock = @fopen($lockPath, 'c+b');
    if (!is_resource($markerLock) || !@flock($markerLock, LOCK_EX | LOCK_NB)) throw new RuntimeException('init_marker_lock_unavailable');
    try {
        $failedMarker = dev20_run_owner_fixture($ctx, 'prepare-init', ['native-ts' => '101', 'gamets' => '101']);
    } finally {
        @flock($markerLock, LOCK_UN);
        @fclose($markerLock);
    }
    $quarantined = dev20_restore_state($admin);
    $sentinelAfterFailure = dev20_read_sentinel($sentinelPath);
    dev20_require(($failedMarker['unsafe'] ?? null) === true && count($quarantined) === 1
        && ($quarantined[0]['epoch'] ?? null) === ($baselineState[0]['epoch'] ?? null)
        && ($quarantined[0]['pending'] ?? null) === 't'
        && $sentinelAfterFailure['bytes'] === $baselineSentinel['bytes'], 'failed_init_marker_did_not_quarantine_exact_prior_authority');
    $refusalBefore = dev20_owner_snapshot($admin);
    $refusal = dev20_api_call($ctx, 'init_marker_repair_pending_refusal', 'correspondence.catalog.read', []);
    $refusalResponse = dev20_simple_assert_response($refusal, false);
    dev20_require(dev20_unchanged($refusalBefore, dev20_owner_snapshot($admin)), 'pending_init_read_changed_rows');

    $repair = dev20_run_owner_fixture($ctx, 'complete-init', ['native-ts' => '102', 'gamets' => '102']);
    $repairEpoch = $repair['initEpoch'] ?? null;
    dev20_require(is_string($repairEpoch) && ($repair['restoreState']['epoch'] ?? null) === $repairEpoch
        && ($repair['restoreState']['pending'] ?? null) === 'f'
        && ($repair['sentinel']['state'] ?? null) === 'clear' && ($repair['sentinel']['epoch'] ?? null) === $repairEpoch,
        'owner_init_marker_repair_not_completed');
    $repairedRead = dev20_api_call($ctx, 'init_marker_repair_ready_read', 'correspondence.catalog.read', []);
    $repairedResponse = dev20_simple_assert_response($repairedRead, true);

    $control = $ctx['scratchRoot'] . DIRECTORY_SEPARATOR . 'control';
    if (is_link($control) || (!is_dir($control) && !@mkdir($control, 0700))) throw new RuntimeException('init_control_directory_invalid');
    $releaseA = $control . DIRECTORY_SEPARATOR . 'init-a-release';
    if (file_exists($releaseA) || is_link($releaseA)) throw new RuntimeException('init_release_path_not_fresh');
    $initA = dev20_run_owner_fixture($ctx, 'prepare-init-hold', ['release-file' => $releaseA, 'native-ts' => '103', 'gamets' => '103'], true);
    $initB = null;
    $initAResult = null;
    $initAFinishAttempted = false;
    $initBFinishAttempted = false;
    try {
        $preparedA = dev20_wait_for($ctx, static function () use (&$initA): array {
            dev20_drain_process($initA);
            foreach (preg_split('/\r?\n/', trim((string)($initA['stdout'] ?? ''))) ?: [] as $line) {
                $event = json_decode($line, true);
                if (is_array($event) && ($event['event'] ?? null) === 'init_prepared') return $event;
            }
            return [];
        }, 20);
        $epochA = $preparedA['epoch'] ?? null;
        dev20_require(is_string($epochA) && ($preparedA['unsafe'] ?? true) === false, 'first_overlapping_init_not_prepared');
        $lockA = dev20_wait_for($ctx, static function () use ($admin, $initA): array {
            foreach (dev20_lock_rows($admin, $initA['app']) as $row) {
                if (($row['locktype'] ?? null) === 'advisory' && ($row['classid'] ?? null) === '18459814'
                    && ($row['objid'] ?? null) === '1' && ($row['objsubid'] ?? null) === '2'
                    && ($row['mode'] ?? null) === 'ExclusiveLock' && ($row['granted'] ?? null) === 't') return [$row];
            }
            return [];
        }, 20);
        $initB = dev20_run_owner_fixture($ctx, 'complete-init', ['native-ts' => '104', 'gamets' => '104'], true);
        $sharedQueuedB = dev20_wait_for($ctx, static function () use ($admin, $initB): array {
            $sessions = dev20_app_sessions($admin, $initB['app']);
            $locks = dev20_lock_rows($admin, $initB['app']);
            foreach ($sessions as $session) {
                if (($session['wait_event_type'] ?? null) !== 'Lock'
                    || !str_contains((string)($session['query'] ?? ''), 'pg_advisory_lock_shared')) continue;
                foreach ($locks as $row) {
                    if (($row['pid'] ?? null) === ($session['pid'] ?? null)
                        && ($row['locktype'] ?? null) === 'advisory' && ($row['classid'] ?? null) === '18459814'
                        && ($row['objid'] ?? null) === '1' && ($row['objsubid'] ?? null) === '2'
                        && ($row['mode'] ?? null) === 'ShareLock' && ($row['granted'] ?? null) === 'f') {
                        return [['session' => $session, 'lock' => $row, 'observedAt' => microtime(true)]];
                    }
                }
            }
            return [];
        }, 2);
        $blockedB = $sharedQueuedB[0]['session'];
        $queueB = $sharedQueuedB[0]['lock'];
        $holderRowsA = dev20_lock_rows($admin, $initA['app']);
        $holderExclusiveA = array_values(array_filter($holderRowsA, static fn(array $row): bool =>
            ($row['locktype'] ?? null) === 'advisory' && ($row['classid'] ?? null) === '18459814'
            && ($row['objid'] ?? null) === '1' && ($row['objsubid'] ?? null) === '2'
            && ($row['mode'] ?? null) === 'ExclusiveLock' && ($row['granted'] ?? null) === 't'));
        dev20_require(count($holderExclusiveA) === 1 && ($holderExclusiveA[0]['pid'] ?? null) === ($lockA[0]['pid'] ?? null)
            && ($queueB['pid'] ?? null) === ($blockedB['pid'] ?? null),
            'overlap_lock_observation_backend_or_holder_invalid');
        $sentinelBQueued = dev20_read_sentinel($sentinelPath);
        dev20_require(($sentinelBQueued['value']['state'] ?? null) === 'pending'
            && ($sentinelBQueued['value']['epoch'] ?? null) !== $epochA, 'newer_init_marker_not_observed_while_older_fence_held');
        if (file_put_contents($releaseA, 'release' . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('init_a_release_write_failed');
        $sharedWaitReleaseMs = (int)round((microtime(true) - (float)$sharedQueuedB[0]['observedAt']) * 1000);
        dev20_require($sharedWaitReleaseMs < 2500, 'newer_init_shared_wait_not_released_promptly');
        $initAFinishAttempted = true;
        $finishedA = dev20_finish_owner_fixture($ctx, 'restore_init_marker_repair_and_overlap', 'prepare-init-hold', $initA, 30);
        $initAResult = $finishedA['receipt'];
        $initBFinishAttempted = true;
        $finishedB = dev20_finish_owner_fixture($ctx, 'restore_init_marker_repair_and_overlap', 'complete-init', $initB, 90);
        $initBReceipt = $finishedB['receipt'];
        $epochB = $initBReceipt['initEpoch'] ?? null;
        dev20_require(is_string($epochB) && $epochB !== $epochA
            && ($initBReceipt['restoreState']['epoch'] ?? null) === $epochB
            && ($initBReceipt['restoreState']['pending'] ?? null) === 'f'
            && ($initBReceipt['sentinel']['state'] ?? null) === 'clear'
            && ($initBReceipt['sentinel']['epoch'] ?? null) === $epochB,
            'newer_overlapping_init_did_not_win');
        $stale = dev20_run_owner_fixture($ctx, 'complete-stale-init', ['stale-epoch' => $epochA,
            'start-rowid' => (string)((int)$initBReceipt['markerRowId'] - 1), 'gamets' => '104']);
        dev20_require(($stale['staleCompletionAccepted'] ?? true) === false
            && ($stale['restoreState']['epoch'] ?? null) === $epochB && ($stale['restoreState']['pending'] ?? null) === 'f'
            && ($stale['sentinel']['epoch'] ?? null) === $epochB && ($stale['sentinel']['state'] ?? null) === 'clear',
            'stale_init_completion_replaced_newer_authority');
        $postRace = dev20_api_call($ctx, 'init_overlap_new_authority_read', 'correspondence.catalog.read', []);
        $postRaceResponse = dev20_simple_assert_response($postRace, true);
        $deleted = dev20_pg($admin, "DELETE FROM public.eventlog WHERE type='init' AND sess='dev20_init_test' AND data=$1 RETURNING rowid::text", ['dev20-init:' . $ctx['runId']]);
        dev20_require(count($deleted) === 2, 'init_fixture_marker_cleanup_count_invalid');
        dev20_pg($admin, 'UPDATE plugins.correspondence_restore_state SET epoch=$1::uuid,pending=$2::boolean,native_ts_floor=$3::bigint,updated_at=$4::timestamptz WHERE singleton IS TRUE',
            [$baselineState[0]['epoch'], $baselineState[0]['pending'], $baselineState[0]['native_ts_floor'], $baselineState[0]['updated_at']]);
        if (file_put_contents($sentinelPath, $baselineSentinel['bytes'], LOCK_EX) === false) throw new RuntimeException('init_sentinel_baseline_restore_failed');
        dev20_set_www_data_file($sentinelPath, 0660);
        dev20_require(dev20_unchanged($before, dev20_owner_snapshot($admin)), 'init_overlap_fixture_did_not_restore_exact_baseline');
        return ['markerWriteFailure' => ['unsafe' => true, 'databaseQuarantined' => true, 'sentinelBytesPreserved' => true],
            'pendingReadRefused' => true, 'pendingReadError' => $refusalResponse['errorCode'] ?? null,
            'markerRepair' => ['completionAccepted' => true, 'markerRowId' => $repair['markerRowId'], 'facadeReadAccepted' => true],
            'overlap' => ['olderEpoch' => $epochA, 'newerEpoch' => $epochB, 'olderFencePid' => $holderExclusiveA[0]['pid'],
                'newerIngressBlockedBackendPid' => $blockedB['pid'], 'newerSharedQueuedBackendPid' => $queueB['pid'],
                'newerSharedQueued' => true, 'olderExclusiveHeldWhenNewerQueued' => true,
                'newerSharedWaitReleaseMs' => $sharedWaitReleaseMs, 'newerMarkerObservedBeforeOldRelease' => true,
                'newerCompletionAccepted' => true,
                'staleCompletionAccepted' => false, 'facadeReadAfterNewAuthority' => true],
            'fixtureRowsRestored' => true, 'rowDelta' => []];
    } finally {
        if (is_array($initA)) {
            if (isset($releaseA)) @file_put_contents($releaseA, 'release' . PHP_EOL, LOCK_EX);
            if (!$initAFinishAttempted) {
                $initAFinishAttempted = true;
                try { dev20_finish_owner_fixture($ctx, 'restore_init_marker_repair_and_overlap', 'prepare-init-hold', $initA, 30); } catch (Throwable) {}
            }
        }
        if (is_array($initB) && !$initBFinishAttempted) {
            $initBFinishAttempted = true;
            try { dev20_finish_owner_fixture($ctx, 'restore_init_marker_repair_and_overlap', 'complete-init', $initB, 30); } catch (Throwable) {}
        }
        @pg_query_params($admin, "DELETE FROM public.eventlog WHERE type='init' AND sess='dev20_init_test' AND data=$1", ['dev20-init:' . $ctx['runId']]);
        @pg_query_params($admin, 'UPDATE plugins.correspondence_restore_state SET epoch=$1::uuid,pending=$2::boolean,native_ts_floor=$3::bigint,updated_at=$4::timestamptz WHERE singleton IS TRUE',
            [$baselineState[0]['epoch'], $baselineState[0]['pending'], $baselineState[0]['native_ts_floor'], $baselineState[0]['updated_at']]);
        if (is_file($sentinelPath) && !is_link($sentinelPath)) {
            @file_put_contents($sentinelPath, $baselineSentinel['bytes'], LOCK_EX);
            @chmod($sentinelPath, 0660);
        }
    }
}, $admin) && $overallPassed;

// Case 5: the read backend takes SH before RR and waits behind an owner X lock.
$overallPassed = dev20_case_record($ctx, 'read_waits_for_init_fence', static function () use ($ctx, $admin): array {
    dev20_pg($admin, 'SELECT pg_advisory_lock(18459814,1)');
    $api = dev20_api_start($ctx, 'read_waits_for_init_fence', 'correspondence.mailbox.read', ['recipientActorKey' => 'npc:900003']);
    try {
        $sessions = dev20_wait_for($ctx, static function () use ($admin, $api): array {
            return array_values(array_filter(dev20_app_sessions($admin, $api['app']), static fn(array $row): bool => ($row['wait_event_type'] ?? null) === 'Lock'
                && str_contains((string)($row['query'] ?? ''), 'pg_advisory_lock_shared')));
        });
        $lockRows = dev20_lock_rows($admin, $api['app']);
        $sharedGranted = false;
        $sharedWaiting = false;
        foreach ($lockRows as $row) {
            if (($row['locktype'] ?? null) === 'advisory' && ($row['classid'] ?? null) === '18459814' && ($row['objid'] ?? null) === '1' && ($row['objsubid'] ?? null) === '2') {
                if (($row['granted'] ?? null) === 't' && str_contains((string)($row['mode'] ?? ''), 'Share')) $sharedGranted = true;
                if (($row['granted'] ?? null) === 'f' && str_contains((string)($row['mode'] ?? ''), 'Share')) $sharedWaiting = true;
            }
        }
        dev20_require(!$sharedGranted && $sharedWaiting, 'read_shared_lock_not_queued_behind_x');
        dev20_require(!str_contains((string)$sessions[0]['query'], 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY'), 'rr_started_before_shared_lock');
        dev20_pg($admin, 'SELECT pg_advisory_unlock(18459814,1)');
        $result = dev20_api_finish($api, 60);
        $response = dev20_simple_assert_response($result, true);
        dev20_require_no_owner_mutation_sql($result, 'fenced_read_emitted_owner_ddl_or_dml');
        dev20_require(($response['data']['recipientActorKey'] ?? null) === 'npc:900003', 'mailbox_projection_not_returned');
        $fence = dev20_read_fence_evidence($ctx, (string)$result['app'], $api['sqlOffset']);
        dev20_require($fence['backendPid'] === (int)$sessions[0]['pid'], 'read_fence_backend_changed');
        return ['xHeld' => true, 'sharedLockQueued' => true, 'rrBeganAfterXRelease' => true, 'sameBackend' => true,
            'backendPid' => $fence['backendPid'], 'readFence' => $fence, 'result' => $response];
    } finally {
        @pg_query($admin, 'SELECT pg_advisory_unlock(18459814,1)');
    }
}, $admin) && $overallPassed;

// Case 6: each actual read facade enters and exits the fenced snapshot without row writes.
$overallPassed = dev20_case_record($ctx, 'read_all_facade_projections', static function () use ($ctx, $admin): array {
    $operations = [
        ['correspondence.catalog.read', []],
        ['correspondence.mailbox.read', ['recipientActorKey' => 'npc:900003']],
        ['correspondence.detail.read', ['recipientActorKey' => 'npc:900003', 'letterId' => dev20_random_uuid()]],
        ['correspondence.reconcile', ['operationId' => dev20_random_uuid()]],
    ];
    $results = [];
    foreach ($operations as $index => [$operation, $payload]) {
        $before = dev20_owner_snapshot($admin);
        $sqlOffset = dev20_case_log_start($ctx);
        $result = dev20_api_call($ctx, 'read_all_facade_projections_' . $index, $operation, $payload);
        dev20_require(($result['exitCode'] ?? null) === 0, 'projection_api_process_exit');
        $response = dev20_last_response($result);
        dev20_require($response !== [], 'projection_response_missing');
        dev20_require(($response['globalStateRestored'] ?? true) === true, 'projection_globals_not_restored');
        $fence = dev20_read_fence_evidence($ctx, (string)$result['app'], $sqlOffset);
        if (in_array($operation, ['correspondence.catalog.read', 'correspondence.mailbox.read'], true)) {
            dev20_require(($response['ok'] ?? null) === true, 'projection_success_missing');
        } else {
            dev20_require(($response['ok'] ?? null) === false && ($response['errorCode'] ?? null) === 'not_found', 'absent_projection_not_not_found');
        }
        $after = dev20_owner_snapshot($admin);
        dev20_require(dev20_unchanged($before, $after), 'read_projection_mutated_owner_or_public_rows');
        dev20_require_no_owner_mutation_sql($result, 'read_projection_emitted_owner_ddl_or_dml');
        $results[] = ['operation' => $operation, 'ok' => $response['ok'] ?? null, 'errorCode' => $response['errorCode'] ?? null,
            'readFence' => $fence, 'rowDelta' => []];
    }
    return ['projections' => $results, 'rowWrites' => 0, 'readLock' => '18459814/1 session SH'];
}, $admin) && $overallPassed;

// Case 7: revoke the exact integer-pair unlock overload and prove the owned reader closes.
$overallPassed = dev20_case_record($ctx, 'read_unlock_failure_closes_connection', static function () use ($ctx, $admin): array {
    $function = 'pg_catalog.pg_advisory_unlock_shared(integer,integer)';
    $observer = null;
    dev20_pg($admin, 'REVOKE EXECUTE ON FUNCTION ' . $function . ' FROM PUBLIC');
    $privilege = dev20_pg_scalar($admin, 'SELECT has_function_privilege($1,$2,$3)', ['dwemer', $function, 'EXECUTE']);
    dev20_require($privilege === 'f', 'app_role_still_has_unlock_privilege');
    $observer = dev20_admin_connect($ctx['pgSocket'], $ctx['database'], $ctx['adminRole']);
    dev20_require(dev20_pg_scalar($observer, 'SELECT 1') === '1', 'unrelated_admin_observer_not_usable');
    dev20_pg($admin, 'BEGIN');
    dev20_pg($admin, 'LOCK TABLE plugins.correspondence_restore_state IN ACCESS EXCLUSIVE MODE');
    $api = dev20_api_start($ctx, 'read_unlock_failure_closes_connection', 'correspondence.catalog.read', []);
    try {
        $session = dev20_wait_for($ctx, static function () use ($observer, $api): array {
            $rows = dev20_app_sessions($observer, $api['app']);
            foreach ($rows as $row) if (($row['wait_event_type'] ?? null) === 'Lock' && str_contains((string)$row['query'], 'correspondence_restore_state')) return [$row];
            return [];
        });
        $pid = (int)$session[0]['pid'];
        dev20_pg($admin, 'COMMIT');
        $result = dev20_api_finish($api, 60);
        dev20_require(($result['exitCode'] ?? null) === 0, 'unlock_failure_api_crashed');
        $response = dev20_last_response($result);
        dev20_require(($response['ok'] ?? null) === false, 'unlock_failure_was_not_refused');
        $gone = dev20_wait_for($ctx, static function () use ($observer, $pid): array {
            return dev20_pg($observer, 'SELECT pid::text FROM pg_stat_activity WHERE pid=$1', [$pid]) === [] ? ['gone' => true] : [];
        }, 15);
        dev20_require(dev20_pg_scalar($observer, 'SELECT 1') === '1', 'unrelated_admin_session_not_usable');
        dev20_pg($admin, 'SELECT pg_advisory_lock(18459814,1)');
        $xAcquired = true;
        dev20_pg($admin, 'SELECT pg_advisory_unlock(18459814,1)');
        return ['unlockOverload' => $function, 'appRoleExecute' => false, 'ownedBackendPid' => $pid,
            'backendGoneAfterApi' => ($gone['gone'] ?? false), 'subsequentXLockSucceeded' => $xAcquired,
            'unrelatedSessionUsable' => true, 'resultError' => $response['errorCode'] ?? null];
    } finally {
        if (is_object($observer)) @pg_close($observer);
        @pg_query($admin, 'ROLLBACK');
        @pg_query($admin, 'GRANT EXECUTE ON FUNCTION ' . $function . ' TO PUBLIC');
    }
}, $admin) && $overallPassed;

// Case 6: valid compose goes through API, journal, owner factory and private renderer.
$latestCompose = dev20_random_uuid();
$overallPassed = dev20_case_record($ctx, 'valid_compose', static function () use ($ctx, $admin, &$latestCompose, &$latestComposeResponse, $presenceSeeded, $presenceExecutor): array {
    dev20_require($presenceSeeded && $presenceExecutor === 'Lydia', 'presence_seed_not_verified');
    $payload = dev20_compose_payload($latestCompose);
    $result = dev20_api_call($ctx, 'valid_compose', 'correspondence.compose', $payload, ['operationRequestId' => $latestCompose]);
    $response = dev20_simple_assert_response($result, true);
    dev20_require(($response['data']['operationId'] ?? null) === $latestCompose, 'compose_operation_identity_mismatch');
    dev20_require(($response['data']['state'] ?? null) === 'dispatched', 'compose_operation_not_dispatched');
    $snapshot = dev20_owner_snapshot($admin);
    dev20_require((int)($snapshot['counts']['plugins.correspondence_documents'] ?? 0) >= 1, 'compose_document_not_persisted');
    dev20_require((int)($snapshot['counts']['plugins.correspondence_operations'] ?? 0) >= 1, 'compose_operation_not_persisted');
    dev20_require((int)($snapshot['counts']['public.books'] ?? 0) >= 1, 'private_renderer_book_not_written');
    dev20_require((int)($snapshot['counts']['public.responselog'] ?? 0) >= 1, 'compose_command_not_queued');
    dev20_require(dev20_has_commit_entered($result['fixtureRoot'], $latestCompose), 'commit_marker_missing_after_compose');
    $latestComposeResponse = $response;
    return ['request' => $payload, 'result' => $response['data'] ?? null, 'ownerDocumentRows' => $snapshot['counts']['plugins.correspondence_documents'] ?? '0',
        'ownerOperationRows' => $snapshot['counts']['plugins.correspondence_operations'] ?? '0', 'privateRenderer' => true,
        'providerCalls' => 0, 'commitEntered' => true, 'fixtureRoot' => basename($result['fixtureRoot'])];
}, $admin) && $overallPassed;

// Case 7: reconciliation is a read projection and never sends another command.
$overallPassed = dev20_case_record($ctx, 'valid_reconcile', static function () use ($ctx, $admin, &$latestCompose, $presenceSeeded, $presenceExecutor): array {
    dev20_require($presenceSeeded && $presenceExecutor === 'Lydia', 'presence_seed_not_verified');
    $before = dev20_owner_snapshot($admin);
    $result = dev20_api_call($ctx, 'valid_reconcile', 'correspondence.reconcile', ['operationId' => $latestCompose]);
    $response = dev20_simple_assert_response($result, true);
    $after = dev20_owner_snapshot($admin);
    dev20_require(($response['data']['operationId'] ?? null) === $latestCompose, 'reconcile_identity_mismatch');
    dev20_require(dev20_unchanged($before, $after), 'reconcile_wrote_owner_or_public_state');
    dev20_require_no_owner_mutation_sql($result, 'reconcile_emitted_owner_ddl_or_dml');
    return ['result' => $response['data'] ?? null, 'noOwnerWrites' => true, 'noRedispatch' => true];
}, $admin) && $overallPassed;

// Cases 10–12: signed UNKNOWN receipts are projected as partial and undisclosed.
foreach ([
    ['id' => 'signed_unknown_negative_delta', 'scenario' => 'negative', 'compose' => dev20_random_uuid()],
    ['id' => 'signed_unknown_overdelivery', 'scenario' => 'overdelivery', 'compose' => dev20_random_uuid()],
    ['id' => 'signed_unknown_partial_receipt', 'scenario' => 'partial', 'compose' => dev20_random_uuid()],
] as $unknownCase) {
    $overallPassed = dev20_case_record($ctx, $unknownCase['id'], static function () use ($ctx, $admin, $unknownCase, $presenceSeeded, $presenceExecutor): array {
        dev20_require($presenceSeeded && $presenceExecutor === 'Lydia', 'presence_seed_not_verified');
        $compose = dev20_compose_payload($unknownCase['compose']);
        $composeResult = dev20_api_call($ctx, $unknownCase['id'] . '_compose', 'correspondence.compose', $compose, ['operationRequestId' => $unknownCase['compose']]);
        $composeResponse = dev20_simple_assert_response($composeResult, true);
        dev20_require(($composeResponse['data']['state'] ?? null) === 'dispatched', 'unknown_compose_not_dispatched');
        dev20_require(($composeResponse['data']['operationId'] ?? null) === $unknownCase['compose'], 'unknown_compose_operation_identity_mismatch');
        $seed = dev20_run_seed_receipt($ctx, $unknownCase['id'], $unknownCase['scenario'], $unknownCase['compose']);
        $before = dev20_owner_snapshot($admin);
        $sqlOffset = dev20_case_log_start($ctx);
        $reconcile = dev20_api_call($ctx, $unknownCase['id'] . '_reconcile', 'correspondence.reconcile', ['operationId' => $unknownCase['compose']]);
        $response = dev20_simple_assert_response($reconcile, true);
        $fence = dev20_read_fence_evidence($ctx, (string)$reconcile['app'], $sqlOffset);
        dev20_assert_single_read_fence($fence);
        $data = $response['data'] ?? [];
        dev20_require(($data['state'] ?? null) === 'partial', 'unknown_receipt_not_partial');
        dev20_require(!array_key_exists('receipt', $data) && !array_key_exists('attachments', $data) && !array_key_exists('delivery', $data), 'unknown_receipt_disclosed_delivery');
        $after = dev20_owner_snapshot($admin);
        dev20_require(dev20_unchanged($before, $after), 'reconcile_redispatched_or_mutated');
        dev20_require_no_owner_mutation_sql($reconcile, 'unknown_reconcile_emitted_owner_ddl_or_dml');
        return ['fixture' => $seed, 'operationId' => $unknownCase['compose'], 'state' => 'partial', 'deliveryDetailsOmitted' => true,
            'noRetryOrRedispatch' => true, 'readFence' => $fence];
    }, $admin) && $overallPassed;
}

// Internal fixture runner for signed UNKNOWN receipts.
function dev20_run_seed_receipt(array $ctx, string $caseId, string $scenario, string $operationId): array
{
    $env = [
        'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME' => '/tmp', 'LC_ALL' => 'C', 'TZ' => 'UTC',
        'QD_CHIM_ROOT' => $ctx['coreRoot'], 'QD_DEV20_RUN_ID' => $ctx['runId'],
        'QD_DEV20_SCRATCH_ROOT' => $ctx['scratchRoot'], 'QD_DEV20_FIXTURE_MARKER' => $ctx['fixtureMarker'],
        'PGAPPNAME' => 'dev20-seed-' . substr(hash('sha256', $ctx['runId'] . ':' . $operationId), 0, 24),
    ];
    $command = [$ctx['runuser'], '-u', 'www-data', '--', '/usr/bin/env', '-i'];
    foreach ($env as $name => $value) $command[] = $name . '=' . $value;
    array_push($command, $ctx['php'], $ctx['testRoot'] . DIRECTORY_SEPARATOR . 'seed_unknown_receipt.php', '--scenario', $scenario, '--operation-id', $operationId);
    $child = dev20_spawn_process($command, null, []);
    $processFailure = null;
    try {
        $result = dev20_finish_process($child, 60);
    } catch (Throwable $error) {
        $processFailure = $error;
        dev20_drain_output($child);
        dev20_reap_process($child, 3);
        dev20_drain_output($child);
        $result = ['exitCode' => null, 'stdout' => $child['stdout'] ?? '', 'stderr' => $child['stderr'] ?? '', 'timedOut' => true];
    }
    $outputDirectory = $ctx['artifactDir'] . DIRECTORY_SEPARATOR . 'cases' . DIRECTORY_SEPARATOR . $caseId . DIRECTORY_SEPARATOR . 'receipt-seeder';
    if (is_link($outputDirectory) || (!is_dir($outputDirectory) && !@mkdir($outputDirectory, 0770, true))) {
        throw new RuntimeException('receipt_seed_evidence_directory_failed');
    }
    dev20_persist_process_output_files($outputDirectory, $result);
    $decoded = json_decode(trim($result['stdout']), true);
    if ($processFailure !== null || ($result['exitCode'] ?? 1) !== 0 || !is_array($decoded) || ($decoded['accepted'] ?? false) !== true) {
        throw new Dev18ScenarioFailure('receipt_seed_failed_' . $scenario);
    }
    return $decoded;
}

// Case 13: invalid negative gold and int32 overflow never reach owner effects.
$overallPassed = dev20_case_record($ctx, 'negative_and_overshoot', static function () use ($ctx, $admin): array {
    $before = dev20_owner_snapshot($admin);
    $negativeId = dev20_random_uuid();
    $negative = dev20_api_call($ctx, 'negative_gold', 'correspondence.compose', dev20_compose_payload($negativeId, ['goldAmount' => -1]), ['operationRequestId' => $negativeId]);
    dev20_simple_assert_response($negative, false, 'invalid_request');
    $overshootId = dev20_random_uuid();
    $overshoot = dev20_api_call($ctx, 'int32_overshoot', 'correspondence.compose', dev20_compose_payload($overshootId, ['attachments' => [['form_id' => 'Skyrim.esm|00000001', 'quantity' => 2147483648]]]), ['operationRequestId' => $overshootId]);
    dev20_simple_assert_response($overshoot, false, 'invalid_request');
    $after = dev20_owner_snapshot($admin);
    dev20_require(dev20_unchanged($before, $after), 'invalid_input_changed_owner_or_public_rows');
    return ['negativeGoldRefused' => true, 'int32OvershootRefused' => true, 'ownerRowDelta' => []];
}, $admin) && $overallPassed;

// Case 14: a targeted document INSERT failure is not retried under the same API request id.
$overallPassed = dev20_case_record($ctx, 'request_id_unknown_no_resend', static function () use ($ctx, $admin): array {
    $operationId = dev20_random_uuid();
    $function = 'public.dev20_fail_target_document_insert';
    $functionSql = "CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS \$dev20\$ "
        . "BEGIN IF NEW.request_id = '{$operationId}'::uuid THEN RAISE EXCEPTION 'dev20 injected document insert failure'; END IF; RETURN NEW; END \$dev20\$";
    dev20_pg($admin, $functionSql);
    dev20_pg($admin, 'CREATE TRIGGER dev20_fail_target_document_insert BEFORE INSERT ON plugins.correspondence_documents FOR EACH ROW EXECUTE FUNCTION ' . $function . '()');
    try {
        $before = dev20_owner_snapshot($admin);
        $result = dev20_api_call($ctx, 'request_id_unknown_no_resend', 'correspondence.compose', dev20_compose_payload($operationId),
            ['operationRequestId' => $operationId, 'repeatSameRequest' => true]);
        dev20_require(($result['exitCode'] ?? null) === 0, 'unknown_request_process_exit');
        $responses = dev20_api_responses($result);
        dev20_require(count($responses) === 3 && ($responses[1]['phase'] ?? null) === 'operation'
            && ($responses[2]['phase'] ?? null) === 'repeat', 'unknown_request_missing_repeat_responses');
        dev20_require(($responses[1]['ok'] ?? null) === false && ($responses[2]['ok'] ?? null) === false, 'unknown_request_not_refused');
        dev20_require(dev20_has_commit_entered($result['fixtureRoot'], $operationId), 'unknown_first_attempt_not_committed_to_journal');
        $ownerMutations = dev20_owner_mutation_statements($result['sqlStatements'] ?? []);
        $eventlogInserts = array_values(array_filter($ownerMutations, static fn(string $sql): bool =>
            preg_match('/\A\s*INSERT\b/i', $sql) === 1 && str_contains(strtolower($sql), 'public.eventlog')));
        $documentInserts = array_values(array_filter($ownerMutations, static fn(string $sql): bool =>
            preg_match('/\A\s*INSERT\b/i', $sql) === 1 && str_contains(strtolower($sql), 'plugins.correspondence_documents')));
        $rollbacks = array_values(array_filter($result['sqlStatements'] ?? [], static fn(string $sql): bool => preg_match('/\A\s*ROLLBACK\s*\z/i', $sql) === 1));
        dev20_require(count($eventlogInserts) === 1 && count($documentInserts) === 1 && count($ownerMutations) === 2
            && count($rollbacks) === 1, 'unknown_request_sql_trace_shows_retry_or_extra_owner_mutation');
        $after = dev20_owner_snapshot($admin);
        dev20_require(dev20_unchanged($before, $after), 'unknown_request_wrote_owner_or_public_rows');
        return ['firstError' => $responses[1]['errorCode'] ?? null, 'retryError' => $responses[2]['errorCode'] ?? null,
            'repeatCount' => 1, 'eventlogAnchorInsertAttempts' => count($eventlogInserts), 'targetedDocumentInsertAttempts' => count($documentInserts),
            'rollbackCount' => count($rollbacks), 'targetedInsertSql' => $documentInserts,
            'commitEntered' => true, 'ownerRowDelta' => []];
    } finally {
        @pg_query($admin, 'DROP TRIGGER IF EXISTS dev20_fail_target_document_insert ON plugins.correspondence_documents');
        @pg_query($admin, 'DROP FUNCTION IF EXISTS ' . $function . '()');
    }
}, $admin) && $overallPassed;

// Case 15: legacy 14–18 source pins remain listed; the separate owner test set stays dev18-only.
$overallPassed = dev20_case_record($ctx, 'preserve_dev14_to_dev18', static function () use ($ctx): array {
    $adapter = file_get_contents($ctx['serverRoot'] . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'correspondence.php');
    dev20_require(is_string($adapter), 'correspondence_adapter_unreadable');
    foreach (["'0.1.0-dev.14'", "'0.1.0-dev.15'", "'0.1.0-dev.16'", "'0.1.0-dev.17'", "'0.1.0-dev.18'"] as $version) {
        dev20_require(str_contains($adapter, $version), 'legacy_version_pin_missing');
    }
    return ['legacyVersionsPinned' => ['0.1.0-dev.14', '0.1.0-dev.15', '0.1.0-dev.16', '0.1.0-dev.17', '0.1.0-dev.18'],
        'compatibilityEvidence' => 'legacy source pins only; unchanged dev18 focused correspondence tests and the dev14 interface probe remain separate; no legacy PG semantics are claimed here'];
}, $admin) && $overallPassed;

// Case 16: six broken sentinel/DB state variants refuse before projection and restore exactly.
$overallPassed = dev20_case_record($ctx, 'sentinel_db_epoch_refusal', static function () use ($ctx, $admin, $sentinelPath): array {
    $state = dev20_restore_state($admin);
    dev20_require(count($state) === 1, 'restore_state_baseline_missing');
    $baseline = $state[0];
    $sentinel = dev20_read_sentinel($sentinelPath);
    $otherEpoch = dev20_random_uuid();
    $variants = ['sentinel_missing', 'sentinel_pending', 'sentinel_epoch_mismatch', 'db_epoch_missing', 'db_epoch_pending', 'db_epoch_mismatch'];
    $results = [];
    foreach ($variants as $variant) {
        try {
            switch ($variant) {
                case 'sentinel_missing': @unlink($sentinelPath); break;
                case 'sentinel_pending': dev20_write_sentinel($sentinelPath, 'pending', $baseline['epoch']); break;
                case 'sentinel_epoch_mismatch': dev20_write_sentinel($sentinelPath, 'clear', $otherEpoch); break;
                case 'db_epoch_missing': dev20_pg($admin, 'DELETE FROM plugins.correspondence_restore_state WHERE singleton IS TRUE'); break;
                case 'db_epoch_pending': dev20_pg($admin, 'UPDATE plugins.correspondence_restore_state SET pending=TRUE WHERE singleton IS TRUE'); break;
                case 'db_epoch_mismatch': dev20_pg($admin, 'UPDATE plugins.correspondence_restore_state SET epoch=$1::uuid WHERE singleton IS TRUE', [$otherEpoch]); break;
            }
            $before = dev20_owner_snapshot($admin);
            $result = dev20_api_call($ctx, 'sentinel_db_epoch_' . $variant, 'correspondence.catalog.read', []);
            $response = dev20_simple_assert_response($result, false);
            $after = dev20_owner_snapshot($admin);
            dev20_require(dev20_unchanged($before, $after), 'restore_state_refusal_mutated_rows');
            $results[$variant] = ['refused' => true, 'errorCode' => $response['errorCode'] ?? null, 'rowDelta' => []];
        } finally {
            $current = dev20_restore_state($admin);
            if ($current === []) {
                dev20_pg($admin, 'INSERT INTO plugins.correspondence_restore_state(singleton,epoch,pending,native_ts_floor) VALUES(TRUE,$1::uuid,$2::boolean,$3::bigint)', [$baseline['epoch'], $baseline['pending'], $baseline['native_ts_floor']]);
            } else {
                dev20_pg($admin, 'UPDATE plugins.correspondence_restore_state SET epoch=$1::uuid,pending=$2::boolean,native_ts_floor=$3::bigint,updated_at=$4::timestamptz WHERE singleton IS TRUE', [$baseline['epoch'], $baseline['pending'], $baseline['native_ts_floor'], $baseline['updated_at']]);
            }
            if (file_exists($sentinelPath) || is_link($sentinelPath)) @unlink($sentinelPath);
            if (file_put_contents($sentinelPath, $sentinel['bytes'], LOCK_EX) === false) throw new RuntimeException('sentinel_baseline_restore_failed');
            dev20_set_www_data_file($sentinelPath, 0660);
        }
    }
    return ['variants' => $results, 'allRefusedBeforeProjection' => true, 'baselineRestored' => true];
}, $admin) && $overallPassed;

// Case 17: null captured token is refused before the owner lifecycle/commit marker.
$overallPassed = dev20_case_record($ctx, 'null_request_token_refusal', static function () use ($ctx, $admin): array {
    $id = dev20_random_uuid();
    $before = dev20_owner_snapshot($admin);
    $result = dev20_api_call($ctx, 'null_request_token_refusal', 'correspondence.compose', dev20_compose_payload($id),
        ['operationRequestId' => $id, 'priorRequestGlobals' => 'captured-null-token']);
    $response = dev20_simple_assert_response($result, false);
    $after = dev20_owner_snapshot($admin);
    dev20_require(dev20_unchanged($before, $after), 'null_token_refusal_mutated_owner');
    dev20_require(dev20_no_commit_entered($result['fixtureRoot']), 'null_token_entered_commit_marker');
    return ['refused' => true, 'errorCode' => $response['errorCode'] ?? null, 'journalCommitEntered' => false,
        'globalStateRestored' => $response['globalStateRestored'] ?? false];
}, $admin) && $overallPassed;

// Case 18: all uncaptured/preprocessed request authority states fail closed and restore globals.
$overallPassed = dev20_case_record($ctx, 'preprocessed_request_globals_refusal', static function () use ($ctx, $admin): array {
    $variants = ['uncaptured-valid-ts', 'uncaptured-malformed-ts', 'uncaptured-token', 'captured-invalid', 'captured-null-token'];
    $results = [];
    foreach ($variants as $variant) {
        $id = dev20_random_uuid();
        $before = dev20_owner_snapshot($admin);
        $result = dev20_api_call($ctx, 'preprocessed_' . str_replace('-', '_', $variant), 'correspondence.compose', dev20_compose_payload($id),
            ['operationRequestId' => $id, 'priorRequestGlobals' => $variant]);
        $response = dev20_simple_assert_response($result, false);
        $after = dev20_owner_snapshot($admin);
        dev20_require(dev20_unchanged($before, $after), 'preprocessed_global_refusal_mutated_owner');
        dev20_require(dev20_no_commit_entered($result['fixtureRoot']), 'preprocessed_global_entered_commit');
        $results[$variant] = ['refused' => true, 'errorCode' => $response['errorCode'] ?? null,
            'globalsRestored' => $response['globalStateRestored'] ?? false];
    }
    return ['variants' => $results, 'allBeforeFreshIngressAndCommit' => true];
}, $admin) && $overallPassed;

// Case 19: a legitimate captured token can be carried through the full write path.
$capturedCompose = dev20_random_uuid();
$overallPassed = dev20_case_record($ctx, 'captured_current_token_success', static function () use ($ctx, $capturedCompose): array {
    $result = dev20_api_call($ctx, 'captured_current_token_success', 'correspondence.compose', dev20_compose_payload($capturedCompose),
        ['operationRequestId' => $capturedCompose, 'priorRequestGlobals' => 'captured-current']);
    $response = dev20_simple_assert_response($result, true);
    dev20_require(($response['globalStateRestored'] ?? null) === true, 'captured_globals_not_restored');
    dev20_require(dev20_has_commit_entered($result['fixtureRoot'], $capturedCompose), 'captured_current_commit_marker_missing');
    return ['accepted' => true, 'validCapturedToken' => true, 'allSixGlobalsRestored' => true, 'result' => $response['data'] ?? null];
}, $admin) && $overallPassed;

// Case 20: native timestamp above the restore floor succeeds; at-floor is stale.
$overallPassed = dev20_case_record($ctx, 'captured_current_native_floor_cases', static function () use ($ctx, $admin): array {
    $floor = dev20_pg_scalar($admin, 'SELECT native_ts_floor::text FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
    dev20_require(is_string($floor), 'restore_floor_missing');
    dev20_pg($admin, 'UPDATE plugins.correspondence_restore_state SET native_ts_floor=10 WHERE singleton IS TRUE');
    try {
        $aboveId = dev20_random_uuid();
        $above = dev20_api_call($ctx, 'captured_native_above_floor', 'correspondence.compose', dev20_compose_payload($aboveId),
            ['operationRequestId' => $aboveId, 'priorRequestGlobals' => 'captured-current-above-floor']);
        $aboveResponse = dev20_simple_assert_response($above, true);
        $atId = dev20_random_uuid();
        $before = dev20_owner_snapshot($admin);
        $at = dev20_api_call($ctx, 'captured_native_at_floor', 'correspondence.compose', dev20_compose_payload($atId),
            ['operationRequestId' => $atId, 'priorRequestGlobals' => 'captured-current-at-floor']);
        $atResponse = dev20_simple_assert_response($at, false);
        $after = dev20_owner_snapshot($admin);
        dev20_require(dev20_unchanged($before, $after), 'at_floor_refusal_mutated_owner');
        dev20_require(dev20_no_commit_entered($at['fixtureRoot']), 'at_floor_entered_commit');
        return ['baselineFloor' => $floor, 'testFloor' => '10', 'aboveFloorAccepted' => $aboveResponse['ok'] ?? false,
            'atFloorRefused' => $atResponse['ok'] === false, 'atFloorError' => $atResponse['errorCode'] ?? null,
            'allGlobalsRestored' => ($aboveResponse['globalStateRestored'] ?? false) && ($atResponse['globalStateRestored'] ?? false)];
    } finally {
        dev20_pg($admin, 'UPDATE plugins.correspondence_restore_state SET native_ts_floor=$1::bigint WHERE singleton IS TRUE', [$floor]);
    }
}, $admin) && $overallPassed;

// Case 21: genuinely absent core generation is the documented initial state.
$overallPassed = dev20_case_record($ctx, 'initial_pointer_generation_absence_allowed', static function () use ($ctx): array {
    $path = $ctx['coreRoot'] . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'playthrough_runtime' . DIRECTORY_SEPARATOR . 'generation';
    dev20_require(!file_exists($path) && !is_link($path), 'initial_generation_is_not_absent');
    $id = dev20_random_uuid();
    $result = dev20_api_call($ctx, 'initial_pointer_generation_absence_allowed', 'correspondence.compose', dev20_compose_payload($id), ['operationRequestId' => $id]);
    $response = dev20_simple_assert_response($result, true);
    return ['generationAbsent' => true, 'emptyInitialGenerationAllowed' => true, 'composeAccepted' => $response['ok'] === true];
}, $admin) && $overallPassed;

// Case 22: malformed, unreadable, stale-global and mismatched-global generations refuse.
$overallPassed = dev20_case_record($ctx, 'pointer_generation_refusal', static function () use ($ctx, $admin): array {
    $runtime = $ctx['coreRoot'] . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'playthrough_runtime';
    $path = $runtime . DIRECTORY_SEPARATOR . 'generation';
    if (!is_dir($runtime) && !@mkdir($runtime, 0770, true)) throw new RuntimeException('runtime_directory_missing');
    $baselineExists = is_file($path);
    $baselineBytes = $baselineExists ? file_get_contents($path) : null;
    $baselineMode = $baselineExists ? (fileperms($path) & 0777) : null;
    $baselineOwner = $baselineExists ? fileowner($path) : null;
    $baselineGroup = $baselineExists ? filegroup($path) : null;
    $results = [];
    try {
        foreach (['malformed', 'unreadable', 'stale_global', 'mismatched_global'] as $variant) {
            if ($variant === 'malformed') dev20_write_generation($path, "broken-generation\n");
            elseif ($variant === 'unreadable') dev20_write_generation($path, str_repeat('a', 32) . "\n", 0000);
            else dev20_write_generation($path, str_repeat('a', 32) . "\n");
            $id = dev20_random_uuid();
            $options = ['operationRequestId' => $id];
            if ($variant === 'stale_global') $options['priorRequestGlobals'] = 'captured-current-stale-generation';
            if ($variant === 'mismatched_global') { $options['leaseMode'] = 'main-owned'; $options['mainLeaseGeneration'] = 'mismatch'; }
            $before = dev20_owner_snapshot($admin);
            $result = dev20_api_call($ctx, 'generation_' . $variant, 'correspondence.compose', dev20_compose_payload($id), $options);
            $response = dev20_simple_assert_response($result, false);
            if ($variant === 'stale_global') {
                $evidence = $response['fixtureGenerationEvidence'] ?? [];
                dev20_require(($evidence['capturedTokenMatchedDiskGeneration'] ?? false) === true
                    && ($evidence['diskGenerationIsFixtureA'] ?? false) === true
                    && ($evidence['stalePointerGenerationDiffersFromDisk'] ?? false) === true
                    && ($evidence['preexistingLeaseAbsent'] ?? false) === true,
                    'stale_pointer_generation_fixture_invalid');
                dev20_require(($response['globalStateRestored'] ?? false) === true,
                    'stale_pointer_generation_globals_not_restored');
                dev20_require(dev20_no_commit_entered($result['fixtureRoot']),
                    'stale_pointer_generation_entered_commit');
            }
            $after = dev20_owner_snapshot($admin);
            dev20_require(dev20_unchanged($before, $after), 'bad_generation_mutated_owner');
            $results[$variant] = ['refused' => true, 'errorCode' => $response['errorCode'] ?? null, 'rowDelta' => []];
            if ($variant === 'stale_global') {
                $results[$variant]['fixtureGenerationEvidence'] = $response['fixtureGenerationEvidence'];
                $results[$variant]['allSixGlobalsRestored'] = true;
                $results[$variant]['commitNotEntered'] = true;
            }
        }
    } finally {
        if (file_exists($path) || is_link($path)) @unlink($path);
        if ($baselineExists && is_string($baselineBytes)) {
            if (file_put_contents($path, $baselineBytes, LOCK_EX) === false) throw new RuntimeException('generation_baseline_restore_failed');
            if (is_int($baselineOwner)) @chown($path, $baselineOwner);
            if (is_int($baselineGroup)) @chgrp($path, $baselineGroup);
            @chmod($path, (int)$baselineMode);
        }
    }
    return ['variants' => $results, 'initialAbsenceSemanticsPreserved' => true];
}, $admin) && $overallPassed;

// Case 23: pathname replacement cannot borrow a live lease through a different inode.
$overallPassed = dev20_case_record($ctx, 'borrowed_work_lock_path_replacement_refusal', static function () use ($ctx, $admin): array {
    $id = dev20_random_uuid();
    $before = dev20_owner_snapshot($admin);
    $result = dev20_api_call($ctx, 'borrowed_work_lock_path_replacement_refusal', 'correspondence.compose', dev20_compose_payload($id),
        ['operationRequestId' => $id, 'leaseMode' => 'main-owned-replaced']);
    $response = dev20_simple_assert_response($result, false);
    dev20_require(($response['borrowedLeaseSameOpen'] ?? false) === true, 'replacement_closed_borrowed_lease');
    dev20_require(($response['borrowedLeaseStillHeld'] ?? false) === true, 'replacement_released_borrowed_lock');
    $after = dev20_owner_snapshot($admin);
    dev20_require(dev20_unchanged($before, $after), 'replacement_refusal_mutated_owner');
    return ['refused' => true, 'descriptorSameOpen' => true, 'originalSharedLockHeld' => true,
        'globalsRestored' => $response['globalStateRestored'] ?? false, 'errorCode' => $response['errorCode'] ?? null];
}, $admin) && $overallPassed;

// Case 24: exact global and borrowed descriptor restoration across success, throw, and nesting.
$overallPassed = dev20_case_record($ctx, 'globals_and_borrowed_lease_restoration', static function () use ($ctx, $admin): array {
    $successId = dev20_random_uuid();
    $success = dev20_api_call($ctx, 'globals_borrowed_success', 'correspondence.compose', dev20_compose_payload($successId),
        ['operationRequestId' => $successId, 'priorRequestGlobals' => 'captured-current-above-floor', 'leaseMode' => 'main-owned', 'nestedProfileLease' => true]);
    $successResponse = dev20_simple_assert_response($success, true);
    dev20_require(($successResponse['borrowedLeaseSameOpen'] ?? false) === true && ($successResponse['borrowedLeaseStillHeld'] ?? false) === true,
        'borrowed_main_lease_not_restored');
    $refusalId = dev20_random_uuid();
    $failure = dev20_api_call($ctx, 'globals_borrowed_refusal', 'correspondence.compose', dev20_compose_payload($refusalId),
        ['operationRequestId' => $refusalId, 'priorRequestGlobals' => 'captured-invalid', 'leaseMode' => 'main-owned', 'nestedProfileLease' => true]);
    $failureResponse = dev20_simple_assert_response($failure, false);
    dev20_require(($failureResponse['borrowedLeaseSameOpen'] ?? false) === true && ($failureResponse['borrowedLeaseStillHeld'] ?? false) === true,
        'borrowed_main_lease_closed_on_refusal');
    dev20_require(($successResponse['globalStateRestored'] ?? false) && ($failureResponse['globalStateRestored'] ?? false), 'six_globals_not_restored');
    return ['successGlobalsRestored' => true, 'refusalGlobalsRestored' => true, 'borrowedDescriptorOpen' => true,
        'independentSharedLockProbe' => true, 'nestedLeaseDidNotCloseOuter' => true];
}, $admin) && $overallPassed;

// Case 25: controller pauses while the request owns SH, then obtains EX only after release.
$overallPassed = dev20_case_record($ctx, 'controller_pause_with_held_lease', static function () use ($ctx, $admin): array {
    $id = dev20_random_uuid();
    $api = null;
    $controller = null;
    $controllerRelease = null;
    try {
        $api = dev20_api_start($ctx, 'controller_pause_with_held_lease', 'correspondence.compose', dev20_compose_payload($id),
            ['operationRequestId' => $id, 'pauseAfterProfileLease' => true]);
        $marker = $api['fixtureRoot'] . DIRECTORY_SEPARATOR . 'control' . DIRECTORY_SEPARATOR . 'profile-lease-held';
        dev20_api_wait_marker($api, $marker);
        $controllerRelease = $api['caseDir'] . DIRECTORY_SEPARATOR . 'controller-release';
        $controller = dev20_spawn_process([$ctx['php'], $ctx['runtimeRoot'] . DIRECTORY_SEPARATOR . 'controller_switch.php',
            '--core-root', $ctx['coreRoot'], '--test-root', $ctx['testRoot'], '--artifact-dir', $ctx['artifactDir'],
            '--release-file', $controllerRelease, '--timeout', '120'], null,
            ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME' => '/tmp', 'LC_ALL' => 'C', 'TZ' => 'UTC',
                'QD_CHIM_ROOT' => $ctx['coreRoot'], 'QD_DEV20_RUN_ID' => $ctx['runId'],
                'QD_DEV20_SCRATCH_ROOT' => $ctx['scratchRoot'], 'QD_DEV20_FIXTURE_MARKER' => $ctx['fixtureMarker']]);
        $paused = $ctx['coreRoot'] . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'playthrough_runtime' . DIRECTORY_SEPARATOR . 'paused';
        dev20_wait_for($ctx, static fn(): array => is_file($paused) ? ['paused' => true] : [], 15);
        $probePath = $ctx['coreRoot'] . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'playthrough_runtime' . DIRECTORY_SEPARATOR . 'work.lock';
        $controllerStatus = proc_get_status($controller['proc']);
        $controllerPid = (int)($controllerStatus['pid'] ?? 0);
        dev20_wait_for($ctx, static function () use (&$controller, $controllerPid, $probePath): array {
            dev20_drain_process($controller);
            $status = @proc_get_status($controller['proc']);
            return ($status['running'] ?? false) && dev20_process_holds_file($controllerPid, $probePath)
                ? ['pid' => $controllerPid] : [];
        }, 10);
        dev20_require(strpos($controller['stdout'], '"event":"exclusive_acquired"') === false, 'controller_ex_acquired_before_request_release');
        $probe = @fopen($probePath, 'c+b');
        dev20_require(is_resource($probe), 'controller_probe_open_failed');
        $exclusivePassed = @flock($probe, LOCK_EX | LOCK_NB);
        if ($exclusivePassed) @flock($probe, LOCK_UN);
        fclose($probe);
        dev20_require(!$exclusivePassed, 'controller_ex_passed_live_shared_lease');
        dev20_require(dev20_owner_mutation_statements(dev20_log_statements_for_app($ctx, $api['app'], $api['sqlOffset'])) === [],
            'request_emitted_owner_ddl_or_dml_while_profile_lease_paused');
        dev20_write_release($api['fixtureRoot']);
        $apiResult = dev20_api_finish($api, 90);
        $api = null;
        dev20_require(($apiResult['exitCode'] ?? null) === 0, 'api_exited_during_controller_pause');
        $apiResponse = dev20_simple_assert_response($apiResult, true);
        $controllerEvent = dev20_wait_for($ctx, static function () use (&$controller): array {
            dev20_drain_process($controller);
            foreach (preg_split('/\r?\n/', $controller['stdout']) ?: [] as $line) {
                $row = json_decode($line, true);
                if (is_array($row) && ($row['event'] ?? null) === 'exclusive_acquired') return $row;
            }
            return [];
        }, 20);
        if (file_put_contents($controllerRelease, 'release' . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('controller_release_write_failed');
        $controllerResult = dev20_finish_process($controller, 30);
        $controller = null;
        dev20_require(($controllerResult['exitCode'] ?? null) === 0, 'controller_cleanup_failed');
        return ['pausedWhileSharedLeaseHeld' => true, 'exclusiveProbeBlocked' => true, 'requestExitCode' => $apiResult['exitCode'],
            'requestError' => $apiResponse['errorCode'] ?? null, 'controllerExclusiveAcquiredAfterRelease' => true,
            'controllerPid' => $controllerPid, 'controllerReceipt' => $controllerEvent];
    } finally {
        if (is_array($api)) {
            try { dev20_write_release($api['fixtureRoot']); } catch (Throwable) {}
            try { dev20_api_finish($api, 15); } catch (Throwable) { dev20_reap_process($api['child'], 5); }
        }
        if (is_array($controller)) {
            if (is_string($controllerRelease)) @file_put_contents($controllerRelease, 'release' . PHP_EOL, LOCK_EX);
            dev20_reap_process($controller, 15);
        }
    }
}, $admin) && $overallPassed;

// Case 26: observe the shared-read/table wait and queued X; require the concurrent rotation to leave compose safe.
$overallPassed = dev20_case_record($ctx, 'epoch_change_inflight_observed_queue', static function () use ($ctx, $admin): array {
    $before = dev20_owner_snapshot($admin);
    dev20_pg($admin, 'BEGIN');
    dev20_pg($admin, 'LOCK TABLE plugins.correspondence_restore_state IN ACCESS EXCLUSIVE MODE');
    $id = dev20_random_uuid();
    $api = dev20_api_start($ctx, 'epoch_change_inflight_observed_queue', 'correspondence.compose', dev20_compose_payload($id), ['operationRequestId' => $id]);
    $rotator = null;
    try {
        $apiRows = dev20_wait_for($ctx, static function () use ($admin, $api): array {
            foreach (dev20_app_sessions($admin, $api['app']) as $row) {
                if (($row['wait_event_type'] ?? null) === 'Lock' && str_contains((string)$row['query'], 'correspondence_restore_state')) return [$row];
            }
            return [];
        });
        $apiPid = (int)$apiRows[0]['pid'];
        $locks = dev20_lock_rows($admin, $api['app']);
        $apiSharedHeld = false;
        foreach ($locks as $lock) if (($lock['locktype'] ?? null) === 'advisory' && ($lock['mode'] ?? null) === 'ShareLock' && ($lock['granted'] ?? null) === 't'
            && ($lock['classid'] ?? null) === '18459814' && ($lock['objid'] ?? null) === '1') $apiSharedHeld = true;
        dev20_require($apiSharedHeld, 'api_did_not_hold_shared_lock_before_state_select');
        $controlDir = $ctx['scratchRoot'] . DIRECTORY_SEPARATOR . 'control';
        if (!is_dir($controlDir) && !@mkdir($controlDir, 0700)) throw new RuntimeException('fixture_control_directory_create_failed');
        $rotateRelease = $controlDir . DIRECTORY_SEPARATOR . 'epoch-rotate-release';
        if (file_exists($rotateRelease) || is_link($rotateRelease)) throw new RuntimeException('epoch_rotate_release_not_fresh');
        $rotator = dev20_run_owner_fixture($ctx, 'rotate-epoch', ['release-file' => $rotateRelease], true);
        $queued = dev20_wait_for($ctx, static function () use ($admin, $rotator): array {
            $rows = dev20_pg($admin,
                "SELECT l.pid::text AS pid,l.granted,l.mode FROM pg_locks l JOIN pg_stat_activity a ON a.pid=l.pid "
                . "WHERE l.locktype='advisory' AND l.classid='18459814'::oid AND l.objid='1'::oid AND l.objsubid=2 "
                . "AND l.mode='ExclusiveLock' AND l.granted=FALSE AND a.application_name=$1", [$rotator['app']]);
            return $rows;
        }, 20);
        dev20_require(count($queued) >= 1, 'exclusive_advisory_lock_not_observed_queued');
        dev20_pg($admin, 'COMMIT');
        $rotatorReady = dev20_wait_for($ctx, static function () use ($admin, &$rotator): array {
            dev20_drain_process($rotator);
            $rows = dev20_pg($admin,
                "SELECT l.pid::text AS pid,l.granted FROM pg_locks l JOIN pg_stat_activity a ON a.pid=l.pid "
                . "WHERE l.locktype='advisory' AND l.classid='18459814'::oid AND l.objid='1'::oid AND l.objsubid=2 "
                . "AND l.mode='ExclusiveLock' AND l.granted=TRUE AND a.application_name=$1", [$rotator['app']]);
            $acquiredEvent = false;
            foreach (preg_split('/\r?\n/', $rotator['stdout']) ?: [] as $line) {
                $event = json_decode($line, true);
                if (is_array($event) && ($event['event'] ?? null) === 'exclusive_acquired') $acquiredEvent = true;
            }
            return $rows !== [] && $acquiredEvent ? $rows : [];
        }, 20);
        dev20_require($rotatorReady !== [], 'queued_exclusive_not_granted_after_read_release');
        if (file_put_contents($rotateRelease, 'release' . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('epoch_rotate_release_write_failed');
        $rotateResult = dev20_finish_process($rotator, 30);
        $rotator = null;
        dev20_require(($rotateResult['exitCode'] ?? null) === 0, 'public_owner_epoch_rotation_failed');
        $result = dev20_api_finish($api, 90);
        $api = null;
        dev20_require(($result['exitCode'] ?? null) === 0, 'epoch_race_api_process_exit');
        $response = dev20_last_response($result);
        $safeUnknown = ($response['ok'] ?? null) === true
            && in_array(($response['data']['state'] ?? $response['data']['status'] ?? null), ['unknown', 'partial', 'unconfirmed', 'locked'], true);
        dev20_require(($response['ok'] ?? null) === false || $safeUnknown, 'epoch_rotation_race_not_safely_refused');
        $after = dev20_owner_snapshot($admin);
        dev20_require(dev20_unchanged($before, $after, ['plugins.correspondence_restore_state']), 'epoch_race_mutated_documents_events_or_commands');
        dev20_require_no_owner_mutation_sql($result, 'epoch_race_api_emitted_owner_ddl_or_dml', false);
        $beforeEpoch = $before['restoreState'][0]['epoch'] ?? null;
        $afterEpoch = $after['restoreState'][0]['epoch'] ?? null;
        dev20_require(is_string($beforeEpoch) && is_string($afterEpoch) && $beforeEpoch !== $afterEpoch, 'epoch_race_rotation_not_observed');
        return ['apiBackendPid' => $apiPid, 'apiHeldSharedWhileStateSelectBlocked' => true, 'competitorExclusiveObservedQueued' => true,
            'exclusiveGrantObservedAfterSharedFenceRelease' => true, 'restoreEpochChangedDuringRace' => true,
            'safeRefusalOrUnknown' => true, 'errorCode' => $response['errorCode'] ?? null, 'safeUnknown' => $safeUnknown,
            'requestState' => $response['data']['state'] ?? $response['data']['status'] ?? null,
            'ownerRowDelta' => [], 'ownerCounts' => $after['counts']];
    } finally {
        @pg_query($admin, 'ROLLBACK');
        if (is_array($rotator)) {
            if (isset($rotateRelease)) @file_put_contents($rotateRelease, 'release' . PHP_EOL, LOCK_EX);
            dev20_reap_process($rotator, 30);
        }
        if (is_array($api)) {
            try { dev20_api_finish($api, 30); } catch (Throwable) { dev20_reap_process($api['child'], 5); }
        }
    }
}, $admin) && $overallPassed;

// Case 27: unrelated telemetry advances during the same read barrier without invalidating the token.
$overallPassed = dev20_case_record($ctx, 'unrelated_event_inflight', static function () use ($ctx, $admin): array {
    dev20_pg($admin, 'BEGIN');
    dev20_pg($admin, 'LOCK TABLE plugins.correspondence_restore_state IN ACCESS EXCLUSIVE MODE');
    $id = dev20_random_uuid();
    $api = dev20_api_start($ctx, 'unrelated_event_inflight', 'correspondence.compose', dev20_compose_payload($id), ['operationRequestId' => $id]);
    try {
        $session = dev20_wait_for($ctx, static function () use ($admin, $api): array {
            foreach (dev20_app_sessions($admin, $api['app']) as $row) if (($row['wait_event_type'] ?? null) === 'Lock' && str_contains((string)$row['query'], 'correspondence_restore_state')) return [$row];
            return [];
        });
        $oldEpoch = dev20_pg_scalar($admin, 'SELECT epoch::text FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
        dev20_pg($admin, "INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES(101,101,'info','unrelated dev20 test telemetry','unrelated-event',101,'','','')");
        dev20_pg($admin, 'COMMIT');
        $result = dev20_api_finish($api, 90);
        $response = dev20_simple_assert_response($result, true);
        $newEpoch = dev20_pg_scalar($admin, 'SELECT epoch::text FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
        dev20_require($newEpoch === $oldEpoch, 'unrelated_event_changed_restore_epoch');
        return ['preflightBackendPid' => $session[0]['pid'], 'unrelatedEventInserted' => true, 'restoreEpochUnchanged' => true,
            'composeAccepted' => true, 'result' => $response['data'] ?? null];
    } finally { @pg_query($admin, 'ROLLBACK'); }
}, $admin) && $overallPassed;

// Case 28: no journal commit marker or owner writes exist while the real lease wrapper is paused.
$overallPassed = dev20_case_record($ctx, 'pause_between_lease_and_dispatch', static function () use ($ctx, $admin): array {
    $id = dev20_random_uuid();
    $before = dev20_owner_snapshot($admin);
    $api = dev20_api_start($ctx, 'pause_between_lease_and_dispatch', 'correspondence.compose', dev20_compose_payload($id),
        ['operationRequestId' => $id, 'pauseAfterProfileLease' => true]);
    $marker = $api['fixtureRoot'] . DIRECTORY_SEPARATOR . 'control' . DIRECTORY_SEPARATOR . 'profile-lease-held';
    dev20_api_wait_marker($api, $marker);
    $pausedSnapshot = dev20_owner_snapshot($admin);
    dev20_require($pausedSnapshot['counts'] === $before['counts'], 'owner_writes_before_dispatch_release');
    dev20_require(dev20_no_commit_entered($api['fixtureRoot']), 'journal_commit_entered_while_paused');
    dev20_write_release($api['fixtureRoot']);
    $result = dev20_api_finish($api, 90);
    $response = dev20_simple_assert_response($result, true);
    dev20_require(($result['exitCode'] ?? null) === 0, 'api_exited_after_pause_release');
    return ['realProfileLeaseHeld' => true, 'ownerRowsUnchangedWhilePaused' => true, 'journalCommitEnteredWhilePaused' => false,
        'requestCompletedAfterRelease' => true, 'errorCode' => $response['errorCode'] ?? null];
}, $admin) && $overallPassed;

// Case 29: block actual owner-factory DDL and read the durable Department marker while blocked.
$overallPassed = dev20_case_record($ctx, 'begin_commit_before_owner_factory', static function () use ($ctx, $admin): array {
    $presenceRowsBeforeDdl = dev20_pg($admin,
        'SELECT playthrough_id,payload::text AS payload FROM plugins.correspondence_presence ORDER BY playthrough_id');
    $api = null;
    try {
        $controlSchema = 'dev20_harness';
        dev20_pg($admin, 'CREATE SCHEMA IF NOT EXISTS ' . pg_escape_identifier($admin, $controlSchema));
        dev20_pg($admin, 'CREATE TABLE IF NOT EXISTS dev20_harness.ddl_gate(release BOOLEAN NOT NULL)');
        dev20_pg($admin, 'TRUNCATE dev20_harness.ddl_gate');
        dev20_pg($admin, 'INSERT INTO dev20_harness.ddl_gate(release) VALUES(FALSE)');
        dev20_pg($admin, "CREATE OR REPLACE FUNCTION public.dev20_gate_presence_ddl() RETURNS event_trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public,dev20_harness AS \$\$ BEGIN IF TG_TAG='CREATE TABLE' AND current_query() ILIKE '%correspondence_presence%' THEN LOOP EXIT WHEN (SELECT release FROM dev20_harness.ddl_gate LIMIT 1); PERFORM pg_sleep(0.05); END LOOP; END IF; END \$\$");
        dev20_pg($admin, 'DROP EVENT TRIGGER IF EXISTS dev20_gate_presence_ddl');
        dev20_pg($admin, 'CREATE EVENT TRIGGER dev20_gate_presence_ddl ON ddl_command_start WHEN TAG IN (\'CREATE TABLE\') EXECUTE FUNCTION public.dev20_gate_presence_ddl()');
        dev20_pg($admin, 'DROP TABLE plugins.correspondence_presence');
        $id = dev20_random_uuid();
        $api = dev20_api_start($ctx, 'begin_commit_before_owner_factory', 'correspondence.compose', dev20_compose_payload($id), ['operationRequestId' => $id]);
        $blocked = dev20_wait_for($ctx, static function () use ($admin, $api): array {
            foreach (dev20_app_sessions($admin, $api['app']) as $row) {
                if (str_contains((string)($row['query'] ?? ''), 'CREATE TABLE') && str_contains((string)($row['query'] ?? ''), 'correspondence_presence')) return [$row];
            }
            return [];
        }, 45);
        $pid = (int)$blocked[0]['pid'];
        dev20_require(($blocked[0]['wait_event_type'] ?? null) === 'Timeout' && ($blocked[0]['wait_event'] ?? null) === 'PgSleep', 'ddl_gate_not_blocking_owner_factory');
        $journal = dev20_journal_records($api['fixtureRoot']);
        dev20_require(dev20_has_commit_entered($api['fixtureRoot'], $id), 'commit_marker_not_durable_before_owner_ddl');
        dev20_pg($admin, 'UPDATE dev20_harness.ddl_gate SET release=TRUE');
        $result = dev20_api_finish($api, 120);
        $api = null;
        $response = dev20_simple_assert_response($result, true);
        return ['blockedOwnerBackendPid' => $pid, 'blockedQuery' => $blocked[0]['query'], 'journalRecordsWhileBlocked' => $journal,
            'commitEnteredObservedBeforeDdlRelease' => true, 'apiSucceededAfterGateRelease' => true,
            'sqlTraceRelativePath' => 'cases/begin_commit_before_owner_factory/postgres-sql.log', 'result' => $response['data'] ?? null];
    } finally {
        try {
            if (is_array($api)) {
                try {
                    dev20_pg($admin, 'UPDATE dev20_harness.ddl_gate SET release=TRUE');
                } finally {
                    try { dev20_api_finish($api, 30); } catch (Throwable) {}
                }
            }
        } finally {
            @pg_query($admin, 'DROP EVENT TRIGGER IF EXISTS dev20_gate_presence_ddl');
            @pg_query($admin, 'DROP FUNCTION IF EXISTS public.dev20_gate_presence_ddl()');
            $presenceRestoreFailure = null;
            try {
                dev20_pg($admin, 'DROP TABLE IF EXISTS plugins.correspondence_presence');
                dev20_pg($admin, 'CREATE TABLE plugins.correspondence_presence (playthrough_id text PRIMARY KEY,payload jsonb NOT NULL CHECK (jsonb_typeof(payload)=\'object\'))');
                dev20_pg($admin, 'GRANT SELECT,INSERT,UPDATE,DELETE ON plugins.correspondence_presence TO dwemer');
                foreach ($presenceRowsBeforeDdl as $presenceRow) {
                    dev20_require(is_string($presenceRow['playthrough_id'] ?? null) && is_string($presenceRow['payload'] ?? null),
                        'presence_fixture_snapshot_invalid');
                    dev20_pg($admin, 'INSERT INTO plugins.correspondence_presence(playthrough_id,payload) VALUES($1,$2::jsonb)',
                        [$presenceRow['playthrough_id'], $presenceRow['payload']]);
                }
                $restoredPresenceRows = dev20_pg($admin,
                    'SELECT playthrough_id,payload::text AS payload FROM plugins.correspondence_presence ORDER BY playthrough_id');
                dev20_require($restoredPresenceRows === $presenceRowsBeforeDdl, 'presence_fixture_restore_not_exact');
            } catch (Throwable $error) {
                $presenceRestoreFailure = $error;
            } finally {
                try {
                    dev20_pg($admin, 'DROP SCHEMA IF EXISTS dev20_harness CASCADE');
                } catch (Throwable $error) {
                    if ($presenceRestoreFailure === null) $presenceRestoreFailure = $error;
                }
            }
            if ($presenceRestoreFailure !== null) throw $presenceRestoreFailure;
        }
    }
}, $admin) && $overallPassed;

// Case 30: a pruned operator-close event remains attached to the closed document; a later positive receipt is read-only.
$overallPassed = dev20_case_record($ctx, 'operator_close_late_receipt', static function () use ($ctx, $admin): array {
    $requestId = dev20_random_uuid();
    $composePayload = dev20_compose_payload($requestId);
    $presenceReceipt = dev20_run_owner_fixture($ctx, 'seed-presence');
    dev20_require(($presenceReceipt['mode'] ?? null) === 'seed-presence'
        && ($presenceReceipt['ready'] ?? null) === true
        && ($presenceReceipt['effectiveOwnerRole'] ?? null) === 'dwemer'
        && ($presenceReceipt['presenceExecutor'] ?? null) === 'Lydia', 'operator_close_late_receipt_presence_not_verified');
    $compose = dev20_api_call($ctx, 'operator_close_late_receipt_compose', 'correspondence.compose',
        $composePayload, ['operationRequestId' => $requestId]);
    $composed = dev20_simple_assert_response($compose, true);
    dev20_require(($composed['data']['state'] ?? null) === 'dispatched'
        && ($composed['data']['operationId'] ?? null) === $requestId, 'operator_close_compose_not_dispatched');
    $documentRows = dev20_pg($admin, 'SELECT payload::text AS payload FROM plugins.correspondence_documents WHERE request_id=$1', [$requestId]);
    dev20_require(count($documentRows) === 1, 'operator_close_document_missing_after_compose');
    $document = json_decode((string)$documentRows[0]['payload'], true, 64);
    dev20_require(is_array($document) && is_string($document['document_id'] ?? null)
        && is_string($document['operation_id'] ?? null) && !array_key_exists('operator_close', $document),
        'operator_close_initial_document_invalid');

    $closed = dev20_run_owner_fixture($ctx, 'operator-close', ['operation-id' => $requestId]);
    $closeEvent = $closed['closeEvent'] ?? null;
    $expectedCloseData = json_encode(['document_id' => $document['document_id'], 'operation_id' => $document['operation_id'], 'reason' => 'operator_closed'],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    dev20_require(($closed['result']['operator_closed'] ?? null) === true
        && ($closed['documentId'] ?? null) === $document['document_id']
        && ($closed['operationId'] ?? null) === $requestId && is_array($closeEvent)
        && ($closeEvent['sess'] ?? null) === 'correspondence_v1'
        && ($closeEvent['data'] ?? null) === $expectedCloseData
        && ctype_digit((string)($closeEvent['rowid'] ?? '')) && (int)$closeEvent['rowid'] > 0,
        'owner_operator_close_receipt_invalid');
    $deleteClose = dev20_pg($admin,
        "DELETE FROM public.eventlog WHERE rowid=$1 AND type=$2 AND sess='correspondence_v1' AND data=$3 RETURNING rowid::text",
        [(string)$closeEvent['rowid'], (string)$closeEvent['type'], (string)$closeEvent['data']]);
    dev20_require(count($deleteClose) === 1
        && dev20_pg($admin, 'SELECT rowid::text FROM public.eventlog WHERE rowid=$1', [(string)$closeEvent['rowid']]) === [],
        'operator_close_event_prune_not_proven');
    $closedRows = dev20_pg($admin, 'SELECT payload::text AS payload FROM plugins.correspondence_documents WHERE request_id=$1', [$requestId]);
    $closedDocument = count($closedRows) === 1 ? json_decode((string)$closedRows[0]['payload'], true, 64) : null;
    dev20_require(is_array($closedDocument) && ($closedDocument['operator_close'] ?? null) === $closeEvent,
        'operator_close_payload_not_preserved_after_event_prune');
    $commandsAfterClose = (int)(dev20_owner_snapshot($admin)['counts']['public.responselog'] ?? 0);

    $beforeReconcile = dev20_owner_snapshot($admin);
    $sqlOffset = dev20_case_log_start($ctx);
    $reconcile = dev20_api_call($ctx, 'operator_close_late_receipt_reconcile', 'correspondence.reconcile', ['operationId' => $requestId]);
    $reconciled = dev20_simple_assert_response($reconcile, true);
    $fence = dev20_read_fence_evidence($ctx, (string)$reconcile['app'], $sqlOffset);
    dev20_assert_read_transaction_shape($fence);
    dev20_require(($reconciled['data']['state'] ?? null) === 'dispatched'
        && ($reconciled['data']['operationId'] ?? null) === $requestId
        && !array_key_exists('receipt', $reconciled['data'] ?? []), 'pruned_close_read_reconcile_projection_invalid');
    $afterReconcile = dev20_owner_snapshot($admin);
    dev20_require(dev20_unchanged($beforeReconcile, $afterReconcile), 'pruned_close_reconcile_mutated_rows');
    dev20_require_no_owner_mutation_sql($reconcile, 'pruned_close_reconcile_emitted_owner_mutation');
    dev20_require((int)($afterReconcile['counts']['public.responselog'] ?? 0) === $commandsAfterClose,
        'pruned_close_reconcile_queued_command');

    $counterfeitType = 'inputtext';
    $counterfeitData = 'dev20-counterfeit-close-event';
    $counterfeitInserted = false;
    $counterfeitRemoved = false;
    $counterfeitProjection = null;
    try {
        $counterfeitInsert = dev20_pg($admin,
            'INSERT INTO public.eventlog(rowid,ts,gamets,type,data,sess,localts,people,location,party) '
            . 'VALUES($1::bigint,$2::bigint,$3::numeric,$4,$5,$6,$7::bigint,$8,\'\',\'\') RETURNING rowid::text AS rowid',
            [(string)$closeEvent['rowid'], (string)$closeEvent['ts'], (string)$closeEvent['gamets'], $counterfeitType,
                $counterfeitData, (string)$closeEvent['sess'], (string)$closeEvent['localts'], (string)$closeEvent['people']]);
        $counterfeitInserted = count($counterfeitInsert) === 1;
        dev20_require($counterfeitInserted && ($counterfeitInsert[0]['rowid'] ?? null) === (string)$closeEvent['rowid'],
            'operator_close_counterfeit_row_insert_failed');
        $counterfeitRow = dev20_pg($admin,
            'SELECT rowid::text AS rowid,type,data,sess FROM public.eventlog WHERE rowid=$1', [(string)$closeEvent['rowid']]);
        dev20_require(count($counterfeitRow) === 1 && ($counterfeitRow[0]['type'] ?? null) === $counterfeitType
            && ($counterfeitRow[0]['data'] ?? null) === $counterfeitData
            && ($counterfeitRow[0]['sess'] ?? null) === $closeEvent['sess'], 'operator_close_counterfeit_row_not_proven');
        $carrierBeforeCounterfeitRead = dev20_pg($admin,
            'SELECT payload::text AS payload FROM plugins.correspondence_documents WHERE request_id=$1', [$requestId]);
        $carrierBeforeCounterfeit = count($carrierBeforeCounterfeitRead) === 1
            ? json_decode((string)$carrierBeforeCounterfeitRead[0]['payload'], true, 64) : null;
        dev20_require(is_array($carrierBeforeCounterfeit) && ($carrierBeforeCounterfeit['document_id'] ?? null) === $document['document_id']
            && ($carrierBeforeCounterfeit['operation_id'] ?? null) === $document['operation_id']
            && ($carrierBeforeCounterfeit['operator_close'] ?? null) === $closeEvent,
            'operator_close_counterfeit_changed_persisted_anchors');

        $beforeCounterfeitReconcile = dev20_owner_snapshot($admin);
        $counterfeitOffset = dev20_case_log_start($ctx);
        $counterfeitReconcile = dev20_api_call($ctx, 'operator_close_late_receipt_counterfeit_reconcile',
            'correspondence.reconcile', ['operationId' => $requestId]);
        $counterfeitProjection = dev20_simple_assert_response($counterfeitReconcile, true);
        $counterfeitFence = dev20_read_fence_evidence($ctx, (string)$counterfeitReconcile['app'], $counterfeitOffset);
        dev20_assert_read_transaction_shape($counterfeitFence);
        dev20_require(($counterfeitProjection['data']['state'] ?? null) === 'dispatched'
            && ($counterfeitProjection['data']['operationId'] ?? null) === $requestId
            && !array_key_exists('receipt', $counterfeitProjection['data'] ?? []),
            'operator_close_counterfeit_reconcile_projected_unconfirmed_receipt');
        $afterCounterfeitReconcile = dev20_owner_snapshot($admin);
        dev20_require(dev20_unchanged($beforeCounterfeitReconcile, $afterCounterfeitReconcile),
            'operator_close_counterfeit_reconcile_mutated_rows');
        dev20_require_no_owner_mutation_sql($counterfeitReconcile, 'operator_close_counterfeit_reconcile_emitted_owner_mutation');
        dev20_require((int)($afterCounterfeitReconcile['counts']['public.responselog'] ?? 0) === $commandsAfterClose,
            'operator_close_counterfeit_reconcile_queued_command');
        $carrierAfterCounterfeitRead = dev20_pg($admin,
            'SELECT payload::text AS payload FROM plugins.correspondence_documents WHERE request_id=$1', [$requestId]);
        $carrierAfterCounterfeit = count($carrierAfterCounterfeitRead) === 1
            ? json_decode((string)$carrierAfterCounterfeitRead[0]['payload'], true, 64) : null;
        dev20_require(is_array($carrierAfterCounterfeit) && ($carrierAfterCounterfeit['document_id'] ?? null) === $document['document_id']
            && ($carrierAfterCounterfeit['operation_id'] ?? null) === $document['operation_id']
            && ($carrierAfterCounterfeit['operator_close'] ?? null) === $closeEvent,
            'operator_close_counterfeit_dropped_persisted_anchors');
    } finally {
        if ($counterfeitInserted) {
            $counterfeitDelete = dev20_pg($admin,
                'DELETE FROM public.eventlog WHERE rowid=$1 AND type=$2 AND data=$3 AND sess=$4 RETURNING rowid::text AS rowid',
                [(string)$closeEvent['rowid'], $counterfeitType, $counterfeitData, (string)$closeEvent['sess']]);
            $counterfeitRemoved = count($counterfeitDelete) === 1
                && ($counterfeitDelete[0]['rowid'] ?? null) === (string)$closeEvent['rowid']
                && dev20_pg($admin, 'SELECT rowid::text FROM public.eventlog WHERE rowid=$1', [(string)$closeEvent['rowid']]) === [];
            if (!$counterfeitRemoved) throw new RuntimeException('operator_close_counterfeit_cleanup_failed');
        }
    }
    dev20_require($counterfeitRemoved, 'operator_close_counterfeit_row_not_removed');

    $receipt = dev20_run_seed_receipt($ctx, 'operator_close_late_receipt', 'delivered', $requestId);
    dev20_require(($receipt['status'] ?? null) === 'delivered' && ($receipt['accepted'] ?? null) === true,
        'late_positive_receipt_not_accepted_by_owner');
    $afterReceiptRows = dev20_pg($admin, 'SELECT payload::text AS payload FROM plugins.correspondence_documents WHERE request_id=$1', [$requestId]);
    $afterReceiptDocument = count($afterReceiptRows) === 1
        ? json_decode((string)$afterReceiptRows[0]['payload'], true, 64) : null;
    dev20_require(is_array($afterReceiptDocument) && ($afterReceiptDocument['document_id'] ?? null) === $document['document_id']
        && ($afterReceiptDocument['operation_id'] ?? null) === $document['operation_id']
        && ($afterReceiptDocument['operator_close'] ?? null) === $closeEvent,
        'late_receipt_dropped_or_rebound_persisted_close_identity');
    $beforeLateRead = dev20_owner_snapshot($admin);
    $lateOffset = dev20_case_log_start($ctx);
    $lateRead = dev20_api_call($ctx, 'operator_close_late_positive_reconcile', 'correspondence.reconcile', ['operationId' => $requestId]);
    $late = dev20_simple_assert_response($lateRead, true);
    $lateFence = dev20_read_fence_evidence($ctx, (string)$lateRead['app'], $lateOffset);
    dev20_assert_single_read_fence($lateFence);
    dev20_require(($late['data']['state'] ?? null) === 'delivered'
        && ($late['data']['receipt']['deliveryConfirmed'] ?? null) === true,
        'late_positive_receipt_not_projected_as_confirmed');
    $afterLateRead = dev20_owner_snapshot($admin);
    dev20_require(dev20_unchanged($beforeLateRead, $afterLateRead), 'late_positive_reconcile_mutated_rows');
    dev20_require_no_owner_mutation_sql($lateRead, 'late_positive_reconcile_emitted_owner_mutation');
    dev20_require((int)($afterLateRead['counts']['public.responselog'] ?? 0) === $commandsAfterClose,
        'late_positive_reconcile_queued_command');
    dev20_require(dev20_pg($admin, 'SELECT rowid::text FROM public.eventlog WHERE rowid=$1', [(string)$closeEvent['rowid']]) === [],
        'late_receipt_recreated_pruned_close_event');

    return ['operationId' => $requestId, 'ownerOperationId' => $document['operation_id'],
        'presenceRefresh' => $presenceReceipt, 'documentId' => $document['document_id'], 'ownerClose' => $closed['result'],
        'closeEventPruned' => true, 'documentClosePayloadPreserved' => true, 'reconcileBeforeReceipt' => $reconciled['data'],
        'counterfeitCloseRowid' => $closeEvent['rowid'], 'counterfeitEventRemovedBeforeReceipt' => $counterfeitRemoved,
        'counterfeitEventReconcile' => $counterfeitProjection['data'], 'latePositiveReceiptFixture' => $receipt,
        'persistedCloseAfterReceipt' => $afterReceiptDocument['operator_close'],
        'reconcileAfterReceipt' => $late['data'],
        'bothReconcilesUsedReadFence' => true, 'ownerWritesOrNewCommandsFromReconcile' => 0, 'closeEventStillAbsent' => true];
}, $admin) && $overallPassed;

// Case 31: a contradictory close identity anchor refuses through the actual facade without mutation.
$overallPassed = dev20_case_record($ctx, 'operator_close_identity_contradiction', static function () use ($ctx, $admin): array {
    $requestId = dev20_random_uuid();
    $composePayload = dev20_compose_payload($requestId);
    $presenceReceipt = dev20_run_owner_fixture($ctx, 'seed-presence');
    dev20_require(($presenceReceipt['mode'] ?? null) === 'seed-presence'
        && ($presenceReceipt['ready'] ?? null) === true
        && ($presenceReceipt['effectiveOwnerRole'] ?? null) === 'dwemer'
        && ($presenceReceipt['presenceExecutor'] ?? null) === 'Lydia', 'operator_close_identity_contradiction_presence_not_verified');
    $compose = dev20_api_call($ctx, 'operator_close_identity_contradiction_compose', 'correspondence.compose',
        $composePayload, ['operationRequestId' => $requestId]);
    $composed = dev20_simple_assert_response($compose, true);
    dev20_require(($composed['data']['state'] ?? null) === 'dispatched', 'contradictory_close_compose_not_dispatched');
    $closed = dev20_run_owner_fixture($ctx, 'operator-close', ['operation-id' => $requestId]);
    $rows = dev20_pg($admin, 'SELECT payload::text AS payload FROM plugins.correspondence_documents WHERE request_id=$1', [$requestId]);
    dev20_require(count($rows) === 1, 'contradictory_close_document_not_found');
    $originalPayload = (string)$rows[0]['payload'];
    $document = json_decode($originalPayload, true, 64);
    dev20_require(is_array($document) && is_array($document['operator_close'] ?? null)
        && ($document['document_id'] ?? null) === ($closed['documentId'] ?? null)
        && ($document['operator_close']['data'] ?? null) === ($closed['closeEvent']['data'] ?? null),
        'contradictory_close_original_anchor_invalid');
    $wrongDocumentId = dev20_random_uuid();
    $wrongOperationId = dev20_random_uuid();
    $contradictoryData = json_encode(['document_id' => $wrongDocumentId, 'operation_id' => $wrongOperationId, 'reason' => 'operator_closed'],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $document['operator_close']['data'] = $contradictoryData;
    $contradictoryPayload = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $changed = dev20_pg($admin, 'UPDATE plugins.correspondence_documents SET payload=$1::jsonb WHERE request_id=$2 RETURNING payload::text AS payload',
        [$contradictoryPayload, $requestId]);
    dev20_require(count($changed) === 1, 'contradictory_close_fixture_mutation_failed');
    $contradictoryHash = hash('sha256', (string)$changed[0]['payload']);
    $beforeRead = dev20_owner_snapshot($admin);
    try {
        $result = dev20_api_call($ctx, 'operator_close_identity_contradiction_reconcile', 'correspondence.reconcile', ['operationId' => $requestId]);
        $response = dev20_simple_assert_response($result, false, 'unavailable');
        $afterRead = dev20_owner_snapshot($admin);
        dev20_require(dev20_unchanged($beforeRead, $afterRead), 'contradictory_close_refusal_mutated_rows');
        dev20_require_no_owner_mutation_sql($result, 'contradictory_close_refusal_emitted_owner_mutation');
    } finally {
        $restored = dev20_pg($admin, 'UPDATE plugins.correspondence_documents SET payload=$1::jsonb WHERE request_id=$2 RETURNING payload::text AS payload',
            [$originalPayload, $requestId]);
        dev20_require(count($restored) === 1 && hash_equals(hash('sha256', $originalPayload), hash('sha256', (string)$restored[0]['payload'])),
            'contradictory_close_fixture_restore_failed');
    }
    return ['operationId' => $requestId, 'presenceRefresh' => $presenceReceipt, 'documentId' => $closed['documentId'], 'ownerCloseEventStillValid' => true,
        'contradictoryDocumentId' => $wrongDocumentId, 'contradictoryOperationId' => $wrongOperationId,
        'contradictoryPayloadSha256' => $contradictoryHash, 'facadeRefused' => true,
        'errorCode' => $response['errorCode'] ?? null, 'noFacadeMutation' => true, 'fixturePayloadRestoredByteEquivalent' => true];
}, $admin) && $overallPassed;

// Case 32: preserve the long forwarded body as queued, then prove a measured forwarded body through real delivery and detail.
$overallPassed = dev20_case_record($ctx, 'full_forwarded_letter_detail', static function () use ($ctx, $admin): array {
    $playerName = dev20_pg_scalar($admin, 'SELECT value FROM public.core_player WHERE id=$1', ['player_name']);
    $recipientName = dev20_pg_scalar($admin, 'SELECT npc_name FROM public.core_npc_master WHERE id=$1', ['900003']);
    dev20_require($playerName === 'Dragonborn' && $recipientName === 'Lydia', 'forwarded_letter_test_identities_missing');
    $forwardHeader = "---------- Forwarded message ----------\nFrom: " . $playerName . "\nTo: " . $recipientName
        . "\nSubject: Private correspondence proof\n\n";
    $renderHeader = 'From: ' . $playerName . "\nTo: " . $recipientName . "\n\n";
    $forwardLine = 'This forwarded paragraph preserves the original wording, delivery terms, and named correspondents in full. ';
    $footer = "\n[End of forwarded message]";
    $fontPath = $ctx['coreRoot'] . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'fonts' . DIRECTORY_SEPARATOR . 'GloriaHallelujah-Regular.ttf';
    $backgroundPath = $ctx['coreRoot'] . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'textures' . DIRECTORY_SEPARATOR . 'chim.png';
    $layoutPath = $ctx['ownerRoot'] . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'boundaries.php';
    $fontSha256 = @hash_file('sha256', $fontPath);
    $backgroundSha256 = @hash_file('sha256', $backgroundPath);
    $layoutSha256 = @hash_file('sha256', $layoutPath);
    $dimensions = @getimagesize($backgroundPath);
    dev20_require($fontSha256 === '4206cbda828ccde275f6d8b406ad80a29d2f5d9bc1a39a86e56a1c9c7691ed8c'
        && $backgroundSha256 === '820c235caa44a8f7a798e91d8091a633954963761de6e1377c70d8c8247d0342'
        && $layoutSha256 === '21034b79e36d208b30ed0ea5aa6892581ae6749c5a9190a3b4a422f4f74b7d0f'
        && is_array($dimensions) && ($dimensions[0] ?? null) === 371 && ($dimensions[1] ?? null) === 471,
        'forwarded_letter_renderer_inputs_drifted');

    $longBodyBudget = 1900 - strlen($renderHeader);
    dev20_require($longBodyBudget > 1800, 'forwarded_letter_identity_headers_leave_insufficient_body_budget');
    $longBody = $forwardHeader;
    while (strlen($longBody) + strlen($forwardLine) + strlen($footer) <= $longBodyBudget) $longBody .= $forwardLine;
    $longBody .= $footer;
    $longBodyCharacters = preg_match_all('/./us', $longBody);
    $renderHeaderCharacters = preg_match_all('/./us', $renderHeader);
    dev20_require(is_int($longBodyCharacters) && is_int($renderHeaderCharacters) && $longBodyCharacters > 1800
        && $longBodyCharacters <= 2000 && $longBodyCharacters + $renderHeaderCharacters <= 2000
        && hash_equals('a9f67903e6a0c3e827d17755fe01589e9c89e4467f8d41b159b25d2ba83569e8', hash('sha256', $longBody)),
        'forwarded_letter_long_fixture_changed_or_outside_owner_bound');

    $longRequestId = dev20_random_uuid();
    $longPayload = dev20_compose_payload($longRequestId, ['body' => $longBody, 'signatureActorKey' => 'player']);
    $longPresence = dev20_run_owner_fixture($ctx, 'seed-presence');
    dev20_require(($longPresence['mode'] ?? null) === 'seed-presence' && ($longPresence['ready'] ?? null) === true
        && ($longPresence['effectiveOwnerRole'] ?? null) === 'dwemer' && ($longPresence['presenceExecutor'] ?? null) === 'Lydia',
        'full_forwarded_letter_long_presence_not_verified');
    $beforeLongCompose = dev20_owner_snapshot($admin);
    $longCompose = dev20_api_call($ctx, 'full_forwarded_letter_long_compose', 'correspondence.compose', $longPayload,
        ['operationRequestId' => $longRequestId]);
    $longComposeResponse = dev20_simple_assert_response($longCompose, true);
    dev20_require(($longComposeResponse['data']['state'] ?? null) === 'queued'
        && ($longComposeResponse['data']['operationId'] ?? null) === $longRequestId,
        'forwarded_letter_long_not_queued');
    dev20_require(dev20_has_commit_entered($longCompose['fixtureRoot'], $longRequestId), 'forwarded_letter_long_commit_marker_missing');
    $longDocumentRows = dev20_pg($admin,
        "SELECT document_id::text AS document_id,payload->>'operation_id' AS operation_id,payload::text AS payload "
        . 'FROM plugins.correspondence_documents WHERE request_id=$1', [$longRequestId]);
    dev20_require(count($longDocumentRows) === 1, 'forwarded_letter_long_owner_document_missing');
    $longDocument = json_decode((string)$longDocumentRows[0]['payload'], true, 64);
    dev20_require(is_array($longDocument) && array_key_exists('operation_id', $longDocument) && $longDocument['operation_id'] === null
        && ($longDocumentRows[0]['operation_id'] ?? null) === null
        && ($longDocument['document_id'] ?? null) === ($longDocumentRows[0]['document_id'] ?? null)
        && ($longDocument['request_id'] ?? null) === $longRequestId
        && ($longDocument['body'] ?? null) === $longBody && ($longDocument['author']['name'] ?? null) === $playerName
        && ($longDocument['claimed_sender']['name'] ?? null) === $playerName
        && ($longDocument['recipient']['name'] ?? null) === $recipientName
        && str_starts_with($longBody, $forwardHeader) && str_ends_with($longBody, $footer),
        'forwarded_letter_long_owner_document_identity_or_body_mismatch');
    $afterLongCompose = dev20_owner_snapshot($admin);
    dev20_require(dev20_row_delta($beforeLongCompose, $afterLongCompose) === [
        'plugins.correspondence_documents' => 1, 'public.eventlog' => 1,
    ], 'forwarded_letter_long_unexpected_row_delta');
    foreach (['plugins.correspondence_operations', 'public.books', 'public.responselog'] as $table) {
        dev20_require(($beforeLongCompose['contentHashes'][$table] ?? null) === ($afterLongCompose['contentHashes'][$table] ?? null),
            'forwarded_letter_long_created_operation_book_or_command');
    }
    $unexpectedLongWrites = array_values(array_filter(dev20_owner_mutation_statements($longCompose['sqlStatements'] ?? []),
        static fn(string $sql): bool => preg_match('/\b(?:plugins\.correspondence_operations|public\.books|public\.responselog)\b/i', $sql) === 1));
    dev20_require($unexpectedLongWrites === [], 'forwarded_letter_long_attempted_operation_book_or_command_write');

    $longReconcileBefore = dev20_owner_snapshot($admin);
    $longReconcileOffset = dev20_case_log_start($ctx);
    $longReconcile = dev20_api_call($ctx, 'full_forwarded_letter_long_reconcile', 'correspondence.reconcile', ['operationId' => $longRequestId]);
    $longReconcileResponse = dev20_simple_assert_response($longReconcile, true);
    $longReconcileFence = dev20_read_fence_evidence($ctx, (string)$longReconcile['app'], $longReconcileOffset);
    dev20_assert_read_transaction_shape($longReconcileFence);
    dev20_require(($longReconcileResponse['data']['operationId'] ?? null) === $longRequestId
        && ($longReconcileResponse['data']['state'] ?? null) === 'queued'
        && !array_key_exists('receipt', $longReconcileResponse['data'] ?? []), 'forwarded_letter_long_reconcile_not_queued');
    dev20_require(dev20_unchanged($longReconcileBefore, dev20_owner_snapshot($admin)), 'forwarded_letter_long_reconcile_mutated_rows');
    dev20_require_no_owner_mutation_sql($longReconcile, 'forwarded_letter_long_reconcile_emitted_owner_mutation');

    $longDetailBefore = dev20_owner_snapshot($admin);
    $longDetailOffset = dev20_case_log_start($ctx);
    $longDetail = dev20_api_call($ctx, 'full_forwarded_letter_long_detail_refusal', 'correspondence.detail.read',
        ['recipientActorKey' => 'npc:900003', 'letterId' => $longDocument['document_id']],
        ['authScopes' => ['inspector', 'author_detail', 'compose']]);
    $longDetailResponse = dev20_simple_assert_response($longDetail, false, 'not_found');
    $longDetailFence = dev20_read_fence_evidence($ctx, (string)$longDetail['app'], $longDetailOffset);
    dev20_assert_refused_read_transaction_shape($longDetailFence);
    dev20_require(dev20_unchanged($longDetailBefore, dev20_owner_snapshot($admin)), 'forwarded_letter_long_detail_refusal_mutated_rows');
    dev20_require_no_owner_mutation_sql($longDetail, 'forwarded_letter_long_detail_refusal_emitted_owner_mutation');
    $longQueued = [
        'operationId' => $longRequestId, 'letterId' => $longDocument['document_id'],
        'bodyCharacters' => $longBodyCharacters, 'sourceBodySha256' => hash('sha256', $longBody),
        'persistedBodySha256' => hash('sha256', (string)$longDocument['body']), 'state' => $longReconcileResponse['data']['state'],
        'publicDetailRefused' => true, 'detailError' => $longDetailResponse['errorCode'] ?? null,
        'noOperationOrBookOrCommand' => true, 'noResend' => true,
        'reconcileReadFence' => $longReconcileFence, 'detailRefusalReadFence' => $longDetailFence,
    ];

    $shortBodyBudget = 800;
    $shortBody = $forwardHeader;
    while (strlen($shortBody) + strlen($forwardLine) + strlen($footer) <= $shortBodyBudget) $shortBody .= $forwardLine;
    $shortBody .= $footer;
    $shortBodyCharacters = preg_match_all('/./us', $shortBody);
    dev20_require(is_int($shortBodyCharacters) && strlen($shortBody) === 775 && $shortBodyCharacters === 775
        && hash_equals('4864c4f391db9093233b017eaccc2f29c8a9a3ea3200203b0d52a35ce4b385be', hash('sha256', $shortBody))
        && is_int($renderHeaderCharacters) && $shortBodyCharacters + $renderHeaderCharacters <= 2000,
        'forwarded_letter_renderable_fixture_changed_or_outside_owner_bound');
    $shortRequestId = dev20_random_uuid();
    $shortPayload = dev20_compose_payload($shortRequestId, ['body' => $shortBody, 'signatureActorKey' => 'player']);
    $shortPresence = dev20_run_owner_fixture($ctx, 'seed-presence');
    dev20_require(($shortPresence['mode'] ?? null) === 'seed-presence' && ($shortPresence['ready'] ?? null) === true
        && ($shortPresence['effectiveOwnerRole'] ?? null) === 'dwemer' && ($shortPresence['presenceExecutor'] ?? null) === 'Lydia',
        'full_forwarded_letter_short_presence_not_verified');
    $beforeShortCompose = dev20_owner_snapshot($admin);
    $shortCompose = dev20_api_call($ctx, 'full_forwarded_letter_short_compose', 'correspondence.compose', $shortPayload,
        ['operationRequestId' => $shortRequestId]);
    $shortComposeResponse = dev20_simple_assert_response($shortCompose, true);
    dev20_require(($shortComposeResponse['data']['state'] ?? null) === 'dispatched'
        && ($shortComposeResponse['data']['operationId'] ?? null) === $shortRequestId,
        'forwarded_letter_renderable_compose_not_dispatched');
    dev20_require(dev20_has_commit_entered($shortCompose['fixtureRoot'], $shortRequestId), 'forwarded_letter_renderable_commit_marker_missing');
    $shortDocumentRows = dev20_pg($admin,
        "SELECT document_id::text AS document_id,payload->>'operation_id' AS operation_id,payload::text AS payload "
        . 'FROM plugins.correspondence_documents WHERE request_id=$1', [$shortRequestId]);
    dev20_require(count($shortDocumentRows) === 1, 'forwarded_letter_renderable_owner_document_missing');
    $shortDocument = json_decode((string)$shortDocumentRows[0]['payload'], true, 64);
    $ownerOperationId = is_array($shortDocument) ? ($shortDocument['operation_id'] ?? null) : null;
    dev20_require(is_array($shortDocument) && is_string($ownerOperationId)
        && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $ownerOperationId) === 1
        && ($shortDocumentRows[0]['operation_id'] ?? null) === $ownerOperationId
        && ($shortDocument['document_id'] ?? null) === ($shortDocumentRows[0]['document_id'] ?? null)
        && ($shortDocument['request_id'] ?? null) === $shortRequestId
        && ($shortDocument['body'] ?? null) === $shortBody && ($shortDocument['author']['name'] ?? null) === $playerName
        && ($shortDocument['claimed_sender']['name'] ?? null) === $playerName
        && ($shortDocument['recipient']['name'] ?? null) === $recipientName,
        'forwarded_letter_renderable_owner_document_identity_or_body_mismatch');
    $ownerOperationRows = dev20_pg($admin,
        'SELECT operation_id::text AS operation_id,document_id::text AS document_id,payload::text AS payload '
        . 'FROM plugins.correspondence_operations WHERE operation_id=$1 AND document_id=$2',
        [$ownerOperationId, $shortDocument['document_id']]);
    dev20_require(count($ownerOperationRows) === 1
        && ($ownerOperationRows[0]['operation_id'] ?? null) === $ownerOperationId
        && ($ownerOperationRows[0]['document_id'] ?? null) === $shortDocument['document_id'],
        'forwarded_letter_owner_operation_row_link_invalid');
    $ownerOperation = json_decode((string)$ownerOperationRows[0]['payload'], true, 64);
    $dispatchAnchor = is_array($ownerOperation) ? ($ownerOperation['dispatch_anchor'] ?? null) : null;
    $dispatchData = is_array($dispatchAnchor) && is_string($dispatchAnchor['data'] ?? null)
        ? json_decode($dispatchAnchor['data'], true, 32) : null;
    dev20_require(is_array($ownerOperation) && ($ownerOperation['operation_id'] ?? null) === $ownerOperationId
        && ($ownerOperation['document_id'] ?? null) === $shortDocument['document_id']
        && is_array($dispatchAnchor) && is_string($dispatchAnchor['rowid'] ?? null)
        && ctype_digit($dispatchAnchor['rowid']) && (int)$dispatchAnchor['rowid'] > 0
        && ($dispatchAnchor['type'] ?? null) === 'info' && ($dispatchAnchor['sess'] ?? null) === 'correspondence_v1'
        && is_array($dispatchData) && ($dispatchData['document_id'] ?? null) === $shortDocument['document_id']
        && ($dispatchData['operation_id'] ?? null) === $ownerOperationId,
        'forwarded_letter_owner_operation_payload_anchor_invalid');
    $dispatchRows = dev20_pg($admin,
        'SELECT rowid::text AS rowid,type,data,sess FROM public.eventlog WHERE rowid=$1', [$dispatchAnchor['rowid']]);
    dev20_require(count($dispatchRows) === 1 && ($dispatchRows[0]['rowid'] ?? null) === $dispatchAnchor['rowid']
        && ($dispatchRows[0]['type'] ?? null) === $dispatchAnchor['type']
        && ($dispatchRows[0]['data'] ?? null) === $dispatchAnchor['data']
        && ($dispatchRows[0]['sess'] ?? null) === $dispatchAnchor['sess'],
        'forwarded_letter_owner_dispatch_event_anchor_missing');
    $afterShortCompose = dev20_owner_snapshot($admin);
    foreach (['plugins.correspondence_documents', 'plugins.correspondence_operations', 'public.books', 'public.eventlog', 'public.responselog'] as $table) {
        dev20_require((int)($afterShortCompose['counts'][$table] ?? 0) > (int)($beforeShortCompose['counts'][$table] ?? 0),
            'forwarded_letter_renderable_dispatch_effect_missing_' . str_replace('.', '_', $table));
    }
    $shortUnexpectedMutations = array_values(array_filter(dev20_owner_mutation_statements($shortCompose['sqlStatements'] ?? []),
        static fn(string $sql): bool => preg_match('/\b(?:plugins\.correspondence_documents|plugins\.correspondence_operations|public\.books|public\.eventlog|public\.responselog)\b/i', $sql) === 1));
    dev20_require($shortUnexpectedMutations !== [], 'forwarded_letter_renderable_dispatch_sql_not_observed');
    $receipt = dev20_run_seed_receipt($ctx, 'full_forwarded_letter_detail', 'delivered', $shortRequestId);
    dev20_require(($receipt['accepted'] ?? null) === true && ($receipt['status'] ?? null) === 'delivered',
        'forwarded_letter_positive_receipt_not_accepted');

    $before = dev20_owner_snapshot($admin);
    $sqlOffset = dev20_case_log_start($ctx);
    $detailResult = dev20_api_call($ctx, 'full_forwarded_letter_short_detail_read', 'correspondence.detail.read',
        ['recipientActorKey' => 'npc:900003', 'letterId' => $shortDocument['document_id']],
        ['authScopes' => ['inspector', 'author_detail', 'compose']]);
    $detail = dev20_simple_assert_response($detailResult, true);
    $fence = dev20_read_fence_evidence($ctx, (string)$detailResult['app'], $sqlOffset);
    dev20_assert_single_read_fence($fence);
    $data = $detail['data'] ?? [];
    dev20_require(($data['letterId'] ?? null) === $shortDocument['document_id']
        && ($data['body'] ?? null) === $shortBody && strlen((string)($data['body'] ?? '')) === strlen($shortBody)
        && ($data['deliveryConfirmed'] ?? null) === true
        && ($data['claimedSignature'] ?? null) === $playerName && ($data['recordedAuthor'] ?? null) === $playerName
        && str_starts_with($shortBody, $forwardHeader) && str_ends_with($shortBody, $footer)
        && str_contains($shortBody, "\nFrom: " . $playerName . "\n")
        && str_contains($shortBody, "\nTo: " . $recipientName . "\n"), 'forwarded_letter_detail_truncated_or_identity_changed');
    $after = dev20_owner_snapshot($admin);
    dev20_require(dev20_unchanged($before, $after), 'forwarded_letter_detail_mutated_rows');
    dev20_require_no_owner_mutation_sql($detailResult, 'forwarded_letter_detail_emitted_owner_mutation');
    $finalFontSha256 = @hash_file('sha256', $fontPath);
    $finalBackgroundSha256 = @hash_file('sha256', $backgroundPath);
    dev20_require($finalFontSha256 === $fontSha256 && $finalBackgroundSha256 === $backgroundSha256,
        'forwarded_letter_renderer_assets_changed_during_case');
    return ['operationId' => $shortRequestId, 'ownerOperationId' => $ownerOperationId,
        'presenceRefresh' => $shortPresence, 'letterId' => $shortDocument['document_id'],
        'sourceDocumentBodySha256' => hash('sha256', $shortBody), 'persistedBodySha256' => hash('sha256', (string)$shortDocument['body']),
        'facadeBodySha256' => hash('sha256', (string)$data['body']),
        'bodyCharacters' => $shortBodyCharacters, 'bodyBytes' => strlen($shortBody), 'rendererHeaderCharacters' => $renderHeaderCharacters,
        'withinOwner2000CharacterBound' => true, 'forwardHeaderPreserved' => true,
        'claimedSignature' => $data['claimedSignature'], 'recordedAuthor' => $data['recordedAuthor'],
        'recipient' => $recipientName, 'deliveryConfirmed' => true, 'positiveReceipt' => $receipt, 'readFence' => $fence, 'readOnly' => true,
        'longQueued' => $longQueued,
        'rendererMeasurement' => ['backgroundDimensionsPx' => [$dimensions[0], $dimensions[1]], 'fontSizePt' => 8, 'lineCount' => 21,
            'bottomGuardRemainingPx' => 21, 'rightGuardRemainingPx' => 1, 'fontSha256' => $fontSha256,
            'backgroundSha256' => $backgroundSha256, 'ownerBoundariesSha256' => $layoutSha256,
            'assetHashesMatchPinnedInputs' => true, 'assetsUnchangedDuringCase' => true,
            'measurement' => 'Windows PHP 8.5.11/GD pinned correspondenceNoteLayout probe; successful facade dispatch is the runtime fit oracle']];
}, $admin) && $overallPassed;

// Case 33: freeze actual SQL and row-count deltas after all facade scenarios.
$overallPassed = dev20_case_record($ctx, 'actual_sql_and_row_deltas', static function () use ($ctx, $admin, $pinHashes): array {
    require_once $ctx['serverRoot'] . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'runtime_support.php';
    require_once $ctx['serverRoot'] . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'correspondence.php';
    dev20_require(function_exists('qd_runtime_with_owned_connection')
        && function_exists('qd_correspondence_close_owned_pg_connection'), 'pinned_close_wrapper_missing');
    $owned = dev20_admin_connect($ctx['pgSocket'], $ctx['database'], $ctx['adminRole']);
    $ownedPid = pg_get_pid($owned);
    $primary = 'dev20-primary-operation-failure';
    $observed = null;
    try {
        qd_runtime_with_owned_connection(
            static fn(): mixed => $owned,
            static fn($connection): mixed => qd_correspondence_close_owned_pg_connection($connection),
            static function ($connection) use ($primary): never {
                @pg_close($connection);
                throw new RuntimeException($primary);
            },
        );
    } catch (Throwable $error) {
        $observed = $error->getMessage();
    }
    dev20_require($observed === $primary, 'already_closed_owner_connection_replaced_primary_error');
    $ownedGone = dev20_wait_for($ctx, static function () use ($admin, $ownedPid): array {
        return dev20_pg($admin, 'SELECT pid::text FROM pg_stat_activity WHERE pid=$1', [$ownedPid]) === [] ? ['gone' => true] : [];
    }, 15);
    dev20_require(dev20_pg_scalar($admin, 'SELECT 1') === '1', 'unrelated_admin_connection_broken_by_close_probe');
    $rows = dev20_pg($admin,
        "SELECT pid::text AS pid,application_name,usename,state,wait_event_type,wait_event "
        . "FROM pg_stat_activity WHERE datname='dwemer' AND application_name LIKE 'dev20-%' ORDER BY pid");
    $log = @file_get_contents($ctx['pgLogPath']);
    dev20_require(is_string($log) && strlen($log) > 0, 'postgres_statement_log_missing');
    dev20_require(count($pinHashes) === 19, 'owner_source_hash_set_incomplete');
    return ['alreadyClosedPgSqlConnection' => true, 'closeWrapperPreservedPrimaryError' => true,
        'ownedBackendPid' => $ownedPid, 'ownedBackendGone' => $ownedGone['gone'] ?? false,
        'unrelatedAdminStillUsable' => true, 'scope' => 'helper-boundary probe only; does not claim owner-store unlock-path injection',
        'activeDev20SessionsAtEnd' => $rows, 'loadedOwnerHashes' => $pinHashes, 'postgresLogBytes' => strlen($log),
        'providerCalls' => 0, 'rawStdoutAndStderrSavedSeparately' => true,
        'rowCounts' => dev20_owner_snapshot($admin)['counts']];
}, $admin) && $overallPassed;

$facadeCaseCount = count($ctx['results']);
$companionSummary = dev23_companion_cases($ctx);
$companionRows = array_slice($ctx['results'], $facadeCaseCount);
$companionIds = array_column($companionRows, 'id');
$validCompanionSummary = is_array($companionSummary)
    && ($companionSummary['schema'] ?? null) === 'department.dev23.companion-cases.v1'
    && ($companionSummary['passed'] ?? null) === true
    && is_int($companionSummary['expectedCases'] ?? null) && $companionSummary['expectedCases'] > 0
    && ($companionSummary['executedCases'] ?? null) === $companionSummary['expectedCases']
    && ($companionSummary['passedCases'] ?? null) === $companionSummary['expectedCases']
    && ($companionSummary['failedCases'] ?? null) === []
    && ($companionSummary['caseIds'] ?? null) === $companionIds
    && ($companionSummary['providerCalls'] ?? null) === 0
    && count($companionRows) === $companionSummary['expectedCases']
    && count(array_unique($companionIds)) === count($companionIds)
    && count(array_filter($companionIds, static fn(mixed $id): bool => is_string($id) && str_starts_with($id, 'companion_'))) === count($companionIds);
if ($facadeCaseCount !== 33) dev20_fail('facade_case_count_invalid');
if (!$validCompanionSummary) $overallPassed = false;

$failedCases = array_values(array_filter($ctx['results'], static fn(array $row): bool => $row['status'] !== 'passed'));
$passedCount = count(array_filter($ctx['results'], static fn(array $row): bool => $row['status'] === 'passed'));
$expectedCaseCount = 33 + (int)($companionSummary['expectedCases'] ?? 0);
$summary = [
    'schema' => 'department.dev20.pg-scenarios.v1',
    'runId' => $runId,
    'passed' => $overallPassed && $validCompanionSummary && $passedCount === $expectedCaseCount,
    'facadeExpectedCases' => 33,
    'companionCases' => $companionSummary,
    'expectedCases' => $expectedCaseCount,
    'executedCases' => count($ctx['results']),
    'passedCases' => $passedCount,
    'failedCases' => $failedCases,
    'providerCalls' => 0,
    'ownerInitializedAfterFreshRead' => $ownerInitialized,
    'presenceSeeded' => $presenceSeeded,
    'presenceExecutor' => $presenceExecutor,
    'presenceSeedFailure' => $presenceSeedFailure,
    'privatePostgresOnly' => true,
    'apiBoundary' => 'qd_api_handle via api_driver.php; no HTTP transport claim',
    'database' => ['host' => '127.0.0.1', 'port' => 5432, 'database' => 'dwemer', 'appRole' => 'dwemer', 'adminRole' => 'postgres'],
    'ownerClosureFileCount' => count($pinHashes),
    'ownerStageProvenance' => ['ownerRoot' => 'K:\\ActorwrightExchange\\artifacts\\chim-sealed-fates-release-dev14-20261020\\source',
        'ownerVersion' => '0.1.0-dev.23', 'ownerCommit' => '01d62982d9b10ca4fce040af9d97fe7111231ba3',
        'pinManifestSha256' => '45e43d191e747d5593b5cc912bbcd9ec1e6597cd8e5ce9e6e91130d5a07e04ef',
        'claim' => 'immutable dev23 source pin provenance only; not a PostgreSQL or runtime outcome'],
    'coreSourceBinding' => ['sourceRoot' => 'K:\\ActorwrightExchange\\artifacts\\chim-correspondence-offscreen-20261019\\upstream\\HerikaServer',
        'approvedManifest' => 'K:\\ActorwrightExchange\\artifacts\\department-dev18-candidate-20261021\\coordinator\\approved-core-source-pins.json',
        'approvedManifestSchema' => 'department.dev18.approved-core-source.v1',
        'approvedManifestSha256' => 'd81281bc30a240f2ef819044dad6ee54786edca44d25ccfae230aa2bc98517a0',
        'commit' => '0ec590b42c8087860138bb4e9aab470049ff9a2b', 'tree' => 'ab97c6dd3947501b8a76bf9b9c6ad74b30be3975'],
    'sourceMutations' => ['wsl' => false, 'service' => false, 'provider' => false, 'game' => false, 'liveOrInstalled' => false, 'git' => false],
    'cases' => $ctx['results'],
];
dev20_json_write($ctx['artifactDir'] . DIRECTORY_SEPARATOR . 'linux.scenarios.summary.json', $summary);
@pg_close($admin);
exit($summary['passed'] ? 0 : 1);
