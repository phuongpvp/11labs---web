<?php
require_once __DIR__ . '/../config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$adminPassword = $input['admin_password'] ?? '';
$planId = $input['plan_id'] ?? '';
$name = $input['name'] ?? null;
$price = isset($input['price']) ? (int) $input['price'] : null;
$priceUsd = array_key_exists('price_usd', $input) ? $input['price_usd'] : 'NOT_SET';
$quota = isset($input['quota']) ? (int) $input['quota'] : null;
$days = isset($input['days']) ? (int) $input['days'] : null;
$srtEnabled = isset($input['srt_enabled']) ? (bool) $input['srt_enabled'] : null;

if (!$adminPassword || !$planId) {
    jsonResponse(['error' => 'Admin password and Plan ID required'], 400);
}

// Verify admin
if (!verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Invalid admin password'], 403);
}

try {
    $db = getDB();

    // 1. Get current package data to retrieve existing features
    $stmt = $db->prepare("SELECT features FROM packages WHERE id = ?");
    $stmt->execute([$planId]);
    $currentPkg = $stmt->fetch();

    if (!$currentPkg) {
        jsonResponse(['error' => 'Package not found'], 404);
    }

    $features = json_decode($currentPkg['features'] ?? '[]', true);
    if (!is_array($features))
        $features = [];

    // 2. Modify features if srtEnabled is provided
    if ($srtEnabled !== null) {
        if ($srtEnabled) {
            if (!in_array('srt_download', $features)) {
                $features[] = 'srt_download';
            }
        } else {
            $features = array_values(array_filter($features, function ($f) {
                return $f !== 'srt_download';
            }));
        }
    }

    $newFeaturesJson = json_encode($features);

    // 3. Update database
    // Only update fields that are provided
    $query = "UPDATE packages SET features = :features";
    $params = [':features' => $newFeaturesJson, ':id' => $planId];

    if ($name !== null) {
        $query .= ", name = :name";
        $params[':name'] = $name;
    }
    if ($price !== null) {
        $query .= ", price = :price";
        $params[':price'] = $price;
    }
    if ($priceUsd !== 'NOT_SET') {
        $query .= ", price_usd = :price_usd";
        $params[':price_usd'] = $priceUsd !== null && $priceUsd !== '' ? (float) $priceUsd : null;
    }
    if ($quota !== null) {
        $query .= ", quota_chars = :quota";
        $params[':quota'] = $quota;
    }
    if ($days !== null) {
        $query .= ", duration_days = :days";
        $params[':days'] = $days;
    }

    $query .= " WHERE id = :id";

    $stmt = $db->prepare($query);
    $stmt->execute($params);

    jsonResponse([
        'status' => 'success',
        'message' => 'Package updated successfully',
        'data' => [
            'id' => $planId,
            'quota' => $quota,
            'days' => $days,
            'features' => $features
        ]
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
?>