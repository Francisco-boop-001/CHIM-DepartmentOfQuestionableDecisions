<?php
declare(strict_types=1);

const PCV_COMPANION_STORE_VERSION = 1;
const PCV_COMPANION_TOKEN_TTL = 120;
const PCV_COMPANION_TERMINAL_DETAIL_TTL = 604800;
const PCV_COMPANION_MAX_OUTSTANDING_OPERATIONS = 64;
const PCV_COMPANION_MAX_PREPARED_TEXT_BYTES = 524288;
const PCV_COMPANION_MAX_RECORD_BYTES = 65536;
const PCV_COMPANION_OWNER_VERSION = '0.1.23';
const PCV_COMPANION_OWNER_UPSTREAM_COMMIT = '77265202d7f4a47b88e1eb88131b21f4b0752020';
const PCV_COMPANION_OWNER_BASE_SOURCE_HASHES = [
    'manifest.json' => 'f045b9b6bd3e13a84cdabbf400a73ef4581acd8fa440e1e84f51b1dd0cb5f84e',
    'state.php' => '142f5a026740dfb629af925005c7e931396aa3dcde0d534002fb42b545c5664d',
    'scope.php' => '763ff62b129086f7c3d91fbccbb80e8db0ec71b04d11fc150c4881420bbe6992',
    'preprocessing.php' => '5a0d46d3b29ba5f1a986f90b1fa7bbc8ce41402dd6b8d30f49a2fcba7783d49c',
    'log.php' => '0a3a0684211bcb7e3e2a380ddf0d0173dfe838471b557d0b6ab1a1ec8a3fef56',
    'installation_guard.php' => '082cb9534bb3c8570faa384c5050d5193f2fbe19a58534381bb7f1b47e2a607d',
    'reflection.php' => 'd33a41331638be653d7c94119043672d7142e73ac752f8adb7b7cfc090670540',
];
const PCV_COMPANION_OWNER_SOURCE_HASHES = [
    'manifest.json' => 'f045b9b6bd3e13a84cdabbf400a73ef4581acd8fa440e1e84f51b1dd0cb5f84e',
    'state.php' => '3fbc48a0f25178011f0b838cc01d3664932822695d7d39c35d49cbc11fa057c1',
    'scope.php' => 'aa800deb4a34e0a77663e938b80a11d748978fc1b420a708c1c9125fce9bf398',
    'preprocessing.php' => '01134dea7cd1adc6a2467f26297f14d26e70398f50b1c1963ea02c9af1936950',
    'log.php' => '0a3a0684211bcb7e3e2a380ddf0d0173dfe838471b557d0b6ab1a1ec8a3fef56',
    'installation_guard.php' => '082cb9534bb3c8570faa384c5050d5193f2fbe19a58534381bb7f1b47e2a607d',
    'reflection.php' => 'd33a41331638be653d7c94119043672d7142e73ac752f8adb7b7cfc090670540',
];

/** Validate the literal before it can be stored for restoration into stock CHIM. */
function pcv_companion_text_is_safe(mixed $text): bool
{
    if (!is_string($text) || strlen($text) > 8000 || preg_match('//u', $text) !== 1
        || str_contains($text, "\0") || trim($text) === '') {
        return false;
    }
    $characters = preg_match_all('/./us', $text);
    if (!is_int($characters) || $characters > 2000) {
        return false;
    }

    // Refuse one-shot route prefixes after leading whitespace before CHIM routing.
    $candidate = ltrim($text, " \t\r\n\v\f");
    if (preg_match('/\A(?:\(\(|%%|!!|\*\*|%|@|>|#|\()/u', $candidate) === 1) {
        return false;
    }
    return true;
}

function pcv_companion_owner_root(): string
{
    $configured = getenv('QD_PCV_ROOT');
    if (!is_string($configured) || $configured === '' || str_contains($configured, "\0")) {
        $configured = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private_conversation';
    }
    $root = realpath($configured);
    if (!is_string($root) || !is_dir($root) || is_link($configured)) {
        throw new RuntimeException('The pinned Private Conversation owner source is unavailable.');
    }
    return $root;
}

function pcv_companion_load_owner_sources(bool $includePreprocessing = false): string
{
    static $loadedRoots = [];
    $root = pcv_companion_owner_root();
    foreach (PCV_COMPANION_OWNER_SOURCE_HASHES as $relative => $expectedHash) {
        $path = $root . DIRECTORY_SEPARATOR . $relative;
        $actualHash = is_file($path) && !is_link($path) ? @hash_file('sha256', $path) : false;
        if (!is_string($actualHash) || !hash_equals($expectedHash, strtolower($actualHash))) {
            throw new RuntimeException('The pinned Private Conversation owner source is incompatible.');
        }
    }
    $manifest = json_decode((string)@file_get_contents($root . DIRECTORY_SEPARATOR . 'manifest.json'), true, 8);
    if (!is_array($manifest) || ($manifest['version'] ?? null) !== PCV_COMPANION_OWNER_VERSION) {
        throw new RuntimeException('The pinned Private Conversation owner version is incompatible.');
    }
    foreach (['log.php', 'state.php', 'scope.php'] as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . $relative;
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('The pinned Private Conversation owner source is unavailable.');
        }
        require_once $path;
    }
    if ($includePreprocessing) {
        $path = $root . DIRECTORY_SEPARATOR . 'preprocessing.php';
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('The pinned Private Conversation preprocessor is unavailable.');
        }
        require_once $path;
    }
    foreach (['pcv_log_default_directory', 'pcv_current_playthrough_key', 'pcv_current_player_name', 'pcv_load_store', 'pcv_visible_state', 'pcv_lock_state', 'pcv_unlock_state', 'pcvPrepareScopedInput', 'pcvScopeKnownNpcs', 'pcvScopeMembers'] as $function) {
        if (!function_exists($function)) {
            throw new RuntimeException('The pinned Private Conversation owner helper contract is unavailable.');
        }
        $sourcePath = realpath((new ReflectionFunction($function))->getFileName() ?: '');
        if (!is_string($sourcePath) || !str_starts_with($sourcePath, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Private Conversation owner helpers were loaded from an unexpected path.');
        }
    }
    $loadedRoots[$root] = true;
    return $root;
}

