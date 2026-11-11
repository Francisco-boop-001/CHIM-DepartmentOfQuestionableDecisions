<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'dispatcher.php';

function receiptJournalExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pairId = '11111111-1111-4111-8111-111111111111';
$operationId = '22222222-2222-4222-8222-222222222222';
$scopeHash = hash('sha256', 'receipt-journal-synthetic-scope');
$payloadHash = hash('sha256', 'receipt-journal-synthetic-payload');
$semanticHash = hash('sha256', 'receipt-journal-synthetic-original-operation');
$key = hash('sha256', implode("\0", [$pairId, $scopeHash, 'correspondence.receipt.check', $operationId]));
$semanticKey = qd_journal_semantic_key($pairId, $scopeHash, 'correspondence.receipt.check', $semanticHash);
$pending = [
    'version' => 1,
    'pairId' => $pairId,
    'scopeHash' => $scopeHash,
    'operation' => 'correspondence.receipt.check',
    'operationId' => $operationId,
    'payloadHash' => $payloadHash,
    'semanticHash' => $semanticHash,
    'state' => 'pending',
    'commitEntered' => false,
    'dataJson' => null,
];

receiptJournalExpect(qd_journal_receipt_check_can_resume(
    ['version' => 2, 'operationKey' => $key, 'commitEntered' => false],
    $pending,
    $key,
    $pairId,
    $scopeHash,
    $semanticHash,
), 'same-attempt retry can resume after pointer reservation and before commit entry');

$pointerWrittenBeforeRecordMarker = $pending;
$pointerWrittenBeforeRecordMarker['commitEntered'] = false;
receiptJournalExpect(qd_journal_receipt_check_can_resume(
    ['version' => 2, 'operationKey' => $key, 'commitEntered' => true],
    $pointerWrittenBeforeRecordMarker,
    $key,
    $pairId,
    $scopeHash,
    $semanticHash,
), 'same attempt can recover after the pointer commit marker was written before the record marker');

$recordMarkerWritten = $pending;
$recordMarkerWritten['commitEntered'] = true;
receiptJournalExpect(qd_journal_receipt_check_can_resume(
    ['version' => 2, 'operationKey' => $key, 'commitEntered' => true],
    $recordMarkerWritten,
    $key,
    $pairId,
    $scopeHash,
    $semanticHash,
), 'same attempt can recover after both commit markers were written');

foreach ([
    ['pairId' => '33333333-3333-4333-8333-333333333333'],
    ['scopeHash' => hash('sha256', 'other-scope')],
    ['operation' => 'correspondence.compose'],
    ['semanticHash' => hash('sha256', 'other-operation')],
] as $change) {
    $mismatch = array_replace($pending, $change);
    receiptJournalExpect(!qd_journal_receipt_check_can_resume(
        ['version' => 2, 'operationKey' => $key, 'commitEntered' => false],
        $mismatch,
        $key,
        $pairId,
        $scopeHash,
        $semanticHash,
    ), 'receipt-check recovery rejects pair, scope, operation, or semantic identity drift');
}

$retired = qd_journal_receipt_check_retired_record($recordMarkerWritten);
receiptJournalExpect(($retired['state'] ?? null) === 'completed' && ($retired['commitEntered'] ?? null) === true
    && array_key_exists('dataJson', $retired) && $retired['dataJson'] === null,
    'retiring an interrupted check leaves only a query-journal tombstone and invents no owner result');
receiptJournalExpect(!qd_journal_receipt_check_can_resume(
    ['version' => 2, 'operationKey' => $key, 'commitEntered' => true],
    $retired,
    $key,
    $pairId,
    $scopeHash,
    $semanticHash,
), 'a retired query tombstone cannot be mistaken for a pending callback or letter receipt');

$wrongPointerRejected = false;
try {
    qd_journal_receipt_check_can_resume(
        ['version' => 2, 'operationKey' => str_repeat('a', 64), 'commitEntered' => false],
        $pending,
        $key,
        $pairId,
        $scopeHash,
        $semanticHash,
    );
} catch (QDServerFault $error) {
    $wrongPointerRejected = $error->wireCode === 'unavailable';
}
receiptJournalExpect($wrongPointerRejected, 'a pointer that names a different attempt fails closed');

$completed = $recordMarkerWritten;
$completed['state'] = 'completed';
$completed['dataJson'] = json_encode((object)['operationId' => $operationId, 'queued' => false, 'reason' => 'executor_unavailable'], JSON_THROW_ON_ERROR);
receiptJournalExpect(!qd_journal_receipt_check_can_resume(
    ['version' => 2, 'operationKey' => $key, 'commitEntered' => true],
    $completed,
    $key,
    $pairId,
    $scopeHash,
    $semanticHash,
), 'a completed real refusal result remains replay-only');

fwrite(STDOUT, "PASS: receipt-check pending recovery identity and query-only tombstone policy (MODELED; no fsync claim)\n");
