<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/protocol.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/dispatcher.php';
require_once __DIR__ . '/../../runtime.php';

$failures = [];
$checks = 0;
$expect = static function (bool $condition, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) $failures[] = $label;
};

$actorKey = 'npc:42';
$uuid = '123e4567-e89b-12d3-a456-426614174000';
$revision = str_repeat('a', 64);
$brainwashOperations = [
    'brainwash.catalog.read' => (object)[],
    'brainwash.card.read' => (object)['actorKey' => $actorKey],
    'brainwash.draft' => (object)[
        'actorKey' => $actorKey, 'expectedRevision' => $revision, 'operationId' => $uuid,
        'mode' => 'manual', 'changes' => (object)['personality' => 'Brusque and watchful.'], 'instruction' => '',
    ],
    'brainwash.apply' => (object)[
        'actorKey' => $actorKey, 'draftId' => $uuid, 'operationId' => $uuid,
        'acceptedFields' => ['personality'], 'acceptBeliefs' => false,
    ],
    'brainwash.undo' => (object)[
        'actorKey' => $actorKey, 'operationId' => $uuid, 'appliedOperationId' => $uuid,
    ],
];

foreach ($brainwashOperations as $operation => $payload) {
    $expect(qd_validate_payload($operation, $payload) === 'ok', "valid payload accepted: {$operation}");
    $expect((QD_OPERATION_REQUIREMENTS[$operation]['scope'] ?? null) === 'brainwash', "explicit scope required: {$operation}");
    $expect((QD_OPERATION_REQUIREMENTS[$operation]['capability'] ?? null) === 'brainwash', "readiness required: {$operation}");
}
$expect(qd_validate_payload('brainwash.unknown', (object)[]) === 'unsupported_operation', 'unknown Brainwash operation refused');
$expect(qd_validate_payload('brainwash.catalog.read', (object)['query' => 'Lydia']) === 'ok', 'bounded catalog query accepted');
$expect(qd_validate_payload('brainwash.catalog.read', (object)['query' => str_repeat('é', 64)]) === 'ok', 'catalog query is bounded by UTF-8 bytes');
$expect(qd_validate_payload('brainwash.catalog.read', (object)['query' => 42]) === 'invalid_request', 'non-string catalog query refused');
$expect(qd_validate_payload('brainwash.catalog.read', (object)['query' => str_repeat('é', 65)]) === 'invalid_request', 'catalog query over 128 UTF-8 bytes refused');
$expect(qd_validate_payload('brainwash.card.read', (object)['actorKey' => 'npc:042']) === 'invalid_request', 'noncanonical actor key refused');
$expect(qd_validate_payload('brainwash.card.read', (object)['actorKey' => 'player']) === 'invalid_request', 'non-NPC actor key refused');
$badField = clone $brainwashOperations['brainwash.draft'];
$badField->changes = (object)['relationships' => 'must stay owner-managed'];
$expect(qd_validate_payload('brainwash.draft', $badField) === 'invalid_request', 'protected field refused');
$noChange = clone $brainwashOperations['brainwash.draft'];
$noChange->changes = (object)[];
$expect(qd_validate_payload('brainwash.draft', $noChange) === 'invalid_request', 'empty draft refused');
$oversizedField = clone $brainwashOperations['brainwash.draft'];
$oversizedField->changes = (object)['personality' => str_repeat('x', 8193)];
$expect(qd_validate_payload('brainwash.draft', $oversizedField) === 'invalid_request', 'oversized card field refused');
$oversizedInstruction = clone $brainwashOperations['brainwash.draft'];
$oversizedInstruction->instruction = str_repeat('x', 2001);
$expect(qd_validate_payload('brainwash.draft', $oversizedInstruction) === 'invalid_request', 'oversized instruction refused');
$emptyApply = clone $brainwashOperations['brainwash.apply'];
$emptyApply->acceptedFields = [];
$expect(qd_validate_payload('brainwash.apply', $emptyApply) === 'invalid_request', 'empty apply refused');
$duplicateApply = clone $brainwashOperations['brainwash.apply'];
$duplicateApply->acceptedFields = ['personality', 'personality'];
$expect(qd_validate_payload('brainwash.apply', $duplicateApply) === 'invalid_request', 'duplicate accepted field refused');
$extraUndo = clone $brainwashOperations['brainwash.undo'];
$extraUndo->fullRow = true;
$expect(qd_validate_payload('brainwash.undo', $extraUndo) === 'invalid_request', 'whole-row undo payload refused');

