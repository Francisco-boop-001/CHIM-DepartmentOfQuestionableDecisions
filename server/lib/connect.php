<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const QD_CONNECT_CLAIM_TTL = 600;
const QD_CONNECT_PENDING_LIMIT = 32;
const QD_CONNECT_CLAIM_LIMIT = 256;
const QD_CONNECT_TERMINAL_RETENTION = 1800;
const QD_CONNECT_CLAIM_BYTES = 524288;

function qd_connect_valid_claim_store(mixed $store): bool
{
    if (!is_array($store) || array_keys($store) !== ['version', 'claims']
        || ($store['version'] ?? null) !== 1 || !is_array($store['claims'] ?? null)
        || !array_is_list($store['claims']) || count($store['claims']) > QD_CONNECT_CLAIM_LIMIT) {
        return false;
    }
    $seen = [];
    foreach ($store['claims'] as $claim) {
        if (!is_array($claim) || array_keys($claim) !== ['claimId', 'tokenHash', 'createdAt', 'expiresAt', 'state', 'pairId']
            || !qd_is_uuid($claim['claimId'] ?? null)
            || isset($seen[strtolower($claim['claimId'])])
            || !is_string($claim['tokenHash'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/D', $claim['tokenHash']) !== 1
            || !is_int($claim['createdAt'] ?? null) || $claim['createdAt'] < 1
            || !is_int($claim['expiresAt'] ?? null) || $claim['expiresAt'] <= $claim['createdAt']
            || !in_array($claim['state'] ?? null, ['pending', 'approved', 'expired', 'revoked'], true)
            || (($claim['pairId'] ?? null) !== null && !qd_is_uuid($claim['pairId']))) {
            return false;
        }
        if (($claim['state'] === 'approved') !== is_string($claim['pairId'])) {
            return false;
        }
        $seen[strtolower($claim['claimId'])] = true;
    }
    return true;
}

/** Read only the status of an existing token hash. This helper never issues a pair. */
function qd_connect_pair_hash_state(array $settings, string $tokenHash): array
{
    $pairingPath = $settings['pairingPath'] ?? null;
    $webRoot = $settings['webRoot'] ?? null;
    $privateRoot = $settings['privateRoot'] ?? null;
    if (!is_string($pairingPath) || !is_string($webRoot)) {
        throw new QDServerFault('unavailable');
    }
    $privateRoot = is_string($privateRoot) ? $privateRoot : null;
    $protectedRoot = is_string($settings['coreRoot'] ?? null) ? $settings['coreRoot'] : null;
    $storage = qd_storage_file_path($pairingPath, $webRoot, $privateRoot, true, $protectedRoot);
    if ($storage === null) {
        $candidate = qd_storage_file_path($pairingPath, $webRoot, $privateRoot, false, $protectedRoot);
        if ($candidate === null || is_link($candidate['path']) || file_exists($candidate['path'])) {
            throw new QDServerFault('unavailable');
        }
        return ['state' => 'new', 'pairId' => null];
    }
    $safePath = $storage['path'];
    $size = @filesize($safePath);
    if ($size === false || $size > QD_MAX_PAIRING_FILE_BYTES + ($storage['guarded'] ? strlen(QD_GUARDED_JSON_PREFIX) : 0)) {
        throw new QDServerFault('unavailable');
    }
    $raw = @file_get_contents($safePath);
    if (!is_string($raw)) {
        throw new QDServerFault('unavailable');
    }
    if ($storage['guarded']) {
        if (!str_starts_with($raw, QD_GUARDED_JSON_PREFIX)) {
            throw new QDServerFault('unavailable');
        }
        $raw = substr($raw, strlen(QD_GUARDED_JSON_PREFIX));
    }
    if (strlen($raw) > QD_MAX_PAIRING_FILE_BYTES) {
        throw new QDServerFault('unavailable');
    }
    try {
        $config = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new QDServerFault('unavailable');
    }
    if (!qd_valid_pairing_config($config)) {
        throw new QDServerFault('unavailable');
    }
    $match = null;
    foreach ($config['pairs'] as $pair) {
        if (hash_equals($pair['tokenHash'], $tokenHash)) {
            if ($match !== null) {
                throw new QDServerFault('unavailable');
            }
            $match = $pair;
        }
    }
    if ($match === null) {
        return ['state' => 'new', 'pairId' => null];
    }
    return [
        'state' => $match['revoked'] ? 'revoked' : 'approved',
        'pairId' => $match['pairId'],
    ];
}

function qd_connect_claim_transaction(array $settings, callable $callback): mixed
{
    $privateRoot = $settings['privateRoot'] ?? null;
    $webRoot = $settings['webRoot'] ?? null;
    if (!is_string($privateRoot) || !is_string($webRoot)
        || qd_managed_directory_path($privateRoot, $privateRoot, false) === null) {
        throw new QDServerFault('unavailable');
    }
    return qd_locked_json_transaction(
        $privateRoot . DIRECTORY_SEPARATOR . 'connect-claims.php',
        $webRoot,
        ['version' => 1, 'claims' => []],
        static function (array &$store) use ($callback): mixed {
            if (!qd_connect_valid_claim_store($store)) {
                throw new QDServerFault('unavailable');
            }
            return $callback($store);
        },
        QD_CONNECT_CLAIM_BYTES,
        $privateRoot,
        is_string($settings['coreRoot'] ?? null) ? $settings['coreRoot'] : null,
    );
}

function qd_connect_expire_claims(array &$store, int $now): void
{
    foreach ($store['claims'] as &$claim) {
        if ($claim['state'] === 'pending' && $claim['expiresAt'] <= $now) {
            $claim['state'] = 'expired';
        }
    }
    unset($claim);
    $store['claims'] = array_values(array_filter(
        $store['claims'],
        static fn(array $claim): bool => $claim['state'] === 'pending'
            || $claim['createdAt'] >= $now - QD_CONNECT_TERMINAL_RETENTION,
    ));
}

function qd_connect_public_state(string $state): string
{
    return in_array($state, ['pending', 'approved'], true) ? $state : 'expired';
}

function qd_connect_begin(array $settings, string $claimId, string $tokenHash, int $now): array
{
    return qd_connect_claim_transaction($settings, static function (array &$store) use ($settings, $claimId, $tokenHash, $now): array {
        foreach ($store['claims'] as &$claim) {
            if (strtolower($claim['claimId']) !== strtolower($claimId)) {
                continue;
            }
            if (!hash_equals($claim['tokenHash'], $tokenHash)) {
                throw new QDServerFault('conflict');
            }
            $pair = qd_connect_pair_hash_state($settings, $tokenHash);
            if ($pair['state'] === 'approved') {
                $claim['state'] = 'approved';
                $claim['pairId'] = $pair['pairId'];
            } elseif ($pair['state'] === 'revoked') {
                $claim['state'] = 'revoked';
                $claim['pairId'] = null;
            } elseif ($claim['state'] === 'approved') {
                $claim['state'] = 'expired';
                $claim['pairId'] = null;
            } elseif ($claim['state'] === 'pending' && $claim['expiresAt'] <= $now) {
                $claim['state'] = 'expired';
            }
            return ['state' => qd_connect_public_state($claim['state'])];
        }
        unset($claim);

        qd_connect_expire_claims($store, $now);
        $pair = qd_connect_pair_hash_state($settings, $tokenHash);
        if (count($store['claims']) >= QD_CONNECT_CLAIM_LIMIT) {
            throw new QDServerFault('unavailable');
        }
        if ($pair['state'] === 'approved' || $pair['state'] === 'revoked') {
            $store['claims'][] = [
                'claimId' => strtolower($claimId),
                'tokenHash' => $tokenHash,
                'createdAt' => $now,
                'expiresAt' => $now + QD_CONNECT_CLAIM_TTL,
                'state' => $pair['state'],
                'pairId' => $pair['state'] === 'approved' ? $pair['pairId'] : null,
            ];
            return ['state' => qd_connect_public_state($pair['state'])];
        }
        $pending = 0;
        foreach ($store['claims'] as $claim) {
            if ($claim['state'] === 'pending') {
                $pending++;
            }
        }
        if ($pending >= QD_CONNECT_PENDING_LIMIT) {
            throw new QDServerFault('unavailable');
        }
        $store['claims'][] = [
            'claimId' => strtolower($claimId),
            'tokenHash' => $tokenHash,
            'createdAt' => $now,
            'expiresAt' => $now + QD_CONNECT_CLAIM_TTL,
            'state' => 'pending',
            'pairId' => null,
        ];
        return ['state' => 'pending'];
    });
}

function qd_connect_status(array $settings, string $claimId, int $now): string
{
    return qd_connect_claim_transaction($settings, static function (array &$store) use ($settings, $claimId, $now): string {
        foreach ($store['claims'] as &$claim) {
            if (strtolower($claim['claimId']) !== strtolower($claimId)) {
                continue;
            }
            $pair = qd_connect_pair_hash_state($settings, $claim['tokenHash']);
            if ($pair['state'] === 'approved') {
                $claim['state'] = 'approved';
                $claim['pairId'] = $pair['pairId'];
                return 'approved';
            }
            if ($pair['state'] === 'revoked') {
                $claim['state'] = 'revoked';
                $claim['pairId'] = null;
                return 'expired';
            }
            if ($claim['state'] === 'approved') {
                $claim['state'] = 'expired';
                $claim['pairId'] = null;
                return 'expired';
            }
            if ($claim['state'] === 'pending' && $claim['expiresAt'] <= $now) {
                $claim['state'] = 'expired';
            }
            return qd_connect_public_state($claim['state']);
        }
        return 'expired';
    });
}

/** Called only after a browser session, CSRF token, same-origin and explicit form POST are verified. */
function qd_connect_approve(array $settings, string $claimId, int $now): string
{
    return qd_connect_claim_transaction($settings, static function (array &$store) use ($settings, $claimId, $now): string {
        foreach ($store['claims'] as &$claim) {
            if (strtolower($claim['claimId']) !== strtolower($claimId)) {
                continue;
            }
            $pair = qd_connect_pair_hash_state($settings, $claim['tokenHash']);
            if ($pair['state'] === 'approved') {
                $claim['state'] = 'approved';
                $claim['pairId'] = $pair['pairId'];
                return 'approved';
            }
            if ($pair['state'] === 'revoked') {
                $claim['state'] = 'revoked';
                $claim['pairId'] = null;
                return 'expired';
            }
            if ($claim['state'] === 'approved') {
                $claim['state'] = 'expired';
                $claim['pairId'] = null;
                return 'expired';
            }
            if ($claim['state'] !== 'pending' || $claim['expiresAt'] <= $now) {
                $claim['state'] = 'expired';
                return 'expired';
            }
            $approved = qd_pair_approve_hash(
                $settings['pairingPath'],
                $settings['webRoot'],
                $claim['tokenHash'],
                is_string($settings['privateRoot'] ?? null) ? $settings['privateRoot'] : null,
                is_string($settings['coreRoot'] ?? null) ? $settings['coreRoot'] : null,
            );
            if (($approved['state'] ?? null) === 'capacity') {
                return 'unavailable';
            }
            if ($approved['state'] !== 'approved' || !qd_is_uuid($approved['pairId'] ?? null)) {
                return 'expired';
            }
            $claim['state'] = 'approved';
            $claim['pairId'] = $approved['pairId'];
            return 'approved';
        }
        return 'expired';
    });
}

function qd_connect_json_response(int $status, array $payload, array $extraHeaders = []): array
{
    try {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $status = 503;
        $body = '{"ok":false,"error":"unavailable"}';
    }
    return [
        'status' => $status,
        'headers' => array_merge([
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ], $extraHeaders),
        'body' => $body,
    ];
}

function qd_connect_handle(string $method, ?string $contentType, string $body, array $settings, ?int $now = null): array
{
    if ($method !== 'POST') {
        return qd_connect_json_response(405, ['ok' => false, 'error' => 'method_not_allowed'], ['Allow' => 'POST']);
    }
    if (!is_string($contentType) || preg_match('/\A\s*application\/json\s*(?:;\s*charset\s*=\s*utf-8\s*)?\z/iD', $contentType) !== 1) {
        return qd_connect_json_response(415, ['ok' => false, 'error' => 'unsupported_media_type']);
    }
    if (strlen($body) > 4096) {
        return qd_connect_json_response(413, ['ok' => false, 'error' => 'payload_too_large']);
    }
    try {
        $request = json_decode($body, false, 4, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return qd_connect_json_response(400, ['ok' => false, 'error' => 'invalid_request']);
    }
    if (!is_object($request) || !is_string($request->action ?? null) || !qd_is_uuid($request->claimId ?? null)) {
        return qd_connect_json_response(400, ['ok' => false, 'error' => 'invalid_request']);
    }
    $claimId = strtolower($request->claimId);
    $now ??= time();
    try {
        if ($request->action === 'begin'
            && qd_exact_properties($request, ['action', 'claimId', 'tokenHash'])
            && is_string($request->tokenHash)
            && preg_match('/\A[0-9a-f]{64}\z/D', $request->tokenHash) === 1) {
            $result = qd_connect_begin($settings, $claimId, $request->tokenHash, $now);
            $payload = [
                'ok' => true,
                'state' => $result['state'],
                'claimId' => $claimId,
            ];
            if ($result['state'] === 'pending') {
                $payload['approvalPath'] = '/ext/questionable_decisions/index.php?claim=' . rawurlencode($claimId);
            }
            return qd_connect_json_response(200, $payload);
        }
        if ($request->action === 'status' && qd_exact_properties($request, ['action', 'claimId'])) {
            return qd_connect_json_response(200, [
                'ok' => true,
                'state' => qd_connect_status($settings, $claimId, $now),
                'claimId' => $claimId,
            ]);
        }
        return qd_connect_json_response(400, ['ok' => false, 'error' => 'invalid_request']);
    } catch (QDServerFault $fault) {
        $status = match ($fault->wireCode) {
            'conflict' => 409,
            'invalid_request' => 400,
            default => 503,
        };
        return qd_connect_json_response($status, ['ok' => false, 'error' => $fault->wireCode]);
    } catch (Throwable) {
        return qd_connect_json_response(503, ['ok' => false, 'error' => 'unavailable']);
    }
}
