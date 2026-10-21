<?php
declare(strict_types=1);

require_once __DIR__ . '/log.php';

const PCV_STATE_VERSION = 1;
const PCV_PENDING_TTL = 900;
const PCV_ACTIVE_TTL = 3600;
const PCV_PRESENCE_TTL = 45;
// observed_at has whole-second resolution and activity is reported just after its heartbeat,
// so a same-second read can compute a slightly negative age; tolerate up to one second of it.
const PCV_PRESENCE_CLOCK_TOLERANCE = 1;
// Busy places report many actors (live maximum seen: 79); generic duplicates are kept so names stay ambiguous.
const PCV_PRESENCE_MAX_ACTORS = 128;
// Room for the actor list plus the bounded recent-name map (both at most 128 names).
const PCV_PRESENCE_DOCUMENT_MAX_BYTES = 131072;
const PCV_BACKGROUND_PRESENCE_VERSION = 1;
// Inside an active scene a participant may be out of the close report this long (live: partners wander mid-scene).
const PCV_PRESENCE_ACTIVE_GRACE = 60;
const PCV_WIDE_PRESENCE_VERSION = 1;
// An early-line ACK within this window of a solo request start is "reply in progress", not "registration missing".
const PCV_SOLO_INFLIGHT_TTL = 180;
// Group scenes (0.1.11) hold 2 to 4 members.
const PCV_GROUP_MAX_MEMBERS = 4;
const PCV_FREE_MAX_MEMBERS = 6;
const PCV_ACTIVE_REFUSAL_END = 300;
const PCV_SCENE_CARD_MAX_CHARS = 300;
const PCV_SCENE_ACTION_GROUPS = ['personal', 'physical', 'intimate'];

function pcv_shared_server_identity($playerName): array
{
    $name = is_string($playerName) ? trim($playerName) : '';
    if ($name === '' || strlen($name) > 256 || preg_match('//u', $name) !== 1
        || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
        return ['key' => null, 'player_name' => null];
    }
    $identityName = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    return ['key' => hash('sha256', 'unprofiled:' . $identityName), 'player_name' => $name];
}

/** A verified empty profile result is distinct from an unavailable helper read. */
function pcv_home_state_has_no_active_profile(array $homeState): bool
{
    $activeId = filter_var($homeState['active_id'] ?? null, FILTER_VALIDATE_INT);
    $profiles = $homeState['playthroughs'] ?? null;
    if (($homeState['available'] ?? false) !== true || $activeId !== 0
        || !is_array($profiles)) {
        return false;
    }
    foreach ($profiles as $profile) {
        if (!is_array($profile) || !is_bool($profile['active'] ?? null) || $profile['active']) {
            return false;
        }
    }
    return true;
}

/** Derive a key from verified profile state, or an explicit shared-server scope. */
function pcv_identity_from_home_state(
    array $homeState,
    bool $profileTableExists = true,
    ?string $corePlayerName = null
): array {
    $unavailable = ['key' => null, 'player_name' => null];
    if (!$profileTableExists || pcv_home_state_has_no_active_profile($homeState)) {
        return pcv_shared_server_identity($corePlayerName);
    }
    if (($homeState['available'] ?? false) !== true) {
        return $unavailable;
    }

    $profileId = filter_var($homeState['active_id'] ?? null, FILTER_VALIDATE_INT);
    $profiles = $homeState['playthroughs'] ?? null;
    if ($profileId === false || $profileId < 1 || !is_array($profiles)) {
        return $unavailable;
    }

    $active = [];
    foreach ($profiles as $profile) {
        if (!is_array($profile) || !is_bool($profile['active'] ?? null)) {
            return $unavailable;
        }
        if ($profile['active']) {
            $active[] = $profile;
        }
    }
    if (count($active) !== 1 || (int)($active[0]['id'] ?? 0) !== $profileId) {
        return $unavailable;
    }

    $characterId = $active[0]['character_id'] ?? null;
    if (!is_string($characterId) || preg_match('/^[a-f0-9]{32}$/D', $characterId) !== 1) {
        return $unavailable;
    }

    $playerName = $active[0]['player_name'] ?? null;
    $playerName = is_string($playerName) ? trim($playerName) : '';
    if ($playerName === '' || preg_match('//u', $playerName) !== 1) {
        $playerName = null;
    }

    return [
        'key' => hash('sha256', $profileId . ':' . $characterId),
        'player_name' => $playerName,
    ];
}

function pcv_profile_table_exists($connection): bool
{
    if (!function_exists('pg_query_params') || !function_exists('pg_num_rows') || !function_exists('pg_fetch_assoc')) {
        throw new RuntimeException('Core profile lookup is unavailable.');
    }
    $result = @pg_query_params($connection,
        "SELECT to_regclass('chim_meta.playthrough_profiles') AS profile_table", []);
    if ($result === false) {
        throw new RuntimeException('Core profile lookup failed.');
    }
    try {
        if (pg_num_rows($result) !== 1) {
            throw new RuntimeException('Core profile lookup returned an invalid result.');
        }
        $row = pg_fetch_assoc($result);
        if (!is_array($row) || !array_key_exists('profile_table', $row)) {
            throw new RuntimeException('Core profile lookup returned an invalid result.');
        }
        if ($row['profile_table'] === null) {
            return false;
        }
        if (!is_string($row['profile_table']) || $row['profile_table'] === '') {
            throw new RuntimeException('Core profile lookup returned an invalid result.');
        }
        return true;
    } finally {
        @pg_free_result($result);
    }
}

function pcv_core_player_name($connection): ?string
{
    if (!function_exists('pg_query_params') || !function_exists('pg_num_rows') || !function_exists('pg_fetch_assoc')) {
        throw new RuntimeException('Core player lookup is unavailable.');
    }
    $result = @pg_query_params($connection,
        'SELECT value FROM public.core_player WHERE id = $1 LIMIT 2', ['player_name']);
    if ($result === false) {
        throw new RuntimeException('Core player lookup failed.');
    }
    try {
        if (pg_num_rows($result) !== 1) {
            return null;
        }
        $row = pg_fetch_assoc($result);
        $name = is_array($row) && is_string($row['value'] ?? null) ? trim($row['value']) : '';
        return $name !== '' && preg_match('//u', $name) === 1 ? $name : null;
    } finally {
        @pg_free_result($result);
    }
}

/** Read and cache the core's current profile or shared-server identity once per request. */
function pcv_current_identity(bool $refresh = false): array
{
    static $loaded = false;
    static $identity = ['key' => null, 'player_name' => null];
    if ($refresh) {
        $loaded = false;
        $identity = ['key' => null, 'player_name' => null];
    }
    if ($loaded) {
        return $identity;
    }
    $loaded = true;

    $engineRoot = dirname(__DIR__, 2);
    $homeHelper = $engineRoot . '/lib/playthrough_home.php';
    if (!is_file($homeHelper)) {
        return $identity;
    }

    $connection = null;
    try {
        require_once $homeHelper;
        if (!function_exists('ptp_connect')) {
            return $identity;
        }

        $connection = ptp_connect();
        if (!$connection) {
            return $identity;
        }
        $profileTableExists = pcv_profile_table_exists($connection);
        if (!$profileTableExists) {
            $identity = pcv_identity_from_home_state([], false, pcv_core_player_name($connection));
            return $identity;
        }
        if (!function_exists('pth_state')) {
            return $identity;
        }
        $homeState = pth_state($connection);
        $corePlayerName = pcv_home_state_has_no_active_profile($homeState)
            ? pcv_core_player_name($connection) : null;
        $identity = pcv_identity_from_home_state($homeState, $profileTableExists, $corePlayerName);
    } catch (Throwable $error) {
        pcv_log_exception('state.unavailable', 'error', 'unavailable', 'profile_lookup_failed', $error, ['operation' => 'identity']);
        $identity = ['key' => null, 'player_name' => null];
    } finally {
        if ($connection !== null && $connection !== false && function_exists('pg_close')) {
            @pg_close($connection);
        }
    }

    return $identity;
}

/** Return null when there is no unambiguous active profile and character identity. */
function pcv_current_playthrough_key(bool $refresh = false): ?string
{
    $key = pcv_current_identity($refresh)['key'];
    pcv_log_set_playthrough_ref($key);
    return $key;
}

/** Return the active character's display name for filtering UI choices only. */
function pcv_current_player_name(): ?string
{
    return pcv_current_identity()['player_name'];
}

function pcv_valid_key(string $key): bool
{
    return preg_match('/^[a-f0-9]{64}$/D', $key) === 1;
}

function pcv_valid_config_id($configId): bool
{
    return $configId === null || (is_string($configId) && pcv_log_valid_uuid($configId));
}

function pcv_state_validate_legacy_json_object(string $path, int $maximumBytes): void
{
    $size = @filesize($path);
    if (!is_int($size) || $size > $maximumBytes) {
        throw new RuntimeException('Legacy state file is unavailable.');
    }
    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('Legacy state file is unavailable.');
    }
    try {
        $document = json_decode($contents, false, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Legacy state file is invalid.');
    }
    if (!is_object($document)) {
        throw new RuntimeException('Legacy state file is invalid.');
    }
}

/** The directory lock must be held by the caller. */
function pcv_state_validate_legacy_directory(string $directory): void
{
    $files = [
        'state.lock' => 4096,
        'state.json' => 16384,
        'presence.json' => PCV_PRESENCE_DOCUMENT_MAX_BYTES,
        'background_presence.json' => PCV_PRESENCE_DOCUMENT_MAX_BYTES,
        'background_wide_presence.json' => PCV_PRESENCE_DOCUMENT_MAX_BYTES,
        'solo_inflight.json' => 512,
        'scene_turns.json' => 4096,
        'reflection.json' => 8192,
        'reflection_receipts.json' => 8192,
    ];
    $entries = @scandir($directory);
    if (!is_array($entries) || count($entries) > 64) {
        throw new RuntimeException('Legacy state directory is unavailable.');
    }

    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Legacy state directory is not safe.');
        }
        if (array_key_exists($name, $files)) {
            $size = @filesize($path);
            if (!is_int($size) || $size > $files[$name]) {
                throw new RuntimeException('Legacy state file is unavailable.');
            }
            continue;
        }
        if (preg_match('/\\A\\.(?:state|background-presence|wide-presence|solo-inflight|presence|reflection|reflection-receipts)-[A-Za-z0-9]{6}\\z/D', $name) === 1) {
            $size = @filesize($path);
            if (!is_int($size) || $size > PCV_PRESENCE_DOCUMENT_MAX_BYTES) {
                throw new RuntimeException('Legacy state temporary file is unavailable.');
            }
            continue;
        }
        throw new RuntimeException('Legacy state directory contains an unknown file.');
    }

    $store = pcv_load_store($directory);
    if (!in_array($store['kind'] ?? null, ['missing', 'ready'], true)) {
        throw new RuntimeException('Legacy state is invalid.');
    }
    foreach (['presence.json', 'background_presence.json'] as $name) {
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (file_exists($path)) {
            pcv_state_validate_legacy_json_object($path, $files[$name]);
        }
    }

    $hasReflectionData = file_exists($directory . DIRECTORY_SEPARATOR . 'reflection.json')
        || file_exists($directory . DIRECTORY_SEPARATOR . 'reflection_receipts.json');
    if ($hasReflectionData) {
        require_once __DIR__ . '/reflection.php';
        if (file_exists($directory . DIRECTORY_SEPARATOR . 'reflection.json')
            && pcv_reflection_read_locked($directory)['kind'] !== 'ready') {
            throw new RuntimeException('Legacy reflection registry is invalid.');
        }
        if (file_exists($directory . DIRECTORY_SEPARATOR . 'reflection_receipts.json')
            && pcv_reflection_read_receipts_locked($directory)['kind'] !== 'ready') {
            throw new RuntimeException('Legacy reflection receipts are invalid.');
        }
    }
}

/** Move a legacy web-root store whole; never merge or copy partial state. */
function pcv_state_migrate_legacy_directory(string $legacyDirectory, string $privateDirectory): void
{
    clearstatcache(true, $legacyDirectory);
    clearstatcache(true, $privateDirectory);
    if (is_link($legacyDirectory)) {
        throw new RuntimeException('Legacy state directory is not safe.');
    }
    if (!file_exists($legacyDirectory)) {
        if (is_link($privateDirectory) || (file_exists($privateDirectory) && !is_dir($privateDirectory))) {
            throw new RuntimeException('Private state directory is not safe.');
        }
        if (is_dir($privateDirectory) && !@chmod($privateDirectory, 0700)) {
            throw new RuntimeException('Private state directory permissions are unavailable.');
        }
        return;
    }
    if (!is_dir($legacyDirectory) || is_link($privateDirectory) || file_exists($privateDirectory)) {
        throw new RuntimeException('State migration has a conflicting directory.');
    }

    $handle = pcv_lock_state($legacyDirectory, false, LOCK_EX);
    if ($handle === null) {
        throw new RuntimeException('Legacy state directory is unavailable.');
    }
    try {
        clearstatcache(true, $legacyDirectory);
        clearstatcache(true, $privateDirectory);
        if (!file_exists($legacyDirectory)) {
            if (!is_dir($privateDirectory) || is_link($privateDirectory)) {
                throw new RuntimeException('State migration could not be resolved.');
            }
            if (!@chmod($privateDirectory, 0700)) {
                throw new RuntimeException('Private state directory permissions are unavailable.');
            }
            return;
        }
        if (!is_dir($legacyDirectory) || is_link($legacyDirectory)
            || is_link($privateDirectory) || file_exists($privateDirectory)) {
            throw new RuntimeException('State migration has a conflicting directory.');
        }
        pcv_state_validate_legacy_directory($legacyDirectory);
        if (!@rename($legacyDirectory, $privateDirectory)) {
            throw new RuntimeException('Legacy state could not be moved to private storage.');
        }
        if (!@chmod($privateDirectory, 0700)) {
            throw new RuntimeException('Private state directory permissions are unavailable.');
        }
    } finally {
        pcv_unlock_state($handle);
    }
}

