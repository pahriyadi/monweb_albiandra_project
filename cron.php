<?php
declare(strict_types=1);

/**
 * MONWEB By AP - ALBIANDRA PROJECT
 * Cron & GitHub Webhook Endpoint (cron.php)
 *
 * 1. Jadwalkan di cPanel / hPanel Niagahoster setiap hari jam 08:00 pagi:
 *    0 8 * * * /usr/bin/php /home/username/public_html/monweb/cron.php --daily-report
 *
 * 2. Atau panggil via URL Cron / GitHub Webhook:
 *    https://monweb.baznassumbawa.id/cron.php?mode=daily_report
 *    https://monweb.baznassumbawa.id/cron.php?mode=github_webhook&site_id=1
 */

require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/SiteOperations.php';

use MonWeb\Database;
use MonWeb\SiteOperations;

$tz = Database::getSetting('wa_timezone', 'Asia/Makassar');
@date_default_timezone_set($tz);

$ops  = new SiteOperations();
$mode = $_GET['mode'] ?? '';

if (PHP_SAPI === 'cli') {
    $args = $argv ?? [];
    if (in_array('--daily-report', $args, true)) {
        $mode = 'daily_report';
    } else {
        $mode = 'sync_all';
    }
}

header('Content-Type: application/json; charset=utf-8');

if ($mode === 'github_webhook') {
    $siteId = (int) ($_GET['site_id'] ?? 0);
    if ($siteId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Parameter site_id diperlukan untuk GitHub Webhook.']);
        exit;
    }
    $res = $ops->executeGitAction($siteId, 'git_pull');
    echo json_encode([
        'ok'      => $res['ok'],
        'webhook' => 'github_auto_deploy',
        'site_id' => $siteId,
        'output'  => $res['output'],
    ]);
    exit;
}

if ($mode === 'daily_report') {
    $ops->syncAllSites();
    $report = $ops->dispatchDailyWhatsAppReport();
    echo json_encode($report);
    exit;
}

$ops->syncAllSites();
echo json_encode([
    'ok'          => true,
    'mode'        => 'sync_all',
    'executed_at' => date('Y-m-d H:i:s'),
    'sites_count' => count($ops->getAllSites()),
]);

