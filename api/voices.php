<?php
require_once __DIR__ . '/config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

// Debugging
$debug = isset($_GET['debug']);
if ($debug) {
    header('Content-Type: text/plain');
    echo "--- VOICES DEBUG ---\n";
}

try {
    $db = getDB();

    $searchQuery = $_GET['q'] ?? '';
    $genderFilter = $_GET['gender'] ?? '';
    $accentFilter = $_GET['accent'] ?? '';
    $categoryFilter = $_GET['category'] ?? '';
    $languageFilter = $_GET['language'] ?? '';
    $ageFilter = $_GET['age'] ?? '';

    $allVoices = [];
    $success = false;
    $errorMsg = "No active keys available";

    // ... [Database API Key selection here] ...
    // Get all active keys to try
    $stmt = $db->query("SELECT id, key_encrypted FROM api_keys WHERE status = 'active' ORDER BY last_checked ASC");
    $keys = $stmt->fetchAll();

    if ($debug)
        echo "Active keys found in DB: " . count($keys) . "\n";

    $allVoices = []; // Initialize $allVoices here
    $success = false;
    $errorMsg = "No active keys available";

    foreach ($keys as $keyData) {
        $apiKey = getEffectiveElevenLabsKey($keyData);
        $keyId = $keyData['id'];

        if (!$apiKey) {
            if ($debug)
                echo "  Key ID: $keyId - Failed to get valid token (Quota or Login error)\n";
            continue;
        }

        if ($debug)
            echo "  Trying Key ID: $keyId (Resolved: " . (strlen($apiKey) > 50 ? "Bearer Token" : "API Key") . ")\n";

        $headers = [];
        if (strpos($apiKey, 'ey') === 0 || strlen($apiKey) > 100) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
            $headers[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36';
            $headers[] = 'Origin: https://elevenlabs.io';
            $headers[] = 'Referer: https://elevenlabs.io/';
            $headers[] = 'Accept: */*';
            $headers[] = 'Accept-Language: vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7';
            $headers[] = 'sec-ch-ua: "Not(A:Brand";v="8", "Chromium";v="144", "Google Chrome";v="144"';
            $headers[] = 'sec-ch-ua-mobile: ?0';
            $headers[] = 'sec-ch-ua-platform: "Windows"';
            $headers[] = 'sec-fetch-dest: empty';
            $headers[] = 'sec-fetch-mode: cors';
            $headers[] = 'sec-fetch-site: same-site';
        } else {
            $headers[] = 'xi-api-key: ' . $apiKey;
            $headers[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        }
        $headers[] = 'Content-Type: application/json';

        $ch = curl_init('https://api.elevenlabs.io/v1/voices');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $sentHeaders = curl_getinfo($ch, CURLINFO_HEADER_OUT);
        curl_close($ch);

        if ($debug) {
            echo "  ElevenLabs Status: $httpCode\n";
            if ($httpCode !== 200)
                echo "  Error Body: $response\n";
        }

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $allVoices = $data['voices'] ?? [];
            if (!empty($allVoices)) {
                if ($debug)
                    echo "  ✅ Successfully fetched " . count($allVoices) . " voices!\n";
                $success = true;
                break;
            }
        }

        $errorMsg = "ElevenLabs API error (Code: $httpCode): " . $response;
        logToFile('error_' . date('Y-m-d') . '.log', "Voices: Key ID $keyId failed. Code $httpCode. Response: " . substr($response, 0, 100));
    }

    if (!$success) {
        jsonResponse(['status' => 'error', 'message' => $errorMsg, 'source' => 'no_working_key'], 500);
    }

    // Local filtering for Account Voices
    if (!empty($searchQuery)) {
        $filtered = [];
        foreach ($allVoices as $v) {
            if (stripos($v['name'], $searchQuery) !== false || stripos($v['voice_id'], $searchQuery) !== false) {
                $filtered[] = $v;
            }
        }
        $allVoices = $filtered;
    }

    // Apply local filters for other attributes on the account voices
    if (!empty($genderFilter) || !empty($accentFilter) || !empty($categoryFilter) || !empty($ageFilter) || !empty($languageFilter)) {
        $filtered = [];
        foreach ($allVoices as $v) {
            $match = true;
            if (!empty($genderFilter) && strcasecmp($v['labels']['gender'] ?? '', $genderFilter) !== 0)
                $match = false;
            // Depending heavily on the label attributes structure
            if (!empty($accentFilter) && stripos($v['labels']['accent'] ?? '', $accentFilter) === false)
                $match = false;
            if (!empty($categoryFilter) && strcasecmp($v['category'] ?? '', $categoryFilter) !== 0)
                $match = false;
            if (!empty($ageFilter) && strcasecmp($v['labels']['age'] ?? '', $ageFilter) !== 0)
                $match = false;

            // Language is not standard on basic voices without language parameter, usually 'description' might hold it
            // If the user selects a language filter, local voices (mostly English presets) should be hidden if they don't match.
            if (!empty($languageFilter)) {
                $vLang = $v['labels']['language'] ?? 'en'; // Default account preset voices are English
                if (strcasecmp($vLang, $languageFilter) !== 0 && stripos($vLang, $languageFilter) === false) {
                    $match = false;
                }
            }

            if ($match)
                $filtered[] = $v;
        }
        $allVoices = $filtered;
    }


    // 2. Direct Voice ID Lookup (If query looks like an ID)
    if (!empty($searchQuery) && strlen($searchQuery) > 15 && !preg_match('/\s/', $searchQuery)) {
        // Use shared-voices search instead of direct ID lookup, as direct /v1/voices/{id} only works for added voices
        $chId = curl_init('https://api.elevenlabs.io/v1/shared-voices?page_size=10&search=' . urlencode($searchQuery));
        curl_setopt($chId, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chId, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($chId, CURLOPT_HTTPHEADER, $headers);

        $idResponse = curl_exec($chId);
        $idHttpCode = curl_getinfo($chId, CURLINFO_HTTP_CODE);
        curl_close($chId);

        if ($idHttpCode === 200) {
            $data = json_decode($idResponse, true);
            if (isset($data['voices'])) {
                foreach ($data['voices'] as $sv) {
                    if ($sv['voice_id'] === $searchQuery) {
                        $allVoices[] = [
                            'voice_id' => $sv['voice_id'],
                            'name' => $sv['name'],
                            'preview_url' => $sv['preview_url'] ?? '',
                            'labels' => [
                                'gender' => $sv['gender'] ?? '', // Shared voices structure is slightly different
                                'accent' => $sv['accent'] ?? '',
                            ],
                            'category' => 'direct_id',
                            'description' => $sv['description'] ?? 'Voice ID Direct Lookup'
                        ];
                        break; // Found it
                    }
                }
            }
        }
    }

    // 3. Fetch Shared Voice Library (Searching ElevenLabs Database)
    $sharedUrl = 'https://api.elevenlabs.io/v1/shared-voices?page_size=50';

    // Instead of relying purely on the broken &language= API parameter, append the language name to the search string to get broader hits
    $combinedSearch = $searchQuery;
    if (!empty($languageFilter)) {
        // Convert ISO back to english name for text search if needed, but since we reverted customer.html to ISO, let's just use the strict ISO filter and see. Actually, competitor app relies on text search for language.
        // Let's create a map to text 
        $langMap = [
            'en' => 'English',
            'vi' => 'Vietnamese',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'zh' => 'Chinese',
            'ar' => 'Arabic',
            'hi' => 'Hindi',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'tr' => 'Turkish',
            'th' => 'Thai',
            'id' => 'Indonesian'
        ];
        $mappedLang = $langMap[$languageFilter] ?? $languageFilter;
        $combinedSearch = trim($combinedSearch . ' ' . $mappedLang);

        $sharedUrl .= '&language=' . urlencode($languageFilter);
    }

    if (!empty($combinedSearch))
        $sharedUrl .= '&search=' . urlencode($combinedSearch);

    if (!empty($genderFilter))
        $sharedUrl .= '&gender=' . urlencode($genderFilter);
    if (!empty($accentFilter))
        $sharedUrl .= '&accent=' . urlencode($accentFilter);
    if (!empty($ageFilter))
        $sharedUrl .= '&age=' . urlencode($ageFilter);
    if (!empty($categoryFilter))
        $sharedUrl .= '&category=' . urlencode($categoryFilter);

    // Check if there are active filters other than query to change sort parameter strategy
    $hasFilters = (!empty($searchQuery) || !empty($genderFilter) || !empty($accentFilter) || !empty($languageFilter) || !empty($categoryFilter) || !empty($ageFilter));

    // Sort logic: if searching, sort by relevance/trending, else trending
    $sharedUrl .= '&sort=' . ($hasFilters ? 'trending' : 'most_users');

    $chShared = curl_init($sharedUrl);
    curl_setopt($chShared, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chShared, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($chShared, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($chShared, CURLOPT_TIMEOUT, 10);
    curl_setopt($chShared, CURLOPT_HTTPHEADER, $headers);

    $sharedResponse = curl_exec($chShared);
    $sharedHttpCode = curl_getinfo($chShared, CURLINFO_HTTP_CODE);
    curl_close($chShared);

    if ($sharedHttpCode === 200) {
        $sharedData = json_decode($sharedResponse, true);
        if (isset($sharedData['voices'])) {
            foreach ($sharedData['voices'] as $sv) {
                // Deduplicate if already in account voices
                $exists = false;
                foreach ($allVoices as $v) {
                    if ($v['voice_id'] === $sv['voice_id']) {
                        $exists = true;
                        break;
                    }
                }
                if ($exists)
                    continue;

                $allVoices[] = [
                    'voice_id' => $sv['voice_id'],
                    'name' => $sv['name'] . (isset($sv['owner_id']) ? ' (Library)' : ''),
                    'preview_url' => $sv['preview_url'],
                    'labels' => [
                        'gender' => $sv['gender'] ?? '',
                        'accent' => $sv['accent'] ?? '',
                        'age' => $sv['age'] ?? '',
                        'language' => $sv['language'] ?? '',
                        'use_case' => $sv['use_case'] ?? ''
                    ],
                    'category' => $sv['category'] ?? 'library',
                    'description' => $sv['description'] ?? '',
                    'usage_count' => $sv['usage_character_count_1y'] ?? 0,
                    'rate' => $sv['rate'] ?? 0 // For custom rate badge if needed
                ];
            }
        }
    }

    if (!empty($allVoices)) {
        jsonResponse(['status' => 'success', 'voices' => $allVoices, 'count' => count($allVoices)]);
    } else {
        // If searching, return empty instead of defaults so frontend can show "Use manual ID" button
        if (!empty($searchQuery)) {
            jsonResponse(['status' => 'success', 'voices' => [], 'count' => 0, 'source' => 'no_results']);
        }
        jsonResponse(['status' => 'success', 'voices' => defaultVoices(), 'source' => 'fallback'], 200);
    }

} catch (Exception $e) {
    if (!empty($_GET['q'])) {
        jsonResponse(['status' => 'success', 'voices' => [], 'source' => 'fallback_exception_search', 'debug' => $e->getMessage()], 200);
    }
    jsonResponse(['status' => 'success', 'voices' => defaultVoices(), 'source' => 'fallback_exception'], 200);
}

function defaultVoices()
{
    return [
        ['voice_id' => '21m00Tcm4TlvDq8ikWAM', 'name' => 'Rachel', 'preview_url' => 'https://storage.googleapis.com/eleven-public-prod/previews/21m00Tcm4TlvDq8ikWAM.mp3', 'labels' => ['gender' => 'female', 'description' => 'calm']],
        ['voice_id' => 'pNInz6obpgDQGcFmaJgB', 'name' => 'Adam', 'preview_url' => 'https://storage.googleapis.com/eleven-public-prod/previews/pNInz6obpgDQGcFmaJgB.mp3', 'labels' => ['gender' => 'male', 'description' => 'deep']],
        ['voice_id' => 'EXAVITQu4vr4xnSDxMaL', 'name' => 'Bella', 'preview_url' => 'https://storage.googleapis.com/eleven-public-prod/previews/EXAVITQu4vr4xnSDxMaL.mp3', 'labels' => ['gender' => 'female', 'description' => 'soft']],
    ];
}
?>