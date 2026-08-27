<?php

namespace Krokedil\WpApi\Tests\Unit\Masking;

use Krokedil\WpApi\Masking\SensitiveValueScrubber;
use PHPUnit\Framework\TestCase;
use stdClass;

class SensitiveValueScrubberTest extends TestCase {
	/**
	 * @var SensitiveValueScrubber
	 */
	private $scrubber;

	protected function setUp(): void {
		parent::setUp();
		$this->scrubber = new SensitiveValueScrubber();
	}

	/**
	 * @dataProvider sensitive_keys
	 * @param string $key The key name.
	 */
	public function test_it_masks_a_sensitive_key( $key ) {
		$scrubbed = $this->scrubber->scrub( array( $key => 'super-secret' ) );

		$this->assertSame( SensitiveValueScrubber::MASK, $scrubbed[ $key ] );
	}

	public function sensitive_keys() {
		return array(
			'password'      => array( 'password' ),
			'shared_secret' => array( 'shared_secret' ),
			'upper case'    => array( 'API_KEY' ),
			'mixed case'    => array( 'Authorization' ),
			'access token'  => array( 'access_token' ),
		);
	}

	public function test_it_leaves_the_client_token_alone() {
		// Support reads the log for this one, and it is not a credential.
		$scrubbed = $this->scrubber->scrub( array( 'client_token' => 'eyJhbGciOi' ) );

		$this->assertSame( 'eyJhbGciOi', $scrubbed['client_token'] );
	}

	public function test_extra_key_patterns_can_be_added() {
		$scrubber = new SensitiveValueScrubber( array( 'client_token' ) );

		$scrubbed = $scrubber->scrub( array( 'client_token' => 'eyJhbGciOi' ) );

		$this->assertSame( SensitiveValueScrubber::MASK, $scrubbed['client_token'] );
	}

	public function test_it_masks_an_auth_header_value_whatever_it_is_called() {
		$scrubbed = $this->scrubber->scrub( array( 'some_arg' => 'Basic dXNlcjpwYXNz' ) );

		$this->assertSame( SensitiveValueScrubber::MASK, $scrubbed['some_arg'] );
	}

	public function test_it_masks_a_bearer_token_value() {
		$scrubbed = $this->scrubber->scrub( array( 0 => 'Bearer eyJhbGciOiJIUzI1NiJ9' ) );

		$this->assertSame( SensitiveValueScrubber::MASK, $scrubbed[0] );
	}

	public function test_it_masks_deep_inside_an_array() {
		$scrubbed = $this->scrubber->scrub(
			array(
				'settings' => array(
					'testmode'      => 'yes',
					'shared_secret' => 'super-secret',
				),
			)
		);

		$this->assertSame( 'yes', $scrubbed['settings']['testmode'] );
		$this->assertSame( SensitiveValueScrubber::MASK, $scrubbed['settings']['shared_secret'] );
	}

	public function test_an_object_is_named_and_never_expanded() {
		$object           = new stdClass();
		$object->password = 'super-secret';

		$scrubbed = $this->scrubber->scrub( $object );

		$this->assertSame( stdClass::class . '#' . spl_object_id( $object ), $scrubbed );
	}

	public function test_a_circular_graph_terminates() {
		$object        = new stdClass();
		$object->self  = $object;
		$data          = array( 'object' => $object );

		$scrubbed = $this->scrubber->scrub( $data );

		$this->assertSame( stdClass::class . '#' . spl_object_id( $object ), $scrubbed['object'] );
	}

	public function test_it_stops_at_the_depth_guard() {
		$scrubber = new SensitiveValueScrubber( array(), 2 );

		$scrubbed = $scrubber->scrub( array( 'a' => array( 'b' => array( 'c' => 'super-secret' ) ) ) );

		$this->assertStringNotContainsString( 'super-secret', json_encode( $scrubbed ) );
	}

	public function test_a_long_string_is_truncated() {
		$scrubbed = $this->scrubber->scrub( array( 'body' => str_repeat( 'a', SensitiveValueScrubber::MAX_STRING + 100 ) ) );

		$this->assertSame(
			str_repeat( 'a', SensitiveValueScrubber::MAX_STRING ) . '...(truncated)',
			$scrubbed['body']
		);
	}

	public function test_scalars_are_left_alone() {
		$data = array(
			'code'    => 200,
			'enabled' => true,
			'ratio'   => 1.5,
			'nothing' => null,
		);

		$this->assertSame( $data, $this->scrubber->scrub( $data ) );
	}
}
