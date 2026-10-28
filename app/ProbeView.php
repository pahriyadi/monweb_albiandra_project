<?php
declare(strict_types=1);

namespace MonWeb;

/**
 * Merender hasil RemoteProbe dan LocalInspector menjadi potongan HTML untuk halaman detail website.
 */
class ProbeView
{
    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    private static function bytes(int $b): string
    {
        if ($b >= 1048576) {
            return number_format($b / 1048576, 2) . ' MB';
        }
        if ($b >= 1024) {
            return number_format($b / 1024, 1) . ' KB';
        }
        return $b . ' B';
    }

    private static function list(array $items, string $empty = '-'): string
    {
        if (empty($items)) {
            return '<span class="muted">' . self::e($empty) . '</span>';
        }
        $out = '<ul class="kv-list mono">';
        foreach ($items as $i) {
            $out .= '<li>' . self::e($i) . '</li>';
        }
        return $out . '</ul>';
    }

    public static function render(string $section, array $d): string
    {
        return match ($section) {
            'http'      => self::http($d),
            'ssl'       => self::ssl($d),
            'dns'       => self::dns($d),
            'headers'   => self::headers($d),
            'disk'      => self::disk($d),
            'git'       => self::git($d),
            'framework' => self::framework($d),
            'errors'    => self::errors($d),
            'links'     => self::links($d),
            default     => '',
        };
    }

    /** Ringkasan temuan yang perlu ditindak, diturunkan dari hasil nyata pemeriksaan. */
    public static function findings(
        array $http,
        array $ssl,
        array $dns,
        array $disk = [],
        array $git = [],
        array $errors = [],
        array $links = []
    ): array {
        $f = [];
        if (($http['code'] ?? 0) >= 500 || ($http['code'] ?? 0) === 0) {
            $f[] = ['err', 'Website membalas HTTP ' . (int) ($http['code'] ?? 0) . '. Periksa error_log server.'];
        } elseif (($http['code'] ?? 0) >= 400) {
            $f[] = ['warn', 'Halaman akhir membalas HTTP ' . (int) $http['code'] . '.'];
        }
        if (($http['timing']['ttfb'] ?? 0) > 1500) {
            $f[] = ['warn', 'Waktu tunggu server (TTFB) ' . (int) $http['timing']['ttfb'] . ' ms. Di atas 1,5 detik terasa lambat.'];
        }
        if (count($http['redirects'] ?? []) > 2) {
            $f[] = ['warn', 'Ada ' . (count($http['redirects']) - 1) . ' kali redirect sebelum halaman tampil.'];
        }
        if (!empty($http['final_url']) && str_starts_with(strtolower($http['final_url']), 'http://')) {
            $f[] = ['err', 'Halaman akhir masih memakai HTTP tanpa enkripsi.'];
        }
        if (empty($http['compressed']) && ($http['size_bytes'] ?? 0) > 20000) {
            $f[] = ['warn', 'Kompresi gzip/brotli belum aktif untuk halaman ' . self::bytes((int) $http['size_bytes']) . '.'];
        }
        if (!empty($http['security']['leaks'])) {
            $f[] = ['warn', 'Header membocorkan versi software: ' . implode(', ', $http['security']['leaks']) . '.'];
        }
        if (isset($http['security']['passed']) && $http['security']['passed'] < 3) {
            $f[] = ['warn', 'Header keamanan baru ' . $http['security']['passed'] . ' dari ' . $http['security']['total'] . ' yang terpasang.'];
        }
        if (empty($ssl['available'])) {
            $f[] = ['err', 'SSL tidak tersedia: ' . ($ssl['note'] ?? '-')];
        } else {
            if (empty($ssl['host_match'])) {
                $f[] = ['err', 'Sertifikat SSL tidak mencakup nama domain ini (penyebab ERR_CERT_COMMON_NAME_INVALID).'];
            }
            if (($ssl['days_left'] ?? 99) < 14) {
                $f[] = ['err', 'Sertifikat SSL habis dalam ' . (int) $ssl['days_left'] . ' hari.'];
            }
        }
        if (empty($dns['a'])) {
            $f[] = ['err', 'Domain tidak memiliki record A.'];
        }
        if (!empty($dns['mx']) && $dns['spf'] === '') {
            $f[] = ['warn', 'Domain punya MX tapi belum ada record SPF. Email bisa masuk spam.'];
        }
        if (!empty($dns['mx']) && $dns['dmarc'] === '') {
            $f[] = ['warn', 'Record DMARC belum dipasang.'];
        }
        if (!empty($git['dirty_files']) && count($git['dirty_files']) > 0) {
            $f[] = ['warn', 'Terdapat ' . count($git['dirty_files']) . ' file yang berubah atau belum di-commit di folder proyek.'];
        }
        if (!empty($errors['unique_bugs']) && (int) $errors['unique_bugs'] > 0) {
            $f[] = ['err', 'Ditemukan ' . (int) $errors['unique_bugs'] . ' jenis error unik (' . (int) ($errors['total_lines'] ?? 0) . ' baris log).'];
        }
        if (!empty($links['broken_count']) && (int) $links['broken_count'] > 0) {
            $f[] = ['err', 'Ditemukan ' . (int) $links['broken_count'] . ' link rusak (HTTP 4xx/5xx) pada halaman utama.'];
        }
        return $f;
    }

