<?php
/**
 * Base request class file for the package. This file is used to define the base class for the package that will be extended by all other classes.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi;

use Krokedil\WpApi\Logger;
use Krokedil\WpApi\KeyMasker;

/**
 * Base request class for the package.
 */
abstract class Request {
	/**
	 * The reserved rule key that turns a rule into an allow list.
	 */
	const MASK_KEEP = 'keep';

	/**
	 * Config values.
	 *
	 * @var array
	 */
	public $config = array();

	/**
	 * Default values for the config.
	 *
	 * @var array
	 */
	public $defaults = array(
		'slug'                   => 'krokedil_api',
		'plugin_version'         => '1.0.0',
		'plugin_user_agent_name' => 'KAPI',
		'logging_enabled'        => true,
		'extended_debugging'     => false,
		'base_url'               => null,
	);

	/**
	 * Settings for plugin.
	 *
	 * @var array
	 */
	public $settings = array();

	/**
	 * Any arguments that the request needs to be made.
	 *
	 * @var array
	 */
	public $arguments = array();

	/**
	 * Endpoint path to be added to the base URL for the request. Has to be added by the the child class.
	 *
	 * @var string
	 */
	public $endpoint = '';

	/**
	 * The title to use for the log message. Has to be added by the the child class.
	 *
	 * @var string
	 */
	public $log_title = '';

	/**
	 * The method to use for the request.
	 *
	 * @var string
	 */
	public $method = 'GET';

	/**
	 * Fields to mask in the request args before logging. Keys map to a list of field names or
	 * to nested arrays, matched case insensitively wherever the name appears below that level.
	 * The reserved 'keep' key turns a rule into an allow list, masking every key it does not name.
	 *
	 * Example:
	 * array(
	 *     'headers' => array( 'Authorization' ),
	 *     'body'    => array(
	 *         'billing_address' => array( 'email', 'phone' ),
	 *         'customer'        => array( 'keep' => array( 'type' ) ),
	 *         'attachment'      => 'mask',
	 *     ),
	 * )
	 *
	 * @var array
	 */
	protected $request_fields_to_mask = array(
		'headers' => array( 'Authorization' ),
	);

	/**
	 * Fields to mask in the response body before logging. Uses the same structure as the
	 * request fields, including the 'keep' allow list.
	 *
	 * Example:
	 * array(
	 *     'client_token',
	 *     'billing_address' => array( 'email', 'phone' ),
	 * )
	 *
	 * @var array
	 */
	protected $response_fields_to_mask = array();

	/**
	 * Fields to mask in the request arguments before logging, same structure as above.
	 *
	 * @var array
	 */
	protected $argument_fields_to_mask = array( 'username', 'password' );

	/**
	 * Constructor.
	 *
	 * @param array $config       Configuration array.
	 * @param array $settings     Plugin settings.
	 * @param array $arguments    Request arguments.
	 * @param array $masked_fields Optional extra fields to mask, with keys 'request', 'response' and/or 'arguments'.
	 *                             Merged into the configured fields, so an allow list can be passed here as well.
	 */
	public function __construct( $config = array(), $settings = array(), $arguments = array(), $masked_fields = array() ) {
		$this->config    = wp_parse_args( $config, $this->defaults );
		$this->settings  = $settings;
		$this->arguments = $arguments;

		if ( ! empty( $masked_fields['request'] ) ) {
			$this->request_fields_to_mask = self::merge_mask_config( $this->request_fields_to_mask, $masked_fields['request'] );
		}
		if ( ! empty( $masked_fields['response'] ) ) {
			$this->response_fields_to_mask = self::merge_mask_config( $this->response_fields_to_mask, $masked_fields['response'] );
		}
		if ( ! empty( $masked_fields['arguments'] ) ) {
			$this->argument_fields_to_mask = self::merge_mask_config( $this->argument_fields_to_mask, $masked_fields['arguments'] );
		}
	}

	/**
	 * Get the request headers.
	 *
	 * @return array
	 */
	protected function get_request_headers() {
		return array(
			'Authorization' => $this->calculate_auth(),
			'Content-Type'  => 'application/json',
		);
	}

	/**
	 * Get the request URL for the request.
	 *
	 * @return string
	 */
	protected function get_request_url() {
		$base_url = rtrim( $this->config['base_url'], '/' );
		$endpoint = ltrim( $this->endpoint, '/' );
		return "$base_url/$endpoint";
	}

	/**
	 * Get the user agent.
	 *
	 * @return string
	 */
	protected function get_user_agent() {
		$wp_version             = get_bloginfo( 'version' );
		$wp_url                 = get_bloginfo( 'url' );
		$wc_version             = WC()->version;
		$plugin_user_agent_name = $this->config['plugin_user_agent_name'];
		$plugin_version         = $this->config['plugin_version'];
		$php_version            = phpversion();

		return apply_filters( 'http_headers_useragent', "WordPress/$wp_version; $wp_url - WooCommerce: $wc_version - $plugin_user_agent_name: $plugin_version - PHP Version: $php_version - Krokedil" );
	}

