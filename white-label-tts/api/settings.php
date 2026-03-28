<?php
// =====================================================
// Cài đặt site — Lưu/Đọc settings từ file JSON
// =====================================================
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

$settingsFile = __DIR__ . '/../data/settings.json';

// Đọc settings hiện tại
function getSettings()
{
    global $settingsFile;
    if (file_exists($settingsFile)) {
        return json_decode(file_get_contents($settingsFile), true) ?? [];
    }
    return [];
}

// Ghi settings
function saveSettings($data)
{
    global $settingsFile;
    $dir = dirname($settingsFile);
    if (!is_dir($dir))
        mkdir($dir, 0755, true);
    file_put_contents($settingsFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$input = getInput();
$action = $input['action'] ?? $_GET['action'] ?? '';

// GET: Đọc settings (public, cho frontend)
if ($action === 'get_public') {
    $settings = getSettings();
    jsonResponse([
        'status' => 'success',
        'site_name' => $settings['site_name'] ?? SITE_NAME,
        'site_logo' => $settings['site_logo'] ?? (defined('SITE_LOGO') ? SITE_LOGO : ''),
        'primary_color' => $settings['primary_color'] ?? PRIMARY_COLOR,
        'api_key_set' => !empty($settings['api_key'] ?? PARTNER_API_KEY),
        'bank_name' => $settings['bank_name'] ?? '',
        'bank_account' => $settings['bank_account'] ?? '',
        'bank_owner' => $settings['bank_owner'] ?? '',
        'bank_note' => $settings['bank_note'] ?? '',
        'popup_enabled' => (bool) ($settings['popup_enabled'] ?? false),
        'popup_image' => $settings['popup_image'] ?? '',
        'popup_link' => $settings['popup_link'] ?? '',
        'popup_frequency' => $settings['popup_frequency'] ?? 'once_per_day',
        'social_zalo' => $settings['social_zalo'] ?? '',
        'social_telegram' => $settings['social_telegram'] ?? '',
        'social_facebook' => $settings['social_facebook'] ?? '',
        'social_youtube' => $settings['social_youtube'] ?? '',
        'affiliate_enabled' => (bool)($settings['affiliate_enabled'] ?? false),
        'affiliate_commission_rate' => floatval($settings['affiliate_commission_rate'] ?? 10)
    ]);
}

// Admin actions
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Verify admin
$pwd = $input['admin_password'] ?? '';
if ($pwd !== ADMIN_PASSWORD) {
    jsonResponse(['error' => 'Invalid admin password'], 401);
}

if ($action === 'get') {
    $settings = getSettings();
    jsonResponse([
        'status' => 'success',
        'settings' => [
            'site_name' => $settings['site_name'] ?? SITE_NAME,
            'site_logo' => $settings['site_logo'] ?? (defined('SITE_LOGO') ? SITE_LOGO : ''),
            'primary_color' => $settings['primary_color'] ?? PRIMARY_COLOR,
            'api_key' => $settings['api_key'] ?? PARTNER_API_KEY,
            'api_key_preview' => substr($settings['api_key'] ?? PARTNER_API_KEY, 0, 10) . '...',
            'bank_name' => $settings['bank_name'] ?? '',
            'bank_account' => $settings['bank_account'] ?? '',
            'bank_owner' => $settings['bank_owner'] ?? '',
            'bank_note' => $settings['bank_note'] ?? '',
            'popup_enabled' => (bool) ($settings['popup_enabled'] ?? false),
            'popup_image' => $settings['popup_image'] ?? '',
            'popup_link' => $settings['popup_link'] ?? '',
            'popup_frequency' => $settings['popup_frequency'] ?? 'once_per_day',
            'social_zalo' => $settings['social_zalo'] ?? '',
            'social_telegram' => $settings['social_telegram'] ?? '',
            'social_facebook' => $settings['social_facebook'] ?? '',
            'social_youtube' => $settings['social_youtube'] ?? '',
            'affiliate_enabled' => (bool)($settings['affiliate_enabled'] ?? false),
            'affiliate_commission_rate' => floatval($settings['affiliate_commission_rate'] ?? 10),
            'telegram_bot_token' => $settings['telegram_bot_token'] ?? '',
            'telegram_chat_id' => $settings['telegram_chat_id'] ?? ''
        ]
    ]);

} elseif ($action === 'save') {
    $settings = getSettings();

    if (isset($input['site_name']))
        $settings['site_name'] = trim($input['site_name']);
    if (isset($input['site_logo']))
        $settings['site_logo'] = trim($input['site_logo']);
    if (isset($input['primary_color']))
        $settings['primary_color'] = trim($input['primary_color']);
    if (isset($input['api_key']) && !empty(trim($input['api_key']))) {
        $settings['api_key'] = trim($input['api_key']);
    }
    if (isset($input['bank_name']))
        $settings['bank_name'] = trim($input['bank_name']);
    if (isset($input['bank_account']))
        $settings['bank_account'] = trim($input['bank_account']);
    if (isset($input['bank_owner']))
        $settings['bank_owner'] = trim($input['bank_owner']);
    if (isset($input['bank_note']))
        $settings['bank_note'] = trim($input['bank_note']);
    if (isset($input['popup_enabled']))
        $settings['popup_enabled'] = (bool) $input['popup_enabled'];
    if (isset($input['popup_image']))
        $settings['popup_image'] = trim($input['popup_image']);
    if (isset($input['popup_link']))
        $settings['popup_link'] = trim($input['popup_link']);
    if (isset($input['popup_frequency']))
        $settings['popup_frequency'] = trim($input['popup_frequency']);
    if (isset($input['social_zalo']))
        $settings['social_zalo'] = trim($input['social_zalo']);
    if (isset($input['social_telegram']))
        $settings['social_telegram'] = trim($input['social_telegram']);
    if (isset($input['social_facebook']))
        $settings['social_facebook'] = trim($input['social_facebook']);
    if (isset($input['social_youtube']))
        $settings['social_youtube'] = trim($input['social_youtube']);
    if (isset($input['affiliate_enabled']))
        $settings['affiliate_enabled'] = (bool)$input['affiliate_enabled'];
    if (isset($input['affiliate_commission_rate']))
        $settings['affiliate_commission_rate'] = floatval($input['affiliate_commission_rate']);
    if (isset($input['telegram_bot_token']))
        $settings['telegram_bot_token'] = trim($input['telegram_bot_token']);
    if (isset($input['telegram_chat_id']))
        $settings['telegram_chat_id'] = trim($input['telegram_chat_id']);

    saveSettings($settings);

    jsonResponse([
        'status' => 'success',
        'message' => 'Đã lưu cài đặt!'
    ]);

} elseif ($action === 'change_admin_password') {
    $newPassword = $input['new_password'] ?? '';
    if (!$newPassword || strlen($newPassword) < 6) {
        jsonResponse(['error' => 'Mật khẩu mới phải có ít nhất 6 ký tự'], 400);
    }

    $settings = getSettings();
    $settings['admin_password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT);
    saveSettings($settings);

    jsonResponse([
        'status' => 'success',
        'message' => 'Đã đổi mật khẩu Admin thành công! Hãy đăng nhập lại.'
    ]);

} else {
    jsonResponse(['error' => 'Invalid action'], 400);
}
