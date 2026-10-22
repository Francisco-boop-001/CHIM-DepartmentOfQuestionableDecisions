<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';
require_once __DIR__ . '/state.php';
require_once __DIR__ . '/scope.php';
require_once __DIR__ . '/reflection_receipt.php';

const PCV_REFLECTION_REGISTRY_MAX_BYTES = 8192;
const PCV_REFLECTION_REGISTRY_TTL = 600;
// Direct ACKs keep the full lifetime until a newer output supersedes an unclaimed registration.
const PCV_REFLECTION_REGISTERED_SUPERSESSION_TTL = 60;
// Mind Poisoning 0.1.16 accepts up to 24 reply lines (0.1.15: 8); its own declared cap wins when lower.
const PCV_REFLECTION_REPLY_MAX_LINES = 24;
const PCV_REFLECTION_REPLY_SCAN_ROWS = 128;

function pcv_reflection_reply_max_lines(): int
{
    $mindPoisoningCap = defined('ChimMindPoisoning\\MIND_POISONING_REFLECTION_REPLY_MAX_LINES')
        ? constant('ChimMindPoisoning\\MIND_POISONING_REFLECTION_REPLY_MAX_LINES') : 8;
    return is_int($mindPoisoningCap) && $mindPoisoningCap > 0
        ? min(PCV_REFLECTION_REPLY_MAX_LINES, $mindPoisoningCap) : 8;
}

function pcvReflectionRegisterLastOutput(array $requestScope): void
{
    if (($requestScope['route'] ?? null) !== 'solo_reflection') {
        return;
    }
    try {
        $mindPoisoningAvailable = pcv_reflection_load_mind_poisoning();
    } catch (Throwable $error) {
        pcv_reflection_log('reflection.registration_error', 'registration', 'internal_error', $requestScope, $error);
        return;
    }
    if (!$mindPoisoningAvailable) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', pcv_reflection_mind_poisoning_unavailable_reason(), $requestScope);
        return;
    }

    try {
        $store = new \ChimMindPoisoning\PostgresStoreDb();
        pcv_reflection_register_with_store($requestScope, $store);
    } catch (Throwable $error) {
        pcv_reflection_log('reflection.registration_error', 'registration', 'database_unavailable', $requestScope, $error);
    }
}

function pcvReflectionEvaluateAck(array $gameRequest): void
{
    $tuple = pcv_reflection_ack_tuple($gameRequest);
    if ($tuple === null) {
        return;
    }
    // Cheap gate: only a stored active solo scene (catalog ID) can make this ACK relevant.
    $cheapScope = pcv_reflection_active_solo_precheck();
    if ($cheapScope === null) {
        return;
    }
    // Other lines (bystanders, the actor's ordinary dialogue) stay quiet; only the registered reflection line is reported.
    $isRegisteredLine = static function () use ($tuple): bool {
        $registered = pcv_reflection_registry_probe();
        return $registered['kind'] === 'ready'
            && $registered['record']['registration']['utterance_id'] === $tuple['utterance_id'];
    };
    $scope = pcv_reflection_current_solo_scope();
    if ($scope === null || ($scope['config_id'] ?? null) !== $cheapScope['config_id']
        || ($scope['pcv_key'] ?? null) !== $cheapScope['pcv_key']
        || !is_string($scope['actor_a_id'] ?? null) || $scope['actor_a_id'] !== $cheapScope['actor_id']) {
        if ($isRegisteredLine()) {
            pcv_reflection_log('reflection.ack_skipped', 'ack', 'scope_changed', $cheapScope);
        }
        return;
    }
    if (pcv_scope_name_key($tuple['speaker']) !== pcv_scope_name_key((string)($scope['scope']['actor_a'] ?? ''))) {
        return;
    }

    $ackGeneration = null;
    if (!pcv_reflection_capture_interaction_generation($ackGeneration)) {
        pcv_reflection_log('reflection.ack_skipped', 'ack', 'interaction_stale', $cheapScope);
        return;
    }
    if (!pcv_reflection_ack_matches_solo_scope($gameRequest, $scope)) {
        if ($isRegisteredLine()) {
            pcv_reflection_log('reflection.ack_skipped', 'ack', 'ack_mismatch', $scope);
        }
        return;
    }
    $utteranceId = $tuple['utterance_id'];
    $ackMetadata = [
        'created_at' => time(),
        'ack_generation' => $ackGeneration,
        'tuple_digest' => pcv_reflection_ack_tuple_digest($tuple),
    ];
    $probe = pcv_reflection_registry_probe();
    if ($probe['kind'] === 'ready' && pcv_reflection_is_non_final_line($probe['record'], $utteranceId)) {
        return; // An earlier line of a registered full reply; only the final line's ACK triggers evaluation.
    }
    if ($probe['kind'] === 'ready' && $probe['record']['registration']['utterance_id'] === $utteranceId) {
        if ($probe['record']['status'] !== 'registered') {
            pcv_reflection_log('reflection.ack_skipped', 'ack', 'claim_taken', $probe['record']);
            return;
        }
        if (!pcv_reflection_record_fresh($probe['record'])) {
            pcv_reflection_log('reflection.ack_skipped', 'ack', 'registration_stale', $probe['record']);
            return;
        }
        try {
            $mindPoisoningAvailable = pcv_reflection_load_mind_poisoning();
        } catch (Throwable $error) {
            pcv_reflection_log('reflection.ack_error', 'ack', 'internal_error', $probe['record'], $error);
            return;
        }
        if (!$mindPoisoningAvailable) {
            pcv_reflection_log('reflection.ack_error', 'ack', pcv_reflection_mind_poisoning_unavailable_reason(), $probe['record']);
            return;
        }

        try {
            $store = new \ChimMindPoisoning\PostgresStoreDb();
            pcv_reflection_evaluate_with_store($gameRequest, $store, null, null, null, null, $ackMetadata);
        } catch (Throwable $error) {
            pcv_reflection_log('reflection.ack_error', 'ack', 'database_unavailable', $probe['record'], $error);
        }
        return;
    }

    // An ACK for a line older than the registered final line can never trigger evaluation. Ignore it quietly
    // instead of parking a receipt: earlier lines used to fill the receipt map and log registration_missing.
    if ($probe['kind'] === 'ready' && pcv_reflection_record_fresh($probe['record'])) {
        try {
            if (pcv_reflection_load_mind_poisoning()
                && pcv_reflection_ack_predates_registration(new \ChimMindPoisoning\PostgresStoreDb(), $probe['record'], $utteranceId)) {
                return;
            }
        } catch (Throwable) {
            // Fall through to the existing receipt path.
        }
    }

    $storedReceipt = pcv_reflection_store_ack_receipt($tuple, $scope, $ackGeneration);
    if ($storedReceipt['kind'] !== 'ready') {
        $event = in_array($storedReceipt['kind'], ['invalid', 'unavailable'], true)
            ? 'reflection.ack_error' : 'reflection.ack_skipped';
        $reason = match ($storedReceipt['kind']) {
            'invalid' => 'receipt_corrupt',
            'unavailable' => 'receipt_unavailable',
            'conflict' => 'ack_conflict',
            'interaction_stale' => 'interaction_stale',
            'scope_changed' => 'scope_changed',
            default => 'receipt_busy',
        };
        pcv_reflection_log($event, 'ack', $reason, $scope);
        return;
    }
    $receipt = $storedReceipt['receipt'];
    if ($probe['kind'] === 'missing'
        || ($probe['kind'] === 'ready' && $probe['record']['registration']['utterance_id'] !== $utteranceId)) {
        // The real client ACKs early lines while CHIM is still generating; registration comes at postrequest.
        $inFlight = pcvReflectionEarlyAckPending($probe, $utteranceId, function_exists('pcv_solo_inflight_matches')
            && is_string($scope['config_id'] ?? null) && is_string($scope['actor_a_id'] ?? null)
            && pcv_solo_inflight_matches($scope['config_id'], $scope['actor_a_id']));
        pcv_reflection_log($inFlight ? 'reflection.ack_pending' : 'reflection.ack_skipped', 'ack',
            $inFlight ? 'reply_in_progress' : 'registration_missing', $scope);
        pcvReflectionQueueAckReconciliation($gameRequest, $receipt);
        return;
    }
    if ($probe['kind'] === 'invalid' || $probe['kind'] === 'unavailable') {
        pcv_reflection_log('reflection.ack_error', 'ack', $probe['kind'] === 'invalid' ? 'registry_corrupt' : 'registry_unavailable');
        pcvReflectionQueueAckReconciliation($gameRequest, $receipt);
    }
}

function pcv_reflection_active_solo_precheck(?string $stateDirectory = null): ?array
{
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, false, LOCK_SH);
        if ($handle === null) {
            return null;
        }
        try {
            $loaded = pcv_load_store($directory);
            return $loaded['kind'] === 'ready'
                ? pcv_reflection_active_solo_from_state($loaded['state'] ?? [])
                : null;
        } finally {
            pcv_unlock_state($handle);
        }
    } catch (Throwable) {
        return null;
    }
}

