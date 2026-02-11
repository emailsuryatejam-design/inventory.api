<?php
/**
 * KCL Stores — Item Detail
 * GET /api/items-detail.php?id=123
 */

require_once __DIR__ . '/middleware.php';
requireMethod('GET');
requireAuth();

$pdo = getDB();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    jsonError('Item ID required', 400);
}

// Full item detail
$stmt = $pdo->prepare("
    SELECT
        i.*,
        g.code as group_code, g.name as group_name,
        s.code as sub_cat_code, s.name as sub_cat_name,
        u.code as stock_uom_code, u.name as stock_uom_name,
        pu.code as purchase_uom_code, pu.name as purchase_uom_name,
        iu.code as issue_uom_code, iu.name as issue_uom_name,
        cc.code as cost_center_code, cc.name as cost_center_name
    FROM items i
    LEFT JOIN item_groups g ON i.item_group_id = g.id
    LEFT JOIN item_sub_categories s ON i.sub_category_id = s.id
    LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
    LEFT JOIN units_of_measure pu ON i.purchase_uom_id = pu.id
    LEFT JOIN units_of_measure iu ON i.issue_uom_id = iu.id
    LEFT JOIN cost_centers cc ON i.default_cost_center_id = cc.id
    WHERE i.id = ?
");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    jsonError('Item not found', 404);
}

