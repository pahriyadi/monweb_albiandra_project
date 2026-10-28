<?php
declare(strict_types=1);

namespace MonWeb;

use PDO;

class Database {
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $storageDir = dirname(__DIR__) . '/storage';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }

        $envFile = dirname(__DIR__) . '/.env.php';
        $config = is_file($envFile) ? require $envFile : [];

        $driver = $config['DB_DRIVER'] ?? 'sqlite';

        if ($driver === 'mysql' && !empty($config['DB_NAME']) && $config['DB_USER'] !== 'u123456_admin') {
            try {
                $host = $config['DB_HOST'] ?? '127.0.0.1';
                $port = $config['DB_PORT'] ?? '3306';
                $db   = $config['DB_NAME'];
                $user = $config['DB_USER'] ?? 'root';
                $pass = $config['DB_PASS'] ?? '';
                $dsn  = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
                self::$pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                self::migrate(self::$pdo, 'mysql');
                return self::$pdo;
            } catch (\Throwable $e) {
                // Fallback otomatis ke SQLite jika kredensial MySQL di .env.php belum sesuai
            }
        }

        $sqlitePath = $storageDir . '/monweb.sqlite';
        self::$pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::$pdo->exec('PRAGMA journal_mode = WAL;');

        self::migrate(self::$pdo, 'sqlite');
        return self::$pdo;
    }

    private static function migrate(PDO $pdo, string $driver): void {
        $autoInc = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS websites (
                id {$autoInc},
                name VARCHAR(120) NOT NULL,
                url VARCHAR(255) NOT NULL,
                stack_type VARCHAR(60) NOT NULL DEFAULT 'PHP Native',
                hosting_panel VARCHAR(60) NOT NULL DEFAULT 'hPanel Niagahoster',
                conn_mode VARCHAR(20) NOT NULL DEFAULT 'agent',
                agent_url VARCHAR(255) DEFAULT '',
                agent_secret VARCHAR(190) DEFAULT '',
                ssh_host VARCHAR(190) DEFAULT '',
                ssh_port INT DEFAULT 65002,
                ssh_user VARCHAR(120) DEFAULT '',
                ssh_key TEXT,
                repo_path VARCHAR(255) DEFAULT '',
                git_branch VARCHAR(80) DEFAULT 'main',
                status VARCHAR(20) DEFAULT 'UNCHECKED',
                http_code INT DEFAULT 0,
                latency_ms INT DEFAULT 0,
                ssl_days_left INT DEFAULT -1,
                disk_used_mb REAL DEFAULT 0,
                disk_free_gb REAL DEFAULT 0,
                inodes_count INT DEFAULT 0,
                last_commit_hash VARCHAR(40) DEFAULT '-',
                last_commit_msg VARCHAR(255) DEFAULT 'Belum disinkronkan',
                dirty_files INT DEFAULT 0,
                error_count INT DEFAULT 0,
                visitor_hits_today INT DEFAULT 0,
                last_checked_at VARCHAR(40) DEFAULT NULL,
                created_at VARCHAR(40) NOT NULL
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS error_logs (
                id {$autoInc},
                website_id INT NOT NULL,
                source_file VARCHAR(120) NOT NULL,
                severity VARCHAR(30) NOT NULL DEFAULT 'ERROR',
                message TEXT NOT NULL,
                logged_at VARCHAR(40) NOT NULL
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS deploy_logs (
                id {$autoInc},
                website_id INT NOT NULL,
                action_type VARCHAR(50) NOT NULL,
                command_str VARCHAR(255) NOT NULL,
                output_text TEXT NOT NULL,
                is_success INT NOT NULL DEFAULT 1,
                executed_at VARCHAR(40) NOT NULL
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS uptime_checks (
                id {$autoInc},
                website_id INT NOT NULL,
                http_code INT NOT NULL,
                latency_ms INT NOT NULL,
                is_online INT NOT NULL DEFAULT 1,
                checked_at VARCHAR(40) NOT NULL
            );
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT NOT NULL
            );
        ");

        $defaults = [
            'company_name'           => 'ALBIANDRA PROJECT',
            'app_name'               => 'MONWEB By AP',
            'wa_recipient'           => '081936955747',
            'wa_schedule_time'       => '08:00',
            'wa_timezone'            => 'Asia/Makassar',
            'wa_auto_trigger'        => '1',
            'wa_webhook_url'         => '',
            'wa_webhook_token'       => '',
            'last_daily_report_date' => '',
            'master_agent_secret'    => 'ap_monweb_' . substr(hash('sha256', __DIR__ . 'ALBIANDRA_PROJECT'), 0, 24),
            'security_pin_enabled'   => '0',
            'security_pin_code'      => '123456',
        ];

        $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE setting_key = :k');
        $stmtIns   = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)');
        foreach ($defaults as $k => $v) {
            $stmtCheck->execute([':k' => $k]);
            if ((int) $stmtCheck->fetchColumn() === 0) {
                $stmtIns->execute([':k' => $k, ':v' => $v]);
            }
        }
    }

    public static function getSetting(string $key, string $default = ''): string {
        $pdo = self::getConnection();
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string) $val : $default;
    }

    public static function setSetting(string $key, string $value): void {
        $pdo = self::getConnection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE setting_key = :k');
        $stmt->execute([':k' => $key]);
        if ((int) $stmt->fetchColumn() > 0) {
            $upd = $pdo->prepare('UPDATE settings SET setting_value = :v WHERE setting_key = :k');
            $upd->execute([':v' => $value, ':k' => $key]);
        } else {
            $ins = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)');
            $ins->execute([':k' => $key, ':v' => $value]);
        }
    }

    public static function getAllSettings(): array {
        $pdo = self::getConnection();
        $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['setting_key']] = $r['setting_value'];
        }
        return $out;
    }
}

