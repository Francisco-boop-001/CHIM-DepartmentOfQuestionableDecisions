<?php
declare(strict_types=1);

if (!(require __DIR__ . '/installation_guard.php')) {
    return;
}

if (!function_exists('pcv_current_playthrough_key')) {
    require_once __DIR__ . '/state.php';
}
if (!function_exists('pcvPrepareScopedInput')) {
    require_once __DIR__ . '/scope.php';
}

$gameRequest = $GLOBALS['gameRequest'] ?? null;
if (!is_array($gameRequest)) {
    return;
}

$requestType = (string)($gameRequest[0] ?? '');
if ($requestType === 'infonpc') {
    // The wider "beings in range" report keeps an active scene's partner who briefly left close range.
    try {
        if (function_exists('pcv_capture_wide_presence_report')) {
            pcv_capture_wide_presence_report(pcv_current_playthrough_key(), $gameRequest[3] ?? null, pcv_current_player_name());
        }
    } catch (Throwable $error) {
        pcv_log_exception('state.unavailable', 'error', 'unavailable', 'presence_unavailable', $error, ['operation' => 'presence_capture']);
    }
    return;
}
if ($requestType === 'infonpc_close') {
    try {
        if (!function_exists('pcv_capture_background_presence_report')) {
            throw new RuntimeException('Background presence capture is unavailable.');
        }
        pcv_capture_background_presence_report(
            pcv_current_playthrough_key(),
            $gameRequest[3] ?? null,
            pcv_current_player_name(),
            $gameRequest[1] ?? null
        );
    } catch (Throwable $error) {
        pcv_invalidate_eligible_npcs();
        pcv_log_exception('state.unavailable', 'error', 'unavailable', 'presence_unavailable', $error, ['operation' => 'presence_capture']);
    }
    return;
}
$ordinaryInputTypes = ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'];
$isOrdinaryInput = in_array($requestType, $ordinaryInputTypes, true);
$isIdentitySync = in_array($requestType, ['playerinfo', 'newgame'], true);
if ($isIdentitySync) {
    pcv_invalidate_eligible_npcs();
    return;
}
$continuationTypes = ['rechat', 'continue', 'continue_group'];
$isContinuation = in_array($requestType, $continuationTypes, true);
if (in_array($requestType, ['bored', 'instruction'], true)) {
    // 0.1.15 (L1/L2): CHIM background narration waits while a scene with excluded bystanders is active. This runs
    // before CHIM logs the request's user_input row, so it can no longer cancel the scene's own reply.
    try {
        $pauseKey = pcv_current_playthrough_key();
        $pauseState = is_string($pauseKey) ? pcv_read($pauseKey) : null;
    } catch (Throwable) {
        $pauseState = null;
    }
    if (pcvBackgroundPauseApplies($requestType, $pauseState)) {
        pcvRoutingLogStart($requestType);
        pcvEndRequestQuietly('Private Conversation paused background narration during an active scene.', 'background_paused', 'preprocessing');
    }
    return;
}
if (!$isOrdinaryInput && !$isContinuation) {
    return;
}
$mode = pcvEffectiveExecutionMode($gameRequest);
if ($mode === 'DIRECTOR') {
    // Director has a separate worker and is explicitly outside this extension's scope.
    pcvRoutingLogDetail('preprocessing', 'director_excluded', $requestType);
    return;
}

if ($mode !== 'STANDARD') {
    pcvRoutingLogStart($requestType);
    if ($mode === 'CLOSE' || $mode === 'WHISPER') {
        try {
            $modeKey = pcv_current_playthrough_key();
            $modeState = $modeKey === null ? null : pcv_read($modeKey);
        } catch (Throwable $error) {
            pcvRoutingLogException('preprocessing', $error);
            throw $error;
        }
        if (($modeState['status'] ?? null) === 'active') {
            pcvRoutingLogSetState([
                'config_id' => $modeState['config_id'] ?? null,
                'actor_a_id' => $modeState['scope']['actor_a'] ?? null,
                'actor_b_id' => $modeState['scope']['actor_b'] ?? null,
            ]);
            pcvBlockRequest('Private Conversation does not support Close or Whisper mode; request stopped for safety.', 'unsupported_special_mode', 'preprocessing');
        }
        if (($modeState['status'] ?? null) === 'unavailable' || ($modeKey === null && pcvScopeStoredStateExists())) {
            pcvBlockRequest('Private Conversation state is unavailable; request stopped for safety.', 'state_unavailable', 'preprocessing', null, true);
        }
    }
    $GLOBALS['PCV_REQUEST_SCOPE'] = ['status' => 'ignored', 'scope' => null, 'start' => false];
    pcv_log_set_terminal('skipped', 'unsupported_mode', ['phase' => 'preprocessing']);
    pcv_log_event('routing.request_skipped', 'info', 'skipped', 'unsupported_mode', [
        'phase' => 'preprocessing',
        'request_type' => pcvRoutingLogType($requestType),
        'state_status' => 'off',
        'mode' => pcvRoutingLogMode($mode),
    ]);
    $GLOBALS['PCV_ROUTING_LOG_TERMINAL'] = true;
    pcvRoutingLogDetail('preprocessing', 'unsupported_mode', $requestType);
    return;
}

