<?php
declare(strict_types=1);

require_once __DIR__ . '/protocol.php';

const QD_ALLOWED_SCOPES = ['pcv', 'inspector', 'compose', 'author_detail'];
const QD_MAX_PAIRING_FILE_BYTES = 1048576;
const QD_MAX_STATE_FILE_BYTES = 4194304;

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

function qd_external_file_path(string $path, string $webRoot, bool $mustExist): ?string
{
    if (!qd_is_absolute_path($path) || !qd_is_absolute_path($webRoot) || str_contains($path, "\0")) {
        return null;
    }
    $realWebRoot = realpath($webRoot);
    $realDirectory = realpath(dirname($path));
    if ($realWebRoot === false || $realDirectory === false || is_link($path) || basename($path) === '' || basename($path) === '.' || basename($path) === '..') {
        return null;
    }
    $candidate = $realDirectory . DIRECTORY_SEPARATOR . basename($path);
    if (qd_path_is_within($candidate, $realWebRoot)) {
        return null;
    }
    if ($mustExist) {
        $realFile = realpath($candidate);
        if ($realFile === false || !is_file($realFile) || qd_path_is_within($realFile, $realWebRoot)) {
            return null;
        }
        return $realFile;
    }
    return $candidate;
}

function qd_atomic_write(string $path, string $content): void
{
    $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $handle = @fopen($temporary, 'x+b');
    if ($handle === false) {
        throw new QDServerFault('unavailable');
    }
    $ok = false;
    try {
        $offset = 0;
        $length = strlen($content);
        while ($offset < $length) {
            $written = fwrite($handle, substr($content, $offset));
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

function qd_locked_json_transaction(string $path, string $webRoot, array $initial, callable $callback, int $maxBytes): mixed
{
    $safePath = qd_external_file_path($path, $webRoot, false);
    if ($safePath === null) {
        throw new QDServerFault('unavailable');
    }
    $lockPath = $safePath . '.lock';
    if (is_link($lockPath)) {
        throw new QDServerFault('unavailable');
    }
    $lock = @fopen($lockPath, 'c+b');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new QDServerFault('unavailable');
    }
    @chmod($lockPath, 0600);
    try {
        $exists = is_file($safePath);
        if (is_link($safePath)) {
            throw new QDServerFault('unavailable');
        }
        if ($exists) {
            $size = filesize($safePath);
            if ($size === false || $size > $maxBytes) {
                throw new QDServerFault('unavailable');
            }
            $raw = file_get_contents($safePath);
            if (!is_string($raw) || strlen($raw) > $maxBytes) {
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
            qd_atomic_write($safePath, $after);
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

function qd_authenticate(string $pairingPath, string $webRoot, ?string $authorizationHeader): ?array
{
    if (!is_string($authorizationHeader) || preg_match('/\ABearer (qd1_[A-Za-z0-9_-]{43})\z/D', $authorizationHeader, $matches) !== 1) {
        return null;
    }
    $tokenHash = hash('sha256', $matches[1]);
    try {
        $safePath = qd_external_file_path($pairingPath, $webRoot, true);
        if ($safePath === null) {
            return null;
        }
        $size = filesize($safePath);
        if ($size === false || $size > QD_MAX_PAIRING_FILE_BYTES) {
            return null;
        }
        $raw = file_get_contents($safePath);
        if (!is_string($raw) || strlen($raw) > QD_MAX_PAIRING_FILE_BYTES) {
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

function qd_pair_issue(string $pairingPath, string $webRoot, array $scopes): array
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
    );
    return ['pairId' => $pairId, 'token' => $token, 'scopes' => $scopes];
}

function qd_pair_revoke(string $pairingPath, string $webRoot, string $pairId): bool
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
    );
}
