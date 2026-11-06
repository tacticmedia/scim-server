<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Repository;

use TacticMedia\ScimServer\Path\AttributePath;

/**
 * The values that a delta added to or removed from an attribute.
 */
final readonly class ValuesChange
{
    /**
     * @param AttributePath              $path    URN-qualified, in schema spelling
     * @param list<array<string, mixed>> $added
     * @param list<array<string, mixed>> $removed
     */
    public function __construct(
        public AttributePath $path,
        public array $added,
        public array $removed,
    ) {
    }
}
