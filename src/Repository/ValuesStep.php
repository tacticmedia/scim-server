<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Repository;

use TacticMedia\ScimServer\Patch\PatchOperationType;
use TacticMedia\ScimServer\Path\AttributePath;

/**
 * A set operation on the values of a delta attribute. A value is identified by its "value" sub-attribute, and a
 * "type" sub-attribute restricts it to resources of that type. Add inserts the values that are not present, Remove
 * deletes the values that are present, Replace makes the values equal to $values.
 */
final readonly class ValuesStep
{
    /**
     * @param AttributePath              $path   URN-qualified, in schema spelling
     * @param list<array<string, mixed>> $values normalized, each with a string "value", which can repeat; a Replace
     *                                           step with [] unassigns the attribute
     */
    public function __construct(
        public AttributePath $path,
        public PatchOperationType $type,
        public array $values,
    ) {
    }
}
