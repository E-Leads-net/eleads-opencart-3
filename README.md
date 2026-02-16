# E-Leads — Module for OpenCart 3.x

## Overview
E-Leads adds:
- product export feed (YML/XML),
- product sync with E-Leads API (create/update/delete),
- SEO pages (`/e-search/...`),
- filter landing pages (`/e-filter/...`),
- module self-update from GitHub.

## Compatibility
- OpenCart: 3.x
- PHP: according to OpenCart 3.x requirements

## Installation
1. Admin → **Extensions → Installer**.
2. Upload: `eleads-opencart-3.x.ocmod.zip`.
3. Admin → **Extensions → Modifications** → **Refresh**.
4. Admin → **Extensions → Extensions → Modules** → **E-Leads** → Install/Edit.

## Feed URL
- `/eleads-yml/{lang}.xml`
- with key: `/eleads-yml/{lang}.xml?key=YOUR_KEY`

Examples:
- `/eleads-yml/en.xml`
- `/eleads-yml/ru.xml`
- `/eleads-yml/uk.xml`

## SEO Mode
### Sitemap
- URL: `/e-search/sitemap.xml`
- Generated when **SEO Pages = Enabled**.
- Contains URLs: `https://your-site.com/{store-lang}/e-search/{slug}`

### SEO Page Route
- `/{store-lang}/e-search/{slug}`
- `/e-search/{slug}`

Page data is fetched from API endpoint `/seo/pages/{slug}` with language.
The module sets canonical to current page and generates `alternate` links from API response.

### Sitemap Sync Endpoint (module)
```http
POST /e-search/api/sitemap-sync
Authorization: Bearer <API_KEY>
Content-Type: application/json
```

Optional query:
- `?lang=<language_label>` (has priority over payload language)

Payload examples:
```json
{"action":"create","slug":"komp-belyy"}
{"action":"delete","slug":"komp-belyy"}
{"action":"update","slug":"old-slug","new_slug":"new-slug"}
{"action":"create","slug":"komp-belyy","lang":"uk"}
{"action":"delete","slug":"komp-belyy","language":"ru"}
{"action":"update","slug":"old-slug","new_slug":"new-slug","lang":"uk","new_lang":"ru"}
```

Rules:
- `action`: `create|update|delete`
- `slug`: required for all actions
- `new_slug`: required for `update`
- source language: `lang` or `language`
- target language for update: `new_lang` or `new_language`
- bearer token must match module API key

### Languages Endpoint (module)
```http
GET /e-search/api/languages
Authorization: Bearer <API_KEY>
Accept: application/json
```

## Filter Mode (E-Filter)
### Route format
Main route:
- `/e-filter`
- `/{store-lang}/e-filter`

With category and selected attributes:
- `/{store-lang}/e-filter/{category}`
- `/{store-lang}/e-filter/{category}/{attribute}-{value}`
- `/{store-lang}/e-filter/{category}/{attribute}-{value}/{attribute2}-{value2}`

Notes:
- URL is path-based (no encoded JSON in path).
- If extra API query is needed, it is passed via GET parameters, not packed into SEO path.
- Category and filter segments are normalized for SEO URL and decoded back on request.

### How data is fetched
On each filter page request, module backend calls E-Leads processing API:
- `GET /ecommerce/search/filters`
- host: `stage-processing.e-leads.net` (configured in `api_routes.php`)

Backend sends:
- language,
- selected category,
- selected attributes,
- pagination/sort/limit,
- project context (`project_id`, if available).

API response is used to render:
- product list,
- available category/attribute facets,
- pagination and sorting UI.

### Index/Noindex rules
Controlled by Filter tab settings:
- **Max Index Depth**: maximum allowed selected-filter depth.
- **Whitelist Attributes**: only these attributes are allowed for indexable depth combinations.

Rule summary:
- over allowed depth → `noindex`
- not allowed attribute combination by whitelist/depth rules → `noindex`
- allowed combination → indexable

Noindex is applied via robots meta tag.

### Dynamic SEO Templates
In Filter tab you can configure per-template:
- category scope (all categories or specific category),
- template depth,
- language tab content,
- fields: `H1`, `Meta Title`, `Meta Description`, `Meta Keywords`, `Short Description`, `Description`.

Variables are supported (`{$category}`, `{$attribute_name}`, etc.) and rendered from current filter state.

## Product Sync Behavior
- Product create/update/delete sends one request per available language version.
- Delete sends for all enabled store languages.
- Payload is generated in target language context.

## Admin Tabs
1. **Export**: feed settings, categories, attributes/options, group mode, shop fields, sync toggle.
2. **Filter**: E-Filter enable, max depth, whitelist attributes, dynamic SEO templates.
3. **SEO**: SEO Pages enable + sitemap URL.
4. **API Key**: token validation and access gate.
5. **Update**: local/latest version and module update from GitHub.

## Current Module Structure
```text
upload/
├─ admin/controller/extension/module/eleads.php
├─ admin/language/{en-gb,ru-ru,uk-ua}/extension/module/eleads.php
├─ admin/view/template/extension/module/eleads.twig
├─ admin/view/javascript/eleads/eleads_admin.js
├─ admin/view/stylesheet/eleads.css
├─ catalog/controller/extension/module/eleads.php
├─ catalog/view/theme/default/template/extension/eleads/
│  ├─ seo.twig
│  └─ filter.twig
└─ system/library/eleads/
   ├─ access_guard.php
   ├─ api_client.php
   ├─ api_routes.php
   ├─ bootstrap.php
   ├─ eleads_admin_controller_trait.php
   ├─ eleads_catalog_controller_trait.php
   ├─ eleads_catalog_filter_trait.php
   ├─ eleads_catalog_seo_page_trait.php
   ├─ eleads_catalog_seo_sitemap_trait.php
   ├─ feed_engine.php
   ├─ feed_formatter.php
   ├─ manifest.json
   ├─ oc_adapter.php
   ├─ offer_builder.php
   ├─ seo_sitemap_manager.php
   ├─ sync_manager.php
   ├─ sync_payload_builder.php
   ├─ sync_service.php
   ├─ update_helper.php
   ├─ update_manager.php
   └─ widget_tag_manager.php
install.xml
```

## Repository
- `https://github.com/E-Leads-net/eleads-opencart-3`
- Release build: `.github/workflows/release.yml` on tags `v*`
