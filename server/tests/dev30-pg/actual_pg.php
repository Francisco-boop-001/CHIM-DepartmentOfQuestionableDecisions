<?php
declare(strict_types=1);

/** Focused, parent-launched actual PostgreSQL proof for Department dev.30. */
final class Dev30PgFailure extends RuntimeException {}

function dev20_require(bool $condition, string $evidence): void
{
    if (!$condition) throw new Dev30PgFailure($evidence);
}

function dev30_assert(bool $condition, string $evidence): void
{
    if (!$condition) throw new Dev30PgFailure($evidence);
    $GLOBALS['dev30_assertions']++;
}

function dev30_pg(mixed $connection, string $sql, array $parameters = []): array
{
    $result = $parameters === [] ? @pg_query($connection, $sql) : @pg_query_params($connection, $sql, $parameters);
    if ($result === false) throw new RuntimeException('database_query_failed');
    try { return pg_num_fields($result) > 0 ? (pg_fetch_all($result) ?: []) : []; }
    finally { pg_free_result($result); }
}

function dev30_scalar(mixed $connection, string $sql, array $parameters = []): mixed
{
    $rows = dev30_pg($connection, $sql, $parameters);
    return count($rows) === 1 && $rows[0] !== [] ? reset($rows[0]) : null;
}

function dev30_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function dev30_require_file(string $path, string $root): string
{
    if (is_link($path) || !is_file($path)) throw new RuntimeException('source_file_invalid');
    $real = realpath($path);
    $realRoot = realpath($root);
    if (!is_string($real) || !is_string($realRoot) || !str_starts_with($real, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('source_file_outside_root');
    }
    return $real;
}

function dev30_owner_pins(string $ownerRoot, array $pins): void
{
    foreach ($pins as $relative => $expected) {
        if (!is_string($relative) || !is_string($expected) || preg_match('/\A[a-f0-9]{64}\z/D', $expected) !== 1) {
            throw new RuntimeException('owner_pin_shape_invalid');
        }
        $path = $ownerRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        dev30_require_file($path, $ownerRoot);
        $actual = strtolower((string)hash_file('sha256', $path));
        if (!hash_equals($expected, $actual)) throw new RuntimeException('owner_pin_mismatch');
    }
}

function dev30_source_hashes(string $serverRoot, array $files): array
{
    $hashes = [];
    foreach ($files as $relative) {
        if (!is_string($relative) || !str_starts_with($relative, 'server/') || str_contains($relative, '..')) {
            throw new RuntimeException('department_source_path_invalid');
        }
        $path = $serverRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($relative, 7));
        dev30_require_file($path, $serverRoot);
        $hashes[$relative] = strtolower((string)hash_file('sha256', $path));
    }
    return $hashes;
}

function dev30_private_dir(string $path, int $mode = 0700): string
{
    if (is_link($path) || (file_exists($path) && !is_dir($path))) throw new RuntimeException('private_directory_invalid');
    if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) throw new RuntimeException('private_directory_create_failed');
    $real = realpath($path);
    if (!is_string($real) || $real !== $path || !@chmod($path, $mode)) throw new RuntimeException('private_directory_identity_invalid');
    return $real;
}

function dev30_ipv6_route_kind(string $line): ?string
{
    $fields = preg_split('/\s+/', trim($line)) ?: [];
    if (count($fields) !== 10) return null;
    [$destination, $prefix, $source, $sourcePrefix, $gateway, $metric, $refCount, $use, $flags, $interface] = $fields;
    if (preg_match('/\A[0-9a-f]{32}\z/iD', $destination) !== 1
        || preg_match('/\A[0-9a-f]{2}\z/iD', $prefix) !== 1
        || preg_match('/\A[0-9a-f]{32}\z/iD', $source) !== 1
        || preg_match('/\A[0-9a-f]{2}\z/iD', $sourcePrefix) !== 1
        || preg_match('/\A[0-9a-f]{32}\z/iD', $gateway) !== 1
        || preg_match('/\A[0-9a-f]{8}\z/iD', $metric) !== 1
        || preg_match('/\A[0-9a-f]{8}\z/iD', $refCount) !== 1
        || preg_match('/\A[0-9a-f]{8}\z/iD', $use) !== 1
        || preg_match('/\A[0-9a-f]{8}\z/iD', $flags) !== 1) return null;
    $destination = strtolower($destination); $prefix = strtolower($prefix);
    $source = strtolower($source); $sourcePrefix = strtolower($sourcePrefix);
    $gateway = strtolower($gateway); $metric = strtolower($metric); $flags = strtolower($flags);
    $zero = str_repeat('0', 32);
    $flagBits = (int)hexdec($flags);
    if ($destination === (str_repeat('0', 31) . '1') && $prefix === '80' && $source === $zero && $sourcePrefix === '00'
        && $gateway === $zero && $interface === 'lo' && ($flagBits & 0x1) === 0x1) return 'loopback';
    if ($destination === $zero && $prefix === '00' && $source === $zero && $sourcePrefix === '00'
        && $gateway === $zero && $metric === 'ffffffff' && $interface === 'lo'
        && $flags === '00200200' && ($flagBits & 0x200) === 0x200 && ($flagBits & 0x1) === 0) return 'reject_sentinel';
    return null;
}

function dev30_ipv6_route_regression(): int
{
    $zero = str_repeat('0', 32);
    $sentinel = implode(' ', [$zero, '00', $zero, '00', $zero, 'ffffffff', '00000001', '00000000', '00200200', 'lo']);
    $loopback = implode(' ', [str_repeat('0', 31) . '1', '80', $zero, '00', $zero, '00000000', '00000002', '00000000', '80200001', 'lo']);
    $activeDefault = implode(' ', [$zero, '00', $zero, '00', $zero, '00000000', '00000001', '00000000', '00000001', 'lo']);
    $upSentinel = implode(' ', [$zero, '00', $zero, '00', $zero, 'ffffffff', '00000001', '00000000', '00200201', 'lo']);
    $nextHopSentinel = implode(' ', [$zero, '00', $zero, '00', 'fe800000000000000000000000000001', 'ffffffff', '00000001', '00000000', '00200200', 'lo']);
    $interfaceSentinel = implode(' ', [$zero, '00', $zero, '00', $zero, 'ffffffff', '00000001', '00000000', '00200200', 'eth0']);
    $external = implode(' ', ['20010db8000000000000000000000000', '40', $zero, '00', 'fe800000000000000000000000000001',
        '00000000', '00000001', '00000000', '00000003', 'eth0']);
    $checks = 0;
    foreach ([$sentinel, $sentinel] as $capturedRow) {
        if (dev30_ipv6_route_kind($capturedRow) !== 'reject_sentinel') throw new RuntimeException('ipv6_sentinel_regression_failed');
        $checks++;
    }
    if (dev30_ipv6_route_kind($loopback) !== 'loopback') throw new RuntimeException('ipv6_loopback_regression_failed');
    $checks++;
    foreach ([
        'ipv6_active_default_regression_failed' => $activeDefault,
        'ipv6_up_sentinel_regression_failed' => $upSentinel,
        'ipv6_nexthop_sentinel_regression_failed' => $nextHopSentinel,
        'ipv6_interface_sentinel_regression_failed' => $interfaceSentinel,
        'ipv6_external_route_regression_failed' => $external,
    ] as $failure => $negativeRoute) {
        if (dev30_ipv6_route_kind($negativeRoute) !== null) throw new RuntimeException($failure);
        $checks++;
    }
    return $checks;
}

function dev30_loopback_interface_evidence(mixed $interfaces): ?array
{
    if (!is_array($interfaces)) return null;
    $names = array_keys($interfaces);
    sort($names, SORT_STRING);
    if ($names !== ['lo'] || !is_array($interfaces['lo'] ?? null)
        || ($interfaces['lo']['up'] ?? null) !== true || !is_array($interfaces['lo']['unicast'] ?? null)
        || !array_is_list($interfaces['lo']['unicast']) || count($interfaces['lo']['unicast']) !== 3) return null;
    $byFamily = [];
    foreach ($interfaces['lo']['unicast'] as $entry) {
        if (!is_array($entry) || !is_int($entry['family'] ?? null) || !is_int($entry['flags'] ?? null)
            || ($entry['flags'] & 0x9) !== 0x9 || isset($byFamily[$entry['family']])) return null;
        $family = $entry['family'];
        if ($family === 17) {
            if (array_key_exists('address', $entry)) return null;
        } elseif ($family === 2) {
            if (($entry['address'] ?? null) !== '127.0.0.1') return null;
        } elseif ($family === 10) {
            if (($entry['address'] ?? null) !== '::1') return null;
        } else return null;
        $byFamily[$family] = $entry['flags'];
    }
    $families = array_keys($byFamily);
    sort($families, SORT_NUMERIC);
    if ($families !== [2, 10, 17]) return null;
    $commonFlags = $byFamily[2] & $byFamily[10] & $byFamily[17];
    return ['interfaces' => ['lo'], 'loopbackFlags' => sprintf('0x%x', $commonFlags)];
}

function dev30_loopback_interface_regression(): int
{
    $flags = 65609;
    $captured = ['lo' => [
        'unicast' => [
            ['flags' => $flags, 'family' => 17],
            ['flags' => $flags, 'family' => 2, 'address' => '127.0.0.1', 'netmask' => '255.0.0.0'],
            ['flags' => $flags, 'family' => 10, 'address' => '::1', 'netmask' => 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
        ],
        'up' => true,
    ]];
    $positive = dev30_loopback_interface_evidence($captured);
    if ($positive !== ['interfaces' => ['lo'], 'loopbackFlags' => '0x10049']) throw new RuntimeException('loopback_interface_positive_regression_failed');
    $checks = 1;
    $extraInterface = $captured; $extraInterface['eth0'] = ['unicast' => [], 'up' => true];
    $extraUnicast = $captured; $extraUnicast['lo']['unicast'][] = ['flags' => $flags, 'family' => 99, 'address' => '2001:db8::1'];
    $nonLoopbackAddress = $captured; $nonLoopbackAddress['lo']['unicast'][1]['address'] = '127.0.0.2';
    $down = $captured; $down['lo']['up'] = false;
    $missingAddress = $captured; unset($missingAddress['lo']['unicast'][2]['address']);
    $missingFamily = $captured; array_pop($missingFamily['lo']['unicast']);
    $badFlags = $captured; $badFlags['lo']['unicast'][0]['flags'] = 65601;
    $packetAddress = $captured; $packetAddress['lo']['unicast'][0]['address'] = '127.0.0.1';
    foreach ([
        'loopback_extra_interface_regression_failed' => $extraInterface,
        'loopback_extra_unicast_regression_failed' => $extraUnicast,
        'loopback_nonloopback_address_regression_failed' => $nonLoopbackAddress,
        'loopback_down_regression_failed' => $down,
        'loopback_missing_address_regression_failed' => $missingAddress,
        'loopback_missing_family_regression_failed' => $missingFamily,
        'loopback_flags_regression_failed' => $badFlags,
        'loopback_packet_address_regression_failed' => $packetAddress,
    ] as $failure => $negative) {
        if (dev30_loopback_interface_evidence($negative) !== null) throw new RuntimeException($failure);
        $checks++;
    }
    return $checks;
}

function dev30_loopback_preflight(array $fixtureIdentity): array
{
    dev30_ipv6_route_regression();
    dev30_loopback_interface_regression();
    $networkNamespace = @readlink('/proc/self/ns/net');
    if (!is_string($networkNamespace) || $networkNamespace === ''
        || $networkNamespace !== ($fixtureIdentity['networkNamespace'] ?? null)
        || $networkNamespace === ($fixtureIdentity['initialNetworkNamespace'] ?? null)) {
        throw new RuntimeException('loopback_namespace_mismatch');
    }
    if (!function_exists('net_get_interfaces')) throw new RuntimeException('network_interface_api_unavailable');
    $interfaceEvidence = dev30_loopback_interface_evidence(net_get_interfaces());
    if (!is_array($interfaceEvidence)) throw new RuntimeException('loopback_interface_topology_invalid');
    $interfaces = $interfaceEvidence['interfaces'];

    $ipv4Raw = @file_get_contents('/proc/net/route');
    if (!is_string($ipv4Raw)) throw new RuntimeException('ipv4_routes_unreadable');
    $ipv4Lines = preg_split('/\r?\n/', trim($ipv4Raw)) ?: [];
    foreach (array_slice($ipv4Lines, 1) as $line) {
        if (trim($line) === '') continue;
        $fields = preg_split('/\s+/', trim($line)) ?: [];
        if (count($fields) < 8 || ($fields[0] ?? null) !== 'lo'
            || preg_match('/\A[0-9a-f]{8}\z/iD', $fields[1] ?? '') !== 1
            || preg_match('/\A[0-9a-f]{8}\z/iD', $fields[2] ?? '') !== 1
            || preg_match('/\A[0-9a-f]{8}\z/iD', $fields[7] ?? '') !== 1) {
            throw new RuntimeException('ipv4_route_shape_invalid');
        }
        $destination = @inet_ntop(pack('V', (int)hexdec($fields[1])));
        $gateway = @inet_ntop(pack('V', (int)hexdec($fields[2])));
        $mask = @inet_ntop(pack('V', (int)hexdec($fields[7])));
        $destinationLong = is_string($destination) ? ip2long($destination) : false;
        $maskLong = is_string($mask) ? ip2long($mask) : false;
        if (!is_string($destination) || !is_string($gateway) || !is_string($mask)
            || $destinationLong === false || $maskLong === false || $maskLong === 0
            || ip2long($gateway) !== 0 || (($destinationLong & $maskLong) & 0xFF000000) !== 0x7F000000) {
            throw new RuntimeException('ipv4_external_or_default_route');
        }
    }

    $ipv6Raw = @file_get_contents('/proc/net/ipv6_route');
    if (!is_string($ipv6Raw)) throw new RuntimeException('ipv6_routes_unreadable');
    $ipv6Counts = ['loopback' => 0, 'reject_sentinel' => 0];
    foreach (preg_split('/\r?\n/', trim($ipv6Raw)) ?: [] as $line) {
        if (trim($line) === '') continue;
        $kind = dev30_ipv6_route_kind($line);
        if (!is_string($kind)) throw new RuntimeException('ipv6_external_or_default_route');
        $ipv6Counts[$kind]++;
    }
    if ($ipv6Counts !== ['loopback' => 1, 'reject_sentinel' => 2]) throw new RuntimeException('ipv6_route_inventory_invalid');

    return [
        'networkNamespace' => $networkNamespace,
        'markerNetworkNamespace' => $fixtureIdentity['networkNamespace'],
        'initialNetworkNamespace' => $fixtureIdentity['initialNetworkNamespace'],
        'interfaces' => $interfaces,
        'loopbackFlags' => $interfaceEvidence['loopbackFlags'],
        'ipv4Routes' => $ipv4Raw,
        'ipv4RouteSha256' => hash('sha256', $ipv4Raw),
        'ipv6Routes' => $ipv6Raw,
        'ipv6RouteSha256' => hash('sha256', $ipv6Raw),
        'privateLoopbackOnly' => true,
    ];
}

function dev30_owned_pg_data_directory(string $scratchRoot, string $reported): string
{
    $expected = $scratchRoot . '/postgres/data';
    foreach ([$scratchRoot, $scratchRoot . '/postgres', $expected] as $path) {
        if (is_link($path) || !is_dir($path)) throw new RuntimeException('postgres_data_path_invalid');
    }
    if (realpath($expected) !== $expected || $reported !== $expected) throw new RuntimeException('postgres_data_path_mismatch');
    return $expected;
}

function dev30_count_snapshot(mixed $admin): array
{
    $out = [];
    foreach ([
        'plugins.correspondence_documents', 'plugins.correspondence_operations',
        'plugins.correspondence_companion_decision', 'plugins.correspondence_companion_episode',
        'plugins.correspondence_restore_state', 'public.eventlog', 'public.books', 'public.responselog',
    ] as $table) {
        $out[$table] = (string)dev30_scalar($admin, 'SELECT count(*)::text FROM ' . $table);
    }
    return $out;
}

function dev30_fixture_error_handler(int $errno, string $errstr, string $errfile, int $errline): bool
{
    if (!empty($GLOBALS['pgr_operation']) && str_contains($errstr, 'pg_')) $GLOBALS['pgr_sql_failed'] = true;
    return false;
}

function dev30_install_fixture_logger(string $coreRoot, string $expectedHash): string
{
    $loggerPath = dev30_require_file($coreRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'logger.php', $coreRoot);
    if (!hash_equals($expectedHash, strtolower((string)hash_file('sha256', $loggerPath)))) throw new RuntimeException('captured_logger_pin_mismatch');
    $logDirectory = $coreRoot . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'playthrough_runtime';
    if (is_link($coreRoot . DIRECTORY_SEPARATOR . 'log') || is_link($logDirectory)
        || !is_dir($logDirectory) || realpath($logDirectory) !== $logDirectory) throw new RuntimeException('fixture_logger_directory_invalid');
    $logPath = $logDirectory . DIRECTORY_SEPARATOR . 'fixture-chim.log';
    if (is_link($logPath) || file_exists($logPath)) throw new RuntimeException('fixture_logger_file_not_fresh');
    $handle = @fopen($logPath, 'x+b');
    if (!is_resource($handle)) throw new RuntimeException('fixture_logger_create_failed');
    fclose($handle);
    if (posix_geteuid() === 0 && (!@chown($logPath, 'www-data') || !@chgrp($logPath, 'www-data'))) throw new RuntimeException('fixture_logger_owner_failed');
    if (!@chmod($logPath, 0660) || !is_writable($logPath)) throw new RuntimeException('fixture_logger_permissions_failed');
    require_once $loggerPath;
    if (!class_exists('Logger', false) || !is_callable(['Logger', 'setCustomLog'])) throw new RuntimeException('captured_logger_unavailable');
    Logger::setCustomLog($logPath);
    set_error_handler('dev30_fixture_error_handler');
    return $logPath;
}

function dev31_write_interaction_state(array &$ctx, bool $enabled, int $generation): string
{
    $path = $ctx['interactionStatePath'] ?? null;
    if (!is_string($path) || $generation < 0 || is_link($path)
        || realpath(dirname($path)) !== dirname($path)
        || dirname($path) !== $ctx['coreRoot'] . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'chim_interaction') {
        throw new RuntimeException('interaction_fixture_path_invalid');
    }
    $bytes = json_encode(['enabled' => $enabled, 'generation' => $generation], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_exists($path)) {
        if (!is_file($path)) throw new RuntimeException('interaction_fixture_file_invalid');
        $written = @file_put_contents($path, $bytes, LOCK_EX);
    } else {
        $handle = @fopen($path, 'x+b');
        if (!is_resource($handle)) throw new RuntimeException('interaction_fixture_create_failed');
        try {
            $written = fwrite($handle, $bytes);
            if (!fflush($handle)) throw new RuntimeException('interaction_fixture_write_failed');
        } finally { fclose($handle); }
    }
    if (!is_int($written) || $written !== strlen($bytes)) throw new RuntimeException('interaction_fixture_write_failed');
    if (function_exists('posix_geteuid') && posix_geteuid() === 0
        && (!@chown($path, 'www-data') || !@chgrp($path, 'www-data'))) throw new RuntimeException('interaction_fixture_owner_failed');
    if (!@chmod($path, 0664) || file_get_contents($path) !== $bytes || is_link($path)) {
        throw new RuntimeException('interaction_fixture_verification_failed');
    }
    $hash = strtolower((string)hash_file('sha256', $path));
    if (preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) throw new RuntimeException('interaction_fixture_hash_failed');
    $fixtureEvidence = [
        'path' => 'conf/chim_interaction/state.json', 'enabled' => $enabled,
        'generation' => $generation, 'bytes' => strlen($bytes), 'sha256' => $hash,
        'provenance' => 'synthetic owned per-run scratch fixture',
    ];
    if (!isset($ctx['interactionFixtureInitial'])) $ctx['interactionFixtureInitial'] = $fixtureEvidence;
    $ctx['interactionFixture'] = $fixtureEvidence;
    $ctx['interactionStateTransitions'][] = $fixtureEvidence;
    return $hash;
}

function dev31_prepare_responselog_generation_column(mixed $admin): void
{
    dev30_pg($admin, 'ALTER TABLE public.responselog ADD COLUMN IF NOT EXISTS interaction_generation BIGINT');
    $columns = dev30_pg($admin,
        "SELECT data_type,udt_name FROM information_schema.columns "
            . "WHERE table_schema='public' AND table_name='responselog' AND column_name='interaction_generation'");
    if (count($columns) !== 1 || ($columns[0]['data_type'] ?? null) !== 'bigint'
        || ($columns[0]['udt_name'] ?? null) !== 'int8') {
        throw new RuntimeException('interaction_generation_fixture_column_invalid');
    }
}

function dev31_stage_interaction_helper(array &$ctx, array $input, string $sourcePath): void
{
    $entry = $input['files']['lib/chim_interaction.php'] ?? null;
    $expected = is_array($entry) ? ($entry['sha256'] ?? null) : null;
    if (($input['schemaVersion'] ?? null) !== 1 || ($input['hostCommit'] ?? null) !== null
        || ($input['hostTree'] ?? null) !== null
        || ($input['provenance'] ?? null) !== 'Captured core helper; no independent Git mapping was recorded.'
        || !is_array($input['files'] ?? null) || array_keys($input['files']) !== ['lib/chim_interaction.php']
        || !is_array($entry) || ($entry['source'] ?? null) !== 'artifacts/department-maintainer-audit-20261026/host/server-dev/lib/chim_interaction.php'
        || !is_string($expected) || preg_match('/\A[a-f0-9]{64}\z/D', $expected) !== 1
        || !is_string($sourcePath) || is_link($sourcePath) || !is_file($sourcePath)) {
        throw new RuntimeException('interaction_core_input_manifest_invalid');
    }
    $sourceReal = realpath($sourcePath);
    $sourceBytes = is_string($sourceReal) ? @file_get_contents($sourceReal) : false;
    $expectedSource = $ctx['scratchRoot'] . DIRECTORY_SEPARATOR . 'inputs' . DIRECTORY_SEPARATOR . 'chim_interaction.php';
    $sourceParent = dirname($expectedSource);
    if ($sourcePath !== $expectedSource || is_link($sourceParent) || realpath($sourceParent) !== $sourceParent
        || !is_string($sourceReal) || $sourceReal !== $expectedSource || !is_string($sourceBytes)
        || strlen($sourceBytes) < 256 || strlen($sourceBytes) > 65536
        || !hash_equals($expected, strtolower(hash('sha256', $sourceBytes)))) {
        throw new RuntimeException('interaction_source_pin_mismatch');
    }
    $destination = $ctx['coreRoot'] . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'chim_interaction.php';
    if (is_link($destination) || file_exists($destination) || realpath(dirname($destination)) !== dirname($destination)) {
        throw new RuntimeException('interaction_stage_destination_not_fresh');
    }
    $handle = @fopen($destination, 'x+b');
    if (!is_resource($handle)) throw new RuntimeException('interaction_stage_create_failed');
    try {
        $written = fwrite($handle, $sourceBytes);
        if (!is_int($written) || $written !== strlen($sourceBytes) || !fflush($handle)) {
            throw new RuntimeException('interaction_stage_write_failed');
        }
    } finally { fclose($handle); }
    if (!@chmod($destination, 0644) || is_link($destination) || realpath($destination) !== $destination
        || !hash_equals($expected, strtolower((string)hash_file('sha256', $destination)))) {
        throw new RuntimeException('interaction_stage_verification_failed');
    }
    $ctx['corePins']['lib/chim_interaction.php'] = $expected;
    $ctx['interactionSourceEvidence'] = [
        'source' => $entry['source'], 'sourcePath' => 'inputs/chim_interaction.php', 'sha256' => $expected,
        'hostCommit' => null, 'hostTree' => null, 'destination' => 'lib/chim_interaction.php',
        'copyMode' => 'captured bytes copied into owned scratch core; capture artifact read only',
    ];
}

function dev31_prepare_interaction_fixture(array &$ctx, array $fixture): void
{
    $conf = $ctx['coreRoot'] . DIRECTORY_SEPARATOR . 'conf';
    $interaction = $conf . DIRECTORY_SEPARATOR . 'chim_interaction';
    if (is_link($conf) || is_link($interaction) || (file_exists($conf) && !is_dir($conf))
        || (file_exists($interaction) && !is_dir($interaction))) throw new RuntimeException('interaction_fixture_directory_invalid');
    if (!is_dir($conf) && !@mkdir($conf, 0755)) throw new RuntimeException('interaction_fixture_conf_create_failed');
    $confEntries = @scandir($conf);
    $expectedConfEntries = is_dir($interaction) ? ['.', '..', 'chim_interaction'] : ['.', '..'];
    if (!is_array($confEntries)) throw new RuntimeException('interaction_fixture_conf_unreadable');
    sort($confEntries, SORT_STRING); sort($expectedConfEntries, SORT_STRING);
    if ($confEntries !== $expectedConfEntries) throw new RuntimeException('interaction_fixture_conf_not_scratch_only');
    if (!is_dir($interaction) && !@mkdir($interaction, 02775)) throw new RuntimeException('interaction_fixture_directory_create_failed');
    $entries = @scandir($interaction);
    if (!is_array($entries) || array_values(array_diff($entries, ['.', '..'])) !== []) {
        throw new RuntimeException('interaction_fixture_directory_not_empty');
    }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0
        && (!@chown($interaction, 'www-data') || !@chgrp($interaction, 'www-data'))) throw new RuntimeException('interaction_fixture_directory_owner_failed');
    if (!@chmod($interaction, 02775) || realpath($interaction) !== $interaction) throw new RuntimeException('interaction_fixture_directory_identity_invalid');
    $state = $fixture['initial'] ?? null;
    if (($fixture['path'] ?? null) !== 'conf/chim_interaction/state.json'
        || !is_array($state) || ($state['enabled'] ?? null) !== true || ($state['generation'] ?? null) !== 7
        || ($fixture['ownership'] ?? null) !== 'synthetic state created only beneath the owned per-run scratch core'
        || ($fixture['captureArtifactWrites'] ?? null) !== false) throw new RuntimeException('interaction_fixture_manifest_invalid');
    $ctx['interactionStatePath'] = $interaction . DIRECTORY_SEPARATOR . 'state.json';
    if (file_exists($ctx['interactionStatePath']) || is_link($ctx['interactionStatePath'])) throw new RuntimeException('interaction_fixture_not_fresh');
    dev31_write_interaction_state($ctx, true, 7);
}

function dev31_interaction_trace(array &$ctx, array $child, bool $enabled, int $stateGeneration, bool $allowed): array
{
    $path = $child['traceFile'] ?? null;
    if (!is_string($path) || is_link($path) || !is_file($path)) throw new RuntimeException('interaction_child_trace_missing');
    $raw = @file_get_contents($path);
    $trace = is_string($raw) ? json_decode($raw, true, 32) : null;
    $evidence = is_array($trace) ? ($trace['interactionEvidence'] ?? null) : null;
    $helperPath = $ctx['coreRoot'] . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'chim_interaction.php';
    $expectedHash = $ctx['corePins']['lib/chim_interaction.php'] ?? null;
    if (!is_array($evidence) || !is_array($trace['includedFiles'] ?? null)
        || ($evidence['loadedAtEntry'] ?? null) !== false || ($evidence['loadedAtShutdown'] ?? null) !== true
        || ($evidence['helperPath'] ?? null) !== $helperPath || !is_string($expectedHash)
        || ($evidence['helperSha256'] ?? null) !== $expectedHash
        || !in_array($helperPath, $trace['includedFiles'], true)
        || ($evidence['capturedGeneration'] ?? null) !== 7
        || ($evidence['state'] ?? null) !== ['enabled' => $enabled, 'generation' => $stateGeneration]
        || ($evidence['allowed'] ?? null) !== $allowed) throw new Dev30PgFailure('interaction_cold_boot_trace_invalid');
    dev30_assert(true, 'interaction_cold_boot_trace_invalid');
    $ctx['interactionChildTraces'][] = [
        'caseId' => $child['caseId'] ?? null, 'app' => $child['app'] ?? null,
        'sourceTraceSha256' => strtolower((string)hash_file('sha256', $path)), 'evidence' => $evidence,
    ];
    return $evidence;
}

