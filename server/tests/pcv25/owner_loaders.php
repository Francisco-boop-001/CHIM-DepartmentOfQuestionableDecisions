<?php
declare(strict_types=1);

const PCV25_LOADER_FILES = [
    'installation_guard.php', 'log.php', 'manifest.json', 'preprocessing.php', 'reflection.php', 'scope.php', 'state.php',
];
const PCV25_PREVIOUS_ADAPTER_SHA256 = '3644c2a55a51c1c5409de932be310ce5d201dca6298703400205ea9365df15be';
const PCV25_PREVIOUS_COMPANION_SHA256 = '8d4e15695044891e32fce5b10f76d1998dcb3cb2f5a02c3378e6670d6dab835f';

function pcv25LoaderCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pcv25LoaderMkdir(string $path): void
{
    if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Could not create the isolated owner-loader fixture.');
    }
}

function pcv25LoaderRemoveTree(string $path): void
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
            pcv25LoaderRemoveTree($item->getPathname());
        }
    }
    @rmdir($path);
}

function pcv25LoaderCopy(string $source, string $destination): void
{
    pcv25LoaderMkdir($destination);
    foreach (PCV25_LOADER_FILES as $name) {
        $from = $source . DIRECTORY_SEPARATOR . $name;
        pcv25LoaderCheck(is_file($from) && !is_link($from)
            && @copy($from, $destination . DIRECTORY_SEPARATOR . $name),
            'Pinned owner-loader fixture file is missing: ' . $name . '.');
    }
}

function pcv25LoaderTreeHashes(string $root): array
{
    $hashes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $item) {
        pcv25LoaderCheck($item->isFile() && !$item->isLink(), 'Owner-loader fixture contains a non-file or symlink.');
        $relative = substr($item->getPathname(), strlen($root) + 1);
        $hash = hash_file('sha256', $item->getPathname());
        pcv25LoaderCheck(is_string($hash), 'Could not hash owner-loader fixture file: ' . $relative . '.');
        $hashes[str_replace('\\', '/', $relative)] = strtolower($hash);
    }
    ksort($hashes, SORT_STRING);
    return $hashes;
}

