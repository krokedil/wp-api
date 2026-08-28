<?php
/**
 * The masking engine.
 *
 * @package Krokedil/WpApi
 */

namespace Krokedil\WpApi\Masking;

/**
 * Masks the fields named by a set of rules, in arrays, objects and JSON strings.
 *
 * For every rule the masker walks that rule's segments into the data, rather than
 * traversing the whole payload. The input is never mutated.
 */
final class Masker {
	/**
	 * The default recursion depth guard.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_DEPTH = 32;

	/**
	 * The format used for rules that do not bring their own.
	 *
	 * @var MaskFormat
	 */
	private $default_format;

	/**
	 * Whether a path segment may match a key with different casing.
	 *
	 * @var bool
	 */
	private $case_insensitive_keys;

	/**
	 * Whether to descend into JSON encoded strings.
	 *
	 * @var bool
	 */
	private $decode_json_strings;

	/**
	 * The recursion depth guard.
	 *
	 * @var int
	 */
	private $max_depth;

	/**
	 * Constructor.
	 *
	 * @param MaskFormat|null     $default_format The format for rules without one. Defaults to DefaultMask.
	 * @param array<string,mixed> $options Optional. 'case_insensitive_keys' (true), 'decode_json_strings' (true), 'max_depth' (32).
	 */
	public function __construct( ?MaskFormat $default_format = null, array $options = array() ) {
		$this->default_format        = null === $default_format ? new DefaultMask() : $default_format;
		$this->case_insensitive_keys = isset( $options['case_insensitive_keys'] ) ? (bool) $options['case_insensitive_keys'] : true;
		$this->decode_json_strings   = isset( $options['decode_json_strings'] ) ? (bool) $options['decode_json_strings'] : true;
		$this->max_depth             = isset( $options['max_depth'] ) ? (int) $options['max_depth'] : self::DEFAULT_MAX_DEPTH;
	}

	/**
	 * Mask every rule in the set against the data.
	 *
	 * @param mixed     $data The data to mask. Array, object, JSON string or scalar.
	 * @param MaskRules $rules The rules to apply.
	 * @return mixed The masked data, in the same shape and type as the input.
	 */
	public function mask( $data, MaskRules $rules ) {
		if ( ! $rules->has_rules() ) {
			return $data;
		}

		foreach ( $rules->all() as $rule ) {
			$format = $rule['format'] instanceof MaskFormat ? $rule['format'] : $this->default_format;
			$data   = $this->apply( $data, $rule['segments'], 0, $format, 0 );
		}

		return $data;
	}

	/**
	 * Resolve one path segment against the node, and mask once the path runs out.
	 *
	 * @param mixed      $node The current node.
	 * @param string[]   $segments The full path.
	 * @param int        $index The segment being resolved.
	 * @param MaskFormat $format The format to apply at the leaf.
	 * @param int        $depth The current recursion depth.
	 * @return mixed
	 */
	private function apply( $node, $segments, $index, MaskFormat $format, $depth ) {
		// Fail closed, rather than return a node we did not get to inspect.
		if ( $depth > $this->max_depth || ! isset( $segments[ $index ] ) ) {
			return $format->mask( $node );
		}

		if ( is_string( $node ) ) {
			return $this->apply_to_json_string( $node, $segments, $index, $format, $depth );
		}

		if ( ! is_array( $node ) && ! is_object( $node ) ) {
			return $node;
		}

		$segment = $segments[ $index ];
		$is_leaf = ( count( $segments ) - 1 ) === $index;

		if ( MaskRules::WILDCARD === $segment ) {
			foreach ( $this->node_keys( $node ) as $key ) {
				$node = $this->node_set( $node, $key, $this->descend( $node, $key, $segments, $index, $is_leaf, $format, $depth ) );
			}

			return $node;
		}

		$key = $this->resolve_key( $node, $segment );
		if ( null === $key ) {
			// We never create a key that was not there.
			return $node;
		}

		return $this->node_set( $node, $key, $this->descend( $node, $key, $segments, $index, $is_leaf, $format, $depth ) );
	}

