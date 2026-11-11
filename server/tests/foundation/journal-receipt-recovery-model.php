<?php
declare(strict_types=1);

$serverRoot = dirname(__DIR__, 2);
require_once $serverRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'protocol.php';
require_once $serverRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'auth.php';
require_once $serverRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'overlay_setup.php';

$GLOBALS['journalReceiptModelTarget'] = null;
$GLOBALS['journalReceiptModelWrites'] = 0;
$GLOBALS['journalReceiptModelFailAt'] = null;
function qd_journal_model_fsync_directory(string $directory): void
{
    $realDirectory = realpath($directory);
    if ($realDirectory === false || !is_dir($realDirectory) || is_link($directory)) {
        throw new QDServerFault('unavailable');
    }
    if ($realDirectory !== $GLOBALS['journalReceiptModelTarget']) return;
    $GLOBALS['journalReceiptModelWrites']++;
    if ($GLOBALS['journalReceiptModelFailAt'] === $GLOBALS['journalReceiptModelWrites']) {
        $GLOBALS['journalReceiptModelFailAt'] = null;
        throw new QDServerFault('unavailable');
    }
}

$journalSource = file_get_contents($serverRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'journal.php');
$dispatcherSource = file_get_contents($serverRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'dispatcher.php');
if (!is_string($journalSource) || !is_string($dispatcherSource)) throw new RuntimeException('Could not load the production journal/dispatcher sources.');
$journalSource = preg_replace('/\A<\?php\s*/', '', $journalSource);
$journalSource = preg_replace('/function qd_journal_fsync_directory\(/', 'function qd_journal_original_fsync_directory(', $journalSource, 1);
$journalSource = str_replace('qd_journal_fsync_directory(', 'qd_journal_model_fsync_directory(', $journalSource);
$dispatcherSource = preg_replace('/\A<\?php\s*/', '', $dispatcherSource);
$dispatcherSource = preg_replace('/^require_once .*;\R/m', '', $dispatcherSource);
if (!is_string($journalSource) || !is_string($dispatcherSource) || str_contains($journalSource, 'qd_journal_fsync_directory(')) {
    throw new RuntimeException('The modeled journal source boundary could not be isolated.');
}
eval($journalSource);
eval($dispatcherSource);

function receiptJournalModelExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function receiptJournalModelUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function receiptJournalModelCleanup(string $root): void
{
    if (!is_dir($root)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($root);
}

function receiptJournalModelFixture(): array
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-receipt-journal-model-' . bin2hex(random_bytes(8));
    $webRoot = $root . DIRECTORY_SEPARATOR . 'web';
    $privateRoot = $root . DIRECTORY_SEPARATOR . 'private';
    if (!mkdir($webRoot, 0700, true) || !mkdir($privateRoot, 0700, true)) throw new RuntimeException('Could not make a private model fixture.');
    $settings = ['statePath' => $privateRoot . DIRECTORY_SEPARATOR . 'state.json', 'webRoot' => $webRoot];
    $directory = qd_journal_directory($settings);
    $GLOBALS['journalReceiptModelTarget'] = realpath($directory);
    $GLOBALS['journalReceiptModelWrites'] = 0;
    $GLOBALS['journalReceiptModelFailAt'] = null;
    return [$root, $settings, $directory];
}

function receiptJournalModelReadRecord(string $directory, string $key): array
{
    $path = $directory . DIRECTORY_SEPARATOR . $key . '.json';
    $record = json_decode((string)file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($record)) throw new RuntimeException('The modeled journal record is missing or malformed.');
    return $record;
}

function receiptJournalModelReadPointer(string $directory, string $semanticKey): ?array
{
    $path = $directory . DIRECTORY_SEPARATOR . $semanticKey . '.pending.json';
    if (!is_file($path)) return null;
    $pointer = json_decode((string)file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($pointer)) throw new RuntimeException('The modeled semantic pointer is malformed.');
    return $pointer;
}

function receiptJournalModelBegin(array $settings, string $pairId, string $scopeHash, string $attemptId, string $semanticHash): array
{
    return qd_journal_begin($settings, $pairId, $scopeHash, 'correspondence.receipt.check', $attemptId,
        hash('sha256', 'payload-' . $attemptId), $semanticHash);
}

