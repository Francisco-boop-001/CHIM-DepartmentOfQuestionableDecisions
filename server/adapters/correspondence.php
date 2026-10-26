<?php
declare(strict_types=1);

const QD_CORRESPONDENCE_MAILBOX_SCAN_MAX = 512;
const QD_CORRESPONDENCE_PAGE_MAX = 100;
const QD_CORRESPONDENCE_PAGE_BYTES = 48000;
const QD_CORRESPONDENCE_CURSOR_TTL = 900;
const QD_CORRESPONDENCE_SOURCE_FILES = [
    'manifest.json',
    'lib/runtime.php',
    'lib/store.php',
    'lib/boundaries.php',
];
const QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION = [
    '0.1.0-dev.14' => [
        'manifest.json' => '2c81726edb680dfc259a79bc53cbafcac6687dfd7a2e45eb2503dd365ebecb61',
        'lib/runtime.php' => 'cdea28ae1171057ef0b221a9f78509035a91d6c26a5da4086d5e01a3857d29ab',
        'lib/store.php' => '5efa9c1c40178b50351f3b005eca431e91f0f678f489f66af64b9c82e413ba0f',
        'lib/boundaries.php' => '21034b79e36d208b30ed0ea5aa6892581ae6749c5a9190a3b4a422f4f74b7d0f',
    ],
    '0.1.0-dev.15' => [
        'manifest.json' => '26501566199e0185ce90003ab8c28328b2bb4491e3fdddbbf97bef8d70d7871e',
        'lib/runtime.php' => 'cdea28ae1171057ef0b221a9f78509035a91d6c26a5da4086d5e01a3857d29ab',
        'lib/store.php' => '5efa9c1c40178b50351f3b005eca431e91f0f678f489f66af64b9c82e413ba0f',
        'lib/boundaries.php' => '21034b79e36d208b30ed0ea5aa6892581ae6749c5a9190a3b4a422f4f74b7d0f',
    ],
    '0.1.0-dev.16' => [
        'manifest.json' => '1e8cb0fe3178de6ee2a1c9345e7584a955e195bb395e192a58e1f6b2491c44c2',
        'lib/runtime.php' => '3cd78e5c0a4023fadea358a255b8e2f4bb996aebc93ea107ec8582d145d6764c',
        'lib/store.php' => '5efa9c1c40178b50351f3b005eca431e91f0f678f489f66af64b9c82e413ba0f',
        'lib/boundaries.php' => '21034b79e36d208b30ed0ea5aa6892581ae6749c5a9190a3b4a422f4f74b7d0f',
    ],
    '0.1.0-dev.17' => [
        'manifest.json' => '0f1cdc0542bce5060eee5a3c4956413be180d11d15351f1808d5cc016c7d9ebf',
        'lib/runtime.php' => 'd885fd83610f87d414f0c6faa1d2bda18d88fc2f39dcc59fdade5660def36e81',
        'lib/store.php' => '2dd57b2d8067a8ce80b8cee25c7ce1be0f43284278b2bb34883f5dfcfd7b2b87',
        'lib/boundaries.php' => '21034b79e36d208b30ed0ea5aa6892581ae6749c5a9190a3b4a422f4f74b7d0f',
    ],
    '0.1.0-dev.18' => [
        'manifest.json' => '669ca4ae3e20a8a2d551fcc3b0f7525b4004f041c8cf442ee0ab246fbf99eef6',
        'lib/runtime.php' => '3d71593fe70b3a64fac7de25f142fc860a9d9a79575fdefc1ddd957fde8d9af6',
        'lib/store.php' => '5a91e6d0722ef81c10b5cbebc11d26773da8bbd9b06da0ffc7a3c734fe17fefa',
        'lib/boundaries.php' => '21034b79e36d208b30ed0ea5aa6892581ae6749c5a9190a3b4a422f4f74b7d0f',
        'lib/bootstrap.php' => 'ec19a0eb40111fb03b99a788f73f55c03f00b8384c768e022009c8f94b2b4129',
        'lib/autonomy_policy.php' => 'db83aa3f1bc85c80c12c57294262ee7d4c93dc4bfcbd9eef4de8ced31b6fba18',
        'lib/autonomy_store.php' => 'ee541b8764131508564267461fff8ba09308f8a6e6a715d40586531c8e030ae2',
        'lib/autonomy_host.php' => 'dfa257dbb68f1ade87b7f13750e4fb25dd306c695b3c4ddd070e7ca1a4140a61',
        'lib/offscreen_policy.php' => '23639437e41eafaebd98ec85ce81cf3815ac51365f036c9e33b373fc17504058',
        'lib/offscreen.php' => 'ee777ec2efcfcf61d6164145964a41b46aa19219f66e24e84226daa38b960328',
        'lib/offscreen_host.php' => '7c036bf86978acc8349742ba4a172d0dcc4a8ef9b8ab8b2b97b321686bc09bdb',
        'migrations/001_initial.sql' => 'b2052570036697147dacb5d041c0a692294af78a83f946e7819d83235e16dc99',
        'migrations/004_restore_state.sql' => 'd6a13622ece3af538996558d892852c5126581d99b4964eec0203a5b5b1a559e',
    ],
    '0.1.0-dev.20' => [
        'manifest.json' => '5ca4a5f2ef5bf0521a73b4e31ee3a44c82946b1f669496108219d4d46b48a843',
        'lib/runtime.php' => 'fc95fde88d422a70c5eb3caafcfdba5f3089cf05d4d5ec73d98faeea4e6b2c15',
        'lib/store.php' => '84d0b6b3e9719dba8ddd0dc724a1c1e10bf14d3f0897c81edb758f8deaf5ee8c',
        'lib/boundaries.php' => '21034b79e36d208b30ed0ea5aa6892581ae6749c5a9190a3b4a422f4f74b7d0f',
        'lib/bootstrap.php' => '284b0b27eabe2f7d9139bef99743f9213a2a6a57f99c138bfc66ca3cffa5650f',
        'lib/autonomy_policy.php' => 'db83aa3f1bc85c80c12c57294262ee7d4c93dc4bfcbd9eef4de8ced31b6fba18',
        'lib/autonomy_store.php' => '3ba80aede8d294439c56f9ed92c35cdfd7d1fac2684cbd28b0fb8dc8d8987a26',
        'lib/autonomy_host.php' => 'dfa257dbb68f1ade87b7f13750e4fb25dd306c695b3c4ddd070e7ca1a4140a61',
        'lib/offscreen_policy.php' => '23639437e41eafaebd98ec85ce81cf3815ac51365f036c9e33b373fc17504058',
        'lib/offscreen.php' => 'ba1405b262a60f913c2ec4a7349464a6b5400de59802a247844d8a34d2446a41',
        'lib/offscreen_host.php' => '7c036bf86978acc8349742ba4a172d0dcc4a8ef9b8ab8b2b97b321686bc09bdb',
        'migrations/001_initial.sql' => 'b2052570036697147dacb5d041c0a692294af78a83f946e7819d83235e16dc99',
        'migrations/004_restore_state.sql' => 'd6a13622ece3af538996558d892852c5126581d99b4964eec0203a5b5b1a559e',
    ],
    '0.1.0-dev.21' => [
        'manifest.json' => 'cf2b7914e66ae6d864956a5df446aa21a9e87d4beebcd4153b214c4d40736541',
        'lib/runtime.php' => 'fc95fde88d422a70c5eb3caafcfdba5f3089cf05d4d5ec73d98faeea4e6b2c15',
        'lib/store.php' => '84d0b6b3e9719dba8ddd0dc724a1c1e10bf14d3f0897c81edb758f8deaf5ee8c',
        'lib/boundaries.php' => '21034b79e36d208b30ed0ea5aa6892581ae6749c5a9190a3b4a422f4f74b7d0f',
        'lib/bootstrap.php' => '284b0b27eabe2f7d9139bef99743f9213a2a6a57f99c138bfc66ca3cffa5650f',
        'lib/autonomy_policy.php' => 'db83aa3f1bc85c80c12c57294262ee7d4c93dc4bfcbd9eef4de8ced31b6fba18',
        'lib/autonomy_store.php' => '3ba80aede8d294439c56f9ed92c35cdfd7d1fac2684cbd28b0fb8dc8d8987a26',
        'lib/autonomy_host.php' => 'dfa257dbb68f1ade87b7f13750e4fb25dd306c695b3c4ddd070e7ca1a4140a61',
        'lib/offscreen_policy.php' => '23639437e41eafaebd98ec85ce81cf3815ac51365f036c9e33b373fc17504058',
        'lib/offscreen.php' => '74fc0e80681fd105217ec0068ff6a531a66be81992c1e0cdbabf1051e68885ad',
        'lib/offscreen_host.php' => '7c036bf86978acc8349742ba4a172d0dcc4a8ef9b8ab8b2b97b321686bc09bdb',
        'migrations/001_initial.sql' => 'b2052570036697147dacb5d041c0a692294af78a83f946e7819d83235e16dc99',
        'migrations/004_restore_state.sql' => 'd6a13622ece3af538996558d892852c5126581d99b4964eec0203a5b5b1a559e',
    ],
    '0.1.0-dev.22' => [
        'manifest.json' => '5f097681f29917d6e00614fc62cbd71e8e98d414eec240dce5d9348ef2e6bbc5',
        'lib/runtime.php' => 'dd12d1389c0547b71eaf4d4e5be242b8aed2a45763a95626ada83434cf014e12',
        'lib/store.php' => '84d0b6b3e9719dba8ddd0dc724a1c1e10bf14d3f0897c81edb758f8deaf5ee8c',
        'lib/boundaries.php' => '21034b79e36d208b30ed0ea5aa6892581ae6749c5a9190a3b4a422f4f74b7d0f',
        'lib/bootstrap.php' => '284b0b27eabe2f7d9139bef99743f9213a2a6a57f99c138bfc66ca3cffa5650f',
        'lib/autonomy_policy.php' => 'db83aa3f1bc85c80c12c57294262ee7d4c93dc4bfcbd9eef4de8ced31b6fba18',
        'lib/autonomy_store.php' => '0c656dd2db24a13cca49261d0e57d3c0f5da0e3a02a54c2843140d737aad9f6c',
        'lib/autonomy_host.php' => 'dfa257dbb68f1ade87b7f13750e4fb25dd306c695b3c4ddd070e7ca1a4140a61',
        'lib/offscreen_policy.php' => '23639437e41eafaebd98ec85ce81cf3815ac51365f036c9e33b373fc17504058',
        'lib/offscreen.php' => '2361f65178d08649e1056a79fc8abd80fd7983e5eda6e7fb18aa5851bc52b568',
        'lib/offscreen_host.php' => '3b5fbb791b93bf9b719da8bbeeee7313be3699375fd2eb04aa050f8c3242a1f1',
        'migrations/001_initial.sql' => 'b2052570036697147dacb5d041c0a692294af78a83f946e7819d83235e16dc99',
        'migrations/004_restore_state.sql' => 'd6a13622ece3af538996558d892852c5126581d99b4964eec0203a5b5b1a559e',
    ],
    '0.1.0-dev.23' => [
        'manifest.json' => '8c36e6703c3dfe70183034e855e0709f34593b90d8d3819c17adc1b260d012b1',
        'lib/runtime.php' => '804554dcce9b9f3fbcb5ffb57958f36007ac4624f07c33fd813f8e312839146e',
        'lib/store.php' => 'ee86741a203b998d5c05c668e52073402248a42290fd23fe153c912f56cfeb28',
        'lib/boundaries.php' => '21034b79e36d208b30ed0ea5aa6892581ae6749c5a9190a3b4a422f4f74b7d0f',
        'lib/bootstrap.php' => '4cf8e693705ad78a8fdd5420ea682060f8712088b1a3b03a1b84510b4672f151',
        'lib/autonomy_policy.php' => 'db83aa3f1bc85c80c12c57294262ee7d4c93dc4bfcbd9eef4de8ced31b6fba18',
        'lib/autonomy_store.php' => 'c163243334c8a558c800fd946d27564acf2f49d8df51ddf914899d784809a795',
        'lib/autonomy_host.php' => 'dfa257dbb68f1ade87b7f13750e4fb25dd306c695b3c4ddd070e7ca1a4140a61',
        'lib/offscreen_policy.php' => '23639437e41eafaebd98ec85ce81cf3815ac51365f036c9e33b373fc17504058',
        'lib/offscreen.php' => '26f9f9a31844bfb433d7d6bf2c996e8ad6e0c9f6c15c5a054d96cb3630e0f439',
        'lib/offscreen_host.php' => '7ae21d2db282d9de0f1d37ca27a43624343fefd5e371b671a5f543177e1a2155',
        'lib/companion.php' => 'b93244fdf64206fefd21960b17a73844a57c5dbd98ed8e83a3b18ffc048a7597',
        'lib/companion_policy.php' => 'b245767ef4764e82e4fd945f0ab0508993b4095b3bba6012ebeb3a1f09754be5',
        'lib/companion_host.php' => 'b632693d26720fa063b7bbcd771cbce4997039aadff1c6755cb361c0239d1203',
        'migrations/001_initial.sql' => 'b2052570036697147dacb5d041c0a692294af78a83f946e7819d83235e16dc99',
        'migrations/002_autonomy.sql' => '20e0ba2901f9ff352311e00a706c976c793e1e1185c224afcb599f426acb63d3',
        'migrations/003_offscreen.sql' => '245ae41800da5d9d291cf25df9e4766059677710241a086c15ca6999d7500855',
        'migrations/004_restore_state.sql' => 'd6a13622ece3af538996558d892852c5126581d99b4964eec0203a5b5b1a559e',
        'migrations/005_companion.sql' => 'b5b65588aea68eacd97907b1c548ba0c8d2c00b064b8d2c405a5aba5239a9cee',
    ],
    '0.1.0-dev.24' => [
        'manifest.json' => '0c11ab7d5dd79941f5b4228b4d4d3e8d3dbc942c56a83b5911e6593fc3a4bc4e',
        'lib/runtime.php' => 'd247cb0af6b2476045157ada30b1b3ba7d1af30b9f4c4ff555f098c8fa7333e6',
        'lib/store.php' => 'ee86741a203b998d5c05c668e52073402248a42290fd23fe153c912f56cfeb28',
        'lib/boundaries.php' => '21034b79e36d208b30ed0ea5aa6892581ae6749c5a9190a3b4a422f4f74b7d0f',
        'lib/bootstrap.php' => '4cf8e693705ad78a8fdd5420ea682060f8712088b1a3b03a1b84510b4672f151',
        'lib/autonomy_policy.php' => 'db83aa3f1bc85c80c12c57294262ee7d4c93dc4bfcbd9eef4de8ced31b6fba18',
        'lib/autonomy_store.php' => 'c163243334c8a558c800fd946d27564acf2f49d8df51ddf914899d784809a795',
        'lib/autonomy_host.php' => 'dfa257dbb68f1ade87b7f13750e4fb25dd306c695b3c4ddd070e7ca1a4140a61',
        'lib/offscreen_policy.php' => '23639437e41eafaebd98ec85ce81cf3815ac51365f036c9e33b373fc17504058',
        'lib/offscreen.php' => '26f9f9a31844bfb433d7d6bf2c996e8ad6e0c9f6c15c5a054d96cb3630e0f439',
        'lib/offscreen_host.php' => '7ae21d2db282d9de0f1d37ca27a43624343fefd5e371b671a5f543177e1a2155',
        'lib/companion.php' => 'b93244fdf64206fefd21960b17a73844a57c5dbd98ed8e83a3b18ffc048a7597',
        'lib/companion_policy.php' => 'b245767ef4764e82e4fd945f0ab0508993b4095b3bba6012ebeb3a1f09754be5',
        'lib/companion_host.php' => 'b632693d26720fa063b7bbcd771cbce4997039aadff1c6755cb361c0239d1203',
        'migrations/001_initial.sql' => 'b2052570036697147dacb5d041c0a692294af78a83f946e7819d83235e16dc99',
        'migrations/002_autonomy.sql' => '20e0ba2901f9ff352311e00a706c976c793e1e1185c224afcb599f426acb63d3',
        'migrations/003_offscreen.sql' => '245ae41800da5d9d291cf25df9e4766059677710241a086c15ca6999d7500855',
        'migrations/004_restore_state.sql' => 'd6a13622ece3af538996558d892852c5126581d99b4964eec0203a5b5b1a559e',
        'migrations/005_companion.sql' => 'b5b65588aea68eacd97907b1c548ba0c8d2c00b064b8d2c405a5aba5239a9cee',
    ],
    '0.1.0-dev.25' => [
        'manifest.json' => 'f5520fcb3ea21b3d6ae880f28597304dfe75e760aa109e058c7e303b1366e436',
        'lib/runtime.php' => 'd247cb0af6b2476045157ada30b1b3ba7d1af30b9f4c4ff555f098c8fa7333e6',
        'lib/store.php' => 'ee86741a203b998d5c05c668e52073402248a42290fd23fe153c912f56cfeb28',
        'lib/boundaries.php' => '21034b79e36d208b30ed0ea5aa6892581ae6749c5a9190a3b4a422f4f74b7d0f',
        'lib/bootstrap.php' => '4cf8e693705ad78a8fdd5420ea682060f8712088b1a3b03a1b84510b4672f151',
        'lib/autonomy_policy.php' => 'db83aa3f1bc85c80c12c57294262ee7d4c93dc4bfcbd9eef4de8ced31b6fba18',
        'lib/autonomy_store.php' => 'c163243334c8a558c800fd946d27564acf2f49d8df51ddf914899d784809a795',
        'lib/autonomy_host.php' => 'dfa257dbb68f1ade87b7f13750e4fb25dd306c695b3c4ddd070e7ca1a4140a61',
        'lib/offscreen_policy.php' => '23639437e41eafaebd98ec85ce81cf3815ac51365f036c9e33b373fc17504058',
        'lib/offscreen.php' => '26f9f9a31844bfb433d7d6bf2c996e8ad6e0c9f6c15c5a054d96cb3630e0f439',
        'lib/offscreen_host.php' => '7ae21d2db282d9de0f1d37ca27a43624343fefd5e371b671a5f543177e1a2155',
        'lib/companion.php' => 'b93244fdf64206fefd21960b17a73844a57c5dbd98ed8e83a3b18ffc048a7597',
        'lib/companion_policy.php' => 'b245767ef4764e82e4fd945f0ab0508993b4095b3bba6012ebeb3a1f09754be5',
        'lib/companion_host.php' => 'b632693d26720fa063b7bbcd771cbce4997039aadff1c6755cb361c0239d1203',
        'migrations/001_initial.sql' => 'b2052570036697147dacb5d041c0a692294af78a83f946e7819d83235e16dc99',
        'migrations/002_autonomy.sql' => '20e0ba2901f9ff352311e00a706c976c793e1e1185c224afcb599f426acb63d3',
        'migrations/003_offscreen.sql' => '245ae41800da5d9d291cf25df9e4766059677710241a086c15ca6999d7500855',
        'migrations/004_restore_state.sql' => 'd6a13622ece3af538996558d892852c5126581d99b4964eec0203a5b5b1a559e',
        'migrations/005_companion.sql' => 'b5b65588aea68eacd97907b1c548ba0c8d2c00b064b8d2c405a5aba5239a9cee',
    ],
];

