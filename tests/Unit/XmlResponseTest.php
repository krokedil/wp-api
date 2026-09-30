<?php
/**
 * Tests for the content type aware response parsing and the log levels, with the masking on.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi\Tests;

use Krokedil\WpApi\KeyMasker;
use Krokedil\WpApi\Logger;
use PHPUnit\Framework\TestCase;

class XmlResponseTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Logger::$log = new \WC_Logger();
	}

	protected function tearDown(): void {
		KeyMasker::reset_keys();
		Logger::$log = null;
		unset( $GLOBALS['wp_api_test_response'] );
		parent::tearDown();
	}

	/** An XML response is parsed into an array, and the response rules reach into it. */
	public function test_an_xml_response_is_parsed_and_masked() {
		$request = $this->run_request(
			'<?xml version="1.0"?><result><status>ok</status><shipment_id>123</shipment_id><access_code>abc123</access_code></result>',
			'application/xml; charset=utf-8',
			array( 'response' => array( 'access_code' ) )
		);

		$this->assertSame( '123', $request['result']['shipment_id'] );
		$this->assertSame( 'abc123', $request['result']['access_code'], 'The caller gets the unmasked body.' );
		$this->assertSame( '123', $request['entry']['response']['body']['shipment_id'] );
		$this->assertSame( KeyMasker::REDACTED, $request['entry']['response']['body']['access_code'] );
		$this->assertStringNotContainsString( 'abc123', $request['message'] );
	}

	/** The key name pass reaches into a parsed XML response too. */
	public function test_an_xml_response_goes_through_the_key_name_pass() {
		$request = $this->run_request(
			'<result><status>ok</status><api_key>hunter2</api_key></result>',
			'text/xml'
		);

		$this->assertSame( KeyMasker::REDACTED, $request['entry']['response']['body']['api_key'] );
		$this->assertStringNotContainsString( 'hunter2', $request['message'] );
	}

	/** A body that cannot be parsed is not logged as the raw string. */
	public function test_an_unparsable_xml_response_is_not_logged_raw() {
		$request = $this->run_request( '<result><api_key>hunter2</api_key>', 'application/xml' );

		$this->assertInstanceOf( \WP_Error::class, $request['result'] );
		$this->assertStringNotContainsString( 'hunter2', $request['message'] );
	}

	/** A json body without a content type is still decoded, so the response rules apply. */
	public function test_an_unlabelled_json_response_is_decoded_and_masked() {
		$request = $this->run_request( '{"order_id":"123","client_token":"abc123"}', '', array( 'response' => array( 'client_token' ) ) );

		$this->assertSame( KeyMasker::REDACTED, $request['entry']['response']['body']['client_token'] );
		$this->assertStringNotContainsString( 'abc123', $request['message'] );
	}

	/** A 200 with an error status in the body is logged at error level. */
	public function test_an_error_status_in_the_body_is_logged_as_error() {
		$request = $this->run_request( '<result><status>error</status><error_message>Invalid</error_message></result>', 'text/xml' );

		$this->assertSame( 'error', Logger::$log->entries[0]['level'] );
		$this->assertSame( 'error', $request['entry']['log_level'] );
	}

	/** A 200 with a warning status in the body is logged at warning level. */
	public function test_a_warning_status_in_the_body_is_logged_as_warning() {
		$this->run_request( '{"status":"warning"}', 'application/json' );

		$this->assertSame( 'warning', Logger::$log->entries[0]['level'] );
	}

	/** A non 2xx response is logged at error level. */
	public function test_a_failed_response_is_logged_as_error() {
		$this->run_request( '{"status":"ok"}', 'application/json', array(), 500 );

		$this->assertSame( 'error', Logger::$log->entries[0]['level'] );
	}

	/** The level survives a masking pass that fails, only the content is replaced. */
	public function test_the_level_survives_a_failed_masking_pass() {
		ThrowingLogger::log(
			'test',
			array(
				'log_level' => 'error',
				'password'  => 'hunter2',
			)
		);

		$this->assertSame( 'error', Logger::$log->entries[0]['level'] );
		$this->assertSame( array( 'error' => KeyMasker::FAILED ), json_decode( Logger::$log->entries[0]['message'], true ) );
	}

	/** An unknown level falls back to info rather than reaching the WooCommerce logger. */
	public function test_an_unknown_level_falls_back_to_info() {
		Logger::log( 'test', array( 'log_level' => 'loud' ) );

		$this->assertSame( 'info', Logger::$log->entries[0]['level'] );
		$this->assertSame( 'test', Logger::$log->entries[0]['handle'] );
	}

	/**
	 * Run a request through the double and return the result and the log entry.
	 *
	 * @param string $body          The response body.
	 * @param string $content_type  The response content type.
	 * @param array  $masked_fields The masked fields to pass to the constructor.
	 * @param int    $code          The response code.
	 * @return array
	 */
	private function run_request( $body, $content_type, $masked_fields = array(), $code = 200 ) {
		$GLOBALS['wp_api_test_response'] = array(
			'headers'  => array( 'content-type' => $content_type ),
			'body'     => $body,
			'response' => array( 'code' => $code ),
		);

		$request               = new TestRequest( array( 'base_url' => 'https://example.test' ), array(), array(), $masked_fields );
		$request->request_args = array( 'method' => 'GET' );
		$result                = $request->request();
		$message               = Logger::$log->entries[0]['message'];

		return array(
			'result'  => $result,
			'message' => $message,
			'entry'   => json_decode( $message, true ),
		);
	}
}
