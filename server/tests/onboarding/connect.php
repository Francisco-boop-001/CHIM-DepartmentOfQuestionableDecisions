<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/protocol.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/connect.php';
require_once __DIR__ . '/../../api.php';
require_once __DIR__ . '/../../index.php';

$checks = 0;
function connect_expect(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function connect_uuid(): string
{
    return qd_new_uuid();
}

function connect_token(): string
{
    return qd_new_token();
}

function connect_post(array $request, array $settings, int $now): array
{
    return qd_connect_handle('POST', 'application/json', json_encode($request, JSON_THROW_ON_ERROR), $settings, $now);
}

function connect_json(array $response): array
{
    $decoded = json_decode($response['body'], true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Connect response was not an object.');
    }
    return $decoded;
}

function connect_expect_unavailable(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (QDServerFault $fault) {
        connect_expect($fault->wireCode === 'unavailable', $message);
        return;
    }
    connect_expect(false, $message);
}

function connect_warning_checked(callable $callback)
{
    set_error_handler(static function (int $severity, string $message, string $file, int $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        return $callback();
    } finally {
        restore_error_handler();
    }
}

function connect_origin_expect(array $serverValues, bool $expected, string $message): void
{
    $savedServer = $_SERVER;
    foreach (['HTTP_ORIGIN', 'HTTP_HOST', 'HTTPS', 'HTTP_SEC_FETCH_SITE'] as $key) {
        unset($_SERVER[$key]);
    }
    foreach ($serverValues as $key => $value) {
        $_SERVER[$key] = $value;
    }
    try {
        $actual = connect_warning_checked(static fn(): bool => qd_onboarding_origin_is_same());
        connect_expect($actual === $expected, $message);
    } finally {
        $_SERVER = $savedServer;
    }
}

function connect_fixture(string $label): array
{
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-onboarding-' . $label . '-' . bin2hex(random_bytes(8));
    $webRoot = $base . DIRECTORY_SEPARATOR . 'web';
    $privateRoot = $base . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'questionable_decisions-private';
    if (!mkdir($webRoot, 0700, true) || !mkdir($privateRoot, 0700, true)) {
        throw new RuntimeException('Could not create temporary onboarding test roots.');
    }
    return [
        'root' => $base,
        'settings' => [
            'pairingPath' => $privateRoot . DIRECTORY_SEPARATOR . 'pairing.php',
            'statePath' => $privateRoot . DIRECTORY_SEPARATOR . 'state.php',
            'webRoot' => $webRoot,
            'privateRoot' => $privateRoot,
            'chimRoot' => $base,
        ],
    ];
}

function connect_remove_tree(string $path): void
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
            connect_remove_tree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

connect_origin_expect([
    'HTTP_ORIGIN' => 'http://chim.example',
    'HTTP_HOST' => 'chim.example',
    'HTTPS' => '',
    'HTTP_SEC_FETCH_SITE' => 'same-origin',
], true, 'same HTTP origin should pass without regex warnings');
connect_origin_expect([
    'HTTP_ORIGIN' => 'http://chim.example:80',
    'HTTP_HOST' => 'chim.example',
    'HTTPS' => '',
], true, 'equivalent default HTTP ports should pass');
connect_origin_expect([
    'HTTP_ORIGIN' => 'https://chim.example',
    'HTTP_HOST' => 'chim.example:443',
    'HTTPS' => 'on',
], true, 'equivalent default HTTPS ports should pass');
connect_origin_expect([
    'HTTP_ORIGIN' => 'https://[2001:db8::1]:8443',
    'HTTP_HOST' => '[2001:db8::1]:8443',
    'HTTPS' => '1',
], true, 'matching bracketed IPv6 authority should pass');
connect_origin_expect([
    'HTTP_ORIGIN' => 'http://other.example',
    'HTTP_HOST' => 'chim.example',
    'HTTPS' => '',
], false, 'foreign origin should fail');
connect_origin_expect([
    'HTTP_ORIGIN' => 'http://chim.example',
    'HTTP_HOST' => 'chim.example',
    'HTTPS' => '',
    'HTTP_SEC_FETCH_SITE' => 'cross-site',
], false, 'cross-site fetch metadata should fail');
foreach ([
    ['HTTP_ORIGIN' => 'http://chim.example/', 'HTTP_HOST' => 'chim.example', 'HTTPS' => ''],
    ['HTTP_ORIGIN' => 'http://user@chim.example', 'HTTP_HOST' => 'chim.example', 'HTTPS' => ''],
    ['HTTP_ORIGIN' => "http://chim.example\r\nX-Fake: yes", 'HTTP_HOST' => 'chim.example', 'HTTPS' => ''],
    ['HTTP_ORIGIN' => 'http://chim.example', 'HTTP_HOST' => 'chim.example/path', 'HTTPS' => ''],
    ['HTTP_ORIGIN' => 'http://chim.example', 'HTTP_HOST' => 'chim.example:', 'HTTPS' => ''],
    ['HTTP_ORIGIN' => 'http://chim.example', 'HTTP_HOST' => 'user@chim.example', 'HTTPS' => ''],
    ['HTTP_ORIGIN' => 'http://chim.example', 'HTTP_HOST' => '999.999.999.999', 'HTTPS' => ''],
    ['HTTP_ORIGIN' => 'http://[2001:db8::1]', 'HTTP_HOST' => '2001:db8::1', 'HTTPS' => ''],
] as $malformed) {
    connect_origin_expect($malformed, false, 'malformed Origin or Host authority should fail');
}

$fixture = connect_fixture('protocol');
$settings = $fixture['settings'];
try {
    $claimId = connect_uuid();
    $token = connect_token();
    $hash = hash('sha256', $token);
    $begin = connect_post(['action' => 'begin', 'claimId' => $claimId, 'tokenHash' => $hash], $settings, 1000);
    connect_expect($begin['status'] === 200, 'begin should create a pending claim; got ' . $begin['body']);
    $beginJson = connect_json($begin);
    connect_expect(($beginJson['state'] ?? null) === 'pending', 'begin should report pending');
    connect_expect(($beginJson['approvalPath'] ?? null) === '/ext/questionable_decisions/index.php?claim=' . strtolower($claimId), 'begin should return the fixed local approval route');
    $retry = connect_post(['action' => 'begin', 'claimId' => $claimId, 'tokenHash' => $hash], $settings, 1001);
    $retryJson = connect_json($retry);
    connect_expect(($retryJson['state'] ?? null) === 'pending', 'same claim and hash should be idempotent');
    connect_expect(($retryJson['approvalPath'] ?? null) === '/ext/questionable_decisions/index.php?claim=' . strtolower($claimId), 'pending begin retries should retain their approval path');
    $mismatch = connect_post(['action' => 'begin', 'claimId' => $claimId, 'tokenHash' => hash('sha256', connect_token())], $settings, 1002);
    connect_expect($mismatch['status'] === 409, 'claim ID reuse with a different hash should be refused');
    $status = connect_post(['action' => 'status', 'claimId' => $claimId], $settings, 1003);
    connect_expect((connect_json($status)['state'] ?? null) === 'pending', 'status should not reveal a token or change a pending claim');

    connect_expect(qd_connect_approve($settings, $claimId, 1004) === 'approved', 'explicit approval should bind the pre-generated hash');
    $statusAfterApproval = connect_post(['action' => 'status', 'claimId' => $claimId], $settings, 1005);
    $approvedStatusJson = connect_json($statusAfterApproval);
    connect_expect(($approvedStatusJson['state'] ?? null) === 'approved', 'status should reflect explicit approval');
    connect_expect(!array_key_exists('approvalPath', $approvedStatusJson), 'status responses must omit the approval path');
    $pairRaw = file_get_contents($settings['pairingPath']);
    connect_expect(is_string($pairRaw) && str_starts_with($pairRaw, QD_GUARDED_JSON_PREFIX), 'managed pair data should carry the PHP guard prefix');
    $pairConfig = json_decode(substr((string)$pairRaw, strlen(QD_GUARDED_JSON_PREFIX)), true, 8, JSON_THROW_ON_ERROR);
    connect_expect(count($pairConfig['pairs'] ?? []) === 1, 'approval should create exactly one pair');
    $pair = $pairConfig['pairs'][0];
    connect_expect($pair['tokenHash'] === $hash && $pair['scopes'] === ['compose', 'inspector', 'pcv'], 'approval should store only the native hash and default scopes');
    connect_expect(qd_authenticate($settings['pairingPath'], $settings['webRoot'], 'Bearer ' . $token, $settings['privateRoot'])['pairId'] === $pair['pairId'], 'native bearer should authenticate after approval');
    connect_expect(!str_contains((string)file_get_contents($settings['privateRoot'] . DIRECTORY_SEPARATOR . 'connect-claims.php'), $token), 'claim persistence must not store the bearer literal');

    $secondClaim = connect_uuid();
    $sameTokenBegin = connect_post(['action' => 'begin', 'claimId' => $secondClaim, 'tokenHash' => $hash], $settings, 1006);
    $sameTokenBeginJson = connect_json($sameTokenBegin);
    connect_expect(($sameTokenBeginJson['state'] ?? null) === 'approved', 'an already paired hash should be reused without a second pair');
    connect_expect(!array_key_exists('approvalPath', $sameTokenBeginJson), 'approved begin responses must omit the approval path');
    $pairsAfterReuse = json_decode(substr((string)file_get_contents($settings['pairingPath']), strlen(QD_GUARDED_JSON_PREFIX)), true, 8, JSON_THROW_ON_ERROR)['pairs'];
    connect_expect(count($pairsAfterReuse) === 1 && $pairsAfterReuse[0]['pairId'] === $pair['pairId'], 'approval retry must retain the exact pair identity');

    qd_pair_revoke($settings['pairingPath'], $settings['webRoot'], $pair['pairId'], $settings['privateRoot']);
    $revokedClaim = connect_uuid();
    $revokedBegin = connect_post(['action' => 'begin', 'claimId' => $revokedClaim, 'tokenHash' => $hash], $settings, 1007);
    $revokedBeginJson = connect_json($revokedBegin);
    connect_expect(($revokedBeginJson['state'] ?? null) === 'expired', 'a revoked hash must not be silently restored');
    connect_expect(!array_key_exists('approvalPath', $revokedBeginJson), 'expired begin responses must omit the approval path');
    connect_expect(qd_connect_approve($settings, $revokedClaim, 1008) === 'expired', 'a revoked hash must remain refused on approval');
    $revokedConfig = json_decode(substr((string)file_get_contents($settings['pairingPath']), strlen(QD_GUARDED_JSON_PREFIX)), true, 8, JSON_THROW_ON_ERROR);
    connect_expect(count($revokedConfig['pairs']) === 1 && $revokedConfig['pairs'][0]['revoked'] === true, 'revocation tombstone must remain intact');

    $badMethod = qd_connect_handle('GET', null, '', $settings, 1009);
    connect_expect($badMethod['status'] === 405 && ($badMethod['headers']['Allow'] ?? null) === 'POST', 'connect route must be POST-only');
    $badType = qd_connect_handle('POST', 'text/plain', '{}', $settings, 1010);
    connect_expect($badType['status'] === 415, 'connect route must require JSON');
} finally {
    connect_remove_tree($fixture['root']);
}

$expiryFixture = connect_fixture('expiry');
try {
    $claimId = connect_uuid();
    $expiredToken = connect_token();
    $expiredHash = hash('sha256', $expiredToken);
    connect_post(['action' => 'begin', 'claimId' => $claimId, 'tokenHash' => $expiredHash], $expiryFixture['settings'], 2000);
    $expiredStatus = connect_post(['action' => 'status', 'claimId' => $claimId], $expiryFixture['settings'], 2600);
    $expiredStatusJson = connect_json($expiredStatus);
    connect_expect(($expiredStatusJson['state'] ?? null) === 'expired', 'claims must expire at ten minutes');
    connect_expect(!array_key_exists('approvalPath', $expiredStatusJson), 'expired status responses must omit the approval path');
    connect_expect(qd_connect_approve($expiryFixture['settings'], $claimId, 2601) === 'expired', 'expired claims must not create a pair');
    connect_expect(!file_exists($expiryFixture['settings']['pairingPath']), 'expired claims must not issue authorization');
} finally {
    connect_remove_tree($expiryFixture['root']);
}

$capacityFixture = connect_fixture('capacity');
try {
    for ($index = 0; $index < QD_CONNECT_PENDING_LIMIT; $index++) {
        $response = connect_post([
            'action' => 'begin',
            'claimId' => connect_uuid(),
            'tokenHash' => hash('sha256', connect_token()),
        ], $capacityFixture['settings'], 3000);
        connect_expect($response['status'] === 200 && (connect_json($response)['state'] ?? null) === 'pending', 'pending claims should fit the configured cap');
    }
    $overCapacity = connect_post([
        'action' => 'begin',
        'claimId' => connect_uuid(),
        'tokenHash' => hash('sha256', connect_token()),
    ], $capacityFixture['settings'], 3001);
    connect_expect($overCapacity['status'] === 503, 'pending claims beyond the cap must fail closed');
} finally {
    connect_remove_tree($capacityFixture['root']);
}

$pathFixture = connect_fixture('paths');
$hadDocumentRoot = array_key_exists('DOCUMENT_ROOT', $_SERVER);
$originalDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
try {
    unset($_SERVER['DOCUMENT_ROOT']);
    $coreRoot = $pathFixture['root'] . DIRECTORY_SEPARATOR . 'chim';
    $coreLib = $coreRoot . DIRECTORY_SEPARATOR . 'lib';
    $extensions = $coreRoot . DIRECTORY_SEPARATOR . 'ext';
    if (!mkdir($coreLib, 0700, true) || !mkdir($extensions . DIRECTORY_SEPARATOR . 'private_conversation', 0700, true)
        || !mkdir($coreRoot . DIRECTORY_SEPARATOR . 'data', 0700, true)) {
        throw new RuntimeException('Could not create the synthetic CHIM installation.');
    }
    file_put_contents($coreLib . DIRECTORY_SEPARATOR . 'playthrough_preferences.php', "<?php\n");
    putenv('QD_CHIM_ROOT=' . $coreRoot);
    putenv('QD_PAIRING_FILE');
    putenv('QD_SERVER_STATE_FILE');
    putenv('QD_WEB_ROOT');
    putenv('QD_PCV_ROOT');
    $defaults = qd_api_settings_from_environment();
    $privateRoot = realpath($coreRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'questionable_decisions-private');
    connect_expect(is_string($privateRoot), 'default settings should create a durable core-data private root');
    connect_expect($defaults['pairingPath'] === $privateRoot . DIRECTORY_SEPARATOR . 'pairing.php', 'pairing should default to a guarded data-root file');
    connect_expect($defaults['statePath'] === $privateRoot . DIRECTORY_SEPARATOR . 'state.php', 'server state should default to a guarded data-root file');
    connect_expect($defaults['privateRoot'] === $privateRoot && $defaults['chimRoot'] === realpath($coreRoot), 'settings should expose the canonical installation and private roots');
    connect_expect($defaults['webRoot'] === realpath($coreRoot) && $defaults['pcvRoot'] === realpath($extensions . DIRECTORY_SEPARATOR . 'private_conversation'), 'web and owner paths should derive from the installed layout');

    qd_server_state_transaction($defaults, static function (array &$state): bool {
        $state['contexts']['test'] = ['contextHash' => hash('sha256', 'test')];
        return true;
    });
    $guardedState = file_get_contents($defaults['statePath']);
    connect_expect(is_string($guardedState) && str_starts_with($guardedState, QD_GUARDED_JSON_PREFIX), 'default server state must be PHP guarded');

    $legacyRoot = $pathFixture['root'] . DIRECTORY_SEPARATOR . 'legacy';
    if (!mkdir($legacyRoot, 0700)) {
        throw new RuntimeException('Could not create the legacy external path.');
    }
    $legacyPairing = $legacyRoot . DIRECTORY_SEPARATOR . 'pairing.json';
    $legacyPair = qd_pair_issue($legacyPairing, $defaults['webRoot'], ['pcv'], null, $defaults['coreRoot']);
    $legacyBytes = file_get_contents($legacyPairing);
    connect_expect(is_string($legacyBytes) && !str_starts_with($legacyBytes, QD_GUARDED_JSON_PREFIX), 'explicit external pairing files must retain the legacy JSON format');
    connect_expect(qd_authenticate($legacyPairing, $defaults['webRoot'], 'Bearer ' . $legacyPair['token'], null, $defaults['coreRoot'])['pairId'] === $legacyPair['pairId'], 'legacy external pair files must remain readable');

    $documentRootPath = $pathFixture['root'] . DIRECTORY_SEPARATOR . 'document-root';
    if (!mkdir($documentRootPath, 0700)) {
        throw new RuntimeException('Could not create the synthetic document root.');
    }
    $_SERVER['DOCUMENT_ROOT'] = $documentRootPath;
    $webDefaults = qd_api_settings_from_environment();
    connect_expect($webDefaults['webRoot'] === realpath($documentRootPath), 'web settings should prefer canonical PHP DOCUMENT_ROOT');
    connect_expect($webDefaults['coreRoot'] === realpath($coreRoot), 'web settings should retain the canonical physical CHIM root');
    $publicSiblingJson = $documentRootPath . DIRECTORY_SEPARATOR . 'sibling.json';
    connect_expect_unavailable(static fn(): array => qd_pair_issue(
        $publicSiblingJson,
        $webDefaults['webRoot'],
        ['pcv'],
        $webDefaults['privateRoot'],
        $webDefaults['coreRoot'],
    ), 'external JSON beneath DOCUMENT_ROOT must be refused');
    $chimRootJson = $coreRoot . DIRECTORY_SEPARATOR . 'legacy.json';
    connect_expect_unavailable(static fn(): array => qd_pair_issue(
        $chimRootJson,
        $webDefaults['webRoot'],
        ['pcv'],
        $webDefaults['privateRoot'],
        $webDefaults['coreRoot'],
    ), 'external JSON beneath the physical CHIM root must be refused even outside DOCUMENT_ROOT');
    $docrootLegacyPair = qd_pair_issue(
        $legacyRoot . DIRECTORY_SEPARATOR . 'outside-both.json',
        $webDefaults['webRoot'],
        ['pcv'],
        $webDefaults['privateRoot'],
        $webDefaults['coreRoot'],
    );
    connect_expect(qd_authenticate(
        $legacyRoot . DIRECTORY_SEPARATOR . 'outside-both.json',
        $webDefaults['webRoot'],
        'Bearer ' . $docrootLegacyPair['token'],
        $webDefaults['privateRoot'],
        $webDefaults['coreRoot'],
    )['pairId'] === $docrootLegacyPair['pairId'], 'legacy external JSON outside both protected roots must remain readable');
} finally {
    if ($hadDocumentRoot) {
        $_SERVER['DOCUMENT_ROOT'] = $originalDocumentRoot;
    } else {
        unset($_SERVER['DOCUMENT_ROOT']);
    }
    putenv('QD_CHIM_ROOT');
    putenv('QD_PAIRING_FILE');
    putenv('QD_SERVER_STATE_FILE');
    putenv('QD_WEB_ROOT');
    putenv('QD_PCV_ROOT');
    connect_remove_tree($pathFixture['root']);
}

$pageFixture = connect_fixture('page-approval');
$pageRoot = $pageFixture['root'] . DIRECTORY_SEPARATOR . 'chim';
$pageWebRoot = $pageFixture['root'] . DIRECTORY_SEPARATOR . 'document-root';
$oldServer = $_SERVER;
$oldSessionPath = session_save_path();
$oldEnv = [];
foreach (['QD_CHIM_ROOT', 'QD_PAIRING_FILE', 'QD_SERVER_STATE_FILE', 'QD_WEB_ROOT', 'QD_PCV_ROOT'] as $name) {
    $value = getenv($name);
    $oldEnv[$name] = is_string($value) ? $value : false;
}
try {
    if (!mkdir($pageRoot . DIRECTORY_SEPARATOR . 'lib', 0700, true)
        || !mkdir($pageRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation', 0700, true)
        || !mkdir($pageRoot . DIRECTORY_SEPARATOR . 'data', 0700, true)
        || !mkdir($pageWebRoot, 0700, true)
        || !mkdir($pageFixture['root'] . DIRECTORY_SEPARATOR . 'sessions', 0700, true)) {
        throw new RuntimeException('Could not create the synthetic approval-page layout.');
    }
    file_put_contents($pageRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_preferences.php', "<?php\n");
    putenv('QD_CHIM_ROOT=' . $pageRoot);
    putenv('QD_PAIRING_FILE');
    putenv('QD_SERVER_STATE_FILE');
    putenv('QD_WEB_ROOT');
    putenv('QD_PCV_ROOT');
    $_SERVER['DOCUMENT_ROOT'] = $pageWebRoot;
    $pageSettings = qd_api_settings_from_environment();
    $pageClaimId = connect_uuid();
    $pageToken = connect_token();
    $pageHash = hash('sha256', $pageToken);
    connect_expect(qd_connect_begin($pageSettings, $pageClaimId, $pageHash, time())['state'] === 'pending', 'page test should start from a real pending claim');

    session_save_path($pageFixture['root'] . DIRECTORY_SEPARATOR . 'sessions');
    $sessionId = 'qdpage' . bin2hex(random_bytes(12));
    $oldCsrf = bin2hex(random_bytes(32));
    session_id($sessionId);
    if (!session_start()) {
        throw new RuntimeException('Could not create the synthetic browser session.');
    }
    $_SESSION['qd_connect_csrf'] = $oldCsrf;
    session_write_close();

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ORIGIN'] = 'http://chim.example';
    $_SERVER['HTTP_HOST'] = 'chim.example';
    $_SERVER['HTTPS'] = '';
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
    $_POST = ['claim' => $pageClaimId, 'csrf' => $oldCsrf, 'decision' => 'approve'];
    ob_start();
    qd_onboarding_emit();
    $approvalHtml = (string)ob_get_clean();
    connect_expect(str_contains($approvalHtml, 'Game connected'), 'successful approval should render the connected page');
    connect_expect(str_contains($approvalHtml, 'Private Conversation is optional'), 'successful approval should explain optional setup');
    connect_expect(str_contains($approvalHtml, 'return to Skyrim and choose Check connection'), 'successful approval should show the game next step');
    connect_expect(str_contains($approvalHtml, 'name="decision" value="enable_pcv"'), 'successful approval should keep the optional setup form visible');
    connect_expect(preg_match('/name="csrf" value="([a-f0-9]{64})"/', $approvalHtml, $csrfMatch) === 1, 'successful approval setup form should include a CSRF token');
    $nextCsrf = $csrfMatch[1];
    connect_expect(!hash_equals($oldCsrf, $nextCsrf), 'successful approval should rotate the CSRF token');
    session_id($sessionId);
    if (!session_start()) {
        throw new RuntimeException('Could not reload the rotated browser session.');
    }
    connect_expect(hash_equals($nextCsrf, (string)($_SESSION['qd_connect_csrf'] ?? '')), 'setup form CSRF should match the rotated session token');
    session_write_close();
    $approvedPair = qd_authenticate(
        $pageSettings['pairingPath'],
        $pageSettings['webRoot'],
        'Bearer ' . $pageToken,
        $pageSettings['privateRoot'],
        $pageSettings['coreRoot'],
    );
    connect_expect(is_array($approvedPair) && $approvedPair['scopes'] === ['compose', 'inspector', 'pcv'], 'page approval should authorize the game token with the expected scopes');
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_save_path($oldSessionPath);
    foreach ($oldEnv as $name => $value) {
        putenv($value === false ? $name : $name . '=' . $value);
    }
    $_SERVER = $oldServer;
    connect_remove_tree($pageFixture['root']);
}

echo 'PASS onboarding connect checks=' . $checks . PHP_EOL;