// 0.1.14 G1: "end scene" said in game ends an active, queued or stuck scene like END; that input reaches no NPC.
// It is checked before activation, so it never starts a queued scene first. Without a scene it is ordinary speech.
$inGameCommand = null;
if ($isOrdinaryInput) {
    try {
        $commandPlayer = pcv_current_player_name();
    } catch (Throwable) {
        $commandPlayer = null;
    }
    $inGameCommand = pcvInGameCommand($gameRequest[3] ?? null, is_string($commandPlayer) ? $commandPlayer : null);
    if (in_array($inGameCommand['command'] ?? null, ['end', 'wrap'], true)) {
        try {
            $endKey = pcv_current_playthrough_key();
            $endState = is_string($endKey) ? pcv_read($endKey) : null;
        } catch (Throwable) {
            $endKey = null;
            $endState = null;
        }
        if ($inGameCommand['command'] === 'end'
            && is_string($endKey) && in_array($endState['status'] ?? null, ['active', 'pending', 'unavailable'], true)) {
            pcvRoutingLogStart($requestType);
            $ended = pcv_stage($endKey, ['enabled' => false], [], null, 'ended_in_game');
            if (($ended['status'] ?? null) !== 'off') {
                pcvBlockRequest('Private Conversation could not confirm that the scene ended. It may still be active or pending; request stopped for safety.', 'state_unavailable', 'preprocessing', $endState, true);
            }
            pcvEndRequestQuietly('Private Conversation scene ended.', 'ended_in_game', 'preprocessing');
        }
        // 0.1.15 (L7): solo has no closing turn; "wrap up:" ends a stored solo scene instead of becoming its topic.
        if ($inGameCommand['command'] === 'wrap' && is_string($endKey) && pcvWrapEndsScene($endState)) {
            pcvRoutingLogStart($requestType);
            $ended = pcv_stage($endKey, ['enabled' => false], [], null, 'wrapped_up');
            if (($ended['status'] ?? null) !== 'off') {
                pcvBlockRequest('Private Conversation could not confirm that the scene ended. It may still be active or pending; request stopped for safety.', 'state_unavailable', 'preprocessing', $endState, true);
            }
            pcvEndRequestQuietly('Private Conversation reflection ended.', 'ended_in_game', 'preprocessing');
        }
    }
}

$currentPresence = null;
if ($isOrdinaryInput) {
    try {
        $presenceKey = pcv_current_playthrough_key();
    } catch (Throwable) {
        $presenceKey = null;
    }
    try {
        $currentPresence = pcv_capture_presence_snapshot($presenceKey, $gameRequest[4] ?? null);
    } catch (Throwable $error) {
        pcv_log_exception('state.unavailable', 'error', 'unavailable', 'presence_unavailable', $error, ['operation' => 'presence_capture']);
        $currentPresence = ['status' => 'unavailable', 'actors' => [], 'reason' => 'presence_unavailable'];
    }
}

