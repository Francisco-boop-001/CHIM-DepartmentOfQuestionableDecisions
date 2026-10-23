<?php
declare(strict_types=1);

require_once __DIR__ . '/protocol.php';

const QD_ALLOWED_SCOPES = ['pcv', 'inspector', 'compose', 'author_detail'];
const QD_MAX_PAIRING_FILE_BYTES = 1048576;
const QD_MAX_STATE_FILE_BYTES = 4194304;
const QD_GUARDED_JSON_PREFIX = "<?php http_response_code(404); exit; __halt_compiler();\n";

function qd_is_absolute_path(string $path): bool
{
    $windowsDriveAbsolute = strlen($path) >= 3
        && preg_match('/\A[A-Za-z]:/', $path) === 1
        && in_array($path[2], ['\\', '/'], true);
    return str_starts_with($path, '/')
        || $windowsDriveAbsolute
        || str_starts_with($path, '\\\\');
}

function qd_path_key(string $path): string
{
    return DIRECTORY_SEPARATOR === '\\' ? strtolower(str_replace('/', '\\', $path)) : $path;
}

function qd_path_is_within(string $path, string $directory): bool
{
    $pathKey = rtrim(qd_path_key($path), '\\/');
    $directoryKey = rtrim(qd_path_key($directory), '\\/');
    return $pathKey === $directoryKey || str_starts_with($pathKey, $directoryKey . DIRECTORY_SEPARATOR);
}

function qd_path_is_lexically_within(string $path, string $directory): bool
{
    $pathKey = rtrim(qd_path_key(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path)), '\\/');
    $directoryKey = rtrim(qd_path_key(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory)), '\\/');
    return $pathKey === $directoryKey || str_starts_with($pathKey, $directoryKey . DIRECTORY_SEPARATOR);
}

/** Return the one companion-owned data root beneath CHIM's persistent data directory. */
function qd_default_private_root(string $coreRoot, bool $create = true): ?string
{
    if (!qd_is_absolute_path($coreRoot) || str_contains($coreRoot, "\0") || is_link($coreRoot)) {
        return null;
    }
    $realCoreRoot = realpath($coreRoot);
    if ($realCoreRoot === false || !is_dir($realCoreRoot)) {
        return null;
    }
    $dataRoot = $realCoreRoot . DIRECTORY_SEPARATOR . 'data';
    if (is_link($dataRoot) || !is_dir($dataRoot)) {
        return null;
    }
    $realDataRoot = realpath($dataRoot);
    if ($realDataRoot === false || qd_path_key($realDataRoot) !== qd_path_key($dataRoot)) {
        return null;
    }
    $privateRoot = $realDataRoot . DIRECTORY_SEPARATOR . 'questionable_decisions-private';
    if (is_link($privateRoot) || (file_exists($privateRoot) && !is_dir($privateRoot))) {
        return null;
    }
    if (!is_dir($privateRoot)) {
        if (!$create || !@mkdir($privateRoot, 0700)) {
            return null;
        }
    }
    $realPrivateRoot = realpath($privateRoot);
    if ($realPrivateRoot === false || qd_path_key($realPrivateRoot) !== qd_path_key($privateRoot)
        || !qd_path_is_within($realPrivateRoot, $realDataRoot)) {
        return null;
    }
    @chmod($realPrivateRoot, 0700);
    return $realPrivateRoot;
}

