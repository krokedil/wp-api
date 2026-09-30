<?php
/**
 * Tests for the filter that turns the log masking off.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi\Tests;

use Krokedil\WpApi\KeyMasker;
use Krokedil\WpApi\Logger;
use Krokedil\WpApi\Masking;
use PHPUnit\Framework\TestCase;

class MaskingFilterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Logger::$log = new \WC_Logger();
	}

	protected function tearDown(): void {
		KeyMasker::reset_keys();
		Logger::$log = null;
		unset( $GLOBALS['wp_api_test_response'], $GLOBALS['wp_api_test_filters'] );
		parent::tearDown();
	}

	/** Masking is on when nothing hooks the filter. */
	public function test_masking_is_on_by_default() {
		$this->assertTrue( Masking::is_enabled( 'test' ) );

		$message = $this->log_one_request();
		$this->assertStringNotContainsString( 'hunter2', $message );
		$this->assertStringNotContainsString( 'dGVzdDp0ZXN0', $message );
	}

	/** Returning false logs the request, the response and the arguments in the clear. */
	public function test_the_filter_turns_masking_off() {
		$this->set_filter(
			function () {
				return false;
			}
		);

		$entry = json_decode( $this->log_one_request(), true );

		$this->assertSame( 'hunter2', $entry['arguments']['password'] );
		$this->assertSame( 'Basic dGVzdDp0ZXN0', $entry['request']['headers']['Authorization'] );
		$this->assertSame( 'hunter2', $entry['request']['body']['password'], 'The body is still decoded.' );
		$this->assertSame( 'abc123', $entry['response']['body']['client_token'] );
	}

	/** The filter gets the slug, so a single plugin can be targeted. */
	public function test_the_filter_can_target_one_slug() {
		$this->set_filter(
			function ( $enabled, $slug ) {
				return 'other_plugin' === $slug ? false : $enabled;
			}
		);

		$this->assertFalse( Masking::is_enabled( 'other_plugin' ) );
		$this->assertStringNotContainsString( 'hunter2', $this->log_one_request() );
	}

	/** Only a strict false turns it off, anything else leaves it on. */
	public function test_only_a_strict_false_turns_masking_off() {
		foreach ( array( 0, '', null, 'no', array() ) as $value ) {
			$this->set_filter(
				function () use ( $value ) {
					return $value;
				}
			);

			$this->assertTrue( Masking::is_enabled( 'test' ) );
		}
	}

	/** A filter that throws leaves masking on. */
	public function test_a_filter_that_throws_leaves_masking_on() {
		$this->set_filter(
			function () {
				throw new \RuntimeException( 'filter exploded' );
			}
		);

		$this->assertTrue( Masking::is_enabled( 'test' ) );
	}

	/** Logging straight through the Logger honours the filter too. */
	public function test_the_logger_honours_the_filter() {
		$this->set_filter(
			function () {
				return false;
			}
		);

		Logger::log( 'test', array( 'password' => 'hunter2' ) );

		$this->assertSame( array( 'password' => 'hunter2' ), json_decode( Logger::$log->entries[0]['message'], true ) );
	}

	/** The helpers for masking outside a Request mask by default. */
	public function test_the_helpers_mask_by_default() {
		$data = array(
			'password' => 'hunter2',
			'email'    => 'ada@example.test',
		);

		$this->assertSame( KeyMasker::REDACTED, Masking::mask_fields( $data, array( 'email' ), 'test' )['email'] );
		$this->assertSame( KeyMasker::REDACTED, Masking::mask_keys( $data, 'test' )['password'] );
	}

	/** The helpers for masking outside a Request honour the filter. */
	public function test_the_helpers_honour_the_filter() {
		$this->set_filter(
			function () {
				return false;
			}
		);

		$data = array( 'password' => 'hunter2' );

		$this->assertSame( $data, Masking::mask_fields( $data, array( 'password' ), 'test' ) );
		$this->assertSame( $data, Masking::mask_keys( $data, 'test' ) );
	}

	/** Stack trace arguments are left alone when masking is off. */
	public function test_the_stack_is_not_masked_when_asked() {
		$this->assertStringContainsString( 'hunter2', wp_json_encode( self::recurse( 8, array( 'password' => 'hunter2' ) ) ) );
	}

	private static function recurse( $remaining, ...$args ) {
		return $remaining > 0 ? self::recurse( $remaining - 1, ...$args ) : Logger::get_stack( true, false );
	}

	private function set_filter( $callback ) {
		$GLOBALS['wp_api_test_filters']['krokedil_wp_api_mask_log_data'] = $callback;
	}

	/**
	 * Run a request with credentials everywhere and return the raw log message.
	 *
	 * @return string
	 */
	private function log_one_request() {
		$GLOBALS['wp_api_test_response'] = array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => '{"client_token":"abc123"}',
			'response' => array( 'code' => 200 ),
		);

		$request               = new TestRequest(
			array( 'base_url' => 'https://example.test' ),
			array(),
			array( 'password' => 'hunter2' ),
			array( 'response' => array( 'client_token' ) )
		);
		$request->request_args = array(
			'method'  => 'POST',
			'headers' => array( 'Authorization' => 'Basic dGVzdDp0ZXN0' ),
			'body'    => '{"password":"hunter2"}',
		);
		$request->request();

		return Logger::$log->entries[0]['message'];
	}
}
