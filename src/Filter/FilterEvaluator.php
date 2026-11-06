<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Filter;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * Evaluates a resolved filter against a document in schema spelling, with extension attributes under their schema
 * URN key.
 *
 * - A multi-valued attribute matches when any of its values matches.
 * - An unassigned attribute (absent, null or an empty array) and an attribute that the resource type does not
 *   define match no operator except ne.
 * - ne matches when the attribute is unassigned or any value is not equal to the filter value.
 * - eq null matches when pr does not; ne null matches when pr does.
 * - pr needs a value that is not null, "" or [], and for a complex value a sub-attribute that is present.
 * - A value that does not fit the attribute type matches no operator except ne.
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.2.2
 */
final readonly class FilterEvaluator
{
    public function __construct(
        private ValueComparator $comparator = new ValueComparator(),
    ) {
    }

    /**
     * @param array<string, mixed> $document
     *
     * @throws ScimException invalidFilter for a filter node that it cannot evaluate
     */
    public function matches(Filter $filter, array $document, ResourceType $type): bool
    {
        return $this->evaluate($filter, fn (Filter $leaf): bool => $this->matchesInDocument($leaf, $document, $type));
    }

    /**
     * Whether one value of a complex attribute satisfies a value filter, for example the filter of a PATCH value
     * path. The paths of the filter are sub-attributes of $attribute.
     *
     * @param array<array-key, mixed> $element
     *
     * @throws ScimException invalidFilter for a filter node that it cannot evaluate, for example a nested value path
     */
    public function matchesElement(Filter $filter, ResolvedAttribute $attribute, array $element): bool
    {
        return $this->evaluate($filter, fn (Filter $leaf): bool => $this->matchesInElement($leaf, $attribute, $element));
    }

    /**
     * @param \Closure(Filter): bool $matchesLeaf
     */
    private function evaluate(Filter $filter, \Closure $matchesLeaf): bool
    {
        return match (true) {
            $filter instanceof LogicalAnd => $this->evaluate($filter->left, $matchesLeaf) && $this->evaluate($filter->right, $matchesLeaf),
            $filter instanceof LogicalOr => $this->evaluate($filter->left, $matchesLeaf) || $this->evaluate($filter->right, $matchesLeaf),
            $filter instanceof Not => !$this->evaluate($filter->filter, $matchesLeaf),
            default => $matchesLeaf($filter),
        };
    }

    /**
     * @param array<string, mixed> $document
     */
    private function matchesInDocument(Filter $leaf, array $document, ResourceType $type): bool
    {
        if ($leaf instanceof ValuePathFilter) {
            return $this->matchesValuePath($leaf, $document, $type);
        }

        $leaf = $this->leaf($leaf);
        $attribute = $type->resolve($leaf->path);

        if (!$attribute instanceof ResolvedAttribute) {
            return $this->matchesValues($leaf, [], new AttributeDefinition($leaf->path->attribute));
        }

        return $this->matchesValues($leaf, DocumentValues::of($attribute, $document, $type), $attribute->definition());
    }

    /**
     * @param array<string, mixed> $document
     */
    private function matchesValuePath(ValuePathFilter $filter, array $document, ResourceType $type): bool
    {
        $attribute = $type->resolve($filter->path);

        return $attribute instanceof ResolvedAttribute && array_any(
            DocumentValues::elements($attribute, $document, $type),
            fn (mixed $element): bool => \is_array($element) && $this->matchesElement($filter->filter, $attribute, $element),
        );
    }

    /**
     * @param array<array-key, mixed> $element
     */
    private function matchesInElement(Filter $leaf, ResolvedAttribute $attribute, array $element): bool
    {
        $leaf = $this->leaf($leaf);
        $subAttribute = $attribute->attribute->subAttribute((string) $leaf->path->subAttribute);
        $values = $subAttribute instanceof AttributeDefinition ? DocumentValues::valuesOf($element[$subAttribute->name] ?? null, $subAttribute) : [];

        return $this->matchesValues($leaf, $values, $subAttribute ?? $attribute->attribute);
    }

    private function leaf(Filter $filter): Comparison|Present
    {
        return $filter instanceof Comparison || $filter instanceof Present
            ? $filter
            : throw ScimException::invalidFilter(\sprintf('The filter node %s is not supported here.', $filter::class));
    }

    /**
     * @param list<mixed> $values
     */
    private function matchesValues(Comparison|Present $leaf, array $values, AttributeDefinition $definition): bool
    {
        if ($leaf instanceof Present) {
            return array_any($values, DocumentValues::isPresent(...));
        }

        if (null === $leaf->value) {
            return $this->matchesNull($leaf->operator, array_any($values, DocumentValues::isPresent(...)));
        }

        if (ComparisonOperator::NotEqual === $leaf->operator) {
            return [] === $values || array_any($values, fn (mixed $value): bool => !$this->comparator->equals($value, $leaf->value, $definition));
        }

        return array_any($values, fn (mixed $value): bool => $this->satisfies($value, $leaf, $definition));
    }

    private function matchesNull(ComparisonOperator $operator, bool $present): bool
    {
        return match ($operator) {
            ComparisonOperator::Equal => !$present,
            ComparisonOperator::NotEqual => $present,
            default => false,
        };
    }

    private function satisfies(mixed $value, Comparison $comparison, AttributeDefinition $definition): bool
    {
        $operator = $comparison->operator;

        if ($operator->isSubstring()) {
            return \is_string($comparison->value) && $this->comparator->matchesSubstring($value, $comparison->value, $operator, $definition);
        }

        if ($operator->isOrdering()) {
            return $this->orders($value, $comparison, $definition);
        }

        return $this->comparator->equals($value, $comparison->value, $definition);
    }

    private function orders(mixed $value, Comparison $comparison, AttributeDefinition $definition): bool
    {
        try {
            $order = $this->comparator->compare($value, $comparison->value, $definition);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return match ($comparison->operator) {
            ComparisonOperator::GreaterThan => $order > 0,
            ComparisonOperator::GreaterThanOrEqual => $order >= 0,
            ComparisonOperator::LessThan => $order < 0,
            default => $order <= 0,
        };
    }
}
