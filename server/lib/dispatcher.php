<?php
declare(strict_types=1);

require_once __DIR__ . '/protocol.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/journal.php';
require_once __DIR__ . '/overlay_setup.php';

const QD_MUTATION_OPERATIONS = ['pcv.arm', 'pcv.end', 'correspondence.compose', 'correspondence.resume', 'brainwash.draft', 'brainwash.apply', 'brainwash.undo'];
const QD_OWNER_IDEMPOTENT_MUTATIONS = ['brainwash.draft', 'brainwash.apply', 'brainwash.undo'];

function qd_false_capabilities(): array
{
    return ['pcv' => false, 'mp' => false, 'sas' => false, 'correspondence' => false, 'brainwash' => false];
}

function qd_false_access(): array
{
    return ['pcv' => false, 'inspector' => false, 'compose' => false, 'authorDetail' => false, 'brainwash' => false];
}

function qd_has_required_scope(array $scopes, mixed $required): bool
{
    if (is_string($required)) {
        return in_array($required, $scopes, true);
    }
    if (!is_array($required) || $required === []) {
        return false;
    }
    foreach ($required as $scope) {
        if (is_string($scope) && in_array($scope, $scopes, true)) {
            return true;
        }
    }
    return false;
}

function qd_access_for_scopes(array $scopes): array
{
    return [
        'pcv' => in_array('pcv', $scopes, true),
        'inspector' => in_array('inspector', $scopes, true),
        'compose' => in_array('compose', $scopes, true),
        'authorDetail' => in_array('author_detail', $scopes, true) && in_array('inspector', $scopes, true),
        'brainwash' => in_array('brainwash', $scopes, true),
    ];
}

