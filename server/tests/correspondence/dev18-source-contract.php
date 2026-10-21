<?php
declare(strict_types=1);

require_once __DIR__ . '/../../adapters/runtime_support.php';
require_once __DIR__ . '/../../adapters/correspondence.php';
require_once __DIR__ . '/../../lib/protocol.php';

function dev18_source_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function dev18_source_is_unavailable(callable $operation): bool
{
    try {
        $operation();
    } catch (QDServerFault $error) {
        return $error->wireCode === 'unavailable';
    } catch (Throwable) {
        return false;
    }
    return false;
}

function dev18_source_expect_route_refused(string $ownerRoot, string $mode): void
{
    if ($mode === 'corrupt') {
        if (file_put_contents($ownerRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'store.php', "<?php\n// pin drift\n") === false) {
            throw new RuntimeException('Could not corrupt the isolated pinned file.');
        }
    } elseif ($mode === 'dev14') {
        $legacyRoot = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'installed-dev14-core'
            . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'correspondence';
        foreach (['manifest.json', 'lib/runtime.php', 'lib/store.php', 'lib/boundaries.php'] as $relative) {
            $source = $legacyRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $target = $ownerRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!copy($source, $target)) throw new RuntimeException('Could not stage the valid dev14 replacement fixture.');
        }
        if (!qd_correspondence_manifest_ready($ownerRoot)) throw new RuntimeException('The dev14 replacement fixture is not a valid pinned legacy closure.');
    } else {
        throw new InvalidArgumentException('Unknown source drift mode.');
    }

    $scope = str_repeat('a', 64);
    $dispatchCurrentCalled = false;
    $accessContext = [
        'scopes' => ['inspector'],
        'scopeKey' => $scope,
        'assertCurrent' => static function () use (&$dispatchCurrentCalled): void { $dispatchCurrentCalled = true; },
    ];
    $dispatchRefused = dev18_source_is_unavailable(static fn() => qd_correspondence_dispatch('catalog', new stdClass(), $accessContext));

    $projectionCalled = false;
    $snapshotRefused = dev18_source_is_unavailable(static function () use (&$projectionCalled, $scope): void {
        qd_correspondence_read_snapshot($scope, static function () use (&$projectionCalled): array {
            $projectionCalled = true;
            return [];
        });
    });

    $contextCalled = false;
    $composeContext = [
        'scopes' => ['compose'],
        'assertCurrent' => static function (): void {},
        'withCurrentContext' => static function (callable $callback) use (&$contextCalled): mixed {
            $contextCalled = true;
            throw new RuntimeException('Compose reached the legacy current-context path.');
        },
    ];
    $payload = (object)[
        'operationId' => '12345678-1234-1234-1234-123456789abc',
        'writerActorKey' => 'player',
        'recipientActorKey' => 'npc:1',
        'body' => 'hello',
        'inventoryRevision' => 'revision',
        'attachments' => [],
        'goldAmount' => 0,
    ];
    $composeRefused = dev18_source_is_unavailable(static fn() => qd_correspondence_compose($payload, $composeContext));

    $failures = [];
    if (!$dispatchRefused || $dispatchCurrentCalled) $failures[] = 'dispatch/current-context';
    if (!$snapshotRefused || $projectionCalled) $failures[] = 'snapshot/projection';
    if (!$composeRefused || $contextCalled) $failures[] = 'compose/current-context';
    if ($failures !== []) {
        throw new RuntimeException('Same-process source drift failed to refuse before routing: ' . implode(', ', $failures));
    }
}

function dev18_source_child(string $coreRoot, string $mode): int
{
    putenv('QD_CHIM_ROOT=' . $coreRoot);
    require_once __DIR__ . '/../../adapters/runtime_support.php';
    require_once __DIR__ . '/../../adapters/correspondence.php';
    $ownerRoot = realpath($coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'correspondence');
    dev18_source_expect(is_string($ownerRoot), 'The staged owner source root is unavailable.');
    dev18_source_expect(qd_correspondence_runtime_ready(), 'The adapter did not accept the pinned dev18 source closure.');
    dev18_source_expect(qd_correspondence_load_sources() === $ownerRoot, 'The adapter loaded a different owner root.');
    dev18_source_expect(qd_correspondence_loaded_source_version() === '0.1.0-dev.18', 'The loaded owner version is not dev18.');
    foreach ([
        'CorrespondencePgStore' => 'lib/store.php',
        'CorrespondenceService' => 'lib/runtime.php',
    ] as $class => $relative) {
        $source = realpath((new ReflectionClass($class))->getFileName() ?: '');
        dev18_source_expect($source === realpath($ownerRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)), 'Unexpected owner class source: ' . $class);
    }
    foreach ([
        'correspondenceNativeConnection' => 'lib/bootstrap.php',
        'correspondenceBeginRequestIngress' => 'lib/bootstrap.php',
        'correspondenceCaptureRequestIngress' => 'lib/bootstrap.php',
        'correspondencePrepareCoreNote' => 'lib/boundaries.php',
    ] as $function => $relative) {
        dev18_source_expect(function_exists($function), 'Missing owner lifecycle function: ' . $function);
        $source = realpath((new ReflectionFunction($function))->getFileName() ?: '');
        dev18_source_expect($source === realpath($ownerRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)), 'Unexpected owner function source: ' . $function);
    }
    if ($mode !== 'accept') {
        dev18_source_expect_route_refused($ownerRoot, $mode);
        fwrite(STDOUT, 'PASS: dev18 source replacement (' . $mode . ") fails closed before projection or current-context work\n");
        return 0;
    }
    fwrite(STDOUT, "PASS: fresh child loaded the exact pinned dev18 owner graph\n");
    return 0;
}

