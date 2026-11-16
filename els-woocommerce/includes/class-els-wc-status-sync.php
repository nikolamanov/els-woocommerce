<?php

defined( 'ABSPATH' ) || exit;

/**
 * Pulls shipment statuses from ELS and moves the WooCommerce order forward:
 * picked up / in delivery -> "shipped" status, delivered -> "delivered" status,
 * returned / cancelled -> "returned" status. Never moves an order backwards.
 */
final class ELS_WC_Status_Sync {
	const HOOK = 'els_wc_sync_statuses';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_interval' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 30, 2 );
		add_action( 'woocommerce_order_action_els_wc_refresh_status', array( __CLASS__, 'run_order_action' ) );
		add_action( 'admin_post_els_wc_sync_now', array( __CLASS__, 'handle_sync_now' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	public static function add_interval( $schedules ) {
		$schedules['els_wc_30min'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 30 minutes (ELS status sync)', 'els-woocommerce' ),
		);
		return $schedules;
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'els_wc_30min', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** "code=target" lines; target is shipped|delivered|returned|ignore. */
	public static function parse_map( $text ) {
		$map = array();
		foreach ( preg_split( '/\R/u', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] || false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $code, $target ) = array_map( 'trim', explode( '=', $line, 2 ) );
			$target = strtolower( $target );
			if ( '' !== $code && in_array( $target, array( 'shipped', 'delivered', 'returned', 'ignore' ), true ) ) {
				$map[ $code ] = $target;
			}
		}
		return $map;
	}

	/** Newest first: array of array( code, text, date, failed ). */
	public static function extract_statuses( array $entry ) {
		$list = array();
		if ( ! empty( $entry['current'] ) && is_array( $entry['current'] ) && ! empty( $entry['current']['code'] ) ) {
			$list[] = $entry['current'];
		}
		foreach ( (array) ( $entry['history'] ?? array() ) as $item ) {
			if ( is_array( $item ) ) {
				$list[] = $item;
			}
		}
		$out = array();
		foreach ( $list as $item ) {
			$out[] = array(
				'code'   => trim( (string) ( $item['code'] ?? '' ) ),
				'text'   => trim( wp_strip_all_tags( (string) ( $item['text'] ?? '' ) ) ),
				'date'   => trim( (string) ( $item['date'] ?? '' ) ),
				'failed' => ! empty( $item['failCode'] ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);
		return $out;
	}

	/** One ELS status -> shipped|delivered|returned or null when unknown. */
	public static function classify( array $st, array $map ) {
		if ( isset( $map[ $st['code'] ] ) ) {
			return 'ignore' === $map[ $st['code'] ] ? null : $map[ $st['code'] ];
		}
		$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( $st['text'] ) : strtolower( $st['text'] );
		if ( '' === $t ) {
			return null;
		}
		if ( preg_match( '/(врат|откаж|сторн|return|cancel|kthim|anul)/u', $t ) ) {
			return 'returned';
		}
		if ( ! $st['failed']
			&& ! preg_match( '/(недоста|неиспор|неуспеш|not delivered|undelivered|failed|pa dorëzuar)/u', $t )
			&& preg_match( '/(доставена|доставен|испорачан|delivered|dorëzuar|dorezuar)/u', $t ) ) {
			return 'delivered';
		}
		if ( preg_match( '/(преземен|подигнат|магацин|транзит|во достава|за достава|на пат|picked|collected|warehouse|transit|in delivery|out for delivery|for delivery|dispatch|depo|në dërgim)/u', $t ) ) {
			return 'shipped';
		}
		return null;
	}

	/** First recognised status, newest first. */
	public static function target( array $statuses, array $map ) {
		foreach ( $statuses as $st ) {
			$target = self::classify( $st, $map );
			if ( null !== $target ) {
				return $target;
			}
		}
		return null;
	}

	/** Never go backwards. $current is a status slug without "wc-". */
	public static function transition_allowed( $current, $target ) {
		$open = array( 'pending', 'on-hold', 'processing' );
		switch ( $target ) {
			case 'shipped':
				return in_array( $current, $open, true );
			case 'delivered':
				return in_array( $current, array_merge( $open, array( 'shipped' ) ), true ) || ! in_array( $current, array( 'completed', 'cancelled', 'refunded', 'failed' ), true );
			case 'returned':
				return ! in_array( $current, array( 'completed', 'cancelled', 'refunded', 'failed' ), true );
		}
		return false;
	}

	/** @return array{checked:int,updated:int,unknown:int,errors:string[]} */
	public static function run( $only_order_id = 0 ) {
		$result   = array( 'checked' => 0, 'updated' => 0, 'unknown' => 0, 'errors' => array() );
		$settings = ELS_WC_Settings::get_all();
		if ( 'yes' !== $settings['enabled'] || ( ! $only_order_id && 'yes' !== $settings['sync_enabled'] ) ) {
			return $result;
		}

		$args = array(
			'limit'      => 200,
			'return'     => 'objects',
			'status'     => array( 'pending', 'on-hold', 'processing', 'shipped' ),
			'meta_key'   => '_els_export_status', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => 'sent', // phpcs:ignore WordPress.DB.SlowDBQuery
			'date_created' => '>' . ( time() - 120 * DAY_IN_SECONDS ),
		);
		if ( $only_order_id ) {
			$args['include'] = array( (int) $only_order_id );
			unset( $args['date_created'] );
			$args['status'] = array_keys( wc_get_order_statuses() );
			$args['status'] = array_map( static function ( $s ) { return preg_replace( '/^wc-/', '', $s ); }, $args['status'] );
		}
		$orders = wc_get_orders( $args );
		if ( ! $orders ) {
			return $result;
		}

		$by_number = array();
		foreach ( $orders as $order ) {
			$number = (string) ( $order->get_meta( '_els_tracking_number' ) ?: $order->get_meta( '_els_serial_number' ) );
			if ( '' !== $number ) {
				$by_number[ $number ] = $order;
			}
		}
		if ( ! $by_number ) {
			return $result;
		}

		$map    = self::parse_map( $settings['status_map'] );
		$client = new ELS_WC_API_Client( $settings );
		foreach ( array_chunk( array_keys( $by_number ), 50 ) as $chunk ) {
			$body = $client->get_statuses( $chunk );
			if ( is_wp_error( $body ) ) {
				$result['errors'][] = $body->get_error_message();
				continue;
			}
			foreach ( $chunk as $number ) {
				$order = $by_number[ $number ];
				$entry = $body[ $number ] ?? null;
				if ( ! is_array( $entry ) ) {
					$result['errors'][] = $number . ': no response from ELS.';
					continue;
				}
				$err = $entry['errorМsg'] ?? $entry['errorMsg'] ?? '';
				if ( ! empty( $err ) ) {
					$result['errors'][] = $number . ': ' . sanitize_text_field( $err );
					continue;
				}
				$statuses = self::extract_statuses( $entry );
				++$result['checked'];
				if ( ! $statuses ) {
					continue;
				}
				$latest = $statuses[0];
				$order->update_meta_data( '_els_last_status_code', sanitize_text_field( $latest['code'] ) );
				$order->update_meta_data( '_els_last_status_text', sanitize_text_field( $latest['text'] ) );
				$order->update_meta_data( '_els_last_status_at', sanitize_text_field( $latest['date'] ) );
				$order->save();

				$target = self::target( $statuses, $map );
				if ( null === $target ) {
					++$result['unknown'];
					continue;
				}
				$slug = preg_replace( '/^wc-/', '', (string) $settings[ 'status_' . $target ] );
				if ( '' === $slug || ! array_key_exists( 'wc-' . $slug, wc_get_order_statuses() ) ) {
					continue;
				}
				$current = $order->get_status();
				if ( $current === $slug || ! self::transition_allowed( $current, $target ) ) {
					continue;
				}
				$order->update_status(
					$slug,
					sprintf( /* translators: %s: ELS status text */ __( 'ELS status: %s.', 'els-woocommerce' ), $latest['text'] )
				);
				++$result['updated'];
			}
		}
		return $result;
	}

	public static function order_actions( $actions, $order = null ) {
		$actions['els_wc_refresh_status'] = __( 'Refresh ELS shipment status', 'els-woocommerce' );
		return $actions;
	}

	public static function run_order_action( $order ) {
		self::run( $order->get_id() );
	}

	public static function handle_sync_now() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'els-woocommerce' ), 403 );
		}
		check_admin_referer( 'els_wc_sync_now' );
		$r   = self::run();
		$msg = sprintf( 'checked=%d&updated=%d&unknown=%d&errors=%d', $r['checked'], $r['updated'], $r['unknown'], count( $r['errors'] ) );
		wp_safe_redirect( admin_url( 'admin.php?page=els-wc-settings&els_sync=1&' . $msg ) );
		exit;
	}
}
