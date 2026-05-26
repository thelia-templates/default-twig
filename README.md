# Thelia back-office — Twig template (`default-twig`)

Modern Bootstrap 5 / Twig / Stimulus port of the legacy Smarty back-office. Lives side by side with `templates/backOffice/default/` during the transition.

## Activation

```bash
# fresh install
ddev exec php bin/install \
  --frontoffice_theme=flexy --backoffice_theme=default-twig \
  --pdf_theme=default --email_theme=default \
  --with-demo --with-admin \
  --admin_login=thelia --admin_password=thelia \
  --admin_first_name=thelia --admin_last_name=thelia \
  --admin_email=thelia@example.com

# already installed: switch active template
ddev exec bin/console template:set backOffice default-twig
ddev exec bin/console cache:warmup -e dev

# build assets
ddev exec bash -c "cd templates/backOffice/default-twig && npm install && npm run build"
```

URL: <https://thelia-3.ddev.site/admin>

## Architecture

```
templates/backOffice/default-twig/
├── _side_nav.html.twig      # sidebar (mirrors includes/main-menu.html)
├── _top_nav.html.twig       # top bar (logo, search, locale, profile, logout)
├── _footer.html.twig        # copyright + social
├── _thelia_logo.html.twig   # inline SVG logo (variant: dark|light)
├── base.html.twig           # base layout
├── auth-layout.html.twig    # login screen
├── home.html.twig           # dashboard
├── <domain>/                # one folder per business domain (catalog, customer, ...)
│   ├── list.html.twig
│   ├── edit.html.twig
│   ├── _create_modal.html.twig
│   └── _delete_modal.html.twig
├── components/              # reusable Twig components (BoDataTable, BoDashboard, ...)
├── form/
│   └── bo_form_theme.html.twig   # custom Bootstrap 5 form theme
├── config/packages/twig.yaml      # registers form_themes
├── assets/                  # SCSS + JS + img + flags
│   ├── app.js
│   ├── controllers/         # Stimulus controllers
│   ├── styles/
│   │   ├── main.scss
│   │   └── _variables.scss   # Bootstrap overrides + Thelia palette
│   └── img/
│       ├── logo-thelia-34px.png
│       └── svgFlags/         # 256 country flags
└── src/
    ├── BackOfficeDefaultTwigBundle.php
    ├── Controller/
    │   ├── Catalog/         # Product, Category, Brand
    │   ├── Configuration/   # Language, Currency, Variable, Profile, ...
    │   ├── Customer/        # Customer, Address
    │   ├── Folder/          # Folder, Content
    │   ├── Module/          # Module, ModuleHook
    │   ├── Order/
    │   └── NewsletterController.php
    ├── DTO/                 # immutable data transfer objects
    ├── EventListener/
    │   ├── AdminContextRequestListener.php
    │   └── AdminLocaleListener.php   # syncs ?lang= with the Symfony locale
    ├── Form/                # Symfony forms (CustomerType, AddressType, ...)
    ├── Hook/Attribute/      # #[AsHook] custom attribute
    ├── Security/AdminVoter.php
    ├── Service/Admin/       # AdminFormAction, AdminAccessChecker, ...
    ├── Twig/                # BoUrl, BoData, BoHook extensions
    └── UiComponents/        # AsTwigComponent / AsLiveComponent
```

## Stack

- **Bootstrap 5.3** with overrides aligned on the thelia.net public palette (orange `#f26041`, soft slate text).
- **Bootstrap Icons** (1.13).
- **Symfony UX** (Stimulus, TwigComponent, LiveComponent).
- **HTMX 2** for progressive enhancement.
- **Symfony forms** with the custom `bo_form_theme.html.twig` theme.

## Working on the back-office

### Local dev

```bash
# watch SCSS / JS rebuild
ddev exec bash -c "cd templates/backOffice/default-twig && npm run watch"

# clear cache after editing a Twig template
ddev exec bin/console cache:clear -e dev
```

### Adding a new admin domain

The proven recipe (Folder → Content → CustomerTitle → Country → State → Newsletter → Message):

