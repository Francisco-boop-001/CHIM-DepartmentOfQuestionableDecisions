<?php
declare(strict_types=1);

const QD_PCV_EXPECTED_VERSION = '0.1.23';
const QD_PCV_CATALOG_MAX_ROWS = 4096;
const QD_PCV_EXPECTED_CORE_MAIN_SHA256 = '3619049e6d54493a77b19c09f8b70420e3c35427545a8bb6e95e93c6fe57ebac';
const QD_PCV_EXPECTED_CORE_MAIN_WINDOWS_CRLF_SHA256 = '9686a446bb4a2e2c02def2b59c87190734b30ba4382781bd71ea0df96baf6ff9';
const QD_PCV_EXPECTED_OWNER_SOURCE_HASHES = [
    'manifest.json' => 'f045b9b6bd3e13a84cdabbf400a73ef4581acd8fa440e1e84f51b1dd0cb5f84e',
    'state.php' => '3fbc48a0f25178011f0b838cc01d3664932822695d7d39c35d49cbc11fa057c1',
    'scope.php' => 'aa800deb4a34e0a77663e938b80a11d748978fc1b420a708c1c9125fce9bf398',
    'preprocessing.php' => '01134dea7cd1adc6a2467f26297f14d26e70398f50b1c1963ea02c9af1936950',
    'log.php' => '0a3a0684211bcb7e3e2a380ddf0d0173dfe838471b557d0b6ab1a1ec8a3fef56',
    'installation_guard.php' => '082cb9534bb3c8570faa384c5050d5193f2fbe19a58534381bb7f1b47e2a607d',
    'reflection.php' => 'd33a41331638be653d7c94119043672d7142e73ac752f8adb7b7cfc090670540',
];

/** QD_PCV_ROOT is an operator-owned path; requests never choose plugin paths. */
function qd_pcv_source_root(): string
{
    $configured = getenv('QD_PCV_ROOT');
    if (!is_string($configured) || $configured === '') {
        $configured = qd_runtime_extension_root('private_conversation');
    }
    $root = realpath($configured);
    if (!is_string($root) || !is_dir($root) || is_link($configured)) {
        throw new RuntimeException('The trusted PCV source path is unavailable.');
    }
    return $root;
}

function qd_pcv_load_owner_sources(): string
{
    static $loadedRoot = null;
    $root = qd_pcv_source_root();
    foreach (QD_PCV_EXPECTED_OWNER_SOURCE_HASHES as $relative => $expectedHash) {
        $path = $root . DIRECTORY_SEPARATOR . $relative;
        $actualHash = is_file($path) && !is_link($path) ? @hash_file('sha256', $path) : false;
        if (!is_string($actualHash) || !hash_equals($expectedHash, strtolower($actualHash))) {
            throw new RuntimeException('The pinned PCV source files are unavailable.');
        }
    }
    if (is_string($loadedRoot)) {
        if ($loadedRoot !== $root) {
            throw new RuntimeException('A different PCV source path is already loaded.');
        }
        return $root;
    }

    $manifestPath = $root . DIRECTORY_SEPARATOR . 'manifest.json';
    $statePath = $root . DIRECTORY_SEPARATOR . 'state.php';
    $scopePath = $root . DIRECTORY_SEPARATOR . 'scope.php';
    foreach ([$manifestPath, $statePath, $scopePath] as $path) {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('The pinned PCV source files are unavailable.');
        }
    }
    $manifestSize = @filesize($manifestPath);
    if (!is_int($manifestSize) || $manifestSize > 8192) {
        throw new RuntimeException('The PCV manifest is unavailable.');
    }
    $manifest = json_decode((string)@file_get_contents($manifestPath), true, 8);
    $version = is_array($manifest) ? ($manifest['version'] ?? null) : null;
    if (is_string($version) && str_starts_with($version, 'private_conversation-v')) {
        $version = substr($version, strlen('private_conversation-v'));
    }
    if ($version !== QD_PCV_EXPECTED_VERSION) {
        throw new RuntimeException('The installed PCV version is not the pinned compatibility target.');
    }

    require_once $statePath;
    require_once $scopePath;
    foreach (['pcv_identity_from_home_state', 'pcv_valid_key', 'pcv_load_store', 'pcv_visible_state', 'pcvScopeKnownNpcs', 'pcvScopeEligibleMapFromPresence'] as $function) {
        if (!function_exists($function)) {
            throw new RuntimeException('The pinned PCV helper contract is unavailable.');
        }
        $functionPath = realpath((new ReflectionFunction($function))->getFileName() ?: '');
        if ($functionPath !== realpath($statePath) && $functionPath !== realpath($scopePath)) {
            throw new RuntimeException('PCV helpers were loaded from an unexpected source path.');
        }
    }
    $loadedRoot = $root;
    return $root;
}

