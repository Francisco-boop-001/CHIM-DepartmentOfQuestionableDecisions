<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'companion.php';

function pcv_companion_scope_for_preflight(array $configuration, array $knownNpcs, array $routingSnapshot, string $targetName): ?array
{
    $names = [];
    if (($configuration['free'] ?? false) === true) {
        foreach ($routingSnapshot['present_actors'] ?? [] as $actor) {
            if (!is_array($actor) || !is_string($actor['name'] ?? null)) {
                return null;
            }
            $identity = pcv_companion_owner_target_identity($actor['name'], $knownNpcs);
            if ($identity !== null && !in_array($identity['canonicalName'], $names, true)) {
                $names[] = $identity['canonicalName'];
            }
        }
    } else {
        $ids = is_array($configuration['actor_ids'] ?? null) ? $configuration['actor_ids'] : [];
        if ($ids === []) {
            foreach (['actor_a', 'actor_b'] as $field) {
                if (is_string($configuration[$field] ?? null)) {
                    $ids[] = $configuration[$field];
                }
            }
        }
        foreach ($ids as $actorKey) {
            if (!is_string($actorKey) || preg_match('/\Anpc:([1-9][0-9]{0,18})\z/D', $actorKey, $matches) !== 1
                || !is_string($knownNpcs[$matches[1]] ?? null)) {
                return null;
            }
            $names[] = $knownNpcs[$matches[1]];
        }
    }
    $targetIdentity = pcv_companion_owner_target_identity($targetName, $knownNpcs);
    if ($targetIdentity === null || !in_array($targetIdentity['canonicalName'], $names, true) || $names === []) {
        return null;
    }
    $sceneMode = $configuration['scene_mode'] ?? 'pair';
    if (!in_array($sceneMode, ['pair', 'solo'], true)
        || ($sceneMode === 'pair' && count($names) < 2)
        || ($sceneMode === 'solo' && count($names) !== 1)) {
        return null;
    }
    $scope = [
        'enabled' => true,
        'scene_mode' => $sceneMode,
        'actor_a' => $names[0],
        'exclude_player' => $configuration['exclude_player'] ?? null,
        'bystander_mode' => $configuration['bystander_mode'] ?? null,
        'members' => $names,
    ];
    if ($sceneMode === 'pair') {
        $scope['actor_b'] = $names[1];
    }
    if (array_key_exists('free', $configuration)) {
        $scope['free'] = $configuration['free'];
    }
    if (array_key_exists('opener', $configuration)) {
        $scope['opener'] = $configuration['opener'] === 'auto'
            ? 'auto'
            : (is_string($configuration['opener']) && preg_match('/\Anpc:([1-9][0-9]{0,18})\z/D', $configuration['opener'], $matches) === 1
                ? ($knownNpcs[$matches[1]] ?? null) : null);
    }
    return is_bool($scope['exclude_player']) ? $scope : null;
}

function pcv_companion_operation_from_packet(array $packet): array
{
    return [
        'recordKey' => $packet['recordKey'] ?? null,
        'principalId' => $packet['principalId'] ?? null,
        'operationId' => $packet['operationId'] ?? null,
        'target' => $packet['target'] ?? null,
        'expectedActiveConfigId' => $packet['expectedActiveConfigId'] ?? null,
        'expectedPendingConfigId' => $packet['expectedPendingConfigId'] ?? null,
        'scopeHash' => $packet['scopeHash'] ?? null,
        'ownerKey' => $packet['ownerKey'] ?? null,
        'context' => $packet['context'] ?? null,
    ];
}

function pcv_companion_projection(array $packet, string $state): array
{
    $projection = [
        'operationId' => is_string($packet['operationId'] ?? null) ? $packet['operationId'] : null,
        'state' => $state,
        'expected' => [
            'activeConfigId' => $packet['expectedActiveConfigId'] ?? null,
            'pendingConfigId' => $packet['expectedPendingConfigId'] ?? null,
        ],
        'target' => [
            'formId' => $packet['target']['formId'] ?? null,
            'name' => $packet['target']['name'] ?? null,
        ],
    ];
    if ($state === 'unknown') {
        $projection['expected'] = ['activeConfigId' => null, 'pendingConfigId' => null];
        $projection['target'] = null;
    }
    return $projection;
}

