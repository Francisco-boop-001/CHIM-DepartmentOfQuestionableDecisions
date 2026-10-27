<?php
declare(strict_types=1);

// The host loads globals.php before it decodes DATA and before any preprocessing hook.
// Keep this early reservation probe isolated from the host's include-scope variables.
(static function (): void {
    $query = $_SERVER['QUERY_STRING'] ?? null;
    if (!is_string($query) || !str_starts_with($query, 'DATA=')) {
        return;
    }

    $separator = strpos($query, '&', 5);
    $dataLength = $separator === false ? strlen($query) - 5 : $separator - 5;
    $encoded = substr($query, 5, min($dataLength, 512));
    $decoded = base64_decode($encoded);
    if (!is_string($decoded)) {
        return;
    }
    $request = explode('|', $decoded);
    if (($request[0] ?? null) !== 'physics_raw'
        || !is_string($request[3] ?? null)
        || !str_starts_with($request[3], 'dql_connect_v1:')) {
        return;
    }

    // The host assigns this after parsing DATA, but this hook terminates before that point.
    $GLOBALS['AUDIT_RUNID_REQUEST'] = 'physics_raw';

    // Consume this marker before SHARMAT's preprocessing can reinterpret it as physics telemetry.
    $suffix = $separator === false ? '' : substr($query, $separator);
    $canonical = $dataLength <= 512
        && base64_encode($decoded) === $encoded
        && ($suffix === '' || preg_match('/\A&profile=[^&\r\n]*\z/D', $suffix) === 1);
    $outcome = 'malformed_query';
    if ($canonical) {
        try {
            require_once __DIR__ . '/api.php';
            require_once __DIR__ . '/lib/connect.php';
            $outcome = qd_connect_handle_stock_event(
                qd_api_settings_from_environment(),
                $request,
                $_SERVER,
            ) ?? 'expired';
            if (!in_array($outcome, ['approved', 'expired', 'unavailable'], true)) {
                $outcome = 'expired';
            }
        } catch (Throwable) {
            $outcome = 'unavailable';
        }
    }

    try {
        error_log('[Department] connection_ingress=' . $outcome);
    } catch (Throwable) {
        // Logging must never allow a reserved packet to fall through to later hooks.
    }

    terminate();
})();
