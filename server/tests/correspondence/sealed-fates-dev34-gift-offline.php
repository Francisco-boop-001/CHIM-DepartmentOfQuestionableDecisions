<?php
declare(strict_types=1);

$options = getopt('', ['owner-root:', 'vectors:']);
$ownerRoot = is_string($options['owner-root'] ?? null) ? realpath($options['owner-root']) : false;
$vectorsPath = is_string($options['vectors'] ?? null) ? realpath($options['vectors']) : false;
if (!is_string($ownerRoot) || !is_dir($ownerRoot) || !is_string($vectorsPath) || !is_file($vectorsPath)) {
    throw new RuntimeException('Pass --owner-root and --vectors for the exact owner source and serializer output.');
}
if (function_exists('pg_connect') || function_exists('curl_init')) throw new RuntimeException('Run PHP -n with database and network extensions absent.');

$departmentRoot = dirname(__DIR__, 3);
$GLOBALS['sealedFatesDev34CoreRoot'] = dirname(dirname($ownerRoot));
require_once $departmentRoot . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'protocol.php';
require_once $departmentRoot . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'correspondence.php';

function qd_runtime_core_root(): string
{
    return (string)$GLOBALS['sealedFatesDev34CoreRoot'];
}

function sealedFatesDev34Expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function sealedFatesDev34PhysicalItemResolver(array $document, array $operation): Closure
{
    $ownerAttachments = correspondenceAttachments($document['attachments'] ?? null);
    $operationAttachments = $operation['attachments'] ?? [];
    $index = 0;
    return static function (array $item) use ($ownerAttachments, $operationAttachments, &$index): array {
        $current = $index++;
        return ($ownerAttachments[$current] ?? null) === $item && is_array($operationAttachments[$current] ?? null)
            ? $operationAttachments[$current] : [];
    };
}

final class SealedFatesDev34ReadStore
{
    private array $events = [];

    public function __construct(array $document, array $operation)
    {
        foreach ([$document['anchor'] ?? null, $operation['dispatch_anchor'] ?? null, $operation['receipt_anchor'] ?? null] as $event) {
            if (is_array($event)) $this->events[(string)($event['rowid'] ?? '')] = $event;
        }
    }

    public function eventRow(array $anchor): ?array
    {
        return $this->events[(string)($anchor['rowid'] ?? '')] ?? null;
    }

    public function eventMatches(array $event): bool
    {
        return $this->eventRow($event) === $event;
    }

    public function companionDecisionRows(string $documentId): array
    {
        return [];
    }
}

function sealedFatesDev34Read(array $document, array $operation): array
{
    $store = new SealedFatesDev34ReadStore($document, $operation);
    $integrity = qd_correspondence_document_integrity($store, $document, []);
    $physicalActorResolver = static fn(array $actor): array => ($actor['kind'] ?? null) === 'player'
        ? $operation['payer'] : $operation['recipient'];
    $receipt = qd_correspondence_validate_receipt(
        $store,
        null,
        $document,
        $operation,
        sealedFatesDev34PhysicalItemResolver($document, $operation),
        $physicalActorResolver,
        [],
        $integrity,
    );
    return [$integrity, $receipt];
}

$vectorBytes = file_get_contents($vectorsPath);
if (!is_string($vectorBytes)) throw new RuntimeException('Could not read the owner serializer vectors.');
$vectors = json_decode($vectorBytes, true, 32, JSON_THROW_ON_ERROR);
if (!is_array($vectors) || !is_array($vectors['legacy'] ?? null) || !is_array($vectors['gift'] ?? null)
    || !is_array($vectors['forged_declaration'] ?? null)) throw new RuntimeException('The owner serializer vector set is incomplete.');

sealedFatesDev34Expect(qd_correspondence_manifest_ready($ownerRoot)
    && qd_correspondence_loaded_source_version() === '0.1.0-dev.34', 'The pinned dev34 source closure did not load.');
