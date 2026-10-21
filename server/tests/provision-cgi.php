<?php
declare(strict_types=1);

$checks = 0;

function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function child_environment(string $phpDirectory, array $overrides = []): array
{
    $systemRoot = getenv('SystemRoot');
    $windows = getenv('WINDIR');
    $base = [
        'SystemRoot' => is_string($systemRoot) && $systemRoot !== '' ? $systemRoot : 'C:\\Windows',
        'WINDIR' => is_string($windows) && $windows !== '' ? $windows : 'C:\\Windows',
        'TEMP' => sys_get_temp_dir(),
        'TMP' => sys_get_temp_dir(),
        'PATH' => $phpDirectory,
    ];
    return array_merge($base, $overrides);
}

function run_child(array $command, array $environment, string $cwd): array
{
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        $environment,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start a local PHP test child.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return ['exitCode' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

function remove_tree(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $path = $item->getPathname();
        if (is_link($path)) {
            unlink($path);
        } elseif ($item->isDir()) {
            rmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($root);
}

$testDirectory = realpath(__DIR__);
$provision = realpath(__DIR__ . '/../tools/provision.php');
$configuredCli = getenv('QD_TEST_PHP_CLI');
$configuredCgi = getenv('QD_TEST_PHP_CGI');
$phpCli = is_string($configuredCli) && $configuredCli !== '' ? realpath($configuredCli) : realpath(PHP_BINARY);
$phpCgi = is_string($configuredCgi) && $configuredCgi !== ''
    ? realpath($configuredCgi)
    : (is_string($phpCli) ? realpath(dirname($phpCli) . DIRECTORY_SEPARATOR . 'php-cgi.exe') : false);
if (!is_string($testDirectory) || !is_string($provision) || !is_string($phpCli) || !is_file($phpCli) || !is_string($phpCgi) || !is_file($phpCgi)) {
    throw new RuntimeException('A local PHP CLI and sibling CGI runtime are required for this regression.');
}

$cgiEnvironment = child_environment(dirname($phpCgi), [
    'REDIRECT_STATUS' => '200',
    'SCRIPT_FILENAME' => $provision,
    'SCRIPT_NAME' => '/tools/provision.php',
    'REQUEST_METHOD' => 'GET',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'GATEWAY_INTERFACE' => 'CGI/1.1',
    'REQUEST_URI' => '/tools/provision.php',
    'SERVER_NAME' => 'localhost',
    'SERVER_PORT' => '80',
    'QUERY_STRING' => '',
    'CONTENT_TYPE' => '',
    'CONTENT_LENGTH' => '0',
]);
$cgi = run_child([$phpCgi, '-n', '-f', $provision], $cgiEnvironment, dirname($testDirectory));
check($cgi['exitCode'] === 0, 'CGI refusal must exit normally.');
check(preg_match('/\AStatus:\s*403\b/im', $cgi['stdout']) === 1, 'CGI request must receive HTTP 403.');
check(preg_match('/\r?\n\r?\n/', $cgi['stdout'], $separator, PREG_OFFSET_CAPTURE) === 1, 'CGI response must contain a header/body boundary.');
$bodyOffset = $separator[0][1] + strlen($separator[0][0]);
check(substr($cgi['stdout'], $bodyOffset) === '', 'CGI refusal must not disclose a diagnostic body.');
check($cgi['stderr'] === '' && preg_match('/Warning|Notice|Fatal error|Parse error|Stack trace/i', $cgi['stdout']) !== 1, 'CGI refusal must be free of PHP diagnostics.');

$cliEnvironment = child_environment(dirname($phpCli));
$usage = run_child([$phpCli, '-n', $provision], $cliEnvironment, dirname($testDirectory));
check($usage['exitCode'] === 2 && $usage['stdout'] === '' && str_starts_with($usage['stderr'], "Usage:\n"), 'CLI usage guard must retain its exit code and usage output.');

$testRoot = $testDirectory . DIRECTORY_SEPARATOR . '.provision-cgi-test-' . bin2hex(random_bytes(8));
if (strpos($testRoot, $testDirectory . DIRECTORY_SEPARATOR) !== 0) {
    throw new RuntimeException('Refusing to create a CLI regression fixture outside server/tests.');
}
$webRoot = $testRoot . DIRECTORY_SEPARATOR . 'web';
$privateRoot = $testRoot . DIRECTORY_SEPARATOR . 'private';
$pairingPath = $privateRoot . DIRECTORY_SEPARATOR . 'pairing.json';
mkdir($webRoot, 0700, true);
mkdir($privateRoot, 0700, true);
try {
    $issue = run_child(
        [$phpCli, '-n', $provision, 'issue', $pairingPath, $webRoot, 'pcv,inspector,compose,author_detail'],
        $cliEnvironment,
        dirname($testDirectory),
    );
    check($issue['exitCode'] === 0 && $issue['stderr'] === '', 'CLI issue must remain successful without diagnostics.');
    $issued = json_decode($issue['stdout'], true, 8, JSON_THROW_ON_ERROR);
    check(
        is_array($issued)
            && preg_match('/\A[0-9a-f-]{36}\z/D', (string)($issued['pairId'] ?? '')) === 1
            && preg_match('/\Aqd1_[A-Za-z0-9_-]{43}\z/D', (string)($issued['token'] ?? '')) === 1
            && ($issued['scopes'] ?? null) === ['author_detail', 'compose', 'inspector', 'pcv'],
        'CLI issue must preserve the pair token format and supported scope issuance.',
    );
    $pairId = $issued['pairId'];
    $token = $issued['token'];
    $revoke = run_child([$phpCli, '-n', $provision, 'revoke', $pairingPath, $webRoot, $pairId], $cliEnvironment, dirname($testDirectory));
    check($revoke['exitCode'] === 0 && $revoke['stdout'] === 'Revoked ' . $pairId . PHP_EOL && $revoke['stderr'] === '', 'CLI revoke must remain successful without diagnostics.');
    check(!str_contains($revoke['stdout'] . $revoke['stderr'], $token), 'CLI revoke must not emit the pair token.');
    $stored = json_decode((string)file_get_contents($pairingPath), true, 8, JSON_THROW_ON_ERROR);
    check(($stored['pairs'][0]['revoked'] ?? null) === true, 'CLI revoke must persist revocation.');
} finally {
    remove_tree($testRoot);
}

echo 'PASS provision CGI refusal and CLI guard/issue/revoke (' . $checks . " assertions)\n";
