<?php
/**
 * TEST V3 VOICE CONSISTENCY
 * Gửi 1 job V3 tiếng Việt dài đến 1 worker cụ thể để test giọng có đổi giữa chừng không.
 * 
 * Cách dùng:
 *   https://11labs.id.vn/api/test_v3.php?secret=YOUR_SECRET
 *   https://11labs.id.vn/api/test_v3.php?secret=YOUR_SECRET&worker=sv18
 *   https://11labs.id.vn/api/test_v3.php?secret=YOUR_SECRET&worker=sv18&voice_id=VOICE_ID
 */

require_once __DIR__ . '/config.php';

// Auth
if (!isset($_GET['secret']) || !verifyWorkerSecret($_GET['secret'])) {
    http_response_code(403);
    die("Access Denied");
}

header('Content-Type: text/html; charset=utf-8');
echo "<pre style='font-family:monospace; background:#111; color:#0f0; padding:20px; font-size:14px;'>";
echo "🧪 TEST V3 VOICE CONSISTENCY\n";
echo str_repeat("─", 60) . "\n\n";

$db = getDB();

// ── 1. Tìm worker ──
$workerFilter = $_GET['worker'] ?? '';
if ($workerFilter) {
    $stmt = $db->prepare("SELECT url, worker_uuid, worker_name FROM workers WHERE status = 'active' AND last_seen > (NOW() - INTERVAL 180 SECOND) AND (worker_name LIKE ? OR worker_uuid LIKE ?) LIMIT 1");
    $stmt->execute(["%$workerFilter%", "%$workerFilter%"]);
} else {
    $stmt = $db->prepare("SELECT url, worker_uuid, worker_name FROM workers WHERE status = 'active' AND last_seen > (NOW() - INTERVAL 180 SECOND) ORDER BY last_assigned ASC LIMIT 1");
    $stmt->execute();
}
$worker = $stmt->fetch();