function receiptJournalModelFault(int $writeNumber, callable $work): void
{
    $GLOBALS['journalReceiptModelWrites'] = 0;
    $GLOBALS['journalReceiptModelFailAt'] = $writeNumber;
    $failed = false;
    try {
        $work();
    } catch (QDServerFault $error) {
        $failed = $error->wireCode === 'unavailable';
    } finally {
        $GLOBALS['journalReceiptModelFailAt'] = null;
    }
    receiptJournalModelExpect($failed, 'fault injection after journal transition write ' . $writeNumber . ' must fail closed');
}

$modelCases = 0;
for ($failureWrite = 1; $failureWrite <= 6; $failureWrite++) {
    $pairId = receiptJournalModelUuid();
    $scopeHash = hash('sha256', 'receipt-query-model-scope-' . $failureWrite);
    $semanticHash = hash('sha256', 'receipt-query-model-original-request-' . $failureWrite);
    $firstAttempt = receiptJournalModelUuid();
    $secondAttempt = receiptJournalModelUuid();
    [$root, $settings, $directory] = receiptJournalModelFixture();
    try {
        $first = receiptJournalModelBegin($settings, $pairId, $scopeHash, $firstAttempt, $semanticHash);
        receiptJournalModelExpect(($first['action'] ?? null) === 'dispatch', 'initial receipt check must reserve a journal attempt');

        receiptJournalModelFault($failureWrite, static function () use ($settings, $pairId, $scopeHash, $secondAttempt, $semanticHash): void {
            receiptJournalModelBegin($settings, $pairId, $scopeHash, $secondAttempt, $semanticHash);
        });

        $second = receiptJournalModelBegin($settings, $pairId, $scopeHash, $secondAttempt, $semanticHash);
        receiptJournalModelExpect(($second['action'] ?? null) === 'dispatch' && ($second['key'] ?? null) !== $first['key'],
            'fresh attempt recovers after modeled crash at transition write ' . $failureWrite);
        $pointer = receiptJournalModelReadPointer($directory, qd_journal_semantic_key($pairId, $scopeHash, 'correspondence.receipt.check', $semanticHash));
        receiptJournalModelExpect(($pointer['operationKey'] ?? null) === $second['key'], 'recovery leaves at most one pending pointer, owned by the current attempt');
        $firstRecord = receiptJournalModelReadRecord($directory, $first['key']);
        receiptJournalModelExpect(($firstRecord['state'] ?? null) === 'completed' && array_key_exists('dataJson', $firstRecord) && $firstRecord['dataJson'] === null,
            'superseded attempt is only a query-journal tombstone, never a fabricated result');

        $sameAttempt = receiptJournalModelBegin($settings, $pairId, $scopeHash, $secondAttempt, $semanticHash);
        receiptJournalModelExpect(($sameAttempt['action'] ?? null) === 'dispatch' && $sameAttempt['key'] === $second['key'],
            'same attempt retry resumes the sole current pending receipt query');
        $pointerBeforeOldAttempt = file_get_contents($directory . DIRECTORY_SEPARATOR
            . qd_journal_semantic_key($pairId, $scopeHash, 'correspondence.receipt.check', $semanticHash) . '.pending.json');
        try {
            receiptJournalModelBegin($settings, $pairId, $scopeHash, $firstAttempt, $semanticHash);
            receiptJournalModelExpect(false, 'a superseded old attempt must require a fresh attempt UUID');
        } catch (QDServerFault $error) {
            receiptJournalModelExpect($error->wireCode === 'conflict', 'a retired query attempt is not replayed as a letter receipt');
        }
        receiptJournalModelExpect(file_get_contents($directory . DIRECTORY_SEPARATOR
            . qd_journal_semantic_key($pairId, $scopeHash, 'correspondence.receipt.check', $semanticHash) . '.pending.json') === $pointerBeforeOldAttempt,
            'an old tombstone conflict cannot remove or rewrite the current attempt pointer');

        $badPayloadConflict = false;
        try {
            qd_journal_begin($settings, $pairId, $scopeHash, 'correspondence.receipt.check', $firstAttempt,
                hash('sha256', 'different-payload'), $semanticHash);
        } catch (QDServerFault $error) {
            $badPayloadConflict = $error->wireCode === 'conflict';
        }
        receiptJournalModelExpect($badPayloadConflict && file_get_contents($directory . DIRECTORY_SEPARATOR
            . qd_journal_semantic_key($pairId, $scopeHash, 'correspondence.receipt.check', $semanticHash) . '.pending.json') === $pointerBeforeOldAttempt,
            'payload mismatch conflicts before any attempt can supersede the pending query');

        $thirdAttempt = receiptJournalModelUuid();
        $third = receiptJournalModelBegin($settings, $pairId, $scopeHash, $thirdAttempt, $semanticHash);
        receiptJournalModelExpect(($third['action'] ?? null) === 'dispatch' && $third['key'] !== $second['key'],
            'a repeated view reload can supersede the previous interrupted query with one fresh attempt');
        $secondRecord = receiptJournalModelReadRecord($directory, $second['key']);
        receiptJournalModelExpect(($secondRecord['state'] ?? null) === 'completed' && array_key_exists('dataJson', $secondRecord) && $secondRecord['dataJson'] === null,
            'each superseded attempt remains only a query-bookkeeping tombstone');
        $thirdPointer = file_get_contents($directory . DIRECTORY_SEPARATOR
            . qd_journal_semantic_key($pairId, $scopeHash, 'correspondence.receipt.check', $semanticHash) . '.pending.json');
        $secondAttemptRefused = false;
        try {
            receiptJournalModelBegin($settings, $pairId, $scopeHash, $secondAttempt, $semanticHash);
        } catch (QDServerFault $error) {
            $secondAttemptRefused = $error->wireCode === 'conflict';
        }
        receiptJournalModelExpect($secondAttemptRefused && file_get_contents($directory . DIRECTORY_SEPARATOR
            . qd_journal_semantic_key($pairId, $scopeHash, 'correspondence.receipt.check', $semanticHash) . '.pending.json') === $thirdPointer,
            'an old tombstoned attempt requires a fresh UUID and cannot replace the latest pointer');

        qd_journal_mark_commit($settings, $third['key']);
        $actualResult = json_encode((object)['operationId' => $pairId, 'queued' => false, 'reason' => 'executor_unavailable'], JSON_THROW_ON_ERROR);
        qd_journal_complete($settings, $third['key'], $actualResult);
        $replay = receiptJournalModelBegin($settings, $pairId, $scopeHash, $thirdAttempt, $semanticHash);
        receiptJournalModelExpect(($replay['action'] ?? null) === 'replay'
            && ($replay['data']->reason ?? null) === 'executor_unavailable', 'completed same-attempt refusal replays its actual fixed result');
        receiptJournalModelExpect(receiptJournalModelReadPointer($directory,
            qd_journal_semantic_key($pairId, $scopeHash, 'correspondence.receipt.check', $semanticHash)) === null,
            'completed receipt query releases its semantic pointer');
        $fourthAttempt = receiptJournalModelUuid();
        $fourth = receiptJournalModelBegin($settings, $pairId, $scopeHash, $fourthAttempt, $semanticHash);
        receiptJournalModelExpect(($fourth['action'] ?? null) === 'dispatch', 'a fresh view attempt may query again after the previous result is complete');
        qd_journal_abandon_no_write($settings, $fourth['key']);
        $modelCases++;
    } finally {
        receiptJournalModelCleanup($root);
    }
}

