# 🏠 EverShelf

> **Self-hosted pantry management system** — Track your food inventory, scan barcodes, get AI-powered recipe suggestions, and reduce waste.

---

<div align="center">

### 🚀 Try the live demo — no installation required!

**[▶ Open Live Demo](https://evershelfproject.dadaloop.it/demo)**
&nbsp;·&nbsp;
[🌐 Project Website](https://evershelfproject.dadaloop.it/)
&nbsp;·&nbsp;
[📖 Wiki](https://github.com/dadaloop82/EverShelf/wiki)

*The demo runs with mock pantry data. AI features are fully enabled. All write operations are safely sandboxed.*

</div>

---

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.0+-blue.svg)](https://www.php.net/)
[![SQLite](https://img.shields.io/badge/SQLite-3-blue.svg)](https://www.sqlite.org/)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED.svg)](Dockerfile)
[![i18n](https://img.shields.io/badge/i18n-IT%20%7C%20EN%20%7C%20DE%20%7C%20FR%20%7C%20ES-orange.svg)](translations/)
[![Version](https://img.shields.io/badge/version-1.8.3-brightgreen.svg)](CHANGELOG.md)
[![GitHub stars](https://img.shields.io/github/stars/dadaloop82/EverShelf?style=social)](https://github.com/dadaloop82/EverShelf/stargazers)
[![Last commit](https://img.shields.io/github/last-commit/dadaloop82/EverShelf/main)](https://github.com/dadaloop82/EverShelf/commits/main)
[![Contributors](https://img.shields.io/github/contributors/dadaloop82/EverShelf)](https://github.com/dadaloop82/EverShelf/graphs/contributors)
[![GitHub Discussions](https://img.shields.io/github/discussions/dadaloop82/EverShelf)](https://github.com/dadaloop82/EverShelf/discussions)
[![CI](https://github.com/dadaloop82/EverShelf/actions/workflows/ci.yml/badge.svg)](https://github.com/dadaloop82/EverShelf/actions/workflows/ci.yml)

[![ko-fi](https://ko-fi.com/img/githubbutton_sm.svg)](https://ko-fi.com/J3J01ZNETZ)

---

> **⚠️ Name disambiguation:** There is an unrelated iOS app also called **EverShelf**, developed and published by [Joshumi Technologies LLC](https://evershelf.joshumi.com/) on the [Apple App Store](https://apps.apple.com/app/evershelf/id6759439940). That application is a **completely separate, independent product** with no affiliation, association, or collaboration with this open-source project. This repository has no connection to Joshumi Technologies LLC, its products, or its services.

---

### 🆕 Release 1.8.3 (2026-07-31)

- **History-first storage defaults** — Previously scanned barcodes and exact product names reuse their latest storage location.
- **Background Gemini classification** — Unseen products can be classified into a storage location or `unknown` without blocking item entry.
- **Current Gemini model support** — Location classification uses Gemini 3.6 Flash with Gemini 3.5 Flash-Lite fallback.
- **Shopping cart quantities** — Internal shopping-list rows store quantity and explicit adds increment existing rows.

See [CHANGELOG.md](CHANGELOG.md) for full details.

---

## ✨ Features

### 🏠 NEW — Home Assistant Integration

EverShelf has a **native Home Assistant integration** available on HACS.  
Connect your pantry to your smart home in minutes — no YAML, no manual sensor setup.

[![Install via HACS](https://my.home-assistant.io/badges/hacs_repository.svg)](https://my.home-assistant.io/redirect/hacs_repository/?owner=dadaloop82&repository=ha-evershelf&category=integration)
&nbsp;
[![Add Integration](https://my.home-assistant.io/badges/config_flow_start.svg)](https://my.home-assistant.io/redirect/config_flow_start/?domain=evershelf)

**What you get:**

| | |
|---|---|
| **18 sensors** | Expiry counts, stock levels by location (pantry / fridge / freezer / spice rack / cabinet), shopping list total, AI API usage, last backup timestamp, days to next expiry |
| **6 binary sensors** | Expired items, expiring items, expiring today, shopping list active, backup overdue, Bring! connected |
| **5 action buttons** | Refresh data, Refresh prices, **Suggest Recipe** (AI — result as HA notification), Sync smart shopping, Clear expired rows |
| **Shopping list todo** | Bidirectional sync — add, remove, check off items directly from HA |
| **Expiry calendar** | Every product's expiry date as a native HA calendar event — works with the calendar card and any calendar automation |
| **Quick-add text entity** | Type a product name in HA to instantly add it to the shopping list (great for voice assistants / Assist) |
| **6 services** | `add_to_shopping`, `mark_used`, `refresh`, `suggest_recipe`, `refresh_prices`, `clear_expired` |
| **Auto-discovery** | Detected automatically via Zeroconf/mDNS when `avahi-daemon` runs on the EverShelf host |
| **5 languages** | English, Italian, German, French, Spanish |

> **Requires a self-hosted EverShelf instance.** The integration talks directly to your server — no cloud involved.  
> Full documentation: [ha-evershelf on GitHub](https://github.com/dadaloop82/ha-evershelf)

---

### 📦 Inventory Management
- **Export inventory** — Download the full inventory as a UTF-8 CSV (Excel-compatible) or open a print-ready page to save as PDF; export button always visible in the inventory page header
- **Barcode scanning** — Scan products with your phone camera using QuaggaJS; last 20 scanned products saved as tappable chips so you can re-select them without rescanning
- **Canonical ingredients** — Products are mapped to common ingredient aliases and editable taxonomy trees (e.g. "Chicken breast" → "Chicken") with optional FoodOn and USDA FoodData Central IDs for better grouping, search, and recipe matching
- **Queued enrichment** — Product saves return immediately; canonical/FoodOn/USDA post-processing runs from cron or the CLI worker so Home Assistant/API additions stay responsive
- **Taxonomy history reuse** — Re-adding a known item replays the placement it was given before (matched by barcode, name+brand, name, or a recorded alias) without calling the model
- **AI taxonomy review** — Genuinely new items have their heuristic placement checked by Gemini against the whole taxonomy tree, which confirms or corrects the term and may add new nodes; it can never rename, move, or delete existing nodes
- **Faceted ingredient ontology v3 (shadow only)** — Versioned base entities,
  reviewed typed relations, closed defining facets, complete product/recipe mapping
  assertions, staged proposals, exhaustive audits, and materialized shadow scores
  coexist with the active v2 matcher until an explicit validated activation
- **Prepared food items** — Finished dishes can be flagged as prepared at add time so they group under the existing prepared meal term instead of being classified by ingredient
- **AI identification** — Take a photo and let Google Gemini identify the product, with suggestions from your existing inventory; gracefully shows a friendly message when AI quota is exhausted instead of a raw API error
- **Smart locations** — Track items across Pantry, Fridge, Freezer, and custom locations
- **Expiry tracking** — Automatic shelf-life estimation based on product type and storage
- **Opened product tracking** — Reduced shelf-life calculation when packages are opened; opened-product expiry is now also checked when building banner alerts (not just the dashboard section)
- **Vacuum-sealed support** — Extended expiry dates for vacuum-sealed items; products sealed under vacuum are only flagged as expired after a configurable grace period past the printed date (`VACUUM_EXPIRY_EXTENSION_DAYS`, default 30 days, configurable in `.env`)
- **Anomaly detection** — Banner alerts for suspicious quantities and consumption predictions with inline correction; dismiss button now shows the current inventory quantity so the action is unambiguous ("Quantity is correct (2 pcs)")

### 🤖 AI-Powered (Google Gemini)
- **Expiry date reading** — Photograph a label and extract the expiry date automatically
- **Product identification** — Point your camera at any product for instant recognition
- **Existing product matching** — AI scan shows matching products already in your pantry before suggesting new ones
- **Storage & shelf-life hint** — When adding a new product, Gemini suggests the optimal storage location and shelf-life in the background; shown as an inline AI badge next to the expiry estimate
- **Recipe generation** — Get personalized recipes based on what's in your pantry; streams live via Server-Sent Events so results appear as they are generated
- **Local recipe catalog** — Generated and saved recipes accumulate in a durable SQLite catalog with title/ingredient FTS5 search, source provenance, favorites, and offline local lookup
- **Taxonomy-aware suggestions** — Recipe ingredients match exact pantry terms and progressively broader taxonomy ancestors; suggestions rank pantry coverage and soon-to-expire stock deterministically
- **Scan-driven discovery** — When a newly stocked product already has taxonomy—or once its queued taxonomy enrichment completes—EverShelf automatically queues both ingredient-filtered and text-only recipe discovery for the canonical ingredient and its full ancestor chain
- **Cookidoo metadata discovery (experimental)** — The operator-approved `metadata-v2` bridge caches bounded factual General metadata, ordered ingredient names and display-only source amounts, remote image/canonical URLs, locale, and timestamps; official instructions and Guided Cooking data remain on Cookidoo
- **Recipe detail and missing groceries API** — A source-agnostic detail projection uses deterministic source-derived ingredient labels, reports separate grocery capability/state counts, and keeps Cookidoo instructions external-only; an idempotent action adds only revalidated missing ingredients to EverShelf's internal shopping list
- **Scalable recipe browse and recommendations** — Materialized inventory score revisions power compact 50-card pages, coverage/expiry filtering, independent weights, snapshot cursors, and a deterministic responsive recommendation carousel without loading the full catalog into PHP
- **Recipe stock hints** — Each pantry ingredient shows how much you have and what remains after use; when the leftover would be less than 5% of the full sealed package (10% for an already-opened partial pack), the recipe automatically uses everything on hand to avoid waste
- **Smart chat assistant** — Ask questions about your inventory, get cooking tips
- **Shopping suggestions with tips** — AI-powered purchase recommendations, each enriched with a short practical buying/storing tip
- **Anomaly explanation** — "Explain" button on anomaly banners explains in plain language why a discrepancy likely occurred and what to do
- **Model fallback** — Expiry scans try `gemini-3-flash` first, then `gemini-2.5-flash` and `gemini-2.0-flash`; other AI endpoints try `gemini-2.5-flash` first and fall back to `gemini-2.0-flash`
- **Graceful no-key state** — When no Gemini key is configured, AI entry points show a friendly message; the header button is visually greyed with an amber dot

### 🛒 Shopping List
- **Bring! integration** — Sync with the [Bring!](https://www.getbring.com/) shopping list app
- **Generic shopping names** — Products are grouped by type (e.g. "Milk", "Cold cuts", "Cooking cream") rather than brand, keeping the Bring! list clean and consolidated
- **Smart predictions** — Know what you'll need before you run out
- **Auto-add on depletion** — When a product reaches zero the app adds it to Bring! automatically, no confirmation needed
- **Auto-remove on scan** — Products are removed from the shopping list when scanned in  - **Auto-migration** — Items already on the Bring! list are silently renamed to their generic name in the background (throttled, runs on list load)
  - **Catalog coverage** — All product types resolve to a German Bring! catalog key for icon and category display in the Bring! app

### 🍳 Cooking Mode
- **♻️ Zero-waste tips** — For each cooking step that generates reusable scraps (peels, cooking water, egg whites, cheese rinds, bread crusts, vegetable tops, etc.), a dismissible ♻️ tip card appears with a practical reuse idea; tips are generated by Gemini as part of the recipe at no extra API cost; opt-in toggle in Settings (default OFF)
- **Step-by-step guidance** — Follow recipes with a hands-free cooking interface
- **Text-to-Speech** — Voice readout of recipe steps; supports browser Web Speech API, native Android TTS (kiosk), or a custom REST endpoint (Home Assistant, etc.); retries voice loading for up to 10 seconds with a fallback refresh button; TTS activates automatically without requiring the global TTS setting to be enabled
- **Auto-read on navigate** — Each step is read aloud automatically when you tap Next or Previous; the first step is read when entering cooking mode
- **Timer voice alerts** — 10-second countdown warning spoken aloud before each timer expires; expiry announced vocally when time is up
- **Recipe completion** — "Bon appétit!" announced via TTS when the last step is confirmed
- **Built-in timer** — Automatic timer suggestions based on recipe instructions
- **Ingredient tracking** — Mark ingredients as used during cooking; leftover quantities prompt a "move to another location" flow

### 📊 Dashboard
- **Waste tracking** — Monitor consumed vs. wasted products over 30 days
- **Anti-waste report** — Personalised waste rate vs. national average with annual kg estimate; shown above the expiring-items list
- **Expiry alerts** — Visual warnings for expired and soon-to-expire items
- **Opened products panel** — Tracks partially-used items; expiry is recalculated from the opening date using AI (Gemini) + per-category rule fallback; whole sealed packages always keep their original manufacturer expiry; conf items with mixed whole + fractional units are shown as two separate entries
- **Freezer shelf-life** — Granular per-product estimates (USDA/EFSA): fish 120 d, poultry 270 d, whole red-meat cuts 365 d, mince 120 d, vegetables/fruit 270 d, generic 180 d; AI + cache still take priority over rules
- **Safety ratings** — Smart assessment of expired product safety (by category and location); expired unsafe items shown with a red danger banner and a discard action as the primary action
- **Expired product banner** — Products that have passed their effective shelf-life (including opened-product reduced expiry) appear in the top notification banner; icon, colour and title adapt to the actual safety level (✅ green for safe, 👀 amber to check, 🚫 red for danger); high-risk items get a prominent discard action
- **Quick recipe bar** — One-tap recipe suggestion using expiring products
- **Anomaly banner** — Scrollable banner with suspicious quantities and consumption prediction mismatches, with one-tap correction or inline edit
- **Expired/expiring alerts** — Priority-sorted banner notifications for expired and soon-to-expire products with use, throw, edit, and dismiss actions
- **Swipe navigation** — Touch swipe or tap arrows/dots to browse banner notifications
- **Quick-access buttons** — Recently used and most popular products shown on the inventory page for fast access

### 🌙 Appearance
- **Dark mode** — Three modes: Light, Dark, and Auto (time-based: dark from 20:00 to 07:00, light otherwise); applies immediately without page reload; auto mode re-evaluates every 5 minutes, so night/day transitions happen automatically even on always-on kiosk displays; theme is applied before the first render to prevent a white flash
- **Global settings tab** — A dedicated **⚙️ General** tab groups all system-wide settings (language, currency, theme, screensaver, zero-waste tips, export) at the top of the Settings panel

### �️ Database Maintenance
- **Automatic cleanup** — Non-favorite legacy meal plans older than `RECIPE_RETENTION_DAYS` and transactions older than `TRANSACTION_RETENTION_DAYS` are deleted automatically; the normalized recipe catalog is retained until the user deletes a recipe
- **Manual cleanup** — Trigger immediately via `GET /api/?action=db_cleanup`
- **Compact by default** — Fresh installs stay small; large accumulated databases shrink back to a few hundred KB within one cron cycle

### �📱 Progressive Web App
- **Mobile-first design** — Optimized for phones, works on tablets and desktop
- **Installable** — Add to home screen for a native app experience
- **Multi-device** — All user data (shopping tags, pinned items, location preferences, scan history) is stored server-side in SQLite and shared across every device on the same instance; no data is siloed in a single browser's localStorage
### 📶 Offline Mode
- **Automatic detection** — Full-screen overlay appears immediately on network loss; shows a "Continue offline" button after 3 s, and auto-enters offline mode after 8 s
- **Local inventory cache** — Inventory is synced to `localStorage` at every startup and on each successful API call; the offline view always reflects the last known state
- **Write queue** — Add, use, update and delete operations performed while offline are queued locally and synced to the server automatically on reconnect (including after a page refresh)
- **Optimistic UI** — Queued writes are applied immediately to the local cache so the interface stays responsive
- **Offline-computed stats** — Expiring and expired items are derived client-side from the cache; dashboard stat cards show real counts instead of zeros
- **AI/network sections hidden** — Anti-waste chart, nutrition analysis, recipe generator, price fetching, and Gemini chat are hidden in offline mode; the inventory, history, and manually-managed shopping list remain fully functional
- **Broken image fallback** — External product images (Open Food Facts, etc.) that fail to load are replaced with a neutral grey placeholder, keeping the layout intact
- **Startup recovery** — If the page is refreshed while operations are queued, they are detected and synced automatically on the next successful startup
- **Buffered error reporting** — `remoteLog` and `reportError` calls made while offline are stored locally and flushed to the server (and to GitHub issues) when the connection is restored
### ⚖️ Smart Scale Integration (Add-on)
- **Bluetooth gateway** — Connects a BLE smart scale to EverShelf via local WebSocket
- **SSE relay** — Server-side relay avoids mixed-content (HTTPS→WS) issues
- **Auto-discovery** — Server scans LAN to find the gateway automatically
- **Auto weight reading** — When adding/using a product with unit g/ml, weight fills automatically
- **10g threshold** — Ignores readings that haven't changed enough between products  - **Duplicate-reading prevention** — Server-side 12-second dedup window rejects a second scale-triggered deduction of the same product, guarding against BLE multi-fire- **ml conversion hint** — Shows "weight in grams → will be converted to ml" when product unit is ml
- **Stability + auto-confirm** — 10s stable wait + 5s countdown before confirming
- **Real-time status** — Scale connection indicator always visible in the header
- **Multi-protocol** — Supports Bluetooth SIG Weight Scale, Body Composition, Xiaomi Mi Scale 2 and 100+ models
- **Built into kiosk (v1.6.0+)** — BLE gateway runs as an integrated foreground service inside the [EverShelf Kiosk](evershelf-kiosk/) app; no separate APK needed.

### 📺 Android Kiosk Mode (Add-on)
- **Dedicated tablet app** — Full-screen WebView wrapper for wall-mounted kitchen tablets
- **True kiosk lock** — Screen pinning blocks home/recent buttons
- **Setup wizard** — 6-step guided configuration (language, welcome, permissions, server URL, BLE scale scan, screensaver, summary)
- **Smart auto-discovery** — Scans the LAN in parallel (60 threads, TCP pre-check, ports 80/443/8080/8443) with real-time UI feedback; correctly identifies the device's Wi-Fi/Ethernet subnet (VPN and cellular interfaces are filtered out)
- **Built-in BLE scale gateway** — `GatewayService` foreground service; BLE scanning + WebSocket server `:8765` run directly inside the kiosk app. Select your scale in step 5 of the wizard — no external app required
- **Scale auto-configuration** — After selecting the BLE device, the wizard writes `scale_enabled` and `scale_gateway_url=ws://127.0.0.1:8765` to the server automatically
- **Camera & mic permissions** — Full hardware access for barcode scanning and voice; grant button transforms to a green confirmation after granting
- **Native TTS bridge** — Cooking mode voice readout uses the Android TextToSpeech engine directly, bypassing Web Speech API voice limitations; no offline voice packs required
- **Hard refresh** — ↻ button clears WebView cache to pick up web app updates
- **Update notifications** — Checks GitHub releases every 6h, shows banner when updates available
- **SSL support** — Accepts self-signed certificates
- **Android kiosk app** — [`evershelf-kiosk/`](evershelf-kiosk/) — downloadable APK

---

## 🚀 Quick Start

### Prerequisites
- **Web server** with PHP 8.0+ (Apache or Nginx)
- **PHP extensions**: `pdo_sqlite`, `curl`, `mbstring`, `json`
- **HTTPS** recommended (required for camera access on mobile)

### Installation

#### Option A: Docker (recommended)

```bash
# 1. Clone the repository
git clone https://github.com/dadaloop82/EverShelf.git
cd EverShelf

# 2. Create configuration file
cp .env.example .env
nano .env

# 3. Start with Docker Compose
docker compose up -d

# → Open http://localhost:8080
```

#### Option B: Manual

```bash
# 1. Clone the repository
git clone https://github.com/dadaloop82/EverShelf.git
cd EverShelf

# 2. Create configuration file
cp .env.example .env

# 3. Set permissions
chmod 755 data/
chmod 664 data/.gitkeep
chown -R www-data:www-data data/

# 4. Edit your configuration
nano .env
```

### Configuration (.env)

```ini
# Required for AI features (get a key at https://aistudio.google.com/app/apikey)
GEMINI_API_KEY=your_api_key_here

# Optional: Bring! shopping list integration
BRING_EMAIL=your_email@example.com
BRING_PASSWORD=your_password

# Optional: Text-to-Speech for cooking mode
TTS_URL=http://your-home-assistant:8123/api/events/tts_speak
TTS_TOKEN=your_long_lived_token
TTS_ENABLED=true

# Optional: DB retention and cleanup (applied automatically each cron cycle)
RECIPE_RETENTION_DAYS=7        # delete non-favorite legacy meal plans older than N days
TRANSACTION_RETENTION_DAYS=90   # delete stock transactions older than N days (min 30 enforced)

# Optional: normalized recipe discovery/index queue
RECIPE_QUEUE_CRON_LIMIT=10
RECIPE_QUEUE_CLI_LIMIT=50
RECIPE_QUEUE_MAX_ATTEMPTS=3
RECIPE_QUEUE_LEASE_MINUTES=15

# Optional/experimental: metadata-only Cookidoo connector
# Account credentials never go in this file; they belong only in cookidoo-bridge/.env.
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
COOKIDOO_PLANNER_ENABLED=false
COOKIDOO_QUEUE_CADENCE_MINUTES=1
COOKIDOO_DISCOVERY_LOCALE=en-US
COOKIDOO_REFRESH_ENQUEUE_LIMIT=2
# Historical compatibility settings; provider hydration is policy-disabled.

# Optional: Vacuum-sealed expiry grace period
VACUUM_EXPIRY_EXTENSION_DAYS=30 # extra days before vacuum-sealed items are flagged expired

# Optional: Gemini cost rates (USD per million tokens, for the Info tab cost estimate)
LOCATION_AI_ENABLED=true
GEMINI_LOCATION_MODEL=gemini-3.6-flash
GEMINI_LOCATION_FALLBACK_MODEL=gemini-3.5-flash-lite
GEMINI_COST_36F_IN=1.50
GEMINI_COST_36F_OUT=7.50
GEMINI_COST_35FL_IN=0.30
GEMINI_COST_35FL_OUT=2.50
GEMINI_COST_3F_IN=0.50
GEMINI_COST_3F_OUT=3.00
GEMINI_COST_25F_IN=0.15
GEMINI_COST_25F_OUT=0.60
GEMINI_COST_20F_IN=0.10
GEMINI_COST_20F_OUT=0.40

# Optional: Security — protect all API endpoints
# Set a strong random string; clients send it as the X-API-Token header
API_TOKEN=

# Optional: Legacy alias for API_TOKEN (settings save only)
SETTINGS_TOKEN=

# Optional: Demo mode — block all write operations at the router level
DEMO_MODE=false

# Optional: Logging
# LOG_LEVEL sets the minimum severity written to disk (DEBUG / INFO / WARN / ERROR)
# DEBUG also logs every SQL query executed against the database
LOG_LEVEL=INFO
LOG_ROTATE_HOURS=24   # hours before opening a new log file (default: 24)
LOG_MAX_FILES=14      # maximum number of rotated files to keep (default: 14)
```

### Optional Cookidoo Metadata Bridge

The connector uses the unofficial, reverse-engineered
[`miaucl/cookidoo-api`](https://github.com/miaucl/cookidoo-api). It is disabled by
default and may break when Cookidoo changes authentication or private endpoints.

**Current policy status:** Cookidoo card/detail hydration is disabled. The available
provider detail response co-transports official steps, so bridge `/v1/search` and
`/v1/metadata` return `503 metadata_hydration_disabled_policy` before any provider
request. EverShelf does not enqueue discovery or metadata backfill jobs. Existing
cached catalog rows and completed isolated pilot artifacts remain readable.

Policy `metadata-v2` stores only bounded factual metadata: title, remote image and
canonical URLs/ID, locale/timestamps, optional bounded provider content-language
evidence, yield quantity plus unit, explicit prep/cook/active/total seconds,
explicit or deterministically derived inactive/rest seconds, bounded
supported/required and optional device names, difficulty, one primary category
label, provider-listed equipment nouns, and ordered
ingredient names with exact/range/unit/amount display facts. Ingredient group and
within-group order, short group titles, provider ingredient/default-title/unit
references, provider-declared optional booleans, and shopping-category references
are retained as bounded factual topology. Source amounts live in a separate
display-only table and never feed ranking quantity/unit, coverage, cookable status,
or internal shopping quantities.

Manual/local recipes derive prep and cook seconds only from existing bounded
prep/cook duration fields (including deterministic ISO-8601 values). When active
and total are the only time facts, their non-negative difference is exposed as
inactive/rest time, never cook time. An inert proposal interface exists for
unresolved time strings, but it performs no model call or automatic persistence.

Historical isolated imports persisted the selected effective regional/script locale
and a matching canonical URL. The separate upstream `languages` search filter is
forced to English in disabled scaffolding. Provider `language`, when present, is
stored only as undocumented captured evidence, not a contractual guarantee; locale
remains separate and deterministic local detection still rejects explicit
non-English ingestion. This describes existing cached data, not an active discovery
path.

Official Cookidoo instructions remain external-only. The bridge and EverShelf do
**not** expose or persist steps, notes/tips, category or collection descriptions,
nutrition, tags, ingredient preparation text/prose, Guided Cooking content, image
bytes, or raw API responses. The bridge never accesses ingredient `preparation`.
Opening a card loads its remote image directly from Cookidoo's image host and
therefore contacts that host.

`GET api/index.php?action=recipe_catalog_detail&id=<positive-id>` returns the bounded
`recipe_detail_v1` projection. `POST api/index.php?action=recipe_catalog_grocery_add`
accepts selected ingredient keys/positions plus a client idempotency key, rechecks
inventory, and adds only genuine missing items to EverShelf's internal shopping list.
EverShelf never calls Home Assistant; HA clients may mirror the returned normalized
names and amount text.

Each detail ingredient also carries an opaque `feedback_token`, persisted
display-only availability override, latest explicit identity verdict, and bounded
feedback capabilities. `recipe_catalog_ingredient_override` stores `have`,
`missing`, or `clear` without changing inventory, scores, ontology, or grocery
eligibility. `recipe_catalog_identity_feedback` records `correct`/`wrong` evidence
for a matched product or identity-safe closest label; evidence settles for 14 days
and exports proposal-only through `scripts/recipe-ingredient-feedback.php`.

New clients use the single atomic
`recipe_catalog_ingredient_decision` command. `assume_have` writes only a display
override. `select_inventory_product` revalidates positive stock and records
positive evidence for that exact product. `reject_current_match` binds negative
evidence only when the exact displayed product is still current; staples/no-target
rows remain availability-only. Every positive/negative identity decision writes a
transactional proposal outbox row and candidate regression fixture in the same
SQLite transaction. Positive evidence is immediately eligible; negative evidence
remains provisional for 48 hours. A later decision deterministically supersedes
the prior provisional outbox item.

Proposal processing is asynchronous, staging-only, and never activates ontology
content. The worker uses exactly
`ingredientOntologyV3ConfiguredProposalModel()` with no fallback, persists
immutable prompt/manifest/raw-response artifacts, runs the existing closed-set
validator, and stores only reviewable change sets:

```bash
# Safe default: build immutable prompts for an operator/Copilot handoff.
php scripts/recipe-ingredient-proposals.php export \
  --db=/path/to/copy.sqlite --out=proposal-handoff.json --write

# Import returned proposal JSON; deterministic validation still owns staging.
php scripts/recipe-ingredient-proposals.php import \
  --db=/path/to/copy.sqlite --input=proposal-result.json --write

# Optional separately keyed worker; never reuses the app/Docker GEMINI_API_KEY.
php scripts/recipe-ingredient-proposals.php work \
  --db=/path/to/copy.sqlite --write --allow-network
```

An imported result echoes the exported `outbox_id`, `feedback_event_id`,
`model`, `prompt_hash`, and `manifest_hash`, uses schema
`recipe_ingredient_proposal_handoff_result_v1`, and places the model JSON under
`response`. Provenance mismatch is rejected.

The optional worker requires a separate
`INGREDIENT_ONTOLOGY_V3_PROPOSAL_API_KEY`; blank configuration uses the handoff
path and never falls back to the app/Docker `GEMINI_API_KEY`. Missing/rejected
keys, unavailable models, and network failures remain durable
blocked/retry rows. Raw model JSON never writes active entities, labels, relations,
or mappings; the proposal CLI refuses the active database, and copied-database
candidate builds, regression/gold gates, shadow
scoring, human adjudication, and explicit activation/rollback remain mandatory.

Cookidoo My Week write scaffolding is separate from metadata hydration and remains
disabled by default. EverShelf requires `COOKIDOO_PLANNER_ENABLED=true`; the bridge
independently requires `COOKIDOO_PLANNER_WRITE_ENABLED=true` and an explicitly
verified `COOKIDOO_PLANNER_PUT_SEMANTICS=append|replace`. `unknown` suppresses
`recipe_planner_v1` and refuses writes. The server resolves the stored Cookidoo
origin, validates a revision-bound provider token and a date from today through
365 days, journals before network traffic, and never holds SQLite open over the
request. The bridge performs read-before-write and fresh read-after-write
verification, preserves preexisting IDs, reconciles ambiguous timeouts, retries
one stale authentication, and opens a circuit on 403/429. This is an account-level
planner action, not a direct Thermomix device push.

Cookidoo ingestion has a separate deterministic content-language assessment.
High-confidence non-English rows can be quarantined from user-facing catalog reads
without changing catalog membership or ontology materializations:

```bash
php scripts/cookidoo-language-assessments.php --db=/path/to/copy.sqlite
```

The CLI is dry-run by default; writes require an explicit copied database,
`--write`, and a rollback manifest.

The detail DTO keeps the compatibility `ingredients` array and adds
`ingredient_groups` containing stable local keys, group/order ordinals, ingredient
keys/positions, and bounded provider/local labels. Ingredient entries add a
source-preserving `provider` object for ingredient reference, English/default title,
unit reference, optionality, and shopping-category reference. The compatibility
flat list and source-derived display name remain unchanged. Optional instruction
groups may reference authorized local/manual/generated step positions; Cookidoo
omits the instruction-group property and emits external-link-only instructions.

Ingredient `display_name`/legacy `name` is always derived from bounded source text,
never from a broad canonical or taxonomy label. Complete approved amount-plus-unit
prefixes in legacy source text are removed as one unit; a bare number is never
stripped from an unknown following word. Grocery names use the cleaned label and
unsafe source-name dedupe removes the same prefix while retaining source descriptors.
Stable mapping IDs and labels remain secondary read-time metadata. `closest_match`
is emitted only for identity-safe alias/slug mappings; taxonomy-rule matches are
suppressed regardless of confidence. `capabilities.grocery_add` means the complete
nonempty list is supported, while the sibling `grocery` object reports missing,
uncertain, in-stock, staple, eligible, and blocking state.

Do not configure Cookidoo credentials or start the provider-facing bridge profile
for metadata hydration while detail hydration is policy-disabled. Local recipe
search and existing cached Cookidoo catalog reads remain available. Planner use is
a separate explicit dual-gate operation and does not amend the instruction
prohibition.

Full-corpus crawls, taxonomy-triggered discovery, periodic refresh, and direct
metadata hydration are policy-disabled. Existing queued jobs terminate as local
`skipped` outcomes; do not run the crawl/backfill commands retained in the source
tree.

`COOKIDOO_METADATA_BACKFILL_ENABLED` remains false and cannot override this policy
gate. Status reports `provider_detail_policy_disabled`; enqueue refuses. No full
backfill is authorized. A future step-free provider endpoint would require a new
repository-policy review before hydration could be re-enabled.

### Ingredient ontology v3 shadow workflow

Ontology v3 is additive, disabled by default, not deployed, and not activated in
this release. It uses a strict primary `is_a` spine plus reviewed
`equivalent_to`, `variant_of`, `substitutes_for`, `derived_from`, and
`component_of` relations. Identity is the same base entity with compatible
defining facets such as form, processing, cut, bone, skin, refinement, variety,
state, and species. Ancestry, components, derivation, lexical containment,
taxonomy rules, aliases quarantined from Gemini, and model confidence are evidence
only and never make a required ingredient sufficient.

Schema v3.15 adds one connected `food` backbone, orthogonal entity
`identity_role`, closed entity/facet policy, fingerprint-bound evidence
manifests, recipe-cohort/provider-cluster evidence, reviewed primary-edge diffs,
and immutable terminal dispositions D1-D9. Structural categories cannot satisfy
identity. Provider refs never directly supply identity, and cohorts are context
gates only. Completion means every product, recipe/source ingredient row, and
provider term has a terminal reviewed disposition; it does not require accepting
an identity.

Corrective v3.15 uses a hash-bound overlay on the frozen v3.12 base. Review
inputs explicitly cover all 304 entity roles and
primary-edge decisions, all prior accepted-label transitions, all 174 products,
all 646 provider terms, and the reviewed owner-scoped provider frontier.
Unmanifested recipe labels terminate D9. D3 requires an exact context review;
recipe D4-D6 require exact semantic review rows. Former candidate targets,
facets, relations, confidence, source, and denied provenance are retained in
immutable assertion history.

The v3.15 gates account for every prior-accepted owner outcome, require exact
reviewed transition facets or narrow waivers, compare all accepted provider
term signatures, and digest every common legacy owner across copies. Dedicated
publication guards prevent direct building-to-ready ontology, score, or
requirement updates; approved builders publish only after complete hashes and
materializations are present.

The v3.15 integrity pass additionally isolates all grocery/mutation decisions
to the true active revision, seals a monotonic owner-source revision/hash,
preserves complete score-revision schemas during legacy FK migration, restores
nested guards after failures, enforces nonproduction preview environments,
verifies mapping-attribute companion rows and versions, and accounts for all
465 prior gold cases as exactly retained, superseded, or explicitly retired.

Candidate builds must explicitly select `--corpus-profile=eval` or
`--corpus-profile=provider`. Each profile recomputes its pinned frozen source
hash and exact owner counts; product and provider-term review sets use exact
set equality. The selected profile, activation policy/reason, reviewed subject
universe, pinned matcher fixture hash, ordered matcher case IDs, and count all
participate in the immutable ontology seal. Activation reruns full revision,
gold, source-universe, ID-set, and materialization integrity before and under
the write reservation.

Development and test instances may set one explicit
`RECIPE_SCORE_PREVIEW_REVISION_ID` only when `EVERSHELF_ENV` is
`development` or `test`. Read-only recipe search, suggestions,
recommendations, detail matching, and cursors then use that validated regular
v3 shadow revision while the true active pointer and all mutation/rebuild logic
remain unchanged. Blank/`0` disables preview; invalid, stale, requirement-shadow,
or unsealed revisions fail closed to the active revision with bounded
diagnostics. Production must leave the setting blank.

The Kalamata review adds closed `variety=kalamata` and
`preparation=pitted` facets, fingerprint-bound product and multilingual
full-span aliases, plus gold boundaries that keep garlic identities separate.
Recipe detail responses never publish a matched product, relation, or candidate
confidence for non-satisfying uncertain ancestry/facet candidates; internal
scoring state is unchanged.

Run the operator CLI only against a database copy:

```bash
php scripts/ingredient-ontology-v3.php build-candidate \
  --db=.ontology-v3-work/evershelf-copy.sqlite \
  --corpus-profile=eval --write
php scripts/ingredient-ontology-v3.php audit --db=.ontology-v3-work/evershelf-copy.sqlite \
  --version-id=1 --json-out=.ontology-v3-work/audit.json
php scripts/ingredient-ontology-v3.php disposition-audit \
  --db=.ontology-v3-work/evershelf-copy.sqlite --version-id=1
php scripts/ingredient-ontology-v3.php export-dispositions \
  --db=.ontology-v3-work/evershelf-copy.sqlite --version-id=1 \
  --csv-out=.ontology-v3-work/dispositions.csv
php scripts/ingredient-ontology-v3.php export-provider-workbook \
  --db=.ontology-v3-work/evershelf-copy.sqlite --version-id=1 \
  --csv-out=.ontology-v3-work/provider.csv
php scripts/ingredient-ontology-v3.php build-shadow \
  --db=.ontology-v3-work/evershelf-copy.sqlite --version-id=1 --write
php scripts/ingredient-ontology-v3.php report --db=.ontology-v3-work/evershelf-copy.sqlite \
  --revision-id=2 --json-out=.ontology-v3-work/shadow.json
php scripts/ingredient-ontology-v3.php validate \
  --db=.ontology-v3-work/evershelf-copy.sqlite --revision-id=2
```

Workbook imports require `--write`, `--reviewer`, and `--batch`, refuse the
active database, validate current fingerprints, and create immutable staging
rows only. They never rewrite a ready terminal disposition.

`activate` and `rollback` require explicit write and confirmation flags. Activation
revalidates inventory, catalog, source-owner, ontology-content, model/prompt/schema,
materialization, blocker, active-pointer, and current-date inputs under SQLite
`BEGIN IMMEDIATE`, then changes only `recipe_score_state.active_score_revision_id`
plus `cursor_revision`. Manual production activation additionally requires a
complete retained parent score revision; build and activate a legacy baseline
before the first v3 shadow. The ontology version is derived from that score
revision and has no independent active pointer. Rollback defaults to the immediate parent
and directly accepts one of the eight retained, cycle-safe proven ancestors even
when its inventory, catalog, or score date is stale. A non-ancestor must be a v3
child of the current active revision and pass the normal activation gates;
non-ancestor legacy targets are rejected. Pruning keeps the active revision, its
eight most recent ancestors, its immediate parent, the latest same-parent
idempotency candidate, four recent ready-v3 revisions, and two recent legacy
revisions. The oldest retained ancestor has its parent cleared to mark the
documented rollback boundary.

Full-resolution v3.16 data under ontology schema v3.17 carries
`activation_policy=manual_review`; validation still requires the exact frozen
corpus and reviewed subjects, pinned gold, complete integrity and materialized
ID/value gates, a validated rollback baseline, plus explicit CLI confirmation
before moving the active score pointer. Requirement-projection and source-aware revisions remain blocked. The
activation machinery remains deliberately manual, and the prior ready revision
is retained for rollback.

Ready versions reject inserts, updates, deletes, and reseals across ontology,
score, match, requirement, member, and recipe-state materializations. Validation
recomputes canonical row/content/seal and materialized-value hashes, checks
bidirectional recipe/ingredient/requirement ID equality, and compares portable
terminal-disposition and legacy-owner outcome digests across eval/pilot copies.
The retained 60-positive / 50-critical-negative adjudicated gold base is
code-pinned, frozen-source and owner/product fingerprint-bound, structurally
validated, and supersession-audited. Maintainer review metadata records its
scope and confidence limits; code does not claim or infer model independence.
Generated accepted-row snapshots are conformance artifacts, not gold.

The minute score rebuild detects an active v3 score revision and rebuilds only
against that exact ontology version under the shared score lock. It never falls
back to legacy v2. Before a fresh return or replacement it verifies current source
owner fingerprints and version/corpus/content hashes; drift returns
`ontology_stale` and leaves the previous pointer intact for a new candidate/remap.
Exclusive lock acquisition fails every pre-existing `building` revision before
reuse/build decisions, so partial legacy/v3 rows become prunable while same-input
recovery creates one replacement. Request handling serves the prior pointer as
stale instead of forcing another rebuild. A committed activation remains
successful if bounded post-activation pruning reports a `cleanup_warning`.
Compatible quantity aggregation always unions distinct inventory rows/lots and
uses their earliest non-null expiry. `INGREDIENT_ONTOLOGY_V3_QUANTITY_SUFFICIENCY_GATE`
controls only whether insufficient quantity blocks an otherwise valid identity
match and defaults to `false`.
Each v3 score revision stores a deterministic `scoring_config_hash` over the
scoring model/version and quantity-gate state. Changing the gate makes the active
revision stale and prevents incompatible reuse, activation, or v3 rollback.

Staged proposal sets can be terminalized through the audited `reject`, `dispose`,
and `revert` CLI commands. Revert transactionally withdraws unapplied pending or
approved sets and children; applied sets still fail closed because inverse
provenance is not represented. Already-reverted sets append an idempotent audit
event. Terminal rejected/reverted sets do not block activation; pending, approved,
or applicable invalid sets do.

Resolution gold contains at least 300 label-to-resolution positives and 150
critical negatives, alongside the matcher fixture and generated assertions for
every accepted manifest alias. Frozen-gold validation requires a bounded, unique,
fully resolved fixture with nonzero expected/predicted positives, expected
negatives, and critical negatives;
precision and recall must be at least `0.99`, with zero false negatives and zero
critical false positives. Assertion attributes are closed to the selected
ontology version's facet/value map. Reports include bounded error cases and 95%
Wilson intervals.

Gemini 3.5 Flash is the frozen proposal default. The benchmark found missing
facets/inconsistent entities from Pro and one Gemini 3.6 Flash run that marked all
hard attributes non-defining. Model JSON is therefore fenced, bounded, closed-set,
staged only, and never auto-applied. Reduced coverage and cookability are expected
when broad false-positive matches are removed.

### Web Server Configuration

<details>
<summary><strong>Apache (.htaccess)</strong></summary>

The app works out of the box with Apache if placed in the web root or a subdirectory. Make sure `mod_rewrite` is enabled and `AllowOverride All` is set.

```apache
<Directory /var/www/html/evershelf>
    AllowOverride All
    Require all granted
</Directory>
```

</details>

<details>
<summary><strong>Nginx</strong></summary>

```nginx
server {
    listen 80;
    server_name your-server.local;
    root /var/www/html/evershelf;
    index index.html;

    location /api/ {
        try_files $uri $uri/ =404;
        location ~ \.php$ {
            fastcgi_pass unix:/run/php/php8.2-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include fastcgi_params;
        }
    }

    # Deny access to sensitive files
    location ~ /\.env { deny all; }
    location ~ /data/ { deny all; }
    location ~ /backup\.sh { deny all; }
}
```

</details>

### HTTPS Setup (Recommended)

Camera access requires HTTPS on most mobile browsers. Options:
- **Let's Encrypt** with Certbot (for public-facing servers)
- **Self-signed certificate** (for local network only)
- **Reverse proxy** (e.g., Caddy, Traefik) with automatic TLS

### Cron Job

The Docker image runs this on its own via `docker/evershelf-cron`, so no host setup is
needed. Configure it manually only for non-Docker installs:

```bash
# Canonical taxonomy and smart shopping, every 5 minutes
*/5 * * * * php /path/to/evershelf/api/cron_smart_shopping.php >> /path/to/evershelf/data/cron.log 2>&1

# Materialized inventory-to-recipe scores, every minute
* * * * * php /path/to/evershelf/scripts/rebuild-recipe-scores.php >> /path/to/evershelf/data/cron.log 2>&1

# Local recipe jobs and cadence-limited Cookidoo discovery, every minute
* * * * * php /path/to/evershelf/scripts/process-recipe-queue.php --limit=2 --max-attempts=3 --respect-cookidoo-cadence >> /path/to/evershelf/data/cron.log 2>&1
```

These jobs are **not optional** when their features are enabled. The first drains canonical
ingredient/taxonomy work, the second keeps recipe browse scores current, and the third
processes local recipe jobs plus remote metadata discovery. Without them, new products
never receive taxonomy terms, large recipe catalogs remain temporarily unavailable, and
remote hydration stays queued. Overlapping score and queue runs use locks/retries safely.

### Backup (Optional)

The included `backup.sh` creates local daily backups of your database:

```bash
# Run daily at 3 AM
0 3 * * * /path/to/evershelf/backup.sh
```

### Google Drive Backup (Optional)

EverShelf supports automatic daily backups to Google Drive via OAuth 2.0. This works on any server, including private IP / local network setups (no public domain required).

**Setup:**

1. Go to [console.cloud.google.com](https://console.cloud.google.com) and select or create a project.
2. Enable the **Google Drive API** (`APIs & Services → Enable APIs → Google Drive API`).
3. Go to `APIs & Services → Credentials → Create Credentials → OAuth client ID`.
4. Application type: **Web application**.
5. Add **`http://localhost`** as an Authorized Redirect URI (this is the key — it works even without a real domain).
6. Copy **Client ID** and **Client Secret** into EverShelf Settings → Backup.
7. Enter your **Google Drive Folder ID** (the last part of the folder URL).
8. Click **Authorize with Google** and sign in.
9. The browser will redirect to `http://localhost` and may show a connection error — **this is expected**. Copy the full URL from the address bar (e.g. `http://localhost/?code=4%2F0A...`) and paste it into the field that appears in EverShelf, then click **Submit**.

> **Note:** While the OAuth app is in *Testing* status in Google Cloud Console, you must add your Google account as a test user under `APIs & Services → OAuth consent screen → Test users`.

---

## 🏗️ Architecture

```
evershelf/
├── index.html              # Single-page application (SPA)
├── manifest.json           # PWA manifest
├── .env.example            # Configuration template
├── backup.sh               # Local database backup script
├── LICENSE                 # MIT License
│
├── api/
│   ├── index.php           # Main API router (all endpoints)
│   ├── database.php        # SQLite schema, migrations, helpers
│   └── cron_smart_shopping.php  # Background job for predictions
│
├── assets/
│   ├── css/style.css       # All application styles
│   ├── js/app.js           # All application logic
│   └── img/                # Static images
│
└── data/                   # Runtime data (gitignored)
    ├── evershelf.db         # SQLite database (auto-created)
    ├── backups/            # Local DB backups
    └── *.json              # Token/cache files

evershelf-scale-gateway/    # ⚖️ Android BLE gateway [DEPRECATED — integrated into kiosk v1.6.0+]
    ├── README.md           # Deprecation notice + legacy docs
    └── app/src/            # Kotlin Android source (WebSocket + BLE)

evershelf-kiosk/            # 📺 Android kiosk app (add-on)
    ├── README.md           # Setup & feature docs
    └── app/src/            # Kotlin Android source (WebView wrapper)
```

### API Endpoints

| Category | Action | Method | Description |
|----------|--------|--------|-------------|
| **Products** | `search_barcode` | GET | Find product by barcode |
| | `lookup_barcode` | GET | Look up barcode on Open Food Facts |
| | `location_suggestion` | POST | Resolve history-first or AI storage location |
| | `product_save` | POST | Create or update a product |
| | `products_list` | GET | List all products |
| **Inventory** | `inventory_list` | GET | List inventory items |
| | `inventory_add` | POST | Add product to inventory |
| | `inventory_use` | POST | Use/consume from inventory |
| | `inventory_summary` | GET | Count by location |
| **AI** | `gemini_identify` | POST | Identify product from photo |
| | `gemini_expiry` | POST | Read expiry date from photo |
| | `gemini_chat` | POST | Chat with AI assistant |
| | `generate_recipe` | POST | Generate recipe from inventory |
| | `gemini_product_hint` | POST | Storage location + shelf-life hint |
| | `gemini_shopping_enrich` | POST | Enrich shopping suggestions with tips |
| | `gemini_anomaly_explain` | POST | Plain-language anomaly explanation |
| **Shopping** | `bring_list` | GET | Get Bring! shopping list |
| | `bring_add` | POST | Add items to Bring! |
| | `smart_shopping` | GET | Smart shopping predictions |
| **Settings** | `get_settings` | GET | Get server configuration |
| | `save_settings` | POST | Update server configuration |

---

## 🔒 Security Notes

- **Credentials** are stored in `.env` (server-side, never committed to Git)
- **Database** stays local — never pushed to remote repositories
- **Apache/Nginx hardening** — `.env`, `data/`, and `logs/` are blocked from direct HTTP access
- **API token** — set `API_TOKEN` in `.env` to require the `X-API-Token` header on all API calls, including Home Assistant. The legacy `?api_token=` form is deprecated because URLs can be written to browser history and access logs.
- **API keys are never exposed to the browser** — `get_settings` returns only boolean flags (`gemini_key_set`, `ha_token_set`, …)
- **GitHub Issues token** — stored encrypted as `GH_ISSUE_TOKEN_ENC` + `GH_ISSUE_TOKEN_KEY` (see `scripts/encrypt-gh-token.php`)
- **Settings write protection** — `save_settings` requires the same API token when configured; validated with `hash_equals`
- **Demo / public mode** — set `DEMO_MODE=true` to block all write operations at the PHP router level before any business logic runs
- The API uses **parameterized SQL queries** (PDO prepared statements) against injection
- **Input validation** on all inventory operations (quantity bounds, location whitelist)
- Consider adding **reverse-proxy authentication** (e.g. Authelia, Nginx `auth_basic`) if the server is accessible from the internet

---

## 🛠️ Development

```bash
# Run PHP's built-in server for local development
php -S localhost:8080 -t /path/to/evershelf

# Check PHP syntax
php -l api/index.php
php -l api/database.php
```

The application uses no build tools — edit files directly and refresh.

---

## 📋 Roadmap

Feature requests, bug reports and planned work are tracked in the [**EverShelf Roadmap**](https://github.com/users/dadaloop82/projects/2) GitHub Project.

---

## 🌐 Translations

The app supports multiple languages via JSON translation files in the `translations/` folder.

| Language | Status |
|----------|--------|
| 🇮🇹 Italian (it) | ✅ Complete (base) |
| 🇬🇧 English (en) | ✅ Complete |
| 🇩🇪 German (de) | ✅ Complete |
| 🇫🇷 French (fr) | ✅ Complete |
| 🇪🇸 Spanish (es) | ✅ Complete |

**Want to add your language?** See the [Translation Guide](CONTRIBUTING.md#-adding-translations) — just copy `translations/it.json`, translate the values, and submit a PR!

---

## 🤝 Contributing

Contributions are welcome! See [CONTRIBUTING.md](CONTRIBUTING.md) for detailed guidelines.

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Commit your changes (`git commit -m 'Add my feature'`)
4. Push to the branch (`git push origin feature/my-feature`)
5. Open a Pull Request

### Easiest way to start — translate EverShelf into your language

Translations are just JSON files. No coding, no setup — fork → edit → PR.

```
translations/
├── it.json   ✅ Italian (base)
├── en.json   ✅ English
├── de.json   ✅ German
├── fr.json   ✅ French
├── es.json   ✅ Spanish
├── pt.json   ❌ Portuguese — wanted!
├── nl.json   ❌ Dutch — wanted!
└── ...       ❌ Your language here!
```

👉 See [issue #93](https://github.com/dadaloop82/EverShelf/issues/93) to claim a language.

### Other ways to contribute

| What | Skill needed |
|---|---|
| 🐛 Report a bug | None |
| 📖 Improve the wiki | Markdown |
| 🌍 Add a translation | JSON editing |
| 🎨 Fix a CSS/UI issue | CSS / HTML |
| ⚙️ Implement a feature | PHP / JS |
| ⭐ Star the repo | Clicking |

👉 Browse [`help wanted`](https://github.com/dadaloop82/EverShelf/labels/help%20wanted) issues for good starting points.

Read [CONTRIBUTING.md](CONTRIBUTING.md) for the full guide (branch naming, code style, how to run locally).

---

## 💬 Community

Join the conversation in [GitHub Discussions](https://github.com/dadaloop82/EverShelf/discussions):
- **Vote on upcoming features** — tell us what to build next
- **Show your setup** — share your kitchen kiosk
- **Ask questions** — get help from the community

---

## 📄 License

This project is licensed under the **MIT License** — see the [LICENSE](LICENSE) file for details.

---

## 👨‍💻 Author

**Stimpfl Daniel** — [evershelfproject@gmail.com](mailto:evershelfproject@gmail.com)

- Website: [evershelfproject.dadaloop.it](https://evershelfproject.dadaloop.it/)
- GitHub: [@dadaloop82](https://github.com/dadaloop82)

---

## 📸 Screenshots

<div align="center">

![EverShelf demo — barcode scan, inventory management and AI recipe generation](assets/img/demo.gif)

</div>

For a live walkthrough with real data and full AI enabled, visit the **[live demo](https://evershelfproject.dadaloop.it/demo)** — no installation required.

> Want to contribute additional screenshots? See [CONTRIBUTING.md](CONTRIBUTING.md) — PRs welcome!
