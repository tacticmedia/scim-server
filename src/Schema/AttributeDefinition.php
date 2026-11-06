<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Schema;

/** @see https://www.rfc-editor.org/rfc/rfc7643#section-7 */
final readonly class AttributeDefinition
{
    /** {@see Returned::Never} for a writeOnly attribute: its values SHALL NOT be returned. */
    public Returned $returned;

    /**
     * @param list<string>              $canonicalValues
     * @param list<string>              $referenceTypes
     * @param list<AttributeDefinition> $subAttributes
     */
    public function __construct(
        public string $name,
        public AttributeType $type = AttributeType::String,
        public bool $multiValued = false,
        public string $description = '',
        public bool $required = false,
        public bool $caseExact = false,
        public Mutability $mutability = Mutability::ReadWrite,
        Returned $returned = Returned::Default,
        public Uniqueness $uniqueness = Uniqueness::None,
        public array $canonicalValues = [],
        public array $referenceTypes = [],
        public array $subAttributes = [],
    ) {
        $this->returned = Mutability::WriteOnly === $mutability ? Returned::Never : $returned;
    }

    public function subAttribute(string $name): ?self
    {
        return array_find($this->subAttributes, static fn (self $subAttribute): bool => 0 === strcasecmp($subAttribute->name, $name));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $definition = [
            'name' => $this->name,
            'type' => $this->type->value,
            'multiValued' => $this->multiValued,
            'description' => $this->description,
            'required' => $this->required,
            'caseExact' => $this->caseExact,
            'mutability' => $this->mutability->value,
            'returned' => $this->returned->value,
            'uniqueness' => $this->uniqueness->value,
        ];

        if ([] !== $this->canonicalValues) {
            $definition['canonicalValues'] = $this->canonicalValues;
        }

        if (AttributeType::Reference === $this->type) {
            $definition['referenceTypes'] = $this->referenceTypes;
        }

        if (AttributeType::Complex === $this->type) {
            $definition['subAttributes'] = array_map(static fn (self $subAttribute): array => $subAttribute->toArray(), $this->subAttributes);
        }

        return $definition;
    }
}
