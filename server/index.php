<?php
declare(strict_types=1);

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/lib/connect.php';
require_once __DIR__ . '/lib/overlay_setup.php';

function qd_onboarding_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function qd_onboarding_origin_is_same(): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    $hostHeader = $_SERVER['HTTP_HOST'] ?? null;
    if (!is_string($origin) || !is_string($hostHeader) || $hostHeader === '') {
        return false;
    }
    $httpsValue = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $scheme = in_array($httpsValue, ['on', '1', 'true'], true) ? 'https' : 'http';
    if (preg_match('~\A(https?)://([^/?#]+)\z~iD', $origin, $originMatch) !== 1
        || strtolower($originMatch[1]) !== $scheme) {
        return false;
    }
    $originAuthority = qd_onboarding_parse_authority($originMatch[2], $scheme);
    $hostAuthority = qd_onboarding_parse_authority($hostHeader, $scheme);
    if ($originAuthority === null || $hostAuthority === null
        || $originAuthority['host'] !== $hostAuthority['host']
        || $originAuthority['port'] !== $hostAuthority['port']) {
        return false;
    }
    $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    return !is_string($fetchSite) || $fetchSite === 'same-origin';
}

function qd_onboarding_parse_authority(string $authority, string $scheme): ?array
{
    if ($authority === '' || preg_match('/[\x00-\x20\x7f]/', $authority) === 1
        || strpbrk($authority, "/\\?#@%") !== false) {
        return null;
    }

    if (str_starts_with($authority, '[')) {
        if (preg_match('/\A\[([0-9a-f:.]+)\](?::([0-9]{1,5}))?\z/iD', $authority, $matches) !== 1
            || filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }
        $host = strtolower($matches[1]);
        $portText = $matches[2] ?? null;
    } else {
        if (preg_match('/\A([a-z0-9.-]+)(?::([0-9]{1,5}))?\z/iD', $authority, $matches) !== 1) {
            return null;
        }
        $host = strtolower($matches[1]);
        $portText = $matches[2] ?? null;
        $hostname = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
        if ($hostname === '' || str_ends_with($hostname, '.') || strlen($hostname) > 253
            || preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*\z/iD', $hostname) !== 1
            || (preg_match('/\A[0-9.]+\z/', $hostname) === 1 && filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)) {
            return null;
        }
    }

    $port = $scheme === 'https' ? 443 : 80;
    if ($portText !== null) {
        $parsedPort = filter_var($portText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if (!is_int($parsedPort)) {
            return null;
        }
        $port = $parsedPort;
    }
    return ['host' => $host, 'port' => $port];
}

function qd_onboarding_headers(): void
{
    header('Content-Type: text/html; charset=utf-8', true);
    header('Cache-Control: no-store, max-age=0', true);
    header('Pragma: no-cache', true);
    header('X-Content-Type-Options: nosniff', true);
    header('X-Frame-Options: DENY', true);
    header('Referrer-Policy: no-referrer', true);
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'", true);
    header('Cross-Origin-Resource-Policy: same-origin', true);
}

function qd_onboarding_render(string $title, string $message, ?string $claimId = null, ?string $csrf = null, bool $approve = false, bool $setupPcv = false): void
{
    qd_onboarding_headers();
    $escapedTitle = qd_onboarding_html($title);
    $escapedMessage = qd_onboarding_html($message);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . $escapedTitle . '</title><style>body{font:16px system-ui,sans-serif;max-width:42rem;margin:4rem auto;padding:0 1.25rem;color:#20242a}main{border:1px solid #c8ccd2;border-radius:12px;padding:1.5rem}button{font:inherit;font-weight:650;padding:.7rem 1.1rem;border:0;border-radius:8px;background:#2457c5;color:white}p{line-height:1.55}small{color:#59616c}</style>';
    echo '<body><main><h1>' . $escapedTitle . '</h1><p>' . $escapedMessage . '</p>';
    if ($approve && $claimId !== null && $csrf !== null) {
        echo '<ul><li>Set up conversations</li><li>View relationships and disputes</li><li>Compose letters</li></ul>';
        echo '<form method="post"><input type="hidden" name="claim" value="' . qd_onboarding_html($claimId) . '"><input type="hidden" name="csrf" value="' . qd_onboarding_html($csrf) . '"><button type="submit" name="decision" value="approve">Connect this game</button></form>';
    }
    if ($setupPcv && $csrf !== null) {
        echo '<hr><h2>Private Conversation</h2><p>Private Conversation needs a small installation update to work with the Department. The update is applied only when the installed files match a supported version, and the original files are saved first. You can skip this; the other Department features will still work.</p>';
        echo '<form method="post"><input type="hidden" name="csrf" value="' . qd_onboarding_html($csrf) . '"><button type="submit" name="decision" value="enable_pcv">Set up Private Conversation</button></form>';
    }
    echo '</main></body></html>';
}

function qd_onboarding_overlay_result(array $result): array
{
    if (($result['state'] ?? null) === 'enabled') {
        return ['Private Conversation ready', 'Private Conversation is ready to use with the Department.'];
    }
    if (($result['state'] ?? null) === 'already_enabled') {
        return ['Private Conversation ready', 'Private Conversation is already set up for the Department.'];
    }
    $message = match ($result['reason'] ?? null) {
        'owner_missing' => 'Private Conversation is not installed. Install its CHIM server plugin, then try again.',
        'owner_incompatible' => 'The installed Private Conversation files do not match a supported version. The other Department features can still be used.',
        'staged_source_missing' => 'This Department installation is missing setup files. Reinstall the Department server plugin before trying again.',
        'unsafe_path' => 'The installation paths could not be verified, so no files were changed.',
        'capacity' => 'There is not enough free space to safely prepare Private Conversation. No files were changed.',
        default => 'Private Conversation could not be prepared. Ask your CHIM maintainer to review the installation before retrying.',
    };
    return ['Private Conversation setup unavailable', $message];
}

function qd_onboarding_session_start(): bool
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return true;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $cookie = session_get_cookie_params();
    $cookieParams = [
        'lifetime' => 0,
        'path' => is_string($cookie['path'] ?? null) && $cookie['path'] !== '' ? $cookie['path'] : '/',
        'secure' => ($cookie['secure'] ?? false) === true || in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')), ['on', '1', 'true'], true),
        'httponly' => true,
        'samesite' => 'Strict',
    ];
    if (is_string($cookie['domain'] ?? null) && $cookie['domain'] !== '') {
        $cookieParams['domain'] = $cookie['domain'];
    }
    session_set_cookie_params($cookieParams);
    return @session_start();
}