function qd_managed_file_path(string $path, string $privateRoot, bool $mustExist): ?string
{
    if (!qd_is_absolute_path($path) || !qd_is_absolute_path($privateRoot)
        || str_contains($path, "\0") || str_contains($privateRoot, "\0")
        || !str_ends_with(strtolower(basename($path)), '.php')) {
        return null;
    }
    $realRoot = realpath($privateRoot);
    if ($realRoot === false || !is_dir($realRoot) || is_link($privateRoot)
        || is_link($path) || basename($path) === '' || basename($path) === '.' || basename($path) === '..') {
        return null;
    }
    $parent = dirname($path);
    $realParent = realpath($parent);
    if ($realParent === false || !is_dir($realParent) || !qd_path_is_within($realParent, $realRoot)) {
        return null;
    }
    for ($cursor = $parent; ; $cursor = dirname($cursor)) {
        if (is_link($cursor)) {
            return null;
        }
        $realCursor = realpath($cursor);
        if ($realCursor === false || !qd_path_is_within($realCursor, $realRoot)) {
            return null;
        }
        if (qd_path_key($realCursor) === qd_path_key($realRoot)) {
            break;
        }
        if (qd_path_key(dirname($cursor)) === qd_path_key($cursor)) {
            return null;
        }
    }
    $candidate = $realParent . DIRECTORY_SEPARATOR . basename($path);
    if (!qd_path_is_within($candidate, $realRoot)) {
        return null;
    }
    if ($mustExist) {
        $realFile = realpath($candidate);
        if ($realFile === false || !is_file($realFile) || is_link($candidate) || !qd_path_is_within($realFile, $realRoot)) {
            return null;
        }
        return $realFile;
    }
    return $candidate;
}

/** Managed paths are accepted only inside the fixed companion data root; all other paths keep the external-only rule. */
function qd_storage_file_path(string $path, string $webRoot, ?string $privateRoot, bool $mustExist, ?string $protectedRoot = null): ?array
{
    if (is_string($privateRoot) && $privateRoot !== '' && qd_path_is_lexically_within($path, $privateRoot)) {
        $managedPath = qd_managed_file_path($path, $privateRoot, $mustExist);
        return $managedPath === null ? null : ['path' => $managedPath, 'guarded' => true];
    }
    $externalPath = qd_external_file_path($path, $webRoot, $mustExist, $protectedRoot);
    return $externalPath === null ? null : ['path' => $externalPath, 'guarded' => false];
}

function qd_managed_directory_path(string $directory, string $privateRoot, bool $create = false): ?string
{
    if (!qd_is_absolute_path($directory) || !qd_path_is_lexically_within($directory, $privateRoot)
        || is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
        return null;
    }
    $realRoot = realpath($privateRoot);
    if ($realRoot === false || is_link($privateRoot)) {
        return null;
    }
    if (!is_dir($directory)) {
        if (!$create || !@mkdir($directory, 0700) || is_link($directory)) {
            return null;
        }
    }
    $realDirectory = realpath($directory);
    if ($realDirectory === false || is_link($directory) || !qd_path_is_within($realDirectory, $realRoot)) {
        return null;
    }
    @chmod($realDirectory, 0700);
    return $realDirectory;
}

function qd_external_file_path(string $path, string $webRoot, bool $mustExist, ?string $protectedRoot = null): ?string
{
    if (!qd_is_absolute_path($path) || !qd_is_absolute_path($webRoot) || str_contains($path, "\0")) {
        return null;
    }
    $realWebRoot = realpath($webRoot);
    $realDirectory = realpath(dirname($path));
    if ($realWebRoot === false || $realDirectory === false || is_link($path) || basename($path) === '' || basename($path) === '.' || basename($path) === '..') {
        return null;
    }
    $protectedRoots = [$realWebRoot];
    if (is_string($protectedRoot) && $protectedRoot !== '') {
        $realProtectedRoot = realpath($protectedRoot);
        if ($realProtectedRoot === false || !is_dir($realProtectedRoot)) {
            return null;
        }
        $protectedRoots[] = $realProtectedRoot;
    }
    $candidate = $realDirectory . DIRECTORY_SEPARATOR . basename($path);
    foreach ($protectedRoots as $root) {
        if (qd_path_is_within($candidate, $root)) {
            return null;
        }
    }
    if ($mustExist) {
        $realFile = realpath($candidate);
        if ($realFile === false || !is_file($realFile)) {
            return null;
        }
        foreach ($protectedRoots as $root) {
            if (qd_path_is_within($realFile, $root)) {
                return null;
            }
        }
        return $realFile;
    }
    return $candidate;
}

