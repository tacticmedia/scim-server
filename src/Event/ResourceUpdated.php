<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Event;

use TacticMedia\ScimServer\Patch\PatchRequest;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * Dispatched after the repository replaced the resource, for PUT and for a PATCH that changed it. The documents and
 * the PATCH request have no value that is never returned, such as "password": an operation whose path names such an
 * attribute has the value null.
 */
final readonly class ResourceUpdated
{
    /**
     * @param array<string, mixed> $before the stored document
     * @param array<string, mixed> $after  the document that the repository returned
     * @param PatchRequest|null    $patch  null for PUT
     */
    public function __construct(
        public ResourceType $type,
        public array $before,
        public array $after,
        public ?PatchRequest $patch,
    ) {
    }
}
