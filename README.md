# Mibizum Sync — Magento 1.x / OpenMage module

The `Mibizum_Sync` module is installed in the merchant's Magento 1.9 / OpenMage
20.x store to connect its catalog with [Mibizum](https://mibizum.io) search.

## Status

`v0.6.3` - **production-ready and distributable** through all the standard
Magento 1 channels (direct, two-phase FTP, Magento Connect, Composer, modman).
Full safe-disable hardening. Packaging, install, uninstall and configuration
guides live in the [project wiki](https://github.com/Mibizum/magento-openMage/wiki).

## What it does

Keeps the tenant's search index in sync with the merchant's Magento catalog.
A **queue + retry + backoff** pattern that tolerates transient failures without
breaking catalog saves.

**Flow:**

```
[Magento admin] saves a product
   └─► Observer.onProductSaveAfter  (short-circuit if !isEnabled())
       └─► Mibizum_Sync_Model_Indexer_Queue::enqueueProductUpdate(productId)
           └─► mibizum_sync_index_queue table (coalesce by UNIQUE(product_id, operation))

   every 5 min, cron drainQueue → Worker:
       1. claimBatch: reserves N unlocked entries (optimistic lock by token)
       2. loads products in batch → ProductMapper → Adapter → POST /api/v1/index
       3. OK: complete() deletes rows; KO: fail() releases the lock + stores last_error
       4. after max_attempts → the entry is discarded (dead-letter)
```

**Observers:**
- `catalog_product_save_after` → upsert
- `catalog_product_delete_before` → delete (captures the id before deletion)
- `cataloginventory_stock_item_save_after` → upsert (stock affects the "Out of stock" badge)

**Defense**: every observer short-circuits if the connection is not active
(`isEnabled()`) and runs inside try/catch - a failure while enqueuing **NEVER**
breaks the merchant's save. Syncing with the SaaS never takes priority over the
merchant's business.

## Module structure

```
src/app/
├── etc/modules/Mibizum_Sync.xml                          ← module activator
└── code/community/Mibizum/Sync/
    ├── etc/{config,system}.xml                           ← config + admin screen
    ├── Helper/Data.php                                   ← config getters (isEnabled…) + badges + log
    ├── Block/Frontend/Config.php                         ← injects the widget snippet into <head>
    ├── Model/
    │   ├── Observer.php                                  ← save/delete/stock observers (+ config-saved)
    │   ├── Scheduler.php                                 ← fullReindex + drainQueue + applyEngineSettings
    │   ├── Indexer/{Queue,Worker,ProductMapper}.php      ← DB queue + worker + product→doc mapping
    │   ├── Search/Adapter.php                            ← HTTP client /api/v1/search (server-side Enter)
    │   ├── Adapter/Mibizum.php                           ← HTTP client /api/v1/index (indexer)
    │   ├── NativeSearchBridge.php                        ← Enter rewrite → Mibizum engine (MySQL fallback)
    │   └── … (Nature/AttributeBadge/SystemOverride + Resource/)
    ├── controllers/Adminhtml/Mibizum/Sync/…Controller.php ← grids/forms + Reindex (full/drain/stats)
    └── sql/mibizum_sync_setup/                            ← install-0.1.0 + upgrades up to 0.6.1
```

(Tables: `mibizum_sync_index_queue`, `mibizum_sync_attribute_config`,
`mibizum_sync_nature_badges` (+`_categories`), `mibizum_sync_attribute_badges`
(+`_categories`), `mibizum_sync_system_badge_overrides`.)

## Installation

### 1) Package and pick a channel

```bash
bash packages/adapter-magento/scripts/package-mibizum-sync.sh
```

Generates 4 artifacts in `dist/` (direct, two-phase FTP, Magento Connect). For
FTP, use the two-phase pattern (upload everything except the activator and, at
the end, only the activator) (the why and the exact steps for each channel
(including Composer and modman) are in the
[Installation wiki](https://github.com/Mibizum/magento-openMage/wiki/Installation)). The
simplest one if you have shell access:

```bash
tar -xzf packages/adapter-magento/dist/Mibizum_Sync-<ver>.tgz -C /path/to/magento/
```

### 2) Flush the cache + permissions

```bash
ssh merchant-server "cd /var/www/magento && \
  rm -rf var/cache/* var/full_page_cache/* && \
  chown -R www-data:www-data app/code/community/Mibizum app/etc/modules/Mibizum_Sync.xml"
```

### 3) Verify the install

In the Magento admin, **System > Configuration > Advanced > Advanced** →
"Mibizum_Sync" must appear as active.

If it is, the install script `install-0.1.0.php` runs automatically when the
cache is flushed and creates the module tables.

### 4) Configure the API key

In the admin: **System > Configuration > MIBIZUM > Mibizum Sync**:

| Field | Value |
|---|---|
| **Enabled** | No (not yet!) |
| **API URL** | `https://app.mibizum.io` |
| **API key** | (paste your tenant key - found in the panel: Data sources → your source → API keys; it must have the `indexer` scope) |
| **Data source slug** | (empty if you only have one catalog) |

### 5) Test connection

Press the **"Test connection"** button on the same page. It should show:

> Mibizum: connection OK. Tenant: YourStore (your-slug)

If it errors, check the API key and the URL. **Do not enable the module yet**
if the test fails.

### 6) Enable + initial Resync

1. Switch **Enabled** to **Yes** and **Save Config**.
2. Press **"Resync all products"** - enqueues ALL active products. Takes seconds.
3. Press **"Drain queue now"** or wait for the cron (every 5 min).
4. Check `var/log/mibizum_sync.log` to follow the progress.
5. Open your widget in the panel's `/widget-studio` - you should see your real products.

### 7) Magento cron

Make sure Magento's `cron.php` is scheduled (most installs already have it):

```cron
*/5 * * * * /usr/bin/php /var/www/magento/cron.php >> /var/log/magento-cron.log 2>&1
```

The module declares its own `mibizum_sync_drain` job that Magento runs every 5 min.

## Multi-store / multi-language

A single Magento install with several websites or store-views maps to Mibizum as
**one data source (catalog) per store-view**. A store-view is "one catalog in one
language" (translated attributes, store price, URLs), so each gets its own search
index, badges, rules and Smart item.

### How to set it up

All Connection and Frontend settings are **per-store-view scoped**. In
**System > Configuration**, switch the scope selector (top-left) to each
store-view and set:

- **MIBIZUM > Mibizum Sync > Connection**: Enabled, API URL, **API key (indexer)**,
  **API key (search)** and **Data source slug** of THAT store-view's catalog.
- **MIBIZUM > Mibizum Sync > Frontend > Widget snippet**: paste the snippet whose
  `search_key` belongs to that store-view's catalog.

Store-views that share the same API key + URL + slug are treated as the same
catalog, so a single-store merchant just configures the Default scope once.

### What happens automatically

- **Indexing fans out**: every saved/stock-changed product is published into each
  connected store-view's catalog, mapped in that view's scope (translated name,
  store price, store URL) and respecting website assignment.
- **Connection change -> reindex**: saving a store-view's Connection (key/slug)
  enqueues a full reindex so the affected catalog is (re)populated. Unrelated
  setting changes do not.
- **Frontend search per view**: the Enter override (`/catalogsearch/result/`) and
  the widget query the current store-view's catalog with its own search key.

### Overview

The **MIBIZUM > Mibizum Sync** Reindex screen shows a **Connected catalogs** table
when the install has more than one store-view: website, store-view, catalog slug
and status (Connected / Not configured / Disabled), plus a warning if a store-view
is enabled but missing its key/URL.

See the [Multi-store wiki page](https://github.com/Mibizum/magento-openMage/wiki/Multi-store)
for a non-technical explanation to share with merchants.

## Operation

### Logs

Everything from the module goes to `var/log/mibizum_sync.log` (it does NOT
pollute `exception.log`). To tail it in real time:

```bash
tail -f var/log/mibizum_sync.log
```

### Inspect the queue

```sql
SELECT queue_id, operation, product_id, reason, attempts, last_error,
       enqueued_at, locked_at, locked_by
FROM mibizum_sync_index_queue
ORDER BY enqueued_at DESC
LIMIT 20;
```

- `locked_at IS NULL` → pending, ready to drain.
- `locked_at IS NOT NULL` → reserved by a worker. If the worker died, the lock
  recycles itself after 5 min (`claimBatch` releases expired locks).
- high `attempts` + `last_error` → it has failed several times; once it exceeds
  `max_attempts` the entry is **discarded** (not kept as a persistent dead-letter).

### Force a retry of stuck entries

After fixing the root cause (key, connectivity, etc.), release any stuck lock and
drain again:

```sql
UPDATE mibizum_sync_index_queue
SET locked_at = NULL, locked_by = NULL
WHERE locked_at IS NOT NULL;
```

Then press **Drain queue now** or wait for the cron. If you need to re-publish
everything, use **Reindex now** (enqueues all visible products).

### Full resync

When you make mass changes to the catalog (CSV import, etc.), press **Resync all
products** from the admin. It enqueues all active products without clearing the
index (products stay accessible during the re-sync; upsert into the index).

## Safe-disable (the store never breaks)

The module is designed so that **disabling it or leaving it unconfigured never
takes the store down**:

- **Disabled** (`<active>false</active>` in the activator, or no activator):
  Magento does not parse `config.xml`, so the search rewrite, the observers and
  the cron do not exist as far as Magento is concerned. The Enter key
  (`/catalogsearch/result/`) falls back to the native MySQL search.
- **Enter rewrite:** falls back to `parent::prepareResult()` (native MySQL) if
  the connection is not active, if the query is empty, or on any engine error
  (5xx/network). It never returns a 500.
- **Observers, Cron and Worker:** short-circuit if `isEnabled()` is false
  (`connection/enabled` + API key + API URL). With no connection they do not
  enqueue, drain or touch `mibizum_sync_index_queue`. (`general/enabled` is
  independent: it only controls the frontend widget.)
- **Idempotent setup/upgrade** (`isTableExists` / `tableColumnExists`) and
  runtime DB access tolerant of missing tables (`try/catch` → degrade to empty).

Reversible pause: **Connection → Enabled = No** (stops sync) or
`scripts/uninstall-mibizum-sync.sh /path/to/magento --disable-only`.

## Uninstall (without leaving junk)

Script (recommended): reads `local.xml`, disables, cleans the DB and deletes
files. Always try it first with `--dry-run`.

```bash
bash packages/adapter-magento/scripts/uninstall-mibizum-sync.sh /path/to/magento --dry-run
bash packages/adapter-magento/scripts/uninstall-mibizum-sync.sh /path/to/magento
# options: --yes  --keep-files  --keep-db  --disable-only
```

Manual: (1) remove the activator, (2) run `scripts/uninstall-mibizum-sync.sql`
(replace `@PREFIX@` with your `table_prefix`) (`DROP TABLE IF EXISTS` of the
module tables + `DELETE` from `core_config_data` (`mibizum_sync%`,
`mibizum_sync_badges%`) + delete `mibizum_sync_setup` from `core_resource`),
(3) delete the files, (4) flush `var/cache`). Full detail in the
[Uninstall wiki page](https://github.com/Mibizum/magento-openMage/wiki/Uninstall).

## Trade-offs and limitations

- **Magento 1.9 / OpenMage 20.x only.** Magento 2 → a dedicated effort.
- **The Mibizum id is the SKU.** If the merchant changes a product's SKU, the old
  one is orphaned in the index. Run **Resync all** after mass renames. (Future
  improvement: capture the old SKU and send an explicit DELETE.)
- **No tracking of images external to Magento.** If the catalog uses a custom
  CDN, adjust `Mapper::_resolveImageUrl`.
- **Optional featured attribute.** If the merchant does not have `featured` as a
  product attribute, it is not sent (the "Featured" badges do not appear).
- **Batch DELETE not supported.** Each delete is an individual HTTP request. In
  stores with thousands of products deleted at once (rare), it is slow. Future
  improvement: a backend `DELETE /api/v1/index/batch` endpoint.
- **No panel metrics yet.** The merchant cannot see from the panel how many
  products are indexed or the queue health. Future improvement: a
  `GET /api/v1/index/stats` endpoint + UI in Data sources.

## Compatibility

- Magento 1.9.x (mage1)
- OpenMage LTS 20.x (same module, no changes)
- Magento 2.4.x - **NOT compatible** (a completely different structure; its own effort)

## Roadmap

- ✅ Server-side Enter override (Mibizum engine + MySQL fallback).
- ✅ Panel-sync of attributes/badges from the admin (no XML editing).
- ✅ Distribution through all channels (direct, two-phase FTP, Magento Connect,
  Composer, modman) + clean uninstall + safe-disable hardening. **(v0.6.1)**
- ✅ Module UI localized (es/de/fr/it/pt_PT/pt_BR), shipped in every channel.
- ✅ Multi-store / multi-language: one catalog per store-view, per-view indexing
  fan-out, reindex on connection change, connected-catalogs admin overview. **(v0.6.2)**
- Pending: per-language engine settings (backend); index metrics in the merchant
  panel; port to Magento 2.

## Frontend widget injection (design principle)

When the module injects the search widget into the merchant's storefront, it
follows a "thin-shim" pattern so a transient outage of the SaaS can never slow
down or break the merchant's store:

### 1. `<script async>` always, NEVER synchronous

```html
<link rel="preconnect" href="https://app.mibizum.io" crossorigin>
<script async src="<panel-cdn-or-skin>/mibizum-widget.js"></script>
```

Without `async`, the merchant's HTML parser blocks until the JS is downloaded.
This is the standard pattern for injected e-commerce search widgets: never synchronous.

### 2. Zero PHP `curl_exec` to the SaaS during page render

`Block/Frontend/Config.php` ONLY injects what comes from a local source (the
merchant's DB, system.xml, PHP helpers with no network). Any data from the SaaS
(engine credentials, rules, boosts, runtime settings) is fetched by the JS on the
client via:

```js
fetch('https://app.mibizum.io/api/widget-bootstrap?source=<slug>', {
  signal: AbortSignal.timeout(2000),
  credentials: 'omit',
  mode: 'cors',
})
```

If the fetch fails (SaaS down, the SaaS IP accidentally blocked by the merchant's
fail2ban/Cloudflare WAF, etc.) → `console.error` + `return`. Magento's native
input keeps working. The store does NOT go down.

### 3. Why no `Bearer` token in the bootstrap

Any token exposed to the JS is effectively public (DevTools). The public endpoint
filters secrets out of the response: only `host`, `index`, `search_key`
(read-only), `settings`, `rules`, `boostRules`. Never `index_key`,
`integration_token` or `inbound_secret`.

### 4. The guiding rule

No merchant that installs the adapter should be able to fall into a slow-page
trap. Even if they block the SaaS IP without realizing it, their store keeps
loading instantly; only "the smart search" degrades to "Magento's native search"
until the misconfiguration is fixed.
