<?php
/**
 * One-time: Seed the kitchen_default_menu table with the weekly rotating menu.
 * Run: curl -X POST https://darkblue-goshawk-672880.hostingersite.com/seed-default-menu.php
 * DELETE THIS FILE after running.
 */
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['message' => 'Send POST to run seed']);
    exit;
}

$pdo = getDB();

// ── Lunch Menu (Mon=0 through Sun=6) ──────────────────────────────
// Pattern: Mon-Thu unique, Fri=Mon, Sat=Tue, Sun=Wed
$lunch = [
    // ── Monday (0) ──
    [0, 'appetizer', 'Vegetable Samosa with Sweet Chili Sauce', 1],
    [0, 'soup', 'Carrot and Celery Soup', 2],
    [0, 'main_course', 'Kilimanjaro Beer-Battered Tilapia with Chips', 3],
    [0, 'main_course', 'Grilled Pork Chops with Sweet and Sour Sauce', 4],
    [0, 'main_course', 'Vegetable Quiche with Mixed Salad', 5],
    [0, 'main_course', 'Pasta with Tomato Pesto Sauce', 6],
    [0, 'dessert', 'Fresh Fruit Salad', 7],
    [0, 'dessert', 'Chocolate Mousse', 8],

    // ── Tuesday (1) ──
    [1, 'appetizer', 'Avocado Vinaigrette', 1],
    [1, 'soup', 'Leek and Potato Soup', 2],
    [1, 'main_course', 'Stuffed Chicken', 3],
    [1, 'main_course', 'Spaghetti Bolognese with Garlic Toast', 4],
    [1, 'main_course', 'Stuffed Aubergine Rolls with Salad and Sweet Corn Salsa', 5],
    [1, 'main_course', 'Vegetable Cannelloni with Salad', 6],
    [1, 'dessert', 'Mango Sorbet', 7],
    [1, 'dessert', 'Coconut Cream Caramel', 8],

    // ── Wednesday (2) ──
    [2, 'appetizer', 'Camembert and Caramelized Onion Bruschetta', 1],
    [2, 'soup', 'Tomato Basil Soup with Croutons', 2],
    [2, 'main_course', 'Fish Nile Perch Paprika', 3],
    [2, 'main_course', 'Beef Stroganoff', 4],
    [2, 'main_course', 'Butternut Squash Ravioli', 5],
    [2, 'main_course', 'Vegetable Wraps', 6],
    [2, 'dessert', 'Berry Panna Cotta', 7],
    [2, 'dessert', 'Banana Fritters with Custard Sauce', 8],

    // ── Thursday (3) ──
    [3, 'appetizer', 'Tomato, Avocado, and Mango Bruschetta', 1],
    [3, 'soup', 'Roasted Butternut Squash Soup', 2],
    [3, 'main_course', 'Pizza of your Choice', 3],
    [3, 'main_course', 'Chicken Satay with Cajun Potato Wedges and Garden Salad', 4],
    [3, 'main_course', 'Thai Green Vegetable Curry', 5],
    [3, 'main_course', 'Veg Burger', 6],
    [3, 'dessert', 'Lemon Meringue Pie', 7],
    [3, 'dessert', 'Chocolate Fudge Cake', 8],

    // ── Friday (4) = Monday repeat ──
    [4, 'appetizer', 'Camembert and Caramelized Onion Bruschetta', 1],
    [4, 'soup', 'Carrot and Celery Soup', 2],
    [4, 'main_course', 'Kilimanjaro Beer-Battered Tilapia with Chips', 3],
    [4, 'main_course', 'Grilled Pork Chops with Sweet and Sour Sauce', 4],
    [4, 'main_course', 'Vegetable Quiche with Mixed Salad', 5],
    [4, 'main_course', 'Pasta with Tomato Pesto Sauce', 6],
    [4, 'dessert', 'Fresh Fruit Salad', 7],
    [4, 'dessert', 'Chocolate Mousse', 8],

    // ── Saturday (5) = Tuesday repeat ──
    [5, 'appetizer', 'Avocado Vinaigrette', 1],
    [5, 'soup', 'Leek and Potato Soup', 2],
    [5, 'main_course', 'Grilled Breast Chicken with Lyonnaise Potatoes and Salad', 3],
    [5, 'main_course', 'Spaghetti Bolognese with Garlic Toast', 4],
    [5, 'main_course', 'Stuffed Aubergine Rolls with Salad and Sweet Corn Salsa', 5],
    [5, 'main_course', 'Vegetable Cannelloni with Salad', 6],
    [5, 'dessert', 'Mango Sorbet', 7],
    [5, 'dessert', 'Coconut Cream Caramel', 8],

    // ── Sunday (6) = Wednesday repeat ──
    [6, 'appetizer', 'Camembert and Caramelized Onion Bruschetta', 1],
    [6, 'soup', 'Tomato Basil Soup with Croutons', 2],
    [6, 'main_course', 'Fish Nile Perch Paprika', 3],
    [6, 'main_course', 'Beef Stroganoff', 4],
    [6, 'main_course', 'Butternut Squash Ravioli', 5],
    [6, 'main_course', 'Vegetable Wraps', 6],
    [6, 'dessert', 'Berry Panna Cotta', 7],
    [6, 'dessert', 'Banana Fritters with Custard Sauce', 8],
];

