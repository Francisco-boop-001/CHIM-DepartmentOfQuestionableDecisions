<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/protocol.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/dispatcher.php';
require_once __DIR__ . '/../../api.php';

$checks = 0;
function expect(bool $ok, string $why): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($why);
    }
    $checks++;
}

function eq(mixed $expected, mixed $actual, string $why): void
{
    expect($expected === $actual, $why . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

function uuid(): string
{
    return sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));
}

function request(string $operation, ?string $serverContext, string $viewToken, string $generation, array $payload = [], ?string $requestId = null): stdClass
{
    $wire = [
        'version' => 1,
        'requestId' => $requestId ?? uuid(),
        'operation' => $operation,
        'context' => (object)[
            'viewToken' => $viewToken,
            'nativeGeneration' => $generation,
            'serverContext' => $serverContext,
        ],
        'payload' => (object)$payload,
    ];
    return json_decode(json_encode($wire, JSON_THROW_ON_ERROR), false, 8, JSON_THROW_ON_ERROR);
}

function wireError(array $response, string $code): void
{
    expect(($response['ok'] ?? null) === false, 'expected error ' . $code);
    eq($code, $response['error']['code'] ?? null, 'wrong wire error code');
    $message = $response['error']['message'] ?? null;
    expect(is_string($message) && strlen($message) <= 256, 'error message must be fixed, safe and <=256 bytes');
}

function bodyData(array $response): object
{
    $wire = json_decode(json_encode($response, JSON_THROW_ON_ERROR), false, 8, JSON_THROW_ON_ERROR);
    expect(is_object($wire) && ($wire->ok ?? null) === true && is_object($wire->data ?? null), 'expected success data object: ' . json_encode($wire, JSON_UNESCAPED_SLASHES));
    return $wire->data;
}

