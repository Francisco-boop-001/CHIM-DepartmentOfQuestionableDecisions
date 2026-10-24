<?php
declare(strict_types=1);

function qd_onboarding_emit(): void
{
    header('Content-Type: text/html; charset=utf-8', true);
    header('Cache-Control: no-store, max-age=0', true);
    header('Pragma: no-cache', true);
    header('X-Content-Type-Options: nosniff', true);
    header('X-Frame-Options: DENY', true);
    header('Referrer-Policy: no-referrer', true);
    header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'", true);
    header('Cross-Origin-Resource-Policy: same-origin', true);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Department of Questionable Decisions</title></head><body><main><h1>Department of Questionable Decisions</h1><p>Open the Department in Skyrim; setup runs automatically.</p><p>This page does not approve or create a connection.</p></main></body></html>';
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    qd_onboarding_emit();
}