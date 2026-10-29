<?php
declare(strict_types=1);

function pcv25Check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pcv25Map(array $entries, string $key): array
{
    $map = [];
    foreach ($entries as $entry) {
        pcv25Check(is_array($entry) && is_string($entry['path'] ?? null), 'Source manifest has an invalid file entry.');
        $map[$entry['path']] = $entry[$key] ?? null;
    }
    return $map;
}

function pcv25MapsEqual(array $actual, array $expected): bool
{
    if (count($actual) !== count($expected)) {
        return false;
    }
    $actualKeys = array_keys($actual);
    $expectedKeys = array_keys($expected);
    sort($actualKeys, SORT_STRING);
    sort($expectedKeys, SORT_STRING);
    if ($actualKeys !== $expectedKeys) {
        return false;
    }
    foreach ($expected as $key => $value) {
        if (($actual[$key] ?? null) !== $value) {
            return false;
        }
    }
    return true;
}

$root = dirname(__DIR__, 3);
require_once $root . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'pcv.php';
require_once $root . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR . 'companion.php';
require_once $root . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'overlay_setup.php';

try {
    pcv25Check(QD_PCV_EXPECTED_VERSION === '0.1.25', 'Adapter owner version is not PCV 0.1.25.');
    pcv25Check(PCV_COMPANION_OWNER_VERSION === '0.1.25'
        && PCV_COMPANION_OWNER_UPSTREAM_COMMIT === '886a54967db7c6c08725c8bec8854ea3919ea532',
        'Companion owner identity is not pinned to the frozen PCV 0.1.25 source.');
    pcv25Check(QD_PCV_OVERLAY_VERSION === '0.1.25'
        && QD_PCV_OVERLAY_TAG === 'private_conversation-v0.1.25'
        && QD_PCV_OVERLAY_COMMIT === '886a54967db7c6c08725c8bec8854ea3919ea532',
        'Overlay loader identity is not pinned to the frozen PCV 0.1.25 source.');
    pcv25Check(($manifestPath = $root . DIRECTORY_SEPARATOR . 'packaging' . DIRECTORY_SEPARATOR . 'companion-only'
        . DIRECTORY_SEPARATOR . 'pcv-0.1.25-source-manifest.json') !== '' && is_file($manifestPath),
        'PCV 0.1.25 source manifest is missing.');

    $manifest = json_decode((string)file_get_contents($manifestPath), true, 16, JSON_THROW_ON_ERROR);
    pcv25Check(is_array($manifest) && array_keys($manifest) === ['schemaVersion', 'upstream', 'stagedSourceDirectory', 'installDirectory', 'files']
        && ($manifest['schemaVersion'] ?? null) === 1
        && ($manifest['upstream'] ?? null) === [
            'version' => '0.1.25',
            'tag' => 'private_conversation-v0.1.25',
            'commit' => '886a54967db7c6c08725c8bec8854ea3919ea532',
        ]
        && ($manifest['stagedSourceDirectory'] ?? null) === 'pcv-companion/private-conversation-v0.1.25/server'
        && ($manifest['installDirectory'] ?? null) === 'ext/private_conversation'
        && is_array($manifest['files'] ?? null) && array_is_list($manifest['files']),
        'PCV 0.1.25 owner source manifest has the wrong identity or shape.');

    $expectedNames = [
        'installation_guard.php', 'log.php', 'manifest.json', 'preprocessing.php', 'reflection.php', 'scope.php', 'state.php',
    ];
    $expectedStageNames = [
        'installation_guard.php' => 'installation_guard.php',
        'log.php' => 'log.php',
        'manifest.json' => 'manifest.json',
        'preprocessing.php' => 'preprocessing.php.template',
        'reflection.php' => 'reflection.php',
        'scope.php' => 'scope.php',
        'state.php' => 'state.php',
    ];
    pcv25Check(count($manifest['files']) === count($expectedNames), 'PCV 0.1.25 stage is not a seven-file closure.');
    $baseHashes = pcv25Map($manifest['files'], 'baseSha256');
    $postHashes = pcv25Map($manifest['files'], 'postimageSha256');
    $stageNames = pcv25Map($manifest['files'], 'stagedPath');
    pcv25Check(array_keys($baseHashes) === $expectedNames && array_keys($postHashes) === $expectedNames
        && $stageNames === $expectedStageNames, 'PCV 0.1.25 owner file order or inert hook mapping changed.');
    pcv25Check(pcv25MapsEqual(PCV_COMPANION_OWNER_BASE_SOURCE_HASHES, $baseHashes),
        'Companion base pins do not match the seven-file owner manifest.');
    pcv25Check(pcv25MapsEqual(PCV_COMPANION_OWNER_SOURCE_HASHES, $postHashes)
        && pcv25MapsEqual(QD_PCV_EXPECTED_OWNER_SOURCE_HASHES, $postHashes),
        'Adapter or companion postimage pins do not match the seven-file owner manifest.');

    $overlayPins = qd_pcv_overlay_pins();
    pcv25Check(array_keys($overlayPins) === $expectedNames, 'Overlay pin order is not the seven-file owner closure.');
    foreach ($expectedNames as $name) {
        pcv25Check(($overlayPins[$name]['base'] ?? null) === $baseHashes[$name]
            && ($overlayPins[$name]['post'] ?? null) === $postHashes[$name],
            'Overlay pins disagree with the owner source manifest for ' . $name . '.');
    }
    pcv25Check(qd_pcv_overlay_stage_names() === $expectedStageNames,
        'Overlay stage mapping would expose a second active preprocessing hook.');

    $stageRoot = $root . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR
        . 'private-conversation-v0.1.25' . DIRECTORY_SEPARATOR . 'server';
    $actualPaths = [];
    if (is_dir($stageRoot) && !is_link($stageRoot)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stageRoot, FilesystemIterator::SKIP_DOTS)) as $item) {
            pcv25Check($item->isFile() && !$item->isLink(), 'PCV 0.1.25 stage contains a non-file or symlink.');
            $actualPaths[] = substr($item->getPathname(), strlen($stageRoot) + 1);
        }
    }
    sort($actualPaths, SORT_STRING);
    $expectedPaths = $expectedNames;
    sort($expectedPaths, SORT_STRING);
    pcv25Check($actualPaths === $expectedPaths, 'PCV 0.1.25 stage contains files outside the seven-file boundary.');
    foreach ($manifest['files'] as $entry) {
        $actual = hash_file('sha256', $stageRoot . DIRECTORY_SEPARATOR . $entry['path']);
        pcv25Check(is_string($actual) && hash_equals($entry['postimageSha256'], $actual),
            'Staged PCV 0.1.25 postimage hash mismatch for ' . $entry['path'] . '.');
    }

    $supportManifest = json_decode((string)file_get_contents($root . DIRECTORY_SEPARATOR . 'pcv-companion'
        . DIRECTORY_SEPARATOR . 'manifest.json'), true, 8, JSON_THROW_ON_ERROR);
    pcv25Check(($supportManifest['version'] ?? null) === '0.1.0-prealpha.13',
        'PCV 0.1.25 source must have a distinct support companion identity.');

    echo "PASS PCV25 owner source contract: frozen identity, seven-file pin closure, inert hook mapping, support identity\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
