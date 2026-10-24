<?php
declare(strict_types=1);

const QD_BRAINWASH_EXPECTED_VERSION = '0.1.0-dev.2';
const QD_BRAINWASH_EXPECTED_SOURCE_HASHES_BY_VERSION = [
    '0.1.0-dev.2' => [
        'api.php' => ['lf' => 'ddf77a91a0f051cccba9918547b3c0ce01163cf8c341ceb68f416e1e2af89255', 'windowsCrlf' => '55314153df354cbf8bad91413725bbeb6d3fe205cea62cc7c9e3066c07fbd593'],
        'bin/provision-passphrase.php' => ['lf' => 'bd324af3b312c3a45cd9a8b9ded0a30b8e74e4a0bf10a1a10d528ab5faf2c1d0', 'windowsCrlf' => '04bf882da384ccb2d6d6917e05faad2b8ba4e62e33fd2d68e3a3864ee68d773a'],
        'context_pre.php' => ['lf' => '67f73134e4818ca513b1269ee1b2365f7696f737793ff6d48ee57b4cebd9120a', 'windowsCrlf' => '2604c771b6c57c0020495f68ed15606a3469b9dedc853f8ebaf163f6d5e75d5f'],
        'index.php' => ['lf' => '5b36c06b045b7a8ff66b9f6d4fc02640b05e90518d7d79dcb1985721338712c0', 'windowsCrlf' => '7ed0d470b62282f79c7e84bfc1c473687e1751e9bd90401d2682c2a95e2c2ae8'],
        'lib/service.php' => ['lf' => 'ebc53bb181873b27f295d68491205a9a9487fbe217a528f19286063da529a2af', 'windowsCrlf' => '96b19fe0fdf934faa7c085e319eb1bab097c5f40cdfed6f1da3c92dfe6391bae'],
        'lib/web.php' => ['lf' => '65b4db11e76bf24e8dda7cbce7c8dddf66b545da8476de3e2f310f81faae6c3b', 'windowsCrlf' => '8b515fd44cea6a008a8d58e796082f34a345a34347ad54c742b7a9176c6fff73'],
        'manifest.json' => ['lf' => '3de87ee72910bedfceb7e16383c4d344f3c30286ab09ca7eac959f306b989939', 'windowsCrlf' => 'c5f8cf16d4f24d3f2bc85e0b3ce702f4e5cb2db6ac000f7419136d336ab3e104'],
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
