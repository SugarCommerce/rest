# REST

A plugin's REST API and who may use it: controllers, allow-listed record
shapes, list parameters, ETags, stable error codes, rate limits, idempotency
keys, a changes feed, one search box and an OpenAPI document — with the
plugin's own capabilities, and its abilities for AI assistants.

## What it does

A plugin with custom tables that wants an app on top of them ends up writing
the same twenty things for every resource: paging headers, `orderby` and the
date filters, who may call it, an error code a client can switch on, a 304
for a record it already has, a rate limit per application password, an
`Idempotency-Key` so a retried refund is not a second refund, a way for an
app to ask "what changed since I last looked", and a document a client
generator can read. This is those, once.

Who may do what is asked once too. The plugin names its capabilities
(`myshop_view_shop`, `myshop_refund_orders`), administrators have them
without anything written to a role, and the REST routes, the admin screens
and the abilities an assistant uses over MCP all ask the same question: a
kind of thing to do, and what it is about.

Everything a client sees that names the plugin (`myshop_not_found`,
`myshop_rest_init`, `X-MyShop-Mode`, `x-myshop`) is built from one prefix, so
two plugins bundling this never meet. It does not create tables: the
idempotency keys and the rate-limit counters live in tables your plugin
creates, as it creates its others.

## Features

- Register a whole resource's list and single-record routes from one spec
- Shape rows through an allow-list, with related records fetched once a page
- Answer every error under one of seven codes a client can switch on
- Tag answers with ETags and answer 304, on every route, one implementation
- Limit requests per application password, with a tighter bucket for refunds
- Do a retried POST once, and replay the first answer to the retry
- Tell an app what changed and what was deleted since its last look, add-ons' records included
- Serve an OpenAPI 3.1 document written from the routes themselves
- Give the plugin its own capabilities, and register its abilities for MCP from one list

## Installation

```bash
composer require sugarcommerce/rest
```

