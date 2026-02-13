<?php
/**
 * KCL Stores — Kitchen Menu Planning API
 *
 * GET  ?action=plan&date=YYYY-MM-DD&meal=lunch|dinner  — single menu plan with dishes + ingredients
 * GET  ?action=plans&month=YYYY-MM                     — all plans for a month
 * GET  ?action=audit&plan_id=X                         — audit trail for a plan
 * GET  ?action=suggest_ingredients&dish=X&portions=N   — AI suggests ingredients for a dish
 * GET  ?action=search_items&q=X                        — search kitchen stock items
 *
 * POST ?action=create_plan        — create a new menu plan (date + meal + portions)
 * POST ?action=add_dish           — add a dish to a plan
 * POST ?action=remove_dish        — remove a dish from a plan
 * POST ?action=accept_suggestions — accept AI-suggested ingredients for a dish
 * POST ?action=add_ingredient     — manually add an ingredient to a dish
 * POST ?action=remove_ingredient  — remove/mark ingredient as removed
 * POST ?action=update_qty         — update ingredient quantity
 * POST ?action=update_portions    — update portions count (recalcs quantities)
 * POST ?action=confirm_plan       — lock menu plan as confirmed
 * POST ?action=reopen_plan        — reopen a confirmed plan for edits
 */

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/helpers.php';

$auth = requireAuth();
$pdo  = getDB();

$campId = $auth['camp_id'];
$userId = $auth['user_id'];
$role   = $auth['role'];

// Only chef, camp_manager, admin can access menu planning
$allowedRoles = ['chef', 'camp_manager', 'admin', 'director'];
if (!in_array($role, $allowedRoles)) {
    jsonError('Insufficient permissions for menu planning', 403);
}

