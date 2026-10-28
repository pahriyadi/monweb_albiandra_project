<?php
declare(strict_types=1);

namespace MonWeb;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Pemeriksaan mendalam pada folder proyek lokal dan tautan halaman utama.
 */
class LocalInspector
{
    public static function diskTree(string $path): array
    {
        if ($path === '' || !is_dir($path)) {
            return [
                'available' => false,
                'note'      => 'Folder proyek lokal belum diatur atau tidak ditemukan.',
            ];
        }

        $real = realpath($path) ?: $path;
        $totalBytes = 0;
        $totalFiles = 0;
        $totalDirs  = 0;
        $topFolders = [];
        $largestFiles = [];
        $extBreakdown = [];
        $recentFiles  = [];
        $sevenDaysAgo = time() - (7 * 86400);
        $maxScan = 18000;

        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($it as $item) {
                $full = $item->getPathname();
                $rel  = ltrim(str_replace('\\', '/', substr($full, strlen($real))), '/');
                if ($rel === '') {
                    continue;
                }

                $parts = explode('/', $rel);
                $top   = count($parts) > 1 ? $parts[0] . '/' : '(root)';

                if ($item->isDir()) {
                    $totalDirs++;
                    continue;
                }

                if (!$item->isFile()) {
                    continue;
                }

                $totalFiles++;
                $size  = (int) @$item->getSize();
                $mtime = (int) @$item->getMTime();
                $totalBytes += $size;

                if (!isset($topFolders[$top])) {
                    $topFolders[$top] = ['folder' => $top, 'bytes' => 0, 'files' => 0];
                }
                $topFolders[$top]['bytes'] += $size;
                $topFolders[$top]['files']++;

                $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                if ($ext === '') {
                    $ext = '(tanpa ekstensi)';
                } else {
                    $ext = '.' . $ext;
                }
                if (!isset($extBreakdown[$ext])) {
                    $extBreakdown[$ext] = ['ext' => $ext, 'count' => 0, 'bytes' => 0];
                }
                $extBreakdown[$ext]['count']++;
                $extBreakdown[$ext]['bytes'] += $size;

                if (!str_starts_with($rel, '.git/')) {
                    $largestFiles[] = [
                        'path'  => $rel,
                        'bytes' => $size,
                        'mtime' => $mtime > 0 ? date('Y-m-d H:i', $mtime) : '-',
                    ];

                    if ($mtime >= $sevenDaysAgo) {
                        $recentFiles[] = [
                            'path'  => $rel,
                            'bytes' => $size,
                            'mtime' => $mtime,
                            'date'  => date('Y-m-d H:i', $mtime),
                        ];
                    }
                }

                if ($totalFiles >= $maxScan) {
                    break;
                }
            }
        } catch (Throwable $e) {
        }

