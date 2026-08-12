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
# Experimental Cookidoo metadata connector
# ─────────────────────────────────────────────

# Cookidoo email/password belong ONLY in cookidoo-bridge/.env.
COOKIDOO_CONNECTOR_ENABLED=false
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

The provider-facing bridge profile and Cookidoo credentials must not be configured
while the current policy gate is active. The available provider detail response
co-transports official steps, so `/v1/search` and `/v1/metadata` return
`503 metadata_hydration_disabled_policy` locally without provider requests.
EverShelf does not enqueue discovery/detail/backfill jobs. Existing cached catalog
rows and completed isolated pilot artifacts remain readable.

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
```

`TAXONOMY_AI_REVIEW` controls only the legacy v2 immediate-write path and is
opt-in. A regular ontology v3 revision may be manually activated only after the
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

Automatic taxonomy discovery, full crawls, periodic refresh, and crawl seeding are
policy-disabled. Legacy queued jobs terminate as local `skipped` outcomes without
connector failure or circuit accounting.

### Direct-ID metadata-v2 backfill

`COOKIDOO_METADATA_BACKFILL_ENABLED=false` is mandatory and cannot override the
policy gate. Status reports `provider_detail_policy_disabled`; enqueue refuses.
There is no authorized full backfill. A future provider endpoint that does not
co-transport official steps would require a new repository-policy review.

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
| Standard | 120 req/min | All general endpoints |
| AI | 15 req/min | `gemini_*`, `generate_recipe` |
| Strict | 5 req/min | `report_error` |

Rate limit state is stored in `data/rate_limits/`. To reset, delete the files in that directory.

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