function qd_runtime_readiness(array $dependencies, bool $includePcv = true): array
{
    $callback = $dependencies['readiness'] ?? null;
    if (!is_callable($callback)) {
        return qd_false_capabilities();
    }
    try {
        $raw = $callback($includePcv);
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
    if (!is_array($raw) || array_diff(array_keys($raw), ['pcv', 'mp', 'sas', 'correspondence', 'brainwash']) !== []) {
        throw new QDServerFault('unavailable');
    }
    $result = qd_false_capabilities();
    foreach ($result as $key => $_) {
        if (array_key_exists($key, $raw)) {
            if (!is_bool($raw[$key])) {
                throw new QDServerFault('unavailable');
            }
            $result[$key] = $raw[$key];
        }
    }
    if (!$includePcv) {
        $result['pcv'] = false;
    }
    return $result;
}

function qd_authoritative_scope(array $dependencies, string $pairId): string
{
    $callback = $dependencies['currentScope'] ?? null;
    if (!is_callable($callback)) {
        throw new QDServerFault('unavailable');
    }
    try {
        $scope = $callback($pairId);
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
    if (!qd_valid_string($scope, 1024)) {
        throw new QDServerFault('unavailable');
    }
    return $scope;
}

function qd_context_record_matches(array $state, string $pairId, object $request, string $scopeHash): bool
{
    $record = $state['contexts'][$pairId] ?? null;
    if (!is_array($record)) {
        return false;
    }
    return isset($record['contextHash'], $record['viewToken'], $record['nativeGeneration'], $record['scopeHash'], $record['scopeToken'])
        && is_string($record['contextHash'])
        && is_string($request->context->serverContext ?? null)
        && hash_equals($record['contextHash'], hash('sha256', $request->context->serverContext))
        && $record['viewToken'] === $request->context->viewToken
        && $record['nativeGeneration'] === $request->context->nativeGeneration
        && is_string($record['scopeHash'])
        && qd_valid_string($record['scopeToken'], 128)
        && hash_equals($record['scopeHash'], $scopeHash);
}

function qd_context_record_current(array $settings, string $pairId, object $request, string $scopeHash): ?array
{
    return qd_server_state_transaction($settings, static function (array &$state) use ($pairId, $request, $scopeHash): ?array {
        if (!qd_context_record_matches($state, $pairId, $request, $scopeHash)) return null;
        $record = $state['contexts'][$pairId] ?? null;
        return is_array($record) ? $record : null;
    });
}

function qd_context_record_is_current(array $settings, string $pairId, object $request, string $scopeHash): bool
{
    return qd_context_record_current($settings, $pairId, $request, $scopeHash) !== null;
}

function qd_assert_current_context(array $settings, array $dependencies, string $authorizationHeader, array $auth, mixed $requiredScope, object $request, string $expectedScopeHash): array
{
    $currentAuth = qd_authenticate($settings['pairingPath'], $settings['webRoot'], $authorizationHeader, $settings['privateRoot'] ?? null, $settings['coreRoot'] ?? null);
    if ($currentAuth === null || $currentAuth['pairId'] !== $auth['pairId']) {
        throw new QDServerFault('unauthorized');
    }
    if (!qd_has_required_scope($currentAuth['scopes'], $requiredScope)) {
        throw new QDServerFault('forbidden');
    }
    $scopeKey = qd_authoritative_scope($dependencies, $currentAuth['pairId']);
    $scopeHash = hash('sha256', $scopeKey);
    if (!hash_equals($expectedScopeHash, $scopeHash)) {
        throw new QDServerFault('stale_context');
    }
    if (!qd_context_record_is_current($settings, $currentAuth['pairId'], $request, $scopeHash)) {
        throw new QDServerFault('stale_context');
    }
    return $currentAuth;
}

/** Hold the trusted core profile-switch lease for the complete authenticated request. */
function qd_with_profile_lease(array $dependencies, callable $callback): mixed
{
    $withLease = $dependencies['withProfileLease'] ?? null;
    if (!is_callable($withLease)) {
        return $callback();
    }
    try {
        return $withLease($callback);
    } catch (QDServerFault $error) {
        throw $error;
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
}

/** Run an owner commit while the current paired view record remains locked. */
function qd_with_current_context(array $settings, array $dependencies, string $authorizationHeader, array $auth, mixed $requiredScope, object $request, string $expectedScopeHash, callable $callback): mixed
{
    $currentAuth = qd_assert_current_context($settings, $dependencies, $authorizationHeader, $auth, $requiredScope, $request, $expectedScopeHash);
    return qd_server_state_transaction($settings, static function (array &$state) use (
        $settings, $dependencies, $authorizationHeader, $currentAuth, $requiredScope, $request, $expectedScopeHash, $callback
    ): mixed {
        $leasedAuth = qd_authenticate($settings['pairingPath'], $settings['webRoot'], $authorizationHeader, $settings['privateRoot'] ?? null, $settings['coreRoot'] ?? null);
        if ($leasedAuth === null || $leasedAuth['pairId'] !== $currentAuth['pairId']) {
            throw new QDServerFault('unauthorized');
        }
        if (!qd_has_required_scope($leasedAuth['scopes'], $requiredScope)) {
            throw new QDServerFault('forbidden');
        }
        $scopeHash = hash('sha256', qd_authoritative_scope($dependencies, $leasedAuth['pairId']));
        if (!hash_equals($expectedScopeHash, $scopeHash)
            || !qd_context_record_matches($state, $leasedAuth['pairId'], $request, $scopeHash)) {
            throw new QDServerFault('stale_context');
        }
        return $callback();
    });
}

/** Hold pairing revocation and the paired-view record through a trusted owner callback. */
function qd_with_locked_pair_context(
    array $settings,
    array $dependencies,
    string $pairId,
    mixed $requiredScope,
    string $expectedScopeHash,
    array $expectedContext,
    ?string $expectedTokenHash,
    callable $callback,
): mixed {
    if (!qd_is_uuid($pairId)
        || preg_match('/\\A[a-f0-9]{64}\\z/D', $expectedScopeHash) !== 1
        || ($expectedTokenHash !== null && preg_match('/\\A[a-f0-9]{64}\\z/D', $expectedTokenHash) !== 1)
        || !is_string($expectedContext['serverContext'] ?? null)
        || !qd_valid_string($expectedContext['serverContext'], 128)
        || !qd_is_uuid($expectedContext['viewToken'] ?? null)
        || !is_string($expectedContext['nativeGeneration'] ?? null)
        || preg_match('/\\A[0-9]{1,20}\\z/D', $expectedContext['nativeGeneration']) !== 1) {
        throw new QDServerFault('stale_context');
    }

    $pairingPath = $settings['pairingPath'] ?? null;
    $webRoot = $settings['webRoot'] ?? null;
    if (!is_string($pairingPath) || !is_string($webRoot)) {
        throw new QDServerFault('unavailable');
    }
    return qd_locked_json_transaction(
        $pairingPath,
        $webRoot,
        ['version' => 1, 'pairs' => []],
        static function (array &$pairing) use (
            $settings, $dependencies, $pairId, $requiredScope, $expectedScopeHash,
            $expectedContext, $expectedTokenHash, $callback
        ): mixed {
            if (!qd_valid_pairing_config($pairing)) {
                throw new QDServerFault('unavailable');
            }
            $matchedPair = null;
            foreach ($pairing['pairs'] as $pair) {
                if (($pair['pairId'] ?? null) === $pairId) {
                    $matchedPair = $pair;
                    break;
                }
            }
            if (!is_array($matchedPair) || ($matchedPair['revoked'] ?? true) !== false
                || ($expectedTokenHash !== null
                    && (!is_string($matchedPair['tokenHash'] ?? null)
                        || !hash_equals($matchedPair['tokenHash'], $expectedTokenHash)))) {
                throw new QDServerFault('unauthorized');
            }
            if (!qd_has_required_scope($matchedPair['scopes'] ?? [], $requiredScope)) {
                throw new QDServerFault('forbidden');
            }
            $scopeHash = hash('sha256', qd_authoritative_scope($dependencies, $pairId));
            if (!hash_equals($expectedScopeHash, $scopeHash)) {
                throw new QDServerFault('stale_context');
            }
            return qd_server_state_transaction($settings, static function (array &$state) use (
                $pairId, $expectedScopeHash, $expectedContext, $callback
            ): mixed {
                $record = $state['contexts'][$pairId] ?? null;
                if (!is_array($record)
                    || !is_string($record['contextHash'] ?? null)
                    || !hash_equals($record['contextHash'], hash('sha256', $expectedContext['serverContext']))
                    || ($record['viewToken'] ?? null) !== $expectedContext['viewToken']
                    || ($record['nativeGeneration'] ?? null) !== $expectedContext['nativeGeneration']
                    || !is_string($record['scopeHash'] ?? null)
                    || !hash_equals($record['scopeHash'], $expectedScopeHash)
                    || !qd_valid_string($record['scopeToken'] ?? null, 128)) {
                    throw new QDServerFault('stale_context');
                }
                return $callback();
            });
        },
        QD_MAX_PAIRING_FILE_BYTES,
        is_string($settings['privateRoot'] ?? null) ? $settings['privateRoot'] : null,
        is_string($settings['coreRoot'] ?? null) ? $settings['coreRoot'] : null,
    );
}

/** Authenticated API variant: the pairing lock closes the revoke-after-check gap. */
function qd_with_locked_current_context(array $settings, array $dependencies, string $authorizationHeader, array $auth, mixed $requiredScope, object $request, string $expectedScopeHash, callable $callback): mixed
{
    if (preg_match('/\\ABearer (qd1_[A-Za-z0-9_-]{43})\\z/D', $authorizationHeader, $matches) !== 1
        || !is_object($request->context ?? null)) {
        throw new QDServerFault('unauthorized');
    }
    $context = [
        'serverContext' => $request->context->serverContext ?? null,
        'viewToken' => $request->context->viewToken ?? null,
        'nativeGeneration' => $request->context->nativeGeneration ?? null,
    ];
    return qd_with_locked_pair_context(
        $settings,
        $dependencies,
        (string)($auth['pairId'] ?? ''),
        $requiredScope,
        $expectedScopeHash,
        $context,
        hash('sha256', $matches[1]),
        $callback,
    );
}

/** Stock main.php has no bearer header; the one-use staged record carries its original view identity. */
function qd_with_locked_companion_context(array $settings, array $dependencies, string $pairId, string $scopeHash, array $context, callable $callback): mixed
{
    return qd_with_locked_pair_context($settings, $dependencies, $pairId, 'pcv', $scopeHash, $context, null, $callback);
}

function qd_new_server_context(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function qd_new_scope_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function qd_perspective_projection(array $dependencies, string $scopeKey, array $readiness): object
{
    $empty = [
        'mp' => ['choices' => [], 'limited' => true],
        'sas' => ['choices' => [], 'limited' => true],
    ];
    $callback = $dependencies['perspectives'] ?? null;
    if (!is_callable($callback)) {
        return (object)[
            'mp' => (object)$empty['mp'],
            'sas' => (object)$empty['sas'],
        ];
    }
    try {
        $raw = $callback($scopeKey);
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
    if (!is_array($raw) || array_diff(array_keys($raw), ['mp', 'sas']) !== []) {
        throw new QDServerFault('unavailable');
    }
    $projection = [];
    foreach (['mp', 'sas'] as $plugin) {
        if (!$readiness[$plugin]) {
            $projection[$plugin] = (object)$empty[$plugin];
            continue;
        }
        $source = $raw[$plugin] ?? null;
        if (!is_array($source)
            || array_diff(array_keys($source), ['choices', 'limited']) !== []
            || !is_array($source['choices'] ?? null)
            || !array_is_list($source['choices'])
            || !is_bool($source['limited'] ?? null)) {
            throw new QDServerFault('unavailable');
        }
        $choices = [];
        foreach (array_slice($source['choices'], 0, 64) as $choice) {
            if (!is_array($choice)
                || array_diff(array_keys($choice), ['actorKey', 'label']) !== []
                || !isset($choice['actorKey'], $choice['label'])
                || !qd_valid_string($choice['actorKey'], 128)
                || !qd_valid_string($choice['label'], 256)) {
                throw new QDServerFault('unavailable');
            }
            $choices[] = (object)['actorKey' => $choice['actorKey'], 'label' => $choice['label']];
        }
        $limited = $source['limited'] || count($source['choices']) > 64;
        $projection[$plugin] = (object)['choices' => $choices, 'limited' => $limited];
    }
    return (object)$projection;
}

function qd_bootstrap_response(object $request, ?string $authorizationHeader, array $settings, array $dependencies): array
{
    $auth = qd_authenticate($settings['pairingPath'], $settings['webRoot'], $authorizationHeader, $settings['privateRoot'] ?? null, $settings['coreRoot'] ?? null);
    if ($auth === null) {
        return qd_success_response($request, [
            'authenticated' => false,
            'authRequired' => true,
            'serverContext' => null,
            'scopeToken' => null,
            'capabilities' => qd_false_capabilities(),
            'access' => qd_false_access(),
        ]);
    }

    $pcvReadyAllowed = false;
    if (in_array('pcv', $auth['scopes'], true)) {
        try {
            $overlay = qd_pcv_overlay_apply($settings);
            $pcvReadyAllowed = in_array($overlay['state'] ?? null, ['enabled', 'already_enabled'], true);
        } catch (Throwable) {
            $pcvReadyAllowed = false;
        }
    }

    return qd_with_profile_lease($dependencies, static fn(): array => qd_bootstrap_authenticated_response(
        $request,
        $authorizationHeader,
        $settings,
        $dependencies,
        $auth,
        $pcvReadyAllowed,
    ));
}

function qd_bootstrap_authenticated_response(object $request, ?string $authorizationHeader, array $settings, array $dependencies, array $auth, bool $pcvReadyAllowed = true): array
{
    $scopeKey = qd_authoritative_scope($dependencies, $auth['pairId']);
    $scopeHash = hash('sha256', $scopeKey);
    $readiness = qd_runtime_readiness($dependencies, $pcvReadyAllowed);
    $access = qd_access_for_scopes($auth['scopes']);
    $context = qd_new_server_context();
    $data = [
        'authenticated' => true,
        'authRequired' => false,
        'serverContext' => $context,
        'capabilities' => $readiness,
        'access' => $access,
    ];
    if ($access['inspector']) {
        $data['perspectives'] = qd_perspective_projection($dependencies, $scopeKey, $readiness);
    }
    $freshAuth = qd_authenticate($settings['pairingPath'], $settings['webRoot'], $authorizationHeader, $settings['privateRoot'] ?? null, $settings['coreRoot'] ?? null);
    if ($freshAuth === null || $freshAuth['pairId'] !== $auth['pairId']) {
        throw new QDServerFault('unauthorized');
    }
    if ($freshAuth['scopes'] !== $auth['scopes']) {
        throw new QDServerFault('forbidden');
    }
    $freshScope = qd_authoritative_scope($dependencies, $auth['pairId']);
    if (!hash_equals($scopeHash, hash('sha256', $freshScope))) {
        throw new QDServerFault('stale_context');
    }
    $scopeToken = qd_server_state_transaction($settings, static function (array &$state) use ($auth, $context, $scopeHash, $request): string {
        $previous = $state['contexts'][$auth['pairId']] ?? null;
        $sameScope = is_array($previous)
            && is_string($previous['scopeHash'] ?? null)
            && hash_equals($previous['scopeHash'], $scopeHash)
            && is_string($previous['scopeToken'] ?? null)
            && qd_valid_string($previous['scopeToken'], 128);
        $scopeToken = $sameScope ? $previous['scopeToken'] : qd_new_scope_token();
        $state['contexts'][$auth['pairId']] = [
            'contextHash' => hash('sha256', $context),
            'viewToken' => $request->context->viewToken,
            'nativeGeneration' => $request->context->nativeGeneration,
            'scopeHash' => $scopeHash,
            'scopeToken' => $scopeToken,
            'pendingReadMacKey' => bin2hex(random_bytes(32)),
        ];
        return $scopeToken;
    });
    $data['scopeToken'] = $scopeToken;
    return qd_success_response($request, $data);
}

function qd_operation_id(object $request): string
{
    return $request->operation === 'correspondence.resume' ? $request->payload->attemptId : $request->payload->operationId;
}

function qd_operation_semantic_payload(object $request): stdClass
{
    $semanticPayload = clone $request->payload;
    unset($semanticPayload->{$request->operation === 'correspondence.resume' ? 'attemptId' : 'operationId'});
    return $semanticPayload;
}

function qd_to_json_value(mixed $value): mixed
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            return array_map('qd_to_json_value', $value);
        }
        $object = new stdClass();
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new QDServerFault('unavailable');
            }
            $object->$key = qd_to_json_value($item);
        }
        return $object;
    }
    if (is_object($value)) {
        $object = new stdClass();
        foreach (get_object_vars($value) as $key => $item) {
            $object->$key = qd_to_json_value($item);
        }
        return $object;
    }
    return $value;
}

