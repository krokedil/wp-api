<?php
/**
 * The default masking format.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi\Masking;

/**
 * A value that was there becomes [REDACTED], a value that was there but empty
 * becomes [MISSING]. Note that 0, '0' and false are values that were sent, and
 * are masked.
 */
final class DefaultMask implements MaskFormat {
	/**
	 * Mask a single value.
	 *
	 * @param mixed $value The original value.
	 * @return string
	 */
	public function mask( $value ) {
		if ( null === $value || '' === $value ) {
			return self::MISSING;
		}

		if ( is_array( $value ) && 0 === count( $value ) ) {
			return self::MISSING;
		}

		return self::REDACTED;
	}
}
