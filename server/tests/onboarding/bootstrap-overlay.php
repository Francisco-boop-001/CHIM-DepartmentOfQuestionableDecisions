<?php
declare(strict_types=1);

require_once __DIR__ . '/../../api.php';

function bootstrap_overlay_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function bootstrap_overlay_remove_tree(string $path): void
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
            bootstrap_overlay_remove_tree($item->getPathname());
        }
    }
    @rmdir($path);
}

$fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-bootstrap-overlay-' . bin2hex(random_bytes(8));
$webRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'web';
$privateRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'questionable_decisions-private';
if (!@mkdir($webRoot, 0700, true) || !@mkdir($privateRoot, 0700, true)) {
    throw new RuntimeException('Could not create isolated bootstrap roots.');
}
$settings = [
    'pairingPath' => $privateRoot . DIRECTORY_SEPARATOR . 'pairing.php',
    'statePath' => $privateRoot . DIRECTORY_SEPARATOR . 'state.php',
    'webRoot' => $webRoot,
    'privateRoot' => $privateRoot,
    'coreRoot' => null,
    'chimRoot' => null,
    'pcvRoot' => null,
];

try {
    $pair = qd_pair_issue($settings['pairingPath'], $webRoot, ['pcv', 'inspector', 'compose'], $privateRoot);
    $viewToken = qd_new_uuid();
    $body = json_encode((object)[
        'version' => 1,
        'requestId' => qd_new_uuid(),
        'operation' => 'bootstrap',
        'context' => (object)[
            'viewToken' => $viewToken,
            'nativeGeneration' => '0',
            'serverContext' => null,
        ],
        'payload' => (object)[],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $readinessArguments = [];
    $dependencies = [
        'withProfileLease' => static fn(callable $callback): mixed => $callback(),
        'currentScope' => static fn(string $pairId): string => 'synthetic-profile',
        'readiness' => static function (bool $includePcv = true) use (&$readinessArguments): array {
            $readinessArguments[] = $includePcv;
            return ['pcv' => true, 'mp' => true, 'sas' => true, 'correspondence' => true, 'brainwash' => true];
        },
        'perspectives' => static fn(string $scopeKey): array => [
            'mp' => ['choices' => [], 'limited' => true],
            'sas' => ['choices' => [], 'limited' => true],
        ],
    ];
    $response = qd_api_handle('POST', 'application/json', $body, 'Bearer ' . $pair['token'], $settings, $dependencies);
    bootstrap_overlay_expect(($response['status'] ?? null) === 200, 'An unavailable optional overlay must not fail authenticated bootstrap.');
    $decoded = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
    $data = $decoded['data'] ?? null;
    bootstrap_overlay_expect(is_array($data) && ($data['authenticated'] ?? null) === true,
        'The installed game should remain authenticated when optional PCV setup is unavailable.');
    $expectedCapabilities = ['pcv' => false, 'mp' => true, 'sas' => true, 'correspondence' => true, 'brainwash' => true];
    bootstrap_overlay_expect(($data['capabilities'] ?? null) === $expectedCapabilities,
        'A refused PCV overlay must leave all four independent plugin capabilities ready with the full schema.');
    bootstrap_overlay_expect(array_keys($decoded) === ['version', 'requestId', 'operation', 'context', 'ok', 'data']
        && array_keys($data) === ['authenticated', 'authRequired', 'serverContext', 'capabilities', 'access', 'perspectives', 'scopeToken'],
        'Refusing optional PCV setup must preserve the authenticated bootstrap wire shape.');
    bootstrap_overlay_expect($readinessArguments === [false],
        'A refused overlay must suppress PCV owner readiness before profile/runtime callbacks run.');
    bootstrap_overlay_expect(!array_key_exists('pcvSetup', $data), 'Bootstrap must keep its existing wire shape.');
} finally {
    bootstrap_overlay_remove_tree($fixtureRoot);
}

echo "PASS onboarding bootstrap-overlay\n";