/** Return the canonical PCV owner key; never substitute the Department auth scope. */
function qd_pcv_owner_key_from_home_state(array $homeState): ?string
{
    qd_pcv_load_owner_sources();
    $identity = pcv_identity_from_home_state($homeState, true);
    $key = $identity['key'] ?? null;
    if (!is_string($key) || !pcv_valid_key($key)) {
        return null;
    }
    $activeId = filter_var($homeState['active_id'] ?? null, FILTER_VALIDATE_INT);
    $choices = array_values(array_filter($homeState['playthroughs'] ?? [], static fn($profile): bool => is_array($profile) && ($profile['active'] ?? null) === true));
    if (!is_int($activeId) || $activeId < 1 || count($choices) !== 1
        || filter_var($choices[0]['id'] ?? null, FILTER_VALIDATE_INT) !== $activeId
        || !is_string($choices[0]['character_id'] ?? null)
        || preg_match('/\A[a-f0-9]{32}\z/D', $choices[0]['character_id']) !== 1
        || !hash_equals(hash('sha256', $activeId . ':' . $choices[0]['character_id']), $key)) {
        return null;
    }
    return $key;
}

/** Read a source state store while using only an already existing lock file. */
function qd_pcv_read_state_directory(string $directory, string $key): array
{
    qd_pcv_load_owner_sources();
    if (!pcv_valid_key($key) || $directory === '' || str_contains($directory, "\0") || is_link($directory)) {
        return ['kind' => 'unavailable', 'reason' => 'unsafe_state_path'];
    }
    if (!file_exists($directory)) {
        return ['kind' => 'missing'];
    }
    if (!is_dir($directory)) {
        return ['kind' => 'unavailable', 'reason' => 'unsafe_state_path'];
    }
    $lockPath = $directory . DIRECTORY_SEPARATOR . 'state.lock';
    $statePath = $directory . DIRECTORY_SEPARATOR . 'state.json';
    if (is_link($lockPath) || is_link($statePath)) {
        return ['kind' => 'unavailable', 'reason' => 'unsafe_state_path'];
    }
    if (!file_exists($lockPath)) {
        return file_exists($statePath) ? ['kind' => 'unavailable', 'reason' => 'lock_missing'] : ['kind' => 'missing'];
    }
    if (!is_file($lockPath)) {
        return ['kind' => 'unavailable', 'reason' => 'unsafe_state_path'];
    }
    $handle = @fopen($lockPath, 'rb');
    if ($handle === false || !flock($handle, LOCK_SH)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return ['kind' => 'unavailable', 'reason' => 'lock_unavailable'];
    }
    try {
        return pcv_load_store($directory);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function qd_pcv_roster_projection(array $managedNames, string $nativeRevision, array $catalogRows, array $presence): array
{
    if (preg_match('/\A[0-9]{1,20}\z/D', $nativeRevision) !== 1 || count($managedNames) > 64) {
        throw new InvalidArgumentException('The native roster snapshot is invalid.');
    }
    $nativeByKey = [];
    foreach ($managedNames as $name) {
        if (!is_string($name) || trim($name) === '' || strlen($name) > 256 || preg_match('//u', $name) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $name) === 1) {
            throw new InvalidArgumentException('The native roster snapshot is invalid.');
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower(trim($name), 'UTF-8') : strtolower(trim($name));
        if (isset($nativeByKey[$key])) {
            throw new InvalidArgumentException('The native roster snapshot is ambiguous.');
        }
        $nativeByKey[$key] = $name;
    }

    $presenceStatus = $presence['status'] ?? 'unavailable';
    if (!in_array($presenceStatus, ['ready', 'empty', 'missing', 'stale', 'unavailable'], true)) {
        $presenceStatus = 'unavailable';
    }
    if ($presenceStatus !== 'ready') {
        return [
            'revision' => $nativeRevision,
            'state' => $presenceStatus,
            'actors' => [],
            'limited' => false,
        ];
    }
    if (!function_exists('pcvScopeKnownNpcs')) {
        throw new RuntimeException('PCV catalog identity checks are unavailable.');
    }
    $eligible = is_array($presence['known_npcs'] ?? null) ? $presence['known_npcs'] : [];
    $actors = [];
    foreach (pcvScopeKnownNpcs($catalogRows, is_string($presence['player_name'] ?? null) ? $presence['player_name'] : null) as $id => $name) {
        $nameKey = function_exists('mb_strtolower') ? mb_strtolower(trim($name), 'UTF-8') : strtolower(trim($name));
        if (!isset($nativeByKey[$nameKey])) {
            continue;
        }
        $isEligible = isset($eligible[(string)$id]) && $eligible[(string)$id] === $name;
        $actors[] = [
            'actorKey' => 'npc:' . $id,
            'label' => $nativeByKey[$nameKey],
            'eligibility' => $isEligible ? 'eligible' : 'ineligible',
            'reason' => $isEligible ? null : 'not_fresh',
        ];
    }
    return [
        'revision' => $nativeRevision,
        'state' => $actors === [] ? 'empty' : 'ready',
        'actors' => $actors,
        'limited' => false,
    ];
}

function qd_pcv_private_state_directory(): ?string
{
    qd_pcv_load_owner_sources();
    $privateRoot = pcv_log_default_directory(false);
    if (!is_string($privateRoot) || $privateRoot === '' || is_link($privateRoot)) {
        return null;
    }
    $directory = $privateRoot . DIRECTORY_SEPARATOR . 'state';
    if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
        return null;
    }
    return $directory;
}

function qd_pcv_companion_require_store(): void
{
    $sourcePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR . 'companion.php';
    $installedPath = qd_runtime_core_root() . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR
        . 'pcv_companion' . DIRECTORY_SEPARATOR . 'companion.php';
    $path = is_file($sourcePath) ? $sourcePath : $installedPath;
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Private Conversation companion operations are unavailable.');
    }
    require_once $path;
    if (!function_exists('pcv_companion_stage_record') || !function_exists('pcv_companion_status_record')
        || !function_exists('pcv_companion_cancel_record') || !function_exists('pcv_companion_owner_target_is_member')) {
        throw new RuntimeException('Private Conversation companion operations are unavailable.');
    }
}

