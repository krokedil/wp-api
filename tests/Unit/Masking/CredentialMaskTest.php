<?php

namespace Krokedil\WpApi\Tests\Unit\Masking;

use Krokedil\WpApi\Masking\CredentialMask;
use Krokedil\WpApi\Masking\MaskFormat;
use PHPUnit\Framework\TestCase;

class CredentialMaskTest extends TestCase {
	public function test_a_long_value_is_assumed_to_hold_a_credential() {
		$this->assertSame( MaskFormat::REDACTED, ( new CredentialMask() )->mask( 'Basic ' . str_repeat( 'x', 40 ) ) );
	}

	public function test_a_short_value_is_reported_as_missing() {
		// The useful case: the credential never made it into the request.
		$this->assertSame( MaskFormat::MISSING, ( new CredentialMask() )->mask( 'Basic ' ) );
	}

	public function test_a_value_of_exactly_the_threshold_is_reported_as_missing() {
		$this->assertSame( MaskFormat::MISSING, ( new CredentialMask() )->mask( str_repeat( 'x', CredentialMask::CREDENTIAL_LENGTH ) ) );
	}

	public function test_one_character_more_than_the_threshold_is_redacted() {
		$this->assertSame( MaskFormat::REDACTED, ( new CredentialMask() )->mask( str_repeat( 'x', CredentialMask::CREDENTIAL_LENGTH + 1 ) ) );
	}

	public function test_null_is_reported_as_missing() {
		$this->assertSame( MaskFormat::MISSING, ( new CredentialMask() )->mask( null ) );
	}

	public function test_a_non_string_is_redacted() {
		$this->assertSame( MaskFormat::REDACTED, ( new CredentialMask() )->mask( array( 'Authorization' => 'Basic abc' ) ) );
	}
}
