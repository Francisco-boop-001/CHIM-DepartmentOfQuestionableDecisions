<?php
declare(strict_types=1);

const PCV25_CAPTURED_CORE_SHA256 = '1111b724c65b017dbbc7baec6dc32a0cee0277222dc6f3bdb6b7a7414e547552';
const PCV25_LOADER_STAGE_MAP = [
    'installation_guard.php' => 'installation_guard.php',
    'log.php' => 'log.php',
    'manifest.json' => 'manifest.json',
    'preprocessing.php' => 'preprocessing.php.template',
    'reflection.php' => 'reflection.php',
    'scope.php' => 'scope.php',
    'state.php' => 'state.php',
];

function pcv25RecursiveCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pcv25RecursiveMkdir(string $path): void
{
    if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create the isolated recursive-loader fixture.');
    }
}

function pcv25RecursiveRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (new DirectoryIterator($path) as $item) {
        if (!$item->isDot()) {
            pcv25RecursiveRemoveTree($item->getPathname());
        }
    }
    @rmdir($path);
}

function pcv25RecursiveStage(string $source, string $destination, bool $activeHook): string
{
    pcv25RecursiveMkdir($destination);
    foreach (PCV25_LOADER_STAGE_MAP as $name => $stagedName) {
        $from = $source . DIRECTORY_SEPARATOR . $name;
        $to = $destination . DIRECTORY_SEPARATOR . ($activeHook && $name === 'preprocessing.php' ? $name : $stagedName);
        pcv25RecursiveCheck(is_file($from) && !is_link($from) && @copy($from, $to),
            'Pinned PCV25 loader fixture file is missing: ' . $name . '.');
    }
    return $destination;
}

