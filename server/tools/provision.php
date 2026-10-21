<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../lib/protocol.php';
require_once __DIR__ . '/../lib/auth.php';

function qd_provision_usage(): never
{
    fwrite(STDERR, "Usage:\n  php provision.php issue <pairing-file> <web-root> <scope,scope,...>\n  php provision.php revoke <pairing-file> <web-root> <pair-id>\n");
    exit(2);
}

function qd_provision_main(array $arguments): int
{
    if (PHP_SAPI !== 'cli' || count($arguments) < 4) {
        qd_provision_usage();
    }
    $action = $arguments[1];
    $pairingPath = $arguments[2];
    $webRoot = $arguments[3];
    try {
        if ($action === 'issue' && count($arguments) === 5) {
            $scopeText = $arguments[4];
            $scopes = explode(',', $scopeText);
            if ($scopeText === '' || in_array('', $scopes, true)) {
                qd_provision_usage();
            }
            $pair = qd_pair_issue($pairingPath, $webRoot, $scopes);
            fwrite(STDOUT, json_encode($pair, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
            return 0;
        }
        if ($action === 'revoke' && count($arguments) === 5) {
            qd_pair_revoke($pairingPath, $webRoot, $arguments[4]);
            fwrite(STDOUT, "Revoked " . $arguments[4] . PHP_EOL);
            return 0;
        }
        qd_provision_usage();
    } catch (QDServerFault $error) {
        fwrite(STDERR, 'Provisioning failed: ' . $error->wireCode . PHP_EOL);
        return 1;
    } catch (Throwable) {
        fwrite(STDERR, "Provisioning failed: unavailable\n");
        return 1;
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    exit(qd_provision_main($argv));
}