$expect(in_array('brainwash', QD_ALLOWED_SCOPES, true), 'Brainwash scope is a recognized explicit permission');
$expect(qd_has_required_scope(['pcv', 'inspector', 'compose'], 'brainwash') === false, 'legacy scopes do not imply Brainwash write access');
$expect(qd_has_required_scope(['brainwash'], 'brainwash') === true, 'Brainwash scope grants only explicit access');
$expect(qd_access_for_scopes(['inspector'])['brainwash'] === false, 'inspector access does not grant Brainwash writes');
$expect(qd_access_for_scopes(['brainwash'])['brainwash'] === true, 'fresh Brainwash permission is exposed');
$expect(in_array('brainwash.draft', QD_MUTATION_OPERATIONS, true)
    && in_array('brainwash.apply', QD_MUTATION_OPERATIONS, true)
    && in_array('brainwash.undo', QD_MUTATION_OPERATIONS, true), 'all Brainwash writes are classified as mutations');

$priorSourceRoot = getenv('QD_BRAINWASH_ROOT');
putenv('QD_BRAINWASH_ROOT=' . sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-brainwash-missing-' . bin2hex(random_bytes(8)));
$dependencies = qd_department_runtime_dependencies();
$operations = $dependencies['operations'] ?? [];
foreach (array_keys($brainwashOperations) as $operation) {
    $expect(isset($operations[$operation]) && is_callable($operations[$operation]), "runtime dispatch wired: {$operation}");
}
$readiness = qd_runtime_readiness($dependencies, false);
$expect(array_key_exists('brainwash', $readiness) && $readiness['brainwash'] === false, 'unavailable or unpinned Brainwash source is not ready');
$expect(function_exists('qd_brainwash_runtime_ready') && qd_brainwash_runtime_ready() === false, 'missing Brainwash source fails readiness closed');
try {
    qd_brainwash_call('brainwash.catalog.read', (object)[], ['scopeKey' => 'trusted-department-scope']);
    $expect(false, 'missing owner source cannot be loaded for dispatch');
} catch (QDServerFault $error) {
    $expect($error->wireCode === 'unavailable', 'missing owner source dispatch returns a fixed unavailable fault');
}
if ($priorSourceRoot === false) putenv('QD_BRAINWASH_ROOT'); else putenv('QD_BRAINWASH_ROOT=' . $priorSourceRoot);

$installedLayoutRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-brainwash-installed-layout-' . bin2hex(random_bytes(8));
if (!mkdir($installedLayoutRoot, 0700)) throw new RuntimeException('Could not create the installed-layout fixture.');
$layoutPreviousRoot = getenv('QD_BRAINWASH_ROOT');
try {
    file_put_contents($installedLayoutRoot . DIRECTORY_SEPARATOR . 'manifest.json', json_encode(['name' => 'brainwash', 'version' => QD_BRAINWASH_EXPECTED_VERSION], JSON_THROW_ON_ERROR));
    file_put_contents($installedLayoutRoot . DIRECTORY_SEPARATOR . 'runtime.php', "<?php\n");
    putenv('QD_BRAINWASH_ROOT=' . $installedLayoutRoot);
    $expect(qd_brainwash_source_root() === realpath($installedLayoutRoot), 'source checkout and installed extension use their direct server root');
    $expect(qd_brainwash_owner_entry_path($installedLayoutRoot) === realpath($installedLayoutRoot . DIRECTORY_SEPARATOR . 'runtime.php'), 'owner runtime entry is resolved from the staged extension root');
} finally {
    if ($layoutPreviousRoot === false) putenv('QD_BRAINWASH_ROOT'); else putenv('QD_BRAINWASH_ROOT=' . $layoutPreviousRoot);
    @unlink($installedLayoutRoot . DIRECTORY_SEPARATOR . 'manifest.json');
    @unlink($installedLayoutRoot . DIRECTORY_SEPARATOR . 'runtime.php');
    @rmdir($installedLayoutRoot);
}

$unpinnedRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-brainwash-unpinned-' . bin2hex(random_bytes(8));
if (!mkdir($unpinnedRoot, 0700)) throw new RuntimeException('Could not create the unpinned source fixture.');
$previousRoot = getenv('QD_BRAINWASH_ROOT');
try {
    file_put_contents($unpinnedRoot . DIRECTORY_SEPARATOR . 'manifest.json', json_encode(['name' => 'brainwash', 'version' => QD_BRAINWASH_EXPECTED_VERSION], JSON_THROW_ON_ERROR));
    file_put_contents($unpinnedRoot . DIRECTORY_SEPARATOR . 'runtime.php', "<?php\n");
    putenv('QD_BRAINWASH_ROOT=' . $unpinnedRoot);
    $expect(qd_brainwash_runtime_ready() === false, 'supported version with no pinned source hashes remains unavailable');
    try {
        qd_brainwash_call('brainwash.catalog.read', (object)[], ['scopeKey' => 'trusted-department-scope']);
        $expect(false, 'unpinned owner source cannot be loaded for dispatch');
    } catch (QDServerFault $error) {
        $expect($error->wireCode === 'unavailable', 'unpinned owner dispatch returns a fixed unavailable fault');
    }
} finally {
    if ($previousRoot === false) putenv('QD_BRAINWASH_ROOT'); else putenv('QD_BRAINWASH_ROOT=' . $previousRoot);
    @unlink($unpinnedRoot . DIRECTORY_SEPARATOR . 'manifest.json');
    @unlink($unpinnedRoot . DIRECTORY_SEPARATOR . 'runtime.php');
    @rmdir($unpinnedRoot);
}

$pinnedSourcePath = '/var/www/html/HerikaServer/ext/brainwash';
$pinnedSourceRoot = realpath($pinnedSourcePath);
$pinnedFiles = QD_BRAINWASH_EXPECTED_SOURCE_HASHES_BY_VERSION[QD_BRAINWASH_EXPECTED_VERSION] ?? null;
if (!is_string($pinnedSourceRoot) || $pinnedSourceRoot !== $pinnedSourcePath || !is_array($pinnedFiles) || $pinnedFiles === []) {
    throw new RuntimeException('The frozen installed Brainwash source pins are unavailable for drift checks.');
}
$pinFixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-brainwash-pins-' . bin2hex(random_bytes(8));
if (!mkdir($pinFixtureRoot, 0700)) throw new RuntimeException('Could not create the Brainwash pin fixture.');
$pinFixtureDirectories = [];
try {
    foreach (array_keys($pinnedFiles) as $relative) {
        if (!is_string($relative) || $relative === '' || str_contains($relative, '\\')
            || in_array('..', explode('/', $relative), true)) {
            throw new RuntimeException('The pinned source path is not safe for a fixture copy.');
        }
        $sourcePath = $pinnedSourceRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $sourceReal = realpath($sourcePath);
        $sourcePrefix = $pinnedSourceRoot . DIRECTORY_SEPARATOR;
        if (!is_string($sourceReal) || !str_starts_with($sourceReal, $sourcePrefix)
            || !is_file($sourcePath) || is_link($sourcePath)) {
            throw new RuntimeException('A pinned installed Brainwash source file is unavailable.');
        }
        $fixturePath = $pinFixtureRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $fixtureDirectory = dirname($fixturePath);
        if (!is_dir($fixtureDirectory) && !mkdir($fixtureDirectory, 0700, true)) {
            throw new RuntimeException('Could not create a pin-fixture directory.');
        }
        $directory = dirname($relative);
        while ($directory !== '.') {
            $pinFixtureDirectories[$directory] = true;
            $parentDirectory = dirname($directory);
            if ($parentDirectory === $directory) break;
            $directory = $parentDirectory;
        }
        if (!copy($sourceReal, $fixturePath)) throw new RuntimeException('Could not copy a pinned source fixture.');
    }
    $expect(qd_brainwash_manifest_ready($pinFixtureRoot), 'complete frozen Brainwash source closure is accepted');
    $fixtureManifestPath = $pinFixtureRoot . DIRECTORY_SEPARATOR . 'manifest.json';
    $fixtureManifestBytes = file_get_contents($fixtureManifestPath);
    $fixtureManifest = is_string($fixtureManifestBytes) ? json_decode($fixtureManifestBytes, true, 8) : null;
    if (!is_string($fixtureManifestBytes) || !is_array($fixtureManifest)) {
        throw new RuntimeException('Could not read the copied Brainwash manifest fixture.');
    }
    $fixtureManifest['version'] = '0.1.0-dev.999';
    file_put_contents($fixtureManifestPath, json_encode($fixtureManifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $expect(qd_brainwash_manifest_ready($pinFixtureRoot) === false, 'unknown owner manifest version fails readiness closed');
    file_put_contents($fixtureManifestPath, $fixtureManifestBytes);
    foreach (array_keys($pinnedFiles) as $relative) {
        $fixturePath = $pinFixtureRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $originalBytes = file_get_contents($fixturePath);
        if (!is_string($originalBytes)) throw new RuntimeException('Could not read a pinned source fixture.');
        if ($relative === 'manifest.json') {
            $changedManifest = json_decode($originalBytes, true, 8, JSON_THROW_ON_ERROR);
            $changedManifest['description'] = (string)($changedManifest['description'] ?? '') . ' pin drift';
            $changedBytes = json_encode($changedManifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } else {
            $changedBytes = $originalBytes . "\n// pin drift\n";
        }
        try {
            if (file_put_contents($fixturePath, $changedBytes) === false) {
                throw new RuntimeException('Could not mutate a source pin fixture.');
            }
            $expect(qd_brainwash_manifest_ready($pinFixtureRoot) === false, "source pin drift refused: {$relative}");
        } finally {
            file_put_contents($fixturePath, $originalBytes);
        }
    }
    $expect(qd_brainwash_manifest_ready($pinFixtureRoot), 'restored source pins return to the accepted state');
} finally {
    foreach (array_keys($pinnedFiles) as $relative) {
        @unlink($pinFixtureRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    }
    $directories = array_keys($pinFixtureDirectories);
    usort($directories, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
    foreach ($directories as $directory) @rmdir($pinFixtureRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory));
    @rmdir($pinFixtureRoot);
}

$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-brainwash-scope-' . bin2hex(random_bytes(8));
if (!mkdir($tempRoot, 0700)) throw new RuntimeException('Could not create owned temporary scope-test directory.');
$pairingPath = $tempRoot . DIRECTORY_SEPARATOR . 'pairing.json';
try {
    $webRoot = dirname(__DIR__, 3);
    $old = qd_pair_issue($pairingPath, $webRoot, ['compose', 'inspector', 'pcv']);
    $freshTokenHash = hash('sha256', 'fresh-native-token-' . bin2hex(random_bytes(8)));
    $approved = qd_pair_approve_hash($pairingPath, $webRoot, $freshTokenHash);
    $pairing = json_decode((string)file_get_contents($pairingPath), true, 8, JSON_THROW_ON_ERROR);
    $oldPair = array_values(array_filter($pairing['pairs'], static fn(array $pair): bool => $pair['pairId'] === $old['pairId']))[0] ?? null;
    $newPair = array_values(array_filter($pairing['pairs'], static fn(array $pair): bool => $pair['pairId'] === ($approved['pairId'] ?? null)))[0] ?? null;
    $expect(!in_array('brainwash', $old['scopes'], true) && is_array($oldPair) && !in_array('brainwash', $oldPair['scopes'], true), 'existing pair remains without Brainwash scope');
    $expect(($approved['state'] ?? null) === 'approved' && is_array($newPair) && in_array('brainwash', $newPair['scopes'], true), 'fresh native approval explicitly receives Brainwash scope');
} finally {
    @unlink($pairingPath);
    @unlink($pairingPath . '.lock');
    @rmdir($tempRoot);
}

$dispatchRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-brainwash-dispatch-' . bin2hex(random_bytes(8));
$dispatchWebRoot = $dispatchRoot . DIRECTORY_SEPARATOR . 'web';
if (!mkdir($dispatchWebRoot, 0700, true)) throw new RuntimeException('Could not create the Brainwash dispatch fixture.');
$dispatchSettings = [
    'pairingPath' => $dispatchRoot . DIRECTORY_SEPARATOR . 'pairing.json',
    'statePath' => $dispatchRoot . DIRECTORY_SEPARATOR . 'state.json',
    'webRoot' => $dispatchWebRoot,
];
$dispatchPair = qd_pair_issue($dispatchSettings['pairingPath'], $dispatchWebRoot, ['brainwash']);
$dispatchTrace = (object)[
    'scope' => 'active-profile-1|restore-1', 'reads' => 0, 'mutations' => [], 'failOperation' => null,
    'applied' => [], 'undone' => [], 'restored' => false,
];
$dispatchDependencies = [
    'currentScope' => static fn(string $pairId): string => $dispatchTrace->scope,
    'readiness' => static fn(bool $includePcv = true): array => [
        'pcv' => false, 'mp' => false, 'sas' => false, 'correspondence' => false, 'brainwash' => true,
    ],
    'perspectives' => static fn(string $scopeKey): array => [
        'mp' => ['choices' => [], 'limited' => false], 'sas' => ['choices' => [], 'limited' => false],
    ],
    'operations' => [],
];
$dispatchCard = (object)[
    'actorKey' => $actorKey, 'label' => 'Lydia', 'scope' => 'active-profile-1|restore-1', 'revision' => $revision,
    'fields' => (object)array_fill_keys(QD_BRAINWASH_FIELDS, ''), 'beliefs' => '', 'draftId' => $uuid, 'lastOperation' => null,
];
$dispatchDependencies['operations']['brainwash.catalog.read'] = static function () use ($dispatchTrace): array {
    $dispatchTrace->reads++;
    return ['actors' => [['actorKey' => 'npc:42', 'label' => 'Lydia']], 'limited' => false];
};
$dispatchDependencies['operations']['brainwash.card.read'] = static function () use ($dispatchTrace, $dispatchCard): object {
    $dispatchTrace->reads++;
    return $dispatchCard;
};
foreach (['brainwash.draft', 'brainwash.apply', 'brainwash.undo'] as $mutationOperation) {
    $dispatchDependencies['operations'][$mutationOperation] = static function (stdClass $payload, array $context) use ($dispatchTrace, $dispatchCard, $mutationOperation, $revision): array {
        $dispatchTrace->mutations[] = $mutationOperation;
        if ($mutationOperation === 'brainwash.apply') {
            if ($dispatchTrace->restored) throw new QDServerFault('stale_context');
            if (isset($dispatchTrace->undone[$payload->operationId])) throw new QDServerFault('conflict');
            if (!in_array($payload->operationId, $dispatchTrace->applied, true)) {
                ($context['beginCommit'])();
                $dispatchTrace->applied[] = $payload->operationId;
            }
        } elseif ($mutationOperation === 'brainwash.undo') {
            ($context['beginCommit'])();
            if ($dispatchTrace->failOperation !== $mutationOperation) $dispatchTrace->undone[$payload->appliedOperationId] = true;
        } else {
            ($context['beginCommit'])();
        }
        if ($dispatchTrace->failOperation === $mutationOperation) throw new RuntimeException('synthetic owner outcome is unknown');
        if ($mutationOperation === 'brainwash.draft') {
            $before = (object)['personality' => str_repeat('B', 8192), 'goals' => str_repeat('C', 8192)];
            return [
                'actorKey' => $payload->actorKey, 'scope' => 'active-profile-1|restore-1', 'revision' => $revision,
                'draftId' => $payload->operationId, 'origin' => $payload->mode, 'before' => $before, 'changes' => $payload->changes,
            ];
        }
        return ['operationId' => $payload->operationId, 'state' => match ($mutationOperation) {
            'brainwash.draft' => 'drafted', 'brainwash.apply' => 'applied', default => 'undone',
        }, 'card' => $dispatchCard];
    };
}
$dispatchCounter = 1;
$dispatchRequest = static function (string $operation, ?string $serverContext, string $viewToken, array $payload = []) use (&$dispatchCounter): stdClass {
    $requestId = sprintf('123e4567-e89b-12d3-a456-%012x', $dispatchCounter++);
    return (object)[
        'version' => 1, 'requestId' => $requestId, 'operation' => $operation,
        'context' => (object)['viewToken' => $viewToken, 'nativeGeneration' => '1', 'serverContext' => $serverContext],
        'payload' => (object)$payload,
    ];
};
$dispatchWire = static fn(array $response): object => json_decode(json_encode($response, JSON_THROW_ON_ERROR), false, 8, JSON_THROW_ON_ERROR);
$dispatchView = '123e4567-e89b-12d3-a456-426614174001';
$dispatchToken = 'Bearer ' . $dispatchPair['token'];
try {
    $dispatchBootstrap = $dispatchWire(qd_server_dispatch($dispatchRequest('bootstrap', null, $dispatchView), $dispatchToken, $dispatchSettings, $dispatchDependencies));
    $expect(($dispatchBootstrap->ok ?? false) === true, 'brainwash-only connection completes authenticated bootstrap');
    $expect(($dispatchBootstrap->data->capabilities->brainwash ?? false) === true && ($dispatchBootstrap->data->access->brainwash ?? false) === true,
        'fresh Brainwash scope exposes only its ready capability and explicit access');
    $dispatchContext = $dispatchBootstrap->data->serverContext ?? null;
    $catalogRequest = $dispatchRequest('brainwash.catalog.read', $dispatchContext, $dispatchView);
    $catalogResponse = $dispatchWire(qd_server_dispatch($catalogRequest, $dispatchToken, $dispatchSettings, $dispatchDependencies));
    $expect(($catalogResponse->ok ?? false) === true && $catalogResponse->data->actors[0]->actorKey === 'npc:42',
        'authorized catalog request reaches the actual Department dispatcher');
    $expect($dispatchTrace->reads === 1, 'catalog read is dispatched once');

    $applyPayload = ['actorKey' => $actorKey, 'draftId' => $uuid, 'operationId' => '123e4567-e89b-12d3-a456-426614174002',
        'acceptedFields' => ['personality'], 'acceptBeliefs' => false];
    $applyRequest = $dispatchRequest('brainwash.apply', $dispatchContext, $dispatchView, $applyPayload);
    $applyFirst = $dispatchWire(qd_server_dispatch($applyRequest, $dispatchToken, $dispatchSettings, $dispatchDependencies));
    $applyReplay = $dispatchWire(qd_server_dispatch($applyRequest, $dispatchToken, $dispatchSettings, $dispatchDependencies));
    $expect(($applyFirst->ok ?? false) === true && ($applyReplay->ok ?? false) === true
        && json_encode($applyFirst->data, JSON_THROW_ON_ERROR) === json_encode($applyReplay->data, JSON_THROW_ON_ERROR),
        'same Brainwash mutation ID and payload returns the current owner idempotent receipt');
    $expect($dispatchTrace->mutations === ['brainwash.apply', 'brainwash.apply'], 'completed Brainwash IDs re-enter the owner instead of replaying Department data');

    $dispatchTrace->restored = true;
    $restoredApply = $dispatchWire(qd_server_dispatch($applyRequest, $dispatchToken, $dispatchSettings, $dispatchDependencies));
    $expect(($restoredApply->error->code ?? null) === 'stale_context' && count($dispatchTrace->mutations) === 3,
        'same-ID restore is revalidated by the owner instead of returning a stale applied result');
    $dispatchTrace->restored = false;
    $changedApply = clone $applyRequest;
    $changedApply->payload = clone $applyRequest->payload;
    $changedApply->payload->acceptBeliefs = true;
    $changedResponse = $dispatchWire(qd_server_dispatch($changedApply, $dispatchToken, $dispatchSettings, $dispatchDependencies));
    $expect(($changedResponse->error->code ?? null) === 'conflict' && count($dispatchTrace->mutations) === 3,
        'reused Brainwash operation ID with changed payload is refused before dispatch');

    $undoOperationId = '123e4567-e89b-12d3-a456-426614174003';
    $undoRequest = $dispatchRequest('brainwash.undo', $dispatchContext, $dispatchView, [
        'actorKey' => $actorKey, 'operationId' => $undoOperationId, 'appliedOperationId' => $applyPayload['operationId'],
    ]);
    $undoResponse = $dispatchWire(qd_server_dispatch($undoRequest, $dispatchToken, $dispatchSettings, $dispatchDependencies));
    $postUndoApply = $dispatchWire(qd_server_dispatch($applyRequest, $dispatchToken, $dispatchSettings, $dispatchDependencies));
    $expect(($undoResponse->ok ?? false) === true && ($postUndoApply->error->code ?? null) === 'conflict',
        'owner replay after undo cannot return the old applied result (calls=' . count($dispatchTrace->mutations) . ')');
    $expect(count($dispatchTrace->mutations) === 5,
        'same-ID apply after undo must reach the Brainwash owner for current receipt validation (calls=' . count($dispatchTrace->mutations) . ')');

    $largeDraftRequest = $dispatchRequest('brainwash.draft', $dispatchContext, $dispatchView, [
        'actorKey' => $actorKey, 'expectedRevision' => $revision, 'operationId' => '123e4567-e89b-12d3-a456-426614174005',
        'mode' => 'manual', 'changes' => (object)['personality' => str_repeat('A', 8192), 'goals' => str_repeat('D', 8192)], 'instruction' => '',
    ]);
    $largeDraft = $dispatchWire(qd_server_dispatch($largeDraftRequest, $dispatchToken, $dispatchSettings, $dispatchDependencies));
    $largeDraftBytes = strlen(json_encode($largeDraft->data ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $expect(($largeDraft->ok ?? false) === true && $largeDraftBytes > QD_MAX_JOURNAL_RESULT_BYTES,
        'valid large Brainwash draft succeeds beyond the Department result-detail cache cap (code=' . ($largeDraft->error->code ?? 'ok') . ',bytes=' . $largeDraftBytes . ',calls=' . count($dispatchTrace->mutations) . ')');
    $expect(count($dispatchTrace->mutations) === 6, 'large draft is dispatched exactly once before its retry (calls=' . count($dispatchTrace->mutations) . ')');
    $largeDraftReplay = $dispatchWire(qd_server_dispatch($largeDraftRequest, $dispatchToken, $dispatchSettings, $dispatchDependencies));
    $expect(($largeDraftReplay->ok ?? false) === true && count($dispatchTrace->mutations) === 7,
        'large owner-idempotent draft is revalidated on repeat without storing its full result in the journal (code=' . ($largeDraftReplay->error->code ?? 'ok') . ',calls=' . count($dispatchTrace->mutations) . ')');

    $unknownOperationId = '123e4567-e89b-12d3-a456-426614174004';
    $unknownRequest = $dispatchRequest('brainwash.undo', $dispatchContext, $dispatchView, [
        'actorKey' => $actorKey, 'operationId' => $unknownOperationId, 'appliedOperationId' => $uuid,
    ]);
    $dispatchTrace->failOperation = 'brainwash.undo';
    $unknownFirst = $dispatchWire(qd_server_dispatch($unknownRequest, $dispatchToken, $dispatchSettings, $dispatchDependencies));
    $expect(count($dispatchTrace->mutations) === 8, 'unknown mutation attempt reaches the owner once (calls=' . count($dispatchTrace->mutations) . ')');
    $unknownRetry = $dispatchWire(qd_server_dispatch($unknownRequest, $dispatchToken, $dispatchSettings, $dispatchDependencies));
    $expect(($unknownFirst->error->code ?? null) === 'unavailable' && ($unknownRetry->error->code ?? null) === 'conflict',
        'unknown post-commit outcome remains refused on retry rather than being reported safe');
    $expect(count($dispatchTrace->mutations) === 8, 'unknown mutation retry is never dispatched a second time (calls=' . count($dispatchTrace->mutations) . ',first=' . ($unknownFirst->error->code ?? 'ok') . ',retry=' . ($unknownRetry->error->code ?? 'ok') . ')');

    $inspectorPair = qd_pair_issue($dispatchSettings['pairingPath'], $dispatchWebRoot, ['inspector']);
    $inspectorToken = 'Bearer ' . $inspectorPair['token'];
    $inspectorView = '123e4567-e89b-12d3-a456-426614174004';
    $inspectorBootstrap = $dispatchWire(qd_server_dispatch($dispatchRequest('bootstrap', null, $inspectorView), $inspectorToken, $dispatchSettings, $dispatchDependencies));
    $denied = $dispatchWire(qd_server_dispatch($dispatchRequest('brainwash.catalog.read', $inspectorBootstrap->data->serverContext, $inspectorView),
        $inspectorToken, $dispatchSettings, $dispatchDependencies));
    $expect(($denied->error->code ?? null) === 'forbidden' && $dispatchTrace->reads === 1,
        'inspector-only session cannot reach Brainwash dispatch');
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dispatchRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($dispatchRoot);
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", array_map(static fn(string $failure): string => "FAIL: {$failure}", $failures)) . "\n");
    fwrite(STDERR, count($failures) . " of {$checks} Department Brainwash contract checks failed.\n");
    exit(1);
}
fwrite(STDOUT, "{$checks} Department Brainwash contract checks passed.\n");
