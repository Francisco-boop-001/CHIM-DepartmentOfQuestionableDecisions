<?php
declare(strict_types=1);

const QD_JOURNAL_DETAIL_LIMIT = 128;
const QD_JOURNAL_RECORD_BYTES = 20480;
const QD_JOURNAL_MAX_OUTSTANDING = 64;

function qd_journal_fsync_directory(string $directory): void
{
    $realDirectory = realpath($directory);
    if ($realDirectory === false || !is_dir($realDirectory) || is_link($directory)) {
        throw new QDServerFault('unavailable');
    }
    $handle = @fopen($realDirectory, 'r');
    if ($handle === false) {
        throw new QDServerFault('unavailable');
    }
    try {
        if (!function_exists('fsync') || !@fsync($handle)) {
            throw new QDServerFault('unavailable');
        }
    } finally {
        @fclose($handle);
    }
}

function qd_journal_directory(array $settings): string
{
    $statePath = $settings['statePath'] ?? null;
    $webRoot = $settings['webRoot'] ?? null;
    if (!is_string($statePath) || !is_string($webRoot)) {
        throw new QDServerFault('unavailable');
    }
    $privateRoot = is_string($settings['privateRoot'] ?? null) ? $settings['privateRoot'] : null;
    $protectedRoot = is_string($settings['coreRoot'] ?? null) ? $settings['coreRoot'] : null;
    $storage = qd_storage_file_path($statePath, $webRoot, $privateRoot, false, $protectedRoot);
    if ($storage === null) {
        throw new QDServerFault('unavailable');
    }
    if ($storage['guarded']) {
        if (!is_string($privateRoot)) {
            throw new QDServerFault('unavailable');
        }
        $directory = qd_managed_directory_path($privateRoot . DIRECTORY_SEPARATOR . 'journal', $privateRoot, true);
        if ($directory === null) {
            throw new QDServerFault('unavailable');
        }
        qd_journal_fsync_directory($privateRoot);
        return $directory;
    }
    $safeStatePath = $storage['path'];
    if ($safeStatePath === null) {
        throw new QDServerFault('unavailable');
    }
    $directory = $safeStatePath . '.journal';
    if (is_link($directory)) {
        throw new QDServerFault('unavailable');
    }
    if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
        throw new QDServerFault('unavailable');
    }
    $realDirectory = realpath($directory);
    $realParent = realpath(dirname($safeStatePath));
    $realWebRoot = realpath($webRoot);
    if ($realDirectory === false || $realParent === false || $realWebRoot === false || is_link($directory)
        || qd_path_key(dirname($realDirectory)) !== qd_path_key($realParent)
        || qd_path_is_within($realDirectory, $realWebRoot)) {
        throw new QDServerFault('unavailable');
    }
    @chmod($realDirectory, 0700);
    qd_journal_fsync_directory($realParent);
    return $realDirectory;
}

function qd_journal_file_path(string $directory, string $webRoot, string $name, ?string $privateRoot = null): string
{
    if (preg_match('/\A[a-f0-9]{64}(?:\.pending|\.recent)?\.json\z/D', $name) !== 1) {
        throw new QDServerFault('unavailable');
    }
    $managed = is_string($privateRoot) && qd_path_is_lexically_within($directory, $privateRoot);
    $path = $directory . DIRECTORY_SEPARATOR . $name . ($managed ? '.php' : '');
    $safePath = $managed
        ? qd_managed_file_path($path, $privateRoot, false)
        : qd_external_file_path($path, $webRoot, false);
    if ($safePath === null || is_link($path)) {
        throw new QDServerFault('unavailable');
    }
    return $safePath;
}