// Stock balances across all camps
$stockStmt = $pdo->prepare("
    SELECT
        sb.camp_id, c.code as camp_code, c.name as camp_name,
        sb.current_qty, sb.current_value, sb.unit_cost,
        sb.par_level, sb.min_level, sb.max_level, sb.safety_stock,
        sb.avg_daily_usage, sb.days_stock_on_hand, sb.stock_status,
        sb.last_receipt_date, sb.last_issue_date, sb.days_since_last_movement
    FROM stock_balances sb
    JOIN camps c ON sb.camp_id = c.id
    WHERE sb.item_id = ?
    ORDER BY c.name
");
$stockStmt->execute([$id]);
$stockBalances = $stockStmt->fetchAll();

// Suppliers
$supplierStmt = $pdo->prepare("
    SELECT
        iss.supplier_id, sup.name as supplier_name,
        iss.unit_price, iss.lead_time_days, iss.is_preferred
    FROM item_suppliers iss
    JOIN suppliers sup ON iss.supplier_id = sup.id
    WHERE iss.item_id = ?
    ORDER BY iss.is_preferred DESC, sup.name
");
$supplierStmt->execute([$id]);
$suppliers = $supplierStmt->fetchAll();

// Recent stock movements (last 20)
$movementStmt = $pdo->prepare("
    SELECT
        sm.id, sm.movement_type, sm.quantity, sm.unit_cost, sm.total_value,
        sm.reference_type, sm.reference_id,
        sm.camp_id, c.code as camp_code,
        sm.movement_date, sm.created_at,
        u.name as created_by_name
    FROM stock_movements sm
    JOIN camps c ON sm.camp_id = c.id
    LEFT JOIN users u ON sm.created_by = u.id
    WHERE sm.item_id = ?
    ORDER BY sm.created_at DESC
    LIMIT 20
");
$movementStmt->execute([$id]);
$movements = $movementStmt->fetchAll();

jsonResponse([
    'item' => [
        'id' => (int) $item['id'],
        'item_code' => $item['item_code'],
        'sap_item_no' => $item['sap_item_no'],
        'name' => $item['name'],
        'description' => $item['description'],
        'barcode' => $item['barcode'] ?? null,
        'manufacturer' => $item['manufacturer'] ?? null,
        'group_code' => $item['group_code'],
        'group_name' => $item['group_name'],
        'sub_cat_code' => $item['sub_cat_code'],
        'sub_cat_name' => $item['sub_cat_name'],
        'cost_center_code' => $item['cost_center_code'],
        'cost_center_name' => $item['cost_center_name'],
        'abc_class' => $item['abc_class'],
        'storage_type' => $item['storage_type'],
        'is_active' => (bool) $item['is_active'],
        'is_perishable' => (bool) $item['is_perishable'],
        'is_critical' => (bool) $item['is_critical'],
        'stock_uom' => $item['stock_uom_code'],
        'stock_uom_name' => $item['stock_uom_name'],
        'purchase_uom' => $item['purchase_uom_code'],
        'purchase_uom_name' => $item['purchase_uom_name'],
        'issue_uom' => $item['issue_uom_code'],
        'issue_uom_name' => $item['issue_uom_name'],
        'purchase_to_stock_factor' => $item['purchase_to_stock_factor'] ? (float) $item['purchase_to_stock_factor'] : 1,
        'stock_to_issue_factor' => $item['stock_to_issue_factor'] ? (float) $item['stock_to_issue_factor'] : 1,
        'standard_pack_size' => $item['standard_pack_size'] ? (float) $item['standard_pack_size'] : null,
        'last_purchase_price' => $item['last_purchase_price'] ? (float) $item['last_purchase_price'] : null,
        'weighted_avg_cost' => $item['weighted_avg_cost'] ? (float) $item['weighted_avg_cost'] : null,
        'last_eval_price' => $item['last_eval_price'] ? (float) $item['last_eval_price'] : null,
        'shelf_life_days' => $item['shelf_life_days'] ? (int) $item['shelf_life_days'] : null,
        'shelf_life_after_opening_days' => $item['shelf_life_after_opening_days'] ? (int) $item['shelf_life_after_opening_days'] : null,
        'haccp_category' => $item['haccp_category'],
        'allergen_info' => $item['allergen_info'] ?? null,
        'storage_temp_min' => $item['storage_temp_min'] ? (float) $item['storage_temp_min'] : null,
        'storage_temp_max' => $item['storage_temp_max'] ? (float) $item['storage_temp_max'] : null,
        'min_order_qty' => $item['min_order_qty'] ? (float) $item['min_order_qty'] : null,
        'yield_percentage' => $item['yield_percentage'] ? (float) $item['yield_percentage'] : null,
        'in_stock_total_at_import' => $item['in_stock_total_at_import'] ? (float) $item['in_stock_total_at_import'] : null,
    ],
    'stock_balances' => array_map(function($sb) {
        return [
            'camp_id' => (int) $sb['camp_id'],
            'camp_code' => $sb['camp_code'],
            'camp_name' => $sb['camp_name'],
            'current_qty' => (float) $sb['current_qty'],
            'current_value' => (float) $sb['current_value'],
            'unit_cost' => (float) $sb['unit_cost'],
            'par_level' => $sb['par_level'] ? (float) $sb['par_level'] : null,
            'min_level' => $sb['min_level'] ? (float) $sb['min_level'] : null,
            'max_level' => $sb['max_level'] ? (float) $sb['max_level'] : null,
            'safety_stock' => $sb['safety_stock'] ? (float) $sb['safety_stock'] : null,
            'avg_daily_usage' => $sb['avg_daily_usage'] ? (float) $sb['avg_daily_usage'] : null,
            'days_stock_on_hand' => $sb['days_stock_on_hand'] ? (float) $sb['days_stock_on_hand'] : null,
            'stock_status' => $sb['stock_status'],
            'last_receipt_date' => $sb['last_receipt_date'],
            'last_issue_date' => $sb['last_issue_date'],
            'days_since_last_movement' => $sb['days_since_last_movement'] ? (int) $sb['days_since_last_movement'] : null,
        ];
    }, $stockBalances),
    'suppliers' => array_map(function($s) {
        return [
            'supplier_id' => (int) $s['supplier_id'],
            'supplier_name' => $s['supplier_name'],
            'unit_price' => $s['unit_price'] ? (float) $s['unit_price'] : null,
            'lead_time_days' => $s['lead_time_days'] ? (int) $s['lead_time_days'] : null,
            'is_preferred' => (bool) $s['is_preferred'],
        ];
    }, $suppliers),
    'recent_movements' => array_map(function($m) {
        return [
            'id' => (int) $m['id'],
            'type' => $m['movement_type'],
            'quantity' => (float) $m['quantity'],
            'unit_cost' => (float) ($m['unit_cost'] ?? 0),
            'total_value' => (float) ($m['total_value'] ?? 0),
            'reference_type' => $m['reference_type'],
            'camp_code' => $m['camp_code'],
            'created_by' => $m['created_by_name'],
            'created_at' => $m['created_at'],
        ];
    }, $movements),
]);
