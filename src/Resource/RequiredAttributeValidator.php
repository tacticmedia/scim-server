<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Resource;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * The server assigns readOnly attributes, so they are never required from a client. An extension is checked when
 * the resource type requires it or when it has values. "" does not satisfy a required attribute.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-6
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.5.1
 */
final class RequiredAttributeValidator
{
    /**
     * @param array<string, array<string, mixed>> $values normalized values keyed by schema id, then attribute name
     *
     * @throws ScimException invalidValue
     */
    public function validate(array $values, ResourceType $type): void
    {
        foreach (ResourceAttributes::all($type) as $attribute) {
            if ($this->applies($attribute, $values, $type)) {
                $this->validateAttribute($values[$attribute->schema->id][$attribute->attribute->name] ?? null, $attribute, $type);
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $values
     */
    private function applies(ResolvedAttribute $attribute, array $values, ResourceType $type): bool
    {
        $schemaId = $attribute->schema->id;

        return Mutability::ReadOnly !== $attribute->attribute->mutability
            && ($schemaId === $type->schema->id || isset($values[$schemaId]) || true === $type->extension($schemaId)?->required);
    }

    private function validateAttribute(mixed $value, ResolvedAttribute $attribute, ResourceType $type): void
    {
        $label = ResourceAttributes::label($attribute, $type);

        if ($attribute->attribute->required && self::isMissing($value)) {
            throw ScimException::invalidValue(\sprintf('Attribute "%s" is required.', $label));
        }

        $elements = match (true) {
            null === $value => [],
            $attribute->attribute->multiValued && \is_array($value) => $value,
            default => [$value],
        };

        foreach ($elements as $element) {
            $this->validateSubAttributes($element, $attribute->attribute, $label);
        }
    }

    private function validateSubAttributes(mixed $element, AttributeDefinition $definition, string $label): void
    {
        $missing = array_find(
            $definition->subAttributes,
            static fn (AttributeDefinition $subAttribute): bool => $subAttribute->required
                && Mutability::ReadOnly !== $subAttribute->mutability
                && self::isMissing(\is_array($element) ? $element[$subAttribute->name] ?? null : null),
        );

        if (null !== $missing) {
            throw ScimException::invalidValue(\sprintf('Attribute "%s.%s" is required.', $label, $missing->name));
        }
    }

    /**
     * RFC 7643 section 4.1.1 requires a non-empty userName, section 3.1 a non-empty id.
     */
    private static function isMissing(mixed $value): bool
    {
        return null === $value || '' === $value;
    }
}
