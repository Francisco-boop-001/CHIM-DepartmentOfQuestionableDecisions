<?php
declare(strict_types=1);

define('PCV_LOG_TESTING', true);
$implementationRoot = dirname(__DIR__, 3);
$pinnedOwnerRoot = $implementationRoot . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR
    . 'private-conversation-v0.1.24' . DIRECTORY_SEPARATOR . 'server';

function companion_owner_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function companion_owner_remove_tree(string $path, string $root): void
{
    $canonicalRoot = realpath($root);
    $canonicalPath = realpath($path);
    if ($canonicalRoot === false || $canonicalPath === false
        || !str_starts_with($canonicalPath . DIRECTORY_SEPARATOR, rtrim($canonicalRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        || is_link($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($canonicalPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($canonicalPath);
}

function companion_owner_nested_include_probe(bool $stockLoaderBinding, string $probePath): array
{
    if ($stockLoaderBinding) {
        global $gameRequest;
    } else {
        $gameRequest = $GLOBALS['gameRequest'] ?? null;
    }
    $snapshot = [];
    $resolvedScope = [
        'enabled' => true,
        'scene_mode' => 'pair',
        'actor_a' => 'Lydia',
        'actor_b' => 'Aela',
        'exclude_player' => true,
        'bystander_mode' => 'exclude',
    ];
    $playerName = 'Dragonborn';
    $mode = 'STANDARD';
    require $probePath;
    return [
        'request' => $GLOBALS['gameRequest'] ?? null,
        'originDialogue' => $GLOBALS['PCV_REQUEST_SCOPE']['origin_dialogue'] ?? null,
    ];
}

$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-companion-owner-' . bin2hex(random_bytes(8));
$stateDirectory = $tempRoot . DIRECTORY_SEPARATOR . 'state';
$logDirectory = $tempRoot . DIRECTORY_SEPARATOR . 'logs';
$fixtureOwnerRoot = $tempRoot . DIRECTORY_SEPARATOR . 'owner';
mkdir($tempRoot, 0700, true);
mkdir($logDirectory, 0700, true);
mkdir($fixtureOwnerRoot, 0700, true);
$logSource = file_get_contents($pinnedOwnerRoot . DIRECTORY_SEPARATOR . 'log.php');
companion_owner_check(is_string($logSource)
    && hash_equals('0a3a0684211bcb7e3e2a380ddf0d0173dfe838471b557d0b6ab1a1ec8a3fef56', strtolower(hash('sha256', $logSource))),
    'the isolated test must start from the exact frozen PCV 0.1.24 logger.');
$uidFunction = <<<'SOURCE'
function pcv_log_effective_uid(): ?int
{
    if (function_exists('posix_geteuid')) {
        return posix_geteuid();
    }
    return null;
}
SOURCE;
$uidReplacement = <<<'SOURCE'
function pcv_log_effective_uid(): ?int
{
    if (function_exists('posix_geteuid')) {
        return posix_geteuid();
    }
    if (defined('PCV_LOG_TESTING') && PCV_LOG_TESTING === true) {
        $owner = @fileowner(sys_get_temp_dir());
        return is_int($owner) ? $owner : null;
    }
    return null;
}
SOURCE;
$safePredicate = <<<'SOURCE'
function pcv_log_directory_is_safe(string $directory, bool $mustExist): bool
{
SOURCE;
$safeReplacement = $safePredicate . "\n" . <<<'SOURCE'
    if (defined('PCV_LOG_TESTING') && PCV_LOG_TESTING === true) {
        $tempRoot = realpath(sys_get_temp_dir());
        $canonical = realpath($directory);
        if ($tempRoot !== false && $canonical !== false
            && !pcv_log_has_symlink_component($directory)
            && pcv_log_path_is_within($canonical, $tempRoot)) {
            return true;
        }
    }
SOURCE;
companion_owner_check(str_contains($logSource, $uidFunction) && str_contains($logSource, $safePredicate),
    'the frozen logger test seams must remain recognizable.');
$logSource = str_replace($uidFunction, $uidReplacement, $logSource);
$logSource = str_replace($safePredicate, $safeReplacement, $logSource);
foreach (['log.php' => $logSource, 'state.php' => file_get_contents($pinnedOwnerRoot . DIRECTORY_SEPARATOR . 'state.php'),
    'scope.php' => file_get_contents($pinnedOwnerRoot . DIRECTORY_SEPARATOR . 'scope.php')] as $name => $contents) {
    companion_owner_check(is_string($contents) && file_put_contents($fixtureOwnerRoot . DIRECTORY_SEPARATOR . $name, $contents) === strlen($contents),
        'could not stage the isolated owner source ' . $name . '.');
}
require_once $fixtureOwnerRoot . DIRECTORY_SEPARATOR . 'state.php';
require_once $fixtureOwnerRoot . DIRECTORY_SEPARATOR . 'scope.php';
require_once $implementationRoot . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR . 'preprocessing.php';
require_once $implementationRoot . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR . 'globals.php';
try {
    companion_owner_check(pcv_log_set_test_directory($logDirectory), 'PCV test log directory must be accepted.');
    $key = str_repeat('a', 64);
    $known = ['1' => 'Lydia', '2' => 'Aela'];
    $config = [
        'enabled' => true,
        'scene_mode' => 'pair',
        'actor_a' => '1',
        'actor_b' => '2',
        'exclude_player' => true,
        'bystander_mode' => 'exclude',
    ];

    $identityKnown = pcvScopeKnownNpcs([
        ['id' => '404', 'npc_name' => 'Éowyn', 'profile_id' => '94', 'metadata' => '{}'],
        ['id' => '303', 'npc_name' => 'Aela', 'profile_id' => '93', 'metadata' => '{}'],
    ], 'Nerevar');
    $rawTargetName = ' éOWYN ';
    $identityConfig = [
        'enabled' => true,
        'scene_mode' => 'pair',
        'actor_a' => '404',
        'actor_b' => '303',
        'exclude_player' => true,
        'bystander_mode' => 'exclude',
    ];
    $projectedIdentityConfig = pcv_companion_project_owner_config($identityConfig, $identityKnown);
    $identityTarget = function_exists('pcv_companion_owner_target_identity')
        ? pcv_companion_owner_target_identity($rawTargetName, $identityKnown) : null;
    $rawOperationPacket = [
        'operationId' => '2244a3cd-7f7c-4d45-b4a6-2d738433f8c2',
        'principalId' => 'principal-identity-test',
        'scopeHash' => hash('sha256', 'raw-identity-scope'),
        'target' => ['formId' => '00f00404', 'name' => $rawTargetName],
        'token' => 'pcv1_' . str_repeat('A', 43),
    ];
    $ownerProjection = pcv_companion_owner_target_projection($rawOperationPacket, $identityKnown);
    $expectedOwnerPacket = $rawOperationPacket;
    $expectedOwnerPacket['target']['name'] = 'Éowyn';
    $rawSnapshot = [
        'source' => 'plugin_player_routing_v2',
        'speech_mode' => 'standard',
        'execution_mode' => 'STANDARD',
        'target_mode' => 'direct',
        'listener' => $rawTargetName,
        'present_actors' => [[
            'form_id' => hexdec('00f00404'),
            'name' => $rawTargetName,
            'managed' => true,
            'creature' => false,
        ]],
    ];
    $rawSnapshotEncoded = base64_encode(json_encode($rawSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $identityScope = is_array($projectedIdentityConfig)
        ? pcv_companion_scope_for_preflight($projectedIdentityConfig, $identityKnown, [
            'present_actors' => [
                ['name' => $rawTargetName],
                ['name' => 'Aela'],
            ],
        ], $rawTargetName)
        : null;
    companion_owner_check(is_array($identityTarget)
        && ($identityTarget['id'] ?? null) === '404'
        && ($identityTarget['canonicalName'] ?? null) === 'Éowyn'
        && is_array($ownerProjection)
        && ($ownerProjection['identity'] ?? null) === $identityTarget
        && ($ownerProjection['packet'] ?? null) === $expectedOwnerPacket
        && pcv_companion_raw_snapshot_matches($rawSnapshotEncoded, $rawOperationPacket['target'])
        && !pcv_companion_raw_snapshot_matches($rawSnapshotEncoded, $expectedOwnerPacket['target'])
        && pcv_companion_owner_target_is_member($projectedIdentityConfig ?? [], $rawTargetName, $identityKnown)
        && ($identityScope['members'] ?? null) === ['Éowyn', 'Aela'],
        'Unicode case and surrounding whitespace must resolve one catalog ID for owner scope while raw snapshot and packet identity remain unchanged.');

    $ambiguousIdentityKnown = pcvScopeKnownNpcs([
        ['id' => '404', 'npc_name' => ' Éowyn ', 'profile_id' => '94', 'metadata' => '{}'],
        ['id' => '405', 'npc_name' => 'éOWYN', 'profile_id' => '95', 'metadata' => '{}'],
        ['id' => '303', 'npc_name' => 'Aela', 'profile_id' => '93', 'metadata' => '{}'],
    ], 'Nerevar');
    $collisionConfig = [
        'enabled' => true,
        'scene_mode' => 'pair',
        'actor_a' => '404',
        'actor_b' => '303',
        'exclude_player' => true,
        'bystander_mode' => 'exclude',
    ];
    $nonmemberConfig = pcv_companion_project_owner_config([
        'enabled' => true,
        'scene_mode' => 'solo',
        'actor_a' => '303',
        'exclude_player' => true,
        'bystander_mode' => 'exclude',
    ], $identityKnown);
    companion_owner_check(!array_key_exists('404', $ambiguousIdentityKnown)
        && !array_key_exists('405', $ambiguousIdentityKnown)
        && pcv_companion_owner_target_identity($rawTargetName, $ambiguousIdentityKnown) === null
        && !pcv_companion_owner_target_is_member(
        pcv_companion_project_owner_config($collisionConfig, $ambiguousIdentityKnown) ?? [],
        $rawTargetName,
        $ambiguousIdentityKnown,
    ) && !pcv_companion_owner_target_is_member(
        $nonmemberConfig ?? [],
        $rawTargetName,
        $identityKnown,
    ) && pcv_companion_owner_target_projection($rawOperationPacket, $ambiguousIdentityKnown) === null,
        'a normalized-name collision and a target outside the configured actor IDs must both fail closed.');

    $identityStateDirectory = $tempRoot . DIRECTORY_SEPARATOR . 'identity-state';
    $identityKey = hash('sha256', 'identity-boundary-test');
    $identityPending = pcv_stage($identityKey, $identityConfig, $identityKnown, $identityStateDirectory);
    $identityPacket = [
        'expectedActiveConfigId' => null,
        'expectedPendingConfigId' => $identityPending['pending_config_id'] ?? null,
        'target' => ['formId' => '00f00404', 'name' => $rawTargetName],
    ];
    $identityBeginGuard = null;
    $identityBegin = pcv_begin_request($identityKey, true, $identityStateDirectory, $identityKnown, null, null, null, true,
        static function (array $before, array $after, array $metadata, callable $commit) use (
            &$identityBeginGuard, $identityPacket, $identityKnown
        ): array {
            $identityBeginGuard = pcv_companion_scene_matches_begin($before, $after, $metadata, $identityPacket, $identityKnown);
            return ['status' => 'refused', 'outcome' => 'known_no_write'];
        });
    $identityAfterRefusedBegin = pcv_load_store($identityStateDirectory);
    companion_owner_check(($identityBegin['_companionOutcome'] ?? null) === 'known_no_write'
        && $identityBeginGuard === true
        && ($identityAfterRefusedBegin['state']['active'] ?? null) === null
        && ($identityAfterRefusedBegin['state']['pending']['config_id'] ?? null) === ($identityPending['pending_config_id'] ?? null),
        'the real owner begin callback must accept the catalog identity while keeping the raw target packet and pending state unchanged.');

    $identityPostguard = function_exists('pcv_companion_owner_scope_contains_target')
        ? pcv_companion_owner_scope_contains_target($identityScope ?? [], $rawTargetName, $identityKnown) : false;
    companion_owner_check($identityPostguard,
        'the owner postguard must confirm the raw target against the canonical catalog member identity.');

    $identityStoreDirectory = $identityStateDirectory . DIRECTORY_SEPARATOR . 'companion-stage';
    mkdir($identityStoreDirectory, 0700, true);
    $rawLiteral = "  Speak exactly: Éowyn.\n";
    $rawStage = [
        'operationId' => '7c73a11a-8e45-4e30-8090-9a03c81490af',
        'principalId' => 'principal-identity-test',
        'scopeHash' => hash('sha256', 'raw-identity-stage-scope'),
        'ownerKey' => hash('sha256', 'raw-identity-owner'),
        'text' => $rawLiteral,
        'target' => $rawOperationPacket['target'],
        'expectedActiveConfigId' => null,
        'expectedPendingConfigId' => $identityPending['pending_config_id'] ?? null,
        'context' => [
            'serverContext' => 'raw-identity-context',
            'viewToken' => '79f648ae-a1ef-4afd-a5c1-f9471328bf6d',
            'nativeGeneration' => '19',
        ],
    ];
    $rawPrepared = pcv_companion_stage_record($identityStoreDirectory, $rawStage, time());
    $rawRecordKey = pcv_companion_record_key($rawStage['principalId'], $rawStage['scopeHash'], $rawStage['operationId']);
    $rawRecordPath = pcv_companion_record_path(pcv_companion_store_directory($identityStoreDirectory), $rawRecordKey);
    $rawRecord = json_decode((string)file_get_contents($rawRecordPath), true, 16, JSON_THROW_ON_ERROR);
    $rawClaim = pcv_companion_claim_token($identityStoreDirectory, $rawPrepared['token'], $rawStage['target'], time());
    companion_owner_check(($rawRecord['target'] ?? null) === $rawStage['target']
        && ($rawRecord['text'] ?? null) === $rawLiteral
        && hash_equals((string)($rawRecord['tokenHash'] ?? ''), hash('sha256', $rawPrepared['token']))
        && ($rawClaim['target'] ?? null) === $rawStage['target']
        && ($rawClaim['text'] ?? null) === $rawLiteral,
        'staging and claiming must preserve the exact raw target and literal while binding the one-use token hash to that record.');

    $initial = pcv_stage($key, $config, $known, $stateDirectory);
    companion_owner_check(($initial['status'] ?? null) === 'pending', 'a normal five-argument stage call must preserve owner behavior.');
    $originalPendingId = $initial['pending_config_id'] ?? null;
    companion_owner_check(is_string($originalPendingId), 'the owner must assign a pending config ID.');

    $stageCallbackCalls = 0;
    $refused = pcv_stage($key, $config, $known, $stateDirectory, null, static function (array $before, array $after, array $metadata, callable $commit) use (&$stageCallbackCalls, $stateDirectory): array {
        $stageCallbackCalls++;
        $otherLock = @fopen($stateDirectory . DIRECTORY_SEPARATOR . 'state.lock', 'c+b');
        $otherAcquired = is_resource($otherLock) && @flock($otherLock, LOCK_EX | LOCK_NB);
        if ($otherAcquired) {
            flock($otherLock, LOCK_UN);
        }
        if (is_resource($otherLock)) {
            fclose($otherLock);
        }
        companion_owner_check(!$otherAcquired, 'stage callback must run while the owner state lock remains exclusively held.');
        companion_owner_check(($before['pending']['config_id'] ?? null) !== ($after['pending']['config_id'] ?? null), 'stage callback must see before and proposed owner state.');
        return ['status' => 'refused', 'outcome' => 'known_no_write'];
    });
    companion_owner_check($stageCallbackCalls === 1, 'pcv_stage must invoke the optional extension guard before owner commit.');
    companion_owner_check(($refused['_companionOutcome'] ?? null) === 'known_no_write', 'a refused stage must return an explicit no-write classification.');
    $afterRefusedStage = pcv_load_store($stateDirectory);
    companion_owner_check(($afterRefusedStage['state']['pending']['config_id'] ?? null) === $originalPendingId, 'refused stage must not mutate pending owner state.');

    $beginCallbackCalls = 0;
    $pendingGuardAccepted = null;
    $pendingPacket = [
        'expectedActiveConfigId' => null,
        'expectedPendingConfigId' => $originalPendingId,
        'target' => ['formId' => '00F00123', 'name' => 'Lydia'],
    ];
    $blocked = pcv_begin_request($key, true, $stateDirectory, ['1' => 'Lydia', '2' => 'Aela'], null, null, null, true,
        static function (array $before, array $after, array $metadata, callable $commit) use (&$beginCallbackCalls, &$pendingGuardAccepted, $stateDirectory, $pendingPacket, $known): array {
            $beginCallbackCalls++;
            $otherLock = @fopen($stateDirectory . DIRECTORY_SEPARATOR . 'state.lock', 'c+b');
            $otherAcquired = is_resource($otherLock) && @flock($otherLock, LOCK_EX | LOCK_NB);
            if ($otherAcquired) {
                flock($otherLock, LOCK_UN);
            }
            if (is_resource($otherLock)) {
                fclose($otherLock);
            }
            companion_owner_check(!$otherAcquired, 'begin callback must run while the owner state lock remains exclusively held.');
            $activated = $metadata['activated'] ?? null;
            companion_owner_check(($before['pending']['config_id'] ?? null) === ($activated['config_id'] ?? null), 'begin callback must observe the exact pending config selected for activation.');
            $pendingGuardAccepted = pcv_companion_scene_matches_begin($before, $after, $metadata, $pendingPacket, $known);
            return ['status' => 'refused', 'outcome' => 'known_no_write'];
        });
    companion_owner_check($beginCallbackCalls === 1, 'pcv_begin_request must invoke the optional extension guard before activation commit.');
    companion_owner_check(($blocked['_companionOutcome'] ?? null) === 'known_no_write', 'a refused begin must return an explicit no-write classification.');
    $afterRefusedBegin = pcv_load_store($stateDirectory);
    companion_owner_check(($afterRefusedBegin['state']['active'] ?? null) === null
        && ($afterRefusedBegin['state']['pending']['config_id'] ?? null) === $originalPendingId,
        'refused begin must leave the prepared scene pending and inactive.');
    companion_owner_check($pendingGuardAccepted === true,
        'Pending activation must authorize the target from the owner strict eligible map.');

    $activeOnlyPendingAccepted = null;
    $activeOnlyPending = pcv_begin_request($key, true, $stateDirectory, ['2' => 'Aela'], ['1' => 'Lydia', '2' => 'Aela'], null, null, true,
        static function (array $before, array $after, array $metadata, callable $commit) use (&$activeOnlyPendingAccepted, $pendingPacket, $known): array {
            companion_owner_check(($metadata['eligible_npc_map']['1'] ?? null) === null
                && ($metadata['active_eligible_npc_map']['1'] ?? null) === 'Lydia',
                'pending negative must expose active-only target evidence separately from the missing strict map entry.');
            $activeOnlyPendingAccepted = pcv_companion_scene_matches_begin($before, $after, $metadata, $pendingPacket, $known);
            return ['status' => 'refused', 'outcome' => 'known_no_write'];
        });
    companion_owner_check(($activeOnlyPending['_companionOutcome'] ?? null) === 'known_no_write'
        && $activeOnlyPendingAccepted === false,
        'An active-only map must not authorize a pending activation.');
    $afterActiveOnlyPending = pcv_load_store($stateDirectory);
    companion_owner_check(($afterActiveOnlyPending['state']['active'] ?? null) === null
        && ($afterActiveOnlyPending['state']['pending']['config_id'] ?? null) === $originalPendingId,
        'active-only evidence must leave the original pending scene uncommitted.');

    $committed = pcv_begin_request($key, true, $stateDirectory, ['1' => 'Lydia', '2' => 'Aela'], null, null, null, true,
        static function (array $before, array $after, array $metadata, callable $commit) use ($stateDirectory): array {
            $otherLock = @fopen($stateDirectory . DIRECTORY_SEPARATOR . 'state.lock', 'c+b');
            $otherAcquired = is_resource($otherLock) && @flock($otherLock, LOCK_EX | LOCK_NB);
            if ($otherAcquired) {
                flock($otherLock, LOCK_UN);
            }
            if (is_resource($otherLock)) {
                fclose($otherLock);
            }
            companion_owner_check(!$otherAcquired, 'allowed begin callback must run while the owner state lock remains exclusively held.');
            $activated = $metadata['activated'] ?? null;
            companion_owner_check(is_array($activated) && ($activated['config_id'] ?? null) === $before['pending']['config_id'], 'allowed begin must match the original pending config.');
            $commit();
            return ['status' => 'committed', 'outcome' => 'owner_commit'];
        });
    companion_owner_check(($committed['status'] ?? null) === 'active'
        && ($committed['config_id'] ?? null) === $originalPendingId,
        'an allowed owner callback must activate the first typed turn.');
    $afterCommit = pcv_load_store($stateDirectory);
    companion_owner_check(($afterCommit['state']['active']['config_id'] ?? null) === $originalPendingId
        && ($afterCommit['state']['pending'] ?? null) === null,
        'allowed begin must atomically replace the exact pending scene with its active state.');

    $activePacket = [
        'expectedActiveConfigId' => $originalPendingId,
        'expectedPendingConfigId' => null,
        'target' => ['formId' => '00F00123', 'name' => 'Lydia'],
    ];
    $activeGuardAccepted = null;
    $activeKept = pcv_begin_request($key, true, $stateDirectory, [], ['1' => 'Lydia', '2' => 'Aela'], null, null, true,
        static function (array $before, array $after, array $metadata, callable $commit) use (&$activeGuardAccepted, $activePacket, $known): array {
            companion_owner_check(($metadata['eligible_npc_map'] ?? null) === []
                && ($metadata['active_eligible_npc_map']['1'] ?? null) === 'Lydia',
                'active positive must use the owner in-scene map independently of the strict map.');
            $activeGuardAccepted = pcv_companion_scene_matches_begin($before, $after, $metadata, $activePacket, $known);
            return ['status' => 'refused', 'outcome' => 'known_no_write'];
        });
    companion_owner_check(($activeKept['_companionOutcome'] ?? null) === 'known_no_write' && $activeGuardAccepted === true,
        'An already-active send must authorize the target from the owner in-scene map.');

    $rejectedReplacement = pcv_stage($key, $config, $known, $stateDirectory);
    companion_owner_check(($rejectedReplacement['status'] ?? null) === 'active'
        && is_string($rejectedReplacement['pending_config_id'] ?? null),
        'an active-scene replacement must be staged with its own pending identity.');
    $rejectedReplacementBegin = pcv_begin_request($key, true, $stateDirectory, ['2' => 'Aela'], ['1' => 'Lydia', '2' => 'Aela']);
    $afterRejectedReplacement = pcv_load_store($stateDirectory);
    companion_owner_check(($rejectedReplacementBegin['reason'] ?? null) === 'scene_not_eligible'
        && ($rejectedReplacementBegin['pending'] ?? null) === false
        && ($afterRejectedReplacement['state']['active']['config_id'] ?? null) === $originalPendingId
        && ($afterRejectedReplacement['state']['pending'] ?? null) === null,
        'a rejected replacement must be consumed while the existing active scene remains unchanged.');

    $strictOnlyActiveAccepted = null;
    $strictOnlyActive = pcv_begin_request($key, true, $stateDirectory, ['1' => 'Lydia', '2' => 'Aela'], null, null, null, true,
        static function (array $before, array $after, array $metadata, callable $commit) use (&$strictOnlyActiveAccepted, $activePacket, $known): array {
            companion_owner_check(($metadata['eligible_npc_map']['1'] ?? null) === 'Lydia'
                && ($metadata['active_eligible_npc_map'] ?? null) === null,
                'active negative must expose strict-only evidence separately from the missing in-scene map.');
            $strictOnlyActiveAccepted = pcv_companion_scene_matches_begin($before, $after, $metadata, $activePacket, $known);
            return ['status' => 'refused', 'outcome' => 'known_no_write'];
        });
    companion_owner_check(($strictOnlyActive['_companionOutcome'] ?? null) === 'known_no_write'
        && $strictOnlyActiveAccepted === false,
        'A strict activation map must not authorize an already-active send when the in-scene map is absent.');
    $afterActiveRefusals = pcv_load_store($stateDirectory);
    companion_owner_check(($afterActiveRefusals['state']['active']['config_id'] ?? null) === $originalPendingId
        && ($afterActiveRefusals['state']['pending'] ?? null) === null,
        'active-map refusal tests must not mutate the committed scene.');

    $literal = "Remember | café\nsecond line\n";
    $ownerInput = ['inputtext', '1700000000', '1700000000', 'Dragonborn:' . $literal, 'e30='];
    $resolvedScope = [
        'enabled' => true,
        'scene_mode' => 'pair',
        'actor_a' => 'Lydia',
        'actor_b' => 'Aela',
        'exclude_player' => true,
        'bystander_mode' => 'exclude',
    ];
    $excludedInput = pcvPrepareScopedInput($ownerInput, [], $resolvedScope, 'Dragonborn', 'STANDARD');
    companion_owner_check(($excludedInput['status'] ?? null) === 'applied'
        && ($excludedInput['game_request'][0] ?? null) === 'instruction'
        && ($excludedInput['game_request'][3] ?? null) === 'Dragonborn: ' . $literal,
        'the frozen owner must preserve UTF-8, pipe, and newline bytes in the player-excluded prepared origin dialogue.');
    $includedScope = $resolvedScope;
    $includedScope['exclude_player'] = false;
    $includedInput = pcvPrepareScopedInput($ownerInput, [], $includedScope, 'Dragonborn', 'STANDARD');
    companion_owner_check(($includedInput['status'] ?? null) === 'applied'
        && ($includedInput['game_request'][0] ?? null) === 'inputtext'
        && ($includedInput['game_request'][3] ?? null) === 'Dragonborn:' . $literal,
        'the frozen owner must preserve the original UTF-8, pipe, and newline origin dialogue when the player is included.');

    $sceneDirectionInput = pcvPrepareScopedInput($ownerInput, [], $includedScope, 'Dragonborn', 'STANDARD', true);
    companion_owner_check(($sceneDirectionInput['status'] ?? null) === 'applied'
        && ($sceneDirectionInput['game_request'][0] ?? null) === 'instruction'
        && ($sceneDirectionInput['game_request'][3] ?? null) === 'Dragonborn: ' . $literal,
        'an explicit wrap-up direction must use the owner instruction route even when the player is included.');

    $ownerPreprocessingPath = $pinnedOwnerRoot . DIRECTORY_SEPARATOR . 'preprocessing.php';
    $ownerPreprocessingSource = file_get_contents($ownerPreprocessingPath);
    companion_owner_check(is_string($ownerPreprocessingSource)
        && str_contains($ownerPreprocessingSource, '$gameRequest = $GLOBALS[\'gameRequest\'] ?? null;')
        && str_contains($ownerPreprocessingSource, '$GLOBALS[\'gameRequest\'] = $prepared[\'game_request\'];')
        && str_contains($ownerPreprocessingSource, '$prepared = pcvPrepareScopedInput($gameRequest, $snapshot, $resolvedScope, $playerName, $mode, $wrapUp);')
        && str_contains($ownerPreprocessingSource, '\'origin_dialogue\' => is_string($gameRequest[3] ?? null) ? $gameRequest[3] : null,'),
        'the nested-include regression must remain tied to the pinned owner request, prepare, and origin assignments.');
    $nestedOwnerProbePath = $tempRoot . DIRECTORY_SEPARATOR . 'nested-owner-probe.php';
    $nestedOwnerProbe = <<<'PHP'
<?php
$gameRequest = $GLOBALS['gameRequest'] ?? null;
$prepared = pcvPrepareScopedInput($gameRequest, $snapshot, $resolvedScope, $playerName, $mode);
$GLOBALS['gameRequest'] = $prepared['game_request'];
$GLOBALS['PCV_REQUEST_SCOPE'] = [
    'origin_dialogue' => is_string($gameRequest[3] ?? null) ? $gameRequest[3] : null,
];
PHP;
    companion_owner_check(file_put_contents($nestedOwnerProbePath, $nestedOwnerProbe) === strlen($nestedOwnerProbe),
        'could not create the nested owner-preprocessing probe.');
    $GLOBALS['gameRequest'] = $ownerInput;
    $withoutStockBinding = companion_owner_nested_include_probe(false, $nestedOwnerProbePath);
    companion_owner_check(($withoutStockBinding['request'][3] ?? null) === 'Dragonborn: ' . $literal
        && ($withoutStockBinding['originDialogue'] ?? null) === 'Dragonborn:' . $literal,
        'the unbound nested include must reproduce the prior owner-local origin mismatch.');
    $GLOBALS['gameRequest'] = $ownerInput;
    $withStockBinding = companion_owner_nested_include_probe(true, $nestedOwnerProbePath);
    companion_owner_check(($withStockBinding['request'][3] ?? null) === 'Dragonborn: ' . $literal
        && ($withStockBinding['originDialogue'] ?? null) === ($withStockBinding['request'][3] ?? null),
        'the stock loader global alias must keep the included owner origin equal to the prepared request.');
    $companionPreprocessingPath = $implementationRoot . DIRECTORY_SEPARATOR . 'pcv-companion'
        . DIRECTORY_SEPARATOR . 'preprocessing.php';
    $companionPreprocessingSource = file_get_contents($companionPreprocessingPath);
    $taggedHandlerStart = is_string($companionPreprocessingSource)
        ? strpos($companionPreprocessingSource, 'function pcv_companion_process_tagged_request(): void') : false;
    $globalBindingAt = $taggedHandlerStart === false ? false : strpos($companionPreprocessingSource, 'global $gameRequest;', $taggedHandlerStart);
    $ownerIncludeAt = $taggedHandlerStart === false ? false
        : strpos($companionPreprocessingSource, 'require_once $root . DIRECTORY_SEPARATOR . \'preprocessing.php\';', $taggedHandlerStart);
    companion_owner_check($taggedHandlerStart !== false && $globalBindingAt !== false && $ownerIncludeAt !== false
        && $globalBindingAt < $ownerIncludeAt,
        'the tagged handler must import the stock loader gameRequest alias before the nested owner preprocessing include.');

    echo "PASS PCV owner callbacks use strict pending and in-scene active eligibility maps\n";
} finally {
    companion_owner_remove_tree($tempRoot, sys_get_temp_dir());
}
