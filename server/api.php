<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/protocol.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/dispatcher.php';
require_once __DIR__ . '/runtime.php';

function qd_api_handle(string $method, ?string $contentType, string $body, ?string $authorizationHeader, array $settings, array $dependencies = []): array
{
    $error = null;
    $request = null;
    $stage = 'request';
    $parsed = strlen($body) <= QD_MAX_PROFILE_REQUEST_BYTES ? qd_parse_json_request($body) : ['request' => null, 'error' => 'payload_too_large'];
    if (is_object($parsed['request']) && qd_valid_echo_metadata($parsed['request'])) {
        $request = $parsed['request'];
    }
    if ($method !== 'POST') {
        $error = 'method_not_allowed';
    } elseif (!is_string($contentType) || preg_match('/\A\s*application\/json\s*(?:;\s*charset\s*=\s*utf-8\s*)?\z/iD', $contentType) !== 1) {
        $error = 'unsupported_media_type';
    } elseif (strlen($body) > QD_MAX_PROFILE_REQUEST_BYTES) {
        $error = 'payload_too_large';
    } elseif ($parsed['error'] !== null) {
        $error = $parsed['error'];
    }

    if ($error !== null) {
        $response = qd_error_response($request, $error);
    } else {
        $stage = 'dispatch';
        $bufferLevel = ob_get_level();
        if (!ob_start()) {
            $response = qd_error_response($request, 'unavailable');
        } else {
            try {
                $response = qd_server_dispatch($request, $authorizationHeader, $settings, $dependencies);
            } finally {
                while (ob_get_level() > $bufferLevel) {
                    ob_end_clean();
                }
            }
        }
    }
    $code = ($response['ok'] ?? false) === false ? (string)($response['error']['code'] ?? 'unavailable') : '';
    $status = $code === '' ? 200 : qd_http_status_for_error($code);
    try {
        if ($code === '') $stage = 'serialize';
        $encoded = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $status = 503;
        $code = 'unavailable';
        $stage = 'serialize';
        $encoded = json_encode(qd_error_response(null, 'unavailable'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    if (strlen($encoded) > qd_response_body_limit($request->operation ?? null)) {
        $status = 503;
        $code = 'unavailable';
        $stage = 'response_size';
        $encoded = json_encode(qd_error_response(null, 'unavailable'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    if ($code !== '') qd_api_log_diagnostic($request, $code, $status, $stage);
    $headers = [
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store, max-age=0',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];
    if ($status === 405) {
        $headers['Allow'] = 'POST';
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $encoded];
}

function qd_api_log_diagnostic(?object $request, string $code, int $status, string $stage, ?callable $logger = null): void
{
    $operation = is_string($request->operation ?? null) && array_key_exists($request->operation, QD_OPERATION_REQUIREMENTS)
        ? $request->operation
        : null;
    $requestId = qd_is_uuid($request->requestId ?? null) ? $request->requestId : null;
    if (!array_key_exists($code, QD_ERROR_MESSAGES)) $code = 'unavailable';
    if ($status !== qd_http_status_for_error($code)) $status = qd_http_status_for_error($code);
    if (!in_array($stage, ['request', 'dispatch', 'serialize', 'response_size'], true)) $stage = 'request';
    $event = [
        'operation' => $operation,
        'requestId' => $requestId,
        'code' => $code,
        'status' => $status,
        'stage' => $stage,
        'at' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    try {
        $encoded = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $write = $logger ?? static fn(string $line): bool => @error_log($line);
        $write('QD_API_DIAGNOSTIC ' . $encoded);
    } catch (Throwable) {
        // Diagnostics must not change the API result when the logger is unavailable.
    }
}

function qd_valid_echo_metadata(object $request): bool
{
    if (($request->version ?? null) !== 1
        || !qd_is_uuid($request->requestId ?? null)
        || !qd_valid_string($request->operation ?? null, 64)
        || !is_object($request->context ?? null)
        || !qd_exact_properties($request->context, ['viewToken', 'nativeGeneration', 'serverContext'])
        || !qd_is_uuid($request->context->viewToken)
        || !is_string($request->context->nativeGeneration)
        || preg_match('/\A[0-9]{1,20}\z/D', $request->context->nativeGeneration) !== 1
        || ($request->context->serverContext !== null && !qd_valid_string($request->context->serverContext, 128))) {
        return false;
    }
    return true;
}

function qd_api_settings_from_environment(): array
{
    $coreRoot = null;
    try {
        $coreRoot = qd_runtime_engine_root();
    } catch (Throwable) {
        // Legacy explicit pairing/state paths remain usable without a derived install root.
    }
    $privateRoot = is_string($coreRoot) ? qd_default_private_root($coreRoot) : null;
    $pairingOverride = getenv('QD_PAIRING_FILE');
    $stateOverride = getenv('QD_SERVER_STATE_FILE');
    $webRootOverride = getenv('QD_WEB_ROOT');
    $pcvOverride = getenv('QD_PCV_ROOT');
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
    $canonicalDocumentRoot = is_string($documentRoot) && $documentRoot !== '' && !str_contains($documentRoot, "\0")
        ? realpath($documentRoot)
        : false;
    if ($canonicalDocumentRoot === false || !is_dir($canonicalDocumentRoot)) {
        $canonicalDocumentRoot = null;
    }
    $pcvCandidate = is_string($pcvOverride) && $pcvOverride !== ''
        ? $pcvOverride
        : (is_string($coreRoot) ? $coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation' : '');
    $pcvRoot = $pcvCandidate !== '' && !str_contains($pcvCandidate, "\0") && !is_link($pcvCandidate)
        ? realpath($pcvCandidate)
        : false;
    return [
        'pairingPath' => is_string($pairingOverride) && $pairingOverride !== ''
            ? $pairingOverride
            : (is_string($privateRoot) ? $privateRoot . DIRECTORY_SEPARATOR . 'pairing.php' : ''),
        'statePath' => is_string($stateOverride) && $stateOverride !== ''
            ? $stateOverride
            : (is_string($privateRoot) ? $privateRoot . DIRECTORY_SEPARATOR . 'state.php' : ''),
        'webRoot' => is_string($webRootOverride) && $webRootOverride !== ''
            ? $webRootOverride
            : (is_string($canonicalDocumentRoot) ? $canonicalDocumentRoot : (is_string($coreRoot) ? $coreRoot : '')),
        'privateRoot' => $privateRoot,
        'coreRoot' => $coreRoot,
        'chimRoot' => $coreRoot,
        'pcvRoot' => is_string($pcvRoot) ? $pcvRoot : null,
    ];
}

function qd_api_runtime_dependencies(): array
{
    if (!function_exists('qd_department_runtime_dependencies')) {
        return [];
    }
    try {
        $dependencies = qd_department_runtime_dependencies();
        return is_array($dependencies) ? $dependencies : [];
    } catch (Throwable) {
        return [];
    }
}

function qd_api_emit(): void
{
    $stream = @fopen('php://input', 'rb');
    $body = $stream === false ? '' : stream_get_contents($stream, QD_MAX_PROFILE_REQUEST_BYTES + 1);
    if (is_resource($stream)) {
        fclose($stream);
    }
    if (!is_string($body)) {
        $body = '';
    }
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (is_string($name) && strtolower($name) === 'authorization' && is_string($value)) {
                $authorization = $value;
                break;
            }
        }
    }
    $response = qd_api_handle(
        is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '',
        is_string($_SERVER['CONTENT_TYPE'] ?? null) ? $_SERVER['CONTENT_TYPE'] : null,
        $body,
        is_string($authorization) ? $authorization : null,
        qd_api_settings_from_environment(),
        qd_api_runtime_dependencies(),
    );
    http_response_code($response['status']);
    foreach ($response['headers'] as $name => $value) {
        header($name . ': ' . $value, true);
    }
    echo $response['body'];
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    qd_api_emit();
}
