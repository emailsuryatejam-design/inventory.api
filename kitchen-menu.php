<?php
/**
 * KCL Stores — Kitchen Menu Planning API
 *
 * GET  ?action=plan&date=YYYY-MM-DD&meal=lunch|dinner  — single menu plan with dishes + ingredients
 * GET  ?action=plans&month=YYYY-MM                     — all plans for a month
 * GET  ?action=audit&plan_id=X                         — audit trail for a plan
 * GET  ?action=recipe_ingredients&recipe_id=X&portions=N — recipe ingredients scaled to portions
 * GET  ?action=search_items&q=X                        — search kitchen stock items
 * GET  ?action=weekly_ingredients&week_start=YYYY-MM-DD — aggregated non-primary ingredients for the week
 *
 * POST ?action=create_plan        — create a new menu plan (date + meal + portions)
 * POST ?action=update_daily_tracking — update ordered/received/consumed qty on a menu ingredient
 * POST ?action=update_weekly_grocery — upsert ordered/received qty for a weekly grocery item
 * POST ?action=add_weekly_grocery    — manually add an item to weekly groceries
 * POST ?action=add_dish           — add a dish to a plan (optionally linked to a recipe)
 * POST ?action=remove_dish        — remove a dish from a plan
 * POST ?action=load_recipe        — load recipe ingredients into a dish
 * POST ?action=add_ingredient     — manually add an ingredient to a dish
 * POST ?action=remove_ingredient  — remove/mark ingredient as removed
 * POST ?action=update_qty         — update ingredient quantity
 * POST ?action=update_portions    — update portions count (recalcs quantities)
 * POST ?action=confirm_plan       — lock menu plan as confirmed
 * POST ?action=reopen_plan        — reopen a confirmed plan for edits
 * POST ?action=rate_presentation  — AI scores dish photo presentation
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

// Gemini config (only used for presentation scoring)
define('GEMINI_KEY', 'AIzaSyDso0Ae7zMkPuswSzrmPYfr9Q1KhQlls8c');
define('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . GEMINI_KEY);
define('KITCHEN_GROUPS', "'FD','FM','FY','FV','FF','BA','BJ','GA','OT'");

// Pantry staples — excluded when loading recipe ingredients into daily menu
define('PANTRY_STAPLES', ['salt', 'black pepper', 'white pepper', 'pepper powder', 'cooking oil', 'olive oil', 'water', 'butter', 'vegetable oil']);


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

    // ── Get Recipe Ingredients (scaled to portions) ──
    if ($action === 'recipe_ingredients') {
        $recipeId = (int) ($_GET['recipe_id'] ?? 0);
        $portions = (int) ($_GET['portions'] ?? 0);
        if (!$recipeId) jsonError('recipe_id required', 400);

        // Get recipe
        $recipeStmt = $pdo->prepare("SELECT * FROM kitchen_recipes WHERE id = ? AND is_active = 1");
        $recipeStmt->execute([$recipeId]);
        $recipe = $recipeStmt->fetch();
        if (!$recipe) jsonError('Recipe not found', 404);

        $recipeServes = max((int) $recipe['serves'], 1);
        $targetPortions = $portions > 0 ? $portions : $recipeServes;
        $ratio = $targetPortions / $recipeServes;

        // Get ingredients with stock info, exclude pantry staples
        $ingStmt = $pdo->prepare("
            SELECT kri.*, i.name as item_name, i.item_code, u.code as uom,
                   COALESCE(sb.current_qty, 0) as stock_qty, ig.code as group_code
            FROM kitchen_recipe_ingredients kri
            JOIN items i ON kri.item_id = i.id
            JOIN item_groups ig ON i.item_group_id = ig.id
            LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
            LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.camp_id = ?
            WHERE kri.recipe_id = ?
            ORDER BY kri.is_primary DESC, i.name
        ");
        $ingStmt->execute([$queryCampId, $recipeId]);
        $ingredients = $ingStmt->fetchAll();

        // Filter out pantry staples
        $filtered = [];
        foreach ($ingredients as $ing) {
            $nameLower = strtolower($ing['item_name']);
            $isStaple = false;
            foreach (PANTRY_STAPLES as $staple) {
                if ($nameLower === strtolower($staple) || strpos($nameLower, strtolower($staple)) === 0) {
                    $isStaple = true;
                    break;
                }
            }
            if ($isStaple) continue;

            $filtered[] = [
                'item_id'       => (int) $ing['item_id'],
                'item_name'     => $ing['item_name'],
                'item_code'     => $ing['item_code'],
                'uom'           => $ing['uom'],
                'group_code'    => $ing['group_code'],
                'qty_per_serving' => (float) $ing['qty_per_serving'],
                'scaled_qty'    => round((float) $ing['qty_per_serving'] * $ratio, 3),
                'is_primary'    => (bool) $ing['is_primary'],
                'notes'         => $ing['notes'],
                'stock_qty'     => (float) $ing['stock_qty'],
            ];
        }

        jsonResponse([
            'recipe' => [
                'id'   => (int) $recipe['id'],
                'name' => $recipe['name'],
                'serves' => $recipeServes,
                'category' => $recipe['category'],
            ],
            'ingredients' => $filtered,
            'portions' => $targetPortions,
            'ratio' => round($ratio, 3),
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

    // ── Weekly Ingredients (aggregated non-primary for the week) ──
    if ($action === 'weekly_ingredients') {
        $weekStart = $_GET['week_start'] ?? '';
        if (!$weekStart) jsonError('week_start required (Monday date YYYY-MM-DD)', 400);

        // Calculate week end (Sunday)
        $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

        // Get projected ingredients from menu plans
        $stmt = $pdo->prepare("
            SELECT mi.item_id,
                   i.name as item_name,
                   i.item_code,
                   u.code as uom,
                   ig.code as group_code,
                   SUM(mi.final_qty) as total_qty,
                   COUNT(DISTINCT mi.dish_id) as dish_count,
                   COALESCE(sb.current_qty, 0) as stock_qty
            FROM kitchen_menu_ingredients mi
            JOIN kitchen_menu_dishes d ON mi.dish_id = d.id
            JOIN kitchen_menu_plans p ON d.menu_plan_id = p.id
            JOIN items i ON mi.item_id = i.id
            JOIN item_groups ig ON i.item_group_id = ig.id
            LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
            LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.camp_id = ?
            WHERE p.camp_id = ?
              AND p.plan_date BETWEEN ? AND ?
              AND mi.is_removed = 0
              AND mi.is_primary = 0
            GROUP BY mi.item_id, i.name, i.item_code, u.code, ig.code, sb.current_qty
            ORDER BY i.name
        ");
        $stmt->execute([$queryCampId, $queryCampId, $weekStart, $weekEnd]);
        $rows = $stmt->fetchAll();

        // Get tracking data from kitchen_weekly_groceries
        $trackingStmt = $pdo->prepare("
            SELECT item_id, projected_qty, ordered_qty, received_qty
            FROM kitchen_weekly_groceries
            WHERE camp_id = ? AND week_start = ?
        ");
        $trackingStmt->execute([$queryCampId, $weekStart]);
        $trackingMap = [];
        foreach ($trackingStmt->fetchAll() as $t) {
            $trackingMap[(int) $t['item_id']] = $t;
        }

        // Also get manually added weekly groceries not in the projected list
        $manualStmt = $pdo->prepare("
            SELECT wg.item_id, wg.projected_qty, wg.ordered_qty, wg.received_qty,
                   i.name as item_name, i.item_code, u.code as uom, ig.code as group_code,
                   COALESCE(sb.current_qty, 0) as stock_qty
            FROM kitchen_weekly_groceries wg
            JOIN items i ON wg.item_id = i.id
            JOIN item_groups ig ON i.item_group_id = ig.id
            LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
            LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.camp_id = ?
            WHERE wg.camp_id = ? AND wg.week_start = ?
              AND wg.item_id NOT IN (
                  SELECT mi2.item_id FROM kitchen_menu_ingredients mi2
                  JOIN kitchen_menu_dishes d2 ON mi2.dish_id = d2.id
                  JOIN kitchen_menu_plans p2 ON d2.menu_plan_id = p2.id
                  WHERE p2.camp_id = ? AND p2.plan_date BETWEEN ? AND ?
                    AND mi2.is_removed = 0 AND mi2.is_primary = 0
              )
            ORDER BY i.name
        ");
        $manualStmt->execute([$queryCampId, $queryCampId, $weekStart, $queryCampId, $weekStart, $weekEnd]);
        $manualRows = $manualStmt->fetchAll();

        $ingredients = array_map(function ($r) use ($trackingMap) {
            $itemId = (int) $r['item_id'];
            $track = $trackingMap[$itemId] ?? null;
            return [
                'item_id'      => $itemId,
                'item_name'    => $r['item_name'],
                'item_code'    => $r['item_code'],
                'uom'          => $r['uom'],
                'group_code'   => $r['group_code'],
                'total_qty'    => round((float) $r['total_qty'], 3),
                'dish_count'   => (int) $r['dish_count'],
                'stock_qty'    => (float) $r['stock_qty'],
                'ordered_qty'  => $track ? (float) $track['ordered_qty'] : null,
                'received_qty' => $track ? (float) $track['received_qty'] : null,
                'is_manual'    => false,
            ];
        }, $rows);

        // Append manual items
        foreach ($manualRows as $m) {
            $ingredients[] = [
                'item_id'      => (int) $m['item_id'],
                'item_name'    => $m['item_name'],
                'item_code'    => $m['item_code'],
                'uom'          => $m['uom'],
                'group_code'   => $m['group_code'],
                'total_qty'    => round((float) $m['projected_qty'], 3),
                'dish_count'   => 0,
                'stock_qty'    => (float) $m['stock_qty'],
                'ordered_qty'  => $m['ordered_qty'] !== null ? (float) $m['ordered_qty'] : null,
                'received_qty' => $m['received_qty'] !== null ? (float) $m['received_qty'] : null,
                'is_manual'    => true,
            ];
        }

        jsonResponse([
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'ingredients' => $ingredients,
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

        $dishPortions = (int) ($input['portions'] ?? $plan['portions'] ?? 20);
        if ($dishPortions < 1) $dishPortions = 20;

        $recipeId = $input['recipe_id'] ?? null;
        if ($recipeId) $recipeId = (int) $recipeId;

        $pdo->prepare("
            INSERT INTO kitchen_menu_dishes (menu_plan_id, course, dish_name, recipe_id, portions, sort_order, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ")->execute([$planId, $course, $dish, $recipeId, $dishPortions, $nextSort]);

        $dishId = (int) $pdo->lastInsertId();

        auditLog($pdo, $planId, $dishId, null, $userId, 'add_dish', null, [
            'course' => $course, 'dish_name' => $dish, 'portions' => $dishPortions, 'recipe_id' => $recipeId,
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

    // ── Load Recipe Ingredients into a Dish ──
    if ($action === 'load_recipe') {
        $dishId   = (int) ($input['dish_id'] ?? 0);
        $recipeId = (int) ($input['recipe_id'] ?? 0);
        $portions = (int) ($input['portions'] ?? 0);
        if (!$dishId || !$recipeId) jsonError('dish_id and recipe_id required', 400);

        $dish = getDish($pdo, $dishId, $queryCampId);
        $plan = getPlanForEdit($pdo, (int) $dish['menu_plan_id'], $queryCampId);

        // Get recipe
        $recipeStmt = $pdo->prepare("SELECT * FROM kitchen_recipes WHERE id = ? AND is_active = 1");
        $recipeStmt->execute([$recipeId]);
        $recipe = $recipeStmt->fetch();
        if (!$recipe) jsonError('Recipe not found', 404);

        $recipeServes = max((int) $recipe['serves'], 1);
        $targetPortions = $portions > 0 ? $portions : (int) ($dish['portions'] ?? 20);
        $ratio = $targetPortions / $recipeServes;

        // Get recipe ingredients
        $ingStmt = $pdo->prepare("
            SELECT kri.*, i.name as item_name, u.code as uom
            FROM kitchen_recipe_ingredients kri
            JOIN items i ON kri.item_id = i.id
            LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
            WHERE kri.recipe_id = ?
        ");
        $ingStmt->execute([$recipeId]);
        $recipeIngs = $ingStmt->fetchAll();

        // Link dish to recipe
        $pdo->prepare("UPDATE kitchen_menu_dishes SET recipe_id = ? WHERE id = ?")->execute([$recipeId, $dishId]);

        $insertStmt = $pdo->prepare("
            INSERT INTO kitchen_menu_ingredients (dish_id, item_id, suggested_qty, final_qty, uom, is_primary, source, ai_reason, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'recipe', ?, NOW())
        ");

        $added = [];
        foreach ($recipeIngs as $ri) {
            $itemId = (int) $ri['item_id'];
            $nameLower = strtolower($ri['item_name']);

            // Skip pantry staples
            $isStaple = false;
            foreach (PANTRY_STAPLES as $staple) {
                if ($nameLower === strtolower($staple) || strpos($nameLower, strtolower($staple)) === 0) {
                    $isStaple = true;
                    break;
                }
            }
            if ($isStaple) continue;

            // Skip if already exists
            $exists = $pdo->prepare("SELECT id FROM kitchen_menu_ingredients WHERE dish_id = ? AND item_id = ? AND is_removed = 0");
            $exists->execute([$dishId, $itemId]);
            if ($exists->fetch()) continue;

            $baseQty  = (float) $ri['qty_per_serving'];
            $finalQty = round($baseQty * $ratio, 3);
            $uom = $ri['uom'] ?? '';
            $note = $ri['notes'] ?? '';
            $isPrimary = (int) ($ri['is_primary'] ?? 0);

            $insertStmt->execute([$dishId, $itemId, $baseQty, $finalQty, $uom, $isPrimary, $note]);
            $ingId = (int) $pdo->lastInsertId();
            $added[] = $ingId;

            auditLog($pdo, (int) $dish['menu_plan_id'], $dishId, $ingId, $userId, 'load_recipe', null, [
                'item_id' => $itemId, 'recipe_qty' => $baseQty, 'final_qty' => $finalQty, 'uom' => $uom, 'recipe_id' => $recipeId,
            ]);
        }

        jsonResponse([
            'message' => count($added) . ' ingredients loaded from recipe',
            'ingredient_ids' => $added,
            'recipe_name' => $recipe['name'],
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
            INSERT INTO kitchen_menu_ingredients (dish_id, item_id, suggested_qty, final_qty, uom, is_primary, source, created_at)
            VALUES (?, ?, NULL, ?, ?, 1, 'manual', NOW())
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

        // If it was recipe/ai_suggested and qty changed from original, mark as modified
        $newSource = $ing['source'];
        if (in_array($ing['source'], ['ai_suggested', 'recipe']) && $ing['suggested_qty'] !== null && abs($newQty - (float) $ing['suggested_qty']) > 0.001) {
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

    // ── Update Portions per dish (recalculates that dish's ingredient quantities) ──
    if ($action === 'update_portions') {
        $dishId      = (int) ($input['dish_id'] ?? 0);
        $newPortions = (int) ($input['portions'] ?? 0);
        if (!$dishId || $newPortions < 1) jsonError('dish_id and portions required', 400);

        $dish = getDish($pdo, $dishId, $queryCampId);
        $plan = getPlanForEdit($pdo, (int) $dish['menu_plan_id'], $queryCampId);

        $oldPortions = (int) ($dish['portions'] ?? 20);

        if ($oldPortions === $newPortions) {
            jsonResponse(['message' => 'Portions unchanged']);
            exit;
        }

        $ratio = $newPortions / max($oldPortions, 1);

        // Update dish portions
        $pdo->prepare("UPDATE kitchen_menu_dishes SET portions = ? WHERE id = ?")->execute([$newPortions, $dishId]);

        // Scale this dish's non-removed ingredient quantities
        $pdo->prepare("
            UPDATE kitchen_menu_ingredients
            SET final_qty = ROUND(final_qty * ?, 3),
                suggested_qty = CASE WHEN suggested_qty IS NOT NULL THEN ROUND(suggested_qty * ?, 3) ELSE NULL END,
                updated_at = NOW()
            WHERE dish_id = ? AND is_removed = 0
        ")->execute([$ratio, $ratio, $dishId]);

        auditLog($pdo, (int) $dish['menu_plan_id'], $dishId, null, $userId, 'update_portions', [
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

    // ── Rate Dish Presentation (Gemini Vision) ──
    if ($action === 'rate_presentation') {
        $dishId   = (int) ($input['dish_id'] ?? 0);
        $photoUrl = trim($input['photo_url'] ?? '');
        if (!$dishId || !$photoUrl) jsonError('dish_id and photo_url required', 400);

        $dish = getDish($pdo, $dishId, $queryCampId);

        // Build the full URL for the photo
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . $_SERVER['HTTP_HOST'];
        $fullPhotoUrl = $baseUrl . $photoUrl;

        // Call Gemini with vision prompt
        $prompt = "You are a 5-star restaurant presentation judge at a luxury safari lodge. "
            . "Score this dish photo on a 1-5 star scale.\n\n"
            . "Evaluate these criteria:\n"
            . "1. PLATING (arrangement, spacing, symmetry)\n"
            . "2. COLOR (contrast, vibrancy, visual appeal)\n"
            . "3. GARNISH (appropriate garnish, freshness)\n"
            . "4. CLEANLINESS (plate edges clean, no smears)\n"
            . "5. PORTION (appropriate size, not overcrowded)\n\n"
            . "Return JSON: { \"score\": 1-5, \"feedback\": \"2-3 sentence critique\", \"tips\": [\"tip 1\", \"tip 2\"] }";

        $result = callGeminiVision($prompt, $fullPhotoUrl);

        $score    = (int) ($result['score'] ?? 0);
        $feedback = $result['feedback'] ?? '';
        $tips     = $result['tips'] ?? [];

        if ($score < 1 || $score > 5) $score = 3; // Fallback

        // Save to dish
        $pdo->prepare("
            UPDATE kitchen_menu_dishes
            SET presentation_score = ?, presentation_feedback = ?, presentation_photo = ?
            WHERE id = ?
        ")->execute([$score, json_encode(['feedback' => $feedback, 'tips' => $tips]), $photoUrl, $dishId]);

        auditLog($pdo, (int) $dish['menu_plan_id'], $dishId, null, $userId, 'rate_presentation', null, [
            'score' => $score, 'photo' => $photoUrl,
        ]);

        jsonResponse([
            'score'    => $score,
            'feedback' => $feedback,
            'tips'     => $tips,
            'photo'    => $photoUrl,
        ]);
        exit;
    }

    // ── Update Daily Tracking (ordered/received/consumed) ──
    if ($action === 'update_daily_tracking') {
        $ingId = (int) ($input['ingredient_id'] ?? 0);
        if (!$ingId) jsonError('ingredient_id required', 400);

        $ing = getIngredient($pdo, $ingId, $queryCampId);

        $updates = [];
        $params = [];
        foreach (['ordered_qty', 'received_qty', 'consumed_qty'] as $field) {
            if (array_key_exists($field, $input)) {
                $updates[] = "{$field} = ?";
                $params[] = $input[$field] !== null ? (float) $input[$field] : null;
            }
        }
        if (empty($updates)) jsonError('At least one of ordered_qty, received_qty, consumed_qty required', 400);

        $params[] = $ingId;
        $pdo->prepare("UPDATE kitchen_menu_ingredients SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?")->execute($params);

        jsonResponse(['message' => 'Daily tracking updated']);
        exit;
    }

    // ── Update Weekly Grocery (ordered/received) ──
    if ($action === 'update_weekly_grocery') {
        $weekStart = $input['week_start'] ?? '';
        $itemId = (int) ($input['item_id'] ?? 0);
        if (!$weekStart || !$itemId) jsonError('week_start and item_id required', 400);

        $updates = [];
        $params = [];
        foreach (['ordered_qty', 'received_qty'] as $field) {
            if (array_key_exists($field, $input)) {
                $updates[] = "{$field} = ?";
                $params[] = $input[$field] !== null ? (float) $input[$field] : null;
            }
        }
        if (empty($updates)) jsonError('At least one of ordered_qty, received_qty required', 400);

        // Check if row exists
        $check = $pdo->prepare("SELECT id FROM kitchen_weekly_groceries WHERE camp_id = ? AND week_start = ? AND item_id = ?");
        $check->execute([$queryCampId, $weekStart, $itemId]);
        $existing = $check->fetch();

        if ($existing) {
            $params[] = $existing['id'];
            $pdo->prepare("UPDATE kitchen_weekly_groceries SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?")->execute($params);
        } else {
            // Auto-create row with projected_qty = 0 if doesn't exist
            $orderedQty = $input['ordered_qty'] ?? null;
            $receivedQty = $input['received_qty'] ?? null;
            $pdo->prepare("
                INSERT INTO kitchen_weekly_groceries (camp_id, week_start, item_id, projected_qty, ordered_qty, received_qty, added_by, created_at)
                VALUES (?, ?, ?, 0, ?, ?, ?, NOW())
            ")->execute([$queryCampId, $weekStart, $itemId, $orderedQty, $receivedQty, $userId]);
        }

        jsonResponse(['message' => 'Weekly grocery updated']);
        exit;
    }

    // ── Add Weekly Grocery (manual item) ──
    if ($action === 'add_weekly_grocery') {
        $weekStart = $input['week_start'] ?? '';
        $itemId = (int) ($input['item_id'] ?? 0);
        $projectedQty = (float) ($input['projected_qty'] ?? 0);
        if (!$weekStart || !$itemId) jsonError('week_start and item_id required', 400);

        // Check if already exists
        $check = $pdo->prepare("SELECT id FROM kitchen_weekly_groceries WHERE camp_id = ? AND week_start = ? AND item_id = ?");
        $check->execute([$queryCampId, $weekStart, $itemId]);
        if ($check->fetch()) jsonError('Item already in weekly groceries', 409);

        $pdo->prepare("
            INSERT INTO kitchen_weekly_groceries (camp_id, week_start, item_id, projected_qty, added_by, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ")->execute([$queryCampId, $weekStart, $itemId, $projectedQty, $userId]);

        jsonResponse(['message' => 'Weekly grocery item added', 'id' => (int) $pdo->lastInsertId()], 201);
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
 * Call Gemini Vision API with image URL for presentation scoring
 */