1. **Form** — `src/Form/<Group>/<Name>Type.php`: `final class extends AbstractType`, options `include_id` / `include_description`.
2. **Controller** — `src/Controller/<Group>/<Name>Controller.php`: `#[Route('/admin/...', name: 'admin.X.')]`. Inject `AdminFormAction`, `AdminAccessChecker`, `Environment`, `FormFactoryInterface`, `UrlGeneratorInterface`, `TokenProvider`, `TranslatorInterface`.
3. **Methods** — `list()` (GET), `create()` (POST), `updateView({id})` (GET), `processUpdate()` (POST), `delete()` (POST/GET), `updatePosition()` (POST/GET).
4. **Events** — use `$this->action->submit(form: ..., eventFactory: ..., eventName: TheliaEvents::X_CREATE)` for forms; `$this->action->tokenAction(event: ..., eventName: ...)` for single-shot actions.
5. **Templates** — `list.html.twig` (DataTable + create modal), `edit.html.twig` (form_start + form_end).

### ACL

Resources live in `core/lib/Thelia/Core/Security/Resource/AdminResources.php`. Check with `is_granted('VIEW', 'admin.foo')` or `$this->access->check(self::RESOURCE, [], AccessManager::VIEW)`.

### Hooks (back-office)

Twig functions exposed by `BackOfficeDefaultTwigBundle\Twig\HookExtension`:

```twig
{{ safe_hook('main.head-css') }}        {# tolerant fallback for buggy listeners #}
{% for block in hook_block('home.block', { foo: bar }) %}
    <h2>{{ block.title }}</h2>
    {{ block.content|raw }}
{% endfor %}
{% if has_hook('product.tab') %}{% endif %}
```

Most hook names are kept iso with the legacy Smarty template. The few that were renamed are bridged
to their legacy name (see [Cohabitation & breaking changes](#cohabitation--breaking-changes)), so
third-party modules keep working unchanged.

## Tests

- **PHPStan**: `ddev exec composer phpstan` (baseline 60 errors).
- **Coding style**: `ddev exec composer cs` / `ddev exec composer cs-diff`.
- **PHPUnit**: `ddev exec composer test`.
- **Playwright (BO Twig)**:
  ```bash
  cd tests/Playwright && BO_TEMPLATE=default-twig npx playwright test specs/backoffice
  ```

## Cohabitation & breaking changes

This template runs side by side with the legacy Smarty back-office. A few names diverge from the
legacy ones; here is how third-party modules are affected.

### Hooks — bridged, no change required

Renamed hooks are replayed under their legacy Smarty name by `Service\Hook\LegacyHookAliases`
(wired into `HookExtension`), so a module listening on the old name keeps contributing. Render
arguments follow the new (Twig) convention — adapt listeners that read a renamed argument.

| Legacy Smarty hook           | Twig hook                    |
|------------------------------|------------------------------|
| `attribute-edit-form.bottom` | `attribute.update-form`      |
| `feature-edit-form.bottom`   | `feature.update-form`        |
| `administrator.update-form`  | `administrator.edit-form`    |
| `advanced-configuration`     | `advanced-configuration.top` |

The `wysiwyg.js` hook on the hook-edit screen keeps its legacy `wysiwyg-hook-edit-js` location.

### ACL — bridged

The advanced-configuration screen accepts both the new `admin.configuration.advanced` resource and
the legacy `admin.cache` one, so existing profiles keep access without a data migration.

### Routes — update your module

Renamed route names are **not** aliased. A module referencing an old name through `path()` / `url()`
must update it:

| Legacy route name                       | Twig route name           |
|-----------------------------------------|---------------------------|
| `admin.sale.reset`                      | `admin.sale.reset-status` |
| `admin.configuration.order-status.*`    | `admin.order-status.*`    |
| `admin.configuration.mailing-system.*`  | `admin.mailingSystem.*`   |

The ACL resource for the mailing system stays `admin.configuration.mailing-system`.

## License

LGPL-3.0+ — same as Thelia core.
