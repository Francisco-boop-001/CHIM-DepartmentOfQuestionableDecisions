<?php
declare(strict_types=1);

// PCV_INSTALLATION_GUARD_MARKER_V120
return (static function (): bool {
    $cacheKey = '__PCV_INSTALLATION_GUARD_CACHE_V120';
    $decisionKey = '__PCV_INSTALLATION_GUARD_DECISION_V120';
    $currentPath = realpath(__DIR__);
    $extensionRoot = realpath(dirname(__DIR__));
    if ($currentPath === false || $extensionRoot === false || is_link(__DIR__)) {
        $GLOBALS[$decisionKey] = ['status' => 'ambiguous', 'reason' => 'The installation path could not be resolved safely.'];
        return false;
    }

    $pathKey = static fn(string $path): string => DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    $samePath = static fn(string $left, string $right): bool => $pathKey($left) === $pathKey($right);
    $cacheKeyPath = $pathKey($extensionRoot);
    $cache = $GLOBALS[$cacheKey] ?? [];
    $decision = is_array($cache) ? ($cache[$cacheKeyPath] ?? null) : null;
    if (!is_array($decision)) {
        $knownNames = ['private_conversation', 'CHIM-PrivateConversation'];
        $entries = @scandir($extensionRoot);
        $candidates = [];
        $failure = is_array($entries) ? null : 'The extension folder could not be inspected safely.';

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $candidatePath = $extensionRoot . DIRECTORY_SEPARATOR . $entry;
            $canonicalPath = realpath($candidatePath);
            $knownPath = in_array($entry, $knownNames, true);
            $currentCopy = is_string($canonicalPath) && $samePath($canonicalPath, $currentPath);
            if (!file_exists($candidatePath) && !is_link($candidatePath)) {
                continue;
            }
            if (is_link($candidatePath) || !is_dir($candidatePath)) {
                if ($knownPath || $currentCopy) {
                    $failure = 'A known Private Conversation installation path is not a regular folder.';
                    break;
                }
                continue;
            }

            $manifestPath = $candidatePath . DIRECTORY_SEPARATOR . 'manifest.json';
            if (!is_file($manifestPath) || is_link($manifestPath) || !is_readable($manifestPath)) {
                if ($knownPath || $currentCopy) {
                    $failure = 'A known Private Conversation installation has no readable manifest.';
                    break;
                }
                continue;
            }
            try {
                $manifest = json_decode((string)file_get_contents($manifestPath), true, 16, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $manifest = null;
            }
            $internalManifest = is_array($manifest) && ($manifest['name'] ?? null) === 'private_conversation';
            $generatedManifest = is_array($manifest)
                && ($manifest['name'] ?? null) === $entry
                && ($manifest['git_repo'] ?? null) === 'Francisco-boop-001/CHIM-PrivateConversation'
                && ($manifest['source'] ?? null) === 'github-release';
            if (!$internalManifest && !$generatedManifest) {
                if ($knownPath || $currentCopy) {
                    $failure = 'A known Private Conversation installation has invalid metadata.';
                    break;
                }
                continue;
            }

            $version = $manifest['version'] ?? null;
            if (is_string($version) && str_starts_with($version, 'private_conversation-v')) {
                $version = substr($version, strlen('private_conversation-v'));
            }
            if (!is_string($version) || preg_match('/\\A\\d+\\.\\d+\\.\\d+(?:[-+][0-9A-Za-z.-]+)?\\z/', $version) !== 1) {
                $failure = 'A Private Conversation manifest has no valid version.';
                break;
            }

            $guardPath = $candidatePath . DIRECTORY_SEPARATOR . 'installation_guard.php';
            $guardExists = file_exists($guardPath) || is_link($guardPath);
            if ($guardExists) {
                $guardContents = is_file($guardPath) && !is_link($guardPath) && is_readable($guardPath)
                    ? @file_get_contents($guardPath) : false;
                if (!is_string($guardContents) || !str_contains($guardContents, 'PCV_INSTALLATION_GUARD_MARKER_V120')) {
                    $failure = 'A Private Conversation installation guard is incomplete.';
                    break;
                }
                $generation = 'guarded';
            } elseif (version_compare($version, '0.1.20', '>=')) {
                $failure = 'A guarded Private Conversation release is missing its installation guard.';
                break;
            } else {
                $generation = 'legacy';
            }

            if (!is_string($canonicalPath)) {
                $failure = 'A Private Conversation installation path could not be resolved safely.';
                break;
            }
            $candidates[] = ['name' => $entry, 'path' => $canonicalPath, 'generation' => $generation];
        }

        if ($failure === null) {
            $currentFound = false;
            foreach ($candidates as $candidate) {
                if ($samePath($candidate['path'], $currentPath)) {
                    $currentFound = true;
                    break;
                }
            }
            if (!$currentFound) {
                $failure = 'This folder is not a complete Private Conversation installation.';
            }
        }

        if ($failure !== null || $candidates === []) {
            $decision = ['status' => 'ambiguous', 'reason' => $failure ?? 'No complete Private Conversation installation was found.', 'owner' => null, 'owner_legacy' => false];
        } else {
            $legacy = array_values(array_filter($candidates, static fn(array $candidate): bool => $candidate['generation'] === 'legacy'));
            $owners = $legacy !== [] ? $legacy : $candidates;
            usort($owners, static function (array $left, array $right) use ($pathKey): int {
                $rank = static fn(string $name): int => match ($name) {
                    'private_conversation' => 0,
                    'CHIM-PrivateConversation' => 1,
                    default => 2,
                };
                return ($rank($left['name']) <=> $rank($right['name']))
                    ?: strcmp($pathKey($left['path']), $pathKey($right['path']));
            });
            $owner = $owners[0];
            $decision = [
                'status' => 'ready',
                'reason' => $owner['generation'] === 'legacy' ? 'legacy_owner' : 'duplicate_owner',
                'owner' => $owner['path'],
                'owner_name' => $owner['name'],
                'owner_legacy' => $owner['generation'] === 'legacy',
            ];
        }

        $cache[$cacheKeyPath] = $decision;
        $GLOBALS[$cacheKey] = $cache;
    }

    $GLOBALS[$decisionKey] = $decision;
    return ($decision['status'] ?? null) === 'ready'
        && is_string($decision['owner'] ?? null)
        && $samePath($decision['owner'], $currentPath);
})();