// ── Dinner Menu (Mon=0 through Sun=6) ─────────────────────────────
$dinner = [
    // ── Monday (0) ──
    [0, 'appetizer', 'Vegetable Spring Rolls', 1],
    [0, 'soup', 'Cream of Broccoli Soup', 2],
    [0, 'main_course', 'Braised Lamb Chops', 3],
    [0, 'main_course', 'Grilled Breast Chicken with Lyonnaise Potatoes and Salad', 4],
    [0, 'main_course', 'Vegetarian Spaghetti Bolognaise', 5],
    [0, 'main_course', 'Red Kidney Beans in Coconut Sauce', 6],
    [0, 'dessert', 'Invisible Apple Cake', 7],
    [0, 'dessert', 'Passion and Cheddar Cheese Tart', 8],

    // ── Tuesday (1) ──
    [1, 'appetizer', 'Caprese Salad with Basil Pesto', 1],
    [1, 'soup', 'Pumpkin Soup', 2],
    [1, 'main_course', 'Grilled Beef Fillet', 3],
    [1, 'main_course', 'Pan-Fried Nile Perch Fillet', 4],
    [1, 'main_course', 'Stir-Fried Vegetables with Noodles or Rice', 5],
    [1, 'main_course', 'Vegetable Lasagne with Salad', 6],
    [1, 'dessert', 'Chocolate Brownies', 7],
    [1, 'dessert', 'Sticky Toffee Pudding', 8],

    // ── Wednesday (2) ──
    [2, 'appetizer', 'Curried Sweet Potato Samosas with Tomato Salsa', 1],
    [2, 'soup', 'Baby Marrow Soup', 2],
    [2, 'main_course', 'Grilled Pork Chop with Rice and Honey Mustard Sauce', 3],
    [2, 'main_course', 'One-Pot Garlic Chicken with Tagliatelle Pasta', 4],
    [2, 'main_course', 'Vegetable Ratatouille', 5],
    [2, 'main_course', 'Pasta Alfredo with Garlic Toast', 6],
    [2, 'dessert', 'Malva Pudding', 7],
    [2, 'dessert', 'Pineapple Upside-Down Cake', 8],

    // ── Thursday (3) ──
    [3, 'appetizer', 'Sliced Beetroot with Orange Segments and Feta Cheese', 1],
    [3, 'soup', 'Mixed Vegetable Soup', 2],
    [3, 'main_course', 'Tilapia Fish Fillet', 3],
    [3, 'main_course', 'Beef, Carrot and Potato Stew', 4],
    [3, 'main_course', 'Vegetable Risotto', 5],
    [3, 'main_course', 'Veg Moussaka', 6],
    [3, 'dessert', 'Apple Crumble with Custard Sauce', 7],
    [3, 'dessert', 'Lemon Cheesecake', 8],

    // ── Friday (4) = Monday repeat ──
    [4, 'appetizer', 'Vegetable Spring Rolls', 1],
    [4, 'soup', 'Cream of Broccoli Soup', 2],
    [4, 'main_course', 'Braised Lamb Chops', 3],
    [4, 'main_course', 'Grilled Breast Chicken with Lyonnaise Potatoes and Salad', 4],
    [4, 'main_course', 'Vegetarian Spaghetti Bolognaise', 5],
    [4, 'main_course', 'Red Kidney Beans in Coconut Sauce', 6],
    [4, 'dessert', 'Invisible Apple Cake', 7],
    [4, 'dessert', 'Passion and Cheddar Cheese Tart', 8],

    // ── Saturday (5) = Tuesday repeat ──
    [5, 'appetizer', 'Caprese Salad with Basil Pesto', 1],
    [5, 'soup', 'Pumpkin Soup', 2],
    [5, 'main_course', 'Grilled Beef Fillet', 3],
    [5, 'main_course', 'Pan-Fried Nile Perch Fillet', 4],
    [5, 'main_course', 'Stir-Fried Vegetables with Noodles or Rice', 5],
    [5, 'main_course', 'Vegetable Lasagne with Salad', 6],
    [5, 'dessert', 'Chocolate Brownies', 7],
    [5, 'dessert', 'Sticky Toffee Pudding', 8],

    // ── Sunday (6) = Wednesday repeat ──
    [6, 'appetizer', 'Curried Sweet Potato Samosas with Tomato Salsa', 1],
    [6, 'soup', 'Baby Marrow Soup', 2],
    [6, 'main_course', 'Grilled Pork Chop with Rice and Honey Mustard Sauce', 3],
    [6, 'main_course', 'One-Pot Garlic Chicken with Tagliatelle Pasta', 4],
    [6, 'main_course', 'Vegetable Ratatouille', 5],
    [6, 'main_course', 'Pasta Alfredo with Garlic Toast', 6],
    [6, 'dessert', 'Malva Pudding', 7],
    [6, 'dessert', 'Pineapple Upside-Down Cake', 8],
];

