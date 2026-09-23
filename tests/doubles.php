<?php
/**
 * Concrete Request classes used by the tests.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi\Tests;

use Krokedil\WpApi\Request;

class TestRequest extends Request {

	public $request_args = array();

	public function set_request_fields( $fields ) {
		$this->request_fields_to_mask = $fields;
		return $this;
	}

	public function set_response_fields( $fields ) {
		$this->response_fields_to_mask = $fields;
		return $this;
	}

	public function set_argument_fields( $fields ) {
		$this->argument_fields_to_mask = $fields;
		return $this;
	}

	public function mask_request( $request_args ) {
		return $this->mask_request_args( $request_args );
	}

	public function mask_the_response( $response ) {
		return $this->mask_response( $response );
	}

	public function mask_the_arguments( $arguments ) {
		return $this->mask_arguments( $arguments );
	}

	public function sanitize( $data, $fields ) {
		return $this->sanitize_field( $data, $fields );
	}

	public function deprecated_sanitize( $request_args ) {
		return $this->sanitize_request_args( $request_args );
	}

	protected function calculate_auth() {
		return 'Basic dGVzdDp0ZXN0';
	}

	protected function get_request_args() {
		return $this->request_args;
	}

	protected function get_error_message( $response ) {
		return new \WP_Error();
	}
}

/**
 * A request whose masking engine blows up, to prove every entry point fails closed.
 */
class ThrowingRequest extends TestRequest {

	protected function sanitize_field( $data, $fields_to_sanitize ) {
		throw new \RuntimeException( 'masking exploded' );
	}
}

/**
 * A request whose configuration cannot be read, to prove the read happens inside the guard.
 * Unsetting a declared property sends every later read through __get().
 */
class UnreadableConfigRequest extends TestRequest {

	public function __construct( $config = array(), $settings = array(), $arguments = array(), $masked_fields = array() ) {
		parent::__construct( $config, $settings, $arguments, $masked_fields );
		unset( $this->request_fields_to_mask, $this->response_fields_to_mask, $this->argument_fields_to_mask );
	}

	public function __get( $name ) {
		throw new \RuntimeException( 'the configuration exploded' );
	}
}

/**
 * A request that masks a token out of the URL path.
 */
class UrlMaskingRequest extends TestRequest {

	protected function mask_request_url( $request_url ) {
		return preg_replace( '#/authorizations/[^/]+#', '/authorizations/[REDACTED]', $request_url );
	}
}

/**
 * A request whose URL masking throws, which must not take down the API call.
 */
class ThrowingUrlRequest extends TestRequest {

	protected function mask_request_url( $request_url ) {
		throw new \RuntimeException( 'url masking exploded' );
	}
}

/**
 * A logger whose masking pass blows up, to prove the log entry fails closed.
 */
class ThrowingLogger extends \Krokedil\WpApi\Logger {

	protected static function mask_log_data( $log_data ) {
		throw new \RuntimeException( 'masking exploded' );
	}
}
