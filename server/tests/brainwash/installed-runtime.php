<?php
declare(strict_types=1);

/**
 * Exercise the installed Brainwash owner through Department's real PHP facade.
 * Run only as www-data against the named TEST2 fixture and a fresh private root:
 * php installed-runtime.php --fixture-id=ID --private-root=/var/tmp/department-brainwash-ID/private
 * The trusted provision CLI's token is captured in memory and never emitted.
 */
function brainwash_installed_fail(string $code): never
{
    fwrite(STDERR, "brainwash-installed-runtime-failure:" . $code . PHP_EOL);
    exit(2);
}

function brainwash_installed_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function brainwash_installed_response(array $response, string $label): object
{
    if (!is_int($response['status'] ?? null) || $response['status'] < 200 || $response['status'] > 599
        || !is_string($response['body'] ?? null)) {
        brainwash_installed_fail($label . '_http');
    }
    try {
        $wire = json_decode($response['body'], false, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        brainwash_installed_fail($label . '_json');
    }
    if (!is_object($wire)) brainwash_installed_fail($label . '_shape');
    return $wire;
}

function brainwash_installed_expect_data(array $response, string $label): object
{
    if (($response['status'] ?? null) !== 200) brainwash_installed_fail($label . '_http');
    $wire = brainwash_installed_response($response, $label);
    if (($wire->ok ?? null) !== true || !is_object($wire->data ?? null)) {
        brainwash_installed_fail($label . '_' . ($wire->error->code ?? 'refused'));
    }
    return $wire->data;
}

function brainwash_installed_generation(string $coreRoot): string
{
    $runtimeRoot = $coreRoot . '/log/playthrough_runtime';
    $path = $runtimeRoot . '/generation';
    if (is_link($runtimeRoot) || is_link($path)) brainwash_installed_fail('generation_path');
    if (!file_exists($path)) return '';
    if (!is_file($path)) brainwash_installed_fail('generation_file');
    $raw = @file_get_contents($path, false, null, 0, 257);
    if (!is_string($raw) || strlen($raw) > 256) brainwash_installed_fail('generation_size');
    $generation = trim($raw);
    if (preg_match('/\A[0-9a-f]{32}\z/D', $generation) !== 1) brainwash_installed_fail('generation_value');
    return $generation;
}

$options = getopt('', ['fixture-id:', 'private-root:']);
$fixtureId = is_array($options) ? ($options['fixture-id'] ?? null) : null;
$privateInput = is_array($options) ? ($options['private-root'] ?? null) : null;
if (!is_string($fixtureId) || preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}\z/D', $fixtureId) !== 1
    || !is_string($privateInput) || $privateInput === '') {
    brainwash_installed_fail('arguments');
}
if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
    brainwash_installed_fail('unprivileged_linux_only');
}

$fixtureParent = '/var/tmp/department-brainwash-' . $fixtureId;
$privateRoot = $fixtureParent . '/private';
$webRoot = $fixtureParent . '/web';
if ($privateInput !== $privateRoot || is_link('/var/tmp') || realpath('/var/tmp') !== '/var/tmp'
    || realpath($privateRoot) !== $privateRoot || is_link($privateRoot)
    || realpath($webRoot) !== $webRoot || is_link($webRoot)
    || !is_dir($privateRoot) || !is_dir($webRoot) || !is_writable($privateRoot)
    || !is_file($fixtureParent . '/fixture-marker.json') || is_link($fixtureParent . '/fixture-marker.json')) {
    brainwash_installed_fail('fixture_paths');
}
$markerStat = @lstat($fixtureParent . '/fixture-marker.json');
$markerRaw = @file_get_contents($fixtureParent . '/fixture-marker.json');
$marker = is_string($markerRaw) ? json_decode($markerRaw, true, 8) : null;
$markerKeys = is_array($marker) ? array_keys($marker) : [];
sort($markerKeys, SORT_STRING);
$coreInput = getenv('QD_CHIM_ROOT');
$coreRoot = is_string($coreInput) ? realpath($coreInput) : false;
if (!is_array($marker) || $markerKeys !== ['coreRoot', 'fixtureId', 'schema']
    || ($marker['schema'] ?? null) !== 'qd-brainwash-installed.v1' || ($marker['fixtureId'] ?? null) !== $fixtureId
    || !is_array($markerStat) || ($markerStat['uid'] ?? null) !== 0 || (($markerStat['mode'] ?? 0) & 0022) !== 0
    || !is_string($coreRoot) || ($marker['coreRoot'] ?? null) !== $coreRoot
    || !is_dir($coreRoot . '/lib')
    || str_starts_with($privateRoot, rtrim($coreRoot, '/') . '/')
    || str_starts_with($privateRoot, rtrim($webRoot, '/') . '/')) {
    brainwash_installed_fail('fixture_marker');
}
if (getenv('QD_BRAINWASH_ROOT') !== false && getenv('QD_BRAINWASH_ROOT') !== '') {
    brainwash_installed_fail('owner_override_forbidden');
}

