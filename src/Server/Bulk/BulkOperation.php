<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server\Bulk;

use Symfony\Component\HttpFoundation\Request;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\SchemaExtension;
use TacticMedia\ScimServer\Server\Endpoint;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\Route;
use TacticMedia\ScimServer\Server\Router;

/**
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.7
 */
final readonly class BulkOperation
{
    private const string REFERENCE_PREFIX = 'bulkId:';

    /**
     * @param int         $index   the position in "Operations"
     * @param string      $method  POST, PUT, PATCH or DELETE
     * @param string|null $bulkId  only for POST
     * @param string|null $version the ETag that the resource must match (If-Match)
     */
    public function __construct(
        public int $index,
        public string $method,
        public string $path,
        public ?string $bulkId,
        public ?string $version,
        public mixed $data,
    ) {
    }

    /**
     * The bulkIds that a string value "bulkId:<bulkId>" anywhere in "data" references (RFC 7644 section 3.7.2).
     *
     * @return list<string>
     */
    public function references(): array
    {
        return self::referencesIn($this->data);
    }

    /**
     * The operation without the attributes whose value references one of $bulkIds, and those attributes. An attribute
     * is a member of "data" or of an extension object in "data".
     *
     * @param list<string> $bulkIds
     *
     * @return array{self, array<mixed>}
     */
    public function withoutReferencesTo(array $bulkIds, ResourceType $type): array
    {
        if (!$this->isObject($this->data)) {
            return [$this, []];
        }

        [$data, $removed] = $this->partition($this->data, $bulkIds);

        foreach ($removed as $key => $value) {
            if ($type->extension((string) $key) instanceof SchemaExtension) {
                [$data[$key], $removed[$key]] = $this->partitionExtension($value, $bulkIds);
            }
        }

        $without = new self($this->index, $this->method, $this->path, $this->bulkId, $this->version, $data);

        return [$without, array_filter($removed, static fn (mixed $value): bool => [] !== $value)];
    }

    /**
     * @return array{Route, RegisteredResourceType}
     *
     * @throws ScimException 404 when the path does not route, invalidSyntax when it routes to no resource type endpoint
     *                       (POST) or resource (others)
     */
    public function route(Router $router): array
    {
        $route = $router->match('/'.ltrim($this->path, '/'), '')
            ?? throw ScimException::notFound(\sprintf('The endpoint %s does not exist.', $this->path));
        $isPost = 'POST' === $this->method;

        if ($route->endpoint !== ($isPost ? Endpoint::Collection : Endpoint::Resource) || !$route->resourceType instanceof RegisteredResourceType) {
            throw ScimException::invalidSyntax(\sprintf('%s needs the path of %s.', $this->method, $isPost ? 'a resource type endpoint, for example "/Users"' : 'a resource, for example "/Users/<id>"'));
        }

        return [$route, $route->resourceType];
    }

    /**
     * The single request of the operation, without the checks of {@see BulkRun}.
     *
     * @param array<string, string> $ids the id of each created resource by bulkId
     *
     * @throws ScimException invalidValue when "data" holds a number beyond the float range, which decodes to INF
     */
    public function toRequest(array $ids): Request
    {
        $data = self::withIds($this->data, $ids);

        try {
            // json_decode() gives [] for {}.
            $content = [] === $data ? '{}' : json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException $jsonException) {
            throw ScimException::invalidValue(\sprintf('"data" cannot be encoded as JSON: %s.', $jsonException->getMessage()));
        }

        $request = Request::create('', $this->method, content: $content);
        $request->headers->set('Content-Type', 'application/scim+json');

        if (null !== $this->version && 'POST' !== $this->method) {
            $request->headers->set('If-Match', $this->version);
        }

        return $request;
    }

    /**
     * @return list<string>
     */
    private static function referencesIn(mixed $value): array
    {
        $values = [$value];
        $references = [];
        array_walk_recursive($values, static function (mixed $value) use (&$references): void {
            if (\is_string($value) && str_starts_with($value, self::REFERENCE_PREFIX)) {
                $references[] = substr($value, \strlen(self::REFERENCE_PREFIX));
            }
        });

        return array_values(array_unique($references));
    }

    /**
     * Only a string key can name an attribute.
     *
     * @param array<mixed> $object
     * @param list<string> $bulkIds
     *
     * @return array{array<mixed>, array<mixed>} the members without and with a reference to one of $bulkIds
     */
    private function partition(array $object, array $bulkIds): array
    {
        $removed = array_filter(
            $object,
            static fn (mixed $value, int|string $key): bool => \is_string($key) && [] !== array_intersect(self::referencesIn($value), $bulkIds),
            \ARRAY_FILTER_USE_BOTH,
        );

        return [array_diff_key($object, $removed), $removed];
    }

    /**
     * The create rejects an extension that is not an object, so such a value stays.
     *
     * @param list<string> $bulkIds
     *
     * @return array{mixed, array<mixed>}
     */
    private function partitionExtension(mixed $value, array $bulkIds): array
    {
        return $this->isObject($value) ? $this->partition($value, $bulkIds) : [$value, []];
    }

    /**
     * @phpstan-assert-if-true array<mixed> $value
     */
    private function isObject(mixed $value): bool
    {
        return \is_array($value) && !array_is_list($value);
    }

    /**
     * @param array<string, string> $ids
     */
    private static function withIds(mixed $value, array $ids): mixed
    {
        return match (true) {
            \is_array($value) => array_map(static fn (mixed $item): mixed => self::withIds($item, $ids), $value),
            \is_string($value) && str_starts_with($value, self::REFERENCE_PREFIX) => $ids[substr($value, \strlen(self::REFERENCE_PREFIX))] ?? $value,
            default => $value,
        };
    }
}