function pcv_reflection_active_solo_from_state(array $state): ?array
{
    $active = $state['active'] ?? null;
    $config = is_array($active) ? ($active['config'] ?? null) : null;
    if (!is_array($active) || !is_array($config)
        || !pcv_valid_key($state['key'] ?? '') || ($config['enabled'] ?? null) !== true
        || ($config['scene_mode'] ?? null) !== 'solo' || ($config['actor_b'] ?? null) !== null
        || ($config['exclude_player'] ?? null) !== true
        || !is_int($active['expires_at'] ?? null) || $active['expires_at'] <= time()
        || !is_string($active['config_id'] ?? null) || !pcv_log_valid_uuid($active['config_id'])
        || !is_string($config['actor_a'] ?? null) || preg_match('/\A[1-9][0-9]*\z/D', $config['actor_a']) !== 1) {
        return null;
    }
    // Stored state holds catalog IDs, never display names; names are resolved from the live catalog later.
    return [
        'pcv_key' => $state['key'],
        'config_id' => $active['config_id'],
        'actor_id' => $config['actor_a'],
    ];
}

function pcv_reflection_capture_interaction_generation(?int &$generation): bool
{
    $generation = null;
    try {
        $reason = null;
        if (function_exists('ChimMindPoisoning\\speechAckInteractionStatus')) {
            if (\ChimMindPoisoning\speechAckInteractionStatus($reason) !== 'ok') {
                return false;
            }
        } elseif (function_exists('chimInteractionBegin')) {
            chimInteractionBegin();
        }
        $requestGeneration = $GLOBALS['chim_interaction_generation'] ?? null;
        if (!is_int($requestGeneration) || !function_exists('chimInteractionState')) {
            return false;
        }
        $state = chimInteractionState();
        if (!is_array($state) || ($state['enabled'] ?? null) !== true
            || !is_int($state['generation'] ?? null) || $state['generation'] !== $requestGeneration) {
            return false;
        }
        $generation = $requestGeneration;
        return true;
    } catch (Throwable) {
        return false;
    }
}

function pcv_reflection_interaction_epochs_match(int $sourceGeneration, int $ackGeneration): bool
{
    try {
        if (!function_exists('chimInteractionState')) {
            return false;
        }
        $state = chimInteractionState();
        return is_array($state) && ($state['enabled'] ?? null) === true
            && is_int($state['generation'] ?? null)
            && $state['generation'] === $sourceGeneration
            && $state['generation'] === $ackGeneration;
    } catch (Throwable) {
        return false;
    }
}

function pcvReflectionQueueAckReconciliation(array $gameRequest, array $receipt): void
{
    register_shutdown_function(static function () use ($gameRequest, $receipt): void {
        try {
            pcvReflectionReconcileAckReceipt($gameRequest, $receipt);
        } catch (Throwable $error) {
            pcv_reflection_log('reflection.ack_error', 'ack', 'internal_error', $receipt, $error);
        }
    });
}

function pcv_reflection_current_solo_scope(): ?array
{
    $scope = pcv_reflection_fresh_scope(null);
    $resolved = is_array($scope) ? ($scope['scope'] ?? null) : null;
    if (!is_array($scope) || ($scope['status'] ?? null) !== 'active' || !is_array($resolved)
        || ($resolved['scene_mode'] ?? null) !== 'solo' || ($resolved['actor_b'] ?? null) !== null
        || ($resolved['exclude_player'] ?? null) !== true || !is_string($resolved['actor_a'] ?? null)
        || trim($resolved['actor_a']) === '' || !is_string($scope['config_id'] ?? null)
        || !pcv_log_valid_uuid($scope['config_id']) || !pcv_log_valid_actor_id($scope['actor_a_id'] ?? null)) {
        return null;
    }
    return $scope;
}

function pcv_reflection_ack_matches_solo_scope(array $gameRequest, array $scope): bool
{
    $tuple = pcv_reflection_ack_tuple($gameRequest);
    if (!is_array($tuple)
        || pcv_scope_name_key($tuple['speaker']) !== pcv_scope_name_key((string)($scope['scope']['actor_a'] ?? ''))) {
        return false;
    }
    $playerName = function_exists('pcv_current_player_name') ? pcv_current_player_name() : null;
    foreach ([$playerName, 'Player', 'the Player', 'Dragonborn', 'the Dragonborn'] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== ''
            && pcv_scope_name_key($tuple['listener']) === pcv_scope_name_key($candidate)) {
            return true;
        }
    }
    return false;
}

function pcvReflectionRevalidate(array $registration, string $phase, string $claimToken): bool
{
    return pcv_reflection_revalidate($registration, $phase, $claimToken, null, null);
}

function pcv_reflection_load_mind_poisoning(): bool
{
    if (pcv_reflection_mind_poisoning_api_compatible()) {
        return true;
    }
    $path = dirname(__DIR__, 2) . '/ext/mind_poisoning/reflection.php';
    if (is_link($path) || !is_file($path)) {
        return false;
    }
    require_once $path;
    return pcv_reflection_mind_poisoning_api_compatible();
}

function pcv_reflection_mind_poisoning_api_compatible(): bool
{
    return defined('ChimMindPoisoning\\MIND_POISONING_REFLECTION_API_VERSION')
        && constant('ChimMindPoisoning\\MIND_POISONING_REFLECTION_API_VERSION') === 1
        && function_exists('ChimMindPoisoning\\mindPoisoningEvaluateReflection')
        && function_exists('ChimMindPoisoning\\reflectionSourceParts')
        && class_exists('ChimMindPoisoning\\PostgresStoreDb');
}

/** Mind Poisoning's opt-in full-reply evaluator (v2); v1 stays the fallback. */
function pcv_reflection_mind_poisoning_reply_api_compatible(): bool
{
    return defined('ChimMindPoisoning\\MIND_POISONING_REFLECTION_REPLY_API_VERSION')
        && constant('ChimMindPoisoning\\MIND_POISONING_REFLECTION_REPLY_API_VERSION') === 2
        && function_exists('ChimMindPoisoning\\mindPoisoningEvaluateReflectionReply')
        && function_exists('ChimMindPoisoning\\chatSourceParts')
        && function_exists('ChimMindPoisoning\\reflectionSourceParts');
}

function pcv_reflection_mp_evaluator(array $registration): string
{
    return array_key_exists('lines', $registration)
        ? 'ChimMindPoisoning\\mindPoisoningEvaluateReflectionReply'
        : 'ChimMindPoisoning\\mindPoisoningEvaluateReflection';
}

/** True when the ACK's eventlog row precedes the registered final line (an earlier line of that reply or older). */
function pcv_reflection_ack_predates_registration(\ChimMindPoisoning\StoreDb $store, array $record, string $utteranceId): bool
{
    $registeredEventId = $record['registration']['event_id'] ?? null;
    if (!is_int($registeredEventId) || $utteranceId === ($record['registration']['utterance_id'] ?? null)) {
        return false;
    }
    try {
        $event = $store->acknowledgedEvent($utteranceId);
    } catch (Throwable) {
        return false;
    }
    return is_array($event) && is_int($event['event_id'] ?? null) && $event['event_id'] < $registeredEventId;
}

/** True when the ACK is for an earlier registered line of a full reply (only the final line triggers). */
function pcv_reflection_is_non_final_line(array $record, string $utteranceId): bool
{
    $lines = $record['registration']['lines'] ?? null;
    if (!is_array($lines) || count($lines) < 2) {
        return false;
    }
    foreach (array_slice($lines, 0, -1) as $line) {
        if (is_array($line) && ($line['utterance_id'] ?? null) === $utteranceId) {
            return true;
        }
    }
    return false;
}

/**
 * Prove which emitted lines belong to this request's solo reply. The window starts at this request's own
 * `instruction` row (same client ts) and ends at the registered final line; any other request inside it, an
 * actor line to someone else, an aborted line or more than the cap means grouping is unproven (null), and the
 * caller keeps the single final-line registration. Digests use Mind Poisoning's own source parser.
 */
