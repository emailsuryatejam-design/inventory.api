<?php
/**
 * WebSquare — Demo Data Manager
 * POST /api/seed-demo.php
 * Body: { "action": "status"|"seed"|"delete", "sections": ["foundation","items","hr","operations","kitchen"] }
 * Admin only. Seeds/deletes demo data into the current tenant.
 */

set_time_limit(300);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('POST required', 405);
}

$auth = requireAdmin();
$tenantId = requireTenant($auth);
$pdo = getDB();
$input = getJsonInput();

$action = $input['action'] ?? 'status';
$sections = $input['sections'] ?? ['foundation', 'items', 'hr', 'operations', 'kitchen'];
$startTime = microtime(true);

try {
    switch ($action) {
        case 'status':
            jsonResponse(getStatus($pdo, $tenantId));
            break;
        case 'seed':
            $result = doSeed($pdo, $tenantId, $sections, (int)$auth['user_id']);
            $result['elapsed_seconds'] = round(microtime(true) - $startTime, 2);
            jsonResponse($result);
            break;
        case 'delete':
            $result = doDelete($pdo, $tenantId, $sections);
            $result['elapsed_seconds'] = round(microtime(true) - $startTime, 2);
            jsonResponse($result);
            break;
        default:
            jsonError('Unknown action. Use: status, seed, delete', 400);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
    exit;
}

// ═══════════════════════════════════════════════════
// STATUS
// ═══════════════════════════════════════════════════
function getStatus(PDO $pdo, int $tid): array {
    $count = function($table) use ($pdo, $tid) {
        try {
            $s = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE tenant_id = ?");
            $s->execute([$tid]);
            return (int)$s->fetchColumn();
        } catch (Exception $e) { return 0; }
    };

    $camps = $count('camps');
    $users = $count('users');
    $suppliers = $count('suppliers');
    $items = $count('items');
    $employees = $count('hr_employees');
    $payrollRuns = $count('payroll_runs');
    $orders = $count('orders');
    $dispatches = $count('dispatches');
    $recipes = $count('kitchen_recipes');
    $menus = $count('menu_plans');

    return [
        'sections' => [
            'foundation' => ['seeded' => $suppliers > 10, 'camps' => $camps, 'users' => $users, 'suppliers' => $suppliers],
            'items' => ['seeded' => $items > 100, 'count' => $items],
            'hr' => ['seeded' => $employees > 10, 'employees' => $employees, 'payroll_runs' => $payrollRuns],
            'operations' => ['seeded' => $orders > 10, 'orders' => $orders, 'dispatches' => $dispatches],
            'kitchen' => ['seeded' => $recipes > 10, 'recipes' => $recipes, 'menus' => $menus],
        ],
    ];
}

// ═══════════════════════════════════════════════════
// DELETE
// ═══════════════════════════════════════════════════
function doDelete(PDO $pdo, int $tid, array $sections): array {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $deleted = [];

    try {
        $del = function($table) use ($pdo, $tid) {
            $s = $pdo->prepare("DELETE FROM {$table} WHERE tenant_id = ?");
            $s->execute([$tid]);
            return $s->rowCount();
        };

        if (in_array('kitchen', $sections)) {
            $deleted['menu_ingredients'] = $del('menu_ingredients');
            $deleted['menu_dishes'] = $del('menu_dishes');
            $deleted['menu_plans'] = $del('menu_plans');
            $deleted['recipe_ingredients'] = $del('kitchen_recipe_ingredients');
            $deleted['recipes'] = $del('kitchen_recipes');
        }

        if (in_array('operations', $sections)) {
            $deleted['stock_movements'] = $del('stock_movements');
            $deleted['stock_balances'] = $del('stock_balances');
            $deleted['issue_lines'] = $del('issue_voucher_lines');
            $deleted['issue_vouchers'] = $del('issue_vouchers');
            $deleted['receipt_lines'] = $del('receipt_lines');
            $deleted['receipts'] = $del('receipts');
            $deleted['dispatch_lines'] = $del('dispatch_lines');
            $deleted['dispatches'] = $del('dispatches');
            $deleted['grn_lines'] = $del('grn_lines');
            $deleted['grns'] = $del('grns');
            $deleted['po_lines'] = $del('purchase_order_lines');
            $deleted['pos'] = $del('purchase_orders');
            $deleted['order_lines'] = $del('order_lines');
            $deleted['orders'] = $del('orders');
        }

        if (in_array('hr', $sections)) {
            $deleted['audit_log'] = $del('payroll_audit_log');
            $deleted['field_tracking'] = $del('field_tracking');
            $deleted['attendance'] = $del('attendance');
            $deleted['payroll_items'] = $del('payroll_items');
            $deleted['payroll_runs'] = $del('payroll_runs');
            $deleted['payroll_periods'] = $del('payroll_periods');
            $deleted['expense_claims'] = $del('expense_claims');
            $deleted['salary_advances'] = $del('hr_salary_advances');
            $deleted['loan_repayments'] = $del('loan_repayments');
            $deleted['loans'] = $del('hr_loans');
            $deleted['contracts'] = $del('contracts');
            $deleted['leave_requests'] = $del('leave_requests');
            $deleted['employee_allowances'] = $del('employee_allowances');
            $deleted['employees'] = $del('hr_employees');
            $deleted['allowance_types'] = $del('allowance_types');
            $deleted['deduction_types'] = $del('deduction_types');
            $deleted['leave_types'] = $del('leave_types');
            $deleted['shifts'] = $del('shifts');
            $deleted['regions'] = $del('hr_regions');
            $deleted['job_grades'] = $del('job_grades');
            $deleted['departments'] = $del('departments');
        }

        if (in_array('items', $sections)) {
            $deleted['item_suppliers'] = $del('item_suppliers');
            $deleted['items'] = $del('items');
        }

        if (in_array('foundation', $sections)) {
            $deleted['item_suppliers'] = ($deleted['item_suppliers'] ?? 0) + $del('item_suppliers');
            $deleted['suppliers'] = $del('suppliers');
            $deleted['sub_categories'] = $del('item_sub_categories');
            $deleted['item_groups'] = $del('item_groups');
            $deleted['uoms'] = $del('units_of_measure');
            $deleted['cost_centers'] = $del('cost_centers');
            $deleted['number_sequences'] = $del('number_sequences');
        }
    } finally {
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    }

    return ['success' => true, 'action' => 'delete', 'deleted' => $deleted];
}

// ═══════════════════════════════════════════════════
// SEED ORCHESTRATOR
// ═══════════════════════════════════════════════════
function doSeed(PDO $pdo, int $tid, array $sections, int $userId): array {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $stats = [];

    try {
        if (in_array('foundation', $sections)) {
            $stats['foundation'] = seedFoundation($pdo, $tid);
        }
        if (in_array('items', $sections)) {
            $stats['items'] = seedItems($pdo, $tid);
        }
        if (in_array('hr', $sections)) {
            $stats['hr'] = seedHR($pdo, $tid, $userId);
        }
        if (in_array('operations', $sections)) {
            $stats['operations'] = seedOperations($pdo, $tid, $userId);
        }
        if (in_array('kitchen', $sections)) {
            $stats['kitchen'] = seedKitchen($pdo, $tid);
        }
    } finally {
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    }

    return ['success' => true, 'action' => 'seed', 'stats' => $stats];
}

// ═══════════════════════════════════════════════════
// SEED: FOUNDATION
// ═══════════════════════════════════════════════════
function seedFoundation(PDO $pdo, int $tid): array {
    $stats = [];

    // -- Camps (skip HO which already exists) --
    $existingCamps = $pdo->prepare("SELECT code FROM camps WHERE tenant_id = ?");
    $existingCamps->execute([$tid]);
    $existingCodes = $existingCamps->fetchAll(PDO::FETCH_COLUMN);

    $newCamps = [
        ['TAR', 'Tarangire Wilderness Lodge', 'camp'],
        ['NGO', 'Ngorongoro Crater Lodge', 'camp'],
        ['SRN', 'Serengeti North Camp', 'camp'],
        ['SRS', 'Serengeti South Camp', 'camp'],
        ['SRW', 'Serengeti West Camp', 'camp'],
    ];

    $campStmt = $pdo->prepare("INSERT IGNORE INTO camps (tenant_id, code, name, type, is_active) VALUES (?,?,?,?,1)");
    $inserted = 0;
    foreach ($newCamps as $c) {
        if (!in_array($c[0], $existingCodes)) {
            $campStmt->execute([$tid, $c[0], $c[1], $c[2]]);
            $inserted++;
        }
    }
    $stats['camps'] = $inserted;

    // -- UOMs --
    $existUoms = $pdo->prepare("SELECT code FROM units_of_measure WHERE tenant_id = ?");
    $existUoms->execute([$tid]);
    $existUomCodes = $existUoms->fetchAll(PDO::FETCH_COLUMN);

    $uoms = [
        ['KG','Kilogram'],['G','Gram'],['L','Litre'],['ML','Millilitre'],
        ['PC','Piece'],['PK','Pack'],['BX','Box'],['BT','Bottle'],
        ['CN','Can'],['BG','Bag'],['RL','Roll'],['DZ','Dozen'],
        ['PR','Pair'],['ST','Set'],['CS','Case'],
    ];
    $uomStmt = $pdo->prepare("INSERT IGNORE INTO units_of_measure (tenant_id, code, name) VALUES (?,?,?)");
    $ins = 0;
    foreach ($uoms as $u) {
        if (!in_array($u[0], $existUomCodes)) { $uomStmt->execute([$tid, $u[0], $u[1]]); $ins++; }
    }
    $stats['uoms'] = $ins;

    // -- Item Groups --
    $existGroups = $pdo->prepare("SELECT code FROM item_groups WHERE tenant_id = ?");
    $existGroups->execute([$tid]);
    $existGroupCodes = $existGroups->fetchAll(PDO::FETCH_COLUMN);

    $groups = [
        ['FP','Fresh Produce'],['MP','Meat & Poultry'],['DE','Dairy & Eggs'],
        ['DG','Dry Goods & Grains'],['CP','Canned & Preserved'],['SC','Spices & Condiments'],
        ['BN','Non-Alcoholic Beverages'],['BA','Alcoholic Beverages'],['BP','Bakery & Pastry'],
        ['FF','Frozen Foods'],['CH','Cleaning & Housekeeping'],['GA','Guest Amenities'],
        ['OS','Office & Stationery'],['MH','Maintenance & Hardware'],['KE','Kitchen Equipment'],
        ['LT','Linen & Textiles'],['MF','Medical & First Aid'],['FE','Fuel & Energy'],
        ['SE','Safari Equipment'],['LN','Laundry'],['PK','Packaging'],['GL','Gardening'],
    ];
    $grpStmt = $pdo->prepare("INSERT IGNORE INTO item_groups (tenant_id, code, name) VALUES (?,?,?)");
    $ins = 0;
    foreach ($groups as $g) {
        if (!in_array($g[0], $existGroupCodes)) { $grpStmt->execute([$tid, $g[0], $g[1]]); $ins++; }
    }
    $stats['item_groups'] = $ins;

    // -- Sub-Categories --
    $existSub = $pdo->prepare("SELECT code FROM item_sub_categories WHERE tenant_id = ?");
    $existSub->execute([$tid]);
    $existSubCodes = $existSub->fetchAll(PDO::FETCH_COLUMN);

    // Get group IDs
    $grpIds = [];
    $gs = $pdo->prepare("SELECT id, code FROM item_groups WHERE tenant_id = ?");
    $gs->execute([$tid]);
    foreach ($gs->fetchAll() as $r) $grpIds[$r['code']] = (int)$r['id'];

    $subCats = [
        ['FP', [['VEG','Vegetables'],['FRT','Fruits'],['HRB','Herbs'],['SAL','Salad Greens']]],
        ['MP', [['BEF','Beef'],['CHK','Chicken'],['LMB','Lamb'],['PRK','Pork'],['GME','Game Meat']]],
        ['DE', [['MLK','Milk'],['CHS','Cheese'],['BTC','Butter & Cream'],['EGG','Eggs']]],
        ['DG', [['RCE','Rice'],['PST','Pasta'],['FLR','Flour'],['CRL','Cereals'],['PLS','Pulses']]],
        ['CP', [['CNV','Canned Vegetables'],['CNF','Canned Fruits'],['PKL','Pickles'],['JAM','Jams & Preserves']]],
        ['SC', [['SPC','Spices'],['SAU','Sauces'],['OIL','Oils'],['VIN','Vinegars'],['SSN','Seasonings']]],
        ['BN', [['SDR','Soft Drinks'],['JCE','Juices'],['WTR','Water'],['COF','Coffee'],['TEA','Tea']]],
        ['BA', [['WIN','Wine'],['BER','Beer'],['SPR','Spirits'],['LIQ','Liqueurs']]],
        ['BP', [['BKI','Baking Ingredients'],['DEC','Decorations']]],
        ['FF', [['FSF','Frozen Seafood'],['FVG','Frozen Vegetables'],['ICR','Ice Cream']]],
        ['CH', [['FLC','Floor Care'],['BTH','Bathroom'],['DIS','Disinfectants'],['WST','Waste Management']]],
        ['GA', [['TLT','Toiletries'],['TWL','Towels'],['RMS','Room Supplies']]],
        ['OS', [['PPR','Paper Products'],['PRT','Printing'],['DSK','Desk Supplies']]],
        ['MH', [['ELC','Electrical'],['PLB','Plumbing'],['TLS','Tools'],['PNT','Paint']]],
        ['KE', [['UTN','Utensils'],['CKW','Cookware'],['SMA','Small Appliances']]],
        ['LT', [['BED','Bedding'],['TBL','Table Linen'],['CRT','Curtains']]],
        ['MF', [['FAD','First Aid'],['MED','Medication']]],
        ['FE', [['DSL','Diesel'],['GAS','Gas'],['SLR','Solar']]],
        ['SE', [['OPT','Optics'],['LGT','Lighting'],['CMP','Camping'],['SFT','Safety']]],
        ['LN', [['DTG','Detergents'],['SFT2','Softeners'],['IRN','Ironing']]],
        ['PK', [['WRP','Wraps'],['CTN','Containers'],['BAG','Bags']]],
        ['GL', [['FRT2','Fertilizers'],['GTL','Garden Tools'],['IRG','Irrigation']]],
    ];

    $subStmt = $pdo->prepare("INSERT IGNORE INTO item_sub_categories (tenant_id, code, name, item_group_id) VALUES (?,?,?,?)");
    $ins = 0;
    foreach ($subCats as [$grpCode, $subs]) {
        $gid = $grpIds[$grpCode] ?? null;
        if (!$gid) continue;
        foreach ($subs as [$code, $name]) {
            if (!in_array($code, $existSubCodes)) {
                $subStmt->execute([$tid, $code, $name, $gid]);
                $ins++;
            }
        }
    }
    $stats['sub_categories'] = $ins;

    // -- Cost Centers --
    $existCC = $pdo->prepare("SELECT COUNT(*) FROM cost_centers WHERE tenant_id = ?");
    $existCC->execute([$tid]);
    if ((int)$existCC->fetchColumn() < 5) {
        $ccStmt = $pdo->prepare("INSERT IGNORE INTO cost_centers (tenant_id, code, name, is_active) VALUES (?,?,?,1)");
        $ccs = [['KIT','Kitchen'],['BAR','Bar'],['HSK','Housekeeping'],['MNT','Maintenance'],
                ['ADM','Administration'],['SPA','Spa & Wellness'],['SAF','Safari Operations'],['GEN','General']];
        foreach ($ccs as $cc) $ccStmt->execute([$tid, $cc[0], $cc[1]]);
        $stats['cost_centers'] = count($ccs);
    }

    // -- Suppliers --
    $existSup = $pdo->prepare("SELECT COUNT(*) FROM suppliers WHERE tenant_id = ?");
    $existSup->execute([$tid]);
    if ((int)$existSup->fetchColumn() < 10) {
        $supStmt = $pdo->prepare("INSERT IGNORE INTO suppliers (tenant_id, supplier_code, name, contact_person, email, phone, city, country, payment_terms, is_active, created_at) VALUES (?,?,?,?,?,?,?,?,?,1,NOW())");
        $suppliers = [
            ['SUP-001','Arusha Fresh Market','Juma Bakari','juma@arushafresh.co.tz','+255 754 100001','Arusha','Tanzania',7],
            ['SUP-002','Kilimanjaro Meats','Asha Mwenda','asha@kilimeats.co.tz','+255 754 100002','Arusha','Tanzania',14],
            ['SUP-003','Tanzania Dairy Ltd','Hassan Omar','hassan@tzdairy.co.tz','+255 754 100003','Dar es Salaam','Tanzania',30],
            ['SUP-004','Safari Beverages','Grace Kimaro','grace@safaribev.co.tz','+255 754 100004','Arusha','Tanzania',14],
            ['SUP-005','East Africa Wines','David Mushi','david@eawines.co.tz','+255 754 100005','Dar es Salaam','Tanzania',30],
            ['SUP-006','Clean Pro Supplies','Fatma Ali','fatma@cleanpro.co.tz','+255 754 100006','Arusha','Tanzania',14],
            ['SUP-007','Serengeti Hardware','Peter Massawe','peter@serhw.co.tz','+255 754 100007','Arusha','Tanzania',30],
            ['SUP-008','Ngorongoro Linen Co','Rose Mlay','rose@ngolinen.co.tz','+255 754 100008','Arusha','Tanzania',30],
            ['SUP-009','TZ Office Supplies','John Shirima','john@tzoffice.co.tz','+255 754 100009','Dar es Salaam','Tanzania',14],
            ['SUP-010','Fuel Tanzania','Amina Mhando','amina@fueltz.co.tz','+255 754 100010','Arusha','Tanzania',0],
            ['SUP-011','Spice World TZ','Khalid Juma','khalid@spicetz.co.tz','+255 754 100011','Zanzibar','Tanzania',7],
            ['SUP-012','Lake Zone Fisheries','Sarah Maganga','sarah@lzfish.co.tz','+255 754 100012','Mwanza','Tanzania',0],
            ['SUP-013','Bakery Essentials','Monica Tarimo','monica@bakeryess.co.tz','+255 754 100013','Arusha','Tanzania',14],
            ['SUP-014','Safari Gear Co','James Ngowi','james@safarigear.co.tz','+255 754 100014','Arusha','Tanzania',30],
            ['SUP-015','Green Gardens TZ','Esther Mwakasege','esther@greentz.co.tz','+255 754 100015','Arusha','Tanzania',7],
        ];
        foreach ($suppliers as $s) $supStmt->execute(array_merge([$tid], $s));
        $stats['suppliers'] = count($suppliers);
    }

    return $stats;
}

// ═══════════════════════════════════════════════════
// SEED: ITEMS (~1000)
// ═══════════════════════════════════════════════════
function seedItems(PDO $pdo, int $tid): array {
    // Check if already seeded
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE tenant_id = ?");
    $cnt->execute([$tid]);
    if ((int)$cnt->fetchColumn() > 100) return ['skipped' => true, 'reason' => 'Items already exist'];

    // Get group & sub-category IDs
    $grpIds = [];
    $gs = $pdo->prepare("SELECT id, code FROM item_groups WHERE tenant_id = ?");
    $gs->execute([$tid]);
    foreach ($gs->fetchAll() as $r) $grpIds[$r['code']] = (int)$r['id'];

    $subIds = [];
    $ss = $pdo->prepare("SELECT id, code FROM item_sub_categories WHERE tenant_id = ?");
    $ss->execute([$tid]);
    foreach ($ss->fetchAll() as $r) $subIds[$r['code']] = (int)$r['id'];

    // Get UOM IDs
    $uomIds = [];
    $us = $pdo->prepare("SELECT id, code FROM units_of_measure WHERE tenant_id = ?");
    $us->execute([$tid]);
    foreach ($us->fetchAll() as $r) $uomIds[$r['code']] = (int)$r['id'];

    $kg = $uomIds['KG'] ?? null; $g = $uomIds['G'] ?? null;
    $l = $uomIds['L'] ?? null; $ml = $uomIds['ML'] ?? null;
    $pc = $uomIds['PC'] ?? null; $pk = $uomIds['PK'] ?? null;
    $bx = $uomIds['BX'] ?? null; $bt = $uomIds['BT'] ?? null;
    $cn = $uomIds['CN'] ?? null; $bg = $uomIds['BG'] ?? null;
    $rl = $uomIds['RL'] ?? null; $dz = $uomIds['DZ'] ?? null;
    $pr = $uomIds['PR'] ?? null; $st = $uomIds['ST'] ?? null;
    $cs = $uomIds['CS'] ?? null;

    // Items: [name, group_code, sub_cat_code, uom_id, price, abc, storage, perishable, critical]
    $allItems = [];

    // Fresh Produce - Vegetables (~20)
    $vegs = ['Tomatoes','Red Onions','White Onions','Potatoes','Carrots','Cabbage','Spinach','Sukuma Wiki',
             'Green Peppers','Red Peppers','Cucumber','Zucchini','Broccoli','Cauliflower','Eggplant',
             'Sweet Potatoes','Cassava','Pumpkin','Mushrooms','Green Beans'];
    foreach ($vegs as $v) $allItems[] = [$v,'FP','VEG',$kg,rand(1500,8000),'B','chilled',1,0];

    // Fresh Produce - Fruits (~15)
    $fruits = ['Mangoes','Bananas','Pineapple','Watermelon','Papaya','Passion Fruit','Avocado',
               'Oranges','Lemons','Limes','Apples','Grapes','Strawberries','Coconut','Jackfruit'];
    foreach ($fruits as $f) $allItems[] = [$f,'FP','FRT',$kg,rand(2000,12000),'B','chilled',1,0];

    // Fresh Produce - Herbs (~10)
    $herbs = ['Fresh Basil','Fresh Rosemary','Fresh Thyme','Fresh Mint','Fresh Coriander',
              'Fresh Parsley','Lemongrass','Fresh Dill','Ginger Root','Garlic'];
    foreach ($herbs as $h) $allItems[] = [$h,'FP','HRB',$g,rand(3000,15000),'C','chilled',1,0];

    // Fresh Produce - Salad (~8)
    $salads = ['Lettuce Iceberg','Lettuce Romaine','Rocket Arugula','Baby Spinach','Mixed Salad Leaves',
               'Cherry Tomatoes','Radishes','Spring Onions'];
    foreach ($salads as $s) $allItems[] = [$s,'FP','SAL',$kg,rand(4000,12000),'C','chilled',1,0];

    // Meat & Poultry (~40)
    $meats = [
        ['Chicken Whole','CHK',$kg,8500],['Chicken Breast','CHK',$kg,12000],['Chicken Thighs','CHK',$kg,9000],
        ['Chicken Wings','CHK',$kg,7500],['Chicken Drumsticks','CHK',$kg,8000],
        ['Beef Fillet','BEF',$kg,28000],['Beef Sirloin','BEF',$kg,22000],['Beef Mince','BEF',$kg,15000],
        ['Beef Ribs','BEF',$kg,18000],['Beef T-Bone','BEF',$kg,25000],['Beef Oxtail','BEF',$kg,16000],
        ['Lamb Rack','LMB',$kg,35000],['Lamb Chops','LMB',$kg,30000],['Lamb Leg','LMB',$kg,28000],
        ['Lamb Mince','LMB',$kg,20000],
        ['Pork Chops','PRK',$kg,15000],['Pork Belly','PRK',$kg,14000],['Pork Sausages','PRK',$kg,12000],
        ['Bacon Rashers','PRK',$kg,18000],['Ham Sliced','PRK',$kg,20000],
        ['Ostrich Fillet','GME',$kg,35000],['Crocodile Fillet','GME',$kg,40000],['Guinea Fowl','GME',$pc,15000],
    ];
    foreach ($meats as $m) $allItems[] = [$m[0],'MP',$m[1],$m[2],$m[3],'A','frozen',1,1];

    // Dairy & Eggs (~25)
    $dairy = [
        ['Fresh Milk 1L','MLK',$l,2500],['UHT Milk 1L','MLK',$l,3000],['Cream Fresh 500ml','BTC',$ml,8000],
        ['Butter Unsalted 500g','BTC',$pc,7000],['Butter Salted 500g','BTC',$pc,7000],
        ['Cheddar Cheese','CHS',$kg,25000],['Mozzarella Cheese','CHS',$kg,22000],['Parmesan Cheese','CHS',$kg,45000],
        ['Cream Cheese','CHS',$kg,20000],['Feta Cheese','CHS',$kg,28000],['Gouda Cheese','CHS',$kg,24000],
        ['Eggs Large (Tray 30)','EGG',$pc,12000],['Eggs Medium (Tray 30)','EGG',$pc,10000],
        ['Yogurt Natural 500ml','MLK',$pc,4000],['Yogurt Strawberry 500ml','MLK',$pc,4500],
        ['Whipping Cream 1L','BTC',$l,12000],['Sour Cream 500ml','BTC',$pc,6000],
        ['Condensed Milk 397g','MLK',$cn,4500],['Evaporated Milk 410ml','MLK',$cn,3500],
    ];
    foreach ($dairy as $d) $allItems[] = [$d[0],'DE',$d[1],$d[2],$d[3],'B','chilled',1,0];

    // Dry Goods & Grains (~50)
    $dry = [
        ['Basmati Rice 5kg','RCE',$bg,18000],['Jasmine Rice 5kg','RCE',$bg,20000],['Brown Rice 5kg','RCE',$bg,16000],
        ['Pilau Rice 5kg','RCE',$bg,17000],['Arborio Rice 1kg','RCE',$kg,12000],
        ['Spaghetti 500g','PST',$pk,3500],['Penne 500g','PST',$pk,3500],['Fusilli 500g','PST',$pk,3500],
        ['Macaroni 500g','PST',$pk,3000],['Lasagne Sheets','PST',$pk,5000],['Egg Noodles 500g','PST',$pk,4000],
        ['Bread Flour 2kg','FLR',$bg,6000],['All Purpose Flour 2kg','FLR',$bg,5000],['Cake Flour 2kg','FLR',$bg,5500],
        ['Corn Flour 1kg','FLR',$kg,3500],['Semolina 1kg','FLR',$kg,4000],
        ['Cornflakes 500g','CRL',$bx,6000],['Oats 1kg','CRL',$pk,5500],['Muesli 500g','CRL',$pk,8000],
        ['Weetabix','CRL',$bx,7000],['Granola 500g','CRL',$pk,9000],
        ['Green Lentils 1kg','PLS',$kg,5000],['Red Lentils 1kg','PLS',$kg,5500],['Chickpeas Dried 1kg','PLS',$kg,4500],
        ['Kidney Beans 1kg','PLS',$kg,4000],['Black Beans 1kg','PLS',$kg,4500],
        ['Sugar White 1kg','FLR',$kg,3000],['Sugar Brown 1kg','FLR',$kg,3500],['Icing Sugar 500g','FLR',$pk,4000],
        ['Salt Table 1kg','FLR',$kg,1500],['Salt Sea Coarse 1kg','FLR',$kg,3000],
    ];
    foreach ($dry as $d) $allItems[] = [$d[0],'DG',$d[1],$d[2],$d[3],'B','ambient',0,0];

    // Canned & Preserved (~30)
    $canned = [
        ['Canned Tomatoes 400g','CNV',$cn,2500],['Tomato Paste 400g','CNV',$cn,3000],['Canned Corn 400g','CNV',$cn,3500],
        ['Canned Mushrooms 400g','CNV',$cn,4000],['Canned Green Beans','CNV',$cn,3000],
        ['Canned Pineapple 820g','CNF',$cn,5000],['Canned Peaches 820g','CNF',$cn,5500],['Canned Pears 820g','CNF',$cn,5500],
        ['Mixed Pickles 500g','PKL',$pc,6000],['Gherkins 370g','PKL',$pc,5000],['Jalapeños 330g','PKL',$pc,4500],
        ['Strawberry Jam 500g','JAM',$pc,6000],['Marmalade 500g','JAM',$pc,6500],['Honey Natural 500g','JAM',$pc,12000],
        ['Peanut Butter 500g','JAM',$pc,7000],['Nutella 400g','JAM',$pc,9000],
        ['Coconut Milk 400ml','CNV',$cn,3500],['Coconut Cream 400ml','CNV',$cn,4000],
        ['Baked Beans 400g','CNV',$cn,3000],['Tuna Canned 185g','CNF',$cn,5500],
    ];
    foreach ($canned as $c) $allItems[] = [$c[0],'CP',$c[1],$c[2],$c[3],'C','ambient',0,0];

    // Spices & Condiments (~60)
    $spices = [
        ['Black Pepper Ground 100g','SPC',$pc,5000],['White Pepper 100g','SPC',$pc,6000],['Cumin Ground 100g','SPC',$pc,4500],
        ['Coriander Ground 100g','SPC',$pc,4000],['Turmeric Powder 100g','SPC',$pc,4000],['Paprika 100g','SPC',$pc,5000],
        ['Chili Powder 100g','SPC',$pc,4500],['Cinnamon Ground 100g','SPC',$pc,6000],['Nutmeg Ground 50g','SPC',$pc,7000],
        ['Cardamom Whole 50g','SPC',$pc,12000],['Cloves Whole 50g','SPC',$pc,8000],['Star Anise 50g','SPC',$pc,9000],
        ['Bay Leaves 25g','SPC',$pc,4000],['Oregano Dried 50g','SPC',$pc,5000],['Thyme Dried 50g','SPC',$pc,5000],
        ['Pilau Masala 100g','SPC',$pc,5500],['Garam Masala 100g','SPC',$pc,6000],['Curry Powder 100g','SPC',$pc,4500],
        ['Soy Sauce 500ml','SAU',$bt,5000],['Worcestershire Sauce 300ml','SAU',$bt,6000],
        ['Tabasco Sauce 60ml','SAU',$bt,5500],['Sweet Chili Sauce 500ml','SAU',$bt,5000],
        ['Tomato Ketchup 500ml','SAU',$bt,4000],['Mustard Yellow 250g','SAU',$pc,4500],
        ['Mustard Dijon 250g','SAU',$pc,7000],['Mayonnaise 500g','SAU',$pc,5500],
        ['BBQ Sauce 500ml','SAU',$bt,5500],['Oyster Sauce 500ml','SAU',$bt,6000],
        ['Fish Sauce 500ml','SAU',$bt,5500],['Hot Sauce 150ml','SAU',$bt,4500],
        ['Balsamic Glaze 250ml','SAU',$bt,9000],['Peri Peri Sauce 250ml','SAU',$bt,6000],
        ['Olive Oil Extra Virgin 1L','OIL',$bt,18000],['Olive Oil Light 1L','OIL',$bt,15000],
        ['Vegetable Oil 5L','OIL',$bt,15000],['Sunflower Oil 5L','OIL',$bt,14000],
        ['Sesame Oil 250ml','OIL',$bt,8000],['Coconut Oil 500ml','OIL',$bt,10000],
        ['White Vinegar 1L','VIN',$bt,3000],['Apple Cider Vinegar 500ml','VIN',$bt,7000],
        ['Balsamic Vinegar 500ml','VIN',$bt,12000],['Red Wine Vinegar 500ml','VIN',$bt,6000],
        ['Chicken Stock Cubes 8s','SSN',$pk,3000],['Beef Stock Cubes 8s','SSN',$pk,3000],
        ['Vegetable Stock Cubes 8s','SSN',$pk,3000],['Vanilla Extract 100ml','SSN',$bt,8000],
    ];
    foreach ($spices as $s) $allItems[] = [$s[0],'SC',$s[1],$s[2],$s[3],'C','ambient',0,0];

    // Non-Alcoholic Beverages (~40)
    $drinks = [
        ['Coca-Cola 330ml','SDR',$cn,1200],['Fanta Orange 330ml','SDR',$cn,1200],['Sprite 330ml','SDR',$cn,1200],
        ['Pepsi 330ml','SDR',$cn,1200],['Tonic Water 200ml','SDR',$bt,1500],['Soda Water 200ml','SDR',$bt,1200],
        ['Ginger Ale 330ml','SDR',$cn,1500],['Red Bull 250ml','SDR',$cn,4000],
        ['Orange Juice 1L','JCE',$l,5000],['Apple Juice 1L','JCE',$l,5000],['Mango Juice 1L','JCE',$l,5500],
        ['Passion Juice 1L','JCE',$l,5500],['Cranberry Juice 1L','JCE',$l,6000],['Pineapple Juice 1L','JCE',$l,5000],
        ['Mineral Water 500ml','WTR',$bt,800],['Mineral Water 1.5L','WTR',$bt,1500],['Mineral Water 5L','WTR',$bt,3000],
        ['Sparkling Water 750ml','WTR',$bt,3000],
        ['Coffee Beans Arabica 1kg','COF',$kg,25000],['Coffee Beans Robusta 1kg','COF',$kg,18000],
        ['Instant Coffee 200g','COF',$pc,8000],['Coffee Capsules Box 10','COF',$bx,12000],
        ['Black Tea Bags 100s','TEA',$bx,5000],['Green Tea Bags 25s','TEA',$bx,6000],['Chamomile Tea 25s','TEA',$bx,7000],
        ['Earl Grey Tea 25s','TEA',$bx,7000],['Rooibos Tea 25s','TEA',$bx,6500],
    ];
    foreach ($drinks as $d) $allItems[] = [$d[0],'BN',$d[1],$d[2],$d[3],'B','ambient',0,0];

    // Alcoholic Beverages (~60)
    $alcohol = [
        ['Sauvignon Blanc','WIN',$bt,25000],['Chardonnay','WIN',$bt,28000],['Pinot Grigio','WIN',$bt,22000],
        ['Cabernet Sauvignon','WIN',$bt,28000],['Merlot','WIN',$bt,25000],['Shiraz','WIN',$bt,26000],
        ['Pinot Noir','WIN',$bt,30000],['Rosé Wine','WIN',$bt,22000],['Prosecco','WIN',$bt,25000],
        ['Champagne Brut','WIN',$bt,85000],['House Red Wine','WIN',$bt,18000],['House White Wine','WIN',$bt,18000],
        ['Tusker Lager 500ml','BER',$bt,3500],['Kilimanjaro Beer 500ml','BER',$bt,3500],
        ['Safari Lager 500ml','BER',$bt,3500],['Serengeti Premium 500ml','BER',$bt,4000],
        ['Castle Lite 330ml','BER',$bt,3500],['Heineken 330ml','BER',$bt,5000],['Corona 330ml','BER',$bt,6000],
        ['Guinness 330ml','BER',$bt,5500],['Stella Artois 330ml','BER',$bt,5000],
        ['Smirnoff Vodka 750ml','SPR',$bt,22000],['Absolut Vodka 750ml','SPR',$bt,30000],
        ['Johnnie Walker Red 750ml','SPR',$bt,28000],['Johnnie Walker Black 750ml','SPR',$bt,48000],
        ['Jack Daniels 750ml','SPR',$bt,38000],['Jameson Irish 750ml','SPR',$bt,32000],
        ['Bacardi White 750ml','SPR',$bt,22000],['Captain Morgan 750ml','SPR',$bt,24000],
        ['Tanqueray Gin 750ml','SPR',$bt,30000],['Hendricks Gin 750ml','SPR',$bt,48000],
        ['Bombay Sapphire 750ml','SPR',$bt,32000],['Konyagi 750ml','SPR',$bt,12000],
        ['Tequila Jose Cuervo 750ml','SPR',$bt,28000],
        ['Baileys Irish Cream 750ml','LIQ',$bt,32000],['Amarula 750ml','LIQ',$bt,25000],
        ['Kahlua 750ml','LIQ',$bt,28000],['Cointreau 750ml','LIQ',$bt,35000],
        ['Grand Marnier 750ml','LIQ',$bt,38000],['Amaretto 750ml','LIQ',$bt,25000],
    ];
    foreach ($alcohol as $a) $allItems[] = [$a[0],'BA',$a[1],$a[2],$a[3],'A','ambient',0,0];

    // Bakery (~20)
    $bakery = [
        ['Active Dry Yeast 500g','BKI',$pk,8000],['Baking Powder 250g','BKI',$pc,3500],
        ['Baking Soda 250g','BKI',$pc,2500],['Cocoa Powder 250g','BKI',$pc,8000],
        ['Chocolate Chips 500g','BKI',$pk,12000],['Dark Chocolate Bar 200g','BKI',$pc,8000],
        ['White Chocolate Bar 200g','BKI',$pc,9000],['Gelatin Sheets 10s','BKI',$pk,5000],
        ['Vanilla Pods 5s','BKI',$pk,15000],['Almond Flour 500g','BKI',$pk,12000],
        ['Desiccated Coconut 250g','BKI',$pk,4000],['Raisins 500g','BKI',$pk,6000],
        ['Walnuts 250g','BKI',$pk,10000],['Cashew Nuts 250g','BKI',$pk,12000],
        ['Food Coloring Set','DEC',$st,8000],['Sprinkles Assorted','DEC',$pc,5000],
        ['Cake Candles Pack','DEC',$pk,3000],['Piping Bags 10s','DEC',$pk,4000],
    ];
    foreach ($bakery as $b) $allItems[] = [$b[0],'BP',$b[1],$b[2],$b[3],'C','ambient',0,0];

    // Frozen Foods (~25)
    $frozen = [
        ['Frozen Prawns 1kg','FSF',$kg,35000],['Frozen Tilapia Fillet 1kg','FSF',$kg,18000],
        ['Frozen Calamari 1kg','FSF',$kg,25000],['Frozen Salmon Fillet 500g','FSF',$pk,30000],
        ['Frozen Fish Fingers 500g','FSF',$pk,10000],['Frozen Nile Perch Fillet 1kg','FSF',$kg,20000],
        ['Frozen Mixed Vegetables 1kg','FVG',$kg,6000],['Frozen Peas 1kg','FVG',$kg,5500],
        ['Frozen Spinach 1kg','FVG',$kg,5000],['Frozen French Fries 2.5kg','FVG',$bg,12000],
        ['Frozen Sweet Corn 1kg','FVG',$kg,5500],['Frozen Broccoli 1kg','FVG',$kg,7000],
        ['Ice Cream Vanilla 5L','ICR',$pc,25000],['Ice Cream Chocolate 5L','ICR',$pc,25000],
        ['Ice Cream Strawberry 5L','ICR',$pc,25000],['Sorbet Mango 2L','ICR',$pc,15000],
        ['Frozen Pizza Margherita','FVG',$pc,12000],['Frozen Croissants 10s','FVG',$pk,15000],
    ];
    foreach ($frozen as $f) $allItems[] = [$f[0],'FF',$f[1],$f[2],$f[3],'B','frozen',1,0];

    // Cleaning (~50)
    $cleaning = [
        ['Floor Cleaner Pine 5L','FLC',$bt,8000],['Floor Polish 5L','FLC',$bt,12000],['Mop Head Cotton','FLC',$pc,5000],
        ['Mop Handle','FLC',$pc,4000],['Broom Soft','FLC',$pc,3500],['Dustpan & Brush Set','FLC',$st,4000],
        ['Toilet Cleaner 750ml','BTH',$bt,3500],['Toilet Brush','BTH',$pc,3000],['Air Freshener 300ml','BTH',$cn,4000],
        ['Hand Soap Liquid 500ml','BTH',$bt,3500],['Bathroom Cleaner 750ml','BTH',$bt,4000],
        ['Bleach 5L','DIS',$bt,5000],['Disinfectant Spray 500ml','DIS',$bt,6000],
        ['Hand Sanitizer 500ml','DIS',$bt,5000],['Surface Cleaner 750ml','DIS',$bt,4000],
        ['Glass Cleaner 750ml','DIS',$bt,3500],
        ['Bin Liners Large 50s','WST',$pk,5000],['Bin Liners Medium 50s','WST',$pk,4000],
        ['Recycling Bags 20s','WST',$pk,3000],
        ['Sponge Scourer 10s','FLC',$pk,3000],['Steel Wool 6s','FLC',$pk,2500],
        ['Dish Soap 5L','FLC',$bt,6000],['Dishwasher Tablets 30s','FLC',$bx,12000],
        ['Rubber Gloves Pair','FLC',$pr,2000],['Microfiber Cloth 5s','FLC',$pk,4000],
    ];
    foreach ($cleaning as $c) $allItems[] = [$c[0],'CH',$c[1],$c[2],$c[3],'C','ambient',0,0];

    // Guest Amenities (~30)
    $amenities = [
        ['Shampoo Sachet 30ml 100s','TLT',$bx,15000],['Conditioner Sachet 30ml 100s','TLT',$bx,15000],
        ['Shower Gel Sachet 30ml 100s','TLT',$bx,15000],['Body Lotion Sachet 30ml 100s','TLT',$bx,16000],
        ['Soap Bar 40g 100s','TLT',$bx,12000],['Dental Kit 100s','TLT',$bx,18000],
        ['Shaving Kit 50s','TLT',$bx,12000],['Vanity Kit 50s','TLT',$bx,10000],
        ['Bath Towel White','TWL',$pc,15000],['Hand Towel White','TWL',$pc,8000],
        ['Face Cloth White','TWL',$pc,4000],['Pool Towel','TWL',$pc,18000],['Bath Mat','TWL',$pc,8000],
        ['Bathrobe White','TWL',$pc,25000],['Slippers Disposable 50pr','TWL',$bx,15000],
        ['Tissue Box 100s','RMS',$bx,2500],['Toilet Roll 48s','RMS',$cs,18000],
        ['Laundry Bag 100s','RMS',$pk,8000],['Shoe Shine Kit 50s','RMS',$bx,10000],
        ['Sewing Kit 50s','RMS',$bx,8000],['Do Not Disturb Sign 10s','RMS',$pk,3000],
        ['Room Freshener 300ml','RMS',$cn,4500],['Matches Box 50s','RMS',$bx,2000],
        ['Mosquito Repellent 100ml','RMS',$bt,6000],
    ];
    foreach ($amenities as $a) $allItems[] = [$a[0],'GA',$a[1],$a[2],$a[3],'C','ambient',0,0];

    // Office (~20)
    $office = [
        ['A4 Paper Ream 500s','PPR',$pk,12000],['A3 Paper Ream','PPR',$pk,18000],
        ['Printer Ink Black','PRT',$pc,25000],['Printer Ink Color','PRT',$pc,30000],
        ['Toner Cartridge','PRT',$pc,45000],['Photocopy Paper A4 Box','PPR',$bx,55000],
        ['Ballpoint Pens Box 50','DSK',$bx,8000],['Stapler','DSK',$pc,5000],
        ['Staples Box','DSK',$bx,2000],['Paper Clips Box','DSK',$bx,1500],
        ['Sticky Notes Pack','DSK',$pk,3000],['Whiteboard Marker Set','DSK',$st,5000],
        ['Envelope A4 50s','PPR',$pk,4000],['Receipt Book 3-ply','PPR',$pc,3000],
        ['File Folder Box 25','DSK',$bx,8000],['Scissors','DSK',$pc,3000],
        ['Calculator','DSK',$pc,8000],['Tape Dispenser','DSK',$pc,4000],
    ];
    foreach ($office as $o) $allItems[] = [$o[0],'OS',$o[1],$o[2],$o[3],'C','ambient',0,0];

    // Maintenance (~40)
    $maint = [
        ['LED Bulb 9W E27','ELC',$pc,3000],['LED Bulb 15W E27','ELC',$pc,4000],
        ['Fluorescent Tube 4ft','ELC',$pc,5000],['Extension Cable 5m','ELC',$pc,8000],
        ['Electrical Tape','ELC',$rl,1500],['Battery AA 4-pack','ELC',$pk,3000],['Battery D 2-pack','ELC',$pk,4000],
        ['PVC Pipe 1inch 3m','PLB',$pc,4000],['PVC Elbow 1inch','PLB',$pc,800],['Pipe Tape Roll','PLB',$rl,1000],
        ['Tap Washer Set','PLB',$st,2000],['Shower Head','PLB',$pc,8000],['Toilet Flush Valve','PLB',$pc,12000],
        ['Hammer','TLS',$pc,8000],['Screwdriver Set','TLS',$st,12000],['Adjustable Wrench','TLS',$pc,10000],
        ['Pliers','TLS',$pc,6000],['Tape Measure 5m','TLS',$pc,4000],['Drill Bits Set','TLS',$st,15000],
        ['Wood Screws Box','TLS',$bx,3000],['Wall Plugs Box','TLS',$bx,2000],['Nails Assorted Box','TLS',$bx,3000],
        ['Paint White 20L','PNT',$pc,45000],['Paint Roller Set','PNT',$st,8000],
        ['Paintbrush 4inch','PNT',$pc,4000],['Sandpaper Pack','PNT',$pk,3000],
        ['Padlock Heavy Duty','TLS',$pc,10000],['Door Hinge Pair','TLS',$pr,5000],
        ['Silicone Sealant','TLS',$pc,6000],['WD-40 Spray 400ml','TLS',$cn,8000],
    ];
    foreach ($maint as $m) $allItems[] = [$m[0],'MH',$m[1],$m[2],$m[3],'C','ambient',0,0];

    // Kitchen Equipment (~25)
    $kitchen = [
        ['Chef Knife 10inch','UTN',$pc,25000],['Paring Knife','UTN',$pc,8000],['Bread Knife','UTN',$pc,12000],
        ['Knife Sharpener','UTN',$pc,10000],['Cutting Board Large','UTN',$pc,12000],
        ['Ladle Stainless','UTN',$pc,5000],['Spatula Silicone','UTN',$pc,4000],['Tongs 12inch','UTN',$pc,5000],
        ['Whisk','UTN',$pc,4000],['Peeler','UTN',$pc,3000],['Can Opener','UTN',$pc,5000],
        ['Saucepan 3L','CKW',$pc,18000],['Stockpot 20L','CKW',$pc,35000],['Frying Pan 28cm','CKW',$pc,15000],
        ['Baking Tray','CKW',$pc,8000],['Mixing Bowl Set','CKW',$st,12000],['Colander Stainless','CKW',$pc,8000],
        ['Hand Blender','SMA',$pc,25000],['Food Processor','SMA',$pc,85000],['Toaster 4-Slice','SMA',$pc,35000],
        ['Electric Kettle 1.7L','SMA',$pc,18000],['Weighing Scale Digital','SMA',$pc,15000],
        ['Thermometer Probe','SMA',$pc,8000],
    ];
    foreach ($kitchen as $k) $allItems[] = [$k[0],'KE',$k[1],$k[2],$k[3],'C','ambient',0,0];

    // Linen (~25)
    $linen = [
        ['Bed Sheet King White','BED',$pc,25000],['Bed Sheet Queen White','BED',$pc,22000],
        ['Bed Sheet Single White','BED',$pc,18000],['Duvet Cover King','BED',$pc,30000],
        ['Duvet Cover Queen','BED',$pc,28000],['Pillow Cases Pair','BED',$pr,8000],
        ['Pillow Standard','BED',$pc,12000],['Duvet King','BED',$pc,45000],
        ['Mattress Protector King','BED',$pc,20000],['Bed Runner','BED',$pc,15000],
        ['Table Cloth White 180cm','TBL',$pc,15000],['Table Cloth Round 120cm','TBL',$pc,12000],
        ['Napkins White 50s','TBL',$pk,8000],['Table Runner','TBL',$pc,10000],
        ['Curtain Blackout','CRT',$pc,25000],['Curtain Sheer','CRT',$pc,15000],
        ['Shower Curtain','CRT',$pc,10000],['Cushion Cover','CRT',$pc,8000],
    ];
    foreach ($linen as $li) $allItems[] = [$li[0],'LT',$li[1],$li[2],$li[3],'C','ambient',0,0];

    // Medical (~15)
    $medical = [
        ['First Aid Kit Complete','FAD',$st,35000],['Bandages Assorted Box','FAD',$bx,8000],
        ['Gauze Pads 100s','FAD',$bx,6000],['Antiseptic Solution 500ml','FAD',$bt,5000],
        ['Cotton Wool 250g','FAD',$pk,3000],['Plasters Box 100','FAD',$bx,5000],
        ['Medical Gloves Box 100','FAD',$bx,12000],['Burn Cream Tube','FAD',$pc,4000],
        ['Paracetamol 100s','MED',$bx,5000],['Ibuprofen 50s','MED',$bx,6000],
        ['Oral Rehydration Sachets 20','MED',$bx,4000],['Insect Bite Cream','MED',$pc,5000],
        ['Eye Drops 10ml','MED',$pc,4000],['Antihistamine 30s','MED',$bx,5000],
    ];
    foreach ($medical as $m) $allItems[] = [$m[0],'MF',$m[1],$m[2],$m[3],'C','ambient',0,1];

    // Fuel (~10)
    $fuel = [
        ['Diesel per Litre','DSL',$l,3200],['Petrol per Litre','DSL',$l,3400],
        ['LPG Gas 15kg','GAS',$pc,45000],['LPG Gas 6kg','GAS',$pc,22000],
        ['Charcoal 50kg Bag','GAS',$bg,25000],['Firewood Bundle','GAS',$pc,5000],
        ['Solar Panel Cleaner 1L','SLR',$bt,8000],['Generator Oil 5L','DSL',$bt,25000],
        ['Kerosene 20L','DSL',$bt,40000],
    ];
    foreach ($fuel as $f) $allItems[] = [$f[0],'FE',$f[1],$f[2],$f[3],'A','hazardous',0,1];

    // Safari Equipment (~25)
    $safari = [
        ['Binoculars 10x42','OPT',$pc,120000],['Binoculars 8x32','OPT',$pc,85000],
        ['Spotting Scope','OPT',$pc,250000],['Camera Trap','OPT',$pc,180000],
        ['Head Torch LED','LGT',$pc,8000],['Torch Rechargeable','LGT',$pc,12000],
        ['Lantern Solar','LGT',$pc,15000],['Spotlight 12V','LGT',$pc,25000],
        ['Safari Hat','CMP',$pc,8000],['Rain Poncho','CMP',$pc,5000],
        ['Backpack 30L','CMP',$pc,25000],['Water Bottle 1L','CMP',$pc,5000],
        ['Cool Box 25L','CMP',$pc,35000],['Camp Chair Folding','CMP',$pc,18000],
        ['Picnic Blanket','CMP',$pc,12000],['Binocular Strap','CMP',$pc,3000],
        ['First Aid Kit Safari','SFT',$st,25000],['Snake Bite Kit','SFT',$st,20000],
        ['Fire Extinguisher 2kg','SFT',$pc,25000],['Two-Way Radio Pair','SFT',$pr,45000],
        ['Reflective Vest','SFT',$pc,5000],
    ];
    foreach ($safari as $s) $allItems[] = [$s[0],'SE',$s[1],$s[2],$s[3],'C','ambient',0,0];

    // Laundry (~18)
    $laundry = [
        ['Laundry Detergent 10kg','DTG',$bg,25000],['Laundry Detergent Liquid 5L','DTG',$bt,18000],
        ['Stain Remover 1L','DTG',$bt,8000],['Bleach Laundry 5L','DTG',$bt,6000],
        ['Dry Cleaning Solvent 5L','DTG',$bt,15000],['Washing Soda 2kg','DTG',$bg,5000],
        ['Fabric Softener 5L','SFT2',$bt,12000],['Fabric Softener Sheets 100s','SFT2',$bx,8000],
        ['Ironing Starch Spray 500ml','IRN',$cn,4000],['Iron Cleaner','IRN',$pc,5000],
        ['Ironing Board Cover','IRN',$pc,8000],['Clothes Hangers 50s','SFT2',$pk,10000],
        ['Laundry Basket','SFT2',$pc,8000],['Pegs Pack 50','SFT2',$pk,3000],
    ];
    foreach ($laundry as $la) $allItems[] = [$la[0],'LN',$la[1],$la[2],$la[3],'C','ambient',0,0];

    // Packaging (~15)
    $packaging = [
        ['Cling Film 300m','WRP',$rl,6000],['Aluminium Foil 30m','WRP',$rl,5000],
        ['Baking Paper Roll','WRP',$rl,4000],['Vacuum Bags 100s','WRP',$pk,12000],
        ['Takeaway Box 50s','CTN',$pk,8000],['Food Container 500ml 50s','CTN',$pk,7000],
        ['Food Container 1L 50s','CTN',$pk,9000],['Styrofoam Box 50s','CTN',$pk,6000],
        ['Ziplock Bags Small 100s','BAG',$pk,4000],['Ziplock Bags Large 50s','BAG',$pk,5000],
        ['Paper Bags Medium 100s','BAG',$pk,5000],['Plastic Wrap Roll','WRP',$rl,4000],
        ['Cocktail Straws 500s','CTN',$pk,3000],['Disposable Cups 50s','CTN',$pk,3500],
    ];
    foreach ($packaging as $p) $allItems[] = [$p[0],'PK',$p[1],$p[2],$p[3],'C','ambient',0,0];

    // Gardening (~12)
    $garden = [
        ['NPK Fertilizer 25kg','FRT2',$bg,25000],['Compost 50kg','FRT2',$bg,15000],
        ['Lawn Fertilizer 10kg','FRT2',$bg,18000],['Insecticide 1L','FRT2',$bt,8000],
        ['Garden Hose 30m','GTL',$pc,20000],['Rake','GTL',$pc,8000],
        ['Spade','GTL',$pc,10000],['Secateurs','GTL',$pc,8000],['Wheelbarrow','GTL',$pc,35000],
        ['Sprinkler Rotating','IRG',$pc,12000],['Drip Hose 15m','IRG',$pc,10000],
        ['Watering Can 10L','IRG',$pc,8000],
    ];
    foreach ($garden as $ga) $allItems[] = [$ga[0],'GL',$ga[1],$ga[2],$ga[3],'C','ambient',0,0];

    // Now insert all items
    $itemStmt = $pdo->prepare("
        INSERT IGNORE INTO items (tenant_id, item_code, name, item_group_id, sub_category_id, stock_uom_id,
            purchase_uom_id, issue_uom_id, last_purchase_price, abc_class, storage_type,
            is_perishable, is_critical, is_active)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)
    ");

    $count = 0;
    foreach ($allItems as $idx => $item) {
        [$name, $grpCode, $subCode, $uomId, $price, $abc, $storage, $perish, $critical] = $item;
        $code = 'ITM-' . str_pad($idx + 1, 4, '0', STR_PAD_LEFT);
        $gid = $grpIds[$grpCode] ?? null;
        $sid = $subIds[$subCode] ?? null;

        $itemStmt->execute([
            $tid, $code, $name, $gid, $sid, $uomId, $uomId, $uomId,
            $price, $abc, $storage, $perish, $critical,
        ]);
        $count++;
    }

    return ['items_inserted' => $count];
}

// ═══════════════════════════════════════════════════
// SEED: HR & PAYROLL
// ═══════════════════════════════════════════════════
function seedHR(PDO $pdo, int $tid, int $userId): array {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM hr_employees WHERE tenant_id = ?");
    $cnt->execute([$tid]);
    if ((int)$cnt->fetchColumn() > 10) return ['skipped' => true];

    $stats = [];

    // Departments
    $deptStmt = $pdo->prepare("INSERT IGNORE INTO departments (tenant_id, name, code, is_active) VALUES (?,?,?,1)");
    $depts = [['Kitchen','KIT'],['Housekeeping','HSK'],['Front Office','FO'],['Maintenance','MNT'],
              ['Safari & Activities','SAF'],['Bar & Beverage','BAR'],['Administration','ADM'],['Transport','TRP']];
    foreach ($depts as $d) $deptStmt->execute([$tid, $d[0], $d[1]]);
    $stats['departments'] = count($depts);

    // Get dept IDs
    $deptIds = [];
    $ds = $pdo->prepare("SELECT id, code FROM departments WHERE tenant_id = ?");
    $ds->execute([$tid]);
    foreach ($ds->fetchAll() as $r) $deptIds[$r['code']] = (int)$r['id'];

    // Job Grades
    $jgStmt = $pdo->prepare("INSERT IGNORE INTO job_grades (tenant_id, name, level, min_salary, max_salary) VALUES (?,?,?,?,?)");
    $jgs = [['Executive',1,2000000,5000000],['Senior Manager',2,1200000,2500000],['Manager',3,800000,1500000],
            ['Supervisor',4,500000,900000],['Staff',5,300000,600000],['Junior Staff',6,200000,400000]];
    foreach ($jgs as $j) $jgStmt->execute([$tid, $j[0], $j[1], $j[2], $j[3]]);
    $stats['job_grades'] = count($jgs);

    $jgIds = [];
    $js = $pdo->prepare("SELECT id, level FROM job_grades WHERE tenant_id = ?");
    $js->execute([$tid]);
    foreach ($js->fetchAll() as $r) $jgIds[$r['level']] = (int)$r['id'];

    // Regions
    $regStmt = $pdo->prepare("INSERT IGNORE INTO hr_regions (tenant_id, name, code, country) VALUES (?,?,?,'TZ')");
    $regs = [['Northern Zone','NZ'],['Central Zone','CZ'],['Western Zone','WZ']];
    foreach ($regs as $r) $regStmt->execute([$tid, $r[0], $r[1]]);
    $stats['regions'] = 3;

    // Shifts
    $shStmt = $pdo->prepare("INSERT IGNORE INTO shifts (tenant_id, name, start_time, end_time, break_minutes, is_active) VALUES (?,?,?,?,?,1)");
    $shifts = [['Morning','06:00','14:00',30],['Afternoon','14:00','22:00',30],['Night','22:00','06:00',30],['Day','07:00','19:00',60]];
    foreach ($shifts as $s) $shStmt->execute([$tid, $s[0], $s[1], $s[2], $s[3]]);
    $stats['shifts'] = 4;

    // Get camp IDs
    $campIds = [];
    $cs = $pdo->prepare("SELECT id, code FROM camps WHERE tenant_id = ?");
    $cs->execute([$tid]);
    foreach ($cs->fetchAll() as $r) $campIds[$r['code']] = (int)$r['id'];

    // Employees (~80)
    $empStmt = $pdo->prepare("
        INSERT IGNORE INTO hr_employees (tenant_id, employee_no, first_name, last_name, email, phone,
            department_id, job_grade_id, employment_type, employment_status,
            gender, basic_salary, hire_date, camp_id, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
    ");

    $firstNames_m = ['Joseph','Emmanuel','Peter','John','James','Michael','David','Daniel','Frank','George',
                     'Charles','Robert','William','Thomas','Andrew','Paul','Patrick','Richard','Henry','Lucas',
                     'Hassan','Ali','Hamisi','Baraka','Juma','Bakari','Selemani','Rashid','Omari','Salim'];
    $firstNames_f = ['Grace','Mary','Anna','Esther','Sarah','Rose','Agnes','Joyce','Lucy','Fatma',
                     'Amina','Halima','Zainab','Rehema','Neema','Flora','Gladys','Dorothy','Martha','Peace',
                     'Happiness','Upendo','Stella','Jane','Catherine','Monica','Ruth','Naomi','Dorcas','Christine'];
    $lastNames = ['Mwangi','Kimaro','Shirima','Massawe','Mlay','Tarimo','Ngowi','Mrema','Lyimo','Urassa',
                  'Mushi','Shayo','Swai','Mkenda','Mchau','Kisaka','Mtei','Makundi','Assey','Temu',
                  'Mwenda','Bakari','Hamisi','Komba','Macha','Minja','Nko','Pallangyo','Saria','Temba'];

    $deptCodes = ['KIT','HSK','FO','MNT','SAF','BAR','ADM','TRP'];
    $campCodes = ['TAR','NGO','SRN','SRS','SRW'];
    $empCount = 0;

    for ($i = 1; $i <= 80; $i++) {
        $isFemale = $i % 3 === 0;
        $fn = $isFemale ? $firstNames_f[array_rand($firstNames_f)] : $firstNames_m[array_rand($firstNames_m)];
        $ln = $lastNames[array_rand($lastNames)];
        $empNo = 'EMP-' . str_pad($i, 3, '0', STR_PAD_LEFT);
        $email = strtolower($fn . '.' . $ln . $i) . '@savannah.co.tz';
        $phone = '+255 7' . rand(10,99) . ' ' . rand(100,999) . ' ' . str_pad(rand(0,999), 3, '0', STR_PAD_LEFT);
        $dept = $deptCodes[array_rand($deptCodes)];
        $camp = $campCodes[($i - 1) % 5];
        $grade = $i <= 5 ? 3 : ($i <= 15 ? 4 : ($i <= 30 ? 5 : 6));
        $salary = rand($jgs[$grade-1][2], $jgs[$grade-1][3]);
        $hireDate = date('Y-m-d', strtotime('-' . rand(30, 400) . ' days'));

        $empStmt->execute([
            $tid, $empNo, $fn, $ln, $email, $phone,
            $deptIds[$dept], $jgIds[$grade], 'full_time', 'active',
            $isFemale ? 'female' : 'male', $salary, $hireDate,
            $campIds[$camp] ?? null,
        ]);
        $empCount++;
    }
    $stats['employees'] = $empCount;

    // Leave Types
    $ltStmt = $pdo->prepare("INSERT IGNORE INTO leave_types (tenant_id, name, code, default_days, is_paid, accrual_method) VALUES (?,?,?,?,?,?)");
    $lts = [['Annual Leave','AL',21,1,'annual'],['Sick Leave','SL',14,1,'annual'],
            ['Maternity Leave','ML',84,1,'none'],['Paternity Leave','PL',14,1,'none'],
            ['Compassionate Leave','CL',5,1,'none'],['Unpaid Leave','UL',30,0,'none'],
            ['Study Leave','STL',10,1,'annual']];
    foreach ($lts as $lt) $ltStmt->execute([$tid, $lt[0], $lt[1], $lt[2], $lt[3], $lt[4]]);
    $stats['leave_types'] = count($lts);

    // Allowance & Deduction Types
    $alStmt = $pdo->prepare("INSERT IGNORE INTO allowance_types (tenant_id, name, code, is_taxable, is_fixed, default_amount) VALUES (?,?,?,?,?,?)");
    $als = [['House Allowance','HSA',1,1,100000],['Transport Allowance','TRA',0,1,50000],
            ['Safari Allowance','SAF',0,0,30000],['Hardship Allowance','HDA',0,1,40000],
            ['Phone Allowance','PHA',0,1,20000]];
    foreach ($als as $a) $alStmt->execute([$tid, $a[0], $a[1], $a[2], $a[3], $a[4]]);

    $deStmt = $pdo->prepare("INSERT IGNORE INTO deduction_types (tenant_id, name, code, is_statutory, calculation_method) VALUES (?,?,?,?,?)");
    $des = [['NSSF','NSSF',1,'percentage'],['PAYE','PAYE',1,'tiered'],['NHIF','NHIF',1,'tiered'],
            ['Housing Levy','HL',1,'percentage'],['Union Fee','UNION',0,'fixed']];
    foreach ($des as $d) $deStmt->execute([$tid, $d[0], $d[1], $d[2], $d[3]]);
    $stats['allowance_deduction_types'] = count($als) + count($des);

    // Payroll Periods + Runs (12 months)
    $ppStmt = $pdo->prepare("INSERT IGNORE INTO payroll_periods (tenant_id, name, period_type, start_date, end_date, pay_date, status) VALUES (?,?,?,?,?,?,?)");
    $prStmt = $pdo->prepare("INSERT IGNORE INTO payroll_runs (tenant_id, period_id, status, employee_count, total_gross, total_net, total_deductions, total_employer_cost, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)");

    for ($m = 0; $m < 12; $m++) {
        $start = date('Y-m-01', strtotime("-{$m} months"));
        $end = date('Y-m-t', strtotime("-{$m} months"));
        $pay = date('Y-m-25', strtotime("-{$m} months"));
        $name = date('F Y', strtotime($start));
        $status = $m === 0 ? 'open' : 'locked';

        $ppStmt->execute([$tid, $name, 'monthly', $start, $end, $pay, $status]);
        $periodId = (int)$pdo->lastInsertId();

        if ($m > 0) {
            $gross = rand(25000000, 35000000);
            $ded = (int)($gross * 0.25);
            $net = $gross - $ded;
            $emp_cost = (int)($gross * 1.1);
            $prStmt->execute([$tid, $periodId, 'paid', 80, $gross, $net, $ded, $emp_cost, $userId, $start . ' 10:00:00']);
        }
    }
    $stats['payroll_periods'] = 12;
    $stats['payroll_runs'] = 11;

    return $stats;
}

// ═══════════════════════════════════════════════════
// SEED: OPERATIONS
// ═══════════════════════════════════════════════════
function seedOperations(PDO $pdo, int $tid, int $userId): array {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE tenant_id = ?");
    $cnt->execute([$tid]);
    if ((int)$cnt->fetchColumn() > 10) return ['skipped' => true];

    // Get camp IDs
    $campIds = [];
    $cs = $pdo->prepare("SELECT id, code FROM camps WHERE tenant_id = ?");
    $cs->execute([$tid]);
    foreach ($cs->fetchAll() as $r) $campIds[$r['code']] = (int)$r['id'];

    // Get some item IDs
    $itemStmt = $pdo->prepare("SELECT id, item_code, last_purchase_price FROM items WHERE tenant_id = ? ORDER BY id LIMIT 200");
    $itemStmt->execute([$tid]);
    $items = $itemStmt->fetchAll();
    if (empty($items)) return ['skipped' => true, 'reason' => 'No items to create orders for'];

    $campCodes = ['TAR','NGO','SRN','SRS','SRW'];
    $stats = ['orders' => 0, 'order_lines' => 0];

    // Create ~100 camp orders over 12 months
    $orderStmt = $pdo->prepare("
        INSERT IGNORE INTO orders (tenant_id, order_number, camp_id, status, created_by,
            total_items, total_value, flagged_items, notes, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
    $lineStmt = $pdo->prepare("
        INSERT IGNORE INTO order_lines (order_id, item_id, requested_qty, estimated_unit_cost, estimated_line_value, validation_status, stores_action)
        VALUES (?,?,?,?,?,'auto_approved','approved')
    ");

    for ($m = 11; $m >= 0; $m--) {
        foreach ($campCodes as $ci => $cc) {
            // 2 orders per camp per month
            for ($o = 0; $o < 2; $o++) {
                $day = rand(1, 28);
                $date = date('Y-m-d', strtotime("-{$m} months +{$day} days"));
                $orderNum = 'ORD-' . $cc . '-' . date('ym', strtotime($date)) . '-' . str_pad($stats['orders'] + 1, 4, '0', STR_PAD_LEFT);
                $status = $m > 0 ? 'completed' : 'draft';
                $lineCount = rand(10, 25);
                $totalValue = 0;

                $orderStmt->execute([
                    $tid, $orderNum, $campIds[$cc] ?? null, $status, $userId,
                    $lineCount, 0, 0, "Monthly order for $cc",
                    $date . ' 09:00:00', $date . ' 09:00:00'
                ]);
                $orderId = (int)$pdo->lastInsertId();
                $stats['orders']++;

                // 10-25 lines per order
                $usedItems = array_rand($items, min($lineCount, count($items)));
                if (!is_array($usedItems)) $usedItems = [$usedItems];

                foreach ($usedItems as $itemIdx) {
                    $item = $items[$itemIdx];
                    $qty = rand(1, 50);
                    $price = $item['last_purchase_price'] ?: rand(2000, 20000);
                    $lineValue = $qty * $price;
                    $lineStmt->execute([$orderId, $item['id'], $qty, $price, $lineValue]);
                    $totalValue += $lineValue;
                    $stats['order_lines']++;
                }

                // Update total_value on the order
                $pdo->prepare("UPDATE orders SET total_items=?, total_value=? WHERE id=?")->execute([count($usedItems), $totalValue, $orderId]);
            }
        }
    }

    // Stock Balances — seed current quantities for all items across all camps
    $allItems = $pdo->prepare("SELECT id, last_purchase_price FROM items WHERE tenant_id = ?");
    $allItems->execute([$tid]);
    $allItemRows = $allItems->fetchAll();

    $sbStmt = $pdo->prepare("
        INSERT IGNORE INTO stock_balances (tenant_id, camp_id, item_id, current_qty, current_value, unit_cost,
            par_level, min_level, max_level, stock_status, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
    ");

    $sbCount = 0;
    foreach ($campCodes as $cc) {
        $cid = $campIds[$cc] ?? null;
        if (!$cid) continue;
        foreach ($allItemRows as $item) {
            $qty = rand(0, 100);
            $cost = $item['last_purchase_price'] ?: rand(2000, 15000);
            $val = $qty * $cost;
            $par = rand(10, 50);
            $status = $qty < ($par * 0.3) ? 'critical' : ($qty < ($par * 0.7) ? 'low' : 'ok');

            $sbStmt->execute([$tid, $cid, $item['id'], $qty, $val, $cost, $par, (int)($par*0.3), $par*2, $status]);
            $sbCount++;
        }
    }
    $stats['stock_balances'] = $sbCount;

    return $stats;
}

// ═══════════════════════════════════════════════════
// SEED: KITCHEN
// ═══════════════════════════════════════════════════
function seedKitchen(PDO $pdo, int $tid): array {
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM kitchen_recipes WHERE tenant_id = ?");
    $cnt->execute([$tid]);
    if ((int)$cnt->fetchColumn() > 10) return ['skipped' => true];

    // Get some item IDs for ingredients
    $itemMap = [];
    $is = $pdo->prepare("SELECT id, name FROM items WHERE tenant_id = ? LIMIT 200");
    $is->execute([$tid]);
    foreach ($is->fetchAll() as $r) $itemMap[strtolower($r['name'])] = (int)$r['id'];

    $findItem = function($name) use ($pdo, $tid) {
        $s = $pdo->prepare("SELECT id FROM items WHERE tenant_id = ? AND name LIKE ? LIMIT 1");
        $s->execute([$tid, "%{$name}%"]);
        $r = $s->fetch();
        return $r ? (int)$r['id'] : null;
    };

    $recStmt = $pdo->prepare("
        INSERT IGNORE INTO kitchen_recipes (tenant_id, name, description, category, cuisine, serves,
            prep_time_minutes, cook_time_minutes, difficulty, instructions, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
    ");
    $ingStmt = $pdo->prepare("
        INSERT IGNORE INTO kitchen_recipe_ingredients (tenant_id, recipe_id, item_id, qty_per_serving, is_primary, notes)
        VALUES (?,?,?,?,?,?)
    ");

    $recipes = [
        ['Eggs Benedict','Poached eggs on muffin with hollandaise','breakfast','Continental',4,20,15,'medium',
         '["Poach eggs","Toast muffins","Make hollandaise","Assemble"]',
         [['Eggs',2],['Butter',0.05],['Lemons',0.25],['Bacon',0.1]]],
        ['Nyama Choma','Traditional East African grilled meat','dinner','African',6,30,60,'easy',
         '["Marinate beef","Prepare charcoal","Grill meat","Serve with ugali"]',
         [['Beef Sirloin',0.3],['Lemons',0.5],['Garlic',0.02],['Salt',0.01]]],
        ['Chicken Tikka Masala','Spiced chicken in tomato cream sauce','dinner','Indian',4,30,40,'medium',
         '["Marinate chicken","Grill chicken","Make masala sauce","Combine and simmer"]',
         [['Chicken Breast',0.25],['Yogurt',0.1],['Tomatoes',0.2],['Cream',0.1],['Garam Masala',0.01]]],
        ['Caesar Salad','Classic caesar with croutons and parmesan','salad','Continental',4,15,5,'easy',
         '["Wash lettuce","Make dressing","Toast croutons","Toss and serve"]',
         [['Lettuce Romaine',0.15],['Parmesan',0.03],['Olive Oil',0.02],['Lemons',0.25],['Garlic',0.005]]],
        ['Pasta Carbonara','Classic Italian pasta with egg and bacon','dinner','Italian',4,10,20,'medium',
         '["Cook pasta","Fry bacon","Mix eggs with cheese","Combine"]',
         [['Spaghetti',0.125],['Bacon',0.05],['Eggs',1],['Parmesan',0.03]]],
        ['Butternut Soup','Creamy roasted butternut squash soup','soup','Continental',6,15,40,'easy',
         '["Roast butternut","Sauté onions","Blend together","Season and serve"]',
         [['Pumpkin',0.25],['Red Onions',0.05],['Cream',0.05],['Butter',0.02]]],
        ['Grilled Tilapia','Fresh tilapia with herbs and lemon','dinner','African',4,15,20,'easy',
         '["Season fish","Heat grill","Grill each side","Serve with vegetables"]',
         [['Tilapia',0.25],['Lemons',0.5],['Olive Oil',0.02],['Garlic',0.01]]],
        ['Chocolate Lava Cake','Warm chocolate cake with molten center','dessert','Continental',4,20,15,'hard',
         '["Melt chocolate and butter","Whisk eggs and sugar","Fold together","Bake 12 minutes"]',
         [['Dark Chocolate',0.05],['Butter',0.05],['Eggs',1],['Sugar',0.03],['Flour',0.02]]],
        ['Pilau Rice','Spiced Swahili rice dish','dinner','African',6,15,45,'medium',
         '["Fry onions","Toast spices","Add rice and stock","Steam until done"]',
         [['Basmati Rice',0.1],['Red Onions',0.1],['Pilau Masala',0.01],['Garlic',0.01]]],
        ['Fruit Salad','Fresh tropical fruit medley','dessert','Continental',4,15,0,'easy',
         '["Cut all fruits","Mix gently","Add honey","Chill and serve"]',
         [['Mangoes',0.15],['Pineapple',0.15],['Watermelon',0.15],['Passion Fruit',0.1]]],
        ['Chapati','East African flatbread','bread','African',8,30,20,'medium',
         '["Mix flour and water","Knead dough","Roll thin rounds","Cook on griddle"]',
         [['Flour',0.1],['Vegetable Oil',0.02],['Salt',0.005]]],
        ['French Onion Soup','Classic caramelized onion soup','soup','Continental',4,15,60,'medium',
         '["Slice onions","Caramelize slowly","Add stock","Top with cheese and broil"]',
         [['White Onions',0.3],['Butter',0.03],['Beef Stock',0.01]]],
        ['Lamb Rack','Herb-crusted rack of lamb','dinner','Continental',4,20,35,'hard',
         '["Season lamb","Sear all sides","Apply herb crust","Roast to medium-rare"]',
         [['Lamb Rack',0.25],['Rosemary',0.01],['Garlic',0.01],['Mustard',0.01],['Olive Oil',0.02]]],
        ['Prawn Curry','Coconut prawn curry with rice','dinner','Indian',4,15,25,'medium',
         '["Sauté aromatics","Add coconut milk","Add prawns","Simmer 5 minutes"]',
         [['Prawns',0.2],['Coconut Milk',0.1],['Curry Powder',0.01],['Tomatoes',0.1]]],
        ['Pancakes','Fluffy breakfast pancakes','breakfast','Continental',4,10,15,'easy',
         '["Mix dry ingredients","Add wet ingredients","Cook on griddle","Serve with syrup"]',
         [['Flour',0.08],['Eggs',0.5],['Milk',0.1],['Butter',0.02],['Sugar',0.02]]],
    ];

    $recipeCount = 0;
    $ingredientCount = 0;

    foreach ($recipes as $rec) {
        $recStmt->execute([$tid, $rec[0], $rec[1], $rec[2], $rec[3], $rec[4], $rec[5], $rec[6], $rec[7], $rec[8]]);
        $recipeId = (int)$pdo->lastInsertId();
        $recipeCount++;

        foreach ($rec[9] as $ing) {
            $itemId = $findItem($ing[0]);
            if ($itemId) {
                $ingStmt->execute([$tid, $recipeId, $itemId, $ing[1], 1, null]);
                $ingredientCount++;
            }
        }
    }

    return ['recipes' => $recipeCount, 'ingredients' => $ingredientCount];
}
