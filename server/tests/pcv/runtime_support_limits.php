<?php
declare(strict_types=1);

define('PGSQL_TRANSACTION_IDLE', 0);
define('PGSQL_TRANSACTION_INTRANS', 2);
define('PGSQL_TRANSACTION_INERROR', 3);
define('PGSQL_CONNECTION_OK', 0);
define('PGSQL_COMMAND_OK', 1);

final class QDProfileReadTestConnection
{
    public int $status = PGSQL_TRANSACTION_IDLE;
    public int $lockTimeoutMs = 0;
    public int $statementTimeoutMs = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    public int $closed = 0;
    public bool $readOnly = false;
    public array $events = [];
    public array $savepoints = [];
    public array $work = [];
    public array $seenLimits = [];
    public array $catalogRows = [];
    public ?string $fault = null;
    public array $transactionSettings = [];
}

final class QDProfileReadTestResult
{
    public int $position = 0;

    public function __construct(public array $rows)
    {
    }
}

function pg_transaction_status($connection): int
{
    return $connection->status;
}

function pg_connection_status($connection): int
{
    return PGSQL_CONNECTION_OK;
}

function pg_query($connection, string $query): QDProfileReadTestResult|false
{
    $query = strtoupper(trim($query));
    $connection->events[] = $query;
    if ($connection->status === PGSQL_TRANSACTION_INERROR
        && !str_starts_with($query, 'ROLLBACK')) {
        return false;
    }
    if (str_starts_with($query, 'BEGIN ')) {
        if ($connection->status !== PGSQL_TRANSACTION_IDLE) {
            return false;
        }
        $connection->status = PGSQL_TRANSACTION_INTRANS;
        $connection->readOnly = str_contains($query, 'READ ONLY');
        $connection->transactionSettings = [$connection->lockTimeoutMs, $connection->statementTimeoutMs];
        return new QDProfileReadTestResult([]);
    }
    if (preg_match('/\ASET LOCAL (LOCK_TIMEOUT|STATEMENT_TIMEOUT) = \'([0-9]+)MS\'\z/', $query, $matches) === 1) {
        if ($connection->status !== PGSQL_TRANSACTION_INTRANS) {
            return false;
        }
        $property = strtolower($matches[1]) === 'lock_timeout' ? 'lockTimeoutMs' : 'statementTimeoutMs';
        $connection->{$property} = (int)$matches[2];
        $connection->events[] = 'apply_owner_pg_limit:' . strtolower($matches[1]);
        return new QDProfileReadTestResult([]);
    }
    if ($query === 'SELECT * FROM PUBLIC.CORE_NPC_MASTER') {
        $connection->events[] = 'read_catalog';
        if ($connection->lockTimeoutMs < 1 || $connection->lockTimeoutMs > 2000
            || $connection->statementTimeoutMs < 1 || $connection->statementTimeoutMs > 3000) {
            throw new RuntimeException('catalog read started before its timeout guard');
        }
        $connection->seenLimits[] = [$connection->lockTimeoutMs, $connection->statementTimeoutMs];
        return new QDProfileReadTestResult($connection->catalogRows);
    }
    if (preg_match('/\ASAVEPOINT ([A-Z_]+)\z/', $query, $matches) === 1) {
        $connection->savepoints[] = [
            'name' => $matches[1],
            'settings' => [$connection->lockTimeoutMs, $connection->statementTimeoutMs],
            'work' => $connection->work,
        ];
        return new QDProfileReadTestResult([]);
    }
    if (preg_match('/\AROLLBACK TO SAVEPOINT ([A-Z_]+)\z/', $query, $matches) === 1) {
        for ($i = count($connection->savepoints) - 1; $i >= 0; $i--) {
            if ($connection->savepoints[$i]['name'] === $matches[1]) {
                [$connection->lockTimeoutMs, $connection->statementTimeoutMs] = $connection->savepoints[$i]['settings'];
                $connection->work = $connection->savepoints[$i]['work'];
                $connection->status = PGSQL_TRANSACTION_INTRANS;
                return new QDProfileReadTestResult([]);
            }
        }
        return false;
    }
    if (preg_match('/\ARELEASE SAVEPOINT ([A-Z_]+)\z/', $query, $matches) === 1) {
        for ($i = count($connection->savepoints) - 1; $i >= 0; $i--) {
            if ($connection->savepoints[$i]['name'] === $matches[1]) {
                array_splice($connection->savepoints, $i, 1);
                return new QDProfileReadTestResult([]);
            }
        }
        return false;
    }
    if ($query === 'COMMIT') {
        if ($connection->status === PGSQL_TRANSACTION_INERROR) {
            return false;
        }
        $connection->status = PGSQL_TRANSACTION_IDLE;
        $connection->readOnly = false;
        [$connection->lockTimeoutMs, $connection->statementTimeoutMs] = $connection->transactionSettings;
        $connection->commits++;
        return new QDProfileReadTestResult([]);
    }
    if ($query === 'ROLLBACK') {
        $connection->status = PGSQL_TRANSACTION_IDLE;
        $connection->readOnly = false;
        [$connection->lockTimeoutMs, $connection->statementTimeoutMs] = $connection->transactionSettings;
        $connection->rollbacks++;
        return new QDProfileReadTestResult([]);
    }
    return false;
}

