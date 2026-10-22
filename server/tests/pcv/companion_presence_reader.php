<?php
declare(strict_types=1);

$serverRoot = dirname(__DIR__, 2);
$implementationRoot = dirname($serverRoot);
$pcvRoot = $implementationRoot . DIRECTORY_SEPARATOR . 'pcv-companion' . DIRECTORY_SEPARATOR
    . 'private-conversation-v0.1.23' . DIRECTORY_SEPARATOR . 'server';

putenv('QD_PCV_ROOT=' . $pcvRoot);
require_once $serverRoot . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'runtime_support.php';
require_once $serverRoot . DIRECTORY_SEPARATOR . 'adapters' . DIRECTORY_SEPARATOR . 'pcv.php';
qd_pcv_load_owner_sources();

function companion_presence_reader_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function companion_presence_reader_remove_tree(string $path): void
{
    $tempRoot = realpath(sys_get_temp_dir());
    $realPath = realpath($path);
    if (!is_string($tempRoot) || !is_string($realPath) || is_link($path)) {
        return;
    }
    $pathKey = DIRECTORY_SEPARATOR === '\\' ? strtolower($realPath) : $realPath;
    $tempKey = DIRECTORY_SEPARATOR === '\\' ? strtolower($tempRoot) : $tempRoot;
    if (!str_starts_with($pathKey . DIRECTORY_SEPARATOR, rtrim($tempKey, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        if ($item->isLink() || !$item->isDir()) {
            @unlink($item->getPathname());
        } else {
            @rmdir($item->getPathname());
        }
    }
    @rmdir($realPath);
}

$ownerStatePath = realpath($pcvRoot . DIRECTORY_SEPARATOR . 'state.php');
foreach (['pcv_capture_background_presence_report', 'pcv_read_eligible_npcs'] as $function) {
    $source = function_exists($function) ? realpath((new ReflectionFunction($function))->getFileName() ?: '') : false;
    companion_presence_reader_check(is_string($ownerStatePath) && $source === $ownerStatePath,
        'Presence capture and read must come from the pinned PCV owner state.php.');
}

$homeState = [
    'available' => true,
    'active_id' => 42,
    'auto_switch_pending' => false,
    'playthroughs' => [[
        'id' => 42,
        'active' => true,
        'available' => true,
        'character_id' => '0123456789abcdef0123456789abcdef',
        'player_name' => 'Department Test Player',
    ]],
];
$identity = pcv_identity_from_home_state($homeState, true);
$key = $identity['key'] ?? null;
$playerName = $identity['player_name'] ?? null;
companion_presence_reader_check(is_string($key) && is_string($playerName)
    && qd_pcv_owner_key_from_home_state($homeState) === $key,
    'The fixture must use the canonical PCV owner identity.');

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qd-pcv-companion-presence-' . bin2hex(random_bytes(8));
$stateDirectory = $root . DIRECTORY_SEPARATOR . 'state';
$logDirectory = $root . DIRECTORY_SEPARATOR . 'logs';
companion_presence_reader_check(@mkdir($stateDirectory, 0700, true)
    && @mkdir($logDirectory, 0700, true), 'The isolated owner fixture directories must be created.');
putenv('PCV_LOG_DIR=' . $logDirectory);

try {
    $heartbeat = hrtime(true);
    $baseline = $heartbeat - 10_000_000_000;
    companion_presence_reader_check($baseline > 0, 'The monotonic test clock must have a valid bounded baseline.');
    $rawReport = 'Department Test Manager//Department Test Recipient//Department Test Player';
    $baselineResult = pcv_capture_background_presence_report($key, $rawReport, $playerName, $baseline, $stateDirectory);
    $heartbeatResult = pcv_capture_background_presence_report($key, $rawReport, $playerName, $heartbeat, $stateDirectory);
    companion_presence_reader_check(($baselineResult['status'] ?? null) === 'baseline'
        && ($heartbeatResult['status'] ?? null) === 'ready',
        'The real PCV background-presence capture must create a fresh ready report.');

    $activity = $heartbeat - 2_000_000_000;
    $catalogRows = [
        ['id' => '1', 'npc_name' => 'Department Test Manager', 'profile_id' => '7',
            'metadata' => json_encode(['activity_status' => ['timestamp' => $activity]], JSON_THROW_ON_ERROR)],
        ['id' => '2', 'npc_name' => 'Department Test Recipient', 'profile_id' => '7',
            'metadata' => json_encode(['activity_status' => ['timestamp' => $activity]], JSON_THROW_ON_ERROR)],
    ];
    $ownerRead = pcv_read_eligible_npcs($key, $catalogRows, $playerName, $stateDirectory);
    companion_presence_reader_check(($ownerRead['status'] ?? null) === 'ready'
        && ($ownerRead['known_npcs'][1] ?? null) === 'Department Test Manager'
        && ($ownerRead['known_npcs'][2] ?? null) === 'Department Test Recipient',
        'The pinned owner reader must accept the freshly captured roster and catalog.');

    $adapterRead = qd_pcv_read_presence_directory($stateDirectory, $key, $catalogRows, $playerName);
    companion_presence_reader_check(($adapterRead['status'] ?? null) === 'ready'
        && ($adapterRead['known_npcs'][1] ?? null) === 'Department Test Manager'
        && ($adapterRead['known_npcs'][2] ?? null) === 'Department Test Recipient'
        && ($adapterRead['player_name'] ?? null) === $playerName,
        'The PCV adapter must reuse the owner reader and preserve its safe roster projection.');

    echo "PASS: actual PCV background capture, owner read, and adapter presence read\n";
} finally {
    companion_presence_reader_remove_tree($root);
}
