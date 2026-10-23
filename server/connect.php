<?php
declare(strict_types=1);

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/lib/connect.php';

function qd_connect_emit(): void
{
    $stream = @fopen('php://input', 'rb');
    $body = $stream === false ? '' : stream_get_contents($stream, 4097);
    if (is_resource($stream)) {
        fclose($stream);
    }
    $response = qd_connect_handle(
        is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '',
        is_string($_SERVER['CONTENT_TYPE'] ?? null) ? $_SERVER['CONTENT_TYPE'] : null,
        is_string($body) ? $body : '',
        qd_api_settings_from_environment(),
    );
    http_response_code($response['status']);
    foreach ($response['headers'] as $name => $value) {
        header($name . ': ' . $value, true);
    }
    echo $response['body'];
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    qd_connect_emit();
}
