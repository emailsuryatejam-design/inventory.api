<?php
/**
 * KCL Stores — Seed Kitchen Recipes from Karibu Camps Menu
 *
 * POST /seed-recipes.php   — inserts ~40 master recipes with ingredients
 * Matches ingredient names to items table, skips unmatched.
 * Safe to run multiple times (checks for existing recipe by name).
 */

require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('POST only', 405);
}

$pdo = getDB();

// Helper: find best matching item_id for a given ingredient name
function findItem($pdo, $name) {
    // Try exact match first
    $stmt = $pdo->prepare("
        SELECT i.id, i.name, u.code as uom
        FROM items i
        JOIN item_groups ig ON i.item_group_id = ig.id
        LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
        WHERE i.is_active = 1 AND LOWER(i.name) = LOWER(?)
        LIMIT 1
    ");
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if ($row) return $row;

    // Try LIKE match
    $stmt = $pdo->prepare("
        SELECT i.id, i.name, u.code as uom
        FROM items i
        JOIN item_groups ig ON i.item_group_id = ig.id
        LEFT JOIN units_of_measure u ON i.stock_uom_id = u.id
        WHERE i.is_active = 1 AND LOWER(i.name) LIKE LOWER(?)
        ORDER BY LENGTH(i.name) ASC
        LIMIT 1
    ");
    $stmt->execute(["%{$name}%"]);
    return $stmt->fetch() ?: null;
}

// Check if recipe already exists
function recipeExists($pdo, $name) {
    $stmt = $pdo->prepare("SELECT id FROM kitchen_recipes WHERE LOWER(name) = LOWER(?) AND is_active = 1");
    $stmt->execute([$name]);
    return $stmt->fetchColumn();
}

// ═══════════════════════════════════════════════════════
// Karibu Camps Kitchen Recipes
// ═══════════════════════════════════════════════════════

$recipes = [
    // ── BREAKFAST ──
    [
        'name' => 'Eggs Benedict Safari Style',
        'description' => 'Classic eggs benedict with hollandaise sauce, served on toasted English muffins',
        'category' => 'breakfast',
        'cuisine' => 'Continental',
        'serves' => 4,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 20,
        'difficulty' => 'medium',
        'instructions' => [
            'Bring a pot of water to a gentle simmer with a splash of vinegar',
            'Toast English muffins and place on plates',
            'Poach eggs for 3-4 minutes until whites are set but yolks runny',
            'Make hollandaise: whisk egg yolks, lemon juice, and melted butter over double boiler',
            'Place ham or bacon on muffins, top with poached eggs',
            'Spoon hollandaise sauce over the top, garnish with chives',
        ],
        'ingredients' => [
            ['name' => 'Eggs', 'qty' => 2, 'primary' => 1, 'notes' => 'poached'],
            ['name' => 'Bread', 'qty' => 0.5, 'primary' => 1, 'notes' => 'English muffin or similar'],
            ['name' => 'Bacon', 'qty' => 0.5, 'primary' => 1, 'notes' => null],
            ['name' => 'Butter', 'qty' => 0.25, 'primary' => 0, 'notes' => 'for hollandaise'],
            ['name' => 'Lemon', 'qty' => 0.125, 'primary' => 0, 'notes' => 'juice only'],
        ],
    ],
    [
        'name' => 'Bush Breakfast Fry-Up',
        'description' => 'Full English-style safari breakfast with eggs, sausages, bacon, beans, and toast',
        'category' => 'breakfast',
        'cuisine' => 'Continental',
        'serves' => 4,
        'prep_time_minutes' => 10,
        'cook_time_minutes' => 25,
        'difficulty' => 'easy',
        'instructions' => [
            'Grill or pan-fry sausages until golden brown and cooked through',
            'Fry bacon until crispy',
            'Fry or scramble eggs to order',
            'Heat baked beans in a saucepan',
            'Grill tomato halves and mushrooms',
            'Toast bread and serve everything together on warm plates',
        ],
        'ingredients' => [
            ['name' => 'Eggs', 'qty' => 2, 'primary' => 1, 'notes' => 'fried or scrambled'],
            ['name' => 'Sausage', 'qty' => 1, 'primary' => 1, 'notes' => null],
            ['name' => 'Bacon', 'qty' => 0.5, 'primary' => 1, 'notes' => null],
            ['name' => 'Baked Beans', 'qty' => 0.5, 'primary' => 0, 'notes' => 'canned'],
            ['name' => 'Tomato', 'qty' => 0.5, 'primary' => 0, 'notes' => 'halved, grilled'],
            ['name' => 'Mushroom', 'qty' => 0.25, 'primary' => 0, 'notes' => null],
            ['name' => 'Bread', 'qty' => 0.5, 'primary' => 0, 'notes' => 'toast'],
        ],
    ],
    [
        'name' => 'Tropical Fruit Pancakes',
        'description' => 'Fluffy pancakes topped with mango, passion fruit, banana and honey',
        'category' => 'breakfast',
        'cuisine' => 'Continental',
        'serves' => 4,
        'prep_time_minutes' => 10,
        'cook_time_minutes' => 15,
        'difficulty' => 'easy',
        'instructions' => [
            'Mix flour, eggs, milk, sugar, and a pinch of salt to make batter',
            'Let batter rest for 5 minutes',
            'Heat a non-stick pan with a little butter',
            'Pour ladles of batter and cook until bubbles form, then flip',
            'Slice tropical fruits for topping',
            'Stack pancakes, top with fruit, drizzle with honey',
        ],
        'ingredients' => [
            ['name' => 'Flour', 'qty' => 0.75, 'primary' => 1, 'notes' => null],
            ['name' => 'Eggs', 'qty' => 1, 'primary' => 1, 'notes' => null],
            ['name' => 'Milk', 'qty' => 0.5, 'primary' => 1, 'notes' => null],
            ['name' => 'Banana', 'qty' => 0.5, 'primary' => 0, 'notes' => 'sliced'],
            ['name' => 'Mango', 'qty' => 0.25, 'primary' => 0, 'notes' => 'diced'],
            ['name' => 'Honey', 'qty' => 0.125, 'primary' => 0, 'notes' => 'drizzle'],
            ['name' => 'Sugar', 'qty' => 0.125, 'primary' => 0, 'notes' => null],
        ],
    ],
    [
        'name' => 'Masala Omelette',
        'description' => 'Spiced omelette with onions, tomatoes, chilies and coriander',
        'category' => 'breakfast',
        'cuisine' => 'Indian',
        'serves' => 2,
        'prep_time_minutes' => 5,
        'cook_time_minutes' => 8,
        'difficulty' => 'easy',
        'instructions' => [
            'Beat eggs with a pinch of turmeric and salt',
            'Dice onion, tomato, and green chili finely',
            'Mix vegetables into the egg mixture',
            'Heat oil in a pan, pour egg mixture',
            'Cook on medium heat until set, fold over',
            'Garnish with fresh coriander leaves',
        ],
        'ingredients' => [
            ['name' => 'Eggs', 'qty' => 3, 'primary' => 1, 'notes' => null],
            ['name' => 'Onion', 'qty' => 0.5, 'primary' => 0, 'notes' => 'diced'],
            ['name' => 'Tomato', 'qty' => 0.5, 'primary' => 0, 'notes' => 'diced'],
            ['name' => 'Green Chili', 'qty' => 0.25, 'primary' => 0, 'notes' => 'minced'],
            ['name' => 'Turmeric', 'qty' => 0.05, 'primary' => 0, 'notes' => 'pinch'],
            ['name' => 'Coriander', 'qty' => 0.05, 'primary' => 0, 'notes' => 'fresh, garnish'],
        ],
    ],

    // ── SOUPS ──
    [
        'name' => 'Butternut Squash Soup',
        'description' => 'Creamy roasted butternut soup with ginger and nutmeg, a Karibu classic',
        'category' => 'soup',
        'cuisine' => 'Continental',
        'serves' => 6,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 35,
        'difficulty' => 'easy',
        'instructions' => [
            'Peel and cube butternut squash',
            'Sauté diced onion and garlic in butter until soft',
            'Add butternut, chicken stock, ginger, and nutmeg',
            'Simmer for 25-30 minutes until butternut is tender',
            'Blend until smooth using an immersion blender',
            'Stir in cream, season with salt and pepper',
            'Serve with a swirl of cream and crusty bread',
        ],
        'ingredients' => [
            ['name' => 'Butternut', 'qty' => 0.5, 'primary' => 1, 'notes' => 'peeled, cubed'],
            ['name' => 'Onion', 'qty' => 0.17, 'primary' => 0, 'notes' => 'diced'],
            ['name' => 'Garlic', 'qty' => 0.05, 'primary' => 0, 'notes' => 'minced'],
            ['name' => 'Chicken Stock', 'qty' => 0.5, 'primary' => 0, 'notes' => null],
            ['name' => 'Cream', 'qty' => 0.1, 'primary' => 0, 'notes' => null],
            ['name' => 'Ginger', 'qty' => 0.05, 'primary' => 0, 'notes' => 'fresh, grated'],
        ],
    ],
    [
        'name' => 'Tomato Basil Soup',
        'description' => 'Classic tomato soup with fresh basil and a touch of cream',
        'category' => 'soup',
        'cuisine' => 'Continental',
        'serves' => 6,
        'prep_time_minutes' => 10,
        'cook_time_minutes' => 30,
        'difficulty' => 'easy',
        'instructions' => [
            'Sauté onion and garlic in olive oil until translucent',
            'Add canned tomatoes and vegetable stock',
            'Season with sugar, salt, and pepper',
            'Simmer for 20 minutes',
            'Add fresh basil leaves, blend until smooth',
            'Finish with a splash of cream and serve hot',
        ],
        'ingredients' => [
            ['name' => 'Tomato', 'qty' => 0.75, 'primary' => 1, 'notes' => 'canned or fresh'],
            ['name' => 'Onion', 'qty' => 0.17, 'primary' => 0, 'notes' => 'diced'],
            ['name' => 'Garlic', 'qty' => 0.05, 'primary' => 0, 'notes' => 'minced'],
            ['name' => 'Basil', 'qty' => 0.05, 'primary' => 0, 'notes' => 'fresh leaves'],
            ['name' => 'Cream', 'qty' => 0.1, 'primary' => 0, 'notes' => 'optional'],
            ['name' => 'Sugar', 'qty' => 0.05, 'primary' => 0, 'notes' => 'pinch'],
        ],
    ],

    // ── SALADS ──
    [
        'name' => 'Caesar Salad',
        'description' => 'Crisp romaine lettuce with Caesar dressing, croutons, and parmesan',
        'category' => 'salad',
        'cuisine' => 'Continental',
        'serves' => 4,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 10,
        'difficulty' => 'easy',
        'instructions' => [
            'Wash and chop romaine lettuce into bite-sized pieces',
            'Make croutons: cube bread, toss with olive oil and herbs, toast in oven',
            'Prepare dressing: blend garlic, anchovies, lemon juice, mustard, egg yolk, and olive oil',
            'Toss lettuce with dressing',
            'Top with croutons and shaved parmesan',
            'Add grilled chicken strips if desired',
        ],
        'ingredients' => [
            ['name' => 'Lettuce', 'qty' => 0.5, 'primary' => 1, 'notes' => 'romaine'],
            ['name' => 'Parmesan', 'qty' => 0.125, 'primary' => 0, 'notes' => 'shaved'],
            ['name' => 'Bread', 'qty' => 0.25, 'primary' => 0, 'notes' => 'for croutons'],
            ['name' => 'Lemon', 'qty' => 0.125, 'primary' => 0, 'notes' => 'juice'],
            ['name' => 'Garlic', 'qty' => 0.05, 'primary' => 0, 'notes' => 'minced'],
            ['name' => 'Chicken', 'qty' => 0.25, 'primary' => 0, 'notes' => 'grilled, optional'],
        ],
    ],
    [
        'name' => 'Greek Salad',
        'description' => 'Fresh Mediterranean salad with cucumber, tomatoes, olives, and feta',
        'category' => 'salad',
        'cuisine' => 'Mediterranean',
        'serves' => 4,
        'prep_time_minutes' => 10,
        'cook_time_minutes' => 0,
        'difficulty' => 'easy',
        'instructions' => [
            'Dice cucumber, tomatoes, and red onion into chunks',
            'Slice olives and crumble feta cheese',
            'Combine vegetables in a large bowl',
            'Dress with olive oil, lemon juice, dried oregano, salt and pepper',
            'Top with feta and olives, serve immediately',
        ],
        'ingredients' => [
            ['name' => 'Cucumber', 'qty' => 0.5, 'primary' => 1, 'notes' => 'diced'],
            ['name' => 'Tomato', 'qty' => 0.5, 'primary' => 1, 'notes' => 'diced'],
            ['name' => 'Feta', 'qty' => 0.125, 'primary' => 0, 'notes' => 'crumbled'],
            ['name' => 'Olives', 'qty' => 0.1, 'primary' => 0, 'notes' => 'sliced'],
            ['name' => 'Red Onion', 'qty' => 0.125, 'primary' => 0, 'notes' => 'sliced'],
            ['name' => 'Lemon', 'qty' => 0.125, 'primary' => 0, 'notes' => 'juice'],
        ],
    ],

    // ── APPETIZERS ──
    [
        'name' => 'Zanzibar Prawn Cocktail',
        'description' => 'Chilled prawns in a tangy cocktail sauce with avocado',
        'category' => 'appetizer',
        'cuisine' => 'African',
        'serves' => 4,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 5,
        'difficulty' => 'easy',
        'instructions' => [
            'Cook prawns in salted boiling water for 3-4 minutes, then ice-bath',
            'Mix cocktail sauce: ketchup, horseradish, lemon juice, Tabasco',
            'Dice avocado and arrange in serving glasses',
            'Place prawns on top of avocado',
            'Drizzle with cocktail sauce',
            'Garnish with lemon wedge and fresh dill',
        ],
        'ingredients' => [
            ['name' => 'Prawn', 'qty' => 1, 'primary' => 1, 'notes' => 'peeled, deveined'],
            ['name' => 'Avocado', 'qty' => 0.5, 'primary' => 0, 'notes' => 'diced'],
            ['name' => 'Ketchup', 'qty' => 0.1, 'primary' => 0, 'notes' => 'for sauce'],
            ['name' => 'Lemon', 'qty' => 0.25, 'primary' => 0, 'notes' => null],
        ],
    ],
    [
        'name' => 'Samosa',
        'description' => 'Crispy pastry triangles filled with spiced potatoes and peas',
        'category' => 'appetizer',
        'cuisine' => 'Indian',
        'serves' => 6,
        'prep_time_minutes' => 30,
        'cook_time_minutes' => 20,
        'difficulty' => 'medium',
        'instructions' => [
            'Boil potatoes until tender, mash roughly',
            'Cook peas, mix with mashed potato',
            'Season with cumin, coriander, turmeric, chili, garam masala',
            'Make dough with flour, oil, and water, roll out thin circles',
            'Cut circles in half, form cones, fill with potato mixture',
            'Seal edges with water, deep fry until golden',
            'Serve with mint chutney and tamarind sauce',
        ],
        'ingredients' => [
            ['name' => 'Potato', 'qty' => 0.5, 'primary' => 1, 'notes' => 'boiled, mashed'],
            ['name' => 'Peas', 'qty' => 0.17, 'primary' => 0, 'notes' => 'green peas'],
            ['name' => 'Flour', 'qty' => 0.25, 'primary' => 1, 'notes' => 'for pastry'],
            ['name' => 'Cumin', 'qty' => 0.05, 'primary' => 0, 'notes' => 'ground'],
            ['name' => 'Garam Masala', 'qty' => 0.05, 'primary' => 0, 'notes' => null],
        ],
    ],

    // ── MAIN COURSES ──
    [
        'name' => 'Grilled Chicken with Herb Butter',
        'description' => 'Tender grilled chicken breast with herb compound butter and roasted vegetables',
        'category' => 'main_course',
        'cuisine' => 'Continental',
        'serves' => 4,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 25,
        'difficulty' => 'medium',
        'instructions' => [
            'Marinate chicken breasts with olive oil, garlic, and herbs for 30 minutes',
            'Prepare herb butter: mix softened butter with parsley, thyme, and garlic',
            'Grill chicken on high heat for 6-7 minutes per side',
            'Rest chicken for 5 minutes, top with a knob of herb butter',
            'Serve with roasted vegetables and mashed potatoes',
        ],
        'ingredients' => [
            ['name' => 'Chicken', 'qty' => 1, 'primary' => 1, 'notes' => 'breast'],
            ['name' => 'Butter', 'qty' => 0.25, 'primary' => 0, 'notes' => 'for herb butter'],
            ['name' => 'Garlic', 'qty' => 0.05, 'primary' => 0, 'notes' => 'minced'],
            ['name' => 'Thyme', 'qty' => 0.02, 'primary' => 0, 'notes' => 'fresh'],
            ['name' => 'Parsley', 'qty' => 0.02, 'primary' => 0, 'notes' => 'fresh, chopped'],
        ],
    ],
    [
        'name' => 'Nyama Choma',
        'description' => 'Kenyan-style grilled meat with kachumbari salad and ugali',
        'category' => 'main_course',
        'cuisine' => 'African',
        'serves' => 4,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 40,
        'difficulty' => 'medium',
        'instructions' => [
            'Season beef or goat with salt, garlic, and lemon juice',
            'Grill over charcoal, turning occasionally, until cooked through',
            'Make kachumbari: dice tomatoes, onions, cilantro, mix with lime juice',
            'Prepare ugali: bring water to boil, gradually add maize flour while stirring',
            'Stir ugali vigorously until thick and pulls away from pot',
            'Slice meat, serve with ugali and kachumbari',
        ],
        'ingredients' => [
            ['name' => 'Beef', 'qty' => 1.5, 'primary' => 1, 'notes' => 'steak cuts or ribs'],
            ['name' => 'Maize Flour', 'qty' => 0.5, 'primary' => 1, 'notes' => 'for ugali'],
            ['name' => 'Tomato', 'qty' => 0.5, 'primary' => 0, 'notes' => 'for kachumbari'],
            ['name' => 'Onion', 'qty' => 0.25, 'primary' => 0, 'notes' => 'for kachumbari'],
            ['name' => 'Lemon', 'qty' => 0.25, 'primary' => 0, 'notes' => 'juice'],
        ],
    ],
    [
        'name' => 'Pan-Seared Fish with Lemon Caper Sauce',
        'description' => 'Fresh fish fillet pan-seared with a buttery lemon and caper sauce',
        'category' => 'main_course',
        'cuisine' => 'Continental',
        'serves' => 4,
        'prep_time_minutes' => 10,
        'cook_time_minutes' => 15,
        'difficulty' => 'medium',
        'instructions' => [
            'Pat fish fillets dry, season with salt and pepper',
            'Heat oil in a skillet over high heat',
            'Sear fish skin-side down for 4 minutes until crispy',
            'Flip and cook 2-3 more minutes',
            'Remove fish, add butter, capers, lemon juice to pan',
            'Swirl to make sauce, pour over fish',
            'Garnish with fresh parsley',
        ],
        'ingredients' => [
            ['name' => 'Fish', 'qty' => 1, 'primary' => 1, 'notes' => 'fillet, tilapia or similar'],
            ['name' => 'Lemon', 'qty' => 0.25, 'primary' => 0, 'notes' => 'juice and zest'],
            ['name' => 'Capers', 'qty' => 0.05, 'primary' => 0, 'notes' => null],
            ['name' => 'Butter', 'qty' => 0.125, 'primary' => 0, 'notes' => null],
            ['name' => 'Parsley', 'qty' => 0.02, 'primary' => 0, 'notes' => 'fresh'],
        ],
    ],
    [
        'name' => 'Chicken Tikka Masala',
        'description' => 'Tender chicken pieces in a creamy spiced tomato sauce, served with rice or naan',
        'category' => 'main_course',
        'cuisine' => 'Indian',
        'serves' => 4,
        'prep_time_minutes' => 20,
        'cook_time_minutes' => 35,
        'difficulty' => 'medium',
        'instructions' => [
            'Marinate chicken cubes in yogurt, turmeric, cumin, paprika for 1 hour',
            'Grill or pan-fry marinated chicken until charred',
            'Sauté onion, garlic, ginger until golden',
            'Add tomato puree, garam masala, chili powder, cook down',
            'Add cream, simmer for 10 minutes',
            'Add grilled chicken, simmer 5 more minutes',
            'Garnish with coriander, serve with basmati rice or naan',
        ],
        'ingredients' => [
            ['name' => 'Chicken', 'qty' => 1, 'primary' => 1, 'notes' => 'cubed breast or thigh'],
            ['name' => 'Yogurt', 'qty' => 0.25, 'primary' => 0, 'notes' => 'plain, for marinade'],
            ['name' => 'Tomato', 'qty' => 0.5, 'primary' => 1, 'notes' => 'puree'],
            ['name' => 'Cream', 'qty' => 0.2, 'primary' => 0, 'notes' => null],
            ['name' => 'Onion', 'qty' => 0.25, 'primary' => 0, 'notes' => 'diced'],
            ['name' => 'Garlic', 'qty' => 0.05, 'primary' => 0, 'notes' => 'minced'],
            ['name' => 'Ginger', 'qty' => 0.05, 'primary' => 0, 'notes' => 'grated'],
            ['name' => 'Garam Masala', 'qty' => 0.05, 'primary' => 0, 'notes' => null],
            ['name' => 'Rice', 'qty' => 0.5, 'primary' => 0, 'notes' => 'basmati'],
        ],
    ],
    [
        'name' => 'Beef Stew with Root Vegetables',
        'description' => 'Slow-cooked tender beef with carrots, potatoes, and rich gravy',
        'category' => 'main_course',
        'cuisine' => 'Continental',
        'serves' => 6,
        'prep_time_minutes' => 20,
        'cook_time_minutes' => 120,
        'difficulty' => 'medium',
        'instructions' => [
            'Cut beef into 2cm cubes, season with salt and pepper',
            'Brown beef in batches in a hot pot with oil',
            'Sauté onions, carrots, and celery until softened',
            'Add tomato paste, beef stock, and red wine',
            'Return beef, add potatoes and herbs',
            'Simmer on low heat for 2 hours until beef is tender',
            'Thicken gravy with flour if needed, serve hot',
        ],
        'ingredients' => [
            ['name' => 'Beef', 'qty' => 1, 'primary' => 1, 'notes' => 'stewing cuts, cubed'],
            ['name' => 'Potato', 'qty' => 0.5, 'primary' => 0, 'notes' => 'cubed'],
            ['name' => 'Carrot', 'qty' => 0.25, 'primary' => 0, 'notes' => 'sliced'],
            ['name' => 'Onion', 'qty' => 0.25, 'primary' => 0, 'notes' => 'diced'],
            ['name' => 'Tomato Paste', 'qty' => 0.1, 'primary' => 0, 'notes' => null],
            ['name' => 'Beef Stock', 'qty' => 0.5, 'primary' => 0, 'notes' => null],
            ['name' => 'Flour', 'qty' => 0.1, 'primary' => 0, 'notes' => 'for thickening'],
        ],
    ],
    [
        'name' => 'Lamb Rack with Rosemary Jus',
        'description' => 'Herb-crusted rack of lamb with rosemary red wine reduction',
        'category' => 'main_course',
        'cuisine' => 'Continental',
        'serves' => 4,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 30,
        'difficulty' => 'hard',
        'instructions' => [
            'French-trim the lamb rack, season well',
            'Sear in a hot pan on all sides until browned',
            'Make herb crust: breadcrumbs, rosemary, garlic, mustard',
            'Press crust onto the fat side of the lamb',
            'Roast at 200°C for 15-20 minutes for medium-rare',
            'Rest for 10 minutes',
            'Make jus with pan drippings, red wine, rosemary, and stock',
            'Slice into cutlets and drizzle with jus',
        ],
        'ingredients' => [
            ['name' => 'Lamb', 'qty' => 1, 'primary' => 1, 'notes' => 'rack'],
            ['name' => 'Rosemary', 'qty' => 0.05, 'primary' => 0, 'notes' => 'fresh'],
            ['name' => 'Garlic', 'qty' => 0.05, 'primary' => 0, 'notes' => 'minced'],
            ['name' => 'Bread', 'qty' => 0.25, 'primary' => 0, 'notes' => 'breadcrumbs'],
            ['name' => 'Mustard', 'qty' => 0.05, 'primary' => 0, 'notes' => 'Dijon'],
            ['name' => 'Red Wine', 'qty' => 0.25, 'primary' => 0, 'notes' => 'for jus'],
        ],
    ],
    [
        'name' => 'Pasta Carbonara',
        'description' => 'Classic Italian pasta with pancetta, egg, parmesan, and black pepper',
        'category' => 'main_course',
        'cuisine' => 'Italian',
        'serves' => 4,
        'prep_time_minutes' => 10,
        'cook_time_minutes' => 20,
        'difficulty' => 'medium',
        'instructions' => [
            'Cook spaghetti in salted boiling water until al dente',
            'Fry pancetta or bacon until crispy',
            'Beat eggs with grated parmesan and black pepper',
            'Drain pasta, reserve some pasta water',
            'Toss hot pasta with pancetta, then quickly mix in egg mixture off heat',
            'Add pasta water as needed for creamy consistency',
            'Serve immediately with extra parmesan',
        ],
        'ingredients' => [
            ['name' => 'Pasta', 'qty' => 0.5, 'primary' => 1, 'notes' => 'spaghetti'],
            ['name' => 'Bacon', 'qty' => 0.25, 'primary' => 1, 'notes' => 'or pancetta'],
            ['name' => 'Eggs', 'qty' => 1, 'primary' => 1, 'notes' => null],
            ['name' => 'Parmesan', 'qty' => 0.125, 'primary' => 0, 'notes' => 'grated'],
        ],
    ],
    [
        'name' => 'Swahili Fish Curry',
        'description' => 'Coconut-based fish curry with Swahili spices, served with coconut rice',
        'category' => 'main_course',
        'cuisine' => 'African',
        'serves' => 4,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 25,
        'difficulty' => 'medium',
        'instructions' => [
            'Cut fish into large chunks, season with turmeric, salt, lime',
            'Sauté onion, garlic, ginger until fragrant',
            'Add tomatoes, curry powder, cumin, coriander',
            'Pour in coconut milk, simmer 10 minutes',
            'Add fish pieces, cook gently for 8-10 minutes',
            'Do not stir too much to keep fish intact',
            'Make coconut rice: cook rice with coconut milk instead of water',
            'Garnish with fresh cilantro and serve with rice',
        ],
        'ingredients' => [
            ['name' => 'Fish', 'qty' => 1, 'primary' => 1, 'notes' => 'white fish, chunked'],
            ['name' => 'Coconut Milk', 'qty' => 0.5, 'primary' => 1, 'notes' => null],
            ['name' => 'Tomato', 'qty' => 0.25, 'primary' => 0, 'notes' => 'diced'],
            ['name' => 'Onion', 'qty' => 0.25, 'primary' => 0, 'notes' => 'sliced'],
            ['name' => 'Rice', 'qty' => 0.5, 'primary' => 0, 'notes' => 'for coconut rice'],
            ['name' => 'Curry Powder', 'qty' => 0.05, 'primary' => 0, 'notes' => null],
            ['name' => 'Garlic', 'qty' => 0.05, 'primary' => 0, 'notes' => 'minced'],
            ['name' => 'Ginger', 'qty' => 0.05, 'primary' => 0, 'notes' => 'grated'],
        ],
    ],
    [
        'name' => 'Pilau Rice',
        'description' => 'Fragrant Swahili spiced rice with meat and whole spices',
        'category' => 'main_course',
        'cuisine' => 'African',
        'serves' => 6,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 40,
        'difficulty' => 'medium',
        'instructions' => [
            'Fry onions until deep golden brown',
            'Add whole spices: cardamom, cloves, cinnamon stick, cumin seeds',
            'Add meat cubes, cook until browned',
            'Add garlic, ginger, tomato paste',
            'Pour in stock, bring to boil',
            'Add washed rice, reduce heat to low',
            'Cover tightly and cook for 20 minutes without lifting lid',
            'Fluff with fork and serve',
        ],
        'ingredients' => [
            ['name' => 'Rice', 'qty' => 0.5, 'primary' => 1, 'notes' => 'basmati'],
            ['name' => 'Beef', 'qty' => 0.5, 'primary' => 1, 'notes' => 'cubed'],
            ['name' => 'Onion', 'qty' => 0.5, 'primary' => 0, 'notes' => 'sliced thin'],
            ['name' => 'Garlic', 'qty' => 0.05, 'primary' => 0, 'notes' => 'minced'],
            ['name' => 'Ginger', 'qty' => 0.05, 'primary' => 0, 'notes' => 'grated'],
            ['name' => 'Tomato Paste', 'qty' => 0.05, 'primary' => 0, 'notes' => null],
            ['name' => 'Cardamom', 'qty' => 0.02, 'primary' => 0, 'notes' => 'whole pods'],
            ['name' => 'Cinnamon', 'qty' => 0.02, 'primary' => 0, 'notes' => 'stick'],
        ],
    ],
    [
        'name' => 'Beef Lasagna',
        'description' => 'Layered pasta with rich Bolognese sauce, bechamel and melted cheese',
        'category' => 'main_course',
        'cuisine' => 'Italian',
        'serves' => 8,
        'prep_time_minutes' => 30,
        'cook_time_minutes' => 45,
        'difficulty' => 'medium',
        'instructions' => [
            'Make Bolognese: sauté onion, garlic, carrot, celery, add minced beef',
            'Add canned tomatoes, tomato paste, herbs, simmer 30 minutes',
            'Make béchamel: melt butter, add flour, gradually whisk in milk',
            'Layer in baking dish: Bolognese, pasta sheets, béchamel, cheese',
            'Repeat layers 3 times, top with cheese',
            'Bake at 180°C for 40-45 minutes until golden and bubbling',
            'Rest 10 minutes before cutting',
        ],
        'ingredients' => [
            ['name' => 'Beef Mince', 'qty' => 0.5, 'primary' => 1, 'notes' => 'ground beef'],
            ['name' => 'Pasta', 'qty' => 0.25, 'primary' => 1, 'notes' => 'lasagna sheets'],
            ['name' => 'Tomato', 'qty' => 0.375, 'primary' => 0, 'notes' => 'canned'],
            ['name' => 'Cheese', 'qty' => 0.25, 'primary' => 0, 'notes' => 'mozzarella or cheddar'],
            ['name' => 'Milk', 'qty' => 0.25, 'primary' => 0, 'notes' => 'for béchamel'],
            ['name' => 'Onion', 'qty' => 0.125, 'primary' => 0, 'notes' => 'diced'],
            ['name' => 'Carrot', 'qty' => 0.125, 'primary' => 0, 'notes' => 'diced'],
            ['name' => 'Flour', 'qty' => 0.05, 'primary' => 0, 'notes' => 'for béchamel'],
        ],
    ],
    [
        'name' => 'Grilled Pork Chops with Apple Sauce',
        'description' => 'Juicy pork chops with a sweet and tangy apple compote',
        'category' => 'main_course',
        'cuisine' => 'Continental',
        'serves' => 4,
        'prep_time_minutes' => 10,
        'cook_time_minutes' => 20,
        'difficulty' => 'easy',
        'instructions' => [
            'Season pork chops with salt, pepper, and paprika',
            'Grill on medium-high heat for 5-6 minutes per side',
            'Peel and dice apples, cook with butter, sugar, and cinnamon',
            'Simmer apple sauce until soft and slightly chunky',
            'Rest pork for 5 minutes, serve with apple sauce',
        ],
        'ingredients' => [
            ['name' => 'Pork', 'qty' => 1, 'primary' => 1, 'notes' => 'chops'],
            ['name' => 'Apple', 'qty' => 0.5, 'primary' => 0, 'notes' => 'for sauce'],
            ['name' => 'Cinnamon', 'qty' => 0.02, 'primary' => 0, 'notes' => 'ground'],
            ['name' => 'Sugar', 'qty' => 0.05, 'primary' => 0, 'notes' => null],
            ['name' => 'Butter', 'qty' => 0.05, 'primary' => 0, 'notes' => null],
        ],
    ],

    // ── SIDES ──
    [
        'name' => 'Roasted Vegetables',
        'description' => 'Seasonal vegetables roasted with herbs and olive oil',
        'category' => 'side',
        'cuisine' => 'Continental',
        'serves' => 4,
        'prep_time_minutes' => 10,
        'cook_time_minutes' => 30,
        'difficulty' => 'easy',
        'instructions' => [
            'Cut vegetables into similar-sized pieces',
            'Toss with olive oil, salt, pepper, and dried herbs',
            'Spread on baking tray in single layer',
            'Roast at 200°C for 25-30 minutes, turning halfway',
            'Serve as a side dish',
        ],
        'ingredients' => [
            ['name' => 'Courgette', 'qty' => 0.25, 'primary' => 0, 'notes' => 'chunked'],
            ['name' => 'Bell Pepper', 'qty' => 0.25, 'primary' => 0, 'notes' => 'chunked'],
            ['name' => 'Carrot', 'qty' => 0.25, 'primary' => 0, 'notes' => 'chunked'],
            ['name' => 'Onion', 'qty' => 0.25, 'primary' => 0, 'notes' => 'wedges'],
        ],
    ],
    [
        'name' => 'Garlic Mashed Potato',
        'description' => 'Creamy mashed potatoes with roasted garlic and butter',
        'category' => 'side',
        'cuisine' => 'Continental',
        'serves' => 4,
        'prep_time_minutes' => 10,
        'cook_time_minutes' => 25,
        'difficulty' => 'easy',
        'instructions' => [
            'Peel and cut potatoes into chunks',
            'Boil in salted water until tender (about 20 minutes)',
            'Roast garlic cloves in oven until soft and golden',
            'Drain potatoes, mash with butter, warm milk, and roasted garlic',
            'Season with salt and white pepper',
            'Serve smooth or slightly textured',
        ],
        'ingredients' => [
            ['name' => 'Potato', 'qty' => 1, 'primary' => 1, 'notes' => null],
            ['name' => 'Garlic', 'qty' => 0.1, 'primary' => 0, 'notes' => 'roasted'],
            ['name' => 'Butter', 'qty' => 0.125, 'primary' => 0, 'notes' => null],
            ['name' => 'Milk', 'qty' => 0.125, 'primary' => 0, 'notes' => 'warm'],
        ],
    ],
    [
        'name' => 'Chapati',
        'description' => 'Soft layered East African flatbread',
        'category' => 'bread',
        'cuisine' => 'African',
        'serves' => 6,
        'prep_time_minutes' => 20,
        'cook_time_minutes' => 20,
        'difficulty' => 'medium',
        'instructions' => [
            'Mix flour with warm water and a pinch of salt to form dough',
            'Knead for 10 minutes until smooth and elastic',
            'Rest dough covered for 15 minutes',
            'Divide into balls, roll each thin on floured surface',
            'Brush with oil, fold and re-roll for layers',
            'Cook on hot flat pan, brushing with oil until golden on both sides',
        ],
        'ingredients' => [
            ['name' => 'Flour', 'qty' => 0.5, 'primary' => 1, 'notes' => null],
        ],
    ],
    [
        'name' => 'Naan Bread',
        'description' => 'Soft Indian bread baked with garlic butter',
        'category' => 'bread',
        'cuisine' => 'Indian',
        'serves' => 6,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 15,
        'difficulty' => 'medium',
        'instructions' => [
            'Mix flour, yeast, sugar, salt, yogurt and warm water',
            'Knead into soft dough, rest for 1 hour to rise',
            'Divide into portions, roll into oval shapes',
            'Cook on very hot pan or tandoor until puffed and charred',
            'Brush with garlic butter immediately',
            'Serve warm alongside curries',
        ],
        'ingredients' => [
            ['name' => 'Flour', 'qty' => 0.5, 'primary' => 1, 'notes' => null],
            ['name' => 'Yogurt', 'qty' => 0.1, 'primary' => 0, 'notes' => null],
            ['name' => 'Yeast', 'qty' => 0.02, 'primary' => 0, 'notes' => 'dried'],
            ['name' => 'Garlic', 'qty' => 0.05, 'primary' => 0, 'notes' => 'minced, for butter'],
            ['name' => 'Butter', 'qty' => 0.1, 'primary' => 0, 'notes' => 'for brushing'],
        ],
    ],

    // ── DESSERTS ──
    [
        'name' => 'Chocolate Lava Cake',
        'description' => 'Rich individual chocolate cakes with a molten center',
        'category' => 'dessert',
        'cuisine' => 'Continental',
        'serves' => 4,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 12,
        'difficulty' => 'hard',
        'instructions' => [
            'Melt dark chocolate and butter together',
            'Whisk eggs and sugar until thick and pale',
            'Fold chocolate mixture into eggs',
            'Add a small amount of flour, fold gently',
            'Pour into greased ramekins',
            'Bake at 200°C for exactly 10-12 minutes',
            'Turn out onto plates immediately, dust with cocoa powder',
            'Serve with vanilla ice cream',
        ],
        'ingredients' => [
            ['name' => 'Chocolate', 'qty' => 0.5, 'primary' => 1, 'notes' => 'dark, 70%'],
            ['name' => 'Butter', 'qty' => 0.25, 'primary' => 0, 'notes' => null],
            ['name' => 'Eggs', 'qty' => 1, 'primary' => 1, 'notes' => null],
            ['name' => 'Sugar', 'qty' => 0.25, 'primary' => 0, 'notes' => null],
            ['name' => 'Flour', 'qty' => 0.1, 'primary' => 0, 'notes' => null],
            ['name' => 'Cocoa', 'qty' => 0.05, 'primary' => 0, 'notes' => 'for dusting'],
        ],
    ],
    [
        'name' => 'Crème Brûlée',
        'description' => 'Classic vanilla custard with a caramelized sugar crust',
        'category' => 'dessert',
        'cuisine' => 'Continental',
        'serves' => 4,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 45,
        'difficulty' => 'hard',
        'instructions' => [
            'Heat cream with vanilla seeds and pod until just simmering',
            'Whisk egg yolks with sugar until pale',
            'Slowly pour warm cream into egg mixture, whisking constantly',
            'Strain into ramekins',
            'Bake in water bath at 150°C for 40-45 minutes',
            'Chill completely (at least 4 hours)',
            'Sprinkle sugar on top, caramelize with torch',
        ],
        'ingredients' => [
            ['name' => 'Cream', 'qty' => 0.5, 'primary' => 1, 'notes' => 'heavy/double'],
            ['name' => 'Eggs', 'qty' => 1, 'primary' => 1, 'notes' => 'yolks only'],
            ['name' => 'Sugar', 'qty' => 0.25, 'primary' => 0, 'notes' => null],
            ['name' => 'Vanilla', 'qty' => 0.02, 'primary' => 0, 'notes' => 'extract or pod'],
        ],
    ],
    [
        'name' => 'Tropical Fruit Salad',
        'description' => 'Fresh seasonal tropical fruits with passion fruit dressing and mint',
        'category' => 'dessert',
        'cuisine' => 'African',
        'serves' => 6,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 0,
        'difficulty' => 'easy',
        'instructions' => [
            'Peel and dice mango, papaya, pineapple into bite-sized pieces',
            'Slice bananas, halve passion fruits',
            'Combine all fruits in a large bowl',
            'Scoop passion fruit pulp over the top',
            'Drizzle with honey and a squeeze of lime',
            'Garnish with fresh mint leaves',
            'Chill before serving',
        ],
        'ingredients' => [
            ['name' => 'Mango', 'qty' => 0.25, 'primary' => 1, 'notes' => 'diced'],
            ['name' => 'Pineapple', 'qty' => 0.25, 'primary' => 0, 'notes' => 'diced'],
            ['name' => 'Banana', 'qty' => 0.25, 'primary' => 0, 'notes' => 'sliced'],
            ['name' => 'Passion Fruit', 'qty' => 0.25, 'primary' => 0, 'notes' => 'pulp'],
            ['name' => 'Honey', 'qty' => 0.05, 'primary' => 0, 'notes' => 'drizzle'],
            ['name' => 'Mint', 'qty' => 0.02, 'primary' => 0, 'notes' => 'fresh leaves'],
        ],
    ],
    [
        'name' => 'Banana Fritters',
        'description' => 'Sweet banana fritters dusted with cinnamon sugar',
        'category' => 'dessert',
        'cuisine' => 'African',
        'serves' => 4,
        'prep_time_minutes' => 10,
        'cook_time_minutes' => 10,
        'difficulty' => 'easy',
        'instructions' => [
            'Mash ripe bananas in a bowl',
            'Mix with flour, egg, sugar, and a pinch of cinnamon',
            'Heat oil for deep frying',
            'Drop spoonfuls of batter into hot oil',
            'Fry until golden brown, drain on paper towels',
            'Dust with cinnamon sugar while hot',
            'Serve with vanilla ice cream or honey',
        ],
        'ingredients' => [
            ['name' => 'Banana', 'qty' => 1, 'primary' => 1, 'notes' => 'ripe, mashed'],
            ['name' => 'Flour', 'qty' => 0.25, 'primary' => 0, 'notes' => null],
            ['name' => 'Eggs', 'qty' => 0.5, 'primary' => 0, 'notes' => null],
            ['name' => 'Sugar', 'qty' => 0.1, 'primary' => 0, 'notes' => null],
            ['name' => 'Cinnamon', 'qty' => 0.02, 'primary' => 0, 'notes' => 'ground'],
        ],
    ],

    // ── SAUCES ──
    [
        'name' => 'Peri-Peri Sauce',
        'description' => 'Spicy Mozambican-style chili sauce for grilled meats',
        'category' => 'sauce',
        'cuisine' => 'African',
        'serves' => 10,
        'prep_time_minutes' => 10,
        'cook_time_minutes' => 15,
        'difficulty' => 'easy',
        'instructions' => [
            'Blend bird eye chilies, garlic, lemon juice, paprika, and oregano',
            'Heat olive oil in a pan',
            'Add blended mixture, cook for 10 minutes stirring often',
            'Add vinegar and salt to taste',
            'Cool and store in a jar',
            'Keeps refrigerated for 2 weeks',
        ],
        'ingredients' => [
            ['name' => 'Chili', 'qty' => 0.1, 'primary' => 1, 'notes' => 'bird eye or similar'],
            ['name' => 'Garlic', 'qty' => 0.05, 'primary' => 0, 'notes' => null],
            ['name' => 'Lemon', 'qty' => 0.1, 'primary' => 0, 'notes' => 'juice'],
            ['name' => 'Paprika', 'qty' => 0.02, 'primary' => 0, 'notes' => 'smoked'],
            ['name' => 'Vinegar', 'qty' => 0.05, 'primary' => 0, 'notes' => null],
        ],
    ],
    [
        'name' => 'Mushroom Sauce',
        'description' => 'Creamy mushroom sauce for steaks and grilled meats',
        'category' => 'sauce',
        'cuisine' => 'Continental',
        'serves' => 4,
        'prep_time_minutes' => 5,
        'cook_time_minutes' => 15,
        'difficulty' => 'easy',
        'instructions' => [
            'Slice mushrooms thinly',
            'Sauté in butter until golden and moisture evaporated',
            'Add minced garlic, cook 1 minute',
            'Pour in cream and beef stock',
            'Simmer until sauce thickens slightly',
            'Season with salt, pepper, and thyme',
        ],
        'ingredients' => [
            ['name' => 'Mushroom', 'qty' => 0.5, 'primary' => 1, 'notes' => 'sliced'],
            ['name' => 'Cream', 'qty' => 0.25, 'primary' => 0, 'notes' => null],
            ['name' => 'Garlic', 'qty' => 0.05, 'primary' => 0, 'notes' => 'minced'],
            ['name' => 'Butter', 'qty' => 0.1, 'primary' => 0, 'notes' => null],
            ['name' => 'Thyme', 'qty' => 0.02, 'primary' => 0, 'notes' => 'fresh'],
        ],
    ],

    // ── LUNCH ──
    [
        'name' => 'Club Sandwich',
        'description' => 'Triple-decker sandwich with chicken, bacon, lettuce, tomato, and mayo',
        'category' => 'lunch',
        'cuisine' => 'Continental',
        'serves' => 2,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 10,
        'difficulty' => 'easy',
        'instructions' => [
            'Toast three slices of bread per sandwich',
            'Grill or fry chicken breast until cooked, slice thinly',
            'Cook bacon until crispy',
            'Layer 1: toast, mayo, lettuce, chicken',
            'Layer 2: toast, mayo, bacon, tomato',
            'Top with final toast, secure with cocktail sticks',
            'Cut diagonally into quarters, serve with chips',
        ],
        'ingredients' => [
            ['name' => 'Chicken', 'qty' => 0.5, 'primary' => 1, 'notes' => 'breast, grilled'],
            ['name' => 'Bread', 'qty' => 1.5, 'primary' => 1, 'notes' => 'toasted slices'],
            ['name' => 'Bacon', 'qty' => 0.25, 'primary' => 0, 'notes' => 'crispy'],
            ['name' => 'Lettuce', 'qty' => 0.125, 'primary' => 0, 'notes' => 'leaves'],
            ['name' => 'Tomato', 'qty' => 0.25, 'primary' => 0, 'notes' => 'sliced'],
            ['name' => 'Mayonnaise', 'qty' => 0.05, 'primary' => 0, 'notes' => null],
        ],
    ],
    [
        'name' => 'Beef Burger',
        'description' => 'Gourmet beef burger with lettuce, tomato, cheese, and special sauce',
        'category' => 'lunch',
        'cuisine' => 'Continental',
        'serves' => 4,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 15,
        'difficulty' => 'easy',
        'instructions' => [
            'Mix minced beef with diced onion, garlic, salt, pepper',
            'Form into 4 patties, slightly larger than buns',
            'Grill or pan-fry for 4-5 minutes per side',
            'Add cheese on top in last minute to melt',
            'Toast buns lightly',
            'Assemble: bun, lettuce, patty with cheese, tomato, onion, sauce',
            'Serve with fries',
        ],
        'ingredients' => [
            ['name' => 'Beef Mince', 'qty' => 0.75, 'primary' => 1, 'notes' => 'ground beef'],
            ['name' => 'Bread', 'qty' => 0.5, 'primary' => 1, 'notes' => 'burger buns'],
            ['name' => 'Cheese', 'qty' => 0.125, 'primary' => 0, 'notes' => 'cheddar slices'],
            ['name' => 'Lettuce', 'qty' => 0.1, 'primary' => 0, 'notes' => null],
            ['name' => 'Tomato', 'qty' => 0.25, 'primary' => 0, 'notes' => 'sliced'],
            ['name' => 'Onion', 'qty' => 0.125, 'primary' => 0, 'notes' => 'rings'],
        ],
    ],
    [
        'name' => 'Vegetable Wrap',
        'description' => 'Grilled vegetable tortilla wrap with hummus and feta',
        'category' => 'lunch',
        'cuisine' => 'Mediterranean',
        'serves' => 4,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 10,
        'difficulty' => 'easy',
        'instructions' => [
            'Grill sliced courgette, bell peppers, and aubergine',
            'Warm tortilla wraps on the grill',
            'Spread hummus on each wrap',
            'Layer grilled vegetables, fresh spinach, and crumbled feta',
            'Drizzle with balsamic glaze',
            'Roll up tightly, cut in half diagonally',
        ],
        'ingredients' => [
            ['name' => 'Tortilla', 'qty' => 0.5, 'primary' => 1, 'notes' => 'wraps'],
            ['name' => 'Courgette', 'qty' => 0.25, 'primary' => 0, 'notes' => 'sliced, grilled'],
            ['name' => 'Bell Pepper', 'qty' => 0.25, 'primary' => 0, 'notes' => 'sliced, grilled'],
            ['name' => 'Feta', 'qty' => 0.1, 'primary' => 0, 'notes' => 'crumbled'],
            ['name' => 'Spinach', 'qty' => 0.1, 'primary' => 0, 'notes' => 'fresh leaves'],
            ['name' => 'Hummus', 'qty' => 0.1, 'primary' => 0, 'notes' => null],
        ],
    ],
    [
        'name' => 'Chicken Quesadilla',
        'description' => 'Crispy tortilla with spiced chicken, cheese, and peppers',
        'category' => 'lunch',
        'cuisine' => 'Mexican',
        'serves' => 4,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 15,
        'difficulty' => 'easy',
        'instructions' => [
            'Season diced chicken with paprika, cumin, chili powder',
            'Pan-fry chicken until cooked through',
            'Lay tortilla on hot pan, add grated cheese on one half',
            'Top cheese with cooked chicken and diced peppers',
            'Fold tortilla in half, cook until golden and cheese melts',
            'Flip to brown other side',
            'Cut into wedges, serve with salsa and sour cream',
        ],
        'ingredients' => [
            ['name' => 'Chicken', 'qty' => 0.5, 'primary' => 1, 'notes' => 'diced'],
            ['name' => 'Tortilla', 'qty' => 0.5, 'primary' => 1, 'notes' => null],
            ['name' => 'Cheese', 'qty' => 0.2, 'primary' => 0, 'notes' => 'grated'],
            ['name' => 'Bell Pepper', 'qty' => 0.25, 'primary' => 0, 'notes' => 'diced'],
            ['name' => 'Paprika', 'qty' => 0.02, 'primary' => 0, 'notes' => null],
            ['name' => 'Cumin', 'qty' => 0.02, 'primary' => 0, 'notes' => 'ground'],
        ],
    ],

    // ── DINNER ──
    [
        'name' => 'Grilled Ribeye Steak',
        'description' => 'Prime ribeye steak grilled to perfection with compound butter',
        'category' => 'dinner',
        'cuisine' => 'Continental',
        'serves' => 2,
        'prep_time_minutes' => 5,
        'cook_time_minutes' => 15,
        'difficulty' => 'medium',
        'instructions' => [
            'Bring steak to room temperature 30 minutes before cooking',
            'Season generously with salt and pepper',
            'Heat grill or cast iron to very high heat',
            'Sear steak for 4-5 minutes per side for medium-rare',
            'Rest for 5 minutes',
            'Top with compound butter (butter, garlic, herbs)',
            'Serve with sides of choice',
        ],
        'ingredients' => [
            ['name' => 'Beef', 'qty' => 1.5, 'primary' => 1, 'notes' => 'ribeye steak'],
            ['name' => 'Butter', 'qty' => 0.125, 'primary' => 0, 'notes' => 'compound'],
            ['name' => 'Garlic', 'qty' => 0.05, 'primary' => 0, 'notes' => 'minced'],
            ['name' => 'Thyme', 'qty' => 0.02, 'primary' => 0, 'notes' => 'fresh'],
        ],
    ],
    [
        'name' => 'Roast Chicken',
        'description' => 'Whole roast chicken with lemon, garlic and herbs',
        'category' => 'dinner',
        'cuisine' => 'Continental',
        'serves' => 6,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 90,
        'difficulty' => 'medium',
        'instructions' => [
            'Pat chicken dry, season inside and out',
            'Stuff cavity with lemon halves, garlic, and thyme',
            'Rub skin with butter and herbs',
            'Roast at 190°C for 1.5 hours (20 min per kg + 20 min)',
            'Baste with pan juices every 30 minutes',
            'Check internal temperature reaches 74°C',
            'Rest for 15 minutes before carving',
            'Make gravy from pan drippings',
        ],
        'ingredients' => [
            ['name' => 'Chicken', 'qty' => 1, 'primary' => 1, 'notes' => 'whole'],
            ['name' => 'Lemon', 'qty' => 0.25, 'primary' => 0, 'notes' => 'halved'],
            ['name' => 'Garlic', 'qty' => 0.1, 'primary' => 0, 'notes' => 'whole cloves'],
            ['name' => 'Butter', 'qty' => 0.125, 'primary' => 0, 'notes' => 'for skin'],
            ['name' => 'Thyme', 'qty' => 0.02, 'primary' => 0, 'notes' => 'fresh sprigs'],
        ],
    ],

    // ── MORE AFRICAN DISHES ──
    [
        'name' => 'Ugali',
        'description' => 'Traditional East African maize meal staple',
        'category' => 'side',
        'cuisine' => 'African',
        'serves' => 6,
        'prep_time_minutes' => 5,
        'cook_time_minutes' => 15,
        'difficulty' => 'easy',
        'instructions' => [
            'Bring water to a rolling boil in a heavy pot',
            'Gradually add maize flour while stirring with a wooden spoon',
            'Stir vigorously and continuously to prevent lumps',
            'Reduce heat and keep stirring until very thick',
            'Ugali should pull away from sides of pot cleanly',
            'Shape into a mound on a plate',
        ],
        'ingredients' => [
            ['name' => 'Maize Flour', 'qty' => 0.5, 'primary' => 1, 'notes' => null],
        ],
    ],
    [
        'name' => 'Mishkaki',
        'description' => 'East African marinated meat skewers grilled over charcoal',
        'category' => 'main_course',
        'cuisine' => 'African',
        'serves' => 4,
        'prep_time_minutes' => 20,
        'cook_time_minutes' => 15,
        'difficulty' => 'easy',
        'instructions' => [
            'Cut beef into 2cm cubes',
            'Marinate in blend of garlic, ginger, lemon juice, cumin, turmeric for 2 hours',
            'Thread onto skewers alternating with onion and pepper pieces',
            'Grill over hot charcoal turning frequently',
            'Baste with marinade while grilling',
            'Serve with kachumbari and naan or chapati',
        ],
        'ingredients' => [
            ['name' => 'Beef', 'qty' => 1, 'primary' => 1, 'notes' => 'cubed'],
            ['name' => 'Garlic', 'qty' => 0.05, 'primary' => 0, 'notes' => 'minced'],
            ['name' => 'Ginger', 'qty' => 0.05, 'primary' => 0, 'notes' => 'grated'],
            ['name' => 'Lemon', 'qty' => 0.25, 'primary' => 0, 'notes' => 'juice'],
            ['name' => 'Cumin', 'qty' => 0.02, 'primary' => 0, 'notes' => 'ground'],
            ['name' => 'Bell Pepper', 'qty' => 0.25, 'primary' => 0, 'notes' => 'chunks'],
            ['name' => 'Onion', 'qty' => 0.25, 'primary' => 0, 'notes' => 'wedges'],
        ],
    ],
    [
        'name' => 'Coconut Bean Curry',
        'description' => 'Creamy coconut curry with mixed beans, served with rice',
        'category' => 'main_course',
        'cuisine' => 'African',
        'serves' => 4,
        'prep_time_minutes' => 10,
        'cook_time_minutes' => 25,
        'difficulty' => 'easy',
        'instructions' => [
            'Sauté onion, garlic, and ginger until fragrant',
            'Add curry powder, cumin, and turmeric, cook 1 minute',
            'Add diced tomatoes and coconut milk',
            'Add drained beans, simmer for 15 minutes',
            'Season with salt and lime juice',
            'Serve over steamed rice with fresh cilantro',
        ],
        'ingredients' => [
            ['name' => 'Beans', 'qty' => 0.5, 'primary' => 1, 'notes' => 'mixed, canned or cooked'],
            ['name' => 'Coconut Milk', 'qty' => 0.5, 'primary' => 1, 'notes' => null],
            ['name' => 'Tomato', 'qty' => 0.25, 'primary' => 0, 'notes' => 'diced'],
            ['name' => 'Onion', 'qty' => 0.25, 'primary' => 0, 'notes' => 'diced'],
            ['name' => 'Curry Powder', 'qty' => 0.05, 'primary' => 0, 'notes' => null],
            ['name' => 'Rice', 'qty' => 0.5, 'primary' => 0, 'notes' => 'steamed'],
        ],
    ],
    [
        'name' => 'Chicken Stir Fry',
        'description' => 'Quick stir-fried chicken with mixed vegetables in soy sauce',
        'category' => 'dinner',
        'cuisine' => 'Asian',
        'serves' => 4,
        'prep_time_minutes' => 15,
        'cook_time_minutes' => 10,
        'difficulty' => 'easy',
        'instructions' => [
            'Slice chicken breast into thin strips',
            'Cut vegetables into thin strips or bite-size pieces',
            'Heat wok with oil until smoking',
            'Stir fry chicken strips for 3-4 minutes until cooked',
            'Add vegetables, stir fry for 2-3 minutes keeping them crunchy',
            'Add soy sauce, sesame oil, and a pinch of sugar',
            'Toss well and serve immediately over rice or noodles',
        ],
        'ingredients' => [
            ['name' => 'Chicken', 'qty' => 0.75, 'primary' => 1, 'notes' => 'breast, sliced'],
            ['name' => 'Bell Pepper', 'qty' => 0.25, 'primary' => 0, 'notes' => 'strips'],
            ['name' => 'Carrot', 'qty' => 0.125, 'primary' => 0, 'notes' => 'julienned'],
            ['name' => 'Soy Sauce', 'qty' => 0.1, 'primary' => 0, 'notes' => null],
            ['name' => 'Ginger', 'qty' => 0.05, 'primary' => 0, 'notes' => 'minced'],
            ['name' => 'Garlic', 'qty' => 0.05, 'primary' => 0, 'notes' => 'minced'],
            ['name' => 'Rice', 'qty' => 0.5, 'primary' => 0, 'notes' => 'steamed'],
        ],
    ],
    [
        'name' => 'Vegetable Biryani',
        'description' => 'Fragrant layered rice with mixed vegetables and aromatic spices',
        'category' => 'main_course',
        'cuisine' => 'Indian',
        'serves' => 6,
        'prep_time_minutes' => 20,
        'cook_time_minutes' => 40,
        'difficulty' => 'medium',
        'instructions' => [
            'Par-cook basmati rice with whole spices (cardamom, cloves, bay leaf)',
            'Sauté mixed vegetables with onion, garlic, ginger',
            'Add biryani masala, turmeric, yogurt',
            'Layer rice and vegetables alternately in a pot',
            'Sprinkle saffron milk and ghee between layers',
            'Seal pot tightly, cook on very low heat for 20 minutes',
            'Let rest 5 minutes, gently mix and serve',
        ],
        'ingredients' => [
            ['name' => 'Rice', 'qty' => 0.5, 'primary' => 1, 'notes' => 'basmati'],
            ['name' => 'Carrot', 'qty' => 0.17, 'primary' => 0, 'notes' => 'diced'],
            ['name' => 'Potato', 'qty' => 0.25, 'primary' => 0, 'notes' => 'cubed'],
            ['name' => 'Peas', 'qty' => 0.17, 'primary' => 0, 'notes' => 'green'],
            ['name' => 'Onion', 'qty' => 0.5, 'primary' => 0, 'notes' => 'sliced, fried'],
            ['name' => 'Yogurt', 'qty' => 0.1, 'primary' => 0, 'notes' => null],
            ['name' => 'Garam Masala', 'qty' => 0.05, 'primary' => 0, 'notes' => null],
            ['name' => 'Cardamom', 'qty' => 0.02, 'primary' => 0, 'notes' => 'whole pods'],
        ],
    ],
];


// ═══════════════════════════════════════════════════════
// Seed Execution
// ═══════════════════════════════════════════════════════

$results = [];
$created = 0;
$skipped = 0;
$totalIngredients = 0;
$unmatchedIngredients = [];

$pdo->beginTransaction();

try {
    foreach ($recipes as $recipe) {
        // Check if already exists
        $existingId = recipeExists($pdo, $recipe['name']);
        if ($existingId) {
            $skipped++;
            $results[] = "SKIP: {$recipe['name']} (already exists, id={$existingId})";
            continue;
        }

        // Insert recipe
        $instructions = json_encode($recipe['instructions']);
        $pdo->prepare("
            INSERT INTO kitchen_recipes (name, description, category, cuisine, serves,
                prep_time_minutes, cook_time_minutes, difficulty, instructions,
                camp_id, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NOW())
        ")->execute([
            $recipe['name'],
            $recipe['description'],
            $recipe['category'],
            $recipe['cuisine'],
            $recipe['serves'],
            $recipe['prep_time_minutes'],
            $recipe['cook_time_minutes'],
            $recipe['difficulty'],
            $instructions,
        ]);
        $recipeId = (int) $pdo->lastInsertId();

        // Insert ingredients
        $ingStmt = $pdo->prepare("
            INSERT INTO kitchen_recipe_ingredients (recipe_id, item_id, qty_per_serving, is_primary, notes)
            VALUES (?, ?, ?, ?, ?)
        ");

        $matchedCount = 0;
        foreach ($recipe['ingredients'] as $ing) {
            $item = findItem($pdo, $ing['name']);
            if ($item) {
                $ingStmt->execute([
                    $recipeId,
                    (int) $item['id'],
                    (float) $ing['qty'],
                    (int) $ing['primary'],
                    $ing['notes'],
                ]);
                $matchedCount++;
                $totalIngredients++;
            } else {
                $unmatchedIngredients[] = "{$recipe['name']}: {$ing['name']}";
            }
        }

        $created++;
        $results[] = "OK: {$recipe['name']} (id={$recipeId}, {$matchedCount}/" . count($recipe['ingredients']) . " ingredients matched)";
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    jsonError("Seed failed: " . $e->getMessage(), 500);
}

jsonResponse([
    'message' => "Seeded {$created} recipes, skipped {$skipped}, matched {$totalIngredients} total ingredients",
    'created' => $created,
    'skipped' => $skipped,
    'total_ingredients_matched' => $totalIngredients,
    'unmatched_ingredients' => $unmatchedIngredients,
    'details' => $results,
]);
