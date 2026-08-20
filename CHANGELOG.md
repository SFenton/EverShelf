# Changelog

All notable changes to EverShelf will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.15.2] - 2026-08-20

### Fixed
- Product saves now queue a sparse source publication even before inventory is
  added, so rapid save-then-stock flows cannot expose an unscoped source
  revision to the score worker.
- Sparse snapshots adopt every product owner present in the bounded source
  mutation journal, preserving prefix publication when a later product save
  lands between pending discovery and the write snapshot instead of entering
  the 30-second failure backoff.

## [1.15.1] - 2026-08-20

### Fixed
- Ontology, recipe-score, and canonical workers now use the shared
  schema-version marker and migration lock. Simultaneous container restarts
  no longer rerun idempotent schema writes against the 49 GB live database or
  crash-loop with `database is locked` after the web service has migrated it.

## [1.15.0] - 2026-08-20

### Added
- Product-local, fingerprint-fenced exact-number admission maps safe English
  plurals to a unique reviewed ingredient identity before any model, FoodOn
  lookup, ontology generation, or copied database work.
- Per-product semantic readiness records expose accepted-but-unscored,
  scoring, ready, retry, needs-review, non-satisfying, and failed states with
  bounded retries, visible latency, affected recipe counts, and diagnostics.

### Changed
- Canonical enrichment, product saves, controller admission, and incremental
  scoring now publish annex identity and score readiness through the same
  fenced transaction path.
- Deterministic admission validates every active product occurrence sharing a
  subject and supersedes only obsolete subject-resolution work, preserving
  exact correction constraints as audit evidence.
- Resolver migration refreshes existing inventory annex rows in place,
  backfills unchanged accepted products as ready, and sparsely queues only
  products whose effective identity changed. Migration now advances in
  bounded, crash-recoverable product transactions instead of one long writer
  reservation.

### Fixed
- Product-only source drift represented by the identity annex no longer
  launches a copied ontology refresh or full-corpus score rebase.
- Activation defers copied score refreshes while valid product-local sparse
  work is pending, eliminating its race with the incremental score worker.
- A legitimate copied score fallback now backfills readiness for accepted
  products whose pending rows it consumes.
- Annex reconciliation now commits identity, readiness, and score queuing
  atomically per product, so a crash cannot leave current identity with lost
  score work.
- Post-publication telemetry failures no longer report a committed score
  revision as failed or demote ready products after their pending work was
  cleared.
- Product-source changes that land during sparse preparation recompute the
  semantic fence inside the publication snapshot instead of backing off for
  30 seconds.
- Unresolved products no longer disappear after a zero-impact score
  publication; retries terminate visibly within 30 seconds instead of
  hot-looping indefinitely.
- Idle retry polling no longer reserves the SQLite writer, and terminal
  review/failure rows no longer inflate pending-age diagnostics.
- Successful activation clears stale reservation warnings, and the example
  live controller no longer includes copy-generation flags rejected by the
  active-database CLI.

## [1.14.2] - 2026-08-20

### Fixed
- Canonical/product corpus drift is now compared directly with the active
  ontology version. A changed corpus selects the copied ontology refresh path
  instead of attempting an obsolete score-only refresh that must fail its
  sealed-corpus validation.

## [1.14.1] - 2026-08-20

### Fixed
- A required copied score refresh now takes priority when ontology generation
  claims only policy-deferred/no-work intents, preventing daily score-date
  rollover or inventory changes from remaining stale behind that backlog.

## [1.14.0] - 2026-08-20

### Added
- Canonical queue due scheduling, adaptive wake timing, and bounded processing
  health for pending retries, active/overdue leases, and terminal failures.

### Changed
- Canonical enrichment now computes provider evidence outside SQLite and
  atomically commits fingerprint-fenced mappings, queue completion, and
  taxonomy-score enqueue in one short transaction.
- Retryable SQLite contention reuses the prepared result, explicitly requeues
  handled failures with attempt-aware backoff (2s and 8s for the default three
  executions; 30s only with at least four), and reserves the tunable
  120-second crash lease for process recovery.
- Provider work now has a lease-derived deadline with a reserved atomic-apply
  window. Budget exhaustion is explicitly requeued instead of becoming an
  expired healthy claim.
- FoodOn and USDA caches now serialize full read-modify-write publication,
  merge different-key writers, track atomic-renamed files in resident state,
  and continue valid classification when persistence is busy or fails without
  waiting behind another publisher. FoodOn hierarchy failures retain no partial
  record, cannot replace a fresh positive, and use a separate short transient
  TTL.

### Fixed
- A failure-state `SQLITE_BUSY` can no longer mask the primary canonical error
  or leave handled work leased until crash recovery.
- Canonical processors fail closed when their shared queue lock is unavailable;
  rate-limited diagnostics and processing status expose blocked/stale due
  work while historical terminal failures remain diagnostic rather than
  permanently unhealthy. Container startup repairs only the dedicated
  queue/provider-cache lock files without traversing the database volume.
- Stale request generations and expired leases cannot write canonical product
  data before their completion fence is rejected. Direct non-queue syncs also
  recheck and rebuild once before writing.

## [1.13.19] - 2026-08-19

### Fixed
- Any copied batch whose claimed jobs all return the controller's typed `retry`
  status is advisory pending work, regardless of the individual retry reason.

## [1.13.18] - 2026-08-19

### Fixed
- Copied batches containing only bounded search expansion or in-flight
  generation retries are recorded as advisory pending work instead of
  bundle-readiness integrity failures.

## [1.13.17] - 2026-08-19

### Fixed
- Copied jobs deferred solely by missing measured benchmark policy now enter
  the verified no-op acknowledgement as `defer` actions instead of reporting a
  bundle-readiness integrity failure.

## [1.13.16] - 2026-08-19

### Fixed
- The resident sparse score worker runs with a 512 MB PHP memory envelope,
  preventing production-scale matcher context from exhausting PHP's 128 MB
  default during multi-product reconciliation.

## [1.13.15] - 2026-08-19

### Fixed
- After ontology activation, inventory identity annex rows are reconciled to
  the new active version and journaled under one sparse inventory revision.
  Newly reviewed products therefore receive recipe matches automatically.

## [1.13.14] - 2026-08-19

### Fixed
- Dynamic provider corpora use their sealed snapshot counts and reviewed/D8
  audits instead of the historical fixed 3,100-row pilot count.

## [1.13.13] - 2026-08-19

### Fixed
- Dynamic provider corpus validation accepts terms beyond the reviewed manifest
  only when each remains entity-less, attribute-less, unresolved D8 evidence.

## [1.13.12] - 2026-08-19

### Fixed
- Provider facet audits validate accepted contextual mappings only. Missing-
  context mappings remain explicitly unresolved and are covered by separate
  frozen gold and owner-outcome gates.

## [1.13.11] - 2026-08-19

### Fixed
- Provider facet audits honor each mapping's immutable mapping-time context
  gate. Evidence observed later cannot retroactively turn a correctly
  unresolved occurrence into an activation failure.

## [1.13.10] - 2026-08-19

### Fixed
- Provider facet audits now require the owner-scoped evidence gate before
  expecting a contextual provider-local alias to resolve. Unrelated occurrences
  remain correctly unresolved instead of blocking activation.

## [1.13.9] - 2026-08-19

### Fixed
- Provider terms observed after the frozen review set receive explicit dynamic
  D8 provider-specific unresolved dispositions. They remain fail-closed
  without blocking reviewed ontology activation.

## [1.13.8] - 2026-08-19

### Fixed
- Products added after the frozen review set receive explicit dynamic D9
  unresolved dispositions during reviewed rebuilds. They remain fail-closed
  without blocking activation of independently reviewed identities.

## [1.13.7] - 2026-08-19

### Fixed
- Dynamic placeholder parent links are applied after the explicit reviewed-edge
  reset, so they survive final graph construction and validation.

## [1.13.6] - 2026-08-19

### Fixed
- Autonomous structural placeholders are attached to the non-satisfying
  `ingredient` branch so dynamic reviewed candidates remain one connected,
  food-rooted graph.

## [1.13.5] - 2026-08-19

### Fixed
- Dynamic reviewed candidates retain product-derived legacy canonical entities
  only as autonomous structural placeholders. They remain identity-ineligible
  until explicitly reviewed instead of blocking the reviewed ontology build.

## [1.13.4] - 2026-08-19

### Fixed
- Fresh reviewed candidates use controller dynamic corpus and subject pins, so
  normal production catalog growth does not violate stale static manifest
  counts.

## [1.13.3] - 2026-08-19

### Fixed
- Reviewed-manifest changes now force a fresh copied ontology candidate instead
  of inheriting the previous immutable manifest row through a controller fork.
- Full score bases may clear sparse source lineage only when their source hash
  matches the current canonical corpus; permanent lineage mismatches remain
  fail-closed.

## [1.13.2] - 2026-08-19

### Fixed
- New ontology and score publication now takes priority over stale import
  purging. Rebase cleanup remains bounded maintenance work and can no longer
  starve user-visible identity or recipe updates behind historical imports.

## [1.13.1] - 2026-08-19

### Fixed
- Historical terminal failed activation imports no longer re-enter cleanup on
  every scheduler cycle, block newer bundles, or report themselves as current
  activation work when referenced rows cannot be purged.

## [1.13.0] - 2026-08-19

### Added
- Reviewed Eggplant identity and multilingual exact aliases shared by product
  and recipe identity admission, with targeted manifest revision invalidation.
- Wake-driven canonical queue worker with a 30-second dropped-wake safety poll.
- Auditable controller coverage-gap records and processing backlog diagnostics.

### Changed
- Semantic no-op activation acknowledgements now carry verified apply/defer
  actions without importing rows or moving active pointers.
- Generation-intent scheduling combines exact-constraint priority, a bounded
  recent lane, and deterministic oldest-first draining.
- Shopping socket timeouts and transport failures retry in 60–120 seconds;
  deterministic abstentions retain the longer negative cache.
- Activation CDC ignores cosmetic shopping-name/timestamp changes and treats
  legacy canonical enrichment as non-fencing evidence for active v3 scoring.
- Sparse publication may commit its captured catalog/source watermark while
  newer journaled mutations remain pending for the next immutable child.
- Full shadow scoring synchronizes reviewed recipe annex rows in bounded
  transactions and skips unchanged overlays instead of autocommitting each
  ingredient.
- Restartable Copilot SDK bridge EOF, broken-pipe, I/O, and restart failures
  use the same bounded transient retry policy as socket outages.

### Fixed
- Final candidate shards can no longer request expansion, exact-multiple and
  policy-truncated pools terminate without an empty-shard failure, and
  low-signal unauthorized identities create one non-satisfying review artifact.
- Expected no-op, policy-deferred, snapshot-superseded, and rebase outcomes no
  longer latch global degraded processing health.
- Committed ontology/score imports now record terminal `converged`/`activated`
  outcomes that atomically clear prior failure and expected-drift state.
- Local score-date rollover is a typed rebase condition in standalone,
  generation, copied-validation, and final-publication paths.
- Reviewed recipe admission refreshes every active occurrence owned by a
  subject before resolving its coverage gap.

## [1.12.0] - 2026-08-18

### Added
- Immutable sparse score revisions with effective-source projection, mutation
  journals, normalized inventory contributors, recipe-local append/edit/delete,
  bounded background pruning, and full projection compaction.
- Live scoring progress and pending product/recipe diagnostics for EverShelf
  and Home Assistant.
- Deterministic recipe identity annex admission tied to the active ontology
  seal, with scoped source lineage and copied-refresh fencing.

### Changed
- Inventory and recipe changes publish only affected score/match rows. Newer
  mutations remain pending so continuous writes cannot starve visible updates.
- Copied activation reserves imported ID ranges and uses a bounded import time
  budget instead of a four-chunk-per-minute ceiling.

### Fixed
- Product merge invalidation, source-fingerprint fencing, orphan overlay
  terminalization, historical match ownership, post-compaction rollback
  ancestry, stale work-state recovery, and unscored recipe detail handling.

## [1.11.5] - 2026-08-18

### Fixed
- Processing health no longer treats intentional
  `generation_abandoned` maintenance terminalization as an actionable
  24-hour failure; genuine recent ontology failures remain visible.

## [1.11.4] - 2026-08-17

### Fixed
- Ready ontology versions now apply reviewed exact/attribute alias admission
  read-only when a current-version annex row has not yet been persisted.
  Full score rebuilds therefore preserve deterministic product identities
  across ontology activation without rewriting products or advancing catalog
  revisions. Processing counters report these effective admissions.

## [1.11.3] - 2026-08-17

### Fixed
- Container startup now validates the configured timezone, updates the system
  zone, and injects `TZ` plus `CRON_TZ` into EverShelf's cron file. Copied
  score builds launched by cron therefore use the same business date as web
  and worker processes.

## [1.11.2] - 2026-08-17

### Fixed
- Controller maintenance now transactionally fails abandoned generation
  records, candidate versions, and finalize jobs after the retention window,
  preventing obsolete high-priority jobs from permanently reporting a
  processing problem.

## [1.11.1] - 2026-08-17

### Fixed
- The configured `TZ` now defines one score-business date across inventory
  filtering, expiry distance, score builders, workers, activation fences, and
  diagnostics. Pacific-day revisions no longer become stale after UTC
  midnight or score future expiries one day too urgently.

## [1.11.0] - 2026-08-17

### Added
- Manual product commits now receive a seal-bound deterministic identity
  admission from reviewed exact or attribute aliases without mutating the
  active ontology. A separately reviewed Russet Potato alias maps to Potato,
  while unknown, prepared, structural, and provisional identities remain
  non-satisfying.
- A dedicated incremental score worker coalesces changed products, publishes a
  validated affected-recipe overlay, and then materializes an immutable child
  score revision with atomic rollback lineage.
- Processing status reports identity admission counts, pending score products,
  active overlays, and the most recent incremental publication.
- A live copied-database harness exercises committed Russet Potatoes and Red
  Onion flows through the logged-in Copilot SDK with key-based Gemini
  environment variables removed.

### Fixed
- Location AI now requires a committed product ID plus its current
  server-derived fingerprint. Partial typing can use exact household history
  only and cannot read/write AI cache entries or consume Copilot capacity.
- Location responses are single-flight per committed product, reject stale
  names/fingerprints, and use bounded versioned cache retention.
- Controller candidates include reviewed aliases and plural token matches,
  exclude provisional controller entities, and reject identity-ineligible
  mapping targets during application.
- Intake-only `expand_search` responses now advance durable candidate shards
  instead of becoming stranded generation intents.
- Sparse scanner product commits preserve omitted metadata, and direct review
  edits are recommitted before inventory is added.
- Concurrent sparse commits now resolve duplicate ownership and omitted fields
  under the write lock; explicit barcode conflicts cannot rewrite the winning
  product.
- Scanner-shaped saves omit server-derived shopping names so Copilot and legacy
  provenance remains intact unless a caller intentionally supplies a name.
- Rejected annex decisions suppress older sealed mappings, while unresolved
  decisions preserve a current-fingerprint reviewed mapping; deleted products
  retain their pending score work until prior matches are removed.
- Incremental invalidation retains every aggregate inventory contributor, and
  catalog mutations atomically retire stale score overlays and their cursors.
