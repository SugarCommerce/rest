<?php
/**
 * A row as the outside world is given it, and the schema pieces.
 *
 * @package SugarCommerce\Rest
 */

declare( strict_types=1 );

namespace SugarCommerce\Rest\Tests;

use SugarCommerce\Rest\Record;
use SugarCommerce\Rest\Schema;
use SugarCommerce\Rest\Tests\Support\TestCase;
use SugarCommerce\Rest\Tests\Support\WidgetRecord;
use SugarCommerce\Rest\Tests\Support\Widgets;
use LogicException;

final class RecordTest extends TestCase {

	public function test_a_page_is_primed_once_and_forgotten(): void {
		$this->api();

		$owner = Widgets::add();
		$rows  = [ Widgets::add( [ 'owner_id' => $owner->id ] ), 'not a row', Widgets::add( [ 'owner_id' => $owner->id ] ) ];

		$shaped = WidgetRecord::shapes( $rows, [ 'owner', 'nonsense' ] );

		$this->assertCount( 2, $shaped );
		$this->assertSame( 1, WidgetRecord::$primes );
		$this->assertSame( $owner->id, $shaped[0]['owner']['id'] );
		$this->assertSame( [], WidgetRecord::$primed );
		$this->assertSame( [], WidgetRecord::shapes( [] ) );
	}

	public function test_a_relation_not_primed_is_asked_of_the_row(): void {
		$this->api();

		$owner  = Widgets::add();
		$widget = Widgets::add( [ 'owner_id' => $owner->id ] );

		WidgetRecord::$primed = [];

		$this->assertSame( $owner->id, WidgetRecord::shape( $widget, [ 'owner' ] )['owner']['id'] );
		$this->assertSame( 1, Widgets::$getter_calls );
	}

	public function test_the_schema_is_named_for_the_api(): void {
		$this->api();

		$schema = WidgetRecord::schema();

		$this->assertSame( 'acme_widget', $schema['title'] );
		$this->assertSame( Schema::links(), $schema['properties']['_links'] );
		$this->assertSame( [ 'object', 'null' ], $schema['properties']['country']['type'] );
	}

	public function test_a_country_is_its_code_without_a_name_function(): void {
		$this->api( [ 'country_name' => null ] );

		$widget = Widgets::add( [ 'country' => 'fr' ] );

		$this->assertSame(
			[
				'code' => 'FR',
				'name' => 'FR',
			],
			WidgetRecord::shape( $widget )['country']
		);
		$this->assertNull( WidgetRecord::shape( Widgets::add() )['country'] );
	}

	public function test_a_record_that_names_no_api_says_so(): void {
		$this->expectException( LogicException::class );

		$record = new class() extends Record {
			public static function name(): string {
				return 'x';
			}
			public static function base(): string {
				return 'x';
			}
			public static function properties(): array {
				return [];
			}
			protected static function fields( object $row ): array {
				return [];
			}
		};

		$record::schema();
	}

	public function test_money_can_be_worded_by_the_plugin(): void {
		$schema = new class() extends Schema {
			protected static function money_formatted(): string {
				return 'As the shop writes money.';
			}
		};

		$this->assertSame( 'As the shop writes money.', $schema::money()['properties']['formatted']['description'] );
		$this->assertSame( 'The amount written out as the site writes money.', Schema::money()['properties']['formatted']['description'] );
		$this->assertSame( [ 'object', 'null' ], Schema::money( '', true )['type'] );
		$this->assertTrue( Schema::money()['readonly'] );
	}

	public function test_the_shapes_that_repeat(): void {
		$this->assertSame( [ 'string', 'null' ], Schema::date()['type'] );
		$this->assertSame( 'date-time', Schema::date()['format'] );
		$this->assertSame( [ 'integer', 'null' ], Schema::integer( '', true )['type'] );
		$this->assertSame( 'number', Schema::number()['type'] );
		$this->assertSame( [ 'a', 'b' ], Schema::string( '', [ 'x' => 'a', 'y' => 'b' ] )['enum'] );
		$this->assertArrayNotHasKey( 'enum', Schema::string() );
		$this->assertSame( [ 'string', 'null' ], Schema::nullable_string()['type'] );
		$this->assertSame( 'boolean', Schema::boolean()['type'] );
		$this->assertSame( [ 'type' => 'integer' ], Schema::list( [ 'type' => 'integer' ] )['items'] );
		$this->assertTrue( Schema::open()['additionalProperties'] );
		$this->assertSame( [ 'status', 'status_label' ], array_keys( Schema::status() ) );
		$this->assertStringContainsString( 'One of a, b, or one an add-on has added.', Schema::status( [ 'a', 'b' ] )['status']['description'] );
		$this->assertSame( [ 'date_created', 'date_modified' ], array_keys( Schema::stamps() ) );
	}
}
