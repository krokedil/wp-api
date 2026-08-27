<?php

namespace Krokedil\WpApi\Tests\Unit\Masking;

use Krokedil\WpApi\Masking\CredentialMask;
use Krokedil\WpApi\Masking\MaskFormat;
use Krokedil\WpApi\Masking\Masker;
use Krokedil\WpApi\Masking\MaskRules;
use PHPUnit\Framework\TestCase;
use stdClass;

class MaskerTest extends TestCase {
	/**
	 * @var Masker
	 */
	private $masker;

	protected function setUp(): void {
		parent::setUp();
		$this->masker = new Masker();
	}

	private function mask( $data, array $paths, array $options = array() ) {
		$masker = empty( $options ) ? $this->masker : new Masker( null, $options );

		return $masker->mask( $data, MaskRules::from_config( $paths ) );
	}

	public function test_it_masks_a_nested_path() {
		$data = array(
			'headers' => array(
				'Authorization' => 'Basic dXNlcjpwYXNz',
				'Content-Type'  => 'application/json',
			),
		);

		$masked = $this->mask( $data, array( 'headers.Authorization' ) );

		$this->assertSame( MaskFormat::REDACTED, $masked['headers']['Authorization'] );
		$this->assertSame( 'application/json', $masked['headers']['Content-Type'] );
	}

	public function test_it_does_not_touch_the_input() {
		$data = array( 'headers' => array( 'Authorization' => 'Basic dXNlcjpwYXNz' ) );

		$this->mask( $data, array( 'headers.Authorization' ) );

		$this->assertSame( 'Basic dXNlcjpwYXNz', $data['headers']['Authorization'] );
	}

	public function test_a_missing_path_changes_nothing_and_creates_no_key() {
		$data = array( 'headers' => array( 'Content-Type' => 'application/json' ) );

		$this->assertSame( $data, $this->mask( $data, array( 'headers.Authorization', 'body.email' ) ) );
	}

	public function test_an_empty_ruleset_returns_the_input() {
		$data = array( 'headers' => array( 'Authorization' => 'secret' ) );

		$this->assertSame( $data, $this->masker->mask( $data, MaskRules::none() ) );
	}

	public function test_keys_are_matched_case_insensitively() {
		$data = array( 'headers' => array( 'authorization' => 'Basic dXNlcjpwYXNz' ) );

		$masked = $this->mask( $data, array( 'headers.Authorization' ) );

		$this->assertSame( MaskFormat::REDACTED, $masked['headers']['authorization'] );
	}

	public function test_an_exact_match_wins_over_a_case_insensitive_one() {
		$data = array(
			'token' => 'lower',
			'Token' => 'upper',
		);

		$masked = $this->mask( $data, array( 'token' ) );

		$this->assertSame( MaskFormat::REDACTED, $masked['token'] );
		$this->assertSame( 'upper', $masked['Token'] );
	}

	public function test_case_insensitive_matching_can_be_turned_off() {
		$data = array( 'headers' => array( 'authorization' => 'Basic dXNlcjpwYXNz' ) );

		$masked = $this->mask( $data, array( 'headers.Authorization' ), array( 'case_insensitive_keys' => false ) );

		$this->assertSame( $data, $masked );
	}

	public function test_it_masks_inside_a_decoded_body() {
		$data = array(
			'body' => array(
				'billing_address' => array(
					'email' => 'customer@example.com',
					'city'  => 'Ystad',
				),
			),
		);

		$masked = $this->mask( $data, array( 'body.billing_address.email' ) );

		$this->assertSame( MaskFormat::REDACTED, $masked['body']['billing_address']['email'] );
		$this->assertSame( 'Ystad', $masked['body']['billing_address']['city'] );
	}

