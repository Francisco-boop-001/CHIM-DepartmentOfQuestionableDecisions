<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/protocol.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/connect.php';
require_once __DIR__ . '/../../api.php';

$checks = 0;
final class StockIngressTerminated extends RuntimeException {}
function terminate(): void
{
    $GLOBALS['stock_ingress_terminated_audit_request'] = $GLOBALS['AUDIT_RUNID_REQUEST'] ?? null;
    throw new StockIngressTerminated();
}

function stock_ingress_expect(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

function stock_ingress_remove_tree(string $path): void
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
            stock_ingress_remove_tree($item->getPathname());
        }
    }
    @rmdir($path);
}

function stock_ingress_event(string $claimId, string $tokenHash): array
{
    return ['physics_raw', '1740000000', '123456', 'dql_connect_v1:' . strtolower($claimId) . ':' . $tokenHash];
}

function stock_ingress_headers(array $overrides = []): array
{
    return array_merge(['REQUEST_METHOD' => 'GET', 'HTTP_X_CHIM_GENERATION' => '0'], $overrides);
}

function stock_ingress_post(array $request, array $settings, int $now): array
{
    return qd_connect_handle('POST', 'application/json', json_encode($request, JSON_THROW_ON_ERROR), $settings, $now);
}

function stock_ingress_json(array $response): array
{
    return json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
}

function stock_ingress_run_hook(array $event, array $headers): array
{
    $_SERVER = array_merge($_SERVER, $headers);
    $GLOBALS['gameRequest'] = $event;
    include __DIR__ . '/../../preprocessing.php';
    $result = $GLOBALS['gameRequest'];
    unset($GLOBALS['gameRequest']);
    return $result;
}

function stock_ingress_run_early_hook(string $rawQuery, array $headers): bool
{
    $query = 'host-query-sentinel';
    $encoded = 'host-encoded-sentinel';
    $decoded = 'host-decoded-sentinel';
    $request = 'host-request-sentinel';
    unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CHIM_GENERATION'], $_SERVER['HTTP_ORIGIN']);
    foreach (array_keys($_SERVER) as $name) {
        if (is_string($name) && str_starts_with(strtoupper($name), 'HTTP_SEC_FETCH_')) {
            unset($_SERVER[$name]);
        }
    }
    $_SERVER = array_merge($_SERVER, $headers, ['QUERY_STRING' => $rawQuery]);
    $terminated = false;
    try {
        include __DIR__ . '/../../globals.php';
    } catch (StockIngressTerminated) {
        $terminated = true;
    }
    stock_ingress_expect($query === 'host-query-sentinel'
        && $encoded === 'host-encoded-sentinel'
        && $decoded === 'host-decoded-sentinel'
        && $request === 'host-request-sentinel',
        'The early globals hook must not overwrite host include-scope variables.');
    return $terminated;
}

function stock_ingress_run_sharmat_collision(array $event, array $headers): string
{
    $_SERVER = array_merge($_SERVER, $headers);
    $GLOBALS['gameRequest'] = $event;
    $parts = explode('^', (string)($GLOBALS['gameRequest'][3] ?? ''));
    if (trim((string)($parts[0] ?? '')) === '' || count($parts) < 4) {
        $GLOBALS['gameRequest'][0] = 'nsfw_blocked_blank_input';
    } else {
        $GLOBALS['gameRequest'][0] = 'ext_nsfw_physics_raw';
    }
    include __DIR__ . '/../../preprocessing.php';
    $type = (string)($GLOBALS['gameRequest'][0] ?? '');
    unset($GLOBALS['gameRequest']);
    return $type;
}

$fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-stock-ingress-' . bin2hex(random_bytes(8));
$chimRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'HerikaServer';
$webRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'www';
$ingressLog = $fixtureRoot . DIRECTORY_SEPARATOR . 'ingress.log';
$oldServer = $_SERVER;
$oldGet = $_GET;
$hadAuditRequest = array_key_exists('AUDIT_RUNID_REQUEST', $GLOBALS);
$oldAuditRequest = $GLOBALS['AUDIT_RUNID_REQUEST'] ?? null;
$hadTerminatedAuditRequest = array_key_exists('stock_ingress_terminated_audit_request', $GLOBALS);
$oldTerminatedAuditRequest = $GLOBALS['stock_ingress_terminated_audit_request'] ?? null;
$oldErrorLog = ini_get('error_log');
$oldEnv = [];
foreach (['QD_CHIM_ROOT', 'QD_PAIRING_FILE', 'QD_SERVER_STATE_FILE', 'QD_WEB_ROOT', 'QD_PCV_ROOT'] as $name) {
    $value = getenv($name);
    $oldEnv[$name] = is_string($value) ? $value : false;
}

