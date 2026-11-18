<?php
/**
 * A plugin's abilities.
 *
 * @package     SugarCommerce\Rest
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest;

use InvalidArgumentException;
use WP_Error;

/**
 * A plugin's abilities, registered with WordPress's Abilities API from one list.
 *
 * One definition per action -- a description, a schema for what goes in and
 * what comes out, what runs it and who may -- and WordPress exposes it over
 * REST, and through the MCP Adapter plugin to Claude, ChatGPT and anything
 * else that speaks MCP.
 *
 * Reading is separate from changing, and each says which it is, so an
 * assistant's client can ask a person before anything that moves money or
 * takes something away. `kind` is one of:
 *
 *     read     changes nothing                    readonly, idempotent
 *     write    changes something, can be undone   neither
 *     destroy  cannot be taken back by calling    destructive
 *              something else: money returned,
 *              access removed
 *
 * and becomes the ability's annotations. A kind left out, or misspelt, is
 * treated as destroy: the one a client asks a person about first.
 *
 * Who may use each is its own question. An assistant acts as a WordPress
 * user and can do no more than that user, so an ability asks for the
 * capability the admin screen for the same thing asks for: its own
 * `capability`, or the one a capability map gives for its `kind` and
 * `area` (see capability()). Only an ability that says neither, in a set
 * with no map, falls back to the set's `capability`, `manage_options`
 * unless told otherwise.
 *
 * Add-ons add abilities to the same category through the `{id}_abilities`
 * filter, which every definition passes through on its way to WordPress.
 *
 * @since 1.0.0
 */
final class Abilities {

	/**
	 * The kinds an ability can be.
	 *
	 * @since 1.0.0
	 */
	public const KINDS = [ 'read', 'write', 'destroy' ];

	/**
	 * Every registered set, by id.
	 *
	 * @var array<string, self>
	 */
	private static array $sets = [];

	/**
	 * The set's id, which names its hooks.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * What every short name is prefixed with: `myplugin` makes `myplugin/list-orders`.
	 *
	 * @var string
	 */
	private string $namespace;

	/**
	 * The category's slug.
	 *
	 * @var string
	 */
	private string $category;

	/**
	 * The category to register, or null when it is somebody else's.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $category_args;

	/**
	 * The definitions, or what makes them.
	 *
	 * @var array<string, array<string, mixed>>|callable
	 */
	private $abilities;

	/**
	 * What gives the capability for a kind and an area, if anything.
	 *
	 * @var object|callable|null
	 */
	private $capabilities;

	/**
	 * The capability an ability needs when nothing else says.
	 *
	 * @var string
	 */
	private string $capability;

	/**
	 * Which kind of the capability map each kind of ability asks for.
	 *
	 * @var array<string, string>
	 */
	private array $access;

	/**
	 * Whether abilities are for clients, by default.
	 *
	 * @var array{public: bool, show_in_rest: bool, mcp: bool}
	 */
	private array $exposure;

	/**
	 * Whether an input may carry properties its schema does not name.
	 *
	 * @var bool
	 */
	private bool $strict;

	/**
	 * Register a plugin's abilities.
	 *
	 * Call it before WordPress starts the Abilities API -- on `init` or
	 * earlier. Registering again under the same id replaces the set.
	 *
	 * @param string               $id     The set's id: usually the plugin's prefix. Names the hooks.
	 * @param array<string, mixed> $config {
	 *     The set.
	 *
	 *     @type string                    $namespace    What short names are prefixed with. The id, made a slug.
	 *     @type array<string, mixed>|string $category   [ slug, label, description, meta ] to register a category,
	 *                                                   or the slug of one registered elsewhere. The id's slug,
	 *                                                   labelled with the id, by default.
	 *     @type array|callable            $abilities    name => definition, or a callable that returns them. A
	 *                                                   callable is called when WordPress asks, which is after
	 *                                                   translations have loaded.
	 *     @type Capabilities|string|object|callable $capabilities What gives the capability for a kind and an
	 *                                                   area: a Capabilities set or its id, any object with a
	 *                                                   capability( $kind, $area ) method, or a callable taking the
	 *                                                   same. A set's `{id}_can_{kind}` filter has its say too.
	 *     @type array<string, string>     $access       Which kind of that map each kind of ability asks for.
	 *                                                   read => read, write => write, destroy => write by default.
	 *     @type string                    $capability   What an ability needs when nothing else says.
	 *                                                   `manage_options` by default.
	 *     @type bool                      $public       Whether abilities are for clients such as AI agents. True.
	 *     @type bool                      $show_in_rest Whether abilities can be run over REST. True.
	 *     @type bool                      $mcp          Whether the MCP Adapter lists them. True.
	 *     @type bool                      $strict       Whether an input is refused for a property its schema
	 *                                                   does not name. True.
	 * }
	 *
	 * @return self
	 * @throws InvalidArgumentException When the id is empty.
	 * @since 1.0.0
	 */
	public static function register( string $id, array $config = [] ): self {
		if ( '' === $id ) {
			throw new InvalidArgumentException( 'A set of abilities needs an id.' );
		}

		self::unregister( $id );

		$set = new self( $id, $config );

		self::$sets[ $id ] = $set;

		add_action( 'wp_abilities_api_categories_init', [ $set, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $set, 'abilities_api_init' ] );

		return $set;
	}

