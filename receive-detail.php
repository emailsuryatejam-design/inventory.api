<?php
/**
 * KCL Stores — Receipt Detail
 * GET  /api/receive-detail.php?id=123 — get receipt with lines
 * PUT  /api/receive-detail.php        — confirm receipt (update received quantities)
 */

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/helpers.php';

$auth = requireAuth();
$pdo = getDB();

// ── GET — Single Receipt ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) jsonError('Receipt ID required', 400);

    // Receipt header
    $stmt = $pdo->prepare("
        SELECT r.*, c.code as camp_code, c.name as camp_name,
               d.dispatch_number, d.order_id,
               o.order_number,
               u_recv.name as received_by_name,
               u_disp.name as dispatched_by_name
        FROM receipts r
        JOIN camps c ON r.camp_id = c.id
        LEFT JOIN dispatches d ON r.dispatch_id = d.id
        LEFT JOIN orders o ON d.order_id = o.id
        LEFT JOIN users u_recv ON r.received_by = u_recv.id
        LEFT JOIN users u_disp ON d.dispatched_by = u_disp.id
        WHERE r.id = ?
    ");
    $stmt->execute([$id]);
    $receipt = $stmt->fetch();

    if (!$receipt) jsonError('Receipt not found', 404);

    // Access control — camp staff can only see their camp's receipts
    if (in_array($auth['role'], ['camp_storekeeper', 'camp_manager']) && $auth['camp_id'] && (int) $receipt['camp_id'] !== (int) $auth['camp_id']) {
        jsonError('Access denied', 403);
    }

    // Receipt lines
    $linesStmt = $pdo->prepare("
        SELECT rl.*, i.item_code, i.name as item_name, i.stock_uom,
               ig.code as group_code
        FROM receipt_lines rl
        JOIN items i ON rl.item_id = i.id
        LEFT JOIN item_groups ig ON i.item_group_id = ig.id
        WHERE rl.receipt_id = ?
        ORDER BY rl.id
    ");
    $linesStmt->execute([$id]);
    $lines = $linesStmt->fetchAll();

    jsonResponse([
        'receipt' => [
            'id' => (int) $receipt['id'],
            'receipt_number' => $receipt['receipt_number'],
            'dispatch_number' => $receipt['dispatch_number'],
            'order_number' => $receipt['order_number'],
            'order_id' => $receipt['order_id'] ? (int) $receipt['order_id'] : null,
            'camp_code' => $receipt['camp_code'],
            'camp_name' => $receipt['camp_name'],
            'received_by' => $receipt['received_by_name'],
            'dispatched_by' => $receipt['dispatched_by_name'],
            'received_date' => $receipt['received_date'],
            'status' => $receipt['status'],
            'notes' => $receipt['notes'],
            'total_value' => (float) ($receipt['total_value'] ?? 0),
            'created_at' => $receipt['created_at'],
        ],
        'lines' => array_map(function($l) {
            return [
                'id' => (int) $l['id'],
                'item_id' => (int) $l['item_id'],
                'item_code' => $l['item_code'],
                'item_name' => $l['item_name'],
                'group_code' => $l['group_code'],
                'uom' => $l['stock_uom'],
                'dispatched_qty' => (float) $l['dispatched_qty'],
                'received_qty' => $l['received_qty'] !== null ? (float) $l['received_qty'] : null,
                'unit_cost' => (float) ($l['unit_cost'] ?? 0),
                'total_value' => (float) ($l['total_value'] ?? 0),
                'condition_status' => $l['condition_status'] ?? 'good',
                'notes' => $l['notes'],
            ];
        }, $lines),
    ]);
    exit;
}

// ── PUT — Confirm Receipt ──
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = getJsonInput();
    requireFields($input, ['id', 'lines']);

    $id = (int) $input['id'];

    // Get receipt
    $receipt = $pdo->prepare("SELECT * FROM receipts WHERE id = ?");
    $receipt->execute([$id]);
    $receipt = $receipt->fetch();

    if (!$receipt) jsonError('Receipt not found', 404);
    if ($receipt['status'] === 'confirmed') jsonError('Receipt already confirmed', 400);

    // Must be at the receiving camp
    if (in_array($auth['role'], ['camp_storekeeper', 'camp_manager']) && $auth['camp_id'] && (int) $receipt['camp_id'] !== (int) $auth['camp_id']) {
        jsonError('Access denied', 403);
    }

    $campId = (int) $receipt['camp_id'];

    $pdo->beginTransaction();
    try {
        $totalValue = 0;

        foreach ($input['lines'] as $lineInput) {
            if (empty($lineInput['line_id'])) continue;

            $lineId = (int) $lineInput['line_id'];
            $receivedQty = (float) ($lineInput['received_qty'] ?? 0);
            $condition = $lineInput['condition_status'] ?? 'good';
            $lineNotes = $lineInput['notes'] ?? null;

            // Get line details
            $line = $pdo->prepare("SELECT * FROM receipt_lines WHERE id = ? AND receipt_id = ?");
            $line->execute([$lineId, $id]);
            $line = $line->fetch();
            if (!$line) continue;

            $unitCost = (float) ($line['unit_cost'] ?? 0);
            $lineValue = $receivedQty * $unitCost;
            $totalValue += $lineValue;

            // Update receipt line
            $pdo->prepare("
                UPDATE receipt_lines
                SET received_qty = ?, condition_status = ?, notes = ?, total_value = ?
                WHERE id = ?
            ")->execute([$receivedQty, $condition, $lineNotes, $lineValue, $lineId]);

            $itemId = (int) $line['item_id'];

            // Add to stock balance
            $existingStock = $pdo->prepare("SELECT id FROM stock_balances WHERE camp_id = ? AND item_id = ?");
            $existingStock->execute([$campId, $itemId]);

            if ($existingStock->fetch()) {
                $pdo->prepare("
                    UPDATE stock_balances
                    SET current_qty = current_qty + ?,
                        current_value = current_value + ?,
                        last_receipt_date = CURDATE(),
                        updated_at = NOW()
                    WHERE camp_id = ? AND item_id = ?
                ")->execute([$receivedQty, $lineValue, $campId, $itemId]);
            } else {
                $pdo->prepare("
                    INSERT INTO stock_balances (camp_id, item_id, current_qty, current_value, last_receipt_date, updated_at)
                    VALUES (?, ?, ?, ?, CURDATE(), NOW())
                ")->execute([$campId, $itemId, $receivedQty, $lineValue]);
            }

            // Create stock movement
            $pdo->prepare("
                INSERT INTO stock_movements (item_id, camp_id, movement_type, quantity, unit_cost, total_value,
                    reference_type, reference_id, reference_number, created_by, created_at)
                VALUES (?, ?, 'receipt', ?, ?, ?, 'receipt', ?, ?, ?, NOW())
            ")->execute([$itemId, $campId, $receivedQty, $unitCost, $lineValue, $id, $receipt['receipt_number'], $auth['user_id']]);
        }

        // Update receipt status and total
        $pdo->prepare("
            UPDATE receipts
            SET status = 'confirmed', received_by = ?, received_date = CURDATE(),
                total_value = ?, notes = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$auth['user_id'], $totalValue, $input['notes'] ?? null, $id]);

        $pdo->commit();

        jsonResponse([
            'message' => 'Receipt confirmed',
            'receipt' => [
                'id' => $id,
                'receipt_number' => $receipt['receipt_number'],
                'total_value' => $totalValue,
                'status' => 'confirmed',
            ],
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed to confirm receipt: ' . $e->getMessage(), 500);
    }
    exit;
}

requireMethod('GET');
