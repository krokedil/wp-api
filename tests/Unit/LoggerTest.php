<?php
/**
 * Tests for the log entry masking in Logger.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi\Tests;

use Krokedil\WpApi\KeyMasker;
use Krokedil\WpApi\Logger;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Logger::$log = new \WC_Logger();
	}

	protected function tearDown(): void {
		Logger::$log = null;
		KeyMasker::reset_keys();
		parent::tearDown();
	}

	/** Item 7: the pass runs over the finished entry, not just the parts a rule described. */
	public function test_the_finished_entry_goes_through_the_key_name_pass() {
		Logger::log(
			'test',
			array(
				'title'    => 'A request',
				'response' => array(
					'body' => array(
						'error_message' => 'failed for shop 1',
						'shared_secret' => 'hunter2',
					),
				),
			)
		);

		$entry = json_decode( Logger::$log->entries[0]['message'], true );

		$this->assertSame( 'A request', $entry['title'] );
		$this->assertSame( 'failed for shop 1', $entry['response']['body']['error_message'] );
		$this->assertSame( KeyMasker::REDACTED, $entry['response']['body']['shared_secret'] );
	}

	/** Item 2: a failure in the pass costs the entry rather than logging it unmasked. */
	public function test_the_log_entry_fails_closed() {
		ThrowingLogger::log( 'test', array( 'password' => 'hunter2' ) );

		$this->assertSame( array( 'error' => KeyMasker::FAILED ), json_decode( Logger::$log->entries[0]['message'], true ) );
		$this->assertStringNotContainsString( 'hunter2', Logger::$log->entries[0]['message'] );
	}

	/** Item 8: stack trace arguments are masked before they are encoded. */
	public function test_stack_trace_arguments_are_masked() {
		$frames = $this->own_frames(
			array( 'password' => 'hunter2' ),
			'Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0'
		);

		$this->assertStringNotContainsString( 'hunter2', $frames );
		$this->assertStringNotContainsString( 'eyJhbGciOiJIUzI1NiJ9', $frames );
		$this->assertStringContainsString( KeyMasker::REDACTED, $frames );
	}

	/** Item 8: the documented limitation, a bare positional token still survives. */
	public function test_a_bare_positional_argument_is_the_known_limitation() {
		$this->assertStringContainsString( 'plainsecretvalue', $this->own_frames( 'plainsecretvalue' ) );
	}

	/**
	 * Get the encoded stack frames that carry the given arguments.
	 *
	 * @param mixed ...$args The arguments to pass down the stack.
	 * @return string
	 */
	private function own_frames( ...$args ) {
		$frames = array_filter(
			self::recurse( 8, ...$args ),
			function ( $frame ) {
				return false !== strpos( $frame['function'], 'recurse' );
			}
		);

		return wp_json_encode( array_values( $frames ) );
	}

	private static function recurse( $remaining, ...$args ) {
		return $remaining > 0 ? self::recurse( $remaining - 1, ...$args ) : Logger::get_stack( true );
	}
}
