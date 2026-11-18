<?php

defined( 'ABSPATH' ) || exit;

final class ELS_WC_Settings {
	const OPTION_NAME = 'els_wc_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'wp_ajax_els_wc_test_connection', array( __CLASS__, 'test_connection' ) );
	}

	public static function defaults() {
		return array(
			'enabled'             => 'no',
			'api_base_url'        => 'https://api.els.mk',
			'login_endpoint'      => '/users/login',
			'bulk_endpoint'       => '/orders/bulk',
			'client_id'           => '',
			'client_secret'       => '',
			'username'            => '',
			'password'            => '',
			'listtype'            => 'ЛИСТА ПАКЕТ-ПИ',
			'delimiter'           => 'comma',
			'import_all_or_none'  => '1',
			'send_statuses'       => array( 'processing', 'completed' ),
			'sender_name'         => '',
			'sender_address'      => '',
			'sender_place'        => '',
			'sender_phone'        => '',
			'reference_prefix'    => '',
			'default_weight'      => '1',
			'cod_methods'         => 'cod',
			'timeout'             => '30',
			'debug'               => 'no',
			'place_autocomplete'  => 'yes',
			'tracking_meta_key'   => '_tracking_number',
			'tracking_page_base_url' => 'https://track.els.mk/mk/',
			'customer_tracking_email' => 'yes',
			'delete_data'         => 'no',
			'sync_enabled'        => 'yes',
			'status_map'          => '',
			'status_shipped'      => '',
			'status_delivered'    => 'completed',
			'status_returned'     => 'cancelled',
			'column_mapping'      => implode(
				"\n",
				array(
					'име на примач=recipient_name',
					'адреса на примач=recipient_address',
					'телефон на примач=recipient_phone',
					'место=recipient_place',
					'тежина=weight',
					'откуп=cod_amount',
					'коментар=note',
					'референца=reference_number',
					'количина=static:1',
					'заменска пратка=static:0',
				)
			),
		);
	}

	public static function get_all() {
		$saved = get_option( self::OPTION_NAME, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key, $default = null ) {
		$settings = self::get_all();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'ELS Integration', 'els-woocommerce' ),
			__( 'ELS Integration', 'els-woocommerce' ),
			'manage_woocommerce',
			'els-wc-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register() {
		register_setting(
			'els_wc_settings_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$current  = self::get_all();
		$defaults = self::defaults();
		$output   = $defaults;

		$output['enabled']            = isset( $input['enabled'] ) ? 'yes' : 'no';
		$output['api_base_url']       = untrailingslashit( esc_url_raw( $input['api_base_url'] ?? $defaults['api_base_url'] ) );
		$output['login_endpoint']     = self::sanitize_endpoint( $input['login_endpoint'] ?? $defaults['login_endpoint'] );
		$output['bulk_endpoint']      = self::sanitize_endpoint( $input['bulk_endpoint'] ?? $defaults['bulk_endpoint'] );
		$output['client_id']          = sanitize_text_field( $input['client_id'] ?? '' );
		$output['username']           = sanitize_text_field( $input['username'] ?? '' );
		$output['listtype']           = sanitize_text_field( $input['listtype'] ?? '' );
		$output['import_all_or_none'] = isset( $input['import_all_or_none'] ) ? '1' : '0';
		$output['sender_name']        = sanitize_text_field( $input['sender_name'] ?? '' );
		$output['sender_address']     = sanitize_text_field( $input['sender_address'] ?? '' );
		$output['sender_place']       = sanitize_text_field( $input['sender_place'] ?? '' );
		$output['sender_phone']       = sanitize_text_field( $input['sender_phone'] ?? '' );
		$output['reference_prefix']   = sanitize_text_field( $input['reference_prefix'] ?? '' );
		$output['cod_methods']        = sanitize_text_field( $input['cod_methods'] ?? 'cod' );
		$output['column_mapping']     = sanitize_textarea_field( $input['column_mapping'] ?? $defaults['column_mapping'] );
		$output['debug']              = isset( $input['debug'] ) ? 'yes' : 'no';
		$output['place_autocomplete'] = isset( $input['place_autocomplete'] ) ? 'yes' : 'no';
		$output['tracking_meta_key']  = sanitize_key( $input['tracking_meta_key'] ?? '_tracking_number' );
		$output['tracking_page_base_url'] = trailingslashit( esc_url_raw( $input['tracking_page_base_url'] ?? 'https://track.els.mk/mk/' ) );
		$output['customer_tracking_email'] = isset( $input['customer_tracking_email'] ) ? 'yes' : 'no';

		$output['delete_data']  = isset( $input['delete_data'] ) ? 'yes' : 'no';
		$output['sync_enabled'] = isset( $input['sync_enabled'] ) ? 'yes' : 'no';
		$output['status_map']   = sanitize_textarea_field( $input['status_map'] ?? '' );
		foreach ( array( 'shipped', 'delivered', 'returned' ) as $target ) {
			$output[ 'status_' . $target ] = sanitize_key( preg_replace( '/^wc-/', '', (string) ( $input[ 'status_' . $target ] ?? $defaults[ 'status_' . $target ] ) ) );
		}

		$delimiter = sanitize_key( $input['delimiter'] ?? 'comma' );
		$output['delimiter'] = in_array( $delimiter, array( 'tab', 'comma', 'semicolon', 'pipe' ), true ) ? $delimiter : 'comma';

		$statuses = isset( $input['send_statuses'] ) && is_array( $input['send_statuses'] ) ? $input['send_statuses'] : array();
		$output['send_statuses'] = array_values( array_unique( array_map( 'sanitize_key', $statuses ) ) );

		$output['default_weight'] = (string) max( 0, (float) ( $input['default_weight'] ?? 1 ) );
		$output['timeout']        = (string) min( 120, max( 5, absint( $input['timeout'] ?? 30 ) ) );

		$output['client_secret'] = ! empty( $input['clear_client_secret'] )
			? ''
			: ( ! empty( $input['client_secret'] ) ? sanitize_text_field( $input['client_secret'] ) : $current['client_secret'] );
		$output['password'] = ! empty( $input['clear_password'] )
			? ''
			: ( ! empty( $input['password'] ) ? sanitize_text_field( $input['password'] ) : $current['password'] );

		return $output;
	}

	private static function sanitize_endpoint( $endpoint ) {
		$endpoint = trim( sanitize_text_field( $endpoint ) );
		if ( '' === $endpoint ) {
			return '/';
		}
		return '/' . ltrim( $endpoint, '/' );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$s = self::get_all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ELS Integration for WooCommerce', 'els-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Enter the API account and list-template data supplied by ELS. No customer or company credential is built into this plugin.', 'els-woocommerce' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'els_wc_settings_group' ); ?>
				<h2><?php esc_html_e( 'Connection', 'els-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php self::checkbox_row( 'enabled', __( 'Enable integration', 'els-woocommerce' ), $s['enabled'], __( 'Automatically queue eligible orders for ELS.', 'els-woocommerce' ) ); ?>
					<?php self::text_row( 'api_base_url', __( 'API base URL', 'els-woocommerce' ), $s['api_base_url'], 'url' ); ?>
					<?php self::text_row( 'login_endpoint', __( 'Login endpoint', 'els-woocommerce' ), $s['login_endpoint'] ); ?>
					<?php self::text_row( 'bulk_endpoint', __( 'Bulk order endpoint', 'els-woocommerce' ), $s['bulk_endpoint'] ); ?>
					<?php self::text_row( 'client_id', __( 'Client ID', 'els-woocommerce' ), $s['client_id'] ); ?>
					<?php self::secret_row( 'client_secret', __( 'Client secret', 'els-woocommerce' ), ! empty( $s['client_secret'] ) ); ?>
					<?php self::text_row( 'username', __( 'ELS username', 'els-woocommerce' ), $s['username'] ); ?>
					<?php self::secret_row( 'password', __( 'ELS password', 'els-woocommerce' ), ! empty( $s['password'] ) ); ?>
					<?php self::text_row( 'timeout', __( 'Request timeout (seconds)', 'els-woocommerce' ), $s['timeout'], 'number', '5', '120' ); ?>
				</table>

				<p><button type="button" class="button" id="els-wc-test"><?php esc_html_e( 'Save first, then test connection', 'els-woocommerce' ); ?></button> <span id="els-wc-test-result" aria-live="polite"></span></p>

				<h2><?php esc_html_e( 'Shipment import', 'els-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php self::text_row( 'listtype', __( 'ELS list type', 'els-woocommerce' ), $s['listtype'], 'text', '', '', __( 'Exact template name from “Типови на листи” in the ELS customer portal.', 'els-woocommerce' ) ); ?>
					<tr><th scope="row"><label for="els-wc-delimiter"><?php esc_html_e( 'CSV delimiter', 'els-woocommerce' ); ?></label></th><td><select id="els-wc-delimiter" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[delimiter]">
						<?php foreach ( array( 'tab' => __( 'Tab', 'els-woocommerce' ), 'comma' => __( 'Comma', 'els-woocommerce' ), 'semicolon' => __( 'Semicolon', 'els-woocommerce' ), 'pipe' => __( 'Pipe', 'els-woocommerce' ) ) as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['delimiter'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
					<?php self::checkbox_row( 'import_all_or_none', __( 'Import all or none', 'els-woocommerce' ), $s['import_all_or_none'], __( 'For a single-order request this should normally remain enabled.', 'els-woocommerce' ) ); ?>
					<tr><th scope="row"><?php esc_html_e( 'Automatic order statuses', 'els-woocommerce' ); ?></th><td>
						<?php foreach ( wc_get_order_statuses() as $status_key => $status_label ) : $status = preg_replace( '/^wc-/', '', $status_key ); ?>
							<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[send_statuses][]" value="<?php echo esc_attr( $status ); ?>" <?php checked( in_array( $status, (array) $s['send_statuses'], true ) ); ?>> <?php echo esc_html( $status_label ); ?></label>
						<?php endforeach; ?>
					</td></tr>
					<?php self::text_row( 'default_weight', __( 'Default weight (kg)', 'els-woocommerce' ), $s['default_weight'], 'number', '0', '', __( 'Used only when products have no weight.', 'els-woocommerce' ), '0.001' ); ?>
					<?php self::text_row( 'cod_methods', __( 'COD payment method IDs', 'els-woocommerce' ), $s['cod_methods'], 'text', '', '', __( 'Comma-separated WooCommerce payment method IDs. Default: cod.', 'els-woocommerce' ) ); ?>
					<?php self::text_row( 'reference_prefix', __( 'Reference prefix', 'els-woocommerce' ), $s['reference_prefix'] ); ?>
					<?php self::text_row( 'tracking_meta_key', __( 'Tracking Number meta key', 'els-woocommerce' ), $s['tracking_meta_key'], 'text', '', '', __( 'ELS tracking number will also be saved here. Default: _tracking_number.', 'els-woocommerce' ) ); ?>
					<?php self::text_row( 'tracking_page_base_url', __( 'Tracking page base URL', 'els-woocommerce' ), $s['tracking_page_base_url'], 'url', '', '', __( 'The tracking number is appended to this URL.', 'els-woocommerce' ) ); ?>
					<?php self::checkbox_row( 'customer_tracking_email', __( 'Customer tracking link', 'els-woocommerce' ), $s['customer_tracking_email'], __( 'Include the tracking link in the Completed order email and My Account order details.', 'els-woocommerce' ) ); ?>
					<?php self::checkbox_row( 'place_autocomplete', __( 'ELS place autocomplete', 'els-woocommerce' ), $s['place_autocomplete'], __( 'Enable Cyrillic/Latin place suggestions only when the checkout country is North Macedonia.', 'els-woocommerce' ) ); ?>
				</table>

				<h2><?php esc_html_e( 'Automatic order status from ELS', 'els-woocommerce' ); ?></h2>
				<p><?php esc_html_e( 'Every 30 minutes (WP-Cron) the plugin checks ELS and moves orders forward. Orders never move backwards.', 'els-woocommerce' ); ?></p>
				<table class="form-table" role="presentation">
					<?php self::checkbox_row( 'sync_enabled', __( 'Sync statuses from ELS', 'els-woocommerce' ), $s['sync_enabled'], __( 'Update WooCommerce order status from the ELS shipment status.', 'els-woocommerce' ) ); ?>
					<?php foreach ( array( 'shipped' => __( 'Picked up / in delivery', 'els-woocommerce' ), 'delivered' => __( 'Delivered', 'els-woocommerce' ), 'returned' => __( 'Returned / cancelled', 'els-woocommerce' ) ) as $target => $target_label ) : ?>
						<tr><th scope="row"><label for="els-wc-status-<?php echo esc_attr( $target ); ?>"><?php echo esc_html( $target_label ); ?></label></th><td><select id="els-wc-status-<?php echo esc_attr( $target ); ?>" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[status_<?php echo esc_attr( $target ); ?>]">
							<option value=""><?php esc_html_e( '— do not change —', 'els-woocommerce' ); ?></option>
							<?php foreach ( wc_get_order_statuses() as $status_key => $status_label ) : $status = preg_replace( '/^wc-/', '', $status_key ); ?>
								<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $s[ 'status_' . $target ], $status ); ?>><?php echo esc_html( $status_label ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
					<?php endforeach; ?>
					<tr><th scope="row"><label for="els-wc-status_map"><?php esc_html_e( 'ELS status code map (optional)', 'els-woocommerce' ); ?></label></th><td>
						<textarea id="els-wc-status_map" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[status_map]" rows="4" class="large-text code" spellcheck="false" placeholder="40=shipped&#10;60=delivered&#10;70=returned"><?php echo esc_textarea( $s['status_map'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One line per ELS status code: code=shipped|delivered|returned|ignore. Without a map the status is recognised from its text.', 'els-woocommerce' ); ?></p>
					</td></tr>
				</table>
				<?php if ( isset( $_GET['els_sync'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
					<div class="notice notice-info inline"><p><?php echo esc_html( sprintf( __( 'ELS sync: checked %1$d, updated %2$d, unknown status %3$d, errors %4$d.', 'els-woocommerce' ), absint( $_GET['checked'] ?? 0 ), absint( $_GET['updated'] ?? 0 ), absint( $_GET['unknown'] ?? 0 ), absint( $_GET['errors'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification ?></p></div>
				<?php endif; ?>
				<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=els_wc_sync_now' ), 'els_wc_sync_now' ) ); ?>"><?php esc_html_e( 'Sync statuses now (save settings first)', 'els-woocommerce' ); ?></a></p>

				<h2><?php esc_html_e( 'Sender/company data', 'els-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php self::text_row( 'sender_name', __( 'Sender name', 'els-woocommerce' ), $s['sender_name'] ); ?>
					<?php self::text_row( 'sender_address', __( 'Sender address', 'els-woocommerce' ), $s['sender_address'] ); ?>
					<?php self::text_row( 'sender_place', __( 'Sender place', 'els-woocommerce' ), $s['sender_place'] ); ?>
					<?php self::text_row( 'sender_phone', __( 'Sender phone', 'els-woocommerce' ), $s['sender_phone'] ); ?>
				</table>

				<h2><?php esc_html_e( 'ELS column mapping', 'els-woocommerce' ); ?></h2>
				<p><?php esc_html_e( 'One line per column: exact ELS header=source. The order of these lines becomes the CSV column order.', 'els-woocommerce' ); ?></p>
				<textarea name="<?php echo esc_attr( self::OPTION_NAME ); ?>[column_mapping]" rows="12" class="large-text code" spellcheck="false"><?php echo esc_textarea( $s['column_mapping'] ); ?></textarea>
				<details style="margin-top:10px"><summary><?php esc_html_e( 'Available mapping sources', 'els-woocommerce' ); ?></summary>
					<p><code>order_id</code>, <code>order_number</code>, <code>reference_number</code>, <code>recipient_name</code>, <code>recipient_first_name</code>, <code>recipient_last_name</code>, <code>recipient_company</code>, <code>recipient_address</code>, <code>recipient_address_1</code>, <code>recipient_address_2</code>, <code>recipient_place</code>, <code>recipient_state</code>, <code>recipient_postcode</code>, <code>recipient_country</code>, <code>recipient_phone</code>, <code>recipient_email</code>, <code>cod_amount</code>, <code>order_total</code>, <code>weight</code>, <code>note</code>, <code>payment_method</code>, <code>payment_method_title</code>, <code>items</code>, <code>sender_name</code>, <code>sender_address</code>, <code>sender_place</code>, <code>sender_phone</code>, <code>store_name</code>, <code>meta:custom_key</code>, <code>static:any text</code>.</p>
				</details>

				<h2><?php esc_html_e( 'Diagnostics', 'els-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php self::checkbox_row( 'delete_data', __( 'Delete settings on uninstall', 'els-woocommerce' ), $s['delete_data'], __( 'Off by default: deleting the plugin keeps your ELS settings so a reinstall or update does not lose them.', 'els-woocommerce' ) ); ?>
					<?php self::checkbox_row( 'debug', __( 'Enable WooCommerce logging', 'els-woocommerce' ), $s['debug'], __( 'Logs technical status only; credentials and order CSV data are not logged.', 'els-woocommerce' ) ); ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<script>
		(function(){
			var button = document.getElementById('els-wc-test');
			var result = document.getElementById('els-wc-test-result');
			if (!button) return;
			button.addEventListener('click', function(){
				button.disabled = true;
				result.textContent = '<?php echo esc_js( __( 'Testing…', 'els-woocommerce' ) ); ?>';
				var data = new URLSearchParams({action:'els_wc_test_connection', nonce:'<?php echo esc_js( wp_create_nonce( 'els_wc_test_connection' ) ); ?>'});
				fetch(ajaxurl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:data.toString()})
					.then(function(r){ return r.json(); })
					.then(function(r){ result.textContent = r.data && r.data.message ? r.data.message : '<?php echo esc_js( __( 'Unexpected response.', 'els-woocommerce' ) ); ?>'; result.style.color = r.success ? '#008a20' : '#b32d2e'; })
					.catch(function(){ result.textContent = '<?php echo esc_js( __( 'Connection test failed.', 'els-woocommerce' ) ); ?>'; result.style.color = '#b32d2e'; })
					.finally(function(){ button.disabled = false; });
			});
		})();
		</script>
		<?php
	}

	private static function text_row( $key, $label, $value, $type = 'text', $min = '', $max = '', $description = '', $step = '' ) {
		$name = self::OPTION_NAME . '[' . $key . ']';
		?>
		<tr><th scope="row"><label for="els-wc-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td>
			<input class="regular-text" id="els-wc-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name ); ?>" type="<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php echo '' !== $min ? 'min="' . esc_attr( $min ) . '"' : ''; ?> <?php echo '' !== $max ? 'max="' . esc_attr( $max ) . '"' : ''; ?> <?php echo '' !== $step ? 'step="' . esc_attr( $step ) . '"' : ''; ?>>
			<?php if ( $description ) : ?><p class="description"><?php echo esc_html( $description ); ?></p><?php endif; ?>
		</td></tr>
		<?php
	}

	private static function checkbox_row( $key, $label, $value, $description = '' ) {
		?>
		<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME . '[' . $key . ']' ); ?>" value="1" <?php checked( in_array( (string) $value, array( 'yes', '1' ), true ) ); ?>> <?php echo esc_html( $description ); ?></label></td></tr>
		<?php
	}

	private static function secret_row( $key, $label, $is_saved ) {
		?>
		<tr><th scope="row"><label for="els-wc-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td>
			<input class="regular-text" id="els-wc-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION_NAME . '[' . $key . ']' ); ?>" type="password" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $is_saved ? __( 'Saved — leave blank to keep', 'els-woocommerce' ) : '' ); ?>">
			<?php if ( $is_saved ) : ?><label style="display:block;margin-top:6px"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_NAME . '[clear_' . $key . ']' ); ?>" value="1"> <?php esc_html_e( 'Clear saved value', 'els-woocommerce' ); ?></label><?php endif; ?>
		</td></tr>
		<?php
	}

	public static function test_connection() {
		check_ajax_referer( 'els_wc_test_connection', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'els-woocommerce' ) ), 403 );
		}

		$client = new ELS_WC_API_Client( self::get_all() );
		$result = $client->authenticate( true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$name = ! empty( $result['client_name'] ) ? ' (' . $result['client_name'] . ')' : '';
		wp_send_json_success( array( 'message' => sprintf( __( 'ELS connection successful%s.', 'els-woocommerce' ), $name ) ) );
	}
}
