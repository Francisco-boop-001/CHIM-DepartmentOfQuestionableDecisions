<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dev23-pg' . DIRECTORY_SEPARATOR . 'fixture_guard.php';
$fixtureIdentity = qd_dev20_assert_private_fixture();
$coreRoot = $fixtureIdentity['coreRoot'];
$helperPath = $coreRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'chim_interaction.php';
$expectedHash = getenv('QD_DEV31_INTERACTION_SHA256');
$tracePath = getenv('QD_SOURCE_TRACE_FILE');
if (!is_string($expectedHash) || !is_string($tracePath)
    || is_link($helperPath) || !is_file($helperPath) || realpath($helperPath) !== $helperPath
    || !hash_equals($expectedHash, strtolower((string)hash_file('sha256', $helperPath)))) {
    throw new RuntimeException('sf31_interaction_helper_pin_invalid');
}

require_once $helperPath;
if (!function_exists('chimInteractionState') || ($GLOBALS['chim_interaction_generation'] ?? null) !== 7
    || chimInteractionState() !== ['enabled' => true, 'generation' => 7]) {
    throw new RuntimeException('sf31_interaction_generation_capture_invalid');
}

$ready = $tracePath . '.interaction-captured';
$release = $tracePath . '.interaction-release';
if (is_link($ready) || file_exists($ready) || is_link($release) || file_exists($release)) {
    throw new RuntimeException('sf31_interaction_barrier_not_fresh');
}
$handle = @fopen($ready, 'x+b');
if (!is_resource($handle)) throw new RuntimeException('sf31_interaction_barrier_create_failed');
try {
    if (fwrite($handle, "captured-generation-7\n") !== 22 || !fflush($handle)) {
        throw new RuntimeException('sf31_interaction_barrier_write_failed');
    }
} finally { fclose($handle); }

$deadline = microtime(true) + 45;
while (!is_file($release)) {
    if (is_link($release) || microtime(true) >= $deadline) throw new RuntimeException('sf31_interaction_release_timeout');
    usleep(10000);
}
if (file_get_contents($release) !== "continue\n") throw new RuntimeException('sf31_interaction_release_invalid');

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dev23-pg' . DIRECTORY_SEPARATOR . 'api_driver.php';
