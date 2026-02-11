<?php
/**
 * KCL Stores — Issue Vouchers
 * GET  /api/issue.php — list issue vouchers
 * POST /api/issue.php — create issue voucher
 */

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/helpers.php';

$auth = requireAuth();
$pdo = getDB();

// ── GET — List ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;
    $type = $_GET['type'] ?? '';
    $campId = $_GET['camp_id'] ?? '';

    $where = [];
    $params = [];

    if (in_array($auth['role'], ['camp_storekeeper', 'camp_manager']) && $auth['camp_id']) {
        $where[] = 'iv.camp_id = ?';
        $params[] = $auth['camp_id'];
    } elseif ($campId) {
        $where[] = 'iv.camp_id = ?';
        $params[] = (int) $campId;
    }

    if ($type) {
        $where[] = 'iv.issue_type = ?';
        $params[] = $type;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM issue_vouchers iv {$whereClause}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT iv.*, c.code as camp_code, c.name as camp_name,
               cc.name as cost_center_name,
               u.name as issued_by_name
        FROM issue_vouchers iv
        JOIN camps c ON iv.camp_id = c.id
        LEFT JOIN cost_centers cc ON iv.cost_center_id = cc.id
        LEFT JOIN users u ON iv.issued_by = u.id
        {$whereClause}
        ORDER BY iv.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $vouchers = $stmt->fetchAll();

    // Cost centers for dropdown
    $costCenters = $pdo->query('SELECT id, code, name FROM cost_centers ORDER BY name')->fetchAll();

    jsonResponse([
        'vouchers' => array_map(function($v) {
            return [
                'id' => (int) $v['id'],
                'voucher_number' => $v['voucher_number'],
                'camp_code' => $v['camp_code'],
                'camp_name' => $v['camp_name'],
                'issue_type' => $v['issue_type'],
                'cost_center' => $v['cost_center_name'],
                'issue_date' => $v['issue_date'],
                'issued_by' => $v['issued_by_name'],
                'received_by_name' => $v['received_by_name'],
                'total_value' => (float) $v['total_value'],
                'status' => $v['status'],
                'notes' => $v['notes'],
                'created_at' => $v['created_at'],
            ];
        }, $vouchers),
        'cost_centers' => array_map(function($cc) {
            return ['id' => (int) $cc['id'], 'code' => $cc['code'], 'name' => $cc['name']];
        }, $costCenters),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => ceil($total / $perPage),
        ],
    ]);
    exit;
}

// ── POST — Create Issue Voucher ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = getJsonInput();
    requireFields($input, ['issue_type', 'cost_center_id', 'received_by_name', 'lines']);

    if (!is_array($input['lines']) || count($input['lines']) === 0) {
        jsonError('At least one item line is required', 400);
    }

    $campId = $auth['camp_id'];
    if (!$campId) jsonError('Must be assigned to a camp', 400);

    $campCode = $pdo->query("SELECT code FROM camps WHERE id = {$campId}")->fetchColumn();
    $voucherNumber = generateDocNumber($pdo, 'ISS', $campCode);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO issue_vouchers (
                voucher_number, camp_id, issue_type, cost_center_id,
                issue_date, issued_by, received_by_name, department,
                room_numbers, guest_count, total_value, notes, status, created_at
            ) VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, 0, ?, 'confirmed', NOW())
        ")->execute([
            $voucherNumber, $campId, $input['issue_type'], (int) $input['cost_center_id'],
            $auth['user_id'], $input['received_by_name'], $input['department'] ?? null,
            $input['room_numbers'] ?? null, $input['guest_count'] ?? null,
            $input['notes'] ?? null,
        ]);

        $voucherId = (int) $pdo->lastInsertId();
        $totalValue = 0;

        $lineStmt = $pdo->prepare("
            INSERT INTO issue_voucher_lines (voucher_id, item_id, quantity, unit_cost, total_value, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($input['lines'] as $line) {
            if (empty($line['item_id']) || empty($line['qty']) || $line['qty'] <= 0) continue;

            $itemId = (int) $line['item_id'];
            $qty = (float) $line['qty'];

            // Get item cost
            $item = $pdo->query("SELECT weighted_avg_cost, last_purchase_price FROM items WHERE id = {$itemId}")->fetch();
            $unitCost = $item ? ((float) ($item['weighted_avg_cost'] ?: $item['last_purchase_price'])) : 0;
            $lineValue = $qty * $unitCost;
            $totalValue += $lineValue;

            $lineStmt->execute([
                $voucherId, $itemId, $qty, $unitCost, $lineValue, $line['notes'] ?? null,
            ]);

            // Deduct from stock
            $pdo->prepare("
                UPDATE stock_balances
                SET current_qty = GREATEST(0, current_qty - ?),
                    current_value = GREATEST(0, current_value - ?),
                    last_issue_date = CURDATE(),
                    updated_at = NOW()
                WHERE camp_id = ? AND item_id = ?
            ")->execute([$qty, $lineValue, $campId, $itemId]);

            // Create stock movement
            $pdo->prepare("
                INSERT INTO stock_movements (item_id, camp_id, movement_type, quantity, unit_cost, total_value,
                    reference_type, reference_id, reference_number, created_by, created_at)
                VALUES (?, ?, 'issue', ?, ?, ?, 'issue_voucher', ?, ?, ?, NOW())
            ")->execute([$itemId, $campId, $qty, $unitCost, $lineValue, $voucherId, $voucherNumber, $auth['user_id']]);
        }

        // Update total
        $pdo->prepare("UPDATE issue_vouchers SET total_value = ? WHERE id = ?")->execute([$totalValue, $voucherId]);

        $pdo->commit();

        jsonResponse([
            'message' => 'Issue voucher created',
            'voucher' => [
                'id' => $voucherId,
                'voucher_number' => $voucherNumber,
                'total_value' => $totalValue,
            ],
        ], 201);

    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Failed to create issue voucher: ' . $e->getMessage(), 500);
    }
    exit;
}

requireMethod('GET');
