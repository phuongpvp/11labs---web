<?php
require_once __DIR__ . '/config.php';

echo "<h1>🔄 Firebase Account Sync</h1>";

try {
    $db = getDB();
    $keys = $db->query("SELECT id, key_encrypted FROM api_keys WHERE status = 'active'")->fetchAll();

    echo "<p>Found " . count($keys) . " active keys. Starting migration to new Firebase project...</p>";
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; font-family: monospace;'>";
    echo "<tr><th>ID</th><th>Email (Masked)</th><th>Action</th><th>Result</th></tr>";

    foreach ($keys as $k) {
        echo "<tr>";
        echo "<td>{$k['id']}</td>";

        $decrypted = decryptKey($k['key_encrypted']);
        if (strpos($decrypted, ':') !== false && strpos($decrypted, '@') !== false) {
            list($email, $pass) = explode(':', $decrypted, 2);
            $masked = substr($email, 0, 3) . "..." . strstr($email, '@');
            echo "<td>$masked</td>";

            // Attempt to Login in Firebase (Worker Proxied)
            $fbTokens = loginWithFirebase($email, $pass, null, $err, true, true);

            if ($fbTokens) {
                echo "<td style='color: green;'>SYNC</td>";
                echo "<td style='color: green;'>✅ OK (Sync via Worker IP)</td>";

                // Update DB with fresh token potentially
                $stmtAuth = $db->prepare("UPDATE api_keys SET fb_token = ?, fb_token_expires = ?, fb_refresh_token = ? WHERE id = ?");
                $stmtAuth->execute([
                    $fbTokens['idToken'] ?? null,
                    $fbTokens['expires'] ?? date('Y-m-d H:i:s', time() + 3300),
                    $fbTokens['refreshToken'] ?? null,
                    $k['id']
                ]);
            } else {
                echo "<td style='color: orange;'>SYNC</td>";
                echo "<td style='color: orange;'>⚠️ Failed to Login/Sync via Worker IP</td>";
            }
        } else {
            echo "<td>-</td>";
            echo "<td>-</td>";
            echo "<td>ℹ️ Plain API Key (No sync needed)</td>";
        }
        echo "</tr>";
    }
    echo "</table>";

} catch (Exception $e) {
    echo "<p style='color: red;'>Global Error: " . $e->getMessage() . "</p>";
}

function registerInFirebase($email, $password)
{
    // API to create user
    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:signUp?key=' . FIREBASE_API_KEY;
    $payload = json_encode([
        'email' => $email,
        'password' => $password,
        'returnSecureToken' => true
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpCode === 200) {
        return ['success' => true];
    } else {
        $error = $data['error']['message'] ?? "HTTP $httpCode";
        if ($error === 'EMAIL_EXISTS') {
            return ['success' => true]; // Already synced
        }
        return ['success' => false, 'error' => $error];
    }
}
?>