function pcv_state_directory(?string $stateDirectory): string
{
    if ($stateDirectory === null) {
        $privateRoot = pcv_log_default_directory(true);
        if (!is_string($privateRoot)) {
            throw new RuntimeException('Private state storage is unavailable.');
        }
        $directory = $privateRoot . DIRECTORY_SEPARATOR . 'state';
        pcv_state_migrate_legacy_directory(__DIR__ . DIRECTORY_SEPARATOR . 'state', $directory);
    } else {
        $directory = $stateDirectory;
    }
    if ($directory === '' || str_contains($directory, "\0")) {
        throw new InvalidArgumentException('Invalid state directory.');
    }
    if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
        throw new RuntimeException('State directory is not safe.');
    }
    return rtrim($directory, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;
}

/** Return null for a missing, not-yet-created state directory. */
function pcv_lock_state(string $directory, bool $create, int $mode)
{
    if (is_link($directory)) {
        throw new RuntimeException('State directory is not safe.');
    }
    if (!is_dir($directory)) {
        if (!$create) {
            return null;
        }
        if (!@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('State directory is unavailable.');
        }
        @chmod($directory, 0770);
    }

    $lockPath = $directory . DIRECTORY_SEPARATOR . 'state.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('State lock is not safe.');
    }
    $handle = @fopen($lockPath, 'c');
    if ($handle === false) {
        throw new RuntimeException('State lock is unavailable.');
    }
    if (!flock($handle, $mode)) {
        fclose($handle);
        throw new RuntimeException('State lock is unavailable.');
    }
    @chmod($lockPath, 0660);
    return $handle;
}

function pcv_unlock_state($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function pcv_empty_store(string $key): array
{
    return ['version' => PCV_STATE_VERSION, 'key' => $key, 'active' => null, 'pending' => null];
}

/** A scene card (0.1.14): 1-300 characters of single-line UTF-8 text. */
function pcv_valid_scene_card($card): bool
{
    return is_string($card) && $card !== '' && $card === trim($card) && preg_match('//u', $card) === 1
        && preg_match('/[\x00-\x1f\x7f]/', $card) !== 1
        && (function_exists('mb_strlen') ? mb_strlen($card, 'UTF-8') : strlen($card)) <= PCV_SCENE_CARD_MAX_CHARS;
}

/** Optional 0.1.14 roleplay settings: card (any scene), pace (non-solo; normal = absent), free_cap (free only). */
function pcv_valid_scene_extras(array $config, ?string $sceneMode): bool
{
    if (array_key_exists('card', $config) && !pcv_valid_scene_card($config['card'])) {
        return false;
    }
    if (array_key_exists('pace', $config) && ($sceneMode !== 'pair' || !in_array($config['pace'], ['short', 'long'], true))) {
        return false;
    }
    if (array_key_exists('actions', $config)) {
        // 0.1.16 scene actions: a non-empty canonical subset; solo has no one to brawl with.
        $actions = $config['actions'];
        $canonical = is_array($actions) ? array_values(array_intersect(PCV_SCENE_ACTION_GROUPS, $actions)) : null;
        if (!is_array($actions) || !array_is_list($actions) || $actions === [] || $canonical !== $actions
            || ($sceneMode === 'solo' && in_array('physical', $actions, true))) {
            return false;
        }
    }
    if (array_key_exists('free_cap', $config)) {
        $cap = $config['free_cap'];
        if (($config['free'] ?? false) !== true || !is_int($cap) || $cap < 2 || $cap > PCV_FREE_MAX_MEMBERS) {
            return false;
        }
    }
    return true;
}

function pcv_valid_config($config, bool $allowDisabled): bool
{
    $sceneMode = is_array($config) && array_key_exists('scene_mode', $config)
        ? $config['scene_mode'] : (is_array($config) ? 'pair' : null);
    if (!is_array($config)
        || !is_bool($config['enabled'] ?? null)
        || !is_bool($config['exclude_player'] ?? null)
        || !in_array($config['bystander_mode'] ?? null, ['exclude', 'silent'], true)
        || !in_array($sceneMode, ['pair', 'solo'], true)) {
        return false;
    }

    if (!$config['enabled']) {
        return $allowDisabled
            && $sceneMode === 'pair'
            && ($config['actor_a'] ?? null) === ''
            && ($config['actor_b'] ?? null) === '';
    }

    if (!pcv_valid_scene_extras($config, $sceneMode)) {
        return false;
    }

    // Free scene (0.1.12): members are chosen at activation; the player is always excluded.
    $free = array_key_exists('free', $config);
    if ($free) {
        if ($config['free'] !== true || $sceneMode !== 'pair' || $config['exclude_player'] !== true) {
            return false;
        }
        if (!array_key_exists('actor_a', $config) && !array_key_exists('actor_b', $config)
            && !array_key_exists('actor_ids', $config) && !array_key_exists('opener', $config)) {
            return true;
        }
        if (!array_key_exists('actor_ids', $config)) {
            return false;
        }
    }

    $actorA = $config['actor_a'] ?? null;
    $actorB = $config['actor_b'] ?? null;
    $validActorA =is_string($actorA) && $actorA !== '' && strlen($actorA) <= 256
        && preg_match('//u', $actorA) === 1 && preg_match('/[\x00-\x1f\x7f]/', $actorA) !== 1;
    if (!$validActorA) {
        return false;
    }
    if ($sceneMode === 'solo') {
        return array_key_exists('actor_b', $config) && $actorB === null && $config['exclude_player'] === true
            && !array_key_exists('actor_ids', $config) && !array_key_exists('opener', $config);
    }
    $validB = is_string($actorB) && $actorB !== '' && strlen($actorB) <= 256
        && $actorA !== $actorB
        && preg_match('//u', $actorB) === 1
        && preg_match('/[\x00-\x1f\x7f]/', $actorB) !== 1;
    if (!$validB) {
        return false;
    }
    // Legacy two-member pair: no member list and no opener.
    if (!array_key_exists('actor_ids', $config)) {
        return !array_key_exists('opener', $config);
    }
    // Group (0.1.11): 2-4 ordered members; actor_a/actor_b mirror the first two.
    $ids = $config['actor_ids'];
    if (!is_array($ids) || !array_is_list($ids) || count($ids) < 2 || count($ids) > ($free ? PCV_FREE_MAX_MEMBERS : PCV_GROUP_MAX_MEMBERS)
        || ($ids[0] ?? null) !== $actorA || ($ids[1] ?? null) !== $actorB) {
        return false;
    }
    foreach ($ids as $id) {
        if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) !== 1) {
            return false;
        }
    }
    if (count(array_unique($ids)) !== count($ids)) {
        return false;
    }
    $opener = $config['opener'] ?? null;
    return $opener === 'auto' || (is_string($opener) && in_array($opener, $ids, true));
}

function pcv_valid_stored_state(array $state): bool
{
    if (($state['version'] ?? null) !== PCV_STATE_VERSION || !pcv_valid_key($state['key'] ?? '')
        || !array_key_exists('active', $state) || !array_key_exists('pending', $state)) {
        return false;
    }

    if ($state['active'] !== null) {
        $active = $state['active'];
        // 0.1.13: the lifetime slides with use; renewed_at (when present) is the last renewal.
        $renewedAt = $active['renewed_at'] ?? null;
        if (!is_array($active) || !pcv_valid_config($active['config'] ?? null, false)
            || !is_int($active['activated_at'] ?? null) || !is_int($active['expires_at'] ?? null)
            || $active['activated_at'] < 1 || $active['expires_at'] <= $active['activated_at']
            || ($renewedAt !== null && (!is_int($renewedAt) || $renewedAt < $active['activated_at']))
            || $active['expires_at'] > (is_int($renewedAt) ? $renewedAt : $active['activated_at']) + PCV_ACTIVE_TTL) {
            return false;
        }
        if (array_key_exists('refused_since', $active)
            && (!is_int($active['refused_since']) || $active['refused_since'] < $active['activated_at'])) {
            return false;
        }
        if (array_key_exists('dropped', $active)) {
            $dropped = $active['dropped'];
            if (!is_array($dropped) || !array_is_list($dropped) || count($dropped) > PCV_FREE_MAX_MEMBERS) {
                return false;
            }
            foreach ($dropped as $entry) {
                if (!is_array($entry) || count($entry) !== 2
                    || !is_string($entry['id'] ?? null) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $entry['id']) !== 1
                    || !in_array($entry['reason'] ?? null, ['not_eligible_at_start', 'left_scene'], true)) {
                    return false;
                }
            }
        }
    }

    if (array_key_exists('last_end', $state)) {
        $lastEnd = $state['last_end'];
        if (!is_array($lastEnd) || count($lastEnd) !== 2
            || !in_array($lastEnd['reason'] ?? null, ['members_gone', 'evidence_unavailable'], true)
            || !is_int($lastEnd['at'] ?? null) || $lastEnd['at'] < 1) {
            return false;
        }
    }

    if ($state['pending'] !== null) {
        $pending = $state['pending'];
        if (!is_array($pending) || !pcv_valid_config($pending['config'] ?? null, true)
            || !is_int($pending['staged_at'] ?? null) || !is_int($pending['expires_at'] ?? null)
            || $pending['staged_at'] < 1 || $pending['expires_at'] <= $pending['staged_at']
            || $pending['expires_at'] > $pending['staged_at'] + PCV_PENDING_TTL) {
            return false;
        }
    }

    return true;
}

/** The caller must hold the state lock. */
function pcv_load_store(string $directory): array
{
    $path = $directory . DIRECTORY_SEPARATOR . 'state.json';
    if (is_link($path)) {
        return ['kind' => 'unavailable', 'reason' => 'symlinked_state'];
    }
    if (!file_exists($path)) {
        return ['kind' => 'missing'];
    }
    if (!is_file($path)) {
        return ['kind' => 'unavailable', 'reason' => 'not_regular_file'];
    }

    $size = @filesize($path);
    if ($size === false) {
        return ['kind' => 'unavailable', 'reason' => 'state_stat_failed'];
    }
    if ($size > 16384) {
        return ['kind' => 'unavailable', 'reason' => 'state_too_large'];
    }
    $contents = @file_get_contents($path);
    if ($contents === false) {
        return ['kind' => 'unavailable', 'reason' => 'state_read_failed'];
    }

    try {
        $state = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['kind' => 'unavailable', 'reason' => 'invalid_json'];
    }
    if (!is_array($state) || !pcv_valid_stored_state($state)) {
        return ['kind' => 'unavailable', 'reason' => 'invalid_state'];
    }

    return ['kind' => 'ready', 'state' => $state];
}

