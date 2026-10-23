<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/protocol.php';
require_once __DIR__ . '/../../lib/auth.php';

function guard_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function guard_remove_tree(string $path): void
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
            guard_remove_tree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-http-guard-' . bin2hex(random_bytes(8));
$webRoot = $root . DIRECTORY_SEPARATOR . 'web';
if (!mkdir($webRoot, 0700, true) || !mkdir($webRoot . DIRECTORY_SEPARATOR . 'data', 0700)) {
    throw new RuntimeException('Could not create the HTTP guard fixture.');
}
$privateRoot = qd_default_private_root($webRoot);
if (!is_string($privateRoot)) {
    guard_remove_tree($root);
    throw new RuntimeException('Could not create the guarded private data root.');
}
$pairingPath = $privateRoot . DIRECTORY_SEPARATOR . 'pairing.php';
qd_pair_issue($pairingPath, $webRoot, ['pcv'], $privateRoot);
$socket = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($socket === false) {
    guard_remove_tree($root);
    throw new RuntimeException('Could not reserve a local HTTP test port.');
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
if (!is_string($address) || !preg_match('/\A127\.0\.0\.1:([0-9]+)\z/D', $address, $matches)) {
    guard_remove_tree($root);
    throw new RuntimeException('Could not resolve the local HTTP test port.');
}
$url = 'http://127.0.0.1:' . $matches[1] . '/data/questionable_decisions-private/pairing.php';
$process = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $matches[1], '-t', $webRoot],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root,
    null,
    ['bypass_shell' => true],
);
if (!is_resource($process)) {
    guard_remove_tree($root);
    throw new RuntimeException('Could not start the local PHP HTTP test server.');
}
try {
    $response = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $response = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]));
        if (is_string($response) || isset($http_response_header)) {
            break;
        }
        usleep(100000);
    }
    $statusLine = is_array($http_response_header ?? null) ? ($http_response_header[0] ?? '') : '';
    guard_expect(is_string($statusLine) && preg_match('/\AHTTP\/\S+ 404\b/', $statusLine) === 1, 'direct HTTP access to guarded pairing data must return 404');
    guard_expect($response === '' || $response === false, 'direct HTTP access must not return the stored JSON');
    guard_expect(!str_contains(is_string($response) ? $response : '', 'tokenHash'), 'direct HTTP access must not disclose pairing fields');
} finally {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_terminate($process);
    proc_close($process);
    guard_remove_tree($root);
}

echo "PASS guarded private data returns HTTP 404 without JSON\n";
