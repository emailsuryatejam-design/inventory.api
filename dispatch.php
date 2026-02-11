<?php
/**
 * KCL Stores — Dispatches
 * GET  /api/dispatch.php  — list dispatches
 * POST /api/dispatch.php  — create new dispatch
 */

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/helpers.php';

$auth = requireAuth();
$pdo = getDB();

// ── GET — List Dispatches ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;
    $status = $_GET['status'] ?? '';
    $campId = $_GET['camp_id'] ?? '';
    $search = trim($_GET['search'] ?? '');

    $where = [];
    $params = [];

    // Camp staff can only see dispatches to their camp
    if (in_array($auth['role'], ['camp_storekeeper', 'camp_manager']) && $auth['camp_id']) {
        $where[] = 'd.camp_id = ?';
        $params[] = $auth['camp_id'];
    } elseif ($campId) {
        $where[] = 'd.camp_id = ?';
        $params[] = (int) $campId;
    }

    if ($status) {
        $where[] = 'd.status = ?';
        $params[] = $status;
    }

    if ($search) {
        $where[] = '(d.dispatch_number LIKE ? OR o.order_number LIKE ? OR c.name LIKE ?)';
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count
    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM dispatches d
        JOIN orders o ON d.order_id = o.id
        JOIN camps c ON d.camp_id = c.id
        {$whereClause}
    ");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Data
    $stmt = $pdo->prepare("
        SELECT d.id, d.dispatch_number, d.order_id, d.status,
               d.total_value, d.dispatch_date,
               d.dispatched_by, d.vehicle_details, d.driver_name,
               d.notes, d.created_at,
               d.camp_id,
               o.order_number,
               c.code as camp_code, c.name as camp_name,
               u.name as dispatched_by_name
        FROM dispatches d
        JOIN orders o ON d.order_id = o.id
        JOIN camps c ON d.camp_id = c.id
        LEFT JOIN users u ON d.dispatched_by = u.id
        {$whereClause}
        ORDER BY d.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $dispatches = $stmt->fetchAll();

    // Status counts
    $countSql = "SELECT d.status, COUNT(*) as cnt FROM dispatches d GROUP BY d.status";
    $statusCounts = $pdo->query($countSql)->fetchAll(PDO::FETCH_KEY_PAIR);

    jsonResponse([
        'dispatches' => array_map(function($d) {
            return [
                'id' => (int) $d['id'],
                'dispatch_number' => $d['dispatch_number'],
                'order_id' => (int) $d['order_id'],
                'order_number' => $d['order_number'],
                'camp_id' => (int) $d['camp_id'],
                'camp_code' => $d['camp_code'],
                'camp_name' => $d['camp_name'],
                'status' => $d['status'],
                'total_value' => (float) ($d['total_value'] ?? 0),
                'dispatched_by' => $d['dispatched_by_name'],
                'dispatched_at' => $d['dispatch_date'],
                'vehicle_number' => $d['vehicle_details'],
                'driver_name' => $d['driver_name'],
                'notes' => $d['notes'],
                'created_at' => $d['created_at'],
            ];
        }, $dispatches),
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

// ── POST — Create Dispatch ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = requireManager();
    $input = getJsonInput();
    requireFields($input, ['order_id', 'lines']);

    $orderId = (int) $input['order_id'];

    // Get order details
    $order = $pdo->prepare("
        SELECT o.*, c.code as camp_code, c.id as camp_id
        FROM orders o JOIN camps c ON o.camp_id = c.id
        WHERE o.id = ? AND o.status IN ('stores_approved', 'procurement_processed')
    ");
    $order->execute([$orderId]);
    $order = $order->fetch();

    if (!$order) {
        jsonError('Order not found or not ready for dispatch', 400);
    }

    $dispatchNumber = generateDocNumber($pdo, 'DSP', $order['camp_code']);

    $pdo->beginTransaction();
    try {
        // Create dispatch header
        $pdo->prepare("
            INSERT INTO dispatches (dispatch_number, order_id, camp_id, status, total_value,
                                    dispatched_by, dispatch_date, vehicle_details, driver_name, notes, created_at)
            VALUES (?, ?, ?, 'dispatched', 0, ?, CURDATE(), ?, ?, ?, NOW())
        ")->execute([
            $dispatchNumber, $orderId, (int) $order['camp_id'], $user['user_id'],
            $input['vehicle_details'] ?? $input['vehicle_number'] ?? null,
            $input['driver_name'] ?? null,
            $input['notes'] ?? null
        ]);

        $dispatchId = (int) $pdo->lastInsertId();

        // Process lines
        $totalValue = 0;
        $lineCount = 0;
        $lineStmt = $pdo->prepare("
            INSERT INTO dispatch_lines (dispatch_id, item_id, dispatched_qty, unit_cost, total_value)
            VALUES (?, ?, ?, ?, ?)
        ");

        // Deduct from HO stock
        $hoId = (int) $pdo->query("SELECT id FROM camps WHERE code = 'HO'")->fetchColumn();

        foreach ($input['lines'] as $line) {
            if (empty($line['item_id']) || empty($line['qty']) || $line['qty'] <= 0) continue;

            $itemId = (int) $line['item_id'];
            $qty = (float) $line['qty'];

            // Get unit cost
            $item = $pdo->query("SELECT weighted_avg_cost, last_purchase_price FROM items WHERE id = {$itemId}")->fetch();
            $unitCost = $item ? ($item['weighted_avg_cost'] ?: $item['last_purchase_price'] ?: 0) : 0;
            $lineValue = $qty * $unitCost;
            $totalValue += $lineValue;
            $lineCount++;

            $lineStmt->execute([$dispatchId, $itemId, $qty, $unitCost, $lineValue]);

            // Deduct from HO stock
            $pdo->prepare("
                UPDATE stock_balances SET current_qty = GREATEST(0, current_qty - ?), updated_at = NOW()
                WHERE item_id = ? AND camp_id = ?
            ")->execute([$qty, $itemId, $hoId]);

            // Stock movement: transfer_out from HO
            $pdo->prepare("
                INSERT INTO stock_movements (item_id, camp_id, movement_type, direction, quantity, unit_cost, total_value,
                    balance_after, reference_type, reference_id, created_by, movement_date, created_at)
                VALUES (?, ?, 'transfer_out', 'out', ?, ?, ?, 0, 'dispatch', ?, ?, CURDATE(), NOW())
            ")->execute([$itemId, $hoId, $qty, $unitCost, $lineValue, $dispatchId, $user['user_id']]);
        }

        // Update dispatch total
        $pdo->prepare("UPDATE dispatches SET total_value = ? WHERE id = ?")->execute([$totalValue, $dispatchId]);

        // Update order status
        $pdo->prepare("UPDATE orders SET status = 'dispatching', updated_at = NOW() WHERE id = ?")->execute([$orderId]);

        $pdo->commit();

        jsonResponse([
            'message' => 'Dispatch created successfully',
            'dispatch' => [
                'id' => $dispatchId,
                'dispatch_number' => $dispatchNumber,
                'total_items' => $lineCount,
                'total_value' => $totalValue,
                'status' => 'dispatched',
            ],
        ], 201);

    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed to create dispatch: ' . $e->getMessage(), 500);
    }
    exit;
}

requireMethod('GET');