function pcv_reflection_reply_lines(
    \ChimMindPoisoning\StoreDb $store,
    string $actorName,
    int $finalEventId,
    string $finalUtteranceId,
    string $requestTs,
    ?callable $rowsReader = null
): ?array {
    try {
        $window = $rowsReader !== null
            ? $rowsReader($requestTs, $finalEventId)
            : pcv_reflection_read_reply_rows($requestTs, $finalEventId);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($window) || ($window['anchor_count'] ?? null) !== 1 || !is_array($window['rows'] ?? null)
        || !array_is_list($window['rows']) || $window['rows'] === []
        || count($window['rows']) > PCV_REFLECTION_REPLY_SCAN_ROWS) {
        return null;
    }
    $requestTypes = ['instruction', 'user_input', 'inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s',
        'rechat', 'continue', 'continue_group'];
    $lines = [];
    $previousRowId = 0;
    try {
        foreach ($window['rows'] as $row) {
            $rowId = is_array($row) ? ($row['rowid'] ?? null) : null;
            $type = is_array($row) ? ($row['type'] ?? null) : null;
            if (!is_int($rowId) || $rowId <= $previousRowId || $rowId > $finalEventId || !is_string($type)
                || in_array($type, $requestTypes, true)) {
                return null;
            }
            $previousRowId = $rowId;
            $utteranceId = $row['utterance_id'] ?? null;
            if ($type !== 'chat' || !is_string($utteranceId) || $utteranceId === '') {
                continue;
            }
            $event = $store->acknowledgedEvent($utteranceId);
            if (!is_array($event) || ($event['event_id'] ?? null) !== $rowId
                || ($event['utterance_id'] ?? null) !== $utteranceId || !is_string($event['source_data'] ?? null)) {
                return null;
            }
            $parts = \ChimMindPoisoning\chatSourceParts($event['source_data']);
            if (!is_array($parts) || !is_string($parts['speaker'] ?? null)) {
                return null;
            }
            if (pcv_scope_name_key($parts['speaker']) !== pcv_scope_name_key($actorName)) {
                continue; // another NPC's line interleaved with this reply
            }
            $reflection = \ChimMindPoisoning\reflectionSourceParts($event['source_data']);
            if (!is_array($reflection) || !is_string($reflection['text'] ?? null)
                || !in_array($event['delivery_state'] ?? null, ['emitted', 'spoken'], true)) {
                return null;
            }
            $lines[] = ['event_id' => $rowId, 'utterance_id' => $utteranceId, 'speech_hash' => hash('sha256', $reflection['text'])];
            if (count($lines) > pcv_reflection_reply_max_lines()) {
                return null;
            }
        }
    } catch (Throwable) {
        return null;
    }
    $final = $lines === [] ? null : $lines[array_key_last($lines)];
    return is_array($final) && $final['event_id'] === $finalEventId && $final['utterance_id'] === $finalUtteranceId
        ? $lines : null;
}

/** Read this request's reply window from CHIM's eventlog: unique instruction anchor, then rows up to the final line. */
function pcv_reflection_read_reply_rows(string $requestTs, int $finalEventId): ?array
{
    if (preg_match('/\A[1-9][0-9]{0,19}\z/D', $requestTs) !== 1 || $finalEventId < 1) {
        return null;
    }
    $connection = null;
    try {
        $connection = pcv_reflection_open_native_connection();
        if ($connection === null || $connection === false) {
            return null;
        }
        $anchors = @pg_query_params($connection,
            "SELECT rowid FROM public.eventlog WHERE type = 'instruction' AND ts::text = $1 AND rowid < $2 ORDER BY rowid DESC LIMIT 2",
            [$requestTs, $finalEventId]);
        if ($anchors === false) {
            return null;
        }
        $anchorRows = pg_fetch_all($anchors) ?: [];
        if (count($anchorRows) !== 1) {
            return ['anchor_count' => count($anchorRows), 'rows' => []];
        }
        $result = @pg_query_params($connection,
            'SELECT rowid, type, utterance_id FROM public.eventlog WHERE rowid > $1 AND rowid <= $2 ORDER BY rowid LIMIT '
                . (PCV_REFLECTION_REPLY_SCAN_ROWS + 1),
            [(int)$anchorRows[0]['rowid'], $finalEventId]);
        if ($result === false) {
            return null;
        }
        $rows = [];
        foreach (pg_fetch_all($result) ?: [] as $row) {
            $rows[] = [
                'rowid' => (int)$row['rowid'],
                'type' => (string)$row['type'],
                'utterance_id' => $row['utterance_id'] === null ? null : (string)$row['utterance_id'],
            ];
        }
        return ['anchor_count' => 1, 'rows' => $rows];
    } catch (Throwable) {
        return null;
    } finally {
        if ($connection !== null && $connection !== false && function_exists('pg_close')) {
            @pg_close($connection);
        }
    }
}

function pcv_reflection_mind_poisoning_unavailable_reason(): string
{
    $path = dirname(__DIR__, 2) . '/ext/mind_poisoning/reflection.php';
    if (is_link($path) || !is_file($path)) {
        return 'mind_poisoning_unavailable';
    }
    if (!pcv_reflection_mind_poisoning_api_compatible()) {
        return 'reflection_api_incompatible';
    }
    return 'mind_poisoning_unavailable';
}

function pcv_reflection_log(
    string $event,
    string $phase,
    string $reason,
    ?array $scope = null,
    ?Throwable $error = null
): void {
    if (!function_exists('pcv_log_event')) {
        return;
    }
    $configId = is_string($scope['config_id'] ?? null) && pcv_log_valid_uuid($scope['config_id'])
        ? $scope['config_id'] : null;
    pcv_log_set_config_id($configId);
    if (is_array($scope) && pcv_reflection_valid_record($scope)) {
        $registration = $scope['registration'];
        pcv_log_set_correlation([
            'config_id' => $scope['config_id'],
            'event_id' => (string)$registration['event_id'],
            'utterance_id' => $registration['utterance_id'],
        ]);
    } else {
        pcv_log_set_correlation([]);
    }
    $actorId = $scope['actor_a_id'] ?? $scope['actor_id'] ?? ($scope['registration']['actor_id'] ?? null);
    if (!is_string($actorId) && is_int($actorId) && $actorId > 0) {
        $actorId = (string)$actorId;
    }
    $context = ['phase' => $phase, 'route' => 'solo_reflection'];
    if (pcv_log_valid_actor_id($actorId)) {
        $context['actor_a_id'] = $actorId;
    }
    if ($event === 'reflection.registration_error' || $event === 'reflection.ack_error') {
        if ($error !== null) {
            pcv_log_exception($event, 'error', 'failed', $reason, $error, $context);
        } else {
            pcv_log_event($event, 'error', 'failed', $reason, $context);
        }
        return;
    }
    if ($event === 'reflection.output_registered') {
        pcv_log_event($event, 'info', 'accepted', null, $context);
        return;
    }
    if ($event === 'reflection.evaluation_finished') {
        pcv_log_event($event, 'info', 'accepted', null, $context);
        return;
    }
    if ($event === 'reflection.observer_unavailable') {
        pcv_log_event($event, 'info', 'unavailable', $reason, $context);
        return;
    }
    if ($event === 'reflection.ack_pending') {
        pcv_log_event($event, 'debug', 'skipped', $reason, $context);
        return;
    }
    pcv_log_event($event, 'info', 'skipped', $reason, $context);
}