/** Hold locks in owner state -> facade context -> companion-store order. */
function qd_pcv_companion_with_owner_state(string $key, callable $callback): mixed
{
    qd_pcv_load_owner_sources();
    qd_pcv_companion_require_store();
    if (!pcv_valid_key($key)) {
        throw new QDServerFault('unavailable');
    }
    $directory = qd_pcv_private_state_directory();
    if ($directory === null) {
        throw new QDServerFault('unavailable');
    }
    $directory = pcv_state_directory($directory);
    $handle = pcv_lock_state($directory, true, LOCK_SH);
    if ($handle === null) {
        throw new QDServerFault('unavailable');
    }
    try {
        $loaded = pcv_load_store($directory);
        if (($loaded['kind'] ?? null) === 'unavailable') {
            throw new QDServerFault('unavailable');
        }
        if (($loaded['kind'] ?? null) === 'ready') {
            $state = $loaded['state'];
            if (!is_array($state) || !is_string($state['key'] ?? null) || !hash_equals($key, $state['key'])) {
                throw new QDServerFault('stale_context');
            }
        } elseif (($loaded['kind'] ?? null) === 'missing') {
            $state = pcv_empty_store($key);
        } else {
            throw new QDServerFault('unavailable');
        }
        $visible = pcv_visible_state($state, time());
        return $callback($directory, $state, $visible);
    } catch (QDServerFault $error) {
        throw $error;
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    } finally {
        pcv_unlock_state($handle);
    }
}

function qd_pcv_companion_visible_config(array $visible, array $state, array $known): ?array
{
    $pending = $visible['pending_scope'] ?? null;
    $config = is_array($pending) && ($pending['enabled'] ?? null) === true
        ? ($state['pending']['config'] ?? null)
        : ($state['active']['config'] ?? null);
    if (!is_array($config) || ($config['enabled'] ?? null) !== true) {
        return null;
    }
    try {
        $projected = qd_pcv_project_config($config, $known);
    } catch (Throwable) {
        return null;
    }
    return is_array($projected) ? $projected : null;
}

function qd_pcv_companion_target_is_member(array $configuration, string $targetName, array $known): bool
{
    qd_pcv_companion_require_store();
    return pcv_companion_owner_target_is_member($configuration, $targetName, $known);
}

