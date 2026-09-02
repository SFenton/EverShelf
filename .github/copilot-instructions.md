# Repository instructions

## Fork and release workflow

- Treat `SFenton/EverShelf` as the only push and release target for this fork.
- Do not push branches, tags, or GitHub Releases to `dadaloop82/EverShelf` from this working tree.
- If a command references `origin`, verify it resolves to `SFenton/EverShelf` first; otherwise use the `upstream` remote, which is configured for `SFenton/EverShelf` in this checkout.

## Recipe catalog API

- The catalog contains tens of thousands of recipes. Rank, filter, deduplicate,
  and paginate in SQLite before hydrating response rows; never load the full
  candidate set into PHP.
- Dashboard list endpoints return compact card projections, stable dedupe keys,
  deterministic tie-breaks, and snapshot-bound opaque cursors.
- Inventory-dependent ranking uses materialized score revisions. Build a new
  revision independently, activate it atomically, and retain the previous ready
  revision for in-flight cursors.
- Ingredient ontology v3 is an additive, faceted, versioned graph and is disabled
  by default. Its accepted identity requires the same base entity plus compatible
  defining attributes; ancestry, contains/component, derivation, lexical/rule/model
  evidence, and unreviewed proposals never satisfy required ingredients.
- Full-resolution ontology versions use one connected `food` backbone and an
  orthogonal identity role. Structural categories never satisfy identity;
  provider refs and recipe cohorts are review/context evidence only. Every
  product, ingredient row, and provider term must end in one immutable,
  fingerprint-bound D1-D9 terminal disposition with zero candidate or
  undispositioned rows.
- D3-D6 are semantic review states, never classifier outputs. They require an
  exact hashed manifest row; cohort, regex, digits, punctuation, legacy
  history, and provider refs are hints/evidence only. Provider evidence is
  bound to the exact source-owner fingerprint and provider observation.
- Ready ontology versions are sealed and immutable. Portable hashes use entity
  slugs, facet keys/values, provider keys, and owner fingerprints rather than
  surrogate IDs. Activation validation checks exact materialized ID sets and
  both matcher and pinned adjudicated resolution gold.
- Candidate builds explicitly select the frozen `eval`, `provider`, or
  `production` corpus profile. Exact source hashes/counts, reviewed
  product/provider subject sets,
  activation policy/reason, matcher fixture hash, ordered matcher case IDs, and
  case count are sealed and revalidated before and under activation reservation.
- Build ontology candidates, audits, and full materialized shadow scores only
  against copied databases. The active ontology is derived solely from the active
  score revision's nullable `ontology_version_id`; never add or read a separate
  active-ontology pointer.
- Gemini 3.5 Flash is the frozen legacy proposal default. Model output never
  writes entities, labels, relations, or mappings directly. The default-off
  autonomous controller uses strict provider-neutral schemas, deterministic
  closed repair application into a forked building child, abstention/quarantine,
  exact constraint, shadow, gold, blast, and rollback gates with no human
  approval state.
- Legacy/manual v3 activation is an explicit CLI operation after graph, corpus,
  frozen-gold, input-revision, score-completeness, and change-set gates. Once v3
  is active, scheduled replacements stay on that exact ontology version, repeat
  the same validation under write reservation, and retain the prior pointer on
  failure. Activation changes only the active score pointer and cursor revision;
  lower cookability from removing false positives is expected correctness.
  Autonomous child versions may move the same score pointer only through the
  separately disabled controller policy after all equivalent and controller-
  specific epoch/constraint/critic/blast gates pass under reservation.
- Full-resolution regular v3 score revisions may be activated only by the
  explicit manual CLI after the frozen production corpus, reviewed subjects,
  graph, gold, source, exact ID/value materialization, integrity, and
  confirmation gates pass. Manual activation also requires an intact retained
  parent score revision so rollback is usable immediately. Requirement-projection
  and source-aware revisions remain shadow-only and activation-blocked.
- Retained and retargeted prior-label decisions are gates on realized
  post-quarantine resolver outcomes, not manifest row counts. Demotions must
  remain nonaccepted with their reviewed terminal semantics.
- Eternal seed resolution gold is the pinned adjudicated source workbook plus exact
  frozen-source, owner/provider, product-fingerprint, supersession, and
  maintainer review metadata. Code does not infer model independence.
  Generated accepted-row snapshots are conformance artifacts only and never
  gold. Future autonomous gold consists only of immutable hash-linked releases
  reproducible from mature direct correction constraints and permanent
  rollback/quarantine adversarial evidence.
- `RECIPE_SCORE_PREVIEW_REVISION_ID` is a development/testing-only read
  override for one explicit validated regular v3 revision. It never activates,
  never selects latest, never affects mutations/rebuilds, and must fail closed
  to the true active revision.
