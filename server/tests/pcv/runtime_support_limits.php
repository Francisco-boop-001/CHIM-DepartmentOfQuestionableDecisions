<?php
declare(strict_types=1);

define('PGSQL_TRANSACTION_IDLE', 0);
define('PGSQL_TRANSACTION_INTRANS', 2);
define('PGSQL_TRANSACTION_INERROR', 3);

final class QDProfileReadTestConnection
{
    public int $status = PGSQL_TRANSACTION_IDLE;
    public int $lockTimeoutMs = 0;
    public int $statementTimeoutMs = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    public int $closed = 0;
    public array $events = [];
    public array $savepoints = [];
    public array $work = [];
    public array $seenLimits = [];
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
        $connection->transactionSettings = [$connection->lockTimeoutMs, $connection->statementTimeoutMs];
        return new QDProfileReadTestResult([]);
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
        [$connection->lockTimeoutMs, $connection->statementTimeoutMs] = $connection->transactionSettings;
        $connection->commits++;
        return new QDProfileReadTestResult([]);
    }
    if ($query === 'ROLLBACK') {
        $connection->status = PGSQL_TRANSACTION_IDLE;
        [$connection->lockTimeoutMs, $connection->statementTimeoutMs] = $connection->transactionSettings;
        $connection->rollbacks++;
        return new QDProfileReadTestResult([]);
    }
    return false;
}

function pg_query_params($connection, string $query, array $params): QDProfileReadTestResult|false
{
    $connection->events[] = $query;
    if ($connection->status === PGSQL_TRANSACTION_INERROR) {
        return false;
    }
    if (str_contains($query, 'FROM pg_settings')) {
        $connection->events[] = 'read_pg_settings';
        return new QDProfileReadTestResult([
            ['name' => 'lock_timeout', 'setting_ms' => (string)$connection->lockTimeoutMs,
                'setting_text' => $connection->lockTimeoutMs === 0 ? '0' : $connection->lockTimeoutMs . 'ms'],
            ['name' => 'statement_timeout', 'setting_ms' => (string)$connection->statementTimeoutMs,
                'setting_text' => $connection->statementTimeoutMs === 0 ? '0' : $connection->statementTimeoutMs . 'ms'],
        ]);
    }
    if (str_contains($query, 'set_config(')) {
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
    if (str_contains($query, 'to_regclass(')) {
        return new QDProfileReadTestResult([['profile_table' => 'chim_meta.playthrough_profiles']]);
    }
    if (str_contains($query, 'FROM chim_meta.playthrough_profiles')) {
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

function pg_free_result(QDProfileReadTestResult $result): bool
{
    return true;
}

function pg_close(QDProfileReadTestConnection $connection): bool
{
    $connection->closed++;
    return true;
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
    return $GLOBALS['qd_profile_read_connection'];
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

function qd_profile_read_test_pcv_owned_identity(): void
{
    $repoRoot = dirname(__DIR__, 3);
    $fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-pcv-identity-limits-' . bin2hex(random_bytes(8));
    $ownerRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'ext' . DIRECTORY_SEPARATOR . 'private_conversation';
    $homeHelper = $fixtureRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_home.php';
    if (!mkdir($ownerRoot, 0700, true) || !mkdir(dirname($homeHelper), 0700, true)) {
        throw new RuntimeException('Could not create the isolated PCV identity fixture.');
    }
    $sourceRoot = $repoRoot . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR
        . 'private-conversation-v0.1.23' . DIRECTORY_SEPARATOR . 'server';
    foreach (['state.php', 'log.php'] as $name) {
        if (!copy($sourceRoot . DIRECTORY_SEPARATOR . $name, $ownerRoot . DIRECTORY_SEPARATOR . $name)) {
            throw new RuntimeException('Could not stage the shipped PCV identity reader.');
        }
    }
    if (file_put_contents($homeHelper, "<?php\n") !== 6) {
        throw new RuntimeException('Could not create the isolated host reader marker.');
    }
    $connection = new QDProfileReadTestConnection();
    $GLOBALS['qd_profile_read_connection'] = $connection;
    require_once $ownerRoot . DIRECTORY_SEPARATOR . 'state.php';
    try {
        $identity = pcv_current_identity(true);
        qd_profile_read_expect(($identity['key'] ?? null) === hash('sha256', '17:0123456789abcdef0123456789abcdef'),
            'The bounded PCV reader changed the authoritative identity.');
        qd_profile_read_expect($connection->seenLimits === [[2000, 3000]],
            'PCV did not install bounds before the initial profile-table/player read.');
        $limitIndex = array_search('apply_pg_limits', $connection->events, true);
        $firstProfileRead = null;
        foreach ($connection->events as $index => $event) {
            if (is_string($event) && str_contains($event, 'to_regclass(')) {
                $firstProfileRead = $index;
                break;
            }
        }
        qd_profile_read_expect(is_int($limitIndex) && is_int($firstProfileRead) && $limitIndex < $firstProfileRead,
            'PCV queried profile identity before applying SQL bounds.');
        qd_profile_read_expect($connection->status === PGSQL_TRANSACTION_IDLE
            && $connection->commits === 1 && $connection->rollbacks === 0 && $connection->closed === 1
            && $connection->lockTimeoutMs === 0 && $connection->statementTimeoutMs === 0,
            'PCV did not close its owned bounded transaction cleanly.');
    } finally {
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
    qd_profile_read_test_pcv_owned_identity();
    qd_profile_read_test_pcv_adapter_first_read();
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

echo "PASS: bounded profile identity reads preserve caller transactions and timeout limits" . PHP_EOL;
