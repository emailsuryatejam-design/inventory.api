<?php
/**
 * KCL Stores — Orders
 * GET  /api/orders.php  — list orders
 * POST /api/orders.php  — create new order
 */

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/helpers.php';

$auth = requireAuth();
$pdo = getDB();

// ── GET — List Orders ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;
    $status = $_GET['status'] ?? '';
    $campId = $_GET['camp_id'] ?? '';
    $search = trim($_GET['search'] ?? '');

    $where = [];
    $params = [];

    // Camp staff can only see their own camp orders
    if (in_array($auth['role'], ['camp_storekeeper', 'camp_manager']) && $auth['camp_id']) {
        $where[] = 'o.camp_id = ?';
        $params[] = $auth['camp_id'];
    } elseif ($campId) {
        $where[] = 'o.camp_id = ?';
        $params[] = (int) $campId;
    }

    if ($status) {
        $where[] = 'o.status = ?';
        $params[] = $status;
    }

    if ($search) {
        $where[] = '(o.order_number LIKE ? OR c.name LIKE ?)';
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN camps c ON o.camp_id = c.id {$whereClause}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Data
    $stmt = $pdo->prepare("
        SELECT o.id, o.order_number, o.camp_id, o.status,
               o.total_items, o.total_value, o.flagged_items,
               o.notes, o.submitted_at, o.created_at, o.updated_at,
               c.code as camp_code, c.name as camp_name,
               u.name as created_by_name,
               sm.name as stores_manager_name,
               o.stores_reviewed_at, o.stores_notes
        FROM orders o
        JOIN camps c ON o.camp_id = c.id
        LEFT JOIN users u ON o.created_by = u.id
        LEFT JOIN users sm ON o.stores_manager_id = sm.id
        {$whereClause}
        ORDER BY o.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    // Status counts (parameterized)
    $countWhere = '';
    $countParams = [];
    if (in_array($auth['role'], ['camp_storekeeper', 'camp_manager']) && $auth['camp_id']) {
        $countWhere = 'WHERE camp_id = ?';
        $countParams = [$auth['camp_id']];
    }
    $countStmt2 = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM orders {$countWhere} GROUP BY status");
    $countStmt2->execute($countParams);
    $statusCounts = $countStmt2->fetchAll(PDO::FETCH_KEY_PAIR);

    jsonResponse([
        'orders' => array_map(function($o) {
            return [
                'id' => (int) $o['id'],
                'order_number' => $o['order_number'],
                'camp_id' => (int) $o['camp_id'],
                'camp_code' => $o['camp_code'],
                'camp_name' => $o['camp_name'],
                'status' => $o['status'],
                'total_items' => (int) $o['total_items'],
                'total_value' => (float) $o['total_value'],
                'flagged_items' => (int) $o['flagged_items'],
                'notes' => $o['notes'],
                'created_by' => $o['created_by_name'],
                'stores_manager' => $o['stores_manager_name'],
                'stores_reviewed_at' => $o['stores_reviewed_at'],
                'stores_notes' => $o['stores_notes'],
                'submitted_at' => $o['submitted_at'],
                'created_at' => $o['created_at'],
            ];
        }, $orders),
        'status_counts' => $statusCounts,
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => ceil($total / $perPage),
        ],
    ]);
    exit;
}

