<?php
declare(strict_types=1);

$ownerRoot = '';
$legacyOwnerRoot = '';
$ownerVersion = '0.1.0-dev.23';
$adapterPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'correspondence.php';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--owner-root' && isset($argv[$i + 1])) {
        $ownerRoot = $argv[++$i];
    } elseif ($argv[$i] === '--legacy-owner-root' && isset($argv[$i + 1])) {
        $legacyOwnerRoot = $argv[++$i];
    } elseif ($argv[$i] === '--owner-version' && isset($argv[$i + 1])) {
        $ownerVersion = $argv[++$i];
    } elseif ($argv[$i] === '--adapter' && isset($argv[$i + 1])) {
        $adapterPath = $argv[++$i];
    } else {
        fwrite(STDERR, "usage: php -n dev23-source-contract.php --owner-root OWNER_SERVER_DIR [--owner-version 0.1.0-dev.23|0.1.0-dev.24] [--legacy-owner-root DEV23_SERVER_DIR] [--adapter PATH]\n");
        exit(2);
    }
}
if (!in_array($ownerVersion, ['0.1.0-dev.23', '0.1.0-dev.24'], true)
    || ($ownerVersion === '0.1.0-dev.24' && $legacyOwnerRoot === '')) {
    fwrite(STDERR, "usage: dev23-source-contract.php supports dev.23 and dev.24; dev.24 requires --legacy-owner-root\n");
    exit(2);
}

function dev23Expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function qd_runtime_core_root(): string
{
    $root = $GLOBALS['DEV23_TEST_CORE_ROOT'] ?? null;
    if (!is_string($root) || $root === '') throw new RuntimeException('The isolated test core root is unavailable.');
    return $root;
}

function dev23Cleanup(string $root): void
{
    if (!file_exists($root) && !is_link($root)) return;
    $base = realpath(sys_get_temp_dir());
    $real = realpath($root);
    if (!is_string($base) || !is_string($real) || is_link($root)
        || strcasecmp(dirname($real), $base) !== 0
        || !str_starts_with(basename($real), 'qd-correspondence-dev23-')) {
        throw new RuntimeException('Unsafe dev23 fixture cleanup path.');
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        if ($entry->isLink() ? !@unlink($entry->getPathname())
            : ($entry->isDir() ? !@rmdir($entry->getPathname()) : !@unlink($entry->getPathname()))) {
            throw new RuntimeException('Could not clean the dev23 fixture.');
        }
    }
    if (!@rmdir($real)) throw new RuntimeException('Could not clean the dev23 fixture.');
}

final class Dev23OwnerStore
{
    public bool $decisionTableExists = true;
    public array $decisionRows = [];
    public array $documents = [];
    public array $operations = [];
    public array $events = [];
    public int $decisionLookups = 0;

    public function query(string $sql, array $parameters = []): array
    {
        if (str_contains($sql, 'to_regclass')) {
            return [['decision_table' => $this->decisionTableExists ? 'plugins.correspondence_companion_decision' : null]];
        }
        if (str_contains($sql, 'plugins.correspondence_companion_decision')) {
            $this->decisionLookups++;
            $documentId = $parameters[0] ?? null;
            return array_values(array_filter($this->decisionRows, static fn(array $row): bool => ($row['document_id'] ?? null) === $documentId));
        }
        throw new RuntimeException('Unexpected test query: ' . $sql);
    }

    public function eventRow(array $anchor): ?array
    {
        return $this->events[(string)($anchor['rowid'] ?? '')] ?? null;
    }

    public function eventMatches(array $event): bool
    {
        return ($this->events[(string)($event['rowid'] ?? '')] ?? null) === $event;
    }

    public function loadDocument(string $id): ?array
    {
        return $this->documents[$id] ?? null;
    }

    public function loadOperation(string $id): ?array
    {
        return $this->operations[$id] ?? null;
    }
}

