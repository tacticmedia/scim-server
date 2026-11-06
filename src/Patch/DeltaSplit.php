<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Patch;

use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Repository\ValuesStep;

/**
 * @internal
 */
final readonly class DeltaSplit
{
    /**
     * @param array<int, PatchOperation> $operations      the operations on other attributes, by index in the request
     * @param list<ValuesStep>           $steps
     * @param list<AttributePath>        $deltaAttributes URN-qualified, in schema spelling
     */
    public function __construct(
        public array $operations,
        public array $steps,
        public array $deltaAttributes,
    ) {
    }
}
