# EverShelf — Architecture (modular layout)

```
dispensa/
├── api/
│   ├── bootstrap.php       # Shared init: env, security, DB, logger
│   ├── index.php           # HTTP handlers + router (split planned per domain)
│   ├── database.php        # SQLite schema & migrations
│   ├── logger.php          # Rotating file logger (logs/)
│   ├── cron_smart_shopping.php  # CLI cron (uses bootstrap + index handlers)
│   ├── lib/
│   │   ├── env.php         # .env loader
│   │   ├── constants.php   # Paths & pricing constants
│   │   ├── security.php    # API auth, CORS, demo mode, scale allowlist
│   │   ├── recipes/        # Catalog schema/search/scores/connectors/detail/grocery
│   │   ├── github.php      # Encrypted GitHub Issues token
│   │   └── cron_log.php    # data/cron.log rotation
│   └── scale_*.php         # Scale gateway helpers (auth + SSRF guards)
├── assets/
│   ├── js/
│   │   ├── core/           # auth.js, dom.js (loaded before app.js)
│   │   └── app.js          # SPA logic (domain modules: future split)
│   └── vendor/             # Offline CDN fallbacks (quagga, transformers)
├── data/                   # Runtime data (.htaccess: deny all)
├── logs/                   # Application logs (.htaccess: deny all)
└── scripts/                # migrate-env-security, fix-permissions, encrypt-gh-token
```

## Security model

- **`API_TOKEN`** (or legacy **`SETTINGS_TOKEN`**): when set, every API action requires the `X-API-Token` header. Query-string tokens remain legacy-compatible but are deprecated because URLs are commonly logged.
- Secrets (`HA_TOKEN`, `TTS_TOKEN`, `GEMINI_API_KEY`) stay in `.env`; `get_settings` exposes only `*_set` flags.
- **`GH_ISSUE_TOKEN_ENC`** + **`GH_ISSUE_TOKEN_KEY`**: AES-256-GCM encrypted GitHub Issues token.

## Recipe metadata boundary

- `recipe_catalog_detail` is a bounded projection, not a persistence-row endpoint.
- Cookidoo `metadata-v2` source ingredients are stored in ordered
  `recipe_source_ingredients` rows with bounded group titles/ordinals, provider
  ingredient/default-title/unit references, provider optionality, and shopping
  category references. Display amounts never populate ranking
  `recipe_ingredients.quantity/unit`, so materialized scores retain their existing
  semantics.
- Non-Cookidoo ingredient text also receives a deterministic, versioned quantity
  parse covering multilingual units, fractions, ranges, packages, and qualifiers.
  Its bounded JSON is advisory metadata on `recipe_ingredients`; it never populates
  ranking quantity/unit fields. Cookidoo bypasses this text parser and accepts only
  validated structured quantity shapes. Unresolved non-Cookidoo text may be exported
  to a versioned prompt, but strict exact-span validation can only create a pending
  `recipe_quantity_parse_proposals` row. Review has no apply/activation path.
- Source mappings carry an explicit mapper version. Bounded local remap jobs operate
  only on stored source names, allowing a future ontology resolver to run without a
  provider re-fetch; only the legacy mapper is active today.
- Detail ingredient labels come from bounded source text and deterministic
  conservative cleaning/casing, including complete approved amount-plus-unit
  prefixes found only in legacy source text. Grocery names use that display label,
  while unsafe source-name dedupe removes the same complete prefix without dropping
  source descriptors. Canonical/taxonomy labels are resolved through indexed joins
  as secondary mapping metadata; taxonomy-rule labels never become `closest_match`,
  display, or grocery identity.
- Grocery capability describes support for a complete nonempty list. The sibling
  grocery state carries missing/uncertain/in-stock/staple/eligible counts and
  no-ingredient/truncation blockers.
- Official Cookidoo steps, notes, nutrition, ingredient preparation text/prose, raw
  payloads, image bytes, and Guided Cooking content are excluded. The bridge never
  accesses ingredient `preparation`. Cookidoo instructions are represented only by an
  external canonical-link capability; the optional group property is omitted. Authorized
  local/manual/generated instructions may persist bounded labels plus step-position
  group references.
