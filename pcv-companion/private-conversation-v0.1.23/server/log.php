<?php
declare(strict_types=1);

const PCV_LOG_SCHEMA_VERSION = 1;
const PCV_LOG_MAX_FILES = 5;
const PCV_LOG_MAX_FILE_BYTES = 10485760;
const PCV_LOG_MAX_ENTRY_BYTES = 8192;
const PCV_LOG_LOCK_WAIT_BUDGET_NS = 100000000;
const PCV_LOG_DEBUG_MAX_SECONDS = 3600;
const PCV_LOG_INSTRUMENTATION_REVISION = 2;

function pcv_log_new_uuid(): ?string
{
    try {
        $bytes = random_bytes(16);
    } catch (Throwable) {
        return null;
    }

    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function pcv_log_request_id_fallback(): string
{
    static $counter = 0;
    $counter++;
    $clock = function_exists('hrtime') ? (string)hrtime(true) : sprintf('%.6F', microtime(true));
    $seed = (string)getmypid() . ':' . $clock . ':' . $counter;
    return substr(hash('sha256', $seed), 0, 32);
}

function pcv_log_debug_setting_is_active(): bool
{
    $until = getenv('PCV_LOG_DEBUG_UNTIL');
    $now = time();
    return is_string($until) && preg_match('/^[1-9][0-9]{0,11}$/D', $until) === 1
        && (int)$until > $now && (int)$until <= $now + PCV_LOG_DEBUG_MAX_SECONDS;
}

function &pcv_log_request_context(): array
{
    static $request = null;
    if (!is_array($request)) {
        $start = function_exists('hrtime') ? hrtime(true) : null;
        $requestId = pcv_log_new_uuid();
        $request = [
            'request_id' => $requestId ?? pcv_log_request_id_fallback(),
            'started_ns' => is_int($start) ? $start : null,
            'config_id' => null,
            'correlation' => [],
            'debug_enabled' => pcv_log_debug_setting_is_active(),
            'fallback_codes' => [],
            'failure_codes' => [],
            'lock_wait_ns' => 0,
            'test_directory' => null,
            'storage_mode' => null,
            'storage_reason' => null,
            'write_status' => 'not_verified',
            'terminal' => null,
            'terminal_emitted' => false,
            'shutdown_registered' => false,
        ];
    }
    return $request;
}

/** Remember the request type so a request ending without speech can say what kind it was. */
function pcv_log_set_request_type(string $requestType): void
{
    $request =& pcv_log_request_context();
    $request['request_type'] = in_array($requestType, pcv_log_enum_values('request_type'), true) ? $requestType : 'other';
}

function pcv_log_begin_request(?string $configId = null): void
{
    $request =& pcv_log_request_context();
    if ($configId !== null) {
        pcv_log_set_config_id($configId);
    }
}

function pcv_log_request_id(): string
{
    $request =& pcv_log_request_context();
    return $request['request_id'];
}

function pcv_log_set_config_id(?string $configId): void
{
    $request =& pcv_log_request_context();
    $request['config_id'] = is_string($configId) && pcv_log_valid_uuid($configId) ? $configId : null;
}

function pcv_log_set_correlation(array $trustedFields): void
{
    $request =& pcv_log_request_context();
    $correlation = [];
    foreach (['event_id', 'utterance_id', 'linked_request_id'] as $key) {
        if (!array_key_exists($key, $trustedFields)) {
            continue;
        }
        $value = $trustedFields[$key];
        if ($key === 'event_id') {
            if (is_int($value) && $value > 0) {
                $correlation[$key] = (string)$value;
            } elseif (is_string($value) && preg_match('/\\A[1-9][0-9]{0,18}\\z/D', $value) === 1) {
                $correlation[$key] = $value;
            } else {
                unset($correlation[$key]);
            }
        } elseif ($key === 'utterance_id') {
            if (pcv_log_valid_utterance_id($value)) {
                $correlation[$key] = $value;
            } else {
                unset($correlation[$key]);
            }
        } elseif (is_string($value) && preg_match('/\\A[a-f0-9]{24}\\z/D', $value) === 1) {
            $correlation[$key] = $value;
        } else {
            unset($correlation[$key]);
        }
    }
    $request['correlation'] = $correlation;
}

function pcv_log_valid_utterance_id($value): bool
{
    if (!is_string($value)) {
        return false;
    }
    if (preg_match('/\\Autt_[A-Za-z0-9_-]{8,128}\\z/D', $value) === 1) {
        return true;
    }
    return preg_match('/\\Ainput_[1-9][0-9]{0,18}\\z/D', $value) === 1;
}

function pcv_log_set_playthrough_ref(?string $playthroughKey): void
{
    $request =& pcv_log_request_context();
    $request['playthrough_ref'] = is_string($playthroughKey)
        && preg_match('/^[a-f0-9]{64}$/D', $playthroughKey) === 1
        ? substr(hash('sha256', $playthroughKey), 0, 16)
        : null;
}

function pcv_log_valid_uuid(string $value): bool
{
    return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value) === 1;
}