function pcv_companion_set_result(array $packet, string $state): void
{
    $GLOBALS['PCV_COMPANION_RESULT'] = pcv_companion_projection($packet, $state);
}

function pcv_companion_scene_matches_begin(array $before, array $after, array $metadata, array $packet, array $knownNpcs): bool
{
    $now = is_int($metadata['now'] ?? null) ? $metadata['now'] : time();
    $beforeVisible = pcv_visible_state($before, $now);
    $afterVisible = pcv_visible_state($after, $now);
    if (($beforeVisible['config_id'] ?? null) !== ($packet['expectedActiveConfigId'] ?? null)
        || ($beforeVisible['pending_config_id'] ?? null) !== ($packet['expectedPendingConfigId'] ?? null)
        || ($afterVisible['status'] ?? null) !== 'active'
        || !is_string($afterVisible['config_id'] ?? null)
        || is_array($afterVisible['pending_scope'] ?? null)) {
        return false;
    }

    $pendingId = $packet['expectedPendingConfigId'] ?? null;
    $activeId = $packet['expectedActiveConfigId'] ?? null;
    if (is_string($pendingId)) {
        if (($metadata['activated']['config_id'] ?? null) !== $pendingId
            || ($afterVisible['config_id'] ?? null) !== $pendingId
            || ($metadata['blocked_pending'] ?? null) !== null) {
            return false;
        }
    } elseif (!is_string($activeId) || ($metadata['activated'] ?? null) !== null
        || ($afterVisible['config_id'] ?? null) !== $activeId
        || ($afterVisible['pending_config_id'] ?? null) !== null) {
        return false;
    }

    $targetName = $packet['target']['name'] ?? null;
    if (!is_string($targetName)) {
        return false;
    }
    $targetIdentity = pcv_companion_owner_target_identity($targetName, $knownNpcs);
    $eligible = is_string($pendingId)
        ? ($metadata['eligible_npc_map'] ?? null)
        : ($metadata['active_eligible_npc_map'] ?? null);
    if ($targetIdentity === null || !is_array($eligible)
        || ($eligible[$targetIdentity['id']] ?? null) !== $targetIdentity['canonicalName']) {
        return false;
    }
    $activeConfig = $after['active']['config'] ?? null;
    if (!is_array($activeConfig)) {
        return false;
    }
    $projected = pcv_companion_project_owner_config($activeConfig, $knownNpcs);
    return $projected !== null && pcv_companion_owner_target_is_member($projected, $targetName, $knownNpcs);
}

