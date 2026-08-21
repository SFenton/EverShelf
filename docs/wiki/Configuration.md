# ⚙️ Configuration

EverShelf is configured via a `.env` file in the project root. Copy `.env.example` to `.env` and edit it — the app reads this file on every API call.

**Never commit `.env` to Git.** It is already in `.gitignore`.

---

## Full `.env` Reference

```ini
# ─────────────────────────────────────────────
# AI — Google Gemini
# ─────────────────────────────────────────────

# Your Gemini API key (required for all AI features)
# Get one free at: https://aistudio.google.com/app/apikey
GEMINI_API_KEY=

# ─────────────────────────────────────────────
# Shopping List — Bring! Integration
# ─────────────────────────────────────────────

# Your Bring! account credentials
# Leave blank to disable Bring! integration
BRING_EMAIL=
BRING_PASSWORD=

# ─────────────────────────────────────────────
# Recipe catalog queue
# ─────────────────────────────────────────────

RECIPE_QUEUE_CRON_LIMIT=10
# Use a low explicit limit (for example --limit=1) when manually draining full crawls.
RECIPE_QUEUE_CLI_LIMIT=50
RECIPE_QUEUE_MAX_ATTEMPTS=3
RECIPE_QUEUE_LEASE_MINUTES=15

# ─────────────────────────────────────────────
# Cookidoo metadata connector
# ───────────────────────────

# Cookidoo email/password belong ONLY in cookidoo-bridge/.env.
# These compatibility gates cannot override the current production policy.
COOKIDOO_CONNECTOR_ENABLED=false
COOKIDOO_DETAIL_HYDRATION_ENABLED=false
COOKIDOO_BRIDGE_URL=http://cookidoo-bridge:8081
COOKIDOO_BRIDGE_TOKEN=
COOKIDOO_BRIDGE_TIMEOUT_SECONDS=50
COOKIDOO_RESULT_LIMIT=20
COOKIDOO_METADATA_REFRESH_DAYS=14
COOKIDOO_METADATA_BACKFILL_ENABLED=false
COOKIDOO_METADATA_BACKFILL_BATCH_SIZE=20
COOKIDOO_METADATA_BACKFILL_INTERVAL_SECONDS=120
COOKIDOO_METADATA_BACKFILL_JITTER_SECONDS=20
COOKIDOO_QUEUE_CADENCE_MINUTES=1
# Language-only discovery values are allowed; stored recipes use the selected effective locale.
COOKIDOO_DISCOVERY_LOCALE=en-US
COOKIDOO_PERIODIC_REFRESH_ENABLED=false
COOKIDOO_REFRESH_ENQUEUE_LIMIT=2

# ─────────────────────────────────────────────
# Text-to-Speech (for Cooking Mode)
# ─────────────────────────────────────────────

# URL to a TTS endpoint (e.g. Home Assistant event endpoint)
TTS_URL=

# Bearer token for the TTS endpoint
TTS_TOKEN=

# Set to true to enable server-side TTS (the browser Web Speech API is always used as fallback)
TTS_ENABLED=false

# ─────────────────────────────────────────────
# Security
# ─────────────────────────────────────────────

# Protect the save_settings endpoint with a token
# If set, the Settings UI will prompt for this value before saving
# Validated with hash_equals() to prevent timing attacks
SETTINGS_TOKEN=

# ─────────────────────────────────────────────
# Demo / Public Mode
# ─────────────────────────────────────────────

# Set to true to block ALL write operations at the PHP router level
# Useful for public demos or read-only kiosk deployments
# Also activatable per-request via ?demo=1 URL parameter
DEMO_MODE=false

# ─────────────────────────────────────────────
# Scale Gateway
# ─────────────────────────────────────────────

# Enable the BLE scale integration
SCALE_ENABLED=false

# WebSocket URL of the Scale Gateway app running on the same device
# Default for Android kiosk: ws://127.0.0.1:8765
SCALE_GATEWAY_URL=ws://127.0.0.1:8765
```

---

## Settings UI

Most settings can also be configured from the browser via **Settings → ⚙️**:

| Setting | `.env` key | Notes |
|---------|-----------|-------|
| Gemini API key | `GEMINI_API_KEY` | Stored server-side, never exposed to browser |
| Bring! email | `BRING_EMAIL` | — |
| Bring! password | `BRING_PASSWORD` | — |
| TTS URL | `TTS_URL` | — |
| TTS token | `TTS_TOKEN` | — |
| TTS enabled | `TTS_ENABLED` | — |
| Scale enabled | `SCALE_ENABLED` | — |
| Scale gateway URL | `SCALE_GATEWAY_URL` | — |
| Settings token | `SETTINGS_TOKEN` | Write-only; current value never shown |

> **Security note:** `get_settings` returns only **boolean flags** (`gemini_key_set: true/false`), never raw key values. Raw values are only accessible server-side.

---

## Cookidoo Metadata Bridge

The provider-facing bridge remains default-off. To enable metadata discovery and
direct refresh, set `COOKIDOO_CONNECTOR_ENABLED=true` and
`COOKIDOO_DETAIL_HYDRATION_ENABLED=true` in EverShelf and set the matching
`COOKIDOO_DETAIL_HYDRATION_ENABLED=true` bridge flag. Provider responses may
co-transport official steps, but only bounded allowlisted factual metadata crosses
the bridge API boundary; instructions are never logged, returned, or persisted.
Existing cached catalog rows remain readable while either gate is disabled.
The app verifies bridge capability policy `metadata-v3-operator-enabled` before
any provider request; persisted factual metadata remains `metadata-v2`. Cached
rows remain searchable after either freshness deadline and enqueue bounded
best-effort refresh on reads without requiring the bulk-backfill gate.
Known superseded Cookidoo discovery policy markers and valid pre-policy
discovery payloads are re-stamped once during schema migration. Unknown policy
identifiers remain untouched and ineligible.

`RECIPE_COOKIDOO_THUMBNAIL_REWRITE=true` returns the verified smaller named Cookidoo
CDN transform alongside the original URL. Disable it to use only the original;
clients retain the original URL as an image-error fallback.

`RECIPE_SCORE_SYNC_BOOTSTRAP_LIMIT=250` controls whether a small catalog may build
its first score revision during a request. Larger catalogs return a temporary 503
until `scripts/rebuild-recipe-scores.php` (installed in the cron image) activates
the revision.

`RECIPE_SCORE_PREVIEW_REVISION_ID=` is a development/testing-only read override
and is refused unless `EVERSHELF_ENV` normalizes to `development` or `test`.
The default blank value (or `0`) reads the true active score revision. A positive
value must name one explicit ready, regular `faceted-ontology-v3` revision whose
ontology, source revisions, exact ID-set seals, and materialization hashes remain
valid. Search, suggestions, recommendations, details, and their cursors then read
that revision without activating it. Invalid or stale configuration fails closed
to the active revision and returns bounded preview diagnostics. It never selects
the latest revision and must remain blank in production. Grocery-add and all
other mutations always evaluate against the true active revision, never preview.
Any owner/source identity change advances a monotonic source revision, clears
its sealed source hash, and invalidates preview until a new shadow is built.

### Ingredient ontology v3

```env
TAXONOMY_AI_REVIEW=false
INGREDIENT_ONTOLOGY_V3_ENABLED=false
INGREDIENT_ONTOLOGY_V3_PROPOSAL_MODEL=gemini-3.5-flash
INGREDIENT_ONTOLOGY_V3_PROMPT_MAX_ITEMS=50
INGREDIENT_ONTOLOGY_V3_RAW_JSON_MAX_BYTES=65536
INGREDIENT_ONTOLOGY_V3_QUANTITY_SUFFICIENCY_GATE=false
RECIPE_SCORE_PREVIEW_REVISION_ID=

INGREDIENT_ONTOLOGY_CONTROLLER_ENABLED=false
INGREDIENT_ONTOLOGY_CONTROLLER_MODEL_ENABLED=false
INGREDIENT_ONTOLOGY_CONTROLLER_PROMOTION_ENABLED=false
INGREDIENT_ONTOLOGY_CONTROLLER_POLL_MS=250
INGREDIENT_ONTOLOGY_CONTROLLER_LOW_SIGNAL_SHORTCUT_ENABLED=false
INGREDIENT_ONTOLOGY_CONTROLLER_LEASE_SECONDS=600
INGREDIENT_ONTOLOGY_CONTROLLER_CRON_LIMIT=10
INGREDIENT_ONTOLOGY_CONTROLLER_MINIMUM_PRIORITY=50
INGREDIENT_ONTOLOGY_CONTROLLER_CANDIDATE_LIMIT=64
INGREDIENT_ONTOLOGY_CONTROLLER_GENERATION_QUIET_SECONDS=300
INGREDIENT_ONTOLOGY_CONTROLLER_GENERATION_MAXIMUM_LATENCY_SECONDS=1800
INGREDIENT_ONTOLOGY_CONTROLLER_GOOGLE_API_KEY=
INGREDIENT_ONTOLOGY_CONTROLLER_GOOGLE_MODEL=gemini-3.7-flash
INGREDIENT_ONTOLOGY_CONTROLLER_GOOGLE_THINKING_LEVEL=medium
INGREDIENT_ONTOLOGY_CONTROLLER_WAKE_SOCKET=
INGREDIENT_ONTOLOGY_CONTROLLER_MODEL_ROSTER_JSON=
```