- The shipped Compose definition now runs the persistent incremental score
  worker, and all release-version surfaces resolve consistently to `1.11.0`.
- Expanded or oversized product labels are bounded before annex persistence,
  and annex-backed requirement shadows preserve nullable mapping IDs.
- Overlay-backed recipe detail, grocery presence, feedback lineage, and cursor
  invalidation now agree with browse ranking during incremental publication.
- Incremental hash chains compact before pruning, cleanup completes old
  full-resolution revisions with bounded transactions, and ontology activation
  fences the active ontology rather than unrelated score-child movement.
- Pending sets above the incremental product limit cannot publish a partially
  fresh revision and instead fall through to copied full-score activation.
- Future product ontology builds resolve English product labels as English
  rather than `und`.
- The Copilot provider requires Node 24+, reserves an interactive queue lane,
  prioritizes interactive requests ahead of waiting background work, and
  launches the SDK with bounded heap configuration.
- Canonical ingredient rules distinguish Sweet Potato from Potato and map
  Russet Potatoes without minting a product-name orphan.

## [1.10.0] - 2026-08-16

### Added
- An `inventory_decrement_v1` discovery capability lets clients fail closed
  instead of sending atomic decrement requests to older servers that ignore
  them.
- A bounded `processing_status_v1` API reports recipe and ontology queues,
  activation health, recipe-score freshness, provider/logging health, and
  source-ingredient ontology coverage.
- A safe, resumable CLI backfills missing recipe ontology observations without
  retrieving Cookidoo instructions or changing ranking ingredients.
- Persistent request logs now include response status, duration, peak memory,
  and Copilot expiry-provider timing.

### Fixed
- Cookidoo discovery and direct metadata hydration again fail locally under the
  repository policy even when legacy operator gates are enabled; synthetic
  adapter tests remain available without creating a production request path.
- Copied ontology activation bounds memory-heavy hashing/import work and avoids
  monopolizing the shared scheduler lock during long offline phases. Dedicated
  activation connections spill SQLite sorts to disk, and durable manifests and
  validation attestations resume safely after transient writer contention.
- Deterministic provisional fallback jobs use distinct durable identities, so a
  quarantined subject-resolution plan cannot recurse until PHP exhausts memory.
- Activation generation rejects a disabled ontology controller before creating
  a production-sized database copy instead of ending in an ambiguous no-bundle
  retry loop.
- Cookidoo metadata-v2 refreshes now observe every rewritten source ingredient
  and wake ontology intake while preserving source-only score invalidation.
- Barcode cache contention no longer discards a successful provider result;
  user-facing write transactions use bounded SQLite begin retries.
- Application logging now uses the writable persistent data volume and reports
  failures through stderr instead of silently suppressing them.
- Apache now denies the runtime data directory in server configuration, so a
  bind mount cannot mask `.htaccess` and expose databases or request logs.
- Row-specific inventory consumption now applies an atomic server-side
  decrement with strict optional-unit validation, so concurrent stock changes
  cannot be overwritten by Home Assistant.
- Activation cleanup preserves valid manifest-referenced payloads, validation
  attestations reload under their own integrity hash, and payload generation
  enforces file-backed SQLite temporary storage.
- Docker builds exclude runtime JSON state, logs, and activation artifacts.
  The PWA displays curated messages for machine-coded API errors while
  retaining their original error codes and suppressing raw request failures.
- Every SQLite connection registers ontology trigger guard functions, including
  current-schema fast-path connections used by normal web requests.

## [1.9.9] - 2026-08-16

### Fixed
- Pantry category refinement now reuses one inventory response, serializes and
  deduplicates inference, caches successful classifications, rotates retries,
  and ignores stale render results.
- Category inference now has an isolated rate tier, strict Gemini completion
  and safety-envelope validation, and atomic versioned cache updates.
- File-backed rate limits now serialize updates, fail closed without rewriting
  malformed state, clean up failed first writes, prune only valid expired
  windows, and remain compatible with PHP 8.0.
- Scale discovery keeps its limiter state list-shaped, and startup health now
  treats unavailable rate-limit storage as a blocking failure.
- Docker builds now exclude local logs, locks, caches, and Python bytecode from
  every image layer.
- Shopping-classification cache and queue paths now use PHP 8.0-compatible
  associative merges, covered by the PHP 8.0 CI matrix.

## [1.9.8] - 2026-08-16

### Fixed
- Browser startup now uses a bounded, authenticated health scope that omits the
  full SQLite integrity scan while reporting that omission explicitly.
- Rotated API tokens recover during startup, and malformed or non-JSON health
  responses fail closed with actionable diagnostics.
- Critical health metadata cannot demote failures, database writability now
  blocks startup, and authenticated diagnostics are never cached.
- Full diagnostics and backup verification retain `PRAGMA quick_check`, and
  malformed health scopes fail without silently running or skipping checks.

## [1.9.7] - 2026-08-16

### Added
- Child-bound EBI FoodOn hierarchy evidence can authorize an exact
  source-to-existing-canonical ontology mapping as deterministic R0.

### Fixed
- FoodOn hierarchy refreshes replace stale provider evidence atomically,
  require one unique nearest depth-1/2 ancestor plus unanimous,
  attribute-safe proof across active occurrences, reject structural and staple
  targets, and never create accepted primary taxonomy edges from an external
  parent response.
- Apply-time proof loss or risk escalation now quarantines the plan instead of
  bypassing the model-policy and critic gates used for generalized mappings.
- Ontology schema v3.18 versions the expanded portable identity seal before
  automatic candidate activation.

## [1.9.6] - 2026-08-15

### Fixed
- Activating an authorized benchmark policy immediately wakes deferred
  model-derived plans; the 24-hour timestamp is now only a fallback watchdog.

## [1.9.5] - 2026-08-15

### Fixed
- Imported ontology versions referenced by ready rollback scores are retained
  instead of being misclassified as stale sibling candidates.

## [1.9.4] - 2026-08-15

### Fixed
- Explicit bounded cleanup may now delete immutable candidate-only manifests,
  evidence, dispositions, review rows, and lifecycle events through the prune
  guard while ordinary mutations remain blocked.

## [1.9.3] - 2026-08-15

### Fixed
- Direct mapping-reference indexes keep failed ontology cleanup bounded instead
  of scanning every historical shadow match for each mapping row.

## [1.9.2] - 2026-08-15

### Fixed
- Transient SQLite writer contention now leaves ontology imports, validation,
  cleanup, and final publication resumable instead of forcing a full rebase.

## [1.9.1] - 2026-08-15

### Fixed
- An ontology bundle is no longer marked stale after its score revision makes
  that same candidate the active ontology.

## [1.9.0] - 2026-08-15

### Added
- A lineage-bound copied-database activation pipeline now exports bounded JSON
  manifests plus immutable SQLite sidecars, imports exact ontology and score
  IDs in crash-resumable chunks, validates on a copy, and publishes through one
  short score-pointer compare-and-swap.
- Durable import leases, per-table sequence and row fences, CDC watermarks,
  validation attestations, copied-workspace rebase, intent acknowledgement,
  rollback-safe cleanup, and a production-sized rehearsal command.

### Changed
- The minute score cron now drives copied ontology/score activation instead of
  building a v3 shadow on the active database. Inventory-only drift rebuilds
  only scores; source or policy drift rebuilds the ontology candidate.
- Copied forks use a multi-second throughput target while live imports retain
  250 ms chunk and 100 ms activation alert budgets. Obsolete score
  materializations are pruned in bounded resumable chunks.
- Recent exact and validated intake intents are activated ahead of historical
  backlog while provisional coverage continues to drain durably.

### Fixed
- Shadow and requirement builders now reject the active database on every path.
- Quarantined model plans retain their immutable artifacts but receive a
  separate deterministic provisional fallback, and the rejected original
  change set no longer blocks activation.
- Validated plans blocked by benchmark policy remain pending with a 24-hour
  durable retry while their non-satisfying provisional fallback is active.
- Blast comparison now uses a current-input parity control for generalized
  changes and does not reject authoritative R0/provisional generations merely
  because stale inventory/catalog state changed many score rows.

## [1.8.9] - 2026-08-15

### Fixed
- Direct source-to-existing-entity ontology plans now discard an exact
  evidence-copy alias that cannot be applied by that repair kind. The raw model
  response remains immutable, while the validated plan no longer quarantines a
  correct mapping such as `Beefsteak Tomato` → `Tomato`.
- The ontology prompt explicitly limits aliases to alias-specific repair kinds.

## [1.8.8] - 2026-08-15

### Performance
- The local Copilot socket provider now keeps one Copilot SDK runtime alive
  instead of launching a new CLI process for every request. Gemini-first expiry
  vision dropped from roughly 9–12 seconds to 3–5 seconds after warm-up while
  preserving isolated sessions, strict JSON schemas, disabled tools, and
  hash-bound attachments.

## [1.8.7] - 2026-08-14

### Changed
- Expiry parsing again prioritizes Gemini over heuristic OCR: Copilot Gemini
  3.6 Vision runs first, with bounded Tesseract parsing retained as fallback.

## [1.8.6] - 2026-08-14

### Fixed
- Expiry scans now fall back to a bounded local Copilot Gemini 3.6 vision
  attachment when Tesseract extracts no text. Gemini 3.7 remains the ontology
  proposer but is not used for images because its Copilot endpoint rejects
  image parts.
- Vision attachments are hash-bound, size-limited, stored only in a
  group-writable runtime directory, and deleted by both provider and caller.

## [Unreleased] — Ideas & Roadmap

> Ideas collected during development. No priority or date implied.

- **Recipe scraps tips** — During cooking steps, detect "waste" generated (peels, cores, bones, eggshells, coffee grounds, citrus zest, etc.) and surface AI-powered tips on how to reuse them (compost, natural cleaner, broth, candied peel, etc.). Could be shown as an optional collapsible hint card below the step that generates the scrap.

### Added
- **Operator-gated Cookidoo scan discovery** — Restored the authenticated,
  bounded private-account search/detail bridge behind matching default-off
  EverShelf and bridge gates. Inventory adds can now query direct ingredient
  terms and taxonomy ancestors, persist allowlisted English metadata locally,
  and reuse fresh discovery jobs without enabling full catalog backfill.
- **Autonomous ingredient ontology controller (default off)** — Added immutable
  recipe-independent product/ingredient subjects, occurrence conservation,
  immediate latest-intent exact allow/deny constraints, fenced durable jobs,
  child-version forking and closed deterministic change-set application,
  strict seven-contract prompts/provider abstractions, generation
  debounce/blast/gold/rollback gates, immutable gold-release lineage, and
  copied-database backfill/controller CLIs. Model and automatic promotion paths
  remain independently disabled by default; no human approval state is required.
  Added canonical owner-fingerprint enforcement, artifact-preserving abandoned
  child handling, crash-resumable staged/applied/shadow phases, relevant-stream
  freshness instead of global-epoch rollback, shared debounced children,
  immutable benchmark-policy import, durable subtract-only P7 critic jobs,
  active adversarial-gold blocking, and scheduler-driven generation/gold cycles.
  Occurrence uniqueness now includes the immutable owner row ID without changing
  canonical fingerprints; legacy schemas rebuild compatibly, recipe retirement
  uses an expression index, late-phase failures terminalize from their actual
  fence, and pending generation keys have database-enforced uniqueness.
  Product observation keys now bind the full semantic subject payload. Mutable
  recipe quantity/unit/requiredness/staple/group context moved from occurrence
  provenance into payload-hashed append-only observations. Default-disabled,
  savepoint-isolated live hooks can no longer roll back ordinary saves.
  Added total non-prepared owner coverage audits, prepared-meal exclusion and
  toggle semantics, portable non-satisfying provisional leaves for quarantined
  subjects, bounded policy/evidence retry circuits, provider health/status
  reporting, and a hardened host Copilot Unix-socket provider with a versioned
  model whitelist and sample systemd user unit.
  Final enablement hardening adds crash-safe product/fork transactions,
  transactional row-count-verified table swaps, strict lease ABA fences,
  manual legacy proposal children when disabled, gated decision writes, cached
  coverage status, SQL-bounded candidate retrieval, attachment-based 160 KB
  prompt/schema delivery, Gemini effort omission, and user-unit runtime/socket
  alignment.
  Production cron and workers remain intake-only. Candidate fork, generation,
  shadow, and promotion work is rejected on the active database and must run
  against a copy. Unsafe or exhausted plans retain a deterministic unresolved,
  confidence-zero, non-satisfying provisional intent for copied-candidate
  materialization without changing active recipe matches. Added realized
  provisional-edge blast accounting, inode-safe
  active-DB guards, SQLITE_BUSY/SQLITE_LOCKED generation replay, post-commit
  job reconciliation, stale-owner pruning in child versions, one-minute
  CAS-claimed monitoring that tolerates ordinary live source drift,
  group-readable user-runtime socket permissions, base-owned queue lease
  schemas, safe
  prepared/delete/merge deactivation, shared-subject job preservation,
  resumable keyset backfill under 128 MB, and fingerprint-aware cached
  coverage. Production intake uses an indexed minimum-priority fence: live
  products run at 100, live recipe ingredients at 50, terminal jobs revive with
  fresh fences, and priority-0 historical backfill remains queued for explicit
  offline processing.
  Intake-only model artifacts now enter a durable generation-intent lane and
  are rebound without another proposer call inside a copied database. Copied
  work coalesces one child per parent, supports crash-resumable keyset forks,
  skips exact semantic no-ops, reuses safe shadow candidates, and exports a
  sealed portable activation bundle. Bundle preflight fails closed on source or
  parent-pointer drift; no production importer is enabled. Removing or marking
  the final product occurrence prepared now supersedes its pending generation
  intents so copied builds cannot apply deleted subjects.
- **Atomic ingredient decision v2** — Added one revision-bound
  `assume_have|select_inventory_product|reject_current_match` command with
  product-level validation, immutable v2 source/target fingerprints, exact
  action provenance, transactional availability/evidence writes, idempotent
  replay/conflict handling, immediate autonomous exact-pair constraints, and
  retained 48-hour settlement only for the legacy proposal-export path.
- **Durable ontology proposal intake** — Positive/negative decisions now enqueue
  a transactional outbox row and candidate regression fixture. A bounded worker
  persists immutable prompt/manifest/response artifacts, uses the one configured
  ontology proposal model without fallback, stages only deterministic
  closed-set-validated proposals, and supports operator/Copilot export/import.
- **Cookidoo My Week scaffolding** — Added a fully fake-tested, account-level
  React/HA/API/bridge planner path with dual default-off gates, revision tokens,
  command journaling, read/write verification, append/replace safety, timeout
  reconciliation, authentication retry, and 403/429 circuit behavior.
- **Cookidoo content-language quarantine** — Added deterministic high-confidence
  English/non-English assessments, future ingestion enforcement, user-facing
  quarantine visibility, and copied-database dry-run/apply/rollback tooling
  without changing recipe/ontology corpus membership.
- **Ingredient availability and identity feedback** — Added revision-bound
  display-only have/missing overrides, append-only correct/wrong match evidence,
  stale-token/idempotency guards, detail capabilities, and a 14-day
  proposal-export workflow. Feedback never mutates inventory, scores, grocery
  eligibility, or ontology automatically.