// ── POST — Create Order ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();
    requireFields($input, ['lines']);

    if (!is_array($input['lines']) || count($input['lines']) === 0) {
        jsonError('At least one item line is required', 400);
    }

    // Determine camp
    $campId = $auth['camp_id'];
    if (!$campId) {
        jsonError('You must be assigned to a camp to create orders', 400);
    }

    // Get camp code (parameterized)
    $campCodeStmt = $pdo->prepare("SELECT code FROM camps WHERE id = ?");
    $campCodeStmt->execute([$campId]);
    $campCode = $campCodeStmt->fetchColumn();
    if (!$campCode) {
        jsonError('Camp not found', 400);
    }

    // HO camp id for stock checks
    $hoId = (int) $pdo->query("SELECT id FROM camps WHERE code = 'HO'")->fetchColumn();

    // Generate order number
    $orderNumber = generateDocNumber($pdo, 'ORD', $campCode);

    // ── Batch-fetch all item data + stock upfront (eliminates N+1) ──
    $itemIds = array_filter(array_map(function($l) {
        return (!empty($l['item_id']) && !empty($l['qty']) && $l['qty'] > 0) ? (int) $l['item_id'] : null;
    }, $input['lines']));
    $itemIds = array_values(array_unique($itemIds));

    $itemsMap = [];
    $campStockMap = [];
    $hoStockMap = [];

    if (count($itemIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));

        // Batch: items
        $itemStmt = $pdo->prepare("SELECT id, weighted_avg_cost, last_purchase_price FROM items WHERE id IN ({$placeholders}) AND is_active = 1");
        $itemStmt->execute($itemIds);
        foreach ($itemStmt->fetchAll() as $row) {
            $itemsMap[(int) $row['id']] = $row;
        }

        // Batch: camp stock
        $csStmt = $pdo->prepare("SELECT item_id, current_qty, par_level, avg_daily_usage FROM stock_balances WHERE item_id IN ({$placeholders}) AND camp_id = ?");
        $csStmt->execute(array_merge($itemIds, [$campId]));
        foreach ($csStmt->fetchAll() as $row) {
            $campStockMap[(int) $row['item_id']] = $row;
        }

        // Batch: HO stock
        $hsStmt = $pdo->prepare("SELECT item_id, current_qty FROM stock_balances WHERE item_id IN ({$placeholders}) AND camp_id = ?");
        $hsStmt->execute(array_merge($itemIds, [$hoId]));
        foreach ($hsStmt->fetchAll() as $row) {
            $hoStockMap[(int) $row['item_id']] = (float) $row['current_qty'];
        }
    }

    $pdo->beginTransaction();
    try {
        // Create order header
        $pdo->prepare("
            INSERT INTO orders (order_number, camp_id, created_by, status, total_items, total_value, flagged_items, notes, created_at, updated_at)
            VALUES (?, ?, ?, 'draft', 0, 0, 0, ?, NOW(), NOW())
        ")->execute([$orderNumber, $campId, $auth['user_id'], $input['notes'] ?? null]);

        $orderId = (int) $pdo->lastInsertId();

        // Process lines
        $totalValue = 0;
        $flaggedCount = 0;
        $lineCount = 0;
        $lineStmt = $pdo->prepare("
            INSERT INTO order_lines (
                order_id, item_id, requested_qty,
                camp_stock_at_order, ho_stock_at_order, par_level, avg_daily_usage,
                estimated_unit_cost, estimated_line_value,
                validation_status, validation_note, stores_action
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");

        foreach ($input['lines'] as $line) {
            if (empty($line['item_id']) || empty($line['qty']) || $line['qty'] <= 0) {
                continue;
            }

            $itemId = (int) $line['item_id'];
            $qty = (float) $line['qty'];

            // Use batch-fetched data
            $item = $itemsMap[$itemId] ?? null;
            if (!$item) continue;

            $unitCost = $item['weighted_avg_cost'] ?: $item['last_purchase_price'] ?: 0;
            $lineValue = $qty * $unitCost;
            $totalValue += $lineValue;

            $campStockRow = $campStockMap[$itemId] ?? null;
            $campStock = $campStockRow ? (float) $campStockRow['current_qty'] : 0;
            $parLevel = $campStockRow ? ($campStockRow['par_level'] ? (float) $campStockRow['par_level'] : null) : null;
            $avgUsage = $campStockRow ? ($campStockRow['avg_daily_usage'] ? (float) $campStockRow['avg_daily_usage'] : null) : null;

            $hoStock = $hoStockMap[$itemId] ?? 0;

            // Validate
            $validation = validateOrderLine($line, $campStock, $hoStock, $parLevel, $avgUsage);
            if ($validation['status'] === 'flagged') $flaggedCount++;
            $lineCount++;

            $lineStmt->execute([
                $orderId, $itemId, $qty,
                $campStock, $hoStock, $parLevel, $avgUsage,
                $unitCost, $lineValue,
                $validation['status'], $validation['note'],
            ]);
        }

        $pdo->prepare("
            UPDATE orders SET total_items = ?, total_value = ?, flagged_items = ?, status = 'submitted', submitted_at = NOW()
            WHERE id = ?
        ")->execute([$lineCount, $totalValue, $flaggedCount, $orderId]);

        $pdo->commit();

        jsonResponse([
            'message' => 'Order created successfully',
            'order' => [
                'id' => $orderId,
                'order_number' => $orderNumber,
                'total_items' => $lineCount,
                'total_value' => $totalValue,
                'flagged_items' => $flaggedCount,
                'status' => 'submitted',
            ],
        ], 201);

    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed to create order: ' . $e->getMessage(), 500);
    }
    exit;
}

requireMethod('GET');