function pcv25LoaderChild(string $root, string $adapterFile, string $companionFile): array
{
    $process = proc_open([PHP_BINARY, __FILE__, '--child', $root, $adapterFile, $companionFile],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    pcv25LoaderCheck(is_resource($process), 'Could not start isolated owner-loader process.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    pcv25LoaderCheck($exit === 0 && is_string($stdout) && $stderr === '',
        'Owner-loader child failed: exit=' . $exit . ' stdout=' . (string)$stdout . ' stderr=' . (string)$stderr);
    $result = json_decode(trim($stdout), true, 8, JSON_THROW_ON_ERROR);
    pcv25LoaderCheck(is_array($result), 'Owner-loader child returned invalid JSON.');
    return $result;
}

function pcv25LoaderCase(string $case, string $candidateStage, string $old24Stage,
    ?string $adapterFile = null, ?string $companionFile = null): void
{
    $fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcv25-owner-loader-' . bin2hex(random_bytes(8));
    try {
        $repo = dirname(__DIR__, 3);
        $ownerRoot = $fixture . DIRECTORY_SEPARATOR . 'private_conversation';
        pcv25LoaderCopy($case === 'pcv24' ? $old24Stage : $candidateStage, $ownerRoot);
        if ($case === 'mixed') {
            pcv25LoaderCheck(@copy($old24Stage . DIRECTORY_SEPARATOR . 'scope.php', $ownerRoot . DIRECTORY_SEPARATOR . 'scope.php'),
                'Could not create mixed PCV24/25 owner fixture.');
        } elseif ($case === 'tampered') {
            pcv25LoaderCheck(file_put_contents($ownerRoot . DIRECTORY_SEPARATOR . 'scope.php', "\n// tampered\n", FILE_APPEND | LOCK_EX) !== false,
                'Could not create the tampered owner fixture.');
        }
        $adapterFile ??= $repo . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'pcv.php';
        $companionFile ??= $repo . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR . 'companion.php';
        $before = pcv25LoaderTreeHashes($ownerRoot);
        $result = pcv25LoaderChild($ownerRoot, $adapterFile, $companionFile);
        $expectedAdapter = $case === 'candidate' || $case === 'previous-companion' ? 'loaded' : 'refused';
        $expectedCompanion = $case === 'candidate' || $case === 'previous-adapter' ? 'loaded' : 'refused';
        pcv25LoaderCheck(($result['adapter']['state'] ?? null) === $expectedAdapter
            && ($result['companion']['state'] ?? null) === $expectedCompanion,
            'PCV owner loaders did not accept the 0.1.25 postimage and fail closed for ' . $case . ': '
                . json_encode($result, JSON_UNESCAPED_SLASHES));
        pcv25LoaderCheck(pcv25LoaderTreeHashes($ownerRoot) === $before,
            'A mixed-version owner loader changed the PCV25 source tree for ' . $case . '.');
        if ($case === 'previous-adapter') {
            pcv25LoaderCheck(($result['adapter']['reason'] ?? null) === 'The pinned PCV source files are unavailable.',
                'Public26 adapter did not refuse the PCV25 source for the pinned-source mismatch.');
        } elseif ($case === 'previous-companion') {
            pcv25LoaderCheck(($result['companion']['reason'] ?? null) === 'The pinned Private Conversation owner source is incompatible.',
                'Public26 companion did not refuse the PCV25 source for the pinned-owner mismatch.');
        }
        echo 'PASS PCV25 owner loader case: ' . $case . ' ' . json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } finally {
        pcv25LoaderRemoveTree($fixture);
    }
}

if (($argv[1] ?? null) === '--child') {
    $ownerRoot = realpath($argv[2] ?? '');
    $adapterFile = realpath($argv[3] ?? '');
    $companionFile = realpath($argv[4] ?? '');
    pcv25LoaderCheck(is_string($ownerRoot) && is_dir($ownerRoot) && is_string($adapterFile) && is_file($adapterFile)
        && !is_link($adapterFile) && is_string($companionFile) && is_file($companionFile) && !is_link($companionFile),
        'Invalid owner-loader fixture paths.');
    putenv('QD_PCV_ROOT=' . $ownerRoot);
    require_once $adapterFile;
    require_once $companionFile;
    $run = static function (callable $loader): array {
        try {
            $loader();
            return ['state' => 'loaded'];
        } catch (Throwable $error) {
            return ['state' => 'refused', 'reason' => $error->getMessage()];
        }
    };
    $adapter = $run(static fn() => qd_pcv_load_owner_sources());
    $companion = $run(static fn() => pcv_companion_load_owner_sources(true));
    echo json_encode(['adapter' => $adapter, 'companion' => $companion], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

$repo = dirname(__DIR__, 3);
try {
    $previousRoot = null;
    for ($index = 1; $index < count($argv); $index++) {
        if ($argv[$index] !== '--previous-reader-root' || !isset($argv[$index + 1]) || $previousRoot !== null) {
            throw new RuntimeException('Usage: owner_loaders.php [--previous-reader-root <pinned-public26-source>]');
        }
        $previousRoot = realpath($argv[++$index]);
        pcv25LoaderCheck(is_string($previousRoot) && is_dir($previousRoot), 'Previous reader source root is invalid.');
    }
    $candidateStage = $repo . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR
        . 'private-conversation-v0.1.25' . DIRECTORY_SEPARATOR . 'server';
    $old24Stage = $repo . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR
        . 'private-conversation-v0.1.24' . DIRECTORY_SEPARATOR . 'server';
    pcv25LoaderCheck(is_dir($candidateStage) && is_dir($old24Stage), 'Pinned owner source stages are unavailable.');
    foreach (['candidate', 'pcv24', 'mixed', 'tampered'] as $case) {
        pcv25LoaderCase($case, $candidateStage, $old24Stage);
    }
    if (is_string($previousRoot)) {
        $previousAdapter = $previousRoot . DIRECTORY_SEPARATOR . 'adapter.php';
        $previousCompanion = $previousRoot . DIRECTORY_SEPARATOR . 'companion.php';
        pcv25LoaderCheck(is_file($previousAdapter) && !is_link($previousAdapter)
            && hash_file('sha256', $previousAdapter) === PCV25_PREVIOUS_ADAPTER_SHA256,
            'Previous public26 adapter does not match its pinned source SHA-256.');
        pcv25LoaderCheck(is_file($previousCompanion) && !is_link($previousCompanion)
            && hash_file('sha256', $previousCompanion) === PCV25_PREVIOUS_COMPANION_SHA256,
            'Previous public26 companion does not match its pinned source SHA-256.');
        $currentAdapter = $repo . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'pcv.php';
        $currentCompanion = $repo . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR . 'companion.php';
        pcv25LoaderCase('previous-adapter', $candidateStage, $old24Stage, $previousAdapter, $currentCompanion);
        pcv25LoaderCase('previous-companion', $candidateStage, $old24Stage, $currentAdapter, $previousCompanion);
        echo 'Pinned previous public26 reader SHA-256: adapter=' . PCV25_PREVIOUS_ADAPTER_SHA256
            . ' companion=' . PCV25_PREVIOUS_COMPANION_SHA256 . PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