try {
    foreach ([$chimRoot . DIRECTORY_SEPARATOR . 'lib', $chimRoot . DIRECTORY_SEPARATOR . 'data', $chimRoot . DIRECTORY_SEPARATOR . 'ext', $webRoot] as $directory) {
        stock_ingress_expect(@mkdir($directory, 0700, true), 'Could not create the isolated CHIM hook fixture.');
    }
    file_put_contents($chimRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_preferences.php', "<?php\n");
    putenv('QD_CHIM_ROOT=' . $chimRoot);
    putenv('QD_PAIRING_FILE');
    putenv('QD_SERVER_STATE_FILE');
    putenv('QD_WEB_ROOT');
    putenv('QD_PCV_ROOT');
    $_SERVER['DOCUMENT_ROOT'] = $webRoot;
    ini_set('error_log', $ingressLog);
    $settings = qd_api_settings_from_environment();
    stock_ingress_expect($settings['coreRoot'] === realpath($chimRoot), 'The hook fixture must resolve the same physical CHIM root as the installed plugin.');

    $claimId = qd_new_uuid();
    $bearer = qd_new_token();
    $tokenHash = hash('sha256', $bearer);
    $testNow = time();
    $begin = stock_ingress_post(['action' => 'begin', 'claimId' => $claimId, 'tokenHash' => $tokenHash], $settings, $testNow);
    stock_ingress_expect(($begin['status'] ?? null) === 200 && (stock_ingress_json($begin)['state'] ?? null) === 'pending', 'The real connect route should persist a pending native claim.');

    $preamble = ['infonpc', '1740000000', '123456', '(beings in range: none)'];
    stock_ingress_expect(qd_connect_handle_stock_event($settings, $preamble, stock_ingress_headers(), $testNow + 1) === null,
        'The stock logMessage preamble must not approve a claim.');

    $packet = stock_ingress_event($claimId, $tokenHash);
    $malformedPackets = [
        ['physics_raw', '1740000000', '123456.0', $packet[3]],
        ['physics_raw', 'not-a-time', '123456', $packet[3]],
        ['physics_raw', '1740000000', '123456', 'dql_connect_v1:bad:' . $tokenHash],
        ['physics_raw', '1740000000', '123456', 'dql_connect_v1:' . strtoupper($claimId) . ':' . $tokenHash],
        ['physics_raw', '1740000000', '123456', 'dql_connect_v1:' . strtolower($claimId) . ':' . strtoupper($tokenHash)],
        array_merge($packet, ['extra']),
    ];
    foreach ($malformedPackets as $malformed) {
        stock_ingress_expect(qd_connect_handle_stock_event($settings, $malformed, stock_ingress_headers(), $testNow + 1) === 'expired',
            'Malformed reserved physics_raw packets must be consumed without approval.');
    }
    foreach ([
        ['REQUEST_METHOD' => 'POST'],
        ['HTTP_X_CHIM_GENERATION' => ''],
        ['HTTP_X_CHIM_GENERATION' => '1e3'],
        ['HTTP_X_CHIM_GENERATION' => str_repeat('1', 21)],
        ['HTTP_ORIGIN' => ''],
        ['HTTP_SEC_FETCH_MODE' => 'cors'],
        ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
    ] as $browserOrInvalidHeaders) {
        stock_ingress_expect(qd_connect_handle_stock_event($settings, $packet, stock_ingress_headers($browserOrInvalidHeaders), $testNow + 1) === 'expired',
            'POST, browser headers, and invalid CHIM generations must be refused.');
    }
    stock_ingress_expect(qd_connect_handle_stock_event($settings, ['request', ...array_slice($packet, 1)], stock_ingress_headers(), $testNow + 1) === null,
        'The approval packet must only be recognized on physics_raw.');
    stock_ingress_expect((stock_ingress_json(stock_ingress_post(['action' => 'status', 'claimId' => $claimId], $settings, $testNow + 2))['state'] ?? null) === 'pending',
        'Refused packets must leave the pending claim unchanged.');

    $collisionClaimId = $claimId;
    $collisionHash = $tokenHash;
    $collisionPacket = $packet;
    $collisionEncoded = base64_encode(implode('|', $collisionPacket));
    $GLOBALS['AUDIT_RUNID_REQUEST'] = 'existing-audit-request';
    foreach ([
        ['physics_raw', '1740000000', '123456', 'ordinary physics payload'],
        ['inputtext', '1740000000', '123456', 'ordinary request'],
    ] as $ordinaryEvent) {
        $ordinaryEncoded = base64_encode(implode('|', $ordinaryEvent));
        stock_ingress_expect(!stock_ingress_run_early_hook('DATA=' . $ordinaryEncoded . '&profile=TEST', stock_ingress_headers()),
            'The early hook must leave ordinary physics and nonreserved events to CHIM.');
    }
    stock_ingress_expect($GLOBALS['AUDIT_RUNID_REQUEST'] === 'existing-audit-request',
        'Ordinary events must preserve a pre-existing audit request label.');
    stock_ingress_expect(!file_exists($ingressLog), 'Ordinary events must not produce connection-ingress diagnostics.');
    stock_ingress_expect((stock_ingress_json(stock_ingress_post(['action' => 'status', 'claimId' => $collisionClaimId], $settings, $testNow + 1))['state'] ?? null) === 'pending',
        'Ordinary events must not change a pending connection claim.');

    stock_ingress_expect(stock_ingress_run_sharmat_collision($collisionPacket, stock_ingress_headers()) === 'nsfw_blocked_blank_input',
        'SHARMAT’s malformed-contact guard should rewrite the reserved packet before the legacy Department hook.');
    stock_ingress_expect((stock_ingress_json(stock_ingress_post(['action' => 'status', 'claimId' => $collisionClaimId], $settings, $testNow + 1))['state'] ?? null) === 'pending',
        'The legacy preprocessing route should miss the claim after SHARMAT rewrites physics_raw.');

    $collisionQuery = 'DATA=' . $collisionEncoded . '&profile=TEST';
    stock_ingress_expect(stock_ingress_run_early_hook($collisionQuery, stock_ingress_headers(['HTTP_ORIGIN' => 'https://example.invalid'])),
        'A browser-origin reserved packet must be consumed before later hooks.');
    stock_ingress_expect((stock_ingress_json(stock_ingress_post(['action' => 'status', 'claimId' => $collisionClaimId], $settings, $testNow + 1))['state'] ?? null) === 'pending',
        'A browser-origin reserved packet must not approve a pending claim.');

    unset($_SERVER['HTTP_X_CHIM_GENERATION']);
    stock_ingress_expect(stock_ingress_run_early_hook($collisionQuery, ['REQUEST_METHOD' => 'GET']),
        'A reserved packet without a generation header must be consumed before later hooks.');
    stock_ingress_expect((stock_ingress_json(stock_ingress_post(['action' => 'status', 'claimId' => $collisionClaimId], $settings, $testNow + 1))['state'] ?? null) === 'pending',
        'A reserved packet without a generation header must not approve a pending claim.');

    $malformedQuad = base64_encode(implode('|', [...$collisionPacket, 'extra']));
    stock_ingress_expect(stock_ingress_run_early_hook('DATA=' . $malformedQuad . '&profile=TEST', stock_ingress_headers()),
        'A malformed reserved quad must be consumed before later hooks.');
    stock_ingress_expect((stock_ingress_json(stock_ingress_post(['action' => 'status', 'claimId' => $collisionClaimId], $settings, $testNow + 1))['state'] ?? null) === 'pending',
        'A malformed reserved quad must not approve a pending claim.');

    $unknownClaimPacket = stock_ingress_event(qd_new_uuid(), $collisionHash);
    stock_ingress_expect(stock_ingress_run_early_hook('DATA=' . base64_encode(implode('|', $unknownClaimPacket)) . '&profile=TEST', stock_ingress_headers()),
        'A reserved packet for an unknown claim must be consumed before later hooks.');
    stock_ingress_expect((stock_ingress_json(stock_ingress_post(['action' => 'status', 'claimId' => $collisionClaimId], $settings, $testNow + 1))['state'] ?? null) === 'pending',
        'A packet for an unknown claim must not approve a different pending claim.');

    $_GET['DATA'] = 'corrupted by PHP query parsing';
    stock_ingress_expect(stock_ingress_run_early_hook($collisionQuery, stock_ingress_headers()),
        'A reserved native packet must terminate in the early globals hook.');
    stock_ingress_expect(($GLOBALS['stock_ingress_terminated_audit_request'] ?? null) === 'physics_raw',
        'The early termination must set the official audit request label before the host shutdown callback runs.');
    stock_ingress_expect((stock_ingress_json(stock_ingress_post(['action' => 'status', 'claimId' => $collisionClaimId], $settings, $testNow + 2))['state'] ?? null) === 'approved',
        'The raw QUERY_STRING packet must approve the claim before SHARMAT preprocessing can consume it.');

    $ingressDiagnostics = (string)file_get_contents($ingressLog);
    stock_ingress_expect(str_contains($ingressDiagnostics, 'connection_ingress=approved')
        && !str_contains($ingressDiagnostics, $collisionClaimId)
        && !str_contains($ingressDiagnostics, $collisionHash),
        'Reserved packet diagnostics must report only a fixed result without claim identifiers or hashes.');

    $ambiguousClaimId = qd_new_uuid();
    $ambiguousHash = hash('sha256', qd_new_token());
    stock_ingress_post(['action' => 'begin', 'claimId' => $ambiguousClaimId, 'tokenHash' => $ambiguousHash], $settings, $testNow);
    $ambiguousEncoded = base64_encode(implode('|', stock_ingress_event($ambiguousClaimId, $ambiguousHash)));
    stock_ingress_expect(stock_ingress_run_early_hook('DATA=' . $ambiguousEncoded . '&profile=TEST&extra=1', stock_ingress_headers()),
        'An ambiguous reserved query must be consumed before later hooks.');
    stock_ingress_expect((stock_ingress_json(stock_ingress_post(['action' => 'status', 'claimId' => $ambiguousClaimId], $settings, $testNow + 1))['state'] ?? null) === 'pending',
        'An ambiguous reserved query must not approve a claim.');

    $malformedClaimId = qd_new_uuid();
    $malformedHash = hash('sha256', qd_new_token());
    stock_ingress_post(['action' => 'begin', 'claimId' => $malformedClaimId, 'tokenHash' => $malformedHash], $settings, $testNow);
    $malformedEncoded = base64_encode(implode('|', stock_ingress_event($malformedClaimId, $malformedHash)));
    stock_ingress_expect(stock_ingress_run_early_hook('DATA=' . $malformedEncoded . '!&profile=TEST', stock_ingress_headers()),
        'A noncanonical reserved query must be consumed before later hooks.');
    stock_ingress_expect((stock_ingress_json(stock_ingress_post(['action' => 'status', 'claimId' => $malformedClaimId], $settings, $testNow + 1))['state'] ?? null) === 'pending',
        'A noncanonical reserved query must not approve a claim.');
    $ingressDiagnostics = (string)file_get_contents($ingressLog);
    stock_ingress_expect(str_contains($ingressDiagnostics, 'connection_ingress=malformed_query')
        && !str_contains($ingressDiagnostics, $malformedClaimId)
        && !str_contains($ingressDiagnostics, $malformedHash),
        'Malformed reserved packet diagnostics must use a fixed result without identifiers or hashes.');

    $deliveredEvent = stock_ingress_run_hook($packet, stock_ingress_headers());
    stock_ingress_expect(($deliveredEvent[0] ?? null) === 'physics_raw', 'The plugin hook must leave physics_raw unchanged for CHIM’s early-exit branch.');
    $afterHookState = stock_ingress_json(stock_ingress_post(['action' => 'status', 'claimId' => $claimId], $settings, $testNow + 3))['state'] ?? null;
    stock_ingress_expect($afterHookState === 'approved',
        'The actual preprocessing hook should approve a matching stock producer packet (state=' . (string)$afterHookState . '; method=' . (string)($_SERVER['REQUEST_METHOD'] ?? '') . '; origin=' . (array_key_exists('HTTP_ORIGIN', $_SERVER) ? 'present' : 'absent') . '; generation=' . (string)($_SERVER['HTTP_X_CHIM_GENERATION'] ?? '') . ').');
    $authenticated = qd_authenticate($settings['pairingPath'], $settings['webRoot'], 'Bearer ' . $bearer, $settings['privateRoot'], $settings['coreRoot']);
    stock_ingress_expect(is_array($authenticated) && $authenticated['scopes'] === ['compose', 'inspector', 'pcv', 'brainwash'],
        'A native-approved bearer should authenticate with the default scopes.');

    $pairId = $authenticated['pairId'];
    stock_ingress_expect(qd_connect_handle_stock_event($settings, $packet, stock_ingress_headers(), $testNow + 4) === 'approved',
        'Replaying an approved stock packet should be idempotent.');
    $secondClaim = qd_new_uuid();
    $sameBearer = stock_ingress_post(['action' => 'begin', 'claimId' => $secondClaim, 'tokenHash' => $tokenHash], $settings, $testNow + 5);
    stock_ingress_expect((stock_ingress_json($sameBearer)['state'] ?? null) === 'approved', 'A previously authorized hash should reconnect without issuing another pair.');
    $pairConfigBytes = file_get_contents($settings['pairingPath']);
    $pairConfig = json_decode(substr((string)$pairConfigBytes, strlen(QD_GUARDED_JSON_PREFIX)), true, 16, JSON_THROW_ON_ERROR);
    stock_ingress_expect(count($pairConfig['pairs']) === 1 && $pairConfig['pairs'][0]['pairId'] === $pairId,
        'A replay or reconnect must preserve the exact existing pair identity.');

    qd_pair_revoke($settings['pairingPath'], $settings['webRoot'], $pairId, $settings['privateRoot'], $settings['coreRoot']);
    stock_ingress_expect(qd_connect_handle_stock_event($settings, stock_ingress_event($secondClaim, $tokenHash), stock_ingress_headers(), $testNow + 6) === 'expired',
        'A revoked bearer hash must not be revived by a stock packet.');
    $revokedBytes = file_get_contents($settings['pairingPath']);
    $revokedConfig = json_decode(substr((string)$revokedBytes, strlen(QD_GUARDED_JSON_PREFIX)), true, 16, JSON_THROW_ON_ERROR);
    stock_ingress_expect(count($revokedConfig['pairs']) === 1 && $revokedConfig['pairs'][0]['revoked'] === true,
        'The native ingress must preserve revoked-pair tombstones.');

    $expiredId = qd_new_uuid();
    $expiredHash = hash('sha256', qd_new_token());
    qd_connect_begin($settings, $expiredId, $expiredHash, 2000);
    stock_ingress_expect(qd_connect_handle_stock_event($settings, stock_ingress_event($expiredId, $expiredHash), stock_ingress_headers(), 2600) === 'expired',
        'An expired pending claim must not become a new pair.');
} finally {
    $_SERVER = $oldServer;
    $_GET = $oldGet;
    if ($hadAuditRequest) {
        $GLOBALS['AUDIT_RUNID_REQUEST'] = $oldAuditRequest;
    } else {
        unset($GLOBALS['AUDIT_RUNID_REQUEST']);
    }
    if ($hadTerminatedAuditRequest) {
        $GLOBALS['stock_ingress_terminated_audit_request'] = $oldTerminatedAuditRequest;
    } else {
        unset($GLOBALS['stock_ingress_terminated_audit_request']);
    }
    if (is_string($oldErrorLog)) {
        ini_set('error_log', $oldErrorLog);
    }
    foreach ($oldEnv as $name => $value) {
        putenv($value === false ? $name : $name . '=' . $value);
    }
    stock_ingress_remove_tree($fixtureRoot);
}

echo 'PASS onboarding stock-ingress checks=' . $checks . PHP_EOL;
