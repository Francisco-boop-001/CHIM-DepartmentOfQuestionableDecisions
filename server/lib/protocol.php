<?php
declare(strict_types=1);

const QD_MAX_BODY_BYTES = 65536;
const QD_MAX_JSON_DEPTH = 8;
const QD_MAX_OBJECT_PROPERTIES = 64;
const QD_MAX_ARRAY_ITEMS = 128;
const QD_MAX_STRING_BYTES = 8192;
const QD_MAX_PAGE_ROWS = 100;
const QD_MAX_TEXT_CHARACTERS = 2000;
const QD_MAX_TEXT_BYTES = 8000;
const QD_MAX_JOURNAL_RESULT_BYTES = 16384;
const QD_BRAINWASH_FIELDS = ['core', 'npc_static_bio', 'personality', 'appearance', 'occupation', 'skills', 'speechstyle', 'goals'];
const QD_PENDING_QUEUED_REASONS = ['executor_unavailable', 'renderer_unavailable', 'identity_changed', 'mapping_unavailable', 'companion_owned', 'operator_closed', 'interaction_unavailable', 'integrity_unavailable', 'not_dispatched', 'operation_unavailable'];

const QD_OPERATION_REQUIREMENTS = [
    'bootstrap' => ['scope' => null, 'capability' => null],
    'pcv.roster.read' => ['scope' => 'pcv', 'capability' => 'pcv'],
    'pcv.status.read' => ['scope' => 'pcv', 'capability' => 'pcv'],
    'pcv.arm' => ['scope' => 'pcv', 'capability' => 'pcv'],
    'pcv.end' => ['scope' => 'pcv', 'capability' => 'pcv'],
    'pcv.companion.stage' => ['scope' => 'pcv', 'capability' => 'pcv'],
    'pcv.companion.status' => ['scope' => 'pcv', 'capability' => 'pcv'],
    'pcv.companion.cancel' => ['scope' => 'pcv', 'capability' => 'pcv'],
    'mp.relationships.read' => ['scope' => 'inspector', 'capability' => 'mp'],
    'sas.issues.read' => ['scope' => 'inspector', 'capability' => 'sas'],
    'correspondence.catalog.read' => ['scope' => ['inspector', 'compose'], 'capability' => 'correspondence'],
    'correspondence.mailbox.read' => ['scope' => 'inspector', 'capability' => 'correspondence'],
    'correspondence.detail.read' => ['scope' => 'inspector', 'capability' => 'correspondence'],
    'correspondence.compose' => ['scope' => 'compose', 'capability' => 'correspondence'],
    'correspondence.reconcile' => ['scope' => 'compose', 'capability' => 'correspondence'],
    'correspondence.resume' => ['scope' => 'compose', 'capability' => 'correspondence'],
    'correspondence.pending.read' => ['scope' => 'compose', 'capability' => 'correspondence'],
    'brainwash.catalog.read' => ['scope' => 'brainwash', 'capability' => 'brainwash'],
    'brainwash.card.read' => ['scope' => 'brainwash', 'capability' => 'brainwash'],
    'brainwash.draft' => ['scope' => 'brainwash', 'capability' => 'brainwash'],
    'brainwash.apply' => ['scope' => 'brainwash', 'capability' => 'brainwash'],
    'brainwash.undo' => ['scope' => 'brainwash', 'capability' => 'brainwash'],
];

const QD_ERROR_MESSAGES = [
    'invalid_request' => 'Request is invalid.',
    'unsupported_operation' => 'Operation is not supported.',
    'unauthorized' => 'Authorization is required.',
    'forbidden' => 'Authorization does not permit this operation.',
    'stale_context' => 'Server context is no longer current.',
    'stale_revision' => 'The requested revision is no longer current.',
    'conflict' => 'The operation conflicts with current state.',
    'not_found' => 'The requested item was not found.',
    'unavailable' => 'The operation is temporarily unavailable.',
    'unsupported_media_type' => 'Content type must be application/json.',
    'method_not_allowed' => 'Only POST is supported.',
    'payload_too_large' => 'Request body exceeds the size limit.',
];

final class QDServerFault extends RuntimeException
{
    public function __construct(public readonly string $wireCode)
    {
        parent::__construct($wireCode);
    }
}

function qd_is_uuid(mixed $value): bool
{
    return is_string($value) && preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/D', $value) === 1;
}

