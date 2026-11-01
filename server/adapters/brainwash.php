<?php
declare(strict_types=1);

const QD_BRAINWASH_EXPECTED_VERSION = '0.1.0-dev.6';
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
    '0.1.0-dev.4' => [
        'api.php' => ['lf' => 'b26658695d86654d10ab2d8c38df0b4ebbf5e37c0d3d42a5cf64ba489f7ff20c', 'windowsCrlf' => '1bdcd95cc3e9b3305d2aa374fcc64b7329e998e2b537879043bbb7d18e502eae'],
        'context_pre.php' => ['lf' => '67f73134e4818ca513b1269ee1b2365f7696f737793ff6d48ee57b4cebd9120a', 'windowsCrlf' => '2604c771b6c57c0020495f68ed15606a3469b9dedc853f8ebaf163f6d5e75d5f'],
        'index.php' => ['lf' => '81678c8fe10d67606d50c99cc9755976316ba90b75419e125c9a8c6aa8b65a38', 'windowsCrlf' => '35c784b42f792f0c8e448f50c5c9d35df88df201103f865a75d7b7b9965961c2'],
        'lib/service.php' => ['lf' => 'ebc53bb181873b27f295d68491205a9a9487fbe217a528f19286063da529a2af', 'windowsCrlf' => '96b19fe0fdf934faa7c085e319eb1bab097c5f40cdfed6f1da3c92dfe6391bae'],
        'lib/web.php' => ['lf' => '4ec97fc191fa72b4ff488032cec0286141ff94c9bc3545bb01e2043cd40c1073', 'windowsCrlf' => '2701a2825fd2839d035962e18daa37ea4e3d1dff370609b972d48a33e321a1a2'],
        'manifest.json' => ['lf' => '0205b5a1c401de1650ce9c4fe895e6cc6b9d09c5e311ecef45c9da3be7a55410', 'windowsCrlf' => '936d19bec3a0f56dcca7b5b895a29bd673435cefb0cb56fc0a051ab85f3df7c7'],
        'runtime.php' => ['lf' => '4cf0692bcc8034d2d9e69da9b6a7e8013e02b71cddfd5f173059397dd8fa34a1', 'windowsCrlf' => '675c6d78947ccba3dc1395a12cc62498a0f2d7b3f18abd362f174fc67d3ff2e7'],
    ],
    '0.1.0-dev.5' => [
        'api.php' => ['lf' => '4ae15803dcaa27ca5a20651fae42842125f1df40f5fc65dcfc678217827e23e3', 'windowsCrlf' => 'f5714a2232d31ffcc35961c07a8ded567e20d7b1ae90e47a3e4b9c6da3ff7352'],
        'assets/editor.css' => ['lf' => '2cc0bf0494f2cb447a9b1177d718dbd13c34c0658745e23413646aba9b7935d1', 'windowsCrlf' => '9ee6f77cfca09c548f6e4d80d1f1f276dc315030da04779e9b7058aed7227796'],
        'assets/editor.js' => ['lf' => '297e1f517c5b8854b65564500480fb874f7c490bd2e6ba41f9c5f5b201776883', 'windowsCrlf' => 'f79034933f6ccfcdb0e78afaecb202b39319a0578e1e8ea3ad32853959b9bc80'],
        'assets/web.js' => ['lf' => 'e7cd45f6664a7ba0ec92a3dd7d65917fadc7f672689d7b847e303b1ca92288dc', 'windowsCrlf' => '12eab2fe1c7cc75d30cc34b180a40c51facf10bdda4d2db3edc974d19f567e46'],
        'context_pre.php' => ['lf' => '67f73134e4818ca513b1269ee1b2365f7696f737793ff6d48ee57b4cebd9120a', 'windowsCrlf' => '2604c771b6c57c0020495f68ed15606a3469b9dedc853f8ebaf163f6d5e75d5f'],
        'index.php' => ['lf' => '81678c8fe10d67606d50c99cc9755976316ba90b75419e125c9a8c6aa8b65a38', 'windowsCrlf' => '35c784b42f792f0c8e448f50c5c9d35df88df201103f865a75d7b7b9965961c2'],
        'lib/authoring.php' => ['lf' => 'fafaa1a19b196cc7829757b7e8075b62c574b990977478ce579420f8c07dfb71', 'windowsCrlf' => '1818623a2a9b6eec3cf5b501b5e7253eafbaeac4f5695a05ad2d250ad3a4e055'],
        'lib/service.php' => ['lf' => '018b57b06bc11546bed83e8683c13e298062ed61f862a79a864eec96e587b9e8', 'windowsCrlf' => '4553eda3a74bb9c1002183ea6f8a8d80ce801aac8de80a894f7c030376292be8'],
        'lib/web.php' => ['lf' => '8590ce5e45ff75d990117e00901dcf240b9d0708d304d2aa3e72fa1a69fd46cd', 'windowsCrlf' => 'b7439ab843b1bc6a1cc5d26c3a16cc9726a839cce9dede85e9790498cfc75d91'],
        'manifest.json' => ['lf' => '910cea30d4e19d32abc2c54733c9bcc3cb17eec026901dd915f2e2f554777376', 'windowsCrlf' => '3000444c39fcc7e1b90669ab9d81d78244f4995afc76b4a12a125ab209582d39'],
        'runtime.php' => ['lf' => '3cf5af02bff7cd423d6d0fd226011c8726200a438c6e469881c16181fd72d3fb', 'windowsCrlf' => 'a5548f50f8cf4facd28e8226e71ec31eefe739f4db8d1dcef11321ea61d7a50a'],
    ],
    '0.1.0-dev.6' => [
        'api.php' => ['lf' => '4ae15803dcaa27ca5a20651fae42842125f1df40f5fc65dcfc678217827e23e3', 'windowsCrlf' => 'f5714a2232d31ffcc35961c07a8ded567e20d7b1ae90e47a3e4b9c6da3ff7352'],
        'assets/editor.css' => ['lf' => '5c52ec2e17a4dc72bb3d7029d6a23d3d9bd55f8039ef12d82cf7524ca3d8a954', 'windowsCrlf' => '6b5357d4568682c3de100125d9fb36f702202ee9e9b71c80e3f5619350edb097'],
        'assets/editor.js' => ['lf' => 'e494d2c3d403d3c71915dd47785d1b13f893b3026ee37151f9696fcb78089abf', 'windowsCrlf' => '05a57eebf7a692c33740be25c30184a267c032146fc0a063a9c08f2c6ccac681'],
        'assets/web.js' => ['lf' => 'e7cd45f6664a7ba0ec92a3dd7d65917fadc7f672689d7b847e303b1ca92288dc', 'windowsCrlf' => '12eab2fe1c7cc75d30cc34b180a40c51facf10bdda4d2db3edc974d19f567e46'],
        'context_pre.php' => ['lf' => '67f73134e4818ca513b1269ee1b2365f7696f737793ff6d48ee57b4cebd9120a', 'windowsCrlf' => '2604c771b6c57c0020495f68ed15606a3469b9dedc853f8ebaf163f6d5e75d5f'],
        'index.php' => ['lf' => 'afdabe1ff5119c16c021e11f4d9157c8601db4bf1672d2fa4508c49aa791decd', 'windowsCrlf' => '54ae8db99e5ef24ea38b9ef9752720fab422c34c073cfed115715e7c4324c94d'],
        'lib/authoring.php' => ['lf' => '3b30e9d4d135f8978b7dfca8f854f89b91207c29e35da4b3ba830ad6fa879586', 'windowsCrlf' => '60ec6d6c8c503e81f6ea827c34bd79b6c7c3f3c5b06de339e06ac6a5f95fa62b'],
        'lib/service.php' => ['lf' => '79f0f2df567ada9ce3dbb0b8371e677f477b644926448f613d9a0da39b7ef71c', 'windowsCrlf' => 'a6c81832b5d829212910600a40ef6912a0591499bc159bad20bdbd60572a7b4f'],
        'lib/web.php' => ['lf' => '8590ce5e45ff75d990117e00901dcf240b9d0708d304d2aa3e72fa1a69fd46cd', 'windowsCrlf' => 'b7439ab843b1bc6a1cc5d26c3a16cc9726a839cce9dede85e9790498cfc75d91'],
        'manifest.json' => ['lf' => '4f352f91a78380b28af3ecb261cbba9740949768a96c4d8ae685663a7ce1190f', 'windowsCrlf' => '35a36130254f2b8ec58c66facabaa956fcbbc569d0684c8737df88ad97edb462'],
        'runtime.php' => ['lf' => '3cf5af02bff7cd423d6d0fd226011c8726200a438c6e469881c16181fd72d3fb', 'windowsCrlf' => 'a5548f50f8cf4facd28e8226e71ec31eefe739f4db8d1dcef11321ea61d7a50a'],
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
    if ($operation==='brainwash.draft') {
        $dto=json_decode(json_encode($result,JSON_THROW_ON_ERROR),false,QD_MAX_JSON_DEPTH,JSON_THROW_ON_ERROR);
        if (!is_object($dto) || !qd_brainwash_valid_draft_authoring($dto)) throw new QDServerFault('unavailable');
    }
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
