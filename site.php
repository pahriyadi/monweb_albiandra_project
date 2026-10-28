<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/RemoteProbe.php';
require_once __DIR__ . '/app/LocalInspector.php';
require_once __DIR__ . '/app/ProbeView.php';
require_once __DIR__ . '/app/SiteOperations.php';

use MonWeb\Database;
use MonWeb\ProbeView;
use MonWeb\SiteOperations;

$tz = Database::getSetting('wa_timezone', 'Asia/Makassar');
@date_default_timezone_set($tz);

$ops      = new SiteOperations();
$allSites = $ops->getAllSites();
$settings = Database::getAllSettings();

$pinEnabled = ($settings['security_pin_enabled'] ?? '0') === '1';
$isLocked   = $pinEnabled && empty($_SESSION['monweb_unlocked']);
if ($isLocked) {
    header('Location: index.php');
    exit;
}

$siteId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($siteId <= 0 && !empty($allSites)) {
    $siteId = (int) $allSites[0]['id'];
}

$site = $siteId > 0 ? $ops->getSiteById($siteId) : null;
$inspection = $site ? $ops->inspectSiteSection($siteId, 'all') : null;
$data       = $inspection['data'] ?? [];
$findings   = $inspection['findings'] ?? [];
?>
<!DOCTYPE html>
<html lang="id" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $site ? htmlspecialchars($site['name']) . ' - ' : '' ?>Inspektur Mendalam - MONWEB By AP</title>
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
                <a href="index.php" class="brand-mark mono" style="text-decoration:none;" aria-label="Kembali ke Dashboard MONWEB By AP">AP</a>
                <div class="brand-titles">
                    <span class="brand-org">ALBIANDRA PROJECT</span>
                    <h1 class="brand-app">MONWEB By AP / Inspektur Akar</h1>
                </div>
            </div>

            <div class="header-actions">
                <a href="index.php" class="btn btn-sm">
                    <span>&larr; Kembali ke Dashboard Utama</span>
                </a>
                <?php if (!empty($allSites)): ?>
                    <form method="get" action="site.php" style="display:flex; gap:0.4rem; align-items:center;">
                        <label for="switch-site-id" class="sr-only">Pilih Website</label>
                        <select name="id" id="switch-site-id" class="form-control" style="min-width:210px; width:auto;" onchange="this.form.submit()">
                            <?php foreach ($allSites as $s): ?>
                                <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $siteId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($s['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>
                <button type="button" class="btn btn-sm" id="btn-theme-toggle" aria-label="Ganti tema gelap atau terang">
                    <span id="theme-label">Mode Terang</span>
                </button>
            </div>
        </div>
    </header>

    <main id="main-content" class="main-container">
        <?php if (!$site): ?>
            <section class="panel">
                <div class="empty-state">
                    <h2 class="empty-title">Website Tidak Ditemukan</h2>
                    <p class="empty-desc">Pilih atau tambahkan website terlebih dahulu melalui Dashboard Utama.</p>
                    <a href="index.php" class="btn btn-primary">Buka Dashboard Utama</a>
                </div>
            </section>
        <?php else: ?>
            <section class="ops-CommandStrip" aria-label="Identitas Website">
                <div>
                    <div class="site-title-line" style="margin-bottom:0.35rem;">
                        <span class="tag <?= $site['status'] === 'ONLINE' ? 'tag-online' : 'tag-down' ?> mono">
                            <?= htmlspecialchars($site['status']) ?> (HTTP <?= (int) ($data['http']['code'] ?? $site['http_code']) ?>)
                        </span>
                        <span class="tag mono"><?= htmlspecialchars($site['stack_type']) ?></span>
                        <span class="tag mono">Mode: <?= strtoupper(htmlspecialchars($site['conn_mode'])) ?></span>
                    </div>
                    <h2 class="ops-headline-title"><?= htmlspecialchars($site['name']) ?></h2>
                    <p class="ops-headline-sub mono">
                        <a href="<?= htmlspecialchars($site['url']) ?>" target="_blank" rel="noopener" class="site-url"><?= htmlspecialchars($site['url']) ?></a>
                        <?php if (!empty($site['repo_path'])): ?>
                            | Folder: <?= htmlspecialchars($site['repo_path']) ?>
                        <?php endif; ?>
                    </p>
                </div>

                <div class="ops-metrics-inline">
                    <div class="metric-pair">
                        <span class="metric-label">Waktu Muat</span>
                        <span class="metric-val mono tabular"><?= (int) ($data['http']['timing']['total'] ?? $site['latency_ms']) ?> ms</span>
                    </div>
                    <div class="metric-pair">
                        <span class="metric-label">Sisa SSL</span>
                        <span class="metric-val mono tabular"><?= (int) ($data['ssl']['days_left'] ?? $site['ssl_days_left']) ?> hr</span>
                    </div>
                    <div class="metric-pair">
                        <span class="metric-label">Sec Header</span>
                        <span class="metric-val mono tabular"><?= (int) ($data['http']['security']['passed'] ?? 0) ?>/<?= (int) ($data['http']['security']['total'] ?? 6) ?></span>
                    </div>
                    <div class="metric-pair">
                        <span class="metric-label">Temuan</span>
                        <span class="metric-val mono tabular"><?= count($findings) ?></span>
                    </div>
                </div>

                <div class="ops-strip-controls">
                    <a href="site.php?id=<?= (int) $site['id'] ?>" class="btn btn-primary">
                        <span>Pindai Ulang Semua Modul</span>
                    </a>
                </div>
            </section>

            <!-- RINGKASAN TEMUAN AKAR MASALAH -->
            <section class="panel" aria-label="Ringkasan Diagnosis dan Temuan">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">Ringkasan Diagnosis &amp; Temuan Otomatis</h3>
                        <p class="panel-subtitle">Hasil analisis otomatis dari HTTP, SSL, DNS, Keamanan Header, Git, Error Log, dan Link halaman utama.</p>
                    </div>
                </div>
                <div class="form-body">
                    <?php if (empty($findings)): ?>
                        <div class="status-banner is-success">
                            <span>Seluruh indikator utama (HTTP, SSL, DNS, Git, Error Log, dan Link) dalam kondisi sehat tanpa peringatan kritis.</span>
                        </div>
                    <?php else: ?>
                        <ul class="findings-list">
                            <?php foreach ($findings as [$lvl, $text]): ?>
                                <li class="finding-item <?= $lvl === 'err' ? 'is-err' : 'is-warn' ?>">
                                    <span class="tag <?= $lvl === 'err' ? 'tag-down' : 'tag-warn' ?> mono">
                                        <?= $lvl === 'err' ? 'KRITIS' : 'PERHATIAN' ?>
                                    </span>
                                    <span><?= htmlspecialchars($text) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </section>

            <!-- MODUL 1: HTTP & PERFORMA -->
            <section class="panel" id="mod-http">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">1. Pemeriksaan HTTP, Rincian Waktu Muat &amp; Rantai Redirect</h3>
                        <p class="panel-subtitle">Mengukur kecepatan DNS lookup, koneksi TCP, negosiasi TLS, waktu tunggu server (TTFB), dan kompresi respons.</p>
                    </div>
                    <button type="button" class="btn btn-sm js-refresh-section" data-id="<?= (int) $site['id'] ?>" data-section="http">
                        <span>Muat Ulang HTTP</span>
                    </button>
                </div>
                <div class="form-body" id="body-http">
                    <?= ProbeView::render('http', $data['http'] ?? []) ?>
                </div>
            </section>

            <!-- MODUL 2 & 3: SSL & DNS -->
            <div class="settings-grid">
                <section class="panel" id="mod-ssl">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">2. Inspektur Sertifikat SSL / TLS</h3>
                            <p class="panel-subtitle">Kecocokan nama domain (SAN), penerbit, sisa hari aktif, dan algoritma enkripsi.</p>
                        </div>
                        <button type="button" class="btn btn-sm js-refresh-section" data-id="<?= (int) $site['id'] ?>" data-section="ssl">
                            <span>Cek SSL</span>
                        </button>
                    </div>
                    <div class="form-body" id="body-ssl">
                        <?= ProbeView::render('ssl', $data['ssl'] ?? []) ?>
                    </div>
                </section>

                <section class="panel" id="mod-dns">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">3. Catatan DNS, Nameserver &amp; Email (SPF/DMARC)</h3>
                            <p class="panel-subtitle">Pengecekan record A, AAAA, CNAME, NS, MX, SPF, DMARC, dan Reverse PTR IP server.</p>
                        </div>
                        <button type="button" class="btn btn-sm js-refresh-section" data-id="<?= (int) $site['id'] ?>" data-section="dns">
                            <span>Cek DNS</span>
                        </button>
                    </div>
                    <div class="form-body" id="body-dns">
                        <?= ProbeView::render('dns', $data['dns'] ?? []) ?>
                    </div>
                </section>
            </div>

            <!-- MODUL 4: AUDIT HEADER KEAMANAN -->
            <section class="panel" id="mod-headers">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">4. Audit Header Keamanan HTTP</h3>
                        <p class="panel-subtitle">Pemeriksaan HSTS, Content-Security-Policy, X-Frame-Options, X-Content-Type-Options, dan kebocoran versi software.</p>
                    </div>
                    <button type="button" class="btn btn-sm js-refresh-section" data-id="<?= (int) $site['id'] ?>" data-section="headers">
                        <span>Cek Header</span>
                    </button>
                </div>
                <div class="form-body" id="body-headers">
                    <?= ProbeView::render('headers', $data['headers'] ?? []) ?>
                </div>
            </section>

            <!-- MODUL 5: STRUKTUR FILE & DISK -->
            <section class="panel" id="mod-disk">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">5. Peta Struktur Folder, Pemakaian Disk &amp; Perubahan File</h3>
                        <p class="panel-subtitle">Rincian direktori terbesar, 15 file terbesar, komposisi ekstensi, dan file yang baru dimodifikasi dalam 7 hari terakhir.</p>
                    </div>
                    <button type="button" class="btn btn-sm js-refresh-section" data-id="<?= (int) $site['id'] ?>" data-section="disk">
                        <span>Pindai Disk</span>
                    </button>
                </div>
                <div class="form-body" id="body-disk">
                    <?= ProbeView::render('disk', $data['disk'] ?? []) ?>
                </div>
            </section>

            <!-- MODUL 6: GIT MENDALAM -->
            <section class="panel" id="mod-git">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">6. Inspektur Repositori Git Mendalam</h3>
                        <p class="panel-subtitle">Remote origin URL, daftar semua branch, posisi ahead/behind, file berubah (dirty), dan 15 riwayat commit terakhir.</p>
                    </div>
                    <button type="button" class="btn btn-sm js-refresh-section" data-id="<?= (int) $site['id'] ?>" data-section="git">
                        <span>Segarkan Git</span>
                    </button>
                </div>
                <div class="form-body" id="body-git">
                    <?= ProbeView::render('git', $data['git'] ?? []) ?>
                </div>
            </section>

            <!-- MODUL 7: FRAMEWORK & KONFIGURASI -->
            <section class="panel" id="mod-framework">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">7. Deteksi Framework, Runtime PHP &amp; Paket Composer</h3>
                        <p class="panel-subtitle">Versi CodeIgniter/Laravel/WordPress/Native, mode environment, batas memori PHP, ekstensi aktif, dan pratinjau .htaccess.</p>
                    </div>
                    <button type="button" class="btn btn-sm js-refresh-section" data-id="<?= (int) $site['id'] ?>" data-section="framework">
                        <span>Cek Konfigurasi</span>
                    </button>
                </div>
                <div class="form-body" id="body-framework">
                    <?= ProbeView::render('framework', $data['framework'] ?? []) ?>
                </div>
            </section>

            <!-- MODUL 8 & 9: ANALISIS ERROR LOG & LINK RUSAK -->
            <div class="settings-grid">
                <section class="panel" id="mod-errors">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">8. Pengelompokan Frekuensi Error &amp; Bug</h3>
                            <p class="panel-subtitle">Mengelompokkan pesan error berulang berdasarkan frekuensi tertinggi beserta lokasi file dan nomor baris.</p>
                        </div>
                        <button type="button" class="btn btn-sm js-refresh-section" data-id="<?= (int) $site['id'] ?>" data-section="errors">
                            <span>Analisis Log</span>
                        </button>
                    </div>
                    <div class="form-body" id="body-errors">
                        <?= ProbeView::render('errors', $data['errors'] ?? []) ?>
                    </div>
                </section>

                <section class="panel" id="mod-links">
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">9. Pemeriksa Link Internal Halaman Utama</h3>
                            <p class="panel-subtitle">Memastikan tautan internal di halaman depan tidak mengarah ke halaman 404 atau 500.</p>
                        </div>
                        <button type="button" class="btn btn-sm js-refresh-section" data-id="<?= (int) $site['id'] ?>" data-section="links">
                            <span>Cek Tautan</span>
                        </button>
                    </div>
                    <div class="form-body" id="body-links">
                        <?= ProbeView::render('links', $data['links'] ?? []) ?>
                    </div>
                </section>
            </div>
        <?php endif; ?>
    </main>

    <footer class="app-footer">
        <div>
            <strong>MONWEB By AP</strong> / ALBIANDRA PROJECT - Inspektur Mendalam Domain &amp; Subdomain
        </div>
        <div class="mono">
            Laporan Harian WA: <?= htmlspecialchars($settings['wa_recipient'] ?? '081936955747') ?> @ <?= htmlspecialchars($settings['wa_schedule_time'] ?? '08:00') ?>
        </div>
    </footer>

    <script>
    (function () {
        const root = document.documentElement;
        const themeBtn = document.getElementById('btn-theme-toggle');
        const themeLabel = document.getElementById('theme-label');
        const savedTheme = localStorage.getItem('monweb_theme') || 'dark';
        root.setAttribute('data-theme', savedTheme);
        if (themeLabel) {
            themeLabel.textContent = savedTheme === 'dark' ? 'Mode Terang' : 'Mode Gelap';
        }
        if (themeBtn) {
            themeBtn.addEventListener('click', function () {
                const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
                root.setAttribute('data-theme', next);
                localStorage.setItem('monweb_theme', next);
                if (themeLabel) {
                    themeLabel.textContent = next === 'dark' ? 'Mode Terang' : 'Mode Gelap';
                }
            });
        }

        document.querySelectorAll('.js-refresh-section').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                const id = btn.getAttribute('data-id');
                const section = btn.getAttribute('data-section');
                const target = document.getElementById('body-' + section);
                const origText = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span>Memuat...</span>';
                try {
                    const res = await fetch('api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'inspect_section', id: Number(id), section: section })
                    });
                    const json = await res.json();
                    if (json.ok && target) {
                        target.innerHTML = json.html;
                    }
                } catch (e) {
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = origText;
                }
            });
        });
    })();
    </script>
</body>
</html>
