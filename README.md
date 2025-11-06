# Translations by Leuchtfeuer (Mautic Plugin)

## Overview / Purpose / Features

Translate Mautic email content with the DeepL API—directly from your email detail page.

**Highlights**

* One-click **Clone & Translate** action on email detail (adds a dropdown item in “Options”).
* Translates **subject** and **MJML** content (GrapesJS builder) while preserving template structure.
* Optional **LOCKED** markers to exclude regions from translation:

  ```html
  <!-- LOCKED_START -->
  ... content you do NOT want translated ...
  <!-- LOCKED_END -->
  ```

## Requirements / Version Support

* **Mautic 5.x** (minimum **5.1**).
* **PHP 8.1+** (matches Mautic 5 requirements).
* **DeepL API key** (required).
* **Optional:** `mjml` CLI on the server for best MJML→HTML rendering quality.

> For other Mautic versions, see the releases page.

## Installation

### Composer

From your Mautic root:

```bash
composer require leuchtfeuer/mautic-translations-bundle
php bin/console mautic:plugins:reload
php bin/console cache:clear
```

### Manual Installation

1. Download the plugin archive.
2. Unzip into Mautic’s `plugins` directory so the folder is named:

   ```
   plugins/LeuchtfeuerTranslationsBundle
   ```
3. In Mautic (as an administrator), go to **Settings → Plugins**.
4. Click **Install/Upgrade Plugins**.

    * **OR** via shell:

      ```bash
      php bin/console cache:clear
      php bin/console mautic:plugins:reload
      ```

## Configuration

1. Go to **Settings → Plugins → Translations by Leuchtfeuer**.
2. Open the plugin and **enter your DeepL API key**.
3. Click **Save & Close**.
4. (Optional) Use **“Test DeepL API”** on the configuration page to verify connectivity.

> Tip: DeepL Free keys typically end with `:fx`. The plugin auto-detects Free vs Pro endpoints.

## Usage

1. Open any email’s **detail** page (not the builder).
2. In **Options** (the dropdown), click **Clone & Translate**.
3. Choose the **target language** (e.g., `DE`, `EN-GB`, `PT-BR`, `ZH-HANS`, …).
4. The plugin will:

    * Probe your DeepL key,
    * Clone the email,
    * Translate the **subject** and **MJML** content (respecting any `LOCKED` regions),
    * Compile MJML → HTML (using the CLI if available),
    * Save the translated draft with a name like `Original Name [DE]`.
5. You’ll be redirected to the **edit** page of the new, translated email. Review and **Save**.

### Excluding Sections from Translation

Wrap content in MJML or HTML with:

```html
<!-- LOCKED_START -->
... any content you want untouched ...
<!-- LOCKED_END -->
```

## Known Issues

* If the `mjml` CLI is **not** installed on the server, the plugin uses a minimal fallback for MJML→HTML. This is good for quick previews but not a full MJML render.
* DeepL **Free** plans have rate limits; large emails or many requests may hit those limits.

## Troubleshooting

* Ensure the plugin is **installed and enabled** under **Settings → Plugins**.
* Clear caches and rebuild assets:

  ```bash
  php bin/console cache:clear
  php bin/console mautic:assets:generate
  ```
* If the “Clone & Translate” button doesn’t appear, you might not be on the email **detail** page or you lack permissions for that email.

## Change log

* [https://github.com/Leuchtfeuer/mautic-translations-bundle/releases](https://github.com/Leuchtfeuer/mautic-translations-bundle/releases)

## Future Ideas

* Translations for additional Mautic entities (Landing Pages, Forms, etc.).
* Per-language style overrides.
* Batch translations and queues.

## Sponsoring & Commercial Support

We are continuously improving our plugins. If you require priority support or custom features, please contact us at **[mautic-plugins@Leuchtfeuer.com](mailto:mautic-plugins@Leuchtfeuer.com)**.

## Get Involved

Open issues or submit pull requests on GitHub. Please follow the contribution guidelines if provided in `CONTRIBUTING.md`.

## Credits

* **Mautic** plugin framework
* **DeepL** API (for translations)
* **MJML** (email markup)
* **Symfony** components

## Author

**Leuchtfeuer Digital Marketing GmbH**
Please raise issues on GitHub.
For everything else, email **[mautic-plugins@Leuchtfeuer.com](mailto:mautic-plugins@Leuchtfeuer.com)**.

## License

This plugin is licensed under **GPL-3.0**. See the `LICENSE` file for details.

## Resources / Further Readings

* DeepL API Docs: [https://www.deepl.com/docs-api](https://www.deepl.com/docs-api)
* MJML: [https://mjml.io/documentation](https://mjml.io/documentation)
* Mautic Developer Docs: [https://developer.mautic.org/](https://developer.mautic.org/)
* Mautic Plugins: [https://developer.mautic.org/#plugins](https://developer.mautic.org/#plugins)