function pcv_reflection_attach_mp_observer(object $requestLog): bool
{
    if (!method_exists($requestLog, 'observe')) {
        return false;
    }
    try {
        $requestLog->observe(static function (array $record, string $level): void {
            try {
                pcv_log_import_mp_record($record, $level);
            } catch (Throwable) {
                // Diagnostics must never alter Mind Poisoning's evaluation or persistence.
            }
        });
        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * 0.1.15 (L6): an ACK that arrives while this scene's reply is still being generated is "reply in progress",
 * whether the registry is empty or still holds an older, finished registration for another utterance.
 */
function pcvReflectionEarlyAckPending(array $probe, string $utteranceId, bool $inFlightMarkerMatches): bool
{
    if (!$inFlightMarkerMatches) {
        return false;
    }
    if (($probe['kind'] ?? null) === 'missing') {
        return true;
    }
    return ($probe['kind'] ?? null) === 'ready'
        && ($probe['record']['registration']['utterance_id'] ?? null) !== $utteranceId;
}

/** $failure (0.1.13): 'identity_changed' when no matching scope exists, 'scope_unavailable' when reading it failed. */
function pcv_reflection_fresh_scope(?callable $reader, ?string &$failure = null): ?array
{
    $failure = 'identity_changed';
    try {
        if ($reader !== null) {
            $scope = $reader();
            if (is_array($scope)) {
                $failure = null;
            }
            return is_array($scope) ? $scope : null;
        }
        $identity = pcv_current_identity(true);
        $key = $identity['key'] ?? null;
        if (!is_string($key) || !pcv_valid_key($key)) {
            return null;
        }
        $scope = pcvReadResolvedScope();
        if (!is_array($scope)) {
            return null;
        }
        $scope['pcv_key'] = $key;
        $failure = null;
        return $scope;
    } catch (Throwable $error) {
        $failure = 'scope_unavailable';
        pcv_log_exception('state.unavailable', 'error', 'unavailable', 'catalog_unavailable', $error, ['operation' => 'read']);
        return null;
    }
}

function pcv_reflection_output_parts(mixed $output, string $actorName, string $utteranceId): ?array
{
    if (!is_string($output) || strlen($output) > 16384 || preg_match('//u', $output) !== 1 || !str_ends_with($output, "\r\n")) {
        return null;
    }
    $output = substr($output, 0, -2);
    if (str_contains($output, "\r") || str_contains($output, "\n")) {
        return null;
    }
    $wire = explode('|', $output);
    if (count($wire) !== 3 || $wire[0] !== $actorName || $wire[1] !== 'ScriptQueue') {
        return null;
    }
    $fields = explode('/', $wire[2]);
    if (count($fields) !== 8
        || trim($fields[0]) === '' || strlen($fields[0]) > 12000 || preg_match('//u', $fields[0]) !== 1
        || $fields[2] !== 'explicit_disable_rechat'
        || $fields[6] !== 'explicit_disable_rechat'
        || $fields[7] !== $utteranceId) {
        return null;
    }
    return ['subtitle' => trim($fields[0])];
}

function pcv_reflection_scope_matches(array $record, array $scope): bool
{
    $registration = $record['registration'] ?? null;
    $resolved = $scope['scope'] ?? null;
    return is_array($registration)
        && ($scope['status'] ?? null) === 'active'
        && is_string($scope['pcv_key'] ?? null)
        && is_string($record['pcv_key'] ?? null)
        && hash_equals($record['pcv_key'], $scope['pcv_key'])
        && ($scope['config_id'] ?? null) === $record['config_id']
        && ($scope['actor_a_id'] ?? null) === (string)$record['actor_id']
        && is_array($resolved)
        && ($resolved['scene_mode'] ?? null) === 'solo'
        && ($resolved['actor_b'] ?? null) === null
        && ($resolved['exclude_player'] ?? null) === true
        && is_string($resolved['actor_a'] ?? null)
        && hash_equals($record['actor_name'], $resolved['actor_a'])
        && $registration['actor_id'] === $record['actor_id']
        && $registration['actor_name'] === $record['actor_name']
        && $registration['config_id'] === $record['config_id'];
}

function pcv_reflection_request_is_eligible(array $requestScope): bool
{
    return function_exists('pcvSoloReflectionRequest')
        && pcvSoloReflectionRequest($requestScope)
        && function_exists('pcvRequestScopeModeMatches')
        && pcvRequestScopeModeMatches($requestScope)
        && is_string($requestScope['config_id'] ?? null)
        && pcv_log_valid_uuid($requestScope['config_id'])
        && pcv_log_valid_actor_id((string)($requestScope['actor_a_id'] ?? ''))
        && is_string($requestScope['scope']['actor_a'] ?? null)
        && trim($requestScope['scope']['actor_a']) !== ''
        && ($requestScope['scope']['actor_b'] ?? null) === null
        && ($requestScope['scope']['scene_mode'] ?? null) === 'solo'
        && ($requestScope['scope']['exclude_player'] ?? null) === true;
}

function pcv_reflection_register_with_store(
    array $requestScope,
    \ChimMindPoisoning\StoreDb $store,
    ?string $stateDirectory = null,
    ?callable $freshScopeReader = null,
    ?callable $nativeAckReader = null,
    ?callable $requestModel = null,
    ?callable $replyRowsReader = null
): string {
    if (($requestScope['route'] ?? null) !== 'solo_reflection') {
        return 'not_applicable';
    }
    if (!pcv_reflection_request_is_eligible($requestScope)) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', 'scope_ineligible', $requestScope);
        return 'scope_ineligible';
    }
    $sourceGeneration = $GLOBALS['chim_interaction_generation'] ?? null;
    if (!is_int($sourceGeneration) || $sourceGeneration < 0
        || !pcv_reflection_interaction_epochs_match($sourceGeneration, $sourceGeneration)) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', 'interaction_stale', $requestScope);
        return 'interaction_stale';
    }

    $baselineId = $requestScope['baseline_utterance_id'] ?? null;
    $utteranceId = $GLOBALS['SCRIPTLINE_UTTERANCE_ID'] ?? null;
    $baselineOutput = $requestScope['baseline_output_log'] ?? null;
    $output = is_array($GLOBALS['DEBUG_DATA'] ?? null) ? ($GLOBALS['DEBUG_DATA']['OUTPUT_LOG'] ?? null) : null;
    if (!array_key_exists('baseline_utterance_id', $requestScope)
        || !is_string($baselineOutput) || strlen($baselineOutput) > 16384 || !is_string($output)) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', 'output_unavailable', $requestScope);
        return 'output_unavailable';
    }
    if (!is_string($utteranceId)
        || preg_match('/\\Autt_[A-Za-z0-9_-]{8,128}\\z/D', $utteranceId) !== 1
        || ($baselineId !== null && (!is_string($baselineId)
            || preg_match('/\\Autt_[A-Za-z0-9_-]{8,128}\\z/D', $baselineId) !== 1
            || $utteranceId === $baselineId))) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', 'baseline_stale', $requestScope);
        return 'baseline_stale';
    }
    if (strlen($output) > 16384 || trim($output) === '' || preg_match('//u', $output) !== 1) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', 'output_malformed', $requestScope);
        return 'output_malformed';
    }
    if (hash_equals($baselineOutput, $output)) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', 'baseline_stale', $requestScope);
        return 'baseline_stale';
    }
    if (($GLOBALS['CHIM_EXECUTION_MODE'] ?? null) !== 'STANDARD'
        || !is_string($GLOBALS['HERIKA_NAME'] ?? null)
        || !hash_equals($requestScope['scope']['actor_a'], $GLOBALS['HERIKA_NAME'])) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', 'scope_ineligible', $requestScope);
        return 'scope_ineligible';
    }

    $freshScope = pcv_reflection_fresh_scope($freshScopeReader, $scopeFailure);
    if (!is_array($freshScope)) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', $scopeFailure ?? 'identity_changed', $requestScope);
        return $scopeFailure ?? 'identity_changed';
    }
    $actorId = filter_var($requestScope['actor_a_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($actorId === false) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', 'scope_ineligible', $requestScope);
        return 'scope_ineligible';
    }
    $wire = pcv_reflection_output_parts($output, $requestScope['scope']['actor_a'], $utteranceId);
    if ($wire === null) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', 'output_malformed', $requestScope);
        return 'output_malformed';
    }
    $expectedRecord = [
        'version' => 2,
        'pcv_key' => $freshScope['pcv_key'] ?? null,
        'config_id' => $requestScope['config_id'],
        'actor_id' => (int)$actorId,
        'actor_name' => $requestScope['scope']['actor_a'],
        'origin_request_type' => $requestScope['origin_request_type'],
        'origin_mode' => 'STANDARD',
        'route' => 'solo_reflection',
        'created_at' => time(),
        'status' => 'registered',
        'claim_token' => null,
        'source_generation' => $sourceGeneration,
        'ack_receipt' => null,
        'registration' => null,
    ];
    $scopeRecord = $expectedRecord;
    $scopeRecord['registration'] = [
        'actor_id' => $expectedRecord['actor_id'],
        'actor_name' => $expectedRecord['actor_name'],
        'config_id' => $expectedRecord['config_id'],
    ];
    if (!is_string($expectedRecord['pcv_key'] ?? null) || !pcv_valid_key($expectedRecord['pcv_key'])
        || !pcv_reflection_scope_matches($scopeRecord, $freshScope)) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', 'scope_changed', $requestScope);
        return 'scope_changed';
    }

    try {
        $profile = $store->activePlaythrough();
    } catch (Throwable $error) {
        pcv_reflection_log('reflection.registration_error', 'registration', 'database_unavailable', $requestScope, $error);
        return 'database_unavailable';
    }
    $playthroughId = is_array($profile) ? ($profile['id'] ?? null) : null;
    if (!is_string($playthroughId) || $playthroughId === '' || strlen($playthroughId) > 64) {
        pcv_reflection_log('reflection.registration_error', 'registration', 'database_unavailable', $requestScope);
        return 'database_unavailable';
    }
    try {
        $source = $store->acknowledgedEvent($utteranceId);
    } catch (Throwable $error) {
        pcv_reflection_log('reflection.registration_error', 'registration', 'database_unavailable', $requestScope, $error);
        return 'database_unavailable';
    }
    if (!is_array($source) || ($source['utterance_id'] ?? null) !== $utteranceId
        || !is_int($source['event_id'] ?? null) || $source['event_id'] < 1) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', 'event_unmatched', $requestScope);
        return 'event_unmatched';
    }
    if (($source['delivery_state'] ?? null) === 'aborted') {
        pcv_reflection_log('reflection.registration_skipped', 'registration', 'source_aborted', $requestScope);
        return 'source_aborted';
    }
    if (!in_array($source['delivery_state'] ?? null, ['emitted', 'spoken'], true)
        || !is_string($source['source_data'] ?? null)
        || !function_exists('ChimMindPoisoning\\reflectionSourceParts')) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', 'event_unmatched', $requestScope);
        return 'event_unmatched';
    }
    $parts = \ChimMindPoisoning\reflectionSourceParts($source['source_data']);
    $targets = is_array($parts) ? ($parts['target']['targets'] ?? null) : null;
    if (!is_array($parts) || !is_string($parts['speaker'] ?? null)
        || !is_array($targets) || count($targets) !== 1
        || !is_string($targets[0] ?? null)
        || strcasecmp(trim($targets[0]), 'explicit_disable_rechat') !== 0) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', 'sentinel_mismatch', $requestScope);
        return 'sentinel_mismatch';
    }
    if (strcasecmp(trim($parts['speaker']), $expectedRecord['actor_name']) !== 0) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', 'event_unmatched', $requestScope);
        return 'event_unmatched';
    }

    $registration = [
        'event_id' => $source['event_id'],
        'utterance_id' => $utteranceId,
        'actor_id' => $expectedRecord['actor_id'],
        'actor_name' => $expectedRecord['actor_name'],
        'playthrough_id' => $playthroughId,
        'config_id' => $expectedRecord['config_id'],
        'rechat_target_hint' => 'explicit_disable_rechat',
        'speech_hash' => hash('sha256', $wire['subtitle']),
    ];
    // With Mind Poisoning's reply API, register every proven line of this reply; otherwise keep the final line.
    if (pcv_reflection_mind_poisoning_reply_api_compatible()) {
        $requestTs = $GLOBALS['gameRequest'][1] ?? null;
        $lines = pcv_reflection_reply_lines($store, $expectedRecord['actor_name'], $source['event_id'], $utteranceId,
            is_string($requestTs) ? $requestTs : (is_int($requestTs) ? (string)$requestTs : ''), $replyRowsReader);
        // The final tuple digests the parsed source text; it must equal the subtitle digest the ACK carries.
        if (is_array($lines) && $lines[array_key_last($lines)]['speech_hash'] === $registration['speech_hash']) {
            $registration['lines'] = $lines;
        }
    }
    $record = $expectedRecord;
    $record['registration'] = $registration;
    if (!pcv_reflection_valid_record($record)) {
        pcv_reflection_log('reflection.registration_skipped', 'registration', 'scope_ineligible', $requestScope);
        return 'scope_ineligible';
    }

    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, true, LOCK_EX);
        $skipReason = null;
        $shouldReconcile = true;
        try {
            $existing = pcv_reflection_read_locked($directory);
            $receipts = pcv_reflection_read_receipts_locked($directory);
            if ($existing['kind'] === 'invalid' || $receipts['kind'] === 'invalid') {
                $skipReason = 'registry_corrupt';
            } elseif ($existing['kind'] === 'unavailable' || $receipts['kind'] === 'unavailable') {
                $skipReason = 'registry_unavailable';
            } elseif ($existing['kind'] === 'ready' && pcv_reflection_record_fresh($existing['record'])) {
                $existingRecord = $existing['record'];
                $sameId = $existingRecord['registration']['utterance_id'] === $utteranceId;
                if ($sameId && $existingRecord['status'] !== 'registered') {
                    $skipReason = 'claim_taken';
                } elseif ($sameId && ($existingRecord['version'] !== 2
                    || !pcv_reflection_registration_matches($existingRecord['registration'], $record['registration'])
                    || $existingRecord['source_generation'] !== $record['source_generation']
                    || $existingRecord['pcv_key'] !== $record['pcv_key'])) {
                    $skipReason = 'registration_busy';
                } elseif ($sameId) {
                    $record = $existingRecord;
                    $shouldReconcile = false;
                } elseif ($existingRecord['status'] === 'claimed') {
                    $skipReason = 'claim_taken';
                } elseif ($existingRecord['status'] === 'registered'
                    // A registration from another scope (ended or re-armed scene) can never be evaluated.
                    && $existingRecord['config_id'] === $record['config_id']
                    && $existingRecord['pcv_key'] === $record['pcv_key']
                    && time() - $existingRecord['created_at'] < PCV_REFLECTION_REGISTERED_SUPERSESSION_TTL) {
                    $skipReason = 'registration_busy';
                } else {
                    pcv_reflection_write_locked($directory, $record);
                }
            } else {
                pcv_reflection_write_locked($directory, $record);
            }
        } finally {
            pcv_unlock_state($handle);
        }
        if ($skipReason !== null) {
            $event = in_array($skipReason, ['registry_corrupt', 'registry_unavailable'], true)
                ? 'reflection.registration_error' : 'reflection.registration_skipped';
            pcv_reflection_log($event, 'registration', $skipReason, $requestScope);
            return $skipReason;
        }
    } catch (Throwable $error) {
        pcv_reflection_log('reflection.registration_error', 'registration', 'registry_unavailable', $requestScope, $error);
        return 'registry_unavailable';
    }

    pcv_reflection_log('reflection.output_registered', 'registration', '', $record);
    if ($shouldReconcile) {
        pcv_reflection_reconcile_registration($record, $store, $stateDirectory, $freshScopeReader, $nativeAckReader, $requestModel);
    }
    return 'registered';
}

