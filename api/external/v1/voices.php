<?php
require_once __DIR__ . '/../../config.php';

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, x-api-key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

// 1. Verify API Key
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
$user = verifyPartnerApiKey($apiKey);

if (!$user) {
    jsonResponse(['error' => 'Invalid or expired API Key'], 401);
}

// 2. Reuse Internal Voices Logic
try {
    $db = getDB();

    // Simple filter support
    $searchQuery = $_GET['q'] ?? '';
    $languageFilter = $_GET['language'] ?? '';

    // Basically copy the core logic from ../../voices.php but more streamlined
    // To keep it simple, we include the main voices file or just duplicate the core fetch logic
    // Let's just do a clean fetch from ElevenLabs using the first active key we find

    $stmt = $db->query("SELECT id, key_encrypted FROM api_keys WHERE status = 'active' ORDER BY last_checked ASC LIMIT 1");
    $keyData = $stmt->fetch();

    if (!$keyData) {
        jsonResponse(['error' => 'System busy, please try again later'], 503);
    }

    $elKey = getEffectiveElevenLabsKey($keyData);
    if (!$elKey) {
        jsonResponse(['error' => 'Voice services temporarily unavailable'], 503);
    }

    $headers = [];
    if (strpos($elKey, 'ey') === 0 || strlen($elKey) > 100) {
        $headers[] = 'Authorization: Bearer ' . $elKey;
    } else {
        $headers[] = 'xi-api-key: ' . $elKey;
    }
    $headers[] = 'Content-Type: application/json';

    // Use shared-voices API for full voice library (not just account voices)
    $sharedUrl = 'https://api.elevenlabs.io/v1/shared-voices?page_size=100';
    if ($searchQuery) {
        $sharedUrl .= '&search=' . urlencode($searchQuery);
    }
    if ($languageFilter) {
        $sharedUrl .= '&language=' . urlencode($languageFilter);
    }

    $ch = curl_init($sharedUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        // Fallback to standard voices endpoint
        $ch2 = curl_init('https://api.elevenlabs.io/v1/voices');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch2);
        $httpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        if ($httpCode !== 200) {
            jsonResponse(['error' => 'External service error'], 502);
        }

        $data = json_decode($response, true);
        $voices = $data['voices'] ?? [];
    } else {
        $data = json_decode($response, true);
        $voices = $data['voices'] ?? [];
    }

    // Return streamlined voice list
    $output = [];
    foreach ($voices as $v) {
        $output[] = [
            'voice_id' => $v['voice_id'] ?? ($v['public_owner_id'] ?? ''),
            'name' => $v['name'] ?? '',
            'preview_url' => $v['preview_url'] ?? '',
            'labels' => $v['labels'] ?? [],
            'category' => $v['category'] ?? ''
        ];
    }

    jsonResponse([
        'status' => 'success',
        'voices' => $output,
        'count' => count($output)
    ]);

} catch (Exception $e) {
    error_log("External Voices API Error: " . $e->getMessage());
    jsonResponse(['error' => 'Đã xảy ra lỗi hệ thống, vui lòng thử lại sau.'], 500);
}
