# MONWEB By AP (ALBIANDRA PROJECT)

Konsol kendali operasional website, Git Deployment (`git pull` & `git status`), pemantauan kapasitas disk/inodes, pembaca `error_log` PHP/CodeIgniter, serta inspektur mendalam domain & subdomain (`site.php`) dengan penjadwal laporan harian WhatsApp otomatis pukul 08:00 pagi.

- **Subdomain Produksi:** `https://monweb.baznassumbawa.id`
- **Path Server cPanel:** `/home/n1579664/public_html/monweb.baznassumbawa.id/`
- **Repositori GitHub:** `https://github.com/pahriyadi/monweb_albiandra_project.git`

## Fitur Utama (v3.0)
1. **Auto-Scan Folder Hosting & Mass Git Pull:** Deteksi seluruh website/subdomain di dalam `public_html` dengan 1 klik dan jalankan `git pull` serentak di semua website.
2. **Inspektur Mendalam Domain & Subdomain (`site.php?id=X`):**
   - **HTTP & Performa:** Rincian waktu muat (DNS, TCP, TLS, TTFB, Unduh), rantai redirect, dan ukuran/kompresi HTML.
   - **SSL & DNS Lengkap:** Kecocokan nama domain dengan daftar SAN sertifikat, sisa masa berlaku SSL, serta pengecekan record `A`, `AAAA`, `CNAME`, `NS`, `MX`, `SPF`, `DMARC`, dan `Reverse PTR`.
   - **Audit Header Keamanan:** Pemeriksaan `HSTS`, `CSP`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, dan deteksi kebocoran versi software.
   - **Peta Struktur Folder & Disk:** Daftar folder terbesar, 15 file terbesar, komposisi berdasarkan ekstensi file, dan daftar file yang baru diubah dalam 7 hari terakhir.
   - **Inspektur Git Mendalam:** URL remote origin, posisi `ahead`/`behind`, ukuran `.git/objects`, semua branch, file berubah (`dirty`), dan 15 commit terakhir.
   - **Deteksi Framework & Runtime PHP:** Auto-deteksi CodeIgniter 3/4, Laravel, WordPress, atau PHP Native beserta versi, mode `ENVIRONMENT`, `base_url`, ekstensi PHP, paket `composer.json`, dan isi `.htaccess`.
   - **Pengelompokan Error Log & Cek Link Rusak:** Mengelompokkan bug berulang berdasarkan frekuensi tertinggi lengkap dengan lokasi `file:baris`, serta memeriksa tautan internal di halaman utama.
3. **Terminal & Otomasi Tanpa cPanel:** Eksekusi perintah terminal kustom, Git Force Reset, pembersih cache/session CodeIgniter & Laravel, serta pembuatan Backup ZIP proyek.
4. **Jadwal Laporan WA 08:00 & PIN Keamanan:** Kirim laporan status seluruh website setiap jam 08:00 pagi ke nomor WhatsApp `081936955747`, dilengkapi proteksi PIN konsol dan generator `monweb-agent.php`.
