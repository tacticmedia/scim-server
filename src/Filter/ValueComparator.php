<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Filter;

use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;

use function Symfony\Component\String\u;

/**
 * Compares values of one attribute type. Strings and references are compared in Unicode NFC, and case-folded
 * (NFKC_CaseFold) when caseExact is false. dateTime values are compared as instants, numbers by value, binary
 * values byte by byte.
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.2.2
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-5
 */
final readonly class ValueComparator
{
    /**
     * False when either value does not fit the attribute type.
     */
    public function equals(mixed $documentValue, mixed $filterValue, AttributeDefinition $definition): bool
    {
        $left = $this->normalize($documentValue, $definition);
        $right = $this->normalize($filterValue, $definition);

        return null !== $left && null !== $right && 0 === $this->order($left, $right);
    }

    /**
     * Strings order by code point after preparation, booleans false before true.
     *
     * @return int<-1, 1>
     *
     * @throws \InvalidArgumentException when a value does not fit the attribute type
     */
    public function compare(mixed $left, mixed $right, AttributeDefinition $definition): int
    {
        return $this->order($this->normalizeOrFail($left, $definition), $this->normalizeOrFail($right, $definition));
    }

    /**
     * co, sw or ew. False when the document value does not fit the attribute type or is not a string.
     *
     * @throws \InvalidArgumentException when the operator is not co, sw or ew
     */
    public function matchesSubstring(mixed $documentValue, string $filterValue, ComparisonOperator $operator, AttributeDefinition $definition): bool
    {
        $haystack = $this->normalize($documentValue, $definition);
        $needle = $this->normalize($filterValue, $definition);

        if (!\is_string($haystack) || !\is_string($needle)) {
            return false;
        }

        return match ($operator) {
            ComparisonOperator::Contains => str_contains($haystack, $needle),
            ComparisonOperator::StartsWith => str_starts_with($haystack, $needle),
            ComparisonOperator::EndsWith => str_ends_with($haystack, $needle),
            default => throw new \InvalidArgumentException(\sprintf("The operator '%s' is not a substring operator.", $operator->value)),
        };
    }

    private function normalizeOrFail(mixed $value, AttributeDefinition $definition): string|int|float|bool|\DateTimeImmutable
    {
        return $this->normalize($value, $definition)
            ?? throw new \InvalidArgumentException(\sprintf("%s does not fit the %s attribute '%s'.", get_debug_type($value), $definition->type->value, $definition->name));
    }

    private function normalize(mixed $value, AttributeDefinition $definition): string|int|float|bool|\DateTimeImmutable|null
    {
        return match ($definition->type) {
            AttributeType::String, AttributeType::Reference => $this->prepareString($value, $definition->caseExact),
            AttributeType::Boolean => ValueCoercion::toBoolean($value),
            AttributeType::Integer, AttributeType::Decimal => $this->number($value),
            AttributeType::DateTime => ValueCoercion::toInstant($value),
            AttributeType::Binary => \is_string($value) ? $value : null,
            AttributeType::Complex => null,
        };
    }

    private function prepareString(mixed $value, bool $caseExact): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $string = u($value)->normalize();

        return ($caseExact ? $string : $string->folded())->toString();
    }

    private function number(mixed $value): int|float|null
    {
        return \is_int($value) || \is_float($value) ? $value : null;
    }

    /**
     * @return int<-1, 1>
     */
    private function order(string|int|float|bool|\DateTimeImmutable $left, string|int|float|bool|\DateTimeImmutable $right): int
    {
        return \is_string($left) && \is_string($right) ? strcmp($left, $right) <=> 0 : $left <=> $right;
    }
}
