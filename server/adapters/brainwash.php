<?php
declare(strict_types=1);

const QD_BRAINWASH_EXPECTED_VERSION = '0.1.0-dev.3';
const QD_BRAINWASH_EXPECTED_SOURCE_HASHES_BY_VERSION = [
    '0.1.0-dev.3' => [
        'api.php' => ['lf' => 'b26658695d86654d10ab2d8c38df0b4ebbf5e37c0d3d42a5cf64ba489f7ff20c', 'windowsCrlf' => '1bdcd95cc3e9b3305d2aa374fcc64b7329e998e2b537879043bbb7d18e502eae'],
        'context_pre.php' => ['lf' => '67f73134e4818ca513b1269ee1b2365f7696f737793ff6d48ee57b4cebd9120a', 'windowsCrlf' => '2604c771b6c57c0020495f68ed15606a3469b9dedc853f8ebaf163f6d5e75d5f'],
        'index.php' => ['lf' => '81678c8fe10d67606d50c99cc9755976316ba90b75419e125c9a8c6aa8b65a38', 'windowsCrlf' => '35c784b42f792f0c8e448f50c5c9d35df88df201103f865a75d7b7b9965961c2'],
        'lib/service.php' => ['lf' => 'ebc53bb181873b27f295d68491205a9a9487fbe217a528f19286063da529a2af', 'windowsCrlf' => '96b19fe0fdf934faa7c085e319eb1bab097c5f40cdfed6f1da3c92dfe6391bae'],
        'lib/web.php' => ['lf' => '4ec97fc191fa72b4ff488032cec0286141ff94c9bc3545bb01e2043cd40c1073', 'windowsCrlf' => '2701a2825fd2839d035962e18daa37ea4e3d1dff370609b972d48a33e321a1a2'],
        'manifest.json' => ['lf' => '92f18b9ade2ba7758f89fe6a54a5de968487d6670c64b198d80911a310be7c60', 'windowsCrlf' => 'fa92297b15bb3d7b18fe4c63af296c60307e5a813a81719e2eee76be13ddb5ef'],
        'runtime.php' => ['lf' => '6c2fd388b527f52a7899f878c68fbda2c9b5515cd5361849960fad33b44d2c62', 'windowsCrlf' => '88551da2b5a65b46ebc7cbf67eb22231ccf7952d5beda1de5caacc802c230644'],
    ],
];
const QD_BRAINWASH_OPERATIONS = [
    'brainwash.catalog.read', 'brainwash.card.read', 'brainwash.draft', 'brainwash.apply', 'brainwash.undo',
];
const QD_BRAINWASH_MUTATIONS = ['brainwash.draft', 'brainwash.apply', 'brainwash.undo'];
const QD_BRAINWASH_ERROR_CODES = ['invalid_request', 'not_found', 'stale_revision', 'stale_context', 'conflict', 'unavailable', 'forbidden'];

function qd_brainwash_source_root(): string
{
    $configured = getenv('QD_BRAINWASH_ROOT');
    if (!is_string($configured) || $configured === '') {
        $configured = qd_runtime_extension_root('brainwash');
    }
    $root = realpath($configured);
    if (!is_string($root) || !is_dir($root) || is_link($configured)) {
        throw new RuntimeException('The trusted Brainwash source root is unavailable.');
    }
    return $root;
}

function qd_brainwash_source_files(string $root): array
{
    $manifestPath = $root . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($manifestPath) || is_link($manifestPath)) return [];
    $manifestBytes = @file_get_contents($manifestPath);
    if (!is_string($manifestBytes) || strlen($manifestBytes) > 8192) return [];
    $manifest = json_decode($manifestBytes, true, 8);
    if (!is_array($manifest) || ($manifest['name'] ?? null) !== 'brainwash' || !is_string($manifest['version'] ?? null)) return [];
    $expectedHashes = QD_BRAINWASH_EXPECTED_SOURCE_HASHES_BY_VERSION[$manifest['version']] ?? null;
    if (!is_array($expectedHashes) || $expectedHashes === []) return [];
    $files = [];
    foreach ($expectedHashes as $relative => $_pins) {
        if (!is_string($relative) || $relative === '' || str_contains($relative, '\\')
            || preg_match('/\\A[a-zA-Z0-9_.\/-]+\\z/D', $relative) !== 1
            || in_array('..', explode('/', $relative), true)) return [];
        $files[$relative] = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
    return $files;
}

function qd_brainwash_owner_entry_path(string $root): ?string
{
    $root = realpath($root);
    if (!is_string($root) || !is_dir($root)) return null;
    $candidate = $root . DIRECTORY_SEPARATOR . 'runtime.php';
    if (!is_file($candidate) || is_link($candidate)) return null;
    $entry = realpath($candidate);
    if (!is_string($entry) || !str_starts_with($entry, $root . DIRECTORY_SEPARATOR)) return null;
    return $entry;
}

