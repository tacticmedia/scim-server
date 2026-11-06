<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Schema;

/** @see https://www.rfc-editor.org/rfc/rfc7643#section-7 */
final readonly class Schema
{
    /**
     * @param list<AttributeDefinition> $attributes
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public array $attributes,
    ) {
    }

    public function attribute(string $name): ?AttributeDefinition
    {
        return array_find($this->attributes, static fn (AttributeDefinition $attribute): bool => 0 === strcasecmp($attribute->name, $name));
    }

    /**
     * @return array{id: string, name: string, description: string, attributes: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'attributes' => array_map(static fn (AttributeDefinition $attribute): array => $attribute->toArray(), $this->attributes),
        ];
    }
}