function qd_journal_transaction(array $settings, callable $callback): mixed
{
    $webRoot = $settings['webRoot'] ?? null;
    if (!is_string($webRoot)) {
        throw new QDServerFault('unavailable');
    }
    $directory = qd_journal_directory($settings);
    $privateRoot = is_string($settings['privateRoot'] ?? null) ? $settings['privateRoot'] : null;
    $managed = is_string($privateRoot) && qd_path_is_lexically_within($directory, $privateRoot);
    $lockCandidate = $directory . DIRECTORY_SEPARATOR . ($managed ? 'journal.lock.php' : 'journal.lock');
    $lockPath = $managed
        ? qd_managed_file_path($lockCandidate, $privateRoot, false)
        : qd_external_file_path($lockCandidate, $webRoot, false);
    if ($lockPath === null || is_link($lockPath)) {
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
        if ($managed) {
            $lockSize = @filesize($lockPath);
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
        return $callback($directory, $webRoot, $privateRoot);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function qd_journal_read_json(string $path, int $maxBytes): ?array
{
    if (is_link($path)) {
        throw new QDServerFault('unavailable');
    }
    if (!file_exists($path)) {
        return null;
    }
    if (!is_file($path)) {
        throw new QDServerFault('unavailable');
    }
    $guarded = str_ends_with(strtolower(basename($path)), '.json.php');
    $size = @filesize($path);
    if (!is_int($size) || $size > $maxBytes + ($guarded ? strlen(QD_GUARDED_JSON_PREFIX) : 0)) {
        throw new QDServerFault('unavailable');
    }
    $raw = @file_get_contents($path);
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
        $value = json_decode($raw, true, QD_MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new QDServerFault('unavailable');
    }
    if (!is_array($value)) {
        throw new QDServerFault('unavailable');
    }
    return $value;
}

function qd_journal_write_json(string $path, array $value, int $maxBytes): void
{
    try {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new QDServerFault('unavailable');
    }
    if (strlen($json) > $maxBytes) {
        throw new QDServerFault('unavailable');
    }
    qd_atomic_write($path, $json, str_ends_with(strtolower(basename($path)), '.json.php'));
    qd_journal_fsync_directory(dirname($path));
}

function qd_journal_delete_file(string $path): void
{
    if (is_link($path)) {
        throw new QDServerFault('unavailable');
    }
    if (file_exists($path)) {
        if (!is_file($path) || !@unlink($path)) {
            throw new QDServerFault('unavailable');
        }
        qd_journal_fsync_directory(dirname($path));
    }
}

function qd_journal_record_is_valid(array $record): bool
{
    return array_diff(array_keys($record), ['version', 'pairId', 'scopeHash', 'operation', 'operationId', 'payloadHash', 'semanticHash', 'state', 'commitEntered', 'dataJson']) === []
        && array_diff(['version', 'pairId', 'scopeHash', 'operation', 'operationId', 'payloadHash', 'semanticHash', 'state', 'commitEntered', 'dataJson'], array_keys($record)) === []
        && ($record['version'] ?? null) === 1
        && qd_is_uuid($record['pairId'] ?? null)
        && is_string($record['scopeHash'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $record['scopeHash']) === 1
        && in_array($record['operation'] ?? null, QD_MUTATION_OPERATIONS, true)
        && qd_is_uuid($record['operationId'] ?? null)
        && is_string($record['payloadHash'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $record['payloadHash']) === 1
        && is_string($record['semanticHash'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/D', $record['semanticHash']) === 1
        && in_array($record['state'] ?? null, ['pending', 'completed'], true)
        && is_bool($record['commitEntered'] ?? null)
        && (($record['dataJson'] ?? null) === null || (is_string($record['dataJson']) && strlen($record['dataJson']) <= QD_MAX_JOURNAL_RESULT_BYTES));
}

function qd_journal_load_record(string $directory, string $webRoot, string $key, ?string $privateRoot = null): ?array
{
    if (preg_match('/\A[a-f0-9]{64}\z/D', $key) !== 1) {
        throw new QDServerFault('unavailable');
    }
    $record = qd_journal_read_json(qd_journal_file_path($directory, $webRoot, $key . '.json', $privateRoot), QD_JOURNAL_RECORD_BYTES);
    if ($record !== null && !qd_journal_record_is_valid($record)) {
        throw new QDServerFault('unavailable');
    }
    return $record;
}

function qd_journal_semantic_key(string $pairId, string $scopeHash, string $operation, string $semanticHash): string
{
    return hash('sha256', implode("\0", [$pairId, $scopeHash, $operation, $semanticHash]));
}

function qd_journal_semantic_pointer(string $directory, string $webRoot, string $semanticKey, ?string $privateRoot = null): ?array
{
    $pointer = qd_journal_read_json(qd_journal_file_path($directory, $webRoot, $semanticKey . '.pending.json', $privateRoot), 256);
    if ($pointer === null) {
        return null;
    }
    $version = $pointer['version'] ?? null;
    $validV1 = $version === 1 && array_keys($pointer) === ['version', 'operationKey'];
    $validV2 = $version === 2 && array_keys($pointer) === ['version', 'operationKey', 'commitEntered']
        && is_bool($pointer['commitEntered'] ?? null);
    if ((!$validV1 && !$validV2)
        || !is_string($pointer['operationKey'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/D', $pointer['operationKey']) !== 1) {
        throw new QDServerFault('unavailable');
    }
    return $pointer;
}

function qd_journal_operation_key(array $record): string
{
    return hash('sha256', implode("\0", [$record['pairId'], $record['scopeHash'], $record['operation'], $record['operationId']]));
}

function qd_journal_assert_record_identity(array $record, string $operationKey, string $semanticKey): void
{
    if (!hash_equals(qd_journal_operation_key($record), $operationKey)
        || !hash_equals(qd_journal_semantic_key($record['pairId'], $record['scopeHash'], $record['operation'], $record['semanticHash']), $semanticKey)) {
        throw new QDServerFault('unavailable');
    }
}

function qd_journal_assert_pointer_record(array $pointer, string $operationKey, string $semanticKey, ?array $record): void
{
    if (($pointer['operationKey'] ?? null) !== $operationKey) {
        throw new QDServerFault('unavailable');
    }
    if ($record === null) {
        return;
    }
    qd_journal_assert_record_identity($record, $operationKey, $semanticKey);
    if (($pointer['version'] ?? null) === 2
        && (($pointer['commitEntered'] === false && $record['commitEntered'] === true)
            || ($pointer['commitEntered'] === false && ($record['state'] ?? null) === 'completed')
            || ($pointer['commitEntered'] === true && ($record['state'] ?? null) === 'completed' && $record['commitEntered'] === false))) {
        throw new QDServerFault('unavailable');
    }
}

function qd_journal_outstanding_count(string $directory, string $webRoot, ?string $privateRoot = null): int
{
    $managed = is_string($privateRoot) && qd_path_is_lexically_within($directory, $privateRoot);
    $files = glob($directory . DIRECTORY_SEPARATOR . ($managed ? '*.pending.json.php' : '*.pending.json'));
    if (!is_array($files)) {
        throw new QDServerFault('unavailable');
    }
    $count = 0;
    foreach ($files as $path) {
        $pattern = $managed ? '/\A([a-f0-9]{64})\.pending\.json\.php\z/D' : '/\A([a-f0-9]{64})\.pending\.json\z/D';
        if (preg_match($pattern, basename($path), $matches) !== 1) {
            throw new QDServerFault('unavailable');
        }
        $semanticKey = $matches[1];
        $pointer = qd_journal_semantic_pointer($directory, $webRoot, $semanticKey, $privateRoot);
        if ($pointer === null) {
            continue;
        }
        $operationKey = $pointer['operationKey'];
        $record = qd_journal_load_record($directory, $webRoot, $operationKey, $privateRoot);
        if ($record === null && ($pointer['version'] ?? null) === 2 && $pointer['commitEntered'] === false) {
            qd_journal_delete_file(qd_journal_file_path($directory, $webRoot, $semanticKey . '.pending.json', $privateRoot));
            continue;
        }
        if ($record === null) {
            $count++;
            continue;
        }
        qd_journal_assert_pointer_record($pointer, $operationKey, $semanticKey, $record);
        if (($record['state'] ?? null) === 'pending') {
            $count++;
        } else {
            qd_journal_delete_file(qd_journal_file_path($directory, $webRoot, $semanticKey . '.pending.json', $privateRoot));
        }
    }
    return $count;
}

function qd_journal_assert_legacy_empty(array $settings): void
{
    qd_server_state_transaction($settings, static function (array &$state): bool {
        if (($state['operations'] ?? null) !== []) {
            // Preserve old entries whose owner-commit outcome cannot be safely migrated here.
            throw new QDServerFault('unavailable');
        }
        return true;
    });
}

function qd_journal_begin(array $settings, string $pairId, string $scopeHash, string $operation, string $operationId, string $payloadHash, string $semanticHash, bool $ownerIdempotent = false): array
{
    qd_journal_assert_legacy_empty($settings);
    if (!qd_is_uuid($pairId) || preg_match('/\A[a-f0-9]{64}\z/D', $scopeHash) !== 1
        || !in_array($operation, QD_MUTATION_OPERATIONS, true) || !qd_is_uuid($operationId)
        || preg_match('/\A[a-f0-9]{64}\z/D', $payloadHash) !== 1 || preg_match('/\A[a-f0-9]{64}\z/D', $semanticHash) !== 1) {
        throw new QDServerFault('unavailable');
    }
    $key = hash('sha256', implode("\0", [$pairId, $scopeHash, $operation, $operationId]));
    $semanticKey = qd_journal_semantic_key($pairId, $scopeHash, $operation, $semanticHash);
    return qd_journal_transaction($settings, static function (string $directory, string $webRoot, ?string $privateRoot) use ($pairId, $scopeHash, $operation, $operationId, $payloadHash, $semanticHash, $key, $semanticKey, $ownerIdempotent): array {
        $existing = qd_journal_load_record($directory, $webRoot, $key, $privateRoot);
        if ($existing !== null) {
            $existingSemanticKey = qd_journal_semantic_key($existing['pairId'], $existing['scopeHash'], $existing['operation'], $existing['semanticHash']);
            qd_journal_assert_record_identity($existing, $key, $existingSemanticKey);
            $existingPointer = qd_journal_semantic_pointer($directory, $webRoot, $existingSemanticKey, $privateRoot);
            if (($existing['state'] ?? null) === 'pending' && $existingPointer === null) {
                throw new QDServerFault('unavailable');
            }
            if ($existingPointer !== null) {
                qd_journal_assert_pointer_record($existingPointer, $key, $existingSemanticKey, $existing);
                if (($existing['state'] ?? null) === 'completed') {
                    qd_journal_delete_file(qd_journal_file_path($directory, $webRoot, $existingSemanticKey . '.pending.json', $privateRoot));
                }
            }
            if (($existing['payloadHash'] ?? null) !== $payloadHash || ($existing['semanticHash'] ?? null) !== $semanticHash) {
                throw new QDServerFault('conflict');
            }
            if (($existing['state'] ?? null) === 'completed' && $ownerIdempotent) {
                return ['action' => 'owner_replay'];
            }
            if (($existing['state'] ?? null) === 'completed' && is_string($existing['dataJson'] ?? null)) {
                try {
                    $data = json_decode($existing['dataJson'], false, QD_MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new QDServerFault('unavailable');
                }
                if (!is_object($data)) {
                    throw new QDServerFault('unavailable');
                }
                return ['action' => 'replay', 'data' => $data];
            }
            throw new QDServerFault('conflict');
        }

        $pointerPath = qd_journal_file_path($directory, $webRoot, $semanticKey . '.pending.json', $privateRoot);
        $pointer = qd_journal_semantic_pointer($directory, $webRoot, $semanticKey, $privateRoot);
        if ($pointer !== null) {
            $pointedRecord = qd_journal_load_record($directory, $webRoot, $pointer['operationKey'], $privateRoot);
            if ($pointedRecord === null) {
                if (($pointer['version'] ?? null) !== 2 || $pointer['commitEntered'] !== false) {
                    throw new QDServerFault('conflict');
                }
            } else {
                qd_journal_assert_pointer_record($pointer, $pointer['operationKey'], $semanticKey, $pointedRecord);
                if (($pointedRecord['state'] ?? null) === 'pending') {
                    throw new QDServerFault('conflict');
                }
            }
            qd_journal_delete_file($pointerPath);
        }

        if (qd_journal_outstanding_count($directory, $webRoot, $privateRoot) >= QD_JOURNAL_MAX_OUTSTANDING) {
            throw new QDServerFault('unavailable');
        }

        // Reserve the semantic slot first: an interrupted second write then fails closed.
        qd_journal_write_json($pointerPath, ['version' => 2, 'operationKey' => $key, 'commitEntered' => false], 256);
        $recordPath = qd_journal_file_path($directory, $webRoot, $key . '.json', $privateRoot);
        qd_journal_write_json($recordPath, [
            'version' => 1,
            'pairId' => $pairId,
            'scopeHash' => $scopeHash,
            'operation' => $operation,
            'operationId' => $operationId,
            'payloadHash' => $payloadHash,
            'semanticHash' => $semanticHash,
            'state' => 'pending',
            'commitEntered' => false,
            'dataJson' => null,
        ], QD_JOURNAL_RECORD_BYTES);
        return ['action' => 'dispatch', 'key' => $key];
    });
}

function qd_journal_mark_commit(array $settings, string $key): void
{
    qd_journal_transaction($settings, static function (string $directory, string $webRoot, ?string $privateRoot) use ($key): bool {
        $record = qd_journal_load_record($directory, $webRoot, $key, $privateRoot);
        if ($record === null || ($record['state'] ?? null) !== 'pending') {
            throw new QDServerFault('unavailable');
        }
        $semanticKey = qd_journal_semantic_key($record['pairId'], $record['scopeHash'], $record['operation'], $record['semanticHash']);
        qd_journal_assert_record_identity($record, $key, $semanticKey);
        $pointer = qd_journal_semantic_pointer($directory, $webRoot, $semanticKey, $privateRoot);
        if ($pointer === null) {
            throw new QDServerFault('unavailable');
        }
        qd_journal_assert_pointer_record($pointer, $key, $semanticKey, $record);
        if (($pointer['version'] ?? null) !== 2 || $pointer['commitEntered'] !== true) {
            qd_journal_write_json(qd_journal_file_path($directory, $webRoot, $semanticKey . '.pending.json', $privateRoot), [
                'version' => 2,
                'operationKey' => $key,
                'commitEntered' => true,
            ], 256);
        }
        if (!$record['commitEntered']) {
            $record['commitEntered'] = true;
            qd_journal_write_json(qd_journal_file_path($directory, $webRoot, $key . '.json', $privateRoot), $record, QD_JOURNAL_RECORD_BYTES);
        }
        return true;
    });
}

function qd_journal_abandon_no_write(array $settings, string $key): void
{
    qd_journal_transaction($settings, static function (string $directory, string $webRoot, ?string $privateRoot) use ($key): bool {
        $record = qd_journal_load_record($directory, $webRoot, $key, $privateRoot);
        if ($record === null || ($record['state'] ?? null) !== 'pending') {
            return true;
        }
        $semanticKey = qd_journal_semantic_key($record['pairId'], $record['scopeHash'], $record['operation'], $record['semanticHash']);
        qd_journal_assert_record_identity($record, $key, $semanticKey);
        $pointer = qd_journal_semantic_pointer($directory, $webRoot, $semanticKey, $privateRoot);
        if ($pointer === null) {
            throw new QDServerFault('unavailable');
        }
        qd_journal_assert_pointer_record($pointer, $key, $semanticKey, $record);
        if (($pointer['version'] ?? null) !== 2 || $pointer['commitEntered'] !== false || $record['commitEntered'] === true) {
            return true;
        }
        // Delete the record first; an orphan pointer blocks a retry, while the reverse can admit one.
        qd_journal_delete_file(qd_journal_file_path($directory, $webRoot, $key . '.json', $privateRoot));
        $pointerPath = qd_journal_file_path($directory, $webRoot, $semanticKey . '.pending.json', $privateRoot);
        qd_journal_delete_file($pointerPath);
        return true;
    });
}

function qd_journal_recent_keys(string $directory, string $webRoot, string $pairId, ?string $privateRoot = null): array
{
    $path = qd_journal_file_path($directory, $webRoot, hash('sha256', $pairId) . '.recent.json', $privateRoot);
    $recent = qd_journal_read_json($path, 16 * 1024);
    if ($recent === null) {
        return [$path, []];
    }
    if (array_keys($recent) !== ['version', 'keys'] || ($recent['version'] ?? null) !== 1
        || !is_array($recent['keys'] ?? null) || !array_is_list($recent['keys']) || count($recent['keys']) > QD_JOURNAL_DETAIL_LIMIT) {
        throw new QDServerFault('unavailable');
    }
    $seen = [];
    foreach ($recent['keys'] as $recentKey) {
        if (!is_string($recentKey) || preg_match('/\A[a-f0-9]{64}\z/D', $recentKey) !== 1 || isset($seen[$recentKey])) {
            throw new QDServerFault('unavailable');
        }
        $seen[$recentKey] = true;
    }
    return [$path, $recent['keys']];
}

function qd_journal_complete(array $settings, string $key, string $dataJson): void
{
    if (strlen($dataJson) > QD_MAX_JOURNAL_RESULT_BYTES) {
        throw new QDServerFault('unavailable');
    }
    try {
        $data = json_decode($dataJson, false, QD_MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new QDServerFault('unavailable');
    }
    if (!is_object($data)) {
        throw new QDServerFault('unavailable');
    }
    qd_journal_transaction($settings, static function (string $directory, string $webRoot, ?string $privateRoot) use ($key, $dataJson): bool {
        $record = qd_journal_load_record($directory, $webRoot, $key, $privateRoot);
        if ($record === null || ($record['state'] ?? null) !== 'pending') {
            throw new QDServerFault('unavailable');
        }
        $semanticKey = qd_journal_semantic_key($record['pairId'], $record['scopeHash'], $record['operation'], $record['semanticHash']);
        qd_journal_assert_record_identity($record, $key, $semanticKey);
        $pointer = qd_journal_semantic_pointer($directory, $webRoot, $semanticKey, $privateRoot);
        if ($pointer === null) {
            throw new QDServerFault('unavailable');
        }
        qd_journal_assert_pointer_record($pointer, $key, $semanticKey, $record);
        if (($pointer['version'] ?? null) !== 2 || $pointer['commitEntered'] !== true) {
            qd_journal_write_json(qd_journal_file_path($directory, $webRoot, $semanticKey . '.pending.json', $privateRoot), [
                'version' => 2,
                'operationKey' => $key,
                'commitEntered' => true,
            ], 256);
        }
        if ($record['commitEntered'] === false) {
            $record['commitEntered'] = true;
            qd_journal_write_json(qd_journal_file_path($directory, $webRoot, $key . '.json', $privateRoot), $record, QD_JOURNAL_RECORD_BYTES);
        }
        [$recentPath, $recentKeys] = qd_journal_recent_keys($directory, $webRoot, $record['pairId'], $privateRoot);
        $recentKeys = array_values(array_filter($recentKeys, static fn(string $recentKey): bool => $recentKey !== $key));
        while (count($recentKeys) >= QD_JOURNAL_DETAIL_LIMIT) {
            $evictedKey = array_shift($recentKeys);
            $evicted = qd_journal_load_record($directory, $webRoot, $evictedKey, $privateRoot);
            if ($evicted !== null && ($evicted['state'] ?? null) === 'completed' && is_string($evicted['dataJson'] ?? null)) {
                $evicted['dataJson'] = null;
                qd_journal_write_json(qd_journal_file_path($directory, $webRoot, $evictedKey . '.json', $privateRoot), $evicted, QD_JOURNAL_RECORD_BYTES);
            }
        }
        $recentKeys[] = $key;
        qd_journal_write_json($recentPath, ['version' => 1, 'keys' => $recentKeys], 16 * 1024);

        $record['state'] = 'completed';
        $record['dataJson'] = $dataJson;
        qd_journal_write_json(qd_journal_file_path($directory, $webRoot, $key . '.json', $privateRoot), $record, QD_JOURNAL_RECORD_BYTES);

        $pointerPath = qd_journal_file_path($directory, $webRoot, $semanticKey . '.pending.json', $privateRoot);
        qd_journal_delete_file($pointerPath);
        return true;
    });
}
