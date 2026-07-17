<?php

defined( 'ABSPATH' ) || exit;

final class ELS_WC_Admin {
	private $exporter;

	public function __construct( ELS_WC_Order_Exporter $exporter ) {
		$this->exporter = $exporter;

		add_action( 'woocommerce_order_status_changed', array( $this, 'status_changed' ), 20, 4 );
		add_action( 'els_wc_send_order_async', array( $this, 'send_scheduled' ), 10, 1 );
		add_action( 'els_wc_send_order_cron', array( $this, 'send_scheduled' ), 10, 1 );
		add_filter( 'woocommerce_order_actions', array( $this, 'order_actions' ), 20, 2 );
		add_action( 'woocommerce_order_action_els_wc_send', array( $this, 'run_order_action' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk' ), 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_post_els_wc_send_order', array( $this, 'handle_send_button' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'backfill_tracking_meta' ), 1, 1 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_tracking_fields' ), 20, 1 );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_tracking_fields' ), 20, 1 );
	}

	public function status_changed( $order_id, $from, $to, $order ) {
		$settings = ELS_WC_Settings::get_all();
		if ( 'yes' !== $settings['enabled'] || ! in_array( $to, (array) $settings['send_statuses'], true ) ) {
			return;
		}
		if ( $order && ( $order->get_meta( '_els_serial_number' ) || $order->get_meta( '_els_tracking_number' ) ) ) {
			return;
		}
		$this->queue( $order_id );
	}

