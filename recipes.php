<?php
/**
 * KCL Stores — Recipe Suggestions (Gemini AI)
 *
 * POST /api/recipes.php — Get cocktail/mocktail recipe suggestions
 *
 * Uses Google Gemini API to suggest drink recipes based on
 * available bar stock at the user's camp.
 */

require_once __DIR__ . '/middleware.php';

$auth = requireAuth();
$pdo = getDB();

$campId = $auth['camp_id'];

// Gemini API configuration
define('GEMINI_API_KEY', 'AIzaSyDso0Ae7zMkPuswSzrmPYfr9Q1KhQlls8c');
define('GEMINI_MODEL', 'gemini-2.0-flash');
define('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent');

// ── POST — Get Recipe Suggestions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();

    $type = $input['type'] ?? 'both'; // cocktail, mocktail, both
    $mood = $input['mood'] ?? '';      // refreshing, tropical, classic, etc.
    $ingredients = $input['ingredients'] ?? []; // specific items user wants to use
    $count = min(5, max(1, (int) ($input['count'] ?? 3)));

    // Get available bar stock items at this camp
    $barItems = [];
    if ($campId) {
        $stmt = $pdo->prepare("
            SELECT i.name, i.item_code, ig.code as group_code, ig.name as group_name,
                   COALESCE(sb.current_qty, 0) as stock_qty,
                   u.code as uom
            FROM items i
            JOIN item_groups ig ON i.item_group_id = ig.id
            LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
            LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.camp_id = ?
            WHERE i.is_active = 1
            AND ig.code IN ('BA', 'BJ', 'FV', 'FY', 'FD', 'GA')
            ORDER BY COALESCE(sb.current_qty, 0) DESC, i.name
            LIMIT 100
        ");
        $stmt->execute([$campId]);
        $barItems = $stmt->fetchAll();
    }

    // Build available ingredients text
    $availableList = [];
    foreach ($barItems as $item) {
        $stock = $item['stock_qty'] > 0 ? " ({$item['stock_qty']} {$item['uom']})" : " (check stock)";
        $availableList[] = $item['name'] . $stock;
    }

    // Build the prompt
    $drinkType = $type === 'cocktail' ? 'cocktail' : ($type === 'mocktail' ? 'non-alcoholic mocktail' : 'cocktail and mocktail');
    $moodText = $mood ? " The drinks should have a {$mood} feel." : '';
    $ingredientText = !empty($ingredients) ? " The guest specifically asked for drinks using: " . implode(', ', $ingredients) . "." : '';

    $prompt = "You are a professional safari lodge bartender at Karibu Camps in Tanzania. "
        . "Suggest {$count} {$drinkType} recipes that can be made with the ingredients available at our bar."
        . $moodText
        . $ingredientText
        . "\n\nAvailable ingredients at our bar:\n" . implode("\n", array_slice($availableList, 0, 60))
        . "\n\nFor each recipe provide:"
        . "\n1. Name (creative safari/African-themed names are great)"
        . "\n2. Type (cocktail or mocktail)"
        . "\n3. Ingredients list with exact measurements"
        . "\n4. Step-by-step preparation instructions"
        . "\n5. Glass type and garnish suggestions"
        . "\n6. A brief description for the menu"
        . "\n\nRespond in JSON format as an array of recipe objects with keys:"
        . " name, type, description, ingredients (array of {item, amount}), steps (array of strings), glass, garnish."
        . " Only return the JSON array, no other text.";

    // Call Gemini API
    $geminiPayload = json_encode([
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.8,
            'topP' => 0.95,
            'maxOutputTokens' => 4096,
            'responseMimeType' => 'application/json',
        ],
    ]);

    $ch = curl_init(GEMINI_ENDPOINT . '?key=' . GEMINI_API_KEY);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $geminiPayload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    unset($ch);

    if ($curlError) {
        jsonError("Failed to connect to AI service: {$curlError}", 502);
    }

    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? "AI service returned HTTP {$httpCode}";
        jsonError("AI service error: {$errorMsg}", 502);
    }

    $geminiData = json_decode($response, true);

    // Extract the text content from Gemini response
    $textContent = $geminiData['candidates'][0]['content']['parts'][0]['text'] ?? '';

    // Parse the JSON recipes from the response
    $recipes = json_decode($textContent, true);

    if (!$recipes || !is_array($recipes)) {
        // Try to extract JSON from the response if it's wrapped in markdown
        if (preg_match('/\[[\s\S]*\]/', $textContent, $matches)) {
            $recipes = json_decode($matches[0], true);
        }
    }

    if (!$recipes || !is_array($recipes)) {
        jsonResponse([
            'recipes' => [],
            'raw_response' => $textContent,
            'error' => 'Could not parse recipe response',
            'available_ingredients' => count($availableList),
        ]);
        exit;
    }

    jsonResponse([
        'recipes' => $recipes,
        'type' => $type,
        'mood' => $mood ?: null,
        'available_ingredients' => count($availableList),
        'camp_name' => $pdo->query("SELECT name FROM camps WHERE id = {$campId}")->fetchColumn(),
    ]);
    exit;
}

// ── GET — Get available ingredients for the recipe builder ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'ingredients';

    if ($action === 'ingredients') {
        $stmt = $pdo->prepare("
            SELECT i.id, i.name, i.item_code, ig.code as group_code, ig.name as group_name,
                   COALESCE(sb.current_qty, 0) as stock_qty,
                   u.code as uom
            FROM items i
            JOIN item_groups ig ON i.item_group_id = ig.id
            LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
            LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.camp_id = ?
            WHERE i.is_active = 1
            AND ig.code IN ('BA', 'BJ', 'FV', 'FY', 'FD', 'GA')
            ORDER BY ig.code, i.name
        ");
        $stmt->execute([$campId ?: 0]);
        $items = $stmt->fetchAll();

        // Group by category
        $grouped = [];
        foreach ($items as $item) {
            $groupCode = $item['group_code'];
            if (!isset($grouped[$groupCode])) {
                $grouped[$groupCode] = [
                    'code' => $groupCode,
                    'name' => $item['group_name'],
                    'items' => [],
                ];
            }
            $grouped[$groupCode]['items'][] = [
                'id' => (int) $item['id'],
                'name' => $item['name'],
                'item_code' => $item['item_code'],
                'stock_qty' => (float) $item['stock_qty'],
                'uom' => $item['uom'],
            ];
        }

        jsonResponse([
            'groups' => array_values($grouped),
            'total_items' => count($items),
        ]);
        exit;
    }

    jsonError('Invalid action', 400);
    exit;
}

requireMethod('POST');