$pairingPath = $privateRoot . '/pairing.json';
$statePath = $privateRoot . '/state.json';
if (file_exists($pairingPath) || is_link($pairingPath) || file_exists($statePath) || is_link($statePath)
    || file_exists($statePath . '.journal') || is_link($statePath . '.journal')) {
    brainwash_installed_fail('fixture_not_fresh');
}

$serverRoot = $coreRoot . '/ext/questionable_decisions';
$canonicalDepartmentRoot = realpath($serverRoot);
$manifestPath = $serverRoot . '/manifest.json';
$manifestRaw = !is_link($manifestPath) && is_file($manifestPath) ? @file_get_contents($manifestPath, false, null, 0, 4097) : false;
$manifest = false;
if (is_string($manifestRaw) && strlen($manifestRaw) <= 4096) {
    try {
        $manifest = json_decode($manifestRaw, true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $manifest = false;
    }
}
if ($canonicalDepartmentRoot !== $serverRoot || !is_array($manifest)
    || ($manifest['name'] ?? null) !== 'questionable_decisions'
    || ($manifest['version'] ?? null) !== '0.1.0-prealpha.10'
    || ($manifest['schema_version'] ?? null) !== 2) {
    brainwash_installed_fail('installed_department_identity');
}
$apiPath = $serverRoot . '/api.php';
$provisionPath = $serverRoot . '/tools/provision.php';
if (!is_file($apiPath) || is_link($apiPath) || !is_file($provisionPath) || is_link($provisionPath)) {
    brainwash_installed_fail('department_source');
}
require_once $apiPath;
$dependencies = qd_api_runtime_dependencies();
$runtimeFile = realpath((new ReflectionFunction('qd_department_runtime_dependencies'))->getFileName() ?: '');
$extensionRoot = qd_runtime_extension_root('brainwash');
if ($runtimeFile !== realpath($serverRoot . '/runtime.php')
    || realpath($extensionRoot) !== realpath($coreRoot . '/ext/brainwash')
    || !qd_brainwash_runtime_ready() || !is_callable($dependencies['withProfileLease'] ?? null)) {
    brainwash_installed_fail('real_runtime_not_ready');
}

$descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$process = @proc_open([PHP_BINARY, $provisionPath, 'issue', $pairingPath, $webRoot, 'brainwash'], $descriptor, $pipes);
if (!is_resource($process)) brainwash_installed_fail('provision_cli_start');
fclose($pipes[0]);
$provisionOut = stream_get_contents($pipes[1], 4097);
$provisionError = stream_get_contents($pipes[2], 2049);
fclose($pipes[1]);
fclose($pipes[2]);
$provisionExit = proc_close($process);
if ($provisionExit !== 0 || !is_string($provisionOut) || strlen($provisionOut) > 4096
    || !is_string($provisionError) || strlen($provisionError) > 2048 || trim($provisionError) !== '') {
    brainwash_installed_fail('provision_cli_failed');
}
try {
    $pair = json_decode(trim($provisionOut), true, 8, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    brainwash_installed_fail('provision_cli_json');
}
if (!is_array($pair) || !is_string($pair['pairId'] ?? null) || !is_string($pair['token'] ?? null)
    || ($pair['scopes'] ?? null) !== ['brainwash']) {
    brainwash_installed_fail('provision_cli_result');
}
$authorization = 'Bearer ' . $pair['token'];
unset($pair, $provisionOut, $provisionError);
$settings = ['pairingPath' => $pairingPath, 'statePath' => $statePath, 'webRoot' => $webRoot];
$viewToken = brainwash_installed_uuid();
$call = static function (string $operation, object $payload, ?string $serverContext = null) use ($authorization, $settings, $dependencies, $viewToken): array {
    $request = (object)[
        'version' => 1,
        'requestId' => brainwash_installed_uuid(),
        'operation' => $operation,
        'context' => (object)[
            'viewToken' => $viewToken,
            'nativeGeneration' => '1',
            'serverContext' => $serverContext,
        ],
        'payload' => $payload,
    ];
    $body = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return qd_api_handle('POST', 'application/json', $body, $authorization, $settings, $dependencies);
};

$generationBefore = brainwash_installed_generation($coreRoot);
$bootstrap = brainwash_installed_expect_data($call('bootstrap', new stdClass()), 'bootstrap');
if (($bootstrap->authenticated ?? null) !== true
    || ($bootstrap->capabilities->brainwash ?? null) !== true
    || ($bootstrap->access->brainwash ?? null) !== true
    || !is_string($bootstrap->serverContext ?? null)) {
    brainwash_installed_fail('brainwash_bootstrap_access');
}
$serverContext = $bootstrap->serverContext;
$catalog = brainwash_installed_expect_data($call('brainwash.catalog.read', (object)['query' => 'Brainwash TEST 01a16f21'], $serverContext), 'catalog');
$actors = $catalog->actors ?? null;
if (!is_array($actors)) brainwash_installed_fail('catalog_shape');
$actor = null;
foreach ($actors as $candidate) {
    if (is_object($candidate) && ($candidate->actorKey ?? null) === 'npc:2147483001') $actor = $candidate;
}
if (!is_object($actor) || ($actor->label ?? null) !== 'Brainwash TEST 01a16f21') {
    brainwash_installed_fail('seeded_actor_missing');
}
$actorKey = 'npc:2147483001';
$before = brainwash_installed_expect_data($call('brainwash.card.read', (object)['actorKey' => $actorKey], $serverContext), 'card_before');
if (($before->actorKey ?? null) !== $actorKey || !is_string($before->revision ?? null)
    || !is_object($before->fields ?? null) || !is_string($before->fields->personality ?? null)) {
    brainwash_installed_fail('card_before_shape');
}
$draftOperationId = brainwash_installed_uuid();
$operationId = brainwash_installed_uuid();
$change = 'Brainwash Department TEST fixture ' . $fixtureId;
$draftPayload = (object)[
    'actorKey' => $actorKey,
    'expectedRevision' => $before->revision,
    'operationId' => $draftOperationId,
    'mode' => 'manual',
    'changes' => (object)['personality' => $change],
    'instruction' => '',
];
$draft = brainwash_installed_expect_data($call('brainwash.draft', $draftPayload, $serverContext), 'draft');
if (($draft->actorKey ?? null) !== $actorKey || !is_string($draft->draftId ?? null)
    || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $draft->draftId) !== 1
    || !is_object($draft->before ?? null) || ($draft->before->personality ?? null) !== $before->fields->personality) {
    brainwash_installed_fail('draft_readback');
}
$draftId = $draft->draftId;
$applyPayload = (object)[
    'actorKey' => $actorKey,
    'draftId' => $draftId,
    'operationId' => $operationId,
    'acceptedFields' => ['personality'],
    'acceptBeliefs' => false,
];
$applied = brainwash_installed_expect_data($call('brainwash.apply', $applyPayload, $serverContext), 'apply');
$applyReplay = brainwash_installed_expect_data($call('brainwash.apply', $applyPayload, $serverContext), 'apply_replay');
if (json_encode($applied, JSON_THROW_ON_ERROR) !== json_encode($applyReplay, JSON_THROW_ON_ERROR)
    || ($applied->card->fields->personality ?? null) !== $change
    || ($applied->card->lastOperation->operationId ?? null) !== $operationId) {
    brainwash_installed_fail('apply_replay_receipt');
}
$undoId = brainwash_installed_uuid();
$undoPayload = (object)['actorKey' => $actorKey, 'operationId' => $undoId, 'appliedOperationId' => $operationId];
$undone = brainwash_installed_expect_data($call('brainwash.undo', $undoPayload, $serverContext), 'undo');
if (($undone->card->fields->personality ?? null) !== $before->fields->personality
    || ($undone->card->lastOperation->operationId ?? null) !== $undoId
    || ($undone->card->lastOperation->state ?? null) !== 'undone') {
    brainwash_installed_fail('undo_readback');
}
$postUndoResponse = $call('brainwash.apply', $applyPayload, $serverContext);
if (($postUndoResponse['status'] ?? null) !== 409) brainwash_installed_fail('apply_after_undo_http');
$postUndoReplay = brainwash_installed_response($postUndoResponse, 'apply_after_undo');
if (($postUndoReplay->ok ?? null) !== false
    || ($postUndoReplay->error->code ?? null) !== 'conflict') {
    brainwash_installed_fail('apply_after_undo_not_refused');
}
$after = brainwash_installed_expect_data($call('brainwash.card.read', (object)['actorKey' => $actorKey], $serverContext), 'card_after_undo');
$generationAfter = brainwash_installed_generation($coreRoot);
if (($after->fields->personality ?? null) !== $before->fields->personality || $generationAfter !== $generationBefore) {
    brainwash_installed_fail('final_state_or_generation');
}

$receipt = [
    'status' => 'PASS',
    'fixtureId' => $fixtureId,
    'authentication' => 'trusted-cli-provisioned-fresh-pair',
    'api' => 'qd_api_handle + qd_department_runtime_dependencies',
    'ownerRoot' => 'TEST2 installed ext/brainwash',
    'actorKey' => $actorKey,
    'actorLabel' => $actor->label,
    'checks' => [
        'authenticatedBrainwashCapability' => true,
        'realCatalogAndCardRead' => true,
        'manualDraft' => true,
        'sameIdApplyReplayUsesOwnerReceipt' => true,
        'narrowUndoRestoresOnlyEditedValue' => true,
        'sameIdApplyAfterUndoRefused' => true,
        'coreGenerationReadOnlyAndUnchanged' => true,
        'paidProviderCalls' => 0,
    ],
    'restoreInvalidation' => 'covered by the isolated Brainwash service scope/generation regression; the shared TEST2 generation file was read only',
];
fwrite(STDOUT, json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
