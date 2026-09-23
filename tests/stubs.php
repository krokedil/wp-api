<?php
/**
 * The handful of WordPress functions that Request and Logger need.
 *
 * The masking code itself must never call a WordPress or WooCommerce function, so if
 * this file has to grow to run the masking tests, something has leaked into it.
 *
 * @package Krokedil/WpApi
 */

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, (array) $args );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		return $GLOBALS['wp_api_test_response'] ?? array(
			'body'     => '{}',
			'response' => array( 'code' => 200 ),
		);
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return $response['body'] ?? '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'] ?? 0;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {}
}

if ( ! class_exists( 'WC_Logger' ) ) {
	class WC_Logger {
		public $entries = array();

		public function add( $handle, $message ) {
			$this->entries[] = array(
				'handle'  => $handle,
				'message' => $message,
			);
		}
	}
}
