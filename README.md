# E-Leads — Module for OpenCart 3.x

## Overview
E-Leads adds a product export feed (YML/XML) and optional product synchronization with the E-Leads platform.
The module provides:
- A configurable export feed: categories, attributes, options, shop info, images, descriptions.
- A public feed URL for each language.
- Optional synchronization of product changes (create/update/delete) to E-Leads via API.
- An API Key gate that locks settings until a valid key is provided.
- Built-in module update from GitHub.
- Optional widget loader tag injection into the current storefront theme.

## Compatibility
- OpenCart: 3.x
- PHP: according to your OpenCart 3.x requirements.

## Installation
1. In admin: **Extensions → Installer**.
2. Upload release archive: `eleads-opencart-3.x.ocmod.zip`.
3. Go to **Extensions → Modifications** and click **Refresh**.
4. Go to **Extensions → Extensions → Modules**, find **E-Leads**, click **Install** and then **Edit**.

## Feed URL
The feed is available at:
```
/eleads-yml/{lang}.xml
```
Examples:
- `/eleads-yml/en.xml`
- `/eleads-yml/ru.xml`
- `/eleads-yml/uk.xml`

If an access key is configured:
```
/eleads-yml/en.xml?key=YOUR_KEY
```

## SEO Pages
### Sitemap
- URL: `/e-search/sitemap.xml`
- Generated when **SEO Pages** is enabled.
- Contains links in the form: `https://your-site.com/{lang}/e-search/{slug}`

### SEO Page Route
- URL: `/e-search/{slug}` and `/{lang}/e-search/{slug}`
- The module requests page data from the E-Leads API and renders it using the product search layout.
- Canonical and alternate links are generated from API `alternate` data, and language switch redirects to mapped alternate URL when available.

### Sitemap Sync Endpoint (Module)
The module exposes a protected endpoint to keep the sitemap in sync with external updates:

```
POST /e-search/api/sitemap-sync
Authorization: Bearer <API_KEY>
Content-Type: application/json
```

Optional query parameter:
- `?lang=<language_label>`

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
- `action` is required: `create` | `update` | `delete`
- `slug` is required for all actions
- `new_slug` is required for `update`
- source language can be passed as `lang` or `language`
- target language for `update` can be passed as `new_lang` or `new_language`
- if `?lang=` is provided, it has priority over payload language
- `Authorization` must match the module API key

Success response:
```json
{"status":"ok","url":"https://example.com/ua/e-search/komp-belyy"}
```

Error responses:
- `401`: `{"error":"unauthorized"}` or `{"error":"api_key_missing"}`
- `405`: `{"error":"method_not_allowed"}`
- `422`: `{"error":"invalid_payload"}` or `{"error":"invalid_action"}`
- `500`: `{"error":"sitemap_update_failed"}`

### Languages Endpoint (Module)
Returns enabled/available store languages for integrations:

```
GET /e-search/api/languages
Authorization: Bearer <API_KEY>
Accept: application/json
```

Success:
```json
{
  "status": "ok",
  "count": 3,
  "items": [
    {
      "id": 1,
      "label": "en-gb",
      "code": "en-gb",
      "href_lang": "en",
      "enabled": true
    }
  ]
}
```

Errors:
- `401`: `{"error":"unauthorized"}` or `{"error":"api_key_missing"}`
- `405`: `{"error":"method_not_allowed"}`

### Feeds Endpoint (Module)
Returns all available feed URLs in `language -> feed_url` format:

```
GET /eleads-yml/api/feeds
Authorization: Bearer <API_KEY>
Accept: application/json
```

Request rules:
- Method must be `GET`.
- `Authorization` header is required and must match module API key.

Language/URL generation rules:
- Uses enabled store languages from OpenCart language list.
- Language keys are normalized with the same logic as `/e-search/api/languages` (`href_lang`):
  - `en-*` -> `en`
  - `ru-*` -> `ru`
  - `uk-*` / `ua-*` -> `uk` for feed language code
- Response object key (`items`) uses sitemap language label:
  - `en`, `ru`, `ua`
- Feed URL format:
  - `https://example.com/eleads-yml/{feed_lang}.xml`
- If feed access key is configured (`module_eleads_access_key`), URL includes:
  - `?key=<access_key>`
