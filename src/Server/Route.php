<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

/**
 * @internal
 */
final readonly class Route
{
    /**
     * @param string $id the decoded last path segment of a single resource, resource type or schema
     */
    public function __construct(
        public Endpoint $endpoint,
        public ?RegisteredResourceType $resourceType = null,
        public string $id = '',
    ) {
    }
}
