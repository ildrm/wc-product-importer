# WooCommerce Product Importer

A dependency-free WordPress plugin for creating and updating WooCommerce products from CSV or XLSX files.

The plugin provides two import modes:

- **Full import** creates products and updates supported product data.
- **Price and inventory import** updates prices and stock on existing products without creating anything.

Imports run from **Products → Import Products** in WordPress admin. The plugin includes downloadable CSV templates for both modes and does not require a spreadsheet library at runtime.

## Features

- Imports CSV and the first worksheet of an XLSX workbook.
- Creates simple, variable, variation, external, and grouped products.
- Updates existing products by product ID, SKU, or both.
- Imports prices, scheduled sale dates, inventory, dimensions, tax settings, and shipping classes.
- Creates missing product categories, tags, and shipping classes.
- Imports featured and gallery images from attachment IDs or remote URLs.
- Supports local product attributes, variation attributes, default attributes, downloads, and custom metadata.
- Preserves existing values when spreadsheet cells are blank.
- Supports explicit clearing with the `__CLEAR__` token.
- Validates headers and structured values instead of silently ignoring misspelled data.
- Protects imports with WordPress capabilities and nonces.
- Limits upload size, row count, column count, and uncompressed XLSX XML size.

## Requirements

- WordPress 6.5 or newer
- PHP 7.4 or newer
- WooCommerce 8.0 or newer
- A user account with the `manage_woocommerce` capability

XLSX imports additionally require the PHP `zip` and `SimpleXML` extensions. CSV imports do not require those extensions.

The plugin declares compatibility through WooCommerce 11.0. As with any bulk data operation, test against your exact WordPress, WooCommerce, PHP, theme, and extension combination before using it on production data.

### Import limits

| Limit | Value |
| --- | ---: |
| Uploaded file size | 20 MB |
| Data rows per import | 10,000 |
| Columns per import | 256 |
| XLSX XML used by the importer, uncompressed | 32 MB total |

Your PHP, web server, or WordPress configuration may impose a smaller upload size or execution-time limit.

## Installation

1. Place this repository in a directory named `wc-product-importer` under `wp-content/plugins/`.
2. Make sure WooCommerce is installed and active.
3. In WordPress admin, open **Plugins**.
4. Activate **WooCommerce Product Importer**.
5. Open **Products → Import Products**.

For a distributable ZIP, place `wc-product-importer.php` and the other repository files inside a top-level `wc-product-importer/` directory, then compress that directory. Install it through **Plugins → Add Plugin → Upload Plugin**.

No Composer install is required in WordPress. `composer.json` only provides local validation commands for contributors.

## Quick start

1. Back up the site database and uploads, or work on a staging copy.
2. Open **Products → Import Products**.
3. Download the template for the intended import mode.
4. Keep the header row and replace the sample row with your product data.
5. Save the file as UTF-8 CSV or XLSX.
6. Select the matching import mode and upload the file.
7. Review the successful, skipped, and failed row counts. Correct reported rows and import them again if needed.

Start with a small file that contains one product of each type you use. Confirm the results in WooCommerce before processing a large catalog.

## Import behavior

### Product matching

- `sku` is the preferred identifier.
- `id` may identify an existing WooCommerce product.
- When both are supplied, they must resolve to the same product.
- Repeated IDs or SKUs in one file are rejected.
- A new product requires `sku`.
- A new non-variation product also requires `name`.
- A new product defaults to type `simple` when `type` is blank or absent.
- A new non-variation product defaults to `draft` when `status` is blank or absent.
- Existing product types cannot be changed by the importer.

In full mode, an unresolved ID does not reserve or assign that WordPress ID. The row may create a new product only when it also contains the required SKU and other required data.

### Blank cells and explicit clearing

A blank cell means “leave the existing value unchanged.” This makes partial update files safe to use.

To clear a supported field, enter:

```text
__CLEAR__
```

The token is case-insensitive. It can clear text, rich text, prices, sale dates, tax class, dimensions, shipping class, downloads, categories, tags, images, attributes, external/grouped fields, purchase notes, and non-protected custom metadata. For `download_limit` and `download_expiry`, clearing restores WooCommerce's unlimited value of `-1`.

The clear token does not apply to identifiers, enumerated settings, booleans, stock quantity, menu order, or product type.

### Row processing and partial changes

