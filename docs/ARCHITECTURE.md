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

## Wake-driven canonical enrichment

- Product writes enqueue canonical work durably in the same foreground SQLite
  transaction and send a nonblocking Unix datagram only after commit. They do
  not perform provider or model calls.
- Canonical, controller, identity, shopping-classification, and recipe-score
  side effects use independent savepoints. A subsystem failure is returned as a
  degraded outcome and reconciled by bounded workers without rolling back the
  core product row.
- The singleton canonical worker computes taxonomy, FoodOn, and USDA evidence
  without a database transaction and under a deadline derived from the crash
  lease minus a reserved apply window. Budget exhaustion explicitly releases
  the claim; it never waits for crash reclaim.
- Provider caches use resident signature validation plus locked atomic
  read-modify-write publication. Provider paths never wait behind a held cache
  lock: busy or failed publication is logged, and the resident process retains
  the valid result. Different-key writers merge safely when published, and a
  transient hierarchy failure cannot replace a fresh positive. Cross-process
  cold misses for the same key are intentionally not single-flight
  deduplicated. FoodOn hierarchy failures store no partial term and use a short
  transient TTL.
- Apply uses one short `BEGIN IMMEDIATE` transaction. It verifies the current
  request generation, lease token/generation/expiry, and product fingerprint
  before writing canonical mappings. Canonical rows, queue completion, and the
  taxonomy-ready score job commit together.
- SQLite contention retries only the prepared result. Exhausted handled work is
  explicitly released to due scheduling. Three total executions use 2- and
  8-second delays before terminal failure; the 30-second third delay requires
  at least four executions. The tunable 120-second crash lease is reserved for
  process recovery, and inherited longer claims are clamped without changing
  their live token. The worker sleeps
  until the earliest retry, lease expiry, wake datagram, or 30-second safety
  poll.
- The resident worker and five-minute cron fallback share a writable canonical
  queue lock. An unavailable lock fails closed instead of allowing overlapping
  provider work. Status reports lock availability and treats blocked or stale
  due work as actionable.

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
- Cookidoo provider detail hydration is default-off and requires matching EverShelf
  and bridge gates. The bounded bridge request may receive a response that
  co-transports official steps, but only the `SafeRecipeMetadata` factual allowlist
  crosses the bridge API boundary; prohibited fields are never inspected, returned,
  logged, or persisted. EverShelf additionally requires the bridge execution-policy
  capability `metadata-v3-operator-enabled`; factual rows retain the independent
  `metadata-v2`/`ingredient-topology-v1` storage versions.
- Recipe workers claim under a short SQLite write reservation using immutable
  request hashes, monotonic request epochs, unpredictable lease tokens, lease
  generations, and expiries. A separate database-backed singleton process lease
  bounds concurrent cron/manual batches and is renewed between jobs; it is
  logical state, not a held SQLite or file lock. All transactions and flock
  probes finish before bridge I/O. A short catalog transaction then revalidates
  the job lease and per-origin/connector request epoch before atomically applying
  facts, pagination, connector outcome, and job completion.
- Existing SQLite recipe-job tables gain ownership fields additively. Their
  original table-level `CHECK` clauses are not rebuilt during upgrade; bounded
  statuses, generations, tokens, and expiries are enforced by claim/update
  predicates and migration regression tests instead.
- When hydration is disabled, new discovery/backfill enqueue is refused and
  existing queued jobs remain pending rather than being destroyed. Cached Cookidoo
  rows remain searchable while stale; TTL controls refresh demand, not catalog
  membership. Read-triggered refresh is bounded, best-effort, and independent of
  the bulk-backfill gate.

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
- FoodOn hierarchy and `resolved_parent` remain compatibility evidence only.
  They cannot lower controller risk, create accepted exact mappings, or satisfy
  an ingredient identity. Exact-self admission defaults on for legitimate
  unresolved foods and can be disabled only as an emergency operational guard;
  unresolved foods remain retryable rather than becoming `needs_review`.
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
  precede staging, and every phase is content-hashed/idempotent. Intake-only
  responses become durable generation intents and are rebound by portable slug
  without another model call. Generation waits for five minutes of quiet with a
  thirty-minute maximum latency and initial six/hour, twenty-four/day ceilings.
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
- Copied-database autonomous children may use persistent, lease-fenced keyset
  fork progress and bounded write reservations. The active database cannot
  fork, shadow, or promote. One collecting or shadowing child is permitted per
  copied parent. Exact semantic no-ops skip shadow scoring, while safe shadow
  retries reuse the completed child.