/** The caller must hold the state lock. */
function pcv_write_store(string $directory, array $state): void
{
    $path = $directory . DIRECTORY_SEPARATOR . 'state.json';
    if (is_link($path)) {
        throw new RuntimeException('State file is not safe.');
    }
    $contents = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $temporary = tempnam($directory, '.state-');
    if ($temporary === false) {
        throw new RuntimeException('Could not stage state update.');
    }

    try {
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException('Could not write state update.');
        }
        @chmod($temporary, 0660);
        if (!@rename($temporary, $path)) {
            throw new RuntimeException('Could not commit state update.');
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function pcv_presence_result(string $status, array $actors = [], ?float $radius = null, ?int $observedAt = null, ?string $reason = null): array
{
    return ['status' => $status, 'actors' => $actors, 'radius' => $radius, 'observed_at' => $observedAt, 'reason' => $reason];
}

/** Log the observation unless it is a routine success the caller marked as unchanged. */
function pcv_presence_observed_result(string $source, array $result, bool $logRoutineSuccess = true): array
{
    $observation = is_array($result['_pcv_observation'] ?? null) ? $result['_pcv_observation'] : [];
    unset($result['_pcv_observation']);
    $sourceStatus = $observation['status'] ?? $result['status'] ?? null;
    $status = match ($sourceStatus) {
        'ready' => 'available',
        'empty' => 'empty',
        'stale' => 'stale',
        default => 'unavailable',
    };
    $reason = null;
    if (is_string($observation['reason'] ?? null)) {
        $reason = $observation['reason'];
    } elseif ($sourceStatus === 'baseline'
        || ($sourceStatus === 'stale' && ($result['reason'] ?? null) === 'presence_baseline')) {
        $status = 'unavailable';
        $reason = 'presence_baseline';
    } elseif ($sourceStatus === 'missing') {
        $reason = 'presence_missing';
    } elseif (in_array($status, ['stale', 'unavailable'], true)) {
        $reason = $result['reason'] ?? null;
        if (!in_array($reason, ['presence_stale', 'presence_unavailable', 'presence_missing', 'presence_baseline', 'presence_invalid', 'presence_key_mismatch', 'identity_unavailable'], true)) {
            $reason = $status === 'stale' ? 'presence_stale' : 'presence_unavailable';
        }
    }
    $actors = $result['known_npcs'] ?? $result['actors'] ?? [];
    if ($logRoutineSuccess || !in_array($status, ['available', 'empty'], true)) {
        pcv_log_presence_observed($source, $status, is_array($actors) ? count($actors) : 0, $reason);
    }
    return $result;
}

function pcv_presence_read_failure(array $empty, string $reason): array
{
    pcv_log_event('state.unavailable', 'error', 'unavailable', $reason, ['operation' => 'presence_read']);
    return array_replace($empty, ['status' => 'unavailable', 'reason' => $reason]);
}

/** Parse the native player-routing payload without retaining unrelated request data. */
function pcv_parse_presence_snapshot($raw): array
{
    if ($raw === null || $raw === '') {
        return pcv_presence_result('missing', reason: 'presence_missing');
    }
    if (!is_string($raw) || strlen($raw) > 65536) {
        return pcv_presence_result('unavailable', reason: 'presence_invalid');
    }
    $json = base64_decode($raw, true);
    if (!is_string($json) || $json === '' || strlen($json) > 32768) {
        return pcv_presence_result('unavailable', reason: 'presence_invalid');
    }
    try {
        $payload = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return pcv_presence_result('unavailable', reason: 'presence_invalid');
    }
    if (!is_array($payload) || ($payload['source'] ?? null) !== 'plugin_player_routing_v2') {
        return pcv_presence_result('unavailable', reason: 'presence_invalid');
    }
    if (($payload['speech_mode'] ?? null) !== 'standard'
        || !is_string($payload['execution_mode'] ?? null)
        || strtoupper(trim($payload['execution_mode'])) !== 'STANDARD') {
        return pcv_presence_result('unavailable', reason: 'unsupported_mode');
    }
    $radius = $payload['audience_radius_units'] ?? null;
    if ((!is_int($radius) && !is_float($radius)) || !is_finite((float)$radius) || $radius <= 0
        || !is_array($payload['present_actors'] ?? null) || !array_is_list($payload['present_actors'])
        || count($payload['present_actors']) > PCV_PRESENCE_MAX_ACTORS) {
        return pcv_presence_result('unavailable', reason: 'presence_invalid');
    }

    $actors = [];
    foreach ($payload['present_actors'] as $actor) {
        if (!is_array($actor) || (!is_int($actor['form_id'] ?? null) && !is_float($actor['form_id'] ?? null))
            || !is_string($actor['name'] ?? null) || (!is_int($actor['distance'] ?? null) && !is_float($actor['distance'] ?? null))
            || !is_bool($actor['managed'] ?? null) || !is_bool($actor['creature'] ?? null)) {
            return pcv_presence_result('unavailable', reason: 'presence_invalid');
        }
        $formId = (float)$actor['form_id'];
        $distance = (float)$actor['distance'];
        $name = trim($actor['name']);
        if (!is_finite($formId) || $formId < 1 || $formId > 4294967295 || floor($formId) !== $formId
            || !is_finite($distance) || $distance < 0
            || $name === '' || strlen($name) > 256 || preg_match('//u', $name) !== 1) {
            return pcv_presence_result('unavailable', reason: 'presence_invalid');
        }
        if (!$actor['managed'] || $distance > $radius || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            continue;
        }
        $actors[] = ['form_id' => (int)$formId, 'name' => $name, 'distance' => $distance];
    }
    return pcv_presence_result($actors === [] ? 'empty' : 'ready', $actors, (float)$radius);
}

/** Parse the native request timestamp without treating it as a wall-clock age. */
function pcv_parse_presence_request_timestamp($value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }
    if (!is_string($value) || preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1) {
        return null;
    }
    $timestamp = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return is_int($timestamp) ? $timestamp : null;
}

/** Parse the names from CHIM's periodic `infonpc_close` report. */
function pcv_parse_background_presence_report($raw, ?string $currentPlayerName): array
{
    if (!is_string($raw) || $raw === '' || strlen($raw) > 32768 || preg_match('//u', $raw) !== 1
        || !is_string($currentPlayerName) || trim($currentPlayerName) === ''
        || strlen($currentPlayerName) > 256 || preg_match('//u', $currentPlayerName) !== 1) {
        return pcv_presence_result('unavailable', reason: 'presence_invalid');
    }

    $tokens = preg_split('/\//u', trim($raw));
    // Raw reports contain empty tokens ("Name//Player"), so allow them on top of the actor bound.
    if (!is_array($tokens) || count($tokens) > 2 * PCV_PRESENCE_MAX_ACTORS + 1) {
        return pcv_presence_result('unavailable', reason: 'presence_invalid');
    }
    if (!function_exists('pcv_scope_name_key')) {
        require_once __DIR__ . '/scope.php';
    }
    $playerKey = pcv_scope_name_key($currentPlayerName);
    $playerCount = 0;
    $actors = [];
    foreach ($tokens as $token) {
        $name = trim((string)$token);
        if ($name === '') {
            continue;
        }
        if (function_exists('chimDataStripActorStateSuffix')) {
            $name = chimDataStripActorStateSuffix($name);
        } else {
            $name = preg_replace(
                '/\s*\((?:busy|hostile|in combat|far away|too far away|restrained|dead|disabled|unavailable|sleeping|unconscious)\)\s*$/iu',
                '',
                $name
            );
            $name = trim((string)$name);
        }
        if ($name === '' || strlen($name) > 256 || preg_match('//u', $name) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            return pcv_presence_result('unavailable', reason: 'presence_invalid');
        }

        if (pcv_scope_name_key($name) === $playerKey) {
            $playerCount++;
            continue;
        }
        $actors[] = ['name' => $name];
        if (count($actors) > PCV_PRESENCE_MAX_ACTORS) {
            return pcv_presence_result('unavailable', reason: 'presence_invalid');
        }
    }

    if ($playerCount !== 1) {
        return pcv_presence_result('unavailable', reason: 'identity_unavailable');
    }
    return pcv_presence_result($actors === [] ? 'empty' : 'ready', $actors);
}

/** Read an activity timestamp from the catalog's CHIM metadata projection. */
function pcv_background_activity_timestamp(array $row): ?int
{
    $metadata = $row['metadata'] ?? null;
    if (is_string($metadata)) {
        try {
            $metadata = json_decode($metadata, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
    if (!is_array($metadata)) {
        return null;
    }
    $activity = $metadata['activity_status'] ?? null;
    $timestamp = is_array($activity)
        ? ($activity['timestamp'] ?? null)
        : ($metadata['activity_status_timestamp'] ?? null);
    return pcv_parse_presence_request_timestamp($timestamp);
}

/** Atomically replace the bounded background report while the state lock is held. */
function pcv_write_background_presence(string $directory, array $document): void
{
    $path = $directory . DIRECTORY_SEPARATOR . 'background_presence.json';
    if (is_link($path)) {
        throw new RuntimeException('Background presence report is not safe.');
    }
    $contents = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    $temporary = tempnam($directory, '.background-presence-');
    if ($temporary === false) {
        throw new RuntimeException('Could not stage background presence report.');
    }
    try {
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException('Could not write background presence report.');
        }
        @chmod($temporary, 0660);
        if (!@rename($temporary, $path)) {
            throw new RuntimeException('Could not commit background presence report.');
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

/** Remove an unreadable or uncommittable report while the state lock is held. */
function pcv_remove_background_presence_locked(string $directory): void
{
    $path = $directory . DIRECTORY_SEPARATOR . 'background_presence.json';
    if (is_link($path)) {
        throw new RuntimeException('Background presence report is not safe.');
    }
    if (file_exists($path) && (!is_file($path) || !@unlink($path))) {
        throw new RuntimeException('Could not invalidate background presence report.');
    }
}

/** Invalidate only the old request snapshot; background reports have their own lifecycle. */
function pcv_invalidate_ordinary_presence_snapshot(?string $stateDirectory = null): array
{
    $handle = null;
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, false, LOCK_EX);
        if ($handle === null) {
            return ['status' => 'missing', 'reason' => 'presence_missing'];
        }
        $path = $directory . DIRECTORY_SEPARATOR . 'presence.json';
        if (is_link($path) || (file_exists($path) && (!is_file($path) || !@unlink($path)))) {
            pcv_log_event('state.unavailable', 'error', 'unavailable', 'presence_unavailable', ['operation' => 'presence_invalidate']);
            return ['status' => 'unavailable', 'reason' => 'presence_unavailable'];
        }
        return ['status' => 'missing', 'reason' => 'presence_missing'];
    } catch (Throwable $error) {
        pcv_log_exception('state.unavailable', 'error', 'unavailable', 'presence_unavailable', $error, ['operation' => 'presence_invalidate']);
        return ['status' => 'unavailable', 'reason' => 'presence_unavailable'];
    } finally {
        pcv_unlock_state($handle);
    }
}

/** Remove the cached report before identity synchronization or after an invalid ordinary payload. */
function pcv_invalidate_eligible_npcs(?string $stateDirectory = null): array
{
    $handle = null;
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, false, LOCK_EX);
        if ($handle === null) {
            return ['status' => 'missing', 'reason' => 'presence_missing'];
        }
        foreach (['presence.json', 'background_presence.json', 'background_wide_presence.json'] as $name) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_link($path) || (file_exists($path) && (!is_file($path) || !@unlink($path)))) {
                pcv_log_event('state.unavailable', 'error', 'unavailable', 'presence_unavailable', ['operation' => 'presence_invalidate']);
                return ['status' => 'unavailable', 'reason' => 'presence_unavailable'];
            }
        }
        return ['status' => 'missing', 'reason' => 'presence_missing'];
    } catch (Throwable $error) {
        pcv_log_exception('state.unavailable', 'error', 'unavailable', 'presence_unavailable', $error, ['operation' => 'presence_invalidate']);
        return ['status' => 'unavailable', 'reason' => 'presence_unavailable'];
    } finally {
        pcv_unlock_state($handle);
    }
}

/** Cache bounded presence metadata and receipt time under the current playthrough key. */
function pcv_store_presence_snapshot(?string $key, array $parsed, ?string $stateDirectory = null): array
{
    if (!is_string($key) || !pcv_valid_key($key) || !in_array($parsed['status'], ['ready', 'empty'], true)) {
        $cleared = pcv_invalidate_ordinary_presence_snapshot($stateDirectory);
        if (($cleared['status'] ?? null) === 'unavailable') {
            return pcv_presence_observed_result('ordinary_capture', pcv_presence_result('unavailable', reason: 'presence_unavailable'));
        }
        if (!is_string($key) || !pcv_valid_key($key)) {
            return pcv_presence_observed_result('ordinary_capture', pcv_presence_result('unavailable', reason: 'identity_unavailable'));
        }
        return pcv_presence_observed_result('ordinary_capture', $parsed);
    }

    $handle = null;
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, true, LOCK_EX);
        $observedAt = time();
        $path = $directory . DIRECTORY_SEPARATOR . 'presence.json';
        if (is_link($path)) {
            throw new RuntimeException('Presence report is not safe.');
        }
        $document = [
            'version' => 1,
            'key' => $key,
            'observed_at' => $observedAt,
            'radius' => $parsed['radius'],
            'actors' => $parsed['actors'],
        ];
        $contents = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $temporary = tempnam($directory, '.presence-');
        if ($temporary === false) {
            throw new RuntimeException('Could not stage presence report.');
        }
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
                throw new RuntimeException('Could not write presence report.');
            }
            @chmod($temporary, 0660);
            if (!@rename($temporary, $path)) {
                throw new RuntimeException('Could not commit presence report.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
        pcv_log_set_playthrough_ref($key);
        return pcv_presence_observed_result('ordinary_capture', pcv_presence_result($parsed['status'], $parsed['actors'], $parsed['radius'], $observedAt));
    } catch (Throwable $error) {
        pcv_log_exception('state.unavailable', 'error', 'unavailable', 'presence_unavailable', $error, ['operation' => 'presence_capture']);
        return pcv_presence_observed_result('ordinary_capture', pcv_presence_result('unavailable', reason: 'presence_unavailable'));
    } finally {
        pcv_unlock_state($handle);
    }
}

/** Cache a validated ordinary player-routing snapshot. */
function pcv_capture_presence_snapshot(
    ?string $key,
    $raw,
    ?string $stateDirectory = null,
    ?int $requestTimestamp = null
): array
{
    $parsed = pcv_parse_presence_snapshot($raw);
    if (!in_array($parsed['status'], ['ready', 'empty'], true)) {
        $cleared = pcv_invalidate_ordinary_presence_snapshot($stateDirectory);
        if (($cleared['status'] ?? null) === 'unavailable') {
            return pcv_presence_observed_result('ordinary_capture', pcv_presence_result('unavailable', reason: 'presence_unavailable'));
        }
        if (!is_string($key) || !pcv_valid_key($key)) {
            return pcv_presence_observed_result('ordinary_capture', pcv_presence_result('unavailable', reason: 'identity_unavailable'));
        }
        return pcv_presence_observed_result('ordinary_capture', $parsed);
    }
    return pcv_store_presence_snapshot($key, $parsed, $stateDirectory);
}