function pcv_log_event_rules(): array
{
    static $rules = [
        'state.scope_staged' => ['severity' => 'info', 'outcome' => 'ok', 'context' => ['action', 'scene_mode', 'actor_a_id', 'actor_b_id', 'exclude_player', 'bystander_mode', 'free_scene']],
        'state.scope_activated' => ['severity' => 'info', 'outcome' => 'ok', 'context' => ['action', 'scene_mode', 'actor_a_id', 'actor_b_id', 'exclude_player', 'bystander_mode', 'member_count', 'dropped_count', 'free_scene']],
        'state.scope_members_dropped' => ['severity' => 'info', 'outcome' => 'ok', 'context' => ['drop_reason', 'dropped_count', 'member_count']],
        'state.scope_ended' => ['severity' => 'info', 'outcome' => 'ok', 'context' => ['action', 'scene_mode', 'actor_a_id', 'actor_b_id', 'exclude_player', 'bystander_mode']],
        'state.scope_skipped' => ['severity' => 'info', 'outcome' => 'skipped', 'context' => ['operation', 'scene_mode', 'presence_check', 'missing_count']],
        'state.scope_expired' => ['severity' => 'info', 'outcome' => 'expired', 'context' => ['target']],
        'state.scope_invalidated' => ['severity' => 'warning', 'outcome' => 'invalidated', 'context' => ['active_config_id', 'pending_config_id']],
        'state.unavailable' => ['severity' => 'error', 'outcome' => 'unavailable', 'context' => ['operation']],
        'state.store_recovered' => ['severity' => 'warning', 'outcome' => 'recovered', 'context' => ['operation']],
        'state.presence_refreshed' => ['severity' => 'debug', 'outcome' => 'accepted', 'context' => ['actor_count']],
        'state.presence_rejected' => ['severity' => 'warning', 'outcome' => 'rejected', 'context' => ['operation']],
        'ui.page_open' => ['severity' => 'info', 'outcome' => 'ok', 'context' => []],
        'ui.unavailable' => ['severity' => 'error', 'outcome' => 'unavailable', 'context' => ['operation']],
        'ui.scope_stage_accepted' => ['severity' => 'info', 'outcome' => 'ok', 'context' => ['action', 'status', 'pending']],
        'ui.scope_stage_rejected' => ['severity' => 'warning', 'outcome' => 'rejected', 'context' => []],
        'ui.scope_stage_failed' => ['severity' => 'error', 'outcome' => 'failed', 'context' => ['action', 'operation']],
        'routing.request_started' => ['severity' => 'info', 'outcome' => 'ok', 'context' => ['request_type']],
        'routing.request_prepared' => ['severity' => 'info', 'outcome' => 'ok', 'context' => ['phase', 'route', 'actor_a_id', 'actor_b_id', 'speaker_id', 'exclude_player', 'bystander_mode', 'member_count', 'opener_source', 'free_scene', 'scene_card', 'pace', 'wrap_up', 'sharmat_listener']],
        'routing.request_skipped' => ['severity' => 'info', 'outcome' => 'skipped', 'context' => ['phase', 'request_type', 'state_status', 'mode']],
        'routing.request_blocked' => ['severity' => 'warning', 'outcome' => 'blocked', 'context' => ['phase', 'request_type', 'actor_a_id', 'actor_b_id']],
        'routing.action_dropped' => ['severity' => 'warning', 'outcome' => 'blocked', 'context' => ['phase', 'request_type']],
        'routing.request_error' => ['severity' => 'error', 'outcome' => 'failed', 'context' => ['phase', 'request_type', 'actor_a_id', 'actor_b_id']],
        'routing.request_detail' => ['severity' => 'debug', 'outcome' => 'ok', 'context' => ['phase', 'decision', 'request_type', 'actor_a_id', 'actor_b_id', 'speaker_id', 'audience_before_count', 'audience_after_count', 'present_before_count', 'present_after_count']],
        'reflection.registration_skipped' => ['severity' => 'info', 'outcome' => 'skipped', 'context' => ['phase', 'route', 'actor_a_id']],
        'reflection.ack_skipped' => ['severity' => 'info', 'outcome' => 'skipped', 'context' => ['phase', 'route', 'actor_a_id']],
        'reflection.ack_pending' => ['severity' => 'debug', 'outcome' => 'skipped', 'context' => ['phase', 'route', 'actor_a_id']],
        'reflection.registration_error' => ['severity' => 'error', 'outcome' => 'failed', 'context' => ['phase', 'route', 'actor_a_id']],
        'reflection.ack_error' => ['severity' => 'error', 'outcome' => 'failed', 'context' => ['phase', 'route', 'actor_a_id']],
        'reflection.output_registered' => ['severity' => 'info', 'outcome' => 'accepted', 'context' => ['phase', 'route', 'actor_a_id']],
        'reflection.evaluation_finished' => ['severity' => 'info', 'outcome' => 'accepted', 'context' => ['phase', 'route', 'actor_a_id']],
        'reflection.observer_unavailable' => ['severity' => 'info', 'outcome' => 'unavailable', 'context' => ['phase', 'route', 'actor_a_id']],
        'routing.request_finished' => [
            'outcomes' => ['postrequest_observed' => 'info', 'skipped' => 'info', 'blocked' => 'warning', 'failed' => 'error', 'unobserved' => 'warning'],
            'reason_by_outcome' => [
                'postrequest_observed' => [],
                'skipped' => ['scope_off', 'scope_pending', 'identity_unavailable', 'unsupported_mode', 'scene_not_eligible', 'scope_ineligible', 'ended_in_game', 'background_paused'],
                'blocked' => ['unsupported_special_mode', 'invalid_input_prefix', 'invalid_input_encoding', 'empty_input', 'malformed_rechat',
                    'rechat_speaker_outside_pair', 'speaker_outside_pair', 'rechat_speaker_outside_scene', 'speaker_outside_scene', 'solo_rechat_unsupported', 'solo_unrouted_request', 'pair_continuation_player_excluded', 'mode_changed', 'scene_not_eligible'],
                'failed' => ['state_unavailable', 'actors_unavailable', 'player_identity_unavailable', 'profile_switch_failed', 'actions_unavailable', 'context_unavailable', 'hook_exception', 'fatal_error'],
                'unobserved' => ['request_unobserved'],
            ],
            'reason_required' => ['skipped', 'blocked', 'failed', 'unobserved'],
            'context' => ['phase', 'route', 'request_type', 'actor_a_id', 'actor_b_id'],
        ],
        'state.presence_observed' => [
            'outcomes' => ['available' => 'info', 'empty' => 'info', 'stale' => ['presence_stale' => 'info'],
            'unavailable' => ['presence_baseline' => 'info', 'presence_missing' => 'info', 'identity_unavailable' => 'info', 'presence_invalid' => 'warning',
                    'presence_key_mismatch' => 'warning', 'presence_unavailable' => 'error']],
            'reason_by_outcome' => ['available' => [], 'empty' => [], 'stale' => ['presence_stale'],
                'unavailable' => ['presence_baseline', 'presence_missing', 'identity_unavailable', 'presence_invalid', 'presence_key_mismatch', 'presence_unavailable']],
            'reason_required' => ['stale', 'unavailable'],
            'context' => ['source', 'actor_count'],
        ],
        'reflection.model_finished' => [
            'outcomes' => ['valid' => 'info', 'invalid' => 'warning', 'failed' => 'error'],
            'reason_by_outcome' => ['valid' => [], 'invalid' => ['model_invalid'], 'failed' => ['model_failed']],
            'reason_required' => ['invalid', 'failed'],
            'context' => ['phase', 'route', 'actor_a_id', 'model_outcome', 'model_ms', 'source_reason'],
        ],
        'reflection.persistence_finished' => [
            'outcomes' => ['committed' => 'info', 'zero_change' => 'info', 'unconfirmed' => 'error', 'rejected' => 'warning',
                'skipped' => 'info', 'failed' => 'error', 'cleanup_failed' => 'error'],
            'reason_by_outcome' => ['committed' => [], 'zero_change' => ['zero_change'], 'unconfirmed' => ['commit_unconfirmed'],
                'rejected' => ['persistence_rejected'], 'skipped' => ['persistence_stale'], 'failed' => ['persistence_failed'], 'cleanup_failed' => ['cleanup_failed']],
            'reason_required' => ['zero_change', 'unconfirmed', 'rejected', 'skipped', 'failed', 'cleanup_failed'],
            'context' => ['phase', 'route', 'actor_a_id', 'persistence_outcome', 'commit_state', 'committed', 'cleanup_failed', 'changed_count', 'changes', 'model_ms', 'persistence_ms', 'source_reason'],
        ],
        'reflection.evaluation_result' => [
            'outcomes' => ['committed' => 'info', 'zero_change' => 'info', 'unconfirmed' => 'error', 'rejected' => 'warning',
                'skipped' => 'info', 'failed' => 'error', 'cleanup_failed' => 'error'],
            'reason_by_outcome' => ['committed' => [], 'zero_change' => ['zero_change'], 'unconfirmed' => ['commit_unconfirmed'],
                'rejected' => ['evaluation_rejected'], 'skipped' => ['evaluation_skipped'], 'failed' => ['evaluation_failed'], 'cleanup_failed' => ['cleanup_failed']],
            'reason_required' => ['zero_change', 'unconfirmed', 'rejected', 'skipped', 'failed', 'cleanup_failed'],
            'context' => ['phase', 'route', 'actor_a_id', 'model_outcome', 'persistence_outcome', 'commit_state', 'committed', 'cleanup_failed', 'changed_count', 'model_ms', 'persistence_ms', 'source_reason'],
        ],
        'ui.browser_refresh_failed' => [
            'outcomes' => ['reported' => 'warning'],
            'reason_by_outcome' => ['reported' => ['browser_refresh_timeout', 'browser_refresh_network', 'browser_refresh_http', 'browser_refresh_invalid_response', 'browser_refresh_unknown']],
            'reason_required' => ['reported'],
            'context' => ['source'],
        ],
        'ui.logs_read' => [
            'outcomes' => ['returned' => 'info', 'empty' => 'info', 'busy' => 'warning', 'limited' => 'warning', 'unavailable' => 'error'],
            'reason_by_outcome' => ['returned' => [], 'empty' => [], 'busy' => [], 'limited' => ['logs_read_limited'], 'unavailable' => ['logs_read_failed']],
            'reason_required' => ['limited', 'unavailable'],
            'context' => ['source', 'returned_count', 'omitted_count'],
        ],
        'ui.diagnostics_rejected' => ['severity' => 'warning', 'outcome' => 'rejected', 'context' => ['operation', 'source']],
        'ui.log_export' => [
            'outcomes' => ['exported' => 'info', 'empty' => 'info', 'busy' => 'warning', 'limited' => 'warning', 'unavailable' => 'error'],
            'reason_by_outcome' => ['exported' => [], 'empty' => [], 'busy' => [], 'limited' => ['logs_read_limited'], 'unavailable' => ['log_export_failed']],
            'reason_required' => ['limited', 'unavailable'],
            'context' => ['source', 'returned_count', 'omitted_count'],
        ],
    ];
    return $rules;
}

