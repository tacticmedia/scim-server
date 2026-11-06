<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Resource;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * Applies the readOnly and immutable rules of a PUT to the normalized input. An absent or null immutable value keeps
 * the stored one, and a stored value that normalizes to unassigned takes the input.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.5.1
 */
final readonly class ReplacementMerger
{
    public function __construct(
        private ValueNormalizer $values,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $values   normalized input keyed by schema id, then attribute name
     * @param array<string, mixed>                $existing the stored document
     *
     * @return array<string, array<string, mixed>> in {@see ResourceAttributes::all()} order
     *
     * @throws ScimException mutability
     */
    public function merge(array $values, array $existing, ResourceType $type): array
    {
        $stored = $this->storedValues($existing, $type);
        $merged = [];

        foreach (ResourceAttributes::all($type) as $attribute) {
            $schemaId = $attribute->schema->id;
            $name = $attribute->attribute->name;
            $value = $this->mergedValue($values[$schemaId][$name] ?? null, $stored[$schemaId][$name] ?? null, $attribute, $type);

            if (null !== $value) {
                $merged[$schemaId][$name] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $existing
     *
     * @return array<string, array<mixed>>
     */
    private function storedValues(array $existing, ResourceType $type): array
    {
        $stored = [$type->schema->id => $existing];

        foreach ($type->schemaExtensions as $extension) {
            $container = $existing[$extension->schema->id] ?? null;
            $stored[$extension->schema->id] = \is_array($container) ? $container : [];
        }

        return $stored;
    }

    private function mergedValue(mixed $input, mixed $stored, ResolvedAttribute $attribute, ResourceType $type): mixed
    {
        return match ($attribute->attribute->mutability) {
            Mutability::ReadOnly => $stored,
            Mutability::Immutable => $this->immutableValue($input, $stored, $attribute, $type),
            default => $input,
        };
    }

    /**
     * Compares with the stored value normalized as the input is, and keeps the stored value with its readOnly
     * sub-attributes.
     */
    private function immutableValue(mixed $input, mixed $stored, ResolvedAttribute $attribute, ResourceType $type): mixed
    {
        $label = ResourceAttributes::label($attribute, $type);
        $normalizedStored = $this->values->normalize($stored, $attribute->attribute, $label);

        if (null !== $input && null !== $normalizedStored && !ValueEquality::same($input, $normalizedStored)) {
            throw ScimException::mutability(\sprintf('Attribute "%s" is immutable.', $label));
        }

        return null === $normalizedStored ? $input : $stored;
    }
}