function qd_is_utf8(string $value): bool
{
    return preg_match('//u', $value) === 1;
}

function qd_text_characters(string $value): int
{
    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? PHP_INT_MAX : $count;
}

function qd_validate_json_tree(mixed $value, int $depth = 1): ?string
{
    if ($depth > QD_MAX_JSON_DEPTH) {
        return 'invalid_request';
    }
    if (is_string($value)) {
        return strlen($value) <= QD_MAX_STRING_BYTES && qd_is_utf8($value) ? null : 'invalid_request';
    }
    if (is_array($value)) {
        if (!array_is_list($value) || count($value) > QD_MAX_ARRAY_ITEMS) {
            return 'invalid_request';
        }
        foreach ($value as $item) {
            if (($error = qd_validate_json_tree($item, $depth + 1)) !== null) {
                return $error;
            }
        }
        return null;
    }
    if (is_object($value)) {
        $properties = get_object_vars($value);
        if (count($properties) > QD_MAX_OBJECT_PROPERTIES) {
            return 'invalid_request';
        }
        foreach ($properties as $key => $item) {
            if (strlen((string)$key) > QD_MAX_STRING_BYTES || qd_validate_json_tree($item, $depth + 1) !== null) {
                return 'invalid_request';
            }
        }
        return null;
    }
    if (is_float($value) && !is_finite($value)) {
        return 'invalid_request';
    }
    return is_null($value) || is_bool($value) || is_int($value) || is_float($value) ? null : 'invalid_request';
}

function qd_exact_properties(object $object, array $required, array $optional = []): bool
{
    $allowed = array_fill_keys(array_merge($required, $optional), true);
    foreach (array_keys(get_object_vars($object)) as $key) {
        if (!isset($allowed[$key])) {
            return false;
        }
    }
    foreach ($required as $key) {
        if (!property_exists($object, $key)) {
            return false;
        }
    }
    return true;
}

function qd_valid_string(mixed $value, int $maxBytes = QD_MAX_STRING_BYTES, bool $allowEmpty = false): bool
{
    return is_string($value)
        && ($allowEmpty || $value !== '')
        && strlen($value) <= $maxBytes
        && qd_is_utf8($value);
}

function qd_valid_nullable_uuid(mixed $value): bool
{
    return $value === null || qd_is_uuid($value);
}

function qd_valid_brainwash_actor_key(mixed $value): bool
{
    if (!is_string($value) || preg_match('/\\Anpc:([1-9][0-9]{0,18})\\z/D', $value, $matches) !== 1) {
        return false;
    }
    $max = (string)PHP_INT_MAX;
    return strlen($matches[1]) < strlen($max)
        || (strlen($matches[1]) === strlen($max) && strcmp($matches[1], $max) <= 0);
}

function qd_brainwash_valid_revision(mixed $value): bool
{
    return is_string($value) && preg_match('/\\A[a-f0-9]{64}\\z/D', $value) === 1;
}

function qd_brainwash_valid_changes(mixed $changes, bool $allowEmpty = false): bool
{
    if (!is_object($changes)) {
        return false;
    }
    $properties = get_object_vars($changes);
    if (count($properties) > count(QD_BRAINWASH_FIELDS) || (!$allowEmpty && $properties === [])) {
        return false;
    }
    $bytes = 0;
    foreach ($properties as $field => $value) {
        if (!in_array($field, QD_BRAINWASH_FIELDS, true) || !qd_valid_string($value, 8192, true)) {
            return false;
        }
        $bytes += strlen($value);
        if ($bytes > 20000) return false;
    }
    return true;
}

function qd_validate_page_options(object $payload): bool
{
    if (property_exists($payload, 'limit') && (!is_int($payload->limit) || $payload->limit < 1 || $payload->limit > QD_MAX_PAGE_ROWS)) {
        return false;
    }
    return !property_exists($payload, 'cursor') || qd_valid_string($payload->cursor, 512);
}

