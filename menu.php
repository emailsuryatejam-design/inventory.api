<?php
/**
 * KCL Stores — Bar Menu API
 *
 * GET  /api/menu.php?action=categories      — menu categories
 * GET  /api/menu.php?action=items            — all menu items with stock status
 * GET  /api/menu.php?action=item&id=X        — single item detail with ingredients
 * GET  /api/menu.php?action=stock_status     — menu stock projections & depletion alerts
 * GET  /api/menu.php?action=depletion        — items at risk of running out
 * POST /api/menu.php                         — create POS order from menu items
 */

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/helpers.php';

$auth = requireAuth();
$pdo = getDB();

$campId = $auth['camp_id'];

// ── GET endpoints ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'items';
    $queryCampId = $campId ?: ((int) ($_GET['camp_id'] ?? 0));

    // ── Categories ──
    if ($action === 'categories') {
        $cats = $pdo->query("
            SELECT c.*, COUNT(m.id) as item_count
            FROM bar_menu_categories c
            LEFT JOIN bar_menu_items m ON m.category_id = c.id AND m.is_active = 1
            WHERE c.is_active = 1
            GROUP BY c.id
            ORDER BY c.sort_order
        ")->fetchAll();

        jsonResponse([
            'categories' => array_map(function($c) {
                return [
                    'id' => (int) $c['id'],
                    'code' => $c['code'],
                    'name' => $c['name'],
                    'pricing_type' => $c['pricing_type'],
                    'item_count' => (int) $c['item_count'],
                ];
            }, $cats),
        ]);
        exit;
    }

    // ── All Menu Items (with stock status for camp) ──
    if ($action === 'items') {
        $categoryId = $_GET['category_id'] ?? '';
        $type = $_GET['type'] ?? ''; // cocktail, mocktail, spirit

        $where = ['m.is_active = 1'];
        $params = [];

        if ($categoryId) {
            $where[] = 'm.category_id = ?';
            $params[] = (int) $categoryId;
        }
        if ($type === 'cocktail') {
            $where[] = 'm.is_cocktail = 1';
        } elseif ($type === 'mocktail') {
            $where[] = 'm.is_mocktail = 1';
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT m.*, c.code as category_code, c.name as category_name, c.pricing_type
            FROM bar_menu_items m
            JOIN bar_menu_categories c ON m.category_id = c.id
            WHERE {$whereClause}
            ORDER BY c.sort_order, m.sort_order
        ");
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        // For each item, check stock via fuzzy name match and ingredients table
        $menuItems = [];
        foreach ($items as $item) {
            $stockInfo = getMenuItemStock($pdo, $item, $queryCampId);
            $menuItems[] = [
                'id' => (int) $item['id'],
                'name' => $item['name'],
                'description' => $item['description'],
                'category_code' => $item['category_code'],
                'category_name' => $item['category_name'],
                'pricing_type' => $item['pricing_type'],
                'price_usd' => $item['price_usd'] ? (float) $item['price_usd'] : null,
                'is_cocktail' => (bool) $item['is_cocktail'],
                'is_mocktail' => (bool) $item['is_mocktail'],
                'serving_size_ml' => $item['serving_size_ml'] ? (int) $item['serving_size_ml'] : null,
                'glass_type' => $item['glass_type'],
                'garnish' => $item['garnish'],
                'par_per_week' => $item['par_per_week'] ? (float) $item['par_per_week'] : null,
                'stock' => $stockInfo,
            ];
        }

        jsonResponse(['menu_items' => $menuItems]);
        exit;
    }

    // ── Single Item Detail ──
    if ($action === 'item') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) jsonError('Item ID required', 400);

        $stmt = $pdo->prepare("
            SELECT m.*, c.code as category_code, c.name as category_name, c.pricing_type
            FROM bar_menu_items m
            JOIN bar_menu_categories c ON m.category_id = c.id
            WHERE m.id = ?
        ");
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (!$item) jsonError('Menu item not found', 404);

        // Get explicit ingredient mappings
        $ingStmt = $pdo->prepare("
            SELECT mi.*, i.name as item_name, i.item_code, u.code as uom,
                   COALESCE(sb.current_qty, 0) as stock_qty,
                   sb.stock_status
            FROM bar_menu_ingredients mi
            JOIN items i ON mi.item_id = i.id
            LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
            LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.camp_id = ?
            WHERE mi.menu_item_id = ?
            ORDER BY mi.is_primary DESC
        ");
        $ingStmt->execute([$queryCampId, $id]);
        $ingredients = $ingStmt->fetchAll();

        $stockInfo = getMenuItemStock($pdo, $item, $queryCampId);

        jsonResponse([
            'item' => [
                'id' => (int) $item['id'],
                'name' => $item['name'],
                'description' => $item['description'],
                'category_code' => $item['category_code'],
                'category_name' => $item['category_name'],
                'pricing_type' => $item['pricing_type'],
                'price_usd' => $item['price_usd'] ? (float) $item['price_usd'] : null,
                'is_cocktail' => (bool) $item['is_cocktail'],
                'is_mocktail' => (bool) $item['is_mocktail'],
                'serving_size_ml' => $item['serving_size_ml'] ? (int) $item['serving_size_ml'] : null,
                'glass_type' => $item['glass_type'],
                'garnish' => $item['garnish'],
                'stock' => $stockInfo,
            ],
            'ingredients' => array_map(function($ing) {
                return [
                    'item_id' => (int) $ing['item_id'],
                    'item_name' => $ing['item_name'],
                    'item_code' => $ing['item_code'],
                    'uom' => $ing['uom'],
                    'qty_per_serving' => (float) $ing['qty_per_serving'],
                    'is_primary' => (bool) $ing['is_primary'],
                    'stock_qty' => (float) $ing['stock_qty'],
                    'stock_status' => $ing['stock_status'],
                ];
            }, $ingredients),
        ]);
        exit;
    }

    // ── Stock Status Overview for All Menu Items ──
    if ($action === 'stock_status') {
        if (!$queryCampId) jsonError('Camp ID required', 400);

        $items = $pdo->query("
            SELECT m.id, m.name, m.is_cocktail, m.is_mocktail, m.par_per_week,
                   c.code as category_code, c.name as category_name
            FROM bar_menu_items m
            JOIN bar_menu_categories c ON m.category_id = c.id
            WHERE m.is_active = 1
            ORDER BY c.sort_order, m.sort_order
        ")->fetchAll();

        $results = [];
        $summary = ['available' => 0, 'low' => 0, 'critical' => 0, 'out' => 0, 'unknown' => 0];

        foreach ($items as $item) {
            $stock = getMenuItemStock($pdo, $item, $queryCampId);
            $status = $stock['status'] ?? 'unknown';
            $summary[$status] = ($summary[$status] ?? 0) + 1;

            $results[] = [
                'id' => (int) $item['id'],
                'name' => $item['name'],
                'category' => $item['category_name'],
                'category_code' => $item['category_code'],
                'is_cocktail' => (bool) $item['is_cocktail'],
                'is_mocktail' => (bool) $item['is_mocktail'],
                'stock' => $stock,
            ];
        }

        jsonResponse([
            'summary' => $summary,
            'items' => $results,
        ]);
        exit;
    }

    // ── Depletion Alerts — items at risk ──
    if ($action === 'depletion') {
        if (!$queryCampId) jsonError('Camp ID required', 400);

        $days = max(1, min(90, (int) ($_GET['days'] ?? 7)));

        $items = $pdo->query("
            SELECT m.id, m.name, m.is_cocktail, m.is_mocktail, m.par_per_week,
                   c.code as category_code, c.name as category_name
            FROM bar_menu_items m
            JOIN bar_menu_categories c ON m.category_id = c.id
            WHERE m.is_active = 1
            ORDER BY c.sort_order, m.sort_order
        ")->fetchAll();

        $alerts = [];
        foreach ($items as $item) {
            $stock = getMenuItemStock($pdo, $item, $queryCampId);
            if (in_array($stock['status'], ['low', 'critical', 'out'])) {
                $alerts[] = [
                    'id' => (int) $item['id'],
                    'name' => $item['name'],
                    'category' => $item['category_name'],
                    'status' => $stock['status'],
                    'stock' => $stock,
                ];
            }
        }

        // Sort by severity: out > critical > low
        usort($alerts, function($a, $b) {
            $order = ['out' => 0, 'critical' => 1, 'low' => 2];
            return ($order[$a['status']] ?? 3) - ($order[$b['status']] ?? 3);
        });

        jsonResponse([
            'alerts' => $alerts,
            'total' => count($alerts),
            'horizon_days' => $days,
        ]);
        exit;
    }

    jsonError('Invalid action. Use: categories, items, item, stock_status, depletion', 400);
    exit;
}

// ── POST — Order from Menu (creates issue voucher with ingredient deductions) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();
    requireFields($input, ['items']);

    if (!is_array($input['items']) || count($input['items']) === 0) {
        jsonError('Add at least one menu item', 400);
    }

    if (!$campId) jsonError('POS requires camp assignment', 400);

    $receivedBy = $input['received_by'] ?? 'Bar Guest';
    $tableNumber = $input['table_number'] ?? null;
    $guestCount = $input['guest_count'] ?? null;
    $notes = $input['notes'] ?? null;

    // Get BAR cost center
    $costCenterId = $pdo->query("SELECT id FROM cost_centers WHERE code = 'BAR' LIMIT 1")->fetchColumn();
    if (!$costCenterId) {
        $costCenterId = $pdo->query("SELECT id FROM cost_centers WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    }

    $campCode = $pdo->query("SELECT code FROM camps WHERE id = {$campId}")->fetchColumn();
    $voucherNumber = generateDocNumber($pdo, 'BAR', $campCode);

    $pdo->beginTransaction();
    try {
        // Build notes with drink names
        $drinkNames = [];
        foreach ($input['items'] as $menuOrder) {
            $menuItem = $pdo->prepare("SELECT name FROM bar_menu_items WHERE id = ?");
            $menuItem->execute([(int) $menuOrder['menu_item_id']]);
            $name = $menuItem->fetchColumn();
            if ($name) $drinkNames[] = ($menuOrder['qty'] ?? 1) . 'x ' . $name;
        }
        $orderNotes = implode(', ', $drinkNames);
        if ($tableNumber) $orderNotes = "Table {$tableNumber}: " . $orderNotes;
        if ($notes) $orderNotes .= " — {$notes}";

        // Create issue voucher
        $pdo->prepare("
            INSERT INTO issue_vouchers (
                voucher_number, camp_id, issue_type, cost_center_id,
                issue_date, issued_by, received_by_name, department,
                room_numbers, guest_count, total_value, notes, status, created_at
            ) VALUES (?, ?, 'kitchen', ?, CURDATE(), ?, ?, 'Bar', ?, ?, 0, ?, 'confirmed', NOW())
        ")->execute([
            $voucherNumber, $campId, (int) $costCenterId,
            $auth['user_id'], $receivedBy,
            null, $guestCount ? (int) $guestCount : null, $orderNotes,
        ]);

        $voucherId = (int) $pdo->lastInsertId();
        $totalValue = 0;

        $lineStmt = $pdo->prepare("
            INSERT INTO issue_voucher_lines (voucher_id, item_id, quantity, unit_cost, total_value, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        // Process each menu item ordered
        foreach ($input['items'] as $menuOrder) {
            $menuItemId = (int) $menuOrder['menu_item_id'];
            $qty = max(1, (int) ($menuOrder['qty'] ?? 1));

            // Get menu item info
            $mi = $pdo->prepare("SELECT * FROM bar_menu_items WHERE id = ?");
            $mi->execute([$menuItemId]);
            $menuItem = $mi->fetch();
            if (!$menuItem) continue;

            // Check for explicit ingredient mappings first
            $ingStmt = $pdo->prepare("
                SELECT mi.item_id, mi.qty_per_serving, i.weighted_avg_cost, i.last_purchase_price
                FROM bar_menu_ingredients mi
                JOIN items i ON mi.item_id = i.id
                WHERE mi.menu_item_id = ?
            ");
            $ingStmt->execute([$menuItemId]);
            $ingredients = $ingStmt->fetchAll();

            if (count($ingredients) > 0) {
                // Deduct each ingredient
                foreach ($ingredients as $ing) {
                    $itemId = (int) $ing['item_id'];
                    $deductQty = (float) $ing['qty_per_serving'] * $qty;
                    $unitCost = (float) ($ing['weighted_avg_cost'] ?: $ing['last_purchase_price'] ?: 0);
                    $lineValue = $deductQty * $unitCost;
                    $totalValue += $lineValue;

                    $lineStmt->execute([
                        $voucherId, $itemId, $deductQty, $unitCost, $lineValue,
                        $menuItem['name'] . " (x{$qty})",
                    ]);

                    deductStock($pdo, $campId, $itemId, $deductQty, $lineValue, $voucherId, $costCenterId, $auth['user_id']);
                }
            } else {
                // Fuzzy match: try to find inventory item by menu item name
                $matchedItem = fuzzyMatchItem($pdo, $menuItem['name']);
                if ($matchedItem) {
                    $itemId = (int) $matchedItem['id'];
                    // For spirits: 1 serving = 45ml = ~0.06 of a 750ml bottle
                    $servingSize = $menuItem['serving_size_ml'] ?: 45;
                    $deductQty = ($servingSize / 750) * $qty; // fraction of bottle
                    $unitCost = (float) ($matchedItem['weighted_avg_cost'] ?: $matchedItem['last_purchase_price'] ?: 0);
                    $lineValue = $deductQty * $unitCost;
                    $totalValue += $lineValue;

                    $lineStmt->execute([
                        $voucherId, $itemId, $deductQty, $unitCost, $lineValue,
                        $menuItem['name'] . " (x{$qty})",
                    ]);

                    deductStock($pdo, $campId, $itemId, $deductQty, $lineValue, $voucherId, $costCenterId, $auth['user_id']);
                }
            }
        }

        // Update total
        $pdo->prepare("UPDATE issue_vouchers SET total_value = ? WHERE id = ?")->execute([$totalValue, $voucherId]);

        $pdo->commit();

        jsonResponse([
            'message' => 'Bar order completed',
            'order' => [
                'id' => $voucherId,
                'voucher_number' => $voucherNumber,
                'total_value' => $totalValue,
                'drinks' => $drinkNames,
            ],
        ], 201);

    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Bar order failed: ' . $e->getMessage(), 500);
    }
    exit;
}

requireMethod('GET');


// ════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ════════════════════════════════════════════════════════════

/**
 * Get stock information for a menu item at a given camp
 * Uses ingredient mappings or fuzzy name matching to inventory
 */
function getMenuItemStock(PDO $pdo, array $menuItem, int $campId): array {
    if (!$campId) return ['status' => 'unknown', 'message' => 'No camp selected'];

    // Check explicit ingredient mappings
    $ingStmt = $pdo->prepare("
        SELECT mi.item_id, mi.qty_per_serving, mi.is_primary,
               i.name as item_name,
               COALESCE(sb.current_qty, 0) as stock_qty,
               sb.stock_status, sb.avg_daily_usage, sb.days_stock_on_hand
        FROM bar_menu_ingredients mi
        JOIN items i ON mi.item_id = i.id
        LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.camp_id = ?
        WHERE mi.menu_item_id = ?
    ");
    $ingStmt->execute([$campId, $menuItem['id']]);
    $ingredients = $ingStmt->fetchAll();

    if (count($ingredients) > 0) {
        // Calculate servings possible from each ingredient
        $minServings = PHP_INT_MAX;
        $limitingIngredient = '';
        $ingredientStatus = [];

        foreach ($ingredients as $ing) {
            $qtyPerServing = (float) $ing['qty_per_serving'];
            $stockQty = (float) $ing['stock_qty'];
            $servings = $qtyPerServing > 0 ? floor($stockQty / $qtyPerServing) : 0;

            if ($servings < $minServings) {
                $minServings = $servings;
                $limitingIngredient = $ing['item_name'];
            }

            $ingredientStatus[] = [
                'name' => $ing['item_name'],
                'stock_qty' => $stockQty,
                'servings_possible' => $servings,
                'stock_status' => $ing['stock_status'],
            ];
        }

        $status = 'available';
        if ($minServings <= 0) $status = 'out';
        elseif ($minServings <= 5) $status = 'critical';
        elseif ($minServings <= 15) $status = 'low';

        return [
            'status' => $status,
            'servings_possible' => $minServings === PHP_INT_MAX ? 0 : $minServings,
            'limiting_ingredient' => $limitingIngredient,
            'ingredients' => $ingredientStatus,
            'days_remaining' => null,
        ];
    }

    // Fuzzy match to inventory
    $matched = fuzzyMatchItem($pdo, $menuItem['name']);
    if ($matched) {
        $sbStmt = $pdo->prepare("
            SELECT current_qty, stock_status, avg_daily_usage, days_stock_on_hand
            FROM stock_balances WHERE camp_id = ? AND item_id = ?
        ");
        $sbStmt->execute([$campId, $matched['id']]);
        $sb = $sbStmt->fetch();

        if ($sb) {
            $servingSize = $menuItem['serving_size_ml'] ?? 45;
            $bottleServings = 750 / $servingSize;
            $servings = floor((float) $sb['current_qty'] * $bottleServings);

            $status = 'available';
            if ($servings <= 0) $status = 'out';
            elseif ($servings <= 5) $status = 'critical';
            elseif ($servings <= 15) $status = 'low';

            return [
                'status' => $status,
                'servings_possible' => $servings,
                'stock_qty' => (float) $sb['current_qty'],
                'stock_status' => $sb['stock_status'],
                'days_remaining' => $sb['days_stock_on_hand'] ? (int) $sb['days_stock_on_hand'] : null,
                'matched_item' => $matched['name'],
                'matched_item_id' => (int) $matched['id'],
            ];
        }

        return [
            'status' => 'unknown',
            'message' => 'No stock balance record',
            'matched_item' => $matched['name'],
            'matched_item_id' => (int) $matched['id'],
        ];
    }

    return ['status' => 'unknown', 'message' => 'No inventory match found'];
}

/**
 * Fuzzy match a menu item name to inventory items
 */
function fuzzyMatchItem(PDO $pdo, string $menuName): ?array {
    // Clean up name for matching
    $search = strtolower(trim($menuName));
    $search = preg_replace('/\s*\(.*\)\s*$/', '', $search); // remove parenthetical
    $search = preg_replace('/\s+/', '%', $search);

    $stmt = $pdo->prepare("
        SELECT id, name, weighted_avg_cost, last_purchase_price
        FROM items
        WHERE LOWER(name) LIKE ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute(["%{$search}%"]);
    $match = $stmt->fetch();

    if ($match) return $match;

    // Try with just the first significant word(s)
    $words = explode(' ', strtolower(trim($menuName)));
    if (count($words) >= 2) {
        $shortSearch = '%' . $words[0] . '%' . $words[1] . '%';
        $stmt->execute([$shortSearch]);
        $match = $stmt->fetch();
        if ($match) return $match;
    }

    return null;
}

/**
 * Deduct stock and create movement record
 */
function deductStock(PDO $pdo, int $campId, int $itemId, float $qty, float $value, int $voucherId, int $costCenterId, int $userId): void {
    // Deduct
    $pdo->prepare("
        UPDATE stock_balances
        SET current_qty = GREATEST(0, current_qty - ?),
            current_value = GREATEST(0, current_value - ?),
            last_issue_date = CURDATE(),
            updated_at = NOW()
        WHERE camp_id = ? AND item_id = ?
    ")->execute([$qty, $value, $campId, $itemId]);

    // Get balance after
    $balStmt = $pdo->prepare("SELECT current_qty FROM stock_balances WHERE camp_id = ? AND item_id = ?");
    $balStmt->execute([$campId, $itemId]);
    $balAfter = (float) ($balStmt->fetchColumn() ?: 0);

    // Stock movement
    $pdo->prepare("
        INSERT INTO stock_movements (item_id, camp_id, movement_type, direction, quantity, unit_cost, total_value,
            balance_after, reference_type, reference_id, cost_center_id, created_by, movement_date, created_at)
        VALUES (?, ?, 'issue_kitchen', 'out', ?, ?, ?, ?, 'issue_voucher', ?, ?, ?, CURDATE(), NOW())
    ")->execute([$itemId, $campId, $qty, $value / max($qty, 0.001), $value, $balAfter,
                 $voucherId, $costCenterId, $userId]);
}
