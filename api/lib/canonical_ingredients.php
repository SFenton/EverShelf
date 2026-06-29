<?php
/**
 * Canonical ingredient inference and persistence.
 *
 * The rules are intentionally local and deterministic: external taxonomies
 * (Open Food Facts/FoodOn/FDC/Wikidata) can be linked through external_ids_json,
 * while product saves and backfills remain fast and offline-safe.
 */

const CANONICAL_INGREDIENT_RULESET_VERSION = 'evershelf_common_ingredients_v1';
const FOODON_LOOKUP_CACHE_VERSION = 'foodon_ols4_v5';
const USDA_FDC_LOOKUP_CACHE_VERSION = 'usda_fdc_v5';

function canonicalIngredientNormalizeText(string $text): string {
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = str_replace(
        ['’', "'", '`', '&', '/', '_', '+', '-'],
        [' ', ' ', ' ', ' and ', ' ', ' ', ' plus ', ' '],
        $text
    );
    $text = preg_replace('/[^\p{L}0-9\s\-]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function canonicalIngredientSlug(string $name): string {
    $slug = canonicalIngredientNormalizeText($name);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        if (is_string($ascii) && $ascii !== '') {
            $slug = strtolower($ascii);
        }
    }
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
    return trim($slug, '-');
}

function canonicalIngredientTitle(string $name): string {
    $small = ['and', 'of', 'with', 'for', 'in'];
    $words = preg_split('/\s+/', canonicalIngredientNormalizeText($name), -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($words ?: [] as $idx => $word) {
        $out[] = $idx > 0 && in_array($word, $small, true)
            ? $word
            : mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($word, 1, null, 'UTF-8');
    }
    return implode(' ', $out);
}

function canonicalIngredientDecodeTags(mixed $value): array {
    if (is_array($value)) {
        return array_values(array_filter(array_map('strval', $value)));
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
        return array_values(array_filter(array_map('strval', $decoded)));
    }
    return array_values(array_filter(array_map('trim', explode(',', $value))));
}

function canonicalIngredientSearchText(array $product): string {
    $parts = [
        $product['name'] ?? '',
        $product['off_generic_name'] ?? '',
        $product['generic_name'] ?? '',
        $product['category'] ?? '',
    ];
    return canonicalIngredientNormalizeText(implode(' ', array_filter(array_map('strval', $parts))));
}

function canonicalIngredientRuleDefinitions(): array {
    return [
        // Sauces and condiments: specific before generic.
        ['rx' => '/\b(spicy\s+brown\s+mustard|brown\s+mustard|mustard\s+spicy\s+brown)\b/u', 'path' => ['Spicy brown mustard', 'Brown mustard', 'Mustard', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.98],
        ['rx' => '/\bdijon\s+mustard\b/u', 'path' => ['Dijon mustard', 'Mustard', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.98],
        ['rx' => '/\b(yellow\s+mustard|classic\s+yellow\s+mustard)\b/u', 'path' => ['Yellow mustard', 'Mustard', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.98],
        ['rx' => '/\bmustard\b/u', 'path' => ['Mustard', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.94],
        ['rx' => '/\b(arrabbiata|marinara)\b/u', 'path' => ['Marinara sauce', 'Tomato sauce', 'Sauce'], 'contains' => ['Tomato'], 'category' => 'sauces', 'confidence' => 0.93],
        ['rx' => '/\btomato\s+sauce\b/u', 'path' => ['Tomato sauce', 'Sauce'], 'contains' => ['Tomato'], 'category' => 'sauces', 'confidence' => 0.95],
        ['rx' => '/\b(chili\s+garlic\s+sauce|hot\s+chili\s+sauce|sriracha)\b/u', 'path' => ['Chili garlic sauce', 'Sauce'], 'contains' => ['Chili pepper', 'Garlic'], 'category' => 'sauces', 'confidence' => 0.96],
        ['rx' => '/\b(chimichurri)\b/u', 'path' => ['Chimichurri sauce', 'Sauce'], 'category' => 'sauces', 'confidence' => 0.96],
        ['rx' => '/\b(steak\s+sauce)\b/u', 'path' => ['Steak sauce', 'Sauce'], 'category' => 'sauces', 'confidence' => 0.94],
        ['rx' => '/\b(barbecue|bbq)\s+sauce\b/u', 'path' => ['Barbecue sauce', 'Sauce'], 'category' => 'sauces', 'confidence' => 0.96],
        ['rx' => '/\b(ketchup)\b/u', 'path' => ['Ketchup', 'Condiment'], 'contains' => ['Tomato'], 'category' => 'condiments', 'confidence' => 0.98],
        ['rx' => '/\b(mayonnaise|mayo)\b/u', 'path' => ['Mayonnaise', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.98],
        ['rx' => '/\b(hoisin\s+sauce)\b/u', 'path' => ['Hoisin sauce', 'Sauce'], 'category' => 'sauces', 'confidence' => 0.97],
        ['rx' => '/\b(oyster\s+sauce)\b/u', 'path' => ['Oyster sauce', 'Sauce'], 'contains' => ['Oyster'], 'category' => 'sauces', 'confidence' => 0.97],
        ['rx' => '/\b(tamari|soy\s+sauce)\b/u', 'path' => ['Soy sauce', 'Condiment'], 'contains' => ['Soybean'], 'category' => 'condiments', 'confidence' => 0.97],
        ['rx' => '/\b(caesar\s+dressing)\b/u', 'path' => ['Caesar dressing', 'Salad dressing', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.96],
        ['rx' => '/\b(red\s+curry\s+paste|curry\s+paste)\b/u', 'path' => ['Curry paste', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.96],
        ['rx' => '/\b(fermented\s+chile\s+paste|chile\s+paste|chili\s+paste)\b/u', 'path' => ['Chili paste', 'Condiment'], 'contains' => ['Chili pepper'], 'category' => 'condiments', 'confidence' => 0.95],
        ['rx' => '/\b(salsa\s+verde|chunky\s+salsa|salsa)\b/u', 'path' => ['Salsa', 'Dip'], 'contains' => ['Tomato'], 'category' => 'dips', 'confidence' => 0.94],
        ['rx' => '/\btartinables?\b/u', 'path' => ['Spread'], 'category' => 'spreads', 'confidence' => 0.72],
        ['rx' => '/\bblack\s+bean\b.*\bsauce\b/u', 'path' => ['Black bean sauce', 'Sauce'], 'contains' => ['Black beans', 'Garlic'], 'category' => 'sauces', 'confidence' => 0.96],
        ['rx' => '/\b(seasoning\s+blend|herb\s+seasoning)\b/u', 'path' => ['Seasoning blend', 'Seasoning', 'Spice'], 'contains' => ['Onion'], 'category' => 'spices', 'confidence' => 0.93],
        ['rx' => '/\b(rice\s+vinegar|vinegar)\b/u', 'path' => ['Vinegar', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.94],
        ['rx' => '/\b(dill\s+chips|hamburger\s+dill|pickle\s+chips?)\b/u', 'path' => ['Pickle chips', 'Pickles', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.95],
        ['rx' => '/\bpickles?\b/u', 'path' => ['Pickles', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.93],
        ['rx' => '/\b(sliced\s+tamed\s+)?jalape(?:n|ñ)o\s+peppers?\b/u', 'path' => ['Pickled jalapeño peppers', 'Pickled peppers', 'Pepper', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.94],
        ['rx' => '/\b(jalape(?:n|ñ)o|banana\s+pepper|pepper\s+rings)\b/u', 'path' => ['Pickled peppers', 'Pepper', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.92],

        // Meat, seafood, stocks.
        ['rx' => '/\bchicken\s+breasts?\b/u', 'path' => ['Chicken breast', 'Chicken', 'Poultry'], 'category' => 'meat', 'confidence' => 0.99],
        ['rx' => '/\b(chicken\s+base|chicken.*bouillon|bouillon.*chicken)\b/u', 'path' => ['Chicken stock base', 'Stock base', 'Stock'], 'contains' => ['Chicken'], 'category' => 'broths', 'confidence' => 0.97],
        ['rx' => '/\bchicken\s+(stock|broth)\b/u', 'path' => ['Chicken stock', 'Stock'], 'contains' => ['Chicken'], 'category' => 'stocks', 'confidence' => 0.96],
        ['rx' => '/\bbeef\s+stock\b/u', 'path' => ['Beef stock', 'Stock'], 'contains' => ['Beef'], 'category' => 'stocks', 'confidence' => 0.97],
        ['rx' => '/\b(vegetable|verdure|veggie)\s+soup\b/u', 'path' => ['Vegetable soup', 'Soup'], 'contains' => ['Vegetables'], 'category' => 'soups', 'confidence' => 0.9],
        ['rx' => '/\bcheese\s+soup\b/u', 'path' => ['Cheese soup', 'Soup'], 'contains' => ['Cheese'], 'category' => 'soups', 'confidence' => 0.9],
        ['rx' => '/\b(tortilla\s+soup|chicken\s+tortilla\s+soup)\b/u', 'path' => ['Multi-ingredient soup', 'Soup'], 'contains' => ['Chicken'], 'category' => 'soups', 'confidence' => 0.84],
        ['rx' => '/\bsoup\b/u', 'path' => ['Soup'], 'category' => 'soups', 'confidence' => 0.78],
        ['rx' => '/\bbacon\b/u', 'path' => ['Bacon', 'Pork', 'Meat'], 'category' => 'meat', 'confidence' => 0.98],
        ['rx' => '/\bfoie\s+gras\b/u', 'path' => ['Foie gras', 'Duck', 'Meat'], 'category' => 'meat', 'confidence' => 0.97],
        ['rx' => '/\b(clams?|sea\s+clams?)\b/u', 'path' => ['Clams', 'Shellfish', 'Seafood'], 'category' => 'seafood', 'confidence' => 0.98],

        // Dairy.
        ['rx' => '/\bcream\s+cheese\b/u', 'path' => ['Cream cheese', 'Cheese', 'Dairy'], 'category' => 'dairy', 'confidence' => 0.99],
        ['rx' => '/\bheavy\s+whipping\s+cream\b/u', 'path' => ['Heavy cream', 'Cream', 'Dairy'], 'category' => 'dairy', 'confidence' => 0.99],
        ['rx' => '/\bsour\s+cream\b/u', 'path' => ['Sour cream', 'Cream', 'Dairy'], 'category' => 'dairy', 'confidence' => 0.99],
        ['rx' => '/\bhalf\s*(?:and|&)\s*half\b/u', 'path' => ['Half and half', 'Cream', 'Dairy'], 'category' => 'dairy', 'confidence' => 0.99],
        ['rx' => '/\bevaporated\s+milk\b/u', 'path' => ['Evaporated milk', 'Milk', 'Dairy'], 'category' => 'dairy', 'confidence' => 0.98],
        ['rx' => '/\b(condensed\s+milk|sweetened\s+condensed)\b/u', 'path' => ['Condensed milk', 'Milk', 'Dairy'], 'category' => 'dairy', 'confidence' => 0.98],
        ['rx' => '/\bdairy[\s-]?free\b.*\bmilk\b/u', 'path' => ['Plant-based milk', 'Milk alternative'], 'category' => 'plant milks', 'confidence' => 0.92],
        ['rx' => '/\breduced\s+fat\s+milk\b/u', 'path' => ['Milk', 'Dairy'], 'category' => 'dairy', 'confidence' => 0.90],
        ['rx' => '/\b(coconut\s*(milk|milk beverage|coconutmilk)|coconutmilk)\b/u', 'path' => ['Coconut milk', 'Beverage'], 'contains' => ['Coconut'], 'category' => 'plant milks', 'confidence' => 0.97],
        ['rx' => '/\bpeanut\s+butter\b/u', 'path' => ['Peanut butter', 'Nut butter', 'Butter', 'Spread'], 'contains' => ['Peanuts'], 'category' => 'spreads', 'confidence' => 0.99],
        ['rx' => '/\bbutter\b/u', 'path' => ['Butter', 'Spread'], 'category' => 'spreads', 'confidence' => 0.98],
        ['rx' => '/\bmozzarella\b/u', 'path' => ['Mozzarella', 'Cheese', 'Dairy'], 'category' => 'dairy', 'confidence' => 0.98],
        ['rx' => '/\bparmesan\b/u', 'path' => ['Parmesan', 'Cheese', 'Dairy'], 'category' => 'dairy', 'confidence' => 0.98],
        ['rx' => '/\bpepper\s+jack\b/u', 'path' => ['Pepper jack cheese', 'Cheese', 'Dairy'], 'category' => 'dairy', 'confidence' => 0.98],
        ['rx' => '/\bswiss\s+cheese\b/u', 'path' => ['Swiss cheese', 'Cheese', 'Dairy'], 'category' => 'dairy', 'confidence' => 0.98],
        ['rx' => '/\b(mexican-style\s+blend\s+cheese|shredded.*cheese|cheese\s+slices?|cheese\s+block)\b/u', 'path' => ['Cheese', 'Dairy'], 'category' => 'dairy', 'confidence' => 0.91],

        // Grains, bakery, prepared meals.
        ['rx' => '/\barborio\s+rice\b/u', 'path' => ['Arborio rice', 'Rice', 'Grain'], 'category' => 'grains', 'confidence' => 0.99],
        ['rx' => '/\bjasmine\s+(long\s+grain\s+)?rice\b/u', 'path' => ['Jasmine rice', 'Rice', 'Grain'], 'category' => 'grains', 'confidence' => 0.99],
        ['rx' => '/\b(rice)\b/u', 'path' => ['Rice', 'Grain'], 'category' => 'grains', 'confidence' => 0.91],
        ['rx' => '/\b(pearl\s+)?couscous\b/u', 'path' => ['Couscous', 'Wheat', 'Grain'], 'category' => 'grains', 'confidence' => 0.98],
        ['rx' => '/\bgrits\b/u', 'path' => ['Grits', 'Corn', 'Grain'], 'category' => 'grains', 'confidence' => 0.98],
        ['rx' => '/\b(bread\s+crumbs?|breadcrumbs?)\b/u', 'path' => ['Bread crumbs', 'Bread', 'Grain'], 'category' => 'bakery', 'confidence' => 0.98],
        ['rx' => '/\b(sandwich\s+buns?|buns?)\b/u', 'path' => ['Buns', 'Bread', 'Grain'], 'category' => 'bakery', 'confidence' => 0.96],
        ['rx' => '/\bpepperoni\s+pizza\b/u', 'path' => ['Pepperoni pizza', 'Pizza'], 'category' => 'pizza', 'confidence' => 0.98],
        ['rx' => '/\bpizza\b/u', 'path' => ['Pizza'], 'category' => 'pizza', 'confidence' => 0.96],
        ['rx' => '/\btacos?\b/u', 'path' => ['Tacos', 'Prepared meal'], 'category' => 'prepared meals', 'confidence' => 0.88],
        ['rx' => '/\bcake\b/u', 'path' => ['Cake', 'Dessert'], 'category' => 'desserts', 'confidence' => 0.92],
        ['rx' => '/\bcookies?\b/u', 'path' => ['Cookies', 'Dessert'], 'category' => 'desserts', 'confidence' => 0.94],

        // Produce, nuts, legumes.
        ['rx' => '/\bcarrots?\b/u', 'path' => ['Carrot', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.99],
        ['rx' => '/\bcauliflower\b/u', 'path' => ['Cauliflower', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.99],
        ['rx' => '/\bspinach\b/u', 'path' => ['Spinach', 'Leafy vegetable', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.99],
        ['rx' => '/\b(green\s+onions?|scallions?)\b/u', 'path' => ['Green onion', 'Onion', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.98],
        ['rx' => '/\bonion\b/u', 'path' => ['Onion', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.88],
        ['rx' => '/\bpeeled\s+garlic\b/u', 'path' => ['Garlic cloves', 'Garlic', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.99],
        ['rx' => '/\bgarlic\b/u', 'path' => ['Garlic', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.98],
        ['rx' => '/\bginger\b/u', 'path' => ['Ginger', 'Spice'], 'category' => 'spices', 'confidence' => 0.98],
        ['rx' => '/\b(sun[\s-]?dried|dried)\s+tomatoes\b/u', 'path' => ['Sun-dried tomatoes', 'Tomato', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.98],
        ['rx' => '/\btomatoes\b/u', 'path' => ['Tomato', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.90],
        ['rx' => '/\bolives?\b/u', 'path' => ['Olives', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.96],
        ['rx' => '/\bveggies?|vegetables?\b/u', 'path' => ['Vegetables'], 'category' => 'vegetables', 'confidence' => 0.80],
        ['rx' => '/\bpineapple\b/u', 'path' => ['Pineapple', 'Fruit'], 'category' => 'fruit', 'confidence' => 0.98],
        ['rx' => '/\bpear\s+marmalade\b/u', 'path' => ['Pear marmalade', 'Pear', 'Fruit preserve'], 'category' => 'fruit preserves', 'confidence' => 0.97],
        ['rx' => '/\bwalnuts?\b/u', 'path' => ['Walnuts', 'Tree nuts', 'Nuts'], 'category' => 'nuts', 'confidence' => 0.98],
        ['rx' => '/\balmonds?\b/u', 'path' => ['Almonds', 'Tree nuts', 'Nuts'], 'category' => 'nuts', 'confidence' => 0.98],
        ['rx' => '/\bpeanuts?\b/u', 'path' => ['Peanuts', 'Legumes', 'Nuts'], 'category' => 'nuts', 'confidence' => 0.98],
        ['rx' => '/\bblack\s+bean\b.*\bsalad\b/u', 'path' => ['Black bean salad', 'Salad'], 'contains' => ['Black beans', 'Corn'], 'category' => 'prepared salads', 'confidence' => 0.93],
        ['rx' => '/\bblack\s+bean\b/u', 'path' => ['Black beans', 'Beans', 'Legume'], 'category' => 'legumes', 'confidence' => 0.90],
        ['rx' => '/\broasted\s+corn\b/u', 'path' => ['Corn', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.78],

        // Baking, sweeteners, oils, beverages.
        ['rx' => '/\bbaking\s+powder\b/u', 'path' => ['Baking powder', 'Leavening agent'], 'category' => 'baking', 'confidence' => 0.99],
        ['rx' => '/\bbaking\s+soda\b/u', 'path' => ['Baking soda', 'Leavening agent'], 'category' => 'baking', 'confidence' => 0.99],
        ['rx' => '/\bgelatin(?:e)?\b/u', 'path' => ['Gelatin', 'Thickener'], 'category' => 'baking', 'confidence' => 0.98],
        ['rx' => '/\bcorn\s+starch\b/u', 'path' => ['Corn starch', 'Starch'], 'category' => 'baking', 'confidence' => 0.98],
        ['rx' => '/\bbrown\s+sugar\b/u', 'path' => ['Brown sugar', 'Sugar', 'Sweetener'], 'category' => 'sweeteners', 'confidence' => 0.98],
        ['rx' => '/\bmolasses\b/u', 'path' => ['Molasses', 'Sweetener'], 'category' => 'sweeteners', 'confidence' => 0.98],
        ['rx' => '/\bhoney\b/u', 'path' => ['Honey', 'Sweetener'], 'category' => 'sweeteners', 'confidence' => 0.98],
        ['rx' => '/\bmaple\s+syrup\b/u', 'path' => ['Maple syrup', 'Syrup', 'Sweetener'], 'category' => 'sweeteners', 'confidence' => 0.98],
        ['rx' => '/\blime\s+juice\b/u', 'path' => ['Lime juice', 'Juice', 'Beverage'], 'contains' => ['Lime'], 'category' => 'beverages', 'confidence' => 0.98],
        ['rx' => '/\bsesame\s+oil\b/u', 'path' => ['Sesame oil', 'Oil'], 'contains' => ['Sesame'], 'category' => 'oils', 'confidence' => 0.98],
        ['rx' => '/\bsesame\s+seeds?\b/u', 'path' => ['Sesame seeds', 'Seasoning'], 'contains' => ['Sesame'], 'category' => 'seasonings', 'confidence' => 0.98],
        ['rx' => '/\b(bitters?)\b/u', 'path' => ['Bitters', 'Flavoring'], 'category' => 'flavorings', 'confidence' => 0.92],
        ['rx' => '/\b(k[\s-]?cup|coffee\s+pods?|pods?)\b/u', 'path' => ['Coffee pod', 'Coffee', 'Beverage'], 'category' => 'beverages', 'confidence' => 0.97],
        ['rx' => '/\b(coffee\s*mate|coffee\s+creamer|creamer)\b/u', 'path' => ['Coffee creamer', 'Creamer', 'Dairy'], 'category' => 'dairy', 'confidence' => 0.94],
        ['rx' => '/\b(?<!mate\s)(?<!creamer\s)(coffee|k-cup)\b/u', 'path' => ['Coffee', 'Beverage'], 'category' => 'beverages', 'confidence' => 0.93],
        ['rx' => '/\bgatorade|sports?\s+drink|thirst\s+quencher\b/u', 'path' => ['Sports drink', 'Beverage'], 'category' => 'beverages', 'confidence' => 0.94],
        ['rx' => '/\bcooking\s+wine\b/u', 'path' => ['Cooking wine', 'Wine'], 'category' => 'cooking wine', 'confidence' => 0.95],
        ['rx' => '/\b(dessert\s+wine|soju|martini|buzzball|cocktail)\b/u', 'path' => ['Alcoholic beverage', 'Beverage'], 'category' => 'beverages', 'confidence' => 0.82],
        ['rx' => '/\b(tamarind\s+(soft\s+drink|soda)|zero\s+sugar\s+tamarind\s+soda|soft\s+drink)\b/u', 'path' => ['Soda', 'Beverage'], 'contains' => ['Tamarind'], 'category' => 'beverages', 'confidence' => 0.89],
    ];
}

function canonicalIngredientPut(array &$mappings, string $name, string $role, float $confidence, string $source, string $evidence, string $category = '', ?string $parentName = null, array $externalIds = []): void {
    $slug = canonicalIngredientSlug($name);
    if ($slug === '') {
        return;
    }
    $priority = ['contains' => 1, 'broader' => 2, 'primary' => 3];
    $newPriority = $priority[$role] ?? 0;
    $existing = $mappings[$slug] ?? null;
    $existingPriority = $existing ? ($priority[$existing['role']] ?? 0) : -1;
    if ($existing && ($existingPriority > $newPriority || ($existingPriority === $newPriority && (float)$existing['confidence'] >= $confidence))) {
        return;
    }
    $mappings[$slug] = [
        'slug' => $slug,
        'name' => $name,
        'role' => $role,
        'confidence' => max(0, min(1, $confidence)),
        'source' => $source,
        'evidence' => mb_substr($evidence, 0, 300, 'UTF-8'),
        'category' => $category,
        'parent_slug' => $parentName ? canonicalIngredientSlug($parentName) : null,
        'external_ids' => $externalIds,
    ];
}

function canonicalIngredientPutPath(array &$mappings, array $path, float $confidence, string $source, string $evidence, string $category, bool $allowPrimary): void {
    $path = array_values(array_filter(array_map('trim', $path)));
    foreach ($path as $idx => $name) {
        if (!$allowPrimary && $idx > 0) {
            continue;
        }
        $role = $idx === 0
            ? ($allowPrimary ? 'primary' : 'contains')
            : 'broader';
        $parentName = $path[$idx + 1] ?? null;
        canonicalIngredientPut(
            $mappings,
            $name,
            $role,
            max(0.1, $confidence - ($idx * 0.04)),
            $source,
            $evidence,
            $category,
            $parentName
        );
    }
}

function canonicalIngredientHasPrimary(array $mappings): bool {
    foreach ($mappings as $mapping) {
        if (($mapping['role'] ?? '') === 'primary') {
            return true;
        }
    }
    return false;
}

function canonicalIngredientIsWeakFallbackName(string $name): bool {
    $n = canonicalIngredientNormalizeText($name);
    if ($n === '' || mb_strlen($n, 'UTF-8') < 3) {
        return true;
    }
    static $weak = [
        'amazon', 'kroger', 'costco', 'kirkland', 'private', 'signature', 'selection',
        'simple', 'truth', 'happy', 'belly', 'whole', 'foods', 'trader', 'joe',
        'challenge', 'daisy', 'darigold', 'gatorade', 'buzzball', 'qfc', 'panda',
        'vlasic', 'swanson', 'tostitos', 'heinz', 'kraft', 'french', 'gulden',
    ];
    $words = preg_split('/\s+/', $n, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return count($words) === 1 && in_array($words[0], $weak, true);
}

function canonicalIngredientFallbackProductName(array $product): ?string {
    $name = canonicalIngredientNormalizeText((string)($product['name'] ?? ''));
    if ($name === '') {
        return null;
    }
    static $dropPhrases = [
        'amazon grocery', 'amazon', 'kroger', 'kirkland signature', 'kirkland',
        'private selection', 'signature select', 'simple truth organic', 'simple truth',
        'happy belly', 'whole foods market', 'whole foods', 'trader joe s',
        '365 everyday value', 'costco', 'qfc',
    ];
    foreach ($dropPhrases as $phrase) {
        $name = trim(preg_replace('/\b' . preg_quote($phrase, '/') . '\b/u', ' ', $name) ?? $name);
    }
    $name = preg_replace('/\b(original|classic|real|pure|organic|non gmo|free range|medium roast|regular|count|brand)\b/u', ' ', $name) ?? $name;
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? $name;
    if (canonicalIngredientIsWeakFallbackName($name)) {
        return null;
    }
    $tokens = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($tokens) > 4) {
        $tokens = array_slice($tokens, -4);
    }
    return canonicalIngredientTitle(implode(' ', $tokens));
}

function canonicalIngredientInferProduct(array $product): array {
    $mappings = [];
    $searchText = canonicalIngredientSearchText($product);
    $source = CANONICAL_INGREDIENT_RULESET_VERSION;

    foreach (canonicalIngredientRuleDefinitions() as $rule) {
        if (empty($rule['rx']) || !preg_match($rule['rx'], $searchText, $match)) {
            continue;
        }
        $matchedPath = $rule['path'] ?? [];
        if (($matchedPath[0] ?? '') === 'Coffee' && preg_match('/\b(coffee\s*mate|coffee\s+creamer|creamer)\b/u', $searchText)) {
            continue;
        }
        $evidence = 'matched "' . ($match[0] ?? $rule['rx']) . '" in product metadata';
        $allowPrimary = !canonicalIngredientHasPrimary($mappings);
        canonicalIngredientPutPath(
            $mappings,
            $matchedPath,
            (float)($rule['confidence'] ?? 0.85),
            $source,
            $evidence,
            (string)($rule['category'] ?? ''),
            $allowPrimary
        );
        foreach (($rule['contains'] ?? []) as $containsName) {
            canonicalIngredientPut(
                $mappings,
                $containsName,
                'contains',
                max(0.1, ((float)($rule['confidence'] ?? 0.85)) - 0.12),
                $source,
                $evidence,
                (string)($rule['category'] ?? '')
            );
        }
    }

    $tagTexts = [];
    foreach (canonicalIngredientDecodeTags($product['ingredients_tags_json'] ?? ($product['ingredients_tags'] ?? null)) as $tag) {
        $tag = preg_replace('/^[a-z]{2}:/', '', $tag) ?? $tag;
        $tagTexts[] = str_replace('-', ' ', $tag);
    }
    $ingredientsText = trim((string)($product['ingredients_text'] ?? ($product['ingredients'] ?? '')));
    if ($ingredientsText !== '') {
        $tagTexts[] = $ingredientsText;
    }
    if (!empty($tagTexts)) {
        $tagHaystack = canonicalIngredientNormalizeText(implode(' ', $tagTexts));
        foreach (canonicalIngredientRuleDefinitions() as $rule) {
            if (empty($rule['rx']) || !preg_match($rule['rx'], $tagHaystack, $match)) {
                continue;
            }
            $name = ($rule['path'][0] ?? '') ?: '';
            if ($name !== '') {
                canonicalIngredientPut(
                    $mappings,
                    $name,
                    'contains',
                    min(0.82, (float)($rule['confidence'] ?? 0.7)),
                    'openfoodfacts_ingredients',
                    'matched "' . ($match[0] ?? $rule['rx']) . '" in ingredient text/tags',
                    (string)($rule['category'] ?? ''),
                    $rule['path'][1] ?? null
                );
            }
        }
    }

    if (!canonicalIngredientHasPrimary($mappings)) {
        $fallback = canonicalIngredientFallbackProductName($product);
        if ($fallback !== null) {
            canonicalIngredientPut(
                $mappings,
                $fallback,
                'primary',
                0.45,
                'fallback_name',
                'fallback from cleaned product name',
                ''
            );
        }
    }

    uasort($mappings, static function(array $a, array $b): int {
        $roleOrder = ['primary' => 0, 'contains' => 1, 'broader' => 2];
        $ra = $roleOrder[$a['role']] ?? 9;
        $rb = $roleOrder[$b['role']] ?? 9;
        if ($ra !== $rb) return $ra <=> $rb;
        $conf = $b['confidence'] <=> $a['confidence'];
        return $conf !== 0 ? $conf : strcmp($a['name'], $b['name']);
    });
    return array_values($mappings);
}

function canonicalIngredientEnvBool(string $key, bool $default): bool {
    $raw = function_exists('env') ? env($key, $default ? 'true' : 'false') : ($default ? 'true' : 'false');
    return in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
}

function canonicalIngredientFoodOnEnabled(): bool {
    return canonicalIngredientEnvBool('FOODON_ENABLED', true);
}

function canonicalIngredientFoodOnLookupOnSave(): bool {
    return canonicalIngredientEnvBool('FOODON_LOOKUP_ON_SAVE', true);
}

function canonicalIngredientFoodOnCacheLoad(): array {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $cache = [];
    $path = defined('FOODON_CACHE_PATH') ? FOODON_CACHE_PATH : (__DIR__ . '/../../data/foodon_lookup_cache.json');
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $cache = $decoded;
        }
    }
    return $cache;
}

function canonicalIngredientFoodOnCacheStore(string $key, array $entry): void {
    $cache = canonicalIngredientFoodOnCacheLoad();
    $cache[$key] = $entry;
    $path = defined('FOODON_CACHE_PATH') ? FOODON_CACHE_PATH : (__DIR__ . '/../../data/foodon_lookup_cache.json');
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $tmp = $path . '.tmp';
    @file_put_contents($tmp, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    if (is_file($tmp)) {
        @rename($tmp, $path);
    }
}

function canonicalIngredientFoodOnPreferredQuery(string $slug, string $name): string {
    static $queries = [
        'beef-stock' => 'beef broth',
        'carrot' => 'carrot food product',
        'bitters' => 'bitters',
        'chicken-stock' => 'chicken broth',
        'clams' => 'clam food product',
        'cookies' => 'cookie',
        'sports-drink' => 'sports drinks rehydration',
        'green-onion' => 'green onions',
        'pickles' => 'pickled cucumber',
        'ketchup' => 'tomato ketchup',
        'pickled-peppers' => 'pepper pickle food product',
        'bread-crumbs' => 'breadcrumbs',
        'barbecue-sauce' => 'barbecue or steak sauces',
        'steak-sauce' => 'barbecue or steak sauces',
        'jasmine-rice' => 'rice grain long-grain',
        'dijon-mustard' => 'dijon mustard',
        'pepper-jack-cheese' => 'Monterey Jack cheese',
        'corn-starch' => 'maize starch',
        'buns' => 'roll or bun',
        'almonds' => 'almond food product',
        'peanuts' => 'peanut food product',
        'tacos' => 'taco',
        'soda' => 'soft drink',
        'ginger' => 'ginger food product',
        'rice' => 'rice food product',
        'grain' => 'cereal grain food product',
        'mustard' => 'mustard condiment food product',
        'brown-mustard' => 'mustard condiment food product',
        'spicy-brown-mustard' => 'mustard condiment food product',
        'chicken-breast' => 'chicken breast raw',
        'chicken' => 'chicken meat food product',
        'poultry' => 'poultry meat food product',
        'tomato' => 'tomato food product',
        'salsa' => 'salsa food product',
        'sauce' => 'condiment sauce',
        'plant-based-milk' => 'plant-based milk',
        'coffee-creamer' => 'coffee creamer',
        'coffee-pod' => 'coffee pod',
        'butter' => 'butter',
        'peanut-butter' => 'peanut butter',
        'nut-butter' => 'nut butter',
        'coconut' => 'coconut food product',
        'coffee' => 'coffee beverage',
        'cream' => 'cream food product',
        'garlic' => 'garlic food product',
        'gelatin' => 'gelatin product',
        'honey' => 'honey food product',
        'milk' => 'mammalian milk product',
        'molasses' => 'sugar syrup molasses',
        'sour-cream' => 'sour cream',
        'seasoning-blend' => 'herb blend seasoning',
        'alcoholic-beverage' => 'alcoholic beverage',
        'vegetables' => 'vegetable food product',
        'vegetable' => 'vegetable food product',
        'fruit' => 'fruit food product',
        'stock' => 'broth or stock',
        'dairy' => 'dairy food product',
        'cheese' => 'cheese food product',
        'bread' => 'bread food product',
        'oil' => 'edible oil',
        'sweetener' => 'sweetener food product',
    ];
    return $queries[$slug] ?? $name;
}

function canonicalIngredientFoodOnSkipSlug(string $slug): bool {
    static $skip = [
        // FoodOn search currently resolves these broad terms to overly specific products.
        'beef' => true,
        'sweetener' => true,
    ];
    return isset($skip[$slug]);
}

function canonicalIngredientFoodOnNormalizeLabel(string $label, bool $stripQualifiers = false): string {
    $label = preg_replace('/^\s*\d+\s*-\s*/u', '', $label) ?? $label;
    if ($stripQualifiers) {
        $label = preg_replace('/\([^)]*\)/u', ' ', $label) ?? $label;
    }
    return canonicalIngredientNormalizeText($label);
}

function canonicalIngredientFoodOnSelectBest(array $docs, string $name, string $query): ?array {
    $target = canonicalIngredientNormalizeText($name);
    $queryNorm = canonicalIngredientNormalizeText($query);
    $best = null;
    $bestScore = 0;
    foreach ($docs as $doc) {
        $label = (string)($doc['label'] ?? '');
        $iri = (string)($doc['iri'] ?? '');
        $shortForm = (string)($doc['short_form'] ?? '');
        if ($label === '' || $iri === '' || !str_starts_with($shortForm, 'FOODON_')) {
            continue;
        }

        $labelNorm = canonicalIngredientFoodOnNormalizeLabel($label);
        $labelStripped = canonicalIngredientFoodOnNormalizeLabel($label, true);
        $desc = strtolower((string)(($doc['description'][0] ?? '')));
        $score = 0;
        if ($labelNorm === $target) $score += 60;
        if ($labelStripped === $target) $score += 55;
        if ($labelNorm === $queryNorm) $score += 60;
        if ($labelStripped === $queryNorm) $score += 55;
        if ($target !== '' && str_contains($labelNorm, $target)) $score += 28;
        if ($queryNorm !== '' && str_contains($labelNorm, $queryNorm)) $score += 40;
        if ($labelNorm !== '' && str_contains($target, $labelNorm)) $score += 20;
        if (str_contains($labelNorm, 'food product')) $score += 12;
        if (str_contains($desc, 'food product')) $score += 8;
        if (str_contains($labelNorm, 'raw') && str_contains($queryNorm, 'raw')) $score += 6;
        if (str_contains($labelNorm, 'plant') && !str_contains($target, 'plant') && !str_contains($queryNorm, 'plant')) $score -= 20;
        if (str_contains($labelNorm, 'supplement') && !str_contains($target, 'supplement')) $score -= 15;
        if (($doc['type'] ?? '') === 'class') $score += 2;

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $doc + ['_match_score' => $score];
        }
    }
    return $bestScore >= 35 ? $best : null;
}

function canonicalIngredientFoodOnThrottle(): void {
    static $lastRequestAt = 0.0;
    $intervalMs = max(0, (int)(function_exists('env') ? env('FOODON_MIN_REQUEST_INTERVAL_MS', '250') : '250'));
    if ($intervalMs <= 0) {
        return;
    }
    $now = microtime(true);
    $elapsedMs = ($now - $lastRequestAt) * 1000;
    if ($lastRequestAt > 0 && $elapsedMs < $intervalMs) {
        usleep((int)(($intervalMs - $elapsedMs) * 1000));
    }
    $lastRequestAt = microtime(true);
}

function canonicalIngredientFoodOnLookup(string $name, string $slug = '', string $category = ''): ?array {
    if (!canonicalIngredientFoodOnEnabled() || trim($name) === '') {
        return null;
    }

    $slug = $slug !== '' ? $slug : canonicalIngredientSlug($name);
    if (canonicalIngredientFoodOnSkipSlug($slug)) {
        return null;
    }
    $query = canonicalIngredientFoodOnPreferredQuery($slug, $name);
    $cacheKey = FOODON_LOOKUP_CACHE_VERSION . ':' . canonicalIngredientSlug($query);
    $ttlDays = max(1, (int)(function_exists('env') ? env('FOODON_CACHE_TTL_DAYS', '30') : '30'));
    $ttlSeconds = $ttlDays * 86400;
    $cache = canonicalIngredientFoodOnCacheLoad();
    $cached = $cache[$cacheKey] ?? null;
    if (is_array($cached) && isset($cached['ts']) && (time() - (int)$cached['ts']) < $ttlSeconds) {
        return !empty($cached['found']) && is_array($cached['foodon'] ?? null) ? $cached['foodon'] : null;
    }

    canonicalIngredientFoodOnThrottle();
    $timeout = max(2, (int)(function_exists('env') ? env('FOODON_TIMEOUT_SEC', '6') : '6'));
    $userAgent = function_exists('env')
        ? env('FOODON_USER_AGENT', 'EverShelf/1.0 (FoodOn integration; https://github.com/SFenton/EverShelf)')
        : 'EverShelf/1.0 (FoodOn integration; https://github.com/SFenton/EverShelf)';
    $url = 'https://www.ebi.ac.uk/ols4/api/search?' . http_build_query([
        'q' => $query,
        'ontology' => 'foodon',
        'rows' => 8,
        'type' => 'class',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Encoding: gzip, deflate',
            'User-Agent: ' . $userAgent,
        ],
        CURLOPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) {
        if (class_exists('EverLog', false)) {
            EverLog::warn('FoodOn lookup failed', ['query' => $query, 'http_code' => $code, 'error' => $err]);
        }
        return null;
    }

    $decoded = json_decode((string)$body, true);
    $docs = $decoded['response']['docs'] ?? [];
    $best = is_array($docs) ? canonicalIngredientFoodOnSelectBest($docs, $name, $query) : null;
    if (!$best) {
        canonicalIngredientFoodOnCacheStore($cacheKey, ['ts' => time(), 'found' => false, 'query' => $query]);
        return null;
    }

    $foodOn = [
        'id' => $best['obo_id'] ?? str_replace('_', ':', (string)$best['short_form']),
        'short_form' => $best['short_form'],
        'iri' => $best['iri'],
        'label' => $best['label'],
        'query' => $query,
        'source' => 'ebi_ols4',
        'match_score' => (int)$best['_match_score'],
    ];
    if (!empty($best['description'][0])) {
        $foodOn['description'] = mb_substr((string)$best['description'][0], 0, 500, 'UTF-8');
    }
    canonicalIngredientFoodOnCacheStore($cacheKey, ['ts' => time(), 'found' => true, 'query' => $query, 'foodon' => $foodOn]);
    return $foodOn;
}

function canonicalIngredientMergeExternalIds(?string $existingJson, array $newExternalIds): array {
    $existing = $existingJson ? json_decode($existingJson, true) : [];
    if (!is_array($existing)) {
        $existing = [];
    }
    return array_replace_recursive($existing, $newExternalIds);
}

function canonicalIngredientEnrichMappingsWithFoodOn(array $mappings): array {
    if (!canonicalIngredientFoodOnLookupOnSave()) {
        return $mappings;
    }
    foreach ($mappings as &$mapping) {
        if (!empty($mapping['external_ids']['foodon'])) {
            continue;
        }
        $foodOn = canonicalIngredientFoodOnLookup(
            (string)($mapping['name'] ?? ''),
            (string)($mapping['slug'] ?? ''),
            (string)($mapping['category'] ?? '')
        );
        if ($foodOn) {
            $mapping['external_ids']['foodon'] = $foodOn;
        }
    }
    unset($mapping);
    return $mappings;
}

function canonicalIngredientUpsert(PDO $db, array $mapping): int {
    $externalIds = $mapping['external_ids'] ?? [];
    if (!empty($externalIds)) {
        $existing = $db->prepare("SELECT external_ids_json FROM canonical_ingredients WHERE slug = ?");
        $existing->execute([$mapping['slug']]);
        $externalIds = canonicalIngredientMergeExternalIds($existing->fetchColumn() ?: null, $externalIds);
    }
    $stmt = $db->prepare("
        INSERT INTO canonical_ingredients (slug, name, parent_slug, category, source, external_ids_json, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(slug) DO UPDATE SET
            name = excluded.name,
            parent_slug = COALESCE(excluded.parent_slug, canonical_ingredients.parent_slug),
            category = COALESCE(NULLIF(excluded.category, ''), canonical_ingredients.category),
            source = excluded.source,
            external_ids_json = COALESCE(excluded.external_ids_json, canonical_ingredients.external_ids_json),
            updated_at = CURRENT_TIMESTAMP
    ");
    $externalJson = !empty($externalIds)
        ? json_encode($externalIds, JSON_UNESCAPED_UNICODE)
        : null;
    $stmt->execute([
        $mapping['slug'],
        $mapping['name'],
        $mapping['parent_slug'] ?? null,
        $mapping['category'] ?? '',
        CANONICAL_INGREDIENT_RULESET_VERSION,
        $externalJson,
    ]);
    $id = $db->prepare("SELECT id FROM canonical_ingredients WHERE slug = ?");
    $id->execute([$mapping['slug']]);
    return (int)$id->fetchColumn();
}

function canonicalIngredientSyncProduct(PDO $db, int $productId, ?array $product = null): array {
    if ($product === null) {
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$product) {
        return ['product_id' => $productId, 'mapped' => 0, 'mappings' => [], 'tags' => []];
    }

    $mappings = canonicalIngredientInferProduct($product);
    if (str_contains(canonicalIngredientSearchText($product), 'dairy free')) {
        $mappings = array_values(array_filter($mappings, static function(array $mapping): bool {
            return !(($mapping['role'] ?? '') === 'contains' && ($mapping['slug'] ?? '') === 'milk');
        }));
    }
    $mappings = canonicalIngredientEnrichMappingsWithFoodOn($mappings);
    $mappings = canonicalIngredientEnrichMappingsWithUsda($mappings);
    $tags = canonicalProductInferTags($product, $mappings);
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM product_ingredients WHERE product_id = ? AND source != 'manual'")
           ->execute([$productId]);
        $db->prepare("DELETE FROM product_tags WHERE product_id = ? AND source != 'manual'")
           ->execute([$productId]);
        $link = $db->prepare("
            INSERT INTO product_ingredients (product_id, ingredient_id, role, confidence, source, evidence, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(product_id, ingredient_id, role) DO UPDATE SET
                confidence = excluded.confidence,
                source = excluded.source,
                evidence = excluded.evidence,
                updated_at = CURRENT_TIMESTAMP
        ");
        foreach ($mappings as $mapping) {
            $ingredientId = canonicalIngredientUpsert($db, $mapping);
            $link->execute([
                $productId,
                $ingredientId,
                $mapping['role'],
                $mapping['confidence'],
                $mapping['source'],
                $mapping['evidence'],
            ]);
        }
        $tagStmt = $db->prepare("
            INSERT INTO product_tags (product_id, facet, value, source, confidence, evidence, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(product_id, facet, value) DO UPDATE SET
                source = excluded.source,
                confidence = excluded.confidence,
                evidence = excluded.evidence,
                updated_at = CURRENT_TIMESTAMP
        ");
        foreach ($tags as $tag) {
            $tagStmt->execute([
                $productId,
                $tag['facet'],
                $tag['value'],
                $tag['source'],
                $tag['confidence'],
                $tag['evidence'],
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return ['product_id' => $productId, 'mapped' => count($mappings), 'mappings' => $mappings, 'tags' => $tags];
}

function canonicalProductTagPut(array &$tags, string $facet, string $value, string $source, float $confidence, string $evidence): void {
    $facet = canonicalIngredientSlug($facet);
    $value = canonicalIngredientSlug($value);
    if ($facet === '' || $value === '') {
        return;
    }
    $key = "{$facet}:{$value}";
    if (isset($tags[$key]) && (float)$tags[$key]['confidence'] >= $confidence) {
        return;
    }
    $tags[$key] = [
        'facet' => $facet,
        'value' => $value,
        'source' => $source,
        'confidence' => max(0, min(1, $confidence)),
        'evidence' => mb_substr($evidence, 0, 300, 'UTF-8'),
    ];
}

function canonicalProductInferTags(array $product, array $mappings): array {
    $tags = [];
    $text = canonicalIngredientNormalizeText(implode(' ', array_filter([
        $product['name'] ?? '',
        $product['brand'] ?? '',
        $product['category'] ?? '',
        $product['off_generic_name'] ?? '',
        $product['ingredients_text'] ?? '',
        $product['package_unit'] ?? '',
        $product['unit'] ?? '',
    ], static fn($v) => trim((string)$v) !== '')));

    $rules = [
        ['form', 'liquid', '/\b(stock|broth|sauce|milk|heavy cream|whipping cream|half and half|juice|vinegar|oil|wine|soju|soft drink|beverage|tamari)\b/u', 0.78],
        ['form', 'spread', '/\b(cream cheese|peanut butter|nut butter|marmalade|spread)\b/u', 0.86],
        ['form', 'paste', '/\b(paste|base|concentrate)\b/u', 0.86],
        ['form', 'cube', '/\b(cube|cubes)\b/u', 0.84],
        ['form', 'powder', '/\b(powder|powdered|baking powder|baking soda|starch|gelatin|gelatine)\b/u', 0.86],
        ['form', 'pod', '/\b(k cup|coffee pods?|pods?)\b/u', 0.94],
        ['form', 'sliced', '/\b(sliced|slices)\b/u', 0.9],
        ['form', 'shredded', '/\b(shredded|grated)\b/u', 0.9],
        ['form', 'whole', '/\b(whole|unroasted|unsalted almonds|peanuts)\b/u', 0.74],
        ['form', 'liquid', '/\b(buzzball|martini|wine|soju|soda|soft drink|sports drink|gatorade|lime juice|juice|coconutmilk)\b/u', 0.82],
        ['preparation', 'smoked', '/\bsmoked\b/u', 0.92],
        ['preparation', 'roasted', '/\broasted\b/u', 0.9],
        ['preparation', 'fried', '/\bfried\b/u', 0.9],
        ['preparation', 'dried', '/\b(dried|sun dried|dehydrated)\b/u', 0.9],
        ['preparation', 'frozen', '/\b(frozen|freezer|surgelat)\b/u', 0.82],
        ['preparation', 'pickled', '/\b(pickle|pickled|dill chips|jalape(?:n|ñ)o peppers|pepper rings|vinegar)\b/u', 0.84],
        ['preparation', 'fermented', '/\b(fermented|soy sauce|tamari|miso|yogurt|sour cream)\b/u', 0.82],
        ['diet', 'dairy-free', '/\bdairy free\b/u', 0.95],
        ['label', 'organic', '/\borganic\b/u', 0.9],
        ['label', 'low-moisture', '/\blow moisture\b/u', 0.88],
        ['label', 'low-sodium', '/\blow sodium|low sodium\b/u', 0.88],
        ['use', 'concentrate', '/\b(base|concentrate|bouillon)\b/u', 0.88],
        ['use', 'condiment', '/\b(mustard|ketchup|mayo|mayonnaise|sauce|dressing|salsa|chutney|vinegar)\b/u', 0.8],
        ['use', 'pizza-sauce', '/\b(marinara|pizza\s+sauce)\b/u', 0.86],
        ['use', 'baking', '/\b(baking|flour|brown sugar|starch|gelatin|breadcrumbs|bread crumbs)\b/u', 0.82],
        ['use', 'ready-to-eat', '/\b(cookies|pizza|salad|cake|leftovers|taco)\b/u', 0.72],
        ['use', 'single-serve', '/\b(k cup|coffee pods?|pods?)\b/u', 0.92],
        ['use', 'coffee-additive', '/\b(coffee\s*mate|coffee\s+creamer|creamer)\b/u', 0.88],
        ['use', 'cooking', '/\bcooking\s+wine\b/u', 0.9],
        ['packaging', 'k-cup', '/\bk cup\b/u', 0.94],
    ];
    foreach ($rules as [$facet, $value, $rx, $confidence]) {
        if (preg_match($rx, $text, $match)) {
            canonicalProductTagPut($tags, $facet, $value, 'local_rule', (float)$confidence, 'matched "' . trim((string)$match[0]) . '"');
        }
    }

    foreach ($mappings as $mapping) {
        $role = (string)($mapping['role'] ?? '');
        $name = (string)($mapping['name'] ?? '');
        $slug = (string)($mapping['slug'] ?? canonicalIngredientSlug($name));
        if ($role === 'primary') {
            canonicalProductTagPut($tags, 'canonical-primary', $slug, 'canonical', 0.99, 'primary canonical term');
        }
        if ($role === 'contains') {
            canonicalProductTagPut($tags, 'contains', $slug, 'canonical', (float)($mapping['confidence'] ?? 0.8), 'canonical contains term');
        }
        if ($slug === 'milk' && str_contains($text, 'dairy free')) {
            continue;
        }
        if (in_array($slug, ['chicken','beef','pork','duck','tomato','rice','corn','garlic','almonds','peanuts','coconut','sesame','milk','oyster','tamarind'], true)) {
            canonicalProductTagPut($tags, 'source', $slug, 'canonical', 0.86, 'canonical ingredient/source');
        }
    }

    $nutriments = json_decode((string)($product['nutriments_json'] ?? ''), true);
    if (is_array($nutriments)) {
        if ((float)($nutriments['proteins_100g'] ?? 0) >= 15) {
            canonicalProductTagPut($tags, 'nutrition', 'high-protein', 'nutriments', 0.78, 'protein >= 15g/100g');
        }
        if ((float)($nutriments['salt_100g'] ?? 0) >= 1.5) {
            canonicalProductTagPut($tags, 'nutrition', 'salty', 'nutriments', 0.70, 'salt >= 1.5g/100g');
        }
    }

    uasort($tags, static fn($a, $b) => [$a['facet'], $a['value']] <=> [$b['facet'], $b['value']]);
    return array_values($tags);
}

function canonicalProductTagRowsForProduct(PDO $db, int $productId): array {
    $stmt = $db->prepare("
        SELECT facet, value, source, confidence, evidence, updated_at
        FROM product_tags
        WHERE product_id = ?
        ORDER BY facet ASC, value ASC
    ");
    $stmt->execute([$productId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['confidence'] = round((float)$row['confidence'], 3);
    }
    unset($row);
    return $rows;
}

function canonicalIngredientEnqueueProduct(PDO $db, int $productId, string $reason = 'product_save'): array {
    if ($productId <= 0) {
        return ['queued' => false, 'status' => 'invalid_product'];
    }
    $stmt = $db->prepare("
        INSERT INTO canonical_processing_queue (product_id, reason, status, attempts, last_error, requested_at, started_at, processed_at, updated_at)
        VALUES (?, ?, 'pending', 0, '', CURRENT_TIMESTAMP, NULL, NULL, CURRENT_TIMESTAMP)
        ON CONFLICT(product_id) DO UPDATE SET
            reason = excluded.reason,
            status = 'pending',
            attempts = 0,
            last_error = '',
            requested_at = CURRENT_TIMESTAMP,
            started_at = NULL,
            processed_at = NULL,
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$productId, mb_substr($reason, 0, 80, 'UTF-8')]);

    $status = canonicalIngredientQueueStatusForProduct($db, $productId);
    return [
        'queued' => true,
        'queue_id' => (int)($status['id'] ?? 0),
        'status' => $status['status'] ?? 'pending',
        'reason' => $reason,
    ];
}

function canonicalIngredientQueueStatusForProduct(PDO $db, int $productId): ?array {
    $stmt = $db->prepare("SELECT * FROM canonical_processing_queue WHERE product_id = ?");
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function canonicalIngredientQueueStats(PDO $db): array {
    $stats = ['pending' => 0, 'in_progress' => 0, 'done' => 0, 'failed' => 0];
    foreach ($db->query("SELECT status, COUNT(*) AS c FROM canonical_processing_queue GROUP BY status") as $row) {
        $stats[$row['status']] = (int)$row['c'];
    }
    return $stats;
}

function canonicalIngredientProcessQueue(PDO $db, int $limit = 5, int $maxAttempts = 3): array {
    $limit = max(0, min(50, $limit));
    if ($limit === 0) {
        return [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'pending' => canonicalIngredientQueueStats($db)['pending'] ?? 0,
            'items' => [],
        ];
    }

    $maxAttempts = max(1, $maxAttempts);
    $stmt = $db->prepare("
        SELECT q.id, q.product_id, q.attempts
        FROM canonical_processing_queue q
        JOIN products p ON p.id = q.product_id
        WHERE q.status IN ('pending', 'failed')
          AND q.attempts < ?
        ORDER BY q.requested_at ASC, q.id ASC
        LIMIT {$limit}
    ");
    $stmt->execute([$maxAttempts]);
    $queueRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $processed = 0;
    $succeeded = 0;
    $failed = 0;
    $items = [];
    foreach ($queueRows as $queueRow) {
        $queueId = (int)$queueRow['id'];
        $productId = (int)$queueRow['product_id'];
        $processed++;
        $db->prepare("
            UPDATE canonical_processing_queue
            SET status = 'in_progress', attempts = attempts + 1, started_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$queueId]);

        try {
            $result = canonicalIngredientSyncProduct($db, $productId);
            $mapped = (int)($result['mapped'] ?? 0);
            $db->prepare("
                UPDATE canonical_processing_queue
                SET status = 'done', processed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP, last_error = ''
                WHERE id = ?
            ")->execute([$queueId]);
            $succeeded++;
            $items[] = ['product_id' => $productId, 'status' => 'done', 'mapped' => $mapped];
        } catch (Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 500, 'UTF-8');
            $db->prepare("
                UPDATE canonical_processing_queue
                SET status = 'failed', last_error = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$message, $queueId]);
            if (class_exists('EverLog', false)) {
                EverLog::exception($e, 'canonical_queue');
            }
            $failed++;
            $items[] = ['product_id' => $productId, 'status' => 'failed', 'error' => $message];
        }
    }

    $stats = canonicalIngredientQueueStats($db);
    return [
        'processed' => $processed,
        'succeeded' => $succeeded,
        'failed' => $failed,
        'pending' => $stats['pending'] ?? 0,
        'items' => $items,
    ];
}

function canonicalIngredientRowsForProduct(PDO $db, int $productId): array {
    $stmt = $db->prepare("
        SELECT ci.id, ci.slug, ci.name, ci.parent_slug, ci.category, ci.external_ids_json,
               pi.role, pi.confidence, pi.source, pi.evidence, pi.updated_at
        FROM product_ingredients pi
        JOIN canonical_ingredients ci ON ci.id = pi.ingredient_id
        WHERE pi.product_id = ?
        ORDER BY
            CASE pi.role WHEN 'primary' THEN 0 WHEN 'contains' THEN 1 WHEN 'broader' THEN 2 ELSE 3 END,
            pi.confidence DESC,
            ci.name ASC
    ");
    $stmt->execute([$productId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['confidence'] = round((float)$row['confidence'], 3);
        $ids = json_decode((string)($row['external_ids_json'] ?? ''), true);
        $row['external_ids'] = is_array($ids) ? $ids : [];
        unset($row['external_ids_json']);
    }
    unset($row);
    return $rows;
}

function canonicalIngredientNamesByProduct(PDO $db, array $productIds, array $roles = []): array {
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn($id) => $id > 0)));
    if (empty($productIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $roleWhere = '';
    $params = $productIds;
    if (!empty($roles)) {
        $rolePlaceholders = implode(',', array_fill(0, count($roles), '?'));
        $roleWhere = " AND pi.role IN ($rolePlaceholders)";
        $params = array_merge($params, array_values($roles));
    }
    $stmt = $db->prepare("
        SELECT pi.product_id, ci.name, pi.role, pi.confidence
        FROM product_ingredients pi
        JOIN canonical_ingredients ci ON ci.id = pi.ingredient_id
        WHERE pi.product_id IN ($placeholders)
        $roleWhere
        ORDER BY
            pi.product_id,
            CASE pi.role WHEN 'primary' THEN 0 WHEN 'contains' THEN 1 WHEN 'broader' THEN 2 ELSE 3 END,
            pi.confidence DESC
    ");
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int)$row['product_id'];
        $out[$pid] ??= [];
        $name = trim((string)$row['name']);
        if ($name !== '' && !in_array($name, $out[$pid], true)) {
            $out[$pid][] = $name;
        }
    }
    return $out;
}

function canonicalIngredientEnrichFoodOnTable(PDO $db, bool $missingOnly = true, int $limit = 0): array {
    $where = $missingOnly
        ? "WHERE external_ids_json IS NULL OR external_ids_json NOT LIKE '%\"foodon\"%'"
        : "";
    $sql = "SELECT id, slug, name, category, external_ids_json FROM canonical_ingredients $where ORDER BY name ASC";
    if ($limit > 0) {
        $sql .= " LIMIT " . (int)$limit;
    }
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $updated = 0;
    $misses = 0;
    $examples = [];
    $stmt = $db->prepare("UPDATE canonical_ingredients SET external_ids_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    foreach ($rows as $row) {
        $foodOn = canonicalIngredientFoodOnLookup((string)$row['name'], (string)$row['slug'], (string)($row['category'] ?? ''));
        if (!$foodOn) {
            $misses++;
            continue;
        }
        $externalIds = canonicalIngredientMergeExternalIds($row['external_ids_json'] ?? null, ['foodon' => $foodOn]);
        $stmt->execute([json_encode($externalIds, JSON_UNESCAPED_UNICODE), (int)$row['id']]);
        $updated++;
        if (count($examples) < 20) {
            $examples[] = [
                'name' => $row['name'],
                'foodon_id' => $foodOn['id'] ?? '',
                'foodon_label' => $foodOn['label'] ?? '',
                'query' => $foodOn['query'] ?? '',
            ];
        }
    }

    return [
        'processed' => count($rows),
        'updated' => $updated,
        'misses' => $misses,
        'examples' => $examples,
    ];
}

function canonicalIngredientFoodOnStats(PDO $db, bool $activeOnly = true): array {
    $termsTotal = (int)$db->query("SELECT COUNT(*) FROM canonical_ingredients")->fetchColumn();
    $termsWithFoodOn = (int)$db->query("SELECT COUNT(*) FROM canonical_ingredients WHERE external_ids_json LIKE '%\"foodon\"%'")->fetchColumn();
    $productWhere = $activeOnly
        ? "AND EXISTS (SELECT 1 FROM inventory i WHERE i.product_id = p.id AND i.quantity > 0)"
        : "";
    $productsWithPrimaryFoodOn = (int)$db->query("
        SELECT COUNT(DISTINCT p.id)
        FROM products p
        JOIN product_ingredients pi ON pi.product_id = p.id AND pi.role = 'primary'
        JOIN canonical_ingredients ci ON ci.id = pi.ingredient_id
        WHERE ci.external_ids_json LIKE '%\"foodon\"%' $productWhere
    ")->fetchColumn();
    $productsWithAnyFoodOn = (int)$db->query("
        SELECT COUNT(DISTINCT p.id)
        FROM products p
        JOIN product_ingredients pi ON pi.product_id = p.id
        JOIN canonical_ingredients ci ON ci.id = pi.ingredient_id
        WHERE ci.external_ids_json LIKE '%\"foodon\"%' $productWhere
    ")->fetchColumn();
    return [
        'canonical_terms_total' => $termsTotal,
        'canonical_terms_with_foodon' => $termsWithFoodOn,
        'canonical_terms_foodon_pct' => $termsTotal > 0 ? round(($termsWithFoodOn / $termsTotal) * 100, 1) : 0,
        'products_with_primary_foodon' => $productsWithPrimaryFoodOn,
        'products_with_any_foodon' => $productsWithAnyFoodOn,
    ];
}

function canonicalIngredientUsdaEnabled(): bool {
    $enabled = canonicalIngredientEnvBool('USDA_FDC_ENABLED', true);
    $apiKey = trim((string)(function_exists('env') ? env('USDA_FDC_API_KEY', '') : ''));
    return $enabled && $apiKey !== '';
}

function canonicalIngredientUsdaLookupOnSave(): bool {
    return canonicalIngredientEnvBool('USDA_FDC_LOOKUP_ON_SAVE', true);
}

function canonicalIngredientUsdaCacheLoad(): array {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $cache = [];
    $path = defined('USDA_FDC_CACHE_PATH') ? USDA_FDC_CACHE_PATH : (__DIR__ . '/../../data/usda_fdc_lookup_cache.json');
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $cache = $decoded;
        }
    }
    return $cache;
}

function canonicalIngredientUsdaCacheStore(string $key, array $entry): void {
    $cache = canonicalIngredientUsdaCacheLoad();
    $cache[$key] = $entry;
    $path = defined('USDA_FDC_CACHE_PATH') ? USDA_FDC_CACHE_PATH : (__DIR__ . '/../../data/usda_fdc_lookup_cache.json');
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $tmp = $path . '.tmp';
    @file_put_contents($tmp, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    if (is_file($tmp)) {
        @rename($tmp, $path);
    }
}

function canonicalIngredientUsdaPreferredQuery(string $slug, string $name): string {
    static $queries = [
        'arborio-rice' => 'rice white short grain raw',
        'rice' => 'rice white raw',
        'grain' => 'rice white raw',
        'chicken-breast' => 'chicken breast raw',
        'chicken' => 'chicken raw',
        'chicken-stock' => 'chicken broth',
        'beef-stock' => 'beef broth',
        'bacon' => 'pork cured bacon',
        'black-beans' => 'beans black mature seeds raw',
        'mustard' => 'mustard prepared yellow',
        'brown-mustard' => 'mustard prepared yellow',
        'spicy-brown-mustard' => 'mustard prepared yellow',
        'yellow-mustard' => 'mustard prepared yellow',
        'heavy-cream' => 'cream heavy',
        'cream' => 'cream heavy',
        'cream-cheese' => 'cream cheese',
        'butter' => 'butter salted',
        'peanut-butter' => 'peanut butter',
        'carrot' => 'carrots raw',
        'tomato' => 'tomatoes raw',
        'tomato-sauce' => 'sauce tomato canned',
        'marinara-sauce' => 'sauce marinara',
        'barbecue-sauce' => 'sauce barbecue',
        'soy-sauce' => 'soy sauce',
        'bread' => 'bread white commercially prepared',
        'brown-sugar' => 'sugars brown',
        'buns' => 'hamburger bun plain',
        'cake' => 'cake yellow commercially prepared',
        'coffee' => 'coffee brewed',
        'coffee-pod' => 'coffee pod',
        'coffee-creamer' => 'coffee creamer',
        'creamer' => 'coffee creamer',
        'cucumber' => 'cucumber raw',
        'almonds' => 'almonds raw',
        'peanuts' => 'peanuts raw',
        'honey' => 'honey',
        'maple-syrup' => 'syrup maple',
        'molasses' => 'molasses',
        'baking-powder' => 'baking powder',
        'baking-soda' => 'baking soda',
        'corn-starch' => 'cornstarch',
        'gelatin' => 'gelatin dry powder',
        'coconut-milk' => 'coconut milk',
        'sesame-oil' => 'oil sesame',
        'sesame-seeds' => 'seeds sesame',
        'vinegar' => 'vinegar',
        'lime-juice' => 'lime juice',
        'garlic' => 'garlic raw',
        'ginger' => 'ginger root raw',
        'spinach' => 'spinach raw',
        'cauliflower' => 'cauliflower raw',
        'green-onion' => 'onions spring raw',
        'onion' => 'onions raw',
        'pineapple' => 'pineapple raw',
        'mozzarella' => 'cheese mozzarella',
        'parmesan' => 'cheese parmesan',
        'swiss-cheese' => 'cheese swiss',
        'cheese' => 'cheese',
        'milk' => 'milk whole',
        'evaporated-milk' => 'milk evaporated',
        'condensed-milk' => 'milk condensed sweetened',
        'sour-cream' => 'sour cream',
        'half-and-half' => 'cream half and half',
        'soda' => 'soft drink cola',
        'sports-drink' => 'sports drink',
        'oyster' => 'oyster raw',
        'pizza' => 'pizza cheese regular crust',
    ];
    return $queries[$slug] ?? $name;
}

function canonicalIngredientUsdaSkipSlug(string $slug): bool {
    static $skip = [
        // USDA FDC is useful for concrete food/nutrition references, not broad classes.
        'alcoholic-beverage' => true,
        'beans' => true,
        'beef' => true,
        'beverage' => true,
        'buns' => true,
        'cake' => true,
        'chicken' => true,
        'condiment' => true,
        'dairy' => true,
        'dessert' => true,
        'flavoring' => true,
        'fruit' => true,
        'grain' => true,
        'meat' => true,
        'milk-alternative' => true,
        'nuts' => true,
        'oil' => true,
        'prepared-meal' => true,
        'poultry' => true,
        'salad' => true,
        'sauce' => true,
        'seafood' => true,
        'seed' => true,
        'seasoning' => true,
        'sesame' => true,
        'spice' => true,
        'soup' => true,
        'spread' => true,
        'starch' => true,
        'stock' => true,
        'sweetener' => true,
        'tamarind' => true,
        'vegetable' => true,
        'vegetables' => true,
        'wheat' => true,
    ];
    return isset($skip[$slug]);
}

function canonicalIngredientUsdaNormalizeText(string $text): string {
    $text = canonicalIngredientNormalizeText($text);
    $text = preg_replace('/\b(raw|cooked|boiled|canned|dry|powder|prepared|yellow|white|whole|salted|unsalted)\b/u', ' ', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function canonicalIngredientUsdaSelectBest(array $foods, string $name, string $query): ?array {
    $target = canonicalIngredientUsdaNormalizeText($name);
    $queryNorm = canonicalIngredientUsdaNormalizeText($query);
    $best = null;
    $bestScore = 0;
    foreach ($foods as $food) {
        $description = (string)($food['description'] ?? '');
        $fdcId = (int)($food['fdcId'] ?? 0);
        if ($description === '' || $fdcId <= 0) {
            continue;
        }
        $descNorm = canonicalIngredientUsdaNormalizeText($description);
        $rawDescNorm = canonicalIngredientNormalizeText($description);
        $dataType = (string)($food['dataType'] ?? '');
        $score = 0;
        if ($descNorm === $target) $score += 70;
        if ($descNorm === $queryNorm) $score += 70;
        if ($target !== '' && str_contains($descNorm, $target)) $score += 35;
        if ($queryNorm !== '' && str_contains($descNorm, $queryNorm)) $score += 35;
        if ($descNorm !== '' && str_contains($target, $descNorm)) $score += 20;
        if ($dataType === 'Foundation') $score += 14;
        if ($dataType === 'SR Legacy') $score += 10;
        if (str_contains($rawDescNorm, 'babyfood')) $score -= 25;
        if (str_contains($rawDescNorm, 'snacks')) $score -= 20;
        if (str_contains($rawDescNorm, 'toasted') && !str_contains($queryNorm, 'toasted')) $score -= 18;
        if (str_contains($rawDescNorm, 'restaurant') && !str_contains($queryNorm, 'restaurant')) $score -= 8;
        if (str_contains($rawDescNorm, 'breaded')) $score -= 15;
        if (str_contains($rawDescNorm, 'with ') && !str_contains($queryNorm, 'with ')) $score -= 8;
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $food + ['_match_score' => $score];
        }
    }
    return $bestScore >= 35 ? $best : null;
}

function canonicalIngredientUsdaThrottle(): void {
    static $lastRequestAt = 0.0;
    $intervalMs = max(0, (int)(function_exists('env') ? env('USDA_FDC_MIN_REQUEST_INTERVAL_MS', '4000') : '4000'));
    if ($intervalMs <= 0) {
        return;
    }
    $now = microtime(true);
    $elapsedMs = ($now - $lastRequestAt) * 1000;
    if ($lastRequestAt > 0 && $elapsedMs < $intervalMs) {
        usleep((int)(($intervalMs - $elapsedMs) * 1000));
    }
    $lastRequestAt = microtime(true);
}

function canonicalIngredientUsdaLookup(string $name, string $slug = '', string $category = ''): ?array {
    static $circuitUntil = 0;
    if (!canonicalIngredientUsdaEnabled() || trim($name) === '' || time() < $circuitUntil) {
        return null;
    }

    $slug = $slug !== '' ? $slug : canonicalIngredientSlug($name);
    if (canonicalIngredientUsdaSkipSlug($slug)) {
        return null;
    }
    $query = canonicalIngredientUsdaPreferredQuery($slug, $name);
    $cacheKey = USDA_FDC_LOOKUP_CACHE_VERSION . ':' . canonicalIngredientSlug($query);
    $ttlDays = max(1, (int)(function_exists('env') ? env('USDA_FDC_CACHE_TTL_DAYS', '30') : '30'));
    $ttlSeconds = $ttlDays * 86400;
    $cache = canonicalIngredientUsdaCacheLoad();
    $cached = $cache[$cacheKey] ?? null;
    if (is_array($cached) && isset($cached['ts']) && (time() - (int)$cached['ts']) < $ttlSeconds) {
        return !empty($cached['found']) && is_array($cached['usda_fdc'] ?? null) ? $cached['usda_fdc'] : null;
    }

    canonicalIngredientUsdaThrottle();
    $apiKey = trim((string)env('USDA_FDC_API_KEY', ''));
    $timeout = max(2, (int)env('USDA_FDC_TIMEOUT_SEC', '8'));
    $userAgent = env('USDA_FDC_USER_AGENT', 'EverShelf/1.0 (USDA FDC integration; https://github.com/SFenton/EverShelf)');
    $url = 'https://api.nal.usda.gov/fdc/v1/foods/search?' . http_build_query([
        'api_key' => $apiKey,
        'query' => $query,
        'pageSize' => 8,
        'requireAllWords' => 'false',
    ]) . '&dataType=Foundation&dataType=SR%20Legacy';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Encoding: gzip, deflate',
            'User-Agent: ' . $userAgent,
        ],
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => true,
    ]);
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);

    $headers = is_string($response) ? substr($response, 0, $headerSize) : '';
    $body = is_string($response) ? substr($response, $headerSize) : '';
    if ($response === false || $code < 200 || $code >= 300) {
        if ($code === 429) {
            $retrySeconds = 3600;
            if (preg_match('/Retry-After:\s*(\d+)/i', $headers, $m)) {
                $retrySeconds = max(60, (int)$m[1]);
            }
            $circuitUntil = time() + $retrySeconds;
        }
        if (class_exists('EverLog', false)) {
            EverLog::warn('USDA FDC lookup failed', ['query' => $query, 'http_code' => $code, 'error' => $err]);
        }
        return null;
    }

    $decoded = json_decode($body, true);
    $foods = $decoded['foods'] ?? [];
    $best = is_array($foods) ? canonicalIngredientUsdaSelectBest($foods, $name, $query) : null;
    if (!$best) {
        canonicalIngredientUsdaCacheStore($cacheKey, ['ts' => time(), 'found' => false, 'query' => $query]);
        return null;
    }

    $fdc = [
        'fdc_id' => (int)$best['fdcId'],
        'description' => $best['description'],
        'data_type' => $best['dataType'] ?? '',
        'food_category' => $best['foodCategory'] ?? '',
        'query' => $query,
        'source' => 'usda_fdc',
        'match_score' => (int)$best['_match_score'],
    ];
    canonicalIngredientUsdaCacheStore($cacheKey, ['ts' => time(), 'found' => true, 'query' => $query, 'usda_fdc' => $fdc]);
    return $fdc;
}

function canonicalIngredientEnrichMappingsWithUsda(array $mappings): array {
    if (!canonicalIngredientUsdaLookupOnSave()) {
        return $mappings;
    }
    foreach ($mappings as &$mapping) {
        if (!empty($mapping['external_ids']['usda_fdc'])) {
            continue;
        }
        $fdc = canonicalIngredientUsdaLookup(
            (string)($mapping['name'] ?? ''),
            (string)($mapping['slug'] ?? ''),
            (string)($mapping['category'] ?? '')
        );
        if ($fdc) {
            $mapping['external_ids']['usda_fdc'] = $fdc;
        }
    }
    unset($mapping);
    return $mappings;
}

function canonicalIngredientEnrichUsdaTable(PDO $db, bool $missingOnly = true, int $limit = 0): array {
    $where = $missingOnly
        ? "WHERE external_ids_json IS NULL OR external_ids_json NOT LIKE '%\"usda_fdc\"%'"
        : "";
    $sql = "SELECT id, slug, name, category, external_ids_json FROM canonical_ingredients $where ORDER BY name ASC";
    if ($limit > 0) {
        $sql .= " LIMIT " . (int)$limit;
    }
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $updated = 0;
    $misses = 0;
    $examples = [];
    $stmt = $db->prepare("UPDATE canonical_ingredients SET external_ids_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    foreach ($rows as $row) {
        $fdc = canonicalIngredientUsdaLookup((string)$row['name'], (string)$row['slug'], (string)($row['category'] ?? ''));
        if (!$fdc) {
            $misses++;
            continue;
        }
        $externalIds = canonicalIngredientMergeExternalIds($row['external_ids_json'] ?? null, ['usda_fdc' => $fdc]);
        $stmt->execute([json_encode($externalIds, JSON_UNESCAPED_UNICODE), (int)$row['id']]);
        $updated++;
        if (count($examples) < 20) {
            $examples[] = [
                'name' => $row['name'],
                'fdc_id' => $fdc['fdc_id'] ?? 0,
                'description' => $fdc['description'] ?? '',
                'data_type' => $fdc['data_type'] ?? '',
                'query' => $fdc['query'] ?? '',
            ];
        }
    }

    return [
        'processed' => count($rows),
        'updated' => $updated,
        'misses' => $misses,
        'examples' => $examples,
    ];
}

function canonicalIngredientUsdaStats(PDO $db, bool $activeOnly = true): array {
    $termsTotal = (int)$db->query("SELECT COUNT(*) FROM canonical_ingredients")->fetchColumn();
    $termsWithUsda = (int)$db->query("SELECT COUNT(*) FROM canonical_ingredients WHERE external_ids_json LIKE '%\"usda_fdc\"%'")->fetchColumn();
    $productWhere = $activeOnly
        ? "AND EXISTS (SELECT 1 FROM inventory i WHERE i.product_id = p.id AND i.quantity > 0)"
        : "";
    $productsWithPrimaryUsda = (int)$db->query("
        SELECT COUNT(DISTINCT p.id)
        FROM products p
        JOIN product_ingredients pi ON pi.product_id = p.id AND pi.role = 'primary'
        JOIN canonical_ingredients ci ON ci.id = pi.ingredient_id
        WHERE ci.external_ids_json LIKE '%\"usda_fdc\"%' $productWhere
    ")->fetchColumn();
    $productsWithAnyUsda = (int)$db->query("
        SELECT COUNT(DISTINCT p.id)
        FROM products p
        JOIN product_ingredients pi ON pi.product_id = p.id
        JOIN canonical_ingredients ci ON ci.id = pi.ingredient_id
        WHERE ci.external_ids_json LIKE '%\"usda_fdc\"%' $productWhere
    ")->fetchColumn();
    return [
        'canonical_terms_total' => $termsTotal,
        'canonical_terms_with_usda_fdc' => $termsWithUsda,
        'canonical_terms_usda_fdc_pct' => $termsTotal > 0 ? round(($termsWithUsda / $termsTotal) * 100, 1) : 0,
        'products_with_primary_usda_fdc' => $productsWithPrimaryUsda,
        'products_with_any_usda_fdc' => $productsWithAnyUsda,
        'enabled' => canonicalIngredientUsdaEnabled(),
    ];
}

function canonicalIngredientAssess(PDO $db, bool $activeOnly = true): array {
    $where = $activeOnly
        ? "WHERE EXISTS (SELECT 1 FROM inventory i WHERE i.product_id = p.id AND i.quantity > 0)"
        : "";
    $products = $db->query("SELECT p.id, p.name, p.brand, p.category, p.shopping_name FROM products p $where ORDER BY p.name")
        ->fetchAll(PDO::FETCH_ASSOC);
    $total = count($products);
    $ids = array_map(fn($p) => (int)$p['id'], $products);
    $aliases = canonicalIngredientNamesByProduct($db, $ids);

    $primaryStmt = $db->prepare("
        SELECT ci.name, pi.confidence, pi.source
        FROM product_ingredients pi
        JOIN canonical_ingredients ci ON ci.id = pi.ingredient_id
        WHERE pi.product_id = ? AND pi.role = 'primary'
        ORDER BY pi.confidence DESC LIMIT 1
    ");
    $roleCounts = [];
    $sourceCounts = [];
    foreach ($db->query("SELECT role, COUNT(*) c FROM product_ingredients GROUP BY role") as $row) {
        $roleCounts[$row['role']] = (int)$row['c'];
    }
    foreach ($db->query("SELECT source, COUNT(*) c FROM product_ingredients GROUP BY source") as $row) {
        $sourceCounts[$row['source']] = (int)$row['c'];
    }

    $matched = 0;
    $lowConfidence = [];
    $unmatched = [];
    $examples = [];
    foreach ($products as $product) {
        $pid = (int)$product['id'];
        $primaryStmt->execute([$pid]);
        $primary = $primaryStmt->fetch(PDO::FETCH_ASSOC);
        if ($primary) {
            $matched++;
            $conf = (float)$primary['confidence'];
            $example = [
                'product_id' => $pid,
                'product' => $product['name'],
                'brand' => $product['brand'] ?? '',
                'primary' => $primary['name'],
                'aliases' => array_slice($aliases[$pid] ?? [], 0, 6),
                'confidence' => round($conf, 3),
                'source' => $primary['source'],
            ];
            if (count($examples) < 12) {
                $examples[] = $example;
            }
            if ($conf < 0.70 && count($lowConfidence) < 20) {
                $lowConfidence[] = $example;
            }
        } elseif (count($unmatched) < 30) {
            $unmatched[] = [
                'product_id' => $pid,
                'product' => $product['name'],
                'brand' => $product['brand'] ?? '',
                'category' => $product['category'] ?? '',
            ];
        }
    }

    return [
        'scope' => $activeOnly ? 'active_inventory_products' : 'all_products',
        'products_total' => $total,
        'products_with_primary' => $matched,
        'coverage_pct' => $total > 0 ? round(($matched / $total) * 100, 1) : 0,
        'foodon' => canonicalIngredientFoodOnStats($db, $activeOnly),
        'usda_fdc' => canonicalIngredientUsdaStats($db, $activeOnly),
        'queue' => canonicalIngredientQueueStats($db),
        'role_counts' => $roleCounts,
        'source_counts' => $sourceCounts,
        'examples' => $examples,
        'low_confidence' => $lowConfidence,
        'unmatched' => $unmatched,
    ];
}