	public function test_it_masks_inside_a_json_encoded_body_and_keeps_it_a_string() {
		$data = array( 'body' => json_encode( array( 'billing_address' => array( 'email' => 'customer@example.com' ) ) ) );

		$masked = $this->mask( $data, array( 'body.billing_address.email' ) );

		$this->assertIsString( $masked['body'] );
		$this->assertStringNotContainsString( 'customer@example.com', $masked['body'] );
		$this->assertSame(
			MaskFormat::REDACTED,
			json_decode( $masked['body'], true )['billing_address']['email']
		);
	}

	public function test_it_masks_inside_json_nested_in_a_header_value() {
		$data = array(
			'headers' => array(
				'X-Klarna-Integration-Metadata' => json_encode( array( 'integrator' => array( 'session_reference' => 'sess-123' ) ) ),
			),
		);

		$masked = $this->mask( $data, array( 'headers.X-Klarna-Integration-Metadata.integrator.session_reference' ) );

		$this->assertStringNotContainsString( 'sess-123', $masked['headers']['X-Klarna-Integration-Metadata'] );
	}

	public function test_a_body_that_is_not_json_is_left_alone() {
		$data = array( 'body' => 'not json at all' );

		$this->assertSame( $data, $this->mask( $data, array( 'body.email' ) ) );
	}

	public function test_json_descent_can_be_turned_off() {
		$data = array( 'body' => json_encode( array( 'email' => 'customer@example.com' ) ) );

		$masked = $this->mask( $data, array( 'body.email' ), array( 'decode_json_strings' => false ) );

		$this->assertSame( $data, $masked );
	}

	public function test_it_masks_an_object_and_keeps_it_an_object() {
		$data = json_decode( '{"billing_address":{"email":"customer@example.com"}}' );

		$masked = $this->mask( $data, array( 'billing_address.email' ) );

		$this->assertInstanceOf( stdClass::class, $masked );
		$this->assertInstanceOf( stdClass::class, $masked->billing_address );
		$this->assertSame( MaskFormat::REDACTED, $masked->billing_address->email );
	}

	public function test_it_does_not_mutate_the_object_it_was_given() {
		$data = json_decode( '{"billing_address":{"email":"customer@example.com"}}' );

		$this->mask( $data, array( 'billing_address.email' ) );

		$this->assertSame( 'customer@example.com', $data->billing_address->email );
	}

	public function test_a_wildcard_masks_a_field_in_every_list_item() {
		$data = array(
			'body' => array(
				'order_lines' => array(
					array(
						'reference' => 'sku-1',
						'name'      => 'One',
					),
					array(
						'reference' => 'sku-2',
						'name'      => 'Two',
					),
				),
			),
		);

		$masked = $this->mask( $data, array( 'body.order_lines.*.reference' ) );

		$this->assertSame( MaskFormat::REDACTED, $masked['body']['order_lines'][0]['reference'] );
		$this->assertSame( MaskFormat::REDACTED, $masked['body']['order_lines'][1]['reference'] );
		$this->assertSame( 'Two', $masked['body']['order_lines'][1]['name'] );
	}

	public function test_a_wildcard_works_over_an_associative_map() {
		$data = array(
			'tokens' => array(
				'one' => array( 'value' => 'a' ),
				'two' => array( 'value' => 'b' ),
			),
		);

		$masked = $this->mask( $data, array( 'tokens.*.value' ) );

		$this->assertSame( MaskFormat::REDACTED, $masked['tokens']['one']['value'] );
		$this->assertSame( MaskFormat::REDACTED, $masked['tokens']['two']['value'] );
	}

	public function test_a_trailing_wildcard_masks_every_value_at_that_level() {
		$data = array( 'tokens' => array( 'a', 'b' ) );

		$masked = $this->mask( $data, array( 'tokens.*' ) );

		$this->assertSame( array( 'tokens' => array( MaskFormat::REDACTED, MaskFormat::REDACTED ) ), $masked );
	}

	public function test_a_wildcard_against_a_scalar_is_a_no_op() {
		$data = array( 'tokens' => 42 );

		$this->assertSame( $data, $this->mask( $data, array( 'tokens.*.value' ) ) );
	}