function qd_atomic_write(string $path, string $content, bool $guarded = false): void
{
    $temporary = $path . '.' . bin2hex(random_bytes(16)) . ($guarded ? '.tmp.php' : '.tmp');
    $handle = @fopen($temporary, 'x+b');
    if ($handle === false) {
        throw new QDServerFault('unavailable');
    }
    $ok = false;
    try {
        $offset = 0;
        $bytes = $guarded ? QD_GUARDED_JSON_PREFIX . $content : $content;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new QDServerFault('unavailable');
            }
            $offset += $written;
        }
        if (!fflush($handle)) {
            throw new QDServerFault('unavailable');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new QDServerFault('unavailable');
        }
        @chmod($temporary, 0600);
        $ok = true;
    } finally {
        fclose($handle);
        if (!$ok) {
            @unlink($temporary);
        }
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new QDServerFault('unavailable');
    }
    @chmod($path, 0600);
}

function qd_locked_json_transaction(string $path, string $webRoot, array $initial, callable $callback, int $maxBytes, ?string $privateRoot = null, ?string $protectedRoot = null): mixed
{
    $storage = qd_storage_file_path($path, $webRoot, $privateRoot, false, $protectedRoot);
    if ($storage === null) {
        throw new QDServerFault('unavailable');
    }
    $safePath = $storage['path'];
    $guarded = $storage['guarded'];
    $lockPath = $safePath . ($guarded ? '.lock.php' : '.lock');
    $safeLock = $guarded ? qd_managed_file_path($lockPath, (string)$privateRoot, false) : qd_external_file_path($lockPath, $webRoot, false, $protectedRoot);
    if ($safeLock === null || is_link($lockPath)) {
        throw new QDServerFault('unavailable');
    }
    $lock = @fopen($safeLock, 'c+b');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new QDServerFault('unavailable');
    }
    @chmod($safeLock, 0600);
    try {
        if ($guarded) {
            $lockSize = @filesize($safeLock);
            if (!is_int($lockSize)) {
                throw new QDServerFault('unavailable');
            }
            if ($lockSize === 0) {
                if (fwrite($lock, QD_GUARDED_JSON_PREFIX) !== strlen(QD_GUARDED_JSON_PREFIX) || !fflush($lock)) {
                    throw new QDServerFault('unavailable');
                }
                if (function_exists('fsync') && !fsync($lock)) {
                    throw new QDServerFault('unavailable');
                }
            } else {
                rewind($lock);
                $lockPrefix = fread($lock, strlen(QD_GUARDED_JSON_PREFIX));
                if (!is_string($lockPrefix) || !hash_equals(QD_GUARDED_JSON_PREFIX, $lockPrefix)) {
                    throw new QDServerFault('unavailable');
                }
            }
        }
        $exists = is_file($safePath);
        if (is_link($safePath)) {
            throw new QDServerFault('unavailable');
        }
        if ($exists) {
            $size = filesize($safePath);
            if ($size === false || $size > $maxBytes + ($guarded ? strlen(QD_GUARDED_JSON_PREFIX) : 0)) {
                throw new QDServerFault('unavailable');
            }
            $raw = file_get_contents($safePath);
            if (!is_string($raw)) {
                throw new QDServerFault('unavailable');
            }
            if ($guarded) {
                if (!str_starts_with($raw, QD_GUARDED_JSON_PREFIX)) {
                    throw new QDServerFault('unavailable');
                }
                $raw = substr($raw, strlen(QD_GUARDED_JSON_PREFIX));
            }
            if (strlen($raw) > $maxBytes) {
                throw new QDServerFault('unavailable');
            }
            try {
                $state = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new QDServerFault('unavailable');
            }
            if (!is_array($state)) {
                throw new QDServerFault('unavailable');
            }
        } else {
            $state = $initial;
        }
        $before = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $result = $callback($state);
        $after = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (!$exists || $before !== $after) {
            if (strlen($after) > $maxBytes) {
                throw new QDServerFault('unavailable');
            }
            qd_atomic_write($safePath, $after, $guarded);
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function qd_valid_pairing_config(mixed $config): bool
{
    if (!is_array($config) || array_keys($config) !== ['version', 'pairs'] || ($config['version'] ?? null) !== 1 || !is_array($config['pairs']) || !array_is_list($config['pairs']) || count($config['pairs']) > 4096) {
        return false;
    }
    $ids = [];
    foreach ($config['pairs'] as $pair) {
        if (!is_array($pair)
            || array_diff(array_keys($pair), ['pairId', 'tokenHash', 'scopes', 'revoked']) !== []
            || array_diff(['pairId', 'tokenHash', 'scopes', 'revoked'], array_keys($pair)) !== []
            || !qd_is_uuid($pair['pairId'])
            || isset($ids[$pair['pairId']])
            || !is_string($pair['tokenHash'])
            || preg_match('/\A[0-9a-f]{64}\z/D', $pair['tokenHash']) !== 1
            || !is_array($pair['scopes'])
            || !array_is_list($pair['scopes'])
            || count($pair['scopes']) < 1
            || count($pair['scopes']) > count(QD_ALLOWED_SCOPES)
            || !is_bool($pair['revoked'])) {
            return false;
        }
        $ids[$pair['pairId']] = true;
        $scopes = [];
        foreach ($pair['scopes'] as $scope) {
            if (!is_string($scope) || !in_array($scope, QD_ALLOWED_SCOPES, true) || isset($scopes[$scope])) {
                return false;
            }
            $scopes[$scope] = true;
        }
        if (isset($scopes['author_detail']) && !isset($scopes['inspector'])) {
            return false;
        }
    }
    return true;
}

function qd_authenticate(string $pairingPath, string $webRoot, ?string $authorizationHeader, ?string $privateRoot = null, ?string $protectedRoot = null): ?array
{
    if (!is_string($authorizationHeader) || preg_match('/\ABearer (qd1_[A-Za-z0-9_-]{43})\z/D', $authorizationHeader, $matches) !== 1) {
        return null;
    }
    $tokenHash = hash('sha256', $matches[1]);
    try {
        $storage = qd_storage_file_path($pairingPath, $webRoot, $privateRoot, true, $protectedRoot);
        if ($storage === null) {
            return null;
        }
        $safePath = $storage['path'];
        $size = filesize($safePath);
        if ($size === false || $size > QD_MAX_PAIRING_FILE_BYTES + ($storage['guarded'] ? strlen(QD_GUARDED_JSON_PREFIX) : 0)) {
            return null;
        }
        $raw = file_get_contents($safePath);
        if (!is_string($raw)) {
            return null;
        }
        if ($storage['guarded']) {
            if (!str_starts_with($raw, QD_GUARDED_JSON_PREFIX)) {
                return null;
            }
            $raw = substr($raw, strlen(QD_GUARDED_JSON_PREFIX));
        }
        if (strlen($raw) > QD_MAX_PAIRING_FILE_BYTES) {
            return null;
        }
        $config = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!qd_valid_pairing_config($config)) {
            return null;
        }
        foreach ($config['pairs'] as $pair) {
            if (!$pair['revoked'] && hash_equals($pair['tokenHash'], $tokenHash)) {
                return ['pairId' => $pair['pairId'], 'scopes' => $pair['scopes']];
            }
        }
        return null;
    } catch (Throwable) {
        return null;
    }
}

function qd_new_token(): string
{
    return 'qd1_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function qd_new_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

/** Approve an already-generated native bearer by hash without returning or creating its literal. */
function qd_pair_approve_hash(string $pairingPath, string $webRoot, string $tokenHash, ?string $privateRoot = null, ?string $protectedRoot = null): array
{
    if (preg_match('/\A[0-9a-f]{64}\z/D', $tokenHash) !== 1) {
        throw new QDServerFault('invalid_request');
    }
    $scopes = ['compose', 'inspector', 'pcv'];
    return qd_locked_json_transaction(
        $pairingPath,
        $webRoot,
        ['version' => 1, 'pairs' => []],
        static function (array &$config) use ($tokenHash, $scopes): array {
            if (!qd_valid_pairing_config($config)) {
                throw new QDServerFault('unavailable');
            }
            $match = null;
            foreach ($config['pairs'] as $pair) {
                if (hash_equals($pair['tokenHash'], $tokenHash)) {
                    if ($match !== null) {
                        throw new QDServerFault('unavailable');
                    }
                    $match = $pair;
                }
            }
            if ($match !== null) {
                return $match['revoked']
                    ? ['state' => 'revoked', 'pairId' => $match['pairId']]
                    : ['state' => 'approved', 'pairId' => $match['pairId']];
            }
            if (count($config['pairs']) >= 4096) {
                return ['state' => 'capacity', 'pairId' => null];
            }
            $pairId = qd_new_uuid();
            $config['pairs'][] = [
                'pairId' => $pairId,
                'tokenHash' => $tokenHash,
                'scopes' => $scopes,
                'revoked' => false,
            ];
            return ['state' => 'approved', 'pairId' => $pairId];
        },
        QD_MAX_PAIRING_FILE_BYTES,
        $privateRoot,
        $protectedRoot,
    );
}

function qd_pair_issue(string $pairingPath, string $webRoot, array $scopes, ?string $privateRoot = null, ?string $protectedRoot = null): array
{
    if (!array_is_list($scopes) || count($scopes) < 1 || count($scopes) > count(QD_ALLOWED_SCOPES)) {
        throw new QDServerFault('invalid_request');
    }
    $unique = [];
    foreach ($scopes as $scope) {
        if (!is_string($scope) || !in_array($scope, QD_ALLOWED_SCOPES, true) || isset($unique[$scope])) {
            throw new QDServerFault('invalid_request');
        }
        $unique[$scope] = true;
    }
    if (isset($unique['author_detail']) && !isset($unique['inspector'])) {
        throw new QDServerFault('invalid_request');
    }
    sort($scopes, SORT_STRING);
    $token = qd_new_token();
    $pairId = sprintf('%08x-%04x-%04x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffffffffffff));
    qd_locked_json_transaction(
        $pairingPath,
        $webRoot,
        ['version' => 1, 'pairs' => []],
        static function (array &$config) use ($pairId, $token, $scopes): bool {
            if (!qd_valid_pairing_config($config)) {
                throw new QDServerFault('unavailable');
            }
            $config['pairs'][] = ['pairId' => $pairId, 'tokenHash' => hash('sha256', $token), 'scopes' => $scopes, 'revoked' => false];
            return true;
        },
        QD_MAX_PAIRING_FILE_BYTES,
        $privateRoot,
        $protectedRoot,
    );
    return ['pairId' => $pairId, 'token' => $token, 'scopes' => $scopes];
}

function qd_pair_revoke(string $pairingPath, string $webRoot, string $pairId, ?string $privateRoot = null, ?string $protectedRoot = null): bool
{
    if (!qd_is_uuid($pairId)) {
        throw new QDServerFault('invalid_request');
    }
    return qd_locked_json_transaction(
        $pairingPath,
        $webRoot,
        ['version' => 1, 'pairs' => []],
        static function (array &$config) use ($pairId): bool {
            if (!qd_valid_pairing_config($config)) {
                throw new QDServerFault('unavailable');
            }
            foreach ($config['pairs'] as &$pair) {
                if ($pair['pairId'] === $pairId) {
                    $pair['revoked'] = true;
                    return true;
                }
            }
            throw new QDServerFault('not_found');
        },
        QD_MAX_PAIRING_FILE_BYTES,
        $privateRoot,
        $protectedRoot,
    );
}

function qd_server_state_transaction(array $settings, callable $callback): mixed
{
    $path = $settings['statePath'] ?? '';
    $webRoot = $settings['webRoot'] ?? '';
    if (!is_string($path) || !is_string($webRoot)) {
        throw new QDServerFault('unavailable');
    }
    $initial = ['version' => 1, 'contexts' => [], 'operations' => []];
    return qd_locked_json_transaction(
        $path,
        $webRoot,
        $initial,
        static function (array &$state) use ($callback): mixed {
            if (array_diff(array_keys($state), ['version', 'contexts', 'operations']) !== []
                || array_diff(['version', 'contexts', 'operations'], array_keys($state)) !== []
                || ($state['version'] ?? null) !== 1
                || !is_array($state['contexts'] ?? null)
                || !is_array($state['operations'] ?? null)) {
                throw new QDServerFault('unavailable');
            }
            return $callback($state);
        },
        QD_MAX_STATE_FILE_BYTES,
        is_string($settings['privateRoot'] ?? null) ? $settings['privateRoot'] : null,
        is_string($settings['coreRoot'] ?? null) ? $settings['coreRoot'] : null,
    );
}
