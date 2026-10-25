<?php
declare(strict_types=1);

/** Reject fixture invocations that could point at a host/live CHIM tree. */
function qd_dev23_is_network_namespace(mixed $value): bool
{
    return is_string($value) && preg_match('/\Anet:\[[1-9][0-9]*\]\z/D', $value) === 1;
}

function qd_dev20_assert_private_fixture(): array
{
    if (PHP_OS_FAMILY !== 'Linux') throw new RuntimeException('fixture_linux_only');
    $selfNamespace = @readlink('/proc/self/ns/net');
    $initNamespace = @readlink('/proc/1/ns/net');
    if (!qd_dev23_is_network_namespace($selfNamespace)) {
        throw new RuntimeException('fixture_self_network_namespace_invalid');
    }
    $effectiveUid = function_exists('posix_geteuid') ? @posix_geteuid() : null;
    if (!is_int($effectiveUid)) throw new RuntimeException('fixture_effective_uid_unavailable');
    $initialNamespaceReadable = is_string($initNamespace) && $initNamespace !== '';
    if ($initialNamespaceReadable && !qd_dev23_is_network_namespace($initNamespace)) {
        throw new RuntimeException('fixture_initial_network_namespace_invalid');
    }
    if ($initialNamespaceReadable && $selfNamespace === $initNamespace) {
        throw new RuntimeException('fixture_network_namespace_not_isolated');
    }
    if (!$initialNamespaceReadable && $effectiveUid === 0) {
        throw new RuntimeException('fixture_initial_network_namespace_unreadable');
    }
    $runId = getenv('QD_DEV20_RUN_ID');
    $scratchInput = getenv('QD_DEV20_SCRATCH_ROOT');
    $markerInput = getenv('QD_DEV20_FIXTURE_MARKER');
    $coreInput = getenv('QD_CHIM_ROOT');
    if (!is_string($runId) || preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}\z/D', $runId) !== 1
        || !is_string($scratchInput) || !is_string($markerInput) || !is_string($coreInput)) {
        throw new RuntimeException('fixture_run_identity_missing');
    }
    $scratchRoot = '/var/tmp/department-dev23-' . $runId;
    $expectedCore = $scratchRoot . '/stage/core';
    $expectedMarker = $scratchRoot . '/fixture-marker.json';
    if (is_link('/var/tmp') || realpath('/var/tmp') !== '/var/tmp'
        || is_link($scratchInput) || is_link($expectedCore) || is_link($markerInput)
        || realpath($scratchInput) !== $scratchRoot || realpath($coreInput) !== $expectedCore
        || realpath($markerInput) !== $expectedMarker || $scratchInput !== $scratchRoot
        || $coreInput !== $expectedCore || $markerInput !== $expectedMarker
        || !is_dir($expectedCore) || !is_file($expectedMarker)) {
        throw new RuntimeException('fixture_path_identity_invalid');
    }
    $scratchStat = @lstat($scratchRoot);
    $markerStat = @lstat($expectedMarker);
    if (!is_array($scratchStat) || ($scratchStat['mode'] & 0170000) !== 0040000
        || ($scratchStat['uid'] ?? null) !== 0 || ($scratchStat['mode'] & 0022) !== 0
        || !is_array($markerStat) || ($markerStat['mode'] & 0170000) !== 0100000
        || ($markerStat['uid'] ?? null) !== 0 || ($markerStat['mode'] & 0022) !== 0) {
        throw new RuntimeException('fixture_root_marker_protection_invalid');
    }
    $size = @filesize($expectedMarker);
    if (!is_int($size) || $size < 1 || $size > 4096) throw new RuntimeException('fixture_marker_size_invalid');
    $raw = @file_get_contents($expectedMarker);
    $marker = is_string($raw) ? json_decode($raw, true, 8) : null;
    $markerKeys = is_array($marker) ? array_keys($marker) : [];
    sort($markerKeys, SORT_STRING);
    if (!is_array($marker) || $markerKeys !== ['coreRoot', 'initialNetworkNamespace', 'networkNamespace', 'runId', 'schema']
        || ($marker['schema'] ?? null) !== 'department.dev23.fixture.v1'
        || ($marker['runId'] ?? null) !== $runId || ($marker['coreRoot'] ?? null) !== $expectedCore
        || !qd_dev23_is_network_namespace($marker['networkNamespace'] ?? null)
        || !qd_dev23_is_network_namespace($marker['initialNetworkNamespace'] ?? null)
        || ($marker['networkNamespace'] ?? null) !== $selfNamespace
        || ($marker['initialNetworkNamespace'] ?? null) === $selfNamespace
        || ($initialNamespaceReadable && $initNamespace !== $marker['initialNetworkNamespace'])) {
        throw new RuntimeException('fixture_marker_mismatch');
    }
    return ['runId' => $runId, 'scratchRoot' => $scratchRoot, 'coreRoot' => $expectedCore,
        'networkNamespace' => $selfNamespace, 'initialNetworkNamespace' => $marker['initialNetworkNamespace']];
}