function pg_query_params($connection, string $query, array $params): QDProfileReadTestResult|false
{
    $connection->events[] = $query;
    $normalized = strtolower($query);
    if ($connection->status === PGSQL_TRANSACTION_INERROR) {
        return false;
    }
    if (str_contains($normalized, 'from pg_settings')) {
        if (str_contains($normalized, 'select (select setting::integer')) {
            $connection->events[] = 'read_owner_pg_settings';
            return new QDProfileReadTestResult([[
                'lock_timeout' => (string)$connection->lockTimeoutMs,
                'statement_timeout' => (string)$connection->statementTimeoutMs,
            ]]);
        }
        $connection->events[] = 'read_pg_settings';
        return new QDProfileReadTestResult([
            ['name' => 'lock_timeout', 'setting_ms' => (string)$connection->lockTimeoutMs,
                'setting_text' => $connection->lockTimeoutMs === 0 ? '0' : $connection->lockTimeoutMs . 'ms'],
            ['name' => 'statement_timeout', 'setting_ms' => (string)$connection->statementTimeoutMs,
                'setting_text' => $connection->statementTimeoutMs === 0 ? '0' : $connection->statementTimeoutMs . 'ms'],
        ]);
    }
    if (str_contains($normalized, 'set_config(')) {
        if (count($params) !== 2
            || preg_match('/\A([0-9]+)ms\z/', (string)$params[0], $lock) !== 1
            || preg_match('/\A([0-9]+)ms\z/', (string)$params[1], $statement) !== 1) {
            return false;
        }
        $connection->lockTimeoutMs = (int)$lock[1];
        $connection->statementTimeoutMs = (int)$statement[1];
        $connection->events[] = 'apply_pg_limits';
        return new QDProfileReadTestResult([['lock_timeout' => $params[0], 'statement_timeout' => $params[1]]]);
    }
    if (str_contains($normalized, 'to_regclass(')) {
        return new QDProfileReadTestResult([['profile_table' => 'chim_meta.playthrough_profiles']]);
    }
    if (str_contains($normalized, 'from chim_meta.playthrough_profiles')) {
        return new QDProfileReadTestResult([[
            'id' => '17',
            'schema_name' => 'chim_profile_test',
            'storage_type' => 'schema',
            'is_active' => 't',
            'schema_available' => 't',
        ]]);
    }
    return false;
}

function pg_num_rows(QDProfileReadTestResult $result): int
{
    return count($result->rows);
}

function pg_fetch_assoc(QDProfileReadTestResult $result): array|false
{
    return $result->rows[$result->position++] ?? false;
}

function pg_fetch_all(QDProfileReadTestResult $result): array|false
{
    return $result->rows;
}

function pg_result_status(QDProfileReadTestResult $result): int
{
    return PGSQL_COMMAND_OK;
}

