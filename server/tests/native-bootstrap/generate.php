<?php
declare(strict_types=1);

final class QDNativeBootstrapFixtureFailure extends RuntimeException
{
}

function qd_nb_fail(string $reason): never
{
    throw new QDNativeBootstrapFixtureFailure($reason);
}

function qd_nb_expect(bool $condition, string $reason): void
{
    if (!$condition) {
        qd_nb_fail($reason);
    }
}

function qd_nb_same_path(string $left, string $right): bool
{
    $left = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $left), '\\/');
    $right = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $right), '\\/');
    return DIRECTORY_SEPARATOR === '\\'
        ? strtolower($left) === strtolower($right)
        : $left === $right;
}

function qd_nb_within(string $path, string $root): bool
{
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), '\\/');
    $pathKey = DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    $rootKey = DIRECTORY_SEPARATOR === '\\' ? strtolower($root) : $root;
    return $pathKey === $rootKey || str_starts_with($pathKey, $rootKey . DIRECTORY_SEPARATOR);
}

function qd_nb_is_absolute_path(string $path): bool
{
    return !str_contains($path, "\0")
        && (str_starts_with($path, '/')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) === 1
            || str_starts_with($path, '\\\\'));
}

function qd_nb_arguments(array $argv): array
{
    $options = [];
    for ($i = 1, $count = count($argv); $i < $count; $i += 2) {
        $key = $argv[$i] ?? null;
        $value = $argv[$i + 1] ?? null;
        if (!is_string($key) || !in_array($key, ['--output', '--state-root'], true)
            || isset($options[$key]) || !is_string($value) || $value === '') {
            qd_nb_fail('arguments_invalid');
        }
        $options[$key] = $value;
    }
    if (count($options) !== 2 || !isset($options['--output'], $options['--state-root'])) {
        qd_nb_fail('arguments_invalid');
    }

    $outputInput = $options['--output'];
    $stateInput = $options['--state-root'];
    if (!qd_nb_is_absolute_path($outputInput) || !qd_nb_is_absolute_path($stateInput)) {
        qd_nb_fail('paths_must_be_absolute');
    }
    $outputParent = realpath(dirname($outputInput));
    $stateParent = realpath(dirname($stateInput));
    if (!is_string($outputParent) || !is_dir($outputParent) || is_link(dirname($outputInput))
        || !is_string($stateParent) || !is_dir($stateParent) || is_link(dirname($stateInput))
        || !qd_nb_same_path($outputParent, dirname($outputInput))
        || !qd_nb_same_path($stateParent, $outputParent)) {
        qd_nb_fail('output_path_invalid');
    }

    $output = $outputParent . DIRECTORY_SEPARATOR . basename($outputInput);
    $stateRoot = $outputParent . DIRECTORY_SEPARATOR . basename($stateInput);
    if (basename($outputInput) === '' || basename($outputInput) === '.' || basename($outputInput) === '..'
        || basename($stateInput) === '' || basename($stateInput) === '.' || basename($stateInput) === '..'
        || !qd_nb_same_path($output, $outputInput) || !qd_nb_same_path($stateRoot, $stateInput)
        || file_exists($output) || is_link($output)) {
        qd_nb_fail('output_path_invalid');
    }
    if (file_exists($stateRoot) || is_link($stateRoot)) {
        qd_nb_fail('state_root_not_fresh');
    }
    return ['output' => $output, 'stateRoot' => $stateRoot, 'outputParent' => $outputParent];
}

function qd_nb_make_subdirectory(string $path, string $ownedRoot): void
{
    if (file_exists($path) || is_link($path) || !@mkdir($path, 0700)) {
        qd_nb_fail('fixture_state_create_failed');
    }
    $realPath = realpath($path);
    $realRoot = realpath($ownedRoot);
    if (!is_string($realPath) || !is_string($realRoot) || is_link($path)
        || !qd_nb_same_path($realPath, $path) || !qd_nb_within($realPath, $realRoot)) {
        qd_nb_fail('fixture_state_path_invalid');
    }
    @chmod($realPath, 0700);
}