$eligible = $isOrdinaryInput && $mode === 'STANDARD';
$snapshot = $GLOBALS['requestRoutingSnapshot'] ?? [];
if (!is_array($snapshot)) {
    $snapshot = [];
}
pcvRoutingLogStart($requestType);
try {
    $companionPrecommit = $GLOBALS['PCV_COMPANION_OWNER_PRECOMMIT'] ?? null;
    $state = pcvBeginResolvedScope($eligible, $currentPresence, $isContinuation,
        is_callable($companionPrecommit) ? $companionPrecommit : null);
} catch (Throwable $error) {
    pcvRoutingLogException('preprocessing', $error);
    throw $error;
}
$companionPacket = $GLOBALS['PCV_COMPANION_PACKET'] ?? null;
if (is_array($companionPacket) && !is_array($GLOBALS['PCV_COMPANION_CLAIM'] ?? null)) {
    $companionResult = $GLOBALS['PCV_COMPANION_RESULT'] ?? null;
    if (!is_array($companionResult) && function_exists('pcv_companion_hook_result')) {
        $companionResult = pcv_companion_hook_result('unknown', $companionPacket);
    }
    if (is_array($companionResult) && function_exists('pcv_companion_emit_hook_result')) {
        pcv_companion_emit_hook_result($companionResult);
    }
    pcvBlockRequest('Private Conversation companion request was not accepted; request stopped for safety.',
        'state_unavailable', 'preprocessing', null, true);
}
if (is_array($companionPacket)) {
    $companionClaim = $GLOBALS['PCV_COMPANION_CLAIM'] ?? null;
    $companionPreflight = $GLOBALS['PCV_COMPANION_PREFLIGHT'] ?? null;
    $gameRequest = $GLOBALS['gameRequest'] ?? null;
    $targetName = $companionPacket['target']['name'] ?? null;
    $companionRestoreText = is_array($companionPreflight) ? ($companionPreflight['literal'] ?? null) : null;
    $companionInputDialogue = is_array($companionPreflight) ? ($companionPreflight['inputDialogue'] ?? null) : null;
    $preparedDialogue = is_array($companionPreflight) ? ($companionPreflight['preparedDialogue'] ?? null) : null;
    if (!is_array($companionClaim) || !is_array($companionPreflight)
        || !is_string($companionRestoreText) || !is_string($companionInputDialogue)
        || !is_string($preparedDialogue) || !is_array($gameRequest)
        || ($companionClaim['recordKey'] ?? null) !== ($companionPacket['recordKey'] ?? null)
        || ($companionClaim['effectiveConfigId'] ?? null) !== ($state['config_id'] ?? null)
        || ($companionClaim['inputDialogue'] ?? null) !== $companionInputDialogue
        || ($companionClaim['preparedDialogue'] ?? null) !== $preparedDialogue
        || ($companionPreflight['playerName'] ?? null) !== ($companionPacket['playerName'] ?? null)
        || !is_string($companionPreflight['wireTokenHash'] ?? null)
        || ($companionPreflight['target'] ?? null) !== ($companionPacket['target'] ?? null)
        || ($companionPreflight['expectedActiveConfigId'] ?? null) !== ($companionPacket['expectedActiveConfigId'] ?? null)
        || ($companionPreflight['expectedPendingConfigId'] ?? null) !== ($companionPacket['expectedPendingConfigId'] ?? null)
        || ($state['status'] ?? null) !== 'active' || !is_string($targetName)
        || !is_array($state['scope'] ?? null) || !in_array($targetName, pcvScopeMembers($state['scope']), true)) {
        $companionResult = function_exists('pcv_companion_hook_result')
            ? pcv_companion_hook_result('unknown', $companionPacket)
            : ['operationId' => null, 'state' => 'unknown', 'expected' => ['activeConfigId' => null, 'pendingConfigId' => null], 'target' => null];
        if (function_exists('pcv_companion_emit_hook_result')) {
            pcv_companion_emit_hook_result($companionResult);
        }
        pcvBlockRequest('Private Conversation companion input could not be restored safely; request stopped.',
            'state_unavailable', 'preprocessing', null, true);
    }
}
$status = $state['status'] ?? 'unavailable';
pcvRoutingLogSetState($state);
if ($status === 'identity_unavailable') {
    // With no validated playthrough key, no stored scope can be matched to this character.
    $GLOBALS['PCV_REQUEST_SCOPE'] = ['status' => 'identity_unavailable', 'scope' => null, 'start' => false];
    pcv_log_set_terminal('skipped', 'identity_unavailable', ['phase' => 'preprocessing']);
    pcv_log_event('routing.request_skipped', 'info', 'skipped', 'identity_unavailable', [
        'phase' => 'preprocessing',
        'request_type' => pcvRoutingLogType($requestType),
        'state_status' => 'identity_unavailable',
        'mode' => pcvRoutingLogMode($mode),
    ]);
    $GLOBALS['PCV_ROUTING_LOG_TERMINAL'] = true;
    pcvRoutingLogDetail('preprocessing', 'identity_unavailable', $requestType);
    return;
}

