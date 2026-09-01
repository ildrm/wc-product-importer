<?php
/**
 * Plugin Name: WooCommerce Product Importer
 * Plugin URI: https://github.com/ildrm/wc-product-importer
 * Description: Imports and updates WooCommerce products from XLSX or CSV files. Includes a full product import mode and a price/inventory update mode.
 * Version:     1.1.1
 * Author:      Shahin Ilderemi
 * Author URI:  https://ildrm.com
 * License:     MIT
 * License URI: https://opensource.org/license/mit
 * Text Domain: wc-product-importer
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 11.0
 */

defined( 'ABSPATH' ) || exit;

final class WC_Single_File_Product_Importer {

	const VERSION                = '1.1.1';
	const MENU_SLUG              = 'wc-product-importer';
	const NONCE_ACTION           = 'wc_sfpi_import_products';
	const NONCE_NAME             = 'wc_sfpi_nonce';
	const TEMPLATE_ACTION        = 'wc_sfpi_download_template';
	const TEMPLATE_NONCE         = 'wc_sfpi_download_template_nonce';
	const MAX_FILE_SIZE          = 20971520; // 20 MB.
	const MAX_ROWS               = 10000;
	const MAX_COLUMNS            = 256;
	const MAX_IMPORT_CELLS       = 500000;
	const MAX_CELL_SIZE          = 1048576; // 1 MB per cell.
	const MAX_XLSX_XML_SIZE      = 33554432; // 32 MB of uncompressed XML.
	const MAX_REMOTE_IMAGE_SIZE  = 10485760; // 10 MB.
	const REMOTE_IMAGE_TIMEOUT   = 15;
	const REMOTE_IMAGE_REDIRECTS = 3;
	const CLEAR_TOKEN            = '__CLEAR__';

	/**
	 * Image IDs resolved during the current request, keyed by source value.
	 *
	 * @var array
	 */
	private static $resolved_image_ids = array();

	/**
	 * Bootstrap the plugin after all plugins have loaded.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_missing_notice' ) );
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 99 );
		add_action( 'admin_post_' . self::TEMPLATE_ACTION, array( __CLASS__, 'download_template' ) );
	}

	/**
	 * Display the WooCommerce dependency notice.
	 *
	 * @return void
	 */
	public static function woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<?php
				echo esc_html__(
					'WooCommerce Product Importer requires WooCommerce to be installed and active.',
					'wc-product-importer'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register "Import Products" under Products.
	 *
	 * @return void
	 */
	public static function register_admin_menu() {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Import Products', 'wc-product-importer' ),
			__( 'Import Products', 'wc-product-importer' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Render the importer admin page.
	 *
	 * @return void
	 */
	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to import products.', 'wc-product-importer' ) );
		}

		$result = null;

		if ( 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			$result = self::process_import_request();
		}

		$full_template_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::TEMPLATE_ACTION . '&mode=full' ),
			self::TEMPLATE_NONCE
		);

