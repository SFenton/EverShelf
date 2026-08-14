<?php
declare(strict_types=1);

const INGREDIENT_ONTOLOGY_V3_SCHEMA_VERSION = 'ingredient-ontology-v3.17';
const INGREDIENT_ONTOLOGY_V3_PROMPT_SCHEMA_VERSION =
    'ingredient_topology_benchmark_v3';
const INGREDIENT_ONTOLOGY_V3_DEFAULT_MODEL = 'gemini-3.5-flash';
const INGREDIENT_ONTOLOGY_V3_SCORING_MODEL = 'faceted-ontology-v3';
const INGREDIENT_ONTOLOGY_V3_SCORING_VERSION =
    'ingredient-ontology-v3-score-1';
const INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_SHA256 =
    '82d8a8b7e24eb28b5b23b18de281f7d1a9326e1e6fb188fc551f5695549190cf';
const INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_IDS_SHA256 =
    'e3ea96007f940d963d14f4b87581bdfd7ff4e69ee2fede3f2bed531020cfeb78';
const INGREDIENT_ONTOLOGY_V3_MATCHER_GOLD_CASE_COUNT = 60;

function ingredientOntologyV3MatcherGoldCaseIds(): array {
    return [
        'salt_exact',
        'water_exact',
        'pepper_exact',
        'olive_oil_exact',
        'olive_not_oil',
        'vegetable_oil_not_olive',
        'vegetable_stock_exact',
        'vegetables_not_stock',
        'rice_noodles_exact',
        'rice_not_rice_noodles',
        'egg_not_egg_noodles',
        'ramen_noodles_exact',
        'ramen_noodles_not_soup',
        'ramen_soup_exact',
        'almond_milk_exact',
        'almond_not_milk',
        'almond_flour_exact',
        'almond_milk_not_flour',
        'vanilla_pod_exact',
        'coffee_pod_not_vanilla',
        'cardamom_pod_exact',
        'coffee_pod_not_cardamom',
        'garlic_powder_exact',
        'garlic_powder_not_fresh',
        'fresh_garlic_not_powder',
        'generic_garlic_unknown_powder',
        'onion_powder_not_fresh',
        'ginger_powder_not_fresh',
        'fresh_jalapeno_exact',
        'pickled_jalapeno_exact',
        'fresh_not_pickled_jalapeno',
        'brown_sugar_exact',
        'brown_not_white_sugar',
        'brown_not_caster_sugar',
        'brown_not_powdered_sugar',
        'white_not_powdered_sugar',
        'generic_sugar_unknown_brown',
        'chicken_thigh_exact',
        'thigh_not_breast',
        'bone_in_not_boneless',
        'skin_on_not_skinless',
        'generic_chicken_unknown_breast',
        'mozzarella_sliced_exact',
        'mozzarella_slice_not_block',
        'mozzarella_shredded_not_block',
        'pepper_jack_slice_not_block',
        'pepper_jack_not_mozzarella',
        'apple_cider_vinegar_exact',
        'apple_not_rice_vinegar',
        'balsamic_not_white_wine_vinegar',
        'prepared_not_ingredient',
        'composite_not_component',
        'flour_not_wheatless_almond',
        'all_purpose_exact',
        'all_purpose_not_cake',
        'cake_not_bread_flour',
        'coffee_pod_exact',
        'oil_not_water',
        'salt_not_salt_pork',
        'pepper_not_pepper_jack',
    ];
}

function ingredientOntologyV3SetRequirementPruneGuard(
    PDO $db,
    bool $enabled
): void {
    if (!method_exists($db, 'sqliteCreateFunction')) {
        throw new RuntimeException(
            'SQLite prune guard functions are unavailable'
        );
    }
    $key = 'ingredient_ontology_prune_guard:' . spl_object_id($db);
    $GLOBALS[$key] = $enabled ? 1 : 0;
    $db->sqliteCreateFunction(
        'ingredient_ontology_prune_guard',
        static fn(): int => (int)($GLOBALS[$key] ?? 0),
        0
    );
}

function ingredientOntologyV3SetReadyMutationGuard(
        PDO $db,
        bool $enabled
    ): void {
        if (!method_exists($db, 'sqliteCreateFunction')) {
            throw new RuntimeException(
                'SQLite ready mutation guard functions are unavailable'
            );
        }
        $key = 'ingredient_ontology_ready_mutation_guard:'
            . spl_object_id($db);
        $GLOBALS[$key] = $enabled ? 1 : 0;
        $db->sqliteCreateFunction(
            'ingredient_ontology_ready_mutation_guard',
            static fn(): int => (int)($GLOBALS[$key] ?? 0),
            0
        );
    }

function ingredientOntologyV3SetPublicationGuard(
    PDO $db,
    bool $enabled
): void {
    if (!method_exists($db, 'sqliteCreateFunction')) {
        throw new RuntimeException(
            'SQLite publication guard functions are unavailable'
        );
    }
    $key = 'ingredient_ontology_publication_guard:'
        . spl_object_id($db);
    $GLOBALS[$key] = $enabled ? 1 : 0;
    $db->sqliteCreateFunction(
        'ingredient_ontology_publication_guard',
        static fn(): int => (int)($GLOBALS[$key] ?? 0),
        0
    );
}

function ingredientOntologyV3RequirementPruneGuardEnabled(
    PDO $db
): bool {
    return !empty($GLOBALS[
        'ingredient_ontology_prune_guard:' . spl_object_id($db)
    ]);
}

function ingredientOntologyV3ReadyMutationGuardEnabled(
    PDO $db
): bool {
    return !empty($GLOBALS[
        'ingredient_ontology_ready_mutation_guard:'
            . spl_object_id($db)
    ]);
}

function ingredientOntologyV3PublicationGuardEnabled(
    PDO $db
): bool {
    return !empty($GLOBALS[
        'ingredient_ontology_publication_guard:'
            . spl_object_id($db)
    ]);
}

function ingredientOntologyV3WithReadyMutationGuard(
    PDO $db,
    callable $callback
): mixed {
    $wasEnabled = ingredientOntologyV3ReadyMutationGuardEnabled($db);
    ingredientOntologyV3SetReadyMutationGuard($db, true);
    try {
        return $callback();
    } finally {
        ingredientOntologyV3SetReadyMutationGuard($db, $wasEnabled);
    }
}

function ingredientOntologyV3WithPublicationGuard(
    PDO $db,
    callable $callback
): mixed {
    $wasEnabled = ingredientOntologyV3PublicationGuardEnabled($db);
    ingredientOntologyV3SetPublicationGuard($db, true);
    try {
        return $callback();
    } finally {
        ingredientOntologyV3SetPublicationGuard($db, $wasEnabled);
    }
}

function ingredientOntologyV3WithRequirementPruneGuard(
    PDO $db,
    callable $callback
): mixed {
    $wasEnabled = ingredientOntologyV3RequirementPruneGuardEnabled($db);
    ingredientOntologyV3SetRequirementPruneGuard($db, true);
    try {
        return $callback();
    } finally {
        ingredientOntologyV3SetRequirementPruneGuard($db, $wasEnabled);
    }
}

function ingredientOntologyV3TriggerSetCurrent(
    PDO $db,
    string $stateKey,
    string $version,
    array $triggerNames
): bool {
    $stored = $db->prepare("
        SELECT state_value
        FROM ingredient_ontology_schema_state
        WHERE state_key = ?
    ");
    $stored->execute([$stateKey]);
    if ((string)($stored->fetchColumn() ?: '') !== $version) {
        return false;
    }
    if ($triggerNames === []) {
        return true;
    }
    $placeholders = implode(
        ', ',
        array_fill(0, count($triggerNames), '?')
    );
    $count = $db->prepare("
        SELECT COUNT(*)
        FROM sqlite_master
        WHERE type = 'trigger'
          AND name IN ({$placeholders})
    ");
    $count->execute($triggerNames);
    return (int)$count->fetchColumn() === count($triggerNames);
}

function ingredientOntologyV3MigrateTriggerSet(
    PDO $db,
    string $stateKey,
    string $version,
    array $triggerNames,
    callable $migration
): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS ingredient_ontology_schema_state (
            state_key TEXT PRIMARY KEY,
            state_value TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    if (
        ingredientOntologyV3TriggerSetCurrent(
            $db,
            $stateKey,
            $version,
            $triggerNames
        )
    ) {
        return;
    }
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->exec('BEGIN IMMEDIATE');
    }
    try {
        if (
            !ingredientOntologyV3TriggerSetCurrent(
                $db,
                $stateKey,
                $version,
                $triggerNames
            )
        ) {
            $migration($db);
            $placeholders = implode(
                ', ',
                array_fill(0, count($triggerNames), '?')
            );
            $count = $db->prepare("
                SELECT COUNT(*)
                FROM sqlite_master
                WHERE type = 'trigger'
                  AND name IN ({$placeholders})
            ");
            $count->execute($triggerNames);
            if ((int)$count->fetchColumn() !== count($triggerNames)) {
                throw new RuntimeException(
                    "Ontology trigger migration {$stateKey} is incomplete"
                );
            }
            $state = $db->prepare("
                INSERT INTO ingredient_ontology_schema_state (
                    state_key, state_value, updated_at
                )
                VALUES (?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(state_key) DO UPDATE SET
                    state_value = excluded.state_value,
                    updated_at = CURRENT_TIMESTAMP
            ");
            $state->execute([$stateKey, $version]);
        }
        if ($ownsTransaction) {
            $db->exec('COMMIT');
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
        }
        throw $e;
    }
}

function ingredientOntologyV3MigrateReadyGuards(PDO $db): void {
        $tables = [
            'ingredient_ontology_entities',
            'ingredient_ontology_labels',
            'ingredient_ontology_relations',
            'ingredient_ontology_facets',
            'ingredient_ontology_facet_values',
            'ingredient_ontology_entity_defaults',
            'ingredient_ontology_label_attributes',
            'ingredient_ontology_mappings',
            'ingredient_ontology_mapping_attributes',
            'ingredient_ontology_mapping_relations',
            'ingredient_ontology_curated_product_assertions',
            'ingredient_ontology_curated_provider_reviews',
            'ingredient_ontology_curated_provider_conflict_reviews',
            'ingredient_ontology_change_sets',
            'ingredient_ontology_provider_terms',
            'ingredient_ontology_provider_observations',
            'ingredient_ontology_resolution_manifests',
            'ingredient_ontology_evidence_sources',
            'ingredient_ontology_entity_facet_policies',
            'ingredient_ontology_label_context_policies',
            'ingredient_ontology_recipe_cohorts',
            'ingredient_ontology_primary_edge_reviews',
            'ingredient_ontology_disposition_scopes',
            'ingredient_ontology_terminal_dispositions',
            'ingredient_ontology_review_imports',
            'ingredient_ontology_review_import_rows',
            'ingredient_ontology_mapping_assertion_history',
        ];
        $triggerNames = [];
        foreach ($tables as $table) {
            if (!ingredientOntologyV3TableExists($db, $table)) {
                continue;
            }
            foreach (['insert', 'update', 'delete'] as $operation) {
                $triggerNames[] = $table . '_ready_' . $operation;
            }
        }
        array_push(
            $triggerNames,
            'ingredient_ontology_versions_ready_update',
            'ingredient_ontology_versions_ready_delete',
            'ingredient_ontology_versions_ready_publish',
            'ingredient_ontology_proposals_ready_insert',
            'ingredient_ontology_proposals_ready_update',
            'ingredient_ontology_proposals_ready_delete',
            'ingredient_ontology_change_events_ready_insert',
            'ingredient_ontology_change_events_ready_update',
            'ingredient_ontology_change_events_ready_delete'
        );
        ingredientOntologyV3MigrateTriggerSet(
            $db,
            'ready_guard_trigger_version',
            'ready-guards-v3.17.1',
            $triggerNames,
            static function (PDO $db) use ($tables): void {
        foreach ($tables as $table) {
            if (!ingredientOntologyV3TableExists($db, $table)) {
                continue;
            }
            foreach (['insert', 'update', 'delete'] as $operation) {
                $trigger = $table . '_ready_' . $operation;
                $readyPredicate = $operation === 'update'
                    ? "version.id IN (
                           OLD.ontology_version_id,
                           NEW.ontology_version_id
                       )"
                    : (
                        $operation === 'insert'
                            ? 'version.id = NEW.ontology_version_id'
                            : 'version.id = OLD.ontology_version_id'
                    );
                $db->exec("DROP TRIGGER IF EXISTS {$trigger}");
                $db->exec("
                    CREATE TRIGGER {$trigger}
                    BEFORE " . strtoupper($operation) . " ON {$table}
                    WHEN ingredient_ontology_ready_mutation_guard() <> 1
                     AND EXISTS (
                         SELECT 1
                         FROM ingredient_ontology_versions version
                         WHERE {$readyPredicate}
                           AND version.status = 'ready'
                     )
                    BEGIN
                        SELECT RAISE(
                            ABORT,
                            'ready ontology version content is immutable'
                        );
                    END
                ");
            }
        }
        $db->exec("
            DROP TRIGGER IF EXISTS ingredient_ontology_versions_ready_update;
            DROP TRIGGER IF EXISTS ingredient_ontology_versions_ready_delete;
            DROP TRIGGER IF EXISTS ingredient_ontology_versions_ready_publish;
            CREATE TRIGGER ingredient_ontology_versions_ready_publish
            BEFORE UPDATE ON ingredient_ontology_versions
            WHEN NEW.status = 'ready'
             AND OLD.status <> 'ready'
             AND ingredient_ontology_publication_guard() <> 1
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'ontology publication requires an explicit guard'
                );
            END;
            CREATE TRIGGER ingredient_ontology_versions_ready_update
            BEFORE UPDATE ON ingredient_ontology_versions
            WHEN OLD.status = 'ready'
             AND ingredient_ontology_ready_mutation_guard() <> 1
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'ready ontology version seal is immutable'
                );
            END;
            CREATE TRIGGER ingredient_ontology_versions_ready_delete
            BEFORE DELETE ON ingredient_ontology_versions
            WHEN OLD.status = 'ready'
             AND ingredient_ontology_ready_mutation_guard() <> 1
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'ready ontology versions require guarded prune'
                );
            END;
            DROP TRIGGER IF EXISTS
                ingredient_ontology_proposals_ready_insert;
            DROP TRIGGER IF EXISTS
                ingredient_ontology_proposals_ready_update;
            DROP TRIGGER IF EXISTS
                ingredient_ontology_proposals_ready_delete;
            CREATE TRIGGER ingredient_ontology_proposals_ready_insert
            BEFORE INSERT ON ingredient_ontology_proposals
            WHEN ingredient_ontology_ready_mutation_guard() <> 1
             AND EXISTS (
                 SELECT 1
                 FROM ingredient_ontology_change_sets change_set
                 JOIN ingredient_ontology_versions version
                   ON version.id = change_set.ontology_version_id
                 WHERE change_set.id = NEW.change_set_id
                   AND version.status = 'ready'
             )
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'ready ontology version proposals are immutable'
                );
            END;
            CREATE TRIGGER ingredient_ontology_proposals_ready_update
            BEFORE UPDATE ON ingredient_ontology_proposals
            WHEN ingredient_ontology_ready_mutation_guard() <> 1
             AND EXISTS (
                 SELECT 1
                 FROM ingredient_ontology_change_sets change_set
                 JOIN ingredient_ontology_versions version
                   ON version.id = change_set.ontology_version_id
                 WHERE change_set.id IN (
                     OLD.change_set_id,
                     NEW.change_set_id
                 )
                   AND version.status = 'ready'
             )
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'ready ontology version proposals are immutable'
                );
            END;
            CREATE TRIGGER ingredient_ontology_proposals_ready_delete
            BEFORE DELETE ON ingredient_ontology_proposals
            WHEN ingredient_ontology_ready_mutation_guard() <> 1
             AND EXISTS (
                 SELECT 1
                 FROM ingredient_ontology_change_sets change_set
                 JOIN ingredient_ontology_versions version
                   ON version.id = change_set.ontology_version_id
                 WHERE change_set.id = OLD.change_set_id
                   AND version.status = 'ready'
             )
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'ready ontology version proposals are immutable'
                );
            END;
            DROP TRIGGER IF EXISTS
                ingredient_ontology_change_events_ready_insert;
            DROP TRIGGER IF EXISTS
                ingredient_ontology_change_events_ready_update;
            DROP TRIGGER IF EXISTS
                ingredient_ontology_change_events_ready_delete;
            CREATE TRIGGER ingredient_ontology_change_events_ready_insert
            BEFORE INSERT ON ingredient_ontology_change_events
            WHEN ingredient_ontology_ready_mutation_guard() <> 1
             AND EXISTS (
                 SELECT 1
                 FROM ingredient_ontology_change_sets change_set
                 JOIN ingredient_ontology_versions version
                   ON version.id = change_set.ontology_version_id
                 WHERE change_set.id = NEW.change_set_id
                   AND version.status = 'ready'
             )
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'ready ontology version change events are immutable'
                );
            END;
            CREATE TRIGGER ingredient_ontology_change_events_ready_update
            BEFORE UPDATE ON ingredient_ontology_change_events
            WHEN ingredient_ontology_ready_mutation_guard() <> 1
             AND EXISTS (
                 SELECT 1
                 FROM ingredient_ontology_change_sets change_set
                 JOIN ingredient_ontology_versions version
                   ON version.id = change_set.ontology_version_id
                 WHERE change_set.id IN (
                     OLD.change_set_id,
                     NEW.change_set_id
                 )
                   AND version.status = 'ready'
             )
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'ready ontology version change events are immutable'
                );
            END;
            CREATE TRIGGER ingredient_ontology_change_events_ready_delete
            BEFORE DELETE ON ingredient_ontology_change_events
            WHEN ingredient_ontology_ready_mutation_guard() <> 1
             AND EXISTS (
                 SELECT 1
                 FROM ingredient_ontology_change_sets change_set
                 JOIN ingredient_ontology_versions version
                   ON version.id = change_set.ontology_version_id
                 WHERE change_set.id = OLD.change_set_id
                   AND version.status = 'ready'
             )
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'ready ontology version change events are immutable'
                );
            END
        ");
            }
        );
    }

