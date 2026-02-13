<?php
/**
 * KCL Stores — POS (Point of Sale)
 *
 * GET  /api/pos.php?action=categories     — item groups with counts
 * GET  /api/pos.php?action=items           — items for POS grid (filterable)
 * GET  /api/pos.php?action=recent          — recent POS transactions
 * POST /api/pos.php                        — create POS sale (issue voucher)
 */

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/helpers.php';

$auth = requireAuth();
$pdo = getDB();

$campId = $auth['camp_id'];
if (!$campId) {
    jsonError('POS requires camp assignment', 400);
}

// ── GET endpoints ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    // ── Categories — item groups with item counts ──
    if ($action === 'categories') {
        $stmt = $pdo->prepare("
            SELECT
                ig.id, ig.code, ig.name,
                COUNT(DISTINCT i.id) as item_count,
                COUNT(DISTINCT CASE WHEN sb.current_qty > 0 THEN sb.item_id END) as in_stock_count
            FROM item_groups ig
            JOIN items i ON i.item_group_id = ig.id AND i.is_active = 1
            LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.camp_id = ?
            GROUP BY ig.id, ig.code, ig.name
            HAVING item_count > 0
            ORDER BY ig.name
        ");
        $stmt->execute([$campId]);
        $groups = $stmt->fetchAll();

        jsonResponse([
            'categories' => array_map(function($g) {
                return [
                    'id' => (int) $g['id'],
                    'code' => $g['code'],
                    'name' => $g['name'],
                    'item_count' => (int) $g['item_count'],
                    'in_stock_count' => (int) $g['in_stock_count'],
                ];
            }, $groups),
        ]);
        exit;
    }

    // ── Items for POS grid ──
    if ($action === 'items') {
        $groupId = $_GET['group_id'] ?? '';
        $search = $_GET['search'] ?? '';
        $limit = min(100, max(10, (int) ($_GET['limit'] ?? 50)));

        $where = ['i.is_active = 1'];
        $params = [$campId];

        if ($groupId) {
            $where[] = 'i.item_group_id = ?';
            $params[] = (int) $groupId;
        }

        if ($search) {
            $where[] = '(i.name LIKE ? OR i.item_code LIKE ? OR i.barcode LIKE ?)';
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT
                i.id, i.item_code, i.name, i.barcode,
                i.weighted_avg_cost, i.last_purchase_price,
                ig.code as group_code, ig.name as group_name,
                u.code as uom,
                COALESCE(sb.current_qty, 0) as stock_qty,
                sb.stock_status
            FROM items i
            JOIN item_groups ig ON i.item_group_id = ig.id
            LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
            LEFT JOIN stock_balances sb ON sb.item_id = i.id AND sb.camp_id = ?
            WHERE {$whereClause}
            ORDER BY i.name
            LIMIT {$limit}
        ");
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        jsonResponse([
            'items' => array_map(function($item) {
                $price = (float) ($item['weighted_avg_cost'] ?: $item['last_purchase_price'] ?: 0);
                return [
                    'id' => (int) $item['id'],
                    'item_code' => $item['item_code'],
                    'name' => $item['name'],
                    'barcode' => $item['barcode'],
                    'group_code' => $item['group_code'],
                    'group_name' => $item['group_name'],
                    'uom' => $item['uom'],
                    'price' => $price,
                    'stock_qty' => (float) $item['stock_qty'],
                    'stock_status' => $item['stock_status'],
                ];
            }, $items),
        ]);
        exit;
    }

    // ── Recent POS transactions ──
    if ($action === 'recent') {
        $limit = min(50, max(5, (int) ($_GET['limit'] ?? 20)));
        $stmt = $pdo->prepare("
            SELECT iv.id, iv.voucher_number, iv.issue_type, iv.received_by_name,
                   iv.total_value, iv.guest_count, iv.room_numbers, iv.notes,
                   iv.created_at, iv.status,
                   cc.name as cost_center_name,
                   u.name as issued_by_name,
                   (SELECT COUNT(*) FROM issue_voucher_lines WHERE voucher_id = iv.id) as line_count
            FROM issue_vouchers iv
            LEFT JOIN cost_centers cc ON iv.cost_center_id = cc.id
            LEFT JOIN users u ON iv.issued_by = u.id
            WHERE iv.camp_id = ?
            ORDER BY iv.created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$campId]);
        $vouchers = $stmt->fetchAll();

        jsonResponse([
            'transactions' => array_map(function($v) {
                return [
                    'id' => (int) $v['id'],
                    'voucher_number' => $v['voucher_number'],
                    'issue_type' => $v['issue_type'],
                    'cost_center' => $v['cost_center_name'],
                    'issued_by' => $v['issued_by_name'],
                    'received_by' => $v['received_by_name'],
                    'total_value' => (float) $v['total_value'],
                    'guest_count' => $v['guest_count'] ? (int) $v['guest_count'] : null,
                    'room_numbers' => $v['room_numbers'],
                    'line_count' => (int) $v['line_count'],
                    'status' => $v['status'],
                    'notes' => $v['notes'],
                    'created_at' => $v['created_at'],
                ];
            }, $vouchers),
        ]);
        exit;
    }

    // ── POS summary/stats for today ──
    if ($action === 'today') {
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) as total_transactions,
                COALESCE(SUM(total_value), 0) as total_value,
                COALESCE(SUM(guest_count), 0) as total_guests
            FROM issue_vouchers
            WHERE camp_id = ? AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$campId]);
        $today = $stmt->fetch();

        // Breakdown by type
        $stmt2 = $pdo->prepare("
            SELECT issue_type, COUNT(*) as count, COALESCE(SUM(total_value), 0) as value
            FROM issue_vouchers
            WHERE camp_id = ? AND DATE(created_at) = CURDATE()
            GROUP BY issue_type
        ");
        $stmt2->execute([$campId]);
        $byType = $stmt2->fetchAll();

        jsonResponse([
            'today' => [
                'transactions' => (int) $today['total_transactions'],
                'value' => (float) $today['total_value'],
                'guests' => (int) $today['total_guests'],
            ],
            'by_type' => array_map(function($t) {
                return [
                    'type' => $t['issue_type'],
                    'count' => (int) $t['count'],
                    'value' => (float) $t['value'],
                ];
            }, $byType),
        ]);
        exit;
    }

    jsonError('Invalid action. Use: categories, items, recent, today', 400);
    exit;
}

