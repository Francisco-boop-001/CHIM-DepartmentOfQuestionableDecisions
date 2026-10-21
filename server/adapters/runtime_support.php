<?php
declare(strict_types=1);

const QD_RUNTIME_EXPECTED_PLAYTHROUGH_PREFERENCES_SHA256 = 'fc9562fad12b867a5593d06bc0b0b8167120b0270088c343ae3db074e4b3f475';
const QD_RUNTIME_EXPECTED_PLAYTHROUGH_PREFERENCES_WINDOWS_CRLF_SHA256 = '02ec005e7109af689056783bf54d9b3080fcfa16194c5dfda0526c5add4eaf41';
const QD_RUNTIME_EXPECTED_PLAYTHROUGH_HOME_SHA256 = 'e28ced10ee4410f0926f9366fca7bb80199b2f423c80afb1c960d074564b88b6';
const QD_RUNTIME_EXPECTED_PLAYTHROUGH_HOME_WINDOWS_CRLF_SHA256 = 'ac55d75bea4ab30828390cba4bfc50ecb3db91e43c72080705c00abbac1ef18b';
const QD_RUNTIME_EXPECTED_PLAYTHROUGH_RUNTIME_SHA256 = 'd480af9d658c8e754b78d1e4ca6056544d21942facbafd94b23ab1da4cb1cd9c';
const QD_RUNTIME_EXPECTED_PLAYTHROUGH_RUNTIME_WINDOWS_CRLF_SHA256 = 'ec29467c61873f255d3bc13962466ab04f12b3bc2bd6d29276e53878088a1d7e';

/**
 * Small source-backed CHIM runtime helpers. Nothing in this file opens a
 * connection or reads engine files until an authenticated operation calls it.
 */

function qd_runtime_with_owned_connection(callable $connect, callable $close, callable $callback): mixed
{
    $connection = $connect();
    if ($connection === false || $connection === null) {
        throw new RuntimeException('The active CHIM profile is unavailable.');
    }
    try {
        return $callback($connection);
    } finally {
        $close($connection);
    }
}