function pcv_reflection_reconcile_registration(
    array $record,
    \ChimMindPoisoning\StoreDb $store,
    ?string $stateDirectory,
    ?callable $freshScopeReader,
    ?callable $nativeAckReader = null,
    ?callable $requestModel = null
): string {
    if (($record['version'] ?? null) !== 2 || !pcv_reflection_valid_record($record)) {
        return 'registration_stale';
    }
    $receipt = pcv_reflection_receipt_for_registration($record, $stateDirectory);
    if ($receipt['kind'] === 'missing') {
        return 'registration_missing';
    }
    if ($receipt['kind'] !== 'ready') {
        $reason = $receipt['kind'] === 'invalid' ? 'receipt_corrupt' : 'receipt_unavailable';
        pcv_reflection_log('reflection.ack_error', 'ack', $reason, $record);
        return $receipt['kind'] === 'invalid' ? 'receipt_corrupt' : 'receipt_unavailable';
    }
    return pcv_reflection_reconcile_receipt_with_store(
        $receipt['receipt'], $store, null, $stateDirectory, $freshScopeReader, $nativeAckReader, $requestModel
    );
}

function pcvReflectionReconcileAckReceipt(array $gameRequest, array $receipt): string
{
    if (!pcv_reflection_load_mind_poisoning()) {
        pcv_reflection_log('reflection.ack_error', 'ack', pcv_reflection_mind_poisoning_unavailable_reason(), $receipt);
        return 'mind_poisoning_unavailable';
    }
    $store = new \ChimMindPoisoning\PostgresStoreDb();
    return pcv_reflection_reconcile_receipt_with_store($receipt, $store, $gameRequest);
}

function pcv_reflection_receipt_for_registration(array $record, ?string $stateDirectory): array
{
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, false, LOCK_SH);
        if ($handle === null) {
            return ['kind' => 'missing'];
        }
        try {
            $loaded = pcv_reflection_read_receipts_locked($directory);
            if ($loaded['kind'] !== 'ready') {
                return ['kind' => $loaded['kind']];
            }
            foreach ($loaded['receipts'] as $receipt) {
                if ($receipt['utterance_id'] === $record['registration']['utterance_id']) {
                    return pcv_reflection_receipt_fresh($receipt)
                        ? ['kind' => 'ready', 'receipt' => $receipt]
                        : ['kind' => 'missing'];
                }
            }
            return ['kind' => 'missing'];
        } finally {
            pcv_unlock_state($handle);
        }
    } catch (Throwable) {
        return ['kind' => 'unavailable'];
    }
}

function pcv_reflection_reconcile_receipt_with_store(
    array $receipt,
    \ChimMindPoisoning\StoreDb $store,
    ?array $originalGameRequest = null,
    ?string $stateDirectory = null,
    ?callable $freshScopeReader = null,
    ?callable $nativeAckReader = null,
    ?callable $requestModel = null
): string {
    if (!pcv_reflection_valid_receipt($receipt) || !pcv_reflection_receipt_fresh($receipt)) {
        return 'registration_stale';
    }
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, false, LOCK_SH);
        if ($handle === null) {
            return 'registration_missing';
        }
        try {
            $registry = pcv_reflection_read_locked($directory);
            $receipts = pcv_reflection_read_receipts_locked($directory);
            if ($registry['kind'] === 'invalid' || $receipts['kind'] === 'invalid') {
                pcv_reflection_log('reflection.ack_error', 'ack', 'registry_corrupt');
                return 'registry_corrupt';
            }
            if ($registry['kind'] === 'unavailable' || $receipts['kind'] === 'unavailable') {
                pcv_reflection_log('reflection.ack_error', 'ack', 'registry_unavailable');
                return 'registry_unavailable';
            }
            if ($registry['kind'] !== 'ready' || $receipts['kind'] !== 'ready') {
                return 'registration_missing';
            }
            $record = $registry['record'];
            $storedReceipt = null;
            foreach ($receipts['receipts'] as $candidate) {
                if ($candidate['utterance_id'] === $receipt['utterance_id']) {
                    $storedReceipt = $candidate;
                    break;
                }
            }
            if ($record['version'] !== 2 || $record['status'] !== 'registered'
                || !pcv_reflection_record_fresh($record)
                || $record['registration']['utterance_id'] !== $receipt['utterance_id']
                || !is_array($storedReceipt) || !pcv_reflection_receipts_match($storedReceipt, $receipt)
                || $record['pcv_key'] !== $receipt['pcv_key']
                || $record['config_id'] !== $receipt['config_id']
                || $record['actor_id'] !== $receipt['actor_id']
                || $record['actor_name'] !== $receipt['actor_name']) {
                return 'registration_missing';
            }
        } finally {
            pcv_unlock_state($handle);
        }
    } catch (Throwable $error) {
        pcv_reflection_log('reflection.ack_error', 'ack', 'registry_unavailable', $receipt, $error);
        return 'registry_unavailable';
    }

    if (!pcv_reflection_interaction_epochs_match($record['source_generation'], $receipt['ack_generation'])) {
        pcv_reflection_log('reflection.ack_skipped', 'ack', 'interaction_stale', $record);
        return 'interaction_stale';
    }
    try {
        $native = $nativeAckReader !== null
            ? $nativeAckReader($receipt['utterance_id'])
            : pcv_reflection_lookup_native_ack($receipt['utterance_id']);
    } catch (Throwable $error) {
        pcv_reflection_log('reflection.ack_error', 'ack', 'database_unavailable', $record, $error);
        return 'database_unavailable';
    }
    if (!is_array($native) || !is_string($native['kind'] ?? null)) {
        pcv_reflection_log('reflection.ack_error', 'ack', 'database_unavailable', $record);
        return 'database_unavailable';
    }
    if ($native['kind'] === 'missing') {
        return 'registration_missing';
    }
    if ($native['kind'] === 'ambiguous') {
        pcv_reflection_log('reflection.ack_skipped', 'ack', 'native_ack_ambiguous', $record);
        return 'native_ack_ambiguous';
    }
    if ($native['kind'] === 'unavailable') {
        pcv_reflection_log('reflection.ack_error', 'ack', 'database_unavailable', $record);
        return 'database_unavailable';
    }
    if ($native['kind'] !== 'row') {
        pcv_reflection_log('reflection.ack_skipped', 'ack', 'ack_mismatch', $record);
        return 'ack_mismatch';
    }
    $gameRequest = pcv_reflection_native_ack_request($native['row'] ?? []);
    $tuple = is_array($gameRequest) ? pcv_reflection_ack_tuple($gameRequest) : null;
    $digest = is_array($tuple) ? pcv_reflection_ack_tuple_digest($tuple) : null;
    if (!is_array($gameRequest) || !is_array($tuple)
        || $tuple['utterance_id'] !== $receipt['utterance_id']
        || !is_string($digest) || !hash_equals($receipt['tuple_digest'], $digest)) {
        pcv_reflection_log('reflection.ack_skipped', 'ack', 'ack_mismatch', $record);
        return 'ack_mismatch';
    }
    if ($originalGameRequest !== null) {
        $originalTuple = pcv_reflection_ack_tuple($originalGameRequest);
        $originalDigest = is_array($originalTuple) ? pcv_reflection_ack_tuple_digest($originalTuple) : null;
        if (!is_string($originalDigest) || !hash_equals($receipt['tuple_digest'], $originalDigest)) {
            pcv_reflection_log('reflection.ack_skipped', 'ack', 'ack_mismatch', $record);
            return 'ack_mismatch';
        }
    }
    if (!pcv_reflection_load_mind_poisoning()) {
        pcv_reflection_log('reflection.ack_error', 'ack', pcv_reflection_mind_poisoning_unavailable_reason(), $record);
        return 'mind_poisoning_unavailable';
    }
    return pcv_reflection_evaluate_with_store(
        $gameRequest, $store, $requestModel, $stateDirectory, $freshScopeReader, null, $receipt
    );
}

