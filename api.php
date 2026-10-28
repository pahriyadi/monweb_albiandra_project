<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Database.php';
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
                $id      = (int) ($req['id'] ?? 0);
                $gitType = (string) ($req['git_type'] ?? 'git_pull');
                $res     = $ops->executeGitAction($id, $gitType);
                echo json_encode($res);
                break;

            case 'clear_logs':
                $id  = (int) ($req['id'] ?? 0);
                $res = $ops->clearSiteErrorLogs($id);
                echo json_encode($res);
                break;

            case 'save_settings':
                $fields = ['wa_recipient', 'wa_schedule_time', 'wa_timezone', 'wa_auto_trigger', 'wa_webhook_url', 'wa_webhook_token'];
                foreach ($fields as $f) {
                    if (isset($req[$f])) {
                        Database::setSetting($f, trim((string) $req[$f]));
                    }
                }
                echo json_encode([
                    'ok'       => true,
                    'message'  => 'Konfigurasi jadwal laporan WhatsApp 08:00 pagi berhasil disimpan.',
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

