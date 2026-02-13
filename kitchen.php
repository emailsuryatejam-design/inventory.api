<?php
/**
 * KCL Stores — Kitchen Recipes & Preferences API
 *
 * GET  ?action=recipes               — list kitchen recipes (optionally filtered by category)
 * GET  ?action=recipe&id=X           — single recipe with ingredients + stock
 * GET  ?action=search_recipes&q=X    — search recipes by name
 * GET  ?action=suggest_recipe&items= — AI suggests a recipe for the given items
 * GET  ?action=suggest_alternatives  — AI alternatives for out-of-stock ingredient
 * GET  ?action=search_ingredients&q= — search stock items (for adding custom ingredients)
 * GET  ?action=preferences           — get user's learned preferences
 * GET  ?action=suggested_items       — AI-ranked items based on preferences
 * POST ?action=save_recipe           — create/update a kitchen recipe
 * POST ?action=log_pattern           — log order pattern for preference learning
 * POST ?action=learn                 — trigger preference computation from logs
 */

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/helpers.php';

$auth = requireAuth();
$pdo = getDB();

$campId = $auth['camp_id'];
$userId = $auth['user_id'];

// Gemini config
define('GEMINI_KEY', 'AIzaSyDso0Ae7zMkPuswSzrmPYfr9Q1KhQlls8c');
define('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . GEMINI_KEY);

// Kitchen-relevant item group codes
define('KITCHEN_GROUPS', "'FD','FM','FY','FV','FF','BA','BJ','GA','OT'");

// ── GET endpoints ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'recipes';
    $queryCampId = $campId ?: ((int) ($_GET['camp_id'] ?? 0));

    // ── List Recipes ──
    if ($action === 'recipes') {
        $category = $_GET['category'] ?? '';
        $where = ['r.is_active = 1'];
        $params = [];

        // Show global recipes + camp-specific
        $where[] = '(r.camp_id IS NULL OR r.camp_id = ?)';
        $params[] = $queryCampId ?: 0;

        if ($category) {
            $where[] = 'r.category = ?';
            $params[] = $category;
        }

        $whereClause = implode(' AND ', $where);
        $stmt = $pdo->prepare("
            SELECT r.*, u.name as created_by_name,
                   (SELECT COUNT(*) FROM kitchen_recipe_ingredients kri WHERE kri.recipe_id = r.id) as ingredient_count
            FROM kitchen_recipes r
            LEFT JOIN users u ON r.created_by = u.id
            WHERE {$whereClause}
            ORDER BY r.category, r.name
        ");
        $stmt->execute($params);
        $recipes = $stmt->fetchAll();

        jsonResponse([
            'recipes' => array_map(function($r) {
                return [
                    'id' => (int) $r['id'],
                    'name' => $r['name'],
                    'description' => $r['description'],
                    'category' => $r['category'],
                    'cuisine' => $r['cuisine'],
                    'serves' => (int) $r['serves'],
                    'prep_time_minutes' => $r['prep_time_minutes'] ? (int) $r['prep_time_minutes'] : null,
                    'cook_time_minutes' => $r['cook_time_minutes'] ? (int) $r['cook_time_minutes'] : null,
                    'difficulty' => $r['difficulty'],
                    'ingredient_count' => (int) $r['ingredient_count'],
                    'created_by_name' => $r['created_by_name'],
                    'is_global' => $r['camp_id'] === null,
                ];
            }, $recipes),
        ]);
        exit;
    }

    // ── Single Recipe Detail ──
    if ($action === 'recipe') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) jsonError('Recipe ID required', 400);

        $stmt = $pdo->prepare("
            SELECT r.*, u.name as created_by_name
            FROM kitchen_recipes r
            LEFT JOIN users u ON r.created_by = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        $recipe = $stmt->fetch();
        if (!$recipe) jsonError('Recipe not found', 404);

        // Get ingredients with stock info
        $ingStmt = $pdo->prepare("
            SELECT kri.*, i.name as item_name, i.item_code, u.code as uom,
                   COALESCE(sb.current_qty, 0) as stock_qty,
                   sb.stock_status
            FROM kitchen_recipe_ingredients kri
            JOIN items i ON kri.item_id = i.id
            LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
            LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.camp_id = ?
            WHERE kri.recipe_id = ?
            ORDER BY kri.is_primary DESC, i.name
        ");
        $ingStmt->execute([$queryCampId, $id]);
        $ingredients = $ingStmt->fetchAll();

        $instructions = null;
        if ($recipe['instructions']) {
            $instructions = json_decode($recipe['instructions'], true);
        }

        jsonResponse([
            'recipe' => [
                'id' => (int) $recipe['id'],
                'name' => $recipe['name'],
                'description' => $recipe['description'],
                'category' => $recipe['category'],
                'cuisine' => $recipe['cuisine'],
                'serves' => (int) $recipe['serves'],
                'prep_time_minutes' => $recipe['prep_time_minutes'] ? (int) $recipe['prep_time_minutes'] : null,
                'cook_time_minutes' => $recipe['cook_time_minutes'] ? (int) $recipe['cook_time_minutes'] : null,
                'difficulty' => $recipe['difficulty'],
                'instructions' => $instructions,
                'created_by_name' => $recipe['created_by_name'],
            ],
            'ingredients' => array_map(function($ing) {
                return [
                    'id' => (int) $ing['id'],
                    'item_id' => (int) $ing['item_id'],
                    'item_name' => $ing['item_name'],
                    'item_code' => $ing['item_code'],
                    'uom' => $ing['uom'],
                    'qty_per_serving' => (float) $ing['qty_per_serving'],
                    'is_primary' => (bool) $ing['is_primary'],
                    'notes' => $ing['notes'],
                    'stock_qty' => (float) $ing['stock_qty'],
                    'stock_status' => $ing['stock_status'],
                ];
            }, $ingredients),
        ]);
        exit;
    }

    // ── Search Recipes ──
    if ($action === 'search_recipes') {
        $q = $_GET['q'] ?? '';
        if (strlen($q) < 2) jsonError('Search query too short', 400);

        $search = "%{$q}%";
        $stmt = $pdo->prepare("
            SELECT r.id, r.name, r.description, r.category, r.cuisine, r.serves, r.difficulty,
                   (SELECT COUNT(*) FROM kitchen_recipe_ingredients kri WHERE kri.recipe_id = r.id) as ingredient_count
            FROM kitchen_recipes r
            WHERE r.is_active = 1
            AND (r.camp_id IS NULL OR r.camp_id = ?)
            AND (r.name LIKE ? OR r.description LIKE ? OR r.cuisine LIKE ?)
            ORDER BY r.name
            LIMIT 20
        ");
        $stmt->execute([$queryCampId ?: 0, $search, $search, $search]);
        $recipes = $stmt->fetchAll();

        jsonResponse([
            'recipes' => array_map(function($r) {
                return [
                    'id' => (int) $r['id'],
                    'name' => $r['name'],
                    'description' => $r['description'],
                    'category' => $r['category'],
                    'cuisine' => $r['cuisine'],
                    'serves' => (int) $r['serves'],
                    'difficulty' => $r['difficulty'],
                    'ingredient_count' => (int) $r['ingredient_count'],
                ];
            }, $recipes),
        ]);
        exit;
    }

    // ── AI Suggest Recipe for Items ──
    if ($action === 'suggest_recipe') {
        $itemNames = $_GET['items'] ?? '';
        if (!$itemNames) jsonError('Item names required', 400);

        // Get available stock for context
        $stmt = $pdo->prepare("
            SELECT i.name, COALESCE(sb.current_qty, 0) as qty, u.code as uom
            FROM items i
            JOIN item_groups ig ON i.item_group_id = ig.id
            LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
            LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.camp_id = ?
            WHERE i.is_active = 1 AND ig.code IN (" . KITCHEN_GROUPS . ")
            AND COALESCE(sb.current_qty, 0) > 0
            ORDER BY sb.current_qty DESC
            LIMIT 80
        ");
        $stmt->execute([$queryCampId]);
        $available = $stmt->fetchAll();

        $stockList = array_map(function($a) {
            return "{$a['name']} ({$a['qty']} {$a['uom']})";
        }, $available);

        $prompt = "You are a professional safari lodge chef at Karibu Camps in Tanzania. "
            . "A chef wants to make a dish using these key ingredients: {$itemNames}. "
            . "Suggest 2 recipe ideas that can be made with the available stock. "
            . "\n\nAvailable kitchen stock:\n" . implode("\n", array_slice($stockList, 0, 60))
            . "\n\nFor each recipe provide:"
            . "\n1. Name (creative safari/African-themed names welcome)"
            . "\n2. Category (breakfast, lunch, dinner, snack, dessert, sauce, soup, salad, bread, other)"
            . "\n3. Ingredients list with exact measurements from the stock list"
            . "\n4. Step-by-step cooking instructions"
            . "\n5. Serves count and prep/cook time"
            . "\n\nRespond in JSON format as an array of objects with keys:"
            . " name, category, description, serves, prep_time_minutes, cook_time_minutes,"
            . " ingredients (array of {name, qty, uom, is_primary}), steps (array of strings)."
            . " Only return the JSON array, no other text.";

        $result = callGemini($prompt);
        jsonResponse(['recipes' => $result ?: [], 'items' => $itemNames]);
        exit;
    }

    // ── Suggest Alternatives (Kitchen version) ──
    if ($action === 'suggest_alternatives') {
        $ingredient = $_GET['ingredient'] ?? '';
        $dishName = $_GET['dish'] ?? '';
        if (!$ingredient) jsonError('Ingredient name required', 400);

        $availStmt = $pdo->prepare("
            SELECT i.id, i.name, ig.name as group_name,
                   COALESCE(sb.current_qty, 0) as stock_qty, u.code as uom
            FROM items i
            JOIN item_groups ig ON i.item_group_id = ig.id
            LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
            LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.camp_id = ?
            WHERE i.is_active = 1 AND ig.code IN (" . KITCHEN_GROUPS . ")
            AND COALESCE(sb.current_qty, 0) > 0
            ORDER BY ig.code, i.name
            LIMIT 80
        ");
        $availStmt->execute([$queryCampId]);
        $available = $availStmt->fetchAll();

        $stockList = array_map(function($a) {
            return "{$a['name']} ({$a['stock_qty']} {$a['uom']})";
        }, $available);

        $dishContext = $dishName ? " for the dish \"{$dishName}\"" : '';
        $prompt = "You are a professional safari lodge chef. "
            . "The ingredient \"{$ingredient}\"{$dishContext} is out of stock. "
            . "Suggest 3 alternative ingredients from this available stock that would work as a substitute. "
            . "For each alternative explain briefly why it works.\n\n"
            . "Available stock:\n" . implode("\n", array_slice($stockList, 0, 60))
            . "\n\nRespond in JSON format as an array of objects with keys: name (exact item name from list), reason (1 sentence). Only return the JSON array.";

        $alts = callGemini($prompt);

        // Match back to real items
        $result = [];
        if (is_array($alts)) {
            foreach ($alts as $alt) {
                $altName = $alt['name'] ?? '';
                foreach ($available as $a) {
                    if (stripos($a['name'], $altName) !== false || stripos($altName, $a['name']) !== false) {
                        $result[] = [
                            'item_id' => (int) $a['id'],
                            'name' => $a['name'],
                            'stock_qty' => (float) $a['stock_qty'],
                            'uom' => $a['uom'],
                            'reason' => $alt['reason'] ?? '',
                        ];
                        break;
                    }
                }
            }
        }

        jsonResponse(['alternatives' => $result, 'ingredient' => $ingredient]);
        exit;
    }

    // ── Search Stock Ingredients (kitchen groups) ──
    if ($action === 'search_ingredients') {
        $q = $_GET['q'] ?? '';
        if (strlen($q) < 2) jsonError('Search query too short', 400);

        $search = "%{$q}%";
        $stmt = $pdo->prepare("
            SELECT i.id, i.name, i.item_code, ig.name as group_name, ig.code as group_code,
                   COALESCE(sb.current_qty, 0) as stock_qty,
                   u.code as uom, sb.stock_status
            FROM items i
            JOIN item_groups ig ON i.item_group_id = ig.id
            LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
            LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.camp_id = ?
            WHERE i.is_active = 1
            AND ig.code IN (" . KITCHEN_GROUPS . ")
            AND (i.name LIKE ? OR i.item_code LIKE ?)
            ORDER BY COALESCE(sb.current_qty, 0) DESC, i.name
            LIMIT 20
        ");
        $stmt->execute([$queryCampId, $search, $search]);
        $items = $stmt->fetchAll();

        jsonResponse([
            'items' => array_map(function($i) {
                return [
                    'id' => (int) $i['id'],
                    'name' => $i['name'],
                    'item_code' => $i['item_code'],
                    'group_name' => $i['group_name'],
                    'group_code' => $i['group_code'],
                    'stock_qty' => (float) $i['stock_qty'],
                    'uom' => $i['uom'],
                    'stock_status' => $i['stock_status'],
                ];
            }, $items),
        ]);
        exit;
    }

    // ── Get User Preferences ──
    if ($action === 'preferences') {
        $type = $_GET['type'] ?? '';

        $where = ['up.user_id = ?'];
        $params = [$userId];

        if ($type) {
            $where[] = 'up.preference_type = ?';
            $params[] = $type;
        }

        $whereClause = implode(' AND ', $where);
        $stmt = $pdo->prepare("
            SELECT up.*, i.name as item_name, i.item_code,
                   ri.name as related_item_name,
                   kr.name as recipe_name
            FROM user_preferences up
            LEFT JOIN items i ON up.item_id = i.id
            LEFT JOIN items ri ON up.related_item_id = ri.id
            LEFT JOIN kitchen_recipes kr ON up.recipe_id = kr.id
            WHERE {$whereClause}
            ORDER BY up.score DESC
            LIMIT 50
        ");
        $stmt->execute($params);
        $prefs = $stmt->fetchAll();

        jsonResponse([
            'preferences' => array_map(function($p) {
                return [
                    'id' => (int) $p['id'],
                    'preference_type' => $p['preference_type'],
                    'item_id' => $p['item_id'] ? (int) $p['item_id'] : null,
                    'item_name' => $p['item_name'],
                    'item_code' => $p['item_code'],
                    'related_item_id' => $p['related_item_id'] ? (int) $p['related_item_id'] : null,
                    'related_item_name' => $p['related_item_name'],
                    'recipe_id' => $p['recipe_id'] ? (int) $p['recipe_id'] : null,
                    'recipe_name' => $p['recipe_name'],
                    'group_code' => $p['group_code'],
                    'score' => (float) $p['score'],
                    'occurrence_count' => (int) $p['occurrence_count'],
                    'last_occurred_at' => $p['last_occurred_at'],
                ];
            }, $prefs),
        ]);
        exit;
    }

    // ── AI-Ranked Suggested Items Based on Preferences ──
    if ($action === 'suggested_items') {
        $context = $_GET['context'] ?? 'issue'; // issue, order

        // Get top frequent items for this user
        $freqStmt = $pdo->prepare("
            SELECT up.item_id, i.name, i.item_code, ig.name as group_name,
                   up.score, up.occurrence_count,
                   COALESCE(sb.current_qty, 0) as stock_qty, u.code as uom
            FROM user_preferences up
            JOIN items i ON up.item_id = i.id
            JOIN item_groups ig ON i.item_group_id = ig.id
            LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
            LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.camp_id = ?
            WHERE up.user_id = ? AND up.preference_type = 'frequent_item'
            AND i.is_active = 1
            ORDER BY up.score DESC
            LIMIT 20
        ");
        $freqStmt->execute([$queryCampId, $userId]);
        $frequentItems = $freqStmt->fetchAll();

        // Get commonly paired items
        $pairStmt = $pdo->prepare("
            SELECT up.item_id, up.related_item_id,
                   i.name as item_name, ri.name as related_item_name,
                   up.score, up.occurrence_count
            FROM user_preferences up
            JOIN items i ON up.item_id = i.id
            JOIN items ri ON up.related_item_id = ri.id
            WHERE up.user_id = ? AND up.preference_type = 'item_pair'
            ORDER BY up.score DESC
            LIMIT 10
        ");
        $pairStmt->execute([$userId]);
        $pairs = $pairStmt->fetchAll();

        jsonResponse([
            'frequent_items' => array_map(function($f) {
                return [
                    'item_id' => (int) $f['item_id'],
                    'name' => $f['name'],
                    'item_code' => $f['item_code'],
                    'group_name' => $f['group_name'],
                    'score' => (float) $f['score'],
                    'times_ordered' => (int) $f['occurrence_count'],
                    'stock_qty' => (float) $f['stock_qty'],
                    'uom' => $f['uom'],
                ];
            }, $frequentItems),
            'common_pairs' => array_map(function($p) {
                return [
                    'item_name' => $p['item_name'],
                    'paired_with' => $p['related_item_name'],
                    'times_together' => (int) $p['occurrence_count'],
                    'score' => (float) $p['score'],
                ];
            }, $pairs),
        ]);
        exit;
    }

    jsonError('Invalid action', 400);
    exit;
}

// ── POST endpoints ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();
    $action = $input['action'] ?? '';

    // ── Save Kitchen Recipe ──
    if ($action === 'save_recipe') {
        requireRole(['chef', 'camp_manager', 'stores_manager', 'admin', 'director']);

        $name = trim($input['name'] ?? '');
        if (!$name) jsonError('Recipe name required', 400);

        $recipeId = $input['id'] ?? null;
        $instructions = isset($input['instructions']) ? json_encode($input['instructions']) : null;

        if ($recipeId) {
            // Update existing
            $pdo->prepare("
                UPDATE kitchen_recipes SET
                    name = ?, description = ?, category = ?, cuisine = ?,
                    serves = ?, prep_time_minutes = ?, cook_time_minutes = ?,
                    difficulty = ?, instructions = ?, updated_at = NOW()
                WHERE id = ?
            ")->execute([
                $name,
                $input['description'] ?? null,
                $input['category'] ?? 'other',
                $input['cuisine'] ?? null,
                $input['serves'] ?? 1,
                $input['prep_time_minutes'] ?? null,
                $input['cook_time_minutes'] ?? null,
                $input['difficulty'] ?? 'medium',
                $instructions,
                (int) $recipeId,
            ]);
        } else {
            // Create new
            $pdo->prepare("
                INSERT INTO kitchen_recipes (name, description, category, cuisine, serves,
                    prep_time_minutes, cook_time_minutes, difficulty, instructions,
                    camp_id, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $name,
                $input['description'] ?? null,
                $input['category'] ?? 'other',
                $input['cuisine'] ?? null,
                $input['serves'] ?? 1,
                $input['prep_time_minutes'] ?? null,
                $input['cook_time_minutes'] ?? null,
                $input['difficulty'] ?? 'medium',
                $instructions,
                $campId,
                $userId,
            ]);
            $recipeId = (int) $pdo->lastInsertId();
        }

        // Update ingredients if provided
        if (isset($input['ingredients']) && is_array($input['ingredients'])) {
            // Clear existing
            $pdo->prepare("DELETE FROM kitchen_recipe_ingredients WHERE recipe_id = ?")->execute([$recipeId]);

            // Insert new
            $ingStmt = $pdo->prepare("
                INSERT INTO kitchen_recipe_ingredients (recipe_id, item_id, qty_per_serving, is_primary, notes)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($input['ingredients'] as $ing) {
                $ingStmt->execute([
                    $recipeId,
                    (int) $ing['item_id'],
                    (float) ($ing['qty_per_serving'] ?? 1),
                    $ing['is_primary'] ?? 0,
                    $ing['notes'] ?? null,
                ]);
            }
        }

        jsonResponse([
            'message' => $input['id'] ? 'Recipe updated' : 'Recipe created',
            'recipe_id' => (int) $recipeId,
        ], $input['id'] ? 200 : 201);
        exit;
    }

    // ── Log Order Pattern (for preference learning) ──
    if ($action === 'log_pattern') {
        $items = $input['items'] ?? [];
        $source = $input['source'] ?? 'issue';
        $referenceId = $input['reference_id'] ?? null;

        if (empty($items)) {
            jsonResponse(['message' => 'No items to log']);
            exit;
        }

        $sessionId = $input['session_id'] ?? bin2hex(random_bytes(16));

        $stmt = $pdo->prepare("
            INSERT INTO order_pattern_log (user_id, session_id, item_id, qty, source, reference_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        foreach ($items as $item) {
            $stmt->execute([
                $userId,
                $sessionId,
                (int) $item['item_id'],
                (float) ($item['qty'] ?? 1),
                $source,
                $referenceId,
            ]);
        }

        // After logging, update preferences in background
        updatePreferences($pdo, $userId, $items, $source);

        jsonResponse(['message' => 'Pattern logged', 'session_id' => $sessionId]);
        exit;
    }

    // ── Delete Recipe (soft-delete) ──
    if ($action === 'delete_recipe') {
        requireRole(['chef', 'camp_manager', 'stores_manager', 'admin', 'director']);

        $recipeId = (int) ($input['id'] ?? 0);
        if (!$recipeId) jsonError('Recipe ID required', 400);

        $recipe = $pdo->prepare("SELECT id, name FROM kitchen_recipes WHERE id = ? AND is_active = 1");
        $recipe->execute([$recipeId]);
        $r = $recipe->fetch();
        if (!$r) jsonError('Recipe not found', 404);

        $pdo->prepare("UPDATE kitchen_recipes SET is_active = 0, updated_at = NOW() WHERE id = ?")->execute([$recipeId]);

        jsonResponse(['message' => 'Recipe deleted', 'recipe_id' => $recipeId]);
        exit;
    }

    // ── Trigger Preference Recomputation ──
    if ($action === 'learn') {
        recomputePreferences($pdo, $userId);
        jsonResponse(['message' => 'Preferences updated']);
        exit;
    }

    jsonError('Invalid action', 400);
    exit;
}

requireMethod('GET');


// ════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ════════════════════════════════════════════════════════════

/**
 * Call Gemini API and parse JSON response
 */
function callGemini(string $prompt): ?array {
    $ch = curl_init(GEMINI_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 2048, 'responseMimeType' => 'application/json'],
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return null;

    $data = json_decode($resp, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $parsed = json_decode($text, true);

    if (!$parsed || !is_array($parsed)) {
        if (preg_match('/\[[\s\S]*\]/', $text, $m)) {
            $parsed = json_decode($m[0], true);
        }
    }

    return is_array($parsed) ? $parsed : null;
}

/**
 * Update user preferences after logging a pattern
 * Quick incremental update (not full recomputation)
 */
function updatePreferences(PDO $pdo, int $userId, array $items, string $source): void {
    try {
        // 1. Update frequent_item scores
        foreach ($items as $item) {
            $itemId = (int) $item['item_id'];
            $qty = (float) ($item['qty'] ?? 1);

            // Upsert frequent_item preference
            $existing = $pdo->prepare("
                SELECT id, score, occurrence_count FROM user_preferences
                WHERE user_id = ? AND preference_type = 'frequent_item' AND item_id = ?
            ");
            $existing->execute([$userId, $itemId]);
            $row = $existing->fetch();

            if ($row) {
                // Increment with decay-weighted score (recent orders count more)
                $newScore = (float) $row['score'] + (1.0 * $qty);
                $newCount = (int) $row['occurrence_count'] + 1;
                $pdo->prepare("
                    UPDATE user_preferences SET score = ?, occurrence_count = ?, last_occurred_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([$newScore, $newCount, $row['id']]);
            } else {
                $pdo->prepare("
                    INSERT INTO user_preferences (user_id, preference_type, item_id, score, occurrence_count, context_data, created_at)
                    VALUES (?, 'frequent_item', ?, ?, 1, ?, NOW())
                ")->execute([$userId, $itemId, 1.0 * $qty, json_encode(['source' => $source])]);
            }
        }

        // 2. Update item_pair scores (items ordered together)
        if (count($items) >= 2) {
            for ($i = 0; $i < count($items); $i++) {
                for ($j = $i + 1; $j < count($items); $j++) {
                    $itemA = (int) $items[$i]['item_id'];
                    $itemB = (int) $items[$j]['item_id'];

                    // Always store smaller ID first for consistency
                    if ($itemA > $itemB) { $tmp = $itemA; $itemA = $itemB; $itemB = $tmp; }

                    $existing = $pdo->prepare("
                        SELECT id, score, occurrence_count FROM user_preferences
                        WHERE user_id = ? AND preference_type = 'item_pair' AND item_id = ? AND related_item_id = ?
                    ");
                    $existing->execute([$userId, $itemA, $itemB]);
                    $row = $existing->fetch();

                    if ($row) {
                        $pdo->prepare("
                            UPDATE user_preferences SET score = score + 1, occurrence_count = occurrence_count + 1,
                                   last_occurred_at = NOW(), updated_at = NOW()
                            WHERE id = ?
                        ")->execute([$row['id']]);
                    } else {
                        $pdo->prepare("
                            INSERT INTO user_preferences (user_id, preference_type, item_id, related_item_id, score, occurrence_count, created_at)
                            VALUES (?, 'item_pair', ?, ?, 1, 1, NOW())
                        ")->execute([$userId, $itemA, $itemB]);
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Non-critical: don't fail the main operation
        error_log("Preference update failed: " . $e->getMessage());
    }
}

/**
 * Full recomputation of preferences from order_pattern_log
 * Applies time decay: recent orders weigh more
 */
function recomputePreferences(PDO $pdo, int $userId): void {
    try {
        // Clear existing computed preferences for this user
        $pdo->prepare("
            DELETE FROM user_preferences WHERE user_id = ? AND preference_type IN ('frequent_item', 'item_pair', 'category_affinity')
        ")->execute([$userId]);

        // Recompute frequent_item from logs (with time decay)
        $pdo->prepare("
            INSERT INTO user_preferences (user_id, preference_type, item_id, score, occurrence_count, last_occurred_at, created_at)
            SELECT ?, 'frequent_item', item_id,
                   SUM(qty * EXP(-DATEDIFF(NOW(), created_at) / 30.0)) as score,
                   COUNT(*) as cnt,
                   MAX(created_at) as last_at
            FROM order_pattern_log
            WHERE user_id = ?
            GROUP BY item_id
            HAVING score > 0.1
            ORDER BY score DESC
            LIMIT 50
        ")->execute([$userId, $userId]);

        // Recompute item_pair from same-session items
        $pdo->prepare("
            INSERT INTO user_preferences (user_id, preference_type, item_id, related_item_id, score, occurrence_count, last_occurred_at, created_at)
            SELECT ?, 'item_pair',
                   LEAST(a.item_id, b.item_id) as item_a,
                   GREATEST(a.item_id, b.item_id) as item_b,
                   COUNT(*) as score,
                   COUNT(*) as cnt,
                   MAX(a.created_at) as last_at
            FROM order_pattern_log a
            JOIN order_pattern_log b ON a.session_id = b.session_id AND a.item_id < b.item_id
            WHERE a.user_id = ?
            GROUP BY item_a, item_b
            HAVING cnt >= 2
            ORDER BY score DESC
            LIMIT 30
        ")->execute([$userId, $userId]);

        // Recompute category_affinity
        $pdo->prepare("
            INSERT INTO user_preferences (user_id, preference_type, group_code, score, occurrence_count, last_occurred_at, created_at)
            SELECT ?, 'category_affinity', ig.code,
                   SUM(opl.qty * EXP(-DATEDIFF(NOW(), opl.created_at) / 30.0)) as score,
                   COUNT(*) as cnt,
                   MAX(opl.created_at) as last_at
            FROM order_pattern_log opl
            JOIN items i ON opl.item_id = i.id
            JOIN item_groups ig ON i.item_group_id = ig.id
            WHERE opl.user_id = ?
            GROUP BY ig.code
            ORDER BY score DESC
        ")->execute([$userId, $userId]);

    } catch (Exception $e) {
        error_log("Preference recomputation failed: " . $e->getMessage());
    }
}