Rows are processed sequentially. Errors on one row do not stop later rows, but an individual row is not transactional. A product may already have been saved before a later taxonomy or image operation for that row fails. There is no automatic rollback.

This also means references must already exist when their row is processed:

- Put a variable parent before its variation rows, unless the parent already exists.
- Products listed in `grouped_products` must already exist or appear earlier in the file.

Always keep a current backup before a production import.

## File formats

### CSV

- Use UTF-8 text; a UTF-8 BOM is accepted.
- Comma, semicolon, and tab delimiters are detected automatically.
- Delimiters inside quoted fields do not affect detection.
- Standard CSV quoting is supported, including fields containing delimiters or line breaks.
- Binary data and rows wider than 256 columns are rejected.

Example full import:

```csv
sku,type,name,status,regular_price,manage_stock,stock_quantity,categories,tags
TSHIRT-001,simple,Logo T-shirt,draft,29.90,yes,40,Clothing > T-shirts,featured|summer
```

Example price and inventory update:

```csv
sku,regular_price,sale_price,manage_stock,stock_quantity,stock_status,backorders
TSHIRT-001,29.90,24.90,yes,35,instock,no
```

### XLSX

- Only the first worksheet is imported.
- Shared strings, inline strings, numbers, booleans, and cached formula results are read.
- Formatting, charts, macros, images embedded in the workbook, and additional worksheets are ignored.
- Use text such as `2026-09-01` for dates; Excel serial date conversion is not provided.
- Workbooks with unsafe relationship paths, document type declarations, excessive columns, or more than 32 MB of relevant uncompressed XML are rejected.

The XLSX reader uses PHP's `ZipArchive` and `SimpleXML`; it does not use PhpSpreadsheet.

## Column reference

Headers are case-insensitive. Spaces and hyphens normalize to underscores, so `Regular Price`, `regular-price`, and `regular_price` resolve to the same canonical header. Unknown columns are rejected to catch spelling mistakes.

Every file must contain `id`, `sku`, or both.

### Identity and product type

| Column | Applies to | Accepted value / behavior |
| --- | --- | --- |
| `id` | Existing products | Positive WooCommerce product ID. |
| `sku` | All products | Unique SKU; required for creation. |
| `type` | Full import | `simple`, `variable`, `variation`, `external`, or `grouped`. Defaults to `simple` for a new product. |
| `parent_sku` | Variations | SKU of an existing variable product; required when creating a variation. |

### Basic product data

| Column | Applies to | Accepted value / behavior |
| --- | --- | --- |
| `name` | Non-variations | Product name; required when creating a non-variation. |
| `slug` | Non-variations | Product slug; normalized by WordPress. |
| `status` | Non-variations | `draft`, `pending`, `private`, or `publish`. New products default to `draft`. |
| `catalog_visibility` | Non-variations | `visible`, `catalog`, `search`, or `hidden`. |
| `description` | Non-variations | Long description; safe WordPress post HTML is retained. |
| `short_description` | Non-variations | Short description; safe WordPress post HTML is retained. |
| `purchase_note` | Supported product types | Text shown to the customer after purchase. |
| `menu_order` | Supported product types | A valid integer. |
| `reviews_allowed` | Supported product types | Boolean value. |

### Price and tax

| Column | Accepted value / behavior |
| --- | --- |
| `regular_price` | Decimal value in the store's expected decimal format. |
| `sale_price` | Decimal value. |
| `sale_start` | Date/time such as `2026-09-01` or `2026-09-01 09:00:00`. |
| `sale_end` | Date/time such as `2026-09-10` or `2026-09-10 23:59:59`. |
| `tax_status` | `taxable`, `shipping`, or `none`. |
| `tax_class` | Tax-class slug or name normalized to a slug; clear it for the standard class. |

### Inventory

| Column | Accepted value / behavior |
| --- | --- |
| `manage_stock` | Boolean value. |
| `stock_quantity` | Numeric stock amount. Providing it enables stock management. |
| `stock_status` | `instock`, `outofstock`, or `onbackorder`. When omitted with a quantity, status is inferred from whether quantity is greater than zero. |
| `backorders` | `no`, `notify`, or `yes`. |
| `sold_individually` | Boolean value. Full import only. |

Accepted boolean values are `yes`/`no`, `true`/`false`, `1`/`0`, `y`/`n`, and `on`/`off`, case-insensitively.

### Dimensions, shipping, and digital products

