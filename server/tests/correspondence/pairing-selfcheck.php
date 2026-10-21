<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(0);

function pairing_selfcheck_args(array $argv): ?array
{
    $values = [];
    $allowed = ['--owner-root', '--department-root', '--published-department-root', '--dev14-root'];
    for ($i = 1, $count = count($argv); $i < $count; $i++) {
        $option = $argv[$i];
        if (!in_array($option, $allowed, true) || isset($values[$option])) return null;
        $value = $argv[++$i] ?? null;
        if (!is_string($value) || $value === '' || str_starts_with($value, '--')) return null;
        $values[$option] = $value;
    }
    foreach ($allowed as $required) {
        if (!isset($values[$required])) return null;
    }
    return $values;
}

function pairing_selfcheck_root(string $path): ?string
{
    if (strlen($path) > 4096 || str_contains($path, "\0") || is_link($path)) return null;
    $real = realpath($path);
    return is_string($real) && is_dir($real) && !is_link($real) ? $real : null;
}

function pairing_selfcheck_run(string $checker, string $ownerRoot, string $departmentRoot): array
{
    $command = [PHP_BINARY, $checker, '--owner-root', $ownerRoot, '--department-root', $departmentRoot];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('process_unavailable');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $receipt = is_string($stdout) ? json_decode($stdout, true) : null;
    if (!is_array($receipt)) throw new RuntimeException('receipt_invalid');
    return ['exit' => $exit, 'raw' => $stdout, 'receipt' => $receipt];
}

function pairing_selfcheck_copy(array $pins, string $sourceRoot, string $targetRoot): void
{
    foreach ($pins as $pin) {
        $relative = $pin['file'] ?? null;
        if (!is_string($relative) || $relative === '' || str_contains($relative, '\\') || str_contains($relative, ':')) {
            throw new RuntimeException('closure_invalid');
        }
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.' || $part === '..') throw new RuntimeException('closure_invalid');
        }
        $source = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_link($source) || !is_file($source) || !str_starts_with(realpath($source) ?: '', rtrim($sourceRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('source_invalid');
        }
        $target = $targetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $targetDirectory = dirname($target);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0700, true)) throw new RuntimeException('copy_failed');
        if (!copy($source, $target)) throw new RuntimeException('copy_failed');
    }
}