function qd_onboarding_emit(): void
{
    qd_onboarding_headers();
    $method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '';
    if (!in_array($method, ['GET', 'POST'], true)) {
        header('Allow: GET, POST', true);
        http_response_code(405);
        qd_onboarding_render('Connection approval', 'This page supports GET and POST only.');
        return;
    }
    if (!qd_onboarding_session_start()) {
        http_response_code(503);
        qd_onboarding_render('Connection approval unavailable', 'CHIM could not start a browser session for this approval.');
        return;
    }
    if (!is_string($_SESSION['qd_connect_csrf'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $_SESSION['qd_connect_csrf']) !== 1) {
        $_SESSION['qd_connect_csrf'] = bin2hex(random_bytes(32));
    }
    $csrf = $_SESSION['qd_connect_csrf'];
    $settings = qd_api_settings_from_environment();

    if ($method === 'POST') {
        $claimId = is_string($_POST['claim'] ?? null) && qd_is_uuid($_POST['claim']) ? strtolower($_POST['claim']) : null;
        $postedCsrf = $_POST['csrf'] ?? null;
        $decision = $_POST['decision'] ?? null;
        if (!qd_onboarding_origin_is_same() || !is_string($postedCsrf) || !hash_equals($csrf, $postedCsrf)
            || !in_array($decision, ['approve', 'enable_pcv'], true)
            || ($decision === 'approve' && $claimId === null)) {
            http_response_code(403);
            qd_onboarding_render('Approval not accepted', 'The approval request could not be verified. Reopen the Department connection page and try again.');
            return;
        }
        if ($decision === 'enable_pcv') {
            try {
                $result = qd_pcv_overlay_apply($settings);
                $_SESSION['qd_connect_csrf'] = bin2hex(random_bytes(32));
                session_write_close();
                [$title, $message] = qd_onboarding_overlay_result($result);
                if (in_array($result['state'] ?? null, ['enabled', 'already_enabled'], true)) {
                    qd_onboarding_render($title, $message);
                    return;
                }
                http_response_code(503);
                qd_onboarding_render($title, $message);
                return;
            } catch (Throwable) {
                session_write_close();
                http_response_code(503);
                qd_onboarding_render('Private Conversation setup unavailable', 'The installation could not be safely updated. Ask your CHIM maintainer to review it before retrying.');
                return;
            }
        }
        try {
            $state = qd_connect_approve($settings, $claimId, time());
            $nextCsrf = bin2hex(random_bytes(32));
            $_SESSION['qd_connect_csrf'] = $nextCsrf;
            session_write_close();
            if ($state === 'approved') {
                qd_onboarding_render('Game connected', 'Private Conversation is optional. Set it up here, then return to Skyrim and choose Check connection.', null, $nextCsrf, false, true);
                return;
            }
            if ($state === 'unavailable') {
                http_response_code(503);
                qd_onboarding_render('Approval unavailable', 'CHIM could not save this connection. No approval was confirmed; retry from the Department.');
                return;
            }
            http_response_code(410);
            qd_onboarding_render('Request expired', 'This game request is expired or no longer eligible. Start a fresh connection from the Department.');
            return;
        } catch (Throwable) {
            session_write_close();
            http_response_code(503);
            qd_onboarding_render('Approval unavailable', 'CHIM could not save the approval. No connection was confirmed; retry from the Department.');
            return;
        }
    }

    $claimId = is_string($_GET['claim'] ?? null) && qd_is_uuid($_GET['claim']) ? strtolower($_GET['claim']) : null;
    if ($claimId === null) {
        session_write_close();
        qd_onboarding_render('Department of Questionable Decisions', 'Open Connect to CHIM in Skyrim to start. Then return here and approve the connection.', null, $csrf, false, true);
        return;
    }
    try {
        $state = qd_connect_status($settings, $claimId, time());
    } catch (Throwable) {
        session_write_close();
        http_response_code(503);
        qd_onboarding_render('Connection request unavailable', 'The pending game request could not be read. Return to the Department and start a fresh connection.');
        return;
    }
    session_write_close();
    if ($state !== 'pending') {
        $message = $state === 'approved'
            ? 'This game connection is approved. Return to Skyrim and choose Check connection.'
            : 'This request is expired or no longer eligible. Start a fresh connection from the Department.';
        qd_onboarding_render('Department connection', $message, null, $csrf, false, true);
        return;
    }
    qd_onboarding_render('Connect this game', 'Allow this Skyrim game to set up conversations, view relationships and disputes, and send letters. Approve only the connection you just started from the Department.', $claimId, $csrf, true, true);
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    qd_onboarding_emit();
}