- `recipe_catalog_grocery_add` revalidates inventory, canonicalizes selected
  ingredients, and writes only to EverShelf's internal shopping list with durable
  client idempotency. Home Assistant orchestration remains outside EverShelf.
- Cookidoo provider detail hydration is policy-disabled because the available detail
  response co-transports official steps. Bridge search/direct metadata fail locally
  with `metadata_hydration_disabled_policy`; no production path calls raw/public
  detail loaders. EverShelf refuses new discovery/backfill enqueue and marks legacy
  queued jobs `skipped` without connector failure/circuit accounting.
- Existing cached Cookidoo catalog rows remain readable and retain their historical
  `metadata-v2`/`ingredient-topology-v1` facts. No policy-disabled path deletes
  catalog data or changes search, clusters, scores, or revisions. Re-enabling would
  require a separately reviewed step-free provider endpoint.

## Ingredient ontology v3

- Additive version rows own schema/prompt/model/source-corpus/ontology-content
  hashes and a lifecycle report. Source owner fingerprints include mapping inputs:
  product identity text (not package quantity/unit), recipe language/source text,
  authoritative requiredness, and provider optionality/reference. They exclude
  mutable legacy mappings and legacy-cleared required/staple flags. Versioned
  entities use one accepted primary `is_a` parent at most;
  reviewed nonhierarchical relations and closed hard/soft facets remain orthogonal.
- Product, legacy recipe-ingredient, and source-ingredient rows each receive
  exactly one version-isolated mapping and one immutable fingerprint-bound D1-D9
  terminal disposition. Deduplicated global-label, provider-term,
  product-fingerprint, owner-fingerprint, and cohort-context scopes avoid
  repeating review prose. Candidate and undispositioned counts must be zero.
  Full corpus work streams bounded SQLite pages/cursors and never hydrates the
  recipe corpus into PHP.
- D3-D6 require exact reviewed manifest rows. Cohort votes, provider refs,
  regexes, punctuation, alternatives, quantities, and modifier parsing are
  retained as evidence hints only; absent review, recipe rows terminate D9.
  Candidate assertions are snapshotted append-only before terminalization.
- Entity `identity_role` is orthogonal to `entity_kind`. The graph has one
  connected `food` root; structural categories are identity-ineligible,
  prepared/composite identities can match only the identical base, and
  `staple_class` requires an explicit staple path. Curated/core parent manifests
  outrank legacy parents, and every changed or removed primary edge has an
  immutable reviewed diff.
- Identity requires the same base entity and compatible defining attributes.
  Reviewed equivalence is explicit; variants stay visible as variants; substitutes
  never make a recipe cookable. Ancestry, component/contains, derivation,
  lexical/rule/model evidence, and unknown defining attributes do not satisfy a
  required ingredient.
- Provider refs form review clusters only and can never inject a base or defining
  facet into an unrelated local mapping. Evidence is keyed by exact owner
  fingerprint plus connector/schema/ref/title hashes. Recipe cohorts persist
  votes, winner, margin,
  conflicts, and algorithm hash; they gate contextual aliases but never prove
  identity. Exact-span comma/parenthetical modifier evidence is proposal/review
  evidence, with unknown residue blocking.
- Proposal prompts fence untrusted text and expose only closed entity IDs, facet
  enums, and relation types. Imported JSON is staged in immutable-hash change sets.
  No model path writes entities, labels, mappings, or scores. Reject/dispose/safe
  revert lifecycle operations update unapplied pending/approved sets and children
  transactionally and append immutable actor/reason/timestamp events. Applied sets
  fail closed without representable inverse provenance.
- The autonomous controller adds a recipe-independent identity substrate above
  owner mappings. Immutable subjects exclude recipe/position/quantity/requiredness
  from identity. Occurrence provenance is derived only from its immutable key;
  mutable quantity/unit/requiredness/staple/group context is retained in
  payload-hashed append-only ingestion observations. Product subjects
  include barcode/name/brand/category/generic/ingredient/prepared facts but not
  inventory quantity or package size. Occurrence identity includes subject,
  owner type, owner row ID, and the unchanged canonical owner fingerprint, so
  content-equivalent legacy recipe rows remain distinct. Unique subject jobs
  still prevent recipe-by-recipe model work.