function qd_validate_pcv_configuration(mixed $configuration): bool
{
    if (!is_object($configuration)) {
        return false;
    }
    $allowed = ['enabled', 'scene_mode', 'actor_a', 'actor_b', 'actor_ids', 'exclude_player', 'bystander_mode', 'free', 'free_cap', 'opener', 'card', 'pace', 'actions'];
    if (!qd_exact_properties($configuration, ['enabled', 'scene_mode', 'exclude_player', 'bystander_mode'], array_diff($allowed, ['enabled', 'scene_mode', 'exclude_player', 'bystander_mode']))) {
        return false;
    }
    if ($configuration->enabled !== true
        || !in_array($configuration->scene_mode, ['pair', 'solo'], true)
        || !is_bool($configuration->exclude_player)
        || !in_array($configuration->bystander_mode, ['exclude', 'silent'], true)) {
        return false;
    }
    if (property_exists($configuration, 'actor_a') && !qd_valid_string($configuration->actor_a, 128)) {
        return false;
    }
    if (property_exists($configuration, 'actor_b') && $configuration->actor_b !== null && !qd_valid_string($configuration->actor_b, 128)) {
        return false;
    }
    if (property_exists($configuration, 'actor_ids')) {
        if (!is_array($configuration->actor_ids) || !array_is_list($configuration->actor_ids) || count($configuration->actor_ids) < 2 || count($configuration->actor_ids) > 4) {
            return false;
        }
        $seen = [];
        foreach ($configuration->actor_ids as $actorId) {
            if (!qd_valid_string($actorId, 128) || isset($seen[$actorId])) {
                return false;
            }
            $seen[$actorId] = true;
        }
    }
    if (property_exists($configuration, 'free') && $configuration->free !== true) {
        return false;
    }
    if (property_exists($configuration, 'free_cap') && (!is_int($configuration->free_cap) || $configuration->free_cap < 2 || $configuration->free_cap > 6)) {
        return false;
    }
    if (property_exists($configuration, 'opener')
        && $configuration->opener !== 'auto'
        && (!qd_valid_string($configuration->opener, 128) || qd_text_characters($configuration->opener) > 128)) {
        return false;
    }
    if (property_exists($configuration, 'card')
        && (!qd_valid_string($configuration->card, 1200, true)
            || qd_text_characters($configuration->card) > 300
            || str_contains($configuration->card, "\r")
            || str_contains($configuration->card, "\n"))) {
        return false;
    }
    if (property_exists($configuration, 'pace') && !in_array($configuration->pace, ['short', 'normal', 'long'], true)) {
        return false;
    }
    if (property_exists($configuration, 'actions')) {
        if (!is_array($configuration->actions) || !array_is_list($configuration->actions) || count($configuration->actions) > 3) {
            return false;
        }
        $seen = [];
        foreach ($configuration->actions as $action) {
            if (!in_array($action, ['personal', 'physical', 'intimate'], true) || isset($seen[$action])) {
                return false;
            }
            $seen[$action] = true;
        }
    }
    if (($configuration->free ?? false) === true) {
        if ($configuration->scene_mode !== 'pair'
            || !property_exists($configuration, 'free_cap')
            || property_exists($configuration, 'actor_ids')
            || property_exists($configuration, 'actor_a')
            || property_exists($configuration, 'actor_b')
            || $configuration->exclude_player !== true) {
            return false;
        }
    } elseif (property_exists($configuration, 'free_cap')) {
        return false;
    }
    if (property_exists($configuration, 'actor_ids')
        && ($configuration->scene_mode !== 'pair' || property_exists($configuration, 'actor_a') || property_exists($configuration, 'actor_b'))) {
        return false;
    }
    if ($configuration->scene_mode === 'solo') {
        if (!qd_valid_string($configuration->actor_a ?? null, 128)
            || (property_exists($configuration, 'actor_b') && $configuration->actor_b !== null)
            || property_exists($configuration, 'actor_ids')) {
            return false;
        }
    } elseif (($configuration->free ?? false) !== true && !property_exists($configuration, 'actor_ids')) {
        if (!qd_valid_string($configuration->actor_a ?? null, 128)
            || !qd_valid_string($configuration->actor_b ?? null, 128)
            || $configuration->actor_a === $configuration->actor_b) {
            return false;
        }
    }
    return true;
}