// ── POST — Create POS Transaction (Issue Voucher) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();
    requireFields($input, ['service_type', 'items']);

    if (!is_array($input['items']) || count($input['items']) === 0) {
        jsonError('Add at least one item', 400);
    }

    $serviceType = $input['service_type']; // bar, kitchen, guest, rooms etc.
    $costCenterCode = strtoupper($input['cost_center'] ?? 'BAR');
    $receivedBy = $input['received_by'] ?? 'Walk-in';
    $tableNumber = $input['table_number'] ?? null;
    $guestCount = $input['guest_count'] ?? null;
    $roomNumbers = $input['room_numbers'] ?? null;
    $notes = $input['notes'] ?? null;

    // Map service type to issue_type enum (kitchen, rooms, employee, waste, other)
    $issueTypeMap = [
        'bar' => 'kitchen',        // bar uses kitchen issue_type (F&B)
        'kitchen' => 'kitchen',
        'restaurant' => 'kitchen',
        'rooms' => 'rooms',
        'housekeeping' => 'rooms',
        'guest' => 'rooms',        // guest uses rooms
        'staff' => 'employee',
        'maintenance' => 'other',
        'other' => 'other',
    ];
    $issueType = $issueTypeMap[$serviceType] ?? 'other';

    // Get cost center ID
    $ccStmt = $pdo->prepare("SELECT id FROM cost_centers WHERE code = ? LIMIT 1");
    $ccStmt->execute([$costCenterCode]);
    $costCenterId = $ccStmt->fetchColumn();
    if (!$costCenterId) {
        // Fallback: get first active cost center
        $costCenterId = $pdo->query("SELECT id FROM cost_centers WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    }

    $ccStmt2 = $pdo->prepare("SELECT code FROM camps WHERE id = ?");
    $ccStmt2->execute([$campId]);
    $campCode = $ccStmt2->fetchColumn();
    $voucherNumber = generateDocNumber($pdo, 'POS', $campCode);

    // ── Batch-fetch all item costs upfront (eliminates N+1) ──
    $itemIds = array_filter(array_map(function($i) {
        return (!empty($i['id']) && !empty($i['qty']) && $i['qty'] > 0) ? (int) $i['id'] : null;
    }, $input['items']));
    $itemIds = array_values(array_unique($itemIds));

    $itemCostMap = [];
    if (count($itemIds) > 0) {
        $ph = implode(',', array_fill(0, count($itemIds), '?'));
        $icStmt = $pdo->prepare("SELECT id, weighted_avg_cost, last_purchase_price FROM items WHERE id IN ({$ph})");
        $icStmt->execute($itemIds);
        foreach ($icStmt->fetchAll() as $row) {
            $itemCostMap[(int) $row['id']] = $row;
        }
    }

    // Movement type
    $mvType = 'issue_other';
    if (in_array($serviceType, ['bar', 'kitchen', 'restaurant'])) $mvType = 'issue_kitchen';
    elseif (in_array($serviceType, ['rooms', 'housekeeping'])) $mvType = 'issue_rooms';
    elseif ($serviceType === 'staff') $mvType = 'issue_employee';

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO issue_vouchers (
                voucher_number, camp_id, issue_type, cost_center_id,
                issue_date, issued_by, received_by_name, department,
                room_numbers, guest_count, total_value, notes, status, created_at
            ) VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, 0, ?, 'confirmed', NOW())
        ")->execute([
            $voucherNumber, $campId, $issueType, (int) $costCenterId,
            $auth['user_id'], $receivedBy,
            $serviceType === 'bar' ? 'Bar' : ($serviceType === 'restaurant' ? 'Restaurant' : ucfirst($serviceType)),
            $roomNumbers, $guestCount ? (int) $guestCount : null,
            $notes ? ($tableNumber ? "Table {$tableNumber}. " : '') . $notes : ($tableNumber ? "Table {$tableNumber}" : null),
        ]);

        $voucherId = (int) $pdo->lastInsertId();
        $totalValue = 0;

        $lineStmt = $pdo->prepare("
            INSERT INTO issue_voucher_lines (voucher_id, item_id, quantity, unit_cost, total_value, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $deductStmt = $pdo->prepare("
            UPDATE stock_balances
            SET current_qty = GREATEST(0, current_qty - ?),
                current_value = GREATEST(0, current_value - ?),
                last_issue_date = CURDATE(),
                updated_at = NOW()
            WHERE camp_id = ? AND item_id = ?
        ");

        $balStmt = $pdo->prepare("SELECT current_qty FROM stock_balances WHERE camp_id = ? AND item_id = ?");

        $mvStmt = $pdo->prepare("
            INSERT INTO stock_movements (item_id, camp_id, movement_type, direction, quantity, unit_cost, total_value,
                balance_after, reference_type, reference_id, cost_center_id, created_by, movement_date, created_at)
            VALUES (?, ?, ?, 'out', ?, ?, ?, ?, 'issue_voucher', ?, ?, ?, CURDATE(), NOW())
        ");

        foreach ($input['items'] as $item) {
            if (empty($item['id']) || empty($item['qty']) || $item['qty'] <= 0) continue;

            $itemId = (int) $item['id'];
            $qty = (float) $item['qty'];

            $itemData = $itemCostMap[$itemId] ?? null;
            $unitCost = $itemData ? ((float) ($itemData['weighted_avg_cost'] ?: $itemData['last_purchase_price'])) : 0;
            $lineValue = $qty * $unitCost;
            $totalValue += $lineValue;

            $lineStmt->execute([$voucherId, $itemId, $qty, $unitCost, $lineValue, $item['notes'] ?? null]);
            $deductStmt->execute([$qty, $lineValue, $campId, $itemId]);

            $balStmt->execute([$campId, $itemId]);
            $balAfter = (float) ($balStmt->fetchColumn() ?: 0);

            $mvStmt->execute([$itemId, $campId, $mvType, $qty, $unitCost, $lineValue, $balAfter,
                         $voucherId, (int) $costCenterId, $auth['user_id']]);
        }

        // Update total
        $pdo->prepare("UPDATE issue_vouchers SET total_value = ? WHERE id = ?")->execute([$totalValue, $voucherId]);

        $pdo->commit();

        jsonResponse([
            'message' => 'POS transaction completed',
            'transaction' => [
                'id' => $voucherId,
                'voucher_number' => $voucherNumber,
                'total_value' => $totalValue,
                'items_count' => count($input['items']),
                'service_type' => $serviceType,
            ],
        ], 201);

    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('POS transaction failed: ' . $e->getMessage(), 500);
    }
    exit;
}

requireMethod('GET');