function pcv_reflection_evaluate_with_store(
    array $gameRequest,
    \ChimMindPoisoning\StoreDb $store,
    ?callable $requestModel = null,
    ?string $stateDirectory = null,
    ?callable $freshScopeReader = null,
    ?\ChimMindPoisoning\RequestLog $requestLog = null,
    ?array $ackReceipt = null
): string {
    $utteranceId = pcv_reflection_ack_utterance_id($gameRequest);
    if ($utteranceId === null) {
        return 'not_applicable';
    }
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, false, LOCK_EX);
        if ($handle === null) {
            return 'registration_missing';
        }
        $registryKind = 'unavailable';
        try {
            $loaded = pcv_reflection_read_locked($directory);
            $registryKind = $loaded['kind'];
            if ($registryKind === 'ready') {
                $record = $loaded['record'];
            }
        } finally {
            pcv_unlock_state($handle);
        }
        if ($registryKind === 'missing') {
            return 'registration_missing';
        }
        if ($registryKind === 'invalid' || $registryKind === 'unavailable') {
            pcv_reflection_log('reflection.ack_error', 'ack', $registryKind === 'invalid' ? 'registry_corrupt' : 'registry_unavailable');
            return $registryKind === 'invalid' ? 'registry_corrupt' : 'registry_unavailable';
        }
    } catch (Throwable $error) {
        pcv_reflection_log('reflection.ack_error', 'ack', 'registry_unavailable', null, $error);
        return 'registry_unavailable';
    }
    if ($record['registration']['utterance_id'] !== $utteranceId) {
        return 'registration_missing';
    }
    if ($record['status'] !== 'registered') {
        pcv_reflection_log('reflection.ack_skipped', 'ack', 'claim_taken', $record);
        return 'claim_taken';
    }
    if (!pcv_reflection_record_fresh($record)) {
        pcv_reflection_log('reflection.ack_skipped', 'ack', 'registration_stale', $record);
        return 'registration_stale';
    }

    $ack = \ChimMindPoisoning\reflectionAckPayload($gameRequest);
    if (!is_array($ack) || $ack['utterance_id'] !== $utteranceId
        || strcasecmp($ack['speaker'], $record['actor_name']) !== 0
        || !hash_equals($record['registration']['speech_hash'], hash('sha256', $ack['speech']))) {
        pcv_reflection_log('reflection.ack_skipped', 'ack', 'ack_mismatch', $record);
        return 'ack_mismatch';
    }
    $tuple = pcv_reflection_ack_tuple($gameRequest);
    $digest = is_array($tuple) ? pcv_reflection_ack_tuple_digest($tuple) : null;
    if (!is_array($tuple) || !is_string($digest)) {
        pcv_reflection_log('reflection.ack_skipped', 'ack', 'ack_mismatch', $record);
        return 'ack_mismatch';
    }
    if ($ackReceipt === null) {
        $ackGeneration = null;
        if (!pcv_reflection_capture_interaction_generation($ackGeneration)) {
            pcv_reflection_log('reflection.ack_skipped', 'ack', 'interaction_stale', $record);
            return 'interaction_stale';
        }
        $ackReceipt = [
            'created_at' => time(),
            'ack_generation' => $ackGeneration,
            'tuple_digest' => $digest,
        ];
    }
    $receiptMetadata = pcv_reflection_valid_receipt_metadata($ackReceipt)
        ? $ackReceipt
        : (pcv_reflection_valid_receipt($ackReceipt) ? pcv_reflection_receipt_metadata($ackReceipt) : null);
    if (!is_array($receiptMetadata) || $receiptMetadata['created_at'] > time()
        || !hash_equals($receiptMetadata['tuple_digest'], $digest)
        || $receiptMetadata['ack_generation'] < 0) {
        pcv_reflection_log('reflection.ack_skipped', 'ack', 'ack_mismatch', $record);
        return 'ack_mismatch';
    }
    if (pcv_reflection_valid_receipt($ackReceipt)) {
        if (!pcv_reflection_receipt_fresh($ackReceipt)
            || $ackReceipt['utterance_id'] !== $utteranceId
            || $ackReceipt['pcv_key'] !== $record['pcv_key']
            || $ackReceipt['config_id'] !== $record['config_id']
            || $ackReceipt['actor_id'] !== $record['actor_id']
            || $ackReceipt['actor_name'] !== $record['actor_name']) {
            pcv_reflection_log('reflection.ack_skipped', 'ack', 'registration_stale', $record);
            return 'registration_stale';
        }
    }
    if ($record['version'] === 2
        && !pcv_reflection_interaction_epochs_match($record['source_generation'], $receiptMetadata['ack_generation'])) {
        pcv_reflection_log('reflection.ack_skipped', 'ack', 'interaction_stale', $record);
        return 'interaction_stale';
    }
    try {
        $profile = $store->activePlaythrough();
    } catch (Throwable $error) {
        pcv_reflection_log('reflection.ack_error', 'ack', 'database_unavailable', $record, $error);
        return 'database_unavailable';
    }
    $playerName = is_array($profile) && is_string($profile['player_name'] ?? null) ? trim($profile['player_name']) : '';
    if (!is_array($profile) || ($profile['id'] ?? null) !== $record['registration']['playthrough_id']
        || $playerName === '' || !\ChimMindPoisoning\reflectionPlayerTransport($ack['listener'], $playerName)) {
        pcv_reflection_log('reflection.ack_skipped', 'ack', 'ack_mismatch', $record);
        return 'ack_mismatch';
    }
    $freshScope = pcv_reflection_fresh_scope($freshScopeReader, $scopeFailure);
    if (!is_array($freshScope)) {
        pcv_reflection_log('reflection.ack_skipped', 'ack', $scopeFailure ?? 'identity_changed', $record);
        return $scopeFailure ?? 'identity_changed';
    }
    if (!pcv_reflection_scope_matches($record, $freshScope)) {
        pcv_reflection_log('reflection.ack_skipped', 'ack', 'scope_changed', $record);
        return 'scope_changed';
    }

    $claimFailure = null;
    try {
        $handle = pcv_lock_state($directory, false, LOCK_EX);
        if ($handle === null) {
            return 'registration_missing';
        }
        try {
            $latest = pcv_reflection_read_locked($directory);
            if ($latest['kind'] === 'invalid') {
                $claimFailure = 'registry_corrupt';
            } elseif ($latest['kind'] === 'unavailable') {
                $claimFailure = 'registry_unavailable';
            } elseif ($latest['kind'] === 'missing') {
                $claimFailure = 'registration_missing';
            } elseif ($latest['record']['status'] !== 'registered'
                || $latest['record']['registration']['utterance_id'] !== $utteranceId
                || !pcv_reflection_records_match($latest['record'], $record)) {
                $claimFailure = 'claim_taken';
            } else {
                $record = $latest['record'];
                if ($record['version'] === 2) {
                    if ($record['ack_receipt'] !== null && $record['ack_receipt'] !== $receiptMetadata) {
                        $claimFailure = 'ack_conflict';
                    } elseif (pcv_reflection_valid_receipt($ackReceipt)) {
                        $receiptState = pcv_reflection_read_receipts_locked($directory);
                        $storedReceipt = null;
                        if ($receiptState['kind'] === 'ready') {
                            foreach ($receiptState['receipts'] as $candidate) {
                                if ($candidate['utterance_id'] === $utteranceId) {
                                    $storedReceipt = $candidate;
                                    break;
                                }
                            }
                        }
                        if ($receiptState['kind'] !== 'ready') {
                            $claimFailure = $receiptState['kind'] === 'invalid' ? 'receipt_corrupt' : 'receipt_unavailable';
                        } elseif (!is_array($storedReceipt) || !pcv_reflection_receipts_match($storedReceipt, $ackReceipt)) {
                            $claimFailure = 'ack_conflict';
                        } elseif ($storedReceipt['created_at'] !== $ackReceipt['created_at']
                            || !pcv_reflection_receipt_fresh($storedReceipt)) {
                            $claimFailure = 'registration_stale';
                        }
                    }
                    if ($claimFailure === null) {
                        $record['ack_receipt'] = $receiptMetadata;
                    }
                }
                if ($claimFailure === null) {
                    $record['status'] = 'claimed';
                    $record['claim_token'] = bin2hex(random_bytes(16));
                    pcv_reflection_write_locked($directory, $record);
                }
            }
        } finally {
            pcv_unlock_state($handle);
        }
    } catch (Throwable $error) {
        pcv_reflection_log('reflection.ack_error', 'ack', 'registry_unavailable', $record, $error);
        return 'registry_unavailable';
    }
    if ($claimFailure !== null) {
        $event = in_array($claimFailure, ['registry_corrupt', 'registry_unavailable'], true)
            || in_array($claimFailure, ['receipt_corrupt', 'receipt_unavailable'], true)
            ? 'reflection.ack_error' : 'reflection.ack_skipped';
        pcv_reflection_log($event, 'ack', $claimFailure, $record);
        return $claimFailure;
    }

    $revalidationReason = null;
    pcv_log_set_config_id($record['config_id']);
    pcv_log_set_correlation([
        'event_id' => (string)$record['registration']['event_id'],
        'utterance_id' => $record['registration']['utterance_id'],
    ]);
    try {
        $requestLog ??= new \ChimMindPoisoning\RequestLog();
        if (!pcv_reflection_attach_mp_observer($requestLog)) {
            pcv_reflection_log('reflection.observer_unavailable', 'ack', 'observer_unsupported', $record);
        }
        $evaluator = pcv_reflection_mp_evaluator($record['registration']);
        if (!function_exists($evaluator)) {
            throw new RuntimeException('The Mind Poisoning evaluator for this registration is unavailable.');
        }
        $status = $evaluator(
            $record['registration'],
            $gameRequest,
            $store,
            static function (array $registration, string $phase) use ($record, $stateDirectory, $freshScopeReader, &$revalidationReason): bool {
                return pcv_reflection_revalidate(
                    $registration,
                    $phase,
                    $record['claim_token'],
                    $stateDirectory,
                    $freshScopeReader,
                    $revalidationReason,
                    $record
                );
            },
            $requestModel,
            $requestLog
        );
        if ($status === 'committed') {
            pcv_reflection_log('reflection.evaluation_finished', 'ack', '', $record);
        }
    } catch (Throwable $error) {
        pcv_reflection_log('reflection.ack_error', 'ack', 'internal_error', $record, $error);
        $status = 'failed';
        $errorLogged = true;
    } finally {
        $consumed = pcv_reflection_consume_claim($record, $stateDirectory);
        if (!$consumed) {
            pcv_reflection_log('reflection.ack_error', 'ack', 'registry_unavailable', $record);
        }
    }

    if ($status !== 'committed' && !($errorLogged ?? false)) {
        if (in_array($revalidationReason, ['registry_corrupt', 'registry_unavailable'], true)) {
            pcv_reflection_log('reflection.ack_error', 'ack', $revalidationReason, $record);
        } elseif ($status === 'failed') {
            pcv_reflection_log('reflection.ack_error', 'ack', 'evaluation_failed', $record);
        } else {
            $reason = in_array($revalidationReason, ['identity_changed', 'scope_changed', 'interaction_stale', 'scope_unavailable'], true)
                ? $revalidationReason : 'evaluation_rejected';
            pcv_reflection_log('reflection.ack_skipped', 'ack', $reason, $record);
        }
    }
    return $status;
}

