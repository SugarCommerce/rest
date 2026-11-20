<?php
/**
 * Definitions: what each ability is registered with.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Traits\Abilities;

/**
 * Definitions: what each ability is registered with.
 *
 * Its label, category, input and output schemas and meta, from a short
 * definition, after add-ons have added theirs.
 *
 * @since 1.0.0
 */
trait Definitions {

	/**
	 * Every definition, by full name, after add-ons have had theirs added.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.0.0
	 */
	public function definitions(): array {
		$abilities = is_callable( $this->abilities ) ? (array) call_user_func( $this->abilities ) : $this->abilities;

		/**
		 * Filter the abilities a plugin registers.
		 *
		 * The way in for an add-on: add a definition and it is registered
		 * with the rest, in the same category and with the same permission
		 * model. A short name is prefixed with the plugin's namespace; a
		 * name with a slash in it is used as it is, so an add-on can keep
		 * its own namespace.
		 *
		 * @param array<string, array<string, mixed>> $abilities name => definition.
		 * @param string                              $namespace What short names are prefixed with.
		 * @param string                              $category  The category's slug.
		 *
		 * @since 1.0.0
		 */
		$abilities = (array) apply_filters( $this->id . '_abilities', $abilities, $this->namespace, $this->category );

		$definitions = [];

		foreach ( $abilities as $name => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$definitions[ $this->name( (string) $name ) ] = $definition;
		}

		return $definitions;
	}

	/**
	 * What one definition is registered with: the arguments to wp_register_ability().
	 *
	 * @param string                    $name       The ability's full name.
	 * @param array<string, mixed>|null $definition Its definition; looked up when null.
	 *
	 * @return array<string, mixed>|null Null when there is no such ability.
	 * @since 1.0.0
	 */
	public function args( string $name, ?array $definition = null ): ?array {
		$definition ??= $this->definitions()[ $name ] ?? null;

		if ( null === $definition ) {
			return null;
		}

		$kind = self::kind( $definition );

		$args = [
			'label'               => (string) ( $definition['label'] ?? $name ),
			// WordPress refuses an ability without a description.
			'description'         => (string) ( $definition['description'] ?? '' ) ?: (string) ( $definition['label'] ?? $name ),
			'category'            => self::slug( (string) ( $definition['category'] ?? $this->category ) ),
			'input_schema'        => $this->input_schema( $definition ),
			'execute_callback'    => $definition['execute'] ?? null,
			'permission_callback' => is_callable( $definition['permission'] ?? null )
				? $definition['permission']
				: fn( mixed $input = null ): bool => $this->permitted( $name, $input, $definition ),
			'meta'                => $this->meta( $definition, $kind ),
		];

		$output = self::output_schema( $definition );

		if ( null !== $output ) {
			$args['output_schema'] = $output;
		}

		if ( isset( $definition['ability_class'] ) ) {
			$args['ability_class'] = (string) $definition['ability_class'];
		}

		return $args;
	}

	/**
	 * A short name made full: `list-orders` is `myplugin/list-orders`.
	 *
	 * @param string $name A short or full name.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function name( string $name ): string {
		return str_contains( $name, '/' ) ? $name : $this->namespace . '/' . $name;
	}

	/**
	 * The schema for what an ability takes.
	 *
	 * `input` is the properties of an object, and `required` the ones that
	 * must be there. An object with nothing in it is still an object: the
	 * Abilities API passes the input to the callbacks only when there is a
	 * schema, and every callback here takes one.
	 *
	 * When nothing is required the object defaults to an empty one. Without
	 * that, an ability asked with no input at all -- a REST GET with no
	 * query, an assistant that sends no arguments -- is refused for not
	 * being given an object, though there was nothing it needed.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return array<string, mixed>
	 */
	private function input_schema( array $definition ): array {
		if ( isset( $definition['input_schema'] ) && is_array( $definition['input_schema'] ) ) {
			return $definition['input_schema'];
		}

		$schema = [
			'type'                 => 'object',
			'properties'           => (array) ( $definition['input'] ?? [] ),
			'required'             => array_values( (array) ( $definition['required'] ?? [] ) ),
			'additionalProperties' => ! $this->strict,
		];

		if ( [] === $schema['required'] ) {
			$schema['default'] = [];
		}

		return $schema;
	}

	/**
	 * The schema for what an ability answers with, if it says.
	 *
	 * A full schema -- anything with a `type` -- is used as it is. Anything
	 * else is the properties of an object. The Abilities API checks the
	 * answer against it, so an answer that does not match becomes an error.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function output_schema( array $definition ): ?array {
		$output = $definition['output_schema'] ?? $definition['output'] ?? null;

		if ( ! is_array( $output ) ) {
			return null;
		}

		return isset( $output['type'] ) ? $output : [
			'type'       => 'object',
			'properties' => $output,
		];
	}

	/**
	 * The ability's meta: its annotations, and who is shown it.
	 *
	 * `mcp.public` is what the MCP Adapter plugin looks for. Without it an
	 * ability exists and no assistant can see it.
	 *
	 * @param array<string, mixed> $definition The definition.
	 * @param string               $kind       Its kind.
	 *
	 * @return array<string, mixed>
	 */
	private function meta( array $definition, string $kind ): array {
		$mcp = $definition['mcp'] ?? $this->exposure['mcp'];
		$mcp = is_array( $mcp ) ? $mcp + [ 'public' => $this->exposure['mcp'] ] : [ 'public' => (bool) $mcp ];

		$meta = [
			'annotations'  => array_merge(
				[
					'readonly'    => 'read' === $kind,
					'destructive' => 'destroy' === $kind,
					'idempotent'  => (bool) ( $definition['idempotent'] ?? ( 'read' === $kind ) ),
				],
				(array) ( $definition['annotations'] ?? [] )
			),
			'public'       => (bool) ( $definition['public'] ?? $this->exposure['public'] ),
			'show_in_rest' => (bool) ( $definition['show_in_rest'] ?? $this->exposure['show_in_rest'] ),
			'mcp'          => $mcp,
		];

		return array_replace_recursive( $meta, (array) ( $definition['meta'] ?? [] ) );
	}

	/**
	 * A definition's kind, with anything unknown treated as destroy.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return string
	 */
	private static function kind( array $definition ): string {
		$kind = $definition['kind'] ?? '';

		return in_array( $kind, self::KINDS, true ) ? $kind : 'destroy';
	}

	/**
	 * A name the Abilities API accepts: lowercase letters, digits and dashes.
	 *
	 * @param string $value Anything.
	 *
	 * @return string
	 */
	private static function slug( string $value ): string {
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' );
	}
}