foreach ([
    qd_correspondence_version_uses_restore_fence('0.1.0-dev.34'),
    qd_correspondence_version_supports_signed_unknown('0.1.0-dev.34'),
    qd_correspondence_version_supports_operator_close('0.1.0-dev.34'),
    qd_correspondence_version_supports_companion_scheduler('0.1.0-dev.34'),
    qd_correspondence_version_supports_pending_resume('0.1.0-dev.34'),
    qd_correspondence_version_supports_receipt_check('0.1.0-dev.34'),
] as $capability) sealedFatesDev34Expect($capability, 'A previously pinned dev34 capability gate was lost.');

$gift = $vectors['gift'];
$giftDocument = $gift['document'] ?? null;
$giftOperation = $gift['operation'] ?? null;
if (!is_array($giftDocument) || !is_array($giftOperation) || ($giftDocument['gift'] ?? null) !== true
    || !is_string($giftOperation['gift_proof'] ?? null)) throw new RuntimeException('The authentic owner gift vector is malformed.');
[$giftIntegrity, $giftReceipt] = sealedFatesDev34Read($giftDocument, $giftOperation);
sealedFatesDev34Expect($giftIntegrity && ($giftReceipt['confirmed'] ?? false) === true,
    'The pinned owner gift document and anchored delivery receipt were not accepted.');
$limited = false;
$projection = qd_correspondence_mailbox_projection($giftDocument, $giftReceipt, $limited);
sealedFatesDev34Expect(!array_key_exists('gift', $projection) && !array_key_exists('gift_proof', $projection)
    && !array_key_exists('giftProof', $projection), 'Gift metadata or private proof leaked into the mailbox projection.');

$legacy = $vectors['legacy'];
[$legacyIntegrity, $legacyReceipt] = sealedFatesDev34Read($legacy['document'], $legacy['operation']);
sealedFatesDev34Expect($legacyIntegrity && ($legacyReceipt['confirmed'] ?? false) === true,
    'The prior attached-letter owner vector no longer reads as delivered.');

$withoutProof = $giftOperation;
unset($withoutProof['gift_proof']);
[, $withoutProofReceipt] = sealedFatesDev34Read($giftDocument, $withoutProof);
sealedFatesDev34Expect(($withoutProofReceipt['confirmed'] ?? false) === true, 'An absent optional gift proof blocked delivery readback.');
$nullProof = $giftOperation;
$nullProof['gift_proof'] = null;
[, $nullProofReceipt] = sealedFatesDev34Read($giftDocument, $nullProof);
sealedFatesDev34Expect(($nullProofReceipt['confirmed'] ?? false) === true, 'The owner’s permanent null non-credit marker blocked delivery readback.');

foreach ([true, strtoupper($giftOperation['gift_proof'])] as $invalidProof) {
    $invalidOperation = $giftOperation;
    $invalidOperation['gift_proof'] = $invalidProof;
    [, $invalidReceipt] = sealedFatesDev34Read($giftDocument, $invalidOperation);
    sealedFatesDev34Expect(($invalidReceipt['confirmed'] ?? false) === false, 'A malformed private gift proof was accepted.');
}
$nongiftProof = $legacy['operation'];
$nongiftProof['gift_proof'] = $giftOperation['gift_proof'];
[, $nongiftReceipt] = sealedFatesDev34Read($legacy['document'], $nongiftProof);
sealedFatesDev34Expect(($nongiftReceipt['confirmed'] ?? false) === false, 'A gift proof on a document without a gift declaration was accepted.');

$tamperedReceipt = $giftOperation;
$tamperedReceipt['receipt']['note_form_id'] = 0;
[, $tamperedRead] = sealedFatesDev34Read($giftDocument, $tamperedReceipt);
sealedFatesDev34Expect(($tamperedRead['confirmed'] ?? false) === false, 'A valid gift proof authorized a receipt with a broken anchor and note.');