	private function queue( $order_id ) {
		$order_id = absint( $order_id );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'els_wc_send_order_async', array( $order_id ), 'els-woocommerce', true );
			return;
		}
		if ( ! wp_next_scheduled( 'els_wc_send_order_cron', array( $order_id ) ) ) {
			wp_schedule_single_event( time() + 5, 'els_wc_send_order_cron', array( $order_id ) );
		}
	}

	public function send_scheduled( $order_id ) {
		$this->exporter->send_order( absint( $order_id ) );
	}

	public function order_actions( $actions, $order = null ) {
		$actions['els_wc_send'] = __( 'Send/retry shipment to ELS', 'els-woocommerce' );
		return $actions;
	}

	public function run_order_action( $order ) {
		$order = is_a( $order, 'WC_Order' ) ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return;
		}
		$result = $this->exporter->send_order( $order->get_id(), false, true );
		if ( is_wp_error( $result ) && 'els_already_sent' === $result->get_error_code() ) {
			$order->add_order_note( $result->get_error_message() );
		}
	}

	public function bulk_actions( $actions ) {
		$actions['els_wc_queue'] = __( 'Queue shipment creation in ELS', 'els-woocommerce' );
		return $actions;
	}

	public function handle_bulk( $redirect, $action, $order_ids ) {
		if ( 'els_wc_queue' !== $action ) {
			return $redirect;
		}
		$count = 0;
		foreach ( (array) $order_ids as $order_id ) {
			$this->queue( $order_id );
			++$count;
		}
		return add_query_arg( 'els_wc_queued', $count, $redirect );
	}

	public function notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_GET['els_wc_queued'] ) ) {
			$count = absint( wp_unslash( $_GET['els_wc_queued'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d order was queued for ELS.', '%d orders were queued for ELS.', $count, 'els-woocommerce' ), $count ) ) . '</p></div>';
		}

		if ( ! isset( $_GET['els_wc_notice'], $_GET['els_wc_order_id'] ) ) {
			return;
		}

		$notice   = sanitize_key( wp_unslash( $_GET['els_wc_notice'] ) );
		$order_id = absint( wp_unslash( $_GET['els_wc_order_id'] ) );
		$order    = wc_get_order( $order_id );
		if ( 'sent' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'ELS shipment for order #%s was created successfully.', 'els-woocommerce' ), $order ? $order->get_order_number() : $order_id ) ) . '</p></div>';
		} elseif ( 'error' === $notice ) {
			$message = $order ? $order->get_meta( '_els_last_error' ) : '';
			if ( ! $message ) {
				$message = __( 'The ELS shipment could not be created. Check the order notes and WooCommerce logs.', 'els-woocommerce' );
			}
			echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__( 'ELS error:', 'els-woocommerce' ) . '</strong> ' . esc_html( $message ) . '</p></div>';
		}
	}

	public function handle_send_button() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to create ELS shipments.', 'els-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		check_admin_referer( 'els_wc_send_order_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'WooCommerce order was not found.', 'els-woocommerce' ), '', array( 'response' => 404 ) );
		}

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		}

		$place = isset( $_POST['els_place'] ) ? sanitize_text_field( wp_unslash( $_POST['els_place'] ) ) : '';
		if ( ! ELS_WC_Places::is_valid( $place ) ) {
			$order->update_meta_data( '_els_export_status', 'failed' );
			$order->update_meta_data( '_els_last_error', __( 'Select a valid ELS place from the list before sending.', 'els-woocommerce' ) );
			$order->save();
			wp_safe_redirect(
				add_query_arg(
					array(
						'els_wc_notice'   => 'error',
						'els_wc_order_id' => $order_id,
					),
					$redirect
				)
			);
			exit;
		}

		$order->update_meta_data( '_els_place_text', $place );
		$order->save();

		$result = $this->exporter->send_order( $order_id, false, true );
		$notice = is_wp_error( $result ) ? 'error' : 'sent';
		wp_safe_redirect(
			add_query_arg(
				array(
					'els_wc_notice'   => $notice,
					'els_wc_order_id' => $order_id,
				),
				$redirect
			)
		);
		exit;
	}

	public function add_meta_box() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'els-wc-shipment', __( 'ELS shipment', 'els-woocommerce' ), array( $this, 'render_meta_box' ), $screen, 'side', 'default' );
		}
	}

	public function backfill_tracking_meta( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$tracking = $order->get_meta( '_els_tracking_number' ) ?: $order->get_meta( '_els_serial_number' );
		$this->sync_tracking_compatibility_meta( $order, $tracking );
	}

	public function render_tracking_fields( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Avoid duplicate fields when the user's original functions.php snippet is active.
		if ( function_exists( 'add_tracking_and_cfma_fields' ) ) {
			return;
		}

		$tracking_number = $order->get_meta( '_tracking_number' );
		$cfma_number     = $order->get_meta( '_cfma_number' );
		if ( ! $tracking_number ) {
			$tracking_number = get_post_meta( $order->get_id(), '_tracking_number', true );
		}
		if ( ! $cfma_number ) {
			$cfma_number = get_post_meta( $order->get_id(), '_cfma_number', true );
		}
		?>
		<p class="form-field form-field-wide">
			<label for="tracking_number"><?php esc_html_e( 'Tracking Number', 'els-woocommerce' ); ?></label>
			<input type="text" name="tracking_number" id="tracking_number" value="<?php echo esc_attr( $tracking_number ); ?>">
		</p>
		<p class="form-field form-field-wide">
			<label for="cfma_number"><?php esc_html_e( 'CFMA Number', 'els-woocommerce' ); ?></label>
			<input type="text" name="cfma_number" id="cfma_number" value="<?php echo esc_attr( $cfma_number ); ?>">
		</p>
		<?php
	}

	public function save_tracking_fields( $order_id ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$changed = false;
		if ( isset( $_POST['tracking_number'] ) ) {
			$tracking_number = sanitize_text_field( wp_unslash( $_POST['tracking_number'] ) );
			$order->update_meta_data( '_tracking_number', $tracking_number );
			update_post_meta( $order_id, '_tracking_number', $tracking_number );
			$changed = true;
		}
		if ( isset( $_POST['cfma_number'] ) ) {
			$cfma_number = sanitize_text_field( wp_unslash( $_POST['cfma_number'] ) );
			$order->update_meta_data( '_cfma_number', $cfma_number );
			update_post_meta( $order_id, '_cfma_number', $cfma_number );
			$changed = true;
		}
		if ( $changed ) {
			$order->save();
		}
	}

	public function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order ) {
			return;
		}
		$status   = $order->get_meta( '_els_export_status' ) ?: __( 'Not sent', 'els-woocommerce' );
		$tracking = $order->get_meta( '_els_tracking_number' );
		$serial   = $order->get_meta( '_els_serial_number' );
		$this->sync_tracking_compatibility_meta( $order, $tracking ?: $serial );
		$links    = array(
			__( 'Track shipment', 'els-woocommerce' ) => $order->get_meta( '_els_tracking_url' ),
			__( 'Delivery note', 'els-woocommerce' )  => $order->get_meta( '_els_delivery_note_url' ),
			__( 'Label', 'els-woocommerce' )          => $order->get_meta( '_els_label_url' ),
		);
		echo '<p><strong>' . esc_html__( 'Status:', 'els-woocommerce' ) . '</strong> ' . esc_html( $status ) . '</p>';
		if ( $tracking ) {
			echo '<p><strong>' . esc_html__( 'Tracking:', 'els-woocommerce' ) . '</strong> ' . esc_html( $tracking ) . '</p>';
		}
		foreach ( $links as $label => $url ) {
			if ( $url ) {
				echo '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a></p>';
			}
		}
		$error = $order->get_meta( '_els_last_error' );
		if ( $error ) {
			echo '<p style="color:#b32d2e"><strong>' . esc_html__( 'Last error:', 'els-woocommerce' ) . '</strong> ' . esc_html( $error ) . '</p>';
		}

		if ( ! $order->get_meta( '_els_serial_number' ) && ! $order->get_meta( '_els_tracking_number' ) ) {
			$label = 'failed' === $status ? __( 'Retry shipment in ELS', 'els-woocommerce' ) : __( 'Create shipment in ELS', 'els-woocommerce' );
			$current_place = $order->get_meta( '_els_place_text' );
			if ( ! $current_place ) {
				$current_place = $order->get_shipping_city() ?: $order->get_billing_city();
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="els_wc_send_order">';
			echo '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '">';
			wp_nonce_field( 'els_wc_send_order_' . $order->get_id() );
			echo '<p><label for="els-wc-order-place"><strong>' . esc_html__( 'Место за достава', 'els-woocommerce' ) . '</strong></label></p>';
			echo '<select id="els-wc-order-place" name="els_place" class="els-wc-place-select" data-placeholder="' . esc_attr__( 'Пребарајте и изберете место…', 'els-woocommerce' ) . '" required style="width:100%">';
			foreach ( ELS_WC_Places::options() as $value => $option_label ) {
				echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current_place, $value, false ) . '>' . esc_html( $option_label ) . '</option>';
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__( 'The exact selected ELS value will be sent as the shipment place.', 'els-woocommerce' ) . '</p>';
			echo '<p><button type="submit" class="button button-primary" style="width:100%" onclick="return confirm(\'' . esc_js( __( 'Create this shipment in ELS now?', 'els-woocommerce' ) ) . '\');">' . esc_html( $label ) . '</button></p>';
			echo '</form>';
		} else {
			echo '<p><em>' . esc_html__( 'This order already has an ELS shipment, so it cannot be sent again.', 'els-woocommerce' ) . '</em></p>';
		}
	}

	private function sync_tracking_compatibility_meta( WC_Order $order, $tracking_value ) {
		$tracking_meta_key = sanitize_key( ELS_WC_Settings::get( 'tracking_meta_key', '_tracking_number' ) );
		$tracking_value    = sanitize_text_field( (string) $tracking_value );
		if ( ! $tracking_meta_key || ! $tracking_value ) {
			return;
		}

		if ( $order->get_meta( $tracking_meta_key ) !== $tracking_value ) {
			$order->update_meta_data( $tracking_meta_key, $tracking_value );
			$order->save();
		}

		// Keeps legacy get_post_meta()-based custom fields working when HPOS is enabled.
		if ( get_post_meta( $order->get_id(), $tracking_meta_key, true ) !== $tracking_value ) {
			update_post_meta( $order->get_id(), $tracking_meta_key, $tracking_value );
		}
	}
}