function qd_is_safe_number(mixed $value): bool
{
    return is_int($value)
        ? abs((float)$value) <= 9007199254740991
        : (is_float($value) && is_finite($value) && abs($value) <= 9007199254740991);
}

function qd_valid_mp_typed_value(mixed $value): bool
{
    return $value === null || qd_is_safe_number($value) || qd_valid_string($value, 256);
}

function qd_validate_mp_projection(object $data, string $perspectiveActorKey): bool
{
    if (array_diff(array_keys(get_object_vars($data)), ['perspectiveActorKey', 'records', 'limited', 'nextCursor']) !== []
        || array_diff(['perspectiveActorKey', 'records', 'limited', 'nextCursor'], array_keys(get_object_vars($data))) !== []
        || $data->perspectiveActorKey !== $perspectiveActorKey
        || !is_array($data->records)
        || !array_is_list($data->records)
        || count($data->records) > QD_MAX_PAGE_ROWS
        || !is_bool($data->limited)
        || ($data->nextCursor !== null && !qd_valid_string($data->nextCursor, 512))) {
        return false;
    }
    $fields = ['id', 'owner', 'target', 'at', 'proposedDelta', 'appliedDelta', 'historicalBefore', 'historicalAfter', 'currentValue', 'currentAsOf', 'sourceKind', 'fixedReason', 'kind', 'confirmation'];
    $seen = [];
    foreach ($data->records as $row) {
        if (!is_object($row)
            || array_diff(array_keys(get_object_vars($row)), $fields) !== []
            || array_diff($fields, array_keys(get_object_vars($row))) !== []
            || !qd_valid_string($row->id ?? null, 128)
            || isset($seen[$row->id])
            || !qd_valid_string($row->owner ?? null, 256)
            || !qd_valid_string($row->target ?? null, 256)
            || !(($row->at ?? null) === null || qd_valid_string($row->at, 256))
            || !(($row->proposedDelta ?? null) === null || qd_is_safe_number($row->proposedDelta))
            || !(($row->appliedDelta ?? null) === null || qd_is_safe_number($row->appliedDelta))
            || !qd_valid_mp_typed_value($row->historicalBefore ?? null)
            || !qd_valid_mp_typed_value($row->historicalAfter ?? null)
            || !qd_valid_mp_typed_value($row->currentValue ?? null)
            || !(($row->currentAsOf ?? null) === null || qd_valid_string($row->currentAsOf, 256))
            || !(($row->sourceKind ?? null) === null || qd_valid_string($row->sourceKind, 128))
            || !(($row->fixedReason ?? null) === null || qd_valid_string($row->fixedReason, 256))
            || !(($row->kind ?? null) === null || qd_valid_string($row->kind, 128))
            || !(($row->confirmation ?? null) === null || qd_valid_string($row->confirmation, 128))) {
            return false;
        }
        $seen[$row->id] = true;
    }
    return true;
}

