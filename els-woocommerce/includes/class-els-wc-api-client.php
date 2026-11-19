<?php

defined( 'ABSPATH' ) || exit;

final class ELS_WC_API_Client {
	private $settings;

	public function __construct( array $settings ) {
		$this->settings = $settings;
	}

	public function authenticate( $force = false ) {
		$required = array( 'api_base_url', 'login_endpoint', 'client_id', 'client_secret', 'username', 'password' );
		foreach ( $required as $key ) {
			if ( empty( $this->settings[ $key ] ) ) {
				return new WP_Error( 'els_missing_setting', sprintf( __( 'Missing ELS setting: %s.', 'els-woocommerce' ), $key ) );
			}
		}

		$cache_key = $this->token_cache_key();
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached['access_token'] ) ) {
				return $cached;
			}
		}

		delete_transient( $cache_key );
		$url = $this->url( $this->settings['login_endpoint'] );
		$payload = array(
			'client_id'     => $this->settings['client_id'],
			'client_secret' => $this->settings['client_secret'],
			'username'      => $this->settings['username'],
			'password'      => $this->settings['password'],
			'grant_type'    => 'password',
		);

		$result = $this->post_json( $url, $payload, array() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['body']['access_token'] ) ) {
			return new WP_Error( 'els_missing_token', __( 'ELS login response did not contain an access token.', 'els-woocommerce' ) );
		}

		$token_data = $result['body'];
		$ttl        = max( 60, absint( $token_data['expires_in'] ?? 3600 ) - 60 );
		set_transient( $cache_key, $token_data, $ttl );
		$this->log( 'Authentication successful.', 'info' );
		return $token_data;
	}

	public function create_bulk_order( array $payload ) {
		$token = $this->authenticate();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$result = $this->post_json(
			$this->url( $this->settings['bulk_endpoint'] ),
			$payload,
			array( 'Authorization' => 'Bearer ' . $token['access_token'] )
		);

		$error_data = is_wp_error( $result ) ? $result->get_error_data( 'els_http_error' ) : array();
		if ( is_wp_error( $result ) && is_array( $error_data ) && 401 === (int) ( $error_data['status'] ?? 0 ) ) {
			$token = $this->authenticate( true );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$result = $this->post_json(
				$this->url( $this->settings['bulk_endpoint'] ),
				$payload,
				array( 'Authorization' => 'Bearer ' . $token['access_token'] )
			);
		}

		return is_wp_error( $result ) ? $result : $result['body'];
	}

	/** POST /orders/status — returns the response keyed by tracking number. */
	public function get_statuses( array $numbers ) {
		$token = $this->authenticate();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$url     = $this->url( '/orders/status' );
		$payload = array( 'trackingNumber' => array_values( $numbers ) );
		$result  = $this->post_json( $url, $payload, array( 'Authorization' => 'Bearer ' . $token['access_token'] ) );

		$error_data = is_wp_error( $result ) ? $result->get_error_data( 'els_http_error' ) : array();
		if ( is_wp_error( $result ) && is_array( $error_data ) && 401 === (int) ( $error_data['status'] ?? 0 ) ) {
			$token = $this->authenticate( true );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$result = $this->post_json( $url, $payload, array( 'Authorization' => 'Bearer ' . $token['access_token'] ) );
		}

		return is_wp_error( $result ) ? $result : $result['body'];
	}

	public function get_shipment_document( $url ) {
		$api   = wp_parse_url( $this->settings['api_base_url'] );
		$print = wp_parse_url( $url );
		if ( 'https' !== ( $api['scheme'] ?? '' ) || 'https' !== ( $print['scheme'] ?? '' )
			|| empty( $api['host'] ) || 0 !== strcasecmp( $api['host'], $print['host'] ?? '' )
			|| ( $api['port'] ?? 443 ) !== ( $print['port'] ?? 443 )
			|| isset( $print['user'] ) || isset( $print['pass'] )
			|| ! preg_match( '~^/(?:orders|consignments)/print/[a-z0-9-]+$~D', $print['path'] ?? '' ) ) {
			return new WP_Error( 'els_document_url', __( 'The saved link is not a print URL on the configured ELS API.', 'els-woocommerce' ) );
		}

		$max_bytes = 10 * 1024 * 1024;
		$args      = array(
			'timeout'             => min( 120, max( 5, absint( $this->settings['timeout'] ?? 30 ) ) ),
			'redirection'         => 0,
			'limit_response_size' => $max_bytes + 1,
			'headers'             => array( 'Accept' => 'application/pdf' ),
		);
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$token = $this->authenticate( 0 < $attempt );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$args['headers']['Authorization'] = 'Bearer ' . $token['access_token'];
			$response = wp_safe_remote_get( $url, $args );
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'els_document_request', __( 'Could not reach ELS to download the document.', 'els-woocommerce' ) );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( 401 !== $status ) {
				break;
			}
		}

		if ( 401 === $status || 403 === $status ) {
			return new WP_Error( 'els_document_unauthorized', __( 'ELS refused access to this document. Check whether the configured ELS account can print it.', 'els-woocommerce' ) );
		}
		if ( 200 !== $status ) {
			return new WP_Error( 'els_document_http', sprintf( __( 'ELS could not return the document (HTTP %d).', 'els-woocommerce' ), $status ) );
		}

		$pdf = wp_remote_retrieve_body( $response );
		if ( strlen( $pdf ) > $max_bytes || 0 !== strpos( $pdf, '%PDF-' ) ) {
			return new WP_Error( 'els_document_format', __( 'ELS did not return a PDF document of 10 MB or smaller.', 'els-woocommerce' ) );
		}

		return $pdf;
	}

	private function post_json( $url, array $payload, array $headers ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => min( 120, max( 5, absint( $this->settings['timeout'] ?? 30 ) ) ),
				'headers' => array_merge(
					array(
						'Accept'       => 'application/json',
						'Content-Type' => 'application/json; charset=utf-8',
					),
					$headers
				),
				'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log( 'Network error: ' . $response->get_error_code(), 'error' );
			return new WP_Error( 'els_network_error', sprintf( __( 'ELS network error: %s', 'els-woocommerce' ), $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$body   = json_decode( $raw, true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$this->log( 'API response HTTP ' . $status . ' for ' . wp_parse_url( $url, PHP_URL_PATH ) . '.', $status >= 400 ? 'error' : 'info' );
		if ( $status < 200 || $status >= 300 ) {
			$message = $body['error_description'] ?? $body['message'] ?? sprintf( __( 'ELS returned HTTP %d.', 'els-woocommerce' ), $status );
			return new WP_Error(
				'els_http_error',
				sanitize_text_field( $message ),
				array(
					'status'   => $status,
					'response' => $body,
				)
			);
		}

		return array( 'status' => $status, 'body' => $body );
	}

	private function url( $endpoint ) {
		return untrailingslashit( $this->settings['api_base_url'] ) . '/' . ltrim( $endpoint, '/' );
	}

	private function token_cache_key() {
		return 'els_wc_token_' . substr( md5( $this->settings['api_base_url'] . '|' . $this->settings['client_id'] . '|' . $this->settings['username'] ), 0, 24 );
	}

	private function log( $message, $level = 'info' ) {
		if ( 'yes' !== ( $this->settings['debug'] ?? 'no' ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => 'els-woocommerce' ) );
	}
}
