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
        SELECT rl.id, rl.receipt_id, rl.item_id,
               rl.expected_qty, rl.received_qty, rl.accepted_qty, rl.rejected_qty,
               rl.rejection_reason, rl.is_received,
               rl.batch_number, rl.manufacturing_date, rl.expiry_date,
               rl.receiving_temperature, rl.quality_notes, rl.discrepancy_note,
               i.item_code, i.name as item_name,
               ig.code as group_code,
               uom.code as uom_code
        FROM receipt_lines rl
        JOIN items i ON rl.item_id = i.id
        LEFT JOIN item_groups ig ON i.item_group_id = ig.id
        LEFT JOIN units_of_measure uom ON i.stock_uom_id = uom.id
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
            'created_at' => $receipt['created_at'],
        ],
        'lines' => array_map(function($l) {
            return [
                'id' => (int) $l['id'],
                'item_id' => (int) $l['item_id'],
                'item_code' => $l['item_code'],
                'item_name' => $l['item_name'],
                'group_code' => $l['group_code'],
                'uom' => $l['uom_code'],
                'expected_qty' => (float) $l['expected_qty'],
                'received_qty' => $l['received_qty'] !== null ? (float) $l['received_qty'] : null,
                'accepted_qty' => $l['accepted_qty'] !== null ? (float) $l['accepted_qty'] : null,
                'rejected_qty' => $l['rejected_qty'] !== null ? (float) $l['rejected_qty'] : null,
                'rejection_reason' => $l['rejection_reason'],
                'is_received' => (bool) $l['is_received'],
                'batch_number' => $l['batch_number'],
                'expiry_date' => $l['expiry_date'],
                'quality_notes' => $l['quality_notes'],
                'discrepancy_note' => $l['discrepancy_note'],
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

    // ── Batch-fetch all receipt lines + item costs upfront (eliminates N+1) ──
    $lineIds = array_filter(array_map(function($l) {
        return !empty($l['line_id']) ? (int) $l['line_id'] : null;
    }, $input['lines']));
    $lineIds = array_values(array_unique($lineIds));

    $linesMap = [];
    $itemCostMap = [];

    if (count($lineIds) > 0) {
        $ph = implode(',', array_fill(0, count($lineIds), '?'));

        // Batch: receipt lines
        $rlStmt = $pdo->prepare("SELECT * FROM receipt_lines WHERE id IN ({$ph}) AND receipt_id = ?");
        $rlStmt->execute(array_merge($lineIds, [$id]));
        $allItemIds = [];
        foreach ($rlStmt->fetchAll() as $row) {
            $linesMap[(int) $row['id']] = $row;
            $allItemIds[] = (int) $row['item_id'];
        }
        $allItemIds = array_values(array_unique($allItemIds));

        // Batch: item costs
        if (count($allItemIds) > 0) {
            $ph2 = implode(',', array_fill(0, count($allItemIds), '?'));
            $icStmt = $pdo->prepare("SELECT id, weighted_avg_cost, last_purchase_price FROM items WHERE id IN ({$ph2})");
            $icStmt->execute($allItemIds);
            foreach ($icStmt->fetchAll() as $row) {
                $itemCostMap[(int) $row['id']] = $row;
            }
        }
    }

    $pdo->beginTransaction();
    try {
        $totalValue = 0;

        $updateLineStmt = $pdo->prepare("
            UPDATE receipt_lines
            SET received_qty = ?, accepted_qty = ?, rejected_qty = ?,
                rejection_reason = ?, quality_notes = ?, is_received = 1
            WHERE id = ?
        ");

        // Use INSERT ... ON DUPLICATE KEY UPDATE instead of SELECT+check+INSERT/UPDATE
        $upsertStockStmt = $pdo->prepare("
            INSERT INTO stock_balances (camp_id, item_id, current_qty, current_value, unit_cost, last_receipt_date, updated_at)
            VALUES (?, ?, ?, ?, ?, CURDATE(), NOW())
            ON DUPLICATE KEY UPDATE
                current_qty = current_qty + VALUES(current_qty),
                current_value = current_value + VALUES(current_value),
                last_receipt_date = CURDATE(),
                updated_at = NOW()
        ");

        $balStmt = $pdo->prepare("SELECT current_qty FROM stock_balances WHERE camp_id = ? AND item_id = ?");

        $mvStmt = $pdo->prepare("
            INSERT INTO stock_movements (item_id, camp_id, movement_type, direction, quantity,
                unit_cost, total_value, balance_after,
                reference_type, reference_id, created_by, movement_date, created_at)
            VALUES (?, ?, 'received', 'in', ?, ?, ?, ?, 'receipt', ?, ?, CURDATE(), NOW())
        ");

        foreach ($input['lines'] as $lineInput) {
            if (empty($lineInput['line_id'])) continue;

            $lineId = (int) $lineInput['line_id'];
            $receivedQty = (float) ($lineInput['received_qty'] ?? 0);
            $acceptedQty = (float) ($lineInput['accepted_qty'] ?? $receivedQty);
            $rejectedQty = (float) ($lineInput['rejected_qty'] ?? 0);
            $rejectionReason = $lineInput['rejection_reason'] ?? null;
            $qualityNotes = $lineInput['quality_notes'] ?? null;

            $line = $linesMap[$lineId] ?? null;
            if (!$line) continue;

            $itemId = (int) $line['item_id'];
            $itemData = $itemCostMap[$itemId] ?? null;
            $unitCost = $itemData ? ((float)($itemData['weighted_avg_cost'] ?: $itemData['last_purchase_price'] ?: 0)) : 0;
            $lineValue = $acceptedQty * $unitCost;
            $totalValue += $lineValue;

            $updateLineStmt->execute([$receivedQty, $acceptedQty, $rejectedQty, $rejectionReason, $qualityNotes, $lineId]);

            if ($acceptedQty > 0) {
                $upsertStockStmt->execute([$campId, $itemId, $acceptedQty, $lineValue, $unitCost]);

                $balStmt->execute([$campId, $itemId]);
                $newBalance = (float) $balStmt->fetchColumn();

                $mvStmt->execute([$itemId, $campId, $acceptedQty, $unitCost, $lineValue, $newBalance, $id, $auth['user_id']]);
            }
        }

        // Update receipt status
        $pdo->prepare("
            UPDATE receipts
            SET status = 'confirmed', received_by = ?, received_date = CURDATE(),
                notes = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([$auth['user_id'], $input['notes'] ?? null, $id]);

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