Keep `INGREDIENT_ONTOLOGY_CONTROLLER_LOW_SIGNAL_SHORTCUT_ENABLED=false`
to allow one bounded semantic review call for zero-overlap, cross-language
subjects. Set it to `true` only to opt into immediate coverage-gap recording
without a model call when autonomous creation is unavailable.

`TAXONOMY_AI_REVIEW` is retained only as a legacy v2 compatibility signal and is
observation-only; it can no longer call a
model or write unversioned nodes/edges. A regular ontology v3 revision may be
manually activated only after the
CLI passes the frozen production-corpus and subject-set, graph, pinned matcher
and resolution gold, score/materialization, unchanged-input, integrity, and
approved-change-set gates. Requirement/source-aware revisions remain
shadow-only. The CLI itself never
calls Gemini: it emits a bounded fenced prompt and imports Copilot-produced JSON
into staging tables. Gemini 3.5 Flash is the frozen proposal default; model
`is_defining` output is ignored in favor of closed deterministic facet semantics.
Quantity aggregation is always complete across compatible rows. The optional
quantity-sufficiency enforcement gate defaults off, so low or unknown quantity
does not convert a valid identity match into a missing ingredient unless enabled.
The gate participates in each v3 revision's deterministic
`scoring_config_hash`; toggling it makes existing v3 scores stale and prevents
reuse, activation, or rollback of scores built under the other setting.

Canonical enrichment is asynchronous and wake-driven in Docker:

```dotenv
CANONICAL_QUEUE_WORKER_LIMIT=5
CANONICAL_QUEUE_WORKER_MAX_BATCHES=100
CANONICAL_QUEUE_MAX_ATTEMPTS=3
CANONICAL_QUEUE_CRASH_LEASE_SECONDS=120
CANONICAL_QUEUE_APPLY_RESERVE_SECONDS=30
CANONICAL_QUEUE_BUSY_TIMEOUT_MS=250
CANONICAL_QUEUE_APPLY_RETRY_SECONDS=20
CANONICAL_QUEUE_RELEASE_RETRY_SECONDS=45
CANONICAL_QUEUE_STALE_DUE_SECONDS=300
CANONICAL_QUEUE_LOCK_WARNING_INTERVAL_SECONDS=60
CANONICAL_QUEUE_WAKE_SOCKET=/var/www/html/data/canonical-queue.sock
CANONICAL_QUEUE_SAFETY_POLL_SECONDS=30
FOODON_HIERARCHY_FAILURE_CACHE_TTL_SECONDS=900
```

Product commits send a nonblocking local datagram after commit. The resident
worker computes enrichment without a database transaction, then applies
canonical rows, queue completion, and taxonomy-score enqueue in one short
fingerprint- and lease-fenced transaction. SQLite contention retries the
already-computed result. With the default three total executions, failures
retry after 2 and 8 seconds and then terminate; a 30-second third backoff is
used only when `CANONICAL_QUEUE_MAX_ATTEMPTS` is at least four. Provider work
has a deadline derived from the crash lease minus the apply reserve, so slow
healthy enrichment is explicitly requeued instead of expiring its lease.
Only a true process crash waits for the 120-second lease. The deprecated
`CANONICAL_QUEUE_LEASE_SECONDS` is honored only for non-600 legacy values;
deliberate 600-second configuration now uses
`CANONICAL_QUEUE_CRASH_LEASE_SECONDS`. The five-minute
smart-shopping cron remains a dropped-wake/restart fallback.
FoodOn hierarchy failures use the short seconds-based TTL; positive and
definitive no-match entries retain the day-based cache TTL. Provider cache
publication is best-effort and never waits behind another publisher; a valid
result remains resident when persistence is busy or unavailable.
Non-Docker installations should run exactly one
`scripts/canonical-queue-worker.php --db=/path/to/evershelf.db
--allow-active-db --loop` process.

