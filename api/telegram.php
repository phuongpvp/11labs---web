<?php
require_once __DIR__ . '/config.php';

function getTelegramConfig()
{
    $db = getDB();
    $stmt = $db->query("SELECT config_key, config_value FROM system_config WHERE config_key IN ('telegram_bot_token', 'telegram_chat_id', 'telegram_enabled', 'telegram_bot_token_2')");
    $config = [];
    while ($row = $stmt->fetch()) {
        $config[$row['config_key']] = $row['config_value'];
    }
    return $config;
}

function sendTelegramMessage($message, $checkEnabled = true)
{
    $config = getTelegramConfig();

    $token = $config['telegram_bot_token'] ?? '';
    $chatId = $config['telegram_chat_id'] ?? '';
    $enabled = $config['telegram_enabled'] ?? '';

    // If checkEnabled is true (default), we respect the "Offline notification" toggle.
    // For registrations, we set this to false to send automatically if credentials exist.
    if ($checkEnabled && !$enabled) {
        return false;
    }

    if (!$token || !$chatId) {
        return false;
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $response = curl_exec($ch);
    curl_close($ch);

    return $response !== false;
}

/**
 * Gửi qua Bot Telegram thứ 2 (Khách hàng: đăng ký, thanh toán, duyệt)
 * Nếu chưa cấu hình token 2 → fallback về token 1
 */
function sendTelegramMessage2($message)
{
    $config = getTelegramConfig();

    $token = $config['telegram_bot_token_2'] ?? '';
    $chatId = $config['telegram_chat_id'] ?? ''; // Dùng chung Chat ID với Bot 1

    // Fallback: nếu chưa cấu hình token 2 thì dùng token 1
    if (!$token) {
        return sendTelegramMessage($message, false);
    }

    if (!$chatId) {
        return false;
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $response = curl_exec($ch);
    curl_close($ch);

    return $response !== false;
}

function notifyNewRegistration($email, $plan)
{
    $msg = "🆕 <b>CÓ KHÁCH ĐĂNG KÝ MỚI</b>\n\n";
    $msg .= "📧 Email: <code>$email</code>\n";
    $msg .= "📦 Gói: <b>" . strtoupper($plan) . "</b>\n\n";
    $msg .= "<i>Anh vào Admin kiểm tra và kích hoạt nhé!</i>";

    return sendTelegramMessage2($msg);
}

function notifyPaymentSent($email, $plan, $amount, $memo)
{
    $msg = "💰 <b>GIAO DỊCH MỚI</b>\n\n";
    $msg .= "📧 Khách hàng: <code>$email</code>\n";
    $msg .= "📦 Gói: <b>" . strtoupper($plan) . "</b>\n";
    $msg .= "💵 Số tiền: <b>" . number_format($amount) . "đ</b>\n";
    $msg .= "📝 Nội dung: <code>$memo</code>\n\n";
    $msg .= "<i>Khách báo đã chuyển tiền. Anh kiểm tra bank và duyệt nhé!</i>";

    return sendTelegramMessage2($msg);
}

function notifyPaymentApproved($email, $plan)
{
    $msg = "✅ <b>DUYỆT THANH TOÁN THÀNH CÔNG</b>\n\n";
    $msg .= "👤 Khách: <code>$email</code>\n";
    $msg .= "🚀 Gói: <b>" . strtoupper($plan) . "</b>\n\n";
    $msg .= "<i>Hệ thống đã tự động kích hoạt gói cước cho khách.</i>";

    return sendTelegramMessage2($msg);
}

function checkLowCreditAlert()
{
    $db = getDB();

    // 1. Calculate total active credits
    $stmt = $db->query("SELECT SUM(credits_remaining) as total FROM api_keys WHERE status = 'active'");
    $result = $stmt->fetch();
    $totalCredits = (int) ($result['total'] ?? 0);
    $threshold = 100000; // 100k characters

    if ($totalCredits < $threshold) {
        // 2. Check cooldown (don't spam, once every 12 hours)
        $configStmt = $db->query("SELECT config_value FROM system_config WHERE config_key = 'last_credit_alert_sent'");
        $lastSent = $configStmt->fetchColumn();
        $cooldown = 12 * 3600; // 12 hours

        if (!$lastSent || (time() - strtotime($lastSent)) > $cooldown) {
            $msg = "🚨 <b>CẢNH BÁO SẮP HẾT CREDIT</b>\n\n";
            $msg .= "💰 Tổng kho hiện tại: <b>" . number_format($totalCredits) . " ký tự</b>\n";
            $msg .= "⚠️ Ngưỡng cảnh báo: <code>" . number_format($threshold) . "</code>\n\n";
            $msg .= "<i>Anh vui lòng bổ sung thêm Key mới để khách không bị gián đoạn nhé!</i>";

            if (sendTelegramMessage($msg, false)) {
                // Update last sent time
                $db->prepare("INSERT INTO system_config (config_key, config_value) VALUES ('last_credit_alert_sent', NOW()) ON DUPLICATE KEY UPDATE config_value = NOW()")->execute();
                return true;
            }
        }
    }
    return false;
}

function checkOfflineWorkers()
{
    $db = getDB();
    // Find workers that are active but haven't been seen for > 60 seconds
    // OR workers that are offline but haven't successfully sent a Telegram alert yet
    try {
        $stmt = $db->query("SELECT worker_uuid, url, ip_address, worker_name, connected_at, last_seen, status, alert_sent 
                           FROM workers 
                           WHERE (status = 'active' AND last_seen < (NOW() - INTERVAL 60 SECOND)) 
                              OR (status = 'offline' AND alert_sent = 0)");
        $offlineWorkers = $stmt->fetchAll();
    } catch (Exception $e) {
        // Fallback for when connected_at doesn't exist yet
        $stmt = $db->query("SELECT worker_uuid, url, '' as ip_address, NULL as worker_name, last_seen as connected_at, last_seen, status, alert_sent 
                           FROM workers 
                           WHERE (status = 'active' AND last_seen < (NOW() - INTERVAL 60 SECOND))
                              OR (status = 'offline' AND alert_sent = 0)");
        $offlineWorkers = $stmt->fetchAll();
    }

    $totalOffline = 0;

    if (count($offlineWorkers) > 0) {
        foreach ($offlineWorkers as $w) {
            $uuid = $w['worker_uuid'];

            // 2. ATOMIC LOCK for alerting: Prevent multiple crons from alerting same worker simultaneously
            // alert_sent: 0 = Pending, 1 = Sent, 2 = Sending...
            if ($w['alert_sent'] != 0)
                continue; // Already handled or in progress

            $lock = $db->prepare("UPDATE workers SET status = 'offline', alert_sent = 2 WHERE worker_uuid = ? AND alert_sent = 0");
            $lock->execute([$uuid]);
            if ($lock->rowCount() === 0)
                continue;

            $totalOffline++;
            $timeOfDeath = date('H:i:s d/m', strtotime($w['last_seen']));
            $durationSec = strtotime($w['last_seen']) - strtotime($w['connected_at'] ?? $w['last_seen']);

            // Fix negative or zero duration
            if ($durationSec < 0)
                $durationSec = 0;

            $hours = floor($durationSec / 3600);
            $minutes = floor(($durationSec % 3600) / 60);
            $durationText = ($hours > 0 ? "{$hours} giờ " : "") . "{$minutes} phút";

            // AUTO-RECOVERY: Release stuck jobs (only once per worker death)
            $recoveredCount = 0;
            try {
                // 1. Recover jobs
                $stuckStmt = $db->prepare("UPDATE conversion_jobs SET status = 'pending', worker_uuid = NULL WHERE status = 'processing' AND worker_uuid = ?");
                $stuckStmt->execute([$uuid]);
                $recoveredCount = $stuckStmt->rowCount();

                // 2. Release API Keys (Tokens)
                $stmtKeys = $db->prepare("UPDATE api_keys SET assigned_worker_uuid = NULL WHERE assigned_worker_uuid = ?");
                $stmtKeys->execute([$uuid]);
            } catch (Exception $e) { /* Ignore */
            }

            // Ưu tiên lấy tên từ bảng workers, nếu không có (null) thì lấy nhãn từ ngrok_keys
            $workerLabel = !empty($w['worker_name']) ? $w['worker_name'] : null;
            if (!$workerLabel) {
                $stmtLabel = $db->prepare("SELECT worker_name FROM ngrok_keys WHERE worker_uuid = ? LIMIT 1");
                $stmtLabel->execute([$uuid]);
                $workerLabel = $stmtLabel->fetchColumn();
            }
            if (!$workerLabel) {
                $workerLabel = 'Worker-' . substr($uuid, 0, 8);
            }

            $workerIp = !empty($w['ip_address']) ? $w['ip_address'] : 'Không rõ';

            $msg = "⚠️ <b>CẢNH BÁO MÁY CHỦ OFFLINE</b>\n\n";
            $msg .= "🏷️ <b>Tên máy:</b> <code>{$workerLabel}</code>\n";
            $msg .= "🌐 <b>IP:</b> <code>{$workerIp}</code>\n";
            $msg .= "🆔 <b>ID:</b> <code>{$uuid}</code>\n";
            $msg .= "🔗 URL: {$w['url']}\n";
            $msg .= "⏰ <b>Ngừng chạy lúc:</b> <code>$timeOfDeath</code>\n";
            $msg .= "⏱️ <b>Tổng thọ:</b> <code>$durationText</code>\n";

            if ($recoveredCount > 0) {
                $msg .= "🔄 <b>Auto-Recover:</b> Đã khôi phục <b>$recoveredCount Job</b> về hàng đợi.\n\n";
            } else {
                $msg .= "\n";
            }

            $msg .= "<i>Vui lòng vào Reset lại máy Colab này anh nhé!</i>";

            if (sendTelegramMessage($msg)) {
                $db->prepare("UPDATE workers SET alert_sent = 1 WHERE worker_uuid = ?")->execute([$uuid]);

                // === AUTO-RESTART: Schedule run_all if not manually disconnected ===
                try {
                    // Get worker name for colab_commands
                    $extName = preg_replace('/^(?:Sever|Server|Sv)/i', 'Sv', $workerLabel);
                    if ($extName) {
                        // Check 1: Was the last disconnect (within 1 hour) a MANUAL admin command?
                        $stmtLastCmd = $db->prepare("SELECT result_message FROM colab_commands WHERE worker_name = ? AND command = 'disconnect' AND created_at > (NOW() - INTERVAL 1 HOUR) ORDER BY created_at DESC LIMIT 1");
                        $stmtLastCmd->execute([$extName]);
                        $lastDisconnect = $stmtLastCmd->fetchColumn();
                        $isManualDisconnect = ($lastDisconnect && strpos($lastDisconnect, 'AUTO:') === false);

                        // Check 2: How many auto-restarts today? (max 3/day)
                        $stmtCount = $db->prepare("SELECT COUNT(*) FROM colab_commands WHERE worker_name = ? AND command = 'run_all' AND result_message LIKE 'AUTO:%' AND created_at > CURDATE()");
                        $stmtCount->execute([$extName]);
                        $restartCountToday = (int) $stmtCount->fetchColumn();

                        if ($isManualDisconnect) {
                            logToFile('auto_restart.log', "SKIP auto-restart for $extName: last disconnect was MANUAL by admin");
                        } elseif ($restartCountToday >= 3) {
                            logToFile('auto_restart.log', "SKIP auto-restart for $extName: already restarted $restartCountToday/3 times today");
                        } else {
                            // Check duplicate: no pending run_all in last 15 min
                            $stmtDup = $db->prepare("SELECT COUNT(*) FROM colab_commands WHERE worker_name = ? AND command = 'run_all' AND status = 'pending' AND created_at > (NOW() - INTERVAL 15 MINUTE)");
                            $stmtDup->execute([$extName]);
                            if ((int) $stmtDup->fetchColumn() === 0) {
                                // Step 0: Release ngrok token in DB (match all name variants)
                                $num = preg_replace('/^(?:Sever|Server|Sv)/i', '', $extName);
                                $variants = ["Sv$num", "Sever$num", "Server$num"];
                                $variants = array_unique($variants);
                                $ph = implode(',', array_fill(0, count($variants), '?'));
                                $stmtToken = $db->prepare("UPDATE ngrok_keys SET worker_uuid = NULL, worker_ip = NULL, assigned_at = NULL WHERE worker_name IN ($ph)");
                                $stmtToken->execute($variants);
                                $released = $stmtToken->rowCount();
                                logToFile('auto_restart.log', "Released $released ngrok token(s) in DB for $extName (offline timeout)");

                                // Step 1: Disconnect
                                $stmtDis = $db->prepare("INSERT INTO colab_commands (worker_name, command, result_message) VALUES (?, 'disconnect', ?)");
                                $stmtDis->execute([$extName, 'AUTO: Disconnect trước khi restart']);
                                // Step 2: Schedule run_all after 5 minutes
                                $stmt2 = $db->prepare("INSERT INTO colab_commands (worker_name, command, result_message, scheduled_at) VALUES (?, 'run_all', ?, NOW() + INTERVAL 5 MINUTE)");
                                $stmt2->execute([$extName, 'AUTO: Restart sau offline (12h timeout)']);
                                logToFile('auto_restart.log', "AUTO-RESTART scheduled for $extName: token released + disconnect now + run_all in 5 min (restart #{$restartCountToday}/3 today)");
                            }
                        }
                    }
                } catch (Exception $eRestart) {
                    logToFile('auto_restart.log', "ERROR auto-restart for $workerLabel: " . $eRestart->getMessage());
                }
            } else {
                // Reset to 0 so it can be retried in next cron run
                $db->prepare("UPDATE workers SET alert_sent = 0 WHERE worker_uuid = ?")->execute([$uuid]);
            }
        }

        // Immediate dispatch for recovered jobs if any workers were flagged
        if ($totalOffline > 0) {
            include_once __DIR__ . '/dispatcher.php';
        }

        return $totalOffline;
    }
    return 0;
}

function notifyWorkerBlocked($uuid, $ip, $url, $reason, $workerName = '')
{
    $isIpBlock = strpos($reason, 'detected_unusual_activity') !== false;
    $isQuotaExceeded = strpos($reason, 'quota_exceeded') !== false;

    if ($isIpBlock) {
        $msg = "🚫 <b>CẢNH BÁO: IP BỊ CHẶN (IP BLOCKED)</b>\n\n";
    } elseif ($isQuotaExceeded) {
        $msg = "💰 <b>CẢNH BÁO: HẾT CREDIT (QUOTA EXCEEDED)</b>\n\n";
    } else {
        $msg = "⚠️ <b>CẢNH BÁO: LỖI API (401)</b>\n\n";
    }

    if ($workerName) {
        $msg .= "🏷️ <b>Máy chủ:</b> <b>{$workerName}</b>\n";
    }
    $msg .= "🆔 ID: <code>{$uuid}</code>\n";
    $msg .= "🌐 IP: <b>{$ip}</b>\n";
    $msg .= "🔗 URL: {$url}\n";
    $msg .= "❌ <b>Lý do:</b> <code>" . htmlspecialchars($reason) . "</code>\n\n";

    if ($isIpBlock) {
        $msg .= "<i>ElevenLabs đã chặn IP này. Hệ thống sẽ tự nhả token + restart sau 5 phút.</i>";
    } elseif ($isQuotaExceeded) {
        $msg .= "<i>Key hết credit. Hệ thống sẽ tự đổi key mới cho máy này.</i>";
    } else {
        $msg .= "<i>Lỗi xác thực API. Kiểm tra key hoặc restart máy.</i>";
    }

    return sendTelegramMessage($msg, false); // Gửi luôn không quan tâm nút gạt thông báo offline
}

function notifyWorkerCapacityExhausted($uuid, $ip, $url, $workerName = '')
{
    // Resolve worker name if not provided
    if (!$workerName) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT worker_name FROM workers WHERE worker_uuid = ? LIMIT 1");
            $stmt->execute([$uuid]);
            $workerName = $stmt->fetchColumn();
            if (!$workerName) {
                $stmtLabel = $db->prepare("SELECT worker_name FROM ngrok_keys WHERE worker_uuid = ? LIMIT 1");
                $stmtLabel->execute([$uuid]);
                $workerName = $stmtLabel->fetchColumn();
            }
        } catch (Exception $e) {}
    }
    if (!$workerName) $workerName = 'Worker-' . substr($uuid, 0, 8);

    $msg = "🔋 <b>CẢNH BÁO MÁY CHỦ HẾT HẠN MỨC KEY</b>\n\n";
    $msg .= "🏷️ <b>Tên máy:</b> <b>{$workerName}</b>\n";
    $msg .= "🆔 ID: <code>{$uuid}</code>\n";
    $msg .= "🌐 IP: <b>{$ip}</b>\n";
    $msg .= "🔗 URL: {$url}\n\n";
    $msg .= "⚠️ <b>Tình trạng:</b> Đã dùng đủ 12 Key bám dính nhưng tất cả đều không đủ điểm cho Job hiện tại.\n\n";
    $msg .= "🔄 <i>Hệ thống đang tự động restart máy chủ này để đổi IP mới...</i>";

    return sendTelegramMessage($msg, false); // Gửi luôn để anh biết mà đổi máy
}

function sendTelegramDocument($filePath, $caption = "")
{
    $config = getTelegramConfig();
    $token = $config['telegram_bot_token'] ?? '';
    $chatId = $config['telegram_chat_id'] ?? '';

    if (!$token || !$chatId || !file_exists($filePath)) {
        return false;
    }

    $url = "https://api.telegram.org/bot{$token}/sendDocument";

    // Use CURLFile for reliable file uploads
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $filePath);
    finfo_close($finfo);

    $cFile = new CURLFile($filePath, $mimeType, basename($filePath));

    $data = [
        'chat_id' => $chatId,
        'document' => $cFile,
        'caption' => $caption,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        logToFile('error_' . date('Y-m-d') . '.log', "Telegram sendDocument error: $err");
    }

    return $response !== false;
}

// === POST Handler: Allow workers to send Telegram alerts ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    if ($action === 'worker_alert') {
        $message = $input['message'] ?? '';
        if ($message) {
            sendTelegramMessage($message, false);
            jsonResponse(['status' => 'sent']);
        }
        jsonResponse(['error' => 'No message'], 400);
    }
}
?>