function ingredientOntologyV3AddColumn(
    PDO $db,
    string $table,
    string $column,
    string $definition
): void {
    $columns = array_column(
        $db->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    if (in_array($column, $columns, true)) {
        return;
    }
    try {
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    } catch (PDOException $e) {
        if (!str_contains(strtolower($e->getMessage()), 'duplicate column')) {
            throw $e;
        }
    }
}

function ingredientOntologyV3MigrateImmutableTriggers(PDO $db): void {
    $triggerVersion = 'requirement-immutability-v3.6';
    $db->exec("
        CREATE TABLE IF NOT EXISTS ingredient_ontology_schema_state (
            state_key TEXT PRIMARY KEY,
            state_value TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->exec('BEGIN IMMEDIATE');
    }
    try {
        $current = $db->prepare("
            SELECT state_value
            FROM ingredient_ontology_schema_state
            WHERE state_key = 'immutable_trigger_version'
        ");
        $current->execute();
        $storedVersion = (string)($current->fetchColumn() ?: '');
        $triggerCount = (int)$db->query("
            SELECT COUNT(*)
            FROM sqlite_master
            WHERE type = 'trigger'
              AND name IN (
                  'ingredient_ontology_requirement_revision_immutable_update',
                  'ingredient_ontology_requirement_revision_immutable_delete',
                  'ingredient_ontology_requirements_immutable_insert',
                  'ingredient_ontology_requirements_immutable_update',
                  'ingredient_ontology_requirements_immutable_delete',
                  'ingredient_ontology_requirement_members_immutable_insert',
                  'ingredient_ontology_requirement_members_immutable_update',
                  'ingredient_ontology_requirement_members_immutable_delete',
                  'ingredient_ontology_requirement_recipe_states_immutable_insert',
                  'ingredient_ontology_requirement_recipe_states_immutable_update',
                  'ingredient_ontology_requirement_recipe_states_immutable_delete',
                  'ingredient_ontology_shadow_requirement_matches_immutable_insert',
                  'ingredient_ontology_shadow_requirement_matches_immutable_update',
                  'ingredient_ontology_shadow_requirement_matches_immutable_delete'
              )
        ")->fetchColumn();
        $revisionTriggerSql = (string)($db->query("
            SELECT sql FROM sqlite_master
            WHERE type = 'trigger'
              AND name =
                'ingredient_ontology_requirement_revision_immutable_update'
        ")->fetchColumn() ?: '');
        $triggersCurrent = $storedVersion === $triggerVersion
            && $triggerCount === 14
            && str_contains(
                $revisionTriggerSql,
                'ingredient_ontology_prune_guard'
            );
        if (!$triggersCurrent) {
            $db->exec("
                DROP TRIGGER IF EXISTS
                    ingredient_ontology_requirement_revision_immutable_update;
                DROP TRIGGER IF EXISTS
                    ingredient_ontology_requirement_revision_immutable_delete;
                DROP TRIGGER IF EXISTS
                    ingredient_ontology_requirements_immutable_update;
                DROP TRIGGER IF EXISTS
                    ingredient_ontology_requirements_immutable_delete;
                DROP TRIGGER IF EXISTS
                    ingredient_ontology_requirement_members_immutable_update;
                DROP TRIGGER IF EXISTS
                    ingredient_ontology_requirement_members_immutable_delete;
                DROP TRIGGER IF EXISTS
                    ingredient_ontology_requirement_recipe_states_immutable_update;
                DROP TRIGGER IF EXISTS
                    ingredient_ontology_requirement_recipe_states_immutable_delete;

                CREATE TRIGGER
                    ingredient_ontology_requirement_revision_immutable_update
                BEFORE UPDATE ON ingredient_ontology_requirement_revisions
                WHEN OLD.status = 'ready'
                 AND ingredient_ontology_ready_mutation_guard() <> 1
                 AND ingredient_ontology_prune_guard() <> 1
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'ready requirement revisions are immutable'
                    );
                END;

                CREATE TRIGGER
                    ingredient_ontology_requirement_revision_immutable_delete
                BEFORE DELETE ON ingredient_ontology_requirement_revisions
                WHEN OLD.status = 'ready'
                 AND ingredient_ontology_ready_mutation_guard() <> 1
                 AND ingredient_ontology_prune_guard() <> 1
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'ready requirement revisions require guarded whole-revision prune'
                    );
                END;

                CREATE TRIGGER IF NOT EXISTS
                    ingredient_ontology_requirements_immutable_insert
                BEFORE INSERT ON ingredient_ontology_recipe_requirements
                WHEN EXISTS (
                    SELECT 1
                    FROM ingredient_ontology_requirement_revisions r
                    WHERE r.id = NEW.requirement_revision_id
                      AND r.status = 'ready'
                )
                 AND ingredient_ontology_ready_mutation_guard() <> 1
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'cannot insert into a ready requirement revision'
                    );
                END;

                CREATE TRIGGER
                    ingredient_ontology_requirements_immutable_update
                BEFORE UPDATE ON ingredient_ontology_recipe_requirements
                WHEN EXISTS (
                    SELECT 1
                    FROM ingredient_ontology_requirement_revisions r
                    WHERE r.id IN (
                        OLD.requirement_revision_id,
                        NEW.requirement_revision_id
                    )
                      AND r.status = 'ready'
                )
                 AND ingredient_ontology_ready_mutation_guard() <> 1
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'ready recipe requirements are immutable'
                    );
                END;

                CREATE TRIGGER
                    ingredient_ontology_requirements_immutable_delete
                BEFORE DELETE ON ingredient_ontology_recipe_requirements
                WHEN EXISTS (
                    SELECT 1
                    FROM ingredient_ontology_requirement_revisions r
                    WHERE r.id = OLD.requirement_revision_id
                      AND r.status = 'ready'
                )
                 AND ingredient_ontology_ready_mutation_guard() <> 1
                 AND ingredient_ontology_prune_guard() <> 1
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'ready recipe requirements are immutable'
                    );
                END;

                CREATE TRIGGER IF NOT EXISTS
                    ingredient_ontology_requirement_members_immutable_insert
                BEFORE INSERT ON ingredient_ontology_requirement_members
                WHEN EXISTS (
                    SELECT 1
                    FROM ingredient_ontology_requirement_revisions r
                    WHERE r.id = NEW.requirement_revision_id
                      AND r.status = 'ready'
                )
                 AND ingredient_ontology_ready_mutation_guard() <> 1
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'cannot insert members into a ready revision'
                    );
                END;

                CREATE TRIGGER
                    ingredient_ontology_requirement_members_immutable_update
                BEFORE UPDATE ON ingredient_ontology_requirement_members
                WHEN EXISTS (
                    SELECT 1
                    FROM ingredient_ontology_requirement_revisions r
                    WHERE r.id IN (
                        OLD.requirement_revision_id,
                        NEW.requirement_revision_id
                    )
                      AND r.status = 'ready'
                )
                 AND ingredient_ontology_ready_mutation_guard() <> 1
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'ready requirement members are immutable'
                    );
                END;

                CREATE TRIGGER
                    ingredient_ontology_requirement_members_immutable_delete
                BEFORE DELETE ON ingredient_ontology_requirement_members
                WHEN EXISTS (
                    SELECT 1
                    FROM ingredient_ontology_requirement_revisions r
                    WHERE r.id = OLD.requirement_revision_id
                      AND r.status = 'ready'
                )
                 AND ingredient_ontology_ready_mutation_guard() <> 1
                 AND ingredient_ontology_prune_guard() <> 1
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'ready requirement members are immutable'
                    );
                END;

                CREATE TRIGGER IF NOT EXISTS
                    ingredient_ontology_requirement_recipe_states_immutable_insert
                BEFORE INSERT
                ON ingredient_ontology_requirement_recipe_states
                WHEN EXISTS (
                    SELECT 1
                    FROM ingredient_ontology_requirement_revisions r
                    WHERE r.id = NEW.requirement_revision_id
                      AND r.status = 'ready'
                )
                 AND ingredient_ontology_ready_mutation_guard() <> 1
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'cannot insert recipe states into a ready revision'
                    );
                END;

                CREATE TRIGGER
                    ingredient_ontology_requirement_recipe_states_immutable_update
                BEFORE UPDATE
                ON ingredient_ontology_requirement_recipe_states
                WHEN EXISTS (
                    SELECT 1
                    FROM ingredient_ontology_requirement_revisions r
                    WHERE r.id IN (
                        OLD.requirement_revision_id,
                        NEW.requirement_revision_id
                    )
                      AND r.status = 'ready'
                )
                 AND ingredient_ontology_ready_mutation_guard() <> 1
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'ready requirement recipe states are immutable'
                    );
                END;

                CREATE TRIGGER
                    ingredient_ontology_requirement_recipe_states_immutable_delete
                BEFORE DELETE
                ON ingredient_ontology_requirement_recipe_states
                WHEN EXISTS (
                    SELECT 1
                    FROM ingredient_ontology_requirement_revisions r
                    WHERE r.id = OLD.requirement_revision_id
                      AND r.status = 'ready'
                )
                 AND ingredient_ontology_ready_mutation_guard() <> 1
                 AND ingredient_ontology_prune_guard() <> 1
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'ready requirement recipe states are immutable'
                    );
                END;

                CREATE TRIGGER IF NOT EXISTS
                    ingredient_ontology_shadow_requirement_matches_immutable_insert
                BEFORE INSERT
                ON ingredient_ontology_shadow_requirement_matches
                WHEN EXISTS (
                    SELECT 1 FROM recipe_score_revisions r
                    WHERE r.id = NEW.score_revision_id
                      AND r.status = 'ready'
                )
                 AND ingredient_ontology_ready_mutation_guard() <> 1
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'cannot insert matches into a ready score revision'
                    );
                END;

                CREATE TRIGGER IF NOT EXISTS
                    ingredient_ontology_shadow_requirement_matches_immutable_update
                BEFORE UPDATE
                ON ingredient_ontology_shadow_requirement_matches
                WHEN EXISTS (
                    SELECT 1 FROM recipe_score_revisions r
                    WHERE r.id IN (
                        OLD.score_revision_id,
                        NEW.score_revision_id
                    )
                      AND r.status = 'ready'
                )
                 AND ingredient_ontology_ready_mutation_guard() <> 1
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'ready requirement matches are immutable'
                    );
                END;

                CREATE TRIGGER IF NOT EXISTS
                    ingredient_ontology_shadow_requirement_matches_immutable_delete
                BEFORE DELETE
                ON ingredient_ontology_shadow_requirement_matches
                WHEN EXISTS (
                    SELECT 1 FROM recipe_score_revisions r
                    WHERE r.id = OLD.score_revision_id
                      AND r.status = 'ready'
                )
                 AND ingredient_ontology_ready_mutation_guard() <> 1
                 AND ingredient_ontology_prune_guard() <> 1
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'ready requirement matches are immutable'
                    );
                END
            ");
            $db->prepare("
                INSERT INTO ingredient_ontology_schema_state (
                    state_key, state_value, updated_at
                )
                VALUES ('immutable_trigger_version', ?, CURRENT_TIMESTAMP)
                ON CONFLICT(state_key) DO UPDATE SET
                    state_value = excluded.state_value,
                    updated_at = CURRENT_TIMESTAMP
            ")->execute([$triggerVersion]);
        }
        if ($ownsTransaction) {
            $db->exec('COMMIT');
        }
    } catch (Throwable $e) {
        if ($ownsTransaction) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
        }
        throw $e;
    }
}

function ingredientOntologyV3MigrateMaterializationGuards(PDO $db): void {
    $createChildGuards = static function (
        PDO $db,
        string $table,
        string $revisionColumn,
        string $revisionTable,
        string $label
    ): void {
        foreach (['insert', 'update', 'delete'] as $operation) {
            $trigger = $table . '_ready_' . $operation;
            $revisionPredicate = match ($operation) {
                'insert' => "revision.id = NEW.{$revisionColumn}",
                'delete' => "revision.id = OLD.{$revisionColumn}",
                default => "revision.id IN (
                    OLD.{$revisionColumn}, NEW.{$revisionColumn}
                )",
            };
            $deleteGuard = $operation === 'delete'
                ? ' AND ingredient_ontology_prune_guard() <> 1'
                : '';
            $db->exec("DROP TRIGGER IF EXISTS {$trigger}");
            $db->exec("
                CREATE TRIGGER {$trigger}
                BEFORE " . strtoupper($operation) . " ON {$table}
                WHEN ingredient_ontology_ready_mutation_guard() <> 1
                 {$deleteGuard}
                 AND EXISTS (
                     SELECT 1 FROM {$revisionTable} revision
                     WHERE {$revisionPredicate}
                       AND revision.status = 'ready'
                 )
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'ready {$label} materialization is immutable'
                    );
                END
            ");
        }
    };
    $guardTables = [
        [
            'recipe_inventory_scores',
            'score_revision_id',
            'recipe_score_revisions',
            'recipe score',
        ],
        [
            'ingredient_ontology_shadow_matches',
            'score_revision_id',
            'recipe_score_revisions',
            'ontology match',
        ],
        [
            'ingredient_ontology_shadow_requirement_matches',
            'score_revision_id',
            'recipe_score_revisions',
            'requirement match',
        ],
        [
            'ingredient_ontology_requirement_input_recipes',
            'requirement_revision_id',
            'ingredient_ontology_requirement_revisions',
            'requirement input recipe',
        ],
        [
            'ingredient_ontology_requirement_input_rows',
            'requirement_revision_id',
            'ingredient_ontology_requirement_revisions',
            'requirement input row',
        ],
        [
            'ingredient_ontology_recipe_requirements',
            'requirement_revision_id',
            'ingredient_ontology_requirement_revisions',
            'recipe requirement',
        ],
        [
            'ingredient_ontology_requirement_recipe_states',
            'requirement_revision_id',
            'ingredient_ontology_requirement_revisions',
            'requirement recipe state',
        ],
        [
            'ingredient_ontology_requirement_members',
            'requirement_revision_id',
            'ingredient_ontology_requirement_revisions',
            'requirement member',
        ],
    ];
    $triggerNames = [];
    foreach ($guardTables as [$table]) {
        if (!ingredientOntologyV3TableExists($db, $table)) {
            continue;
        }
        foreach (['insert', 'update', 'delete'] as $operation) {
            $triggerNames[] = $table . '_ready_' . $operation;
        }
    }
    if (ingredientOntologyV3TableExists($db, 'recipe_score_revisions')) {
        array_push(
            $triggerNames,
            'recipe_score_revisions_ready_insert',
            'recipe_score_revisions_ready_update',
            'recipe_score_revisions_ready_delete',
            'recipe_score_revisions_ready_publish'
        );
    }
    if (
        ingredientOntologyV3TableExists(
            $db,
            'ingredient_ontology_requirement_revisions'
        )
    ) {
        array_push(
            $triggerNames,
            'ingredient_ontology_requirement_revisions_ready_insert',
            'ingredient_ontology_requirement_revisions_ready_publish'
        );
    }
    ingredientOntologyV3MigrateTriggerSet(
        $db,
        'materialization_guard_trigger_version',
        'materialization-guards-v3.17.1',
        $triggerNames,
        static function (PDO $db) use (
            $createChildGuards,
            $guardTables
        ): void {
    foreach ($guardTables as [$table, $column, $parent, $label]) {
        if (ingredientOntologyV3TableExists($db, $table)) {
            $createChildGuards(
                $db,
                $table,
                $column,
                $parent,
                $label
            );
        }
    }
    if (ingredientOntologyV3TableExists($db, 'recipe_score_revisions')) {
        $db->exec("
            DROP TRIGGER IF EXISTS recipe_score_revisions_ready_insert;
            DROP TRIGGER IF EXISTS recipe_score_revisions_ready_update;
            DROP TRIGGER IF EXISTS recipe_score_revisions_ready_delete;
            DROP TRIGGER IF EXISTS recipe_score_revisions_ready_publish;
            CREATE TRIGGER recipe_score_revisions_ready_insert
            BEFORE INSERT ON recipe_score_revisions
            WHEN NEW.status = 'ready'
             AND ingredient_ontology_ready_mutation_guard() <> 1
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'ready score revisions require sealed publication'
                );
            END;
            CREATE TRIGGER recipe_score_revisions_ready_update
            BEFORE UPDATE ON recipe_score_revisions
            WHEN OLD.status = 'ready'
             AND ingredient_ontology_ready_mutation_guard() <> 1
             AND ingredient_ontology_prune_guard() <> 1
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'ready score revisions are immutable'
                );
            END;
            CREATE TRIGGER recipe_score_revisions_ready_publish
            BEFORE UPDATE ON recipe_score_revisions
            WHEN NEW.status = 'ready'
             AND OLD.status <> 'ready'
             AND ingredient_ontology_publication_guard() <> 1
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'score publication requires an explicit guard'
                );
            END;
            CREATE TRIGGER recipe_score_revisions_ready_delete
            BEFORE DELETE ON recipe_score_revisions
            WHEN OLD.status = 'ready'
             AND ingredient_ontology_ready_mutation_guard() <> 1
             AND ingredient_ontology_prune_guard() <> 1
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'ready score revisions require guarded prune'
                );
            END
        ");
    }
    if (
        ingredientOntologyV3TableExists(
            $db,
            'ingredient_ontology_requirement_revisions'
        )
    ) {
        $db->exec("
            DROP TRIGGER IF EXISTS
                ingredient_ontology_requirement_revisions_ready_insert;
            DROP TRIGGER IF EXISTS
                ingredient_ontology_requirement_revisions_ready_publish;
            CREATE TRIGGER
                ingredient_ontology_requirement_revisions_ready_insert
            BEFORE INSERT ON ingredient_ontology_requirement_revisions
            WHEN NEW.status = 'ready'
             AND ingredient_ontology_ready_mutation_guard() <> 1
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'ready requirement revisions require sealed publication'
                );
            END;
            CREATE TRIGGER
                ingredient_ontology_requirement_revisions_ready_publish
            BEFORE UPDATE ON ingredient_ontology_requirement_revisions
            WHEN NEW.status = 'ready'
             AND OLD.status <> 'ready'
             AND ingredient_ontology_publication_guard() <> 1
            BEGIN
                SELECT RAISE(
                    ABORT,
                    'requirement publication requires an explicit guard'
                );
            END
        ");
    }
        }
    );
}