	/**
	 * A registered set.
	 *
	 * @param string $id The set's id.
	 *
	 * @return self|null
	 * @since 1.0.0
	 */
	public static function get( string $id ): ?self {
		return self::$sets[ $id ] ?? null;
	}

	/**
	 * Forget a set before WordPress asks for it.
	 *
	 * Abilities WordPress already holds stay registered; unregister those
	 * with wp_unregister_ability().
	 *
	 * @param string $id The set's id.
	 *
	 * @return bool Whether there was one.
	 * @since 1.0.0
	 */
	public static function unregister( string $id ): bool {
		if ( ! isset( self::$sets[ $id ] ) ) {
			return false;
		}

		remove_action( 'wp_abilities_api_categories_init', [ self::$sets[ $id ], 'register_category' ] );
		remove_action( 'wp_abilities_api_init', [ self::$sets[ $id ], 'abilities_api_init' ] );
		unset( self::$sets[ $id ] );

		return true;
	}

	/**
	 * Build the set.
	 *
	 * @param string               $id     The set's id.
	 * @param array<string, mixed> $config See register().
	 */
	private function __construct( string $id, array $config ) {
		$this->id        = $id;
		$this->namespace = self::slug( (string) ( $config['namespace'] ?? $id ) );

		$category = $config['category'] ?? [];

		if ( is_string( $category ) ) {
			$this->category      = self::slug( $category );
			$this->category_args = null;
		} else {
			$category            = (array) $category;
			$this->category      = self::slug( (string) ( $category['slug'] ?? $id ) );
			$label               = (string) ( $category['label'] ?? $id );
			$this->category_args = [
				'label'       => $label,

				// WordPress refuses a category without a description, and
				// then every ability in it.
				'description' => (string) ( $category['description'] ?? '' ) ?: $label,
			];

			if ( isset( $category['meta'] ) ) {
				$this->category_args['meta'] = (array) $category['meta'];
			}
		}

		$abilities       = $config['abilities'] ?? [];
		$this->abilities = is_callable( $abilities ) ? $abilities : (array) $abilities;

		$capabilities       = $config['capabilities'] ?? null;
		$this->capabilities = is_object( $capabilities ) || is_callable( $capabilities ) || ( is_string( $capabilities ) && '' !== $capabilities )
			? $capabilities
			: null;

		$this->capability = (string) ( $config['capability'] ?? 'manage_options' );
		$this->capability = '' !== $this->capability ? $this->capability : 'manage_options';

		$this->access = array_map(
			'strval',
			(array) ( $config['access'] ?? [] ) + [
				'read'    => 'read',
				'write'   => 'write',
				'destroy' => 'write',
			]
		);

		$this->exposure = [
			'public'       => (bool) ( $config['public'] ?? true ),
			'show_in_rest' => (bool) ( $config['show_in_rest'] ?? true ),
			'mcp'          => (bool) ( $config['mcp'] ?? true ),
		];

		$this->strict = (bool) ( $config['strict'] ?? true );
	}

