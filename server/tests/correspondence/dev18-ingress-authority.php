<?php
declare(strict_types=1);

final class QDServerFault extends RuntimeException
{
    public function __construct(public string $fault)
    {
        parent::__construct($fault);
    }
}

$GLOBALS['dev18_ingress_begin_count'] = 0;
function correspondenceBeginRequestIngress(array $nativeRequest): void
{
    if ($nativeRequest !== []) throw new RuntimeException('The fresh web ingress must not synthesize native authority.');
    $GLOBALS['dev18_ingress_begin_count']++;
    $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'] = false;
    $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'] = null;
    $GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS'] = null;
}

require_once __DIR__ . '/../../adapters/runtime_support.php';
require_once __DIR__ . '/../../adapters/correspondence.php';

function dev18_ingress_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

foreach ([
    ['native timestamp', '1700000000'],
    ['malformed native timestamp', 'invalid-clock'],
] as [$label, $nativeTs]) {
    $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'] = false;
    $GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'] = null;
    $GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS'] = $nativeTs;
    $before = $GLOBALS['dev18_ingress_begin_count'];
    $rejected = false;
    try {
        qd_correspondence_dev18_begin_fresh_ingress();
    } catch (QDServerFault $error) {
        $rejected = $error->fault === 'stale_context';
    }
    dev18_ingress_expect($rejected, 'Uncaptured ' . $label . ' must be refused.');
    dev18_ingress_expect($GLOBALS['dev18_ingress_begin_count'] === $before, 'Refusal must happen before ingress reset.');
}

$GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'] = false;
$GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'] = 'stale-token';
$GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS'] = null;
$before = $GLOBALS['dev18_ingress_begin_count'];
$rejected = false;
try {
    qd_correspondence_dev18_begin_fresh_ingress();
} catch (QDServerFault $error) {
    $rejected = $error->fault === 'stale_context';
}
dev18_ingress_expect($rejected, 'An uncaptured non-null restore token must be refused.');
dev18_ingress_expect($GLOBALS['dev18_ingress_begin_count'] === $before, 'Token refusal must happen before ingress reset.');

$GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_CAPTURED'] = false;
$GLOBALS['CORRESPONDENCE_REQUEST_RESTORE_TOKEN'] = null;
$GLOBALS['CORRESPONDENCE_REQUEST_NATIVE_TS'] = null;
qd_correspondence_dev18_begin_fresh_ingress();
dev18_ingress_expect($GLOBALS['dev18_ingress_begin_count'] === $before + 1, 'A fresh absent/null ingress should initialize once.');

fwrite(STDOUT, "PASS: stale uncaptured authority is refused before ingress reset\n");
