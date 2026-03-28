<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/telegram.php';

// Security: Only allow CLI or internal Cron execution
$isCLI = (php_sapi_name() === 'cli');
$isCron = defined('CRON_EXECUTION');

if (!$isCLI && !$isCron) {
    die("Access Denied");
}

$dbName = DB_NAME;
$dbUser = DB_USER;
$dbPass = DB_PASS;
$dbHost = DB_HOST;

$timestamp = date('Y-m-d_H-i-s');
$fileName = "backup_{$dbName}_{$timestamp}.sql";
$filePath = __DIR__ . "/logs/{$fileName}";

if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// 1. Generate Dump using PHP (Portability workaround if mysqldump is missing)
try {
    $db = getDB();
    $tables = [];
    $result = $db->query("SHOW TABLES");
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    $sqlDump = "-- Database Backup: {$dbName}\n";
    $sqlDump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $sqlDump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $res = $db->query("SHOW CREATE TABLE `{$table}`");
        $createRow = $res->fetch(PDO::FETCH_NUM);
        $sqlDump .= "\n\n" . $createRow[1] . ";\n\n";

        $res = $db->query("SELECT * FROM `{$table}`");
        while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
            $keys = array_map(function ($k) {
                return "`$k`";
            }, array_keys($row));
            $values = array_map(function ($v) use ($db) {
                if ($v === null)
                    return 'NULL';
                return $db->quote($v);
            }, array_values($row));
            $sqlDump .= "INSERT INTO `{$table}` (" . implode(', ', $keys) . ") VALUES (" . implode(', ', $values) . ");\n";
        }
    }

    $sqlDump .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
    file_put_contents($filePath, $sqlDump);

    // 2. Send to Telegram
    $caption = "📁 <b>BẢN BACKUP DỮ LIỆU</b>\n\n";
    $caption .= "📅 Ngày: <code>" . date('d/m/Y H:i') . "</code>\n";
    $caption .= "🗄️ Database: <code>{$dbName}</code>\n\n";
    $caption .= "<i>Đây là bản sao lưu dữ liệu tự động. Anh hãy lưu giữ cẩn thận nhé!</i>";

    if (function_exists('sendTelegramDocument')) {
        $success = sendTelegramDocument($filePath, $caption);
        if ($success) {
            echo "✅ Backup success and sent to Telegram.\n";
            logToFile('admin_actions.log', "BACKUP: Database backed up and sent to Telegram.");
        } else {
            echo "❌ Failed to send backup to Telegram.\n";
            logToFile('error_' . date('Y-m-d') . '.log', "BACKUP: Failed to send backup to Telegram.");
        }
    } else {
        echo "⚠️ sendTelegramDocument function missing. Please check api/telegram.php.\n";
    }

    // 3. Cleanup
    if (file_exists($filePath)) {
        unlink($filePath);
    }

} catch (Exception $e) {
    echo "❌ Backup Error: " . $e->getMessage();
    logToFile('error_' . date('Y-m-d') . '.log', "BACKUP ERROR: " . $e->getMessage());
}