The autonomous controller records immutable ingestion subjects only when its
live-ingestion gate is enabled; explicit correction constraints remain
transactional. It performs no model call or automatic promotion unless the
controller model/promotion gates are enabled. Its Google key is separate from both
`GEMINI_API_KEY` and the legacy proposal key. Candidate limits support benchmark
rungs `64|96|128|277|500`; 64 is the default. Provider/model selection is
versioned data and has no silent fallback. Prompts include the bounded pool
total, offset, remaining count and truthful expansion permission. Complete
exhaustion and policy truncation create non-satisfying coverage-gap evidence;
they never create accepted identity.

`ONTOLOGY_AUTONOMOUS_ENABLED=false` is the default live-ingestion gate.
Disabled product/recipe hooks perform no controller write. Enabled hooks run in
savepoints: a controller failure is logged and returned as degradation while the
ordinary product or recipe save still commits. The older
`INGREDIENT_ONTOLOGY_CONTROLLER_ENABLED` name remains a compatibility fallback
when the new gate is absent.

Production is always intake/model-evidence-only. The active database rejects
`--copy-generation`, generation finalization, shadowing, and promotion even if
`--allow-active-db` or the promotion feature gate is enabled. Candidate work
must run against a copied database.

Copied-database generations are immutable children of the copied active
ontology. Plans are
collected for five quiet minutes with a bounded thirty-minute maximum latency,
then shadow-scored and checked
against exact constraints, blast limits, immutable gold, integrity, and
materialized-value parity, then the copied pointer may be promoted with a
compare-and-swap update for validation. A failed, abstained, or quarantined
subject receives a unique
non-satisfying provisional leaf and durable retry rather than being dropped.
Copied-database controller forks may use persistent keyset progress and
conservative row chunks. Intake-only production responses are stored as durable
generation intents and later rebound inside the copy by portable entity slug
without another proposer call.
Live product observations enqueue subject resolution at priority `100`, and
live recipe ingestion uses priority `50`; both refresh queued/retry work and
safely revive terminal jobs with fresh immutable input and lease fences.
Historical backfill remains priority `0`. Production cron and the dedicated
live worker use `INGREDIENT_ONTOLOGY_CONTROLLER_MINIMUM_PRIORITY=50`, so
historical work is retained but not drained:

```bash
php scripts/ontology-controller.php work \
  --db=/path/to/evershelf.db --write --allow-active-db --loop \
  --minimum-priority=50 --allow-network
```

Copy the database safely, then consume and coalesce the stored intents, build
one candidate and complete shadow, and export a sealed manifest plus immutable
SQLite sidecars:

```bash
php scripts/ontology-controller.php bundle-build \
  --db=/path/to/evershelf-copy.sqlite --write \
  --out=/path/to/activation-bundle.json \
  --payload-dir=/path/to/activation-payloads
```

Set `ONTOLOGY_ACTIVATION_ENABLED=true` to let
`scripts/process-ontology-activation.php` run this workflow automatically.
Copied generation, scoring, and validation run without the shared
background-writer lock. The worker takes that lock nonblocking only for bounded
live import, reservation, and publication phases, yielding between phases. It
validates the imported candidate on a fresh database copy, then publishes
through one short score-pointer CAS. Inventory-only drift rebuilds only the
score sidecar; source, policy, or constraint drift rebases from a fresh copy
without another proposer call. Imported intents are acknowledged only in the
same transaction that activates their score revision.

Activation-only SQLite connections use file-backed temporary storage so large
ordered verification queries stay within the worker memory limit. Generated
bundle manifests and copied-validation attestations are written atomically in
the activation directory; a later worker invocation revalidates their hashes,
payloads, lineage, and publication fences before resuming.

`ONTOLOGY_ACTIVATION_DIRECTORY` must point inside durable storage with enough
space for a database copy plus sidecars. `RECIPE_SCORE_PRUNE_CHUNK_ROWS` and
`RECIPE_SCORE_PRUNE_MAX_CHUNKS` bound obsolete materialization cleanup. The
active database remains intake-only: fork, generation, shadow scoring, and full
semantic validation still run only on copies.

