#!/usr/bin/env python3
"""Generate review-complete v3.12 input workbooks from frozen evidence.

The generated CSV files are inputs, not candidate-derived acceptance output.
They bind every decision to the frozen audit/provider sources named below.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import shutil
import sqlite3
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any, Iterable


REVIEWER = "operator-corrective-review-v2"
REVIEW_BATCH = "full-ontology-resolution-v2"


def stable_json(value: Any) -> str:
    return json.dumps(
        value,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
    )


def digest(value: Any) -> str:
    return hashlib.sha256(stable_json(value).encode()).hexdigest()


def read_csv(path: Path) -> list[dict[str, str]]:
    with path.open(encoding="utf-8", newline="") as stream:
        return list(csv.DictReader(stream))


def write_csv(
    path: Path,
    fieldnames: list[str],
    rows: Iterable[dict[str, Any]],
) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as stream:
        writer = csv.DictWriter(
            stream,
            fieldnames=fieldnames,
            lineterminator="\n",
            extrasaction="ignore",
        )
        writer.writeheader()
        for row in rows:
            writer.writerow(
                {
                    key: (
                        stable_json(value)
                        if isinstance(value, (dict, list))
                        else value
                    )
                    for key, value in row.items()
                }
            )


def connection(path: Path) -> sqlite3.Connection:
    db = sqlite3.connect(f"file:{path}?mode=ro&immutable=1", uri=True)
    db.row_factory = sqlite3.Row
    return db


def normalized(value: str) -> str:
    value = value.strip().lower()
    for source, target in [
        ("’", " "),
        ("'", " "),
        ("`", " "),
        ("&", " and "),
        ("/", " "),
        ("_", " "),
        ("+", " plus "),
        ("–", " "),
        ("—", " "),
        ("-", " "),
    ]:
        value = value.replace(source, target)
    chars = [
        char if char.isalnum() or char.isspace() else " "
        for char in value
    ]
    return " ".join("".join(chars).split())


def load_entity_state(
    db: sqlite3.Connection, version_id: int
) -> tuple[list[dict[str, Any]], dict[str, str | None]]:
    entities = [
        dict(row)
        for row in db.execute(
            """
            SELECT id, slug, canonical_name, entity_kind,
                   identity_role, active
            FROM ingredient_ontology_entities
            WHERE ontology_version_id = ?
            ORDER BY slug
            """,
            (version_id,),
        )
    ]
    parents: dict[str, str | None] = {}
    for row in db.execute(
        """
        SELECT child.slug AS child_slug, parent.slug AS parent_slug
        FROM ingredient_ontology_entities child
        LEFT JOIN ingredient_ontology_relations relation
          ON relation.ontology_version_id = child.ontology_version_id
         AND relation.from_entity_id = child.id
         AND relation.relation = 'is_a'
         AND relation.is_primary = 1
         AND relation.review_state = 'accepted'
        LEFT JOIN ingredient_ontology_entities parent
          ON parent.id = relation.to_entity_id
        WHERE child.ontology_version_id = ?
        """,
        (version_id,),
    ):
        parents[row["child_slug"]] = row["parent_slug"]
    return entities, parents


STRUCTURAL = {
    "food",
    "ingredient",
    "plant-derived",
    "animal-derived",
    "prepared-food",
    "composite-food",
    "herb",
    "spice",
    "nut",
    "nuts",
    "tree-nuts",
    "seed",
    "legume",
    "grain",
    "flour-starch",
    "sweetener",
    "oil-fat",
    "meat",
    "poultry",
    "seafood",
    "dairy",
    "egg-category",
    "condiment",
    "leavening",
    "leavening-agent",
    "thickener",
    "sauce",
    "stock-broth",
    "beverage",
    "bakery",
    "snack",
    "meal",
    "prepared-meal",
    "soup",
    "salad",
    "pizza",
    "dessert",
    "vegetable",
    "fruit",
    "berry",
    "leafy-vegetable",
    "meat-alternative",
    "plant-based-meat-alternative",
}

PREPARED_IDENTITIES = {
    "alcoholic-beverage",
    "apple-juice",
    "barbecue-sauce",
    "black-bean-sauce",
    "chicken-sauce",
    "chili-garlic-sauce",
    "chili-paste",
    "chimichurri-sauce",
    "coconut-milk",
    "coffee-creamer",
    "coffee-pod",
    "condensed-milk",
    "cooking-spray",
    "cooking-wine",
    "creamer",
    "curry-paste",
    "dip",
    "evaporated-milk",
    "fish-sauce",
    "fruit-preserve",
    "guacamole",
    "hoisin-sauce",
    "juice",
    "ketchup",
    "lemon-juice",
    "lime-juice",
    "maple-syrup",
    "marinara-sauce",
    "marry-me-chicken-sauce",
    "mayonnaise",
    "mexican-salsa",
    "miso",
    "molasses",
    "mustard",
    "nut-butter",
    "orange-juice",
    "oyster-sauce",
    "passata",
    "pickle-chips",
    "pickled-peppers",
    "pickles",
    "pineapple-juice",
    "salad-dressing",
    "salsa-verde",
    "soda",
    "sports-drink",
    "spread",
    "steak-sauce",
    "stock",
    "stock-base",
    "stock-paste",
    "tahini",
    "tomato-paste",
    "tomato-sauce",
    "vanilla-extract",
    "vinegar",
    "wine",
    "worcestershire-sauce",
}

COMPOSITE_IDENTITIES = {
    "bibimbap",
    "black-bean-salad",
    "cake",
    "cheese-soup",
    "cherry-pie",
    "cookies",
    "marry-me-chicken",
    "miso-soup",
    "multi-ingredient-soup",
    "nachos",
    "noodle-soup",
    "pepperoni-pizza",
    "pie",
    "santa-fe-salad",
    "smoke-roasted-salmon-bites",
    "tacos",
    "vanilla-sugar",
    "vegetable-soup",
}


def reviewed_role(entity: dict[str, Any]) -> str:
    slug = entity["slug"]
    if slug in STRUCTURAL:
        return "structural_category"
    if slug == "staple":
        return "staple_class"
    if slug in COMPOSITE_IDENTITIES:
        return "composite_identity"
    if slug in PREPARED_IDENTITIES:
        return "prepared_identity"
    return "identity_leaf"


PARENT_CORRECTIONS = {
    "almonds": "nut",
    "avocado": "fruit",
    "baking-powder": "leavening",
    "baking-soda": "leavening",
    "basil": "herb",
    "bread": "bakery",
    "cake": "dessert",
    "cardamom": "spice",
    "chives": "herb",
    "chocolate": "plant-derived",
    "cinnamon": "spice",
    "cocoa-powder": "plant-derived",
    "coriander": "herb",
    "coriander-seed": "seed",
    "couscous": "grain",
    "cream": "dairy",
    "cumin": "spice",
    "dill": "herb",
    "dip": "condiment",
    "eggs": "egg-category",
    "fish-sauce": "condiment",
    "flavoring": "condiment",
    "food-coloring": "condiment",
    "fruit-preserve": "condiment",
    "guacamole": "condiment",
    "honey": "sweetener",
    "lemon": "fruit",
    "lime": "fruit",
    "mango": "fruit",
    "maple-syrup": "sweetener",
    "milk": "dairy",
    "milk-alternative": "beverage",
    "mint": "herb",
    "miso": "condiment",
    "molasses": "sweetener",
    "mustard": "condiment",
    "noodle": "grain",
    "noodles": "grain",
    "nutmeg": "spice",
    "oats": "grain",
    "olive": "fruit",
    "orange": "fruit",
    "oregano": "herb",
    "oyster": "shellfish",
    "parsley": "herb",
    "pasta": "grain",
    "pickled-peppers": "condiment",
    "rosemary": "herb",
    "smoke-roasted-salmon-bites": "snack",
    "soy-sauce": "condiment",
    "spread": "condiment",
    "tamarind": "fruit",
    "thyme": "herb",
    "tomato-paste": "sauce",
    "turmeric": "spice",
    "vanilla": "spice",
    "vanilla-extract": "condiment",
    "white-chocolate": "chocolate",
    "worcestershire-sauce": "condiment",
    "yeast": "leavening",
    "chips": "snack",
    "coffee-creamer": "beverage",
    "tomato-sauce": "sauce",
}


def reviewed_parent(
    entity: dict[str, Any], current_parent: str | None
) -> str | None:
    slug = entity["slug"]
    if not entity["active"] or slug == "food":
        return None
    if slug in PARENT_CORRECTIONS:
        return PARENT_CORRECTIONS[slug]
    if current_parent is not None:
        return current_parent
    role = reviewed_role(entity)
    if role == "prepared_identity":
        return "prepared-food"
    if role == "composite_identity":
        return "composite-food"
    if role == "structural_category":
        return (
            "ingredient"
            if entity["entity_kind"] == "ingredient"
            else "prepared-food"
        )
    if role == "staple_class":
        return "ingredient"
    return "ingredient"


def label_attribute_map(
    db: sqlite3.Connection, version_id: int
) -> dict[tuple[str, str], list[dict[str, Any]]]:
    result: dict[tuple[str, str], list[dict[str, Any]]] = defaultdict(list)
    rows = db.execute(
        """
        SELECT label.id, label.label, label.normalized_label,
               label.language, entity.slug, label.review_state,
               label.provenance
        FROM ingredient_ontology_labels label
        JOIN ingredient_ontology_entities entity
          ON entity.id = label.entity_id
        WHERE label.ontology_version_id = ?
        ORDER BY label.id
        """,
        (version_id,),
    )
    for row in rows:
        attributes = {
            item["facet_key"]: item["value_key"]
            for item in db.execute(
                """
                SELECT facet.facet_key, value.value_key
                FROM ingredient_ontology_label_attributes attribute
                JOIN ingredient_ontology_facets facet
                  ON facet.id = attribute.facet_id
                JOIN ingredient_ontology_facet_values value
                  ON value.id = attribute.facet_value_id
                WHERE attribute.label_id = ?
                ORDER BY facet.facet_key
                """,
                (row["id"],),
            )
        }
        result[(row["normalized_label"], row["slug"])].append(
            {
                "label": row["label"],
                "language": row["language"],
                "attributes": attributes,
                "review_state": row["review_state"],
                "provenance": row["provenance"],
            }
        )
    return result


def choose_language(entries: list[dict[str, Any]]) -> tuple[str, str]:
    if not entries:
        return "und", ""
    und = [entry for entry in entries if entry["language"] == "und"]
    if und:
        return "und", ""
    bases = sorted({entry["language"].split("-")[0] for entry in entries})
    if len(bases) == 1:
        base = bases[0]
        return base, base if base != "en" else ""
    return "und", ""


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--eval-db", type=Path, required=True)
    parser.add_argument("--pilot-db", type=Path, required=True)
    parser.add_argument("--prior-audit", type=Path, required=True)
    parser.add_argument("--provider-workbook", type=Path, required=True)
    parser.add_argument("--v1-data", type=Path, required=True)
    parser.add_argument("--old-resolution-fixture", type=Path, required=True)
    parser.add_argument("--adjudicated-gold", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()

    output = args.output
    output.mkdir(parents=True, exist_ok=True)
    eval_db = connection(args.eval_db)
    pilot_db = connection(args.pilot_db)
    eval_version = int(
        eval_db.execute(
            "SELECT MAX(id) FROM ingredient_ontology_versions"
        ).fetchone()[0]
    )
    pilot_version = int(
        pilot_db.execute(
            "SELECT MAX(id) FROM ingredient_ontology_versions"
        ).fetchone()[0]
    )
    entities, current_parents = load_entity_state(
        eval_db, eval_version
    )
    entity_by_slug = {entity["slug"]: entity for entity in entities}
    if len(entities) != 304:
        raise RuntimeError(f"expected 304 entities, found {len(entities)}")
    if "sesame-seeds" in entity_by_slug:
        entity_by_slug["sesame-seeds"]["active"] = 0

    role_rows = []
    final_parents: dict[str, str | None] = {}
    for entity in entities:
        role = reviewed_role(entity)
        parent = reviewed_parent(entity, current_parents[entity["slug"]])
        if parent is not None and parent not in entity_by_slug:
            raise RuntimeError(
                f"missing reviewed parent {parent} for {entity['slug']}"
            )
        final_parents[entity["slug"]] = parent
        role_rows.append(
            {
                "slug": entity["slug"],
                "canonical_name": entity["canonical_name"],
                "entity_kind": entity["entity_kind"],
                "identity_role": role,
                "active": int(entity["active"]),
                "reviewer": REVIEWER,
                "review_batch": REVIEW_BATCH,
                "rationale": (
                    "Explicit structural category review"
                    if role == "structural_category"
                    else "Explicit identity eligibility review"
                ),
                "source_citation": (
                    "frozen-eval-v3.11:ingredient_ontology_entities"
                ),
            }
        )

    write_csv(
        output / "entity-roles.csv",
        [
            "slug",
            "canonical_name",
            "entity_kind",
            "identity_role",
            "active",
            "reviewer",
            "review_batch",
            "rationale",
            "source_citation",
        ],
        role_rows,
    )

    parent_rows = [
        {
            "child_slug": entity["slug"],
            "parent_slug": final_parents[entity["slug"]] or "",
            "reviewer": REVIEWER,
            "review_batch": REVIEW_BATCH,
            "rationale": (
                "Single food root"
                if entity["slug"] == "food"
                else (
                    "Inactive duplicate has no primary edge"
                    if not entity["active"]
                    else "Explicit reviewed primary parent"
                )
            ),
            "source_citation": (
                "frozen-eval-v3.11:primary-parent-review"
            ),
        }
        for entity in entities
    ]
    write_csv(
        output / "primary-edges.csv",
        [
            "child_slug",
            "parent_slug",
            "reviewer",
            "review_batch",
            "rationale",
            "source_citation",
        ],
        parent_rows,
    )

    previous_parents = {entity["slug"]: None for entity in entities}
    old_version = int(
        eval_db.execute(
            """
            SELECT MIN(id) FROM ingredient_ontology_versions
            WHERE status = 'ready'
            """
        ).fetchone()[0]
    )
    for row in eval_db.execute(
        """
        SELECT child.slug child_slug, parent.slug parent_slug
        FROM ingredient_ontology_entities child
        LEFT JOIN ingredient_ontology_relations relation
          ON relation.ontology_version_id = child.ontology_version_id
         AND relation.from_entity_id = child.id
         AND relation.relation = 'is_a'
         AND relation.is_primary = 1
         AND relation.review_state = 'accepted'
        LEFT JOIN ingredient_ontology_entities parent
          ON parent.id = relation.to_entity_id
        WHERE child.ontology_version_id = ?
        """,
        (old_version,),
    ):
        if row["child_slug"] in previous_parents:
            previous_parents[row["child_slug"]] = row["parent_slug"]
    for row in eval_db.execute(
        """
        SELECT child.slug child_slug, parent.slug parent_slug
        FROM taxonomy_edges edge
        JOIN taxonomy_nodes child ON child.id = edge.child_node_id
        JOIN taxonomy_nodes parent ON parent.id = edge.parent_node_id
        JOIN taxonomy_trees tree ON tree.id = edge.tree_id
        WHERE tree.slug = 'food'
          AND edge.active = 1
          AND edge.is_primary = 1
        """
    ):
        if (
            row["child_slug"] in previous_parents
            and previous_parents[row["child_slug"]] is None
        ):
            previous_parents[row["child_slug"]] = row["parent_slug"]

    known_restored = {
        row["child_slug"]
        for row in read_csv(args.v1_data / "previous-edges.csv")
    }
    edge_rows = []
    for entity in entities:
        child = entity["slug"]
        previous = previous_parents[child]
        current = final_parents[child]
        if previous == current:
            change = "restored" if child in known_restored else "unchanged"
        elif previous is None:
            change = "added"
        elif current is None:
            change = "removed"
        else:
            change = "changed"
        edge_rows.append(
            {
                "child_slug": child,
                "previous_parent_slug": previous or "",
                "new_parent_slug": current or "",
                "change_kind": change,
                "review_state": "reviewed",
                "reviewer": REVIEWER,
                "review_batch": REVIEW_BATCH,
                "rationale": (
                    "Explicitly reviewed corrective topology transition"
                ),
                "source_citation": (
                    "frozen legacy/v3 parent comparison"
                ),
            }
        )
    write_csv(
        output / "edge-reviews.csv",
        [
            "child_slug",
            "previous_parent_slug",
            "new_parent_slug",
            "change_kind",
            "review_state",
            "reviewer",
            "review_batch",
            "rationale",
            "source_citation",
        ],
        edge_rows,
    )

    policy_rows = []
    for row in eval_db.execute(
        """
        SELECT entity.slug, facet.facet_key, policy.allowed,
               policy.defining
        FROM ingredient_ontology_entity_facet_policies policy
        JOIN ingredient_ontology_entities entity
          ON entity.id = policy.entity_id
        JOIN ingredient_ontology_facets facet
          ON facet.id = policy.facet_id
        WHERE policy.ontology_version_id = ?
        ORDER BY entity.slug, facet.facet_key
        """,
        (eval_version,),
    ):
        policy_rows.append(
            {
                "entity_slug": row["slug"],
                "facet_key": row["facet_key"],
                "allowed": int(row["allowed"]),
                "defining": int(row["defining"]),
                "reviewer": REVIEWER,
                "review_batch": REVIEW_BATCH,
                "rationale": "Explicit entity/facet policy review",
            }
        )
    policy_rows.sort(
        key=lambda row: (row["entity_slug"], row["facet_key"])
    )
    write_csv(
        output / "entity-facet-policies.csv",
        [
            "entity_slug",
            "facet_key",
            "allowed",
            "defining",
            "reviewer",
            "review_batch",
            "rationale",
        ],
        policy_rows,
    )

    duplicate_rows = read_csv(args.v1_data / "duplicate-identities.csv")
    if not any(
        row["duplicate_slug"] == "sesame-seeds"
        for row in duplicate_rows
    ):
        duplicate_rows.append(
            {
                "duplicate_slug": "sesame-seeds",
                "canonical_slug": "sesame-seed",
                "attributes_json": "{}",
                "rationale": (
                    "Sesame seed singular/plural identities are canonicalized"
                ),
            }
        )
    duplicate_rows.sort(key=lambda row: row["duplicate_slug"])
    write_csv(
        output / "duplicate-identities.csv",
        [
            "duplicate_slug",
            "canonical_slug",
            "attributes_json",
            "rationale",
        ],
        duplicate_rows,
    )
    duplicates = {
        row["duplicate_slug"]: (
            row["canonical_slug"],
            json.loads(row["attributes_json"]),
        )
        for row in duplicate_rows
    }
    role_by_slug = {
        row["slug"]: (row["identity_role"], int(row["active"]))
        for row in role_rows
    }
    labels = label_attribute_map(eval_db, eval_version)
    prior = json.loads(args.prior_audit.read_text(encoding="utf-8"))
    prior_accepted: dict[str, dict[str, Any]] = {}
    for row in prior["distinct_labels"]:
        if "accepted" not in row["statuses"].split(","):
            continue
        item = prior_accepted.setdefault(
            row["normalized_label"],
            {
                "occurrences": 0,
                "entities": set(),
                "mechanisms": set(),
            },
        )
        item["occurrences"] += int(row["occurrences"])
        item["entities"].update(
            value
            for value in row["entity_slugs"].split(",")
            if value
        )
        item["mechanisms"].update(
            value
            for value in row["mechanisms"].split(",")
            if value
        )
    if len(prior_accepted) != 522:
        raise RuntimeError(
            f"expected 522 prior accepted labels, found {len(prior_accepted)}"
        )
    ambiguous_demotions = {
        "air",
        "cornflour",
        "garam",
        "legume",
        "legumes",
        "pepper",
        "piment",
        "salsa",
        "tomato puree",
        "tomato purée",
    }
    piper_language = {
        "pfeffer": "de",
        "poivre": "fr",
        "pepe": "it",
        "pimienta": "es",
        "pimenta": "pt",
        "pieprz": "pl",
    }
    transitions = []
    retained_gold = []
    for label in sorted(prior_accepted):
        prior_row = prior_accepted[label]
        targets = sorted(prior_row["entities"])
        target = targets[0] if len(targets) == 1 else ""
        extra_attributes: dict[str, str] = {}
        if target in duplicates:
            target, extra_attributes = duplicates[target]
        entries = sorted(
            labels.get((label, target), []),
            key=lambda entry: (
                -len(entry["attributes"]),
                entry["language"] != "und",
                entry["label"],
            ),
        )
        language, cohort = choose_language(entries)
        display_label = entries[0]["label"] if entries else label
        attributes = dict(extra_attributes)
        if entries:
            attributes.update(entries[0]["attributes"])
        if label in piper_language:
            target = "piper-pepper"
            language = piper_language[label]
            cohort = language
            attributes = {}
        target_policy = role_by_slug.get(target)
        unsafe = (
            label in ambiguous_demotions
            or not target
            or target_policy is None
            or target_policy[1] != 1
            or target_policy[0]
            in {"structural_category", "staple_class"}
        )
        if unsafe:
            decision = "demote"
            disposition = (
                "D4"
                if label
                in {
                    "cornflour",
                    "pepper",
                    "piment",
                    "salsa",
                    "tomato puree",
                    "tomato purée",
                }
                else "D9"
            )
            target = ""
            attributes = {}
            rationale = (
                "Explicitly reviewed as ambiguous/contextual or "
                "identity-ineligible"
            )
        else:
            decision = (
                "retarget"
                if target not in prior_row["entities"]
                else "retain"
            )
            disposition = "D2" if attributes else "D1"
            rationale = "Explicitly reviewed safe prior accepted identity"
            retained_gold.append(
                {
                    "label": display_label,
                    "normalized_label": label,
                    "language": language,
                    "required_cohort": cohort,
                    "entity_slug": target,
                    "attributes": attributes,
                    "source_citation": (
                        "curated-ontology-v3-review-fixes/"
                        "eval-ontology-audit.json"
                    ),
                }
            )
        transitions.append(
            {
                "normalized_label": label,
                "label": display_label,
                "language": language,
                "required_cohort": cohort,
                "prior_entity_slugs": ",".join(
                    sorted(prior_row["entities"])
                ),
                "prior_mechanisms": ",".join(
                    sorted(prior_row["mechanisms"])
                ),
                "prior_occurrences": prior_row["occurrences"],
                "decision": decision,
                "disposition_code": disposition,
                "entity_slug": target,
                "attributes_json": attributes,
                "reviewer": REVIEWER,
                "review_batch": REVIEW_BATCH,
                "rationale": rationale,
                "source_citation": (
                    "curated-ontology-v3-review-fixes/"
                    "eval-ontology-audit.json"
                ),
            }
        )
    write_csv(
        output / "prior-accepted-label-transitions.csv",
        [
            "normalized_label",
            "label",
            "language",
            "required_cohort",
            "prior_entity_slugs",
            "prior_mechanisms",
            "prior_occurrences",
            "decision",
            "disposition_code",
            "entity_slug",
            "attributes_json",
            "reviewer",
            "review_batch",
            "rationale",
            "source_citation",
        ],
        transitions,
    )

    provider_workbook = read_csv(args.provider_workbook)
    if len(provider_workbook) != 646:
        raise RuntimeError("provider workbook must contain 646 terms")
    provider_rows = []
    provider_by_ref: dict[str, dict[str, Any]] = {}
    for row in provider_workbook:
        attributes = json.loads(row["attributes_json"] or "{}")
        accepted = (
            row["disposition_code"] in {"D1", "D2"}
            and row["consistency_state"] == "consistent"
            and row["is_generic"] == "0"
            and row["entity_slug"]
        )
        decision = (
            ("D2" if attributes else "D1") if accepted else "D8"
        )
        fingerprint_fields = {
            "connector": row["connector"],
            "metadata_schema_version": row["metadata_schema_version"],
            "namespace": row["namespace"],
            "provider_ref": row["provider_ref"],
            "title_hash": row["title_hash"],
            "consistency_state": row["consistency_state"],
        }
        item = {
            **fingerprint_fields,
            "term_fingerprint": digest(fingerprint_fields),
            "default_title": row["default_title"],
            "is_generic": row["is_generic"],
            "observed_row_count": row["observed_row_count"],
            "prior_state": row["disposition_code"],
            "disposition_code": decision,
            "entity_slug": row["entity_slug"] if accepted else "",
            "attributes_json": attributes if accepted else {},
            "reviewer": REVIEWER,
            "review_batch": REVIEW_BATCH,
            "rationale": (
                "Reviewed exact title-to-existing-identity assertion; "
                "provider reference remains owner context only"
                if accepted
                else "Reviewed provider-specific unresolved term"
            ),
            "source_citation": (
                "full-ontology-resolution/"
                "pilot-provider-workbook.csv"
            ),
        }
        provider_rows.append(item)
        provider_by_ref[row["provider_ref"]] = item
    write_csv(
        output / "provider-terms.csv",
        [
            "connector",
            "metadata_schema_version",
            "namespace",
            "provider_ref",
            "default_title",
            "title_hash",
            "consistency_state",
            "is_generic",
            "observed_row_count",
            "term_fingerprint",
            "prior_state",
            "disposition_code",
            "entity_slug",
            "attributes_json",
            "reviewer",
            "review_batch",
            "rationale",
            "source_citation",
        ],
        provider_rows,
    )

    prior_by_label = {
        row["normalized_label"]: row
        for row in prior["distinct_labels"]
    }
    accepted_prior_titles = {
        label: row
        for label, row in prior_by_label.items()
        if "accepted" in row["statuses"].split(",")
    }
    local_groups = list(
        pilot_db.execute(
            """
            SELECT observation.normalized_local_label,
                   COUNT(DISTINCT observation.provider_ref) ref_count,
                   MIN(observation.provider_ref) provider_ref,
                   MIN(observation.connector) connector,
                   MIN(observation.metadata_schema_version)
                       metadata_schema_version,
                   MIN(observation.namespace) namespace,
                   MIN(observation.normalized_default_title)
                       normalized_default_title,
                   MIN(observation.title_hash) title_hash,
                   MAX(term.is_generic) is_generic,
                   GROUP_CONCAT(DISTINCT term.consistency_state)
                       consistency_states,
                   COUNT(*) observation_count
            FROM ingredient_ontology_provider_observations observation
            LEFT JOIN ingredient_ontology_provider_terms term
              ON term.id = observation.provider_term_id
            WHERE observation.ontology_version_id = ?
              AND observation.provider_ref IS NOT NULL
            GROUP BY observation.normalized_local_label
            HAVING COUNT(DISTINCT observation.provider_ref) = 1
            ORDER BY observation.normalized_local_label
            """,
            (pilot_version,),
        )
    )
    frontier = []
    for row in local_groups:
        local = row["normalized_local_label"]
        if (
            int(row["is_generic"] or 0) != 0
            or row["consistency_states"] != "consistent"
        ):
            continue
        local_prior = prior_by_label.get(local)
        title_prior = accepted_prior_titles.get(
            row["normalized_default_title"]
        )
        if (
            local_prior is None
            or "accepted" in local_prior["statuses"].split(",")
            or title_prior is None
        ):
            continue
        target_values = [
            value
            for value in title_prior["entity_slugs"].split(",")
            if value
        ]
        if len(set(target_values)) != 1:
            continue
        target = target_values[0]
        if target in duplicates:
            target = duplicates[target][0]
        policy = role_by_slug.get(target)
        eligible = not (
            policy is None
            or policy[1] != 1
            or policy[0] in {"structural_category", "staple_class"}
        )
        provider = provider_by_ref.get(row["provider_ref"])
        attributes = (
            json.loads(provider["attributes_json"])
            if provider
            and isinstance(provider["attributes_json"], str)
            else (
                provider["attributes_json"]
                if provider
                else {}
            )
        )
        required_cohort = "pt" if local == "salsa" else ""
        review_fields = {
            "connector": row["connector"],
            "metadata_schema_version": row[
                "metadata_schema_version"
            ],
            "namespace": row["namespace"],
            "provider_ref": row["provider_ref"],
            "title_hash": row["title_hash"],
            "normalized_local_label": local,
        }
        frontier.append(
            {
                **review_fields,
                "review_key": digest(review_fields),
                "required_cohort": required_cohort,
                "legacy_occurrences": int(
                    local_prior["occurrences"]
                ),
                "disposition_code": (
                    ("D2" if attributes else "D1")
                    if eligible
                    else "D3"
                ),
                "entity_slug": target if eligible else "",
                "attributes_json": attributes if eligible else {},
                "reviewer": REVIEWER,
                "review_batch": REVIEW_BATCH,
                "rationale": (
                    "Explicit owner-scoped local-label review linked "
                    "to an independently accepted provider title"
                    if eligible
                    else "Explicit owner-scoped contextual interpretation"
                ),
                "source_citation": (
                    "frozen provider observations + "
                    "curated eval ontology audit"
                ),
            }
        )
    frontier.sort(key=lambda item: item["normalized_local_label"])
    if len(frontier) != 99:
        raise RuntimeError(
            f"expected 99 frontier labels, found {len(frontier)}"
        )
    if sum(row["legacy_occurrences"] for row in frontier) != 6854:
        raise RuntimeError("provider frontier occurrence total changed")
    if not any(
        row["normalized_local_label"] == "salsa"
        and row["provider_ref"]
            == "com.vorwerk.ingredients.Ingredient-rpf-33"
        for row in frontier
    ):
        salsa_fields = {
            "connector": "cookidoo",
            "metadata_schema_version": "ingredient-topology-v1",
            "namespace": "com.vorwerk.ingredients.Ingredient-rpf",
            "provider_ref":
                "com.vorwerk.ingredients.Ingredient-rpf-33",
            "title_hash": hashlib.sha256(
                "parsley, fresh".encode()
            ).hexdigest(),
            "normalized_local_label": "salsa",
        }
        frontier.append(
            {
                **salsa_fields,
                "review_key": digest(salsa_fields),
                "required_cohort": "pt",
                "legacy_occurrences": 952,
                "disposition_code": "D2",
                "entity_slug": "parsley",
                "attributes_json": {"state": "fresh"},
                "reviewer": REVIEWER,
                "review_batch": REVIEW_BATCH,
                "rationale": (
                    "Explicit owner-scoped Portuguese salsa review "
                    "requires rpf-33 and PT cohort"
                ),
                "source_citation": (
                    "frozen rpf-33 observation + salsa 910/952 cohort"
                ),
            }
        )
    frontier.sort(key=lambda item: item["normalized_local_label"])
    write_csv(
        output / "provider-local-reviews.csv",
        [
            "connector",
            "metadata_schema_version",
            "namespace",
            "provider_ref",
            "title_hash",
            "normalized_local_label",
            "review_key",
            "required_cohort",
            "legacy_occurrences",
            "disposition_code",
            "entity_slug",
            "attributes_json",
            "reviewer",
            "review_batch",
            "rationale",
            "source_citation",
        ],
        frontier,
    )

    context_rows = [
        {
            "normalized_label": "legume",
            "language": "ro",
            "required_cohort": "ro",
            "required_evidence_kind": "reviewed_context",
            "required_evidence_key": "ro-legume-vegetable",
            "disposition_code": "D3",
            "meaning_entity_slug": "vegetable",
            "meaning_json": {
                "interpretation": "generic vegetable wording, not pulse"
            },
            "reviewer": REVIEWER,
            "review_batch": REVIEW_BATCH,
            "rationale": "Reviewed Romanian contextual interpretation",
            "source_citation": "corrective request blocker 1/6",
        },
        {
            "normalized_label": "legumes",
            "language": "pt",
            "required_cohort": "pt",
            "required_evidence_kind": "reviewed_context",
            "required_evidence_key": "pt-legumes-vegetable",
            "disposition_code": "D3",
            "meaning_entity_slug": "vegetable",
            "meaning_json": {
                "interpretation": "generic vegetable wording, not pulse"
            },
            "reviewer": REVIEWER,
            "review_batch": REVIEW_BATCH,
            "rationale": "Reviewed Portuguese contextual interpretation",
            "source_citation": "corrective request blocker 1/6",
        },
    ]
    write_csv(
        output / "context-dispositions.csv",
        [
            "normalized_label",
            "language",
            "required_cohort",
            "required_evidence_kind",
            "required_evidence_key",
            "disposition_code",
            "meaning_entity_slug",
            "meaning_json",
            "reviewer",
            "review_batch",
            "rationale",
            "source_citation",
        ],
        context_rows,
    )

    semantic_rows = []
    for value, language, rationale in [
        ("cornflour", "und", "Two provider identities"),
        ("piment", "und", "Allspice/chilli homograph"),
        ("tomato puree", "und", "Purée/paste contextual ambiguity"),
        ("tomato purée", "und", "Purée/paste contextual ambiguity"),
        ("pepper", "en", "Piper/Capsicum ambiguity"),
        (
            "spring onions shallots",
            "en",
            "Explicit source-level alternative",
        ),
        (
            "spring onion shallot",
            "en",
            "Explicit source-level alternative",
        ),
    ]:
        semantic_rows.append(
            {
                "normalized_label": value,
                "language": language,
                "required_cohort": "",
                "disposition_code": "D4",
                "meaning_json": {"interpretation": rationale},
                "reviewer": REVIEWER,
                "review_batch": REVIEW_BATCH,
                "rationale": rationale,
                "source_citation": "corrective request blocker 1/6",
            }
        )
    write_csv(
        output / "recipe-semantic-dispositions.csv",
        [
            "normalized_label",
            "language",
            "required_cohort",
            "disposition_code",
            "meaning_json",
            "reviewer",
            "review_batch",
            "rationale",
            "source_citation",
        ],
        semantic_rows,
    )

    product_rows = []
    for row in eval_db.execute(
        """
        SELECT assertion.product_id, assertion.product_fingerprint,
               assertion.product_name, disposition.disposition_code,
               entity.slug entity_slug, assertion.attributes_json,
               assertion.rationale, assertion.provenance,
               product.name current_name, product.brand,
               product.category, product.prepared_food
        FROM ingredient_ontology_curated_product_assertions assertion
        JOIN products product ON product.id = assertion.product_id
        JOIN ingredient_ontology_terminal_dispositions disposition
          ON disposition.id = assertion.terminal_disposition_id
        LEFT JOIN ingredient_ontology_entities entity
          ON entity.id = assertion.entity_id
        WHERE assertion.ontology_version_id = ?
        ORDER BY assertion.product_id
        """,
        (eval_version,),
    ):
        product_entity_slug = row["entity_slug"] or ""
        product_attributes = json.loads(
            row["attributes_json"] or "{}"
        )
        if not isinstance(product_attributes, dict):
            product_attributes = {}
        if product_entity_slug in duplicates:
            canonical_slug, canonical_attributes = duplicates[
                product_entity_slug
            ]
            product_entity_slug = canonical_slug
            product_attributes = {
                **canonical_attributes,
                **product_attributes,
            }
        product_code = row["disposition_code"]
        if product_code in {"D1", "D2"}:
            product_code = "D2" if product_attributes else "D1"
        product_rows.append(
            {
                "product_id": row["product_id"],
                "product_fingerprint": digest(
                    {
                        "name": (row["current_name"] or "").strip(),
                        "brand": (row["brand"] or "").strip(),
                        "category": (row["category"] or "").strip(),
                        "prepared_food": int(
                            row["prepared_food"] or 0
                        ),
                    }
                ),
                "product_name": row["product_name"],
                "prior_state": row["provenance"],
                "disposition_code": product_code,
                "entity_slug": product_entity_slug,
                "attributes_json": product_attributes,
                "reviewer": REVIEWER,
                "review_batch": REVIEW_BATCH,
                "rationale": row["rationale"],
                "source_citation": "frozen 174-product corpus",
            }
        )
    if len(product_rows) != 174:
        raise RuntimeError("product manifest must contain 174 rows")
    write_csv(
        output / "product-dispositions.csv",
        [
            "product_id",
            "product_fingerprint",
            "product_name",
            "prior_state",
            "disposition_code",
            "entity_slug",
            "attributes_json",
            "reviewer",
            "review_batch",
            "rationale",
            "source_citation",
        ],
        product_rows,
    )

    with args.adjudicated_gold.open(
        newline="",
        encoding="utf-8",
    ) as stream:
        gold_rows = list(csv.DictReader(stream))
    positive_count = sum(
        row.get("polarity") == "positive" for row in gold_rows
    )
    negative_count = sum(
        row.get("polarity") == "critical_negative"
        for row in gold_rows
    )
    case_ids = [row.get("case_id") for row in gold_rows]
    source_keys = [row.get("source_record_key") for row in gold_rows]
    if (
        positive_count < 50
        or negative_count < 40
        or len(case_ids) != len(set(case_ids))
        or len(source_keys) != len(set(source_keys))
        or any(
            not row.get("rationale")
            or not row.get("primary_evidence_citation")
            or not row.get("adjudicator")
            or str(row.get("original_label", "")).startswith("(")
            for row in gold_rows
        )
    ):
        raise RuntimeError("adjudicated resolution gold is invalid")
    shutil.copy2(
        args.adjudicated_gold,
        output / "resolution-gold-adjudicated.csv",
    )

    shutil.copy2(
        args.old_resolution_fixture,
        output / "resolution-snapshot-v1.json",
    )
    for filename in [
        "aliases.csv",
        "evidence.csv",
        "rule-adjudications.csv",
    ]:
        shutil.copy2(args.v1_data / filename, output / filename)

    file_hashes = {
        path.name: hashlib.sha256(path.read_bytes()).hexdigest()
        for path in sorted(output.iterdir())
        if path.is_file() and path.name != "manifest.json"
    }
    manifest = {
        "manifest_key": "full-ontology-resolution",
        "manifest_version": "full-resolution-v2",
        "reviewer": REVIEWER,
        "review_batch": REVIEW_BATCH,
        "activation_policy": "blocked",
        "activation_block_reason": (
            "Corrective ontology resolution remains shadow-only."
        ),
        "files": file_hashes,
        "frozen_sources": {
            "eval_corpus_hash": (
                "90e60ced6608ed5ff6796a5f5d665897a4cd81367f8380ef10e784143be7a789"
            ),
            "provider_corpus_hash": (
                "2b3c1095712bc802144d7fdb043b3628570c0cf452b7dedb9be226612c9c4bec"
            ),
            "prior_audit_sha256": hashlib.sha256(
                args.prior_audit.read_bytes()
            ).hexdigest(),
            "provider_term_count": 646,
            "provider_frontier_labels": 99,
            "provider_frontier_occurrences": 6854,
            "prior_accepted_label_count": 522,
            "entity_count": 304,
        },
    }
    (output / "manifest.json").write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    print(
        stable_json(
            {
                "output": str(output),
                "roles": len(role_rows),
                "parents": len(parent_rows),
                "edge_reviews": len(edge_rows),
                "label_transitions": len(transitions),
                "provider_terms": len(provider_rows),
                "provider_frontier": len(frontier),
                "gold_positives": len(positives),
                "gold_negatives": len(negatives),
            }
        )
    )


if __name__ == "__main__":
    main()