- If several store languages map to the same label (`en`, `ru`, `ua`), only one entry is returned for that label.

Success response example:
```json
{
  "status": "ok",
  "count": 3,
  "items": {
    "ru": "https://example.com/eleads-yml/ru.xml?key=abc",
    "ua": "https://example.com/eleads-yml/uk.xml?key=abc",
    "en": "https://example.com/eleads-yml/en.xml?key=abc"
  }
}
```

Error responses:
- `401`: `{"error":"unauthorized"}` or `{"error":"api_key_missing"}`
- `405`: `{"error":"method_not_allowed"}`

Curl example:
```bash
curl -X GET "https://example.com/eleads-yml/api/feeds" \
  -H "Authorization: Bearer <API_KEY>" \
  -H "Accept: application/json"
```

## Product Sync Behavior
- Create/update/delete events send one API request per language available for the product.
- For delete events, requests are sent for all enabled store languages.
- Each request builds payload in the target language context.

## Admin Tabs
### 1) Export Settings
- **Feed URLs** per language (copy / download).
- **Categories and subcategories**: only selected categories are exported.
- **Attribute filters** (optional): selected attributes are marked with `filter="true"`.
- **Option filters** (optional): selected options are marked with `filter="true"`.
- **Group products**:
  - **Enabled**: one `<offer>` per product, options are aggregated into one `<param name="Options">` value.
  - **Disabled**: variants can be exported as separate offers.
- **Shop name / Email / Shop URL / Currency**: used in `<shop>`.
- **Picture limit**: max number of `<picture>` tags per offer.
- **Short description source**: defines which field is used for `<short_description>`.
- **Sync toggle**: enables/disables API sync of product changes.

### 2) API Key
- Enter and validate the E-Leads API Key.
- Token status is checked when opening module settings.
- Without a valid key, settings are locked.

### 3) Update
- Shows local version and latest version from GitHub.
- Updates the module directly from this repository.

## Feed Structure (Excerpt)
```xml
<yml_catalog date="YYYY-MM-DD HH:MM">
  <shop>
    <shopName>...</shopName>
    <email>...</email>
    <url>...</url>
    <language>...</language>
    <categories>
      <category id="..." parentId="..." position="..." url="...">...</category>
    </categories>
    <offers>
      <offer id="..." group_id="..." available="true|false">
        <url>...</url>
        <name>...</name>
        <price>...</price>
        <old_price>...</old_price>
        <currency>...</currency>
        <categoryId>...</categoryId>
        <quantity>...</quantity>
        <stock_status>...</stock_status>
        <picture>...</picture>
        <vendor>...</vendor>
        <sku>...</sku>
        <label/>
        <order>...</order>
        <description>...</description>
        <short_description>...</short_description>
        <param name="...">...</param>
        <param filter="true" name="...">...</param>
      </offer>
    </offers>
  </shop>
</yml_catalog>
```

## Widget Loader Tag Injection
On module enable:
- The module requests loader tag from:
  `https://stage-api.e-leads.net/v1/widgets-loader-tag`
- The tag is injected into the active theme footer template.

On module disable:
- The injected block is removed.

If the tag request fails, nothing is inserted.

## Module Structure
```text
upload/
├─ admin/controller/extension/module/eleads.php
├─ admin/language/en-gb/extension/module/eleads.php
├─ admin/language/ru-ru/extension/module/eleads.php
├─ admin/view/template/extension/module/eleads.twig
├─ catalog/controller/extension/module/eleads.php
└─ system/library/eleads/
   ├─ api_routes.php
   ├─ feed_engine.php
   ├─ offer_builder.php
   ├─ seo_sitemap_manager.php
   ├─ sync_manager.php
   ├─ sync_service.php
   ├─ update_helper.php
   ├─ update_manager.php
   ├─ widget_tag_manager.php
   └─ ...
install.xml
```

## Repository & Release
- Repository: `https://github.com/E-Leads-net/eleads-opencart-3`
- Build/release is automated via `.github/workflows/release.yml` on tag `v*`.

## Notes for Marketplace Review
- The module does not modify core files directly.
- API routes are centralized in `upload/system/library/eleads/api_routes.php`.
- Feed is generated on demand via URL.
- Sync can be enabled/disabled at any time.