function qd_brainwash_manifest_ready(string $root): bool
{
    $root = realpath($root);
    if (!is_string($root) || !is_dir($root)) return false;
    $manifestPath = $root . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($manifestPath) || is_link($manifestPath)) return false;
    $manifestBytes = @file_get_contents($manifestPath);
    if (!is_string($manifestBytes) || strlen($manifestBytes) > 8192) return false;
    $manifest = json_decode($manifestBytes, true, 8);
    if (!is_array($manifest) || ($manifest['name'] ?? null) !== 'brainwash'
        || ($manifest['version'] ?? null) !== QD_BRAINWASH_EXPECTED_VERSION) return false;
    $pins = QD_BRAINWASH_EXPECTED_SOURCE_HASHES_BY_VERSION[$manifest['version']] ?? null;
    if (!is_array($pins) || $pins === [] || !array_key_exists('manifest.json', $pins)
        || !array_key_exists('runtime.php', $pins)) return false;
    $files = qd_brainwash_source_files($root);
    if (count($files) !== count($pins)) return false;
    foreach ($pins as $relative => $pin) {
        if (!is_array($pin) || !is_string($pin['lf'] ?? null) || !is_string($pin['windowsCrlf'] ?? null)
            || preg_match('/\\A[a-f0-9]{64}\\z/D', $pin['lf']) !== 1
            || preg_match('/\\A[a-f0-9]{64}\\z/D', $pin['windowsCrlf']) !== 1) return false;
        $path = $files[$relative] ?? null;
        if (!is_string($path) || !is_file($path) || is_link($path)) return false;
        $real = realpath($path);
        if (!is_string($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)
            || !qd_runtime_source_matches_pins($real, $pin['lf'], $pin['windowsCrlf'])) return false;
    }
    return true;
}

function qd_brainwash_load_owner_sources(): string
{
    static $loadedRoot = null;
    try {
        $root = qd_brainwash_source_root();
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
    if (!qd_brainwash_manifest_ready($root)) throw new QDServerFault('unavailable');
    if ($loadedRoot !== null && $loadedRoot !== $root) throw new QDServerFault('unavailable');
    $entry = qd_brainwash_owner_entry_path($root);
    if (!is_string($entry)) {
        throw new QDServerFault('unavailable');
    }
    try {
        require_once $entry;
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
    foreach (['brainwash_dispatch', 'brainwash_runtime_ready'] as $function) {
        if (!function_exists($function)
            || realpath((new ReflectionFunction($function))->getFileName() ?: '') !== $entry) {
            throw new QDServerFault('unavailable');
        }
    }
    $loadedRoot = $root;
    return $root;
}

function qd_brainwash_runtime_ready(): bool
{
    try {
        qd_brainwash_load_owner_sources();
        return brainwash_runtime_ready() === true;
    } catch (Throwable) {
        return false;
    }
}

function qd_brainwash_call(string $operation, stdClass $payload, array $context): array
{
    if (!in_array($operation, QD_BRAINWASH_OPERATIONS, true) || !qd_valid_string($context['scopeKey'] ?? null, 1024)) {
        throw new QDServerFault('unavailable');
    }
    qd_brainwash_load_owner_sources();
    if (brainwash_runtime_ready() !== true) throw new QDServerFault('unavailable');
    $ownerContext = ['scopeKey' => $context['scopeKey']];
    foreach (['assertCurrent', 'withCurrentContext', 'withLockedCurrentContext', 'beginCommit'] as $key) {
        if (is_callable($context[$key] ?? null)) $ownerContext[$key] = $context[$key];
    }
    if (in_array($operation, QD_BRAINWASH_MUTATIONS, true)) {
        if (!is_callable($ownerContext['beginCommit'] ?? null)
            || !is_callable($ownerContext['withLockedCurrentContext'] ?? null)) throw new QDServerFault('unavailable');
    }
    try {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $ownerPayload = json_decode($json, true, QD_MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        $result = brainwash_dispatch($operation, $ownerPayload, $ownerContext);
    } catch (Throwable $error) {
        if ($error instanceof BrainwashFault && in_array($error->wireCode ?? null, QD_BRAINWASH_ERROR_CODES, true)) {
            throw new QDServerFault($error->wireCode);
        }
        if ($error instanceof QDServerFault) throw $error;
        throw new QDServerFault('unavailable');
    }
    if (!is_array($result) || array_is_list($result)) throw new QDServerFault('unavailable');
    return $result;
}

function qd_brainwash_runtime_operations(): array
{
    $operations = [];
    foreach (QD_BRAINWASH_OPERATIONS as $operation) {
        $operations[$operation] = static fn(stdClass $payload, array $context): array => qd_brainwash_call($operation, $payload, $context);
    }
    return $operations;
}