function qd_page_field(string $operation): ?string
{
    return match ($operation) {
        'pcv.roster.read', 'correspondence.catalog.read' => 'actors',
        'mp.relationships.read' => 'records',
        'sas.issues.read' => 'issues',
        'correspondence.mailbox.read' => 'letters',
        'correspondence.pending.read' => 'operations',
        default => null,
    };
}

function qd_response_has_next_cursor(object $data): bool
{
    return property_exists($data, 'nextCursor') && $data->nextCursor !== null && $data->nextCursor !== '';
}

function qd_validate_pending_operation_rows(array $operations): bool
{
    $expectedKeys = ['canResume', 'hasTransfer', 'operationId', 'operatorClosed', 'queuedReason', 'recipientLabel', 'state'];
    foreach ($operations as $row) {
        if (!is_object($row)) return false;
        $keys = array_keys(get_object_vars($row));
        sort($keys, SORT_STRING);
        if ($keys !== $expectedKeys
            || !qd_is_uuid($row->operationId ?? null)
            || !qd_valid_string($row->recipientLabel ?? null)
            || !in_array($row->state ?? null, ['stored', 'queued', 'dispatched', 'unknown', 'partial'], true)
            || !is_bool($row->hasTransfer ?? null)
            || !is_bool($row->canResume ?? null)
            || !is_bool($row->operatorClosed ?? null)) {
            return false;
        }
        if ($row->state === 'queued') {
            if (!is_string($row->queuedReason ?? null)
                || !in_array($row->queuedReason, QD_PENDING_QUEUED_REASONS, true)
                || ($row->canResume && $row->operatorClosed)) {
                return false;
            }
        } elseif ($row->queuedReason !== null || $row->canResume) {
            return false;
        }
        if (in_array($row->state, ['dispatched', 'unknown', 'partial'], true) && !$row->hasTransfer) return false;
    }
    return true;
}

