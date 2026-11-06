<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Schema\CoreSchemas;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\SchemaUrn;

/**
 * /ServiceProviderConfig, /ResourceTypes and /Schemas. A filter gets 403, the other query parameters are ignored.
 * /Schemas lists the schemas of the registered resource types, then the ServiceProviderConfig, ResourceType and Schema
 * schemas.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-4
 */
final readonly class DiscoveryHandler
{
    /**
     * @param list<RegisteredResourceType> $resourceTypes
     */
    public function __construct(
        private array $resourceTypes,
        private ServiceProviderConfig $config,
        private ResponseFactory $responses,
        private bool $cursorSupported = false,
    ) {
    }

    /**
     * @throws ScimException
     */
    public function handle(Request $request, string $baseUrl, Route $route): Response
    {
        if (new RequestParameters($request->query->all())->has('filter')) {
            throw new ScimException(Response::HTTP_FORBIDDEN, 'The discovery endpoints do not support filtering.');
        }

        return match ($route->endpoint) {
            Endpoint::ServiceProviderConfig => $this->responses->json($request, $this->serviceProviderConfig($baseUrl)),
            Endpoint::ResourceTypes => $this->list($request, array_map(fn (ResourceType $type): array => $this->resourceType($type, $baseUrl), $this->types())),
            Endpoint::ResourceType => $this->responses->json($request, $this->resourceType($this->findType($route->id), $baseUrl)),
            Endpoint::Schemas => $this->list($request, array_map(fn (Schema $schema): array => $this->schema($schema, $baseUrl), $this->schemas())),
            default => $this->responses->json($request, $this->schema($this->findSchema($route->id), $baseUrl)),
        };
    }

    /**
     * @param list<array<string, mixed>> $resources
     */
    private function list(Request $request, array $resources): Response
    {
        return $this->responses->list($request, $resources, \count($resources));
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceProviderConfig(string $baseUrl): array
    {
        return [
            'schemas' => [SchemaUrn::ServiceProviderConfig->value],
            ...$this->config->toArray($this->cursorSupported),
            'meta' => ['resourceType' => 'ServiceProviderConfig', 'location' => $baseUrl.'/ServiceProviderConfig'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceType(ResourceType $type, string $baseUrl): array
    {
        return [
            'schemas' => [SchemaUrn::ResourceType->value],
            ...$type->toArray(),
            'meta' => ['resourceType' => 'ResourceType', 'location' => $baseUrl.'/ResourceTypes/'.rawurlencode($type->name)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(Schema $schema, string $baseUrl): array
    {
        return [
            'schemas' => [SchemaUrn::Schema->value],
            ...$schema->toArray(),
            'meta' => ['resourceType' => 'Schema', 'location' => $baseUrl.'/Schemas/'.str_replace('%3A', ':', rawurlencode($schema->id))],
        ];
    }

    /**
     * @return list<ResourceType>
     */
    private function types(): array
    {
        return array_map(static fn (RegisteredResourceType $registered): ResourceType => $registered->type, $this->resourceTypes);
    }

    /**
     * Each schema once, also when several resource types use it.
     *
     * @return list<Schema>
     */
    private function schemas(): array
    {
        $resourceSchemas = array_merge(...array_map(static fn (ResourceType $type): array => $type->schemas(), $this->types()));
        $unique = [];

        foreach ([...$resourceSchemas, CoreSchemas::serviceProviderConfig(), CoreSchemas::resourceType(), CoreSchemas::schema()] as $schema) {
            $unique[strtolower($schema->id)] ??= $schema;
        }

        return array_values($unique);
    }

    private function findType(string $name): ResourceType
    {
        return array_find($this->types(), static fn (ResourceType $type): bool => 0 === strcasecmp($type->name, $name))
            ?? throw ScimException::notFound(\sprintf('Resource type %s not found.', $name));
    }

    private function findSchema(string $id): Schema
    {
        return array_find($this->schemas(), static fn (Schema $schema): bool => 0 === strcasecmp($schema->id, $id))
            ?? throw ScimException::notFound(\sprintf('Schema %s not found.', $id));
    }
}
