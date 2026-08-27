<?php
/**
 * Masking format for credentials.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi\Masking;

/**
 * Masks a credential, while still telling you whether it looked like one. A value
 * long enough to hold a token becomes [REDACTED], a shorter one becomes [MISSING],
 * which means the credential never made it into the request.
 */
final class CredentialMask implements MaskFormat {
	/**
	 * The length above which a value is assumed to hold a credential.
	 *
	 * @var int
	 */
	const CREDENTIAL_LENGTH = 15;

	/**
	 * Mask a single value.
	 *
	 * @param mixed $value The original value.
	 * @return string
	 */
	public function mask( $value ) {
		if ( null === $value ) {
			return self::MISSING;
		}

		if ( ! is_string( $value ) ) {
			return self::REDACTED;
		}

		return strlen( $value ) > self::CREDENTIAL_LENGTH ? self::REDACTED : self::MISSING;
	}
}