function pcv_log_reason_codes(): array
{
    return [
        'active_ttl', 'pending_ttl', 'invalid_state_key', 'identity_unavailable', 'state_unavailable', 'reply_in_progress',
        'left_scene', 'not_eligible_at_start', 'speaker_outside_scene', 'rechat_speaker_outside_scene', 'members_gone', 'evidence_unavailable', 'scope_unavailable', 'ended_in_game', 'wrapped_up', 'background_paused', 'action_not_allowed', 'target_outside_scene',
        'corrupt_state', 'symlinked_state', 'not_regular_file', 'state_stat_failed', 'state_too_large',
        'state_read_failed', 'invalid_json', 'invalid_state', 'state_stage_failed', 'state_transition_failed', 'profile_lookup_failed',
        'session_unavailable', 'catalog_unavailable', 'readback_mismatch',
        'invalid_configuration', 'actor_unavailable', 'actor_ambiguous', 'missing_settings', 'access_denied', 'csrf_failed',
        'invalid_filter', 'invalid_failure_code',
        'internal_error', 'playthrough_changed', 'scope_off', 'scope_pending', 'unsupported_mode', 'unsupported_special_mode',
        'invalid_input_prefix', 'invalid_input_encoding', 'empty_input', 'malformed_rechat', 'rechat_speaker_outside_pair', 'speaker_outside_pair',
        'solo_rechat_unsupported', 'solo_unrouted_request', 'pair_continuation_player_excluded', 'mode_changed',
        'scope_ineligible', 'baseline_stale', 'output_unavailable', 'output_malformed', 'sentinel_mismatch',
        'event_unmatched', 'registry_unavailable', 'registry_corrupt', 'registration_missing', 'registration_stale',
        'claim_taken', 'registration_busy', 'receipt_busy', 'receipt_unavailable', 'receipt_corrupt', 'ack_conflict',
        'interaction_stale', 'native_ack_ambiguous', 'ack_mismatch', 'source_aborted', 'scope_changed', 'identity_changed',
        'mind_poisoning_unavailable', 'reflection_api_incompatible', 'evaluation_rejected', 'database_unavailable', 'observer_unsupported',
        'actors_unavailable', 'player_identity_unavailable', 'profile_switch_failed', 'actions_unavailable',
        'context_unavailable', 'hook_exception', 'debug_detail', 'presence_unavailable', 'presence_stale',
        'presence_baseline', 'presence_missing', 'presence_invalid', 'presence_key_mismatch', 'pair_not_eligible', 'scene_not_eligible',
        'request_unobserved', 'fatal_error', 'model_invalid', 'model_failed', 'zero_change', 'commit_unconfirmed',
        'cleanup_failed', 'persistence_rejected', 'persistence_stale', 'persistence_failed', 'evaluation_skipped',
        'evaluation_failed', 'browser_refresh_timeout', 'browser_refresh_network', 'browser_refresh_http',
        'browser_refresh_invalid_response', 'browser_refresh_unknown', 'logs_read_limited', 'logs_read_failed', 'log_export_failed',
    ];
}

function pcv_log_rule_matches(string $event, string $severity, string $outcome, ?string $reason): bool
{
    $rule = pcv_log_event_rules()[$event] ?? null;
    if (!is_array($rule)) {
        return false;
    }
    if (isset($rule['outcomes'])) {
        $expected = $rule['outcomes'][$outcome] ?? null;
        if (is_array($expected)) {
            $expected = $reason === null ? null : ($expected[$reason] ?? null);
        }
        if ($expected !== $severity) {
            return false;
        }
    } elseif (($rule['severity'] ?? null) !== $severity || ($rule['outcome'] ?? null) !== $outcome) {
        return false;
    }
    return pcv_log_reason_allowed($event, $reason, $outcome);
}

function pcv_log_event_context_valid(string $event, array $context): bool
{
    if ($event !== 'ui.diagnostics_rejected') {
        return true;
    }
    return in_array($context['operation'] ?? null, ['logs_read', 'log_export', 'browser_report'], true)
        && ($context['source'] ?? null) === 'browser';
}

function pcv_log_reason_allowed(string $event, ?string $reason, ?string $outcome = null): bool
{
    $rule = pcv_log_event_rules()[$event] ?? null;
    if (!is_array($rule)) {
        return false;
    }
    if (isset($rule['outcomes'])) {
        $allowed = $rule['reason_by_outcome'][$outcome] ?? null;
        if (!is_array($allowed)) {
            return false;
        }
        if ($reason === null) {
            return !in_array($outcome, $rule['reason_required'] ?? [], true) && $allowed === [];
        }
        return in_array($reason, $allowed, true);
    }
    if ($reason === null) {
        return !in_array($event, ['routing.action_dropped', 'state.store_recovered', 'state.scope_expired', 'state.unavailable', 'ui.unavailable', 'ui.scope_stage_rejected', 'ui.scope_stage_failed',
            'ui.diagnostics_rejected', 'routing.request_skipped', 'routing.request_blocked', 'routing.request_error', 'state.scope_skipped'], true);
    }

    if (!in_array($reason, pcv_log_reason_codes(), true)) {
        return false;
    }
    if ($event === 'state.scope_expired') {
        return in_array($reason, ['active_ttl', 'pending_ttl'], true);
    }
    if ($event === 'state.scope_skipped') {
        return $reason === 'scene_not_eligible';
    }
    if ($event === 'reflection.ack_pending') {
        return $reason === 'reply_in_progress';
    }
    if ($event === 'state.scope_members_dropped') {
        return $reason === 'left_scene';
    }
    if ($event === 'state.presence_rejected') {
        return $reason === 'presence_stale';
    }
    if ($event === 'ui.scope_stage_rejected') {
        return in_array($reason, ['invalid_configuration', 'actor_unavailable', 'actor_ambiguous', 'missing_settings', 'state_unavailable', 'access_denied', 'csrf_failed', 'internal_error', 'presence_missing', 'presence_stale', 'presence_unavailable', 'presence_invalid', 'presence_key_mismatch'], true);
    }
    if ($event === 'ui.unavailable') {
        return in_array($reason, ['session_unavailable', 'identity_unavailable', 'catalog_unavailable', 'state_unavailable'], true);
    }
    if ($event === 'ui.diagnostics_rejected') {
        return in_array($reason, ['access_denied', 'csrf_failed', 'invalid_filter', 'invalid_failure_code'], true);
    }
    if ($event === 'ui.scope_stage_failed') {
        return in_array($reason, ['state_unavailable', 'readback_mismatch', 'internal_error'], true);
    }
    if ($event === 'routing.request_skipped') {
        return in_array($reason, ['scope_off', 'scope_pending', 'identity_unavailable', 'unsupported_mode', 'scene_not_eligible', 'ended_in_game', 'background_paused'], true);
    }
    if ($event === 'routing.request_blocked') {
        return in_array($reason, ['unsupported_special_mode', 'invalid_input_prefix', 'invalid_input_encoding', 'empty_input', 'malformed_rechat', 'rechat_speaker_outside_pair', 'speaker_outside_pair', 'rechat_speaker_outside_scene', 'speaker_outside_scene', 'solo_rechat_unsupported', 'solo_unrouted_request', 'pair_continuation_player_excluded', 'mode_changed', 'scene_not_eligible'], true);
    }
    if ($event === 'routing.request_error') {
        return in_array($reason, ['state_unavailable', 'actors_unavailable', 'player_identity_unavailable', 'profile_switch_failed', 'actions_unavailable', 'context_unavailable', 'hook_exception'], true);
    }
    if ($event === 'state.unavailable') {
        return in_array($reason, ['invalid_state_key', 'identity_unavailable', 'state_unavailable', 'corrupt_state', 'symlinked_state', 'not_regular_file', 'state_stat_failed', 'state_too_large', 'state_read_failed', 'invalid_json', 'invalid_state', 'state_stage_failed', 'state_transition_failed', 'profile_lookup_failed', 'catalog_unavailable', 'presence_unavailable', 'presence_stale', 'presence_missing', 'presence_invalid', 'presence_key_mismatch', 'unsupported_special_mode'], true);
    }
    if ($event === 'state.scope_ended') {
        return in_array($reason, ['members_gone', 'evidence_unavailable', 'ended_in_game', 'wrapped_up'], true);
    }
    if ($event === 'routing.action_dropped') {
        return in_array($reason, ['action_not_allowed', 'target_outside_scene'], true);
    }
    if ($event === 'state.store_recovered') {
        return in_array($reason, ['invalid_json', 'invalid_state', 'state_too_large'], true);
    }
    if ($event === 'state.scope_invalidated') {
        return $reason === 'playthrough_changed';
    }
    if ($event === 'routing.request_detail') {
        return $reason === 'debug_detail';
    }
    if (in_array($event, ['reflection.registration_skipped', 'reflection.ack_skipped'], true)) {
        return in_array($reason, [
            'scope_ineligible', 'baseline_stale', 'output_unavailable', 'output_malformed', 'sentinel_mismatch',
            'event_unmatched', 'registry_unavailable', 'registry_corrupt', 'registration_missing', 'registration_stale',
            'claim_taken', 'ack_mismatch', 'source_aborted', 'scope_changed', 'identity_changed', 'scope_unavailable',
            'mind_poisoning_unavailable', 'reflection_api_incompatible', 'evaluation_rejected', 'registration_busy',
            'receipt_busy', 'ack_conflict', 'interaction_stale', 'native_ack_ambiguous',
        ], true);
    }
    if (in_array($event, ['reflection.registration_error', 'reflection.ack_error'], true)) {
        return in_array($reason, [
            'registry_unavailable', 'registry_corrupt', 'database_unavailable',
            'mind_poisoning_unavailable', 'reflection_api_incompatible', 'evaluation_failed', 'internal_error',
            'receipt_unavailable', 'receipt_corrupt',
        ], true);
    }
    if ($event === 'reflection.output_registered') {
        return $reason === null;
    }
    if ($event === 'reflection.evaluation_finished') {
        return $reason === null;
    }
    if ($event === 'reflection.observer_unavailable') {
        return $reason === 'observer_unsupported';
    }
    return false;
}

