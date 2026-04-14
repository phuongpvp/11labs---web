<?php
require_once __DIR__ . '/config.php';

$db = getDB();

// ── API: Gửi job ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true);
    
    $workerUuid = $input['worker_uuid'] ?? '';
    $voiceId = $input['voice_id'] ?? '';
    $text = $input['text'] ?? '';
    $modelId = $input['model_id'] ?? 'eleven_v3';
    
    if (!$workerUuid || !$voiceId || !$text) {
        echo json_encode(['error' => 'Thiếu thông tin']);
        exit;
    }
    
    // Tìm worker
    $stmt = $db->prepare("SELECT url, worker_uuid, worker_name FROM workers WHERE worker_uuid = ? AND status = 'active' AND last_seen > (NOW() - INTERVAL 180 SECOND)");
    $stmt->execute([$workerUuid]);
    $worker = $stmt->fetch();
    if (!$worker) {
        echo json_encode(['error' => 'Worker không hoạt động hoặc không tìm thấy']);
        exit;
    }
    
    // Tìm key
    $stmtKey = $db->prepare("SELECT * FROM api_keys WHERE status = 'active' AND assigned_worker_uuid = ? AND CAST(REPLACE(credits_remaining, '.', '') AS SIGNED) >= 5000 ORDER BY credits_remaining DESC LIMIT 3");
    $stmtKey->execute([$workerUuid]);
    $keys = $stmtKey->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($keys)) {
        $stmtKey = $db->prepare("SELECT * FROM api_keys WHERE status = 'active' AND CAST(REPLACE(credits_remaining, '.', '') AS SIGNED) >= 5000 ORDER BY credits_remaining DESC LIMIT 3");
        $stmtKey->execute();
        $keys = $stmtKey->fetchAll(PDO::FETCH_ASSOC);
    }
    
    if (empty($keys)) {
        echo json_encode(['error' => 'Không tìm thấy key nào có đủ credit (≥5000)']);
        exit;
    }
    
    $tokens = [];
    foreach ($keys as $key) {
        $elToken = getEffectiveElevenLabsKey($key);
        if ($elToken) {
            $tokens[] = ['id' => $key['id'], 'token' => $elToken];
        }
    }
    
    if (empty($tokens)) {
        echo json_encode(['error' => 'Không thể lấy token cho các key']);
        exit;
    }
    
    $jobId = 'test_v3_' . time() . '_' . rand(100, 999);
    
    $stmt = $db->prepare("INSERT INTO conversion_jobs (id, user_id, full_text, voice_id, model_id, status, job_type, created_at, updated_at) VALUES (?, 1, ?, ?, ?, 'processing', 'tts', NOW(), NOW())");
    $stmt->execute([$jobId, $text, $voiceId, $modelId]);
    
    $workerUrl = rtrim($worker['url'], '/');
    $workerPayload = [
        'text' => $text,
        'api_keys' => $tokens,
        'voice_id' => $voiceId,
        'model_id' => $modelId,
        'job_type' => 'tts',
        'job_id' => $jobId,
        'voice_settings' => [
            'stability' => isset($input['stability']) ? floatval($input['stability']) / 100 : 0.5
        ],
        'php_backend' => PHP_BACKEND_URL,
    ];
    
    $ch = curl_init($workerUrl . '/api/convert');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($workerPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $respData = json_decode($response, true);
    
    if ($httpCode === 200 && isset($respData['status'])) {
        echo json_encode([
            'success' => true,
            'job_id' => $jobId,
            'worker' => $worker['worker_name'] ?? $workerUuid,
            'total_chunks' => $respData['total_chunks'] ?? '?',
            'keys_used' => count($tokens),
            'text_length' => mb_strlen($text, 'UTF-8')
        ]);
    } else {
        $db->prepare("DELETE FROM conversion_jobs WHERE id = ?")->execute([$jobId]);
        echo json_encode(['error' => 'Worker trả lỗi: ' . ($respData['error'] ?? "HTTP $httpCode")]);
    }
    exit;
}