function ingredientOntologyV3EnsureParityBaselineForeignKey(
    PDO $db
): void {
    $foreignKeys = $db->query(
        "PRAGMA foreign_key_list(recipe_score_revisions)"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($foreignKeys as $foreignKey) {
        if (
            (string)$foreignKey['from']
                === 'parity_baseline_score_revision_id'
            && (string)$foreignKey['table']
                === 'recipe_score_revisions'
            && strtolower((string)$foreignKey['on_delete'])
                === 'set null'
        ) {
            return;
        }
    }
    if ($db->inTransaction()) {
        throw new RuntimeException(
            'parity baseline foreign-key migration requires no active transaction'
        );
    }
    $tableSql = $db->query("
        SELECT sql FROM sqlite_master
        WHERE type = 'table' AND name = 'recipe_score_revisions'
    ")->fetchColumn();
    if (!is_string($tableSql) || $tableSql === '') {
        throw new RuntimeException(
            'recipe score revision schema is unavailable'
        );
    }
    $replacement = 'recipe_score_revisions_fk_migration';
    $createSql = preg_replace(
        '/^CREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?'
            . '[\"`]?recipe_score_revisions[\"`]?/i',
        'CREATE TABLE ' . $replacement,
        $tableSql,
        1
    );
    if (!is_string($createSql) || $createSql === $tableSql) {
        throw new RuntimeException(
            'recipe score revision migration could not rewrite the table name'
        );
    }
    $withDelete = preg_replace(
        '/(parity_baseline_score_revision_id\\s+INTEGER'
            . '(?:\\s+DEFAULT\\s+NULL)?\\s+REFERENCES\\s+'
            . '[\"`]?recipe_score_revisions[\"`]?\\s*\\(\\s*id\\s*\\))'
            . '(?!\\s+ON\\s+DELETE)/i',
        '$1 ON DELETE SET NULL',
        $createSql,
        1,
        $columnReplacementCount
    );
    if ($columnReplacementCount === 0) {
        $withDelete = preg_replace(
            '/(FOREIGN\\s+KEY\\s*\\(\\s*'
                . 'parity_baseline_score_revision_id\\s*\\)\\s*'
                . 'REFERENCES\\s+[\"`]?recipe_score_revisions[\"`]?'
                . '\\s*\\(\\s*id\\s*\\))(?!\\s+ON\\s+DELETE)/i',
            '$1 ON DELETE SET NULL',
            $createSql,
            1,
            $tableReplacementCount
        );
        if ($tableReplacementCount === 0) {
            throw new RuntimeException(
                'recipe score revision parity foreign key is not rewritable'
            );
        }
    }
    $createSql = (string)$withDelete;
    $columns = $db->query(
        'PRAGMA table_info(recipe_score_revisions)'
    )->fetchAll(PDO::FETCH_ASSOC);
    if (!$columns) {
        throw new RuntimeException(
            'recipe score revision columns are unavailable'
        );
    }
    $columnSignature = static fn(array $rows): array => array_map(
        static fn(array $row): array => [
            'name' => (string)$row['name'],
            'type' => strtoupper(trim((string)$row['type'])),
            'notnull' => (int)$row['notnull'],
            'default' => $row['dflt_value'],
            'pk' => (int)$row['pk'],
        ],
        $rows
    );
    $expectedSignature = $columnSignature($columns);
    $quotedColumns = implode(', ', array_map(
        static fn(array $row): string =>
            '"' . str_replace('"', '""', (string)$row['name']) . '"',
        $columns
    ));
    $objects = $db->query("
        SELECT type, name, sql
        FROM sqlite_master
        WHERE tbl_name = 'recipe_score_revisions'
          AND type IN ('index', 'trigger')
          AND sql IS NOT NULL
        ORDER BY type, name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $rowCount = (int)$db->query(
        'SELECT COUNT(*) FROM recipe_score_revisions'
    )->fetchColumn();
    $sequenceValue = $db->query("
        SELECT seq
        FROM sqlite_sequence
        WHERE name = 'recipe_score_revisions'
    ")->fetchColumn();
    $sequenceValue = $sequenceValue !== false
        ? (int)$sequenceValue
        : null;
    $foreignKeysEnabled = (int)$db->query(
        'PRAGMA foreign_keys'
    )->fetchColumn() === 1;
    $legacyAlterTableEnabled = (int)$db->query(
        'PRAGMA legacy_alter_table'
    )->fetchColumn() === 1;
    $transactionStarted = false;
    try {
        $db->exec('PRAGMA foreign_keys = OFF');
        $db->exec('PRAGMA legacy_alter_table = ON');
        $db->exec('BEGIN IMMEDIATE');
        $transactionStarted = true;
        $db->exec("DROP TABLE IF EXISTS {$replacement}");
        $db->exec($createSql);
        $actualSignature = $columnSignature(
            $db->query(
                'PRAGMA table_info(' . $replacement . ')'
            )->fetchAll(PDO::FETCH_ASSOC)
        );
        if ($actualSignature !== $expectedSignature) {
            throw new RuntimeException(
                'recipe score revision migration changed its column schema'
            );
        }
        $db->exec("
            INSERT INTO {$replacement} ({$quotedColumns})
            SELECT {$quotedColumns}
            FROM recipe_score_revisions
        ");
        if (
            (int)$db->query(
                "SELECT COUNT(*) FROM {$replacement}"
            )->fetchColumn() !== $rowCount
        ) {
            throw new RuntimeException(
                'recipe score revision migration lost rows'
            );
        }
        $db->exec("
            DROP TABLE recipe_score_revisions;
            ALTER TABLE {$replacement}
                RENAME TO recipe_score_revisions
        ");
        if ($sequenceValue !== null) {
            $sequenceRowExists = (int)$db->query("
                SELECT COUNT(*)
                FROM sqlite_sequence
                WHERE name = 'recipe_score_revisions'
            ")->fetchColumn() > 0;
            if ($sequenceRowExists) {
                $db->prepare("
                    UPDATE sqlite_sequence
                    SET seq = MAX(seq, ?)
                    WHERE name = 'recipe_score_revisions'
                ")->execute([$sequenceValue]);
            } else {
                $db->prepare("
                    INSERT INTO sqlite_sequence (name, seq)
                    VALUES ('recipe_score_revisions', ?)
                ")->execute([$sequenceValue]);
            }
        }
        foreach ($objects as $object) {
            $db->exec((string)$object['sql']);
        }
        $db->exec('COMMIT');
        $transactionStarted = false;
    } catch (Throwable $e) {
        if ($transactionStarted || $db->inTransaction()) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
        }
        throw $e;
    } finally {
        $db->exec(
            'PRAGMA legacy_alter_table = '
                . ($legacyAlterTableEnabled ? 'ON' : 'OFF')
        );
        $db->exec(
            'PRAGMA foreign_keys = '
                . ($foreignKeysEnabled ? 'ON' : 'OFF')
        );
    }
}

function ingredientOntologyV3EnsureHistoricalShadowMatchOwners(
    PDO $db
): void {
    if (
        !ingredientOntologyV3TableExists(
            $db,
            'ingredient_ontology_shadow_matches'
        )
    ) {
        return;
    }
    $hasIngredientForeignKey = false;
    foreach (
        $db->query(
            'PRAGMA foreign_key_list(ingredient_ontology_shadow_matches)'
        )->fetchAll(PDO::FETCH_ASSOC) as $foreignKey
    ) {
        if (
            (string)($foreignKey['from'] ?? '')
                === 'recipe_ingredient_id'
            && (string)($foreignKey['table'] ?? '')
                === 'recipe_ingredients'
        ) {
            $hasIngredientForeignKey = true;
            break;
        }
    }
    if (!$hasIngredientForeignKey) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException(
            'shadow match owner migration requires no active transaction'
        );
    }
    $foreignKeysEnabled =
        (int)$db->query('PRAGMA foreign_keys')->fetchColumn() === 1;
    $replacement = 'ingredient_ontology_shadow_matches_v317';
    $rowCount = (int)$db->query("
        SELECT COUNT(*) FROM ingredient_ontology_shadow_matches
    ")->fetchColumn();
    $db->exec('PRAGMA foreign_keys = OFF');
    $transactionStarted = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $transactionStarted = true;
        $db->exec("DROP TABLE IF EXISTS {$replacement}");
        $db->exec("
            CREATE TABLE {$replacement} (
                score_revision_id INTEGER NOT NULL,
                recipe_ingredient_id INTEGER NOT NULL,
                recipe_mapping_id INTEGER DEFAULT NULL,
                inventory_product_id INTEGER DEFAULT NULL,
                inventory_mapping_id INTEGER DEFAULT NULL,
                outcome TEXT NOT NULL CHECK(length(outcome) <= 80),
                satisfies_required INTEGER NOT NULL DEFAULT 0
                    CHECK(satisfies_required IN (0, 1)),
                confidence REAL NOT NULL DEFAULT 0
                    CHECK(confidence BETWEEN 0 AND 1),
                relationship TEXT NOT NULL DEFAULT 'none'
                    CHECK(length(relationship) <= 80),
                explanation_json TEXT NOT NULL DEFAULT '{}'
                    CHECK(length(explanation_json) <= 32768),
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(score_revision_id, recipe_ingredient_id),
                FOREIGN KEY (score_revision_id)
                    REFERENCES recipe_score_revisions(id)
                        ON DELETE CASCADE,
                FOREIGN KEY (recipe_mapping_id)
                    REFERENCES ingredient_ontology_mappings(id)
                        ON DELETE SET NULL,
                FOREIGN KEY (inventory_mapping_id)
                    REFERENCES ingredient_ontology_mappings(id)
                        ON DELETE SET NULL
            );
            INSERT INTO {$replacement} (
                score_revision_id, recipe_ingredient_id,
                recipe_mapping_id, inventory_product_id,
                inventory_mapping_id, outcome, satisfies_required,
                confidence, relationship, explanation_json, created_at
            )
            SELECT score_revision_id, recipe_ingredient_id,
                   recipe_mapping_id, inventory_product_id,
                   inventory_mapping_id, outcome, satisfies_required,
                   confidence, relationship, explanation_json, created_at
            FROM ingredient_ontology_shadow_matches
        ");
        if (
            (int)$db->query(
                "SELECT COUNT(*) FROM {$replacement}"
            )->fetchColumn() !== $rowCount
        ) {
            throw new RuntimeException(
                'shadow match owner migration lost rows'
            );
        }
        $db->exec("
            DROP TABLE ingredient_ontology_shadow_matches;
            ALTER TABLE {$replacement}
                RENAME TO ingredient_ontology_shadow_matches;
            CREATE INDEX idx_ontology_shadow_outcome
                ON ingredient_ontology_shadow_matches(
                    score_revision_id, outcome, satisfies_required
                );
            CREATE INDEX idx_ontology_shadow_mapping
                ON ingredient_ontology_shadow_matches(
                    score_revision_id, recipe_mapping_id
                )
        ");
        $db->exec('COMMIT');
        $transactionStarted = false;
    } catch (Throwable $e) {
        if ($transactionStarted || $db->inTransaction()) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
        }
        throw $e;
    } finally {
        $db->exec(
            'PRAGMA foreign_keys = '
                . ($foreignKeysEnabled ? 'ON' : 'OFF')
        );
    }
}

function ingredientOntologyV3EnsurePendingEdgeReviewDisposition(
    PDO $db
): void {
    $tableSql = $db->query("
        SELECT sql
        FROM sqlite_master
        WHERE type = 'table'
          AND name = 'ingredient_ontology_primary_edge_reviews'
    ")->fetchColumn();
    if (
        !is_string($tableSql)
        || str_contains($tableSql, "'pending'")
    ) {
        return;
    }
    if ($db->inTransaction()) {
        throw new RuntimeException(
            'edge-review disposition migration requires no active transaction'
        );
    }
    $foreignKeysEnabled = (int)$db->query(
        'PRAGMA foreign_keys'
    )->fetchColumn() === 1;
    $legacyAlterTableEnabled = (int)$db->query(
        'PRAGMA legacy_alter_table'
    )->fetchColumn() === 1;
    $transactionStarted = false;
    try {
        $db->exec('PRAGMA foreign_keys = OFF');
        $db->exec('PRAGMA legacy_alter_table = ON');
        $db->exec('BEGIN IMMEDIATE');
        $transactionStarted = true;
        $db->exec("
            CREATE TABLE ingredient_ontology_primary_edge_reviews_v314 (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ontology_version_id INTEGER NOT NULL,
                child_entity_id INTEGER NOT NULL,
                previous_parent_entity_id INTEGER DEFAULT NULL,
                new_parent_entity_id INTEGER DEFAULT NULL,
                change_kind TEXT NOT NULL
                    CHECK(change_kind IN (
                        'added', 'changed', 'removed',
                        'restored', 'unchanged'
                    )),
                disposition TEXT NOT NULL
                    CHECK(disposition IN (
                        'pending', 'reviewed',
                        'rejected', 'evidence_needed'
                    )),
                rationale TEXT NOT NULL
                    CHECK(length(rationale) BETWEEN 1 AND 1000),
                manifest_id INTEGER DEFAULT NULL,
                content_hash TEXT NOT NULL
                    CHECK(length(content_hash) = 64),
                reviewer TEXT NOT NULL
                    CHECK(length(reviewer) BETWEEN 1 AND 120),
                review_batch TEXT NOT NULL
                    CHECK(length(review_batch) BETWEEN 1 AND 120),
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(ontology_version_id, child_entity_id),
                FOREIGN KEY (ontology_version_id)
                    REFERENCES ingredient_ontology_versions(id)
                        ON DELETE CASCADE,
                FOREIGN KEY (child_entity_id)
                    REFERENCES ingredient_ontology_entities(id)
                        ON DELETE CASCADE,
                FOREIGN KEY (previous_parent_entity_id)
                    REFERENCES ingredient_ontology_entities(id)
                        ON DELETE SET NULL,
                FOREIGN KEY (new_parent_entity_id)
                    REFERENCES ingredient_ontology_entities(id)
                        ON DELETE SET NULL,
                FOREIGN KEY (manifest_id)
                    REFERENCES ingredient_ontology_resolution_manifests(id)
                        ON DELETE SET NULL
            );
            INSERT INTO ingredient_ontology_primary_edge_reviews_v314 (
                id, ontology_version_id, child_entity_id,
                previous_parent_entity_id, new_parent_entity_id,
                change_kind, disposition, rationale, manifest_id,
                content_hash, reviewer, review_batch, created_at
            )
            SELECT id, ontology_version_id, child_entity_id,
                   previous_parent_entity_id, new_parent_entity_id,
                   change_kind, disposition, rationale, manifest_id,
                   content_hash, reviewer, review_batch, created_at
            FROM ingredient_ontology_primary_edge_reviews;
            DROP TABLE ingredient_ontology_primary_edge_reviews;
            ALTER TABLE ingredient_ontology_primary_edge_reviews_v314
                RENAME TO ingredient_ontology_primary_edge_reviews;
            CREATE INDEX idx_ontology_edge_review
                ON ingredient_ontology_primary_edge_reviews(
                    ontology_version_id, change_kind, disposition
                );
        ");
        $db->exec('COMMIT');
        $transactionStarted = false;
    } catch (Throwable $e) {
        if ($transactionStarted || $db->inTransaction()) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
        }
        throw $e;
    } finally {
        $db->exec(
            'PRAGMA legacy_alter_table = '
                . ($legacyAlterTableEnabled ? 'ON' : 'OFF')
        );
        $db->exec(
            'PRAGMA foreign_keys = '
                . ($foreignKeysEnabled ? 'ON' : 'OFF')
        );
    }
}

function ingredientOntologyV3SchemaMigrate(PDO $db): void {
    ingredientOntologyV3SetRequirementPruneGuard(
        $db,
        ingredientOntologyV3RequirementPruneGuardEnabled($db)
    );
    $publicationGuardKey = 'ingredient_ontology_publication_guard:'
        . spl_object_id($db);
    ingredientOntologyV3SetPublicationGuard(
        $db,
        !empty($GLOBALS[$publicationGuardKey])
    );
    $readyGuardKey = 'ingredient_ontology_ready_mutation_guard:'
        . spl_object_id($db);
    ingredientOntologyV3SetReadyMutationGuard(
        $db,
        !empty($GLOBALS[$readyGuardKey])
    );
    $db->exec("
        CREATE TABLE IF NOT EXISTS ingredient_ontology_versions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            version TEXT NOT NULL UNIQUE
                CHECK(length(version) BETWEEN 1 AND 80),
            status TEXT NOT NULL DEFAULT 'building'
                CHECK(status IN (
                    'building', 'ready', 'active', 'failed', 'retired'
                )),
            schema_hash TEXT NOT NULL CHECK(length(schema_hash) = 64),
            prompt_hash TEXT NOT NULL CHECK(length(prompt_hash) = 64),
            model_hash TEXT NOT NULL CHECK(length(model_hash) = 64),
            model_name TEXT NOT NULL DEFAULT 'gemini-3.5-flash'
                CHECK(length(model_name) BETWEEN 1 AND 100),
            corpus_hash TEXT NOT NULL CHECK(length(corpus_hash) = 64),
            content_hash TEXT NOT NULL CHECK(length(content_hash) = 64),
            parent_version_id INTEGER DEFAULT NULL,
            validation_report_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(validation_report_json) <= 262144),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ready_at DATETIME DEFAULT NULL,
            failed_at DATETIME DEFAULT NULL,
            retired_at DATETIME DEFAULT NULL,
            FOREIGN KEY (parent_version_id)
                REFERENCES ingredient_ontology_versions(id)
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_entities (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            local_key TEXT NOT NULL CHECK(length(local_key) BETWEEN 1 AND 160),
            slug TEXT NOT NULL CHECK(length(slug) BETWEEN 1 AND 160),
            canonical_name TEXT NOT NULL
                CHECK(length(canonical_name) BETWEEN 1 AND 200),
            entity_kind TEXT NOT NULL DEFAULT 'ingredient'
                CHECK(entity_kind IN (
                    'ingredient', 'prepared_food', 'composite_food'
                )),
            active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0, 1)),
            provenance TEXT NOT NULL DEFAULT 'local'
                CHECK(length(provenance) <= 120),
            legacy_taxonomy_node_id INTEGER DEFAULT NULL,
            legacy_canonical_ingredient_id INTEGER DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(ontology_version_id, local_key),
            UNIQUE(ontology_version_id, slug),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_labels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            entity_id INTEGER NOT NULL,
            language TEXT NOT NULL DEFAULT 'und'
                CHECK(length(language) BETWEEN 2 AND 35),
            label TEXT NOT NULL CHECK(length(label) BETWEEN 1 AND 200),
            normalized_label TEXT NOT NULL
                CHECK(length(normalized_label) BETWEEN 1 AND 200),
            kind TEXT NOT NULL
                CHECK(kind IN (
                    'exact_alias', 'attribute_alias', 'trade_name',
                    'candidate_only', 'misspelling'
                )),
            review_state TEXT NOT NULL DEFAULT 'pending'
                CHECK(review_state IN (
                    'pending', 'accepted', 'rejected', 'quarantined'
                )),
            provenance TEXT NOT NULL DEFAULT 'local'
                CHECK(length(provenance) <= 120),
            source_ref TEXT DEFAULT NULL CHECK(length(source_ref) <= 240),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(
                ontology_version_id, entity_id, language,
                normalized_label, kind
            ),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (entity_id)
                REFERENCES ingredient_ontology_entities(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_relations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            from_entity_id INTEGER NOT NULL,
            to_entity_id INTEGER NOT NULL,
            relation TEXT NOT NULL
                CHECK(relation IN (
                    'is_a', 'equivalent_to', 'variant_of',
                    'substitutes_for', 'derived_from', 'component_of'
                )),
            direction TEXT NOT NULL DEFAULT 'forward'
                CHECK(direction IN ('forward', 'bidirectional')),
            is_primary INTEGER NOT NULL DEFAULT 0 CHECK(is_primary IN (0, 1)),
            satisfies_required INTEGER NOT NULL DEFAULT 0
                CHECK(satisfies_required = 0),
            confidence REAL NOT NULL DEFAULT 0
                CHECK(confidence BETWEEN 0 AND 1),
            provenance TEXT NOT NULL DEFAULT 'local'
                CHECK(length(provenance) <= 120),
            review_state TEXT NOT NULL DEFAULT 'pending'
                CHECK(review_state IN (
                    'pending', 'accepted', 'rejected', 'quarantined'
                )),
            semantics_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(semantics_json) <= 16384),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CHECK(from_entity_id <> to_entity_id),
            CHECK(relation = 'is_a' OR is_primary = 0),
            UNIQUE(
                ontology_version_id, from_entity_id, to_entity_id, relation
            ),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (from_entity_id)
                REFERENCES ingredient_ontology_entities(id) ON DELETE CASCADE,
            FOREIGN KEY (to_entity_id)
                REFERENCES ingredient_ontology_entities(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_facets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            facet_key TEXT NOT NULL CHECK(length(facet_key) BETWEEN 1 AND 60),
            display_name TEXT NOT NULL CHECK(length(display_name) <= 100),
            hard_default INTEGER NOT NULL DEFAULT 1
                CHECK(hard_default IN (0, 1)),
            active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0, 1)),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(ontology_version_id, facet_key),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_facet_values (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            facet_id INTEGER NOT NULL,
            value_key TEXT NOT NULL CHECK(length(value_key) BETWEEN 1 AND 80),
            display_name TEXT NOT NULL CHECK(length(display_name) <= 120),
            active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0, 1)),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(facet_id, value_key),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (facet_id)
                REFERENCES ingredient_ontology_facets(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_entity_defaults (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            entity_id INTEGER NOT NULL,
            facet_id INTEGER NOT NULL,
            facet_value_id INTEGER NOT NULL,
            is_defining INTEGER NOT NULL DEFAULT 1
                CHECK(is_defining IN (0, 1)),
            provenance TEXT NOT NULL DEFAULT 'deterministic_seed'
                CHECK(length(provenance) <= 120),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(entity_id, facet_id),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (entity_id)
                REFERENCES ingredient_ontology_entities(id) ON DELETE CASCADE,
            FOREIGN KEY (facet_id)
                REFERENCES ingredient_ontology_facets(id) ON DELETE CASCADE,
            FOREIGN KEY (facet_value_id)
                REFERENCES ingredient_ontology_facet_values(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_label_attributes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            label_id INTEGER NOT NULL,
            facet_id INTEGER NOT NULL,
            facet_value_id INTEGER NOT NULL,
            is_defining INTEGER NOT NULL DEFAULT 1
                CHECK(is_defining IN (0, 1)),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(label_id, facet_id),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (label_id)
                REFERENCES ingredient_ontology_labels(id) ON DELETE CASCADE,
            FOREIGN KEY (facet_id)
                REFERENCES ingredient_ontology_facets(id) ON DELETE CASCADE,
            FOREIGN KEY (facet_value_id)
                REFERENCES ingredient_ontology_facet_values(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_mappings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            owner_type TEXT NOT NULL
                CHECK(owner_type IN (
                    'product', 'recipe_ingredient',
                    'recipe_source_ingredient'
                )),
            owner_id INTEGER NOT NULL CHECK(owner_id > 0),
            owner_fingerprint TEXT NOT NULL CHECK(length(owner_fingerprint) = 64),
            source_label TEXT NOT NULL DEFAULT ''
                CHECK(length(source_label) <= 200),
            normalized_label TEXT NOT NULL DEFAULT ''
                CHECK(length(normalized_label) <= 200),
            language TEXT NOT NULL DEFAULT 'und'
                CHECK(length(language) BETWEEN 2 AND 35),
            entity_id INTEGER DEFAULT NULL,
            status TEXT NOT NULL
                CHECK(status IN (
                    'accepted', 'candidate', 'ambiguous',
                    'unresolved', 'rejected'
                )),
            confidence REAL NOT NULL DEFAULT 0
                CHECK(confidence BETWEEN 0 AND 1),
            mapping_source TEXT NOT NULL DEFAULT 'unresolved'
                CHECK(length(mapping_source) <= 80),
            evidence_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(evidence_json) <= 32768),
            attributes_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(attributes_json) <= 16384),
            is_staple INTEGER NOT NULL DEFAULT 0 CHECK(is_staple IN (0, 1)),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(ontology_version_id, owner_type, owner_id),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (entity_id)
                REFERENCES ingredient_ontology_entities(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_mapping_attributes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            mapping_id INTEGER NOT NULL,
            facet_id INTEGER NOT NULL,
            facet_value_id INTEGER NOT NULL,
            is_defining INTEGER NOT NULL DEFAULT 1
                CHECK(is_defining IN (0, 1)),
            provenance TEXT NOT NULL DEFAULT 'deterministic'
                CHECK(length(provenance) <= 120),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(mapping_id, facet_id),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (mapping_id)
                REFERENCES ingredient_ontology_mappings(id) ON DELETE CASCADE,
            FOREIGN KEY (facet_id)
                REFERENCES ingredient_ontology_facets(id) ON DELETE CASCADE,
            FOREIGN KEY (facet_value_id)
                REFERENCES ingredient_ontology_facet_values(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_mapping_relations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            mapping_id INTEGER NOT NULL,
            to_entity_id INTEGER NOT NULL,
            relation TEXT NOT NULL
                CHECK(relation IN (
                    'equivalent_to', 'variant_of', 'substitutes_for',
                    'derived_from', 'component_of'
                )),
            direction TEXT NOT NULL DEFAULT 'forward'
                CHECK(direction IN ('forward', 'bidirectional')),
            confidence REAL NOT NULL DEFAULT 1
                CHECK(confidence BETWEEN 0 AND 1),
            provenance TEXT NOT NULL DEFAULT 'deterministic'
                CHECK(length(provenance) <= 120),
            review_state TEXT NOT NULL DEFAULT 'accepted'
                CHECK(review_state IN (
                    'pending', 'accepted', 'rejected', 'quarantined'
                )),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(mapping_id, to_entity_id, relation),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (mapping_id)
                REFERENCES ingredient_ontology_mappings(id) ON DELETE CASCADE,
            FOREIGN KEY (to_entity_id)
                REFERENCES ingredient_ontology_entities(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_resolution_manifests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            manifest_key TEXT NOT NULL CHECK(length(manifest_key) BETWEEN 1 AND 120),
            manifest_version TEXT NOT NULL
                CHECK(length(manifest_version) BETWEEN 1 AND 80),
            manifest_hash TEXT NOT NULL CHECK(length(manifest_hash) = 64),
            content_hash TEXT NOT NULL CHECK(length(content_hash) = 64),
            source_corpus_hash TEXT DEFAULT NULL
                CHECK(source_corpus_hash IS NULL OR length(source_corpus_hash) = 64),
            reviewer TEXT NOT NULL CHECK(length(reviewer) BETWEEN 1 AND 120),
            review_batch TEXT NOT NULL
                CHECK(length(review_batch) BETWEEN 1 AND 120),
            metadata_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(metadata_json) <= 65536),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(ontology_version_id, manifest_key),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_evidence_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            manifest_id INTEGER DEFAULT NULL,
            evidence_kind TEXT NOT NULL
                CHECK(evidence_kind IN (
                    'curated_manifest', 'provider_ref_cluster',
                    'recipe_cohort', 'modifier_span', 'comma_segment',
                    'parenthetical_segment', 'rule_adjudication',
                    'product_review', 'provider_review',
                    'deterministic_exhaustion'
                )),
            evidence_key TEXT NOT NULL CHECK(length(evidence_key) BETWEEN 1 AND 240),
            evidence_scope TEXT NOT NULL DEFAULT 'global_review'
                CHECK(evidence_scope IN (
                    'global_review', 'owner_observation'
                )),
            owner_fingerprint TEXT DEFAULT NULL
                CHECK(
                    owner_fingerprint IS NULL
                    OR length(owner_fingerprint) = 64
                ),
            connector TEXT DEFAULT NULL
                CHECK(connector IS NULL OR length(connector) <= 80),
            metadata_schema_version TEXT DEFAULT NULL
                CHECK(
                    metadata_schema_version IS NULL
                    OR length(metadata_schema_version) <= 80
                ),
            provider_ref TEXT DEFAULT NULL
                CHECK(provider_ref IS NULL OR length(provider_ref) <= 200),
            title_hash TEXT DEFAULT NULL
                CHECK(title_hash IS NULL OR length(title_hash) = 64),
            observation_hash TEXT DEFAULT NULL
                CHECK(
                    observation_hash IS NULL
                    OR length(observation_hash) = 64
                ),
            scope_hash TEXT NOT NULL CHECK(length(scope_hash) = 64),
            payload_hash TEXT NOT NULL CHECK(length(payload_hash) = 64),
            payload_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(payload_json) <= 65536),
            algorithm_hash TEXT NOT NULL CHECK(length(algorithm_hash) = 64),
            reviewer TEXT NOT NULL CHECK(length(reviewer) BETWEEN 1 AND 120),
            review_batch TEXT NOT NULL
                CHECK(length(review_batch) BETWEEN 1 AND 120),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(ontology_version_id, evidence_kind, evidence_key),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (manifest_id)
                REFERENCES ingredient_ontology_resolution_manifests(id)
                    ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_entity_facet_policies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            entity_id INTEGER NOT NULL,
            facet_id INTEGER NOT NULL,
            allowed INTEGER NOT NULL DEFAULT 1 CHECK(allowed IN (0, 1)),
            defining INTEGER NOT NULL DEFAULT 1 CHECK(defining IN (0, 1)),
            evidence_source_id INTEGER DEFAULT NULL,
            policy_hash TEXT NOT NULL CHECK(length(policy_hash) = 64),
            provenance TEXT NOT NULL CHECK(length(provenance) <= 120),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(entity_id, facet_id),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (entity_id)
                REFERENCES ingredient_ontology_entities(id) ON DELETE CASCADE,
            FOREIGN KEY (facet_id)
                REFERENCES ingredient_ontology_facets(id) ON DELETE CASCADE,
            FOREIGN KEY (evidence_source_id)
                REFERENCES ingredient_ontology_evidence_sources(id)
                    ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_label_context_policies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            label_id INTEGER NOT NULL,
            required_cohort TEXT DEFAULT NULL
                CHECK(required_cohort IS NULL OR length(required_cohort) <= 35),
            required_evidence_kind TEXT DEFAULT NULL
                CHECK(
                    required_evidence_kind IS NULL
                    OR length(required_evidence_kind) <= 60
                ),
            required_evidence_key TEXT DEFAULT NULL
                CHECK(
                    required_evidence_key IS NULL
                    OR length(required_evidence_key) <= 240
                ),
            policy_hash TEXT NOT NULL CHECK(length(policy_hash) = 64),
            provenance TEXT NOT NULL CHECK(length(provenance) <= 120),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(label_id),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (label_id)
                REFERENCES ingredient_ontology_labels(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_recipe_cohorts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            recipe_id INTEGER NOT NULL CHECK(recipe_id > 0),
            cohort TEXT DEFAULT NULL
                CHECK(cohort IS NULL OR length(cohort) <= 35),
            winner_votes INTEGER NOT NULL DEFAULT 0 CHECK(winner_votes >= 0),
            runner_up_votes INTEGER NOT NULL DEFAULT 0
                CHECK(runner_up_votes >= 0),
            margin INTEGER NOT NULL DEFAULT 0 CHECK(margin >= 0),
            conflict_count INTEGER NOT NULL DEFAULT 0
                CHECK(conflict_count >= 0),
            votes_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(votes_json) <= 4096),
            recipe_fingerprint TEXT NOT NULL CHECK(length(recipe_fingerprint) = 64),
            algorithm_hash TEXT NOT NULL CHECK(length(algorithm_hash) = 64),
            evidence_source_id INTEGER DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(ontology_version_id, recipe_id),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (evidence_source_id)
                REFERENCES ingredient_ontology_evidence_sources(id)
                    ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_primary_edge_reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            child_entity_id INTEGER NOT NULL,
            previous_parent_entity_id INTEGER DEFAULT NULL,
            new_parent_entity_id INTEGER DEFAULT NULL,
            change_kind TEXT NOT NULL
                CHECK(change_kind IN (
                    'added', 'changed', 'removed', 'restored', 'unchanged'
                )),
            disposition TEXT NOT NULL
                CHECK(disposition IN (
                    'pending', 'reviewed', 'rejected', 'evidence_needed'
                )),
            rationale TEXT NOT NULL CHECK(length(rationale) BETWEEN 1 AND 1000),
            manifest_id INTEGER DEFAULT NULL,
            content_hash TEXT NOT NULL CHECK(length(content_hash) = 64),
            reviewer TEXT NOT NULL CHECK(length(reviewer) BETWEEN 1 AND 120),
            review_batch TEXT NOT NULL
                CHECK(length(review_batch) BETWEEN 1 AND 120),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(ontology_version_id, child_entity_id),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (child_entity_id)
                REFERENCES ingredient_ontology_entities(id) ON DELETE CASCADE,
            FOREIGN KEY (previous_parent_entity_id)
                REFERENCES ingredient_ontology_entities(id) ON DELETE SET NULL,
            FOREIGN KEY (new_parent_entity_id)
                REFERENCES ingredient_ontology_entities(id) ON DELETE SET NULL,
            FOREIGN KEY (manifest_id)
                REFERENCES ingredient_ontology_resolution_manifests(id)
                    ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_disposition_scopes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            scope_type TEXT NOT NULL
                CHECK(scope_type IN (
                    'global_label', 'provider_term', 'product_fingerprint',
                    'owner_fingerprint', 'cohort_context'
                )),
            scope_key TEXT NOT NULL CHECK(length(scope_key) BETWEEN 1 AND 240),
            scope_fingerprint TEXT NOT NULL CHECK(length(scope_fingerprint) = 64),
            portable_scope_hash TEXT NOT NULL
                CHECK(length(portable_scope_hash) = 64),
            normalized_label TEXT NOT NULL DEFAULT ''
                CHECK(length(normalized_label) <= 200),
            language TEXT NOT NULL DEFAULT 'und'
                CHECK(length(language) BETWEEN 2 AND 35),
            context_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(context_json) <= 16384),
            content_hash TEXT NOT NULL CHECK(length(content_hash) = 64),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(ontology_version_id, scope_type, scope_fingerprint),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_terminal_dispositions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            scope_id INTEGER NOT NULL,
            disposition_code TEXT NOT NULL
                CHECK(disposition_code IN (
                    'D1', 'D2', 'D3', 'D4', 'D5',
                    'D6', 'D7', 'D8', 'D9'
                )),
            disposition_name TEXT NOT NULL
                CHECK(disposition_name IN (
                    'accepted_identity',
                    'accepted_identity_with_facets',
                    'reviewed_contextual',
                    'reviewed_ambiguous',
                    'reviewed_composite_or_prepared',
                    'reviewed_non_identity_modifier',
                    'rejected_non_food_or_noise',
                    'provider_specific_unresolved',
                    'evidence_needed_terminal'
                )),
            entity_id INTEGER DEFAULT NULL,
            attributes_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(attributes_json) <= 16384),
            mechanism TEXT NOT NULL CHECK(length(mechanism) BETWEEN 1 AND 120),
            evidence_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(evidence_json) <= 32768),
            evidence_hash TEXT NOT NULL CHECK(length(evidence_hash) = 64),
            reviewer TEXT NOT NULL CHECK(length(reviewer) BETWEEN 1 AND 120),
            review_batch TEXT NOT NULL
                CHECK(length(review_batch) BETWEEN 1 AND 120),
            batch_hash TEXT NOT NULL CHECK(length(batch_hash) = 64),
            portable_disposition_hash TEXT NOT NULL
                CHECK(length(portable_disposition_hash) = 64),
            content_hash TEXT NOT NULL CHECK(length(content_hash) = 64),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(ontology_version_id, scope_id),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (scope_id)
                REFERENCES ingredient_ontology_disposition_scopes(id)
                    ON DELETE CASCADE,
            FOREIGN KEY (entity_id)
                REFERENCES ingredient_ontology_entities(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_review_imports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            import_kind TEXT NOT NULL
                CHECK(import_kind IN ('disposition', 'provider_workbook')),
            input_hash TEXT NOT NULL CHECK(length(input_hash) = 64),
            manifest_hash TEXT NOT NULL CHECK(length(manifest_hash) = 64),
            row_count INTEGER NOT NULL DEFAULT 0 CHECK(row_count >= 0),
            reviewer TEXT NOT NULL CHECK(length(reviewer) BETWEEN 1 AND 120),
            review_batch TEXT NOT NULL
                CHECK(length(review_batch) BETWEEN 1 AND 120),
            payload_json TEXT NOT NULL DEFAULT '[]'
                CHECK(length(payload_json) <= 262144),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(ontology_version_id, import_kind, input_hash),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_review_import_rows (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            import_id INTEGER NOT NULL,
            ontology_version_id INTEGER NOT NULL,
            row_number INTEGER NOT NULL CHECK(row_number > 0),
            scope_fingerprint TEXT NOT NULL CHECK(length(scope_fingerprint) = 64),
            owner_fingerprint TEXT DEFAULT NULL
                CHECK(owner_fingerprint IS NULL OR length(owner_fingerprint) = 64),
            disposition_code TEXT NOT NULL
                CHECK(disposition_code IN (
                    'D1', 'D2', 'D3', 'D4', 'D5',
                    'D6', 'D7', 'D8', 'D9'
                )),
            entity_slug TEXT DEFAULT NULL
                CHECK(entity_slug IS NULL OR length(entity_slug) <= 160),
            attributes_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(attributes_json) <= 16384),
            evidence_hash TEXT NOT NULL CHECK(length(evidence_hash) = 64),
            row_hash TEXT NOT NULL CHECK(length(row_hash) = 64),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(import_id, row_number),
            UNIQUE(import_id, scope_fingerprint),
            FOREIGN KEY (import_id)
                REFERENCES ingredient_ontology_review_imports(id)
                    ON DELETE CASCADE,
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS
            ingredient_ontology_mapping_assertion_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            mapping_id INTEGER NOT NULL,
            owner_type TEXT NOT NULL
                CHECK(owner_type IN (
                    'product', 'recipe_ingredient',
                    'recipe_source_ingredient'
                )),
            owner_fingerprint TEXT NOT NULL CHECK(length(owner_fingerprint) = 64),
            phase TEXT NOT NULL CHECK(length(phase) BETWEEN 1 AND 80),
            prior_status TEXT NOT NULL
                CHECK(prior_status IN (
                    'accepted', 'candidate', 'ambiguous',
                    'unresolved', 'rejected'
                )),
            proposed_entity_slug TEXT DEFAULT NULL
                CHECK(
                    proposed_entity_slug IS NULL
                    OR length(proposed_entity_slug) <= 160
                ),
            proposed_confidence REAL NOT NULL DEFAULT 0
                CHECK(proposed_confidence BETWEEN 0 AND 1),
            proposed_attributes_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(proposed_attributes_json) <= 16384),
            proposed_relations_json TEXT NOT NULL DEFAULT '[]'
                CHECK(length(proposed_relations_json) <= 32768),
            mapping_source TEXT NOT NULL CHECK(length(mapping_source) <= 120),
            legacy_target_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(legacy_target_json) <= 32768),
            denied_provenance_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(denied_provenance_json) <= 32768),
            evidence_hash TEXT NOT NULL CHECK(length(evidence_hash) = 64),
            content_hash TEXT NOT NULL CHECK(length(content_hash) = 64),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(ontology_version_id, mapping_id, phase),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (mapping_id)
                REFERENCES ingredient_ontology_mappings(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_curated_product_assertions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL CHECK(product_id > 0),
            product_fingerprint TEXT NOT NULL CHECK(length(product_fingerprint) = 64),
            product_name TEXT NOT NULL CHECK(length(product_name) <= 200),
            normalized_product_name TEXT NOT NULL
                CHECK(length(normalized_product_name) <= 200),
            entity_id INTEGER DEFAULT NULL,
            status TEXT NOT NULL
                CHECK(status IN (
                    'accepted', 'candidate', 'ambiguous',
                    'unresolved', 'rejected'
                )),
            confidence REAL NOT NULL DEFAULT 0
                CHECK(confidence BETWEEN 0 AND 1),
            attributes_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(attributes_json) <= 16384),
            rationale TEXT NOT NULL CHECK(length(rationale) <= 1000),
            provenance TEXT NOT NULL DEFAULT 'curated_review_v1'
                CHECK(length(provenance) <= 120),
            review_state TEXT NOT NULL DEFAULT 'accepted'
                CHECK(review_state IN (
                    'pending', 'accepted', 'rejected', 'quarantined'
                )),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(ontology_version_id, product_id),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (entity_id)
                REFERENCES ingredient_ontology_entities(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_curated_provider_reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            connector TEXT NOT NULL CHECK(length(connector) <= 80),
            metadata_schema_version TEXT NOT NULL
                CHECK(length(metadata_schema_version) <= 80),
            namespace TEXT NOT NULL CHECK(length(namespace) <= 160),
            provider_ref TEXT NOT NULL CHECK(length(provider_ref) <= 200),
            disposition TEXT NOT NULL
                CHECK(disposition IN (
                    'accepted', 'candidate', 'quarantined',
                    'unresolved', 'rejected'
                )),
            rationale TEXT NOT NULL CHECK(length(rationale) <= 1000),
            provenance TEXT NOT NULL DEFAULT 'curated_review_v1'
                CHECK(length(provenance) <= 120),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(
                ontology_version_id, connector, metadata_schema_version,
                namespace, provider_ref
            ),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_curated_provider_conflict_reviews (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            mapping_id INTEGER NOT NULL,
            provider_term_id INTEGER DEFAULT NULL,
            disposition TEXT NOT NULL
                CHECK(disposition IN ('quarantined', 'unresolved', 'rejected')),
            rationale TEXT NOT NULL CHECK(length(rationale) <= 1000),
            provenance TEXT NOT NULL DEFAULT 'curated_review_v1'
                CHECK(length(provenance) <= 120),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(ontology_version_id, mapping_id),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (mapping_id)
                REFERENCES ingredient_ontology_mappings(id) ON DELETE CASCADE,
            FOREIGN KEY (provider_term_id)
                REFERENCES ingredient_ontology_provider_terms(id)
                    ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_change_sets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            change_set_key TEXT NOT NULL CHECK(length(change_set_key) <= 100),
            input_hash TEXT NOT NULL CHECK(length(input_hash) = 64),
            prompt_hash TEXT NOT NULL CHECK(length(prompt_hash) = 64),
            model_hash TEXT NOT NULL CHECK(length(model_hash) = 64),
            schema_hash TEXT NOT NULL CHECK(length(schema_hash) = 64),
            model_name TEXT NOT NULL CHECK(length(model_name) <= 100),
            raw_model_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(raw_model_json) <= 65536),
            validator_result_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(validator_result_json) <= 65536),
            review_state TEXT NOT NULL DEFAULT 'pending'
                CHECK(review_state IN (
                    'pending', 'approved', 'rejected',
                    'applied', 'reverted'
                )),
            approved_by TEXT DEFAULT NULL CHECK(length(approved_by) <= 120),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME DEFAULT NULL,
            applied_at DATETIME DEFAULT NULL,
            reverted_at DATETIME DEFAULT NULL,
            UNIQUE(ontology_version_id, change_set_key),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_proposals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            change_set_id INTEGER NOT NULL,
            input_id TEXT NOT NULL CHECK(length(input_id) <= 120),
            decision TEXT NOT NULL
                CHECK(decision IN (
                    'link', 'propose', 'ambiguous', 'reject'
                )),
            entity_id INTEGER DEFAULT NULL,
            proposed_local_key TEXT DEFAULT NULL
                CHECK(proposed_local_key IS NULL OR length(proposed_local_key) <= 160),
            proposed_name TEXT DEFAULT NULL
                CHECK(proposed_name IS NULL OR length(proposed_name) <= 200),
            proposed_parent_entity_id INTEGER DEFAULT NULL,
            entity_kind TEXT DEFAULT NULL
                CHECK(entity_kind IS NULL OR entity_kind IN (
                    'ingredient', 'prepared_food', 'composite_food'
                )),
            normalized_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(normalized_json) <= 32768),
            raw_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(raw_json) <= 32768),
            validator_result_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(validator_result_json) <= 32768),
            merge_key TEXT NOT NULL CHECK(length(merge_key) = 64),
            merged_into_proposal_id INTEGER DEFAULT NULL,
            review_state TEXT NOT NULL DEFAULT 'pending'
                CHECK(review_state IN (
                    'pending', 'approved', 'rejected',
                    'applied', 'reverted'
                )),
            approved_by TEXT DEFAULT NULL CHECK(length(approved_by) <= 120),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME DEFAULT NULL,
            applied_at DATETIME DEFAULT NULL,
            reverted_at DATETIME DEFAULT NULL,
            UNIQUE(change_set_id, input_id),
            FOREIGN KEY (change_set_id)
                REFERENCES ingredient_ontology_change_sets(id) ON DELETE CASCADE,
            FOREIGN KEY (entity_id)
                REFERENCES ingredient_ontology_entities(id) ON DELETE SET NULL,
            FOREIGN KEY (proposed_parent_entity_id)
                REFERENCES ingredient_ontology_entities(id) ON DELETE SET NULL,
            FOREIGN KEY (merged_into_proposal_id)
                REFERENCES ingredient_ontology_proposals(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_change_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            change_set_id INTEGER NOT NULL,
            proposal_id INTEGER DEFAULT NULL,
            action TEXT NOT NULL
                CHECK(action IN ('apply', 'reject', 'dispose', 'revert')),
            from_state TEXT NOT NULL CHECK(length(from_state) <= 20),
            to_state TEXT NOT NULL CHECK(length(to_state) <= 20),
            actor TEXT NOT NULL CHECK(length(actor) BETWEEN 1 AND 120),
            reason TEXT NOT NULL CHECK(length(reason) BETWEEN 1 AND 1000),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (change_set_id)
                REFERENCES ingredient_ontology_change_sets(id),
            FOREIGN KEY (proposal_id)
                REFERENCES ingredient_ontology_proposals(id)
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_shadow_matches (
            score_revision_id INTEGER NOT NULL,
            recipe_ingredient_id INTEGER NOT NULL,
            recipe_mapping_id INTEGER DEFAULT NULL,
            inventory_product_id INTEGER DEFAULT NULL,
            inventory_mapping_id INTEGER DEFAULT NULL,
            outcome TEXT NOT NULL CHECK(length(outcome) <= 80),
            satisfies_required INTEGER NOT NULL DEFAULT 0
                CHECK(satisfies_required IN (0, 1)),
            confidence REAL NOT NULL DEFAULT 0
                CHECK(confidence BETWEEN 0 AND 1),
            relationship TEXT NOT NULL DEFAULT 'none'
                CHECK(length(relationship) <= 80),
            explanation_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(explanation_json) <= 32768),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(score_revision_id, recipe_ingredient_id),
            FOREIGN KEY (score_revision_id)
                REFERENCES recipe_score_revisions(id) ON DELETE CASCADE,
            FOREIGN KEY (recipe_mapping_id)
                REFERENCES ingredient_ontology_mappings(id) ON DELETE SET NULL,
            FOREIGN KEY (inventory_mapping_id)
                REFERENCES ingredient_ontology_mappings(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_provider_terms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            connector TEXT NOT NULL CHECK(length(connector) BETWEEN 1 AND 80),
            metadata_schema_version TEXT NOT NULL
                CHECK(length(metadata_schema_version) BETWEEN 1 AND 80),
            namespace TEXT NOT NULL CHECK(length(namespace) BETWEEN 1 AND 160),
            provider_ref TEXT NOT NULL
                CHECK(length(provider_ref) BETWEEN 1 AND 200),
            default_title TEXT DEFAULT NULL
                CHECK(default_title IS NULL OR length(default_title) <= 200),
            normalized_default_title TEXT DEFAULT NULL
                CHECK(
                    normalized_default_title IS NULL
                    OR length(normalized_default_title) <= 200
                ),
            title_hash TEXT DEFAULT NULL
                CHECK(title_hash IS NULL OR length(title_hash) = 64),
            observed_row_count INTEGER NOT NULL DEFAULT 0
                CHECK(observed_row_count >= 0),
            distinct_title_count INTEGER NOT NULL DEFAULT 0
                CHECK(distinct_title_count >= 0),
            first_seen_at DATETIME DEFAULT NULL,
            last_seen_at DATETIME DEFAULT NULL,
            consistency_state TEXT NOT NULL
                CHECK(consistency_state IN (
                    'consistent', 'variant', 'conflicted', 'missing'
                )),
            is_generic INTEGER NOT NULL DEFAULT 0 CHECK(is_generic IN (0, 1)),
            mapping_status TEXT NOT NULL
                CHECK(mapping_status IN (
                    'accepted', 'candidate', 'ambiguous',
                    'unresolved', 'rejected'
                )),
            review_state TEXT NOT NULL DEFAULT 'pending'
                CHECK(review_state IN (
                    'pending', 'accepted', 'rejected', 'quarantined'
                )),
            entity_id INTEGER DEFAULT NULL,
            attributes_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(attributes_json) <= 16384),
            evidence_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(evidence_json) <= 32768),
            provenance TEXT NOT NULL DEFAULT 'provider_observation'
                CHECK(length(provenance) <= 120),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(
                ontology_version_id, connector, metadata_schema_version,
                namespace, provider_ref
            ),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (entity_id)
                REFERENCES ingredient_ontology_entities(id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_provider_observations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            provider_term_id INTEGER DEFAULT NULL,
            mapping_id INTEGER DEFAULT NULL,
            owner_type TEXT NOT NULL
                CHECK(owner_type = 'recipe_source_ingredient'),
            owner_id INTEGER NOT NULL CHECK(owner_id > 0),
            owner_fingerprint TEXT NOT NULL CHECK(length(owner_fingerprint) = 64),
            recipe_id INTEGER NOT NULL CHECK(recipe_id > 0),
            connector TEXT NOT NULL CHECK(length(connector) BETWEEN 1 AND 80),
            metadata_schema_version TEXT NOT NULL
                CHECK(length(metadata_schema_version) BETWEEN 1 AND 80),
            namespace TEXT NOT NULL CHECK(length(namespace) BETWEEN 1 AND 160),
            provider_ref TEXT DEFAULT NULL
                CHECK(provider_ref IS NULL OR length(provider_ref) <= 200),
            default_title TEXT DEFAULT NULL
                CHECK(default_title IS NULL OR length(default_title) <= 200),
            normalized_default_title TEXT DEFAULT NULL
                CHECK(
                    normalized_default_title IS NULL
                    OR length(normalized_default_title) <= 200
                ),
            title_hash TEXT DEFAULT NULL
                CHECK(title_hash IS NULL OR length(title_hash) = 64),
            local_label TEXT NOT NULL CHECK(length(local_label) <= 200),
            normalized_local_label TEXT NOT NULL
                CHECK(length(normalized_local_label) <= 200),
            local_label_hash TEXT NOT NULL CHECK(length(local_label_hash) = 64),
            consistency_state TEXT NOT NULL
                CHECK(consistency_state IN (
                    'consistent', 'variant', 'conflicted', 'missing'
                )),
            ref_provenance TEXT NOT NULL
                CHECK(ref_provenance IN (
                    'persisted_source_ingredient_ref',
                    'unknown_legacy_adapter'
                )),
            group_index INTEGER DEFAULT NULL,
            group_position INTEGER DEFAULT NULL,
            source_position INTEGER NOT NULL CHECK(source_position >= 0),
            observed_first_at DATETIME DEFAULT NULL,
            observed_last_at DATETIME DEFAULT NULL,
            evidence_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(evidence_json) <= 32768),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(ontology_version_id, owner_type, owner_id),
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id) ON DELETE CASCADE,
            FOREIGN KEY (provider_term_id)
                REFERENCES ingredient_ontology_provider_terms(id)
                    ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_requirement_revisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ontology_version_id INTEGER NOT NULL,
            parent_revision_id INTEGER DEFAULT NULL,
            projection_model TEXT NOT NULL
                CHECK(length(projection_model) BETWEEN 1 AND 80),
            status TEXT NOT NULL DEFAULT 'building'
                CHECK(status IN ('building', 'ready', 'failed', 'retired')),
            source_corpus_hash TEXT NOT NULL CHECK(length(source_corpus_hash) = 64),
            input_snapshot_hash TEXT NOT NULL
                DEFAULT '0000000000000000000000000000000000000000000000000000000000000000'
                CHECK(length(input_snapshot_hash) = 64),
            ontology_content_hash TEXT NOT NULL
                CHECK(length(ontology_content_hash) = 64),
            mapping_hash TEXT NOT NULL CHECK(length(mapping_hash) = 64),
            requirement_rows_hash TEXT DEFAULT NULL
                CHECK(
                    requirement_rows_hash IS NULL
                    OR length(requirement_rows_hash) = 64
                ),
            requirement_member_rows_hash TEXT DEFAULT NULL
                CHECK(
                    requirement_member_rows_hash IS NULL
                    OR length(requirement_member_rows_hash) = 64
                ),
            requirement_recipe_state_rows_hash TEXT DEFAULT NULL
                CHECK(
                    requirement_recipe_state_rows_hash IS NULL
                    OR length(requirement_recipe_state_rows_hash) = 64
                ),
            materialization_hash TEXT DEFAULT NULL
                CHECK(
                    materialization_hash IS NULL
                    OR length(materialization_hash) = 64
                ),
            recipe_count INTEGER NOT NULL DEFAULT 0 CHECK(recipe_count >= 0),
            requirement_count INTEGER NOT NULL DEFAULT 0
                CHECK(requirement_count >= 0),
            member_count INTEGER NOT NULL DEFAULT 0 CHECK(member_count >= 0),
            source_recipe_count INTEGER NOT NULL DEFAULT 0
                CHECK(source_recipe_count >= 0),
            legacy_recipe_count INTEGER NOT NULL DEFAULT 0
                CHECK(legacy_recipe_count >= 0),
            incomplete_recipe_count INTEGER NOT NULL DEFAULT 0
                CHECK(incomplete_recipe_count >= 0),
            validation_report_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(validation_report_json) <= 262144),
            last_error TEXT NOT NULL DEFAULT ''
                CHECK(length(last_error) <= 1000),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL,
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id),
            FOREIGN KEY (parent_revision_id)
                REFERENCES ingredient_ontology_requirement_revisions(id)
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_requirement_input_recipes (
            requirement_revision_id INTEGER NOT NULL,
            ontology_version_id INTEGER NOT NULL,
            recipe_id INTEGER NOT NULL CHECK(recipe_id > 0),
            basis TEXT NOT NULL CHECK(basis IN ('source', 'legacy')),
            payload_json TEXT NOT NULL CHECK(length(payload_json) <= 32768),
            payload_hash TEXT NOT NULL CHECK(length(payload_hash) = 64),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(requirement_revision_id, recipe_id),
            FOREIGN KEY (requirement_revision_id)
                REFERENCES ingredient_ontology_requirement_revisions(id)
                    ON DELETE CASCADE,
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id)
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_requirement_input_rows (
            requirement_revision_id INTEGER NOT NULL,
            ontology_version_id INTEGER NOT NULL,
            owner_type TEXT NOT NULL
                CHECK(owner_type IN (
                    'recipe_ingredient', 'recipe_source_ingredient'
                )),
            owner_id INTEGER NOT NULL CHECK(owner_id > 0),
            recipe_id INTEGER NOT NULL CHECK(recipe_id > 0),
            source_position INTEGER NOT NULL CHECK(source_position >= 0),
            payload_json TEXT NOT NULL CHECK(length(payload_json) <= 65536),
            payload_hash TEXT NOT NULL CHECK(length(payload_hash) = 64),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(requirement_revision_id, owner_type, owner_id),
            FOREIGN KEY (requirement_revision_id)
                REFERENCES ingredient_ontology_requirement_revisions(id)
                    ON DELETE CASCADE,
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id)
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_recipe_requirements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            requirement_revision_id INTEGER NOT NULL,
            ontology_version_id INTEGER NOT NULL,
            recipe_id INTEGER NOT NULL CHECK(recipe_id > 0),
            requirement_key TEXT NOT NULL CHECK(length(requirement_key) = 64),
            basis TEXT NOT NULL
                CHECK(basis IN ('source', 'source_incomplete', 'legacy')),
            entity_id INTEGER DEFAULT NULL,
            mapping_status TEXT NOT NULL
                CHECK(mapping_status IN (
                    'accepted', 'candidate', 'ambiguous',
                    'unresolved', 'rejected'
                )),
            mapping_source TEXT NOT NULL CHECK(length(mapping_source) <= 80),
            confidence REAL NOT NULL DEFAULT 0
                CHECK(confidence BETWEEN 0 AND 1),
            identity_basis TEXT NOT NULL CHECK(length(identity_basis) <= 80),
            attributes_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(attributes_json) <= 16384),
            defining_signature TEXT NOT NULL CHECK(length(defining_signature) = 64),
            requiredness TEXT NOT NULL
                CHECK(requiredness IN ('required', 'optional', 'uncertain')),
            is_staple INTEGER NOT NULL DEFAULT 0 CHECK(is_staple IN (0, 1)),
            contributor_count INTEGER NOT NULL DEFAULT 0
                CHECK(contributor_count > 0),
            provider_ref_count INTEGER NOT NULL DEFAULT 0
                CHECK(provider_ref_count >= 0),
            quantity_audit_state TEXT NOT NULL DEFAULT 'none'
                CHECK(quantity_audit_state IN (
                    'none', 'single_unit', 'mixed_units',
                    'mixed_known_unknown'
                )),
            evidence_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(evidence_json) <= 32768),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(requirement_revision_id, recipe_id, requirement_key),
            FOREIGN KEY (requirement_revision_id)
                REFERENCES ingredient_ontology_requirement_revisions(id)
                    ON DELETE CASCADE,
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id),
            FOREIGN KEY (entity_id)
                REFERENCES ingredient_ontology_entities(id)
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_requirement_recipe_states (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            requirement_revision_id INTEGER NOT NULL,
            ontology_version_id INTEGER NOT NULL,
            recipe_id INTEGER NOT NULL CHECK(recipe_id > 0),
            basis TEXT NOT NULL
                CHECK(basis IN ('source', 'source_incomplete', 'legacy')),
            complete INTEGER NOT NULL CHECK(complete IN (0, 1)),
            source_row_count INTEGER NOT NULL DEFAULT 0
                CHECK(source_row_count >= 0),
            projected_member_count INTEGER NOT NULL DEFAULT 0
                CHECK(projected_member_count >= 0),
            projected_requirement_count INTEGER NOT NULL DEFAULT 0
                CHECK(projected_requirement_count >= 0),
            recipe_fingerprint TEXT NOT NULL CHECK(length(recipe_fingerprint) = 64),
            evidence_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(evidence_json) <= 32768),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(requirement_revision_id, recipe_id),
            FOREIGN KEY (requirement_revision_id)
                REFERENCES ingredient_ontology_requirement_revisions(id)
                    ON DELETE CASCADE,
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id)
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_requirement_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            requirement_revision_id INTEGER NOT NULL,
            requirement_id INTEGER NOT NULL,
            ontology_version_id INTEGER NOT NULL,
            owner_type TEXT NOT NULL
                CHECK(owner_type IN (
                    'recipe_ingredient', 'recipe_source_ingredient'
                )),
            owner_id INTEGER NOT NULL CHECK(owner_id > 0),
            owner_fingerprint TEXT NOT NULL CHECK(length(owner_fingerprint) = 64),
            mapping_id INTEGER DEFAULT NULL,
            provider_term_id INTEGER DEFAULT NULL,
            source_position INTEGER NOT NULL CHECK(source_position >= 0),
            group_index INTEGER DEFAULT NULL,
            group_position INTEGER DEFAULT NULL,
            provider_ref TEXT DEFAULT NULL
                CHECK(provider_ref IS NULL OR length(provider_ref) <= 200),
            default_title TEXT DEFAULT NULL
                CHECK(default_title IS NULL OR length(default_title) <= 200),
            title_hash TEXT DEFAULT NULL
                CHECK(title_hash IS NULL OR length(title_hash) = 64),
            source_label TEXT NOT NULL CHECK(length(source_label) <= 200),
            source_label_hash TEXT NOT NULL CHECK(length(source_label_hash) = 64),
            source_optional INTEGER DEFAULT NULL
                CHECK(source_optional IS NULL OR source_optional IN (0, 1)),
            source_quantity REAL DEFAULT NULL,
            source_quantity_max REAL DEFAULT NULL,
            source_unit TEXT DEFAULT NULL CHECK(source_unit IS NULL OR length(source_unit) <= 80),
            source_amount_text TEXT DEFAULT NULL
                CHECK(source_amount_text IS NULL OR length(source_amount_text) <= 160),
            quantity_state TEXT NOT NULL DEFAULT 'display_only'
                CHECK(quantity_state IN ('none', 'display_only')),
            evidence_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(evidence_json) <= 32768),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(requirement_revision_id, owner_type, owner_id),
            FOREIGN KEY (requirement_revision_id)
                REFERENCES ingredient_ontology_requirement_revisions(id)
                    ON DELETE CASCADE,
            FOREIGN KEY (requirement_id)
                REFERENCES ingredient_ontology_recipe_requirements(id)
                    ON DELETE CASCADE,
            FOREIGN KEY (ontology_version_id)
                REFERENCES ingredient_ontology_versions(id)
        );

        CREATE TABLE IF NOT EXISTS ingredient_ontology_shadow_requirement_matches (
            score_revision_id INTEGER NOT NULL,
            requirement_id INTEGER NOT NULL,
            requirement_revision_id INTEGER NOT NULL,
            inventory_product_id INTEGER DEFAULT NULL,
            inventory_mapping_id INTEGER DEFAULT NULL,
            outcome TEXT NOT NULL CHECK(length(outcome) <= 80),
            satisfies_required INTEGER NOT NULL DEFAULT 0
                CHECK(satisfies_required IN (0, 1)),
            confidence REAL NOT NULL DEFAULT 0
                CHECK(confidence BETWEEN 0 AND 1),
            relationship TEXT NOT NULL DEFAULT 'none'
                CHECK(length(relationship) <= 80),
            explanation_json TEXT NOT NULL DEFAULT '{}'
                CHECK(length(explanation_json) <= 32768),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(score_revision_id, requirement_id),
            FOREIGN KEY (score_revision_id)
                REFERENCES recipe_score_revisions(id) ON DELETE CASCADE,
            FOREIGN KEY (requirement_id)
                REFERENCES ingredient_ontology_recipe_requirements(id),
            FOREIGN KEY (requirement_revision_id)
                REFERENCES ingredient_ontology_requirement_revisions(id),
            FOREIGN KEY (inventory_mapping_id)
                REFERENCES ingredient_ontology_mappings(id) ON DELETE SET NULL
        );

        CREATE UNIQUE INDEX IF NOT EXISTS idx_ontology_labels_identity
            ON ingredient_ontology_labels(
                ontology_version_id, language, normalized_label
            )
            WHERE review_state = 'accepted'
              AND kind IN ('exact_alias', 'attribute_alias');
        CREATE INDEX IF NOT EXISTS idx_ontology_labels_lookup
            ON ingredient_ontology_labels(
                ontology_version_id, normalized_label, review_state, kind
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_entities_version_active
            ON ingredient_ontology_entities(
                ontology_version_id, active, slug
            );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_ontology_primary_parent
            ON ingredient_ontology_relations(
                ontology_version_id, from_entity_id
            )
            WHERE relation = 'is_a' AND is_primary = 1
              AND review_state = 'accepted';
        CREATE INDEX IF NOT EXISTS idx_ontology_relations_from
            ON ingredient_ontology_relations(
                ontology_version_id, from_entity_id, relation, review_state
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_relations_to
            ON ingredient_ontology_relations(
                ontology_version_id, to_entity_id, relation, review_state
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_mappings_owner
            ON ingredient_ontology_mappings(
                ontology_version_id, owner_type, owner_id
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_mappings_entity
            ON ingredient_ontology_mappings(
                ontology_version_id, entity_id, status, owner_type
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_mappings_audit
            ON ingredient_ontology_mappings(
                ontology_version_id, owner_type, status,
                mapping_source, normalized_label
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_mapping_attributes_mapping
            ON ingredient_ontology_mapping_attributes(mapping_id, facet_id);
        CREATE INDEX IF NOT EXISTS idx_ontology_disposition_scope
            ON ingredient_ontology_disposition_scopes(
                ontology_version_id, scope_type, normalized_label, language
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_terminal_disposition_code
            ON ingredient_ontology_terminal_dispositions(
                ontology_version_id, disposition_code, mechanism
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_evidence_kind
            ON ingredient_ontology_evidence_sources(
                ontology_version_id, evidence_kind, evidence_key
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_cohort
            ON ingredient_ontology_recipe_cohorts(
                ontology_version_id, cohort, recipe_id
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_edge_review
            ON ingredient_ontology_primary_edge_reviews(
                ontology_version_id, change_kind, disposition
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_entity_facet_policy
            ON ingredient_ontology_entity_facet_policies(
                ontology_version_id, entity_id, allowed, defining
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_review_import_rows
            ON ingredient_ontology_review_import_rows(
                ontology_version_id, disposition_code, scope_fingerprint
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_assertion_history_owner
            ON ingredient_ontology_mapping_assertion_history(
                ontology_version_id, owner_type, owner_fingerprint, phase
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_curated_products_status
            ON ingredient_ontology_curated_product_assertions(
                ontology_version_id, status, review_state, product_id
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_curated_provider_disposition
            ON ingredient_ontology_curated_provider_reviews(
                ontology_version_id, disposition, provider_ref
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_curated_conflict_disposition
            ON ingredient_ontology_curated_provider_conflict_reviews(
                ontology_version_id, disposition, mapping_id
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_change_sets_review
            ON ingredient_ontology_change_sets(
                ontology_version_id, review_state, id
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_proposals_review
            ON ingredient_ontology_proposals(
                change_set_id, review_state, merge_key
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_change_events_set
            ON ingredient_ontology_change_events(
                change_set_id, created_at, id
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_shadow_outcome
            ON ingredient_ontology_shadow_matches(
                score_revision_id, outcome, satisfies_required
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_shadow_mapping
            ON ingredient_ontology_shadow_matches(
                score_revision_id, recipe_mapping_id
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_provider_terms_scope
            ON ingredient_ontology_provider_terms(
                ontology_version_id, connector, metadata_schema_version,
                consistency_state, mapping_status, review_state
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_provider_terms_title
            ON ingredient_ontology_provider_terms(
                ontology_version_id, normalized_default_title, connector
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_provider_observations_term
            ON ingredient_ontology_provider_observations(
                ontology_version_id, provider_term_id, recipe_id
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_provider_observations_local
            ON ingredient_ontology_provider_observations(
                ontology_version_id, normalized_local_label, provider_ref
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_requirement_revisions_ready
            ON ingredient_ontology_requirement_revisions(
                ontology_version_id, status, completed_at DESC, id DESC
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_requirements_recipe
            ON ingredient_ontology_recipe_requirements(
                requirement_revision_id, recipe_id, id
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_requirement_recipe_states_basis
            ON ingredient_ontology_requirement_recipe_states(
                requirement_revision_id, basis, complete, recipe_id
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_requirement_input_rows_recipe
            ON ingredient_ontology_requirement_input_rows(
                requirement_revision_id, owner_type, recipe_id, owner_id
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_requirements_identity
            ON ingredient_ontology_recipe_requirements(
                requirement_revision_id, entity_id, defining_signature,
                requiredness
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_requirement_members_requirement
            ON ingredient_ontology_requirement_members(
                requirement_revision_id, requirement_id, id
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_requirement_members_owner
            ON ingredient_ontology_requirement_members(
                requirement_revision_id, owner_type, owner_id
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_shadow_requirement_outcome
            ON ingredient_ontology_shadow_requirement_matches(
                score_revision_id, outcome, satisfies_required
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_shadow_requirement_revision
            ON ingredient_ontology_shadow_requirement_matches(
                requirement_revision_id, score_revision_id
            );

        CREATE TRIGGER IF NOT EXISTS ingredient_ontology_change_sets_hash_immutable
        BEFORE UPDATE OF input_hash, prompt_hash, model_hash, schema_hash
        ON ingredient_ontology_change_sets
        BEGIN
            SELECT RAISE(ABORT, 'ontology change-set hashes are immutable');
        END;

        CREATE TRIGGER IF NOT EXISTS ingredient_ontology_change_events_immutable_update
        BEFORE UPDATE ON ingredient_ontology_change_events
        BEGIN
            SELECT RAISE(ABORT, 'ontology lifecycle events are immutable');
        END;

        CREATE TRIGGER IF NOT EXISTS ingredient_ontology_change_events_immutable_delete
        BEFORE DELETE ON ingredient_ontology_change_events
        BEGIN
            SELECT RAISE(ABORT, 'ontology lifecycle events are append-only');
        END;

        CREATE TRIGGER IF NOT EXISTS
            ingredient_ontology_resolution_manifests_immutable_update
        BEFORE UPDATE ON ingredient_ontology_resolution_manifests
        BEGIN
            SELECT RAISE(ABORT, 'resolution manifests are immutable');
        END;

        CREATE TRIGGER IF NOT EXISTS
            ingredient_ontology_resolution_manifests_immutable_delete
        BEFORE DELETE ON ingredient_ontology_resolution_manifests
        BEGIN
            SELECT RAISE(ABORT, 'resolution manifests are immutable');
        END;

        CREATE TRIGGER IF NOT EXISTS
            ingredient_ontology_evidence_sources_immutable_update
        BEFORE UPDATE ON ingredient_ontology_evidence_sources
        BEGIN
            SELECT RAISE(ABORT, 'ontology evidence sources are immutable');
        END;

        CREATE TRIGGER IF NOT EXISTS
            ingredient_ontology_evidence_sources_immutable_delete
        BEFORE DELETE ON ingredient_ontology_evidence_sources
        BEGIN
            SELECT RAISE(ABORT, 'ontology evidence sources are immutable');
        END;

        CREATE TRIGGER IF NOT EXISTS
            ingredient_ontology_disposition_scopes_immutable_update
        BEFORE UPDATE ON ingredient_ontology_disposition_scopes
        BEGIN
            SELECT RAISE(ABORT, 'ontology disposition scopes are immutable');
        END;

        CREATE TRIGGER IF NOT EXISTS
            ingredient_ontology_disposition_scopes_immutable_delete
        BEFORE DELETE ON ingredient_ontology_disposition_scopes
        BEGIN
            SELECT RAISE(ABORT, 'ontology disposition scopes are immutable');
        END;

        CREATE TRIGGER IF NOT EXISTS
            ingredient_ontology_terminal_dispositions_immutable_update
        BEFORE UPDATE ON ingredient_ontology_terminal_dispositions
        BEGIN
            SELECT RAISE(ABORT, 'terminal ontology dispositions are immutable');
        END;

        CREATE TRIGGER IF NOT EXISTS
            ingredient_ontology_terminal_dispositions_immutable_delete
        BEFORE DELETE ON ingredient_ontology_terminal_dispositions
        BEGIN
            SELECT RAISE(ABORT, 'terminal ontology dispositions are immutable');
        END;

        CREATE TRIGGER IF NOT EXISTS
            ingredient_ontology_review_import_rows_immutable_update
        BEFORE UPDATE ON ingredient_ontology_review_import_rows
        BEGIN
            SELECT RAISE(ABORT, 'ontology review import rows are immutable');
        END;

        CREATE TRIGGER IF NOT EXISTS
            ingredient_ontology_review_import_rows_immutable_delete
        BEFORE DELETE ON ingredient_ontology_review_import_rows
        BEGIN
            SELECT RAISE(ABORT, 'ontology review import rows are immutable');
        END;

        CREATE TRIGGER IF NOT EXISTS ingredient_ontology_requirement_revision_immutable_update
        BEFORE UPDATE ON ingredient_ontology_requirement_revisions
        WHEN OLD.status = 'ready'
        BEGIN
            SELECT RAISE(ABORT, 'ready requirement revisions are immutable');
        END;

        CREATE TRIGGER IF NOT EXISTS ingredient_ontology_requirement_revision_immutable_delete
        BEFORE DELETE ON ingredient_ontology_requirement_revisions
        WHEN OLD.status = 'ready'
        BEGIN
            SELECT RAISE(ABORT, 'ready requirement revisions cannot be deleted');
        END;

        CREATE TRIGGER IF NOT EXISTS ingredient_ontology_requirements_immutable_update
        BEFORE UPDATE ON ingredient_ontology_recipe_requirements
        WHEN EXISTS (
            SELECT 1 FROM ingredient_ontology_requirement_revisions r
            WHERE r.id = OLD.requirement_revision_id
              AND r.status = 'ready'
        )
        BEGIN
            SELECT RAISE(ABORT, 'ready recipe requirements are immutable');
        END;

        CREATE TRIGGER IF NOT EXISTS ingredient_ontology_requirements_immutable_delete
        BEFORE DELETE ON ingredient_ontology_recipe_requirements
        WHEN EXISTS (
            SELECT 1 FROM ingredient_ontology_requirement_revisions r
            WHERE r.id = OLD.requirement_revision_id
              AND r.status = 'ready'
        )
        BEGIN
            SELECT RAISE(ABORT, 'ready recipe requirements cannot be deleted');
        END;

        CREATE TRIGGER IF NOT EXISTS ingredient_ontology_requirement_members_immutable_update
        BEFORE UPDATE ON ingredient_ontology_requirement_members
        WHEN EXISTS (
            SELECT 1 FROM ingredient_ontology_requirement_revisions r
            WHERE r.id = OLD.requirement_revision_id
              AND r.status = 'ready'
        )
        BEGIN
            SELECT RAISE(ABORT, 'ready requirement members are immutable');
        END;

        CREATE TRIGGER IF NOT EXISTS ingredient_ontology_requirement_members_immutable_delete
        BEFORE DELETE ON ingredient_ontology_requirement_members
        WHEN EXISTS (
            SELECT 1 FROM ingredient_ontology_requirement_revisions r
            WHERE r.id = OLD.requirement_revision_id
              AND r.status = 'ready'
        )
        BEGIN
            SELECT RAISE(ABORT, 'ready requirement members cannot be deleted');
        END;

        CREATE TRIGGER IF NOT EXISTS ingredient_ontology_requirement_recipe_states_immutable_update
        BEFORE UPDATE ON ingredient_ontology_requirement_recipe_states
        WHEN EXISTS (
            SELECT 1 FROM ingredient_ontology_requirement_revisions r
            WHERE r.id = OLD.requirement_revision_id
              AND r.status = 'ready'
        )
        BEGIN
            SELECT RAISE(ABORT, 'ready requirement recipe states are immutable');
        END;

        CREATE TRIGGER IF NOT EXISTS ingredient_ontology_requirement_recipe_states_immutable_delete
        BEFORE DELETE ON ingredient_ontology_requirement_recipe_states
        WHEN EXISTS (
            SELECT 1 FROM ingredient_ontology_requirement_revisions r
            WHERE r.id = OLD.requirement_revision_id
              AND r.status = 'ready'
        )
        BEGIN
            SELECT RAISE(ABORT, 'ready requirement recipe states cannot be deleted');
        END;
    ");

    ingredientOntologyV3MigrateImmutableTriggers($db);

    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_versions',
        'model_name',
        "TEXT NOT NULL DEFAULT 'gemini-3.5-flash'"
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_versions',
        'content_hash',
        "TEXT NOT NULL DEFAULT '"
            . str_repeat('0', 64)
            . "'"
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_versions',
        'activation_policy',
        "TEXT NOT NULL DEFAULT 'blocked' CHECK(activation_policy IN ("
            . "'blocked','manual_review','test_only'))"
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_versions',
        'activation_block_reason',
        "TEXT NOT NULL DEFAULT 'full ontology resolution remains shadow-only'"
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_versions',
        'corpus_profile',
        "TEXT NOT NULL DEFAULT 'test' CHECK(corpus_profile IN ("
            . "'eval','provider','production','test'))"
    );
    foreach ([
        'frozen_corpus_hash',
        'frozen_subjects_hash',
        'policy_hash',
    ] as $frozenHashColumn) {
        ingredientOntologyV3AddColumn(
            $db,
            'ingredient_ontology_versions',
            $frozenHashColumn,
            "TEXT NOT NULL DEFAULT '"
                . str_repeat('0', 64)
                . "' CHECK(length({$frozenHashColumn}) = 64)"
        );
    }
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_versions',
        'portable_content_hash',
        "TEXT NOT NULL DEFAULT '"
            . str_repeat('0', 64)
            . "' CHECK(length(portable_content_hash) = 64)"
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_versions',
        'review_manifest_hash',
        "TEXT NOT NULL DEFAULT '"
            . str_repeat('0', 64)
            . "' CHECK(length(review_manifest_hash) = 64)"
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_versions',
        'resolution_gold_hash',
        "TEXT NOT NULL DEFAULT '"
            . str_repeat('0', 64)
            . "' CHECK(length(resolution_gold_hash) = 64)"
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_versions',
        'seal_hash',
        "TEXT NOT NULL DEFAULT '"
            . str_repeat('0', 64)
            . "' CHECK(length(seal_hash) = 64)"
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_entities',
        'identity_role',
        "TEXT NOT NULL DEFAULT 'identity_leaf' CHECK(identity_role IN ("
            . "'structural_category','identity_leaf','prepared_identity',"
            . "'composite_identity','staple_class'))"
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_evidence_sources',
        'evidence_scope',
        "TEXT NOT NULL DEFAULT 'global_review' CHECK(evidence_scope IN ("
            . "'global_review','owner_observation'))"
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_evidence_sources',
        'owner_fingerprint',
        'TEXT DEFAULT NULL'
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_evidence_sources',
        'connector',
        'TEXT DEFAULT NULL'
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_evidence_sources',
        'metadata_schema_version',
        'TEXT DEFAULT NULL'
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_evidence_sources',
        'provider_ref',
        'TEXT DEFAULT NULL'
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_evidence_sources',
        'title_hash',
        'TEXT DEFAULT NULL'
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_evidence_sources',
        'observation_hash',
        'TEXT DEFAULT NULL'
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_mappings',
        'provider_term_id',
        'INTEGER DEFAULT NULL REFERENCES '
            . 'ingredient_ontology_provider_terms(id) ON DELETE SET NULL'
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_mappings',
        'identity_basis',
        "TEXT NOT NULL DEFAULT 'local_label' CHECK(identity_basis IN ("
            . "'local_label','provider_term','provider_plus_local_attributes',"
            . "'provider_local_conflict','provider_variant',"
            . "'provider_candidate','provider_missing',"
            . "'unknown_legacy_adapter'))"
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_mappings',
        'terminal_disposition_id',
        'INTEGER DEFAULT NULL REFERENCES '
            . 'ingredient_ontology_terminal_dispositions(id)'
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_provider_terms',
        'terminal_disposition_id',
        'INTEGER DEFAULT NULL REFERENCES '
            . 'ingredient_ontology_terminal_dispositions(id)'
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_curated_product_assertions',
        'terminal_disposition_id',
        'INTEGER DEFAULT NULL REFERENCES '
            . 'ingredient_ontology_terminal_dispositions(id)'
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_requirement_revisions',
        'input_snapshot_hash',
        "TEXT NOT NULL DEFAULT '" . str_repeat('0', 64) . "'"
    );
    foreach ([
        'requirement_rows_hash',
        'requirement_member_rows_hash',
        'requirement_recipe_state_rows_hash',
        'materialization_hash',
    ] as $hashColumn) {
        ingredientOntologyV3AddColumn(
            $db,
            'ingredient_ontology_requirement_revisions',
            $hashColumn,
            'TEXT DEFAULT NULL CHECK('
                . $hashColumn . ' IS NULL OR length('
                . $hashColumn . ') = 64)'
        );
    }
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_disposition_scopes',
        'portable_scope_hash',
        "TEXT NOT NULL DEFAULT '" . str_repeat('0', 64) . "'"
    );
    ingredientOntologyV3AddColumn(
        $db,
        'ingredient_ontology_terminal_dispositions',
        'portable_disposition_hash',
        "TEXT NOT NULL DEFAULT '" . str_repeat('0', 64) . "'"
    );
    $db->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS
            idx_ontology_disposition_portable_scope
            ON ingredient_ontology_disposition_scopes(
                ontology_version_id, portable_scope_hash
            )
            WHERE portable_scope_hash <> '" . str_repeat('0', 64) . "'
    ");
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_ontology_mappings_provider_term
            ON ingredient_ontology_mappings(
                ontology_version_id, provider_term_id, identity_basis
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_mappings_disposition
            ON ingredient_ontology_mappings(
                ontology_version_id, terminal_disposition_id, owner_type
            );
        CREATE INDEX IF NOT EXISTS idx_ontology_provider_disposition
            ON ingredient_ontology_provider_terms(
                ontology_version_id, terminal_disposition_id
            )
    ");

    $scoreRevisionExists = (int)$db->query("
        SELECT COUNT(*) FROM sqlite_master
        WHERE type = 'table' AND name = 'recipe_score_revisions'
    ")->fetchColumn() > 0;
    if ($scoreRevisionExists) {
        ingredientOntologyV3AddColumn(
            $db,
            'recipe_score_revisions',
            'ontology_version_id',
            'INTEGER DEFAULT NULL'
        );
        ingredientOntologyV3AddColumn(
            $db,
            'recipe_score_revisions',
            'scoring_model',
            "TEXT NOT NULL DEFAULT 'legacy-v2'"
        );
        ingredientOntologyV3AddColumn(
            $db,
            'recipe_score_revisions',
            'parent_score_revision_id',
            'INTEGER DEFAULT NULL'
        );
        ingredientOntologyV3AddColumn(
            $db,
            'recipe_score_revisions',
            'validation_report_json',
            "TEXT NOT NULL DEFAULT '{}'"
        );
        ingredientOntologyV3AddColumn(
            $db,
            'recipe_score_revisions',
            'catalog_fingerprint',
            "TEXT NOT NULL DEFAULT ''"
        );
        ingredientOntologyV3AddColumn(
            $db,
            'recipe_score_revisions',
            'scoring_config_hash',
            'TEXT DEFAULT NULL CHECK('
                . 'scoring_config_hash IS NULL '
                . 'OR length(scoring_config_hash) = 64)'
        );
        ingredientOntologyV3AddColumn(
            $db,
            'recipe_score_revisions',
            'ontology_source_revision',
            'INTEGER NOT NULL DEFAULT 1'
        );
        ingredientOntologyV3AddColumn(
            $db,
            'recipe_score_revisions',
            'ontology_source_hash',
            "TEXT NOT NULL DEFAULT ''"
        );
        foreach ([
            'ontology_schema_hash',
            'ontology_prompt_hash',
            'ontology_model_hash',
            'ontology_corpus_hash',
            'ontology_content_hash',
            'ontology_portable_content_hash',
            'ontology_review_manifest_hash',
            'ontology_resolution_gold_hash',
            'ontology_seal_hash',
        ] as $hashColumn) {
            ingredientOntologyV3AddColumn(
                $db,
                'recipe_score_revisions',
                $hashColumn,
                'TEXT DEFAULT NULL'
            );
        }
        ingredientOntologyV3AddColumn(
            $db,
            'recipe_score_revisions',
            'requirement_revision_id',
            'INTEGER DEFAULT NULL REFERENCES '
                . 'ingredient_ontology_requirement_revisions(id)'
        );
        ingredientOntologyV3AddColumn(
            $db,
            'recipe_score_revisions',
            'requirement_model',
            'TEXT DEFAULT NULL CHECK('
                . 'requirement_model IS NULL '
                . 'OR length(requirement_model) <= 80)'
        );
        ingredientOntologyV3AddColumn(
            $db,
            'recipe_score_revisions',
            'parity_baseline_score_revision_id',
            'INTEGER DEFAULT NULL REFERENCES recipe_score_revisions(id)'
        );
        foreach ([
            'catalog_id_set_hash',
            'ingredient_id_set_hash',
            'requirement_recipe_id_set_hash',
            'requirement_id_set_hash',
            'score_rows_hash',
            'match_rows_hash',
            'materialization_hash',
        ] as $setHashColumn) {
            ingredientOntologyV3AddColumn(
                $db,
                'recipe_score_revisions',
                $setHashColumn,
                'TEXT DEFAULT NULL CHECK('
                    . $setHashColumn . ' IS NULL OR length('
                    . $setHashColumn . ') = 64)'
            );
        }
        ingredientOntologyV3EnsureParityBaselineForeignKey($db);
        $readyGuardWasEnabled =
            ingredientOntologyV3ReadyMutationGuardEnabled($db);
        ingredientOntologyV3SetReadyMutationGuard($db, true);
        try {
            $db->exec("
                UPDATE recipe_score_revisions
                SET parity_baseline_score_revision_id = NULL
                WHERE parity_baseline_score_revision_id IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1
                      FROM recipe_score_revisions baseline
                      WHERE baseline.id =
                          recipe_score_revisions
                              .parity_baseline_score_revision_id
                  );
                CREATE INDEX IF NOT EXISTS
                    idx_recipe_score_revisions_parity_baseline
                    ON recipe_score_revisions(
                        parity_baseline_score_revision_id
                    );
                CREATE INDEX IF NOT EXISTS idx_recipe_score_revisions_ready
                    ON recipe_score_revisions(
                        status, completed_at DESC, id DESC
                    );
                CREATE TRIGGER IF NOT EXISTS
                    recipe_score_revisions_parity_baseline_insert
                BEFORE INSERT ON recipe_score_revisions
                WHEN NEW.parity_baseline_score_revision_id IS NOT NULL
                 AND NOT EXISTS (
                     SELECT 1 FROM recipe_score_revisions baseline
                     WHERE baseline.id =
                         NEW.parity_baseline_score_revision_id
                 )
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'invalid parity baseline revision'
                    );
                END;
                CREATE TRIGGER IF NOT EXISTS
                    recipe_score_revisions_parity_baseline_update
                BEFORE UPDATE OF parity_baseline_score_revision_id
                ON recipe_score_revisions
                WHEN NEW.parity_baseline_score_revision_id IS NOT NULL
                 AND NOT EXISTS (
                     SELECT 1 FROM recipe_score_revisions baseline
                     WHERE baseline.id =
                         NEW.parity_baseline_score_revision_id
                 )
                BEGIN
                    SELECT RAISE(
                        ABORT,
                        'invalid parity baseline revision'
                    );
                END;
                CREATE TRIGGER IF NOT EXISTS
                    recipe_score_revisions_parity_baseline_delete
                AFTER DELETE ON recipe_score_revisions
                BEGIN
                    UPDATE recipe_score_revisions
                    SET parity_baseline_score_revision_id = NULL
                    WHERE parity_baseline_score_revision_id = OLD.id;
                END;
                CREATE INDEX IF NOT EXISTS
                    idx_recipe_score_revisions_ontology
                    ON recipe_score_revisions(
                        ontology_version_id, scoring_model, status, id
                    );
                CREATE INDEX IF NOT EXISTS
                    idx_recipe_score_revisions_requirement
                    ON recipe_score_revisions(
                        requirement_revision_id, requirement_model,
                        status, id
                    );
            ");
        } finally {
            ingredientOntologyV3SetReadyMutationGuard(
                $db,
                $readyGuardWasEnabled
            );
        }
    }

    $inventoryScoresExist = (int)$db->query("
        SELECT COUNT(*) FROM sqlite_master
        WHERE type = 'table' AND name = 'recipe_inventory_scores'
    ")->fetchColumn() > 0;
    if ($inventoryScoresExist) {
        ingredientOntologyV3AddColumn(
            $db,
            'recipe_inventory_scores',
            'uncertain_required_count',
            'INTEGER NOT NULL DEFAULT 0'
        );
    }
    ingredientOntologyV3MigrateTriggerSet(
        $db,
        'identity_guard_trigger_version',
        'identity-guards-v3.17.1',
        [
            'ingredient_ontology_relations_no_identity_satisfaction_insert',
            'ingredient_ontology_relations_no_identity_satisfaction_update',
            'ingredient_ontology_mappings_attributes_json_insert',
            'ingredient_ontology_mappings_attributes_json_update',
            'ingredient_ontology_mapping_attributes_version_insert',
            'ingredient_ontology_mapping_attributes_version_update',
            'ingredient_ontology_mapping_attributes_actual_ready_insert',
            'ingredient_ontology_mapping_attributes_actual_ready_update',
            'ingredient_ontology_mapping_attributes_actual_ready_delete',
        ],
        static function (PDO $db): void {
    $db->exec("
        DROP TRIGGER IF EXISTS
            ingredient_ontology_relations_no_identity_satisfaction_insert;
        DROP TRIGGER IF EXISTS
            ingredient_ontology_relations_no_identity_satisfaction_update;
        CREATE TRIGGER
            ingredient_ontology_relations_no_identity_satisfaction_insert
        BEFORE INSERT ON ingredient_ontology_relations
        WHEN NEW.satisfies_required <> 0
        BEGIN
            SELECT RAISE(
                ABORT,
                'relations never satisfy identity across entities'
            );
        END;
        CREATE TRIGGER
            ingredient_ontology_relations_no_identity_satisfaction_update
        BEFORE UPDATE OF satisfies_required
        ON ingredient_ontology_relations
        WHEN NEW.satisfies_required <> 0
        BEGIN
            SELECT RAISE(
                ABORT,
                'relations never satisfy identity across entities'
            );
        END
    ");
    $db->exec("
        DROP TRIGGER IF EXISTS
            ingredient_ontology_mappings_attributes_json_insert;
        DROP TRIGGER IF EXISTS
            ingredient_ontology_mappings_attributes_json_update;
        DROP TRIGGER IF EXISTS
            ingredient_ontology_mapping_attributes_version_insert;
        DROP TRIGGER IF EXISTS
            ingredient_ontology_mapping_attributes_version_update;
        DROP TRIGGER IF EXISTS
            ingredient_ontology_mapping_attributes_actual_ready_insert;
        DROP TRIGGER IF EXISTS
            ingredient_ontology_mapping_attributes_actual_ready_update;
        DROP TRIGGER IF EXISTS
            ingredient_ontology_mapping_attributes_actual_ready_delete;

        CREATE TRIGGER
            ingredient_ontology_mappings_attributes_json_insert
        BEFORE INSERT ON ingredient_ontology_mappings
        WHEN CASE
            WHEN json_valid(NEW.attributes_json) = 0 THEN 1
            WHEN json_type(NEW.attributes_json) = 'object' THEN 0
            WHEN NEW.attributes_json = '[]' THEN 0
            ELSE 1
        END
        BEGIN
            SELECT RAISE(
                ABORT,
                'mapping attributes_json must be an object or []'
            );
        END;
        CREATE TRIGGER
            ingredient_ontology_mappings_attributes_json_update
        BEFORE UPDATE OF attributes_json
        ON ingredient_ontology_mappings
        WHEN CASE
            WHEN json_valid(NEW.attributes_json) = 0 THEN 1
            WHEN json_type(NEW.attributes_json) = 'object' THEN 0
            WHEN NEW.attributes_json = '[]' THEN 0
            ELSE 1
        END
        BEGIN
            SELECT RAISE(
                ABORT,
                'mapping attributes_json must be an object or []'
            );
        END;

        CREATE TRIGGER
            ingredient_ontology_mapping_attributes_version_insert
        BEFORE INSERT ON ingredient_ontology_mapping_attributes
        WHEN NOT EXISTS (
            SELECT 1
            FROM ingredient_ontology_mappings mapping
            JOIN ingredient_ontology_facets facet
              ON facet.id = NEW.facet_id
            JOIN ingredient_ontology_facet_values value
              ON value.id = NEW.facet_value_id
             AND value.facet_id = facet.id
            WHERE mapping.id = NEW.mapping_id
              AND mapping.ontology_version_id =
                  NEW.ontology_version_id
              AND facet.ontology_version_id =
                  NEW.ontology_version_id
              AND value.ontology_version_id =
                  NEW.ontology_version_id
        )
        BEGIN
            SELECT RAISE(
                ABORT,
                'mapping attribute crosses ontology versions'
            );
        END;
        CREATE TRIGGER
            ingredient_ontology_mapping_attributes_version_update
        BEFORE UPDATE ON ingredient_ontology_mapping_attributes
        WHEN NOT EXISTS (
            SELECT 1
            FROM ingredient_ontology_mappings mapping
            JOIN ingredient_ontology_facets facet
              ON facet.id = NEW.facet_id
            JOIN ingredient_ontology_facet_values value
              ON value.id = NEW.facet_value_id
             AND value.facet_id = facet.id
            WHERE mapping.id = NEW.mapping_id
              AND mapping.ontology_version_id =
                  NEW.ontology_version_id
              AND facet.ontology_version_id =
                  NEW.ontology_version_id
              AND value.ontology_version_id =
                  NEW.ontology_version_id
        )
        BEGIN
            SELECT RAISE(
                ABORT,
                'mapping attribute crosses ontology versions'
            );
        END;

        CREATE TRIGGER
            ingredient_ontology_mapping_attributes_actual_ready_insert
        BEFORE INSERT ON ingredient_ontology_mapping_attributes
        WHEN ingredient_ontology_ready_mutation_guard() <> 1
         AND EXISTS (
             SELECT 1
             FROM ingredient_ontology_mappings mapping
             JOIN ingredient_ontology_versions version
               ON version.id = mapping.ontology_version_id
             WHERE mapping.id = NEW.mapping_id
               AND version.status = 'ready'
         )
        BEGIN
            SELECT RAISE(
                ABORT,
                'ready mapping attributes are immutable'
            );
        END;
        CREATE TRIGGER
            ingredient_ontology_mapping_attributes_actual_ready_update
        BEFORE UPDATE ON ingredient_ontology_mapping_attributes
        WHEN ingredient_ontology_ready_mutation_guard() <> 1
         AND EXISTS (
             SELECT 1
             FROM ingredient_ontology_mappings mapping
             JOIN ingredient_ontology_versions version
               ON version.id = mapping.ontology_version_id
             WHERE mapping.id IN (OLD.mapping_id, NEW.mapping_id)
               AND version.status = 'ready'
         )
        BEGIN
            SELECT RAISE(
                ABORT,
                'ready mapping attributes are immutable'
            );
        END;
        CREATE TRIGGER
            ingredient_ontology_mapping_attributes_actual_ready_delete
        BEFORE DELETE ON ingredient_ontology_mapping_attributes
        WHEN ingredient_ontology_ready_mutation_guard() <> 1
         AND ingredient_ontology_prune_guard() <> 1
         AND EXISTS (
             SELECT 1
             FROM ingredient_ontology_mappings mapping
             JOIN ingredient_ontology_versions version
               ON version.id = mapping.ontology_version_id
             WHERE mapping.id = OLD.mapping_id
               AND version.status = 'ready'
         )
        BEGIN
            SELECT RAISE(
                ABORT,
                'ready mapping attributes are immutable'
            );
        END
    ");
        }
    );
    ingredientOntologyV3EnsureHistoricalShadowMatchOwners($db);
    ingredientOntologyV3EnsurePendingEdgeReviewDisposition($db);
    if (function_exists('ingredientOntologyControllerSchemaMigrate')) {
        ingredientOntologyControllerSchemaMigrate($db);
    }
    ingredientOntologyV3MigrateReadyGuards($db);
    ingredientOntologyV3MigrateMaterializationGuards($db);
}