        usort($topFolders, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
        usort($largestFiles, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
        usort($extBreakdown, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
        usort($recentFiles, fn($a, $b) => $b['mtime'] <=> $a['mtime']);

        return [
            'available'     => true,
            'root_path'     => $real,
            'total_bytes'   => $totalBytes,
            'total_files'   => $totalFiles,
            'total_dirs'    => $totalDirs,
            'top_folders'   => array_slice(array_values($topFolders), 0, 12),
            'largest_files' => array_slice($largestFiles, 0, 15),
            'extensions'    => array_slice(array_values($extBreakdown), 0, 12),
            'recent_files'  => array_slice($recentFiles, 0, 15),
            'capped'        => $totalFiles >= $maxScan,
        ];
    }

    public static function gitDeep(string $path): array
    {
        if ($path === '' || !is_dir($path . '/.git')) {
            return [
                'available' => false,
                'note'      => 'Folder proyek belum memiliki repositori .git.',
            ];
        }

        $branch   = self::runGit('git rev-parse --abbrev-ref HEAD', $path);
        $remote   = self::runGit('git config --get remote.origin.url', $path);
        $tot      = self::runGit('git rev-list --count HEAD', $path);
        $tag      = self::runGit('git describe --tags --abbrev=0', $path);
        $branches = self::runGit('git branch -a --no-color', $path);
        $status   = self::runGit('git status --porcelain', $path);
        $logRaw   = self::runGit('git log -15 --format="%h|%an|%cr|%ci|%s"', $path);

        $ahead  = 0;
        $behind = 0;
        $ab = self::runGit('git rev-list --left-right --count HEAD...@{u}', $path);
        if ($ab !== '' && preg_match('/^(\d+)\s+(\d+)$/', $ab, $m)) {
            $ahead  = (int) $m[1];
            $behind = (int) $m[2];
        }

        $packBytes = 0;
        $gitObjDir = $path . '/.git/objects';
        if (is_dir($gitObjDir)) {
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($gitObjDir, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $f) {
                    if ($f->isFile()) {
                        $packBytes += (int) @$f->getSize();
                    }
                }
            } catch (Throwable $e) {
            }
        }

        $commits = [];
        if ($logRaw !== '') {
            foreach (explode("\n", $logRaw) as $ln) {
                $p = explode('|', trim($ln, '"'), 5);
                if (count($p) === 5) {
                    $commits[] = [
                        'hash'    => $p[0],
                        'author'  => $p[1],
                        'rel'     => $p[2],
                        'date'    => $p[3],
                        'subject' => $p[4],
                    ];
                }
            }
        }

        $branchList = [];
        if ($branches !== '') {
            foreach (explode("\n", $branches) as $b) {
                $b = trim($b);
                if ($b !== '') {
                    $branchList[] = $b;
                }
            }
        }

        $dirtyList = [];
        if ($status !== '') {
            foreach (explode("\n", $status) as $s) {
                $s = trim($s);
                if ($s !== '') {
                    $dirtyList[] = $s;
                }
            }
        }

        return [
            'available'     => true,
            'branch'        => $branch !== '' ? $branch : 'main',
            'remote_url'    => $remote !== '' ? $remote : '(belum diatur)',
            'total_commits' => (int) $tot,
            'latest_tag'    => $tag !== '' ? $tag : '(belum ada tag)',
            'ahead'         => $ahead,
            'behind'        => $behind,
            'pack_bytes'    => $packBytes,
            'branches'      => $branchList,
            'dirty_files'   => $dirtyList,
            'commits'       => $commits,
        ];
    }

    public static function frameworkInfo(string $path): array
    {
        if ($path === '' || !is_dir($path)) {
            return [
                'available' => false,
                'note'      => 'Folder proyek lokal tidak ditemukan.',
            ];
        }

        $framework = 'PHP Native';
        $version   = '-';
        $envMode   = '-';
        $baseUrl   = '-';
        $dbDriver  = '-';

        if (is_file($path . '/system/core/CodeIgniter.php') || is_dir($path . '/application/controllers')) {
            $framework = 'CodeIgniter 3';
            $ciCore = $path . '/system/core/CodeIgniter.php';
            if (is_file($ciCore) && is_readable($ciCore)) {
                $raw = (string) @file_get_contents($ciCore);
                if (preg_match("/CI_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $raw, $m)) {
                    $version = $m[1];
                }
            }
            $idxFile = $path . '/index.php';
            if (is_file($idxFile) && is_readable($idxFile)) {
                $raw = (string) @file_get_contents($idxFile);
                if (preg_match("/define\(\s*['\"]ENVIRONMENT['\"]\s*,[^'\"]*['\"]([^'\"]+)['\"]/", $raw, $m)) {
                    $envMode = $m[1];
                }
            }
            $cfgFile = $path . '/application/config/config.php';
            if (is_file($cfgFile) && is_readable($cfgFile)) {
                $raw = (string) @file_get_contents($cfgFile);
                if (preg_match('/\$config\[[\'"]base_url[\'"]\]\s*=\s*[\'"]([^\'"]+)[\'"]/', $raw, $m)) {
                    $baseUrl = $m[1];
                }
            }
            $dbFile = $path . '/application/config/database.php';
            if (is_file($dbFile) && is_readable($dbFile)) {
                $raw = (string) @file_get_contents($dbFile);
                if (preg_match('/[\'"]dbdriver[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $raw, $m)) {
                    $dbDriver = $m[1];
                }
            }
        } elseif (is_file($path . '/spark') || is_dir($path . '/app/Config')) {
            $framework = 'CodeIgniter 4';
            $envMode   = is_file($path . '/.env') ? 'Dikonfigurasi via .env' : 'production';
        } elseif (is_file($path . '/artisan')) {
            $framework = 'Laravel';
            $envMode   = is_file($path . '/.env') ? 'Dikonfigurasi via .env' : 'production';
        } elseif (is_file($path . '/wp-includes/version.php')) {
            $framework = 'WordPress';
            $raw = (string) @file_get_contents($path . '/wp-includes/version.php');
            if (preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $raw, $m)) {
                $version = $m[1];
            }
        }

        $composerPackages = [];
        $compFile = $path . '/composer.json';
        if (is_file($compFile) && is_readable($compFile)) {
            $comp = json_decode((string) @file_get_contents($compFile), true);
            if (is_array($comp) && !empty($comp['require']) && is_array($comp['require'])) {
                foreach ($comp['require'] as $pkg => $ver) {
                    $composerPackages[] = $pkg . ': ' . $ver;
                }
            }
        }

        $htaccessLines = [];
        $htFile = $path . '/.htaccess';
        if (is_file($htFile) && is_readable($htFile)) {
            $lines = file($htFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $htaccessLines = array_slice($lines, 0, 25);
        }

        $importantExts = ['curl', 'mbstring', 'openssl', 'pdo_mysql', 'pdo_sqlite', 'zip', 'gd', 'intl'];
        $extStatus = [];
        foreach ($importantExts as $ext) {
            $extStatus[$ext] = extension_loaded($ext);
        }

        return [
            'available'         => true,
            'framework'         => $framework,
            'framework_version' => $version,
            'environment'       => $envMode,
            'base_url'          => $baseUrl,
            'db_driver'         => $dbDriver,
            'php_version'       => PHP_VERSION,
            'php_sapi'          => PHP_SAPI,
            'memory_limit'      => (string) ini_get('memory_limit'),
            'upload_max'        => (string) ini_get('upload_max_filesize'),
            'post_max'          => (string) ini_get('post_max_size'),
            'max_exec_time'     => (string) ini_get('max_execution_time') . ' detik',
            'composer_packages' => $composerPackages,
            'htaccess_preview'  => $htaccessLines,
            'extensions'        => $extStatus,
        ];
    }

    public static function errorAnalytics(string $path): array
    {
        if ($path === '' || !is_dir($path)) {
            return [
                'available' => false,
                'note'      => 'Folder proyek lokal tidak ditemukan.',
            ];
        }

        $files = [];
        foreach ([$path . '/error_log', $path . '/php_errorlog', $path . '/storage/logs/laravel.log'] as $f) {
            if (is_file($f) && is_readable($f)) {
                $files[] = $f;
            }
        }
        foreach (glob($path . '/application/logs/log-*.php') ?: [] as $cf) {
            if (is_readable($cf)) {
                $files[] = $cf;
            }
        }

        $groups = [];
        $totalLines = 0;

        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_slice($lines, -250) as $line) {
                $trim = trim($line);
                if ($trim === '' || str_starts_with($trim, '<?php')) {
                    continue;
                }
                $totalLines++;

                $clean = preg_replace('/^\[[^\]]+\]\s*/', '', $trim);
                $clean = preg_replace('/^(ERROR|WARNING|NOTICE|DEBUG)\s*-\s*\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\s*-->\s*/i', '', (string) $clean);
                $key   = md5((string) $clean);

                $sev = 'NOTICE';
                if (stripos($trim, 'fatal') !== false || stripos($trim, 'exception') !== false || stripos($trim, 'error') !== false) {
                    $sev = 'ERROR';
                } elseif (stripos($trim, 'warn') !== false) {
                    $sev = 'WARNING';
                }

                $loc = '-';
                if (preg_match('#in\s+([^\s:]+\.php)(?:\s+on\s+line\s+|\:)(\d+)#i', (string) $clean, $m)) {
                    $loc = basename($m[1]) . ':' . $m[2];
                }

                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'severity' => $sev,
                        'message'  => mb_substr((string) $clean, 0, 260),
                        'location' => $loc,
                        'source'   => basename($file),
                        'count'    => 0,
                    ];
                }
                $groups[$key]['count']++;
            }
        }

