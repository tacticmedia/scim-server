<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Patch;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\FilterEvaluator;
use TacticMedia\ScimServer\Filter\LogicalAnd;
use TacticMedia\ScimServer\Resource\DocumentNormalizer;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;

/**
 * Edits complex values: one value, or the values of a multi-valued attribute that a value filter selects. A
 * sub-attribute path without a value filter selects every value. A null value removes.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.5.2
 */
final readonly class ComplexValueEditor
{
    private DocumentNormalizer $normalizer;

    private FilterEvaluator $evaluator;

    public function __construct()
    {
        $this->normalizer = new DocumentNormalizer();
        $this->evaluator = new FilterEvaluator();
    }

    /**
     * @param bool $replaceValues true replaces each selected value, false merges into it
     *
     * @return mixed the new list, null when it is empty
     *
     * @throws ScimException invalidSyntax, invalidValue, mutability, or noTarget when nothing is selected and nothing
     *                       can be created
     */
    public function applyToSelected(mixed $stored, PatchTarget $target, mixed $value, bool $replaceValues): mixed
    {
        $value = $this->normalize($value, $target);
        $elements = \is_array($stored) ? array_values($stored) : [];
        $selected = array_keys(array_filter($elements, fn (mixed $element): bool => $this->selects($target, $element)));

        if ([] === $selected) {
            // RFC 7644 section 3.5.2.2: removing what is not there succeeds. A value that assigns nothing creates nothing.
            return $this->assignsNothing($value) ? $stored : [...$elements, $this->create($target, $value)];
        }

        foreach ($selected as $index) {
            $elements[$index] = $this->edit($elements[$index], $target, $value, $replaceValues);
        }

        $elements = array_values(array_filter($elements, static fn (mixed $element): bool => null !== $element));

        return [] === $elements ? null : $elements;
    }

    /**
     * Edits a single-valued complex attribute or its sub-attribute. A whole value merges.
     *
     * @return array<mixed>|null
     *
     * @throws ScimException invalidSyntax, invalidValue or mutability
     */
    public function applyToValue(mixed $stored, PatchTarget $target, mixed $value): ?array
    {
        return $this->edit($stored, $target, $this->normalize($value, $target), false);
    }

    /**
     * Sets the sub-attribute of the target, or replaces or merges the whole value. A merge keeps the sub-attributes
     * that the value does not name and unassigns those that it sets to null (RFC 7644 sections 3.5.2.1 and 3.5.2.3,
     * RFC 7643 section 2.5). A replacement without a sub-attribute value merges.
     *
     * @return array<mixed>|null
     *
     * @throws ScimException mutability
     */
    private function edit(mixed $complex, PatchTarget $target, mixed $value, bool $replace): ?array
    {
        $complex = \is_array($complex) ? $complex : [];
        $assigned = \is_array($value) ? array_filter($value, static fn (mixed $subValue): bool => null !== $subValue) : [];

        $edited = match (true) {
            $target->attribute->subAttribute instanceof AttributeDefinition => $this->withSubValue($complex, $target->attribute, $value),
            !\is_array($value) => [],
            $replace && [] !== $assigned => $assigned,
            default => array_replace($this->withoutSubValues($complex, $target, array_diff_key($value, $assigned)), $assigned),
        };

        return [] === $edited ? null : $edited;
    }

    /**
     * @param array<mixed> $complex
     *
     * @return array<mixed>
     *
     * @throws ScimException mutability
     */
    private function withSubValue(array $complex, ResolvedAttribute $subAttribute, mixed $value): array
    {
        $name = $subAttribute->definition()->name;
        ChangeGuard::check($subAttribute, $complex[$name] ?? null, $value);
        $complex[$name] = $value;

        return array_filter($complex, static fn (mixed $subValue): bool => null !== $subValue);
    }

    /**
     * @param array<mixed> $complex
     * @param array<mixed> $unassigned keyed by sub-attribute name
     *
     * @return array<mixed>
     *
     * @throws ScimException mutability
     */
    private function withoutSubValues(array $complex, PatchTarget $target, array $unassigned): array
    {
        $attribute = $target->attribute;

        foreach (array_keys($unassigned) as $name) {
            $complex = $this->withSubValue($complex, new ResolvedAttribute($attribute->schema, $attribute->attribute, $attribute->attribute->subAttribute((string) $name)), null);
        }

        return $complex;
    }

    /**
     * A whole value keeps the sub-attributes that it sets to null, as null. A value that sets no sub-attribute is [].
     */
    private function normalize(mixed $value, PatchTarget $target): mixed
    {
        $normalized = $this->normalizer->normalizeValue($value, $target->attribute);

        if (null === $value || $target->attribute->subAttribute instanceof AttributeDefinition) {
            return $normalized;
        }

        return [...$this->nullSubAttributes($value, $target->attribute->attribute), ...$this->single($normalized, $target)];
    }

    /**
     * @return array<mixed>
     */
    private function single(mixed $normalized, PatchTarget $target): array
    {
        $values = $target->attribute->attribute->multiValued ? $normalized ?? [[]] : [$normalized ?? []];
        $value = \is_array($values) && 1 === \count($values) ? array_first($values) : null;

        return \is_array($value)
            ? $value
            : throw ScimException::invalidValue(\sprintf('Attribute "%s" takes one value when a value filter selects it.', $target->attribute->path()));
    }

    /**
     * The value is an object, or a list with one object (Entra Q11).
     *
     * @return array<string, null> keyed by the schema spelling
     */
    private function nullSubAttributes(mixed $value, AttributeDefinition $definition): array
    {
        $object = \is_array($value) && [0] === array_keys($value) ? $value[0] : $value;
        $names = \is_array($object) ? array_keys(array_filter($object, \is_null(...))) : [];

        return array_fill_keys(array_filter(array_map(static fn (int|string $name): ?string => $definition->subAttribute((string) $name)?->name, $names), \is_string(...)), null);
    }

    private function assignsNothing(mixed $value): bool
    {
        return null === $value || (\is_array($value) && [] === array_filter($value, static fn (mixed $subValue): bool => null !== $subValue));
    }

    private function selects(PatchTarget $target, mixed $element): bool
    {
        return \is_array($element)
            && (!$target->valueFilter instanceof Filter || $this->evaluator->matchesElement($target->valueFilter, $target->whole(), $element));
    }

    /**
     * Microsoft Entra ID sets emails[type eq "work"].value on a user without a work email and expects a new value
     * (Entra Q6, erratum 8097).
     *
     * @return array<mixed>|null
     */
    private function create(PatchTarget $target, mixed $value): ?array
    {
        $filter = $target->valueFilter ?? throw self::noTarget($target);

        return $this->edit(self::equalityTerms($filter, $target), $target, $value, false);
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    private static function equalityTerms(Filter $filter, PatchTarget $target): array
    {
        return match (true) {
            $filter instanceof LogicalAnd => array_replace(self::equalityTerms($filter->left, $target), self::equalityTerms($filter->right, $target)),
            $filter instanceof Comparison && ComparisonOperator::Equal === $filter->operator && null !== $filter->value => [(string) $filter->path->subAttribute => $filter->value],
            default => throw self::noTarget($target),
        };
    }

    private static function noTarget(PatchTarget $target): ScimException
    {
        return ScimException::noTarget(\sprintf("No value of '%s' matches the path, and only a value filter of eq comparisons joined by 'and' can create one.", $target->whole()->path()));
    }
}
