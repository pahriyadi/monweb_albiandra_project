<?php
declare(strict_types=1);

/**
 * MONWEB By AP - ALBIANDRA PROJECT
 * Standalone Remote Agent v2.0 (monweb-agent.php)
 * Upload file ini ke root folder website target (PHP Native, CodeIgniter 3/4, Laravel, atau JS).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$AGENT_SECRET = getenv('MONWEB_AGENT_SECRET') ?: '{{MONWEB_SECRET_KEY}}';
$ROOT_PATH    = __DIR__;

$rawBody   = (string) file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_MONWEB_SIGNATURE'] ?? '';
$timestamp = (int) ($_SERVER['HTTP_X_MONWEB_TIMESTAMP'] ?? 0);

if (isset($_GET['track_hit']) && $_GET['track_hit'] === '1') {
    recordVisitorHit($ROOT_PATH);
    echo json_encode(['ok' => true, 'tracked' => true]);
    exit;
}

if (abs(time() - $timestamp) > 300) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Timestamp request kedaluwarsa (selisih > 5 menit). Periksa jam server.']);
    exit;
}

$expectedSig = hash_hmac('sha256', $timestamp . '.' . $rawBody, $AGENT_SECRET);
if (!hash_equals($expectedSig, $signature)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Signature HMAC tidak cocok. Pastikan Agent Secret Key sama dengan di dashboard MONWEB By AP.']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = [];
}

$action     = (string) ($payload['action'] ?? 'telemetry');
$targetPath = !empty($payload['repo_path']) && is_dir($payload['repo_path'])
    ? realpath($payload['repo_path'])
    : $ROOT_PATH;

function canRunShell(): bool {
    if (!function_exists('proc_open')) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array('proc_open', $disabled, true);
}

function runCommand(string $cmd, string $cwd): array {
    if (!canRunShell()) {
        return [
            'exit_code' => 127,
            'stdout'    => '',
            'stderr'    => 'Fungsi proc_open dinonaktifkan di php.ini hosting ini. Gunakan mode SSH di dashboard atau aktifkan proc_open di Select PHP Version (cPanel/hPanel).'
        ];
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $env = array_merge($_ENV, [
        'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        'LC_ALL' => 'C',
    ]);

    $process = @proc_open($cmd, $descriptors, $pipes, $cwd, $env);
    if (!is_resource($process)) {
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Gagal memulai proses perintah shell.'];
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit_code' => $exitCode,
        'stdout'    => trim($stdout),
        'stderr'    => trim($stderr),
    ];
}

function collectGitInfo(string $cwd): array {
    if (!is_dir($cwd . DIRECTORY_SEPARATOR . '.git')) {
        return [
            'initialized'    => false,
            'branch'         => '-',
            'commit_hash'    => '-',
            'commit_msg'     => 'Folder ini belum memiliki repositori .git',
            'commit_time'    => '-',
            'dirty_count'    => 0,
            'status_raw'     => '',
            'recent_commits' => [],
        ];
    }

    $branchRes = runCommand('git rev-parse --abbrev-ref HEAD', $cwd);
    $hashRes   = runCommand('git log -1 --format="%h|%s|%ci"', $cwd);
    $statusRes = runCommand('git status --porcelain', $cwd);
    $logRes    = runCommand('git log -5 --format="%h|%an|%cr|%s"', $cwd);

    $commitHash = '-';
    $commitMsg  = '-';
    $commitTime = '-';
    if ($hashRes['exit_code'] === 0 && $hashRes['stdout'] !== '') {
        $parts = explode('|', trim($hashRes['stdout'], '"'), 3);
        $commitHash = $parts[0] ?? '-';
        $commitMsg  = $parts[1] ?? '-';
        $commitTime = $parts[2] ?? '-';
    }

    $statusLines = $statusRes['stdout'] !== '' ? explode("\n", $statusRes['stdout']) : [];
    $commits = [];
    if ($logRes['exit_code'] === 0 && $logRes['stdout'] !== '') {
        foreach (explode("\n", $logRes['stdout']) as $line) {
            $p = explode('|', trim($line, '"'), 4);
            if (count($p) === 4) {
                $commits[] = [
                    'hash'    => $p[0],
                    'author'  => $p[1],
                    'time'    => $p[2],
                    'message' => $p[3],
                ];
            }
        }
    }

    return [
        'initialized'    => true,
        'branch'         => $branchRes['exit_code'] === 0 ? $branchRes['stdout'] : 'unknown',
        'commit_hash'    => $commitHash,
        'commit_msg'     => $commitMsg,
        'commit_time'    => $commitTime,
        'dirty_count'    => count($statusLines),
        'status_raw'     => $statusRes['stdout'],
        'recent_commits' => $commits,
    ];
}

function collectDiskInfo(string $cwd): array {
    $free  = @disk_free_space($cwd);
    $total = @disk_total_space($cwd);

    $projectBytes = 0;
    $inodeCount   = 0;
    $maxScanFiles = 15000;

    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cwd, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            $inodeCount++;
            if ($file->isFile()) {
                $projectBytes += $file->getSize();
            }
            if ($inodeCount >= $maxScanFiles) {
                break;
            }
        }
    } catch (Throwable $e) {
    }

    return [
        'disk_free_bytes'  => $free !== false ? (int) $free : 0,
        'disk_total_bytes' => $total !== false ? (int) $total : 0,
        'project_bytes'    => $projectBytes,
        'inodes_counted'   => $inodeCount,
        'scan_capped'      => $inodeCount >= $maxScanFiles,
    ];
}

function collectErrorLogs(string $cwd, int $maxLines = 60): array {
    $candidates = [
        $cwd . '/error_log',
        $cwd . '/php_errorlog',
        $cwd . '/application/logs',
        $cwd . '/writable/logs',
        $cwd . '/storage/logs/laravel.log',
    ];

    $entries = [];
    foreach ($candidates as $path) {
        if (is_file($path) && is_readable($path)) {
            $lines = tailFileLines($path, $maxLines);
            foreach ($lines as $line) {
                if (trim($line) !== '') {
                    $entries[] = [
                        'source' => basename($path),
                        'line'   => $line,
                    ];
                }
            }
        } elseif (is_dir($path) && is_readable($path)) {
            $files = glob($path . '/*.{php,log}', GLOB_BRACE) ?: [];
            rsort($files);
            foreach (array_slice($files, 0, 2) as $logFile) {
                $lines = tailFileLines($logFile, 30);
                foreach ($lines as $line) {
                    $trim = trim($line);
                    if ($trim === '' || str_starts_with($trim, '<?php')) {
                        continue;
                    }
                    $entries[] = [
                        'source' => basename(dirname($logFile)) . '/' . basename($logFile),
                        'line'   => $trim,
                    ];
                }
            }
        }
    }

    return array_slice($entries, -$maxLines);
}

function tailFileLines(string $filePath, int $lines = 50): array {
    $size = @filesize($filePath);
    if (!$size) {
        return [];
    }
    $readBytes = min($size, 32768);
    $fp = @fopen($filePath, 'rb');
    if (!$fp) {
        return [];
    }
    fseek($fp, -$readBytes, SEEK_END);
    $chunk = (string) fread($fp, $readBytes);
    fclose($fp);
    $all = explode("\n", str_replace("\r\n", "\n", $chunk));
    return array_slice($all, -$lines);
}

function cleanCacheAndSessions(string $cwd): array {
    $dirs = [
        $cwd . '/application/cache',
        $cwd . '/writable/cache',
        $cwd . '/writable/session',
        $cwd . '/writable/debugbar',
        $cwd . '/storage/framework/cache/data',
        $cwd . '/storage/framework/views',
    ];
    $deletedFiles = 0;
    $freedBytes   = 0;

    foreach ($dirs as $d) {
        if (!is_dir($d)) {
            continue;
        }
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $name = $f->getFilename();
                if ($name === 'index.html' || $name === '.htaccess' || $name === '.gitignore') {
                    continue;
                }
                if ($f->isFile() && is_writable($f->getPathname())) {
                    $freedBytes += (int) $f->getSize();
                    if (@unlink($f->getPathname())) {
                        $deletedFiles++;
                    }
                }
            }
        } catch (Throwable $e) {
        }
    }

    return [
        'deleted_files' => $deletedFiles,
        'freed_mb'      => round($freedBytes / 1048576, 2),
    ];
}

function createZipArchive(string $cwd): array {
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'Ekstensi ZipArchive tidak aktif di PHP server ini.'];
    }
    $backupName = 'backup_' . basename($cwd) . '_' . date('Ymd_His') . '.zip';
    $backupPath = $cwd . '/' . $backupName;
    $zip = new ZipArchive();
    if ($zip->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'error' => 'Gagal membuat file ZIP di folder target.'];
    }

    $count = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cwd, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $real = $file->getRealPath();
            $rel  = substr($real, strlen($cwd) + 1);
            if (str_starts_with($rel, '.git') || str_ends_with($rel, '.zip') || str_ends_with($rel, '.sqlite')) {
                continue;
            }
            $zip->addFile($real, $rel);
            $count++;
            if ($count >= 5000) {
                break;
            }
        }
    } catch (Throwable $e) {
    }
    $zip->close();

    return [
        'ok'         => true,
        'file_name'  => $backupName,
        'files_zipped' => $count,
        'size_mb'    => is_file($backupPath) ? round(filesize($backupPath) / 1048576, 2) : 0,
    ];
}

function recordVisitorHit(string $cwd): void {
    $file = $cwd . '/.monweb_traffic.json';
    $today = date('Y-m-d');
    $data = [];
    if (is_file($file)) {
        $data = json_decode((string) @file_get_contents($file), true) ?: [];
    }
    $data[$today] = (int) ($data[$today] ?? 0) + 1;
    ksort($data);
    if (count($data) > 14) {
        $data = array_slice($data, -14, null, true);
    }
    @file_put_contents($file, json_encode($data));
}

function getTrafficStats(string $cwd): array {
    $file = $cwd . '/.monweb_traffic.json';
    $today = date('Y-m-d');
    if (!is_file($file)) {
        return ['today_hits' => 0, 'daily' => []];
    }
    $data = json_decode((string) @file_get_contents($file), true) ?: [];
    return [
        'today_hits' => (int) ($data[$today] ?? 0),
        'daily'      => $data,
    ];
}

switch ($action) {
    case 'telemetry':
        echo json_encode([
            'ok'          => true,
            'php_version' => PHP_VERSION,
            'server_os'   => PHP_OS_FAMILY,
            'cwd'         => $targetPath,
            'git'         => collectGitInfo($targetPath),
            'disk'        => collectDiskInfo($targetPath),
            'errors'      => collectErrorLogs($targetPath, 50),
            'traffic'     => getTrafficStats($targetPath),
            'timestamp'   => date('Y-m-d H:i:s'),
        ]);
        break;

    case 'git_pull':
        $branch = preg_replace('/[^a-zA-Z0-9._\/-]/', '', (string) ($payload['branch'] ?? 'main'));
        $cmd = $branch !== '' ? "git pull origin {$branch} 2>&1" : "git pull 2>&1";
        $res = runCommand($cmd, $targetPath);
        echo json_encode([
            'ok'      => $res['exit_code'] === 0,
            'command' => $cmd,
            'output'  => trim($res['stdout'] . "\n" . $res['stderr']),
            'git'     => collectGitInfo($targetPath),
        ]);
        break;

    case 'git_status':
        $res = runCommand('git status -sb 2>&1', $targetPath);
        echo json_encode([
            'ok'      => $res['exit_code'] === 0,
            'command' => 'git status -sb',
            'output'  => trim($res['stdout'] . "\n" . $res['stderr']),
            'git'     => collectGitInfo($targetPath),
        ]);
        break;

    case 'git_reset':
        $branch = preg_replace('/[^a-zA-Z0-9._\/-]/', '', (string) ($payload['branch'] ?? 'main'));
        $cmd = "git fetch --all 2>&1 && git reset --hard origin/{$branch} 2>&1";
        $res = runCommand($cmd, $targetPath);
        echo json_encode([
            'ok'      => $res['exit_code'] === 0,
            'command' => $cmd,
            'output'  => trim($res['stdout'] . "\n" . $res['stderr']),
            'git'     => collectGitInfo($targetPath),
        ]);
        break;

    case 'git_log':
        $cmd = 'git log -8 --oneline --decorate --graph 2>&1';
        $res = runCommand($cmd, $targetPath);
        echo json_encode([
            'ok'      => $res['exit_code'] === 0,
            'command' => $cmd,
            'output'  => trim($res['stdout'] . "\n" . $res['stderr']),
            'git'     => collectGitInfo($targetPath),
        ]);
        break;

    case 'custom_cmd':
        $customCmd = trim((string) ($payload['command'] ?? ''));
        if ($customCmd === '') {
            echo json_encode(['ok' => false, 'error' => 'Perintah tidak boleh kosong.']);
            break;
        }
        $res = runCommand($customCmd . ' 2>&1', $targetPath);
        echo json_encode([
            'ok'      => $res['exit_code'] === 0,
            'command' => $customCmd,
            'output'  => trim($res['stdout'] . "\n" . $res['stderr']),
        ]);
        break;

    case 'clean_cache':
        $clean = cleanCacheAndSessions($targetPath);
        echo json_encode([
            'ok'      => true,
            'command' => 'clean_cache_and_sessions',
            'output'  => "Berhasil menghapus {$clean['deleted_files']} file cache/session ({$clean['freed_mb']} MB dibebaskan).",
        ]);
        break;

    case 'backup_zip':
        $zipRes = createZipArchive($targetPath);
        if (!$zipRes['ok']) {
            echo json_encode($zipRes);
            break;
        }
        echo json_encode([
            'ok'      => true,
            'command' => 'backup_zip',
            'output'  => "Arsip {$zipRes['file_name']} selesai dibuat ({$zipRes['files_zipped']} file, {$zipRes['size_mb']} MB).",
        ]);
        break;

    case 'clear_logs':
        $cleared = [];
        $candidates = [
            $targetPath . '/error_log',
            $targetPath . '/php_errorlog',
            $targetPath . '/storage/logs/laravel.log',
        ];
        foreach ($candidates as $file) {
            if (is_file($file) && is_writable($file)) {
                @file_put_contents($file, '');
                $cleared[] = basename($file);
            }
        }
        $ciLogs = glob($targetPath . '/application/logs/log-*.php') ?: [];
        foreach ($ciLogs as $ciFile) {
            if (is_writable($ciFile)) {
                @unlink($ciFile);
                $cleared[] = 'application/logs/' . basename($ciFile);
            }
        }
        echo json_encode([
            'ok'      => true,
            'cleared' => $cleared,
            'message' => count($cleared) > 0
                ? 'Berhasil membersihkan: ' . implode(', ', $cleared)
                : 'Tidak ada file error_log yang ditemukan atau perlu dihapus.',
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Action tidak dikenali: ' . $action]);
        break;
}