- Copied semantic no-ops never reuse the ordinary bundle import path. A
  lineage-bound acknowledgement resolves fallback jobs back to their original
  source intents, verifies source artifacts under the live reservation, applies
  or policy-defers each intent, and never imports rows or moves score pointers.
- Generation-intent scheduling gives exact constraints first priority, reserves
  only a bounded recent/high-priority lane, and drains the remaining capacity
  oldest-first so continuous arrivals cannot starve historical work.
- Sparse score publication binds one captured product/recipe watermark. Newer
  inventory, catalog, or source mutations remain journaled and pending while
  that immutable child becomes visible, then publish in the next child rather
  than invalidating an already completed scored prefix.
- Production activation uses lineage-bound manifest v2 plus immutable SQLite
  sidecars. Exact copied IDs are admitted only when baseline sequences match,
  imported in adaptive chunks, and validated on a copy. Ontology publication
  remains inactive until a separately imported score revision passes semantic
  fingerprints and one short pointer CAS; drift triggers cleanup and fresh-copy
  replay of durable intents. The 250 ms import and 100 ms publication values are
  operational alert budgets, not transactional deadlines: SQLite commit latency
  cannot be known before commit, so breaches are persisted for monitoring and
  chunk sizes are reduced on the next reservation.
- Successful ontology and score imports record `converged` or `activated`
  inside the same publication transaction. Those terminal-good outcomes clear
  prior failure/backoff and expected-drift counters only after the import CAS is
  committed. Local score-date rollover is expected rebase drift; immutable
  lineage mismatches remain fail-closed integrity errors.
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
- Reviewed recipe admission is occurrence-complete: every active occurrence
  for the subject is fingerprint-fenced, every distinct owning recipe receives
  its annex overlay and sparse dirty marker, and coverage gaps resolve only in
  the successful job-transition transaction. Full shadow builds synchronize
  annex rows in recipe batches, use multi-row upserts only for changed rows,
  and reuse matching seal/content/manifest-bound rows without writes.
- Prepared products stay in the existing prepared-meal taxonomy path and are
  excluded from autonomous ingredient expansion and backfill totals. Raw to
  prepared deactivates live occurrences/jobs; prepared to raw observes and
  requeues without deleting history.
- The optional `copilot_socket` provider uses a user-only Unix socket and a
  bounded local Python service. The service runs the exact host Copilot CLI with
  a versioned model whitelist, no available tools, custom instructions, MCP
  servers, remote export, or shell interpolation. PHP records the exact
  provider/model and never silently falls back.
- Copilot SDK bridge EOF, broken-pipe, I/O, restart, malformed-response, and
  mismatched-response failures stop the warm bridge and remain explicitly
  transient for bounded shopping-classification retry.
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
- Corpus Projection v2 separates mutable product/recipe bindings from the
  immutable semantic graph generation. Every score revision pins one immutable,
  hash-chained projection revision; there is no separately active projection
  pointer. Initial reconstruction checkpoints contain complete product and
  recipe aggregates. Routine rollover checkpoints instead reference the prior
  immutable head, carry the exact captured/covered fences and continuation,
  and contain only the current bounded delta. Subsequent revisions publish
  deterministic aggregate `REPLACE` or `DELETE`
  operations, including durable tombstone heads, while derived member and
  reverse-dependency tables remain rebuildable caches.