function dev23Document(bool $manualPayer = false, bool $closed = false): array
{
    $documentId = '11111111-1111-4111-8111-111111111111';
    $requestId = '22222222-2222-4222-8222-222222222222';
    $npc = ['kind' => 'npc', 'id' => 42, 'name' => 'Lydia'];
    $player = ['kind' => 'player', 'id' => null, 'name' => 'Dragonborn'];
    $anchor = [
        'rowid' => 1,
        'ts' => '1',
        'gamets' => '1',
        'type' => 'info',
        'data' => correspondenceJson(['document_id' => $documentId, 'scope_token' => '33333333-3333-4333-8333-333333333333']),
        'sess' => 'correspondence_v1',
        'localts' => '1',
        'people' => '',
    ];
    $document = [
        'document_id' => $documentId,
        'playthrough_id' => '1',
        'request_id' => $requestId,
        'request_hash' => '',
        'body' => 'A private letter.',
        'attachments' => [],
        'operation_id' => null,
        'author' => $npc,
        'payer' => $manualPayer ? $player : $npc,
        'recipient' => $player,
        'claimed_sender' => $npc,
        'title' => 'letter ' . $documentId,
        'anchor' => $anchor,
    ];
    $request = [
        'request_id' => $requestId,
        'author' => 'npc:42',
        'payer' => $manualPayer ? 'player' : 'npc:42',
        'recipient' => 'player',
        'claimed_sender' => 'npc:42',
        'body' => $document['body'],
        'attachments' => [],
    ];
    $document['request_hash'] = hash('sha256', correspondenceCanonicalJson($request));
    if ($closed) {
        $document['operator_close'] = [
            'rowid' => 2,
            'ts' => '2',
            'gamets' => '2',
            'type' => 'correspondence_operator_closed',
            'data' => correspondenceJson(['document_id' => $documentId, 'operation_id' => null, 'reason' => 'operator_closed']),
            'sess' => 'correspondence_v1',
            'localts' => '2',
            'people' => '',
        ];
    }
    return [$document, $anchor];
}

function dev23Decision(array $document, int $actorId = 42, ?string $requestId = null, ?string $playthroughId = null, string $status = 'stale'): array
{
    return [
        'document_id' => $document['document_id'],
        'request_id' => $requestId ?? $document['request_id'],
        'playthrough_id' => $playthroughId ?? $document['playthrough_id'],
        'actor_id' => (string)$actorId,
        'status' => $status,
    ];
}

function dev23Operation(array &$document, string $status = 'delivered'): array
{
    $operationId = '44444444-4444-4444-8444-444444444444';
    $receiptId = '55555555-5555-4555-8555-555555555555';
    $scopeToken = '66666666-6666-4666-8666-666666666666';
    $nonce = str_repeat('a', 64);
    $document['operation_id'] = $operationId;
    if (isset($document['operator_close'])) {
        $document['operator_close']['data'] = correspondenceJson([
            'document_id' => $document['document_id'],
            'operation_id' => $operationId,
            'reason' => 'operator_closed',
        ]);
    }
    $physicalNpc = ['kind' => 'npc', 'id' => 42, 'name' => 'Lydia', 'form_id' => 42000042];
    $physicalPlayer = ['kind' => 'player', 'id' => null, 'name' => 'Dragonborn', 'form_id' => 1];
    $dispatch = [
        'rowid' => 3,
        'ts' => '3',
        'gamets' => '3',
        'type' => 'info',
        'data' => correspondenceJson(['document_id' => $document['document_id'], 'operation_id' => $operationId]),
        'sess' => 'correspondence_v1',
        'localts' => '3',
        'people' => '',
    ];
    $receipt = [
        'protocol_version' => 1,
        'document_id' => $document['document_id'],
        'operation_id' => $operationId,
        'receipt_id' => $receiptId,
        'playthrough_id' => $document['playthrough_id'],
        'scope_token' => $scopeToken,
        'nonce' => $nonce,
        'status' => $status,
        'reason' => $status === 'delivered' ? 'written' : 'uncertain',
        'note_form_id' => $status === 'delivered' ? 12345 : 0,
        'attachments' => [],
    ];
    $receiptAnchor = [
        'rowid' => 4,
        'ts' => '4',
        'gamets' => '4',
        'type' => 'info',
        'data' => correspondenceJson([
            'document_id' => $document['document_id'],
            'operation_id' => $operationId,
            'receipt_hash' => hash('sha256', correspondenceJson($receipt)),
        ]),
        'sess' => 'correspondence_v1',
        'localts' => '4',
        'people' => '',
    ];
    return [[
        'protocol_version' => 1,
        'document_id' => $document['document_id'],
        'operation_id' => $operationId,
        'receipt_id' => $receiptId,
        'playthrough_id' => $document['playthrough_id'],
        'scope_token' => $scopeToken,
        'nonce' => $nonce,
        'recipient' => $physicalPlayer,
        'payer' => $physicalNpc,
        'title' => $document['title'],
        'renderer_key' => md5(strtolower(trim($document['title']))),
        'attachments' => [],
        'receipt' => $receipt,
        'receipt_anchor' => $receiptAnchor,
        'source' => null,
        'dispatch_anchor' => $dispatch,
    ], $dispatch, $receiptAnchor, $physicalNpc, $physicalPlayer];
}