function pcv_log_enum_values(string $key): array
{
    static $values = [
        'action' => ['enable', 'end'],
        'scene_mode' => ['pair', 'solo'],
        'bystander_mode' => ['exclude', 'silent'],
        'target' => ['active', 'pending'],
        'operation' => ['session', 'identity', 'catalog', 'read', 'stage', 'readback', 'begin', 'presence_capture', 'presence_read', 'presence_invalidate', 'logs_read', 'log_export', 'browser_report'],
        'status' => ['active', 'pending', 'off', 'unavailable'],
        'request_type' => ['inputtext', 'inputtext_s', 'ginputtext', 'ginputtext_s', 'rechat', 'narrator_inputtext', 'chat', 'prechat', 'continue', 'continue_group', 'instruction', 'bored', 'narration', 'other'],
        'phase' => ['preprocessing', 'prerequest', 'context_pre', 'context', 'postrequest', 'registration', 'ack', 'shutdown'],
        'route' => ['scene_direction', 'player_speech', 'rechat_clamped', 'pair_continuation', 'generated_event', 'solo_reflection'],
        'state_status' => ['off', 'pending', 'identity_unavailable', 'active', 'unavailable'],
        'mode' => ['standard', 'close', 'whisper', 'autochat', 'other'],
        'source' => ['ordinary_capture', 'autonomous_capture', 'background_capture', 'ordinary_read', 'background_read', 'browser'],
        'model_outcome' => ['valid', 'invalid', 'failed', 'not_called'],
        'persistence_outcome' => ['committed', 'invalid', 'stale', 'failed'],
        'commit_state' => ['confirmed', 'unconfirmed', 'not_attempted'],
        'presence_check' => ['close', 'grace_expired', 'wide_absent', 'wide_unavailable', 'presence_error'],
        'drop_reason' => ['not_eligible_at_start', 'left_scene'],
        'opener_source' => ['named', 'picker', 'first', 'target', 'nearest'],
        'pace' => ['short', 'normal', 'long'],
        'decision' => ['non_candidate_request', 'director_excluded', 'scope_off', 'scope_pending', 'identity_unavailable', 'unsupported_mode', 'input_rewritten', 'player_speech_preserved', 'solo_reflection_routed', 'rechat_clamped', 'continuation_routed', 'responder_selected', 'context_prepared', 'action_constraints_refreshed', 'action_instructions_removed'],
    ];
    if ($key === 'source_reason') {
        return pcv_log_mp_reason_codes();
    }
    return $values[$key] ?? [];
}

function pcv_log_mp_reason_codes(): array
{
    return [
        'other', 'committed', 'zero-change', 'interaction_off', 'interaction_helpers_unavailable', 'interaction_state_invalid',
        'interaction_generation_stale', 'ok', 'pause_control_invalid', 'plugin_paused', 'restore-policy', 'connector-disabled',
        'connector-invalid', 'reflection-registration-invalid', 'reflection-scope-stale', 'reflection-ack-mismatch',
        'reflection-source-unmatched', 'reflection-source-mismatch', 'reflection-registration-stale', 'reflection-listener-ambiguous',
        'reflection-actor-unmatched', 'reflection-actor-stale', 'reflection-actor-state-invalid', 'relationship-locked',
        'duplicate-event', 'ledger-invalid', 'reflection-too-many-subjects', 'reflection-no-subjects', 'relationships-invalid',
        'player-alias-ambiguous', 'reflection-subject-stale', 'reflection-basis-unavailable', 'reflection-basis-stale',
        'reflection-basis-duplicate', 'reflection-subject-cap', 'connector_id_invalid', 'connector_unavailable', 'connector_not_found',
        'connector_driver_unsupported', 'connector_config_incomplete', 'connector_api_key_missing', 'model_response_empty',
        'model_response_invalid_type', 'model_request_failed', 'invalid-event', 'invalid-opinion-owner', 'listener-busy',
        'event-stale', 'invalid-opinion-owner', 'reflection-subjects-invalid', 'player-identity-stale', 'listener-identity-stale',
        'listener-state-invalid', 'input-audience-stale', 'actor-catalog-stale', 'subject-catalog-stale', 'subject-stale',
        'speaker-stale', 'relationships-invalid', 'edge-invalid', 'affinity-invalid', 'ledger-floor', 'timeline-invalid',
        'listener-write-failed', 'listener-verification-failed', 'snapshot-verification-failed', 'commit-failed',
        'validation-failed', 'begin-listener-failed', 'revalidate-event-failed', 'revalidate-actors-failed', 'revalidate-subject-failed',
        'revalidate-speaker-failed', 'resolve-relationships-failed', 'validate-ledger-failed', 'update-ledger-failed',
        'validate-timeline-failed', 'write-listener-failed', 'verify-listener-failed', 'verify-snapshot-failed', 'rollback-failed', 'release-failed',
    ];
}

function pcv_log_mp_reason_code(string $reason): string
{
    return in_array($reason, pcv_log_mp_reason_codes(), true) ? $reason : 'other';
}

function pcv_log_valid_actor_id($value): bool
{
    return is_string($value) && strlen($value) <= 20
        && preg_match('/^[1-9][0-9]*$/D', $value) === 1;
}

function pcv_log_clean_correlation(array $correlation): array
{
    $clean = [];
    if (is_string($correlation['event_id'] ?? null) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $correlation['event_id']) === 1) {
        $clean['event_id'] = $correlation['event_id'];
    }
    if (pcv_log_valid_utterance_id($correlation['utterance_id'] ?? null)) {
        $clean['utterance_id'] = $correlation['utterance_id'];
    }
    if (is_string($correlation['linked_request_id'] ?? null)
        && preg_match('/\A[a-f0-9]{24}\z/D', $correlation['linked_request_id']) === 1) {
        $clean['linked_request_id'] = $correlation['linked_request_id'];
    }
    return $clean;
}

function pcv_log_clean_changes($changes): ?array
{
    if (!is_array($changes) || !array_is_list($changes)) {
        return null;
    }
    $clean = [];
    foreach (array_slice($changes, 0, 8) as $change) {
        if (!is_array($change)) {
            continue;
        }
        $subject = $change['subject'] ?? null;
        if ($subject !== 'player' && (!is_string($subject) || preg_match('/\Anpc:[1-9][0-9]{0,18}\z/D', $subject) !== 1)) {
            continue;
        }
        $delta = $change['delta'] ?? null;
        $before = $change['before'] ?? null;
        $after = $change['after'] ?? null;
        if (!is_int($delta) || $delta < -5 || $delta > 5
            || (!is_int($before) && !is_float($before)) || !is_finite((float)$before) || $before < -100 || $before > 100
            || (!is_int($after) && !is_float($after)) || !is_finite((float)$after) || $after < -100 || $after > 100) {
            continue;
        }
        $clean[] = ['subject' => $subject, 'delta' => $delta, 'before' => $before, 'after' => $after];
    }
    return $clean;
}

