<?php
/**
 * Plugin Name:       SureCart Bulk Coupons
 * Description:       A plugin to generate bulk surecart coupons with many options
 * Tested up to:      6.8.2
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Version:           1.2
 * Author:            reallyusefulplugins.com
 * Author URI:        https://reallyusefulplugins.com
 * License:           GPL2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sc-bulk-coupons
 * Website:           https://reallyusefulplugins.com
 */

if ( ! defined('ABSPATH') ) {
    exit; // Prevent direct access
}

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Define plugin constants
define('RUP_SC_SC_BULK_COUPONS_VERSION', '0.9');
define('RUP_SC_SC_BULK_COUPONS_SLUG', 'sc-bulk-coupons'); // Replace with your unique slug if needed
define('RUP_SC_SC_BULK_COUPONS_MAIN_FILE', __FILE__);
define('RUP_SC_SC_BULK_COUPONS_DIR', plugin_dir_path(__FILE__));
define('RUP_SC_SC_BULK_COUPONS_URL', plugin_dir_url(__FILE__));

class RUP_SCBG_Bulk_Coupon_Generator {
	const OPTION_KEY  = 'scbg_api_key';
	const PAGE_SLUG   = 'scbg-bulk-coupons';
	const CSV_PREFIX  = 'surecart-promo-codes-'; // used for naming + clean-up
	const PRODUCTS_CACHE_KEY = 'scbg_products_cache_v1'; // cache for product list (15 min)

	/** @var string Hook suffix for our submenu page (for targeted enqueues) */
	private $page_hook = '';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_post' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	public function add_menu() {
		$this->page_hook = add_submenu_page(
			'tools.php',
			'SureCart Bulk Coupons',
			'SureCart Bulk Coupons',
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Load Select2 only on our admin page and init the products field.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( empty( $this->page_hook ) || $hook !== $this->page_hook ) {
			return;
		}

		// Select2 from jsDelivr (lightweight, no extra deps beyond jQuery which WP already provides)
		wp_register_style(
			'scbg-select2',
			'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
			[],
			'4.1.0-rc.0'
		);
		wp_register_script(
			'scbg-select2',
			'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
			[ 'jquery' ],
			'4.1.0-rc.0',
			true
		);

		wp_enqueue_style( 'scbg-select2' );
		wp_enqueue_script( 'scbg-select2' );

		// Minimal styling so the widget has breathing room in WP tables
		$css = <<<CSS
#scbg_product_ids { min-width: 360px; }
.select2-container { min-width: 360px; }
CSS;
		wp_add_inline_style( 'scbg-select2', $css );

		// Initialize Select2 on our multi-select
		$init = <<<JS
jQuery(function($){
  var \$el = $('#scbg_product_ids');
  if (!\$el.length || !$.fn.select2) return;
  \$el.select2({
    width: 'resolve',
    placeholder: 'Select one or more products…',
    allowClear: true,
    closeOnSelect: false
  });
});
JS;
		wp_add_inline_script( 'scbg-select2', $init );
	}

