<?php
/**
 * Dependency-free regression tests for parsing and validation helpers.
 *
 * Run with: php tests/run.php
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

function add_action() {}

function __( $text ) {
	return $text;
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_title( $value ) {
	$value = strtolower( trim( (string) $value ) );
	$value = preg_replace( '/[^a-z0-9]+/', '-', $value );

	return trim( $value, '-' );
}

function esc_url_raw( $value ) {
	return wp_http_validate_url( $value ) ? (string) $value : '';
}

function wp_http_validate_url( $value ) {
	$scheme = parse_url( (string) $value, PHP_URL_SCHEME );

	return in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true );
}

function wc_format_decimal( $value ) {
	$value = trim( (string) $value );

	return is_numeric( $value ) ? $value : '';
}

function wc_clean( $value ) {
	return sanitize_text_field( $value );
}

function sanitize_file_name( $value ) {
	return preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $value );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_basename( $path ) {
	return basename( $path );
}

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code, $message, $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function wp_tempnam() {
	return tempnam( sys_get_temp_dir(), 'wc-sfpi-image-' );
}

function wp_safe_remote_get( $url, array $args ) {
	$GLOBALS['last_remote_url']  = $url;
	$GLOBALS['last_remote_args'] = $args;
	file_put_contents( $args['filename'], isset( $GLOBALS['remote_body'] ) ? $GLOBALS['remote_body'] : 'image' );

	return array(
		'response' => array( 'code' => isset( $GLOBALS['remote_status'] ) ? $GLOBALS['remote_status'] : 200 ),
		'headers'  => isset( $GLOBALS['remote_headers'] ) ? $GLOBALS['remote_headers'] : array(),
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

function wp_remote_retrieve_header( $response, $name ) {
	$name = strtolower( $name );

	return isset( $response['headers'][ $name ] ) ? $response['headers'][ $name ] : '';
}

function wp_get_image_mime() {
	return isset( $GLOBALS['remote_mime'] ) ? $GLOBALS['remote_mime'] : 'image/png';
}

function wp_check_filetype_and_ext() {
	return isset( $GLOBALS['remote_filetype'] ) ? $GLOBALS['remote_filetype'] : array(
		'ext'             => 'png',
		'type'            => 'image/png',
		'proper_filename' => false,
	);
}

function media_handle_sideload( $file ) {
	$GLOBALS['last_sideload_file'] = $file;

	if ( file_exists( $file['tmp_name'] ) ) {
		unlink( $file['tmp_name'] );
	}

	return 321;
}

function wp_delete_file( $path ) {
	if ( file_exists( $path ) ) {
		unlink( $path );
	}
}

class WC_Product {
	private $id;
	private $sku;

	public function __construct( $id = 0, $sku = '' ) {
		$this->id  = $id;
		$this->sku = $sku;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_sku() {
		return $this->sku;
	}
}

function wc_get_product( $id ) {
	return isset( $GLOBALS['products_by_id'][ $id ] ) ? $GLOBALS['products_by_id'][ $id ] : false;
}

function wc_get_product_id_by_sku( $sku ) {
	return isset( $GLOBALS['product_ids_by_sku'][ $sku ] ) ? $GLOBALS['product_ids_by_sku'][ $sku ] : 0;
}

class WC_Product_Attribute {
	public $name;
	public $options;

	public function set_id( $value ) {}

	public function set_name( $value ) {
		$this->name = $value;
	}

	public function set_options( $value ) {
		$this->options = $value;
	}

	public function set_position( $value ) {}

	public function set_visible( $value ) {}

	public function set_variation( $value ) {}
}

class WC_Product_Download {
	private $id;
	private $name;
	private $file;

	public function set_id( $value ) {
		$this->id = $value;
	}

	public function get_id() {
		return $this->id;
	}

	public function set_name( $value ) {
		$this->name = $value;
	}

	public function set_file( $value ) {
		$this->file = $value;
	}
}

require_once dirname( __DIR__ ) . '/wc-product-importer.php';

$tests_run = 0;

function invoke_private( $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( 'WC_Single_File_Product_Importer', $method );

	if ( PHP_VERSION_ID < 80100 ) {
		$reflection->setAccessible( true );
	}

	return $reflection->invokeArgs( null, $arguments );
}

function assert_same( $expected, $actual, $message ) {
	global $tests_run;
	++$tests_run;

	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true )
		);
	}
}

function assert_throws( $method, array $arguments, $message_fragment ) {
	global $tests_run;
	++$tests_run;

	try {
		invoke_private( $method, $arguments );
	} catch ( RuntimeException $exception ) {
		if ( false === strpos( $exception->getMessage(), $message_fragment ) ) {
			throw new RuntimeException(
				'Unexpected exception from ' . $method . ': ' . $exception->getMessage()
			);
		}

		return;
	}

	throw new RuntimeException( 'Expected ' . $method . ' to throw a RuntimeException.' );
}

function assert_wp_error_code( $expected_code, $actual, $message ) {
	global $tests_run;
	++$tests_run;

	if ( ! is_wp_error( $actual ) || $expected_code !== $actual->get_error_code() ) {
		throw new RuntimeException( $message );
	}
}

$headers = invoke_private(
	'normalize_headers',
	array( array( 'Product ID', 'Product SKU', 'Regular Price', 'meta:Brand', '', '' ) )
);
assert_same(
	array( 'id', 'sku', 'regular_price', 'meta:brand' ),
	$headers,
	'Header aliases, metadata, and trailing blank columns should normalize.'
);

invoke_private( 'validate_import_headers', array( $headers, 'full' ) );
assert_same( true, true, 'Valid full-import headers should be accepted.' );

assert_throws(
	'validate_import_headers',
	array( array( 'sku', 'regular_prce' ), 'full' ),
	'Unsupported column'
);
assert_throws(
	'validate_import_headers',
	array( array( 'sku', 'name' ), 'price_stock' ),
	'Unsupported column'
);
assert_throws(
	'validate_import_headers',
	array( array( 'sku', 'meta:_protected' ), 'full' ),
	'Unsupported column'
);
assert_throws(
	'validate_import_headers',
	array( array( 'sku', '', 'name' ), 'full' ),
	'must contain a column name'
);
assert_throws(
	'validate_import_headers',
	array( array( 'sku', 'sku' ), 'full' ),
	'duplicate column'
);

$row = invoke_private( 'combine_row', array( array( 'sku', 'name' ), array( 'SKU-1', 'Product', '' ) ) );
assert_same( array( 'sku' => 'SKU-1', 'name' => 'Product' ), $row, 'Trailing blank row cells should be ignored.' );

assert_throws(
	'combine_row',
	array( array( 'sku', 'name' ), array( 'SKU-1', 'Product', 'unexpected' ) ),
	'beyond the last named header'
);

assert_same( 12, invoke_private( 'integer_value', array( '12', 'id', 1 ) ), 'Valid integers should parse.' );
assert_same( 12, invoke_private( 'integer_value', array( '0012', 'id', 1 ) ), 'Integers with spreadsheet-style leading zeroes should parse.' );
assert_same( -1, invoke_private( 'integer_value', array( '-1', 'download_limit', -1 ) ), 'The unlimited download value should parse.' );
assert_throws( 'integer_value', array( '2.5', 'menu_order' ), 'not a valid integer' );
assert_throws( 'integer_value', array( '0', 'id', 1 ), 'not a valid integer' );
assert_throws( 'integer_value', array( '999999999999999999999999', 'id', 1 ), 'not a valid integer' );
assert_same( '12.50', invoke_private( 'non_negative_decimal_or_clear', array( '12.50', 'regular_price' ) ), 'Non-negative decimals should parse.' );
assert_throws( 'non_negative_decimal_or_clear', array( '-0.01', 'regular_price' ), 'non-negative' );

$product_42 = new WC_Product( 42, 'SKU-42' );
$GLOBALS['products_by_id'] = array( 42 => $product_42 );
$GLOBALS['product_ids_by_sku'] = array( 'SKU-42' => 42 );
assert_throws( 'find_product', array( array( 'id' => '999', 'sku' => 'SKU-42' ) ), 'does not identify an existing' );
assert_same(
	array( 'id:42', 'sku:sku-42' ),
	invoke_private( 'identity_keys_for_row', array( array( 'id' => '42' ), $product_42 ) ),
	'Matched product identifiers should include both the stored ID and SKU.'
);
assert_same(
	invoke_private( 'identity_keys_for_row', array( array( 'id' => '42' ), $product_42 ) ),
	invoke_private( 'identity_keys_for_row', array( array( 'sku' => 'SKU-42' ), $product_42 ) ),
	'ID-only and SKU-only rows for the same product should have the same identity keys.'
);

assert_throws( 'split_pipe', array( 'one||two' ), 'cannot contain empty items' );
assert_throws( 'resolve_categories', array( '>' ), 'both sides' );

assert_same( ',', invoke_private( 'detect_csv_delimiter', array( "sku,name\n" ) ), 'Comma delimiters should be detected.' );
assert_same( ';', invoke_private( 'detect_csv_delimiter', array( "sku;\"meta:note,thing\"\n" ) ), 'Quoted commas should not affect delimiter detection.' );

assert_same( 0, invoke_private( 'xlsx_column_index', array( 'A1' ) ), 'Column A should have index 0.' );
assert_same( 26, invoke_private( 'xlsx_column_index', array( 'AA1' ) ), 'Column AA should have index 26.' );
assert_same( -1, invoke_private( 'xlsx_column_index', array( '1' ) ), 'Malformed cell references should be rejected.' );
assert_same( -1, invoke_private( 'xlsx_column_index', array( 'A' ) ), 'Cell references without row numbers should be rejected.' );

$attributes = invoke_private( 'parse_parent_attributes', array( 'Color=Red|Blue;Size=S|M', true ) );
assert_same( 2, count( $attributes ), 'Valid parent attributes should parse.' );
assert_throws( 'parse_parent_attributes', array( 'Color', true ), 'Malformed attribute definition' );
assert_throws( 'parse_parent_attributes', array( 'Color=Red;color=Blue', true ), 'Duplicate attribute definition' );
assert_throws( 'parse_variation_attributes', array( 'Color=Red;Color=Blue' ), 'Duplicate variation attribute definition' );

$downloads = invoke_private( 'parse_downloads', array( 'Manual=https://example.com/manual.pdf' ) );
assert_same( 1, count( $downloads ), 'Valid downloads should parse.' );
assert_throws( 'parse_downloads', array( 'Missing URL' ), 'Malformed download definition' );
assert_throws( 'parse_downloads', array( 'Manual=not-a-url' ), 'valid HTTP or HTTPS URL' );

if ( function_exists( 'simplexml_load_string' ) ) {
	assert_same( false, invoke_private( 'safe_simplexml', array( '<!DOCTYPE x><x />' ) ), 'DOCTYPE declarations should be rejected.' );
}

$csv_path = tempnam( sys_get_temp_dir(), 'wc-sfpi-' );

if ( false === $csv_path ) {
	throw new RuntimeException( 'Could not create a temporary CSV fixture.' );
}

try {
	file_put_contents( $csv_path, "sku;name\nSKU-1;\"Test, Product\"\n" );
	$rows = invoke_private( 'read_csv', array( $csv_path ) );
	assert_same( 'Test, Product', $rows[1][1], 'Semicolon CSV files should preserve quoted commas.' );

	file_put_contents( $csv_path, "sku,name\nSKU-1,\0binary\n" );
	assert_throws( 'read_csv', array( $csv_path ), 'binary data' );

	file_put_contents( $csv_path, "sku,name\nSKU-1,\xC3\x28\n" );
	assert_throws( 'read_csv', array( $csv_path ), 'not valid UTF-8' );
} finally {
	unlink( $csv_path );
}

if ( class_exists( 'ZipArchive' ) && function_exists( 'simplexml_load_string' ) ) {
	$xlsx_path = tempnam( sys_get_temp_dir(), 'wc-sfpi-' );

	if ( false === $xlsx_path ) {
		throw new RuntimeException( 'Could not create a temporary XLSX fixture.' );
	}

	$zip = new ZipArchive();
	$zip->open( $xlsx_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( '[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types" />' );
	$zip->addFromString(
		'xl/workbook.xml',
		'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Products" sheetId="1" r:id="rId1" /></sheets></workbook>'
	);
	$zip->addFromString(
		'xl/_rels/workbook.xml.rels',
		'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" /></Relationships>'
	);
	$zip->addFromString(
		'xl/worksheets/sheet1.xml',
		'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>sku</t></is></c><c r="B1" t="inlineStr"><is><t>name</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>SKU-1</t></is></c><c r="B2" t="inlineStr"><is><t>Test Product</t></is></c></row></sheetData></worksheet>'
	);
	$zip->close();

	try {
		$rows = invoke_private( 'read_xlsx', array( $xlsx_path ) );
		assert_same( 'SKU-1', $rows[1][0], 'XLSX inline strings should parse.' );
		assert_same( 'Test Product', $rows[1][1], 'XLSX columns should preserve their indexes.' );

		$zip = new ZipArchive();
		$zip->open( $xlsx_path );
		$zip->addFromString(
			'xl/sharedStrings.xml',
			'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>sku</t></si></sst>'
		);
		$zip->addFromString(
			'xl/worksheets/sheet1.xml',
			'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>invalid</v></c></row></sheetData></worksheet>'
		);
		$zip->close();
		assert_throws( 'read_xlsx', array( $xlsx_path ), 'invalid shared-string reference' );

		$zip = new ZipArchive();
		$zip->open( $xlsx_path );
		$zip->addFromString(
			'xl/worksheets/sheet1.xml',
			'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>sku</t></is></c><c r="A1" t="inlineStr"><is><t>duplicate</t></is></c></row></sheetData></worksheet>'
		);
		$zip->close();
		assert_throws( 'read_xlsx', array( $xlsx_path ), 'duplicate cell references' );
	} finally {
		unlink( $xlsx_path );
	}
}

$GLOBALS['remote_body']     = 'fake image bytes';
$GLOBALS['remote_headers']  = array();
$GLOBALS['remote_status']   = 200;
$GLOBALS['remote_mime']     = 'image/png';
$GLOBALS['remote_filetype'] = array( 'ext' => 'png', 'type' => 'image/png', 'proper_filename' => false );
assert_same( 321, invoke_private( 'sideload_remote_image', array( 'https://example.com/photo.png', 10 ) ), 'A bounded valid image should be sideloaded.' );
assert_same( true, ! empty( $GLOBALS['last_remote_args']['stream'] ), 'Remote images should stream to disk.' );
assert_same( 15, $GLOBALS['last_remote_args']['timeout'], 'Remote images should use a short timeout.' );
assert_same( 10485761, $GLOBALS['last_remote_args']['limit_response_size'], 'Remote image responses should be capped.' );

$GLOBALS['remote_headers'] = array( 'content-length' => '10485761' );
assert_wp_error_code(
	'wc_sfpi_image_too_large',
	invoke_private( 'sideload_remote_image', array( 'https://example.com/large.png', 10 ) ),
	'Oversized remote images should be rejected.'
);

echo 'All ' . $tests_run . " tests passed.\n";
