<?php

namespace Krokedil\WpApi\Tests\Unit\Masking;

use InvalidArgumentException;
use Krokedil\WpApi\Masking\CredentialMask;
use Krokedil\WpApi\Masking\DefaultMask;
use Krokedil\WpApi\Masking\MaskRules;
use PHPUnit\Framework\TestCase;

class MaskRulesTest extends TestCase {
	public function test_dot_paths_are_split_into_segments() {
		$rules = MaskRules::from_config( array( 'headers.Authorization', 'body.billing_address.email' ) );

		$this->assertSame( array( 'headers.Authorization', 'body.billing_address.email' ), $rules->to_paths() );
	}

	public function test_the_legacy_nested_format_is_still_accepted() {
		$rules = MaskRules::from_config( array( 'headers' => array( 'Authorization' ) ) );

		$this->assertSame( array( 'headers.Authorization' ), $rules->to_paths() );
	}

	public function test_the_legacy_response_format_is_still_accepted() {
		$rules = MaskRules::from_config(
			array(
				'client_token',
				'billing_address' => array( 'email', 'phone' ),
			)
		);

		$this->assertSame(
			array( 'client_token', 'billing_address.email', 'billing_address.phone' ),
			$rules->to_paths()
		);
	}

	public function test_dot_paths_and_the_nested_format_normalize_identically() {
		$paths  = MaskRules::from_config( array( 'body.billing_address.email' ) );
		$nested = MaskRules::from_config( array( 'body' => array( 'billing_address' => array( 'email' ) ) ) );

		$this->assertSame( $paths->to_paths(), $nested->to_paths() );
	}

	public function test_the_two_formats_can_be_mixed_in_one_config() {
		$rules = MaskRules::from_config(
			array(
				'body.order_lines.*.reference',
				'headers' => array( 'Authorization' ),
			)
		);

		$this->assertSame( array( 'body.order_lines.*.reference', 'headers.Authorization' ), $rules->to_paths() );
	}

	public function test_merge_keeps_the_rules_of_both_sets() {
		// The regression that matters: a caller adding a header used to replace the
		// built in Authorization rule instead of adding to it.
		$rules = MaskRules::from_config( array( 'headers.Authorization' ) )
			->merge( MaskRules::from_config( array( 'headers.X-Api-Key' ) ) );

		$this->assertSame( array( 'headers.Authorization', 'headers.X-Api-Key' ), $rules->to_paths() );
	}

	public function test_merge_dedupes_identical_paths() {
		$rules = MaskRules::from_config( array( 'headers.Authorization' ) )
			->merge( MaskRules::from_config( array( 'headers.Authorization' ) ) );

		$this->assertSame( array( 'headers.Authorization' ), $rules->to_paths() );
	}

	public function test_merge_does_not_change_the_receiver() {
		$rules = MaskRules::from_config( array( 'headers.Authorization' ) );
		$rules->merge( MaskRules::from_config( array( 'headers.X-Api-Key' ) ) );

		$this->assertSame( array( 'headers.Authorization' ), $rules->to_paths() );
	}

	public function test_a_later_rule_without_a_format_keeps_the_earlier_one() {
		// The regression that matters: the built in CredentialMask on the Authorization
		// header used to be replaced by the plain default rule declared for the same path.
		$rules = MaskRules::from_segments( array( 'headers', 'Authorization' ), new CredentialMask() )
			->merge( MaskRules::from_config( array( 'headers.Authorization' ) ) );

		$all = $rules->all();
		$this->assertInstanceOf( CredentialMask::class, $all['headers.Authorization']['format'] );
	}

	public function test_the_later_format_wins_for_a_duplicated_path() {
		$rules = MaskRules::from_config( array( 'headers.Authorization' ), new DefaultMask() )
			->merge( MaskRules::from_config( array( 'headers.Authorization' ), new CredentialMask() ) );

		$all = $rules->all();
		$this->assertInstanceOf( CredentialMask::class, $all['headers.Authorization']['format'] );
	}

	public function test_with_does_not_change_the_receiver() {
		$rules = MaskRules::none();
		$rules->with( array( 'headers', 'Authorization' ) );

		$this->assertFalse( $rules->has_rules() );
	}

	public function test_from_segments_keeps_a_key_containing_a_dot() {
		$rules = MaskRules::from_segments( array( 'headers', 'X-Some.Dotted-Header' ) );
		$all   = $rules->all();

		$this->assertSame( array( 'headers', 'X-Some.Dotted-Header' ), $all['headers.X-Some.Dotted-Header']['segments'] );
	}

	public function test_mask_rules_are_passed_through_unchanged() {
		$rules = MaskRules::from_config( array( 'headers.Authorization' ) );

		$this->assertSame( $rules, MaskRules::from_config( $rules ) );
	}

	/**
	 * @dataProvider empty_configs
	 * @param mixed $config The config to test.
	 */
	public function test_an_empty_config_yields_no_rules( $config ) {
		$this->assertFalse( MaskRules::from_config( $config )->has_rules() );
	}

	public function empty_configs() {
		return array(
			'null'         => array( null ),
			'empty string' => array( '' ),
			'empty array'  => array( array() ),
			'a scalar'     => array( 5 ),
		);
	}

	public function test_a_path_that_is_too_long_is_rejected() {
		$this->expectException( InvalidArgumentException::class );

		MaskRules::from_config( array( implode( '.', array_fill( 0, MaskRules::MAX_SEGMENTS + 1, 'a' ) ) ) );
	}

	public function test_an_empty_segment_is_rejected() {
		$this->expectException( InvalidArgumentException::class );

		MaskRules::from_config( array( 'headers..Authorization' ) );
	}

	public function test_an_empty_path_is_rejected() {
		$this->expectException( InvalidArgumentException::class );

		MaskRules::from_config( array( '  ' ) );
	}
}