if (!$worker) {
    die("❌ Không tìm thấy worker" . ($workerFilter ? " '$workerFilter'" : "") . " đang hoạt động.\n\nDanh sách worker:\n" . print_r($db->query("SELECT worker_name, worker_uuid, status, last_seen FROM workers ORDER BY last_seen DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC), true));
}

$workerUrl = rtrim($worker['url'], '/');
$workerName = $worker['worker_name'] ?? $worker['worker_uuid'];
echo "✅ Worker: {$workerName}\n";
echo "   URL: {$workerUrl}\n\n";

// ── 2. Lấy 1 key có credit ──
$stmtKey = $db->prepare("SELECT * FROM api_keys WHERE status = 'active' AND assigned_worker_uuid = ? AND CAST(REPLACE(credits_remaining, '.', '') AS SIGNED) >= 5000 ORDER BY credits_remaining DESC LIMIT 1");
$stmtKey->execute([$worker['worker_uuid']]);
$key = $stmtKey->fetch();

if (!$key) {
    // Fallback: lấy key bất kỳ có credit
    $stmtKey = $db->prepare("SELECT * FROM api_keys WHERE status = 'active' AND CAST(REPLACE(credits_remaining, '.', '') AS SIGNED) >= 5000 ORDER BY credits_remaining DESC LIMIT 1");
    $stmtKey->execute();
    $key = $stmtKey->fetch();
}

if (!$key) {
    die("❌ Không tìm thấy key nào có đủ credit (≥5000).\n");
}

$elToken = getEffectiveElevenLabsKey($key);
if (!$elToken) {
    die("❌ Không thể lấy token cho key #{$key['id']}.\n");
}
echo "✅ Key: #{$key['id']} (credits: {$key['credits_remaining']})\n\n";

// ── 3. Voice ID ──
$voiceId = $_GET['voice_id'] ?? 'nPczCjzI2devNBz1zQrb';  // Brian (default multilingual)
echo "🎤 Voice ID: {$voiceId}\n";

// ── 4. Text test - đoạn tiếng Việt dài (~4500 chars) để buộc chia thành nhiều chunk ──
$testText = $_GET['text'] ?? "Xin chào tất cả các bạn, hôm nay tôi rất vui được chia sẻ với các bạn về một chủ đề vô cùng thú vị, đó chính là công nghệ trí tuệ nhân tạo và những ứng dụng tuyệt vời của nó trong cuộc sống hàng ngày. Trí tuệ nhân tạo, hay còn gọi là AI, đang dần thay đổi mọi khía cạnh trong cuộc sống của chúng ta, từ cách chúng ta làm việc cho đến cách chúng ta giải trí và giao tiếp với nhau.

Trong lĩnh vực y tế, AI đã và đang được ứng dụng rộng rãi trong việc chẩn đoán bệnh, phân tích hình ảnh y khoa, và thậm chí là dự đoán dịch bệnh. Các bác sĩ giờ đây có thể sử dụng AI để hỗ trợ trong quá trình khám và điều trị bệnh nhân, giúp tăng độ chính xác và giảm thời gian chẩn đoán. Điều này đặc biệt quan trọng ở những vùng nông thôn, nơi mà nguồn lực y tế còn hạn chế.

Về phía giáo dục, AI cũng đang mở ra những cánh cửa hoàn toàn mới. Các nền tảng học trực tuyến sử dụng AI để cá nhân hóa trải nghiệm học tập cho từng học sinh, giúp các em có thể học theo tốc độ và phong cách riêng của mình. Giáo viên cũng được hỗ trợ trong việc đánh giá bài làm và theo dõi tiến độ học tập của học sinh một cách hiệu quả hơn.

Trong lĩnh vực giao thông, xe tự lái và hệ thống giao thông thông minh đang dần trở thành hiện thực. Những chiếc xe được trang bị AI có thể tự di chuyển trên đường phố, tuân thủ luật giao thông và tránh va chạm với các phương tiện khác. Điều này hứa hẹn sẽ giảm đáng kể tai nạn giao thông và giúp việc di chuyển trở nên thuận tiện hơn bao giờ hết.

Và cuối cùng, AI cũng đang thay đổi cách chúng ta sáng tạo nội dung. Từ việc viết nhạc, vẽ tranh cho đến sản xuất video, AI có thể hỗ trợ và thậm chí là tạo ra những tác phẩm nghệ thuật độc đáo. Công nghệ chuyển đổi văn bản thành giọng nói như ElevenLabs chính là một ví dụ điển hình, giúp mọi người có thể tạo ra các nội dung âm thanh chất lượng cao một cách dễ dàng và nhanh chóng. Tôi tin rằng trong tương lai không xa, AI sẽ trở thành người bạn đồng hành không thể thiếu trong cuộc sống của mỗi chúng ta. Cảm ơn các bạn đã lắng nghe.";

$textLen = mb_strlen($testText, 'UTF-8');
echo "📝 Text length: {$textLen} chars\n";
echo "📐 Chunk size sẽ là: 1500 (tiếng Việt → non-Latin)\n";
echo "📦 Ước tính: " . ceil($textLen / 1500) . " chunks\n\n";

// ── 5. Tạo job test ──
$jobId = 'test_v3_' . time();
$modelId = $_GET['model'] ?? 'eleven_v3';

// Insert job vào DB
$stmt = $db->prepare("INSERT INTO conversion_jobs (id, user_id, full_text, voice_id, model_id, status, job_type, created_at, updated_at) VALUES (?, 1, ?, ?, ?, 'processing', 'tts', NOW(), NOW())");
$stmt->execute([$jobId, $testText, $voiceId, $modelId]);

echo "🆔 Job ID: {$jobId}\n";
echo "🤖 Model: {$modelId}\n";
echo str_repeat("─", 60) . "\n";
echo "📤 Đang gửi đến worker...\n\n";
flush();

// ── 6. Gửi đến worker ──
$workerPayload = [
    'text' => $testText,
    'api_keys' => [['id' => $key['id'], 'token' => $elToken]],
    'voice_id' => $voiceId,
    'model_id' => $modelId,
    'job_type' => 'tts',
    'job_id' => $jobId,
    'voice_settings' => null,
    'php_backend' => PHP_BACKEND_URL,
];

$payload = json_encode($workerPayload);

$ch = curl_init($workerUrl . '/api/convert');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "📥 Worker response (HTTP {$httpCode}):\n";
$respData = json_decode($response, true);
echo json_encode($respData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

if ($httpCode === 200 && isset($respData['status'])) {
    echo str_repeat("─", 60) . "\n";
    echo "✅ Job đã gửi thành công!\n\n";
    echo "📊 Theo dõi tiến trình:\n";
    echo "   → Admin panel: https://11labs.id.vn/concucac.php\n";
    echo "   → Logs worker: Xem trực tiếp trên Colab {$workerName}\n\n";
    echo "🔍 Khi hoàn thành, kiểm tra:\n";
    echo "   1. Nghe file audio xem giọng có đổi giữa chừng không\n";
    echo "   2. Check logs worker: seed phải giống nhau ở mọi chunk\n";
    echo "   3. So sánh với version cũ (không có seed)\n";
} else {
    echo "❌ Lỗi gửi job đến worker!\n";
    // Cleanup
    $db->prepare("DELETE FROM conversion_jobs WHERE id = ?")->execute([$jobId]);
    echo "🧹 Đã xóa job test khỏi DB.\n";
}

echo "\n" . str_repeat("─", 60) . "\n";
echo "⏱️ " . date('Y-m-d H:i:s') . "\n";
echo "</pre>";