$ownerReal = realpath($ownerRoot);
$legacyOwnerReal = $legacyOwnerRoot !== '' ? realpath($legacyOwnerRoot) : null;
$adapterReal = realpath($adapterPath);
dev23Expect(is_string($ownerReal) && is_dir($ownerReal), 'Pass the immutable Sealed Fates server source directory.');
if ($ownerVersion === '0.1.0-dev.24') dev23Expect(is_string($legacyOwnerReal) && is_dir($legacyOwnerReal), 'Pass the immutable Sealed Fates dev.23 server source directory for manifest replacement.');
dev23Expect(is_string($adapterReal) && is_file($adapterReal), 'The candidate Department adapter is unavailable.');
$manifest = json_decode((string)@file_get_contents($ownerReal . DIRECTORY_SEPARATOR . 'manifest.json'), true);
dev23Expect(is_array($manifest) && ($manifest['name'] ?? null) === 'correspondence' && ($manifest['version'] ?? null) === $ownerVersion, 'Owner root is not the requested exact Sealed Fates source.');

$expectedHashes = [
    'manifest.json' => '8c36e6703c3dfe70183034e855e0709f34593b90d8d3819c17adc1b260d012b1',
    'lib/runtime.php' => '804554dcce9b9f3fbcb5ffb57958f36007ac4624f07c33fd813f8e312839146e',
    'lib/store.php' => 'ee86741a203b998d5c05c668e52073402248a42290fd23fe153c912f56cfeb28',
    'lib/boundaries.php' => '21034b79e36d208b30ed0ea5aa6892581ae6749c5a9190a3b4a422f4f74b7d0f',
    'lib/bootstrap.php' => '4cf8e693705ad78a8fdd5420ea682060f8712088b1a3b03a1b84510b4672f151',
    'lib/autonomy_policy.php' => 'db83aa3f1bc85c80c12c57294262ee7d4c93dc4bfcbd9eef4de8ced31b6fba18',
    'lib/autonomy_store.php' => 'c163243334c8a558c800fd946d27564acf2f49d8df51ddf914899d784809a795',
    'lib/autonomy_host.php' => 'dfa257dbb68f1ade87b7f13750e4fb25dd306c695b3c4ddd070e7ca1a4140a61',
    'lib/offscreen_policy.php' => '23639437e41eafaebd98ec85ce81cf3815ac51365f036c9e33b373fc17504058',
    'lib/offscreen.php' => '26f9f9a31844bfb433d7d6bf2c996e8ad6e0c9f6c15c5a054d96cb3630e0f439',
    'lib/offscreen_host.php' => '7ae21d2db282d9de0f1d37ca27a43624343fefd5e371b671a5f543177e1a2155',
    'lib/companion.php' => 'b93244fdf64206fefd21960b17a73844a57c5dbd98ed8e83a3b18ffc048a7597',
    'lib/companion_policy.php' => 'b245767ef4764e82e4fd945f0ab0508993b4095b3bba6012ebeb3a1f09754be5',
    'lib/companion_host.php' => 'b632693d26720fa063b7bbcd771cbce4997039aadff1c6755cb361c0239d1203',
    'migrations/001_initial.sql' => 'b2052570036697147dacb5d041c0a692294af78a83f946e7819d83235e16dc99',
    'migrations/002_autonomy.sql' => '20e0ba2901f9ff352311e00a706c976c793e1e1185c224afcb599f426acb63d3',
    'migrations/003_offscreen.sql' => '245ae41800da5d9d291cf25df9e4766059677710241a086c15ca6999d7500855',
    'migrations/004_restore_state.sql' => 'd6a13622ece3af538996558d892852c5126581d99b4964eec0203a5b5b1a559e',
    'migrations/005_companion.sql' => 'b5b65588aea68eacd97907b1c548ba0c8d2c00b064b8d2c405a5aba5239a9cee',
];
if ($ownerVersion === '0.1.0-dev.24') {
    $expectedHashes['manifest.json'] = '0c11ab7d5dd79941f5b4228b4d4d3e8d3dbc942c56a83b5911e6593fc3a4bc4e';
    $expectedHashes['lib/runtime.php'] = 'd247cb0af6b2476045157ada30b1b3ba7d1af30b9f4c4ff555f098c8fa7333e6';
}

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'protocol.php';
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-correspondence-dev23-' . bin2hex(random_bytes(8));
$coreRoot = $tempRoot . DIRECTORY_SEPARATOR . 'core';
$stagedOwnerRoot = $coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'correspondence';
if (!@mkdir($stagedOwnerRoot, 0700, true)) throw new RuntimeException('Could not create the isolated dev23 source stage.');
$GLOBALS['DEV23_TEST_CORE_ROOT'] = $coreRoot;

