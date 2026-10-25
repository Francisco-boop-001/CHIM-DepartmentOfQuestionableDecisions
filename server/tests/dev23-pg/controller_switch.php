<?php
declare(strict_types=1);

function qd_dev20_controller_fail(string $code): never
{
    fwrite(STDERR, 'dev20-controller-failure:' . $code . PHP_EOL);
    exit(2);
}

$options = getopt('', ['core-root:', 'test-root:', 'artifact-dir:', 'release-file:', 'timeout:']);
$coreArgument = is_array($options) ? ($options['core-root'] ?? null) : null;
$testArgument = is_array($options) ? ($options['test-root'] ?? null) : null;
$artifactArgument = is_array($options) ? ($options['artifact-dir'] ?? null) : null;
$release = is_array($options) ? ($options['release-file'] ?? null) : null;
$timeout = is_array($options) ? ($options['timeout'] ?? '120') : '120';
if (!is_string($coreArgument) || !is_string($testArgument) || !is_string($artifactArgument)
    || !is_string($release) || !is_string($timeout)
    || preg_match('/\A[1-9][0-9]{0,2}\z/D', $timeout) !== 1 || (int)$timeout > 300) {
    qd_dev20_controller_fail('arguments_invalid');
}
$coreRoot = realpath($coreArgument);
$testRoot = realpath($testArgument);
$artifactRoot = realpath($artifactArgument);
$releaseParent = realpath(dirname($release));
if (!is_string($coreRoot) || !is_dir($coreRoot) || !is_string($testRoot) || !is_dir($testRoot)
    || is_link($testArgument) || is_link($testRoot . '/fixture_guard.php')
    || !is_string($artifactRoot) || !is_dir($artifactRoot) || is_link($artifactArgument)
    || !is_string($releaseParent) || !is_dir($releaseParent) || file_exists($release) || is_link($release)) {
    qd_dev20_controller_fail('private_controller_root_invalid');
}
$expectedTestRoot = $coreRoot . '/ext/questionable_decisions/tests/dev23-pg';
$runId = getenv('QD_DEV20_RUN_ID');
if ($testRoot !== $expectedTestRoot || !is_string($runId)
    || preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}\z/D', $runId) !== 1) {
    qd_dev20_controller_fail('fixture_inputs_not_exact');
}
require_once $testRoot . '/fixture_guard.php';
try {
    $fixtureIdentity = qd_dev20_assert_private_fixture();
} catch (Throwable) {
    qd_dev20_controller_fail('private_fixture_guard_refused');
}
$expectedArtifact = '/mnt/k/ActorwrightExchange/artifacts/department-dev23-compatibility-20261024/runtime/runs/' . $runId;
$expectedRelease = $expectedArtifact . '/cases/controller_pause_with_held_lease/controller-release';
if ($fixtureIdentity['runId'] !== $runId || $fixtureIdentity['coreRoot'] !== $coreRoot
    || $artifactRoot !== $expectedArtifact || $release !== $expectedRelease
    || $releaseParent !== dirname($expectedRelease)
    || !is_file($coreRoot . '/lib/playthrough_runtime.php') || is_link($coreRoot . '/lib/playthrough_runtime.php')) {
    qd_dev20_controller_fail('private_controller_path_mismatch');
}

$state = null;
try {
    require_once $coreRoot . '/lib/playthrough_runtime.php';
    $state = ptr_runtime_begin_switch((float)$timeout, null);
    fwrite(STDOUT, json_encode([
        'schema' => 'department.dev20.controller-fixture.v1',
        'event' => 'exclusive_acquired',
        'generationPresent' => is_file($coreRoot . '/log/playthrough_runtime/generation'),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    fflush(STDOUT);
    $deadline = microtime(true) + (int)$timeout;
    while (!is_file($release)) {
        if (microtime(true) >= $deadline) throw new RuntimeException('controller_release_timeout');
        usleep(10000);
    }
    ptr_runtime_release_switch($state);
    $state = null;
    fwrite(STDOUT, json_encode([
        'schema' => 'department.dev20.controller-fixture.v1',
        'event' => 'released',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $error) {
    if (is_array($state)) {
        try { ptr_runtime_release_switch($state); } catch (Throwable) {}
    }
    qd_dev20_controller_fail('runtime_' . strtolower((new ReflectionClass($error))->getShortName()));
}