function pcv_log_clean_context(string $event, array $context): array
{
    $rules = pcv_log_event_rules()[$event]['context'] ?? [];
    $clean = [];
    foreach ($rules as $key) {
        if (!array_key_exists($key, $context)) {
            continue;
        }
        $value = $context[$key];
        if (in_array($key, ['actor_a_id', 'actor_b_id', 'speaker_id'], true)) {
            if (pcv_log_valid_actor_id($value)) {
                $clean[$key] = $value;
            }
        } elseif (in_array($key, ['exclude_player', 'pending', 'committed', 'cleanup_failed', 'free_scene', 'scene_card', 'wrap_up', 'sharmat_listener'], true)) {
            if (is_bool($value)) {
                $clean[$key] = $value;
            }
        } elseif ($key === 'changes') {
            $changes = pcv_log_clean_changes($value);
            if ($changes !== null) {
                $clean[$key] = $changes;
            }
        } elseif ($key === 'correlation' && is_array($value)) {
            $correlation = pcv_log_clean_correlation($value);
            if ($correlation !== []) {
                $clean[$key] = $correlation;
            }
        } elseif (str_ends_with($key, '_count')) {
            if (is_int($value) && $value >= 0 && $value <= 10000) {
                $clean[$key] = $value;
            }
        } elseif (in_array($key, ['model_ms', 'persistence_ms'], true)) {
            if ((is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= 0 && $value <= 86400000) {
                $clean[$key] = is_int($value) ? $value : round($value, 2);
            }
        } elseif (in_array($key, ['action', 'scene_mode', 'bystander_mode', 'target', 'operation', 'status', 'request_type', 'phase', 'route', 'state_status', 'mode', 'decision', 'source', 'model_outcome', 'persistence_outcome', 'commit_state', 'source_reason', 'presence_check', 'drop_reason', 'opener_source', 'pace'], true)) {
            if (is_string($value) && in_array($value, pcv_log_enum_values($key), true)) {
                $clean[$key] = $value;
            }
        }
    }

    foreach (['active_config_id', 'pending_config_id', 'exception_class', 'exception_code', 'source_file', 'source_line'] as $key) {
        if (!array_key_exists($key, $context)) {
            continue;
        }
        $value = $context[$key];
        if (in_array($key, ['active_config_id', 'pending_config_id'], true)
            && is_string($value) && pcv_log_valid_uuid($value)) {
            $clean[$key] = $value;
        } elseif ($key === 'exception_class' && is_string($value)
            && preg_match('~^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$~D', $value) === 1) {
            $clean[$key] = $value;
        } elseif ($key === 'exception_code' && is_int($value)) {
            $clean[$key] = $value;
        } elseif ($key === 'source_file' && is_string($value)
            && preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $value) === 1) {
            $clean[$key] = $value;
        } elseif ($key === 'source_line' && is_int($value) && $value > 0) {
            $clean[$key] = $value;
        }
    }
    if (is_array($context['correlation'] ?? null)) {
        $correlation = pcv_log_clean_correlation($context['correlation']);
        if ($correlation !== []) {
            $clean['correlation'] = $correlation;
        }
    }
    return $clean;
}

function pcv_log_plugin_version(): string
{
    static $version = null;
    if (is_string($version)) {
        return $version;
    }
    $version = 'unknown';
    $manifest = __DIR__ . '/manifest.json';
    if (is_file($manifest) && !is_link($manifest)) {
        $contents = @file_get_contents($manifest);
        if (is_string($contents)) {
            $decoded = json_decode($contents, true);
            $candidate = is_array($decoded) ? ($decoded['version'] ?? null) : null;
            if (is_string($candidate) && preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/D', $candidate) === 1) {
                $version = $candidate;
            }
        }
    }
    return $version;
}

function pcv_log_effective_uid(): ?int
{
    if (function_exists('posix_geteuid')) {
        return posix_geteuid();
    }
    return null;
}

function pcv_log_absolute_path(string $path): bool
{
    return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1
        || str_starts_with($path, '\\\\');
}

function pcv_log_path_is_within(string $path, string $parent): bool
{
    $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    $parent = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $parent), DIRECTORY_SEPARATOR);
    if (PHP_OS_FAMILY === 'Windows') {
        $path = strtolower($path);
        $parent = strtolower($parent);
    }
    return $path === $parent || str_starts_with($path, $parent . DIRECTORY_SEPARATOR);
}

function pcv_log_document_root()
{
    $root = $_SERVER['DOCUMENT_ROOT'] ?? getenv('DOCUMENT_ROOT');
    if (!is_string($root) || $root === '') {
        return PHP_SAPI === 'cli' ? null : false;
    }
    $canonical = realpath($root);
    return $canonical === false ? false : $canonical;
}

function pcv_log_known_webroot(): ?string
{
    $directory = realpath(__DIR__);
    if ($directory === false) {
        return null;
    }
    for ($depth = 0; $depth < 6; $depth++) {
        if (strtolower(basename($directory)) === 'ext') {
            $serverRoot = realpath(dirname($directory));
            if ($serverRoot === false) {
                return null;
            }
            // Without DOCUMENT_ROOT, conservatively treat the server root's parent as public.
            return realpath(dirname($serverRoot)) ?: $serverRoot;
        }
        $parent = dirname($directory);
        if ($parent === $directory) {
            break;
        }
        $directory = $parent;
    }
    return null;
}

function pcv_log_has_symlink_component(string $path): bool
{
    $path = str_replace('\\', '/', $path);
    if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
        $current = substr($path, 0, 3);
        $parts = explode('/', substr($path, 3));
    } elseif (str_starts_with($path, '/')) {
        $current = '/';
        $parts = explode('/', substr($path, 1));
    } else {
        return true;
    }
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        $current = rtrim($current, '/') . '/' . $part;
        clearstatcache(true, $current);
        if (is_link($current)) {
            return true;
        }
    }
    return false;
}

function pcv_log_directory_is_safe(string $directory, bool $mustExist): bool
{
    if ($directory === '' || str_contains($directory, "\0") || !pcv_log_absolute_path($directory)
        || pcv_log_has_symlink_component($directory)) {
        return false;
    }
    clearstatcache(true, $directory);
    if (!file_exists($directory)) {
        return !$mustExist && pcv_log_outside_webroot($directory);
    }
    if (!is_dir($directory)) {
        return false;
    }
    $canonical = realpath($directory);
    $uid = pcv_log_effective_uid();
    $owner = @fileowner($directory);
    $permissions = @fileperms($directory);
    if ($canonical === false || $uid === null || $owner !== $uid || $permissions === false
        || (($permissions & 0077) !== 0) || (($permissions & 0700) !== 0700)) {
        return false;
    }
    return pcv_log_outside_webroot($canonical);
}

function pcv_log_outside_webroot(string $directory): bool
{
    $knownWebroot = pcv_log_known_webroot();
    if ($knownWebroot !== null && pcv_log_path_is_within($directory, $knownWebroot)) {
        return false;
    }
    $documentRoot = pcv_log_document_root();
    if ($documentRoot === false) {
        return false;
    }
    if ($documentRoot === null) {
        return PHP_SAPI === 'cli';
    }
    return !pcv_log_path_is_within($directory, $documentRoot);
}

function pcv_log_default_directory(bool $create): ?string
{
    $base = realpath(sys_get_temp_dir());
    $uid = pcv_log_effective_uid();
    $extensionPath = realpath(__DIR__);
    if ($base === false || $uid === null || $extensionPath === false || is_link(sys_get_temp_dir())) {
        return null;
    }
    $directory = $base . DIRECTORY_SEPARATOR . 'private-conversation-' . $uid . '-'
        . substr(hash('sha256', $extensionPath), 0, 16);
    clearstatcache(true, $directory);
    if (!file_exists($directory) && $create) {
        if (@mkdir($directory, 0700)) {
            @chmod($directory, 0700);
        } elseif (!is_dir($directory)) {
            return null;
        }
    }
    if (file_exists($directory) && !pcv_log_directory_is_safe($directory, true)) {
        return null;
    }
    return pcv_log_outside_webroot($directory) ? $directory : null;
}

function pcv_log_resolve_directory(bool $create): ?string
{
    $request =& pcv_log_request_context();
    if (is_string($request['test_directory'])) {
        $request['storage_mode'] = 'test';
        $request['storage_reason'] = null;
        return pcv_log_directory_is_safe($request['test_directory'], true) ? $request['test_directory'] : null;
    }

    $override = getenv('PCV_LOG_DIR');
    if (is_string($override) && $override !== '' && pcv_log_directory_is_safe($override, true)) {
        $request['storage_mode'] = 'external';
        $request['storage_reason'] = null;
        return realpath($override) ?: null;
    }
    $fallback = pcv_log_default_directory($create);
    if (is_string($override) && $override !== '') {
        $request['storage_mode'] = $fallback === null ? 'unavailable' : 'temporary_fallback';
        $request['storage_reason'] = $fallback === null ? 'default_unavailable' : 'override_invalid';
        if ($fallback !== null) {
            pcv_log_fallback_once('override_invalid');
        }
        return $fallback;
    }
    $request['storage_mode'] = $fallback === null ? 'unavailable' : 'temporary';
    $request['storage_reason'] = $fallback === null ? 'default_unavailable' : null;
    return $fallback;
}