- Source journals capture old and new aggregate scopes for re-parenting.
  Product ingredients, canonical ingredients, taxonomy aliases, and recipe
  origin language are projection inputs. Dense journals load complete events
  and all of their scopes in bounded pages; they never issue one scope query per
  event or split one event across coverage commits. Missing journal rows use
  the durable scope copy for the same complete-event page.
  Every source event has an independent durable revision-indexed header, and
  its old/new scopes are copied into the same reconciliation log. Upgrade
  backfill copies headers and scopes in the same bounded page before advancing
  its checkpoint. A durable header with missing scope evidence fails closed to
  authoritative recovery rather than trusting only the new owner. A missing
  durable event fails closed. Explicit global invalidation uses a keyset-paged
  authoritative aggregate walk.
  Only semantic-generation fence changes or corrupt immutable evidence fail
  closed. Source-journal pruning and reconciliation GC are disabled until the
  versioned header-and-scope backfill finishes, then remove only revisions at
  or below every retained building/ready score and materialized projection
  fence.
- Publication makes the projection revision and sparse score child visible in
  one guarded transaction. Existing ready score rows are never restamped:
  migration publishes an immutable zero-score child. Rollback selects the prior
  score/projection tuple, and score compaction preserves that exact pin.
  Generic identity-event dedup accepts a physical score source only when its
  covered identity fence includes the event or the source is a freshly computed
  sparse delta whose pinned Corpus Annex is also the recipe aggregate's exact
  physical head. Inventory-only fan-out rows and copied full-compaction rows
  never manufacture per-recipe freshness.
- The worker selects the source high-water mark, dense journal window,
  aggregate closure, identity-extension snapshot, and owner fingerprints through
  bounded write phases surrounding a WAL read snapshot. Identity and recipe
  annex writes are chunked; aggregate resolution runs without a writer
  reservation; the prepared immutable delta is persisted in a short write
  phase. Coverage advances only through the last fully materialized contiguous
  event, while a hashed aggregate cursor continues high-fan-out alias/canonical
  work without retrying the first page. Publication requires exact inventory,
  catalog, source, identity, and active-parent fences plus the stored entry-set
  hash; it never rebuilds the plan while holding the publication lock.
- Alias inversion uses the same normalized `source_label` semantics as recipe
  aggregate construction (`raw_text`/source name before `normalized_name`).
  The derived member cache stores and indexes that lookup key. Missing keys,
  empty normalized aliases, or otherwise incomplete alias scope metadata fail
  closed or continue through the bounded worker cursor instead of silently
  dropping dependencies.
- Source-projection product IDs and score-fan-out commands are separate.
  Canonical/alias dependency closure may republish existing product aggregates,
  but only an actually pending product, a direct product-owner source event, or
  a non-scope-directed authoritative product mismatch opens a score fan-out
  cursor. Routine product classification links existing canonical slugs without
  rewriting their shared taxonomy/provider evidence; explicit enrichment jobs
  own those shared canonical updates.
- The reverse dependency cache indexes each accepted identity plus only
  mapping-specific `equivalent_to`, `variant_of`, and `substitutes_for`
  evidence. Non-satisfying `derived_from` and `component_of` relations remain
  in immutable member evidence for diagnostics but never widen inventory score
  fan-out through a shared ancestor key.
- Identity-extension changes are projection inputs even when no source row
  changed.   SQL discovery first records a monotonic durable identity event, then keyset
  pages reverse-dependent recipes into a queue carrying first/latest event
  identities and required identity-extension revision/hash. Former product
  bindings are retained separately, so delete, detach, and rebind events page
  both former and current dependencies. Each pass discovers and processes at
  most the configured aggregate limit. Newer identity
  revisions may be discovered while older pages remain, but the separately
  stored covered identity prefix advances only after every required recipe
  through that prefix has published. Score and projection revisions pin exact
  captured and covered identity revision/hash pairs.
- Product-to-recipe score contributors use a durable product cursor. Each
  contributor source is keyset-paged, the combined page is capped by the
  incremental limit, and the product remains pending until every historical
  and current dependent recipe has been republished. A changed product or
  inventory fingerprint resets only that product cursor.
