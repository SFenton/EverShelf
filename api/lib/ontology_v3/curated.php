<?php
declare(strict_types=1);

const INGREDIENT_ONTOLOGY_V3_CURATED_VERSION = 'curated-review-v3';

function ingredientOntologyV3CuratedCanonicalSlug(string $slug): string {
    $slug = ingredientOntologyV3Slug($slug);
    return [
        'eggs' => 'egg',
        'almonds' => 'almond',
        'noodles' => 'noodle',
        'olives' => 'olive',
        'vegetables' => 'vegetable',
        'legumes' => 'legume',
        'beans' => 'bean',
        'corn-starch' => 'cornstarch',
        'black-beans' => 'black-bean',
        'cilantro' => 'coriander',
        'green-onion' => 'spring-onion',
        'coriander-seeds' => 'coriander-seed',
        'tomatoes' => 'tomato',
        'lemons' => 'lemon',
        'walnuts' => 'walnut',
        'blueberries' => 'blueberry',
        'peanuts' => 'peanut',
        'bread-crumbs' => 'breadcrumbs',
        'egg-whites' => 'egg-white',
        'garlic-cloves' => 'garlic',
        'chicken-stock' => 'stock',
        'chicken-stock-base' => 'stock',
        'beef-stock' => 'stock',
        'black-pepper' => 'piper-pepper',
        'white-pepper' => 'piper-pepper',
        'peppercorn' => 'piper-pepper',
    ][$slug] ?? $slug;
}

function ingredientOntologyV3CuratedEntities(): array {
    return [
        ['lemon', 'Lemon', 'ingredient', 'ingredient'],
        ['lemon-juice', 'Lemon Juice', 'ingredient', 'ingredient'],
        ['lime', 'Lime', 'ingredient', 'ingredient'],
        ['lime-juice', 'Lime Juice', 'ingredient', 'ingredient'],
        ['tomato', 'Tomato', 'ingredient', 'vegetable'],
        ['tomato-paste', 'Tomato Paste', 'ingredient', 'ingredient'],
        ['tomato-sauce', 'Tomato Sauce', 'prepared_food', 'prepared-food'],
        ['zucchini', 'Zucchini', 'ingredient', 'vegetable'],
        ['parsley', 'Parsley', 'ingredient', 'ingredient'],
        ['coriander', 'Coriander', 'ingredient', 'ingredient'],
        ['coriander-seed', 'Coriander Seed', 'ingredient', 'ingredient'],
        ['basil', 'Basil', 'ingredient', 'ingredient'],
        ['thyme', 'Thyme', 'ingredient', 'ingredient'],
        ['oregano', 'Oregano', 'ingredient', 'ingredient'],
        ['rosemary', 'Rosemary', 'ingredient', 'ingredient'],
        ['mint', 'Mint', 'ingredient', 'ingredient'],
        ['chives', 'Chives', 'ingredient', 'ingredient'],
        ['dill', 'Dill', 'ingredient', 'ingredient'],
        ['yeast', 'Yeast', 'ingredient', 'ingredient'],
        ['baking-powder', 'Baking Powder', 'ingredient', 'ingredient'],
        ['baking-soda', 'Baking Soda', 'ingredient', 'ingredient'],
        ['cornstarch', 'Cornstarch', 'ingredient', 'ingredient'],
        ['paprika', 'Paprika', 'ingredient', 'ingredient'],
        ['turmeric', 'Turmeric', 'ingredient', 'ingredient'],
        ['cinnamon', 'Cinnamon', 'ingredient', 'ingredient'],
        ['cumin', 'Cumin', 'ingredient', 'ingredient'],
        ['nutmeg', 'Nutmeg', 'ingredient', 'ingredient'],
        ['cayenne-pepper', 'Cayenne Pepper', 'ingredient', 'pepper'],
        ['peppercorn', 'Peppercorn', 'ingredient', 'pepper'],
        ['shallot', 'Shallot', 'ingredient', 'vegetable'],
        ['potato', 'Potato', 'ingredient', 'vegetable'],
        ['carrot', 'Carrot', 'ingredient', 'vegetable'],
        ['celery', 'Celery', 'ingredient', 'vegetable'],
        ['avocado', 'Avocado', 'ingredient', 'ingredient'],
        ['orange', 'Orange', 'ingredient', 'ingredient'],
        ['vanilla-extract', 'Vanilla Extract', 'ingredient', 'ingredient'],
        ['vanilla-sugar', 'Vanilla Sugar', 'composite_food', 'composite-food'],
        ['egg-yolk', 'Egg Yolk', 'ingredient', 'egg'],
        ['egg-white', 'Egg White', 'ingredient', 'egg'],
        ['butter', 'Butter', 'ingredient', 'ingredient'],
        ['milk', 'Milk', 'ingredient', 'ingredient'],
        ['cream', 'Cream', 'ingredient', 'ingredient'],
        ['sour-cream', 'Sour Cream', 'ingredient', 'cream'],
        ['cream-cheese', 'Cream Cheese', 'ingredient', 'cheese'],
        ['parmesan', 'Parmesan', 'ingredient', 'cheese'],
        ['cheddar', 'Cheddar', 'ingredient', 'cheese'],
        ['feta', 'Feta', 'ingredient', 'cheese'],
        ['condensed-milk', 'Condensed Milk', 'ingredient', 'milk'],
        ['evaporated-milk', 'Evaporated Milk', 'ingredient', 'milk'],
        ['coconut-milk', 'Coconut Milk', 'ingredient', 'ingredient'],
        ['honey', 'Honey', 'ingredient', 'ingredient'],
        ['peanut', 'Peanut', 'ingredient', 'legume'],
        ['maple-syrup', 'Maple Syrup', 'ingredient', 'ingredient'],
        ['molasses', 'Molasses', 'ingredient', 'ingredient'],
        ['cocoa-powder', 'Cocoa Powder', 'ingredient', 'ingredient'],
        ['chocolate', 'Chocolate', 'ingredient', 'ingredient'],
        ['soy-sauce', 'Soy Sauce', 'ingredient', 'ingredient'],
        ['fish-sauce', 'Fish Sauce', 'ingredient', 'ingredient'],
        ['worcestershire-sauce', 'Worcestershire Sauce', 'ingredient', 'ingredient'],
        ['spring-onion', 'Spring Onion', 'ingredient', 'vegetable'],
        ['bell-pepper', 'Bell Pepper', 'ingredient', 'vegetable'],
        ['cauliflower', 'Cauliflower', 'ingredient', 'vegetable'],
        ['spinach', 'Spinach', 'ingredient', 'vegetable'],
        ['mango', 'Mango', 'ingredient', 'ingredient'],
        ['blueberry', 'Blueberry', 'ingredient', 'ingredient'],
        ['walnut', 'Walnut', 'ingredient', 'ingredient'],
        ['bean', 'Bean', 'ingredient', 'legume'],
        ['black-bean', 'Black Bean', 'ingredient', 'bean'],
        ['pasta', 'Pasta', 'ingredient', 'ingredient'],
        ['bread', 'Bread', 'ingredient', 'ingredient'],
        ['breadcrumbs', 'Breadcrumbs', 'ingredient', 'bread'],
        ['couscous', 'Couscous', 'ingredient', 'ingredient'],
        ['oats', 'Oats', 'ingredient', 'ingredient'],
        ['stock-paste', 'Stock Paste', 'ingredient', 'ingredient'],
        ['coffee', 'Coffee', 'ingredient', 'ingredient'],
        ['coffee-creamer', 'Coffee Creamer', 'prepared_food', 'prepared-food'],
        ['chips', 'Chips', 'prepared_food', 'prepared-food'],
        ['cake', 'Cake', 'composite_food', 'composite-food'],
        ['pizza', 'Pizza', 'composite_food', 'composite-food'],
        ['salad', 'Salad', 'composite_food', 'composite-food'],
        ['guacamole', 'Guacamole', 'composite_food', 'composite-food'],
        ['mexican-salsa', 'Mexican Salsa', 'prepared_food', 'prepared-food'],
        ['salsa-verde', 'Salsa Verde', 'prepared_food', 'prepared-food'],
        ['mustard', 'Mustard', 'ingredient', 'ingredient'],
    ];
}