try {
    require $adapterReal;
    $adapterHashes = QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION[$ownerVersion] ?? null;
    dev23Expect(is_array($adapterHashes) && $adapterHashes === $expectedHashes, 'The adapter owner pin map differs from the exact transitive closure.');
    $sourceFiles = qd_correspondence_source_files($ownerReal);
    dev23Expect(array_keys($sourceFiles) === array_keys($expectedHashes), 'The owner source closure is incomplete or overbroad.');
    dev23Expect(qd_correspondence_manifest_ready($ownerReal), 'The exact owner closure did not pass the adapter manifest check.');
    $identity = qd_correspondence_current_source_identity($ownerReal);
    dev23Expect(is_array($identity) && ($identity['version'] ?? null) === $ownerVersion
        && ($identity['manifestHash'] ?? null) === $expectedHashes['manifest.json'], 'The exact owner identity was not recognized.');

    foreach ($sourceFiles as $relative => $source) {
        $sourceReal = realpath($source);
        dev23Expect(is_string($sourceReal) && !is_link($source) && str_starts_with($sourceReal, $ownerReal . DIRECTORY_SEPARATOR), 'Owner closure path escaped its exact source root: ' . $relative);
        $target = $stagedOwnerRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $targetDirectory = dirname($target);
        if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0700, true)) throw new RuntimeException('Could not create staged owner directory.');
        if (!@copy($sourceReal, $target)) throw new RuntimeException('Could not stage owner file: ' . $relative);
    }
    dev23Expect(qd_correspondence_runtime_ready(), 'Runtime readiness did not accept the exact staged owner closure.');
    foreach (['manifest.json', 'lib/runtime.php', 'lib/companion.php', 'lib/companion_policy.php', 'lib/companion_host.php', 'migrations/002_autonomy.sql', 'migrations/003_offscreen.sql', 'migrations/005_companion.sql'] as $relative) {
        $driftPath = $stagedOwnerRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $originalBytes = @file_get_contents($driftPath);
        dev23Expect(is_string($originalBytes), 'Could not read the staged drift fixture: ' . $relative);
        if (@file_put_contents($driftPath, $originalBytes . ' ', LOCK_EX) === false) throw new RuntimeException('Could not alter the staged drift fixture: ' . $relative);
        dev23Expect(!qd_correspondence_runtime_ready(), 'Runtime readiness accepted drift in ' . $relative . '.');
        if (@file_put_contents($driftPath, $originalBytes, LOCK_EX) !== strlen($originalBytes)) throw new RuntimeException('Could not restore the staged drift fixture: ' . $relative);
        dev23Expect(qd_correspondence_runtime_ready(), 'Runtime readiness did not recover after restoring ' . $relative . '.');
    }

    if ($ownerVersion === '0.1.0-dev.24') {
        $legacyManifestBytes = @file_get_contents($legacyOwnerReal . DIRECTORY_SEPARATOR . 'manifest.json');
        $legacyManifest = is_string($legacyManifestBytes) ? json_decode($legacyManifestBytes, true) : null;
        dev23Expect(is_array($legacyManifest) && ($legacyManifest['version'] ?? null) === '0.1.0-dev.23'
            && hash_equals('8c36e6703c3dfe70183034e855e0709f34593b90d8d3819c17adc1b260d012b1', hash('sha256', $legacyManifestBytes)), 'The manifest replacement fixture is not the exact dev.23 manifest.');
        $stagedManifest = $stagedOwnerRoot . DIRECTORY_SEPARATOR . 'manifest.json';
        $dev24ManifestBytes = @file_get_contents($stagedManifest);
        dev23Expect(is_string($dev24ManifestBytes), 'Could not read the staged dev.24 manifest.');
        if (@file_put_contents($stagedManifest, $legacyManifestBytes, LOCK_EX) !== strlen($legacyManifestBytes)) throw new RuntimeException('Could not stage the dev.23 manifest replacement fixture.');
        dev23Expect(!qd_correspondence_manifest_ready($stagedOwnerRoot) && !qd_correspondence_runtime_ready(), 'A dev.23 manifest replacement was accepted over dev.24 runtime bytes.');
        if (@file_put_contents($stagedManifest, $dev24ManifestBytes, LOCK_EX) !== strlen($dev24ManifestBytes)) throw new RuntimeException('Could not restore the exact dev.24 manifest.');
        dev23Expect(qd_correspondence_runtime_ready(), 'The exact dev.24 source did not recover after the manifest replacement fixture was restored.');
    }

    foreach (['0.1.0-dev.14', '0.1.0-dev.15', '0.1.0-dev.16', '0.1.0-dev.17', '0.1.0-dev.18', '0.1.0-dev.20', '0.1.0-dev.21', '0.1.0-dev.22', '0.1.0-dev.23', '0.1.0-dev.24'] as $version) {
        dev23Expect(isset(QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION[$version]), 'An existing pinned owner version was removed: ' . $version);
    }
    dev23Expect(!isset(QD_CORRESPONDENCE_SOURCE_HASHES_BY_VERSION['0.1.0-dev.19']), 'The intentionally unsupported dev.19 pin was added.');
    foreach (['0.1.0-dev.18', '0.1.0-dev.20', '0.1.0-dev.21', '0.1.0-dev.22', '0.1.0-dev.23', '0.1.0-dev.24'] as $version) {
        dev23Expect(qd_correspondence_version_uses_restore_fence($version), 'The restore fence is missing for ' . $version . '.');
    }
    dev23Expect(!qd_correspondence_version_uses_restore_fence('0.1.0-dev.19'), 'The unsupported dev.19 source was enabled.');
    foreach (['0.1.0-dev.17', '0.1.0-dev.18', '0.1.0-dev.20', '0.1.0-dev.21', '0.1.0-dev.22', '0.1.0-dev.23', '0.1.0-dev.24'] as $version) {
        dev23Expect(qd_correspondence_version_supports_signed_unknown($version), 'Signed-unknown support is missing for ' . $version . '.');
    }
    dev23Expect(!qd_correspondence_version_supports_signed_unknown('0.1.0-dev.19'), 'The unsupported dev.19 signed-unknown source was enabled.');
    foreach (['0.1.0-dev.20', '0.1.0-dev.21', '0.1.0-dev.22', '0.1.0-dev.23', '0.1.0-dev.24'] as $version) {
        dev23Expect(qd_correspondence_version_supports_operator_close($version), 'Operator-close support is missing for ' . $version . '.');
    }
    foreach (['0.1.0-dev.17', '0.1.0-dev.18', '0.1.0-dev.19'] as $version) {
        dev23Expect(!qd_correspondence_version_supports_operator_close($version), 'Operator-close support was broadened to ' . $version . '.');
    }
    foreach (['0.1.0-dev.23', '0.1.0-dev.24'] as $version) {
        dev23Expect(qd_correspondence_version_supports_companion_scheduler($version), 'Companion scheduler support is missing for ' . $version . '.');
    }
    dev23Expect(!qd_correspondence_version_supports_companion_scheduler('0.1.0-dev.22'), 'Companion scheduler support was broadened before dev.23.');

    $unsupportedRoot = $tempRoot . DIRECTORY_SEPARATOR . 'unsupported-dev19';
    if (!@mkdir($unsupportedRoot, 0700, true)
        || @file_put_contents($unsupportedRoot . DIRECTORY_SEPARATOR . 'manifest.json', '{"name":"correspondence","version":"0.1.0-dev.19"}', LOCK_EX) === false) {
        throw new RuntimeException('Could not create the unsupported-version fixture.');
    }
    dev23Expect(qd_correspondence_source_files($unsupportedRoot) === [] && !qd_correspondence_manifest_ready($unsupportedRoot), 'An unpinned dev.19 source was accepted.');

    dev23Expect(qd_correspondence_loaded_source_version() === $ownerVersion, 'The exact staged owner source did not load.');
    $revisionDocA = '11111111-1111-4111-8111-111111111111';
    $revisionDocB = '22222222-2222-4222-8222-222222222222';
    $decisionA = ['document_id' => $revisionDocA, 'playthrough_id' => '1', 'request_id' => '33333333-3333-4333-8333-333333333333', 'actor_id' => '42'];
    $decisionA2 = [...$decisionA, 'actor_id' => '44'];
    $decisionB = ['document_id' => $revisionDocB, 'playthrough_id' => '1', 'request_id' => '44444444-4444-4444-8444-444444444444', 'actor_id' => '43'];
    $revisionDecisions = [$revisionDocB => [$decisionB], $revisionDocA => [$decisionA, $decisionA2]];
    $revision = qd_correspondence_source_revision('player', '1', [], [], $revisionDecisions);
    $reorderedRevision = qd_correspondence_source_revision('player', '1', [], [], [$revisionDocA => [$decisionA2, $decisionA], $revisionDocB => [$decisionB]]);
    $removedDecisionRevision = qd_correspondence_source_revision('player', '1', [], [], [$revisionDocB => [$decisionB]]);
    $mismatchedDecisionRevision = qd_correspondence_source_revision('player', '1', [], [], [$revisionDocA => [[...$decisionA, 'actor_id' => '44']], $revisionDocB => [$decisionB]]);
    dev23Expect($revision === $reorderedRevision && $revision !== $removedDecisionRevision && $revision !== $mismatchedDecisionRevision,
        'Mailbox revisions ignored decision ownership changes or depended on result ordering.');
    dev23Expect(qd_correspondence_source_revision('player', '1', [], []) === qd_correspondence_source_revision('player', '1', [], [], null),
        'Legacy mailbox revision bytes changed when no dev.23 ownership map is supplied.');
    if (!class_exists('Dev23ProjectStore', false)) {
        class Dev23ProjectStore implements CorrespondenceStore
        {
            public function __construct(private Dev23OwnerStore $fixture) {}
            public function transaction(callable $work): mixed { return $work(); }
            public function readTransaction(callable $work): mixed { return $work(); }
            public function scope(): array { return ['playthrough_id' => '1', 'ts' => '1', 'gamets' => '1']; }
            public function actors(): array { return [['id' => 42, 'npc_name' => 'Lydia', 'refid' => '0100002A', 'profile_id' => '1']]; }
            public function player(): string { return 'Dragonborn'; }
            public function lockActorRows(array $npcIds, bool $includePlayer = false): array { return ['actors' => [42 => ['id' => 42, 'npc_name' => 'Lydia']], 'player_name' => 'Dragonborn']; }
            public function findRequest(string $scope, string $request): ?array { return null; }
            public function loadDocument(string $id): ?array { return $this->fixture->loadDocument($id); }
            public function saveDocument(array $document): void { throw new LogicException('read_only_correspondence_store'); }
            public function documents(string $scope, ?int $recipientId = null): array { return []; }
            public function loadOperation(string $id): ?array { return $this->fixture->loadOperation($id); }
            public function saveOperation(array $operation): void { throw new LogicException('read_only_correspondence_store'); }
            public function appendEvent(string $type, string $data, string $people, string $sess): array { throw new LogicException('read_only_correspondence_store'); }
            public function eventMatches(array $event): bool { return $this->fixture->eventMatches($event); }
            public function eventRow(array $event): ?array { return $this->fixture->eventRow($event); }
            public function managedExecutor(): ?string { return null; }
            public function prepareNote(string $title, string $body): void { throw new LogicException('read_only_correspondence_store'); }
            public function queueCommand(string $actor, string $action): void { throw new LogicException('read_only_correspondence_store'); }
            public function physicalActor(array $actor): array { return ($actor['kind'] ?? null) === 'npc' ? ['kind' => 'npc', 'id' => 42, 'form_id' => 42000042] : ['kind' => 'player', 'id' => null, 'form_id' => 1]; }
            public function physicalItem(array $item): array { throw new LogicException('unexpected_attachment_resolution'); }
            public function query(string $sql, array $parameters = []): array { return $this->fixture->query($sql, $parameters); }
        }
    }
    [$ownerDocument, $ownerAnchor] = dev23Document();
    $store = new Dev23OwnerStore();
    $store->events['1'] = $ownerAnchor;
    $store->decisionRows[] = dev23Decision($ownerDocument, status: 'failed');
    dev23Expect(qd_correspondence_document_integrity($store, $ownerDocument), 'An exact per-document owner row did not authorize its NPC-payer document.');

    $manualStore = new Dev23OwnerStore();
    $manualStore->decisionTableExists = true;
    [$manualDocument, $manualAnchor] = dev23Document(manualPayer: true);
    $manualDocument['attachments'] = [
        ['kind' => 'gold', 'form_id' => 'Skyrim.esm|0000000F', 'quantity' => 5],
        ['kind' => 'item', 'form_id' => 'Skyrim.esm|0003A070', 'quantity' => 1],
    ];
    $manualRequest = ['request_id' => $manualDocument['request_id'], 'author' => 'npc:42', 'payer' => 'player', 'recipient' => 'player', 'claimed_sender' => 'npc:42', 'body' => $manualDocument['body'], 'attachments' => $manualDocument['attachments']];
    $manualDocument['request_hash'] = hash('sha256', correspondenceCanonicalJson($manualRequest));
    $manualStore->events['1'] = $manualAnchor;
    dev23Expect(qd_correspondence_document_integrity($manualStore, $manualDocument), 'Ordinary player-payer compose with NPC writer/signature and attachments no longer passes when no companion row exists.');
    dev23Expect($manualStore->decisionTableExists, 'The manual document fixture did not exercise an installed owner table.');
    $manualWithoutOwnerTable = new Dev23OwnerStore();
    $manualWithoutOwnerTable->decisionTableExists = false;
    $manualWithoutOwnerTable->events['1'] = $manualAnchor;
    dev23Expect(qd_correspondence_document_integrity($manualWithoutOwnerTable, $manualDocument), 'Ordinary player-payer compose failed when the companion migration is absent.');

    foreach ([
        dev23Decision($ownerDocument, actorId: 43),
        dev23Decision($ownerDocument, requestId: '99999999-9999-4999-8999-999999999999'),
        dev23Decision($ownerDocument, playthroughId: '2'),
    ] as $mismatchedDecision) {
        $mismatchStore = new Dev23OwnerStore();
        $mismatchStore->events['1'] = $ownerAnchor;
        $mismatchStore->decisionRows[] = $mismatchedDecision;
        dev23Expect(!qd_correspondence_document_integrity($mismatchStore, $ownerDocument), 'Mismatched owner request/scope/actor identity authorized an NPC-payer document.');
    }
    $unownedStore = new Dev23OwnerStore();
    $unownedStore->events['1'] = $ownerAnchor;
    dev23Expect(!qd_correspondence_document_integrity($unownedStore, $ownerDocument), 'An unowned NPC-payer document was accepted.');
    $attachedDocument = $ownerDocument;
    $attachedDocument['attachments'] = [['kind' => 'gold', 'form_id' => 'Skyrim.esm|0000000F', 'quantity' => 1]];
    $attachedRequest = ['request_id' => $attachedDocument['request_id'], 'author' => 'npc:42', 'payer' => 'npc:42', 'recipient' => 'player', 'claimed_sender' => 'npc:42', 'body' => $attachedDocument['body'], 'attachments' => $attachedDocument['attachments']];
    $attachedDocument['request_hash'] = hash('sha256', correspondenceCanonicalJson($attachedRequest));
    $attachedStore = new Dev23OwnerStore();
    $attachedStore->events['1'] = $ownerAnchor;
    $attachedStore->decisionRows[] = dev23Decision($attachedDocument);
    dev23Expect(!qd_correspondence_document_integrity($attachedStore, $attachedDocument), 'An owner decision with an attachment was accepted.');

    $projectStore = new Dev23OwnerStore();
    [$closedDocument, $closedAnchor] = dev23Document(closed: true);
    [$operation, $dispatchAnchor, $receiptAnchor, $physicalNpc, $physicalPlayer] = dev23Operation($closedDocument);
    $projectStore->documents[$closedDocument['document_id']] = $closedDocument;
    $projectStore->operations[$operation['operation_id']] = $operation;
    foreach ([$closedAnchor, $dispatchAnchor, $receiptAnchor] as $event) $projectStore->events[(string)$event['rowid']] = $event;
    $projectStore->decisionRows[] = dev23Decision($closedDocument, status: 'unknown');
    $physicalActor = static fn(array $actor): array => ($actor['kind'] ?? null) === 'npc' ? $physicalNpc : $physicalPlayer;
    $delivered = qd_correspondence_project_result(new CorrespondenceService(new Dev23ProjectStore($projectStore)), $projectStore, null, $closedDocument['document_id'], $closedDocument['request_id'], null, $physicalActor);
    dev23Expect(($delivered['state'] ?? null) === 'delivered' && ($delivered['receipt']['deliveryConfirmed'] ?? false) === true, 'Owner NPC-payer note delivery was not confirmed through the pinned receipt checks.');
    dev23Expect(($delivered['companionSchedulerOwned'] ?? null) === true && ($delivered['operatorClosed'] ?? null) === true, 'A valid owner-closed compose result omitted its independent metadata.');

    $unknownOperation = $operation;
    $unknownOperation['receipt']['status'] = 'unknown';
    $unknownOperation['receipt']['reason'] = 'uncertain';
    $unknownOperation['receipt']['note_form_id'] = 0;
    $unknownOperation['receipt_anchor']['data'] = correspondenceJson([
        'document_id' => $closedDocument['document_id'],
        'operation_id' => $operation['operation_id'],
        'receipt_hash' => hash('sha256', correspondenceJson($unknownOperation['receipt'])),
    ]);
    $projectStore->operations[$operation['operation_id']] = $unknownOperation;
    $projectStore->events['4'] = $unknownOperation['receipt_anchor'];
    $unknown = qd_correspondence_project_result(new CorrespondenceService(new Dev23ProjectStore($projectStore)), $projectStore, null, $closedDocument['document_id'], $closedDocument['request_id'], null, $physicalActor);
    dev23Expect(($unknown['state'] ?? null) === 'unknown' && ($unknown['operatorClosed'] ?? null) === true
        && ($unknown['companionSchedulerOwned'] ?? null) === true, 'Operator closure collapsed or replaced the independent unknown delivery state.');
    dev23Expect(!array_key_exists('closed', $unknown), 'Operator closure was projected as a delivery state.');

    $mismatchedOwnerStore = new Dev23OwnerStore();
    [$mismatchedOwnerDocument, $mismatchedOwnerAnchor] = dev23Document(closed: true);
    [$mismatchedOperation, $mismatchedDispatch, $mismatchedReceiptAnchor] = dev23Operation($mismatchedOwnerDocument, 'unknown');
    $mismatchedOwnerStore->documents[$mismatchedOwnerDocument['document_id']] = $mismatchedOwnerDocument;
    $mismatchedOwnerStore->operations[$mismatchedOperation['operation_id']] = $mismatchedOperation;
    foreach ([$mismatchedOwnerAnchor, $mismatchedDispatch, $mismatchedReceiptAnchor] as $event) $mismatchedOwnerStore->events[(string)$event['rowid']] = $event;
    $mismatchedOwnerStore->decisionRows[] = dev23Decision($mismatchedOwnerDocument, actorId: 43, status: 'stale');
    $mismatchedOwner = qd_correspondence_project_result(new CorrespondenceService(new Dev23ProjectStore($mismatchedOwnerStore)), $mismatchedOwnerStore, null, $mismatchedOwnerDocument['document_id'], $mismatchedOwnerDocument['request_id']);
    dev23Expect(($mismatchedOwner['state'] ?? null) === 'unknown'
        && ($mismatchedOwner['companionSchedulerOwned'] ?? null) === true
        && ($mismatchedOwner['operatorClosed'] ?? null) === false,
        'A mismatched stale owner row lost its lock or became a closed/delivered document.');

    fwrite(STDOUT, "PASS: exact {$ownerVersion} closure and drift refusal, retained historical pins, owner-only NPC payer validation, read-only receipt mapping, and independent projections\n");
} finally {
    unset($GLOBALS['DEV23_TEST_CORE_ROOT']);
    dev23Cleanup($tempRoot);
}