// ── API: Check job status ──
if (isset($_GET['check_job'])) {
    header('Content-Type: application/json; charset=utf-8');
    $jobId = $_GET['check_job'];
    $stmt = $db->prepare("SELECT id, status, processed_chunks, total_chunks, result_url, created_at, updated_at FROM conversion_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($job ?: ['error' => 'Job not found']);
    exit;
}

// ── Lấy danh sách workers ──
$workers = $db->query("SELECT worker_uuid, worker_name, url, status, last_seen, ip_address,
    (SELECT COUNT(*) FROM conversion_jobs j WHERE j.worker_uuid = w.worker_uuid AND j.status = 'processing') as active_jobs
    FROM workers w WHERE last_seen > (NOW() - INTERVAL 600 SECOND) ORDER BY worker_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Test V3 Voice</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #0a0a0a; color: #e0e0e0; font-family: 'Segoe UI', system-ui, sans-serif; padding: 20px; }
h1 { font-size: 20px; color: #00ff88; margin-bottom: 16px; }
.section { background: #151515; border: 1px solid #252525; border-radius: 10px; padding: 16px; margin-bottom: 16px; }
.section h2 { font-size: 14px; color: #888; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px; }

/* Worker table */
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; color: #666; font-weight: 500; padding: 6px 10px; border-bottom: 1px solid #252525; }
td { padding: 8px 10px; border-bottom: 1px solid #1a1a1a; cursor: pointer; transition: background 0.15s; }
tr.worker-row:hover { background: #1a2a1a; }
tr.worker-row.selected { background: #0a2a0a; outline: 1px solid #00ff88; }
tr.worker-row.offline td { color: #555; }
.status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
.status-dot.online { background: #00ff88; box-shadow: 0 0 6px #00ff8855; }
.status-dot.offline { background: #555; }
.status-dot.busy { background: #ffaa00; box-shadow: 0 0 6px #ffaa0055; }
.ago { color: #666; font-size: 11px; }

/* Form */
.form-row { margin-bottom: 12px; }
label { display: block; font-size: 12px; color: #888; margin-bottom: 4px; }
input, textarea, select { width: 100%; background: #0a0a0a; border: 1px solid #333; border-radius: 6px; padding: 10px 12px; color: #e0e0e0; font-size: 14px; font-family: inherit; }
input:focus, textarea:focus, select:focus { outline: none; border-color: #00ff88; }
textarea { min-height: 120px; resize: vertical; }
.row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

/* Button */
.btn { background: #00ff88; color: #000; border: none; border-radius: 8px; padding: 12px 24px; font-size: 15px; font-weight: 600; cursor: pointer; width: 100%; transition: all 0.2s; }
.btn:hover { background: #00cc6a; transform: translateY(-1px); }
.btn:disabled { background: #333; color: #666; cursor: not-allowed; transform: none; }
.btn.loading { position: relative; color: transparent; }
.btn.loading::after { content: ''; position: absolute; left: 50%; top: 50%; width: 18px; height: 18px; margin: -9px 0 0 -9px; border: 2px solid #666; border-top-color: #000; border-radius: 50%; animation: spin 0.6s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Result */
.result { display: none; margin-top: 12px; padding: 14px; border-radius: 8px; font-size: 13px; line-height: 1.6; }
.result.success { background: #0a2a0a; border: 1px solid #00ff8844; color: #00ff88; }
.result.error { background: #2a0a0a; border: 1px solid #ff444444; color: #ff6666; }
.result.tracking { background: #1a1a0a; border: 1px solid #ffaa0044; color: #ffcc00; }

/* Audio player */
.audio-container { margin-top: 10px; }
.audio-container audio { width: 100%; margin-top: 6px; }

.selected-info { background: #0a2a0a; border: 1px solid #00ff8833; padding: 8px 12px; border-radius: 6px; font-size: 12px; color: #00ff88; margin-bottom: 12px; display: none; }
</style>
</head>
<body>
<h1>🧪 Test V3 Voice Consistency</h1>

<div class="section">
    <h2>1. Chọn máy chủ</h2>
    <table>
        <tr><th></th><th>Tên</th><th>IP</th><th>Jobs</th><th>Last Seen</th></tr>
        <?php foreach ($workers as $w):
            $lastSeen = strtotime($w['last_seen']);
            $ago = time() - $lastSeen;
            $isOnline = $ago < 180;
            $isBusy = $w['active_jobs'] > 0;
            $statusClass = $isOnline ? ($isBusy ? 'busy' : 'online') : 'offline';
            $agoText = $ago < 60 ? "{$ago}s" : floor($ago/60)."m";
        ?>
        <tr class="worker-row <?= $isOnline ? '' : 'offline' ?>" 
            data-uuid="<?= htmlspecialchars($w['worker_uuid']) ?>"
            data-name="<?= htmlspecialchars($w['worker_name'] ?? '?') ?>"
            onclick="selectWorker(this)">
            <td><span class="status-dot <?= $statusClass ?>"></span></td>
            <td><strong><?= htmlspecialchars($w['worker_name'] ?? $w['worker_uuid']) ?></strong></td>
            <td><?= htmlspecialchars($w['ip_address'] ?? '—') ?></td>
            <td><?= $isBusy ? "🔄 {$w['active_jobs']}" : "✅ Rảnh" ?></td>
            <td><span class="ago"><?= $agoText ?> ago</span></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($workers)): ?>
        <tr><td colspan="5" style="color:#666;text-align:center;padding:20px;">Không có worker nào hoạt động</td></tr>
        <?php endif; ?>
    </table>
</div>

<div class="section">
    <h2>2. Cấu hình</h2>
    <div class="selected-info" id="selectedInfo"></div>
    <div class="row-2">
        <div class="form-row">
            <label>Voice ID</label>
            <input type="text" id="voiceId" value="nPczCjzI2devNBz1zQrb" placeholder="VD: nPczCjzI2devNBz1zQrb">
        </div>
        <div class="form-row">
            <label>Model</label>
            <select id="modelId">
                <option value="eleven_v3" selected>Eleven V3</option>
                <option value="eleven_multilingual_v2">Multilingual V2</option>
                <option value="eleven_flash_v2_5">Flash V2.5</option>
                <option value="eleven_turbo_v2_5">Turbo V2.5</option>
            </select>
        </div>
    </div>
    <div class="form-row">
        <label>ĐỘ ỔN ĐỊNH <span id="stabilityValue" style="float:right;color:#aa88ff;font-weight:600;">50%</span></label>
        <input type="range" id="stability" min="0" max="100" value="50" style="accent-color:#aa88ff;cursor:pointer;" oninput="document.getElementById('stabilityValue').textContent=this.value+'%'">
        <div style="display:flex;justify-content:space-between;font-size:11px;color:#666;margin-top:2px;"><span>Mạnh mẽ</span><span>Ổn định hơn</span></div>
    </div>
    <div class="form-row">
        <label>Nội dung test (tiếng Việt dài để chia nhiều chunk)</label>
        <textarea id="textContent">Xin chào tất cả các bạn, hôm nay tôi rất vui được chia sẻ với các bạn về một chủ đề vô cùng thú vị, đó chính là công nghệ trí tuệ nhân tạo và những ứng dụng tuyệt vời của nó trong cuộc sống hàng ngày. Trí tuệ nhân tạo, hay còn gọi là AI, đang dần thay đổi mọi khía cạnh trong cuộc sống của chúng ta, từ cách chúng ta làm việc cho đến cách chúng ta giải trí và giao tiếp với nhau.

Trong lĩnh vực y tế, AI đã và đang được ứng dụng rộng rãi trong việc chẩn đoán bệnh, phân tích hình ảnh y khoa, và thậm chí là dự đoán dịch bệnh. Các bác sĩ giờ đây có thể sử dụng AI để hỗ trợ trong quá trình khám và điều trị bệnh nhân, giúp tăng độ chính xác và giảm thời gian chẩn đoán. Điều này đặc biệt quan trọng ở những vùng nông thôn, nơi mà nguồn lực y tế còn hạn chế.

Về phía giáo dục, AI cũng đang mở ra những cánh cửa hoàn toàn mới. Các nền tảng học trực tuyến sử dụng AI để cá nhân hóa trải nghiệm học tập cho từng học sinh, giúp các em có thể học theo tốc độ và phong cách riêng của mình. Giáo viên cũng được hỗ trợ trong việc đánh giá bài làm và theo dõi tiến độ học tập của học sinh một cách hiệu quả hơn.

Trong lĩnh vực giao thông, xe tự lái và hệ thống giao thông thông minh đang dần trở thành hiện thực. Những chiếc xe được trang bị AI có thể tự di chuyển trên đường phố, tuân thủ luật giao thông và tránh va chạm với các phương tiện khác. Điều này hứa hẹn sẽ giảm đáng kể tai nạn giao thông và giúp việc di chuyển trở nên thuận tiện hơn bao giờ hết.

Và cuối cùng, AI cũng đang thay đổi cách chúng ta sáng tạo nội dung. Từ việc viết nhạc, vẽ tranh cho đến sản xuất video, AI có thể hỗ trợ và thậm chí là tạo ra những tác phẩm nghệ thuật độc đáo. Công nghệ chuyển đổi văn bản thành giọng nói như ElevenLabs chính là một ví dụ điển hình, giúp mọi người có thể tạo ra các nội dung âm thanh chất lượng cao một cách dễ dàng và nhanh chóng. Tôi tin rằng trong tương lai không xa, AI sẽ trở thành người bạn đồng hành không thể thiếu trong cuộc sống của mỗi chúng ta. Cảm ơn các bạn đã lắng nghe.</textarea>
    </div>
    <div style="font-size:12px;color:#666;margin-bottom:12px;" id="charCount">0 chars • ước tính 0 chunks (1500/chunk)</div>
    <button class="btn" id="btnGenerate" onclick="generate()" disabled>Chọn máy chủ trước</button>
</div>

<div class="section" id="resultSection" style="display:none;">
    <h2>3. Kết quả</h2>
    <div class="result" id="result"></div>
    <div class="audio-container" id="audioContainer" style="display:none;">
        <label>🎧 Nghe kết quả:</label>
        <audio controls id="audioPlayer"></audio>
    </div>
</div>

<script>
let selectedWorkerUuid = null;
let trackingInterval = null;

function selectWorker(row) {
    document.querySelectorAll('.worker-row').forEach(r => r.classList.remove('selected'));
    row.classList.add('selected');
    selectedWorkerUuid = row.dataset.uuid;
    const name = row.dataset.name;
    
    document.getElementById('selectedInfo').style.display = 'block';
    document.getElementById('selectedInfo').textContent = '✅ Đã chọn: ' + name;
    
    const btn = document.getElementById('btnGenerate');
    btn.disabled = false;
    btn.textContent = '🚀 Generate trên ' + name;
}

function updateCharCount() {
    const text = document.getElementById('textContent').value;
    const len = text.length;
    const chunks = Math.ceil(len / 1500);
    document.getElementById('charCount').textContent = len + ' chars • ước tính ' + chunks + ' chunks (1500/chunk)';
}
document.getElementById('textContent').addEventListener('input', updateCharCount);
updateCharCount();

async function generate() {
    if (!selectedWorkerUuid) return;
    
    const btn = document.getElementById('btnGenerate');
    btn.disabled = true;
    btn.classList.add('loading');
    btn.textContent = '';
    
    const resultDiv = document.getElementById('result');
    const resultSection = document.getElementById('resultSection');
    const audioContainer = document.getElementById('audioContainer');
    resultSection.style.display = 'block';
    audioContainer.style.display = 'none';
    resultDiv.style.display = 'block';
    resultDiv.className = 'result tracking';
    resultDiv.textContent = '⏳ Đang gửi đến worker...';
    
    try {
        const resp = await fetch('test_v3.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                worker_uuid: selectedWorkerUuid,
                voice_id: document.getElementById('voiceId').value.trim(),
                text: document.getElementById('textContent').value,
                model_id: document.getElementById('modelId').value,
                stability: parseInt(document.getElementById('stability').value)
            })
        });
        const data = await resp.json();
        
        if (data.error) {
            resultDiv.className = 'result error';
            resultDiv.textContent = '❌ ' + data.error;
            btn.disabled = false;
            btn.classList.remove('loading');
            btn.textContent = '🚀 Thử lại';
            return;
        }
        
        resultDiv.className = 'result success';
        resultDiv.innerHTML = '✅ Job gửi thành công!<br>' +
            '📦 Job ID: <strong>' + data.job_id + '</strong><br>' +
            '🖥️ Worker: ' + data.worker + '<br>' +
            '📐 Chunks: ' + data.total_chunks + '<br>' +
            '🔑 Keys: ' + data.keys_used + '<br>' +
            '📝 Text: ' + data.text_length + ' chars';
        
        // Track job progress
        trackJob(data.job_id);
        
    } catch (e) {
        resultDiv.className = 'result error';
        resultDiv.textContent = '❌ Lỗi: ' + e.message;
        btn.disabled = false;
        btn.classList.remove('loading');
        btn.textContent = '🚀 Thử lại';
    }
}

function trackJob(jobId) {
    const resultDiv = document.getElementById('result');
    const btn = document.getElementById('btnGenerate');
    
    if (trackingInterval) clearInterval(trackingInterval);
    
    let pollCount = 0;
    trackingInterval = setInterval(async () => {
        pollCount++;
        try {
            const resp = await fetch('test_v3.php?check_job=' + jobId);
            const job = await resp.json();
            
            if (!job || job.error) {
                clearInterval(trackingInterval);
                resultDiv.className = 'result error';
                resultDiv.textContent = '❌ Job không tìm thấy';
                resetBtn(btn);
                return;
            }
            
            const status = job.status || '';
            const progress = (job.processed_chunks || 0) + '/' + (job.total_chunks || '?');
            
            if (status === 'completed' || status === 'done') {
                clearInterval(trackingInterval);
                resultDiv.className = 'result success';
                resultDiv.innerHTML = '✅ Hoàn thành!<br>' +
                    '📦 Job: <strong>' + jobId + '</strong><br>' +
                    '📐 Chunks: ' + progress + '<br>' +
                    '⏱️ ' + job.updated_at;
                
                if (job.result_url) {
                    const audioContainer = document.getElementById('audioContainer');
                    const audioPlayer = document.getElementById('audioPlayer');
                    let audioUrl = job.result_url;
                    if (!audioUrl.startsWith('http')) {
                        audioUrl = '<?= PHP_BACKEND_URL ?>/api/results/' + audioUrl;
                    }
                    audioPlayer.src = audioUrl;
                    audioContainer.style.display = 'block';
                }
                resetBtn(btn);
                
            } else if (status.startsWith('failed')) {
                clearInterval(trackingInterval);
                resultDiv.className = 'result error';
                resultDiv.innerHTML = '❌ Thất bại<br>' + status + '<br>Job: ' + jobId;
                resetBtn(btn);
                
            } else {
                resultDiv.className = 'result tracking';
                resultDiv.innerHTML = '⏳ Đang xử lý... (' + progress + ' chunks)<br>' +
                    '📦 Job: ' + jobId + '<br>' +
                    '🔄 Poll #' + pollCount;
            }
            
        } catch (e) {
            // ignore poll errors
        }
        
        if (pollCount > 120) {
            clearInterval(trackingInterval);
            resultDiv.className = 'result error';
            resultDiv.textContent = '⏰ Timeout tracking (10 phút)';
            resetBtn(btn);
        }
    }, 5000);
}

function resetBtn(btn) {
    btn.disabled = false;
    btn.classList.remove('loading');
    btn.textContent = '🚀 Generate trên ' + (document.querySelector('.worker-row.selected')?.dataset.name || 'worker');
}
</script>
</body>
</html>