It builds on [sugarcommerce/security](https://github.com/sugarcommerce/security)
(the rate-limit counters), [sugarcommerce/data](https://github.com/sugarcommerce/data)
(`Query::claim()` and `sweep()` for idempotency keys in a table of your own)
and [sugarcommerce/dates](https://github.com/sugarcommerce/dates) (UTC dates,
and one clock a test can stop).

## Quick start

Register the capabilities and the API once, as the plugin loads; name the API
in a base controller and a base record; write a controller and a record for
each table.

```php
use SugarCommerce\Rest\Api;
use SugarCommerce\Rest\Capabilities;

Capabilities::register( 'myshop', [
	'kinds' => [
		'read'   => [ 'default' => 'myshop_view_shop', 'areas' => [ 'reports' => 'myshop_view_reports' ] ],
		'write'  => [ 'default' => 'myshop_manage_settings', 'areas' => [ 'orders' => 'myshop_manage_orders' ] ],
		'refund' => [ 'default' => 'myshop_refund_orders' ],
	],
] );

Api::register( 'myshop', [
	'namespace'    => 'myshop/v1',
	'test_mode'    => 'myshop_is_test_mode',
	'ready'        => 'myshop_tables_installed',
	'capabilities' => 'myshop',
	'controllers'  => [ Rest\Orders::class ],
	'resources'    => [
		'orders' => [
			'record'  => Rest\Order::class,
			'table'   => 'myshop_orders',
			'deleted' => 'myshop_delete_order',
			'search'  => [ 'list' => 'myshop_get_orders', 'find' => 'myshop_find_order' ],
		],
	],
] );
```

```php
abstract class Controller extends \SugarCommerce\Rest\Controller {
	protected const API = 'myshop';
}

abstract class Record extends \SugarCommerce\Rest\Record {
	protected const API = 'myshop';
}

final class Orders extends Controller {
	protected string $area     = 'orders';
	protected string $resource = Order::class;

	protected function spec(): array {
		return [
			'get'      => 'myshop_get_order',
			'list'     => 'myshop_get_orders',
			'count'    => 'myshop_count_orders',
			'sortable' => [ 'total' ],
			'search'   => true,
			'mode'     => true,
			'filters'  => [
				'status' => [ 'description' => 'Only these statuses.', 'type' => 'string', 'list' => true ],
			],
		];
	}

	public function register_routes(): void {
		$this->route( '/orders', [ $this->handle( 'GET', 'get_items', 'read', $this->get_collection_params() ) ] );
		$this->route( '/orders/' . self::id(), [ $this->handle( 'GET', 'get_item', 'read', self::id_arg() ) ] );
		$this->route( '/orders/' . self::id() . '/refunds', [
			$this->handle( 'POST', 'refund', 'refund', self::id_arg(), once: true ),
		] );
	}

	public function refund( WP_REST_Request $request ) {
		$order = $this->record( $request );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$done = myshop_refund_order( $order->id );   // the function the admin screen calls

		return is_wp_error( $done ) ? self::refused( $done ) : $this->shown( $request, myshop_get_order( $order->id ) );
	}
}

final class Order extends Record {
	public static function name(): string { return 'order'; }
	public static function base(): string { return 'orders'; }

	public static function properties(): array {
		return [
			'id'    => Schema::integer( 'The order’s id.' ),
			'total' => Schema::money( 'What was paid.' ),
		] + Schema::status( [ 'paid', 'refunded' ] ) + Schema::stamps();
	}

	protected static function fields( object $row ): array {
		return [
			'id'            => (int) $row->id,
			'total'         => myshop_rest_money( $row->total, $row->currency ),
			'status'        => $row->status,
			'date_created'  => self::api()->date( $row->date_created ),
			'date_modified' => self::api()->date( $row->date_modified ),
		];
	}
}
```

That is `GET /orders` (paged, sorted, filtered, searchable, test or live),
`GET /orders/{id}` (with an ETag), an idempotent `POST /orders/{id}/refunds`
only `myshop_refund_orders` may make, `GET /changes`, `GET /search` and
`GET /openapi.json`.

## Lists and records

`get_collection_params()` declares what every list takes — `page`,
`per_page` (up to 100), `orderby`, `order`, `include`, `exclude`, `after`,
`before`, `updated_after`, and `search`, `mode` and `expand` where the spec
and the record have them — followed by the spec's own `filters`, and turns a
request into BerlinDB-style arguments (`number`, `offset`, `id__in`,
`date_created_query`, `status__in`). `mode` is for tables with an `is_test`
column: left out, a list shows the current mode's records; `all` shows both.
A list answers with `X-WP-Total`, `X-WP-TotalPages`, and a `Link` header with
`first`, `prev`, `next` and `last`.

A record's `fields()` is the allow-list: a column it does not name never
leaves, so adding a column never adds it to the API. `prime()` fetches each
`expand`able relation for a whole page with one query; every record carries
`_links` and passes through `{prefix}_rest_prepare_{name}`;
`register_rest_field( $api->object_type( 'order' ), … )` adds a field to the
record and to the OpenAPI document. `Schema` has the shapes that repeat:
`money()`, `date()`, `status()`, `stamps()`, `links()`, `object()`, `list()`.

## Errors

`not_found()`, `invalid( $param, $message )`, `conflict()`, and `refused()`
for an error from the function that did the work: its message is kept, its
own code becomes `data.reason`, and the code a client switches on is one of
`{prefix}_unauthorized`, `_forbidden`, `_not_found`, `_invalid_param`,
`_conflict`, `_rate_limited` or `_failed`, chosen by its status. WordPress's
own `rest_invalid_param` and `rest_missing_callback_param` are said under
`{prefix}_invalid_param` too, with `data.param` naming the parameter.

## Capabilities

```php
$caps = Capabilities::get( 'myshop' );

$caps->capability( 'read', 'reports' );         // 'myshop_view_reports', for a menu page
$caps->can( 'refund', 'orders' );               // the current user
$caps->user_can( $user_id, 'write', 'orders' ); // somebody else
$caps->all();                                   // every one of them, for a role editor
$caps->granted();                               // the ones the current user has

// An add-on's own capability, and the areas that take it.
$caps->add( 'myshop_manage_affiliates', 'Run the affiliate programme', [
	'read'  => [ 'affiliates' ],
	'write' => [ 'affiliates', 'payouts' ],
] );
```

`add()` grants the new capability to the parent's holders like the rest. An
area already mapped keeps its capability, so an add-on cannot loosen what the
plugin's own areas ask for.

Anyone with the parent capability (`manage_options`, or `'parent' => …`;
`false` for nobody) has every one, worked out as the question is asked and
never written into a role. An area the map does not name — an add-on's —
takes the kind's default; a kind it does not name is treated as `write`; and
with no answer at all it is the parent, never `''`, which would refuse
administrators too. Each kind runs one filter, `{id}_can_{kind}` (or
`'filter' => 'myshop_rest_can_{kind}'`), with the answer, the area, the
context and the user.

Every API route takes a kind and its controller's area. Nobody signed in is a
401 that says why application passwords do not work on this site, if they do
not; signed in without the capability is a 403. A `can` callable in the API's
configuration decides instead; with neither, it is `manage_options`.

## Abilities for AI assistants

```php
use SugarCommerce\Rest\Abilities;

Abilities::register( 'myshop', [
	'category'     => [ 'label' => __( 'My Shop', 'my-shop' ), 'description' => __( 'Orders and refunds.', 'my-shop' ) ],
	'capabilities' => 'myshop',               // the same set the API asks
	'abilities'    => fn() => [
		'list-orders'  => [
			'label'       => __( 'List orders', 'my-shop' ),
			'description' => __( 'Lists recent orders, newest first.', 'my-shop' ),
			'kind'        => 'read',
			'area'        => 'orders',
			'input'       => [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ] ],
			'execute'     => [ Orders::class, 'list_for_assistant' ],
		],
		'refund-order' => [
			'label'       => __( 'Refund an order', 'my-shop' ),
			'description' => __( 'Returns money to the customer. Confirm with a person first.', 'my-shop' ),
			'kind'        => 'destroy',
			'access'      => 'refund',
			'area'        => 'orders',
			'input'       => [ 'order' => [ 'type' => 'integer' ] ],
			'required'    => [ 'order' ],
			'execute'     => [ Orders::class, 'refund_for_assistant' ],
		],
	],
] );
```

Registered with WordPress's Abilities API when it starts, so they run over
REST and the MCP Adapter plugin lists them (`mcp.public`). `kind` becomes the
annotations a client sees — `read` is readonly and idempotent, `destroy` is
destructive, and a missing or misspelt kind is `destroy`, the one a client asks
a person about first. Each ability needs its own `capability`, or what the
set gives for its kind (`access`) and `area`, or `manage_options`; a
`permission` callable decides instead. Asked of a `Capabilities` set, the
set's `{id}_can_{kind}` filter has its say too, with the ability's input, so a
rule written for the REST routes holds for an assistant as well. An input property the schema does not
name is refused (`'strict' => false` allows them), and with nothing required a
call with no input works. Add-ons add theirs through `{id}_abilities`;
`{id}_ability_capability` changes what any one needs.

## Rate limits

Counted per application password (`app:{uuid}`), or per user for a cookie.
Every request counts towards `general` (120 a minute); a `refund` counts
towards `money` (10 a minute) as well. Answers carry `X-RateLimit-*`, and
`Retry-After` as soon as nothing is left. The counter is sugarcommerce/security's
`Limiter`: the object cache when there is a persistent one, otherwise the table
your plugin made (`{prefix}_rate_limits`; see `RateLimit\Stores\Table::schema()`
there).

```php
'rate_limit' => [
	'limits' => [ 'general' => 300, 'money' => 10 ],
	'kinds'  => [ 'refund' => 'money' ],
	'table'  => 'myshop_rate_limits',
],
```

`{prefix}_rest_rate_limit` changes a limit (0 lifts it);
`{prefix}_rest_rate_counter` hands over a Limiter of your own. A public route
outside the API counts each caller on its own with
`$api->rate_limit()->check( 'general', 'storefront:' . $ip )`.

## Idempotency keys

`handle( …, once: true )` makes a route require an `Idempotency-Key` header.
The same key, route and body within a day is given the first answer, with
`Idempotent-Replayed: true`, and nothing is done again; the same key with a
different body is a 422; the same key while the first is still running is a
409 with `retry_after`. A 5xx is not kept, so the retry may try. Keys belong
to the user who sent them.

The default store is `Stores\Table` on `{prefix}_idempotency_keys`, a table
your plugin creates with `dbDelta( Stores\Table::schema( $wpdb->prefix .
'myshop_idempotency_keys', $wpdb->get_charset_collate() ) )`. A plugin whose
tables are sugarcommerce/data components keeps them through its own query
class, functions and hooks:

```php
'idempotency' => fn() => new Stores\Query(
	query:  fn() => new Database\Queries\IdempotencyKey(),   // a SugarCommerce\Data\Query
	insert: 'myshop_add_idempotency_key',
	update: 'myshop_update_idempotency_key',
	delete: 'myshop_delete_idempotency_key',
),
```

## Changes, deletions and search

Every resource given a `table` is in `GET /changes?since=…`: the ids changed
since then, kind by kind (up to 500 each, with `truncated` when there were
more), the ids deleted (the last 500 deletions, from each resource's `deleted`
action, with `complete` false when that does not reach back far enough), and a
`cursor` to send next time. Every resource given `search` is in
`GET /search?q=…`: its `find` first, for the exact match, then its `list`'s
own search. Both answer only for the kinds the user may read: each kind is
asked about under its own `area` (its name unless it gives one), on top of the
route's check of the API's area. An add-on's records join both with one call as the plugin loads,
`Api::get( 'myshop' )->add_resource( 'reviews', [ … ] )`, and its controller
is handed over on `{prefix}_rest_init` with `$api->add( $controller )`.

## ETags, 304 and CORS

`Conditional::apply( $request, $response, $args )` is the one implementation,
used by `shown()` for every single record: a weak ETag of the answer and the
query; 304 for a matching `If-None-Match`; `Last-Modified` from `modified`;
`If-Modified-Since` honoured only with `trust_modified`; `Cache-Control` from
`cache_control` for a public catalogue.

For routes other sites call — a headless storefront's catalogue:

```php
( new Cors(
	fn( WP_REST_Request $request ) => str_starts_with( $request->get_route(), '/myshop/v1/storefront' ),
	[ 'https://shop.example', 'http://localhost:3000' ],   // or fn( $origin ) => the origin to echo, or ''
	[ 'rate_limit' => Api::get( 'myshop' )->rate_limit() ]
) )->init();
```

A list matches exactly — scheme, host and port — so a suffix, a subdomain or
the other scheme is another site. The caller's origin is echoed only when
allowed, never `*` and never with credentials; `Vary: Origin` always; a
preflight is a 204.

## The OpenAPI document

`GET /openapi.json` (or `$api->openapi()->document()`) is OpenAPI 3.1 written
from the routes as they are registered, add-ons included, so it cannot fall
behind the code. `openapi.title`, `description` and `security` say what it says
about the API as a whole.

## Hooks

All named from the prefix or id: `{prefix}_rest_init`, `{prefix}_rest_request`,
`{prefix}_rest_log_requests` (with a `log` callable to write a line a request),
`{prefix}_rest_prepare_{name}`, `{prefix}_rest_rate_limit`,
`{prefix}_rest_rate_counter`, `{id}_can_{kind}`, `{id}_abilities`,
`{id}_ability_capability`.

## Security

* Every route checks a signed-in user and a capability before it runs, and
  a refund or anything else that moves money has its own, tighter rate limit
  per application password.
* `/changes` and `/search` check each kind of record under its own area, so
  a user allowed one kind cannot read another's ids or rows through them.
* A retried POST cannot run twice: idempotency keys are per user, the
  request's fingerprint is compared in constant time, and a key reused for a
  different request is refused.
* Errors from a plugin's functions pass on their message and code, never
  their data, so nothing internal leaks into an answer.
* CORS echoes an origin only when it is on the list, exactly (scheme, host,
  port); never `*`, never with credentials.
* The request log, when on, is one line a request without the body.

## Layout

* `src/Api.php` registers an API and makes its parts; `src/Traits/Api/` holds
  its names, what the site says, its routes and what every response gets.
* `src/Controller.php` is the base controller; `src/Traits/Controller/` holds
  its list parameters, its answers and its refusals. `src/Controllers/` are
  the kit's own routes (`/changes`, `/search`, `/openapi.json`).
* `src/Record.php`, `Schema.php`, `Errors.php`: record shapes, JSON Schema
  helpers, the seven error codes.
* `src/Capabilities.php` and `src/Abilities.php` (with `src/Traits/Abilities/`):
  who may do what, and the abilities for AI assistants.
* `src/Permissions.php`, `RateLimit.php`, `Idempotency.php` (and
  `src/Idempotency/`), `Changes.php`, `Conditional.php`, `Cors.php`,
  `OpenApi.php`: the parts.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