for ($failureWrite = 1; $failureWrite <= 3; $failureWrite++) {
    $pairId = receiptJournalModelUuid();
    $scopeHash = hash('sha256', 'receipt-complete-model-scope-' . $failureWrite);
    $semanticHash = hash('sha256', 'receipt-complete-model-semantic-' . $failureWrite);
    $attemptId = receiptJournalModelUuid();
    [$root, $settings, $directory] = receiptJournalModelFixture();
    try {
        $begin = receiptJournalModelBegin($settings, $pairId, $scopeHash, $attemptId, $semanticHash);
        qd_journal_mark_commit($settings, $begin['key']);
        $actualResult = json_encode((object)['operationId' => $pairId, 'queued' => true, 'reason' => null], JSON_THROW_ON_ERROR);
        receiptJournalModelFault($failureWrite, static function () use ($settings, $begin, $actualResult): void {
            qd_journal_complete($settings, $begin['key'], $actualResult);
        });
        if ($failureWrite === 1) {
            $sameAttempt = receiptJournalModelBegin($settings, $pairId, $scopeHash, $attemptId, $semanticHash);
            receiptJournalModelExpect(($sameAttempt['action'] ?? null) === 'dispatch', 'crash after recent-result write leaves same check retryable');
            qd_journal_mark_commit($settings, $sameAttempt['key']);
            qd_journal_complete($settings, $sameAttempt['key'], $actualResult);
        } else {
            $replay = receiptJournalModelBegin($settings, $pairId, $scopeHash, $attemptId, $semanticHash);
            receiptJournalModelExpect(($replay['action'] ?? null) === 'replay' && ($replay['data']->queued ?? null) === true,
                'crash after terminal record write or pointer deletion replays only the actual query result');
        }
        $modelCases++;
    } finally {
        receiptJournalModelCleanup($root);
    }
}

