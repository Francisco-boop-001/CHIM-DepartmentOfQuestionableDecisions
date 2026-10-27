<?php
declare(strict_types=1);

$implementationRoot = dirname(__DIR__, 3);
require_once $implementationRoot . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'runtime_support.php';
require_once $implementationRoot . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'pcv.php';

if ($argc !== 3) {
    throw new RuntimeException('Usage: php core_main_guard.php <accepted-host-main.php> <captured-main.php>');
}
$acceptedMain = $argv[1];
$capturedMain = $argv[2];
foreach ([$acceptedMain, $capturedMain] as $path) {
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('A guarded main.php source artifact is unavailable.');
    }
}

$acceptedSource = file_get_contents($acceptedMain);
$capturedSource = file_get_contents($capturedMain);
if (!is_string($acceptedSource) || !is_string($capturedSource)
    || str_contains($acceptedSource, "\r")
    || strtolower((string)hash_file('sha256', $acceptedMain)) !== QD_PCV_EXPECTED_CORE_MAIN_SHA256
    || strtolower((string)hash_file('sha256', $capturedMain)) !== '374085e7190495e5372d8604f1d8ecbe7bbe7fda8ec76a238eb7b81fd19a08ec') {
    throw new RuntimeException('The source artifacts do not match the reviewed PCV guard inputs.');
}

$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-pcv-core-main-guard-' . bin2hex(random_bytes(8));
$coreRoot = $tempRoot . DIRECTORY_SEPARATOR . 'core';
$mainPath = $coreRoot . DIRECTORY_SEPARATOR . 'main.php';
$hookRoot = $coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'pcv_companion';
$previousCoreRoot = getenv('QD_CHIM_ROOT');

try {
    if (!mkdir($tempRoot, 0700) || !mkdir($coreRoot . DIRECTORY_SEPARATOR . 'lib', 0700, true)
        || !mkdir($hookRoot, 0700, true)
        || file_put_contents($coreRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_preferences.php', "<?php\n") !== 6) {
        throw new RuntimeException('The isolated core fixture could not be created.');
    }
    foreach (['companion.php', 'globals.php', 'preprocessing.php'] as $hook) {
        if (file_put_contents($hookRoot . DIRECTORY_SEPARATOR . $hook, "<?php\n") !== 6) {
            throw new RuntimeException('The isolated companion hook fixture could not be created.');
        }
    }
    putenv('QD_CHIM_ROOT=' . $coreRoot);

    $checkAcceptedCore = static function (string $source, string $label) use ($mainPath): void {
        if (file_put_contents($mainPath, $source) !== strlen($source)
            || !qd_pcv_stock_main_matches_pin($mainPath)
            || !qd_pcv_core_guard_ready()) {
            throw new RuntimeException($label . '_core_variant_rejected');
        }
    };

    $checkAcceptedCore($acceptedSource, 'accepted_lf');
    $acceptedCrlf = str_replace("\n", "\r\n", $acceptedSource);
    if (strtolower(hash('sha256', $acceptedCrlf)) !== QD_PCV_EXPECTED_CORE_MAIN_WINDOWS_CRLF_SHA256) {
        throw new RuntimeException('The CRLF source did not match the pinned checkout form.');
    }
    $checkAcceptedCore($acceptedCrlf, 'accepted_crlf');

    if (!qd_pcv_stock_main_matches_pin($capturedMain)) {
        throw new RuntimeException('captured_npcspellcast_variant_rejected');
    }
    $checkAcceptedCore($capturedSource, 'captured_npcspellcast_variant');

    $inputWhitelist = 'in_array($gameRequest[0],["inputtext","inputtext_s","ginputtext","ginputtext_s","narrator_inputtext","instruction","init"])';
    $tamperedWhitelist = 'in_array($gameRequest[0],["inputtext","ginputtext","ginputtext_s","narrator_inputtext","instruction","init"])';
    $tamperedSource = str_replace($inputWhitelist, $tamperedWhitelist, $capturedSource, $replacements);
    if ($replacements !== 1 || file_put_contents($mainPath, $tamperedSource) !== strlen($tamperedSource)
        || qd_pcv_stock_main_matches_pin($mainPath) || qd_pcv_core_guard_ready()) {
        throw new RuntimeException('player_input_whitelist_tamper_accepted');
    }

    if (!unlink($hookRoot . DIRECTORY_SEPARATOR . 'preprocessing.php')
        || file_put_contents($mainPath, $capturedSource) !== strlen($capturedSource)
        || !qd_pcv_stock_main_matches_pin($mainPath) || qd_pcv_core_guard_ready()) {
        throw new RuntimeException('missing_companion_hook_accepted');
    }
} finally {
    is_string($previousCoreRoot) ? putenv('QD_CHIM_ROOT=' . $previousCoreRoot) : putenv('QD_CHIM_ROOT');
    if (file_exists($tempRoot) || is_link($tempRoot)) {
        $systemTempRoot = realpath(sys_get_temp_dir());
        $resolvedTempRoot = realpath($tempRoot);
        if (!is_string($systemTempRoot) || !is_string($resolvedTempRoot)
            || dirname($resolvedTempRoot) !== $systemTempRoot
            || !str_starts_with(basename($resolvedTempRoot), 'qd-pcv-core-main-guard-')
            || is_link($tempRoot)) {
            throw new RuntimeException('The isolated core fixture escaped its temporary root.');
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolvedTempRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $path = $item->getPathname();
            $removed = $item->isLink() ? @unlink($path)
                : ($item->isDir() ? @rmdir($path) : @unlink($path));
            if (!$removed && (file_exists($path) || is_link($path))) {
                throw new RuntimeException('The isolated core fixture could not be cleaned.');
            }
        }
        if (!@rmdir($resolvedTempRoot) && is_dir($resolvedTempRoot)) {
            throw new RuntimeException('The isolated core fixture root could not be cleaned.');
        }
    }
}

echo "PASS PCV core main source guard\n";
