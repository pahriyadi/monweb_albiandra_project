<?php
declare(strict_types=1);

namespace MonWeb;

/**
 * Pemeriksaan dari luar (tanpa akses file): HTTP & performa, SSL, DNS, dan header keamanan.
 */
class RemoteProbe
{
    private const UA = 'MONWEB-By-AP-Inspector/1.0 (ALBIANDRA PROJECT)';

    public static function http(string $url): array
    {
        $chain = [];
        $current = $url;
        $final = null;

        for ($i = 0; $i < 6; $i++) {
            $res = self::request($current, true);
            $chain[] = [
                'url'    => $current,
                'code'   => $res['code'],
                'ms'     => $res['timing']['total'],
            ];
            if ($res['code'] >= 300 && $res['code'] < 400 && !empty($res['headers']['location'])) {
                $current = self::resolveUrl($current, $res['headers']['location']);
                continue;
            }
            $final = $res;
            break;
        }

        if ($final === null) {
            $final = self::request($current, true);
        }

        $body = $final['body'];
        $title = '';
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
        }
        $generator = '';
        if (preg_match('#<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)#i', $body, $m)) {
            $generator = trim($m[1]);
        }

        return [
            'final_url'   => $current,
            'code'        => $final['code'],
            'timing'      => $final['timing'],
            'size_bytes'  => $final['size'],
            'compressed'  => isset($final['headers']['content-encoding']),
            'encoding'    => $final['headers']['content-encoding'] ?? '-',
            'server'      => $final['headers']['server'] ?? '-',
            'powered_by'  => $final['headers']['x-powered-by'] ?? '',
            'content_type'=> $final['headers']['content-type'] ?? '-',
            'http_version'=> $final['http_version'],
            'title'       => $title,
            'generator'   => $generator,
            'redirects'   => $chain,
            'headers'     => $final['headers'],
            'security'    => self::auditHeaders($final['headers'], str_starts_with(strtolower($current), 'https://')),
        ];
    }

    public static function ssl(string $url): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return ['available' => false, 'note' => 'Host tidak valid.'];
        }

        $ctx = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ]]);
        $client = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 6, STREAM_CLIENT_CONNECT, $ctx);
        if (!$client) {
            return ['available' => false, 'note' => 'Port 443 tidak merespons: ' . ($errstr ?: 'timeout')];
        }

        $meta = stream_get_meta_data($client);
        $params = stream_context_get_params($client);
        fclose($client);

        $cert = !empty($params['options']['ssl']['peer_certificate'])
            ? openssl_x509_parse($params['options']['ssl']['peer_certificate'])
            : false;
        if (!is_array($cert)) {
            return ['available' => false, 'note' => 'Sertifikat tidak bisa dibaca.'];
        }

        $sans = [];
        if (!empty($cert['extensions']['subjectAltName'])) {
            foreach (explode(',', $cert['extensions']['subjectAltName']) as $san) {
                $sans[] = trim(str_replace('DNS:', '', $san));
            }
        }

        $matches = false;
        foreach ($sans as $san) {
            if (strcasecmp($san, $host) === 0) {
                $matches = true;
                break;
            }
            if (str_starts_with($san, '*.') && strcasecmp(substr($san, 2), substr($host, strpos($host, '.') + 1)) === 0) {
                $matches = true;
                break;
            }
        }

        $validTo = (int) ($cert['validTo_time_t'] ?? 0);

        return [
            'available'   => true,
            'subject_cn'  => $cert['subject']['CN'] ?? '-',
            'issuer'      => trim(($cert['issuer']['O'] ?? '') . ' ' . ($cert['issuer']['CN'] ?? '')) ?: '-',
            'valid_from'  => date('Y-m-d', (int) ($cert['validFrom_time_t'] ?? 0)),
            'valid_to'    => date('Y-m-d', $validTo),
            'days_left'   => (int) floor(($validTo - time()) / 86400),
            'sans'        => $sans,
            'host_match'  => $matches,
            'signature'   => $cert['signatureTypeSN'] ?? '-',
            'protocol'    => $meta['crypto']['protocol'] ?? '-',
            'cipher'      => $meta['crypto']['cipher_name'] ?? '-',
        ];
    }

    public static function dns(string $url): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $root = self::rootDomain($host);

        $fetch = function (string $name, int $type): array {
            $r = @dns_get_record($name, $type);
            return is_array($r) ? $r : [];
        };

        $a = array_map(fn($r) => $r['ip'], $fetch($host, DNS_A));
        $aaaa = array_map(fn($r) => $r['ipv6'], $fetch($host, DNS_AAAA));
        $cname = array_map(fn($r) => $r['target'], $fetch($host, DNS_CNAME));
        $ns = array_map(fn($r) => $r['target'], $fetch($root, DNS_NS));
        $mx = array_map(fn($r) => $r['pri'] . ' ' . $r['target'], $fetch($root, DNS_MX));
        $txt = array_map(fn($r) => $r['txt'], $fetch($root, DNS_TXT));
        $dmarc = array_map(fn($r) => $r['txt'], $fetch('_dmarc.' . $root, DNS_TXT));

        $spf = '';
        foreach ($txt as $t) {
            if (str_starts_with(strtolower($t), 'v=spf1')) {
                $spf = $t;
            }
        }

        $reverse = [];
        foreach (array_slice($a, 0, 3) as $ip) {
            $ptr = @gethostbyaddr($ip);
            $reverse[] = $ip . ' -> ' . ($ptr && $ptr !== $ip ? $ptr : '(tanpa PTR)');
        }

        return [
            'host'    => $host,
            'root'    => $root,
            'a'       => $a,
            'aaaa'    => $aaaa,
            'cname'   => $cname,
            'ns'      => $ns,
            'mx'      => $mx,
            'txt'     => $txt,
            'spf'     => $spf,
            'dmarc'   => $dmarc[0] ?? '',
            'reverse' => $reverse,
        ];
    }

    private static function auditHeaders(array $h, bool $isHttps): array
    {
        $checks = [
            ['Strict-Transport-Security', 'strict-transport-security', 'Memaksa browser selalu memakai HTTPS.'],
            ['Content-Security-Policy', 'content-security-policy', 'Membatasi sumber script/style yang boleh dimuat.'],
            ['X-Frame-Options', 'x-frame-options', 'Mencegah halaman disisipkan ke iframe situs lain.'],
            ['X-Content-Type-Options', 'x-content-type-options', 'Mencegah browser menebak tipe file.'],
            ['Referrer-Policy', 'referrer-policy', 'Mengatur data URL asal yang dikirim ke situs lain.'],
            ['Permissions-Policy', 'permissions-policy', 'Membatasi akses kamera, mikrofon, lokasi.'],
        ];

        $items = [];
        $passed = 0;
        foreach ($checks as [$label, $key, $why]) {
            $present = isset($h[$key]);
            if ($key === 'strict-transport-security' && !$isHttps) {
                $present = false;
            }
            if ($present) {
                $passed++;
            }
            $items[] = [
                'label'   => $label,
                'present' => $present,
                'value'   => $h[$key] ?? '',
                'why'     => $why,
            ];
        }

        $leaks = [];
        foreach (['x-powered-by', 'server', 'x-aspnet-version'] as $k) {
            if (!empty($h[$k]) && preg_match('/\d/', $h[$k])) {
                $leaks[] = ucwords($k, '-') . ': ' . $h[$k];
            }
        }

        return [
            'items'  => $items,
            'passed' => $passed,
            'total'  => count($checks),
            'leaks'  => $leaks,
        ];
    }

    private static function request(string $url, bool $withBody): array
    {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOBODY         => !$withBody,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
                $len = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
        ]);
        $body = (string) curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        $ms = fn($k) => (int) round(((float) ($info[$k] ?? 0)) * 1000);
        $httpVer = match ((int) ($info['http_version'] ?? 0)) {
            CURL_HTTP_VERSION_1_0 => 'HTTP/1.0',
            CURL_HTTP_VERSION_1_1 => 'HTTP/1.1',
            CURL_HTTP_VERSION_2_0 => 'HTTP/2',
            3                     => 'HTTP/3',
            default               => '-',
        };

        return [
            'code'         => (int) ($info['http_code'] ?? 0),
            'headers'      => $headers,
            'body'         => $body,
            'size'         => strlen($body),
            'http_version' => $httpVer,
            'timing'       => [
                'dns'     => $ms('namelookup_time'),
                'connect' => $ms('connect_time'),
                'tls'     => $ms('appconnect_time'),
                'ttfb'    => $ms('starttransfer_time'),
                'total'   => $ms('total_time'),
            ],
        ];
    }

    private static function resolveUrl(string $base, string $loc): string
    {
        if (preg_match('#^https?://#i', $loc)) {
            return $loc;
        }
        $p = parse_url($base);
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
        if (str_starts_with($loc, '/')) {
            return $origin . $loc;
        }
        $dir = rtrim(dirname($p['path'] ?? '/'), '/');
        return $origin . $dir . '/' . $loc;
    }

    private static function rootDomain(string $host): string
    {
        $parts = explode('.', $host);
        $n = count($parts);
        if ($n <= 2) {
            return $host;
        }
        $sld = ['co', 'ac', 'go', 'or', 'sch', 'web', 'my', 'biz', 'net', 'mil', 'ponpes', 'desa'];
        if ($parts[$n - 1] === 'id' && in_array($parts[$n - 2], $sld, true)) {
            return implode('.', array_slice($parts, -3));
        }
        return implode('.', array_slice($parts, -2));
    }
}

