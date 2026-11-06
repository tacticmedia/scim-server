<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Filter;

use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * Reads attribute values from a document in schema spelling. Every list it returns omits null.
 *
 * @internal
 */
final class DocumentValues
{
    /**
     * The values of the attribute, or of its sub-attribute in every element.
     *
     * @param array<string, mixed> $document
     *
     * @return list<mixed>
     */
    public static function of(ResolvedAttribute $attribute, array $document, ResourceType $type): array
    {
        $elements = self::elements($attribute, $document, $type);
        $subAttribute = $attribute->subAttribute;

        if (!$subAttribute instanceof AttributeDefinition) {
            return $elements;
        }

        return array_merge(...array_map(
            static fn (mixed $element): array => \is_array($element) ? self::valuesOf($element[$subAttribute->name] ?? null, $subAttribute) : [],
            $elements,
        ));
    }

    /**
     * The values of the attribute itself: the elements of a multi-valued attribute, or the one value.
     *
     * @param array<string, mixed> $document
     *
     * @return list<mixed>
     */
    public static function elements(ResolvedAttribute $attribute, array $document, ResourceType $type): array
    {
        $container = $attribute->schema->id === $type->schema->id ? $document : ($document[$attribute->schema->id] ?? null);

        return self::valuesOf(\is_array($container) ? ($container[$attribute->attribute->name] ?? null) : null, $attribute->attribute);
    }

    /**
     * @return list<mixed>
     */
    public static function valuesOf(mixed $node, AttributeDefinition $definition): array
    {
        if (!$definition->multiValued) {
            $node = [$node];
        }

        return \is_array($node) ? array_values(array_filter($node, static fn (mixed $value): bool => null !== $value)) : [];
    }

    public static function isPresent(mixed $value): bool
    {
        return \is_array($value) ? array_any($value, self::isPresent(...)) : null !== $value && '' !== $value;
    }
}
