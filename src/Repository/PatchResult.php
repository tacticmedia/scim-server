<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Repository;

/**
 * @see DeltaRepository::patch()
 */
final readonly class PatchResult
{
    /**
     * @param array<string, mixed> $document the stored document without the delta attributes, with "meta.version"
     * @param list<ValuesChange>   $changes  the effective changes; [] when no value was added or removed
     */
    public function __construct(
        public array $document,
        public array $changes,
    ) {
    }
}