function qd_nb_remove_owned_tree(string $path, string $ownedRoot): void
{
    if (is_link($path)) {
        qd_nb_fail('state_cleanup_failed');
    }
    if (!file_exists($path)) {
        return;
    }
    $realPath = realpath($path);
    $realRoot = realpath($ownedRoot);
    if (!is_string($realPath) || !is_string($realRoot) || !qd_nb_same_path($realPath, $path)
        || !qd_nb_within($realPath, $realRoot)) {
        qd_nb_fail('state_cleanup_failed');
    }
    if (is_dir($realPath)) {
        foreach (new DirectoryIterator($realPath) as $item) {
            if (!$item->isDot()) {
                qd_nb_remove_owned_tree($item->getPathname(), $realRoot);
            }
        }
        if (!@rmdir($realPath)) {
            qd_nb_fail('state_cleanup_failed');
        }
        return;
    }
    if (!@unlink($realPath)) {
        qd_nb_fail('state_cleanup_failed');
    }
}

function qd_nb_source_files(string $repoRoot, string $serverRoot): array
{
    $repoRoot = realpath($repoRoot);
    $serverRoot = realpath($serverRoot);
    if (!is_string($repoRoot) || !is_string($serverRoot)) {
        qd_nb_fail('source_root_invalid');
    }
    $rootPrefix = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $repoRoot), '\\/') . DIRECTORY_SEPARATOR;
    $rootKey = DIRECTORY_SEPARATOR === '\\' ? strtolower($rootPrefix) : $rootPrefix;
    $files = [];
    foreach (get_included_files() as $included) {
        $real = realpath($included);
        if (!is_string($real) || !qd_nb_within($real, $serverRoot)) {
            continue;
        }
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $real);
        $normalizedKey = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
        if (!str_starts_with($normalizedKey, $rootKey)) {
            continue;
        }
        $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($normalized, strlen($rootPrefix)));
    }
    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);
    foreach (['server/api.php', 'server/lib/auth.php', 'server/lib/dispatcher.php', 'server/lib/protocol.php'] as $required) {
        if (!in_array($required, $files, true)) {
            qd_nb_fail('source_boundary_incomplete');
        }
    }
    return $files;
}

function qd_nb_assert_context_state(array $settings, array $pair, object $request, object $data): void
{
    $pairRaw = @file_get_contents($settings['pairingPath']);
    qd_nb_expect(is_string($pairRaw) && str_starts_with($pairRaw, QD_GUARDED_JSON_PREFIX), 'pairing_state_invalid');
    $pairing = json_decode(substr($pairRaw, strlen(QD_GUARDED_JSON_PREFIX)), true, 8, JSON_THROW_ON_ERROR);
    $storedPair = null;
    foreach ($pairing['pairs'] ?? [] as $entry) {
        if (($entry['pairId'] ?? null) === $pair['pairId']) {
            $storedPair = $entry;
            break;
        }
    }
    qd_nb_expect(is_array($storedPair) && ($storedPair['tokenHash'] ?? null) === hash('sha256', $pair['token'])
        && ($storedPair['scopes'] ?? null) === $pair['scopes'] && !str_contains($pairRaw, $pair['token']), 'pairing_state_invalid');

    $stateRaw = @file_get_contents($settings['statePath']);
    qd_nb_expect(is_string($stateRaw) && str_starts_with($stateRaw, QD_GUARDED_JSON_PREFIX), 'server_context_state_invalid');
    $state = json_decode(substr($stateRaw, strlen(QD_GUARDED_JSON_PREFIX)), true, 8, JSON_THROW_ON_ERROR);
    $record = $state['contexts'][$pair['pairId']] ?? null;
    qd_nb_expect(is_array($record)
        && ($record['contextHash'] ?? null) === hash('sha256', $data->serverContext)
        && ($record['viewToken'] ?? null) === $request->context->viewToken
        && ($record['nativeGeneration'] ?? null) === $request->context->nativeGeneration
        && ($record['scopeHash'] ?? null) === hash('sha256', 'native-bootstrap-fixture-scope')
        && ($record['scopeToken'] ?? null) === $data->scopeToken, 'server_context_state_invalid');
}

