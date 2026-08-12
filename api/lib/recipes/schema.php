<?php
/**
 * Additive normalized recipe catalog schema.
 *
 * The legacy `recipes` table remains the compatibility store for meal plans.
 */

require_once __DIR__ . '/../ontology_v3/schema.php';

const RECIPE_MAX_FACTUAL_DURATION_SECONDS = 366 * 24 * 60 * 60;
const RECIPE_ONTOLOGY_SOURCE_TRIGGER_VERSION = 31501;

function recipeArrayIsList(array $value): bool {
    $expected = 0;
    foreach ($value as $key => $_) {
        if ($key !== $expected) {
            return false;
        }
        $expected++;
    }
    return true;
}

function recipeOntologySourceTriggerHash(
    PDO $db,
    array $triggerNames
): string {
    if (!$triggerNames) {
        return '';
    }
    $sortedNames = array_values($triggerNames);
    sort($sortedNames, SORT_STRING);
    $stmt = $db->prepare("
        SELECT name, sql
        FROM sqlite_master
        WHERE type = 'trigger'
          AND name IN (" . implode(
              ',',
              array_fill(0, count($sortedNames), '?')
          ) . ")
        ORDER BY name
    ");
    $stmt->execute($sortedNames);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (
        count($rows) !== count($sortedNames)
        || array_column($rows, 'name') !== $sortedNames
    ) {
        return '';
    }
    $hash = hash_init('sha256');
    foreach ($rows as $row) {
        hash_update(
            $hash,
            (string)$row['name'] . "\n"
                . (string)$row['sql'] . "\n"
        );
    }
    return hash_final($hash);
}

function recipeSchemaMigrate(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS recipe_catalog (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            primary_connector TEXT NOT NULL DEFAULT 'manual',
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            image_url TEXT NOT NULL DEFAULT '',
            language TEXT NOT NULL DEFAULT 'und',
            servings INTEGER DEFAULT NULL,
            prep_time TEXT DEFAULT NULL,
            cook_time TEXT DEFAULT NULL,
            total_time TEXT DEFAULT NULL,
            cuisine TEXT NOT NULL DEFAULT '',
            category TEXT NOT NULL DEFAULT '',
            yield_quantity REAL DEFAULT NULL,
            yield_unit TEXT DEFAULT NULL,
            active_time_seconds INTEGER DEFAULT NULL,
            total_time_seconds INTEGER DEFAULT NULL,
            difficulty TEXT DEFAULT NULL,
            primary_category TEXT DEFAULT NULL,
            equipment_json TEXT NOT NULL DEFAULT '[]',
            keywords_json TEXT NOT NULL DEFAULT '[]',
            instructions_json TEXT NOT NULL DEFAULT '[]',
            instruction_groups_json TEXT NOT NULL DEFAULT '[]',
            nutrition_json TEXT NOT NULL DEFAULT '{}',
            storage_policy TEXT NOT NULL DEFAULT 'persistent',
            rights_basis TEXT NOT NULL DEFAULT 'user_or_generated',
            cache_expires_at DATETIME DEFAULT NULL,
            stale_at DATETIME DEFAULT NULL,
            source_payload_json TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            retrieved_at DATETIME DEFAULT NULL,
            deleted_at DATETIME DEFAULT NULL
        );

        CREATE TABLE IF NOT EXISTS recipe_origins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id INTEGER NOT NULL,
            connector TEXT NOT NULL,
            external_id TEXT DEFAULT NULL,
            canonical_url TEXT DEFAULT NULL,
            locale TEXT DEFAULT NULL,
            attribution TEXT DEFAULT NULL,
            license TEXT DEFAULT NULL,
            metadata_version TEXT DEFAULT NULL,
            metadata_schema_version TEXT DEFAULT NULL,
            metadata_failure_version TEXT DEFAULT NULL,
            metadata_failure_kind TEXT DEFAULT NULL,
            metadata_failure_at DATETIME DEFAULT NULL,
            metadata_failure_count INTEGER NOT NULL DEFAULT 0
                CHECK(metadata_failure_count BETWEEN 0 AND 255),
            metadata_next_probe_at DATETIME DEFAULT NULL,
            metadata_failure_schema_version TEXT DEFAULT NULL,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            availability TEXT NOT NULL DEFAULT 'available',
            FOREIGN KEY (recipe_id) REFERENCES recipe_catalog(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_ingredients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id INTEGER NOT NULL,
            position INTEGER NOT NULL,
            raw_text TEXT NOT NULL DEFAULT '',
            normalized_name TEXT NOT NULL DEFAULT '',
            quantity REAL DEFAULT NULL,
            quantity_text TEXT DEFAULT NULL,
            unit TEXT DEFAULT NULL,
            quantity_parse_json TEXT DEFAULT NULL
                CHECK(
                    quantity_parse_json IS NULL
                    OR length(quantity_parse_json) <= 8192
                ),
            quantity_parse_version TEXT DEFAULT NULL
                CHECK(
                    quantity_parse_version IS NULL
                    OR length(quantity_parse_version) <= 80
                ),
            is_required INTEGER NOT NULL DEFAULT 1,
            is_optional INTEGER NOT NULL DEFAULT 0,
            is_staple INTEGER NOT NULL DEFAULT 0,
            source_is_required INTEGER DEFAULT NULL
                CHECK(source_is_required IS NULL OR source_is_required IN (0, 1)),
            source_is_optional INTEGER DEFAULT NULL
                CHECK(source_is_optional IS NULL OR source_is_optional IN (0, 1)),
            requiredness_source TEXT NOT NULL DEFAULT 'legacy_backfill'
                CHECK(length(requiredness_source) <= 40),
            canonical_ingredient_id INTEGER DEFAULT NULL,
            taxonomy_node_id INTEGER DEFAULT NULL,
            mapping_confidence REAL NOT NULL DEFAULT 0,
            mapping_source TEXT NOT NULL DEFAULT 'unresolved',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(recipe_id, position),
            FOREIGN KEY (recipe_id) REFERENCES recipe_catalog(id) ON DELETE CASCADE,
            FOREIGN KEY (canonical_ingredient_id) REFERENCES canonical_ingredients(id) ON DELETE SET NULL,
            FOREIGN KEY (taxonomy_node_id) REFERENCES taxonomy_nodes(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS recipe_source_ingredients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id INTEGER NOT NULL,
            position INTEGER NOT NULL,
            name TEXT NOT NULL DEFAULT '',
            normalized_name TEXT NOT NULL DEFAULT '',
            source_quantity REAL DEFAULT NULL,
            source_quantity_max REAL DEFAULT NULL,
            source_unit TEXT DEFAULT NULL,
            source_amount_text TEXT DEFAULT NULL,
            source_group_index INTEGER DEFAULT NULL
                CHECK(source_group_index IS NULL OR source_group_index BETWEEN 0 AND 199),
            source_group_position INTEGER DEFAULT NULL
                CHECK(source_group_position IS NULL OR source_group_position BETWEEN 0 AND 199),
            source_group_title TEXT DEFAULT NULL
                CHECK(source_group_title IS NULL OR length(source_group_title) <= 160),
            source_ingredient_ref TEXT DEFAULT NULL
                CHECK(source_ingredient_ref IS NULL OR length(source_ingredient_ref) <= 200),
            source_default_title TEXT DEFAULT NULL
                CHECK(source_default_title IS NULL OR length(source_default_title) <= 200),
            source_unit_ref TEXT DEFAULT NULL
                CHECK(source_unit_ref IS NULL OR length(source_unit_ref) <= 200),
            source_optional INTEGER DEFAULT NULL
                CHECK(source_optional IS NULL OR source_optional IN (0, 1)),
            source_shopping_category_ref TEXT DEFAULT NULL
                CHECK(
                    source_shopping_category_ref IS NULL
                    OR length(source_shopping_category_ref) <= 200
                ),
            canonical_ingredient_id INTEGER DEFAULT NULL,
            taxonomy_node_id INTEGER DEFAULT NULL,
            mapping_confidence REAL NOT NULL DEFAULT 0,
            mapping_source TEXT NOT NULL DEFAULT 'unresolved',
            mapping_version TEXT NOT NULL DEFAULT 'legacy-v1',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(recipe_id, position),
            FOREIGN KEY (recipe_id) REFERENCES recipe_catalog(id) ON DELETE CASCADE,
            FOREIGN KEY (canonical_ingredient_id) REFERENCES canonical_ingredients(id) ON DELETE SET NULL,
            FOREIGN KEY (taxonomy_node_id) REFERENCES taxonomy_nodes(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS recipe_user_state (
            recipe_id INTEGER PRIMARY KEY,
            favorite INTEGER NOT NULL DEFAULT 0,
            hidden INTEGER NOT NULL DEFAULT 0,
            rating INTEGER DEFAULT NULL,
            note TEXT NOT NULL DEFAULT '',
            cooked_count INTEGER NOT NULL DEFAULT 0,
            last_cooked DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recipe_id) REFERENCES recipe_catalog(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_grocery_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            idempotency_key TEXT NOT NULL UNIQUE,
            recipe_id INTEGER NOT NULL,
            request_fingerprint TEXT DEFAULT NULL,
            selection_hash TEXT NOT NULL,
            outcomes_json TEXT NOT NULL DEFAULT '[]',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recipe_id) REFERENCES recipe_catalog(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_quantity_parse_proposals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            input_hash TEXT NOT NULL CHECK(length(input_hash) = 64),
            source_connector TEXT NOT NULL
                CHECK(
                    length(source_connector) BETWEEN 1 AND 40
                    AND lower(source_connector) <> 'cookidoo'
                ),
            source_locale TEXT NOT NULL DEFAULT 'und'
                CHECK(length(source_locale) BETWEEN 2 AND 35),
            source_text TEXT NOT NULL
                CHECK(length(source_text) BETWEEN 1 AND 500),
            parser_version TEXT NOT NULL
                CHECK(length(parser_version) BETWEEN 1 AND 80),
            prompt_version TEXT NOT NULL
                CHECK(length(prompt_version) BETWEEN 1 AND 80),
            prompt_hash TEXT NOT NULL CHECK(length(prompt_hash) = 64),
            model_name TEXT NOT NULL
                CHECK(length(model_name) BETWEEN 1 AND 100),
            result_hash TEXT NOT NULL CHECK(length(result_hash) = 64),
            proposed_result_json TEXT NOT NULL
                CHECK(length(proposed_result_json) <= 8192),
            raw_response_json TEXT NOT NULL
                CHECK(length(raw_response_json) <= 65536),
            review_status TEXT NOT NULL DEFAULT 'pending'
                CHECK(review_status IN ('pending', 'approved', 'rejected')),
            reviewed_by TEXT DEFAULT NULL
                CHECK(reviewed_by IS NULL OR length(reviewed_by) <= 100),
            review_reason TEXT DEFAULT NULL
                CHECK(review_reason IS NULL OR length(review_reason) <= 500),
            reviewed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(input_hash, prompt_version, model_name, result_hash)
        );

        CREATE TABLE IF NOT EXISTS recipe_clusters (
            recipe_id INTEGER PRIMARY KEY,
            cluster_key TEXT NOT NULL,
            method TEXT NOT NULL DEFAULT 'heuristic',
            confidence REAL NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recipe_id) REFERENCES recipe_catalog(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            idempotency_key TEXT NOT NULL UNIQUE,
            job_type TEXT NOT NULL,
            priority INTEGER NOT NULL DEFAULT 0,
            scope TEXT DEFAULT NULL,
            connector TEXT DEFAULT NULL,
            ingredient_id INTEGER DEFAULT NULL,
            product_id INTEGER DEFAULT NULL,
            query TEXT DEFAULT NULL,
            payload_json TEXT NOT NULL DEFAULT '{}',
            status TEXT NOT NULL DEFAULT 'pending'
                CHECK(status IN ('pending', 'in_progress', 'retry', 'done', 'failed', 'skipped')),
            attempts INTEGER NOT NULL DEFAULT 0,
            max_attempts INTEGER NOT NULL DEFAULT 3,
            next_retry_at DATETIME DEFAULT NULL,
            last_error TEXT NOT NULL DEFAULT '',
            last_result_json TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME DEFAULT NULL,
            finished_at DATETIME DEFAULT NULL,
            FOREIGN KEY (ingredient_id) REFERENCES canonical_ingredients(id) ON DELETE SET NULL,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_score_state (
            id INTEGER PRIMARY KEY CHECK(id = 1),
            inventory_revision INTEGER NOT NULL DEFAULT 1,
            catalog_revision INTEGER NOT NULL DEFAULT 1,
            cursor_revision INTEGER NOT NULL DEFAULT 1,
            ontology_source_revision INTEGER NOT NULL DEFAULT 1,
            ontology_source_hash TEXT NOT NULL DEFAULT '',
            ontology_source_trigger_version INTEGER NOT NULL DEFAULT 0,
            ontology_source_trigger_hash TEXT NOT NULL DEFAULT ''
                CHECK(
                    ontology_source_trigger_hash = ''
                    OR length(ontology_source_trigger_hash) = 64
                ),
            active_score_revision_id INTEGER DEFAULT NULL,
            dirty_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_built_at DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS recipe_score_revisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            inventory_revision INTEGER NOT NULL,
            catalog_revision INTEGER NOT NULL DEFAULT 1,
            inventory_fingerprint TEXT NOT NULL,
            score_date DATE NOT NULL,
            catalog_max_id INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'building'
                CHECK(status IN ('building', 'ready', 'failed')),
            recipe_count INTEGER NOT NULL DEFAULT 0,
            ontology_version_id INTEGER DEFAULT NULL,
            scoring_model TEXT NOT NULL DEFAULT 'legacy-v2',
            scoring_config_hash TEXT DEFAULT NULL
                CHECK(
                    scoring_config_hash IS NULL
                    OR length(scoring_config_hash) = 64
                ),
            parent_score_revision_id INTEGER DEFAULT NULL,
            catalog_fingerprint TEXT NOT NULL DEFAULT '',
            ontology_schema_hash TEXT DEFAULT NULL,
            ontology_prompt_hash TEXT DEFAULT NULL,
            ontology_model_hash TEXT DEFAULT NULL,
            ontology_corpus_hash TEXT DEFAULT NULL,
            ontology_content_hash TEXT DEFAULT NULL,
            ontology_source_revision INTEGER NOT NULL DEFAULT 1,
            ontology_source_hash TEXT NOT NULL DEFAULT '',
            requirement_revision_id INTEGER DEFAULT NULL,
            requirement_model TEXT DEFAULT NULL
                CHECK(
                    requirement_model IS NULL
                    OR length(requirement_model) <= 80
                ),
            parity_baseline_score_revision_id INTEGER DEFAULT NULL,
            catalog_id_set_hash TEXT DEFAULT NULL,
            ingredient_id_set_hash TEXT DEFAULT NULL,
            requirement_recipe_id_set_hash TEXT DEFAULT NULL,
            requirement_id_set_hash TEXT DEFAULT NULL,
            score_rows_hash TEXT DEFAULT NULL,
            match_rows_hash TEXT DEFAULT NULL,
            materialization_hash TEXT DEFAULT NULL,
            validation_report_json TEXT NOT NULL DEFAULT '{}',
            last_error TEXT NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL,
            FOREIGN KEY (parity_baseline_score_revision_id)
                REFERENCES recipe_score_revisions(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS recipe_inventory_scores (
            score_revision_id INTEGER NOT NULL,
            recipe_id INTEGER NOT NULL,
            coverage REAL NOT NULL DEFAULT 0,
            directness REAL NOT NULL DEFAULT 0,
            expiry_score REAL NOT NULL DEFAULT 0,
            source_user_score REAL NOT NULL DEFAULT 0,
            availability_score REAL NOT NULL DEFAULT 0,
            required_count INTEGER NOT NULL DEFAULT 0,
            matched_required_count INTEGER NOT NULL DEFAULT 0,
            missing_required_count INTEGER NOT NULL DEFAULT 0,
            uncertain_required_count INTEGER NOT NULL DEFAULT 0,
            cookable INTEGER NOT NULL DEFAULT 0,
            soonest_expiry_days INTEGER DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (score_revision_id, recipe_id),
            FOREIGN KEY (score_revision_id) REFERENCES recipe_score_revisions(id)
                ON DELETE CASCADE,
            FOREIGN KEY (recipe_id) REFERENCES recipe_catalog(id)
                ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_connector_state (
            connector TEXT PRIMARY KEY,
            enabled INTEGER NOT NULL DEFAULT 1,
            cursor TEXT DEFAULT NULL,
            policy_version TEXT NOT NULL DEFAULT '1',
            last_success_at DATETIME DEFAULT NULL,
            last_error TEXT NOT NULL DEFAULT '',
            failure_count INTEGER NOT NULL DEFAULT 0,
            circuit_open_until DATETIME DEFAULT NULL,
            quota_json TEXT NOT NULL DEFAULT '{}',
            quota_reset_at DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS recipe_search_documents (
            recipe_id INTEGER PRIMARY KEY,
            title TEXT NOT NULL DEFAULT '',
            ingredient_text TEXT NOT NULL DEFAULT '',
            tags TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recipe_id) REFERENCES recipe_catalog(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_recipe_catalog_active
            ON recipe_catalog(deleted_at, cache_expires_at, updated_at DESC);
        CREATE INDEX IF NOT EXISTS idx_recipe_catalog_connector
            ON recipe_catalog(primary_connector, deleted_at);
        CREATE INDEX IF NOT EXISTS idx_recipe_catalog_language
            ON recipe_catalog(language, deleted_at);
        CREATE INDEX IF NOT EXISTS idx_recipe_origins_recipe
            ON recipe_origins(recipe_id);
        CREATE INDEX IF NOT EXISTS idx_recipe_origins_connector
            ON recipe_origins(connector, availability);
        CREATE UNIQUE INDEX IF NOT EXISTS idx_recipe_origins_connector_external
            ON recipe_origins(connector, external_id)
            WHERE external_id IS NOT NULL AND TRIM(external_id) <> '';
        CREATE INDEX IF NOT EXISTS idx_recipe_origins_canonical_url_lookup
            ON recipe_origins(canonical_url)
            WHERE canonical_url IS NOT NULL AND TRIM(canonical_url) <> '';
        CREATE INDEX IF NOT EXISTS idx_recipe_ingredients_recipe
            ON recipe_ingredients(recipe_id, position);
        CREATE INDEX IF NOT EXISTS idx_recipe_ingredients_canonical
            ON recipe_ingredients(canonical_ingredient_id);
        CREATE INDEX IF NOT EXISTS idx_recipe_ingredients_taxonomy
            ON recipe_ingredients(taxonomy_node_id);
        CREATE INDEX IF NOT EXISTS idx_recipe_grocery_requests_recipe
            ON recipe_grocery_requests(recipe_id, created_at DESC);
        CREATE INDEX IF NOT EXISTS idx_recipe_grocery_requests_created
            ON recipe_grocery_requests(created_at, id);
        CREATE INDEX IF NOT EXISTS idx_recipe_quantity_proposals_review
            ON recipe_quantity_parse_proposals(
                review_status, created_at, id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_quantity_proposals_input
            ON recipe_quantity_parse_proposals(input_hash, id);
        CREATE INDEX IF NOT EXISTS idx_recipe_user_state_favorite
            ON recipe_user_state(favorite, hidden, updated_at DESC);
        CREATE INDEX IF NOT EXISTS idx_recipe_clusters_key
            ON recipe_clusters(cluster_key);
        CREATE INDEX IF NOT EXISTS idx_recipe_jobs_product
            ON recipe_jobs(product_id, status);
        CREATE INDEX IF NOT EXISTS idx_recipe_jobs_connector
            ON recipe_jobs(connector, status);
        CREATE INDEX IF NOT EXISTS idx_recipe_score_revisions_ready
            ON recipe_score_revisions(status, completed_at DESC, id DESC);
        CREATE INDEX IF NOT EXISTS idx_recipe_inventory_scores_availability
            ON recipe_inventory_scores(
                score_revision_id, cookable DESC, coverage DESC,
                availability_score DESC, recipe_id ASC
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_inventory_scores_expiry
            ON recipe_inventory_scores(
                score_revision_id, expiry_score DESC, coverage DESC, recipe_id ASC
            );
    ");

    $scoreStateColumns = array_column(
        $db->query("PRAGMA table_info(recipe_score_state)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    foreach ([
        'catalog_revision' => 'INTEGER NOT NULL DEFAULT 1',
        'cursor_revision' => 'INTEGER NOT NULL DEFAULT 1',
        'ontology_source_revision' => 'INTEGER NOT NULL DEFAULT 1',
        'ontology_source_hash' => "TEXT NOT NULL DEFAULT ''",
        'ontology_source_trigger_version' =>
            'INTEGER NOT NULL DEFAULT 0',
        'ontology_source_trigger_hash' =>
            "TEXT NOT NULL DEFAULT ''",
    ] as $column => $definition) {
        if (in_array($column, $scoreStateColumns, true)) {
            continue;
        }
        try {
            $db->exec("
                ALTER TABLE recipe_score_state
                ADD COLUMN {$column} {$definition}
            ");
        } catch (PDOException $e) {
            if (!str_contains(
                strtolower($e->getMessage()),
                'duplicate column'
            )) {
                throw $e;
            }
        }
    }

    $catalogColumns = array_column(
        $db->query("PRAGMA table_info(recipe_catalog)")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    if (!in_array('stale_at', $catalogColumns, true)) {
        try {
            $db->exec("ALTER TABLE recipe_catalog ADD COLUMN stale_at DATETIME DEFAULT NULL");
        } catch (PDOException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) {
                throw $e;
            }
        }
    }
    foreach ([
        'yield_quantity' => 'REAL DEFAULT NULL',
        'yield_unit' => 'TEXT DEFAULT NULL',
        'active_time_seconds' => 'INTEGER DEFAULT NULL',
        'total_time_seconds' => 'INTEGER DEFAULT NULL',
        'difficulty' => 'TEXT DEFAULT NULL',
        'primary_category' => 'TEXT DEFAULT NULL',
        'equipment_json' => "TEXT NOT NULL DEFAULT '[]'",
        'instruction_groups_json' => "TEXT NOT NULL DEFAULT '[]'",
    ] as $column => $definition) {
        if (in_array($column, $catalogColumns, true)) {
            continue;
        }
        try {
            $db->exec("ALTER TABLE recipe_catalog ADD COLUMN {$column} {$definition}");
        } catch (PDOException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) {
                throw $e;
            }
        }
    }
    $originColumns = array_column(
        $db->query("PRAGMA table_info(recipe_origins)")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    foreach ([
        'metadata_version' => 'TEXT DEFAULT NULL',
        'metadata_schema_version' => 'TEXT DEFAULT NULL',
        'metadata_failure_version' => 'TEXT DEFAULT NULL',
        'metadata_failure_kind' => 'TEXT DEFAULT NULL',
        'metadata_failure_at' => 'DATETIME DEFAULT NULL',
        'metadata_failure_count' => 'INTEGER NOT NULL DEFAULT 0',
        'metadata_next_probe_at' => 'DATETIME DEFAULT NULL',
        'metadata_failure_schema_version' => 'TEXT DEFAULT NULL',
    ] as $column => $definition) {
        if (in_array($column, $originColumns, true)) {
            continue;
        }
        try {
            $db->exec("
                ALTER TABLE recipe_origins
                ADD COLUMN {$column} {$definition}
            ");
        } catch (PDOException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) {
                throw $e;
            }
        }
    }
    $sourceIngredientColumns = array_column(
        $db->query("PRAGMA table_info(recipe_source_ingredients)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    foreach ([
        'name' => "TEXT NOT NULL DEFAULT ''",
        'normalized_name' => "TEXT NOT NULL DEFAULT ''",
        'source_quantity' => 'REAL DEFAULT NULL',
        'source_quantity_max' => 'REAL DEFAULT NULL',
        'source_unit' => 'TEXT DEFAULT NULL',
        'source_amount_text' => 'TEXT DEFAULT NULL',
        'source_group_index' => (
            'INTEGER DEFAULT NULL CHECK('
            . 'source_group_index IS NULL '
            . 'OR source_group_index BETWEEN 0 AND 199)'
        ),
        'source_group_position' => (
            'INTEGER DEFAULT NULL CHECK('
            . 'source_group_position IS NULL '
            . 'OR source_group_position BETWEEN 0 AND 199)'
        ),
        'source_group_title' => (
            'TEXT DEFAULT NULL CHECK('
            . 'source_group_title IS NULL OR length(source_group_title) <= 160)'
        ),
        'source_ingredient_ref' => (
            'TEXT DEFAULT NULL CHECK('
            . 'source_ingredient_ref IS NULL OR length(source_ingredient_ref) <= 200)'
        ),
        'source_default_title' => (
            'TEXT DEFAULT NULL CHECK('
            . 'source_default_title IS NULL OR length(source_default_title) <= 200)'
        ),
        'source_unit_ref' => (
            'TEXT DEFAULT NULL CHECK('
            . 'source_unit_ref IS NULL OR length(source_unit_ref) <= 200)'
        ),
        'source_optional' => (
            'INTEGER DEFAULT NULL CHECK('
            . 'source_optional IS NULL OR source_optional IN (0, 1))'
        ),
        'source_shopping_category_ref' => (
            'TEXT DEFAULT NULL CHECK('
            . 'source_shopping_category_ref IS NULL '
            . 'OR length(source_shopping_category_ref) <= 200)'
        ),
        'canonical_ingredient_id' => 'INTEGER DEFAULT NULL',
        'taxonomy_node_id' => 'INTEGER DEFAULT NULL',
        'mapping_confidence' => 'REAL NOT NULL DEFAULT 0',
        'mapping_source' => "TEXT NOT NULL DEFAULT 'unresolved'",
        'mapping_version' => "TEXT NOT NULL DEFAULT 'legacy-v1'",
        'created_at' => 'DATETIME DEFAULT NULL',
        'updated_at' => 'DATETIME DEFAULT NULL',
    ] as $column => $definition) {
        if (in_array($column, $sourceIngredientColumns, true)) {
            continue;
        }
        try {
            $db->exec("
                ALTER TABLE recipe_source_ingredients
                ADD COLUMN {$column} {$definition}
            ");
        } catch (PDOException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) {
                throw $e;
            }
        }
    }
    $recipeIngredientColumns = array_column(
        $db->query("PRAGMA table_info(recipe_ingredients)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    foreach ([
        'quantity_parse_json' => (
            'TEXT DEFAULT NULL CHECK('
            . 'quantity_parse_json IS NULL '
            . 'OR length(quantity_parse_json) <= 8192)'
        ),
        'quantity_parse_version' => (
            'TEXT DEFAULT NULL CHECK('
            . 'quantity_parse_version IS NULL '
            . 'OR length(quantity_parse_version) <= 80)'
        ),
        'source_is_required' => (
            'INTEGER DEFAULT NULL CHECK('
            . 'source_is_required IS NULL OR source_is_required IN (0, 1))'
        ),
        'source_is_optional' => (
            'INTEGER DEFAULT NULL CHECK('
            . 'source_is_optional IS NULL OR source_is_optional IN (0, 1))'
        ),
        'requiredness_source' => (
            "TEXT NOT NULL DEFAULT 'legacy_backfill' "
            . 'CHECK(length(requiredness_source) <= 40)'
        ),
    ] as $column => $definition) {
        if (in_array($column, $recipeIngredientColumns, true)) {
            continue;
        }
        try {
            $db->exec("
                ALTER TABLE recipe_ingredients
                ADD COLUMN {$column} {$definition}
            ");
        } catch (PDOException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) {
                throw $e;
            }
        }
    }
    do {
        $updatedRequiredness = $db->exec("
            UPDATE recipe_ingredients
            SET source_is_optional = COALESCE(source_is_optional, is_optional),
                source_is_required = COALESCE(
                    source_is_required,
                    CASE
                        WHEN is_optional = 1 THEN 0
                        WHEN is_required = 1 THEN 1
                        WHEN is_staple = 1 THEN 1
                        ELSE 0
                    END
                ),
                requiredness_source = CASE
                    WHEN requiredness_source <> 'legacy_backfill'
                        THEN requiredness_source
                    WHEN is_optional = 1 THEN 'legacy_optional'
                    WHEN is_required = 1 THEN 'legacy_required'
                    WHEN is_staple = 1 THEN 'legacy_staple_recovery'
                    ELSE 'legacy_not_required'
                END
            WHERE id IN (
                SELECT id
                FROM recipe_ingredients
                WHERE source_is_required IS NULL
                   OR source_is_optional IS NULL
                ORDER BY id
                LIMIT 500
            )
        ");
    } while ($updatedRequiredness > 0);
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_recipe_origins_metadata_version
            ON recipe_origins(connector, locale, metadata_version, id);
        CREATE INDEX IF NOT EXISTS idx_recipe_origins_metadata_schema
            ON recipe_origins(
                connector, locale, metadata_version,
                metadata_schema_version, id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_origins_metadata_failure
            ON recipe_origins(
                connector, locale, metadata_failure_version, id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_origins_metadata_probe
            ON recipe_origins(
                connector, locale, metadata_version, metadata_failure_kind,
                metadata_next_probe_at, id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_origins_metadata_candidates
            ON recipe_origins(connector, lower(locale), id);
        CREATE INDEX IF NOT EXISTS idx_recipe_source_ingredients_recipe
            ON recipe_source_ingredients(recipe_id, position);
        CREATE INDEX IF NOT EXISTS idx_recipe_source_ingredients_grouped
            ON recipe_source_ingredients(
                recipe_id, source_group_index, source_group_position, position
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_source_ingredients_mapping_version
            ON recipe_source_ingredients(mapping_version, id);
        CREATE INDEX IF NOT EXISTS idx_recipe_source_ingredients_provider_ref
            ON recipe_source_ingredients(source_ingredient_ref, recipe_id)
            WHERE source_ingredient_ref IS NOT NULL;
        CREATE INDEX IF NOT EXISTS idx_recipe_source_ingredients_unit_ref
            ON recipe_source_ingredients(source_unit_ref, recipe_id)
            WHERE source_unit_ref IS NOT NULL;
        CREATE INDEX IF NOT EXISTS idx_recipe_source_ingredients_canonical
            ON recipe_source_ingredients(canonical_ingredient_id);
        CREATE INDEX IF NOT EXISTS idx_recipe_source_ingredients_taxonomy
            ON recipe_source_ingredients(taxonomy_node_id);
        CREATE INDEX IF NOT EXISTS idx_recipe_quantity_proposals_review
            ON recipe_quantity_parse_proposals(
                review_status, created_at, id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_quantity_proposals_input
            ON recipe_quantity_parse_proposals(input_hash, id);
    ");
    $groceryRequestColumns = array_column(
        $db->query("PRAGMA table_info(recipe_grocery_requests)")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    if (!in_array('request_fingerprint', $groceryRequestColumns, true)) {
        try {
            $db->exec("
                ALTER TABLE recipe_grocery_requests
                ADD COLUMN request_fingerprint TEXT DEFAULT NULL
            ");
        } catch (PDOException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) {
                throw $e;
            }
        }
    }
    $jobColumns = array_column(
        $db->query("PRAGMA table_info(recipe_jobs)")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    if (!in_array('priority', $jobColumns, true)) {
        try {
            $db->exec("ALTER TABLE recipe_jobs ADD COLUMN priority INTEGER NOT NULL DEFAULT 0");
        } catch (PDOException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) {
                throw $e;
            }
        }
    }
    $jobIndexSql = (string)($db->query("
        SELECT sql FROM sqlite_master
        WHERE type = 'index' AND name = 'idx_recipe_jobs_ready'
    ")->fetchColumn() ?: '');
    if ($jobIndexSql === '' || !str_contains(strtolower($jobIndexSql), 'priority')) {
        $db->exec("
            DROP INDEX IF EXISTS idx_recipe_jobs_ready;
            CREATE INDEX idx_recipe_jobs_ready
                ON recipe_jobs(status, priority DESC, next_retry_at, created_at);
        ");
    }
    $scoreRevisionColumns = array_column(
        $db->query("PRAGMA table_info(recipe_score_revisions)")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    if (!in_array('catalog_revision', $scoreRevisionColumns, true)) {
        try {
            $db->exec("
                ALTER TABLE recipe_score_revisions
                ADD COLUMN catalog_revision INTEGER NOT NULL DEFAULT 1
            ");
        } catch (PDOException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) {
                throw $e;
            }
        }
    }
    foreach ([
        'catalog_fingerprint' => "TEXT NOT NULL DEFAULT ''",
        'scoring_config_hash' => (
            'TEXT DEFAULT NULL CHECK('
            . 'scoring_config_hash IS NULL '
            . 'OR length(scoring_config_hash) = 64)'
        ),
        'ontology_schema_hash' => 'TEXT DEFAULT NULL',
        'ontology_prompt_hash' => 'TEXT DEFAULT NULL',
        'ontology_model_hash' => 'TEXT DEFAULT NULL',
        'ontology_corpus_hash' => 'TEXT DEFAULT NULL',
        'ontology_content_hash' => 'TEXT DEFAULT NULL',
        'ontology_source_revision' =>
            'INTEGER NOT NULL DEFAULT 1',
        'ontology_source_hash' => "TEXT NOT NULL DEFAULT ''",
    ] as $column => $definition) {
        if (in_array($column, $scoreRevisionColumns, true)) {
            continue;
        }
        try {
            $db->exec("
                ALTER TABLE recipe_score_revisions
                ADD COLUMN {$column} {$definition}
            ");
        } catch (PDOException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) {
                throw $e;
            }
        }
    }
    $scoreStateExists = (int)$db->query("
        SELECT COUNT(*) FROM recipe_score_state WHERE id = 1
    ")->fetchColumn();
    if ($scoreStateExists === 0) {
        $db->exec("INSERT OR IGNORE INTO recipe_score_state (id) VALUES (1)");
    }
    $ontologySourceTableCount = (int)$db->query("
        SELECT COUNT(*)
        FROM sqlite_master
        WHERE type = 'table'
          AND name IN (
              'products', 'recipe_catalog', 'recipe_origins',
              'recipe_ingredients', 'recipe_source_ingredients'
          )
    ")->fetchColumn();
    $ontologySourceStateColumns = array_column(
        $db->query("PRAGMA table_info(recipe_score_state)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    $ontologySourceTriggerNames = [
        'recipe_ontology_source_products_insert',
        'recipe_ontology_source_products_update',
        'recipe_ontology_source_products_delete',
        'recipe_ontology_source_catalog_insert',
        'recipe_ontology_source_catalog_update',
        'recipe_ontology_source_catalog_delete',
        'recipe_ontology_source_origins_insert',
        'recipe_ontology_source_origins_update',
        'recipe_ontology_source_origins_delete',
        'recipe_ontology_source_ingredients_insert',
        'recipe_ontology_source_ingredients_update',
        'recipe_ontology_source_ingredients_delete',
        'recipe_ontology_source_rows_insert',
        'recipe_ontology_source_rows_update',
        'recipe_ontology_source_rows_delete',
    ];
    $ontologySourceTriggerState = $db->query("
        SELECT ontology_source_trigger_version,
               ontology_source_trigger_hash
        FROM recipe_score_state
        WHERE id = 1
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $ontologySourceTriggerHash = recipeOntologySourceTriggerHash(
        $db,
        $ontologySourceTriggerNames
    );
    $ontologySourceTriggersCurrent =
        (int)($ontologySourceTriggerState[
            'ontology_source_trigger_version'
        ] ?? 0) === RECIPE_ONTOLOGY_SOURCE_TRIGGER_VERSION
        && strlen((string)($ontologySourceTriggerState[
            'ontology_source_trigger_hash'
        ] ?? '')) === 64
        && hash_equals(
            (string)$ontologySourceTriggerState[
                'ontology_source_trigger_hash'
            ],
            $ontologySourceTriggerHash
        );
    if (
        $ontologySourceTableCount === 5
        && !array_diff(
            ['ontology_source_revision', 'ontology_source_hash'],
            $ontologySourceStateColumns
        )
        && !$ontologySourceTriggersCurrent
    ) {
        $ownsOntologySourceTransaction = !$db->inTransaction();
        $ontologySourceTransactionStarted = false;
        try {
            if ($ownsOntologySourceTransaction) {
                $db->exec('BEGIN IMMEDIATE');
                $ontologySourceTransactionStarted = true;
            }
            $lockedTriggerState = $db->query("
                SELECT ontology_source_trigger_version,
                       ontology_source_trigger_hash
                FROM recipe_score_state
                WHERE id = 1
            ")->fetch(PDO::FETCH_ASSOC) ?: [];
            $lockedTriggerHash = recipeOntologySourceTriggerHash(
                $db,
                $ontologySourceTriggerNames
            );
            $lockedTriggersCurrent =
                (int)($lockedTriggerState[
                    'ontology_source_trigger_version'
                ] ?? 0) === RECIPE_ONTOLOGY_SOURCE_TRIGGER_VERSION
                && strlen((string)($lockedTriggerState[
                    'ontology_source_trigger_hash'
                ] ?? '')) === 64
                && hash_equals(
                    (string)$lockedTriggerState[
                        'ontology_source_trigger_hash'
                    ],
                    $lockedTriggerHash
                );
            if (!$lockedTriggersCurrent) {
                $db->exec("
        DROP TRIGGER IF EXISTS recipe_ontology_source_products_insert;
        DROP TRIGGER IF EXISTS recipe_ontology_source_products_update;
        DROP TRIGGER IF EXISTS recipe_ontology_source_products_delete;
        DROP TRIGGER IF EXISTS recipe_ontology_source_catalog_insert;
        DROP TRIGGER IF EXISTS recipe_ontology_source_catalog_update;
        DROP TRIGGER IF EXISTS recipe_ontology_source_catalog_delete;
        DROP TRIGGER IF EXISTS recipe_ontology_source_origins_insert;
        DROP TRIGGER IF EXISTS recipe_ontology_source_origins_update;
        DROP TRIGGER IF EXISTS recipe_ontology_source_origins_delete;
        DROP TRIGGER IF EXISTS recipe_ontology_source_ingredients_insert;
        DROP TRIGGER IF EXISTS recipe_ontology_source_ingredients_update;
        DROP TRIGGER IF EXISTS recipe_ontology_source_ingredients_delete;
        DROP TRIGGER IF EXISTS recipe_ontology_source_rows_insert;
        DROP TRIGGER IF EXISTS recipe_ontology_source_rows_update;
        DROP TRIGGER IF EXISTS recipe_ontology_source_rows_delete;

        CREATE TRIGGER recipe_ontology_source_products_insert
        AFTER INSERT ON products
        BEGIN
            UPDATE recipe_score_state
            SET ontology_source_revision =
                    ontology_source_revision + 1,
                ontology_source_hash = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1;
        END;
        CREATE TRIGGER recipe_ontology_source_products_update
        AFTER UPDATE OF name, brand, category, prepared_food ON products
        WHEN OLD.name IS NOT NEW.name
          OR OLD.brand IS NOT NEW.brand
          OR OLD.category IS NOT NEW.category
          OR OLD.prepared_food IS NOT NEW.prepared_food
        BEGIN
            UPDATE recipe_score_state
            SET ontology_source_revision =
                    ontology_source_revision + 1,
                ontology_source_hash = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1;
        END;
        CREATE TRIGGER recipe_ontology_source_products_delete
        AFTER DELETE ON products
        BEGIN
            UPDATE recipe_score_state
            SET ontology_source_revision =
                    ontology_source_revision + 1,
                ontology_source_hash = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1;
        END;

        CREATE TRIGGER recipe_ontology_source_catalog_insert
        AFTER INSERT ON recipe_catalog
        BEGIN
            UPDATE recipe_score_state
            SET ontology_source_revision =
                    ontology_source_revision + 1,
                ontology_source_hash = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1;
        END;
        CREATE TRIGGER recipe_ontology_source_catalog_update
        AFTER UPDATE OF primary_connector, language ON recipe_catalog
        WHEN OLD.primary_connector IS NOT NEW.primary_connector
          OR OLD.language IS NOT NEW.language
        BEGIN
            UPDATE recipe_score_state
            SET ontology_source_revision =
                    ontology_source_revision + 1,
                ontology_source_hash = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1;
        END;
        CREATE TRIGGER recipe_ontology_source_catalog_delete
        AFTER DELETE ON recipe_catalog
        BEGIN
            UPDATE recipe_score_state
            SET ontology_source_revision =
                    ontology_source_revision + 1,
                ontology_source_hash = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1;
        END;

        CREATE TRIGGER recipe_ontology_source_origins_insert
        AFTER INSERT ON recipe_origins
        BEGIN
            UPDATE recipe_score_state
            SET ontology_source_revision =
                    ontology_source_revision + 1,
                ontology_source_hash = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1;
        END;
        CREATE TRIGGER recipe_ontology_source_origins_update
        AFTER UPDATE OF recipe_id, connector, external_id, locale,
                        metadata_version, metadata_schema_version
        ON recipe_origins
        WHEN OLD.recipe_id IS NOT NEW.recipe_id
          OR OLD.connector IS NOT NEW.connector
          OR OLD.external_id IS NOT NEW.external_id
          OR OLD.locale IS NOT NEW.locale
          OR OLD.metadata_version IS NOT NEW.metadata_version
          OR OLD.metadata_schema_version
                IS NOT NEW.metadata_schema_version
        BEGIN
            UPDATE recipe_score_state
            SET ontology_source_revision =
                    ontology_source_revision + 1,
                ontology_source_hash = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1;
        END;
        CREATE TRIGGER recipe_ontology_source_origins_delete
        AFTER DELETE ON recipe_origins
        BEGIN
            UPDATE recipe_score_state
            SET ontology_source_revision =
                    ontology_source_revision + 1,
                ontology_source_hash = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1;
        END;

        CREATE TRIGGER recipe_ontology_source_ingredients_insert
        AFTER INSERT ON recipe_ingredients
        BEGIN
            UPDATE recipe_score_state
            SET ontology_source_revision =
                    ontology_source_revision + 1,
                ontology_source_hash = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1;
        END;
        CREATE TRIGGER recipe_ontology_source_ingredients_update
        AFTER UPDATE OF recipe_id, position, raw_text, normalized_name,
                        source_is_required, source_is_optional,
                        requiredness_source, canonical_ingredient_id,
                        taxonomy_node_id, mapping_confidence,
                        mapping_source
        ON recipe_ingredients
        WHEN OLD.recipe_id IS NOT NEW.recipe_id
          OR OLD.position IS NOT NEW.position
          OR OLD.raw_text IS NOT NEW.raw_text
          OR OLD.normalized_name IS NOT NEW.normalized_name
          OR OLD.source_is_required IS NOT NEW.source_is_required
          OR OLD.source_is_optional IS NOT NEW.source_is_optional
          OR OLD.requiredness_source IS NOT NEW.requiredness_source
          OR OLD.canonical_ingredient_id
                IS NOT NEW.canonical_ingredient_id
          OR OLD.taxonomy_node_id IS NOT NEW.taxonomy_node_id
          OR OLD.mapping_confidence IS NOT NEW.mapping_confidence
          OR OLD.mapping_source IS NOT NEW.mapping_source
        BEGIN
            UPDATE recipe_score_state
            SET ontology_source_revision =
                    ontology_source_revision + 1,
                ontology_source_hash = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1;
        END;
        CREATE TRIGGER recipe_ontology_source_ingredients_delete
        AFTER DELETE ON recipe_ingredients
        BEGIN
            UPDATE recipe_score_state
            SET ontology_source_revision =
                    ontology_source_revision + 1,
                ontology_source_hash = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1;
        END;

        CREATE TRIGGER recipe_ontology_source_rows_insert
        AFTER INSERT ON recipe_source_ingredients
        BEGIN
            UPDATE recipe_score_state
            SET ontology_source_revision =
                    ontology_source_revision + 1,
                ontology_source_hash = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1;
        END;
        CREATE TRIGGER recipe_ontology_source_rows_update
        AFTER UPDATE OF recipe_id, position, name, normalized_name,
                        source_optional, source_ingredient_ref,
                        source_default_title, canonical_ingredient_id,
                        taxonomy_node_id, mapping_confidence,
                        mapping_source
        ON recipe_source_ingredients
        WHEN OLD.recipe_id IS NOT NEW.recipe_id
          OR OLD.position IS NOT NEW.position
          OR OLD.name IS NOT NEW.name
          OR OLD.normalized_name IS NOT NEW.normalized_name
          OR OLD.source_optional IS NOT NEW.source_optional
          OR OLD.source_ingredient_ref
                IS NOT NEW.source_ingredient_ref
          OR OLD.source_default_title
                IS NOT NEW.source_default_title
          OR OLD.canonical_ingredient_id
                IS NOT NEW.canonical_ingredient_id
          OR OLD.taxonomy_node_id IS NOT NEW.taxonomy_node_id
          OR OLD.mapping_confidence IS NOT NEW.mapping_confidence
          OR OLD.mapping_source IS NOT NEW.mapping_source
        BEGIN
            UPDATE recipe_score_state
            SET ontology_source_revision =
                    ontology_source_revision + 1,
                ontology_source_hash = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1;
        END;
        CREATE TRIGGER recipe_ontology_source_rows_delete
        AFTER DELETE ON recipe_source_ingredients
        BEGIN
            UPDATE recipe_score_state
            SET ontology_source_revision =
                    ontology_source_revision + 1,
                ontology_source_hash = '',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1;
        END;
        ");
                $installedTriggerHash =
                    recipeOntologySourceTriggerHash(
                        $db,
                        $ontologySourceTriggerNames
                    );
                if (strlen($installedTriggerHash) !== 64) {
                    throw new RuntimeException(
                        'ontology source triggers were not installed completely'
                    );
                }
                $triggerStateUpdate = $db->prepare("
                    UPDATE recipe_score_state
                    SET ontology_source_revision =
                            ontology_source_revision + 1,
                        ontology_source_hash = '',
                        ontology_source_trigger_version = ?,
                        ontology_source_trigger_hash = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = 1
                ");
                $triggerStateUpdate->execute([
                    RECIPE_ONTOLOGY_SOURCE_TRIGGER_VERSION,
                    $installedTriggerHash,
                ]);
            }
            if ($ownsOntologySourceTransaction) {
                $db->exec('COMMIT');
                $ontologySourceTransactionStarted = false;
            }
        } catch (Throwable $e) {
            if ($ontologySourceTransactionStarted) {
                try {
                    $db->exec('ROLLBACK');
                } catch (Throwable $ignored) {
                }
            }
            throw $e;
        }
    }
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_recipe_catalog_visibility
            ON recipe_catalog(deleted_at, stale_at, updated_at DESC)
    ");

    $db->exec("
        CREATE VIRTUAL TABLE IF NOT EXISTS recipe_catalog_fts USING fts5(
            title,
            ingredient_text,
            tags,
            description,
            content='recipe_search_documents',
            content_rowid='recipe_id',
            tokenize='unicode61 remove_diacritics 2'
        )
    ");

    $db->exec("
        CREATE TRIGGER IF NOT EXISTS recipe_search_documents_ai
        AFTER INSERT ON recipe_search_documents BEGIN
            INSERT INTO recipe_catalog_fts(rowid, title, ingredient_text, tags, description)
            VALUES (new.recipe_id, new.title, new.ingredient_text, new.tags, new.description);
        END;

        CREATE TRIGGER IF NOT EXISTS recipe_search_documents_ad
        AFTER DELETE ON recipe_search_documents BEGIN
            INSERT INTO recipe_catalog_fts(
                recipe_catalog_fts, rowid, title, ingredient_text, tags, description
            )
            VALUES (
                'delete', old.recipe_id, old.title, old.ingredient_text, old.tags, old.description
            );
        END;

        CREATE TRIGGER IF NOT EXISTS recipe_search_documents_au
        AFTER UPDATE ON recipe_search_documents BEGIN
            INSERT INTO recipe_catalog_fts(
                recipe_catalog_fts, rowid, title, ingredient_text, tags, description
            )
            VALUES (
                'delete', old.recipe_id, old.title, old.ingredient_text, old.tags, old.description
            );
            INSERT INTO recipe_catalog_fts(rowid, title, ingredient_text, tags, description)
            VALUES (new.recipe_id, new.title, new.ingredient_text, new.tags, new.description);
        END;
    ");

    $connectorSeed = $db->prepare("
        INSERT OR IGNORE INTO recipe_connector_state (connector, enabled, policy_version)
        VALUES (?, 1, ?)
    ");
    foreach ([
        'local' => '1',
        'generated' => '1',
        'manual' => '1',
        'cookidoo' => 'metadata-v2',
    ] as $connector => $policyVersion) {
        $connectorSeed->execute([$connector, $policyVersion]);
    }
    $db->exec("
        UPDATE recipe_connector_state
        SET policy_version = 'metadata-v2', updated_at = CURRENT_TIMESTAMP
        WHERE connector = 'cookidoo' AND policy_version <> 'metadata-v2'
    ");

    ingredientOntologyV3SchemaMigrate($db);
}
