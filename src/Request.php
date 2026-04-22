<?php
/**
 * Base request class file for the package. This file is used to define the base class for the package that will be extended by all other classes.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi;

use Krokedil\WpApi\Logger;

/**
 * Base request class for the package.
 */
abstract class Request {
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
	 * Fields to mask in the request args before logging.
	 * The structure mirrors the request array. Keys map to either a list of field names (string values)
	 * or nested arrays for recursive masking.
	 *
	 * Example:
	 * array(
	 *     'headers' => array( 'Authorization' ),
	 *     'body'    => array(
	 *         'billing_address' => array( 'email', 'phone' ),
	 *     ),
	 * )
	 *
	 * @var array
	 */
	protected $request_fields_to_mask = array(
		'headers' => array( 'Authorization' ),
	);

	/**
	 * Fields to mask in the response body before logging.
	 * Top-level string values are masked directly. Array values trigger recursive masking of the named sub-key.
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
	 * Constructor.
	 *
	 * @param array $config       Configuration array.
	 * @param array $settings     Plugin settings.
	 * @param array $arguments    Request arguments.
	 * @param array $masked_fields Optional extra fields to mask, with keys 'request' and/or 'response'.
	 */
	public function __construct( $config = array(), $settings = array(), $arguments = array(), $masked_fields = array() ) {
		$this->config    = wp_parse_args( $config, $this->defaults );
		$this->settings  = $settings;
		$this->arguments = $arguments;

		if ( ! empty( $masked_fields['request'] ) ) {
			$this->request_fields_to_mask = array_merge( $this->request_fields_to_mask, $masked_fields['request'] );
		}
		if ( ! empty( $masked_fields['response'] ) ) {
			$this->response_fields_to_mask = array_merge( $this->response_fields_to_mask, $masked_fields['response'] );
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

		// Parse the Request body into an array if its json format.
		$request_body         = $request_args['body'] ?? '';
		$decoded_body         = json_decode( $request_body );
		$request_args['body'] = $decoded_body ?? $request_args['body'] ?? null;

		$request_args = $this->mask_request_args( $request_args );

		$arguments = $this->arguments;
		if ( isset( $arguments['username'] ) ) {
			$arguments['username'] = '[REDACTED]';
		}
		if ( isset( $arguments['password'] ) ) {
			$arguments['password'] = '[REDACTED]';
		}

		// Log the response.
		Logger::log(
			$this->config['slug'],
			array(
				'type'           => $this->method,
				'title'          => $this->log_title,
				'arguments'      => $arguments,
				'request'        => $request_args,
				'request_url'    => $request_url,
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
	 * Mask sensitive fields in the request args before logging.
	 *
	 * @param array $request_args The request args.
	 * @return array
	 */
	protected function mask_request_args( $request_args ) {
		if ( empty( $this->request_fields_to_mask ) ) {
			return $request_args;
		}
		try {
			return $this->sanitize_field( $request_args, $this->request_fields_to_mask );
		} catch ( \Throwable $e ) {
			return $request_args;
		}
	}

	/**
	 * Mask sensitive fields in the response body before logging.
	 *
	 * @param array|\WP_Error $response The decoded response body.
	 * @return array|\WP_Error
	 */
	protected function mask_response( $response ) {
		if ( is_wp_error( $response ) || empty( $response ) ) {
			return $response;
		}
		if ( empty( $this->response_fields_to_mask ) ) {
			return $response;
		}
		try {
			return $this->sanitize_field( $response, $this->response_fields_to_mask );
		} catch ( \Throwable $e ) {
			return $response;
		}
	}

	/**
	 * Recursively mask a set of fields within a data array.
	 * Array values in $fields_to_sanitize trigger recursive descent into the matching key.
	 * String values name a field to replace with '*****' (empty values are left as-is).
	 *
	 * @param array $data               The data array to sanitize.
	 * @param array $fields_to_sanitize The fields to sanitize.
	 * @return array
	 */
	protected function sanitize_field( $data, $fields_to_sanitize ) {
		foreach ( $fields_to_sanitize as $key => $value ) {
			if ( is_array( $value ) && isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$data[ $key ] = $this->sanitize_field( $data[ $key ], $value );
			} elseif ( is_string( $value ) && isset( $data[ $value ] ) ) {
				$data[ $value ] = empty( $data[ $value ] ) ? $data[ $value ] : '*****';
			}
		}
		return $data;
	}

	/**
	 * Remove sensitive data from the log.
	 *
	 * @deprecated Use mask_request_args() instead. Kept for backward compatibility.
	 * @param array $request_args The request data to sanitize.
	 * @return array The request data sanitized.
	 */
	protected function sanitize_request_args( $request_args ) {
		// Do not log the authorization token.
		foreach ( $request_args['headers'] as $header => $value ) {
			if ( 'authorization' === strtolower( $header ) ) {
				// If it is longer than 15 char., it most likely has a token. This is an assumption that is safe even if it is wrong.
				$request_args['headers'][ $header ] = strlen( $value ) > 15 ? '[REDACTED]' : '[MISSING]';
				break;
			}
		}

		return $request_args;
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