- Worker selection rotates serving recipes, products, and maintenance recipes,
  while reserving capacity for pending source and identity projection work.
  The low-latency serving bypass periodically yields to a coordinated fairness
  cycle, so sustained serving traffic cannot permanently starve maintenance or
  identity work. If the active score date is stale, identity-only or source
  maintenance returns `score_date_refresh_required` without consuming its
  durable queue or creating failed revisions; the activation worker owns the
  daily refresh. Urgent serving work may publish first, but identity projection
  remains deferred until that serving child advances the active score date.
- Worker planning verifies bounded revision/manifest lineage and publication
  fences; foreground reads validate only the exact pinned tuple and never scan
  checkpoint entries. The deployment and
  release deep-integrity audit streams every immutable entry and recomputes
  entry-set, aggregate, source, and projection hashes under a fixed memory
  ceiling, recursively following every rollover `checkpoint_source`. Derived
  projection caches reject unguarded writes and can be rebuilt from the audited
  chain. Replay independently verifies each revision's stored entry-set hash.
  Repair is worker-only and refuses to replay a chain until that immutable
  evidence passes the transitive deep audit.
- Recipe browse, score resolution, and processing status are observational:
  they never acquire the projection write lock or reconstruct derived rows.
  HTTP reads consume only singleton worker-produced projection and processing
  snapshots plus bounded active-pointer/high-water joins. The verdict exposes `computed_at`,
  freshness, captured/covered/current source and identity fences, and pending
  identity work. A cache-schema mismatch or missing materialization is reported
  as `projection_repair_required`; a worker performs the guarded repair.
- Each bounded projection page can roll over before the lineage limit, including
  while source or identity coverage is partial. The rollover root references
  the previous immutable head, preserves its continuation, applies only the
  current delta to the materialized cache, and atomically publishes a zero-score
  child. Entry-count rollover is delayed until 100,000 direct delta entries
  (or the bounded lineage-depth limit), avoiding one marker per ordinary
  production page. Checkpoint traversal is iterative and cycle-checked rather
  than failing at an arbitrary reference count. Interrupted attempts remain
  unreachable. Guarded cleanup may remove unreferenced failed or abandoned
  building revisions and their entries, but ready revisions and entries remain
  immutable and retained scores keep their exact historical prefixes.
- The disposable production benchmark rejects unbounded identity
  `GROUP_CONCAT`, constrains candidate fan-out to its page budget, records
  named corpus-proportional operations, enforces a five-second maximum
  contiguous write reservation, and verifies zero-entry rollover markers.
  `--worker-lifecycle` runs scenario pages through the actual score worker and
  its lock, cleanup, status, and compaction path. Snapshot-race and
  identity-arrival probes are durable, database-visible fixture rows consumed
  at the worker's exact snapshot phases, so subprocess execution exercises the
  same races as direct mode. Each worker result reports its process-local
  operation counters, full-corpus scan count, Linux peak RSS, and PHP allocator
  peak; lifecycle limits use the worker RSS rather than the benchmark parent's
  memory. The production-validated default work cap is 250 aggregates.
- Schema migration installs projection storage and CDC only; it never performs
  a synchronous full-corpus checkpoint or effective-score projection rebuild
  on an API request. Replacing or repairing an equivalent CDC trigger set
  updates only trigger metadata; it does not advance the semantic source fence
  or invent a global mutation. Recipe identity resolver upgrades likewise page
  stale annex rows and emit scoped maintenance recipe batches; they do not
  advance the source fence or create a zero-scope authoritative event.
  Semantic-generation transitions use the explicit fail-closed transition
  reason. A legacy missing effective-projection pointer remains
  unset and is surfaced as `score_projection_repair_pending` until the worker
  reconstructs it. Legacy rows without an explicit identity-coverage field
  start at the zero prefix and are selectively replayed rather than being
  trusted as fully covered. Before enabling
  projection-backed maintenance on an upgraded database, run one forced
  incremental-score worker cycle on the writable deployment database (and first
  on its disposable validation copy). A missing pin is reported as
  `projection_bootstrap_pending`, remains handled without creating ontology
  build intent, and the worker publishes the checkpoint through an immutable
  zero-score child.
- Full-resolution v3.17 data under ontology schema v3.17 uses
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
