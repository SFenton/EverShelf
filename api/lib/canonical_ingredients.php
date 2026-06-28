<?php
/**
 * Canonical ingredient inference and persistence.
 *
 * The rules are intentionally local and deterministic: external taxonomies
 * (Open Food Facts/FoodOn/FDC/Wikidata) can be linked through external_ids_json,
 * while product saves and backfills remain fast and offline-safe.
 */

const CANONICAL_INGREDIENT_RULESET_VERSION = 'evershelf_common_ingredients_v1';

function canonicalIngredientNormalizeText(string $text): string {
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = str_replace(
        ['’', "'", '`', '&', '/', '_', '+'],
        [' ', ' ', ' ', ' and ', ' ', ' ', ' plus '],
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
        ['rx' => '/\b(spicy\s+brown\s+mustard|brown\s+mustard)\b/u', 'path' => ['Spicy brown mustard', 'Brown mustard', 'Mustard', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.98],
        ['rx' => '/\bdijon\s+mustard\b/u', 'path' => ['Dijon mustard', 'Mustard', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.98],
        ['rx' => '/\b(yellow\s+mustard|classic\s+yellow\s+mustard)\b/u', 'path' => ['Yellow mustard', 'Mustard', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.98],
        ['rx' => '/\bmustard\b/u', 'path' => ['Mustard', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.94],
        ['rx' => '/\b(arrabbiata|marinara)\b/u', 'path' => ['Marinara sauce', 'Tomato sauce', 'Tomato', 'Sauce'], 'category' => 'sauces', 'confidence' => 0.93],
        ['rx' => '/\btomato\s+sauce\b/u', 'path' => ['Tomato sauce', 'Tomato', 'Sauce'], 'category' => 'sauces', 'confidence' => 0.95],
        ['rx' => '/\b(chili\s+garlic\s+sauce|hot\s+chili\s+sauce|sriracha)\b/u', 'path' => ['Chili garlic sauce', 'Chili pepper', 'Sauce'], 'contains' => ['Garlic'], 'category' => 'sauces', 'confidence' => 0.96],
        ['rx' => '/\b(chimichurri)\b/u', 'path' => ['Chimichurri sauce', 'Sauce'], 'category' => 'sauces', 'confidence' => 0.96],
        ['rx' => '/\b(steak\s+sauce)\b/u', 'path' => ['Steak sauce', 'Sauce'], 'category' => 'sauces', 'confidence' => 0.94],
        ['rx' => '/\b(barbecue|bbq)\s+sauce\b/u', 'path' => ['Barbecue sauce', 'Sauce'], 'category' => 'sauces', 'confidence' => 0.96],
        ['rx' => '/\b(ketchup)\b/u', 'path' => ['Ketchup', 'Tomato sauce', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.98],
        ['rx' => '/\b(mayonnaise|mayo)\b/u', 'path' => ['Mayonnaise', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.98],
        ['rx' => '/\b(hoisin\s+sauce)\b/u', 'path' => ['Hoisin sauce', 'Sauce'], 'category' => 'sauces', 'confidence' => 0.97],
        ['rx' => '/\b(oyster\s+sauce)\b/u', 'path' => ['Oyster sauce', 'Sauce'], 'contains' => ['Oyster'], 'category' => 'sauces', 'confidence' => 0.97],
        ['rx' => '/\b(tamari|soy\s+sauce)\b/u', 'path' => ['Soy sauce', 'Soybean', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.97],
        ['rx' => '/\b(caesar\s+dressing)\b/u', 'path' => ['Caesar dressing', 'Salad dressing', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.96],
        ['rx' => '/\b(red\s+curry\s+paste|curry\s+paste)\b/u', 'path' => ['Curry paste', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.96],
        ['rx' => '/\b(fermented\s+chile\s+paste|chile\s+paste|chili\s+paste)\b/u', 'path' => ['Chili paste', 'Chili pepper', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.95],
        ['rx' => '/\b(salsa\s+verde|chunky\s+salsa|salsa)\b/u', 'path' => ['Salsa', 'Sauce'], 'contains' => ['Tomato'], 'category' => 'sauces', 'confidence' => 0.94],
        ['rx' => '/\btartinables?\b/u', 'path' => ['Spread', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.72],
        ['rx' => '/\bblack\s+bean\b.*\bsauce\b/u', 'path' => ['Black bean sauce', 'Black beans', 'Sauce'], 'contains' => ['Garlic'], 'category' => 'sauces', 'confidence' => 0.96],
        ['rx' => '/\b(seasoning\s+blend|herb\s+seasoning)\b/u', 'path' => ['Seasoning blend', 'Seasoning', 'Spice'], 'contains' => ['Onion'], 'category' => 'spices', 'confidence' => 0.93],
        ['rx' => '/\b(rice\s+vinegar|vinegar)\b/u', 'path' => ['Vinegar', 'Condiment'], 'category' => 'condiments', 'confidence' => 0.94],
        ['rx' => '/\b(pickles?|dill\s+(chips|pickles?)|hamburger\s+dill)\b/u', 'path' => ['Pickles', 'Cucumber', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.93],
        ['rx' => '/\b(jalape(?:n|ñ)o|banana\s+pepper|pepper\s+rings)\b/u', 'path' => ['Pickled peppers', 'Pepper', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.92],

        // Meat, seafood, stocks.
        ['rx' => '/\bchicken\s+breasts?\b/u', 'path' => ['Chicken breast', 'Chicken', 'Poultry'], 'category' => 'meat', 'confidence' => 0.99],
        ['rx' => '/\b(chicken\s+(stock|broth)|chicken\s+base|chicken.*bouillon|bouillon.*chicken)\b/u', 'path' => ['Chicken stock', 'Chicken', 'Stock'], 'category' => 'broths', 'confidence' => 0.96],
        ['rx' => '/\bbeef\s+stock\b/u', 'path' => ['Beef stock', 'Beef', 'Stock'], 'category' => 'broths', 'confidence' => 0.97],
        ['rx' => '/\b(tortilla\s+soup|soup)\b/u', 'path' => ['Soup', 'Prepared meal'], 'category' => 'prepared meals', 'confidence' => 0.78],
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
        ['rx' => '/\bcoconut\s*(milk|milk beverage|coconutmilk)\b/u', 'path' => ['Coconut milk', 'Coconut'], 'category' => 'plant milks', 'confidence' => 0.97],
        ['rx' => '/\bbutter\b/u', 'path' => ['Butter', 'Dairy'], 'category' => 'dairy', 'confidence' => 0.98],
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
        ['rx' => '/\bpizza\b/u', 'path' => ['Pizza', 'Prepared meal'], 'category' => 'prepared meals', 'confidence' => 0.96],
        ['rx' => '/\btacos?\b/u', 'path' => ['Tacos', 'Prepared meal'], 'category' => 'prepared meals', 'confidence' => 0.88],
        ['rx' => '/\bcake\b/u', 'path' => ['Cake', 'Dessert'], 'category' => 'desserts', 'confidence' => 0.92],
        ['rx' => '/\bcookies?\b/u', 'path' => ['Cookies', 'Dessert'], 'category' => 'desserts', 'confidence' => 0.94],

        // Produce, nuts, legumes.
        ['rx' => '/\bcarrots?\b/u', 'path' => ['Carrot', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.99],
        ['rx' => '/\bcauliflower\b/u', 'path' => ['Cauliflower', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.99],
        ['rx' => '/\bspinach\b/u', 'path' => ['Spinach', 'Leafy vegetable', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.99],
        ['rx' => '/\b(green\s+onions?|scallions?)\b/u', 'path' => ['Green onion', 'Onion', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.98],
        ['rx' => '/\bonion\b/u', 'path' => ['Onion', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.88],
        ['rx' => '/\b(peeled\s+)?garlic\b/u', 'path' => ['Garlic', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.98],
        ['rx' => '/\bginger\b/u', 'path' => ['Ginger', 'Spice'], 'category' => 'spices', 'confidence' => 0.98],
        ['rx' => '/\b(sun[\s-]?dried|dried)\s+tomatoes\b/u', 'path' => ['Sun-dried tomatoes', 'Tomato', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.98],
        ['rx' => '/\btomatoes\b/u', 'path' => ['Tomato', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.90],
        ['rx' => '/\bolives?\b/u', 'path' => ['Olives', 'Vegetable'], 'category' => 'vegetables', 'confidence' => 0.96],
        ['rx' => '/\bveggies?|vegetables?\b/u', 'path' => ['Vegetables'], 'category' => 'vegetables', 'confidence' => 0.80],
        ['rx' => '/\bpineapple\b/u', 'path' => ['Pineapple', 'Fruit'], 'category' => 'fruit', 'confidence' => 0.98],
        ['rx' => '/\bpear\s+marmalade\b/u', 'path' => ['Pear marmalade', 'Pear', 'Fruit preserve'], 'category' => 'fruit preserves', 'confidence' => 0.97],
        ['rx' => '/\bwalnuts?\b/u', 'path' => ['Walnuts', 'Tree nuts', 'Nuts'], 'category' => 'nuts', 'confidence' => 0.98],
        ['rx' => '/\balmonds?\b/u', 'path' => ['Almonds', 'Tree nuts', 'Nuts'], 'category' => 'nuts', 'confidence' => 0.98],
        ['rx' => '/\bpeanuts?\b/u', 'path' => ['Peanuts', 'Legume'], 'category' => 'nuts', 'confidence' => 0.98],
        ['rx' => '/\bblack\s+bean\b.*\bsalad\b/u', 'path' => ['Black bean salad', 'Black beans', 'Salad'], 'contains' => ['Corn'], 'category' => 'prepared salads', 'confidence' => 0.93],
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
        ['rx' => '/\bmaple\s+syrup\b/u', 'path' => ['Maple syrup', 'Sweetener'], 'category' => 'sweeteners', 'confidence' => 0.98],
        ['rx' => '/\blime\s+juice\b/u', 'path' => ['Lime juice', 'Lime', 'Fruit'], 'category' => 'fruit', 'confidence' => 0.98],
        ['rx' => '/\bsesame\s+oil\b/u', 'path' => ['Sesame oil', 'Sesame', 'Oil'], 'category' => 'oils', 'confidence' => 0.98],
        ['rx' => '/\bsesame\s+seeds?\b/u', 'path' => ['Sesame seeds', 'Sesame', 'Seed'], 'category' => 'seeds', 'confidence' => 0.98],
        ['rx' => '/\b(bitters?)\b/u', 'path' => ['Bitters', 'Flavoring'], 'category' => 'flavorings', 'confidence' => 0.92],
        ['rx' => '/\b(coffee\s*mate|coffee\s+creamer|creamer)\b/u', 'path' => ['Coffee creamer', 'Creamer', 'Dairy'], 'category' => 'dairy', 'confidence' => 0.94],
        ['rx' => '/\b(coffee|k-cup)\b/u', 'path' => ['Coffee', 'Beverage'], 'category' => 'beverages', 'confidence' => 0.93],
        ['rx' => '/\bgatorade|sports?\s+drink|thirst\s+quencher\b/u', 'path' => ['Sports drink', 'Beverage'], 'category' => 'beverages', 'confidence' => 0.94],
        ['rx' => '/\b(cooking\s+wine|dessert\s+wine|soju|martini|buzzball|cocktail)\b/u', 'path' => ['Alcoholic beverage', 'Beverage'], 'category' => 'beverages', 'confidence' => 0.82],
        ['rx' => '/\b(tamarind\s+(soft\s+drink|soda)|soda)\b/u', 'path' => ['Soda', 'Beverage'], 'contains' => ['Tamarind'], 'category' => 'beverages', 'confidence' => 0.89],
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
        $evidence = 'matched "' . ($match[0] ?? $rule['rx']) . '" in product metadata';
        $allowPrimary = !canonicalIngredientHasPrimary($mappings);
        canonicalIngredientPutPath(
            $mappings,
            $rule['path'] ?? [],
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

function canonicalIngredientUpsert(PDO $db, array $mapping): int {
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
    $externalJson = !empty($mapping['external_ids'])
        ? json_encode($mapping['external_ids'], JSON_UNESCAPED_UNICODE)
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
        return ['product_id' => $productId, 'mapped' => 0, 'mappings' => []];
    }

    $mappings = canonicalIngredientInferProduct($product);
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM product_ingredients WHERE product_id = ? AND source != 'manual'")
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
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return ['product_id' => $productId, 'mapped' => count($mappings), 'mappings' => $mappings];
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
        'role_counts' => $roleCounts,
        'source_counts' => $sourceCounts,
        'examples' => $examples,
        'low_confidence' => $lowConfidence,
        'unmatched' => $unmatched,
    ];
}