function pcv_log_storage_health(): array
{
    $request =& pcv_log_request_context();
    $mode = $request['storage_mode'];
    $reason = $request['storage_reason'];
    if (!is_string($mode)) {
        $override = getenv('PCV_LOG_DIR');
        if (is_string($override) && $override !== '') {
            $mode = pcv_log_directory_is_safe($override, true) ? 'external' : 'temporary_fallback';
            $reason = $mode === 'external' ? null : 'override_invalid';
            if ($mode === 'temporary_fallback' && pcv_log_default_directory(false) === null) {
                $mode = 'unavailable';
                $reason = 'default_unavailable';
            }
        } else {
            $mode = pcv_log_default_directory(false) === null ? 'unavailable' : 'temporary';
            $reason = $mode === 'unavailable' ? 'default_unavailable' : null;
        }
    }
    return [
        'storage_mode' => $mode,
        'reason' => $reason,
        'write_status' => $request['write_status'],
        'failure_codes' => array_keys($request['failure_codes']),
        'retention' => 'bounded_rotation',
        'max_files' => PCV_LOG_MAX_FILES,
        'max_file_bytes' => PCV_LOG_MAX_FILE_BYTES,
        'max_entry_bytes' => PCV_LOG_MAX_ENTRY_BYTES,
        'completeness' => 'bounded_history',
    ];
}

function pcv_log_path(): ?string
{
    if (PHP_SAPI !== 'cli') {
        return null;
    }
    try {
        $directory = pcv_log_resolve_directory(false);
        return $directory === null ? null : $directory . DIRECTORY_SEPARATOR . 'events.jsonl';
    } catch (Throwable) {
        return null;
    }
}

function pcv_log_set_test_directory(string $directory): bool
{
    if (PHP_SAPI !== 'cli' || !defined('PCV_LOG_TESTING') || PCV_LOG_TESTING !== true) {
        return false;
    }
    clearstatcache(true, $directory);
    $canonical = realpath($directory);
    $tempRoot = realpath(sys_get_temp_dir());
    if ($canonical === false || $tempRoot === false || is_link($directory)
        || !pcv_log_path_is_within($canonical, $tempRoot)
        || !pcv_log_directory_is_safe($canonical, true)) {
        return false;
    }
    $request =& pcv_log_request_context();
    $request['test_directory'] = $canonical;
    return true;
}

function pcv_log_fallback_once(string $code): void
{
    try {
        $request =& pcv_log_request_context();
        $request['write_status'] = 'degraded';
        $allowedCodes = ['directory_unavailable', 'override_invalid', 'lock_unavailable', 'rotation_failed', 'append_failed', 'entry_too_large', 'invalid_event', 'logger_failed'];
        if (!in_array($code, $allowedCodes, true)) {
            $code = 'logger_failed';
        }
        $request['failure_codes'][$code] = true;
        if (isset($request['fallback_codes'][$code]) || count($request['fallback_codes']) >= count($allowedCodes)) {
            return;
        }
        $request['fallback_codes'][$code] = true;
        $configId = is_string($request['config_id']) && pcv_log_valid_uuid($request['config_id'])
            ? $request['config_id']
            : 'none';
        @error_log('Private Conversation logger: ' . $code
            . ' request_id=' . $request['request_id']
            . ' config_id=' . $configId);
    } catch (Throwable) {
        // Logging fallback must not escape through CHIM's process-wide error handler.
    }
}

function pcv_log_fallback_event_line(string $line): void
{
    if (strlen($line) > PCV_LOG_MAX_ENTRY_BYTES || !str_ends_with($line, "\n")) {
        return;
    }
    try {
        @error_log('Private Conversation event fallback: ' . rtrim($line, "\r\n"));
    } catch (Throwable) {
        // The PHP error-log sink is best effort; never break the request.
    }
}

function pcv_log_private_file(string $path): bool
{
    if (is_link($path) || !is_file($path)) {
        return false;
    }
    clearstatcache(true, $path);
    $uid = pcv_log_effective_uid();
    $owner = @fileowner($path);
    $permissions = @fileperms($path);
    return $uid !== null && $owner === $uid && $permissions !== false
        && (($permissions & 0077) === 0) && (($permissions & 0600) === 0600);
}

function pcv_log_rotate(string $directory): bool
{
    $active = $directory . DIRECTORY_SEPARATOR . 'events.jsonl';
    for ($index = PCV_LOG_MAX_FILES - 1; $index >= 1; $index--) {
        $source = $index === 1 ? $active : $directory . DIRECTORY_SEPARATOR . 'events.' . ($index - 1) . '.jsonl';
        $destination = $directory . DIRECTORY_SEPARATOR . 'events.' . $index . '.jsonl';
        clearstatcache(true, $source);
        clearstatcache(true, $destination);
        if (is_link($source) || is_link($destination)) {
            return false;
        }
        if (!file_exists($source)) {
            continue;
        }
        if (!pcv_log_private_file($source)) {
            return false;
        }
        if (file_exists($destination)) {
            if (!pcv_log_private_file($destination) || !@unlink($destination)) {
                return false;
            }
        }
        if (!@rename($source, $destination)) {
            return false;
        }
        @chmod($destination, 0600);
    }
    return true;
}

function pcv_log_write_line(string $line): bool
{
    $directory = pcv_log_resolve_directory(true);
    if ($directory === null) {
        pcv_log_fallback_once('directory_unavailable');
        return false;
    }
    $lockPath = $directory . DIRECTORY_SEPARATOR . 'events.lock';
    if (is_link($lockPath) || (file_exists($lockPath) && !pcv_log_private_file($lockPath))) {
        pcv_log_fallback_once('lock_unavailable');
        return false;
    }
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        pcv_log_fallback_once('lock_unavailable');
        return false;
    }
    @chmod($lockPath, 0600);
    if (!pcv_log_private_file($lockPath)) {
        @fclose($lock);
        pcv_log_fallback_once('lock_unavailable');
        return false;
    }

    $request =& pcv_log_request_context();
    $waitedBeforeNs = $request['lock_wait_ns'];
    $waitStartedNs = hrtime(true);
    $deadlineNs = $waitStartedNs + max(0, PCV_LOG_LOCK_WAIT_BUDGET_NS - $waitedBeforeNs);
    $locked = false;
    do {
        if (@flock($lock, LOCK_EX | LOCK_NB)) {
            $locked = true;
            break;
        }
        $remainingNs = $deadlineNs - hrtime(true);
        if ($remainingNs <= 0) {
            break;
        }
        usleep(max(1, (int)ceil(min(1000000, $remainingNs) / 1000)));
    } while (true);
    $request['lock_wait_ns'] = min(PCV_LOG_LOCK_WAIT_BUDGET_NS,
        $waitedBeforeNs + max(0, hrtime(true) - $waitStartedNs));
    if (!$locked) {
        @fclose($lock);
        pcv_log_fallback_once('lock_unavailable');
        pcv_log_fallback_event_line($line);
        return false;
    }

    $ok = false;
    try {
        $active = $directory . DIRECTORY_SEPARATOR . 'events.jsonl';
        if (is_link($active)) {
            pcv_log_fallback_once('append_failed');
            return false;
        }
        if (file_exists($active)) {
            clearstatcache(true, $active);
            if (!pcv_log_private_file($active)) {
                pcv_log_fallback_once('append_failed');
                return false;
            }
            $size = @filesize($active);
            if ($size === false) {
                pcv_log_fallback_once('append_failed');
                return false;
            }
            if ($size + strlen($line) > PCV_LOG_MAX_FILE_BYTES) {
                if (!pcv_log_rotate($directory)) {
                    pcv_log_fallback_once('rotation_failed');
                    return false;
                }
            }
        }

        clearstatcache(true, $active);
        $originalSize = file_exists($active) ? @filesize($active) : 0;
        if (!is_int($originalSize) && $originalSize !== 0) {
            pcv_log_fallback_once('append_failed');
            return false;
        }
        $stream = @fopen($active, 'ab');
        if ($stream === false) {
            pcv_log_fallback_once('append_failed');
            return false;
        }
        @chmod($active, 0600);
        if (!pcv_log_private_file($active)) {
            @fclose($stream);
            pcv_log_fallback_once('append_failed');
            return false;
        }
        $written = 0;
        $length = strlen($line);
        while ($written < $length) {
            $count = @fwrite($stream, substr($line, $written));
            if (!is_int($count) || $count < 1) {
                @ftruncate($stream, $originalSize);
                @fflush($stream);
                @fclose($stream);
                pcv_log_fallback_once('append_failed');
                return false;
            }
            $written += $count;
        }
        $ok = @fflush($stream);
        if (!$ok) {
            @ftruncate($stream, $originalSize);
            @fflush($stream);
        }
        @fclose($stream);
    } catch (Throwable) {
        pcv_log_fallback_once('append_failed');
        $ok = false;
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }

    if (!$ok) {
        pcv_log_fallback_once('append_failed');
    } else {
        $request =& pcv_log_request_context();
        if ($request['failure_codes'] === []) {
            $request['write_status'] = 'written';
        }
    }
    return $ok;
}

