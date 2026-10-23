<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/protocol.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/connect.php';

function qd_fixture_remove_tree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (new DirectoryIterator($path) as $item) {
        if ($item->isDot()) {
            continue;
        }
        $child = $item->getPathname();
        if ($item->isDir() && !$item->isLink()) {
            qd_fixture_remove_tree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

function qd_fixture_response(array $request, array $settings, int $now): array
{
    $response = qd_connect_handle(
        'POST',
        'application/json',
        json_encode($request, JSON_THROW_ON_ERROR),
        $settings,
        $now,
    );
    if ($response['status'] !== 200) {
        throw new RuntimeException('The PHP response producer returned HTTP ' . $response['status'] . ': ' . $response['body']);
    }
    return json_decode($response['body'], true, 8, JSON_THROW_ON_ERROR);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-connect-fixtures-' . bin2hex(random_bytes(8));
$webRoot = $root . DIRECTORY_SEPARATOR . 'web';
$privateRoot = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'questionable_decisions-private';
if (!mkdir($webRoot, 0700, true) || !mkdir($privateRoot, 0700, true)) {
    throw new RuntimeException('Could not create temporary fixture roots.');
}
$settings = [
    'pairingPath' => $privateRoot . DIRECTORY_SEPARATOR . 'pairing.php',
    'statePath' => $privateRoot . DIRECTORY_SEPARATOR . 'state.php',
    'webRoot' => $webRoot,
    'privateRoot' => $privateRoot,
    'chimRoot' => $root,
];
$now = 1800000000;
$tokenHash = hash('sha256', qd_new_token());
$expiredHash = hash('sha256', qd_new_token());
$pendingId = qd_new_uuid();
$expiredId = qd_new_uuid();
$approvedId = qd_new_uuid();

try {
    $beginPending = qd_fixture_response([
        'action' => 'begin', 'claimId' => $pendingId, 'tokenHash' => $tokenHash,
    ], $settings, $now);
    if (qd_connect_approve($settings, $pendingId, $now + 1) !== 'approved') {
        throw new RuntimeException('Could not approve the fixture claim.');
    }
    $statusApproved = qd_fixture_response([
        'action' => 'status', 'claimId' => $pendingId,
    ], $settings, $now + 2);
    $beginApproved = qd_fixture_response([
        'action' => 'begin', 'claimId' => $approvedId, 'tokenHash' => $tokenHash,
    ], $settings, $now + 3);

    qd_fixture_response([
        'action' => 'begin', 'claimId' => $expiredId, 'tokenHash' => $expiredHash,
    ], $settings, $now + 20);
    $beginExpired = qd_fixture_response([
        'action' => 'begin', 'claimId' => $expiredId, 'tokenHash' => $expiredHash,
    ], $settings, $now + QD_CONNECT_CLAIM_TTL + 20);
    $fixtures = [
        'beginPending' => $beginPending,
        'beginApproved' => $beginApproved,
        'beginExpired' => $beginExpired,
        'statusApproved' => $statusApproved,
    ];

    $json = json_encode($fixtures, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    $destination = $argv[1] ?? (getenv('DQL_CONNECTION_FIXTURES_PATH') ?: '-');
    if ($destination === '-') {
        echo $json;
    } elseif (file_put_contents($destination, $json, LOCK_EX) === false) {
        throw new RuntimeException('Could not write the requested fixture output path.');
    }
} finally {
    qd_fixture_remove_tree($root);
}