- Explicit decisions append immutable observations and a monotonic global
  constraint epoch in the same `BEGIN IMMEDIATE` transaction as the visible
  override. One partial unique index permits only one live exact constraint per
  correction stream. Exact `must_equal`/`must_not_equal` constraints are
  immediate; model work may generalize them only inside a forked building child.
- Controller jobs use lease token, lease generation, required epoch, and
  controller generation as one completion fence. Prompts precede calls, responses
  precede staging, and every phase is content-hashed/idempotent. Generation waits
  for 30 seconds of quiet with a five-minute maximum debounce and initial
  six/hour, twenty-four/day ceilings.
- Recipe occurrence retirement uses an indexed recipe-ID JSON expression and
  active-row predicate. Late-phase failures transition from their actual fenced
  state immediately, and a partial database unique index prevents duplicate
  pending children for one parent/generation key.
- Product and recipe persistence call savepoint-isolated observation wrappers.
  `ONTOLOGY_AUTONOMOUS_ENABLED=false` skips them; when enabled, controller
  failure rolls back only controller evidence, logs bounded degradation, and
  never rolls back the core product/recipe transaction.
- Each generation records only the exact correction-stream heads consumed by
  its plans. Promotion rechecks those heads plus the complete active constraint
  snapshot; monitoring ignores unrelated later epochs but rolls back on a
  same-stream reversal. Compatible feedback work shares one debounced child.
- Generalized repairs require an immutable imported benchmark policy and a
  durable provider-neutral P7 critic artifact after realized shadow impact is
  available. The critic is subtract-only; block, malformed output, or
  unavailability quarantines. Finalization, critic, and gold maturity/dual-run
  cycles are scheduler-driven job types.
- Proposals never target a ready version. `ingredientOntologyV3ForkVersion()`
  rebinds portable references into an unreferenced child; unchanged forks retain
  the same portable content hash. The deterministic applier supports only
  table-driven closed repairs and materializes exact constraints before all-edge
  graph, corpus, gold, shadow, blast, and activation gates.
- One primary navigation `is_a` plus at most two secondary parents is supported
  only when all accepted `is_a` edges form a food-rooted DAG with depth, ancestor,
  and path caps. Secondary ancestry and all typed relations remain non-satisfying.
- Gold authority is an immutable hash-linked release registry. Mature direct
  correction pairs and rollback/quarantine adversarial cases dual-run before an
  autonomous hash-CAS advances the active release; insufficient evidence remains
  a candidate without creating a human review queue. Adversarial cases actively
  reject recurrence of a quarantined plan hash; correction candidates must be
  stable, non-oscillating, and survive the required promoted-version lineage.
- Coverage is independent from generalized mutation quality. Every enabled,
  non-prepared product and nonempty recipe owner has an immutable subject and
  active occurrence. Abstention/quarantine creates an unresolved owner mapping
  and portable, non-satisfying provisional leaf beneath the structural
  `Unclassified ingredient` node; quarantine isolates the mutation, never the
  subject. Backoff/circuit records permit bounded retry after policy/evidence
  changes or a retry horizon.
- Prepared products stay in the existing prepared-meal taxonomy path and are
  excluded from autonomous ingredient expansion and backfill totals. Raw to
  prepared deactivates live occurrences/jobs; prepared to raw observes and
  requeues without deleting history.
- The optional `copilot_socket` provider uses a user-only Unix socket and a
  bounded local Python service. The service runs the exact host Copilot CLI with
  a versioned model whitelist, no available tools, custom instructions, MCP
  servers, remote export, or shell interpolation. PHP records the exact
  provider/model and never silently falls back.
- Production cron is intake-only: it records subjects/occurrences, exact R0
  constraints, immutable model responses, and provisional queue intents, but
  never forks ontology versions, builds shadow scores, monitors, or promotes.
  Generation/shadow/promotion commands remain copy-only CLI operations;
  production promotion stays false for this release.
- Intake claims are SQL-bounded by priority. Live products enqueue/reset
  subject resolution at priority 100 and live recipe ingredients at 50;
  historical backfill stays at 0. Production cron/work uses a minimum of 50,
  preserving the historical backlog for an explicit copied-database batch while
  allowing fresh evidence to revive terminal jobs under new lease fences.
