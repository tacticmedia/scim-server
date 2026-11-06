<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Patch;

final readonly class PatchOperation
{
    public function __construct(
        public PatchOperationType $type,
        public ?string $path = null,
        public mixed $value = null,
    ) {
    }
}
