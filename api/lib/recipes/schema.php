<?php
/**
 * Additive normalized recipe catalog schema.
 *
 * The legacy `recipes` table remains the compatibility store for meal plans.
 */

require_once __DIR__ . '/../ontology_v3/schema.php';

const RECIPE_MAX_FACTUAL_DURATION_SECONDS = 366 * 24 * 60 * 60;
const RECIPE_SCHEMA_VERSION = 31601;
const RECIPE_ONTOLOGY_SOURCE_TRIGGER_VERSION = 31502;

function recipeSchemaTestHook(
    string $stage,
    array $context = []
): void {
    if (
        defined('RECIPE_BACKEND_TEST_MODE')
        && RECIPE_BACKEND_TEST_MODE
        && is_callable($GLOBALS['RECIPE_SCHEMA_TEST_HOOK'] ?? null)
    ) {
        ($GLOBALS['RECIPE_SCHEMA_TEST_HOOK'])($stage, $context);
    }
}

function recipeSchemaRunOnce(
    PDO $db,
    string $migrationKey,
    callable $migration
): bool {
    static $savepointSequence = 0;
    $savepoint = 'recipe_schema_once_' . (++$savepointSequence);
    $db->exec("SAVEPOINT {$savepoint}");
    try {
        $marker = $db->prepare("
            INSERT OR IGNORE INTO recipe_schema_migrations (
                migration_key, schema_version
            )
            VALUES (?, ?)
        ");
        $marker->execute([$migrationKey, RECIPE_SCHEMA_VERSION]);
        $applied = $marker->rowCount() === 1;
        if ($applied) {
            recipeSchemaTestHook('after_marker_insert', [
                'migration_key' => $migrationKey,
            ]);
            $migration();
        }
        $db->exec("RELEASE SAVEPOINT {$savepoint}");
        return $applied;
    } catch (Throwable $error) {
        try {
            $db->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            $db->exec("RELEASE SAVEPOINT {$savepoint}");
        } catch (Throwable $ignored) {
        }
        throw $error;
    }
}

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
            prep_time_seconds INTEGER DEFAULT NULL,
            cook_time_seconds INTEGER DEFAULT NULL,
            active_time_seconds INTEGER DEFAULT NULL,
            inactive_time_seconds INTEGER DEFAULT NULL,
            total_time_seconds INTEGER DEFAULT NULL,
            difficulty TEXT DEFAULT NULL,
            primary_category TEXT DEFAULT NULL,
            devices_json TEXT NOT NULL DEFAULT '[]',
            optional_devices_json TEXT NOT NULL DEFAULT '[]',
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
            content_language TEXT DEFAULT NULL
                CHECK(
                    content_language IS NULL
                    OR length(content_language) BETWEEN 2 AND 20
                ),
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
            last_applied_request_epoch INTEGER NOT NULL DEFAULT 0
                CHECK(last_applied_request_epoch >= 0),
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

        CREATE TABLE IF NOT EXISTS
            recipe_cookidoo_language_assessments (
            recipe_id INTEGER PRIMARY KEY,
            connector TEXT NOT NULL DEFAULT 'cookidoo'
                CHECK(connector = 'cookidoo'),
            content_hash TEXT NOT NULL CHECK(length(content_hash) = 64),
            verdict TEXT NOT NULL
                CHECK(verdict IN (
                    'english', 'non_english', 'undetermined'
                )),
            disposition TEXT NOT NULL DEFAULT 'review'
                CHECK(disposition IN (
                    'allow', 'review', 'quarantine'
                )),
            reason TEXT NOT NULL CHECK(length(reason) BETWEEN 1 AND 80),
            foreign_language TEXT DEFAULT NULL
                CHECK(
                    foreign_language IS NULL
                    OR length(foreign_language) BETWEEN 2 AND 20
                ),
            english_hits INTEGER NOT NULL DEFAULT 0
                CHECK(english_hits BETWEEN 0 AND 10000),
            foreign_hits INTEGER NOT NULL DEFAULT 0
                CHECK(foreign_hits BETWEEN 0 AND 10000),
            script_hits INTEGER NOT NULL DEFAULT 0
                CHECK(script_hits BETWEEN 0 AND 10000),
            token_count INTEGER NOT NULL DEFAULT 0
                CHECK(token_count BETWEEN 0 AND 100000),
            detector_version TEXT NOT NULL
                CHECK(length(detector_version) BETWEEN 1 AND 80),
            rules_hash TEXT NOT NULL CHECK(length(rules_hash) = 64),
            manual_override INTEGER NOT NULL DEFAULT 0
                CHECK(manual_override IN (0, 1)),
            evaluated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recipe_id)
                REFERENCES recipe_catalog(id) ON DELETE CASCADE
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

        CREATE TABLE IF NOT EXISTS recipe_ingredient_user_overrides (
            recipe_id INTEGER NOT NULL,
            ingredient_key TEXT NOT NULL
                CHECK(length(ingredient_key) BETWEEN 1 AND 64),
            position INTEGER NOT NULL CHECK(position BETWEEN 0 AND 10000),
            source_text_hash TEXT NOT NULL CHECK(length(source_text_hash) = 64),
            availability_override TEXT NOT NULL
                CHECK(availability_override IN ('have', 'missing')),
            evidence_token TEXT NOT NULL CHECK(length(evidence_token) = 64),
            observed_state TEXT NOT NULL
                CHECK(observed_state IN (
                    'in_stock', 'missing', 'uncertain', 'staple'
                )),
            observed_relation TEXT DEFAULT NULL
                CHECK(
                    observed_relation IS NULL
                    OR length(observed_relation) <= 80
                ),
            observed_confidence REAL NOT NULL DEFAULT 0
                CHECK(observed_confidence BETWEEN 0 AND 1),
            observed_product_id INTEGER DEFAULT NULL,
            observed_closest_label TEXT DEFAULT NULL
                CHECK(
                    observed_closest_label IS NULL
                    OR length(observed_closest_label) <= 240
                ),
            observed_mapping_source TEXT DEFAULT NULL
                CHECK(
                    observed_mapping_source IS NULL
                    OR length(observed_mapping_source) <= 80
                ),
            selected_product_id INTEGER DEFAULT NULL,
            selected_product_fingerprint TEXT DEFAULT NULL
                CHECK(
                    selected_product_fingerprint IS NULL
                    OR length(selected_product_fingerprint) = 64
                ),
            decision_action TEXT DEFAULT NULL
                CHECK(
                    decision_action IS NULL
                    OR decision_action IN (
                        'assume_have',
                        'select_inventory_product',
                        'reject_current_match'
                    )
                ),
            action_origin TEXT DEFAULT NULL
                CHECK(
                    action_origin IS NULL
                    OR length(action_origin) BETWEEN 1 AND 80
                ),
            observed_inventory_revision INTEGER DEFAULT NULL,
            observed_catalog_revision INTEGER DEFAULT NULL,
            score_revision_id INTEGER DEFAULT NULL,
            ontology_version_id INTEGER DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(recipe_id, ingredient_key),
            FOREIGN KEY (recipe_id)
                REFERENCES recipe_catalog(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_ingredient_feedback_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            idempotency_key TEXT NOT NULL UNIQUE
                CHECK(length(idempotency_key) BETWEEN 1 AND 128),
            request_fingerprint TEXT NOT NULL CHECK(length(request_fingerprint) = 64),
            recipe_id INTEGER NOT NULL,
            ingredient_key TEXT NOT NULL
                CHECK(length(ingredient_key) BETWEEN 1 AND 64),
            position INTEGER NOT NULL CHECK(position BETWEEN 0 AND 10000),
            event_type TEXT NOT NULL
                CHECK(event_type IN ('availability', 'identity')),
            availability_override TEXT DEFAULT NULL
                CHECK(
                    availability_override IS NULL
                    OR availability_override IN ('have', 'missing')
                ),
            identity_verdict TEXT DEFAULT NULL
                CHECK(
                    identity_verdict IS NULL
                    OR identity_verdict IN ('correct', 'wrong')
                ),
            target_kind TEXT DEFAULT NULL
                CHECK(
                    target_kind IS NULL
                    OR target_kind IN (
                        'matched_product', 'closest_match',
                        'inventory_product'
                    )
                ),
            target_product_id INTEGER DEFAULT NULL,
            target_label TEXT DEFAULT NULL
                CHECK(
                    target_label IS NULL
                    OR length(target_label) <= 240
                ),
            source_text_hash TEXT NOT NULL CHECK(length(source_text_hash) = 64),
            evidence_token TEXT NOT NULL CHECK(length(evidence_token) = 64),
            observed_state TEXT NOT NULL
                CHECK(observed_state IN (
                    'in_stock', 'missing', 'uncertain', 'staple'
                )),
            observed_relation TEXT DEFAULT NULL
                CHECK(
                    observed_relation IS NULL
                    OR length(observed_relation) <= 80
                ),
            observed_confidence REAL NOT NULL DEFAULT 0
                CHECK(observed_confidence BETWEEN 0 AND 1),
            observed_product_id INTEGER DEFAULT NULL,
            observed_closest_label TEXT DEFAULT NULL
                CHECK(
                    observed_closest_label IS NULL
                    OR length(observed_closest_label) <= 240
                ),
            observed_mapping_source TEXT DEFAULT NULL
                CHECK(
                    observed_mapping_source IS NULL
                    OR length(observed_mapping_source) <= 80
                ),
            score_revision_id INTEGER DEFAULT NULL,
            ontology_version_id INTEGER DEFAULT NULL,
            decision_action TEXT DEFAULT NULL
                CHECK(
                    decision_action IS NULL
                    OR decision_action IN (
                        'assume_have',
                        'select_inventory_product',
                        'reject_current_match'
                    )
                ),
            action_origin TEXT DEFAULT NULL
                CHECK(
                    action_origin IS NULL
                    OR length(action_origin) BETWEEN 1 AND 80
                ),
            source_fingerprint_v2 TEXT DEFAULT NULL
                CHECK(
                    source_fingerprint_v2 IS NULL
                    OR length(source_fingerprint_v2) = 64
                ),
            source_owner_fingerprint TEXT DEFAULT NULL
                CHECK(
                    source_owner_fingerprint IS NULL
                    OR length(source_owner_fingerprint) = 64
                ),
            target_owner_fingerprint TEXT DEFAULT NULL
                CHECK(
                    target_owner_fingerprint IS NULL
                    OR length(target_owner_fingerprint) = 64
                ),
            observed_inventory_revision INTEGER DEFAULT NULL,
            observed_catalog_revision INTEGER DEFAULT NULL,
            supersedes_event_id INTEGER DEFAULT NULL,
            review_state TEXT NOT NULL DEFAULT 'settling'
                CHECK(review_state IN (
                    'settling', 'eligible', 'exported',
                    'reviewed', 'rejected'
                )),
            settle_after DATETIME NOT NULL,
            result_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(result_json) <= 16384),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recipe_id)
                REFERENCES recipe_catalog(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_ingredient_proposal_outbox (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            feedback_event_id INTEGER NOT NULL UNIQUE,
            status TEXT NOT NULL DEFAULT 'queued'
                CHECK(status IN (
                    'queued', 'processing', 'retry', 'blocked',
                    'staged', 'superseded'
                )),
            attempts INTEGER NOT NULL DEFAULT 0
                CHECK(attempts BETWEEN 0 AND 1000),
            next_attempt_at DATETIME DEFAULT NULL,
            lease_token TEXT DEFAULT NULL
                CHECK(
                    lease_token IS NULL
                    OR length(lease_token) = 64
                ),
            lease_generation INTEGER NOT NULL DEFAULT 0,
            lease_expires_at DATETIME DEFAULT NULL,
            input_json TEXT NOT NULL
                CHECK(length(input_json) BETWEEN 2 AND 32768),
            prompt_artifact_id INTEGER DEFAULT NULL,
            response_artifact_id INTEGER DEFAULT NULL,
            last_error_kind TEXT DEFAULT NULL
                CHECK(
                    last_error_kind IS NULL
                    OR length(last_error_kind) <= 80
                ),
            last_error TEXT NOT NULL DEFAULT ''
                CHECK(length(last_error) <= 1000),
            claimed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (feedback_event_id)
                REFERENCES recipe_ingredient_feedback_events(id)
                ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_ingredient_proposal_prompts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            outbox_id INTEGER NOT NULL UNIQUE,
            feedback_event_id INTEGER NOT NULL UNIQUE,
            ontology_version_id INTEGER NOT NULL,
            model_name TEXT NOT NULL
                CHECK(length(model_name) BETWEEN 1 AND 100),
            prompt_text TEXT NOT NULL
                CHECK(length(prompt_text) BETWEEN 1 AND 120000),
            prompt_hash TEXT NOT NULL CHECK(length(prompt_hash) = 64),
            manifest_json TEXT NOT NULL
                CHECK(length(manifest_json) BETWEEN 2 AND 262144),
            manifest_hash TEXT NOT NULL CHECK(length(manifest_hash) = 64),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (outbox_id)
                REFERENCES recipe_ingredient_proposal_outbox(id)
                ON DELETE CASCADE,
            FOREIGN KEY (feedback_event_id)
                REFERENCES recipe_ingredient_feedback_events(id)
                ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_ingredient_proposal_responses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            prompt_artifact_id INTEGER NOT NULL,
            feedback_event_id INTEGER NOT NULL,
            source TEXT NOT NULL
                CHECK(source IN ('gemini_api', 'operator_import')),
            raw_response_json TEXT NOT NULL
                CHECK(length(raw_response_json) BETWEEN 2 AND 65536),
            response_hash TEXT NOT NULL CHECK(length(response_hash) = 64),
            validation_json TEXT NOT NULL
                CHECK(length(validation_json) BETWEEN 2 AND 65536),
            change_set_id INTEGER DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(prompt_artifact_id, response_hash),
            FOREIGN KEY (prompt_artifact_id)
                REFERENCES recipe_ingredient_proposal_prompts(id)
                ON DELETE CASCADE,
            FOREIGN KEY (feedback_event_id)
                REFERENCES recipe_ingredient_feedback_events(id)
                ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_ingredient_feedback_regression_fixtures (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            feedback_event_id INTEGER NOT NULL UNIQUE,
            case_key TEXT NOT NULL UNIQUE
                CHECK(length(case_key) BETWEEN 1 AND 120),
            polarity TEXT NOT NULL
                CHECK(polarity IN ('positive', 'negative')),
            source_fingerprint_v2 TEXT NOT NULL
                CHECK(length(source_fingerprint_v2) = 64),
            target_owner_fingerprint TEXT NOT NULL
                CHECK(length(target_owner_fingerprint) = 64),
            fixture_json TEXT NOT NULL
                CHECK(length(fixture_json) BETWEEN 2 AND 32768),
            status TEXT NOT NULL DEFAULT 'candidate'
                CHECK(status IN ('candidate', 'accepted', 'rejected')),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (feedback_event_id)
                REFERENCES recipe_ingredient_feedback_events(id)
                ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_planner_commands (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            idempotency_key TEXT NOT NULL UNIQUE
                CHECK(length(idempotency_key) BETWEEN 1 AND 128),
            request_fingerprint TEXT NOT NULL
                CHECK(length(request_fingerprint) = 64),
            recipe_id INTEGER NOT NULL,
            origin_id INTEGER NOT NULL,
            external_id TEXT NOT NULL
                CHECK(length(external_id) BETWEEN 1 AND 160),
            target_date TEXT NOT NULL
                CHECK(length(target_date) = 10),
            provider_action_token TEXT NOT NULL
                CHECK(length(provider_action_token) = 64),
            observed_catalog_revision INTEGER NOT NULL,
            account_scope TEXT NOT NULL DEFAULT 'configured_account'
                CHECK(account_scope = 'configured_account'),
            status TEXT NOT NULL DEFAULT 'reserved'
                CHECK(status IN (
                    'reserved', 'dispatching', 'succeeded',
                    'failed', 'blocked'
                )),
            result_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(result_json) <= 16384),
            last_error TEXT NOT NULL DEFAULT ''
                CHECK(length(last_error) <= 1000),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recipe_id)
                REFERENCES recipe_catalog(id) ON DELETE CASCADE,
            FOREIGN KEY (origin_id)
                REFERENCES recipe_origins(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_planner_command_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            command_id INTEGER NOT NULL,
            state TEXT NOT NULL
                CHECK(state IN (
                    'reserved', 'dispatching', 'reconciling',
                    'succeeded', 'failed', 'blocked', 'replayed'
                )),
            detail_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(detail_json) <= 8192),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (command_id)
                REFERENCES recipe_planner_commands(id) ON DELETE CASCADE
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
            request_epoch INTEGER NOT NULL DEFAULT 0
                CHECK(request_epoch >= 0),
            request_generation INTEGER NOT NULL DEFAULT 1
                CHECK(request_generation > 0),
            request_hash TEXT NOT NULL DEFAULT '',
            lease_token TEXT DEFAULT NULL,
            lease_generation INTEGER NOT NULL DEFAULT 0
                CHECK(lease_generation >= 0),
            lease_expires_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME DEFAULT NULL,
            finished_at DATETIME DEFAULT NULL,
            FOREIGN KEY (ingredient_id) REFERENCES canonical_ingredients(id) ON DELETE SET NULL,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_job_request_epoch (
            id INTEGER PRIMARY KEY CHECK(id = 1),
            next_epoch INTEGER NOT NULL DEFAULT 1
                CHECK(next_epoch > 0)
        );

        CREATE TABLE IF NOT EXISTS recipe_schema_migrations (
            migration_key TEXT PRIMARY KEY,
            schema_version INTEGER NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS recipe_worker_leases (
            lease_name TEXT PRIMARY KEY,
            lease_token TEXT DEFAULT NULL,
            lease_generation INTEGER NOT NULL DEFAULT 0
                CHECK(lease_generation >= 0),
            lease_expires_at DATETIME DEFAULT NULL,
            owner_started_at DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS recipe_score_state (
            id INTEGER PRIMARY KEY CHECK(id = 1),
            inventory_revision INTEGER NOT NULL DEFAULT 1,
            catalog_revision INTEGER NOT NULL DEFAULT 1,
            cursor_revision INTEGER NOT NULL DEFAULT 1,
            ontology_source_revision INTEGER NOT NULL DEFAULT 1,
            ontology_source_hash TEXT NOT NULL DEFAULT '',
            ontology_source_lineage_hash TEXT NOT NULL DEFAULT ''
                CHECK(
                    ontology_source_lineage_hash = ''
                    OR length(ontology_source_lineage_hash) = 64
                ),
            ontology_source_trigger_version INTEGER NOT NULL DEFAULT 0,
            ontology_source_trigger_hash TEXT NOT NULL DEFAULT ''
                CHECK(
                    ontology_source_trigger_hash = ''
                    OR length(ontology_source_trigger_hash) = 64
                ),
            active_score_revision_id INTEGER DEFAULT NULL,
            active_score_overlay_revision_id INTEGER DEFAULT NULL,
            active_score_projection_revision_id INTEGER DEFAULT NULL,
            dirty_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_built_at DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS recipe_score_pending_products (
            product_id INTEGER PRIMARY KEY,
            first_inventory_revision INTEGER NOT NULL
                CHECK(first_inventory_revision > 0),
            latest_inventory_revision INTEGER NOT NULL
                CHECK(latest_inventory_revision > 0),
            reason TEXT NOT NULL DEFAULT ''
                CHECK(length(reason) <= 160),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CHECK(latest_inventory_revision >= first_inventory_revision)
        );

        CREATE TABLE IF NOT EXISTS recipe_score_pending_recipes (
            recipe_id INTEGER PRIMARY KEY,
            operation TEXT NOT NULL
                CHECK(operation IN ('replace', 'delete')),
            first_catalog_revision INTEGER NOT NULL
                CHECK(first_catalog_revision > 0),
            latest_catalog_revision INTEGER NOT NULL
                CHECK(latest_catalog_revision > 0),
            latest_ontology_source_revision INTEGER NOT NULL
                CHECK(latest_ontology_source_revision > 0),
            reason TEXT NOT NULL DEFAULT ''
                CHECK(length(reason) <= 160),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CHECK(latest_catalog_revision >= first_catalog_revision)
        );

        CREATE TABLE IF NOT EXISTS recipe_score_mutations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            domain TEXT NOT NULL
                CHECK(domain IN ('catalog', 'source')),
            revision INTEGER NOT NULL CHECK(revision > 0),
            owner_type TEXT NOT NULL
                CHECK(owner_type IN ('recipe', 'product', 'global')),
            owner_id INTEGER DEFAULT NULL,
            operation TEXT NOT NULL
                CHECK(operation IN (
                    'insert', 'update', 'delete', 'replace', 'global'
                )),
            reason TEXT NOT NULL DEFAULT ''
                CHECK(length(reason) <= 160),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(domain, revision)
        );

        CREATE TABLE IF NOT EXISTS recipe_score_work_state (
            id INTEGER PRIMARY KEY CHECK(id = 1),
            phase TEXT NOT NULL DEFAULT 'idle'
                CHECK(phase IN (
                    'idle', 'preparing', 'scoring',
                    'publishing', 'compacting', 'failed'
                )),
            revision_id INTEGER DEFAULT NULL,
            parent_revision_id INTEGER DEFAULT NULL,
            total_recipe_count INTEGER NOT NULL DEFAULT 0
                CHECK(total_recipe_count >= 0),
            processed_recipe_count INTEGER NOT NULL DEFAULT 0
                CHECK(processed_recipe_count >= 0),
            pending_product_count INTEGER NOT NULL DEFAULT 0
                CHECK(pending_product_count >= 0),
            pending_recipe_count INTEGER NOT NULL DEFAULT 0
                CHECK(pending_recipe_count >= 0),
            last_error TEXT NOT NULL DEFAULT ''
                CHECK(length(last_error) <= 1000),
            started_at DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CHECK(processed_recipe_count <= total_recipe_count)
        );

        CREATE TABLE IF NOT EXISTS
            ingredient_ontology_recipe_identity_annex (
            recipe_ingredient_id INTEGER PRIMARY KEY,
            ontology_version_id INTEGER NOT NULL,
            ontology_content_hash TEXT NOT NULL
                CHECK(length(ontology_content_hash) = 64),
            ontology_seal_hash TEXT NOT NULL
                CHECK(length(ontology_seal_hash) = 64),
            owner_fingerprint TEXT NOT NULL
                CHECK(length(owner_fingerprint) = 64),
            source_label TEXT NOT NULL DEFAULT ''
                CHECK(length(source_label) <= 200),
            normalized_label TEXT NOT NULL DEFAULT ''
                CHECK(length(normalized_label) <= 200),
            language TEXT NOT NULL DEFAULT 'und'
                CHECK(length(language) BETWEEN 2 AND 35),
            label_id INTEGER DEFAULT NULL,
            entity_id INTEGER DEFAULT NULL,
            extension_entity_id INTEGER DEFAULT NULL,
            status TEXT NOT NULL
                CHECK(status IN ('accepted', 'unresolved', 'rejected')),
            confidence REAL NOT NULL DEFAULT 0
                CHECK(confidence BETWEEN 0 AND 1),
            admission_source TEXT NOT NULL DEFAULT 'none'
                CHECK(length(admission_source) <= 80),
            attributes_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(attributes_json) <= 16384),
            resolver_version TEXT NOT NULL
                CHECK(length(resolver_version) <= 80),
            review_manifest_hash TEXT NOT NULL
                CHECK(length(review_manifest_hash) = 64),
            evidence_hash TEXT NOT NULL
                CHECK(length(evidence_hash) = 64),
            reason TEXT NOT NULL
                CHECK(length(reason) BETWEEN 1 AND 80),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recipe_ingredient_id)
                REFERENCES recipe_ingredients(id) ON DELETE CASCADE,
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id)
                    ON DELETE CASCADE,
            FOREIGN KEY (entity_id)
                REFERENCES ingredient_ontology_entities(id)
                    ON DELETE SET NULL,
            FOREIGN KEY (extension_entity_id)
                REFERENCES ingredient_ontology_identity_extension_entities(id)
                    ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS recipe_score_incremental_recipes (
            score_revision_id INTEGER NOT NULL,
            recipe_id INTEGER NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (score_revision_id, recipe_id),
            FOREIGN KEY (score_revision_id)
                REFERENCES recipe_score_revisions(id) ON DELETE CASCADE,
            FOREIGN KEY (recipe_id)
                REFERENCES recipe_catalog(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_score_recipe_operations (
            score_revision_id INTEGER NOT NULL,
            recipe_id INTEGER NOT NULL,
            operation TEXT NOT NULL
                CHECK(operation IN ('replace', 'delete')),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (score_revision_id, recipe_id),
            FOREIGN KEY (score_revision_id)
                REFERENCES recipe_score_revisions(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_score_recipe_ingredients (
            score_revision_id INTEGER NOT NULL,
            recipe_id INTEGER NOT NULL,
            recipe_ingredient_id INTEGER NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (
                score_revision_id,
                recipe_id,
                recipe_ingredient_id
            ),
            FOREIGN KEY (score_revision_id)
                REFERENCES recipe_score_revisions(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_score_effective_sources (
            recipe_id INTEGER PRIMARY KEY,
            score_revision_id INTEGER NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (recipe_id)
                REFERENCES recipe_catalog(id) ON DELETE CASCADE,
            FOREIGN KEY (score_revision_id)
                REFERENCES recipe_score_revisions(id) ON DELETE RESTRICT
        );

        CREATE TABLE IF NOT EXISTS recipe_score_match_contributors (
            score_revision_id INTEGER NOT NULL,
            recipe_ingredient_id INTEGER NOT NULL,
            recipe_id INTEGER DEFAULT NULL,
            product_id INTEGER NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (
                score_revision_id,
                recipe_ingredient_id,
                product_id
            ),
            FOREIGN KEY (score_revision_id)
                REFERENCES recipe_score_revisions(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS recipe_score_contributor_revisions (
            score_revision_id INTEGER PRIMARY KEY,
            match_count INTEGER NOT NULL CHECK(match_count >= 0),
            contributor_count INTEGER NOT NULL
                CHECK(contributor_count >= 0),
            completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (score_revision_id)
                REFERENCES recipe_score_revisions(id) ON DELETE CASCADE
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
            catalog_lineage_hash TEXT NOT NULL DEFAULT ''
                CHECK(
                    catalog_lineage_hash = ''
                    OR length(catalog_lineage_hash) = 64
                ),
            ontology_schema_hash TEXT DEFAULT NULL,
            ontology_prompt_hash TEXT DEFAULT NULL,
            ontology_model_hash TEXT DEFAULT NULL,
            ontology_corpus_hash TEXT DEFAULT NULL,
            ontology_content_hash TEXT DEFAULT NULL,
            ontology_source_revision INTEGER NOT NULL DEFAULT 1,
            ontology_source_hash TEXT NOT NULL DEFAULT '',
            identity_extension_revision INTEGER NOT NULL DEFAULT 0
                CHECK(identity_extension_revision >= 0),
            identity_extension_hash TEXT NOT NULL
                DEFAULT '0000000000000000000000000000000000000000000000000000000000000000'
                CHECK(length(identity_extension_hash) = 64),
            ontology_source_lineage_hash TEXT NOT NULL DEFAULT ''
                CHECK(
                    ontology_source_lineage_hash = ''
                    OR length(ontology_source_lineage_hash) = 64
                ),
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
            last_outcome_request_epoch INTEGER NOT NULL DEFAULT 0
                CHECK(last_outcome_request_epoch >= 0),
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
        CREATE INDEX IF NOT EXISTS idx_recipe_score_pending_revision
            ON recipe_score_pending_products(
                latest_inventory_revision, updated_at, product_id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_score_pending_recipe_revision
            ON recipe_score_pending_recipes(
                latest_catalog_revision, updated_at, recipe_id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_score_mutations_revision
            ON recipe_score_mutations(domain, revision, owner_type, owner_id);
        CREATE INDEX IF NOT EXISTS idx_recipe_annex_version_status
            ON ingredient_ontology_recipe_identity_annex(
                ontology_version_id, status, recipe_ingredient_id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_score_incremental_recipe
            ON recipe_score_incremental_recipes(
                recipe_id, score_revision_id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_score_operations_recipe
            ON recipe_score_recipe_operations(
                recipe_id, score_revision_id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_score_recipe_ingredients
            ON recipe_score_recipe_ingredients(
                score_revision_id, recipe_id,
                recipe_ingredient_id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_score_effective_revision
            ON recipe_score_effective_sources(
                score_revision_id, recipe_id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_score_contributors_product
            ON recipe_score_match_contributors(
                product_id, score_revision_id, recipe_ingredient_id
            );
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
        CREATE TRIGGER IF NOT EXISTS recipe_score_overlay_clear_terminalize
        AFTER UPDATE OF active_score_overlay_revision_id
        ON recipe_score_state
        WHEN OLD.active_score_overlay_revision_id IS NOT NULL
         AND NEW.active_score_overlay_revision_id IS NULL
        BEGIN
            UPDATE recipe_score_revisions
            SET status = 'failed',
                last_error = 'superseded score overlay',
                completed_at = CURRENT_TIMESTAMP
            WHERE id = OLD.active_score_overlay_revision_id
              AND status = 'building';
        END;
    ");

    $contributorColumns = array_column(
        $db->query("
            PRAGMA table_info(recipe_score_match_contributors)
        ")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    $contributorSql = (string)($db->query("
        SELECT sql
        FROM sqlite_master
        WHERE type = 'table'
          AND name = 'recipe_score_match_contributors'
    ")->fetchColumn() ?: '');
    if (
        !in_array('recipe_id', $contributorColumns, true)
        || str_contains(
            strtolower($contributorSql),
            'references recipe_ingredients'
        )
    ) {
        $db->exec("
            DROP TABLE IF EXISTS recipe_score_match_contributors_next;
            CREATE TABLE recipe_score_match_contributors_next (
                score_revision_id INTEGER NOT NULL,
                recipe_ingredient_id INTEGER NOT NULL,
                recipe_id INTEGER DEFAULT NULL,
                product_id INTEGER NOT NULL,
                created_at DATETIME NOT NULL
                    DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (
                    score_revision_id,
                    recipe_ingredient_id,
                    product_id
                ),
                FOREIGN KEY (score_revision_id)
                    REFERENCES recipe_score_revisions(id)
                        ON DELETE CASCADE
            );
            INSERT INTO recipe_score_match_contributors_next (
                score_revision_id, recipe_ingredient_id,
                recipe_id, product_id, created_at
            )
            SELECT contributor.score_revision_id,
                   contributor.recipe_ingredient_id,
                   ingredient.recipe_id,
                   contributor.product_id,
                   contributor.created_at
            FROM recipe_score_match_contributors contributor
            LEFT JOIN recipe_ingredients ingredient
              ON ingredient.id =
                 contributor.recipe_ingredient_id;
            DROP TABLE recipe_score_match_contributors;
            ALTER TABLE recipe_score_match_contributors_next
                RENAME TO recipe_score_match_contributors;
            CREATE INDEX idx_recipe_score_contributors_product
                ON recipe_score_match_contributors(
                    product_id, score_revision_id,
                    recipe_ingredient_id
                );
            CREATE INDEX idx_recipe_score_contributors_recipe
                ON recipe_score_match_contributors(
                    score_revision_id, recipe_id,
                    recipe_ingredient_id
                );
        ");
    }
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_recipe_score_contributors_recipe
            ON recipe_score_match_contributors(
                score_revision_id, recipe_id,
                recipe_ingredient_id
            )
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
        'ontology_source_lineage_hash' =>
            "TEXT NOT NULL DEFAULT ''",
        'ontology_source_trigger_version' =>
            'INTEGER NOT NULL DEFAULT 0',
        'ontology_source_trigger_hash' =>
            "TEXT NOT NULL DEFAULT ''",
        'active_score_overlay_revision_id' =>
            'INTEGER DEFAULT NULL',
        'active_score_projection_revision_id' =>
            'INTEGER DEFAULT NULL',
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
        'prep_time_seconds' => 'INTEGER DEFAULT NULL',
        'cook_time_seconds' => 'INTEGER DEFAULT NULL',
        'active_time_seconds' => 'INTEGER DEFAULT NULL',
        'inactive_time_seconds' => 'INTEGER DEFAULT NULL',
        'total_time_seconds' => 'INTEGER DEFAULT NULL',
        'difficulty' => 'TEXT DEFAULT NULL',
        'primary_category' => 'TEXT DEFAULT NULL',
        'devices_json' => "TEXT NOT NULL DEFAULT '[]'",
        'optional_devices_json' => "TEXT NOT NULL DEFAULT '[]'",
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
        'content_language' => (
            'TEXT DEFAULT NULL CHECK('
            . 'content_language IS NULL '
            . 'OR length(content_language) BETWEEN 2 AND 20)'
        ),
        'metadata_version' => 'TEXT DEFAULT NULL',
        'metadata_schema_version' => 'TEXT DEFAULT NULL',
        'metadata_failure_version' => 'TEXT DEFAULT NULL',
        'metadata_failure_kind' => 'TEXT DEFAULT NULL',
        'metadata_failure_at' => 'DATETIME DEFAULT NULL',
        'metadata_failure_count' => 'INTEGER NOT NULL DEFAULT 0',
        'metadata_next_probe_at' => 'DATETIME DEFAULT NULL',
        'metadata_failure_schema_version' => 'TEXT DEFAULT NULL',
        'last_applied_request_epoch' =>
            'INTEGER NOT NULL DEFAULT 0',
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
    $overrideColumns = array_column(
        $db->query("PRAGMA table_info(recipe_ingredient_user_overrides)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    foreach ([
        'selected_product_id' => 'INTEGER DEFAULT NULL',
        'selected_product_fingerprint' => (
            'TEXT DEFAULT NULL CHECK('
            . 'selected_product_fingerprint IS NULL '
            . 'OR length(selected_product_fingerprint) = 64)'
        ),
        'decision_action' => (
            "TEXT DEFAULT NULL CHECK(decision_action IS NULL OR "
            . "decision_action IN ('assume_have', "
            . "'select_inventory_product', 'reject_current_match'))"
        ),
        'action_origin' => (
            'TEXT DEFAULT NULL CHECK(action_origin IS NULL '
            . 'OR length(action_origin) BETWEEN 1 AND 80)'
        ),
        'observed_inventory_revision' => 'INTEGER DEFAULT NULL',
        'observed_catalog_revision' => 'INTEGER DEFAULT NULL',
    ] as $column => $definition) {
        if (in_array($column, $overrideColumns, true)) {
            continue;
        }
        try {
            $db->exec("
                ALTER TABLE recipe_ingredient_user_overrides
                ADD COLUMN {$column} {$definition}
            ");
        } catch (PDOException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) {
                throw $e;
            }
        }
    }
    $feedbackColumns = array_column(
        $db->query("PRAGMA table_info(recipe_ingredient_feedback_events)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    foreach ([
        'decision_action' => (
            "TEXT DEFAULT NULL CHECK(decision_action IS NULL OR "
            . "decision_action IN ('assume_have', "
            . "'select_inventory_product', 'reject_current_match'))"
        ),
        'action_origin' => (
            'TEXT DEFAULT NULL CHECK(action_origin IS NULL '
            . 'OR length(action_origin) BETWEEN 1 AND 80)'
        ),
        'source_fingerprint_v2' => (
            'TEXT DEFAULT NULL CHECK(source_fingerprint_v2 IS NULL '
            . 'OR length(source_fingerprint_v2) = 64)'
        ),
        'source_owner_fingerprint' => (
            'TEXT DEFAULT NULL CHECK(source_owner_fingerprint IS NULL '
            . 'OR length(source_owner_fingerprint) = 64)'
        ),
        'target_owner_fingerprint' => (
            'TEXT DEFAULT NULL CHECK(target_owner_fingerprint IS NULL '
            . 'OR length(target_owner_fingerprint) = 64)'
        ),
        'observed_inventory_revision' => 'INTEGER DEFAULT NULL',
        'observed_catalog_revision' => 'INTEGER DEFAULT NULL',
        'supersedes_event_id' => 'INTEGER DEFAULT NULL',
    ] as $column => $definition) {
        if (in_array($column, $feedbackColumns, true)) {
            continue;
        }
        try {
            $db->exec("
                ALTER TABLE recipe_ingredient_feedback_events
                ADD COLUMN {$column} {$definition}
            ");
        } catch (PDOException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) {
                throw $e;
            }
        }
    }
    foreach ([
        'lease_generation' => 'INTEGER NOT NULL DEFAULT 0',
        'lease_expires_at' => 'DATETIME DEFAULT NULL',
    ] as $column => $definition) {
        try {
            $db->exec("
                ALTER TABLE recipe_ingredient_proposal_outbox
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
    $jobColumns = array_column(
        $db->query("PRAGMA table_info(recipe_jobs)")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    foreach ([
        'priority' => 'INTEGER NOT NULL DEFAULT 0',
        'request_epoch' => 'INTEGER NOT NULL DEFAULT 0',
        'request_generation' => 'INTEGER NOT NULL DEFAULT 1',
        'request_hash' => "TEXT NOT NULL DEFAULT ''",
        'lease_token' => 'TEXT DEFAULT NULL',
        'lease_generation' => 'INTEGER NOT NULL DEFAULT 0',
        'lease_expires_at' => 'DATETIME DEFAULT NULL',
    ] as $column => $definition) {
        if (in_array($column, $jobColumns, true)) {
            continue;
        }
        try {
            $db->exec("
                ALTER TABLE recipe_jobs
                ADD COLUMN {$column} {$definition}
            ");
        } catch (PDOException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) {
                throw $e;
            }
        }
    }
    $db->exec("
        CREATE TABLE IF NOT EXISTS recipe_job_request_epoch (
            id INTEGER PRIMARY KEY CHECK(id = 1),
            next_epoch INTEGER NOT NULL DEFAULT 1
                CHECK(next_epoch > 0)
        );
        INSERT OR IGNORE INTO recipe_job_request_epoch (id, next_epoch)
        VALUES (
            1,
            COALESCE(
                (SELECT MAX(request_epoch) + 1 FROM recipe_jobs),
                1
            )
        );
        UPDATE recipe_jobs
        SET request_epoch = id
        WHERE request_epoch <= 0;
        UPDATE recipe_job_request_epoch
        SET next_epoch = MAX(
            next_epoch,
            COALESCE(
                (SELECT MAX(request_epoch) + 1 FROM recipe_jobs),
                1
            )
        )
        WHERE id = 1;
        UPDATE recipe_jobs
        SET status = 'retry',
            next_retry_at = CURRENT_TIMESTAMP,
            last_error = 'legacy lease reclaimed during migration',
            lease_token = NULL,
            lease_expires_at = NULL,
            started_at = NULL,
            finished_at = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE status = 'in_progress'
          AND (
              lease_token IS NULL
              OR TRIM(lease_token) = ''
              OR lease_expires_at IS NULL
          );
    ");
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_recipe_jobs_lease
            ON recipe_jobs(status, lease_expires_at, id)
    ");
    $connectorStateColumns = array_column(
        $db->query("PRAGMA table_info(recipe_connector_state)")
            ->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    if (!in_array(
        'last_outcome_request_epoch',
        $connectorStateColumns,
        true
    )) {
        try {
            $db->exec("
                ALTER TABLE recipe_connector_state
                ADD COLUMN last_outcome_request_epoch
                    INTEGER NOT NULL DEFAULT 0
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
    $jobIndexSql = (string)($db->query("
        SELECT sql FROM sqlite_master
        WHERE type = 'index' AND name = 'idx_recipe_jobs_ready'
    ")->fetchColumn() ?: '');
    if (
        $jobIndexSql === ''
        || !str_contains(strtolower($jobIndexSql), 'priority')
        || !str_contains(strtolower($jobIndexSql), 'lease_expires_at')
    ) {
        $db->exec("
            DROP INDEX IF EXISTS idx_recipe_jobs_ready;
            CREATE INDEX idx_recipe_jobs_ready
                ON recipe_jobs(
                    status, priority DESC, next_retry_at,
                    lease_expires_at, created_at
                );
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
        'catalog_lineage_hash' => "TEXT NOT NULL DEFAULT ''",
        'ontology_source_lineage_hash' =>
            "TEXT NOT NULL DEFAULT ''",
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
    $db->exec("
        INSERT OR IGNORE INTO recipe_score_work_state (id)
        VALUES (1)
    ");
    $projectionState = $db->query("
        SELECT active_score_revision_id,
               active_score_projection_revision_id
        FROM recipe_score_state
        WHERE id = 1
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $projectionActiveId = (int)(
        $projectionState['active_score_revision_id'] ?? 0
    );
    if (
        $projectionActiveId > 0
        && (int)(
            $projectionState[
                'active_score_projection_revision_id'
            ] ?? 0
        ) === 0
    ) {
        $projectionRevision = $db->prepare("
            SELECT recipe_count
            FROM recipe_score_revisions
            WHERE id = ? AND status = 'ready'
        ");
        $projectionRevision->execute([$projectionActiveId]);
        $expectedProjectionCount =
            $projectionRevision->fetchColumn();
        if ($expectedProjectionCount !== false) {
            $db->exec("DELETE FROM recipe_score_effective_sources");
            $projectionInsert = $db->prepare("
                INSERT INTO recipe_score_effective_sources (
                    recipe_id, score_revision_id, updated_at
                )
                SELECT recipe_id, ?, CURRENT_TIMESTAMP
                FROM recipe_inventory_scores
                WHERE score_revision_id = ?
                ORDER BY recipe_id
            ");
            $projectionInsert->execute([
                $projectionActiveId,
                $projectionActiveId,
            ]);
            if (
                $projectionInsert->rowCount()
                    === (int)$expectedProjectionCount
            ) {
                $db->prepare("
                    UPDATE recipe_score_state
                    SET active_score_projection_revision_id = ?
                    WHERE id = 1
                      AND active_score_revision_id = ?
                ")->execute([
                    $projectionActiveId,
                    $projectionActiveId,
                ]);
            } else {
                $db->exec(
                    "DELETE FROM recipe_score_effective_sources"
                );
            }
        }
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
        $ownsOntologySourceTransaction =
            !databaseTransactionIsActive($db);
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
            INSERT INTO recipe_score_mutations (
                domain, revision, owner_type, owner_id,
                operation, reason
            )
            SELECT 'source', ontology_source_revision,
                   'product', NEW.id, 'insert', 'products'
            FROM recipe_score_state WHERE id = 1;
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
            INSERT INTO recipe_score_mutations (
                domain, revision, owner_type, owner_id,
                operation, reason
            )
            SELECT 'source', ontology_source_revision,
                   'product', NEW.id, 'update', 'products'
            FROM recipe_score_state WHERE id = 1;
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
            INSERT INTO recipe_score_mutations (
                domain, revision, owner_type, owner_id,
                operation, reason
            )
            SELECT 'source', ontology_source_revision,
                   'product', OLD.id, 'delete', 'products'
            FROM recipe_score_state WHERE id = 1;
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
            INSERT INTO recipe_score_mutations (
                domain, revision, owner_type, owner_id,
                operation, reason
            )
            SELECT 'source', ontology_source_revision,
                   'recipe', NEW.id, 'insert', 'recipe_catalog'
            FROM recipe_score_state WHERE id = 1;
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
            INSERT INTO recipe_score_mutations (
                domain, revision, owner_type, owner_id,
                operation, reason
            )
            SELECT 'source', ontology_source_revision,
                   'recipe', NEW.id, 'update', 'recipe_catalog'
            FROM recipe_score_state WHERE id = 1;
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
            INSERT INTO recipe_score_mutations (
                domain, revision, owner_type, owner_id,
                operation, reason
            )
            SELECT 'source', ontology_source_revision,
                   'recipe', OLD.id, 'delete', 'recipe_catalog'
            FROM recipe_score_state WHERE id = 1;
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
            INSERT INTO recipe_score_mutations (
                domain, revision, owner_type, owner_id,
                operation, reason
            )
            SELECT 'source', ontology_source_revision,
                   'recipe', NEW.recipe_id, 'insert', 'recipe_origins'
            FROM recipe_score_state WHERE id = 1;
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
            INSERT INTO recipe_score_mutations (
                domain, revision, owner_type, owner_id,
                operation, reason
            )
            SELECT 'source', ontology_source_revision,
                   CASE
                       WHEN OLD.recipe_id IS NOT NEW.recipe_id
                       THEN 'global' ELSE 'recipe'
                   END,
                   CASE
                       WHEN OLD.recipe_id IS NOT NEW.recipe_id
                       THEN NULL ELSE NEW.recipe_id
                   END,
                   'update', 'recipe_origins'
            FROM recipe_score_state WHERE id = 1;
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
            INSERT INTO recipe_score_mutations (
                domain, revision, owner_type, owner_id,
                operation, reason
            )
            SELECT 'source', ontology_source_revision,
                   'recipe', OLD.recipe_id, 'delete', 'recipe_origins'
            FROM recipe_score_state WHERE id = 1;
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
            INSERT INTO recipe_score_mutations (
                domain, revision, owner_type, owner_id,
                operation, reason
            )
            SELECT 'source', ontology_source_revision,
                   'recipe', NEW.recipe_id, 'insert',
                   'recipe_ingredients'
            FROM recipe_score_state WHERE id = 1;
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
            INSERT INTO recipe_score_mutations (
                domain, revision, owner_type, owner_id,
                operation, reason
            )
            SELECT 'source', ontology_source_revision,
                   CASE
                       WHEN OLD.recipe_id IS NOT NEW.recipe_id
                       THEN 'global' ELSE 'recipe'
                   END,
                   CASE
                       WHEN OLD.recipe_id IS NOT NEW.recipe_id
                       THEN NULL ELSE NEW.recipe_id
                   END,
                   'update', 'recipe_ingredients'
            FROM recipe_score_state WHERE id = 1;
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
            INSERT INTO recipe_score_mutations (
                domain, revision, owner_type, owner_id,
                operation, reason
            )
            SELECT 'source', ontology_source_revision,
                   'recipe', OLD.recipe_id, 'delete',
                   'recipe_ingredients'
            FROM recipe_score_state WHERE id = 1;
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
            INSERT INTO recipe_score_mutations (
                domain, revision, owner_type, owner_id,
                operation, reason
            )
            SELECT 'source', ontology_source_revision,
                   'recipe', NEW.recipe_id, 'insert',
                   'recipe_source_ingredients'
            FROM recipe_score_state WHERE id = 1;
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
            INSERT INTO recipe_score_mutations (
                domain, revision, owner_type, owner_id,
                operation, reason
            )
            SELECT 'source', ontology_source_revision,
                   CASE
                       WHEN OLD.recipe_id IS NOT NEW.recipe_id
                       THEN 'global' ELSE 'recipe'
                   END,
                   CASE
                       WHEN OLD.recipe_id IS NOT NEW.recipe_id
                       THEN NULL ELSE NEW.recipe_id
                   END,
                   'update', 'recipe_source_ingredients'
            FROM recipe_score_state WHERE id = 1;
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
            INSERT INTO recipe_score_mutations (
                domain, revision, owner_type, owner_id,
                operation, reason
            )
            SELECT 'source', ontology_source_revision,
                   'recipe', OLD.recipe_id, 'delete',
                   'recipe_source_ingredients'
            FROM recipe_score_state WHERE id = 1;
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
                $db->exec("
                    INSERT OR IGNORE INTO recipe_score_mutations (
                        domain, revision, owner_type, owner_id,
                        operation, reason
                    )
                    SELECT 'source', ontology_source_revision,
                           'global', NULL, 'global',
                           'ontology_source_trigger_upgrade'
                    FROM recipe_score_state
                    WHERE id = 1
                ");
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
        CREATE INDEX IF NOT EXISTS idx_recipe_cookidoo_language_visibility
            ON recipe_cookidoo_language_assessments(
                disposition, verdict, recipe_id
            )
    ");
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_recipe_ingredient_feedback_settlement
            ON recipe_ingredient_feedback_events(
                review_state, settle_after, id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_ingredient_feedback_recipe
            ON recipe_ingredient_feedback_events(
                recipe_id, ingredient_key, id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_ingredient_feedback_supersedes
            ON recipe_ingredient_feedback_events(
                supersedes_event_id, id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_ingredient_proposal_outbox_ready
            ON recipe_ingredient_proposal_outbox(
                status, next_attempt_at, id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_feedback_regression_status
            ON recipe_ingredient_feedback_regression_fixtures(
                status, polarity, id
            );
        CREATE INDEX IF NOT EXISTS idx_recipe_planner_commands_recipe
            ON recipe_planner_commands(recipe_id, target_date, id);
        CREATE INDEX IF NOT EXISTS idx_recipe_planner_events_command
            ON recipe_planner_command_events(command_id, id);

        CREATE TRIGGER IF NOT EXISTS
            recipe_ingredient_decision_provenance_immutable
        BEFORE UPDATE OF
            decision_action,
            action_origin,
            source_fingerprint_v2,
            source_owner_fingerprint,
            target_owner_fingerprint,
            observed_inventory_revision,
            observed_catalog_revision,
            supersedes_event_id
        ON recipe_ingredient_feedback_events
        WHEN OLD.decision_action IS NOT NULL
        BEGIN
            SELECT RAISE(
                ABORT,
                'ingredient decision provenance is immutable'
            );
        END;

        CREATE TRIGGER IF NOT EXISTS
            recipe_ingredient_proposal_prompts_immutable_update
        BEFORE UPDATE ON recipe_ingredient_proposal_prompts
        BEGIN
            SELECT RAISE(ABORT, 'proposal prompt artifacts are immutable');
        END;
        CREATE TRIGGER IF NOT EXISTS
            recipe_ingredient_proposal_prompts_immutable_delete
        BEFORE DELETE ON recipe_ingredient_proposal_prompts
        BEGIN
            SELECT RAISE(ABORT, 'proposal prompt artifacts are immutable');
        END;
        CREATE TRIGGER IF NOT EXISTS
            recipe_ingredient_proposal_responses_immutable_update
        BEFORE UPDATE ON recipe_ingredient_proposal_responses
        BEGIN
            SELECT RAISE(ABORT, 'proposal response artifacts are immutable');
        END;
        CREATE TRIGGER IF NOT EXISTS
            recipe_ingredient_proposal_responses_immutable_delete
        BEFORE DELETE ON recipe_ingredient_proposal_responses
        BEGIN
            SELECT RAISE(ABORT, 'proposal response artifacts are immutable');
        END;
        CREATE TRIGGER IF NOT EXISTS
            recipe_ingredient_feedback_fixtures_immutable_update
        BEFORE UPDATE OF
            feedback_event_id,
            case_key,
            polarity,
            source_fingerprint_v2,
            target_owner_fingerprint,
            fixture_json,
            created_at
        ON recipe_ingredient_feedback_regression_fixtures
        BEGIN
            SELECT RAISE(ABORT, 'feedback regression provenance is immutable');
        END;
        CREATE TRIGGER IF NOT EXISTS
            recipe_planner_command_events_immutable_update
        BEFORE UPDATE ON recipe_planner_command_events
        BEGIN
            SELECT RAISE(ABORT, 'planner command events are append-only');
        END;
        CREATE TRIGGER IF NOT EXISTS
            recipe_planner_command_events_immutable_delete
        BEFORE DELETE ON recipe_planner_command_events
        BEGIN
            SELECT RAISE(ABORT, 'planner command events are append-only');
        END
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
        'cookidoo' => 'metadata-v3-operator-enabled',
    ] as $connector => $policyVersion) {
        $connectorSeed->execute([$connector, $policyVersion]);
    }
    $db->exec("
        UPDATE recipe_connector_state
        SET policy_version = 'metadata-v3-operator-enabled',
            updated_at = CURRENT_TIMESTAMP
        WHERE connector = 'cookidoo'
          AND policy_version IN (
              'metadata-v1',
              'metadata-v2',
              'metadata-v2-detail-disabled',
              'metadata-v2-allowlisted-opt-in'
          )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS recipe_schema_migrations (
            migration_key TEXT PRIMARY KEY,
            schema_version INTEGER NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    recipeSchemaRunOnce(
        $db,
        'cookidoo_discovery_policy_v3_v1',
        static function () use ($db): void {
            $db->exec("
                UPDATE recipe_jobs
                SET payload_json = json_set(
                        payload_json,
                        '$._detail_policy_version',
                        'metadata-v3-operator-enabled'
                    ),
                    request_generation = request_generation + 1,
                    request_hash = '',
                    status = CASE
                        WHEN status = 'in_progress' THEN 'retry'
                        ELSE status
                    END,
                    next_retry_at = CASE
                        WHEN status = 'in_progress'
                        THEN CURRENT_TIMESTAMP
                        ELSE next_retry_at
                    END,
                    last_error = CASE
                        WHEN status = 'in_progress'
                        THEN 'policy migration reclaimed active discovery job'
                        ELSE last_error
                    END,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    started_at = CASE
                        WHEN status = 'in_progress' THEN NULL
                        ELSE started_at
                    END,
                    finished_at = CASE
                        WHEN status = 'in_progress' THEN NULL
                        ELSE finished_at
                    END,
                    updated_at = CASE
                        WHEN status = 'in_progress'
                        THEN CURRENT_TIMESTAMP
                        ELSE updated_at
                    END
                WHERE connector = 'cookidoo'
                  AND job_type = 'connector_discovery'
                  AND CASE
                      WHEN json_valid(payload_json) = 0 THEN 0
                      WHEN json_extract(
                          payload_json,
                          '$._detail_policy_version'
                      ) IN (
                          'metadata-v2-detail-disabled',
                          'metadata-v2-allowlisted-opt-in'
                      ) THEN 1
                      WHEN json_type(
                          payload_json,
                          '$._detail_policy_version'
                      ) IS NULL
                      AND json_type(payload_json, '$.locale') = 'text'
                      AND (
                          json_type(payload_json, '$.query') = 'text'
                          OR json_type(
                              payload_json,
                              '$.ingredients'
                          ) = 'array'
                      ) THEN 1
                      ELSE 0
                  END = 1
            ");
        }
    );
    recipeSchemaRunOnce(
        $db,
        'recipe_stale_while_revalidate_v1',
        static function () use ($db): void {
            $db->exec("
            UPDATE recipe_score_state
            SET cursor_revision = cursor_revision + 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = 1
            ");
        }
    );

    ingredientOntologyV3SchemaMigrate($db);
}
