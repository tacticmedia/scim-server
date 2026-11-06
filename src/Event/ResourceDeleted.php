<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Event;

use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * Dispatched after the repository deleted the resource. The document has no value that is never returned, such as
 * "password".
 */
final readonly class ResourceDeleted
{
    /**
     * @param array<string, mixed> $resource the document before the delete
     */
    public function __construct(
        public ResourceType $type,
        public array $resource,
    ) {
    }
}