function pcv_companion_after_owner_preprocessing(): void
{
    $ownerPacket = $GLOBALS['PCV_COMPANION_PACKET'] ?? null;
    $packet = $GLOBALS['PCV_COMPANION_RAW_PACKET'] ?? null;
    $claim = $GLOBALS['PCV_COMPANION_CLAIM'] ?? null;
    if (!is_array($packet) || !is_array($ownerPacket) || !is_array($claim)
        || array_key_exists('token', $packet) || array_key_exists('token', $ownerPacket)) {
        return;
    }
    try {
        $ownerRoot = pcv_companion_load_owner_sources();
        $playerName = pcv_current_player_name();
        if (!is_string($playerName) || trim($playerName) === '') {
            throw new RuntimeException('The PCV owner identity is unavailable after preprocessing.');
        }
        $knownNpcs = pcvScopeKnownNpcs(pcvScopeLoadNpcCatalog(), $playerName);
        $projection = pcv_companion_owner_target_projection($packet, $knownNpcs);
        $rawIdentity = is_array($projection) ? ($projection['identity'] ?? null) : null;
        $expectedOwnerPacket = is_array($projection) ? ($projection['packet'] ?? null) : null;
        if (!is_array($rawIdentity) || !is_array($expectedOwnerPacket)
            || ($ownerPacket['target'] ?? null) !== ($expectedOwnerPacket['target'] ?? null)) {
            throw new RuntimeException('The owner-facing target no longer matches the unique catalog identity.');
        }
        unset($expectedOwnerPacket['token']);
        if ($ownerPacket !== $expectedOwnerPacket) {
            throw new RuntimeException('The temporary owner packet changed raw operation identity.');
        }
        $preflight = $GLOBALS['PCV_COMPANION_PREFLIGHT'] ?? null;
        if (!is_array($preflight) || ($preflight['target'] ?? null) !== ($ownerPacket['target'] ?? null)) {
            throw new RuntimeException('The owner preflight target does not match the unique catalog identity.');
        }
        $scope = $GLOBALS['PCV_REQUEST_SCOPE'] ?? null;
        $gameRequest = $GLOBALS['gameRequest'] ?? null;
        $requestScopeMatches = is_array($scope)
            && ($scope['status'] ?? null) === 'active'
            && ($scope['start'] ?? null) === true
            && ($scope['origin_request_type'] ?? null) === 'inputtext'
            && ($scope['origin_mode'] ?? null) === 'STANDARD'
            && ($scope['config_id'] ?? null) === ($claim['effectiveConfigId'] ?? null)
            && is_string($scope['opener_name'] ?? null)
            && is_array($gameRequest)
            && is_string($gameRequest[3] ?? null)
            && ($scope['origin_dialogue'] ?? null) === ($claim['preparedDialogue'] ?? null)
            && ($claim['preparedDialogue'] ?? null) === $gameRequest[3]
            && is_array($scope['scope'] ?? null)
            && pcv_companion_owner_scope_contains_target($scope['scope'], $packet['target']['name'] ?? '', $knownNpcs)
            && pcvRequestScopeModeMatches($scope)
            && pcvEffectiveExecutionMode($gameRequest) === 'STANDARD';
        if (!$requestScopeMatches) {
            pcv_companion_set_result($packet, 'unknown');
            return;
        }
        $directory = pcv_state_directory(pcv_companion_state_directory());
        $ownerKey = pcv_current_playthrough_key();
        if (!is_string($ownerKey) || !hash_equals((string)($packet['ownerKey'] ?? ''), $ownerKey)) {
            throw new RuntimeException('The PCV owner identity changed after preprocessing.');
        }
        $ownerLock = pcv_lock_state($directory, false, LOCK_SH);
        if ($ownerLock === null) {
            throw new RuntimeException('The PCV owner state is unavailable after preprocessing.');
        }
        try {
            $loaded = pcv_load_store($directory);
            if (($loaded['kind'] ?? null) !== 'ready' || !is_array($loaded['state'] ?? null)
                || !hash_equals($ownerKey, (string)($loaded['state']['key'] ?? ''))) {
                throw new RuntimeException('The PCV owner state is unavailable after preprocessing.');
            }
            $visible = pcv_visible_state($loaded['state'], time());
            if (($visible['config_id'] ?? null) !== ($claim['effectiveConfigId'] ?? null)
                || ($visible['status'] ?? null) !== 'active') {
                throw new RuntimeException('The accepted PCV scene changed before readback.');
            }
            $activeConfig = $loaded['state']['active']['config'] ?? null;
            $projected = is_array($activeConfig) ? pcv_companion_project_owner_config($activeConfig, $knownNpcs) : null;
            if ($projected === null || !pcv_companion_owner_target_is_member($projected, $packet['target']['name'], $knownNpcs)) {
                throw new RuntimeException('The accepted PCV scene no longer contains the native target.');
            }
            $operation = pcv_companion_operation_from_packet($packet);
            pcv_companion_with_current_pair_context($operation, static function () use ($directory, $claim): void {
                pcv_companion_finish_claim($directory, $claim['recordKey'], 'consumed', time());
            });
        } finally {
            pcv_unlock_state($ownerLock);
        }
        $result = pcv_companion_projection($packet, 'consumed');
        $result['configId'] = $scope['config_id'];
        $result['openerName'] = $scope['opener_name'];
        $result['requestType'] = $scope['origin_request_type'];
        $result['originMode'] = $scope['origin_mode'];
        $result['start'] = true;
        $GLOBALS['PCV_COMPANION_RESULT'] = $result;
        unset($ownerRoot);
    } catch (Throwable) {
        pcv_companion_set_result($packet, 'unknown');
    }
}