function fixture(array $scopes = ['pcv', 'inspector', 'compose']): array
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-foundation-' . bin2hex(random_bytes(8));
    $webRoot = $root . DIRECTORY_SEPARATOR . 'web';
    $private = $root . DIRECTORY_SEPARATOR . 'private';
    mkdir($webRoot, 0700, true);
    mkdir($private, 0700, true);
    $pairingPath = $private . DIRECTORY_SEPARATOR . 'pairing.json';
    $statePath = $private . DIRECTORY_SEPARATOR . 'state.json';
    $pair = qd_pair_issue($pairingPath, $webRoot, $scopes);
    $trace = (object)['calls' => [], 'privateCalls' => 0, 'scope' => 'synthetic-active-save-7', 'readyCalls' => 0, 'perspectiveCalls' => 0];
    $deps = [
        'currentScope' => static function (string $pairId) use ($trace): ?string {
            return $trace->scope;
        },
        'readiness' => static function () use ($trace): array {
            $trace->readyCalls++;
            return ['pcv' => true, 'mp' => true, 'sas' => true, 'correspondence' => true];
        },
        'perspectives' => static function (string $scopeKey) use ($trace): array {
            $trace->perspectiveCalls++;
            return [
                'mp' => ['choices' => [['actorKey' => 'mp:npc-1', 'label' => 'Aela']], 'limited' => false],
                'sas' => ['choices' => [['actorKey' => 'sas:npc-2', 'label' => 'Lydia']], 'limited' => false],
            ];
        },
        'operations' => [],
    ];
    foreach (['pcv.status.read', 'mp.relationships.read', 'sas.issues.read', 'correspondence.detail.read', 'correspondence.mailbox.read'] as $operation) {
        $deps['operations'][$operation] = static function (stdClass $payload, array $auth) use ($trace, $operation): array {
            $trace->calls[] = [$operation, $payload];
            $trace->privateCalls++;
            if ($operation === 'mp.relationships.read') {
                return ['perspectiveActorKey' => $payload->perspectiveActorKey, 'records' => [[
                    'id' => 'record-1', 'owner' => 'Aela', 'target' => 'Lydia', 'at' => 'day 4',
                    'proposedDelta' => 0, 'appliedDelta' => 0, 'historicalBefore' => 'friend',
                    'historicalAfter' => 'foe', 'currentValue' => '-2', 'currentAsOf' => 'day 9',
                    'sourceKind' => 'review', 'fixedReason' => 'Relationship changed', 'kind' => 'arrangement', 'confirmation' => 'confirmed',
                ]], 'limited' => false, 'nextCursor' => null];
            }
            if ($operation === 'correspondence.detail.read') {
                return ['letterId' => $payload->letterId, 'claimedSignature' => 'Dragonborn', 'body' => 'Delivered text', 'recordedAuthor' => 'private-author'];
            }
            return [];
        };
    }
    $deps['operations']['correspondence.catalog.read'] = static function () use ($trace): array {
        $trace->calls[] = ['correspondence.catalog.read'];
        return ['actors' => [], 'limited' => false, 'nextCursor' => null];
    };
    $deps['operations']['pcv.arm'] = static function (stdClass $payload, array $auth) use ($trace): array {
        ($auth['assertCurrent'])();
        ($auth['beginCommit'])();
        $trace->calls[] = ['pcv.arm', $payload];
        return ['operationId' => $payload->operationId, 'state' => 'staged'];
    };
    $deps['operations']['pcv.end'] = static function (stdClass $payload, array $auth) use ($trace): array {
        ($auth['assertCurrent'])();
        ($auth['beginCommit'])();
        $trace->calls[] = ['pcv.end', $payload];
        return ['operationId' => $payload->operationId, 'state' => 'ended'];
    };
    $deps['operations']['correspondence.compose'] = static function (stdClass $payload, array $auth) use ($trace): array {
        ($auth['assertCurrent'])();
        ($auth['beginCommit'])();
        $trace->calls[] = ['correspondence.compose', $payload];
        return ['operationId' => $payload->operationId, 'state' => 'stored'];
    };
    $deps['operations']['correspondence.reconcile'] = static function (stdClass $payload, array $auth) use ($trace): array {
        $trace->calls[] = ['correspondence.reconcile', $payload];
        return ['operationId' => $payload->operationId, 'state' => 'unknown'];
    };
    $settings = ['pairingPath' => $pairingPath, 'statePath' => $statePath, 'webRoot' => $webRoot];
    $send = static function (stdClass $request, ?string $token = null) use (&$pair, $settings, $deps): array {
        return qd_server_dispatch($request, $token ?? ('Bearer ' . $pair['token']), $settings, $deps);
    };
    return compact('root', 'webRoot', 'private', 'pairingPath', 'statePath', 'pair', 'trace', 'settings', 'deps', 'send');
}
function cleanup(string $root): void
{
    if (!is_dir($root)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}

$env = fixture();
try {
    $view = uuid();
    $boot = request('bootstrap', null, $view, '17');
    $authorizedBoot = $env['send']($boot);
    $bootData = bodyData($authorizedBoot);
    eq(json_encode($boot->context, JSON_THROW_ON_ERROR), json_encode($authorizedBoot['context'], JSON_THROW_ON_ERROR), 'bootstrap must echo every context field exactly');
    eq(null, $authorizedBoot['context']->serverContext, 'bootstrap request context remains null');
    expect(is_string($bootData->serverContext) && strlen($bootData->serverContext) <= 128, 'fresh opaque context must be returned in data');
    expect(is_string($bootData->scopeToken) && preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $bootData->scopeToken) === 1, 'authenticated bootstrap must return an opaque 32-byte scope token');
    expect(!property_exists($authorizedBoot['context'], 'newServerContext'), 'bootstrap context must not be rewritten in the response envelope');
    eq(['pcv' => true, 'mp' => true, 'sas' => true, 'correspondence' => true], (array)$bootData->capabilities, 'readiness flags must remain separate from access scopes');
    eq(['pcv' => true, 'inspector' => true, 'compose' => true, 'authorDetail' => false], (array)$bootData->access, 'access flags must derive from token scopes');
    eq(1, $env['trace']->readyCalls, 'authenticated bootstrap may read readiness');
    eq(1, $env['trace']->perspectiveCalls, 'inspector bootstrap may read bounded perspectives');

    $contextOnly = request('mp.relationships.read', $bootData->serverContext, $view, '17', ['perspectiveActorKey' => 'mp:npc-1']);
    wireError(qd_server_dispatch($contextOnly, null, $env['settings'], $env['deps']), 'unauthorized');
    eq(0, count($env['trace']->calls), 'serverContext alone never authorizes a private read');

    $oldContext = $bootData->serverContext;
    $view2 = uuid();
    $boot2 = request('bootstrap', null, $view2, '17');
    $boot2Data = bodyData($env['send']($boot2));
    expect($boot2Data->serverContext !== $oldContext, 'a fresh same-profile view must retire the old paired context');
    eq($bootData->scopeToken, $boot2Data->scopeToken, 'same pair and authoritative scope retain the scope token across view reopen');
    $oldRead = request('mp.relationships.read', $oldContext, $view, '17', ['perspectiveActorKey' => 'mp:npc-1']);
    $stale = $env['send']($oldRead);
    wireError($stale, 'stale_context');
    eq(0, count($env['trace']->calls), 'stale context must stop before any plugin callback');

    $newContext = $boot2Data->serverContext;
    $boot3 = request('bootstrap', null, $view2, '18');
    $boot3Data = bodyData($env['send']($boot3));
    expect($boot3Data->serverContext !== $newContext, 'a generation change must retire context even for the same profile/view');
    eq($boot2Data->scopeToken, $boot3Data->scopeToken, 'same authoritative scope retains its draft identity across generation bootstrap');
    $generationStale = request('mp.relationships.read', $newContext, $view2, '17', ['perspectiveActorKey' => 'mp:npc-1']);
    wireError($env['send']($generationStale), 'stale_context');

    $currentContext = $boot3Data->serverContext;
    $goodRead = request('mp.relationships.read', $currentContext, $view2, '18', ['perspectiveActorKey' => 'mp:npc-1', 'limit' => 25]);
    $mpData = bodyData($env['send']($goodRead));
    eq('friend', $mpData->records[0]->historicalBefore, 'historical MP values may remain bounded strings');
    eq('-2', $mpData->records[0]->currentValue, 'current MP values may remain bounded strings');
    eq(1, count($env['trace']->calls), 'one valid read should call exactly one adapter');
    $env['send']($goodRead);
    eq(2, count($env['trace']->calls), 'pure reads are never response-cached');

    $inspectorOnly = fixture(['inspector']);
    try {
        $restrictedView = uuid();
        $restrictedBoot = bodyData($inspectorOnly['send'](request('bootstrap', null, $restrictedView, '1')));
        bodyData($inspectorOnly['send'](request('correspondence.catalog.read', $restrictedBoot->serverContext, $restrictedView, '1')));
        $forbidden = request('pcv.status.read', $restrictedBoot->serverContext, $restrictedView, '1');
        wireError($inspectorOnly['send']($forbidden), 'forbidden');
        $secondRestrictedBoot = bodyData($inspectorOnly['send'](request('bootstrap', null, uuid(), '2')));
        expect($secondRestrictedBoot->access->pcv === false, 'inspector scope must not grant pcv');
    } finally {
        cleanup($inspectorOnly['root']);
    }

    $composeOnly = fixture(['compose']);
    try {
        $composeView = uuid();
        $composeBoot = bodyData($composeOnly['send'](request('bootstrap', null, $composeView, '1')));
        bodyData($composeOnly['send'](request('correspondence.catalog.read', $composeBoot->serverContext, $composeView, '1')));
        eq(1, count($composeOnly['trace']->calls), 'compose scope alone also grants the catalog choice list');
    } finally {
        cleanup($composeOnly['root']);
    }

    $scopeChangeEnv = fixture(['inspector']);
    try {
        $scopeViewA = uuid();
        $scopeBootA = bodyData($scopeChangeEnv['send'](request('bootstrap', null, $scopeViewA, '1')));
        $scopeChangeEnv['trace']->scope = 'synthetic-active-save-8';
        $scopeViewB = uuid();
        $scopeBootB = bodyData($scopeChangeEnv['send'](request('bootstrap', null, $scopeViewB, '2')));
        expect($scopeBootA->scopeToken !== $scopeBootB->scopeToken, 'a real authoritative scope change rotates the scope token');
        expect($scopeBootA->serverContext !== $scopeBootB->serverContext, 'a real authoritative scope change also gets a fresh server context');
    } finally {
        cleanup($scopeChangeEnv['root']);
    }

    $authorDetailEnv = fixture(['inspector', 'author_detail']);
    try {
        $authorView = uuid();
        $authorBoot = bodyData($authorDetailEnv['send'](request('bootstrap', null, $authorView, '1')));
        expect(((array)$authorBoot->access)['authorDetail'] === true, 'author_detail must derive from the explicit issued scope');
        $authorDetail = bodyData($authorDetailEnv['send'](request('correspondence.detail.read', $authorBoot->serverContext, $authorView, '1', ['recipientActorKey' => 'npc:recipient', 'letterId' => 'doc-1'])));
        eq('private-author', $authorDetail->recordedAuthor ?? null, 'recorded author is visible only with author_detail scope');
    } finally {
        cleanup($authorDetailEnv['root']);
    }

    $revokedDuringRead = fixture(['inspector']);
    try {
        $revokedDeps = $revokedDuringRead['deps'];
        $revokedDeps['operations']['mp.relationships.read'] = static function () use ($revokedDuringRead): array {
            qd_pair_revoke($revokedDuringRead['pairingPath'], $revokedDuringRead['webRoot'], $revokedDuringRead['pair']['pairId']);
            return ['perspectiveActorKey' => 'mp:npc-1', 'records' => [], 'limited' => false, 'nextCursor' => null, 'privateMarker' => 'must-not-escape'];
        };
        $revokedView = uuid();
        $revokedBoot = bodyData(qd_server_dispatch(request('bootstrap', null, $revokedView, '1'), 'Bearer ' . $revokedDuringRead['pair']['token'], $revokedDuringRead['settings'], $revokedDeps));
        $revokedRequest = request('mp.relationships.read', $revokedBoot->serverContext, $revokedView, '1', ['perspectiveActorKey' => 'mp:npc-1']);
        $revokedResponse = qd_server_dispatch($revokedRequest, 'Bearer ' . $revokedDuringRead['pair']['token'], $revokedDuringRead['settings'], $revokedDeps);
        wireError($revokedResponse, 'unauthorized');
        expect(!str_contains(json_encode($revokedResponse, JSON_THROW_ON_ERROR), 'must-not-escape'), 'revocation during a slow read discards its private result');
    } finally {
        cleanup($revokedDuringRead['root']);
    }

    $detail = request('correspondence.detail.read', $currentContext, $view2, '18', ['recipientActorKey' => 'npc:recipient', 'letterId' => 'doc-1']);
    $detailData = bodyData($env['send']($detail));
    expect(!property_exists($detailData, 'recordedAuthor'), 'recorded author must be removed without author_detail scope');

    $composePayload = [
        'operationId' => uuid(), 'writerActorKey' => 'npc:writer', 'recipientActorKey' => 'npc:recipient',
        'body' => 'A private letter', 'inventoryRevision' => 'inventory-r1',
        'attachments' => [(object)['form_id' => 'Skyrim.esm|0000000A', 'quantity' => 2]], 'goldAmount' => 5,
    ];
    $compose = request('correspondence.compose', $currentContext, $view2, '18', $composePayload);
    eq(null, qd_validate_request($compose), 'native-normalized compose payload should use owner form_id plus separate goldAmount');
    $composeFirst = bodyData($env['send']($compose));
    $composeReplay = bodyData($env['send']($compose));
    eq((array)$composeFirst, (array)$composeReplay, 'compose replay returns the stored result');
    eq(1, count(array_filter($env['trace']->calls, static fn(array $call): bool => $call[0] === 'correspondence.compose')), 'compose replay must not call its adapter twice');
    $composeChanged = request('correspondence.compose', $currentContext, $view2, '18', $composePayload);
    $composeChanged->payload->body = 'Changed body';
    wireError($env['send']($composeChanged), 'conflict');

    $badForm = request('correspondence.compose', $currentContext, $view2, '18', $composePayload);
    $badForm->payload->attachments[0]->form_id = 'Skyrim.esm|not-a-form';
    eq('invalid_request', qd_validate_request($badForm), 'unsupported form-id grammar must be rejected');
    $duplicateGold = request('correspondence.compose', $currentContext, $view2, '18', $composePayload);
    $duplicateGold->payload->attachments[0]->form_id = 'Skyrim.esm|0000000F';
    eq('invalid_request', qd_validate_request($duplicateGold), 'gold cannot be smuggled as a non-gold attachment');
    $tooManyWithGold = request('correspondence.compose', $currentContext, $view2, '18', $composePayload);
    $tooManyWithGold->payload->attachments = array_fill(0, 8, (object)['form_id' => 'Skyrim.esm|00000001', 'quantity' => 1]);
    eq('invalid_request', qd_validate_request($tooManyWithGold), 'gold must count toward the eight attachment cap');

    $reconcile = request('correspondence.reconcile', $currentContext, $view2, '18', ['operationId' => uuid()]);
    $env['send']($reconcile);
    $env['send']($reconcile);
    eq(2, count(array_filter($env['trace']->calls, static fn(array $call): bool => $call[0] === 'correspondence.reconcile')), 'reconcile remains a fresh read on every call');

    $env['trace']->scope = 'synthetic-active-save-8';
    $changedScope = request('mp.relationships.read', $currentContext, $view2, '18', ['perspectiveActorKey' => 'mp:npc-1']);
    wireError($env['send']($changedScope), 'stale_context');
    $env['trace']->scope = 'synthetic-active-save-7';

    $unknown = request('arbitrary.proxy', $currentContext, $view2, '18', ['url' => 'http://127.0.0.1/']);
    wireError($env['send']($unknown), 'unsupported_operation');
    foreach (['view.close', 'pcv.send', 'correspondence.inventory.read'] as $nativeOnly) {
        wireError($env['send'](request($nativeOnly, $currentContext, $view2, '18')), 'unsupported_operation');
    }

    $invalidPayload = request('mp.relationships.read', $currentContext, $view2, '18', ['perspectiveActorKey' => 'mp:npc-1', 'unexpected' => 'x']);
    wireError($env['send']($invalidPayload), 'invalid_request');
    $invalidContext = request('mp.relationships.read', $currentContext, $view2, '18', ['perspectiveActorKey' => 'mp:npc-1']);
    $invalidContext->context->unexpected = true;
    wireError($env['send']($invalidContext), 'invalid_request');

    $operationId = uuid();
    $arm = request('pcv.arm', $currentContext, $view2, '18', [
        'operationId' => $operationId,
        'expectedActiveConfigId' => null,
        'expectedPendingConfigId' => null,
        'configuration' => ['enabled' => true, 'scene_mode' => 'solo', 'actor_a' => 'npc:22', 'exclude_player' => true, 'bystander_mode' => 'exclude'],
    ]);
    $firstArm = bodyData($env['send']($arm));
    $armReplay = bodyData($env['send']($arm));
    eq((array)$firstArm, (array)$armReplay, 'same operationId and payload must replay the recorded outcome');
    eq(1, count(array_filter($env['trace']->calls, static fn(array $call): bool => $call[0] === 'pcv.arm')), 'mutation replay must not dispatch a second time');
    $changedArm = request('pcv.arm', $currentContext, $view2, '18', [
        'operationId' => $operationId,
        'expectedActiveConfigId' => null,
        'expectedPendingConfigId' => null,
        'configuration' => ['enabled' => true, 'scene_mode' => 'solo', 'actor_a' => 'npc:33', 'exclude_player' => true, 'bystander_mode' => 'exclude'],
    ]);
    wireError($env['send']($changedArm), 'conflict');
    eq(1, count(array_filter($env['trace']->calls, static fn(array $call): bool => $call[0] === 'pcv.arm')), 'changed payload under a reused ID must stop before callback');

    qd_pair_revoke($env['pairingPath'], $env['webRoot'], $env['pair']['pairId']);
    wireError($env['send']($arm), 'unauthorized');
    eq(1, count(array_filter($env['trace']->calls, static fn(array $call): bool => $call[0] === 'pcv.arm')), 'cached mutation outcome still requires fresh authentication');

    $unconfiguredRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-foundation-locked-' . bin2hex(random_bytes(8));
    mkdir($unconfiguredRoot, 0700, true);
    try {
        $counts = ['scope' => 0, 'ready' => 0, 'perspectives' => 0, 'operation' => 0];
        $lockedDeps = [
            'currentScope' => static function () use (&$counts): ?string { $counts['scope']++; return 'should-not-run'; },
            'readiness' => static function () use (&$counts): array { $counts['ready']++; return []; },
            'perspectives' => static function () use (&$counts): array { $counts['perspectives']++; return []; },
            'operations' => ['mp.relationships.read' => static function () use (&$counts): array { $counts['operation']++; return []; }],
        ];
        $settings = ['pairingPath' => $unconfiguredRoot . DIRECTORY_SEPARATOR . 'absent.json', 'statePath' => $unconfiguredRoot . DIRECTORY_SEPARATOR . 'state.json', 'webRoot' => $unconfiguredRoot . DIRECTORY_SEPARATOR . 'web'];
        $locked = bodyData(qd_server_dispatch(request('bootstrap', null, uuid(), '1'), null, $settings, $lockedDeps));
        eq(false, $locked->authenticated, 'unconfigured bootstrap must remain locked');
        eq(['pcv' => false, 'mp' => false, 'sas' => false, 'correspondence' => false], (array)$locked->capabilities, 'unconfigured runtime must report unavailable capabilities');
        eq(['pcv' => false, 'inspector' => false, 'compose' => false, 'authorDetail' => false], (array)$locked->access, 'unconfigured bootstrap must deny all access');
    eq(null, $locked->scopeToken ?? null, 'unauthenticated bootstrap returns a null scope token');
        eq(['scope' => 0, 'ready' => 0, 'perspectives' => 0, 'operation' => 0], $counts, 'unauthenticated bootstrap must invoke no private callback');
        wireError(qd_server_dispatch(request('mp.relationships.read', uuid(), uuid(), '1', ['perspectiveActorKey' => 'mp:npc-1']), 'Bearer invalid', $settings, $lockedDeps), 'unauthorized');
        eq(['scope' => 0, 'ready' => 0, 'perspectives' => 0, 'operation' => 0], $counts, 'unauthorized private calls must invoke no callback');
        expect(!file_exists($settings['pairingPath']) && !file_exists($settings['pairingPath'] . '.lock'), 'failed authentication must not create a default pairing file or lock');
    } finally {
        cleanup($unconfiguredRoot);
    }

    $badEnv = fixture(['inspector']);
    $badDeps = $badEnv['deps'];
    $badDeps['operations']['mp.relationships.read'] = static function (): array { throw new RuntimeException('SQL secret /private/path'); };
    try {
        $badView = uuid();
        $badBoot = bodyData(qd_server_dispatch(request('bootstrap', null, $badView, '1'), 'Bearer ' . $badEnv['pair']['token'], $badEnv['settings'], $badDeps));
        $badRequest = request('mp.relationships.read', $badBoot->serverContext, $badView, '1', ['perspectiveActorKey' => 'mp:npc-1']);
        $safeError = qd_server_dispatch($badRequest, 'Bearer ' . $badEnv['pair']['token'], $badEnv['settings'], $badDeps);
        wireError($safeError, 'unavailable');
        expect(!str_contains(json_encode($safeError, JSON_THROW_ON_ERROR), 'SQL secret') && !str_contains(json_encode($safeError, JSON_THROW_ON_ERROR), '/private/path'), 'exception text and paths must not escape');
    } finally {
        cleanup($badEnv['root']);
    }

    $ambiguousEnv = fixture(['pcv']);
    try {
        $ambiguousDeps = $ambiguousEnv['deps'];
        $ambiguousCalls = 0;
        $ambiguousDeps['operations']['pcv.arm'] = static function (stdClass $payload, array $auth) use (&$ambiguousCalls): array {
            ($auth['assertCurrent'])();
            ($auth['beginCommit'])();
            $ambiguousCalls++;
            throw new RuntimeException('commit outcome is ambiguous');
        };
        $ambiguousView = uuid();
        $ambiguousBoot = bodyData(qd_server_dispatch(request('bootstrap', null, $ambiguousView, '1'), 'Bearer ' . $ambiguousEnv['pair']['token'], $ambiguousEnv['settings'], $ambiguousDeps));
        $ambiguousPayload = [
            'operationId' => uuid(), 'expectedActiveConfigId' => null, 'expectedPendingConfigId' => null,
            'configuration' => ['enabled' => true, 'scene_mode' => 'solo', 'actor_a' => 'npc:22', 'exclude_player' => true, 'bystander_mode' => 'exclude'],
        ];
        $ambiguousRequest = request('pcv.arm', $ambiguousBoot->serverContext, $ambiguousView, '1', $ambiguousPayload);
        wireError(qd_server_dispatch($ambiguousRequest, 'Bearer ' . $ambiguousEnv['pair']['token'], $ambiguousEnv['settings'], $ambiguousDeps), 'unavailable');
        wireError(qd_server_dispatch($ambiguousRequest, 'Bearer ' . $ambiguousEnv['pair']['token'], $ambiguousEnv['settings'], $ambiguousDeps), 'conflict');
        $retryWithNewId = request('pcv.arm', $ambiguousBoot->serverContext, $ambiguousView, '1', $ambiguousPayload);
        $retryWithNewId->payload->operationId = uuid();
        wireError(qd_server_dispatch($retryWithNewId, 'Bearer ' . $ambiguousEnv['pair']['token'], $ambiguousEnv['settings'], $ambiguousDeps), 'conflict');
        eq(1, $ambiguousCalls, 'an ambiguous mutation cannot be retried under its old or a new operation ID');
    } finally {
        cleanup($ambiguousEnv['root']);
    }

    $capPayload = (object)['s' => str_repeat('x', QD_MAX_STRING_BYTES + 1)];
    eq('invalid_request', qd_validate_json_tree($capPayload), 'overlong strings must fail common envelope caps');
    eq('invalid_request', qd_validate_json_tree((object)['a' => array_fill(0, QD_MAX_ARRAY_ITEMS + 1, 1)]), 'oversized arrays must fail common caps');
    $tooMany = [];
    for ($i = 0; $i < QD_MAX_OBJECT_PROPERTIES + 1; $i++) { $tooMany['p' . $i] = true; }
    eq('invalid_request', qd_validate_json_tree((object)$tooMany), 'oversized objects must fail common caps');
    $deep = (object)['a' => (object)['b' => (object)['c' => (object)['d' => (object)['e' => (object)['f' => (object)['g' => (object)['h' => true]]]]]]]];
    eq('invalid_request', qd_validate_json_tree($deep), 'nesting beyond eight levels must fail');

    expect(qd_validate_pcv_configuration((object)['enabled' => true, 'scene_mode' => 'pair', 'actor_a' => 'npc:a', 'actor_b' => 'npc:b', 'exclude_player' => true, 'bystander_mode' => 'silent', 'opener' => 'auto', 'card' => str_repeat('é', 300), 'actions' => ['personal', 'physical']]), 'valid pair owner configuration should pass exact byte/character limits');
    expect(qd_validate_pcv_configuration((object)['enabled' => true, 'scene_mode' => 'pair', 'actor_ids' => ['npc:a', 'npc:b', 'npc:c'], 'exclude_player' => true, 'bystander_mode' => 'exclude']), 'valid group owner configuration should pass');
    expect(qd_validate_pcv_configuration((object)['enabled' => true, 'scene_mode' => 'pair', 'free' => true, 'free_cap' => 6, 'exclude_player' => true, 'bystander_mode' => 'exclude']), 'valid free owner configuration should pass');
    expect(!qd_validate_pcv_configuration((object)['enabled' => true, 'scene_mode' => 'pair', 'actor_a' => 'npc:a', 'actor_b' => 'npc:b', 'exclude_player' => true, 'bystander_mode' => 'exclude', 'opener' => str_repeat('é', 65)]), 'opener byte limit must reject 65 two-byte characters');
    expect(!qd_validate_pcv_configuration((object)['enabled' => true, 'scene_mode' => 'pair', 'actor_a' => 'npc:a', 'actor_b' => 'npc:b', 'exclude_player' => true, 'bystander_mode' => 'exclude', 'card' => str_repeat('x', 301)]), 'card character limit must reject 301 characters');
    expect(!qd_validate_pcv_configuration((object)['enabled' => true, 'scene_mode' => 'pair', 'actor_a' => 'npc:a', 'actor_b' => 'npc:b', 'exclude_player' => true, 'bystander_mode' => 'exclude', 'card' => "line one\nline two"]), 'card newlines must be rejected');
    expect(!qd_validate_pcv_configuration((object)['enabled' => true, 'scene_mode' => 'pair', 'actor_a' => 'npc:a', 'actor_b' => 'npc:b', 'exclude_player' => true, 'bystander_mode' => 'exclude', 'free' => false]), 'owner free flag accepts true only, not false');
    expect(!qd_validate_pcv_configuration((object)['enabled' => true, 'scene_mode' => 'pair', 'actor_a' => 'npc:a', 'actor_b' => 'npc:b', 'exclude_player' => true, 'bystander_mode' => 'exclude', 'actions' => ['unsafe']]), 'owner action allowlist rejects unknown values');

    $mpRow = (object)[
        'id' => 'record-2', 'owner' => 'Aela', 'target' => 'Lydia', 'at' => null,
        'proposedDelta' => null, 'appliedDelta' => 2.5, 'historicalBefore' => 'arrangement',
        'historicalAfter' => null, 'currentValue' => -2, 'currentAsOf' => null,
        'sourceKind' => 'review', 'fixedReason' => null, 'kind' => 'type-change', 'confirmation' => 'confirmed',
    ];
    $mpProjection = (object)['perspectiveActorKey' => 'mp:npc-1', 'records' => [$mpRow], 'limited' => false, 'nextCursor' => null];
    expect(qd_validate_mp_projection($mpProjection, 'mp:npc-1'), 'MP response accepts bounded categorical historical values and numeric deltas');
    $mpRow->proposedDelta = '2';
    expect(!qd_validate_mp_projection($mpProjection, 'mp:npc-1'), 'MP numeric deltas reject strings');
    $mpRow->proposedDelta = null;
    $mpRow->historicalAfter = str_repeat('x', 257);
    expect(!qd_validate_mp_projection($mpProjection, 'mp:npc-1'), 'MP categorical values are bounded to 256 bytes');

    $largeSasRequest = request('sas.issues.read', 'opaque', uuid(), '1', ['perspectiveActorKey' => 'sas:npc-1']);
    $largeIssues = [];
    for ($i = 0; $i < 50; $i++) {
        $largeIssues[] = ['id' => 'issue-' . $i, 'kind' => 'promise', 'owner' => 'NPC', 'counterpart' => 'Other', 'direction' => 'owed', 'matter' => str_repeat('m', 2000), 'nature' => str_repeat('n', 512), 'status' => 'open', 'settlement' => 'unknown'];
    }
    $bounded = qd_response_with_bounded_data($largeSasRequest, ['perspectiveActorKey' => 'sas:npc-1', 'issues' => $largeIssues, 'limited' => false, 'nextCursor' => null], false);
    expect(count($bounded['data']->issues) < count($largeIssues) && $bounded['data']->limited === true, 'oversized non-cursor page shrinks and reports omitted rows');
    try {
        qd_response_with_bounded_data($largeSasRequest, ['perspectiveActorKey' => 'sas:npc-1', 'issues' => $largeIssues, 'limited' => false, 'nextCursor' => 'cursor-after-original-page'], false);
        expect(false, 'oversized cursor-bearing page must be rejected to avoid skipping dropped rows');
    } catch (QDServerFault $error) {
        eq('unavailable', $error->wireCode, 'cursor-bearing overflow must fail closed');
    }

    $validRaw = json_encode(request('bootstrap', null, uuid(), '1'), JSON_THROW_ON_ERROR);
    $http = qd_api_handle('POST', 'application/json; charset=utf-8', $validRaw, null, $env['settings'], $env['deps']);
    eq(200, $http['status'], 'bootstrap should be available over fixed JSON HTTP handler');
    expect(!isset($http['headers']['Access-Control-Allow-Origin']) && str_contains(strtolower($http['headers']['Cache-Control'] ?? ''), 'no-store'), 'HTTP handler must omit CORS and disable caching');
    $getResponse = qd_api_handle('GET', 'application/json', $validRaw, null, $env['settings'], $env['deps']);
    eq(405, $getResponse['status'], 'non-POST methods must be rejected');
    eq(json_encode(json_decode($validRaw, false, 8, JSON_THROW_ON_ERROR)->context), json_encode(json_decode($getResponse['body'], false, 8, JSON_THROW_ON_ERROR)->context), 'HTTP protocol errors echo validated request context');
    $mediaResponse = qd_api_handle('POST', 'text/plain', $validRaw, null, $env['settings'], $env['deps']);
    eq(415, $mediaResponse['status'], 'non-JSON requests must be rejected');
    eq('unsupported_media_type', json_decode($mediaResponse['body'], true, 8, JSON_THROW_ON_ERROR)['error']['code'], 'wrong content type gets a fixed safe error');
    eq(413, qd_api_handle('POST', 'application/json', str_repeat('x', QD_MAX_BODY_BYTES + 1), null, $env['settings'], $env['deps'])['status'], 'oversized HTTP bodies must be rejected');
    $invalidJson = qd_api_handle('POST', 'application/json', '{', null, $env['settings'], $env['deps']);
    eq('invalid_request', json_decode($invalidJson['body'], true, 8, JSON_THROW_ON_ERROR)['error']['code'], 'malformed JSON must have a safe protocol error');

    $apiEnv = fixture();
    try {
        $noisyReadinessCalls = 0;
        $noisyDeps = $apiEnv['deps'];
        $noisyDeps['readiness'] = static function () use (&$noisyReadinessCalls): array {
            $noisyReadinessCalls++;
            echo "private auth-core diagnostic\n";
            ob_start();
            echo "private buffered whitespace ";
            return ['pcv' => true, 'mp' => true, 'sas' => true, 'correspondence' => true];
        };
        $apiRaw = json_encode(request('bootstrap', null, uuid(), '1'), JSON_THROW_ON_ERROR);
        $callerBufferLevel = ob_get_level();
        if (!ob_start()) {
            throw new RuntimeException('could not start API output assertion buffer');
        }
        $testBufferLevel = ob_get_level();
        $response = null;
        $incidentalOutput = '';
        try {
            $response = qd_api_handle('POST', 'application/json', $apiRaw, 'Bearer ' . $apiEnv['pair']['token'], $apiEnv['settings'], $noisyDeps);
            $responseBufferLevel = ob_get_level();
            while (ob_get_level() > $testBufferLevel) {
                $incidentalOutput = (string)ob_get_clean() . $incidentalOutput;
            }
            $incidentalOutput = (string)ob_get_contents() . $incidentalOutput;
            eq($testBufferLevel, $responseBufferLevel, 'API dispatch must restore the caller output-buffer level');
            eq('', $incidentalOutput, 'API dispatch must discard incidental business output');
        } finally {
            while (ob_get_level() > $callerBufferLevel) {
                ob_end_clean();
            }
        }
        eq(1, $noisyReadinessCalls, 'authenticated API dispatch must invoke the noisy readiness dependency');
        eq(200, $response['status'], 'authenticated API request remains successful after incidental output');
        eq('application/json; charset=utf-8', $response['headers']['Content-Type'] ?? '', 'API retains its safe JSON content type');
        expect(str_contains(strtolower($response['headers']['Cache-Control'] ?? ''), 'no-store'), 'API retains no-store response headers');
        $safeBody = json_decode($response['body'], false, 8, JSON_THROW_ON_ERROR);
        expect(is_object($safeBody) && ($safeBody->ok ?? null) === true, 'API returns valid safe JSON after incidental output');
        expect(!str_contains($response['body'], 'private auth-core diagnostic'), 'private business output is absent from the JSON body');
    } finally {
        cleanup($apiEnv['root']);
    }
} finally {
    cleanup($env['root']);
}

echo 'PASS ' . $checks . " server foundation assertions\n";