// ── Insert all entries ────────────────────────────────────────────

// First check if data already exists
$count = $pdo->query("SELECT COUNT(*) FROM kitchen_default_menu")->fetchColumn();
if ($count > 0) {
    jsonResponse(['message' => "kitchen_default_menu already has {$count} rows. Truncating and re-seeding."]);
    // Truncate to allow re-seeding
    $pdo->exec("TRUNCATE TABLE kitchen_default_menu");
}

// Build a recipe name lookup for auto-matching
$recipeMap = [];
try {
    $recipeRows = $pdo->query("SELECT id, LOWER(TRIM(name)) as lname FROM kitchen_recipes WHERE is_active = 1")->fetchAll();
    foreach ($recipeRows as $r) {
        $recipeMap[$r['lname']] = (int) $r['id'];
    }
} catch (Exception $e) {
    // kitchen_recipes may not exist yet — that's ok
}

$insertStmt = $pdo->prepare("
    INSERT INTO kitchen_default_menu (camp_id, day_of_week, meal_type, course, dish_name, sort_order, recipe_id, is_active, created_at)
    VALUES (NULL, ?, ?, ?, ?, ?, ?, 1, NOW())
");

$inserted = 0;
$matched = 0;

// Insert lunch dishes
foreach ($lunch as $item) {
    [$day, $course, $name, $sort] = $item;
    $recipeLookup = strtolower(trim($name));
    $recipeId = $recipeMap[$recipeLookup] ?? null;
    if ($recipeId) $matched++;
    $insertStmt->execute([$day, 'lunch', $course, $name, $sort, $recipeId]);
    $inserted++;
}

// Insert dinner dishes
foreach ($dinner as $item) {
    [$day, $course, $name, $sort] = $item;
    $recipeLookup = strtolower(trim($name));
    $recipeId = $recipeMap[$recipeLookup] ?? null;
    if ($recipeId) $matched++;
    $insertStmt->execute([$day, 'dinner', $course, $name, $sort, $recipeId]);
    $inserted++;
}

jsonResponse([
    'message' => "Seeded {$inserted} default menu entries ({$matched} matched to recipes)",
    'inserted' => $inserted,
    'recipe_matches' => $matched,
]);
