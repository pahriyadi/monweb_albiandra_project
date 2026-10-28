<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/RemoteProbe.php';
require_once __DIR__ . '/app/LocalInspector.php';
require_once __DIR__ . '/app/ProbeView.php';
require_once __DIR__ . '/app/SiteOperations.php';

use MonWeb\Database;
use MonWeb\SiteOperations;

$tz = Database::getSetting('wa_timezone', 'Asia/Makassar');
@date_default_timezone_set($tz);

$ops = new SiteOperations();

if (isset($_GET['download_agent']) && $_GET['download_agent'] === '1') {
    $secret = Database::getSetting('master_agent_secret');
    $tplPath = __DIR__ . '/agent/monweb-agent-template.php';
    $code = is_file($tplPath) ? (string) file_get_contents($tplPath) : '';
    $code = str_replace('{{MONWEB_SECRET_KEY}}', addslashes($secret), $code);

    header('Content-Type: application/x-httpd-php; charset=utf-8');
    header('Content-Disposition: attachment; filename="monweb-agent.php"');
    echo $code;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = (string) file_get_contents('php://input');
    $req = json_decode($raw, true);
    if (!is_array($req)) {
        $req = $_POST;
    }

    $action = (string) ($req['action'] ?? '');

    if ($action === 'verify_pin') {
        $inputPin = trim((string) ($req['pin_code'] ?? ''));
        $savedPin = Database::getSetting('security_pin_code', '123456');
        if ($inputPin !== '' && hash_equals($savedPin, $inputPin)) {
            $_SESSION['monweb_unlocked'] = true;
            echo json_encode(['ok' => true, 'message' => 'Akses konsol dibuka.']);
        } else {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Kode PIN Keamanan tidak sesuai.']);
        }
        exit;
    }

    if ($action === 'lock_session') {
        unset($_SESSION['monweb_unlocked']);
        echo json_encode(['ok' => true]);
        exit;
    }

    $pinEnabled = Database::getSetting('security_pin_enabled', '0') === '1';
    if ($pinEnabled && empty($_SESSION['monweb_unlocked'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Sesi terkunci. Masukkan PIN Keamanan terlebih dahulu.']);
        exit;
    }

    try {
        switch ($action) {
            case 'add_site':
                $newId = $ops->addSite($req);
                $sync  = $ops->syncSite($newId);
                echo json_encode([
                    'ok'      => true,
                    'message' => 'Website berhasil ditambahkan dan disinkronkan.',
                    'site'    => $sync['site'] ?? $ops->getSiteById($newId),
                ]);
                break;

            case 'auto_discover':
                $res = $ops->discoverLocalHostingDirectories();
                echo json_encode($res);
                break;

            case 'delete_site':
                $id = (int) ($req['id'] ?? 0);
                $ops->deleteSite($id);
                echo json_encode(['ok' => true, 'message' => 'Website telah dihapus dari daftar pantauan.']);
                break;

            case 'sync_site':
                $id = (int) ($req['id'] ?? 0);
                $res = $ops->syncSite($id);
                echo json_encode($res);
                break;

            case 'sync_all':
                $res = $ops->syncAllSites();
                echo json_encode([
                    'ok'    => true,
                    'sites' => $ops->getAllSites(),
                    'count' => count($res),
                ]);
                break;

            case 'git_action':
                $id        = (int) ($req['id'] ?? 0);
                $gitType   = (string) ($req['git_type'] ?? 'git_pull');
                $customCmd = (string) ($req['custom_cmd'] ?? '');
                $res       = $ops->executeGitAction($id, $gitType, $customCmd);
                echo json_encode($res);
                break;

            case 'mass_git_pull':
                $res = $ops->executeMassGitPull();
                echo json_encode($res);
                break;

            case 'clear_logs':
                $id  = (int) ($req['id'] ?? 0);
                $res = $ops->clearSiteErrorLogs($id);
                echo json_encode($res);
                break;

            case 'inspect_section':
                $id      = (int) ($req['id'] ?? 0);
                $section = (string) ($req['section'] ?? 'http');
                $res     = $ops->inspectSiteSection($id, $section);
                echo json_encode($res);
                break;

            case 'save_settings':
                $fields = [
                    'wa_recipient',
                    'wa_schedule_time',
                    'wa_timezone',
                    'wa_auto_trigger',
                    'wa_webhook_url',
                    'wa_webhook_token',
                    'security_pin_enabled',
                    'security_pin_code',
                ];
                foreach ($fields as $f) {
                    if (isset($req[$f])) {
                        Database::setSetting($f, trim((string) $req[$f]));
                    }
                }
                if (($req['security_pin_enabled'] ?? '0') === '1') {
                    $_SESSION['monweb_unlocked'] = true;
                }
                echo json_encode([
                    'ok'       => true,
                    'message'  => 'Konfigurasi sistem dan jadwal otomatisasi berhasil disimpan.',
                    'settings' => Database::getAllSettings(),
                ]);
                break;

            case 'trigger_wa_report':
                $ops->syncAllSites();
                $report = $ops->dispatchDailyWhatsAppReport();
                echo json_encode($report);
                break;

            default:
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Aksi API tidak valid: ' . $action]);
                break;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'Gunakan metode POST atau parameter download_agent=1.']);