| Column | Accepted value / behavior |
| --- | --- |
| `weight` | Decimal value in the store's configured weight unit. |
| `length` | Decimal value in the store's configured dimension unit. |
| `width` | Decimal value in the store's configured dimension unit. |
| `height` | Decimal value in the store's configured dimension unit. |
| `shipping_class` | Existing shipping-class slug/name, or a new class to create. |
| `virtual` | Boolean value. |
| `downloadable` | Boolean value. |
| `downloads` | Pipe-separated `Name=https://example.com/file` definitions. HTTP(S) URLs are required. |
| `download_limit` | Integer `-1` or greater; `-1` means unlimited. |
| `download_expiry` | Integer `-1` or greater; number of days, with `-1` meaning never expires. |

Example downloads value:

```text
Manual=https://example.com/manual.pdf|Guide=https://example.com/guide.pdf
```

### Categories, tags, and images

| Column | Accepted value / behavior |
| --- | --- |
| `categories` | Pipe-separated category paths. Use `>` for hierarchy. Missing categories are created. Not applied to variations. |
| `tags` | Pipe-separated tag names. Missing tags are created. Not applied to variations. |
| `featured_image` | A WordPress image attachment ID or a valid remote image URL. |
| `gallery_images` | Pipe-separated image attachment IDs and/or remote image URLs. Not applied to variations. |

Examples:

```text
categories: Clothing > Shirts|Sale
tags: summer|featured
gallery_images: 123|https://example.com/back.jpg
```

Remote images are sideloaded into the WordPress media library. A source-URL marker and an in-request cache prevent the same remote image from being downloaded repeatedly by this importer.

### Attributes and specialized product types

| Column | Applies to | Accepted value / behavior |
| --- | --- | --- |
| `attributes` | Simple, variable, external, grouped | Semicolon-separated local attributes in `Name=Value|Value` form. On a variable product, these are enabled for variations. |
| `variation_attributes` | Variations | Semicolon-separated selections in `Name=Value` form. |
| `default_attributes` | Variable products | Semicolon-separated defaults in `Name=Value` form. |
| `external_url` | External products | Valid HTTP(S) product URL. |
| `button_text` | External products | External-product button label. |
| `grouped_products` | Grouped products | Pipe-separated SKUs of existing child products. |

Examples:

```text
attributes: Color=Red|Blue;Size=S|M|L
variation_attributes: Color=Red;Size=M
default_attributes: Color=Red;Size=M
grouped_products: CHILD-001|CHILD-002
```

The importer creates local product attributes (`WC_Product_Attribute` with ID `0`). It does not create or resolve global attribute taxonomies such as `pa_color`.

Malformed definitions and duplicate attribute names are reported as row errors.

### Custom metadata

Use a header in this form:

```text
meta:brand
```

The part after `meta:` is normalized with WordPress `sanitize_key()`. Metadata values are stored as sanitized text. A `__CLEAR__` value deletes the metadata entry.

Protected metadata keys beginning with `_` are rejected. Custom metadata is available only in full-import mode.

### Header aliases

The following aliases normalize to canonical headers:

| Alias | Canonical header |
| --- | --- |
| `product_id` | `id` |
| `product_sku` | `sku` |
| `product_type` | `type` |
| `title`, `product_name` | `name` |
| `price`, `regularprice` | `regular_price` |
| `saleprice` | `sale_price` |
| `stock`, `quantity`, `qty`, `inventory` | `stock_quantity` |
| `manage_inventory` | `manage_stock` |
| `featured_image_url`, `image` | `featured_image` |
| `gallery` | `gallery_images` |
| `parent` | `parent_sku` |
| `product_url` | `external_url` |

Duplicate headers after normalization are rejected. For example, a file cannot contain both `price` and `regular_price`.

## Import modes

### Full import

Full import accepts all canonical columns in the reference above plus non-protected `meta:*` columns. It creates missing products when the creation requirements are satisfied and updates products that match an ID or SKU.

Fields omitted from the file or left blank are not changed.

### Price and inventory only

This mode never creates a product. It accepts only:

```text
id
sku
regular_price
sale_price
manage_stock
stock_quantity
stock_status
backorders
```

At least one price or inventory field must contain a value in each data row. Other columns are rejected so that a file selected in the wrong mode cannot silently lose changes.

## Security and operational notes