function qd_correspondence_source_root(): string
{
    $coreRoot = qd_runtime_core_root();
    $extensionRoot = $coreRoot . DIRECTORY_SEPARATOR . 'ext';
    $configured = $extensionRoot . DIRECTORY_SEPARATOR . 'correspondence';
    if (is_link($extensionRoot) || is_link($configured)) {
        throw new RuntimeException('The trusted Correspondence source root is unavailable.');
    }
    $root = realpath($configured);
    if (!is_string($root) || !is_dir($root)) {
        throw new RuntimeException('The trusted Correspondence source root is unavailable.');
    }
    return $root;
}

function qd_correspondence_source_files(string $root): array
{
    $manifestPath = $root . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($manifestPath) || is_link($manifestPath)) return [];
    $manifestBytes = @file_get_contents($manifestPath);
    if (!is_string($manifestBytes) || strlen($manifestBytes) > 8192) return [];
    $manifest = json_decode($manifestBytes, true, 8);
    if (!is_array($manifest) || ($manifest['name'] ?? null) !== 'correspondence' || !is_string($manifest['version'] ?? null)) return [];
    $expectedHashes = QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION[$manifest['version']] ?? null;
    if (!is_array($expectedHashes)) return [];
    $files = [];
    foreach (array_keys($expectedHashes) as $relative) {
        $files[$relative] = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
    return $files;
}

