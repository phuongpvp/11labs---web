<?php
session_start();
require_once __DIR__ . '/api/config.php';

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: quocprovgy147.php');
    exit;
}

// Helper: verify admin password (reuse logic from config.php)
function checkAdminPwd($pwd) {
    // Check hashed password first (from settings.json)
    $settingsFile = __DIR__ . '/data/settings.json';
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true) ?? [];
        if (!empty($settings['admin_password_hash'])) {
            return password_verify($pwd, $settings['admin_password_hash']);
        }
    }
    // Fallback: plaintext from config.php
    return $pwd === ADMIN_PASSWORD;
}

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    if (checkAdminPwd($_POST['admin_password'])) {
        $_SESSION['wl_admin_authenticated'] = true;
        $_SESSION['wl_admin_login_time'] = time();
        header('Location: quocprovgy147.php');
        exit;
    } else {
        $loginError = 'Sai mật khẩu!';
    }
}

// Session expires after 24 hours
if (isset($_SESSION['wl_admin_login_time']) && (time() - $_SESSION['wl_admin_login_time']) > 86400) {
    session_destroy();
    header('Location: quocprovgy147.php');
    exit;
}

// If not authenticated, show login form
if (!isset($_SESSION['wl_admin_authenticated']) || $_SESSION['wl_admin_authenticated'] !== true) {
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="auth-wrapper" id="loginScreen">
        <div class="auth-box fade-in">
            <h1>🔒 Quản lý</h1>
            <p class="subtitle">Nhập mật khẩu Admin để tiếp tục</p>
            <div class="card">
                <?php if (isset($loginError)): ?>
                    <div class="auth-error" style="display:block;"><?= htmlspecialchars($loginError) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <div class="form-group">
                        <label>Mật khẩu Admin</label>
                        <input type="password" name="admin_password" placeholder="Nhập mật khẩu..." autofocus required>
                    </div>
                    <button class="btn btn-primary" style="width:100%" type="submit">
                        <i class="fa-solid fa-lock-open"></i> Đăng nhập
                    </button>
                </form>
            </div>
        </div>
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
    <title>Quản lý - Admin</title>
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>

<body>
    <!-- Login Screen -->
    <div class="auth-wrapper" id="loginScreen">
        <div class="auth-box fade-in">
            <h1>🔐 Quản lý</h1>
            <p class="subtitle">Nhập mật khẩu Admin để tiếp tục</p>
            <div class="card">
                <div id="errorBox" class="auth-error"></div>
                <div class="form-group">
                    <label>Mật khẩu Admin</label>
                    <input type="password" id="adminPwd" placeholder="Nhập mật khẩu...">
                </div>
                <button class="btn btn-primary" style="width:100%" onclick="doAdminLogin()">
                    <i class="fa-solid fa-lock-open"></i> Đăng nhập
                </button>
            </div>
        </div>
    </div>

    <!-- Admin Panel -->
    <div id="adminPanel" style="display:none;">
        <div class="header">
            <div class="header-brand">
                <h1><i class="fa-solid fa-shield-halved"></i> Quản lý Khách hàng</h1>
            </div>
            <div class="header-user">
                <a href="app.html" class="btn btn-sm btn-outline"><i class="fa-solid fa-arrow-left"></i> Về trang
                    TTS</a>
                <button class="btn-logout" onclick="adminLogout()"><i class="fa-solid fa-sign-out"></i> Thoát</button>
            </div>
        </div>

        <div class="container-wide">
            <!-- Stats -->
            <div class="stats-grid fade-in" id="statsGrid">
                <div class="stat-card">
                    <div class="stat-value" id="statTotal">--</div>
                    <div class="stat-label">Quota tổng</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="statRemaining">--</div>
                    <div class="stat-label">Còn lại</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="statAllocated">--</div>
                    <div class="stat-label">Đã cấp cho khách</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="statCustomers">--</div>
                    <div class="stat-label">Số khách</div>
                </div>
            </div>

            <!-- Settings -->
            <div class="card fade-in" style="margin-bottom:20px;">
                <div class="flex-between mb-4" style="cursor:pointer;" onclick="toggleSettings()">
                    <div class="card-title" style="margin-bottom:0;"><i class="fa-solid fa-gear"></i> Cài đặt Site</div>
                    <i class="fa-solid fa-chevron-down" id="settingsArrow"
                        style="color:var(--text-muted);transition:.2s;"></i>
                </div>
                <div id="settingsBody" style="display:none;">
                    <div class="form-group">
                        <label>API Key <span style="color:var(--primary);font-size:0.75rem;">(lấy từ
                                Admin)</span></label>
                        <input type="text" id="settApiKey" placeholder="PL_xxxxxxxxxx">
                        <div style="font-size:0.72rem;color:var(--text-muted);margin-top:4px;">Nhập API Key được cấp để
                            kết nối hệ thống TTS</div>
                    </div>
                    <div class="form-group">
                        <label>Tên Site</label>
                        <input type="text" id="settSiteName" placeholder="Ví dụ: My TTS Pro">
                    </div>
                    <div class="form-group">
                        <label>Logo <span style="font-size:0.72rem;color:var(--text-muted);">(đường dẫn file, ví dụ:
                                assets/logo.png)</span></label>
                        <input type="text" id="settLogo" placeholder="assets/logo.png">
                    </div>

                    <!-- Bank Info -->
                    <div style="border-top:1px solid var(--border);margin-top:20px;padding-top:20px;">
                        <div style="font-size:0.88rem;font-weight:700;margin-bottom:12px;"><i
                                class="fa-solid fa-building-columns" style="color:var(--primary);"></i> Thông tin chuyển
                            khoản</div>
                        <div style="font-size:0.72rem;color:var(--text-muted);margin-bottom:12px;">Khách hàng sẽ thấy
                            thông tin này khi mua gói</div>
                        <div class="form-group">
                            <label>Tên ngân hàng</label>
                            <input type="text" id="settBankName" placeholder="Ví dụ: Vietcombank">
                        </div>
                        <div class="form-group">
                            <label>Số tài khoản</label>
                            <input type="text" id="settBankAccount" placeholder="Số tài khoản ngân hàng">
                        </div>
                        <div class="form-group">
                            <label>Chủ tài khoản</label>
                            <input type="text" id="settBankOwner" placeholder="Tên chủ tài khoản">
                        </div>
                        <div class="form-group">
                            <label>Ghi chú CK <span style="font-size:0.72rem;color:var(--text-muted);">(tùy
                                    chọn)</span></label>
                            <input type="text" id="settBankNote"
                                placeholder="Ví dụ: Nội dung CK: [email] mua goi [tên gói]">
                        </div>
                    </div>

                    <!-- Popup Khuyến mãi -->
                    <div style="border-top:1px solid var(--border);margin-top:20px;padding-top:20px;">
                        <div style="font-size:0.88rem;font-weight:700;margin-bottom:12px;color:#f59e0b;"><i
                                class="fa-solid fa-bullhorn"></i> Popup Khuyến mãi (Dashboard)</div>
                        <div style="font-size:0.72rem;color:var(--text-muted);margin-bottom:12px;">Popup ảnh sẽ
                            hiển thị cho khách hàng khi vào trang TTS</div>
                        <div class="form-group" style="display:flex;align-items:center;gap:10px;">
                            <input type="checkbox" id="settPopupEnabled"
                                style="width:18px;height:18px;accent-color:var(--primary);cursor:pointer;">
                            <label style="margin:0;cursor:pointer;" for="settPopupEnabled">Kích hoạt Popup</label>
                        </div>
                        <div class="form-group">
                            <label>Link ảnh Popup (JPG/PNG/GIF)</label>
                            <input type="text" id="settPopupImage" placeholder="https://example.com/banner.jpg">
                        </div>
                        <div class="form-group">
                            <label>Link đích khi click <span style="font-size:0.72rem;color:var(--text-muted);">(Tùy
                                    chọn)</span></label>
                            <input type="text" id="settPopupLink" placeholder="https://zalo.me/... hoặc #">
                        </div>
                        <div class="form-group">
                            <label>Tần suất hiển thị</label>
                            <select id="settPopupFrequency"
                                style="width:100%;padding:10px 14px;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:0.88rem;">
                                <option value="always">Hiện liên tục (Để test)</option>
                                <option value="once_per_day" selected>1 lần mỗi 24 giờ (Thực tế)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Mạng xã hội -->
                    <div style="border-top:1px solid var(--border);margin-top:20px;padding-top:20px;">
                        <div style="font-size:0.88rem;font-weight:700;margin-bottom:12px;"><i
                                class="fa-solid fa-share-nodes" style="color:#06b6d4;"></i> Mạng xã hội (Header)</div>
                        <div class="form-group">
                            <label><i class="fa-solid fa-comment-dots" style="color:#0068FF;"></i> Zalo Link</label>
                            <input type="text" id="settSocialZalo" placeholder="https://zalo.me/...">
                        </div>
                        <div class="form-group">
                            <label><i class="fa-brands fa-telegram" style="color:#24A1DE;"></i> Telegram Link</label>
                            <input type="text" id="settSocialTelegram" placeholder="https://t.me/...">
                        </div>
                        <div class="form-group">
                            <label><i class="fa-brands fa-facebook" style="color:#1877F2;"></i> Facebook Link</label>
                            <input type="text" id="settSocialFacebook" placeholder="https://facebook.com/...">
                        </div>
                        <div class="form-group">
                            <label><i class="fa-brands fa-youtube" style="color:#FF0000;"></i> YouTube Link</label>
                            <input type="text" id="settSocialYoutube" placeholder="https://youtube.com/...">
                        </div>
                    </div>

                    <!-- Affiliate / Giới thiệu -->
                    <div style="border-top:1px solid var(--border);margin-top:20px;padding-top:20px;">
                        <div style="font-size:0.88rem;font-weight:700;margin-bottom:12px;color:#22c55e;"><i
                                class="fa-solid fa-users" style="color:#22c55e;"></i> Hệ thống giới thiệu (Affiliate)</div>
                        <div style="font-size:0.72rem;color:var(--text-muted);margin-bottom:12px;">Khi bật, khách hàng có thể giới thiệu bạn bè và nhận bonus khi người được giới thiệu mua gói</div>
                        <div class="form-group" style="display:flex;align-items:center;gap:10px;">
                            <input type="checkbox" id="settAffiliateEnabled"
                                style="width:18px;height:18px;accent-color:#22c55e;cursor:pointer;">
                            <label style="margin:0;cursor:pointer;" for="settAffiliateEnabled">Bật hệ thống giới thiệu</label>
                        </div>
                        <div class="form-group">
                            <label>% Hoa hồng (bonus = quota_gói × %)</label>
                            <input type="number" id="settAffiliateRate" value="10" min="0" max="100" step="1" placeholder="10">
                            <div style="font-size:0.72rem;color:var(--text-muted);margin-top:4px;">Ví dụ: 10% → Gói 100,000 ký tự → Người giới thiệu nhận 10,000 ký tự bonus</div>
                        </div>
                    </div>

                    <!-- Telegram Thông báo -->
                    <div style="border-top:1px solid var(--border);margin-top:20px;padding-top:20px;">
                        <div style="font-size:0.88rem;font-weight:700;margin-bottom:12px;color:#24A1DE;"><i
                                class="fa-brands fa-telegram" style="color:#24A1DE;"></i> Thông báo Telegram</div>
                        <div style="font-size:0.72rem;color:var(--text-muted);margin-bottom:12px;">Nhận thông báo qua Telegram khi có đơn hàng mới</div>
                        <div class="form-group">
                            <label>Bot Token</label>
                            <input type="text" id="settTelegramToken" placeholder="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11">
                        </div>
                        <div class="form-group">
                            <label>Chat ID</label>
                            <input type="text" id="settTelegramChatId" placeholder="-100123456789 hoặc ID cá nhân">
                            <div style="font-size:0.72rem;color:var(--text-muted);margin-top:4px;">Dùng @userinfobot hoặc @RawDataBot để lấy Chat ID</div>
                        </div>
                    </div>

                    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
                        <button class="btn btn-primary" onclick="saveSettings()">
                            <i class="fa-solid fa-check"></i> Lưu cài đặt
                        </button>
                    </div>
                    <div id="settingsMsg"
                        style="display:none;margin-top:10px;padding:8px 12px;border-radius:8px;font-size:0.82rem;">
                    </div>

                    <!-- Admin Password Change -->
                    <div style="border-top:1px solid var(--border);margin-top:20px;padding-top:20px;">
                        <div style="font-size:0.88rem;font-weight:700;margin-bottom:12px;"><i class="fa-solid fa-key"
                                style="color:var(--primary);"></i> Đổi mật khẩu Admin</div>
                        <div class="form-group">
                            <label>Mật khẩu mới</label>
                            <input type="password" id="newAdminPwd" placeholder="Tối thiểu 6 ký tự">
                        </div>
                        <div class="form-group">
                            <label>Xác nhận mật khẩu mới</label>
                            <input type="password" id="confirmAdminPwd" placeholder="Nhập lại mật khẩu mới">
                        </div>
                        <button class="btn btn-primary btn-sm" onclick="changeAdminPassword()">
                            <i class="fa-solid fa-shield-halved"></i> Đổi mật khẩu Admin
                        </button>
                        <div id="adminPwdMsg"
                            style="display:none;margin-top:10px;padding:8px 12px;border-radius:8px;font-size:0.82rem;">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Plans Management -->
            <div class="card fade-in" style="margin-bottom:20px;">
                <div class="flex-between mb-4" style="cursor:pointer;" onclick="togglePlans()">
                    <div class="card-title" style="margin-bottom:0;"><i class="fa-solid fa-box-open"></i> Quản lý gói
                        dịch vụ</div>
                    <i class="fa-solid fa-chevron-down" id="plansArrow"
                        style="color:var(--text-muted);transition:.2s;"></i>
                </div>
                <div id="plansSection" style="display:none;">
                    <div class="flex-between mb-4">
                        <div style="font-size:0.82rem;color:var(--text-muted);">Tạo các gói dịch vụ để nhanh chóng cấp
                            quota cho khách hàng</div>
                        <button class="btn btn-primary btn-sm" onclick="showPlanModal()">
                            <i class="fa-solid fa-plus"></i> Thêm gói
                        </button>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="data-table" id="plansTable">
                            <thead>
                                <tr>
                                    <th>Tên gói</th>
                                    <th>Quota (ký tự)</th>
                                    <th>Giá</th>
                                    <th>Trạng thái</th>
                                    <th>Hành động</th>
                                </tr>
                            </thead>
                            <tbody id="plansBody">
                                <tr>
                                    <td colspan="5" style="text-align:center;color:var(--text-muted);padding:20px;">Đang
                                        tải...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="planMsg"
                        style="display:none;margin-top:10px;padding:8px 12px;border-radius:8px;font-size:0.82rem;">
                    </div>
                </div>
            </div>

            <!-- Order Management -->
            <div class="card fade-in" style="margin-bottom:20px;">
                <div class="flex-between mb-4" style="cursor:pointer;" onclick="toggleOrders()">
                    <div class="card-title" style="margin-bottom:0;"><i class="fa-solid fa-receipt"></i> Đơn mua gói
                        <span id="pendingOrderCount"
                            style="background:var(--error);color:#fff;font-size:0.7rem;padding:2px 8px;border-radius:10px;margin-left:6px;display:none;"></span>
                    </div>
                    <i class="fa-solid fa-chevron-down" id="ordersArrow"
                        style="color:var(--text-muted);transition:.2s;"></i>
                </div>
                <div id="ordersSection" style="display:none;">
                    <div style="overflow-x:auto;">
                        <table class="data-table" id="ordersTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Khách hàng</th>
                                    <th>Gói</th>
                                    <th>Giá</th>
                                    <th>Quota</th>
                                    <th>Trạng thái</th>
                                    <th>Ngày tạo</th>
                                    <th>Hành động</th>
                                </tr>
                            </thead>
                            <tbody id="ordersBody">
                                <tr>
                                    <td colspan="8" style="text-align:center;color:var(--text-muted);padding:20px;">Đang
                                        tải...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Customer List -->
            <div class="card fade-in">
                <div class="flex-between mb-4">
                    <div class="card-title" style="margin-bottom:0;"><i class="fa-solid fa-users"></i> Danh sách khách
                        hàng</div>
                    <button class="btn btn-primary btn-sm" onclick="showAddModal()">
                        <i class="fa-solid fa-user-plus"></i> Thêm khách
                    </button>
                </div>
                <div style="overflow-x:auto;">
                    <table class="data-table" id="customerTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Email</th>
                                <th>Tên</th>
                                <th>Gói</th>
                                <th>Quota cấp</th>
                                <th>Đã dùng</th>
                                <th>Còn lại</th>
                                <th>Trạng thái</th>
                                <th>Hành động</th>
                            </tr>
                        </thead>
                        <tbody id="customerBody">
                            <tr>
                                <td colspan="9" style="text-align:center;color:var(--text-muted);padding:30px;">Đang
                                    tải...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div class="modal-overlay" id="modalOverlay" onclick="if(event.target===this)closeModal()">
        <div class="modal">
            <h2 id="modalTitle">Thêm khách hàng</h2>
            <div class="form-group">
                <label>Email</label>
                <input type="email" id="modalEmail" placeholder="email@example.com">
            </div>
            <div class="form-group">
                <label>Mật khẩu</label>
                <input type="password" id="modalPassword" placeholder="Tối thiểu 6 ký tự">
            </div>
            <div class="form-group">
                <label>Tên hiển thị</label>
                <input type="text" id="modalName" placeholder="Tên khách">
            </div>
            <div class="form-group">
                <label>Quota cấp (ký tự)</label>
                <input type="number" id="modalQuota" value="10000" min="0" step="1000">
            </div>
            <div class="flex gap-2" style="justify-content:flex-end;margin-top:20px;">
                <button class="btn btn-outline" onclick="closeModal()">Hủy</button>
                <button class="btn btn-primary" id="modalSaveBtn" onclick="saveCustomer()">
                    <i class="fa-solid fa-check"></i> Lưu
                </button>
            </div>
            <input type="hidden" id="modalCustomerId" value="">
        </div>
    </div>

    <!-- Plan Modal -->
    <div class="modal-overlay" id="planModalOverlay" onclick="if(event.target===this)closePlanModal()">
        <div class="modal">
            <h2 id="planModalTitle">Thêm gói dịch vụ</h2>
            <div class="form-group">
                <label>Tên gói</label>
                <input type="text" id="planName" placeholder="Ví dụ: Gói Starter">
            </div>
            <div class="form-group">
                <label>Quota (ký tự)</label>
                <input type="number" id="planQuota" value="5000" min="0" step="1000">
            </div>
            <div class="form-group">
                <label>Giá (hiển thị)</label>
                <input type="text" id="planPrice" placeholder="Ví dụ: 100,000 VNĐ">
            </div>
            <div class="form-group">
                <label>Mô tả <span style="color:var(--text-muted);font-size:0.75rem;">(tùy chọn)</span></label>
                <input type="text" id="planDesc" placeholder="Gói phổ thông cho người mới">
            </div>
            <div class="flex gap-2" style="justify-content:flex-end;margin-top:20px;">
                <button class="btn btn-outline" onclick="closePlanModal()">Hủy</button>
                <button class="btn btn-primary" onclick="savePlan()">
                    <i class="fa-solid fa-check"></i> Lưu
                </button>
            </div>
            <input type="hidden" id="planModalId" value="">
        </div>
    </div>

    <!-- Activate Plan Modal -->
    <div class="modal-overlay" id="activateModalOverlay" onclick="if(event.target===this)closeActivateModal()">
        <div class="modal">
            <h2><i class="fa-solid fa-gift" style="color:var(--primary);"></i> Cấp gói dịch vụ</h2>
            <p id="activateCustomerInfo" style="color:var(--text-muted);margin-bottom:16px;"></p>
            <div class="form-group">
                <label>Chọn gói</label>
                <select id="activatePlanSelect"
                    style="width:100%;padding:10px 14px;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:0.88rem;">
                    <option value="">-- Chọn gói --</option>
                </select>
            </div>
            <div id="activatePlanInfo"
                style="display:none;padding:12px;background:rgba(124,58,237,0.1);border-radius:8px;margin-bottom:16px;font-size:0.85rem;">
            </div>
            <div class="flex gap-2" style="justify-content:flex-end;margin-top:20px;">
                <button class="btn btn-outline" onclick="closeActivateModal()">Hủy</button>
                <button class="btn btn-primary" onclick="confirmActivatePlan()">
                    <i class="fa-solid fa-check"></i> Kích hoạt
                </button>
            </div>
            <input type="hidden" id="activateCustomerId" value="">
        </div>
    </div>

    <script>
        const API = 'api';
        let adminPassword = '';
        let EXT_API = '';
        let EXT_KEY = '';
        const fmt = n => Number(n || 0).toLocaleString('vi-VN');

        // Check saved password
        const savedPwd = sessionStorage.getItem('admin_pwd');
        if (savedPwd) {
            adminPassword = savedPwd;
            showAdminPanel();
        }

        function doAdminLogin() {
            const pwd = document.getElementById('adminPwd').value;
            if (!pwd) {
                document.getElementById('errorBox').textContent = 'Vui lòng nhập mật khẩu';
                document.getElementById('errorBox').style.display = 'block';
                return;
            }
            adminPassword = pwd;
            sessionStorage.setItem('admin_pwd', pwd);
            showAdminPanel();
        }

        document.getElementById('adminPwd').addEventListener('keydown', e => {
            if (e.key === 'Enter') doAdminLogin();
        });

        function showAdminPanel() {
            document.getElementById('loginScreen').style.display = 'none';
            document.getElementById('adminPanel').style.display = 'block';
            loadPartnerInfo();
            loadCustomers();
            checkPendingOrders();
        }

        function adminLogout() {
            sessionStorage.removeItem('admin_pwd');
            location.reload();
        }

        // ─── Partner Info ───

        async function loadConfig() {
            try {
                const res = await fetch(`${API}/get_config.php`);
                const data = await res.json();
                if (data.status === 'success') {
                    EXT_API = data.api_base;
                    EXT_KEY = data.api_key;
                    if (data.site_name) document.querySelector('h1').innerHTML = `<i class="fa-solid fa-shield-halved"></i> ${data.site_name} - Quản lý`;
                }
            } catch (e) { console.error('Config error:', e); }
        }

        async function loadPartnerInfo() {
            // Always ensure config is loaded
            if (!EXT_API || !EXT_KEY) {
                await loadConfig();
            }

            try {
                // Get partner quota from External API (direct from browser)
                const extUrl = `${EXT_API}/user.php?api_key=${EXT_KEY}`;
                console.log('Calling:', extUrl);
                const extRes = await fetch(extUrl);
                const extData = await extRes.json();
                console.log('Partner data:', extData);

                // Get local customer stats
                const localRes = await fetch(`${API}/customers.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'list', admin_password: adminPassword })
                });
                const localData = await localRes.json();

                if (localRes.status === 401) {
                    alert('Mật khẩu không đúng!');
                    adminLogout();
                    return;
                }

                // Calculate stats
                const customers = localData.customers || [];
                const totalAllocated = customers.reduce((s, c) => s + (parseInt(c.quota_allocated) || 0), 0);

                document.getElementById('statTotal').textContent = fmt(extData.quota_total ?? extData.character_limit ?? '--');
                document.getElementById('statRemaining').textContent = fmt(extData.remaining_quota ?? extData.character_remaining ?? '--');
                document.getElementById('statAllocated').textContent = fmt(totalAllocated);
                document.getElementById('statCustomers').textContent = customers.length;
            } catch (e) {
                console.error('Partner info error:', e);
                // Still load customer count even if External API fails
                try {
                    const localRes = await fetch(`${API}/customers.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'list', admin_password: adminPassword })
                    });
                    const localData = await localRes.json();
                    const customers = localData.customers || [];
                    const totalAllocated = customers.reduce((s, c) => s + (parseInt(c.quota_allocated) || 0), 0);
                    document.getElementById('statAllocated').textContent = fmt(totalAllocated);
                    document.getElementById('statCustomers').textContent = customers.length;
                } catch (e2) { }
            }
        }

        // ─── Customers ───
        async function loadCustomers() {
            try {
                const res = await fetch(`${API}/customers.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'list', admin_password: adminPassword })
                });
                const data = await res.json();

                if (data.status === 'success') {
                    renderCustomers(data.customers);
                }
            } catch (e) { }
        }

        function renderCustomers(customers) {
            const tbody = document.getElementById('customerBody');
            if (!customers.length) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--text-muted);padding:30px;">Chưa có khách hàng. Bấm "Thêm khách" để bắt đầu.</td></tr>';
                return;
            }

            tbody.innerHTML = customers.map(c => {
                const remaining = Math.max(0, (c.quota_allocated || 0) - (c.quota_used || 0));
                return `<tr>
                    <td>${c.id}</td>
                    <td>${c.email}</td>
                    <td>${c.display_name || '--'}</td>
                    <td>${c.plan_name ? '<span style="color:var(--primary);font-weight:600;">' + c.plan_name + '</span>' : '<span style="color:var(--text-muted)">--</span>'}</td>
                    <td>${fmt(c.quota_allocated)}</td>
                    <td>${fmt(c.quota_used)}</td>
                    <td>${fmt(remaining)}</td>
                    <td>${c.is_active ? '<span style="color:var(--success)">✅ Active</span>' : '<span style="color:var(--error)">🔒 Khóa</span>'}</td>
                    <td>
                        <button class="btn btn-sm btn-outline" style="border-color:var(--success);color:var(--success)" onclick="showActivateModal(${c.id}, '${c.email}')" title="Cấp gói">
                            <i class="fa-solid fa-gift"></i>
                        </button>
                        <button class="btn btn-sm btn-outline" onclick="editCustomer(${c.id}, '${c.email}', '${c.display_name || ''}', ${c.quota_allocated || 0})" title="Sửa">
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <button class="btn btn-sm btn-outline" style="border-color:var(--primary);color:var(--primary)" onclick="resetCustomerPassword(${c.id}, '${c.email}')" title="Đổi mật khẩu">
                            <i class="fa-solid fa-key"></i>
                        </button>
                        <button class="btn btn-sm btn-outline" style="border-color:var(--${c.is_active ? 'warning' : 'success'});color:var(--${c.is_active ? 'warning' : 'success'})" onclick="toggleActive(${c.id}, ${c.is_active ? 0 : 1})" title="${c.is_active ? 'Khóa' : 'Mở khóa'}">
                            <i class="fa-solid fa-${c.is_active ? 'lock' : 'lock-open'}"></i>
                        </button>
                        <button class="btn btn-sm btn-outline" style="border-color:var(--error);color:var(--error)" onclick="deleteCustomer(${c.id}, '${c.email}')" title="Xóa">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        // ─── Modal ───
        function showAddModal() {
            document.getElementById('modalTitle').textContent = 'Thêm khách hàng';
            document.getElementById('modalEmail').value = '';
            document.getElementById('modalEmail').disabled = false;
            document.getElementById('modalPassword').value = '';
            document.getElementById('modalName').value = '';
            document.getElementById('modalQuota').value = '10000';
            document.getElementById('modalCustomerId').value = '';
            document.getElementById('modalOverlay').classList.add('active');
        }

        function editCustomer(id, email, name, quota) {
            document.getElementById('modalTitle').textContent = 'Sửa khách hàng';
            document.getElementById('modalEmail').value = email;
            document.getElementById('modalEmail').disabled = true;
            document.getElementById('modalPassword').value = '';
            document.getElementById('modalName').value = name;
            document.getElementById('modalQuota').value = quota;
            document.getElementById('modalCustomerId').value = id;
            document.getElementById('modalOverlay').classList.add('active');
        }

        function closeModal() {
            document.getElementById('modalOverlay').classList.remove('active');
        }

        async function saveCustomer() {
            const id = document.getElementById('modalCustomerId').value;
            const email = document.getElementById('modalEmail').value.trim();
            const password = document.getElementById('modalPassword').value;
            const name = document.getElementById('modalName').value.trim();
            const quota = parseInt(document.getElementById('modalQuota').value) || 0;

            if (!id && (!email || !password)) {
                return alert('Email và mật khẩu là bắt buộc');
            }

            const payload = { admin_password: adminPassword };

            if (id) {
                // Update
                payload.action = 'update';
                payload.customer_id = parseInt(id);
                payload.display_name = name;
                payload.quota_allocated = quota;
                if (password) payload.password = password;
            } else {
                // Create
                payload.action = 'create';
                payload.email = email;
                payload.password = password;
                payload.display_name = name;
                payload.quota_allocated = quota;
            }

            try {
                const res = await fetch(`${API}/customers.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();

                if (data.status === 'success') {
                    closeModal();
                    loadCustomers();
                    loadPartnerInfo();
                } else {
                    alert(data.error || 'Lỗi lưu');
                }
            } catch (e) {
                alert('Lỗi kết nối');
            }
        }

        async function toggleActive(id, newState) {
            try {
                await fetch(`${API}/customers.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update',
                        admin_password: adminPassword,
                        customer_id: id,
                        is_active: newState
                    })
                });
                loadCustomers();
            } catch (e) { }
        }

        async function deleteCustomer(id, email) {
            if (!confirm(`Xóa khách "${email}"?\n\nDữ liệu sẽ bị mất vĩnh viễn!`)) return;

            try {
                await fetch(`${API}/customers.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete',
                        admin_password: adminPassword,
                        customer_id: id
                    })
                });
                loadCustomers();
                loadPartnerInfo();
            } catch (e) { }
        }

        // ─── Settings ───
        function toggleSettings() {
            const body = document.getElementById('settingsBody');
            const arrow = document.getElementById('settingsArrow');
            if (body.style.display === 'none') {
                body.style.display = 'block';
                arrow.style.transform = 'rotate(180deg)';
                loadSettings();
            } else {
                body.style.display = 'none';
                arrow.style.transform = '';
            }
        }

        async function loadSettings() {
            try {
                const res = await fetch(`${API}/settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'get', admin_password: adminPassword })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    const s = data.settings;
                    document.getElementById('settApiKey').value = s.api_key || '';
                    document.getElementById('settSiteName').value = s.site_name || '';
                    document.getElementById('settLogo').value = s.site_logo || '';
                    document.getElementById('settBankName').value = s.bank_name || '';
                    document.getElementById('settBankAccount').value = s.bank_account || '';
                    document.getElementById('settBankOwner').value = s.bank_owner || '';
                    document.getElementById('settBankNote').value = s.bank_note || '';
                    document.getElementById('settPopupEnabled').checked = !!s.popup_enabled;
                    document.getElementById('settPopupImage').value = s.popup_image || '';
                    document.getElementById('settPopupLink').value = s.popup_link || '';
                    document.getElementById('settPopupFrequency').value = s.popup_frequency || 'once_per_day';
                    document.getElementById('settSocialZalo').value = s.social_zalo || '';
                    document.getElementById('settSocialTelegram').value = s.social_telegram || '';
                    document.getElementById('settSocialFacebook').value = s.social_facebook || '';
                    document.getElementById('settSocialYoutube').value = s.social_youtube || '';
                    document.getElementById('settAffiliateEnabled').checked = !!s.affiliate_enabled;
                    document.getElementById('settAffiliateRate').value = s.affiliate_commission_rate || 10;
                    document.getElementById('settTelegramToken').value = s.telegram_bot_token || '';
                    document.getElementById('settTelegramChatId').value = s.telegram_chat_id || '';
                }
            } catch (e) { }
        }

        async function saveSettings() {
            const msg = document.getElementById('settingsMsg');
            try {
                const res = await fetch(`${API}/settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save',
                        admin_password: adminPassword,
                        api_key: document.getElementById('settApiKey').value.trim(),
                        site_name: document.getElementById('settSiteName').value.trim(),
                        site_logo: document.getElementById('settLogo').value.trim(),
                        bank_name: document.getElementById('settBankName').value.trim(),
                        bank_account: document.getElementById('settBankAccount').value.trim(),
                        bank_owner: document.getElementById('settBankOwner').value.trim(),
                        bank_note: document.getElementById('settBankNote').value.trim(),
                        popup_enabled: document.getElementById('settPopupEnabled').checked,
                        popup_image: document.getElementById('settPopupImage').value.trim(),
                        popup_link: document.getElementById('settPopupLink').value.trim(),
                        popup_frequency: document.getElementById('settPopupFrequency').value,
                        social_zalo: document.getElementById('settSocialZalo').value.trim(),
                        social_telegram: document.getElementById('settSocialTelegram').value.trim(),
                        social_facebook: document.getElementById('settSocialFacebook').value.trim(),
                        social_youtube: document.getElementById('settSocialYoutube').value.trim(),
                        affiliate_enabled: document.getElementById('settAffiliateEnabled').checked,
                        affiliate_commission_rate: parseFloat(document.getElementById('settAffiliateRate').value) || 10,
                        telegram_bot_token: document.getElementById('settTelegramToken').value.trim(),
                        telegram_chat_id: document.getElementById('settTelegramChatId').value.trim()
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    msg.style.display = 'block';
                    msg.style.background = 'rgba(34,197,94,0.15)';
                    msg.style.color = 'var(--success)';
                    msg.textContent = '✅ ' + data.message;
                    loadPartnerInfo();
                    setTimeout(() => msg.style.display = 'none', 3000);
                } else {
                    msg.style.display = 'block';
                    msg.style.background = 'rgba(239,68,68,0.15)';
                    msg.style.color = 'var(--error)';
                    msg.textContent = '❌ ' + (data.error || 'Lỗi');
                }
            } catch (e) {
                msg.style.display = 'block';
                msg.style.background = 'rgba(239,68,68,0.15)';
                msg.style.color = 'var(--error)';
                msg.textContent = '❌ Lỗi kết nối';
            }
        }

        // ─── Admin Password Change ───
        async function changeAdminPassword() {
            const newPwd = document.getElementById('newAdminPwd').value;
            const confirmPwd = document.getElementById('confirmAdminPwd').value;
            const msg = document.getElementById('adminPwdMsg');

            if (!newPwd || newPwd.length < 6) {
                msg.style.display = 'block';
                msg.style.background = 'rgba(239,68,68,0.15)';
                msg.style.color = 'var(--error)';
                msg.textContent = '❌ Mật khẩu mới phải có ít nhất 6 ký tự';
                return;
            }
            if (newPwd !== confirmPwd) {
                msg.style.display = 'block';
                msg.style.background = 'rgba(239,68,68,0.15)';
                msg.style.color = 'var(--error)';
                msg.textContent = '❌ Mật khẩu xác nhận không khớp';
                return;
            }

            try {
                const res = await fetch(`${API}/settings.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'change_admin_password',
                        admin_password: adminPassword,
                        new_password: newPwd
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    msg.style.display = 'block';
                    msg.style.background = 'rgba(34,197,94,0.15)';
                    msg.style.color = 'var(--success)';
                    msg.textContent = '✅ ' + data.message;
                    document.getElementById('newAdminPwd').value = '';
                    document.getElementById('confirmAdminPwd').value = '';
                    // Update session password
                    adminPassword = newPwd;
                    sessionStorage.setItem('admin_pwd', newPwd);
                    setTimeout(() => msg.style.display = 'none', 4000);
                } else {
                    msg.style.display = 'block';
                    msg.style.background = 'rgba(239,68,68,0.15)';
                    msg.style.color = 'var(--error)';
                    msg.textContent = '❌ ' + (data.error || 'Lỗi');
                }
            } catch (e) {
                msg.style.display = 'block';
                msg.style.background = 'rgba(239,68,68,0.15)';
                msg.style.color = 'var(--error)';
                msg.textContent = '❌ Lỗi kết nối';
            }
        }

        // ─── Reset Customer Password ───
        async function resetCustomerPassword(customerId, email) {
            const newPwd = prompt(`Nhập mật khẩu mới cho "${email}":`);
            if (!newPwd) return;
            if (newPwd.length < 6) {
                alert('Mật khẩu phải có ít nhất 6 ký tự');
                return;
            }

            try {
                const res = await fetch(`${API}/customers.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'update',
                        admin_password: adminPassword,
                        customer_id: customerId,
                        password: newPwd
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alert(`✅ Đã đổi mật khẩu cho "${email}" thành công!`);
                } else {
                    alert('❌ ' + (data.error || 'Lỗi đổi mật khẩu'));
                }
            } catch (e) {
                alert('❌ Lỗi kết nối');
            }
        }

        // ─── Plans Management ───
        let cachedPlans = [];

        function togglePlans() {
            const body = document.getElementById('plansSection');
            const arrow = document.getElementById('plansArrow');
            if (body.style.display === 'none') {
                body.style.display = 'block';
                arrow.style.transform = 'rotate(180deg)';
                loadPlans();
            } else {
                body.style.display = 'none';
                arrow.style.transform = '';
            }
        }

        async function loadPlans() {
            try {
                const res = await fetch(`${API}/plans.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'list', admin_password: adminPassword })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    cachedPlans = data.plans || [];
                    renderPlans(cachedPlans);
                }
            } catch (e) { }
        }

        function renderPlans(plans) {
            const tbody = document.querySelector('#plansTable tbody');
            if (!plans.length) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:20px;">Chưa có gói nào. Bấm "Thêm gói" để tạo.</td></tr>';
                return;
            }
            tbody.innerHTML = plans.map(p => `<tr>
                <td><strong>${p.name}</strong>${p.description ? '<br><span style="font-size:0.75rem;color:var(--text-muted);">' + p.description + '</span>' : ''}</td>
                <td>${fmt(p.quota)}</td>
                <td>${p.price || '--'}</td>
                <td>${p.is_active ? '<span style="color:var(--success)">✅ Active</span>' : '<span style="color:var(--text-muted)">🔒 Tắt</span>'}</td>
                <td>
                    <button class="btn btn-sm btn-outline" onclick='editPlan(${JSON.stringify(p)})' title="Sửa">
                        <i class="fa-solid fa-pen"></i>
                    </button>
                    <button class="btn btn-sm btn-outline" style="border-color:var(--error);color:var(--error)" onclick="deletePlan(${p.id}, '${p.name}')" title="Xóa">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </td>
            </tr>`).join('');
        }

        function showPlanModal() {
            document.getElementById('planModalTitle').textContent = 'Thêm gói dịch vụ';
            document.getElementById('planName').value = '';
            document.getElementById('planQuota').value = '5000';
            document.getElementById('planPrice').value = '';
            document.getElementById('planDesc').value = '';
            document.getElementById('planModalId').value = '';
            document.getElementById('planModalOverlay').classList.add('active');
        }

        function editPlan(plan) {
            document.getElementById('planModalTitle').textContent = 'Sửa gói dịch vụ';
            document.getElementById('planName').value = plan.name || '';
            document.getElementById('planQuota').value = plan.quota || 0;
            document.getElementById('planPrice').value = plan.price || '';
            document.getElementById('planDesc').value = plan.description || '';
            document.getElementById('planModalId').value = plan.id;
            document.getElementById('planModalOverlay').classList.add('active');
        }

        function closePlanModal() {
            document.getElementById('planModalOverlay').classList.remove('active');
        }

        async function savePlan() {
            const id = document.getElementById('planModalId').value;
            const name = document.getElementById('planName').value.trim();
            const quota = parseInt(document.getElementById('planQuota').value) || 0;
            const price = document.getElementById('planPrice').value.trim();
            const description = document.getElementById('planDesc').value.trim();

            if (!name || quota <= 0) {
                return alert('Tên gói và quota phải lớn hơn 0');
            }

            const payload = { admin_password: adminPassword };
            if (id) {
                payload.action = 'update';
                payload.plan_id = parseInt(id);
                payload.name = name;
                payload.quota = quota;
                payload.price = price;
                payload.description = description;
            } else {
                payload.action = 'create';
                payload.name = name;
                payload.quota = quota;
                payload.price = price;
                payload.description = description;
            }

            try {
                const res = await fetch(`${API}/plans.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.status === 'success') {
                    closePlanModal();
                    loadPlans();
                } else {
                    alert(data.error || 'Lỗi');
                }
            } catch (e) {
                alert('Lỗi kết nối');
            }
        }

        async function deletePlan(id, name) {
            if (!confirm(`Xóa gói "${name}"?`)) return;
            try {
                await fetch(`${API}/plans.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', admin_password: adminPassword, plan_id: id })
                });
                loadPlans();
            } catch (e) { }
        }

        // ─── Activate Plan for Customer ───
        async function showActivateModal(customerId, email) {
            document.getElementById('activateCustomerId').value = customerId;
            document.getElementById('activateCustomerInfo').textContent = `Khách hàng: ${email}`;
            document.getElementById('activatePlanInfo').style.display = 'none';

            // Load plans for dropdown
            try {
                const res = await fetch(`${API}/plans.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'list', admin_password: adminPassword })
                });
                const data = await res.json();
                const select = document.getElementById('activatePlanSelect');
                select.innerHTML = '<option value="">-- Chọn gói --</option>';
                if (data.plans) {
                    data.plans.filter(p => p.is_active).forEach(p => {
                        select.innerHTML += `<option value="${p.id}" data-quota="${p.quota}" data-price="${p.price || ''}">${p.name} — ${fmt(p.quota)} ký tự${p.price ? ' — ' + p.price : ''}</option>`;
                    });
                }
            } catch (e) { }

            document.getElementById('activateModalOverlay').classList.add('active');

            // Show plan info when selected
            document.getElementById('activatePlanSelect').onchange = function () {
                const opt = this.options[this.selectedIndex];
                const info = document.getElementById('activatePlanInfo');
                if (this.value) {
                    info.style.display = 'block';
                    info.innerHTML = `<strong>${opt.text}</strong><br><span style="color:var(--text-muted);font-size:0.8rem;">⚠️ Quota sẽ được <strong>reset</strong> về ${fmt(opt.dataset.quota)} ký tự, quota đã dùng sẽ về 0.</span>`;
                } else {
                    info.style.display = 'none';
                }
            };
        }

        function closeActivateModal() {
            document.getElementById('activateModalOverlay').classList.remove('active');
        }

        async function confirmActivatePlan() {
            const customerId = document.getElementById('activateCustomerId').value;
            const planId = document.getElementById('activatePlanSelect').value;

            if (!planId) return alert('Vui lòng chọn gói');

            const planName = document.getElementById('activatePlanSelect').options[document.getElementById('activatePlanSelect').selectedIndex].text;
            if (!confirm(`Kích hoạt "${planName}" cho khách hàng này?\n\nQuota sẽ được reset theo gói.`)) return;

            try {
                const res = await fetch(`${API}/plans.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'activate',
                        admin_password: adminPassword,
                        customer_id: parseInt(customerId),
                        plan_id: parseInt(planId)
                    })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alert('✅ ' + data.message);
                    closeActivateModal();
                    loadCustomers();
                    loadPartnerInfo();
                } else {
                    alert('❌ ' + (data.error || 'Lỗi'));
                }
            } catch (e) {
                alert('❌ Lỗi kết nối');
            }
        }

        // ─── Orders Management ───
        function toggleOrders() {
            const body = document.getElementById('ordersSection');
            const arrow = document.getElementById('ordersArrow');
            if (body.style.display === 'none') {
                body.style.display = 'block';
                arrow.style.transform = 'rotate(180deg)';
                loadOrders();
            } else {
                body.style.display = 'none';
                arrow.style.transform = '';
            }
        }

        async function loadOrders() {
            try {
                const res = await fetch(`${API}/plans.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'list_orders', admin_password: adminPassword })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    renderOrders(data.orders || []);
                }
            } catch (e) { }
        }

        function renderOrders(orders) {
            const tbody = document.getElementById('ordersBody');
            const pendingCount = orders.filter(o => o.status === 'pending').length;
            const badge = document.getElementById('pendingOrderCount');
            if (pendingCount > 0) {
                badge.textContent = pendingCount;
                badge.style.display = 'inline';
            } else {
                badge.style.display = 'none';
            }

            if (!orders.length) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:20px;">Chưa có đơn nào</td></tr>';
                return;
            }
            tbody.innerHTML = orders.map(o => {
                const statusMap = {
                    'pending': '<span style="color:#facc15;font-weight:600;">⏳ Chờ duyệt</span>',
                    'approved': '<span style="color:var(--success);font-weight:600;">✅ Đã duyệt</span>',
                    'rejected': '<span style="color:var(--error);font-weight:600;">❌ Từ chối</span>'
                };
                const actions = o.status === 'pending' ? `
                    <button class="btn btn-sm btn-outline" style="border-color:var(--success);color:var(--success)" onclick="approveOrder(${o.id})" title="Duyệt">
                        <i class="fa-solid fa-check"></i>
                    </button>
                    <button class="btn btn-sm btn-outline" style="border-color:var(--error);color:var(--error)" onclick="rejectOrder(${o.id})" title="Từ chối">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                ` : '--';
                return `<tr>
                    <td>${o.id}</td>
                    <td>${o.customer_email || '--'}</td>
                    <td><strong>${o.plan_name}</strong></td>
                    <td>${o.plan_price || '--'}</td>
                    <td>${fmt(o.quota)}</td>
                    <td>${statusMap[o.status] || o.status}</td>
                    <td style="font-size:0.75rem;">${o.created_at || '--'}</td>
                    <td>${actions}</td>
                </tr>`;
            }).join('');
        }

        async function approveOrder(orderId) {
            if (!confirm('Duyệt đơn này? Quota sẽ được kích hoạt cho khách.')) return;
            try {
                const res = await fetch(`${API}/plans.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'approve_order', admin_password: adminPassword, order_id: orderId })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alert('✅ ' + data.message);
                    loadOrders();
                    loadCustomers();
                    loadPartnerInfo();
                } else {
                    alert('❌ ' + (data.error || 'Lỗi'));
                }
            } catch (e) { alert('❌ Lỗi kết nối'); }
        }

        async function rejectOrder(orderId) {
            if (!confirm('Từ chối đơn này?')) return;
            try {
                const res = await fetch(`${API}/plans.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reject_order', admin_password: adminPassword, order_id: orderId })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    alert('✅ ' + data.message);
                    loadOrders();
                } else {
                    alert('❌ ' + (data.error || 'Lỗi'));
                }
            } catch (e) { alert('❌ Lỗi kết nối'); }
        }

        // Auto-load pending orders count on login
        async function checkPendingOrders() {
            try {
                const res = await fetch(`${API}/plans.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'list_orders', admin_password: adminPassword, status: 'pending' })
                });
                const data = await res.json();
                if (data.status === 'success') {
                    const count = (data.orders || []).length;
                    const badge = document.getElementById('pendingOrderCount');
                    if (count > 0) { badge.textContent = count; badge.style.display = 'inline'; }
                }
            } catch (e) { }
        }
    </script>
</body>

</html>