<?php
/**
 * KCL Stores — Daily Overview
 * GET /api/daily-overview.php?date=2026-02-12&camp_id=1
 * Returns items with columns: Stock, Ordered, Received, Bar Stock, Kitchen Stock for a given date
 */

require_once __DIR__ . '/middleware.php';
requireMethod('GET');
$auth = requireAuth();

$pdo = getDB();

// Params
$date = $_GET['date'] ?? date('Y-m-d');
$campId = (int) ($_GET['camp_id'] ?? $auth['camp_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

// Validate date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    jsonError('Invalid date format. Use YYYY-MM-DD');
}

// ── Build main items list with stock, orders, receipts, bar, kitchen ──

$where = [];
$params = [];

if ($search) {
    $where[] = '(i.item_code LIKE ? OR i.name LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = $where ? 'AND ' . implode(' AND ', $where) : '';

// Get all active items
$itemsStmt = $pdo->prepare("
    SELECT i.id, i.item_code, i.name, i.stock_uom,
           ig.code as group_code, ig.name as group_name
    FROM items i
    LEFT JOIN item_groups ig ON i.item_group_id = ig.id
    WHERE i.is_active = 1
    {$whereClause}
    ORDER BY ig.code, i.name
");
$itemsStmt->execute($params);
$items = $itemsStmt->fetchAll();

$itemIds = array_column($items, 'id');
if (empty($itemIds)) {
    jsonResponse(['items' => [], 'date' => $date, 'camp_name' => '']);
}

$placeholders = implode(',', array_fill(0, count($itemIds), '?'));

// ── Current Stock (from stock_balances) ──
$stockMap = [];
if ($campId) {
    $stockStmt = $pdo->prepare("
        SELECT item_id, current_qty, stock_status
        FROM stock_balances
        WHERE camp_id = ? AND item_id IN ({$placeholders})
    ");
    $stockStmt->execute(array_merge([$campId], $itemIds));
    foreach ($stockStmt->fetchAll() as $row) {
        $stockMap[$row['item_id']] = $row;
    }
}

// ── Ordered quantities on the date ──
$orderedMap = [];
$ordParams = [$date, $date];
if ($campId) {
    $campFilter = 'AND o.camp_id = ?';
    $ordParams[] = $campId;
} else {
    $campFilter = '';
}
$ordStmt = $pdo->prepare("
    SELECT ol.item_id, SUM(ol.requested_qty) as ordered_qty
    FROM order_lines ol
    JOIN orders o ON ol.order_id = o.id
    WHERE DATE(o.created_at) >= ? AND DATE(o.created_at) <= ?
    {$campFilter}
    AND ol.item_id IN ({$placeholders})
    GROUP BY ol.item_id
");
$ordStmt->execute(array_merge($ordParams, $itemIds));
foreach ($ordStmt->fetchAll() as $row) {
    $orderedMap[$row['item_id']] = (float) $row['ordered_qty'];
}

// ── Received quantities on the date ──
$receivedMap = [];
$recParams = [$date, $date];
if ($campId) {
    $campFilterR = 'AND r.camp_id = ?';
    $recParams[] = $campId;
} else {
    $campFilterR = '';
}
$recStmt = $pdo->prepare("
    SELECT rl.item_id, SUM(rl.received_qty) as received_qty
    FROM receipt_lines rl
    JOIN receipts r ON rl.receipt_id = r.id
    WHERE DATE(r.created_at) >= ? AND DATE(r.created_at) <= ?
    {$campFilterR}
    AND rl.item_id IN ({$placeholders})
    GROUP BY rl.item_id
");
$recStmt->execute(array_merge($recParams, $itemIds));
foreach ($recStmt->fetchAll() as $row) {
    $receivedMap[$row['item_id']] = (float) $row['received_qty'];
}

// ── Issue quantities by type on the date (bar + kitchen) ──
$barIssuedMap = [];
$kitchenIssuedMap = [];
$issParams = [$date, $date];
if ($campId) {
    $campFilterI = 'AND iv.camp_id = ?';
    $issParams[] = $campId;
} else {
    $campFilterI = '';
}
$issStmt = $pdo->prepare("
    SELECT il.item_id, iv.issue_type, SUM(il.qty) as issued_qty
    FROM issue_lines il
    JOIN issue_vouchers iv ON il.issue_voucher_id = iv.id
    WHERE DATE(iv.created_at) >= ? AND DATE(iv.created_at) <= ?
    {$campFilterI}
    AND il.item_id IN ({$placeholders})
    GROUP BY il.item_id, iv.issue_type
");
$issStmt->execute(array_merge($issParams, $itemIds));
foreach ($issStmt->fetchAll() as $row) {
    if ($row['issue_type'] === 'bar') {
        $barIssuedMap[$row['item_id']] = (float) $row['issued_qty'];
    } elseif ($row['issue_type'] === 'kitchen') {
        $kitchenIssuedMap[$row['item_id']] = (float) $row['issued_qty'];
    }
}

// ── Combine results ──
$result = [];
foreach ($items as $item) {
    $id = $item['id'];
    $stockQty = $stockMap[$id]['current_qty'] ?? 0;
    $ordered = $orderedMap[$id] ?? 0;
    $received = $receivedMap[$id] ?? 0;
    $barIssued = $barIssuedMap[$id] ?? 0;
    $kitchenIssued = $kitchenIssuedMap[$id] ?? 0;

    // Only include items that have any activity or stock
    if ($stockQty > 0 || $ordered > 0 || $received > 0 || $barIssued > 0 || $kitchenIssued > 0) {
        $result[] = [
            'id' => (int) $id,
            'item_code' => $item['item_code'],
            'name' => $item['name'],
            'uom' => $item['stock_uom'],
            'group_code' => $item['group_code'],
            'group_name' => $item['group_name'],
            'stock' => (float) $stockQty,
            'stock_status' => $stockMap[$id]['stock_status'] ?? 'unknown',
            'ordered' => (float) $ordered,
            'received' => (float) $received,
            'bar_issued' => (float) $barIssued,
            'kitchen_issued' => (float) $kitchenIssued,
        ];
    }
}

// Get camp name
$campName = '';
if ($campId) {
    $campStmt = $pdo->prepare('SELECT name FROM camps WHERE id = ?');
    $campStmt->execute([$campId]);
    $campName = $campStmt->fetchColumn() ?: '';
}

jsonResponse([
    'items' => $result,
    'date' => $date,
    'camp_id' => $campId,
    'camp_name' => $campName,
    'total_items' => count($result),
]);