function qd_pcv_companion_stage(stdClass $payload, array $context): array
{
    $assertCurrent = $context['assertCurrent'] ?? null;
    $withLockedCurrentContext = $context['withLockedCurrentContext'] ?? null;
    $scopeHash = $context['scopeHash'] ?? null;
    if (!is_callable($assertCurrent) || !is_callable($withLockedCurrentContext)
        || !is_string($scopeHash) || preg_match('/\A[a-f0-9]{64}\z/D', $scopeHash) !== 1) {
        throw new QDServerFault('unavailable');
    }
    try {
        $identity = qd_pcv_current_identity_and_catalog(true);
        $known = pcvScopeKnownNpcs($identity['catalogRows'], $identity['playerName']);
        $assertCurrent();
        return qd_pcv_companion_with_owner_state($identity['pcvKey'], function (string $directory, array $state, array $visible) use (
            $payload, $context, $scopeHash, $withLockedCurrentContext, $identity, $known
        ): array {
            if (($visible['config_id'] ?? null) !== $payload->expectedActiveConfigId
                || ($visible['pending_config_id'] ?? null) !== $payload->expectedPendingConfigId) {
                throw new QDServerFault('stale_revision');
            }
            $configuration = qd_pcv_companion_visible_config($visible, $state, $known);
            if ($configuration === null || !qd_pcv_companion_target_is_member($configuration, $payload->target->name, $known)) {
                throw new QDServerFault('conflict');
            }
            return $withLockedCurrentContext(static function () use ($payload, $context, $scopeHash, $identity, $directory): array {
                try {
                    return pcv_companion_stage_record($directory, [
                        'operationId' => $payload->operationId,
                        'principalId' => $context['principalId'],
                        'scopeHash' => $scopeHash,
                        'ownerKey' => $identity['pcvKey'],
                        'text' => $payload->text,
                        'target' => ['formId' => strtolower($payload->target->formId), 'name' => $payload->target->name],
                        'expectedActiveConfigId' => $payload->expectedActiveConfigId,
                        'expectedPendingConfigId' => $payload->expectedPendingConfigId,
                        'context' => [
                            'serverContext' => $context['serverContext'],
                            'viewToken' => $context['viewToken'],
                            'nativeGeneration' => $context['nativeGeneration'],
                        ],
                    ], time());
                } catch (InvalidArgumentException) {
                    throw new QDServerFault('invalid_request');
                } catch (DomainException $error) {
                    throw new QDServerFault($error->getMessage() === 'conflict' ? 'conflict' : 'unavailable');
                } catch (Throwable) {
                    throw new QDServerFault('unavailable');
                }
            });
        });
    } catch (QDServerFault $error) {
        throw $error;
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
}

function qd_pcv_companion_status(stdClass $payload, array $context): array
{
    return qd_pcv_companion_operation_status($payload, $context, false);
}

function qd_pcv_companion_cancel(stdClass $payload, array $context): array
{
    return qd_pcv_companion_operation_status($payload, $context, true);
}

function qd_pcv_companion_operation_status(stdClass $payload, array $context, bool $cancel): array
{
    $withLockedCurrentContext = $context['withLockedCurrentContext'] ?? null;
    $scopeHash = $context['scopeHash'] ?? null;
    $principalId = $context['principalId'] ?? null;
    if (!is_callable($withLockedCurrentContext) || !is_string($scopeHash) || !is_string($principalId)) {
        throw new QDServerFault('unavailable');
    }
    try {
        $identity = qd_pcv_current_identity_and_catalog(false);
        return qd_pcv_companion_with_owner_state($identity['pcvKey'], static function (string $directory) use (
            $payload, $scopeHash, $principalId, $withLockedCurrentContext, $context, $cancel
        ): array {
            return $withLockedCurrentContext(static function () use ($directory, $payload, $scopeHash, $principalId, $cancel): array {
                return $cancel
                    ? pcv_companion_cancel_record($directory, $principalId, $scopeHash, $payload->operationId, time())
                    : pcv_companion_status_record($directory, $principalId, $scopeHash, $payload->operationId, time());
            });
        });
    } catch (QDServerFault $error) {
        throw $error;
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
}

/** Accept the pinned upstream LF blob, or its exact known Windows CRLF checkout form. */
function qd_pcv_stock_main_matches_pin(string $mainPath): bool
{
    $rawHash = @hash_file('sha256', $mainPath);
    if (!is_string($rawHash)) {
        return false;
    }
    $rawHash = strtolower($rawHash);
    if (hash_equals(QD_PCV_EXPECTED_CORE_MAIN_SHA256, $rawHash)) {
        return true;
    }
    if (!hash_equals(QD_PCV_EXPECTED_CORE_MAIN_WINDOWS_CRLF_SHA256, $rawHash)) {
        return false;
    }
    $source = @file_get_contents($mainPath);
    if (!is_string($source)) {
        return false;
    }
    $lf = str_replace("\r\n", "\n", $source);
    return !str_contains($lf, "\r")
        && hash_equals(QD_PCV_EXPECTED_CORE_MAIN_SHA256, hash('sha256', $lf));
}

/** Require stock CHIM main plus the extension-owned hooks; no core ingress patch is accepted. */
function qd_pcv_core_guard_ready(): bool
{
    try {
        $coreRoot = qd_runtime_core_root();
        $mainPath = $coreRoot . DIRECTORY_SEPARATOR . 'main.php';
        $hookRoot = $coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'pcv_companion';
        if (!is_file($mainPath) || is_link($mainPath)
            || !qd_pcv_stock_main_matches_pin($mainPath)
            || !is_dir($hookRoot) || is_link($hookRoot)) {
            return false;
        }
        foreach (['companion.php', 'globals.php', 'preprocessing.php'] as $name) {
            $path = $hookRoot . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path) || is_link($path)) {
                return false;
            }
        }
        return true;
    } catch (Throwable) {
        return false;
    }
}