- Backfill uses keyset pages, bounded per-batch transactions, durable
  checkpoints, and indexed temporary fingerprint/collision tables; copied
  production validation runs within 128 MB. Coverage validity binds each owner
  to its recomputed canonical owner and subject fingerprints, while the status
  endpoint serves only a cached/stale materialization.
- A candidate ontology can build a complete materialized shadow score revision.
  The active ontology is derived by joining the single active score revision to
  its nullable `ontology_version_id`; there is no separate active ontology setting.
  Activation enters `BEGIN IMMEDIATE` and rechecks inventory, catalog, hashes,
  owner fingerprints, blockers, completeness, active pointer, and score date
  before changing only the score pointer and cursor revision. Manual production
  activation also validates a complete retained parent score revision, including
  first-deployment legacy baseline materialization. Rollback directly
  restores one of eight retained proven ancestors without freshness gates;
  non-ancestors use full activation validation and must be a v3 child of the
  current active revision.
- Full-resolution v3.16 data under ontology schema v3.17 uses
  `activation_policy=manual_review`. Candidate and score builds remain copy-only;
  activation requires the exact frozen production profile, reviewed subject
  sets, graph/gold/integrity and materialized ID/value gates, a valid rollback
  baseline, and explicit CLI confirmation. Requirement-projection and source-aware revisions remain
  shadow-only.
- All 304 roles, final primary-parent decisions, and previous-to-final edge
  transitions are exact manifest tuples; generated fallback cannot become
  reviewed. Identity-to-identity parents and critical derivation/component
  corrections have separate semantic review. Ready ontology and materialized
  score/match/requirement content is immutable. Portable hashes exclude
  surrogate IDs, cross-copy audit compares exact disposition outcomes, and
  activation performs bidirectional anti-joins plus stored ID/value hash checks.
- Once a v3 score is active, the shared-lock scheduled rebuild path builds only
  against that exact ontology version, validates current owner fingerprints plus
  version/corpus/content hashes before freshness or replacement, and returns
  `ontology_stale` without moving the pointer when a new candidate/remap is
  required. Exclusive lock acquisition terminalizes every pre-existing legacy or
  v3 `building` revision before reuse/build decisions, allowing pruning of
  bounded partial rows while same-input recovery creates one replacement. Request reads
  serve a stale active v3 pointer rather than forcing a replacement. Revision
  pruning retains the active revision, eight ancestors, the latest same-parent
  idempotency candidate, four recent ready-v3 revisions, and two legacy revisions;
  cleanup failure is a warning after a committed activation. Compatible inventory
  rows are unioned by inventory-row ID for quantity and minimum expiry. Quantity
  enforcement is separately gated and defaults off. A deterministic per-revision
  `scoring_config_hash` covers the v3 scoring model/version and gate state,
  participates in freshness/reuse, and is rechecked by reports, activation, and
  v3 rollback.
- Hand-adjudicated resolution gold contains 84 positives and 52 critical
  negatives with exact outcomes, facets, evidence, owner/product/source
  bindings, supersession provenance, a code-pinned SHA-256, and explicit
  maintainer review metadata. Code validates the artifact but does not claim
  model independence. Its confidence is deterministic regression coverage, not
  corpus-wide statistical precision. Generated
  assertions for accepted manifest rows remain a separate resolution snapshot.
  Matcher validation still requires expected negatives and critical negatives,
  precision/recall, zero false negatives, and zero critical false positives.
  Every assertion attribute must use a facet key/value allowed by the selected
  entity policy.
- Public recipe details project inventory identity only for satisfying in-stock
  matches, or exact identity matches made missing solely by enforced quantity.
  Non-satisfying uncertain ancestry, derivation, component, and facet-conflict
  candidates remain internal and expose no product, relation, or candidate
  confidence.
- The frozen Copilot benchmark selected Gemini 3.5 Flash for proposals. Pro showed
  missing facets/inconsistent entity choices; one 3.6 Flash run marked all hard
  attributes non-defining. Deterministic validators always override that field.

## Planned refactors

1. Split `api/index.php` handlers into `api/handlers/{products,inventory,ai,shopping}.php`
2. Split `assets/js/app.js` into ES modules under `assets/js/features/`
3. Optional `npm run build` to minify JS/CSS (see `package.json`)
