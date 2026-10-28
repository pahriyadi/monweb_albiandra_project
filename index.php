<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/SiteOperations.php';

use MonWeb\Database;
use MonWeb\SiteOperations;

$tz = Database::getSetting('wa_timezone', 'Asia/Makassar');
@date_default_timezone_set($tz);

$ops        = new SiteOperations();
$sites      = $ops->getAllSites();
$settings   = Database::getAllSettings();
$errorLogs  = $ops->getRecentErrors(null, 80);
$deployLogs = $ops->getRecentDeployLogs(20);
$uptimeLogs = $ops->getUptimeHistory(40);
$waPreview  = $ops->buildDailyWhatsAppReport();

$pinEnabled = ($settings['security_pin_enabled'] ?? '0') === '1';
$isLocked   = $pinEnabled && empty($_SESSION['monweb_unlocked']);

$activeTab = (string) ($_GET['tab'] ?? 'overview');
$validTabs = ['overview', 'automation', 'errors', 'traffic', 'schedule'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'overview';
}

$filterSiteId = isset($_GET['site_id']) && $_GET['site_id'] !== '' ? (int) $_GET['site_id'] : null;
if ($filterSiteId !== null) {
    $errorLogs = $ops->getRecentErrors($filterSiteId, 80);
}

$totalSites  = count($sites);
$onlineSites = 0;
$downSites   = 0;
$totalErrors = 0;

foreach ($sites as $s) {
    if ($s['status'] === 'ONLINE') {
        $onlineSites++;
    } elseif ($s['status'] === 'DOWN') {
        $downSites++;
    }
    $totalErrors += (int) $s['error_count'];
}