function pcv_log_event(string $event, string $severity, string $outcome, ?string $reason = null, array $context = []): void
{
    try {
        $rules = pcv_log_event_rules();
        if (!isset($rules[$event]) || !pcv_log_rule_matches($event, $severity, $outcome, $reason)
            || !pcv_log_event_context_valid($event, $context)) {
            pcv_log_fallback_once('invalid_event');
            return;
        }

        $request =& pcv_log_request_context();
        if ($event === 'routing.request_started') {
            pcv_log_register_shutdown_observer();
        }
        if ($severity === 'debug' && !$request['debug_enabled']) {
            return;
        }
        $started = $request['started_ns'];
        $elapsed = is_int($started) && function_exists('hrtime')
            ? max(0, (int)((hrtime(true) - $started) / 1000000))
            : null;
        $now = microtime(true);
        $timestamp = gmdate('Y-m-d\TH:i:s', (int)$now)
            . sprintf('.%03dZ', (int)(($now - floor($now)) * 1000));
        $entry = [
            'schema_version' => PCV_LOG_SCHEMA_VERSION,
            'logging_revision' => PCV_LOG_INSTRUMENTATION_REVISION,
            'plugin_version' => pcv_log_plugin_version(),
            'timestamp' => $timestamp,
            'event' => $event,
            'severity' => $severity,
            'outcome' => $outcome,
            'reason' => $reason,
            'request_id' => $request['request_id'],
            'config_id' => $request['config_id'],
            'playthrough_ref' => $request['playthrough_ref'] ?? null,
            'elapsed_ms' => $elapsed,
            'context' => pcv_log_clean_context($event, $context),
        ];
        $correlation = pcv_log_clean_correlation($request['correlation']);
        if ($correlation !== []) {
            $entry['context']['correlation'] = $correlation;
        }
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
        $line = json_encode($entry, $flags) . "\n";
        if (strlen($line) > PCV_LOG_MAX_ENTRY_BYTES) {
            $entry['context'] = [];
            $line = json_encode($entry, $flags) . "\n";
        }
        if (strlen($line) > PCV_LOG_MAX_ENTRY_BYTES) {
            pcv_log_fallback_once('entry_too_large');
            return;
        }
        pcv_log_write_line($line);
        if ($event === 'routing.request_finished' && in_array($outcome, ['postrequest_observed', 'unobserved'], true)
            && is_string($entry['config_id'])) {
            pcv_log_record_last_turn($entry['config_id'], $outcome, $timestamp);
        }
    } catch (Throwable) {
        pcv_log_fallback_once('append_failed');
    }
}

/** Keep the last scene turn's outcome (no dialogue) so the plugin page need not scan the log. Best effort. */
function pcv_log_record_last_turn(string $configId, string $outcome, string $timestamp): void
{
    try {
        $directory = pcv_log_resolve_directory(false);
        if (!is_string($directory)) {
            return;
        }
        $path = $directory . DIRECTORY_SEPARATOR . 'last_turn.json';
        if (is_link($path)) {
            return;
        }
        $contents = json_encode(['config_id' => $configId, 'outcome' => $outcome, 'timestamp' => $timestamp], JSON_THROW_ON_ERROR);
        $temporary = tempnam($directory, '.last-turn-');
        if ($temporary === false) {
            return;
        }
        if (file_put_contents($temporary, $contents) === strlen($contents)) {
            @chmod($temporary, 0600);
            @rename($temporary, $path);
        }
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    } catch (Throwable) {
        // The status line is optional; logging continues without it.
    }
}

/** Read the last scene turn written by pcv_log_record_last_turn, if it belongs to $configId. */
function pcv_log_read_last_turn(string $configId): ?array
{
    try {
        $directory = pcv_log_resolve_directory(false);
        $path = is_string($directory) ? $directory . DIRECTORY_SEPARATOR . 'last_turn.json' : null;
        if ($path === null || !is_file($path) || is_link($path) || (int)@filesize($path) > 512) {
            return null;
        }
        $turn = json_decode((string)@file_get_contents($path), true, 4);
        if (!is_array($turn) || ($turn['config_id'] ?? null) !== $configId
            || !in_array($turn['outcome'] ?? null, ['postrequest_observed', 'unobserved'], true)
            || !is_string($turn['timestamp'] ?? null) || preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d/', $turn['timestamp']) !== 1) {
            return null;
        }
        return $turn;
    } catch (Throwable) {
        return null;
    }
}

function pcv_log_presence_observed(string $source, string $status, int $actorCount, ?string $reason = null): void
{
    $severity = match ($status) {
        'available', 'empty', 'stale' => 'info',
        'unavailable' => match ($reason) {
            'presence_baseline', 'presence_missing', 'identity_unavailable' => 'info',
            'presence_invalid', 'presence_key_mismatch' => 'warning',
            'presence_unavailable' => 'error',
            default => '',
        },
        default => '',
    };
    if ($severity === '') {
        pcv_log_fallback_once('invalid_event');
        return;
    }
    pcv_log_event('state.presence_observed', $severity, $status, $reason, [
        'source' => $source,
        'actor_count' => max(0, min(10000, $actorCount)),
    ]);
}

function pcv_log_set_terminal(string $outcome, ?string $reason, array $safeContext = []): void
{
    $request =& pcv_log_request_context();
    if ($request['terminal_emitted']) {
        return;
    }
    $severity = [
        'postrequest_observed' => 'info',
        'skipped' => 'info',
        'blocked' => 'warning',
        'failed' => 'error',
        'unobserved' => 'warning',
    ][$outcome] ?? null;
    if (!is_string($severity) || !pcv_log_rule_matches('routing.request_finished', $severity, $outcome, $reason)) {
        pcv_log_fallback_once('invalid_event');
        return;
    }
    $previous = $request['terminal'] ?? null;
    $severityRank = ['info' => 1, 'warning' => 2, 'error' => 3];
    if (is_array($previous)
        && ($severityRank[$severity] ?? 0) <= ($severityRank[$previous['severity'] ?? ''] ?? 0)) {
        return;
    }
    $request['terminal'] = [
        'severity' => $severity,
        'outcome' => $outcome,
        'reason' => $reason,
        'context' => pcv_log_clean_context('routing.request_finished', $safeContext),
    ];
}

function pcv_log_register_shutdown_observer(): void
{
    $request =& pcv_log_request_context();
    if ($request['shutdown_registered']) {
        return;
    }
    $request['shutdown_registered'] = true;
    register_shutdown_function('pcv_log_shutdown_terminal');
}