Live import chunks have a 250 ms alert budget and final publication has a
100 ms alert budget. They are measured and persisted after commit rather than
treated as hard transaction deadlines because SQLite commit latency cannot be
predicted before issuing the commit.

The repository Compose worker must be configured as intake-only before it is
started against `evershelf.db`. Any command retaining `--copy-generation`,
`--run-generation`, `--promote`, or `--allow-active-generation` now fails
closed. The worker runs as its own container rather than through a host
`docker exec` service, so stop/restart signals cannot orphan a second
controller process.

An offline copied-database historical batch may opt in later with
`--minimum-priority=0`.

`INGREDIENT_ONTOLOGY_CONTROLLER_CRITIC_PROVIDER` and
`INGREDIENT_ONTOLOGY_CONTROLLER_CRITIC_MODEL` select the separately persisted P7
critic lane; blank values fail closed for generalized mutations. Measured model
policies are imported on a copied database with
`scripts/ontology-controller.php benchmark-import --file=... --write --activate`.
Policy documents are immutable, risk-specific benchmark evidence; disagreement
abstains unless that measured policy explicitly authorizes adjudication.

For host-authenticated models without a Docker Google key:

```dotenv
INGREDIENT_ONTOLOGY_CONTROLLER_PROVIDER=copilot_socket
INGREDIENT_ONTOLOGY_CONTROLLER_PROPOSER_MODEL=gemini-3.7-flash
INGREDIENT_ONTOLOGY_CONTROLLER_CRITIC_PROVIDER=copilot_socket
INGREDIENT_ONTOLOGY_CONTROLLER_CRITIC_MODEL=claude-sonnet-5
INGREDIENT_ONTOLOGY_CONTROLLER_COPILOT_SOCKET=/run/evershelf-ontology/copilot.sock
INGREDIENT_ONTOLOGY_CONTROLLER_COPILOT_TIMEOUT_SECONDS=90
```

The socket service accepts only the versioned Gemini 3.7 Flash, Claude Sonnet
5, GPT-5.6 Terra, and Claude Opus 5 roster with fixed roles/effort. It has no
silent fallback and must be installed separately from the sample
`docs/evershelf-ontology-copilot.service`. Quarantine retains subject coverage
through non-satisfying provisional leaves and bounded retry/circuit state.

Install the sample explicitly as the EverShelf host user, not as a system
service:

```bash
mkdir -p ~/.config/systemd/user
cp docs/evershelf-ontology-copilot.service \
  ~/.config/systemd/user/evershelf-ontology-copilot.service
systemctl --user daemon-reload
```

The user unit creates
`$XDG_RUNTIME_DIR/evershelf-ontology/copilot.sock`. Compose bind-mounts that
directory read-only at `/run/evershelf-ontology`, matching the PHP path above.
Before Compose deployment, export
`EVERSHELF_ONTOLOGY_SOCKET_DIR=$XDG_RUNTIME_DIR/evershelf-ontology` and set
`EVERSHELF_ONTOLOGY_SOCKET_GID` to the host user's numeric primary group
(`1000` in the sample). The host directory is `0750`, socket `0660`, and the
container joins only that supplementary group; neither path is world-writable.
The unit intentionally allows AF_INET/AF_INET6 for Copilot HTTPS and writable
`~/.copilot`/`~/.cache/copilot` state, while retaining a read-only home and
other sandboxing. Node/V8 requires executable memory, so
`MemoryDenyWriteExecute` is intentionally not used.

Large prompts and the exact strict JSON schema are written to a mode-`0600`
request attachment inside the provider state directory; only a tiny immutable
instruction and attachment path appear in argv. Gemini 3.7 Flash omits the
unsupported effort flag. Request files are deleted after each invocation, and
server error codes remain visible to PHP without model fallback.

Copy-only candidate builds require an explicit frozen corpus profile:
`--corpus-profile=eval` for the 174-product/402,284-ingredient corpus with zero
provider source rows, or `--corpus-profile=provider` for the same base plus
3,100 source rows and exactly 646 provider terms. Builds and activation compare
the recomputed profile hash, counts, reviewed product/provider sets, pinned
matcher fixture hash, ordered case IDs, policy/reason, and full materialization
seals. There is no generic zero-provider or latest-profile fallback.