function qd_validate_payload(string $operation, object $payload): string
{
    switch ($operation) {
        case 'bootstrap':
        case 'pcv.status.read':
            return qd_exact_properties($payload, []) ? 'ok' : 'invalid_request';

        case 'correspondence.catalog.read':
            return qd_exact_properties($payload, [], ['limit', 'cursor']) && qd_validate_page_options($payload)
                ? 'ok' : 'invalid_request';

        case 'pcv.roster.read':
            if (!qd_exact_properties($payload, ['managedNames', 'nativeRosterRevision'])
                || !is_array($payload->managedNames)
                || count($payload->managedNames) > 64
                || !qd_valid_string($payload->nativeRosterRevision, 128)) {
                return 'invalid_request';
            }
            $names = [];
            foreach ($payload->managedNames as $name) {
                if (!qd_valid_string($name, 128)) {
                    return 'invalid_request';
                }
                $folded = strtolower($name);
                if (isset($names[$folded])) {
                    return 'invalid_request';
                }
                $names[$folded] = true;
            }
            return 'ok';

        case 'pcv.arm':
            return qd_exact_properties($payload, ['operationId', 'expectedActiveConfigId', 'expectedPendingConfigId', 'configuration'])
                && qd_is_uuid($payload->operationId)
                && qd_valid_nullable_uuid($payload->expectedActiveConfigId)
                && qd_valid_nullable_uuid($payload->expectedPendingConfigId)
                && qd_validate_pcv_configuration($payload->configuration) ? 'ok' : 'invalid_request';

        case 'pcv.end':
            return qd_exact_properties($payload, ['operationId', 'expectedActiveConfigId', 'expectedPendingConfigId'])
                && qd_is_uuid($payload->operationId)
                && qd_valid_nullable_uuid($payload->expectedActiveConfigId)
                && qd_valid_nullable_uuid($payload->expectedPendingConfigId) ? 'ok' : 'invalid_request';

        case 'pcv.companion.stage':
            return qd_exact_properties($payload, ['operationId', 'text', 'target', 'expectedActiveConfigId', 'expectedPendingConfigId'])
                && qd_is_uuid($payload->operationId)
                && qd_valid_string($payload->text, QD_MAX_TEXT_BYTES)
                && is_object($payload->target)
                && qd_exact_properties($payload->target, ['formId', 'name'])
                && qd_valid_string($payload->target->formId, 8)
                && preg_match('/\\A[0-9a-fA-F]{8}\\z/D', $payload->target->formId) === 1
                && qd_valid_string($payload->target->name, 256)
                && qd_valid_nullable_uuid($payload->expectedActiveConfigId)
                && qd_valid_nullable_uuid($payload->expectedPendingConfigId) ? 'ok' : 'invalid_request';

        case 'pcv.companion.status':
        case 'pcv.companion.cancel':
            return qd_exact_properties($payload, ['operationId']) && qd_is_uuid($payload->operationId)
                ? 'ok' : 'invalid_request';

        case 'mp.relationships.read':
            return qd_exact_properties($payload, ['perspectiveActorKey'], ['targetActorKey', 'limit', 'cursor'])
                && qd_valid_string($payload->perspectiveActorKey, 128)
                && (!property_exists($payload, 'targetActorKey') || qd_valid_string($payload->targetActorKey, 128))
                && qd_validate_page_options($payload) ? 'ok' : 'invalid_request';

        case 'sas.issues.read':
            return qd_exact_properties($payload, ['perspectiveActorKey'], ['limit', 'cursor'])
                && qd_valid_string($payload->perspectiveActorKey, 128)
                && qd_validate_page_options($payload) ? 'ok' : 'invalid_request';

        case 'correspondence.mailbox.read':
            return qd_exact_properties($payload, ['recipientActorKey'], ['limit', 'cursor'])
                && qd_valid_string($payload->recipientActorKey, 128)
                && qd_validate_page_options($payload) ? 'ok' : 'invalid_request';

        case 'correspondence.detail.read':
            return qd_exact_properties($payload, ['recipientActorKey', 'letterId'])
                && qd_valid_string($payload->recipientActorKey, 128)
                && qd_valid_string($payload->letterId, 128) ? 'ok' : 'invalid_request';

        case 'correspondence.compose':
            if (!qd_exact_properties($payload, ['operationId', 'writerActorKey', 'recipientActorKey', 'body', 'inventoryRevision', 'attachments', 'goldAmount'], ['signatureActorKey'])
                || !qd_is_uuid($payload->operationId)
                || !qd_valid_string($payload->writerActorKey, 128)
                || !qd_valid_string($payload->recipientActorKey, 128)
                || !qd_valid_string($payload->inventoryRevision, 128)
                || !is_int($payload->goldAmount)
                || $payload->goldAmount < 0
                || $payload->goldAmount > 9007199254740991
                || (property_exists($payload, 'signatureActorKey') && !qd_valid_string($payload->signatureActorKey, 128))
                || !is_string($payload->body)
                || !qd_is_utf8($payload->body)
                || strlen($payload->body) > QD_MAX_TEXT_BYTES
                || qd_text_characters($payload->body) > QD_MAX_TEXT_CHARACTERS
                || !is_array($payload->attachments)
                || !array_is_list($payload->attachments)
                || count($payload->attachments) + ($payload->goldAmount > 0 ? 1 : 0) > 8) {
                return 'invalid_request';
            }
            $forms = [];
            foreach ($payload->attachments as $attachment) {
                if (!is_object($attachment)
                    || !qd_exact_properties($attachment, ['form_id', 'quantity'])
                    || !qd_valid_string($attachment->form_id, 128)
                    || preg_match('/\\A[^|\\\\\/\\r\\n]{1,64}\\.(?:esm|esp|esl)\\|[0-9a-fA-F]{8}\\z/iD', $attachment->form_id) !== 1
                    || strtolower($attachment->form_id) === 'skyrim.esm|0000000f'
                    || !is_int($attachment->quantity)
                    || $attachment->quantity < 1
                    || $attachment->quantity > 9007199254740991) {
                    return 'invalid_request';
                }
                $key = strtolower($attachment->form_id);
                if (isset($forms[$key])) {
                    return 'invalid_request';
                }
                $forms[$key] = true;
            }
            return 'ok';

        case 'correspondence.reconcile':
            return qd_exact_properties($payload, ['operationId']) && qd_is_uuid($payload->operationId) ? 'ok' : 'invalid_request';

        case 'correspondence.resume':
            return qd_exact_properties($payload, ['operationId', 'attemptId'])
                && qd_is_uuid($payload->operationId)
                && qd_is_uuid($payload->attemptId) ? 'ok' : 'invalid_request';

        case 'correspondence.pending.read':
            return qd_exact_properties($payload, [], ['cursor'])
                && (!property_exists($payload, 'cursor') || qd_valid_string($payload->cursor, 512))
                ? 'ok' : 'invalid_request';

        case 'brainwash.catalog.read':
            return qd_exact_properties($payload, [], ['query'])
                && (!property_exists($payload, 'query') || qd_valid_string($payload->query, 128, true))
                ? 'ok' : 'invalid_request';

        case 'brainwash.card.read':
            return qd_exact_properties($payload, ['actorKey']) && qd_valid_brainwash_actor_key($payload->actorKey)
                ? 'ok' : 'invalid_request';

        case 'brainwash.draft':
            return qd_exact_properties($payload, ['actorKey', 'expectedRevision', 'operationId', 'mode', 'changes', 'instruction'], ['beliefs'])
                && qd_valid_brainwash_actor_key($payload->actorKey)
                && qd_brainwash_valid_revision($payload->expectedRevision)
                && qd_is_uuid($payload->operationId)
                && in_array($payload->mode, ['manual', 'ai'], true)
                && qd_brainwash_valid_changes($payload->changes, true)
                && qd_valid_string($payload->instruction, 2000, true)
                && (!property_exists($payload, 'beliefs') || qd_valid_string($payload->beliefs, 4000, true))
                && (get_object_vars($payload->changes) !== [] || property_exists($payload, 'beliefs')) ? 'ok' : 'invalid_request';

        case 'brainwash.apply':
            if (!qd_exact_properties($payload, ['actorKey', 'draftId', 'operationId', 'acceptedFields', 'acceptBeliefs'])
                || !qd_valid_brainwash_actor_key($payload->actorKey)
                || !qd_is_uuid($payload->draftId)
                || !qd_is_uuid($payload->operationId)
                || !is_array($payload->acceptedFields)
                || !array_is_list($payload->acceptedFields)
                || count($payload->acceptedFields) > count(QD_BRAINWASH_FIELDS)
                || !is_bool($payload->acceptBeliefs)) {
                return 'invalid_request';
            }
            $seen = [];
            foreach ($payload->acceptedFields as $field) {
                if (!is_string($field) || !in_array($field, QD_BRAINWASH_FIELDS, true) || isset($seen[$field])) {
                    return 'invalid_request';
                }
                $seen[$field] = true;
            }
            return ($seen !== [] || $payload->acceptBeliefs) ? 'ok' : 'invalid_request';

        case 'brainwash.undo':
            return qd_exact_properties($payload, ['actorKey', 'operationId', 'appliedOperationId'])
                && qd_valid_brainwash_actor_key($payload->actorKey)
                && qd_is_uuid($payload->operationId)
                && qd_is_uuid($payload->appliedOperationId) ? 'ok' : 'invalid_request';

        default:
            return 'unsupported_operation';
    }
}