/** Revalidate the staged facade identity on stock main.php, which has no HTTP bearer header. */
function pcv_companion_with_current_pair_context(array $operation, callable $callback): mixed
{
    $configuredRoot = getenv('QD_CHIM_ROOT');
    if (!is_string($configuredRoot) || $configuredRoot === '') {
        // Installed at ext/pcv_companion/companion.php.
        $configuredRoot = dirname(__DIR__, 2);
    }
    $coreRoot = str_contains($configuredRoot, "\0") ? false : realpath($configuredRoot);
    if (!is_string($coreRoot) || !is_dir($coreRoot)
        || !is_file($coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'questionable_decisions' . DIRECTORY_SEPARATOR . 'api.php')) {
        throw new RuntimeException('The trusted CHIM root is unavailable.');
    }
    $apiPath = $coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR
        . 'questionable_decisions' . DIRECTORY_SEPARATOR . 'api.php';
    $dispatcherPath = $coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR
        . 'questionable_decisions' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'dispatcher.php';
    if (!is_file($apiPath) || is_link($apiPath)) {
        throw new RuntimeException('The paired Department context helper is unavailable.');
    }
    $expectedApiPath = realpath($apiPath);
    $expectedDispatcherPath = realpath($dispatcherPath);
    if (!is_string($expectedApiPath) || !is_string($expectedDispatcherPath)) {
        throw new RuntimeException('The paired Department context helper is unavailable.');
    }
    foreach (['qd_api_settings_from_environment', 'qd_api_runtime_dependencies'] as $function) {
        if (function_exists($function)) {
            $loadedPath = realpath((new ReflectionFunction($function))->getFileName() ?: '');
            if ($loadedPath !== $expectedApiPath) {
                throw new RuntimeException('The paired Department API was loaded from an unexpected path.');
            }
        }
    }
    if (function_exists('qd_with_locked_companion_context')) {
        $loadedPath = realpath((new ReflectionFunction('qd_with_locked_companion_context'))->getFileName() ?: '');
        if ($loadedPath !== $expectedDispatcherPath) {
            throw new RuntimeException('The paired Department context helper was loaded from an unexpected path.');
        }
    }
    require_once $apiPath;
    foreach (['qd_api_settings_from_environment', 'qd_api_runtime_dependencies', 'qd_with_locked_companion_context'] as $function) {
        if (!function_exists($function)) {
            throw new RuntimeException('The paired Department context helper is unavailable.');
        }
        $loadedPath = realpath((new ReflectionFunction($function))->getFileName() ?: '');
        $expectedPath = $function === 'qd_with_locked_companion_context' ? $expectedDispatcherPath : $expectedApiPath;
        if (!is_string($loadedPath) || $loadedPath !== $expectedPath) {
            throw new RuntimeException('The paired Department helper was loaded from an unexpected path.');
        }
    }
    $principalId = $operation['principalId'] ?? null;
    $scopeHash = $operation['scopeHash'] ?? null;
    $context = $operation['context'] ?? null;
    if (!is_string($principalId) || !is_string($scopeHash) || !is_array($context)
        || !is_string($context['serverContext'] ?? null)
        || !is_string($context['viewToken'] ?? null)
        || !is_string($context['nativeGeneration'] ?? null)) {
        throw new RuntimeException('The staged Department context is unavailable.');
    }
    return qd_with_locked_companion_context(
        qd_api_settings_from_environment(),
        qd_api_runtime_dependencies(),
        $principalId,
        $scopeHash,
        $context,
        $callback,
    );
}

function pcv_companion_state_directory(): string
{
    pcv_companion_load_owner_sources();
    $privateRoot = pcv_log_default_directory(false);
    if (!is_string($privateRoot) || $privateRoot === '' || is_link($privateRoot)) {
        throw new RuntimeException('Private Conversation state storage is unavailable.');
    }
    $directory = $privateRoot . DIRECTORY_SEPARATOR . 'state';
    if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
        throw new RuntimeException('Private Conversation state storage is unsafe.');
    }
    return $directory;
}

