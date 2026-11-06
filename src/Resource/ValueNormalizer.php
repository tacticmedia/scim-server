<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Resource;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;

/**
 * Normalizes the value of one attribute. It drops the readOnly sub-attributes of an attribute that is not readOnly and
 * does not check required ones.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-2.4
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-2.5
 */
final readonly class ValueNormalizer
{
    private ScalarNormalizer $scalars;

    public function __construct()
    {
        $this->scalars = new ScalarNormalizer();
    }

    /**
     * @return mixed null when the value is unassigned
     *
     * @throws ScimException invalidValue, or invalidSyntax for a sub-attribute given twice
     */
    public function normalize(mixed $value, AttributeDefinition $definition, string $path): mixed
    {
        if (null === $value) {
            return null;
        }

        return $definition->multiValued
            ? $this->normalizeList($value, $definition, $path)
            : $this->normalizeSingle($value, $definition, $path);
    }

    /**
     * @return non-empty-list<mixed>|null
     */
    private function normalizeList(mixed $value, AttributeDefinition $definition, string $path): ?array
    {
        $elements = array_values(array_filter(
            array_map(fn (mixed $element): mixed => $this->normalizeElement($element, $definition, $path), $this->asList($value, $definition, $path)),
            static fn (mixed $element): bool => null !== $element,
        ));

        return [] === $elements ? null : $this->keepFirstPrimary($elements, $definition);
    }

    /**
     * @return list<mixed>
     */
    private function asList(mixed $value, AttributeDefinition $definition, string $path): array
    {
        if (\is_array($value) && array_is_list($value)) {
            return $value;
        }

        if (\is_array($value) && AttributeType::Complex === $definition->type) {
            return [$value];
        }

        throw ScimException::invalidValue(\sprintf('Attribute "%s" must be a list.', $path));
    }

    private function normalizeSingle(mixed $value, AttributeDefinition $definition, string $path): mixed
    {
        // Microsoft Entra ID sends a single-valued complex attribute such as manager as a list with one object.
        if (AttributeType::Complex === $definition->type && \is_array($value) && [0] === array_keys($value)) {
            $value = $value[0];
        }

        return $this->normalizeElement($value, $definition, $path);
    }

    private function normalizeElement(mixed $value, AttributeDefinition $definition, string $path): mixed
    {
        if (null === $value) {
            return null;
        }

        return AttributeType::Complex === $definition->type
            ? $this->normalizeComplex($value, $definition, $path)
            : $this->scalars->normalize($value, $definition->type, $path);
    }

    /**
     * @return non-empty-array<string, mixed>|null
     */
    private function normalizeComplex(mixed $value, AttributeDefinition $definition, string $path): ?array
    {
        $normalized = [];

        foreach ($this->subAttributeValues($this->asObject($value, $definition, $path), $definition, $path) as $name => [$subAttribute, $subValue]) {
            $normalized[$name] = $this->normalize($subValue, $subAttribute, $path.'.'.$name);
        }

        $normalized = array_filter($normalized, static fn (mixed $subValue): bool => null !== $subValue);

        return [] === $normalized ? null : $normalized;
    }

    /**
     * @return array<mixed>
     */
    private function asObject(mixed $value, AttributeDefinition $definition, string $path): array
    {
        if (\is_string($value) && $definition->subAttribute('value') instanceof AttributeDefinition) {
            return $this->valueObject($value);
        }

        if (\is_array($value) && ([] === $value || !array_is_list($value))) {
            return $value;
        }

        throw ScimException::invalidValue(\sprintf('Attribute "%s" must be a complex object.', $path));
    }

    /**
     * Microsoft Entra ID sends manager as a bare id, and "" to unassign it.
     *
     * @return array<string, string>
     */
    private function valueObject(string $value): array
    {
        return '' === $value ? [] : ['value' => $value];
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, array{AttributeDefinition, mixed}> keyed by the schema spelling, without unknown keys,
     *                                                          and without readOnly sub-attributes of an attribute
     *                                                          that is not readOnly
     */
    private function subAttributeValues(array $value, AttributeDefinition $definition, string $path): array
    {
        $entries = [];

        foreach ($value as $name => $subValue) {
            $subAttribute = $definition->subAttribute((string) $name);

            if (!$subAttribute instanceof AttributeDefinition) {
                continue;
            }

            if (\array_key_exists($subAttribute->name, $entries)) {
                throw ScimException::invalidSyntax(\sprintf('Attribute "%s.%s" appears more than once.', $path, $subAttribute->name));
            }

            $entries[$subAttribute->name] = [$subAttribute, $subValue];
        }

        // The value of a readOnly attribute keeps its sub-attributes, so that a PATCH change check sees it.
        return Mutability::ReadOnly === $definition->mutability
            ? $entries
            : array_filter($entries, static fn (array $entry): bool => Mutability::ReadOnly !== $entry[0]->mutability);
    }

    /**
     * RFC 7643 section 2.4 allows one primary value. PingOne sends more.
     *
     * @param non-empty-list<mixed> $elements
     *
     * @return non-empty-list<mixed>
     */
    private function keepFirstPrimary(array $elements, AttributeDefinition $definition): array
    {
        $primary = $definition->subAttribute('primary')?->name;

        if (null === $primary) {
            return $elements;
        }

        $primaryFound = false;

        foreach ($elements as $index => $element) {
            if (\is_array($element) && true === ($element[$primary] ?? null)) {
                $element[$primary] = !$primaryFound;
                $elements[$index] = $element;
                $primaryFound = true;
            }
        }

        return $elements;
    }
}