function qd_validate_request(mixed $request): ?string
{
    if (!is_object($request) || ($treeError = qd_validate_json_tree($request)) !== null) {
        return 'invalid_request';
    }
    if (!qd_exact_properties($request, ['version', 'requestId', 'operation', 'context', 'payload'])
        || $request->version !== 1
        || !qd_is_uuid($request->requestId)
        || !is_string($request->operation)
        || strlen($request->operation) > 64
        || !is_object($request->context)
        || !qd_exact_properties($request->context, ['viewToken', 'nativeGeneration', 'serverContext'])
        || !qd_is_uuid($request->context->viewToken)
        || !is_string($request->context->nativeGeneration)
        || preg_match('/\A[0-9]{1,20}\z/D', $request->context->nativeGeneration) !== 1
        || ($request->context->serverContext !== null && !qd_valid_string($request->context->serverContext, 128))
        || !is_object($request->payload)) {
        return 'invalid_request';
    }
    if (!array_key_exists($request->operation, QD_OPERATION_REQUIREMENTS)) {
        return 'unsupported_operation';
    }
    if ($request->operation === 'bootstrap') {
        if ($request->context->serverContext !== null) {
            return 'invalid_request';
        }
    } elseif ($request->context->serverContext === null) {
        return 'invalid_request';
    }
    $payloadResult = qd_validate_payload($request->operation, $request->payload);
    return $payloadResult === 'ok' ? null : $payloadResult;
}