/** Merge names seen now into the bounded recent map, dropping entries older than the grace window. */
function pcv_presence_recent_merge(array $previous, array $actors, int $now): array
{
    $recent = [];
    foreach ($previous as $nameKey => $entry) {
        if (is_string($nameKey) && is_array($entry) && is_string($entry['name'] ?? null) && is_int($entry['seen_at'] ?? null)
            && $entry['seen_at'] <= $now && $now - $entry['seen_at'] <= PCV_PRESENCE_ACTIVE_GRACE) {
            $recent[$nameKey] = ['name' => $entry['name'], 'seen_at' => $entry['seen_at']];
        }
    }
    foreach ($actors as $actor) {
        $recent[pcv_scope_name_key($actor['name'])] = ['name' => $actor['name'], 'seen_at' => $now];
    }
    if (count($recent) > PCV_PRESENCE_MAX_ACTORS) {
        uasort($recent, static fn(array $a, array $b): int => $b['seen_at'] <=> $a['seen_at']);
        $recent = array_slice($recent, 0, PCV_PRESENCE_MAX_ACTORS, true);
    }
    return $recent;
}

/** Capture a native close-range heartbeat without making a game or database call. */
function pcv_capture_background_presence_report(
    ?string $key,
    $raw,
    ?string $playerName,
    $requestTimestamp,
    ?string $stateDirectory = null
): array {
    $parsed = pcv_parse_background_presence_report($raw, $playerName);
    $timestamp = pcv_parse_presence_request_timestamp($requestTimestamp);
    $reason = $parsed['reason'] ?? null;
    if (!in_array($parsed['status'], ['ready', 'empty'], true)) {
        $reason = is_string($reason) ? $reason : 'presence_invalid';
    } elseif (!is_string($key) || !pcv_valid_key($key) || $timestamp === null) {
        $reason = 'identity_unavailable';
    }

    if ($reason !== null) {
        try {
            $directory = pcv_state_directory($stateDirectory);
            $handle = pcv_lock_state($directory, true, LOCK_EX);
            try {
                pcv_write_background_presence($directory, [
                    'version' => PCV_BACKGROUND_PRESENCE_VERSION,
                    'source' => 'infonpc_close_v1',
                    'key' => is_string($key) && pcv_valid_key($key) ? $key : null,
                    'player_name' => is_string($playerName) ? trim($playerName) : null,
                    'state' => 'unavailable',
                    'reason' => $reason,
                    'observed_at' => time(),
                    'heartbeat_timestamp' => null,
                    'baseline_timestamp' => null,
                    'actors' => [],
                ]);
            } finally {
                pcv_unlock_state($handle);
            }
        } catch (Throwable $error) {
            pcv_log_exception('state.unavailable', 'error', 'unavailable', 'presence_unavailable', $error, ['operation' => 'presence_capture']);
            return pcv_presence_observed_result('background_capture', pcv_presence_result('unavailable', reason: 'presence_unavailable'));
        }
        pcv_log_event('state.unavailable', 'error', 'unavailable', $reason, ['operation' => 'presence_capture']);
        return pcv_presence_observed_result('background_capture', pcv_presence_result('unavailable', reason: $reason));
    }

    $handle = null;
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, true, LOCK_EX);
        $now = time();
        $path = $directory . DIRECTORY_SEPARATOR . 'background_presence.json';
        if (is_link($path)) {
            throw new RuntimeException('Background presence report is not safe.');
        }

        $previous = null;
        if (is_file($path)) {
            $size = @filesize($path);
            if (is_int($size) && $size <= PCV_PRESENCE_DOCUMENT_MAX_BYTES) {
                $contents = @file_get_contents($path);
                $document = is_string($contents) ? json_decode($contents, true, 16) : null;
                if (is_array($document)
                    && ($document['version'] ?? null) === PCV_BACKGROUND_PRESENCE_VERSION
                    && ($document['source'] ?? null) === 'infonpc_close_v1'
                    && is_string($document['key'] ?? null) && pcv_valid_key($document['key'])
                    && is_string($document['player_name'] ?? null)
                    && is_int($document['observed_at'] ?? null)
                    && in_array($document['state'] ?? null, ['baseline', 'ready', 'empty'], true)
                    && is_int($document['heartbeat_timestamp'] ?? null)
                    && is_int($document['baseline_timestamp'] ?? null)
                    && $document['baseline_timestamp'] <= $document['heartbeat_timestamp']
                    && is_array($document['actors'] ?? null) && array_is_list($document['actors'])
                    && count($document['actors']) <= PCV_PRESENCE_MAX_ACTORS) {
                    $previous = $document;
                }
            }
        }

        $baseline = $timestamp;
        $state = $parsed['status'] === 'empty' ? 'empty' : 'baseline';
        $sameIdentity = is_array($previous)
            && hash_equals($key, $previous['key'])
            && pcv_scope_name_key($playerName) === pcv_scope_name_key($previous['player_name']);
        if ($sameIdentity) {
            $previousTimestamp = $previous['heartbeat_timestamp'];
            $previousReceipt = $previous['observed_at'];
            $leaseExpired = $now >= $previousReceipt && ($now - $previousReceipt) > PCV_PRESENCE_TTL;
            $serverClockRewound = $previousReceipt > $now;
            if ($timestamp === $previousTimestamp) {
                pcv_log_event('state.presence_rejected', 'warning', 'rejected', 'presence_stale', [
                    'operation' => 'presence_capture',
                ]);
                return pcv_presence_observed_result('background_capture', pcv_presence_result('stale', observedAt: $previousReceipt, reason: 'presence_stale'));
            }
            if ($timestamp < $previousTimestamp && !$leaseExpired && !$serverClockRewound) {
                pcv_log_event('state.presence_rejected', 'warning', 'rejected', 'presence_stale', [
                    'operation' => 'presence_capture',
                ]);
                return pcv_presence_observed_result('background_capture', pcv_presence_result('stale', observedAt: $previousReceipt, reason: 'presence_stale'));
            }
            if ($timestamp > $previousTimestamp && !$leaseExpired && !$serverClockRewound) {
                $baseline = $previous['baseline_timestamp'];
                $state = $parsed['status'] === 'empty' ? 'empty' : 'ready';
            }
        }

        $document = [
            'version' => PCV_BACKGROUND_PRESENCE_VERSION,
            'source' => 'infonpc_close_v1',
            'key' => $key,
            'player_name' => trim($playerName),
            'state' => $state,
            'reason' => null,
            'observed_at' => $now,
            'heartbeat_timestamp' => $timestamp,
            'baseline_timestamp' => $baseline,
            'actors' => $parsed['actors'],
            'recent' => pcv_presence_recent_merge(
                $sameIdentity && is_array($previous['recent'] ?? null) ? $previous['recent'] : [], $parsed['actors'], $now),
        ];
        pcv_write_background_presence($directory, $document);
        pcv_log_set_playthrough_ref($key);
        pcv_log_event('state.presence_refreshed', 'debug', 'accepted', null, [
            'actor_count' => count($parsed['actors']),
        ]);
        // Heartbeats repeat every ~10 s: log the roster when its state or size changes, not every time.
        $rosterChanged = !$sameIdentity || ($previous['state'] ?? null) !== $state
            || count($previous['actors'] ?? []) !== count($parsed['actors']);
        return pcv_presence_observed_result('background_capture',
            pcv_presence_result($state, $parsed['actors'], observedAt: $now), $rosterChanged);
    } catch (Throwable $error) {
        if (is_resource($handle)) {
            try {
                pcv_remove_background_presence_locked($directory);
            } catch (Throwable) {
                // If storage also prevents invalidation, the prior report expires on its original receipt time.
            }
        }
        pcv_log_exception('state.unavailable', 'error', 'unavailable', 'presence_unavailable', $error, ['operation' => 'presence_capture']);
        return pcv_presence_observed_result('background_capture', pcv_presence_result('unavailable', reason: 'presence_unavailable'));
    } finally {
        pcv_unlock_state($handle);
    }
}

/** Parse CHIM's wider `infonpc` report: "(beings in range:Name,Name,...,)". */
function pcv_parse_wide_presence_report($raw, ?string $currentPlayerName): array
{
    if (!is_string($raw) || strlen($raw) > 32768 || preg_match('//u', $raw) !== 1
        || !is_string($currentPlayerName) || trim($currentPlayerName) === ''
        || preg_match('/\A\(?\s*beings in range:(.*?)\)?\s*\z/su', trim($raw), $match) !== 1) {
        return pcv_presence_result('unavailable', reason: 'presence_invalid');
    }
    if (!function_exists('pcv_scope_name_key')) {
        require_once __DIR__ . '/scope.php';
    }
    $playerKey = pcv_scope_name_key($currentPlayerName);
    $actors = [];
    foreach (explode(',', $match[1]) as $token) {
        $name = trim($token);
        if ($name === '' || preg_match('/\((?:dead|disabled|unconscious)\)\s*\z/iu', $name) === 1) {
            continue;
        }
        $name = function_exists('chimDataStripActorStateSuffix')
            ? trim((string)chimDataStripActorStateSuffix($name))
            : trim((string)preg_replace('/\s*\([^()]*\)\s*\z/u', '', $name));
        if ($name === '' || strlen($name) > 256 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            return pcv_presence_result('unavailable', reason: 'presence_invalid');
        }
        if (pcv_scope_name_key($name) === $playerKey) {
            continue;
        }
        $actors[] = ['name' => $name];
        if (count($actors) > PCV_PRESENCE_MAX_ACTORS) {
            return pcv_presence_result('unavailable', reason: 'presence_invalid');
        }
    }
    return pcv_presence_result($actors === [] ? 'empty' : 'ready', $actors);
}

/** Store the latest wide report under the state lock; an invalid report removes the previous one. */
function pcv_capture_wide_presence_report(?string $key, $raw, ?string $playerName, ?string $stateDirectory = null): array
{
    $parsed = pcv_parse_wide_presence_report($raw, $playerName);
    $handle = null;
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, true, LOCK_EX);
        $path = $directory . DIRECTORY_SEPARATOR . 'background_wide_presence.json';
        if (is_link($path)) {
            throw new RuntimeException('Wide presence report is not safe.');
        }
        if (!is_string($key) || !pcv_valid_key($key) || !in_array($parsed['status'], ['ready', 'empty'], true)) {
            if (file_exists($path) && (!is_file($path) || !@unlink($path))) {
                throw new RuntimeException('Could not invalidate wide presence report.');
            }
            return ['status' => 'unavailable'];
        }
        $document = [
            'version' => PCV_WIDE_PRESENCE_VERSION,
            'source' => 'infonpc_v1',
            'key' => $key,
            'player_name' => trim((string)$playerName),
            'observed_at' => time(),
            'actors' => $parsed['actors'],
        ];
        $contents = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $temporary = tempnam($directory, '.wide-presence-');
        if ($temporary === false) {
            throw new RuntimeException('Could not stage wide presence report.');
        }
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
                throw new RuntimeException('Could not write wide presence report.');
            }
            @chmod($temporary, 0660);
            if (!@rename($temporary, $path)) {
                throw new RuntimeException('Could not commit wide presence report.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
        return ['status' => $parsed['status']];
    } catch (Throwable $error) {
        pcv_log_exception('state.unavailable', 'error', 'unavailable', 'presence_unavailable', $error, ['operation' => 'presence_capture']);
        return ['status' => 'unavailable'];
    } finally {
        pcv_unlock_state($handle);
    }
}

/** Read the wide report as name-key counts. Used only inside an active scene, never for activation. */
function pcv_read_wide_presence_names(?string $key, ?string $playerName, ?string $stateDirectory = null, ?int $now = null): array
{
    $unavailable = ['status' => 'unavailable', 'counts' => []];
    if (!is_string($key) || !pcv_valid_key($key) || !is_string($playerName) || trim($playerName) === '') {
        return $unavailable;
    }
    if (!function_exists('pcv_scope_name_key')) {
        require_once __DIR__ . '/scope.php';
    }
    $handle = null;
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, false, LOCK_SH);
        $path = $directory . DIRECTORY_SEPARATOR . 'background_wide_presence.json';
        if ($handle === null || !is_file($path) || is_link($path)) {
            return $unavailable;
        }
        $size = @filesize($path);
        if (!is_int($size) || $size > PCV_PRESENCE_DOCUMENT_MAX_BYTES) {
            return $unavailable;
        }
        $document = json_decode((string)@file_get_contents($path), true, 16);
        if (!is_array($document)) {
            pcv_log_event('state.unavailable', 'error', 'unavailable', 'presence_unavailable', ['operation' => 'presence_read']);
            return ['status' => 'error', 'counts' => []];
        }
        if (($document['version'] ?? null) !== PCV_WIDE_PRESENCE_VERSION
            || ($document['source'] ?? null) !== 'infonpc_v1'
            || !is_string($document['key'] ?? null) || !hash_equals($key, $document['key'])
            || !is_string($document['player_name'] ?? null)
            || pcv_scope_name_key($document['player_name']) !== pcv_scope_name_key($playerName)
            || !is_int($document['observed_at'] ?? null)
            || !is_array($document['actors'] ?? null) || !array_is_list($document['actors'])
            || count($document['actors']) > PCV_PRESENCE_MAX_ACTORS) {
            return $unavailable;
        }
        $now ??= time();
        if ($document['observed_at'] > $now || $now - $document['observed_at'] > PCV_PRESENCE_TTL) {
            return ['status' => 'stale', 'counts' => []];
        }
        $counts = [];
        foreach ($document['actors'] as $actor) {
            if (!is_array($actor) || !is_string($actor['name'] ?? null)) {
                return $unavailable;
            }
            $nameKey = pcv_scope_name_key($actor['name']);
            $counts[$nameKey] = ($counts[$nameKey] ?? 0) + 1;
        }
        return ['status' => 'ready', 'counts' => $counts];
    } catch (Throwable $error) {
        pcv_log_exception('state.unavailable', 'error', 'unavailable', 'presence_unavailable', $error, ['operation' => 'presence_read']);
        return ['status' => 'error', 'counts' => []];
    } finally {
        pcv_unlock_state($handle);
    }
}

