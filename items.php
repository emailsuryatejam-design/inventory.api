<?php
/**
 * KCL Stores — Items List
 * GET /api/items.php?page=1&per_page=25&search=&group=&abc_class=&storage_type=&active=1
 */

require_once __DIR__ . '/middleware.php';
requireMethod('GET');
requireAuth();

$pdo = getDB();

// Pagination
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(10, (int) ($_GET['per_page'] ?? 25)));
$offset = ($page - 1) * $perPage;

// Filters
$search = trim($_GET['search'] ?? '');
$groupId = $_GET['group'] ?? '';
$abcClass = $_GET['abc_class'] ?? '';
$storageType = $_GET['storage_type'] ?? '';
$active = $_GET['active'] ?? '1';

// Build query
$where = [];
$params = [];

if ($active !== 'all') {
    $where[] = 'i.is_active = ?';
    $params[] = (int) $active;
}

if ($search) {
    $where[] = '(i.item_code LIKE ? OR i.name LIKE ? OR i.sap_item_no LIKE ?)';
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if ($groupId) {
    $where[] = 'i.item_group_id = ?';
    $params[] = (int) $groupId;
}

if ($abcClass && in_array($abcClass, ['A', 'B', 'C'])) {
    $where[] = 'i.abc_class = ?';
    $params[] = $abcClass;
}

if ($storageType && in_array($storageType, ['ambient', 'chilled', 'frozen', 'hazardous'])) {
    $where[] = 'i.storage_type = ?';
    $params[] = $storageType;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM items i {$whereClause}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

// Items query
$sql = "
    SELECT
        i.id, i.item_code, i.sap_item_no, i.name, i.description,
        i.abc_class, i.storage_type, i.is_active, i.is_perishable, i.is_critical,
        i.last_purchase_price, i.weighted_avg_cost,
        i.shelf_life_days, i.haccp_category,
        g.code as group_code, g.name as group_name,
        s.code as sub_cat_code, s.name as sub_cat_name,
        u.code as stock_uom_code, u.name as stock_uom_name,
        pu.code as purchase_uom_code,
        iu.code as issue_uom_code,
        i.purchase_to_stock_factor, i.stock_to_issue_factor,
        i.min_order_qty, i.standard_pack_size
    FROM items i
    LEFT JOIN item_groups g ON i.item_group_id = g.id
    LEFT JOIN item_sub_categories s ON i.sub_category_id = s.id
    LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
    LEFT JOIN units_of_measure pu ON i.purchase_uom_id = pu.id
    LEFT JOIN units_of_measure iu ON i.issue_uom_id = iu.id
    {$whereClause}
    ORDER BY i.item_code ASC
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Load item groups for filter dropdown
$groups = $pdo->query('SELECT id, code, name FROM item_groups ORDER BY name')->fetchAll();

jsonResponse([
    'items' => array_map(function($i) {
        return [
            'id' => (int) $i['id'],
            'item_code' => $i['item_code'],
            'sap_item_no' => $i['sap_item_no'],
            'name' => $i['name'],
            'description' => $i['description'],
            'group_code' => $i['group_code'],
            'group_name' => $i['group_name'],
            'sub_cat_code' => $i['sub_cat_code'],
            'sub_cat_name' => $i['sub_cat_name'],
            'stock_uom' => $i['stock_uom_code'],
            'purchase_uom' => $i['purchase_uom_code'],
            'issue_uom' => $i['issue_uom_code'],
            'purchase_to_stock_factor' => $i['purchase_to_stock_factor'] ? (float) $i['purchase_to_stock_factor'] : 1,
            'stock_to_issue_factor' => $i['stock_to_issue_factor'] ? (float) $i['stock_to_issue_factor'] : 1,
            'abc_class' => $i['abc_class'],
            'storage_type' => $i['storage_type'],
            'is_active' => (bool) $i['is_active'],
            'is_perishable' => (bool) $i['is_perishable'],
            'is_critical' => (bool) $i['is_critical'],
            'last_purchase_price' => $i['last_purchase_price'] ? (float) $i['last_purchase_price'] : null,
            'weighted_avg_cost' => $i['weighted_avg_cost'] ? (float) $i['weighted_avg_cost'] : null,
            'shelf_life_days' => $i['shelf_life_days'] ? (int) $i['shelf_life_days'] : null,
            'haccp_category' => $i['haccp_category'],
            'min_order_qty' => $i['min_order_qty'] ? (float) $i['min_order_qty'] : null,
            'standard_pack_size' => $i['standard_pack_size'] ? (float) $i['standard_pack_size'] : null,
        ];
    }, $items),
    'groups' => array_map(function($g) {
        return ['id' => (int) $g['id'], 'code' => $g['code'], 'name' => $g['name']];
    }, $groups),
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => ceil($total / $perPage),
    ],
]);