function pcv_log_shutdown_terminal(): void
{
    $request =& pcv_log_request_context();
    if ($request['terminal_emitted'] || !$request['shutdown_registered']) {
        return;
    }
    // CHIM can end a request without speech on purpose (e.g. its rechat budget, which plugins cannot
    // observe), so record the request type to tell an ended rechat chain from a failed input.
    $terminal = $request['terminal'] ?? [
        'severity' => 'warning',
        'outcome' => 'unobserved',
        'reason' => 'request_unobserved',
        'context' => array_filter(['phase' => 'shutdown', 'request_type' => $request['request_type'] ?? null],
            static fn($value) => $value !== null),
    ];
    $lastError = error_get_last();
    if (is_array($lastError) && in_array($lastError['type'] ?? null, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        $terminal = [
            'severity' => 'error',
            'outcome' => 'failed',
            'reason' => 'fatal_error',
            'context' => [
                'phase' => 'shutdown',
                'source_file' => basename((string)($lastError['file'] ?? 'unknown')),
                'source_line' => is_int($lastError['line'] ?? null) ? $lastError['line'] : null,
                'exception_code' => is_int($lastError['type'] ?? null) ? $lastError['type'] : null,
            ],
        ];
    }
    $request['terminal_emitted'] = true;
    pcv_log_event('routing.request_finished', $terminal['severity'], $terminal['outcome'], $terminal['reason'], $terminal['context']);
}

function pcv_log_import_mp_record(array $record, string $level): void
{
    if (($record['schema_version'] ?? null) !== 1 || ($record['plugin'] ?? null) !== 'mind_poisoning'
        || ($record['source_kind'] ?? null) !== 'reflection' || !in_array($level, ['debug', 'info', 'warning', 'error'], true)) {
        return;
    }
    $event = $record['event'] ?? null;
    if (!in_array($event, ['reflection_model_finished', 'persistence_finished', 'persistence_cleanup_failed', 'request_finished'], true)) {
        return;
    }
    $requestId = $record['request_id'] ?? null;
    $configId = $record['config_id'] ?? null;
    $eventId = $record['event_id'] ?? null;
    $utteranceId = $record['utterance_id'] ?? null;
    $id = is_int($eventId) && $eventId > 0 ? (string)$eventId : $eventId;
    if (!is_string($requestId) || preg_match('/\A[a-f0-9]{24}\z/D', $requestId) !== 1
        || !is_string($configId) || !pcv_log_valid_uuid($configId)
        || (!is_string($id) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) !== 1)
        || !pcv_log_valid_utterance_id($utteranceId)) {
        return;
    }
    $request =& pcv_log_request_context();
    if (is_string($request['config_id']) && $request['config_id'] !== $configId) {
        return;
    }
    $currentCorrelation = pcv_log_clean_correlation($request['correlation']);
    if ((isset($currentCorrelation['event_id']) && $currentCorrelation['event_id'] !== $id)
        || (isset($currentCorrelation['utterance_id']) && $currentCorrelation['utterance_id'] !== $utteranceId)) {
        return;
    }
    pcv_log_set_config_id($configId);
    pcv_log_set_correlation(['event_id' => $id, 'utterance_id' => $utteranceId, 'linked_request_id' => $requestId]);

    $actorId = null;
    if (($record['speaker_kind'] ?? null) === 'npc' && pcv_log_valid_actor_id($record['speaker_id'] ?? null)) {
        $actorId = $record['speaker_id'];
    }
    $context = ['phase' => 'ack', 'route' => 'solo_reflection'];
    if ($actorId !== null) {
        $context['actor_a_id'] = $actorId;
    }
    foreach (['model_outcome', 'persistence_outcome', 'commit_state', 'committed', 'cleanup_failed', 'changed_count', 'changes', 'model_ms', 'persistence_ms'] as $key) {
        if (array_key_exists($key, $record)) {
            $context[$key] = $record[$key];
        }
    }
    $sourceReason = $record['reason'] ?? $record['persistence_reason'] ?? null;
    if (is_string($sourceReason)) {
        $context['source_reason'] = pcv_log_mp_reason_code($sourceReason);
    }

    if ($event === 'reflection_model_finished') {
        $modelOutcome = $record['model_outcome'] ?? null;
        $reason = match ($modelOutcome) {
            'valid' => null,
            'invalid' => 'model_invalid',
            'failed' => 'model_failed',
            default => null,
        };
        $severity = match ($modelOutcome) {
            'valid' => 'info', 'invalid' => 'warning', 'failed' => 'error', default => '',
        };
        if ($severity !== '' && $level === $severity) {
            pcv_log_event('reflection.model_finished', $severity, $modelOutcome, $reason, $context);
        }
        return;
    }

    if ($event === 'persistence_cleanup_failed') {
        if ($level === 'error') {
            pcv_log_event('reflection.persistence_finished', 'error', 'cleanup_failed', 'cleanup_failed', $context);
        }
        return;
    }

    if ($event === 'persistence_finished') {
        $outcome = pcv_log_mp_persistence_outcome($record);
        if ($outcome === null) {
            return;
        }
        [$pcvOutcome, $severity, $reason] = $outcome;
        $expectedSourceLevel = ($record['cleanup_failed'] ?? false) === true
            || ($record['persistence_outcome'] ?? null) === 'failed' ? 'error'
            : (($record['persistence_outcome'] ?? null) === 'invalid' ? 'warning' : 'info');
        if ($level === $expectedSourceLevel) {
            pcv_log_event('reflection.persistence_finished', $severity, $pcvOutcome, $reason, $context);
        }
        return;
    }

    $outcome = pcv_log_mp_evaluation_outcome($record, $level);
    if ($outcome === null) {
        return;
    }
    [$pcvOutcome, $severity, $reason] = $outcome;
    pcv_log_event('reflection.evaluation_result', $severity, $pcvOutcome, $reason, $context);
}

function pcv_log_mp_persistence_outcome(array $record): ?array
{
    $status = $record['persistence_outcome'] ?? null;
    $commit = $record['commit_state'] ?? null;
    $committed = $record['committed'] ?? null;
    $changed = $record['changed_count'] ?? null;
    $cleanupFailed = ($record['cleanup_failed'] ?? false) === true;
    if (!in_array($commit, ['confirmed', 'unconfirmed', 'not_attempted'], true) || !is_bool($committed)
        || !is_int($changed) || $changed < 0 || $changed > 8) {
        return null;
    }
    if ($cleanupFailed) {
        return ['cleanup_failed', 'error', 'cleanup_failed'];
    }
    if ($commit === 'unconfirmed') {
        return ['unconfirmed', 'error', 'commit_unconfirmed'];
    }
    if ($commit === 'confirmed' && $committed && $status === 'committed') {
        return $changed === 0 ? ['zero_change', 'info', 'zero_change'] : ['committed', 'info', null];
    }
    if ($commit === 'not_attempted' && !$committed && $status === 'invalid') {
        return ['rejected', 'warning', 'persistence_rejected'];
    }
    if ($commit === 'not_attempted' && !$committed && $status === 'stale') {
        return ['skipped', 'info', 'persistence_stale'];
    }
    if ($commit === 'not_attempted' && !$committed && $status === 'failed') {
        return ['failed', 'error', 'persistence_failed'];
    }
    return null;
}

function pcv_log_mp_evaluation_outcome(array $record, string $level): ?array
{
    $result = $record['outcome'] ?? null;
    $commit = $record['commit_state'] ?? null;
    $committed = $record['committed'] ?? null;
    $changed = $record['changed_count'] ?? null;
    $cleanupFailed = ($record['cleanup_failed'] ?? false) === true;
    if ($cleanupFailed) {
        return $level === 'error' ? ['cleanup_failed', 'error', 'cleanup_failed'] : null;
    }
    if ($commit === 'unconfirmed') {
        return $level === 'error' ? ['unconfirmed', 'error', 'commit_unconfirmed'] : null;
    }
    if ($result === 'committed' && $commit === 'confirmed' && $committed === true && is_int($changed) && $changed >= 0 && $changed <= 8) {
        return $level === 'info'
            ? ($changed === 0 ? ['zero_change', 'info', 'zero_change'] : ['committed', 'info', null])
            : null;
    }
    if ($result === 'rejected' && $level === 'warning') {
        return ['rejected', 'warning', 'evaluation_rejected'];
    }
    if ($result === 'skipped' && $level === 'warning') {
        return ['rejected', 'warning', 'evaluation_rejected'];
    }
    if ($result === 'skipped' && $level === 'info') {
        return ['skipped', 'info', 'evaluation_skipped'];
    }
    if ($result === 'failed' && $level === 'error') {
        return ['failed', 'error', 'evaluation_failed'];
    }
    return null;
}

function pcv_log_exception(string $event, string $severity, string $outcome, string $reason, Throwable $error, array $context = []): void
{
    try {
        $context['exception_class'] = get_class($error);
        $context['exception_code'] = (int)$error->getCode();
        $context['source_file'] = basename($error->getFile());
        $context['source_line'] = $error->getLine();
        pcv_log_event($event, $severity, $outcome, $reason, $context);
    } catch (Throwable) {
        pcv_log_fallback_once('append_failed');
    }
}