function qd_nb_case(array $scenario, string $stateRoot): array
{
    $caseRoot = $stateRoot . DIRECTORY_SEPARATOR . $scenario['name'];
    qd_nb_make_subdirectory($caseRoot, $stateRoot);
    $webRoot = $caseRoot . DIRECTORY_SEPARATOR . 'web';
    qd_nb_make_subdirectory($webRoot, $stateRoot);
    $dataRoot = $caseRoot . DIRECTORY_SEPARATOR . 'data';
    qd_nb_make_subdirectory($dataRoot, $stateRoot);
    $privateRoot = $dataRoot . DIRECTORY_SEPARATOR . 'questionable_decisions-private';
    qd_nb_make_subdirectory($privateRoot, $stateRoot);
    $settings = [
        'pairingPath' => $privateRoot . DIRECTORY_SEPARATOR . 'pairing.php',
        'statePath' => $privateRoot . DIRECTORY_SEPARATOR . 'state.php',
        'webRoot' => $webRoot,
        'privateRoot' => $privateRoot,
        'coreRoot' => null,
        'chimRoot' => null,
        'pcvRoot' => null,
    ];

    $pair = null;
    $authorization = null;
    if ($scenario['authenticated']) {
        $pair = qd_pair_issue($settings['pairingPath'], $webRoot, $scenario['scopes'], $privateRoot);
        $authorization = 'Bearer ' . $pair['token'];
    }

    $request = (object)[
        'version' => 1,
        'requestId' => qd_new_uuid(),
        'operation' => 'bootstrap',
        'context' => (object)[
            'viewToken' => qd_new_uuid(),
            'nativeGeneration' => '0',
            'serverContext' => null,
        ],
        'payload' => (object)[],
    ];
    $requestBody = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $trace = ['lease' => 0, 'scope' => 0, 'readiness' => 0, 'perspectives' => 0];
    $dependencies = [
        'withProfileLease' => static function (callable $callback) use (&$trace): mixed {
            $trace['lease']++;
            return $callback();
        },
        'currentScope' => static function (string $pairId) use (&$trace, $pair): string {
            $trace['scope']++;
            if (!is_array($pair) || $pairId !== $pair['pairId']) {
                throw new RuntimeException('fixture scope mismatch');
            }
            return 'native-bootstrap-fixture-scope';
        },
        'readiness' => static function (bool $includePcv = true) use (&$trace, $scenario): array {
            $trace['readiness']++;
            if ($includePcv) {
                throw new RuntimeException('unexpected PCV owner readiness');
            }
            return $scenario['readiness'];
        },
        'perspectives' => static function (string $scopeKey) use (&$trace): array {
            $trace['perspectives']++;
            if ($scopeKey !== 'native-bootstrap-fixture-scope') {
                throw new RuntimeException('fixture perspective scope mismatch');
            }
            return [
                'mp' => ['choices' => [], 'limited' => true],
                'sas' => ['choices' => [], 'limited' => true],
            ];
        },
    ];
    $response = qd_api_handle('POST', 'application/json', $requestBody, $authorization, $settings, $dependencies);
    qd_nb_expect(($response['status'] ?? null) === 200
        && ($response['headers']['Content-Type'] ?? null) === 'application/json; charset=utf-8'
        && is_string($response['body'] ?? null), 'api_response_invalid');
    $decoded = json_decode($response['body'], false, 32, JSON_THROW_ON_ERROR);
    qd_nb_expect(is_object($decoded) && ($decoded->version ?? null) === 1
        && ($decoded->requestId ?? null) === $request->requestId
        && ($decoded->operation ?? null) === 'bootstrap' && ($decoded->ok ?? null) === true
        && is_object($decoded->context ?? null) && get_object_vars($decoded->context) === get_object_vars($request->context)
        && is_object($decoded->data ?? null), 'api_response_envelope_invalid');
    $data = $decoded->data;
    qd_nb_expect(($data->authenticated ?? null) === $scenario['authenticated']
        && ($data->authRequired ?? null) === !$scenario['authenticated'], 'api_authentication_expectation_invalid');
    qd_nb_expect(is_object($data->capabilities ?? null) && get_object_vars($data->capabilities) === $scenario['capabilities']
        && is_object($data->access ?? null) && get_object_vars($data->access) === $scenario['access'], 'api_bootstrap_maps_invalid');
    qd_nb_expect(($data->capabilities->brainwash ?? null) === $scenario['brainwash']
        && ($data->access->brainwash ?? null) === $scenario['brainwash'], 'api_brainwash_expectation_invalid');

    if ($scenario['authenticated']) {
        qd_nb_expect(is_string($data->serverContext ?? null) && preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $data->scopeToken ?? '') === 1,
            'api_authenticated_context_invalid');
        qd_nb_expect($trace === [
            'lease' => 1,
            'scope' => 2,
            'readiness' => 1,
            'perspectives' => $scenario['access']['inspector'] ? 1 : 0,
        ], 'fixture_dependency_trace_invalid');
        qd_nb_assert_context_state($settings, $pair, $request, $data);
        qd_nb_expect(!str_contains($requestBody, $pair['token']) && !str_contains($response['body'], $pair['token']), 'credential_leak');
    } else {
        qd_nb_expect(property_exists($data, 'serverContext') && $data->serverContext === null
            && property_exists($data, 'scopeToken') && $data->scopeToken === null
            && $trace === ['lease' => 0, 'scope' => 0, 'readiness' => 0, 'perspectives' => 0], 'unauthenticated_bootstrap_invalid');
    }

    return [
        'name' => $scenario['name'],
        'requestBody' => $requestBody,
        'responseStatus' => $response['status'],
        'responseBody' => $response['body'],
        'authenticated' => $scenario['authenticated'],
        'brainwash' => $scenario['brainwash'],
    ];
}