- Only users with `manage_woocommerce` can view the importer, submit imports, or download templates.
- Import and template requests use WordPress nonce verification.
- Uploaded files must be real PHP uploads with a `.csv` or `.xlsx` extension.
- Output is escaped and custom metadata keys are restricted.
- XML external network access is disabled, document type declarations are rejected, and workbook relationship paths are constrained to worksheet XML inside the archive.
- Remote images are validated and downloaded through WordPress media APIs.
- Imports run synchronously in the admin HTTP request. Large files and remote images can hit hosting time or memory limits even when they are below the plugin limits.
- Imports write directly to WooCommerce and are not a dry run. Use staging and backups for important catalogs.

## Troubleshooting

### The importer menu is missing

Confirm that WooCommerce is active and that the current account has the `manage_woocommerce` capability. The menu appears under **Products**.

### The upload is rejected before processing

Check all of the following:

- The file extension is `.csv` or `.xlsx`.
- The file is not empty or larger than 20 MB.
- The web server's `upload_max_filesize` and `post_max_size` values permit the upload.
- The import contains no more than 10,000 data rows and 256 columns.

### XLSX says ZipArchive or SimpleXML is required

Enable the PHP `zip` and XML/SimpleXML extensions, restart the relevant PHP service if necessary, and confirm those extensions are enabled in the PHP runtime serving WordPress. Alternatively, export the worksheet as UTF-8 CSV.

### “Unsupported column(s)”

Use a downloadable template and compare the reported header with the column reference. Also check whether you selected price/inventory mode for a full-import file.

### “Duplicate column names after normalization”

Aliases and formatting normalize before validation. Remove one of the colliding columns—for example, keep either `price` or `regular_price`, not both.

### A product or parent cannot be found

Confirm the exact SKU or positive product ID. Put variable parents before their variations and ensure grouped child products exist before the grouped row is processed.

### An image import fails

Confirm the attachment ID refers to an image, or verify that the remote URL is publicly reachable and points to an image type accepted by WordPress. Remote hosts, firewalls, DNS, TLS, and WordPress's allowed image extensions can all affect sideloading.

### Some fields changed on a failed row

Rows are not transactional. Product data is initially saved before taxonomy and image work. Restore from backup if needed, correct the row, and import it again.

## Development

### Repository layout

```text
.
├── wc-product-importer.php  # Complete runtime plugin
├── tests/run.php            # Dependency-free parser/validation regression tests
├── composer.json            # Local lint and test scripts
├── README.md
└── LICENSE
```

The runtime intentionally remains in one PHP file so the plugin can be installed without a build step or Composer dependencies.

### Run checks

With PHP and Composer available:

```bash
composer check
```

Or run the commands directly:

```bash
php -l wc-product-importer.php
php -l tests/run.php
php tests/run.php
```

The standalone suite stubs the small subset of WordPress/WooCommerce APIs needed to exercise parsing and validation. It covers header normalization, mode schemas, integer validation, structured attributes/downloads, delimiter handling, binary CSV rejection, XML hardening, and a minimal XLSX workbook.

The suite is not a replacement for integration testing in WordPress. Before releasing, test at least:

- Creating and updating every supported product type.
- Variation parent/default-attribute behavior.
- Category, tag, and shipping-class creation.
- Local and remote media imports.
- Downloadable and external products.
- The active store's decimal, stock, tax, and date configuration.
- Error behavior under the hosting environment's time and memory limits.

## Contributing

1. Create a focused branch.
2. Keep runtime code compatible with PHP 7.4.
3. Add or update regression coverage for parser and validation changes.
4. Run `composer check`.
5. Test behavioral changes in a disposable WordPress/WooCommerce environment.
6. Describe user-visible changes and any compatibility considerations in the pull request.

## Changelog

### 1.1.0

- Added strict, mode-specific header validation and rejection of unnamed, duplicate, protected-meta, and unexpected columns.
- Added integer and URL validation plus actionable errors for malformed attributes, variations, and downloads.
- Hardened CSV delimiter/binary handling and XLSX path, XML-size, document-type, and column validation.
- Added in-request image resolution caching.
- Added a dependency-free regression suite and Composer check commands.
- Aligned plugin metadata with the MIT license and documented the project fully.

### 1.0.0

- Initial CSV/XLSX full-product and price/inventory importer.

## License

This project is licensed under the [MIT License](LICENSE).