function pg_free_result(QDProfileReadTestResult $result): bool
{
    return true;
}

function pg_close(QDProfileReadTestConnection $connection): bool
{
    $connection->events[] = 'close';
    $connection->closed++;
    return true;
}

function ptr_runtime_enter(): void
{
    $connection = $GLOBALS['qd_profile_read_connection'] ?? null;
    if ($connection instanceof QDProfileReadTestConnection) {
        $connection->events[] = 'runtime_enter';
    }
}

function pas_guard($connection, bool $write = false): void
{
    if (!$connection instanceof QDProfileReadTestConnection || $write !== true
        || $connection->status !== PGSQL_TRANSACTION_INTRANS || !$connection->readOnly
        || $connection->lockTimeoutMs < 1 || $connection->lockTimeoutMs > 2000
        || $connection->statementTimeoutMs < 1 || $connection->statementTimeoutMs > 3000) {
        throw new RuntimeException('playthrough restore guard ran outside a bounded read transaction');
    }
    $connection->events[] = 'pas_guard';
}

function pth_state($connection): array
{
    $connection->events[] = 'pth_state';
    $connection->seenLimits[] = [$connection->lockTimeoutMs, $connection->statementTimeoutMs];
    if ($connection->lockTimeoutMs < 1 || $connection->lockTimeoutMs > 2000
        || $connection->statementTimeoutMs < 1 || $connection->statementTimeoutMs > 3000) {
        throw new RuntimeException('profile read started before its timeout guard');
    }
    if ($connection->fault === 'timeout') {
        $connection->status = PGSQL_TRANSACTION_INERROR;
        throw new RuntimeException('simulated profile SQL timeout');
    }
    if ($connection->fault === 'logical') {
        return ['available' => false, 'active_id' => 0, 'playthroughs' => []];
    }
    return [
        'available' => true,
        'active_id' => 17,
        'auto_switch_pending' => false,
        'playthroughs' => [[
            'id' => 17,
            'active' => true,
            'available' => true,
            'character_id' => '0123456789abcdef0123456789abcdef',
        ]],
    ];
}

function ptp_product(): array
{
    return ['meta' => 'chim_meta'];
}

function ptp_connect(): QDProfileReadTestConnection
{
    $connection = $GLOBALS['qd_profile_read_connection'];
    $connection->events[] = 'ptp_connect';
    return $connection;
}

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'runtime_support.php';

function qd_profile_read_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function qd_profile_read_run_success(QDProfileReadTestConnection $connection, bool $owned): void
{
    $identity = qd_runtime_active_playthrough($connection);
    qd_profile_read_expect(($identity['profileId'] ?? null) === 17
        && ($identity['schemaName'] ?? null) === 'chim_profile_test',
        'The bounded reader changed the authoritative identity.');
    qd_profile_read_expect(count($connection->seenLimits) === 1, 'pth_state was not read exactly once.');
    $readIndex = array_search('pth_state', $connection->events, true);
    $limitIndex = array_search('apply_pg_limits', $connection->events, true);
    qd_profile_read_expect(is_int($readIndex) && is_int($limitIndex) && $limitIndex < $readIndex,
        'Timeout bounds were not installed before the first pth_state read.');
    qd_profile_read_expect($connection->status === ($owned ? PGSQL_TRANSACTION_IDLE : PGSQL_TRANSACTION_INTRANS),
        'The reader changed caller transaction ownership.');
}

function qd_profile_read_test_owned_defaults(): void
{
    $connection = new QDProfileReadTestConnection();
    qd_profile_read_run_success($connection, true);
    qd_profile_read_expect($connection->commits === 1 && $connection->rollbacks === 0,
        'An owned successful read did not commit exactly once.');
    qd_profile_read_expect($connection->lockTimeoutMs === 0 && $connection->statementTimeoutMs === 0,
        'An owned read leaked transaction-local timeout settings.');
}