function qd_correspondence_manifest_ready(string $root): bool
{
    $root = realpath($root);
    if (!is_string($root) || !is_dir($root)) return false;
    $files = qd_correspondence_source_files($root);
    if ($files === []) return false;
    foreach ($files as $relative => $path) {
        if (!is_file($path) || is_link($path)) return false;
        $real = realpath($path);
        if (!is_string($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) return false;
        $files[$relative] = $real;
    }
    $manifestPath = $files['manifest.json'] ?? null;
    if (!is_string($manifestPath)) return false;
    $manifestBytes = @file_get_contents($manifestPath);
    if (!is_string($manifestBytes) || strlen($manifestBytes) > 8192) return false;
    $manifest = json_decode($manifestBytes, true, 8);
    if (!is_array($manifest) || ($manifest['name'] ?? null) !== 'correspondence' || !is_string($manifest['version'] ?? null)) return false;
    $expectedHashes = QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION[$manifest['version']] ?? null;
    if (!is_array($expectedHashes)) return false;
    foreach ($expectedHashes as $relative => $expectedHash) {
        $path = $files[$relative] ?? null;
        if (!is_string($path)) return false;
        $actualHash = @hash_file('sha256', $path);
        if (!is_string($actualHash) || !hash_equals($expectedHash, strtolower($actualHash))) return false;
    }
    return true;
}

function qd_correspondence_current_source_identity(string $root): ?array
{
    $root = realpath($root);
    if (!is_string($root) || !is_dir($root) || !qd_correspondence_manifest_ready($root)) return null;
    $manifestBytes = @file_get_contents($root . DIRECTORY_SEPARATOR . 'manifest.json');
    if (!is_string($manifestBytes) || strlen($manifestBytes) > 8192) return null;
    $manifest = json_decode($manifestBytes, true, 8);
    $version = is_array($manifest) ? ($manifest['version'] ?? null) : null;
    $expectedManifestHash = is_string($version)
        ? (QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION[$version]['manifest.json'] ?? null)
        : null;
    if (!is_string($expectedManifestHash) || !hash_equals($expectedManifestHash, hash('sha256', $manifestBytes))) return null;
    return ['root' => $root, 'version' => $version, 'manifestHash' => hash('sha256', $manifestBytes)];
}

function qd_correspondence_loaded_source_identity(?array $identity = null): ?array
{
    static $loadedIdentity = null;
    if ($identity !== null) {
        if ($loadedIdentity !== null && $loadedIdentity !== $identity) throw new QDServerFault('unavailable');
        $loadedIdentity = $identity;
    }
    return $loadedIdentity;
}

function qd_correspondence_loaded_source_version(): string
{
    try {
        qd_correspondence_load_sources();
        $identity = qd_correspondence_loaded_source_identity();
        if (!is_array($identity) || !is_string($identity['version'] ?? null)) throw new QDServerFault('unavailable');
        return $identity['version'];
    } catch (QDServerFault $error) {
        throw $error;
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
}

function qd_correspondence_version_uses_restore_fence(string $version): bool
{
    return in_array($version, ['0.1.0-dev.18', '0.1.0-dev.20', '0.1.0-dev.21', '0.1.0-dev.22', '0.1.0-dev.23', '0.1.0-dev.24', '0.1.0-dev.25'], true);
}

function qd_correspondence_uses_restore_fence(): bool
{
    return qd_correspondence_version_uses_restore_fence(qd_correspondence_loaded_source_version());
}

function qd_correspondence_version_supports_signed_unknown(string $version): bool
{
    return in_array($version, ['0.1.0-dev.17', '0.1.0-dev.18', '0.1.0-dev.20', '0.1.0-dev.21', '0.1.0-dev.22', '0.1.0-dev.23', '0.1.0-dev.24', '0.1.0-dev.25'], true);
}

function qd_correspondence_version_supports_operator_close(string $version): bool
{
    return in_array($version, ['0.1.0-dev.20', '0.1.0-dev.21', '0.1.0-dev.22', '0.1.0-dev.23', '0.1.0-dev.24', '0.1.0-dev.25'], true);
}

function qd_correspondence_version_supports_companion_scheduler(string $version): bool
{
    return in_array($version, ['0.1.0-dev.23', '0.1.0-dev.24', '0.1.0-dev.25'], true);
}

function qd_correspondence_runtime_ready(): bool
{
    try {
        if (PHP_VERSION_ID < 80100) return false;
        $identity = qd_correspondence_current_source_identity(qd_correspondence_source_root());
        if (!is_array($identity)) return false;
        $loadedIdentity = qd_correspondence_loaded_source_identity();
        return $loadedIdentity === null || $loadedIdentity === $identity;
    } catch (Throwable) {
        return false;
    }
}

function qd_correspondence_load_sources(): string
{
    try {
        $root = qd_correspondence_source_root();
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
    $identity = qd_correspondence_current_source_identity($root);
    if (!is_array($identity)) throw new QDServerFault('unavailable');
    $loadedIdentity = qd_correspondence_loaded_source_identity();
    if ($loadedIdentity !== null) {
        if ($identity !== $loadedIdentity) throw new QDServerFault('unavailable');
        return $root;
    }
    $version = $identity['version'];
    if (qd_correspondence_version_uses_restore_fence($version)) {
        require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php';
    } else {
        require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime.php';
        require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'store.php';
        require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'boundaries.php';
    }
    foreach ([
        'CorrespondenceService' => 'lib/runtime.php',
        'CorrespondencePgStore' => 'lib/store.php',
    ] as $class => $relative) {
        if (!class_exists($class, false)) throw new QDServerFault('unavailable');
        $source = realpath((new ReflectionClass($class))->getFileName() ?: '');
        if ($source !== realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
            throw new QDServerFault('unavailable');
        }
    }
    foreach ([
        'correspondenceBody' => 'lib/runtime.php',
        'correspondenceAttachments' => 'lib/runtime.php',
        'correspondencePrepareCoreNote' => 'lib/boundaries.php',
    ] as $function => $relative) {
        if (!function_exists($function)) throw new QDServerFault('unavailable');
        $source = realpath((new ReflectionFunction($function))->getFileName() ?: '');
        if ($source !== realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
            throw new QDServerFault('unavailable');
        }
    }
    if (qd_correspondence_version_uses_restore_fence($version)) {
        foreach ([
            'correspondenceEngineRoot' => 'lib/bootstrap.php',
            'correspondenceNativeConnection' => 'lib/bootstrap.php',
            'correspondenceBeginRequestIngress' => 'lib/bootstrap.php',
            'correspondenceCaptureRequestIngress' => 'lib/bootstrap.php',
        ] as $function => $relative) {
            if (!function_exists($function)) throw new QDServerFault('unavailable');
            $source = realpath((new ReflectionFunction($function))->getFileName() ?: '');
            if ($source !== realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
                throw new QDServerFault('unavailable');
            }
        }
    }
    if (qd_correspondence_current_source_identity($root) !== $identity) throw new QDServerFault('unavailable');
    qd_correspondence_loaded_source_identity($identity);
    return $root;
}

function qd_correspondence_runtime_operations(): array
{
    return [
        'correspondence.catalog.read' => static fn(stdClass $payload, array $context): array => qd_correspondence_dispatch('catalog', $payload, $context),
        'correspondence.mailbox.read' => static fn(stdClass $payload, array $context): array => qd_correspondence_dispatch('mailbox', $payload, $context),
        'correspondence.detail.read' => static fn(stdClass $payload, array $context): array => qd_correspondence_dispatch('detail', $payload, $context),
        'correspondence.compose' => static fn(stdClass $payload, array $context): array => qd_correspondence_dispatch('compose', $payload, $context),
        'correspondence.reconcile' => static fn(stdClass $payload, array $context): array => qd_correspondence_dispatch('reconcile', $payload, $context),
    ];
}

function qd_correspondence_dispatch(string $operation, stdClass $payload, array $context): array
{
    $handlers = [
        'catalog' => 'qd_correspondence_catalog_read',
        'mailbox' => 'qd_correspondence_mailbox_read',
        'detail' => 'qd_correspondence_detail_read',
        'compose' => 'qd_correspondence_compose',
        'reconcile' => 'qd_correspondence_reconcile',
    ];
    $handler = $handlers[$operation] ?? null;
    if (!is_string($handler)) throw new LogicException('Unknown correspondence operation.');
    if (!qd_correspondence_uses_restore_fence()) return $handler($payload, $context);

    $requestKeys = ['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED', 'CORRESPONDENCE_REQUEST_RESTORE_TOKEN', 'CORRESPONDENCE_REQUEST_NATIVE_TS'];
    $saved = qd_runtime_correspondence_save_globals($requestKeys);
    try {
        if (!empty($GLOBALS['CORRESPONDENCE_RESTORE_INIT_UNSAFE']) || !empty($GLOBALS['CORRESPONDENCE_RESTORE_INIT_ACTIVE'])) {
            throw new QDServerFault('stale_context');
        }
        $captured = array_key_exists('CORRESPONDENCE_REQUEST_RESTORE_CAPTURED', $GLOBALS)
            ? $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED']
            : false;
        if ($captured !== true && $captured !== false) throw new QDServerFault('unavailable');
        if ($captured) {
            $token = $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'] ?? null;
            $nativeTs = $GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS'] ?? null;
            if (!is_string($token) || $token === '' || ($nativeTs !== null && !qd_correspondence_valid_native_timestamp($nativeTs))) {
                throw new QDServerFault('stale_context');
            }
        } else {
            qd_correspondence_dev18_begin_fresh_ingress();
        }
        $ownerRoot = qd_correspondence_source_root();
        return qd_runtime_with_correspondence_lease($ownerRoot, static function (string $generation, mixed $lease, string $coreRoot, string $ownerRoot) use ($handler, $payload, $context): array {
            $context['__qdCorrespondenceDev18'] = [
                'coreRoot' => $coreRoot,
                'generation' => $generation,
                'lease' => $lease,
                'ownerRoot' => $ownerRoot,
            ];
            return $handler($payload, $context);
        });
    } finally {
        qd_runtime_correspondence_restore_globals($saved);
    }
}

function qd_correspondence_dev18_begin_fresh_ingress(): void
{
    if (($GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'] ?? null) !== null
        || ($GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS'] ?? null) !== null) {
        throw new QDServerFault('stale_context');
    }
    correspondenceBeginRequestIngress([]);
}

function qd_correspondence_catalog_read(stdClass $payload, array $context): array
{
    qd_correspondence_require_fields($payload, [], ['limit', 'cursor']);
    qd_correspondence_require_access($context, ['inspector', 'compose']);
    $limit = property_exists($payload, 'limit') ? $payload->limit : 50;
    $cursor = property_exists($payload, 'cursor') ? $payload->cursor : null;
    if (!is_int($limit) || $limit < 1 || $limit > QD_CORRESPONDENCE_PAGE_MAX
        || ($cursor !== null && (!is_string($cursor) || strlen($cursor) > 512))) {
        throw new QDServerFault('invalid_request');
    }
    $assertCurrent = qd_correspondence_assertion($context);
    $assertCurrent();
    qd_correspondence_load_sources();
    try {
        $scopeKey = qd_correspondence_scope_key($context);
        $result = qd_correspondence_read_snapshot($scopeKey, static function ($connection, array $active) use ($scopeKey, $limit, $cursor): array {
            $store = qd_correspondence_read_store($connection, $active);
            $service = new CorrespondenceService($store);
            $actors = $service->actors();
            $limited = $store->actorCatalogLimited();
            $counts = [];
            foreach ($actors as $actor) {
                $name = $actor['name'] ?? null;
                if (is_string($name)) $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
            $rows = [];
            $revisionActors = [];
            foreach ($actors as $actor) {
                if (!is_array($actor)) {
                    $limited = true;
                    $revisionActors[] = ['invalid' => true];
                    continue;
                }
                $key = $actor['key'] ?? null;
                $name = $actor['name'] ?? null;
                $revisionActors[] = [
                    'key' => is_string($key) ? $key : null,
                    'kind' => is_string($actor['kind'] ?? null) ? $actor['kind'] : null,
                    'id' => is_int($actor['id'] ?? null) ? $actor['id'] : null,
                    'name' => is_string($name) ? $name : null,
                ];
                if (!is_string($key) || preg_match('/\A(?:player|npc:[1-9][0-9]{0,18})\z/D', $key) !== 1 || !is_string($name)) {
                    $limited = true;
                    continue;
                }
                $label = $name;
                if (($counts[$label] ?? 0) > 1 && str_starts_with($actor['key'], 'npc:')) {
                    $label .= ' (#' . substr($actor['key'], 4) . ')';
                }
                $rows[] = [
                    'actorKey' => $actor['key'],
                    'label' => qd_correspondence_clip_utf8($label, 256, $limited),
                    'canWrite' => true,
                    'canReceive' => true,
                    'canSignAs' => true,
                ];
            }
            $revision = qd_correspondence_catalog_revision($scopeKey, $revisionActors, $store->actorCatalogLimited());
            $offset = qd_correspondence_catalog_cursor_offset($cursor, $scopeKey, $revision, count($rows));
            $page = [];
            $index = $offset;
            while ($index < count($rows) && count($page) < $limit) {
                $candidate = $page;
                $candidate[] = $rows[$index];
                $candidateOffset = $index + 1;
                $candidateCursor = $candidateOffset < count($rows)
                    ? qd_correspondence_make_catalog_cursor($scopeKey, $revision, $candidateOffset)
                    : null;
                $candidateResult = [
                    'actors' => $candidate,
                    'limited' => $limited,
                    'revision' => $revision,
                    'nextCursor' => $candidateCursor,
                ];
                if (strlen(json_encode($candidateResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > QD_CORRESPONDENCE_PAGE_BYTES) {
                    if ($page === []) throw new RuntimeException('A single catalog row exceeds the response bound.');
                    break;
                }
                $page[] = $rows[$index];
                $index = $candidateOffset;
            }
            $nextCursor = $index < count($rows)
                ? qd_correspondence_make_catalog_cursor($scopeKey, $revision, $index)
                : null;
            return ['actors' => $page, 'limited' => $limited, 'revision' => $revision, 'nextCursor' => $nextCursor];
        }, $context);
        $assertCurrent();
        return $result;
    } catch (QDServerFault $error) {
        throw $error;
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
}

function qd_correspondence_mailbox_read(stdClass $payload, array $context): array
{
    qd_correspondence_require_fields($payload, ['recipientActorKey'], ['limit', 'cursor']);
    qd_correspondence_require_access($context, ['inspector']);
    $recipientKey = qd_correspondence_actor_key($payload->recipientActorKey ?? null);
    $limit = $payload->limit ?? 50;
    $cursor = $payload->cursor ?? null;
    if (!is_int($limit) || $limit < 1 || $limit > QD_CORRESPONDENCE_PAGE_MAX
        || ($cursor !== null && (!is_string($cursor) || strlen($cursor) > 512))) {
        throw new QDServerFault('invalid_request');
    }
    $assertCurrent = qd_correspondence_assertion($context);
    $assertCurrent();
    qd_correspondence_load_sources();
    try {
        $scopeKey = qd_correspondence_scope_key($context);
        $result = qd_correspondence_read_snapshot($scopeKey, static function ($connection, array $active) use ($recipientKey, $limit, $cursor, $scopeKey): array {
            $actorStore = qd_correspondence_read_store($connection, $active);
            $service = new CorrespondenceService($actorStore);
            $actor = qd_correspondence_find_actor($service->actors(), $recipientKey);
            if ($actor === null) throw new QDServerFault('not_found');

            $scan = qd_correspondence_mailbox_source_rows($connection, (string)$active['profileId'], $actor);
            $docs = $scan['documents'];
            $ops = $scan['operations'];
            $eventRows = qd_correspondence_prefetch_events($connection, $docs, $ops);
            $companionDecisions = qd_correspondence_prefetch_companion_decisions($connection, $docs);
            $revision = qd_correspondence_source_revision($recipientKey, $scopeKey, $scan['rawRows'], $eventRows, $companionDecisions);
            $offset = qd_correspondence_cursor_offset($cursor, $recipientKey, $scopeKey, $revision);
            $store = qd_correspondence_read_store($connection, $active, $docs, $ops, $eventRows, $companionDecisions);
            $service = new CorrespondenceService($store);
            $letters = [];
            $limited = $scan['limited'];
            $index = $offset;
            $count = count($scan['rawRows']);
            while ($index < $count && count($letters) < $limit) {
                $candidate = $scan['rawRows'][$index];
                $index++;
                $docId = $candidate['document_id'] ?? null;
                $doc = is_string($candidate['document_payload'] ?? null)
                    ? json_decode($candidate['document_payload'], true, 512, JSON_THROW_ON_ERROR) : null;
                $op = is_string($candidate['operation_payload'] ?? null) && $candidate['operation_payload'] !== ''
                    ? json_decode($candidate['operation_payload'], true, 512, JSON_THROW_ON_ERROR) : null;
                if (!is_string($docId) || !is_array($doc) || ($op !== null && !is_array($op))) {
                    $limited = true;
                    continue;
                }
                try {
                    $status = $service->get($docId);
                } catch (Throwable) {
                    $limited = true;
                    continue;
                }
                if (!in_array($status['state'] ?? null, ['delivered', 'exposed'], true)) continue;
                $receipt = qd_correspondence_validate_receipt($store, $connection, $doc, $op);
                if (($receipt['confirmed'] ?? false) !== true) {
                    $limited = true;
                    continue;
                }
                $letter = qd_correspondence_mailbox_projection($doc, $receipt, $limited);
                $candidatePage = $letters;
                $candidatePage[] = $letter;
                if (strlen(json_encode(['letters' => $candidatePage, 'limited' => $limited, 'nextCursor' => null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > QD_CORRESPONDENCE_PAGE_BYTES) {
                    $index--;
                    $limited = true;
                    break;
                }
                $letters[] = $letter;
            }
            $next = $index < $count
                ? qd_correspondence_make_cursor($recipientKey, $scopeKey, $revision, $index)
                : null;
            return ['recipientActorKey' => $recipientKey, 'letters' => $letters, 'limited' => $limited, 'nextCursor' => $next];
        }, $context);
        $assertCurrent();
        return $result;
    } catch (QDServerFault $error) {
        throw $error;
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
}

function qd_correspondence_detail_read(stdClass $payload, array $context): array
{
    qd_correspondence_require_fields($payload, ['recipientActorKey', 'letterId']);
    qd_correspondence_require_access($context, ['inspector']);
    $recipientKey = qd_correspondence_actor_key($payload->recipientActorKey ?? null);
    $letterId = $payload->letterId ?? null;
    if (!is_string($letterId) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $letterId) !== 1) {
        throw new QDServerFault('invalid_request');
    }
    $assertCurrent = qd_correspondence_assertion($context);
    $assertCurrent();
    qd_correspondence_load_sources();
    try {
        $scopeKey = qd_correspondence_scope_key($context);
        $result = qd_correspondence_read_snapshot($scopeKey, static function ($connection, array $active) use ($recipientKey, $letterId, $context): array {
            $baseStore = qd_correspondence_read_store($connection, $active);
            $service = new CorrespondenceService($baseStore);
            $actor = qd_correspondence_find_actor($service->actors(), $recipientKey);
            if ($actor === null) throw new QDServerFault('not_found');
            $rows = qd_correspondence_detail_source_rows($connection, (string)$active['profileId'], $actor, $letterId);
            if (count($rows) !== 1) throw new QDServerFault('not_found');
            $doc = json_decode($rows[0]['document_payload'], true, 512, JSON_THROW_ON_ERROR);
            $op = is_string($rows[0]['operation_payload'] ?? null) && $rows[0]['operation_payload'] !== ''
                ? json_decode($rows[0]['operation_payload'], true, 512, JSON_THROW_ON_ERROR) : null;
            if (!is_array($doc) || ($op !== null && !is_array($op))) throw new QDServerFault('unavailable');
            $docs = [$letterId => $doc];
            $ops = is_array($op) && is_string($doc['operation_id'] ?? null) ? [$doc['operation_id'] => $op] : [];
            $events = qd_correspondence_prefetch_events($connection, $docs, $ops);
            $companionDecisions = qd_correspondence_prefetch_companion_decisions($connection, $docs);
            $store = qd_correspondence_read_store($connection, $active, $docs, $ops, $events, $companionDecisions);
            $service = new CorrespondenceService($store);
            try {
                $status = $service->get($letterId);
            } catch (Throwable) {
                throw new QDServerFault('not_found');
            }
            if (!in_array($status['state'] ?? null, ['delivered', 'exposed'], true)) throw new QDServerFault('not_found');
            $receipt = qd_correspondence_validate_receipt($store, $connection, $doc, $op);
            if (($receipt['confirmed'] ?? false) !== true) throw new QDServerFault('unavailable');
            if (($status['state'] ?? null) === 'exposed' && $actor['kind'] === 'npc') {
                $sourceId = (int)($op['source']['rowid'] ?? 0);
                if ($sourceId < 1) throw new QDServerFault('unavailable');
                $service->readExposedSource($sourceId, (int)$actor['id']);
            }
            $limited = false;
            $ordinary = [
                'letterId' => $letterId,
                'claimedSignature' => qd_correspondence_clip_utf8((string)$doc['claimed_sender']['name'], 256, $limited),
                'body' => $doc['body'],
                'receivedAt' => $receipt['receivedAt'],
                'attachments' => $receipt['attachments'],
                'deliveryConfirmed' => true,
            ];
            $authorDetail = in_array('inspector', $context['scopes'] ?? [], true)
                && in_array('author_detail', $context['scopes'] ?? [], true);
            if ($authorDetail) {
                $ordinary['recordedAuthor'] = qd_correspondence_clip_utf8((string)$doc['author']['name'], 256, $limited);
            }
            return $ordinary;
        }, $context);
        $assertCurrent();
        return $result;
    } catch (QDServerFault $error) {
        throw $error;
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
}

function qd_correspondence_commit_boundary_store(object $baseStore, callable $beginCommit): object
{
    return new class($baseStore, $beginCommit) implements CorrespondenceStore {
        private object $baseStore;
        private Closure $beginCommit;
        private bool $entered = false;

        public function __construct(object $baseStore, callable $beginCommit)
        {
            $this->baseStore = $baseStore;
            $this->beginCommit = Closure::fromCallable($beginCommit);
        }

        private function enterCommit(): void
        {
            if (!$this->entered) {
                ($this->beginCommit)();
                $this->entered = true;
            }
        }

        public function transaction(callable $work): mixed { return $this->baseStore->transaction($work); }
        public function readTransaction(callable $work): mixed { return $this->baseStore->readTransaction($work); }
        public function scope(): array { return $this->baseStore->scope(); }
        public function actors(): array { return $this->baseStore->actors(); }
        public function player(): string { return $this->baseStore->player(); }
        public function lockActorRows(array $npcIds, bool $includePlayer = false): array { return $this->baseStore->lockActorRows($npcIds, $includePlayer); }
        public function findRequest(string $scope, string $request): ?array { return $this->baseStore->findRequest($scope, $request); }
        public function loadDocument(string $id): ?array { return $this->baseStore->loadDocument($id); }
        public function saveDocument(array $document): void { $this->enterCommit(); $this->baseStore->saveDocument($document); }
        public function documents(string $scope, ?int $recipientId = null): array { return $this->baseStore->documents($scope, $recipientId); }
        public function loadOperation(string $id): ?array { return $this->baseStore->loadOperation($id); }
        public function saveOperation(array $operation): void { $this->enterCommit(); $this->baseStore->saveOperation($operation); }
        public function appendEvent(string $type, string $data, string $people, string $sess): array { $this->enterCommit(); return $this->baseStore->appendEvent($type, $data, $people, $sess); }
        public function eventMatches(array $event): bool { return $this->baseStore->eventMatches($event); }
        public function managedExecutor(): ?string { return $this->baseStore->managedExecutor(); }
        public function prepareNote(string $title, string $body): void { $this->enterCommit(); $this->baseStore->prepareNote($title, $body); }
        public function queueCommand(string $actor, string $action): void { $this->enterCommit(); $this->baseStore->queueCommand($actor, $action); }
        public function physicalActor(array $actor): array { return $this->baseStore->physicalActor($actor); }
        public function physicalItem(array $item): array { return $this->baseStore->physicalItem($item); }
    };
}

function qd_correspondence_compose(stdClass $payload, array $context): array
{
    qd_correspondence_require_fields($payload, ['operationId', 'writerActorKey', 'recipientActorKey', 'body', 'inventoryRevision', 'attachments', 'goldAmount'], ['signatureActorKey']);
    qd_correspondence_require_access($context, ['compose']);
    $assertCurrent = qd_correspondence_assertion($context);
    $withCurrentContext = $context['withCurrentContext'] ?? null;
    if (!is_callable($withCurrentContext)) throw new QDServerFault('unavailable');
    $assertCurrent();
    qd_correspondence_load_sources();
    $mapped = qd_correspondence_prepare_compose($payload);
    $requestId = $mapped['requestId'];
    $writerKey = $mapped['writerKey'];
    $recipientKey = $mapped['recipientKey'];
    $signatureKey = $mapped['signatureKey'];
    $input = $mapped['input'];
    try {
        if (qd_correspondence_uses_restore_fence()) {
            return qd_correspondence_compose_dev18($context, $withCurrentContext, $requestId, $writerKey, $recipientKey, $signatureKey, $input);
        }
        $result = $withCurrentContext(static function () use ($context, $requestId, $writerKey, $recipientKey, $signatureKey, $input): array {
            return qd_runtime_with_core_db(static function ($connection) use ($context, $requestId, $writerKey, $recipientKey, $signatureKey, $input): array {
                $active = qd_runtime_active_playthrough($connection);
                qd_correspondence_assert_active_scope($active, qd_correspondence_scope_key($context));
                $clock = qd_correspondence_pg_rows($connection, 'SELECT ts::text AS ts,gamets::text AS gamets FROM public.eventlog ORDER BY rowid DESC LIMIT 1');
                if (count($clock) !== 1 || !is_string($clock[0]['ts'] ?? null) || !is_string($clock[0]['gamets'] ?? null)) {
                    throw new QDServerFault('unavailable');
                }
                $coreRoot = qd_runtime_core_root();
                $store = new CorrespondencePgStore(
                    $connection,
                    static fn($candidate): ?string => $candidate->observedExecutor(),
                    static fn(string $title, string $noteBody): bool => correspondencePrepareCoreNote($coreRoot, $title, $noteBody),
                );
                $store->bindRequestClock([1 => $clock[0]['ts'], 2 => $clock[0]['gamets']]);
                $service = new CorrespondenceService($store);
                $actorKeys = [];
                foreach ($service->actors() as $actor) $actorKeys[$actor['key']] = $actor;
                if (!isset($actorKeys[$writerKey]) || !isset($actorKeys[$recipientKey]) || !isset($actorKeys[$signatureKey])) {
                    throw new QDServerFault('not_found');
                }
                $beginCommit = $context['beginCommit'] ?? null;
                if (!is_callable($beginCommit)) throw new QDServerFault('unavailable');
                $service = new CorrespondenceService(qd_correspondence_commit_boundary_store($store, $beginCommit));
                try {
                    $composed = $service->compose($input);
                } catch (QDServerFault $error) {
                    throw $error;
                } catch (DomainException $error) {
                    throw qd_correspondence_domain_fault($error);
                } catch (InvalidArgumentException) {
                    throw new QDServerFault('invalid_request');
                }
                $documentId = $composed['document_id'] ?? null;
                if (!is_string($documentId)) throw new QDServerFault('unavailable');
                try {
                    $service->dispatch($documentId);
                } catch (Throwable) {
                    // Preserve the source's queued document if dispatch cannot complete.
                }
                qd_correspondence_assert_active_scope(qd_runtime_active_playthrough($connection), qd_correspondence_scope_key($context));
                return qd_correspondence_write_outcome($service, $store, $connection, $documentId, $requestId);
            });
        });
        $assertCurrent();
        return $result;
    } catch (QDServerFault $error) {
        throw $error;
    } catch (DomainException $error) {
        throw qd_correspondence_domain_fault($error);
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
}

function qd_correspondence_compose_dev18(array $context, callable $withCurrentContext, string $requestId, string $writerKey, string $recipientKey, string $signatureKey, array $input): array
{
    $assertCurrent = qd_correspondence_assertion($context);
    $scopeKey = qd_correspondence_scope_key($context);
    $authority = qd_correspondence_dev18_authority($context);
    $assertCurrent();
    qd_correspondence_dev18_fenced_snapshot($scopeKey, $authority, static function ($connection, array $active) use ($writerKey, $recipientKey, $signatureKey): bool {
        $service = new CorrespondenceService(qd_correspondence_read_store($connection, $active));
        $actorKeys = [];
        foreach ($service->actors() as $actor) $actorKeys[$actor['key']] = $actor;
        if (!isset($actorKeys[$writerKey]) || !isset($actorKeys[$recipientKey]) || !isset($actorKeys[$signatureKey])) {
            throw new QDServerFault('not_found');
        }
        return true;
    });

    $result = $withCurrentContext(static function () use ($context, $scopeKey, $authority, $requestId, $input): array {
        $beginCommit = $context['beginCommit'] ?? null;
        if (!is_callable($beginCommit)) throw new QDServerFault('unavailable');
        $beginCommit();

        return qd_runtime_with_correspondence_owner_lease($authority['ownerRoot'], $authority['generation'], $authority['lease'], static function () use ($authority, $scopeKey, $requestId, $input): array {
            return qd_runtime_with_owned_connection(
                static fn() => correspondenceNativeConnection(),
                static function ($connection): void { qd_correspondence_close_owned_pg_connection($connection); },
                static function ($connection) use ($authority, $scopeKey, $requestId, $input): array {
                    $store = new CorrespondencePgStore(
                        $connection,
                        static fn($candidate): ?string => $candidate->observedExecutor(),
                        static fn(string $title, string $body): bool => correspondencePrepareCoreNote($authority['coreRoot'], $title, $body),
                        $authority['coreRoot'] . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'playthrough_runtime' . DIRECTORY_SEPARATOR . 'correspondence.restore.json',
                    );
                    $store->ensureSchema();
                    correspondenceCaptureRequestIngress($store);
                    $originalToken = $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'] ?? null;
                    if (!is_string($originalToken) || $originalToken === ''
                        || !is_string($store->requestRestoreToken())
                        || !hash_equals($originalToken, (string)$store->requestRestoreToken())) {
                        throw new QDServerFault('stale_context');
                    }
                    try {
                        $store->assertRestoreToken($originalToken);
                    } catch (Throwable) {
                        throw new QDServerFault('stale_context');
                    }

                    $service = new CorrespondenceService($store);
                    $actorKeys = [];
                    foreach ($service->actors() as $actor) $actorKeys[$actor['key']] = $actor;
                    foreach ([$input['author'], $input['recipient'], $input['claimed_sender']] as $actorKey) {
                        if (!isset($actorKeys[$actorKey])) throw new QDServerFault('not_found');
                    }
                    try {
                        $composed = $service->compose($input);
                    } catch (QDServerFault $error) {
                        throw $error;
                    } catch (DomainException $error) {
                        throw qd_correspondence_domain_fault($error);
                    } catch (InvalidArgumentException) {
                        throw new QDServerFault('invalid_request');
                    }
                    $documentId = $composed['document_id'] ?? null;
                    if (!is_string($documentId)) throw new QDServerFault('unavailable');
                    try {
                        $service->dispatch($documentId);
                    } catch (Throwable) {
                        // Keep the queued owner document when dispatch cannot complete.
                    }
                    try {
                        $store->assertRestoreToken($originalToken);
                    } catch (Throwable) {
                        throw new QDServerFault('stale_context');
                    }
                    qd_correspondence_assert_active_scope(qd_runtime_active_playthrough($connection), $scopeKey);
                    return qd_correspondence_write_outcome($service, $store, $connection, $documentId, $requestId);
                },
            );
        });
    });
    $assertCurrent();
    return $result;
}

function qd_correspondence_prepare_compose(stdClass $payload): array
{
    $requestId = $payload->operationId ?? null;
    $writerKey = qd_correspondence_actor_key($payload->writerActorKey ?? null);
    $recipientKey = qd_correspondence_actor_key($payload->recipientActorKey ?? null);
    $signatureKey = property_exists($payload, 'signatureActorKey') ? qd_correspondence_actor_key($payload->signatureActorKey) : $writerKey;
    $body = $payload->body ?? null;
    $inventoryRevision = $payload->inventoryRevision ?? null;
    $goldAmount = $payload->goldAmount ?? null;
    if (!is_string($requestId) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $requestId) !== 1
        || !is_string($body) || !is_string($inventoryRevision) || $inventoryRevision === '' || strlen($inventoryRevision) > 128
        || !is_int($goldAmount) || $goldAmount < 0 || $goldAmount > 2147483647
        || !is_array($payload->attachments ?? null) || !array_is_list($payload->attachments)) {
        throw new QDServerFault('invalid_request');
    }
    $attachments = [];
    foreach ($payload->attachments as $item) {
        if (!is_object($item) || array_diff(array_keys(get_object_vars($item)), ['form_id', 'quantity']) !== []
            || count(get_object_vars($item)) !== 2 || !is_string($item->form_id ?? null)
            || !is_int($item->quantity ?? null) || $item->quantity < 1 || $item->quantity > 2147483647
            || preg_match('#\A([^|/\\\\\r\n]{1,64}\.(?:esm|esp|esl))\|([0-9a-fA-F]{8})\z#iD', $item->form_id, $matches) !== 1
            || strtolower($item->form_id) === 'skyrim.esm|0000000f') {
            throw new QDServerFault('invalid_request');
        }
        $attachments[] = ['kind' => 'item', 'form_id' => $matches[1] . '|' . strtoupper($matches[2]), 'quantity' => $item->quantity];
    }
    if (count($attachments) + ($goldAmount > 0 ? 1 : 0) > 8) throw new QDServerFault('invalid_request');
    if ($goldAmount > 0) $attachments[] = ['kind' => 'gold', 'form_id' => 'Skyrim.esm|0000000F', 'quantity' => $goldAmount];
    try {
        correspondenceBody($body);
        $attachments = correspondenceAttachments($attachments);
    } catch (Throwable) {
        throw new QDServerFault('invalid_request');
    }
    return [
        'requestId' => $requestId,
        'writerKey' => $writerKey,
        'recipientKey' => $recipientKey,
        'signatureKey' => $signatureKey,
        'input' => [
            'request_id' => $requestId,
            'author' => $writerKey,
            'payer' => 'player',
            'recipient' => $recipientKey,
            'claimed_sender' => $signatureKey,
            'body' => $body,
            'attachments' => $attachments,
        ],
    ];
}

function qd_correspondence_reconcile(stdClass $payload, array $context): array
{
    qd_correspondence_require_fields($payload, ['operationId']);
    qd_correspondence_require_access($context, ['compose']);
    $requestId = $payload->operationId ?? null;
    if (!is_string($requestId) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $requestId) !== 1) {
        throw new QDServerFault('invalid_request');
    }
    $assertCurrent = qd_correspondence_assertion($context);
    $assertCurrent();
    qd_correspondence_load_sources();
    try {
        $scopeKey = qd_correspondence_scope_key($context);
        $result = qd_correspondence_read_snapshot($scopeKey, static function ($connection, array $active) use ($requestId): array {
            $scope = (string)$active['profileId'];
            $store = qd_correspondence_read_store($connection, $active);
            $doc = $store->findRequest($scope, $requestId);
            if (!is_array($doc) || !is_string($doc['document_id'] ?? null)) throw new QDServerFault('not_found');
            $docId = $doc['document_id'];
            $operation = is_string($doc['operation_id'] ?? null) ? $store->loadOperation($doc['operation_id']) : null;
            $docs = [$docId => $doc];
            $ops = is_array($operation) ? [$operation['operation_id'] => $operation] : [];
            $events = qd_correspondence_prefetch_events($connection, $docs, $ops);
            $store = qd_correspondence_read_store($connection, $active, $docs, $ops, $events);
            $service = new CorrespondenceService($store);
            try {
                return qd_correspondence_project_result($service, $store, $connection, $docId, $requestId);
            } catch (Throwable) {
                throw new QDServerFault('unavailable');
            }
        }, $context);
        $assertCurrent();
        return $result;
    } catch (QDServerFault $error) {
        throw $error;
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
}

function qd_correspondence_write_outcome(CorrespondenceService $service, CorrespondencePgStore $store, mixed $connection, string $documentId, string $requestId): array
{
    return $store->transaction(static fn(): array => qd_correspondence_project_result($service, $store, $connection, $documentId, $requestId));
}

function qd_correspondence_companion_decision_rows(object $store, array $doc): array
{
    if (!qd_correspondence_version_supports_companion_scheduler(qd_correspondence_loaded_source_version())) return [];
    $documentId = $doc['document_id'] ?? null;
    if (!is_string($documentId) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $documentId) !== 1) return [];
    if (method_exists($store, 'companionDecisionRows')) {
        $cached = $store->companionDecisionRows($documentId);
        if (is_array($cached)) return $cached;
    }
    $table = $store->query("SELECT to_regclass('plugins.correspondence_companion_decision') AS decision_table");
    if (count($table) !== 1) throw new RuntimeException('The bounded companion ownership read failed.');
    if (!is_string($table[0]['decision_table'] ?? null) || $table[0]['decision_table'] === '') return [];
    return $store->query(
        'SELECT document_id::text AS document_id,playthrough_id,request_id::text AS request_id,actor_id::text AS actor_id FROM plugins.correspondence_companion_decision WHERE document_id=$1::uuid LIMIT 2',
        [$documentId],
    );
}

function qd_correspondence_prefetch_companion_decisions(mixed $connection, array $documents): ?array
{
    if (!qd_correspondence_version_supports_companion_scheduler(qd_correspondence_loaded_source_version())) return null;
    $documentIds = [];
    foreach ($documents as $document) {
        $id = $document['document_id'] ?? null;
        if (is_string($id) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $id) === 1) $documentIds[$id] = true;
    }
    if ($documentIds === []) return [];
    $table = qd_correspondence_pg_rows($connection, "SELECT to_regclass('plugins.correspondence_companion_decision') AS decision_table");
    if (count($table) !== 1) throw new RuntimeException('The bounded companion ownership read failed.');
    if (!is_string($table[0]['decision_table'] ?? null) || $table[0]['decision_table'] === '') return [];
    $ids = array_keys($documentIds);
    $array = '{' . implode(',', $ids) . '}';
    $rows = qd_correspondence_pg_rows(
        $connection,
        'SELECT document_id,playthrough_id,request_id,actor_id FROM (SELECT document_id::text AS document_id,playthrough_id,request_id::text AS request_id,actor_id::text AS actor_id,row_number() OVER (PARTITION BY document_id ORDER BY request_id,playthrough_id,actor_id) AS decision_row FROM plugins.correspondence_companion_decision WHERE document_id=ANY($1::uuid[])) AS bounded WHERE decision_row<=2 ORDER BY document_id,request_id,playthrough_id,actor_id',
        [$array],
    );
    $byDocument = [];
    foreach ($rows as $row) {
        if (is_string($row['document_id'] ?? null) && isset($documentIds[$row['document_id']])) $byDocument[$row['document_id']][] = $row;
    }
    return $byDocument;
}

function qd_correspondence_companion_identity_matches(array $rows, array $doc, string $authorKey, string $payerKey, string $recipientKey, string $signatureKey, array $attachments): bool
{
    if (count($rows) !== 1 || $payerKey !== $authorKey || $signatureKey !== $authorKey || $recipientKey !== 'player' || $attachments !== []) return false;
    if (preg_match('/\Anpc:([1-9][0-9]{0,18})\z/D', $authorKey, $match) !== 1) return false;
    $row = $rows[0];
    return is_array($row)
        && ($row['document_id'] ?? null) === ($doc['document_id'] ?? null)
        && ($row['playthrough_id'] ?? null) === ($doc['playthrough_id'] ?? null)
        && ($row['request_id'] ?? null) === ($doc['request_id'] ?? null)
        && ($row['actor_id'] ?? null) === $match[1];
}

function qd_correspondence_project_result(CorrespondenceService $service, object $store, mixed $connection, string $documentId, string $requestId, ?callable $physicalItemResolver = null, ?callable $physicalActorResolver = null): array
{
    $status = $service->get($documentId);
    $doc = $store->loadDocument($documentId);
    if (!is_array($doc) || ($doc['request_id'] ?? null) !== $requestId) throw new QDServerFault('not_found');
    $version = qd_correspondence_loaded_source_version();
    $companionRows = qd_correspondence_version_supports_companion_scheduler($version) ? qd_correspondence_companion_decision_rows($store, $doc) : null;
    $documentIntegrity = qd_correspondence_document_integrity($store, $doc, $companionRows);
    $metadata = qd_correspondence_version_supports_companion_scheduler($version)
        ? ['companionSchedulerOwned' => $companionRows !== [] && $companionRows !== null, 'operatorClosed' => $documentIntegrity && array_key_exists('operator_close', $doc)]
        : [];
    $operation = is_string($doc['operation_id'] ?? null) ? $store->loadOperation($doc['operation_id']) : null;
    $receipt = qd_correspondence_validate_receipt($store, $connection, $doc, $operation, $physicalItemResolver, $physicalActorResolver, $companionRows, $documentIntegrity);
    if (($receipt['confirmed'] ?? false) === true) {
        return ['operationId' => $requestId, 'state' => 'delivered', 'receipt' => ['deliveryConfirmed' => true, 'receivedAt' => $receipt['receivedAt']]] + $metadata;
    }
    if (($receipt['failed'] ?? false) === true && ($status['state'] ?? null) === 'failed') return ['operationId' => $requestId, 'state' => 'rejected'] + $metadata;
    if (($receipt['partial'] ?? false) === true) return ['operationId' => $requestId, 'state' => 'partial'] + $metadata;
    $state = match ($status['state'] ?? null) {
        'queued' => 'queued',
        'dispatched' => 'dispatched',
        default => 'unknown',
    };
    return ['operationId' => $requestId, 'state' => $state] + $metadata;
}

function qd_correspondence_document_integrity(object $store, array $doc, ?array $companionRows = null): bool
{
    try {
        $version = qd_correspondence_loaded_source_version();
        $hasOperatorClose = array_key_exists('operator_close', $doc);
        $documentKeys = ['document_id', 'playthrough_id', 'request_id', 'request_hash', 'body', 'attachments', 'operation_id', 'author', 'payer', 'recipient', 'claimed_sender', 'title', 'anchor'];
        if ($hasOperatorClose && qd_correspondence_version_supports_operator_close($version)) $documentKeys[] = 'operator_close';
        correspondenceKeys($doc, $documentKeys);
        correspondenceUuidCheck($doc['document_id'] ?? null);
        correspondenceUuidCheck($doc['request_id'] ?? null);
        correspondenceBody($doc['body'] ?? null);
        $ownerAttachments = correspondenceAttachments($doc['attachments'] ?? null);
        if (!is_string($doc['request_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $doc['request_hash']) !== 1
            || !is_string($doc['playthrough_id'] ?? null) || !is_string($doc['title'] ?? null)
            || !is_array($doc['anchor'] ?? null) || !is_array($doc['author'] ?? null) || !is_array($doc['payer'] ?? null)
            || !is_array($doc['recipient'] ?? null) || !is_array($doc['claimed_sender'] ?? null)) return false;
        $authorKey = qd_correspondence_actor_record_key($doc['author']);
        $payerKey = qd_correspondence_actor_record_key($doc['payer']);
        $recipientKey = qd_correspondence_actor_record_key($doc['recipient']);
        $signatureKey = qd_correspondence_actor_record_key($doc['claimed_sender']);
        if ($authorKey === null || $payerKey === null || $recipientKey === null || $signatureKey === null
            || $doc['title'] !== 'letter ' . $doc['document_id']
            || (!is_string($doc['operation_id']) && $doc['operation_id'] !== null)) return false;
        $companionRows ??= qd_correspondence_companion_decision_rows($store, $doc);
        if ($companionRows !== []) {
            if (!qd_correspondence_version_supports_companion_scheduler($version)
                || !qd_correspondence_companion_identity_matches($companionRows, $doc, $authorKey, $payerKey, $recipientKey, $signatureKey, $ownerAttachments)) return false;
        } elseif ($payerKey !== 'player') {
            return false;
        }
        $requestInput = [
            'request_id' => $doc['request_id'], 'author' => $authorKey, 'payer' => $payerKey,
            'recipient' => $recipientKey, 'claimed_sender' => $signatureKey,
            'body' => $doc['body'], 'attachments' => $ownerAttachments,
        ];
        if (!hash_equals($doc['request_hash'], hash('sha256', correspondenceCanonicalJson($requestInput)))) return false;
        $documentAnchor = qd_correspondence_fetch_event($store, $doc['anchor']);
        if ($documentAnchor === null || ($documentAnchor['type'] ?? null) !== 'info'
            || ($documentAnchor['sess'] ?? null) !== 'correspondence_v1' || ($documentAnchor['people'] ?? null) !== '') return false;
        $data = json_decode((string)($documentAnchor['data'] ?? ''), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_diff(array_keys($data), ['document_id', 'scope_token']) !== []
            || ($data['document_id'] ?? null) !== $doc['document_id']) return false;
        correspondenceUuidCheck($data['scope_token'] ?? null);
        if ($hasOperatorClose) {
            if (!qd_correspondence_version_supports_operator_close($version)) return false;
            if (is_string($doc['operation_id'])) correspondenceUuidCheck($doc['operation_id']);
            $close = $doc['operator_close'];
            if (!is_array($close)) return false;
            correspondenceKeys($close, ['rowid', 'ts', 'gamets', 'type', 'data', 'sess', 'localts', 'people']);
            $rowId = $close['rowid'];
            if ((!is_int($rowId) && (!is_string($rowId) || !ctype_digit($rowId))) || (int)$rowId < 1
                || !is_numeric($close['ts']) || !is_numeric($close['gamets']) || !is_numeric($close['localts'])
                || !in_array($close['type'], ['info', 'correspondence_operator_closed'], true)
                || $close['sess'] !== 'correspondence_v1' || $close['people'] !== ''
                || $close['data'] !== correspondenceJson(['document_id' => $doc['document_id'], 'operation_id' => $doc['operation_id'], 'reason' => 'operator_closed'])) return false;
            // Presence stays authoritative when the owner prunes the close event.
        }
        return true;
    } catch (Throwable) {
        return false;
    }
}

function qd_correspondence_bridge_receipt_wire_order(array $receipt): ?array
{
    // JSONB readback can reorder object keys; rebuild only the pinned bridge's exact wire order.
    $receiptOrder = ['protocol_version', 'document_id', 'operation_id', 'receipt_id', 'playthrough_id', 'scope_token', 'nonce', 'status', 'reason', 'note_form_id', 'attachments'];
    $attachmentOrder = ['form_id', 'quantity', 'before_source', 'after_source', 'before_recipient', 'after_recipient'];
    if (count($receipt) !== count($receiptOrder) || array_diff_key($receipt, array_fill_keys($receiptOrder, true)) !== []) return null;
    foreach ($receiptOrder as $key) if (!array_key_exists($key, $receipt)) return null;
    if (!is_array($receipt['attachments']) || !array_is_list($receipt['attachments'])) return null;

    $attachments = [];
    foreach ($receipt['attachments'] as $attachment) {
        if (!is_array($attachment) || count($attachment) !== count($attachmentOrder)
            || array_diff_key($attachment, array_fill_keys($attachmentOrder, true)) !== []) return null;
        $orderedAttachment = [];
        foreach ($attachmentOrder as $key) {
            if (!array_key_exists($key, $attachment)) return null;
            $orderedAttachment[$key] = $attachment[$key];
        }
        $attachments[] = $orderedAttachment;
    }

    $orderedReceipt = [];
    foreach ($receiptOrder as $key) $orderedReceipt[$key] = $key === 'attachments' ? $attachments : $receipt[$key];
    return $orderedReceipt;
}

function qd_correspondence_validate_receipt(object $store, mixed $connection, array $doc, ?array $operation, ?callable $physicalItemResolver = null, ?callable $physicalActorResolver = null, ?array $companionRows = null, ?bool $documentIntegrity = null): array
{
    if (!($documentIntegrity ?? qd_correspondence_document_integrity($store, $doc, $companionRows))
        || !is_array($operation) || ($doc['operation_id'] ?? null) !== ($operation['operation_id'] ?? null)
        || ($operation['document_id'] ?? null) !== ($doc['document_id'] ?? null)
        || !is_array($operation['receipt'] ?? null) || !is_array($operation['receipt_anchor'] ?? null)
        || !is_array($operation['dispatch_anchor'] ?? null) || !$store->eventMatches($operation['dispatch_anchor'])
        || !$store->eventMatches($operation['receipt_anchor'])) {
        return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
    }
    $receipt = $operation['receipt'];
    try {
        $ownerAttachments = correspondenceAttachments($doc['attachments']);
        correspondenceKeys($operation, ['protocol_version', 'document_id', 'operation_id', 'receipt_id', 'playthrough_id', 'scope_token', 'nonce', 'recipient', 'payer', 'title', 'renderer_key', 'attachments', 'receipt', 'receipt_anchor', 'source', 'dispatch_anchor']);
        foreach (['document_id', 'operation_id', 'receipt_id', 'scope_token'] as $key) correspondenceUuidCheck($operation[$key] ?? null);
        if (($operation['protocol_version'] ?? null) !== 1 || ($operation['playthrough_id'] ?? null) !== $doc['playthrough_id']
            || ($operation['title'] ?? null) !== $doc['title'] || !is_string($operation['nonce'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $operation['nonce']) !== 1
            || !is_string($operation['renderer_key'] ?? null) || $operation['renderer_key'] !== md5(strtolower(trim($doc['title'])))
            || !is_array($operation['attachments'] ?? null) || !is_array($operation['dispatch_anchor'] ?? null)) {
            return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
        }
        if ($physicalActorResolver === null) {
            $physicalActorResolver = qd_correspondence_uses_restore_fence()
                ? fn(array $actor): array => $store->physicalActor($actor)
                : static fn(array $actor): array => qd_correspondence_physical_actor($connection, $actor);
        }
        if (correspondenceCanonicalJson($operation['recipient'] ?? []) !== correspondenceCanonicalJson($physicalActorResolver($doc['recipient']))
            || correspondenceCanonicalJson($operation['payer'] ?? []) !== correspondenceCanonicalJson($physicalActorResolver($doc['payer']))) {
            return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
        }
        $dispatchAnchor = qd_correspondence_fetch_event($store, $operation['dispatch_anchor']);
        if ($dispatchAnchor === null || ($dispatchAnchor['type'] ?? null) !== 'info'
            || ($dispatchAnchor['sess'] ?? null) !== 'correspondence_v1' || ($dispatchAnchor['people'] ?? null) !== '') {
            return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
        }
        $dispatchData = json_decode((string)($dispatchAnchor['data'] ?? ''), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($dispatchData) || array_diff(array_keys($dispatchData), ['document_id', 'operation_id']) !== []
            || ($dispatchData['document_id'] ?? null) !== $doc['document_id'] || ($dispatchData['operation_id'] ?? null) !== $operation['operation_id']) {
            return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
        }
        correspondenceKeys($receipt, ['protocol_version', 'document_id', 'operation_id', 'receipt_id', 'playthrough_id', 'scope_token', 'nonce', 'status', 'reason', 'note_form_id', 'attachments']);
        $anchorRow = qd_correspondence_fetch_event($store, $operation['receipt_anchor']);
        if ($anchorRow === null || ($anchorRow['type'] ?? null) !== 'info' || ($anchorRow['sess'] ?? null) !== 'correspondence_v1' || ($anchorRow['people'] ?? null) !== '') {
            return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
        }
        $anchor = json_decode((string)($anchorRow['data'] ?? ''), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($anchor) || array_diff(array_keys($anchor), ['document_id', 'operation_id', 'receipt_hash']) !== []
            || ($anchor['document_id'] ?? null) !== ($doc['document_id'] ?? null)
            || ($anchor['operation_id'] ?? null) !== ($operation['operation_id'] ?? null)
            || !is_string($anchor['receipt_hash'] ?? null)) {
            return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
        }
        $receiptHash = hash('sha256', correspondenceJson($receipt));
        if (!hash_equals($anchor['receipt_hash'], $receiptHash)) {
            $wireOrderedReceipt = qd_correspondence_bridge_receipt_wire_order($receipt);
            if ($wireOrderedReceipt === null
                || !hash_equals($anchor['receipt_hash'], hash('sha256', correspondenceJson($wireOrderedReceipt)))) {
                return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
            }
        }
        foreach (['document_id', 'operation_id', 'receipt_id', 'scope_token', 'nonce'] as $key) {
            if (!is_string($operation[$key] ?? null) || !is_string($receipt[$key] ?? null) || !hash_equals($operation[$key], $receipt[$key])) {
                return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
            }
        }
        if (($receipt['protocol_version'] ?? null) !== 1 || ($receipt['playthrough_id'] ?? null) !== ($doc['playthrough_id'] ?? null)
            || !in_array($receipt['status'] ?? null, ['delivered', 'failed', 'unknown'], true)
            || !is_string($receipt['reason'] ?? null) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $receipt['reason']) !== 1
            || !is_int($receipt['note_form_id'] ?? null) || $receipt['note_form_id'] < -2147483648 || $receipt['note_form_id'] > 2147483647
            || !is_array($receipt['attachments'] ?? null) || !array_is_list($receipt['attachments'])) {
            return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
        }
        $expected = [];
        $physicalAttachments = [];
        foreach ($ownerAttachments as $item) {
            if (!is_array($item)) return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
            if ($physicalItemResolver === null) {
                $physicalItemResolver = qd_correspondence_uses_restore_fence()
                    ? fn(array $candidate): array => $store->physicalItem($candidate)
                    : static fn(array $candidate): array => qd_correspondence_physical_item($connection, $candidate);
            }
            $physical = $physicalItemResolver($item);
            $formId = $physical['form_id'] ?? null;
            if (!is_int($formId) || isset($expected[$formId])) return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
            $physicalAttachments[] = $physical;
            $expected[$formId] = ['item' => $item, 'quantity' => $item['quantity'] ?? null];
        }
        if (correspondenceCanonicalJson($operation['attachments']) !== correspondenceCanonicalJson($physicalAttachments)) {
            return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
        }
        $seen = [];
        $confirmedItems = [];
        $partial = ($receipt['note_form_id'] ?? 0) !== 0;
        $signedUnknownVersion = ($receipt['status'] ?? null) === 'unknown'
            && qd_correspondence_version_supports_signed_unknown(qd_correspondence_loaded_source_version());
        foreach ($receipt['attachments'] as $actual) {
            if (!is_array($actual)) return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
            correspondenceKeys($actual, ['form_id', 'quantity', 'before_source', 'after_source', 'before_recipient', 'after_recipient']);
            foreach ($actual as $key => $value) {
                if (!is_int($value) || ($key !== 'form_id' && (
                    $key === 'quantity' && $signedUnknownVersion
                        ? ($value < -2147483648 || $value > 2147483647)
                        : ($value < 0 || $value > 2147483647)
                ))) {
                    return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
                }
            }
            $form = $actual['form_id'];
            if (!isset($expected[$form]) || isset($seen[$form])
                || (!$signedUnknownVersion && $actual['quantity'] > $expected[$form]['quantity'])
                || ($signedUnknownVersion && $actual['after_recipient'] - $actual['before_recipient'] !== $actual['quantity'])) {
                return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
            }
            $seen[$form] = true;
            $changed = $actual['before_source'] !== $actual['after_source'] || $actual['before_recipient'] !== $actual['after_recipient'];
            $partial = $partial || $changed || $actual['quantity'] > 0;
            if ($receipt['status'] === 'delivered'
                && ($actual['quantity'] !== $expected[$form]['quantity']
                    || $actual['before_source'] - $actual['after_source'] !== $actual['quantity']
                    || $actual['after_recipient'] - $actual['before_recipient'] !== $actual['quantity'])) {
                return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
            }
            if ($receipt['status'] === 'failed'
                && ($actual['quantity'] !== 0 || $changed || $receipt['note_form_id'] !== 0)) {
                return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
            }
        }
        if ($receipt['status'] === 'delivered') {
            if ($receipt['note_form_id'] === 0 || count($seen) !== count($expected)) return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
            foreach ($expected as $formId => $expectedItem) {
                $quantity = null;
                foreach ($receipt['attachments'] as $actual) if ($actual['form_id'] === $formId) { $quantity = $actual['quantity']; break; }
                if (!is_int($quantity)) return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
                $label = is_string($expectedItem['item']['form_id'] ?? null) ? $expectedItem['item']['form_id'] : 'Item';
                $ignored = false;
                $confirmedItems[] = ['label' => qd_correspondence_clip_utf8($label, 256, $ignored), 'quantity' => $quantity];
            }
            $at = qd_correspondence_received_at($anchorRow['ts'] ?? null);
            return ['confirmed' => true, 'failed' => false, 'partial' => false, 'receivedAt' => $at, 'attachments' => $confirmedItems];
        }
        if ($receipt['status'] === 'failed') {
            return ['confirmed' => false, 'failed' => !$partial && $receipt['note_form_id'] === 0, 'partial' => false, 'attachments' => []];
        }
        return ['confirmed' => false, 'failed' => false, 'partial' => $partial, 'attachments' => []];
    } catch (Throwable) {
        return ['confirmed' => false, 'failed' => false, 'partial' => false, 'attachments' => []];
    }
}

function qd_correspondence_physical_item(mixed $connection, array $item): array
{
    qd_correspondence_load_sources();
    $store = new CorrespondencePgStore(
        $connection,
        static fn($candidate): ?string => null,
        static fn(string $title, string $body): bool => false,
    );
    return $store->physicalItem($item);
}

function qd_correspondence_physical_actor(mixed $connection, array $actor): array
{
    qd_correspondence_load_sources();
    $store = new CorrespondencePgStore(
        $connection,
        static fn($candidate): ?string => null,
        static fn(string $title, string $body): bool => false,
    );
    return $store->physicalActor($actor);
}

function qd_correspondence_actor_record_key(array $actor): ?string
{
    try {
        correspondenceKeys($actor, ['kind', 'id', 'name']);
    } catch (Throwable) {
        return null;
    }
    if (!is_string($actor['name'] ?? null) || !correspondenceActorName($actor['name'])) return null;
    if (($actor['kind'] ?? null) === 'player' && ($actor['id'] ?? null) === null) return 'player';
    if (($actor['kind'] ?? null) === 'npc' && is_int($actor['id'] ?? null) && $actor['id'] > 0) return 'npc:' . $actor['id'];
    return null;
}

function qd_correspondence_fetch_event(object $store, array $anchor): ?array
{
    $rowId = $anchor['rowid'] ?? null;
    if ((!is_int($rowId) || $rowId < 1) && (!is_string($rowId) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $rowId) !== 1)) return null;
    if (method_exists($store, 'eventRow')) {
        $row = $store->eventRow($anchor);
        return is_array($row) && $row == $anchor && $store->eventMatches($anchor) ? $row : null;
    }
    $rows = $store->query('SELECT rowid,ts,gamets,type,data,sess,localts,people FROM public.eventlog WHERE rowid=$1', [(string)$rowId]);
    if (count($rows) !== 1 || $rows[0] != $anchor || !$store->eventMatches($anchor)) return null;
    return $rows[0];
}

function qd_correspondence_mailbox_projection(array $doc, array $receipt, bool &$limited): array
{
    $sender = is_array($doc['claimed_sender'] ?? null) ? ($doc['claimed_sender']['name'] ?? null) : null;
    $body = $doc['body'] ?? null;
    if (!is_string($sender) || !is_string($body) || preg_match('//u', $body) !== 1) throw new RuntimeException('The Correspondence document is invalid.');
    $preview = qd_correspondence_clip_utf8($body, 128, $limited);
    return [
        'letterId' => (string)$doc['document_id'],
        'claimedSignature' => qd_correspondence_clip_utf8($sender, 256, $limited),
        'receivedAt' => $receipt['receivedAt'],
        'bodyPreview' => $preview,
        'attachments' => $receipt['attachments'],
        'deliveryConfirmed' => true,
    ];
}

function qd_correspondence_mailbox_source_rows(mixed $connection, string $scope, array $actor): array
{
    if ($actor['kind'] === 'player') {
        $filter = "d.payload->'recipient'->>'kind'='player' AND d.payload->'recipient'->>'id' IS NULL";
        $parameters = [$scope, (string)(QD_CORRESPONDENCE_MAILBOX_SCAN_MAX + 1)];
        $sql = "SELECT d.document_id::text AS document_id,d.payload::text AS document_payload,COALESCE(o.payload::text,'') AS operation_payload,d.created_at::text AS created_at\n"
            . "FROM plugins.correspondence_documents d LEFT JOIN plugins.correspondence_operations o ON o.operation_id::text = d.payload->>'operation_id'\n"
            . "WHERE d.playthrough_id=$1 AND $filter ORDER BY d.created_at DESC,d.document_id DESC LIMIT $2::integer";
    } else {
        $filter = "d.payload->'recipient'->>'kind'='npc' AND d.payload->'recipient'->>'id'=$2";
        $parameters = [$scope, (string)$actor['id'], (string)(QD_CORRESPONDENCE_MAILBOX_SCAN_MAX + 1)];
        $sql = "SELECT d.document_id::text AS document_id,d.payload::text AS document_payload,COALESCE(o.payload::text,'') AS operation_payload,d.created_at::text AS created_at\n"
            . "FROM plugins.correspondence_documents d LEFT JOIN plugins.correspondence_operations o ON o.operation_id::text = d.payload->>'operation_id'\n"
            . "WHERE d.playthrough_id=$1 AND $filter ORDER BY d.created_at DESC,d.document_id DESC LIMIT $3::integer";
    }
    $rows = qd_correspondence_pg_rows($connection, $sql, $parameters);
    $limited = count($rows) > QD_CORRESPONDENCE_MAILBOX_SCAN_MAX;
    $rows = array_slice($rows, 0, QD_CORRESPONDENCE_MAILBOX_SCAN_MAX);
    $documents = [];
    $operations = [];
    foreach ($rows as $row) {
        $doc = json_decode((string)($row['document_payload'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($doc) || !is_string($row['document_id'] ?? null) || ($doc['document_id'] ?? null) !== $row['document_id']) {
            $limited = true;
            continue;
        }
        $documents[$row['document_id']] = $doc;
        if (is_string($doc['operation_id'] ?? null) && is_string($row['operation_payload'] ?? null) && $row['operation_payload'] !== '') {
            $op = json_decode($row['operation_payload'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($op)) $operations[$doc['operation_id']] = $op;
            else $limited = true;
        }
    }
    return ['rawRows' => $rows, 'documents' => $documents, 'operations' => $operations, 'limited' => $limited];
}

function qd_correspondence_detail_source_rows(mixed $connection, string $scope, array $actor, string $letterId): array
{
    if ($actor['kind'] === 'player') {
        $recipient = "d.payload->'recipient'->>'kind'='player' AND d.payload->'recipient'->>'id' IS NULL";
        $parameters = [$letterId, $scope];
    } else {
        $recipient = "d.payload->'recipient'->>'kind'='npc' AND d.payload->'recipient'->>'id'=$3";
        $parameters = [$letterId, $scope, (string)$actor['id']];
    }
    $sql = "SELECT d.document_id::text AS document_id,d.payload::text AS document_payload,COALESCE(o.payload::text,'') AS operation_payload\n"
        . "FROM plugins.correspondence_documents d LEFT JOIN plugins.correspondence_operations o ON o.operation_id::text = d.payload->>'operation_id'\n"
        . "WHERE d.document_id=$1 AND d.playthrough_id=$2 AND $recipient LIMIT 2";
    return qd_correspondence_pg_rows($connection, $sql, $parameters);
}

function qd_correspondence_prefetch_events(mixed $connection, array $documents, array $operations): array
{
    $ids = [];
    foreach ($documents as $doc) {
        if (is_array($doc['anchor'] ?? null)) $ids[] = $doc['anchor']['rowid'] ?? null;
    }
    foreach ($operations as $operation) {
        foreach (['dispatch_anchor', 'receipt_anchor', 'source'] as $field) {
            if (is_array($operation[$field] ?? null)) $ids[] = $operation[$field]['rowid'] ?? null;
        }
    }
    $ids = array_values(array_unique(array_filter($ids, static fn($id): bool => (is_int($id) && $id > 0) || (is_string($id) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) === 1))));
    if ($ids === []) return [];
    $array = '{' . implode(',', array_map('strval', $ids)) . '}';
    $rows = qd_correspondence_pg_rows($connection,
        'SELECT rowid::text AS rowid,ts::text AS ts,gamets::text AS gamets,type,data,sess,localts::text AS localts,people FROM public.eventlog WHERE rowid=ANY($1::bigint[])',
        [$array]);
    $events = [];
    foreach ($rows as $row) $events[(string)$row['rowid']] = $row;
    return $events;
}

function qd_correspondence_catalog_revision(string $scopeKey, array $actors, bool $sourceLimited): string
{
    $source = json_encode(
        ['scope' => $scopeKey, 'sourceLimited' => $sourceLimited, 'actors' => $actors],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    return hash('sha256', $source);
}

function qd_correspondence_make_catalog_cursor(string $scopeKey, string $revision, int $offset): string
{
    $json = json_encode(
        ['v' => 1, 's' => $scopeKey, 'h' => $revision, 'o' => $offset, 'e' => time() + QD_CORRESPONDENCE_CURSOR_TTL],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

function qd_correspondence_catalog_cursor_offset(?string $cursor, string $scopeKey, string $revision, int $actorCount): int
{
    if ($cursor === null) return 0;
    if ($cursor === '' || strlen($cursor) > 512 || preg_match('/\A[A-Za-z0-9_-]+\z/D', $cursor) !== 1) {
        throw new QDServerFault('stale_revision');
    }
    $raw = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', (4 - strlen($cursor) % 4) % 4), true);
    $state = is_string($raw) ? json_decode($raw, true, 8) : null;
    if (!is_array($state) || array_keys($state) !== ['v', 's', 'h', 'o', 'e'] || ($state['v'] ?? null) !== 1
        || !is_string($state['s'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $state['s']) !== 1
        || !is_string($state['h'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $state['h']) !== 1
        || !is_int($state['o'] ?? null) || $state['o'] < 1 || $state['o'] >= $actorCount
        || !is_int($state['e'] ?? null) || $state['e'] < time()) {
        throw new QDServerFault('stale_revision');
    }
    if (!hash_equals($scopeKey, $state['s']) || !hash_equals($revision, $state['h'])) {
        throw new QDServerFault('stale_revision');
    }
    return $state['o'];
}

function qd_correspondence_source_revision(string $recipientKey, string $scopeKey, array $rows, array $events, ?array $companionDecisions = null): string
{
    $hash = hash_init('sha256');
    hash_update($hash, $recipientKey . "\0" . $scopeKey . "\0");
    foreach ($rows as $row) {
        hash_update($hash, (string)($row['document_id'] ?? '') . "\0" . (string)($row['document_payload'] ?? '') . "\0" . (string)($row['operation_payload'] ?? '') . "\0");
    }
    ksort($events, SORT_NUMERIC);
    foreach ($events as $id => $event) hash_update($hash, (string)$id . "\0" . correspondenceJson($event) . "\0");
    if ($companionDecisions !== null) {
        hash_update($hash, "\0companion-decisions-v1\0");
        ksort($companionDecisions, SORT_STRING);
        foreach ($companionDecisions as $documentId => $decisionRows) {
            $normalizedRows = [];
            foreach ($decisionRows as $row) {
                if (!is_array($row)) continue;
                $normalizedRows[] = [
                    'document_id' => (string)($row['document_id'] ?? ''),
                    'playthrough_id' => (string)($row['playthrough_id'] ?? ''),
                    'request_id' => (string)($row['request_id'] ?? ''),
                    'actor_id' => (string)($row['actor_id'] ?? ''),
                ];
            }
            usort($normalizedRows, static fn(array $left, array $right): int => strcmp(correspondenceJson($left), correspondenceJson($right)));
            hash_update($hash, (string)$documentId . "\0" . correspondenceJson($normalizedRows) . "\0");
        }
    }
    return hash_final($hash);
}

function qd_correspondence_make_cursor(string $recipientKey, string $scopeKey, string $revision, int $offset): string
{
    $json = json_encode(['v' => 1, 'r' => $recipientKey, 's' => $scopeKey, 'h' => $revision, 'o' => $offset, 'e' => time() + QD_CORRESPONDENCE_CURSOR_TTL], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

function qd_correspondence_cursor_offset(?string $cursor, string $recipientKey, string $scopeKey, string $revision): int
{
    if ($cursor === null) return 0;
    $raw = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', (4 - strlen($cursor) % 4) % 4), true);
    $state = is_string($raw) ? json_decode($raw, true, 8) : null;
    if (!is_array($state) || array_keys($state) !== ['v', 'r', 's', 'h', 'o', 'e'] || ($state['v'] ?? null) !== 1
        || !is_string($state['r'] ?? null) || !is_string($state['s'] ?? null) || !is_string($state['h'] ?? null)
        || !is_int($state['o'] ?? null) || $state['o'] < 0 || $state['o'] > QD_CORRESPONDENCE_MAILBOX_SCAN_MAX
        || !is_int($state['e'] ?? null) || $state['e'] < time()) {
        throw new QDServerFault('stale_revision');
    }
    if (!hash_equals($scopeKey, $state['s'])) throw new QDServerFault('stale_context');
    if (!hash_equals($recipientKey, $state['r']) || !hash_equals($revision, $state['h'])) throw new QDServerFault('stale_revision');
    return $state['o'];
}

function qd_correspondence_read_snapshot(string $scopeKey, callable $callback, ?array $context = null): mixed
{
    if (qd_correspondence_uses_restore_fence()) {
        return qd_correspondence_dev18_fenced_snapshot($scopeKey, qd_correspondence_dev18_authority($context ?? []), $callback);
    }
    return qd_runtime_with_core_db(static function ($connection) use ($scopeKey, $callback): mixed {
        if (@pg_query($connection, 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY') === false) throw new RuntimeException('The Correspondence read snapshot is unavailable.');
        try {
            $active = qd_runtime_active_playthrough($connection);
            qd_correspondence_assert_active_scope($active, $scopeKey);
            $result = $callback($connection, $active);
            if (@pg_query($connection, 'COMMIT') === false) throw new RuntimeException('The Correspondence read snapshot could not be closed.');
            return $result;
        } catch (Throwable $error) {
            if (pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) @pg_query($connection, 'ROLLBACK');
            throw $error;
        }
    });
}

function qd_correspondence_dev18_authority(array $context): array
{
    $authority = $context['__qdCorrespondenceDev18'] ?? null;
    if (!is_array($authority) || !is_string($authority['coreRoot'] ?? null)
        || !is_string($authority['ownerRoot'] ?? null) || !is_string($authority['generation'] ?? null)
        || !is_resource($authority['lease'] ?? null)) {
        throw new QDServerFault('unavailable');
    }
    $coreRoot = qd_runtime_core_root();
    if (realpath($authority['coreRoot']) !== $coreRoot || realpath($authority['ownerRoot']) !== qd_correspondence_source_root()
        || dirname($authority['ownerRoot'], 2) !== $coreRoot) {
        throw new QDServerFault('unavailable');
    }
    return $authority;
}

function qd_correspondence_valid_native_timestamp(mixed $timestamp): bool
{
    return is_string($timestamp) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $timestamp) === 1
        && filter_var($timestamp, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
}

/** Close only a fresh owner connection; the owner store may already close it on unlock failure. */
function qd_correspondence_close_owned_pg_connection(mixed $connection): void
{
    try {
        @pg_close($connection);
    } catch (Throwable) {
        // Preserve the operation error when the pinned store already closed this connection.
    }
}

function qd_correspondence_dev18_fenced_snapshot(string $scopeKey, array $authority, callable $callback): mixed
{
    return qd_runtime_with_core_db(static function ($connection) use ($scopeKey, $authority, $callback): mixed {
        $locked = false;
        $begun = false;
        try {
            qd_correspondence_pg_rows($connection, "SET lock_timeout='3s'");
            qd_correspondence_pg_rows($connection, 'SELECT pg_advisory_lock_shared(18459814, 1) AS acquired');
            $locked = true;
            if (!hash_equals($authority['generation'], qd_runtime_correspondence_read_generation())) {
                throw new QDServerFault('stale_context');
            }
            qd_correspondence_pg_rows($connection, 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $begun = true;

            $token = qd_correspondence_dev18_assert_restore_current($connection, $authority, null, true);
            $active = qd_runtime_active_playthrough($connection);
            qd_correspondence_assert_active_scope($active, $scopeKey);
            $result = $callback($connection, $active);
            if (pg_transaction_status($connection) !== PGSQL_TRANSACTION_INTRANS) {
                throw new RuntimeException('The Correspondence read snapshot ended before validation.');
            }
            qd_correspondence_dev18_assert_restore_current($connection, $authority, $token, false);
            qd_correspondence_pg_rows($connection, 'COMMIT');
            $begun = false;
            qd_correspondence_dev18_assert_restore_current($connection, $authority, $token, false);
            return $result;
        } catch (Throwable $error) {
            if ($begun && pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) @pg_query($connection, 'ROLLBACK');
            throw $error;
        } finally {
            if ($locked) {
                $rows = qd_correspondence_pg_rows($connection, 'SELECT pg_advisory_unlock_shared(18459814, 1) AS released');
                if (($rows[0]['released'] ?? null) !== 't') throw new RuntimeException('The Correspondence read fence could not be released.');
            }
        }
    });
}

function qd_correspondence_dev18_assert_restore_current(mixed $connection, array $authority, ?string $expectedToken, bool $capture): string
{
    if (!hash_equals($authority['generation'], qd_runtime_correspondence_read_generation())) throw new QDServerFault('stale_context');
    $rows = qd_correspondence_pg_rows($connection, 'SELECT epoch::text AS epoch,pending,native_ts_floor::text AS native_ts_floor FROM plugins.correspondence_restore_state WHERE singleton IS TRUE');
    if (count($rows) !== 1 || !is_string($rows[0]['epoch'] ?? null)
        || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $rows[0]['epoch']) !== 1
        || ($rows[0]['pending'] ?? null) !== 'f') {
        throw new QDServerFault('stale_context');
    }
    $floor = $rows[0]['native_ts_floor'] ?? null;
    if (!is_string($floor) || preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $floor) !== 1
        || filter_var($floor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
        throw new QDServerFault('stale_context');
    }
    $sentinel = qd_correspondence_dev18_read_sentinel($authority['coreRoot']);
    if (($sentinel['state'] ?? null) !== 'clear' || !hash_equals($rows[0]['epoch'], (string)($sentinel['epoch'] ?? ''))) {
        throw new QDServerFault('stale_context');
    }
    $token = $rows[0]['epoch'] . ':' . ($authority['generation'] === '' ? 'none' : $authority['generation']);
    $captured = $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'] ?? false;
    if ($captured === true) {
        $ingressToken = $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'] ?? null;
        $nativeTs = $GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS'] ?? null;
        if (!is_string($ingressToken) || $ingressToken === '' || !hash_equals($ingressToken, $token)
            || ($nativeTs !== null && (!qd_correspondence_valid_native_timestamp($nativeTs) || (int)$nativeTs <= (int)$floor))) {
            throw new QDServerFault('stale_context');
        }
    } elseif ($capture && $captured === false) {
        $nativeTs = $GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS'] ?? null;
        if ($nativeTs !== null) throw new QDServerFault('stale_context');
        $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'] = $token;
        $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'] = true;
    } else {
        throw new QDServerFault('stale_context');
    }
    if ($expectedToken !== null && !hash_equals($expectedToken, $token)) throw new QDServerFault('stale_context');
    if (!hash_equals($authority['generation'], qd_runtime_correspondence_read_generation())) throw new QDServerFault('stale_context');
    return $token;
}

function qd_correspondence_dev18_read_sentinel(string $coreRoot): array
{
    $path = $coreRoot . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'playthrough_runtime' . DIRECTORY_SEPARATOR . 'correspondence.restore.json';
    clearstatcache(true, $path);
    $linkStat = @lstat($path);
    $pathStat = @stat($path);
    if (!is_array($linkStat) || !is_array($pathStat) || (($linkStat['mode'] ?? 0) & 0170000) !== 0100000
        || (($pathStat['mode'] ?? 0) & 0170000) !== 0100000 || !is_int($pathStat['dev'] ?? null)
        || !is_int($pathStat['ino'] ?? null) || $pathStat['ino'] === 0
        || ($linkStat['dev'] ?? null) !== $pathStat['dev'] || ($linkStat['ino'] ?? null) !== $pathStat['ino']
        || ($pathStat['size'] ?? 4097) < 1 || $pathStat['size'] > 4096) {
        throw new QDServerFault('stale_context');
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) throw new QDServerFault('stale_context');
    try {
        $handleStat = fstat($handle);
        $bytes = stream_get_contents($handle, 4097);
        clearstatcache(true, $path);
        $afterLinkStat = @lstat($path);
        $after = @stat($path);
        if (!is_array($handleStat) || !is_string($bytes) || strlen($bytes) > 4096
            || (($handleStat['mode'] ?? 0) & 0170000) !== 0100000
            || ($handleStat['dev'] ?? null) !== $pathStat['dev'] || ($handleStat['ino'] ?? null) !== $pathStat['ino']
            || !is_array($afterLinkStat) || (($afterLinkStat['mode'] ?? 0) & 0170000) !== 0100000
            || !is_array($after) || $after['dev'] !== $handleStat['dev'] || $after['ino'] !== $handleStat['ino']
            || $afterLinkStat['dev'] !== $handleStat['dev'] || $afterLinkStat['ino'] !== $handleStat['ino']) {
            throw new QDServerFault('stale_context');
        }
    } finally {
        fclose($handle);
    }
    try {
        $sentinel = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new QDServerFault('stale_context');
    }
    if (!is_array($sentinel) || count($sentinel) !== 3 || ($sentinel['schema'] ?? null) !== 'correspondence.restore.v1'
        || !in_array($sentinel['state'] ?? null, ['clear', 'pending'], true)
        || !is_string($sentinel['epoch'] ?? null)
        || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $sentinel['epoch']) !== 1) {
        throw new QDServerFault('stale_context');
    }
    return $sentinel;
}

function qd_correspondence_assert_active_scope(array $active, string $scopeKey): void
{
    if (!is_string($active['scopeKey'] ?? null) || !hash_equals($active['scopeKey'], $scopeKey)) throw new QDServerFault('stale_context');
}

function qd_correspondence_read_store(mixed $connection, array $active, array $documents = [], array $operations = [], array $events = [], ?array $companionDecisions = null): object
{
    return new class($connection, $active, $documents, $operations, $events, $companionDecisions) implements CorrespondenceStore {
        private ?array $actorRows = null;
        private ?string $playerName = null;
        private bool $actorLimited = false;
        private string $scopeId;
        public function __construct(private mixed $connection, private array $active, private array $documents, private array $operations, private array $events, private ?array $companionDecisions)
        {
            $this->scopeId = (string)($active['profileId'] ?? '');
        }
        public function companionDecisionRows(string $documentId): ?array
        {
            return $this->companionDecisions === null ? null : ($this->companionDecisions[$documentId] ?? []);
        }
        public function transaction(callable $work): mixed { return $work(); }
        // The caller already owns the repeatable-read, read-only snapshot.
        public function readTransaction(callable $work): mixed { return $work(); }
        public function scope(): array
        {
            $clock = $this->query('SELECT ts,gamets FROM public.eventlog ORDER BY rowid DESC LIMIT 1');
            if (count($clock) !== 1) throw new DomainException('game_clock_unavailable');
            return ['playthrough_id' => $this->scopeId, 'ts' => $clock[0]['ts'], 'gamets' => $clock[0]['gamets']];
        }
        public function actors(): array
        {
            if ($this->actorRows !== null) return $this->actorRows;
            $rows = $this->query('SELECT id,npc_name,refid,profile_id FROM public.core_npc_master WHERE id>0 ORDER BY npc_name,id LIMIT 10001');
            $this->actorLimited = count($rows) > 10000;
            $this->actorRows = array_slice($rows, 0, 10000);
            return $this->actorRows;
        }
        public function actorCatalogLimited(): bool { return $this->actorLimited; }
        public function player(): string
        {
            if ($this->playerName !== null) return $this->playerName;
            $rows = $this->query("SELECT value FROM public.core_player WHERE id=$1 LIMIT 2", ['player_name']);
            if (count($rows) !== 1 || !is_string($rows[0]['value'] ?? null)) throw new DomainException('player_unavailable');
            $this->playerName = $rows[0]['value'];
            return $this->playerName;
        }
        public function findRequest(string $scope, string $request): ?array
        {
            if ($scope !== $this->scopeId) return null;
            $rows = $this->query('SELECT payload FROM plugins.correspondence_documents WHERE playthrough_id=$1 AND request_id=$2 LIMIT 2', [$scope, $request]);
            return $this->decodedPayload($rows);
        }
        public function loadDocument(string $id): ?array
        {
            if (isset($this->documents[$id])) return $this->documents[$id];
            $rows = $this->query('SELECT payload FROM plugins.correspondence_documents WHERE document_id=$1 AND playthrough_id=$2 LIMIT 2', [$id, $this->scopeId]);
            return $this->decodedPayload($rows);
        }
        public function saveDocument(array $document): void { throw new LogicException('read_only_correspondence_store'); }
        public function lockActorRows(array $npcIds, bool $includePlayer = false): array { throw new LogicException('read_only_correspondence_store'); }
        public function documents(string $scope, ?int $recipientId = null): array
        {
            if ($scope !== $this->scopeId) return [];
            if ($recipientId === null) throw new LogicException('unbounded_correspondence_document_scan');
            if ($recipientId < 1) throw new InvalidArgumentException('invalid_recipient_id');
            $rows = $this->query("SELECT payload FROM plugins.correspondence_documents WHERE playthrough_id=$1 AND payload->'recipient'->>'kind'='npc' AND payload->'recipient'->>'id'=\$2 ORDER BY created_at DESC,document_id", [$scope, (string)$recipientId]);
            $documents = [];
            foreach ($rows as $row) {
                if (!is_string($row['payload'] ?? null)) throw new DomainException('document_unavailable');
                $document = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($document)) throw new DomainException('document_unavailable');
                if (($document['playthrough_id'] ?? null) === $scope
                    && ($document['recipient']['kind'] ?? null) === 'npc'
                    && ($document['recipient']['id'] ?? null) === $recipientId) {
                    $documents[] = $document;
                }
            }
            return $documents;
        }
        public function loadOperation(string $id): ?array
        {
            if (isset($this->operations[$id])) return $this->operations[$id];
            $rows = $this->query('SELECT o.payload FROM plugins.correspondence_operations o JOIN plugins.correspondence_documents d ON d.document_id=o.document_id WHERE o.operation_id=$1 AND d.playthrough_id=$2 LIMIT 2', [$id, $this->scopeId]);
            return $this->decodedPayload($rows);
        }
        public function saveOperation(array $operation): void { throw new LogicException('read_only_correspondence_store'); }
        public function appendEvent(string $type, string $data, string $people, string $sess): array { throw new LogicException('read_only_correspondence_store'); }
        public function eventMatches(array $event): bool
        {
            $id = (string)($event['rowid'] ?? '');
            if ($id === '') return false;
            if (array_key_exists($id, $this->events)) return $this->events[$id] == $event;
            $rows = $this->query('SELECT rowid,ts,gamets,type,data,sess,localts,people FROM public.eventlog WHERE rowid=$1 LIMIT 2', [$id]);
            return count($rows) === 1 && $rows[0] == $event;
        }
        public function eventRow(array $event): ?array
        {
            $id = (string)($event['rowid'] ?? '');
            if ($id === '') return null;
            if (array_key_exists($id, $this->events)) return is_array($this->events[$id]) ? $this->events[$id] : null;
            $rows = $this->query('SELECT rowid,ts,gamets,type,data,sess,localts,people FROM public.eventlog WHERE rowid=$1 LIMIT 2', [$id]);
            return count($rows) === 1 ? $rows[0] : null;
        }
        public function managedExecutor(): ?string { throw new LogicException('read_only_correspondence_store'); }
        public function prepareNote(string $title, string $body): void { throw new LogicException('read_only_correspondence_store'); }
        public function queueCommand(string $actor, string $action): void { throw new LogicException('read_only_correspondence_store'); }
        public function physicalActor(array $actor): array
        {
            if (($actor['kind'] ?? null) === 'player') {
                $runtime = '00000014';
            } else {
                $rows = $this->query('SELECT refid FROM public.core_npc_master WHERE id=$1 LIMIT 2', [$actor['id'] ?? null]);
                if (count($rows) !== 1) throw new DomainException('actor_form_unavailable');
                $runtime = $rows[0]['refid'] ?? '';
            }
            if (!is_string($runtime) || preg_match('/\A(?:0x)?([0-9a-fA-F]{8})\z/D', $runtime, $match) !== 1) {
                throw new DomainException('actor_form_unavailable');
            }
            $runtime = strtoupper($match[1]);
            if (str_starts_with($runtime, 'FF')) throw new DomainException('dynamic_form_unsupported');
            $prefix = str_starts_with($runtime, 'FE') ? substr($runtime, 0, 5) : substr($runtime, 0, 2);
            $rows = array_values(array_filter(
                $this->query('SELECT plugin_name,formid_prefix FROM public.game_plugins WHERE upper(formid_prefix COLLATE "C")=upper($1 COLLATE "C") LIMIT 2', [$prefix]),
                fn(array $row): bool => is_string($row['formid_prefix'] ?? null) && strtoupper($row['formid_prefix']) === $prefix,
            ));
            if (count($rows) !== 1 || !is_string($rows[0]['plugin_name'] ?? null)) throw new DomainException('plugin_mapping_unavailable');
            $plugin = $rows[0]['plugin_name'];
            if (preg_match('/\A[^|@\x00-\x1f\/\\\\]{1,200}\.(?i:esm|esp|esl)\z/D', $plugin) !== 1) throw new DomainException('invalid_plugin');
            $id = (int)hexdec($runtime);
            $local = $id & (strlen($prefix) === 5 ? 4095 : 16777215);
            if ($local === 0) throw new DomainException('invalid_local_form');
            if ($id > 2147483647) $id -= 4294967296;
            return ['refid' => $id, 'plugin' => $plugin, 'local_id' => $local];
        }
        public function physicalItem(array $item): array
        {
            correspondenceAttachments([$item]);
            [$plugin, $hex] = explode('|', $item['form_id']);
            $local = (int)hexdec($hex);
            $rows = array_values(array_filter(
                $this->query('SELECT plugin_name,formid_prefix FROM public.game_plugins WHERE lower(plugin_name COLLATE "C")=lower($1 COLLATE "C") LIMIT 2', [$plugin]),
                fn(array $row): bool => is_string($row['plugin_name'] ?? null) && strcasecmp($row['plugin_name'], $plugin) === 0,
            ));
            if (count($rows) !== 1 || !is_string($rows[0]['formid_prefix'] ?? null) || !is_string($rows[0]['plugin_name'] ?? null)) {
                throw new DomainException('plugin_mapping_unavailable');
            }
            $row = $rows[0];
            $prefix = strtoupper($row['formid_prefix']);
            if ($local < 1 || (strlen($prefix) === 5 ? $local > 4095 : $local > 16777215)
                || preg_match('/\A(?:[0-9A-E][0-9A-F]|F[0-9A-D]|FE[0-9A-F]{3})\z/D', $prefix) !== 1) {
                throw new DomainException('invalid_local_form');
            }
            $runtime = strlen($prefix) === 5 ? (hexdec($prefix) << 12) | $local : (hexdec($prefix) << 24) | $local;
            $formId = (int)$runtime;
            if ($formId > 2147483647) $formId -= 4294967296;
            return ['kind' => $item['kind'], 'form_id' => $formId, 'plugin' => $row['plugin_name'], 'local_id' => $local, 'quantity' => $item['quantity']];
        }
        public function query(string $sql, array $parameters = []): array
        {
            if (preg_match('/\A\s*SELECT\b/i', $sql) !== 1 || preg_match('/\b(FOR\s+(?:UPDATE|SHARE)|INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|LOCK|pg_advisory)\b/i', $sql) === 1) {
                throw new LogicException('read_only_correspondence_query');
            }
            return qd_correspondence_pg_rows($this->connection, $sql, $parameters);
        }
        private function decodedPayload(array $rows): ?array
        {
            if (count($rows) > 1) throw new DomainException('ambiguous_correspondence_row');
            if ($rows === [] || !is_string($rows[0]['payload'] ?? null)) return null;
            $payload = json_decode($rows[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
            return is_array($payload) ? $payload : null;
        }
    };
}

function qd_correspondence_pg_rows(mixed $connection, string $sql, array $parameters = []): array
{
    $result = $parameters === [] ? @pg_query($connection, $sql) : @pg_query_params($connection, $sql, $parameters);
    if ($result === false) throw new RuntimeException('The bounded Correspondence database read failed.');
    try {
        if (pg_num_fields($result) === 0) return [];
        return pg_fetch_all($result) ?: [];
    } finally {
        @pg_free_result($result);
    }
}

function qd_correspondence_find_actor(array $actors, string $actorKey): ?array
{
    foreach ($actors as $actor) if (is_array($actor) && ($actor['key'] ?? null) === $actorKey) return $actor;
    return null;
}

function qd_correspondence_actor_key(mixed $key): string
{
    if (!is_string($key) || ($key !== 'player' && preg_match('/\Anpc:[1-9][0-9]{0,18}\z/D', $key) !== 1)) throw new QDServerFault('invalid_request');
    return $key;
}

function qd_correspondence_require_fields(stdClass $payload, array $required, array $optional = []): void
{
    $properties = get_object_vars($payload);
    if (array_diff(array_keys($properties), array_merge($required, $optional)) !== [] || array_diff($required, array_keys($properties)) !== []) {
        throw new QDServerFault('invalid_request');
    }
}

function qd_correspondence_require_access(array $context, array $allowed): void
{
    $scopes = $context['scopes'] ?? null;
    if (!is_array($scopes) || !array_intersect($allowed, $scopes)) throw new QDServerFault('forbidden');
}

function qd_correspondence_assertion(array $context): callable
{
    $assertCurrent = $context['assertCurrent'] ?? null;
    if (!is_callable($assertCurrent)) throw new QDServerFault('unavailable');
    return $assertCurrent;
}

function qd_correspondence_scope_key(array $context): string
{
    $scopeKey = $context['scopeKey'] ?? null;
    if (!is_string($scopeKey) || preg_match('/\A[a-f0-9]{64}\z/D', $scopeKey) !== 1) throw new QDServerFault('unavailable');
    return $scopeKey;
}

function qd_correspondence_clip_utf8(string $value, int $maxBytes, bool &$limited): string
{
    if (strlen($value) <= $maxBytes) return $value;
    $limited = true;
    $value = substr($value, 0, $maxBytes);
    while ($value !== '' && preg_match('//u', $value) !== 1) $value = substr($value, 0, -1);
    return $value;
}

function qd_correspondence_received_at(mixed $timestamp): ?string
{
    if (is_int($timestamp)) $timestamp = (string)$timestamp;
    if (!is_string($timestamp) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $timestamp) !== 1) return null;
    $seconds = filter_var($timestamp, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 253402300799]]);
    return is_int($seconds) ? gmdate('Y-m-d\TH:i:s\Z', $seconds) : null;
}

function qd_correspondence_domain_fault(DomainException $error): QDServerFault
{
    return match ($error->getMessage()) {
        'request_conflict', 'immutable_document_conflict', 'immutable_operation_conflict' => new QDServerFault('conflict'),
        'actor_unavailable', 'document_unavailable', 'operation_unavailable', 'source_unavailable' => new QDServerFault('not_found'),
        'actor_identity_changed', 'stale_request_clock', 'scope_unavailable', 'scope_ambiguous' => new QDServerFault('stale_context'),
        'invalid_actor', 'invalid_body', 'invalid_attachment', 'invalid_attachment_identity', 'invalid_attachments' => new QDServerFault('invalid_request'),
        default => new QDServerFault('unavailable'),
    };
}
