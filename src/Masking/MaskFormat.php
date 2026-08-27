<?php
/**
 * Interface for the masking formats.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi\Masking;

/**
 * Decides what a masked value looks like in the log.
 */
interface MaskFormat {
	/**
	 * The placeholder for a value that was present.
	 *
	 * @var string
	 */
	const REDACTED = '[REDACTED]';

	/**
	 * The placeholder for a value that was there, but empty.
	 *
	 * @var string
	 */
	const MISSING = '[MISSING]';

	/**
	 * Mask a single value.
	 *
	 * @param mixed $value The original value.
	 * @return mixed The masked value.
	 */
	public function mask( $value );
}