if ($status === 'off' || $status === 'pending') {
    $GLOBALS['PCV_REQUEST_SCOPE'] = ['status' => $status, 'scope' => null, 'start' => false];
    $reason = $status === 'pending' ? 'scope_pending' : 'scope_off';
    pcv_log_set_terminal('skipped', $reason, ['phase' => 'preprocessing']);
    pcv_log_event('routing.request_skipped', 'info', 'skipped', $reason, [
        'phase' => 'preprocessing',
        'request_type' => pcvRoutingLogType($requestType),
        'state_status' => $status,
        'mode' => pcvRoutingLogMode($mode),
    ]);
    $GLOBALS['PCV_ROUTING_LOG_TERMINAL'] = true;
    pcvRoutingLogDetail('preprocessing', $status === 'pending' ? 'scope_pending' : 'scope_off', $requestType, $state);
    return;
}
if ($status === 'unavailable' || !is_array($state['scope'] ?? null)) {
    $GLOBALS['PCV_REQUEST_SCOPE'] = ['status' => 'unavailable', 'scope' => null, 'start' => false];
    if (($state['reason'] ?? null) === 'scene_not_eligible') {
        pcvBlockRequest('Private Conversation actors are no longer eligible; request stopped for safety.', 'scene_not_eligible', 'preprocessing', $state);
    }
    pcvBlockRequest('Private Conversation is unavailable; request stopped for safety.', 'state_unavailable', 'preprocessing', $state, true);
}

if ($isContinuation && ($state['scope']['scene_mode'] ?? 'pair') === 'solo') {
    pcvBlockRequest('Solo reflection accepts one ordinary Standard input and does not continue through rechat.', 'solo_rechat_unsupported', 'preprocessing', $state);
}
if (in_array($requestType, ['continue', 'continue_group'], true)
    && ($state['scope']['scene_mode'] ?? 'pair') === 'pair'
    && ($state['scope']['exclude_player'] ?? true) === true) {
    // Core's continue prompt explicitly injects a player gesture; this would violate
    // the excluded-player contract even though the continuation speaker is scoped.
    pcvBlockRequest('This Private Conversation continuation prompt includes the player; request stopped for safety.', 'pair_continuation_player_excluded', 'preprocessing', $state);
}

try {
    $playerName = pcv_current_player_name();
} catch (Throwable $error) {
    pcvRoutingLogException('preprocessing', $error, $state);
    throw $error;
}
if (!is_string($playerName) || trim($playerName) === '') {
    pcvBlockRequest('Private Conversation player identity is unavailable; request stopped for safety.', 'player_identity_unavailable', 'preprocessing', $state, true);
}
$resolvedScope = $state['scope'];
$profileA = $state['profile_id_a'] ?? null;
if (!is_int($profileA) || $profileA < 1) {
    pcvBlockRequest('Private Conversation actor profile is unavailable; request stopped for safety.', 'actors_unavailable', 'preprocessing', $state, true);
}

if ($isContinuation) {
    if ($requestType === 'rechat') {
        $rawRechat = $gameRequest[3] ?? null;
        $failureReason = null;
        try {
            $clamped = pcvClampRechatActiveAgents(is_string($rawRechat) ? $rawRechat : null, $resolvedScope, (string)($GLOBALS['HERIKA_NAME'] ?? ''), $failureReason);
        } catch (Throwable $error) {
            pcvRoutingLogException('preprocessing', $error, $state);
            throw $error;
        }
        if ($clamped === null) {
            $reason = $failureReason === 'rechat_speaker_outside_scene' ? $failureReason : 'malformed_rechat';
            pcvBlockRequest('Private Conversation rechat payload or speaker is outside the selected scene; request stopped for safety.', $reason, 'preprocessing', $state);
        }
        $gameRequest[3] = $clamped;
        $GLOBALS['gameRequest'] = $gameRequest;
    }
    $routing = pcvScopeRoutingSnapshot($snapshot, $resolvedScope, $playerName);
    $GLOBALS['requestRoutingSnapshot'] = $routing;
    $route = $requestType === 'rechat' ? 'rechat_clamped' : 'pair_continuation';
    $decision = $requestType === 'rechat' ? 'rechat_clamped' : 'continuation_routed';
    $GLOBALS['PCV_REQUEST_SCOPE'] = array_replace($state, [
        'status' => 'active', 'scope' => $resolvedScope, 'start' => false,
        'profile_id_a' => $profileA, 'route' => $route,
        'origin_request_type' => $requestType, 'origin_mode' => $mode,
    ]);
    pcvRoutingLogDetail('preprocessing', $decision, $requestType, $state, [
        'audience_before_count' => pcvRoutingAudienceCount($snapshot['audience'] ?? null),
        'audience_after_count' => pcvRoutingAudienceCount($routing['audience'] ?? null),
        'present_before_count' => pcvRoutingPresenceCount($snapshot['present_actors'] ?? null),
        'present_after_count' => pcvRoutingPresenceCount($routing['present_actors'] ?? null),
    ]);
    return;
}

