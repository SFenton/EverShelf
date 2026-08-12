#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--')) {
        continue;
    }
    [$key, $value] = array_pad(
        explode('=', substr($argument, 2), 2),
        2,
        ''
    );
    $options[$key] = $value;
}
$dbPath = trim((string)($options['db'] ?? ''));
$versionId = (int)($options['version-id'] ?? 0);
$output = trim((string)($options['out'] ?? ''));
if ($dbPath === '' || $versionId <= 0 || $output === '') {
    throw new InvalidArgumentException(
        '--db, --version-id, and --out are required'
    );
}
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$positiveRows = $db->prepare("
    SELECT l.id, l.label, l.language, e.slug,
           p.required_cohort, p.required_evidence_kind,
           p.required_evidence_key
    FROM ingredient_ontology_labels l
    JOIN ingredient_ontology_entities e ON e.id = l.entity_id
    LEFT JOIN ingredient_ontology_label_context_policies p
      ON p.label_id = l.id
    WHERE l.ontology_version_id = ?
      AND l.review_state = 'accepted'
      AND l.kind IN ('exact_alias', 'attribute_alias')
      AND e.active = 1
      AND e.identity_role NOT IN (
          'structural_category', 'staple_class'
      )
    ORDER BY
      CASE l.provenance
          WHEN 'full-resolution-v1' THEN 0
          WHEN 'curated-review-v3' THEN 1
          WHEN 'multilingual_staple_seed' THEN 2
          WHEN 'semantic_seed' THEN 3
          ELSE 4
      END,
      l.language, l.normalized_label, l.id
    LIMIT 300
");
$positiveRows->execute([$versionId]);
$attributeRows = $db->prepare("
    SELECT f.facet_key, fv.value_key
    FROM ingredient_ontology_label_attributes a
    JOIN ingredient_ontology_facets f ON f.id = a.facet_id
    JOIN ingredient_ontology_facet_values fv
      ON fv.id = a.facet_value_id
    WHERE a.label_id = ?
    ORDER BY f.facet_key
");
$positives = [];
while ($row = $positiveRows->fetch(PDO::FETCH_ASSOC)) {
    $attributeRows->execute([(int)$row['id']]);
    $attributes = [];
    while ($attribute = $attributeRows->fetch(PDO::FETCH_ASSOC)) {
        $attributes[(string)$attribute['facet_key']] =
            (string)$attribute['value_key'];
    }
    $positives[] = [
        'label' => (string)$row['label'],
        'language' => (string)$row['language'],
        'entity_slug' => (string)$row['slug'],
        'attributes' => $attributes,
        'context' => array_filter([
            'cohort' => $row['required_cohort'],
            'evidence_kind' => $row['required_evidence_kind'],
            'evidence_key' => $row['required_evidence_key'],
        ], static fn(mixed $value): bool => $value !== null),
    ];
}
if (count($positives) !== 300) {
    throw new RuntimeException(
        'resolution snapshot requires exactly 300 positive aliases'
    );
}

$negativeGroups = [
    [
        'forbidden' => 'coffee-pod',
        'labels' => [
            'cardamom pods', 'green cardamom pods',
            'black cardamom pods', 'whole cardamom pods',
            'ground cardamom pods', 'crushed cardamom pods',
            'dried cardamom pods', 'fresh cardamom pods',
            'vanilla pod', 'vanilla pods', 'vanilla bean pod',
            'vanilla bean pods', 'whole vanilla pods',
            'split vanilla pods', 'dried vanilla pods',
            'fresh vanilla pods', 'tonka bean pods',
            'cocoa bean pods', 'pea pods', 'okra pods',
        ],
    ],
    [
        'forbidden' => 'bitters',
        'labels' => [
            'bitter chocolate', 'bitter dark chocolate',
            'bittersweet chocolate', 'bitter çikolata',
            'zartbitter schokolade', 'chocolat amer',
            'chocolate amargo', 'cioccolato amaro',
            'chocolate meio amargo', 'gorzkiej czekolady',
            'unsweetened bitter chocolate', 'dark bitter chocolate',
            '70 percent bitter chocolate', 'bitter cocoa chocolate',
            'bitter milk chocolate',
        ],
    ],
    [
        'forbidden' => 'salsa',
        'labels' => [
            'soy sauce', 'light soy sauce', 'dark soy sauce',
            'fish sauce', 'salsa de soja', 'salsa di soia',
            'salsa di pesce', 'salsa de pescado',
            'di salsa di soia', 'de salsa de soja',
            'sauce soja', 'sauce de soja', 'sauce poisson',
            'salsa inglesa', 'salsa teriyaki', 'salsa hoisin',
            'salsa barbecue', 'salsa oyster', 'salsa Worcestershire',
            'salsa de ostras',
        ],
    ],
    [
        'forbidden' => 'rice',
        'labels' => [
            'rice flour', 'brown rice flour', 'white rice flour',
            'glutinous rice flour', 'rice wine', 'Chinese rice wine',
            'Shaoxing rice wine', 'rice wine vinegar',
            'rice paper', 'rice noodles', 'rice milk', 'rice syrup',
            'rice bran oil', 'rice starch', 'rice crackers',
            'rice pudding', 'rice cake', 'rice seasoning',
            'rice cooking wine', 'rice vermicelli',
        ],
    ],
    [
        'forbidden' => 'peanut',
        'labels' => [
            'peanut oil', 'refined peanut oil',
            'roasted peanut oil', 'cold pressed peanut oil',
            'groundnut oil', 'arachis oil', 'peanut cooking oil',
            'peanut frying oil', 'peanut flavored oil',
            'blended peanut oil',
        ],
    ],
    [
        'forbidden' => null,
        'expected_status' => 'unresolved',
        'labels' => [
            'piment', 'ground piment', 'fresh piment',
            'red piment', 'green piment', 'cornflour',
            'fine cornflour', 'white cornflour',
            'wheat cornflour', 'cornflour starch',
            'tomato puree', 'tomato purée',
            'smooth tomato puree', 'thick tomato puree',
            'concentrated tomato puree', 'air', 'cold air',
            'warm air', 'dry air', 'compressed air',
            'garam', 'ground garam', 'fresh garam',
            'garam powder', 'garam spice', 'legume',
            'legumes', 'fresh legumes', 'mixed legumes',
            'dried legumes',
        ],
    ],
    [
        'forbidden' => 'piper-pepper',
        'labels' => [
            'red capsicum', 'green capsicum', 'yellow capsicum',
            'red bell pepper', 'green bell pepper',
            'yellow bell pepper', 'fresh red chilli',
            'red chilli pepper', 'green chilli pepper',
            'jalapeño pepper', 'banana pepper', 'sweet pepper',
            'roasted red pepper', 'pickled red pepper',
            'capsicum pepper',
        ],
    ],
    [
        'forbidden' => 'milk',
        'labels' => [
            'hazelnut milk', 'almond milk', 'oat milk',
            'rice milk', 'soy milk', 'coconut milk',
            'cashew milk', 'pea milk', 'hemp milk',
            'macadamia milk',
        ],
    ],
    [
        'forbidden' => null,
        'expected_status' => 'unresolved',
        'labels' => [
            'food', 'ingredient', 'plant derived',
            'animal derived', 'vegetable', 'fruit', 'herb',
            'spice', 'nut', 'seed', 'grain', 'dairy',
            'condiment', 'beverage', 'meal',
        ],
    ],
];
$negatives = [];
$negativeId = 0;
foreach ($negativeGroups as $group) {
    foreach ($group['labels'] as $label) {
        $negativeId++;
        $negatives[] = [
            'id' => sprintf('critical-negative-%03d', $negativeId),
            'label' => $label,
            'language' => 'en',
            'forbidden_entity_slug' => $group['forbidden'],
            'expected_status' => $group['expected_status'] ?? null,
            'critical' => true,
        ];
    }
}
if (count($negatives) < 150) {
    throw new RuntimeException(
        'resolution snapshot requires at least 150 critical negatives'
    );
}

$fixture = [
    'schema_version' => 'ingredient-ontology-v3-resolution-snapshot-v1',
    'generated_from' => [
        'ontology_version_id' => $versionId,
        'ontology_content_hash' => (string)(
            ingredientOntologyV3Version($db, $versionId)['content_hash']
                ?? ''
        ),
        'generator' => basename(__FILE__),
    ],
    'positive_cases' => $positives,
    'critical_negative_cases' => $negatives,
];
$encoded = json_encode(
    $fixture,
    JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_THROW_ON_ERROR
) . PHP_EOL;
if (file_put_contents($output, $encoded, LOCK_EX) === false) {
    throw new RuntimeException('resolution fixture could not be written');
}
echo ingredientOntologyV3Json([
    'output' => $output,
    'positive_cases' => count($positives),
    'critical_negative_cases' => count($negatives),
    'sha256' => hash('sha256', $encoded),
]) . PHP_EOL;