		$price_stock_template_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::TEMPLATE_ACTION . '&mode=price_stock' ),
			self::TEMPLATE_NONCE
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Import Products', 'wc-product-importer' ); ?></h1>

			<p>
				<?php
				echo esc_html__(
					'Upload an XLSX or CSV file. SKU is the preferred product identifier and is required when creating a new product.',
					'wc-product-importer'
				);
				?>
			</p>

			<?php self::render_result( $result ); ?>

			<div style="max-width: 980px; background: #fff; border: 1px solid #dcdcde; padding: 20px; margin-top: 20px;">
				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="wc-sfpi-mode"><?php echo esc_html__( 'Import mode', 'wc-product-importer' ); ?></label>
								</th>
								<td>
									<select name="import_mode" id="wc-sfpi-mode" required>
										<option value="full">
											<?php echo esc_html__( '1. Import products — create/update uploaded product information', 'wc-product-importer' ); ?>
										</option>
										<option value="price_stock">
											<?php echo esc_html__( '2. Update prices and inventory only', 'wc-product-importer' ); ?>
										</option>
									</select>
									<p class="description">
										<?php
										echo esc_html__(
											'Price/inventory mode never creates products; it only updates products already found by ID or SKU.',
											'wc-product-importer'
										);
										?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="wc-sfpi-file"><?php echo esc_html__( 'Import file', 'wc-product-importer' ); ?></label>
								</th>
								<td>
									<input
										type="file"
										name="import_file"
										id="wc-sfpi-file"
										accept=".xlsx,.csv"
										required
									/>
									<p class="description">
										<?php
										printf(
											/* translators: %s: maximum file size. */
											esc_html__( 'Supported formats: .xlsx and .csv. Maximum plugin limit: %s.', 'wc-product-importer' ),
											esc_html( size_format( self::MAX_FILE_SIZE ) )
										);
										?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<?php submit_button( __( 'Import Products', 'wc-product-importer' ) ); ?>
				</form>
			</div>

			<div style="max-width: 980px; background: #fff; border: 1px solid #dcdcde; padding: 20px; margin-top: 20px;">
				<h2><?php echo esc_html__( 'Templates and column format', 'wc-product-importer' ); ?></h2>

				<p>
					<a class="button" href="<?php echo esc_url( $full_template_url ); ?>">
						<?php echo esc_html__( 'Download Full Import CSV Template', 'wc-product-importer' ); ?>
					</a>
					<a class="button" href="<?php echo esc_url( $price_stock_template_url ); ?>">
						<?php echo esc_html__( 'Download Price/Stock CSV Template', 'wc-product-importer' ); ?>
					</a>
				</p>

				<p>
					<strong><?php echo esc_html__( 'Important:', 'wc-product-importer' ); ?></strong>
					<?php
					printf(
						/* translators: %s: clear-token value. */
						esc_html__(
							'Blank cells do not overwrite existing values. To explicitly clear a supported text/price field, use %s.',
							'wc-product-importer'
						),
						'<code>' . esc_html( self::CLEAR_TOKEN ) . '</code>'
					);
					?>
				</p>

				<ul style="list-style: disc; padding-left: 22px;">
					<li><code>categories</code>: <code>Clothing &gt; Shirts|Sale</code></li>
					<li><code>tags</code>: <code>summer|featured</code></li>
					<li><code>attributes</code>: <code>Color=Red|Blue;Size=S|M|L</code></li>
					<li><code>variation_attributes</code>: <code>Color=Red;Size=M</code></li>
					<li><code>default_attributes</code>: <code>Color=Red;Size=M</code></li>
					<li><code>gallery_images</code>: attachment IDs or image URLs separated by <code>|</code></li>
					<li><code>downloads</code>: <code>Manual=https://example.com/manual.pdf|Guide=https://example.com/guide.pdf</code></li>
					<li><code>grouped_products</code>: child product SKUs separated by <code>|</code></li>
					<li><code>meta:your_key</code>: any non-protected custom metadata key.</li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render import result.
	 *
	 * @param array|null $result Import result.
	 * @return void
	 */
	private static function render_result( $result ) {
		if ( empty( $result ) || ! is_array( $result ) ) {
			return;
		}

		$success_count = isset( $result['success'] ) ? (int) $result['success'] : 0;
		$error_count   = isset( $result['errors'] ) ? count( $result['errors'] ) : 0;
		$skipped_count = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;
		?>
		<div class="notice <?php echo $error_count > 0 ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
			<p>
				<?php
				printf(
					/* translators: 1: successful rows, 2: skipped rows, 3: failed rows. */
					esc_html__( 'Import finished. Successful: %1$d, skipped: %2$d, errors: %3$d.', 'wc-product-importer' ),
					(int) $success_count,
					(int) $skipped_count,
					(int) $error_count
				);
				?>
			</p>
		</div>
		<?php

		if ( empty( $result['errors'] ) ) {
			return;
		}
		?>
		<div style="max-width: 980px; background: #fff; border: 1px solid #dcdcde; padding: 20px; margin-top: 15px;">
			<h2><?php echo esc_html__( 'Import errors', 'wc-product-importer' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Row', 'wc-product-importer' ); ?></th>
						<th><?php echo esc_html__( 'SKU', 'wc-product-importer' ); ?></th>
						<th><?php echo esc_html__( 'Message', 'wc-product-importer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $result['errors'] as $error ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $error['row'] ); ?></td>
							<td><?php echo esc_html( (string) $error['sku'] ); ?></td>
							<td><?php echo esc_html( (string) $error['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle the import form.
	 *
	 * @return array
	 */
	private static function process_import_request() {
		$result = array(
			'success' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$result['errors'][] = self::error_entry( 0, '', __( 'Permission denied.', 'wc-product-importer' ) );
			return $result;
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$result['errors'][] = self::error_entry( 0, '', __( 'Security verification failed. Please refresh the page and try again.', 'wc-product-importer' ) );
			return $result;
		}

		$mode = isset( $_POST['import_mode'] ) ? sanitize_key( wp_unslash( $_POST['import_mode'] ) ) : '';

		if ( ! in_array( $mode, array( 'full', 'price_stock' ), true ) ) {
			$result['errors'][] = self::error_entry( 0, '', __( 'Invalid import mode.', 'wc-product-importer' ) );
			return $result;
		}

		if ( empty( $_FILES['import_file'] ) || ! is_array( $_FILES['import_file'] ) ) {
			$result['errors'][] = self::error_entry( 0, '', __( 'No import file was uploaded.', 'wc-product-importer' ) );
			return $result;
		}

		$file = $_FILES['import_file'];

		if ( ! empty( $file['error'] ) ) {
			$result['errors'][] = self::error_entry(
				0,
				'',
				sprintf(
					/* translators: %d: PHP upload error code. */
					__( 'Upload failed with error code %d.', 'wc-product-importer' ),
					(int) $file['error']
				)
			);
			return $result;
		}

		$file_size = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( $file_size <= 0 || $file_size > self::MAX_FILE_SIZE ) {
			$result['errors'][] = self::error_entry( 0, '', __( 'The uploaded file is empty or exceeds the allowed size.', 'wc-product-importer' ) );
			return $result;
		}

		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$filename = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';

		if ( empty( $tmp_name ) || ! is_uploaded_file( $tmp_name ) ) {
			$result['errors'][] = self::error_entry( 0, '', __( 'The uploaded file could not be verified.', 'wc-product-importer' ) );
			return $result;
		}

		$actual_file_size = filesize( $tmp_name );

		if ( false === $actual_file_size || $actual_file_size <= 0 || $actual_file_size > self::MAX_FILE_SIZE ) {
			$result['errors'][] = self::error_entry( 0, '', __( 'The uploaded file is empty or exceeds the allowed size.', 'wc-product-importer' ) );
			return $result;
		}

		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'xlsx', 'csv' ), true ) ) {
			$result['errors'][] = self::error_entry( 0, '', __( 'Only XLSX and CSV files are supported.', 'wc-product-importer' ) );
			return $result;
		}

		try {
			$rows = self::read_import_file( $tmp_name, $extension );

			if ( count( $rows ) < 2 ) {
				throw new RuntimeException( __( 'The file must contain a header row and at least one data row.', 'wc-product-importer' ) );
			}

			if ( count( $rows ) - 1 > self::MAX_ROWS ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %d: maximum number of rows. */
						__( 'The import contains too many rows. The maximum is %d data rows per import.', 'wc-product-importer' ),
						self::MAX_ROWS
					)
				);
			}

			$headers = self::normalize_headers( array_shift( $rows ) );
			self::validate_import_headers( $headers, $mode );

			$seen_products = array();

			foreach ( $rows as $index => $row_values ) {
				$excel_row_number = $index + 2;
				$sku              = '';

				try {
					$row = self::combine_row( $headers, $row_values );

					if ( self::row_is_empty( $row ) ) {
						++$result['skipped'];
						continue;
					}

					$sku             = self::get_string( $row, 'sku' );
					$matched_product = self::find_product( $row );
					$identities      = self::identity_keys_for_row( $row, $matched_product );

					foreach ( $identities as $identity ) {
						if ( isset( $seen_products[ $identity ] ) ) {
							throw new RuntimeException( __( 'Duplicate product identifier in the same import file.', 'wc-product-importer' ) );
						}
					}

					foreach ( $identities as $identity ) {
						$seen_products[ $identity ] = true;
					}

					if ( 'price_stock' === $mode ) {
						self::import_price_stock_row( $row, $matched_product );
					} else {
						self::import_full_row( $row, $matched_product );
					}

					++$result['success'];
				} catch ( Throwable $throwable ) {
					$result['errors'][] = self::error_entry(
						$excel_row_number,
						$sku,
						$throwable->getMessage()
					);
				}
			}
		} catch ( Throwable $throwable ) {
			$result['errors'][] = self::error_entry( 0, '', $throwable->getMessage() );
		}

		return $result;
	}

	/**
	 * Import one row in price/inventory mode.
	 *
	 * @param array            $row     Row data.
	 * @param WC_Product|false $product Matched product, if any.
	 * @return void
	 * @throws RuntimeException On invalid data.
	 */
	private static function import_price_stock_row( array $row, $product ) {
		if ( ! $product ) {
			throw new RuntimeException( __( 'Product not found. Price/inventory mode does not create new products.', 'wc-product-importer' ) );
		}

		$changed = false;

		if ( self::has_value_or_clear( $row, 'regular_price' ) ) {
			$product->set_regular_price( self::non_negative_decimal_or_clear( $row['regular_price'], 'regular_price' ) );
			$changed = true;
		}

		if ( self::has_value_or_clear( $row, 'sale_price' ) ) {
			$product->set_sale_price( self::non_negative_decimal_or_clear( $row['sale_price'], 'sale_price' ) );
			$changed = true;
		}

		if ( self::has_value( $row, 'manage_stock' ) ) {
			$product->set_manage_stock( self::to_bool( $row['manage_stock'] ) );
			$changed = true;
		}

		if ( self::has_value( $row, 'stock_quantity' ) ) {
			$product->set_manage_stock( true );
			$quantity = self::stock_amount( $row['stock_quantity'] );
			$product->set_stock_quantity( $quantity );

			if ( ! self::has_value( $row, 'stock_status' ) ) {
				$product->set_stock_status( $quantity > 0 ? 'instock' : 'outofstock' );
			}

			$changed = true;
		}

		if ( self::has_value( $row, 'stock_status' ) ) {
			$product->set_stock_status( self::validate_stock_status( $row['stock_status'] ) );
			$changed = true;
		}

		if ( self::has_value( $row, 'backorders' ) ) {
			$product->set_backorders( self::validate_backorders( $row['backorders'] ) );
			$changed = true;
		}

		if ( ! $changed ) {
			throw new RuntimeException( __( 'No supported price or inventory values were provided in this row.', 'wc-product-importer' ) );
		}

		$product->save();
	}

	/**
	 * Import one row in full mode.
	 *
	 * @param array            $row     Row data.
	 * @param WC_Product|false $product Matched product, if any.
	 * @return void
	 * @throws RuntimeException On invalid data.
	 */
	private static function import_full_row( array $row, $product ) {
		$is_new  = ! $product;
		$type    = self::has_value( $row, 'type' ) ? sanitize_key( $row['type'] ) : 'simple';

		if ( $is_new ) {
			$product = self::create_product_object( $type, $row );
		} elseif ( self::has_value( $row, 'type' ) && $product->get_type() !== $type ) {
			throw new RuntimeException(
				sprintf(
					/* translators: 1: existing product type, 2: requested product type. */
					__( 'Changing an existing product type from "%1$s" to "%2$s" is not supported by this importer. Create a new product or keep the existing type.', 'wc-product-importer' ),
					$product->get_type(),
					$type
				)
			);
		}

		if ( $is_new && ! self::has_value( $row, 'sku' ) ) {
			throw new RuntimeException( __( 'SKU is required when creating a new product.', 'wc-product-importer' ) );
		}

		if ( $is_new && 'variation' !== $type && ! self::has_value( $row, 'name' ) ) {
			throw new RuntimeException( __( 'Name is required when creating a new product.', 'wc-product-importer' ) );
		}

		self::apply_common_fields( $product, $row, $is_new );
		self::apply_type_specific_fields( $product, $row );
		self::apply_custom_meta( $product, $row );

		$product_id = $product->save();

		if ( ! $product_id ) {
			throw new RuntimeException( __( 'WooCommerce could not save the product.', 'wc-product-importer' ) );
		}

		self::apply_taxonomies( $product, $row );
		self::apply_images( $product, $row );

		$product->save();
	}

	/**
	 * Find a product by ID and/or SKU, validating conflicts.
	 *
	 * @param array $row Row data.
	 * @return WC_Product|false
	 * @throws RuntimeException On conflicting identifiers.
	 */
	private static function find_product( array $row ) {
		$id  = self::get_int( $row, 'id' );
		$sku = self::get_string( $row, 'sku' );

		$product_by_id  = $id > 0 ? wc_get_product( $id ) : false;
		$product_by_sku = false;

		if ( $id > 0 && ! $product_by_id ) {
			throw new RuntimeException( __( 'The supplied product ID does not identify an existing WooCommerce product.', 'wc-product-importer' ) );
		}

		if ( '' !== $sku ) {
			$sku_id = wc_get_product_id_by_sku( $sku );

			if ( $sku_id ) {
				$product_by_sku = wc_get_product( $sku_id );
			}
		}

		if ( $product_by_id && $product_by_sku && $product_by_id->get_id() !== $product_by_sku->get_id() ) {
			throw new RuntimeException( __( 'The supplied product ID and SKU point to different products.', 'wc-product-importer' ) );
		}

		if ( $product_by_id && '' !== $sku && $product_by_id->get_sku() && $product_by_id->get_sku() !== $sku ) {
			throw new RuntimeException( __( 'The supplied SKU does not match the product found by ID.', 'wc-product-importer' ) );
		}

		return $product_by_id ? $product_by_id : $product_by_sku;
	}

	/**
	 * Build stable identity keys for duplicate detection.
	 *
	 * Including identifiers already stored on a matched product prevents the same
	 * product from appearing once by ID and again by SKU in a single import.
	 *
	 * @param array            $row     Row data.
	 * @param WC_Product|false $product Matched product, if any.
	 * @return array
	 * @throws RuntimeException If the row has no usable identifier.
	 */
	private static function identity_keys_for_row( array $row, $product ) {
		$id   = self::get_int( $row, 'id' );
		$sku  = self::get_string( $row, 'sku' );
		$keys = array();

		if ( $id > 0 ) {
			$keys[] = 'id:' . $id;
		}

		if ( '' !== $sku ) {
			$keys[] = 'sku:' . strtolower( $sku );
		}

		if ( $product ) {
			$keys[] = 'id:' . (int) $product->get_id();
			$stored_sku = (string) $product->get_sku();

			if ( '' !== $stored_sku ) {
				$keys[] = 'sku:' . strtolower( $stored_sku );
			}
		}

		$keys = array_values( array_unique( $keys ) );
		sort( $keys, SORT_STRING );

		if ( empty( $keys ) ) {
			throw new RuntimeException( __( 'This row does not contain a valid product ID or SKU.', 'wc-product-importer' ) );
		}

		return $keys;
	}

	/**
	 * Create a WooCommerce product object for a new row.
	 *
	 * @param string $type Product type.
	 * @param array  $row  Row data.
	 * @return WC_Product
	 * @throws RuntimeException On unsupported types or invalid parent.
	 */
	private static function create_product_object( $type, array $row ) {
		switch ( $type ) {
			case 'simple':
				return new WC_Product_Simple();

			case 'variable':
				return new WC_Product_Variable();

			case 'external':
				return new WC_Product_External();

			case 'grouped':
				return new WC_Product_Grouped();

			case 'variation':
				$parent_sku = self::get_string( $row, 'parent_sku' );

				if ( '' === $parent_sku ) {
					throw new RuntimeException( __( 'parent_sku is required when creating a variation.', 'wc-product-importer' ) );
				}

				$variation = new WC_Product_Variation();
				$variation->set_parent_id( self::resolve_variable_parent_id( $parent_sku ) );

				return $variation;

			default:
				throw new RuntimeException(
					sprintf(
						/* translators: %s: product type. */
						__( 'Unsupported product type "%s". Supported types: simple, variable, variation, external, grouped.', 'wc-product-importer' ),
						$type
					)
				);
		}
	}

	/**
	 * Apply shared product fields.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $row     Row data.
	 * @param bool       $is_new  Whether the product is new.
	 * @return void
	 * @throws RuntimeException On invalid values.
	 */
	private static function apply_common_fields( WC_Product $product, array $row, $is_new ) {
		if ( self::has_value( $row, 'sku' ) ) {
			$sku = wc_clean( $row['sku'] );

			if ( $is_new || $product->get_sku() !== $sku ) {
				$product->set_sku( $sku );
			}
		}

		if ( ! $product->is_type( 'variation' ) && self::has_value_or_clear( $row, 'name' ) ) {
			$product->set_name( self::text_or_clear( $row['name'] ) );
		}

		if ( ! $product->is_type( 'variation' ) && self::has_value_or_clear( $row, 'slug' ) ) {
			$product->set_slug( sanitize_title( self::text_or_clear( $row['slug'] ) ) );
		}

		if ( ! $product->is_type( 'variation' ) && self::has_value( $row, 'status' ) ) {
			$product->set_status( self::validate_choice( $row['status'], array( 'draft', 'pending', 'private', 'publish' ), 'status' ) );
		} elseif ( $is_new && ! $product->is_type( 'variation' ) ) {
			$product->set_status( 'draft' );
		}

		if ( ! $product->is_type( 'variation' ) && self::has_value( $row, 'catalog_visibility' ) ) {
			$product->set_catalog_visibility(
				self::validate_choice(
					$row['catalog_visibility'],
					array( 'visible', 'catalog', 'search', 'hidden' ),
					'catalog_visibility'
				)
			);
		}

		if ( ! $product->is_type( 'variation' ) && self::has_value_or_clear( $row, 'description' ) ) {
			$product->set_description( self::html_or_clear( $row['description'] ) );
		}

		if ( ! $product->is_type( 'variation' ) && self::has_value_or_clear( $row, 'short_description' ) ) {
			$product->set_short_description( self::html_or_clear( $row['short_description'] ) );
		}

		if ( self::has_value_or_clear( $row, 'regular_price' ) && method_exists( $product, 'set_regular_price' ) ) {
			$product->set_regular_price( self::non_negative_decimal_or_clear( $row['regular_price'], 'regular_price' ) );
		}

		if ( self::has_value_or_clear( $row, 'sale_price' ) && method_exists( $product, 'set_sale_price' ) ) {
			$product->set_sale_price( self::non_negative_decimal_or_clear( $row['sale_price'], 'sale_price' ) );
		}

		if ( method_exists( $product, 'set_date_on_sale_from' ) && method_exists( $product, 'set_date_on_sale_to' ) ) {
			$sale_start = method_exists( $product, 'get_date_on_sale_from' ) ? $product->get_date_on_sale_from( 'edit' ) : null;
			$sale_end   = method_exists( $product, 'get_date_on_sale_to' ) ? $product->get_date_on_sale_to( 'edit' ) : null;

			if ( self::has_value_or_clear( $row, 'sale_start' ) ) {
				$sale_start = self::date_or_clear( $row['sale_start'] );
			}

			if ( self::has_value_or_clear( $row, 'sale_end' ) ) {
				$sale_end = self::date_or_clear( $row['sale_end'] );
			}

			if ( $sale_start && $sale_end && $sale_start->getTimestamp() > $sale_end->getTimestamp() ) {
				throw new RuntimeException( __( 'sale_end must be later than or equal to sale_start.', 'wc-product-importer' ) );
			}

			if ( self::has_value_or_clear( $row, 'sale_start' ) ) {
				$product->set_date_on_sale_from( $sale_start );
			}

			if ( self::has_value_or_clear( $row, 'sale_end' ) ) {
				$product->set_date_on_sale_to( $sale_end );
			}
		}

		if ( self::has_value( $row, 'tax_status' ) ) {
			$product->set_tax_status( self::validate_choice( $row['tax_status'], array( 'taxable', 'shipping', 'none' ), 'tax_status' ) );
		}

		if ( self::has_value_or_clear( $row, 'tax_class' ) ) {
			$product->set_tax_class( sanitize_title( self::text_or_clear( $row['tax_class'] ) ) );
		}

		if ( self::has_value( $row, 'manage_stock' ) ) {
			$product->set_manage_stock( self::to_bool( $row['manage_stock'] ) );
		}

		if ( self::has_value( $row, 'stock_quantity' ) ) {
			$product->set_manage_stock( true );
			$quantity = self::stock_amount( $row['stock_quantity'] );
			$product->set_stock_quantity( $quantity );

			if ( ! self::has_value( $row, 'stock_status' ) ) {
				$product->set_stock_status( $quantity > 0 ? 'instock' : 'outofstock' );
			}
		}

		if ( self::has_value( $row, 'stock_status' ) ) {
			$product->set_stock_status( self::validate_stock_status( $row['stock_status'] ) );
		}

		if ( self::has_value( $row, 'backorders' ) ) {
			$product->set_backorders( self::validate_backorders( $row['backorders'] ) );
		}

		if ( self::has_value( $row, 'sold_individually' ) ) {
			$product->set_sold_individually( self::to_bool( $row['sold_individually'] ) );
		}

		if ( self::has_value_or_clear( $row, 'weight' ) ) {
			$product->set_weight( self::non_negative_decimal_or_clear( $row['weight'], 'weight' ) );
		}

		if ( self::has_value_or_clear( $row, 'length' ) ) {
			$product->set_length( self::non_negative_decimal_or_clear( $row['length'], 'length' ) );
		}

		if ( self::has_value_or_clear( $row, 'width' ) ) {
			$product->set_width( self::non_negative_decimal_or_clear( $row['width'], 'width' ) );
		}

		if ( self::has_value_or_clear( $row, 'height' ) ) {
			$product->set_height( self::non_negative_decimal_or_clear( $row['height'], 'height' ) );
		}

		if ( self::has_value( $row, 'virtual' ) ) {
			$product->set_virtual( self::to_bool( $row['virtual'] ) );
		}

		if ( self::has_value( $row, 'downloadable' ) ) {
			$product->set_downloadable( self::to_bool( $row['downloadable'] ) );
		}

		if ( self::has_value_or_clear( $row, 'download_limit' ) ) {
			$value = self::text_or_clear( $row['download_limit'] );
			$product->set_download_limit( '' === $value ? -1 : self::integer_value( $value, 'download_limit', -1 ) );
		}

		if ( self::has_value_or_clear( $row, 'download_expiry' ) ) {
			$value = self::text_or_clear( $row['download_expiry'] );
			$product->set_download_expiry( '' === $value ? -1 : self::integer_value( $value, 'download_expiry', -1 ) );
		}

		if ( self::has_value_or_clear( $row, 'purchase_note' ) ) {
			$product->set_purchase_note( self::text_or_clear( $row['purchase_note'] ) );
		}

		if ( self::has_value( $row, 'menu_order' ) ) {
			$product->set_menu_order( self::integer_value( $row['menu_order'], 'menu_order' ) );
		}

		if ( self::has_value( $row, 'reviews_allowed' ) ) {
			$product->set_reviews_allowed( self::to_bool( $row['reviews_allowed'] ) );
		}

		if ( self::has_value_or_clear( $row, 'shipping_class' ) ) {
			$product->set_shipping_class_id( self::resolve_shipping_class_id( self::text_or_clear( $row['shipping_class'] ) ) );
		}

		if ( self::has_value_or_clear( $row, 'downloads' ) ) {
			$product->set_downloads( self::parse_downloads( self::text_or_clear( $row['downloads'] ) ) );
		}
	}

	/**
	 * Apply fields specific to variable, variation, external, and grouped products.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $row     Row data.
	 * @return void
	 * @throws RuntimeException On invalid values.
	 */
	private static function apply_type_specific_fields( WC_Product $product, array $row ) {
		if ( $product->is_type( 'variable' ) && self::has_value_or_clear( $row, 'attributes' ) ) {
			$product->set_attributes( self::parse_parent_attributes( self::text_or_clear( $row['attributes'] ), true ) );
		}

		if ( $product->is_type( 'variable' ) && self::has_value_or_clear( $row, 'default_attributes' ) ) {
			$product->set_default_attributes( self::parse_variation_attributes( self::text_or_clear( $row['default_attributes'] ) ) );
		}

		if ( $product->is_type( 'variation' ) ) {
			if ( self::has_value( $row, 'parent_sku' ) ) {
				$parent_id = self::resolve_variable_parent_id( self::get_string( $row, 'parent_sku' ) );
				$product->set_parent_id( $parent_id );
			}

			$has_attribute_source = false;
			$attribute_source     = '';

			if ( self::has_value_or_clear( $row, 'variation_attributes' ) ) {
				$has_attribute_source = true;
				$attribute_source     = self::text_or_clear( $row['variation_attributes'] );
			} elseif ( self::has_value_or_clear( $row, 'attributes' ) ) {
				$has_attribute_source = true;
				$attribute_source     = self::text_or_clear( $row['attributes'] );
			}

			if ( $has_attribute_source ) {
				$product->set_attributes( self::parse_variation_attributes( $attribute_source ) );
			}
		} elseif ( self::has_value_or_clear( $row, 'attributes' ) && ! $product->is_type( 'variable' ) ) {
			$product->set_attributes( self::parse_parent_attributes( self::text_or_clear( $row['attributes'] ), false ) );
		}

		if ( $product->is_type( 'external' ) ) {
			if ( self::has_value_or_clear( $row, 'external_url' ) ) {
				$value = self::text_or_clear( $row['external_url'] );
				$url   = esc_url_raw( $value );

				if ( '' !== $value && ( '' === $url || ! wp_http_validate_url( $url ) ) ) {
					throw new RuntimeException( __( 'external_url must be a valid HTTP or HTTPS URL.', 'wc-product-importer' ) );
				}

				$product->set_product_url( $url );
			}

			if ( self::has_value_or_clear( $row, 'button_text' ) ) {
				$product->set_button_text( self::text_or_clear( $row['button_text'] ) );
			}
		}

		if ( $product->is_type( 'grouped' ) && self::has_value_or_clear( $row, 'grouped_products' ) ) {
			$product->set_children( self::resolve_product_skus( self::text_or_clear( $row['grouped_products'] ) ) );
		}
	}

	/**
	 * Apply product categories and tags.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $row     Row data.
	 * @return void
	 * @throws RuntimeException On taxonomy errors.
	 */
	private static function apply_taxonomies( WC_Product $product, array $row ) {
		if ( $product->is_type( 'variation' ) ) {
			return;
		}

		if ( self::has_value_or_clear( $row, 'categories' ) ) {
			$value = self::text_or_clear( $row['categories'] );
			$product->set_category_ids( '' === $value ? array() : self::resolve_categories( $value ) );
		}

		if ( self::has_value_or_clear( $row, 'tags' ) ) {
			$value = self::text_or_clear( $row['tags'] );
			$product->set_tag_ids( '' === $value ? array() : self::resolve_tags( $value ) );
		}
	}

	/**
	 * Apply featured and gallery images.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $row     Row data.
	 * @return void
	 * @throws RuntimeException On image import failure.
	 */
	private static function apply_images( WC_Product $product, array $row ) {
		$product_id = $product->get_id();

		if ( ! $product_id ) {
			return;
		}

		if ( self::has_value_or_clear( $row, 'featured_image' ) ) {
			$value = self::text_or_clear( $row['featured_image'] );
			$product->set_image_id( '' === $value ? 0 : self::resolve_image_id( $value, $product_id ) );
		}

		if ( ! $product->is_type( 'variation' ) && self::has_value_or_clear( $row, 'gallery_images' ) ) {
			$value = self::text_or_clear( $row['gallery_images'] );

			if ( '' === $value ) {
				$product->set_gallery_image_ids( array() );
			} else {
				$ids = array();

				foreach ( self::split_pipe( $value ) as $image ) {
					$ids[] = self::resolve_image_id( $image, $product_id );
				}

				$product->set_gallery_image_ids( array_values( array_unique( array_filter( $ids ) ) ) );
			}
		}
	}

	/**
	 * Import custom metadata from columns named meta:key.
	 *
	 * Protected metadata beginning with "_" is intentionally rejected.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $row     Row data.
	 * @return void
	 */
	private static function apply_custom_meta( WC_Product $product, array $row ) {
		foreach ( $row as $column => $value ) {
			if ( 0 !== strpos( $column, 'meta:' ) || ! self::has_value_or_clear( $row, $column ) ) {
				continue;
			}

			$key = sanitize_key( substr( $column, 5 ) );

			if ( '' === $key || 0 === strpos( $key, '_' ) ) {
				continue;
			}

			if ( self::is_clear( $value ) ) {
				$product->delete_meta_data( $key );
			} else {
				$product->update_meta_data( $key, sanitize_text_field( (string) $value ) );
			}
		}
	}

	/**
	 * Resolve a shipping class by slug or name, creating it if necessary.
	 *
	 * @param string $value Shipping class.
	 * @return int
	 * @throws RuntimeException On term creation error.
	 */
	private static function resolve_shipping_class_id( $value ) {
		if ( '' === $value ) {
			return 0;
		}

		$term = get_term_by( 'slug', sanitize_title( $value ), 'product_shipping_class' );

		if ( ! $term ) {
			$term = get_term_by( 'name', $value, 'product_shipping_class' );
		}

		if ( $term ) {
			return (int) $term->term_id;
		}

		$created = wp_insert_term(
			$value,
			'product_shipping_class',
			array(
				'slug' => sanitize_title( $value ),
			)
		);

		return self::inserted_term_id_or_throw( $created );
	}

	/**
	 * Resolve or create hierarchical product categories.
	 *
	 * @param string $value Category paths separated by "|".
	 * @return array
	 * @throws RuntimeException On taxonomy errors.
	 */
	private static function resolve_categories( $value ) {
		$ids = array();

		foreach ( self::split_pipe( $value ) as $category_path ) {
			$segments = array_map( 'trim', explode( '>', $category_path ) );

			if ( in_array( '', $segments, true ) ) {
				throw new RuntimeException( __( 'Each category path must contain a name on both sides of every ">" separator.', 'wc-product-importer' ) );
			}

			$parent_id = 0;

			foreach ( $segments as $segment ) {
				$existing = term_exists( $segment, 'product_cat', $parent_id );

				if ( $existing ) {
					$term_id = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;
				} else {
					$created = wp_insert_term(
						$segment,
						'product_cat',
						array(
							'parent' => $parent_id,
						)
					);

					$term_id = self::inserted_term_id_or_throw( $created );
				}

				$parent_id = $term_id;
			}

			if ( $parent_id ) {
				$ids[] = $parent_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Resolve or create product tags.
	 *
	 * @param string $value Tag names separated by "|".
	 * @return array
	 * @throws RuntimeException On taxonomy errors.
	 */
	private static function resolve_tags( $value ) {
		$ids = array();

		foreach ( self::split_pipe( $value ) as $tag ) {
			$existing = term_exists( $tag, 'product_tag' );

			if ( $existing ) {
				$ids[] = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;
				continue;
			}

			$created = wp_insert_term( $tag, 'product_tag' );

			$ids[] = self::inserted_term_id_or_throw( $created );
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Return a newly inserted term ID, including a concurrent existing term.
	 *
	 * @param array|WP_Error $created Result from wp_insert_term().
	 * @return int
	 * @throws RuntimeException On term creation errors.
	 */
	private static function inserted_term_id_or_throw( $created ) {
		if ( ! is_wp_error( $created ) ) {
			return (int) $created['term_id'];
		}

		if ( 'term_exists' === $created->get_error_code() ) {
			$existing = $created->get_error_data( 'term_exists' );
			$term_id  = is_array( $existing ) && isset( $existing['term_id'] ) ? (int) $existing['term_id'] : (int) $existing;

			if ( $term_id > 0 ) {
				return $term_id;
			}
		}

		throw new RuntimeException( $created->get_error_message() );
	}

	/**
	 * Parse custom product attributes.
	 *
	 * Format: Color=Red|Blue;Size=S|M|L
	 *
	 * @param string $value        Raw value.
	 * @param bool   $for_variation Whether attributes should be variation attributes.
	 * @return array
	 * @throws RuntimeException On malformed attributes.
	 */
	private static function parse_parent_attributes( $value, $for_variation ) {
		if ( '' === $value ) {
			return array();
		}

		$attributes = array();
		$position   = 0;
		$seen       = array();

		foreach ( array_filter( array_map( 'trim', explode( ';', $value ) ), 'strlen' ) as $definition ) {
			$parts = explode( '=', $definition, 2 );

			if ( 2 !== count( $parts ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: malformed attribute definition. */
						__( 'Malformed attribute definition "%s". Use Name=Value|Value.', 'wc-product-importer' ),
						$definition
					)
				);
			}

			$name    = sanitize_text_field( trim( $parts[0] ) );
			$options = array_map( 'sanitize_text_field', self::split_pipe( $parts[1] ) );

			if ( '' === $name || empty( $options ) ) {
				throw new RuntimeException( __( 'Each product attribute must have a name and at least one value.', 'wc-product-importer' ) );
			}

			$key = sanitize_title( $name );

			if ( isset( $seen[ $key ] ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: duplicate attribute name. */
						__( 'Duplicate attribute definition: %s.', 'wc-product-importer' ),
						$name
					)
				);
			}

			$seen[ $key ] = true;

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $name );
			$attribute->set_options( array_values( array_unique( $options ) ) );
			$attribute->set_position( $position );
			$attribute->set_visible( true );
			$attribute->set_variation( (bool) $for_variation );

			$attributes[] = $attribute;
			++$position;
		}

		return $attributes;
	}

	/**
	 * Parse variation/default attributes.
	 *
	 * Format: Color=Red;Size=M
	 *
	 * @param string $value Raw value.
	 * @return array
	 * @throws RuntimeException On malformed attributes.
	 */
	private static function parse_variation_attributes( $value ) {
		$attributes = array();

		if ( '' === $value ) {
			return $attributes;
		}

		foreach ( array_filter( array_map( 'trim', explode( ';', $value ) ), 'strlen' ) as $definition ) {
			$parts = explode( '=', $definition, 2 );

			if ( 2 !== count( $parts ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: malformed attribute definition. */
						__( 'Malformed variation attribute "%s". Use Name=Value.', 'wc-product-importer' ),
						$definition
					)
				);
			}

			$name  = sanitize_title( trim( $parts[0] ) );
			$term  = sanitize_text_field( trim( $parts[1] ) );

			if ( '' === $name ) {
				throw new RuntimeException( __( 'Each variation attribute must have a name.', 'wc-product-importer' ) );
			}

			if ( array_key_exists( $name, $attributes ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: duplicate attribute name. */
						__( 'Duplicate variation attribute definition: %s.', 'wc-product-importer' ),
						$name
					)
				);
			}

			$attributes[ $name ] = $term;
		}

		return $attributes;
	}

	/**
	 * Resolve a variable parent product by SKU.
	 *
	 * @param string $parent_sku Parent SKU.
	 * @return int
	 * @throws RuntimeException If the parent cannot be resolved.
	 */
	private static function resolve_variable_parent_id( $parent_sku ) {
		$parent_id = wc_get_product_id_by_sku( $parent_sku );
		$parent    = $parent_id ? wc_get_product( $parent_id ) : false;

		if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
			throw new RuntimeException( __( 'The variation parent could not be found or is not a variable product.', 'wc-product-importer' ) );
		}

		return (int) $parent_id;
	}

	/**
	 * Resolve a list of product SKUs to IDs.
	 *
	 * @param string $value SKU list.
	 * @return array
	 * @throws RuntimeException If a child SKU does not exist.
	 */
	private static function resolve_product_skus( $value ) {
		if ( '' === $value ) {
			return array();
		}

		$ids = array();

		foreach ( self::split_pipe( $value ) as $sku ) {
			$id = wc_get_product_id_by_sku( $sku );

			if ( ! $id ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: SKU. */
						__( 'Referenced product SKU "%s" could not be found.', 'wc-product-importer' ),
						$sku
					)
				);
			}

			$ids[] = $id;
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Parse downloadable files.
	 *
	 * Format: Name=https://example.com/file.pdf|Second=https://example.com/file2.zip
	 *
	 * @param string $value Raw download definitions.
	 * @return array
	 * @throws RuntimeException On malformed downloads.
	 */
	private static function parse_downloads( $value ) {
		$downloads = array();

		if ( '' === $value ) {
			return $downloads;
		}

		foreach ( self::split_pipe( $value ) as $definition ) {
			$parts = explode( '=', $definition, 2 );

			if ( 2 !== count( $parts ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: malformed download definition. */
						__( 'Malformed download definition "%s". Use Name=https://example.com/file.', 'wc-product-importer' ),
						$definition
					)
				);
			}

			$name = sanitize_text_field( trim( $parts[0] ) );
			$url  = esc_url_raw( trim( $parts[1] ) );

			if ( '' === $name || '' === $url || ! wp_http_validate_url( $url ) ) {
				throw new RuntimeException( __( 'Each download must have a name and a valid HTTP or HTTPS URL.', 'wc-product-importer' ) );
			}

			$download = new WC_Product_Download();
			$download->set_id( md5( $url ) );
			$download->set_name( $name );
			$download->set_file( $url );

			$downloads[ $download->get_id() ] = $download;
		}

		return $downloads;
	}

	/**
	 * Resolve an attachment ID or sideload a remote image URL.
	 *
	 * @param string $value      Attachment ID or URL.
	 * @param int    $product_id Product ID.
	 * @return int
	 * @throws RuntimeException On invalid image.
	 */
	private static function resolve_image_id( $value, $product_id ) {
		$value = trim( $value );

		if ( isset( self::$resolved_image_ids[ $value ] ) ) {
			return self::$resolved_image_ids[ $value ];
		}

		if ( ctype_digit( $value ) ) {
			$attachment_id = (int) $value;

			if ( wp_attachment_is_image( $attachment_id ) ) {
				self::$resolved_image_ids[ $value ] = $attachment_id;

				return self::$resolved_image_ids[ $value ];
			}

			throw new RuntimeException( __( 'An image attachment ID in the file is invalid.', 'wc-product-importer' ) );
		}

		$url = esc_url_raw( $value );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			throw new RuntimeException( __( 'An image URL in the file is invalid.', 'wc-product-importer' ) );
		}

		$existing_id = attachment_url_to_postid( $url );

		if ( $existing_id && wp_attachment_is_image( $existing_id ) ) {
			self::$resolved_image_ids[ $value ] = (int) $existing_id;

			return self::$resolved_image_ids[ $value ];
		}

		$existing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_wc_sfpi_source_url',
				'meta_value'     => $url,
				'no_found_rows'  => true,
			)
		);

		if ( ! empty( $existing[0] ) && wp_attachment_is_image( $existing[0] ) ) {
			self::$resolved_image_ids[ $value ] = (int) $existing[0];

			return self::$resolved_image_ids[ $value ];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = self::sideload_remote_image( $url, $product_id );

		if ( is_wp_error( $attachment_id ) ) {
			throw new RuntimeException( $attachment_id->get_error_message() );
		}

		update_post_meta( $attachment_id, '_wc_sfpi_source_url', $url );
		self::$resolved_image_ids[ $value ] = (int) $attachment_id;

		return self::$resolved_image_ids[ $value ];
	}

	/**
	 * Download and sideload an image with explicit network and size limits.
	 *
	 * wp_safe_remote_get() validates the initial URL and every redirect. Streaming
	 * to a temporary file avoids holding remote content in PHP memory.
	 *
	 * @param string $url        Remote image URL.
	 * @param int    $product_id Product ID.
	 * @return int|WP_Error
	 */
	private static function sideload_remote_image( $url, $product_id ) {
		$url_path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$filename = sanitize_file_name( wp_basename( rawurldecode( $url_path ) ) );

		if ( '' === $filename || '' === pathinfo( $filename, PATHINFO_EXTENSION ) ) {
			return new WP_Error( 'wc_sfpi_image_filename', __( 'The remote image URL must end with an image filename and extension.', 'wc-product-importer' ) );
		}

		$tmp_name = wp_tempnam( $filename );

		if ( ! $tmp_name ) {
			return new WP_Error( 'wc_sfpi_image_temp_file', __( 'A temporary file could not be created for the remote image.', 'wc-product-importer' ) );
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::REMOTE_IMAGE_TIMEOUT,
				'redirection'         => self::REMOTE_IMAGE_REDIRECTS,
				'stream'              => true,
				'filename'            => $tmp_name,
				'limit_response_size' => self::MAX_REMOTE_IMAGE_SIZE + 1,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::delete_temp_file( $tmp_name );
			return $response;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			self::delete_temp_file( $tmp_name );
			return new WP_Error( 'wc_sfpi_image_http_status', __( 'The remote image server returned an unsuccessful response.', 'wc-product-importer' ) );
		}

		$content_length = wp_remote_retrieve_header( $response, 'content-length' );

		if ( is_numeric( $content_length ) && (float) $content_length > self::MAX_REMOTE_IMAGE_SIZE ) {
			self::delete_temp_file( $tmp_name );
			return new WP_Error( 'wc_sfpi_image_too_large', __( 'The remote image exceeds the 10 MB size limit.', 'wc-product-importer' ) );
		}

		$file_size = file_exists( $tmp_name ) ? filesize( $tmp_name ) : false;

		if ( false === $file_size || $file_size <= 0 ) {
			self::delete_temp_file( $tmp_name );
			return new WP_Error( 'wc_sfpi_image_empty', __( 'The remote image download was empty.', 'wc-product-importer' ) );
		}

		if ( $file_size > self::MAX_REMOTE_IMAGE_SIZE ) {
			self::delete_temp_file( $tmp_name );
			return new WP_Error( 'wc_sfpi_image_too_large', __( 'The remote image exceeds the 10 MB size limit.', 'wc-product-importer' ) );
		}

		$actual_mime = wp_get_image_mime( $tmp_name );
		$filetype    = wp_check_filetype_and_ext( $tmp_name, $filename );

		if (
			! $actual_mime ||
			0 !== strpos( $actual_mime, 'image/' ) ||
			empty( $filetype['ext'] ) ||
			empty( $filetype['type'] ) ||
			0 !== strpos( $filetype['type'], 'image/' )
		) {
			self::delete_temp_file( $tmp_name );
			return new WP_Error( 'wc_sfpi_image_type', __( 'The downloaded file is not a permitted image type.', 'wc-product-importer' ) );
		}

		if ( ! empty( $filetype['proper_filename'] ) ) {
			$filename = sanitize_file_name( $filetype['proper_filename'] );
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => $filename,
				'tmp_name' => $tmp_name,
				'error'    => 0,
				'size'     => $file_size,
			),
			$product_id
		);

		if ( is_wp_error( $attachment_id ) ) {
			self::delete_temp_file( $tmp_name );
		}

		return $attachment_id;
	}

	/**
	 * Delete a temporary file if it still exists.
	 *
	 * @param string $path Temporary file path.
	 * @return void
	 */
	private static function delete_temp_file( $path ) {
		if ( $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Read XLSX or CSV into a matrix.
	 *
	 * @param string $path      Temporary upload path.
	 * @param string $extension File extension.
	 * @return array
	 * @throws RuntimeException On invalid file.
	 */
	private static function read_import_file( $path, $extension ) {
		if ( 'csv' === $extension ) {
			return self::read_csv( $path );
		}

		return self::read_xlsx( $path );
	}

	/**
	 * Read a CSV file.
	 *
	 * @param string $path File path.
	 * @return array
	 * @throws RuntimeException On unreadable file.
	 */
	private static function read_csv( $path ) {
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			throw new RuntimeException( __( 'The CSV file could not be opened.', 'wc-product-importer' ) );
		}

		try {
			$first_line = fgets( $handle );

			if ( false === $first_line ) {
				throw new RuntimeException( __( 'The CSV file is empty.', 'wc-product-importer' ) );
			}

			if ( false !== strpos( $first_line, "\0" ) ) {
				throw new RuntimeException( __( 'The CSV file contains binary data and cannot be imported.', 'wc-product-importer' ) );
			}

			$delimiter = self::detect_csv_delimiter( $first_line );
			rewind( $handle );

			$rows       = array();
			$cell_count = 0;

			while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) {
				if ( count( $row ) > self::MAX_COLUMNS ) {
					throw new RuntimeException(
						sprintf(
							/* translators: %d: maximum number of columns. */
							__( 'The CSV contains more than the allowed %d columns.', 'wc-product-importer' ),
							self::MAX_COLUMNS
						)
					);
				}

				foreach ( $row as $value ) {
					if ( is_string( $value ) && false !== strpos( $value, "\0" ) ) {
						throw new RuntimeException( __( 'The CSV file contains binary data and cannot be imported.', 'wc-product-importer' ) );
					}

					self::validate_cell_value( $value, 'CSV' );
				}

				$cell_count += count( $row );

				if ( $cell_count > self::MAX_IMPORT_CELLS ) {
					throw new RuntimeException(
						sprintf(
							/* translators: %d: maximum number of cells. */
							__( 'The CSV contains more than the allowed %d total cells.', 'wc-product-importer' ),
							self::MAX_IMPORT_CELLS
						)
					);
				}

				if ( ! empty( $row[0] ) && 0 === strpos( $row[0], "\xEF\xBB\xBF" ) ) {
					$row[0] = substr( $row[0], 3 );
				}

				$rows[] = array_map(
					static function ( $value ) {
						return is_string( $value ) ? trim( $value ) : $value;
					},
					$row
				);

				if ( count( $rows ) > self::MAX_ROWS + 1 ) {
					break;
				}
			}

			return $rows;
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Detect common CSV delimiters.
	 *
	 * @param string $line First CSV line.
	 * @return string
	 */
	private static function detect_csv_delimiter( $line ) {
		$delimiters = array(
			','  => self::count_unquoted_delimiter( $line, ',' ),
			';'  => self::count_unquoted_delimiter( $line, ';' ),
			"\t" => self::count_unquoted_delimiter( $line, "\t" ),
		);

		arsort( $delimiters );

		$delimiter = key( $delimiters );

		return $delimiters[ $delimiter ] > 0 ? $delimiter : ',';
	}

	/**
	 * Count delimiter characters that are outside quoted CSV fields.
	 *
	 * @param string $line      CSV line.
	 * @param string $delimiter Candidate delimiter.
	 * @return int
	 */
	private static function count_unquoted_delimiter( $line, $delimiter ) {
		$count     = 0;
		$in_quotes = false;
		$length    = strlen( $line );

		for ( $index = 0; $index < $length; ++$index ) {
			$character = $line[ $index ];

			if ( '"' === $character ) {
				if ( $in_quotes && $index + 1 < $length && '"' === $line[ $index + 1 ] ) {
					++$index;
					continue;
				}

				$in_quotes = ! $in_quotes;
				continue;
			}

			if ( ! $in_quotes && $delimiter === $character ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Read the first worksheet from an XLSX file.
	 *
	 * Uses ZipArchive + SimpleXML to avoid any external PHP library.
	 *
	 * @param string $path File path.
	 * @return array
	 * @throws RuntimeException On malformed/unsupported XLSX.
	 */
	private static function read_xlsx( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( __( 'The PHP ZipArchive extension is required to read XLSX files.', 'wc-product-importer' ) );
		}

		if ( ! function_exists( 'simplexml_load_string' ) ) {
			throw new RuntimeException( __( 'The PHP SimpleXML extension is required to read XLSX files.', 'wc-product-importer' ) );
		}

		$zip = new ZipArchive();

		if ( true !== $zip->open( $path ) ) {
			throw new RuntimeException( __( 'The XLSX file is not a valid ZIP-based Excel workbook.', 'wc-product-importer' ) );
		}

		try {
			if ( false === $zip->locateName( '[Content_Types].xml' ) || false === $zip->locateName( 'xl/workbook.xml' ) ) {
				throw new RuntimeException( __( 'The uploaded XLSX file is missing required workbook data.', 'wc-product-importer' ) );
			}

			$sheet_path = self::xlsx_first_sheet_path( $zip );

			self::validate_xlsx_xml_budget(
				$zip,
				array(
					'[Content_Types].xml',
					'xl/workbook.xml',
					'xl/_rels/workbook.xml.rels',
					'xl/sharedStrings.xml',
					$sheet_path,
				)
			);

			$shared_strings = self::xlsx_shared_strings( $zip );
			$sheet_xml      = self::xlsx_entry_contents( $zip, $sheet_path, true );

			$xml = self::safe_simplexml( $sheet_xml );

			if ( ! $xml ) {
				throw new RuntimeException( __( 'The first Excel worksheet contains invalid XML.', 'wc-product-importer' ) );
			}

			$xml->registerXPathNamespace( 'main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
			$row_nodes  = $xml->xpath( '//main:sheetData/main:row' );
			$rows       = array();
			$cell_count = 0;

			if ( empty( $row_nodes ) ) {
				return $rows;
			}

			foreach ( $row_nodes as $row_node ) {
				$row          = array();
				$row_children = $row_node->children( 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );

				foreach ( $row_children->c as $cell ) {
					$attributes   = $cell->attributes();
					$reference    = isset( $attributes['r'] ) ? (string) $attributes['r'] : '';
					$column_index = self::xlsx_column_index( $reference );

					if ( $column_index < 0 ) {
						throw new RuntimeException( __( 'The Excel worksheet contains a cell without a valid reference.', 'wc-product-importer' ) );
					}

					if ( $column_index >= self::MAX_COLUMNS ) {
						throw new RuntimeException(
							sprintf(
								/* translators: %d: maximum number of columns. */
								__( 'The Excel worksheet contains a cell beyond the allowed %d columns.', 'wc-product-importer' ),
								self::MAX_COLUMNS
							)
						);
					}

					if ( array_key_exists( $column_index, $row ) ) {
						throw new RuntimeException( __( 'The Excel worksheet contains duplicate cell references in a row.', 'wc-product-importer' ) );
					}

					$type         = isset( $attributes['t'] ) ? (string) $attributes['t'] : 'n';
					$value        = '';

					$cell_children = $cell->children( 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );

					if ( 'inlineStr' === $type ) {
						$value = self::xlsx_inline_string( $cell );
					} elseif ( isset( $cell_children->v ) ) {
						$raw = (string) $cell_children->v;

						if ( 's' === $type ) {
							if ( 1 !== preg_match( '/^\d+$/D', $raw ) || ! array_key_exists( (int) $raw, $shared_strings ) ) {
								throw new RuntimeException( __( 'The Excel worksheet contains an invalid shared-string reference.', 'wc-product-importer' ) );
							}

							$shared_index = (int) $raw;
							$value        = $shared_strings[ $shared_index ];
						} elseif ( 'b' === $type ) {
							if ( ! in_array( $raw, array( '0', '1' ), true ) ) {
								throw new RuntimeException( __( 'The Excel worksheet contains an invalid boolean cell.', 'wc-product-importer' ) );
							}

							$value = $raw;
						} elseif ( 'n' === $type ) {
							if ( '' !== $raw && ! is_numeric( $raw ) ) {
								throw new RuntimeException( __( 'The Excel worksheet contains an invalid numeric cell.', 'wc-product-importer' ) );
							}

							$value = $raw;
						} elseif ( 'str' === $type ) {
							$value = $raw;
						} else {
							throw new RuntimeException( __( 'The Excel worksheet contains an unsupported cell type.', 'wc-product-importer' ) );
						}
					} elseif ( ! in_array( $type, array( 'n', 'str' ), true ) ) {
						throw new RuntimeException( __( 'The Excel worksheet contains an unsupported empty cell type.', 'wc-product-importer' ) );
					}

					self::validate_cell_value( $value, 'Excel' );

					$row[ $column_index ] = trim( (string) $value );
				}

				if ( empty( $row ) ) {
					$rows[] = array();
				} else {
					$max_index  = max( array_keys( $row ) );
					$normalized = array();

					for ( $i = 0; $i <= $max_index; $i++ ) {
						$normalized[] = isset( $row[ $i ] ) ? $row[ $i ] : '';
					}

					$cell_count += $max_index + 1;

					if ( $cell_count > self::MAX_IMPORT_CELLS ) {
						throw new RuntimeException(
							sprintf(
								/* translators: %d: maximum number of cells. */
								__( 'The Excel worksheet contains more than the allowed %d total cells.', 'wc-product-importer' ),
								self::MAX_IMPORT_CELLS
							)
						);
					}

					$rows[] = $normalized;
				}

				if ( count( $rows ) > self::MAX_ROWS + 1 ) {
					break;
				}
			}

			return $rows;
		} finally {
			$zip->close();
		}
	}

	/**
	 * Read a size-limited XML entry from an XLSX archive.
	 *
	 * @param ZipArchive $zip      XLSX archive.
	 * @param string     $path     Entry path.
	 * @param bool       $required Whether a missing entry is an error.
	 * @return string|false
	 * @throws RuntimeException On a missing, unreadable, or oversized entry.
	 */
	private static function xlsx_entry_contents( ZipArchive $zip, $path, $required ) {
		$stat = $zip->statName( $path );

		if ( false === $stat ) {
			if ( $required ) {
				throw new RuntimeException( __( 'The Excel workbook is missing required XML data.', 'wc-product-importer' ) );
			}

			return false;
		}

		$size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;

		if ( $size < 0 || $size > self::MAX_XLSX_XML_SIZE ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %s: XLSX archive entry path. */
					__( 'The Excel workbook contains an oversized XML entry: %s.', 'wc-product-importer' ),
					$path
				)
			);
		}

		$contents = $zip->getFromName( $path );

		if ( false === $contents ) {
			if ( $required ) {
				throw new RuntimeException( __( 'Required Excel workbook XML could not be read.', 'wc-product-importer' ) );
			}

			return false;
		}

		if ( strlen( $contents ) > self::MAX_XLSX_XML_SIZE ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %s: XLSX archive entry path. */
					__( 'The Excel workbook contains an oversized XML entry: %s.', 'wc-product-importer' ),
					$path
				)
			);
		}

		return $contents;
	}

	/**
	 * Enforce a total uncompressed-size budget for XML used by the importer.
	 *
	 * @param ZipArchive $zip   XLSX archive.
	 * @param array      $paths XML entry paths.
	 * @return void
	 * @throws RuntimeException When the XML budget is exceeded.
	 */
	private static function validate_xlsx_xml_budget( ZipArchive $zip, array $paths ) {
		$total = 0;

		foreach ( array_unique( $paths ) as $path ) {
			$stat = $zip->statName( $path );

			if ( false === $stat ) {
				continue;
			}

			$size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;

			if ( $size < 0 || $size > self::MAX_XLSX_XML_SIZE ) {
				throw new RuntimeException( __( 'The Excel workbook exceeds the safe uncompressed XML limit.', 'wc-product-importer' ) );
			}

			$total += $size;

			if ( $total > self::MAX_XLSX_XML_SIZE ) {
				throw new RuntimeException( __( 'The Excel workbook exceeds the safe uncompressed XML limit.', 'wc-product-importer' ) );
			}
		}
	}

	/**
	 * Load XLSX shared strings.
	 *
	 * @param ZipArchive $zip XLSX archive.
	 * @return array
	 */
	private static function xlsx_shared_strings( ZipArchive $zip ) {
		$xml_string = self::xlsx_entry_contents( $zip, 'xl/sharedStrings.xml', false );

		if ( false === $xml_string ) {
			return array();
		}

		$xml = self::safe_simplexml( $xml_string );

		if ( ! $xml ) {
			throw new RuntimeException( __( 'The Excel shared-strings table contains invalid XML.', 'wc-product-importer' ) );
		}

		$strings  = array();
		$children = $xml->children( 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );

		foreach ( $children->si as $item ) {
			$text          = '';
			$item_children = $item->children( 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );

			if ( isset( $item_children->t ) ) {
				$text = (string) $item_children->t;
			} elseif ( isset( $item_children->r ) ) {
				foreach ( $item_children->r as $run ) {
					$run_children = $run->children( 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
					$text        .= isset( $run_children->t ) ? (string) $run_children->t : '';
				}
			}

			$strings[] = $text;
		}

		return $strings;
	}

	/**
	 * Get the first worksheet path from workbook relationships.
	 *
	 * @param ZipArchive $zip XLSX archive.
	 * @return string
	 * @throws RuntimeException On malformed workbook.
	 */
	private static function xlsx_first_sheet_path( ZipArchive $zip ) {
		$workbook_xml = self::xlsx_entry_contents( $zip, 'xl/workbook.xml', true );
		$rels_xml     = self::xlsx_entry_contents( $zip, 'xl/_rels/workbook.xml.rels', false );
		$workbook     = self::safe_simplexml( $workbook_xml );

		if ( ! $workbook ) {
			throw new RuntimeException( __( 'The Excel workbook contains invalid XML.', 'wc-product-importer' ) );
		}

		if ( false === $rels_xml ) {
			return 'xl/worksheets/sheet1.xml';
		}

		$rels = self::safe_simplexml( $rels_xml );

		if ( ! $rels ) {
			throw new RuntimeException( __( 'The Excel workbook relationships contain invalid XML.', 'wc-product-importer' ) );
		}

		$workbook->registerXPathNamespace( 'main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
		$workbook->registerXPathNamespace( 'r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );

		$sheets = $workbook->xpath( '//main:sheets/main:sheet' );

		if ( empty( $sheets[0] ) ) {
			return 'xl/worksheets/sheet1.xml';
		}

		$sheet_attributes = $sheets[0]->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
		$relationship_id  = isset( $sheet_attributes['id'] ) ? (string) $sheet_attributes['id'] : '';

		if ( '' === $relationship_id ) {
			return 'xl/worksheets/sheet1.xml';
		}

		$relationship_children = $rels->children( 'http://schemas.openxmlformats.org/package/2006/relationships' );

		foreach ( $relationship_children->Relationship as $relationship ) {
			$attributes = $relationship->attributes();

			if ( isset( $attributes['Id'], $attributes['Target'] ) && (string) $attributes['Id'] === $relationship_id ) {
				$target = str_replace( '\\', '/', (string) $attributes['Target'] );
				$target = ltrim( $target, '/' );

				if ( 0 !== strpos( $target, 'xl/' ) ) {
					$target = 'xl/' . $target;
				}

				if (
					false !== strpos( $target, '../' ) ||
					0 !== strpos( $target, 'xl/worksheets/' ) ||
					'.xml' !== strtolower( substr( $target, -4 ) )
				) {
					throw new RuntimeException( __( 'The first Excel worksheet has an invalid archive path.', 'wc-product-importer' ) );
				}

				return $target;
			}
		}

		return 'xl/worksheets/sheet1.xml';
	}

	/**
	 * Safely load XML with external network access disabled.
	 *
	 * @param string $xml XML data.
	 * @return SimpleXMLElement|false
	 */
	private static function safe_simplexml( $xml ) {
		if ( false !== stripos( $xml, '<!DOCTYPE' ) ) {
			return false;
		}

		$previous = libxml_use_internal_errors( true );
		$object   = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $object;
	}

	/**
	 * Read an inline XLSX string.
	 *
	 * @param SimpleXMLElement $cell Cell.
	 * @return string
	 */
	private static function xlsx_inline_string( $cell ) {
		$children = $cell->children( 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );

		if ( ! isset( $children->is ) ) {
			return '';
		}

		$inline_children = $children->is->children( 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );

		if ( isset( $inline_children->t ) ) {
			return (string) $inline_children->t;
		}

		$text = '';

		if ( isset( $inline_children->r ) ) {
			foreach ( $inline_children->r as $run ) {
				$run_children = $run->children( 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
				$text        .= isset( $run_children->t ) ? (string) $run_children->t : '';
			}
		}

		return $text;
	}

	/**
	 * Convert an Excel cell reference to a zero-based column index.
	 *
	 * Example: A1 => 0, B1 => 1, AA1 => 26.
	 *
	 * @param string $reference Cell reference.
	 * @return int
	 */
	private static function xlsx_column_index( $reference ) {
		if ( ! preg_match( '/^([A-Z]+)[1-9][0-9]*$/i', $reference, $matches ) ) {
			return -1;
		}

		$letters = strtoupper( $matches[1] );
		$index   = 0;
		$length  = strlen( $letters );

		for ( $i = 0; $i < $length; $i++ ) {
			$index = ( $index * 26 ) + ( ord( $letters[ $i ] ) - 64 );
		}

		return $index - 1;
	}

	/**
	 * Get the canonical full-import columns.
	 *
	 * @return array
	 */
	private static function full_import_headers() {
		return array(
			'id',
			'sku',
			'type',
			'parent_sku',
			'name',
			'slug',
			'status',
			'catalog_visibility',
			'description',
			'short_description',
			'regular_price',
			'sale_price',
			'sale_start',
			'sale_end',
			'tax_status',
			'tax_class',
			'manage_stock',
			'stock_quantity',
			'stock_status',
			'backorders',
			'sold_individually',
			'weight',
			'length',
			'width',
			'height',
			'shipping_class',
			'virtual',
			'downloadable',
			'downloads',
			'download_limit',
			'download_expiry',
			'categories',
			'tags',
			'featured_image',
			'gallery_images',
			'attributes',
			'variation_attributes',
			'default_attributes',
			'external_url',
			'button_text',
			'grouped_products',
			'purchase_note',
			'menu_order',
			'reviews_allowed',
		);
	}

	/**
	 * Get the canonical price and inventory columns.
	 *
	 * @return array
	 */
	private static function price_stock_headers() {
		return array(
			'id',
			'sku',
			'regular_price',
			'sale_price',
			'manage_stock',
			'stock_quantity',
			'stock_status',
			'backorders',
		);
	}

	/**
	 * Validate normalized headers for the selected import mode.
	 *
	 * @param array  $headers Normalized headers.
	 * @param string $mode    Import mode.
	 * @return void
	 * @throws RuntimeException On invalid headers.
	 */
	private static function validate_import_headers( array $headers, $mode ) {
		if ( empty( $headers ) ) {
			throw new RuntimeException( __( 'The header row is empty.', 'wc-product-importer' ) );
		}

		if ( count( $headers ) > self::MAX_COLUMNS ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %d: maximum number of columns. */
					__( 'The import contains too many columns. The maximum is %d.', 'wc-product-importer' ),
					self::MAX_COLUMNS
				)
			);
		}

		if ( in_array( '', $headers, true ) ) {
			throw new RuntimeException( __( 'Every populated header cell must contain a column name.', 'wc-product-importer' ) );
		}

		if ( count( $headers ) !== count( array_unique( $headers ) ) ) {
			throw new RuntimeException( __( 'The file contains duplicate column names after normalization.', 'wc-product-importer' ) );
		}

		if ( ! in_array( 'sku', $headers, true ) && ! in_array( 'id', $headers, true ) ) {
			throw new RuntimeException( __( 'The file must contain an "sku" or "id" column.', 'wc-product-importer' ) );
		}

		$allowed = 'price_stock' === $mode ? self::price_stock_headers() : self::full_import_headers();
		$unknown = array();

		foreach ( $headers as $header ) {
			if ( in_array( $header, $allowed, true ) ) {
				continue;
			}

			if (
				'full' === $mode &&
				0 === strpos( $header, 'meta:' ) &&
				strlen( $header ) > 5 &&
				'_' !== substr( $header, 5, 1 )
			) {
				continue;
			}

			$unknown[] = $header;
		}

		if ( ! empty( $unknown ) ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %s: comma-separated column names. */
					__( 'Unsupported column(s) for this import mode: %s.', 'wc-product-importer' ),
					implode( ', ', $unknown )
				)
			);
		}
	}

	/**
	 * Normalize spreadsheet headers to canonical field names.
	 *
	 * @param array $headers Raw headers.
	 * @return array
	 */
	private static function normalize_headers( array $headers ) {
		$aliases = array(
			'product_id'        => 'id',
			'product_sku'       => 'sku',
			'product_type'      => 'type',
			'title'             => 'name',
			'product_name'      => 'name',
			'price'             => 'regular_price',
			'regularprice'      => 'regular_price',
			'saleprice'         => 'sale_price',
			'stock'             => 'stock_quantity',
			'quantity'          => 'stock_quantity',
			'qty'               => 'stock_quantity',
			'inventory'         => 'stock_quantity',
			'manage_inventory'  => 'manage_stock',
			'featured_image_url' => 'featured_image',
			'image'             => 'featured_image',
			'gallery'           => 'gallery_images',
			'parent'            => 'parent_sku',
			'product_url'       => 'external_url',
		);

		while ( ! empty( $headers ) && '' === trim( (string) end( $headers ) ) ) {
			array_pop( $headers );
		}

		$normalized = array();

		foreach ( $headers as $header ) {
			$header = trim( (string) $header );

			if ( 0 === strpos( strtolower( $header ), 'meta:' ) ) {
				$key          = sanitize_key( substr( $header, 5 ) );
				$normalized[] = '' === $key ? '' : 'meta:' . $key;
				continue;
			}

			$header = strtolower( $header );
			$header = preg_replace( '/[\s\-]+/', '_', $header );
			$header = preg_replace( '/[^a-z0-9_]/', '', $header );
			$header = trim( $header, '_' );

			$normalized[] = isset( $aliases[ $header ] ) ? $aliases[ $header ] : $header;
		}

		return $normalized;
	}

	/**
	 * Combine headers and row values safely.
	 *
	 * @param array $headers Header names.
	 * @param array $values  Row values.
	 * @return array
	 * @throws RuntimeException On unexpected row data.
	 */
	private static function combine_row( array $headers, array $values ) {
		if ( count( $values ) > self::MAX_COLUMNS ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %d: maximum number of columns. */
					__( 'This row contains more than the allowed %d columns.', 'wc-product-importer' ),
					self::MAX_COLUMNS
				)
			);
		}

		$extra_values = array_slice( $values, count( $headers ) );

		foreach ( $extra_values as $extra_value ) {
			if ( '' !== trim( (string) $extra_value ) ) {
				throw new RuntimeException( __( 'This row contains data beyond the last named header column.', 'wc-product-importer' ) );
			}
		}

		$row = array();

		foreach ( $headers as $index => $header ) {
			if ( '' === $header ) {
				continue;
			}

			$row[ $header ] = isset( $values[ $index ] ) ? trim( (string) $values[ $index ] ) : '';
		}

		return $row;
	}

	/**
	 * Determine whether an associative row is empty.
	 *
	 * @param array $row Row.
	 * @return bool
	 */
	private static function row_is_empty( array $row ) {
		foreach ( $row as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check if a column exists and is non-blank.
	 *
	 * @param array  $row Row.
	 * @param string $key Key.
	 * @return bool
	 */
	private static function has_value( array $row, $key ) {
		return array_key_exists( $key, $row ) && '' !== trim( (string) $row[ $key ] );
	}

	/**
	 * Check if a column has a value or explicit clear token.
	 *
	 * @param array  $row Row.
	 * @param string $key Key.
	 * @return bool
	 */
	private static function has_value_or_clear( array $row, $key ) {
		return self::has_value( $row, $key );
	}

	/**
	 * Check clear token.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_clear( $value ) {
		return self::CLEAR_TOKEN === strtoupper( trim( (string) $value ) );
	}

	/**
	 * Get sanitized string from row.
	 *
	 * @param array  $row Row.
	 * @param string $key Key.
	 * @return string
	 */
	private static function get_string( array $row, $key ) {
		return self::has_value( $row, $key ) ? wc_clean( $row[ $key ] ) : '';
	}

	/**
	 * Get integer from row.
	 *
	 * @param array  $row Row.
	 * @param string $key Key.
	 * @return int
	 */
	private static function get_int( array $row, $key ) {
		return self::has_value( $row, $key ) ? self::integer_value( $row[ $key ], $key, 1 ) : 0;
	}

	/**
	 * Parse an integer and optionally enforce a minimum value.
	 *
	 * @param mixed    $value   Raw value.
	 * @param string   $field   Field name.
	 * @param int|null $minimum Minimum accepted value, or null for no minimum.
	 * @return int
	 * @throws RuntimeException On an invalid or out-of-range integer.
	 */
	private static function integer_value( $value, $field, $minimum = null ) {
		$raw = trim( (string) $value );

		if ( ! preg_match( '/^[+-]?[0-9]+$/', $raw ) ) {
			$integer = false;
		} else {
			$is_negative = '-' === substr( $raw, 0, 1 );
			$digits      = ltrim( $raw, '+-' );
			$digits      = ltrim( $digits, '0' );
			$digits      = '' === $digits ? '0' : $digits;
			$limit       = $is_negative ? substr( (string) PHP_INT_MIN, 1 ) : (string) PHP_INT_MAX;

			if ( strlen( $digits ) > strlen( $limit ) || ( strlen( $digits ) === strlen( $limit ) && strcmp( $digits, $limit ) > 0 ) ) {
				$integer = false;
			} else {
				$integer = (int) $raw;
			}
		}

		if ( false === $integer || ( null !== $minimum && $integer < $minimum ) ) {
			throw new RuntimeException(
				sprintf(
					/* translators: 1: invalid value, 2: field name. */
					__( '"%1$s" is not a valid integer for %2$s.', 'wc-product-importer' ),
					$value,
					$field
				)
			);
		}

		return (int) $integer;
	}

	/**
	 * Sanitize text or return empty for clear token.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text_or_clear( $value ) {
		return self::is_clear( $value ) ? '' : sanitize_text_field( (string) $value );
	}

	/**
	 * Sanitize rich text or return empty for clear token.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function html_or_clear( $value ) {
		return self::is_clear( $value ) ? '' : wp_kses_post( (string) $value );
	}

	/**
	 * Format decimal or return empty for clear token.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws RuntimeException On invalid decimal.
	 */
	private static function decimal_or_clear( $value ) {
		if ( self::is_clear( $value ) ) {
			return '';
		}

		$normalized = wc_format_decimal( trim( (string) $value ) );

		if ( '' === $normalized || ! is_numeric( $normalized ) ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %s: invalid numeric value. */
					__( '"%s" is not a valid numeric value.', 'wc-product-importer' ),
					$value
				)
			);
		}

		return $normalized;
	}

	/**
	 * Format a non-negative decimal or return empty for the clear token.
	 *
	 * @param mixed  $value Value.
	 * @param string $field Field name for errors.
	 * @return string
	 * @throws RuntimeException On negative or non-finite values.
	 */
	private static function non_negative_decimal_or_clear( $value, $field ) {
		$normalized = self::decimal_or_clear( $value );

		if ( '' !== $normalized && ( ! is_finite( (float) $normalized ) || (float) $normalized < 0 ) ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %s: field name. */
					__( '%s must be a non-negative numeric value.', 'wc-product-importer' ),
					$field
				)
			);
		}

		return $normalized;
	}

	/**
	 * Parse a stock quantity safely.
	 *
	 * @param mixed $value Raw stock value.
	 * @return int|float
	 * @throws RuntimeException On invalid stock quantity.
	 */
	private static function stock_amount( $value ) {
		$normalized = wc_format_decimal( trim( (string) $value ) );

		if ( '' === $normalized || ! is_numeric( $normalized ) ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %s: invalid stock value. */
					__( '"%s" is not a valid stock quantity.', 'wc-product-importer' ),
					$value
				)
			);
		}

		return wc_stock_amount( $normalized );
	}

	/**
	 * Parse date for WooCommerce or clear it.
	 *
	 * @param mixed $value Date value.
	 * @return WC_DateTime|null
	 * @throws RuntimeException On invalid date.
	 */
	private static function date_or_clear( $value ) {
		if ( self::is_clear( $value ) ) {
			return null;
		}

		try {
			return wc_string_to_datetime( trim( (string) $value ) );
		} catch ( Exception $exception ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %s: invalid date. */
					__( '"%s" is not a valid date. Use a format such as YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.', 'wc-product-importer' ),
					$value
				)
			);
		}
	}

	/**
	 * Parse a boolean value.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 * @throws RuntimeException On invalid boolean.
	 */
	private static function to_bool( $value ) {
		$value = strtolower( trim( (string) $value ) );

		if ( in_array( $value, array( '1', 'true', 'yes', 'y', 'on' ), true ) ) {
			return true;
		}

		if ( in_array( $value, array( '0', 'false', 'no', 'n', 'off' ), true ) ) {
			return false;
		}

		throw new RuntimeException(
			sprintf(
				/* translators: %s: invalid boolean value. */
				__( '"%s" is not a valid boolean value. Use yes/no, true/false, or 1/0.', 'wc-product-importer' ),
				$value
			)
		);
	}

	/**
	 * Validate an allowed choice.
	 *
	 * @param mixed  $value   Raw value.
	 * @param array  $allowed Allowed values.
	 * @param string $field   Field name.
	 * @return string
	 * @throws RuntimeException On invalid choice.
	 */
	private static function validate_choice( $value, array $allowed, $field ) {
		$value = sanitize_key( $value );

		if ( ! in_array( $value, $allowed, true ) ) {
			throw new RuntimeException(
				sprintf(
					/* translators: 1: field name, 2: allowed values. */
					__( 'Invalid %1$s. Allowed values: %2$s.', 'wc-product-importer' ),
					$field,
					implode( ', ', $allowed )
				)
			);
		}

		return $value;
	}

	/**
	 * Validate a stock status.
	 *
	 * @param mixed $value Raw stock status.
	 * @return string
	 */
	private static function validate_stock_status( $value ) {
		return self::validate_choice( $value, array( 'instock', 'outofstock', 'onbackorder' ), 'stock_status' );
	}

	/**
	 * Validate backorders.
	 *
	 * @param mixed $value Raw backorder value.
	 * @return string
	 */
	private static function validate_backorders( $value ) {
		return self::validate_choice( $value, array( 'no', 'notify', 'yes' ), 'backorders' );
	}

	/**
	 * Split a pipe-separated value.
	 *
	 * @param string $value Raw value.
	 * @return array
	 */
	private static function split_pipe( $value ) {
		$parts = array_map( 'trim', explode( '|', $value ) );

		if ( in_array( '', $parts, true ) ) {
			throw new RuntimeException( __( 'Pipe-separated values cannot contain empty items.', 'wc-product-importer' ) );
		}

		return array_values( $parts );
	}

	/**
	 * Validate a parsed spreadsheet cell before retaining it in memory.
	 *
	 * @param mixed  $value  Cell value.
	 * @param string $format Human-readable input format.
	 * @return void
	 * @throws RuntimeException On invalid UTF-8 or an oversized cell.
	 */
	private static function validate_cell_value( $value, $format ) {
		if ( ! is_string( $value ) ) {
			return;
		}

		if ( strlen( $value ) > self::MAX_CELL_SIZE ) {
			throw new RuntimeException(
				sprintf(
					/* translators: 1: file format, 2: size in bytes. */
					__( 'A %1$s cell exceeds the allowed %2$d-byte size.', 'wc-product-importer' ),
					$format,
					self::MAX_CELL_SIZE
				)
			);
		}

		if ( 1 !== preg_match( '//u', $value ) ) {
			throw new RuntimeException(
				sprintf(
					/* translators: %s: file format. */
					__( 'The %s file contains text that is not valid UTF-8.', 'wc-product-importer' ),
					$format
				)
			);
		}
	}

	/**
	 * Create one error result entry.
	 *
	 * @param int    $row     Row number.
	 * @param string $sku     SKU.
	 * @param string $message Error message.
	 * @return array
	 */
	private static function error_entry( $row, $sku, $message ) {
		return array(
			'row'     => (int) $row,
			'sku'     => (string) $sku,
			'message' => wp_strip_all_tags( (string) $message ),
		);
	}

	/**
	 * Download CSV templates without any extra plugin files.
	 *
	 * @return void
	 */
	public static function download_template() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to download this template.', 'wc-product-importer' ) );
		}

		check_admin_referer( self::TEMPLATE_NONCE );

		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'full';

		if ( 'price_stock' === $mode ) {
			$filename = 'woocommerce-price-stock-import-template.csv';
			$headers  = self::price_stock_headers();
			$sample   = array(
				'',
				'SKU-001',
				'99.90',
				'79.90',
				'yes',
				'25',
				'instock',
				'no',
			);
		} else {
			$filename = 'woocommerce-full-product-import-template.csv';
			$headers  = array_merge( self::full_import_headers(), array( 'meta:brand' ) );
			$sample   = array(
				'',
				'SKU-001',
				'simple',
				'',
				'Example Product',
				'example-product',
				'draft',
				'visible',
				'Long product description',
				'Short product description',
				'99.90',
				'79.90',
				'2026-09-01',
				'2026-09-10',
				'taxable',
				'',
				'yes',
				'25',
				'instock',
				'no',
				'no',
				'1.2',
				'20',
				'10',
				'5',
				'Standard',
				'no',
				'no',
				'',
				'-1',
				'-1',
				'Example Category > Child|Sale',
				'featured|example',
				'',
				'',
				'Color=Red|Blue;Size=S|M|L',
				'',
				'',
				'',
				'',
				'',
				'',
				'0',
				'yes',
				'Example Brand',
			);
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'wb' );

		if ( false === $output ) {
			wp_die( esc_html__( 'Could not open the output stream.', 'wc-product-importer' ) );
		}

		// UTF-8 BOM improves Excel compatibility.
		fwrite( $output, "\xEF\xBB\xBF" );
		fputcsv( $output, $headers, ',', '"', '' );
		fputcsv( $output, $sample, ',', '"', '' );
		fclose( $output );
		exit;
	}
}

add_action( 'plugins_loaded', array( 'WC_Single_File_Product_Importer', 'init' ), 20 );