// 0.1.14 G6: "wrap up: <how>" makes this the closing turn of a pair, group or free scene; the scene ends after it.
$wrapUp = false;
if (($inGameCommand['command'] ?? null) === 'wrap' && ($resolvedScope['scene_mode'] ?? 'pair') !== 'solo') {
    // Normalize before the shared prefix check; $wrapUp makes included-player input use that same validation path.
    $gameRequest[3] = $playerName . ': ' . $inGameCommand['direction'];
    $wrapUp = true;
}

try {
    if (is_array($companionPacket)) {
        // The wire carried only the one-use token. After the locked owner claim, restore
        // the literal in the original inputtext shape; PCV must apply its normal prefix
        // rules exactly once before the request can be marked accepted.
        $wireParts = [];
        $wireText = $gameRequest[3] ?? null;
        $wireValid = is_string($wireText)
            && preg_match('/\A([^:\r\n]{1,96}):(pcv1_[A-Za-z0-9_-]{43})\z/D', $wireText, $wireParts) === 1
            && ($wireParts[1] ?? null) === $playerName
            && ($companionPreflight['playerName'] ?? null) === $playerName
            && hash_equals((string)$companionPreflight['wireTokenHash'], hash('sha256', (string)($wireParts[2] ?? '')));
        if (!$wireValid || !is_string($companionRestoreText)) {
            $companionResult = function_exists('pcv_companion_hook_result')
                ? pcv_companion_hook_result('unknown', $companionPacket)
                : ['operationId' => null, 'state' => 'unknown', 'expected' => ['activeConfigId' => null, 'pendingConfigId' => null], 'target' => null];
            if (function_exists('pcv_companion_emit_hook_result')) {
                pcv_companion_emit_hook_result($companionResult);
            }
            pcvBlockRequest('Private Conversation companion input could not be restored safely; request stopped.',
                'state_unavailable', 'preprocessing', null, true);
        }
        $gameRequest[3] = $playerName . ':' . $companionRestoreText;
        if (pcvEffectiveExecutionMode($gameRequest) !== 'STANDARD') {
            $companionResult = function_exists('pcv_companion_hook_result')
                ? pcv_companion_hook_result('unknown', $companionPacket)
                : ['operationId' => null, 'state' => 'unknown', 'expected' => ['activeConfigId' => null, 'pendingConfigId' => null], 'target' => null];
            if (function_exists('pcv_companion_emit_hook_result')) {
                pcv_companion_emit_hook_result($companionResult);
            }
            pcvBlockRequest('Private Conversation companion input changed the effective route; request stopped.',
                'unsupported_mode', 'preprocessing', null, true);
        }
        $GLOBALS['gameRequest'] = $gameRequest;
    }
    $prepared = pcvPrepareScopedInput($gameRequest, $snapshot, $resolvedScope, $playerName, $mode, $wrapUp);
} catch (Throwable $error) {
    pcvRoutingLogException('preprocessing', $error, $state);
    throw $error;
}
if (($prepared['status'] ?? null) !== 'applied') {
    if (($prepared['status'] ?? null) === 'unavailable') {
        pcvBlockRequest('Private Conversation actors are unavailable; request stopped for safety.', 'actors_unavailable', 'preprocessing', $state, true);
    }
    $reason = $prepared['reason'] ?? 'invalid_input_prefix';
    if (!in_array($reason, ['unsupported_special_mode', 'invalid_input_prefix', 'empty_input', 'invalid_input_encoding'], true)) {
        $reason = 'invalid_input_prefix';
    }
    pcvBlockRequest('Private Conversation input could not be routed safely; request stopped.', $reason, 'preprocessing', $state);
}
$GLOBALS['gameRequest'] = $prepared['game_request'];
$gameRequest = $prepared['game_request'];
$GLOBALS['requestRoutingSnapshot'] = $prepared['routing_snapshot'];
$route = ($resolvedScope['scene_mode'] ?? 'pair') === 'solo'
    ? 'solo_reflection'
    : (($wrapUp || ($resolvedScope['exclude_player'] ?? true)) ? 'scene_direction' : 'player_speech');