function dev18_source_remove_tree(string $path): void
{
    if (!is_dir($path) || is_link($path)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        if ($item->isLink()) unlink($item->getPathname());
        elseif ($item->isDir()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($path);
}

if (($argv[1] ?? null) === '--child') {
    try {
        exit(dev18_source_child((string)($argv[2] ?? ''), (string)($argv[3] ?? 'accept')));
    } catch (Throwable $error) {
        fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
        exit(1);
    }
}

if (($argv[1] ?? null) !== '--owner-root' || !is_string($argv[2] ?? null) || $argv[2] === '') {
    fwrite(STDERR, 'Usage: php dev18-source-contract.php --owner-root <frozen-owner-server-root>' . "\n");
    exit(2);
}

$sourceRoot = realpath($argv[2]);
if (!is_string($sourceRoot) || !is_dir($sourceRoot)) {
    fwrite(STDERR, "FAIL: owner root is unavailable\n");
    exit(1);
}
$manifestBytes = @file_get_contents($sourceRoot . DIRECTORY_SEPARATOR . 'manifest.json');
$manifest = is_string($manifestBytes) ? json_decode($manifestBytes, true, 8) : null;
if (!is_array($manifest) || ($manifest['version'] ?? null) !== '0.1.0-dev.18') {
    fwrite(STDERR, "FAIL: owner root is not the frozen dev18 release\n");
    exit(1);
}
$expected = QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION['0.1.0-dev.18'] ?? null;
if (!is_array($expected) || !qd_correspondence_manifest_ready($sourceRoot)) {
    fwrite(STDERR, "FAIL: the dev18 owner closure is absent or differs from adapter pins\n");
    exit(1);
}

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-dept-dev18-source-' . bin2hex(random_bytes(8));
$coreRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'core';
$targetRoot = $coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'correspondence';
try {
    if (!mkdir($targetRoot . DIRECTORY_SEPARATOR . 'lib', 0700, true)) throw new RuntimeException('Could not create isolated fixture root.');
    if (!mkdir($targetRoot . DIRECTORY_SEPARATOR . 'migrations', 0700, true)) throw new RuntimeException('Could not create isolated migration root.');
    if (!mkdir($coreRoot . DIRECTORY_SEPARATOR . 'lib', 0700, true)) throw new RuntimeException('Could not create isolated core root.');
    if (@file_put_contents($coreRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_preferences.php', "<?php\n") === false) {
        throw new RuntimeException('Could not create the inert core-root probe marker.');
    }
    foreach ($expected as $relative => $expectedHash) {
        $source = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $realSource = realpath($source);
        if (!is_string($realSource) || is_link($source)
            || !str_starts_with($realSource, $sourceRoot . DIRECTORY_SEPARATOR)
            || !hash_equals($expectedHash, strtolower((string)hash_file('sha256', $realSource)))) {
            throw new RuntimeException('Refusing an unpinned or escaped owner input: ' . $relative);
        }
        $target = $targetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!@copy($realSource, $target) || !hash_equals($expectedHash, strtolower((string)hash_file('sha256', $target)))) {
            throw new RuntimeException('Could not preserve the pinned owner input: ' . $relative);
        }
    }
    foreach (['accept', 'corrupt', 'dev14'] as $mode) {
        if ($mode === 'dev14') {
            foreach ($expected as $relative => $expectedHash) {
                $source = $sourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $target = $targetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (!copy($source, $target) || !hash_equals($expectedHash, strtolower((string)hash_file('sha256', $target)))) {
                    throw new RuntimeException('Could not restore the isolated pinned dev18 fixture.');
                }
            }
        }
        $command = [PHP_BINARY, __FILE__, '--child', $coreRoot, $mode];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start the fresh PHP child.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) throw new RuntimeException('Fresh child failed in ' . $mode . ' mode: ' . trim((string)$stderr . ' ' . (string)$stdout));
        fwrite(STDOUT, trim((string)$stdout) . "\n");
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    dev18_source_remove_tree($temporaryRoot);
}
