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

function connect_stock_event(string $claimId, string $tokenHash): array
{
    return ['physics_raw', '1740000000', '123', 'dql_connect_v1:' . strtolower($claimId) . ':' . $tokenHash];
}

function connect_native_headers(array $overrides = []): array
{
    return array_merge([
        'REQUEST_METHOD' => 'GET',
        'HTTP_X_CHIM_GENERATION' => '0',
    ], $overrides);
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
    connect_expect(array_keys($beginJson) === ['ok', 'state', 'claimId'], 'begin should expose only the fixed state and claim ID');
    $retry = connect_post(['action' => 'begin', 'claimId' => $claimId, 'tokenHash' => $hash], $settings, 1001);
    $retryJson = connect_json($retry);
    connect_expect(($retryJson['state'] ?? null) === 'pending', 'same claim and hash should be idempotent');
    connect_expect(array_keys($retryJson) === ['ok', 'state', 'claimId'], 'begin retries should not expose a browser approval path');
    $mismatch = connect_post(['action' => 'begin', 'claimId' => $claimId, 'tokenHash' => hash('sha256', connect_token())], $settings, 1002);
    connect_expect($mismatch['status'] === 409, 'claim ID reuse with a different hash should be refused');
    $status = connect_post(['action' => 'status', 'claimId' => $claimId], $settings, 1003);
    connect_expect((connect_json($status)['state'] ?? null) === 'pending', 'status should not reveal a token or change a pending claim');

    $packet = connect_stock_event($claimId, $hash);
    connect_expect(qd_connect_handle_stock_event($settings, ['infonpc', '1740000000', '123', 'nearby actors'], connect_native_headers(), 1003) === null,
        'the native logMessage preamble should not match the reserved approval packet');
    connect_expect(qd_connect_handle_stock_event($settings, connect_stock_event($claimId, hash('sha256', connect_token())), connect_native_headers(), 1004) === 'expired',
        'a packet with a mismatched token hash must not approve the pending claim');
    connect_expect(!file_exists($settings['pairingPath']), 'a mismatched packet must not create a pair');
    foreach ([
        ['REQUEST_METHOD' => 'POST'],
        ['HTTP_X_CHIM_GENERATION' => ''],
        ['HTTP_X_CHIM_GENERATION' => '1e3'],
        ['HTTP_X_CHIM_GENERATION' => str_repeat('1', 21)],
        ['HTTP_ORIGIN' => ''],
        ['HTTP_SEC_FETCH_MODE' => 'cors'],
        ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
    ] as $rejectedHeaders) {
        connect_expect(qd_connect_handle_stock_event($settings, $packet, connect_native_headers($rejectedHeaders), 1004) === 'expired',
            'browser-shaped or invalid-generation stock packets must be refused');
    }
    foreach ([
        ['physics_raw', '1740000000', '123.000000', 'dql_connect_v1:bad:' . $hash],
        ['physics_raw', '1740000000', '123', 'dql_connect_v1:' . strtoupper($claimId) . ':' . $hash],
        ['physics_raw', '1740000000', '123', 'dql_connect_v1:' . strtolower($claimId) . ':' . strtoupper($hash)],
        ['physics_raw', '1740000000', 'not-a-time', 'dql_connect_v1:' . strtolower($claimId) . ':' . $hash],
        ['physics_raw', '1740000000', '123', 'dql_connect_v1:' . strtolower($claimId) . ':' . $hash, 'extra'],
    ] as $malformedPacket) {
        connect_expect(qd_connect_handle_stock_event($settings, $malformedPacket, connect_native_headers(), 1004) === 'expired',
            'malformed reserved packets must be consumed without approval');
    }
    connect_expect(qd_connect_handle_stock_event($settings, ['request', '1740000000', '123', $packet[3]], connect_native_headers(), 1004) === null,
        'the reserved packet must only be recognized on physics_raw');
    $directApproval = connect_post(['action' => 'approve', 'claimId' => $claimId, 'tokenHash' => $hash], $settings, 1004);
    connect_expect($directApproval['status'] === 400 && !file_exists($settings['pairingPath']), 'the HTTP route must not expose direct approval');
    connect_expect(qd_connect_handle_stock_event($settings, $packet, connect_native_headers(), 1004) === 'approved',
        'the exact stock physics_raw packet should approve the matching pending claim');
    $statusAfterApproval = connect_post(['action' => 'status', 'claimId' => $claimId], $settings, 1005);
    $approvedStatusJson = connect_json($statusAfterApproval);
    connect_expect(($approvedStatusJson['state'] ?? null) === 'approved', 'status should reflect explicit approval');
    connect_expect(array_keys($approvedStatusJson) === ['ok', 'state', 'claimId'], 'status should expose only the fixed state and claim ID');
    $pairRaw = file_get_contents($settings['pairingPath']);
    connect_expect(is_string($pairRaw) && str_starts_with($pairRaw, QD_GUARDED_JSON_PREFIX), 'managed pair data should carry the PHP guard prefix');
    $pairConfig = json_decode(substr((string)$pairRaw, strlen(QD_GUARDED_JSON_PREFIX)), true, 8, JSON_THROW_ON_ERROR);
    connect_expect(count($pairConfig['pairs'] ?? []) === 1, 'the native packet should create exactly one pair');
    $pair = $pairConfig['pairs'][0];
    connect_expect($pair['tokenHash'] === $hash && $pair['scopes'] === ['compose', 'inspector', 'pcv'], 'approval should store only the native hash and default scopes');
    connect_expect(qd_authenticate($settings['pairingPath'], $settings['webRoot'], 'Bearer ' . $token, $settings['privateRoot'])['pairId'] === $pair['pairId'], 'native bearer should authenticate after approval');
    connect_expect(!str_contains((string)file_get_contents($settings['privateRoot'] . DIRECTORY_SEPARATOR . 'connect-claims.php'), $token), 'claim persistence must not store the bearer literal');
    connect_expect(qd_connect_handle_stock_event($settings, $packet, connect_native_headers(), 1005) === 'approved', 'replaying the native packet should be idempotently approved');

    $secondClaim = connect_uuid();
    $sameTokenBegin = connect_post(['action' => 'begin', 'claimId' => $secondClaim, 'tokenHash' => $hash], $settings, 1006);
    $sameTokenBeginJson = connect_json($sameTokenBegin);
    connect_expect(($sameTokenBeginJson['state'] ?? null) === 'approved', 'an already paired hash should be reused without a second pair');
    connect_expect(array_keys($sameTokenBeginJson) === ['ok', 'state', 'claimId'], 'approved begin responses should keep the fixed shape');
    $pairsAfterReuse = json_decode(substr((string)file_get_contents($settings['pairingPath']), strlen(QD_GUARDED_JSON_PREFIX)), true, 8, JSON_THROW_ON_ERROR)['pairs'];
    connect_expect(count($pairsAfterReuse) === 1 && $pairsAfterReuse[0]['pairId'] === $pair['pairId'], 'approval retry must retain the exact pair identity');

    qd_pair_revoke($settings['pairingPath'], $settings['webRoot'], $pair['pairId'], $settings['privateRoot']);
    $revokedClaim = connect_uuid();
    $revokedBegin = connect_post(['action' => 'begin', 'claimId' => $revokedClaim, 'tokenHash' => $hash], $settings, 1007);
    $revokedBeginJson = connect_json($revokedBegin);
    connect_expect(($revokedBeginJson['state'] ?? null) === 'expired', 'a revoked hash must not be silently restored');
    connect_expect(array_keys($revokedBeginJson) === ['ok', 'state', 'claimId'], 'expired begin responses should keep the fixed shape');
    connect_expect(qd_connect_handle_stock_event($settings, connect_stock_event($revokedClaim, $hash), connect_native_headers(), 1008) === 'expired', 'a revoked hash must remain refused on stock ingress');
    $revokedConfig = json_decode(substr((string)file_get_contents($settings['pairingPath']), strlen(QD_GUARDED_JSON_PREFIX)), true, 8, JSON_THROW_ON_ERROR);
    connect_expect(count($revokedConfig['pairs']) === 1 && $revokedConfig['pairs'][0]['revoked'] === true, 'revocation tombstone must remain intact');
    $staleRestoredConfig = $revokedConfig;
    $staleRestoredConfig['pairs'][0]['revoked'] = false;
    qd_atomic_write($settings['pairingPath'], json_encode($staleRestoredConfig, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true);
    $staleRestoredPairing = file_get_contents($settings['pairingPath']);
    connect_expect(qd_connect_handle_stock_event($settings, connect_stock_event($revokedClaim, $hash), connect_native_headers(), 1009) === 'expired',
        'a terminal revoked claim must not become approved if an older pairing snapshot is restored');
    connect_expect(file_get_contents($settings['pairingPath']) === $staleRestoredPairing,
        'a packet for a terminal revoked claim must not rewrite pairing state');
    $revokedRetry = connect_json(connect_post(['action' => 'begin', 'claimId' => $revokedClaim, 'tokenHash' => $hash], $settings, 1010));
    $revokedStatus = connect_json(connect_post(['action' => 'status', 'claimId' => $revokedClaim], $settings, 1011));
    connect_expect(($revokedRetry['state'] ?? null) === 'expired' && ($revokedStatus['state'] ?? null) === 'expired',
        'same-claim begin and status retries must preserve terminal revocation after an older pairing snapshot is restored');
    $claimStore = json_decode(substr((string)file_get_contents($settings['privateRoot'] . DIRECTORY_SEPARATOR . 'connect-claims.php'), strlen(QD_GUARDED_JSON_PREFIX)), true, 16, JSON_THROW_ON_ERROR);
    $revokedRecord = array_values(array_filter($claimStore['claims'], static fn(array $claim): bool => $claim['claimId'] === strtolower($revokedClaim)))[0] ?? null;
    connect_expect(is_array($revokedRecord) && $revokedRecord['state'] === 'revoked',
        'the durable claim tombstone must remain revoked after the stock packet retry');

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
    connect_expect(array_keys($expiredStatusJson) === ['ok', 'state', 'claimId'], 'expired status responses should keep the fixed shape');
    connect_expect(qd_connect_handle_stock_event($expiryFixture['settings'], connect_stock_event($claimId, $expiredHash), connect_native_headers(), 2601) === 'expired', 'expired claims must not create a pair');
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

$pageFixture = connect_fixture('page');
$oldServer = $_SERVER;
try {
    $pageSettings = $pageFixture['settings'];
    $pagePair = qd_pair_issue($pageSettings['pairingPath'], $pageSettings['webRoot'], ['compose'], $pageSettings['privateRoot'], $pageSettings['chimRoot']);
    $pairingBeforePagePost = file_get_contents($pageSettings['pairingPath']);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    qd_onboarding_emit();
    $page = (string)ob_get_clean();
    connect_expect(str_contains($page, 'Open the Department in Skyrim; setup runs automatically.'), 'plugin page should direct setup to Skyrim');
    connect_expect(str_contains($page, 'This page does not approve or create a connection.'), 'plugin page should state that it cannot approve a connection');
    connect_expect(!str_contains(strtolower($page), '<form'), 'plugin page must not expose a browser approval or setup form');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['decision' => 'approve', 'claim' => connect_uuid()];
    ob_start();
    qd_onboarding_emit();
    $postPage = (string)ob_get_clean();
    connect_expect($postPage === $page, 'browser POST data must not alter the informational page or approve a connection');
    connect_expect(file_get_contents($pageSettings['pairingPath']) === $pairingBeforePagePost,
        'POSTing the informational page must leave the guarded pairing file byte-for-byte unchanged');
    connect_expect(qd_authenticate($pageSettings['pairingPath'], $pageSettings['webRoot'], 'Bearer ' . $pagePair['token'], $pageSettings['privateRoot'], $pageSettings['chimRoot'])['pairId'] === $pagePair['pairId'],
        'The informational page POST must preserve the existing pair identity and authorization state');
} finally {
    $_SERVER = $oldServer;
    connect_remove_tree($pageFixture['root']);
}

echo 'PASS onboarding connect checks=' . $checks . PHP_EOL;