function ingredientOntologyV3CuratedAliases(): array {
    return [
        ['lemon-juice', 'lemon juice', 'en', []],
        ['lemon-juice', 'freshly squeezed lemon juice', 'en', []],
        ['lemon-juice', 'Zitronensaft', 'de', []],
        ['lemon-juice', 'jus de citron', 'fr', []],
        ['lemon-juice', 'de jus de citron', 'fr', []],
        ['lemon-juice', 'succo di limone', 'it', []],
        ['lemon-juice', 'di succo di limone', 'it', []],
        ['lemon-juice', 'zumo de limón', 'es', []],
        ['lemon-juice', 'sumo de limão', 'pt', []],
        ['lemon', 'lemons', 'en', []],
        ['lime-juice', 'lime juice', 'en', []],
        ['tomato-paste', 'tomato paste', 'en', []],
        ['tomato-paste', 'Tomatenmark', 'de', []],
        ['tomato', 'tomatoes', 'en', []],
        ['zucchini', 'zucchini', 'en', []],
        ['zucchini', 'courgette', 'en', []],
        ['zucchini', 'courgettes', 'fr', []],
        ['parsley', 'parsley', 'en', []],
        ['parsley', 'fresh parsley', 'en', []],
        ['parsley', 'fresh flat leaf parsley', 'en', []],
        ['parsley', 'dried parsley', 'en', ['processing' => 'dried']],
        ['parsley', 'Petersilie', 'de', []],
        ['parsley', 'persil frais', 'fr', []],
        ['parsley', 'de persil frais', 'fr', []],
        ['parsley', 'persil séché', 'fr', ['processing' => 'dried']],
        ['parsley', 'frische Petersilie', 'de', ['state' => 'fresh']],
        ['parsley', 'getrocknete Petersilie', 'de', ['processing' => 'dried']],
        ['parsley', 'perejil fresco', 'es', ['state' => 'fresh']],
        ['parsley', 'prezzemolo fresco', 'it', ['state' => 'fresh']],
        ['parsley', 'salsa fresca', 'pt', ['state' => 'fresh']],
        ['coriander', 'coriander', 'en', []],
        ['coriander', 'fresh coriander', 'en', ['state' => 'fresh']],
        ['coriander', 'coriander leaves', 'en', []],
        ['coriander', 'cilantro', 'en', []],
        ['coriander', 'cilantro', 'es', []],
        ['coriander', 'cilantro fresco', 'es', ['state' => 'fresh']],
        ['coriander', 'coriandre fraîche', 'fr', ['state' => 'fresh']],
        ['coriander', 'de coriandre fraîche', 'fr', ['state' => 'fresh']],
        ['coriander', 'frischer Koriander', 'de', ['state' => 'fresh']],
        ['coriander', 'Koriander', 'de', []],
        ['coriander', 'coentros', 'pt', []],
        ['coriander', 'coentros frescos', 'pt', ['state' => 'fresh']],
        ['coriander-seed', 'coriander seed', 'en', ['form' => 'whole']],
        ['coriander-seed', 'coriander seeds', 'en', ['form' => 'whole']],
        ['coriander-seed', 'ground coriander', 'en', ['form' => 'ground']],
        ['basil', 'basil', 'en', []],
        ['basil', 'fresh basil', 'en', []],
        ['basil', 'Basilikum', 'de', []],
        ['thyme', 'thyme', 'en', []],
        ['thyme', 'fresh thyme', 'en', []],
        ['oregano', 'oregano', 'en', []],
        ['oregano', 'dried oregano', 'en', ['processing' => 'dried']],
        ['oregano', 'Oregano getrocknet', 'de', ['processing' => 'dried']],
        ['oregano', 'getrockneter Oregano', 'de', ['processing' => 'dried']],
        ['oregano', 'origan séché', 'fr', ['processing' => 'dried']],
        ['oregano', 'orégano seco', 'es', ['processing' => 'dried']],
        ['oregano', 'origano secco', 'it', ['processing' => 'dried']],
        ['oregano', 'orégãos secos', 'pt', ['processing' => 'dried']],
        ['rosemary', 'fresh rosemary', 'en', []],
        ['mint', 'fresh mint', 'en', []],
        ['chives', 'fresh chives', 'en', []],
        ['dill', 'fresh dill', 'en', []],
        ['yeast', 'yeast', 'en', []],
        ['yeast', 'dried instant yeast', 'en', ['processing' => 'dried']],
        ['yeast', 'Hefe', 'de', []],
        ['yeast', 'levure boulangère fraîche', 'fr', []],
        ['yeast', 'de levure boulangère fraîche', 'fr', []],
        ['yeast', 'lievito di birra fresco', 'it', []],
        ['baking-powder', 'baking powder', 'en', []],
        ['baking-powder', 'Backpulver', 'de', []],
        ['baking-powder', 'levure chimique', 'fr', []],
        ['baking-powder', 'de levure chimique', 'fr', []],
        ['baking-soda', 'baking soda', 'en', []],
        ['baking-soda', 'bicarbonate of soda', 'en', []],
        ['cornstarch', 'cornstarch', 'en', []],
        ['paprika', 'paprika', 'en', []],
        ['paprika', 'sweet paprika', 'en', []],
        ['paprika', 'smoked paprika', 'en', ['processing' => 'smoked']],
        ['paprika', 'Paprika edelsüß', 'de', []],
        ['turmeric', 'ground turmeric', 'en', ['form' => 'ground']],
        ['cinnamon', 'ground cinnamon', 'en', ['form' => 'ground']],
        ['cinnamon', 'Zimt', 'de', []],
        ['cumin', 'ground cumin', 'en', ['form' => 'ground']],
        ['cumin', 'cumin seeds', 'en', ['form' => 'whole']],
        ['nutmeg', 'ground nutmeg', 'en', ['form' => 'ground']],
        ['nutmeg', 'Muskat', 'de', []],
        ['shallot', 'shallot', 'en', []],
        ['shallot', 'shallots', 'en', []],
        ['shallot', 'Schalotten', 'de', []],
        ['shallot', 'échalotes', 'fr', []],
        ['potato', 'potatoes', 'en', []],
        ['potato', 'Kartoffeln', 'de', []],
        ['potato', 'pommes de terre', 'fr', []],
        ['carrot', 'carrots', 'en', []],
        ['carrot', 'Möhren', 'de', []],
        ['carrot', 'carottes', 'fr', []],
        ['carrot', 'cenoura', 'pt', []],
        ['avocado', 'avocado', 'en', []],
        ['orange', 'orange', 'en', []],
        ['vanilla-extract', 'vanilla extract', 'en', []],
        ['vanilla-extract', 'natural vanilla extract', 'en', []],
        ['vanilla-sugar', 'vanilla sugar', 'en', []],
        ['vanilla-sugar', 'Vanillezucker', 'de', []],
        ['egg', 'egg', 'en', ['egg_part' => 'whole']],
        ['egg', 'eggs', 'en', ['egg_part' => 'whole']],
        ['egg', 'large eggs', 'en', ['egg_part' => 'whole']],
        ['egg', 'Eier', 'de', ['egg_part' => 'whole']],
        ['egg', 'Ei', 'de', ['egg_part' => 'whole']],
        ['egg', 'œufs', 'fr', ['egg_part' => 'whole']],
        ['egg', 'œuf', 'fr', ['egg_part' => 'whole']],
        ['egg', 'uova', 'it', ['egg_part' => 'whole']],
        ['egg', 'uovo', 'it', ['egg_part' => 'whole']],
        ['egg', 'huevos', 'es', ['egg_part' => 'whole']],
        ['egg', 'ovos', 'pt', ['egg_part' => 'whole']],
        ['egg-yolk', 'egg yolk', 'en', ['egg_part' => 'yolk']],
        ['egg-yolk', 'egg yolks', 'en', ['egg_part' => 'yolk']],
        ['egg-yolk', 'Eigelb', 'de', ['egg_part' => 'yolk']],
        ['egg-white', 'egg white', 'en', ['egg_part' => 'white']],
        ['egg-white', 'egg whites', 'en', ['egg_part' => 'white']],
        ['butter', 'unsalted butter', 'en', ['saltedness' => 'unsalted']],
        ['butter', 'salted butter', 'en', ['saltedness' => 'salted']],
        ['butter', 'beurre', 'fr', []],
        ['butter', 'de beurre', 'fr', []],
        ['butter', 'burro', 'it', []],
        ['butter', 'mantequilla', 'es', []],
        ['butter', 'manteiga', 'pt', []],
        ['milk', 'milk', 'en', []],
        ['milk', 'whole milk', 'en', ['fat_content' => 'whole']],
        ['milk', 'Milch', 'de', []],
        ['milk', 'lait', 'fr', []],
        ['milk', 'latte', 'it', []],
        ['milk', 'leche', 'es', []],
        ['milk', 'leite', 'pt', []],
        ['cream', 'Sahne', 'de', []],
        ['cream', 'whipping cream', 'en', ['cream_class' => 'whipping']],
        ['cream', 'pouring whipping cream', 'en', ['cream_class' => 'whipping']],
        ['cream', 'double cream', 'en', ['cream_class' => 'double']],
        ['cream-cheese', 'Frischkäse', 'de', []],
        ['sugar', 'Zucker', 'de', []],
        ['sugar', 'sucre', 'fr', []],
        ['sugar', 'zucchero', 'it', []],
        ['sugar', 'azúcar', 'es', []],
        ['sugar', 'açúcar', 'pt', []],
        ['sugar', 'cukier', 'pl', []],
        ['sugar', 'caster sugar', 'en', ['refinement' => 'caster']],
        ['sugar', 'icing sugar', 'en', ['refinement' => 'powdered']],
        ['sugar', 'Puderzucker', 'de', ['refinement' => 'powdered']],
        ['sugar', 'sucre glace', 'fr', ['refinement' => 'powdered']],
        ['sugar', 'de sucre glace', 'fr', ['refinement' => 'powdered']],
        ['sugar', 'sucre en poudre', 'fr', ['refinement' => 'granulated']],
        ['sugar', 'de sucre en poudre', 'fr', ['refinement' => 'granulated']],
        ['sugar', 'zucchero a velo', 'it', ['refinement' => 'powdered']],
        ['sugar', 'azúcar glas', 'es', ['refinement' => 'powdered']],
        ['sugar', 'açúcar em pó', 'pt', ['refinement' => 'powdered']],
        ['sugar', 'cukier puder', 'pl', ['refinement' => 'powdered']],
        ['sugar', 'brown sugar', 'en', ['refinement' => 'brown']],
        ['sugar', 'brauner Zucker', 'de', ['refinement' => 'brown']],
        ['flour', 'plain flour', 'en', ['form' => 'flour', 'refinement' => 'plain']],
        ['flour', 'self raising flour', 'en', ['form' => 'flour', 'refinement' => 'self_raising']],
        ['flour', 'Mehl', 'de', ['form' => 'flour']],
        ['flour', 'farine', 'fr', ['form' => 'flour']],
        ['flour', 'farina', 'it', ['form' => 'flour']],
        ['flour', 'harina', 'es', ['form' => 'flour']],
        ['flour', 'farinha', 'pt', ['form' => 'flour']],
        ['flour', 'wheat flour', 'en', ['form' => 'flour']],
        ['flour', 'farine de blé', 'fr', ['form' => 'flour']],
        ['flour', 'de farine de blé', 'fr', ['form' => 'flour']],
        ['flour', 'Weizenmehl', 'de', ['form' => 'flour']],
        ['flour', 'farina di frumento', 'it', ['form' => 'flour']],
        ['flour', 'harina de trigo', 'es', ['form' => 'flour']],
        ['flour', 'farinha de trigo', 'pt', ['form' => 'flour']],
        ['flour', 'mąka pszenna', 'pl', ['form' => 'flour']],
        ['flour', 'bread flour', 'en', ['form' => 'flour', 'refinement' => 'bread']],
        ['flour', "baker's flour", 'en', ['form' => 'flour', 'refinement' => 'bread']],
        ['flour', 'farinha tipo 65', 'pt', ['form' => 'flour', 'refinement' => 'type_65']],
        ['flour', 'farinha tipo65', 'pt', ['form' => 'flour', 'refinement' => 'type_65']],
        ['flour', 'farina tipo 00', 'it', ['form' => 'flour', 'refinement' => 'type_00']],
        ['flour', 'di farina tipo 00', 'it', ['form' => 'flour', 'refinement' => 'type_00']],
        ['garlic', 'garlic clove', 'en', ['form' => 'clove']],
        ['garlic', 'garlic cloves', 'en', ['form' => 'clove']],
        ['garlic', 'Knoblauchzehe', 'de', ['form' => 'clove']],
        ['garlic', 'Knoblauchzehen', 'de', ['form' => 'clove']],
        ['garlic', 'gousse d ail', 'fr', ['form' => 'clove']],
        ['garlic', 'gousses d ail', 'fr', ['form' => 'clove']],
        ['garlic', 'spicchio di aglio', 'it', ['form' => 'clove']],
        ['garlic', 'spicchi di aglio', 'it', ['form' => 'clove']],
        ['garlic', 'diente de ajo', 'es', ['form' => 'clove']],
        ['garlic', 'dientes de ajo', 'es', ['form' => 'clove']],
        ['garlic', 'dente de alho', 'pt', ['form' => 'clove']],
        ['garlic', 'dentes de alho', 'pt', ['form' => 'clove']],
        ['onion', 'onions', 'en', []],
        ['onion', 'red onion', 'en', ['variety' => 'red']],
        ['onion', 'red onions', 'en', ['variety' => 'red']],
        ['onion', 'white onion', 'en', ['variety' => 'white']],
        ['onion', 'white onions', 'en', ['variety' => 'white']],
        ['onion', 'yellow onion', 'en', ['variety' => 'yellow']],
        ['onion', 'yellow onions', 'en', ['variety' => 'yellow']],
        ['onion', 'Zwiebel', 'de', []],
        ['onion', 'Zwiebeln', 'de', []],
        ['onion', 'oignon', 'fr', []],
        ['onion', 'oignons', 'fr', []],
        ['onion', 'cipolla', 'it', []],
        ['onion', 'cebolla', 'es', []],
        ['onion', 'cebola', 'pt', []],
        ['parmesan', 'parmesan cheese', 'en', []],
        ['parmesan', 'parmesan', 'en', []],
        ['parmesan', 'parmesão', 'pt', []],
        ['parmesan', 'queijo parmesão', 'pt', []],
        ['stock-paste', 'vegetable stock paste', 'en', ['variety' => 'vegetable']],
        ['stock-paste', 'chicken stock paste', 'en', ['variety' => 'chicken']],
        ['stock-paste', 'Gewürzpaste für Gemüsebrühe selbst gemacht', 'de', ['variety' => 'vegetable']],
        ['stock', 'chicken stock', 'en', ['variety' => 'chicken']],
        ['stock', 'beef stock', 'en', ['variety' => 'beef']],
        ['soy-sauce', 'soy sauce', 'en', []],
        ['soy-sauce', 'Sojasauce', 'de', []],
        ['fish-sauce', 'fish sauce', 'en', []],
        ['worcestershire-sauce', 'worcestershire sauce', 'en', []],
        ['spring-onion', 'spring onions', 'en', []],
        ['spring-onion', 'spring onion', 'en', []],
        ['spring-onion', 'green onions', 'en', []],
        ['spring-onion', 'green onion', 'en', []],
        ['spring-onion', 'Frühlingszwiebeln', 'de', []],
        ['black-bean', 'black bean', 'en', []],
        ['black-bean', 'black beans', 'en', []],
        ['almond', 'Mandeln', 'de', []],
        ['almond', 'blanched almonds', 'en', ['processing' => 'blanched']],
        ['walnut', 'walnut', 'en', []],
        ['walnut', 'walnuts', 'en', []],
        ['blueberry', 'blueberry', 'en', []],
        ['blueberry', 'blueberries', 'en', []],
        ['peanut', 'peanut', 'en', []],
        ['peanut', 'peanuts', 'en', []],
        ['breadcrumbs', 'bread crumbs', 'en', []],
        ['breadcrumbs', 'breadcrumbs', 'en', []],
        ['honey', 'Honig', 'de', []],
        ['honey', 'miel', 'fr', []],
        ['honey', 'de miel', 'fr', []],
        ['mustard', 'Senf', 'de', []],
        ['cocoa-powder', 'cocoa powder', 'en', []],
        ['cocoa-powder', 'Kakao', 'de', []],
        ['cheddar', 'cheddar cheese', 'en', []],
        ['feta', 'feta cheese', 'en', []],
    ];
}