The bridge exposes no host port and stores session cookies with mode `0600` in a
dedicated Docker volume. Policy `metadata-v2` allows only title, remote image and
canonical URLs/ID, locale/timestamps, factual yield/unit, active/total seconds,
difficulty, one category label, equipment nouns, and ordered ingredient names with
bounded display-only exact/range/unit/amount facts. Bounded factual topology includes
short group titles/ordinals, provider ingredient/default-title/unit references,
provider-declared optional booleans, and shopping-category references. Source
amounts are isolated from ranking quantity/unit columns and never affect coverage,
cookability, or internal shopping quantity.

Official Cookidoo instructions are always external-only. `recipeStepGroups`, provider
notes/tips, category/collection descriptions, nutrition, tags, preparation prose,
Guided Cooking data, image bytes, and raw payloads are excluded. Remote image bytes
are not proxied or stored; displaying the image contacts Cookidoo's image host.
Ingredient `preparation` is never accessed.

Automatic taxonomy discovery, bounded crawls, periodic refresh, and crawl seeding
run only when connector and detail hydration gates are enabled. Existing
disabled-gate jobs remain pending without connector failure or circuit accounting.

### Direct-ID metadata-v2 backfill

Direct-ID backfill requires connector/detail hydration plus
`COOKIDOO_METADATA_BACKFILL_ENABLED=true`. Scan-triggered discovery requires the
connector and detail hydration gates but not the backfill gate. Existing cached
recipes remain readable while network hydration is disabled. Existing v3-policy
jobs remain pending while gates are off. Only known superseded or historically
absent discovery policy values are migrated; unknown values fail closed. Recipe
queue processes coordinate through an expiring database singleton lease, so a
second cron/manual invocation skips without holding SQLite or flock across
provider traffic.

### FoodOn exact-identity audit

Audit copied databases without mutation:

```bash
php scripts/audit-foodon-hierarchy-identity.php --db=copy.sqlite
```

`--write` is accepted only for a copied database and requeues affected products
through exact-self admission; it never materializes FoodOn semantic parents.

---

## Protecting Settings with a Token

If your EverShelf instance is accessible from untrusted networks, set `SETTINGS_TOKEN` to a strong random string:

```bash
# Generate a strong token
openssl rand -hex 32
```

```ini
SETTINGS_TOKEN=a3f9b2c1d4e5...
```

Users will be prompted for this token before any Settings save. If the token doesn't match, the request is rejected with HTTP 403.

---

## Demo Mode

Two ways to enable demo mode:

1. **Permanent:** Set `DEMO_MODE=true` in `.env`
2. **Per-session:** Append `?demo=1` to any URL (e.g. `https://evershelfproject.dadaloop.it/demo`)

In demo mode:
- All POST/write API calls return success without touching the database
- A "DEMO" badge appears in the header
- Gemini AI is treated as available (mock responses)
- Bring! write operations are silently no-op'd
- A mock pantry with sample data is loaded

---

## API Rate Limiting

EverShelf applies file-based rate limiting to protect AI endpoints:

| Tier | Limit | Endpoints |
|------|-------|-----------|
| Standard | 120 req/min | General endpoints |
| Category refinement | 120 req/min | `guess_category` |
| AI | 15 req/min | Gemini, OCR, and AI suggestion endpoints |
| Price | 60 req/min | Shopping price lookups |
| Recipe generation | 5 req/min | `generate_recipe`, `generate_recipe_stream` |
| Recipe refresh | 10 req/min | Recipe catalog refresh and discovery |
| Recipe catalog | 60 req/min | Recipe catalog read/write endpoints |
| Error reporting | 20 req/min | `report_error`, `check_update` |

Concurrent requests for the same client and bucket are serialized before the
window is checked and updated.

Rate limit state is stored in `data/rate_limits/`. The directory must be
writable and is treated as startup-critical. To reset, delete the files in that
directory.

---

## Database

EverShelf uses **SQLite** stored at `data/evershelf.db`. The file is created automatically on first run.

Schema migrations run automatically whenever `database.php` is loaded — no manual migration steps needed.

To back up the database:

```bash
cp data/evershelf.db data/backups/evershelf-$(date +%Y%m%d).db
```

Or use the included `backup.sh`:

```bash
./backup.sh
```
