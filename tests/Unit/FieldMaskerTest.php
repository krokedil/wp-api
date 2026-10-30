<?php
/**
 * Tests for the configured masking pass.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi\Tests;

use Krokedil\WpApi\FieldMasker;
use Krokedil\WpApi\KeyMasker;
use PHPUnit\Framework\TestCase;

class FieldMaskerTest extends TestCase {

	/** A value held by reference is masked in the copy only. */
	public function test_does_not_write_through_a_reference() {
		$password = 'hunter2';
		$card     = array(
			'number' => '4111111111111111',
			'holder' => 'Jane Doe',
		);
		$items    = array( array( 'number' => '4111111111111111' ) );
		$data     = array(
			'password' => &$password,
			'card'     => &$card,
			'items'    => &$items,
		);

		$masked = FieldMasker::mask(
			$data,
			array(
				'password',
				'card'  => array( 'number' ),
				'items' => array( 'number' ),
			)
		);

		$this->assertSame( KeyMasker::REDACTED, $masked['password'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['card']['number'] );
		$this->assertSame( 'Jane Doe', $masked['card']['holder'] );
		$this->assertSame( KeyMasker::REDACTED, $masked['items'][0]['number'] );
		$this->assertSame( 'hunter2', $password );
		$this->assertSame( '4111111111111111', $card['number'] );
		$this->assertSame( '4111111111111111', $items[0]['number'] );
	}
}