/**
 * In-scene presence for an already active scene. A participant counts when named exactly once in a
 * fresh close report (including a baseline report after a gap), when seen in a close report within
 * the grace window, or when named exactly once in a fresh wide report. Activation never uses this.
 * Returns ['known_npcs' => [id => name], 'missing' => [id => close|grace_expired|wide_absent|wide_unavailable]].
 */
function pcv_read_active_scene_npcs(?string $key, array $catalogRows, ?string $playerName, array $actorIds,
    ?string $stateDirectory = null, ?int $now = null): array
{
    $now ??= time();
    // 'error' (0.1.13): the evidence could not be read, which is not the same as an absent member.
    $result = ['known_npcs' => [], 'missing' => [], 'error' => false, 'report_fresh' => false];
    if (!function_exists('pcv_scope_name_key')) {
        require_once __DIR__ . '/scope.php';
    }
    $names = [];
    foreach (pcvScopeKnownNpcs($catalogRows, $playerName) as $id => $name) {
        $names[(string)$id] = $name;
    }
    $document = null;
    if (is_string($key) && pcv_valid_key($key) && is_string($playerName) && trim($playerName) !== '') {
        $handle = null;
        try {
            $directory = pcv_state_directory($stateDirectory);
            $handle = pcv_lock_state($directory, false, LOCK_SH);
            $path = $directory . DIRECTORY_SEPARATOR . 'background_presence.json';
            $size = is_file($path) && !is_link($path) ? @filesize($path) : false;
            if ($handle !== null && (file_exists($path) || is_link($path))
                && (!is_int($size) || $size > PCV_PRESENCE_DOCUMENT_MAX_BYTES)) {
                $result['error'] = true;
                pcv_log_event('state.unavailable', 'error', 'unavailable', 'presence_unavailable', ['operation' => 'presence_read']);
            }
            if ($handle !== null && is_int($size) && $size <= PCV_PRESENCE_DOCUMENT_MAX_BYTES) {
                $decoded = json_decode((string)@file_get_contents($path), true, 16);
                if (!is_array($decoded)) {
                    $result['error'] = true;
                    pcv_log_event('state.unavailable', 'error', 'unavailable', 'presence_unavailable', ['operation' => 'presence_read']);
                }
                if (is_array($decoded) && ($decoded['version'] ?? null) === PCV_BACKGROUND_PRESENCE_VERSION
                    && is_string($decoded['key'] ?? null) && hash_equals($key, $decoded['key'])
                    && is_string($decoded['player_name'] ?? null)
                    && pcv_scope_name_key($decoded['player_name']) === pcv_scope_name_key($playerName)) {
                    $document = $decoded;
                }
            }
        } catch (Throwable $error) {
            $document = null;
            $result['error'] = true;
            pcv_log_exception('state.unavailable', 'error', 'unavailable', 'presence_unavailable', $error, ['operation' => 'presence_read']);
        } finally {
            pcv_unlock_state($handle);
        }
    }
    $current = [];
    if (is_array($document) && is_int($document['observed_at'] ?? null)
        && $document['observed_at'] <= $now && $now - $document['observed_at'] <= PCV_PRESENCE_TTL
        && in_array($document['state'] ?? null, ['baseline', 'ready'], true) && is_array($document['actors'] ?? null)) {
        $result['report_fresh'] = true;
        foreach ($document['actors'] as $actor) {
            if (is_array($actor) && is_string($actor['name'] ?? null)) {
                $nameKey = pcv_scope_name_key($actor['name']);
                $current[$nameKey] = ($current[$nameKey] ?? 0) + 1;
            }
        }
    }
    $recent = is_array($document) && is_array($document['recent'] ?? null) ? $document['recent'] : [];
    $wide = null;
    foreach ($actorIds as $id) {
        $id = (string)$id;
        $name = $names[$id] ?? null;
        if (!is_string($name)) {
            $result['missing'][$id] = 'close';
            continue;
        }
        $nameKey = pcv_scope_name_key($name);
        $currentCount = $current[$nameKey] ?? 0;
        if ($currentCount === 1) {
            $result['known_npcs'][$id] = $name;
            continue;
        }
        $seenAt = $recent[$nameKey]['seen_at'] ?? null;
        if ($currentCount === 0 && is_int($seenAt) && $seenAt <= $now && $now - $seenAt <= PCV_PRESENCE_ACTIVE_GRACE) {
            $result['known_npcs'][$id] = $name;
            continue;
        }
        $wide ??= pcv_read_wide_presence_names($key, $playerName, $stateDirectory, $now);
        if (($wide['status'] ?? null) === 'error') {
            $result['error'] = true;
        }
        if (($wide['status'] ?? null) !== 'ready') {
            $result['missing'][$id] = 'wide_unavailable';
        } elseif (($wide['counts'][$nameKey] ?? 0) === 1) {
            $result['known_npcs'][$id] = $name;
        } else {
            $result['missing'][$id] = 'wide_absent';
        }
    }
    return $result;
}

/** Re-resolve snapshot names against the current catalog on every caller read. */
function pcv_read_eligible_npcs(?string $key, array $catalogRows, ?string $playerName, ?string $stateDirectory = null): array
{
    // Page polls and ACK checks read often; only non-routine read outcomes are logged.
    return pcv_presence_observed_result('background_read',
        pcv_read_eligible_npcs_unobserved($key, $catalogRows, $playerName, $stateDirectory), false);
}

function pcv_read_eligible_npcs_unobserved(?string $key, array $catalogRows, ?string $playerName, ?string $stateDirectory = null): array
{
    $empty = ['status' => 'missing', 'known_npcs' => [], 'observed_at' => null, 'reason' => 'presence_missing'];
    if (!is_string($key) || !pcv_valid_key($key) || !is_string($playerName) || trim($playerName) === '') {
        return pcv_presence_read_failure($empty, 'identity_unavailable');
    }

    $handle = null;
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, false, LOCK_SH);
        if ($handle === null) {
            return array_replace($empty, [
                '_pcv_observation' => ['status' => 'unavailable', 'reason' => 'presence_unavailable'],
            ]);
        }
        $path = $directory . DIRECTORY_SEPARATOR . 'background_presence.json';
        if (is_link($path)) {
            return pcv_presence_read_failure($empty, 'presence_unavailable');
        }
        if (!file_exists($path)) {
            return $empty;
        }
        if (!is_file($path)) {
            return pcv_presence_read_failure($empty, 'presence_unavailable');
        }
        $size = @filesize($path);
        if (!is_int($size) || $size > PCV_PRESENCE_DOCUMENT_MAX_BYTES) {
            return pcv_presence_read_failure($empty, 'presence_unavailable');
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return pcv_presence_read_failure($empty, 'presence_unavailable');
        }
        $document = json_decode($contents, true, 16);
        if (!is_array($document) || ($document['version'] ?? null) !== PCV_BACKGROUND_PRESENCE_VERSION
            || ($document['source'] ?? null) !== 'infonpc_close_v1'
            || !is_string($document['key'] ?? null) || !pcv_valid_key($document['key'])
            || !is_int($document['observed_at'] ?? null)
            || !is_string($document['player_name'] ?? null)
            || !in_array($document['state'] ?? null, ['baseline', 'ready', 'empty', 'unavailable'], true)
            || !is_array($document['actors'] ?? null) || !array_is_list($document['actors']) || count($document['actors']) > PCV_PRESENCE_MAX_ACTORS) {
            return pcv_presence_read_failure($empty, 'presence_unavailable');
        }
        if (!hash_equals($key, $document['key'])) {
            return pcv_presence_read_failure($empty, 'presence_key_mismatch');
        }
        if (!function_exists('pcv_scope_name_key')) {
            require_once __DIR__ . '/scope.php';
        }
        if (pcv_scope_name_key($playerName) !== pcv_scope_name_key($document['player_name'])) {
            return pcv_presence_read_failure($empty, 'identity_unavailable');
        }
        $now = time();
        if ($document['observed_at'] > $now || $document['observed_at'] < $now - PCV_PRESENCE_TTL) {
            return array_replace($empty, [
                'status' => $document['observed_at'] > $now ? 'unavailable' : 'stale',
                'observed_at' => $document['observed_at'],
                'reason' => $document['observed_at'] > $now ? 'presence_unavailable' : 'presence_stale',
            ]);
        }
        if ($document['state'] === 'unavailable') {
            $reason = is_string($document['reason'] ?? null) ? $document['reason'] : 'presence_unavailable';
            return pcv_presence_read_failure($empty, $reason);
        }
        if (!is_int($document['heartbeat_timestamp'] ?? null)
            || !is_int($document['baseline_timestamp'] ?? null)
            || $document['baseline_timestamp'] > $document['heartbeat_timestamp']) {
            return pcv_presence_read_failure($empty, 'presence_unavailable');
        }
        foreach ($document['actors'] as $actor) {
            if (!is_array($actor) || !is_string($actor['name'] ?? null)
                || trim($actor['name']) === '' || strlen($actor['name']) > 256 || preg_match('//u', $actor['name']) !== 1
                || preg_match('/[\x00-\x1f\x7f]/', $actor['name']) === 1) {
                return pcv_presence_read_failure($empty, 'presence_unavailable');
            }
        }

        if ($document['state'] === 'empty') {
            return [
                'status' => 'empty',
                'known_npcs' => [],
                'observed_at' => $document['observed_at'],
                'reason' => null,
            ];
        }
        if ($document['state'] === 'baseline') {
            return array_replace($empty, [
                'status' => 'stale',
                'observed_at' => $document['observed_at'],
                'reason' => 'presence_stale',
                '_pcv_observation' => ['status' => 'unavailable', 'reason' => 'presence_baseline'],
            ]);
        }

        $reportedCounts = [];
        foreach ($document['actors'] as $actor) {
            $nameKey = pcv_scope_name_key($actor['name']);
            $reportedCounts[$nameKey] = ($reportedCounts[$nameKey] ?? 0) + 1;
        }
        $freshNames = [];
        $heartbeat = $document['heartbeat_timestamp'];
        $baseline = $document['baseline_timestamp'];
        foreach (pcvScopeKnownNpcs($catalogRows, $playerName) as $id => $name) {
            $nameKey = pcv_scope_name_key($name);
            if (($reportedCounts[$nameKey] ?? 0) !== 1) {
                continue;
            }
            foreach ($catalogRows as $row) {
                if ((string)($row['id'] ?? '') !== (string)$id
                    || pcv_scope_name_key((string)($row['npc_name'] ?? '')) !== $nameKey) {
                    continue;
                }
                $statusTimestamp = pcv_background_activity_timestamp($row);
                if ($statusTimestamp === null || $statusTimestamp <= $baseline) {
                    continue;
                }
                $combinedAgeSeconds = (($heartbeat - $statusTimestamp) / 1_000_000_000)
                    + ($now - $document['observed_at']);
                if ($combinedAgeSeconds >= -PCV_PRESENCE_CLOCK_TOLERANCE && $combinedAgeSeconds <= PCV_PRESENCE_TTL) {
                    $freshNames[$nameKey] = $name;
                }
                break;
            }
        }
        if ($freshNames === []) {
            return array_replace($empty, [
                'status' => 'stale',
                'observed_at' => $document['observed_at'],
                'reason' => 'presence_stale',
            ]);
        }
        $freshActors = [];
        foreach ($document['actors'] as $actor) {
            if (isset($freshNames[pcv_scope_name_key($actor['name'])])) {
                $freshActors[] = $actor;
            }
        }
        $known = pcvScopeEligibleMapFromPresence($freshActors, $catalogRows, $playerName);
        if ($known === []) {
            return array_replace($empty, [
                'status' => 'stale',
                'observed_at' => $document['observed_at'],
                'reason' => 'presence_stale',
            ]);
        }
        return [
            'status' => 'ready',
            'known_npcs' => $known,
            'observed_at' => $document['observed_at'],
            'reason' => null,
        ];
    } catch (Throwable $error) {
        pcv_log_exception('state.unavailable', 'error', 'unavailable', 'presence_unavailable', $error, ['operation' => 'presence_read']);
        return array_replace($empty, ['status' => 'unavailable', 'reason' => 'presence_unavailable']);
    } finally {
        pcv_unlock_state($handle);
    }
}