function qd_pcv_runtime_ready(): bool
{
    try {
        $coreRoot = qd_runtime_core_root();
        $root = qd_pcv_source_root();
        $installedRoot = realpath($coreRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation');
        if (!is_string($installedRoot) || $root !== $installedRoot) {
            return false;
        }
        qd_pcv_load_owner_sources();
        return qd_pcv_core_guard_ready();
    } catch (Throwable) {
        return false;
    }
}

function qd_pcv_status_projection(array $loaded, string $key, array $knownNpcs): array
{
    if (($loaded['kind'] ?? null) === 'missing') {
        $visible = ['status' => 'off', 'scope' => null, 'pending_scope' => null, 'config_id' => null, 'pending_config_id' => null];
    } elseif (($loaded['kind'] ?? null) !== 'ready' || !is_array($loaded['state'] ?? null)) {
        return ['state' => 'unavailable', 'active' => null, 'pending' => null, 'activeConfigId' => null, 'pendingConfigId' => null];
    } elseif (!hash_equals($loaded['state']['key'] ?? '', $key)) {
        $visible = ['status' => 'off', 'scope' => null, 'pending_scope' => null, 'config_id' => null, 'pending_config_id' => null];
    } else {
        $visible = pcv_visible_state($loaded['state'], time());
    }
    try {
        $active = qd_pcv_project_config(is_array($visible['scope'] ?? null) ? $visible['scope'] : null, $knownNpcs);
        $pending = qd_pcv_project_config(is_array($visible['pending_scope'] ?? null) ? $visible['pending_scope'] : null, $knownNpcs);
    } catch (Throwable) {
        return ['state' => 'unavailable', 'active' => null, 'pending' => null, 'activeConfigId' => null, 'pendingConfigId' => null];
    }
    $state = $active !== null ? 'active' : ($pending !== null ? 'pending' : 'off');
    return [
        'state' => $state,
        'active' => $active,
        'pending' => $pending,
        'activeConfigId' => is_string($visible['config_id'] ?? null) ? $visible['config_id'] : null,
        'pendingConfigId' => is_string($visible['pending_config_id'] ?? null) ? $visible['pending_config_id'] : null,
    ];
}

function qd_pcv_status_read(stdClass $payload, array $context): array
{
    $assertCurrent = $context['assertCurrent'] ?? null;
    if (!is_callable($assertCurrent)) {
        throw new QDServerFault('unavailable');
    }
    $assertCurrent();
    qd_pcv_load_owner_sources();
    $identity = qd_pcv_current_identity_and_catalog(true);
    $known = pcvScopeKnownNpcs($identity['catalogRows'], $identity['playerName']);
    $directory = qd_pcv_private_state_directory();
    $loaded = $directory === null ? ['kind' => 'unavailable'] : qd_pcv_read_state_directory($directory, $identity['pcvKey']);
    return qd_pcv_status_projection($loaded, $identity['pcvKey'], $known);
}

function qd_pcv_roster_read(stdClass $payload, array $context): array
{
    $assertCurrent = $context['assertCurrent'] ?? null;
    if (!is_callable($assertCurrent)) {
        throw new QDServerFault('unavailable');
    }
    if (array_diff(array_keys(get_object_vars($payload)), ['managedNames', 'nativeRosterRevision']) !== []
        || !is_array($payload->managedNames ?? null) || !array_is_list($payload->managedNames)
        || !is_string($payload->nativeRosterRevision ?? null)) {
        throw new QDServerFault('invalid_request');
    }
    $assertCurrent();
    qd_pcv_load_owner_sources();
    $snapshot = qd_pcv_current_identity_and_catalog(true);
    $directory = qd_pcv_private_state_directory();
    if ($directory === null) {
        return ['revision' => $payload->nativeRosterRevision, 'state' => 'unavailable', 'actors' => [], 'limited' => false];
    }
    $presence = qd_pcv_read_presence_directory($directory, $snapshot['pcvKey'], $snapshot['catalogRows'], $snapshot['playerName']);
    return qd_pcv_roster_projection($payload->managedNames, $payload->nativeRosterRevision, $snapshot['catalogRows'], $presence);
}

function qd_pcv_arm(stdClass $payload, array $context): array
{
    return qd_pcv_mutate_configuration($payload, $context, false);
}

function qd_pcv_end(stdClass $payload, array $context): array
{
    return qd_pcv_mutate_configuration($payload, $context, true);
}

function qd_pcv_mutate_configuration(stdClass $payload, array $context, bool $end): array
{
    $withLockedCurrentContext = $context['withLockedCurrentContext'] ?? null;
    if (!is_callable($withLockedCurrentContext)) {
        throw new QDServerFault('unavailable');
    }
    qd_pcv_load_owner_sources();
    $operationId = $payload->operationId ?? null;
    $expectedActive = $payload->expectedActiveConfigId ?? null;
    $expectedPending = $payload->expectedPendingConfigId ?? null;
    if (!is_string($operationId) || !pcv_log_valid_uuid($operationId)
        || !pcv_valid_config_id($expectedActive) || !pcv_valid_config_id($expectedPending)) {
        throw new QDServerFault('invalid_request');
    }
    $allowed = $end
        ? ['operationId', 'expectedActiveConfigId', 'expectedPendingConfigId']
        : ['operationId', 'expectedActiveConfigId', 'expectedPendingConfigId', 'configuration'];
    if (array_diff(array_keys(get_object_vars($payload)), $allowed) !== []
        || (!$end && !is_object($payload->configuration ?? null))) {
        throw new QDServerFault('invalid_request');
    }
    $assertCurrent = $context['assertCurrent'] ?? null;
    if (!is_callable($assertCurrent)) {
        throw new QDServerFault('unavailable');
    }
    $beginCommit = $context['beginCommit'] ?? null;
    if (!is_callable($beginCommit)) {
        throw new QDServerFault('unavailable');
    }
    $assertCurrent();
    $snapshot = qd_pcv_current_identity_and_catalog(!$end);
    $known = $end ? [] : pcvScopeKnownNpcs($snapshot['catalogRows'], $snapshot['playerName']);
    if ($end) {
        // END depends on the canonical owner identity only; roster/presence failures must not block it.
        $eligible = [];
    } else {
        $presence = qd_pcv_read_presence_directory(qd_pcv_private_state_directory() ?? '', $snapshot['pcvKey'], $snapshot['catalogRows'], $snapshot['playerName']);
        $eligible = ($presence['status'] ?? null) === 'ready' && is_array($presence['known_npcs'] ?? null) ? $presence['known_npcs'] : [];
    }
    $config = $end ? ['enabled' => false] : qd_pcv_normalize_ui_configuration($payload->configuration, $known, $eligible);
    $companionPrecommit = static function (array $before, array $after, array $metadata, callable $commit) use (
        $expectedActive, $expectedPending, $config, $end, $withLockedCurrentContext, $beginCommit
    ): array {
        $beforeVisible = pcv_visible_state($before, is_int($metadata['now'] ?? null) ? $metadata['now'] : time());
        if (($beforeVisible['config_id'] ?? null) !== $expectedActive
            || ($beforeVisible['pending_config_id'] ?? null) !== $expectedPending) {
            return ['status' => 'refused', 'outcome' => 'known_no_write', 'reason' => 'stale_revision'];
        }
        $afterVisible = pcv_visible_state($after, is_int($metadata['now'] ?? null) ? $metadata['now'] : time());
        if ($end) {
            if (($afterVisible['status'] ?? null) !== 'off' || ($afterVisible['pending'] ?? false) !== false) {
                return ['status' => 'refused', 'outcome' => 'known_no_write', 'reason' => 'conflict'];
            }
        } elseif (($afterVisible['pending_scope']['enabled'] ?? null) !== true
            || !is_string($afterVisible['pending_config_id'] ?? null)
            || ($after['pending']['config'] ?? null) !== $config
            || ($afterVisible['config_id'] ?? null) !== $expectedActive) {
            return ['status' => 'refused', 'outcome' => 'known_no_write', 'reason' => 'conflict'];
        }
        $commitAttempted = false;
        try {
            $withLockedCurrentContext(static function () use ($commit, &$commitAttempted, $beginCommit): array {
                $beginCommit();
                $commitAttempted = true;
                $commit();
                return ['status' => 'committed'];
            });
            return ['status' => 'committed'];
        } catch (QDServerFault $error) {
            return $commitAttempted
                ? ['status' => 'unknown', 'outcome' => 'uncertain']
                : ['status' => 'refused', 'outcome' => 'known_no_write', 'reason' => $error->wireCode];
        } catch (Throwable) {
            return $commitAttempted
                ? ['status' => 'unknown', 'outcome' => 'uncertain']
                : ['status' => 'refused', 'outcome' => 'known_no_write', 'reason' => 'unavailable'];
        }
    };
    try {
        $result = pcv_stage($snapshot['pcvKey'], $config, $eligible, null, null, $companionPrecommit);
    } catch (QDServerFault $error) {
        throw $error;
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
    $companionOutcome = $result['_companionOutcome'] ?? null;
    $outcome = $result['_qd_outcome'] ?? $result['_companionReason'] ?? 'unavailable';
    unset($result['_qd_outcome'], $result['_companionOutcome'], $result['_companionReason']);
    if ($outcome === 'stale_revision') {
        return ['operationId' => $operationId, 'state' => 'rejected'];
    }
    if ($outcome === 'stale_context') {
        throw new QDServerFault('stale_context');
    }
    if ($outcome === 'conflict') {
        throw new QDServerFault('conflict');
    }
    if ($outcome === 'invalid_request') {
        throw new QDServerFault('invalid_request');
    }
    if ($outcome === 'replay' || $companionOutcome === 'uncertain') {
        return ['operationId' => $operationId, 'state' => 'unknown'];
    }
    if ($companionOutcome !== null && $companionOutcome !== 'owner_commit') {
        return ['operationId' => $operationId, 'state' => 'unknown'];
    }
    $acceptedStatuses = $end ? ['off'] : ['pending', 'active'];
    if (!is_array($result) || !in_array($result['status'] ?? null, $acceptedStatuses, true)) {
        return ['operationId' => $operationId, 'state' => 'unknown'];
    }
    $directory = qd_pcv_private_state_directory();
    $loaded = $directory === null ? ['kind' => 'unavailable'] : qd_pcv_read_state_directory($directory, $snapshot['pcvKey']);
    $readback = qd_pcv_status_projection($loaded, $snapshot['pcvKey'], $known);
    if (($readback['state'] ?? null) === 'unavailable') {
        return ['operationId' => $operationId, 'state' => 'unknown'];
    }
    return [
        'operationId' => $operationId,
        'state' => $end ? 'ended' : 'staged',
        'activeConfigId' => $readback['activeConfigId'],
        'pendingConfigId' => $readback['pendingConfigId'],
        'active' => $readback['active'],
        'pending' => $readback['pending'],
    ];
}

/** Convert the owner's numeric IDs and optional fields into the v1 public UI shape. */
function qd_pcv_project_config(?array $source, array $knownNpcs): ?array
{
    if ($source === null) {
        return null;
    }
    $allowed = ['enabled', 'scene_mode', 'actor_a', 'actor_b', 'actor_ids', 'exclude_player', 'bystander_mode', 'free', 'free_cap', 'opener', 'card', 'pace', 'actions'];
    if (array_diff(array_keys($source), $allowed) !== []
        || !is_bool($source['enabled'] ?? null)
        || !in_array($source['scene_mode'] ?? 'pair', ['pair', 'solo'], true)
        || !is_bool($source['exclude_player'] ?? null)
        || !in_array($source['bystander_mode'] ?? null, ['exclude', 'silent'], true)) {
        throw new InvalidArgumentException('The stored PCV configuration is unavailable.');
    }
    if (!$source['enabled']) {
        return ['enabled' => false, 'scene_mode' => 'pair', 'exclude_player' => true, 'bystander_mode' => 'exclude'];
    }

    $mapId = static function (mixed $rawId) use ($knownNpcs): string {
        if ((!is_string($rawId) && !is_int($rawId)) || preg_match('/\A[1-9][0-9]{0,18}\z/D', (string)$rawId) !== 1
            || !array_key_exists((string)$rawId, $knownNpcs)) {
            throw new InvalidArgumentException('The stored PCV actor is no longer in the current catalog.');
        }
        return 'npc:' . (string)$rawId;
    };
    $result = [
        'enabled' => true,
        'scene_mode' => $source['scene_mode'] ?? 'pair',
        'exclude_player' => $source['exclude_player'],
        'bystander_mode' => $source['bystander_mode'],
    ];
    foreach (['card', 'pace', 'actions'] as $field) {
        if (array_key_exists($field, $source)) {
            $result[$field] = $source[$field];
        }
    }
    if (($source['free'] ?? false) === true) {
        if (($source['scene_mode'] ?? 'pair') !== 'pair' || $source['exclude_player'] !== true
            || array_key_exists('actor_a', $source) || array_key_exists('actor_b', $source)
            || array_key_exists('actor_ids', $source) || array_key_exists('opener', $source)) {
            throw new InvalidArgumentException('The stored free PCV configuration is invalid.');
        }
        $result['free'] = true;
        $result['free_cap'] = $source['free_cap'] ?? PCV_FREE_MAX_MEMBERS;
        return $result;
    }
    if (array_key_exists('free', $source) && $source['free'] !== false) {
        throw new InvalidArgumentException('The stored PCV configuration is invalid.');
    }

    if (($source['scene_mode'] ?? 'pair') === 'solo') {
        if ($source['exclude_player'] !== true || array_key_exists('actor_ids', $source) || array_key_exists('opener', $source)) {
            throw new InvalidArgumentException('The stored solo PCV configuration is invalid.');
        }
        $result['actor_a'] = $mapId($source['actor_a'] ?? null);
        return $result;
    }
    if (array_key_exists('actor_ids', $source)) {
        $ids = $source['actor_ids'];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) < 2 || count($ids) > PCV_GROUP_MAX_MEMBERS) {
            throw new InvalidArgumentException('The stored PCV group is invalid.');
        }
        $result['actor_ids'] = array_map($mapId, $ids);
        $opener = $source['opener'] ?? 'auto';
        $result['opener'] = $opener === 'auto' ? 'auto' : $mapId($opener);
        return $result;
    }
    if (array_key_exists('opener', $source)) {
        throw new InvalidArgumentException('The stored PCV pair is invalid.');
    }
    $result['actor_a'] = $mapId($source['actor_a'] ?? null);
    $result['actor_b'] = $mapId($source['actor_b'] ?? null);
    return $result;
}

