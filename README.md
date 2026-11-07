# tacticmedia/scim-server

SCIM 2.0 server library for PHP (RFC 7643, RFC 7644). `ScimServer::handle()` takes a Symfony HttpFoundation
`Request` and returns a SCIM `Response`. You supply one repository for each resource type.

The library does these tasks:

- It routes and answers the SCIM endpoints: resources, list and search, PATCH, Bulk and discovery.
- It validates request bodies against the schemas and applies PATCH operations.
- It checks uniqueness, applies `attributes`, `excludedAttributes` and `returned`, and evaluates ETags.
- It sets `meta.resourceType`, `meta.location` and `meta.version`, and fills absent `$ref` values.
- It contains the RFC 7643 User, Group and Enterprise User schemas and an in-memory repository.

The library does not do these tasks:

- Authentication and authorization. Authenticate each request before you call `handle()`.
- Storage. Implement `ResourceRepository` for your storage.
- Rate limiting, logging and the HTTP routing of your application.

For a Symfony application, use [tacticmedia/scim-server-bundle](https://github.com/tacticmedia/scim-server-bundle).

## Installation

Requirement: PHP 8.5 or later.

```bash
composer require tacticmedia/scim-server
```

The library needs no registration.

## Quick start

```php
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use TacticMedia\ScimServer\Repository\InMemoryResourceRepository;
use TacticMedia\ScimServer\Schema\CoreResourceTypes;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\ScimServer;

$clock = new class implements ClockInterface {
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
};

$users = CoreResourceTypes::user();
$groups = CoreResourceTypes::group();

$server = new ScimServer([
    new RegisteredResourceType($users, new InMemoryResourceRepository($users, $clock)),
    new RegisteredResourceType($groups, new InMemoryResourceRepository($groups, $clock)),
]);

$request = Request::create(
    'https://example.com/scim/v2/Users',
    'POST',
    server: ['CONTENT_TYPE' => 'application/scim+json'],
    content: '{"schemas": ["urn:ietf:params:scim:schemas:core:2.0:User"], "userName": "bjensen"}',
);

$response = $server->handle($request, '/scim/v2');

echo $response->getStatusCode(), "\n", $response->getContent(), "\n";
```

```text
201
{"schemas":["urn:ietf:params:scim:schemas:core:2.0:User"],"id":"<UUID v7>","meta":{"resourceType":"User","created":"<now>","lastModified":"<now>","version":"W/\"1\"","location":"https://example.com/scim/v2/Users/<UUID v7>"},"userName":"bjensen"}
```

`InMemoryResourceRepository` keeps the documents in a PHP array. Use it for tests and prototypes.

The second argument of `handle()` is the path prefix of the SCIM API, or `""` at the root. The server removes it
from `$request->getPathInfo()`. The base URL of `meta.location` is the scheme, the host, `$request->getBaseUrl()`
and the prefix.

In a front controller without HttpKernel, call `prepare()`. It removes the body of a HEAD response:

```php
$request = Request::createFromGlobals();
$server->handle($request, '/scim/v2')->prepare($request)->send();
```

`handle()` turns each `ScimException` into a SCIM error response. Other exceptions propagate.

## PSR-15

`Psr15\ScimRequestHandler` is a PSR-15 request handler. It converts the PSR-7 request with
`symfony/psr-http-message-bridge`, calls `ScimServer::handle()` and `prepare()`, and converts the response back. A
HEAD response thus has no body in every host. The handler needs packages that the library only suggests:

```bash
composer require symfony/psr-http-message-bridge psr/http-message psr/http-server-handler
```

The constructor takes the server, the path prefix of the SCIM API, and a bridge `PsrHttpFactory` with the four
PSR-17 factories of your PSR-7 implementation. Slim 4 with `nyholm/psr7` and `nyholm/psr7-server`:

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Slim\Factory\AppFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use TacticMedia\ScimServer\Psr15\ScimRequestHandler;

$psr17 = new Psr17Factory();
$app = AppFactory::create($psr17);
$app->any('/scim/v2[/{path:.*}]', new ScimRequestHandler($server, '/scim/v2', new PsrHttpFactory($psr17, $psr17, $psr17, $psr17)));
$app->run();
```

- `$server` is the `ScimServer` of the quick start.
- The handler does not authenticate. Add an authentication middleware to the route.
- Exceptions other than `ScimException` go to the error middleware of the host, which answers without a SCIM body.
- The base URL of `meta.location` comes from the `Host` header and the URI scheme of the PSR-7 request. Behind a
  proxy, make the host give the public values.

## Implement a repository

A repository stores the documents of one resource type. A document is an `array<string, mixed>` in schema
spelling. `schemas`, `id`, `externalId`, `meta` and the core attributes are at the top level. The attributes of an
extension are below the URN of the extension:

```php
[
    'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User', 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User'],
    'id' => '01a1ac91-c4ab-7b1b-b72f-45f08a161b38',
    'meta' => ['created' => '2026-11-05T10:20:30Z', 'lastModified' => '2026-11-05T10:20:30Z', 'version' => 'W/"1"'],
    'userName' => 'bjensen',
    'emails' => [['value' => 'bjensen@example.com', 'type' => 'work']],
    'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User' => ['manager' => ['value' => '01a1ac92-54be-77a4-8bd1-24e885e32c9f']],
]
```

The server does this work before it calls the repository:

- It normalizes the body: schema spelling, `schemas`, value types, single values wrapped in lists, one `primary`.
- It rejects a body without a required value. It drops `readOnly` values from POST and PUT bodies.
- It answers 400 `mutability` for a change of a stored `immutable` value, and for a PATCH that changes a
  `readOnly` value.
- It applies PATCH operations and gives `replace()` the complete result. A PATCH that changes nothing calls no
  write.
- It checks the single-valued attributes with `uniqueness` `server` or `global`. It calls `list()` with an `eq`
  filter and `count` 2, and answers 409 `uniqueness` on a match.
- It projects responses, evaluates `If-Match` and `If-None-Match`, and sets `meta.resourceType`, `meta.location`
  and `meta.version`.
- It fills an absent `$ref` from `value`. The target is the registered resource type that `type` names, or else the
  only resource type in the `referenceTypes` of `$ref`.

The repository does this work:

| Method | Contract |
| --- | --- |
| `get($id, $attributes, $excludedAttributes)` | Return the document, or `null` for an unknown id. |
| `list(ListQuery $query)` | Apply `filter`, then `sortBy` and `sortOrder`, then `startIndex` (from 1) and `count`. Return a `ListResult` with the page and the number of all matches. |
| `create($document)` | Set `id`, `meta.created`, `meta.lastModified` and, if you use it, `meta.version`. The document has no `id` and no `meta`. Return the stored document. |
| `replace($id, $document, $version)` | Store `$document` as the complete new state. Update `meta.lastModified` and, if you use it, `meta.version`. Throw `ScimException::notFound()` for an unknown id. When `$version` is not `null`, write only while the stored `meta.version` equals it, else throw `ScimException::preconditionFailed()`. |
| `delete($id, $version)` | Throw `ScimException::notFound()` for an unknown id. When `$version` is not `null`, delete only while the stored `meta.version` equals it, else throw `ScimException::preconditionFailed()`. |

Rules:

- `meta.version` is optional. Without it, the server makes a weak ETag from a hash of the document.
- Every path in a `ListQuery` has the schema URN and the schema spelling, for example
  `urn:ietf:params:scim:schemas:core:2.0:User:externalId`. A comparison on a complex attribute targets its `value`
  sub-attribute: `emails co "x"` arrives as `emails.value co "x"`.
- The filter is a tree of `Comparison`, `Present`, `LogicalAnd`, `LogicalOr`, `Not` and `ValuePathFilter`. Apply
  RFC 7644 section 3.4.2.2. Compare strings in any case when `caseExact` is false. The uniqueness check needs this.
  `FilterEvaluator::matches()` evaluates a filter against one document, as `InMemoryResourceRepository` does.
- Sort as RFC 7644 section 3.4.2.3 says. Root queries with `sortBy` merge the sorted pages of all types.
- The server checks uniqueness before the write, without a lock. Make the storage reject duplicates too, and throw
  `ScimException::uniqueness()` from `create()` or `replace()`.
- `password` arrives as the client sent it. Hash it before you store it. PUT and PATCH give the value from `get()`
  back to `replace()`. Thus do not return the hash from `get()`, and keep the stored hash when `replace()` gets no
  `password`.
- User `groups` is `readOnly`. The server never changes it. Compute it from the group memberships if you need it.

Projection hints: `get()` gets the `attributes` and `excludedAttributes` of a GET of one resource, and on the delta
path of a PATCH the hints that [Member deltas](#member-deltas) lists. `ListQuery` has them for lists and searches,
except for root queries with `sortBy`. Each is a list of resolved `AttributePath`. You can skip what the response
omits. The server projects the response itself. If you skip attributes, set `meta.version`, because a hash of a
partial document is not a stable ETag.

`If-Match`: the server compares it with the document from `get()`. For an `If-Match` with an entity tag, it then
passes the `meta.version` of that document as `$version` to `replace()` or `delete()`. Without `If-Match`, and for
`If-Match: *`, `$version` is `null`. A repository that writes only while the stored `meta.version` still equals
`$version` makes `If-Match` atomic. A repository that ignores `$version` leaves a race between the check and the
write: two concurrent requests with the same `If-Match` can both pass.

## Member deltas

By default a PATCH reads the complete resource, applies the operations and calls `replace()` with the complete
result. For a group with many members, each member add or remove thus reads and writes every member. Microsoft
Entra ID sends one member per PATCH to a non-gallery application. Implement `Repository\DeltaRepository` to change large multi-valued attributes
by delta instead:

```php
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Repository\DeltaRepository;
use TacticMedia\ScimServer\Repository\PatchResult;
use TacticMedia\ScimServer\Repository\ResourcePatch;

final class GroupRepository implements DeltaRepository
{
    public function deltaAttributes(): array
    {
        return [new AttributePath('urn:ietf:params:scim:schemas:core:2.0:Group', 'members')];
    }

    public function patch(string $id, ResourcePatch $patch): PatchResult
    {
        // One transaction: see the contract below.
    }

    // get(), list(), create(), replace() and delete() as for every repository.
}
```

Each attribute of `deltaAttributes()` must be multi-valued, complex, `readWrite` and not required, with a `value`
sub-attribute and without a `primary` sub-attribute. Else each PATCH of the type throws `\LogicException`. Return
`[]` to turn deltas off.

The server uses the delta path when each operation on these attributes is one of these forms:

| Operation | Step that the repository gets |
| --- | --- |
| `add` with `path` `members` | `Add` of the values |
| `remove` with `path` `members` and a `value` list (Entra) | `Remove` of the values |
| `remove` with `path` `members[value eq "<id>"]` (Entra, Okta) | `Remove` of `[{value: <id>}]` |
| `remove` with `path` `members` and no `value` | `Replace` with `[]` |
| `replace` with `path` `members` (Okta) | `Replace` with the values |

Each value has a string `value`. Any other operation on `members` sends the whole request to the full path. The
server then:

1. Calls `get($id, [], <deltaAttributes>)`. Leave out these attributes, and return `meta.version`.
2. Checks `If-Match` and `If-None-Match` against that document.
3. Applies the operations on the other attributes to that document, with all checks, and checks uniqueness.
4. Calls `patch($id, $patch)`, unless nothing changed and there is no step.
5. Answers 204 with the ETag of the returned document. With `attributes` or `excludedAttributes` it answers 200 and
   calls `get()` again with the projection.

Contract of `patch()`:

| Item | Rule |
| --- | --- |
| Transaction | Write `$patch->document` and all steps in one transaction. Nothing changes when `patch()` throws. |
| `$patch->document` | The complete, normalized new state without the delta attributes, as `replace()` gets it. Store it when it is not `null`. Keep the stored delta attribute values. |
| `$patch->steps` | `list<ValuesStep>`, in request order. `Add` inserts the values that are not present. `Remove` deletes the values that are present. `Replace` makes the values equal to `$step->values`. Identify a value by its `value`. A `type` limits it to resources of that type. A `value` can repeat. |
| `$patch->version` | Not `null`: throw `ScimException::preconditionFailed('Failed to update. Resource has changed on the server.')` when the stored `meta.version` is different. |
| Errors | `ScimException::notFound()` for an unknown id. `ScimException::invalidValue()` for a `value` of an `Add` or `Replace` step that names no resource. |
| `meta` | Change `meta.version` and `meta.lastModified` when the document or a value changed. Keep them when nothing changed. |
| Result | `new PatchResult($document, $changes)`: the stored document without the delta attributes and with `meta.version`, and one `ValuesChange` for each step that added or removed a value, with those values. Leave out steps without effect, so `$changes` is `[]` when no value changed. |

Events: a PATCH on the delta path dispatches `Event\ResourcePatched` (`type`, `before`, `after`, `changes`, `patch`)
instead of `ResourceUpdated`. `before` and `after` have no delta attributes. `changes` is the `PatchResult` list
without a `ValuesChange` that has no values. `before`, `after` and `patch` have no value that is never returned, as
[Events](#events) describes. The server dispatches it when the document changed or a value was added or removed. PUT
and PATCH on the full path still dispatch `ResourceUpdated`. A listener that must see every change listens to both
events.

Differences from the full path:

- The repository compares `value` with its own id rules. The full path compares exact strings.
- Without `If-Match`, two concurrent member changes do not remove the member of the other request.

## Custom schemas and resource types

```php
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\CoreSchemas;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Schema\SchemaExtension;
use TacticMedia\ScimServer\Schema\Uniqueness;

$acmeUser = new Schema('urn:example:scim:schemas:extension:acme:2.0:User', 'AcmeUser', 'Acme user', [
    new AttributeDefinition('badgeNumber', required: true, uniqueness: Uniqueness::Server),
    new AttributeDefinition('clearanceLevel', AttributeType::Integer),
]);

$users = new ResourceType('User', '/Users', CoreSchemas::user(), [
    new SchemaExtension(CoreSchemas::enterpriseUser()),
    new SchemaExtension($acmeUser, required: true),
], 'User Account');

$devices = new ResourceType('Device', '/Devices', new Schema('urn:example:scim:schemas:core:2.0:Device', 'Device', 'Device', [
    new AttributeDefinition('serialNumber', required: true, caseExact: true, uniqueness: Uniqueness::Server),
    new AttributeDefinition('owner', AttributeType::Complex, subAttributes: [
        new AttributeDefinition('value', required: true, caseExact: true),
        new AttributeDefinition('$ref', AttributeType::Reference, referenceTypes: ['User']),
    ]),
]));
```

- `AttributeDefinition` defaults: `string`, single-valued, not required, `caseExact` false, `readWrite`, `returned`
  `default`, `uniqueness` `none`. A `writeOnly` attribute is always `returned` `never`.
- Register each type with `new RegisteredResourceType($type, $repository)`. `/ResourceTypes` and `/Schemas` show
  them. `/Schemas` also shows the ServiceProviderConfig, ResourceType and Schema schemas (RFC 7643 section 8.7.2).
- Endpoint names match in any case. A fixed name such as `Schemas` or `Bulk` wins over a resource type endpoint.
- The server fills `owner.$ref` above, because `referenceTypes` names one resource type.

## Configuration

`ScimServer` takes a `ServiceProviderConfig` as its second argument:

```php
use TacticMedia\ScimServer\Server\AuthenticationScheme;
use TacticMedia\ScimServer\Server\ServiceProviderConfig;

$config = new ServiceProviderConfig(
    documentationUri: 'https://example.com/docs/scim',
    maxResults: 200,
    authenticationSchemes: [
        new AuthenticationScheme('oauthbearertoken', 'OAuth Bearer Token', 'Send an access token in the Authorization header.', primary: true),
    ],
    bulkMaxOperations: 100,
);
```

| Parameter | Default | Effect |
| --- | --- | --- |
| `documentationUri` | `null` | Shown in `/ServiceProviderConfig` when set. |
| `maxResults` | `100` | The largest page of a list or search, and the `count` when the client sends none. |
| `changePasswordSupported` | `false` | Shown in `/ServiceProviderConfig` only. |
| `authenticationSchemes` | `[]` | Shown in `/ServiceProviderConfig` only. The server does not authenticate. |
| `bulkSupported` | `true` | `false` makes `POST /Bulk` answer 501. |
| `bulkMaxOperations` | `1000` | More operations in one request: 413. |
| `bulkMaxPayloadSizeBytes` | `1048576` | A larger `Content-Length` header or body: 413. |

`/ServiceProviderConfig` always shows `patch`, `filter`, `sort` and `etag` as supported.

## Cursor pagination

The server supports RFC 9865 cursors when you give it a secret key. Without a key, it ignores `cursor`, and
`/ServiceProviderConfig` shows `"pagination": {"cursor": false, ...}`. The PHP extension `sodium` is required.

```php
$server = new ScimServer(
    $resourceTypes,
    new ServiceProviderConfig(maxResults: 200, cursorTimeoutSeconds: 3600),
    cursorKey: $cursorKey,
    clock: $clock,
);
```

| Argument | Default | Effect |
| --- | --- | --- |
| `cursorKey` of `ScimServer` | `null` | The secret that encrypts the cursors, for example 32 random bytes from your secret store. Use the same key in each process of the API. A new key makes all open cursors invalid. |
| `clock` of `ScimServer` | `null` | A PSR-20 clock for the cursor lifetime. `null` uses the system time. |
| `cursorTimeoutSeconds` of `ServiceProviderConfig` | `null` | The lifetime of a cursor. `null`: cursors do not expire. |

Requests:

- Index pagination stays the default. `GET /Users?cursor&count=100`, or `"cursor": ""` in a SearchRequest, asks
  for the first cursor page. For the next page, send the `nextCursor` of the response as `cursor`, with the same
  endpoint, `filter`, `sortBy` and `sortOrder`. `count`, `attributes` and `excludedAttributes` can change.
- An empty `cursor` with a `startIndex` uses index pagination. A non-empty `cursor` ignores `startIndex`.
- A cursor response has `nextCursor` on each page except the last, and no `startIndex`. `count=0` returns
  `totalResults` and no `nextCursor`. The server does not send `previousCursor`.
- GET and POST `.search` accept the cursors of each other.

| Error (400) | Cause |
| --- | --- |
| `invalidCursor` | The cursor is not from this server and key, or it belongs to another endpoint, `filter`, `sortBy` or `sortOrder`. The detail is always `The cursor is not valid for this query.` |
| `expiredCursor` | The cursor is older than `cursorTimeoutSeconds`. |
| `invalidCount` | `count` is not an integer. |

A cursor contains the offset, the position, a hash of the query and the time of issue, encrypted and authenticated
with XSalsa20-Poly1305. Clients cannot read or change it, and the server keeps no state. Each page runs the query
again, so your authorization and the current data apply to each page.

The position comes from the repository:

- A repository that implements only `ResourceRepository`: the cursor holds an offset, and the server calls `list()`.
  A create or delete before the position moves the page window, as with `startIndex`.
- A `CursorPaginatedRepository` pages by position (keyset). `listAfter(ListQuery $query, ?array $after)` gets the
  query with `startIndex` 1 and `$after` null for the first page. It returns a
  `CursorPage($resources, $totalResults, $next)`. `$next` is a list of JSON scalar values, for example the sort value
  and a unique id of the last resource, or `null` on the last page. `$totalResults` can be `null` when it is unknown;
  the response then omits it. Return `null` from `listAfter()` to page this query by offset. Throw
  `ScimException::invalidCursor()` for a position that you cannot use. `InMemoryResourceRepository` implements it.
  When a position would make the cursor longer than 4096 characters, for example with a long sort value, the cursor
  holds only the offset, and the rest of the traversal pages by offset.
- Queries across resource types (`/` and `/.search`) always use an offset, because the server merges their pages
  itself.

## Events

Give a PSR-14 event dispatcher, for example `symfony/event-dispatcher`, as the third argument of `ScimServer`.

| Event | Properties | Dispatched after |
| --- | --- | --- |
| `Event\ResourceCreated` | `type`, `resource` | `create()` |
| `Event\ResourceUpdated` | `type`, `before`, `after`, `patch` (`null` for PUT) | `replace()`, for PUT and for a PATCH that changes the resource |
| `Event\ResourceDeleted` | `type`, `resource` (the document before the delete) | `delete()` |

`resource`, `before` and `after` are the repository documents without the values that are never returned, such as
`password`. They have no `meta.resourceType` and no `meta.location`. `patch` is the PATCH request without these
values: an operation whose path names such an attribute has the value `null`, a value object loses the keys of such
attributes, and a complex value loses such sub-attributes. The server dispatches no event when the repository call
throws. A `ScimException` from a listener becomes the error response, but the write is already done.

## /Me

`ScimServer` takes a `Server\MeResolver` as its `me` argument. Without one, `/Me` answers 501.

```php
use Symfony\Component\HttpFoundation\Request;
use TacticMedia\ScimServer\Server\MeResolver;
use TacticMedia\ScimServer\Server\MeSubject;

final class UserNameMeResolver implements MeResolver
{
    public function resolve(Request $request): ?MeSubject
    {
        // Set by your authentication.
        $userName = $request->attributes->get('user_name');

        return \is_string($userName) ? new MeSubject('User', $userName, 'userName') : null;
    }
}

$server = new ScimServer($resourceTypes, me: new UserNameMeResolver());
```

- `new MeSubject($resourceType, $value, $attribute = 'id')` names the resource whose `$attribute` equals `$value`.
  For `id`, the server makes no query. For another attribute, it calls `list()` with an `eq` filter, so the
  attribute must be filterable. A resource type that is not registered, or an attribute that it cannot filter,
  throws `\LogicException`.
- `null` or no match answers 404. More than one match answers 409. A `ScimException` from `resolve()` becomes the
  response, for example 403.
- GET, HEAD, PUT, PATCH and DELETE act on the resource as on `/Users/{id}`. Each response after the lookup has
  `Location` set to the URL of the resource. POST answers 501.
- A write through `/Me` has the rights of a `/Users/{id}` request: an end user can change `active`, `userName` or
  `roles`, or delete the resource. Thus allow only GET and HEAD on `/Me` for end users.

## Endpoints and features

| Path | Methods |
| --- | --- |
| `/` | GET, HEAD: query across all resource types |
| `/.search` | POST: search across all resource types |
| `/{endpoint}` | GET, HEAD, POST |
| `/{endpoint}/.search` | POST |
| `/{endpoint}/{id}` | GET, HEAD, PUT, PATCH, DELETE |
| `/ServiceProviderConfig` | GET, HEAD |
| `/ResourceTypes`, `/ResourceTypes/{name}` | GET, HEAD |
| `/Schemas`, `/Schemas/{urn}` | GET, HEAD |
| `/Bulk` | POST |
| `/Me` | GET, HEAD, PUT, PATCH, DELETE with a `MeResolver`, else 501. POST: 501. See [/Me](#me). |

An unknown path answers 404. A known path with another method answers 405 with an `Allow` header.

- Filters: the grammar of RFC 7644 Figure 1 with errata 4670, 4690, 7319 and 7322. Keywords and operators match in
  any case. Value paths such as `emails[type eq "work" and value co "@example.com"]` work.
- `caseExact` false compares with Unicode case folding.
- Lists: `sortBy`, `sortOrder`, `startIndex` and `count`. An empty result answers 200 with `"Resources": []`.
- Root queries: in a query across resource types, an attribute that a type does not define has no value for that
  type.
- PATCH: `add`, `remove` and `replace`, with or without `path`. Paths can have a value filter and a sub-attribute,
  for example `emails[type eq "work"].value`. All operations of one request apply, or none. PATCH answers 204.
  With `attributes` or `excludedAttributes` in the query, it answers 200 with the resource.
- Bulk: `bulkId` references in `data` at any depth, also to a later POST. `failOnErrors` stops after the given
  number of failed operations.
- Bulk resolves circular `bulkId` references (RFC 7644 section 3.7.1). It takes the first POST of the cycle, in
  request order, that has no such reference in a required attribute. It creates that resource without the
  references, then adds them with a PATCH. When each POST of the cycle has its reference in a required attribute,
  the first POST fails with 409, and the POSTs that reference it fail with 409 too.
- ETags: each returned resource has `meta.version`, and single-resource responses have an `ETag` header. GET, PUT,
  PATCH and DELETE of a resource evaluate `If-Match` and `If-None-Match` and can answer 304 or 412.
- HEAD works wherever GET works.
- Discovery endpoints answer a `filter` with 403 and ignore the other query parameters.
- Responses are `application/scim+json`, or `application/json` when `Accept` names `application/json` and not
  `application/scim+json`. Request bodies can be `application/scim+json`, `application/json` or have no
  `Content-Type`.

## Interoperability

The monorepo tests replay request sequences of Microsoft Entra ID, Okta, Keycloak, PingOne, OneLogin and JumpCloud.
The server accepts these quirks:

- `op` in any case, and `"True"` or `"False"` as booleans.
- PATCH without `path` whose keys are paths, for example `name.givenName` or a URN-qualified name.
- A PATCH `path` that is an extension URN.
- A value filter path that matches no value. The server creates the value from the `eq` terms of the filter.
- `manager` as an id string, an object or a list with one object. `""` and `null` clear it.
- `remove` with a `value` list removes only those values. `add` of a value that is present changes nothing.
- PUT built from GET output. The stored `password` stays.
- `members[].display`. The server drops it, because it is `readOnly`.
- A base URL with a trailing slash (`//Users`), and unknown query parameters.

The server does not accept unquoted filter values (`userName eq bjensen`). It answers 400 `invalidFilter`, because
RFC 7644 Figure 1 requires a JSON value.

PATCH `replace` of a single-valued complex attribute, for example `name`, merges the sub-attributes, as RFC 7644
section 3.5.2.3 requires. A client that expects a full replacement finds the omitted sub-attributes unchanged.

## Limits and deviations from RFC 7643 and RFC 7644

- `manager.$ref` is optional, because Microsoft Entra ID sends only the manager id. Erratum 8359 makes it required.
- Group `members.display` is `readOnly` (erratum 6011). Group `members.value` is `caseExact`, because ids are case
  exact.
- Group `displayName` has `uniqueness` `server`, unlike RFC 7643 Figure 9 (`none`), because Microsoft Entra ID
  expects 409 for a duplicate group name. A duplicate name answers 409 `uniqueness`. To accept duplicate names,
  register a Group schema whose `displayName` has `Uniqueness::None`.
- The server checks `uniqueness` `global` only in the repository of the same resource type.
- A `filter` or `sortBy` on an attribute that is never returned, for example `password`, answers 400. A match or an
  order could reveal the value.
- A filter longer than 8192 bytes, or with more than 64 nested levels of `(` and `[`, answers 400 `invalidFilter`.
- `schemas` is not an attribute in filters.
- Cursor pagination (RFC 9865) needs `cursorKey`. The server does not send `previousCursor`. An offset cursor moves
  when a resource before its position is created or deleted, as `startIndex` does.
- A root query with `sortBy` loads `startIndex - 1 + count` resources from each resource type.
- `If-Match` uses the weak comparison of ETags.

## Contributing

This repository is a read-only split of the [tacticmedia/scim](https://github.com/tacticmedia/scim) monorepo,
directory `scim-server/`. Send issues and pull requests to the monorepo.

## License

MIT. See [LICENSE](https://github.com/tacticmedia/scim-server/blob/main/LICENSE).