function qd_profile_read_test_caller_defaults(): void
{
    $connection = new QDProfileReadTestConnection();
    $connection->status = PGSQL_TRANSACTION_INTRANS;
    $connection->work = ['caller write'];
    qd_profile_read_run_success($connection, false);
    qd_profile_read_expect($connection->seenLimits === [[2000, 3000]],
        'A caller-owned transaction did not receive the bounded defaults.');
    qd_profile_read_expect($connection->lockTimeoutMs === 0 && $connection->statementTimeoutMs === 0
        && $connection->work === ['caller write'] && $connection->savepoints === [],
        'A successful caller read changed its limits, work or savepoints.');
    qd_profile_read_expect($connection->commits === 0 && $connection->rollbacks === 0,
        'The reader committed or rolled back caller work.');
}

function qd_profile_read_test_shorter_caller_bounds(): void
{
    $connection = new QDProfileReadTestConnection();
    $connection->status = PGSQL_TRANSACTION_INTRANS;
    $connection->lockTimeoutMs = 500;
    $connection->statementTimeoutMs = 1250;
    $connection->work = ['caller write'];
    qd_profile_read_run_success($connection, false);
    qd_profile_read_expect($connection->seenLimits === [[500, 1250]],
        'An existing shorter timeout was loosened.');
    qd_profile_read_expect($connection->lockTimeoutMs === 500 && $connection->statementTimeoutMs === 1250
        && $connection->work === ['caller write'],
        'The caller limits or work were not restored after success.');
}

function qd_profile_read_test_caller_error(string $fault): void
{
    $connection = new QDProfileReadTestConnection();
    $connection->status = PGSQL_TRANSACTION_INTRANS;
    $connection->lockTimeoutMs = 700;
    $connection->statementTimeoutMs = 1400;
    $connection->work = ['caller write'];
    $connection->fault = $fault;
    $message = null;
    try {
        qd_runtime_active_playthrough($connection);
    } catch (Throwable $error) {
        $message = $error->getMessage();
    }
    qd_profile_read_expect(is_string($message), 'A profile read failure was accepted.');
    qd_profile_read_expect($connection->status === PGSQL_TRANSACTION_INTRANS
        && $connection->lockTimeoutMs === 700 && $connection->statementTimeoutMs === 1400
        && $connection->work === ['caller write'] && $connection->savepoints === [],
        'A failed caller read did not restore the transaction, work and previous limits.');
    qd_profile_read_expect($connection->commits === 0 && $connection->rollbacks === 0,
        'A failed read committed or rolled back caller work.');
}

function qd_profile_read_test_owned_error(): void
{
    $connection = new QDProfileReadTestConnection();
    $connection->fault = 'timeout';
    try {
        qd_runtime_active_playthrough($connection);
        throw new RuntimeException('An owned timed-out read was accepted.');
    } catch (RuntimeException $error) {
        qd_profile_read_expect($error->getMessage() !== 'An owned timed-out read was accepted.',
            'An owned timed-out read was accepted.');
    }
    qd_profile_read_expect($connection->status === PGSQL_TRANSACTION_IDLE
        && $connection->lockTimeoutMs === 0 && $connection->statementTimeoutMs === 0
        && $connection->rollbacks === 1 && $connection->commits === 0,
        'An owned timeout did not roll back and restore the connection.');
}