function qd_operation_has_cursor(string $operation): bool
{
    return in_array($operation, ['mp.relationships.read', 'sas.issues.read', 'correspondence.catalog.read', 'correspondence.mailbox.read', 'correspondence.pending.read'], true);
}

function qd_response_with_bounded_data(object $request, mixed $rawData, bool $authorDetail): array
{
    if (!is_array($rawData) && !is_object($rawData)) {
        throw new QDServerFault('unavailable');
    }
    if (is_array($rawData) && $rawData !== [] && array_is_list($rawData)) {
        throw new QDServerFault('unavailable');
    }
    $data = qd_to_json_value($rawData);
    if (!is_object($data)) {
        $data = (object)[];
    }
    if (!$authorDetail && property_exists($data, 'recordedAuthor')) {
        unset($data->recordedAuthor);
    }
    if ($request->operation === 'mp.relationships.read'
        && !qd_validate_mp_projection($data, $request->payload->perspectiveActorKey)) {
        throw new QDServerFault('unavailable');
    }

    $field = qd_page_field($request->operation);
    if ($field !== null && (!property_exists($data, $field) || !is_array($data->$field) || !array_is_list($data->$field))) {
        throw new QDServerFault('unavailable');
    }
    if ($field !== null) {
        if (!property_exists($data, 'limited') || !is_bool($data->limited)) {
            throw new QDServerFault('unavailable');
        }
        if (qd_operation_has_cursor($request->operation)) {
            if (!property_exists($data, 'nextCursor')
                || ($data->nextCursor !== null && !qd_valid_string($data->nextCursor, 512))) {
                throw new QDServerFault('unavailable');
            }
        } elseif (property_exists($data, 'nextCursor')) {
            throw new QDServerFault('unavailable');
        }
        if ($request->operation === 'correspondence.pending.read') {
            $keys = array_keys(get_object_vars($data));
            sort($keys, SORT_STRING);
            if ($keys !== ['limited', 'nextCursor', 'operations'] || count($data->operations) > 25
                || $data->limited !== qd_response_has_next_cursor($data)
                || !qd_validate_pending_operation_rows($data->operations)) {
                throw new QDServerFault('unavailable');
            }
        }
        if (count($data->$field) > QD_MAX_PAGE_ROWS) {
            if (qd_response_has_next_cursor($data)) {
                throw new QDServerFault('unavailable');
            }
            $data->$field = array_slice($data->$field, 0, QD_MAX_PAGE_ROWS);
            $data->limited = true;
        }
        if (count($data->$field) > 0 && !property_exists($data, 'limited')) {
            $data->limited = false;
        }
    }

    while (true) {
        $response = qd_success_response($request, $data);
        $treeError = qd_validate_json_tree(qd_to_json_value($response));
        $encoded = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($treeError === null && strlen($encoded) <= QD_MAX_BODY_BYTES) {
            return $response;
        }
        if ($field === null || !property_exists($data, $field) || !is_array($data->$field) || $data->$field === []) {
            throw new QDServerFault('unavailable');
        }
        if (qd_response_has_next_cursor($data)) {
            throw new QDServerFault('unavailable');
        }
        array_pop($data->$field);
        $data->limited = true;
    }
}

