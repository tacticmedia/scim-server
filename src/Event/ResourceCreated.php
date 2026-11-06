<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Event;

use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * Dispatched after the repository created the resource. The document has no value that is never returned, such as
 * "password".
 */
final readonly class ResourceCreated
{
    /**
     * @param array<string, mixed> $resource the document that the repository returned
     */
    public function __construct(
        public ResourceType $type,
        public array $resource,
    ) {
    }
}
