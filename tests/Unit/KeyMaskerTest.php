<?php
/**
 * Tests for the key name pass.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi\Tests;

use Krokedil\WpApi\KeyMasker;
use PHPUnit\Framework\TestCase;

class KeyMaskerTest extends TestCase {

	protected function tearDown(): void {
		KeyMasker::reset_keys();
		parent::tearDown();
	}

	/** Item 7: a sensitive key name is masked wherever it appears. */
	public function test_masks_a_sensitive_key_at_any_depth() {
		$masked = KeyMasker::mask(
			array(
				'password' => 'hunter2',
				'nested'   => array(
					'list' => array(
						array( 'api_key' => 'abc' ),
					),
				),
				'keep_me'  => 'visible',
			)
		);

		$this->assertSame( KeyMasker::REDACTED, $masked['password'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['nested']['list'][0]['api_key'] );
		$this->assertSame( 'visible', $masked['keep_me'] );
	}

	/** Item 7: the match is a case insensitive substring, so shared_secret is caught by secret. */
	public function test_matches_key_names_as_case_insensitive_substrings() {
		$masked = KeyMasker::mask(
			array(
				'shared_secret' => 'abc',
				'AUTHORIZATION' => 'Basic abc',
				'access_token'  => 'abc',
			)
		);

		$this->assertSame( KeyMasker::REDACTED, $masked['shared_secret'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['AUTHORIZATION'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['access_token'] );
	}

	/** Item 7: an object is named, never expanded. */
	public function test_never_expands_an_object() {
		$object         = new \stdClass();
		$object->secret = 'hunter2';

		$masked = KeyMasker::mask( array( 'payload' => $object ) );

		$this->assertSame( '[object stdClass]', $masked['payload'] );
		$this->assertStringNotContainsString( 'hunter2', wp_json_encode( $masked ) );
	}

	/** Nothing is shortened. Request logs are long, and that is what they are for. */
	public function test_a_long_string_is_left_whole() {
		// Spaces on purpose: an unbroken run that long is masked as a credential instead.
		$long = str_repeat( 'a note. ', 5000 );

		$this->assertSame( $long, KeyMasker::mask( array( 'note' => $long ) )['note'] );
	}

	/** Item 7: whatever the depth guard did not reach is masked, not returned. */
	public function test_masks_what_the_depth_guard_did_not_reach() {
		$deep = 'the deepest value';
		for ( $i = 0; $i < KeyMasker::MAX_DEPTH + 3; $i++ ) {
			$deep = array( 'level' => $deep );
		}

		$masked = KeyMasker::mask( $deep );

		$this->assertStringNotContainsString( 'the deepest value', wp_json_encode( $masked ) );
		$this->assertStringContainsString( KeyMasker::REDACTED, wp_json_encode( $masked ) );
	}

	/** Item 7: a consuming plugin can widen the key list with its own provider names. */
	public function test_a_consumer_can_widen_the_key_list() {
		$data = array(
			'given_name' => 'Ada',
			'city'       => 'Stockholm',
		);

		$this->assertSame( 'Ada', KeyMasker::mask( $data )['given_name'] );

		KeyMasker::add_keys( array( 'given_name' ) );

		$masked = KeyMasker::mask( $data );
		$this->assertSame( KeyMasker::REDACTED, $masked['given_name'] );
		$this->assertSame( 'Stockholm', $masked['city'] );
	}

	/** Item 5 and 7: a value the configured pass already masked keeps its placeholder. */
	public function test_keeps_a_placeholder_the_configured_pass_already_set() {
		$masked = KeyMasker::mask(
			array(
				'password' => KeyMasker::MISSING,
				'token'    => KeyMasker::REDACTED,
				'note'     => KeyMasker::MISSING,
			)
		);

		$this->assertSame( KeyMasker::MISSING, $masked['password'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['token'] );
		$this->assertSame( KeyMasker::MISSING, $masked['note'] );
	}

	/** Item 5: the two placeholders tell a value that was sent from one that was empty. */
	public function test_the_placeholder_tells_present_from_empty() {
		$this->assertSame( KeyMasker::REDACTED, KeyMasker::placeholder( 'value' ) );
		$this->assertSame( KeyMasker::REDACTED, KeyMasker::placeholder( 0 ) );
		$this->assertSame( KeyMasker::REDACTED, KeyMasker::placeholder( false ) );
		$this->assertSame( KeyMasker::MISSING, KeyMasker::placeholder( '' ) );
		$this->assertSame( KeyMasker::MISSING, KeyMasker::placeholder( null ) );
		$this->assertSame( KeyMasker::MISSING, KeyMasker::placeholder( array() ) );
	}

	/** Item 8: the value shape checks, which are all a positional argument can be given. */
	public function test_masks_values_that_are_shaped_like_credentials() {
		$masked = KeyMasker::mask(
			array(
				'a' => 'Basic dGVzdDp0ZXN0dGVzdHRlc3R0ZXN0',
				'b' => 'Bearer abcdefghijklmnop',
				'c' => 'token is eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.abc',
				'd' => str_repeat( 'QWxhZGRpbjpvcGVu', 4 ),
				'e' => 'a perfectly ordinary sentence about a Basic problem',
			)
		);

		$this->assertSame( 'Basic ' . KeyMasker::REDACTED, $masked['a'] );
		$this->assertSame( 'Bearer ' . KeyMasker::REDACTED, $masked['b'] );
		$this->assertSame( 'token is ' . KeyMasker::REDACTED, $masked['c'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['d'] );
		$this->assertSame( 'a perfectly ordinary sentence about a Basic problem', $masked['e'] );
	}
}
