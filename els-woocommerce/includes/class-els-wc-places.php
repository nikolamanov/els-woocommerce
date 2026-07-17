<?php

defined( 'ABSPATH' ) || exit;

final class ELS_WC_Places {
	private static $places = null;
	private static $alias_index = null;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_checkout_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'normalize_classic_order' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'normalize_store_api_order' ), 20, 2 );
	}

	public static function all() {
		if ( null !== self::$places ) {
			return self::$places;
		}

		self::$places = array();
		$path         = ELS_WC_PATH . 'assets/els-places.json';
		if ( ! is_readable( $path ) ) {
			return self::$places;
		}

		$data = json_decode( file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) ) {
			return self::$places;
		}

		foreach ( $data as $place ) {
			if ( isset( $place['id'], $place['text'] ) && '' !== trim( (string) $place['text'] ) ) {
				self::$places[ (string) absint( $place['id'] ) ] = sanitize_text_field( $place['text'] );
			}
		}

		return self::$places;
	}

	public static function options() {
		$options = array( '' => __( 'Пребарајте и изберете место…', 'els-woocommerce' ) );
		foreach ( self::all() as $text ) {
			$options[ $text ] = $text;
		}
		return $options;
	}

	public static function is_valid( $text ) {
		return in_array( (string) $text, self::all(), true );
	}

	public static function resolve( $input, $allow_fuzzy = true ) {
		$input = sanitize_text_field( (string) $input );
		if ( '' === $input ) {
			return null;
		}
		if ( self::is_valid( $input ) ) {
			return $input;
		}

		self::build_alias_index();
		$normalized = self::normalize( $input );
		$loose      = self::loose_normalize( $normalized );
		$keys       = array_filter( array_unique( array( $normalized, self::sorted_tokens( $normalized ), $loose, self::sorted_tokens( $loose ) ) ) );
		foreach ( $keys as $key ) {
			$candidates = self::$alias_index[ $key ] ?? array();
			if ( 1 === count( $candidates ) ) {
				return reset( $candidates );
			}
		}

		if ( ! $allow_fuzzy || strlen( $normalized ) < 4 ) {
			return null;
		}

		$maximum   = strlen( $normalized ) <= 7 ? 1 : 2;
		$best      = null;
		$best_dist = $maximum + 1;
		$tied      = false;
		foreach ( self::$alias_index as $alias => $candidates ) {
			if ( 1 !== count( $candidates ) || abs( strlen( $alias ) - strlen( $normalized ) ) > $maximum ) {
				continue;
			}
			$distance = levenshtein( $normalized, $alias );
			if ( $distance < $best_dist ) {
				$best      = reset( $candidates );
				$best_dist = $distance;
				$tied      = false;
			} elseif ( $distance === $best_dist && reset( $candidates ) !== $best ) {
				$tied = true;
			}
		}

		return ! $tied && $best_dist <= $maximum ? $best : null;
	}

	public static function normalize( $text ) {
		$text = strtr(
			(string) $text,
			array(
				'А' => 'a', 'а' => 'a', 'Б' => 'b', 'б' => 'b', 'В' => 'v', 'в' => 'v', 'Г' => 'g', 'г' => 'g',
				'Д' => 'd', 'д' => 'd', 'Ѓ' => 'gj', 'ѓ' => 'gj', 'Е' => 'e', 'е' => 'e', 'Ж' => 'zh', 'ж' => 'zh',
				'З' => 'z', 'з' => 'z', 'Ѕ' => 'dz', 'ѕ' => 'dz', 'И' => 'i', 'и' => 'i', 'Ј' => 'j', 'ј' => 'j',
				'К' => 'k', 'к' => 'k', 'Л' => 'l', 'л' => 'l', 'Љ' => 'lj', 'љ' => 'lj', 'М' => 'm', 'м' => 'm',
				'Н' => 'n', 'н' => 'n', 'Њ' => 'nj', 'њ' => 'nj', 'О' => 'o', 'о' => 'o', 'П' => 'p', 'п' => 'p',
				'Р' => 'r', 'р' => 'r', 'С' => 's', 'с' => 's', 'Т' => 't', 'т' => 't', 'Ќ' => 'kj', 'ќ' => 'kj',
				'У' => 'u', 'у' => 'u', 'Ф' => 'f', 'ф' => 'f', 'Х' => 'h', 'х' => 'h', 'Ц' => 'c', 'ц' => 'c',
				'Ч' => 'ch', 'ч' => 'ch', 'Џ' => 'dzh', 'џ' => 'dzh', 'Ш' => 'sh', 'ш' => 'sh',
				'Č' => 'c', 'č' => 'c', 'Ć' => 'c', 'ć' => 'c', 'Š' => 's', 'š' => 's', 'Ž' => 'z', 'ž' => 'z', 'Đ' => 'dj', 'đ' => 'dj',
			)
		);
		$text = strtolower( $text );
		$text = preg_replace( '/[^a-z0-9]+/', ' ', $text );
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	private static function build_alias_index() {
		if ( null !== self::$alias_index ) {
			return;
		}
		self::$alias_index = array();
		foreach ( self::all() as $place ) {
			$full    = self::normalize( $place );
			$primary = self::normalize( preg_split( '/[,(]/u', $place, 2 )[0] );
			$loose_full    = self::loose_normalize( $full );
			$loose_primary = self::loose_normalize( $primary );
			foreach ( array_filter( array_unique( array( $full, self::sorted_tokens( $full ), $primary, $loose_full, self::sorted_tokens( $loose_full ), $loose_primary ) ) ) as $alias ) {
				self::$alias_index[ $alias ][ $place ] = $place;
			}
		}
	}

	private static function loose_normalize( $normalized ) {
		return strtr(
			$normalized,
			array(
				'dzh' => 'dz',
				'zh'  => 'z',
				'sh'  => 's',
				'ch'  => 'c',
			)
		);
	}

	private static function sorted_tokens( $normalized ) {
		$tokens = array_filter( explode( ' ', $normalized ) );
		sort( $tokens, SORT_STRING );
		return implode( ' ', $tokens );
	}

	public static function enqueue_checkout_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || 'yes' !== ELS_WC_Settings::get( 'place_autocomplete', 'yes' ) ) {
			return;
		}
		wp_enqueue_script(
			'els-wc-place-autocomplete',
			plugins_url( 'assets/els-place-autocomplete.js', ELS_WC_FILE ),
			array(),
			ELS_WC_VERSION,
			true
		);
		wp_enqueue_style(
			'els-wc-place-autocomplete',
			plugins_url( 'assets/els-place-autocomplete.css', ELS_WC_FILE ),
			array(),
			ELS_WC_VERSION
		);
		$default_country = '';
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$default_country = WC()->customer->get_shipping_country() ?: WC()->customer->get_billing_country();
		}
		if ( ! $default_country && function_exists( 'wc_get_base_location' ) ) {
			$base_location   = wc_get_base_location();
			$default_country = $base_location['country'] ?? '';
		}
		wp_localize_script(
			'els-wc-place-autocomplete',
			'elsWcPlaces',
			array(
				'places'         => array_values( self::all() ),
				'allowedCountry' => 'MK',
				'defaultCountry' => strtoupper( (string) $default_country ),
				'placeholder'    => __( 'Пребарајте и изберете место…', 'els-woocommerce' ),
			)
		);
	}

	public static function normalize_classic_order( $order, $data ) {
		self::normalize_order( $order, false );
	}

	public static function normalize_store_api_order( $order, $request ) {
		self::normalize_order( $order, false );
		$order->save();
	}

	private static function normalize_order( $order, $allow_fuzzy ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$billing_country  = strtoupper( (string) $order->get_billing_country() );
		$shipping_country = strtoupper( (string) ( $order->get_shipping_country() ?: $billing_country ) );
		$billing          = 'MK' === $billing_country ? self::resolve( $order->get_billing_city(), $allow_fuzzy ) : null;
		$shipping         = 'MK' === $shipping_country ? self::resolve( $order->get_shipping_city(), $allow_fuzzy ) : null;
		if ( $billing ) {
			$order->set_billing_city( $billing );
		}
		if ( $shipping ) {
			$order->set_shipping_city( $shipping );
		}
		$preferred = $shipping ?: $billing;
		if ( $preferred ) {
			$order->update_meta_data( '_els_place_text', $preferred );
		} elseif ( 'MK' !== $billing_country && 'MK' !== $shipping_country ) {
			$order->delete_meta_data( '_els_place_text' );
		}
	}

	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		self::enqueue_selectwoo();
	}

	private static function enqueue_selectwoo() {
		wp_enqueue_script( 'selectWoo' );
		wp_enqueue_style( 'select2' );
		$script = <<<'JS'
(function($){
	var elsMap = {
		'а':'a','б':'b','в':'v','г':'g','д':'d','ѓ':'gj','е':'e','ж':'zh','з':'z','ѕ':'dz','и':'i','ј':'j','к':'k','л':'l','љ':'lj','м':'m','н':'n','њ':'nj','о':'o','п':'p','р':'r','с':'s','т':'t','ќ':'kj','у':'u','ф':'f','х':'h','ц':'c','ч':'ch','џ':'dzh','ш':'sh',
		'č':'c','ć':'c','š':'s','ž':'z','đ':'dj'
	};
	function elsNormalize(value){
		return String(value || '').toLowerCase().split('').map(function(char){
			return elsMap[char] || char;
		}).join('').replace(/[^a-z0-9]+/g, ' ').replace(/\s+/g, ' ').trim();
	}
	function elsLoose(value){
		return elsNormalize(value).replace(/dzh/g, 'dz').replace(/zh/g, 'z').replace(/sh/g, 's').replace(/ch/g, 'c');
	}
	function elsMatcher(params, data){
		var term = elsLoose(params.term);
		if (!term) return data;
		if (!data || !data.text) return null;
		var text = elsLoose(data.text);
		var words = term.split(' ').filter(Boolean);
		return words.every(function(word){ return text.indexOf(word) !== -1; }) ? data : null;
	}
	function elsInitPlaces(){
		$('.els-wc-place-select').each(function(){
			var $field = $(this);
			if ($field.data('els-wc-latin-matcher')) return;
			if ($.fn.selectWoo) {
				if ($field.hasClass('select2-hidden-accessible')) {
					$field.selectWoo('destroy');
				}
				$field.selectWoo({
					width: '100%',
					placeholder: $field.data('placeholder') || '',
					matcher: elsMatcher
				});
				$field.data('els-wc-latin-matcher', true);
			}
		});
	}
	$(elsInitPlaces);
	$(document.body).on('updated_checkout country_to_state_changed', elsInitPlaces);
})(jQuery);
JS;
		wp_add_inline_script( 'selectWoo', $script );
	}
}