	private function get_api_key() {
		$key = get_option( self::OPTION_KEY );
		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * Fetch products from SureCart API, cached for 15 minutes.
	 *
	 * @param bool $force_refresh Force refresh, bypassing cache.
	 * @return array[] Each: ['id' => 'prod_xxx', 'name' => 'Product Name', 'archived' => bool]
	 */
	private function fetch_products_from_api( $force_refresh = false ) {
		$cache_key = self::PRODUCTS_CACHE_KEY;

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return [];
		}

		$out   = [];
		$page  = 1;
		$limit = 100;

		while ( true ) {
			$url = add_query_arg(
				[
					'limit' => $limit,
					'page'  => $page,
				],
				'https://api.surecart.com/v1/products'
			);

			$res = $this->api_get( $url, $api_key );
			if ( is_wp_error( $res ) ) { break; }

			$items = $res['data'] ?? ( is_array( $res ) ? $res : [] );
			if ( empty( $items ) ) { break; }

			foreach ( $items as $p ) {
				$out[] = [
					'id'       => $p['id']   ?? '',
					'name'     => $p['name'] ?? '(no name)',
					'archived' => ! empty( $p['archived'] ),
				];
			}

			$pg = $res['pagination'] ?? null;
			if ( is_array( $pg ) ) {
				$total = (int) ( $pg['count'] ?? 0 );
				$lim   = (int) ( $pg['limit'] ?? $limit );
				$cur   = (int) ( $pg['page']  ?? $page );
				if ( $total <= $lim * $cur ) { break; }
				$page++;
			} else {
				if ( count( $items ) < $limit ) { break; }
				$page++;
			}
		}

		set_transient( $cache_key, $out, 15 * MINUTE_IN_SECONDS );
		return $out;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		$api_key      = $this->get_api_key();
		$csv_url      = isset( $_GET['scbg_csv'] ) ? esc_url_raw( $_GET['scbg_csv'] ) : '';
		$last_message = isset( $_GET['scbg_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['scbg_msg'] ) ) : '';

		// Optional: allow manual refresh of product cache via button
		if ( isset( $_GET['scbg_refresh_products'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'scbg_refresh_products' ) ) {
			delete_transient( self::PRODUCTS_CACHE_KEY );
			$last_message = 'Products refreshed.';
		}

		$products = $this->fetch_products_from_api( false );
		$refresh_products_url = wp_nonce_url(
			add_query_arg( [ 'page' => self::PAGE_SLUG, 'scbg_refresh_products' => 1 ], admin_url( 'tools.php' ) ),
			'scbg_refresh_products'
		);

		?>
		<div class="wrap">
			<h1>SureCart Coupon Bulk Generator</h1>

			<?php if ( $last_message ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $last_message ); ?></p></div>
			<?php endif; ?>

			<?php
			// Support separate links for full/codes (and keep legacy scbg_csv).
			$links = array();

			if ( isset( $_GET['scbg_csv_full'] ) ) {
			    $links[] = array(
			        'label'   => 'Download (Full)',
			        'url'     => esc_url_raw( wp_unslash( $_GET['scbg_csv_full'] ) ),
			        'primary' => true,
			    );
			}
			if ( isset( $_GET['scbg_csv_codes'] ) ) {
			    $links[] = array(
			        'label'   => 'Download (Codes)',
			        'url'     => esc_url_raw( wp_unslash( $_GET['scbg_csv_codes'] ) ),
			        'primary' => false,
			    );
			}
			// Legacy single param still supported.
			if ( $csv_url ) {
			    $links[] = array(
			        'label'   => 'Download',
			        'url'     => $csv_url,
			        'primary' => true,
			    );
			}

			if ( ! empty( $links ) ) :
			?>
			    <div class="notice notice-info">
			        <p>
			            <?php foreach ( $links as $i => $link ) : ?>
			                <a href="<?php echo esc_url( $link['url'] ); ?>"
			                   target="_blank" rel="noopener"
			                   class="button <?php echo $link['primary'] ? 'button-primary' : ''; ?>"
			                   style="<?php echo $i ? 'margin-left:6px' : ''; ?>">
			                    <?php
			                    // Show filename when available.
			                    $basename = basename( parse_url( $link['url'], PHP_URL_PATH ) );
			                    echo esc_html( $link['label'] . ( $basename ? ' ' . $basename : '' ) );
			                    ?>
			                </a>
			            <?php endforeach; ?>
			        </p>
			    </div>
			<?php endif; ?>


			<h2 class="title">API Key</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ); ?>">
				<?php wp_nonce_field( 'scbg_save_key', 'scbg_nonce_key' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="scbg_api_key">SureCart Secret API Token</label></th>
							<td>
								<input type="password" id="scbg_api_key" name="scbg_api_key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>" autocomplete="off" />
								<p class="description">Paste your SureCart Secret API token (bearer). Stored in WordPress options. Visible only to administrators.</p>
							</td>
						</tr>
					</tbody>
				</table>		
				<p><button type="submit" name="scbg_action" value="save_key" class="button button-secondary">Save API Key</button></p>
			</form>

			<hr />

			<h2 class="title">Generate Coupons & Promotion Codes</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ); ?>">
				<?php wp_nonce_field( 'scbg_generate', 'scbg_nonce_generate' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="scbg_campaign">Campaign (friendly name)</label></th>
							<td>
								<input type="text" id="scbg_campaign" name="campaign" class="regular-text" placeholder="e.g. AppSumo August 2025" required />
								<p class="description">Saved as the coupon name so you can recognize the campaign in SureCart.</p>
							</td>
						</tr>

						<!-- Products multi-select populated from API + Select2 -->
						<tr>
							<th scope="row"><label for="scbg_product_ids">Products</label></th>
							<td>
								<select
									id="scbg_product_ids"
									name="product_ids[]"
									multiple
									size="8"
									data-placeholder="Select one or more products…"
									style="min-width:360px;">
									<?php if ( empty( $products ) ) : ?>
										<option value="">(No products found — save API key and click Refresh)</option>
									<?php else : ?>
										<?php foreach ( $products as $p ) : ?>
											<option value="<?php echo esc_attr( $p['id'] ); ?>">
												<?php echo esc_html( $p['name'] . ( ! empty( $p['archived'] ) ? ' (archived)' : '' ) ); ?>
											</option>
										<?php endforeach; ?>
									<?php endif; ?>
								</select>
								<a href="<?php echo esc_url( $refresh_products_url ); ?>" class="button" style="margin-left:6px;">Refresh products</a>
								<p class="description">Search and multi-select products. Friendly names shown; IDs are submitted.</p>
							</td>
						</tr>

						<tr>
							<th scope="row">Discount Type</th>
							<td>
								<fieldset>
									<label><input type="radio" name="discount_type" value="percent" checked /> Percent Off</label>
									&nbsp;&nbsp;
									<label><input type="radio" name="discount_type" value="amount" /> Fixed Amount Off</label>
								</fieldset>
								<p class="description">Choose <em>Percent Off</em> (e.g., 100 for 100% free) or <em>Fixed Amount</em> (e.g., 10.00).</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="scbg_discount_value">Discount Value</label></th>
							<td>
								<input type="text" id="scbg_discount_value" name="discount_value" class="regular-text" placeholder="e.g. 100 or 10.00" required />
							</td>
						</tr>
						<tr class="scbg-currency-row">
							<th scope="row"><label for="scbg_currency">Currency (for fixed amount)</label></th>
							<td>
								<input type="text" id="scbg_currency" name="currency" class="regular-text" placeholder="e.g. usd" />
								<p class="description">3-letter ISO currency (only needed for Fixed Amount Off).</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="scbg_count">Number of Promotion Codes</label></th>
							<td>
								<input type="number" id="scbg_count" name="count" min="1" max="1000" value="100" required />
								<p class="description">How many unique <em>codes</em> to create (max 1000 per run). All codes reference the single coupon created for this campaign.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="scbg_prefix">Code Prefix (optional)</label></th>
							<td>
								<input type="text" id="scbg_prefix" name="prefix" class="regular-text" placeholder="e.g. appsumo-" />
								<p class="description">Lowercase/uppercase accepted; stored & generated in UPPERCASE. Include any trailing separator you want (e.g., <code>APPSUMO-</code>).</p>
							</td>
						</tr>

						<tr>
							<th scope="row">Code Pattern & Grouping</th>
							<td>
								<p>
									<label>Pattern:
										<select name="code_pattern">
											<option value="readable" selected>Readable (no 0/O or 1/I/l)</option>
											<option value="alnum">Alphanumeric (A–Z, 0–9)</option>
											<option value="alnum_no_vowels">Alphanumeric (no vowels)</option>
											<option value="hex">Hex (0–9, A–F)</option>
											<option value="numeric">Numeric (0–9)</option>
										</select>
									</label>
									&nbsp;&nbsp;
									<label>Separator:
										<input type="text" name="code_group_sep" value="-" size="2" maxlength="1" />
									</label>
								</p>
								<p class="description">Define up to 6 groups (first plus up to 5 additional). Set a group to 0 to skip it.</p>
								<p>
									<label>Group 1 <input type="number" name="group_size_1" min="0" max="32" step="1" value="8" /></label>
									<label>Group 2 <input type="number" name="group_size_2" min="0" max="32" step="1" value="0" /></label>
									<label>Group 3 <input type="number" name="group_size_3" min="0" max="32" step="1" value="0" /></label>
									<label>Group 4 <input type="number" name="group_size_4" min="0" max="32" step="1" value="0" /></label>
									<label>Group 5 <input type="number" name="group_size_5" min="0" max="32" step="1" value="0" /></label>
									<label>Group 6 <input type="number" name="group_size_6" min="0" max="32" step="1" value="0" /></label>
								</p>
								<p class="description">Total characters across all groups is capped at 32.</p>
							</td>
						</tr>

						<tr>
							<th scope="row">Duration</th>
							<td>
								<select name="duration">
									<option value="once">Once</option>
									<option value="forever">Forever</option>
									<option value="repeating">Multiple months</option>
								</select>
								<input type="number" min="1" step="1" name="duration_months" placeholder="# months (if repeating)" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="scbg_usage_limit">Usage limit per code</label></th>
							<td>
								<input type="number" id="scbg_usage_limit" name="usage_limit" min="1" step="1" value="1" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="scbg_usage_per_customer">Usage limit per customer</label></th>
							<td>
								<input type="number" id="scbg_usage_per_customer" name="usage_per_customer" min="1" step="1" value="1" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="scbg_min_subtotal">Minimum order subtotal (optional)</label></th>
							<td>
								<input type="text" id="scbg_min_subtotal" name="min_subtotal" class="regular-text" placeholder="e.g. 0.00" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="scbg_end_date">Coupon end date (UTC, optional)</label></th>
							<td>
								<input type="datetime-local" id="scbg_end_date" name="end_date" />
								<p class="description">Sets the coupon’s <code>redeem_by</code> (UTC).</p>
							</td>
						</tr>
					</tbody>
				</table>
				<!-- Export mode -->
				<p>
					<label for="sc_export_mode"><strong>Export mode</strong></label><br>
					<select name="sc_export_mode" id="sc_export_mode">
						<option value="full" selected>Full CSV</option>
						<option value="codes">Just the codes CSV</option>
						<option value="both">Both</option>
					</select>
				</p>
				<p>
					<button type="submit" name="scbg_action" value="generate" class="button button-primary">Generate Coupon + Codes & CSV</button>
				</p>
			</form>

			<hr />

			<h2 class="title">Maintenance</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ); ?>" onsubmit="return confirm('Delete all CSVs generated by this tool? This cannot be undone.');">
				<?php wp_nonce_field( 'scbg_delete_csvs', 'scbg_nonce_delete' ); ?>
				<p class="description">Deletes files in your uploads folder starting with <code><?php echo esc_html( self::CSV_PREFIX ); ?></code>.</p>
				<p><button type="submit" name="scbg_action" value="delete_csvs" class="button button-secondary">Delete generated CSV files</button></p>
			</form>
		</div>
		<?php
	}

	public function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }

		// Save API key.
		if ( isset( $_POST['scbg_action'] ) && 'save_key' === $_POST['scbg_action'] ) {
			check_admin_referer( 'scbg_save_key', 'scbg_nonce_key' );
			$api_key = isset( $_POST['scbg_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['scbg_api_key'] ) ) : '';
			update_option( self::OPTION_KEY, $api_key );
			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'scbg_msg' => rawurlencode( 'API key saved.' ) ], admin_url( 'tools.php' ) ) );
			exit;
		}

