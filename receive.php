<?php
/**
 * KCL Stores — Receipts
 * GET /api/receive.php — list receipts
 */

require_once __DIR__ . '/middleware.php';
requireMethod('GET');
$auth = requireAuth();

$pdo = getDB();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $perPage;
$status = $_GET['status'] ?? '';
$campId = $_GET['camp_id'] ?? '';

$where = [];
$params = [];

if (in_array($auth['role'], ['camp_storekeeper', 'camp_manager']) && $auth['camp_id']) {
    $where[] = 'r.camp_id = ?';
    $params[] = $auth['camp_id'];
} elseif ($campId) {
    $where[] = 'r.camp_id = ?';
    $params[] = (int) $campId;
}

if ($status) {
    $where[] = 'r.status = ?';
    $params[] = $status;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM receipts r {$whereClause}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT r.*, c.code as camp_code, c.name as camp_name,
           d.dispatch_number, u.name as received_by_name
    FROM receipts r
    JOIN camps c ON r.camp_id = c.id
    LEFT JOIN dispatches d ON r.dispatch_id = d.id
    LEFT JOIN users u ON r.received_by = u.id
    {$whereClause}
    ORDER BY r.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$receipts = $stmt->fetchAll();

jsonResponse([
    'receipts' => array_map(function($r) {
        return [
            'id' => (int) $r['id'],
            'receipt_number' => $r['receipt_number'],
            'dispatch_number' => $r['dispatch_number'],
            'camp_code' => $r['camp_code'],
            'camp_name' => $r['camp_name'],
            'received_by' => $r['received_by_name'],
            'received_date' => $r['received_date'],
            'status' => $r['status'],
            'notes' => $r['notes'],
            'created_at' => $r['created_at'],
        ];
    }, $receipts),
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => ceil($total / $perPage),
    ],
]);
