<?php
/**
 * Heuristic scrubber for values of an unknown shape.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi\Masking;

/**
 * Scrubs values that cannot be described by a path rule, such as the arguments in
 * the stack trace. Masks by key name and by value shape, and never expands an
 * object, only names it.
 */
final class SensitiveValueScrubber {
	/**
	 * The placeholder for anything masked.
	 *
	 * @var string
	 */
	const MASK = MaskFormat::REDACTED;

	/**
	 * How deep to walk before giving up and masking the rest. Deep enough to clear a
	 * real payload, since this also runs over the whole log entry.
	 *
	 * @var int
	 */
	const MAX_DEPTH = 16;

	/**
	 * Strings longer than this are truncated.
	 *
	 * @var int
	 */
	const MAX_STRING = 512;

	/**
	 * Key name fragments that mark a value as sensitive. Matched case insensitively
	 * as a substring, so 'shared_secret' is caught by 'secret'. A bare 'token' is
	 * left out on purpose, since it would mask the client token everywhere.
	 *
	 * @var string[]
	 */
	private static $default_key_patterns = array(
		'password',
		'passwd',
		'secret',
		'authorization',
		'api_key',
		'apikey',
		'access_token',
		'refresh_token',
		'authtoken',
		'credential',
		'private_key',
	);

	/**
	 * The key name fragments to treat as sensitive.
	 *
	 * @var string[]
	 */
	private $key_patterns;

	/**
	 * How deep to walk before giving up and masking the rest.
	 *
	 * @var int
	 */
	private $max_depth;

	/**
	 * Constructor.
	 *
	 * @param string[] $key_patterns Optional. Extra key name fragments to treat as sensitive.
	 * @param int      $max_depth Optional. How deep to walk before masking the rest.
	 */
	public function __construct( array $key_patterns = array(), $max_depth = self::MAX_DEPTH ) {
		$this->key_patterns = array_merge( self::$default_key_patterns, array_map( 'strtolower', $key_patterns ) );
		$this->max_depth    = (int) $max_depth;
	}

	/**
	 * Scrub a value into something that is safe to write to the log.
	 *
	 * @param mixed $value The value to scrub.
	 * @return mixed
	 */
	public function scrub( $value ) {
		return $this->walk( $value, null, 0 );
	}

	/**
	 * Walk a value, masking as it goes.
	 *
	 * @param mixed           $value The value.
	 * @param string|int|null $key The key the value was found under, if any.
	 * @param int             $depth The current depth.
	 * @return mixed
	 */
	private function walk( $value, $key, $depth ) {
		// Fail closed, rather than return a value we did not get to inspect.
		if ( $depth > $this->max_depth ) {
			return self::MASK;
		}

		if ( null !== $key && $this->is_sensitive_key( $key ) ) {
			return self::MASK;
		}

		if ( is_object( $value ) ) {
			return get_class( $value ) . '#' . spl_object_id( $value );
		}

		if ( is_array( $value ) ) {
			$scrubbed = array();
			foreach ( $value as $child_key => $child ) {
				$scrubbed[ $child_key ] = $this->walk( $child, $child_key, $depth + 1 );
			}

			return $scrubbed;
		}

		if ( is_string( $value ) ) {
			return $this->scrub_string( $value );
		}

		return $value;
	}

	/**
	 * Mask a string that looks like a credential, and truncate a long one.
	 *
	 * @param string $value The string.
	 * @return string
	 */
	private function scrub_string( $value ) {
		if ( preg_match( '/^\s*(Basic|Bearer)\s+\S+/i', $value ) ) {
			return self::MASK;
		}

		if ( strlen( $value ) > self::MAX_STRING ) {
			return substr( $value, 0, self::MAX_STRING ) . '...(truncated)';
		}

		return $value;
	}

	/**
	 * Whether a key name marks its value as sensitive.
	 *
	 * @param string|int $key The key.
	 * @return bool
	 */
	private function is_sensitive_key( $key ) {
		if ( ! is_string( $key ) ) {
			return false;
		}

		$key = strtolower( $key );
		foreach ( $this->key_patterns as $pattern ) {
			if ( '' !== $pattern && false !== strpos( $key, $pattern ) ) {
				return true;
			}
		}

		return false;
	}
}