- **Manual production ontology activation profile** — Added an exact frozen
  production corpus profile and explicit `manual_review` activation policy for
  regular faceted-v3 score revisions. Requirement/source-aware revisions remain
  shadow-only; activation still requires every integrity, gold, source, exact
  ID/value materialization, and explicit CLI confirmation gate.
- **Evidence-bound recipe quantity parser** — Added deterministic multilingual
  amount, range, fraction, package, qualifier, and unit parsing for non-Cookidoo
  ingredient text; strict structured Cookidoo passthrough; exact numeric source
  spans; advisory parse persistence; and a no-API, review-only model proposal
  workflow with closed-schema validation.
- **Faceted ingredient ontology v3 shadow stack** — Added versioned entity/label/
  relation/facet schemas, complete product and recipe-row mapping assertions,
  quarantined legacy Gemini aliases, deterministic multilingual staple seeding,
  exhaustive JSON/human audits, a strict attribute-aware matcher, staged proposal
  change sets, full materialized shadow score revisions, diff reports, frozen
  synthetic gold/benchmark fixtures, and guarded validate/activate/rollback CLI
  commands. Everything is additive and disabled by default.
- **One-pointer ontology activation** — Score revisions may reference an ontology
  version; the effective ontology is derived only from the active score revision.
  Explicit activation/rollback updates the score pointer and cursor revision
  atomically while retaining prior ready revisions.
- **Local recipe discovery catalog** — Durable normalized recipe variants, title/ingredient FTS5 search, source provenance, favorites, taxonomy-aware pantry matching, quantity checks, and expiration-weighted suggestions.
- **Asynchronous recipe jobs** — Inventory and taxonomy changes enqueue bounded recipe indexing/discovery work without adding remote calls to scan or product-save requests.
- **Experimental Cookidoo metadata bridge** — Optional isolated Python service caches only operator-approved factual `metadata-v2` General/Ingredients fields, remote image/canonical URLs, locale, and timestamps. Credentials/cookies stay outside the EverShelf container.
- **Progressive Cookidoo corpus crawling** — Stocked taxonomy terms and their eligible ancestors now seed stable one-page jobs for ingredient-filtered and text-only lanes, with cached-only page advancement, page-zero refresh chaining, and a repeatable inventory backfill CLI.
- **Recipe discovery UI** — Search by title or ingredient, switch between stocked and expiring-soon ranking, filter sources, inspect match explanations, and hydrate Cookidoo results without blocking local results.
- **Dashboard recipe contracts** — Compact 50-card catalog pages, a deterministic mixed recommendation endpoint, snapshot cursors, coverage/expiry filters, independent ranking weights, and aggregate hydration status.
- **Responsive recommendation totals** — Carousel clients may request 5–100 deterministic cards while preserving the availability/expiry/fill mix.
- **Recipe detail contract** — Added the bounded `recipe_detail_v1` projection with source/freshness/revision metadata, General facts, ordered inventory-aware ingredients, local-or-external instruction capability, and explicit display-only quantity semantics.
- **Idempotent missing groceries** — Added `recipe_catalog_grocery_add` to revalidate selected ingredient positions, canonicalize equivalents, and add only genuine missing items to EverShelf's internal shopping list without calling Home Assistant.
- **Cookidoo metadata-v2** — The disabled bridge adapter allowlist now preserves approved factual yield/unit, explicit prep/cook/active/inactive/total seconds, supported/required and optional devices, difficulty, primary category, equipment nouns, and ordered ingredient source amounts. Existing cached rows remain nullable and readable; production hydration stays policy-disabled.
- **Disabled direct-ID metadata backfill** — Added authenticated 1–20 ID bridge batches, per-origin metadata versioning, resumable `recipe_metadata_refresh` jobs, and a status/dry-run/enqueue CLI guarded by `COOKIDOO_METADATA_BACKFILL_ENABLED=false`.
- **Complete factual ingredient topology** — Source ingredients retain bounded named group/within-group topology, provider ingredient/default-title/unit references, provider-declared optionality, and shopping-category references. Recipe detail exposes named groups and additive provider metadata without changing the flat compatibility list.
- **Versioned source mapping/remap** — Source mappings record the active `legacy-v1` mapper and can be remapped in bounded local jobs when a future resolver is registered, without another provider fetch; ontology v3 remains inactive.
- **Authorized instruction groups** — Local/manual/generated recipes may persist bounded group labels and step-position references, while Cookidoo detail is structurally fixed to external-only instructions with no groups.

### Changed
- **English-only Cookidoo request evidence** — Disabled search scaffolding now
  forces the separate upstream `languages=en` filter, retains bounded provider
  language as undocumented provenance distinct from locale, and combines it
  with deterministic local rejection of explicit non-English ingestion.
- **Cookidoo detail hydration policy-disabled** — Because the available provider detail response co-transports official steps, production bridge search/direct metadata routes now return `503 metadata_hydration_disabled_policy` without provider requests. EverShelf refuses discovery/backfill enqueue, skips legacy queued jobs locally without connector accounting, and keeps existing cached catalog rows readable.
- **AI ontology proposals are staging-only** — Gemini 3.5 Flash is the frozen
  default proposal model. Closed candidate IDs, facet enums, exact evidence,
  relation direction, retail alias bans, deterministic hard/soft attributes, and
  human review prevent model output from writing ontology data directly. Legacy
  v2 immediate-write review is now opt-in (`TAXONOMY_AI_REVIEW=false` by default).
- **Cookidoo crawl safety** — Full-corpus jobs remain rate/concurrency bounded, hydrate at most one 20-hit page per job, and expose only scalar page progress alongside allowlisted metadata.
- **Cookidoo policy boundary** — Source amounts are display-only and isolated from ranking quantity/unit fields. Official steps, notes/tips, nutrition, descriptions, tags, ingredient preparation text, raw payloads, image bytes, and Guided Cooking content remain categorically excluded; the bridge never accesses `preparation`.
- **Recipe ranking at scale** — Inventory matching is materialized into atomically activated score revisions, so broad and empty searches paginate in SQLite instead of hydrating the full catalog in PHP.
- **Interactive discovery scheduling** — One of the two per-minute connector slots is reserved for an interactive search when present while background crawling continues in the other slot.
- **Source-first ingredient identity** — Detail and grocery labels now use deterministic conservative cleaning/casing of bounded source text. Complete approved amount-plus-unit prefixes are also detected directly in legacy source text, while unsafe grocery dedupe preserves the remaining source identity. Canonical/taxonomy joins remain secondary metadata, and `closest_match` is restricted to identity-safe alias/slug mappings.
- **Grocery capability semantics** — `grocery_add` now reports feature support for complete nonempty ingredient lists independently of missing count, with separate bounded grocery state counts and blockers.
- **Direct metadata batch contract** — `/v1/metadata` now requires an exact supported locale and returns ordered per-ID success or bounded failure outcomes. Job status reports bounded succeeded/failed counts and IDs; transient/authentication/rate failures remain whole-batch retries.
- **Backfill failure reconsideration and pilot telemetry** — Invalid IDs/locales remain blocked until origin change/reset, while not-found/parser failures use bounded probes and parser-version reconsideration. Status/jobs expose safe group/unit/null/range, byte/latency, failure-kind, mapping-version, and revision-invariant metrics; the documented pilot ladder is 1→5→10→20→≤200 with concurrency one and ~2-minute jittered jobs.
- **Topology schema gating** — The metadata policy remains `metadata-v2`, while the separate `ingredient-topology-v1` marker prevents partial/older source rows from being treated as current. Pilot metrics count topology key presence, bounded group-title lengths, reference/default-title/unit fill, and optional true/false/null rates without logging values.

### Fixed
- **Mutation revision correctness** — Ingredient feedback submit-time detail
  validation now always uses the true active score/ontology rather than a
  development preview revision.
- **Production activation and migration safety** — Ontology schema v3.17
  version-gates protection-trigger migrations, requires a validated rollback
  baseline for manual activation, and preserves retained shadow matches as
  historical owner records when recipe ingredients are removed.
- **Inventory recipe invalidation atomicity** — Inventory mutations now commit
  recipe-score invalidation and queue work in the same transaction. Product
  unit conversion rebuilds history baselines for every positive inventory lot,
  while no-op edit forms omit unchanged unit/package metadata.
- **Deployment secret and maintenance hardening** — Removed the tracked Android
  signing key and inline credentials, externalized release signing, excluded
  kiosk/Playwright artifacts from web images, and denied all HTTP access to
  maintenance scripts.
- **Concurrent startup migrations** — Legacy column additions now tolerate a
  second process winning the same SQLite migration race, preventing duplicate
  column fatals when Apache and cron start together.
- **Recipe queue leases and API IDs** — Stale exhausted leases now terminate
  with stable `lease_exhausted` state while only nonexhausted leases retry.
  When provider detail policy is disabled, every pending/retry/leased Cookidoo
  discovery, crawl, metadata-refresh, or network-refresh job atomically becomes
  `skipped` with `provider_detail_policy_disabled` regardless of cadence-worker
  allowance, without connector accounting.
  Recipe detail/job query IDs and save/delete/favorite/refresh JSON IDs reject
  malformed values; refresh distinguishes an omitted ID from an invalid one and
  requires an object body.
- **Policy-disabled crawl CLI reported successful enqueue** — Non-dry `backfill-cookidoo-crawls.php` now returns `provider_detail_policy_disabled` with `success:false` and exit code 3 before opening the database. Dry-run remains a zero-write disabled-status report.
- **Cookidoo discovery leaked internal request flags** — Bridge search calls now use an explicit SearchRequest-only payload; interactive, force, local-result, cache, and crawl controls remain local.
- **Missing ingredient groups erased stored source rows** — Raw/public detail adapters and PHP bridge normalization now require structural evidence for a complete nonempty ingredient list. Missing, null, or empty groups fail as invalid metadata instead of producing an authoritative empty replacement; the parser marker advances for reconsideration.
- **Deleted metadata targets retried and tripped connector health** — Queued targets deleted or changed after enqueue now terminate as local stale/skipped outcomes without bridge traffic, retries, or connector failure/circuit accounting.
- **Discovery acquired flock after SQLite write lock** — Discovery now takes the reentrant catalog flock before opening its write transaction and releases it on every path, matching standalone catalog saves.
- **Cookidoo exclusion sets reused the wrong discovery job** — Search and idempotency identities now include a SHA-256 digest plus count of sorted unique bounded `exclude_ids`, making equivalent sets order-insensitive and different sets distinct without embedding IDs in keys.
- **Public Cookidoo amount prose crossed the metadata boundary** — Ambiguous fallback descriptions now pass only a closed numeric exact/range and safe unit/count grammar. PHP independently rejects prose and structured amount text that disagrees with quantity/range/unit fields.
- **Malformed source ranges persisted orphan maxima** — Raw Cookidoo quantities now accept only a pure exact value or a complete ordered range; partial/mixed objects fail as invalid metadata. PHP persistence also rejects `source_quantity_max` without `source_quantity` for Cookidoo and generic sources. The parser marker advances for reconsideration.
- **Quantity parser review hardening** — Restricted implicit piece counts to a
  conservative countable-noun grammar, added explicit regional decimal/group
  profiles, included bounded source text and effective parse locale in exact
  non-Cookidoo identity, preserved locale-only parse roundtrips, rejected invalid
  UTF-8 and nonpositive/nonfinite/oversized ranking quantities, blocked contextual
  identifier numbers, overlapping model evidence, inconsistent unit/raw pairs,
  and implausible amount layouts, parsed terminal qualifiers after amounts,
  validated canonical structured source amount text, made detail joins and stale
  full-text reparsing deterministic, rejected conflicting recipe identifiers and
  non-integer API IDs, kept unsafe grocery source identities distinct, and bound
  persisted deterministic parses to current source text, locale, version,
  provenance, and reproducible evidence without enabling ranking quantities.
- **Long curing recipes exceeded mismatched duration bounds** — Bridge and PHP normalization now share a named 366-day ceiling while remaining nonnegative, integer-only, and fail-closed above the bound. The parser marker advances for affected pilot outcomes.
- **Repeated Cookidoo catalog IDs failed valid recipes** — Duplicate provider ingredient IDs now coalesce when nonempty bounded default titles agree. Missing/empty plus present title deterministically keeps the present title; conflicting nonempty titles still fail closed. The parser marker advances for affected pilot outcomes.
- **Legitimate Cookidoo asset lists exceeded the adapter cap** — Recipe detail parsing now permits up to 100 bounded descriptive-asset descriptors while still returning only the first allowlisted image URL and failing closed above the limit. The parser marker advances so affected pilot failures are reconsidered.
- **Cookidoo row IDs rejected valid ingredient references** — The raw detail adapter now prioritizes `ingredient_ref`, then `localId`; generic row `id` is ignored unless it matches the bounded ingredient catalog. The parser marker advances so affected `invalid_metadata` pilot outcomes are reconsidered.
- **Ontology v3 activation and scoring review fixes** — Scheduled rebuilds now
  preserve the active ontology model/version, activation rechecks mutable inputs
  under a write reservation, source/content hashes exclude mutable mapping
  outputs, quantity/expiry aggregate every compatible lot, original requiredness
  survives legacy staple prefixes, rollback requires ancestry, proposal lifecycle
  is audited, frozen-gold policy is non-vacuous, and optional unmatched
  explanations no longer become required blockers.
- **Ontology v3 follow-up correctness** — Stale active v3 scores are served without
  request-path rebuild storms; rollback restores stale retained ancestors while
  wrong-parent siblings and legacy non-ancestors fail closed; mapping hashes ignore
  package/legacy-cleared fields and no-op source refreshes preserve row IDs;
  committed activation survives prune warnings; v3 revision/materialization
  retention is bounded to an eight-ancestor rollback window plus small history;
  unapplied proposal sets can be auditedly reverted; blocker counters and
  explanations share one outcome classifier; and write reservation plus reject-all
  recall have direct regressions.
- **Ontology v3 source freshness and closure** — Scheduled freshness now verifies
  current owner, corpus, content, and version hashes and returns `ontology_stale`
  without moving the active pointer when source owners need a new candidate/remap.
  Provider source-text/reference/optionality changes dirty only active-v3 catalog
  state, while identical refreshes and active-v2 inventory/catalog state remain
  unchanged. Shared-lock recovery terminalizes every old legacy/v3 build before
  replacement or pruning, and frozen-gold attributes are closed to the selected
  facet/value map.
- **Ontology v3 copied-database migration order** — Mutating operator CLI commands
  now migrate the selected copy's current recipe schema before ontology v3, so
  pre-v3 `recipe_ingredients` tables gain and backfill source requiredness columns
  before candidate and shadow builders query them.
- **Prefix staples and broad ingredient identity** — Ontology v3 recognizes only
  exact multilingual staple aliases, so pepper jack/sauce, salt cod/pork, and
  water chestnuts/spinach are not staples. Required matches no longer succeed from
  broad ancestry, components, derivation, rule/model/lexical evidence, or
  conflicting/unknown defining attributes.
- **Cookidoo fallback locale persistence** — Language-only discovery now stores the
  selected effective regional/script localization and requires the canonical URL
  locale to match. Exact direct-ID refresh remains strict, while legacy language-only
  origins are reported invalid/unrefreshable instead of being mapped to a market.
