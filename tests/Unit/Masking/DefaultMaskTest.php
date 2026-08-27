<?php

namespace Krokedil\WpApi\Tests\Unit\Masking;

use Krokedil\WpApi\Masking\DefaultMask;
use Krokedil\WpApi\Masking\MaskFormat;
use PHPUnit\Framework\TestCase;

class DefaultMaskTest extends TestCase {
	/**
	 * @dataProvider missing_values
	 * @param mixed $value The value to mask.
	 */
	public function test_a_value_that_was_empty_is_reported_as_missing( $value ) {
		$this->assertSame( MaskFormat::MISSING, ( new DefaultMask() )->mask( $value ) );
	}

	public function missing_values() {
		return array(
			'null'         => array( null ),
			'empty string' => array( '' ),
			'empty array'  => array( array() ),
		);
	}

	/**
	 * @dataProvider present_values
	 * @param mixed $value The value to mask.
	 */
	public function test_a_value_that_was_there_is_redacted( $value ) {
		$this->assertSame( MaskFormat::REDACTED, ( new DefaultMask() )->mask( $value ) );
	}

	public function present_values() {
		return array(
			'a string'      => array( 'abc' ),
			// Falsy, but sent. The masking this replaced leaked all three of these.
			'zero'          => array( 0 ),
			'string zero'   => array( '0' ),
			'false'         => array( false ),
			'a float'       => array( 1.5 ),
			'a filled array' => array( array( 'a' => 'b' ) ),
		);
	}
}
