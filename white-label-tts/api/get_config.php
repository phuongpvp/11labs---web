<?php
// Cung cấp config an toàn cho frontend
// Ưu tiên đọc từ settings.json (admin UI), fallback về config.php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

// Read settings.json if exists
$settings = [];
$settingsFile = __DIR__ . '/../data/settings.json';
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true) ?? [];
}

jsonResponse([
    'status' => 'success',
    'api_base' => 'https://11labs.id.vn/api/external/v1',
    'api_key' => $settings['api_key'] ?? PARTNER_API_KEY,
    'site_name' => $settings['site_name'] ?? SITE_NAME,
    'site_logo' => $settings['site_logo'] ?? (defined('SITE_LOGO') ? SITE_LOGO : ''),
    'primary_color' => $settings['primary_color'] ?? PRIMARY_COLOR,
    'popup_enabled' => (bool) ($settings['popup_enabled'] ?? false),
    'popup_image' => $settings['popup_image'] ?? '',
    'popup_link' => $settings['popup_link'] ?? '',
    'popup_frequency' => $settings['popup_frequency'] ?? 'once_per_day',
    'social_zalo' => $settings['social_zalo'] ?? '',
    'social_telegram' => $settings['social_telegram'] ?? '',
    'social_facebook' => $settings['social_facebook'] ?? '',
    'social_youtube' => $settings['social_youtube'] ?? '',
    'affiliate_enabled' => (bool)($settings['affiliate_enabled'] ?? false)
]);
