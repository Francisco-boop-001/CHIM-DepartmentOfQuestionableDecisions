<?php
declare(strict_types=1);

$implementationRoot = dirname(__DIR__, 2);
$companionSource = dirname($implementationRoot) . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR . 'companion.php';
require_once $companionSource;
$globalsSource = dirname($implementationRoot) . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR . 'globals.php';
$hadQueryString = array_key_exists('QUERY_STRING', $_SERVER);
$priorQueryString = $_SERVER['QUERY_STRING'] ?? null;
unset($_SERVER['QUERY_STRING']);
require_once $globalsSource;
if ($hadQueryString) {
    $_SERVER['QUERY_STRING'] = $priorQueryString;
}
$ownerScopeSource = dirname($implementationRoot) . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR
    . 'private-conversation-v0.1.23' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'scope.php';
$ownerScopeHash = PCV_COMPANION_OWNER_SOURCE_HASHES['scope.php'] ?? null;
if (!is_file($ownerScopeSource) || !is_string($ownerScopeHash)
    || !hash_equals($ownerScopeHash, strtolower((string)hash_file('sha256', $ownerScopeSource)))) {
    throw new RuntimeException('The text-policy owner fixture must match the pinned PCV scope source.');
}
require_once $ownerScopeSource;

function pcv_companion_test_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pcv_companion_test_uuid(): string
{
    return 'da2b45a7-a9f7-4c1d-8f24-' . bin2hex(random_bytes(6));
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pcv-companion-store-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);