function pcv25RecursiveRunCaptured(string $fixtureRoot, string $capturedCore, string $tracePath, string $repo): array
{
    $test = $repo . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR
        . 'onboarding' . DIRECTORY_SEPARATOR . 'overlay.php';
    $source = @file_get_contents($test);
    pcv25RecursiveCheck(is_string($source), 'Could not read the unchanged offline loader fixture harness.');
    $harness = preg_replace('/^    require_once \$ownerRoot \. DIRECTORY_SEPARATOR \. \x27log\.php\x27;\r?\n/m', '', $source, 1, $removed);
    pcv25RecursiveCheck(is_string($harness) && $removed === 1,
        'Could not isolate the loader harness from its owner-first logger preload.');
    $isolatedHarness = $fixtureRoot . DIRECTORY_SEPARATOR . 'overlay-loader-harness.php';
    pcv25RecursiveCheck(file_put_contents($isolatedHarness, $harness, LOCK_EX) === strlen($harness),
        'Could not write the isolated scratch copy of the unchanged loader harness.');
    $process = proc_open([PHP_BINARY, '-n', $isolatedHarness, '--loader-run', '1', '--fixture-root', $fixtureRoot,
        '--captured-core', $capturedCore, '--trace-output', $tracePath],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo);
    pcv25RecursiveCheck(is_resource($process), 'Could not start the captured recursive-loader fixture.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $trace = json_decode((string)@file_get_contents($tracePath), true, 12, JSON_THROW_ON_ERROR);
    pcv25RecursiveCheck($exit === 0 && $stderr === '' && is_array($trace),
        'Captured recursive-loader child failed: exit=' . $exit . ' stdout=' . (string)$stdout . ' stderr=' . (string)$stderr);
    return $trace;
}

function pcv25RecursiveFixture(string $stageSource, string $supportRoot, string $order,
    bool $duplicate, bool $actualCompanion): array
{
    $fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcv25-recursive-loader-' . bin2hex(random_bytes(8));
    $ownerRoot = $fixture . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation';
    $overlayFolder = $order === 'owner-first' ? 'z-questionable_decisions' : 'a-questionable_decisions';
    $companionExtensionRoot = $fixture . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . $overlayFolder;
    $overlayRoot = $companionExtensionRoot
        . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'pcv-overlay';
    pcv25RecursiveStage($stageSource, $ownerRoot, true);
    pcv25RecursiveMkdir($overlayRoot);
    if ($actualCompanion) {
        foreach (['companion.php', 'preprocessing.php'] as $name) {
            $from = $supportRoot . DIRECTORY_SEPARATOR . $name;
            $to = $companionExtensionRoot . DIRECTORY_SEPARATOR . $name;
            pcv25RecursiveCheck(is_file($from) && !is_link($from) && @copy($from, $to),
                'Pinned candidate companion hook file is missing: ' . $name . '.');
        }
    }
    if ($duplicate) {
        pcv25RecursiveStage($stageSource, $overlayRoot, true);
    } else {
        pcv25RecursiveStage($stageSource, $overlayRoot, false);
    }
    return [
        'root' => $fixture, 'ownerRoot' => $ownerRoot, 'overlayRoot' => $overlayRoot,
        'companionExtensionRoot' => $companionExtensionRoot,
    ];
}

function pcv25RecursiveAssertOrder(array $trace, array $expectedPaths, string $label): void
{
    pcv25RecursiveCheck(($trace['hookCount'] ?? null) === count($expectedPaths)
        && ($trace['hookPaths'] ?? null) === $expectedPaths,
        $label . ' recursive hook order mismatch: ' . json_encode($trace, JSON_UNESCAPED_SLASHES));
}

function pcv25RecursiveRunCase(string $case, string $stageSource, string $supportRoot,
    string $capturedCore, string $repo): void
{
    $duplicate = in_array($case, ['owner-first', 'overlay-first'], true);
    $actualCompanion = in_array($case, ['candidate-owner-first', 'candidate-overlay-first'], true);
    $order = in_array($case, ['overlay-first', 'candidate-overlay-first'], true) ? 'overlay-first' : 'owner-first';
    $fixture = pcv25RecursiveFixture($stageSource, $supportRoot, $order, $duplicate, $actualCompanion);
    try {
        $trace = pcv25RecursiveRunCaptured($fixture['root'], $capturedCore,
            $fixture['root'] . DIRECTORY_SEPARATOR . 'trace.json', $repo);
        $ownerHook = str_replace('\\', '/', realpath($fixture['ownerRoot'] . DIRECTORY_SEPARATOR . 'preprocessing.php'));
        if ($duplicate) {
            $overlayHook = str_replace('\\', '/', realpath($fixture['overlayRoot'] . DIRECTORY_SEPARATOR . 'preprocessing.php'));
            $expected = $case === 'owner-first' ? [$ownerHook, $overlayHook] : [$overlayHook, $ownerHook];
            pcv25RecursiveAssertOrder($trace, $expected, $case);
            pcv25RecursiveCheck(($trace['httpStatus'] ?? null) === 409
                && ($trace['route'] ?? null) === 'solo_reflection'
                && ($trace['terminalReason'] ?? null) === 'background_paused',
                $case . ' duplicate-hook execution did not reproduce the routed background pause: '
                    . json_encode($trace, JSON_UNESCAPED_SLASHES));
        } elseif ($actualCompanion) {
            $companionHook = str_replace('\\', '/', realpath($fixture['companionExtensionRoot'] . DIRECTORY_SEPARATOR . 'preprocessing.php'));
            $expected = $case === 'candidate-owner-first' ? [$ownerHook, $companionHook] : [$companionHook, $ownerHook];
            pcv25RecursiveAssertOrder($trace, $expected, $case);
            pcv25RecursiveCheck(($trace['httpStatus'] ?? null) === 200
                && ($trace['requestType'] ?? null) === 'instruction'
                && ($trace['route'] ?? null) === 'solo_reflection'
                && ($trace['originRequestType'] ?? null) === 'inputtext'
                && ($trace['originDialogue'] ?? null) === 'Dragonborn: I wait quietly.'
                && ($trace['terminalReason'] ?? null) === null,
                $case . ' candidate companion hook changed ordinary Solo routing without a tagged packet: '
                    . json_encode($trace, JSON_UNESCAPED_SLASHES));
            pcv25RecursiveCheck(is_file($fixture['overlayRoot'] . DIRECTORY_SEPARATOR . 'preprocessing.php.template')
                && !file_exists($fixture['overlayRoot'] . DIRECTORY_SEPARATOR . 'preprocessing.php'),
                'Candidate setup stage exposed an active recursive preprocessing basename.');
        } else {
            pcv25RecursiveAssertOrder($trace, [$ownerHook], 'candidate-template');
            pcv25RecursiveCheck(($trace['httpStatus'] ?? null) === 200
                && ($trace['requestType'] ?? null) === 'instruction'
                && ($trace['route'] ?? null) === 'solo_reflection'
                && ($trace['originRequestType'] ?? null) === 'inputtext'
                && ($trace['originDialogue'] ?? null) === 'Dragonborn: I wait quietly.'
                && ($trace['terminalReason'] ?? null) === null,
                'Candidate inert-template route changed ordinary Solo processing: ' . json_encode($trace, JSON_UNESCAPED_SLASHES));
            pcv25RecursiveCheck(is_file($fixture['overlayRoot'] . DIRECTORY_SEPARATOR . 'preprocessing.php.template')
                && !file_exists($fixture['overlayRoot'] . DIRECTORY_SEPARATOR . 'preprocessing.php'),
                'Candidate setup stage exposed an active recursive preprocessing basename.');
        }
        echo 'PASS PCV25 recursive loader case: ' . $case . ' ' . json_encode($trace, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } finally {
        pcv25RecursiveRemoveTree($fixture['root']);
    }
}

$repo = dirname(__DIR__, 3);
try {
    $options = [];
    for ($index = 1; $index < count($argv); $index++) {
        if ($argv[$index] !== '--captured-core' || !isset($argv[$index + 1])) {
            throw new RuntimeException('Usage: recursive_loader_orders.php --captured-core <pinned-source-file>');
        }
        $options['captured-core'] = $argv[++$index];
    }
    $capturedCore = realpath($options['captured-core'] ?? '');
    pcv25RecursiveCheck(is_string($capturedCore) && is_file($capturedCore)
        && hash_equals(PCV25_CAPTURED_CORE_SHA256, (string)hash_file('sha256', $capturedCore)),
        'Captured CHIM recursive-loader source does not match the pinned offline source.');
    $stageSource = $repo . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR
        . 'private-conversation-v0.1.25' . DIRECTORY_SEPARATOR . 'server';
    $supportRoot = $repo . DIRECTORY_SEPARATOR . 'pcv-companion';
    pcv25RecursiveCheck(is_dir($stageSource), 'PCV25 owner stage is unavailable.');
    foreach (['owner-first', 'overlay-first', 'candidate-template', 'candidate-owner-first', 'candidate-overlay-first'] as $case) {
        pcv25RecursiveRunCase($case, $stageSource, $supportRoot, $capturedCore, $repo);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