function pcv_companion_project_owner_config(?array $source, array $knownNpcs): ?array
{
    if (!is_array($source) || ($source['enabled'] ?? null) !== true) {
        return null;
    }
    $mapId = static function (mixed $rawId) use ($knownNpcs): ?string {
        $id = is_int($rawId) ? (string)$rawId : (is_string($rawId) ? $rawId : '');
        return preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) === 1 && isset($knownNpcs[$id]) ? 'npc:' . $id : null;
    };
    $configuration = [
        'enabled' => true,
        'scene_mode' => $source['scene_mode'] ?? 'pair',
        'exclude_player' => $source['exclude_player'] ?? null,
        'bystander_mode' => $source['bystander_mode'] ?? null,
    ];
    if (array_key_exists('free', $source)) {
        $configuration['free'] = $source['free'];
    }
    if (array_key_exists('actor_a', $source)) {
        $configuration['actor_a'] = $mapId($source['actor_a']);
    }
    if (array_key_exists('actor_b', $source)) {
        $configuration['actor_b'] = $mapId($source['actor_b']);
    }
    if (array_key_exists('actor_ids', $source)) {
        if (!is_array($source['actor_ids']) || !array_is_list($source['actor_ids'])) {
            return null;
        }
        $configuration['actor_ids'] = array_map($mapId, $source['actor_ids']);
    }
    if (array_key_exists('opener', $source)) {
        $configuration['opener'] = $source['opener'] === 'auto' ? 'auto' : $mapId($source['opener']);
    }
    foreach (['actor_a', 'actor_b'] as $field) {
        if (array_key_exists($field, $configuration) && !is_string($configuration[$field])) {
            return null;
        }
    }
    foreach ($configuration['actor_ids'] ?? [] as $actorKey) {
        if (!is_string($actorKey)) {
            return null;
        }
    }
    return $configuration;
}

function pcv_companion_owner_target_identity(string $targetName, array $knownNpcs): ?array
{
    if ($targetName === '' || strlen($targetName) > 256 || preg_match('//u', $targetName) !== 1
        || !function_exists('pcv_scope_name_key')) {
        return null;
    }
    try {
        $targetKey = pcv_scope_name_key($targetName);
    } catch (Throwable) {
        return null;
    }
    if ($targetKey === '') {
        return null;
    }

    $matches = [];
    foreach ($knownNpcs as $rawId => $canonicalName) {
        $id = is_int($rawId) ? (string)$rawId : $rawId;
        if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) !== 1
            || !is_string($canonicalName) || $canonicalName === '' || strlen($canonicalName) > 256
            || preg_match('//u', $canonicalName) !== 1 || trim($canonicalName) !== $canonicalName) {
            continue;
        }
        try {
            if (pcv_scope_name_key($canonicalName) === $targetKey) {
                $matches[] = ['id' => $id, 'canonicalName' => $canonicalName];
            }
        } catch (Throwable) {
            return null;
        }
    }
    return count($matches) === 1 ? $matches[0] : null;
}

/** Build a temporary strict-name packet for the frozen owner guard; callers retain raw identity separately. */
function pcv_companion_owner_target_projection(array $packet, array $knownNpcs): ?array
{
    $target = $packet['target'] ?? null;
    $rawName = is_array($target) ? ($target['name'] ?? null) : null;
    if (!is_array($target) || !is_string($rawName)) {
        return null;
    }
    $identity = pcv_companion_owner_target_identity($rawName, $knownNpcs);
    if ($identity === null) {
        return null;
    }
    $packet['target']['name'] = $identity['canonicalName'];
    return ['identity' => $identity, 'packet' => $packet];
}

function pcv_companion_owner_target_is_member(array $configuration, string $targetName, array $knownNpcs): bool
{
    $identity = pcv_companion_owner_target_identity($targetName, $knownNpcs);
    if ($identity === null) {
        return false;
    }
    if (($configuration['free'] ?? false) === true) {
        return true;
    }
    $targetKey = 'npc:' . $identity['id'];
    $members = is_array($configuration['actor_ids'] ?? null) ? $configuration['actor_ids'] : [];
    if ($members === []) {
        foreach (['actor_a', 'actor_b'] as $field) {
            if (is_string($configuration[$field] ?? null)) {
                $members[] = $configuration[$field];
            }
        }
    }
    return in_array($targetKey, $members, true);
}

function pcv_companion_owner_scope_contains_target(array $scope, string $targetName, array $knownNpcs): bool
{
    if (!function_exists('pcvScopeMembers')) {
        return false;
    }
    $identity = pcv_companion_owner_target_identity($targetName, $knownNpcs);
    if ($identity === null) {
        return false;
    }
    $matches = 0;
    foreach (pcvScopeMembers($scope) as $memberName) {
        if (!is_string($memberName)) {
            return false;
        }
        $member = pcv_companion_owner_target_identity($memberName, $knownNpcs);
        if ($member !== null && $member['id'] === $identity['id']
            && $member['canonicalName'] === $identity['canonicalName']) {
            $matches++;
        }
    }
    return $matches === 1;
}

function pcv_companion_record_key(string $principalId, string $scopeHash, string $operationId): string
{
    return hash('sha256', implode("\0", [$principalId, $scopeHash, strtolower($operationId)]));
}

function pcv_companion_store_directory(string $directory): string
{
    $resolved = realpath($directory);
    if (!is_string($resolved) || !is_dir($resolved) || is_link($directory)) {
        throw new RuntimeException('Private Conversation state directory is unavailable.');
    }
    $store = $resolved . DIRECTORY_SEPARATOR . 'companion-operations';
    if (!is_dir($store) && !@mkdir($store, 0700)) {
        throw new RuntimeException('Private Conversation companion state is unavailable.');
    }
    if (is_link($store) || !is_dir($store) || realpath($store) !== $store) {
        throw new RuntimeException('Private Conversation companion state is unsafe.');
    }
    @chmod($store, 0700);
    foreach (['prepared', 'receipts'] as $child) {
        $path = $store . DIRECTORY_SEPARATOR . $child;
        if (!is_dir($path) && !@mkdir($path, 0700)) {
            throw new RuntimeException('Private Conversation companion state is unavailable.');
        }
        if (is_link($path) || !is_dir($path) || realpath($path) !== $path) {
            throw new RuntimeException('Private Conversation companion state is unsafe.');
        }
        @chmod($path, 0700);
    }
    return $store;
}

