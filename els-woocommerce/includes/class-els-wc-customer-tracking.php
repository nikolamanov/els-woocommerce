<?php

defined( 'ABSPATH' ) || exit;

final class ELS_WC_Customer_Tracking {
	public static function init() {
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_tracking_link' ), 20, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'account_tracking_link' ), 20, 1 );
		add_action( 'els_wc_order_sent', array( __CLASS__, 'notify_completed_order_after_shipment' ), 20, 1 );
	}

	public static function email_tracking_link( $order, $sent_to_admin, $plain_text, $email ) {
		if ( 'yes' !== ELS_WC_Settings::get( 'customer_tracking_email', 'yes' ) || $sent_to_admin || ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! $email || 'customer_completed_order' !== $email->id ) {
			return;
		}

		$url = self::tracking_url( $order );
		if ( ! $url ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Вашата нарачка е испратена. Можете да ја следите на следниот линк:', 'els-woocommerce' ) . "\n" . esc_url_raw( $url ) . "\n";
			return;
		}

		echo '<h2>' . esc_html__( 'Следење на пратката', 'els-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Вашата нарачка е испратена. Можете да ја следите на следниот линк:', 'els-woocommerce' ) . ' ';
		echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Следете ја пратката', 'els-woocommerce' ) . '</a></p>';
	}

	public static function account_tracking_link( $order ) {
		if ( 'yes' !== ELS_WC_Settings::get( 'customer_tracking_email', 'yes' ) || ! $order instanceof WC_Order ) {
			return;
		}
		$url = self::tracking_url( $order );
		if ( ! $url ) {
			return;
		}
		echo '<section class="woocommerce-order-tracking">';
		echo '<h2 class="woocommerce-order-details__title">' . esc_html__( 'Следење на пратката', 'els-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Вашата нарачка е испратена. Можете да ја следите на следниот линк:', 'els-woocommerce' ) . ' ';
		echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Следете ја пратката', 'els-woocommerce' ) . '</a></p>';
		echo '</section>';
	}

	public static function notify_completed_order_after_shipment( $order_id ) {
		if ( 'yes' !== ELS_WC_Settings::get( 'customer_tracking_email', 'yes' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->has_status( 'completed' ) || $order->get_meta( '_els_tracking_customer_note_sent' ) ) {
			return;
		}
		$url = self::tracking_url( $order );
		if ( ! $url ) {
			return;
		}

		$note = sprintf(
			'%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>',
			esc_html__( 'Вашата нарачка е испратена. Можете да ја следите на следниот линк:', 'els-woocommerce' ),
			esc_url( $url ),
			esc_html__( 'Следете ја пратката', 'els-woocommerce' )
		);
		$order->add_order_note( wp_kses_post( $note ), true );
		$order->update_meta_data( '_els_tracking_customer_note_sent', current_time( 'mysql' ) );
		$order->save();
	}

	private static function tracking_url( WC_Order $order ) {
		$meta_key = sanitize_key( ELS_WC_Settings::get( 'tracking_meta_key', '_tracking_number' ) );
		$tracking = $meta_key ? $order->get_meta( $meta_key ) : '';
		if ( ! $tracking ) {
			$tracking = $order->get_meta( '_els_tracking_number' ) ?: $order->get_meta( '_els_serial_number' );
		}
		$tracking = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $tracking );
		if ( ! $tracking ) {
			return '';
		}
		$base = ELS_WC_Settings::get( 'tracking_page_base_url', 'https://track.els.mk/mk/' );
		return trailingslashit( $base ) . rawurlencode( $tracking );
	}
}

