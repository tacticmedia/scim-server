<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Event;

use TacticMedia\ScimServer\Patch\PatchRequest;
use TacticMedia\ScimServer\Repository\DeltaRepository;
use TacticMedia\ScimServer\Repository\ValuesChange;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * Dispatched after {@see DeltaRepository::patch()} for a PATCH that changed the resource. The documents have no delta
 * attributes. The documents and the PATCH request have no value that is never returned, such as "password", as in
 * {@see ResourceUpdated}. A PATCH through a repository without deltas dispatches {@see ResourceUpdated}.
 */
final readonly class ResourcePatched
{
    /**
     * @param array<string, mixed> $before  the stored document
     * @param array<string, mixed> $after   the document that the repository returned
     * @param list<ValuesChange>   $changes the values that the repository added and removed; no change without values
     */
    public function __construct(
        public ResourceType $type,
        public array $before,
        public array $after,
        public array $changes,
        public PatchRequest $patch,
    ) {
    }
}
