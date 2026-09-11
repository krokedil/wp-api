<?php
/**
 * Key name based masking of a finished log entry.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi;

/**
 * Masks values by key name anywhere they appear, catching the shapes no rule describes.
 * No WordPress or WooCommerce function may be called from this class.
 */
class KeyMasker {

	/**
	 * Placeholder for a value that was present.
	 */
	public const REDACTED = '[REDACTED]';

	/**
	 * Placeholder for a value that was there, but empty.
	 */
	public const MISSING = '[MISSING]';

	/**
	 * Placeholder for a section that could not be masked.
	 */
	public const FAILED = '[MASKING FAILED]';

	/**
	 * How many levels to walk before masking whatever is left unreached.
	 */
	public const MAX_DEPTH = 12;

	/**
	 * Key name fragments masked out of the box, matched case insensitively as
	 * substrings, so 'secret' also covers 'shared_secret'.
	 *
	 * @var string[]
	 */
	private static $default_keys = array(
		'password',
		'passwd',
		'secret',
		'authorization',
		'api_key',
		'apikey',
		'token',
		'credential',
		'private_key',
		'signature',
		'cookie',
		'csrf',
		'cvv',
		'cvc',
		'card_number',
		'iban',
	);

	/**
	 * Key name fragments added by the consuming plugin.
	 *
	 * @var string[]
	 */
	private static $extra_keys = array();

	/**
	 * Widen the list of masked key names, for the names specific to a provider.
	 *
	 * @param string[] $keys Key name fragments, matched case insensitively as substrings.
	 * @return void
	 */
	public static function add_keys( $keys ) {
		foreach ( (array) $keys as $key ) {
			$key = strtolower( trim( (string) $key ) );
			if ( '' !== $key && ! in_array( $key, self::$extra_keys, true ) ) {
				self::$extra_keys[] = $key;
			}
		}
	}

	/**
	 * Drop every key name added with add_keys().
	 *
	 * @return void
	 */
	public static function reset_keys() {
		self::$extra_keys = array();
	}

	/**
	 * Get the key name fragments that are masked.
	 *
	 * @return string[]
	 */
	public static function get_keys() {
		return array_merge( self::$default_keys, self::$extra_keys );
	}

	/**
	 * Mask a value, or anything nested inside it, by key name.
	 *
	 * @param mixed $data The data to mask. Anything may be passed.
	 * @return mixed
	 */
	public static function mask( $data ) {
		return self::mask_node( $data, 0 );
	}

	/**
	 * The placeholder for a value, telling a value that was sent from an empty one.
	 *
	 * @param mixed $value The value being replaced.
	 * @return string
	 */
	public static function placeholder( $value ) {
		if ( null === $value || '' === $value || array() === $value ) {
			return self::MISSING;
		}

		return self::REDACTED;
	}

	/**
	 * Whether a value has already been replaced by a placeholder.
	 *
	 * @param mixed $value The value to check.
	 * @return bool
	 */
	public static function is_placeholder( $value ) {
		return in_array( $value, array( self::REDACTED, self::MISSING, self::FAILED ), true );
	}

	/**
	 * Walk one node of the data.
	 *
	 * @param mixed $data The node.
	 * @param int   $depth How deep we already are.
	 * @return mixed
	 */
	private static function mask_node( $data, $depth ) {
		if ( $depth > self::MAX_DEPTH ) {
			return self::REDACTED;
		}

		// Name an object and stop, since we cannot know what it holds.
		if ( is_object( $data ) ) {
			return '[object ' . get_class( $data ) . ']';
		}

		if ( is_string( $data ) ) {
			return self::mask_string( $data );
		}

		if ( ! is_array( $data ) ) {
			return $data;
		}

		foreach ( $data as $key => $value ) {
			if ( ! is_int( $key ) && self::is_sensitive_key( (string) $key ) ) {
				$data[ $key ] = self::is_placeholder( $value ) ? $value : self::placeholder( $value );
				continue;
			}

			$data[ $key ] = self::mask_node( $value, $depth + 1 );
		}

		return $data;
	}

	/**
	 * Whether a key name looks sensitive.
	 *
	 * @param string $key The key name.
	 * @return bool
	 */
	private static function is_sensitive_key( $key ) {
		$key = strtolower( $key );
		foreach ( self::get_keys() as $needle ) {
			if ( false !== strpos( $key, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Mask a string by the shape of its value. The shape checks are a mitigation, not
	 * coverage: see Logger::get_caller_string(). Nothing here shortens a log entry.
	 *
	 * @param string $value The string.
	 * @return string
	 */
	private static function mask_string( $value ) {
		if ( self::is_placeholder( $value ) ) {
			return $value;
		}

		$patterns = array(
			// An Authorization header value, with the scheme left readable.
			'/^(Basic|Bearer)\s+\S+$/i'   => '$1 ' . self::REDACTED,
			// A JWT, which is what most hosted checkout tokens look like.
			'/\bey[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}(?:\.[A-Za-z0-9_-]+)?/' => self::REDACTED,
			// A long unbroken base64 run, which no readable field ever contains.
			'/^[A-Za-z0-9+\/]{40,}={0,2}$/' => self::REDACTED,
		);

		foreach ( $patterns as $pattern => $replacement ) {
			$masked = preg_replace( $pattern, $replacement, $value );
			$value  = null === $masked ? self::REDACTED : $masked;
		}

		return $value;
	}
}
