<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Schema;

use TacticMedia\ScimServer\Path\AttributePath;

final readonly class ResolvedAttribute
{
    public function __construct(
        public Schema $schema,
        public AttributeDefinition $attribute,
        public ?AttributeDefinition $subAttribute = null,
    ) {
    }

    public function definition(): AttributeDefinition
    {
        return $this->subAttribute ?? $this->attribute;
    }

    /**
     * The path in schema spelling, always qualified with the schema URN.
     */
    public function path(): AttributePath
    {
        return new AttributePath($this->schema->id, $this->attribute->name, $this->subAttribute?->name);
    }
}
