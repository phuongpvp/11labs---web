<?php
session_start();
require_once __DIR__ . '/api/config.php';

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: concucac.php');
    exit;
}

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    if (verifyAdminPassword($_POST['admin_password'])) {
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_login_time'] = time();
        header('Location: concucac.php');
        exit;
    } else {
        $loginError = 'Sai mật khẩu!';
    }
}

// Session expires after 24 hours
if (isset($_SESSION['admin_login_time']) && (time() - $_SESSION['admin_login_time']) > 86400) {
    session_destroy();
    header('Location: concucac.php');
    exit;
}

// If not authenticated, show login form
if (!isset($_SESSION['admin_authenticated']) || $_SESSION['admin_authenticated'] !== true) {
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Outfit', sans-serif; background: #09090b; color: #f4f4f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .login-box { background: #18181b; border: 1px solid #27272a; border-radius: 16px; padding: 40px; width: 100%; max-width: 400px; text-align: center; }
        .login-box h2 { margin-bottom: 8px; font-size: 1.5rem; }
        .login-box p { color: #a1a1aa; font-size: 0.85rem; margin-bottom: 24px; }
        .login-box input { width: 100%; padding: 12px 16px; background: #09090b; border: 1px solid #27272a; border-radius: 8px; color: white; font-size: 1rem; margin-bottom: 16px; outline: none; }
        .login-box input:focus { border-color: #8b5cf6; }
        .login-box button { width: 100%; padding: 12px; background: #8b5cf6; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: 0.2s; }
        .login-box button:hover { background: #7c3aed; }
        .error { color: #ef4444; font-size: 0.85rem; margin-bottom: 12px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>🔒 Admin Panel</h2>
        <p>Nhập mật khẩu quản trị để tiếp tục</p>
        <?php if (isset($loginError)): ?>
            <div class="error"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="password" name="admin_password" placeholder="Mật khẩu admin" autofocus required>
            <button type="submit">Đăng nhập</button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
}
// === Admin authenticated — render admin panel below ===
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="icon.png">
    <title>Admin Panel - Voice Rental System</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #8b5cf6;
            --bg: #09090b;
            --surface: #18181b;
            --border: #27272a;
            --text: #f4f4f5;
            --text-muted: #a1a1aa;
            --success: #22c55e;
            --error: #ef4444;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            padding: 0 20px;
            box-sizing: border-box;
            margin: 0 auto;
        }

        h1 {
            text-align: center;
            margin-bottom: 30px;
            background: linear-gradient(to right, #fff, #a1a1aa);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .auth-box {
            max-width: 400px;
            margin: 100px auto;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 30px;
        }

        .auth-box h2 {
            text-align: center;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            background: #000;
            border: 1px solid var(--border);
            border-radius: 8px;
            color: white;
            font-family: inherit;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: 0.2s;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            width: 100%;
        }

        .btn-primary:hover {
            opacity: 0.9;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--error);
            color: white;
        }

        .dashboard {
            display: none;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
        }

        .stat-card h3 {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 10px;
        }

        .stat-card .value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
        }

        .section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        /* Glowing border animation */
        .glow-border {
            position: relative;
            overflow: hidden;
            border: none;
        }
        .glow-border::before {
            content: '';
            position: absolute;
            top: -2px; left: -2px;
            right: -2px; bottom: -2px;
            background: conic-gradient(from 0deg, transparent 0%, transparent 60%, #8b5cf6 75%, #ec4899 85%, #8b5cf6 95%, transparent 100%);
            border-radius: 14px;
            animation: glowSpin 3s linear infinite;
            z-index: 0;
        }
        .glow-border::after {
            content: '';
            position: absolute;
            top: 2px; left: 2px;
            right: 2px; bottom: 2px;
            background: var(--surface);
            border-radius: 10px;
            z-index: 1;
        }
        .glow-border > * {
            position: relative;
            z-index: 2;
        }
        @keyframes glowSpin {
            to { transform: rotate(360deg); }
        }

        .section h2 {
            margin-bottom: 20px;
            font-size: 1.3rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th,
        table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        table th {
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.9rem;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-success {
            background: var(--success);
            color: white;
        }

        .badge-error {
            background: var(--error);
            color: white;
        }

        .badge-warning {
            background: #f59e0b;
            color: white;
        }

        .badge-premium {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            font-weight: 800;
            box-shadow: 0 0 10px rgba(245, 158, 11, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .badge-pro {
            background: var(--primary);
            color: white;
        }

        .badge-trial {
            background: #3f3f46;
            color: #d4d4d8;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #333;
            transition: .4s;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
        }

        input:checked+.slider {
            background-color: var(--primary);
        }

        input:checked+.slider:before {
            transform: translateX(26px);
        }

        .slider.round {
            border-radius: 24px;
        }

        .slider.round:before {
            border-radius: 50%;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .scrollable-table {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-top: 10px;
        }

        /* Keep table header fixed if possible */
        .scrollable-table thead th {
            position: sticky;
            top: 0;
            background: var(--surface);
            z-index: 10;
        }

        /* TABS STYLING */
        .nav-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 1px;
            overflow-x: auto;
            white-space: nowrap;
        }

        .tab-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            padding: 12px 20px;
            cursor: pointer;
            font-family: inherit;
            font-weight: 600;
            font-size: 0.95rem;
            position: relative;
            transition: 0.2s;
        }

        .tab-btn:hover {
            color: var(--text);
        }

        .tab-btn.active {
            color: var(--primary);
        }

        .tab-btn.active::after {
            content: "";
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .dashboard-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 25px;
            align-items: start;
        }

        .dashboard-layout .stats-grid {
            margin-bottom: 0;
        }

        @media (min-width: 1024px) {
            .dashboard-layout {
                grid-template-columns: 320px 1fr;
            }
            .dashboard-layout .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 15px;
            }
            #tab-dashboard .stats-grid .stat-card:last-child {
                grid-column: span 2;
            }
            .stat-card {
                padding: 12px;
            }
            .stat-card h3 {
                font-size: 0.8rem;
            }
            .stat-card .value {
                font-size: 1.3rem;
            }
            .nav-tabs {
                margin-bottom: 15px;
            }
        }
    </style>
</head>

<body>

    <!-- Sound Alert Element (Hidden) -->
    <audio id="offlineAlertSound" preload="auto">
        <source src="https://actions.google.com/sounds/v1/alarms/alarm_clock_beep.ogg" type="audio/ogg">
    </audio>

    <!-- Global Alert Banner -->
    <div id="offlineBanner"
        style="display: none; background: var(--error); color: white; padding: 15px; text-align: center; font-weight: 700; font-size: 1.2rem; position: sticky; top: 0; z-index: 9999; border-bottom: 2px solid white; box-shadow: 0 5px 15px rgba(239, 68, 68, 0.4);">
        <i class="fa-solid fa-triangle-exclamation"></i> CẢNH BÁO: TOÀN BỘ MÁY CHỦ COLAB ĐANG OFFLINE! <i
            class="fa-solid fa-triangle-exclamation"></i>
        <div style="font-size: 0.9rem; font-weight: 400; margin-top: 5px;">Vui lòng vào Reset lại các tab Colab ngay để
            không gián đoạn dịch vụ.</div>
    </div>

    <!-- Auth Screen -->
    <div id="authScreen">
        <div class="auth-box">
            <h2>🔐 Admin Login</h2>
            <div class="form-group">
                <label>Admin Password</label>
                <input type="password" class="form-control" id="adminPassword"
                    onkeydown="if(event.key==='Enter')login()">
            </div>
            <div class="form-group" id="totpGroup" style="display:none;">
                <label>Mã 2FA (Google Authenticator)</label>
                <input type="text" class="form-control" id="totpCode" maxlength="6" placeholder="Nhập 6 số"
                    autocomplete="off" onkeydown="if(event.key==='Enter')login()"
                    style="text-align:center; font-size:1.5rem; letter-spacing:8px;">
            </div>
            <button class="btn btn-primary" onclick="login()">Đăng nhập</button>
            <div class="error-msg" id="authError"></div>
        </div>
    </div>

    <!-- Dashboard -->
    <div id="dashboard" class="dashboard">
        <div class="container">
            <div
                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 20px;">
                <h1 style="margin: 0;">⚙️ Admin Panel</h1>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <div
                        style="display: flex; align-items: center; gap: 8px; background: var(--surface); border: 1px solid var(--border); padding: 5px 12px; border-radius: 8px;">
                        <span style="font-size: 0.85rem; color: var(--text-muted); white-space: nowrap;">Auto
                            Refresh</span>
                        <label class="switch">
                            <input type="checkbox" id="autoRefreshToggle" onchange="toggleAutoRefresh()" checked>
                            <span class="slider round"></span>
                        </label>
                        <span id="lastUpdated"
                            style="font-size: 0.75rem; color: var(--text-muted); margin-left: 5px;"></span>
                        <span id="headerTotalCredits"
                            style="font-size: 0.85rem; color: var(--primary); font-weight: 700; margin-left: 10px; border-left: 1px solid var(--border); padding-left: 10px; white-space: nowrap;"
                            title="Tổng Credits khả dụng trên toàn hệ thống">
                            - pts
                        </span>
                    </div>
                    <button class="btn"
                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text); width: auto;"
                        onclick="loadStats()">
                        <i class="fa-solid fa-rotate"></i>
                    </button>
                    <a href="api/admin/view_affiliate_logs.php" target="_blank" class="btn"
                        style="background: var(--surface); border: 1px solid var(--border); color: #f59e0b; width: auto; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;"
                        title="Xem lịch sử cộng điểm Affiliate">
                        <i class="fa-solid fa-gift"></i>
                    </a>
                    <button class="btn" style="background: var(--error); color: white; width: auto;" onclick="logout()">
                        <i class="fa-solid fa-right-from-bracket"></i> Đăng xuất
                    </button>
                </div>
            </div>

            <!-- Tab Navigation -->
            <nav class="nav-tabs">
                <button class="tab-btn active" id="btn-dashboard" onclick="switchTab('dashboard')"><i
                        class="fa-solid fa-chart-line"></i> Tổng quan</button>
                <button class="tab-btn" id="btn-users" onclick="switchTab('users')"><i class="fa-solid fa-users"></i>
                    Khách hàng</button>
                <button class="tab-btn" id="btn-health" onclick="switchTab('health')"><i
                        class="fa-solid fa-heart-pulse"></i>
                    Sức khỏe</button>
                <button class="tab-btn" id="btn-keys" onclick="switchTab('keys')"><i class="fa-solid fa-key"></i>
                    API
                    Keys</button>
                <button class="tab-btn" id="btn-billing" onclick="switchTab('billing')"><i
                        class="fa-solid fa-wallet"></i> Gói & Tiền</button>
                <button class="tab-btn" id="btn-logs" onclick="switchTab('logs')"><i class="fa-solid fa-file-lines"></i>
                    Logs</button>
                <button class="tab-btn" id="btn-broadcast" onclick="switchTab('broadcast')"><i
                        class="fa-solid fa-bullhorn"></i>
                    Thông báo</button>
                <button class="tab-btn" id="btn-settings" onclick="switchTab('settings')"><i
                        class="fa-solid fa-gear"></i> Cài đặt</button>
                <button class="tab-btn" id="btn-ngrok" onclick="switchTab('ngrok')"><i
                        class="fa-solid fa-network-wired"></i> Ngrok Keys</button>
                <button class="tab-btn" id="btn-docs" onclick="switchTab('docs')"><i class="fa-solid fa-book"></i> Tài
                    liệu</button>
            </nav>
        </div> <!-- End container for header and nav -->

        <!-- TAB: DASHBOARD -->
        <div id="tab-dashboard" class="tab-content active">
            <div class="dashboard-layout">
                    <div>
                        <div class="stats-grid">
                    <div class="stat-card">
                        <h3>Tổng khách hàng / Khách đang hoạt động</h3>
                        <div class="value"><span id="statTotalUsers">-</span> / <span id="statActiveUsers">-</span></div>
                    </div>
                    <div class="stat-card">
                        <h3>API Keys hoạt động</h3>
                        <div class="value" id="statActiveKeys">-</div>
                    </div>
                    <div class="stat-card">
                        <h3>Credits khả dụng</h3>
                        <div class="value" id="statTotalCredits">-</div>
                    </div>
                    <div class="stat-card">
                        <h3>Chuyển đổi hôm nay</h3>
                        <div class="value" id="statTodayConversions">-</div>
                    </div>
                    <div class="stat-card">
                        <h3>Ký tự hôm nay</h3>
                        <div class="value" id="statTodayChars">-</div>
                    </div>
                    <div class="stat-card">
                        <h3>Ký tự hôm qua</h3>
                        <div class="value" id="statYesterdayChars">-</div>
                    </div>
                    <div class="stat-card">
                        <h3>Doanh thu hôm nay</h3>
                        <div class="value" id="statTodayRevenue">-</div>
                    </div>
                    <div class="stat-card"
                        style="border: 2px solid var(--success); background: rgba(34, 197, 94, 0.05);">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div>
                                <h3 style="color: var(--success);">Tổng doanh thu</h3>
                                <div class="value" id="statHarvestableBalance" style="color: var(--success);">-</div>
                            </div>
                            <button class="btn btn-success" style="width: auto; padding: 5px 12px; font-size: 0.8rem;"
                                onclick="harvestRevenue()">
                                <i class="fa-solid fa-hand-holding-dollar"></i> Rút về túi
                            </button>
                        </div>
                    </div>
                </div>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 25px;">
                        <div class="section glow-border" style="margin-bottom: 0;">
                    <h2>Hoạt động gần đây</h2>
                    <div class="scrollable-table" style="max-height: 600px;">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Email</th>
                                    <th>Characters</th>
                                    <th>Trạng thái</th>
                                    <th>Voice</th>
                                    <th>Worker/Server</th>
                                    <th>Preview</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody id="logsTable">
                                <tr>
                                    <td colspan="8" style="text-align: center;">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                        <div class="section" style="border-top: 2px solid var(--primary); margin-bottom: 0;">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h2 style="margin:0;"><i class="fa-solid fa-terminal"></i> ⚡ Live Server Logs (Hoạt động máy
                            chủ)</h2>
                        <span class="badge badge-pro">Real-time</span>
                    </div>
                    <div class="scrollable-table" style="max-height: 400px; background: #000;">
                        <table style="font-family: -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif; font-weight: 600">
                            <thead style="background: #111;">
                                <tr>
                                    <th style="width: 150px;">Thời gian</th>
                                    <th style="width: 150px;">Máy chủ</th>
                                    <th style="width: 120px;">Job ID</th>
                                    <th>Nội dung sự kiện</th>
                                </tr>
                            </thead>
                            <tbody id="workerEventsTable">
                                <tr>
                                    <td colspan="4" style="text-align: center; color: var(--text-muted);">Đang đợi dữ
                                        liệu...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                        </div>
                    </div>
                </div>
            </div>

        <!-- TAB: HEALTH -->
        <div id="tab-health" class="tab-content">
            <div class="stats-grid" style="margin-bottom: 20px;">
                <div class="stat-card" style="border-left: 4px solid var(--primary);">
                    <h3>Tổng ký tự hệ thống</h3>
                    <div class="value" id="healthTotalCredits">-</div>
                </div>
                <div class="stat-card" style="border-left: 4px solid var(--success);">
                    <h3>Máy chủ Online</h3>
                    <div class="value" id="healthOnlineWorkers">-</div>
                </div>
            </div>

            <div class="section">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                    <h2 style="margin:0"><i class="fa-solid fa-server"></i> Cụm máy chủ Colab (Worker Pool)</h2>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <button class="btn btn-warning" style="width: auto; font-size: 0.9rem; padding: 8px 15px;"
                            onclick="retryFailedJobs()">
                            <i class="fa-solid fa-rotate-right"></i> Thử lại tất cả Job lỗi
                        </button>
                        <button class="btn btn-danger" style="width: auto; font-size: 0.9rem; padding: 8px 15px;"
                            onclick="forceResetJobs()">
                            <i class="fa-solid fa-bolt"></i> Giải cứu Job kẹt (Force Reset)
                        </button>
                        <button class="btn"
                            style="width: auto; font-size: 0.9rem; padding: 8px 15px; background: #6b7280; color: white;"
                            onclick="cleanupOfflineWorkers()">
                            <i class="fa-solid fa-broom"></i> Dọn máy Offline
                        </button>
                    </div>
                </div>
                <div class="scrollable-table">
                    <table>
                        <thead>
                            <tr>
                                <th>UUID</th>
                                <th>IP Address</th>
                                <th>Ngrok URL</th>
                                <th>Status</th>
                                <th>Jobs</th>
                                <th>Chars</th>
                                <th>Failed (session)</th>
                                <th>Online Since</th>
                                <th>Last Seen</th>
                            </tr>
                        </thead>
                        <tbody id="healthWorkersTable">
                            <tr>
                                <td colspan="5" style="text-align: center;">Đang tải...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>


            <div class="section">
                <h2><i class="fa-solid fa-key"></i> Trình trạng API Keys (ElevenLabs)</h2>
                <div class="scrollable-table">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 40px;"><input type="checkbox"
                                        onclick="toggleAllKeys(this, 'health-key-checkbox')"></th>
                                <th>ID</th>
                                <th>Key (Masked)</th>
                                <th>Credits</th>
                                <th>Status</th>
                                <th>Gán cho Máy</th>
                                <th title="Ngày tài khoản được làm mới character">Hạn Reset</th>
                                <th>Last Checked</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="healthKeysTable">
                            <tr>
                                <td colspan="5" style="text-align: center;">Đang tải...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB: USERS -->
        <div id="tab-users" class="tab-content">
            <div class="section"
                style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div style="display: flex; align-items: center; gap: 15px; flex: 1;">
                    <h2 style="margin:0; white-space: nowrap;">Quản lý khách hàng</h2>
                    <div style="position: relative; max-width: 300px; width: 100%;">
                        <i class="fa-solid fa-magnifying-glass"
                            style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
                        <input type="text" id="userSearchInput" class="form-control" placeholder="Tìm theo email..."
                            onkeyup="filterUsersTable()" style="padding-left: 35px; border-radius: 20px;">
                    </div>
                </div>
                <button class="btn btn-success" style="width:auto" onclick="showAddUserModal()">
                    <i class="fa-solid fa-user-plus"></i> Tạo khách hàng
                </button>
            </div>
            <div class="section">
                <div class="scrollable-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>Plan</th>
                                <th>Hôm nay</th>
                                <th>Used</th>
                                <th>Total</th>
                                <th>Expires</th>
                                <th>Status</th>
                                <th>Chủ nhóm</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTable">
                            <tr>
                                <td colspan="9" style="text-align: center;">Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB: API KEYS -->
        <div id="tab-keys" class="tab-content">
            <div class="section">
                <h2>Quản lý API Keys</h2>
                <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;">
                    <button class="btn btn-success" style="width:auto" onclick="showAddKeyModal()"><i
                            class="fa-solid fa-key"></i> Thêm Key</button>
                    <button class="btn btn-primary" style="width:auto" onclick="showBulkImportModal()"><i
                            class="fa-solid fa-file-import"></i> Import hàng loạt</button>
                    <button class="btn" style="width:auto; background: #3b82f6; color: white;"
                        onclick="checkSelectedKeys()">
                        <i class="fa-solid fa-sync" id="bulkCheckIcon"></i> Kiểm tra Credits đã chọn
                    </button>
                </div>
                <div
                    style="padding: 15px; background: rgba(0,0,0,0.2); border-radius: 8px; display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 0.9rem; color: var(--text-muted);">Xóa key có credits ≤ :</span>
                    <input type="number" id="minCreditsDelete" class="form-control" style="width: 100px;" value="20">
                    <button class="btn btn-danger" style="width: auto;" onclick="bulkDeleteKeys()"><i
                            class="fa-solid fa-trash-can"></i> Dọn dẹp key yếu</button>
                    <button class="btn" style="width: auto; background: #f59e0b; color: white;" onclick="reloadInactiveKeys()"><i
                            class="fa-solid fa-rotate-right"></i> Reload Key lỗi</button>
                </div>
                <div
                    style="padding: 15px; background: rgba(59, 130, 246, 0.1); border-radius: 8px; display: flex; align-items: center; gap: 10px; margin-top: 10px;">
                    <span style="font-size: 0.9rem; color: var(--text-muted);">Kiểm tra key có credits ≤ :</span>
                    <input type="number" id="minCreditsCheck" class="form-control" style="width: 100px;" value="1000">
                    <button class="btn" style="width: auto; background: #3b82f6; color: white;"
                        onclick="checkWeakKeys()"><i class="fa-solid fa-sync"></i> Kiểm tra Key yếu</button>
                    <a href="api/admin/reset_system.php" target="_blank"
                        style="color: #ff4d4d; font-weight: bold; border: 1px solid #ff4d4d; padding: 2px 8px; border-radius: 4px; text-decoration: none; margin-left:10px;">RESET
                        HỆ THỐNG</a>
                </div>

                <!-- KEY POOL SECTION -->
                <div
                    style="margin-top: 15px; padding: 20px; background: rgba(34, 197, 94, 0.05); border: 1px solid rgba(34, 197, 94, 0.2); border-radius: 12px;">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <h3 style="margin: 0; font-size: 1rem; color: #22c55e;">
                            <i class="fa-solid fa-warehouse"></i> Kho Key dự trữ
                            <span id="poolCount"
                                style="font-size: 0.85rem; color: var(--text-muted); font-weight: 400;">(0 key)</span>
                        </h3>
                        <div style="display: flex; gap: 8px;">
                            <button class="btn"
                                style="width:auto; padding:5px 12px; font-size:0.8rem; background:#22c55e; color:white;"
                                onclick="saveKeyPool()">
                                <i class="fa-solid fa-floppy-disk"></i> Lưu Pool
                        </div>
                    </div>
                    <textarea class="form-control" id="keyPoolText" rows="5"
                        placeholder="Dán key vào đây (mỗi key 1 dòng). Bấm 'Lưu Pool' để lưu.&#10;enc:xxx&#10;email@example.com:password&#10;..."
                        style="font-size: 0.8rem; font-family: monospace; margin-bottom: 12px;"></textarea>

                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <span style="font-size: 0.9rem; color: var(--text-muted);">Lấy từ kho:</span>
                        <input type="number" id="poolConsumeCount" class="form-control" style="width: 80px;" value="10"
                            min="1">
                        <span style="font-size: 0.9rem; color: var(--text-muted);">key</span>
                        <button class="btn btn-success" style="width: auto; padding: 6px 16px;"
                            onclick="addKeysFromPool()" id="btnAddFromPool">
                            <i class="fa-solid fa-plus-circle"></i> Thêm vào hệ thống
                        </button>
                    </div>

                    <!-- Pool Results -->
                    <div id="poolResults" style="display: none; margin-top: 12px; max-height: 300px; overflow-y: auto;">
                        <div id="poolResultsList"></div>
                    </div>
                </div>
            </div>
            <div class="section">
                <div class="scrollable-table">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 40px;"><input type="checkbox"
                                        onclick="toggleAllKeys(this, 'key-checkbox')"></th>
                                <th>ID</th>
                                <th>Key Preview</th>
                                <th>Credits</th>
                                <th>Status</th>
                                <th>Gán cho Máy</th>
                                <th title="Ngày tài khoản được làm mới character">Hạn Reset</th>
                                <th>Last Checked</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="keysTable">
                            <tr>
                                <td colspan="6" style="text-align: center;">Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB: NGROK KEYS -->
        <div id="tab-ngrok" class="tab-content">
            <div class="section">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                    <h2 style="margin:0"><i class="fa-solid fa-network-wired"></i> Quản lý Ngrok Token Pool</h2>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <button class="btn btn-success" style="width:auto" onclick="showAddNgrokModal()"><i
                                class="fa-solid fa-plus"></i> Thêm Token</button>
                        <button class="btn" style="width:auto; background:#f59e0b; color:white;"
                            onclick="resetAllNgrokTokens()" title="Giải phóng toàn bộ token về trạng thái rảnh"><i
                                class="fa-solid fa-rotate-left"></i> Reset Tất Cả</button>
                        <button class="btn"
                            style="width:auto; background: var(--surface); border: 1px solid var(--border); color: var(--text);"
                            onclick="loadNgrokKeys()"><i class="fa-solid fa-rotate"></i> Làm mới</button>
                    </div>
                </div>
                <div
                    style="padding: 12px; background: rgba(139,92,246,0.1); border: 1px solid rgba(139,92,246,0.3); border-radius: 8px; margin-bottom: 15px; font-size: 0.9rem; color: var(--text-muted);">
                    <i class="fa-solid fa-circle-info" style="color: var(--primary);"></i>
                    Khi máy Colab khởi động, nó sẽ tự động xin token rảnh từ danh sách này. Token đang dùng sẽ được thu
                    hồi sau 5 phút máy đó offline.
                </div>
                <div class="scrollable-table">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Tên Token</th>
                                <th>Token (Ẩn)</th>
                                <th>Trạng thái</th>
                                <th>Máy đang dùng</th>
                                <th>IP</th>
                                <th>Cấp lúc</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="ngrokKeysTable">
                            <tr>
                                <td colspan="8" style="text-align: center;">Đang tải...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Colab Remote Control -->
            <div class="section" style="border-top: 2px solid #8b5cf6; margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2 style="margin:0"><i class="fa-solid fa-gamepad"></i> 🎮 Colab Remote Control</h2>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn"
                            style="width: auto; font-size: 0.85rem; padding: 6px 14px; background: var(--error);"
                            onclick="colabCommandAll('disconnect')">
                            <i class="fa-solid fa-power-off"></i> Disconnect All
                        </button>
                        <button class="btn"
                            style="width: auto; font-size: 0.85rem; padding: 6px 14px; background: var(--success);"
                            onclick="colabCommandAll('run_all')">
                            <i class="fa-solid fa-play"></i> Run All
                        </button>
                        <button class="btn"
                            style="width: auto; font-size: 0.85rem; padding: 6px 14px; background: var(--surface); border: 1px solid var(--border); color: var(--text);"
                            onclick="loadColabExtensions()">
                            <i class="fa-solid fa-rotate"></i>
                        </button>
                    </div>
                </div>
                <div id="colabExtensionGrid"
                    style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px;">
                    <div style="text-align: center; color: var(--text-muted); padding: 20px;">Đang tải...</div>
                </div>
                <div class="section" style="margin-top: 15px;">
                    <h3 style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 10px;"><i
                            class="fa-solid fa-clock-rotate-left"></i> Lệnh gần đây</h3>
                    <div class="scrollable-table" style="max-height: 200px;">
                        <table style="font-size: 0.85rem;">
                            <thead>
                                <tr>
                                    <th>Thời gian</th>
                                    <th>Worker</th>
                                    <th>Lệnh</th>
                                    <th>Trạng thái</th>
                                    <th>Kết quả</th>
                                </tr>
                            </thead>
                            <tbody id="colabCommandsTable">
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--text-muted);">Chưa có lệnh
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>


        </div>

        <!-- TAB: BILLING -->
        <div id="tab-billing" class="tab-content">
            <div class="section">
                <h2>Giao dịch gần đây</h2>
                <div class="scrollable-table">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Email</th>
                                <th>Plan</th>
                                <th>Số tiền</th>
                                <th>Status</th>
                                <th>Thời gian</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="paymentsTable">
                            <tr>
                                <td colspan="6" style="text-align: center;">Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="section">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                    <h2 style="margin: 0;">Cấu hình các Gói (Packages)</h2>
                    <button class="btn btn-primary" style="width: auto; padding: 8px 20px;"
                        onclick="document.getElementById('addPackageModal').style.display='flex'">
                        <i class="fa-solid fa-plus-circle"></i> Thêm Gói Mới
                    </button>
                </div>
                <div class="scrollable-table">
                <table>
                    <thead>
                        <tr>
                            <th>Plan ID</th>
                            <th>Tên Gói</th>
                            <th>Hạn mức</th>
                            <th>Hạn dùng</th>
                            <th>Giá VNĐ</th>
                            <th>Giá USD ($)</th>
                            <th style="text-align: center;">SRT</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="packagesTable">
                        <tr>
                            <td colspan="6" style="text-align: center;">Loading...</td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        <!-- TAB: SETTINGS -->
        <div id="tab-settings" class="tab-content">
            <div class="section">
                <h2>Cài đặt Hệ thống</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6"
                    style="display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 20px;">
                    <!-- 2FA Security -->
                    <div class="card" style="background: rgba(255,255,255,0.05); padding: 20px; border-radius: 12px;">
                        <h3 style="margin-top: 0; color: #22c55e; font-size: 1.1rem;"><i
                                class="fa-solid fa-shield-halved"></i> Bảo mật 2FA (Google Authenticator)</h3>
                        <div id="2faStatus" style="margin: 15px 0;">
                            <span style="color: var(--text-muted);">Đang kiểm tra...</span>
                        </div>
                        <div id="2faActions"></div>
                        <div id="2faQR" style="display:none; margin-top:15px; text-align:center;">
                            <p style="color: var(--text-muted); margin-bottom:10px;">Quét mã QR bằng Google
                                Authenticator:</p>
                            <img id="2faQRImage" src="" style="border-radius:8px; background:white; padding:10px;">
                            <div style="margin-top:10px;">
                                <code id="2faSecretText" style="font-size:1.1rem; color:var(--primary);"></code>
                                <p style="font-size:0.8rem; color:var(--text-muted);">Hoặc nhập mã trên vào app thủ công
                                </p>
                            </div>
                            <div class="form-group"
                                style="margin-top:15px; max-width:200px; margin-left:auto; margin-right:auto;">
                                <input type="text" class="form-control" id="2faConfirmCode" maxlength="6"
                                    placeholder="Nhập mã 6 số"
                                    style="text-align:center; font-size:1.3rem; letter-spacing:6px;">
                            </div>
                            <button class="btn btn-success" style="width:auto; margin-top:10px;"
                                onclick="confirm2FA()">✅ Xác nhận bật 2FA</button>
                        </div>
                        <div id="2faMessage" style="margin-top:10px;"></div>
                    </div>

                    <!-- Affiliate Settings -->
                    <div class="card" style="background: rgba(255,255,255,0.05); padding: 20px; border-radius: 12px;">
                        <h3 style="margin-top: 0; color: var(--primary); font-size: 1.1rem;"><i
                                class="fa-solid fa-hand-holding-dollar"></i> Tiếp thị liên kết (Affiliate)</h3>
                        <div class="form-group" style="margin-top: 15px;">
                            <label>Tỷ lệ hoa hồng (%)</label>
                            <input type="number" class="form-control" id="conf_affiliate_rate" placeholder="10">
                            <small class="text-muted">Nhập số phần trăm (Ví dụ: 10 = 10%). Mặc định là 10%.</small>
                        </div>
                    </div>

                    <!-- Promo Popup Settings -->
                    <div class="card" style="background: rgba(255,255,255,0.05); padding: 20px; border-radius: 12px;">
                        <h3 style="margin-top: 0; color: #f59e0b; font-size: 1.1rem;"><i
                                class="fa-solid fa-bullhorn"></i> Popup Khuyến mãi (Dashboard)</h3>
                        <div class="form-group"
                            style="margin-top: 15px; display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" id="conf_promo_enabled" style="width: 20px; height: 20px;">
                            <label style="margin: 0;">Kích hoạt Popup</label>
                        </div>
                        <div class="form-group">
                            <label>Link ảnh Popup (JPG/PNG/GIF)</label>
                            <input type="text" class="form-control" id="conf_promo_image"
                                placeholder="https://imgur.com/...">
                        </div>
                        <div class="form-group">
                            <label>Link đích khi click (Tùy chọn)</label>
                            <input type="text" class="form-control" id="conf_promo_link"
                                placeholder="https://zalo.me/...">
                        </div>
                        <div class="form-group">
                            <label>Tần suất hiển thị</label>
                            <select class="form-control" id="conf_promo_frequency">
                                <option value="always">Hiện liên tục (Để test)</option>
                                <option value="once_per_day">1 lần mỗi 24 giờ (Thực tế)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div style="text-align: right; margin-bottom: 20px;">
                    <button class="btn btn-primary" onclick="saveSettings()">
                        <i class="fa-solid fa-floppy-disk"></i> Lưu cấu hình chung
                    </button>
                    <div id="saveSettingsMsg" style="margin-top: 10px; font-weight: bold;"></div>
                </div>


            </div>

            <div class="section">
                <h2>Mạng xã hội (Header)</h2>
                <div style="background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px;">
                    <div class="form-group"><label><i class="fa-solid fa-comment-dots"></i> Zalo Link</label><input
                            type="text" id="socialZalo" class="form-control" placeholder="https://zalo.me/...">
                    </div>
                    <div class="form-group"><label><i class="fa-brands fa-telegram"></i> Telegram Link</label><input
                            type="text" id="socialTelegram" class="form-control" placeholder="https://t.me/...">
                    </div>
                    <div class="form-group"><label><i class="fa-brands fa-facebook"></i> Facebook Link</label><input
                            type="text" id="socialFacebook" class="form-control" placeholder="https://facebook.com/...">
                    </div>
                    <div class="form-group"><label><i class="fa-brands fa-youtube"></i> YouTube Link</label><input
                            type="text" id="socialYoutube" class="form-control" placeholder="https://youtube.com/...">
                    </div>
                    <div class="form-group"><label><i class="fa-solid fa-circle-play"></i> Video Hướng dẫn
                            Link</label><input type="text" id="socialTutorialLink" class="form-control"
                            placeholder="https://youtube.com/watch?v=...">
                    </div>

                    <button class="btn btn-primary" onclick="saveSocialSettings()"><i class="fa-solid fa-save"></i>
                        Lưu Mạng Xã Hội</button>
                </div>
            </div>

            <div class="section">
                <h2>🔔 Telegram Hệ thống (Worker Offline, Credit, IP Block...)</h2>
                <div style="background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px;">
                    <div class="form-group"><label>Bot Token</label><input type="text" id="tgToken"
                            class="form-control"></div>
                    <div class="form-group"><label>Chat ID</label><input type="text" id="tgChatId" class="form-control">
                    </div>
                    <div class="form-group" style="display: flex; align-items: center; gap: 10px;">
                        <input type="checkbox" id="tgEnabled" style="width: 20px; height: 20px;">
                        <label style="margin: 0;">Thông báo khi máy Offline</label>
                    </div>
                </div>
            </div>

            <div class="section">
                <h2>💰 Telegram Khách hàng (Đăng ký, Chuyển tiền, Duyệt)</h2>
                <div style="background: rgba(36, 161, 222, 0.08); padding: 15px; border-radius: 8px; border: 1px solid rgba(36, 161, 222, 0.25);">
                    <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 12px;">Tách riêng thông báo khách hàng sang Bot khác. Nếu để trống sẽ dùng chung Bot hệ thống ở trên. Chat ID dùng chung với Bot trên.</div>
                    <div class="form-group"><label>Bot Token (Bot 2)</label><input type="text" id="tgToken2"
                            class="form-control" placeholder="Để trống = dùng chung Bot trên"></div>
                    <button class="btn btn-primary" onclick="saveTelegramSettings()"><i class="fa-solid fa-save"></i>
                        Lưu Telegram</button>
                    <div id="tgStatus" style="margin-top: 10px; font-size: 0.9rem;"></div>
                </div>
            </div>
        </div>

        <!-- TAB: BROADCAST -->
        <div id="tab-broadcast" class="tab-content">
            <div class="section">
                <h2><i class="fa-solid fa-bullhorn"></i> Gửi thông báo hàng loạt</h2>
                <p style="color: var(--text-muted); margin-bottom: 20px;">Gửi email thông báo cho toàn bộ khách hàng
                    hoặc nhóm khách hàng cụ thể.</p>

                <div
                    style="background: rgba(0,0,0,0.2); padding: 25px; border-radius: 12px; border: 1px solid var(--border);">
                    <div class="form-group">
                        <label>Đối tượng nhận tin</label>
                        <select id="broadcastTarget" class="form-control">
                            <option value="active">Chỉ khách đang hoạt động (Active)</option>
                            <option value="all">Tất cả khách hàng (Cả Inactive/Expired)</option>
                            <option value="expired">Chỉ khách đã hết hạn (Expired)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Mẫu Email</label>
                        <select id="broadcastTemplate" class="form-control" onchange="toggleBroadcastCustom()">
                            <option value="activation">Mẫu: Thông báo Kích hoạt tài khoản</option>
                            <option value="custom">Soạn thảo nội dung tùy chỉnh (HTML)</option>
                        </select>
                    </div>

                    <div id="broadcastCustomFields" style="display: none;">
                        <div class="form-group">
                            <label>Tiêu đề Email</label>
                            <input type="text" id="broadcastSubject" class="form-control"
                                placeholder="Nhập tiêu đề email...">
                        </div>
                        <div class="form-group">
                            <label>Nội dung Email (HTML)</label>
                            <textarea id="broadcastBody" class="form-control" rows="10"
                                placeholder="Nhập nội dung thông báo..."></textarea>
                        </div>
                    </div>

                    <div style="margin-top: 30px;">
                        <button class="btn btn-primary" id="btnSendBroadcast" onclick="sendMassMail()">
                            <i class="fa-solid fa-paper-plane"></i> Bắt đầu gửi ngay
                        </button>
                    </div>

                    <div id="broadcastProgress"
                        style="display: none; margin-top: 20px; padding: 15px; background: rgba(139, 92, 246, 0.1); border-radius: 8px; border: 1px solid var(--primary);">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-spinner fa-spin" style="color: var(--primary);"></i>
                            <span id="broadcastStatus">Đang xử lý gửi email... Vui lòng không đóng trình
                                duyệt.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB: LOGS -->
        <div id="tab-logs" class="tab-content">
            <div class="section">
                <h2><i class="fa-solid fa-file-lines"></i> Nhật ký lỗi Key (Theo ngày)</h2>
                <div
                    style="background: rgba(0,0,0,0.3); padding: 15px; border-radius: 8px; font-family: monospace; font-size: 0.9rem; max-height: 400px; overflow-y: auto;">
                    <div id="errorLogsContent" style="color: #0f0; line-height: 1.6;">Loading...</div>
                </div>
            </div>

            <div class="section">
                <h2><i class="fa-solid fa-circle-check"></i> Nhật ký Job hoàn thành (Gần đây)</h2>
                <div
                    style="background: rgba(0,0,0,0.3); padding: 15px; border-radius: 8px; font-family: monospace; font-size: 0.9rem; max-height: 400px; overflow-y: auto;">
                    <div id="completedLogsContent" style="color: #4ade80; line-height: 1.6;">Loading...</div>
                </div>
            </div>

            <div class="section">
                <h2><i class="fa-solid fa-triangle-exclamation"></i> Nhật ký Job thất bại (Tổng hợp)</h2>
                <div
                    style="background: rgba(0,0,0,0.3); padding: 15px; border-radius: 8px; font-family: monospace; font-size: 0.9rem; max-height: 400px; overflow-y: auto;">
                    <div id="failedLogsContent" style="color: #f00; line-height: 1.6;">Loading...</div>
                </div>
            </div>


        </div>

        <!-- TAB: DOCUMENTS -->
        <div id="tab-docs" class="tab-content">
            <div class="section"
                style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <h2 style="margin:0"><i class="fa-solid fa-book"></i> Quản lý Tài liệu</h2>
                <button class="btn btn-success" style="width:auto" onclick="showDocEditor()">
                    <i class="fa-solid fa-plus"></i> Tạo bài viết mới
                </button>
            </div>
            <div class="section">
                <div class="scrollable-table">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tiêu đề</th>
                                <th>Danh mục</th>
                                <th>Gói tối thiểu</th>
                                <th>Trạng thái</th>
                                <th>Ngày tạo</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="docsTable">
                            <tr>
                                <td colspan="7" style="text-align: center;">Đang tải...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <!-- Document Editor Modal -->
    <div class="modal" id="docEditorModal">
        <div class="modal-content" style="max-width: 900px; max-height: 90vh; overflow-y: auto;">
            <h2 id="docEditorTitle">Tạo bài viết mới</h2>
            <input type="hidden" id="docEditId" value="">
            <div class="form-group">
                <label>Tiêu đề</label>
                <input type="text" class="form-control" id="docTitle" placeholder="Tiêu đề bài viết">
            </div>
            <div class="form-group">
                <label>Mô tả ngắn (hiện ở card)</label>
                <textarea class="form-control" id="docSummary" rows="2" placeholder="Mô tả ngắn..."></textarea>
            </div>
            <div class="form-group">
                <label>Thumbnail URL</label>
                <input type="text" class="form-control" id="docThumbnail" placeholder="https://...">
            </div>
            <div style="display: flex; gap: 15px;">
                <div class="form-group" style="flex:1;">
                    <label>Danh mục</label>
                    <select class="form-control" id="docCategory">
                        <option value="guide">📖 Hướng dẫn</option>
                        <option value="tips">💡 Mẹo hay</option>
                        <option value="template">📝 Mẫu kịch bản</option>
                        <option value="news">📢 Tin tức</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Gói tối thiểu</label>
                    <select class="form-control" id="docMinPlan">
                        <option value="free">Free (Ai cũng xem được)</option>
                        <option value="basic">Basic</option>
                        <option value="pro" selected>Pro</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Trạng thái</label>
                    <select class="form-control" id="docStatus">
                        <option value="draft">Bản nháp</option>
                        <option value="published">Xuất bản</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Nội dung bài viết</label>
                <div id="quillEditor"
                    style="height: 300px; background: #000; color: white; border: 1px solid var(--border); border-radius: 8px;">
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-primary" onclick="saveDocument()">Lưu bài viết</button>
                <button class="btn" style="background: var(--border);"
                    onclick="closeModal('docEditorModal')">Hủy</button>
            </div>
        </div>
    </div>

    <!-- Add Key Modal -->
    <div class="modal" id="addKeyModal">
        <div class="modal-content">
            <h2>Thêm API Key</h2>
            <div class="form-group">
                <label>Encrypted Key (enc:... hoặc email:password)</label>
                <textarea class="form-control" id="newKeyEncrypted" rows="3"></textarea>
            </div>
            <div class="modal-actions">
                <button class="btn btn-primary" onclick="addKey()">Thêm</button>
                <button class="btn" style="background: var(--border);" onclick="closeModal('addKeyModal')">Hủy</button>
            </div>
            <div class="success-msg" id="addKeySuccess"></div>
            <div class="error-msg" id="addKeyError"></div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal" id="addUserModal">
        <div class="modal-content">
            <h2>Tạo khách hàng</h2>
            <div class="form-group">
                <label>Email</label>
                <input type="email" class="form-control" id="newUserEmail">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" class="form-control" id="newUserPassword">
            </div>
            <div class="form-group">
                <label>Plan</label>
                <select class="form-control" id="newUserPlan" onchange="autoFillQuota()">
                    <option value="trial">Trial</option>
                    <option value="basic" selected>Basic</option>
                    <option value="pro">Pro</option>
                    <option value="premium">Premium</option>
                </select>
            </div>
            <div class="form-group">
                <label>Quota (ký tự)</label>
                <input type="number" class="form-control" id="newUserQuota" value="10000">
            </div>
            <div class="form-group">
                <label>Thời hạn (ngày)</label>
                <input type="number" class="form-control" id="newUserDays" value="30">
            </div>
            <div class="modal-actions">
                <button class="btn btn-success" onclick="addUser()">Tạo</button>
                <button class="btn" style="background: var(--border);" onclick="closeModal('addUserModal')">Hủy</button>
            </div>
            <div class="success-msg" id="addUserSuccess"></div>
            <div class="error-msg" id="addUserError"></div>
        </div>
    </div>

    <!-- Add Package Modal -->
    <div class="modal" id="addPackageModal">
        <div class="modal-content" style="max-width: 500px;">
            <h2>✨ Thêm Gói Cước Mới</h2>
            <div class="form-group">
                <label>Plan ID (Viết liền, không dấu, ví dụ: supper_vip)</label>
                <input type="text" class="form-control" id="newPkgId" placeholder="supper_vip">
            </div>
            <div class="form-group">
                <label>Tên Gói (Ví dụ: Supper VIP)</label>
                <input type="text" class="form-control" id="newPkgName" placeholder="Supper VIP">
            </div>
            <div class="form-group">
                <label>Giá (VNĐ)</label>
                <input type="number" class="form-control" id="newPkgPrice" placeholder="500000">
            </div>
            <div class="form-group">
                <label>Giá USD ($) — để trống nếu không bán cho nước ngoài</label>
                <input type="number" step="0.01" class="form-control" id="newPkgPriceUsd" placeholder="1.99">
            </div>
            <div class="form-group">
                <label>Hạn mức (Ký tự)</label>
                <input type="number" class="form-control" id="newPkgQuota" placeholder="10000000">
            </div>
            <div class="form-group">
                <label>Hạn dùng (Ngày)</label>
                <input type="number" class="form-control" id="newPkgDays" value="9999">
            </div>
            <div class="modal-actions">
                <button class="btn btn-primary" onclick="addPackage()">Xác nhận thêm</button>
                <button class="btn" style="background: var(--border);"
                    onclick="closeModal('addPackageModal')">Hủy</button>
            </div>
            <div class="success-msg" id="addPackageSuccess"></div>
            <div class="error-msg" id="addPackageError"></div>
        </div>
    </div>

    <!-- Bulk Import Modal -->
    <div class="modal" id="bulkImportModal">
        <div class="modal-content" style="max-width: 700px;">
            <h2>📦 Import hàng loạt API Keys</h2>
            <div class="form-group">
                <label>Dán keys (mỗi key 1 dòng)</label>
                <textarea class="form-control" id="bulkKeysText" rows="10" placeholder="enc:xxx:yyy
enc:aaa:bbb
email1@example.com:password1
email2@example.com:password2"></textarea>
                <small style="color: var(--text-muted); display: block; margin-top: 5px;">
                    Hỗ trợ format: <code>enc:...</code>, <code>email:password</code>, hoặc <code>xi-...</code>
                </small>
            </div>
            <div class="modal-actions">
                <button class="btn btn-primary" onclick="bulkImport()">Import</button>
                <button class="btn" style="background: var(--border);"
                    onclick="closeModal('bulkImportModal')">Hủy</button>
            </div>
            <div id="bulkImportProgress" style="margin-top: 15px; display: none;">
                <div style="background: var(--border); border-radius: 8px; padding: 10px;">
                    <div style="font-size: 0.9rem; margin-bottom: 5px;">Đang xử lý...</div>
                    <div id="bulkImportStatus"></div>
                </div>
            </div>
            <div class="success-msg" id="bulkImportSuccess"></div>
            <div class="error-msg" id="bulkImportError"></div>
        </div>
    </div>

    <!-- Update Ngrok Modal -->
    <div class="modal" id="updateNgrokModal">
        <div class="modal-content">
            <h2>🔗 Cập nhật Ngrok URL</h2>
            <div class="form-group">
                <label>Ngrok URL Mới (từ Colab)</label>
                <input type="url" class="form-control" id="newNgrokUrl"
                    placeholder="https://xxxx-xx-xx-xx.ngrok-free.app">
            </div>
            <div class="modal-actions">
                <button class="btn btn-primary" onclick="saveNgrokUrl()">Lưu lại</button>
                <button class="btn" style="background: var(--border);"
                    onclick="closeModal('updateNgrokModal')">Hủy</button>
            </div>
            <div class="success-msg" id="updateNgrokSuccess"></div>
            <div class="error-msg" id="updateNgrokError"></div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal" id="editUserModal">
        <div class="modal-content">
            <h2>✏️ Sửa thông tin khách hàng</h2>
            <input type="hidden" id="editUserId">
            <div class="form-group">
                <label>Email</label>
                <input type="email" class="form-control" id="editUserEmail" readonly
                    style="background: var(--bg); opacity: 0.7;">
            </div>
            <div class="form-group">
                <label>Gói cước (Plan)</label>
                <select class="form-control" id="editUserPlan" onchange="autoFillQuotaEdit()">
                    <option value="trial">Trial (Dùng thử)</option>
                    <option value="basic">Basic (Cơ bản)</option>
                    <option value="pro">Pro (Chuyên nghiệp)</option>
                    <option value="premium">Premium (Cao cấp)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Biệt danh Gói (Dùng để hiển thị đè tên gốc)</label>
                <input type="text" class="form-control" id="editUserCustomPlan" placeholder="Super VIP,...">
            </div>
            <div class="form-group">
                <label>Quota Tổng (ký tự)</label>
                <input type="number" class="form-control" id="editUserQuota">
            </div>
            <div class="form-group">
                <label>Ngày hết hạn (YYYY-MM-DD)</label>
                <input type="date" class="form-control" id="editUserExpiry">
            </div>
            <div class="form-group">
                <label>Trạng thái</label>
                <select class="form-control" id="editUserStatus">
                    <option value="active">Hoạt động</option>
                    <option value="expired">Hết hạn</option>
                    <option value="inactive">Khóa</option>
                </select>
            </div>
            <div class="form-group">
                <label>Partner API Key (Dành cho đại lý kết nối Web riêng)</label>
                <div style="display: flex; gap: 10px;">
                    <input type="text" class="form-control" id="editUserPartnerKey" placeholder="Chưa cấp key">
                    <button class="btn btn-warning" style="width: auto; white-space: nowrap;"
                        onclick="generatePartnerKey()">
                        Tạo Key mới
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label>Loại Key API <span style="color:#888; font-size:0.8rem;">(Partner = đại lý, Developer = khách
                        API)</span></label>
                <select class="form-control" id="editUserKeyType">
                    <option value="partner">🏢 Partner (đại lý) — 200 req/phút</option>
                    <option value="developer">👨‍💻 Developer (khách API) — 20 req/phút</option>
                </select>
            </div>
            <div class="form-group">
                <label>Số luồng song song <span style="color:#888; font-size:0.8rem;">(Để trống = theo gói)</span></label>
                <input type="number" class="form-control" id="editUserMaxParallel" placeholder="Mặc định theo gói" min="1" max="50">
            </div>
            <div class="modal-actions">
                <button class="btn btn-primary" onclick="saveUserEdit()">Lưu thay đổi</button>
                <button class="btn" style="background: var(--border);"
                    onclick="closeModal('editUserModal')">Hủy</button>
            </div>
            <div class="success-msg" id="editUserSuccess"></div>
            <div class="error-msg" id="editUserError"></div>
        </div>
    </div>

    <script>
        const API_BASE = './api';
        let adminPassword = '';
        function adminHeaders(extra = {}) {
            return { 'X-Admin-Password': adminPassword, ...extra };
        }
        let isSavingSettings = false; // Flag to prevent UI overwrite during save

        async function harvestRevenue() {
            const amount = document.getElementById('statHarvestableBalance').innerText;
            if (!confirm(`Bạn có chắc chắn muốn chốt và rút ${amount} về túi không?\nHành động này sẽ ghi nhận bạn đã lấy tiền ra khỏi hệ thống để chi tiêu hoặc trả lương.`)) return;

            try {
                const res = await fetch(`${API_BASE}/admin/harvest_revenue.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        admin_password: adminPassword,
                        notes: 'Rút doanh thu hệ thống - Admin thực hiện'
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alert('✅ ' + data.message);
                    loadStats();
                } else {
                    alert('❌ ' + (data.error || 'Lỗi khi rút tiền'));
                }
            } catch (error) {
                alert('❌ Lỗi kết nối server');
            }
        }

        async function login() {
            adminPassword = document.getElementById('adminPassword').value;
            if (!adminPassword) {
                document.getElementById('authError').innerText = 'Vui lòng nhập password';
                return;
            }

            // Check if 2FA is enabled
            try {
                const res2fa = await fetch(`${API_BASE}/admin/setup_2fa.php`, {
                    method: 'POST',
                    headers: adminHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ action: 'status' })
                });
                const data2fa = await res2fa.json();

                if (data2fa.enabled) {
                    const totpGroup = document.getElementById('totpGroup');
                    const totpCode = document.getElementById('totpCode').value.trim();

                    if (!totpCode) {
                        // Show 2FA field and ask for code
                        totpGroup.style.display = 'block';
                        document.getElementById('totpCode').focus();
                        document.getElementById('authError').innerText = 'Nhập mã 6 số từ Google Authenticator';
                        return;
                    }

                    // Verify 2FA code
                    const verifyRes = await fetch(`${API_BASE}/admin/verify_2fa.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ admin_password: adminPassword, code: totpCode })
                    });
                    const verifyData = await verifyRes.json();

                    if (verifyData.status !== 'success') {
                        document.getElementById('authError').innerText = verifyData.error || 'Mã 2FA không đúng';
                        return;
                    }
                }
            } catch (e) {
                // If 2FA check fails, still allow login (backwards compatible)
                console.log('2FA check skipped:', e.message);
            }

            // Save to localStorage for persistent login
            localStorage.setItem('admin_password', adminPassword);
            loadStats();
        }

        function logout() {
            localStorage.removeItem('admin_password');
            location.reload();
        }

        // Auto-Refresh Logic
        let refreshTimer = null;
        function toggleAutoRefresh() {
            const isEnabled = document.getElementById('autoRefreshToggle').checked;
            localStorage.setItem('admin_auto_refresh', isEnabled ? '1' : '0');

            if (isEnabled) {
                startAutoRefresh();
            } else {
                stopAutoRefresh();
            }
        }

        async function refreshAllData() {
            if (isSavingSettings) return;

            const clockEl = document.getElementById('lastUpdated');
            const originalClock = clockEl ? clockEl.innerText : '';
            if (clockEl) clockEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <small>Đang tải...</small>';

            console.log("Auto-refreshing all data...");
            const promises = [];

            // Always refresh main stats
            promises.push(loadStats());

            // If health tab active, reload it specifically for masked keys and detailed worker stats
            if (document.getElementById('tab-health').classList.contains('active')) {
                promises.push(loadHealthStats());
            }

            try {
                // Wait for everything to finish
                await Promise.allSettled(promises);
            } finally {
                // Now update the visual indicator
                updateRefreshClock();
            }
        }

        function updateRefreshClock() {
            const now = new Date();
            const timeStr = `${now.getHours().toString().padStart(2, '0')}:${now.getMinutes().toString().padStart(2, '0')}:${now.getSeconds().toString().padStart(2, '0')}`;
            if (document.getElementById('lastUpdated')) {
                document.getElementById('lastUpdated').innerText = `Cập nhật: ${timeStr}`;
            }
        }

        function startAutoRefresh() {
            if (refreshTimer) clearInterval(refreshTimer);
            refreshTimer = setInterval(refreshAllData, 20000);
            // Also run once immediately
            refreshAllData();
        }

        function stopAutoRefresh() {
            if (refreshTimer) {
                clearInterval(refreshTimer);
                refreshTimer = null;
            }
        }

        // Initialize Auto-Refresh from localStorage
        function initAutoRefresh() {
            const saved = localStorage.getItem('admin_auto_refresh');
            // DEFAULT TO ON (1) if not set
            if (saved === '1' || saved === null) {
                document.getElementById('autoRefreshToggle').checked = true;
                startAutoRefresh();
            } else {
                document.getElementById('autoRefreshToggle').checked = false;
                stopAutoRefresh();
            }
        }

        // Initial Load handled by DOMContentLoaded
        window.addEventListener('DOMContentLoaded', function () {
            const savedPassword = localStorage.getItem('admin_password');
            if (savedPassword) {
                adminPassword = savedPassword;

                // Restore last active tab FIRST (before loading data)
                const lastTab = localStorage.getItem('admin_active_tab') || 'dashboard';
                switchTab(lastTab);

                // Then load data
                loadStats();
                // Initialize auto-refresh
                initAutoRefresh();
            }
        });

        function switchTab(tabId) {
            // Update buttons
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            const btn = document.getElementById(`btn-${tabId}`);
            if (btn) btn.classList.add('active');

            // Update content
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            const content = document.getElementById(`tab-${tabId}`);
            if (content) content.classList.add('active');

            // Save state
            localStorage.setItem('admin_active_tab', tabId);

            // Load data for specific tabs
            if (tabId === 'health') {
                loadHealthStats();
            } else if (tabId === 'logs') {
                loadLogs();
            } else if (tabId === 'settings') {
                loadSettings();
                loadSocialSettings(); // Ensure social settings also load
                load2FAStatus();
            } else if (tabId === 'ngrok') {
                if (typeof loadNgrokKeys === 'function') loadNgrokKeys();
                if (typeof startColabRefresh === 'function') startColabRefresh();
            } else if (tabId === 'keys') {
                loadKeyPool();
                restorePoolImportProgress();
            } else if (tabId === 'docs') {
                loadAdminDocs();
            }
        }

        let SUBSCRIPTION_PACKAGES = {};

        function autoFillQuota() {
            const plan = document.getElementById('newUserPlan').value;
            if (SUBSCRIPTION_PACKAGES[plan]) {
                document.getElementById('newUserQuota').value = SUBSCRIPTION_PACKAGES[plan].quota;
                document.getElementById('newUserDays').value = SUBSCRIPTION_PACKAGES[plan].days;
            }
        }

        function autoFillQuotaEdit() {
            const plan = document.getElementById('editUserPlan').value;
            if (SUBSCRIPTION_PACKAGES[plan]) {
                if (confirm("Bạn có muốn tự động cập nhật lại Quota và Hạn dùng theo gói mới không?")) {
                    document.getElementById('editUserQuota').value = SUBSCRIPTION_PACKAGES[plan].quota;

                    // Update expiry based on days
                    const days = parseInt(SUBSCRIPTION_PACKAGES[plan].days);
                    const expiryDate = new Date();
                    expiryDate.setDate(expiryDate.getDate() + days);
                    const formattedDate = expiryDate.toISOString().split('T')[0];
                    document.getElementById('editUserExpiry').value = formattedDate;
                }
            }
        }

        function updatePackagesList(packages) {
            SUBSCRIPTION_PACKAGES = packages;
            const tbody = document.getElementById('packagesTable');

            if (!packages || Object.keys(packages).length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--error);">Chưa có gói nào trong DB. Vui lòng bấm Setup DB phía dưới.</td></tr>';
                return;
            }

            tbody.innerHTML = Object.keys(packages).map(id => {
                const pkg = packages[id];
                const hasSrt = (pkg.features && pkg.features.includes('srt_download')) ? 'checked' : '';
                const priceUsdVal = pkg.price_usd !== null && pkg.price_usd !== undefined ? pkg.price_usd : '';

                return `
                <tr>
                    <td><code>${id}</code></td>
                    <td>${pkg.name}</td>
                    <td><input type="number" class="form-control" style="width: 120px;" id="pkg_quota_${id}" value="${pkg.quota}"></td>
                    <td><input type="number" class="form-control" style="width: 80px;" id="pkg_days_${id}" value="${pkg.days}"></td>
                    <td><input type="number" class="form-control" style="width: 110px;" id="pkg_price_${id}" value="${pkg.price}" placeholder="0"></td>
                    <td><input type="number" step="0.01" class="form-control" style="width: 90px;" id="pkg_usd_${id}" value="${priceUsdVal}" placeholder="-"></td>
                    <td style="text-align: center;">
                        <input type="checkbox" id="pkg_srt_${id}" style="width: 20px; height: 20px; cursor: pointer;" ${hasSrt}>
                    </td>
                    <td>
                        <button class="btn btn-success" style="padding: 5px 12px; font-size: 0.85rem;" onclick="updatePackage('${id}')">
                            <i class="fa-solid fa-save"></i> Lưu
                        </button>
                    </td>
                </tr>
            `}).join('');

            // Update user creation plan select
            const userPlanSelect = document.getElementById('newUserPlan');
            const editUserPlanSelect = document.getElementById('editUserPlan');

            const options = Object.keys(packages).map(id => `<option value="${id}">${packages[id].name}</option>`).join('');
            if (userPlanSelect) userPlanSelect.innerHTML = options;
            if (editUserPlanSelect) editUserPlanSelect.innerHTML = options;
        }

        async function updatePackage(planId) {
            const quota = document.getElementById(`pkg_quota_${planId}`).value;
            const days = document.getElementById(`pkg_days_${planId}`).value;
            const priceVnd = document.getElementById(`pkg_price_${planId}`).value;
            const priceUsd = document.getElementById(`pkg_usd_${planId}`).value;
            const srtEnabled = document.getElementById(`pkg_srt_${planId}`).checked;

            try {
                const res = await fetch(`${API_BASE}/admin/update_package.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        admin_password: adminPassword,
                        plan_id: planId,
                        quota: parseInt(quota),
                        days: parseInt(days),
                        price: parseInt(priceVnd) || 0,
                        price_usd: priceUsd !== '' ? parseFloat(priceUsd) : null,
                        srt_enabled: srtEnabled
                    })
                });

                const data = await res.json();
                if (res.ok && data.status === 'success') {
                    alert('Cập nhật gói thành công!');
                    loadStats();
                } else {
                    alert('Lỗi: ' + data.error);
                }
            } catch (error) {
                alert('Lỗi kết nối');
            }
        }

        async function addPackage() {
            const planId = document.getElementById('newPkgId').value.trim();
            const name = document.getElementById('newPkgName').value.trim();
            const price = document.getElementById('newPkgPrice').value;
            const priceUsd = document.getElementById('newPkgPriceUsd').value;
            const quota = document.getElementById('newPkgQuota').value;
            const days = document.getElementById('newPkgDays').value;
            const successEl = document.getElementById('addPackageSuccess');
            const errorEl = document.getElementById('addPackageError');

            if (!planId || !name) {
                errorEl.innerText = 'Vui lòng nhập Plan ID và Tên Gói';
                return;
            }

            try {
                const res = await fetch(`${API_BASE}/admin/create_package.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        admin_password: adminPassword,
                        plan_id: planId,
                        name: name,
                        price: parseInt(price),
                        price_usd: priceUsd !== '' ? parseFloat(priceUsd) : null,
                        quota: parseInt(quota),
                        days: parseInt(days)
                    })
                });

                const data = await res.json();
                if (res.ok && data.status === 'success') {
                    successEl.innerText = '✅ Thêm gói thành công!';
                    setTimeout(() => {
                        closeModal('addPackageModal');
                        loadStats();
                    }, 1500);
                } else {
                    errorEl.innerText = data.error || 'Lỗi khi thêm gói';
                }
            } catch (error) {
                errorEl.innerText = 'Lỗi kết nối';
            }
        }

        function showAddUserModal() {
            document.getElementById('addUserModal').style.display = 'flex';
            autoFillQuota();
        }

        async function loadStats() {
            try {
                const res = await fetch(`${API_BASE}/admin/stats.php?_=${Date.now()}`, { headers: adminHeaders() });
                const data = await res.json();

                if (res.ok && data.status === 'success') {
                    // Trigger async background check (Heartbeat) - uses admin auth
                    fetch(`${API_BASE}/cron.php`, { headers: adminHeaders() }).catch(e => console.log("Background cron trigger: " + e.message));

                    document.getElementById('authScreen').style.display = 'none';
                    document.getElementById('dashboard').style.display = 'block';

                    // Update stats
                    document.getElementById('statTotalUsers').innerText = Number(data.summary.users.total_users || 0).toLocaleString('vi-VN');
                    document.getElementById('statActiveUsers').innerText = Number(data.summary.users.active_users || 0).toLocaleString('vi-VN');
                    document.getElementById('statActiveKeys').innerText = Number(data.summary.keys.active_keys || 0).toLocaleString('vi-VN');
                    const totalCredits = Number(data.summary.keys.active_credits || 0);
                    document.getElementById('statTotalCredits').innerText = totalCredits.toLocaleString('vi-VN');

                    // Update Header Credits (so it's visible on every tab)
                    if (document.getElementById('headerTotalCredits')) {
                        document.getElementById('headerTotalCredits').innerText = totalCredits.toLocaleString('vi-VN') + ' pts';
                    }

                    // Sync to Health tab as well if element exists
                    if (document.getElementById('healthTotalCredits')) {
                        document.getElementById('healthTotalCredits').innerText = totalCredits.toLocaleString('vi-VN');
                    }

                    document.getElementById('statTodayConversions').innerText = Number(data.summary.today.conversions_today || 0).toLocaleString('vi-VN');
                    document.getElementById('statTodayChars').innerText = Number(data.summary.today.chars_today || 0).toLocaleString('vi-VN');
                    
                    if (document.getElementById('statYesterdayChars') && data.summary.yesterday) {
                        document.getElementById('statYesterdayChars').innerText = Number(data.summary.yesterday.chars_yesterday || 0).toLocaleString('vi-VN');
                    }

                    const totalRev = parseFloat(data.summary.revenue.total_revenue || 0);
                    const todayRev = parseFloat(data.summary.revenue.revenue_today || 0);
                    if (document.getElementById('statTotalRevenue')) {
                        document.getElementById('statTotalRevenue').innerText = Number(data.summary.revenue.total_revenue || 0).toLocaleString('vi-VN') + 'đ';
                    }

                    // Harvestable Balance logic
                    const harvestableBalance = document.getElementById('statHarvestableBalance');
                    if (harvestableBalance) {
                        harvestableBalance.innerText = Number(data.summary.revenue.harvestable_balance || 0).toLocaleString('vi-VN') + 'đ';
                    }
                    document.getElementById('statTodayRevenue').innerText = todayRev.toLocaleString('vi-VN') + 'đ';

                    // Update tables
                    updateKeysTable(data.api_keys, data.workers);
                    updateUsersTable(data.top_users);
                    updateLogsTable(data.recent_logs, data.workers);
                    updatePaymentsTable(data.recent_payments);
                    updateWorkersTable(data.workers);
                    updateWorkerEventsTable(data.logs.worker_events);
                    updatePackagesList(data.packages);
                    if (typeof updateIpBlockLogs === 'function') updateIpBlockLogs(data.ip_block_logs);

                    // Update System Settings UI
                    if (data.settings) {
                        document.getElementById('tgToken').value = data.settings.telegram_bot_token || '';
                        document.getElementById('tgChatId').value = data.settings.telegram_chat_id || '';
                        document.getElementById('tgEnabled').checked = data.settings.telegram_enabled == '1';
                        document.getElementById('tgToken2').value = data.settings.telegram_bot_token_2 || '';
                    }

                    // Update Logs if we are on logs tab or just load them if visible
                    if (data.logs) {
                        if (document.getElementById('errorLogsContent'))
                            document.getElementById('errorLogsContent').innerText = data.logs.key_errors || '';
                        if (document.getElementById('failedLogsContent'))
                            document.getElementById('failedLogsContent').innerText = data.logs.failed || '';
                        if (document.getElementById('completedLogsContent'))
                            document.getElementById('completedLogsContent').innerText = data.logs.completed || '';
                    }

                    // Update Setup Links in Settings Tab
                    const setupPass = encodeURIComponent(adminPassword);
                    document.getElementById('linkSetupWorkers').href = `api/admin/setup_workers.php?admin_password=${setupPass}`;
                    document.getElementById('linkSetupLogs').href = `api/admin/setup_logs_worker.php?admin_password=${setupPass}`;
                } else {
                    document.getElementById('authError').innerText = data.error || 'Sai password';
                }
            } catch (error) {
                document.getElementById('authError').innerText = 'Lỗi kết nối';
            }
        }

        function updateKeysTable(keys, workers) {
            const tbody = document.getElementById('keysTable');
            if (!keys || keys.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">Chưa có key nào</td></tr>';
                return;
            }
            // Build UUID → worker_name map
            const workerNameMap = {};
            if (workers && workers.length) {
                workers.forEach(w => { if (w.worker_uuid) workerNameMap[w.worker_uuid] = w.worker_name || w.worker_uuid.substring(0, 8); });
            }
            // Preserve checkbox selection
            const selectedIds = new Set(Array.from(document.getElementsByClassName('key-checkbox'))
                .filter(cb => cb.checked)
                .map(cb => cb.value));

            tbody.innerHTML = keys.map(k => `
                <tr>
                    <td><input type="checkbox" class="key-checkbox" value="${k.id}" ${selectedIds.has(String(k.id)) ? 'checked' : ''}></td>
                    <td>${k.id}</td>
                    <td><code>${k.key_preview}</code></td>
                    <td id="key-credits-${k.id}" style="font-weight:700;">${Number(k.credits_remaining || 0).toLocaleString('vi-VN')}</td>
                    <td><span class="badge badge-${k.status === 'active' ? 'success' : 'error'}">${k.status}</span></td>
                    <td>
                        ${k.assigned_worker_uuid ? `<span class="badge" style="background:#2563eb; color:#fff;" title="${k.assigned_worker_uuid}">${workerNameMap[k.assigned_worker_uuid] || k.assigned_worker_uuid.substring(0, 8) + '...'}</span>` : '<span style="color:var(--text-muted)">-</span>'}
                    </td>
                    <td><small title="Ngày tạo: ${k.created_at || '-'}">${k.reset_at ? new Date(k.reset_at).toLocaleDateString('vi-VN') : '-'}</small></td>
                    <td id="key-check-${k.id}"><small>${k.last_checked || '-'}</small></td>
                    <td>
                        <div style="display: flex; gap: 5px;">
                            <button class="btn" style="background: #3b82f6; color: white; padding: 5px 10px; font-size: 0.85rem;" onclick="checkKeyCredits(${k.id}, this)">
                                <i class="fa-solid fa-sync"></i>
                            </button>
                            <button class="btn" style="background: var(--error); color: white; padding: 5px 10px; font-size: 0.85rem;" onclick="deleteKey(${k.id})">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        async function deleteKey(keyId) {
            if (!confirm('Bạn có chắc muốn xóa key này?')) return;

            try {
                const res = await fetch(`${API_BASE}/admin/delete_key.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        admin_password: adminPassword,
                        key_id: keyId
                    })
                });

                const data = await res.json();

                if (res.ok && data.status === 'success') {
                    alert('✅ Đã xóa key thành công!');
                    loadStats(); // Reload data
                } else {
                    alert('❌ Lỗi: ' + (data.error || 'Không thể xóa key'));
                }
            } catch (error) {
                alert('❌ Lỗi kết nối: ' + error.message);
            }
        }

        async function reloadInactiveKeys() {
            if (!confirm('Reset tất cả key lỗi (inactive/cooldown) về trạng thái active?')) return;
            try {
                const res = await fetch(`${API_BASE}/admin/reload_keys.php`, {
                    method: 'POST',
                    headers: adminHeaders(),
                    body: JSON.stringify({ admin_password: adminPassword })
                });
                const data = await res.json();
                if (res.ok && data.status === 'success') {
                    alert(`✅ Đã reload ${data.reactivated} key inactive và xóa cooldown ${data.cooldown_cleared} key!`);
                    loadStats();
                } else {
                    alert('❌ Lỗi: ' + (data.error || 'Không thể reload'));
                }
            } catch (error) {
                alert('❌ Lỗi kết nối: ' + error.message);
            }
        }

        function filterUsersTable() {
            const input = document.getElementById("userSearchInput");
            const filter = input.value.toLowerCase();
            const table = document.getElementById("usersTable");
            const tr = table.getElementsByTagName("tr");

            // Loop through all table rows, and hide those who don't match the search query
            for (let i = 0; i < tr.length; i++) {
                // Ignore the "Loading..." or "Chưa có user nào" row
                if (tr[i].cells.length === 1) continue;

                // Email is in the first column (index 0)
                const td = tr[i].getElementsByTagName("td")[0];
                if (td) {
                    const txtValue = td.textContent || td.innerText;
                    if (txtValue.toLowerCase().indexOf(filter) > -1) {
                        tr[i].style.display = "";
                    } else {
                        tr[i].style.display = "none";
                    }
                }
            }
        }

        function updateUsersTable(users) {
            const tbody = document.getElementById('usersTable');
            if (!users || users.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align: center;">Chưa có user nào</td></tr>';
                return;
            }
            tbody.innerHTML = users.map(u => {
                let planBadgeClass = 'badge-trial';
                const p = (u.plan || '').toLowerCase();
                if (p === 'premium') planBadgeClass = 'badge-premium';
                else if (p === 'pro') planBadgeClass = 'badge-pro';

                let displayPlan = `<span class="badge ${planBadgeClass}">${u.plan.toUpperCase()}</span>`;
                if (u.custom_plan_name) {
                    displayPlan = `<span class="badge" style="background: linear-gradient(135deg, #d4af37, #f3e5ab); color: #000; border: 1px solid #c5a017; font-weight: bold;">${u.custom_plan_name.toUpperCase()}</span> <br><span style="font-size: 0.7rem; color: var(--text-muted);">(Gốc: ${u.plan.toUpperCase()})</span>`;
                } else {
                    if (p === 'supper_vip' || p === 'suppervip' || p === 'supper vip') {
                        displayPlan = `<span class="badge" style="background: linear-gradient(135deg, #d4af37, #f3e5ab); color: #000; border: 1px solid #c5a017; font-weight: bold;">${u.plan.toUpperCase()}</span>`;
                    } else if (p === 'basic') {
                        displayPlan = `<span class="badge" style="background: #3b82f6; color: white;">${u.plan.toUpperCase()}</span>`;
                    }
                }

                return `
                <tr>
                    <td>${u.email}</td>
                    <td>${displayPlan}</td>
                    <td style="${Number(u.today_used || 0) > 0 ? 'color: #f59e0b; font-weight: 600;' : 'color: var(--text-muted);'}">${Number(u.today_used || 0).toLocaleString('vi-VN')}</td>
                    <td>${Number(u.quota_used || 0).toLocaleString('vi-VN')}</td>
                    <td>${Number(u.quota_total || 0).toLocaleString('vi-VN')}</td>
                    <td>${new Date(u.expires_at).toLocaleDateString('vi-VN')}</td>
                    <td>
                        <span class="badge ${u.status === 'active' ? 'badge-success' : (u.status === 'inactive' ? 'badge-warning' : 'badge-error')}">
                            ${u.status}
                        </span>
                    </td>
                    <td>
                        ${u.parent_email ? `
                            <div style="font-size: 0.8rem; line-height: 1.2;">
                                <span class="badge" style="background: #3b82f6; color: white; padding: 2px 6px; font-size: 0.65rem; margin-bottom: 2px;">MEMBER</span><br>
                                <span title="Chủ nhóm: ${u.parent_email}">${u.parent_email}</span>
                            </div>
                        ` : '<span style="color: var(--text-muted); font-size: 0.8rem;">-</span>'}
                    </td>
                    <td>
                        <div style="display: flex; gap: 5px;">
                            <button class="btn" style="background: var(--primary); color: white; padding: 5px 10px; font-size: 0.8rem;" onclick='openEditUserModal(${JSON.stringify(u)})'>
                                <i class="fa-solid fa-pen"></i> Sửa
                            </button>
                            <button class="btn" style="background: var(--error); color: white; padding: 5px 10px; font-size: 0.8rem;" onclick="deleteUser(${u.id})">
                                <i class="fa-solid fa-trash"></i> Xóa
                            </button>
                        </div>
                    </td>
                </tr>
            `}).join('');

            // Re-apply filter if user is searching while data updates
            filterUsersTable();
        }

        async function deleteUser(userId) {
            if (!confirm('Bạn có chắc muốn xóa khách hàng này?')) return;
            try {
                const res = await fetch(`${API_BASE}/admin/delete_user.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ admin_password: adminPassword, user_id: userId })
                });
                const data = await res.json();
                if (res.ok && data.status === 'success') {
                    alert('✅ Đã xóa khách hàng!');
                    loadStats();
                } else {
                    alert('❌ Lỗi: ' + (data.error || 'Không thể xóa'));
                }
            } catch (error) {
                alert('❌ Lỗi kết nối: ' + error.message);
            }
        }

        function openEditUserModal(user) {
            document.getElementById('editUserId').value = user.id;
            document.getElementById('editUserEmail').value = user.email;

            // Auto-select trial for new inactive users, otherwise use their current plan
            const currentPlan = (user.plan || 'trial').toLowerCase();
            const planToSet = (user.status === 'inactive' && currentPlan === 'trial') ? 'trial' : currentPlan;
            document.getElementById('editUserPlan').value = planToSet;

            // Custom Plan name
            document.getElementById('editUserCustomPlan').value = user.custom_plan_name || '';

            document.getElementById('editUserQuota').value = user.quota_total;
            document.getElementById('editUserExpiry').value = user.expires_at.split(' ')[0];

            // Auto-set status to active for inactive users (to activate them)
            const statusToSet = (user.status === 'inactive') ? 'active' : user.status;
            document.getElementById('editUserStatus').value = statusToSet;
            document.getElementById('editUserPartnerKey').value = user.partner_api_key || '';
            document.getElementById('editUserKeyType').value = user.key_type || 'partner';
            document.getElementById('editUserMaxParallel').value = user.max_parallel || '';

            document.getElementById('editUserSuccess').innerText = '';
            document.getElementById('editUserError').innerText = '';
            document.getElementById('editUserModal').style.display = 'flex';
        }

        async function saveUserEdit() {
            const userId = document.getElementById('editUserId').value;
            const quota = document.getElementById('editUserQuota').value;
            const plan = document.getElementById('editUserPlan').value;
            const customPlan = document.getElementById('editUserCustomPlan').value.trim();
            const expiry = document.getElementById('editUserExpiry').value;
            const status = document.getElementById('editUserStatus').value;
            const partnerKey = document.getElementById('editUserPartnerKey').value.trim();
            const successEl = document.getElementById('editUserSuccess');
            const errorEl = document.getElementById('editUserError');

            try {
                const res = await fetch(`${API_BASE}/admin/edit_user.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        admin_password: adminPassword,
                        user_id: userId,
                        quota_total: quota,
                        plan: plan,
                        custom_plan_name: customPlan,
                        expires_at: expiry + ' 23:59:59',
                        status: status,
                        partner_api_key: partnerKey,
                        key_type: document.getElementById('editUserKeyType').value,
                        max_parallel: document.getElementById('editUserMaxParallel').value || null
                    })
                });
                const data = await res.json();
                if (res.ok && data.status === 'success') {
                    successEl.innerText = '✅ Cập nhật thành công!';
                    setTimeout(() => {
                        closeModal('editUserModal');
                        loadStats();
                    }, 1000);
                } else {
                    errorEl.innerText = data.error || 'Lỗi cập nhật';
                }
            } catch (error) {
                errorEl.innerText = 'Lỗi kết nối';
            }
        }

        function updateLogsTable(logs, workers) {
            const tbody = document.getElementById('logsTable');
            if (!logs || logs.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align: center;">Chưa có hoạt động</td></tr>';
                return;
            }
            // Build UUID → worker_name map
            const workerNameMap = {};
            if (workers && workers.length) {
                workers.forEach(w => { if (w.worker_uuid) workerNameMap[w.worker_uuid] = w.worker_name || w.worker_uuid.substring(0, 8); });
            }
            tbody.innerHTML = logs.map(l => {
                let statusBadge = '';
                const s = (l.job_status || '').toLowerCase();
                if (s === 'completed') {
                    statusBadge = '<span class="badge badge-success">✅ Hoàn thành</span>';
                } else if (s.startsWith('failed')) {
                    const reason = s.replace('failed:', '').replace('failed', 'Lỗi').trim();
                    statusBadge = `<span class="badge badge-danger" title="${reason}">❌ Lỗi</span>`;
                } else if (s.startsWith('processing')) {
                    const subStatus = s.replace('processing:', '').trim() || 'Đang xử lý';
                    statusBadge = `<span class="badge badge-warning" title="${subStatus}">⏳ ${subStatus.substring(0, 20)}${subStatus.length > 20 ? '...' : ''}</span> <button onclick="cancelJob('${l.id}')" style="background:#e74c3c;color:white;border:none;padding:3px 8px;border-radius:6px;font-size:0.75rem;cursor:pointer;font-weight:600;" title="Hủy job và hoàn trả điểm">🚫 Hủy</button>`;
                } else if (s === 'pending') {
                    statusBadge = '<span class="badge" style="background: #3498db; color: white;">🕒 Chờ xử lý</span> <button onclick="retryPendingJob(\''+l.id+'\')" style="background:#f59e0b;color:white;border:none;padding:3px 8px;border-radius:6px;font-size:0.75rem;cursor:pointer;font-weight:600;" title="Reset và dispatch lại">↻ Chạy lại</button> <button onclick="cancelJob(\''+l.id+'\')" style="background:#e74c3c;color:white;border:none;padding:3px 8px;border-radius:6px;font-size:0.75rem;cursor:pointer;font-weight:600;" title="Hủy job">🚫 Hủy</button>';
                } else if (s === 'retrying') {
                    statusBadge = '<span class="badge" style="background: #8b5cf6; color: white;">🔁 Đang thử lại</span> <button onclick="cancelJob(\''+l.id+'\')" style="background:#e74c3c;color:white;border:none;padding:3px 8px;border-radius:6px;font-size:0.75rem;cursor:pointer;font-weight:600;" title="Hủy job">🚫 Hủy</button>';
                } else if (s === 'canceled' || s === 'cancelled') {
                    statusBadge = '<span class="badge" style="background: #6b7280; color: white;">🚫 Bị hủy</span>';
                } else {
                    statusBadge = `<span class="badge" style="background:var(--border);color:var(--text-muted)" title="${s}">—</span>`;
                }
                const workerName = l.worker_uuid ? (workerNameMap[l.worker_uuid] || l.worker_uuid.substring(0, 8) + '...') : null;
                const workerDisplay = workerName
                    ? `<span class="badge" style="background: #2563eb; color: white; font-size: 0.8rem;" title="${l.worker_uuid}">${workerName}</span>`
                    : '<span style="color: var(--text-muted);">-</span>';
                return `
                <tr>
                    <td>${l.id}</td>
                    <td>${l.email}</td>
                    <td>${Number(l.characters_used || 0).toLocaleString('vi-VN')}</td>
                    <td>${statusBadge}</td>
                    <td><span class="badge" style="background:#444; color:#fff; font-size:0.75rem;">${l.voice_id || '-'}</span></td>
                    <td>${workerDisplay}</td>
                    <td>${l.text_preview || '-'}</td>
                    <td>${new Date(l.created_at).toLocaleString('vi-VN')}</td>
                </tr>
            `}).join('');
        }

        async function retryPendingJob(jobId) {
            try {
                const res = await fetch(`${API_BASE}/admin/cancel_job.php`, {
                    method: 'POST',
                    headers: adminHeaders({'Content-Type': 'application/json'}),
                    body: JSON.stringify({ job_id: jobId, action: 'retry' })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alert(`✅ ${data.message}`);
                } else {
                    alert('❌ ' + (data.error || 'Lỗi'));
                }
                loadStats();
            } catch (e) {
                alert('Lỗi: ' + e.message);
            }
        }

        async function cancelJob(jobId) {
            if (!confirm(`Hủy Job ${jobId}? Điểm sẽ được hoàn trả cho người dùng.`)) return;
            try {
                const res = await fetch(`${API_BASE}/admin/cancel_job.php`, {
                    method: 'POST',
                    headers: adminHeaders({'Content-Type': 'application/json'}),
                    body: JSON.stringify({ job_id: jobId, action: 'cancel' })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alert(`✅ ${data.message}`);
                } else {
                    alert('❌ ' + (data.error || 'Lỗi'));
                }
                loadStats();
            } catch (e) {
                alert('Lỗi: ' + e.message);
            }
        }

        function updateWorkersTable(workers) {
            const tbody = document.getElementById('workersTable');
            if (!tbody) return;

            const activeWorkers = workers ? workers.filter(w => w.status === 'active') : [];

            // Alert logic for admin panel
            const banner = document.getElementById('offlineBanner');
            const alertSound = document.getElementById('offlineAlertSound');

            if (activeWorkers.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">Chưa có máy nào online</td></tr>';
                if (banner) {
                    if (banner.style.display === 'none') {
                        // Play sound only once when first going offline
                        if (alertSound) alertSound.play().catch(e => console.log("Sound play blocked"));
                    }
                    banner.style.display = 'block';
                }
                return;
            } else {
                if (banner) banner.style.display = 'none';
            }

            tbody.innerHTML = activeWorkers.map(w => {
                const statusClass = 'badge-success';

                // Format connected_at (Online Since)
                const onlineSince = w.connected_at
                    ? new Date(w.connected_at).toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' })
                    : '-';

                // Calculate Uptime
                let uptimeText = '-';
                if (w.connected_at) {
                    const start = new Date(w.connected_at).getTime();
                    const now = new Date().getTime();
                    const diff = Math.floor((now - start) / 1000);
                    const h = floor(diff / 3600);
                    const m = floor((diff % 3600) / 60);
                    uptimeText = `${h}h ${m}m`;
                }

                return `
                <tr>
                    <td><small>${w.worker_uuid}</small></td>
                    <td><a href="${w.url}" target="_blank" style="color: var(--primary);">${w.url}</a></td>
                    <td><span class="badge ${statusClass}">${w.status.toUpperCase()}</span></td>
                    <td style="font-weight: 600;">${onlineSince}</td>
                    <td>${new Date(w.last_seen).toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}</td>
                    <td style="font-family: monospace; color: var(--success); font-weight: 700;">${uptimeText}</td>
                </tr>
            `}).join('');
        }

        function updateWorkerEventsTable(events) {
            const tbody = document.getElementById('workerEventsTable');
            if (!tbody) return;
            if (!events || events.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: var(--text-muted);">Đang đợi dữ liệu...</td></tr>';
                return;
            }

            tbody.innerHTML = events.map(e => {
                let color = 'var(--text)';
                if (e.level === 'error') color = 'var(--error)';
                if (e.level === 'warning') color = '#f59e0b';
                if (e.message.includes('Hoàn thành')) color = 'var(--success)';

                const time = new Date(e.created_at).toLocaleTimeString('vi-VN');
                const worker = e.worker_name || (e.worker_uuid ? e.worker_uuid.substring(0, 8) : 'Unknown');

                return `
                    <tr style="color: ${color}; border-bottom: 1px solid #111;">
                        <td style="white-space:nowrap"><small>${time}</small></td>
                        <td><span style="color: yellow">${worker}</span></td>
                        <td><small style="font-family: -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif; font-weight: 600">${e.job_id || '-'}</small></td>
                        <td>${e.message}</td>
                    </tr>
                `;
            }).join('');
        }

        async function retryFailedJobs() {
            if (!confirm('Bạn có chắc muốn THỬ LẠI TẤT CẢ các Job đang ở trạng thái LỖI (trong 24h qua)?')) return;

            const btn = event.currentTarget;
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Đang khởi động lại...';
            btn.disabled = true;

            try {
                const res = await fetch(`api/cron.php?debug=1&retry_failed=1`, { headers: adminHeaders() });
                const text = await res.text();

                let message = "Yêu cầu đã được gửi!";
                if (text.includes("Successfully reset")) {
                    const match = text.match(/Successfully reset (\d+) failed jobs/);
                    if (match) {
                        message = `✅ Đã đưa ${match[1]} Job lỗi quay lại hàng chờ xử lý!`;
                    }
                }

                alert(message);
                loadStats();
            } catch (e) {
                alert('Lỗi: ' + e.message);
            } finally {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        }

        async function forceResetJobs() {
            if (!confirm('Hành động này sẽ ép buộc toàn bộ các Job "Đang xử lý" quay về trạng thái "Chờ xử lý" ngay lập tức để chuyển sang máy khác. Bạn có chắc chắn không?')) return;

            const btn = event.currentTarget;
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Đang giải cứu...';
            btn.disabled = true;

            try {
                const res = await fetch(`api/cron.php?debug=1&force=1`, { headers: adminHeaders() });
                const text = await res.text();

                // Simple parser for the text response to show a nice message
                let message = "Hệ thống cứu hộ đã được kích hoạt!";
                if (text.includes("Successfully reset")) {
                    const match = text.match(/Successfully reset (\d+) stuck jobs/);
                    if (match) {
                        message = `✅ Đã giải cứu thành công ${match[1]} Job bị kẹt! Hãy chờ vài phút để máy khác nhận việc.`;
                    }
                } else if (text.includes("No pending jobs found")) {
                    message = "Hệ thống đã quét nhưng không thấy Job nào cần giải cứu lúc này.";
                }

                alert(message);
                loadStats(); // Reload to update UI
            } catch (e) {
                alert("Lỗi kết nối khi thực hiện cứu hộ: " + e.message);
            } finally {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        }

        // Helper for floor if not globally available or as shorthand
        function floor(n) { return Math.floor(n); }

        async function saveTelegramSettings() {
            const token = document.getElementById('tgToken').value;
            const chatId = document.getElementById('tgChatId').value;
            const enabled = document.getElementById('tgEnabled').checked ? '1' : '0';
            const statusDiv = document.getElementById('tgStatus');

            statusDiv.innerHTML = '<span style="color: var(--text-muted)">Đang lưu...</span>';

            try {
                // Since we don't have a dedicated settings API yet, we can use update_ngrok.php logic or a dedicated simple one.
                // Let's create a quick API for overall settings or reuse update_ngrok logic for multi-params.
                // For simplicity, I'll create a new small API: api/admin/update_settings.php
                const res = await fetch(`${API_BASE}/admin/update_settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        admin_password: adminPassword,
                        settings: {
                            telegram_bot_token: token,
                            telegram_chat_id: chatId,
                            telegram_enabled: enabled,
                            telegram_bot_token_2: document.getElementById('tgToken2').value.trim()
                        }
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    statusDiv.innerHTML = '<span style="color: var(--success)">Đã lưu thành công!</span>';
                    setTimeout(() => statusDiv.innerHTML = '', 3000);
                } else {
                    statusDiv.innerHTML = `<span style="color: var(--danger)">Lỗi: ${data.error}</span>`;
                }
            } catch (e) {
                statusDiv.innerHTML = `<span style="color: var(--danger)">Lỗi kết nối</span>`;
            }
        }

        function updatePaymentsTable(payments) {
            const tbody = document.getElementById('paymentsTable');
            if (!payments || payments.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">Chưa có giao dịch nào</td></tr>';
                return;
            }
            tbody.innerHTML = payments.map(p => {
                let statusClass = 'badge-warning';
                if (p.status === 'completed') statusClass = 'badge-success';
                if (p.status === 'sent') statusClass = 'badge-primary';

                return `
                <tr>
                    <td>${p.id}</td>
                    <td>${p.email}</td>
                    <td><span class="badge ${p.plan_id === 'pro' ? 'badge-warning' : 'badge-success'}">${p.plan_id.toUpperCase()}</span></td>
                    <td>${Number(p.amount || 0).toLocaleString('vi-VN')}đ</td>
                    <td><span class="badge ${statusClass}">${p.status.toUpperCase()}</span></td>
                    <td>${new Date(p.created_at).toLocaleString('vi-VN')}</td>
                    <td>
                        <div style="display: flex; gap: 5px;">
                            ${(p.status === 'sent' || p.status === 'pending') ? `
                                <button class="btn btn-success" style="padding: 4px 8px; font-size: 0.8rem;" onclick="approvePayment(${p.id})">
                                    <i class="fa-solid fa-check"></i> Duyệt
                                </button>` : ''}
                            <button class="btn btn-danger" style="padding: 4px 8px; font-size: 0.8rem; background: #e74c3c;" onclick="deletePayment(${p.id})">
                                <i class="fa-solid fa-trash"></i> Xóa
                            </button>
                        </div>
                    </td>
                </tr>
            `}).join('');
        }

        async function approvePayment(paymentId) {
            if (!confirm('Xác nhận duyệt thanh toán này? Người dùng sẽ được nâng cấp gói ngay lập tức.')) return;

            try {
                const res = await fetch(`${API_BASE}/admin/approve_payment.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        admin_password: adminPassword,
                        payment_id: paymentId
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alert('✅ Đã duyệt!');
                    loadStats();
                } else {
                    alert(data.error || 'Lỗi khi duyệt');
                }
            } catch (error) {
                alert('Lỗi kết nối');
            }
        }

        async function deletePayment(paymentId) {
            if (!confirm('Bạn có chắc muốn xóa giao dịch này không? Hành động này không thể hoàn tác.')) return;

            try {
                const res = await fetch(`${API_BASE}/admin/delete_payment.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        admin_password: adminPassword,
                        payment_id: paymentId
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alert('✅ Đã xóa giao dịch!');
                    loadStats();
                } else {
                    alert(data.error || 'Lỗi khi xóa');
                }
            } catch (error) {
                alert('Lỗi kết nối server');
            }
        }

        function showAddKeyModal() {
            document.getElementById('addKeyModal').style.display = 'flex';
        }

        function showAddUserModal() {
            document.getElementById('addUserModal').style.display = 'flex';
        }

        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function toggleAllKeys(source, className) {
            const checkboxes = document.getElementsByClassName(className);
            for (let i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = source.checked;
            }
        }

        // Shift+Click multi-select for key checkboxes
        (function () {
            let lastCheckedIndex = { 'key-checkbox': -1, 'health-key-checkbox': -1 };

            document.addEventListener('click', function (e) {
                const cb = e.target;
                if (!cb.classList.contains('key-checkbox') && !cb.classList.contains('health-key-checkbox')) return;

                const className = cb.classList.contains('key-checkbox') ? 'key-checkbox' : 'health-key-checkbox';
                const checkboxes = Array.from(document.getElementsByClassName(className));
                const currentIndex = checkboxes.indexOf(cb);

                if (e.shiftKey && lastCheckedIndex[className] >= 0 && lastCheckedIndex[className] !== currentIndex) {
                    const start = Math.min(lastCheckedIndex[className], currentIndex);
                    const end = Math.max(lastCheckedIndex[className], currentIndex);
                    for (let i = start; i <= end; i++) {
                        checkboxes[i].checked = cb.checked;
                    }
                }

                lastCheckedIndex[className] = currentIndex;
            });
        })();

        async function checkSelectedKeys() {
            const checkboxes = document.getElementsByClassName('key-checkbox');
            const selectedIds = [];
            for (let i = 0; i < checkboxes.length; i++) {
                if (checkboxes[i].checked) {
                    selectedIds.push(parseInt(checkboxes[i].value));
                }
            }

            if (selectedIds.length === 0) {
                alert('Vui lòng chọn ít nhất 1 key để kiểm tra');
                return;
            }

            const btn = document.getElementById('bulkCheckIcon');
            btn.classList.add('fa-spin');

            await checkKeyCredits(selectedIds);

            btn.classList.remove('fa-spin');
        }

        async function checkKeyCredits(ids, btnElement = null) {
            if (!Array.isArray(ids)) ids = [ids];

            let icon = null;
            if (btnElement) {
                icon = btnElement.querySelector('i');
                if (icon) icon.classList.add('fa-spin');
            }

            try {
                const res = await fetch(`${API_BASE}/admin/check_keys.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        admin_password: adminPassword,
                        ids: ids
                    })
                });

                const data = await res.json();
                if (res.ok && data.status === 'success') {
                    // Update UI for each key
                    data.results.details.forEach(detail => {
                        if (detail.status === 'success') {
                            const creditsEl = document.getElementById(`key-credits-${detail.id}`);
                            const checkEl = document.getElementById(`key-check-${detail.id}`);

                            // Note: we'd need an ID for reset_at to update it individually.
                            // For simplicity, we just reload stats if there's any success.
                            if (creditsEl) creditsEl.innerText = Number(detail.credits).toLocaleString('vi-VN');
                            if (checkEl) checkEl.innerText = 'Vừa xong';

                            // Also update health tab if exists
                            const hCreditsEl = document.getElementById(`health-key-credits-${detail.id}`);
                            const hCheckEl = document.getElementById(`health-key-check-${detail.id}`);
                            if (hCreditsEl) {
                                hCreditsEl.innerText = Number(detail.credits).toLocaleString('vi-VN');
                                hCreditsEl.style.color = detail.credits < 50000 ? 'var(--error)' : 'var(--success)';
                            }
                            if (hCheckEl) hCheckEl.innerHTML = '<small>Vừa xong</small>';
                        }
                    });

                    if (ids.length === 1 && data.results.failed > 0) {
                        alert('❌ Lỗi: ' + data.results.details[0].error);
                    } else if (ids.length > 0) {
                        // Always reload to get actual timestamps and reset_at dates
                        if (ids.length > 1) {
                            alert(`✅ Đã kiểm tra xong ${data.results.success}/${data.results.total} keys`);
                        }
                        loadStats();
                    }
                } else {
                    alert('❌ Lỗi: ' + (data.error || 'Không thể kiểm tra key'));
                }
            } catch (error) {
                alert('❌ Lỗi kết nối: ' + error.message);
            } finally {
                if (icon) icon.classList.remove('fa-spin');
            }
        }

        async function addKey() {
            const keyEncrypted = document.getElementById('newKeyEncrypted').value;
            const successEl = document.getElementById('addKeySuccess');
            const errorEl = document.getElementById('addKeyError');

            successEl.innerText = '';
            errorEl.innerText = '';

            if (!keyEncrypted) {
                errorEl.innerText = 'Vui lòng nhập key';
                return;
            }

            try {
                const res = await fetch(`${API_BASE}/admin/add_key.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ admin_password: adminPassword, key_encrypted: keyEncrypted })
                });

                const data = await res.json();

                if (res.ok && data.status === 'success') {
                    successEl.innerText = `✅ Đã thêm key! Credits: ${data.credits_remaining}`;
                    document.getElementById('newKeyEncrypted').value = '';
                    setTimeout(() => {
                        closeModal('addKeyModal');
                        loadStats();
                    }, 1500);
                } else {
                    errorEl.innerText = data.error || 'Thêm key thất bại';
                }
            } catch (error) {
                errorEl.innerText = 'Lỗi kết nối';
            }
        }

        async function addUser() {
            const email = document.getElementById('newUserEmail').value;
            const password = document.getElementById('newUserPassword').value;
            const plan = document.getElementById('newUserPlan').value;
            const quota = document.getElementById('newUserQuota').value;
            const days = document.getElementById('newUserDays').value;
            const successEl = document.getElementById('addUserSuccess');
            const errorEl = document.getElementById('addUserError');

            successEl.innerText = '';
            errorEl.innerText = '';

            if (!email || !password) {
                errorEl.innerText = 'Vui lòng nhập đầy đủ thông tin';
                return;
            }

            try {
                const res = await fetch(`${API_BASE}/admin/create_user.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        admin_password: adminPassword,
                        email, password, plan,
                        quota_total: parseInt(quota),
                        days: parseInt(days)
                    })
                });

                const data = await res.json();

                if (res.ok && data.status === 'success') {
                    successEl.innerText = `✅ Đã tạo user: ${email}`;
                    document.getElementById('newUserEmail').value = '';
                    document.getElementById('newUserPassword').value = '';
                    setTimeout(() => {
                        closeModal('addUserModal');
                        loadStats();
                    }, 1500);
                } else {
                    errorEl.innerText = data.error || 'Tạo user thất bại';
                }
            } catch (error) {
                errorEl.innerText = 'Lỗi kết nối';
            }
        }

        function showBulkImportModal() {
            document.getElementById('bulkImportModal').style.display = 'flex';
            document.getElementById('bulkImportProgress').style.display = 'none';
            document.getElementById('bulkImportSuccess').innerText = '';
            document.getElementById('bulkImportError').innerText = '';
        }

        async function bulkImport() {
            const keysText = document.getElementById('bulkKeysText').value;
            const progressEl = document.getElementById('bulkImportProgress');
            const statusEl = document.getElementById('bulkImportStatus');
            const successEl = document.getElementById('bulkImportSuccess');
            const errorEl = document.getElementById('bulkImportError');

            successEl.innerText = '';
            errorEl.innerText = '';

            if (!keysText.trim()) {
                errorEl.innerText = 'Vui lòng nhập ít nhất 1 key';
                return;
            }

            progressEl.style.display = 'block';
            statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Đang xử lý...';

            try {
                const res = await fetch(`${API_BASE}/admin/bulk_add_keys.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        admin_password: adminPassword,
                        keys_text: keysText
                    })
                });

                const data = await res.json();

                if (res.ok && data.status === 'success') {
                    const results = data.results;

                    let failedKeysText = '';
                    if (results.details) {
                        failedKeysText = results.details
                            .filter(d => d.status === 'failed')
                            .map(d => d.full_key)
                            .join('\n');
                    }

                    statusEl.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <div>
                                <div style="color: var(--success); font-weight: 600;">✅ Thành công: ${results.success}/${results.total}</div>
                                <div style="color: var(--error); font-weight: 600;">❌ Thất bại: ${results.failed}/${results.total}</div>
                            </div>
                            ${results.failed > 0 ? `
                                <button class="btn btn-primary" style="width: auto; padding: 5px 12px; font-size: 0.8rem;" onclick="copyFailedKeys(\`${failedKeysText.replace(/`/g, '\\`').replace(/\$/g, '\\$')}\`)">
                                    <i class="fa-solid fa-copy"></i> Copy các Key lỗi
                                </button>
                            ` : ''}
                        </div>
                    `;

                    if (results.details && results.details.length > 0) {
                        const detailsHtml = results.details.map(d => `
                            <div style="font-size: 0.85rem; padding: 6px 0; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between;">
                                <code style="color: var(--text-muted);">${d.key}</code>
                                ${d.status === 'success' ?
                                `<span style="color: var(--success);">✅ ${d.credits} credits</span>` :
                                `<span style="color: var(--error);">❌ ${d.error}</span>`
                            }
                            </div>
                        `).join('');
                        statusEl.innerHTML += `<div style="margin-top: 10px; max-height: 250px; overflow-y: auto; background: #000; padding: 10px; border-radius: 8px;">${detailsHtml}</div>`;
                    }

                    if (results.success > 0) {
                        document.getElementById('bulkKeysText').value = '';
                        setTimeout(() => { loadStats(); }, 2000);
                    }
                } else {
                    errorEl.innerText = data.error || 'Import thất bại';
                    progressEl.style.display = 'none';
                }
            } catch (error) {
                errorEl.innerText = 'Lỗi kết nối';
                progressEl.style.display = 'none';
            }
        }

        // ======= KEY POOL =======
        async function loadKeyPool() {
            try {
                const res = await fetch('api/admin/key_pool.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ admin_password: adminPassword, action: 'load' })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    const textarea = document.getElementById('keyPoolText');
                    textarea.value = data.keys.map(k => k.key_text).join('\n');
                    document.getElementById('poolCount').textContent = `(${data.count} key)`;
                }
            } catch (e) { console.error('Load pool error:', e); }
        }

        async function saveKeyPool() {
            const keysText = document.getElementById('keyPoolText').value.trim();
            try {
                const res = await fetch('api/admin/key_pool.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ admin_password: adminPassword, action: 'save', keys_text: keysText })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    document.getElementById('poolCount').textContent = `(${data.count} key)`;
                    alert(`Đã lưu ${data.count} key vào kho!`);
                } else {
                    alert(data.error || 'Lưu pool thất bại');
                }
            } catch (e) { alert('Lỗi kết nối'); }
        }

        async function clearKeyPool() {
            if (!confirm('Xóa hết key trong kho?')) return;
            try {
                await fetch('api/admin/key_pool.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ admin_password: adminPassword, action: 'clear' })
                });
                document.getElementById('keyPoolText').value = '';
                document.getElementById('poolCount').textContent = '(0 key)';
                document.getElementById('poolResults').style.display = 'none';
            } catch (e) { alert('Lỗi kết nối'); }
        }

        async function addKeysFromPool() {
            const count = parseInt(document.getElementById('poolConsumeCount').value) || 10;
            const btn = document.getElementById('btnAddFromPool');
            const resultsDiv = document.getElementById('poolResults');
            const listDiv = document.getElementById('poolResultsList');

            btn.disabled = true;
            resultsDiv.style.display = 'block';
            listDiv.innerHTML = '<div style="color: var(--text-muted); padding: 10px;">Đang lấy key từ kho...</div>';

            try {
                // 1. Consume keys from pool
                const consumeRes = await fetch('api/admin/key_pool.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ admin_password: adminPassword, action: 'consume', count })
                });
                const consumeData = await consumeRes.json();

                if (!consumeData.keys || consumeData.keys.length === 0) {
                    listDiv.innerHTML = '<div style="color: var(--error); padding: 10px;">Kho hết key!</div>';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-plus-circle"></i> Thêm vào hệ thống';
                    return;
                }

                const keysToAdd = consumeData.keys;
                const total = keysToAdd.length;
                let successCount = 0;
                let failedCount = 0;
                const failedKeys = [];
                let detailsHtml = '';

                // Save initial state to localStorage
                const saveProgress = () => {
                    localStorage.setItem('poolImportProgress', JSON.stringify({
                        total, successCount, failedCount, detailsHtml,
                        remainingKeys: keysToAdd.slice(successCount + failedCount).map(k => k.key_text || k),
                        timestamp: Date.now()
                    }));
                };

                // Update button with live counter
                const updateBtn = () => {
                    btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> ${successCount + failedCount}/${total} — <span style="color:#4ade80">✅${successCount}</span> <span style="color:#f87171">❌${failedCount}</span>`;
                };
                updateBtn();
                listDiv.innerHTML = `<div style="color: var(--text-muted); padding: 10px;">Đang thêm ${total} key vào hệ thống...</div>`;
                saveProgress();

                // 2. Add keys ONE BY ONE with live counter
                for (let i = 0; i < keysToAdd.length; i++) {
                    const keyText = keysToAdd[i].key_text;
                    try {
                        const addRes = await fetch('api/admin/bulk_add_keys.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ admin_password: adminPassword, keys_text: keyText })
                        });
                        const addData = await addRes.json();

                        if (addData.results && addData.results.details && addData.results.details.length > 0) {
                            const d = addData.results.details[0];
                            if (d.status === 'success') {
                                successCount++;
                                detailsHtml += `<div style="font-size: 0.82rem; padding: 5px 8px; border-bottom: 1px solid #222; display: flex; justify-content: space-between; align-items: center;">
                                    <code style="color: #888;">${d.key}</code>
                                    <span style="color: #22c55e;">✅ ${d.credits?.toLocaleString()} credits</span>
                                </div>`;
                            } else {
                                failedCount++;
                                failedKeys.push(d.full_key || keyText);
                                detailsHtml += `<div style="font-size: 0.82rem; padding: 5px 8px; border-bottom: 1px solid #222; display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                                    <code style="color: #888; flex: 1;">${d.key}</code>
                                    <span style="color: var(--error); font-size: 0.8rem; flex: 1;">${d.error}</span>
                                    <button class="btn" style="width:auto; padding:2px 10px; font-size:0.75rem; background:#f59e0b; color:#000;" onclick="retryPoolKey('${(d.full_key || '').replace(/'/g, "\\'")}', this)">
                                        <i class="fa-solid fa-rotate-right"></i> Thử lại
                                    </button>
                                </div>`;
                            }
                        } else {
                            failedCount++;
                            failedKeys.push(keyText);
                            detailsHtml += `<div style="font-size: 0.82rem; padding: 5px 8px; border-bottom: 1px solid #222; color: var(--error);">
                                <code style="color: #888;">${keyText.substring(0, 30)}...</code> — ${addData.error || 'Lỗi'}
                            </div>`;
                        }
                    } catch (e) {
                        failedCount++;
                        failedKeys.push(keyText);
                        detailsHtml += `<div style="font-size: 0.82rem; padding: 5px 8px; border-bottom: 1px solid #222; color: var(--error);">
                            <code style="color: #888;">${keyText.substring(0, 30)}...</code> — Lỗi kết nối
                        </div>`;
                    }
                    updateBtn();
                    saveProgress();
                }

                // 3. Show final results
                listDiv.innerHTML = `<div style="padding: 10px; background: rgba(0,0,0,0.3); border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <span style="color: #22c55e; font-weight: 600;">✅ ${successCount}</span> · 
                        <span style="color: var(--error); font-weight: 600;">❌ ${failedCount}</span> · 
                        Tổng: ${total}
                    </div>
                </div>
                <div style="background: #000; border-radius: 8px; padding: 8px;">${detailsHtml}</div>`;

                // 4. Return failed keys to pool
                if (failedKeys.length > 0) {
                    await fetch('api/admin/key_pool.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ admin_password: adminPassword, action: 'return', keys: failedKeys })
                    });
                }

                // Mark as complete
                localStorage.setItem('poolImportProgress', JSON.stringify({
                    total, successCount, failedCount, detailsHtml,
                    completed: true, timestamp: Date.now()
                }));

                // Refresh pool and keys table
                loadKeyPool();
                if (successCount > 0) loadStats();
            } catch (e) {
                listDiv.innerHTML = '<div style="color: var(--error); padding: 10px;">Lỗi kết nối</div>';
            }

            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-plus-circle"></i> Thêm vào hệ thống';
        }

        // Restore pool import progress on page load
        function restorePoolImportProgress() {
            try {
                const saved = JSON.parse(localStorage.getItem('poolImportProgress'));
                if (!saved) return;

                // Only show if less than 1 hour old
                if (Date.now() - saved.timestamp > 3600000) {
                    localStorage.removeItem('poolImportProgress');
                    return;
                }

                const resultsDiv = document.getElementById('poolResults');
                const listDiv = document.getElementById('poolResultsList');
                const btn = document.getElementById('btnAddFromPool');
                if (!resultsDiv || !listDiv) return;

                resultsDiv.style.display = 'block';

                if (saved.completed) {
                    // Show completed results
                    listDiv.innerHTML = `<div style="padding: 10px; background: rgba(0,0,0,0.3); border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <span style="color: #22c55e; font-weight: 600;">✅ ${saved.successCount}</span> · 
                            <span style="color: var(--error); font-weight: 600;">❌ ${saved.failedCount}</span> · 
                            Tổng: ${saved.total}
                        </div>
                        <button class="btn" style="width:auto; padding:4px 12px; font-size:0.75rem; background:var(--border); color:var(--text-muted);" onclick="localStorage.removeItem('poolImportProgress'); this.closest('#poolResults').style.display='none';">
                            <i class="fa-solid fa-xmark"></i> Đóng
                        </button>
                    </div>
                    <div style="background: #000; border-radius: 8px; padding: 8px;">${saved.detailsHtml}</div>`;
                } else {
                    // Show interrupted progress
                    const processed = saved.successCount + saved.failedCount;
                    const remaining = saved.remainingKeys?.length || (saved.total - processed);
                    listDiv.innerHTML = `<div style="padding: 10px; background: rgba(234,179,8,0.1); border: 1px solid rgba(234,179,8,0.3); border-radius: 8px; margin-bottom: 8px;">
                        <div style="color: #eab308; font-weight: 600; margin-bottom: 6px;">
                            <i class="fa-solid fa-triangle-exclamation"></i> Phiên trước bị gián đoạn!
                        </div>
                        <div style="font-size: 0.85rem; color: var(--text-muted);">
                            Đã xử lý: ${processed}/${saved.total} — 
                            <span style="color: #22c55e;">✅ ${saved.successCount}</span> · 
                            <span style="color: var(--error);">❌ ${saved.failedCount}</span>
                            ${remaining > 0 ? `<br>Còn <strong style="color: #eab308;">${remaining} key</strong> chưa xử lý (đã trả về kho).` : ''}
                        </div>
                        <button class="btn" style="width:auto; padding:4px 12px; font-size:0.75rem; background:var(--border); color:var(--text-muted); margin-top: 8px;" onclick="localStorage.removeItem('poolImportProgress'); this.closest('#poolResults').style.display='none';">
                            <i class="fa-solid fa-xmark"></i> Đóng
                        </button>
                    </div>
                    <div style="background: #000; border-radius: 8px; padding: 8px;">${saved.detailsHtml}</div>`;
                }
            } catch (e) { /* ignore */ }
        }

        async function retryPoolKey(keyText, btnEl) {
            if (!keyText) return;
            btnEl.disabled = true;
            btnEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
            try {
                const res = await fetch('api/admin/bulk_add_keys.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ admin_password: adminPassword, keys_text: keyText })
                });
                const data = await res.json();
                const row = btnEl.closest('div');
                if (data.results && data.results.success > 0) {
                    const credits = data.results.details?.[0]?.credits || 0;
                    row.innerHTML = `<code style="color: #888; flex: 1;">${keyText.substring(0, 30)}...</code>
                        <span style="color: #22c55e;">✅ ${credits.toLocaleString()} credits</span>`;
                    // Remove from pool
                    loadKeyPool();
                    setTimeout(() => loadStats(), 1500);
                } else {
                    const err = data.results?.details?.[0]?.error || data.error || 'Thất bại';
                    btnEl.disabled = false;
                    btnEl.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Thử lại';
                    row.querySelector('span[style*="error"]').textContent = '❌ ' + err;
                }
            } catch (e) {
                btnEl.disabled = false;
                btnEl.innerHTML = '<i class="fa-solid fa-rotate-right"></i> Thử lại';
            }
        }

        function showUpdateNgrokModal() {
            const currentUrl = document.getElementById('ngrokUrl').innerText;
            if (currentUrl && currentUrl !== '-' && currentUrl !== 'Not set') {
                document.getElementById('newNgrokUrl').value = currentUrl;
            }
            document.getElementById('updateNgrokSuccess').innerText = '';
            document.getElementById('updateNgrokError').innerText = '';
            document.getElementById('updateNgrokModal').style.display = 'flex';
        }

        async function saveNgrokUrl() {
            const newUrl = document.getElementById('newNgrokUrl').value.trim();
            const successEl = document.getElementById('updateNgrokSuccess');
            const errorEl = document.getElementById('updateNgrokError');

            successEl.innerText = '';
            errorEl.innerText = '';

            if (!newUrl) {
                errorEl.innerText = 'Vui lòng nhập URL';
                return;
            }

            try {
                const res = await fetch(`${API_BASE}/admin/update_ngrok.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        admin_password: adminPassword,
                        ngrok_url: newUrl
                    })
                });

                const data = await res.json();

                if (res.ok && data.status === 'success') {
                    successEl.innerText = '✅ Cập nhật thành công!';
                    document.getElementById('ngrokUrl').innerText = data.ngrok_url;
                    setTimeout(() => {
                        closeModal('updateNgrokModal');
                    }, 1500);
                } else {
                    errorEl.innerText = data.error || 'Cập nhật thất bại';
                }
            } catch (error) {
                errorEl.innerText = 'Lỗi kết nối server';
            }
        }
        async function bulkDeleteKeys() {
            const minCredits = document.getElementById('minCreditsDelete').value;
            if (!confirm(`Bạn có chắc muốn xóa tất cả các key có từ ${minCredits} credits trở xuống?`)) return;

            try {
                const res = await fetch(`${API_BASE}/admin/bulk_delete_keys.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        admin_password: adminPassword,
                        min_credits: parseInt(minCredits)
                    })
                });

                const data = await res.json();
                if (res.ok && data.status === 'success') {
                    alert('✅ ' + data.message);
                    loadStats();
                } else {
                    alert('❌ Lỗi: ' + (data.error || 'Không thể xóa key'));
                }
            } catch (error) {
                alert('❌ Lỗi kết nối: ' + error.message);
            }
        }

        function checkWeakKeys() {
            const threshold = parseInt(document.getElementById('minCreditsCheck').value);
            if (isNaN(threshold)) return alert('Vui lòng nhập số hợp lệ');

            const checkboxes = document.getElementsByClassName('key-checkbox');
            let count = 0;
            for (let i = 0; i < checkboxes.length; i++) {
                const cb = checkboxes[i];
                const row = cb.closest('tr');
                const creditsCell = row.cells[3]; // Credits is the 4th column
                const creditsString = creditsCell.innerText.replace(/\./g, '').trim();
                const credits = parseInt(creditsString);

                if (!isNaN(credits) && credits <= threshold) {
                    cb.checked = true;
                    count++;
                } else {
                    cb.checked = false;
                }
            }

            if (count === 0) return alert('Không tìm thấy key nào có credit dưới ngưỡng ' + threshold.toLocaleString('vi-VN'));

            if (confirm(`Đã chọn ${count} key có credit <= ${threshold.toLocaleString('vi-VN')}. Bắt đầu kiểm tra?`)) {
                checkSelectedKeys();
            }
        }

        async function copyFailedKeys(text) {
            try {
                await navigator.clipboard.writeText(text);
                alert('📋 Đã copy danh sách các Key lỗi vào Clipboard!');
            } catch (err) {
                alert('❌ Lỗi khi copy: ' + err);
            }
        }


        // ===== 2FA Settings =====
        async function load2FAStatus() {
            try {
                const res = await fetch(`${API_BASE}/admin/setup_2fa.php`, {
                    method: 'POST',
                    headers: adminHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ action: 'status' })
                });
                const data = await res.json();
                const statusEl = document.getElementById('2faStatus');
                const actionsEl = document.getElementById('2faActions');

                if (data.enabled) {
                    statusEl.innerHTML = '<span class="badge badge-success" style="font-size:0.95rem; padding:6px 14px;">🔒 2FA đang BẬT</span>';
                    actionsEl.innerHTML = '<button class="btn" style="background:#3b82f6; color:white; width:auto; margin-top:10px; margin-right:8px;" onclick="show2FA()"><i class="fa-solid fa-qrcode"></i> Hiện mã QR</button>' +
                        '<button class="btn" style="background:var(--error); color:white; width:auto; margin-top:10px;" onclick="disable2FA()"><i class="fa-solid fa-lock-open"></i> Tắt 2FA</button>';
                } else {
                    statusEl.innerHTML = '<span class="badge badge-warning" style="font-size:0.95rem; padding:6px 14px;">⚠️ 2FA đang TẮT</span>';
                    actionsEl.innerHTML = '<button class="btn btn-success" style="width:auto; margin-top:10px;" onclick="enable2FA()"><i class="fa-solid fa-shield-halved"></i> Bật 2FA</button>';
                }
            } catch (e) {
                document.getElementById('2faStatus').innerHTML = '<span style="color:var(--error);">Lỗi kiểm tra 2FA</span>';
            }
        }

        async function show2FA() {
            try {
                const res = await fetch(`${API_BASE}/admin/setup_2fa.php`, {
                    method: 'POST',
                    headers: adminHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ action: 'show' })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    document.getElementById('2faQR').style.display = 'block';
                    document.getElementById('2faQRImage').src = data.qr_url;
                    document.getElementById('2faSecretText').innerText = data.secret;
                    document.getElementById('2faMessage').innerHTML = '<span style="color:var(--primary);">Cho nhân viên quét QR hoặc nhập mã text vào Google Authenticator</span>';
                } else {
                    document.getElementById('2faMessage').innerHTML = '<span style="color:var(--error);">' + (data.error || 'Lỗi') + '</span>';
                }
            } catch (e) {
                document.getElementById('2faMessage').innerHTML = '<span style="color:var(--error);">Lỗi kết nối</span>';
            }
        }

        async function enable2FA() {
            try {
                const res = await fetch(`${API_BASE}/admin/setup_2fa.php`, {
                    method: 'POST',
                    headers: adminHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ action: 'enable' })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    document.getElementById('2faQR').style.display = 'block';
                    document.getElementById('2faQRImage').src = data.qr_url;
                    document.getElementById('2faSecretText').innerText = data.secret;
                    document.getElementById('2faMessage').innerHTML = '<span style="color:var(--primary);">Quét QR rồi nhập mã 6 số để xác nhận</span>';
                } else {
                    document.getElementById('2faMessage').innerHTML = '<span style="color:var(--error);">' + (data.error || 'Lỗi') + '</span>';
                }
            } catch (e) {
                document.getElementById('2faMessage').innerHTML = '<span style="color:var(--error);">Lỗi kết nối</span>';
            }
        }

        async function confirm2FA() {
            const code = document.getElementById('2faConfirmCode').value.trim();
            if (!code || code.length !== 6) {
                document.getElementById('2faMessage').innerHTML = '<span style="color:var(--error);">Vui lòng nhập đủ 6 số</span>';
                return;
            }
            try {
                const res = await fetch(`${API_BASE}/admin/setup_2fa.php`, {
                    method: 'POST',
                    headers: adminHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ action: 'confirm', code: code })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    document.getElementById('2faQR').style.display = 'none';
                    document.getElementById('2faMessage').innerHTML = '<span style="color:var(--success);">✅ ' + data.message + '</span>';
                    load2FAStatus();
                } else {
                    document.getElementById('2faMessage').innerHTML = '<span style="color:var(--error);">❌ ' + (data.error || 'Mã không đúng') + '</span>';
                }
            } catch (e) {
                document.getElementById('2faMessage').innerHTML = '<span style="color:var(--error);">Lỗi kết nối</span>';
            }
        }

        async function disable2FA() {
            const code = prompt('Nhập mã 6 số từ Google Authenticator để tắt 2FA:');
            if (!code) return;
            try {
                const res = await fetch(`${API_BASE}/admin/setup_2fa.php`, {
                    method: 'POST',
                    headers: adminHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ action: 'disable', code: code })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    document.getElementById('2faMessage').innerHTML = '<span style="color:var(--success);">✅ 2FA đã tắt</span>';
                    load2FAStatus();
                } else {
                    alert('❌ ' + (data.error || 'Lỗi'));
                }
            } catch (e) {
                alert('❌ Lỗi kết nối');
            }
        }

        // Social Settings
        async function loadSocialSettings() {
            try {
                const res = await fetch(`${API_BASE}/social_settings.php`);
                const data = await res.json();
                if (data.status === 'success') {
                    const s = data.settings;
                    if (s.zalo) document.getElementById('socialZalo').value = s.zalo;
                    if (s.telegram) document.getElementById('socialTelegram').value = s.telegram;
                    if (s.facebook) document.getElementById('socialFacebook').value = s.facebook;
                    if (s.youtube) document.getElementById('socialYoutube').value = s.youtube;
                    if (s.tutorial_link) document.getElementById('socialTutorialLink').value = s.tutorial_link;
                }
            } catch (e) { console.error(e); }
        }

        async function saveSocialSettings() {
            const zalo = document.getElementById('socialZalo').value.trim();
            const telegram = document.getElementById('socialTelegram').value.trim();
            const facebook = document.getElementById('socialFacebook').value.trim();
            const youtube = document.getElementById('socialYoutube').value.trim();
            const tutorial = document.getElementById('socialTutorialLink').value.trim();

            try {
                const res = await fetch(`${API_BASE}/admin/update_settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        admin_password: adminPassword,
                        settings: {
                            social_zalo: zalo,
                            social_telegram: telegram,
                            social_facebook: facebook,
                            social_youtube: youtube,
                            tutorial_link: tutorial
                        }
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alert('✅ Đã lưu cài đặt mạng xã hội!');
                } else {
                    alert('❌ Lỗi: ' + (data.error || 'Không thể lưu'));
                }
            } catch (e) {
                alert('Lỗi kết nối');
            }
        }

        async function loadHealthStats() {
            try {
                const res = await fetch(`${API_BASE}/admin/health.php?_=${Date.now()}`, {
                    headers: { 'X-Admin-Password': adminPassword }
                });
                const result = await res.json();

                if (res.ok && result.status === 'success') {
                    const data = result.data;

                    // Update Summary
                    if (document.getElementById('healthTotalCredits')) {
                        document.getElementById('healthTotalCredits').innerText = Number(data.summary.total_active_credits || 0).toLocaleString('vi-VN');
                        document.getElementById('healthOnlineWorkers').innerText = `${data.summary.online_workers} / ${data.summary.worker_count}`;
                    }

                    // Update Tables
                    updateHealthWorkersTable(data.workers);
                    updateHealthKeysTable(data.api_keys, data.workers);
                }
            } catch (error) {
                console.error('Health check failed:', error);
            }
        }

        function timeAgo(dateStr) {
            if (!dateStr) return '—';
            const diff = Math.floor((new Date() - new Date(dateStr)) / 1000);
            if (diff < 60) return diff + 's';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ' + (diff % 60) + 's';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ' + Math.floor((diff % 3600) / 60) + 'm';
            return Math.floor(diff / 86400) + 'd ' + Math.floor((diff % 86400) / 3600) + 'h';
        }

        function updateHealthWorkersTable(workers) {
            const tbody = document.getElementById('healthWorkersTable');
            if (!workers || workers.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align: center;">Chưa có máy nào online</td></tr>';
                return;
            }
            tbody.innerHTML = workers.map(w => {
                const isOnline = (new Date() - new Date(w.last_seen)) < 120000;
                const statusClass = isOnline ? 'badge-success' : 'badge-error';
                const statusText = isOnline ? 'ONLINE' : 'OFFLINE';
                const failedJobs = parseInt(w.failed_jobs || 0);
                const failedColor = failedJobs >= 3 ? 'var(--error)' : failedJobs >= 1 ? '#f59e0b' : 'var(--success)';
                const failedLabel = failedJobs >= 3 ? `⚠️ ${failedJobs} lỗi` : failedJobs >= 1 ? `${failedJobs} lỗi` : '✅ OK';
                const rowStyle = failedJobs >= 3 ? 'background: rgba(239,68,68,0.07);' : '';

                return `
                <tr style="${rowStyle}">
                    <td><small style="color: var(--text-muted);">${w.worker_name || w.worker_uuid}</small><br><code style="font-size:0.7rem;color:#888;" title="${w.worker_uuid}">${w.worker_uuid ? w.worker_uuid.substring(0, 8) : ''}</code></td>
                    <td><code style="color: #60a5fa;">${w.ip_address || '—'}</code></td>
                    <td><a href="${w.url}" target="_blank" style="color: var(--primary); font-size:0.85rem;">${w.url}</a></td>
                    <td><span class="badge ${statusClass}">${statusText}</span></td>
                    <td style="font-weight: 700; color: var(--primary);">${Number(w.jobs_completed || 0).toLocaleString('vi-VN')}</td>
                    <td style="font-weight: 700; color: #60a5fa;">${Number(w.chars_used || 0).toLocaleString('vi-VN')}</td>
                    <td style="color: ${failedColor}; font-weight: 700;">${failedLabel}</td>
                    <td><small>${w.connected_at ? timeAgo(w.connected_at) : '—'}</small></td>
                    <td><small style="color: #4ade80;">${timeAgo(w.last_seen)}</small></td>
                    <td>${!isOnline ? `<button class="btn" style="padding:3px 7px;font-size:0.7rem;background:transparent;border:1px solid var(--border);color:var(--text-muted);" onclick="deleteWorker('${w.worker_uuid}')" title="Xóa entry này"><i class="fa-solid fa-xmark"></i></button>` : ''}</td>
                </tr>
            `}).join('');
        }

        async function cleanupOfflineWorkers() {
            if (!confirm('Xóa tất cả worker OFFLINE khỏi danh sách?')) return;
            try {
                const res = await fetch(`${API_BASE}/admin/health.php`, {
                    method: 'POST',
                    headers: adminHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ action: 'cleanup_workers' })
                });
                const data = await res.json();
                alert('✅ ' + (data.message || 'Đã dọn'));
                loadHealthStats();
            } catch (e) { alert('❌ Lỗi kết nối'); }
        }

        async function deleteWorker(uuid) {
            if (!confirm('Xóa worker này?')) return;
            try {
                const res = await fetch(`${API_BASE}/admin/health.php`, {
                    method: 'POST',
                    headers: adminHeaders({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ action: 'delete_worker', worker_uuid: uuid })
                });
                const data = await res.json();
                if (data.success) loadHealthStats();
                else alert('❌ ' + (data.error || 'Lỗi'));
            } catch (e) { alert('❌ Lỗi kết nối'); }
        }

        function updateHealthKeysTable(keys, workers) {
            const tbody = document.getElementById('healthKeysTable');
            if (!keys || keys.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">Chưa có key nào</td></tr>';
                return;
            }
            const workerNameMap = {};
            if (workers && workers.length) {
                workers.forEach(w => { if (w.worker_uuid) workerNameMap[w.worker_uuid] = w.worker_name || w.worker_uuid.substring(0, 8); });
            }
            tbody.innerHTML = keys.map(k => `
                <tr>
                    <td><input type="checkbox" class="health-key-checkbox" value="${k.id}"></td>
                    <td>${k.id}</td>
                    <td><code>${k.masked_key}</code></td>
                    <td><b id="health-key-credits-${k.id}" style="color: ${k.credits_remaining < 50000 ? 'var(--error)' : 'var(--success)'}">${Number(k.credits_remaining || 0).toLocaleString('vi-VN')}</b></td>
                    <td><span class="badge badge-${k.status === 'active' ? 'success' : 'error'}">${k.status}</span></td>
                    <td>
                        ${k.assigned_worker_uuid ? `<span class="badge" style="background:#2563eb; color:#fff;" title="${k.assigned_worker_uuid}">${workerNameMap[k.assigned_worker_uuid] || k.assigned_worker_uuid.substring(0, 8) + '...'}</span>` : '<span style="color:var(--text-muted)">-</span>'}
                    </td>
                    <td><small title="Ngày tạo: ${k.created_at || '-'}">${k.reset_at ? new Date(k.reset_at).toLocaleDateString('vi-VN') : '-'}</small></td>
                    <td id="health-key-check-${k.id}"><small>${k.last_checked || '-'}</small></td>
                    <td>
                        <button class="btn" style="background: #3b82f6; color: white; padding: 4px 8px; font-size: 0.75rem;" onclick="checkKeyCredits(${k.id}, this)">
                            <i class="fa-solid fa-sync"></i>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        async function loadSettings() {
            if (!adminPassword) return;
            try {
                // FIX: Use encodeURIComponent to handle special characters in password
                const res = await fetch(`${API_BASE}/admin/get_settings.php?_=${Date.now()}`, { headers: adminHeaders() });
                const data = await res.json();

                if (data.status === 'success') {
                    const s = data.settings;

                    // Populate fields
                    if (document.getElementById('conf_affiliate_rate')) {
                        const val = s.affiliate_commission_rate;
                        // Only set if val is not undefined/null. If it's 0, it should be 0.
                        if (val !== undefined && val !== null) {
                            document.getElementById('conf_affiliate_rate').value = val;
                        }
                    }

                    // Promo Popup
                    if (document.getElementById('conf_promo_enabled')) {
                        document.getElementById('conf_promo_enabled').checked = s.promo_popup_enabled == '1';
                        document.getElementById('conf_promo_image').value = s.promo_popup_image_url || '';
                        document.getElementById('conf_promo_link').value = s.promo_popup_link || '';
                        if (document.getElementById('conf_promo_frequency')) {
                            document.getElementById('conf_promo_frequency').value = s.promo_popup_frequency || 'once_per_day';
                        }
                    }
                } else {
                    console.error("Load settings failed:", data);
                    if (data.error === 'Invalid admin password') {
                        // Silently fail or redirect to login if catastrophic
                        console.warn("Invalid password detected in loadSettings");
                    }
                }
            } catch (e) {
                console.error("Load settings error", e);
            }
        }

        function generatePartnerKey() {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            let key = 'PL_';
            for (let i = 0; i < 20; i++) {
                key += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            document.getElementById('editUserPartnerKey').value = key;
        }

        async function saveSettings() {
            if (!adminPassword) return alert("Vui lòng đăng nhập");

            const btn = document.querySelector('button[onclick="saveSettings()"]');
            const msg = document.getElementById('saveSettingsMsg');

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Đang lưu...';
            msg.innerText = '';

            const settings = {
                'affiliate_commission_rate': document.getElementById('conf_affiliate_rate').value,
                'promo_popup_enabled': document.getElementById('conf_promo_enabled').checked ? '1' : '0',
                'promo_popup_image_url': document.getElementById('conf_promo_image').value.trim(),
                'promo_popup_link': document.getElementById('conf_promo_link').value.trim(),
                'promo_popup_frequency': document.getElementById('conf_promo_frequency') ? document.getElementById('conf_promo_frequency').value : 'once_per_day'
            };

            try {
                const res = await fetch(`${API_BASE}/admin/update_settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        admin_password: adminPassword,
                        settings: settings
                    })
                });
                const data = await res.json();

                if (data.status === 'success') {
                    msg.style.color = 'var(--success)';
                    msg.innerText = '✅ ' + data.message;
                } else {
                    msg.style.color = 'var(--error)';
                    msg.innerText = '❌ ' + (data.error || 'Lỗi khi lưu');
                }
            } catch (e) {
                msg.style.color = 'var(--error)';
                msg.innerText = '❌ Lỗi kết nối';
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Lưu cấu hình';
            }
        }





        // Load Logs
        async function loadLogs() {
            try {
                const ap = localStorage.getItem('admin_password');
                const response = await fetch(`api/admin/get_logs.php`, { headers: { 'X-Admin-Password': ap } });
                const data = await response.json();

                if (data.status === 'success') {
                    // Display error logs
                    const errorDiv = document.getElementById('errorLogsContent');
                    if (data.error_logs && data.error_logs.length > 0) {
                        errorDiv.innerHTML = `<p style="color: #888; margin-bottom: 10px;">File: ${data.error_log_file || 'N/A'}</p>` +
                            data.error_logs.map(line => `<div style="margin-bottom: 5px; border-bottom: 1px solid #222; padding-bottom: 3px;">${escapeHtml(line)}</div>`).join('');
                    } else {
                        errorDiv.innerHTML = '<p style="color: #888;">Chưa có log lỗi key nào.</p>';
                    }

                    // Display failed jobs logs
                    const failedDiv = document.getElementById('failedLogsContent');
                    if (data.failed_logs && data.failed_logs.length > 0) {
                        failedDiv.innerHTML = data.failed_logs.map(line => `<div style="margin-bottom: 5px; border-bottom: 1px solid #222; padding-bottom: 3px;">${escapeHtml(line)}</div>`).join('');
                    } else {
                        failedDiv.innerHTML = '<p style="color: #888;">Chưa có job nào thất bại.</p>';
                    }

                    // IP block logs are now handled by updateIpBlockLogs() in the Health tab
                    // (structured DB data, not plain text lines)
                } else {
                    document.getElementById('errorLogsContent').innerHTML = '<p style="color: #f00;">Lỗi: ' + (data.error || 'Unknown error') + '</p>';
                    document.getElementById('failedLogsContent').innerHTML = '<p style="color: #f00;">Lỗi: ' + (data.error || 'Unknown error') + '</p>';
                }
            } catch (error) {
                console.error('Load logs error:', error);
                document.getElementById('errorLogsContent').innerHTML = '<p style="color: #f00;">Không thể tải logs</p>';
                document.getElementById('failedLogsContent').innerHTML = '<p style="color: #f00;">Không thể tải logs</p>';
            }
        }

        // Helper function to escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function toggleBroadcastCustom() {
            const template = document.getElementById('broadcastTemplate').value;
            document.getElementById('broadcastCustomFields').style.display = (template === 'custom') ? 'block' : 'none';
        }

        async function sendMassMail() {
            const target = document.getElementById('broadcastTarget').value;
            const template = document.getElementById('broadcastTemplate').value;
            const subject = document.getElementById('broadcastSubject').value;
            const body = document.getElementById('broadcastBody').value;

            let confirmMsg = `Bạn có chắc chắn muốn gửi email tới toàn bộ khách hàng thuộc nhóm [${target.toUpperCase()}] không?`;
            if (template === 'activation') confirmMsg += "\nHệ thống sẽ dùng mẫu 'Kích hoạt tài khoản' có sẵn.";

            if (!confirm(confirmMsg)) return;

            const btn = document.getElementById('btnSendBroadcast');
            const progress = document.getElementById('broadcastProgress');
            const status = document.getElementById('broadcastStatus');

            btn.disabled = true;
            progress.style.display = 'block';
            status.innerText = "Đang bắt đầu chuẩn bị danh sách gửi...";

            try {
                const res = await fetch(`${API_BASE}/admin/send_mass_mail.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        admin_password: adminPassword,
                        target: target,
                        template: template,
                        subject: subject,
                        body: body
                    })
                });

                const data = await res.json();
                if (data.status === 'success') {
                    const r = data.results;
                    alert(`✅ Hoàn thành!\nTổng cộng: ${r.total}\nThành công: ${r.success}\nThất bại: ${r.failed}`);
                    status.innerText = "Hoàn tất! Đã gửi thành công " + r.success + " email.";
                } else {
                    alert('❌ Lỗi: ' + (data.error || 'Gửi mail thất bại'));
                    status.innerText = "Lỗi: " + (data.error || 'Gửi mail thất bại');
                }
            } catch (error) {
                alert('❌ Lỗi kết nối server');
                status.innerText = "Lỗi kết nối server.";
            } finally {
                btn.disabled = false;
            }
        }

        // Init Social Settings
        document.addEventListener('DOMContentLoaded', loadSocialSettings);

        // Initial Load handled by DOMContentLoaded
    </script>

    <!-- MODAL: ADD NGROK TOKEN -->
    <div id="addNgrokModal" class="modal" style="display:none;">
        <div class="modal-content" style="max-width:520px;">
            <h3 style="margin-bottom:15px;"><i class="fa-solid fa-network-wired"></i> Thêm Ngrok Tokens</h3>
            <div class="form-group">
                <label>Dán token (mỗi dòng 1 token):</label>
                <textarea id="ngrokTokenInput" class="form-control" rows="8"
                    placeholder="Nhập mỗi token trên 1 dòng&#10;Ví dụ:&#10;2abc123xyz...&#10;3def456uvw..."
                    style="font-family:monospace;font-size:0.85rem;"></textarea>
            </div>
            <p style="font-size:0.85rem;color:var(--text-muted);margin-bottom:15px;">
                <i class="fa-solid fa-lightbulb" style="color:#f59e0b"></i>
                Token Ngrok lấy tại: <a href="https://dashboard.ngrok.com/tunnels/authtokens" target="_blank"
                    style="color:var(--primary)">dashboard.ngrok.com</a>
            </p>
            <div class="modal-actions">
                <button class="btn btn-success" style="width:auto;" onclick="submitAddNgrokTokens()"><i
                        class="fa-solid fa-plus"></i> Thêm vào pool</button>
                <button class="btn" style="width:auto;background:var(--surface);border:1px solid var(--border);"
                    onclick="closeModal('addNgrokModal')">Hủy</button>
            </div>
        </div>
    </div>

    <script>
        // =====================================================
        // NGROK KEYS MANAGEMENT
        // =====================================================

        async function loadNgrokKeys() {
            const tbody = document.getElementById('ngrokKeysTable');
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;">Đang tải...</td></tr>';
            try {
                const res = await fetch('api/admin/ngrok_keys.php', {
                    headers: { 'X-Admin-Password': adminPassword }
                });
                const data = await res.json();
                if (!data.success) {
                    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;color:var(--error)">${data.error || 'Lỗi'}</td></tr>`;
                    return;
                }
                if (!data.keys || data.keys.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--text-muted)">Chưa có token nào. Hãy thêm Ngrok tokens.</td></tr>';
                    return;
                }
                tbody.innerHTML = data.keys.map((k, i) => {
                    let statusBadge = '';
                    if (!k.is_active) {
                        statusBadge = '<span class="badge badge-error">Tắt</span>';
                    } else if (k.status === 'in_use') {
                        statusBadge = '<span class="badge badge-success">Đang dùng</span>';
                    } else if (k.status === 'expired') {
                        statusBadge = '<span class="badge badge-warning">Hết hạn</span>';
                    } else {
                        statusBadge = '<span class="badge" style="background:#3b82f6;color:white;">Rảnh</span>';
                    }
                    const workerName = k.worker_name || (k.worker_uuid ? ('Worker-' + k.worker_uuid.substring(0, 6)) : '-');
                    const assignedAt = k.assigned_at ? k.assigned_at : '-';
                    return `<tr>
                        <td>${i + 1}</td>
                        <td style="cursor:pointer;" onclick="editNgrokName(${k.id}, this)" title="Click để đặt tên">${k.worker_name || '<span style="color:var(--text-muted)">Chưa đặt tên</span>'}</td>
                        <td style="font-family:monospace;font-size:0.85rem;">${k.token_masked}</td>
                        <td>${statusBadge}</td>
                        <td>${k.status === 'in_use' ? workerName : '-'}</td>
                        <td style="font-size:0.8rem;">${k.worker_ip || '-'}</td>
                        <td style="font-size:0.8rem;">${assignedAt}</td>
                        <td>
                            <div style="display:flex;gap:5px;">
                                <button class="btn" style="padding:5px 10px;font-size:0.8rem;background:#3b82f6;color:white;" onclick="editNgrokToken(${k.id}, '${k.token_masked}')" title="Sửa token"><i class="fa-solid fa-pen-to-square"></i></button>
                                <button class="btn" style="padding:5px 10px;font-size:0.8rem;background:#f59e0b;color:white;" onclick="resetNgrokToken(${k.id})" title="Giải phóng token này về rảnh"><i class="fa-solid fa-lock-open"></i></button>
                                <button class="btn btn-danger" style="padding:5px 10px;font-size:0.8rem;" onclick="deleteNgrokToken(${k.id})"><i class="fa-solid fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>`;
                }).join('');
            } catch (e) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--error)">Lỗi kết nối</td></tr>';
            }
        }

        function showAddNgrokModal() {
            document.getElementById('ngrokTokenInput').value = '';
            document.getElementById('addNgrokModal').style.display = 'flex';
        }

        async function submitAddNgrokTokens() {
            const tokens = document.getElementById('ngrokTokenInput').value.trim();
            if (!tokens) { alert('Vui lòng nhập ít nhất 1 token!'); return; }
            try {
                const res = await fetch('api/admin/ngrok_keys.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Admin-Password': adminPassword
                    },
                    body: JSON.stringify({ action: 'add', tokens })
                });
                const data = await res.json();
                if (data.success) {
                    alert('✅ ' + data.message);
                    closeModal('addNgrokModal');
                    loadNgrokKeys();
                } else {
                    alert('❌ ' + (data.error || 'Lỗi'));
                }
            } catch (e) { alert('❌ Lỗi kết nối'); }
        }

        async function deleteNgrokToken(id) {
            if (!confirm('Xóa token này?')) return;
            try {
                const res = await fetch('api/admin/ngrok_keys.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Admin-Password': adminPassword
                    },
                    body: JSON.stringify({ action: 'delete', id })
                });
                const data = await res.json();
                data.success ? loadNgrokKeys() : alert('❌ ' + (data.error || 'Lỗi'));
            } catch (e) { alert('❌ Lỗi kết nối'); }
        }

        async function resetNgrokToken(id) {
            try {
                const res = await fetch('api/admin/ngrok_keys.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Admin-Password': adminPassword
                    },
                    body: JSON.stringify({ action: 'reset', id })
                });
                const data = await res.json();
                data.success ? loadNgrokKeys() : alert('❌ ' + (data.error || 'Lỗi'));
            } catch (e) { alert('❌ Lỗi kết nối'); }
        }

        async function resetAllNgrokTokens() {
            if (!confirm('Giải phóng TẤT CẢ token về rảnh? Các máy Colab sẽ phải xin lại token khi khởi động tiếp.')) return;
            try {
                const res = await fetch('api/admin/ngrok_keys.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Admin-Password': adminPassword
                    },
                    body: JSON.stringify({ action: 'reset' })
                });
                const data = await res.json();
                data.success ? (alert('✅ ' + data.message), loadNgrokKeys()) : alert('❌ ' + (data.error || 'Lỗi'));
            } catch (e) { alert('❌ Lỗi kết nối'); }
        }

        async function editNgrokName(id, cell) {
            const currentName = cell.textContent.trim();
            const newName = prompt('Đặt tên cho token này (để phân biệt máy):', currentName === 'Chưa đặt tên' ? '' : currentName);
            if (newName === null) return;
            try {
                const res = await fetch('api/admin/ngrok_keys.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Admin-Password': adminPassword
                    },
                    body: JSON.stringify({ action: 'update_name', id, worker_name: newName })
                });
                const data = await res.json();
                data.success ? loadNgrokKeys() : alert('❌ ' + (data.error || 'Lỗi'));
            } catch (e) { alert('❌ Lỗi kết nối'); }
        }

        async function editNgrokToken(id, currentMasked) {
            const newToken = prompt(`Nhập token mới để thay thế ${currentMasked}:`, '');
            if (newToken === null || newToken.trim() === '') return;
            try {
                const res = await fetch('api/admin/ngrok_keys.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Admin-Password': adminPassword
                    },
                    body: JSON.stringify({ action: 'update_token', id, token: newToken.trim() })
                });
                const data = await res.json();
                if (data.success) {
                    alert('✅ ' + data.message);
                    loadNgrokKeys();
                } else {
                    alert('❌ ' + (data.error || 'Lỗi'));
                }
            } catch (e) { alert('❌ Lỗi kết nối'); }
        }

        // ====== COLAB REMOTE CONTROL ======
        async function loadColabExtensions() {
            try {
                const res = await fetch('api/worker_command.php?list=1');
                const data = await res.json();
                const grid = document.getElementById('colabExtensionGrid');
                const cmdTable = document.getElementById('colabCommandsTable');

                // Render extension cards
                if (!data.extensions || data.extensions.length === 0) {
                    grid.innerHTML = `<div style="text-align: center; color: var(--text-muted); padding: 30px; grid-column: 1/-1;">
                        <i class="fa-solid fa-puzzle-piece" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                        Chưa có Extension nào kết nối.<br>
                        <span style="font-size: 0.8rem;">Cài Chrome Extension và mở tab Colab để bắt đầu.</span>
                    </div>`;
                } else {
                    data.extensions.sort((a, b) => {
                        const numA = parseInt(a.worker_name.replace(/\D/g, '')) || 0;
                        const numB = parseInt(b.worker_name.replace(/\D/g, '')) || 0;
                        return numA - numB;
                    });
                    grid.innerHTML = data.extensions.map(ext => {
                        const runtimeOnline = ext.is_online == 1;
                        const extOnline = ext.ext_online == 1;
                        const dot = runtimeOnline ? '🟢' : (extOnline ? '🟡' : '🔴');
                        const statusText = runtimeOnline ? 'Online' : (extOnline ? 'Extension OK' : 'Offline');
                        const statusColor = runtimeOnline ? 'var(--success)' : (extOnline ? '#f59e0b' : 'var(--error)');
                        const borderColor = (runtimeOnline || extOnline) ? (runtimeOnline ? 'var(--success)' : '#f59e0b') : 'var(--border)';
                        return `<div style="background: var(--surface); border: 1px solid ${borderColor}; border-radius: 10px; padding: 14px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <div>
                                    <span style="font-weight: 700; font-size: 1.1rem;">${dot} ${ext.worker_name}</span>
                                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">${ext.tab_title || ''}</div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 0.75rem; color: ${statusColor}; font-weight: 600;">${statusText}</span>
                                    <button onclick="removeColabExtension('${ext.worker_name}')" class="btn" 
                                        style="padding: 3px 7px; font-size: 0.7rem; background: transparent; border: 1px solid var(--border); color: var(--text-muted);" title="Xóa entry này">
                                        <i class="fa-solid fa-xmark"></i>
                                    </button>
                                </div>
                            </div>
                            <div style="display: flex; gap: 8px;">
                                <button onclick="colabSendCommand('${ext.worker_name}', 'disconnect')" class="btn" 
                                    style="flex:1; padding: 6px; font-size: 0.8rem; background: var(--error);">
                                    <i class="fa-solid fa-power-off"></i> Disconnect
                                </button>
                                <button onclick="colabSendCommand('${ext.worker_name}', 'run_all')" class="btn"
                                    style="flex:1; padding: 6px; font-size: 0.8rem; background: var(--success);">
                                    <i class="fa-solid fa-play"></i> Run All
                                </button>
                            </div>
                        </div>`;
                    }).join('');
                }

                // Render recent commands
                if (data.recent_commands && data.recent_commands.length > 0) {
                    cmdTable.innerHTML = data.recent_commands.map(cmd => {
                        const statusBadge = cmd.status === 'completed' ? 'badge-success' :
                            cmd.status === 'failed' ? 'badge-error' :
                                cmd.status === 'executing' ? 'badge-warning' : 'badge-premium';
                        const cmdLabel = cmd.command === 'disconnect' ? '⏹ Disconnect' : '▶ Run All';
                        return `<tr>
                            <td>${cmd.created_at || '-'}</td>
                            <td><strong>${cmd.worker_name}</strong></td>
                            <td>${cmdLabel}</td>
                            <td><span class="badge ${statusBadge}">${cmd.status}</span></td>
                            <td style="font-size: 0.8rem; color: var(--text-muted);">${cmd.result_message || '-'}</td>
                        </tr>`;
                    }).join('');
                }
            } catch (e) {
                console.error('Load Colab Extensions error:', e);
            }
        }



        async function colabSendCommand(workerName, command) {
            const cmdLabel = command === 'disconnect' ? 'Disconnect' : 'Run All';
            if (!confirm(`Gửi lệnh ${cmdLabel} đến ${workerName}?`)) return;
            try {
                const res = await fetch('api/worker_command.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'send', worker: workerName, command: command })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    // Auto-release ngrok token when disconnecting
                    if (command === 'disconnect') {
                        try {
                            await fetch('api/admin/ngrok_keys.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-Admin-Password': adminPassword
                                },
                                body: JSON.stringify({ action: 'reset_by_worker', worker_name: workerName })
                            });
                        } catch (e) { }
                        if (typeof loadNgrokKeys === 'function') loadNgrokKeys();
                    }
                    alert(`✅ ${data.message}`);
                    loadColabExtensions();
                } else {
                    alert('❌ ' + (data.error || 'Lỗi'));
                }
            } catch (e) { alert('❌ Lỗi kết nối'); }
        }

        async function removeColabExtension(workerName) {
            if (!confirm(`Xóa entry "${workerName}" khỏi danh sách?`)) return;
            try {
                const res = await fetch('api/worker_command.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'remove', worker: workerName })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    loadColabExtensions();
                } else {
                    alert('❌ ' + (data.error || 'Lỗi'));
                }
            } catch (e) { alert('❌ Lỗi kết nối'); }
        }

        async function colabCommandAll(command) {
            const cmdLabel = command === 'disconnect' ? 'DISCONNECT' : 'RUN ALL';
            if (!confirm(`⚠️ Gửi lệnh ${cmdLabel} đến TẤT CẢ workers?`)) return;
            try {
                const res = await fetch('api/worker_command.php?list=1');
                const data = await res.json();
                const onlineWorkers = (data.extensions || []).filter(e => e.is_online == 1);
                for (const ext of onlineWorkers) {
                    await fetch('api/worker_command.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'send', worker: ext.worker_name, command: command })
                    });
                }
                alert(`✅ Đã gửi lệnh ${cmdLabel} đến ${onlineWorkers.length} workers`);
                loadColabExtensions();
            } catch (e) { alert('❌ Lỗi'); }
        }

        // Auto-refresh Colab extensions when on Health tab
        let colabRefreshTimer = null;
        const origSwitchTab = typeof switchTab === 'function' ? switchTab : null;
        function startColabRefresh() {
            loadColabExtensions();
            if (colabRefreshTimer) clearInterval(colabRefreshTimer);
            colabRefreshTimer = setInterval(loadColabExtensions, 10000);
        }

        // Initialize when DOM ready
        document.addEventListener('DOMContentLoaded', () => {
            const lastTab = localStorage.getItem('admin_active_tab');
            if (lastTab === 'ngrok' && typeof loadNgrokKeys === 'function') {
                loadNgrokKeys();
            }
            // Load Colab extensions status when on ngrok tab
            if (lastTab === 'ngrok') {
                startColabRefresh();
            }
        });
    </script>

    <!-- Quill.js CDN -->
    <link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
    <style>
        .ql-toolbar.ql-snow {
            background: #222;
            border-color: var(--border) !important;
        }

        .ql-snow .ql-stroke {
            stroke: #aaa !important;
        }

        .ql-snow .ql-fill {
            fill: #aaa !important;
        }

        .ql-snow .ql-picker-label {
            color: #aaa !important;
        }

        .ql-editor {
            color: white;
            font-size: 1rem;
            line-height: 1.6;
        }

        .ql-editor.ql-blank::before {
            color: #666 !important;
        }
    </style>
    <script>
        let quillEditor = null;

        function initQuill() {
            if (quillEditor) return;
            quillEditor = new Quill('#quillEditor', {
                theme: 'snow',
                placeholder: 'Viết nội dung bài viết tại đây...',
                modules: {
                    toolbar: [
                        [{ 'header': [1, 2, 3, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ 'color': [] }, { 'background': [] }],
                        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                        ['blockquote', 'code-block'],
                        ['link', 'image'],
                        ['clean']
                    ]
                }
            });
        }

        async function loadAdminDocs() {
            try {
                const res = await fetch('api/docs.php?action=admin_list');
                const data = await res.json();
                const tbody = document.getElementById('docsTable');
                const docs = data.documents || [];
                if (docs.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-muted)">Chưa có bài viết nào</td></tr>';
                    return;
                }
                const categoryLabels = { guide: '📖 Hướng dẫn', tips: '💡 Mẹo', template: '📝 Mẫu', news: '📢 Tin tức' };
                tbody.innerHTML = docs.map(d => {
                    const date = new Date(d.created_at).toLocaleDateString('vi-VN');
                    return `<tr>
                        <td>${d.id}</td>
                        <td style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${d.title}</td>
                        <td>${categoryLabels[d.category] || d.category}</td>
                        <td><span class="badge ${d.min_plan === 'pro' ? 'badge-pro' : d.min_plan === 'basic' ? 'badge-warning' : 'badge-success'}">${d.min_plan.toUpperCase()}</span></td>
                        <td><span class="badge ${d.status === 'published' ? 'badge-success' : 'badge-trial'}">${d.status === 'published' ? 'Đã xuất bản' : 'Bản nháp'}</span></td>
                        <td>${date}</td>
                        <td>
                            <button class="btn" style="width:auto;padding:4px 10px;font-size:0.8rem;background:var(--primary);color:white;" onclick="editDocument(${d.id})"><i class="fa-solid fa-pen"></i></button>
                            <button class="btn" style="width:auto;padding:4px 10px;font-size:0.8rem;background:var(--error);color:white;" onclick="deleteDocument(${d.id})"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>`;
                }).join('');
            } catch (e) {
                document.getElementById('docsTable').innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--error)">Lỗi tải dữ liệu</td></tr>';
            }
        }

        function showDocEditor(doc = null) {
            initQuill();
            document.getElementById('docEditorTitle').textContent = doc ? 'Sửa bài viết' : 'Tạo bài viết mới';
            document.getElementById('docEditId').value = doc ? doc.id : '';
            document.getElementById('docTitle').value = doc ? doc.title : '';
            document.getElementById('docSummary').value = doc ? (doc.summary || '') : '';
            document.getElementById('docThumbnail').value = doc ? (doc.thumbnail || '') : '';
            document.getElementById('docCategory').value = doc ? doc.category : 'guide';
            document.getElementById('docMinPlan').value = doc ? doc.min_plan : 'pro';
            document.getElementById('docStatus').value = doc ? doc.status : 'draft';
            quillEditor.root.innerHTML = doc ? (doc.content || '') : '';
            document.getElementById('docEditorModal').style.display = 'flex';
        }

        async function editDocument(id) {
            try {
                const res = await fetch(`api/docs.php?action=detail&id=${id}&token=admin`);
                const data = await res.json();
                if (data.document) {
                    showDocEditor(data.document);
                }
            } catch (e) { alert('Lỗi tải bài viết'); }
        }

        async function saveDocument() {
            const id = document.getElementById('docEditId').value;
            const payload = {
                action: id ? 'update' : 'create',
                id: id ? parseInt(id) : undefined,
                title: document.getElementById('docTitle').value,
                summary: document.getElementById('docSummary').value,
                thumbnail: document.getElementById('docThumbnail').value,
                category: document.getElementById('docCategory').value,
                min_plan: document.getElementById('docMinPlan').value,
                status: document.getElementById('docStatus').value,
                content: quillEditor.root.innerHTML
            };

            try {
                const res = await fetch('api/docs.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    closeModal('docEditorModal');
                    loadAdminDocs();
                    alert(id ? '✅ Đã cập nhật bài viết!' : '✅ Đã tạo bài viết mới!');
                } else {
                    alert('❌ Lỗi: ' + (data.error || 'Unknown'));
                }
            } catch (e) { alert('❌ Lỗi kết nối'); }
        }

        async function deleteDocument(id) {
            if (!confirm('Bạn có chắc muốn xóa bài viết này?')) return;
            try {
                const res = await fetch('api/docs.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', id })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    loadAdminDocs();
                    alert('✅ Đã xóa bài viết!');
                }
            } catch (e) { alert('❌ Lỗi kết nối'); }
        }
    </script>
</body>

</html>