function dev31_observe_native_activity(array &$ctx, mixed $admin, bool $passive): array
{
    dev30_pg($admin, 'DELETE FROM plugins.correspondence_presence WHERE playthrough_id=$1', ['unprofiled']);
    $helperPath = $ctx['coreRoot'] . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'chim_interaction.php';
    if (!function_exists('chimInteractionState') || !function_exists('chimInteractionAllowed')
        || realpath($helperPath) !== $helperPath
        || !hash_equals($ctx['corePins']['lib/chim_interaction.php'], strtolower((string)hash_file('sha256', $helperPath)))
        || ($GLOBALS['chim_interaction_generation'] ?? null) !== 7) {
        throw new RuntimeException('interaction_owner_hook_helper_not_captured');
    }
    $hadPassive = array_key_exists('HTTP_X_CHIM_PASSIVE', $_SERVER);
    $oldPassive = $_SERVER['HTTP_X_CHIM_PASSIVE'] ?? null;
    if ($passive) $_SERVER['HTTP_X_CHIM_PASSIVE'] = '1';
    else unset($_SERVER['HTTP_X_CHIM_PASSIVE']);
    try {
        $evidence = dev23_companion_with_owner_runtime($ctx, static function ($store) use ($passive): array {
            $scope = $store->scope()['playthrough_id'] ?? null;
            if ($scope !== 'unprofiled') throw new RuntimeException('interaction_fixture_scope_invalid');
            $clock = $store->query('SELECT ts::text AS ts,gamets::text AS gamets FROM public.eventlog ORDER BY rowid DESC LIMIT 1');
            if (count($clock) !== 1 || !is_string($clock[0]['ts'] ?? null) || !is_string($clock[0]['gamets'] ?? null)) {
                throw new RuntimeException('interaction_native_request_clock_missing');
            }
            $request = [0 => 'request', 1 => $clock[0]['ts'], 2 => $clock[0]['gamets']];
            $observed = correspondenceManualDeliveryObserveNativeRequest($request, static fn(): array => ['store' => $store]);
            $rows = $store->query('SELECT payload::text AS payload FROM plugins.correspondence_presence WHERE playthrough_id=$1', [$scope]);
            $payload = count($rows) === 1 && is_string($rows[0]['payload'] ?? null)
                ? json_decode($rows[0]['payload'], true, 32, JSON_THROW_ON_ERROR) : null;
            $activity = is_array($payload) ? ($payload['activity'] ?? null) : null;
            $legacyManagedPresenceAbsent = is_array($payload)
                && !array_key_exists('observed_at', $payload) && !array_key_exists('raw', $payload)
                && !array_key_exists('anchor', $payload);
            if (($observed['status'] ?? null) !== 'observed' || !is_array($payload)
                || array_keys($payload) !== ['activity'] || !is_array($activity) || !$legacyManagedPresenceAbsent) {
                throw new RuntimeException('interaction_native_activity_fixture_invalid');
            }
            $requestHash = hash('sha256', correspondenceCanonicalJson(['request', $clock[0]['ts'], $clock[0]['gamets']]));
            $anchor = $activity['anchor'] ?? null;
            $anchorValid = is_array($anchor) && $store->eventMatches($anchor)
                && ($anchor['type'] ?? null) === 'info' && ($anchor['sess'] ?? null) === 'correspondence_v1'
                && ($anchor['ts'] ?? null) === $clock[0]['ts'] && ($anchor['gamets'] ?? null) === $clock[0]['gamets'];
            $receivedAt = $activity['received_at'] ?? null;
            $activityCurrent = ($activity['playthrough_id'] ?? null) === $scope
                && ($activity['native_type'] ?? null) === 'request'
                && ($activity['native_ts'] ?? null) === $clock[0]['ts']
                && ($activity['gamets'] ?? null) === $clock[0]['gamets']
                && ($activity['request_sha256'] ?? null) === $requestHash
                && ($activity['interaction_generation'] ?? null) === 7
                && is_string($activity['restore_generation'] ?? null) && $activity['restore_generation'] !== ''
                && is_int($receivedAt) && $receivedAt <= time() && time() - $receivedAt <= 45
                && ($activity['active'] ?? null) === !$passive && $anchorValid;
            $executor = $store->observedExecutor();
            $nativeActivityFullyAnchoredCurrent = $activityCurrent
                && ($passive ? $executor === null : $executor === 'The Narrator');
            return [
                'scope' => $scope, 'requestTs' => $clock[0]['ts'], 'gameTs' => $clock[0]['gamets'],
                'producer' => 'correspondenceManualDeliveryObserveNativeRequest',
                'observationStatus' => $observed['status'], 'gameplayObserved' => false,
                'callerFreshnessProof' => 'current anchored owner native request; legacy managed presence absent',
                'legacyManagedPresenceAbsent' => $legacyManagedPresenceAbsent,
                'nativeActivityFullyAnchoredCurrent' => $nativeActivityFullyAnchoredCurrent,
                'passiveProbe' => $passive, 'active' => $activity['active'] ?? null,
                'interactionGeneration' => $activity['interaction_generation'] ?? null,
                'nearbyActorReport' => false, 'nearbyActorCount' => 0,
                'managedExecutorObservation' => false, 'legacyNpcFallback' => false,
                'executor' => $executor,
            ];
        });
        $expectedExecutor = $passive ? null : 'The Narrator';
        if (($evidence['executor'] ?? null) !== $expectedExecutor || ($evidence['active'] ?? null) !== !$passive
            || ($evidence['interactionGeneration'] ?? null) !== 7
            || ($evidence['legacyManagedPresenceAbsent'] ?? null) !== true
            || ($evidence['nativeActivityFullyAnchoredCurrent'] ?? null) !== true
            || ($evidence['nearbyActorCount'] ?? null) !== 0 || ($evidence['managedExecutorObservation'] ?? null) !== false) {
            throw new Dev30PgFailure('interaction_owner_observation_invalid');
        }
        $ctx['nativeActivityFixtures'][] = $evidence;
        return $evidence;
    } finally {
        if ($hadPassive) $_SERVER['HTTP_X_CHIM_PASSIVE'] = $oldPassive;
        else unset($_SERVER['HTTP_X_CHIM_PASSIVE']);
    }
}

function dev31_compose_saved_500(array &$ctx, mixed $admin, string $caseId): array
{
    $requestId = dev30_uuid();
    $compose = dev30_api($ctx, $caseId, 'correspondence.compose', [
        'operationId' => $requestId, 'writerActorKey' => 'player', 'signatureActorKey' => 'player',
        'recipientActorKey' => 'npc:900003', 'body' => 'A synthetic saved letter carrying five hundred gold.',
        'inventoryRevision' => 'dev31-zero-nearby-npc-500-gold', 'attachments' => [], 'goldAmount' => 500,
    ], ['operationRequestId' => $requestId, 'authScopes' => ['compose']]);
    $response = dev30_response($compose);
    $document = dev30_document($admin, $requestId);
    $gold = array_values(array_filter($document['payload']['attachments'] ?? [], static fn(array $item): bool => ($item['kind'] ?? null) === 'gold'));
    dev30_assert(($response['ok'] ?? null) === true && ($response['data']['operationId'] ?? null) === $requestId
        && ($response['data']['state'] ?? null) === 'queued' && ($response['data']['canResume'] ?? null) === true
        && ($document['payload']['playthrough_id'] ?? null) === 'unprofiled'
        && count($gold) === 1 && ($gold[0]['quantity'] ?? null) === 500,
        'saved_500_gold_letter_not_queued_in_ordinary_chim_scope');
    dev30_assert(dev30_delivery_counts($admin, $document['id']) === ['documents' => 1, 'operations' => 0, 'commands' => 0],
        'saved_500_gold_letter_dispatched_before_explicit_resume');
    return ['documentId' => $document['id'], 'requestId' => $requestId, 'payload' => $document['payload']];
}

function dev31_queued_letter_with_activity(array &$ctx, mixed $admin, string $caseId, bool $passive = false): array
{
    dev31_write_interaction_state($ctx, true, 7);
    dev30_pg($admin, 'DELETE FROM plugins.correspondence_presence WHERE playthrough_id=$1', ['unprofiled']);
    dev30_seed_case_clock($admin);
    $letter = dev31_compose_saved_500($ctx, $admin, $caseId . '_compose');
    $observation = dev31_observe_native_activity($ctx, $admin, $passive);
    return ['letter' => $letter, 'observation' => $observation];
}

function dev31_assert_unchanged_queued_letter(mixed $admin, array $letter, array $before): void
{
    dev30_assert($before === dev30_delivery_counts($admin, $letter['documentId'])
        && $before === ['documents' => 1, 'operations' => 0, 'commands' => 0], 'native_refusal_mutated_saved_letter_or_created_operation');
    $document = dev30_document($admin, $letter['requestId']);
    $gold = array_values(array_filter($document['payload']['attachments'] ?? [], static fn(array $item): bool => ($item['kind'] ?? null) === 'gold'));
    dev30_assert($document['id'] === $letter['documentId'] && ($document['payload']['operation_id'] ?? null) === null
        && $document['payload'] === ($letter['payload'] ?? null)
        && count($gold) === 1 && ($gold[0]['quantity'] ?? null) === 500, 'native_refusal_changed_saved_500_document');
}

function dev31_assert_executor_unavailable_response(array $result, array $letter): array
{
    $response = dev30_response($result);
    dev30_assert(($response['ok'] ?? null) === true && ($response['data']['state'] ?? null) === 'queued'
        && ($response['data']['queuedReason'] ?? null) === 'executor_unavailable'
        && ($response['data']['canResume'] ?? null) === true
        && ($response['data']['operationId'] ?? null) === $letter['requestId'],
        'native_context_refusal_not_executor_unavailable');
    return $response;
}

function dev31_restore_preflight_trace(array &$ctx, array $child, string $variant): array
{
    $path = $child['traceFile'] ?? null;
    if (!is_string($path) || is_link($path) || !is_file($path)) throw new RuntimeException('interaction_child_trace_missing');
    $raw = @file_get_contents($path);
    $trace = is_string($raw) ? json_decode($raw, true, 32) : null;
    $evidence = is_array($trace) ? ($trace['interactionEvidence'] ?? null) : null;
    $helperPath = $ctx['coreRoot'] . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'chim_interaction.php';
    $expectedHash = $ctx['corePins']['lib/chim_interaction.php'] ?? null;
    if (!is_array($evidence) || !is_array($trace['includedFiles'] ?? null)
        || ($evidence['loadedAtEntry'] ?? null) !== false || ($evidence['helperPath'] ?? null) !== $helperPath
        || !is_string($expectedHash) || ($evidence['helperSha256'] ?? null) !== $expectedHash) {
        throw new Dev30PgFailure('restore_preflight_trace_invalid');
    }
    $loaded = ($evidence['loadedAtShutdown'] ?? null) === true;
    if (($loaded && !in_array($helperPath, $trace['includedFiles'], true))
        || (!$loaded && in_array($helperPath, $trace['includedFiles'], true))) {
        throw new Dev30PgFailure('restore_preflight_helper_boundary_invalid');
    }
    $boundary = $loaded ? 'owner_bootstrap_entered_before_restore_refusal' : 'adapter_restore_preflight_before_owner_bootstrap';
    $record = [
        'caseId' => $child['caseId'] ?? null, 'variant' => $variant, 'boundary' => $boundary,
        'sourceTraceSha256' => strtolower((string)hash_file('sha256', $path)), 'evidence' => $evidence,
    ];
    $ctx['interactionChildTraces'][] = $record;
    return $record;
}

