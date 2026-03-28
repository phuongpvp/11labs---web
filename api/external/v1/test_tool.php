<?php
// Tệp này giúp anh Test thử API cho đại lý xem có chạy không trước khi gửi
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Tool Test Partner API</title>
    <style>
        body {
            font-family: sans-serif;
            max-width: 800px;
            margin: 20px auto;
            padding: 20px;
            background: #f4f4f9;
        }

        .card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        input,
        textarea,
        select {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }

        button {
            background: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        button:hover {
            background: #45a049;
        }

        pre {
            background: #272822;
            color: #f8f8f2;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 13px;
        }

        .status {
            font-weight: bold;
        }
    </style>
</head>

<body>
    <h1>🛠️ Partner API Tester</h1>

    <div class="card">
        <h3>1. Cấu hình</h3>
        <label>Partner API Key (Lấy từ Admin):</label>
        <input type="text" id="apiKey" placeholder="PL_...">
    </div>

    <div class="card">
        <h3>2. Bước 1: Kiểm tra thông tin & Số dư</h3>
        <button onclick="testUser()">Kiểm tra Số dư (Quota)</button>
        <div id="userResult"></div>
    </div>

    <div class="card">
        <h3>3. Bước 2: Danh sách Voice</h3>
        <button onclick="testVoices()">Lấy danh sách Voice</button>
        <div id="voicesResult"></div>
    </div>

    <div class="card">
        <h3>3. Bước 2: Tạo thẻ Job (TTS)</h3>
        <label>Văn bản cần đọc:</label>
        <textarea id="ttsText" rows="3">Chào mừng bạn đến với hệ thống ElevenLabs của tôi!</textarea>

        <div style="display: flex; gap: 15px;">
            <div style="flex: 1;">
                <label>Voice ID (Lấy từ Bước 1, ví dụ: 21m00...):</label>
                <input type="text" id="voiceId" value="21m00Tcm4TlvDq8ikWAM">
            </div>
            <div style="flex: 1;">
                <label>Model ID:</label>
                <select id="modelId">
                    <option value="eleven_multilingual_v2">Multilingual v2 (Khuyên dùng - Đọc tiếng Việt tốt nhất)
                    </option>
                    <option value="eleven_turbo_v2_5">Turbo v2.5 (Nhanh nhất)</option>
                    <option value="eleven_flash_v2_5">Flash v2.5 (Rẻ nhất/Nhanh nhất)</option>
                    <option value="eleven_monolingual_v1">Monolingual v1 (Chỉ tiếng Anh)</option>
                </select>
            </div>
        </div>

        <button onclick="testTTS()">Tạo Job TTS</button>
        <div id="ttsResult"></div>
    </div>

    <div class="card">
        <h3>4. Bước 3: Kiểm tra trạng thái Job</h3>
        <label>Job ID:</label>
        <input type="text" id="jobId" placeholder="Mã Job nhận được ở Bước 2">
        <button onclick="testStatus()">Xem trạng thái</button>
        <div id="statusResult"></div>
    </div>

    <script>
        async function testUser() {
            const key = document.getElementById('apiKey').value;
            if (!key) return alert('Vui lòng nhập API Key');
            try {
                const res = await fetch(`user.php?api_key=${key}`);
                const data = await res.json();
                document.getElementById('userResult').innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
            } catch (e) {
                document.getElementById('userResult').innerHTML = '<pre>Lỗi: ' + e.message + '</pre>';
            }
        }

        async function testVoices() {
            const key = document.getElementById('apiKey').value;
            if (!key) return alert('Vui lòng nhập API Key');
            try {
                const res = await fetch(`voices.php?api_key=${key}`);
                const data = await res.json();
                document.getElementById('voicesResult').innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
            } catch (e) {
                document.getElementById('voicesResult').innerHTML = '<pre>Lỗi: ' + e.message + '</pre>';
            }
        }

        async function testTTS() {
            const key = document.getElementById('apiKey').value;
            const text = document.getElementById('ttsText').value;
            const vId = document.getElementById('voiceId').value;
            const mId = document.getElementById('modelId').value;

            if (!key) return alert('Vui lòng nhập API Key');

            try {
                const res = await fetch(`tts.php?api_key=${key}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        text,
                        voice_id: vId,
                        model_id: mId
                    })
                });
                const data = await res.json();
                document.getElementById('ttsResult').innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';

                if (data.job_id) {
                    document.getElementById('jobId').value = data.job_id;
                }
            } catch (e) {
                document.getElementById('ttsResult').innerHTML = '<pre>Lỗi: ' + e.message + '</pre>';
            }
        }

        async function testStatus() {
            const key = document.getElementById('apiKey').value;
            const jId = document.getElementById('jobId').value;
            if (!key || !jId) return alert('Vui lòng nhập API Key và Job ID');

            try {
                const res = await fetch(`status.php?api_key=${key}&job_id=${jId}`);
                const data = await res.json();
                document.getElementById('statusResult').innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';

                if (data.download_url) {
                    document.getElementById('statusResult').innerHTML += `<p>✅ <b>Hoàn thành!</b> <a href="${data.download_url}" target="_blank">Bấm vào đây để tải file</a></p>`;
                }
            } catch (e) {
                document.getElementById('statusResult').innerHTML = '<pre>Lỗi: ' + e.message + '</pre>';
            }
        }
    </script>
</body>

</html>