	/**
	 * Make the request.
	 *
	 * @return array|\WP_Error
	 */
	public function request() {
		$url      = $this->get_request_url();
		$args     = $this->get_request_args();
		$response = wp_remote_request( $url, $args );
		return $this->process_response( $response, $args, $url );
	}

	/**
	 * Processes the response checking for errors.
	 *
	 * @param array|\WP_Error $response The response from the request.
	 * @param array           $request_args The request args.
	 * @param string          $request_url The request url.
	 * @return array|\WP_Error
	 */
	protected function process_response( $response, $request_args, $request_url ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 || $response_code > 299 ) {
			$return = $this->get_error_message( $response );
		} else {
			$return = json_decode( wp_remote_retrieve_body( $response ), true );
		}

		$this->log_response( $response, $request_args, $request_url );
		return $return;
	}

	/**
	 * Logs the response from the request.
	 *
	 * @param array|\WP_Error $response The response from the request.
	 * @param array           $request_args The request args.
	 * @param string          $request_url The request URL.
	 * @return void
	 */
	protected function log_response( $response, $request_args, $request_url ) {
		if ( ! $this->config['logging_enabled'] ) {
			return;
		}

		// Get the response body if its not a WP_Error.
		$response_body = ! is_wp_error( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : array();
		$response_body = $this->mask_response( $response_body );
		$code          = wp_remote_retrieve_response_code( $response );

		// Log the response.
		Logger::log(
			$this->config['slug'],
			array(
				'type'           => $this->method,
				'title'          => $this->log_title,
				'arguments'      => $this->mask_arguments( $this->arguments ),
				'request'        => $this->mask_request_args( $request_args ),
				'request_url'    => $this->get_masked_request_url( $request_url ),
				'response'       => array(
					'body' => $response_body,
					'code' => $code,
				),
				'timestamp'      => date( 'Y-m-d H:i:s' ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions -- Date is not used for display.
				'stack'          => Logger::get_stack( $this->config['extended_debugging'] ),
				'plugin_version' => $this->config['plugin_version'],
			)
		);
	}

	/**
	 * Mask sensitive fields in the request args before logging. Reading the configuration
	 * happens inside the guard, so a broken config costs the section and not the request.
	 *
	 * @param array $request_args The request args.
	 * @return array|string The masked request args, or the failure marker.
	 */
	protected function mask_request_args( $request_args ) {
		try {
			// Decode the json body that was really sent, so the rules can reach into it and
			// the log holds a readable structure rather than an escaped string.
			if ( isset( $request_args['body'] ) && is_string( $request_args['body'] ) ) {
				$decoded              = json_decode( $request_args['body'], true );
				$request_args['body'] = is_array( $decoded ) ? $decoded : $request_args['body'];
			}

			$fields = $this->request_fields_to_mask;
			return empty( $fields ) ? $request_args : $this->sanitize_field( $request_args, $fields );
		} catch ( \Throwable $e ) {
			return KeyMasker::FAILED;
		}
	}

	/**
	 * Mask sensitive fields in the response body before logging.
	 *
	 * @param array|\WP_Error $response The decoded response body.
	 * @return array|string|\WP_Error The masked response body, or the failure marker.
	 */
	protected function mask_response( $response ) {
		if ( is_wp_error( $response ) || empty( $response ) ) {
			return $response;
		}

		try {
			$fields = $this->response_fields_to_mask;
			return empty( $fields ) ? $response : $this->sanitize_field( $response, $fields );
		} catch ( \Throwable $e ) {
			return KeyMasker::FAILED;
		}
	}

	/**
	 * Mask sensitive fields in the request arguments before logging.
	 *
	 * @param array $arguments The request arguments.
	 * @return array|string The masked arguments, or the failure marker.
	 */
	protected function mask_arguments( $arguments ) {
		try {
			$fields = $this->argument_fields_to_mask;
			return empty( $fields ) ? $arguments : $this->sanitize_field( $arguments, $fields );
		} catch ( \Throwable $e ) {
			return KeyMasker::FAILED;
		}
	}

	/**
	 * Mask the request URL before logging, unchanged by default. Override it when the API
	 * addresses a resource by a token in the path, such as /authorizations/{token}/order.
	 *
	 * @param string $request_url The request URL.
	 * @return string
	 */
	protected function mask_request_url( $request_url ) {
		return $request_url;
	}

	/**
	 * Call mask_request_url() without letting an override that throws kill the API call.
	 * The fallback is a redaction, never the raw URL.
	 *
	 * @param string $request_url The request URL.
	 * @return string
	 */
	private function get_masked_request_url( $request_url ) {
		try {
			$masked = $this->mask_request_url( $request_url );
			return is_string( $masked ) ? $masked : KeyMasker::FAILED;
		} catch ( \Throwable $e ) {
			return KeyMasker::FAILED;
		}
	}

	/**
	 * Recursively mask a set of fields within a data array. A rule keeps matching below the
	 * level it was declared on, so a container nested in a list is treated like one at the root.
	 *
	 * @param array $data               The data array to sanitize.
	 * @param array $fields_to_sanitize The fields to sanitize.
	 * @return array
	 */
	protected function sanitize_field( $data, $fields_to_sanitize ) {
		$rules = self::compile_mask_rules( $fields_to_sanitize );
		return self::mask_node( $data, $rules, $rules['keep'], 0 );
	}

	/**
	 * Turn the nested configuration array into rules that can be matched by key name.
	 *
	 * @param array $fields The configured fields.
	 * @return array A rule set with the keys 'mask', 'children' and 'keep'.
	 */
	private static function compile_mask_rules( $fields ) {
		$rules = array(
			'mask'     => array(),
			'children' => array(),
			'keep'     => null,
		);

		foreach ( (array) $fields as $key => $value ) {
			// A list entry names a field to mask.
			if ( is_int( $key ) ) {
				if ( is_string( $value ) ) {
					$rules['mask'][ strtolower( $value ) ] = true;
				}
				continue;
			}

			$name = strtolower( (string) $key );

			if ( self::MASK_KEEP === $name && is_array( $value ) ) {
				$rules['keep'] = array();
				foreach ( $value as $keep ) {
					$rules['keep'][ strtolower( (string) $keep ) ] = true;
				}
				continue;
			}

			if ( is_array( $value ) ) {
				$rules['children'][ $name ] = self::compile_mask_rules( $value );
			} else {
				// A named field with anything but an array, such as 'attachment' => 'mask'.
				$rules['mask'][ $name ] = true;
			}
		}

		return $rules;
	}

	/**
	 * Walk one node of the data and mask what the rules in scope name.
	 *
	 * @param mixed      $data  The node.
	 * @param array      $scope The rules that are in scope here, which is every rule declared on this node or above it.
	 * @param array|null $keep  The allow list for this container, or null if it is not allow listed.
	 * @param int        $depth How deep we already are.
	 * @return mixed
	 */
	private static function mask_node( $data, $scope, $keep, $depth ) {
		if ( $depth > KeyMasker::MAX_DEPTH ) {
			return KeyMasker::REDACTED;
		}

		if ( ! is_array( $data ) ) {
			return $data;
		}

		foreach ( $data as $key => $value ) {
			// A list entry has no name of its own, so it is treated as the container it sits in.
			if ( is_int( $key ) ) {
				$data[ $key ] = self::mask_node( $value, $scope, $keep, $depth + 1 );
				continue;
			}

			$name = strtolower( (string) $key );

			if ( isset( $scope['mask'][ $name ] ) || ( null !== $keep && ! isset( $keep[ $name ] ) ) ) {
				$data[ $key ] = KeyMasker::placeholder( $value );
				continue;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			$child = $scope['children'][ $name ] ?? null;
			if ( null === $child ) {
				$data[ $key ] = self::mask_node( $value, $scope, null, $depth + 1 );
				continue;
			}

			// Rules stay in scope as we descend, so a kept key is still described by
			// the rules declared beside it, in whichever order.
			$next = array(
				'mask'     => $child['mask'] + $scope['mask'],
				'children' => $child['children'] + $scope['children'],
			);

			$data[ $key ] = self::mask_node( $value, $next, $child['keep'], $depth + 1 );
		}

		return $data;
	}

	/**
	 * Merge extra masking configuration into the configured fields, keeping both.
	 *
	 * @param array $base  The configured fields.
	 * @param array $extra The extra fields to merge in.
	 * @return array
	 */
	private static function merge_mask_config( $base, $extra ) {
		foreach ( (array) $extra as $key => $value ) {
			if ( is_int( $key ) ) {
				if ( ! in_array( $value, $base, true ) ) {
					$base[] = $value;
				}
			} elseif ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = self::merge_mask_config( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}

		return $base;
	}

	/**
	 * Remove sensitive data from the log.
	 *
	 * @deprecated Use mask_request_args() instead. Kept for backward compatibility.
	 * @param array $request_args The request data to sanitize.
	 * @return array The request data sanitized.
	 */
	protected function sanitize_request_args( $request_args ) {
		return $this->sanitize_field( $request_args, array( 'headers' => array( 'Authorization' ) ) );
	}

	/**
	 * Calculate the auth headers. Has to be implemented by the child class.
	 *
	 * @return string
	 */
	abstract protected function calculate_auth();

	/**
	 * Get the request args.
	 *
	 * @return array
	 */
	abstract protected function get_request_args();

	/**
	 * Gets the error message from the response. Has to be implemented by the child class.
	 *
	 * @param array $response The response from the request.
	 * @return \WP_Error
	 */
	abstract protected function get_error_message( $response );
}