function pcv_result(
    string $status,
    ?array $scope,
    bool $pending,
    ?array $pendingScope = null,
    ?string $configId = null,
    ?string $pendingConfigId = null,
    ?string $reason = null
): array
{
    return [
        'status' => $status,
        'scope' => $scope,
        'pending' => $pending,
        'pending_scope' => $pendingScope,
        'config_id' => $configId,
        'pending_config_id' => $pendingConfigId,
        'reason' => $reason,
    ];
}

/** Run an extension-owned commit gate while the caller still holds state.lock. */
function pcv_companion_run_locked_commit(?callable $callback, array $before, array $after, array $metadata, callable $commit): array
{
    if ($callback === null) {
        $commit();
        return ['status' => 'committed', 'outcome' => 'owner_commit'];
    }

    $commitCount = 0;
    $lockedCommit = static function () use ($commit, &$commitCount): void {
        if ($commitCount !== 0) {
            throw new LogicException('The companion owner commit can run only once.');
        }
        $commitCount = 1;
        $commit();
    };
    try {
        $decision = $callback($before, $after, $metadata, $lockedCommit);
    } catch (Throwable) {
        return ['status' => 'unknown', 'outcome' => 'uncertain'];
    }
    if (is_array($decision) && ($decision['status'] ?? null) === 'committed' && $commitCount === 1) {
        return ['status' => 'committed', 'outcome' => 'owner_commit'];
    }
    if (is_array($decision) && ($decision['status'] ?? null) === 'refused' && $commitCount === 0
        && ($decision['outcome'] ?? null) === 'known_no_write') {
        return [
            'status' => 'refused',
            'outcome' => 'known_no_write',
            'reason' => is_string($decision['reason'] ?? null) ? $decision['reason'] : 'companion_guard_refused',
        ];
    }
    return ['status' => 'unknown', 'outcome' => 'uncertain'];
}

function pcv_visible_state(array $state, int $now): array
{
    $active = $state['active'];
    if (is_array($active) && $active['expires_at'] > $now) {
        $scope = pcv_config_with_scene_mode($active['config']);
    } else {
        $scope = null;
    }

    $pendingScope = is_array($state['pending']) && $state['pending']['expires_at'] > $now
        ? pcv_config_with_scene_mode($state['pending']['config'])
        : null;
    $configId = $scope !== null && pcv_valid_config_id($active['config_id'] ?? null)
        ? ($active['config_id'] ?? null) : null;
    $storedPendingConfigId = $state['pending']['config_id'] ?? null;
    $pendingConfigId = $pendingScope !== null && pcv_valid_config_id($storedPendingConfigId)
        ? $storedPendingConfigId : null;
    $pending = $pendingScope !== null;
    // 0.1.13: an automatically ended scene is shown on the page for 30 minutes.
    $lastEnd = $scope === null && is_array($state['last_end'] ?? null) && $now - $state['last_end']['at'] <= 1800
        ? $state['last_end'] : null;
    if ($lastEnd !== null) {
        $result = $pending
            ? pcv_result('pending', null, true, $pendingScope, null, $pendingConfigId)
            : pcv_result('off', null, false);
        $result['last_end'] = $lastEnd;
        return $result;
    }
    if ($scope !== null) {
        $result = pcv_result('active', $scope, $pending, $pendingScope, $configId, $pendingConfigId);
        // Group members left out at the start or who left mid-scene (only present when someone was dropped).
        if (is_array($active['dropped'] ?? null) && $active['dropped'] !== []) {
            $result['dropped'] = $active['dropped'];
        }
        return $result;
    }
    return $pending
        ? pcv_result('pending', null, true, $pendingScope, null, $pendingConfigId)
        : pcv_result('off', null, false);
}

function pcv_config_with_scene_mode(array $config): array
{
    if (($config['enabled'] ?? false) === true) {
        $sceneMode = $config['scene_mode'] ?? 'pair';
        unset($config['scene_mode']);
        return ['enabled' => true, 'scene_mode' => $sceneMode] + $config;
    }
    return $config;
}

function pcv_state_log_context(array $config): array
{
    $enabled = ($config['enabled'] ?? false) === true;
    $context = [
        'action' => $enabled ? 'enable' : 'end',
        'exclude_player' => ($config['exclude_player'] ?? true) === true,
        'bystander_mode' => in_array($config['bystander_mode'] ?? null, ['exclude', 'silent'], true)
            ? $config['bystander_mode'] : 'exclude',
    ];
    if ($enabled) {
        $context['scene_mode'] = $config['scene_mode'] ?? 'pair';
        $context['actor_a_id'] = $config['actor_a'] ?? null;
        $context['actor_b_id'] = $config['actor_b'] ?? null;
        if (($config['free'] ?? false) === true) {
            $context['free_scene'] = true;
        }
    }
    return $context;
}

function pcv_read(string $key, ?string $stateDirectory = null): array
{
    if (!pcv_valid_key($key)) {
        pcv_log_set_playthrough_ref(null);
        pcv_log_event('state.unavailable', 'error', 'unavailable', 'invalid_state_key', ['operation' => 'read']);
        return pcv_result('unavailable', null, false);
    }
    pcv_log_set_playthrough_ref($key);

    $handle = null;
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, false, LOCK_SH);
        if ($handle === null) {
            return pcv_result('off', null, false);
        }
        $loaded = pcv_load_store($directory);
        if ($loaded['kind'] === 'missing') {
            return pcv_result('off', null, false);
        }
        if ($loaded['kind'] !== 'ready') {
            pcv_log_event('state.unavailable', 'error', 'unavailable', $loaded['reason'] ?? 'state_read_failed', ['operation' => 'read']);
            return pcv_result('unavailable', null, false);
        }
        $state = $loaded['state'];
        if (!hash_equals($state['key'], $key)) {
            pcv_log_set_config_id(null);
            return pcv_result('off', null, false);
        }
        $result = pcv_visible_state($state, time());
        pcv_log_set_config_id($result['config_id']);
        return $result;
    } catch (Throwable $error) {
        pcv_log_exception('state.unavailable', 'error', 'unavailable', 'state_read_failed', $error, ['operation' => 'read']);
        return pcv_result('unavailable', null, false);
    } finally {
        pcv_unlock_state($handle);
    }
}

function pcv_normalize_config(array $desired, array $knownNpcs): array
{
    $config = pcv_normalize_config_core($desired, $knownNpcs);
    if ($config['enabled'] !== true) {
        return $config;
    }
    // 0.1.14 roleplay settings. A blank card and a normal pace are simply absent.
    $card = $desired['card'] ?? null;
    if (is_string($card) && trim($card) !== '') {
        $card = trim($card);
        if (!pcv_valid_scene_card($card)) {
            throw new InvalidArgumentException('The scene card must be one line of at most 300 characters.');
        }
        $config['card'] = $card;
    } elseif ($card !== null && !is_string($card)) {
        throw new InvalidArgumentException('The scene card is invalid.');
    }
    $pace = $desired['pace'] ?? 'normal';
    if (!in_array($pace, ['short', 'normal', 'long'], true)) {
        throw new InvalidArgumentException('Choose a valid turn length.');
    }
    if ($pace !== 'normal' && ($config['scene_mode'] ?? 'pair') === 'pair') {
        $config['pace'] = $pace;
    }
    if (array_key_exists('actions', $desired)) {
        $wanted = $desired['actions'];
        if (!is_array($wanted)) {
            throw new InvalidArgumentException('Choose valid scene actions.');
        }
        foreach ($wanted as $group) {
            if (!is_string($group) || !in_array($group, PCV_SCENE_ACTION_GROUPS, true)) {
                throw new InvalidArgumentException('Choose valid scene actions.');
            }
        }
        $groups = array_values(array_intersect(PCV_SCENE_ACTION_GROUPS, $wanted));
        if (($config['scene_mode'] ?? 'pair') === 'solo') {
            $groups = array_values(array_diff($groups, ['physical']));
        }
        if ($groups !== []) {
            $config['actions'] = $groups;
        }
    }
    if (($config['free'] ?? false) === true && array_key_exists('free_cap', $desired)) {
        $cap = filter_var($desired['free_cap'], FILTER_VALIDATE_INT);
        if ($cap === false || $cap < 2 || $cap > PCV_FREE_MAX_MEMBERS) {
            throw new InvalidArgumentException('Choose two to six NPCs for a free scene.');
        }
        $config['free_cap'] = $cap;
    }
    if (!pcv_valid_config($config, false)) {
        throw new InvalidArgumentException('The private conversation settings are incomplete.');
    }
    return $config;
}

function pcv_normalize_config_core(array $desired, array $knownNpcs): array
{
    if (!is_bool($desired['enabled'] ?? null)) {
        throw new InvalidArgumentException('Choose whether the private scope is enabled.');
    }
    if (!$desired['enabled']) {
        return [
            'enabled' => false,
            'actor_a' => '',
            'actor_b' => '',
            'exclude_player' => true,
            'bystander_mode' => 'exclude',
        ];
    }

    $sceneMode = array_key_exists('scene_mode', $desired) ? $desired['scene_mode'] : 'pair';
    if ($sceneMode === 'pair' && ($desired['free'] ?? false) === true) {
        if (!in_array($desired['bystander_mode'] ?? null, ['exclude', 'silent'], true)) {
            throw new InvalidArgumentException('The private conversation settings are incomplete.');
        }
        return ['enabled' => true, 'scene_mode' => 'pair', 'free' => true, 'exclude_player' => true,
            'bystander_mode' => $desired['bystander_mode']];
    }
    $groupIds = null;
    $groupOpener = null;
    if ($sceneMode === 'pair' && array_key_exists('actor_ids', $desired)) {
        $rawIds = $desired['actor_ids'];
        if (!is_array($rawIds) || !array_is_list($rawIds) || count($rawIds) < 2 || count($rawIds) > PCV_GROUP_MAX_MEMBERS) {
            throw new InvalidArgumentException('Choose two to four different NPCs.');
        }
        $groupIds = [];
        foreach ($rawIds as $rawId) {
            if (!is_string($rawId) && !is_int($rawId)) {
                throw new InvalidArgumentException('Choose two to four different NPCs.');
            }
            $groupIds[] = (string)$rawId;
        }
        if (count(array_unique($groupIds)) !== count($groupIds)) {
            throw new InvalidArgumentException('Choose two to four different NPCs.');
        }
        $groupOpener = $desired['opener'] ?? 'auto';
        $groupOpener = is_int($groupOpener) ? (string)$groupOpener : $groupOpener;
        if ($groupOpener !== 'auto' && (!is_string($groupOpener) || !in_array($groupOpener, $groupIds, true))) {
            throw new InvalidArgumentException('The opener must be one of the selected NPCs.');
        }
        $desired['actor_a'] = $groupIds[0];
        $desired['actor_b'] = $groupIds[1];
    }
    if (!in_array($sceneMode, ['pair', 'solo'], true)
        || !is_string($desired['actor_a'] ?? null)
        || !is_bool($desired['exclude_player'] ?? null)
        || !in_array($desired['bystander_mode'] ?? null, ['exclude', 'silent'], true)) {
        throw new InvalidArgumentException('The private conversation settings are incomplete.');
    }

    $actorA = $desired['actor_a'];
    $actorB = $sceneMode === 'solo' ? null : ($desired['actor_b'] ?? null);
    $excludePlayer = $sceneMode === 'solo' ? true : $desired['exclude_player'];
    $config = [
        'enabled' => true,
        'scene_mode' => $sceneMode,
        'actor_a' => $actorA,
        'actor_b' => $actorB,
        'exclude_player' => $excludePlayer,
        'bystander_mode' => $desired['bystander_mode'],
    ];
    if (is_array($groupIds)) {
        $config['actor_ids'] = $groupIds;
        $config['opener'] = $groupOpener;
    }
    if (!pcv_valid_config($config, false)) {
        throw new InvalidArgumentException($sceneMode === 'solo'
            ? 'Choose one valid NPC for solo reflection.'
            : 'Choose two to four different valid NPCs.');
    }
    $actorIds = pcv_config_actor_ids($config);
    foreach ($actorIds as $actorId) {
        if (!is_string($actorId) || !array_key_exists($actorId, $knownNpcs)
            || !is_string($knownNpcs[$actorId]) || trim($knownNpcs[$actorId]) === '') {
            throw new InvalidArgumentException('The selected NPC is no longer available. Reload the NPC list.');
        }
    }
    $fold = static fn(string $name): string => function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $selectedNames = [];
    foreach ($actorIds as $selectedId) {
        $selectedName = $fold(trim($knownNpcs[$selectedId]));
        if (isset($selectedNames[$selectedName])) {
            throw new InvalidArgumentException('The selected NPC names are ambiguous.');
        }
        $selectedNames[$selectedName] = true;
        foreach ($knownNpcs as $otherId => $otherName) {
            if ((string)$otherId !== $selectedId && is_string($otherName) && $fold(trim($otherName)) === $selectedName) {
                throw new InvalidArgumentException('The selected NPC name is ambiguous.');
            }
        }
    }
    return $config;
}

