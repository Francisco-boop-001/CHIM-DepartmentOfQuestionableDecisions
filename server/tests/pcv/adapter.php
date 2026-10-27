<?php
declare(strict_types=1);

require_once __DIR__ . '/../../adapters/runtime_support.php';
require_once __DIR__ . '/../../adapters/pcv.php';
require_once __DIR__ . '/../../lib/protocol.php';

$pcvRoot = dirname(__DIR__, 3) . '/pcv-companion/private-conversation-v0.1.24/server';
$previousPcvRoot = getenv('QD_PCV_ROOT');
$previousChimRoot = getenv('QD_CHIM_ROOT');
$previousLogRoot = getenv('PCV_LOG_DIR');
putenv('QD_PCV_ROOT=' . $pcvRoot);
$manifest = json_decode((string)file_get_contents($pcvRoot . '/manifest.json'), true, 8, JSON_THROW_ON_ERROR);
if (($manifest['version'] ?? null) !== '0.1.24') {
    throw new RuntimeException('fixture must use the pinned Private Conversation 0.1.24 source');
}
qd_pcv_load_owner_sources();

$home = [
    'available' => true,
    'active_id' => 42,
    'auto_switch_pending' => false,
    'playthroughs' => [[
        'id' => 42,
        'active' => true,
        'available' => true,
        'character_id' => '0123456789abcdef0123456789abcdef',
        'player_name' => 'Nerevar',
    ]],
];
$pcvIdentity = pcv_identity_from_home_state($home, true);
if (qd_pcv_owner_key_from_home_state($home) !== $pcvIdentity['key']) {
    throw new RuntimeException('PCV adapter must use the owner source canonical state namespace');
}
$normalHome = [
    'available' => true,
    'active_id' => 0,
    'auto_switch' => false,
    'auto_switch_pending' => false,
    'playthroughs' => [],
];
$normalPlayerName = 'Dragonborn';
$normalIdentity = pcv_identity_from_home_state($normalHome, true, $normalPlayerName);
$normalKey = qd_pcv_owner_key_from_home_state($normalHome, $normalPlayerName);
if (($normalIdentity['key'] ?? null) !== hash('sha256', 'unprofiled:dragonborn')
    || ($normalIdentity['player_name'] ?? null) !== $normalPlayerName
    || $normalKey !== $normalIdentity['key']
    || qd_pcv_owner_key_from_home_state($normalHome) !== null) {
    throw new RuntimeException('PCV adapter must forward the canonical core player name for shared unprofiled history');
}
$normalHome['auto_switch'] = true;
if (qd_pcv_owner_key_from_home_state($normalHome, $normalPlayerName) !== null) {
    throw new RuntimeException('PCV adapter must refuse unprofiled state while PAS is enabled');
}
$normalHome['auto_switch'] = false;
$normalHome['auto_switch_pending'] = true;
if (qd_pcv_owner_key_from_home_state($normalHome, $normalPlayerName) !== null) {
    throw new RuntimeException('PCV adapter must refuse unprofiled state while a profile switch is pending');
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-pcv-adapter-' . bin2hex(random_bytes(8));
$stateDirectory = $root . DIRECTORY_SEPARATOR . 'state';
$logDirectory = $root . DIRECTORY_SEPARATOR . 'logs';
$coreRoot = $root . DIRECTORY_SEPARATOR . 'core';
mkdir($stateDirectory, 0700, true);
mkdir($logDirectory, 0700, true);
if (!mkdir($coreRoot . DIRECTORY_SEPARATOR . 'lib', 0700, true)
    || file_put_contents($coreRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_preferences.php', "<?php\n") !== 6) {
    throw new RuntimeException('Could not create the isolated core-root marker.');
}
putenv('QD_CHIM_ROOT=' . $coreRoot);
register_shutdown_function(static function () use ($root, $previousPcvRoot, $previousChimRoot, $previousLogRoot): void {
    is_string($previousPcvRoot) ? putenv('QD_PCV_ROOT=' . $previousPcvRoot) : putenv('QD_PCV_ROOT');
    is_string($previousChimRoot) ? putenv('QD_CHIM_ROOT=' . $previousChimRoot) : putenv('QD_CHIM_ROOT');
    is_string($previousLogRoot) ? putenv('PCV_LOG_DIR=' . $previousLogRoot) : putenv('PCV_LOG_DIR');
    $resolved = realpath($root);
    $temporary = realpath(sys_get_temp_dir());
    if (!is_string($resolved) || !is_string($temporary) || dirname($resolved) !== $temporary
        || !str_starts_with(basename($resolved), 'qd-pcv-adapter-') || is_link($root)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $path = $item->getPathname();
        $removed = $item->isLink() ? @unlink($path)
            : ($item->isDir() ? @rmdir($path) : @unlink($path));
        if (!$removed && (file_exists($path) || is_link($path))) {
            return;
        }
    }
    @rmdir($resolved);
});
putenv('PCV_LOG_DIR=' . $logDirectory);
file_put_contents($stateDirectory . DIRECTORY_SEPARATOR . 'state.lock', '');
$state = pcv_empty_store($pcvIdentity['key']);
pcv_write_store($stateDirectory, $state);
$canonicalState = json_decode((string)file_get_contents($stateDirectory . DIRECTORY_SEPARATOR . 'state.json'), true, 16, JSON_THROW_ON_ERROR);
if (array_key_exists('qd_companion', $canonicalState) || !pcv_valid_stored_state($canonicalState)) {
    throw new RuntimeException('companion records must not change the canonical PCV state schema');
}
$before = [];
foreach (new FilesystemIterator($stateDirectory, FilesystemIterator::SKIP_DOTS) as $item) {
    $before[$item->getFilename()] = [hash_file('sha256', $item->getPathname()), filemtime($item->getPathname())];
}
$read = qd_pcv_read_state_directory($stateDirectory, $pcvIdentity['key']);
if (($read['kind'] ?? null) !== 'ready' || ($read['state']['key'] ?? null) !== $pcvIdentity['key']) {
    throw new RuntimeException('PCV adapter must read the real validated owner store');
}
$unavailableProjection = qd_pcv_status_projection(['kind' => 'unavailable'], $pcvIdentity['key'], []);
if ($unavailableProjection !== [
    'state' => 'unavailable',
    'active' => null,
    'pending' => null,
    'activeConfigId' => null,
    'pendingConfigId' => null,
]) {
    throw new RuntimeException('an unavailable owner readback must not project a completed active or pending state');
}
$after = [];
foreach (new FilesystemIterator($stateDirectory, FilesystemIterator::SKIP_DOTS) as $item) {
    $after[$item->getFilename()] = [hash_file('sha256', $item->getPathname()), filemtime($item->getPathname())];
}
if ($before !== $after) {
    throw new RuntimeException('PCV state reads must not mutate files or timestamps');
}

$missingDirectory = $root . DIRECTORY_SEPARATOR . 'missing';
$missing = qd_pcv_read_state_directory($missingDirectory, $pcvIdentity['key']);
if (($missing['kind'] ?? null) !== 'missing' || file_exists($missingDirectory)) {
    throw new RuntimeException('a missing PCV store must stay missing on read');
}

$duplicateNameRows = [
    ['id' => '101', 'npc_name' => 'Aela', 'profile_id' => '91', 'metadata' => '{}'],
    ['id' => '202', 'npc_name' => 'Aela', 'profile_id' => '92', 'metadata' => '{}'],
    ['id' => '303', 'npc_name' => 'Lydia', 'profile_id' => '93', 'metadata' => '{}'],
];
$known = pcvScopeKnownNpcs($duplicateNameRows, 'Nerevar');
if (array_key_exists('101', $known) || array_key_exists('202', $known) || ($known['303'] ?? null) !== 'Lydia') {
    throw new RuntimeException('PCV roster must preserve source duplicate-name refusal');
}
$roster = qd_pcv_roster_projection(
    ['Aela', 'Lydia'],
    '19',
    $duplicateNameRows,
    ['status' => 'ready', 'known_npcs' => ['303' => 'Lydia']],
);
if (($roster['revision'] ?? null) !== '19' || ($roster['state'] ?? null) !== 'ready'
    || count($roster['actors'] ?? []) !== 1 || ($roster['actors'][0]['actorKey'] ?? null) !== 'npc:303'
    || ($roster['actors'][0]['eligibility'] ?? null) !== 'eligible') {
    throw new RuntimeException('roster must emit only unique eligible catalog joins with canonical actor keys');
}

$accentedRows = [
    ['id' => '404', 'npc_name' => '  Éowyn  ', 'profile_id' => '94', 'metadata' => '{}'],
    ['id' => '303', 'npc_name' => 'Lydia', 'profile_id' => '93', 'metadata' => '{}'],
];
$accentedKnown = pcvScopeKnownNpcs($accentedRows, 'Nerevar');
$nativeWireName = 'éOWYN';
$accentedMemberConfig = ['actor_ids' => ['npc:404']];
$otherActorOnlyConfig = ['actor_ids' => ['npc:303']];
$ambiguousCanonicalMap = ['404' => 'Éowyn', '405' => 'éOWYN'];
$companionHelperPath = realpath(dirname(__DIR__, 3) . '/pcv-companion/companion.php');
$uniqueMemberAccepted = qd_pcv_companion_target_is_member($accentedMemberConfig, $nativeWireName, $accentedKnown);
$loadedCompanionHelperPath = realpath((new ReflectionFunction('pcv_companion_owner_target_is_member'))->getFileName() ?: '');
if (!is_string($companionHelperPath) || $loadedCompanionHelperPath !== $companionHelperPath
    || !$uniqueMemberAccepted
    || qd_pcv_companion_target_is_member($otherActorOnlyConfig, $nativeWireName, $accentedKnown)
    || qd_pcv_companion_target_is_member($accentedMemberConfig, $nativeWireName, $ambiguousCanonicalMap)) {
    throw new RuntimeException('the adapter must delegate to the owner identity resolver, authorize the unique catalog ID, and refuse collisions or other IDs');
}
$accentedRoster = qd_pcv_roster_projection(
    [$nativeWireName, 'Lydia'],
    '20',
    $accentedRows,
    ['status' => 'ready', 'known_npcs' => $accentedKnown],
);
if (count($accentedRoster['actors'] ?? []) !== 2
    || ($accentedRoster['actors'][0]['actorKey'] ?? null) !== 'npc:404'
    || ($accentedRoster['actors'][0]['label'] ?? null) !== $nativeWireName
    || ($accentedRoster['actors'][0]['eligibility'] ?? null) !== 'eligible'
    || ($accentedRoster['actors'][1]['label'] ?? null) !== 'Lydia') {
    throw new RuntimeException('Accented catalog identity must join by Unicode lower/trim and return the exact native wire spelling without dropping other roster actors.');
}
$accentedSelection = json_decode(
    '{"actor_a":"' . $accentedRoster['actors'][0]['actorKey'] . '","actor_b":"' . $accentedRoster['actors'][1]['actorKey']
        . '","scene_mode":"pair","bystander_mode":"silent","enabled":true,"exclude_player":false}',
    false,
    8,
    JSON_THROW_ON_ERROR,
);
$selectedConfiguration = qd_pcv_normalize_ui_configuration($accentedSelection, $accentedKnown, $accentedKnown);
if (($selectedConfiguration['actor_a'] ?? null) !== '404' || ($selectedConfiguration['actor_b'] ?? null) !== '303') {
    throw new RuntimeException('A roster actor key must remain the exact selected server catalog identity through configuration validation.');
}
$ambiguousAccentedRows = [
    ['id' => '404', 'npc_name' => ' Éowyn ', 'profile_id' => '94', 'metadata' => '{}'],
    ['id' => '405', 'npc_name' => 'éOWYN', 'profile_id' => '95', 'metadata' => '{}'],
];
$ambiguousKnown = pcvScopeKnownNpcs($ambiguousAccentedRows, 'Nerevar');
$ambiguousRoster = qd_pcv_roster_projection(
    [$nativeWireName],
    '21',
    $ambiguousAccentedRows,
    ['status' => 'ready', 'known_npcs' => []],
);
if (isset($ambiguousKnown['404']) || isset($ambiguousKnown['405']) || ($ambiguousRoster['actors'] ?? null) !== []) {
    throw new RuntimeException('Unicode-equivalent catalog identities must stay excluded rather than guessing a selected NPC.');
}
$ambiguousSelectionRefused = false;
try {
    qd_pcv_normalize_ui_configuration($accentedSelection, $ambiguousKnown, []);
} catch (QDServerFault $error) {
    $ambiguousSelectionRefused = $error->wireCode === 'stale_revision';
}
if (!$ambiguousSelectionRefused) {
    throw new RuntimeException('A target omitted for normalized identity ambiguity must be refused when selected.');
}

$eligibleActors = ['101' => 'Aela', '303' => 'Lydia'];
$uiConfiguration = json_decode(
    '{"actor_b":"npc:303","scene_mode":"pair","bystander_mode":"silent","enabled":true,'
        . '"exclude_player":false,"actor_a":"npc:101","pace":"normal","card":"","actions":[]}',
    false,
    8,
    JSON_THROW_ON_ERROR,
);
$uiMappedConfiguration = qd_pcv_normalize_ui_configuration($uiConfiguration, $eligibleActors, $eligibleActors);
$uiOwnerCallbackCalls = 0;
$uiOwnerObservedConfig = null;
$uiShapeStage = pcv_stage($pcvIdentity['key'], $uiMappedConfiguration, $eligibleActors, $stateDirectory, null,
    static function (array $before, array $after, array $metadata, callable $commit) use (
        $uiMappedConfiguration, &$uiOwnerCallbackCalls, &$uiOwnerObservedConfig
    ): array {
        $uiOwnerCallbackCalls++;
        $uiOwnerObservedConfig = $after['pending']['config'] ?? null;
        if ($uiOwnerObservedConfig !== $uiMappedConfiguration) {
            return ['status' => 'refused', 'outcome' => 'known_no_write', 'reason' => 'conflict'];
        }
        $commit();
        return ['status' => 'committed', 'outcome' => 'owner_commit'];
    });
if (($uiShapeStage['status'] ?? null) !== 'pending' || $uiOwnerCallbackCalls !== 1
    || $uiOwnerObservedConfig !== $uiMappedConfiguration) {
    throw new RuntimeException('UI-shaped owner callback mismatch: status=' . ($uiShapeStage['status'] ?? 'missing')
        . ' calls=' . $uiOwnerCallbackCalls
        . ' observedKeys=' . (is_array($uiOwnerObservedConfig) ? implode(',', array_keys($uiOwnerObservedConfig)) : 'missing'));
}
$uiStateReadback = pcv_load_store($stateDirectory);
if (($uiStateReadback['kind'] ?? null) !== 'ready'
    || ($uiStateReadback['state']['pending']['config'] ?? null) !== $uiMappedConfiguration) {
    throw new RuntimeException('UI-shaped owner config must persist in the actual pending store');
}
if (pcv_normalize_config($uiMappedConfiguration, $eligibleActors) !== $uiMappedConfiguration) {
    throw new RuntimeException('owner-normalized UI config must be stable across the actual stage normalization');
}

$pairStage = pcv_stage($pcvIdentity['key'], [
    'enabled' => true,
    'scene_mode' => 'pair',
    'actor_a' => '101',
    'actor_b' => '303',
    'exclude_player' => false,
    'bystander_mode' => 'silent',
], $eligibleActors, $stateDirectory);
$stateReadback = qd_pcv_read_state_directory($stateDirectory, $pcvIdentity['key']);
$pairProjection = qd_pcv_status_projection($stateReadback, $pcvIdentity['key'], $eligibleActors);
if (($pairProjection['state'] ?? null) !== 'pending'
    || ($pairProjection['pending']['actor_a'] ?? null) !== 'npc:101'
    || ($pairProjection['pending']['actor_b'] ?? null) !== 'npc:303'
    || isset($pairProjection['pending']['actor_ids']) || isset($pairProjection['pending']['opener'])) {
    throw new RuntimeException('a real PCV pair stage must project only UI actor keys');
}

$activatedPair = pcv_begin_request($pcvIdentity['key'], true, $stateDirectory, $eligibleActors);
$activeConfigId = $pairProjection['pendingConfigId'] ?? null;
if (($activatedPair['status'] ?? null) !== 'active' || ($activatedPair['config_id'] ?? null) !== $activeConfigId) {
    throw new RuntimeException('the real owner must activate the staged pair before replacement Arm');
}
$replacementUiConfiguration = json_decode(
    '{"actor_a":"npc:303","scene_mode":"pair","bystander_mode":"silent","enabled":true,'
        . '"exclude_player":true,"actor_b":"npc:101","pace":"normal","card":"","actions":[]}',
    false,
    8,
    JSON_THROW_ON_ERROR,
);
$replacementConfiguration = qd_pcv_normalize_ui_configuration($replacementUiConfiguration, $eligibleActors, $eligibleActors);
$replacementGuardCalls = 0;
$replacementStage = pcv_stage($pcvIdentity['key'], $replacementConfiguration, $eligibleActors, $stateDirectory, null,
    static function (array $before, array $after, array $metadata, callable $commit) use (
        &$replacementGuardCalls, $activeConfigId, $replacementConfiguration
    ): array {
        $replacementGuardCalls++;
        if (($before['active']['config_id'] ?? null) !== $activeConfigId || ($before['pending'] ?? null) !== null
            || ($after['active']['config_id'] ?? null) !== $activeConfigId
            || ($after['pending']['config'] ?? null) !== $replacementConfiguration
            || !pcv_valid_config_id($after['pending']['config_id'] ?? null)) {
            return ['status' => 'refused', 'outcome' => 'known_no_write', 'reason' => 'conflict'];
        }
        $commit();
        return ['status' => 'committed', 'outcome' => 'owner_commit'];
    });
$replacementReadback = qd_pcv_status_projection(pcv_load_store($stateDirectory), $pcvIdentity['key'], $eligibleActors);
if (($replacementStage['status'] ?? null) !== 'active' || $replacementGuardCalls !== 1
    || ($replacementStage['config_id'] ?? null) !== $activeConfigId
    || !is_string($replacementStage['pending_config_id'] ?? null)
    || $replacementStage['pending_config_id'] === $activeConfigId
    || ($replacementReadback['activeConfigId'] ?? null) !== $activeConfigId
    || ($replacementReadback['pendingConfigId'] ?? null) !== $replacementStage['pending_config_id']
    || ($replacementReadback['pending']['exclude_player'] ?? null) !== true) {
    throw new RuntimeException('active-scene Arm must accept the real owner active-plus-pending result with a fresh pending config identity');
}

/*
 * Companion lifecycle and guarded mutations now live in the server facade. Their assertions are
 * kept in server/tests/foundation/run.php (journal replay/uncertainty),
 * server/tests/pcv/companion_store.php (one-use token lifecycle/retention), and
 * server/tests/pcv/companion_owner_seam.php (locked stage/begin callbacks). The stock-main
 * Pending-to-Active route is covered by the separate integration proof when its runtime is run.
 */
echo "PASS PCV adapter source and read-only tests\n";
