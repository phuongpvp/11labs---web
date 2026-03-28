<?php
/**
 * Cleanup Script: Deactivate all API keys with 0 credits
 * 
 * This script will find and deactivate all API keys that have 0 or negative credits.
 * Run this ONCE by accessing: https://11labs.id.vn/api/cleanup_keys.php
 */

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = getDB();

    echo "=== CLEANING UP EXHAUSTED API KEYS ===\n\n";

    // Find keys with 0 or negative credits that are still active
    $stmt = $db->query("SELECT id, credits_remaining, status FROM api_keys WHERE credits_remaining <= 0 AND status = 'active'");
    $exhaustedKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($exhaustedKeys)) {
        echo "✅ No exhausted keys found. All keys are healthy!\n";
        exit;
    }

    echo "Found " . count($exhaustedKeys) . " exhausted key(s):\n\n";

    foreach ($exhaustedKeys as $key) {
        echo "  - Key ID {$key['id']}: {$key['credits_remaining']} credits\n";
    }

    echo "\nDeactivating...\n";

    // Deactivate all exhausted keys
    $stmt = $db->exec("UPDATE api_keys SET status = 'inactive' WHERE credits_remaining <= 0 AND status = 'active'");

    echo "\n✅ Successfully deactivated " . count($exhaustedKeys) . " key(s)!\n";
    echo "\nThese keys will no longer be selected for new jobs.\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>