$baselineOutputLog = is_array($GLOBALS['DEBUG_DATA'] ?? null) && is_string($GLOBALS['DEBUG_DATA']['OUTPUT_LOG'] ?? null)
    ? $GLOBALS['DEBUG_DATA']['OUTPUT_LOG'] : '';
// Group scenes: the member named earliest in the direction opens, else the picked opener, else the first member.
// Free scenes: named, else the player's direct target if a member, else the nearest member.
$openerName = (string)($resolvedScope['actor_a'] ?? '');
$openerSource = 'first';
$openerProfile = $profileA;
if ($route !== 'solo_reflection') {
    $direction = is_string($gameRequest[3] ?? null) ? $gameRequest[3] : '';
    $pick = pcvGroupPickOpener($direction, pcvScopeMembers($resolvedScope), $resolvedScope['opener'] ?? null,
        pcvSnapshotDirectTarget($snapshot), ($resolvedScope['free'] ?? false) === true);
    $pickedProfile = $state['profiles'][$pick['name']] ?? null;
    if ($pick['name'] !== '' && is_int($pickedProfile) && $pickedProfile > 0) {
        $openerName = $pick['name'];
        $openerSource = $pick['source'];
        $openerProfile = $pickedProfile;
    }
}
$GLOBALS['PCV_REQUEST_SCOPE'] = array_replace($state, [
    'status' => 'active', 'scope' => $resolvedScope, 'start' => true,
    'profile_id_a' => $profileA, 'route' => $route,
    'origin_request_type' => $requestType, 'origin_mode' => $mode,
    'origin_dialogue' => is_string($gameRequest[3] ?? null) ? $gameRequest[3] : null,
    'baseline_utterance_id' => is_string($GLOBALS['SCRIPTLINE_UTTERANCE_ID'] ?? null) ? $GLOBALS['SCRIPTLINE_UTTERANCE_ID'] : null,
    'baseline_output_log' => $baselineOutputLog,
    'opener_name' => $openerName,
    'opener_source' => $openerSource,
    'profile_id_opener' => $openerProfile,
    'wrap_up' => $wrapUp,
]);
if ($wrapUp) {
    // The closing reply is already routed from PCV_REQUEST_SCOPE; end the stored scene now so nothing follows it.
    $ended = null;
    try {
        $wrapKey = pcv_current_playthrough_key();
        if (is_string($wrapKey)) {
            $ended = pcv_stage($wrapKey, ['enabled' => false], [], null, 'wrapped_up');
        }
    } catch (Throwable $error) {
        pcvRoutingLogException('preprocessing', $error, $state);
    }
    if (($ended['status'] ?? null) !== 'off') {
        pcvBlockRequest('Private Conversation could not confirm that the scene ended. It may still be active; request stopped for safety.', 'state_unavailable', 'preprocessing', $state, true);
    }
}
if ($route === 'solo_reflection' && is_string($state['config_id'] ?? null) && is_string($state['actor_a_id'] ?? null)) {
    try {
        pcv_solo_inflight_mark($state['config_id'], $state['actor_a_id']);
    } catch (Throwable) {
        // Classification aid only; registration and evaluation never depend on it.
    }
}
pcvRoutingLogDetail(
    'preprocessing',
    $route === 'solo_reflection' ? 'solo_reflection_routed'
        : ($route === 'scene_direction' ? 'input_rewritten' : 'player_speech_preserved'),
    $requestType,
    $state,
    [
        'audience_before_count' => pcvRoutingAudienceCount($snapshot['audience'] ?? null),
        'audience_after_count' => pcvRoutingAudienceCount($prepared['routing_snapshot']['audience'] ?? null),
        'present_before_count' => pcvRoutingPresenceCount($snapshot['present_actors'] ?? null),
        'present_after_count' => pcvRoutingPresenceCount($prepared['routing_snapshot']['present_actors'] ?? null),
    ]
);
if (function_exists('pcv_companion_after_owner_preprocessing')) {
    pcv_companion_after_owner_preprocessing();
}