function ingredientOntologyV3SeedCuratedEntities(
    PDO $db,
    int $versionId,
    array $facetMap
): void {
    foreach (ingredientOntologyV3CuratedEntities() as $definition) {
        [$slug, $name, $kind] = $definition;
        ingredientOntologyV3UpsertEntity(
            $db,
            $versionId,
            'curated:' . $slug,
            $slug,
            $name,
            $kind,
            INGREDIENT_ONTOLOGY_V3_CURATED_VERSION
        );
    }
    $entities = ingredientOntologyV3EntityMap($db, $versionId)['by_slug'];
    foreach (ingredientOntologyV3CuratedEntities() as $definition) {
        [$slug, $name, , $parentSlug] = $definition;
        $entity = $entities[$slug] ?? null;
        if ($entity === null) {
            continue;
        }
        ingredientOntologyV3UpsertLabel(
            $db,
            $versionId,
            $entity['id'],
            'und',
            $name,
            'exact_alias',
            'accepted',
            INGREDIENT_ONTOLOGY_V3_CURATED_VERSION,
            'curated-canonical:' . $slug,
            [],
            $facetMap
        );
        if (
            isset($entities[$parentSlug])
            && $entities[$parentSlug]['id'] !== $entity['id']
        ) {
            $db->prepare("
                UPDATE ingredient_ontology_relations
                SET is_primary = 0,
                    review_state = 'quarantined',
                    semantics_json =
                        '{\"superseded_by_curated_parent\":true}',
                    updated_at = CURRENT_TIMESTAMP
                WHERE ontology_version_id = ?
                  AND from_entity_id = ?
                  AND relation = 'is_a'
                  AND is_primary = 1
                  AND review_state = 'accepted'
                  AND to_entity_id <> ?
            ")->execute([
                $versionId,
                $entity['id'],
                $entities[$parentSlug]['id'],
            ]);
            ingredientOntologyV3InsertRelation(
                $db,
                $versionId,
                $entity['id'],
                $entities[$parentSlug]['id'],
                'is_a',
                true,
                false,
                1.0,
                INGREDIENT_ONTOLOGY_V3_CURATED_VERSION,
                'accepted',
                'forward',
                ['curated_parent_manifest' => true]
            );
        }
    }
}

