<?php
declare(strict_types=1);

if (isset($GLOBALS['gameRequest']) && is_array($GLOBALS['gameRequest'])
    && ($GLOBALS['gameRequest'][0] ?? null) === 'physics_raw'
    && is_string($GLOBALS['gameRequest'][3] ?? null)
    && str_starts_with($GLOBALS['gameRequest'][3], 'dql_connect_v1:')
    && ($_SERVER['REQUEST_METHOD'] ?? null) === 'GET') {
    require_once __DIR__ . '/api.php';
    require_once __DIR__ . '/lib/connect.php';
    qd_connect_handle_stock_event(
        qd_api_settings_from_environment(),
        $GLOBALS['gameRequest'],
        $_SERVER,
    );
}