$recipientRaw = preg_replace('/[^0-9]/', '', (string) ($settings['wa_recipient'] ?? '081936955747'));
$waIntl = str_starts_with($recipientRaw, '0') ? '62' . substr($recipientRaw, 1) : $recipientRaw;
$waDirectLink = 'https://wa.me/' . $waIntl . '?text=' . rawurlencode($waPreview);
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MONWEB By AP - ALBIANDRA PROJECT</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <a href="#main-content" class="skip-link">Lewati ke konten utama</a>

    <header class="top-header">
        <div class="header-inner">
            <div class="brand-block">
                <div class="brand-mark mono" aria-hidden="true">AP</div>
                <div class="brand-titles">
                    <span class="brand-org">ALBIANDRA PROJECT</span>
                    <h1 class="brand-app">MONWEB By AP</h1>
                </div>
            </div>

            <?php if (!$isLocked): ?>
            <nav class="nav-bar" role="tablist" aria-label="Menu Utama MONWEB By AP">
                <button type="button" class="nav-tab" role="tab" id="tab-btn-overview"
                    aria-selected="<?= $activeTab === 'overview' ? 'true' : 'false' ?>"
                    aria-controls="panel-overview" data-tab="overview">
                    <span>Overview &amp; Git</span>
                    <span class="nav-count tabular"><?= $totalSites ?></span>
                </button>
                <button type="button" class="nav-tab" role="tab" id="tab-btn-automation"
                    aria-selected="<?= $activeTab === 'automation' ? 'true' : 'false' ?>"
                    aria-controls="panel-automation" data-tab="automation">
                    <span>Terminal &amp; Otomasi</span>
                </button>
                <button type="button" class="nav-tab" role="tab" id="tab-btn-errors"
                    aria-selected="<?= $activeTab === 'errors' ? 'true' : 'false' ?>"
                    aria-controls="panel-errors" data-tab="errors">
                    <span>Error &amp; Bug Logs</span>
                    <span class="nav-count tabular"><?= count($errorLogs) ?></span>
                </button>
                <button type="button" class="nav-tab" role="tab" id="tab-btn-traffic"
                    aria-selected="<?= $activeTab === 'traffic' ? 'true' : 'false' ?>"
                    aria-controls="panel-traffic" data-tab="traffic">
                    <span>Traffic &amp; Uptime</span>
                </button>
                <button type="button" class="nav-tab" role="tab" id="tab-btn-schedule"
                    aria-selected="<?= $activeTab === 'schedule' ? 'true' : 'false' ?>"
                    aria-controls="panel-schedule" data-tab="schedule">
                    <span>Jadwal WA 08:00 &amp; Sistem</span>
                </button>
            </nav>
            <?php endif; ?>

            <div class="header-actions">
                <button type="button" class="btn btn-sm" id="btn-theme-toggle" aria-label="Ganti tema gelap atau terang">
                    <span id="theme-label">Mode Terang</span>
                </button>
                <?php if (!$isLocked): ?>
                    <?php if ($pinEnabled): ?>
                        <button type="button" class="btn btn-sm" id="btn-lock-console">
                            <span>Kunci Konsol</span>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary btn-sm" id="btn-open-add-modal">
                        <span>+ Tambah Website</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main id="main-content" class="main-container">
        <div id="global-status-banner" class="status-banner" role="status" aria-live="polite" hidden>
            <span id="global-status-text">Memuat status...</span>
            <button type="button" class="btn btn-sm" id="btn-close-banner">Tutup</button>
        </div>

        <?php if ($isLocked): ?>
            <section class="panel" style="max-width:460px; margin:3rem auto; width:100%;">
                <div class="panel-header">
                    <div>
                        <h2 class="panel-title">Konsol Terkunci (Proteksi PIN)</h2>
                        <p class="panel-subtitle">Masukkan kode PIN Keamanan ALBIANDRA PROJECT untuk mengakses kendali Git &amp; Server.</p>
                    </div>
                </div>
                <form id="form-unlock-pin" class="form-body">
                    <div class="form-group">
                        <label class="form-label" for="unlock_pin_input">Kode PIN Keamanan</label>
                        <input type="password" id="unlock_pin_input" name="pin_code" class="form-control mono"
                            placeholder="Masukkan PIN" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;">Buka Konsol MONWEB By AP</button>
                </form>
            </section>
        <?php else: ?>

        <section class="ops-CommandStrip" aria-label="Ringkasan Operasional">
            <div>
                <h2 class="ops-headline-title">Konsol Kendali Website, Git &amp; Otomasi Server</h2>
                <p class="ops-headline-sub">
                    Kelola semua website, jalankan <code class="mono">git pull</code> massal, bersihkan cache/inodes, dan pantau bug tanpa buka cPanel/hPanel.
                </p>
            </div>

            <div class="ops-metrics-inline">
                <div class="metric-pair">
                    <span class="metric-label">Terdaftar</span>
                    <span class="metric-val tabular" id="stat-total"><?= $totalSites ?> Site</span>
                </div>
                <div class="metric-pair">
                    <span class="metric-label">Online</span>
                    <span class="metric-val tabular" id="stat-online"><?= $onlineSites ?></span>
                </div>
                <div class="metric-pair">
                    <span class="metric-label">Down</span>
                    <span class="metric-val tabular" id="stat-down"><?= $downSites ?></span>
                </div>
                <div class="metric-pair">
                    <span class="metric-label">Jadwal WA</span>
                    <span class="metric-val mono"><?= htmlspecialchars($settings['wa_schedule_time'] ?? '08:00') ?></span>
                </div>
            </div>

            <div class="ops-strip-controls">
                <button type="button" class="btn btn-primary" id="btn-auto-discover">
                    <span>Auto-Scan Folder Hosting</span>
                </button>
                <button type="button" class="btn" id="btn-mass-git-pull">
                    <span>Git Pull Semua</span>
                </button>
                <button type="button" class="btn" id="btn-sync-all">
                    <span>Sinkronkan Semua</span>
                </button>
            </div>
        </section>

        <!-- TAB 1: OVERVIEW & GIT DEPLOY -->
        <section id="panel-overview" role="tabpanel" aria-labelledby="tab-btn-overview" <?= $activeTab !== 'overview' ? 'hidden' : '' ?>>
            <div class="workspace-split">
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">Matriks Website &amp; Kendali Cepat</h3>
                            <p class="panel-subtitle">Klik <strong>Auto-Scan Folder Hosting</strong> di atas untuk mendeteksi semua subdomain/website di akun cPanel ini secara otomatis.</p>
                        </div>
                        <a href="api.php?download_agent=1" class="btn btn-sm">
                            <span>Unduh monweb-agent.php</span>
                        </a>
                    </div>

                    <?php if (empty($sites)): ?>
                        <div class="empty-state">
                            <h4 class="empty-title">Belum Ada Website yang Dipantau</h4>
                            <p class="empty-desc">
                                Anda dapat memindai seluruh website/subdomain yang ada di dalam folder <code class="mono">public_html</code> hosting Anda secara otomatis hanya dengan 1 klik, atau menambahkannya secara manual.
                            </p>
                            <div style="display:flex; gap:0.6rem; flex-wrap:wrap; justify-content:center;">
                                <button type="button" class="btn btn-primary" id="btn-empty-discover">
                                    <span>Auto-Scan Semua Website di Hosting Ini</span>
                                </button>
                                <button type="button" class="btn" id="btn-empty-add-site">
                                    <span>+ Tambah Website Manual</span>
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="site-matrix">
                            <?php foreach ($sites as $site):
                                $statusClass = 'tag';
                                $statusLabel = 'BELUM DICEK';
                                if ($site['status'] === 'ONLINE') {
                                    $statusClass = 'tag tag-online';
                                    $statusLabel = 'ONLINE (' . (int) $site['http_code'] . ')';
                                } elseif ($site['status'] === 'DOWN') {
                                    $statusClass = 'tag tag-down';
                                    $statusLabel = 'DOWN (' . (int) $site['http_code'] . ')';
                                }
                                $diskPercent = min(100, max(4, (int) round(((float) $site['disk_used_mb'] / 1024) * 100)));
                            ?>
                            <article class="site-row" data-site-id="<?= (int) $site['id'] ?>">
                                <div class="site-identity">
                                    <div class="site-title-line">
                                        <span class="<?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                        <strong class="site-name"><?= htmlspecialchars($site['name']) ?></strong>
                                    </div>
                                    <a href="<?= htmlspecialchars($site['url']) ?>" target="_blank" rel="noopener" class="site-url mono">
                                        <?= htmlspecialchars($site['url']) ?>
                                    </a>
                                    <div class="site-meta-tags">
                                        <span class="tag"><?= htmlspecialchars($site['stack_type']) ?></span>
                                        <span class="tag mono">Mode: <?= strtoupper(htmlspecialchars($site['conn_mode'])) ?></span>
                                        <span class="tag mono">ID Webhook: #<?= (int) $site['id'] ?></span>
                                        <?php if ((int) $site['ssl_days_left'] >= 0): ?>
                                            <span class="tag <?= (int) $site['ssl_days_left'] < 14 ? 'tag-warn' : '' ?>">
                                                SSL: <?= (int) $site['ssl_days_left'] ?> hari
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="site-git-box">
                                    <div class="git-top-line">
                                        <span class="git-branch-name mono">branch: <?= htmlspecialchars($site['git_branch']) ?></span>
                                        <span class="git-commit-hash mono"><?= htmlspecialchars($site['last_commit_hash']) ?></span>
                                    </div>
                                    <div class="git-commit-msg" title="<?= htmlspecialchars($site['last_commit_msg']) ?>">
                                        <?= htmlspecialchars($site['last_commit_msg']) ?>
                                    </div>
                                    <div class="git-top-line" style="color: var(--text-muted); font-size: 0.75rem;">
                                        <span>Dirty: <?= (int) $site['dirty_files'] ?> file</span>
                                        <span>Ping: <?= (int) $site['latency_ms'] ?> ms</span>
                                    </div>
                                </div>

                                <div class="site-resource-box">
                                    <div class="resource-line">
                                        <span>Disk: <strong class="tabular"><?= number_format((float) $site['disk_used_mb'], 2) ?> MB</strong></span>
                                        <span class="tabular"><?= number_format((int) $site['inodes_count']) ?> inodes</span>
                                    </div>
                                    <div class="meter-track" aria-hidden="true">
                                        <div class="meter-fill" style="width: <?= $diskPercent ?>%;"></div>
                                    </div>
                                    <div class="resource-line">
                                        <span>Error Log: <strong class="<?= (int) $site['error_count'] > 0 ? 'tag-down' : '' ?> tabular" style="padding:0 4px; border-radius:3px;"><?= (int) $site['error_count'] ?> baris</strong></span>
                                        <span>Hits: <strong class="tabular"><?= (int) $site['visitor_hits_today'] ?></strong></span>
                                    </div>
                                </div>

                                <div class="site-actions">
                                    <button type="button" class="btn btn-primary btn-sm js-git-action"
                                        data-id="<?= (int) $site['id'] ?>" data-git="git_pull"
                                        data-name="<?= htmlspecialchars($site['name']) ?>">
                                        <span>Git Pull</span>
                                    </button>
                                    <button type="button" class="btn btn-sm js-git-action"
                                        data-id="<?= (int) $site['id'] ?>" data-git="git_status"
                                        data-name="<?= htmlspecialchars($site['name']) ?>">
                                        <span>Status</span>
                                    </button>
                                    <button type="button" class="btn btn-sm js-git-action"
                                        data-id="<?= (int) $site['id'] ?>" data-git="clean_cache"
                                        data-name="<?= htmlspecialchars($site['name']) ?>">
                                        <span>Bersihkan Cache</span>
                                    </button>
                                    <button type="button" class="btn btn-sm js-sync-site"
                                        data-id="<?= (int) $site['id'] ?>"
                                        data-name="<?= htmlspecialchars($site['name']) ?>">
                                        <span>Cek</span>
                                    </button>
                                    <button type="button" class="btn btn-danger btn-sm js-delete-site"
                                        data-id="<?= (int) $site['id'] ?>"
                                        data-name="<?= htmlspecialchars($site['name']) ?>">
                                        <span>Hapus</span>
                                    </button>
                                </div>
                            </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- LIVE GIT & DEPLOYMENT OUTPUT DRAWER -->
                <aside class="panel" aria-label="Output Eksekusi Git dan Riwayat Deploy">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">Output Konsol Langsung</h3>
                            <p class="panel-subtitle">Hasil nyata dari perintah Git, Pembersih Cache, dan Backup.</p>
                        </div>
                    </div>
                    <pre id="git-console-box" class="console-output mono">Pilih aksi "Git Pull", "Status", atau "Bersihkan Cache" pada salah satu website untuk melihat output langsung dari server hosting Anda.</pre>

                    <div class="panel-header" style="border-top: 1px solid var(--border-subtle);">
                        <h4 class="panel-title" style="font-size: 0.9rem;">Riwayat Eksekusi Terakhir</h4>
                    </div>
                    <?php if (empty($deployLogs)): ?>
                        <div class="empty-state" style="padding: 1.25rem;">
                            <p class="empty-desc">Belum ada perintah yang dijalankan. Klik tombol <strong>Git Pull</strong> atau <strong>Status</strong> untuk mencatat riwayat.</p>
                        </div>
                    <?php else: ?>
                        <ul class="deploy-history-list">
                            <?php foreach ($deployLogs as $dlog): ?>
                                <li class="deploy-history-item">
                                    <div style="display:flex; justify-content:space-between; gap:0.5rem;">
                                        <strong><?= htmlspecialchars($dlog['site_name']) ?></strong>
                                        <span class="mono" style="color:var(--text-muted);"><?= htmlspecialchars($dlog['executed_at']) ?></span>
                                    </div>
                                    <div class="mono" style="font-size:0.75rem; color:var(--text-secondary);">
                                        $ <?= htmlspecialchars($dlog['command_str']) ?>
                                        (<?= (int) $dlog['is_success'] === 1 ? 'SUKSES' : 'GAGAL' ?>)
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </aside>
            </div>
        </section>

        <!-- TAB 2: TERMINAL & OTOMASI POWERFUL -->
        <section id="panel-automation" role="tabpanel" aria-labelledby="tab-btn-automation" <?= $activeTab !== 'automation' ? 'hidden' : '' ?>>
            <div class="settings-grid">
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">Remote Command Runner &amp; Otomasi Website</h3>
                            <p class="panel-subtitle">Jalankan perintah Git tingkat lanjut, Composer, pembersihan cache CodeIgniter/Laravel, atau buat Backup ZIP tanpa buka cPanel.</p>
                        </div>
                    </div>

                    <?php if (empty($sites)): ?>
                        <div class="empty-state">
                            <h4 class="empty-title">Tambahkan Website Terlebih Dahulu</h4>
                            <p class="empty-desc">Klik tombol <strong>Auto-Scan Folder Hosting</strong> di atas agar daftar website muncul di pilihan target eksekusi.</p>
                        </div>
                    <?php else: ?>
                        <form id="form-remote-runner" class="form-body">
                            <div class="form-group">
                                <label class="form-label" for="runner_site_id">Pilih Website Target</label>
                                <select id="runner_site_id" class="form-control" required>
                                    <?php foreach ($sites as $s): ?>
                                        <option value="<?= (int) $s['id'] ?>">
                                            <?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['repo_path'] ?: $s['url']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <span class="form-label">Tombol Otomasi Satu Klik (Preset)</span>
                                <div style="display:flex; flex-wrap:wrap; gap:0.5rem;">
                                    <button type="button" class="btn btn-sm js-preset-action" data-git="git_pull">Git Pull Origin</button>
                                    <button type="button" class="btn btn-sm js-preset-action" data-git="git_reset">Git Force Reset (Atasi Konflik Pull)</button>
                                    <button type="button" class="btn btn-sm js-preset-action" data-git="git_log">Lihat 8 Commit Terakhir</button>
                                    <button type="button" class="btn btn-sm js-preset-action" data-git="clean_cache">Hapus Cache &amp; Session CI/Laravel</button>
                                    <button type="button" class="btn btn-sm js-preset-action" data-git="backup_zip">Buat Backup ZIP Proyek</button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="runner_custom_cmd">Atau Ketik Perintah Terminal / Shell Kustom</label>
                                <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                                    <input type="text" id="runner_custom_cmd" class="form-control mono" style="flex:1; min-width:220px;"
                                        placeholder="Contoh: git branch -a  atau  composer install --no-dev">
                                    <button type="submit" class="btn btn-primary">Jalankan Perintah</button>
                                </div>
                                <span class="form-hint">Perintah dieksekusi langsung di dalam folder proyek website yang dipilih.</span>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">Layar Output Terminal Eksekusi</h3>
                            <p class="panel-subtitle">Menampilkan stdout &amp; stderr langsung dari server.</p>
                        </div>
                    </div>
                    <pre id="automation-console-box" class="console-output mono" style="min-height:320px;">Siap menerima perintah. Pilih website di sebelah kiri lalu klik tombol preset atau jalankan perintah terminal kustom.</pre>
                </div>
            </div>
        </section>

        <!-- TAB 3: ERROR & BUG LOGS -->
        <section id="panel-errors" role="tabpanel" aria-labelledby="tab-btn-errors" <?= $activeTab !== 'errors' ? 'hidden' : '' ?>>
            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">Inspektur Bug &amp; Error Log (PHP Native &amp; CodeIgniter)</h3>
                        <p class="panel-subtitle">Menarik isi file <code class="mono">error_log</code> dan <code class="mono">application/logs/log-*.php</code> dari website tanpa membuka File Manager Niagahoster.</p>
                    </div>
                    <div style="display:flex; flex-wrap:wrap; gap:0.5rem; align-items:center;">
                        <form method="get" action="index.php" style="display:flex; gap:0.5rem; align-items:center;">
                            <input type="hidden" name="tab" value="errors">
                            <label for="filter-site-select" class="form-label" style="margin:0;">Filter Website:</label>
                            <select name="site_id" id="filter-site-select" class="form-control" style="width:auto; min-width:200px;">
                                <option value="">Semua Website (<?= $totalSites ?>)</option>
                                <?php foreach ($sites as $s): ?>
                                    <option value="<?= (int) $s['id'] ?>" <?= $filterSiteId === (int) $s['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['name']) ?> (<?= (int) $s['error_count'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-sm">Terapkan</button>
                        </form>
                        <?php if ($filterSiteId !== null): ?>
                            <button type="button" class="btn btn-danger btn-sm" id="btn-clear-filtered-logs" data-id="<?= (int) $filterSiteId ?>">
                                <span>Bersihkan Log Website Ini</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (empty($errorLogs)): ?>
                    <div class="empty-state">
                        <h4 class="empty-title">Tidak Ada Catatan Error / Bug Ditemukan</h4>
                        <p class="empty-desc">
                            File <code class="mono">error_log</code> pada website yang dipantau sedang bersih, atau Anda belum melakukan sinkronisasi. Klik tombol <strong>Sinkronkan Semua</strong> di atas untuk menarik log terbaru.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="ops-table">
                            <thead>
                                <tr>
                                    <th>Level</th>
                                    <th>Website</th>
                                    <th>Sumber File</th>
                                    <th>Pesan Error / Stack Trace</th>
                                    <th>Waktu Tarik</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($errorLogs as $err):
                                    $sevBadge = 'tag';
                                    if ($err['severity'] === 'ERROR') {
                                        $sevBadge = 'tag tag-down';
                                    } elseif ($err['severity'] === 'WARNING') {
                                        $sevBadge = 'tag tag-warn';
                                    }
                                ?>
                                <tr>
                                    <td><span class="<?= $sevBadge ?> mono"><?= htmlspecialchars($err['severity']) ?></span></td>
                                    <td><strong><?= htmlspecialchars($err['site_name']) ?></strong></td>
                                    <td class="mono"><?= htmlspecialchars($err['source_file']) ?></td>
                                    <td class="mono" style="word-break:break-word;"><?= htmlspecialchars($err['message']) ?></td>
                                    <td class="mono" style="white-space:nowrap;"><?= htmlspecialchars($err['logged_at']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- TAB 4: TRAFFIC & UPTIME -->
        <section id="panel-traffic" role="tabpanel" aria-labelledby="tab-btn-traffic" <?= $activeTab !== 'traffic' ? 'hidden' : '' ?>>
            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">Riwayat Pemeriksaan Uptime, Kecepatan Respon &amp; Trafik</h3>
                        <p class="panel-subtitle">Catatan waktu respon HTTP nyata (dalam milidetik) dan jumlah kunjungan harian yang direkam oleh agent.</p>
                    </div>
                </div>

                <?php if (empty($uptimeLogs)): ?>
                    <div class="empty-state">
                        <h4 class="empty-title">Belum Ada Riwayat Pemeriksaan Uptime</h4>
                        <p class="empty-desc">
                            Tambahkan website atau klik <strong>Sinkronkan Semua</strong> untuk merekam kode respons HTTP dan kecepatan koneksi server.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table class="ops-table">
                            <thead>
                                <tr>
                                    <th>Waktu Cek</th>
                                    <th>Website</th>
                                    <th>URL</th>
                                    <th>Status HTTP</th>
                                    <th>Kecepatan Respon</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($uptimeLogs as $up): ?>
                                <tr>
                                    <td class="mono"><?= htmlspecialchars($up['checked_at']) ?></td>
                                    <td><strong><?= htmlspecialchars($up['site_name']) ?></strong></td>
                                    <td class="mono"><?= htmlspecialchars($up['site_url']) ?></td>
                                    <td>
                                        <span class="tag <?= (int) $up['is_online'] === 1 ? 'tag-online' : 'tag-down' ?> mono">
                                            <?= (int) $up['is_online'] === 1 ? 'ONLINE' : 'DOWN' ?> (HTTP <?= (int) $up['http_code'] ?>)
                                        </span>
                                    </td>
                                    <td class="mono tabular"><?= (int) $up['latency_ms'] ?> ms</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- TAB 5: JADWAL WA 08:00 & SISTEM -->
        <section id="panel-schedule" role="tabpanel" aria-labelledby="tab-btn-schedule" <?= $activeTab !== 'schedule' ? 'hidden' : '' ?>>
            <div class="settings-grid">
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">Otomasi Laporan WA Jam 08:00 &amp; Keamanan PIN</h3>
                            <p class="panel-subtitle">Atur laporan harian ke <strong>081936955747</strong> dan kunci PIN pengaman konsol.</p>
                        </div>
                    </div>

                    <form id="form-wa-settings" class="form-body">
                        <input type="hidden" name="action" value="save_settings">

                        <div class="form-row-2">
                            <div class="form-group">
                                <label class="form-label" for="wa_recipient">Nomor WhatsApp Tujuan</label>
                                <input type="text" id="wa_recipient" name="wa_recipient" class="form-control mono"
                                    value="<?= htmlspecialchars($settings['wa_recipient'] ?? '081936955747') ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="wa_schedule_time">Jam Pemicu Harian</label>
                                <input type="time" id="wa_schedule_time" name="wa_schedule_time" class="form-control mono"
                                    value="<?= htmlspecialchars($settings['wa_schedule_time'] ?? '08:00') ?>" required>
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label class="form-label" for="wa_timezone">Zona Waktu Server</label>
                                <select id="wa_timezone" name="wa_timezone" class="form-control">
                                    <?php
                                    $tzOptions = [
                                        'Asia/Makassar' => 'WITA - Sumbawa / Bali / Makassar (UTC+8)',
                                        'Asia/Jakarta'  => 'WIB - Jakarta / Surabaya (UTC+7)',
                                        'Asia/Jayapura' => 'WIT - Jayapura (UTC+9)',
                                    ];
                                    $curTz = $settings['wa_timezone'] ?? 'Asia/Makassar';
                                    foreach ($tzOptions as $tzVal => $tzLabel):
                                    ?>
                                        <option value="<?= $tzVal ?>" <?= $curTz === $tzVal ? 'selected' : '' ?>><?= $tzLabel ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="wa_auto_trigger">Auto-Popup Jam 08:00 di Dashboard</label>
                                <select id="wa_auto_trigger" name="wa_auto_trigger" class="form-control">
                                    <option value="1" <?= ($settings['wa_auto_trigger'] ?? '1') === '1' ? 'selected' : '' ?>>Aktif (Sinkron &amp; Buka WA Otomatis Jam 08:00)</option>
                                    <option value="0" <?= ($settings['wa_auto_trigger'] ?? '1') === '0' ? 'selected' : '' ?>>Nonaktif (Hanya via Cron / Tombol Manual)</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row-2">
                            <div class="form-group">
                                <label class="form-label" for="security_pin_enabled">Proteksi PIN Konsol MONWEB</label>
                                <select id="security_pin_enabled" name="security_pin_enabled" class="form-control">
                                    <option value="0" <?= ($settings['security_pin_enabled'] ?? '0') === '0' ? 'selected' : '' ?>>Nonaktif (Langsung Buka Dashboard)</option>
                                    <option value="1" <?= ($settings['security_pin_enabled'] ?? '0') === '1' ? 'selected' : '' ?>>Aktif (Wajib Masukkan PIN)</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="security_pin_code">Kode PIN Keamanan</label>
                                <input type="text" id="security_pin_code" name="security_pin_code" class="form-control mono"
                                    value="<?= htmlspecialchars($settings['security_pin_code'] ?? '123456') ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="wa_webhook_url">Opsional: Local/Self-Hosted WA Bridge URL (Kosongkan jika tidak pakai)</label>
                            <input type="url" id="wa_webhook_url" name="wa_webhook_url" class="form-control mono"
                                placeholder="https://localhost:3000/send-message"
                                value="<?= htmlspecialchars($settings['wa_webhook_url'] ?? '') ?>">
                        </div>

                        <div style="display:flex; flex-wrap:wrap; gap:0.65rem; padding-top:0.25rem;">
                            <button type="submit" class="btn btn-primary">Simpan Pengaturan</button>
                            <button type="button" class="btn" id="btn-trigger-wa-now">
                                <span>Picu &amp; Kirim Laporan ke 081936955747 Sekarang</span>
                            </button>
                        </div>
                    </form>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">Pratinjau Laporan WA &amp; Webhook GitHub</h3>
                            <p class="panel-subtitle">Isi pesan yang otomatis dikirim setiap pukul 08:00 pagi.</p>
                        </div>
                        <a href="<?= htmlspecialchars($waDirectLink) ?>" target="_blank" rel="noopener" class="btn btn-primary btn-sm" id="link-wa-direct">
                            <span>Buka di WhatsApp (081936955747)</span>
                        </a>
                    </div>

                    <pre id="wa-report-preview" class="console-output mono" style="max-height:240px;"><?= htmlspecialchars($waPreview) ?></pre>

                    <div class="form-body">
                        <div class="form-group">
                            <label class="form-label" for="master-secret-display">Master Agent Secret Key Anda</label>
                            <input type="text" id="master-secret-display" class="form-control mono" readonly
                                value="<?= htmlspecialchars($settings['master_agent_secret'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="cron-command-display">Baris Cronjob Niagahoster (08:00 Pagi Setiap Hari)</label>
                            <input type="text" id="cron-command-display" class="form-control mono" readonly
                                value="0 8 * * * /usr/local/bin/php /home/n1579664/public_html/monweb.baznassumbawa.id/cron.php --daily-report">
                            <span class="form-hint">
                                URL GitHub Auto-Deploy Webhook (pasang di GitHub Settings -&gt; Webhooks):<br>
                                <code class="mono">https://monweb.baznassumbawa.id/cron.php?mode=github_webhook&amp;site_id=ID_WEBSITE</code>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <?php endif; ?>
    </main>

    <!-- MODAL TAMBAH WEBSITE -->
    <div id="modal-add-site" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="modal-add-title" hidden>
        <div class="modal-card">
            <div class="panel-header">
                <div>
                    <h3 id="modal-add-title" class="panel-title">Tambah Website ke MONWEB By AP</h3>
                    <p class="panel-subtitle">Pilih koneksi via Folder Lokal (Satu Hosting), Agent API (<code class="mono">monweb-agent.php</code>), atau SSH.</p>
                </div>
                <button type="button" class="btn btn-sm" id="btn-close-modal">Tutup (Esc)</button>
            </div>

            <form id="form-add-site" class="form-body">
                <input type="hidden" name="action" value="add_site">

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="site_name">Nama Website / Proyek</label>
                        <input type="text" id="site_name" name="name" class="form-control"
                            placeholder="Contoh: Portal BAZNAS Sumbawa" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="site_url">URL Domain / Subdomain</label>
                        <input type="text" id="site_url" name="url" class="form-control mono"
                            placeholder="https://baznassumbawa.id" required>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="stack_type">Stack / Teknologi</label>
                        <select id="stack_type" name="stack_type" class="form-control">
                            <option value="CodeIgniter 3/4">CodeIgniter 3 / 4</option>
                            <option value="PHP Native">PHP Native</option>
                            <option value="JavaScript / Node">JavaScript / Static / Node</option>
                            <option value="Laravel">Laravel</option>
                            <option value="WordPress">WordPress</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="hosting_panel">Jenis Panel Hosting</label>
                        <select id="hosting_panel" name="hosting_panel" class="form-control">
                            <option value="cPanel Unlimited">cPanel Unlimited Hosting</option>
                            <option value="hPanel Niagahoster">hPanel Niagahoster</option>
                            <option value="VPS / Cloud Server">VPS / Cloud Server</option>
                        </select>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="conn_mode">Metode Koneksi Git &amp; Disk</label>
                        <select id="conn_mode" name="conn_mode" class="form-control">
                            <option value="local">Folder Server Lokal (Satu Hosting dengan MONWEB)</option>
                            <option value="agent">Agent API (monweb-agent.php di Hosting Lain)</option>
                            <option value="ssh">SSH Langsung (Port 65002 / 22)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="git_branch">Branch Git Utama</label>
                        <input type="text" id="git_branch" name="git_branch" class="form-control mono"
                            placeholder="main" value="main">
                    </div>
                </div>

                <div id="conn-fields-agent" class="form-group" hidden>
                    <label class="form-label" for="agent_url">URL File monweb-agent.php (Opsional, otomatis mengikuti domain)</label>
                    <input type="text" id="agent_url" name="agent_url" class="form-control mono"
                        placeholder="https://domain-lain.id/monweb-agent.php">
                </div>

                <div id="conn-fields-ssh" hidden>
                    <div class="form-row-2" style="margin-bottom:0.85rem;">
                        <div class="form-group">
                            <label class="form-label" for="ssh_host">SSH Host / IP Server</label>
                            <input type="text" id="ssh_host" name="ssh_host" class="form-control mono"
                                placeholder="185.229.118.74">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="ssh_port">SSH Port (hPanel: 65002, cPanel: 22)</label>
                            <input type="number" id="ssh_port" name="ssh_port" class="form-control mono"
                                value="65002">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ssh_user">Username SSH Hosting</label>
                        <input type="text" id="ssh_user" name="ssh_user" class="form-control mono"
                            placeholder="n1579664">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="repo_path">Path Folder di Server</label>
                    <input type="text" id="repo_path" name="repo_path" class="form-control mono"
                        placeholder="/home/n1579664/public_html/nama-website">
                    <span class="form-hint">Contoh untuk domain utama: <code class="mono">/home/n1579664/public_html</code></span>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:0.65rem; padding-top:0.5rem;">
                    <button type="button" class="btn" id="btn-cancel-modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btn-submit-site">Simpan &amp; Sinkronkan</button>
                </div>
            </form>
        </div>
    </div>

    <footer class="app-footer">
        <div>
            <strong>MONWEB By AP</strong> // Dikembangkan untuk <strong>ALBIANDRA PROJECT</strong> (<code class="mono">monweb.baznassumbawa.id</code>)
        </div>
        <div class="mono">
            Laporan Harian WA: <?= htmlspecialchars($settings['wa_recipient'] ?? '081936955747') ?> @ <?= htmlspecialchars($settings['wa_schedule_time'] ?? '08:00') ?>
        </div>
    </footer>

    <script>
        window.MONWEB_CONFIG = {
            scheduleTime: <?= json_encode($settings['wa_schedule_time'] ?? '08:00') ?>,
            autoTrigger: <?= json_encode(($settings['wa_auto_trigger'] ?? '1') === '1') ?>,
            lastReportDate: <?= json_encode($settings['last_daily_report_date'] ?? '') ?>,
            todayDate: <?= json_encode(date('Y-m-d')) ?>,
            selfPath: <?= json_encode(__DIR__) ?>
        };
    </script>
    <script src="assets/app.js"></script>
</body>
</html>
