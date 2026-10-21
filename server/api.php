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
    $parsed = strlen($body) <= QD_MAX_BODY_BYTES ? qd_parse_json_request($body) : ['request' => null, 'error' => 'payload_too_large'];
    if (is_object($parsed['request']) && qd_valid_echo_metadata($parsed['request'])) {
        $request = $parsed['request'];
    }
    if ($method !== 'POST') {
        $error = 'method_not_allowed';
    } elseif (!is_string($contentType) || preg_match('/\A\s*application\/json\s*(?:;\s*charset\s*=\s*utf-8\s*)?\z/iD', $contentType) !== 1) {
        $error = 'unsupported_media_type';
    } elseif (strlen($body) > QD_MAX_BODY_BYTES) {
        $error = 'payload_too_large';
    } elseif ($parsed['error'] !== null) {
        $error = $parsed['error'];
    }

    if ($error !== null) {
        $response = qd_error_response($request, $error);
    } else {
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
        $encoded = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $status = 503;
        $encoded = json_encode(qd_error_response(null, 'unavailable'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    if (strlen($encoded) > QD_MAX_BODY_BYTES) {
        $status = 503;
        $encoded = json_encode(qd_error_response(null, 'unavailable'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
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
    return [
        'pairingPath' => getenv('QD_PAIRING_FILE') ?: '',
        'statePath' => getenv('QD_SERVER_STATE_FILE') ?: '',
        'webRoot' => getenv('QD_WEB_ROOT') ?: '',
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
    $body = $stream === false ? '' : stream_get_contents($stream, QD_MAX_BODY_BYTES + 1);
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
