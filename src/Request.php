<?php
/**
 * Base request class file for the package. This file is used to define the base class for the package that will be extended by all other classes.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi;

use Krokedil\WpApi\Logger;
use Krokedil\WpApi\Masking\CredentialMask;
use Krokedil\WpApi\Masking\Masker;
use Krokedil\WpApi\Masking\MaskRules;

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
	 *
	 * Dot separated paths into the request args, where '*' matches every key at that
	 * level. The legacy nested array format is still accepted.
	 *
	 * Example:
	 * array(
	 *     'headers.Authorization',
	 *     'body.billing_address.email',
	 *     'body.order_lines.*.reference',
	 * )
	 *
	 * @var array
	 */
	protected $request_fields_to_mask = array(
		'headers.Authorization',
	);

	/**
	 * Fields to mask in the response body before logging.
	 *
	 * Same format as $request_fields_to_mask, but relative to the decoded response body.
	 *
	 * Example:
	 * array(
	 *     'client_token',
	 *     'billing_address.email',
	 * )
	 *
	 * @var array
	 */
	protected $response_fields_to_mask = array();

	/**
	 * Fields to mask in the request arguments before logging.
	 *
	 * @var array
	 */
	protected $argument_fields_to_mask = array(
		'username',
		'password',
		'api_password',
	);

	/**
	 * Extra fields to mask, as passed to the constructor.
	 *
	 * @var array
	 */
	protected $masked_field_overrides = array();

	/**
	 * The masker used for logging.
	 *
	 * @var Masker|null
	 */
	protected $masker = null;

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

		// The rules are built when the request is logged, so that a child class can still
		// change the fields to mask after calling this constructor.
		$this->masked_field_overrides = is_array( $masked_fields ) ? $masked_fields : array();
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

		// Parse the request body into an array if its json format, so the rules can reach into it.
		$request_args['body'] = $this->decode_request_body( $request_args['body'] ?? null );
		$request_args         = $this->mask_request_args( $request_args );

		$arguments = $this->mask_arguments( $this->arguments );

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
	 * Decode a JSON request body. A body that is not JSON is returned untouched.
	 *
	 * @param mixed $request_body The raw request body.
	 * @return mixed
	 */
	protected function decode_request_body( $request_body ) {
		if ( ! is_string( $request_body ) || '' === $request_body ) {
			return $request_body;
		}

		$decoded = json_decode( $request_body, true );

		return ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) ? $decoded : $request_body;
	}

	/**
	 * Mask sensitive fields in the request args before logging.
	 *
	 * @param array $request_args The request args.
	 * @return array
	 */
	protected function mask_request_args( $request_args ) {
		return $this->mask( $request_args, $this->get_request_mask_rules() );
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

		return $this->mask( $response, $this->get_response_mask_rules() );
	}

	/**
	 * Mask sensitive fields in the request arguments before logging.
	 *
	 * @param array $arguments The request arguments.
	 * @return array
	 */
	protected function mask_arguments( $arguments ) {
		if ( empty( $arguments ) ) {
			return $arguments;
		}

		return $this->mask( $arguments, $this->get_argument_mask_rules() );
	}

	/**
	 * Mask one section of the log payload. Fails closed, so a failure costs us that
	 * one section rather than leaving it unmasked in the log.
	 *
	 * @param mixed     $data The data to mask.
	 * @param MaskRules $rules The rules to apply.
	 * @return mixed
	 */
	protected function mask( $data, MaskRules $rules ) {
		try {
			return $this->get_masker()->mask( $data, $rules );
		} catch ( \Throwable $e ) {
			return array( 'masking_error' => $e->getMessage() );
		}
	}

	/**
	 * The mask rules for the request args.
	 *
	 * @return MaskRules
	 */
	protected function get_request_mask_rules() {
		return MaskRules::from_segments( array( 'headers', 'Authorization' ), new CredentialMask() )
			->merge( MaskRules::from_config( $this->request_fields_to_mask ) )
			->merge( MaskRules::from_config( $this->masked_field_overrides['request'] ?? array() ) );
	}

	/**
	 * The mask rules for the response body.
	 *
	 * @return MaskRules
	 */
	protected function get_response_mask_rules() {
		return MaskRules::from_config( $this->response_fields_to_mask )
			->merge( MaskRules::from_config( $this->masked_field_overrides['response'] ?? array() ) );
	}

	/**
	 * The mask rules for the request arguments.
	 *
	 * @return MaskRules
	 */
	protected function get_argument_mask_rules() {
		return MaskRules::from_config( $this->argument_fields_to_mask )
			->merge( MaskRules::from_config( $this->masked_field_overrides['arguments'] ?? array() ) );
	}

	/**
	 * The masker used for logging. Override to change the mask format.
	 *
	 * @return Masker
	 */
	protected function get_masker() {
		if ( null === $this->masker ) {
			$this->masker = new Masker();
		}

		return $this->masker;
	}

	/**
	 * Remove sensitive data from the log.
	 *
	 * @deprecated Use mask_request_args() instead. Kept for backward compatibility.
	 * @param array $request_args The request data to sanitize.
	 * @return array The request data sanitized.
	 */
	protected function sanitize_request_args( $request_args ) {
		// The Authorization header only, with the behaviour this method has always had.
		return $this->mask( $request_args, MaskRules::from_segments( array( 'headers', 'Authorization' ), new CredentialMask() ) );
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