    private static function http(array $d): string
    {
        $t = $d['timing'];
        $max = max(1, $t['total']);
        $segments = [
            ['DNS', $t['dns']],
            ['Koneksi', max(0, $t['connect'] - $t['dns'])],
            ['TLS', max(0, $t['tls'] - $t['connect'])],
            ['Server (TTFB)', max(0, $t['ttfb'] - max($t['tls'], $t['connect']))],
            ['Unduh', max(0, $t['total'] - $t['ttfb'])],
        ];

        $codeClass = $d['code'] >= 200 && $d['code'] < 400 ? 'tag-online' : ($d['code'] >= 500 || $d['code'] === 0 ? 'tag-down' : 'tag-warn');

        $html = '<div class="probe-grid">';
        $html .= '<dl class="kv">';
        $html .= '<dt>Status akhir</dt><dd><span class="tag ' . $codeClass . ' mono">HTTP ' . (int) $d['code'] . '</span></dd>';
        $html .= '<dt>URL akhir</dt><dd class="mono break">' . self::e($d['final_url']) . '</dd>';
        $html .= '<dt>Judul halaman</dt><dd>' . ($d['title'] !== '' ? self::e($d['title']) : '<span class="muted">(tanpa &lt;title&gt;)</span>') . '</dd>';
        $html .= '<dt>Protokol</dt><dd class="mono">' . self::e($d['http_version']) . '</dd>';
        $html .= '<dt>Server</dt><dd class="mono">' . self::e($d['server']) . '</dd>';
        $html .= '<dt>Ukuran HTML</dt><dd class="mono">' . self::bytes((int) $d['size_bytes']) . ' (kompresi: ' . self::e($d['encoding']) . ')</dd>';
        $html .= '<dt>Content-Type</dt><dd class="mono">' . self::e($d['content_type']) . '</dd>';
        if ($d['generator'] !== '') {
            $html .= '<dt>Generator</dt><dd class="mono">' . self::e($d['generator']) . '</dd>';
        }
        $html .= '</dl>';

        $html .= '<div><h4 class="sub-title">Rincian waktu muat: total ' . (int) $t['total'] . ' ms</h4>';
        $html .= '<div class="timing-bars">';
        foreach ($segments as [$label, $ms]) {
            $w = $ms > 0 ? max(2, (int) round($ms / $max * 100)) : 0;
            $html .= '<div class="timing-row"><span class="timing-label">' . self::e($label) . '</span>'
                . '<span class="timing-track"><span class="timing-fill" style="width:' . $w . '%"></span></span>'
                . '<span class="timing-ms mono">' . (int) $ms . ' ms</span></div>';
        }
        $html .= '</div>';

        $html .= '<h4 class="sub-title">Rantai redirect</h4><ol class="redirect-chain mono">';
        foreach ($d['redirects'] as $r) {
            $html .= '<li><span class="tag mono">' . (int) $r['code'] . '</span> <span class="break">' . self::e($r['url']) . '</span></li>';
        }
        $html .= '</ol></div></div>';

        $html .= '<details class="raw-headers"><summary>Lihat semua header respons (' . count($d['headers']) . ')</summary><table class="ops-table"><tbody>';
        foreach ($d['headers'] as $k => $v) {
            $html .= '<tr><td class="mono">' . self::e($k) . '</td><td class="mono break">' . self::e($v) . '</td></tr>';
        }
        $html .= '</tbody></table></details>';

        return $html;
    }