	public function test_a_numeric_segment_reaches_a_list_index() {
		$data = array( 'order_lines' => array( array( 'reference' => 'sku-1' ) ) );

		$masked = $this->mask( $data, array( 'order_lines.0.reference' ) );

		$this->assertSame( MaskFormat::REDACTED, $masked['order_lines'][0]['reference'] );
	}

	public function test_masking_a_whole_branch_collapses_it() {
		$data = array( 'body' => array( 'billing_address' => array( 'email' => 'customer@example.com' ) ) );

		$masked = $this->mask( $data, array( 'body.billing_address' ) );

		$this->assertSame( MaskFormat::REDACTED, $masked['body']['billing_address'] );
	}

	public function test_it_fails_closed_when_the_data_is_deeper_than_the_guard() {
		$data = array(
			'a' => json_encode( array( 'b' => json_encode( array( 'c' => 'super-secret' ) ) ) ),
		);

		$masked = $this->mask( $data, array( 'a.b.c' ), array( 'max_depth' => 2 ) );

		$this->assertStringNotContainsString( 'super-secret', json_encode( $masked ) );
	}

	public function test_a_self_referencing_object_terminates() {
		$data           = new stdClass();
		$data->child    = $data;
		$data->password = 'super-secret';

		$masked = $this->mask( $data, array( 'child.child.child.password' ) );

		$this->assertInstanceOf( stdClass::class, $masked );
	}

	/**
	 * @dataProvider scalar_inputs
	 * @param mixed $data The top level value.
	 */
	public function test_a_scalar_top_level_value_is_returned_unchanged( $data ) {
		$this->assertSame( $data, $this->mask( $data, array( 'headers.Authorization' ) ) );
	}

	public function scalar_inputs() {
		return array(
			'null'   => array( null ),
			'int'    => array( 5 ),
			'bool'   => array( false ),
			'string' => array( 'plain' ),
		);
	}

	public function test_a_per_rule_format_overrides_the_default_in_the_same_pass() {
		$rules = MaskRules::from_segments( array( 'headers', 'Authorization' ), new CredentialMask() )
			->merge( MaskRules::from_config( array( 'body.email' ) ) );

		$data = array(
			'headers' => array( 'Authorization' => 'Basic' ),
			'body'    => array( 'email' => 'customer@example.com' ),
		);

		$masked = $this->masker->mask( $data, $rules );

		$this->assertSame( MaskFormat::MISSING, $masked['headers']['Authorization'] );
		$this->assertSame( MaskFormat::REDACTED, $masked['body']['email'] );
	}

	public function test_a_realistic_request_is_masked_end_to_end() {
		$data = array(
			'headers' => array(
				'Authorization' => 'Basic ' . str_repeat( 'x', 40 ),
				'Content-Type'  => 'application/json',
			),
			'method'  => 'POST',
			'body'    => array(
				'purchase_currency' => 'SEK',
				'billing_address'   => array(
					'email'   => 'customer@example.com',
					'phone'   => '070000000',
					'country' => 'SE',
				),
				'order_lines'       => array(
					array(
						'reference'   => 'sku-1',
						'name'        => 'One',
						'total_amount' => 1000,
					),
				),
			),
		);

		$masked = $this->mask(
			$data,
			array(
				'headers.Authorization',
				'body.billing_address.email',
				'body.billing_address.phone',
				'body.order_lines.*.reference',
			)
		);

		$this->assertSame(
			array(
				'headers' => array(
					'Authorization' => MaskFormat::REDACTED,
					'Content-Type'  => 'application/json',
				),
				'method'  => 'POST',
				'body'    => array(
					'purchase_currency' => 'SEK',
					'billing_address'   => array(
						'email'   => MaskFormat::REDACTED,
						'phone'   => MaskFormat::REDACTED,
						'country' => 'SE',
					),
					'order_lines'       => array(
						array(
							'reference'   => MaskFormat::REDACTED,
							'name'        => 'One',
							'total_amount' => 1000,
						),
					),
				),
			),
			$masked
		);
	}
}
