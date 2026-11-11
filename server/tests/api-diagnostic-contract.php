<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api.php';

function apiDiagnosticExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$requestId = '11111111-1111-4111-8111-111111111111';
$request = (object)[
    'operation' => 'correspondence.receipt.check',
    'requestId' => $requestId,
    'payload' => (object)['body' => 'PROFILE_SECRET_BODY', 'authorization' => 'HEADER_SECRET_AUTH'],
    'context' => (object)['serverContext' => 'CONTEXT_SECRET', 'viewToken' => 'VIEW_SECRET'],
];
$lines = [];
qd_api_log_diagnostic($request, 'unavailable', 503, 'dispatch', static function (string $line) use (&$lines): bool {
    $lines[] = $line;
    return true;
});
apiDiagnosticExpect(count($lines) === 1 && str_starts_with($lines[0], 'QD_API_DIAGNOSTIC '), 'API diagnostic emits one fixed event line');
$event = json_decode(substr($lines[0], strlen('QD_API_DIAGNOSTIC ')), true, 8, JSON_THROW_ON_ERROR);
apiDiagnosticExpect(is_array($event) && array_keys($event) === ['operation', 'requestId', 'code', 'status', 'stage', 'at'], 'diagnostic field set is exact and allowlisted');
apiDiagnosticExpect($event['operation'] === 'correspondence.receipt.check' && $event['requestId'] === $requestId
    && $event['code'] === 'unavailable' && $event['status'] === 503 && $event['stage'] === 'dispatch', 'diagnostic retains only validated request identity and fixed server outcome');
apiDiagnosticExpect(is_string($event['at']) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T/', $event['at']) === 1, 'diagnostic timestamp is a fixed UTC ISO value');
foreach (['PROFILE_SECRET_BODY', 'HEADER_SECRET_AUTH', 'CONTEXT_SECRET', 'VIEW_SECRET'] as $sentinel) {
    apiDiagnosticExpect(!str_contains($lines[0], $sentinel), 'diagnostic must not contain body, header, profile, or context sentinels');
}

$untrustedLine = null;
qd_api_log_diagnostic((object)['operation' => 'private.operation', 'requestId' => 'not-a-uuid'], 'private_error', 599, 'private-stage', static function (string $line) use (&$untrustedLine): bool {
    $untrustedLine = $line;
    return true;
});
$untrusted = json_decode(substr((string)$untrustedLine, strlen('QD_API_DIAGNOSTIC ')), true, 8, JSON_THROW_ON_ERROR);
apiDiagnosticExpect($untrusted === ['operation' => null, 'requestId' => null, 'code' => 'unavailable', 'status' => 503, 'stage' => 'request', 'at' => $untrusted['at']], 'unvalidated operation, UUID, code, status, and stage collapse to fixed safe values');

qd_api_log_diagnostic($request, 'unavailable', 503, 'dispatch', static fn(string $line): bool => false);
qd_api_log_diagnostic($request, 'unavailable', 503, 'dispatch', static function (string $line): bool { throw new RuntimeException('logger_failed'); });
apiDiagnosticExpect(true, 'a false or throwing logger cannot escape the diagnostic boundary');

$httpRequest = (object)[
    'version' => 1,
    'requestId' => $requestId,
    'operation' => 'bootstrap',
    'context' => (object)['viewToken' => '22222222-2222-4222-8222-222222222222', 'nativeGeneration' => '1', 'serverContext' => null],
    'payload' => new stdClass(),
];
$body = json_encode($httpRequest, JSON_THROW_ON_ERROR);
$logRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-api-diagnostic-' . bin2hex(random_bytes(8));
if (!mkdir($logRoot, 0700)) throw new RuntimeException('could not create isolated API diagnostic log directory');
$logPath = $logRoot . DIRECTORY_SEPARATOR . 'diagnostic.log';
$priorErrorLog = ini_get('error_log');
try {
    if (ini_set('error_log', $logPath) === false) throw new RuntimeException('could not redirect isolated API diagnostic log');
    $successRequest = clone $httpRequest;
    $successRequest->payload = new stdClass();
    $success = qd_api_handle('POST', 'application/json', json_encode($successRequest, JSON_THROW_ON_ERROR), null,
        ['pairingPath' => $logRoot . DIRECTORY_SEPARATOR . 'pairing.json', 'webRoot' => $logRoot], []);
    apiDiagnosticExpect($success['status'] === 200 && !file_exists($logPath), 'successful API requests do not emit failure diagnostics');

    $privacyRequest = clone $httpRequest;
    $privacyRequest->payload = (object)['body' => 'PROFILE_SECRET_BODY', 'authorization' => 'HEADER_SECRET_AUTH'];
    $privacyRequest->context->serverContext = 'CONTEXT_SECRET';
    $refused = qd_api_handle('POST', 'application/json', json_encode($privacyRequest, JSON_THROW_ON_ERROR), 'Bearer HEADER_SECRET_AUTH',
        ['pairingPath' => $logRoot . DIRECTORY_SEPARATOR . 'pairing.json', 'webRoot' => $logRoot], []);
    apiDiagnosticExpect($refused['status'] === 400 && json_decode($refused['body'], true, 8, JSON_THROW_ON_ERROR)['error']['code'] === 'invalid_request', 'API refusal status and fixed error stay unchanged');
    $actualLog = file_get_contents($logPath);
    apiDiagnosticExpect(is_string($actualLog) && str_contains($actualLog, 'QD_API_DIAGNOSTIC ') && str_contains($actualLog, '"operation":"bootstrap"')
        && str_contains($actualLog, '"requestId":"' . $requestId . '"'), 'the API logs validated request identity for a refusal');
    foreach (['PROFILE_SECRET_BODY', 'HEADER_SECRET_AUTH', 'CONTEXT_SECRET'] as $sentinel) {
        apiDiagnosticExpect(!str_contains($actualLog, $sentinel), 'the API diagnostic log omits request body, Authorization, and context values');
    }
} finally {
    if (is_string($priorErrorLog)) ini_set('error_log', $priorErrorLog);
    if (is_file($logPath)) unlink($logPath);
    rmdir($logRoot);
}

fwrite(STDOUT, "PASS: privacy-safe API diagnostics and best-effort failure handling\n");