- Grocery and every other mutation path always use the true active score and
  ontology, never a configured preview. Preview also requires an explicit
  development/test runtime environment.
- A monotonic ontology-source revision/hash invalidates v3 reads and scores for
  product, recipe/source identity, provider-title/ref/optional, or mapping-field
  changes. Mapping attribute companion rows must match their mapping version
  and authoritative `attributes_json` exactly.
- Ready score, match, requirement, member, and recipe-state materializations
  are immutable and value-hashed in canonical row order. Activation and
  rollback validate both exact ID sets and exact materialized values.
- Provider-local accepted attributes must equal the reviewed manifest exactly;
  every parsed defining hint is either preserved or explicitly waived.
  Cross-copy audits compare portable disposition outcomes per stable scope.
- Primary edges encode only reviewed subtype semantics. Derivation and
  component evidence use non-satisfying secondary relations and are covered by
  the edge-semantic fixture.
- Connector work stays asynchronous and idempotent. Interactive discovery gets a
  reserved queue lane but must not starve background crawl progress. Provider
  calls run only after a committed lease claim with no SQLite/file lock held;
  short apply transactions revalidate lease and per-target request-order fences.
- Cookidoo storage contract `metadata-v2` and execution capability
  `metadata-v3-operator-enabled` permit only factual General and Ingredients
  metadata: title, canonical URL/ID, locale/timestamps, allowlisted remote image
  URLs, yield quantity/unit, explicit prep/cook/active/inactive/total time
  seconds, difficulty, one primary category label, bounded supported/required
  and optional device names, provider-listed equipment/utensil nouns, and ordered
  ingredient names with display-only exact/range values, unit, and bounded
  source amount text. Approved bounded factual ingredient topology also includes
  short ingredient-group titles, group and within-group ordinals, provider
  ingredient references/IDs, provider English/default titles, provider unit
  references/IDs, provider-declared optional booleans, and provider shopping
  category references/IDs. Store source amounts separately from ranking quantity/unit
  fields; they must not affect score, coverage, inventory, or cookability.
- Quantity parsing of manual/local/generated ingredient text is deterministic,
  multilingual, and advisory. Persisted parse metadata is separate from ranking
  quantity/unit fields. Cookidoo uses structured passthrough only, never source-text
  parsing. Model output is proposal-only with exact source evidence and explicit
  review; it has no automatic activation path.
- Time parsing for manual/local/generated recipes is deterministic and limited
  to existing bounded prep/cook fields or structured ISO-8601 durations. Never
  infer cook time from total minus active time; that difference is inactive/rest
  time only. Device facts remain separate from ordinary equipment, and any model
  fallback is proposal-only with no automatic activation path.
- Ingredient primary labels always come from bounded source text plus deterministic
  conservative cleaning/casing. Canonical/taxonomy labels are secondary read-time
  joins only; `closest_match` is limited to `taxonomy_alias`, `taxonomy_slug`, and
  `canonical_slug`, never `taxonomy_rule` or confidence alone.
- Grocery capability reports backend support for a complete nonempty ingredient
  list, not whether anything is currently missing. Only confirmed missing items
  are eligible; unsafe mappings dedupe by normalized source name rather than IDs.
- Recipe detail responses expose a matched product/relation only for an
  identity-satisfying in-stock match, or an exact identity match made missing
  solely by enforced insufficient quantity. Uncertain ancestry, component,
  derivation, and facet-conflict candidates remain internal.
- Cookidoo detail hydration is default-off and requires matching EverShelf and
  bridge configuration gates. Provider responses may co-transport official steps,
  but the bridge may only decode a bounded response and immediately project the
  existing factual metadata allowlist. Search, direct-ID refresh, and backfill may
  use that production path only while both gates are enabled.
- Recipe freshness controls refresh demand, never catalog membership. Stale
  cached rows remain searchable and cursor-stable while bounded best-effort
  refresh is queued after reads.
- Historical metadata-only writes may change only v2 General/freshness/version
  fields and the complete ordered source-ingredient list; they must not use the
  generic catalog save path or touch ranking ingredients, FTS, clusters, scores,
  or revisions.
- Official Cookidoo instructions remain prohibited. Co-transported step fields
  must never be inspected, exposed, logged, persisted, cached, or tested with real
  step text; `recipeStepGroups` is omitted from the safe projection. Also prohibit
  provider notes/tips, category or collection descriptions, nutrition, tags,
  preparation text/prose, unverified optionality, guided-cooking content, image
  bytes, and raw source payload persistence. Never access or persist ingredient
  `preparation`.
- Test list endpoints against live-sized fixtures under PHP's 128 MB memory
  limit, with bounded SQL/query counts and empty/broad searches.
