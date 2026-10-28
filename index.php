<?php
declare(strict_types=1);

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

$activeTab = (string) ($_GET['tab'] ?? 'overview');
$validTabs = ['overview', 'errors', 'traffic', 'schedule'];
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

            <nav class="nav-bar" role="tablist" aria-label="Menu Utama MONWEB By AP">
                <button type="button" class="nav-tab" role="tab" id="tab-btn-overview"
                    aria-selected="<?= $activeTab === 'overview' ? 'true' : 'false' ?>"
                    aria-controls="panel-overview" data-tab="overview">
                    <span>Overview &amp; Git Deploy</span>
                    <span class="nav-count tabular"><?= $totalSites ?></span>
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
                    <span>Jadwal WA 08:00 &amp; Agent</span>
                </button>
            </nav>

            <div class="header-actions">
                <button type="button" class="btn btn-sm" id="btn-theme-toggle" aria-label="Ganti tema gelap atau terang">
                    <span id="theme-label">Mode Terang</span>
                </button>
                <button type="button" class="btn btn-primary btn-sm" id="btn-open-add-modal">
                    <span>+ Tambah Website</span>
                </button>
            </div>
        </div>
    </header>

    <main id="main-content" class="main-container">
        <div id="global-status-banner" class="status-banner" role="status" aria-live="polite" hidden>
            <span id="global-status-text">Memuat status...</span>
            <button type="button" class="btn btn-sm" id="btn-close-banner">Tutup</button>
        </div>

        <section class="ops-CommandStrip" aria-label="Ringkasan Operasional">
            <div>
                <h2 class="ops-headline-title">Konsol Kendali Website &amp; Git Niagahoster</h2>
                <p class="ops-headline-sub">
                    Pantau disk, inode, error log PHP/CodeIgniter, dan jalankan <code class="mono">git pull</code> tanpa membuka hPanel/cPanel.
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
                <button type="button" class="btn" id="btn-sync-all">
                    <span>Sinkronkan Semua Website</span>
                </button>
                <a href="api.php?download_agent=1" class="btn">
                    <span>Unduh monweb-agent.php</span>
                </a>
            </div>
        </section>

        <!-- TAB 1: OVERVIEW & GIT DEPLOY -->
        <section id="panel-overview" role="tabpanel" aria-labelledby="tab-btn-overview" <?= $activeTab !== 'overview' ? 'hidden' : '' ?>>
            <div class="workspace-split">
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">Daftar Website &amp; Repositori Git</h3>
                            <p class="panel-subtitle">Mendukung koneksi Agent API (<code class="mono">monweb-agent.php</code>), SSH (cPanel/hPanel), maupun Folder Lokal.</p>
                        </div>
                    </div>

                    <?php if (empty($sites)): ?>
                        <div class="empty-state">
                            <h4 class="empty-title">Belum Ada Website yang Dipantau</h4>
                            <p class="empty-desc">
                                Tambahkan website pertama Anda (misalnya domain utama atau aplikasi di Niagahoster) untuk mulai memantau kapasitas disk, membaca file <code class="mono">error_log</code>, dan menjalankan <code class="mono">git pull</code> dari satu tempat.
                            </p>
                            <div style="display:flex; gap:0.6rem; flex-wrap:wrap; justify-content:center;">
                                <button type="button" class="btn btn-primary" id="btn-empty-add-site">
                                    <span>+ Tambah Website Sekarang</span>
                                </button>
                                <button type="button" class="btn" id="btn-register-self">
                                    <span>Pantau Folder MONWEB Ini Sendiri (Tes Langsung)</span>
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
                                        <span class="tag"><?= htmlspecialchars($site['hosting_panel']) ?></span>
                                        <span class="tag mono">Mode: <?= strtoupper(htmlspecialchars($site['conn_mode'])) ?></span>
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
                                        <span>Perubahan lokal: <?= (int) $site['dirty_files'] ?> file</span>
                                        <span>Ping: <?= (int) $site['latency_ms'] ?> ms</span>
                                    </div>
                                </div>

                                <div class="site-resource-box">
                                    <div class="resource-line">
                                        <span>Ukuran Proyek: <strong class="tabular"><?= number_format((float) $site['disk_used_mb'], 2) ?> MB</strong></span>
                                        <span class="tabular"><?= number_format((int) $site['inodes_count']) ?> file</span>
                                    </div>
                                    <div class="meter-track" aria-hidden="true">
                                        <div class="meter-fill" style="width: <?= $diskPercent ?>%;"></div>
                                    </div>
                                    <div class="resource-line">
                                        <span>Bug / Error Log: <strong class="<?= (int) $site['error_count'] > 0 ? 'tag-down' : '' ?> tabular" style="padding:0 4px; border-radius:3px;"><?= (int) $site['error_count'] ?> baris</strong></span>
                                        <span>Hits Hari Ini: <strong class="tabular"><?= (int) $site['visitor_hits_today'] ?></strong></span>
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
                                        <span>Git Status</span>
                                    </button>
                                    <button type="button" class="btn btn-sm js-sync-site"
                                        data-id="<?= (int) $site['id'] ?>"
                                        data-name="<?= htmlspecialchars($site['name']) ?>">
                                        <span>Cek Ulang</span>
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
                            <h3 class="panel-title">Output Eksekusi Git &amp; SSH/Agent</h3>
                            <p class="panel-subtitle">Hasil nyata dari perintah <code class="mono">git pull</code> atau <code class="mono">git status</code>.</p>
                        </div>
                    </div>
                    <pre id="git-console-box" class="console-output mono">Pilih tombol "Git Pull" atau "Git Status" pada salah satu website untuk melihat output langsung dari server hosting Anda.</pre>

                    <div class="panel-header" style="border-top: 1px solid var(--border-subtle);">
                        <h4 class="panel-title" style="font-size: 0.9rem;">Riwayat Eksekusi Terakhir</h4>
                    </div>
                    <?php if (empty($deployLogs)): ?>
                        <div class="empty-state" style="padding: 1.25rem;">
                            <p class="empty-desc">Belum ada perintah Git yang dijalankan. Klik tombol <strong>Git Pull</strong> atau <strong>Git Status</strong> pada baris website untuk mencatat riwayat.</p>
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

        <!-- TAB 2: ERROR & BUG LOGS -->
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
                            File <code class="mono">error_log</code> pada website yang dipantau sedang bersih, atau Anda belum melakukan sinkronisasi. Klik tombol <strong>Sinkronkan Semua Website</strong> di atas untuk menarik log terbaru.
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

        <!-- TAB 3: TRAFFIC & UPTIME -->
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
                            Tambahkan website atau klik <strong>Sinkronkan Semua Website</strong> untuk merekam kode respons HTTP dan kecepatan koneksi server.
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

        <!-- TAB 4: JADWAL WA 08:00 & AGENT -->
        <section id="panel-schedule" role="tabpanel" aria-labelledby="tab-btn-schedule" <?= $activeTab !== 'schedule' ? 'hidden' : '' ?>>
            <div class="settings-grid">
                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">Otomasi Laporan Harian Jam 08:00 Pagi ke WhatsApp</h3>
                            <p class="panel-subtitle">Tanpa berlangganan layanan pihak ketiga: laporan disusun otomatis setiap pukul 08:00 pagi ke nomor <strong>081936955747</strong>.</p>
                        </div>
                    </div>

                    <form id="form-wa-settings" class="form-body">
                        <input type="hidden" name="action" value="save_settings">

                        <div class="form-row-2">
                            <div class="form-group">
                                <label class="form-label" for="wa_recipient">Nomor WhatsApp Tujuan</label>
                                <input type="text" id="wa_recipient" name="wa_recipient" class="form-control mono"
                                    value="<?= htmlspecialchars($settings['wa_recipient'] ?? '081936955747') ?>" required>
                                <span class="form-hint">Default: 081936955747 (Nomor Anda).</span>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="wa_schedule_time">Jam Pemicu Harian</label>
                                <input type="time" id="wa_schedule_time" name="wa_schedule_time" class="form-control mono"
                                    value="<?= htmlspecialchars($settings['wa_schedule_time'] ?? '08:00') ?>" required>
                                <span class="form-hint">Terpicu otomatis setiap hari pada jam ini.</span>
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

                        <div class="form-group">
                            <label class="form-label" for="wa_webhook_url">Opsional: Local/Self-Hosted WA Bridge URL (Kosongkan jika tidak pakai)</label>
                            <input type="url" id="wa_webhook_url" name="wa_webhook_url" class="form-control mono"
                                placeholder="https://localhost:3000/send-message"
                                value="<?= htmlspecialchars($settings['wa_webhook_url'] ?? '') ?>">
                            <span class="form-hint">Jika dikosongkan, sistem menggunakan Direct WhatsApp Dispatcher ke 081936955747 tanpa pihak ketiga.</span>
                        </div>

                        <div style="display:flex; flex-wrap:wrap; gap:0.65rem; padding-top:0.25rem;">
                            <button type="submit" class="btn btn-primary">Simpan Pengaturan Jadwal</button>
                            <button type="button" class="btn" id="btn-trigger-wa-now">
                                <span>Picu &amp; Kirim Laporan ke 081936955747 Sekarang</span>
                            </button>
                        </div>
                    </form>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">Pratinjau Laporan WA &amp; Panduan Pasang Agent</h3>
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
                            <span class="form-hint">
                                File <a href="api.php?download_agent=1" style="color:var(--accent-emerald); font-weight:700;">monweb-agent.php</a> yang Anda unduh dari tombol di atas sudah terisi Secret Key ini secara otomatis. Tinggal upload ke folder <code class="mono">public_html</code> website target Anda.
                            </span>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="cron-command-display">Baris Cronjob Niagahoster (08:00 Pagi Setiap Hari)</label>
                            <input type="text" id="cron-command-display" class="form-control mono" readonly
                                value="0 8 * * * /usr/local/bin/php /home/n1579664/public_html/monweb.baznassumbawa.id/cron.php --daily-report">
                            <span class="form-hint">
                                Anda juga dapat memasang URL Webhook GitHub ke: <code class="mono">https://monweb.baznassumbawa.id/cron.php?mode=github_webhook&amp;site_id=ID_WEBSITE</code> agar setiap <code class="mono">git push</code> otomatis melakukan <code class="mono">git pull</code>.
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- MODAL TAMBAH WEBSITE -->
    <div id="modal-add-site" class="modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="modal-add-title" hidden>
        <div class="modal-card">
            <div class="panel-header">
                <div>
                    <h3 id="modal-add-title" class="panel-title">Tambah Website ke MONWEB By AP</h3>
                    <p class="panel-subtitle">Pilih koneksi via Agent API (<code class="mono">monweb-agent.php</code>), SSH Niagahoster, atau Folder Lokal.</p>
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
                            <option value="PHP Native">PHP Native</option>
                            <option value="CodeIgniter 3/4">CodeIgniter 3 / 4</option>
                            <option value="JavaScript / Node">JavaScript / Static / Node</option>
                            <option value="Laravel">Laravel</option>
                            <option value="WordPress">WordPress</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="hosting_panel">Jenis Panel Hosting</label>
                        <select id="hosting_panel" name="hosting_panel" class="form-control">
                            <option value="hPanel Niagahoster">hPanel Niagahoster</option>
                            <option value="cPanel Unlimited">cPanel Unlimited Hosting</option>
                            <option value="VPS / Cloud Server">VPS / Cloud Server</option>
                        </select>
                    </div>
                </div>

                <div class="form-row-2">
                    <div class="form-group">
                        <label class="form-label" for="conn_mode">Metode Koneksi Git &amp; Disk</label>
                        <select id="conn_mode" name="conn_mode" class="form-control">
                            <option value="agent">Agent API (monweb-agent.php)</option>
                            <option value="ssh">SSH Langsung (Port 65002 / 22)</option>
                            <option value="local">Folder Server Lokal (Satu Hosting dengan MONWEB)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="git_branch">Branch Git Utama</label>
                        <input type="text" id="git_branch" name="git_branch" class="form-control mono"
                            placeholder="main" value="main">
                    </div>
                </div>

                <div id="conn-fields-agent" class="form-group">
                    <label class="form-label" for="agent_url">URL File monweb-agent.php (Opsional, otomatis mengikuti domain)</label>
                    <input type="text" id="agent_url" name="agent_url" class="form-control mono"
                        placeholder="https://baznassumbawa.id/monweb-agent.php">
                </div>

                <div id="conn-fields-ssh" hidden>
                    <div class="form-row-2" style="margin-bottom:0.85rem;">
                        <div class="form-group">
                            <label class="form-label" for="ssh_host">SSH Host / IP Server</label>
                            <input type="text" id="ssh_host" name="ssh_host" class="form-control mono"
                                placeholder="153.92.10.xx">
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
                            placeholder="u123456789">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="repo_path">Path Folder di Server (Opsional)</label>
                    <input type="text" id="repo_path" name="repo_path" class="form-control mono"
                        placeholder="/home/n1579664/public_html/nama-website">
                    <span class="form-hint">Jika menggunakan mode Agent API, kosongkan saja agar otomatis membaca folder tempat <code class="mono">monweb-agent.php</code> berada.</span>
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
            <strong>MONWEB By AP</strong> // Dikembangkan untuk <strong>ALBIANDRA PROJECT</strong> (`monweb.baznassumbawa.id`)
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

