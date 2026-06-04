# Changelog — Mibizum_Sync (Magento 1 / OpenMage)

All notable changes to the Magento 1 / OpenMage module are documented here.

## 0.6.9

### Added — Smart Items (ingredient) public ficha, folded into this module

The public Smart Item ficha (`/ingredientes/{slug}` — e.g. an ingredient page
with status, description and recommended substitute) now ships **inside
Mibizum_Sync** instead of a separate `Mibizum_Ingredient` module. This is the
**thin (Option B)** port: the Mibizum panel remains the single source of truth
for Smart Items; the module only RENDERS the public ficha, reading from
`GET /api/v1/smart-items/{slug}` on the SaaS and reusing the module's existing
Connection config (API URL, public key, data source slug).

- New clean-URL router `Mibizum_Sync_Controller_IngredientRouter`, registered on
  `controller_front_init_routers`. It runs on every storefront request, so it is
  fully defensive — returns false fast for any non-ingredient URL and never
  throws — and is gated by a master toggle (`mibizum_sync/frontend/ingredient_enabled`).
- `Mibizum_Sync_IndexController::viewAction` renders the ficha;
  `Mibizum_Sync_Block_Frontend_Ingredient` + `template/mibizum_sync/ingredient/view.phtml`
  draw it; `Mibizum_Sync_Helper_Ingredient::fetchSmartItem()` fetches + briefly
  caches the SaaS response. Unknown slugs 404.
- The URL prefix is configurable (`mibizum_sync/frontend/ingredient_url_prefix`,
  default `ingredientes`). No DB schema change (Smart Items live in the SaaS DB).

Note: managing Smart Items (create/edit, statuses, substitutes) stays in the
Mibizum panel — one source of truth that scales across platforms. The full
Magento admin from the old standalone module is intentionally not bundled.

### Added — Reindex console with live progress

The admin **Reindex** panel (System → Configuration → Mibizum Sync → General
configuration) is now **non-blocking** and shows real-time progress, so the
merchant can see that products are being uploaded instead of staring at a frozen
request:

- **Buttons lock while a reindex runs.** *Reindex*, *Process pending* and
  *Refresh status* are disabled together so a second run can't be launched on top
  of the first.
- **Live spinner + counter.** A progress bar and a `done / total (NN%)` label
  update roughly once per second.
- **Completion notice.** When it finishes, a success message ("Reindex complete:
  N product(s) indexed.") is shown — or, if any document failed, a warning with
  the error count — and the buttons become operational again.
- **Resilient to reloads.** If the merchant refreshes the page mid-reindex, the
  console re-attaches to the running drain instead of looking idle. A stale
  "running" flag self-heals on the next poll.

**How it works.** `ReindexController::fullAction` now *enqueues* the catalog and
returns immediately for AJAX callers (the on-screen console); a new
`progressAction` drains one small batch per poll and reports `{done, total,
pending, indexed, failed}`. A non-JS navigation still falls back to the classic
synchronous full reindex. New helper: `Scheduler::enqueueFullReindex()`. No DB
schema change.

### Fixed — products with accented SKUs silently dropped from the index

Meilisearch document ids only accept `[A-Za-z0-9_-]`. A product whose **SKU
contains an accent** (e.g. `AES-LAVESPAÑA`, `FRA-PIÑA`) produced an invalid id,
and Meilisearch **rejects the entire batch** that the bad document travels in —
so ~50 *other*, perfectly valid products were silently dropped from the index on
every reindex (symptom: fewer products in the index than in the catalog, with no
visible error). Fixed by `ProductMapper::sanitizeDocId()`, which transliterates
common Latin accents and replaces any remaining invalid character before using
the value as the engine primary key. The **original SKU is preserved** in the
document's `sku` field for display and click attribution; the delete path
(`Worker`) applies the same transform so removals still match.

### Added — system badges in the search widget

System badges (out of stock / low stock / on sale / new / featured) now reach the
search overlay. `ProductMapper` resolves the applicable badges per product from
its live state + the merchant's visual overrides and publishes them in the
document's generic `_badges` array, which the SDK renders with full visuals
(icon, shape, position, display mode). The **out-of-stock** badge is driven only
by the authoritative `is_in_stock` flag — never by `qty` — because shops that do
not track per-unit stock keep many AVAILABLE products at `qty=0` with
`is_in_stock=1`; the **low-stock** badge requires a tracked `qty > 0` at/below the
threshold so those qty=0 available products never flip to "Last units". Because the widget renders inside a shadow
root that isolates CSS, **FontAwesome icon classes cannot resolve there** — the
`0.6.8 → 0.6.9` upgrade backfills inline-SVG equivalents for the default-iconed
badges (empty battery / double chevron / gift) when the merchant has not set an
`icon_svg`, and leaves merchant-customized rows untouched.

---

_Earlier versions: see the module's `etc/config.xml` `<version>` history and the
`sql/mibizum_sync_setup/` upgrade scripts._
