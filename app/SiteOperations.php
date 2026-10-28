<?php
declare(strict_types=1);

namespace MonWeb;

use PDO;
use Throwable;
use ZipArchive;

class SiteOperations {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function getAllSites(): array {
        return $this->db->query('SELECT * FROM websites ORDER BY id ASC')->fetchAll();
    }

    public function getSiteById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM websites WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function addSite(array $data): int {
        $stmt = $this->db->prepare('
            INSERT INTO websites (
                name, url, stack_type, hosting_panel, conn_mode,
                agent_url, agent_secret, ssh_host, ssh_port, ssh_user, ssh_key,
                repo_path, git_branch, status, created_at
            ) VALUES (
                :name, :url, :stack_type, :hosting_panel, :conn_mode,
                :agent_url, :agent_secret, :ssh_host, :ssh_port, :ssh_user, :ssh_key,
                :repo_path, :git_branch, :status, :created_at
            )
        ');

        $url = rtrim(trim((string) ($data['url'] ?? '')), '/');
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $agentUrl = trim((string) ($data['agent_url'] ?? ''));
        if ($agentUrl === '' && $url !== '') {
            $agentUrl = $url . '/monweb-agent.php';
        }

        $secret = trim((string) ($data['agent_secret'] ?? ''));
        if ($secret === '') {
            $secret = Database::getSetting('master_agent_secret');
        }

        $stmt->execute([
            ':name'          => trim((string) ($data['name'] ?? 'Website Tanpa Nama')),
            ':url'           => $url,
            ':stack_type'    => trim((string) ($data['stack_type'] ?? 'PHP Native')),
            ':hosting_panel' => trim((string) ($data['hosting_panel'] ?? 'cPanel Unlimited')),
            ':conn_mode'     => trim((string) ($data['conn_mode'] ?? 'local')),
            ':agent_url'     => $agentUrl,
            ':agent_secret'  => $secret,
            ':ssh_host'      => trim((string) ($data['ssh_host'] ?? '')),
            ':ssh_port'      => (int) ($data['ssh_port'] ?? 65002),
            ':ssh_user'      => trim((string) ($data['ssh_user'] ?? '')),
            ':ssh_key'       => trim((string) ($data['ssh_key'] ?? '')),
            ':repo_path'     => trim((string) ($data['repo_path'] ?? '')),
            ':git_branch'    => trim((string) ($data['git_branch'] ?? 'main')) ?: 'main',
            ':status'        => 'UNCHECKED',
            ':created_at'    => date('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function deleteSite(int $id): void {
        $this->db->prepare('DELETE FROM websites WHERE id = :id')->execute([':id' => $id]);
        $this->db->prepare('DELETE FROM error_logs WHERE website_id = :id')->execute([':id' => $id]);
        $this->db->prepare('DELETE FROM deploy_logs WHERE website_id = :id')->execute([':id' => $id]);
        $this->db->prepare('DELETE FROM uptime_checks WHERE website_id = :id')->execute([':id' => $id]);
    }

    public function discoverLocalHostingDirectories(): array {
        $parentDir = dirname(__DIR__);
        if (basename($parentDir) !== 'public_html' && is_dir(dirname($parentDir))) {
            $parentDir = dirname($parentDir);
        }

        $existingPaths = [];
        $existingUrls  = [];
        foreach ($this->getAllSites() as $s) {
            if (!empty($s['repo_path'])) {
                $existingPaths[rtrim(str_replace('\\', '/', $s['repo_path']), '/')] = true;
            }
            if (!empty($s['url'])) {
                $existingUrls[strtolower(rtrim($s['url'], '/'))] = true;
            }
        }

        $discovered = [];
        $candidates = [];

        if (basename($parentDir) === 'public_html') {
            $candidates[] = [
                'name'      => 'Domain Utama (baznassumbawa.id)',
                'url'       => 'https://baznassumbawa.id',
                'repo_path' => $parentDir,
            ];
        }

        $items = @scandir($parentDir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === 'cgi-bin' || str_starts_with($item, '.')) {
                continue;
            }
            $fullPath = $parentDir . '/' . $item;
            if (!is_dir($fullPath)) {
                continue;
            }

            $hasIndex = is_file($fullPath . '/index.php')
                || is_file($fullPath . '/index.html')
                || is_dir($fullPath . '/.git')
                || is_dir($fullPath . '/application')
                || is_dir($fullPath . '/app');

            if (!$hasIndex) {
                continue;
            }

            if (str_contains($item, '.')) {
                $url = 'https://' . $item;
                $label = 'Subdomain: ' . $item;
            } else {
                $url = 'https://baznassumbawa.id/' . $item;
                $label = 'Aplikasi: ' . $item;
            }

            $candidates[] = [
                'name'      => $label,
                'url'       => $url,
                'repo_path' => $fullPath,
            ];
        }

        foreach ($candidates as $cand) {
            $normPath = rtrim(str_replace('\\', '/', $cand['repo_path']), '/');
            $normUrl  = strtolower(rtrim($cand['url'], '/'));
            if (isset($existingPaths[$normPath]) || isset($existingUrls[$normUrl])) {
                continue;
            }

            $stack = 'PHP Native';
            if (is_dir($cand['repo_path'] . '/application/controllers') || is_dir($cand['repo_path'] . '/system/core')) {
                $stack = 'CodeIgniter 3/4';
            } elseif (is_file($cand['repo_path'] . '/artisan')) {
                $stack = 'Laravel';
            } elseif (is_file($cand['repo_path'] . '/wp-config.php')) {
                $stack = 'WordPress';
            }

            $branch = 'main';
            if (is_dir($cand['repo_path'] . '/.git')) {
                $b = $this->runLocalProcess('git rev-parse --abbrev-ref HEAD', $cand['repo_path']);
                if ($b['exit_code'] === 0 && $b['output'] !== '') {
                    $branch = trim($b['output']);
                }
            }

            $newId = $this->addSite([
                'name'          => $cand['name'],
                'url'           => $cand['url'],
                'stack_type'    => $stack,
                'hosting_panel' => 'cPanel Unlimited',
                'conn_mode'     => 'local',
                'repo_path'     => $cand['repo_path'],
                'git_branch'    => $branch,
            ]);
            $this->syncSite($newId);
            $discovered[] = $cand['name'];
        }

        return [
            'ok'         => true,
            'scanned_in' => $parentDir,
            'added'      => $discovered,
            'count'      => count($discovered),
        ];
    }

    public function syncSite(int $id): array {
        $site = $this->getSiteById($id);
        if (!$site) {
            return ['ok' => false, 'error' => 'Website tidak ditemukan.'];
        }

        $httpProbe = $this->probeHttpAndSsl($site['url']);
        $status    = $httpProbe['is_online'] ? 'ONLINE' : 'DOWN';

        $diskUsedMb   = (float) $site['disk_used_mb'];
        $diskFreeGb   = (float) $site['disk_free_gb'];
        $inodesCount  = (int) $site['inodes_count'];
        $commitHash   = (string) $site['last_commit_hash'];
        $commitMsg    = (string) $site['last_commit_msg'];
        $gitBranch    = (string) $site['git_branch'];
        $dirtyFiles   = (int) $site['dirty_files'];
        $errorCount   = (int) $site['error_count'];
        $visitorHits  = (int) $site['visitor_hits_today'];
        $agentNote    = '';

        if ($site['conn_mode'] === 'local' || $this->isLocalSelfTarget($site)) {
            $localData   = $this->collectLocalWorkspaceTelemetry($site['repo_path']);
            $diskUsedMb  = $localData['disk_used_mb'];
            $diskFreeGb  = $localData['disk_free_gb'];
            $inodesCount = $localData['inodes_count'];
            $commitHash  = $localData['commit_hash'];
            $commitMsg   = $localData['commit_msg'];
            $gitBranch   = $localData['branch'] ?: $gitBranch;
            $dirtyFiles  = $localData['dirty_files'];
            $errorCount  = $this->storeErrorEntries($id, $localData['errors']);
            $visitorHits = $localData['visitor_hits'];
            if (!$httpProbe['is_online'] && is_dir($site['repo_path'] ?: dirname(__DIR__))) {
                $status = 'ONLINE';
                $httpProbe['http_code'] = 200;
            }
        } elseif ($site['conn_mode'] === 'agent' && !empty($site['agent_url'])) {
            $agentResp = $this->callRemoteAgent($site, ['action' => 'telemetry', 'repo_path' => $site['repo_path']]);
            if ($agentResp['ok']) {
                $d = $agentResp['data'];
                $diskUsedMb  = round(((int) ($d['disk']['project_bytes'] ?? 0)) / 1048576, 2);
                $diskFreeGb  = round(((int) ($d['disk']['disk_free_bytes'] ?? 0)) / 1073741824, 2);
                $inodesCount = (int) ($d['disk']['inodes_counted'] ?? 0);
                $commitHash  = (string) ($d['git']['commit_hash'] ?? $commitHash);
                $commitMsg   = (string) ($d['git']['commit_msg'] ?? $commitMsg);
                $gitBranch   = (string) ($d['git']['branch'] ?? $gitBranch);
                $dirtyFiles  = (int) ($d['git']['dirty_count'] ?? 0);
                $visitorHits = (int) ($d['traffic']['today_hits'] ?? 0);
                $errorCount  = $this->storeErrorEntries($id, $d['errors'] ?? []);
            } else {
                $agentNote = $agentResp['error'] ?? 'Agent belum merespons';
            }
        } elseif ($site['conn_mode'] === 'ssh' && !empty($site['ssh_host'])) {
            $sshResp = $this->runSshTelemetry($site);
            if ($sshResp['ok']) {
                $diskUsedMb  = $sshResp['disk_used_mb'];
                $inodesCount = $sshResp['inodes_count'];
                $commitHash  = $sshResp['commit_hash'];
                $commitMsg   = $sshResp['commit_msg'];
                $gitBranch   = $sshResp['branch'] ?: $gitBranch;
                $dirtyFiles  = $sshResp['dirty_files'];
                $errorCount  = $this->storeErrorEntries($id, $sshResp['errors']);
            } else {
                $agentNote = $sshResp['error'];
            }
        }

        $now = date('Y-m-d H:i:s');
        $upd = $this->db->prepare('
            UPDATE websites SET
                status = :status,
                http_code = :http_code,
                latency_ms = :latency_ms,
                ssl_days_left = :ssl_days_left,
                disk_used_mb = :disk_used_mb,
                disk_free_gb = :disk_free_gb,
                inodes_count = :inodes_count,
                git_branch = :git_branch,
                last_commit_hash = :last_commit_hash,
                last_commit_msg = :last_commit_msg,
                dirty_files = :dirty_files,
                error_count = :error_count,
                visitor_hits_today = :visitor_hits_today,
                last_checked_at = :last_checked_at
            WHERE id = :id
        ');

        $upd->execute([
            ':status'             => $status,
            ':http_code'          => $httpProbe['http_code'],
            ':latency_ms'         => $httpProbe['latency_ms'],
            ':ssl_days_left'      => $httpProbe['ssl_days_left'],
            ':disk_used_mb'       => $diskUsedMb,
            ':disk_free_gb'       => $diskFreeGb,
            ':inodes_count'       => $inodesCount,
            ':git_branch'         => $gitBranch,
            ':last_commit_hash'   => $commitHash,
            ':last_commit_msg'    => $commitMsg,
            ':dirty_files'        => $dirtyFiles,
            ':error_count'        => $errorCount,
            ':visitor_hits_today' => $visitorHits,
            ':last_checked_at'    => $now,
            ':id'                 => $id,
        ]);

        $insUptime = $this->db->prepare('
            INSERT INTO uptime_checks (website_id, http_code, latency_ms, is_online, checked_at)
            VALUES (:wid, :code, :lat, :on, :at)
        ');
        $insUptime->execute([
            ':wid'  => $id,
            ':code' => $httpProbe['http_code'],
            ':lat'  => $httpProbe['latency_ms'],
            ':on'   => $status === 'ONLINE' ? 1 : 0,
            ':at'   => $now,
        ]);

        return [
            'ok'         => true,
            'site'       => $this->getSiteById($id),
            'agent_note' => $agentNote,
        ];
    }

    public function syncAllSites(): array {
        $sites = $this->getAllSites();
        $results = [];
        foreach ($sites as $s) {
            $results[] = $this->syncSite((int) $s['id']);
        }
        return $results;
    }

    public function executeGitAction(int $id, string $action = 'git_pull', string $customCmd = ''): array {
        $site = $this->getSiteById($id);
        if (!$site) {
            return ['ok' => false, 'error' => 'Website tidak ditemukan.'];
        }

        $branch = $site['git_branch'] ?: 'main';
        $cmdStr = match ($action) {
            'git_pull'   => "git pull origin {$branch}",
            'git_status' => 'git status -sb',
            'git_reset'  => "git fetch --all && git reset --hard origin/{$branch}",
            'git_log'    => 'git log -8 --oneline --decorate --graph',
            'custom_cmd' => $customCmd,
            default      => "git pull origin {$branch}",
        };

        $output    = '';
        $isSuccess = false;

        if ($site['conn_mode'] === 'local' || $this->isLocalSelfTarget($site)) {
            $targetDir = !empty($site['repo_path']) && is_dir($site['repo_path'])
                ? $site['repo_path']
                : dirname(__DIR__);

            if ($action === 'clean_cache') {
                $clean = $this->cleanLocalCacheAndSessions($targetDir);
                $cmdStr = 'clean_cache_and_sessions';
                $output = "Berhasil menghapus {$clean['deleted_files']} file cache/session ({$clean['freed_mb']} MB dibebaskan) di {$targetDir}.";
                $isSuccess = true;
            } elseif ($action === 'backup_zip') {
                $zipRes = $this->createLocalZipBackup($targetDir);
                $cmdStr = 'backup_zip';
                $isSuccess = $zipRes['ok'];
                $output = $zipRes['ok']
                    ? "Backup ZIP berhasil dibuat: {$zipRes['file_path']} ({$zipRes['files_zipped']} file, {$zipRes['size_mb']} MB)."
                    : ($zipRes['error'] ?? 'Gagal membuat ZIP.');
            } else {
                $res = $this->runLocalProcess($cmdStr . ' 2>&1', $targetDir);
                $output = $res['output'];
                $isSuccess = $res['exit_code'] === 0;
            }
        } elseif ($site['conn_mode'] === 'agent') {
            $resp = $this->callRemoteAgent($site, [
                'action'    => $action,
                'branch'    => $branch,
                'command'   => $customCmd,
                'repo_path' => $site['repo_path'],
            ]);
            if ($resp['ok']) {
                $isSuccess = (bool) ($resp['data']['ok'] ?? true);
                $output    = (string) ($resp['data']['output'] ?? 'Perintah dieksekusi oleh monweb-agent.php.');
            } else {
                $isSuccess = false;
                $output    = 'Koneksi ke Agent gagal: ' . ($resp['error'] ?? 'Unknown error');
            }
        } elseif ($site['conn_mode'] === 'ssh') {
            $sshRes = $this->runSshCommand($site, $cmdStr);
            $isSuccess = $sshRes['ok'];
            $output    = $sshRes['output'];
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare('
            INSERT INTO deploy_logs (website_id, action_type, command_str, output_text, is_success, executed_at)
            VALUES (:wid, :act, :cmd, :out, :ok, :at)
        ');
        $stmt->execute([
            ':wid' => $id,
            ':act' => strtoupper($action),
            ':cmd' => $cmdStr,
            ':out' => $output !== '' ? $output : '(Tidak ada output standar)',
            ':ok'  => $isSuccess ? 1 : 0,
            ':at'  => $now,
        ]);

        $this->syncSite($id);

        return [
            'ok'        => $isSuccess,
            'command'   => $cmdStr,
            'output'    => $output !== '' ? $output : '(Selesai tanpa output tambahan)',
            'timestamp' => $now,
            'site'      => $this->getSiteById($id),
        ];
    }

    public function executeMassGitPull(): array {
        $sites = $this->getAllSites();
        $logs  = [];
        $okCount = 0;

        foreach ($sites as $s) {
            $res = $this->executeGitAction((int) $s['id'], 'git_pull');
            if ($res['ok']) {
                $okCount++;
            }
            $logs[] = "=== [{$s['name']}] ({$res['command']}) ===\n" . $res['output'];
        }

        return [
            'ok'        => true,
            'total'     => count($sites),
            'succeeded' => $okCount,
            'output'    => empty($logs) ? 'Belum ada website terdaftar.' : implode("\n\n", $logs),
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    public function clearSiteErrorLogs(int $id): array {
        $site = $this->getSiteById($id);
        if (!$site) {
            return ['ok' => false, 'error' => 'Website tidak ditemukan.'];
        }

        $msg = 'Log di database MONWEB dan file error_log telah dibersihkan.';
        if ($site['conn_mode'] === 'local' || $this->isLocalSelfTarget($site)) {
            $cwd = !empty($site['repo_path']) && is_dir($site['repo_path']) ? $site['repo_path'] : dirname(__DIR__);
            foreach ([$cwd . '/error_log', $cwd . '/php_errorlog', $cwd . '/storage/logs/laravel.log'] as $f) {
                if (is_file($f) && is_writable($f)) {
                    @file_put_contents($f, '');
                }
            }
            foreach (glob($cwd . '/application/logs/log-*.php') ?: [] as $cf) {
                if (is_writable($cf)) {
                    @unlink($cf);
                }
            }
        } elseif ($site['conn_mode'] === 'agent' && !empty($site['agent_url'])) {
            $resp = $this->callRemoteAgent($site, [
                'action'    => 'clear_logs',
                'repo_path' => $site['repo_path'],
            ]);
            if ($resp['ok'] && !empty($resp['data']['message'])) {
                $msg = $resp['data']['message'];
            }
        }

        $this->db->prepare('DELETE FROM error_logs WHERE website_id = :id')->execute([':id' => $id]);
        $this->db->prepare('UPDATE websites SET error_count = 0 WHERE id = :id')->execute([':id' => $id]);

        return ['ok' => true, 'message' => $msg];
    }

    public function getRecentErrors(?int $websiteId = null, int $limit = 80): array {
        if ($websiteId) {
            $stmt = $this->db->prepare('
                SELECT e.*, w.name AS site_name, w.url AS site_url
                FROM error_logs e
                JOIN websites w ON w.id = e.website_id
                WHERE e.website_id = :wid
                ORDER BY e.id DESC LIMIT :lim
            ');
            $stmt->bindValue(':wid', $websiteId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }

        $stmt = $this->db->prepare('
            SELECT e.*, w.name AS site_name, w.url AS site_url
            FROM error_logs e
            JOIN websites w ON w.id = e.website_id
            ORDER BY e.id DESC LIMIT :lim
        ');
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getRecentDeployLogs(int $limit = 25): array {
        $stmt = $this->db->prepare('
            SELECT d.*, w.name AS site_name
            FROM deploy_logs d
            JOIN websites w ON w.id = d.website_id
            ORDER BY d.id DESC LIMIT :lim
        ');
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getUptimeHistory(int $limit = 50): array {
        $stmt = $this->db->prepare('
            SELECT u.*, w.name AS site_name, w.url AS site_url
            FROM uptime_checks u
            JOIN websites w ON w.id = u.website_id
            ORDER BY u.id DESC LIMIT :lim
        ');
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function buildDailyWhatsAppReport(): string {
        $sites = $this->getAllSites();
        $total = count($sites);
        $online = 0;
        $down = 0;
        $totalErrors = 0;

        foreach ($sites as $s) {
            if ($s['status'] === 'ONLINE') {
                $online++;
            } elseif ($s['status'] === 'DOWN') {
                $down++;
            }
            $totalErrors += (int) $s['error_count'];
        }

        $dateStr = date('d M Y - H:i');
        $lines = [];
        $lines[] = "*LAPORAN HARIAN MONWEB By AP*";
        $lines[] = "ALBIANDRA PROJECT";
        $lines[] = "Waktu: {$dateStr} WITA";
        $lines[] = "--------------------------------";
        $lines[] = "Total Website: {$total} | Online: {$online} | Down: {$down} | Total Bug/Log: {$totalErrors}";
        $lines[] = "";

        if ($total === 0) {
            $lines[] = "Belum ada website yang didaftarkan pada panel monitoring.";
        } else {
            foreach ($sites as $idx => $s) {
                $num = $idx + 1;
                $st  = $s['status'] === 'ONLINE' ? '[ONLINE]' : ($s['status'] === 'DOWN' ? '[DOWN]' : '[BELUM DICEK]');
                $lines[] = "{$num}. *{$s['name']}* {$st}";
                $lines[] = "   URL: {$s['url']} ({$s['latency_ms']} ms)";
                $lines[] = "   Git: branch `{$s['git_branch']}` @ `{$s['last_commit_hash']}`";
                $lines[] = "   Disk: {$s['disk_used_mb']} MB ({$s['inodes_count']} files) | Error Log: {$s['error_count']}";
            }
        }

        $lines[] = "--------------------------------";
        $lines[] = "Panel Kontrol: https://monweb.baznassumbawa.id";

        return implode("\n", $lines);
    }

    public function dispatchDailyWhatsAppReport(): array {
        $reportText = $this->buildDailyWhatsAppReport();
        $recipient  = preg_replace('/[^0-9]/', '', Database::getSetting('wa_recipient', '081936955747'));
        if (str_starts_with($recipient, '0')) {
            $waIntl = '62' . substr($recipient, 1);
        } else {
            $waIntl = $recipient;
        }

        $webhookUrl   = trim(Database::getSetting('wa_webhook_url', ''));
        $webhookToken = trim(Database::getSetting('wa_webhook_token', ''));

        $apiSent = false;
        $apiResponse = '';

        if ($webhookUrl !== '') {
            $ch = curl_init($webhookUrl);
            $payload = [
                'target'  => $recipient,
                'phone'   => $waIntl,
                'message' => $reportText,
            ];
            $headers = ['Content-Type: application/json'];
            if ($webhookToken !== '') {
                $headers[] = 'Authorization: ' . $webhookToken;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $apiResponse = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $apiSent = ($code >= 200 && $code < 300);
        }

        Database::setSetting('last_daily_report_date', date('Y-m-d'));

        $waDirectUrl = 'https://wa.me/' . $waIntl . '?text=' . rawurlencode($reportText);

        return [
            'ok'            => true,
            'recipient'     => $recipient,
            'report_text'   => $reportText,
            'wa_direct_url' => $waDirectUrl,
            'webhook_sent'  => $apiSent,
            'webhook_resp'  => $apiResponse,
            'dispatched_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function cleanLocalCacheAndSessions(string $cwd): array {
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
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
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

    private function createLocalZipBackup(string $cwd): array {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'Ekstensi ZipArchive tidak tersedia di PHP ini.'];
        }
        $storageDir = dirname(__DIR__) . '/storage/backups';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        $backupName = 'backup_' . basename($cwd) . '_' . date('Ymd_His') . '.zip';
        $backupPath = $storageDir . '/' . $backupName;

        $zip = new ZipArchive();
        if ($zip->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'Gagal membuka file arsip ZIP untuk penulisan.'];
        }

        $count = 0;
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cwd, \FilesystemIterator::SKIP_DOTS)
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
                if ($count >= 6000) {
                    break;
                }
            }
        } catch (Throwable $e) {
        }
        $zip->close();

        return [
            'ok'           => true,
            'file_name'    => $backupName,
            'file_path'    => 'storage/backups/' . $backupName,
            'files_zipped' => $count,
            'size_mb'      => is_file($backupPath) ? round(filesize($backupPath) / 1048576, 2) : 0,
        ];
    }

    private function isLocalSelfTarget(array $site): bool {
        $path = trim((string) ($site['repo_path'] ?? ''));
        return $path !== '' && is_dir($path) && empty($site['ssh_host']);
    }

    private function collectLocalWorkspaceTelemetry(string $customPath = ''): array {
        $cwd = ($customPath !== '' && is_dir($customPath)) ? $customPath : dirname(__DIR__);
        $free = @disk_free_space($cwd);
        $projectBytes = 0;
        $inodes = 0;

        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cwd, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                $inodes++;
                if ($file->isFile()) {
                    $projectBytes += $file->getSize();
                }
                if ($inodes >= 12000) {
                    break;
                }
            }
        } catch (Throwable $e) {
        }

        $branch = 'main';
        $commitHash = '-';
        $commitMsg = 'Folder terhubung (tanpa .git)';
        $dirtyFiles = 0;

        if (is_dir($cwd . '/.git')) {
            $b = $this->runLocalProcess('git rev-parse --abbrev-ref HEAD', $cwd);
            if ($b['exit_code'] === 0 && $b['output'] !== '') {
                $branch = trim($b['output']);
            }
            $h = $this->runLocalProcess('git log -1 --format="%h|%s"', $cwd);
            if ($h['exit_code'] === 0 && $h['output'] !== '') {
                $parts = explode('|', trim($h['output'], '"'), 2);
                $commitHash = $parts[0] ?? '-';
                $commitMsg  = $parts[1] ?? '-';
            }
            $st = $this->runLocalProcess('git status --porcelain', $cwd);
            if ($st['exit_code'] === 0 && $st['output'] !== '') {
                $dirtyFiles = count(explode("\n", trim($st['output'])));
            }
        }

        $errors = [];
        $errCandidates = [
            $cwd . '/error_log',
            $cwd . '/php_errorlog',
            $cwd . '/storage/logs/laravel.log',
        ];
        foreach ($errCandidates as $errFile) {
            if (is_file($errFile) && is_readable($errFile)) {
                $lines = array_slice(file($errFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -30);
                foreach ($lines as $ln) {
                    $errors[] = ['source' => basename($errFile), 'line' => $ln];
                }
            }
        }

        $ciLogDir = $cwd . '/application/logs';
        if (is_dir($ciLogDir) && is_readable($ciLogDir)) {
            $ciFiles = glob($ciLogDir . '/log-*.php') ?: [];
            rsort($ciFiles);
            foreach (array_slice($ciFiles, 0, 2) as $cf) {
                $lines = array_slice(file($cf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -25);
                foreach ($lines as $ln) {
                    if (str_starts_with(trim($ln), '<?php')) {
                        continue;
                    }
                    $errors[] = ['source' => 'application/logs/' . basename($cf), 'line' => $ln];
                }
            }
        }

        return [
            'disk_used_mb' => round($projectBytes / 1048576, 2),
            'disk_free_gb' => $free !== false ? round($free / 1073741824, 2) : 0.0,
            'inodes_count' => $inodes,
            'branch'       => $branch,
            'commit_hash'  => $commitHash,
            'commit_msg'   => $commitMsg,
            'dirty_files'  => $dirtyFiles,
            'errors'       => $errors,
            'visitor_hits' => 1,
        ];
    }

    private function storeErrorEntries(int $websiteId, array $entries): int {
        if (empty($entries)) {
            $stmtCount = $this->db->prepare('SELECT COUNT(*) FROM error_logs WHERE website_id = :wid');
            $stmtCount->execute([':wid' => $websiteId]);
            return (int) $stmtCount->fetchColumn();
        }

        $this->db->prepare('DELETE FROM error_logs WHERE website_id = :wid')->execute([':wid' => $websiteId]);
        $ins = $this->db->prepare('
            INSERT INTO error_logs (website_id, source_file, severity, message, logged_at)
            VALUES (:wid, :src, :sev, :msg, :at)
        ');

        $now = date('Y-m-d H:i:s');
        $count = 0;
        foreach ($entries as $item) {
            $line = trim((string) ($item['line'] ?? ''));
            if ($line === '') {
                continue;
            }
            $sev = 'NOTICE';
            if (stripos($line, 'fatal') !== false || stripos($line, 'exception') !== false || stripos($line, 'error') !== false) {
                $sev = 'ERROR';
            } elseif (stripos($line, 'warn') !== false) {
                $sev = 'WARNING';
            }
            $ins->execute([
                ':wid' => $websiteId,
                ':src' => (string) ($item['source'] ?? 'error_log'),
                ':sev' => $sev,
                ':msg' => $line,
                ':at'  => $now,
            ]);
            $count++;
        }
        return $count;
    }

    private function probeHttpAndSsl(string $url): array {
        $start = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'MONWEB-By-AP-Monitor/2.0 (ALBIANDRA PROJECT)',
        ]);

        @curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        curl_close($ch);

        $sslDaysLeft = -1;
        $host = parse_url($url, PHP_URL_HOST);
        if ($host && str_starts_with(strtolower($url), 'https://')) {
            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
            $client = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 3, STREAM_CLIENT_CONNECT, $ctx);
            if ($client) {
                $params = stream_context_get_params($client);
                if (!empty($params['options']['ssl']['peer_certificate'])) {
                    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
                    if (!empty($cert['validTo_time_t'])) {
                        $sslDaysLeft = (int) floor(($cert['validTo_time_t'] - time()) / 86400);
                    }
                }
                fclose($client);
            }
        }

        return [
            'is_online'     => ($httpCode >= 200 && $httpCode < 500),
            'http_code'     => $httpCode,
            'latency_ms'    => $latencyMs,
            'ssl_days_left' => $sslDaysLeft,
        ];
    }

    private function callRemoteAgent(array $site, array $payload): array {
        $agentUrl = (string) $site['agent_url'];
        $secret   = (string) ($site['agent_secret'] ?: Database::getSetting('master_agent_secret'));
        $body     = json_encode($payload);
        $ts       = (string) time();
        $sig      = hash_hmac('sha256', $ts . '.' . $body, $secret);

        $ch = curl_init($agentUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Monweb-Timestamp: ' . $ts,
                'X-Monweb-Signature: ' . $sig,
            ],
        ]);

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code === 0) {
            return ['ok' => false, 'error' => 'Gagal menghubungi Agent URL (' . ($err ?: 'Timeout') . ')'];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => "Respons Agent bukan JSON valid (HTTP {$code}). Pastikan monweb-agent.php sudah terpasang."];
        }

        if (empty($decoded['ok'])) {
            return ['ok' => false, 'error' => (string) ($decoded['error'] ?? "Agent mengembalikan HTTP {$code}")];
        }

        return ['ok' => true, 'data' => $decoded];
    }

    private function runSshCommand(array $site, string $remoteCmd): array {
        $host = escapeshellarg((string) $site['ssh_host']);
        $port = (int) ($site['ssh_port'] ?: 65002);
        $user = escapeshellarg((string) $site['ssh_user']);
        $path = trim((string) $site['repo_path']) ?: '~/public_html';

        $fullRemote = "cd {$path} && {$remoteCmd}";
        $sshCmd = "ssh -p {$port} -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=6 {$user}@{$host} " . escapeshellarg($fullRemote) . " 2>&1";

        $res = $this->runLocalProcess($sshCmd, dirname(__DIR__));
        return [
            'ok'     => $res['exit_code'] === 0,
            'output' => $res['output'],
        ];
    }

    private function runSshTelemetry(array $site): array {
        $path = trim((string) $site['repo_path']) ?: '~/public_html';
        $script = "cd {$path} && git rev-parse --abbrev-ref HEAD && git log -1 --format='%h|%s' && du -sm . | cut -f1 && find . -maxdepth 3 | wc -l";
        $res = $this->runSshCommand($site, $script);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['output']];
        }
        $lines = explode("\n", trim($res['output']));
        $branch = $lines[0] ?? 'main';
        $commitParts = explode('|', $lines[1] ?? '-|-', 2);
        return [
            'ok'           => true,
            'branch'       => $branch,
            'commit_hash'  => $commitParts[0] ?? '-',
            'commit_msg'   => $commitParts[1] ?? '-',
            'disk_used_mb' => (float) ($lines[2] ?? 0),
            'inodes_count' => (int) ($lines[3] ?? 0),
            'dirty_files'  => 0,
            'errors'       => [],
        ];
    }

    private function runLocalProcess(string $cmd, string $cwd): array {
        if (!function_exists('proc_open')) {
            return ['exit_code' => 1, 'output' => 'proc_open tidak tersedia di PHP ini.'];
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($proc)) {
            return ['exit_code' => 1, 'output' => 'Gagal menjalankan perintah proses.'];
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return [
            'exit_code' => $code,
            'output'    => trim($out . ($err !== '' ? "\n" . $err : '')),
        ];
    }
}