// Gemini config (same key as kitchen.php)
define('GEMINI_KEY', 'AIzaSyDso0Ae7zMkPuswSzrmPYfr9Q1KhQlls8c');
define('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . GEMINI_KEY);
define('KITCHEN_GROUPS', "'FD','FM','FY','FV','FF','BA','BJ','GA','OT'");


// ════════════════════════════════════════════════════════════
// GET ENDPOINTS
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    $queryCampId = $campId ?: ((int) ($_GET['camp_id'] ?? 0));

    // ── Get Single Menu Plan ──
    if ($action === 'plan') {
        $date = $_GET['date'] ?? '';
        $meal = $_GET['meal'] ?? '';
        if (!$date || !$meal) jsonError('date and meal required', 400);

        $stmt = $pdo->prepare("
            SELECT p.*, u.name as created_by_name
            FROM kitchen_menu_plans p
            LEFT JOIN users u ON p.created_by = u.id
            WHERE p.camp_id = ? AND p.plan_date = ? AND p.meal_type = ?
        ");
        $stmt->execute([$queryCampId, $date, $meal]);
        $plan = $stmt->fetch();

        if (!$plan) {
            jsonResponse(['plan' => null, 'dishes' => []]);
            exit;
        }

        // Get dishes with ingredients
        $dishes = getFullPlanDishes($pdo, (int) $plan['id'], $queryCampId);

        jsonResponse([
            'plan' => formatPlan($plan),
            'dishes' => $dishes,
        ]);
        exit;
    }

    // ── List Plans for a Month ──
    if ($action === 'plans') {
        $month = $_GET['month'] ?? date('Y-m');
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        $stmt = $pdo->prepare("
            SELECT p.*, u.name as created_by_name,
                   (SELECT COUNT(*) FROM kitchen_menu_dishes d WHERE d.menu_plan_id = p.id) as dish_count
            FROM kitchen_menu_plans p
            LEFT JOIN users u ON p.created_by = u.id
            WHERE p.camp_id = ? AND p.plan_date BETWEEN ? AND ?
            ORDER BY p.plan_date, FIELD(p.meal_type, 'lunch', 'dinner')
        ");
        $stmt->execute([$queryCampId, $startDate, $endDate]);
        $plans = $stmt->fetchAll();

        jsonResponse([
            'plans' => array_map(function ($p) {
                return array_merge(formatPlan($p), [
                    'dish_count' => (int) $p['dish_count'],
                ]);
            }, $plans),
            'month' => $month,
        ]);
        exit;
    }

    // ── Audit Trail ──
    if ($action === 'audit') {
        $planId = (int) ($_GET['plan_id'] ?? 0);
        if (!$planId) jsonError('plan_id required', 400);

        // Verify plan belongs to this camp
        $check = $pdo->prepare("SELECT id FROM kitchen_menu_plans WHERE id = ? AND camp_id = ?");
        $check->execute([$planId, $queryCampId]);
        if (!$check->fetch()) jsonError('Plan not found', 404);

        $stmt = $pdo->prepare("
            SELECT a.*, u.name as user_name,
                   d.dish_name, d.course
            FROM kitchen_menu_audit_log a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN kitchen_menu_dishes d ON a.dish_id = d.id
            WHERE a.menu_plan_id = ?
            ORDER BY a.created_at ASC
        ");
        $stmt->execute([$planId]);
        $logs = $stmt->fetchAll();

        jsonResponse([
            'audit' => array_map(function ($log) {
                return [
                    'id'            => (int) $log['id'],
                    'action'        => $log['action'],
                    'user_name'     => $log['user_name'],
                    'dish_name'     => $log['dish_name'],
                    'course'        => $log['course'],
                    'old_value'     => $log['old_value'] ? json_decode($log['old_value'], true) : null,
                    'new_value'     => $log['new_value'] ? json_decode($log['new_value'], true) : null,
                    'created_at'    => $log['created_at'],
                ];
            }, $logs),
        ]);
        exit;
    }

    // ── AI Suggest Ingredients for a Dish ──
    if ($action === 'suggest_ingredients') {
        $dishName  = $_GET['dish'] ?? '';
        $portions  = (int) ($_GET['portions'] ?? 20);
        $course    = $_GET['course'] ?? '';
        if (!$dishName) jsonError('dish name required', 400);
        if ($portions < 1) $portions = 20;

        // Get available stock for context
        $stmt = $pdo->prepare("
            SELECT i.id, i.name, ig.name as group_name, ig.code as group_code,
                   COALESCE(sb.current_qty, 0) as stock_qty, u.code as uom
            FROM items i
            JOIN item_groups ig ON i.item_group_id = ig.id
            LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
            LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.camp_id = ?
            WHERE i.is_active = 1 AND ig.code IN (" . KITCHEN_GROUPS . ")
            ORDER BY ig.code, i.name
        ");
        $stmt->execute([$queryCampId]);
        $allItems = $stmt->fetchAll();

        $stockList = array_map(function ($a) {
            return "{$a['name']} (stock: {$a['stock_qty']} {$a['uom']}, group: {$a['group_name']})";
        }, $allItems);

        $courseCtx = $course ? " as a {$course} course" : '';
        $prompt = "You are a professional safari lodge chef at Karibu Camps in Tanzania. "
            . "A chef is planning to cook \"{$dishName}\"{$courseCtx} for {$portions} portions. "
            . "Suggest the main ingredients needed with exact quantities for {$portions} portions. "
            . "\n\nIMPORTANT: You MUST only use ingredient names that EXACTLY match items from this stock list. "
            . "Do not invent item names. Match the closest item from the list.\n\n"
            . "Available stock items:\n" . implode("\n", array_slice($stockList, 0, 150))
            . "\n\nFor each ingredient provide:"
            . "\n- name: EXACT item name from the stock list above"
            . "\n- qty: numeric quantity needed for {$portions} portions"
            . "\n- uom: unit (kg, g, L, ml, pcs, etc.)"
            . "\n- reason: brief reason why this ingredient is needed (1 sentence)"
            . "\n- is_primary: true if it's a core ingredient, false if supplementary"
            . "\n\nRespond as a JSON array of objects with keys: name, qty, uom, reason, is_primary."
            . " Only return the JSON array, no other text.";

        $result = callGemini($prompt);

        // Match AI names back to real stock items
        $suggestions = [];
        if (is_array($result)) {
            foreach ($result as $suggestion) {
                $aiName = $suggestion['name'] ?? '';
                $matched = matchItemByName($aiName, $allItems);
                if ($matched) {
                    $suggestions[] = [
                        'item_id'    => (int) $matched['id'],
                        'item_name'  => $matched['name'],
                        'group_code' => $matched['group_code'],
                        'stock_qty'  => (float) $matched['stock_qty'],
                        'uom'        => $matched['uom'] ?: ($suggestion['uom'] ?? ''),
                        'suggested_qty' => (float) ($suggestion['qty'] ?? 0),
                        'reason'     => $suggestion['reason'] ?? '',
                        'is_primary' => (bool) ($suggestion['is_primary'] ?? false),
                    ];
                }
            }
        }

        jsonResponse([
            'suggestions' => $suggestions,
            'dish'        => $dishName,
            'portions'    => $portions,
        ]);
        exit;
    }

    // ── Search Stock Items (for manual ingredient add) ──
    if ($action === 'search_items') {
        $q = $_GET['q'] ?? '';
        if (strlen($q) < 2) jsonError('Search query too short', 400);

        $search = "%{$q}%";
        $stmt = $pdo->prepare("
            SELECT i.id, i.name, i.item_code, ig.name as group_name, ig.code as group_code,
                   COALESCE(sb.current_qty, 0) as stock_qty,
                   u.code as uom
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
            'items' => array_map(function ($i) {
                return [
                    'id'         => (int) $i['id'],
                    'name'       => $i['name'],
                    'item_code'  => $i['item_code'],
                    'group_name' => $i['group_name'],
                    'group_code' => $i['group_code'],
                    'stock_qty'  => (float) $i['stock_qty'],
                    'uom'        => $i['uom'],
                ];
            }, $items),
        ]);
        exit;
    }

    jsonError('Invalid action', 400);
    exit;
}


// ════════════════════════════════════════════════════════════
// POST ENDPOINTS
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input  = getJsonInput();
    $action = $input['action'] ?? '';
    $queryCampId = $campId ?: ((int) ($input['camp_id'] ?? 0));

    // ── Create Menu Plan ──
    if ($action === 'create_plan') {
        $date     = $input['date'] ?? '';
        $meal     = $input['meal'] ?? '';
        $portions = (int) ($input['portions'] ?? 20);
        if (!$date || !$meal) jsonError('date and meal required', 400);
        if (!in_array($meal, ['lunch', 'dinner'])) jsonError('meal must be lunch or dinner', 400);
        if ($portions < 1) $portions = 20;

        // Check if plan already exists
        $check = $pdo->prepare("SELECT id FROM kitchen_menu_plans WHERE camp_id = ? AND plan_date = ? AND meal_type = ?");
        $check->execute([$queryCampId, $date, $meal]);
        if ($check->fetch()) jsonError('A plan for this date and meal already exists', 409);

        $pdo->prepare("
            INSERT INTO kitchen_menu_plans (camp_id, plan_date, meal_type, portions, status, created_by, created_at)
            VALUES (?, ?, ?, ?, 'draft', ?, NOW())
        ")->execute([$queryCampId, $date, $meal, $portions, $userId]);

        $planId = (int) $pdo->lastInsertId();

        // Audit log
        auditLog($pdo, $planId, null, null, $userId, 'create_plan', null, [
            'date' => $date, 'meal' => $meal, 'portions' => $portions,
        ]);

        jsonResponse([
            'message' => 'Menu plan created',
            'plan_id' => $planId,
        ], 201);
        exit;
    }

    // ── Add Dish ──
    if ($action === 'add_dish') {
        $planId  = (int) ($input['plan_id'] ?? 0);
        $course  = $input['course'] ?? '';
        $dish    = trim($input['dish_name'] ?? '');
        if (!$planId || !$course || !$dish) jsonError('plan_id, course, and dish_name required', 400);

        $plan = getPlanForEdit($pdo, $planId, $queryCampId);

        // Get next sort order
        $maxSort = $pdo->prepare("SELECT MAX(sort_order) FROM kitchen_menu_dishes WHERE menu_plan_id = ? AND course = ?");
        $maxSort->execute([$planId, $course]);
        $nextSort = ((int) $maxSort->fetchColumn()) + 1;

        $pdo->prepare("
            INSERT INTO kitchen_menu_dishes (menu_plan_id, course, dish_name, sort_order, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ")->execute([$planId, $course, $dish, $nextSort]);

        $dishId = (int) $pdo->lastInsertId();

        auditLog($pdo, $planId, $dishId, null, $userId, 'add_dish', null, [
            'course' => $course, 'dish_name' => $dish,
        ]);

        jsonResponse([
            'message' => 'Dish added',
            'dish_id' => $dishId,
        ], 201);
        exit;
    }

    // ── Remove Dish ──
    if ($action === 'remove_dish') {
        $dishId = (int) ($input['dish_id'] ?? 0);
        if (!$dishId) jsonError('dish_id required', 400);

        $dish = getDish($pdo, $dishId, $queryCampId);
        $plan = getPlanForEdit($pdo, (int) $dish['menu_plan_id'], $queryCampId);

        auditLog($pdo, (int) $dish['menu_plan_id'], $dishId, null, $userId, 'remove_dish', [
            'course' => $dish['course'], 'dish_name' => $dish['dish_name'],
        ], null);

        // CASCADE will remove ingredients too
        $pdo->prepare("DELETE FROM kitchen_menu_dishes WHERE id = ?")->execute([$dishId]);

        jsonResponse(['message' => 'Dish removed']);
        exit;
    }

    // ── Accept AI Suggestions ──
    if ($action === 'accept_suggestions') {
        $dishId      = (int) ($input['dish_id'] ?? 0);
        $suggestions = $input['suggestions'] ?? [];
        $portions    = (int) ($input['portions'] ?? 20);
        if (!$dishId || empty($suggestions)) jsonError('dish_id and suggestions required', 400);

        $dish = getDish($pdo, $dishId, $queryCampId);
        $plan = getPlanForEdit($pdo, (int) $dish['menu_plan_id'], $queryCampId);

        $insertStmt = $pdo->prepare("
            INSERT INTO kitchen_menu_ingredients (dish_id, item_id, suggested_qty, final_qty, uom, source, ai_reason, created_at)
            VALUES (?, ?, ?, ?, ?, 'ai_suggested', ?, NOW())
        ");

        $added = [];
        foreach ($suggestions as $s) {
            $itemId      = (int) ($s['item_id'] ?? 0);
            $suggestedQty = (float) ($s['suggested_qty'] ?? 0);
            $finalQty    = (float) ($s['final_qty'] ?? $suggestedQty);
            $uom         = $s['uom'] ?? '';
            $reason      = $s['reason'] ?? '';
            if (!$itemId) continue;

            // Skip if already exists for this dish
            $exists = $pdo->prepare("SELECT id FROM kitchen_menu_ingredients WHERE dish_id = ? AND item_id = ? AND is_removed = 0");
            $exists->execute([$dishId, $itemId]);
            if ($exists->fetch()) continue;

            $insertStmt->execute([$dishId, $itemId, $suggestedQty, $finalQty, $uom, $reason]);
            $ingId = (int) $pdo->lastInsertId();

            $added[] = $ingId;

            auditLog($pdo, (int) $dish['menu_plan_id'], $dishId, $ingId, $userId, 'ai_suggest', null, [
                'item_id' => $itemId, 'suggested_qty' => $suggestedQty, 'final_qty' => $finalQty, 'uom' => $uom,
            ]);
        }

        jsonResponse([
            'message' => count($added) . ' ingredients added',
            'ingredient_ids' => $added,
        ]);
        exit;
    }

    // ── Add Manual Ingredient ──
    if ($action === 'add_ingredient') {
        $dishId = (int) ($input['dish_id'] ?? 0);
        $itemId = (int) ($input['item_id'] ?? 0);
        $qty    = (float) ($input['qty'] ?? 0);
        $uom    = $input['uom'] ?? '';
        if (!$dishId || !$itemId) jsonError('dish_id and item_id required', 400);

        $dish = getDish($pdo, $dishId, $queryCampId);
        $plan = getPlanForEdit($pdo, (int) $dish['menu_plan_id'], $queryCampId);

        // Check not already added
        $exists = $pdo->prepare("SELECT id FROM kitchen_menu_ingredients WHERE dish_id = ? AND item_id = ? AND is_removed = 0");
        $exists->execute([$dishId, $itemId]);
        if ($exists->fetch()) jsonError('Ingredient already added to this dish', 409);

        $pdo->prepare("
            INSERT INTO kitchen_menu_ingredients (dish_id, item_id, suggested_qty, final_qty, uom, source, created_at)
            VALUES (?, ?, NULL, ?, ?, 'manual', NOW())
        ")->execute([$dishId, $itemId, $qty, $uom]);

        $ingId = (int) $pdo->lastInsertId();

        auditLog($pdo, (int) $dish['menu_plan_id'], $dishId, $ingId, $userId, 'add_ingredient', null, [
            'item_id' => $itemId, 'qty' => $qty, 'uom' => $uom, 'source' => 'manual',
        ]);

        jsonResponse([
            'message' => 'Ingredient added',
            'ingredient_id' => $ingId,
        ], 201);
        exit;
    }

    // ── Remove Ingredient ──
    if ($action === 'remove_ingredient') {
        $ingId  = (int) ($input['ingredient_id'] ?? 0);
        $reason = trim($input['reason'] ?? '');
        if (!$ingId) jsonError('ingredient_id required', 400);

        $ing = getIngredient($pdo, $ingId, $queryCampId);
        $plan = getPlanForEdit($pdo, (int) $ing['menu_plan_id'], $queryCampId);

        $pdo->prepare("
            UPDATE kitchen_menu_ingredients SET is_removed = 1, removed_reason = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$reason ?: null, $ingId]);

        auditLog($pdo, (int) $ing['menu_plan_id'], (int) $ing['dish_id'], $ingId, $userId, 'remove_ingredient', [
            'item_id' => (int) $ing['item_id'], 'qty' => (float) $ing['final_qty'],
        ], ['reason' => $reason]);

        jsonResponse(['message' => 'Ingredient removed']);
        exit;
    }

    // ── Update Ingredient Quantity ──
    if ($action === 'update_qty') {
        $ingId  = (int) ($input['ingredient_id'] ?? 0);
        $newQty = (float) ($input['qty'] ?? 0);
        if (!$ingId) jsonError('ingredient_id required', 400);

        $ing = getIngredient($pdo, $ingId, $queryCampId);
        $plan = getPlanForEdit($pdo, (int) $ing['menu_plan_id'], $queryCampId);

        $oldQty = (float) $ing['final_qty'];

        // If it was ai_suggested and qty changed, mark as modified
        $newSource = $ing['source'];
        if ($ing['source'] === 'ai_suggested' && abs($newQty - (float) $ing['suggested_qty']) > 0.001) {
            $newSource = 'modified';
        }

        $pdo->prepare("
            UPDATE kitchen_menu_ingredients SET final_qty = ?, source = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$newQty, $newSource, $ingId]);

        auditLog($pdo, (int) $ing['menu_plan_id'], (int) $ing['dish_id'], $ingId, $userId, 'modify_qty', [
            'qty' => $oldQty,
        ], [
            'qty' => $newQty,
        ]);

        jsonResponse(['message' => 'Quantity updated']);
        exit;
    }

    // ── Update Portions (recalculates all ingredient quantities) ──
    if ($action === 'update_portions') {
        $planId      = (int) ($input['plan_id'] ?? 0);
        $newPortions = (int) ($input['portions'] ?? 0);
        if (!$planId || $newPortions < 1) jsonError('plan_id and portions required', 400);

        $plan = getPlanForEdit($pdo, $planId, $queryCampId);
        $oldPortions = (int) $plan['portions'];

        if ($oldPortions === $newPortions) {
            jsonResponse(['message' => 'Portions unchanged']);
            exit;
        }

        $ratio = $newPortions / max($oldPortions, 1);

        // Update plan portions
        $pdo->prepare("UPDATE kitchen_menu_plans SET portions = ?, updated_at = NOW() WHERE id = ?")->execute([$newPortions, $planId]);

        // Scale all non-removed ingredient quantities
        $pdo->prepare("
            UPDATE kitchen_menu_ingredients
            SET final_qty = ROUND(final_qty * ?, 3),
                suggested_qty = CASE WHEN suggested_qty IS NOT NULL THEN ROUND(suggested_qty * ?, 3) ELSE NULL END,
                updated_at = NOW()
            WHERE dish_id IN (SELECT id FROM kitchen_menu_dishes WHERE menu_plan_id = ?)
            AND is_removed = 0
        ")->execute([$ratio, $ratio, $planId]);

        auditLog($pdo, $planId, null, null, $userId, 'update_portions', [
            'portions' => $oldPortions,
        ], [
            'portions' => $newPortions, 'ratio' => round($ratio, 3),
        ]);

        jsonResponse(['message' => "Portions updated from {$oldPortions} to {$newPortions}"]);
        exit;
    }

    // ── Confirm Plan ──
    if ($action === 'confirm_plan') {
        $planId = (int) ($input['plan_id'] ?? 0);
        if (!$planId) jsonError('plan_id required', 400);

        $plan = $pdo->prepare("SELECT * FROM kitchen_menu_plans WHERE id = ? AND camp_id = ?");
        $plan->execute([$planId, $queryCampId]);
        $plan = $plan->fetch();
        if (!$plan) jsonError('Plan not found', 404);

        $pdo->prepare("
            UPDATE kitchen_menu_plans SET status = 'confirmed', confirmed_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ")->execute([$planId]);

        auditLog($pdo, $planId, null, null, $userId, 'confirm_plan', [
            'status' => $plan['status'],
        ], [
            'status' => 'confirmed',
        ]);

        jsonResponse(['message' => 'Plan confirmed']);
        exit;
    }

    // ── Reopen Plan ──
    if ($action === 'reopen_plan') {
        $planId = (int) ($input['plan_id'] ?? 0);
        if (!$planId) jsonError('plan_id required', 400);

        $plan = $pdo->prepare("SELECT * FROM kitchen_menu_plans WHERE id = ? AND camp_id = ?");
        $plan->execute([$planId, $queryCampId]);
        $plan = $plan->fetch();
        if (!$plan) jsonError('Plan not found', 404);

        $pdo->prepare("
            UPDATE kitchen_menu_plans SET status = 'draft', confirmed_at = NULL, updated_at = NOW()
            WHERE id = ?
        ")->execute([$planId]);

        auditLog($pdo, $planId, null, null, $userId, 'reopen_plan', [
            'status' => $plan['status'],
        ], [
            'status' => 'draft',
        ]);

        jsonResponse(['message' => 'Plan reopened for edits']);
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
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 2048, 'responseMimeType' => 'application/json'],
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return null;

    $data   = json_decode($resp, true);
    $text   = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $parsed = json_decode($text, true);

    if (!$parsed || !is_array($parsed)) {
        if (preg_match('/\[[\s\S]*\]/', $text, $m)) {
            $parsed = json_decode($m[0], true);
        }
    }

    return is_array($parsed) ? $parsed : null;
}

/**
 * Fuzzy match AI-returned item name to real stock item
 */
function matchItemByName(string $aiName, array $items): ?array {
    $aiLower = strtolower(trim($aiName));
    if (!$aiLower) return null;

    // Exact match first
    foreach ($items as $item) {
        if (strtolower($item['name']) === $aiLower) return $item;
    }
    // Contains match
    foreach ($items as $item) {
        $itemLower = strtolower($item['name']);
        if (strpos($itemLower, $aiLower) !== false || strpos($aiLower, $itemLower) !== false) {
            return $item;
        }
    }
    // Word overlap match (at least 2 words match)
    $aiWords = preg_split('/\s+/', $aiLower);
    $bestMatch = null;
    $bestScore = 0;
    foreach ($items as $item) {
        $itemWords = preg_split('/\s+/', strtolower($item['name']));
        $overlap = count(array_intersect($aiWords, $itemWords));
        if ($overlap >= 2 && $overlap > $bestScore) {
            $bestScore = $overlap;
            $bestMatch = $item;
        }
    }
    return $bestMatch;
}

/**
 * Format plan row for JSON response
 */
function formatPlan(array $p): array {
    return [
        'id'              => (int) $p['id'],
        'camp_id'         => (int) $p['camp_id'],
        'plan_date'       => $p['plan_date'],
        'meal_type'       => $p['meal_type'],
        'portions'        => (int) $p['portions'],
        'status'          => $p['status'],
        'created_by'      => (int) $p['created_by'],
        'created_by_name' => $p['created_by_name'] ?? null,
        'confirmed_at'    => $p['confirmed_at'],
        'notes'           => $p['notes'],
        'created_at'      => $p['created_at'],
        'updated_at'      => $p['updated_at'],
    ];
}

/**
 * Get full dishes + ingredients for a plan
 */
function getFullPlanDishes(PDO $pdo, int $planId, int $campId): array {
    $dishStmt = $pdo->prepare("
        SELECT * FROM kitchen_menu_dishes
        WHERE menu_plan_id = ?
        ORDER BY FIELD(course, 'appetizer','soup','salad','main_course','side','dessert','beverage'), sort_order
    ");
    $dishStmt->execute([$planId]);
    $dishes = $dishStmt->fetchAll();

    $result = [];
    foreach ($dishes as $d) {
        $ingStmt = $pdo->prepare("
            SELECT mi.*, i.name as item_name, i.item_code, ig.code as group_code,
                   COALESCE(sb.current_qty, 0) as stock_qty
            FROM kitchen_menu_ingredients mi
            JOIN items i ON mi.item_id = i.id
            JOIN item_groups ig ON i.item_group_id = ig.id
            LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.camp_id = ?
            WHERE mi.dish_id = ?
            ORDER BY mi.source = 'ai_suggested' DESC, i.name
        ");
        $ingStmt->execute([$campId, $d['id']]);
        $ingredients = $ingStmt->fetchAll();

        $result[] = [
            'id'         => (int) $d['id'],
            'course'     => $d['course'],
            'dish_name'  => $d['dish_name'],
            'sort_order' => (int) $d['sort_order'],
            'ingredients' => array_map(function ($ing) {
                return [
                    'id'            => (int) $ing['id'],
                    'item_id'       => (int) $ing['item_id'],
                    'item_name'     => $ing['item_name'],
                    'item_code'     => $ing['item_code'],
                    'group_code'    => $ing['group_code'],
                    'suggested_qty' => $ing['suggested_qty'] !== null ? (float) $ing['suggested_qty'] : null,
                    'final_qty'     => (float) $ing['final_qty'],
                    'uom'           => $ing['uom'],
                    'source'        => $ing['source'],
                    'is_removed'    => (bool) $ing['is_removed'],
                    'removed_reason' => $ing['removed_reason'],
                    'ai_reason'     => $ing['ai_reason'],
                    'stock_qty'     => (float) $ing['stock_qty'],
                ];
            }, $ingredients),
        ];
    }

    return $result;
}

/**
 * Get plan and verify it's editable (draft) and belongs to camp
 */
function getPlanForEdit(PDO $pdo, int $planId, int $campId): array {
    $stmt = $pdo->prepare("SELECT * FROM kitchen_menu_plans WHERE id = ? AND camp_id = ?");
    $stmt->execute([$planId, $campId]);
    $plan = $stmt->fetch();
    if (!$plan) jsonError('Plan not found', 404);
    if ($plan['status'] === 'confirmed') jsonError('Plan is confirmed. Reopen it to make changes.', 403);
    return $plan;
}

/**
 * Get dish and verify it belongs to the camp
 */
function getDish(PDO $pdo, int $dishId, int $campId): array {
    $stmt = $pdo->prepare("
        SELECT d.*, p.camp_id, p.id as menu_plan_id
        FROM kitchen_menu_dishes d
        JOIN kitchen_menu_plans p ON d.menu_plan_id = p.id
        WHERE d.id = ? AND p.camp_id = ?
    ");
    $stmt->execute([$dishId, $campId]);
    $dish = $stmt->fetch();
    if (!$dish) jsonError('Dish not found', 404);
    return $dish;
}

/**
 * Get ingredient and verify it belongs to the camp
 */
function getIngredient(PDO $pdo, int $ingId, int $campId): array {
    $stmt = $pdo->prepare("
        SELECT mi.*, d.menu_plan_id, d.dish_name, p.camp_id
        FROM kitchen_menu_ingredients mi
        JOIN kitchen_menu_dishes d ON mi.dish_id = d.id
        JOIN kitchen_menu_plans p ON d.menu_plan_id = p.id
        WHERE mi.id = ? AND p.camp_id = ?
    ");
    $stmt->execute([$ingId, $campId]);
    $ing = $stmt->fetch();
    if (!$ing) jsonError('Ingredient not found', 404);
    return $ing;
}

/**
 * Write audit log entry
 */
function auditLog(PDO $pdo, int $planId, ?int $dishId, ?int $ingId, int $userId, string $action, ?array $oldValue, ?array $newValue): void {
    $pdo->prepare("
        INSERT INTO kitchen_menu_audit_log (menu_plan_id, dish_id, ingredient_id, user_id, action, old_value, new_value, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $planId,
        $dishId,
        $ingId,
        $userId,
        $action,
        $oldValue ? json_encode($oldValue) : null,
        $newValue ? json_encode($newValue) : null,
    ]);
}