function callGeminiVision(string $prompt, string $imageUrl): ?array {
    // Download image and convert to base64
    $imageData = @file_get_contents($imageUrl);
    if (!$imageData) return ['score' => 3, 'feedback' => 'Could not load image for analysis.', 'tips' => []];

    $base64 = base64_encode($imageData);
    $mimeType = 'image/jpeg'; // Default

    $ch = curl_init(GEMINI_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64]],
                ],
            ]],
            'generationConfig' => ['temperature' => 0.5, 'maxOutputTokens' => 1024, 'responseMimeType' => 'application/json'],
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return ['score' => 3, 'feedback' => 'AI analysis unavailable.', 'tips' => []];

    $data   = json_decode($resp, true);
    $text   = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $parsed = json_decode($text, true);

    if (!$parsed || !is_array($parsed)) {
        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $parsed = json_decode($m[0], true);
        }
    }

    return is_array($parsed) ? $parsed : ['score' => 3, 'feedback' => 'Could not parse AI response.', 'tips' => []];
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
            ORDER BY mi.source = 'recipe' DESC, mi.source = 'ai_suggested' DESC, i.name
        ");
        $ingStmt->execute([$campId, $d['id']]);
        $ingredients = $ingStmt->fetchAll();

        // Parse presentation feedback
        $presFeedback = null;
        if ($d['presentation_feedback'] ?? null) {
            $presFeedback = json_decode($d['presentation_feedback'], true);
        }

        $result[] = [
            'id'                     => (int) $d['id'],
            'course'                 => $d['course'],
            'dish_name'              => $d['dish_name'],
            'recipe_id'              => $d['recipe_id'] ? (int) $d['recipe_id'] : null,
            'portions'               => (int) ($d['portions'] ?? 20),
            'sort_order'             => (int) $d['sort_order'],
            'presentation_score'     => $d['presentation_score'] ? (int) $d['presentation_score'] : null,
            'presentation_feedback'  => $presFeedback,
            'presentation_photo'     => $d['presentation_photo'] ?? null,
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
                    'is_primary'    => (bool) ($ing['is_primary'] ?? 0),
                    'ordered_qty'   => $ing['ordered_qty'] !== null ? (float) $ing['ordered_qty'] : null,
                    'received_qty'  => $ing['received_qty'] !== null ? (float) $ing['received_qty'] : null,
                    'consumed_qty'  => $ing['consumed_qty'] !== null ? (float) $ing['consumed_qty'] : null,
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
