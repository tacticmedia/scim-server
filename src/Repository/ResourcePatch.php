<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Repository;

/**
 * @see DeltaRepository::patch()
 */
final readonly class ResourcePatch
{
    /**
     * @param array<string, mixed>|null $document the complete, normalized new state without the delta attributes, as
     *                                            replace() gets it; null keeps the stored state
     * @param list<ValuesStep>          $steps    in request order
     * @param string|null               $version  the "meta.version" that the resource must still have; null: no
     *                                            condition
     */
    public function __construct(
        public ?array $document,
        public array $steps,
        public ?string $version,
    ) {
    }
}