try {
    $operationId = pcv_companion_test_uuid();
    $stage = [
        'operationId' => $operationId,
        'principalId' => 'principal-test',
        'scopeHash' => hash('sha256', 'save:character'),
        'ownerKey' => hash('sha256', 'profile:character'),
        'text' => "  Hello exactly.\nKeep the trailing space. ",
        'target' => ['formId' => '00F00123', 'name' => 'Lydia'],
        'expectedActiveConfigId' => null,
        'expectedPendingConfigId' => 'b56c3651-114b-40c3-bff8-6b7a6f3ea52f',
        'context' => [
            'serverContext' => 'server-context-a',
            'viewToken' => '9d24dc61-8b37-4b5d-b6b4-7416a10b0115',
            'nativeGeneration' => '17',
        ],
    ];
    $now = 1_850_000_000;

    $prepared = pcv_companion_stage_record($root, $stage, $now, 120);
    pcv_companion_test_check($prepared['operationId'] === $operationId
        && $prepared['state'] === 'prepared'
        && is_string($prepared['token'])
        && preg_match('/\Apcv1_[A-Za-z0-9_-]{43}\z/D', $prepared['token']) === 1
        && $prepared['expiresAt'] === $now + 120,
        'stage must return one bounded prepared token');
    $recordKey = pcv_companion_record_key($stage['principalId'], $stage['scopeHash'], $operationId);
    $preparedPath = pcv_companion_record_path(pcv_companion_store_directory($root), $recordKey);
    $preparedBytes = file_get_contents($preparedPath);
    pcv_companion_test_check(is_string($preparedBytes), 'prepared payload should be readable for the test fixture');

    $sameStage = pcv_companion_stage_record($root, $stage, $now + 1, 120);
    pcv_companion_test_check($sameStage === $prepared,
        'an exact stage retry with the stable operation ID must recover the same unclaimed token');

    $inspected = pcv_companion_inspect_token($root, $prepared['token'], $now + 1);
    pcv_companion_test_check($inspected['state'] === 'prepared'
        && $inspected['operationId'] === $operationId
        && $inspected['target'] === ['formId' => '00f00123', 'name' => 'Lydia']
        && !array_key_exists('text', $inspected)
        && !array_key_exists('token', $inspected),
        'early routing inspection must expose metadata only and leave the token claimable');

    $preparedStatus = pcv_companion_status_record($root, $stage['principalId'], $stage['scopeHash'], $operationId, $now + 1);
    pcv_companion_test_check($preparedStatus['state'] === 'prepared'
        && $preparedStatus['expected'] === ['activeConfigId' => null, 'pendingConfigId' => $stage['expectedPendingConfigId']]
        && $preparedStatus['target'] === ['formId' => '00f00123', 'name' => 'Lydia'],
        'status must expose only scoped operation identity, never token or literal text');

    $claim = pcv_companion_claim_token($root, $prepared['token'], ['formId' => '00f00123', 'name' => 'Lydia'], $now + 2);
    pcv_companion_test_check($claim['state'] === 'claimed'
        && $claim['text'] === $stage['text']
        && $claim['expectedPendingConfigId'] === $stage['expectedPendingConfigId']
        && ($claim['ownerKey'] ?? null) === $stage['ownerKey'],
        'claim must atomically retire token and return the exact literal only to the preprocessing caller');

    $duringClaim = pcv_companion_status_record($root, $stage['principalId'], $stage['scopeHash'], $operationId, $now + 2);
    pcv_companion_test_check($duringClaim['state'] === 'unknown'
        && $duringClaim['expected'] === ['activeConfigId' => null, 'pendingConfigId' => null]
        && $duringClaim['target'] === null
        && $duringClaim['expiresAt'] === null
        && !array_key_exists('token', $duringClaim)
        && !array_key_exists('text', $duringClaim),
        'an in-progress/ambiguous owner handoff must stay unknown and non-replayable');

    $replay = pcv_companion_claim_token($root, $prepared['token'], ['formId' => '00f00123', 'name' => 'Lydia'], $now + 3);
    pcv_companion_test_check($replay['state'] === 'unknown' && !array_key_exists('text', $replay),
        'a token replay after claim must never recover the literal');

    pcv_companion_finish_claim($root, $claim['recordKey'], 'consumed', $now + 4);
    $consumed = pcv_companion_status_record($root, $stage['principalId'], $stage['scopeHash'], $operationId, $now + 4);
    pcv_companion_test_check($consumed['state'] === 'consumed',
        'only the post-PCV acceptance callback may mark a claimed operation consumed');
    pcv_companion_test_check(file_put_contents($preparedPath, $preparedBytes) === strlen($preparedBytes),
        'test should be able to recreate a crash-window prepared payload shadow');
    pcv_companion_test_check(pcv_companion_inspect_token($root, $prepared['token'], $now + 4)['state'] === 'unknown'
        && is_file($preparedPath),
        'early raw-route inspection must remain read-only even if a terminal receipt shadows stale prepared bytes');
    pcv_companion_status_record($root, $stage['principalId'], $stage['scopeHash'], $operationId, $now + 4);
    pcv_companion_test_check(!file_exists($preparedPath),
        'a scoped status read may remove crash-window payload bytes under its outer owner/context locks');

    $cancelId = pcv_companion_test_uuid();
    $cancelStage = $stage;
    $cancelStage['operationId'] = $cancelId;
    $cancelStage['text'] = 'cancel me';
    $cancelPrepared = pcv_companion_stage_record($root, $cancelStage, $now + 5, 120);
    $cancelled = pcv_companion_cancel_record($root, $cancelStage['principalId'], $cancelStage['scopeHash'], $cancelId, $now + 6);
    pcv_companion_test_check($cancelled['state'] === 'cancelled',
        'cancel may confirm only after invalidating a still-prepared token');
    $cancelReplay = pcv_companion_claim_token($root, $cancelPrepared['token'], ['formId' => '00f00123', 'name' => 'Lydia'], $now + 7);
    pcv_companion_test_check($cancelReplay['state'] === 'unknown' && !array_key_exists('text', $cancelReplay),
        'a cancelled token must remain unusable');

    $wrongTargetId = pcv_companion_test_uuid();
    $wrongTargetStage = $stage;
    $wrongTargetStage['operationId'] = $wrongTargetId;
    $wrongTargetStage['text'] = 'wrong route';
    $wrongTargetPrepared = pcv_companion_stage_record($root, $wrongTargetStage, $now + 7, 120);
    $wrongTarget = pcv_companion_claim_token($root, $wrongTargetPrepared['token'], ['formId' => '01000012', 'name' => 'Aela'], $now + 8);
    $wrongTargetStatus = pcv_companion_status_record($root, $stage['principalId'], $stage['scopeHash'], $wrongTargetId, $now + 8);
    pcv_companion_test_check($wrongTarget['state'] === 'rejected'
        && $wrongTargetStatus['state'] === 'rejected',
        'a token presented for a different direct target must be terminally refused');

    $expiryId = pcv_companion_test_uuid();
    $expiryStage = $stage;
    $expiryStage['operationId'] = $expiryId;
    $expiryStage['text'] = 'expire me';
    $expiryPrepared = pcv_companion_stage_record($root, $expiryStage, $now + 8, 1);
    $expired = pcv_companion_status_record($root, $expiryStage['principalId'], $expiryStage['scopeHash'], $expiryId, $now + 9);
    pcv_companion_test_check($expired['state'] === 'expired',
        'only unclaimed prepared text may safely expire');
    $expiredReplay = pcv_companion_claim_token($root, $expiryPrepared['token'], ['formId' => '00f00123', 'name' => 'Lydia'], $now + 9);
    pcv_companion_test_check($expiredReplay['state'] === 'unknown' && !array_key_exists('text', $expiredReplay),
        'expired prepared data must not be returned or resurrected');

    $oldNow = $now + PCV_COMPANION_TERMINAL_DETAIL_TTL + 100;
    $compacted = pcv_companion_status_record($root, $stage['principalId'], $stage['scopeHash'], $operationId, $oldNow);
    pcv_companion_test_check($compacted['state'] === 'unknown'
        && $compacted['expected'] === ['activeConfigId' => null, 'pendingConfigId' => null]
        && $compacted['target'] === null,
        'terminal detail may expire to unknown while its anti-replay tombstone remains');
    $afterCompactionRetry = pcv_companion_stage_record($root, $stage, $oldNow + 1, 120);
    pcv_companion_test_check($afterCompactionRetry['state'] === 'unknown' && !array_key_exists('token', $afterCompactionRetry),
        'same operation ID must remain non-replayable after terminal detail compaction');
    $receiptKey = pcv_companion_record_key($stage['principalId'], $stage['scopeHash'], $operationId);
    $receiptPath = pcv_companion_record_path(pcv_companion_store_directory($root), $receiptKey, true);
    pcv_companion_test_check(filesize($receiptPath) < 512,
        'long-lived anti-replay identity must retain only a compact tombstone');

    $capacityNow = $oldNow + 1;
    $claimedOperations = [];
    for ($index = 0; $index < PCV_COMPANION_MAX_OUTSTANDING_OPERATIONS; $index++) {
        $claimedStage = $stage;
        $claimedStage['operationId'] = pcv_companion_test_uuid();
        $claimedStage['text'] = 'outstanding claimed message ' . $index;
        $issued = pcv_companion_stage_record($root, $claimedStage, $capacityNow, 120);
        $accepted = pcv_companion_claim_token($root, $issued['token'], ['formId' => '00f00123', 'name' => 'Lydia'], $capacityNow + 1);
        pcv_companion_test_check($accepted['state'] === 'claimed', 'each live PCV claim must count as one outstanding operation');
        $claimedOperations[] = ['stage' => $claimedStage, 'issued' => $issued, 'claim' => $accepted];
    }
    $overflowStage = $stage;
    $overflowStage['operationId'] = pcv_companion_test_uuid();
    $overflowStage['text'] = 'must wait for an unresolved claim';
    $capacityRejected = false;
    try {
        pcv_companion_stage_record($root, $overflowStage, $capacityNow + 121, 120);
    } catch (DomainException $error) {
        $capacityRejected = $error->getMessage() === 'capacity';
    }
    pcv_companion_test_check($capacityRejected,
        'elapsed time must not release capacity held by claimed operations');
    $claimedReplay = pcv_companion_claim_token($root, $claimedOperations[0]['issued']['token'], ['formId' => '00f00123', 'name' => 'Lydia'], $capacityNow + 121);
    pcv_companion_test_check($claimedReplay['state'] === 'unknown' && !array_key_exists('text', $claimedReplay),
        'an unresolved claimed token must remain non-replayable after its original TTL');
    pcv_companion_finish_claim($root, $claimedOperations[0]['claim']['recordKey'], 'consumed', $capacityNow + 122);
    $afterTerminal = pcv_companion_stage_record($root, $overflowStage, $capacityNow + 123, 120);
    pcv_companion_test_check($afterTerminal['state'] === 'prepared' && is_string($afterTerminal['token']),
        'settling one claim must release one outstanding slot while retaining its terminal anti-replay record');
    pcv_companion_cancel_record($root, $stage['principalId'], $stage['scopeHash'], $overflowStage['operationId'], $capacityNow + 124);
    for ($index = 1; $index < count($claimedOperations); $index++) {
        pcv_companion_finish_claim($root, $claimedOperations[$index]['claim']['recordKey'], 'consumed', $capacityNow + 124 + $index);
    }

    for ($index = 0; $index < 1100; $index++) {
        $many = $stage;
        $many['operationId'] = pcv_companion_test_uuid();
        $many['text'] = 'ordinary message ' . $index;
        $issued = pcv_companion_stage_record($root, $many, $oldNow + 2 + $index * 3, 120);
        $accepted = pcv_companion_claim_token($root, $issued['token'], ['formId' => '00f00123', 'name' => 'Lydia'], $oldNow + 3 + $index * 3);
        pcv_companion_test_check($accepted['state'] === 'claimed' && $accepted['text'] === $many['text'],
            'normal operation ' . $index . ' must still stage and claim after more than 1,024 consumed operations');
        pcv_companion_finish_claim($root, $accepted['recordKey'], 'consumed', $oldNow + 4 + $index * 3);
    }

    foreach (["#cheat", "  #cheat", "**resay", "  **resay", "(event)"] as $unsafeText) {
        pcv_companion_test_check(!pcv_companion_text_is_safe($unsafeText),
            'route-changing prefix must fail closed: ' . json_encode($unsafeText));
    }
    pcv_companion_test_check(pcv_companion_text_is_safe('A pipe | remains literal.'),
        'ordinary literal pipe characters remain stageable for post-parse restoration');
    pcv_companion_test_check(pcv_companion_text_is_safe('https://example.net/a:b and key:value'),
        'ordinary URL and colon-without-whitespace text remain stageable');
    pcv_companion_test_check(pcv_companion_text_is_safe('I wonder: maybe later'),
        'ordinary colon-space prose must remain stageable when wrapped in the known player prefix');
    $ownerScope = [
        'enabled' => true,
        'scene_mode' => 'solo',
        'actor_a' => 'Lydia',
        'actor_b' => null,
        'exclude_player' => true,
        'bystander_mode' => 'exclude',
    ];
    $knownPlayerRequest = ['inputtext', '1700000000', '1700000000', 'Dragonborn:Remember: this matters', 'e30='];
    $knownPlayerPrepared = pcvPrepareScopedInput($knownPlayerRequest, [], $ownerScope, 'Dragonborn', 'STANDARD');
    pcv_companion_test_check(($knownPlayerPrepared['status'] ?? null) === 'applied'
        && ($knownPlayerPrepared['game_request'][3] ?? null) === 'Dragonborn: Remember: this matters',
        'the frozen owner must strip only the exact known player prefix and preserve ordinary colon-space literal text');
    $foreignPlayerRequest = ['inputtext', '1700000000', '1700000000', 'Lydia: Remember: this matters', 'e30='];
    $foreignPlayerPrepared = pcvPrepareScopedInput($foreignPlayerRequest, [], $ownerScope, 'Dragonborn', 'STANDARD');
    pcv_companion_test_check(($foreignPlayerPrepared['status'] ?? null) === 'blocked'
        && ($foreignPlayerPrepared['reason'] ?? null) === 'invalid_input_prefix',
        'the frozen owner must still reject foreign speaker-shaped prefixes without the exact known player prefix');

    $fixtureToken = 'pcv1_' . str_repeat('A', 43);
    $ordinaryParts = ['inputtext', '1700000000', '1700000000', 'Dragonborn:A pcv1_example is just text.', 'e30='];
    pcv_companion_test_check(pcv_companion_raw_message_token($ordinaryParts) === null,
        'ordinary message text containing a protocol-like substring must preserve untagged fallback');
    $producerParts = ['inputtext', '1700000000', '1700000000', 'Dragonborn:' . $fixtureToken, 'e30='];
    pcv_companion_test_check(pcv_companion_raw_message_token($producerParts) === [
        'playerName' => 'Dragonborn',
        'token' => $fixtureToken,
    ], 'only the exact token in the stock player-attributed message field is reserved');

    $rawSnapshot = [
        'source' => 'plugin_player_routing_v2',
        'listener' => 'Lydia',
        'target_mode' => 'direct',
        'speech_mode' => 'standard',
        'execution_mode' => 'STANDARD',
        'present_actors' => [
            ['form_id' => 15728931, 'name' => 'Lydia', 'distance' => 125.5, 'managed' => true, 'creature' => false],
            ['form_id' => 16777234, 'name' => 'Aela', 'distance' => 130.0, 'managed' => true, 'creature' => false],
        ],
    ];
    $snapshotTarget = ['formId' => '00F00123', 'name' => 'Lydia'];
    $snapshotEncoded = base64_encode(json_encode($rawSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    pcv_companion_test_check(pcv_companion_raw_snapshot_matches($snapshotEncoded, $snapshotTarget),
        'ordinary stock producer snapshot must pass when shortcut field is omitted');
    $rawSnapshot['chat_shortcut_routed'] = false;
    $snapshotEncoded = base64_encode(json_encode($rawSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    pcv_companion_test_check(pcv_companion_raw_snapshot_matches($snapshotEncoded, $snapshotTarget),
        'explicit false shortcut marker remains a valid ordinary route');
    $rawSnapshot['chat_shortcut_routed'] = true;
    $snapshotEncoded = base64_encode(json_encode($rawSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    pcv_companion_test_check(!pcv_companion_raw_snapshot_matches($snapshotEncoded, $snapshotTarget),
        'a true shortcut marker must fail closed');
    $rawSnapshot['chat_shortcut_routed'] = null;
    $snapshotEncoded = base64_encode(json_encode($rawSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    pcv_companion_test_check(!pcv_companion_raw_snapshot_matches($snapshotEncoded, $snapshotTarget),
        'a malformed shortcut marker must fail closed');

    echo "PASS companion stage store lifecycle, 1,100-operation retention, owner-prefix policy, and stock-producer guard\n";
} finally {
    $resolvedRoot = realpath($root);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if (is_string($resolvedRoot) && is_string($temporaryRoot)
        && str_starts_with($resolvedRoot . DIRECTORY_SEPARATOR, rtrim($temporaryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($resolvedRoot);
    }
}