function qd_profile_read_test_pcv_owner_lifecycle(
    QDProfileReadTestConnection $connection,
    array $expectedLimits,
    bool $expectGuard,
    string $readEvent,
    array $restoredLimits = [0, 0]
): void {
    qd_profile_read_expect($connection->seenLimits === [$expectedLimits],
        'PCV did not preserve the minimum bounded lock and statement timeouts before reading.');
    $runtimeIndex = array_search('runtime_enter', $connection->events, true);
    $connectIndex = array_search('ptp_connect', $connection->events, true);
    $beginIndex = array_search('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY', $connection->events, true);
    $settingsIndex = array_search('read_owner_pg_settings', $connection->events, true);
    $lockIndex = array_search('apply_owner_pg_limit:lock_timeout', $connection->events, true);
    $statementIndex = array_search('apply_owner_pg_limit:statement_timeout', $connection->events, true);
    $guardIndex = array_search('pas_guard', $connection->events, true);
    $readIndex = null;
    foreach ($connection->events as $index => $event) {
        if ($event === $readEvent || (is_string($event) && str_contains($event, 'to_regclass('))) {
            $readIndex = $index;
            break;
        }
    }
    $commitIndex = array_search('COMMIT', $connection->events, true);
    $closeIndex = array_search('close', $connection->events, true);
    qd_profile_read_expect(is_int($runtimeIndex) && is_int($connectIndex) && $runtimeIndex < $connectIndex
        && is_int($beginIndex) && $connectIndex < $beginIndex
        && is_int($settingsIndex) && $beginIndex < $settingsIndex
        && is_int($lockIndex) && is_int($statementIndex) && $settingsIndex < $lockIndex
        && $settingsIndex < $statementIndex && is_int($readIndex)
        && $lockIndex < $readIndex && $statementIndex < $readIndex,
        'PCV must enter the official runtime lease, begin its bounded read transaction, and apply limits before owner reads: '
            . json_encode($connection->events, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    qd_profile_read_expect($expectGuard
        ? is_int($guardIndex) && $statementIndex < $guardIndex && $guardIndex < $readIndex
        : $guardIndex === false,
        'The playthrough restore guard was not conditionally placed after bounded BEGIN and before owner reads.');
    qd_profile_read_expect($connection->status === PGSQL_TRANSACTION_IDLE
        && $connection->commits === 1 && $connection->rollbacks === 0 && $connection->closed === 1
        && [$connection->lockTimeoutMs, $connection->statementTimeoutMs] === $restoredLimits,
        'PCV did not close its owned bounded transaction cleanly.');
    qd_profile_read_expect(is_int($commitIndex) && is_int($closeIndex) && $commitIndex < $closeIndex,
        'PCV must finish its read transaction before releasing the connection.');
}

function qd_profile_read_test_pcv_owned_identity_and_catalog(): void
{
    $repoRoot = dirname(__DIR__, 3);
    $fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-pcv-identity-limits-' . bin2hex(random_bytes(8));
    $ownerRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation';
    $homeHelper = $fixtureRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_home.php';
    $switchingHelper = $fixtureRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_switching.php';
    if (!mkdir($ownerRoot, 0700, true) || !mkdir(dirname($homeHelper), 0700, true)) {
        throw new RuntimeException('Could not create the isolated PCV identity fixture.');
    }
    $sourceRoot = $repoRoot . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR
        . 'private-conversation-v0.1.24' . DIRECTORY_SEPARATOR . 'server';
    foreach (['state.php', 'log.php', 'scope.php'] as $name) {
        if (!copy($sourceRoot . DIRECTORY_SEPARATOR . $name, $ownerRoot . DIRECTORY_SEPARATOR . $name)) {
            throw new RuntimeException('Could not stage the shipped PCV bounded reader.');
        }
    }
    if (file_put_contents($homeHelper, "<?php\n") !== 6) {
        throw new RuntimeException('Could not create the isolated host reader marker.');
    }
    if (file_put_contents($switchingHelper, "<?php\n") !== 6) {
        throw new RuntimeException('Could not create the isolated playthrough guard marker.');
    }
    $previousScript = $_SERVER['SCRIPT_FILENAME'] ?? null;
    $hadScript = array_key_exists('SCRIPT_FILENAME', $_SERVER);
    $previousToken = $_SERVER['HTTP_X_CHIM_PLAYTHROUGH'] ?? null;
    $hadToken = array_key_exists('HTTP_X_CHIM_PLAYTHROUGH', $_SERVER);
    require_once $ownerRoot . DIRECTORY_SEPARATOR . 'state.php';
    require_once $ownerRoot . DIRECTORY_SEPARATOR . 'scope.php';
    try {
        $identityConnection = new QDProfileReadTestConnection();
        $GLOBALS['qd_profile_read_connection'] = $identityConnection;
        $_SERVER['SCRIPT_FILENAME'] = 'main.php';
        unset($_SERVER['HTTP_X_CHIM_PLAYTHROUGH']);
        $identity = pcv_current_identity(true);
        qd_profile_read_expect(($identity['key'] ?? null) === hash('sha256', '17:0123456789abcdef0123456789abcdef'),
            'The bounded PCV reader changed the authoritative identity.');
        qd_profile_read_test_pcv_owner_lifecycle($identityConnection, [2000, 3000], true,
            'pth_state');
        qd_profile_read_expect($identityConnection->seenLimits === [[2000, 3000]],
            'The default owner limits did not cap the first PCV profile read.');

        $catalogConnection = new QDProfileReadTestConnection();
        $catalogConnection->lockTimeoutMs = 500;
        $catalogConnection->statementTimeoutMs = 1250;
        $catalogConnection->catalogRows = [[
            'id' => '101', 'npc_name' => 'Aela', 'profile_id' => '91',
            'metadata' => '{"activity_status":{"timestamp":123},"extra":"kept"}', 'extra_column' => 'kept',
        ]];
        $GLOBALS['qd_profile_read_connection'] = $catalogConnection;
        $_SERVER['SCRIPT_FILENAME'] = 'api.php';
        $_SERVER['HTTP_X_CHIM_PLAYTHROUGH'] = 'token-present';
        $catalogRows = pcvScopeLoadNpcCatalog();
        qd_profile_read_expect($catalogRows === $catalogConnection->catalogRows,
            'The owner catalog reader must preserve the full SELECT * row semantics.');
        qd_profile_read_test_pcv_owner_lifecycle($catalogConnection, [500, 1250], true,
            'read_catalog', [500, 1250]);

        $unGuardedConnection = new QDProfileReadTestConnection();
        $unGuardedConnection->lockTimeoutMs = 7000;
        $unGuardedConnection->statementTimeoutMs = 9000;
        $GLOBALS['qd_profile_read_connection'] = $unGuardedConnection;
        $_SERVER['SCRIPT_FILENAME'] = 'api.php';
        unset($_SERVER['HTTP_X_CHIM_PLAYTHROUGH']);
        pcv_current_identity(true);
        qd_profile_read_test_pcv_owner_lifecycle($unGuardedConnection, [2000, 3000], false,
            'pth_state', [7000, 9000]);
    } finally {
        if ($hadScript) {
            $_SERVER['SCRIPT_FILENAME'] = $previousScript;
        } else {
            unset($_SERVER['SCRIPT_FILENAME']);
        }
        if ($hadToken) {
            $_SERVER['HTTP_X_CHIM_PLAYTHROUGH'] = $previousToken;
        } else {
            unset($_SERVER['HTTP_X_CHIM_PLAYTHROUGH']);
        }
        unset($GLOBALS['qd_profile_read_connection']);
        $root = realpath($fixtureRoot);
        $temp = realpath(sys_get_temp_dir());
        if (is_string($root) && is_string($temp) && dirname($root) === $temp
            && str_starts_with(basename($root), 'qd-pcv-identity-limits-')) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                $entryPath = $entry->getPathname();
                if ($entry->isLink() || !$entry->isDir()) {
                    @unlink($entryPath);
                } else {
                    @rmdir($entryPath);
                }
            }
            @rmdir($root);
        }
    }
}