/**
 * Mark a solo request as in flight so early-line ACKs that arrive before registration can be told
 * apart from a genuinely missing registration. Classification aid only; nothing depends on it.
 */
function pcv_solo_inflight_mark(string $configId, string $actorId, ?string $stateDirectory = null, ?int $now = null): void
{
    $directory = pcv_state_directory($stateDirectory);
    $path = $directory . DIRECTORY_SEPARATOR . 'solo_inflight.json';
    if (is_link($path)) {
        return;
    }
    $contents = json_encode(['config_id' => $configId, 'actor_id' => $actorId, 'started_at' => $now ?? time()], JSON_THROW_ON_ERROR);
    $temporary = tempnam($directory, '.solo-inflight-');
    if ($temporary === false) {
        return;
    }
    if (file_put_contents($temporary, $contents) === strlen($contents)) {
        @chmod($temporary, 0660);
        @rename($temporary, $path);
    }
    if (is_file($temporary)) {
        @unlink($temporary);
    }
}

function pcv_solo_inflight_matches(string $configId, string $actorId, ?string $stateDirectory = null, ?int $now = null): bool
{
    try {
        $path = pcv_state_directory($stateDirectory) . DIRECTORY_SEPARATOR . 'solo_inflight.json';
        if (!is_file($path) || is_link($path) || (int)@filesize($path) > 512) {
            return false;
        }
        $marker = json_decode((string)@file_get_contents($path), true, 4);
        $now ??= time();
        return is_array($marker) && ($marker['config_id'] ?? null) === $configId && ($marker['actor_id'] ?? null) === $actorId
            && is_int($marker['started_at'] ?? null) && $marker['started_at'] <= $now
            && $now - $marker['started_at'] <= PCV_SOLO_INFLIGHT_TTL;
    } catch (Throwable) {
        return false;
    }
}

/**
 * 0.1.14 turn spreading: record that $speaker took a turn in scene $configId and return the name keys of members
 * who have spoken in the current round. When every member has spoken, a new round starts with this speaker.
 */
function pcv_scene_turns_record(string $configId, string $speaker, array $memberNames, ?string $stateDirectory = null): array
{
    if (!function_exists('pcv_scope_name_key')) {
        require_once __DIR__ . '/scope.php';
    }
    $memberKeys = array_values(array_unique(array_map(static fn($name) => pcv_scope_name_key((string)$name), $memberNames)));
    $speakerKey = pcv_scope_name_key($speaker);
    $handle = null;
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, true, LOCK_EX);
        $path = $directory . DIRECTORY_SEPARATOR . 'scene_turns.json';
        $spoken = [];
        if (is_file($path) && !is_link($path) && (int)@filesize($path) <= 4096) {
            $document = json_decode((string)@file_get_contents($path), true, 4);
            if (is_array($document) && ($document['config_id'] ?? null) === $configId && is_array($document['spoken'] ?? null)) {
                $spoken = array_values(array_filter($document['spoken'], static fn($key) => is_string($key) && in_array($key, $memberKeys, true)));
            }
        }
        if (!in_array($speakerKey, $spoken, true)) {
            $spoken[] = $speakerKey;
        }
        if (array_diff($memberKeys, $spoken) === []) {
            $spoken = [$speakerKey];
        }
        $contents = json_encode(['config_id' => $configId, 'spoken' => $spoken], JSON_THROW_ON_ERROR);
        $temporary = tempnam($directory, '.turns-');
        if ($temporary !== false) {
            if (file_put_contents($temporary, $contents) === strlen($contents)) {
                @chmod($temporary, 0660);
                @rename($temporary, $path);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
        return $spoken;
    } catch (Throwable $error) {
        // Turn spreading is a preference; a failure here never blocks the scene.
        pcv_log_exception('state.unavailable', 'error', 'unavailable', 'state_transition_failed', $error, ['operation' => 'begin']);
        return [$speakerKey];
    } finally {
        pcv_unlock_state($handle);
    }
}

function pcv_solo_inflight_clear(?string $stateDirectory = null): void
{
    $path = pcv_state_directory($stateDirectory) . DIRECTORY_SEPARATOR . 'solo_inflight.json';
    if (is_file($path) && !is_link($path)) {
        @unlink($path);
    }
}

/** The participant catalog IDs of a stored scene configuration (solo: A only; group: actor_ids; legacy pair: A, B). */
function pcv_config_actor_ids(array $config): array
{
    if (($config['scene_mode'] ?? 'pair') === 'solo') {
        return [$config['actor_a'] ?? null];
    }
    if (is_array($config['actor_ids'] ?? null)) {
        return array_values($config['actor_ids']);
    }
    if (($config['free'] ?? false) === true) {
        return []; // pending free scene: members are chosen at activation
    }
    return [$config['actor_a'] ?? null, $config['actor_b'] ?? null];
}

/** How many participants of $config are absent from $eligibleNpcMap (for refusal logs). */
function pcv_config_missing_actor_count(array $config, array $eligibleNpcMap): int
{
    $missing = 0;
    foreach (pcv_config_actor_ids($config) as $actorId) {
        if (!is_string($actorId) || !array_key_exists($actorId, $eligibleNpcMap)) {
            $missing++;
        }
    }
    return $missing;
}

function pcv_config_actors_are_eligible(array $config, ?array $eligibleNpcMap): bool
{
    if (!is_array($eligibleNpcMap)) {
        return false;
    }
    foreach (pcv_config_actor_ids($config) as $actorId) {
        if (!is_string($actorId) || !array_key_exists($actorId, $eligibleNpcMap)) {
            return false;
        }
    }
    return true;
}

/** Stage enabled settings for the next eligible input; disabled settings clear both slots immediately. */
function pcv_stage(string $key, array $desired, array $knownNpcs, ?string $stateDirectory = null, ?string $endReason = null,
    ?callable $companionPrecommit = null): array
{
    if (!pcv_valid_key($key)) {
        pcv_log_set_playthrough_ref(null);
        pcv_log_event('state.unavailable', 'error', 'unavailable', 'invalid_state_key', ['operation' => 'stage']);
        return pcv_result('unavailable', null, false);
    }
    pcv_log_set_playthrough_ref($key);
    $config = pcv_normalize_config($desired, $knownNpcs);

    $handle = null;
    $invalidated = null;
    $expired = [];
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, true, LOCK_EX);
        $loaded = pcv_load_store($directory);
        if ($loaded['kind'] === 'unavailable' && !$config['enabled']
            && in_array($loaded['reason'] ?? null, ['invalid_json', 'invalid_state', 'state_too_large'], true)) {
            // 0.1.13: END recovers an unreadable store; the bad copy is kept for diagnosis. ARM still refuses.
            $path = $directory . DIRECTORY_SEPARATOR . 'state.json';
            $kept = $path . '.corrupt-' . time() . '-' . bin2hex(random_bytes(4));
            if (@rename($path, $kept)) {
                pcv_log_event('state.store_recovered', 'warning', 'recovered', $loaded['reason'], ['operation' => 'stage']);
                $loaded = ['kind' => 'missing'];
            }
        }
        if ($loaded['kind'] === 'unavailable') {
            pcv_log_event('state.unavailable', 'error', 'unavailable', $loaded['reason'] ?? 'state_stage_failed', ['operation' => 'stage']);
            return pcv_result('unavailable', null, false);
        }

        $state = $loaded['kind'] === 'ready' ? $loaded['state'] : pcv_empty_store($key);
        if ($state['key'] !== $key) {
            $invalidated = [
                'key' => $state['key'],
                'active_config_id' => is_array($state['active']) && pcv_valid_config_id($state['active']['config_id'] ?? null)
                    ? ($state['active']['config_id'] ?? null) : null,
                'pending_config_id' => is_array($state['pending']) && pcv_valid_config_id($state['pending']['config_id'] ?? null)
                    ? ($state['pending']['config_id'] ?? null) : null,
            ];
            $state = pcv_empty_store($key);
        }

        $before = $state;
        $now = time();
        if (is_array($state['active']) && $state['active']['expires_at'] <= $now) {
            $expired[] = ['target' => 'active', 'config_id' => $state['active']['config_id'] ?? null];
            $state['active'] = null;
        }
        if (is_array($state['pending']) && $state['pending']['expires_at'] <= $now) {
            $expired[] = ['target' => 'pending', 'config_id' => $state['pending']['config_id'] ?? null];
            $state['pending'] = null;
        }
        $endConfigId = null;
        unset($state['last_end']);
        if ($config['enabled']) {
            $state['pending'] = [
                'config' => $config,
                'config_id' => pcv_log_new_uuid(),
                'staged_at' => $now,
                'expires_at' => $now + PCV_PENDING_TTL,
            ];
        } else {
            foreach (['active', 'pending'] as $slot) {
                $configId = $state[$slot]['config_id'] ?? null;
                if (is_string($configId) && pcv_log_valid_uuid($configId)) {
                    $endConfigId = $configId;
                    break;
                }
            }
            $state['active'] = null;
            $state['pending'] = null;
        }
        $commit = static function () use ($directory, $state): void {
            pcv_write_store($directory, $state);
        };
        $companion = pcv_companion_run_locked_commit($companionPrecommit, $before, $state, [
            'operation' => 'stage',
            'now' => $now,
            'config_id' => $state['pending']['config_id'] ?? null,
            'end_reason' => $endReason,
        ], $commit);
        if (($companion['status'] ?? null) !== 'committed') {
            $refused = pcv_result('unavailable', null, false, reason: 'companion_precommit_refused');
            $refused['_companionOutcome'] = $companion['outcome'] ?? 'uncertain';
            $refused['_companionReason'] = $companion['reason'] ?? null;
            return $refused;
        }
        if (is_array($invalidated)) {
            pcv_log_set_playthrough_ref($invalidated['key']);
            pcv_log_set_config_id($invalidated['active_config_id']);
            pcv_log_event('state.scope_invalidated', 'warning', 'invalidated', 'playthrough_changed', [
                'active_config_id' => $invalidated['active_config_id'],
                'pending_config_id' => $invalidated['pending_config_id'],
            ]);
            pcv_log_set_playthrough_ref($key);
        }
        foreach ($expired as $expiredEntry) {
            pcv_log_set_config_id(pcv_valid_config_id($expiredEntry['config_id']) ? $expiredEntry['config_id'] : null);
            pcv_log_event('state.scope_expired', 'info', 'expired', $expiredEntry['target'] . '_ttl', ['target' => $expiredEntry['target']]);
        }
        if (!$config['enabled']) {
            pcv_log_set_config_id($endConfigId);
            pcv_log_event('state.scope_ended', 'info', 'ok', in_array($endReason, ['ended_in_game', 'wrapped_up'], true) ? $endReason : null,
                pcv_state_log_context($config));
            return pcv_visible_state($state, $now);
        }
        $pendingConfigId = $state['pending']['config_id'];
        pcv_log_set_config_id($pendingConfigId);
        pcv_log_event('state.scope_staged', 'info', 'ok', null, pcv_state_log_context($config));
        return pcv_visible_state($state, $now);
    } catch (Throwable $error) {
        pcv_log_exception('state.unavailable', 'error', 'unavailable', 'state_stage_failed', $error, ['operation' => 'stage']);
        return pcv_result('unavailable', null, false);
    } finally {
        pcv_unlock_state($handle);
    }
}

/**
 * Narrow a group config to the members present in $eligible (catalog ID => name). Legacy pairs and solo are
 * all-or-nothing. Returns ['config' => narrowed, 'dropped' => [ids]] or null when the scene cannot run.
 */
function pcv_group_narrow_config(array $config, ?array $eligible): ?array
{
    if (!is_array($eligible)) {
        return null;
    }
    $ids = pcv_config_actor_ids($config);
    $present = array_values(array_filter($ids, static fn($id) => is_string($id) && array_key_exists($id, $eligible)));
    if (!is_array($config['actor_ids'] ?? null)) {
        return count($present) === count($ids) ? ['config' => $config, 'dropped' => []] : null;
    }
    if (count($present) < 2) {
        return null;
    }
    $narrowed = $config;
    $narrowed['actor_ids'] = $present;
    $narrowed['actor_a'] = $present[0];
    $narrowed['actor_b'] = $present[1];
    if (($narrowed['opener'] ?? 'auto') !== 'auto' && !in_array($narrowed['opener'], $present, true)) {
        $narrowed['opener'] = 'auto';
    }
    return ['config' => $narrowed, 'dropped' => array_values(array_diff($ids, $present))];
}

/** Members of a free scene: the first $cap candidate IDs (nearest first) that are strictly eligible. */
function pcv_free_select_members(array $candidateOrder, array $eligible, int $cap = PCV_FREE_MAX_MEMBERS): array
{
    $members = [];
    foreach ($candidateOrder as $id) {
        $id = (string)$id;
        if (array_key_exists($id, $eligible) && !in_array($id, $members, true)) {
            $members[] = $id;
            if (count($members) >= $cap) {
                break;
            }
        }
    }
    return $members;
}

/**
 * $eligibleNpcMap is the strict map (close report + fresh activity) used for activation.
 * $freeCandidateOrder lists catalog IDs nearest first for a pending free scene (map order when null).
 * $activeEligibleNpcMap, when given, is the in-scene map (pcv_read_active_scene_npcs) used only to
 * keep an already active scene; $activePresenceCheck names the failed check for the refusal log.
 */
