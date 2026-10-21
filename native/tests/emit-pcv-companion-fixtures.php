<?php
declare(strict_types=1);

$implementationRoot = dirname(__DIR__, 2);
require_once $implementationRoot . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR . 'companion.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcv-native-serializer-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700, true)) {
    throw new RuntimeException('Could not create isolated temporary store.');
}
try {
    $operationId = 'da2b45a7-a9f7-4c1d-8f24-010203040506';
    $principalId = 'synthetic-native-serializer-principal';
    $scopeHash = hash('sha256', 'synthetic-save:character');
    $stage = [
        'operationId' => $operationId,
        'principalId' => $principalId,
        'scopeHash' => $scopeHash,
        'ownerKey' => hash('sha256', 'synthetic-owner'),
        'text' => 'Synthetic native serializer fixture body.',
        'target' => ['formId' => '00F00123', 'name' => 'Lydia'],
        'expectedActiveConfigId' => null,
        'expectedPendingConfigId' => 'b56c3651-114b-40c3-bff8-6b7a6f3ea52f',
        'context' => [
            'serverContext' => 'synthetic-server-context',
            'viewToken' => '9d24dc61-8b37-4b5d-b6b4-7416a10b0115',
            'nativeGeneration' => '17',
        ],
    ];
    $now = time();
    $prepared = pcv_companion_stage_record($root, $stage, $now, 120);
    $preparedStatus = pcv_companion_status_record($root, $principalId, $scopeHash, $operationId, $now + 1);
    $claimed = pcv_companion_claim_token($root, $prepared['token'], ['formId' => '00f00123', 'name' => 'Lydia'], $now + 2);
    if (($claimed['state'] ?? null) !== 'claimed' || !is_string($claimed['recordKey'] ?? null)) {
        throw new RuntimeException('The synthetic token claim failed.');
    }
    $claimedUnknownStatus = pcv_companion_status_record($root, $principalId, $scopeHash, $operationId, $now + 2);
    pcv_companion_finish_claim($root, $claimed['recordKey'], 'consumed', $now + 3);
    $consumedStatus = pcv_companion_status_record($root, $principalId, $scopeHash, $operationId, $now + 3);
    $missingUnknownStatus = pcv_companion_status_record(
        $root, $principalId, $scopeHash, 'da2b45a7-a9f7-4c1d-8f24-111213141516', $now + 3,
    );
    $fixture = [
        'stage' => $prepared,
        'preparedStatus' => $preparedStatus,
        'claimedUnknownStatus' => $claimedUnknownStatus,
        'consumedStatus' => $consumedStatus,
        'missingUnknownStatus' => $missingUnknownStatus,
    ];
    $fixtureDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures';
    if (!is_dir($fixtureDirectory) && !mkdir($fixtureDirectory, 0700, true) && !is_dir($fixtureDirectory)) {
        throw new RuntimeException('Could not create the native fixture directory.');
    }
    $output = $fixtureDirectory . DIRECTORY_SEPARATOR . 'php-companion-json-fixtures.json';
    $json = json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($output, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not write the serializer fixture.');
    }
    echo "PHP companion serializer fixtures emitted; token and literal withheld.\n";
} finally {
    $resolvedRoot = realpath($root);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if (is_string($resolvedRoot) && is_string($temporaryRoot)
        && str_starts_with($resolvedRoot . DIRECTORY_SEPARATOR, rtrim($temporaryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($resolvedRoot);
    }
}