function qd_dispatch_authorized_operation(object $request, array $auth, string $scopeKey, string $scopeHash, string $authorizationHeader, array $settings, array $dependencies, array $readiness, ?string $pendingReadMacKey = null): array
{
    $operation = $request->operation;
    $callback = $dependencies['operations'][$operation] ?? null;
    if (!is_callable($callback)) {
        throw new QDServerFault('unavailable');
    }
    if ($operation === 'correspondence.pending.read'
        && (!is_string($pendingReadMacKey) || preg_match('/\A[a-f0-9]{64}\z/D', $pendingReadMacKey) !== 1)) {
        throw new QDServerFault('stale_context');
    }
    $requiredScope = QD_OPERATION_REQUIREMENTS[$operation]['scope'];
    $currentAuth = $auth;
    $commitGuard = static function () use ($settings, $dependencies, $authorizationHeader, $auth, $requiredScope, $request, $scopeHash, &$currentAuth): void {
        $currentAuth = qd_assert_current_context($settings, $dependencies, $authorizationHeader, $auth, $requiredScope, $request, $scopeHash);
    };
    $journalKey = null;
    $mutationEntered = false;
    $ownerReplay = false;
    $beginCommit = static function () use (&$journalKey, &$mutationEntered, &$ownerReplay, $settings): void {
        if ($ownerReplay) return;
        if (!is_string($journalKey)) {
            throw new QDServerFault('unavailable');
        }
        if (!$mutationEntered) {
            qd_journal_mark_commit($settings, $journalKey);
            $mutationEntered = true;
        }
    };
    $callbackContext = [
        'principalId' => $auth['pairId'],
        'scopeKey' => $scopeKey,
        'scopeHash' => $scopeHash,
        'scopes' => $auth['scopes'],
        'serverContext' => $request->context->serverContext,
        'viewToken' => $request->context->viewToken,
        'nativeGeneration' => $request->context->nativeGeneration,
        'pendingReadMacKey' => $operation === 'correspondence.pending.read' ? $pendingReadMacKey : null,
        'assertCurrent' => $commitGuard,
        'beginCommit' => $beginCommit,
        'withCurrentContext' => static fn(callable $callback): mixed => qd_with_current_context(
            $settings,
            $dependencies,
            $authorizationHeader,
            $auth,
            $requiredScope,
            $request,
            $scopeHash,
            $callback,
        ),
        'withLockedCurrentContext' => static fn(callable $callback): mixed => qd_with_locked_current_context(
            $settings,
            $dependencies,
            $authorizationHeader,
            $auth,
            $requiredScope,
            $request,
            $scopeHash,
            $callback,
        ),
    ];

    if (in_array($operation, QD_MUTATION_OPERATIONS, true)) {
        $commitGuard();
        $payloadHash = hash('sha256', qd_canonical_json($request->payload));
        $semanticPayload = qd_operation_semantic_payload($request);
        $semanticHash = hash('sha256', qd_canonical_json($semanticPayload));
        $ownerIdempotent = in_array($operation, QD_OWNER_IDEMPOTENT_MUTATIONS, true);
        $journal = qd_journal_begin($settings, $auth['pairId'], $scopeHash, $operation, qd_operation_id($request), $payloadHash, $semanticHash, $ownerIdempotent);
        if ($journal['action'] === 'replay') {
            $response = qd_response_with_bounded_data($request, $journal['data'], qd_access_for_scopes($currentAuth['scopes'])['authorDetail']);
            $commitGuard();
            if (!qd_access_for_scopes($currentAuth['scopes'])['authorDetail'] && property_exists($response['data'], 'recordedAuthor')) {
                unset($response['data']->recordedAuthor);
            }
            return $response;
        }
        if ($journal['action'] === 'owner_replay' && $ownerIdempotent) {
            $ownerReplay = true;
        } else {
            $journalKey = $journal['key'];
        }
    }

    try {
        $data = $callback($request->payload, $callbackContext);
        $commitGuard();
        $response = qd_response_with_bounded_data($request, $data, qd_access_for_scopes($currentAuth['scopes'])['authorDetail']);
        if ($journalKey !== null) {
            $dataJson = in_array($operation, QD_OWNER_IDEMPOTENT_MUTATIONS, true)
                ? json_encode((object)['ownerReceipt' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : json_encode($response['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (!is_string($dataJson) || strlen($dataJson) > QD_MAX_JOURNAL_RESULT_BYTES) {
                throw new QDServerFault('unavailable');
            }
            $commitGuard();
            qd_journal_complete($settings, $journalKey, $dataJson);
        } else {
            $commitGuard();
        }
        if (!qd_access_for_scopes($currentAuth['scopes'])['authorDetail'] && property_exists($response['data'], 'recordedAuthor')) {
            unset($response['data']->recordedAuthor);
        }
        return $response;
    } catch (Throwable $error) {
        if ($journalKey !== null && !$mutationEntered) {
            try {
                qd_journal_abandon_no_write($settings, $journalKey);
            } catch (Throwable) {
                throw new QDServerFault('unavailable');
            }
        }
        if ($error instanceof QDServerFault) {
            throw $error;
        }
        throw new QDServerFault('unavailable');
    }
}

function qd_server_dispatch(mixed $request, ?string $authorizationHeader, array $settings, array $dependencies = []): array
{
    $requestObject = is_object($request) ? $request : null;
    try {
        $error = qd_validate_request($request);
        if ($error !== null) {
            return qd_error_response($requestObject, $error);
        }
        if (!is_string($settings['pairingPath'] ?? null) || !is_string($settings['webRoot'] ?? null)) {
            if ($request->operation === 'bootstrap') {
                return qd_success_response($request, [
                    'authenticated' => false,
                    'authRequired' => true,
                    'serverContext' => null,
                    'scopeToken' => null,
                    'capabilities' => qd_false_capabilities(),
                    'access' => qd_false_access(),
                ]);
            }
            throw new QDServerFault('unauthorized');
        }
        if ($request->operation === 'bootstrap') {
            return qd_bootstrap_response($request, $authorizationHeader, $settings, $dependencies);
        }

        $auth = qd_authenticate($settings['pairingPath'], $settings['webRoot'], $authorizationHeader, $settings['privateRoot'] ?? null, $settings['coreRoot'] ?? null);
        if ($auth === null) {
            throw new QDServerFault('unauthorized');
        }
        $requiredScope = QD_OPERATION_REQUIREMENTS[$request->operation]['scope'];
        if (!qd_has_required_scope($auth['scopes'], $requiredScope)) {
            throw new QDServerFault('forbidden');
        }
        return qd_with_profile_lease($dependencies, static function () use ($request, $auth, $authorizationHeader, $settings, $dependencies): array {
            $scopeKey = qd_authoritative_scope($dependencies, $auth['pairId']);
            $scopeHash = hash('sha256', $scopeKey);
            $contextRecord = qd_context_record_current($settings, $auth['pairId'], $request, $scopeHash);
            if ($contextRecord === null) {
                throw new QDServerFault('stale_context');
            }
            $pendingReadMacKey = null;
            if ($request->operation === 'correspondence.pending.read') {
                $pendingReadMacKey = $contextRecord['pendingReadMacKey'] ?? null;
                if (!is_string($pendingReadMacKey) || preg_match('/\A[a-f0-9]{64}\z/D', $pendingReadMacKey) !== 1) {
                    throw new QDServerFault('stale_context');
                }
            }
            $readiness = qd_runtime_readiness($dependencies);
            $capability = QD_OPERATION_REQUIREMENTS[$request->operation]['capability'];
            if (!is_string($capability) || !$readiness[$capability]) {
                throw new QDServerFault('unavailable');
            }
            return qd_dispatch_authorized_operation($request, $auth, $scopeKey, $scopeHash, $authorizationHeader ?? '', $settings, $dependencies, $readiness, $pendingReadMacKey);
        });
    } catch (QDServerFault $error) {
        return qd_error_response($requestObject, $error->wireCode);
    } catch (Throwable) {
        return qd_error_response($requestObject, 'unavailable');
    }
}
