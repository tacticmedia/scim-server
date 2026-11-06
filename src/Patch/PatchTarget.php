<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Patch;

use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;

/**
 * The attribute or sub-attribute that a PATCH path names, and the resolved value filter that selects values of the
 * attribute.
 *
 * @internal
 */
final readonly class PatchTarget
{
    public function __construct(
        public ResolvedAttribute $attribute,
        public ?Filter $valueFilter = null,
    ) {
    }

    /**
     * The attribute without the sub-attribute.
     */
    public function whole(): ResolvedAttribute
    {
        return new ResolvedAttribute($this->attribute->schema, $this->attribute->attribute);
    }

    public function isWholeList(): bool
    {
        return $this->attribute->attribute->multiValued
            && !$this->attribute->subAttribute instanceof AttributeDefinition
            && !$this->valueFilter instanceof Filter;
    }
}
