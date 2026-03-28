<?php
require_once __DIR__ . '/config.php';

// Public endpoint
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

try {
    $db = getDB();

    $stmt = $db->query("
        SELECT u.email, CHAR_LENGTH(cj.full_text) as characters_used, cj.created_at
        FROM conversion_jobs cj
        JOIN users u ON cj.user_id = u.id
        WHERE cj.status = 'completed'
        ORDER BY cj.created_at DESC
        LIMIT 10
    ");
    $jobs = $stmt->fetchAll();

    $formattedJobs = [];
    foreach ($jobs as $job) {
        $email = $job['email'];
        $parts = explode('@', $email);
        $name = $parts[0];
        $domain = $parts[1] ?? '';

        // Mask: keep 2 first chars, replace rest with ****
        if (strlen($name) > 2) {
            $maskedName = substr($name, 0, 2) . '****';
        } else {
            $maskedName = $name . '****';
        }

        $formattedJobs[] = [
            'user' => $maskedName . '@' . $domain,
            'chars' => number_format($job['characters_used']),
            'time' => $job['created_at']
        ];
    }

    jsonResponse([
        'status' => 'success',
        'jobs' => $formattedJobs
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error'], 500);
}