function pcv_reflection_ack_utterance_id(array $gameRequest): ?string
{
    $raw = $gameRequest[3] ?? null;
    if (($gameRequest[0] ?? null) !== '_speech' || !is_string($raw) || strlen($raw) > 16384 || preg_match('//u', $raw) !== 1) {
        return null;
    }
    try {
        $payload = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    $utteranceId = $payload instanceof stdClass ? ($payload->utterance_id ?? null) : null;
    if (!is_string($utteranceId)) {
        return null;
    }
    $utteranceId = trim($utteranceId);
    return preg_match('/\\Autt_[A-Za-z0-9_-]{8,128}\\z/D', $utteranceId) === 1
        ? $utteranceId : null;
}

function pcv_reflection_revalidate(
    array $registration,
    string $phase,
    string $claimToken,
    ?string $stateDirectory,
    ?callable $freshScopeReader,
    ?string &$reason = null,
    ?array $claimedRecord = null
): bool {
    $reason = null;
    if (!in_array($phase, ['pre_model', 'transaction'], true)
        || preg_match('/\\A[a-f0-9]{32}\\z/D', $claimToken) !== 1
        || !pcv_reflection_valid_registration($registration)) {
        $reason = 'registration_stale';
        return false;
    }
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, false, LOCK_SH);
        if ($handle === null) {
            $reason = 'registration_stale';
            return false;
        }
        try {
            $loaded = pcv_reflection_read_locked($directory);
            if ($loaded['kind'] !== 'ready') {
                $reason = match ($loaded['kind']) {
                    'invalid' => 'registry_corrupt',
                    'unavailable' => 'registry_unavailable',
                    default => 'registration_stale',
                };
                return false;
            }
            $record = $loaded['record'];
        } finally {
            pcv_unlock_state($handle);
        }
    } catch (Throwable) {
        $reason = 'registry_unavailable';
        return false;
    }
    if ($record['status'] !== 'claimed' || !hash_equals($record['claim_token'], $claimToken)
        || !pcv_reflection_record_fresh($record)
        || !pcv_reflection_registration_matches($record['registration'], $registration)
        || ($claimedRecord !== null && !pcv_reflection_records_match($record, $claimedRecord))) {
        $reason = 'registration_stale';
        return false;
    }
    if ($record['version'] === 2
        && !pcv_reflection_interaction_epochs_match(
            $record['source_generation'],
            $record['ack_receipt']['ack_generation']
        )) {
        $reason = 'interaction_stale';
        return false;
    }
    $scope = pcv_reflection_fresh_scope($freshScopeReader, $scopeFailure);
    if (!is_array($scope)) {
        $reason = $scopeFailure ?? 'identity_changed';
        return false;
    }
    if (!pcv_reflection_scope_matches($record, $scope)) {
        $reason = is_string($scope['pcv_key'] ?? null) && $scope['pcv_key'] !== $record['pcv_key']
            ? 'identity_changed' : 'scope_changed';
        return false;
    }
    return true;
}

function pcv_reflection_consume_claim(array $claimedRecord, ?string $stateDirectory): bool
{
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, false, LOCK_EX);
        if ($handle === null) {
            return false;
        }
        try {
            $loaded = pcv_reflection_read_locked($directory);
            if ($loaded['kind'] === 'ready'
                && $loaded['record']['status'] === 'claimed'
                && hash_equals($loaded['record']['claim_token'], $claimedRecord['claim_token'] ?? '')
                && pcv_reflection_records_match($loaded['record'], $claimedRecord)) {
                $record = $loaded['record'];
                $record['status'] = 'consumed';
                pcv_reflection_write_locked($directory, $record);
                return true;
            }
            return false;
        } finally {
            pcv_unlock_state($handle);
        }
    } catch (Throwable) {
        // The persisted claimed state still prevents a duplicate provider attempt.
        return false;
    }
}

