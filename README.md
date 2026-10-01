# Resource listing (LD-448)

A "Filtered listing" page on Drupal 11: Resource content with a category
taxonomy, a View with an exposed filter and a URL-driven contextual filter,
rendered through presentational Twig (SDC) components and placed with
Layout Builder. The same listing is built in the Spectrum project; this is a
standalone copy with its own minimal theme.

- Site: https://resource-listing.lndo.site/listing
- Admin: https://resource-listing.lndo.site/user/login (local only: `admin` / `admin`)

## Run it

```bash
composer install
lando start
lando drush site:install standard \
  --db-url=mysql://drupal11:drupal11@database/drupal11 \
  --site-name="Resource Listing" --account-name=admin --account-pass=admin -y
lando drush php:script scripts/setup-site.php
```

`setup-site.php` does everything an editor would do in the admin UI, and can
be run again safely. During the run Drupal prints two or three
`block plugin was not found` warnings for blocks of the vocabulary and
content type created in the same run. The layouts are saved correctly, and
the warnings do not appear afterwards.

`config/sync/` holds the full exported site config (`lando drush cex`).

## URLs

| URL | Shows |
|---|---|
| `/` and `/listing` | Home (front page, alias `/listing`): all resources (no term in the URL → fallback: show all) |
| `/listing/crm`, `/listing/sales-enablement`, `/listing/inbound-strategy`, `/listing/website` | Only that category (term pages, aliased) |
| `/listing?category=2` | Exposed dropdown filter |
| `/listing?topic[2]=2&topic[4]=4` | Exposed checkboxes (CRM or Website) |

## How the ticket is met

| Requirement | Where |
|---|---|
| Content type with a taxonomy reference field | Structure → Content types → **Resource**: `field_resource_category` → vocabulary **Resource category**; plus `field_resource_image` (media: image) and `field_resource_summary` |
| Exposed filter | View **Resource listing**: "Category" dropdown (`category`) and "Topics" checkboxes (`topic`, turned into checkboxes in `resource_theme.theme`) |
| Contextual filter from the URL, with a default | Contextual filter on the category field: default **Taxonomy term ID from URL** ("Load default filter from term page" on). `/listing/crm` is the alias of the CRM term page, so the term ID comes from the route. On `/listing` there is no term: validation fails and the action **Display all results** shows everything |
| Presentational Twig component | `templates/views/views-view--resource-listing--block-1.html.twig` is the Drupal-facing wrapper (reads node, media and file) → `resource_theme:filtered-listing` → `resource_theme:resource-card`, which only take plain variables |
| Placed with Layout Builder | **Home** (front page, alias `/listing`) is a **Landing page** whose layout holds the view block; the **Resource category** term display has the same block in its default layout |
| Responsive | CSS grid: 1 column (mobile), 2 columns (≥ 640px), sidebar beside the grid (≥ 992px), 3 columns (≥ 1200px) |
| Extra | Filters submit on change via Views AJAX (`filtered-listing.js`); Apply button kept for no-JS |

## Admin: where things are

1. **Structure → Taxonomy → Resource category**: 4 terms, each with a URL alias `/listing/<name>`.
2. **Structure → Content types → Resource**: fields and Manage display (detail page).
3. **Content**: 6 Resource nodes (Category, Image, Summary) and the **Home** landing page (front page, alias `/listing`).
4. **Structure → Views → Resource listing → Block**: exposed filters, contextual filter, result summary, "No resources found." text, full pager (9), AJAX on, Reset off.
5. **Structure → Views → Taxonomy term**: disabled, so term pages render their Layout Builder layout.
6. **Home (/ or /listing) → top bar ⋮ → Layout** and **Structure → Taxonomy → Resource category → Manage display → Manage layout**: the "Resource listing" block.
7. **Structure → Block layout (Resource Theme)**: header, main menu (Home), tabs, page title, content, footer.

## Code

```
web/themes/custom/resource_theme/
├── resource_theme.info.yml          standalone theme, regions
├── resource_theme.libraries.yml     global CSS
├── resource_theme.theme             exposed form: "All Categories", checkboxes
├── css/base.css                     tokens, page frame
├── templates/layout/page.html.twig
├── templates/views/views-view--resource-listing--block-1.html.twig   Drupal wrapper
└── components/
    ├── 01-atoms/svg-icon/
    ├── 02-molecules/resource-card/
    └── 03-organisms/filtered-listing/   (+ filtered-listing.js: submit on change)
config/resource-listing/   listing config used by setup-site.php
config/sync/               full site config export
scripts/setup-site.php     admin setup + sample content
```

## Debugging

Turn on Twig development mode (Configuration → Development → Development
settings). Then uncomment the `dump()` lines one step at a time:

- Views template: steps 1–10 (row count, contextual filter value, exposed
  input, node, summary, category, media, image file, final cards, slots).
- `resource_theme.theme`: steps 1–5 (view ID, form keys, dropdown options,
  topic element before/after). Do not leave these on while using the filters:
  the dump output breaks the AJAX response.
- Each component has one dump line for the props it receives.
