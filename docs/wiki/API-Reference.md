# 🔌 API Reference

EverShelf exposes a single PHP endpoint: **`api/index.php`**. All actions are selected via the `action` query parameter.

> **Full OpenAPI 3.1 spec:** [`docs/openapi.yaml`](https://github.com/dadaloop82/EverShelf/blob/main/docs/openapi.yaml)

---

## Base URL

```
https://your-server/api/index.php?action=ACTION_NAME
```

GET requests pass parameters as query params; POST requests send JSON in the body.

---

## Rate Limits

| Tier | Limit | Applies to |
|------|-------|-----------|
| Standard | 120 req/min | All general endpoints |
| AI | 15 req/min | `gemini_*`, `generate_recipe*` |
| Strict | 5 req/min | `report_error` |

Exceeded limits return HTTP 429 with `{"error": "rate_limit_exceeded"}`.

---

## Products

### `search_barcode` — GET
Search for a product in the local database by barcode.

| Param | Type | Description |
|-------|------|-------------|
| `barcode` | string | EAN/UPC barcode |

### `lookup_barcode` — GET
Look up a barcode on Open Food Facts (external call).

| Param | Type | Description |
|-------|------|-------------|
| `barcode` | string | EAN/UPC barcode |

### `product_save` — POST
Create or update a product. Pass `id` to update. The save path queues canonical/common ingredient post-processing and returns immediately after the product is persisted. Existing mappings may be returned in `canonical_ingredients`; queued work is processed by cron or `scripts/process-canonical-queue.php`.

When the queue processes a product it first looks for a previous classification of the same item (barcode, name+brand, name, or recorded alias) and replays it. Only genuinely new items are sent to Gemini for taxonomy review, which confirms or corrects the heuristic placement against the whole tree and may add new nodes — never modifying existing ones.

Pass `prepared_food: true` for finished dishes that should not be classified by ingredient. Those group straight under the existing prepared meal term and skip both the history lookup and the model. The flag is sticky: it only changes when explicitly supplied, so ordinary saves never clear it.

### `product_set_prepared_food` — POST
Toggle the prepared-food flag on an existing product and re-queue its taxonomy grouping. Separate from `product_save`, which rewrites every column from its input and would blank out fields a partial payload omits.

```json
{ "id": 42, "prepared_food": true }
```

### `inventory_set_prepared_food` — POST
Mark some or all units of an inventory row as prepared food. Passing a `quantity` below the row quantity splits those units onto their own inventory row so the rest of the batch keeps its previous state; rows that become identical again are merged back together. The product-level flag is recomputed as "any stocked row is flagged" and the product is re-queued so its taxonomy regroups.

```json
{ "inventory_id": 190, "prepared_food": true, "quantity": 2 }
```

```json
{
  "id": 42,
  "name": "Pasta Barilla",
  "brand": "Barilla",
  "category": "pasta",
  "unit": "g",
  "default_quantity": 500,
  "barcode": "8076800105988",
  "ingredients_text": "durum wheat semolina",
  "ingredients_tags": ["en:durum-wheat-semolina"],
  "off_generic_name": "pasta"
}
```

### `product_get` — GET
Get product details by `id`, including `canonical_ingredients` when mappings exist.

### `product_delete` — POST
Delete a product by `id`.

### `products_list` — GET
List all products.

### `products_search` — GET
Search products by product name, brand, barcode, category, and canonical taxonomy terms. Token matching is order-independent and requires every query token to appear somewhere in the searchable fields, so `fried tenders` can match `Fried Chicken Tenders`. Queries also match editable taxonomy tree nodes, expand to descendant products, and de-dupe those taxonomy results with direct product matches.

| Param | Type | Description |
|-------|------|-------------|
| `q` | string | Search text |
| `limit` | int | Optional result cap (default 20, max 100) |

### `product_ingredients` — GET
Return canonical/common ingredient mappings for a product.

| Param | Type | Description |
|-------|------|-------------|
| `product_id` | int | Product ID |

### `canonical_ingredients_assess` — GET
Return coverage, examples, and external FoodOn/USDA FDC link counts for canonical ingredient mappings. Defaults to active inventory products; pass `scope=all` for all products.

---

## Inventory

### `inventory_list` — GET
List all inventory items with product details, grouped.

**Response:**
```json
{
  "inventory": [
    {
      "id": 1,
      "product_id": 42,
      "name": "Pasta Barilla",
      "quantity": 2,
      "unit": "pz",
      "location": "dispensa",
      "expiry_date": "2027-03-01",
      "opened_at": null,
      "vacuum_sealed": 0
    }
  ]
}
```

### `inventory_add` — POST
Add a product to inventory.

```json
{
  "product_id": 42,
  "quantity": 3,
  "location": "dispensa",
  "expiry_date": "2027-03-01",
  "vacuum_sealed": false
}
```

### `inventory_search` — GET
Search active inventory by product name, brand, barcode, category, and canonical taxonomy terms. Uses the same tokenized taxonomy-tree expansion and product de-duping as `products_search`.

| Param | Type | Description |
|-------|------|-------------|
| `q` | string | Search text |
| `limit` | int | Optional result cap (default 3, max 50) |

**Locations:** `dispensa`, `frigo`, `freezer`, `altro`

### `inventory_use` — POST
Consume inventory. Set `use_all: true` to consume all stock at a location.

```json
{
  "product_id": 42,
  "quantity": 1,
  "location": "dispensa"
}
```

```json
{
  "product_id": 42,
  "use_all": true,
  "location": "__all__",
  "notes": "Buttato"
}
```

### `inventory_update` — POST
Update an inventory entry by `id`.

### `inventory_delete` — POST
Remove an inventory entry by `id`.

### `inventory_summary` — GET
Returns item counts per location.

```json
{
  "dispensa": 12,
  "frigo": 5,
  "freezer": 8
}
```

---

## Transactions (Log)

### `transactions_list` — GET
Returns the operation log.

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 50 | Results per page |
| `offset` | int | 0 | Pagination offset |

### `transaction_undo` — POST
Undo a transaction within 24 hours.

```json
{ "id": 873 }
```

**Response on success:**
```json
{ "success": true, "name": "Tonno all'olio d'oliva" }
```

**Error cases:**
```json
{ "error": "...", "already_undone": true }
{ "error": "...", "too_old": true }
```

### `stats` — GET
Returns waste and consumption statistics for the last 30 days.

---

## AI / Gemini

All AI endpoints require `GEMINI_API_KEY` to be configured. Rate limit: 15 req/min.

### `gemini_expiry` — POST
Read an expiry date from a product photo.

```json
{ "image": "data:image/jpeg;base64,..." }
```

### `gemini_identify` — POST
Identify a product from a photo.

```json
{ "image": "data:image/jpeg;base64,..." }
```

### `gemini_chat` — POST
Chat with the AI kitchen assistant.

```json
{ "message": "Cosa posso fare con la pasta?", "history": [] }
```

### `generate_recipe` — POST
Generate a recipe based on current inventory.

```json
{ "persons": 2, "meal": "dinner", "preferences": {} }
```

### `generate_recipe_stream` — POST
Same as `generate_recipe` but streams output via Server-Sent Events.

### `recipe_catalog_search` — GET
Search the durable local recipe catalog by title and ingredient text.

| Param | Type | Description |
|-------|------|-------------|
| `q` | string | Title/ingredient query |
| `sort` | string | `availability`, `expiry`, or `alphabetical` |
| `availability_weight` / `expiry_weight` | integer | Independent 0–100 ranking weights |
| `minimum_coverage` | integer | Minimum required-ingredient coverage percent |
| `expiring_within_days` | integer | Optional expiring-ingredient filter horizon |
| `source` | string | Optional connector filter |
| `locale` | string | Optional locale filter |
| `fields` | string | `card` for compact dashboard results; otherwise `full` |
| `limit` / `cursor` | integer/string | Snapshot-bound pagination |
| `explain` | boolean | Include per-ingredient match explanations |

The response includes a criteria hash, score/catalog snapshot, `next_cursor`,
`has_more`, stable `dedupe_key` values, and explicit read/preview revision
metadata. Ranking/filtering occurs in SQLite
before the page is hydrated. A metadata freshness update that changes effective
recipe visibility increments only the cursor revision; cursors created before that
transition are rejected and callers must restart from the first page. Refreshes that
remain visible or invisible keep the existing cursor revision.
When the development-only score preview is active, cursors are accepted only for
that one configured validated revision; changing or invalidating the setting
rejects older preview cursors.

### `recipe_catalog_suggest` — GET
Compatibility alias for a no-query ranked catalog browse.

### `recipe_catalog_recommendations` — GET
Return 5–100 Food & Recipes carousel cards. The default 30-card mobile response
uses a 40/40/20 availability, expiry, and deterministic-fill mix; wider clients
request a larger total while preserving the same proportions and final display
order.

### `recipe_catalog_get` — GET
Return one normalized catalog recipe by `id`.

### `recipe_catalog_detail` — GET
Return the bounded source-agnostic `recipe_detail_v1` projection for a positive
`id`. The DTO contains:

- `source`, attribution, canonical URL, locale, optional bounded provider
  content-language evidence, per-origin metadata/topology schema versions, and
  image URLs
- `general` yield/unit; prep, cook, active, inactive/rest, and total seconds;
  difficulty; category; supported/required devices; optional devices; and
  additional equipment
- ordered `ingredients` with stable keys, bounded `source_text`, deterministic
  `display_name` (also returned as legacy `name`), display amount facts, mapping
  IDs/labels, current `in_stock|missing|uncertain|staple` inventory states, and an
  additive `provider` object for ingredient/default-title/unit references,
  provider-declared optionality, and shopping-category reference
- additive `ingredient_groups` with stable `rig:*` keys, group/order ordinals,
  ingredient keys/positions, and bounded provider/local labels; the flat ingredient
  array is unchanged
- optional `closest_match` only for `taxonomy_alias`, `taxonomy_slug`, or
  `canonical_slug`; `taxonomy_rule` mappings are omitted regardless of confidence
- sibling `grocery` missing/uncertain/in-stock/staple/eligible counts,
  `max_selections`, and `blocked_reason`
- `instructions` (including optional local group-to-step-position references), user
  state, freshness, inventory/ranking/catalog revision, and
  capability enums (`general`, `ingredients`, `instructions`, `quantities`,
  `grocery_add`, `ingredient_feedback`, `ingredient_feedback_v2`, `planner`),
  `score_preview` capability/diagnostics, and explicit active
  versus preview score/ontology IDs

Cookidoo always returns:

```json
{
  "available": false,
  "reason": "provider_external_only",
  "steps": [],
  "fallback_url": "https://cookidoo.co.uk/recipes/recipe/en-GB/example-id",
  "truncated": false
}
```

The optional instruction `groups` property is omitted for Cookidoo. Cookidoo
ingredient groups use bounded provider titles when present and null when empty.
Cookidoo source amounts set `quantity_state: "display_only"` and
`quantity_sufficiency: "unknown"`. Local/manual/generated variants may return their
authorized stored steps, bounded instruction group labels, and genuinely known
ranking quantities. Provider `optional: true` is optional; false or null remains
assumed required. Only exact, pantry-descendant, or normalized-name
inventory relations can report `in_stock`; contains or broader evidence reports
`uncertain`.

Ingredient rows add `feedback_token`, `user_override`, `identity_feedback`, and
`feedback_capabilities`. Overrides are display-only evidence and never replace
`inventory.state` or alter `grocery.eligible_count`.

### `recipe_catalog_ingredient_override` — POST
Persist `have`, `missing`, or `clear` for one ingredient key/position and current
feedback token. The action is idempotent, rejects stale evidence with HTTP 409,
and does not mutate inventory, ranking, ontology, or grocery truth.

### `recipe_catalog_identity_feedback` — POST
Record `correct` or `wrong` for the displayed `matched_product` or
identity-safe `closest_match`. Events are append-only, revision-bound, and settle
for 14 days before proposal-only export. No event applies an ontology change.

### `recipe_catalog_ingredient_decision` — POST
The v2 command boundary accepts exactly one action:

- `assume_have`: availability `have`; no identity evidence or proposal row
- `select_inventory_product`: availability `have`; positive evidence bound to
  the exact selected currently stocked product; immediate
  `must_equal(subject,target)` constraint
- `reject_current_match`: availability `missing`; negative evidence only when
  the exact displayed product still matches; otherwise availability-only;
  immediate `must_not_equal(subject,target)` constraint

The command revalidates the feedback token, active score/ontology,
inventory/catalog revisions, selected product stock, and expected negative
target under one SQLite transaction. Drift returns HTTP 409
`ingredient_feedback_stale` with no writes. Provenance retains the v1 source hash
and product ID while adding `source_fingerprint_v2`, ontology owner-derived
`target_owner_fingerprint`, action origin, observed revisions, and deterministic
supersession. The same transaction appends an immutable controller observation,
advances the monotonic constraint epoch, deactivates the prior live constraint
for the stream, and queues one fenced autonomous job. `assume_have` clears prior
identity intent without creating a new identity constraint.

Positive/negative identity events enqueue a proposal outbox row and candidate
regression fixture in the same transaction. `scripts/recipe-ingredient-proposals.php`
claims bounded rows, persists immutable prompt/manifest/raw-response artifacts,
uses the exact configured ontology proposal model without fallback, and only
stages existing closed-set-validator results. Missing keys/models/network remain
durable blocked/retry states. The `export`/`import` commands support an
operator/Copilot artifact handoff when the deployed process cannot call Gemini.
That legacy proposal path retains its 48-hour negative handoff delay and has no
automatic activation path.

The separate default-off autonomous controller stages only against a forked
building child. It applies closed repairs deterministically, evaluates every
live exact constraint through the actual matcher, validates graph/gold/source/
materialization/blast/rollback gates, and can atomically activate only when its
promotion feature flag is enabled. Disagreement abstains and unsafe work is
quarantined; quarantine isolates the mutation, never the subject. Missing model
output receives an unresolved non-satisfying provisional mapping and bounded
retry state.

### `ontology_controller_status` — GET
Returns runtime/model/promotion flags, configured provider/model and local
provider health, a cached/stale non-prepared owner coverage summary,
resolution/mapping/provisional counts, the configured intake minimum priority
plus eligible/historical pending counts, quarantine/retry totals, and active
benchmark policy metadata for each risk tier. `include_coverage=1` never scans
the corpus synchronously and is honored only for authenticated admin requests.

### `recipe_catalog_planner_add` — POST
Assign a stored Cookidoo-origin recipe to an ISO date from today through 365 days.
The request includes only `recipe_id`, a revision-bound
`provider_action_token`, date, and idempotency key; EverShelf resolves the
provider external ID. Commands are journaled before the network call and never
hold SQLite over I/O. Capability `recipe_planner_v1` is absent unless the
EverShelf app gate and authenticated bridge gate both report known
`append|replace` semantics. The bridge reads before writing, verifies with a
fresh read, preserves all preexisting IDs, reconciles ambiguous timeouts,
retries one stale authentication, and opens a circuit on 403/429. This is an
account planner action, not direct device push.

Primary ingredient and grocery labels always come from conservative source-text
cleaning/casing. Canonical/taxonomy labels are secondary metadata and never replace
the display label. `capabilities.grocery_add` reports feature support for a complete
nonempty list, not whether `missing_count` is greater than zero. No ingredients and
truncated ingredients are distinct blockers; an all-in-stock or uncertain-only
complete list still reports capability `true`.

### `recipe_catalog_grocery_add` — POST
Recheck a recipe and add only selected ingredients that are still genuinely missing
to EverShelf's internal shopping list. The action never calls Home Assistant and
never interprets source amount text as a list quantity. Uncertain items are
ineligible. Mapping IDs dedupe only for identity-safe alias/slug sources; otherwise
the normalized source-derived name is used.

```json
{
  "recipe_id": 123,
  "idempotency_key": "ha-01J...",
  "selections": [
    { "key": "ri:2:0123456789abcdef", "position": 2 }
  ]
}
```

`ingredient_keys` or `positions` arrays are also accepted (maximum 100 selections).
The bounded result contains `added`, `already_listed`, `now_in_stock`, `unresolved`,
or `failed` per item plus normalized names/amount text for a caller to mirror.
Replaying the same key and normalized selector payload returns the stored outcomes
before mutable recipe metadata is revalidated, so an exact retry remains stable
after ingredient reorder or removal. Reusing a key for a different payload returns
HTTP 409. Idempotency records have a 30-day retention window from the original
command; older records may be pruned by later grocery commands.

### `recipe_catalog_discover` — POST
The action always returns local catalog results and may queue bounded Cookidoo
network discovery when the connector and detail hydration gates are enabled.
Only allowlisted factual metadata is imported. Provider execution requires the
bridge to report `metadata-v3-operator-enabled`; cached stale results remain
visible while refresh is queued.

### `recipe_jobs_status` — GET
Read one background job by `id`/`idempotency_key`, list recent jobs, or pass
`search_id` to receive aggregate hydration status, queue position, polling delay,
exhaustion state, and compact imported/updated cards. When the detail gate is
disabled, Cookidoo network jobs remain pending without provider or connector
accounting. Claimed work uses opaque leases and request-order fences; lease tokens
are never returned by this endpoint.

### `recipe_connectors` — GET
List connector capabilities, enabled/configured state, and circuit-breaker health.
Cookidoo reports its detail/discovery gate, policy version, cached-catalog read,
canonical-link, and external-instructions-link capabilities.

`ha_info` advertises `recipe_detail_v1`, `recipe_grocery_v1`,
`recipe_ingredient_feedback_v1`, and `recipe_ingredient_feedback_v2` alongside
`recipe_catalog_v2`. `recipe_planner_v1` is dynamic and absent under default
planner gates.

### Ingredient ontology v3 operator CLI (no public HTTP mutation)

`scripts/ingredient-ontology-v3.php` supports `build-candidate`, `audit`,
`build-shadow`, `report`, `validate`, `prompt`, `stage-proposals`, `reject`,
`dispose`, `revert`, `activate`, and `rollback`. Mutating commands require
`--write`; activation additionally requires
`--confirm-activate=<revision-id>`. The default/help command never activates.

Candidate builds account for every product, `recipe_ingredients` row, and
`recipe_source_ingredients` row with an accepted/candidate/ambiguous/unresolved/
rejected assertion. Audits stream every product and distinct label plus mechanism,
language, facet, status, delta, and false-positive-cluster counts. Shadow reports
include coverage/cookability/rank changes, every changed currently-cookable recipe,
all labels with frequency at least 100, and product mapping changes.

There is intentionally no model API call or auto-apply endpoint. Copilot-produced
JSON may only be staged after closed-set/schema/evidence/direction/retail/cycle
validation. V2 API behavior remains active until a manually validated score revision
is activated. Responses expose a nullable ontology version only through the active
score revision.

V3 explanations preserve `required`, `optional`, and `staple` flags, report
optional unmatched rows separately, and expose bounded compatible row/product
counts plus the minimum compatible expiry. The existing API `explain` default and
cursor semantics are unchanged.

After an initial manual v3 activation, the normal minute rebuild command preserves
that exact ontology version, reuses same-input ready revisions, and atomically
activates only a freshly validated replacement. It never falls back to legacy v2.
Request reads serve a stale active v3 revision without forcing rebuild. Pruning
keeps an eight-ancestor rollback window plus four recent ready-v3 revisions and
the latest same-parent candidate. Proven ancestors may be restored while stale;
non-ancestors must be current-parent v3 children and pass normal activation.
`revert` safely withdraws pending/approved sets, is audited/idempotent once
reverted, and still rejects applied sets without inverse provenance.

### `recipe_catalog_favorite` — POST
Set or toggle catalog favorite state with `{ "id": 123, "favorite": true }`.

### `gemini_product_hint` — POST
Get AI storage location + shelf-life hint for a new product.

### `location_suggestion` — POST
Resolve the default storage location for an item without applying page-specific
fallbacks. Exact barcode history wins first. In `manual` mode, an exact
case-insensitive product-name history match is checked next. Unseen products use
a cached Gemini classification and may return `unknown`.

```json
{
  "mode": "manual",
  "name": "Milk",
  "barcode": "",
  "category": "Dairy"
}
```

Example response:

```json
{
  "success": true,
  "location": "frigo",
  "source": "history_name",
  "confidence": 1
}
```

### `gemini_shopping_enrich` — POST
Enrich shopping suggestions with practical tips.

### `gemini_anomaly_explain` — POST
Get a plain-language explanation for a specific inventory anomaly.

---

## Shopping List (Bring!)

Requires `BRING_EMAIL` and `BRING_PASSWORD` in `.env`.

### `bring_list` — GET
Get the current Bring! shopping list.

### `bring_add` — POST
Add items to the Bring! list.

```json
{ "items": ["Latte", "Pane"] }
```

### `bring_remove` — POST
Remove an item from the Bring! list.

```json
{ "name": "Latte" }
```

### `smart_shopping` — GET
Get smart shopping predictions based on consumption history.

---

## Settings

### `get_settings` — GET
Returns current settings as **boolean flags only** (no raw key values):

```json
{
  "gemini_key_set": true,
  "bring_configured": false,
  "tts_enabled": false,
  "scale_enabled": true,
  "demo_mode": false,
  "settings_token_set": true
}
```

### `save_settings` — POST
Update server configuration. If `SETTINGS_TOKEN` is set, requires header:

```
X-Settings-Token: your_token
```

```json
{
  "gemini_api_key": "...",
  "bring_email": "...",
  "scale_enabled": true,
  "scale_gateway_url": "ws://127.0.0.1:8765"
}
```

---

## Error Reporting

### `report_error` — POST
Submit an automatic error report (creates a GitHub Issue).

```json
{
  "type": "uncaught-error",
  "message": "...",
  "stack": "...",
  "context": {}
}
```

Only creates an issue if:
- The client is running the latest released version
- The fingerprint hasn't been seen in the last 24 hours

---

## Anomaly Detection

### `inventory_anomalies` — GET
Returns inventory rows where stored quantity significantly differs from transaction history.

### `dismiss_anomaly` — POST
Dismiss an anomaly banner without changing inventory.

---

## Scale Integration

### `scale_relay` (SSE) — GET
Relays BLE scale readings from the gateway to the browser via Server-Sent Events (avoids HTTPS→WS mixed-content issues).

### `scale_ping` — GET
Check if the Scale Gateway is reachable.

### `scale_discover` — GET
Scan the local LAN for a running Scale Gateway instance.
