<?php

defined( 'ABSPATH' ) || exit;

final class ELS_WC_Order_Exporter {
	public function send_order( $order_id, $force = false, $manual = false ) {
		$settings = ELS_WC_Settings::get_all();
		if ( ! $manual && 'yes' !== $settings['enabled'] ) {
			return new WP_Error( 'els_disabled', __( 'ELS integration is disabled.', 'els-woocommerce' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'els_invalid_order', __( 'WooCommerce order was not found.', 'els-woocommerce' ) );
		}

		if ( ! $force && ( $order->get_meta( '_els_serial_number' ) || $order->get_meta( '_els_tracking_number' ) ) ) {
			return new WP_Error( 'els_already_sent', __( 'This order already has an ELS shipment and was not sent again.', 'els-woocommerce' ) );
		}

		if ( empty( $settings['listtype'] ) ) {
			return $this->fail( $order, __( 'ELS list type is not configured.', 'els-woocommerce' ) );
		}

		$mapping = $this->parse_mapping( $settings['column_mapping'] );
		if ( empty( $mapping ) ) {
			return $this->fail( $order, __( 'ELS column mapping is empty or invalid.', 'els-woocommerce' ) );
		}

		$headers = array_keys( $mapping );
		$row     = array();
		foreach ( $mapping as $source ) {
			$row[] = $this->value( $source, $order, $settings );
		}

		$delimiter = $this->delimiter( $settings['delimiter'] );
		$csv       = $this->make_csv( $headers, $row, $delimiter );
		if ( is_wp_error( $csv ) ) {
			return $this->fail( $order, $csv->get_error_message() );
		}

		$order->update_meta_data( '_els_export_status', 'sending' );
		$order->delete_meta_data( '_els_last_error' );
		$order->save();

		$client   = new ELS_WC_API_Client( $settings );
		$response = $client->create_bulk_order(
			array(
				'listtype'           => $settings['listtype'],
				'csv'                => $csv,
				'delimiter'          => $delimiter,
				'import_all_or_none' => (int) $settings['import_all_or_none'],
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->fail( $order, $response->get_error_message() );
		}

		$order->update_meta_data( '_els_last_response', wp_json_encode( $response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$order_result = $this->find_order_result( $response, (string) $this->value( 'reference_number', $order, $settings ) );
		$errors       = isset( $response['errors'] ) && is_array( $response['errors'] ) ? $response['errors'] : array();

		if ( ! $order_result || ( 'success' !== ( $order_result['status'] ?? '' ) && empty( $order_result['serialNumber'] ) && empty( $order_result['trackingNumber'] ) ) ) {
			$message = $this->response_error( $response, $errors, $order_result );
			return $this->fail( $order, $message );
		}

		$fields = array(
			'_els_serial_number'     => 'serialNumber',
			'_els_tracking_number'   => 'trackingNumber',
			'_els_tracking_url'      => 'trackingUrl',
			'_els_delivery_note_url' => 'deliveryNoteUrl',
			'_els_label_url'         => 'labelUrl',
		);
		foreach ( $fields as $meta_key => $response_key ) {
			if ( ! empty( $order_result[ $response_key ] ) ) {
				if ( in_array( $response_key, array( 'serialNumber', 'trackingNumber' ), true ) ) {
					$order->update_meta_data( $meta_key, sanitize_text_field( $order_result[ $response_key ] ) );
				} else {
					$order->update_meta_data( $meta_key, esc_url_raw( $order_result[ $response_key ] ) );
				}
			}
		}

		$tracking_meta_key = sanitize_key( $settings['tracking_meta_key'] ?? '_tracking_number' );
		$tracking_value    = $order_result['trackingNumber'] ?? $order_result['serialNumber'] ?? '';
		if ( $tracking_meta_key && $tracking_value ) {
			$order->update_meta_data( $tracking_meta_key, sanitize_text_field( $tracking_value ) );
		}

		$order->update_meta_data( '_els_export_status', 'sent' );
		$order->update_meta_data( '_els_sent_at', current_time( 'mysql' ) );
		$order->delete_meta_data( '_els_last_error' );
		$order->add_order_note( sprintf( __( 'ELS shipment created. Tracking number: %s', 'els-woocommerce' ), $order_result['trackingNumber'] ?? $order_result['serialNumber'] ?? '—' ) );
		$order->save();

		// Compatibility mirror for existing admin fields that still read with get_post_meta().
		if ( $tracking_meta_key && $tracking_value ) {
			update_post_meta( $order->get_id(), $tracking_meta_key, sanitize_text_field( $tracking_value ) );
		}

		do_action( 'els_wc_order_sent', $order->get_id(), $order_result, $response );
		return $order_result;
	}

	private function parse_mapping( $text ) {
		$mapping = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) || false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $header, $source ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' !== $header && '' !== $source ) {
				$mapping[ $header ] = $source;
			}
		}
		return $mapping;
	}

	private function value( $source, WC_Order $order, array $settings ) {
		if ( 0 === strpos( $source, 'static:' ) ) {
			return apply_filters( 'els_wc_column_value', substr( $source, 7 ), $source, $order, $settings );
		}
		if ( 0 === strpos( $source, 'meta:' ) ) {
			return apply_filters( 'els_wc_column_value', (string) $order->get_meta( substr( $source, 5 ) ), $source, $order, $settings );
		}

		$first_name = $order->get_shipping_first_name() ?: $order->get_billing_first_name();
		$last_name  = $order->get_shipping_last_name() ?: $order->get_billing_last_name();
		$company    = $order->get_shipping_company() ?: $order->get_billing_company();
		$address_1  = $order->get_shipping_address_1() ?: $order->get_billing_address_1();
		$address_2  = $order->get_shipping_address_2() ?: $order->get_billing_address_2();
		$city       = $order->get_shipping_city() ?: $order->get_billing_city();
		$state      = $order->get_shipping_state() ?: $order->get_billing_state();
		$postcode   = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
		$country    = $order->get_shipping_country() ?: $order->get_billing_country();
		$els_place  = $order->get_meta( '_els_place_text' );
		$recipient_place = 'MK' === strtoupper( (string) $country ) ? ( $els_place ?: $city ) : $city;
		if ( 'MK' === strtoupper( (string) $country ) ) {
			$recipient_place = ELS_WC_Places::resolve( $recipient_place, true ) ?: $recipient_place;
		}

		$values = array(
			'order_id'                 => $order->get_id(),
			'order_number'             => $order->get_order_number(),
			'reference_number'         => $settings['reference_prefix'] . $order->get_order_number(),
			'recipient_name'           => trim( $first_name . ' ' . $last_name ),
			'recipient_first_name'     => $first_name,
			'recipient_last_name'      => $last_name,
			'recipient_company'        => $company,
			'recipient_address'        => trim( $address_1 . ' ' . $address_2 ),
			'recipient_address_1'      => $address_1,
			'recipient_address_2'      => $address_2,
			'recipient_place'          => $recipient_place,
			'recipient_state'          => $state,
			'recipient_postcode'       => $postcode,
			'recipient_country'        => $country,
			'recipient_phone'          => $order->get_billing_phone(),
			'recipient_email'          => $order->get_billing_email(),
			'cod_amount'               => $this->cod_amount( $order, $settings ),
			'order_total'              => wc_format_decimal( $order->get_total(), 2 ),
			'weight'                   => $this->weight( $order, $settings ),
			'note'                     => $order->get_customer_note(),
			'payment_method'           => $order->get_payment_method(),
			'payment_method_title'     => $order->get_payment_method_title(),
			'items'                    => $this->items( $order ),
			'sender_name'              => $settings['sender_name'],
			'sender_address'           => $settings['sender_address'],
			'sender_place'             => $settings['sender_place'],
			'sender_phone'             => $settings['sender_phone'],
			'store_name'               => get_bloginfo( 'name' ),
		);

		$value = $values[ $source ] ?? '';
		return apply_filters( 'els_wc_column_value', $value, $source, $order, $settings );
	}

	private function cod_amount( WC_Order $order, array $settings ) {
		$methods = array_filter( array_map( 'trim', explode( ',', strtolower( $settings['cod_methods'] ) ) ) );
		return in_array( strtolower( $order->get_payment_method() ), $methods, true ) ? wc_format_decimal( $order->get_total(), 2 ) : '0';
	}

	private function weight( WC_Order $order, array $settings ) {
		$total = 0.0;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && '' !== $product->get_weight() ) {
				$total += (float) wc_get_weight( (float) $product->get_weight(), 'kg' ) * (float) $item->get_quantity();
			}
		}
		if ( $total <= 0 ) {
			$total = (float) $settings['default_weight'];
		}
		return wc_format_decimal( $total, 3 );
	}

	private function items( WC_Order $order ) {
		$parts = array();
		foreach ( $order->get_items() as $item ) {
			$parts[] = $item->get_name() . ' x ' . $item->get_quantity();
		}
		return implode( ', ', $parts );
	}

	private function make_csv( array $headers, array $row, $delimiter ) {
		$stream = fopen( 'php://temp', 'r+' );
		if ( false === $stream ) {
			return new WP_Error( 'els_csv_error', __( 'Could not create the ELS CSV payload.', 'els-woocommerce' ) );
		}
		fputcsv( $stream, $headers, $delimiter, '"', '' );
		fputcsv( $stream, $row, $delimiter, '"', '' );
		rewind( $stream );
		$csv = stream_get_contents( $stream );
		fclose( $stream );
		return $csv;
	}

	private function delimiter( $setting ) {
		return array( 'tab' => "\t", 'comma' => ',', 'semicolon' => ';', 'pipe' => '|' )[ $setting ] ?? ',';
	}

	private function find_order_result( array $response, $reference ) {
		$orders = isset( $response['orders'] ) && is_array( $response['orders'] ) ? $response['orders'] : array();
		foreach ( $orders as $result ) {
			if ( isset( $result['referenceNumber'] ) && (string) $result['referenceNumber'] === $reference ) {
				return $result;
			}
		}
		return $orders[0] ?? null;
	}

	private function response_error( array $response, array $errors, $order_result ) {
		if ( ! empty( $errors[0]['error_description'] ) ) {
			return sanitize_text_field( $errors[0]['error_description'] );
		}
		if ( is_array( $order_result ) && ! empty( $order_result['message'] ) ) {
			return sanitize_text_field( $order_result['message'] );
		}
		return sanitize_text_field( $response['message'] ?? __( 'ELS did not create a shipment for this order.', 'els-woocommerce' ) );
	}

	private function fail( WC_Order $order, $message ) {
		$order->update_meta_data( '_els_export_status', 'failed' );
		$order->update_meta_data( '_els_last_error', sanitize_text_field( $message ) );
		$order->add_order_note( sprintf( __( 'ELS shipment failed: %s', 'els-woocommerce' ), sanitize_text_field( $message ) ) );
		$order->save();
		do_action( 'els_wc_order_failed', $order->get_id(), $message );
		return new WP_Error( 'els_order_failed', $message );
	}
}