function pairing_selfcheck_remove(string $path, string $ownedRoot): bool
{
    $real = realpath($path);
    if (!is_string($real)) return true;
    $same = PHP_OS_FAMILY === 'Windows' ? strtolower($real) === strtolower($ownedRoot) : $real === $ownedRoot;
    $inside = PHP_OS_FAMILY === 'Windows'
        ? str_starts_with(strtolower($real), strtolower(rtrim($ownedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))
        : str_starts_with($real, rtrim($ownedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    if (!$same && !$inside) return false;
    try {
        foreach (new DirectoryIterator($real) as $entry) {
            if ($entry->isDot()) continue;
            $child = $entry->getPathname();
            if ($entry->isLink()) {
                if (!@unlink($child)) return false;
            } elseif ($entry->isDir()) {
                if (!pairing_selfcheck_remove($child, $ownedRoot)) return false;
            } elseif (!@unlink($child)) {
                return false;
            }
        }
    } catch (Throwable) {
        return false;
    }
    return @rmdir($real);
}


function pairing_selfcheck_owner_load_refusal(string $checker, string $tempRoot): bool
{
    $ownerRoot = $tempRoot . DIRECTORY_SEPARATOR . 'owner-load-probe';
    $departmentRoot = $tempRoot . DIRECTORY_SEPARATOR . 'department-load-probe';
    foreach ([
        $ownerRoot . DIRECTORY_SEPARATOR . 'lib',
        $departmentRoot . DIRECTORY_SEPARATOR . 'lib',
        $departmentRoot . DIRECTORY_SEPARATOR . 'adapters',
    ] as $directory) {
        if (!@mkdir($directory, 0700, true)) throw new RuntimeException('owner_load_setup_failed');
    }

    $manifest = json_encode([
        'name' => 'correspondence',
        'version' => '0.1.0-owner-load-probe',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $ownerStub = "<?php\n\$GLOBALS['pairing_owner_stub_loaded'] = true;\n";
    if (@file_put_contents($ownerRoot . DIRECTORY_SEPARATOR . 'manifest.json', $manifest) === false
        || @file_put_contents($ownerRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime.php', $ownerStub) === false
        || @file_put_contents($departmentRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'protocol.php', "<?php\ndeclare(strict_types=1);\n") === false) {
        throw new RuntimeException('owner_load_setup_failed');
    }

    $adapter = <<<'PAIRING'
<?php
declare(strict_types=1);
const QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION = [
    '0.1.0-owner-load-probe' => [
        'manifest.json' => '__MANIFEST_HASH__',
        'lib/runtime.php' => '__RUNTIME_HASH__',
    ],
];
function qd_correspondence_source_files(string $root): array
{
    return [
        'manifest.json' => $root . DIRECTORY_SEPARATOR . 'manifest.json',
        'lib/runtime.php' => $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime.php',
    ];
}
function qd_correspondence_manifest_ready(string $root): bool
{
    foreach (QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION['0.1.0-owner-load-probe'] as $relative => $expected) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $actual = @hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) return false;
    }
    return true;
}
function qd_correspondence_current_source_identity(string $root): ?array
{
    if (!qd_correspondence_manifest_ready($root)) return null;
    $manifestBytes = @file_get_contents($root . DIRECTORY_SEPARATOR . 'manifest.json');
    $real = realpath($root);
    if (!is_string($manifestBytes) || !is_string($real)) return null;
    return [
        'root' => $real,
        'version' => '0.1.0-owner-load-probe',
        'manifestHash' => hash('sha256', $manifestBytes),
    ];
}
function qd_correspondence_runtime_ready(): bool
{
    $root = qd_runtime_core_root() . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'correspondence';
    require $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime.php';
    return true;
}
PAIRING;
    $adapter = str_replace(
        ['__MANIFEST_HASH__', '__RUNTIME_HASH__'],
        [hash('sha256', $manifest), hash('sha256', $ownerStub)],
        $adapter
    );
    if (@file_put_contents($departmentRoot . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'correspondence.php', $adapter) === false) {
        throw new RuntimeException('owner_load_setup_failed');
    }

    $result = pairing_selfcheck_run($checker, $ownerRoot, $departmentRoot);
    return $result['exit'] !== 0
        && ($result['receipt']['ready'] ?? true) === false
        && ($result['receipt']['errorReason'] ?? null) === 'owner_runtime_loaded'
        && ($result['receipt']['limits']['ownerRuntimeLoaded'] ?? false) === true
        && ($result['receipt']['checks']['runtimeReady'] ?? false) === true
        && ($result['receipt']['checks']['stagedClosureMatches'] ?? false) === true;
}

function pairing_selfcheck(): array
{
    $cases = [
        'dev20Ready' => false,
        'deterministicReceipt' => false,
        'publishedPrealpha3RefusesDev20' => false,
        'dev14ControlReady' => false,
        'corruptedClosureRefused' => false,
        'unsupportedVersionRefused' => false,
        'ownerRuntimeLoadRefused' => false,
        'sourceOnlyLimits' => false,
    ];
    $tempBase = realpath(sys_get_temp_dir());
    $tempRoot = null;
    $failure = null;
    try {
        $arguments = pairing_selfcheck_args($GLOBALS['argv']);
        if (!is_array($arguments) || !is_string($tempBase) || is_link(sys_get_temp_dir())) throw new RuntimeException('arguments_invalid');
        $ownerRoot = pairing_selfcheck_root($arguments['--owner-root']);
        $departmentRoot = pairing_selfcheck_root($arguments['--department-root']);
        $publishedRoot = pairing_selfcheck_root($arguments['--published-department-root']);
        $dev14Root = pairing_selfcheck_root($arguments['--dev14-root']);
        if (!is_string($ownerRoot) || !is_string($departmentRoot) || !is_string($publishedRoot) || !is_string($dev14Root)) {
            throw new RuntimeException('unsafe_path');
        }
        $tempRoot = $tempBase . DIRECTORY_SEPARATOR . 'department-pairing-selfcheck-' . bin2hex(random_bytes(12));
        if (!mkdir($tempRoot, 0700) || is_link($tempRoot)) throw new RuntimeException('temp_unavailable');
        $checker = __DIR__ . DIRECTORY_SEPARATOR . 'check-owner-pairing.php';
        $current = pairing_selfcheck_run($checker, $ownerRoot, $departmentRoot);
        $second = pairing_selfcheck_run($checker, $ownerRoot, $departmentRoot);
        $receipt = $current['receipt'];
        $cases['dev20Ready'] = $current['exit'] === 0 && ($receipt['ready'] ?? false) === true
            && ($receipt['owner']['version'] ?? null) === '0.1.0-dev.20'
            && array_key_exists('errorReason', $receipt) && $receipt['errorReason'] === null
            && ($receipt['checks']['adapterLoaded'] ?? false) === true
            && ($receipt['checks']['manifestReady'] ?? false) === true
            && ($receipt['checks']['sourceIdentityReady'] ?? false) === true
            && ($receipt['checks']['runtimeReady'] ?? false) === true
            && ($receipt['checks']['stagedClosureMatches'] ?? false) === true;
        $cases['deterministicReceipt'] = $current['raw'] === $second['raw'];
        $cases['sourceOnlyLimits'] = array_keys($receipt['checks'] ?? []) === [
                'adapterLoaded', 'manifestReady', 'sourceIdentityReady', 'runtimeReady', 'stagedClosureMatches',
            ]
            && array_keys($receipt['limits'] ?? []) === [
                'sourceOnly', 'database', 'http', 'skyrim', 'ownerRuntimeLoaded',
            ]
            && array_keys($receipt['department'] ?? []) === ['protocol', 'adapter']
            && array_keys($receipt['department']['protocol'] ?? []) === ['file', 'sha256']
            && array_keys($receipt['department']['adapter'] ?? []) === ['file', 'sha256']
            && ($receipt['limits']['sourceOnly'] ?? false) === true
            && ($receipt['limits']['database'] ?? true) === false
            && ($receipt['limits']['http'] ?? true) === false
            && ($receipt['limits']['skyrim'] ?? true) === false
            && ($receipt['limits']['ownerRuntimeLoaded'] ?? true) === false
            && preg_match('/\A[a-f0-9]{64}\z/D', (string)($receipt['department']['protocol']['sha256'] ?? '')) === 1
            && preg_match('/\A[a-f0-9]{64}\z/D', (string)($receipt['department']['adapter']['sha256'] ?? '')) === 1;
        $published = pairing_selfcheck_run($checker, $ownerRoot, $publishedRoot);
        $cases['publishedPrealpha3RefusesDev20'] = $published['exit'] !== 0
            && ($published['receipt']['ready'] ?? true) === false
            && ($published['receipt']['errorReason'] ?? null) === 'owner_version_unsupported'
            && ($published['receipt']['owner']['version'] ?? null) === '0.1.0-dev.20'
            && ($published['receipt']['owner']['selectedClosurePins'] ?? null) === []
            && ($published['receipt']['checks']['runtimeReady'] ?? true) === false;
        $dev14 = pairing_selfcheck_run($checker, $dev14Root, $departmentRoot);
        $cases['dev14ControlReady'] = $dev14['exit'] === 0 && ($dev14['receipt']['ready'] ?? false) === true
            && ($dev14['receipt']['owner']['version'] ?? null) === '0.1.0-dev.14';
        $pins = $receipt['owner']['selectedClosurePins'] ?? null;
        if (!is_array($pins) || $pins === []) throw new RuntimeException('closure_missing');
        $corruptRoot = $tempRoot . DIRECTORY_SEPARATOR . 'corrupt';
        $unsupportedRoot = $tempRoot . DIRECTORY_SEPARATOR . 'unsupported';
        if (!mkdir($corruptRoot, 0700) || !mkdir($unsupportedRoot, 0700)) throw new RuntimeException('temp_unavailable');
        pairing_selfcheck_copy($pins, $ownerRoot, $corruptRoot);
        pairing_selfcheck_copy([['file' => 'manifest.json']], $ownerRoot, $unsupportedRoot);
        $runtimePath = $corruptRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime.php';
        $runtimeBytes = @file_get_contents($runtimePath);
        if (!is_string($runtimeBytes) || @file_put_contents($runtimePath, $runtimeBytes . "\n") === false) throw new RuntimeException('corrupt_setup_failed');
        $corrupt = pairing_selfcheck_run($checker, $corruptRoot, $departmentRoot);
        $cases['corruptedClosureRefused'] = $corrupt['exit'] !== 0
            && ($corrupt['receipt']['ready'] ?? true) === false
            && ($corrupt['receipt']['errorReason'] ?? null) === 'owner_source_drift';
        $manifestPath = $unsupportedRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = json_decode((string)@file_get_contents($manifestPath), true, 8, JSON_THROW_ON_ERROR);
        $manifest['version'] = '0.1.0-dev.999';
        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (@file_put_contents($manifestPath, $encoded) === false) throw new RuntimeException('unsupported_setup_failed');
        $unsupported = pairing_selfcheck_run($checker, $unsupportedRoot, $departmentRoot);
        $cases['unsupportedVersionRefused'] = $unsupported['exit'] !== 0
            && ($unsupported['receipt']['ready'] ?? true) === false
            && ($unsupported['receipt']['errorReason'] ?? null) === 'owner_version_unsupported';
        $cases['ownerRuntimeLoadRefused'] = pairing_selfcheck_owner_load_refusal($checker, $tempRoot);
    } catch (Throwable) {
        $failure = 'selfcheck_failed';
    } finally {
        if (is_string($tempRoot)) {
            $real = realpath($tempRoot);
            if (is_string($real) && dirname($real) === $tempBase
                && str_starts_with(basename($real), 'department-pairing-selfcheck-')
                && !pairing_selfcheck_remove($real, $real)) {
                $failure ??= 'selfcheck_cleanup_failed';
            }
        }
    }
    $failedCases = array_keys(array_filter($cases, static fn(bool $passed): bool => !$passed));
    return [
        'schemaVersion' => 1,
        'status' => $failure === null && $failedCases === [] ? 'PASS' : 'FAIL',
        'errorReason' => $failure,
        'cases' => $cases,
        'failedCases' => $failedCases,
    ];
}

$result = pairing_selfcheck();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
exit($result['status'] === 'PASS' ? 0 : 1);