function qd_profile_read_test_pcv_owner_source_order(): void
{
    $ownerRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR
        . 'private-conversation-v0.1.24' . DIRECTORY_SEPARATOR . 'server';
    $state = file_get_contents($ownerRoot . DIRECTORY_SEPARATOR . 'state.php');
    $scope = file_get_contents($ownerRoot . DIRECTORY_SEPARATOR . 'scope.php');
    qd_profile_read_expect(is_string($state) && is_string($scope), 'Could not read the paired PCV owner sources.');
    $start = strpos($state, 'function pcv_with_read_connection(');
    $end = is_int($start) ? strpos($state, 'function pcv_profile_table_exists(', $start) : false;
    $reader = is_int($start) && is_int($end) ? substr($state, $start, $end - $start) : false;
    qd_profile_read_expect(is_string($reader), 'The PCV owner does not expose its bounded shared reader.');
    $positions = array_map(static fn(string $needle): int|false => strpos($reader, $needle), [
        'ptr_runtime_enter();',
        '$connection = ptp_connect();',
        "pcv_read_command(\$connection, 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY'",
        'pcv_apply_read_bounds($connection);',
        'pas_guard($connection, true);',
        '$value = $read($connection);',
    ]);
    $positionValues = array_values(array_filter($positions, 'is_int'));
    qd_profile_read_expect(count($positionValues) === count($positions)
        && $positionValues === array_values(array_unique($positionValues))
        && $positionValues[0] < $positionValues[1]
        && $positionValues[1] < $positionValues[2] && $positionValues[2] < $positionValues[3]
        && $positionValues[3] < $positionValues[4] && $positionValues[4] < $positionValues[5]
        && !str_contains($reader, 'pg_connect('),
        'The official runtime lease, ptp_connect reader, read-only bounds and conditional restore guard order changed.');
    $identityStart = strpos($state, 'function pcv_current_identity(');
    $catalogStart = strpos($state, 'function pcv_read_npc_catalog(');
    $scopeStart = strpos($scope, 'function pcvScopeLoadNpcCatalog(');
    qd_profile_read_expect(is_int($identityStart) && is_int($catalogStart) && is_int($scopeStart)
        && str_contains(substr($state, $identityStart, strpos($state, 'function pcv_read_npc_catalog(', $identityStart) - $identityStart), 'pcv_with_read_connection(')
        && str_contains(substr($state, $catalogStart, strpos($state, 'function pcv_current_playthrough_key(', $catalogStart) - $catalogStart), 'pcv_with_read_connection(')
        && str_contains(substr($scope, $scopeStart, strpos($scope, 'function pcvScopePresenceFailureReason(', $scopeStart) - $scopeStart), '\\pcv_read_npc_catalog()'),
        'PCV identity and native scope catalog callers must reuse the owner-bounded reader.');
}