function dev31_assert_expired_activity_anchor(mixed $admin, int $minimumAge): array
{
    $rows = dev30_pg($admin,
        'SELECT payload::text AS payload FROM plugins.correspondence_presence WHERE playthrough_id=$1', ['unprofiled']);
    if (count($rows) !== 1 || !is_string($rows[0]['payload'] ?? null)) throw new RuntimeException('expired_native_activity_missing');
    $payload = json_decode($rows[0]['payload'], true, 32, JSON_THROW_ON_ERROR);
    $activity = is_array($payload) ? ($payload['activity'] ?? null) : null;
    $anchor = is_array($activity) ? ($activity['anchor'] ?? null) : null;
    $receivedAt = is_array($activity) ? ($activity['received_at'] ?? null) : null;
    if (!is_array($payload) || array_keys($payload) !== ['activity'] || !is_array($activity) || !is_array($anchor)
        || ($activity['active'] ?? null) !== true || ($activity['interaction_generation'] ?? null) !== 7
        || !is_int($receivedAt) || time() - $receivedAt < $minimumAge
        || ($anchor['type'] ?? null) !== 'info' || ($anchor['sess'] ?? null) !== 'correspondence_v1') {
        throw new Dev30PgFailure('expired_native_activity_fixture_invalid');
    }
    $eventRows = dev30_pg($admin,
        'SELECT rowid::text AS rowid,ts::text AS ts,gamets::text AS gamets,type,data,sess,localts::text AS localts,people '
            . 'FROM public.eventlog WHERE rowid=$1::bigint', [(string)($anchor['rowid'] ?? '')]);
    if (count($eventRows) !== 1) throw new Dev30PgFailure('expired_activity_anchor_event_missing');
    $event = $eventRows[0];
    $expectedFields = array_intersect_key($activity, array_flip([
        'playthrough_id','native_type','native_ts','gamets','request_sha256','interaction_generation',
        'restore_generation','received_at','active',
    ]));
    $expectedData = ['kind' => 'manual_delivery_activity'] + $expectedFields;
    $anchorData = json_decode((string)($event['data'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($anchorData)) throw new Dev30PgFailure('expired_activity_anchor_data_invalid');
    ksort($anchorData, SORT_STRING); ksort($expectedData, SORT_STRING);
    if ((string)($event['rowid'] ?? '') !== (string)($anchor['rowid'] ?? '')
        || ($event['ts'] ?? null) !== ($anchor['ts'] ?? null) || ($event['gamets'] ?? null) !== ($anchor['gamets'] ?? null)
        || ($event['type'] ?? null) !== ($anchor['type'] ?? null) || ($event['data'] ?? null) !== ($anchor['data'] ?? null)
        || ($event['sess'] ?? null) !== ($anchor['sess'] ?? null) || ($event['localts'] ?? null) !== ($anchor['localts'] ?? null)
        || ($event['people'] ?? null) !== ($anchor['people'] ?? null) || $anchorData !== $expectedData) {
        throw new Dev30PgFailure('expired_activity_anchor_changed');
    }
    return [
        'requestedWaitSeconds' => $minimumAge, 'observedAgeSeconds' => time() - $receivedAt,
        'activityGeneration' => 7, 'activityActive' => true, 'anchorRowId' => (string)$anchor['rowid'],
        'anchorDataSha256' => strtolower(hash('sha256', (string)$event['data'])),
        'presencePayload' => $rows[0]['payload'], 'anchorEvent' => $event,
        'anchorMatchesUnmodifiedActivity' => true,
    ];
}

function dev31_assert_dispatch_generation(mixed $admin, string $documentId, array &$ctx): void
{
    $delivery = dev30_pg($admin,
        "SELECT action,text,interaction_generation::text AS interaction_generation FROM public.responselog "
            . "WHERE action='command' AND text LIKE '%ExtCmdCHIMCorrespondenceBridge_Deliver@%' AND text LIKE $1",
        ['%' . $documentId . '%']);
    dev30_assert(count($delivery) === 1 && ($delivery[0]['interaction_generation'] ?? null) === '7',
        'cold_resume_delivery_generation_not_seven');
    $renderer = dev30_pg($admin,
        "SELECT action,text,interaction_generation::text AS interaction_generation FROM public.responselog "
            . "WHERE action='rolecommand' AND text=$1", ['generateLetter@letter ' . $documentId]);
    if ($renderer !== []) {
        dev30_assert(count($renderer) === 1 && ($renderer[0]['interaction_generation'] ?? null) === '7',
            'cold_resume_renderer_generation_not_seven');
    }
    $ctx['nativeDispatchGenerationChecks'][] = [
        'documentId' => $documentId, 'deliveryRows' => count($delivery), 'deliveryGeneration' => '7',
        'rendererRows' => count($renderer), 'rendererGeneration' => $renderer === [] ? null : '7',
    ];
}

function dev31_release_interaction_barrier(string $path): void
{
    if (is_link($path) || file_exists($path)) throw new RuntimeException('interaction_release_not_fresh');
    $handle = @fopen($path, 'x+b');
    if (!is_resource($handle)) throw new RuntimeException('interaction_release_create_failed');
    try {
        if (fwrite($handle, "continue\n") !== 9 || !fflush($handle)) throw new RuntimeException('interaction_release_write_failed');
    } finally { fclose($handle); }
    if (function_exists('posix_geteuid') && posix_geteuid() === 0
        && (!@chown($path, 'www-data') || !@chgrp($path, 'www-data'))) throw new RuntimeException('interaction_release_owner_failed');
    if (!@chmod($path, 0660)) throw new RuntimeException('interaction_release_mode_failed');
}

function dev31_generation_change_api(array &$ctx, string $caseId, string $requestId, string $attemptId, bool $enabled, int $generation): array
{
    $offset = @filesize($ctx['pgLogPath']);
    if (!is_int($offset)) throw new RuntimeException('postgres_log_unreadable');
    $script = $ctx['dev31TestRoot'] . DIRECTORY_SEPARATOR . 'generation_change_driver.php';
    $child = dev30_start_child($ctx, $caseId, $script, [], null, 'correspondence.resume',
        ['operationId' => $requestId, 'attemptId' => $attemptId], ['operationRequestId' => $attemptId, 'authScopes' => ['compose']]);
    $ready = $child['traceFile'] . '.interaction-captured';
    $release = $child['traceFile'] . '.interaction-release';
    $deadline = microtime(true) + 45;
    while (!is_file($ready)) {
        if (is_link($ready) || microtime(true) >= $deadline) {
            dev31_release_interaction_barrier($release);
            try { dev30_finish_child($ctx, $child); } catch (Throwable) {}
            throw new Dev30PgFailure('interaction_capture_barrier_timeout');
        }
        usleep(10000);
    }
    if (is_link($ready) || file_get_contents($ready) !== "captured-generation-7\n") {
        dev31_release_interaction_barrier($release);
        try { dev30_finish_child($ctx, $child); } catch (Throwable) {}
        throw new RuntimeException('interaction_capture_barrier_invalid');
    }
    dev31_write_interaction_state($ctx, $enabled, $generation);
    dev31_release_interaction_barrier($release);
    return dev30_api_result($ctx, 'correspondence.resume', $offset, dev30_finish_child($ctx, $child));
}

function dev30_document(mixed $admin, string $requestId): array
{
    $rows = dev30_pg($admin,
        'SELECT document_id::text AS document_id,payload::text AS payload FROM plugins.correspondence_documents WHERE request_id=$1::uuid',
        [$requestId]);
    if (count($rows) !== 1 || !is_string($rows[0]['payload'] ?? null)) throw new RuntimeException('owner_document_missing');
    $payload = json_decode($rows[0]['payload'], true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || ($payload['document_id'] ?? null) !== $rows[0]['document_id']) throw new RuntimeException('owner_document_invalid');
    return ['id' => $rows[0]['document_id'], 'payload' => $payload];
}

function dev30_trace_helper(array $ctx): array
{
    $path = $ctx['scratchRoot'] . DIRECTORY_SEPARATOR . 'source-trace-prepend.php';
    if (is_link($path)) throw new RuntimeException('trace_helper_redirected');
    $source = <<<'PHP'
<?php
$trace = getenv('QD_SOURCE_TRACE_FILE');
$markerPath = getenv('QD_DEV20_FIXTURE_MARKER');
$coreRoot = getenv('QD_CHIM_ROOT');
$expectedInteractionHash = getenv('QD_DEV31_INTERACTION_SHA256');
$interactionLoadedAtEntry = function_exists('chimInteractionState');
$interactionHelperPath = is_string($coreRoot) ? $coreRoot . '/lib/chim_interaction.php' : '';
$interactionActualHash = $interactionHelperPath !== '' && !is_link($interactionHelperPath) && is_file($interactionHelperPath)
    ? @hash_file('sha256', $interactionHelperPath) : false;
$interactionPathValid = $interactionHelperPath !== '' && realpath($interactionHelperPath) === $interactionHelperPath;
if ($expectedInteractionHash !== false && (!is_string($expectedInteractionHash)
    || preg_match('/\A[a-f0-9]{64}\z/D', $expectedInteractionHash) !== 1
    || !is_string($interactionActualHash) || !hash_equals($expectedInteractionHash, strtolower($interactionActualHash))
    || !$interactionPathValid)) {
    throw new RuntimeException('child_interaction_helper_boundary_invalid');
}
$markerRaw = is_string($markerPath) && $markerPath !== '' ? @file_get_contents($markerPath) : false;
$marker = is_string($markerRaw) ? json_decode($markerRaw, true, 8) : null;
$networkNamespace = @readlink('/proc/self/ns/net');
$netInterfaces = function_exists('net_get_interfaces') ? net_get_interfaces() : false;
$interfaceEvidence = null;
if (is_array($netInterfaces)) {
    $interfaceNames = array_keys($netInterfaces);
    sort($interfaceNames, SORT_STRING);
    $loopback = $netInterfaces['lo'] ?? null;
    if ($interfaceNames === ['lo'] && is_array($loopback) && ($loopback['up'] ?? null) === true
        && is_array($loopback['unicast'] ?? null) && array_is_list($loopback['unicast']) && count($loopback['unicast']) === 3) {
        $familyFlags = []; $commonFlags = null; $validInterface = true;
        foreach ($loopback['unicast'] as $entry) {
            if (!is_array($entry) || !is_int($entry['family'] ?? null) || !is_int($entry['flags'] ?? null)
                || ($entry['flags'] & 0x9) !== 0x9 || isset($familyFlags[$entry['family']])) { $validInterface = false; break; }
            $family = $entry['family'];
            if ($family === 17) {
                if (array_key_exists('address', $entry)) { $validInterface = false; break; }
            } elseif ($family === 2) {
                if (($entry['address'] ?? null) !== '127.0.0.1') { $validInterface = false; break; }
            } elseif ($family === 10) {
                if (($entry['address'] ?? null) !== '::1') { $validInterface = false; break; }
            } else { $validInterface = false; break; }
            $familyFlags[$family] = $entry['flags'];
            $commonFlags = $commonFlags === null ? $entry['flags'] : ($commonFlags & $entry['flags']);
        }
        $familyNumbers = array_keys($familyFlags);
        sort($familyNumbers, SORT_NUMERIC);
        if ($validInterface && $familyNumbers === [2, 10, 17] && is_int($commonFlags)) {
            $interfaceEvidence = ['interfaces' => $interfaceNames, 'loopbackFlags' => sprintf('0x%x', $commonFlags)];
        }
    }
}
$ipv4Routes = @file_get_contents('/proc/net/route');
$ipv6Routes = @file_get_contents('/proc/net/ipv6_route');
$expectedIpv4Hash = getenv('QD_DEV30_IPV4_ROUTE_SHA256');
$expectedIpv6Hash = getenv('QD_DEV30_IPV6_ROUTE_SHA256');
$networkEvidence = [
    'networkNamespace' => $networkNamespace,
    'markerNetworkNamespace' => is_array($marker) ? ($marker['networkNamespace'] ?? null) : null,
    'initialNetworkNamespace' => is_array($marker) ? ($marker['initialNetworkNamespace'] ?? null) : null,
    'namespaceMatchesMarker' => is_array($marker) && is_string($networkNamespace) && $networkNamespace === ($marker['networkNamespace'] ?? null),
    'interfaces' => is_array($interfaceEvidence) ? $interfaceEvidence['interfaces'] : [],
    'loopbackFlags' => is_array($interfaceEvidence) ? $interfaceEvidence['loopbackFlags'] : null,
    'interfaceEvidenceValid' => is_array($interfaceEvidence),
    'ipv4RouteSha256' => is_string($ipv4Routes) ? hash('sha256', $ipv4Routes) : null,
    'ipv6RouteSha256' => is_string($ipv6Routes) ? hash('sha256', $ipv6Routes) : null,
];
if (is_string($trace) && $trace !== '') {
    register_shutdown_function(static function () use ($trace, $networkEvidence, $coreRoot, $expectedInteractionHash,
        $interactionLoadedAtEntry, $interactionHelperPath, $interactionActualHash, $interactionPathValid): void {
        $files = get_included_files();
        sort($files, SORT_STRING);
        $traceData = ['includedFiles' => $files, 'networkEvidence' => $networkEvidence];
        if (is_string($expectedInteractionHash)) {
            $loadedAtShutdown = function_exists('chimInteractionState');
            $capturedGeneration = $GLOBALS['chim_interaction_generation'] ?? null;
            $interactionState = null;
            $interactionAllowed = null;
            if ($loadedAtShutdown) {
                try { $interactionState = chimInteractionState(); } catch (Throwable) {}
                if (is_int($capturedGeneration)) {
                    try { $interactionAllowed = chimInteractionAllowed(); } catch (Throwable) {}
                }
            }
            $traceData['interactionEvidence'] = [
                'loadedAtEntry' => $interactionLoadedAtEntry,
                'loadedAtShutdown' => $loadedAtShutdown,
                'helperPath' => $interactionPathValid ? $interactionHelperPath : null,
                'helperSha256' => is_string($interactionActualHash) ? strtolower($interactionActualHash) : null,
                'capturedGeneration' => $capturedGeneration,
                'state' => $interactionState,
                'allowed' => $interactionAllowed,
            ];
        }
        file_put_contents($trace, json_encode($traceData, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
    });
}
if (!is_array($marker) || !is_string($networkNamespace) || $networkNamespace !== ($marker['networkNamespace'] ?? null)
    || $networkNamespace !== getenv('QD_DEV30_NETNS_ID') || $networkNamespace === ($marker['initialNetworkNamespace'] ?? null)
    || !is_array($interfaceEvidence) || $interfaceEvidence['interfaces'] !== ['lo']
    || preg_match('/\A0x([a-f0-9]+)\z/iD', $interfaceEvidence['loopbackFlags'], $interfaceFlagMatch) !== 1
    || (((int)hexdec($interfaceFlagMatch[1])) & 0x9) !== 0x9
    || !is_string($ipv4Routes) || !is_string($ipv6Routes)
    || !is_string($expectedIpv4Hash) || !hash_equals($expectedIpv4Hash, hash('sha256', $ipv4Routes))
    || !is_string($expectedIpv6Hash) || !hash_equals($expectedIpv6Hash, hash('sha256', $ipv6Routes))) {
    throw new RuntimeException('child_network_boundary_invalid');
}
$expectedLoggerHash = getenv('QD_DEV30_LOGGER_SHA256');
$loggerPath = is_string($coreRoot) ? $coreRoot . '/lib/logger.php' : '';
$customLogPath = getenv('QD_DEV30_CUSTOM_LOG');
$expectedCustomLog = is_string($coreRoot) ? $coreRoot . '/log/playthrough_runtime/fixture-chim.log' : '';
$actualLoggerHash = $loggerPath !== '' && !is_link($loggerPath) && is_file($loggerPath) ? @hash_file('sha256', $loggerPath) : false;
if (!is_string($coreRoot) || $coreRoot === '' || realpath($coreRoot) !== $coreRoot
    || !is_string($expectedLoggerHash) || preg_match('/\A[a-f0-9]{64}\z/D', $expectedLoggerHash) !== 1
    || !is_string($actualLoggerHash) || !hash_equals($expectedLoggerHash, strtolower($actualLoggerHash))
    || realpath($loggerPath) !== $loggerPath
    || !is_string($customLogPath) || $customLogPath !== $expectedCustomLog || is_link($customLogPath) || !is_file($customLogPath)
    || !is_writable($customLogPath) || realpath(dirname($customLogPath)) !== dirname($customLogPath)) {
    throw new RuntimeException('child_logger_boundary_invalid');
}
require_once $loggerPath;
if (!class_exists('Logger', false) || !is_callable(['Logger', 'setCustomLog'])) throw new RuntimeException('child_logger_unavailable');
Logger::setCustomLog($customLogPath);
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!empty($GLOBALS['pgr_operation']) && str_contains($errstr, 'pg_')) $GLOBALS['pgr_sql_failed'] = true;
    return false;
});
PHP;
    if (!is_file($path) && file_put_contents($path, $source . "\n", LOCK_EX) === false) throw new RuntimeException('trace_helper_write_failed');
    if (!is_file($path) || !@chmod($path, 0644)) throw new RuntimeException('trace_helper_mode_failed');
    $traceDir = $ctx['scratchRoot'] . DIRECTORY_SEPARATOR . 'source-load';
    if (!is_dir($traceDir) && !@mkdir($traceDir, 0770)) throw new RuntimeException('trace_directory_create_failed');
    if (function_exists('posix_geteuid') && posix_geteuid() === 0
        && (!@chown($traceDir, 'www-data') || !@chgrp($traceDir, 'www-data'))) throw new RuntimeException('trace_directory_owner_failed');
    if (!@chmod($traceDir, 0770)) throw new RuntimeException('trace_directory_mode_failed');
    return ['path' => $path, 'sha256' => strtolower((string)hash_file('sha256', $path)), 'directory' => $traceDir];
}

function dev30_child_php_args(string $php, string $traceHelper): array
{
    $args = [$php, '-n'];
    foreach (['posix', 'ctype', 'tokenizer', 'mbstring', 'pgsql', 'gd'] as $extension) {
        array_push($args, '-d', 'extension=' . $extension);
    }
    array_push($args, '-d', 'display_errors=stderr');
    array_push($args, '-d', 'auto_prepend_file=' . $traceHelper);
    return $args;
}

function dev30_start_child(array $ctx, string $caseId, string $script, array $scriptArgs = [], ?string $stdin = null, ?string $apiOperation = null, array $apiPayload = [], array $apiOptions = []): array
{
    $testRoot = $ctx['legacyTestRoot'];
    $dev31Driver = ($ctx['dev31TestRoot'] ?? '') . DIRECTORY_SEPARATOR . 'generation_change_driver.php';
    if (($ctx['suite'] ?? 'dev30') === 'dev31' && realpath($script) === $dev31Driver) $testRoot = $ctx['dev31TestRoot'];
    $script = dev30_require_file($script, $testRoot);
    $app = 'dev30-' . substr(hash('sha256', $ctx['runId'] . ':' . $caseId . ':' . bin2hex(random_bytes(6))), 0, 20);
    $traceFile = $ctx['trace']['directory'] . DIRECTORY_SEPARATOR . $app . '.json';
    $env = [
        'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME' => '/tmp', 'LC_ALL' => 'C', 'TZ' => 'UTC',
        'QD_CHIM_ROOT' => $ctx['coreRoot'], 'QD_DEV20_RUN_ID' => $ctx['runId'],
        'QD_DEV20_SCRATCH_ROOT' => $ctx['scratchRoot'], 'QD_DEV20_FIXTURE_MARKER' => $ctx['fixtureMarker'],
        'QD_DEV30_NETNS_ID' => $ctx['networkEvidence']['networkNamespace'],
        'QD_DEV30_IPV4_ROUTE_SHA256' => $ctx['networkEvidence']['ipv4RouteSha256'],
        'QD_DEV30_IPV6_ROUTE_SHA256' => $ctx['networkEvidence']['ipv6RouteSha256'],
        'QD_DEV30_LOGGER_SHA256' => $ctx['corePins']['lib/logger.php'],
        'QD_DEV30_CUSTOM_LOG' => $ctx['fixtureLogPath'],
        'QD_SOURCE_TRACE_FILE' => $traceFile, 'PGAPPNAME' => $app,
    ];
    if (($ctx['suite'] ?? 'dev30') === 'dev31') {
        $env['QD_DEV31_INTERACTION_SHA256'] = $ctx['corePins']['lib/chim_interaction.php'];
    }
    $runuser = $ctx['runuser'];
    $command = [$runuser, '-u', 'www-data', '--', '/usr/bin/env', '-i'];
    foreach ($env as $name => $value) $command[] = $name . '=' . $value;
    $command = array_merge($command, dev30_child_php_args($ctx['php'], $ctx['trace']['path']), [$script]);
    if ($apiOperation !== null) {
        $fixtureRoot = $ctx['apiFixtureParent'] . DIRECTORY_SEPARATOR . $caseId;
        $request = ['caseId' => $caseId, 'operation' => $apiOperation, 'payload' => (object)$apiPayload] + $apiOptions;
        $stdin = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        array_push($command, '--fixture-root', $fixtureRoot);
    } else {
        foreach ($scriptArgs as $argument) $command[] = (string)$argument;
    }
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = @proc_open($command, $descriptors, $pipes, null, [], ['bypass_shell' => true]);
    if (!is_resource($process)) throw new RuntimeException('child_process_start_failed');
    if ($stdin !== null && $stdin !== '') {
        if (fwrite($pipes[0], $stdin) !== strlen($stdin)) {
            @proc_terminate($process, 9);
            throw new RuntimeException('child_input_write_failed');
        }
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    return ['process' => $process, 'pipes' => $pipes, 'app' => $app, 'traceFile' => $traceFile,
        'caseId' => $caseId, 'script' => $script, 'deadline' => microtime(true) + 100,
        'timedOut' => false, 'observedExit' => null, 'stdout' => '', 'stderr' => ''];
}

function dev30_finish_child(array &$ctx, array $child): array
{
    $process = $child['process'] ?? null;
    $pipes = $child['pipes'] ?? null;
    if (!is_resource($process) || !is_array($pipes) || !is_resource($pipes[1] ?? null) || !is_resource($pipes[2] ?? null)) {
        throw new RuntimeException('child_process_handle_invalid');
    }
    $app = (string)$child['app']; $traceFile = (string)$child['traceFile'];
    $caseId = (string)$child['caseId']; $script = (string)$child['script'];
    $stdout = (string)($child['stdout'] ?? ''); $stderr = (string)($child['stderr'] ?? '');
    $deadline = (float)($child['deadline'] ?? (microtime(true) + 100));
    $timedOut = (bool)($child['timedOut'] ?? false); $observedExit = $child['observedExit'] ?? null;
    do {
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!($status['running'] ?? false)) {
            $observedExit = is_int($status['exitcode'] ?? null) ? $status['exitcode'] : null;
            break;
        }
        if (microtime(true) >= $deadline) { $timedOut = true; @proc_terminate($process, 15); usleep(100000); @proc_terminate($process, 9); break; }
        usleep(20000);
    } while (true);
    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $closed = proc_close($process);
    if ((!is_int($closed) || $closed < 0) && is_int($observedExit)) $closed = $observedExit;
    $result = ['exitCode' => $closed, 'timedOut' => $timedOut, 'stdout' => $stdout, 'stderr' => $stderr, 'app' => $app,
        'traceFile' => $traceFile, 'caseId' => $caseId, 'script' => $script];
    $caseDir = dev30_private_dir($ctx['artifactDir'] . DIRECTORY_SEPARATOR . 'processes' . DIRECTORY_SEPARATOR . $caseId, 0700);
    file_put_contents($caseDir . DIRECTORY_SEPARATOR . 'stdout.raw', $stdout, LOCK_EX);
    file_put_contents($caseDir . DIRECTORY_SEPARATOR . 'stderr.raw', $stderr, LOCK_EX);
    @chmod($caseDir . DIRECTORY_SEPARATOR . 'stdout.raw', 0600);
    @chmod($caseDir . DIRECTORY_SEPARATOR . 'stderr.raw', 0600);
    $traceExists = file_exists($traceFile) || is_link($traceFile);
    if (!$traceExists) throw new Dev30PgFailure('child_source_trace_missing');
    if (is_link($traceFile) || !is_file($traceFile)) throw new Dev30PgFailure('child_source_trace_invalid');
    $traceRaw = file_get_contents($traceFile);
    if (!is_string($traceRaw)) throw new Dev30PgFailure('child_source_trace_read_failed');
    $preservedTracePath = null;
    $traceSha256 = null;
    if (is_string($traceRaw)) {
        $preservedTracePath = $caseDir . DIRECTORY_SEPARATOR . 'source-trace.raw.json';
        $traceSha256 = strtolower(hash('sha256', $traceRaw));
        if (file_exists($preservedTracePath) || is_link($preservedTracePath)) throw new Dev30PgFailure('child_source_trace_not_fresh');
        $traceWritten = file_put_contents($preservedTracePath, $traceRaw, LOCK_EX);
        if (!is_int($traceWritten) || $traceWritten !== strlen($traceRaw) || !is_file($preservedTracePath)
            || is_link($preservedTracePath) || !@chmod($preservedTracePath, 0600)) {
            throw new Dev30PgFailure('child_source_trace_preserve_failed');
        }
        $preservedTraceHash = hash_file('sha256', $preservedTracePath);
        if (!is_string($preservedTraceHash) || !hash_equals($traceSha256, strtolower($preservedTraceHash))) {
            throw new Dev30PgFailure('child_source_trace_hash_mismatch');
        }
    }
    $trace = is_string($traceRaw) ? json_decode($traceRaw, true) : null;
    $childNetwork = is_array($trace) && is_array($trace['networkEvidence'] ?? null) ? $trace['networkEvidence'] : [];
    $ctx['processes'][] = [
        'caseId' => $caseId, 'app' => $app, 'script' => $script,
        'tracePath' => $preservedTracePath,
        'traceSha256' => $traceSha256,
        'includedFiles' => is_array($trace) && is_array($trace['includedFiles'] ?? null) ? $trace['includedFiles'] : [],
        'networkEvidence' => $childNetwork,
        'stdoutSha256' => hash('sha256', $stdout), 'stderrSha256' => hash('sha256', $stderr),
    ];
    if (is_array($trace['interactionEvidence'] ?? null)) {
        $ctx['processes'][array_key_last($ctx['processes'])]['interactionEvidence'] = $trace['interactionEvidence'];
    }
    $childFlags = preg_match('/\A0x([a-f0-9]+)\z/iD', (string)($childNetwork['loopbackFlags'] ?? ''), $childFlagMatch) === 1
        ? (int)hexdec($childFlagMatch[1]) : 0;
    if (($childNetwork['namespaceMatchesMarker'] ?? null) !== true
        || ($childNetwork['networkNamespace'] ?? null) !== $ctx['networkEvidence']['networkNamespace']
        || ($childNetwork['markerNetworkNamespace'] ?? null) !== $ctx['networkEvidence']['networkNamespace']
        || ($childNetwork['initialNetworkNamespace'] ?? null) !== $ctx['networkEvidence']['initialNetworkNamespace']
        || ($childNetwork['interfaceEvidenceValid'] ?? null) !== true
        || ($childNetwork['interfaces'] ?? null) !== ['lo'] || ($childFlags & 0x9) !== 0x9
        || ($childNetwork['loopbackFlags'] ?? null) !== $ctx['networkEvidence']['loopbackFlags']
        || ($childNetwork['ipv4RouteSha256'] ?? null) !== $ctx['networkEvidence']['ipv4RouteSha256']
        || ($childNetwork['ipv6RouteSha256'] ?? null) !== $ctx['networkEvidence']['ipv6RouteSha256']) {
        throw new Dev30PgFailure('child_network_evidence_invalid');
    }
    if ($timedOut || $closed !== 0) throw new Dev30PgFailure('child_process_failed');
    return $result;
}

function dev30_run_child(array &$ctx, string $caseId, string $script, array $scriptArgs = [], ?string $stdin = null, ?string $apiOperation = null, array $apiPayload = [], array $apiOptions = []): array
{
    return dev30_finish_child($ctx, dev30_start_child($ctx, $caseId, $script, $scriptArgs, $stdin, $apiOperation, $apiPayload, $apiOptions));
}

function dev30_api_result(array &$ctx, string $operation, int $offset, array $result): array
{
    $rows = [];
    foreach (preg_split('/\r?\n/', trim($result['stdout'])) ?: [] as $line) {
        if ($line === '') continue;
        $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        if (is_array($row)) $rows[] = $row;
    }
    $result['responses'] = $rows;
    $result['sqlOffset'] = $offset;
    $result['readFence'] = $operation === 'correspondence.resume' ? null : dev30_read_fence($ctx, $result['app'], $offset);
    $result['queryEvidence'] = dev30_query_evidence($ctx, $result['app'], $offset);
    return $result;
}

function dev30_api(array &$ctx, string $caseId, string $operation, array $payload, array $options = []): array
{
    $offset = @filesize($ctx['pgLogPath']);
    if (!is_int($offset)) throw new RuntimeException('postgres_log_unreadable');
    $result = dev30_run_child($ctx, $caseId, $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'api_driver.php', [], null, $operation, $payload, $options);
    return dev30_api_result($ctx, $operation, $offset, $result);
}

function dev30_wait_for_api_pause(array $ctx, string $caseId): string
{
    $control = $ctx['apiFixtureParent'] . DIRECTORY_SEPARATOR . $caseId . DIRECTORY_SEPARATOR . 'control';
    $marker = $control . DIRECTORY_SEPARATOR . 'profile-lease-held';
    if (is_link($control) || is_link($marker)) throw new RuntimeException('api_pause_marker_redirected');
    $deadline = microtime(true) + 45;
    while (!is_file($marker)) {
        if (microtime(true) >= $deadline) throw new Dev30PgFailure('api_pause_barrier_timeout');
        usleep(10000);
    }
    if (file_get_contents($marker) !== "lease-held\n") throw new RuntimeException('api_pause_marker_invalid');
    return $control . DIRECTORY_SEPARATOR . 'continue-request';
}

function dev30_release_api_pause(string $release): void
{
    if (is_link($release) || file_exists($release)) throw new RuntimeException('api_pause_release_not_fresh');
    $handle = @fopen($release, 'x+b');
    if (!is_resource($handle)) throw new RuntimeException('api_pause_release_create_failed');
    try {
        if (fwrite($handle, "continue\n") !== 9 || !fflush($handle)) throw new RuntimeException('api_pause_release_write_failed');
    } finally { fclose($handle); }
    if (posix_geteuid() === 0 && (!@chown($release, 'www-data') || !@chgrp($release, 'www-data'))) throw new RuntimeException('api_pause_release_owner_failed');
    if (!@chmod($release, 0660)) throw new RuntimeException('api_pause_release_mode_failed');
}

function dev30_wait_for_pending_page(array $ctx, string $caseId): string
{
    $control = $ctx['apiFixtureParent'] . DIRECTORY_SEPARATOR . $caseId . DIRECTORY_SEPARATOR . 'control';
    $marker = $control . DIRECTORY_SEPARATOR . 'pending-page1-ready';
    if (is_link($control) || is_link($marker)) throw new RuntimeException('pending_page_marker_redirected');
    $deadline = microtime(true) + 45;
    while (!is_file($marker)) {
        if (microtime(true) >= $deadline) throw new Dev30PgFailure('pending_page_barrier_timeout');
        usleep(10000);
    }
    if (file_get_contents($marker) !== "page1-ready\n") throw new RuntimeException('pending_page_marker_invalid');
    return $control . DIRECTORY_SEPARATOR . 'continue-pending-page';
}

function dev30_release_pending_page(string $release): void
{
    if (is_link($release) || file_exists($release)) throw new RuntimeException('pending_page_release_not_fresh');
    $handle = @fopen($release, 'x+b');
    if (!is_resource($handle)) throw new RuntimeException('pending_page_release_create_failed');
    try {
        if (fwrite($handle, "continue\n") !== 9 || !fflush($handle)) throw new RuntimeException('pending_page_release_write_failed');
    } finally { fclose($handle); }
    if (posix_geteuid() === 0 && (!@chown($release, 'www-data') || !@chgrp($release, 'www-data'))) throw new RuntimeException('pending_page_release_owner_failed');
    if (!@chmod($release, 0660)) throw new RuntimeException('pending_page_release_mode_failed');
}

function dev30_concurrent_api(array &$ctx, array $calls): array
{
    if (count($calls) !== 2) throw new InvalidArgumentException('concurrent_api_requires_two_calls');
    $offset = @filesize($ctx['pgLogPath']);
    if (!is_int($offset)) throw new RuntimeException('postgres_log_unreadable');
    $children = [];
    try {
        foreach ($calls as $call) {
            if (!is_array($call) || !is_string($call['caseId'] ?? null) || !is_string($call['operation'] ?? null)
                || !is_array($call['payload'] ?? null) || !is_array($call['options'] ?? null)) {
                throw new InvalidArgumentException('concurrent_api_call_invalid');
            }
            $options = array_replace($call['options'], ['pauseAfterProfileLease' => true, 'pauseTimeoutSeconds' => 60]);
            $child = dev30_start_child($ctx, $call['caseId'], $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'api_driver.php', [], null,
                $call['operation'], $call['payload'], $options);
            $children[] = ['call' => $call, 'child' => $child, 'finished' => false];
        }
        $releases = [];
        foreach ($children as $entry) $releases[] = dev30_wait_for_api_pause($ctx, $entry['call']['caseId']);
        foreach ($releases as $release) dev30_release_api_pause($release);
        $results = [];
        foreach ($children as &$entry) {
            $completed = dev30_finish_child($ctx, $entry['child']);
            $entry['finished'] = true;
            $results[] = dev30_api_result($ctx, $entry['call']['operation'], $offset, $completed);
        }
        unset($entry);
        return $results;
    } catch (Throwable $error) {
        foreach ($children as $entry) {
            if ($entry['finished']) continue;
            $caseId = $entry['call']['caseId'];
            $control = $ctx['apiFixtureParent'] . DIRECTORY_SEPARATOR . $caseId . DIRECTORY_SEPARATOR . 'control';
            $marker = $control . DIRECTORY_SEPARATOR . 'profile-lease-held';
            $release = $control . DIRECTORY_SEPARATOR . 'continue-request';
            if (is_file($marker) && !is_link($marker) && !file_exists($release)) {
                try { dev30_release_api_pause($release); } catch (Throwable) {}
            } else {
                @proc_terminate($entry['child']['process'], 9);
            }
            try { dev30_finish_child($ctx, $entry['child']); } catch (Throwable) {}
        }
        throw $error;
    }
}

function dev30_query_evidence(array $ctx, string $app, int $offset): array
{
    clearstatcache(true, $ctx['pgLogPath']);
    $raw = @file_get_contents($ctx['pgLogPath']);
    if (!is_string($raw)) throw new RuntimeException('postgres_log_unreadable');
    $tail = substr($raw, min($offset, strlen($raw)));
    $counts = ['statements' => 0, 'selects' => 0, 'mutations' => 0, 'operationExists' => 0, 'dispatchAnchorChecks' => 0, 'pendingScans' => 0,
        'pendingWatermarkChecks' => 0, 'pendingRowCountChecks' => 0, 'autonomyBatchChecks' => 0,
        'ownerWriterLocks' => 0, 'eventlogWriterLocks' => 0, 'guardChecksUnderWriterFence' => 0];
    $lastWriterFenceLine = -1;
    foreach (preg_split('/\r?\n/', $tail) ?: [] as $lineNumber => $line) {
        if (!preg_match('/(?:^|[ ,])app=' . preg_quote($app, '/') . '(?:[ ,]|$)/', $line)) continue;
        $marker = strpos($line, 'statement:');
        if ($marker !== false) $sql = trim(substr($line, $marker + 10));
        else {
            $execute = strpos($line, 'execute ');
            $colon = $execute === false ? false : strpos($line, ':', $execute);
            if ($colon === false) continue;
            $sql = trim(substr($line, $colon + 1));
        }
        if ($sql === '') continue;
        $counts['statements']++;
        if (preg_match('/\A(?:SELECT|WITH)\b/i', $sql) === 1) $counts['selects']++;
        if (preg_match('/\A(?:INSERT|UPDATE|DELETE|TRUNCATE|ALTER|DROP|CREATE|GRANT|REVOKE)\b/i', $sql) === 1) $counts['mutations']++;
        if (stripos($sql, 'EXISTS') !== false && stripos($sql, 'FROM plugins.correspondence_operations') !== false) $counts['operationExists']++;
        if (stripos($sql, 'public.eventlog') !== false && stripos($sql, "sess='correspondence_v1'") !== false
            && stripos($sql, 'data LIKE') !== false) $counts['dispatchAnchorChecks']++;
        if (stripos($sql, 'FROM plugins.correspondence_documents d') !== false
            && stripos($sql, "payload->'payer'->>'kind'='player'") !== false
            && stripos($sql, 'LIMIT $6::integer') !== false) $counts['pendingScans']++;
        if (stripos($sql, 'FROM plugins.correspondence_documents d') !== false
            && stripos($sql, "payload->'payer'->>'kind'='player'") !== false
            && stripos($sql, 'ORDER BY d.created_at DESC,d.document_id DESC LIMIT 1') !== false) $counts['pendingWatermarkChecks']++;
        if (stripos($sql, 'count(*)') !== false && stripos($sql, 'FROM plugins.correspondence_documents d') !== false
            && stripos($sql, "payload->'payer'->>'kind'='player'") !== false) $counts['pendingRowCountChecks']++;
        if (stripos($sql, 'FROM plugins.correspondence_autonomy_decisions') !== false
            && stripos($sql, 'child_document_id=ANY($2::uuid[])') !== false) $counts['autonomyBatchChecks']++;
        if (stripos($sql, 'pg_advisory_xact_lock(18459814, 1)') !== false) {
            $counts['ownerWriterLocks']++;
            $lastWriterFenceLine = $lineNumber;
        }
        if (stripos($sql, 'LOCK TABLE public.eventlog IN SHARE ROW EXCLUSIVE MODE') !== false) {
            $counts['eventlogWriterLocks']++;
            $lastWriterFenceLine = $lineNumber;
        }
        if ($counts['operationExists'] > 0 && stripos($sql, 'EXISTS') !== false
            && stripos($sql, 'FROM plugins.correspondence_operations') !== false && $lastWriterFenceLine >= 0
            && $lineNumber > $lastWriterFenceLine) $counts['guardChecksUnderWriterFence']++;
    }
    return $counts;
}

function dev30_read_fence(array $ctx, string $app, int $offset): array
{
    clearstatcache(true, $ctx['pgLogPath']);
    $raw = @file_get_contents($ctx['pgLogPath']);
    if (!is_string($raw)) throw new RuntimeException('postgres_log_unreadable');
    $tail = substr($raw, min($offset, strlen($raw)));
    $statements = [];
    foreach (preg_split('/\r?\n/', $tail) ?: [] as $lineNo => $line) {
        if (!preg_match('/(?:^|[ ,])app=' . preg_quote($app, '/') . '(?:[ ,]|$)/', $line, $pidMatch)
            || !preg_match('/\[(\d+)\]/', $line, $pid)) continue;
        $marker = strpos($line, 'statement:');
        if ($marker !== false) $sql = trim(substr($line, $marker + 10));
        else {
            $execute = strpos($line, 'execute ');
            $colon = $execute === false ? false : strpos($line, ':', $execute);
            if ($colon === false) continue;
            $sql = trim(substr($line, $colon + 1));
        }
        $kind = null;
        if (stripos($sql, 'pg_advisory_unlock_shared') !== false) $kind = 'unlock';
        elseif (stripos($sql, 'pg_advisory_lock_shared') !== false) $kind = 'lock';
        elseif (preg_match('/\ABEGIN\s+ISOLATION\s+LEVEL\s+REPEATABLE\s+READ\s+READ\s+ONLY\b/i', $sql)) $kind = 'begin';
        elseif (preg_match('/\ACOMMIT(?:\s+AND\s+(?:NO\s+)?CHAIN)?\s*;?\z/i', $sql)) $kind = 'commit';
        elseif (preg_match('/\AROLLBACK(?:\s+AND\s+(?:NO\s+)?CHAIN)?\s*;?\z/i', $sql)) $kind = 'rollback';
        elseif (stripos($sql, 'FROM plugins.correspondence_restore_state') !== false) $kind = 'restore';
        elseif (preg_match('/\ASELECT\b(?:(?!\bFROM\b).)*\brefid\b(?:(?!\bFROM\b).)*\bFROM\s+public\.core_npc_master\b/is', $sql)) $kind = 'actor';
        elseif (preg_match('/\ASELECT\s+plugin_name\s*,\s*formid_prefix\s+FROM\s+public\.game_plugins\b/is', $sql)) $kind = 'plugin';
        if ($kind !== null) $statements[] = ['line' => $lineNo, 'pid' => (int)$pid[1], 'kind' => $kind];
    }
    foreach ($statements as $lock) {
        if ($lock['kind'] !== 'lock') continue;
        $backend = array_values(array_filter($statements, static fn(array $row): bool => $row['pid'] === $lock['pid']));
        $found = ['begin' => null, 'restore' => null, 'terminal' => null, 'unlock' => null];
        foreach ($backend as $item) {
            if ($item['line'] <= $lock['line']) continue;
            if ($found['begin'] === null && $item['kind'] === 'begin') { $found['begin'] = $item; continue; }
            if ($found['begin'] !== null && $found['restore'] === null && $item['kind'] === 'restore') { $found['restore'] = $item; continue; }
            if ($found['restore'] !== null && $found['terminal'] === null && in_array($item['kind'], ['commit','rollback'], true)) { $found['terminal'] = $item; continue; }
            if ($found['terminal'] !== null && $item['kind'] === 'unlock') { $found['unlock'] = $item; break; }
        }
        if (in_array(null, $found, true)) continue;
        $counts = ['sharedLock' => 1, 'rrBegin' => 0, 'commit' => 0, 'sharedUnlock' => 0, 'rollback' => 0];
        foreach ($backend as $item) {
            if ($item['kind'] === 'begin') $counts['rrBegin']++;
            if ($item['kind'] === 'commit') $counts['commit']++;
            if ($item['kind'] === 'unlock') $counts['sharedUnlock']++;
            if ($item['kind'] === 'rollback') $counts['rollback']++;
        }
        $mappings = ['coreActorRead' => 0, 'pluginRead' => 0];
        foreach ($backend as $item) {
            if ($item['line'] <= $found['restore']['line'] || $item['line'] >= $found['terminal']['line']) continue;
            if ($item['kind'] === 'actor') $mappings['coreActorRead']++;
            if ($item['kind'] === 'plugin') $mappings['pluginRead']++;
        }
        return ['counts' => $counts, 'terminal' => $found['terminal']['kind'], 'ordered' => true, 'mappings' => $mappings];
    }
    throw new Dev30PgFailure('read_fence_not_observed');
}

function dev30_assert_fence(array $fence, bool $rollback): void
{
    $expected = $rollback
        ? ['sharedLock' => 1, 'rrBegin' => 1, 'commit' => 0, 'sharedUnlock' => 1, 'rollback' => 1]
        : ['sharedLock' => 1, 'rrBegin' => 1, 'commit' => 1, 'sharedUnlock' => 1, 'rollback' => 0];
    dev30_assert(($fence['counts'] ?? null) === $expected && ($fence['terminal'] ?? null) === ($rollback ? 'rollback' : 'commit')
        && ($fence['ordered'] ?? null) === true, 'read_fence_shape_invalid');
}

function dev30_response(array $result, string $phase = 'operation'): array
{
    foreach (array_reverse($result['responses'] ?? []) as $response) if (($response['phase'] ?? null) === $phase) return $response;
    throw new Dev30PgFailure('api_response_missing');
}

function dev30_seed_companion(array &$ctx, string $body, bool $dueNow): array
{
    return dev23_companion_with_owner_runtime($ctx, static function ($store, $service, $companion) use ($body, $dueNow): array {
        $clock = $store->scope();
        $scope = (string)($clock['playthrough_id'] ?? '');
        $gamets = filter_var($clock['gamets'] ?? null, FILTER_VALIDATE_INT);
        $token = $store->restoreGeneration();
        if ($scope === '' || !is_int($gamets) || !is_string($token) || $token === '') throw new RuntimeException('companion_context_missing');
        $epoch = correspondenceCompanionEpoch($token);
        $requestId = dev30_uuid();
        $episodeId = dev30_uuid();
        return $store->transaction(static function () use ($store, $service, $scope, $gamets, $epoch, $requestId, $episodeId, $body, $dueNow): array {
            $store->query('INSERT INTO plugins.correspondence_autonomy_settings(playthrough_id,enabled) VALUES($1,TRUE) ON CONFLICT(playthrough_id) DO UPDATE SET enabled=TRUE,updated_at=clock_timestamp()', [$scope]);
            $made = $service->compose(['request_id' => $requestId, 'author' => 'npc:900003', 'payer' => 'npc:900003',
                'recipient' => 'player', 'claimed_sender' => 'npc:900003', 'body' => $body, 'attachments' => []]);
            $documentId = $made['document_id'] ?? null;
            if (!is_string($documentId)) throw new RuntimeException('companion_document_missing');
            $identity = correspondenceCompanionIdentity(['id' => '900003'] + $store->physicalActor(['kind' => 'npc', 'id' => 900003]));
            $minute = correspondenceCompanionGameMinute(max(0, $gamets));
            $sample = '1';
            $due = $dueNow ? max(0, $gamets - 1) : $gamets + 100000000;
            $store->query('INSERT INTO plugins.correspondence_companion_episode(playthrough_id,epoch,actor_id,episode_id,first_game_minute,native_first_game_minute,game_minute,sample_gamets,identity) VALUES($1,$2::uuid,900003,$3::uuid,$4::bigint,$4::bigint,$5::bigint,$6::numeric,$7::jsonb) ON CONFLICT(playthrough_id,actor_id) DO UPDATE SET epoch=EXCLUDED.epoch,episode_id=EXCLUDED.episode_id,first_game_minute=EXCLUDED.first_game_minute,native_first_game_minute=EXCLUDED.native_first_game_minute,game_minute=EXCLUDED.game_minute,sample_gamets=EXCLUDED.sample_gamets,identity=EXCLUDED.identity',
                [$scope, $epoch, $episodeId, (string)max(0, $minute - 2880), (string)$minute, $sample, correspondenceJson($identity)]);
            $store->query("INSERT INTO plugins.correspondence_companion_decision(playthrough_id,epoch,actor_id,episode_id,request_id,attempted,status,document_id,due_gamets,claimed_gamets,reason_code) VALUES($1,$2::uuid,900003,$3::uuid,$4::uuid,TRUE,'queued',$5::uuid,$6::numeric,$7::numeric,'queued_for_transit')",
                [$scope, $epoch, $episodeId, $requestId, $documentId, (string)$due, (string)$gamets]);
            return ['documentId' => $documentId, 'requestId' => $requestId, 'episodeId' => $episodeId,
                'scope' => $scope, 'epoch' => $epoch, 'gamets' => $gamets];
        });
    });
}

function dev30_seed_manual_letter(array $ctx, string $body, array $attachments = []): array
{
    return dev23_companion_with_owner_runtime($ctx, static function ($store, $service) use ($body, $attachments): array {
        $scope = (string)($store->scope()['playthrough_id'] ?? '');
        if ($scope === '') throw new RuntimeException('manual_letter_scope_missing');
        $requestId = dev30_uuid();
        $made = $service->compose([
            'request_id' => $requestId,
            'author' => 'player',
            'payer' => 'player',
            'recipient' => 'npc:900003',
            'claimed_sender' => 'player',
            'body' => $body,
            'attachments' => $attachments,
        ]);
        $documentId = $made['document_id'] ?? null;
        if (!is_string($documentId)) throw new RuntimeException('manual_letter_document_missing');
        return ['documentId' => $documentId, 'requestId' => $requestId, 'scope' => $scope];
    });
}

function dev30_seed_resolved_manual_letters(array $ctx, int $count): array
{
    return dev23_companion_with_owner_runtime($ctx, static function ($store, $service) use ($count): array {
        if ($count < 1 || ($store->scope()['playthrough_id'] ?? null) !== 'unprofiled') {
            throw new RuntimeException('resolved_history_scope_invalid');
        }
        $resolved = [];
        for ($index = 0; $index < $count; $index++) {
            $requestId = dev30_uuid();
            $made = $service->compose([
                'request_id' => $requestId,
                'author' => 'player',
                'payer' => 'player',
                'recipient' => 'npc:900003',
                'claimed_sender' => 'player',
                'body' => 'Synthetic TEST owner-confirmed resolved history row ' . ($index + 1) . '.',
                'attachments' => [['kind' => 'item', 'form_id' => 'Skyrim.esm|00000001', 'quantity' => 1]],
            ]);
            $documentId = $made['document_id'] ?? null;
            if (!is_string($documentId)) throw new RuntimeException('resolved_history_document_missing');
            $dispatch = $service->dispatch($documentId);
            $operationId = $dispatch['operation_id'] ?? null;
            if (($dispatch['state'] ?? null) !== 'dispatched' || !is_string($operationId)) {
                throw new RuntimeException('resolved_history_dispatch_missing');
            }
            $operation = $store->loadOperation($operationId);
            if (!is_array($operation) || !is_array($operation['attachments'] ?? null) || $operation['attachments'] === []) {
                throw new RuntimeException('resolved_history_operation_missing');
            }
            $clock = $store->query('SELECT ts::text AS ts,gamets::text AS gamets FROM public.eventlog ORDER BY rowid DESC LIMIT 1');
            if (count($clock) !== 1 || !is_string($clock[0]['ts'] ?? null) || !is_string($clock[0]['gamets'] ?? null)) {
                throw new RuntimeException('resolved_history_clock_missing');
            }
            $store->bindRequestClock([1 => $clock[0]['ts'], 2 => $clock[0]['gamets']]);
            $receiptAttachments = [];
            foreach ($operation['attachments'] as $attachment) {
                if (!is_int($attachment['form_id'] ?? null) || !is_int($attachment['quantity'] ?? null)) {
                    throw new RuntimeException('resolved_history_attachment_invalid');
                }
                $quantity = $attachment['quantity'];
                $receiptAttachments[] = [
                    'form_id' => $attachment['form_id'],
                    'quantity' => $quantity,
                    'before_source' => $quantity,
                    'after_source' => 0,
                    'before_recipient' => 0,
                    'after_recipient' => $quantity,
                ];
            }
            $receipt = [
                'protocol_version' => 1,
                'document_id' => $operation['document_id'],
                'operation_id' => $operation['operation_id'],
                'receipt_id' => $operation['receipt_id'],
                'playthrough_id' => $operation['playthrough_id'],
                'scope_token' => $operation['scope_token'],
                'nonce' => $operation['nonce'],
                'status' => 'delivered',
                'reason' => 'delivered',
                'note_form_id' => hexdec('000A2C94'),
                'attachments' => $receiptAttachments,
            ];
            $accepted = $service->receive($receipt);
            if (($accepted['state'] ?? null) !== 'delivered') throw new RuntimeException('resolved_history_receipt_rejected');
            $resolved[] = ['documentId' => $documentId, 'requestId' => $requestId];
        }
        return $resolved;
    });
}

function dev30_with_unprofiled_scope(array &$ctx, mixed $admin, callable $work): mixed
{
    $settings = dev30_pg($admin,
        "SELECT key,value FROM chim_meta.settings WHERE key IN ('PLAYTHROUGH_AUTO_SWITCH','PLAYTHROUGH_SESSION') ORDER BY key");
    $settingValues = [];
    foreach ($settings as $setting) {
        if (!is_string($setting['key'] ?? null) || !is_string($setting['value'] ?? null)) {
            throw new RuntimeException('unprofiled_settings_invalid');
        }
        $settingValues[$setting['key']] = json_decode($setting['value'], true, 16, JSON_THROW_ON_ERROR);
    }
    if (($settingValues['PLAYTHROUGH_AUTO_SWITCH'] ?? false) !== false
        || (($settingValues['PLAYTHROUGH_SESSION']['status'] ?? null) === 'pending')) {
        throw new RuntimeException('unprofiled_fallback_not_available');
    }

    $active = dev30_pg($admin,
        'SELECT id::text AS id FROM chim_meta.playthrough_profiles WHERE is_active IS TRUE ORDER BY id LIMIT 2');
    if (count($active) > 1 || (count($active) === 1 && !ctype_digit((string)($active[0]['id'] ?? '')))) {
        throw new RuntimeException('unprofiled_active_profile_ambiguous');
    }
    $activeId = count($active) === 1 ? (string)$active[0]['id'] : null;
    if ($activeId !== null) {
        $deactivated = dev30_pg($admin,
            'UPDATE chim_meta.playthrough_profiles SET is_active=FALSE WHERE id=$1::bigint AND is_active IS TRUE RETURNING id::text AS id',
            [$activeId]);
        if (count($deactivated) !== 1 || ($deactivated[0]['id'] ?? null) !== $activeId) {
            throw new RuntimeException('unprofiled_profile_deactivate_failed');
        }
    }

    try {
        dev23_companion_with_owner_runtime($ctx, static function ($store): void {
            $scope = $store->scope();
            if (($scope['playthrough_id'] ?? null) !== 'unprofiled') {
                throw new RuntimeException('pinned_owner_unprofiled_scope_missing');
            }
        });
        return $work();
    } finally {
        if ($activeId !== null) {
            $restored = dev30_pg($admin,
                'UPDATE chim_meta.playthrough_profiles SET is_active=TRUE WHERE id=$1::bigint AND is_active IS FALSE RETURNING id::text AS id',
                [$activeId]);
            if (count($restored) !== 1 || ($restored[0]['id'] ?? null) !== $activeId) {
                throw new RuntimeException('unprofiled_profile_restore_failed');
            }
        }
    }
}

function dev30_delivery_counts(mixed $admin, string $documentId): array
{
    return [
        'documents' => (int)dev30_scalar($admin, 'SELECT count(*)::text FROM plugins.correspondence_documents WHERE document_id=$1::uuid', [$documentId]),
        'operations' => (int)dev30_scalar($admin, 'SELECT count(*)::text FROM plugins.correspondence_operations WHERE document_id=$1::uuid', [$documentId]),
        'commands' => (int)dev30_scalar($admin,
            "SELECT count(*)::text FROM public.responselog WHERE action='command' AND text LIKE '%ExtCmdCHIMCorrespondenceBridge_Deliver@%' AND text LIKE $1",
            ['%' . $documentId . '%']),
    ];
}

function dev30_owner_case(array &$ctx, mixed $admin, string $caseId, string $variant, array $baseline): void
{
    $originalEpoch = (string)dev30_scalar($admin, 'SELECT epoch::text FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
    $seed = dev30_seed_companion($ctx, 'I am waiting for you. Please write when you can.', true);
    try {
    dev23_companion_with_owner_runtime($ctx, static function ($store, $service, $companion) use (&$ctx, $admin, $seed, $variant, $originalEpoch, $baseline): array {
        $restoreRows = dev30_pg($admin, 'SELECT epoch::text AS epoch,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
        if (count($restoreRows) !== 1 || ($restoreRows[0]['pending'] ?? null) !== 'f' || ($restoreRows[0]['epoch'] ?? null) !== $originalEpoch) throw new RuntimeException('restore_state_not_ready');
        if ($variant === 'restore_changed') {
            dev30_pg($admin, 'UPDATE plugins.correspondence_restore_state SET epoch=$1::uuid WHERE singleton IS TRUE', [dev30_uuid()]);
        } else $store->transaction(static function () use ($store, $variant, $seed): void {
            if ($variant === 'bgl_unreadable') $store->query("UPDATE public.core_npc_master SET extended_data=jsonb_set(extended_data,'{background_life_letters}','{}'::jsonb) WHERE id=900003");
            elseif ($variant === 'player_unavailable') $store->query("UPDATE public.core_player SET value='' WHERE id='player_name'");
            elseif ($variant === 'form_unavailable') $store->query('DELETE FROM public.game_plugins');
            elseif ($variant === 'player_changed') $store->query("UPDATE public.core_player SET value='Someone Else' WHERE id='player_name'");
            elseif ($variant === 'actor_changed') $store->query("UPDATE public.core_npc_master SET npc_name='Someone Else' WHERE id=900003");
            elseif ($variant === 'form_changed') $store->query("UPDATE public.core_npc_master SET refid='000A2C95' WHERE id=900003");
            elseif ($variant === 'optout') $store->query("UPDATE public.core_npc_master SET extended_data=jsonb_set(extended_data,'{background_life_letters}','false'::jsonb) WHERE id=900003");
            elseif ($variant === 'dead') $store->query("UPDATE public.core_npc_master SET metadata='{" . "\"stats\":{\"is_dead\":true}}'::jsonb WHERE id=900003");
            elseif ($variant === 'episode_changed') $store->query('UPDATE plugins.correspondence_companion_episode SET episode_id=$1::uuid WHERE playthrough_id=$2 AND actor_id=900003', [dev30_uuid(), $seed['scope']]);
        });
        $restoreRejected = false;
        $tick = null;
        try { $tick = $companion->tick(null); }
        catch (DomainException $error) {
            if ($variant !== 'restore_changed' || !in_array($error->getMessage(), ['restore_epoch_changed', 'restore_unavailable'], true)) throw $error;
            $restoreRejected = true;
        }
        $decision = dev30_pg($admin, 'SELECT status,reason_code FROM plugins.correspondence_companion_decision WHERE document_id=$1::uuid', [$seed['documentId']]);
        if (count($decision) !== 1) throw new RuntimeException('companion_decision_missing');
        $operations = (int)dev30_scalar($admin, 'SELECT count(*)::text FROM plugins.correspondence_operations WHERE document_id=$1::uuid', [$seed['documentId']]);
        $commands = (int)dev30_scalar($admin, "SELECT count(*)::text FROM public.responselog WHERE action='command' AND text LIKE '%ExtCmdCHIMCorrespondenceBridge_Deliver@%' AND text LIKE $1", ['%' . $seed['documentId'] . '%']);
        if ($variant === 'restore_changed') {
            dev30_assert($restoreRejected, 'restore_epoch_change_not_rejected');
            dev30_assert($decision[0]['status'] === 'queued' && $operations === 0 && $commands === 0, 'restore_epoch_rejection_dispatched');
            return ['transient' => false, 'originalEpoch' => $originalEpoch];
        }
        $cancelled = in_array($variant, ['player_changed', 'actor_changed', 'form_changed', 'optout', 'dead', 'episode_changed'], true);
        if ($cancelled) {
            dev30_assert($decision[0]['status'] === 'stale' && $operations === 0 && $commands === 0, 'confirmed_identity_change_not_cancelled');
            if (in_array($variant, ['player_changed', 'actor_changed'], true)) {
                dev30_reset_fixture_inputs($admin, $baseline);
                dev30_enable_owner_actor($admin, $baseline);
                $presenceRaw = base64_encode(json_encode([
                    'source' => 'plugin_player_routing_v2',
                    'present_actors' => [[
                        'form_id' => hexdec('000A2C94'), 'name' => 'Lydia', 'managed' => true, 'creature' => false, 'distance' => 0,
                    ]],
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                $presenceClock = $store->query('SELECT ts::text AS ts,gamets::text AS gamets FROM public.eventlog ORDER BY rowid DESC LIMIT 1');
                if (count($presenceClock) !== 1 || !is_string($presenceClock[0]['ts'] ?? null) || !is_string($presenceClock[0]['gamets'] ?? null)) {
                    throw new RuntimeException('owner_fixture_request_clock_unavailable');
                }
                $store->bindRequestClock([0 => 'inputtext', 1 => $presenceClock[0]['ts'], 2 => $presenceClock[0]['gamets']]);
                $store->transaction(static function () use ($store, $presenceRaw): void { $store->observeManaged($presenceRaw); });
                dev30_assert($store->observedExecutor() === 'Lydia', 'valid_presence_not_restored_after_identity_change');
                $store->transaction(static function () use ($store, $seed): void {
                    $store->query('UPDATE plugins.correspondence_companion_episode SET sample_gamets=$1::numeric WHERE episode_id=$2::uuid', [(string)$seed['gamets'], $seed['episodeId']]);
                });
                $companion->tick(null);
                $companion->tick(null);
                $stillStale = dev30_pg($admin, 'SELECT status FROM plugins.correspondence_companion_decision WHERE document_id=$1::uuid', [$seed['documentId']]);
                $stillNoOperations = (int)dev30_scalar($admin, 'SELECT count(*)::text FROM plugins.correspondence_operations WHERE document_id=$1::uuid', [$seed['documentId']]);
                $stillNoCommands = (int)dev30_scalar($admin, "SELECT count(*)::text FROM public.responselog WHERE action='command' AND text LIKE '%ExtCmdCHIMCorrespondenceBridge_Deliver@%' AND text LIKE $1", ['%' . $seed['documentId'] . '%']);
                dev30_assert(count($stillStale) === 1 && $stillStale[0]['status'] === 'stale' && $stillNoOperations === 0 && $stillNoCommands === 0,
                    'fresh_identity_sample_reactivated_cancelled_decision');
                try {
                    $service->dispatch($seed['documentId']);
                    throw new Dev30PgFailure('manual_cancelled_scheduler_dispatch_allowed');
                } catch (DomainException $error) {
                    dev30_assert($error->getMessage() === 'companion_scheduler_owned', 'manual_cancelled_scheduler_dispatch_wrong_refusal');
                }
                $ownerManualOperations = (int)dev30_scalar($admin, 'SELECT count(*)::text FROM plugins.correspondence_operations WHERE document_id=$1::uuid', [$seed['documentId']]);
                $ownerManualCommands = (int)dev30_scalar($admin, "SELECT count(*)::text FROM public.responselog WHERE action='command' AND text LIKE '%ExtCmdCHIMCorrespondenceBridge_Deliver@%' AND text LIKE $1", ['%' . $seed['documentId'] . '%']);
                dev30_assert($ownerManualOperations === 0 && $ownerManualCommands === 0, 'manual_cancelled_scheduler_dispatch_created_delivery');
                $reconciled = dev30_api($ctx, 'sf30_cancel_read_' . $variant, 'correspondence.reconcile', ['operationId' => $seed['requestId']], ['authScopes' => ['compose']]);
                $projection = dev30_response($reconciled);
                dev30_assert_fence($reconciled['readFence'], false);
                $reconcileOperations = (int)dev30_scalar($admin, 'SELECT count(*)::text FROM plugins.correspondence_operations WHERE document_id=$1::uuid', [$seed['documentId']]);
                $reconcileCommands = (int)dev30_scalar($admin, "SELECT count(*)::text FROM public.responselog WHERE action='command' AND text LIKE '%ExtCmdCHIMCorrespondenceBridge_Deliver@%' AND text LIKE $1", ['%' . $seed['documentId'] . '%']);
                dev30_assert(($projection['ok'] ?? null) === true && ($projection['data']['state'] ?? null) === 'queued'
                    && ($projection['data']['companionSchedulerOwned'] ?? null) === true
                    && $reconcileOperations === 0 && $reconcileCommands === 0, 'cancelled_document_lost_scheduler_ownership_or_reconcile_dispatched');

                $beforeManual = dev30_count_snapshot($admin);
                $manual = dev30_api($ctx, 'sf30_cancel_manual_' . $variant, 'correspondence.compose', [
                    'operationId' => $seed['requestId'], 'writerActorKey' => 'npc:900003', 'signatureActorKey' => 'npc:900003',
                    'recipientActorKey' => 'player', 'body' => 'A manual retry probe after identity cancellation.',
                    'inventoryRevision' => 'dev30-cancelled-manual-probe', 'attachments' => [], 'goldAmount' => 0,
                ], ['operationRequestId' => $seed['requestId'], 'authScopes' => ['compose']]);
                $manualResponse = dev30_response($manual);
                $afterManual = dev30_count_snapshot($admin);
                $manualOperations = (int)dev30_scalar($admin, 'SELECT count(*)::text FROM plugins.correspondence_operations WHERE document_id=$1::uuid', [$seed['documentId']]);
                $manualCommands = (int)dev30_scalar($admin, "SELECT count(*)::text FROM public.responselog WHERE action='command' AND text LIKE '%ExtCmdCHIMCorrespondenceBridge_Deliver@%' AND text LIKE $1", ['%' . $seed['documentId'] . '%']);
                dev30_assert(($manualResponse['ok'] ?? null) === false && ($manualResponse['errorCode'] ?? null) === 'conflict'
                    && $beforeManual === $afterManual && $manualOperations === 0 && $manualCommands === 0,
                    'same_request_manual_compose_reopened_cancelled_document');
            } else {
                $closed = dev30_scalar($admin, 'SELECT payload ? \'operator_close\' FROM plugins.correspondence_documents WHERE document_id=$1::uuid', [$seed['documentId']]);
                dev30_assert(in_array($closed, ['t', true], true), 'cancelled_companion_document_not_closed');
            }
            return ['transient' => false, 'originalEpoch' => $originalEpoch];
        }
        dev30_assert($decision[0]['status'] === 'queued' && $operations === 0 && $commands === 0, 'temporary_unavailability_did_not_defer');
        $closed = dev30_scalar($admin, 'SELECT payload ? \'operator_close\' FROM plugins.correspondence_documents WHERE document_id=$1::uuid', [$seed['documentId']]);
        dev30_assert(in_array($closed, ['f', false], true), 'temporary_unavailability_invented_close');
        $store->transaction(static function () use ($store, $variant, $seed): void {
            if ($variant === 'player_unavailable') $store->query("UPDATE public.core_player SET value='Dragonborn' WHERE id='player_name'");
            elseif ($variant === 'form_unavailable') $store->query("INSERT INTO public.game_plugins(plugin_name,formid_prefix) VALUES('Skyrim.esm','00')");
            elseif ($variant === 'bgl_unreadable') $store->query("UPDATE public.core_npc_master SET extended_data=jsonb_set(extended_data,'{background_life_letters}','true'::jsonb) WHERE id=900003");
        });
        $companion->tick(null);
        $staleSampleOps = (int)dev30_scalar($admin, 'SELECT count(*)::text FROM plugins.correspondence_operations WHERE document_id=$1::uuid', [$seed['documentId']]);
        dev30_assert($staleSampleOps === 0, 'stale_sample_dispatched_after_identity_recovery');
        $store->transaction(static function () use ($store, $seed): void {
            $store->query('UPDATE plugins.correspondence_companion_episode SET sample_gamets=$1::numeric WHERE playthrough_id=$2 AND actor_id=900003', [(string)$seed['gamets'], $seed['scope']]);
        });
        $firstRecovery = $companion->tick(null);
        $secondRecovery = $companion->tick(null);
        $final = dev30_pg($admin, 'SELECT status FROM plugins.correspondence_companion_decision WHERE document_id=$1::uuid', [$seed['documentId']]);
        $finalOps = (int)dev30_scalar($admin, 'SELECT count(*)::text FROM plugins.correspondence_operations WHERE document_id=$1::uuid', [$seed['documentId']]);
        $finalCommands = (int)dev30_scalar($admin, "SELECT count(*)::text FROM public.responselog WHERE action='command' AND text LIKE '%ExtCmdCHIMCorrespondenceBridge_Deliver@%' AND text LIKE $1", ['%' . $seed['documentId'] . '%']);
        dev30_assert(($firstRecovery['status'] ?? null) === 'dispatched' && in_array(($secondRecovery['status'] ?? null), ['idle', 'dispatched'], true), 'fresh_same_episode_recovery_failed');
        dev30_assert(count($final) === 1 && $final[0]['status'] === 'dispatched' && $finalOps === 1 && $finalCommands === 1, 'recovery_not_exactly_once');
        return ['transient' => true, 'originalEpoch' => $originalEpoch];
    });
    } finally {
        if ($variant === 'restore_changed' && preg_match('/\A[0-9a-f-]{36}\z/D', $originalEpoch) === 1) {
            dev30_pg($admin, 'UPDATE plugins.correspondence_restore_state SET epoch=$1::uuid WHERE singleton IS TRUE', [$originalEpoch]);
        }
    }
}

function dev30_enable_owner_actor(mixed $admin, array $baseline): void
{
    $extended = json_decode((string)$baseline['actor']['extended_data'], true, 32);
    $metadata = json_decode((string)$baseline['actor']['metadata'], true, 32);
    if (!is_array($extended)) $extended = [];
    if (!is_array($metadata)) $metadata = [];
    $extended['background_life_enabled'] = true;
    $extended['background_life_letters'] = true;
    if (isset($metadata['stats']) && is_array($metadata['stats'])) unset($metadata['stats']['is_dead']);
    dev30_pg($admin, 'UPDATE public.core_npc_master SET extended_data=$1::jsonb,metadata=$2::jsonb WHERE id=900003',
        [json_encode($extended, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
}

function dev30_case(array &$cases, string $id, callable $work): void
{
    $selected = $GLOBALS['dev30_selected_cases'] ?? null;
    if (is_array($selected) && !in_array($id, $selected, true)) return;
    $GLOBALS['dev30_assertions'] = 0;
    $status = 'passed';
    try { $work(); }
    catch (Throwable $error) {
        $status = 'failed';
        $code = $error->getMessage();
        if (preg_match('/\A[a-z][a-z0-9_]{0,79}\z/iD', $code) !== 1) $code = 'case_exception';
        fwrite(STDERR, 'case:' . $id . ':' . strtolower($code) . "\n");
    }
    $cases[] = ['id' => $id, 'status' => $status, 'assertions' => (int)$GLOBALS['dev30_assertions']];
}

function dev30_reset_fixture_inputs(mixed $admin, array $baseline): void
{
    dev30_pg($admin, 'UPDATE public.core_player SET value=$1 WHERE id=\'player_name\'', [$baseline['player']]);
    dev30_pg($admin, 'UPDATE public.core_npc_master SET npc_name=$1,refid=$2,extended_data=$3::jsonb,metadata=$4::jsonb WHERE id=900003',
        [$baseline['actor']['npc_name'], $baseline['actor']['refid'], $baseline['actor']['extended_data'], $baseline['actor']['metadata']]);
    dev30_pg($admin, 'DELETE FROM public.game_plugins');
    foreach ($baseline['plugins'] as $row) dev30_pg($admin, 'INSERT INTO public.game_plugins(plugin_name,formid_prefix) VALUES($1,$2)', [$row['plugin_name'], $row['formid_prefix']]);
}

function dev30_seed_case_clock(mixed $admin): void
{
    $last = dev30_scalar($admin, 'SELECT COALESCE(MAX(ts),0)::text FROM public.eventlog');
    $ts = max((int)$last + 1, time());
    dev30_pg($admin, "INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES($1::bigint,30000000::numeric,'info','synthetic dev30 fixture clock','dev30_fixture',$1::bigint,'','','')", [(string)$ts]);
}

if (($argv[1] ?? null) === '--topology-guard-self-test') {
    $checks = dev30_ipv6_route_regression() + dev30_loopback_interface_regression();
    fwrite(STDOUT, 'topology_guard_assertions=' . $checks . "\n");
    exit(0);
}

$required = [
    'run-id','source-commit','source-binding','owner-binding','artifact-dir','core-root','server-root','owner-root',
    'scratch-root','fixture-marker','pg-socket','pg-port','pg-database','pg-admin-role','pg-app-role','pg-log-path','php',
];
$options = getopt('', array_merge(array_map(static fn(string $key): string => $key . ':', $required), ['cases:','suite:','interaction-source:']));
$ctx = ['processes' => []];
$cases = [];
$status = 'failed';
$sourceHashes = [];
$ownerBinding = null;
$identity = null;
$networkVerified = false;
$networkEvidenceSha256 = null;
$admin = null;
$failure = null;
$selectedCaseIds = null;
$expectedCaseCount = null;
$suite = 'dev30';
try {
    foreach ($required as $key) if (!is_array($options) || !is_string($options[$key] ?? null) || $options[$key] === '') throw new RuntimeException('argument_' . str_replace('-', '_', $key) . '_required');
    if (array_key_exists('suite', $options) && !is_string($options['suite'])) throw new RuntimeException('suite_invalid');
    $suite = is_string($options['suite'] ?? null) ? $options['suite'] : 'dev30';
    if (!in_array($suite, ['dev30','dev31'], true)) throw new RuntimeException('suite_invalid');
    if ($suite === 'dev31' && (!is_string($options['cases'] ?? null) || $options['cases'] === ''
        || !is_string($options['interaction-source'] ?? null) || $options['interaction-source'] === '')) {
        throw new RuntimeException('dev31_explicit_cases_and_interaction_source_required');
    }
    $runId = (string)$options['run-id'];
    if (preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}\z/D', $runId) !== 1
        || preg_match('/\A[0-9a-f]{40}\z/D', (string)$options['source-commit']) !== 1) throw new RuntimeException('run_identity_invalid');
    if (($options['pg-port'] ?? null) !== '5432' || ($options['pg-database'] ?? null) !== 'dwemer'
        || ($options['pg-admin-role'] ?? null) !== 'postgres' || ($options['pg-app-role'] ?? null) !== 'dwemer') throw new RuntimeException('database_contract_invalid');
    if (PHP_VERSION_ID < 80100 || !extension_loaded('posix') || !extension_loaded('ctype') || !extension_loaded('tokenizer')
        || !extension_loaded('mbstring') || !extension_loaded('pgsql') || !extension_loaded('gd')
        || !function_exists('imagettfbbox') || !function_exists('imagecreatefrompng') || !function_exists('imagepng')) throw new RuntimeException('php_extension_preflight_failed');
    if (PHP_OS_FAMILY !== 'Linux' || getenv('WSL_DISTRO_NAME') !== 'DwemerAI4Skyrim3-test2') throw new RuntimeException('test2_linux_boundary_invalid');
    if (array_key_exists('cases', $options)) {
        if (!is_string($options['cases']) || $options['cases'] === '') throw new RuntimeException('case_filter_invalid');
        $selectedCaseIds = explode(',', $options['cases']);
        if ($selectedCaseIds === [] || in_array('', $selectedCaseIds, true)
            || count(array_unique($selectedCaseIds)) !== count($selectedCaseIds)) throw new RuntimeException('case_filter_invalid');
    }

    $ctx['suite'] = $suite;
    $ctx['runId'] = $runId;
    $ctx['sourceCommit'] = (string)$options['source-commit'];
    $ctx['scratchRoot'] = realpath((string)$options['scratch-root']) ?: '';
    $ctx['coreRoot'] = realpath((string)$options['core-root']) ?: '';
    $ctx['serverRoot'] = realpath((string)$options['server-root']) ?: '';
    $ctx['ownerRoot'] = realpath((string)$options['owner-root']) ?: '';
    $ctx['ownerBindingPath'] = (string)$options['owner-binding'];
    $ctx['sourceBindingPath'] = (string)$options['source-binding'];
    $ctx['fixtureMarker'] = (string)$options['fixture-marker'];
    $ctx['pgSocket'] = realpath((string)$options['pg-socket']) ?: '';
    $ctx['pgLogPath'] = (string)$options['pg-log-path'];
    $ctx['php'] = realpath((string)$options['php']) ?: (string)$options['php'];
    $ctx['artifactDir'] = realpath((string)$options['artifact-dir']) ?: '';
    $ctx['interactionSourcePath'] = is_string($options['interaction-source'] ?? null) ? $options['interaction-source'] : '';
    $ctx['legacyTestRoot'] = $ctx['serverRoot'] . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'dev23-pg';
    $ctx['dev31TestRoot'] = $ctx['serverRoot'] . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'dev31-pg';
    if ($ctx['scratchRoot'] !== '/var/tmp/department-dev23-' . $runId
        || $ctx['coreRoot'] !== $ctx['scratchRoot'] . '/stage/core'
        || $ctx['serverRoot'] !== $ctx['coreRoot'] . '/ext/questionable_decisions'
        || $ctx['ownerRoot'] !== $ctx['coreRoot'] . '/ext/correspondence'
        || $ctx['pgSocket'] !== $ctx['scratchRoot'] . '/socket'
        || (string)$options['pg-socket'] !== $ctx['scratchRoot'] . '/socket'
        || is_link((string)$options['pg-socket']) || !is_dir($ctx['pgSocket'])
        || $ctx['fixtureMarker'] !== $ctx['scratchRoot'] . '/fixture-marker.json'
        || is_link($ctx['fixtureMarker']) || !is_file($ctx['fixtureMarker']) || !is_file($ctx['pgLogPath'])
        || !is_file($ctx['php']) || !is_dir($ctx['legacyTestRoot'])
        || ($suite === 'dev31' && !is_dir($ctx['dev31TestRoot']))) throw new RuntimeException('staged_fixture_identity_invalid');
    if (!is_dir($ctx['artifactDir']) || is_link($ctx['artifactDir'])) throw new RuntimeException('artifact_directory_invalid');
    dev30_require_file($ctx['sourceBindingPath'], $ctx['scratchRoot']);
    dev30_require_file($ctx['ownerBindingPath'], $ctx['scratchRoot']);

    require_once $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'fixture_guard.php';
    putenv('QD_DEV20_RUN_ID=' . $runId);
    putenv('QD_DEV20_SCRATCH_ROOT=' . $ctx['scratchRoot']);
    putenv('QD_DEV20_FIXTURE_MARKER=' . $ctx['fixtureMarker']);
    putenv('QD_CHIM_ROOT=' . $ctx['coreRoot']);
    $fixtureIdentity = qd_dev20_assert_private_fixture();
    if (($fixtureIdentity['runId'] ?? null) !== $runId || ($fixtureIdentity['coreRoot'] ?? null) !== $ctx['coreRoot']) throw new RuntimeException('private_fixture_guard_mismatch');
    $ctx['networkEvidence'] = dev30_loopback_preflight($fixtureIdentity);
    $networkVerified = true;

    $caseManifestPath = $ctx['serverRoot'] . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR
        . ($suite === 'dev31' ? 'dev31-pg' : 'dev30-pg') . DIRECTORY_SEPARATOR . 'cases.json';
    $caseRaw = @file_get_contents($caseManifestPath);
    $caseManifest = is_string($caseRaw) ? json_decode($caseRaw, true, 32) : null;
    $manifestContract = $suite === 'dev31'
        ? is_array($caseManifest) && ($caseManifest['schemaVersion'] ?? null) === 1
            && ($caseManifest['kind'] ?? null) === 'department-dev31-actual-pg-cases'
            && ($caseManifest['ownerVersion'] ?? null) === '0.1.0-dev.31'
            && ($caseManifest['ownerCommit'] ?? null) === 'e393bd5f9a1cd31a8d8d006a4356faddc9eb3212'
            && ($caseManifest['ownerClosureFiles'] ?? null) === 21
            && is_array($caseManifest['cases'] ?? null) && count($caseManifest['cases']) === 11
            && is_array($caseManifest['sourceFiles'] ?? null) && count($caseManifest['sourceFiles']) === 25
            && count(array_unique($caseManifest['sourceFiles'])) === 25
        : is_array($caseManifest) && ($caseManifest['schemaVersion'] ?? null) === 1
            && ($caseManifest['kind'] ?? null) === 'department-dev30-actual-pg-cases'
            && ($caseManifest['ownerVersion'] ?? null) === '0.1.0-dev.30'
            && ($caseManifest['ownerCommit'] ?? null) === '1eb58d999698002f8aeaa05b21464f7608717794'
            && count($caseManifest['cases'] ?? []) === 22 && count($caseManifest['sourceFiles'] ?? []) === 22;
    if (!$manifestContract) throw new RuntimeException('case_manifest_invalid');
    $caseIds = array_column($caseManifest['cases'], 'id');
    $dev30CaseIds = [
        'adapter.unavailable_player_read_recovery','adapter.manual_compose_scheduler_no_dispatch','adapter.request_id_repeat_no_resend',
        'adapter.unknown_partial_reconcile_no_resend','adapter.forwarded_utf8_detail_roundtrip','adapter.restore_pending_refusal_recovery',
        'adapter.resume_manual_readonly_and_retry','adapter.resume_guard_refusals','adapter.resume_concurrent_attempts','adapter.resume_reason_projection',
        'adapter.pending_restart_projection','adapter.pending_limited_and_failure',
        'owner.bgl_unreadable_defer_recover','owner.player_unavailable_defer_recover','owner.form_identity_unavailable_defer_recover',
        'owner.player_identity_mismatch_cancel','owner.actor_identity_mismatch_cancel','owner.form_identity_mismatch_cancel',
        'owner.explicit_optout_cancel','owner.confirmed_dead_cancel','owner.episode_change_cancel','owner.restore_epoch_change_reject',
    ];
    $dev31CaseIds = [
        'adapter.request_id_repeat_no_resend','adapter.unknown_partial_reconcile_no_resend',
        'adapter.forwarded_utf8_detail_roundtrip','adapter.restore_pending_refusal_recovery',
        'adapter.resume_manual_readonly_and_retry','adapter.resume_guard_refusals','adapter.resume_concurrent_attempts',
        'adapter.pending_limited_and_failure','adapter.resume_cold_boot_zero_nearby_npc_saved_500_exact_once',
        'adapter.resume_cold_boot_zero_nearby_npc_saved_500_concurrent_once','adapter.resume_native_context_refusals_no_operation',
    ];
    $manifestExpectedIds = $suite === 'dev31' ? $dev31CaseIds : $dev30CaseIds;
    if ($caseIds !== $manifestExpectedIds) throw new RuntimeException('case_inventory_invalid');
    if ($selectedCaseIds !== null && array_diff($selectedCaseIds, $caseIds) !== []) throw new RuntimeException('case_filter_invalid');
    if ($suite === 'dev31') {
        $requiredSelectedCases = $caseManifest['requiredSelectedCases'] ?? null;
        $actualSelection = $selectedCaseIds;
        if (!is_array($requiredSelectedCases) || $requiredSelectedCases !== $dev31CaseIds || !is_array($actualSelection)) {
            throw new RuntimeException('dev31_case_selection_invalid');
        }
        $sortedSelection = $actualSelection; $sortedRequired = $requiredSelectedCases;
        sort($sortedSelection, SORT_STRING); sort($sortedRequired, SORT_STRING);
        if ($sortedSelection !== $sortedRequired) throw new RuntimeException('dev31_case_selection_invalid');
    }
    $expectedCaseIds = $selectedCaseIds ?? $caseIds;
    $expectedCaseCount = count($expectedCaseIds);
    $GLOBALS['dev30_selected_cases'] = $selectedCaseIds;
    $sourceBindingRaw = @file_get_contents($ctx['sourceBindingPath']);
    $sourceBinding = is_string($sourceBindingRaw) ? json_decode($sourceBindingRaw, true, 32) : null;
    $expectedKeys = ['schemaVersion','sourceCommit','sourceHashes'];
    $bindingKeys = is_array($sourceBinding) ? array_keys($sourceBinding) : [];
    sort($bindingKeys); sort($expectedKeys);
    if (!is_array($sourceBinding) || $bindingKeys !== $expectedKeys || ($sourceBinding['schemaVersion'] ?? null) !== 1
        || ($sourceBinding['sourceCommit'] ?? null) !== $ctx['sourceCommit'] || !is_array($sourceBinding['sourceHashes'] ?? null)) throw new RuntimeException('source_binding_invalid');
    $sourceHashes = dev30_source_hashes($ctx['serverRoot'], $caseManifest['sourceFiles']);
    $bindingHashes = $sourceBinding['sourceHashes']; ksort($bindingHashes); ksort($sourceHashes);
    if ($bindingHashes !== $sourceHashes) throw new RuntimeException('department_source_hash_mismatch');

    $ownerRaw = @file_get_contents($ctx['ownerBindingPath']);
    $ownerBinding = is_string($ownerRaw) ? json_decode($ownerRaw, true, 32) : null;
    $expectedOwnerVersion = $suite === 'dev31' ? '0.1.0-dev.31' : '0.1.0-dev.30';
    $expectedOwnerCommit = $suite === 'dev31'
        ? 'e393bd5f9a1cd31a8d8d006a4356faddc9eb3212' : '1eb58d999698002f8aeaa05b21464f7608717794';
    $expectedOwnerPinCount = $suite === 'dev31' ? 21 : 20;
    if (!is_array($ownerBinding) || ($ownerBinding['schemaVersion'] ?? null) !== 1
        || ($ownerBinding['ownerVersion'] ?? null) !== $expectedOwnerVersion
        || ($ownerBinding['ownerCommit'] ?? null) !== $expectedOwnerCommit
        || !is_array($ownerBinding['ownerClosurePins'] ?? null) || count($ownerBinding['ownerClosurePins']) !== $expectedOwnerPinCount) throw new RuntimeException('owner_binding_invalid');
    if ($suite === 'dev31') {
        $ownerPinsPath = $ctx['dev31TestRoot'] . DIRECTORY_SEPARATOR . 'owner-pins.json';
        dev30_require_file($ownerPinsPath, $ctx['serverRoot']);
        $ownerPinsRaw = @file_get_contents($ownerPinsPath);
        $ownerPinsManifest = is_string($ownerPinsRaw) ? json_decode($ownerPinsRaw, true, 32) : null;
        if (!is_array($ownerPinsManifest) || ($ownerPinsManifest['schemaVersion'] ?? null) !== 1
            || ($ownerPinsManifest['ownerVersion'] ?? null) !== $expectedOwnerVersion
            || ($ownerPinsManifest['ownerCommit'] ?? null) !== $expectedOwnerCommit
            || !is_array($ownerPinsManifest['ownerClosurePins'] ?? null) || count($ownerPinsManifest['ownerClosurePins']) !== $expectedOwnerPinCount) {
            throw new RuntimeException('dev31_owner_pin_manifest_invalid');
        }
        $bindingPins = $ownerBinding['ownerClosurePins'];
        $expectedPins = $ownerPinsManifest['ownerClosurePins'];
        ksort($bindingPins, SORT_STRING); ksort($expectedPins, SORT_STRING);
        if ($bindingPins !== $expectedPins) throw new RuntimeException('dev31_owner_pin_binding_mismatch');
    }
    dev30_owner_pins($ctx['ownerRoot'], $ownerBinding['ownerClosurePins']);
    if (is_link($ctx['coreRoot'] . '/lib/playthrough_preferences.php') || is_link($ctx['coreRoot'] . '/lib/playthrough_runtime.php')
        || is_link($ctx['coreRoot'] . '/lib/playthrough_home.php')) throw new RuntimeException('core_source_redirected');
    $corePins = [
        'lib/playthrough_preferences.php' => '02ec005e7109af689056783bf54d9b3080fcfa16194c5dfda0526c5add4eaf41',
        'lib/playthrough_runtime.php' => 'ec29467c61873f255d3bc13962466ab04f12b3bc2bd6d29276e53878088a1d7e',
        'lib/playthrough_home.php' => 'ac55d75bea4ab30828390cba4bfc50ecb3db91e43c72080705c00abbac1ef18b',
        'lib/playthrough_retention.php' => '6e67953516e43e61d948c324204a43a80437251618822dbbbe58a639c286d8ce',
        'lib/playthrough_schema.php' => '9ea8e03cc870fe813916593104d7cb82c0302235925cee302d0f37a3bd023814',
        'lib/logger.php' => '7a58436e6fed0343f70c086157eff3f501fb3d7a0c51ab50d7d2315ec5b2270a',
        'lib/playthrough_policy.php' => '6568ea2cb04a7c0ee7b72871d4d3cedf3244d6a6a95cf4e9800fbb312f0e98eb',
        'lib/playthrough_categories.php' => 'bd7d8ac41c92870b119e956c200cede1ad83ded39e5e6219f3f64d9a298358c0',
        'lib/utils_game_timestamp.php' => '5a017ff8e3b4ec9f099b76829f44d136d1d5a0432e4e05b9e2af6f6b205e63b4',
        'data/fonts/GloriaHallelujah-Regular.ttf' => '4206cbda828ccde275f6d8b406ad80a29d2f5d9bc1a39a86e56a1c9c7691ed8c',
        'data/textures/chim.png' => '820c235caa44a8f7a798e91d8091a633954963761de6e1377c70d8c8247d0342',
    ];
    $ctx['corePins'] = $corePins;
    foreach ($corePins as $relative => $expected) {
        $path = $ctx['coreRoot'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        dev30_require_file($path, $ctx['coreRoot']);
        if (!hash_equals($expected, strtolower((string)hash_file('sha256', $path)))) throw new RuntimeException('captured_core_input_mismatch');
    }
    if ($suite === 'dev31') {
        $coreInputPath = $ctx['dev31TestRoot'] . DIRECTORY_SEPARATOR . 'core-inputs.json';
        dev30_require_file($coreInputPath, $ctx['serverRoot']);
        $coreInputRaw = @file_get_contents($coreInputPath);
        $coreInputManifest = is_string($coreInputRaw) ? json_decode($coreInputRaw, true, 32) : null;
        if (!is_array($coreInputManifest) || !is_array($coreInputManifest['fixtureState'] ?? null)) {
            throw new RuntimeException('interaction_core_input_manifest_invalid');
        }
        dev31_stage_interaction_helper($ctx, $coreInputManifest, $ctx['interactionSourcePath']);
        dev31_prepare_interaction_fixture($ctx, $coreInputManifest['fixtureState']);
        $corePins = $ctx['corePins'];
    }
    $ctx['trace'] = dev30_trace_helper($ctx);

    $apiFixtureParent = $ctx['scratchRoot'] . DIRECTORY_SEPARATOR . 'api-fixtures';
    if (file_exists($apiFixtureParent) || is_link($apiFixtureParent) || !@mkdir($apiFixtureParent, 0770)) throw new RuntimeException('api_fixture_parent_not_fresh');
    if (posix_geteuid() === 0 && (!@chown($apiFixtureParent, 'www-data') || !@chgrp($apiFixtureParent, 'www-data'))) throw new RuntimeException('api_fixture_parent_owner_failed');
    if (!@chmod($apiFixtureParent, 0770)) throw new RuntimeException('api_fixture_parent_mode_failed');
    $ctx['apiFixtureParent'] = realpath($apiFixtureParent) ?: '';
    $ctx['runuser'] = is_file('/usr/sbin/runuser') ? '/usr/sbin/runuser' : '/usr/bin/runuser';
    if (!is_file($ctx['runuser']) || !is_executable($ctx['runuser'])) throw new RuntimeException('runuser_unavailable');

    foreach (['log/playthrough_runtime','data/books'] as $relative) {
        $path = $ctx['coreRoot'];
        foreach (explode('/', $relative) as $component) {
            $path .= DIRECTORY_SEPARATOR . $component;
            if (is_link($path)) throw new RuntimeException('core_output_path_redirected');
        }
        if (!is_dir($path) && !@mkdir($path, 0770, true) && !is_dir($path)) throw new RuntimeException('core_output_path_create_failed');
        if (!@chown($path, 'www-data') || !@chgrp($path, 'www-data') || !@chmod($path, 02770)) throw new RuntimeException('core_output_permissions_failed');
    }
    $ctx['fixtureLogPath'] = dev30_install_fixture_logger($ctx['coreRoot'], $corePins['lib/logger.php']);

    $socket = $ctx['pgSocket'];
    $quote = static fn(string $value): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    $admin = @pg_connect('host=' . $quote($socket) . ' port=5432 dbname=dwemer user=postgres connect_timeout=3', PGSQL_CONNECT_FORCE_NEW);
    if ($admin === false || $admin === null) throw new RuntimeException('admin_connection_failed');
    $ctx['admin'] = $admin;
    if ($suite === 'dev31') dev31_prepare_responselog_generation_column($admin);
    $appHost = $suite === 'dev31' ? '127.0.0.1' : 'localhost';
    $app = @pg_connect('host=' . $appHost . ' port=5432 dbname=dwemer user=dwemer connect_timeout=3', PGSQL_CONNECT_FORCE_NEW);
    if ($app === false || $app === null) throw new RuntimeException('app_connection_failed');
    $server = dev30_pg($admin, "SELECT current_setting('server_version_num')::int AS version,current_setting('data_directory') AS directory,current_setting('port')::int AS port,current_setting('listen_addresses') AS listeners,current_setting('unix_socket_directories') AS sockets,system_identifier::text AS system_id FROM pg_control_system()");
    $appIdentity = dev30_pg($app, 'SELECT current_database() AS database,current_user AS role,host(inet_client_addr()) AS client_address,host(inet_server_addr()) AS server_address');
    pg_close($app);
    if (count($server) !== 1 || count($appIdentity) !== 1 || (int)$server[0]['version'] < 150000 || (int)$server[0]['version'] >= 160000
        || ($server[0]['listeners'] ?? null) !== '127.0.0.1' || (int)($server[0]['port'] ?? 0) !== 5432
        || ($server[0]['sockets'] ?? null) !== $socket
        || ($appIdentity[0]['database'] ?? null) !== 'dwemer' || ($appIdentity[0]['role'] ?? null) !== 'dwemer'
        || ($appIdentity[0]['client_address'] ?? null) !== '127.0.0.1' || ($appIdentity[0]['server_address'] ?? null) !== '127.0.0.1') throw new RuntimeException('database_identity_invalid');
    $dataDirectory = dev30_owned_pg_data_directory($ctx['scratchRoot'], (string)$server[0]['directory']);
    $identity = [
        'postgresMajor' => 15, 'systemIdentifier' => (string)$server[0]['system_id'],
        'dataDirectory' => $dataDirectory, 'role' => (string)$appIdentity[0]['role'],
        'socketDirectory' => $socket, 'port' => 5432, 'listenAddresses' => '127.0.0.1',
    ];
    $ownershipRows = dev30_pg($admin,
        "SELECT c.relname AS name,pg_get_userbyid(c.relowner) AS owner FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname IN ('eventlog','eventlog_rowid_seq') ORDER BY c.relname");
    $ownership = [];
    foreach ($ownershipRows as $row) {
        if (!is_string($row['name'] ?? null) || !is_string($row['owner'] ?? null)) throw new RuntimeException('database_object_owner_shape_invalid');
        $ownership[$row['name']] = $row['owner'];
    }
    if ($ownership !== ['eventlog' => 'dwemer', 'eventlog_rowid_seq' => 'dwemer']) throw new RuntimeException('database_object_owner_invalid');
    $ctx['fixtureSetup'] = ['appRole' => $identity['role'], 'objectOwners' => $ownership];
    if ($suite === 'dev31') $ctx['fixtureSetup']['responselogInteractionGeneration'] = 'bigint';

    require_once $ctx['serverRoot'] . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'dev23-pg' . DIRECTORY_SEPARATOR . 'companion_cases.php';
    dev30_run_child($ctx, 'fixture_owner_initialize', $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'owner_fixture.php',
        ['--mode','initialize','--core-root',$ctx['coreRoot'],'--pg-socket',$socket]);
    dev30_run_child($ctx, 'fixture_seed_presence', $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'owner_fixture.php',
        ['--mode','seed-presence','--core-root',$ctx['coreRoot'],'--pg-socket',$socket]);

    $baseline = [
        'player' => dev30_scalar($admin, "SELECT value FROM public.core_player WHERE id='player_name'"),
        'actor' => dev30_pg($admin, 'SELECT npc_name,refid,extended_data::text AS extended_data,metadata::text AS metadata FROM public.core_npc_master WHERE id=900003')[0] ?? [],
        'plugins' => dev30_pg($admin, 'SELECT plugin_name,formid_prefix FROM public.game_plugins ORDER BY plugin_name,formid_prefix'),
    ];
    if (!is_string($baseline['player']) || $baseline['actor'] === [] || $baseline['plugins'] === []) throw new RuntimeException('synthetic_profile_baseline_missing');

    dev30_case($cases, 'adapter.resume_manual_readonly_and_retry', static function () use (&$ctx, $admin, $baseline): void {
        dev30_reset_fixture_inputs($admin, $baseline); dev30_seed_case_clock($admin);
        dev30_with_unprofiled_scope($ctx, $admin, static function () use (&$ctx, $admin): void {
            if ((int)dev30_scalar($admin, 'SELECT count(*)::text FROM plugins.correspondence_presence WHERE playthrough_id=$1', ['unprofiled']) !== 0) {
                throw new RuntimeException('unprofiled_presence_fixture_not_empty');
            }
            $resolvedPresence = dev30_run_child($ctx, 'resume_unprofiled_resolved_history_presence', $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'owner_fixture.php',
                ['--mode','seed-presence','--core-root',$ctx['coreRoot'],'--pg-socket',$ctx['pgSocket']]);
            $resolvedPresenceReceipt = json_decode(trim($resolvedPresence['stdout']), true, 32, JSON_THROW_ON_ERROR);
            dev30_assert(is_array($resolvedPresenceReceipt) && ($resolvedPresenceReceipt['ready'] ?? null) === true
                && ($resolvedPresenceReceipt['presenceExecutor'] ?? null) === 'Lydia', 'resolved_history_owner_presence_fixture_not_ready');
            $resolvedHistory = dev30_seed_resolved_manual_letters($ctx, 27);
            foreach ($resolvedHistory as $index => $letter) {
                $createdAt = sprintf('1999-01-01 00:00:%02d+00', $index + 1);
                dev30_pg($admin, 'UPDATE plugins.correspondence_documents SET created_at=$1::timestamptz WHERE document_id=$2::uuid',
                    [$createdAt, $letter['documentId']]);
            }
            $beforeResolvedRead = dev30_count_snapshot($admin);
            $resolvedRead = dev30_api($ctx, 'resume_unprofiled_resolved_history_read', 'correspondence.pending.read', [],
                ['authScopes' => ['compose'], 'pendingPages' => 2]);
            $resolvedPageOne = dev30_response($resolvedRead);
            $resolvedPageTwo = dev30_response($resolvedRead, 'pendingPage2');
            dev30_assert(count($resolvedHistory) === 27
                && ($resolvedPageOne['ok'] ?? null) === true && ($resolvedPageOne['data']['operations'] ?? null) === []
                && ($resolvedPageOne['data']['limited'] ?? null) === true && is_string($resolvedPageOne['data']['nextCursor'] ?? null)
                && ($resolvedPageTwo['ok'] ?? null) === true && ($resolvedPageTwo['data']['operations'] ?? null) === []
                && ($resolvedPageTwo['data']['limited'] ?? null) === false && ($resolvedPageTwo['data']['nextCursor'] ?? null) === null,
                'more_than_26_resolved_letters_did_not_reach_an_empty_final_page');
            dev30_assert(($resolvedPageOne['pendingContextReused'] ?? null) === true
                && ($resolvedPageTwo['pendingContextReused'] ?? null) === true,
                'resolved_history_continuation_did_not_reuse_authorized_context');
            dev30_assert_fence($resolvedRead['readFence'], false);
            dev30_assert($beforeResolvedRead === dev30_count_snapshot($admin)
                && ($resolvedRead['queryEvidence']['pendingScans'] ?? null) === 2
                && ($resolvedRead['queryEvidence']['pendingWatermarkChecks'] ?? null) === 4
                && ($resolvedRead['queryEvidence']['pendingRowCountChecks'] ?? null) === 2
                && ($resolvedRead['queryEvidence']['mutations'] ?? null) === 0,
                'resolved_history_discovery_wrote_rows_or_exceeded_two_bounded_pages');
            dev30_pg($admin, 'DELETE FROM plugins.correspondence_presence WHERE playthrough_id=$1', ['unprofiled']);

            $history = [];
            for ($index = 0; $index < 26; $index++) {
                $history[] = dev30_seed_manual_letter($ctx, 'Synthetic bounded paging row ' . ($index + 1) . ' in the unprofiled scope.');
            }
            foreach ($history as $index => $letter) {
                $createdAt = sprintf('2001-01-01 00:00:%02d+00', $index + 1);
                dev30_pg($admin, 'UPDATE plugins.correspondence_documents SET created_at=$1::timestamptz WHERE document_id=$2::uuid',
                    [$createdAt, $letter['documentId']]);
            }
            $malformedHistory = $history[array_key_last($history)];
            $wrongDocumentId = dev30_uuid();
            dev30_pg($admin, "UPDATE plugins.correspondence_documents SET payload=jsonb_set(payload,'{document_id}',$1::jsonb) WHERE document_id=$2::uuid",
                [json_encode($wrongDocumentId, JSON_THROW_ON_ERROR), $malformedHistory['documentId']]);

            $requestId = dev30_uuid();
            $compose = dev30_api($ctx, 'resume_unprofiled_gold_compose', 'correspondence.compose', [
                'operationId' => $requestId,
                'writerActorKey' => 'player',
                'signatureActorKey' => 'player',
                'recipientActorKey' => 'npc:900003',
                'body' => 'A synthetic TEST fixture letter carrying five hundred gold.',
                'inventoryRevision' => 'dev30-unprofiled-500-gold',
                'attachments' => [],
                'goldAmount' => 500,
            ], ['operationRequestId' => $requestId, 'authScopes' => ['compose']]);
            $composed = dev30_response($compose);
            dev30_assert(($composed['ok'] ?? null) === true && ($composed['data']['operationId'] ?? null) === $requestId
                && ($composed['data']['state'] ?? null) === 'queued' && ($composed['data']['canResume'] ?? null) === true
                && ($composed['data']['queuedReason'] ?? null) === 'executor_unavailable', 'unprofiled_gold_letter_not_authoritatively_queued');
            $document = dev30_document($admin, $requestId);
            $gold = array_values(array_filter($document['payload']['attachments'] ?? [], static fn(array $attachment): bool => ($attachment['kind'] ?? null) === 'gold'));
            dev30_assert(($document['payload']['playthrough_id'] ?? null) === 'unprofiled'
                && count($gold) === 1 && ($gold[0]['quantity'] ?? null) === 500, 'unprofiled_gold_document_scope_or_attachment_invalid');
            dev30_pg($admin, 'UPDATE plugins.correspondence_documents SET created_at=$1::timestamptz WHERE document_id=$2::uuid',
                ['2000-01-01 00:00:00+00', $document['id']]);

            $beforePending = dev30_count_snapshot($admin);
            $pending = dev30_api($ctx, 'resume_unprofiled_gold_pending', 'correspondence.pending.read', [], ['authScopes' => ['compose'], 'pendingPages' => 3]);
            $pendingResponse = dev30_response($pending);
            $pendingSecondPage = dev30_response($pending, 'pendingPage2');
            $pendingThirdPage = dev30_response($pending, 'pendingPage3');
            $goldMatches = array_values(array_filter($pendingSecondPage['data']['operations'] ?? [], static fn(array $row): bool => ($row['operationId'] ?? null) === $requestId));
            $unknownMatches = array_values(array_filter($pendingResponse['data']['operations'] ?? [], static fn(array $row): bool => ($row['operationId'] ?? null) === $malformedHistory['requestId']));
            $expectedEntryKeys = ['canResume','hasTransfer','operationId','operatorClosed','queuedReason','recipientLabel','state'];
            $entryKeys = is_array($goldMatches[0] ?? null) ? array_keys($goldMatches[0]) : [];
            sort($expectedEntryKeys); sort($entryKeys);
            $bootstrapProjection = dev30_response($pending, 'bootstrap');
            $firstCursor = $pendingResponse['data']['nextCursor'] ?? null;
            // Each cursor page scans 25 manual documents; owner-resolved history is omitted from operations.
            dev30_assert(($pendingResponse['ok'] ?? null) === true && count($pendingResponse['data']['operations'] ?? []) === 25
                && ($pendingResponse['data']['limited'] ?? null) === true && is_string($firstCursor)
                && ($pendingSecondPage['ok'] ?? null) === true && count($pendingSecondPage['data']['operations'] ?? []) === 2
                && ($pendingSecondPage['data']['limited'] ?? null) === true && is_string($pendingSecondPage['data']['nextCursor'] ?? null)
                && ($pendingThirdPage['ok'] ?? null) === true && ($pendingThirdPage['data']['operations'] ?? null) === []
                && ($pendingThirdPage['data']['limited'] ?? null) === false && ($pendingThirdPage['data']['nextCursor'] ?? null) === null,
                'unprofiled_pending_keyset_pages_did_not_complete_cleanly');
            dev30_assert(($bootstrapProjection['pendingMacKeyStored'] ?? null) === true
                && ($bootstrapProjection['pendingMacKeyNotExposed'] ?? null) === true
                && (dev30_response($pending)['pendingContextReused'] ?? null) === true
                && ($pendingSecondPage['pendingContextReused'] ?? null) === true
                && ($pendingThirdPage['pendingContextReused'] ?? null) === true,
                'pending_mac_key_privacy_or_same_context_continuation_proof_missing');
            dev30_assert(count($goldMatches) === 1 && $entryKeys === $expectedEntryKeys
                && ($goldMatches[0]['state'] ?? null) === 'queued' && ($goldMatches[0]['hasTransfer'] ?? null) === true
                && ($goldMatches[0]['canResume'] ?? null) === true && ($goldMatches[0]['queuedReason'] ?? null) === 'not_dispatched'
                && ($goldMatches[0]['operatorClosed'] ?? null) === false
                && !array_key_exists('body', $goldMatches[0]) && !array_key_exists('author', $goldMatches[0]), 'older_unprofiled_gold_pending_projection_invalid');
            dev30_assert(count($unknownMatches) === 1 && ($unknownMatches[0]['state'] ?? null) === 'unknown'
                && ($unknownMatches[0]['hasTransfer'] ?? null) === true && ($unknownMatches[0]['canResume'] ?? null) === false
                && ($unknownMatches[0]['queuedReason'] ?? null) === null,
                'older_malformed_record_was_lost_or_marked_transfer_safe');
            dev30_assert_fence($pending['readFence'], false);
            dev30_assert($beforePending === dev30_count_snapshot($admin), 'pending_read_mutated_owner_or_game_rows');
            dev30_assert(($pending['queryEvidence']['pendingScans'] ?? null) === 3
                && ($pending['queryEvidence']['pendingWatermarkChecks'] ?? null) === 6
                && ($pending['queryEvidence']['pendingRowCountChecks'] ?? null) === 3
                && ($pending['queryEvidence']['autonomyBatchChecks'] ?? null) === 3
                && ($pending['queryEvidence']['operationExists'] ?? null) === 3
                && ($pending['queryEvidence']['dispatchAnchorChecks'] ?? null) === 3
                && ($pending['queryEvidence']['mutations'] ?? null) === 0, 'pending_read_query_or_mutation_trace_invalid');

            $rotatedCursor = dev30_api($ctx, 'resume_unprofiled_gold_cursor_rotated', 'correspondence.pending.read', ['cursor' => $firstCursor], ['authScopes' => ['compose']]);
            $rotatedResponse = dev30_response($rotatedCursor);
            dev30_assert(($rotatedResponse['ok'] ?? null) === false && ($rotatedResponse['errorCode'] ?? null) === 'stale_revision'
                && ($rotatedCursor['queryEvidence']['pendingScans'] ?? null) === 0
                && ($rotatedCursor['queryEvidence']['mutations'] ?? null) === 0,
                'new_bootstrap_did_not_invalidate_old_pending_cursor');

            $legacyKeyProbe = dev30_api($ctx, 'resume_unprofiled_gold_legacy_key', 'correspondence.pending.read', [],
                ['authScopes' => ['compose'], 'legacyPendingKeyProbe' => true]);
            $legacyProbeRows = array_values(array_filter($legacyKeyProbe['responses'] ?? [], static fn(array $row): bool => ($row['event'] ?? null) === 'legacy_pending_key_probe'));
            dev30_assert((dev30_response($legacyKeyProbe, 'legacyOther')['errorCode'] ?? null) === 'not_found'
                && (dev30_response($legacyKeyProbe, 'legacyPending')['errorCode'] ?? null) === 'stale_context'
                && ($legacyProbeRows[0]['otherOperationAccepted'] ?? null) === true
                && ($legacyProbeRows[0]['pendingDiscoveryRefused'] ?? null) === true
                && ($legacyProbeRows[0]['missingKeyNotRewritten'] ?? null) === true,
                'legacy_context_without_private_cursor_key_was_not_scoped_to_pending_discovery');

            $attemptOne = dev30_uuid();
            $attemptTwo = dev30_uuid();
            $retry = dev30_api($ctx, 'resume_unprofiled_gold_queued_retry', 'correspondence.resume', [
                'operationId' => $requestId, 'attemptId' => $attemptOne,
            ], ['operationRequestId' => $attemptOne, 'nextResumeAttemptId' => $attemptTwo, 'authScopes' => ['compose']]);
            $retryResponses = array_values(array_filter($retry['responses'], static fn(array $row): bool => in_array($row['phase'] ?? null, ['operation','nextAttempt'], true)));
            dev30_assert(count($retryResponses) === 2, 'fresh_explicit_retry_result_missing');
            foreach ($retryResponses as $retryResponse) {
                dev30_assert(($retryResponse['ok'] ?? null) === true && ($retryResponse['data']['operationId'] ?? null) === $requestId
                    && ($retryResponse['data']['state'] ?? null) === 'queued' && ($retryResponse['data']['canResume'] ?? null) === true
                    && ($retryResponse['data']['queuedReason'] ?? null) === 'executor_unavailable', 'queued_retry_lost_original_letter_or_safe_reason');
            }
            dev30_assert(dev30_delivery_counts($admin, $document['id']) === ['documents' => 1, 'operations' => 0, 'commands' => 0],
                'queued_attempt_created_owner_operation_or_delivery');

            $presence = dev30_run_child($ctx, 'resume_unprofiled_gold_seed_presence', $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'owner_fixture.php',
                ['--mode','seed-presence','--core-root',$ctx['coreRoot'],'--pg-socket',$ctx['pgSocket']]);
            $presenceReceipt = json_decode(trim($presence['stdout']), true, 32, JSON_THROW_ON_ERROR);
            dev30_assert(is_array($presenceReceipt) && ($presenceReceipt['ready'] ?? null) === true
                && ($presenceReceipt['presenceExecutor'] ?? null) === 'Lydia', 'pinned_owner_presence_fixture_not_ready');

            $finalAttempt = dev30_uuid();
            $resumed = dev30_api($ctx, 'resume_unprofiled_gold_final', 'correspondence.resume', [
                'operationId' => $requestId, 'attemptId' => $finalAttempt,
            ], ['operationRequestId' => $finalAttempt, 'authScopes' => ['compose']]);
            $finalResponse = dev30_response($resumed);
            dev30_assert(($finalResponse['ok'] ?? null) === true && ($finalResponse['data']['operationId'] ?? null) === $requestId
                && ($finalResponse['data']['state'] ?? null) === 'dispatched', 'explicit_unprofiled_resume_did_not_dispatch_original_letter');
            dev30_assert(dev30_delivery_counts($admin, $document['id']) === ['documents' => 1, 'operations' => 1, 'commands' => 1],
                'explicit_unprofiled_resume_was_not_exactly_once');
            dev30_assert(($resumed['queryEvidence']['ownerWriterLocks'] ?? 0) >= 1
                && ($resumed['queryEvidence']['eventlogWriterLocks'] ?? 0) >= 1
                && ($resumed['queryEvidence']['operationExists'] ?? 0) >= 1
                && ($resumed['queryEvidence']['guardChecksUnderWriterFence'] ?? 0) >= 1,
                'resume_writer_fence_or_guard_trace_missing');

            dev30_pg($admin, "UPDATE plugins.correspondence_documents SET payload=jsonb_set(payload,'{operation_id}','null'::jsonb) WHERE document_id=$1::uuid",
                [$document['id']]);
            $beforeUnknownRediscovery = dev30_count_snapshot($admin);
            $unknownRediscovery = dev30_api($ctx, 'resume_unprofiled_gold_unknown_rediscovery', 'correspondence.pending.read', [],
                ['authScopes' => ['compose'], 'pendingPages' => 3]);
            $unknownGoldPage = dev30_response($unknownRediscovery, 'pendingPage2');
            $unknownGoldEndPage = dev30_response($unknownRediscovery, 'pendingPage3');
            $unknownGoldMatches = array_values(array_filter($unknownGoldPage['data']['operations'] ?? [], static fn(array $row): bool => ($row['operationId'] ?? null) === $requestId));
            dev30_assert(count($unknownGoldMatches) === 1 && ($unknownGoldMatches[0]['state'] ?? null) === 'unknown'
                && ($unknownGoldMatches[0]['hasTransfer'] ?? null) === true && ($unknownGoldMatches[0]['canResume'] ?? null) === false
                && ($unknownGoldMatches[0]['queuedReason'] ?? null) === null
                && ($unknownGoldPage['data']['limited'] ?? null) === true && is_string($unknownGoldPage['data']['nextCursor'] ?? null)
                && ($unknownGoldEndPage['data']['limited'] ?? null) === false && ($unknownGoldEndPage['data']['nextCursor'] ?? null) === null,
                'older_orphaned_500_gold_was_not_retained_as_unknown_transfer_risk');
            dev30_assert($beforeUnknownRediscovery === dev30_count_snapshot($admin)
                && ($unknownRediscovery['queryEvidence']['pendingRowCountChecks'] ?? null) === 3
                && ($unknownRediscovery['queryEvidence']['mutations'] ?? null) === 0,
                'unknown_gold_rediscovery_mutated_owner_or_game_rows');
        });
        dev30_reset_fixture_inputs($admin, $baseline);
    });

    dev30_case($cases, 'adapter.resume_guard_refusals', static function () use (&$ctx, $admin, $baseline): void {
        dev30_reset_fixture_inputs($admin, $baseline); dev30_seed_case_clock($admin);
        try {
            $companion = dev30_seed_companion($ctx, 'A scheduler-owned request must never enter manual recovery.', false);
            $companionBefore = dev30_delivery_counts($admin, $companion['documentId']);
            $beforeStaleIngress = dev30_count_snapshot($admin);
            $staleAttemptId = dev30_uuid();
            $staleIngress = dev30_api($ctx, 'resume_refuse_companion_stale_ingress', 'correspondence.resume', [
                'operationId' => $companion['requestId'], 'attemptId' => $staleAttemptId,
            ], ['operationRequestId' => $staleAttemptId, 'authScopes' => ['compose'], 'priorRequestGlobals' => 'captured-invalid']);
            $staleIngressResponse = dev30_response($staleIngress);
            dev30_assert(($staleIngressResponse['ok'] ?? null) === false && ($staleIngressResponse['errorCode'] ?? null) === 'stale_context'
                && !is_array($staleIngressResponse['data'] ?? null) && ($staleIngressResponse['globalStateRestored'] ?? null) === true
                && $beforeStaleIngress === dev30_count_snapshot($admin)
                && $companionBefore === dev30_delivery_counts($admin, $companion['documentId']),
                'stale_captured_resume_token_was_reinterpreted_or_mutated_owner_rows');

            $nullAttemptId = dev30_uuid();
            $nullIngress = dev30_api($ctx, 'resume_refuse_companion_null_ingress', 'correspondence.resume', [
                'operationId' => $companion['requestId'], 'attemptId' => $nullAttemptId,
            ], ['operationRequestId' => $nullAttemptId, 'authScopes' => ['compose'], 'priorRequestGlobals' => 'captured-null-token']);
            $nullIngressResponse = dev30_response($nullIngress);
            dev30_assert(($nullIngressResponse['ok'] ?? null) === false && ($nullIngressResponse['errorCode'] ?? null) === 'stale_context'
                && !is_array($nullIngressResponse['data'] ?? null) && ($nullIngressResponse['globalStateRestored'] ?? null) === true
                && $beforeStaleIngress === dev30_count_snapshot($admin),
                'already_captured_null_resume_token_was_reinterpreted_or_mutated_owner_rows');

            $beforePendingResume = dev30_count_snapshot($admin);
            dev30_pg($admin, 'UPDATE plugins.correspondence_restore_state SET pending=TRUE WHERE singleton IS TRUE');
            try {
                $pendingAttemptId = dev30_uuid();
                $pendingIngress = dev30_api($ctx, 'resume_refuse_companion_pending_restore', 'correspondence.resume', [
                    'operationId' => $companion['requestId'], 'attemptId' => $pendingAttemptId,
                ], ['operationRequestId' => $pendingAttemptId, 'authScopes' => ['compose']]);
            } finally {
                dev30_pg($admin, 'UPDATE plugins.correspondence_restore_state SET pending=FALSE WHERE singleton IS TRUE');
            }
            $pendingIngressResponse = dev30_response($pendingIngress);
            $pendingIngressRestore = dev30_pg($admin, 'SELECT pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
            dev30_assert(($pendingIngressResponse['ok'] ?? null) === false && ($pendingIngressResponse['errorCode'] ?? null) === 'stale_context'
                && !is_array($pendingIngressResponse['data'] ?? null) && ($pendingIngressResponse['globalStateRestored'] ?? null) === true
                && ($pendingIngressRestore[0]['pending'] ?? null) === 'f' && $beforePendingResume === dev30_count_snapshot($admin)
                && $companionBefore === dev30_delivery_counts($admin, $companion['documentId']),
                'pending_restore_resume_preflight_was_not_refused_read_only');

            $companionResume = dev30_api($ctx, 'resume_refuse_companion', 'correspondence.resume', [
                'operationId' => $companion['requestId'], 'attemptId' => dev30_uuid(),
            ], ['authScopes' => ['compose']]);
            $companionResponse = dev30_response($companionResume);
            dev30_assert(($companionResponse['ok'] ?? null) === false && ($companionResponse['errorCode'] ?? null) === 'conflict'
                && $companionBefore === dev30_delivery_counts($admin, $companion['documentId']), 'companion_owned_resume_was_not_refused_cleanly');

            $managedParent = dev30_seed_manual_letter($ctx, 'Synthetic TEST parent record for a managed-child ownership guard.');
            $managedChild = dev30_seed_manual_letter($ctx, 'Synthetic TEST child record must not enter manual recovery.');
            $sourceEventId = dev30_scalar($admin,
                "SELECT payload #>> '{anchor,rowid}' FROM plugins.correspondence_documents WHERE document_id=$1::uuid", [$managedParent['documentId']]);
            if (!is_string($sourceEventId) || !ctype_digit($sourceEventId) || (int)$sourceEventId < 1) {
                throw new RuntimeException('synthetic_autonomy_parent_anchor_missing');
            }
            dev30_pg($admin,
                "INSERT INTO plugins.correspondence_autonomy_decisions(playthrough_id,source_document_id,source_event_id,claim_id,request_id,root_document_id,depth,status,action,child_document_id,due_gamets,payload) VALUES($1,$2::uuid,$3::bigint,$4::uuid,$5::uuid,$2::uuid,0,'queued','reply',$6::uuid,1::numeric,'{\"reason_code\":\"queued_for_transit\"}'::jsonb)",
                [$managedChild['scope'], $managedParent['documentId'], $sourceEventId, dev30_uuid(), dev30_uuid(), $managedChild['documentId']]);
            $managedBefore = dev30_delivery_counts($admin, $managedChild['documentId']);
            $managedResume = dev30_api($ctx, 'resume_refuse_managed_child', 'correspondence.resume', [
                'operationId' => $managedChild['requestId'], 'attemptId' => dev30_uuid(),
            ], ['authScopes' => ['compose']]);
            $managedResponse = dev30_response($managedResume);
            dev30_assert(($managedResponse['ok'] ?? null) === false && ($managedResponse['errorCode'] ?? null) === 'conflict'
                && $managedBefore === dev30_delivery_counts($admin, $managedChild['documentId']), 'managed_child_resume_was_not_refused_cleanly');

            $closed = dev30_seed_manual_letter($ctx, 'An operator-closed queued letter remains visible but cannot resume.');
            $close = dev30_run_child($ctx, 'resume_fixture_close_queued', $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'owner_fixture.php',
                ['--mode','operator-close','--core-root',$ctx['coreRoot'],'--pg-socket',$ctx['pgSocket'],'--operation-id',$closed['requestId']]);
            $closeReceipt = json_decode(trim($close['stdout']), true, 32, JSON_THROW_ON_ERROR);
            $closedBefore = dev30_delivery_counts($admin, $closed['documentId']);
            $closedResume = dev30_api($ctx, 'resume_refuse_closed', 'correspondence.resume', [
                'operationId' => $closed['requestId'], 'attemptId' => dev30_uuid(),
            ], ['authScopes' => ['compose']]);
            $closedResponse = dev30_response($closedResume);
            dev30_assert(is_array($closeReceipt) && ($closeReceipt['ready'] ?? null) === true
                && ($closedResponse['ok'] ?? null) === false && ($closedResponse['errorCode'] ?? null) === 'conflict'
                && $closedBefore === dev30_delivery_counts($admin, $closed['documentId']), 'operator_closed_resume_was_not_refused_cleanly');

            $linked = dev30_seed_manual_letter($ctx, 'An operation-linked document must never be recovered as queued.');
            $linkedDispatch = dev23_companion_with_owner_runtime($ctx, static function ($store, $service) use ($linked): array {
                return $service->dispatch($linked['documentId']);
            });
            dev30_assert(($linkedDispatch['state'] ?? null) === 'dispatched', 'linked_operation_fixture_not_dispatched');
            $linkedOperationRows = dev30_pg($admin,
                'SELECT operation_id::text AS operation_id FROM plugins.correspondence_operations WHERE document_id=$1::uuid LIMIT 2', [$linked['documentId']]);
            dev30_assert(count($linkedOperationRows) === 1 && is_string($linkedOperationRows[0]['operation_id'] ?? null), 'linked_operation_fixture_missing');
            dev30_pg($admin, "UPDATE plugins.correspondence_documents SET payload=jsonb_set(payload,'{operation_id}','null'::jsonb) WHERE document_id=$1::uuid", [$linked['documentId']]);
            $linkedBefore = dev30_delivery_counts($admin, $linked['documentId']);
            $linkedResume = dev30_api($ctx, 'resume_refuse_orphan_operation', 'correspondence.resume', [
                'operationId' => $linked['requestId'], 'attemptId' => dev30_uuid(),
            ], ['authScopes' => ['compose']]);
            $linkedResponse = dev30_response($linkedResume);
            dev30_assert(($linkedResponse['ok'] ?? null) === false && ($linkedResponse['errorCode'] ?? null) === 'conflict'
                && $linkedBefore === dev30_delivery_counts($admin, $linked['documentId']), 'orphan_operation_resume_was_not_refused_cleanly');

            $anchor = dev30_seed_manual_letter($ctx, 'A persisted dispatch anchor without its operation must block recovery.');
            $anchorDispatch = dev23_companion_with_owner_runtime($ctx, static function ($store, $service) use ($anchor): array {
                return $service->dispatch($anchor['documentId']);
            });
            dev30_assert(($anchorDispatch['state'] ?? null) === 'dispatched', 'dispatch_anchor_fixture_not_dispatched');
            dev30_pg($admin, "UPDATE plugins.correspondence_documents SET payload=jsonb_set(payload,'{operation_id}','null'::jsonb) WHERE document_id=$1::uuid", [$anchor['documentId']]);
            dev30_pg($admin, 'DELETE FROM plugins.correspondence_operations WHERE document_id=$1::uuid', [$anchor['documentId']]);
            $anchorCount = dev30_scalar($admin,
                "SELECT count(*)::text FROM public.eventlog WHERE type='info' AND sess='correspondence_v1' AND data LIKE $1",
                ['{"document_id":"' . $anchor['documentId'] . '","operation_id":"________-____-____-____-____________"}']);
            $anchorBefore = dev30_delivery_counts($admin, $anchor['documentId']);
            $anchorResume = dev30_api($ctx, 'resume_refuse_orphan_dispatch_anchor', 'correspondence.resume', [
                'operationId' => $anchor['requestId'], 'attemptId' => dev30_uuid(),
            ], ['authScopes' => ['compose']]);
            $anchorResponse = dev30_response($anchorResume);
            dev30_assert((int)$anchorCount === 1 && ($anchorResponse['ok'] ?? null) === false
                && ($anchorResponse['errorCode'] ?? null) === 'conflict'
                && $anchorBefore === dev30_delivery_counts($admin, $anchor['documentId']), 'real_orphan_dispatch_anchor_did_not_block_resume');

            $unknownRequest = dev30_uuid();
            $outside = dev30_api($ctx, 'resume_refuse_unknown_scope_request', 'correspondence.resume', [
                'operationId' => $unknownRequest, 'attemptId' => dev30_uuid(),
            ], ['authScopes' => ['compose']]);
            $outsideResponse = dev30_response($outside);
            dev30_assert(($outsideResponse['ok'] ?? null) === false && ($outsideResponse['errorCode'] ?? null) === 'not_found',
                'resume_disclosed_or_mutated_an_out_of_scope_request');
        } finally { dev30_reset_fixture_inputs($admin, $baseline); }
    });

    dev30_case($cases, 'adapter.resume_concurrent_attempts', static function () use (&$ctx, $admin, $baseline): void {
        dev30_reset_fixture_inputs($admin, $baseline); dev30_seed_case_clock($admin);
        try {
            $queued = dev30_seed_manual_letter($ctx, 'Two synchronized explicit attempts share one queued manual request.');
            $attempts = [dev30_uuid(), dev30_uuid()];
            $racedResumes = dev30_concurrent_api($ctx, [
                ['caseId' => 'resume_race_attempt_a', 'operation' => 'correspondence.resume',
                    'payload' => ['operationId' => $queued['requestId'], 'attemptId' => $attempts[0]],
                    'options' => ['operationRequestId' => $attempts[0], 'authScopes' => ['compose']]],
                ['caseId' => 'resume_race_attempt_b', 'operation' => 'correspondence.resume',
                    'payload' => ['operationId' => $queued['requestId'], 'attemptId' => $attempts[1]],
                    'options' => ['operationRequestId' => $attempts[1], 'authScopes' => ['compose']]],
            ]);
            $racedResponses = array_map(static fn(array $result): array => dev30_response($result), $racedResumes);
            $successes = array_values(array_filter($racedResponses, static fn(array $response): bool => ($response['ok'] ?? null) === true
                && ($response['data']['operationId'] ?? null) === $queued['requestId'] && ($response['data']['state'] ?? null) === 'dispatched'));
            $conflicts = array_values(array_filter($racedResponses, static fn(array $response): bool => ($response['ok'] ?? null) === false
                && ($response['errorCode'] ?? null) === 'conflict'));
            dev30_assert(count($successes) === 1 && count($conflicts) === 1
                && dev30_delivery_counts($admin, $queued['documentId']) === ['documents' => 1, 'operations' => 1, 'commands' => 1],
                'concurrent_explicit_resumes_did_not_serialize_exactly_once');

            $scope = (string)$queued['scope'];
            dev30_pg($admin, 'DELETE FROM plugins.correspondence_presence WHERE playthrough_id=$1', [$scope]);
            $requestId = dev30_uuid();
            $composePayload = [
                'operationId' => $requestId, 'writerActorKey' => 'player', 'signatureActorKey' => 'player',
                'recipientActorKey' => 'npc:900003', 'body' => 'The same request races manual compose against explicit resume.',
                'inventoryRevision' => 'dev30-mixed-compose-resume', 'attachments' => [], 'goldAmount' => 0,
            ];
            $composed = dev30_api($ctx, 'resume_mixed_compose_seed', 'correspondence.compose', $composePayload,
                ['operationRequestId' => $requestId, 'authScopes' => ['compose']]);
            $composedResponse = dev30_response($composed);
            $mixedDocument = dev30_document($admin, $requestId);
            dev30_assert(($composedResponse['ok'] ?? null) === true && ($composedResponse['data']['state'] ?? null) === 'queued'
                && ($composedResponse['data']['canResume'] ?? null) === true && ($mixedDocument['payload']['playthrough_id'] ?? null) === $scope,
                'mixed_compose_resume_seed_was_not_queued_in_same_scope');
            $presence = dev30_run_child($ctx, 'resume_mixed_seed_presence', $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'owner_fixture.php',
                ['--mode','seed-presence','--core-root',$ctx['coreRoot'],'--pg-socket',$ctx['pgSocket']]);
            $presenceReceipt = json_decode(trim($presence['stdout']), true, 32, JSON_THROW_ON_ERROR);
            dev30_assert(is_array($presenceReceipt) && ($presenceReceipt['ready'] ?? null) === true
                && ($presenceReceipt['presenceExecutor'] ?? null) === 'Lydia', 'mixed_race_owner_presence_fixture_not_ready');

            $resumeAttempt = dev30_uuid();
            $mixedResults = dev30_concurrent_api($ctx, [
                ['caseId' => 'resume_mixed_race_compose', 'operation' => 'correspondence.compose',
                    'payload' => $composePayload,
                    'options' => ['operationRequestId' => $requestId, 'authScopes' => ['compose']]],
                ['caseId' => 'resume_mixed_race_resume', 'operation' => 'correspondence.resume',
                    'payload' => ['operationId' => $requestId, 'attemptId' => $resumeAttempt],
                    'options' => ['operationRequestId' => $resumeAttempt, 'authScopes' => ['compose']]],
            ]);
            foreach ($mixedResults as $result) {
                $response = dev30_response($result);
                dev30_assert(($response['ok'] ?? null) === true || (($response['ok'] ?? null) === false && ($response['errorCode'] ?? null) === 'conflict'),
                    'mixed_same_request_race_returned_an_unexpected_outcome');
            }
            dev30_assert((int)dev30_scalar($admin, 'SELECT count(*)::text FROM plugins.correspondence_documents WHERE request_id=$1::uuid', [$requestId]) === 1
                && dev30_delivery_counts($admin, $mixedDocument['id']) === ['documents' => 1, 'operations' => 1, 'commands' => 1],
                'mixed_same_request_compose_resume_created_duplicate_or_missing_delivery');
        } finally { dev30_reset_fixture_inputs($admin, $baseline); }
    });

    dev30_case($cases, 'adapter.resume_reason_projection', static function () use (&$ctx, $admin, $baseline): void {
        dev30_reset_fixture_inputs($admin, $baseline); dev30_seed_case_clock($admin);
        $scope = '';
        $presenceBaseline = [];
        $presenceModified = false;
        try {
            $scope = (string)dev23_companion_with_owner_runtime($ctx, static function ($store): string {
                $scope = $store->scope()['playthrough_id'] ?? null;
                if (!is_string($scope) || $scope === '') throw new RuntimeException('reason_projection_scope_missing');
                return $scope;
            });
            $presenceBaseline = dev30_pg($admin,
                'SELECT payload::text AS payload FROM plugins.correspondence_presence WHERE playthrough_id=$1', [$scope]);
            if (count($presenceBaseline) > 1 || (isset($presenceBaseline[0]) && !is_string($presenceBaseline[0]['payload'] ?? null))) {
                throw new RuntimeException('reason_projection_presence_baseline_invalid');
            }
            dev30_pg($admin, 'DELETE FROM plugins.correspondence_presence WHERE playthrough_id=$1', [$scope]);
            $presenceModified = true;

            $composeQueued = static function (string $caseId, string $body, int $goldAmount = 0) use (&$ctx, $admin, $scope): array {
                $requestId = dev30_uuid();
                $result = dev30_api($ctx, $caseId . '_compose', 'correspondence.compose', [
                    'operationId' => $requestId,
                    'writerActorKey' => 'player',
                    'signatureActorKey' => 'player',
                    'recipientActorKey' => 'npc:900003',
                    'body' => $body,
                    'inventoryRevision' => 'dev30-reason-' . $requestId,
                    'attachments' => [],
                    'goldAmount' => $goldAmount,
                ], ['operationRequestId' => $requestId, 'authScopes' => ['compose']]);
                $response = dev30_response($result);
                $document = dev30_document($admin, $requestId);
                dev30_assert(($response['ok'] ?? null) === true && ($response['data']['operationId'] ?? null) === $requestId
                    && ($response['data']['state'] ?? null) === 'queued' && ($response['data']['canResume'] ?? null) === true
                    && ($response['data']['queuedReason'] ?? null) === 'executor_unavailable'
                    && ($document['payload']['playthrough_id'] ?? null) === $scope,
                    'reason_projection_letter_was_not_queued_in_the_actual_scope');
                return ['requestId' => $requestId, 'documentId' => (string)$document['id']];
            };
            $resume = static function (array $letter, string $caseId) use (&$ctx): array {
                $attemptId = dev30_uuid();
                return dev30_api($ctx, $caseId, 'correspondence.resume', [
                    'operationId' => $letter['requestId'], 'attemptId' => $attemptId,
                ], ['operationRequestId' => $attemptId, 'authScopes' => ['compose']]);
            };
            $assertReason = static function (array $response, string $requestId, string $reason, bool $canResume): void {
                $expectedKeys = ['canResume','companionSchedulerOwned','operationId','operatorClosed','queuedReason','state'];
                $actualKeys = array_keys($response['data'] ?? []);
                sort($expectedKeys, SORT_STRING); sort($actualKeys, SORT_STRING);
                dev30_assert(($response['ok'] ?? null) === true && ($response['data']['operationId'] ?? null) === $requestId
                    && ($response['data']['state'] ?? null) === 'queued' && ($response['data']['queuedReason'] ?? null) === $reason
                    && ($response['data']['canResume'] ?? null) === $canResume && $actualKeys === $expectedKeys,
                    'actual_owner_failure_did_not_project_its_fixed_reason_code');
            };

            $executorLetter = $composeQueued('resume_reason_executor', 'The actual owner has no observed executor for this request.');
            $rendererLetter = $composeQueued('resume_reason_renderer', str_repeat('W', 1800));
            $identityLetter = $composeQueued('resume_reason_identity', 'The actual owner identity is checked again during dispatch.');
            $mappingLetter = $composeQueued('resume_reason_mapping', 'The actual owner validates the gold plugin mapping.', 500);

            $beforeExecutor = dev30_count_snapshot($admin);
            $executorResponse = dev30_response($resume($executorLetter, 'resume_reason_executor_attempt'));
            $assertReason($executorResponse, $executorLetter['requestId'], 'executor_unavailable', true);
            dev30_assert($beforeExecutor === dev30_count_snapshot($admin)
                && dev30_delivery_counts($admin, $executorLetter['documentId']) === ['documents' => 1, 'operations' => 0, 'commands' => 0],
                'executor_unavailable_projection_created_a_transfer');

            $beforeIdentity = dev30_count_snapshot($admin);
            dev30_pg($admin, 'UPDATE public.core_npc_master SET npc_name=$1 WHERE id=900003', ['Changed Lydia (synthetic reason fixture)']);
            try {
                $identityResponse = dev30_response($resume($identityLetter, 'resume_reason_identity_attempt'));
            } finally {
                dev30_pg($admin, 'UPDATE public.core_npc_master SET npc_name=$1 WHERE id=900003', [$baseline['actor']['npc_name']]);
            }
            $assertReason($identityResponse, $identityLetter['requestId'], 'identity_changed', false);
            dev30_assert($beforeIdentity === dev30_count_snapshot($admin)
                && dev30_delivery_counts($admin, $identityLetter['documentId']) === ['documents' => 1, 'operations' => 0, 'commands' => 0],
                'identity_changed_projection_created_a_transfer');

            $presence = dev30_run_child($ctx, 'resume_reason_seed_presence', $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'owner_fixture.php',
                ['--mode','seed-presence','--core-root',$ctx['coreRoot'],'--pg-socket',$ctx['pgSocket']]);
            $presenceReceipt = json_decode(trim($presence['stdout']), true, 32, JSON_THROW_ON_ERROR);
            dev30_assert(is_array($presenceReceipt) && ($presenceReceipt['ready'] ?? null) === true
                && ($presenceReceipt['presenceExecutor'] ?? null) === 'Lydia', 'reason_projection_owner_presence_fixture_not_ready');

            $beforeRenderer = dev30_count_snapshot($admin);
            $rendererResponse = dev30_response($resume($rendererLetter, 'resume_reason_renderer_attempt'));
            $assertReason($rendererResponse, $rendererLetter['requestId'], 'renderer_unavailable', true);
            dev30_assert($beforeRenderer === dev30_count_snapshot($admin)
                && dev30_delivery_counts($admin, $rendererLetter['documentId']) === ['documents' => 1, 'operations' => 0, 'commands' => 0],
                'renderer_failure_projection_created_a_transfer');

            $skyrimMappings = array_values(array_filter($baseline['plugins'], static fn(array $row): bool =>
                is_string($row['plugin_name'] ?? null) && strcasecmp($row['plugin_name'], 'Skyrim.esm') === 0));
            if (count($skyrimMappings) !== 1) throw new RuntimeException('reason_projection_gold_mapping_fixture_missing');
            $beforeMapping = dev30_count_snapshot($admin);
            try {
                $removedMapping = dev30_pg($admin,
                    'DELETE FROM public.game_plugins WHERE plugin_name=$1 RETURNING plugin_name', [$skyrimMappings[0]['plugin_name']]);
                if (count($removedMapping) !== 1) throw new RuntimeException('reason_projection_gold_mapping_not_removed');
                $mappingResponse = dev30_response($resume($mappingLetter, 'resume_reason_mapping_attempt'));
            } finally {
                dev30_reset_fixture_inputs($admin, $baseline);
            }
            $assertReason($mappingResponse, $mappingLetter['requestId'], 'mapping_unavailable', true);
            dev30_assert($beforeMapping === dev30_count_snapshot($admin)
                && dev30_delivery_counts($admin, $mappingLetter['documentId']) === ['documents' => 1, 'operations' => 0, 'commands' => 0],
                'mapping_failure_projection_created_a_transfer');
        } finally {
            dev30_reset_fixture_inputs($admin, $baseline);
            if ($scope !== '' && $presenceModified) {
                dev30_pg($admin, 'DELETE FROM plugins.correspondence_presence WHERE playthrough_id=$1', [$scope]);
                if (count($presenceBaseline) === 1) {
                    dev30_pg($admin, 'INSERT INTO plugins.correspondence_presence(playthrough_id,payload) VALUES($1,$2::jsonb)',
                        [$scope, $presenceBaseline[0]['payload']]);
                }
                $restoredPresence = dev30_pg($admin,
                    'SELECT payload::text AS payload FROM plugins.correspondence_presence WHERE playthrough_id=$1', [$scope]);
                if ($restoredPresence !== $presenceBaseline) throw new RuntimeException('reason_projection_presence_not_restored');
            }
        }
    });

    dev30_case($cases, 'adapter.pending_restart_projection', static function () use (&$ctx, $admin, $baseline): void {
        dev30_reset_fixture_inputs($admin, $baseline); dev30_seed_case_clock($admin);
        try {
            $queued = dev30_seed_manual_letter($ctx, 'A queued manual letter survives restart discovery.');
            $unknown = dev30_seed_manual_letter($ctx, 'An inconsistent operation reference stays transfer-risk unknown.');
            dev30_pg($admin, 'UPDATE plugins.correspondence_documents SET payload=jsonb_set(payload,\'{operation_id}\',$1::jsonb) WHERE document_id=$2::uuid',
                [json_encode(dev30_uuid(), JSON_THROW_ON_ERROR), $unknown['documentId']]);

            $closed = dev30_seed_manual_letter($ctx, 'A closed queued letter remains visible after restart.');
            $closedRun = dev30_run_child($ctx, 'pending_restart_close', $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'owner_fixture.php',
                ['--mode','operator-close','--core-root',$ctx['coreRoot'],'--pg-socket',$ctx['pgSocket'],'--operation-id',$closed['requestId']]);
            $closedReceipt = json_decode(trim($closedRun['stdout']), true, 32, JSON_THROW_ON_ERROR);

            $requestDispatched = dev30_uuid();
            $dispatched = dev30_api($ctx, 'pending_restart_compose_dispatched', 'correspondence.compose', [
                'operationId' => $requestDispatched, 'writerActorKey' => 'player', 'signatureActorKey' => 'player',
                'recipientActorKey' => 'npc:900003', 'body' => 'A real owner-dispatched letter remains transfer-risk pending.',
                'inventoryRevision' => 'dev30-pending-dispatched',
                'attachments' => [['form_id' => 'Skyrim.esm|00000001', 'quantity' => 2]], 'goldAmount' => 0,
            ], ['operationRequestId' => $requestDispatched, 'authScopes' => ['compose']]);
            $dispatchedDocument = dev30_document($admin, $requestDispatched);
            dev30_assert((dev30_response($dispatched)['data']['state'] ?? null) === 'dispatched', 'pending_dispatched_fixture_not_dispatched');

            $requestPartial = dev30_uuid();
            $partialCompose = dev30_api($ctx, 'pending_restart_compose_partial', 'correspondence.compose', [
                'operationId' => $requestPartial, 'writerActorKey' => 'player', 'signatureActorKey' => 'player',
                'recipientActorKey' => 'npc:900003', 'body' => 'A partial receipt remains transfer-risk pending.',
                'inventoryRevision' => 'dev30-pending-partial',
                'attachments' => [['form_id' => 'Skyrim.esm|00000001', 'quantity' => 2]], 'goldAmount' => 0,
            ], ['operationRequestId' => $requestPartial, 'authScopes' => ['compose']]);
            dev30_assert((dev30_response($partialCompose)['data']['state'] ?? null) === 'dispatched', 'pending_partial_fixture_not_dispatched');
            $partialReceipt = dev30_run_child($ctx, 'pending_restart_seed_partial', $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'seed_unknown_receipt.php',
                ['--scenario','partial','--operation-id',$requestPartial]);
            $partialReceiptData = json_decode(trim($partialReceipt['stdout']), true, 32, JSON_THROW_ON_ERROR);
            dev30_assert(is_array($partialReceiptData) && ($partialReceiptData['accepted'] ?? null) === true, 'pending_partial_fixture_receipt_not_accepted');

            $before = dev30_count_snapshot($admin);
            $read = dev30_api($ctx, 'pending_restart_projection_read', 'correspondence.pending.read', [], ['authScopes' => ['compose']]);
            $response = dev30_response($read);
            $byRequest = [];
            foreach ($response['data']['operations'] ?? [] as $entry) if (is_array($entry) && is_string($entry['operationId'] ?? null)) $byRequest[$entry['operationId']] = $entry;
            $queuedEntry = $byRequest[$queued['requestId']] ?? [];
            $unknownEntry = $byRequest[$unknown['requestId']] ?? [];
            $closedEntry = $byRequest[$closed['requestId']] ?? [];
            $dispatchedEntry = $byRequest[$requestDispatched] ?? [];
            $partialEntry = $byRequest[$requestPartial] ?? [];
            $exactKeys = ['canResume','hasTransfer','operationId','operatorClosed','queuedReason','recipientLabel','state'];
            sort($exactKeys);
            foreach ([$queuedEntry, $unknownEntry, $closedEntry, $dispatchedEntry, $partialEntry] as $entry) {
                $keys = array_keys($entry); sort($keys);
                dev30_assert($keys === $exactKeys && !array_key_exists('body', $entry) && !array_key_exists('author', $entry),
                    'pending_restart_entry_shape_or_privacy_invalid');
            }
            dev30_assert(($response['ok'] ?? null) === true && ($response['data']['limited'] ?? null) === false
                && ($queuedEntry['state'] ?? null) === 'queued' && ($queuedEntry['canResume'] ?? null) === true
                && ($queuedEntry['queuedReason'] ?? null) === 'not_dispatched', 'pending_restart_queued_projection_invalid');
            dev30_assert(($unknownEntry['state'] ?? null) === 'unknown' && ($unknownEntry['hasTransfer'] ?? null) === true
                && ($unknownEntry['canResume'] ?? null) === false && ($unknownEntry['queuedReason'] ?? null) === null,
                'pending_restart_unknown_projection_invalid');
            dev30_assert(($closedEntry['operatorClosed'] ?? null) === true && ($closedEntry['canResume'] ?? null) === false,
                'pending_restart_closed_state_hidden_or_resumable');
            dev30_assert(($dispatchedEntry['state'] ?? null) === 'dispatched' && ($dispatchedEntry['hasTransfer'] ?? null) === true
                && ($dispatchedEntry['canResume'] ?? null) === false && ($dispatchedEntry['queuedReason'] ?? null) === null,
                'pending_restart_dispatched_projection_invalid');
            dev30_assert(($partialEntry['state'] ?? null) === 'partial' && ($partialEntry['hasTransfer'] ?? null) === true
                && ($partialEntry['canResume'] ?? null) === false && ($partialEntry['queuedReason'] ?? null) === null,
                'pending_restart_partial_projection_invalid');
            dev30_assert_fence($read['readFence'], false);
            dev30_assert($before === dev30_count_snapshot($admin), 'pending_restart_discovery_mutated_owner_or_game_rows');
            dev30_assert(($read['queryEvidence']['pendingScans'] ?? null) === 1
                && ($read['queryEvidence']['mutations'] ?? null) === 0, 'pending_restart_query_or_mutation_trace_invalid');
        } finally { dev30_reset_fixture_inputs($admin, $baseline); }
    });

    dev30_case($cases, 'adapter.pending_limited_and_failure', static function () use (&$ctx, $admin, $baseline): void {
        dev30_reset_fixture_inputs($admin, $baseline); dev30_seed_case_clock($admin);
        try {
            $history = [];
            for ($index = 0; $index < 26; $index++) {
                $history[] = dev30_seed_manual_letter($ctx, 'A bounded pending-discovery fixture row ' . ($index + 1) . '.');
            }
            foreach ($history as $index => $letter) {
                $createdAt = sprintf('2099-01-01 00:00:%02d+00', $index + 1);
                dev30_pg($admin, 'UPDATE plugins.correspondence_documents SET created_at=$1::timestamptz WHERE document_id=$2::uuid',
                    [$createdAt, $letter['documentId']]);
            }
            $offset = @filesize($ctx['pgLogPath']);
            if (!is_int($offset)) throw new RuntimeException('postgres_log_unreadable');
            $child = dev30_start_child($ctx, 'pending_new_letter_between_pages', $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'api_driver.php', [], null,
                'correspondence.pending.read', [], ['authScopes' => ['compose'], 'pendingPages' => 2, 'pauseAfterPendingPage' => true]);
            $release = null;
            try {
                $release = dev30_wait_for_pending_page($ctx, 'pending_new_letter_between_pages');
                $newer = dev30_seed_manual_letter($ctx, 'A new manual request appears between pending pages.');
                dev30_pg($admin, 'UPDATE plugins.correspondence_documents SET created_at=$1::timestamptz WHERE document_id=$2::uuid',
                    ['2100-01-01 00:00:00+00', $newer['documentId']]);
                $beforeContinuation = dev30_count_snapshot($admin);
                dev30_release_pending_page($release);
                $read = dev30_api_result($ctx, 'correspondence.pending.read', $offset, dev30_finish_child($ctx, $child));
                $child = null;
            } finally {
                if (is_array($child)) {
                    if (is_string($release) && !file_exists($release) && !is_link($release)) {
                        try { dev30_release_pending_page($release); } catch (Throwable) {}
                    }
                    try { dev30_finish_child($ctx, $child); } catch (Throwable) {}
                }
            }
            $response = dev30_response($read);
            $continuation = dev30_response($read, 'pendingPage2');
            dev30_assert(($response['ok'] ?? null) === true && count($response['data']['operations'] ?? []) === 25
                && ($response['data']['limited'] ?? null) === true && is_string($response['data']['nextCursor'] ?? null)
                && ($continuation['ok'] ?? null) === false && ($continuation['errorCode'] ?? null) === 'stale_revision',
                'new_document_between_pages_did_not_require_pending_refresh');
            dev30_assert_fence($read['readFence'], false);
            dev30_assert(($read['queryEvidence']['pendingScans'] ?? null) === 1
                && ($read['queryEvidence']['pendingWatermarkChecks'] ?? null) === 2
                && ($read['queryEvidence']['pendingRowCountChecks'] ?? null) === 2
                && ($read['queryEvidence']['mutations'] ?? null) === 0
                && $beforeContinuation === dev30_count_snapshot($admin), 'pending_watermark_rejection_was_not_bounded_read_only');

            $backdatedHistory = [];
            for ($index = 0; $index < 26; $index++) {
                $backdatedHistory[] = dev30_seed_manual_letter($ctx, 'A deterministic backdated pending-history fixture row ' . ($index + 1) . '.');
            }
            foreach ($backdatedHistory as $index => $letter) {
                $createdAt = sprintf('2200-01-01 00:00:%02d+00', $index + 1);
                dev30_pg($admin, 'UPDATE plugins.correspondence_documents SET created_at=$1::timestamptz WHERE document_id=$2::uuid',
                    [$createdAt, $letter['documentId']]);
            }
            $backdatedOffset = @filesize($ctx['pgLogPath']);
            if (!is_int($backdatedOffset)) throw new RuntimeException('postgres_log_unreadable');
            $backdatedChild = dev30_start_child($ctx, 'pending_backdated_manual_insert', $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'api_driver.php', [], null,
                'correspondence.pending.read', [], ['authScopes' => ['compose'], 'pendingPages' => 2, 'pauseAfterPendingPage' => true]);
            $backdatedRelease = null;
            try {
                $backdatedRelease = dev30_wait_for_pending_page($ctx, 'pending_backdated_manual_insert');
                $scope = $backdatedHistory[0]['scope'];
                $pageOneRows = dev30_pg($admin,
                    "SELECT d.created_at::text AS created_at,d.document_id::text AS document_id FROM plugins.correspondence_documents d "
                        . "WHERE d.playthrough_id=$1 AND d.payload->'payer'->>'kind'='player' "
                        . "ORDER BY d.created_at DESC,d.document_id DESC LIMIT 25",
                    [$scope]);
                $manualCountBeforeLateInsert = dev30_scalar($admin,
                    "SELECT count(*)::text FROM plugins.correspondence_documents d "
                        . "WHERE d.playthrough_id=$1 AND d.payload->'payer'->>'kind'='player'",
                    [$scope]);
                if (count($pageOneRows) !== 25 || !is_string($manualCountBeforeLateInsert)
                    || ($pageOneRows[0]['document_id'] ?? null) !== $backdatedHistory[25]['documentId']
                    || ($pageOneRows[24]['document_id'] ?? null) !== $backdatedHistory[1]['documentId']) {
                    throw new RuntimeException('backdated_fixture_page_tuples_invalid');
                }
                $upperTuple = $pageOneRows[0];
                $lastScannedTuple = $pageOneRows[24];
                $late = dev30_seed_manual_letter($ctx, 'A late committing manual request falls inside the already-scanned keyset range.');
                dev30_pg($admin, 'UPDATE plugins.correspondence_documents SET created_at=$1::timestamptz WHERE document_id=$2::uuid',
                    ['2200-01-01 00:00:02.500000+00', $late['documentId']]);
                $lateInterval = dev30_pg($admin,
                    "SELECT CASE WHEN (late.created_at,late.document_id)<($3::timestamptz,$4::uuid) THEN 1 ELSE 0 END AS below_upper,"
                        . "CASE WHEN (late.created_at,late.document_id)>($5::timestamptz,$6::uuid) THEN 1 ELSE 0 END AS above_last_scanned,"
                        . "(SELECT top.document_id::text FROM plugins.correspondence_documents top WHERE top.playthrough_id=$2 "
                        . "AND top.payload->'payer'->>'kind'='player' ORDER BY top.created_at DESC,top.document_id DESC LIMIT 1) AS current_upper_id,"
                        . "(SELECT count(*)::text FROM plugins.correspondence_documents d WHERE d.playthrough_id=$2 "
                        . "AND d.payload->'payer'->>'kind'='player') AS manual_count "
                        . "FROM plugins.correspondence_documents late WHERE late.document_id=$1::uuid AND late.playthrough_id=$2",
                    [$late['documentId'], $scope, $upperTuple['created_at'], $upperTuple['document_id'],
                        $lastScannedTuple['created_at'], $lastScannedTuple['document_id']]);
                dev30_assert(count($lateInterval) === 1 && ($lateInterval[0]['below_upper'] ?? null) === '1'
                    && ($lateInterval[0]['above_last_scanned'] ?? null) === '1'
                    && ($lateInterval[0]['current_upper_id'] ?? null) === $upperTuple['document_id']
                    && ($lateInterval[0]['manual_count'] ?? null) === (string)((int)$manualCountBeforeLateInsert + 1),
                    'backdated_fixture_insert_is_not_inside_the_actual_scanned_interval');
                $beforeBackdatedContinuation = dev30_count_snapshot($admin);
                dev30_release_pending_page($backdatedRelease);
                $backdatedRead = dev30_api_result($ctx, 'correspondence.pending.read', $backdatedOffset, dev30_finish_child($ctx, $backdatedChild));
                $backdatedChild = null;
            } finally {
                if (is_array($backdatedChild)) {
                    if (is_string($backdatedRelease) && !file_exists($backdatedRelease) && !is_link($backdatedRelease)) {
                        try { dev30_release_pending_page($backdatedRelease); } catch (Throwable) {}
                    }
                    try { dev30_finish_child($ctx, $backdatedChild); } catch (Throwable) {}
                }
            }
            $backdatedFirstPage = dev30_response($backdatedRead);
            $backdatedContinuation = dev30_response($backdatedRead, 'pendingPage2');
            dev30_assert(($backdatedFirstPage['ok'] ?? null) === true && count($backdatedFirstPage['data']['operations'] ?? []) === 25
                && ($backdatedFirstPage['data']['limited'] ?? null) === true && is_string($backdatedFirstPage['data']['nextCursor'] ?? null)
                && ($backdatedContinuation['ok'] ?? null) === false && ($backdatedContinuation['errorCode'] ?? null) === 'stale_revision',
                'backdated_late_manual_insert_was_missed_by_a_steady_upper_watermark');
            dev30_assert_fence($backdatedRead['readFence'], false);
            dev30_assert(($backdatedRead['queryEvidence']['pendingScans'] ?? null) === 1
                && ($backdatedRead['queryEvidence']['pendingWatermarkChecks'] ?? null) === 2
                && ($backdatedRead['queryEvidence']['pendingRowCountChecks'] ?? null) === 2
                && ($backdatedRead['queryEvidence']['mutations'] ?? null) === 0
                && $beforeBackdatedContinuation === dev30_count_snapshot($admin),
                'backdated_cursor_rejection_scanned_candidates_or_mutated_owner_rows');

            $restore = dev30_pg($admin, 'SELECT epoch::text AS epoch,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
            if (count($restore) !== 1 || !is_string($restore[0]['epoch'] ?? null) || ($restore[0]['pending'] ?? null) !== 'f') {
                throw new RuntimeException('pending_restore_failure_fixture_not_ready');
            }
            $beforeRestoreFailure = dev30_count_snapshot($admin);
            try {
                dev30_pg($admin, 'UPDATE plugins.correspondence_restore_state SET pending=TRUE WHERE singleton IS TRUE');
                $failed = dev30_api($ctx, 'pending_restore_failure', 'correspondence.pending.read', [], ['authScopes' => ['compose']]);
                $failedResponse = dev30_response($failed);
                dev30_assert(($failedResponse['ok'] ?? null) === false && ($failedResponse['errorCode'] ?? null) === 'stale_context'
                    && !is_array($failedResponse['data'] ?? null), 'pending_discovery_failure_was_hidden_as_empty_success');
                dev30_assert_fence($failed['readFence'], true);
            } finally {
                dev30_pg($admin, 'UPDATE plugins.correspondence_restore_state SET pending=FALSE WHERE singleton IS TRUE');
            }
            $restoreAfter = dev30_pg($admin, 'SELECT epoch::text AS epoch,pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
            dev30_assert(count($restoreAfter) === 1 && $restoreAfter[0] === $restore[0]
                && $beforeRestoreFailure === dev30_count_snapshot($admin), 'pending_failure_fixture_state_or_rows_not_restored');
        } finally { dev30_reset_fixture_inputs($admin, $baseline); }
    });

    // Actual Department reader rollback, then same-document recovery.
    dev30_case($cases, 'adapter.unavailable_player_read_recovery', static function () use (&$ctx, $admin, $baseline): void {
        dev30_reset_fixture_inputs($admin, $baseline); dev30_seed_case_clock($admin);
        $seed = dev30_seed_companion($ctx, 'A scheduler-owned private letter.', false);
        try {
            dev30_pg($admin, "UPDATE public.core_player SET value='' WHERE id='player_name'");
            $before = dev30_count_snapshot($admin);
            $failed = dev30_api($ctx, 'sf30_unavailable_player', 'correspondence.reconcile', ['operationId' => $seed['requestId']], ['authScopes' => ['compose']]);
            $refused = dev30_response($failed);
            dev30_assert(($refused['ok'] ?? null) === false && ($refused['errorCode'] ?? null) === 'unavailable', 'player_unavailability_misclassified');
            dev30_assert_fence($failed['readFence'], true);
            dev30_pg($admin, 'UPDATE public.core_player SET value=$1 WHERE id=\'player_name\'', [$baseline['player']]);
            $recovered = dev30_api($ctx, 'sf30_player_recovery', 'correspondence.reconcile', ['operationId' => $seed['requestId']], ['authScopes' => ['compose']]);
            $ok = dev30_response($recovered);
            dev30_assert(($ok['ok'] ?? null) === true && ($ok['data']['state'] ?? null) === 'queued'
                && ($ok['data']['companionSchedulerOwned'] ?? null) === true, 'same_document_reader_recovery_failed');
            dev30_assert_fence($recovered['readFence'], false);
            dev30_assert($before === dev30_count_snapshot($admin), 'reader_rollback_or_recovery_mutated_rows');
        } finally { dev30_reset_fixture_inputs($admin, $baseline); }
    });

    dev30_case($cases, 'adapter.manual_compose_scheduler_no_dispatch', static function () use (&$ctx, $admin, $baseline): void {
        dev30_reset_fixture_inputs($admin, $baseline); dev30_seed_case_clock($admin);
        $seed = dev30_seed_companion($ctx, 'A scheduler-owned private letter.', false);
        $before = dev30_count_snapshot($admin);
        dev23_companion_with_owner_runtime($ctx, static function ($store, $service) use ($seed): void {
            foreach (['dispatch','retry'] as $method) {
                try { $service->{$method}($seed['documentId']); throw new Dev30PgFailure('manual_scheduler_bypass_allowed'); }
                catch (DomainException $error) { dev30_assert($error->getMessage() === 'companion_scheduler_owned', 'manual_scheduler_bypass_wrong_refusal'); }
            }
        });
        $manualId = $seed['requestId'];
        $manual = dev30_api($ctx, 'sf30_manual_same_request', 'correspondence.compose', [
            'operationId' => $manualId, 'writerActorKey' => 'npc:900003', 'signatureActorKey' => 'npc:900003',
            'recipientActorKey' => 'player', 'body' => 'A manual retry probe.', 'inventoryRevision' => 'dev30-manual-probe',
            'attachments' => [], 'goldAmount' => 0,
        ], ['operationRequestId' => $manualId, 'authScopes' => ['compose']]);
        $response = dev30_response($manual);
        dev30_assert(($response['ok'] ?? null) === false && ($response['errorCode'] ?? null) === 'conflict', 'manual_compose_replaced_scheduler_document');
        dev30_assert($before === dev30_count_snapshot($admin), 'manual_compose_created_duplicate_or_dispatched');
        dev30_reset_fixture_inputs($admin, $baseline);
    });

    dev30_case($cases, 'adapter.request_id_repeat_no_resend', static function () use (&$ctx, $admin, $baseline): void {
        dev30_reset_fixture_inputs($admin, $baseline); dev30_seed_case_clock($admin);
        $requestId = dev30_uuid();
        $payload = ['operationId' => $requestId, 'writerActorKey' => 'player', 'recipientActorKey' => 'npc:900003',
            'body' => 'A bounded repeated compose request.', 'inventoryRevision' => 'dev30-repeat', 'attachments' => [], 'goldAmount' => 0];
        $result = dev30_api($ctx, 'sf30_repeat_request', 'correspondence.compose', $payload,
            ['operationRequestId' => $requestId, 'repeatSameRequest' => true, 'authScopes' => ['compose']]);
        $responses = array_values(array_filter($result['responses'], static fn(array $row): bool => in_array($row['phase'] ?? null, ['operation','repeat'], true)));
        dev30_assert(count($responses) === 2 && ($responses[0]['ok'] ?? null) === true && ($responses[1]['ok'] ?? null) === true, 'repeated_compose_response_invalid');
        $document = dev30_document($admin, $requestId);
        $ops = (int)dev30_scalar($admin, 'SELECT count(*)::text FROM plugins.correspondence_operations WHERE document_id=$1::uuid', [$document['id']]);
        $commands = (int)dev30_scalar($admin, "SELECT count(*)::text FROM public.responselog WHERE action='command' AND text LIKE '%ExtCmdCHIMCorrespondenceBridge_Deliver@%' AND text LIKE $1", ['%' . $document['id'] . '%']);
        dev30_assert($ops === 1 && $commands === 1, 'repeated_compose_duplicated_operation_or_command');
        dev30_assert((int)dev30_scalar($admin, 'SELECT count(*)::text FROM plugins.correspondence_documents WHERE request_id=$1::uuid', [$requestId]) === 1, 'repeated_compose_duplicated_document');
        dev30_reset_fixture_inputs($admin, $baseline);
    });

    dev30_case($cases, 'adapter.unknown_partial_reconcile_no_resend', static function () use (&$ctx, $admin, $baseline): void {
        dev30_reset_fixture_inputs($admin, $baseline); dev30_seed_case_clock($admin);
        $requestId = dev30_uuid();
        $payload = ['operationId' => $requestId, 'writerActorKey' => 'player', 'recipientActorKey' => 'npc:900003',
            'body' => 'A partial UNKNOWN receipt probe.', 'inventoryRevision' => 'dev30-partial',
            'attachments' => [['form_id' => 'Skyrim.esm|00000001', 'quantity' => 2]], 'goldAmount' => 0];
        $compose = dev30_api($ctx, 'sf30_partial_compose', 'correspondence.compose', $payload,
            ['operationRequestId' => $requestId, 'authScopes' => ['compose']]);
        $composeResponse = dev30_response($compose);
        dev30_assert(($composeResponse['ok'] ?? null) === true && ($composeResponse['data']['state'] ?? null) === 'dispatched', 'partial_probe_not_dispatched');
        $seed = dev30_run_child($ctx, 'sf30_partial_receipt', $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'seed_unknown_receipt.php',
            ['--scenario','partial','--operation-id',$requestId]);
        $receipt = json_decode(trim($seed['stdout']), true, 32, JSON_THROW_ON_ERROR);
        dev30_assert(is_array($receipt) && ($receipt['accepted'] ?? null) === true, 'partial_receipt_not_seeded');
        $before = dev30_count_snapshot($admin);
        $result = dev30_api($ctx, 'sf30_partial_reconcile', 'correspondence.reconcile', ['operationId' => $requestId], ['authScopes' => ['compose']]);
        $response = dev30_response($result);
        dev30_assert(($response['ok'] ?? null) === true && ($response['data']['state'] ?? null) === 'partial', 'unknown_partial_projection_invalid');
        dev30_assert(!isset($response['data']['receipt']) && !isset($response['data']['attachments']) && !isset($response['data']['delivery']), 'unknown_partial_disclosed_delivery');
        dev30_assert_fence($result['readFence'], false);
        dev30_assert($before === dev30_count_snapshot($admin), 'unknown_partial_reconcile_mutated_or_resent');
        dev30_reset_fixture_inputs($admin, $baseline);
    });

    dev30_case($cases, 'adapter.forwarded_utf8_detail_roundtrip', static function () use (&$ctx, $admin, $baseline): void {
        dev30_reset_fixture_inputs($admin, $baseline); dev30_seed_case_clock($admin);
        $requestId = dev30_uuid();
        $body = "Forwarded message — café begins.\n";
        for ($index = 0; $index < 14; $index++) $body .= 'A careful courier keeps every phrase in the ledger; café remains legible. ';
        $body .= "\n[forwarded text ends]";
        $bodyChars = preg_match_all('/./us', $body);
        dev30_assert(is_int($bodyChars) && $bodyChars > 128 && strlen($body) < 1800, 'forwarded_utf8_fixture_size_invalid');
        $payload = ['operationId' => $requestId, 'writerActorKey' => 'player', 'recipientActorKey' => 'npc:900003', 'signatureActorKey' => 'player',
            'body' => $body, 'inventoryRevision' => 'dev30-forwarded-utf8',
            'attachments' => [['form_id' => 'Skyrim.esm|00000001', 'quantity' => 2]], 'goldAmount' => 0];
        $compose = dev30_api($ctx, 'sf30_forwarded_compose', 'correspondence.compose', $payload,
            ['operationRequestId' => $requestId, 'authScopes' => ['compose']]);
        $composed = dev30_response($compose);
        dev30_assert(($composed['ok'] ?? null) === true && ($composed['data']['state'] ?? null) === 'dispatched', 'forwarded_utf8_compose_failed');
        $document = dev30_document($admin, $requestId);
        dev30_assert(($document['payload']['body'] ?? null) === $body, 'forwarded_utf8_owner_body_changed');
        $seed = dev30_run_child($ctx, 'sf30_forwarded_receipt', $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'seed_unknown_receipt.php',
            ['--scenario','delivered','--operation-id',$requestId]);
        $receipt = json_decode(trim($seed['stdout']), true, 32, JSON_THROW_ON_ERROR);
        dev30_assert(is_array($receipt) && ($receipt['accepted'] ?? null) === true, 'forwarded_utf8_receipt_not_seeded');
        $before = dev30_count_snapshot($admin);
        $detail = dev30_api($ctx, 'sf30_forwarded_detail', 'correspondence.detail.read',
            ['recipientActorKey' => 'npc:900003', 'letterId' => $document['id']], ['authScopes' => ['inspector']]);
        $response = dev30_response($detail);
        dev30_assert(($response['ok'] ?? null) === true && ($response['data']['body'] ?? null) === $body, 'forwarded_utf8_detail_truncated_or_changed');
        dev30_assert(hash_equals(hash('sha256', $body), hash('sha256', (string)$response['data']['body']))
            && strlen((string)$response['data']['body']) === strlen($body), 'forwarded_utf8_detail_hash_mismatch');
        dev30_assert_fence($detail['readFence'], false);
        dev30_assert($before === dev30_count_snapshot($admin), 'forwarded_utf8_detail_read_mutated_rows');
        dev30_reset_fixture_inputs($admin, $baseline);
    });

    dev30_case($cases, 'adapter.restore_pending_refusal_recovery', static function () use (&$ctx, $admin, $baseline): void {
        dev30_reset_fixture_inputs($admin, $baseline); dev30_seed_case_clock($admin);
        $seed = dev30_seed_companion($ctx, 'A scheduler-owned restore probe.', false);
        try {
            dev30_pg($admin, 'UPDATE plugins.correspondence_restore_state SET pending=TRUE WHERE singleton IS TRUE');
            $refused = dev30_api($ctx, 'sf30_restore_pending', 'correspondence.reconcile', ['operationId' => $seed['requestId']], ['authScopes' => ['compose']]);
            $response = dev30_response($refused);
            dev30_assert(($response['ok'] ?? null) === false && ($response['errorCode'] ?? null) === 'stale_context', 'pending_restore_read_not_refused');
            dev30_assert_fence($refused['readFence'], true);
            dev30_pg($admin, 'UPDATE plugins.correspondence_restore_state SET pending=FALSE WHERE singleton IS TRUE');
            $recovered = dev30_api($ctx, 'sf30_restore_recovery', 'correspondence.reconcile', ['operationId' => $seed['requestId']], ['authScopes' => ['compose']]);
            $ok = dev30_response($recovered);
            dev30_assert(($ok['ok'] ?? null) === true && ($ok['data']['state'] ?? null) === 'queued', 'restore_pending_same_document_recovery_failed');
            dev30_assert_fence($recovered['readFence'], false);
        } finally {
            dev30_pg($admin, 'UPDATE plugins.correspondence_restore_state SET pending=FALSE WHERE singleton IS TRUE');
            dev30_reset_fixture_inputs($admin, $baseline);
        }
    });

    if ($suite === 'dev31') {
        dev30_case($cases, 'adapter.resume_cold_boot_zero_nearby_npc_saved_500_exact_once', static function () use (&$ctx, $admin, $baseline): void {
            dev30_reset_fixture_inputs($admin, $baseline);
            try {
                dev30_with_unprofiled_scope($ctx, $admin, static function () use (&$ctx, $admin): void {
                    $fixture = dev31_queued_letter_with_activity($ctx, $admin, 'sf31_zero_nearby_npc');
                    $letter = $fixture['letter']; $observation = $fixture['observation'];
                    dev30_assert(($observation['executor'] ?? null) === 'The Narrator'
                        && ($observation['nearbyActorCount'] ?? null) === 0
                        && ($observation['managedExecutorObservation'] ?? null) === false
                        && (int)dev30_scalar($admin, 'SELECT count(*)::text FROM public.core_npc_master WHERE id=900003') === 1,
                        'zero_nearby_npc_owner_observation_invalid');
                    $attemptId = dev30_uuid();
                    $resume = dev30_api($ctx, 'sf31_zero_nearby_npc_resume_repeat', 'correspondence.resume', [
                        'operationId' => $letter['requestId'], 'attemptId' => $attemptId,
                    ], ['operationRequestId' => $attemptId, 'repeatSameRequest' => true, 'authScopes' => ['compose']]);
                    $responses = array_values(array_filter($resume['responses'], static fn(array $row): bool => in_array($row['phase'] ?? null, ['operation','repeat'], true)));
                    dev30_assert(count($responses) === 2
                        && ($responses[0]['ok'] ?? null) === true && ($responses[0]['data']['state'] ?? null) === 'dispatched'
                        && ($responses[1]['ok'] ?? null) === true && ($responses[1]['data']['state'] ?? null) === 'dispatched',
                        'saved_500_gold_resume_repeat_did_not_dispatch_same_letter');
                    dev31_interaction_trace($ctx, $resume, true, 7, true);
                    dev30_assert(dev30_delivery_counts($admin, $letter['documentId']) === ['documents' => 1, 'operations' => 1, 'commands' => 1],
                        'saved_500_gold_resume_repeat_was_not_exactly_once');
                    dev31_assert_dispatch_generation($admin, $letter['documentId'], $ctx);
                    $document = dev30_document($admin, $letter['requestId']);
                    dev30_assert(($document['payload']['recipient']['kind'] ?? null) === 'npc'
                        && (int)($document['payload']['recipient']['id'] ?? 0) === 900003,
                        'saved_500_gold_recipient_identity_changed');
                });
            } finally {
                dev31_write_interaction_state($ctx, true, 7);
                dev30_pg($admin, 'DELETE FROM plugins.correspondence_presence WHERE playthrough_id=$1', ['unprofiled']);
                dev30_reset_fixture_inputs($admin, $baseline);
            }
        });

        dev30_case($cases, 'adapter.resume_cold_boot_zero_nearby_npc_saved_500_concurrent_once', static function () use (&$ctx, $admin, $baseline): void {
            dev30_reset_fixture_inputs($admin, $baseline);
            try {
                dev30_with_unprofiled_scope($ctx, $admin, static function () use (&$ctx, $admin): void {
                    $fixture = dev31_queued_letter_with_activity($ctx, $admin, 'sf31_zero_nearby_npc_concurrent');
                    $letter = $fixture['letter']; $observation = $fixture['observation'];
                    dev30_assert(($observation['executor'] ?? null) === 'The Narrator'
                        && ($observation['nearbyActorCount'] ?? null) === 0
                        && ($observation['managedExecutorObservation'] ?? null) === false,
                        'zero_nearby_npc_concurrent_owner_observation_invalid');
                    $attempts = [dev30_uuid(), dev30_uuid()];
                    $results = dev30_concurrent_api($ctx, [
                        ['caseId' => 'sf31_zero_nearby_npc_resume_a', 'operation' => 'correspondence.resume',
                            'payload' => ['operationId' => $letter['requestId'], 'attemptId' => $attempts[0]],
                            'options' => ['operationRequestId' => $attempts[0], 'authScopes' => ['compose']]],
                        ['caseId' => 'sf31_zero_nearby_npc_resume_b', 'operation' => 'correspondence.resume',
                            'payload' => ['operationId' => $letter['requestId'], 'attemptId' => $attempts[1]],
                            'options' => ['operationRequestId' => $attempts[1], 'authScopes' => ['compose']]],
                    ]);
                    $responses = [];
                    foreach ($results as $result) {
                        dev31_interaction_trace($ctx, $result, true, 7, true);
                        $responses[] = dev30_response($result);
                    }
                    $successes = array_values(array_filter($responses, static fn(array $row): bool => ($row['ok'] ?? null) === true
                        && ($row['data']['operationId'] ?? null) === $letter['requestId'] && ($row['data']['state'] ?? null) === 'dispatched'));
                    $conflicts = array_values(array_filter($responses, static fn(array $row): bool => ($row['ok'] ?? null) === false
                        && ($row['errorCode'] ?? null) === 'conflict'));
                    dev30_assert(count($successes) === 1 && count($conflicts) === 1
                        && dev30_delivery_counts($admin, $letter['documentId']) === ['documents' => 1, 'operations' => 1, 'commands' => 1],
                        'zero_nearby_npc_concurrent_resume_not_exactly_once');
                    dev31_assert_dispatch_generation($admin, $letter['documentId'], $ctx);
                });
            } finally {
                dev31_write_interaction_state($ctx, true, 7);
                dev30_pg($admin, 'DELETE FROM plugins.correspondence_presence WHERE playthrough_id=$1', ['unprofiled']);
                dev30_reset_fixture_inputs($admin, $baseline);
            }
        });

        dev30_case($cases, 'adapter.resume_native_context_refusals_no_operation', static function () use (&$ctx, $admin, $baseline): void {
            dev30_reset_fixture_inputs($admin, $baseline);
            try {
                dev30_with_unprofiled_scope($ctx, $admin, static function () use (&$ctx, $admin): void {
                    $fixture = dev31_queued_letter_with_activity($ctx, $admin, 'sf31_native_context');
                    $letter = $fixture['letter'];
                    dev30_assert(($fixture['observation']['executor'] ?? null) === 'The Narrator'
                        && ($fixture['observation']['nearbyActorCount'] ?? null) === 0
                        && ($fixture['observation']['managedExecutorObservation'] ?? null) === false,
                        'native_refusal_shared_letter_fixture_invalid');

                    foreach (['passive','off','generation','expired','pending_restore','changed_restore'] as $mode) {
                        $pendingRestore = false;
                        $originalEpoch = null;
                        $expiredProofBefore = null;
                        $expiredCountSnapshotBefore = null;
                        $expiryEvidenceIndex = null;
                        $restoreSnapshotBefore = null;
                        try {
                            dev31_write_interaction_state($ctx, true, 7);
                            $observation = dev31_observe_native_activity($ctx, $admin, $mode === 'passive');
                            if ($mode === 'passive') {
                                dev30_assert(($observation['active'] ?? null) === false && ($observation['executor'] ?? null) === null,
                                    'passive_native_probe_created_active_executor');
                            } else {
                                dev30_assert(($observation['active'] ?? null) === true && ($observation['executor'] ?? null) === 'The Narrator',
                                    'active_native_probe_did_not_create_narrator_executor');
                            }
                            $before = dev30_delivery_counts($admin, $letter['documentId']);
                            dev30_assert($before === ['documents' => 1, 'operations' => 0, 'commands' => 0],
                                'native_refusal_shared_letter_not_queued');
                            $attemptId = dev30_uuid();
                            $caseId = 'sf31_native_' . $mode . '_resume';

                            if ($mode === 'off') {
                                dev31_write_interaction_state($ctx, false, 7);
                            } elseif ($mode === 'generation') {
                                $result = dev31_generation_change_api($ctx, $caseId, $letter['requestId'], $attemptId, true, 8);
                                dev31_interaction_trace($ctx, $result, true, 8, false);
                            } elseif ($mode === 'expired') {
                                $waitStarted = microtime(true);
                                sleep(46);
                                $waitElapsed = microtime(true) - $waitStarted;
                                dev30_assert($waitElapsed >= 46.0, 'native_activity_expiry_wait_interrupted');
                                $expiredProofBefore = dev31_assert_expired_activity_anchor($admin, 46);
                                $expiryEvidence = [
                                    'caseId' => $caseId, 'requestedWaitSeconds' => 46,
                                    'observedElapsedSeconds' => round($waitElapsed, 3),
                                    'activityPayloadOrAnchorMutated' => false,
                                ] + $expiredProofBefore;
                                unset($expiryEvidence['presencePayload'], $expiryEvidence['anchorEvent']);
                                $ctx['interactionExpiryWaits'][] = $expiryEvidence;
                                $expiryEvidenceIndex = array_key_last($ctx['interactionExpiryWaits']);
                                $expiredCountSnapshotBefore = dev30_count_snapshot($admin);
                            } elseif ($mode === 'pending_restore') {
                                dev30_pg($admin, 'UPDATE plugins.correspondence_restore_state SET pending=TRUE WHERE singleton IS TRUE');
                                $pendingRestore = true;
                            } elseif ($mode === 'changed_restore') {
                                $originalEpoch = (string)dev30_scalar($admin,
                                    'SELECT epoch::text FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
                                if (preg_match('/\A[0-9a-f-]{36}\z/D', $originalEpoch) !== 1) {
                                    throw new RuntimeException('restore_epoch_fixture_invalid');
                                }
                                dev30_pg($admin, 'UPDATE plugins.correspondence_restore_state SET epoch=$1::uuid WHERE singleton IS TRUE', [dev30_uuid()]);
                            }

                            if (in_array($mode, ['pending_restore','changed_restore'], true)) {
                                $restoreSnapshotBefore = [
                                    'restoreRow' => dev30_scalar($admin,
                                        'SELECT to_jsonb(s)::text FROM plugins.correspondence_restore_state AS s WHERE s.singleton IS TRUE'),
                                    'presencePayload' => dev30_scalar($admin,
                                        'SELECT payload::text FROM plugins.correspondence_presence WHERE playthrough_id=$1', ['unprofiled']),
                                    'anchorEvent' => dev30_scalar($admin,
                                        "SELECT to_jsonb(e)::text FROM public.eventlog AS e WHERE e.rowid = "
                                            . "(SELECT (payload->'activity'->'anchor'->>'rowid')::bigint FROM plugins.correspondence_presence WHERE playthrough_id=\$1)",
                                        ['unprofiled']),
                                    'ownerCounts' => dev30_count_snapshot($admin),
                                    'roleCommandCount' => (string)dev30_scalar($admin,
                                        "SELECT count(*)::text FROM public.responselog WHERE action='rolecommand'"),
                                    'deliveryCounts' => dev30_delivery_counts($admin, $letter['documentId']),
                                ];
                                dev30_assert(is_string($restoreSnapshotBefore['restoreRow'] ?? null)
                                    && is_string($restoreSnapshotBefore['presencePayload'] ?? null)
                                    && is_string($restoreSnapshotBefore['anchorEvent'] ?? null),
                                    'restore_refusal_snapshot_missing');
                            }

                            if ($mode !== 'generation') {
                                $result = dev30_api($ctx, $caseId, 'correspondence.resume', [
                                    'operationId' => $letter['requestId'], 'attemptId' => $attemptId,
                                ], ['operationRequestId' => $attemptId, 'authScopes' => ['compose']]);
                                if (in_array($mode, ['pending_restore','changed_restore'], true)) {
                                    $response = dev30_response($result);
                                    dev30_assert(($response['ok'] ?? null) === false && ($response['errorCode'] ?? null) === 'stale_context',
                                        'restore_context_refusal_not_stale_context');
                                    $restoreSnapshotAfter = [
                                        'restoreRow' => dev30_scalar($admin,
                                            'SELECT to_jsonb(s)::text FROM plugins.correspondence_restore_state AS s WHERE s.singleton IS TRUE'),
                                        'presencePayload' => dev30_scalar($admin,
                                            'SELECT payload::text FROM plugins.correspondence_presence WHERE playthrough_id=$1', ['unprofiled']),
                                        'anchorEvent' => dev30_scalar($admin,
                                            "SELECT to_jsonb(e)::text FROM public.eventlog AS e WHERE e.rowid = "
                                                . "(SELECT (payload->'activity'->'anchor'->>'rowid')::bigint FROM plugins.correspondence_presence WHERE playthrough_id=\$1)",
                                            ['unprofiled']),
                                        'ownerCounts' => dev30_count_snapshot($admin),
                                        'roleCommandCount' => (string)dev30_scalar($admin,
                                            "SELECT count(*)::text FROM public.responselog WHERE action='rolecommand'"),
                                        'deliveryCounts' => dev30_delivery_counts($admin, $letter['documentId']),
                                    ];
                                    dev30_assert($restoreSnapshotBefore === $restoreSnapshotAfter,
                                        'restore_context_refusal_mutated_owned_state');
                                    dev31_restore_preflight_trace($ctx, $result, $mode);
                                    $ctx['nativeContextRefusalResults'][] = [
                                        'variant' => $mode, 'ok' => false, 'errorCode' => 'stale_context',
                                        'boundary' => 'adapter_restore_preflight',
                                    ];
                                }
                            }

                            if (!in_array($mode, ['pending_restore','changed_restore'], true)) {
                                $response = dev31_assert_executor_unavailable_response($result, $letter);
                                if ($mode === 'expired') {
                                    dev30_assert(is_array($expiredCountSnapshotBefore)
                                        && $expiredCountSnapshotBefore === dev30_count_snapshot($admin),
                                        'expired_resume_mutated_owner_counts');
                                    $expiredProofAfter = dev31_assert_expired_activity_anchor($admin, 46);
                                    dev30_assert(($expiredProofAfter['presencePayload'] ?? null) === ($expiredProofBefore['presencePayload'] ?? null)
                                        && ($expiredProofAfter['anchorEvent'] ?? null) === ($expiredProofBefore['anchorEvent'] ?? null)
                                        && ($expiredProofAfter['anchorRowId'] ?? null) === ($expiredProofBefore['anchorRowId'] ?? null)
                                        && ($expiredProofAfter['anchorDataSha256'] ?? null) === ($expiredProofBefore['anchorDataSha256'] ?? null),
                                        'expired_resume_mutated_activity_or_owned_anchor');
                                    dev30_assert(is_int($expiryEvidenceIndex), 'expired_resume_evidence_index_missing');
                                    $ctx['interactionExpiryWaits'][$expiryEvidenceIndex]['postResumePayloadAndAnchorUnchanged'] = true;
                                }
                                if (!in_array($mode, ['generation'], true)) {
                                    dev31_interaction_trace($ctx, $result, $mode !== 'off', 7, $mode !== 'off');
                                }
                                $ctx['nativeContextRefusalResults'][] = [
                                    'variant' => $mode, 'ok' => true, 'state' => 'queued',
                                    'queuedReason' => 'executor_unavailable', 'responseOperationId' => $response['data']['operationId'],
                                ];
                            }
                            dev31_assert_unchanged_queued_letter($admin, $letter, $before);
                        } finally {
                            if ($pendingRestore) {
                                dev30_pg($admin, 'UPDATE plugins.correspondence_restore_state SET pending=FALSE WHERE singleton IS TRUE');
                            }
                            if (is_string($originalEpoch)) {
                                dev30_pg($admin, 'UPDATE plugins.correspondence_restore_state SET epoch=$1::uuid WHERE singleton IS TRUE', [$originalEpoch]);
                            }
                            dev31_write_interaction_state($ctx, true, 7);
                            dev30_pg($admin, 'DELETE FROM plugins.correspondence_presence WHERE playthrough_id=$1', ['unprofiled']);
                        }
                    }
                });
            } finally {
                dev31_write_interaction_state($ctx, true, 7);
                dev30_pg($admin, 'UPDATE plugins.correspondence_restore_state SET pending=FALSE WHERE singleton IS TRUE');
                dev30_pg($admin, 'DELETE FROM plugins.correspondence_presence WHERE playthrough_id=$1', ['unprofiled']);
                dev30_reset_fixture_inputs($admin, $baseline);
            }
        });
    }

    $ownerCases = [
        'owner.bgl_unreadable_defer_recover' => 'bgl_unreadable',
        'owner.player_unavailable_defer_recover' => 'player_unavailable',
        'owner.form_identity_unavailable_defer_recover' => 'form_unavailable',
        'owner.player_identity_mismatch_cancel' => 'player_changed',
        'owner.actor_identity_mismatch_cancel' => 'actor_changed',
        'owner.form_identity_mismatch_cancel' => 'form_changed',
        'owner.explicit_optout_cancel' => 'optout',
        'owner.confirmed_dead_cancel' => 'dead',
        'owner.episode_change_cancel' => 'episode_changed',
        'owner.restore_epoch_change_reject' => 'restore_changed',
    ];
    foreach ($ownerCases as $caseId => $variant) {
        dev30_case($cases, $caseId, static function () use (&$ctx, $admin, $baseline, $caseId, $variant): void {
            dev30_reset_fixture_inputs($admin, $baseline); dev30_seed_case_clock($admin);
            dev30_enable_owner_actor($admin, $baseline);
            try { dev30_owner_case($ctx, $admin, $caseId, $variant, $baseline); }
            finally { dev30_reset_fixture_inputs($admin, $baseline); }
        });
    }

    $executedCaseIds = array_column($cases, 'id');
    $expectedIds = $expectedCaseIds;
    $executedIds = $executedCaseIds;
    sort($expectedIds, SORT_STRING);
    sort($executedIds, SORT_STRING);
    $inventoryMatches = $expectedCaseIds !== []
        && count(array_unique($expectedCaseIds)) === count($expectedCaseIds)
        && count(array_unique($executedCaseIds)) === count($executedCaseIds)
        && $executedIds === $expectedIds;
    if (!$inventoryMatches) {
        $status = 'failed';
        $failure = 'executed_case_inventory_mismatch';
        fwrite(STDERR, "fixture:executed_case_inventory_mismatch\n");
    } else {
        $status = count(array_filter($cases, static fn(array $case): bool => $case['status'] !== 'passed')) === 0
            && is_int($expectedCaseCount) && count($cases) === $expectedCaseCount ? 'passed' : 'failed';
    }
} catch (Throwable $error) {
    $failure = $error->getMessage();
    if (preg_match('/\A[a-z][a-z0-9_]{0,79}\z/iD', $failure) !== 1) $failure = 'fixture_preflight_failed';
    fwrite(STDERR, 'fixture:' . strtolower($failure) . "\n");
} finally {
    if (is_resource($admin)) @pg_close($admin);
    if ($cases !== [] || is_array($ctx['networkEvidence'] ?? null)) {
        $trace = [
            'schemaVersion' => 1, 'sourceCommit' => $ctx['sourceCommit'] ?? null,
            'sourceHashes' => $sourceHashes, 'mainIncludedFiles' => array_values(get_included_files()),
            'processes' => $ctx['processes'] ?? [], 'ownerClosurePins' => $ownerBinding['ownerClosurePins'] ?? [],
            'coreInputs' => $corePins ?? [], 'traceHelperSha256' => $ctx['trace']['sha256'] ?? null,
            'coreProvenance' => ['hostCommit' => '0ec590b42c8087860138bb4e9aab470049ff9a2b', 'hostTree' => 'ab97c6dd3947501b8a76bf9b9c6ad74b30be3975'],
            'fixtureSetup' => $ctx['fixtureSetup'] ?? null,
            'fixtureInstrumentation' => [
                'loggerSha256' => $corePins['lib/logger.php'] ?? null,
                'customLogPath' => $ctx['fixtureLogPath'] ?? null,
                'errorHandlerOverride' => 'dev30_fixture_error_handler',
                'preservesPgrSqlFailed' => true,
                'warnings' => 'stderr',
            ],
            'networkEvidence' => $ctx['networkEvidence'] ?? null,
            'traceScope' => 'captured PHP includes and pinned non-PHP source bundles only; no live or installed roots are permitted',
        ];
        if (($ctx['suite'] ?? 'dev30') === 'dev31') {
            $trace['suite'] = 'dev31';
            $trace['interactionSource'] = $ctx['interactionSourceEvidence'] ?? null;
            $trace['interactionFixtureInitial'] = $ctx['interactionFixtureInitial'] ?? null;
            $trace['interactionFixtureFinal'] = $ctx['interactionFixture'] ?? null;
            $trace['interactionStateTransitions'] = $ctx['interactionStateTransitions'] ?? [];
            $trace['interactionChildTraces'] = $ctx['interactionChildTraces'] ?? [];
            $trace['nativeActivityFixtures'] = $ctx['nativeActivityFixtures'] ?? [];
            $trace['nativeDispatchGenerationChecks'] = $ctx['nativeDispatchGenerationChecks'] ?? [];
            $trace['nativeContextRefusalResults'] = $ctx['nativeContextRefusalResults'] ?? [];
            $trace['interactionExpiryWaits'] = $ctx['interactionExpiryWaits'] ?? [];
        }
        $tracePath = ($ctx['artifactDir'] ?? '') . DIRECTORY_SEPARATOR . 'source-load-trace.json';
        try {
            if (!is_string($ctx['artifactDir'] ?? null) || !is_dir($ctx['artifactDir']) || is_link($ctx['artifactDir'])
                || file_exists($tracePath) || is_link($tracePath)) throw new RuntimeException('trace_output_path_invalid');
            $traceBytes = json_encode($trace, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            $written = file_put_contents($tracePath, $traceBytes, LOCK_EX);
            if (!is_int($written) || $written !== strlen($traceBytes) || !is_file($tracePath) || is_link($tracePath)
                || !chmod($tracePath, 0600)) throw new RuntimeException('trace_output_write_failed');
            clearstatcache(true, $tracePath);
            $traceHash = hash_file('sha256', $tracePath);
            if (!is_string($traceHash) || preg_match('/\A[a-f0-9]{64}\z/D', $traceHash) !== 1) throw new RuntimeException('trace_output_hash_failed');
            $networkEvidenceSha256 = strtolower($traceHash);
        } catch (Throwable) {
            $status = 'failed';
            $failure = 'network_evidence_write_failed';
            fwrite(STDERR, "fixture:network_evidence_write_failed\n");
        }
    }
}

$passed = count(array_filter($cases, static fn(array $case): bool => $case['status'] === 'passed'));
$failed = count(array_filter($cases, static fn(array $case): bool => $case['status'] === 'failed'));
$totalAssertions = array_sum(array_column($cases, 'assertions'));
$receipt = [
    'schemaVersion' => 1,
    'kind' => $suite === 'dev31' ? 'department-dev31-actual-pg' : 'department-dev30-actual-pg',
    'status' => $status,
    'failure' => $failure,
    'sourceCommit' => $ctx['sourceCommit'] ?? (string)($options['source-commit'] ?? ''),
    'ownerVersion' => $ownerBinding['ownerVersion'] ?? ($suite === 'dev31' ? '0.1.0-dev.31' : '0.1.0-dev.30'),
    'ownerCommit' => $ownerBinding['ownerCommit'] ?? ($suite === 'dev31'
        ? 'e393bd5f9a1cd31a8d8d006a4356faddc9eb3212' : '1eb58d999698002f8aeaa05b21464f7608717794'),
    'ownerClosurePins' => $ownerBinding['ownerClosurePins'] ?? new stdClass(),
    'sourceHashes' => $sourceHashes,
    'databaseIdentity' => $identity ?? new stdClass(),
    'socketOnly' => false,
    'privateLoopbackOnly' => $networkVerified,
    'networkEvidenceSha256' => $networkEvidenceSha256,
    'distro' => 'DwemerAI4Skyrim3-test2',
    'privateNetworkNamespace' => true,
    'cases' => $cases,
    'counts' => ['cases' => count($cases), 'passed' => $passed, 'failed' => $failed, 'assertions' => $totalAssertions],
    'limits' => ['database' => true, 'http' => false, 'provider' => false, 'native' => false, 'skyrim' => false, 'installedGame' => false, 'live' => false],
];
if ($suite === 'dev31') $receipt['suite'] = 'dev31';
fwrite(STDOUT, json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
exit($status === 'passed' ? 0 : 1);