		// Maintenance: delete generated CSVs.
		if ( isset( $_POST['scbg_action'] ) && 'delete_csvs' === $_POST['scbg_action'] ) {
			check_admin_referer( 'scbg_delete_csvs', 'scbg_nonce_delete' );
			$upload_dir = wp_upload_dir();
			$base_dir   = trailingslashit( $upload_dir['basedir'] );
			$pattern    = $base_dir . self::CSV_PREFIX . '*.csv';
			$files      = glob( $pattern );
			$deleted    = 0;

			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					// Safety: only delete inside uploads dir and matching prefix.
					if ( strpos( $file, $base_dir ) === 0 && is_file( $file ) ) {
						if ( @unlink( $file ) ) {
							$deleted++;
						}
					}
				}
			}

			$msg = sprintf( 'Deleted %d CSV file%s.', $deleted, $deleted === 1 ? '' : 's' );
			wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'scbg_msg' => rawurlencode( $msg ) ], admin_url( 'tools.php' ) ) );
			exit;
		}

		// Generate coupon + promotion codes.
		if ( isset( $_POST['scbg_action'] ) && 'generate' === $_POST['scbg_action'] ) {
			check_admin_referer( 'scbg_generate', 'scbg_nonce_generate' );

			$api_key = $this->get_api_key();
			if ( empty( $api_key ) ) {
				wp_die( esc_html__( 'Please save your SureCart API token first.', 'scbg' ) );
			}

			$campaign = isset( $_POST['campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['campaign'] ) ) : '';

			// Read Product IDs from multi-select (no manual typing)
			$raw_product_ids = isset( $_POST['product_ids'] ) ? (array) $_POST['product_ids'] : [];
			$product_ids     = array_values( array_unique( array_filter( array_map( function( $id ) {
				$id = sanitize_text_field( wp_unslash( $id ) );
				// allow letters, digits, underscore, hyphen
				return preg_replace( '/[^A-Za-z0-9_-]/', '', $id );
			}, $raw_product_ids ) ) ) );

			$discount_type       = isset( $_POST['discount_type'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_type'] ) ) : 'percent';
			$discount_value_raw  = isset( $_POST['discount_value'] ) ? trim( wp_unslash( $_POST['discount_value'] ) ) : '';
			$currency            = isset( $_POST['currency'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['currency'] ) ) ) : '';

			$count               = isset( $_POST['count'] ) ? max( 1, min( 1000, absint( $_POST['count'] ) ) ) : 1;

			// Accept lower/upper case, keep hyphen/underscore; store uppercase.
			$prefix_raw          = isset( $_POST['prefix'] ) ? wp_unslash( $_POST['prefix'] ) : '';
			$prefix              = strtoupper( preg_replace( '/[^A-Za-z0-9\-\_]/', '', $prefix_raw ) );

			// Pattern + grouping
			$pattern             = isset( $_POST['code_pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['code_pattern'] ) ) : 'readable';
			$sep_raw             = isset( $_POST['code_group_sep'] ) ? wp_unslash( $_POST['code_group_sep'] ) : '-';
			$group_sep           = substr( $sep_raw, 0, 1 );
			if ( ! preg_match( '/^[A-Za-z0-9\-\_\.]$/', $group_sep ) ) { $group_sep = '-'; }

			$groups = [];
			for ( $g = 1; $g <= 6; $g++ ) {
				$key = "group_size_$g";
				$val = isset( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : ( $g === 1 ? 8 : 0 );
				if ( $val > 0 ) { $groups[] = $val; }
			}
			if ( empty( $groups ) ) { $groups = [ 8 ]; }               // default
			$total_len = array_sum( $groups );
			if ( $total_len > 32 ) {                                    // hard cap
				$trimmed = [];
				$running = 0;
				foreach ( $groups as $size ) {
					if ( $running + $size > 32 ) {
						$size = max( 0, 32 - $running );
					}
					if ( $size > 0 ) {
						$trimmed[] = $size;
						$running  += $size;
					}
					if ( $running >= 32 ) { break; }
				}
				$groups = $trimmed;
			}

			$duration            = isset( $_POST['duration'] ) ? sanitize_text_field( wp_unslash( $_POST['duration'] ) ) : 'once';
			$duration_months     = isset( $_POST['duration_months'] ) ? absint( $_POST['duration_months'] ) : 0;
			$usage_limit         = isset( $_POST['usage_limit'] ) ? max( 1, absint( $_POST['usage_limit'] ) ) : 1;
			$usage_per_customer  = isset( $_POST['usage_per_customer'] ) ? max( 1, absint( $_POST['usage_per_customer'] ) ) : 1;
			$min_subtotal_raw    = isset( $_POST['min_subtotal'] ) ? trim( wp_unslash( $_POST['min_subtotal'] ) ) : '';
			$end_date_raw        = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '';

			// Normalize amounts.
			if ( 'percent' === $discount_type ) {
				$percent_off = (int) round( floatval( $discount_value_raw ) );
				if ( $percent_off < 1 || $percent_off > 100 ) {
					wp_die( esc_html__( 'Percent must be between 1 and 100.', 'scbg' ) );
				}
				$amount_off_cents = null;
			} else {
				$amount = floatval( $discount_value_raw );
				$amount_off_cents = (int) round( $amount * 100 );
				$percent_off = null;
				if ( empty( $currency ) ) {
					wp_die( esc_html__( 'Currency is required for fixed amount discounts.', 'scbg' ) );
				}
			}

			$min_subtotal_cents = '' !== $min_subtotal_raw ? (int) round( floatval( $min_subtotal_raw ) * 100 ) : null;
			$coupon_redeem_by   = '' !== $end_date_raw ? (int) strtotime( $end_date_raw . ' UTC' ) : null;

			// 1) Create the COUPON (one per campaign)
			$coupon_payload = [
				'name'               => $campaign ?: ( 'Bulk ' . gmdate( 'Y-m-d H:i:s' ) ),
				'percent_off'        => $percent_off,
				'amount_off'         => $amount_off_cents,
				'currency'           => $amount_off_cents ? $currency : null,
				'duration'           => $duration,
				'duration_in_months' => ( 'repeating' === $duration && $duration_months > 0 ) ? $duration_months : null,
				'max_redemptions_per_customer' => $usage_per_customer,
				'product_ids'        => ! empty( $product_ids ) ? $product_ids : null,
				'redeem_by'          => $coupon_redeem_by, // coupon end date
			];
			$coupon_payload = array_filter( $coupon_payload, static function( $v ) { return ! is_null( $v ); } );

			$coupon = $this->api_post( 'https://api.surecart.com/v1/coupons', $coupon_payload, $api_key );
			if ( is_wp_error( $coupon ) ) {
				wp_die( esc_html( 'Failed to create coupon: ' . $coupon->get_error_message() ) );
			}
			$coupon_id = isset( $coupon['id'] ) ? $coupon['id'] : '';

			// === NEW: Export mode from POST ===
			$mode = isset( $_POST['sc_export_mode'] )
			    ? sanitize_text_field( wp_unslash( $_POST['sc_export_mode'] ) )
			    : 'full';

			$mode = in_array( $mode, array( 'full', 'codes', 'both' ), true ) ? $mode : 'full';

			// Prepare uploads info.
			$upload_dir = wp_upload_dir();
			$basedir    = trailingslashit( $upload_dir['basedir'] );
			$baseurl    = trailingslashit( $upload_dir['baseurl'] );
			$stamp      = gmdate( 'Ymd-His' );

			// File handles + urls we might create.
			$fh_full       = null;
			$fh_codes      = null;
			$csv_url_full  = null;
			$csv_url_codes = null;

			// Open FULL CSV if needed.
			if ( 'full' === $mode || 'both' === $mode ) {
			    $csv_name_full = self::CSV_PREFIX . 'full-' . $stamp . '.csv';
			    $csv_path_full = $basedir . $csv_name_full;
			    $csv_url_full  = $baseurl . $csv_name_full;

			    $fh_full = fopen( $csv_path_full, 'w' );
			    if ( ! $fh_full ) {
			        wp_die( esc_html__( 'Could not create full CSV file in uploads.', 'scbg' ) );
			    }
			    // Header row matches your existing format.
			    fputcsv( $fh_full, array( 'campaign', 'code', 'type', 'value', 'currency', 'coupon_id', 'promotion_id' ) );
			}

			// Open CODES-ONLY CSV if needed.
			if ( 'codes' === $mode || 'both' === $mode ) {
			    $csv_name_codes = self::CSV_PREFIX . 'codes-' . $stamp . '.csv';
			    $csv_path_codes = $basedir . $csv_name_codes;
			    $csv_url_codes  = $baseurl . $csv_name_codes;

			    $fh_codes = fopen( $csv_path_codes, 'w' );
			    if ( ! $fh_codes ) {
			        if ( $fh_full ) { fclose( $fh_full ); }
			        wp_die( esc_html__( 'Could not create codes CSV file in uploads.', 'scbg' ) );
			    }
			    // Single column header.
			    fputcsv( $fh_codes, array( 'code' ) );
			}

			// 2) Create PROMOTION CODES (one per requested code)
			$created = 0;
			$errors  = 0;
			for ( $i = 0; $i < $count; $i++ ) {
			    $code = $this->generate_code( $prefix, $pattern, $groups, $group_sep );

			    $promotion_payload = array(
			        'coupon_id'       => $coupon_id,
			        'code'            => $code,
			        'max_redemptions' => $usage_limit,
			    );
			    $promotion_payload = array_filter( $promotion_payload, static function( $v ) { return ! is_null( $v ); } );

			    $promo = $this->api_post( 'https://api.surecart.com/v1/promotions', $promotion_payload, $api_key );
			    if ( is_wp_error( $promo ) ) {
			        $errors++;
			        continue;
			    }
			    $promotion_id = isset( $promo['id'] ) ? $promo['id'] : '';

			    // Write to FULL CSV if open.
			    if ( $fh_full ) {
			        fputcsv(
			            $fh_full,
			            array(
			                $campaign,
			                $code,
			                $discount_type,
			                ( 'percent' === $discount_type ? $percent_off : ( $amount_off_cents / 100 ) ),
			                $currency,
			                $coupon_id,
			                $promotion_id,
			            )
			        );
			    }

			    // Write to CODES CSV if open.
			    if ( $fh_codes ) {
			        fputcsv( $fh_codes, array( $code ) );
			    }

			    $created++;
			}

			// Close any open files.
			if ( $fh_full ) { fclose( $fh_full ); }
			if ( $fh_codes ) { fclose( $fh_codes ); }

			// Build message + redirect with up to two links.
			$msg = sprintf(
			    'Created coupon "%s" and %d promotion codes%s.',
			    $campaign ?: 'Bulk',
			    $created,
			    $errors ? sprintf( ' (%d errors)', $errors ) : ''
			);

			// Keep backward compatibility: always pass scbg_msg.
			// Pass separate params for each CSV that exists.
			$query = array(
			    'page'     => self::PAGE_SLUG,
			    'scbg_msg' => rawurlencode( $msg ),
			);

			if ( $csv_url_full ) {
			    $query['scbg_csv_full'] = rawurlencode( $csv_url_full );
			}
			if ( $csv_url_codes ) {
			    $query['scbg_csv_codes'] = rawurlencode( $csv_url_codes );
			}

			wp_safe_redirect( add_query_arg( $query, admin_url( 'tools.php' ) ) );
			exit;

		}
	}

	private function api_get( $url, $api_key ) {
		$args = [
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Accept'        => 'application/json',
			],
			'timeout' => 30,
		];
		$res = wp_remote_get( esc_url_raw( $url ), $args );
		if ( is_wp_error( $res ) ) { return $res; }
		$status = wp_remote_retrieve_response_code( $res );
		$body   = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $status >= 200 && $status < 300 ) { return is_array( $body ) ? $body : []; }
		$error_message = is_array( $body ) && isset( $body['error']['message'] ) ? $body['error']['message'] : 'Unknown error';
		return new WP_Error( 'scbg_api_error', sprintf( 'API error (%d): %s', $status, $error_message ), [ 'status' => $status, 'response' => $body ] );
	}

	private function api_post( $url, $payload, $api_key ) {
		$args = [
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 30,
		];

		$res = wp_remote_post( esc_url_raw( $url ), $args );
		if ( is_wp_error( $res ) ) { return $res; }
		$status = wp_remote_retrieve_response_code( $res );
		$body   = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $status >= 200 && $status < 300 ) { return is_array( $body ) ? $body : []; }
		$error_message = 'Unknown error';
		if ( is_array( $body ) && isset( $body['error']['message'] ) ) {
			$error_message = $body['error']['message'];
		} elseif ( is_array( $body ) && isset( $body['message'] ) ) {
			$error_message = $body['message'];
		}
		return new WP_Error( 'scbg_api_error', sprintf( 'API error (%d): %s', $status, $error_message ), [ 'status' => $status, 'response' => $body ] );
	}

	/**
	 * Generate a promotion code string with optional grouping.
	 *
	 * @param string $prefix       Optional prefix (case-insensitive; will be uppercased).
	 * @param string $pattern      readable|alnum|alnum_no_vowels|hex|numeric
	 * @param int[]  $group_sizes  Array of group lengths; total capped at 32; zeros ignored.
	 * @param string $group_sep    Single-character separator between groups (default "-").
	 * @return string Uppercased code (prefix + grouped random).
	 */
	private function generate_code( $prefix = '', $pattern = 'readable', $group_sizes = [8], $group_sep = '-' ) {
		$prefix = strtoupper( $prefix );

		switch ( $pattern ) {
			case 'hex':
				$chars = '0123456789ABCDEF';
				break;
			case 'numeric':
				$chars = '0123456789';
				break;
			case 'alnum_no_vowels':
				$chars = 'BCDFGHJKLMNPQRSTVWXYZ0123456789';
				break;
			case 'alnum':
				$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
				break;
			case 'readable':
			default:
				// No 0/O or 1/I/l to avoid confusion.
				$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
				break;
		}

		$group_sep = substr( (string) $group_sep, 0, 1 );
		if ( ! preg_match( '/^[A-Za-z0-9\-\_\.]$/', $group_sep ) ) {
			$group_sep = '-';
		}

		// Build groups.
		$groups = [];
		$max_index = strlen( $chars ) - 1;

		foreach ( $group_sizes as $len ) {
			$len = absint( $len );
			if ( $len <= 0 ) { continue; }
			$piece = '';
			for ( $i = 0; $i < $len; $i++ ) {
				$piece .= $chars[ random_int( 0, $max_index ) ];
			}
			$groups[] = $piece;
		}

		$random_part = implode( $group_sep, $groups );

		return $prefix . $random_part;
	}
}

new RUP_SCBG_Bulk_Coupon_Generator();

// ──────────────────────────────────────────────────────────────────────────
//  Updater bootstrap (plugins_loaded priority 1):
// ──────────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function() {
    // 1) Load our universal drop-in. Because that file begins with "namespace UUPD\V1;",
    //    both the class and the helper live under UUPD\V1.
    require_once __DIR__ . '/inc/updater.php';

    // 2) Build a single $updater_config array:
    $updater_config = [
        'plugin_file' => plugin_basename(__FILE__),             // e.g. "simply-static-export-notify/simply-static-export-notify.php"
        'slug'        => RUP_SC_SC_BULK_COUPONS_SLUG,           // must match your updater‐server slug
        'name'        => 'SureCart Bulk Coupons',               // human‐readable plugin name
        'version'     => RUP_SC_SC_BULK_COUPONS_VERSION,        // same as the VERSION constant above
        'key'         => '',                                    // your secret key for private updater
        'server'      => 'https://raw.githubusercontent.com/stingray82/sc-bulk-coupons/main/uupd/index.json',
    ];

    // 3) Call the helper in the UUPD\V1 namespace:
    \RUP\Updater\Updater_V1::register( $updater_config );
}, 20 );

// MainWP Icon Filter
add_filter('mainwp_child_stats_get_plugin_info', function($info, $slug) {
    if ('sc-bulk-coupons/sc-bulk-coupons.php' === $slug) {
        $info['icon'] = 'https://raw.githubusercontent.com/stingray82/sc-bulk-coupons/main/uupd/icon-128.png'; // Supported types: jpeg, jpg, gif, ico, png
    }
    return $info;
}, 10, 2);
