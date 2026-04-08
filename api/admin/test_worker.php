<?php
/**
 * Test Worker — Restart 1 máy + gửi job test thẳng tới máy đó
 * Các máy khác vẫn chạy bình thường, không bị ảnh hưởng.
 * 
 * /api/admin/test_worker.php → Danh sách máy + nút Restart / Test
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: text/html; charset=utf-8');

$db = getDB();
$action = $_GET['action'] ?? 'list';

// ═══════════════ LIST ═══════════════
if ($action === 'list') {
    $workers = $db->query("
        SELECT w.worker_uuid, w.worker_name, w.url, w.ip_address, w.last_seen,
               CASE WHEN w.last_seen > (NOW() - INTERVAL 300 SECOND) THEN 1 ELSE 0 END as is_online
        FROM workers w
        ORDER BY w.last_seen DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "<h2>🖥️ Workers</h2>";
    echo "<p style='color:#666'>Restart 1 máy để pull code mới → chờ online → gửi Test job thẳng tới máy đó.</p>";
    echo "<table border='1' cellpadding='6' style='border-collapse:collapse;font-family:monospace'>";
    echo "<tr style='background:#333;color:#fff'><th>Name</th><th>IP</th><th>Status</th><th>Last Seen</th><th>Actions</th></tr>";
    foreach ($workers as $w) {
        $status = $w['is_online'] ? '🟢 Online' : '🔴 Offline';
        $uuid = htmlspecialchars($w['worker_uuid']);
        $name = htmlspecialchars($w['worker_name']);
        echo "<tr>";
        echo "<td><b>{$name}</b></td>";
        echo "<td>{$w['ip_address']}</td>";
        echo "<td>{$status}</td>";
        echo "<td>{$w['last_seen']}</td>";
        echo "<td>";
        echo "<a href='?action=restart&name={$name}' onclick='return confirm(\"Restart {$name}?\")'>🔄 Restart</a>";
        echo " &nbsp;|&nbsp; <a href='?action=test_form&worker={$uuid}&name={$name}'>📝 Test Job</a>";
        echo "</td></tr>";
    }
    echo "</table>";
    exit;
}

// ═══════════════ RESTART ═══════════════
if ($action === 'restart') {
    $name = $_GET['name'] ?? '';
    if (!$name) { echo "❌ Missing name"; exit; }

    $db->prepare("INSERT INTO colab_commands (worker_name, command) VALUES (?, 'disconnect')")->execute([$name]);
    $db->prepare("INSERT INTO colab_commands (worker_name, command, scheduled_at) VALUES (?, 'run_all', NOW() + INTERVAL 3 MINUTE)")->execute([$name]);

    echo "<h2>🔄 Đã gửi lệnh Restart cho {$name}</h2>";
    echo "<p>Máy sẽ disconnect → pull code mới → khởi động lại sau ~3 phút.</p>";
    echo "<p>Chờ máy hiện 🟢 Online rồi bấm Test.</p>";
    echo "<p><a href='?action=list'>⬅️ Về danh sách</a> (F5 để refresh trạng thái)</p>";
    exit;
}

// ═══════════════ TEST FORM ═══════════════
if ($action === 'test_form') {
    $workerUuid = $_GET['worker'] ?? '';
    $workerName = $_GET['name'] ?? '';
    $testText = "The advancement of artificial intelligence has transformed numerous industries across the globe. From healthcare to finance, from education to entertainment, AI technologies are reshaping how we live and work. Machine learning algorithms now power recommendation systems that suggest what we watch, read, and buy. Natural language processing enables virtual assistants to understand and respond to human speech with remarkable accuracy. Computer vision systems can identify objects, faces, and even emotions in images and videos. The impact extends beyond consumer applications. In scientific research, AI accelerates drug discovery, climate modeling, and materials science. In manufacturing, intelligent robots work alongside humans to increase productivity and safety. Self-driving vehicles promise to revolutionize transportation, potentially reducing accidents caused by human error. However, these developments also raise important questions about privacy, employment, and the ethical use of technology that society must address.";

    echo "<h2>📝 Test Job → {$workerName}</h2>";
    echo "<p style='color:#666'>Job sẽ gửi <b>thẳng</b> tới máy này, không qua dispatcher. Các máy khác không bị ảnh hưởng.</p>";
    echo "<form method='POST' action='?action=send_test'>";

    echo "<input type='hidden' name='worker' value='{$workerUuid}'>";
    echo "<p>Voice: <input name='voice_id' value='7WNwm0yUcEo1Hsfg5Bhk' size='30'></p>";
    echo "<p>Model: <select name='model_id'>";
    echo "<option value='eleven_multilingual_v2' selected>Multilingual v2.5</option>";
    echo "<option value='eleven_v3'>V3</option>";
    echo "<option value='eleven_turbo_v2_5'>Turbo v2.5</option>";
    echo "<option value='eleven_flash_v2_5'>Flash v2.5</option>";
    echo "</select></p>";
    echo "<p>Text (US English, dài để test nối chunk):<br>";
    echo "<textarea name='text' rows='8' cols='80'>{$testText}</textarea></p>";
    echo "<button type='submit' style='padding:8px 20px;font-size:15px;background:#4CAF50;color:#fff;border:none;cursor:pointer'>🚀 Gửi</button>";
    echo "</form>";
    exit;
}

// ═══════════════ SEND TEST ═══════════════
if ($action === 'send_test') {
    $workerUuid = $_POST['worker'] ?? '';
    $text = $_POST['text'] ?? '';
    $voiceId = $_POST['voice_id'] ?? '7WNwm0yUcEo1Hsfg5Bhk';
    $modelId = $_POST['model_id'] ?? 'eleven_multilingual_v2';

    if (!$workerUuid || !$text) { echo "❌ Thiếu thông tin"; exit; }

    // Lấy URL worker
    $stmt = $db->prepare("SELECT url FROM workers WHERE worker_uuid = ?");
    $stmt->execute([$workerUuid]);
    $worker = $stmt->fetch();
    if (!$worker) { echo "❌ Worker không tìm thấy"; exit; }

    // Tạo job ID
    $jobId = 'TEST-' . substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 6);

    // Insert job record
    $db->prepare("INSERT INTO conversion_jobs (id, user_id, total_chunks, full_text, voice_id, model_id, status, worker_uuid) VALUES (?, 1, 1, ?, ?, ?, 'processing', ?)")
       ->execute([$jobId, $text, $voiceId, $modelId, $workerUuid]);

    // Lấy key cho worker này
    $stmtKeys = $db->prepare("SELECT * FROM api_keys WHERE status = 'active' AND assigned_worker_uuid = ? AND (cooldown_until IS NULL OR cooldown_until < NOW()) AND CAST(REPLACE(credits_remaining, '.', '') AS SIGNED) >= 1000 ORDER BY credits_remaining DESC LIMIT 5");
    $stmtKeys->execute([$workerUuid]);
    $keys = $stmtKeys->fetchAll(PDO::FETCH_ASSOC);

    if (empty($keys)) {
        $keys = $db->query("SELECT * FROM api_keys WHERE status = 'active' AND (cooldown_until IS NULL OR cooldown_until < NOW()) AND CAST(REPLACE(credits_remaining, '.', '') AS SIGNED) >= 1000 ORDER BY credits_remaining DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    }

    $tokens = [];
    foreach ($keys as $key) {
        $t = getEffectiveElevenLabsKey($key);
        if ($t) $tokens[] = ['id' => $key['id'], 'token' => $t];
    }
    if (empty($tokens)) { echo "❌ Không có key khả dụng"; exit; }

    // Gửi thẳng tới worker
    $ch = curl_init(rtrim($worker['url'], '/') . '/api/convert');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'text' => $text, 'api_keys' => $tokens, 'voice_id' => $voiceId,
            'model_id' => $modelId, 'job_id' => $jobId, 'php_backend' => PHP_BACKEND_URL
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $keyIds = array_map(fn($t) => $t['id'], $tokens);

    echo "<h2>🚀 Đã gửi Test Job</h2>";
    echo "<p><b>Job:</b> {$jobId} &nbsp; <b>Model:</b> {$modelId} &nbsp; <b>Chars:</b> " . mb_strlen($text) . "</p>";
    echo "<p><b>Response:</b> HTTP {$httpCode} — <code>" . htmlspecialchars($response) . "</code></p>";
    echo "<p style='color:#888'>⚡ <b>Không trừ quota user.</b> Chỉ tốn credits ElevenLabs từ key: " . implode(', ', $keyIds) . "</p>";
    echo "<hr>";

    // Auto-refresh progress
    $progressUrl = PHP_BACKEND_URL . "/api/progress.php?job_id={$jobId}";
    $audioUrl = PHP_BACKEND_URL . "/api/results/{$jobId}.mp3";
    echo <<<HTML
    <h3>📊 Tiến trình:</h3>
    <div id="progress" style="font-family:monospace;padding:10px;background:#f5f5f5;border:1px solid #ddd;min-height:60px">
        Loading...
    </div>
    <div id="audio-container" style="margin-top:15px;display:none">
        <h3>🎧 Audio:</h3>
        <audio id="audio-player" controls style="width:100%"></audio>
        <br><a href="{$audioUrl}" target="_blank" download>⬇️ Download MP3</a>
    </div>
    <p style="margin-top:15px"><a href="?action=list">⬅️ Về danh sách</a></p>

    <script>
    const jobId = '{$jobId}';
    const progressUrl = '{$progressUrl}';
    const audioUrl = '{$audioUrl}';
    let done = false;

    async function checkProgress() {
        if (done) return;
        try {
            const r = await fetch(progressUrl);
            const data = await r.json();
            const el = document.getElementById('progress');
            const job = data.job || {};

            let status = job.status || data.status || 'unknown';
            let chunks = job.processed_chunks || '0';
            let total = job.total_chunks || '?';
            let logs = data.logs || [];

            let html = '<b>Status:</b> ' + status + ' &nbsp; <b>Chunks:</b> ' + chunks + '/' + total + '<br><br>';
            if (logs.length) {
                html += '<b>Logs:</b><br>';
                logs.slice(-10).forEach(l => {
                    html += '• ' + l.message + ' <span style="color:#999">(' + l.time + ')</span><br>';
                });
            }
            el.innerHTML = html;

            if (status === 'completed' || status === 'done' || (job.result_url && job.result_url.length > 5)) {
                done = true;
                el.innerHTML += '<br><b style="color:green">✅ Hoàn thành!</b>';
                const ac = document.getElementById('audio-container');
                ac.style.display = 'block';
                document.getElementById('audio-player').src = job.result_url || audioUrl;
            } else if (status.startsWith('failed') || status.startsWith('Hủy')) {
                done = true;
                el.innerHTML += '<br><b style="color:red">❌ Thất bại: ' + status + '</b>';
            }
        } catch(e) {
            document.getElementById('progress').innerHTML = 'Lỗi fetch: ' + e.message;
        }
    }

    checkProgress();
    setInterval(checkProgress, 3000);
    </script>
HTML;
    exit;
}

echo "❌ Unknown action";