	/**
	 * The set's id.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The category's slug.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function category(): string {
		return $this->category;
	}

	/**
	 * Register the category, unless it is somebody else's or already there.
	 *
	 * Hooked to `wp_abilities_api_categories_init`.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register_category(): void {
		if ( null === $this->category_args || wp_has_ability_category( $this->category ) ) {
			return;
		}

		wp_register_ability_category( $this->category, $this->category_args );
	}

	/**
	 * Register every ability, as WordPress starts the Abilities API.
	 *
	 * Hooked to `wp_abilities_api_init`. Not register_abilities() itself:
	 * a plugin that bundles this library with Strauss has the global
	 * register_abilities() function renamed, and Strauss renames the
	 * function's name wherever it appears as a string too -- so a callback
	 * written as `[ $set, 'register_abilities' ]` would name a method that
	 * does not exist.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function abilities_api_init(): void {
		$this->register_abilities();
	}

	/**
	 * Register every ability.
	 *
	 * @return string[] The names WordPress accepted.
	 * @since 1.0.0
	 */
	public function register_abilities(): array {
		$registered = [];

		foreach ( $this->definitions() as $name => $definition ) {
			if ( null !== wp_register_ability( $name, $this->args( $name, $definition ) ) ) {
				$registered[] = $name;
			}
		}

		return $registered;
	}

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
	 * The capability an ability needs.
	 *
	 * The first of: its own `capability`; what the capability map says for
	 * the kind of thing it does (its `access`, or the one its `kind` maps
	 * to) and its `area`; the set's `capability`.
	 *
	 * @param string                    $name       The ability's full name.
	 * @param array<string, mixed>|null $definition Its definition; looked up when null.
	 *
	 * @return string Empty for an ability whose own `permission` callback decides.
	 * @since 1.0.0
	 */
	public function capability( string $name, ?array $definition = null ): string {
		$definition ??= $this->definitions()[ $name ] ?? null;

		if ( null === $definition ) {
			return $this->capability;
		}

		if ( is_callable( $definition['permission'] ?? null ) ) {
			return '';
		}

		$capability = (string) ( $definition['capability'] ?? '' );

		$map = $this->map();

		if ( '' === $capability && null !== $map ) {
			[ $access, $area ] = $this->asks( $definition );

			$capability = is_object( $map ) && method_exists( $map, 'capability' )
				? (string) $map->capability( $access, $area )
				: (string) call_user_func( $map, $access, $area );
		}

		$capability = '' !== $capability ? $capability : $this->capability;

		/**
		 * Filter the capability one of a plugin's abilities needs.
		 *
		 * @param string               $capability A WordPress capability.
		 * @param string               $name       The ability's full name.
		 * @param array<string, mixed> $definition Its definition.
		 *
		 * @since 1.0.0
		 */
		$capability = (string) apply_filters( $this->id . '_ability_capability', $capability, $name, $definition );

		return '' !== $capability ? $capability : $this->capability;
	}

	/**
	 * Whether the current user may use an ability.
	 *
	 * The permission callback every ability without its own is given.
	 *
	 * @param string                    $name       The ability's full name.
	 * @param mixed                     $input      What it was asked with.
	 * @param array<string, mixed>|null $definition Its definition; looked up when null.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function permitted( string $name, mixed $input = null, ?array $definition = null ): bool {
		$definition ??= $this->definitions()[ $name ] ?? null;

		if ( null !== $definition && is_callable( $definition['permission'] ?? null ) ) {
			return true === call_user_func( $definition['permission'], $input );
		}

		$allowed = current_user_can( $this->capability( $name, $definition ) );
		$map     = $this->map();

		// Asked of a Capabilities set, its filter has the say it has over
		// the REST routes and the admin screens: one rule, everywhere.
		if ( $map instanceof Capabilities && null !== $definition && '' === (string) ( $definition['capability'] ?? '' ) ) {
			[ $access, $area ] = $this->asks( $definition );

			$allowed = $map->filter( $allowed, $access, $area, $input );
		}

		return $allowed;
	}

	/**
	 * What gives the capability for a kind and an area, if anything.
	 *
	 * A set's id is looked up as it is needed, so the set may be
	 * registered after the abilities.
	 *
	 * @return object|callable|null
	 */
	private function map() {
		if ( is_string( $this->capabilities ) && ! is_callable( $this->capabilities ) ) {
			return Capabilities::get( $this->capabilities );
		}

		return $this->capabilities;
	}

	/**
	 * The kind and the area an ability asks a capability map about.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function asks( array $definition ): array {
		return [
			(string) ( $definition['access'] ?? $this->access[ self::kind( $definition ) ] ),
			(string) ( $definition['area'] ?? '' ),
		];
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