function ingredientOntologyV3BuildCuratedProductAssertions(
    PDO $db,
    int $versionId
): array {
    $entities = ingredientOntologyV3EntityMap($db, $versionId)['by_slug'];
    $manifest = ingredientOntologyV3CuratedProductManifest();
    $primary = [];
    $stmt = $db->query("
        SELECT pi.product_id, pi.confidence, pi.source,
               ci.slug, ci.name
        FROM product_ingredients pi
        JOIN canonical_ingredients ci ON ci.id = pi.ingredient_id
        WHERE pi.role = 'primary'
        ORDER BY pi.product_id, pi.confidence DESC, pi.id
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $productId = (int)$row['product_id'];
        if (!isset($primary[$productId])) {
            $primary[$productId] = $row;
        }
    }
    $insert = $db->prepare("
        INSERT INTO ingredient_ontology_curated_product_assertions (
            ontology_version_id, product_id, product_fingerprint,
            product_name, normalized_product_name, entity_id, status,
            confidence, attributes_json, rationale, provenance,
            review_state
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $counts = [];
    foreach ($db->query("
        SELECT id, name, brand, category, prepared_food
        FROM products ORDER BY id
    ") as $product) {
        $productId = (int)$product['id'];
        $attributes = ingredientOntologyV3ExtractAttributes(
            (string)$product['name']
        );
        $status = 'unresolved';
        $entityId = null;
        $confidence = 0.0;
        $rationale = 'No reviewed culinary identity';
        $reviewState = 'pending';
        $provenance = 'unresolved_product';
        $reviewFingerprint =
            ingredientOntologyV3CuratedProductReviewFingerprint(
                $product
            );
        $review = $manifest[$reviewFingerprint] ?? null;
        if ($review !== null) {
            $target = $entities[(string)$review['slug']] ?? null;
            if ($target === null) {
                throw new RuntimeException(
                    'reviewed product target is missing: '
                    . (string)$review['slug']
                );
            }
            $status = 'accepted';
            $entityId = $target['id'];
            $confidence = 1.0;
            $attributes = (array)$review['attributes'];
            $rationale = (string)$review['rationale'];
            $reviewState = 'accepted';
            $provenance =
                INGREDIENT_ONTOLOGY_V3_CURATED_PRODUCT_MANIFEST_VERSION;
        } else {
            $legacy = $primary[$productId] ?? null;
            if ($legacy !== null) {
                $base = ingredientOntologyV3LegacyBase(
                    (string)$legacy['slug'],
                    (string)$legacy['name']
                );
                $target = $entities[$base['slug']] ?? null;
                $provenance = trim((string)$legacy['source'])
                    ?: 'legacy_product_mapping';
                if ($target !== null) {
                    $status = 'candidate';
                    $entityId = $target['id'];
                    $attributes = array_replace(
                        $base['attributes'],
                        $attributes
                    );
                    $confidence = min(
                        0.80,
                        (float)$legacy['confidence']
                    );
                    $rationale =
                        'Unreviewed legacy primary retained as candidate';
                    $reviewState = 'quarantined';
                }
            } elseif (!empty($product['prepared_food'])) {
                $target = $entities['prepared-food'] ?? null;
                if ($target !== null) {
                    $status = 'candidate';
                    $entityId = $target['id'];
                    $confidence = 0.60;
                    $rationale =
                        'Prepared-food flag requires explicit identity review';
                    $reviewState = 'quarantined';
                    $provenance = 'prepared_food_flag';
                }
            }
        }
        ksort($attributes, SORT_STRING);
        $insert->execute([
            $versionId,
            $productId,
            ingredientOntologyV3ProductOwnerFingerprint($product),
            mb_substr((string)$product['name'], 0, 200, 'UTF-8'),
            mb_substr(
                ingredientOntologyV3NormalizeLabel(
                    (string)$product['name']
                ),
                0,
                200,
                'UTF-8'
            ),
            $entityId,
            $status,
            $confidence,
            ingredientOntologyV3Json($attributes),
            $rationale,
            $provenance,
            $reviewState,
        ]);
        $counts[$status] = ($counts[$status] ?? 0) + 1;
    }
    return $counts;
}

function ingredientOntologyV3ApplyCuratedLabelQuarantine(
    PDO $db,
    int $versionId
): void {
    $ambiguous = [
        ingredientOntologyV3NormalizeLabel('salsa'),
        ingredientOntologyV3NormalizeLabel('tomato puree'),
        ingredientOntologyV3NormalizeLabel('tomato purée'),
    ];
    $placeholders = implode(',', array_fill(0, count($ambiguous), '?'));
    $db->prepare("
        UPDATE ingredient_ontology_labels
        SET kind = 'candidate_only',
            review_state = 'quarantined',
            provenance = 'curated_ambiguity_quarantine_v2'
        WHERE ontology_version_id = ?
          AND normalized_label IN ({$placeholders})
          AND review_state = 'accepted'
    ")->execute(array_merge([$versionId], $ambiguous));

    $duplicateSlugs = [
        'tomatoes', 'lemons', 'walnuts', 'blueberries', 'peanuts',
        'bread-crumbs', 'egg-whites', 'garlic-cloves',
        'chicken-stock', 'beef-stock', 'black-pepper', 'white-pepper',
    ];
    $placeholders = implode(
        ',',
        array_fill(0, count($duplicateSlugs), '?')
    );
    $db->prepare("
        UPDATE ingredient_ontology_labels
        SET kind = 'candidate_only',
            review_state = 'quarantined',
            provenance = 'curated_duplicate_entity_quarantine_v2'
        WHERE ontology_version_id = ?
          AND entity_id IN (
              SELECT id
              FROM ingredient_ontology_entities
              WHERE ontology_version_id = ?
                AND slug IN ({$placeholders})
          )
          AND review_state = 'accepted'
    ")->execute(array_merge(
        [$versionId, $versionId],
        $duplicateSlugs
    ));
}

function ingredientOntologyV3ApplyCuratedSeed(
    PDO $db,
    int $versionId,
    array $facetMap
): array {
    $entities = ingredientOntologyV3EntityMap($db, $versionId)['by_slug'];
    foreach (ingredientOntologyV3CuratedAliases() as [$slug, $label, $language, $attributes]) {
        if ($slug === 'pepper') {
            $normalizedPepper = ingredientOntologyV3NormalizeLabel($label);
            if (
                !preg_match(
                    '/\b(black|white|nero|bianco|noir|blanc|schwarz|'
                        . 'weiß|weiss|negra|blanca|preta|branca|czarn|biał)\b/u',
                    $normalizedPepper
                )
            ) {
                continue;
            }
            $slug = 'piper-pepper';
        }
        if (!isset($entities[$slug])) {
            continue;
        }
        $attributes = array_replace(
            ingredientOntologyV3ExtractAttributes($label),
            $attributes
        );
        ksort($attributes, SORT_STRING);
        ingredientOntologyV3UpsertLabel(
            $db,
            $versionId,
            $entities[$slug]['id'],
            $language,
            $label,
            $attributes ? 'attribute_alias' : 'exact_alias',
            'accepted',
            INGREDIENT_ONTOLOGY_V3_CURATED_VERSION,
            'curated:' . ingredientOntologyV3Slug($label),
            $attributes,
            $facetMap
        );
    }
    foreach ([
        ['lemon-juice', 'lemon', 'derived_from'],
        ['lime-juice', 'lime', 'derived_from'],
        ['tomato-paste', 'tomato', 'derived_from'],
        ['vanilla-extract', 'vanilla', 'derived_from'],
    ] as [$from, $to, $relation]) {
        if (isset($entities[$from], $entities[$to])) {
            ingredientOntologyV3InsertRelation(
                $db,
                $versionId,
                $entities[$from]['id'],
                $entities[$to]['id'],
                $relation,
                false,
                false,
                1.0,
                INGREDIENT_ONTOLOGY_V3_CURATED_VERSION
            );
        }
    }
    foreach ([
        'eggs' => 'egg',
        'almonds' => 'almond',
        'noodles' => 'noodle',
        'olives' => 'olive',
        'vegetables' => 'vegetable',
        'legumes' => 'legume',
        'beans' => 'bean',
        'corn-starch' => 'cornstarch',
        'black-beans' => 'black-bean',
        'cilantro' => 'coriander',
        'green-onion' => 'spring-onion',
        'coriander-seeds' => 'coriander-seed',
        'tomatoes' => 'tomato',
        'lemons' => 'lemon',
        'walnuts' => 'walnut',
        'blueberries' => 'blueberry',
        'peanuts' => 'peanut',
        'bread-crumbs' => 'breadcrumbs',
        'egg-whites' => 'egg-white',
    ] as $duplicate => $canonical) {
        if (isset($entities[$duplicate], $entities[$canonical])) {
            ingredientOntologyV3InsertRelation(
                $db,
                $versionId,
                $entities[$duplicate]['id'],
                $entities[$canonical]['id'],
                'equivalent_to',
                false,
                false,
                1.0,
                INGREDIENT_ONTOLOGY_V3_CURATED_VERSION,
                'accepted',
                'bidirectional'
            );
        }
    }
    foreach ([
        ['garlic-cloves', 'garlic'],
        ['chicken-stock', 'stock'],
        ['beef-stock', 'stock'],
        ['black-pepper', 'piper-pepper'],
        ['white-pepper', 'piper-pepper'],
        ['peppercorn', 'piper-pepper'],
    ] as [$specific, $base]) {
        if (isset($entities[$specific], $entities[$base])) {
            ingredientOntologyV3InsertRelation(
                $db,
                $versionId,
                $entities[$specific]['id'],
                $entities[$base]['id'],
                'variant_of',
                false,
                false,
                1.0,
                INGREDIENT_ONTOLOGY_V3_CURATED_VERSION,
                'accepted',
                'forward'
            );
        }
    }
    ingredientOntologyV3ApplyCuratedLabelQuarantine(
        $db,
        $versionId
    );
    return [
        'entity_count' => count(ingredientOntologyV3CuratedEntities()),
        'alias_count' => count(ingredientOntologyV3CuratedAliases()),
        'product_statuses' =>
            ingredientOntologyV3BuildCuratedProductAssertions(
                $db,
                $versionId
            ),
    ];
}

function ingredientOntologyV3CuratedProductMap(
    PDO $db,
    int $versionId
): array {
    $stmt = $db->prepare("
        SELECT product_id, product_fingerprint, entity_id, status,
               confidence, attributes_json, rationale, provenance,
               review_state
        FROM ingredient_ontology_curated_product_assertions
        WHERE ontology_version_id = ?
    ");
    $stmt->execute([$versionId]);
    $result = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $row['product_id'] = (int)$row['product_id'];
        $row['entity_id'] = $row['entity_id'] !== null
            ? (int)$row['entity_id']
            : null;
        $row['confidence'] = (float)$row['confidence'];
        $row['attributes'] = json_decode(
            (string)$row['attributes_json'],
            true
        ) ?: [];
        $result[$row['product_id']] = $row;
    }
    return $result;
}

function ingredientOntologyV3ApplyCuratedProviderReviews(
    PDO $db,
    int $versionId
): array {
    $insert = $db->prepare("
        INSERT INTO ingredient_ontology_curated_provider_reviews (
            ontology_version_id, connector, metadata_schema_version,
            namespace, provider_ref, disposition, rationale, provenance
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(
            ontology_version_id, connector, metadata_schema_version,
            namespace, provider_ref
        ) DO UPDATE SET
            disposition = excluded.disposition,
            rationale = excluded.rationale,
            provenance = excluded.provenance
    ");
    $reviews = 0;
    $terms = $db->prepare("
        SELECT connector, metadata_schema_version, namespace, provider_ref,
               consistency_state, mapping_status
        FROM ingredient_ontology_provider_terms
        WHERE ontology_version_id = ?
          AND (
              provider_ref = 'com.vorwerk.ingredients.Ingredient-rpf-548'
              OR consistency_state IN ('variant', 'conflicted')
              OR id IN (
                  SELECT DISTINCT provider_term_id
                  FROM ingredient_ontology_mappings
                  WHERE ontology_version_id = ?
                    AND identity_basis = 'provider_local_conflict'
                    AND provider_term_id IS NOT NULL
              )
          )
        ORDER BY provider_ref
    ");
    $terms->execute([$versionId, $versionId]);
    while ($term = $terms->fetch(PDO::FETCH_ASSOC)) {
        $variant = (string)$term['consistency_state'] === 'variant';
        $insert->execute([
            $versionId,
            $term['connector'],
            $term['metadata_schema_version'],
            $term['namespace'],
            $term['provider_ref'],
            'quarantined',
            $variant
                ? 'Two semantically similar title variants require explicit policy; no majority vote'
                : 'Provider/local base or hard-facet conflict remains unproven',
            INGREDIENT_ONTOLOGY_V3_CURATED_VERSION,
        ]);
        $reviews++;
    }
    $insertConflict = $db->prepare("
        INSERT INTO ingredient_ontology_curated_provider_conflict_reviews (
            ontology_version_id, mapping_id, provider_term_id,
            disposition, rationale, provenance
        )
        VALUES (?, ?, ?, 'quarantined', ?, ?)
        ON CONFLICT(ontology_version_id, mapping_id) DO UPDATE SET
            provider_term_id = excluded.provider_term_id,
            disposition = excluded.disposition,
            rationale = excluded.rationale,
            provenance = excluded.provenance
    ");
    $conflicts = $db->prepare("
        SELECT id, provider_term_id,
               COALESCE(
                   json_extract(
                       evidence_json,
                       '$.provider_term.base_conflict'
                   ),
                   0
               ) AS base_conflict,
               COALESCE(
                   json_extract(
                       evidence_json,
                       '$.provider_term.hard_attribute_conflicts'
                   ),
                   '{}'
               ) AS hard_conflicts
        FROM ingredient_ontology_mappings
        WHERE ontology_version_id = ?
          AND owner_type = 'recipe_source_ingredient'
          AND identity_basis = 'provider_local_conflict'
        ORDER BY id
    ");
    $conflicts->execute([$versionId]);
    $conflictReviews = 0;
    while ($conflict = $conflicts->fetch(PDO::FETCH_ASSOC)) {
        $insertConflict->execute([
            $versionId,
            (int)$conflict['id'],
            $conflict['provider_term_id'] !== null
                ? (int)$conflict['provider_term_id']
                : null,
            !empty($conflict['base_conflict'])
                ? 'Provider/local accepted bases conflict; no automatic override'
                : 'Provider/local hard facets conflict; no automatic override',
            INGREDIENT_ONTOLOGY_V3_CURATED_VERSION,
        ]);
        $conflictReviews++;
    }
    return [
        'review_count' => $reviews,
        'conflict_review_count' => $conflictReviews,
    ];
}

function ingredientOntologyV3CuratedAudit(
    PDO $db,
    int $versionId
): array {
    $status = [];
    $stmt = $db->prepare("
        SELECT status, COUNT(*)
        FROM ingredient_ontology_curated_product_assertions
        WHERE ontology_version_id = ?
        GROUP BY status ORDER BY status
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $status[(string)$row[0]] = (int)$row[1];
    }
    $terminalStatus = [];
    $stmt = $db->prepare("
        SELECT d.disposition_code, COUNT(*)
        FROM ingredient_ontology_curated_product_assertions a
        JOIN ingredient_ontology_terminal_dispositions d
          ON d.id = a.terminal_disposition_id
        WHERE a.ontology_version_id = ?
        GROUP BY d.disposition_code ORDER BY d.disposition_code
    ");
    $stmt->execute([$versionId]);
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $terminalStatus[(string)$row[0]] = (int)$row[1];
    }
    $top = $db->prepare("
        SELECT normalized_label, COUNT(*) AS occurrences,
               MAX(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END)
                    AS accepted
        FROM ingredient_ontology_mappings
        WHERE ontology_version_id = ?
          AND owner_type = 'recipe_ingredient'
        GROUP BY normalized_label
        ORDER BY occurrences DESC, normalized_label
        LIMIT 300
    ");
    $top->execute([$versionId]);
    $rows = $top->fetchAll(PDO::FETCH_ASSOC);
    $covered = count(array_filter(
        $rows,
        static fn(array $row): bool => !empty($row['accepted'])
    ));
    $providerReviewCount = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_curated_provider_reviews
        WHERE ontology_version_id = ?
    ");
    $providerReviewCount->execute([$versionId]);
    $providerConflictReviewCount = $db->prepare("
        SELECT COUNT(*)
        FROM ingredient_ontology_curated_provider_conflict_reviews
        WHERE ontology_version_id = ?
    ");
    $providerConflictReviewCount->execute([$versionId]);
    $products = $db->prepare("
        SELECT a.product_id, a.product_name, a.normalized_product_name,
               a.product_fingerprint, a.status, a.confidence,
               e.slug AS entity_slug, e.canonical_name AS entity_name,
               a.attributes_json, a.rationale, a.provenance,
               a.review_state, p.name AS current_name,
               p.brand AS current_brand, p.category AS current_category,
               p.prepared_food AS current_prepared_food
        FROM ingredient_ontology_curated_product_assertions a
        JOIN products p ON p.id = a.product_id
        LEFT JOIN ingredient_ontology_entities e ON e.id = a.entity_id
        WHERE a.ontology_version_id = ?
        ORDER BY a.product_id
    ");
    $products->execute([$versionId]);
    $manifest = ingredientOntologyV3CuratedProductManifest();
    $productRows = [];
    while ($product = $products->fetch(PDO::FETCH_ASSOC)) {
        $product['product_id'] = (int)$product['product_id'];
        $product['confidence'] = (float)$product['confidence'];
        $product['attributes'] = json_decode(
            (string)$product['attributes_json'],
            true
        ) ?: [];
        unset($product['attributes_json']);
        $reviewFingerprint =
            ingredientOntologyV3CuratedProductReviewFingerprint([
                'name' => $product['current_name'],
                'brand' => $product['current_brand'],
                'category' => $product['current_category'],
                'prepared_food' =>
                    $product['current_prepared_food'],
            ]);
        $review = $manifest[$reviewFingerprint] ?? null;
        $product['review_fingerprint'] = $reviewFingerprint;
        $product['manifest_match'] = $review !== null;
        $product['manifest_expected_product_id'] =
            $review !== null
                ? (int)$review['expected_product_id']
                : null;
        unset(
            $product['current_name'],
            $product['current_brand'],
            $product['current_category'],
            $product['current_prepared_food']
        );
        $productRows[] = $product;
    }
    return [
        'product_statuses' => $status,
        'product_terminal_dispositions' => $terminalStatus,
        'product_count' => array_sum($status),
        'top_300_label_count' => count($rows),
        'top_300_accepted_labels' => $covered,
        'top_300_accepted_rate' => $rows
            ? round($covered / count($rows), 6)
            : 1.0,
        'provider_review_count' =>
            (int)$providerReviewCount->fetchColumn(),
        'provider_conflict_review_count' =>
            (int)$providerConflictReviewCount->fetchColumn(),
        'products' => $productRows,
    ];
}

function ingredientOntologyV3WriteCuratedProductCsv(
    PDO $db,
    int $versionId,
    mixed $stream
): void {
    if (!is_resource($stream)) {
        throw new InvalidArgumentException('curated CSV stream is invalid');
    }
    fputcsv($stream, [
        'product_id',
        'product_name',
        'product_fingerprint',
        'review_fingerprint',
        'manifest_match',
        'manifest_expected_product_id',
        'status',
        'confidence',
        'entity_slug',
        'entity_name',
        'attributes_json',
        'rationale',
        'provenance',
        'review_state',
    ]);
    $stmt = $db->prepare("
        SELECT a.product_id, a.product_name, a.product_fingerprint,
               a.status, a.confidence, e.slug AS entity_slug,
               e.canonical_name AS entity_name, a.attributes_json,
               a.rationale, a.provenance, a.review_state,
               p.name AS current_name, p.brand AS current_brand,
               p.category AS current_category,
               p.prepared_food AS current_prepared_food
        FROM ingredient_ontology_curated_product_assertions a
        JOIN products p ON p.id = a.product_id
        LEFT JOIN ingredient_ontology_entities e ON e.id = a.entity_id
        WHERE a.ontology_version_id = ?
        ORDER BY a.product_id
    ");
    $stmt->execute([$versionId]);
    $manifest = ingredientOntologyV3CuratedProductManifest();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $reviewFingerprint =
            ingredientOntologyV3CuratedProductReviewFingerprint([
                'name' => $row['current_name'],
                'brand' => $row['current_brand'],
                'category' => $row['current_category'],
                'prepared_food' => $row['current_prepared_food'],
            ]);
        $review = $manifest[$reviewFingerprint] ?? null;
        fputcsv($stream, [
            $row['product_id'],
            $row['product_name'],
            $row['product_fingerprint'],
            $reviewFingerprint,
            $review !== null ? 1 : 0,
            $review !== null
                ? (int)$review['expected_product_id']
                : null,
            $row['status'],
            $row['confidence'],
            $row['entity_slug'],
            $row['entity_name'],
            $row['attributes_json'],
            $row['rationale'],
            $row['provenance'],
            $row['review_state'],
        ]);
    }
}
