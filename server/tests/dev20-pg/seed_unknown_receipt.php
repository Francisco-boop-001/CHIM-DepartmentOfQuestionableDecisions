<?php
declare(strict_types=1);

/**
 * Fixture-only owner receipt seeder. The measured projection is exercised through
 * api_driver.php; this helper uses the pinned owner service only to create its input.
 */
function qd_dev20_seed_fail(string $code): never
{
    fwrite(STDERR, 'dev20-receipt-seed-failure:' . $code . PHP_EOL);
    exit(2);
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'fixture_guard.php';
try {
    qd_dev20_assert_private_fixture();
} catch (Throwable) {
    qd_dev20_seed_fail('private_fixture_guard_refused');
}

$options = getopt('', ['scenario:', 'operation-id:']);
$scenario = is_array($options) ? ($options['scenario'] ?? null) : null;
$operationId = is_array($options) ? ($options['operation-id'] ?? null) : null;
if (!in_array($scenario, ['negative', 'overdelivery', 'partial', 'delivered'], true)
    || !is_string($operationId)
    || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $operationId) !== 1) {
    qd_dev20_seed_fail('arguments_invalid');
}

$coreRoot = getenv('QD_CHIM_ROOT');
$serverRoot = dirname(__DIR__, 2);
if (!is_string($coreRoot) || $coreRoot === '' || !is_dir($coreRoot)
    || !is_file($serverRoot . DIRECTORY_SEPARATOR . 'api.php')) {
    qd_dev20_seed_fail('private_fixture_source_missing');
}

try {
    require_once $serverRoot . DIRECTORY_SEPARATOR . 'api.php';
    $dependencies = qd_api_runtime_dependencies();
    $withProfileLease = $dependencies['withProfileLease'] ?? null;
    if (!is_callable($withProfileLease)) throw new RuntimeException('profile_lease_unavailable');
    $ownerRoot = qd_correspondence_source_root();

    $result = $withProfileLease(static function () use ($ownerRoot, $operationId, $scenario): array {
        return qd_runtime_with_correspondence_lease($ownerRoot,
            static function (string $generation, mixed $lease, string $canonicalCoreRoot, string $canonicalOwnerRoot) use ($operationId, $scenario): array {
                return qd_runtime_with_correspondence_owner_lease($canonicalOwnerRoot, $generation, $lease,
                    static function () use ($canonicalCoreRoot, $operationId, $scenario): array {
                        qd_correspondence_load_sources();
                        correspondenceBeginRequestIngress([]);
                        return qd_runtime_with_owned_connection(
                            static fn() => correspondenceNativeConnection(),
                            static function ($connection): void { @pg_close($connection); },
                            static function ($connection) use ($canonicalCoreRoot, $operationId, $scenario): array {
                                $store = new CorrespondencePgStore(
                                    $connection,
                                    static fn($candidate): ?string => $candidate->observedExecutor(),
                                    static fn(string $title, string $body): bool => correspondencePrepareCoreNote($canonicalCoreRoot, $title, $body),
                                    $canonicalCoreRoot . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'playthrough_runtime' . DIRECTORY_SEPARATOR . 'correspondence.restore.json',
                                );
                                $store->ensureSchema();
                                correspondenceCaptureRequestIngress($store);
                                require_once $canonicalCoreRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_home.php';
                                $active = qd_runtime_active_playthrough($connection);
                                $document = $store->findRequest((string)$active['profileId'], $operationId);
                                if (!is_array($document) || !is_string($document['operation_id'] ?? null)) {
                                    throw new RuntimeException('composed_document_unavailable');
                                }
                                $operation = $store->loadOperation($document['operation_id']);
                                if (!is_array($operation) || !is_array($operation['attachments'] ?? null)
                                    || count($operation['attachments']) !== 1 || !is_int($operation['attachments'][0]['form_id'] ?? null)) {
                                    throw new RuntimeException('composed_operation_unavailable');
                                }
                                $clock = $store->query('SELECT ts::text AS ts,gamets::text AS gamets FROM public.eventlog ORDER BY rowid DESC LIMIT 1');
                                if (count($clock) !== 1 || !is_string($clock[0]['ts'] ?? null) || !is_string($clock[0]['gamets'] ?? null)) {
                                    throw new RuntimeException('event_clock_unavailable');
                                }
                                $store->bindRequestClock([1 => $clock[0]['ts'], 2 => $clock[0]['gamets']]);
                                $negative = $scenario === 'negative';
                                $partial = $scenario === 'partial';
                                $delivered = $scenario === 'delivered';
                                $beforeRecipient = $negative ? 1 : 0;
                                $afterRecipient = $negative ? 0 : ($delivered ? 2 : ($partial ? 1 : 6));
                                $quantity = $negative ? -1 : ($delivered ? 2 : ($partial ? 1 : 6));
                                $beforeSource = $delivered ? 2 : 2;
                                $afterSource = $delivered ? 0 : 2;
                                $receipt = [
                                    'protocol_version' => 1,
                                    'document_id' => $operation['document_id'],
                                    'operation_id' => $operation['operation_id'],
                                    'receipt_id' => $operation['receipt_id'],
                                    'playthrough_id' => $operation['playthrough_id'],
                                    'scope_token' => $operation['scope_token'],
                                    'nonce' => $operation['nonce'],
                                    'status' => $delivered ? 'delivered' : 'unknown',
                                    'reason' => $delivered ? 'delivered' : 'dispatch_unknown',
                                    'note_form_id' => $delivered ? hexdec('000A2C94') : 0,
                                    'attachments' => [[
                                        'form_id' => $operation['attachments'][0]['form_id'],
                                        'quantity' => $quantity,
                                        'before_source' => $beforeSource,
                                        'after_source' => $afterSource,
                                        'before_recipient' => $beforeRecipient,
                                        'after_recipient' => $afterRecipient,
                                    ]],
                                ];
                                $service = new CorrespondenceService($store);
                                $accepted = $service->receive($receipt);
                                return [
                                    'accepted' => is_array($accepted) && ($accepted['state'] ?? null) === ($delivered ? 'delivered' : 'unknown'),
                                    'scenario' => $scenario,
                                    'status' => $receipt['status'],
                                    'signedTransferDelta' => $quantity,
                                    'requestedQuantity' => $operation['attachments'][0]['quantity'] ?? null,
                                ];
                            },
                        );
                    },
                );
            });
    });
    if (!is_array($result) || ($result['accepted'] ?? null) !== true) qd_dev20_seed_fail('owner_receipt_rejected');
    fwrite(STDOUT, json_encode(['schema' => 'department.dev20.unknown-receipt-fixture.v1'] + $result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $error) {
    qd_dev20_seed_fail('runtime_' . strtolower((new ReflectionClass($error))->getShortName()));
}