function qd_runtime_scope_key_from_home_state(array $homeState, string $schemaName): string
{
    if (($homeState['available'] ?? null) !== true || !is_array($homeState['playthroughs'] ?? null)) {
        throw new InvalidArgumentException('The active CHIM profile is unavailable.');
    }
    if (($homeState['auto_switch_pending'] ?? false) === true) {
        throw new InvalidArgumentException('A CHIM profile switch is still pending.');
    }
    $activeId = filter_var($homeState['active_id'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($activeId) || $activeId < 1) {
        throw new InvalidArgumentException('There is no active CHIM profile.');
    }

    $matches = [];
    foreach ($homeState['playthroughs'] as $profile) {
        if (!is_array($profile) || !is_bool($profile['active'] ?? null)) {
            throw new InvalidArgumentException('The active CHIM profile is ambiguous.');
        }
        if ($profile['active']) {
            $matches[] = $profile;
        }
    }
    if (count($matches) !== 1 || filter_var($matches[0]['id'] ?? null, FILTER_VALIDATE_INT) !== $activeId) {
        throw new InvalidArgumentException('The active CHIM profile is ambiguous.');
    }

    $profile = $matches[0];
    $characterId = $profile['character_id'] ?? null;
    if (preg_match('/\Achim_profile_[a-z0-9_]{1,50}\z/D', $schemaName) !== 1
        || ($profile['available'] ?? null) !== true
        || !is_string($characterId) || preg_match('/\A[a-f0-9]{32}\z/D', $characterId) !== 1) {
        throw new InvalidArgumentException('The active CHIM character identity is unavailable.');
    }

    return hash('sha256', "qd-department-scope-v1\0" . $activeId . "\0" . $schemaName . "\0" . $characterId);
}

function qd_runtime_engine_root(): string
{
    $configured = getenv('QD_CHIM_ROOT');
    if (!is_string($configured) || $configured === '' || str_contains($configured, "\0")) {
        throw new RuntimeException('The trusted CHIM source root is not configured.');
    }
    $root = realpath($configured);
    if (!is_string($root) || !is_dir($root) || !is_file($root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_preferences.php')) {
        throw new RuntimeException('The trusted CHIM source root is unavailable.');
    }
    return $root;
}

/** Accept the pinned LF bytes or the exact, independently hashed Windows CRLF checkout. */
function qd_runtime_source_matches_pins(string $path, string $expectedLfSha256, string $expectedWindowsCrlfSha256): bool
{
    if (!is_file($path) || is_link($path)) {
        return false;
    }
    $actualFileSha256 = strtolower((string)@hash_file('sha256', $path));
    if (hash_equals($expectedLfSha256, $actualFileSha256)) {
        return true;
    }
    if (!hash_equals($expectedWindowsCrlfSha256, $actualFileSha256)) {
        return false;
    }
    $bytes = @file_get_contents($path);
    if (!is_string($bytes) || !hash_equals($expectedWindowsCrlfSha256, hash('sha256', $bytes))
        || preg_match('/(?<!\r)\n|\r(?!\n)/', $bytes) === 1) {
        return false;
    }
    return hash_equals($expectedLfSha256, hash('sha256', str_replace("\r\n", "\n", $bytes)));
}

/** Load only the pinned read-request lock helpers; called after the pair is authenticated. */
function qd_runtime_load_profile_lock_helpers(): void
{
    $root = qd_runtime_engine_root();
    $path = $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_runtime.php';
    if (!qd_runtime_source_matches_pins(
        $path,
        QD_RUNTIME_EXPECTED_PLAYTHROUGH_RUNTIME_SHA256,
        QD_RUNTIME_EXPECTED_PLAYTHROUGH_RUNTIME_WINDOWS_CRLF_SHA256,
    )) {
        throw new RuntimeException('The pinned Playthrough Saves request lease is unavailable.');
    }
    require_once $path;
    foreach (['ptr_runtime_file', 'ptr_runtime_paused'] as $function) {
        if (!function_exists($function)
            || realpath((new ReflectionFunction($function))->getFileName() ?: '') !== realpath($path)) {
            throw new RuntimeException('The pinned Playthrough Saves request lease is unavailable.');
        }
    }
}

/** Keep a Department request on one active playthrough through all reads and owner commits. */
function qd_runtime_with_profile_lease(callable $callback): mixed
{
    if (isset($GLOBALS['ptr_runtime_lease']) && is_resource($GLOBALS['ptr_runtime_lease'])) {
        return $callback(); // main.php already owns this request's core lease; never close it here.
    }
    if (is_resource($GLOBALS['qd_runtime_owned_profile_lease'] ?? null)) {
        return $callback(); // Nested trusted helpers reuse our one shared lease.
    }
    if (!empty($GLOBALS['ptr_runtime_controller'])) {
        throw new QDServerFault('busy');
    }
    try {
        qd_runtime_load_profile_lock_helpers();
        if (ptr_runtime_paused()) {
            throw new QDServerFault('busy');
        }
        $lease = ptr_runtime_file('work.lock');
        if (!is_resource($lease)) {
            throw new QDServerFault('unavailable');
        }
        if (!@flock($lease, LOCK_SH | LOCK_NB) || ptr_runtime_paused()) {
            fclose($lease);
            throw new QDServerFault('busy');
        }
    } catch (QDServerFault $error) {
        throw $error;
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }

    $GLOBALS['qd_runtime_owned_profile_lease'] = $lease;
    try {
        return $callback();
    } finally {
        unset($GLOBALS['qd_runtime_owned_profile_lease']);
        if (is_resource($lease)) {
            fclose($lease);
        }
    }
}

/** Capture the real CHIM restore generation while the request owns work.lock SH. */
function qd_runtime_with_correspondence_lease(string $ownerRoot, callable $callback): mixed
{
    if (!empty($GLOBALS['ptr_runtime_controller'])) throw new QDServerFault('busy');
    if (array_key_exists('ptr_runtime_lease', $GLOBALS) && !is_resource($GLOBALS['ptr_runtime_lease'])) {
        throw new QDServerFault('unavailable');
    }
    if (array_key_exists('ptr_runtime_generation', $GLOBALS) && !is_string($GLOBALS['ptr_runtime_generation'])) {
        throw new QDServerFault('unavailable');
    }
    try {
        qd_runtime_load_profile_lock_helpers();
        $coreRoot = qd_runtime_core_root();
        $canonicalOwnerRoot = realpath($ownerRoot);
        if (!is_string($canonicalOwnerRoot) || dirname($canonicalOwnerRoot, 2) !== $coreRoot) {
            throw new RuntimeException('The pinned Correspondence engine root does not match CHIM.');
        }
        if (array_key_exists('ENGINE_PATH', $GLOBALS)) {
            if (!is_string($GLOBALS['ENGINE_PATH']) || realpath($GLOBALS['ENGINE_PATH']) !== $coreRoot) {
                throw new RuntimeException('The Correspondence engine root does not match CHIM.');
            }
        }
        return qd_runtime_with_profile_lease(static function () use ($canonicalOwnerRoot, $coreRoot, $callback): mixed {
            $lease = qd_runtime_correspondence_lease_resource($coreRoot);
            $generation = qd_runtime_correspondence_read_generation();
            if (array_key_exists('ptr_runtime_lease', $GLOBALS)
                && (!is_resource($GLOBALS['ptr_runtime_lease'])
                    || $GLOBALS['ptr_runtime_lease'] !== $lease
                    || !array_key_exists('ptr_runtime_generation', $GLOBALS)
                    || !is_string($GLOBALS['ptr_runtime_generation'])
                    || !hash_equals($generation, $GLOBALS['ptr_runtime_generation']))) {
                throw new RuntimeException('The current core lease generation is unavailable.');
            }
            if (array_key_exists('ptr_runtime_generation', $GLOBALS)
                && (!is_string($GLOBALS['ptr_runtime_generation']) || !hash_equals($generation, $GLOBALS['ptr_runtime_generation']))) {
                throw new RuntimeException('The current core generation is stale.');
            }
            return $callback($generation, $lease, $coreRoot, $canonicalOwnerRoot);
        });
    } catch (QDServerFault $error) {
        throw $error;
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    }
}

/** Temporarily lend the existing Department SH handle to the owner's idempotent runtime entry. */
function qd_runtime_with_correspondence_owner_lease(string $ownerRoot, string $expectedGeneration, mixed $lease, callable $callback): mixed
{
    $keys = ['ptr_runtime_lease', 'ptr_runtime_generation', 'ENGINE_PATH'];
    $saved = qd_runtime_correspondence_save_globals($keys);
    try {
        if (!is_resource($lease) || !empty($GLOBALS['ptr_runtime_controller'])) {
            throw new RuntimeException('The current core lease is unavailable.');
        }
        $canonicalOwnerRoot = realpath($ownerRoot);
        $coreRoot = qd_runtime_core_root();
        if (!is_string($canonicalOwnerRoot) || dirname($canonicalOwnerRoot, 2) !== $coreRoot
            || !hash_equals($expectedGeneration, qd_runtime_correspondence_read_generation())
            || qd_runtime_correspondence_lease_resource($coreRoot) !== $lease) {
            throw new RuntimeException('The current core lease generation changed.');
        }
        if (array_key_exists('ENGINE_PATH', $GLOBALS)) {
            if (!is_string($GLOBALS['ENGINE_PATH']) || realpath($GLOBALS['ENGINE_PATH']) !== $coreRoot) {
                throw new RuntimeException('The Correspondence engine root does not match CHIM.');
            }
        } else {
            $GLOBALS['ENGINE_PATH'] = $coreRoot;
        }
        if (array_key_exists('ptr_runtime_lease', $GLOBALS)) {
            if (!is_resource($GLOBALS['ptr_runtime_lease']) || $GLOBALS['ptr_runtime_lease'] !== $lease
                || !array_key_exists('ptr_runtime_generation', $GLOBALS)
                || !is_string($GLOBALS['ptr_runtime_generation'])
                || !hash_equals($expectedGeneration, $GLOBALS['ptr_runtime_generation'])) {
                throw new RuntimeException('The existing core runtime lease is stale.');
            }
        } else {
            $GLOBALS['ptr_runtime_lease'] = $lease;
            if (array_key_exists('ptr_runtime_generation', $GLOBALS)) {
                if (!is_string($GLOBALS['ptr_runtime_generation']) || !hash_equals($expectedGeneration, $GLOBALS['ptr_runtime_generation'])) {
                    throw new RuntimeException('The existing core runtime generation is stale.');
                }
            } else {
                $GLOBALS['ptr_runtime_generation'] = $expectedGeneration;
            }
        }
        return $callback();
    } catch (QDServerFault $error) {
        throw $error;
    } catch (Throwable) {
        throw new QDServerFault('unavailable');
    } finally {
        qd_runtime_correspondence_restore_globals($saved);
    }
}

function qd_runtime_correspondence_read_generation(): string
{
    $coreRoot = qd_runtime_core_root();
    $logRoot = $coreRoot . DIRECTORY_SEPARATOR . 'log';
    $runtimeRoot = $logRoot . DIRECTORY_SEPARATOR . 'playthrough_runtime';
    $generationPath = $runtimeRoot . DIRECTORY_SEPARATOR . 'generation';
    clearstatcache(true, $logRoot);
    clearstatcache(true, $runtimeRoot);
    clearstatcache(true, $generationPath);
    if (is_link($logRoot) || is_link($runtimeRoot) || is_link($generationPath)) {
        throw new RuntimeException('The core restore generation is not a regular file.');
    }
    $canonicalRuntimeRoot = realpath($runtimeRoot);
    if (!is_string($canonicalRuntimeRoot) || !is_dir($canonicalRuntimeRoot)
        || !is_readable($canonicalRuntimeRoot)
        || !str_starts_with($canonicalRuntimeRoot, $coreRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('The core restore generation path is unavailable.');
    }
    $linkStat = @lstat($generationPath);
    if ($linkStat === false) return '';
    $pathStat = @stat($generationPath);
    if (!is_array($pathStat) || (($linkStat['mode'] ?? 0) & 0170000) !== 0100000
        || (($pathStat['mode'] ?? 0) & 0170000) !== 0100000) {
        throw new RuntimeException('The core restore generation is not a regular file.');
    }
    $handle = @fopen($generationPath, 'rb');
    if ($handle === false) throw new RuntimeException('The core restore generation cannot be read.');
    try {
        $stat = fstat($handle);
        if (!is_array($stat) || !isset($stat['mode'], $stat['size'])
            || (($stat['mode'] & 0170000) !== 0100000) || $stat['size'] < 1 || $stat['size'] > 256) {
            throw new RuntimeException('The core restore generation has an invalid size or type.');
        }
        $raw = stream_get_contents($handle, 257);
        clearstatcache(true, $generationPath);
        $afterLinkStat = @lstat($generationPath);
        $afterPathStat = @stat($generationPath);
        if (!is_string($raw) || strlen($raw) > 256 || !is_int($pathStat['dev'] ?? null) || !is_int($pathStat['ino'] ?? null)
            || !is_int($stat['dev'] ?? null) || !is_int($stat['ino'] ?? null)
            || !is_array($afterLinkStat) || !is_array($afterPathStat)
            || (($afterLinkStat['mode'] ?? 0) & 0170000) !== 0100000
            || (($afterPathStat['mode'] ?? 0) & 0170000) !== 0100000
            || ($pathStat['dev'] ?? null) !== ($stat['dev'] ?? null)
            || ($pathStat['ino'] ?? null) !== ($stat['ino'] ?? null)
            || ($afterLinkStat['dev'] ?? null) !== ($stat['dev'] ?? null)
            || ($afterLinkStat['ino'] ?? null) !== ($stat['ino'] ?? null)
            || ($afterPathStat['dev'] ?? null) !== ($stat['dev'] ?? null)
            || ($afterPathStat['ino'] ?? null) !== ($stat['ino'] ?? null)) {
            throw new RuntimeException('The core restore generation changed while being read.');
        }
        $generation = trim($raw);
        if (preg_match('/\A[0-9a-f]{32}\z/D', $generation) !== 1) {
            throw new RuntimeException('The core restore generation is malformed.');
        }
        return $generation;
    } finally {
        fclose($handle);
    }
}

function qd_runtime_correspondence_lease_resource(string $coreRoot): mixed
{
    $lease = $GLOBALS['ptr_runtime_lease'] ?? $GLOBALS['qd_runtime_owned_profile_lease'] ?? null;
    if (!is_resource($lease)) throw new RuntimeException('The shared profile lease is unavailable.');
    if (is_resource($GLOBALS['ptr_runtime_lease'] ?? null)
        && is_resource($GLOBALS['qd_runtime_owned_profile_lease'] ?? null)
        && $GLOBALS['ptr_runtime_lease'] !== $GLOBALS['qd_runtime_owned_profile_lease']) {
        throw new RuntimeException('The shared profile lease handles do not match.');
    }
    $path = $coreRoot . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'playthrough_runtime' . DIRECTORY_SEPARATOR . 'work.lock';
    clearstatcache(true, $path);
    $linkStat = @lstat($path);
    $pathStat = @stat($path);
    $handleStat = @fstat($lease);
    if (!is_array($linkStat) || !is_array($pathStat) || !is_array($handleStat)
        || (($linkStat['mode'] ?? 0) & 0170000) !== 0100000
        || (($pathStat['mode'] ?? 0) & 0170000) !== 0100000
        || (($handleStat['mode'] ?? 0) & 0170000) !== 0100000
        || !is_int($linkStat['dev'] ?? null) || !is_int($linkStat['ino'] ?? null)
        || !is_int($pathStat['dev'] ?? null) || !is_int($pathStat['ino'] ?? null)
        || !is_int($handleStat['dev'] ?? null) || !is_int($handleStat['ino'] ?? null)
        || $linkStat['ino'] === 0 || $pathStat['ino'] === 0 || $handleStat['ino'] === 0
        || $linkStat['dev'] !== $pathStat['dev'] || $linkStat['ino'] !== $pathStat['ino']
        || $pathStat['dev'] !== $handleStat['dev'] || $pathStat['ino'] !== $handleStat['ino']) {
        throw new RuntimeException('The shared profile lease path no longer identifies the held file.');
    }
    $expected = realpath($path);
    $metadata = stream_get_meta_data($lease);
    $uri = is_array($metadata) ? ($metadata['uri'] ?? null) : null;
    if (!is_string($expected) || !is_string($uri) || realpath($uri) !== $expected) {
        throw new RuntimeException('The shared profile lease is not the pinned work.lock handle.');
    }
    return $lease;
}

function qd_runtime_correspondence_save_globals(array $keys): array
{
    $saved = [];
    foreach ($keys as $key) {
        $saved[$key] = [array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null];
    }
    return $saved;
}

function qd_runtime_correspondence_restore_globals(array $saved): void
{
    foreach ($saved as $key => [$present, $value]) {
        if ($present) $GLOBALS[$key] = $value;
        else unset($GLOBALS[$key]);
    }
}

function qd_runtime_core_root(): string
{
    return qd_runtime_engine_root();
}

function qd_runtime_load_core_readers(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $root = qd_runtime_engine_root();
    $preferences = $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_preferences.php';
    $home = $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_home.php';
    $runtime = $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_runtime.php';
    foreach ([
        $preferences => [QD_RUNTIME_EXPECTED_PLAYTHROUGH_PREFERENCES_SHA256, QD_RUNTIME_EXPECTED_PLAYTHROUGH_PREFERENCES_WINDOWS_CRLF_SHA256],
        $home => [QD_RUNTIME_EXPECTED_PLAYTHROUGH_HOME_SHA256, QD_RUNTIME_EXPECTED_PLAYTHROUGH_HOME_WINDOWS_CRLF_SHA256],
        $runtime => [QD_RUNTIME_EXPECTED_PLAYTHROUGH_RUNTIME_SHA256, QD_RUNTIME_EXPECTED_PLAYTHROUGH_RUNTIME_WINDOWS_CRLF_SHA256],
    ] as $path => $expectedHashes) {
        if (!qd_runtime_source_matches_pins($path, $expectedHashes[0], $expectedHashes[1])) {
            throw new RuntimeException('The pinned CHIM playthrough reader is unavailable.');
        }
    }
    if (!is_file($home)) {
        throw new RuntimeException('The pinned CHIM playthrough reader is unavailable.');
    }
    require_once $preferences;
    require_once $runtime;
    require_once $home;
    if (!function_exists('ptp_connect') || !function_exists('pth_state')
        || !function_exists('ptr_runtime_file') || !function_exists('ptr_runtime_paused')) {
        throw new RuntimeException('The CHIM playthrough reader contract is unavailable.');
    }
    $loaded = true;
}

function qd_runtime_resolve_scope(string $pairId): string
{
    if (preg_match('/\A[0-9a-f-]{36}\z/D', $pairId) !== 1) {
        throw new InvalidArgumentException('The issued pair is invalid.');
    }
    return qd_runtime_with_core_db(static function ($connection): string {
        return qd_runtime_active_playthrough($connection)['scopeKey'];
    });
}

/** Open and close an owned ptp_connect() connection around one bounded read. */
function qd_runtime_with_core_db(callable $callback): mixed
{
    qd_runtime_load_core_readers();
    if (!function_exists('pg_close')) {
        throw new RuntimeException('The CHIM database reader is unavailable.');
    }
    return qd_runtime_with_owned_connection(
        static fn() => ptp_connect(),
        static function ($connection): void { @pg_close($connection); },
        $callback,
    );
}

/** Read only the current authoritative profile identity through pinned pth_state(). */
function qd_runtime_active_playthrough(mixed $connection): array
{
    if ($connection === false || $connection === null || !function_exists('pth_state')
        || !function_exists('pg_transaction_status') || !function_exists('pg_query')
        || !function_exists('pg_query_params') || !function_exists('pg_fetch_assoc')
        || !function_exists('pg_num_rows') || !function_exists('pg_free_result')) {
        throw new RuntimeException('The active CHIM profile is unavailable.');
    }

    $ownsTransaction = pg_transaction_status($connection) === PGSQL_TRANSACTION_IDLE;
    if ($ownsTransaction && pg_query($connection, 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY') === false) {
        throw new RuntimeException('The active CHIM profile is unavailable.');
    }
    try {
        $homeState = pth_state($connection);
        if (($homeState['available'] ?? null) !== true || !is_array($homeState['playthroughs'] ?? null)) {
            throw new RuntimeException('The active CHIM profile is unavailable.');
        }
        $activeId = filter_var($homeState['active_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($activeId) || $activeId < 1 || ($homeState['auto_switch_pending'] ?? false) === true) {
            throw new RuntimeException('There is no settled active CHIM profile.');
        }
        $activeChoices = array_values(array_filter($homeState['playthroughs'], static fn($profile): bool => is_array($profile) && ($profile['active'] ?? null) === true));
        if (count($activeChoices) !== 1 || filter_var($activeChoices[0]['id'] ?? null, FILTER_VALIDATE_INT) !== $activeId) {
            throw new RuntimeException('The active CHIM profile is ambiguous.');
        }
        $choice = $activeChoices[0];
        if (($choice['available'] ?? null) !== true
            || !is_string($choice['character_id'] ?? null)
            || preg_match('/\A[a-f0-9]{32}\z/D', $choice['character_id']) !== 1) {
            throw new RuntimeException('The active CHIM character identity is unavailable.');
        }

        $meta = function_exists('ptp_product') ? ptp_product()['meta'] ?? null : null;
        if ($meta !== 'chim_meta') {
            throw new RuntimeException('The configured CHIM profile catalog is unavailable.');
        }
        $result = @pg_query_params($connection,
            "SELECT p.id,p.schema_name,p.storage_type,p.is_active,(n.oid IS NOT NULL) AS schema_available\n"
            . "FROM chim_meta.playthrough_profiles p\n"
            . "LEFT JOIN pg_namespace n ON n.nspname=p.schema_name AND p.storage_type='schema'\n"
            . 'WHERE p.id=$1 LIMIT 2',
            [$activeId]);
        if ($result === false) {
            throw new RuntimeException('The active CHIM profile metadata is unavailable.');
        }
        try {
            if (pg_num_rows($result) !== 1) {
                throw new RuntimeException('The active CHIM profile metadata is ambiguous.');
            }
            $row = pg_fetch_assoc($result);
        } finally {
            @pg_free_result($result);
        }
        $schema = is_array($row) ? ($row['schema_name'] ?? null) : null;
        if (!is_array($row) || (string)($row['id'] ?? '') !== (string)$activeId
            || !in_array($row['is_active'] ?? null, ['t', true], true)
            || ($row['storage_type'] ?? null) !== 'schema'
            || !in_array($row['schema_available'] ?? null, ['t', true], true)
            || !is_string($schema) || preg_match('/\Achim_profile_[a-z0-9_]{1,50}\z/D', $schema) !== 1) {
            throw new RuntimeException('The active CHIM profile schema is unavailable.');
        }

        $scopeKey = qd_runtime_scope_key_from_home_state($homeState, $schema);
        if ($ownsTransaction && pg_query($connection, 'COMMIT') === false) {
            throw new RuntimeException('The active CHIM profile could not be verified.');
        }
        return [
            'profileId' => $activeId,
            'schemaName' => $schema,
            'characterId' => $choice['character_id'],
            'scopeKey' => $scopeKey,
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction && pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
            @pg_query($connection, 'ROLLBACK');
        }
        throw $error;
    }
}

function qd_runtime_module_operations(string $functionName): array
{
    if (!in_array($functionName, ['qd_mp_runtime_operations', 'qd_sas_runtime_operations', 'qd_correspondence_runtime_operations'], true)) {
        throw new InvalidArgumentException('Runtime module is not allowlisted.');
    }
    if (!function_exists($functionName)) {
        return [];
    }
    $operations = $functionName();
    return is_array($operations) ? $operations : [];
}