/**
 * Serialize extension records independently of the PCV JSON schema.
 * Production callers first hold PCV's state.lock, then the facade context lock;
 * this lock is always last in that order.
 */
function pcv_companion_store_transaction(string $directory, callable $callback): mixed
{
    $store = pcv_companion_store_directory($directory);
    $lockPath = $store . DIRECTORY_SEPARATOR . '.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Private Conversation companion lock is unsafe.');
    }
    $lock = @fopen($lockPath, 'c+b');
    if ($lock === false) {
        throw new RuntimeException('Private Conversation companion lock is unavailable.');
    }
    @chmod($lockPath, 0600);
    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        throw new RuntimeException('Private Conversation companion lock is unavailable.');
    }
    try {
        return $callback($store);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function pcv_companion_record_path(string $store, string $recordKey, bool $receipt = false): string
{
    if (preg_match('/\A[a-f0-9]{64}\z/D', $recordKey) !== 1) {
        throw new RuntimeException('Private Conversation companion record key is invalid.');
    }
    return $store . DIRECTORY_SEPARATOR . ($receipt ? 'receipts' : 'prepared') . DIRECTORY_SEPARATOR . $recordKey . '.json';
}

function pcv_companion_read_record(string $store, string $recordKey, bool $cleanupShadowPayload = false): ?array
{
    // Receipts win if a process stopped after publishing a terminal tombstone but
    // before it removed the prepared payload. Such a payload is never claimable.
    $receiptPath = pcv_companion_record_path($store, $recordKey, true);
    $path = file_exists($receiptPath) ? $receiptPath : pcv_companion_record_path($store, $recordKey);
    if (!file_exists($path)) {
        return null;
    }
    if (is_link($path) || !is_file($path)) {
        throw new RuntimeException('Private Conversation companion record is unsafe.');
    }
    $size = @filesize($path);
    if (!is_int($size) || $size < 2 || $size > PCV_COMPANION_MAX_RECORD_BYTES) {
        throw new RuntimeException('Private Conversation companion record is unavailable.');
    }
    $json = @file_get_contents($path);
    if (!is_string($json)) {
        throw new RuntimeException('Private Conversation companion record is unavailable.');
    }
    try {
        $record = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Private Conversation companion record is invalid.');
    }
    if (!is_array($record) || ($record['version'] ?? null) !== PCV_COMPANION_STORE_VERSION
        || ($record['recordKey'] ?? null) !== $recordKey
        || !in_array($record['state'] ?? null, ['prepared', 'claimed', 'consumed', 'rejected', 'cancelled', 'expired', 'unknown'], true)) {
        throw new RuntimeException('Private Conversation companion record is invalid.');
    }
    if ($cleanupShadowPayload && $path === $receiptPath) {
        $preparedPath = pcv_companion_record_path($store, $recordKey);
        if (file_exists($preparedPath)) {
            if (is_link($preparedPath) || !is_file($preparedPath) || !@unlink($preparedPath)) {
                throw new RuntimeException('Private Conversation companion payload cleanup is unavailable.');
            }
        }
    }
    return $record;
}

function pcv_companion_write_record(string $store, string $recordKey, array $record): void
{
    $isPrepared = ($record['state'] ?? null) === 'prepared';
    $path = pcv_companion_record_path($store, $recordKey, !$isPrepared);
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (strlen($json) > PCV_COMPANION_MAX_RECORD_BYTES) {
        throw new RuntimeException('Private Conversation companion record exceeds its bound.');
    }
    $temporary = $store . DIRECTORY_SEPARATOR . '.' . $recordKey . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $handle = @fopen($temporary, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('Private Conversation companion state is unavailable.');
    }
    $written = 0;
    try {
        while ($written < strlen($json)) {
            $count = @fwrite($handle, substr($json, $written));
            if (!is_int($count) || $count < 1) {
                throw new RuntimeException('Private Conversation companion state is unavailable.');
            }
            $written += $count;
        }
        if (!@fflush($handle) || (function_exists('fsync') && !@fsync($handle))) {
            throw new RuntimeException('Private Conversation companion state is unavailable.');
        }
        @chmod($temporary, 0600);
    } finally {
        fclose($handle);
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Private Conversation companion state is unavailable.');
    }
    @chmod($path, 0600);
    if (!$isPrepared) {
        $preparedPath = pcv_companion_record_path($store, $recordKey);
        if (file_exists($preparedPath) && (is_link($preparedPath) || !is_file($preparedPath) || !@unlink($preparedPath))) {
            throw new RuntimeException('Private Conversation companion payload cleanup is unavailable.');
        }
    }
}

function pcv_companion_payload_hash(array $stage): string
{
    $canonical = [
        'principalId' => hash('sha256', $stage['principalId']),
        'scopeHash' => $stage['scopeHash'],
        'operationId' => strtolower($stage['operationId']),
        'text' => $stage['text'],
        'target' => [
            'formId' => strtolower($stage['target']['formId']),
            'name' => $stage['target']['name'],
        ],
        'expectedActiveConfigId' => is_string($stage['expectedActiveConfigId']) ? strtolower($stage['expectedActiveConfigId']) : null,
        'expectedPendingConfigId' => is_string($stage['expectedPendingConfigId']) ? strtolower($stage['expectedPendingConfigId']) : null,
        'ownerKey' => $stage['ownerKey'],
        'context' => $stage['context'],
    ];
    return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function pcv_companion_validate_stage(array $stage): array
{
    $required = [
        'operationId', 'principalId', 'scopeHash', 'ownerKey', 'text', 'target', 'expectedActiveConfigId', 'expectedPendingConfigId', 'context',
    ];
    if (array_diff(array_keys($stage), $required) !== []
        || array_diff($required, array_keys($stage)) !== []
        || !is_string($stage['operationId'] ?? null)
        || preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/D', $stage['operationId']) !== 1
        || !is_string($stage['principalId'] ?? null) || $stage['principalId'] === '' || strlen($stage['principalId']) > 128
        || !is_string($stage['scopeHash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $stage['scopeHash']) !== 1
        || !is_string($stage['ownerKey'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $stage['ownerKey']) !== 1
        || !pcv_companion_text_is_safe($stage['text'] ?? null)
        || !is_array($stage['target'] ?? null)
        || array_diff(array_keys($stage['target']), ['formId', 'name']) !== []
        || !is_string($stage['target']['formId'] ?? null) || preg_match('/\A[0-9a-fA-F]{8}\z/D', $stage['target']['formId']) !== 1
        || !is_string($stage['target']['name'] ?? null) || trim($stage['target']['name']) === '' || strlen($stage['target']['name']) > 256
        || !is_array($stage['context'] ?? null)
        || array_diff(array_keys($stage['context']), ['serverContext', 'viewToken', 'nativeGeneration']) !== []
        || !is_string($stage['context']['serverContext'] ?? null) || $stage['context']['serverContext'] === '' || strlen($stage['context']['serverContext']) > 128
        || !is_string($stage['context']['viewToken'] ?? null)
        || preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/D', $stage['context']['viewToken']) !== 1
        || !is_string($stage['context']['nativeGeneration'] ?? null)
        || preg_match('/\A[0-9]{1,20}\z/D', $stage['context']['nativeGeneration']) !== 1) {
        throw new InvalidArgumentException('invalid_stage');
    }
    foreach (['expectedActiveConfigId', 'expectedPendingConfigId'] as $field) {
        $value = $stage[$field] ?? null;
        if ($value !== null && (!is_string($value)
            || preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/D', $value) !== 1)) {
            throw new InvalidArgumentException('invalid_stage');
        }
    }
    $stage['operationId'] = strtolower($stage['operationId']);
    $stage['target']['formId'] = strtolower($stage['target']['formId']);
    $stage['expectedActiveConfigId'] = is_string($stage['expectedActiveConfigId']) ? strtolower($stage['expectedActiveConfigId']) : null;
    $stage['expectedPendingConfigId'] = is_string($stage['expectedPendingConfigId']) ? strtolower($stage['expectedPendingConfigId']) : null;
    $stage['context']['viewToken'] = strtolower($stage['context']['viewToken']);
    return $stage;
}

function pcv_companion_new_token(): string
{
    $random = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    return 'pcv1_' . $random;
}

function pcv_companion_expire_prepared_records(string $store, int $now): array
{
    $preparedCount = 0;
    $preparedBytes = 0;
    $claimedCount = 0;
    $preparedDirectory = $store . DIRECTORY_SEPARATOR . 'prepared';
    foreach (new DirectoryIterator($preparedDirectory) as $item) {
        if (!$item->isFile() || $item->isLink() || !preg_match('/\A([a-f0-9]{64})\.json\z/D', $item->getFilename(), $matches)) {
            continue;
        }
        $recordKey = $matches[1];
        $record = pcv_companion_read_record($store, $recordKey, true);
        if ($record === null || ($record['state'] ?? null) !== 'prepared') {
            continue;
        }
        if (!is_int($record['expiresAt'] ?? null) || !is_string($record['text'] ?? null) || !is_string($record['token'] ?? null)) {
            throw new RuntimeException('Private Conversation companion record is invalid.');
        }
        if ($record['expiresAt'] <= $now) {
            $record['state'] = 'expired';
            $record['text'] = null;
            $record['token'] = null;
            $record['tokenHash'] = null;
            $record['terminalAt'] = $now;
            pcv_companion_write_record($store, $recordKey, $record);
            continue;
        }
        $preparedCount++;
        $preparedBytes += strlen($record['text']);
    }
    $receiptDirectory = $store . DIRECTORY_SEPARATOR . 'receipts';
    foreach (new DirectoryIterator($receiptDirectory) as $item) {
        if (!$item->isFile() || $item->isLink() || !preg_match('/\A([a-f0-9]{64})\.json\z/D', $item->getFilename(), $matches)) {
            continue;
        }
        $record = pcv_companion_read_record($store, $matches[1], true);
        if (($record['state'] ?? null) === 'claimed') {
            $claimedCount++;
        }
    }
    return [$preparedCount, $preparedBytes, $claimedCount];
}

function pcv_companion_status_projection(?array $record, string $operationId): array
{
    $state = $record['state'] ?? 'unknown';
    if ($state === 'claimed') {
        $state = 'unknown';
    }
    if ($state === 'unknown') {
        return [
            'operationId' => strtolower($operationId),
            'state' => 'unknown',
            'expected' => ['activeConfigId' => null, 'pendingConfigId' => null],
            'target' => null,
            'expiresAt' => null,
        ];
    }
    return [
        'operationId' => strtolower($operationId),
        'state' => $state,
        'expected' => [
            'activeConfigId' => $record['expectedActiveConfigId'] ?? null,
            'pendingConfigId' => $record['expectedPendingConfigId'] ?? null,
        ],
        'target' => isset($record['target']) && is_array($record['target'])
            ? ['formId' => $record['target']['formId'] ?? null, 'name' => $record['target']['name'] ?? null]
            : ['formId' => null, 'name' => null],
        'expiresAt' => is_int($record['expiresAt'] ?? null) ? $record['expiresAt'] : null,
    ];
}

function pcv_companion_stage_record(string $directory, array $stage, int $now, int $ttl = PCV_COMPANION_TOKEN_TTL): array
{
    $stage = pcv_companion_validate_stage($stage);
    if ($now < 1 || $ttl < 1 || $ttl > PCV_COMPANION_TOKEN_TTL) {
        throw new InvalidArgumentException('invalid_stage');
    }
    $recordKey = pcv_companion_record_key($stage['principalId'], $stage['scopeHash'], $stage['operationId']);
    $payloadHash = pcv_companion_payload_hash($stage);
    return pcv_companion_store_transaction($directory, static function (string $store) use ($stage, $now, $ttl, $recordKey, $payloadHash): array {
        [$preparedCount, $preparedBytes, $claimedCount] = pcv_companion_expire_prepared_records($store, $now);
        $existing = pcv_companion_read_record($store, $recordKey, true);
        if ($existing !== null) {
            if (!hash_equals((string)($existing['payloadHash'] ?? ''), $payloadHash)) {
                throw new DomainException('conflict');
            }
            $existing = pcv_companion_compact_terminal_record($store, $recordKey, $existing, $now);
            if (($existing['state'] ?? null) === 'prepared') {
                return [
                    'operationId' => $stage['operationId'],
                    'state' => 'prepared',
                    'token' => $existing['token'],
                    'expiresAt' => $existing['expiresAt'],
                ];
            }
            return ['operationId' => $stage['operationId'], 'state' => ($existing['state'] ?? null) === 'claimed' ? 'unknown' : ($existing['state'] ?? 'unknown')];
        }
        $textBytes = strlen($stage['text']);
        if ($preparedCount + $claimedCount >= PCV_COMPANION_MAX_OUTSTANDING_OPERATIONS
            || $preparedBytes + $textBytes > PCV_COMPANION_MAX_PREPARED_TEXT_BYTES) {
            throw new DomainException('capacity');
        }
        $token = pcv_companion_new_token();
        $record = [
            'version' => PCV_COMPANION_STORE_VERSION,
            'recordKey' => $recordKey,
            'principalId' => $stage['principalId'],
            'operationId' => $stage['operationId'],
            'payloadHash' => $payloadHash,
            'state' => 'prepared',
            'text' => $stage['text'],
            'token' => $token,
            'tokenHash' => hash('sha256', $token),
            'target' => ['formId' => $stage['target']['formId'], 'name' => $stage['target']['name']],
            'expectedActiveConfigId' => $stage['expectedActiveConfigId'],
            'expectedPendingConfigId' => $stage['expectedPendingConfigId'],
            'scopeHash' => $stage['scopeHash'],
            'ownerKey' => $stage['ownerKey'],
            'context' => $stage['context'],
            'createdAt' => $now,
            'expiresAt' => $now + $ttl,
            'terminalAt' => null,
        ];
        pcv_companion_write_record($store, $recordKey, $record);
        return ['operationId' => $stage['operationId'], 'state' => 'prepared', 'token' => $token, 'expiresAt' => $record['expiresAt']];
    });
}

function pcv_companion_status_record(string $directory, string $principalId, string $scopeHash, string $operationId, int $now): array
{
    $recordKey = pcv_companion_record_key($principalId, $scopeHash, $operationId);
    return pcv_companion_store_transaction($directory, static function (string $store) use ($recordKey, $operationId, $now): array {
        $record = pcv_companion_read_record($store, $recordKey, true);
        if ($record !== null && ($record['state'] ?? null) === 'prepared' && ($record['expiresAt'] ?? 0) <= $now) {
            $record['state'] = 'expired';
            $record['text'] = null;
            $record['token'] = null;
            $record['tokenHash'] = null;
            $record['terminalAt'] = $now;
            pcv_companion_write_record($store, $recordKey, $record);
        }
        $record = pcv_companion_compact_terminal_record($store, $recordKey, $record, $now);
        return pcv_companion_status_projection($record, $operationId);
    });
}

function pcv_companion_cancel_record(string $directory, string $principalId, string $scopeHash, string $operationId, int $now): array
{
    $recordKey = pcv_companion_record_key($principalId, $scopeHash, $operationId);
    return pcv_companion_store_transaction($directory, static function (string $store) use ($recordKey, $operationId, $now): array {
        $record = pcv_companion_read_record($store, $recordKey, true);
        if ($record !== null && ($record['state'] ?? null) === 'prepared') {
            $record['state'] = ($record['expiresAt'] ?? 0) <= $now ? 'expired' : 'cancelled';
            $record['text'] = null;
            $record['token'] = null;
            $record['tokenHash'] = null;
            $record['terminalAt'] = $now;
            pcv_companion_write_record($store, $recordKey, $record);
        }
        return pcv_companion_status_projection($record, $operationId);
    });
}

function pcv_companion_claim_token(string $directory, string $token, array $observedTarget, int $now): array
{
    if (preg_match('/\Apcv1_[A-Za-z0-9_-]{43}\z/D', $token) !== 1) {
        return ['state' => 'unknown'];
    }
    $tokenHash = hash('sha256', $token);
    return pcv_companion_store_transaction($directory, static function (string $store) use ($tokenHash, $token, $observedTarget, $now): array {
        $recordKey = null;
        foreach (new DirectoryIterator($store . DIRECTORY_SEPARATOR . 'prepared') as $item) {
            if (!$item->isFile() || $item->isLink() || !preg_match('/\A([a-f0-9]{64})\.json\z/D', $item->getFilename(), $matches)) {
                continue;
            }
            $candidateKey = $matches[1];
            $candidate = pcv_companion_read_record($store, $candidateKey, true);
            if (is_array($candidate) && is_string($candidate['tokenHash'] ?? null) && hash_equals($candidate['tokenHash'], $tokenHash)) {
                $recordKey = $candidateKey;
                break;
            }
        }
        if (!is_string($recordKey)) {
            return ['state' => 'unknown'];
        }
        $record = pcv_companion_read_record($store, $recordKey, true);
        if ($record === null) {
            return ['state' => 'unknown'];
        }
        if (($record['state'] ?? null) !== 'prepared') {
            return ['state' => ($record['state'] ?? null) === 'claimed' ? 'unknown' : ($record['state'] ?? 'unknown')];
        }
        if (($record['expiresAt'] ?? 0) <= $now) {
            $record['state'] = 'expired';
            $record['text'] = null;
            $record['token'] = null;
            $record['tokenHash'] = null;
            $record['terminalAt'] = $now;
            pcv_companion_write_record($store, $recordKey, $record);
            return ['state' => 'expired'];
        }
        $target = $record['target'] ?? null;
        if (!is_array($target)
            || !is_string($target['formId'] ?? null) || !is_string($observedTarget['formId'] ?? null)
            || !hash_equals($target['formId'], strtolower($observedTarget['formId']))
            || !is_string($target['name'] ?? null) || !is_string($observedTarget['name'] ?? null)
            || !hash_equals($target['name'], $observedTarget['name'])
            || !is_string($record['text'] ?? null)
            || !is_string($record['tokenHash'] ?? null)
            || !hash_equals($record['tokenHash'], $tokenHash)) {
            $record['state'] = 'rejected';
            $record['text'] = null;
            $record['token'] = null;
            $record['tokenHash'] = null;
            $record['terminalAt'] = $now;
            pcv_companion_write_record($store, $recordKey, $record);
            return ['state' => 'rejected'];
        }
        $text = $record['text'];
        $record['state'] = 'claimed';
        $record['text'] = null;
        $record['token'] = null;
        $record['tokenHash'] = null;
        $record['claimedAt'] = $now;
        pcv_companion_write_record($store, $recordKey, $record);
        return [
            'state' => 'claimed',
            'recordKey' => $recordKey,
            'principalId' => $record['principalId'] ?? null,
            'operationId' => $record['operationId'] ?? null,
            'ownerKey' => $record['ownerKey'] ?? null,
            'text' => $text,
            'target' => $target,
            'expectedActiveConfigId' => $record['expectedActiveConfigId'] ?? null,
            'expectedPendingConfigId' => $record['expectedPendingConfigId'] ?? null,
            'scopeHash' => $record['scopeHash'] ?? null,
            'context' => $record['context'] ?? null,
        ];
    });
}

/**
 * Inspect an opaque token without returning its literal or claiming it.
 * Only prepared payloads are searched; outstanding prepared and claimed records share a 64-operation limit.
 */
function pcv_companion_inspect_token(string $directory, string $token, int $now): array
{
    if (preg_match('/\Apcv1_[A-Za-z0-9_-]{43}\z/D', $token) !== 1) {
        return ['state' => 'unknown'];
    }
    $tokenHash = hash('sha256', $token);
    return pcv_companion_store_transaction($directory, static function (string $store) use ($tokenHash, $now): array {
        foreach (new DirectoryIterator($store . DIRECTORY_SEPARATOR . 'prepared') as $item) {
            if (!$item->isFile() || $item->isLink()
                || !preg_match('/\A([a-f0-9]{64})\.json\z/D', $item->getFilename(), $matches)) {
                continue;
            }
            $recordKey = $matches[1];
            $record = pcv_companion_read_record($store, $recordKey);
            if (!is_array($record) || !is_string($record['tokenHash'] ?? null)
                || !hash_equals($record['tokenHash'], $tokenHash)) {
                continue;
            }
            if (($record['state'] ?? null) !== 'prepared') {
                return ['state' => 'unknown'];
            }
            if (!is_int($record['expiresAt'] ?? null) || $record['expiresAt'] <= $now) {
                return [
                    'state' => 'expired',
                    'recordKey' => $recordKey,
                    'principalId' => $record['principalId'] ?? null,
                    'operationId' => $record['operationId'] ?? null,
                    'target' => $record['target'] ?? null,
                    'expectedActiveConfigId' => $record['expectedActiveConfigId'] ?? null,
                    'expectedPendingConfigId' => $record['expectedPendingConfigId'] ?? null,
                    'scopeHash' => $record['scopeHash'] ?? null,
                    'ownerKey' => $record['ownerKey'] ?? null,
                    'context' => $record['context'] ?? null,
                    'expiresAt' => $record['expiresAt'] ?? null,
                ];
            }
            foreach (['operationId', 'target', 'expectedActiveConfigId', 'expectedPendingConfigId', 'scopeHash', 'ownerKey', 'context'] as $field) {
                if (!array_key_exists($field, $record)) {
                    throw new RuntimeException('Private Conversation companion record is invalid.');
                }
            }
            return [
                'state' => 'prepared',
                'recordKey' => $recordKey,
                'principalId' => $record['principalId'],
                'operationId' => $record['operationId'],
                'target' => $record['target'],
                'expectedActiveConfigId' => $record['expectedActiveConfigId'],
                'expectedPendingConfigId' => $record['expectedPendingConfigId'],
                'scopeHash' => $record['scopeHash'],
                'ownerKey' => $record['ownerKey'],
                'context' => $record['context'],
                'expiresAt' => $record['expiresAt'],
            ];
        }
        return ['state' => 'unknown'];
    });
}

/** Read a still-prepared literal for pure owner preflight; the later claim is authoritative. */
function pcv_companion_preview_prepared_token(string $directory, string $recordKey, string $token, int $now): array
{
    if (preg_match('/\Apcv1_[A-Za-z0-9_-]{43}\z/D', $token) !== 1) {
        return ['state' => 'unknown'];
    }
    $tokenHash = hash('sha256', $token);
    return pcv_companion_store_transaction($directory, static function (string $store) use ($recordKey, $tokenHash, $now): array {
        $record = pcv_companion_read_record($store, $recordKey, true);
        if (!is_array($record) || ($record['state'] ?? null) !== 'prepared'
            || !is_string($record['tokenHash'] ?? null) || !hash_equals($record['tokenHash'], $tokenHash)) {
            return ['state' => 'unknown'];
        }
        if (!is_int($record['expiresAt'] ?? null) || $record['expiresAt'] <= $now) {
            return ['state' => 'expired'];
        }
        if (!is_string($record['text'] ?? null)) {
            throw new RuntimeException('Private Conversation companion literal is unavailable.');
        }
        return ['state' => 'prepared', 'text' => $record['text']];
    });
}

/** Retire a token after an authoritative preflight refusal; ambiguous claims stay unknown. */
function pcv_companion_reject_prepared_token(string $directory, string $recordKey, string $token, int $now): string
{
    $tokenHash = hash('sha256', $token);
    return pcv_companion_store_transaction($directory, static function (string $store) use ($recordKey, $tokenHash, $now): string {
        $record = pcv_companion_read_record($store, $recordKey, true);
        if (!is_array($record) || ($record['state'] ?? null) !== 'prepared'
            || !is_string($record['tokenHash'] ?? null) || !hash_equals($record['tokenHash'], $tokenHash)) {
            return 'unknown';
        }
        $record['state'] = ($record['expiresAt'] ?? 0) <= $now ? 'expired' : 'rejected';
        $record['text'] = null;
        $record['token'] = null;
        $record['tokenHash'] = null;
        $record['terminalAt'] = $now;
        pcv_companion_write_record($store, $recordKey, $record);
        return $record['state'];
    });
}

/** Retire a refused stock-route token under PCV -> pair -> context -> companion lock order. */
function pcv_companion_retire_route_token(array $operation, string $token, int $now): string
{
    $recordKey = $operation['recordKey'] ?? null;
    $ownerKey = $operation['ownerKey'] ?? null;
    if (!is_string($recordKey) || preg_match('/\A[a-f0-9]{64}\z/D', $recordKey) !== 1
        || !is_string($ownerKey) || preg_match('/\A[a-f0-9]{64}\z/D', $ownerKey) !== 1) {
        return 'unknown';
    }
    try {
        pcv_companion_load_owner_sources();
        $directory = pcv_state_directory(pcv_companion_state_directory());
        $ownerLock = pcv_lock_state($directory, false, LOCK_SH);
        if ($ownerLock === null) {
            return 'unknown';
        }
        try {
            $loaded = pcv_load_store($directory);
            if (($loaded['kind'] ?? null) !== 'ready' || !is_array($loaded['state'] ?? null)
                || !hash_equals($ownerKey, (string)($loaded['state']['key'] ?? ''))) {
                return 'unknown';
            }
            return pcv_companion_with_current_pair_context($operation, static function () use ($directory, $recordKey, $token, $now): string {
                return pcv_companion_reject_prepared_token($directory, $recordKey, $token, $now);
            });
        } finally {
            pcv_unlock_state($ownerLock);
        }
    } catch (Throwable) {
        return 'unknown';
    }
}

function pcv_companion_compact_terminal_record(string $store, string $recordKey, ?array $record, int $now): ?array
{
    if (!is_array($record)
        || !in_array($record['state'] ?? null, ['consumed', 'rejected', 'cancelled', 'expired'], true)
        || !is_int($record['terminalAt'] ?? null)
        || $record['terminalAt'] > $now - PCV_COMPANION_TERMINAL_DETAIL_TTL) {
        return $record;
    }
    $record = [
        'version' => PCV_COMPANION_STORE_VERSION,
        'recordKey' => $recordKey,
        'operationId' => $record['operationId'] ?? '',
        'payloadHash' => $record['payloadHash'] ?? '',
        'state' => 'unknown',
        'terminalAt' => $record['terminalAt'],
    ];
    pcv_companion_write_record($store, $recordKey, $record);
    return $record;
}

function pcv_companion_finish_claim(string $directory, string $recordKey, string $terminalState, int $now): void
{
    if (!in_array($terminalState, ['consumed', 'rejected'], true)) {
        throw new InvalidArgumentException('invalid_terminal_state');
    }
    pcv_companion_store_transaction($directory, static function (string $store) use ($recordKey, $terminalState, $now): bool {
        $record = pcv_companion_read_record($store, $recordKey, true);
        if (!is_array($record) || ($record['state'] ?? null) !== 'claimed') {
            throw new RuntimeException('Private Conversation companion claim is no longer current.');
        }
        $record['state'] = $terminalState;
        $record['terminalAt'] = $now;
        $record['text'] = null;
        $record['token'] = null;
        $record['tokenHash'] = null;
        pcv_companion_write_record($store, $recordKey, $record);
        return true;
    });
}