    private static function headers(array $d): string
    {
        $s = $d['security'];
        $html = '<p class="panel-subtitle" style="margin-bottom:0.75rem;">Terpasang <strong>' . (int) $s['passed'] . ' dari ' . (int) $s['total'] . '</strong> header keamanan yang direkomendasikan.</p>';
        $html .= '<div class="table-scroll"><table class="ops-table"><thead><tr><th>Header</th><th>Status</th><th>Fungsi</th><th>Nilai</th></tr></thead><tbody>';
        foreach ($s['items'] as $i) {
            $html .= '<tr><td class="mono">' . self::e($i['label']) . '</td>'
                . '<td><span class="tag ' . ($i['present'] ? 'tag-online' : 'tag-down') . '">' . ($i['present'] ? 'Terpasang' : 'Belum ada') . '</span></td>'
                . '<td>' . self::e($i['why']) . '</td>'
                . '<td class="mono break">' . ($i['value'] !== '' ? self::e($i['value']) : '-') . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        if (!empty($s['leaks'])) {
            $html .= '<h4 class="sub-title">Informasi versi yang terlihat publik</h4>' . self::list($s['leaks']);
            $html .= '<p class="form-hint">Sembunyikan dengan menambahkan <code class="mono">Header unset X-Powered-By</code> di .htaccess, atau matikan <code class="mono">expose_php</code> di Select PHP Version.</p>';
        }
        return $html;
    }

    private static function ssl(array $d): string
    {
        if (empty($d['available'])) {
            return '<div class="status-banner is-error">' . self::e($d['note'] ?? 'SSL tidak tersedia.') . '</div>';
        }
        $daysClass = $d['days_left'] < 14 ? 'tag-down' : ($d['days_left'] < 30 ? 'tag-warn' : 'tag-online');
        $html = '<dl class="kv">';
        $html .= '<dt>Cocok dengan domain</dt><dd><span class="tag ' . ($d['host_match'] ? 'tag-online' : 'tag-down') . '">' . ($d['host_match'] ? 'Ya' : 'Tidak cocok') . '</span></dd>';
        $html .= '<dt>Sisa masa berlaku</dt><dd><span class="tag ' . $daysClass . ' mono">' . (int) $d['days_left'] . ' hari</span> <span class="mono">(' . self::e($d['valid_from']) . ' s/d ' . self::e($d['valid_to']) . ')</span></dd>';
        $html .= '<dt>Diterbitkan untuk</dt><dd class="mono">' . self::e($d['subject_cn']) . '</dd>';
        $html .= '<dt>Penerbit</dt><dd>' . self::e($d['issuer']) . '</dd>';
        $html .= '<dt>Protokol / Cipher</dt><dd class="mono">' . self::e($d['protocol']) . ' / ' . self::e($d['cipher']) . '</dd>';
        $html .= '<dt>Algoritma tanda tangan</dt><dd class="mono">' . self::e($d['signature']) . '</dd>';
        $html .= '<dt>Domain dalam sertifikat (' . count($d['sans']) . ')</dt><dd>' . self::list($d['sans']) . '</dd>';
        $html .= '</dl>';
        if (!$d['host_match']) {
            $html .= '<p class="form-hint">Jalankan <strong>SSL/TLS Status &gt; Run AutoSSL</strong> di cPanel untuk menerbitkan sertifikat yang mencakup domain ini.</p>';
        }
        return $html;
    }

    private static function dns(array $d): string
    {
        $html = '<dl class="kv">';
        $html .= '<dt>Host diperiksa</dt><dd class="mono">' . self::e($d['host']) . ' <span class="muted">(domain induk: ' . self::e($d['root']) . ')</span></dd>';
        $html .= '<dt>A (IPv4)</dt><dd>' . self::list($d['a'], 'Tidak ada') . '</dd>';
        $html .= '<dt>AAAA (IPv6)</dt><dd>' . self::list($d['aaaa'], 'Tidak ada') . '</dd>';
        if (!empty($d['cname'])) {
            $html .= '<dt>CNAME</dt><dd>' . self::list($d['cname']) . '</dd>';
        }
        $html .= '<dt>Reverse DNS</dt><dd>' . self::list($d['reverse'], '-') . '</dd>';
        $html .= '<dt>Nameserver</dt><dd>' . self::list($d['ns'], 'Tidak terbaca') . '</dd>';
        $html .= '<dt>MX (email)</dt><dd>' . self::list($d['mx'], 'Tidak ada') . '</dd>';
        $html .= '<dt>SPF</dt><dd>' . ($d['spf'] !== '' ? '<span class="mono break">' . self::e($d['spf']) . '</span>' : '<span class="tag tag-warn">Belum ada</span>') . '</dd>';
        $html .= '<dt>DMARC</dt><dd>' . ($d['dmarc'] !== '' ? '<span class="mono break">' . self::e($d['dmarc']) . '</span>' : '<span class="tag tag-warn">Belum ada</span>') . '</dd>';
        $html .= '<dt>TXT lain</dt><dd>' . self::list(array_values(array_filter($d['txt'], fn($t) => !str_starts_with(strtolower($t), 'v=spf1'))), 'Tidak ada') . '</dd>';
        $html .= '</dl>';
        return $html;
    }

    private static function disk(array $d): string
    {
        if (empty($d['available'])) {
            return '<div class="status-banner">' . self::e($d['note'] ?? 'Data struktur folder tidak tersedia.') . '</div>';
        }

        $html = '<dl class="kv" style="margin-bottom:1rem;">';
        $html .= '<dt>Lokasi Folder</dt><dd class="mono break">' . self::e($d['root_path'] ?? '-') . '</dd>';
        $html .= '<dt>Total Ukuran Proyek</dt><dd class="mono"><strong>' . self::bytes((int) ($d['total_bytes'] ?? 0)) . '</strong></dd>';
        $html .= '<dt>Jumlah File &amp; Folder</dt><dd class="mono">' . number_format((int) ($d['total_files'] ?? 0)) . ' file dalam ' . number_format((int) ($d['total_dirs'] ?? 0)) . ' direktori</dd>';
        $html .= '</dl>';

        $html .= '<div class="probe-grid">';

        $html .= '<div><h4 class="sub-title">Folder Terbesar</h4>';
        $html .= '<div class="table-scroll"><table class="ops-table"><thead><tr><th>Folder</th><th>File</th><th>Ukuran</th></tr></thead><tbody>';
        foreach ($d['top_folders'] ?? [] as $f) {
            $html .= '<tr><td class="mono">' . self::e($f['folder']) . '</td>'
                . '<td class="mono tabular">' . number_format((int) $f['files']) . '</td>'
                . '<td class="mono tabular">' . self::bytes((int) $f['bytes']) . '</td></tr>';
        }
        $html .= '</tbody></table></div></div>';

        $html .= '<div><h4 class="sub-title">Komposisi Ekstensi File</h4>';
        $html .= '<div class="table-scroll"><table class="ops-table"><thead><tr><th>Ekstensi</th><th>Jumlah</th><th>Total Ukuran</th></tr></thead><tbody>';
        foreach ($d['extensions'] ?? [] as $ext) {
            $html .= '<tr><td class="mono">' . self::e($ext['ext']) . '</td>'
                . '<td class="mono tabular">' . number_format((int) $ext['count']) . '</td>'
                . '<td class="mono tabular">' . self::bytes((int) $ext['bytes']) . '</td></tr>';
        }
        $html .= '</tbody></table></div></div>';

        $html .= '</div>';

        $html .= '<div class="probe-grid" style="margin-top:1rem;">';

        $html .= '<div><h4 class="sub-title">15 File Terbesar</h4>';
        $html .= '<div class="table-scroll"><table class="ops-table"><thead><tr><th>Path File</th><th>Ukuran</th><th>Diubah</th></tr></thead><tbody>';
        foreach ($d['largest_files'] ?? [] as $lf) {
            $html .= '<tr><td class="mono break">' . self::e($lf['path']) . '</td>'
                . '<td class="mono tabular">' . self::bytes((int) $lf['bytes']) . '</td>'
                . '<td class="mono">' . self::e($lf['mtime']) . '</td></tr>';
        }
        $html .= '</tbody></table></div></div>';

        $html .= '<div><h4 class="sub-title">File Baru Diubah (7 Hari Terakhir)</h4>';
        if (empty($d['recent_files'])) {
            $html .= '<p class="muted">Tidak ada file yang diubah dalam 7 hari terakhir.</p>';
        } else {
            $html .= '<div class="table-scroll"><table class="ops-table"><thead><tr><th>Path File</th><th>Ukuran</th><th>Waktu Ubah</th></tr></thead><tbody>';
            foreach ($d['recent_files'] as $rf) {
                $html .= '<tr><td class="mono break">' . self::e($rf['path']) . '</td>'
                    . '<td class="mono tabular">' . self::bytes((int) $rf['bytes']) . '</td>'
                    . '<td class="mono">' . self::e($rf['date']) . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= '</div></div>';

        return $html;
    }

    private static function git(array $d): string
    {
        if (empty($d['available'])) {
            return '<div class="status-banner">' . self::e($d['note'] ?? 'Repositori Git belum diinisialisasi.') . '</div>';
        }

        $html = '<div class="probe-grid">';
        $html .= '<dl class="kv">';
        $html .= '<dt>Branch Aktif</dt><dd><span class="tag tag-online mono">' . self::e($d['branch']) . '</span></dd>';
        $html .= '<dt>Remote Origin</dt><dd class="mono break">' . self::e($d['remote_url']) . '</dd>';
        $html .= '<dt>Total Commit</dt><dd class="mono tabular">' . number_format((int) $d['total_commits']) . ' commit</dd>';
        $html .= '<dt>Tag Terakhir</dt><dd class="mono">' . self::e($d['latest_tag']) . '</dd>';
        $html .= '<dt>Posisi terhadap Origin</dt><dd class="mono">Ahead ' . (int) $d['ahead'] . ' / Behind ' . (int) $d['behind'] . '</dd>';
        $html .= '<dt>Ukuran .git/objects</dt><dd class="mono">' . self::bytes((int) $d['pack_bytes']) . '</dd>';
        $html .= '<dt>Daftar Branch</dt><dd>' . self::list($d['branches'] ?? [], '-') . '</dd>';
        $html .= '</dl>';

        $html .= '<div><h4 class="sub-title">File Berubah / Belum Di-commit (' . count($d['dirty_files'] ?? []) . ')</h4>';
        if (empty($d['dirty_files'])) {
            $html .= '<p class="muted">Working tree bersih. Tidak ada perubahan lokal yang menggantung.</p>';
        } else {
            $html .= self::list($d['dirty_files']);
        }
        $html .= '</div></div>';

        $html .= '<h4 class="sub-title" style="margin-top:1rem;">15 Riwayat Commit Terakhir</h4>';
        $html .= '<div class="table-scroll"><table class="ops-table"><thead><tr><th>Hash</th><th>Penulis</th><th>Waktu</th><th>Pesan Commit</th></tr></thead><tbody>';
        foreach ($d['commits'] ?? [] as $c) {
            $html .= '<tr><td class="mono"><span class="git-commit-hash">' . self::e($c['hash']) . '</span></td>'
                . '<td>' . self::e($c['author']) . '</td>'
                . '<td class="mono">' . self::e($c['rel']) . '</td>'
                . '<td>' . self::e($c['subject']) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';

        return $html;
    }

    private static function framework(array $d): string
    {
        if (empty($d['available'])) {
            return '<div class="status-banner">' . self::e($d['note'] ?? 'Data framework tidak tersedia.') . '</div>';
        }

        $html = '<div class="probe-grid">';
        $html .= '<dl class="kv">';
        $html .= '<dt>Framework Terdeteksi</dt><dd><span class="tag tag-online">' . self::e($d['framework']) . '</span></dd>';
        $html .= '<dt>Versi Framework</dt><dd class="mono">' . self::e($d['framework_version']) . '</dd>';
        $html .= '<dt>Mode Environment</dt><dd class="mono">' . self::e($d['environment']) . '</dd>';
        $html .= '<dt>Konfigurasi Base URL</dt><dd class="mono break">' . self::e($d['base_url']) . '</dd>';
        $html .= '<dt>Driver Database</dt><dd class="mono">' . self::e($d['db_driver']) . '</dd>';
        $html .= '<dt>Versi PHP Server</dt><dd class="mono">' . self::e($d['php_version']) . ' (' . self::e($d['php_sapi']) . ')</dd>';
        $html .= '<dt>Batas Memori &amp; Upload</dt><dd class="mono">Memory: ' . self::e($d['memory_limit']) . ' | Upload: ' . self::e($d['upload_max']) . ' | Post: ' . self::e($d['post_max']) . '</dd>';
        $html .= '<dt>Batas Eksekusi</dt><dd class="mono">' . self::e($d['max_exec_time']) . '</dd>';
        $html .= '</dl>';

        $html .= '<div>';
        $html .= '<h4 class="sub-title">Status Ekstensi PHP Penting</h4><div class="site-meta-tags" style="margin-bottom:1rem;">';
        foreach ($d['extensions'] ?? [] as $ext => $loaded) {
            $html .= '<span class="tag ' . ($loaded ? 'tag-online' : 'tag-down') . ' mono">' . self::e($ext) . ': ' . ($loaded ? 'ON' : 'OFF') . '</span>';
        }
        $html .= '</div>';

        $html .= '<h4 class="sub-title">Paket Composer (' . count($d['composer_packages'] ?? []) . ')</h4>';
        $html .= self::list($d['composer_packages'] ?? [], 'Tidak menggunakan composer.json');
        $html .= '</div></div>';

        if (!empty($d['htaccess_preview'])) {
            $html .= '<h4 class="sub-title" style="margin-top:1rem;">Isi File .htaccess</h4>';
            $html .= '<pre class="console-output mono" style="max-height:220px; border:1px solid var(--border-subtle); border-radius:6px;">' . self::e(implode("\n", $d['htaccess_preview'])) . '</pre>';
        }

        return $html;
    }

    private static function errors(array $d): string
    {
        if (empty($d['available'])) {
            return '<div class="status-banner">' . self::e($d['note'] ?? 'Analisis error log belum tersedia.') . '</div>';
        }

        if (empty($d['items'])) {
            return '<div class="empty-state" style="padding:1.25rem;"><h4 class="empty-title">Log Bersih (0 Error)</h4><p class="empty-desc">Tidak ditemukan catatan error pada file log aplikasi maupun PHP error_log.</p></div>';
        }

        $html = '<p class="panel-subtitle" style="margin-bottom:0.75rem;">Ditemukan <strong>' . (int) $d['unique_bugs'] . ' jenis error unik</strong> dari total ' . (int) $d['total_lines'] . ' baris log pada file: <span class="mono">' . self::e(implode(', ', $d['log_files'] ?? [])) . '</span>.</p>';
        $html .= '<div class="table-scroll"><table class="ops-table"><thead><tr><th>Frekuensi</th><th>Level</th><th>Lokasi File:Baris</th><th>Sumber</th><th>Pesan Error</th></tr></thead><tbody>';
        foreach ($d['items'] as $item) {
            $cls = $item['severity'] === 'ERROR' ? 'tag-down' : ($item['severity'] === 'WARNING' ? 'tag-warn' : '');
            $html .= '<tr>'
                . '<td class="mono tabular"><strong>' . (int) $item['count'] . 'x</strong></td>'
                . '<td><span class="tag ' . $cls . ' mono">' . self::e($item['severity']) . '</span></td>'
                . '<td class="mono">' . self::e($item['location']) . '</td>'
                . '<td class="mono">' . self::e($item['source']) . '</td>'
                . '<td class="mono break">' . self::e($item['message']) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table></div>';
        return $html;
    }

    private static function links(array $d): string
    {
        if (empty($d['available'])) {
            return '<div class="status-banner">' . self::e($d['note'] ?? 'Pemeriksaan tautan belum tersedia.') . '</div>';
        }

        if (empty($d['items'])) {
            return '<p class="muted">Tidak ditemukan tautan internal pada halaman utama.</p>';
        }

        $html = '<p class="panel-subtitle" style="margin-bottom:0.75rem;">Memeriksa <strong>' . (int) $d['checked'] . ' tautan internal</strong> di halaman utama. Rusak: <strong>' . (int) $d['broken_count'] . '</strong>.</p>';
        $html .= '<div class="table-scroll"><table class="ops-table"><thead><tr><th>Status</th><th>Waktu</th><th>Teks Link</th><th>URL Tujuan</th></tr></thead><tbody>';
        foreach ($d['items'] as $l) {
            $cls = $l['broken'] ? 'tag-down' : 'tag-online';
            $html .= '<tr>'
                . '<td><span class="tag ' . $cls . ' mono">HTTP ' . (int) $l['code'] . '</span></td>'
                . '<td class="mono tabular">' . (int) $l['ms'] . ' ms</td>'
                . '<td>' . self::e($l['label']) . '</td>'
                . '<td class="mono break">' . self::e($l['url']) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table></div>';
        return $html;
    }
}