function pcv_companion_process_tagged_request(): void
{
    global $gameRequest;

    $packet = $GLOBALS['PCV_COMPANION_PACKET'] ?? null;
    if (!is_array($packet)) {
        return;
    }
    $gameRequest = $GLOBALS['gameRequest'] ?? null;
    if (!is_array($gameRequest) || strtolower((string)($gameRequest[0] ?? '')) !== 'inputtext'
        || !is_string($gameRequest[3] ?? null)
        || preg_match('/\A([^:\r\n]{1,96}):(pcv1_[A-Za-z0-9_-]{43})\z/D', $gameRequest[3], $matches) !== 1
        || $matches[1] !== ($packet['playerName'] ?? null)
        || !is_string($packet['token'] ?? null) || !hash_equals($packet['token'], $matches[2])) {
        pcv_companion_stop_stock_request('rejected', $packet, $packet['token'] ?? null);
    }
    $playerName = $matches[1];
    $token = $matches[2];
    $recordKey = $packet['recordKey'] ?? null;
    $operation = pcv_companion_operation_from_packet($packet);
    if (!is_string($recordKey) || preg_match('/\A[a-f0-9]{64}\z/D', $recordKey) !== 1) {
        pcv_companion_stop_stock_request('unknown', null);
    }

    try {
        $root = pcv_companion_load_owner_sources();
        $directory = pcv_state_directory(pcv_companion_state_directory());
        $ownerKey = pcv_current_playthrough_key();
        $currentPlayerName = pcv_current_player_name();
        $catalog = pcvScopeLoadNpcCatalog();
        $knownNpcs = pcvScopeKnownNpcs($catalog, $currentPlayerName);
        $ownerProjection = pcv_companion_owner_target_projection($packet, $knownNpcs);
        if ($ownerProjection === null) {
            pcv_companion_stop_stock_request('rejected', $operation, $token);
        }
        $snapshot = $GLOBALS['requestRoutingSnapshot'] ?? null;
        if (!is_string($ownerKey) || !hash_equals((string)($packet['ownerKey'] ?? ''), $ownerKey)
            || !is_string($currentPlayerName) || $currentPlayerName !== $playerName
            || strtoupper((string)($GLOBALS['CHIM_EXECUTION_MODE'] ?? '')) !== 'STANDARD'
            || pcvEffectiveExecutionMode($gameRequest) !== 'STANDARD'
            || !is_array($snapshot) || !is_string($packet['scopeHash'] ?? null)) {
            pcv_companion_stop_stock_request('rejected', $operation, $token);
        }

        $ownerLock = pcv_lock_state($directory, false, LOCK_SH);
        if ($ownerLock === null) {
            pcv_companion_stop_stock_request('rejected', $operation, $token);
        }
        $preflightFailure = null;
        $literal = null;
        $prepared = null;
        try {
            $loaded = pcv_load_store($directory);
            if (($loaded['kind'] ?? null) !== 'ready' || !is_array($loaded['state'] ?? null)
                || !hash_equals($ownerKey, (string)($loaded['state']['key'] ?? ''))) {
                $preflightFailure = 'rejected';
            } else {
                $state = $loaded['state'];
                $visible = pcv_visible_state($state, time());
                if (($visible['config_id'] ?? null) !== ($packet['expectedActiveConfigId'] ?? null)
                    || ($visible['pending_config_id'] ?? null) !== ($packet['expectedPendingConfigId'] ?? null)) {
                    $preflightFailure = 'rejected';
                } else {
                    $preview = pcv_companion_with_current_pair_context($operation, static function () use ($directory, $recordKey, $token): array {
                        return pcv_companion_preview_prepared_token($directory, $recordKey, $token, time());
                    });
                    if (($preview['state'] ?? null) !== 'prepared' || !is_string($preview['text'] ?? null)) {
                        $preflightFailure = ($preview['state'] ?? null) === 'expired' ? 'expired' : 'unknown';
                    } else {
                        $literal = $preview['text'];
                    }
                    if ($preflightFailure === null
                        && (!pcv_companion_text_is_safe($literal)
                            || str_starts_with($literal, $currentPlayerName . ':')
                            || in_array(pcvInGameCommand($literal, $currentPlayerName)['command'] ?? null, ['end', 'wrap'], true))) {
                        $preflightFailure = 'rejected';
                    }
                    if ($preflightFailure === null) {
                        $pending = $visible['pending_scope'] ?? null;
                        $ownerConfiguration = is_array($pending) && ($pending['enabled'] ?? null) === true
                            ? ($state['pending']['config'] ?? null)
                            : ($state['active']['config'] ?? null);
                        $configuration = pcv_companion_project_owner_config(is_array($ownerConfiguration) ? $ownerConfiguration : null, $knownNpcs);
                        if ($configuration === null || !pcv_companion_owner_target_is_member($configuration, $packet['target']['name'] ?? '', $knownNpcs)) {
                            $preflightFailure = 'rejected';
                        } else {
                            $scope = pcv_companion_scope_for_preflight($configuration, $knownNpcs, $snapshot, $packet['target']['name']);
                            if ($scope === null) {
                                $preflightFailure = 'rejected';
                            } else {
                                $preflightRequest = $gameRequest;
                                // Match the genuine stock request shape for both owner modes. PCV itself
                                // strips this exact player prefix only when exclude_player is enabled.
                                $preflightRequest[3] = $playerName . ':' . $literal;
                                try {
                                    $prepared = pcvPrepareScopedInput($preflightRequest, $snapshot, $scope, $currentPlayerName, 'STANDARD');
                                } catch (Throwable) {
                                    $preflightFailure = 'rejected';
                                }
                                if ($preflightFailure === null
                                    && (($prepared['status'] ?? null) !== 'applied' || !is_array($prepared['game_request'] ?? null)
                                        || !is_string($prepared['game_request'][3] ?? null))) {
                                    $preflightFailure = 'rejected';
                                }
                            }
                        }
                    }
                }
            }
        } finally {
            pcv_unlock_state($ownerLock);
        }
        if ($preflightFailure !== null || !is_array($prepared) || !is_string($literal)) {
            pcv_companion_stop_stock_request($preflightFailure ?? 'rejected', $operation, $token);
        }

        $preparedDialogue = $prepared['game_request'][3];
        $inputDialogue = $playerName . ':' . $literal;
        $precommit = static function (array $before, array $after, array $metadata, callable $commit) use (
            $packet, $operation, $token, $recordKey, $literal, $inputDialogue, $preparedDialogue, $knownNpcs, $directory
        ): array {
            $sceneMatches = pcv_companion_scene_matches_begin($before, $after, $metadata, $packet, $knownNpcs);
            $claimAttempted = false;
            try {
                return pcv_companion_with_current_pair_context($operation, static function () use (
                    $sceneMatches, $packet, $operation, $token, $recordKey, $literal, $inputDialogue, $preparedDialogue, $directory,
                    &$claimAttempted, $commit
                ): array {
                    if (!$sceneMatches) {
                        $retired = pcv_companion_reject_prepared_token($directory, $recordKey, $token, time());
                        pcv_companion_set_result($packet, $retired === 'rejected' ? 'rejected' : ($retired === 'expired' ? 'expired' : 'unknown'));
                        return ['status' => 'refused', 'outcome' => 'known_no_write', 'reason' => 'stale_revision'];
                    }
                    $claimAttempted = true;
                    $claimed = pcv_companion_claim_token($directory, $token, $packet['target'], time());
                    $sameContext = is_array($claimed['context'] ?? null)
                        && ($claimed['context']['serverContext'] ?? null) === ($packet['context']['serverContext'] ?? null)
                        && ($claimed['context']['viewToken'] ?? null) === ($packet['context']['viewToken'] ?? null)
                        && ($claimed['context']['nativeGeneration'] ?? null) === ($packet['context']['nativeGeneration'] ?? null);
                    if (($claimed['state'] ?? null) !== 'claimed'
                        || ($claimed['recordKey'] ?? null) !== $recordKey
                        || ($claimed['principalId'] ?? null) !== ($packet['principalId'] ?? null)
                        || ($claimed['operationId'] ?? null) !== ($packet['operationId'] ?? null)
                        || ($claimed['text'] ?? null) !== $literal
                        || ($claimed['target'] ?? null) !== ($packet['target'] ?? null)
                        || ($claimed['expectedActiveConfigId'] ?? null) !== ($packet['expectedActiveConfigId'] ?? null)
                        || ($claimed['expectedPendingConfigId'] ?? null) !== ($packet['expectedPendingConfigId'] ?? null)
                        || ($claimed['ownerKey'] ?? null) !== ($packet['ownerKey'] ?? null)
                        || ($claimed['scopeHash'] ?? null) !== ($packet['scopeHash'] ?? null)
                        || !$sameContext) {
                        pcv_companion_set_result($packet, 'unknown');
                        return ['status' => 'unknown', 'outcome' => 'uncertain'];
                    }
                    $effectiveConfigId = is_string($packet['expectedPendingConfigId'] ?? null)
                        ? $packet['expectedPendingConfigId'] : $packet['expectedActiveConfigId'];
                    $GLOBALS['PCV_COMPANION_CLAIM'] = [
                        'recordKey' => $recordKey,
                        'effectiveConfigId' => $effectiveConfigId,
                        'inputDialogue' => $inputDialogue,
                        'preparedDialogue' => $preparedDialogue,
                    ];
                    unset($GLOBALS['PCV_COMPANION_PACKET']['token']);
                    try {
                        $commit();
                    } catch (Throwable) {
                        pcv_companion_set_result($packet, 'unknown');
                        return ['status' => 'unknown', 'outcome' => 'uncertain'];
                    }
                    return ['status' => 'committed'];
                });
            } catch (Throwable $error) {
                pcv_companion_set_result($packet, 'unknown');
                if ($claimAttempted) {
                    return ['status' => 'unknown', 'outcome' => 'uncertain'];
                }
                $reason = $error instanceof QDServerFault ? $error->wireCode : 'unavailable';
                return ['status' => 'refused', 'outcome' => 'known_no_write', 'reason' => $reason];
            }
        };

        $ownerPacket = $ownerProjection['packet'];
        $rawPacketForOwner = $packet;
        unset($rawPacketForOwner['token']);
        $GLOBALS['PCV_COMPANION_OWNER_PRECOMMIT'] = $precommit;
        $GLOBALS['PCV_COMPANION_RAW_PACKET'] = $rawPacketForOwner;
        $GLOBALS['PCV_COMPANION_PACKET'] = $ownerPacket;
        $GLOBALS['PCV_COMPANION_PREFLIGHT'] = [
            'literal' => $literal,
            'inputDialogue' => $inputDialogue,
            'preparedDialogue' => $preparedDialogue,
            'playerName' => $playerName,
            'wireTokenHash' => hash('sha256', $token),
            'target' => $ownerPacket['target'],
            'expectedActiveConfigId' => $packet['expectedActiveConfigId'],
            'expectedPendingConfigId' => $packet['expectedPendingConfigId'],
        ];
        try {
            require_once $root . DIRECTORY_SEPARATOR . 'preprocessing.php';
        } finally {
            $restoredPacket = $rawPacketForOwner;
            unset($restoredPacket['token']);
            $GLOBALS['PCV_COMPANION_PACKET'] = $restoredPacket;
            if (is_array($GLOBALS['PCV_COMPANION_PREFLIGHT'] ?? null)) {
                $GLOBALS['PCV_COMPANION_PREFLIGHT']['target'] = $rawPacketForOwner['target'];
            }
            unset($GLOBALS['PCV_COMPANION_RAW_PACKET']);
        }
        $result = $GLOBALS['PCV_COMPANION_RESULT'] ?? null;
        if (!is_array($result) || ($result['state'] ?? null) !== 'consumed') {
            pcv_companion_stop_stock_request('unknown', $packet);
        }
    } catch (Throwable) {
        pcv_companion_stop_stock_request('unknown', $packet);
    }
}

pcv_companion_process_tagged_request();