- **Metadata sibling retry loops** — Successful direct-ID recipes and blocked outcomes commit in one bounded job transaction, failure state is recorded for checkpoint skipping/reconsideration, and resumed jobs omit currently terminal siblings.
- **Ingredient amount-prefix cleanup** — Display and grocery names remove a complete approved quantity-plus-unit prefix directly from legacy source text even when parsed quantity columns are null. The bounded vocabulary covers common package, culinary, mass, volume, and piece units with ranges/fractions, while names such as `7 Up` and `1000 Island dressing` remain intact.
- **Failed grocery write dedupe** — Equivalent selections are marked seen only after an existing row is confirmed or an insert succeeds, so later selections report their real write outcome.
- **Prepared meals leaked into ingredient recipes** — Row-level prepared-food inventory is excluded from recipe generation and matching; mixed raw/prepared products keep raw ingredient taxonomy until every positive row is prepared.
- **Required recipe ingredients matched broad stock** — Metadata-v2 source ingredients now default to required, so contains and broad relations remain uncertain while exact, descendant, and normalized-name matches retain existing behavior.
- **Bounded inventory detail false-missing states** — Expiration filtering now happens before safe SQL bounds where possible, and pre-filter truncation conservatively produces uncertain rather than grocery-eligible missing states.
- **Grocery replay drift** — Grocery commands persist a stable request fingerprint and replay stored outcomes before mutable ingredient validation; legacy selection hashes are upgraded on their next valid replay.
- **Unbounded grocery idempotency history** — Grocery request records now have indexed, bounded pruning with a documented 30-day retention window.
- **Metadata refresh score churn** — Existing Cookidoo metadata updates use a
  dedicated bounded batch transaction that atomically replaces source ingredients
  while preserving title/image, ranking ingredients, search/FTS, clusters, score
  rows, inventory revision, and the active pointer. Only ontology-relevant provider
  identity/optionality drift marks an active-v3 catalog revision stale; active v2
  and no-op refreshes remain unchanged.
- **Flat ingredient-group corruption** — The direct detail adapter now rejects malformed/shopping-list group shapes instead of silently flattening them, while the public parser fallback emits one ordered group.
- **Metadata freshness cursor safety** — A metadata batch that changes actual search visibility across `stale_at` now increments only `cursor_revision`, exactly once per transaction. Fresh-to-fresh, still-invisible, favorited, and hidden transitions do not invalidate cursors; pre-transition browse cursors are rejected after a real visibility change.
- **Home Assistant recipe suggestions always failed** — Gemini fallback responses are read from their actual `data.candidates` wrapper.
- **Favorite recipe plans were purged** — Legacy retention now preserves favorites.

## [1.8.3] - 2026-07-31

### Added
- **Smart storage-location defaults** — EverShelf now remembers the latest location used for each product, backfills existing products from inventory history, and exposes a history-first location suggestion API for Home Assistant and other clients.
- **Quality-first Gemini fallback** — Genuinely unseen products can be classified by `gemini-3.6-flash`, with `gemini-3.5-flash-lite` as the fast fallback. Structured output can return `unknown`, and decisions are cached.

### Changed
- **Bounded location AI** — Location classification uses minimal thinking, a five-second model timeout, and no same-model retries so item entry remains responsive during quota or service issues.
- **Accurate Gemini cost estimates** — Usage telemetry now includes thinking tokens and current Gemini 3.6 Flash / 3.5 Flash-Lite prices.

## [1.8.2] - 2026-07-27

### Fixed
- **Re-queued products lost their `contains` terms** — history reuse deliberately excludes the product itself when looking for a prior classification, so a product being re-processed fell through to the alias replay. That rebuilds from the taxonomy tree, which stores only the node hierarchy, so the product's own `contains` terms were dropped on every re-queue and never came back. Since `product_save`, `product_set_prepared_food`, and `inventory_set_prepared_food` all re-queue, editing an item quietly stripped its ingredient terms. Taxonomy-aware search matches on every role, so those items stopped matching their ingredients.

  History reuse now replays the product's own mappings when it has classified before under the same name. The alias path still applies to genuinely new products, where contributing only placement is correct — `contains` terms are product-specific and must not be inherited through a shared alias.

## [1.8.1] - 2026-07-27

### Added
- **Per-item prepared food** — `inventory_set_prepared_food` marks some or all units of an inventory row as prepared. Passing a quantity below the row quantity splits those units onto their own row, so part of a batch can be prepared while the rest is not. The product counts as prepared when any stocked row is flagged, and its taxonomy is regrouped automatically. New stock inherits the product's state, and rows that become identical again are merged back together.

### Fixed
- **Inventory batch matching never matched on integer columns** — `COALESCE(...)` drops column affinity and PDO binds `execute()` parameters as strings, so `COALESCE(vacuum_sealed, 0) = ?` compared integer `0` against string `'0'` and always failed. Affected batch merging in `inventory_add`. Both sides are now cast explicitly.

## [1.8.0] - 2026-07-27

### Added
- **Prepared food items** — Products can be flagged `prepared_food` at save time. Finished dishes group straight under the existing prepared meal taxonomy term instead of being classified by ingredient, skipping both history lookup and the AI review. The flag is sticky, so ordinary saves never clear it, and `product_set_prepared_food` toggles it on an existing product without a destructive partial save.
- **Taxonomy history reuse** — Items classified before replay that decision instead of being re-derived, matched by barcode, name+brand, name, or a recorded alias. Costs no model call.
- **AI taxonomy review** — New items have their heuristic placement reviewed by Gemini against the entire taxonomy tree, which confirms/corrects the primary term, supplies ancestors, and reports a correctness verdict. Guarded so it may only add nodes and edges, never rename, move, or delete existing ones. Toggle with `TAXONOMY_AI_REVIEW`.
- **Container-managed cron** — The image now runs `cron_smart_shopping.php` every 5 minutes itself via `docker/evershelf-cron`, so Docker installs no longer depend on host crontab configuration.
- **Egg taxonomy rules** — Added `Eggs` and `Egg whites`; previously no rule matched eggs at all.

### Fixed
- **Enrichment queue never drained** — Product saves enqueued canonical/taxonomy work that nothing consumed on Docker deployments, so items added after the queue was introduced silently never received taxonomy terms while `product_save` still reported `canonical_queued: true`.
- **Plural-blind rule matching** — Patterns are written singular and `\b`-anchored, so "Black Beans" could never match `/\bblack\s+bean\b/`. The rule haystack now also includes a singularized copy of the product text.
- **Overlapping queue runs** — Concurrent workers raised `database is locked` and duplicated work; queue processing now takes an exclusive lock and a second run yields.

## [1.7.44] - 2026-06-26

### Added
- **Split-safe inventory item APIs** — `inventory_update_one` updates one item from a multi-quantity row, splitting it into a separate row when needed.
- **Single-item delete API** — `inventory_delete_one` removes one item from an inventory row, decrementing rows with quantity greater than 1 instead of deleting the entire row.

## [1.7.43] - 2026-06-26

### Added
- **Shopping cart quantities** — Internal `shopping_list` rows now store a `quantity` value with an automatic migration for existing carts.
- **Quantity increment semantics** — `shopping_add` increments an existing row when callers provide `quantity`; callers that omit quantity keep the previous idempotent skip behavior for automated suggestions.
- **Quantity-aware HA output** — `shopping_list` and `ha_shopping_items` include quantity so Home Assistant and UI clients can display the cart amount.

### Changed
- Shopping price totals now include the stored cart quantity multiplier.

## [1.7.42] - 2026-06-11

### Added
- **Waste reason picker** — Discarding a product prompts for why (expired, spoiled, wrong storage, kept too long, bought too much, forgotten, bad quality, other) in IT/EN/DE/FR/ES.
- **Waste learning** — Reasons are stored per product in `app_settings.waste_learning`; caps smart-shopping suggested quantities, surfaces preferred storage location, and tightens expiry alerts after repeated spoilage.
- **`scripts/github-issue-triage.php`** — Reopens wrongly closed feature backlog items; closes resolved auto-report bugs with English comments.

