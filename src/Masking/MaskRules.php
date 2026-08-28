<?php
/**
 * Value object holding the fields to mask.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi\Masking;

use InvalidArgumentException;

/**
 * An immutable set of mask rules. A rule is a path into the data, held as a list
 * of segments and keyed by its dot joined form.
 *
 * Rules are declared as dot paths:
 *
 *     array(
 *         'headers.Authorization',
 *         'body.billing_address.email',
 *         'body.order_lines.*.reference',
 *     )
 *
 * The nested array format is still accepted for backwards compatibility:
 *
 *     array(
 *         'headers' => array( 'Authorization' ),
 *         'body'    => array( 'billing_address' => array( 'email' ) ),
 *     )
 */
final class MaskRules {
	/**
	 * The wildcard segment, matching every key at that level.
	 *
	 * @var string
	 */
	const WILDCARD = '*';

	/**
	 * The longest path a single rule may have.
	 *
	 * @var int
	 */
	const MAX_SEGMENTS = 32;

	/**
	 * The rules, keyed by their canonical dot path. Each entry is
	 * array( 'segments' => string[], 'format' => MaskFormat|null ).
	 *
	 * @var array<string, array{segments: string[], format: MaskFormat|null}>
	 */
	private $rules;

	/**
	 * Constructor. Private, use the named constructors.
	 *
	 * @param array<string, array{segments: string[], format: MaskFormat|null}> $rules The rules, keyed by canonical path.
	 */
	private function __construct( array $rules = array() ) {
		$this->rules = $rules;
	}

	/**
	 * An empty set of rules.
	 *
	 * @return self
	 */
	public static function none() {
		return new self();
	}

	/**
	 * Build rules from a MaskRules instance, a list of dot paths, the legacy nested
	 * array format, or a mix of the two.
	 *
	 * @param mixed           $config The rule config.
	 * @param MaskFormat|null $format The format to use for these rules, or null for the masker default.
	 * @return self
	 * @throws InvalidArgumentException If a path is empty or too long.
	 */
	public static function from_config( $config, ?MaskFormat $format = null ) {
		if ( $config instanceof self ) {
			return $config;
		}

		if ( ! is_array( $config ) || empty( $config ) ) {
			return self::none();
		}

		$rules = self::none();
		foreach ( self::parse( $config, array() ) as $segments ) {
			$rules = $rules->with( $segments, $format );
		}

		return $rules;
	}

	/**
	 * Build rules from a list of dot paths.
	 *
	 * @param string[]        $paths The dot paths.
	 * @param MaskFormat|null $format The format to use for these rules.
	 * @return self
	 * @throws InvalidArgumentException If a path is empty or too long.
	 */
	public static function from_paths( array $paths, ?MaskFormat $format = null ) {
		$rules = self::none();
		foreach ( $paths as $path ) {
			$rules = $rules->with( self::split( (string) $path ), $format );
		}

		return $rules;
	}

	/**
	 * Build a single rule from ready made segments. Use this for keys that contain a
	 * literal dot, since the dot path format has no escape syntax.
	 *
	 * @param string[]        $segments The path segments.
	 * @param MaskFormat|null $format The format to use for this rule.
	 * @return self
	 * @throws InvalidArgumentException If the path is empty or too long.
	 */
	public static function from_segments( array $segments, ?MaskFormat $format = null ) {
		return self::none()->with( $segments, $format );
	}

	/**
	 * Add a rule, returning a new instance.
	 *
	 * @param string[]        $segments The path segments.
	 * @param MaskFormat|null $format The format to use for this rule.
	 * @return self
	 * @throws InvalidArgumentException If the path is empty or too long.
	 */
	public function with( array $segments, ?MaskFormat $format = null ) {
		$segments = self::validate( $segments );
		$rules    = $this->rules;

		$rules[ implode( '.', $segments ) ] = array(
			'segments' => $segments,
			'format'   => $format,
		);

		return new self( $rules );
	}

	/**
	 * Merge another set of rules into this one, returning a new instance. The union
	 * of both sets, so a caller supplied rule adds to the built in ones.
	 *
	 * A duplicated path keeps the later format, but only when the later rule brings
	 * one. A rule declared without a format asks for the masker default, which must
	 * not silently replace a format that was picked on purpose, such as the built in
	 * CredentialMask on the Authorization header.
	 *
	 * @param mixed $other Another MaskRules, or anything from_config() accepts.
	 * @return self
	 * @throws InvalidArgumentException If a path is empty or too long.
	 */
	public function merge( $other ) {
		$other = self::from_config( $other );
		$rules = $this->rules;

		foreach ( $other->rules as $path => $rule ) {
			if ( null === $rule['format'] && isset( $rules[ $path ]['format'] ) ) {
				$rule['format'] = $rules[ $path ]['format'];
			}

			$rules[ $path ] = $rule;
		}

		return new self( $rules );
	}

	/**
	 * Whether there is anything to mask.
	 *
	 * @return bool
	 */
	public function has_rules() {
		return ! empty( $this->rules );
	}

	/**
	 * All rules, keyed by canonical path.
	 *
	 * @return array<string, array{format: MaskFormat|null, segments: string[]}>
	 */
	public function all() {
		return $this->rules;
	}

	/**
	 * The canonical dot paths.
	 *
	 * @return string[]
	 */
	public function to_paths() {
		return array_keys( $this->rules );
	}

	/**
	 * Walk a rule config into a list of segment paths. A numerically keyed string is
	 * a dot path, a key with an array value is a container.
	 *
	 * @param array<mixed> $config The config to walk.
	 * @param string[]     $prefix The segments collected so far.
	 * @return array<string[]> A list of segment arrays.
	 */
	private static function parse( array $config, array $prefix ) {
		$paths = array();

		foreach ( $config as $key => $value ) {
			if ( is_array( $value ) ) {
				// A numeric key is a list index, not a path segment.
				$nested = is_int( $key ) ? $prefix : array_merge( $prefix, self::split( (string) $key ) );
				$paths  = array_merge( $paths, self::parse( $value, $nested ) );
				continue;
			}

			if ( is_int( $key ) ) {
				$paths[] = array_merge( $prefix, self::split( (string) $value ) );
				continue;
			}

			// An associative key with a string value: both are segments.
			$paths[] = array_merge( $prefix, self::split( (string) $key ), self::split( (string) $value ) );
		}

		return $paths;
	}

	/**
	 * Split a dot path into segments.
	 *
	 * @param string $path The dot path.
	 * @return string[]
	 */
	private static function split( $path ) {
		return explode( '.', trim( $path ) );
	}

	/**
	 * Validate a set of segments.
	 *
	 * @param string[] $segments The segments to validate.
	 * @return string[] The trimmed segments.
	 * @throws InvalidArgumentException If the path is empty, holds an empty segment, or is too long.
	 */
	private static function validate( array $segments ) {
		$segments = array_values(
			array_map(
				function ( $segment ) {
					return trim( (string) $segment );
				},
				$segments
			)
		);

		if ( empty( $segments ) ) {
			throw new InvalidArgumentException( 'A mask rule needs at least one path segment.' );
		}

		if ( count( $segments ) > self::MAX_SEGMENTS ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An exception message, not output.
			throw new InvalidArgumentException( sprintf( 'A mask rule may hold at most %d path segments.', self::MAX_SEGMENTS ) );
		}

		foreach ( $segments as $segment ) {
			if ( '' === $segment ) {
				throw new InvalidArgumentException( 'A mask rule may not hold an empty path segment.' );
			}
		}

		return $segments;
	}
}
