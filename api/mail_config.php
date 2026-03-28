<?php
// ========================================
// SMTP Configuration for Email Sending
// ========================================

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'info.11labs@gmail.com');
define('SMTP_PASS', 'hhzpyemsduunadnc');
define('SMTP_FROM', 'info.11labs@gmail.com');
define('SMTP_FROM_NAME', '11LABS Support');
define('SMTP_DEBUG', 0);              // Đặt thành 2 để debug nếu gửi mail lỗi

/**
 * Lưu ý cấu hình Zoho:
 * - Host: smtp.zoho.com
 * - Port: 465 (SSL)
 * - Cần tạo "App Password" trong phần Security của tài khoản Zoho.
 */
?>