### Fixed
- **Inflated shopping total** — Price each Bring!/shopping line as **one retail purchase**; convert AI €/kg prices to estimated piece weight (200 g default) instead of multiplying by piece count; cap smart-shopping conf/pz suggestions used for pricing context.
- **SQLite database locked (#201–#202)** — `inventory_use` and `shopping_add` (including Bring mode) wrapped in `dbWithRetry()`.
- **Smart shopping timeout (#203–#204)** — `set_time_limit(120)` on `smartShopping()` / `smartShoppingCached()` for large inventories.
- **Android kiosk CI** — Escaped apostrophes in locale `strings.xml` (de/es/fr/it); fixed Kotlin JSON string escaping in `SetupActivity.finishSetup()`.
- **GitHub triage** — `triage-open-issues.php` no longer bulk-closes enhancement/feature backlog; reopened #98 (pin products) and #125 (cooking voice commands) where not yet implemented.

## [1.7.41] - 2026-06-08

### Fixed
- **Docker/Traefik “Impossibile contattare il server”** — PHP 8.2 deprecation notices (`LoggingPDO::prepare`) were emitted as HTML before JSON, breaking `fetch().json()` on the startup health check; API bootstrap now suppresses HTML error output in production.
- **Traefik HTTPS redirect loop** — `.htaccess` skips the HTTPS redirect when `X-Forwarded-Proto: https` is already set (compatible with Traefik `sslheader` middleware); no need to disable `.htaccess` manually.
- **LoggingPDO PHP 8.2** — `#[\ReturnTypeWillChange]` on `prepare()` to eliminate deprecation noise in error logs.

## [1.7.40] - 2026-06-08

### Added
- **Qty unit badges** — Quantity inputs show the active unit (g, ml, conf, pz, …) on use, add, recipe-use, edit and throw modals; scale live label “Inserimento in …”.
- **Recipe shopping suggestions** — AI recipes can list optional missing ingredients with one-tap add to Bring!/shopping list.
- **Recipe frozen badge** — Freezer items flagged in pantry lines and recipe UI; prompt rule for cooking from frozen.
- **Health check `db_writable`** — Startup diagnostic detects non-writable SQLite file (common Docker volume issue).
- **`scripts/triage-open-issues.php`** — Maintenance helper to comment/close GitHub issues via encrypted token.
- **Ops CLI scripts** — `audit-finished-shopping.php`, `backfill-finished-shopping.php`, `sync-shopping-bring.php`, `install-transformers-model.sh` (offline Xenova classifier bootstrap).

### Fixed
- **SQLite database locked** — `PRAGMA busy_timeout` 10s + `dbWithRetry()` on `inventory_update` under cron/PWA contention.
- **Barcode duplicate on save** — `saveProduct` merges or returns 409 instead of HTTP 500 on UNIQUE barcode.
- **EverLog CLI crash** — Safe cast of `REQUEST_METHOD` when null (kiosk/cron).
- **Spesa scan crash** — `currentPage` → `_currentPageId` in `_applySpesaScanUI`.
- **Recipe quantities** — Piece products use 1 pc base; serving caps for onions, leafy greens, minestrone; pantry-only post-processing; conf/g display fixes.
- **Smart shopping purchased block** — Server-side blocklist + spesa mode sync prevents cron from re-adding bought items.

### Changed
- **Docker behind Traefik** — Apache `SetEnvIf X-Forwarded-Proto https HTTPS=on` to avoid redirect loops.

## [1.7.39] - 2026-06-06

### Added
- **`resolve_barcode` API** — Single round-trip: local catalog lookup plus **parallel** external search (Open Food Facts IT/world, UPC Item DB, Open Products Facts, Open Beauty Facts via `curl_multi`). Results are stored in SQLite `barcode_cache` for instant repeat scans.
- **Spesa barcode fast path** — In shopping mode, a successful scan opens the **add form directly** (skips the intermediate action page).
- **Session barcode cache** — In-memory cache avoids duplicate API calls when scanning many items in one trip.
- **Manual expiry flag (`expiry_user_set`)** — User-entered expiry dates are kept when changing location, vacuum seal, or moving stock; only auto-estimated dates are recalculated.
- **Family sibling 24h dedup** — After confirming “Sì, tutto ok” on a similar in-stock product, the check prompt is suppressed for the same `shopping_name` family for 24 hours (synced via `family_sibling_confirmed` in app settings).
- **Family sibling stock line** — Spesa prompt shows readable stock (e.g. `4 conf (da 20g)`); new `family_sibling_check` / `family_sibling_stock` strings in IT/EN/DE/FR/ES.
- **Quick-edit product notes** — Notes field in the inline name/brand editor on the product action page.

### Fixed
- **Kiosk / WebView stability** — Guard `$_SERVER['REQUEST_METHOD']` when null; fix JS temporal-dead-zone crashes (`setProgress`, `enriched` → `enrichedRaw`, `duplicateNames`); lazy-load ZBar WASM so kiosk startup no longer OOM-crashes.
- **Empty barcode SQL error** — Multiple products with `barcode = ''` violated SQLite UNIQUE; empty strings are normalized to `NULL` (migration included).
- **Spesa ghost products** — Finished/catalog AI candidates and scan recents no longer show zero-stock items in shopping mode; `family_sibling_suggest` requires live inventory quantity.
- **Insalata di riso misclassification** — Prepared rice salads (e.g. Ponti) map to `pasta` instead of fresh `verdura`; server and client rules aligned.
- **Family sibling prompt readability** — Quantity and question text use high-contrast colours on the dark overlay.
- **Move after use / recipe move** — Respects manually set expiry (`expiry_user_set`); purchased items marked on blocklist after spesa add.

### Changed
- **Barcode lookup** — Replaced sequential API waterfall (up to ~15s) with parallel fetch (~1–2s first hit); 30-minute negative cache for unknown codes.
- **Local barcode search** — Automatically tries EAN-13 / UPC-A variant barcodes.

## [1.7.38] - 2026-06-04

### Fixed
- **Finished products on shopping list** — Depleted items are now added to Bring! under their generic `shopping_name` (e.g. “Affettato”). If the generic is already on the list, the specific variant is appended to the specification instead of being skipped. Confirming a ghost/finished product from the dashboard banner also triggers this flow.
- **Unstable shopping total** — Dashboard, Spesa tab, Home Assistant and screensaver now share one **weekly canonical total** (`PRICE_UPDATE_WEEKS=1`). Totals use **1 package per list item** (no more day-to-day swings from smart-shopping suggested quantities). AI prices are fetched only for items missing from cache; manual 🔄 refresh forces an update.
- **Screensaver price mismatch** — Screensaver waits for the canonical total sync before displaying the amount, matching the other surfaces.

### Changed
- **Shopping list UI** — Generic list entries show the group name with specific finished variants underneath (same pattern as smart shopping suggestions).

## [1.7.37] - 2026-06-04

### Fixed
- **Recipe pantry false positives** — Generated recipes no longer mark ingredients as ✅ in pantry when the product is not in stock or the name does not strictly match an inventory item (score ≥ 80, no generic alias expansion like *formaggio* → any cheese). AI prompt now receives the full in-stock list and explicit rules forbidding invented ingredient names.
- **`renderRecipe` crash** — Restored missing `qtyNum` variable when reopening archived recipes with pantry ingredients (ReferenceError on the "Use ingredient" button).

### Changed
- **`re-enrich-recipe.php`** — Re-applies strict pantry matching before stock hints when fixing archived recipes.

## [1.7.36] - 2026-06-04

### Added
- **Recipe ingredient stock hints** — Pantry ingredients in generated and archived recipes now show a small line under each item: how much you have in stock and how much would remain after use. Quantities are summed across all storage locations.
- **Zero-waste use-all rule** — When the leftover would be less than **5% of the full sealed package** (or **10%** when less than one full unit is left on an opened pack), the recipe quantity is automatically bumped to use everything on hand (♻️ badge + note in all 5 languages).
- **Ghost product detection** — Dashboard anomaly banner now surfaces products that vanished from inventory (ledger says stock should exist but no rows remain), with a restore prompt and quantity input.
- **`inventory_restore_ghost` API** — Restores a vanished product row from the banner without losing transaction history.
- **`product_merge` API** — Merges duplicate product records (inventory, transactions, aliases) into a single canonical product.
- **Maintenance scripts** — `scripts/sync-i18n.py` (5-language key sync), `scripts/re-enrich-recipe.php` (re-apply stock hints to archived recipes), `scripts/merge-duplicate-products.php` (batch duplicate merge).

### Fixed
- **Unified shopping total** — Dashboard, Spesa page and screensaver now share one canonical server-side total (`shopping_total_cache`); background refresh runs during screensaver too.
- **Recipe stream auth** — `generate_recipe_stream` and other direct `fetch()` calls now send the API token consistently, fixing 401 errors during recipe generation.
- **Home Assistant auth compatibility** — HA integration endpoints accept the configured API token without breaking legacy setups.
- **Security hardening** — API bootstrap modularised; scale SSE relay and sensitive routes require auth; env migration script for legacy installs.
- **Dashboard banner i18n** — Fixed raw translation keys (`dashboard.banner_*`) showing in the UI; full sync across IT/EN/DE/FR/ES with cache bust.
- **Ghost banner permanently hidden** — Removed incorrect `fin_*` hide logic that suppressed vanished-product alerts after a false "finished" confirmation.
- **`deleteInventory` / `use_all` dedup** — Inventory deletions now log transactions; duplicate `use_all` within 60 s is deduplicated; `confirmFinished` reconciles ledger mismatches.
- **Duplicate product prevention** — `saveProduct` blocks creating a second product with the same normalised name.
- **Recipe qty normalization** — conf+weight ingredients (e.g. ceci, basilico) now keep recipe amounts in grams/ml instead of copying the inventory conf count; use-all percentage is calculated on the sealed package size, not current stock.

## [1.7.35] - 2026-06-02

### Fixed
- **Barcode scanner accepts invalid codes** — Manual barcode input with an incorrect EAN checksum now blocks the lookup and shows an error (previously showed a warning but proceeded anyway). The native `BarcodeDetector` path now also validates EAN-8/EAN-13/UPC checksum before confirming a scan, consistent with the Quagga fallback which already did this check.
- **Recipe persons +/− buttons stopped working in the generation dialog** — A duplicate `adjustRecipePersons` function added for the post-generation rescaler was overriding the one that updated the persons input in the recipe setup dialog. The rescaler is now named `scaleRecipePersons` to avoid the conflict.

## [1.7.34] - 2026-05-30

### Added
- **AI visual barcode fallback** — When the barcode scanner fails to read a barcode within 5 seconds, EverShelf can now automatically capture a camera frame and send it to Gemini Vision to visually identify the product (name, brand, category). On success the product is saved and the inventory form opens just as if a barcode had been scanned. A new toggle in **Settings → Camera** (`AI visual identification (5s fallback)`) lets users enable or disable this feature at any time. Requires Gemini API key configured. Disabled by default.

## [1.7.33] - 2026-05-29

### Fixed
- **HA sensor `shopping_total` always null** — `haInventorySensor` was reading `shopping_total_cache.json` with a 1-hour TTL (cache populated only by the JS frontend, so it was often empty). Extended TTL to 24 hours and added an inline fallback: when the cache is absent or stale, the sensor now computes the total directly from `shopping_price_cache.json` without any AI calls. Queries `shopping_list` joined to `products` for the canonical `shopping_name`, then looks up both v3 and legacy v0 cache key formats to maximise hit rate. Works in both internal and Bring shopping modes.
- **HA `ha_refresh_prices` using non-existent columns** — `haInventorySensor` and `haRefreshPrices` were querying `quantity`, `unit`, `checked` from `shopping_list` — columns that do not exist in that table (schema: `id, name, raw_name, specification, added_at, sort_order`). Changed to `SELECT name` with `shopping_name` join and default `qty=1 / unit=pz`.


## [1.7.32] - 2026-05-29

### Changed
- **Smarter expiry u2192 shopping list logic** — The "expiring soon" threshold is now 7 days (was 3), giving enough time to plan the next shopping trip. Items expiring soon are only flagged for restocking when the user is a **regular buyer** (`isRegular`) and either stock is low (<50%) or the consumption rate predicts the item will expire before being used. Non-regular products keep the old 3-day safety-net. Expired items are now only added to the shopping list when `isRegular || buyCount >= 2` — products that expired unused without ever being a staple no longer pollute the list; the expiry banner handles them.


## [1.7.31] - 2026-05-29

### Fixed
- **New pack merges into opened pack on add** — `addToInventory` was looking for ANY existing row for the same product+location and adding the new quantity to it. This caused a newly purchased sealed pack to be silently merged with an already-opened pack, collapsing two physically distinct containers into one row and corrupting the `opened_at` timestamp. The fix now searches only for a **sealed** (unopened) row (`opened_at IS NULL`) to merge into. If only opened rows exist, a new sealed row is created instead — keeping the two packs separate and allowing the anomaly model and shelf-life tracker to work correctly.


## [1.7.30] - 2026-05-29

### Fixed
- **False consumption anomaly with multi-row stock** — The anomaly detection banner was evaluating each inventory row in isolation. Products split across multiple rows (e.g. one opened pack with 1 pz + one sealed pack with 6 pz) incorrectly triggered a "consumed faster than expected" warning because only the opened row (1 pz) was compared against the model. The check now aggregates the total quantity across all rows for the same product before deciding to flag an anomaly. If the combined total ≥ expected remaining, the anomaly is suppressed.


## [1.7.29] - 2026-05-29

### Added
- **Buy-cycle consumption prediction** — Products that are never tracked per-use (salt, spices, cleaning supplies, etc.) now use the average time between restocks as a proxy for consumption rate. When a product has ≥ 3 purchase events and no individual `out` events, EverShelf calculates the average buy cycle (`(lastBuy - firstBuy) / (buyCount - 1)`) and estimates how many days of stock remain in the current cycle. The product appears in the smart shopping list with a reason like "Finisce tra ~12gg (ciclo medio 75gg)" before it runs out, rather than only after. These products are now also treated as `isRegular` so all stock-level urgency checks apply correctly.


## [1.7.28] - 2026-05-30

### Fixed
- **Duplicate auto-reported issues** — The GitHub issue reporter was relying solely on the GitHub Search API for deduplication. Because search indexing has a several-minutes lag, rapid error recurrences each created a new issue before the previous one was indexed, producing ~50 duplicate issues. The reporter now uses a local file cache (`data/reported_issue_fps.json`, with `/tmp/` fallback when `data/` is not writable) as the primary deduplication store. A 30-minute per-fingerprint comment throttle is also applied to prevent flooding an existing issue. GitHub Search is used only on first run or after a cache miss. Closes [#134](https://github.com/dadaloop82/EverShelf/issues/134) (and all duplicates #135–#183).

## [1.7.27] - 2026-05-29

### Added
- **HA sensor enrichment** — All HA sensor attributes that list products now include full product details: `location`, `brand`, `category`, `days_remaining`, `opened_at`, `vacuum_sealed`, `default_quantity`, `package_unit`, `product_id`, `inventory_id`. Applies to `expiring_list`, the new `expired_list`, and the new `low_stock_list`.
- **HA `expired_list` attribute** — `sensor.evershelf_overview` now exposes `expired_list` (full details for all expired items, not just a count).
- **HA `low_stock_list` attribute** — New attribute listing all items with quantity ≤ 1 with full product info.
- **HA `sensor=product` endpoint** — New `GET /api/?action=ha_sensor&sensor=product` returns the full inventory with all product details. Optional filters: `&id=N`, `&name=...`, `&location=...`.
- **Inventory edit safety guard** — Confirm dialog when saving a quantity that is unusually large for its unit (e.g. 183 conf), preventing accidental data loss from unit-confusion typos.
- **Bread shelf-life in fridge** — Opened shelf-life rules added for piadina/crescia (2 days), packaged sliced bread/bauletto (4 days), and generic bread (3 days).

### Fixed
- **Recipe AI ingredient substitution** — Added explicit rule to both recipe prompts preventing Gemini from substituting ingredient forms (e.g. fresh tomatoes ↔ passata, fresh milk ↔ UHT ↔ cream, flour 00 ↔ wholemeal).
- **HA cron webhook payload** — Expiry alert webhook items now include full product details (brand, category, location, days_remaining, opened_at, vacuum_sealed) instead of only name/qty/unit/expiry_date.

### Docs
- `docs/wiki/Home-Assistant.md` — Documented new `sensor=product` endpoint, full product schema table, enriched webhook payload example, and Lovelace/automation template examples using `location` and `days_remaining`.

## [1.7.26] - 2026-05-26

### Added
- **Monthly stats panel** — Third rotating card in the insight banner (anti-waste → nutrition → monthly stats, 1 minute each). Shows products consumed this month with a trend vs. the previous calendar month (↑/↓/→ with % delta), animated horizontal category bars, and badges for items added, wasted, and top-used product. Falls back gracefully when the current month has no transactions. Closes [#100](https://github.com/dadaloop82/EverShelf/issues/100).
- **Extended smart-shopping horizon for staples** — Items consumed ≥ 4 times/month now get a 28-day look-ahead window; ≥ 2 times/month get 21 days. Frequently used staples no longer disappear from the smart list between restocks. Closes [#98](https://github.com/dadaloop82/EverShelf/issues/98).

### Fixed
- **TTS test interactive confirmation** — Test timeout raised from 4 s to 10 s; instead of an error, the UI shows a YES/NO prompt ("Did you hear it?") so users can confirm or report failure explicitly.
- **`end()` PHP 8 reference error** — `_offFetchProduct()` passed the result of `??` directly to `end()`, which requires a variable. Fixed with a temporary variable.
- **Database migration crash on fresh installs** — `migrateDB()` tried to rename the `transactions` table before it existed. A `sqlite_master` guard now calls `initializeDB()` and returns early when the schema is absent. Closes [#131](https://github.com/dadaloop82/EverShelf/issues/131), [#133](https://github.com/dadaloop82/EverShelf/issues/133).
- **Health-check crash on empty database** — `db_row_count` query was executed even when the `inventory` table was missing, causing a fatal PDO error. The query is now skipped until the schema is fully initialised. Closes [#132](https://github.com/dadaloop82/EverShelf/issues/132).
- **Insight banner stuck on one panel** — Rotation interval was 1 hour (effectively invisible); now 60 seconds. `_applyInsightPhase` also now skips empty panels instead of always falling back to the anti-waste card, so the rotation works correctly even when a panel has no data.
- **Untranslated OpenFoodFacts category labels** — Categories stored as OFF slugs (`en:plant-based-foods-and-beverages`, `en:dairies`, …) were shown raw. A new `_normalizeCat()` PHP function maps ~60 OFF slugs to Italian app categories; counts are re-aggregated after normalisation so `en:dairies` + `en:milk` both contribute to `latticini`.

## [1.7.25] - 2026-05-25

### Added
- **Home Assistant integration** — Full bidirectional HA support: inventory sensor (`sensor.evershelf_*`) exposes item counts, expiring items, shopping total, opened items and next-expiry info. Webhooks fire on inventory changes (add/use/shopping). Daily cron alert notifies via HA for items expiring within the configured threshold. TTS announces cooking steps through HA Media Player. New Settings tab 🏠 with connection test, TTS preset (Piper, Google, Nabu Casa), webhook config, and YAML snippet for `configuration.yaml`. Resolves [#111](https://github.com/dadaloop82/EverShelf/issues/111).
- **Offline mode** — Full offline-first support. Full-screen overlay on network loss; "Continue offline" button after 3 s, auto-enter after 8 s. Inventory and settings are synced to `localStorage` at startup and cached on every successful API call. Writes (add/use/update/delete) are queued and synced on reconnect with optimistic UI updates. Pending operations survive page refresh and are re-synced automatically at next startup. AI/network-dependent sections (anti-waste chart, nutrition analysis, recipe generator, price fetching, Gemini chat) are hidden in offline mode. `remoteLog` and `reportError` are buffered offline and flushed on restore. Broken external images replaced with a grey placeholder.
- **Offline-computed dashboard** — While offline, `inventory_summary` and `stats` (expiring/expired/opened) are derived client-side from the local cache so all dashboard stat cards and expiry alerts show accurate data.

### Fixed
- **Offline banner flood** — Opened items in the offline `stats` response lacked `is_edible`; `!undefined` evaluated to `true`, causing every opened item to be shown as "not edible" in the dashboard banner. Field is now set to `true` (client-side shelf-life check already handles genuinely expired items).
- **Version update badge showing older versions** — `_checkWebappUpdate` used `latestTag !== _loadedVersion` (inequality only), so running a newer dev build triggered an "update available" badge for an older GitHub release. Now uses `_semverGt(latest, current)` so only genuinely newer releases trigger the badge.
- **Bring! items re-appearing after manual purchase removal** — `removeBringItem` and `confirmShoppingItemFound` now call `_markBringPurchased` immediately, and `autoAddCriticalItems` respects the blocklist for depleted items.
- **Barcode lookup false "not found"** — New `_offFetchProduct()` tries three barcode candidates (given, UPC-A↔EAN-13 conversion) across two Open Food Facts locales with auto-retry.
- **Partial throw from expired-items banner** — "Butta" now opens the throw modal (qty + location) instead of silently deleting the entire inventory row.
- **Related stock display when scanning branded products** — When scanning a product, the action page now shows a green card listing any inventory items from the same generic family already at home.

## [1.7.24] - 2026-05-21

### Fixed
- **Dark mode resets to Auto on every reload** — `dark_mode` was never saved to `.env` (missing from `saveSettings` and `getServerSettings`). It is now fully server-side like all other settings; `localStorage` retains only a pre-render hint for the flash-prevention IIFE.
- **Cooking timer — no sound or speech on Android kiosk** — Three independent root causes fixed: (1) `AudioContext` was created fresh outside a user gesture, starting in `suspended` state and failing silently; a shared pre-unlocked context (`_sharedAudioCtx`) is now created during user gestures (`startCookingMode`, `addCookingTimer`). (2) The `_cookingTTS` gate (for step narration) was incorrectly blocking timer alarm speech — timer alerts now always speak regardless of that flag. (3) `_kioskBridge.speak()` (native Android TTS) was never considered as a fallback when `window.speechSynthesis` is absent in the WebView.
- **Scale use ignored for conf products** — `_scaleAutoFillUse()` returned early when `_activeUnit !== 'sub'`, but conf products default to `conf` mode. The function now auto-switches to sub mode before processing the weight reading. Scale button (`btnUse`) is also now visible for conf products that have a g/ml package unit.
- **Kiosk — native settings button reappearing unexpectedly** — `closeModal()` was calling `setNativeSettingsVisible(true)`, restoring the native Android settings button after every modal close. `_injectKioskOverlay()` now permanently hides the native button; scattered per-modal show/hide calls removed; a ⚙️ web button opens the in-app settings page.
- **SQLite database locked during inventory update** — `updateInventory()` made 3–4 separate write statements without a transaction; a concurrent cron job could acquire the write lock between them, causing a `database is locked` PDO error. All writes are now wrapped in `beginTransaction()`/`commit()`, with the Bring! HTTP sync deferred to after `commit()`. Closes [#109](https://github.com/dadaloop82/EverShelf/issues/109), [#110](https://github.com/dadaloop82/EverShelf/issues/110).
- **Depleted-item urgency incorrect** — Items with zero quantity were assigned urgency based on recency of use rather than consumption frequency. Urgency is now computed from `usesPerMonth` only, so frequently-used depleted items are correctly flagged as urgent.
- **0.5 conf use and decimal display** — Default mode on the use-quantity page is now conf for conf products; fraction buttons (½, ¼, ¾) work correctly; conf decimals are shown in the transaction history log.
- **Bring! health check token warning** — Token validity warning was shown even for valid tokens; health check is now restored with correct token-format detection.
- **Recipe quantities for conf+weight products** — Quantities are now calculated correctly when a conf product has a gram-based package unit.
- **Shopping settings not syncing across clients** — `shopping_*` keys were missing from `serverKeys` in `_applySyncedSettings`; shopping settings were client-local. All shopping keys now sync from server on load.

### Added
- **Native shopping list** — Built-in shopping list (no Bring! required) as an alternative mode (`SHOPPING_MODE=internal`). Resolves [#105](https://github.com/dadaloop82/EverShelf/issues/105).
- **Google Drive backup via localhost OAuth** — GDrive backup no longer requires a public domain; the OAuth redirect flow uses `http://localhost` via a temporary local server, compatible with self-hosted setups. Resolves [#107](https://github.com/dadaloop82/EverShelf/issues/107).

### Changed
- **All settings fully server-centralised** — Removed remaining `localStorage` usage for user preferences; all settings are now read from and written to `.env` via the API. Preferences are shared across all devices (desktop, phone, kiosk) automatically.

## [1.7.23] - 2026-05-18

### Added
- **⚙️ Generali tab** — new first tab in Settings groups all global settings: language, currency, theme, screensaver, zero-waste tips, inventory export. Old Language tab removed.
- **DB auto-cleanup** — `RECIPE_RETENTION_DAYS` (default 7) and `TRANSACTION_RETENTION_DAYS` (default 7) added to `.env`; old rows are deleted automatically every cron cycle, followed by `VACUUM` to compact the database. Manual trigger: `GET /api/?action=db_cleanup`.
- **Vacuum-sealed expiry grace period** — `VACUUM_EXPIRY_EXTENSION_DAYS` (default 30): vacuum-sealed products are only flagged as expired N days *after* the printed date, preventing false alarms on long-lasting items like cured meats.
- **Gemini AI usage tracking** — monthly and yearly token/cost stats now shown in Settings → ℹ️ Info tab, using tracked data from `data/ai_usage.json`. Cost rates configurable via `GEMINI_COST_25F_IN/OUT` and `GEMINI_COST_20F_IN/OUT` in `.env`.

### Changed
- **Auto theme is now time-based** — "Automatico" mode switches to dark at 20:00 and back to light at 07:00, based on server/device clock (not OS preference). Re-evaluates every 5 minutes; ideal for always-on kiosk displays.
- **`dispensa.db` auto-deleted** — if the legacy empty `dispensa.db` file appears alongside `evershelf.db`, it is now removed automatically by the health check.
- **ZeroWaste tips and screensaver timeout** — these settings were not being persisted to `.env` on save (missing from POST payload); fixed.

## [1.7.22] - 2026-05-17

### Fixed
- **DB name corrected** — `health_check` now looks for `evershelf.db` (was wrongly looking for `dispensa.db`). Auto-migration included: if `evershelf.db` is missing but `dispensa.db` exists, it is renamed automatically on startup.
- **Removed legacy `data/dispensa.db`** — the old database file has been deleted; only `evershelf.db` is used.
- **Conditional checks** — Bring!, TTS, Scale and Internet checks only run when the respective feature is enabled in `.env` (no more false ❌/⚠️ for unconfigured features).
- **Backups check** — no longer checks if `data/backups/` is writable by www-data (cron writes as root). Now checks that backup files actually exist and the most recent one is recent.
- **Bring! token check** — reads `data/bring_token.json` file instead of looking for a non-existent `BRING_ACCESS_TOKEN` env var.

### Changed
- **Warning popup with 5s countdown** — when non-critical checks fail at startup, a styled popup appears showing each warning with its label and a plain-language hint explaining the problem. A countdown bar auto-closes the popup after 5 seconds, then the app starts normally.
- **Error blocking popup** — when critical checks fail, a clear blocking panel shows with title "Errore critico", each failed check listed with its explanation hint, and a Retry button. The app does not start.
- **`db_legacy` check added** — warns (optional) if the old `dispensa.db` file is still present alongside `evershelf.db`.
- **32 total checks** — added `db_legacy`, `tts_url`, `scale_gateway` to the check set (conditional).
- **Hint messages** — every check now has an Italian-language `hint` field explaining what is wrong and how to fix it.

## [1.7.21] - 2026-05-20

### Changed
- **Startup health check** — Complete redesign from a banner checklist to a **real-time progress bar**. The bar fills smoothly as each of 29 diagnostic checks runs, with the current check name shown below in real time. Warnings (⚠️) are displayed as amber badges that remain visible for 2 seconds before the app proceeds. Critical failures turn the bar red and show a detailed error block with a Retry button.
- **29 comprehensive checks**: PHP version, 8 PHP extensions (pdo_sqlite, curl, json, mbstring, openssl, fileinfo, zip, intl), PHP memory/timeout/upload config, data directory, rate_limits dir, backups dir, disk write test, free disk space, SQLite connection, required tables, integrity (PRAGMA quick_check), WAL mode, DB size, inventory row count, .env file, Gemini AI key, Bring! credentials, Bring! token, cURL SSL, internet reachability.
- Warnings now clearly visible: each non-critical failure shows as a named amber badge (e.g. "⚠️ Bring! token") that cannot be missed.

## [1.7.20] - 2026-05-20

### Added
- **Startup health check** — During the splash screen, the app now runs a comprehensive server-side diagnostic before loading: PHP version, required extensions (pdo_sqlite, curl, mbstring, json), `data/` directory writability, SQLite database connection and table integrity, `.env` file presence, Gemini AI key and Bring! token. Results are displayed as an animated checklist (✅ / ⚠️ / ❌). Critical failures (DB, extensions, data dir) block the app with a clear error message and a "Retry" button — the app never starts silently broken. Non-critical warnings (missing Gemini key, Bring! token) are shown as amber items but do not block startup.
- New `?action=health_check` PHP endpoint (early-exit, no rate-limit, no auth).
- New translation keys `startup.*` in all 5 languages (IT, EN, DE, FR, ES).

## [1.7.19] - 2026-05-19

### Added
- **Zero-waste tips during cooking** — When cooking mode is active, a ♻️ card appears below each step that generates reusable scraps (peels, cooking water, egg whites, cheese rinds, bread crusts, vegetable tops, etc.). Gemini generates the tips as part of the recipe JSON at no extra API cost. Tips are dismissible per-step and reset on recipe restart. Opt-in toggle in Settings → Zero-waste tips (default OFF). Resolves [#76](https://github.com/dadaloop82/EverShelf/issues/76).
- New translation keys `cooking.zerowaste_*` and `settings.zerowaste.*` in all 5 languages (IT, EN, DE, FR, ES).

## [1.7.18] - 2026-05-19

### Added
- **Dark mode** — New theme selector in Settings (Appearance card): **Off (Light)**, **On (Dark)**, **Auto (follows system)**. Applied immediately on page load to prevent white flash. Resolves [#78](https://github.com/dadaloop82/EverShelf/issues/78).
- **Export inventory** — New 📤 button in inventory page header opens a modal to download the inventory as **CSV** (UTF-8 with BOM, Excel-compatible) or open a **print-ready HTML page** (auto-triggers print dialog for PDF). Export card also available in Settings tab. Resolves [#64](https://github.com/dadaloop82/EverShelf/issues/64).
- `translations/de.json`: fixed missing `log.recipe_prefix` key.

## [1.7.17] - 2026-05-19

### Added
- **French translation (🇫🇷 Français)** — Complete `translations/fr.json` with all 1049 translation keys. Resolves [#77](https://github.com/dadaloop82/EverShelf/issues/77).
- **Spanish translation (🇪🇸 Español)** — Complete `translations/es.json` with all 1049 translation keys. Resolves [#77](https://github.com/dadaloop82/EverShelf/issues/77).
- Language selector in Settings now shows all 5 languages: 🇮🇹 Italiano, 🇬🇧 English, 🇩🇪 Deutsch, 🇫🇷 Français, 🇪🇸 Español.
- Default fallback language changed from Italian to English (for users with unsupported browser locale).
- Setup wizard "Done" screen and navigation buttons localised for French and Spanish.

## [1.7.16] - 2026-05-17

### Added
- **Barcode scan history** — Last 20 scanned products are stored server-side (SQLite `app_settings`) and shown as chips in the scan page (`#scan-recents-chips`). Tapping a chip selects the product directly — no need to scan again. Resolves [#68](https://github.com/dadaloop82/EverShelf/issues/68).
- **Full server-side user-data centralisation** — All user preferences previously siloed in `localStorage` per-device are now synced to the server via `app_settings_save` and loaded back at startup via `app_settings_get`. Affected data: shopping tags, pinned Bring! items, location preferences (use/move), auto-added Bring! entries, Bring! purchased blocklist, no-expiry dismissed products. Data is now shared across all devices (desktop, phone, kiosk, Android app).
- **One-time localStorage migration** — On first load, any data found in the old localStorage keys (`shopping_tags`, `_userPinnedBring`, `_prefUseLoc`, `_prefMoveLoc`, `_autoAddedBring`, `_bringPurchasedBlocklist`, `_noExpiryDismissed`, `evershelf_scan_recents`) is automatically migrated to the server and the local keys are removed.

## [1.7.15] - 2026-05-16

### Added
- **Full i18n audit** — Comprehensive sweep of all user-visible strings in `app.js` and `index.html`. 25+ new translation keys added across `it.json`, `en.json`, `de.json`, covering: vacuum toast, TTS voice controls, timer step labels, product note labels, error messages, expiry form, barcode hint, category select placeholder, cooking step fallback, `form.select_placeholder`, `btn.yes_short`/`no_short`, `add.vacuum_question`, `add.vacuum_saved`, `move.vacuum_seal_rest`, `cooking.step_fallback`, `error.prefix`/`unknown`, `product.select_variant`, and more.
- **Splash screen redesign** — Logo displayed prominently, spinner below, app version shown at the bottom; version label injected dynamically at boot time so it never gets out of sync. Minimum 3-second display duration enforced: `_splashStart` is recorded before `DOMContentLoaded`; the fade-out is delayed by the remaining time if the app loads faster than 3 s.
- **Demo GIF in README** — `assets/img/demo.gif` (processed at 2× speed, ~36 s) added to the `## 📸 Screenshots` section.
- **`pz`/`conf` unit labels translated** — "pz" now shows as "pcs" in English and "Stk" in German; "conf" shows as "pkg" / "Pkg". All `unitLabels` objects in JS now use `t('units.pz')` / `t('units.conf')`.

### Fixed
- **Camera button (📷) opened kiosk SettingsActivity on Android** — The native `btnSettings` ImageButton in the kiosk layout was positioned `top|end` with `alpha=0.12` (nearly invisible), sitting directly on top of the HTML scan button in the webapp header. Every tap on the 📷 button was intercepted by the native View and opened `SettingsActivity`. Fixed: moved `btnSettings` to `bottom|end` (above the bottom nav bar, `marginBottom=80dp`) and increased `alpha` to `0.28` so it is clearly separate from the header. Kiosk versionCode bumped to 16.
- **Camera button (📷) opened settings on Android Chrome/Brave** — `pointerleave` fired before `pointerup` when finger drifted slightly, cancelling the long-press timer and leaving the browser to dispatch a synthetic `click` that bubbled to an unintended handler. Fixed: added `setPointerCapture` (prevents `pointerleave` during touch) and `preventDefault` (blocks synthetic click); replaced `pointerleave` with `pointercancel` handler. Added `touch-action: manipulation` to `.header-scan-btn` CSS.
- **Logo white background on splash screen** — Re-processed both `logo.png` and `logo_icon.png` with fuzz 35% alpha extraction, removing the white background that was visible against the dark splash background (`#0f172a`).
- **Recipe button label** — Shortened to "Ricetta" / "Recipe" / "Rezept" for compact display in the inventory quick-action modal.
- **Quantity decimal precision** — `qtyNum` in recipe/cooking ingredient buttons and `conf` fallback display in inventory cards now limited to 1 decimal place (was showing 7+ decimal places from raw AI output, e.g. `0.25353223 conf`).
- **"Errore" / "Error" fallback strings** — All remaining Italian hardcoded `'Errore'` fallbacks in `showToast()` calls replaced with `t('error.generic')`. Italian fallback strings removed from buttons that already used `t()`.
- **README Italian phrases** — "La quantità è giusta (2 pz)", "🤖 Spiega", "Latte / Affettato / Panna da cucina", "Buon appetito!", "L'ho buttato" replaced with English equivalents in the README.
- **Appliance chips translated** — `renderAppliances()` now shows translated names (e.g. "Air fryer" in EN, "Heißluftfritteuse" in DE) for all known canonical Italian appliance names via `_applianceDisplayName()` lookup. `addApplianceQuick` toast no longer hardcoded Italian. Remove-button title translated.
- **Gemini API key not preserved on settings save** — `saveSettings()` was overwriting `s.gemini_key = ""` when the Gemini input field was empty (it is intentionally not pre-populated for security). Key is now preserved if the input is blank. `_geminiAvailable` is re-fetched from the server after every settings save so the recipe buttons reflect the real state immediately.

## [1.7.14] - 2026-05-16

### Added
- **In-app bug report form** — "Segnala un problema" now opens a modal form instead of redirecting to GitHub. Users can select type (Bug / Feature / Question), write title and description, optionally add reproduction steps. A GitHub issue is created directly with labels and app metadata attached.

### Fixed
- **Kiosk settings button** — "Apri configurazione kiosk" in webapp settings was showing a toast asking to tap a gear icon that no longer exists. Now calls `openNativeSettings()` bridge directly (opens Android SettingsActivity). Fallback for old APKs shows a proper "update the kiosk app" hint.
- **False update badge** — `manifest.json` version was `1.7.12` while the app header showed `v1.7.13`, causing the server to report an older deployed version and triggering a spurious update notification.
- **Kiosk settings gear disappeared** — Race condition where Kotlin's `onPageFinished` injects `#_kiosk_overlay` before JS runs; JS found the element already present and returned early without ever restoring the native gear button. Fixed: JS no longer hides the native gear on load; `closeModal()` restores it with `setNativeSettingsVisible(true)`.
- **`openNativeSettings()` fragile typeof check** — Android `@JavascriptInterface` methods are not always detected as `'function'` by typeof; replaced with try/catch.

## [1.7.13] - 2026-05-16

### Fixed
- **Fresh-install crash: `no such column: undone`** — The `transactions` table was created in `initializeDB()` without the `undone` column, but the composite index `idx_transactions_pid_type_undone` immediately referenced it, crashing every new installation at first DB access.  Added `undone INTEGER DEFAULT 0` to the transactions schema in `initializeDB()`.
- **Race condition: `duplicate column name: package_unit`** — Concurrent API requests on a new installation could all pass the `PRAGMA table_info` guard simultaneously and each try to `ALTER TABLE products ADD COLUMN package_unit`, with all but the first failing with a PDOException.  Wrapped all `ALTER TABLE … ADD COLUMN` calls in try/catch to silently ignore duplicate-column errors.

## [1.7.12] - 2026-05-13

### Fixed
- **"Use first" banner showed a calculated expiry date** — `_renderUseExpiryHint` was displaying a *calculated* shelf-life date (from opening date) instead of the actual one. When `opened_at` is set, the banner now shows "That one [in the fridge], opened X days ago — use it first!" using the new `use.expiry_warning_opened` translation key.
- **"Use All / Done" in recipes deleted the inventory row** — `submitRecipeUse(true)` was sending `use_all: true` to the API, which executed a direct `DELETE` on the inventory row without any confirmation. The function now calculates the exact quantity from the available items (`_recipeUseContext.items`) and sends a regular `inventory_use` with an explicit quantity.
- **Recipes: `qty_number` returned in grams for piece-counted (`pz`) items** — The AI prompt and PHP post-processing now instruct Gemini to express `qty_number` as whole pieces for ingredients with unit `pz` (sliced bread, crackers, etc.). The ingredient list in the prompt includes `[use whole PIECES]` for each `pz` product. The PHP fallback for `pz` items without `default_quantity` no longer divides by 100, but uses the AI-returned `qty_number` if it is a plausible count, otherwise defaults to 1.

### Added
- **Translation key `use.expiry_warning_opened`** — New key in `it.json`, `en.json`, `de.json` with `{loc}` (location) and `{when}` (days since opening) placeholders.

## [1.7.11] - 2026-05-12

### Added
- **Scan page redesign** — The scanner page has been completely redesigned for tablet and mobile:
  - **2× fixed zoom** — hardware zoom if available, otherwise automatic CSS `scale(2)`.
  - **Torch** — in-viewport button with toast feedback and visual state indicator.
  - **Camera flip** — front/back switch with persistence in settings.
  - **3 input tabs** — Barcode / Name / AI for quick access to each scanning mode.
  - **Recent products** — chips for the last 6 scanned products (localStorage), with category icon.
  - **Live code overlay** — partially detected barcode shown as overlay in the viewport during partial scan.
  - **Confirm overlay** — checkmark + product name displayed for 900 ms on successful recognition.
  - **Guide corners** — visual alignment frame for barcode centering.
  - **AI Number OCR** — after 4 s without a scan, a "Read numbers with AI" button appears; Gemini analyses the video frame and returns barcode digits even when the optical scanner fails.
- **PHP `gemini_number_ocr` endpoint** — New POST endpoint; accepts a base64 JPEG image, asks Gemini to locate the EAN-13 / EAN-8 code printed on the product, and returns the digits or `not_found`.

### Fixed
- **False consumption anomaly positives (e.g. "Mozzarella 3 pcs")** — Removed the `untracked` direction (consumption higher than recorded purchases), which was generating banners for every product with untracked purchase history. Only `phantom` and `missing` anomalies are now reported.
- **"~0 g/week" consumption prediction** — The model now requires a minimum of 5 transactions (was 3) and a time span of at least 7 days; predictions where consumption is < 15% of the baseline are skipped, eliminating false positives for products with few closely-spaced transactions.
- **Suggestion dropdown on the Name field (scan page)** — Removed `list="common-products"` from the input field; the datalist is no longer triggered on tablets.

## [1.7.10] - 2026-05-11

### Fixed
- **"Set expiry" banner did nothing** — `editBannerNoExpiry()` was calling `openEditInventoryModal()` which does not exist. Fixed to call `editInventoryItem()` (the correct function used by all other banner handlers). Added a prefetch of `inventory_list` because `currentInventory` is empty on the dashboard.
- **"Product not found" when opening modal from a banner** — `currentInventory` is always empty on the dashboard; the inventory fetch now happens before opening the modal (same pattern as `editReviewItem` and `weighBannerItem`).
- **Expired banner on opened UHT milk** — The banner was showing "Expired!" instead of "Opened too long". Items with `opened_at` now display "Opened X days ago in [location]" in both the title and the banner detail.
- **Generic milk shelf life 4 → 7 days** — Milk without qualifiers (e.g. "Milk") was treated as fresh (4 days). Fresh milk is still handled explicitly (`latte fresco/intero/parzial/scremato` → 3 days); the generic case now defaults to 7 days (UHT default). Fix applied in both PHP (`database.php`) and JS (`app.js`).
- **Stale `opened_at` on sealed packages after split** — When a use operation splits a row into "whole sealed packages + opened fraction", the sealed-packages row was not clearing `opened_at`. All 3 split code paths now execute `opened_at = NULL` on the sealed row.
- **`inventory_update` was not recording transactions** — The quantity-edit modal updated inventory without creating transaction records. The quantity difference is now automatically recorded as `in` or `out` with a `[Manual correction]` note, preventing false positives in the anomaly detector.
- **False consumption anomalies after restocking** — The prediction baseline was using only the restock quantity (`restockQty`), ignoring pre-existing stock, causing `actual > expected` systematically. New baseline: `current_qty + consumed_since_last_restock`, which correctly reflects the real situation regardless of prior stock levels.
- **Anomaly banner firing on almost all products** — Two fixes:
  1. `expected = 0` no longer generates a "more" anomaly (the model assumed you should have run out, but you restocked).
  2. "More than expected" threshold raised to 400% (was 30%); "less than expected" threshold remains at 30%.
- **Expired section showing already-discarded products** — The `expired` query was missing `AND i.quantity > 0`; discarded products (qty=0) with a past expiry kept appearing. Query fixed and orphan rows cleaned from the DB.
- **Hardcoded Italian string `scade il` in banner** — Replaced with the correct i18n key.
- **Docker: `SQLSTATE[HY000][14] unable to open database file`** — `_ensureDataDir()` in `database.php` now creates the `data/` directory if missing and attempts `chmod(0775)` if not writable, resolving the error on freshly mounted Docker volumes.

### Added
- **Complete i18n** — Added ~25 missing translation keys for kiosk UI, Gemini responses, banners, scanner, shopping, and appliances across all 3 language files (`it.json`, `en.json`, `de.json`). Total: 934 keys per language.

## [1.7.8] - 2026-05-10

### Added
- **Transfer to Recipes from chat** — When the Gemini Chef chat generates a recipe, a "📥 Transfer to Recipes" button appears. Pressing it triggers Gemini to convert the chat text into a complete structured JSON (title, meal, ingredients, steps); the backend enriches each ingredient with `product_id` and `location` via fuzzy-match (identical to `generateRecipe`); the recipe is saved and opens directly in the Recipes section with all "Use" buttons and full cooking mode.
- **"Open recipe" button** — After a successful transfer, the "📥 Transfer to Recipes" button transforms into "📖 Open recipe" (same DOM element), preventing overlap.
- **Create a recipe from an ingredient** — In the action panel of every inventory item, a "👨‍🍳 Create a recipe with this" button appears (teal, full width). Pressing it, Gemini generates a recipe using that ingredient as the star (same pipeline as `chatToRecipe`: inventory fuzzy-match enrichment, `meal=null`, 8192 token max).
- **Meal not auto-categorized** — Recipes generated from chat or from an ingredient are no longer auto-categorized (`meal` remains null); the meal tag in the UI is only shown when explicitly set.

### Fixed
- **Smart shopping: false "running low" alert** — If a product in grams/ml was nearly exhausted (e.g. Butter 30 g = 12%) but the same product was also available as a sealed package (Butter 1 pack = 99%), the system still flagged "running low". Now checks whether the `shopping_name` family has stock from other products; if so, the alert is suppressed.
- **Corrupted translation JSON** — The `action` section was duplicated in `de.json`, `en.json`, and `it.json`, causing JSON parse errors that blocked CI/CD. The spurious duplicate section has been removed.

## [1.7.7] - 2026-05-10

### Fixed
- **Smart shopping family suppression** — The `recentlyExhausted` logic (products finished < 14 days ago) was incorrectly bypassing the `shopping_name` family suppression, causing false positives: products like Vanilla Yogurt appeared urgent even with 2 kg of Yogurt in stock. `recentlyExhausted` now only bypasses the token-based loose match; family suppression by `shopping_name` always applies.
- **Shelf-life pre-warming in cron** — The cron now calls `prewarmShelfLifeCache()` every 5 minutes, pre-loading via Gemini AI the shelf life of opened inventory items (max 5 items per cycle) before the user views them. This eliminates the noticeable delay on first click of "Opened on…".

## [1.7.6] - 2026-05-10

### Fixed
- **`shopping_name` truncated (Piadina)** — The product "Piadine medie" had `shopping_name='Pi'` (truncated), preventing it from grouping correctly in its family. Fixed to `Piadina`.
- **Family merges in DB** — Grana Padano now under `Formaggio` (was a `Grana` singleton), Prosciutto cotto now under `Affettato`, Panna acida now under `Panna`.
- **`daily_rate` over the actual active period** — The daily consumption rate was using `first_in → now` as the window, diluting the rate with periods when the product was already exhausted (e.g. garlic exhausted at day 34 was calculated over 60+ days). Now uses `first_in → last_activity` (last purchase or last use), giving more accurate reorder predictions.
- **Stable anomaly dismiss key** — The dismiss key was using `product_id + round(expected)`, which changed with every new transaction, causing already-dismissed anomalies to reappear. Now uses `product_id + direction` (phantom/missing/untracked) — stable as long as the direction does not change.
- **Smart shopping: products exhausted < 14 days ago** — Products finished within the last 14 days are no longer suppressed by the token-coverage check or the shopping_name family check: if you just ran out, you probably want to restock regardless of equivalent stock on hand.
- **Chat pruning** — `chatSave()` now deletes messages beyond the 200 most recent after each save, preventing unbounded growth of the `chat_messages` table.


## [1.7.5] - 2026-05-10

### Added
- **Vacuum sealed prompt on item use** — After using a conf/weighted-unit item that still has remaining stock, a sliding popup asks "🔒 Messo sotto vuoto?" with Sì/No buttons and an 8-second auto-dismiss countdown bar. Default is Sì if the item was previously sealed, No otherwise. Works for all container units (conf, g, kg, ml, l) and any item previously marked as vacuum sealed.
- **Multi-function appliance awareness in recipes** — When the user sets a multi-function appliance (Cookeo, Bimby, Thermomix, Monsieur Cuisine, Instant Pot, Multicooker, Robot da cucina) in Settings, all Gemini recipe prompts (chat, recipe generation, weekly meal plan) now explicitly instruct the AI to consolidate as many cooking steps as possible into that single machine. Each appliance's available functions (rosolare, tritare, vapore, cuocere a pressione, etc.) are listed and the AI is required to indicate the specific mode/program at each step.
- **Server-side Bring! cleanup in cron** — `bringCleanupObsolete()` now runs every 5 minutes via cron without requiring any client page load. Items auto-added by the app (identified by `⚡`/`🟠`/`🛒` markers in their Bring! spec) are automatically removed when the smart shopping engine no longer flags them as needed. Works across all devices/clients.
- **`shopping_name` in `inventory_list` API** — The `inventory_list` endpoint now returns the `shopping_name` field from the products table, enabling family-based stock matching in the client-side cleanup fallback.

### Fixed
- **Bring! cleanup: false token match (Succo/Frutta)** — `bringCleanupObsolete` previously indexed smart items by product name tokens. "Pera Italiana **Succo** e polpa **frutta**" (shopping_name: "Pere") caused "Succo" and "Frutta" to be retained on Bring! indefinitely even when fully stocked. Now indexes **only** by `shopping_name` tokens.
- **Bring! cleanup: expired items with fresh family stock (Verdure)** — When a product is expired but its `shopping_name` family has ≥50% fresh stock from other products (e.g. Minestrone tradizione scaduto 01/05 but 590g fresh Verdure in freezer/pantry), it is no longer flagged as `critical` and is removed from the shopping list.
- **Bring! remove: catalog items not removed (Formaggio/Käse)** — `bringRemoveItem()` and `bringCleanupObsolete()` now try both the Italian display name and the Bring! internal German catalog key (e.g. `Käse` for `Formaggio`). Previously, catalog items with a German key were silently not removed.
- **Barcode scanner: EAN auto-submit on manual input** — Typing or pasting a valid 8/13-digit EAN in the manual barcode field now auto-submits immediately without needing to press a button. Checksum validation gives a warning toast for invalid codes without blocking entry.
- **Shopping list: `isExpiringSoon` false positives** — Products bought in bulk that expire naturally in 3 days (e.g. fresh produce) were flagged `medium` urgency on the shopping list despite having 100%+ stock. Now requires `pctLeft < 50%` before triggering.
- **Shopping list: expired batch with fresh restock suppressed** — Products with an expired batch AND a recent fresh restock (≥50% fresh stock) are no longer flagged `critical` for shopping. The expired-batch UI banner on the dashboard handles the disposal prompt instead.
- **Shopping list: cross-device cleanup** — Client-side `cleanupObsoleteBringItems()` now detects app-added items by their spec markers (`⚡`/`🟠`/`🛒`) instead of a per-device localStorage map, making cleanup work correctly on all clients including newly logged-in devices. Throttle reduced from 30 minutes to 3 minutes.
- **API fetch caching disabled** — All `api()` calls in the frontend now set `cache: 'no-store'` to prevent stale data from browser cache.
- **Shopping page multi-client sync** — Added 45-second polling on the shopping page so changes made on another device are reflected automatically.



### Added
- **AI price estimation for shopping list** — Each item on the Bring! shopping list now shows an estimated retail price badge (per unit and total). Prices are fetched from Gemini AI and cached server-side for 3 months (`PRICE_UPDATE_MONTHS`). The running estimated total is displayed both in the shopping tab and as a green pill badge on the dashboard stat card.
- **Dashboard price total badge** — The shopping stat card on the dashboard shows a green `ca. €X.XX` badge (top-right, same position as the old urgency badge). It updates in real-time as prices are calculated and persists across navigation via `sessionStorage`.
- **Background price refresh** — Prices are fetched silently every 2 minutes even when not on the shopping tab, keeping the dashboard badge current without user interaction.
- **Smart quantity estimation** — The price payload uses `smart_shopping` data (consumption patterns) to send the correct buy quantity per item; falls back to Bring! spec parsing, then to `qty=1, unit=conf` for manually-added items.

### Fixed
- **`stat-price-total` not visible on dashboard** — The total was only computed when `shoppingItems` was populated (i.e. shopping tab had been visited). Now uses `sessionStorage._pricetotal` as fallback so the badge is visible immediately on any page.
- **Price bar reloading on every tab switch** — `renderShoppingItems` now checks if ALL items are already cached with matching qty/unit; if so, it applies prices from cache instantly with no loading bar or API call.
- **`stat-price-total` real-time update** — Dashboard stat now increments as each individual item is priced (not only after the entire fetch completes).
- **Broken emoji in `log.title`** — Corrupted `\uFFFD` character in `it.json` and `de.json` replaced with `📒`.
- **`PRICE_CACHE_PATH` undefined crash** — Server-side constant was used inside functions that were called before the define; moved define to the very top of `api/index.php` (line 19). Affected: all `get_shopping_price` and `get_all_shopping_prices` calls from 16:33–16:40 on 2026-05-07.

## [1.7.1] - 2026-05-04

### Fixed
- **Destructive actions now require confirmation** — "Butta tutto" (`throwAll`) and "Finisci tutto" (`submitUseAll`) now display a confirmation modal before executing. The modal features a 5-second auto-confirm countdown bar (red) with an "Annulla" cancel button, matching the scale auto-confirm UX pattern already in use.
- **History undo button visibility** — The ↩ undo button in the transaction log was using `color: var(--text-muted)` making it nearly invisible. It now uses a red tint background + border (`#f87171`) with larger font size (1rem) for easy tap targeting.
- **History undo uses custom modal** — `undoTransactionEntry()` previously used the native browser `confirm()` dialog (broken in Android WebView kiosk mode). It now uses the same `_showDestructiveConfirm()` modal with countdown.



### Added
- **Demo mode (JS frontend)** — Full client-side demo experience: Gemini is treated as available, Bring! write operations silently no-op, and a mock pantry + shopping list is shown; activated via `?demo=1` URL param or `.env` `DEMO_MODE=true`; a "DEMO" badge is injected in the header and Settings is hidden to prevent accidental writes
- **Graceful Bring! no-key state** — When Bring! credentials are not configured the shopping tab shows a friendly localised message with a direct link to the Settings page instead of a raw API error
- **Use-quantity guard** — Consuming more than the quantity stocked at the selected location is now blocked before the API call; the quantity input shakes (CSS `input-shake` animation) and a toast shows `use.error_exceeds_stock`
- **Kiosk: smart auto-discovery rewrite** — `autoDiscover()` now uses `ExecutorCompletionService` + `NetworkInterface` (replaces deprecated `WifiManager`), 60 parallel threads, 600 ms TCP pre-check per host, real-time UI feedback every 120 ms, ports `[443, 80, 8080, 8443]`; VPN/cellular interfaces (tun, ppp, rmnet, pdp, ccmni, etc.) are filtered out and `wlan*`/`eth*` interfaces are prioritised
- **Kiosk: permissions button transform** — After permissions are granted, the button changes to "✅ Permessi concessi — Continua →" (green background, dark text) and advances to step 3 on tap, replacing the separate "permissions granted" card
- **Kiosk: gateway auto-pre-configuration** — On successful gateway install `finishSetup()` POSTs `scale_enabled=true` + `scale_gateway_url=ws://127.0.0.1:8765` to the server's `save_settings` endpoint so the webapp is scale-ready immediately after setup
- **Kiosk: ErrorReporter init at setup start** — `SetupActivity.onCreate()` now calls `ErrorReporter.init()` with any previously saved URL, ensuring errors in step 4 (gateway install) are reported even before the user confirms the server URL

### Fixed
- **Kiosk: wrong subnet scanned** — The previous implementation picked up VPN/tun interfaces and scanned a 10.x.x.x range instead of the device's actual Wi-Fi LAN; fixed by filtering interface names and preferring `wlan`/`eth`
- **Kiosk: port 443 missing from discovery** — HTTPS servers were never reachable during auto-discovery; ports list extended to `[443, 80, 8080, 8443]`
- **Kiosk: gateway install status=1 silent failure** — `PackageInstaller.STATUS_FAILURE` (status 1) showed an error card but never called `ErrorReporter`; `ErrorReporter.reportMessage()` is now called with status code, message, and package name
- **Screensaver toggle in web settings** — The screensaver row was missing a `<span class="toggle-slider">` inside the `<span class="toggle-switch">` wrapper, so no slider was rendered; corrected to use the same `toggle-row` / `toggle-switch` / `toggle-slider` structure as all other settings toggles
- **antiwaste.title translation** — IT and DE locale files were missing the `antiwaste.title` key, causing a raw key string to appear in the anti-waste section header; added to both `it.json` and `de.json`

### Kiosk (v1.4.0 → v1.5.0)
- `autoDiscover()` fully rewritten (CompletionService, NetworkInterface, TCP pre-check, real-time feedback, correct LAN subnet)
- Port 443 added to discovery scan
- Permissions button transforms after grant (`onPermissionsGranted()`)
- `ErrorReporter.init()` called at `SetupActivity.onCreate()`
- `ErrorReporter.reportMessage()` called on gateway install failure
- `finishSetup()` pre-configures gateway via `save_settings` API call

## [1.6.0] - 2026-05-03

### Added
- **Dashboard skeleton loading** — Stat cards (Dispensa / Frigo / Freezer) show an animated shimmer placeholder (`…`) instead of the jarring `0` flash that appeared for 3–5 seconds before data loaded; the loading class is applied before the API call and removed atomically when data arrives
- **Webapp startup preloader** — Full-screen spinner overlay during initial app load, fades out after the dashboard is ready
- **Webapp update notification** — A dismissible top banner alerts the user when a newer GitHub release is available (checked once every 6 hours, comparison based on `published_at`)
- **Native Android update banners** — Both Kiosk (v1.4.0) and Scale Gateway (v2.1.0) show a native top bar when a newer APK is available, with one-tap download and install

### Fixed
- **APK install conflict** — Replaced `ACTION_VIEW`-based APK install with the `PackageInstaller.Session` API (API 21+) in both Kiosk and Scale Gateway; the session-based approach correctly handles:
  - `STATUS_PENDING_USER_ACTION` → automatically launches the system confirmation dialog
  - `STATUS_SUCCESS` → success toast
  - `STATUS_FAILURE_CONFLICT` / `STATUS_FAILURE_INCOMPATIBLE` → `AlertDialog` offering to uninstall the old app (signature mismatch) before reinstalling
- **Cooking mode z-index** — Update banner and app header are now hidden when `body.cooking-mode-active` is set, and the cooking overlay z-index was raised to `99998` so it can no longer be obscured by UI chrome
- **Version-aware error reporting** — GitHub Issues are only created when the client is running the latest released version, avoiding noise from stale deployments; non-semver tag names (e.g. `"latest"`) are treated as "always up-to-date"
- **XOR-obfuscated GitHub token** — The PAT used for GitHub API calls is stored as an XOR-encoded hex string in both the PHP backend and Kotlin apps to prevent accidental exposure via secret scanning

### Kiosk (v1.3.0 → v1.4.0)
- FileProvider + `REQUEST_INSTALL_PACKAGES` permission added
- APK download destination moved to `getExternalFilesDir(null)` (no storage permission needed)
- `PackageInstaller` self-update with signature-conflict recovery
- BLE scale gateway update banner with download + install flow

### Scale Gateway (v2.0.0 → v2.1.0)
- Same FileProvider + permission + `PackageInstaller` changes as Kiosk
- Update banner for self-update
- CI workflow now triggers on `develop` branch (in addition to `main`)

## [Unreleased] - 2026-04-30

### Fixed
- **Low-qty banner false positive** — A "suspiciously low quantity" review alert is now suppressed for a partially-used inventory entry when one or more sibling entries for the same product (identified by barcode, or name+brand as fallback) exist in other locations with stock > 0. Prevents noise like "191 ml of milk" when 11 sealed packages are stored in the pantry.

### Changed
- **Non-alarmist expired banner** — Banner icon, CSS class, and title suffix now adapt to the `getExpiredSafety()` level:
  - `ok` (long-life products, freezer within margin): green banner, ✅ icon, "— Scaduto (ancora ok)"
  - `warning` (items that should be inspected): amber/yellow banner, 👀 icon, "— Scaduto (controlla)"
  - `danger` (raw meat, dairy, fish, etc.): unchanged red 🚫 banner and "— Scaduto!" title
- Added `expiry.expired_suffix_ok` and `expiry.expired_suffix_warning` i18n keys to all three language files (IT/EN/DE)
- Added `banner-expired-ok` and `banner-expired-warning` CSS variants (green / amber) in `style.css`

## [1.5.0] - 2026-04-28

### Added
- **Expired banner for opened products** — Products whose opened-product shelf-life has passed (e.g. fridge cream opened 6 days ago) now appear in the top notification banner, not just the dashboard list
- **Safety-aware expired banner** — Each expired banner item shows a contextual safety tip (from `getExpiredSafety()`); danger-level items (fridge dairy/meat/fish) get an intense red banner and "L'ho buttato" as the primary button; safe/warning items keep the original button order
- **AI model fallback** — All Gemini API endpoints (expiry scan, product identification, chat, recipe non-streaming, shopping name classifier) now try `gemini-2.5-flash` first and fall back to `gemini-2.0-flash` automatically, matching the resilience already in place for recipe streaming
- **Friendly AI quota message** — When the AI returns a quota/rate-limit error the user sees "Quota AI esaurita. Riprova tra qualche minuto." instead of the raw API error string
- **Cooking TTS auto-read** — Each recipe step is read aloud automatically when navigating forward or backward; the first step is also read when entering cooking mode
- **Cooking timer 10-second warning** — When a cooking timer reaches 10 seconds the TTS announces "Attenzione! [label]: mancano 10 secondi!"
- **Cooking recipe completion announcement** — "Ricetta completata! Buon appetito!" is spoken via TTS when the last step is confirmed

### Fixed
- **Cooking TTS gate** — `speakCookingStep()` was blocked by the global `tts_enabled` setting; the `_cookingTTS` toggle (🔊/🔇 button) is now the only gate; browser Web Speech API is used by default without requiring TTS configuration in Settings
- **Anomaly dismiss label** — The "La quantità è giusta" button now appends the current inventory quantity, e.g. "La quantità è giusta (2 pz)", so the action is unambiguous
- **i18n sync** — Added `timer_warning_tts`, `recipe_done_tts`, `error.ai_quota` keys to all three language files (IT/EN/DE)


### Added
- **Generic shopping names** — Products are grouped by type ("Latte", "Affettato", "Pasta") rather than brand; computed via an expanded keyword map with Google Gemini AI as fallback for unknown products
- **Bring! auto-migration** — Existing list items with old specific names are silently migrated to generic names on every list load, throttled to once per 10 minutes
- **Bring! catalog coverage** — All 93 shopping_name values now resolve to a German Bring! catalog key (icons and categories in the Bring! app); 24 aliases added to cover previously unmatched names
- **Auto-add to Bring! on depletion** — When a product reaches zero the app adds it to Bring! automatically using the generic shopping name, with the specific product name and brand in the specification field
- **Finished-product confirmation banner** — Instead of silently deleting zero-stock entries, a banner prompts the user to confirm; banner title includes the last 3 digits of the product barcode for easier identification
- **Anomaly detection banner** — Dashboard notifications for suspicious inventory/transaction mismatches and consumption prediction errors, with one-tap inline correction
- **SSE recipe streaming** — Recipe generation streams live via Server-Sent Events; Gemini agent feedback is shown in real time as it is generated
- **Smart alert banners** — Configurable expired-only mode with explanatory messages; banner buttons are fully internationalized

### Fixed
- **Scale double-deduction** — Multiple BLE stable readings of the same weight no longer fire duplicate `inventory_use` events; JS preserves the confirmation sentinel on submit and PHP rejects a second `out` transaction for the same product within 12 seconds
- **Kiosk native TTS** — CI workflow now builds the APK on `develop` branch too; the native Android `TextToSpeech` bridge bypasses Web Speech API voice-availability issues without requiring offline voice packs
- **TTS voice loading** — Retries for up to 10 seconds on page load; shows a message if no voices are available and offers a manual refresh button
- **Bring! migration** — Corrected two bugs: wrong removal API (`DELETE /item` → `PUT remove=item`) and wrong purchase key sent to Bring! (Italian shopping name → German catalog key), which previously created Italian/German duplicate entries
- **Gemini 429 rate limiting** — API calls are retried with exponential backoff; recipe requests are capped at 5 per minute with a dedicated rate-limit bucket

### Performance
- **Gemini calls centralized** — All Gemini API requests go through a single `callGemini()` helper with intelligent backoff; Gemini removed from the product-selection and bringSuggest flows in favour of fast offline logic

## [1.3.0] - 2026-04-18

### Added
- **Expired product banner** — Dashboard notifications for expired products with use, throw away, edit, and dismiss actions
- **Expiring soon banner** — Dashboard notifications for products expiring within 3 days with use, edit, and dismiss actions
- **Priority-sorted notifications** — Banner alerts sorted by urgency: expired > expiring > suspicious quantities > consumption predictions
- **Swipe navigation** — Touch swipe left/right to browse banner notifications, with dot indicators and arrow buttons
- **Quick-access buttons** — Inventory page shows 4 recently used and up to 8 most popular products for quick selection
- **Recent & popular products API** — New `recent_popular_products` endpoint
- **Auto-refresh** — Banner notifications refresh every 5 minutes while on the dashboard
- **Edit from expiry banner** — Correct expiry dates directly from expired/expiring notifications

### Fixed
- **Negative scale values** — BLE scale readings with negative weight are now ignored
- **Banner re-appearing after edit** — Editing from a banner now persists the confirmation so it doesn't reappear on dashboard reload
- **False consumption predictions** — Manual inventory edits (updated_at > last restock) now use the correct baseline for prediction calculations
- **Kiosk overlay blocking header** — Removed injected exit/refresh buttons from the web app header in kiosk mode

## [1.2.0] - 2026-04-13

### Changed
- **Project renamed** from "Dispensa Manager" to **EverShelf**
- Contact email updated to `evershelfproject@gmail.com`
- Docker service, container, and volume renamed to `evershelf`
- SQLite database renamed from `dispensa.db` to `evershelf.db`
- All localStorage keys migrated: `dispensa_*` → `evershelf_*`
- Apache config file renamed to `evershelf.conf`
- CI workflow Docker image/container names updated
- App name updated in all translations (it, en, de)
- Navigation title updated to EverShelf across all languages

### Added
- Version badge (`v1.2.0`) in the app header

### Fixed
- JS file truncation caused by `sed` in-place edit on large files
- Browser cache invalidation via bumped asset version strings (`?v=20260413a`)

## [1.0.0] - 2026-04-10

### Added
- Complete pantry inventory management (Pantry, Fridge, Freezer, Other)
- Barcode scanning with QuaggaJS
- Open Food Facts barcode lookup
- Google Gemini AI integration (product identification, expiry reading, recipes, chat)
- Bring! shopping list integration
- Smart shopping predictions with cron-based caching
- Cooking mode with step-by-step guidance and TTS support
- Opened product tracking with reduced shelf-life calculation
- Vacuum-sealed product support with extended expiry
- Waste vs. consumption tracking (30-day chart)
- Expired product safety assessment by category
- Weekly meal plan configuration
- DupliClick online grocery ordering integration
- PWA support (installable, mobile-first)
- Local database backup script
- Multi-device settings sync via SQLite

### Security
- Centralized `.env` configuration (secrets never in code)
- Removed all hardcoded credentials and personal data
- Input validation on inventory operations
- Parameterized SQL queries throughout