function pcv_begin_request(string $key, bool $eligible, ?string $stateDirectory = null, ?array $eligibleNpcMap = null,
    ?array $activeEligibleNpcMap = null, ?string $activePresenceCheck = null, ?array $freeCandidateOrder = null,
    bool $allowActiveDrops = true, ?callable $companionPrecommit = null): array
{
    if (!pcv_valid_key($key)) {
        pcv_log_set_playthrough_ref(null);
        pcv_log_event('state.unavailable', 'error', 'unavailable', 'invalid_state_key', ['operation' => 'begin']);
        return pcv_result('unavailable', null, false);
    }
    pcv_log_set_playthrough_ref($key);

    $handle = null;
    $expired = [];
    $activated = null;
    $invalidated = null;
    $blockedPending = null;
    $blockedActive = null;
    $autoEnded = null;
    try {
        $directory = pcv_state_directory($stateDirectory);
        $handle = pcv_lock_state($directory, true, LOCK_EX);
        $loaded = pcv_load_store($directory);
        if ($loaded['kind'] === 'unavailable') {
            pcv_log_event('state.unavailable', 'error', 'unavailable', $loaded['reason'] ?? 'state_transition_failed', ['operation' => 'begin']);
            return pcv_result('unavailable', null, false);
        }

        $state = $loaded['kind'] === 'ready' ? $loaded['state'] : pcv_empty_store($key);
        $changed = $loaded['kind'] === 'ready' && $state['key'] !== $key;
        if ($changed) {
            $invalidated = [
                'key' => $state['key'],
                'active_config_id' => is_array($state['active']) && pcv_valid_config_id($state['active']['config_id'] ?? null)
                    ? ($state['active']['config_id'] ?? null) : null,
                'pending_config_id' => is_array($state['pending']) && pcv_valid_config_id($state['pending']['config_id'] ?? null)
                    ? ($state['pending']['config_id'] ?? null) : null,
            ];
            $state = pcv_empty_store($key);
        }

        $before = $state;

        $now = time();
        if (is_array($state['active']) && $state['active']['expires_at'] <= $now) {
            $expired[] = ['target' => 'active', 'config_id' => $state['active']['config_id'] ?? null];
            $state['active'] = null;
            $changed = true;
        }
        if (is_array($state['pending']) && $state['pending']['expires_at'] <= $now) {
            $expired[] = ['target' => 'pending', 'config_id' => $state['pending']['config_id'] ?? null];
            $state['pending'] = null;
            $changed = true;
        }

        if ($eligible && is_array($state['pending'])) {
            $pending = $state['pending'];
            $config = $pending['config'];
            $configId = $pending['config_id'] ?? null;
            // Groups start with the checked members who are present (at least 2); pairs and solo need everyone.
            if (($config['free'] ?? false) === true && !isset($config['actor_ids'])) {
                // Free scenes (0.1.12) take the nearest eligible NPCs, fixed for the scene.
                $members = is_array($eligibleNpcMap)
                    ? pcv_free_select_members($freeCandidateOrder ?? array_keys($eligibleNpcMap), $eligibleNpcMap,
                        is_int($config['free_cap'] ?? null) ? $config['free_cap'] : PCV_FREE_MAX_MEMBERS) : [];
                $narrowed = count($members) >= 2 ? ['config' => array_replace($config, [
                    'actor_a' => $members[0], 'actor_b' => $members[1], 'actor_ids' => $members, 'opener' => 'auto',
                ]), 'dropped' => []] : null;
            } else {
                $narrowed = $config['enabled'] ? pcv_group_narrow_config($config, $eligibleNpcMap) : ['config' => $config, 'dropped' => []];
            }
            if (!is_array($narrowed)) {
                $blockedPending = $pending;
            } else {
                if (array_key_exists('config_id', $pending) && !pcv_valid_config_id($configId)) {
                    $configId = null;
                } elseif (!array_key_exists('config_id', $pending)) {
                    $configId = pcv_log_new_uuid();
                }
                $activeEntry = [
                    'config' => $narrowed['config'],
                    'config_id' => $configId,
                    'activated_at' => $now,
                    'expires_at' => $now + PCV_ACTIVE_TTL,
                ];
                if ($narrowed['dropped'] !== []) {
                    $activeEntry['dropped'] = array_map(static fn($id) => ['id' => $id, 'reason' => 'not_eligible_at_start'], $narrowed['dropped']);
                }
                $state['active'] = $config['enabled'] ? $activeEntry : null;
                $state['pending'] = null;
                unset($state['last_end']);
                $activated = ['config' => $narrowed['config'], 'config_id' => $configId, 'dropped_count' => count($narrowed['dropped'])];
                $changed = true;
            }
        }

        $leftScene = null;
        $autoEndReason = null;
        if (!is_array($blockedPending) && is_array($state['active'])) {
            $activeConfig = $state['active']['config'] ?? null;
            if (is_array($activeConfig) && ($activeConfig['enabled'] ?? null) === true) {
                // A member who left beyond grace/wide is dropped while 2 remain; otherwise the scene is refused.
                $kept = pcv_group_narrow_config($activeConfig, $activeEligibleNpcMap ?? $eligibleNpcMap);
                if (is_array($kept) && $kept['dropped'] !== [] && !$allowActiveDrops) {
                    // 0.1.13: unreadable evidence refuses the turn; it never removes a member for good.
                    $kept = null;
                }
                if (!is_array($kept)) {
                    $blockedActive = $state['active'];
                    // 0.1.13: a scene refused for PCV_ACTIVE_REFUSAL_END seconds ends itself (this input stays refused).
                    $refusedSince = $state['active']['refused_since'] ?? null;
                    if (is_int($refusedSince) && $now - $refusedSince >= PCV_ACTIVE_REFUSAL_END) {
                        $autoEnded = $state['active'];
                        $state['active'] = null;
                        $autoEndReason = $allowActiveDrops && is_array($activeEligibleNpcMap ?? $eligibleNpcMap)
                            ? 'members_gone' : 'evidence_unavailable';
                        $state['last_end'] = ['reason' => $autoEndReason, 'at' => $now];
                    } elseif (!is_int($refusedSince)) {
                        $state['active']['refused_since'] = max($now, $state['active']['activated_at']);
                    }
                    $changed = true;
                } elseif ($kept['dropped'] !== []) {
                    $state['active']['config'] = $kept['config'];
                    $state['active']['dropped'] = array_merge($state['active']['dropped'] ?? [],
                        array_map(static fn($id) => ['id' => $id, 'reason' => 'left_scene'], $kept['dropped']));
                    $changed = true;
                    $leftScene = [
                        'config_id' => $state['active']['config_id'] ?? null,
                        'dropped_count' => count($kept['dropped']),
                        'member_count' => count(pcv_config_actor_ids($kept['config'])),
                    ];
                }
            }
        }

        // 0.1.13: a turn that keeps the scene active renews its lifetime (at most once a minute), so a scene ends
        // after an hour without use instead of one hour after it started.
        if (!is_array($blockedPending) && !is_array($blockedActive) && is_array($state['active'])) {
            if (array_key_exists('refused_since', $state['active'])) {
                unset($state['active']['refused_since']);
                $changed = true;
            }
            $lastRenewal = $state['active']['renewed_at'] ?? $state['active']['activated_at'];
            if (is_int($lastRenewal) && $now - $lastRenewal >= 60) {
                $state['active']['renewed_at'] = $now;
                $state['active']['expires_at'] = $now + PCV_ACTIVE_TTL;
                $changed = true;
            }
        }

        $commit = static function () use ($directory, $state, $changed): void {
            if ($changed) {
                pcv_write_store($directory, $state);
            }
        };
        $companion = pcv_companion_run_locked_commit($companionPrecommit, $before, $state, [
            'operation' => 'begin',
            'now' => $now,
            'key' => $key,
            'eligible' => $eligible,
            'eligible_npc_map' => $eligibleNpcMap,
            'active_eligible_npc_map' => $activeEligibleNpcMap,
            'activated' => $activated,
            'blocked_pending' => $blockedPending,
            'blocked_active' => $blockedActive,
        ], $commit);
        if (($companion['status'] ?? null) !== 'committed') {
            $refused = pcv_result('unavailable', null, false, reason: 'companion_precommit_refused');
            $refused['_companionOutcome'] = $companion['outcome'] ?? 'uncertain';
            $refused['_companionReason'] = $companion['reason'] ?? null;
            return $refused;
        }
        if (is_array($invalidated)) {
            pcv_log_set_playthrough_ref($invalidated['key']);
            pcv_log_set_config_id($invalidated['active_config_id']);
            pcv_log_event('state.scope_invalidated', 'warning', 'invalidated', 'playthrough_changed', [
                'active_config_id' => $invalidated['active_config_id'],
                'pending_config_id' => $invalidated['pending_config_id'],
            ]);
            pcv_log_set_playthrough_ref($key);
        }
        foreach ($expired as $expiredEntry) {
            pcv_log_set_config_id(pcv_valid_config_id($expiredEntry['config_id']) ? $expiredEntry['config_id'] : null);
            pcv_log_event('state.scope_expired', 'info', 'expired', $expiredEntry['target'] . '_ttl', ['target' => $expiredEntry['target']]);
        }
        if (is_array($activated)) {
            pcv_log_set_config_id($activated['config_id']);
            $activationContext = pcv_state_log_context($activated['config']);
            if (($activated['config']['enabled'] ?? false) === true) {
                $activationContext['member_count'] = count(pcv_config_actor_ids($activated['config']));
                $activationContext['dropped_count'] = $activated['dropped_count'] ?? 0;
            }
            pcv_log_event('state.scope_activated', 'info', 'ok', null, $activationContext);
        }
        if (is_array($autoEnded)) {
            $endedId = $autoEnded['config_id'] ?? null;
            pcv_log_set_config_id(is_string($endedId) && pcv_log_valid_uuid($endedId) ? $endedId : null);
            pcv_log_event('state.scope_ended', 'info', 'ok', $autoEndReason,
                pcv_state_log_context(['enabled' => false] + $autoEnded['config']));
        }
        if (is_array($leftScene)) {
            pcv_log_set_config_id(is_string($leftScene['config_id']) && pcv_log_valid_uuid($leftScene['config_id']) ? $leftScene['config_id'] : null);
            pcv_log_event('state.scope_members_dropped', 'info', 'ok', 'left_scene', [
                'drop_reason' => 'left_scene', 'dropped_count' => $leftScene['dropped_count'], 'member_count' => $leftScene['member_count'],
            ]);
        }
        if (is_array($blockedPending)) {
            $pendingConfigId = $blockedPending['config_id'] ?? null;
            if (!is_string($pendingConfigId) || !pcv_log_valid_uuid($pendingConfigId)) {
                $pendingConfigId = null;
            }
            pcv_log_set_config_id($pendingConfigId);
            $sceneMode = $blockedPending['config']['scene_mode'] ?? 'pair';
            pcv_log_event('state.scope_skipped', 'info', 'skipped', 'scene_not_eligible', [
                'operation' => 'begin', 'scene_mode' => $sceneMode,
            ]);
            return pcv_result('unavailable', null, true, pcv_config_with_scene_mode($blockedPending['config']), null,
                is_string($pendingConfigId) ? $pendingConfigId : null, 'scene_not_eligible');
        }
        if (is_array($blockedActive)) {
            $activeConfigId = $blockedActive['config_id'] ?? null;
            if (!is_string($activeConfigId) || !pcv_log_valid_uuid($activeConfigId)) {
                $activeConfigId = null;
            }
            $pendingScope = is_array($state['pending'] ?? null) ? $state['pending']['config'] : null;
            $pendingConfigId = is_array($state['pending'] ?? null) ? ($state['pending']['config_id'] ?? null) : null;
            if (!is_string($pendingConfigId) || !pcv_log_valid_uuid($pendingConfigId)) {
                $pendingConfigId = null;
            }
            pcv_log_set_config_id($activeConfigId);
            $sceneMode = $blockedActive['config']['scene_mode'] ?? 'pair';
            $skipContext = ['operation' => 'begin', 'scene_mode' => $sceneMode];
            if (is_string($activePresenceCheck)) {
                $skipContext['presence_check'] = $activePresenceCheck;
            }
            if (is_array($activeEligibleNpcMap)) {
                $skipContext['missing_count'] = pcv_config_missing_actor_count($blockedActive['config'], $activeEligibleNpcMap);
            }
            pcv_log_event('state.scope_skipped', 'info', 'skipped', 'scene_not_eligible', $skipContext);
            return pcv_result('unavailable', null, is_array($pendingScope),
                is_array($pendingScope) ? pcv_config_with_scene_mode($pendingScope) : null,
                is_string($activeConfigId) ? $activeConfigId : null,
                is_string($pendingConfigId) ? $pendingConfigId : null, 'scene_not_eligible');
        }
        $result = pcv_visible_state($state, $now);
        pcv_log_set_config_id($result['config_id']);
        return $result;
    } catch (Throwable $error) {
        pcv_log_exception('state.unavailable', 'error', 'unavailable', 'state_transition_failed', $error, ['operation' => 'begin']);
        return pcv_result('unavailable', null, false);
    } finally {
        pcv_unlock_state($handle);
    }
}