function qd_nb_write_new_file(string $path, string $bytes): void
{
    $handle = @fopen($path, 'xb');
    if ($handle === false) {
        qd_nb_fail('output_write_failed');
    }
    $ok = false;
    try {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) {
                qd_nb_fail('output_write_failed');
            }
            $offset += $written;
        }
        if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
            qd_nb_fail('output_write_failed');
        }
        @chmod($path, 0600);
        $ok = true;
    } finally {
        fclose($handle);
        if (!$ok) {
            @unlink($path);
        }
    }
}

try {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    set_error_handler(static function (int $severity): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new RuntimeException('php_runtime_error');
    });
    $paths = qd_nb_arguments($argv);
    $serverRoot = dirname(__DIR__, 2);
    $repoRoot = dirname($serverRoot);
    $artifactRoots = [];
    $repoArtifactPath = $repoRoot . DIRECTORY_SEPARATOR . 'artifacts';
    $repoArtifactRoot = realpath($repoArtifactPath);
    if (is_string($repoArtifactRoot) && is_dir($repoArtifactRoot) && !is_link($repoArtifactPath)) {
        $artifactRoots[] = $repoArtifactRoot;
    }
    if (basename(dirname($repoRoot)) === 'worktrees') {
        $workspaceArtifactPath = dirname(dirname($repoRoot)) . DIRECTORY_SEPARATOR . 'artifacts';
        $workspaceArtifactRoot = realpath($workspaceArtifactPath);
        if (is_string($workspaceArtifactRoot) && is_dir($workspaceArtifactRoot) && !is_link($workspaceArtifactPath)) {
            $artifactRoots[] = $workspaceArtifactRoot;
        }
    }
    $outputAllowed = false;
    foreach ($artifactRoots as $artifactRoot) {
        $outputAllowed = $outputAllowed || qd_nb_within($paths['outputParent'], $artifactRoot);
    }
    qd_nb_expect($outputAllowed, 'output_outside_artifacts');
    ob_start();
    require_once $serverRoot . DIRECTORY_SEPARATOR . 'api.php';
    $sourceOutput = ob_get_clean();
    qd_nb_expect($sourceOutput === '', 'source_emitted_output');
    $sourceFiles = qd_nb_source_files($repoRoot, $serverRoot);

    $allFalse = ['pcv' => false, 'mp' => false, 'sas' => false, 'correspondence' => false, 'brainwash' => false];
    $noAccess = ['pcv' => false, 'inspector' => false, 'compose' => false, 'authorDetail' => false, 'brainwash' => false];
    $allAccess = ['pcv' => true, 'inspector' => true, 'compose' => true, 'authorDetail' => true, 'brainwash' => true];
    $readiness = ['pcv' => true, 'mp' => true, 'sas' => true, 'correspondence' => true, 'brainwash' => true];
    $scenarios = [
        [
            'name' => 'unauthenticated_bootstrap', 'authenticated' => false, 'scopes' => [], 'brainwash' => false,
            'readiness' => $allFalse, 'capabilities' => $allFalse, 'access' => $noAccess,
        ],
        [
            'name' => 'authenticated_all_readiness_disabled', 'authenticated' => true, 'scopes' => ['inspector'], 'brainwash' => false,
            'readiness' => $allFalse, 'capabilities' => $allFalse,
            'access' => ['pcv' => false, 'inspector' => true, 'compose' => false, 'authorDetail' => false, 'brainwash' => false],
        ],
        [
            'name' => 'authenticated_full_access', 'authenticated' => true, 'scopes' => ['pcv', 'inspector', 'compose', 'author_detail', 'brainwash'], 'brainwash' => true,
            'readiness' => $readiness,
            'capabilities' => ['pcv' => false, 'mp' => true, 'sas' => true, 'correspondence' => true, 'brainwash' => true],
            'access' => $allAccess,
        ],
        [
            'name' => 'authenticated_brainwash_only', 'authenticated' => true, 'scopes' => ['brainwash'], 'brainwash' => true,
            'readiness' => $readiness,
            'capabilities' => ['pcv' => false, 'mp' => true, 'sas' => true, 'correspondence' => true, 'brainwash' => true],
            'access' => ['pcv' => false, 'inspector' => false, 'compose' => false, 'authorDetail' => false, 'brainwash' => true],
        ],
    ];

    if (!@mkdir($paths['stateRoot'], 0700) || is_link($paths['stateRoot'])) {
        qd_nb_fail('state_root_create_failed');
    }
    @chmod($paths['stateRoot'], 0700);
    $stateRootReal = realpath($paths['stateRoot']);
    if (!is_string($stateRootReal) || !qd_nb_same_path($stateRootReal, $paths['stateRoot'])) {
        qd_nb_fail('state_root_invalid');
    }
    $cases = [];
    try {
        foreach ($scenarios as $scenario) {
            $cases[] = qd_nb_case($scenario, $stateRootReal);
        }
    } finally {
        qd_nb_remove_owned_tree($stateRootReal, $stateRootReal);
    }
    qd_nb_expect(!file_exists($paths['stateRoot']) && !is_link($paths['stateRoot']), 'state_cleanup_failed');

    $fixture = [
        'schemaVersion' => 1,
        'sourceFiles' => $sourceFiles,
        'stateCleanup' => 'removed',
        'cases' => $cases,
    ];
    $json = json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    qd_nb_expect(!str_starts_with($json, "\xEF\xBB\xBF") && !str_contains($json, "\r"), 'output_encoding_invalid');
    qd_nb_write_new_file($paths['output'], $json);
    fwrite(STDOUT, 'PASS native bootstrap fixtures cases=' . count($cases) . ' stateCleanup=removed' . "\n");
    exit(0);
} catch (Throwable $error) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $reason = $error instanceof QDNativeBootstrapFixtureFailure ? $error->getMessage() : 'internal_error';
    fwrite(STDERR, 'FAIL native bootstrap fixtures reason=' . $reason . "\n");
    exit(1);
}
