<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    $db = getDB();

    $settings = [
        'promo_popup_enabled' => getSystemSetting('promo_popup_enabled', '0'),
        'promo_popup_image_url' => getSystemSetting('promo_popup_image_url', ''),
        'promo_popup_link' => getSystemSetting('promo_popup_link', ''),
        'promo_popup_frequency' => getSystemSetting('promo_popup_frequency', 'once_per_day')
    ];

    echo json_encode(['status' => 'success', 'promo' => $settings]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
?>