function qd_pcv_normalize_ui_configuration(stdClass $configuration, array $known, array $eligible): array
{
    $allowed = ['enabled', 'scene_mode', 'actor_a', 'actor_b', 'actor_ids', 'exclude_player', 'bystander_mode', 'free', 'free_cap', 'opener', 'card', 'pace', 'actions'];
    if (array_diff(array_keys(get_object_vars($configuration)), $allowed) !== []) {
        throw new QDServerFault('invalid_request');
    }
    $mapActor = static function (mixed $key) use ($known, $eligible): string {
        if (!is_string($key) || preg_match('/\Anpc:([1-9][0-9]{0,18})\z/D', $key, $matches) !== 1) {
            throw new QDServerFault('invalid_request');
        }
        $id = $matches[1];
        if (!isset($known[$id]) || !isset($eligible[$id])) {
            throw new QDServerFault('stale_revision');
        }
        return $id;
    };
    $normalized = get_object_vars($configuration);
    foreach (['actor_a', 'actor_b', 'opener'] as $field) {
        if (array_key_exists($field, $normalized) && is_string($normalized[$field]) && $normalized[$field] !== 'auto') {
            $normalized[$field] = $mapActor($normalized[$field]);
        }
    }
    if (isset($normalized['actor_ids'])) {
        if (!is_array($normalized['actor_ids']) || !array_is_list($normalized['actor_ids']) || count($normalized['actor_ids']) > 6) {
            throw new QDServerFault('invalid_request');
        }
        $normalized['actor_ids'] = array_map($mapActor, $normalized['actor_ids']);
    }
    try {
        return pcv_normalize_config($normalized, $eligible);
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
}

function qd_pcv_current_identity_and_catalog(bool $includeCatalog): array
{
    qd_pcv_load_owner_sources();
    return qd_runtime_with_core_db(static function ($connection) use ($includeCatalog): array {
        $ownsTransaction = pg_transaction_status($connection) === PGSQL_TRANSACTION_IDLE;
        if ($ownsTransaction && pg_query($connection, 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY') === false) {
            throw new RuntimeException('The active CHIM identity is unavailable.');
        }
        try {
            qd_runtime_apply_profile_read_bounds($connection);
            $homeState = pth_state($connection);
            $active = qd_runtime_active_playthrough($connection);
            $identity = pcv_identity_from_home_state($homeState, true);
            $key = qd_pcv_owner_key_from_home_state($homeState);
            $playerName = $identity['player_name'] ?? null;
            if (!is_string($key) || !is_string($playerName) || trim($playerName) === ''
                || $active['profileId'] !== filter_var($homeState['active_id'] ?? null, FILTER_VALIDATE_INT)
                || !hash_equals(hash('sha256', $active['profileId'] . ':' . $active['characterId']), $key)) {
                throw new RuntimeException('The active CHIM character identity is unavailable.');
            }
            $rows = $includeCatalog ? qd_pcv_read_catalog_rows($connection) : [];
            if ($ownsTransaction && pg_query($connection, 'COMMIT') === false) {
                throw new RuntimeException('The active CHIM identity could not be verified.');
            }
            return ['profileId' => $active['profileId'], 'scopeKey' => $active['scopeKey'], 'pcvKey' => $key, 'playerName' => $playerName, 'catalogRows' => $rows];
        } catch (Throwable $error) {
            if ($ownsTransaction && pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
                @pg_query($connection, 'ROLLBACK');
            }
            throw $error;
        }
    });
}

function qd_pcv_read_catalog_rows(mixed $connection): array
{
    $result = @pg_query($connection,
        "SELECT id::text AS id, npc_name, profile_id::text AS profile_id, "
        . "CASE WHEN jsonb_typeof(metadata::jsonb -> 'activity_status') = 'object' "
        . "THEN jsonb_build_object('activity_status', jsonb_build_object('timestamp', metadata::jsonb -> 'activity_status' -> 'timestamp')) "
        . "WHEN jsonb_typeof(metadata::jsonb -> 'activity_status') = 'array' "
        . "THEN jsonb_build_object('activity_status', '[]'::jsonb) "
        . "ELSE jsonb_build_object('activity_status_timestamp', metadata::jsonb -> 'activity_status_timestamp') END::text AS metadata "
        . 'FROM public.core_npc_master WHERE profile_id IS NOT NULL ORDER BY id LIMIT ' . (QD_PCV_CATALOG_MAX_ROWS + 1));
    if ($result === false) {
        throw new RuntimeException('The current CHIM NPC catalog is unavailable.');
    }
    try {
        $rows = pg_fetch_all($result);
    } finally {
        @pg_free_result($result);
    }
    $rows = is_array($rows) ? $rows : [];
    if (count($rows) > QD_PCV_CATALOG_MAX_ROWS) {
        throw new RuntimeException('The current CHIM NPC catalog exceeds the safe read bound.');
    }
    return $rows;
}

function qd_pcv_read_presence_directory(string $directory, string $key, array $catalogRows, string $playerName): array
{
    qd_pcv_load_owner_sources();
    if ($directory === '' || !is_dir($directory) || is_link($directory) || !is_string($playerName)) {
        return ['status' => 'missing', 'known_npcs' => [], 'player_name' => $playerName];
    }
    $lockPath = $directory . DIRECTORY_SEPARATOR . 'state.lock';
    if (!is_file($lockPath) || is_link($lockPath)) {
        return ['status' => 'unavailable', 'known_npcs' => [], 'player_name' => $playerName];
    }
    try {
        $presence = pcv_read_eligible_npcs($key, $catalogRows, $playerName, $directory);
    } catch (Throwable) {
        return ['status' => 'unavailable', 'known_npcs' => [], 'player_name' => $playerName];
    }
    if (!is_array($presence) || !in_array($presence['status'] ?? null,
        ['ready', 'empty', 'missing', 'stale', 'unavailable'], true)
        || !is_array($presence['known_npcs'] ?? null)) {
        return ['status' => 'unavailable', 'known_npcs' => [], 'player_name' => $playerName];
    }
    return [
        'status' => $presence['status'],
        'known_npcs' => $presence['known_npcs'],
        'player_name' => $playerName,
    ];
}
