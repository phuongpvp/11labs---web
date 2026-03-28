<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

try {
    $packages = getSubscriptionPackages();

    // Convert associative array to indexed array for easier JS handling if needed, 
    // but associative is fine if keys are IDs. 
    // Let's return as a simple list for easier rendering.
    $list = [];
    foreach ($packages as $id => $pkg) {
        if ($id === 'trial' || $pkg['price'] <= 0) continue;
        $list[] = array_merge(['id' => $id], $pkg);
    }

    echo json_encode(['status' => 'success', 'packages' => $list]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>