$repairedOperation = $giftOperation;
$repairedOperation['receipt']['note_form_id']++;
$wireReceipt = qd_correspondence_bridge_receipt_wire_order($repairedOperation['receipt']);
if (!is_array($wireReceipt)) throw new RuntimeException('Could not build the repaired owner receipt wire shape.');
$repairedHash = hash('sha256', correspondenceJson($wireReceipt));
$repairedOperation['receipt_anchor']['rowid'] = '99';
$repairedOperation['receipt_anchor']['data'] = correspondenceJson([
    'document_id' => $repairedOperation['document_id'],
    'operation_id' => $repairedOperation['operation_id'],
    'receipt_hash' => $repairedHash,
]);
$repairedStore = new SealedFatesDev34ReadStore($giftDocument, $repairedOperation);
$repairedIntegrity = qd_correspondence_document_integrity($repairedStore, $giftDocument, []);
$repairedReceipt = qd_correspondence_validate_receipt(
    $repairedStore,
    null,
    $giftDocument,
    $repairedOperation,
    sealedFatesDev34PhysicalItemResolver($giftDocument, $repairedOperation),
    static fn(array $actor): array => ($actor['kind'] ?? null) === 'player' ? $repairedOperation['payer'] : $repairedOperation['recipient'],
    [],
    $repairedIntegrity,
);
sealedFatesDev34Expect($repairedIntegrity && ($repairedReceipt['confirmed'] ?? false) === true
    && !hash_equals($giftOperation['gift_proof'], $repairedHash),
    'The old immutable gift proof incorrectly had to match the owner’s repaired current receipt.');

$forged = $vectors['forged_declaration'];
sealedFatesDev34Expect(($forged['reason'] ?? null) === 'invalid_gift_declaration', 'The owner did not refuse the forged gift declaration vector.');
$forgedDocument = $giftDocument;
$forgedDocument['request_id'] = $forged['request']['request_id'];
$forgedDocument['body'] = $forged['request']['body'];
$forgedDocument['claimed_sender'] = $legacy['document']['claimed_sender'];
$forgedDocument['request_hash'] = $forged['request_hash'];
$forgedStore = new SealedFatesDev34ReadStore($forgedDocument, $giftOperation);
sealedFatesDev34Expect(!qd_correspondence_document_integrity($forgedStore, $forgedDocument, []),
    'A player-declared gift with an NPC claimed sender passed the owner identity checks.');

$mismatchedPlayer = $giftDocument;
$mismatchedPlayer['payer']['name'] = 'Different player snapshot';
$mismatchedStore = new SealedFatesDev34ReadStore($mismatchedPlayer, $giftOperation);
sealedFatesDev34Expect(!qd_correspondence_document_integrity($mismatchedStore, $mismatchedPlayer, []),
    'A player gift with equal actor keys but different payer snapshots passed.');

$nonNpcRecipient = $giftDocument;
$nonNpcRecipient['recipient'] = $giftDocument['author'];
$nonNpcRequest = $gift['request'];
$nonNpcRequest['recipient'] = 'player';
$nonNpcRecipient['request_hash'] = hash('sha256', correspondenceCanonicalJson($nonNpcRequest));
$nonNpcStore = new SealedFatesDev34ReadStore($nonNpcRecipient, $giftOperation);
sealedFatesDev34Expect(!qd_correspondence_document_integrity($nonNpcStore, $nonNpcRecipient, []),
    'A player gift addressed to the player passed the owner recipient eligibility check.');

$emptyGift = $giftDocument;
$emptyGift['attachments'] = [];
$emptyRequest = $gift['request'];
$emptyRequest['attachments'] = [];
$emptyGift['request_hash'] = hash('sha256', correspondenceCanonicalJson($emptyRequest));
$emptyStore = new SealedFatesDev34ReadStore($emptyGift, $giftOperation);
sealedFatesDev34Expect(!qd_correspondence_document_integrity($emptyStore, $emptyGift, []),
    'A player gift without attachments passed the owner eligibility checks.');

$falseGift = $giftDocument;
$falseGift['gift'] = false;
$falseRequest = $gift['request'];
$falseRequest['gift'] = false;
$falseGift['request_hash'] = hash('sha256', correspondenceCanonicalJson($falseRequest));
$falseStore = new SealedFatesDev34ReadStore($falseGift, $giftOperation);
sealedFatesDev34Expect(!qd_correspondence_document_integrity($falseStore, $falseGift, []),
    'A false gift declaration passed the owner eligibility check.');

fwrite(STDOUT, "PASS: pinned dev34 closure, owner serializer gift and legacy receipts, proof shape, and read-only projection\n");
