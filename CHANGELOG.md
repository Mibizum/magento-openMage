# Changelog — Mibizum_Sync (Magento 1 / OpenMage)

All notable changes to the Magento 1 / OpenMage module are documented here.

## 0.7.9

### Changed — debounced reindex after an image cache flush
Flushing the **Catalog Images Cache** no longer triggers a reindex on every flush.
The module now waits until the flush activity has settled (a short debounce) and then
runs a single, memory-safe reindex (queue + cron drain), so the resized thumbnails the
search index points at are regenerated without 404s — never on each click or while the
cache is still being regenerated. Repeated flushes in a short window are reported to
Mibizum as unusual activity.

## 0.7.8

### Added — auto-reindex after an image cache flush
Flushing the Catalog Images Cache deletes the resized image derivatives that the search
index references. The module now observes that event and re-syncs the catalog so search
thumbnails stay valid.

## 0.7.7

### Changed — Smart Items (formerly "Ingredients") + product page redesign
The Ingredients feature is renamed to **Smart Items** across the admin and storefront
(spanning 0.7.5–0.7.7). The existing `ingredientes` front URL is preserved, so there are
no broken links. The Smart Item detail page was redesigned (roadmap + photo).

## 0.7.4

### Added — live reindex progress
The reindex admin screen now shows live progress ("Reindexing… N of M") while a full
reindex runs.

## 0.7.3

### Added — remote catalog resync command polling

The module can now receive a remote `resync_catalog` command from Mibizum and
run a full catalog reindex from the merchant's own Magento cron, without opening
an inbound connection to the store.

- Adds `GET /api/v1/commands?source=...` polling and
  `POST /api/v1/commands/:id/ack` acknowledgement in the Mibizum adapter.
- Adds `Scheduler::pollRemoteCommands()`, executed by cron, to process pending
  commands.
- Unknown commands are acknowledged and ignored, so newer backend commands do
  not trap older modules in a retry loop.
- The command is best-effort and scoped to the configured data source slug.

Code-only; no DB schema change.

## 0.7.2

### Fixed — native search bridge and badge table self-healing

- Hardens the native Magento search fallback around `catalogsearch_result` so a
  store with a non-standard/ghost foreign key does not break the Mibizum Enter
  override.
- Makes the three badge admin controllers validate POST + `form_key`.
- Adds idempotent self-healing for the badge tables so missing rows are restored
  safely during upgrade.

## 0.7.1

### Fixed — storefront-ready cached product images

Search documents now use Magento's cached/resized catalog image helper
derivative instead of the original raw media file. The mapper prefers the same
storefront-ready image chain Magento would serve to visitors, with
`image`/`small_image`/`thumbnail` fallback.

This avoids loading unnecessarily heavy original product images in the Mibizum
search overlay and reduces visitor bandwidth.

Code-only for normal upgrades; includes compatibility upgrade scripts for stores
coming from 0.6.10 or 0.7.0.

## 0.7.0

### Added — multistore (multi-store-view) support

For merchants running several store views / sites from one Magento install
(first client: tiendafetichista.com + sexshopsgay.com).

- **Scope separation.** Connection and reindex configuration is now **per
  store-view** (`show_in_default=0`): `enabled`, `api_key`, `search_api_key`,
  `data_source_slug`, the widget snippet, and the whole **Reindex** panel. Each
  store view points at its own catalog with its own keys, and the Reindex panel
  is only shown in store-view scope — you can no longer reindex with another
  store's keys. `api_url`, `debug_mode` and `sync/*` remain global.
- **Per-store-view pause/resume.** A paused store-view's destination is filtered
  out **before** the queue batch is claimed (the queue stays intact), and the
  drain stops between batches when every destination is paused. Admin
  `pause`/`resume` actions + UI banner/buttons, scoped to the current store view.
- **Per-store URL/media correctness.** Each product is mapped in its own
  store-view scope (`setStoreId` + `setCurrentStore` per destination, restored
  afterwards), so a product's link and image resolve to **its** store's domain —
  not the first store's. This was the core multistore bug.

Includes the module side of the **file-based bulk ingest** (Cloudflare-safe
reindex: one upload instead of dozens of HTTP requests). The document-id scheme
is unchanged (`sanitizeDocId`), so **no index clear/reindex is required** on
upgrade. Code-only; no DB schema change.

## 0.6.10

### Added — reindex telemetry reaches the Mibizum Superadmin

The progress-tracked reindex now reports to the backend (`POST /api/v1/sync-runs`)
a **`running`** event when it starts and the **terminal** event (success/partial/
failed + item counts + duration) when it finishes, reusing the backend run id to
close it precisely. This powers the Mibizum Superadmin's **"reindex in progress
per tenant"** view. Best-effort and backward-compatible: an old backend without
the endpoint still 404s silently. The classic synchronous `fullReindex()` already
reported the terminal event; nothing regresses there.

### Fixed — AJAX reindex now applies the engine schema (parity)

The non-blocking console reindex now calls `applyEngineSettings()` first (best-
effort), matching the classic `fullReindex()`. Previously a console reindex
skipped the attribute-schema sync, so a newly-added searchable/filterable
attribute could 400 and fall back to MySQL until the next cron/CLI reindex.

### Added — SKU collision warning

The `Worker` now logs a **WARN** when two distinct SKUs sanitize to the same
Meilisearch document id (e.g. `FRA-PIÑA` vs `FRA-PINA`), where the later one
silently shadows the earlier in the index. Detection is per-batch (best-effort);
the definitive fix (hash-suffixed ids) needs an index clear and is tracked
separately. Code-only; no DB schema change.

## 0.6.9

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
