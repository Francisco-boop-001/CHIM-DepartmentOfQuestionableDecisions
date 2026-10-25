<?php
declare(strict_types=1);

/** Dev.23 owner cases use prepared synthetic episodes, not Skyrim sampling evidence. */
function dev23_companion_cases(array &$ctx): array
{
    $admin = $ctx['admin'] ?? null;
    dev20_require(is_object($admin), 'companion_admin_connection_unavailable');
    $actors = [900001, 900002, 900003];
    $originals = [];
    $identities = [];
    $docs = [];
    $scope = null;
    $priorEnabled = null;
    $settingCaptured = false;
    $caseIds = [];
    $start = count($ctx['results']);
    $record = static function (string $id, callable $work) use (&$ctx, $admin, &$caseIds): bool {
        $caseIds[] = $id;
        return dev20_case_record($ctx, $id, $work, $admin);
    };

    try {
        $workflowReady = $record('companion_owner_tick_and_compose', static function () use (
            $ctx, $admin, $actors, &$originals, &$identities, &$docs, &$scope, &$priorEnabled, &$settingCaptured
        ): array {
            foreach ($actors as $id) {
                $rows = dev20_pg($admin, 'SELECT extended_data::text AS data FROM public.core_npc_master WHERE id=$1::bigint', [(string)$id]);
                dev20_require(count($rows) === 1 && is_string($rows[0]['data'] ?? null), 'companion_actor_missing');
                $originals[$id] = $rows[0]['data'];
                $data = json_decode($rows[0]['data'], true, 32, JSON_THROW_ON_ERROR);
                dev20_require(is_array($data), 'companion_actor_data_invalid');
                $data['background_life_enabled'] = true;
                $data['background_life_letters'] = true;
                dev20_pg($admin, 'UPDATE public.core_npc_master SET extended_data=$1::jsonb WHERE id=$2::bigint',
                    [json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (string)$id]);
            }
            $last = dev20_pg($admin, 'SELECT ts::text AS ts FROM public.eventlog ORDER BY rowid DESC LIMIT 1');
            dev20_require(count($last) === 1 && ctype_digit((string)($last[0]['ts'] ?? '')), 'companion_clock_missing');
            $ts = max((int)$last[0]['ts'] + 1, time());
            dev20_pg($admin, "INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES($1::bigint,20000000::numeric,'info','synthetic dev23 clock','dev23_companion_test',$1::bigint,'','','')", [(string)$ts]);

            $presence = dev20_run_owner_fixture($ctx, 'seed-presence');
            dev20_require(($presence['ready'] ?? null) === true && ($presence['presenceExecutor'] ?? null) === 'Lydia',
                'companion_presence_precondition_missing');

            $made = dev23_companion_with_owner_runtime($ctx, static function ($store, $service, $companion) use (
                $actors, &$identities, &$scope, &$priorEnabled, &$settingCaptured
            ): array {
                $clock = $store->scope();
                $scope = $clock['playthrough_id'] ?? null;
                $gamets = filter_var($clock['gamets'] ?? null, FILTER_VALIDATE_INT);
                $token = $store->restoreGeneration();
                dev20_require(is_string($scope) && $scope !== '' && is_int($gamets) && $gamets >= 20000000
                    && is_string($token) && $token !== '', 'companion_runtime_context_missing');
                $epoch = correspondenceCompanionEpoch($token);
                $minute = correspondenceCompanionGameMinute($gamets);
                $setting = $store->query('SELECT enabled FROM plugins.correspondence_autonomy_settings WHERE playthrough_id=$1', [$scope]);
                $priorEnabled = $setting === [] ? null : in_array($setting[0]['enabled'] ?? null, [true, 't', 'true', '1', 1], true);
                $settingCaptured = true;

                $episodeIds = [];
                $store->transaction(static function () use ($store, $actors, $scope, $epoch, $gamets, $minute, &$identities, &$episodeIds): void {
                    $store->query('INSERT INTO plugins.correspondence_autonomy_settings(playthrough_id,enabled) VALUES($1,TRUE) ON CONFLICT(playthrough_id) DO UPDATE SET enabled=EXCLUDED.enabled,updated_at=clock_timestamp()', [$scope]);
                    foreach ($actors as $id) {
                        $identities[$id] = correspondenceCompanionIdentity(['id' => (string)$id] + $store->physicalActor(['kind' => 'npc', 'id' => $id]));
                        $episodeIds[$id] = dev20_random_uuid();
                        $store->query('INSERT INTO plugins.correspondence_companion_episode(playthrough_id,epoch,actor_id,episode_id,first_game_minute,native_first_game_minute,game_minute,sample_gamets,identity) VALUES($1,$2::uuid,$3::bigint,$4::uuid,$5::bigint,$5::bigint,$6::bigint,$7::numeric,$8::jsonb)',
                            [$scope, $epoch, (string)$id, $episodeIds[$id], (string)max(0, $minute - 2880), (string)$minute, (string)$gamets, correspondenceJson($identities[$id])]);
                    }
                });
                foreach ($actors as $id) {
                    $row = $store->query('SELECT identity::text AS identity FROM plugins.correspondence_companion_episode WHERE playthrough_id=$1 AND epoch=$2::uuid AND actor_id=$3::bigint', [$scope, $epoch, (string)$id]);
                    dev20_require(count($row) === 1 && json_decode($row[0]['identity'], true, 16, JSON_THROW_ON_ERROR) === $identities[$id],
                        'companion_physical_identity_snapshot_mismatch');
                }

                $calls = 0;
                $model = static function (array $messages) use (&$calls): string {
                    dev20_require(count($messages) === 2, 'companion_model_context_missing');
                    $calls++;
                    return '{"action":"letter","recipient":"player","body":"I am waiting for you. I hope you are well."}';
                };
                $owned = [];
                foreach ($actors as $index => $id) {
                    if ($index > 0) {
                        $gamets += 416667;
                        dev23_companion_advance_clock($store, $gamets, $scope, $epoch);
                    }
                    $tick = $companion->tick($model);
                    dev20_require(($tick['status'] ?? null) === 'queued' && ($tick['reason'] ?? null) === 'queued_for_transit',
                        'companion_actual_tick_did_not_queue');
                    $row = $store->query("SELECT document_id::text AS document_id,request_id::text AS request_id,playthrough_id,actor_id::text AS actor_id FROM plugins.correspondence_companion_decision WHERE playthrough_id=$1 AND epoch=$2::uuid AND actor_id=$3::bigint AND episode_id=$4::uuid AND status='queued'",
                        [$scope, $epoch, (string)$id, $episodeIds[$id]]);
                    dev20_require(count($row) === 1, 'companion_decision_link_missing');
                    $doc = $store->loadDocument($row[0]['document_id']);
                    dev20_require(is_array($doc) && ($doc['playthrough_id'] ?? null) === $scope
                        && ($doc['request_id'] ?? null) === $row[0]['request_id']
                        && ($row[0]['playthrough_id'] ?? null) === $scope && ($row[0]['actor_id'] ?? null) === (string)$id
                        && ($doc['author']['kind'] ?? null) === 'npc' && (int)($doc['author']['id'] ?? 0) === $id
                        && ($doc['payer']['kind'] ?? null) === 'npc' && (int)($doc['payer']['id'] ?? 0) === $id
                        && ($doc['claimed_sender']['kind'] ?? null) === 'npc' && (int)($doc['claimed_sender']['id'] ?? 0) === $id
                        && ($doc['recipient']['kind'] ?? null) === 'player' && ($doc['attachments'] ?? null) === [],
                        'companion_owner_document_contract_failed');
                    $owned[$id] = ['documentId' => $row[0]['document_id'], 'requestId' => $row[0]['request_id']];
                }
                dev20_require($calls === count($actors), 'companion_fixed_model_call_count_mismatch');
                return ['scope' => $scope, 'epoch' => $epoch, 'docs' => $owned, 'modelCalls' => $calls,
                    'providerCalls' => 0, 'identitySnapshots' => $identities, 'preparedEligibleEpisodes' => 3,
                    'actualPinnedTickCompose' => true, 'noGameObservationClaim' => true];
            });
            $docs = $made['docs'] ?? [];
            $scope = $made['scope'] ?? $scope;
            return $made + ['presenceExecutor' => $presence['presenceExecutor'],
                'samplingBoundary' => 'synthetic eligible episode rows; no poll or reply'];
        });

        if ($workflowReady) {
            $lifecycleReady = $record('companion_lifecycle_and_facade', static function () use ($ctx, $admin, &$docs, &$scope): array {
                dev20_require(count($docs) === 3 && is_string($scope), 'companion_workflow_missing');
                $queued = dev23_companion_read($ctx, $admin, 'companion_queued_projection', 'correspondence.reconcile',
                    ['operationId' => $docs[900003]['requestId']], ['authScopes' => ['compose']], false);
                dev20_require(($queued['data']['state'] ?? null) === 'queued'
                    && ($queued['data']['companionSchedulerOwned'] ?? null) === true
                    && ($queued['data']['operatorClosed'] ?? null) === false, 'companion_queued_projection_missing');
                $life = dev23_companion_with_owner_runtime($ctx, static function ($store, $service, $companion) use ($docs, $scope): array {
                    $refused = [];
                    foreach (['dispatch', 'retry'] as $method) {
                        try { $service->{$method}($docs[900003]['documentId']); throw new Dev18ScenarioFailure('companion_' . $method . '_allowed'); }
                        catch (DomainException $e) { dev20_require($e->getMessage() === 'companion_scheduler_owned', 'companion_' . $method . '_wrong_refusal'); $refused[] = $method; }
                    }
                    $service->close($docs[900001]['documentId']);
                    $row = $store->query("SELECT MAX(due_gamets)::text AS due FROM plugins.correspondence_companion_decision WHERE playthrough_id=$1 AND status='queued'", [$scope]);
                    dev20_require(count($row) === 1 && ctype_digit((string)($row[0]['due'] ?? '')), 'companion_due_clock_missing');
                    $gamets = (int)$row[0]['due'];
                    $epoch = correspondenceCompanionEpoch($store->restoreGeneration());
                    dev23_companion_advance_clock($store, $gamets, $scope, $epoch);
                    $ticks = [];
                    for ($i = 0; $i < 3; $i++) $ticks[] = $companion->tick(null);
                    dev20_require(($ticks[0]['status'] ?? null) === 'stale' && ($ticks[0]['reason'] ?? null) === 'operator_closed'
                        && ($ticks[1]['status'] ?? null) === 'dispatched' && ($ticks[2]['status'] ?? null) === 'dispatched',
                        'companion_close_or_dispatch_lifecycle_failed');
                    $stale = $store->query('SELECT status FROM plugins.correspondence_companion_decision WHERE document_id=$1::uuid', [$docs[900001]['documentId']]);
                    dev20_require(count($stale) === 1 && ($stale[0]['status'] ?? null) === 'stale', 'companion_closed_decision_not_stale');

                    $clock = $store->query('SELECT ts::text AS ts,gamets::text AS gamets FROM public.eventlog ORDER BY rowid DESC LIMIT 1');
                    $store->bindRequestClock([0 => 'inputtext', 1 => $clock[0]['ts'], 2 => $clock[0]['gamets']]);
                    $receiptStates = [];
                    foreach ([900002 => 'unknown', 900003 => 'delivered'] as $id => $status) {
                        $doc = $store->loadDocument($docs[$id]['documentId']);
                        $op = is_array($doc) && is_string($doc['operation_id'] ?? null) ? $store->loadOperation($doc['operation_id']) : null;
                        dev20_require(is_array($doc) && is_array($op), 'companion_operation_missing');
                        $physical = $store->physicalActor($doc['claimed_sender']);
                        $accepted = $service->receive([
                            'protocol_version' => 1, 'document_id' => $op['document_id'], 'operation_id' => $op['operation_id'],
                            'receipt_id' => $op['receipt_id'], 'playthrough_id' => $op['playthrough_id'],
                            'scope_token' => $op['scope_token'], 'nonce' => $op['nonce'], 'status' => $status,
                            'reason' => $status === 'delivered' ? 'delivered' : 'dispatch_unknown',
                            'note_form_id' => $status === 'delivered' ? $physical['refid'] : 0, 'attachments' => [],
                        ]);
                        dev20_require(($accepted['state'] ?? null) === $status, 'companion_service_receipt_not_accepted');
                        $receiptStates[$id] = $accepted['state'];
                    }
                    $service->close($docs[900002]['documentId']);
                    return ['manualDispatchRetryRefused' => $refused, 'closeBecameStale' => true,
                        'unknownReceiptAccepted' => $receiptStates[900002] === 'unknown',
                        'positiveNoAttachmentReceiptAccepted' => $receiptStates[900003] === 'delivered', 'providerCalls' => 0];
                });
                $closed = dev23_companion_read($ctx, $admin, 'companion_closed_projection', 'correspondence.reconcile',
                    ['operationId' => $docs[900001]['requestId']], ['authScopes' => ['compose']], false);
                dev20_require(($closed['data']['companionSchedulerOwned'] ?? null) === true
                    && ($closed['data']['operatorClosed'] ?? null) === true, 'companion_closed_metadata_missing');

                $unknown = dev23_companion_read($ctx, $admin, 'companion_unknown_projection', 'correspondence.reconcile',
                    ['operationId' => $docs[900002]['requestId']], ['authScopes' => ['compose']]);
                dev20_require(($unknown['data']['state'] ?? null) === 'unknown'
                    && ($unknown['data']['companionSchedulerOwned'] ?? null) === true
                    && ($unknown['data']['operatorClosed'] ?? null) === true, 'companion_unknown_close_projection_collapsed');

                $delivered = dev23_companion_read($ctx, $admin, 'companion_delivered_projection', 'correspondence.reconcile',
                    ['operationId' => $docs[900003]['requestId']], ['authScopes' => ['compose']]);
                dev20_require(($delivered['data']['state'] ?? null) === 'delivered'
                    && ($delivered['data']['companionSchedulerOwned'] ?? null) === true
                    && ($delivered['data']['operatorClosed'] ?? null) === false
                    && ($delivered['data']['receipt']['deliveryConfirmed'] ?? null) === true,
                    'companion_delivered_projection_missing');

                $mailbox = dev23_companion_read($ctx, $admin, 'companion_mailbox_projection', 'correspondence.mailbox.read',
                    ['recipientActorKey' => 'player', 'limit' => 50], ['authScopes' => ['inspector']]);
                $letter = null;
                foreach (($mailbox['data']['letters'] ?? []) as $candidate) {
                    if (($candidate['letterId'] ?? null) === $docs[900003]['documentId']) $letter = $candidate;
                }
                dev20_require(is_array($letter) && ($letter['claimedSignature'] ?? null) === 'Lydia'
                    && !array_key_exists('companionSchedulerOwned', $letter), 'companion_mailbox_projection_missing_or_expanded');

                $detail = dev23_companion_read($ctx, $admin, 'companion_detail_projection', 'correspondence.detail.read',
                    ['recipientActorKey' => 'player', 'letterId' => $docs[900003]['documentId']], ['authScopes' => ['inspector']]);
                dev20_require(($detail['data']['letterId'] ?? null) === $docs[900003]['documentId']
                    && ($detail['data']['deliveryConfirmed'] ?? null) === true
                    && is_string($detail['data']['body'] ?? null)
                    && !array_key_exists('companionSchedulerOwned', $detail['data']), 'companion_detail_projection_missing_or_expanded');
                return $life + ['closedUnknownAndDeliveredProjections' => true, 'mailboxAndDetail' => true, 'readOnly' => true];
            });

            if ($lifecycleReady) {
                $record('companion_owner_identity_mismatch_variants', static function () use ($ctx, $admin, &$docs): array {
                    $doc = $docs[900003] ?? [];
                    dev20_require(is_string($doc['documentId'] ?? null) && is_string($doc['requestId'] ?? null), 'companion_owner_doc_missing');
                    $rows = dev20_pg($admin, 'SELECT playthrough_id,request_id::text AS request_id,actor_id::text AS actor_id FROM plugins.correspondence_companion_decision WHERE document_id=$1::uuid', [$doc['documentId']]);
                    dev20_require(count($rows) === 1, 'companion_decision_row_missing');
                    $old = $rows[0];
                    $variants = [
                        ['label' => 'actor', 'sql' => 'UPDATE plugins.correspondence_companion_decision SET actor_id=$1::bigint WHERE document_id=$2::uuid', 'value' => '900001'],
                        ['label' => 'request', 'sql' => 'UPDATE plugins.correspondence_companion_decision SET request_id=$1::uuid WHERE document_id=$2::uuid', 'value' => dev20_random_uuid()],
                        ['label' => 'scope', 'sql' => 'UPDATE plugins.correspondence_companion_decision SET playthrough_id=$1 WHERE document_id=$2::uuid', 'value' => 'mismatched-playthrough'],
                    ];
                    $checked = [];
                    foreach ($variants as $variant) {
                        try {
                            dev20_pg($admin, $variant['sql'], [$variant['value'], $doc['documentId']]);
                            $read = dev23_companion_read($ctx, $admin, 'companion_identity_' . $variant['label'],
                                'correspondence.reconcile', ['operationId' => $doc['requestId']], ['authScopes' => ['compose']], false);
                            dev20_require(($read['data']['state'] ?? null) === 'unknown'
                                && ($read['data']['companionSchedulerOwned'] ?? null) === true
                                && ($read['data']['operatorClosed'] ?? null) === false
                                && !array_key_exists('receipt', $read['data']),
                                'companion_mismatched_owner_row_became_valid_or_unowned_' . $variant['label']);
                            $checked[] = $variant['label'];
                        } finally {
                            dev20_pg($admin,
                                'UPDATE plugins.correspondence_companion_decision SET playthrough_id=$1,request_id=$2::uuid,actor_id=$3::bigint WHERE document_id=$4::uuid',
                                [$old['playthrough_id'], $old['request_id'], $old['actor_id'], $doc['documentId']]);
                        }
                    }
                    return ['mismatchVariants' => $checked, 'identityNeverAuthorized' => true, 'readOnly' => true];
                });

                $record('companion_manual_player_payer_control', static function () use ($ctx, $admin): array {
                    $requestId = dev20_random_uuid();
                    $payload = dev20_compose_payload($requestId, [
                        'writerActorKey' => 'npc:900003',
                        'recipientActorKey' => 'npc:900002',
                        'signatureActorKey' => 'npc:900003',
                        'body' => 'A manually written letter carrying ordinary goods.',
                        'attachments' => [['form_id' => 'Skyrim.esm|00000001', 'quantity' => 1]],
                        'goldAmount' => 7,
                    ]);
                    $write = dev20_api_call($ctx, 'companion_manual_player_payer_write', 'correspondence.compose',
                        $payload, ['operationRequestId' => $requestId]);
                    dev20_simple_assert_response($write, true);
                    $rows = dev20_pg($admin,
                        'SELECT document_id::text AS document_id,payload::text AS payload FROM plugins.correspondence_documents WHERE request_id=$1::uuid',
                        [$requestId]);
                    dev20_require(count($rows) === 1, 'companion_manual_doc_missing');
                    $originalPayload = $rows[0]['payload'];
                    $document = json_decode($originalPayload, true, 64, JSON_THROW_ON_ERROR);
                    $kinds = array_column($document['attachments'] ?? [], 'kind');
                    dev20_require(($document['author']['kind'] ?? null) === 'npc' && (int)($document['author']['id'] ?? 0) === 900003
                        && ($document['claimed_sender']['kind'] ?? null) === 'npc' && (int)($document['claimed_sender']['id'] ?? 0) === 900003
                        && ($document['payer']['kind'] ?? null) === 'player' && ($document['recipient']['kind'] ?? null) === 'npc'
                        && in_array('item', $kinds, true) && in_array('gold', $kinds, true),
                        'companion_ordinary_manual_player_payer_control_failed');
                    $documentId = $rows[0]['document_id'];
                    $forged = $document;
                    $forged['payer'] = $forged['author'];
                    try {
                        dev20_pg($admin, 'UPDATE plugins.correspondence_documents SET payload=$1::jsonb WHERE document_id=$2::uuid',
                            [json_encode($forged, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $documentId]);
                        $read = dev23_companion_read($ctx, $admin, 'companion_forged_npc_payer', 'correspondence.reconcile',
                            ['operationId' => $requestId], ['authScopes' => ['compose']], false);
                        dev20_require(($read['data']['state'] ?? null) !== 'delivered'
                            && !array_key_exists('receipt', $read['data']), 'unowned_npc_payer_projected_as_delivered');
                    } finally {
                        dev20_pg($admin, 'UPDATE plugins.correspondence_documents SET payload=$1::jsonb WHERE document_id=$2::uuid',
                            [$originalPayload, $documentId]);
                    }
                    return ['ordinaryNpcWriterAndSignature' => true, 'payer' => 'player',
                        'itemAndGoldAttachments' => true, 'forgedNpcPayerWithheld' => true, 'providerCalls' => 0];
                });
            }
        }
    } finally {
        foreach ($originals as $id => $json) {
            dev20_pg($admin, 'UPDATE public.core_npc_master SET extended_data=$1::jsonb WHERE id=$2::bigint', [$json, (string)$id]);
        }
        if ($settingCaptured && is_string($scope) && $scope !== '') {
            if ($priorEnabled === null) dev20_pg($admin, 'DELETE FROM plugins.correspondence_autonomy_settings WHERE playthrough_id=$1', [$scope]);
            else dev20_pg($admin, 'UPDATE plugins.correspondence_autonomy_settings SET enabled=$1::boolean,updated_at=clock_timestamp() WHERE playthrough_id=$2',
                [$priorEnabled ? 'true' : 'false', $scope]);
        }
    }

    $record('companion_fixture_restore', static function () use ($admin, $actors, &$originals, &$scope, &$settingCaptured, &$priorEnabled): array {
        dev20_require(count($originals) === count($actors), 'companion_actor_restore_baseline_missing');
        foreach ($originals as $id => $expected) {
            $rows = dev20_pg($admin, 'SELECT extended_data::text AS data FROM public.core_npc_master WHERE id=$1::bigint', [(string)$id]);
            dev20_require(count($rows) === 1 && ($rows[0]['data'] ?? null) === $expected, 'companion_actor_flags_not_restored');
        }
        if ($settingCaptured && is_string($scope)) {
            $rows = dev20_pg($admin, 'SELECT enabled FROM plugins.correspondence_autonomy_settings WHERE playthrough_id=$1', [$scope]);
            if ($priorEnabled === null) dev20_require($rows === [], 'companion_autonomy_setting_not_removed');
            else dev20_require(count($rows) === 1
                && in_array($rows[0]['enabled'] ?? null, $priorEnabled ? [true, 't', 'true', '1', 1] : [false, 'f', 'false', '0', 0], true),
                'companion_autonomy_setting_not_restored');
        }
        return ['actorFlagsRestored' => true, 'autonomySettingRestored' => true];
    });

    $results = array_slice($ctx['results'], $start);
    $failed = array_values(array_filter($results, static fn(array $row): bool => ($row['status'] ?? null) !== 'passed'));
    return [
        'schema' => 'department.dev23.companion-cases.v1',
        'passed' => $failed === [] && count($results) === count($caseIds),
        'expectedCases' => count($caseIds),
        'executedCases' => count($results),
        'passedCases' => count($results) - count($failed),
        'failedCases' => $failed,
        'caseIds' => $caseIds,
        'providerCalls' => 0,
    ];
}

function dev23_companion_with_owner_runtime(array $ctx, callable $work): mixed
{
    require_once $ctx['serverRoot'] . DIRECTORY_SEPARATOR . 'api.php';
    $dependencies = qd_api_runtime_dependencies();
    $withProfileLease = $dependencies['withProfileLease'] ?? null;
    if (!is_callable($withProfileLease)) throw new RuntimeException('companion_profile_lease_unavailable');
    $ownerRoot = qd_correspondence_source_root();
    return $withProfileLease(static function () use ($ctx, $ownerRoot, $work): mixed {
        return qd_runtime_with_correspondence_lease($ownerRoot,
            static function (string $generation, mixed $lease, string $coreRoot, string $ownerRoot) use ($ctx, $work): mixed {
                return qd_runtime_with_correspondence_owner_lease($ownerRoot, $generation, $lease,
                    static function () use ($ctx, $coreRoot, $work): mixed {
                        qd_correspondence_load_sources();
                        correspondenceBeginRequestIngress([]);
                        return qd_runtime_with_owned_connection(
                            static fn(): mixed => correspondenceNativeConnection(),
                            static fn($connection): mixed => qd_correspondence_close_owned_pg_connection($connection),
                            static function ($connection) use ($ctx, $coreRoot, $work): mixed {
                                $store = new CorrespondencePgStore($connection,
                                    static fn($candidate): ?string => $candidate->observedExecutor(),
                                    static fn(string $title, string $body): bool => correspondencePrepareCoreNote($coreRoot, $title, $body),
                                    $coreRoot . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'playthrough_runtime' . DIRECTORY_SEPARATOR . 'correspondence.restore.json');
                                $store->ensureSchema();
                                correspondenceCaptureRequestIngress($store);
                                $service = new CorrespondenceService($store);
                                $autonomy = new CorrespondenceAutonomyPg($store, $service);
                                $autonomy->ensureSchema();
                                $companion = new CorrespondenceCompanionPg($store, $service);
                                $companion->ensureSchema();
                                require_once $coreRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_home.php';
                                $active = qd_runtime_active_playthrough($connection);
                                dev20_require(is_array($active) && isset($active['profileId']), 'companion_active_playthrough_missing');
                                return $work($store, $service, $companion);
                            });
                    });
            });
    });
}

function dev23_companion_advance_clock(CorrespondencePgStore $store, int $gamets, string $scope, string $epoch): void
{
    $store->transaction(static function () use ($store, $gamets, $scope, $epoch): void {
        $rows = $store->query('SELECT ts::text AS ts FROM public.eventlog ORDER BY rowid DESC LIMIT 1');
        if (count($rows) !== 1 || !ctype_digit((string)($rows[0]['ts'] ?? ''))) throw new RuntimeException('companion_test_clock_missing');
        $ts = max((int)$rows[0]['ts'] + 1, time());
        $minute = correspondenceCompanionGameMinute($gamets);
        $store->query("INSERT INTO public.eventlog(ts,gamets,type,data,sess,localts,people,location,party) VALUES($1::bigint,$2::numeric,'info','synthetic dev23 clock step','dev23_companion_test',$1::bigint,'','','')",
            [(string)$ts, (string)$gamets]);
        $store->query('UPDATE plugins.correspondence_companion_episode SET sample_gamets=$1::numeric,game_minute=$2::bigint WHERE playthrough_id=$3 AND epoch=$4::uuid',
            [(string)$gamets, (string)$minute, $scope, $epoch]);
    });
}

function dev23_companion_read(array $ctx, mixed $admin, string $caseId, string $operation, array $payload, array $options, bool $physicalMapping = true): array
{
    $before = dev20_owner_snapshot($admin);
    $offset = dev20_case_log_start($ctx);
    $result = dev20_api_call($ctx, $caseId, $operation, $payload, $options);
    $response = dev20_simple_assert_response($result, true);
    $fence = dev20_read_fence_evidence($ctx, (string)$result['app'], $offset);
    if ($physicalMapping) dev20_assert_single_read_fence($fence);
    else dev20_assert_read_transaction_shape($fence);
    dev20_require(dev20_unchanged($before, dev20_owner_snapshot($admin)), 'companion_read_mutated_rows');
    dev20_require_no_owner_mutation_sql($result, 'companion_read_emitted_owner_write');
    return ['data' => $response['data'] ?? [], 'readFence' => $fence];
}
