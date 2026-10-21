<?php
declare(strict_types=1);

$ownerRoot = '';
$adapterPath = dirname(__DIR__, 2) . '/adapters/correspondence.php';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--owner-root' && isset($argv[$i + 1])) {
        $ownerRoot = $argv[++$i];
    } elseif ($argv[$i] === '--adapter' && isset($argv[$i + 1])) {
        $adapterPath = $argv[++$i];
    } else {
        fwrite(STDERR, "usage: php -n dev20-closed-unknown.php --owner-root OWNER_SERVER_DIR [--adapter PATH]\n");
        exit(2);
    }
}

function dev20ClosedExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function qd_runtime_core_root(): string
{
    $root = $GLOBALS['DEV20_TEST_CORE_ROOT'] ?? null;
    if (!is_string($root) || $root === '') throw new RuntimeException('The isolated test core root is unavailable.');
    return $root;
}
function dev20ClosedCleanup(string $root): void
{
    if (!is_dir($root) || is_link($root)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

class Dev20ClosedStore
{
    public array $events = [];
    public function eventRow(array $anchor): ?array
    {
        $rowId = (string)($anchor['rowid'] ?? '');
        return $this->events[$rowId] ?? null;
    }
    public function eventMatches(array $event): bool
    {
        return ($this->events[(string)($event['rowid'] ?? '')] ?? null) === $event;
    }
}
$ownerReal = realpath($ownerRoot);
$adapterReal = realpath($adapterPath);
dev20ClosedExpect(is_string($ownerReal) && is_dir($ownerReal), 'Pass the immutable dev20 owner server source directory.');
dev20ClosedExpect(is_string($adapterReal) && is_file($adapterReal), 'The candidate Department adapter is unavailable.');
$manifest = json_decode((string)@file_get_contents($ownerReal . DIRECTORY_SEPARATOR . 'manifest.json'), true);
dev20ClosedExpect(is_array($manifest) && ($manifest['name'] ?? null) === 'correspondence' && ($manifest['version'] ?? null) === '0.1.0-dev.20', 'Owner root is not the exact dev20 source.');

require_once dirname(__DIR__, 2) . '/lib/protocol.php';
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-correspondence-dev20-close-' . bin2hex(random_bytes(8));
$coreRoot = $tempRoot . DIRECTORY_SEPARATOR . 'core';
$stagedOwnerRoot = $coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'correspondence';
if (!mkdir($stagedOwnerRoot, 0700, true)) throw new RuntimeException('Could not create the isolated dev20 source stage.');
$GLOBALS['DEV20_TEST_CORE_ROOT'] = $coreRoot;

try {
    require $adapterReal;
    $sourceFiles = qd_correspondence_source_files($ownerReal);
    dev20ClosedExpect(count($sourceFiles) === 13 && qd_correspondence_manifest_ready($ownerReal), 'The adapter did not pin the full exact dev20 owner closure.');
    foreach ($sourceFiles as $relative => $source) {
        $sourceReal = realpath($source);
        dev20ClosedExpect(is_string($sourceReal) && !is_link($source) && str_starts_with($sourceReal, $ownerReal . DIRECTORY_SEPARATOR), 'Owner closure path escaped its exact source root: ' . $relative);
        $target = $stagedOwnerRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $targetDirectory = dirname($target);
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0700, true)) throw new RuntimeException('Could not create a staged owner directory.');
        if (!copy($sourceReal, $target)) throw new RuntimeException('Could not stage owner file: ' . $relative);
    }
    $identity = qd_correspondence_current_source_identity($stagedOwnerRoot);
    dev20ClosedExpect(is_array($identity) && ($identity['version'] ?? null) === '0.1.0-dev.20', 'The staged owner identity is not exact dev20.');
    qd_correspondence_loaded_source_identity($identity);
    require_once $ownerReal . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'runtime.php';

    $documentId = '11111111-1111-4111-8111-111111111111';
    $requestId = '22222222-2222-4222-8222-222222222222';
    $operationId = '33333333-3333-4333-8333-333333333333';
    $receiptId = '44444444-4444-4444-8444-444444444444';
    $scopeToken = '55555555-5555-4555-8555-555555555555';
    $nonce = str_repeat('a', 64);
    $playthroughId = '1';
    $author = ['kind' => 'player', 'id' => null, 'name' => 'Dragonborn'];
    $payer = ['kind' => 'player', 'id' => null, 'name' => 'Dragonborn'];
    $recipient = ['kind' => 'npc', 'id' => 7, 'name' => 'Lydia'];
    $claimedSender = ['kind' => 'npc', 'id' => 8, 'name' => 'Alduin'];
    $attachments = [['kind' => 'item', 'form_id' => 'Skyrim.esm|0000002A', 'quantity' => 3]];
    $body = 'A sealed letter.';
    $title = 'letter ' . $documentId;
    $requestInput = [
        'request_id' => $requestId,
        'author' => qd_correspondence_actor_record_key($author),
        'payer' => qd_correspondence_actor_record_key($payer),
        'recipient' => qd_correspondence_actor_record_key($recipient),
        'claimed_sender' => qd_correspondence_actor_record_key($claimedSender),
        'body' => $body,
        'attachments' => correspondenceAttachments($attachments),
    ];
    $documentAnchor = [
        'rowid' => 11, 'ts' => 1700000000, 'gamets' => 1700000000, 'type' => 'info',
        'data' => correspondenceJson(['document_id' => $documentId, 'scope_token' => $scopeToken]),
        'sess' => 'correspondence_v1', 'localts' => 1700000000, 'people' => '',
    ];
    $document = [
        'document_id' => $documentId, 'playthrough_id' => $playthroughId, 'request_id' => $requestId,
        'request_hash' => hash('sha256', correspondenceCanonicalJson($requestInput)), 'body' => $body,
        'attachments' => $attachments, 'operation_id' => $operationId, 'author' => $author, 'payer' => $payer,
        'recipient' => $recipient, 'claimed_sender' => $claimedSender, 'title' => $title,
        'anchor' => $documentAnchor,
    ];
    $closeCarrier = [
        'rowid' => '12', 'ts' => 1700000001, 'gamets' => 1700000001, 'type' => 'correspondence_operator_closed',
        'data' => correspondenceJson(['document_id' => $documentId, 'operation_id' => $operationId, 'reason' => 'operator_closed']),
        'sess' => 'correspondence_v1', 'localts' => 1700000001, 'people' => '',
    ];
    $document['operator_close'] = $closeCarrier;

    $store = new Dev20ClosedStore();
    $store->events['11'] = $documentAnchor;
    dev20ClosedExpect(qd_correspondence_document_integrity($store, $document), 'Exact dev20 close carrier should remain valid when its event was pruned.');

    $invalidCloseCases = [];
    $invalid = $document;
    $invalid['operator_close']['data'] = correspondenceJson(['document_id' => $documentId, 'operation_id' => $requestId, 'reason' => 'operator_closed']);
    $invalidCloseCases['wrong operation identity'] = $invalid;
    $invalid = $document;
    $invalid['operator_close']['people'] = '|Lydia|';
    $invalidCloseCases['nonempty people'] = $invalid;
    $invalid = $document;
    $invalid['operator_close']['unexpected'] = true;
    $invalidCloseCases['extra carrier key'] = $invalid;
    $invalid = $document;
    $invalid['operator_close']['type'] = 'correspondence_receipt_confirmed';
    $invalidCloseCases['wrong carrier type'] = $invalid;
    foreach ($invalidCloseCases as $label => $candidate) {
        dev20ClosedExpect(!qd_correspondence_document_integrity($store, $candidate), 'Malformed close carrier was accepted: ' . $label);
    }
    dev20ClosedExpect(!qd_correspondence_version_supports_operator_close('0.1.0-dev.18')
        && !qd_correspondence_version_supports_operator_close('0.1.0-dev.19'), 'Operator close must remain unavailable outside exact dev20.');

    $actorResolver = static fn(array $actor): array => [
        'kind' => $actor['kind'], 'id' => $actor['id'], 'name' => $actor['name'],
    ];
    $physicalItemResolver = static fn(array $item): array => [
        'kind' => $item['kind'], 'form_id' => 42, 'plugin' => 'Skyrim.esm', 'local_id' => 42, 'quantity' => $item['quantity'],
    ];
    $physicalAttachments = [$physicalItemResolver($attachments[0])];
    $dispatchAnchor = [
        'rowid' => 13, 'ts' => 1700000002, 'gamets' => 1700000002, 'type' => 'info',
        'data' => correspondenceJson(['document_id' => $documentId, 'operation_id' => $operationId]),
        'sess' => 'correspondence_v1', 'localts' => 1700000002, 'people' => '',
    ];

    $makeOperation = static function (array $receipt) use (
        $store, $document, $dispatchAnchor, $documentId, $operationId, $receiptId, $scopeToken, $nonce,
        $playthroughId, $recipient, $payer, $title, $physicalAttachments,
    ): array {
        $store->events['13'] = $dispatchAnchor;
        $receiptAnchor = [
            'rowid' => 14, 'ts' => 1700000003, 'gamets' => 1700000003, 'type' => 'info',
            'data' => correspondenceJson([
                'document_id' => $documentId,
                'operation_id' => $operationId,
                'receipt_hash' => hash('sha256', correspondenceJson($receipt)),
            ]),
            'sess' => 'correspondence_v1', 'localts' => 1700000003, 'people' => '',
        ];
        $store->events['14'] = $receiptAnchor;
        return [
            'protocol_version' => 1, 'document_id' => $document['document_id'], 'operation_id' => $operationId,
            'receipt_id' => $receiptId, 'playthrough_id' => $playthroughId, 'scope_token' => $scopeToken,
            'nonce' => $nonce, 'recipient' => $recipient, 'payer' => $payer, 'title' => $title,
            'renderer_key' => md5(strtolower(trim($title))), 'attachments' => $physicalAttachments,
            'receipt' => $receipt, 'receipt_anchor' => $receiptAnchor, 'source' => null,
            'dispatch_anchor' => $dispatchAnchor,
        ];
    };
    $makeReceipt = static function (array $receiptAttachments) use ($documentId, $operationId, $receiptId, $playthroughId, $scopeToken, $nonce): array {
        return [
            'protocol_version' => 1, 'document_id' => $documentId, 'operation_id' => $operationId,
            'receipt_id' => $receiptId, 'playthrough_id' => $playthroughId, 'scope_token' => $scopeToken,
            'nonce' => $nonce, 'status' => 'unknown', 'reason' => 'transfer_uncertain', 'note_form_id' => 0,
            'attachments' => $receiptAttachments,
        ];
    };

    foreach ([
        'negative_unknown_delta' => ['form_id' => 42, 'quantity' => -2, 'before_source' => 10, 'after_source' => 10, 'before_recipient' => 5, 'after_recipient' => 3],
        'unknown_overdelivery' => ['form_id' => 42, 'quantity' => 5, 'before_source' => 10, 'after_source' => 10, 'before_recipient' => 2, 'after_recipient' => 7],
    ] as $label => $counter) {
        $receipt = $makeReceipt([$counter]);
        $operation = $makeOperation($receipt);
        $validated = qd_correspondence_validate_receipt($store, null, $document, $operation, $physicalItemResolver, $actorResolver);
        dev20ClosedExpect(($validated['confirmed'] ?? null) === false && ($validated['failed'] ?? null) === false
            && ($validated['partial'] ?? null) === true && ($validated['attachments'] ?? null) === [],
            'Signed unknown result lost partial/unconfirmed semantics: ' . $label);
    }
    $invalidDelta = $makeReceipt([['form_id' => 42, 'quantity' => -2, 'before_source' => 10, 'after_source' => 10, 'before_recipient' => 5, 'after_recipient' => 4]]);
    $invalidDeltaResult = qd_correspondence_validate_receipt($store, null, $document, $makeOperation($invalidDelta), $physicalItemResolver, $actorResolver);
    dev20ClosedExpect(($invalidDeltaResult['confirmed'] ?? null) === false && ($invalidDeltaResult['partial'] ?? null) === false,
        'Signed unknown with an unauthenticated recipient delta must remain unconfirmed.');

    fwrite(STDOUT, "PASS: dev20 operator-close proof, pruned-close retention, and signed-unknown partials\n");
} finally {
    unset($GLOBALS['DEV20_TEST_CORE_ROOT']);
    dev20ClosedCleanup($tempRoot);
}