        usort($groups, fn($a, $b) => $b['count'] <=> $a['count']);

        return [
            'available'   => true,
            'log_files'   => array_map('basename', $files),
            'total_lines' => $totalLines,
            'unique_bugs' => count($groups),
            'items'       => array_slice(array_values($groups), 0, 20),
        ];
    }

    public static function checkLinks(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'MONWEB-By-AP-LinkChecker/1.0',
        ]);
        $html = (string) curl_exec($ch);
        $baseCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string) (curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url);
        curl_close($ch);

        if ($baseCode === 0 || $html === '') {
            return [
                'available' => false,
                'note'      => 'Halaman utama tidak dapat diunduh untuk pemindaian tautan.',
            ];
        }

        $originHost = strtolower((string) parse_url($finalUrl, PHP_URL_HOST));
        $originScheme = (string) (parse_url($finalUrl, PHP_URL_SCHEME) ?: 'https');

        preg_match_all('#<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER);

        $targets = [];
        foreach ($matches as $m) {
            $href = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
            $text = trim(strip_tags($m[2]));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:') || str_starts_with(strtolower($href), 'mailto:') || str_starts_with(strtolower($href), 'tel:')) {
                continue;
            }

            if (preg_match('#^https?://#i', $href)) {
                $full = $href;
            } elseif (str_starts_with($href, '//')) {
                $full = $originScheme . ':' . $href;
            } elseif (str_starts_with($href, '/')) {
                $full = $originScheme . '://' . $originHost . $href;
            } else {
                $dir = rtrim((string) dirname(parse_url($finalUrl, PHP_URL_PATH) ?: '/'), '/');
                $full = $originScheme . '://' . $originHost . $dir . '/' . $href;
            }

            $linkHost = strtolower((string) parse_url($full, PHP_URL_HOST));
            if ($linkHost !== $originHost) {
                continue;
            }

            if (!isset($targets[$full])) {
                $targets[$full] = $text !== '' ? mb_substr($text, 0, 50) : '(tanpa teks)';
            }
            if (count($targets) >= 12) {
                break;
            }
        }

        $results = [];
        $brokenCount = 0;

        foreach ($targets as $linkUrl => $label) {
            $start = microtime(true);
            $c = curl_init($linkUrl);
            curl_setopt_array($c, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY         => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'MONWEB-By-AP-LinkChecker/1.0',
            ]);
            @curl_exec($c);
            $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
            if ($code === 405 || $code === 0) {
                curl_setopt($c, CURLOPT_NOBODY, false);
                @curl_exec($c);
                $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
            }
            $ms = (int) round((microtime(true) - $start) * 1000);
            curl_close($c);

            $isBroken = ($code >= 400 || $code === 0);
            if ($isBroken) {
                $brokenCount++;
            }

            $results[] = [
                'url'    => $linkUrl,
                'label'  => $label,
                'code'   => $code,
                'ms'     => $ms,
                'broken' => $isBroken,
            ];
        }

        return [
            'available'    => true,
            'checked'      => count($results),
            'broken_count' => $brokenCount,
            'items'        => $results,
        ];
    }

    private static function runGit(string $cmd, string $cwd): string
    {
        if (!function_exists('proc_open')) {
            return '';
        }
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $desc, $pipes, $cwd);
        if (!is_resource($proc)) {
            return '';
        }
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return $code === 0 ? trim($out) : '';
    }
}