for ($failureWrite = 1; $failureWrite <= 2; $failureWrite++) {
    $pairId = receiptJournalModelUuid();
    $scopeHash = hash('sha256', 'receipt-mark-model-scope-' . $failureWrite);
    $semanticHash = hash('sha256', 'receipt-mark-model-semantic-' . $failureWrite);
    $attemptId = receiptJournalModelUuid();
    [$root, $settings] = receiptJournalModelFixture();
    try {
        $begin = receiptJournalModelBegin($settings, $pairId, $scopeHash, $attemptId, $semanticHash);
        receiptJournalModelFault($failureWrite, static function () use ($settings, $begin): void {
            qd_journal_mark_commit($settings, $begin['key']);
        });
        $sameAttempt = receiptJournalModelBegin($settings, $pairId, $scopeHash, $attemptId, $semanticHash);
        receiptJournalModelExpect(($sameAttempt['action'] ?? null) === 'dispatch', 'same attempt recovers after each commit marker write');
        qd_journal_mark_commit($settings, $sameAttempt['key']);
        $modelCases++;
    } finally {
        receiptJournalModelCleanup($root);
    }
}

$pairId = receiptJournalModelUuid();
$scopeHash = hash('sha256', 'receipt-concurrent-model-scope');
$semanticHash = hash('sha256', 'receipt-concurrent-model-semantic');
$firstAttempt = receiptJournalModelUuid();
$secondAttempt = receiptJournalModelUuid();
[$root, $settings, $directory] = receiptJournalModelFixture();
try {
    $first = receiptJournalModelBegin($settings, $pairId, $scopeHash, $firstAttempt, $semanticHash);
    qd_journal_mark_commit($settings, $first['key']);
    $second = receiptJournalModelBegin($settings, $pairId, $scopeHash, $secondAttempt, $semanticHash);
    $pointerPath = $directory . DIRECTORY_SEPARATOR . qd_journal_semantic_key($pairId, $scopeHash, 'correspondence.receipt.check', $semanticHash) . '.pending.json';
    $pointerBeforeOldCompletion = file_get_contents($pointerPath);
    $oldCompletionRefused = false;
    try {
        qd_journal_complete($settings, $first['key'], json_encode((object)['operationId' => $pairId, 'queued' => true, 'reason' => null], JSON_THROW_ON_ERROR));
    } catch (QDServerFault $error) {
        $oldCompletionRefused = $error->wireCode === 'unavailable';
    }
    receiptJournalModelExpect($oldCompletionRefused && file_get_contents($pointerPath) === $pointerBeforeOldCompletion,
        'concurrent old callback cannot overwrite the current attempt or produce a success response');
    qd_journal_mark_commit($settings, $second['key']);
    qd_journal_complete($settings, $second['key'], json_encode((object)['operationId' => $pairId, 'queued' => true, 'reason' => null], JSON_THROW_ON_ERROR));
    $modelCases++;
} finally {
    receiptJournalModelCleanup($root);
}