function qd_profile_read_test_pcv_adapter_first_read(): void
{
    $source = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'adapters'
        . DIRECTORY_SEPARATOR . 'pcv.php');
    qd_profile_read_expect(is_string($source), 'Could not read the PCV adapter source contract.');
    $start = strpos($source, 'function qd_pcv_current_identity_and_catalog(');
    $end = is_int($start) ? strpos($source, 'function qd_pcv_read_catalog_rows(', $start) : false;
    $body = is_int($start) && is_int($end) ? substr($source, $start, $end - $start) : false;
    $bounds = is_string($body) ? strpos($body, 'qd_runtime_apply_profile_read_bounds($connection);') : false;
    $firstRead = is_string($body) ? strpos($body, '$homeState = pth_state($connection);') : false;
    $sharedRead = is_string($body) ? strpos($body, '$active = qd_runtime_active_playthrough($connection);') : false;
    $catalogRead = is_string($body) ? strpos($body, 'qd_pcv_read_catalog_rows($connection)') : false;
    qd_profile_read_expect(is_int($bounds) && is_int($firstRead) && is_int($sharedRead)
        && is_int($catalogRead) && $bounds < $firstRead && $firstRead < $sharedRead && $sharedRead < $catalogRead,
        'PCV identity and catalog reads must stay inside bounds installed before the first profile read.');
}

try {
    qd_profile_read_test_owned_defaults();
    qd_profile_read_test_caller_defaults();
    qd_profile_read_test_shorter_caller_bounds();
    qd_profile_read_test_caller_error('timeout');
    qd_profile_read_test_caller_error('logical');
    qd_profile_read_test_owned_error();
    qd_profile_read_test_pcv_owned_identity_and_catalog();
    qd_profile_read_test_pcv_owner_source_order();
    qd_profile_read_test_pcv_adapter_first_read();
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

echo "PASS: bounded profile identity reads preserve caller transactions and timeout limits" . PHP_EOL;
