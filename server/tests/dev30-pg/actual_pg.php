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
    register_shutdown_function(static function () use ($trace, $networkEvidence): void {
        $files = get_included_files();
        sort($files, SORT_STRING);
        file_put_contents($trace, json_encode(['includedFiles' => $files, 'networkEvidence' => $networkEvidence], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
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
    array_push($args, '-d', 'auto_prepend_file=' . $traceHelper);
    return $args;
}

function dev30_run_child(array &$ctx, string $caseId, string $script, array $scriptArgs = [], ?string $stdin = null, ?string $apiOperation = null, array $apiPayload = [], array $apiOptions = []): array
{
    $testRoot = $apiOperation === null ? $ctx['legacyTestRoot'] : $ctx['legacyTestRoot'];
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
        'QD_SOURCE_TRACE_FILE' => $traceFile, 'PGAPPNAME' => $app,
    ];
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
    $stdout = ''; $stderr = ''; $deadline = microtime(true) + 100; $timedOut = false; $observedExit = null;
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
    $traceRaw = is_file($traceFile) && !is_link($traceFile) ? file_get_contents($traceFile) : false;
    $trace = is_string($traceRaw) ? json_decode($traceRaw, true) : null;
    $childNetwork = is_array($trace) && is_array($trace['networkEvidence'] ?? null) ? $trace['networkEvidence'] : [];
    $ctx['processes'][] = [
        'caseId' => $caseId, 'app' => $app, 'script' => $script,
        'tracePath' => is_file($traceFile) ? $traceFile : null,
        'traceSha256' => is_file($traceFile) ? strtolower((string)hash_file('sha256', $traceFile)) : null,
        'includedFiles' => is_array($trace) && is_array($trace['includedFiles'] ?? null) ? $trace['includedFiles'] : [],
        'networkEvidence' => $childNetwork,
        'stdoutSha256' => hash('sha256', $stdout), 'stderrSha256' => hash('sha256', $stderr),
    ];
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

function dev30_api(array &$ctx, string $caseId, string $operation, array $payload, array $options = []): array
{
    $offset = @filesize($ctx['pgLogPath']);
    if (!is_int($offset)) throw new RuntimeException('postgres_log_unreadable');
    $result = dev30_run_child($ctx, $caseId, $ctx['legacyTestRoot'] . DIRECTORY_SEPARATOR . 'api_driver.php', [], null, $operation, $payload, $options);
    $rows = [];
    foreach (preg_split('/\r?\n/', trim($result['stdout'])) ?: [] as $line) {
        if ($line === '') continue;
        $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        if (is_array($row)) $rows[] = $row;
    }
    $result['responses'] = $rows;
    $result['sqlOffset'] = $offset;
    $result['readFence'] = dev30_read_fence($ctx, $result['app'], $offset);
    return $result;
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

function dev30_owner_case(array &$ctx, mixed $admin, string $caseId, string $variant): void
{
    $originalEpoch = (string)dev30_scalar($admin, 'SELECT epoch::text FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
    $seed = dev30_seed_companion($ctx, 'I am waiting for you. Please write when you can.', true);
    try {
    dev23_companion_with_owner_runtime($ctx, static function ($store, $service, $companion) use ($admin, $seed, $variant, $originalEpoch): array {
        $restoreRows = dev30_pg($admin, 'SELECT epoch::text AS epoch,pending::text AS pending FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
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
            $closed = dev30_scalar($admin, 'SELECT payload ? \'operator_close\' FROM plugins.correspondence_documents WHERE document_id=$1::uuid', [$seed['documentId']]);
            dev30_assert(in_array($closed, ['t', true], true), 'cancelled_companion_document_not_closed');
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
$options = getopt('', array_map(static fn(string $key): string => $key . ':', $required));
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
try {
    foreach ($required as $key) if (!is_array($options) || !is_string($options[$key] ?? null) || $options[$key] === '') throw new RuntimeException('argument_' . str_replace('-', '_', $key) . '_required');
    $runId = (string)$options['run-id'];
    if (preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}\z/D', $runId) !== 1
        || preg_match('/\A[0-9a-f]{40}\z/D', (string)$options['source-commit']) !== 1) throw new RuntimeException('run_identity_invalid');
    if (($options['pg-port'] ?? null) !== '5432' || ($options['pg-database'] ?? null) !== 'dwemer'
        || ($options['pg-admin-role'] ?? null) !== 'postgres' || ($options['pg-app-role'] ?? null) !== 'dwemer') throw new RuntimeException('database_contract_invalid');
    if (PHP_VERSION_ID < 80100 || !extension_loaded('posix') || !extension_loaded('ctype') || !extension_loaded('tokenizer')
        || !extension_loaded('mbstring') || !extension_loaded('pgsql') || !extension_loaded('gd')
        || !function_exists('imagettfbbox') || !function_exists('imagecreatefrompng') || !function_exists('imagepng')) throw new RuntimeException('php_extension_preflight_failed');
    if (PHP_OS_FAMILY !== 'Linux' || getenv('WSL_DISTRO_NAME') !== 'DwemerAI4Skyrim3-test2') throw new RuntimeException('test2_linux_boundary_invalid');

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
    $ctx['legacyTestRoot'] = $ctx['serverRoot'] . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'dev23-pg';
    if ($ctx['scratchRoot'] !== '/var/tmp/department-dev23-' . $runId
        || $ctx['coreRoot'] !== $ctx['scratchRoot'] . '/stage/core'
        || $ctx['serverRoot'] !== $ctx['coreRoot'] . '/ext/questionable_decisions'
        || $ctx['ownerRoot'] !== $ctx['coreRoot'] . '/ext/correspondence'
        || $ctx['pgSocket'] !== $ctx['scratchRoot'] . '/socket'
        || (string)$options['pg-socket'] !== $ctx['scratchRoot'] . '/socket'
        || is_link((string)$options['pg-socket']) || !is_dir($ctx['pgSocket'])
        || $ctx['fixtureMarker'] !== $ctx['scratchRoot'] . '/fixture-marker.json'
        || is_link($ctx['fixtureMarker']) || !is_file($ctx['fixtureMarker']) || !is_file($ctx['pgLogPath'])
        || !is_file($ctx['php']) || !is_dir($ctx['legacyTestRoot'])) throw new RuntimeException('staged_fixture_identity_invalid');
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

    $caseManifestPath = $ctx['serverRoot'] . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'dev30-pg' . DIRECTORY_SEPARATOR . 'cases.json';
    $caseRaw = @file_get_contents($caseManifestPath);
    $caseManifest = is_string($caseRaw) ? json_decode($caseRaw, true, 32) : null;
    if (!is_array($caseManifest) || ($caseManifest['schemaVersion'] ?? null) !== 1
        || ($caseManifest['kind'] ?? null) !== 'department-dev30-actual-pg-cases'
        || ($caseManifest['ownerVersion'] ?? null) !== '0.1.0-dev.30'
        || ($caseManifest['ownerCommit'] ?? null) !== '1eb58d999698002f8aeaa05b21464f7608717794'
        || count($caseManifest['cases'] ?? []) !== 16 || count($caseManifest['sourceFiles'] ?? []) !== 21) throw new RuntimeException('case_manifest_invalid');
    $caseIds = array_column($caseManifest['cases'], 'id');
    if ($caseIds !== [
        'adapter.unavailable_player_read_recovery','adapter.manual_compose_scheduler_no_dispatch','adapter.request_id_repeat_no_resend',
        'adapter.unknown_partial_reconcile_no_resend','adapter.forwarded_utf8_detail_roundtrip','adapter.restore_pending_refusal_recovery',
        'owner.bgl_unreadable_defer_recover','owner.player_unavailable_defer_recover','owner.form_identity_unavailable_defer_recover',
        'owner.player_identity_mismatch_cancel','owner.actor_identity_mismatch_cancel','owner.form_identity_mismatch_cancel',
        'owner.explicit_optout_cancel','owner.confirmed_dead_cancel','owner.episode_change_cancel','owner.restore_epoch_change_reject',
    ]) throw new RuntimeException('case_inventory_invalid');
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
    if (!is_array($ownerBinding) || ($ownerBinding['schemaVersion'] ?? null) !== 1
        || ($ownerBinding['ownerVersion'] ?? null) !== '0.1.0-dev.30'
        || ($ownerBinding['ownerCommit'] ?? null) !== '1eb58d999698002f8aeaa05b21464f7608717794'
        || !is_array($ownerBinding['ownerClosurePins'] ?? null) || count($ownerBinding['ownerClosurePins']) !== 20) throw new RuntimeException('owner_binding_invalid');
    dev30_owner_pins($ctx['ownerRoot'], $ownerBinding['ownerClosurePins']);
    if (is_link($ctx['coreRoot'] . '/lib/playthrough_preferences.php') || is_link($ctx['coreRoot'] . '/lib/playthrough_runtime.php')
        || is_link($ctx['coreRoot'] . '/lib/playthrough_home.php')) throw new RuntimeException('core_source_redirected');
    $corePins = [
        'lib/playthrough_preferences.php' => 'fc9562fad12b867a5593d06bc0b0b8167120b0270088c343ae3db074e4b3f475',
        'lib/playthrough_runtime.php' => 'd480af9d658c8e754b78d1e4ca6056544d21942facbafd94b23ab1da4cb1cd9c',
        'lib/playthrough_home.php' => 'e28ced10ee4410f0926f9366fca7bb80199b2f423c80afb1c960d074564b88b6',
        'data/fonts/GloriaHallelujah-Regular.ttf' => '4206cbda828ccde275f6d8b406ad80a29d2f5d9bc1a39a86e56a1c9c7691ed8c',
        'data/textures/chim.png' => '820c235caa44a8f7a798e91d8091a633954963761de6e1377c70d8c8247d0342',
    ];
    foreach ($corePins as $relative => $expected) {
        $path = $ctx['coreRoot'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        dev30_require_file($path, $ctx['coreRoot']);
        if (!hash_equals($expected, strtolower((string)hash_file('sha256', $path)))) throw new RuntimeException('captured_core_input_mismatch');
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

    $socket = $ctx['pgSocket'];
    $quote = static fn(string $value): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    $admin = @pg_connect('host=' . $quote($socket) . ' port=5432 dbname=dwemer user=postgres connect_timeout=3', PGSQL_CONNECT_FORCE_NEW);
    if ($admin === false || $admin === null) throw new RuntimeException('admin_connection_failed');
    $ctx['admin'] = $admin;
    $app = @pg_connect('host=localhost port=5432 dbname=dwemer user=dwemer connect_timeout=3', PGSQL_CONNECT_FORCE_NEW);
    if ($app === false || $app === null) throw new RuntimeException('app_connection_failed');
    $server = dev30_pg($admin, "SELECT current_setting('server_version_num')::int AS version,current_setting('data_directory') AS directory,current_setting('port')::int AS port,current_setting('listen_addresses') AS listeners,current_setting('unix_socket_directories') AS sockets,system_identifier::text AS system_id FROM pg_control_system()");
    $appIdentity = dev30_pg($app, 'SELECT current_database() AS database,current_user AS role,inet_client_addr()::text AS client_address,inet_server_addr()::text AS server_address');
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
            dev30_assert(($response['ok'] ?? null) === false && ($response['errorCode'] ?? null) === 'unavailable', 'pending_restore_read_not_refused');
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
            try { dev30_owner_case($ctx, $admin, $caseId, $variant); }
            finally { dev30_reset_fixture_inputs($admin, $baseline); }
        });
    }

    $status = count(array_filter($cases, static fn(array $case): bool => $case['status'] !== 'passed')) === 0 && count($cases) === 16 ? 'passed' : 'failed';
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
            'networkEvidence' => $ctx['networkEvidence'] ?? null,
            'traceScope' => 'captured PHP includes and pinned non-PHP source bundles only; no live or installed roots are permitted',
        ];
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
    'kind' => 'department-dev30-actual-pg',
    'status' => $status,
    'sourceCommit' => $ctx['sourceCommit'] ?? (string)($options['source-commit'] ?? ''),
    'ownerVersion' => $ownerBinding['ownerVersion'] ?? '0.1.0-dev.30',
    'ownerCommit' => $ownerBinding['ownerCommit'] ?? '1eb58d999698002f8aeaa05b21464f7608717794',
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
fwrite(STDOUT, json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
exit($status === 'passed' ? 0 : 1);