	/**
	 * Mask, or descend into, the value behind a resolved key.
	 *
	 * @param mixed      $node The current node.
	 * @param string|int $key The resolved key.
	 * @param string[]   $segments The full path.
	 * @param int        $index The segment that resolved to the key.
	 * @param bool       $is_leaf Whether the path ends at this segment.
	 * @param MaskFormat $format The format to apply at the leaf.
	 * @param int        $depth The current recursion depth.
	 * @return mixed
	 */
	private function descend( $node, $key, $segments, $index, $is_leaf, MaskFormat $format, $depth ) {
		$value = $this->node_get( $node, $key );

		return $is_leaf ? $format->mask( $value ) : $this->apply( $value, $segments, $index + 1, $format, $depth + 1 );
	}

	/**
	 * Mask inside a JSON encoded string, keeping it a string. This is what reaches
	 * the request body, and any other value that holds JSON.
	 *
	 * @param string     $node The string node.
	 * @param string[]   $segments The full path.
	 * @param int        $index The segment being resolved.
	 * @param MaskFormat $format The format to apply at the leaf.
	 * @param int        $depth The current recursion depth.
	 * @return string
	 */
	private function apply_to_json_string( $node, $segments, $index, MaskFormat $format, $depth ) {
		if ( ! $this->decode_json_strings || '' === $node ) {
			return $node;
		}

		$decoded = json_decode( $node, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return $node;
		}

		$masked = $this->apply( $decoded, $segments, $index, $format, $depth + 1 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The package is WordPress free by design.
		$encoded = json_encode( $masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return false === $encoded ? $node : $encoded;
	}

	/**
	 * Resolve a path segment against a node. An exact match wins, then a case
	 * insensitive one, since header names are case insensitive by spec.
	 *
	 * @param mixed  $node The node to look in.
	 * @param string $segment The path segment.
	 * @return string|int|null The real key, or null when the node has no such key.
	 */
	private function resolve_key( $node, $segment ) {
		$vars = $this->node_vars( $node );

		if ( array_key_exists( $segment, $vars ) ) {
			return $segment;
		}

		// A numeric segment against a list, where the key is an int and not a string.
		if ( is_numeric( $segment ) && array_key_exists( (int) $segment, $vars ) ) {
			return (int) $segment;
		}

		if ( ! $this->case_insensitive_keys ) {
			return null;
		}

		foreach ( array_keys( $vars ) as $key ) {
			if ( is_string( $key ) && 0 === strcasecmp( $key, $segment ) ) {
				return $key;
			}
		}

		return null;
	}

	/**
	 * The node as an array, whether it is an array or an object.
	 *
	 * @param mixed $node The node.
	 * @return mixed
	 */
	private function node_vars( $node ) {
		return is_object( $node ) ? get_object_vars( $node ) : $node;
	}

	/**
	 * The keys of a node.
	 *
	 * @param mixed $node The node.
	 * @return mixed
	 */
	private function node_keys( $node ) {
		return array_keys( $this->node_vars( $node ) );
	}

	/**
	 * Read a key from a node.
	 *
	 * @param mixed      $node The node.
	 * @param string|int $key The key.
	 * @return mixed
	 */
	private function node_get( $node, $key ) {
		$vars = $this->node_vars( $node );

		return array_key_exists( $key, $vars ) ? $vars[ $key ] : null;
	}

	/**
	 * Write a key to a node without touching the caller's data.
	 *
	 * @param mixed      $node The node.
	 * @param string|int $key The key.
	 * @param mixed      $value The value to set.
	 * @return mixed
	 */
	private function node_set( $node, $key, $value ) {
		if ( is_object( $node ) ) {
			// Objects are held by handle, so without the clone we mutate the caller's data.
			$copy         = clone $node;
			$copy->{$key} = $value;

			return $copy;
		}

		$node[ $key ] = $value;

		return $node;
	}
}
