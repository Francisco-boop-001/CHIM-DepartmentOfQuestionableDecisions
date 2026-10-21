<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'companion.php';

function pcv_companion_hook_result(string $state, ?array $operation): array
{
    if ($state === 'unknown' || !is_array($operation)) {
        return [
            'operationId' => is_string($operation['operationId'] ?? null) ? $operation['operationId'] : null,
            'state' => 'unknown',
            'expected' => ['activeConfigId' => null, 'pendingConfigId' => null],
            'target' => null,
        ];
    }
    return [
        'operationId' => $operation['operationId'],
        'state' => $state,
        'expected' => [
            'activeConfigId' => $operation['expectedActiveConfigId'] ?? null,
            'pendingConfigId' => $operation['expectedPendingConfigId'] ?? null,
        ],
        'target' => [
            'formId' => $operation['target']['formId'] ?? null,
            'name' => $operation['target']['name'] ?? null,
        ],
    ];
}

function pcv_companion_emit_hook_result(array $projection): void
{
    $GLOBALS['PCV_COMPANION_RESULT'] = $projection;
    if (PHP_SAPI !== 'cli') {
        http_response_code(409);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
    }
    echo json_encode(['companion' => $projection], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pcv_companion_stop_stock_request(string $state, ?array $operation, ?string $token = null): void
{
    $projection = pcv_companion_hook_result($state, $operation);
    if ($state === 'rejected' && is_array($operation) && is_string($token)) {
        $retired = pcv_companion_retire_route_token($operation, $token, time());
        if ($retired !== 'rejected') {
            $projection = pcv_companion_hook_result($retired === 'expired' ? 'expired' : 'unknown', $operation);
        }
    } elseif ($state === 'expired' && is_array($operation) && is_string($token)) {
        $retired = pcv_companion_retire_route_token($operation, $token, time());
        if ($retired !== 'expired') {
            $projection['state'] = 'unknown';
            $projection['expected'] = ['activeConfigId' => null, 'pendingConfigId' => null];
            $projection['target'] = null;
        }
    }
    pcv_companion_emit_hook_result($projection);
}

function pcv_companion_raw_data_frame(): ?string
{
    $query = $_SERVER['QUERY_STRING'] ?? null;
    if (!is_string($query) || !str_starts_with($query, 'DATA=')) {
        return null;
    }
    $separator = strpos($query, '&');
    $encoded = $separator === false ? substr($query, 5) : substr($query, 5, $separator - 5);
    if ($encoded === '') {
        return null;
    }
    $decoded = base64_decode($encoded, false);
    return is_string($decoded) ? $decoded : null;
}

function pcv_companion_raw_message_token(array $rawParts): ?array
{
    $message = $rawParts[3] ?? null;
    if (!is_string($message)
        || preg_match('/\A([^:\r\n]{1,96}):(pcv1_[A-Za-z0-9_-]{43})\z/D', $message, $matches) !== 1) {
        return null;
    }
    return ['playerName' => $matches[1], 'token' => $matches[2]];
}

function pcv_companion_raw_snapshot_matches(string $encoded, array $target): bool
{
    if (strlen($encoded) > 32768) {
        return false;
    }
    $json = base64_decode($encoded, true);
    if (!is_string($json) || preg_match('//u', $json) !== 1) {
        return false;
    }
    try {
        $snapshot = json_decode($json, true, 12, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return false;
    }
    if (!is_array($snapshot)
        || ($snapshot['source'] ?? null) !== 'plugin_player_routing_v2'
        || ($snapshot['listener'] ?? null) !== ($target['name'] ?? null)
        || ($snapshot['target_mode'] ?? null) !== 'direct'
        || ($snapshot['speech_mode'] ?? null) !== 'standard'
        || ($snapshot['execution_mode'] ?? null) !== 'STANDARD'
        || (array_key_exists('chat_shortcut_routed', $snapshot) && $snapshot['chat_shortcut_routed'] !== false)
        || !is_array($snapshot['present_actors'] ?? null)
        || !array_is_list($snapshot['present_actors'])
        || !is_string($target['name'] ?? null)
        || preg_match('/\A[0-9a-fA-F]{8}\z/D', (string)($target['formId'] ?? '')) !== 1
        || count($snapshot['present_actors']) > 64
        || !is_string($target['formId'] ?? null)
        || !is_int(hexdec($target['formId']))) {
        return false;
    }
    $expectedFormId = hexdec($target['formId']);
    $nameKey = static fn(string $value): string => function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $nameMatches = 0;
    $formMatches = 0;
    foreach ($snapshot['present_actors'] as $actor) {
        if (!is_array($actor) || !is_int($actor['form_id'] ?? null) || !is_string($actor['name'] ?? null)) {
            return false;
        }
        $sameName = $nameKey($actor['name']) === $nameKey($target['name']);
        if ($sameName) {
            $nameMatches++;
            if ($actor['name'] !== $target['name'] || $actor['form_id'] !== $expectedFormId
                || ($actor['managed'] ?? null) !== true || ($actor['creature'] ?? null) !== false) {
                return false;
            }
        }
        if ($actor['form_id'] === $expectedFormId) {
            $formMatches++;
            if (!$sameName) {
                return false;
            }
        }
    }
    return $nameMatches === 1 && $formMatches === 1;
}

$rawFrame = pcv_companion_raw_data_frame();
if (!is_string($rawFrame)) {
    return;
}

$rawParts = explode('|', $rawFrame);
$candidate = pcv_companion_raw_message_token($rawParts);
if ($candidate === null) {
    return;
}
$playerName = $candidate['playerName'];
$token = $candidate['token'];
try {
    $directory = pcv_companion_state_directory();
    $inspection = pcv_companion_inspect_token($directory, $token, time());
} catch (Throwable) {
    pcv_companion_stop_stock_request('unknown', null);
}
if (($inspection['state'] ?? null) !== 'prepared') {
    pcv_companion_stop_stock_request(
        ($inspection['state'] ?? null) === 'expired' ? 'expired' : 'unknown',
        ($inspection['state'] ?? null) === 'expired' ? $inspection : null,
        ($inspection['state'] ?? null) === 'expired' ? $token : null,
    );
}

$frameIsExact = count($rawParts) === 5 && strtolower((string)$rawParts[0]) === 'inputtext';
$routeIsExact = $frameIsExact && pcv_companion_raw_snapshot_matches((string)$rawParts[4], $inspection['target']);
if (!$routeIsExact) {
    pcv_companion_stop_stock_request('rejected', $inspection, $token);
}

$GLOBALS['PCV_COMPANION_PACKET'] = [
    'recordKey' => $inspection['recordKey'],
    'principalId' => $inspection['principalId'],
    'operationId' => $inspection['operationId'],
    'token' => $token,
    'target' => $inspection['target'],
    'expectedActiveConfigId' => $inspection['expectedActiveConfigId'],
    'expectedPendingConfigId' => $inspection['expectedPendingConfigId'],
    'scopeHash' => $inspection['scopeHash'],
    'ownerKey' => $inspection['ownerKey'],
    'context' => $inspection['context'],
    'expiresAt' => $inspection['expiresAt'],
    'playerName' => $playerName,
];