function pcv_reflection_records_match(array $left, array $right): bool
{
    return pcv_reflection_valid_record($left) && pcv_reflection_valid_record($right)
        && $left['version'] === $right['version']
        && $left['pcv_key'] === $right['pcv_key']
        && $left['config_id'] === $right['config_id']
        && $left['actor_id'] === $right['actor_id']
        && $left['actor_name'] === $right['actor_name']
        && $left['origin_request_type'] === $right['origin_request_type']
        && $left['created_at'] === $right['created_at']
        && ($left['version'] !== 2 || ($left['source_generation'] === $right['source_generation']
            && $left['ack_receipt'] === $right['ack_receipt']))
        && pcv_reflection_registration_matches($left['registration'], $right['registration']);
}

function pcv_reflection_registration_matches(array $left, array $right): bool
{
    foreach (['event_id', 'utterance_id', 'actor_id', 'actor_name', 'playthrough_id', 'config_id', 'rechat_target_hint', 'speech_hash'] as $key) {
        if (($left[$key] ?? null) !== ($right[$key] ?? null)) {
            return false;
        }
    }
    // A full reply's ordered line list is part of its identity (Mind Poisoning revalidates it).
    if (array_key_exists('lines', $left) !== array_key_exists('lines', $right)
        || ($left['lines'] ?? null) !== ($right['lines'] ?? null)) {
        return false;
    }
    return pcv_reflection_valid_registration($left) && pcv_reflection_valid_registration($right);
}

/** 1–8 exact tuples, strictly increasing event IDs, unique utterances, final tuple equal to the top level. */
function pcv_reflection_valid_reply_lines(mixed $lines, array $registration): bool
{
    if (!is_array($lines) || !array_is_list($lines) || $lines === [] || count($lines) > PCV_REFLECTION_REPLY_MAX_LINES) {
        return false;
    }
    $previous = 0;
    $seen = [];
    foreach ($lines as $line) {
        $keys = is_array($line) ? array_keys($line) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['event_id', 'speech_hash', 'utterance_id']
            || !is_int($line['event_id']) || $line['event_id'] <= $previous
            || !is_string($line['utterance_id']) || preg_match('/\\Autt_[A-Za-z0-9_-]{8,128}\\z/D', $line['utterance_id']) !== 1
            || isset($seen[$line['utterance_id']])
            || !is_string($line['speech_hash']) || preg_match('/\\A[a-f0-9]{64}\\z/D', $line['speech_hash']) !== 1) {
            return false;
        }
        $previous = $line['event_id'];
        $seen[$line['utterance_id']] = true;
    }
    $final = $lines[array_key_last($lines)];
    return $final['event_id'] === ($registration['event_id'] ?? null)
        && $final['utterance_id'] === ($registration['utterance_id'] ?? null)
        && $final['speech_hash'] === ($registration['speech_hash'] ?? null);
}

function pcv_reflection_valid_registration(array $registration): bool
{
    if (array_key_exists('lines', $registration)) {
        $lines = $registration['lines'];
        unset($registration['lines']);
        if (!pcv_reflection_valid_reply_lines($lines, $registration)) {
            return false;
        }
    }
    $keys = ['event_id', 'utterance_id', 'actor_id', 'actor_name', 'playthrough_id', 'config_id', 'rechat_target_hint', 'speech_hash'];
    $actual = array_keys($registration);
    sort($actual, SORT_STRING);
    sort($keys, SORT_STRING);
    return $actual === $keys
        && is_int($registration['event_id']) && $registration['event_id'] > 0
        && is_string($registration['utterance_id']) && preg_match('/\\Autt_[A-Za-z0-9_-]{8,128}\\z/D', $registration['utterance_id']) === 1
        && is_int($registration['actor_id']) && $registration['actor_id'] > 0
        && is_string($registration['actor_name']) && trim($registration['actor_name']) !== ''
        && strlen($registration['actor_name']) <= 256 && preg_match('//u', $registration['actor_name']) === 1
        && is_string($registration['playthrough_id']) && $registration['playthrough_id'] !== ''
        && strlen($registration['playthrough_id']) <= 64
        && is_string($registration['config_id']) && pcv_log_valid_uuid($registration['config_id'])
        && $registration['rechat_target_hint'] === 'explicit_disable_rechat'
        && is_string($registration['speech_hash']) && preg_match('/\\A[a-f0-9]{64}\\z/D', $registration['speech_hash']) === 1;
}

function pcv_reflection_valid_record(array $record): bool
{
    $version = $record['version'] ?? null;
    $keys = $version === 1
        ? ['version', 'pcv_key', 'config_id', 'actor_id', 'actor_name', 'origin_request_type', 'origin_mode', 'route', 'created_at', 'status', 'claim_token', 'registration']
        : ['version', 'pcv_key', 'config_id', 'actor_id', 'actor_name', 'origin_request_type', 'origin_mode', 'route', 'created_at', 'status', 'claim_token', 'registration', 'source_generation', 'ack_receipt'];
    $actual = array_keys($record);
    sort($actual, SORT_STRING);
    sort($keys, SORT_STRING);
    $status = $record['status'] ?? null;
    $claim = $record['claim_token'] ?? null;
    return $actual === $keys
        && in_array($version, [1, 2], true)
        && is_string($record['pcv_key'] ?? null) && pcv_valid_key($record['pcv_key'])
        && is_string($record['config_id'] ?? null) && pcv_log_valid_uuid($record['config_id'])
        && is_int($record['actor_id'] ?? null) && $record['actor_id'] > 0
        && is_string($record['actor_name'] ?? null) && trim($record['actor_name']) !== ''
        && strlen($record['actor_name']) <= 256 && preg_match('//u', $record['actor_name']) === 1
        && in_array($record['origin_request_type'] ?? null, ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s'], true)
        && ($record['origin_mode'] ?? null) === 'STANDARD'
        && ($record['route'] ?? null) === 'solo_reflection'
        && is_int($record['created_at'] ?? null) && $record['created_at'] > 0
        && in_array($status, ['registered', 'claimed', 'consumed'], true)
        && (($status === 'registered' && $claim === null)
            || ($status !== 'registered' && is_string($claim) && preg_match('/\\A[a-f0-9]{32}\\z/D', $claim) === 1))
        && ($version === 1
            || (is_int($record['source_generation'] ?? null) && $record['source_generation'] >= 0
                && (($record['ack_receipt'] ?? null) === null
                    ? $status === 'registered'
                    : (is_array($record['ack_receipt']) && pcv_reflection_valid_receipt_metadata($record['ack_receipt'])))))
        && is_array($record['registration'] ?? null)
        && pcv_reflection_valid_registration($record['registration'])
        && $record['registration']['config_id'] === $record['config_id']
        && $record['registration']['actor_id'] === $record['actor_id']
        && $record['registration']['actor_name'] === $record['actor_name'];
}

function pcv_reflection_record_fresh(array $record): bool
{
    $age = time() - ($record['created_at'] ?? 0);
    return $age >= 0 && $age <= PCV_REFLECTION_REGISTRY_TTL;
}

function pcv_reflection_read_locked(string $directory): array
{
    $path = $directory . DIRECTORY_SEPARATOR . 'reflection.json';
    if (is_link($path)) {
        return ['kind' => 'invalid'];
    }
    if (!file_exists($path)) {
        return ['kind' => 'missing'];
    }
    if (!is_file($path)) {
        return ['kind' => 'invalid'];
    }
    clearstatcache(true, $path);
    $size = @filesize($path);
    $mode = @fileperms($path);
    if (!is_int($size) || $size > PCV_REFLECTION_REGISTRY_MAX_BYTES || !is_int($mode) || ($mode & 0077) !== 0) {
        return ['kind' => 'invalid'];
    }
    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        return ['kind' => 'unavailable'];
    }
    try {
        $record = json_decode($contents, true, 12, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['kind' => 'invalid'];
    }
    if (!is_array($record) || !pcv_reflection_valid_record($record)) {
        return ['kind' => 'invalid'];
    }
    return ['kind' => 'ready', 'record' => $record];
}

function pcv_reflection_registry_probe(?string $stateDirectory = null): array
{
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, false, LOCK_SH);
        if ($handle === null) {
            return ['kind' => 'missing'];
        }
        try {
            return pcv_reflection_read_locked($directory);
        } finally {
            pcv_unlock_state($handle);
        }
    } catch (Throwable) {
        return ['kind' => 'unavailable'];
    }
}

function pcv_reflection_write_locked(string $directory, array $record): void
{
    if (!pcv_reflection_valid_record($record)) {
        throw new RuntimeException('Invalid reflection registration.');
    }
    $path = $directory . DIRECTORY_SEPARATOR . 'reflection.json';
    if (is_link($path)) {
        throw new RuntimeException('Reflection registry is not safe.');
    }
    $contents = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (strlen($contents) > PCV_REFLECTION_REGISTRY_MAX_BYTES) {
        throw new RuntimeException('Reflection registry is too large.');
    }
    $temporary = tempnam($directory, '.reflection-');
    if ($temporary === false) {
        throw new RuntimeException('Reflection registry is unavailable.');
    }
    try {
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException('Reflection registry is unavailable.');
        }
        @chmod($temporary, 0600);
        if (is_link($path) || !@rename($temporary, $path)) {
            throw new RuntimeException('Reflection registry is unavailable.');
        }
        @chmod($path, 0600);
    } finally {
        if (is_file($temporary) && !is_link($temporary)) {
            @unlink($temporary);
        }
    }
}