$pairId = receiptJournalModelUuid();
$scopeHash = hash('sha256', 'nonreceipt-control-scope');
$semanticHash = hash('sha256', 'nonreceipt-control-semantic');
[$root, $settings, $directory] = receiptJournalModelFixture();
try {
    $first = qd_journal_begin($settings, $pairId, $scopeHash, 'correspondence.compose', receiptJournalModelUuid(), hash('sha256', 'compose-payload'), $semanticHash);
    $pointerPath = $directory . DIRECTORY_SEPARATOR . qd_journal_semantic_key($pairId, $scopeHash, 'correspondence.compose', $semanticHash) . '.pending.json';
    $pointerBefore = file_get_contents($pointerPath);
    $refused = false;
    try {
        qd_journal_begin($settings, $pairId, $scopeHash, 'correspondence.compose', receiptJournalModelUuid(), hash('sha256', 'second-compose-payload'), $semanticHash);
    } catch (QDServerFault $error) {
        $refused = $error->wireCode === 'conflict';
    }
    receiptJournalModelExpect($refused && file_get_contents($pointerPath) === $pointerBefore,
        'unresolved compose keeps its original pending reservation and does not use receipt-only recovery');
    $modelCases++;
} finally {
    receiptJournalModelCleanup($root);
}

$pairId = receiptJournalModelUuid();
$scopeHash = hash('sha256', 'receipt-payload-control-scope');
$semanticHash = hash('sha256', 'receipt-payload-control-semantic');
$attemptId = receiptJournalModelUuid();
[$root, $settings, $directory] = receiptJournalModelFixture();
try {
    $pending = receiptJournalModelBegin($settings, $pairId, $scopeHash, $attemptId, $semanticHash);
    $semanticKey = qd_journal_semantic_key($pairId, $scopeHash, 'correspondence.receipt.check', $semanticHash);
    $pointerPath = $directory . DIRECTORY_SEPARATOR . $semanticKey . '.pending.json';
    $pointerBefore = file_get_contents($pointerPath);
    $mismatchRefused = false;
    try {
        qd_journal_begin($settings, $pairId, $scopeHash, 'correspondence.receipt.check', $attemptId,
            hash('sha256', 'changed-payload'), $semanticHash);
    } catch (QDServerFault $error) {
        $mismatchRefused = $error->wireCode === 'conflict';
    }
    receiptJournalModelExpect($mismatchRefused && file_get_contents($pointerPath) === $pointerBefore,
        'same attempt payload mismatch conflicts before any retirement write');
    $otherScope = hash('sha256', 'receipt-payload-control-other-scope');
    $otherScopeAttempt = receiptJournalModelUuid();
    $otherScopePending = receiptJournalModelBegin($settings, $pairId, $otherScope, $otherScopeAttempt, $semanticHash);
    receiptJournalModelExpect(($otherScopePending['action'] ?? null) === 'dispatch'
        && file_get_contents($pointerPath) === $pointerBefore,
        'a different scope has an independent journal slot and cannot retire the current query');
    $corruptedRecord = receiptJournalModelReadRecord($directory, $pending['key']);
    $corruptedRecord['semanticHash'] = hash('sha256', 'corrupted-semantic');
    file_put_contents($directory . DIRECTORY_SEPARATOR . $pending['key'] . '.json', json_encode($corruptedRecord, JSON_THROW_ON_ERROR));
    $corruptionRefused = false;
    try {
        receiptJournalModelBegin($settings, $pairId, $scopeHash, receiptJournalModelUuid(), $semanticHash);
    } catch (QDServerFault $error) {
        $corruptionRefused = $error->wireCode === 'unavailable';
    }
    receiptJournalModelExpect($corruptionRefused, 'pointer target semantic corruption fails closed before recovery');
    $modelCases++;
} finally {
    receiptJournalModelCleanup($root);
}

fwrite(STDOUT, 'PASS: ' . $modelCases . " receipt-check journal crash/reload cases execute production begin/mark/complete/abandon code (MODELED directory fsync boundary)\n");