function qd_parse_json_request(string $raw): array
{
    if (strlen($raw) > QD_MAX_BODY_BYTES) {
        return ['request' => null, 'error' => 'payload_too_large'];
    }
    try {
        $request = json_decode($raw, false, QD_MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['request' => null, 'error' => 'invalid_request'];
    }
    $error = qd_validate_request($request);
    return ['request' => is_object($request) ? $request : null, 'error' => $error];
}

function qd_error_message(string $code): string
{
    return QD_ERROR_MESSAGES[$code] ?? QD_ERROR_MESSAGES['unavailable'];
}

function qd_error_response(?object $request, string $code): array
{
    if (!array_key_exists($code, QD_ERROR_MESSAGES)) {
        $code = 'unavailable';
    }
    return [
        'version' => $request->version ?? 1,
        'requestId' => $request->requestId ?? null,
        'operation' => $request->operation ?? null,
        'context' => $request->context ?? null,
        'ok' => false,
        'error' => ['code' => $code, 'message' => qd_error_message($code)],
    ];
}

function qd_success_response(object $request, mixed $data): array
{
    return [
        'version' => $request->version,
        'requestId' => $request->requestId,
        'operation' => $request->operation,
        'context' => $request->context,
        'ok' => true,
        'data' => is_object($data) ? $data : (object)$data,
    ];
}

function qd_http_status_for_error(string $code): int
{
    return match ($code) {
        'method_not_allowed' => 405,
        'unsupported_media_type' => 415,
        'payload_too_large' => 413,
        'unauthorized' => 401,
        'forbidden' => 403,
        'not_found' => 404,
        'conflict', 'stale_context', 'stale_revision' => 409,
        'unavailable' => 503,
        default => 400,
    };
}

function qd_canonical_json(mixed $value): string
{
    $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
        if (is_object($item)) {
            $properties = get_object_vars($item);
            ksort($properties, SORT_STRING);
            $object = new stdClass();
            foreach ($properties as $key => $child) {
                $object->$key = $canonicalize($child);
            }
            return $object;
        }
        if (is_array($item)) {
            return array_map($canonicalize, $item);
        }
        return $item;
    };
    return json_encode($canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
