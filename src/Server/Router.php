<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

/**
 * Endpoint names match without regard to case. Empty path segments are ignored, so a trailing slash and "//Users"
 * (Microsoft Entra ID with a Tenant URL that ends in "/") match too.
 *
 * @internal
 */
final readonly class Router
{
    /**
     * @param list<RegisteredResourceType> $resourceTypes
     */
    public function __construct(
        private array $resourceTypes,
    ) {
    }

    /**
     * @param string $basePath without a trailing slash
     */
    public function match(string $pathInfo, string $basePath): ?Route
    {
        if (!str_starts_with($pathInfo.'/', $basePath.'/')) {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', substr($pathInfo, \strlen($basePath))),
            static fn (string $segment): bool => '' !== $segment,
        ));

        return match (\count($segments)) {
            0 => new Route(Endpoint::Root),
            1 => $this->matchOne(rawurldecode($segments[0])),
            2 => $this->matchTwo(rawurldecode($segments[0]), rawurldecode($segments[1])),
            default => null,
        };
    }

    private function matchOne(string $segment): ?Route
    {
        $endpoint = Endpoint::named($segment);

        if ($endpoint instanceof Endpoint) {
            return new Route($endpoint);
        }

        $resourceType = $this->resourceType($segment);

        return $resourceType instanceof RegisteredResourceType ? new Route(Endpoint::Collection, $resourceType) : null;
    }

    private function matchTwo(string $first, string $id): ?Route
    {
        $endpoint = match (Endpoint::named($first)) {
            Endpoint::ResourceTypes => Endpoint::ResourceType,
            Endpoint::Schemas => Endpoint::Schema,
            default => null,
        };

        if ($endpoint instanceof Endpoint) {
            return new Route($endpoint, id: $id);
        }

        $resourceType = $this->resourceType($first);

        return match (true) {
            !$resourceType instanceof RegisteredResourceType => null,
            0 === strcasecmp($id, '.search') => new Route(Endpoint::CollectionSearch, $resourceType),
            default => new Route(Endpoint::Resource, $resourceType, $id),
        };
    }

    private function resourceType(string $segment): ?RegisteredResourceType
    {
        return array_find(
            $this->resourceTypes,
            static fn (RegisteredResourceType $registered): bool => 0 === strcasecmp(ltrim($registered->type->endpoint, '/'), $segment),
        );
    }
}
