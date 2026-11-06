<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Patch;

use TacticMedia\ScimServer\Schema\AttributeDefinition;

/**
 * An operation that sets primary true on one value sets it false on the other values. The value that became primary
 * is the value whose primary was not true at the same list position before.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.5.2
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-2.4
 */
final class PrimaryValue
{
    public static function demoteOthers(mixed $stored, mixed $value, AttributeDefinition $definition): mixed
    {
        $primary = $definition->multiValued ? $definition->subAttribute('primary')?->name : null;

        if (null === $primary || !\is_array($value)) {
            return $value;
        }

        $promoted = self::promotedIndex(\is_array($stored) ? $stored : [], $value, $primary);

        return null === $promoted ? $value : self::demote($value, $promoted, $primary);
    }

    /**
     * @param array<mixed> $stored
     * @param array<mixed> $value
     */
    private static function promotedIndex(array $stored, array $value, string $primary): int|string|null
    {
        return array_find_key(
            $value,
            static fn (mixed $element, int|string $index): bool => self::isPrimary($element, $primary) && !self::isPrimary($stored[$index] ?? null, $primary),
        );
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function demote(array $value, int|string $promoted, string $primary): array
    {
        foreach ($value as $index => $element) {
            if ($index !== $promoted && self::isPrimary($element, $primary)) {
                $element[$primary] = false;
                $value[$index] = $element;
            }
        }

        return $value;
    }

    /**
     * @phpstan-assert-if-true array<mixed> $element
     */
    private static function isPrimary(mixed $element, string $primary): bool
    {
        return \is_array($element) && true === ($element[$primary] ?? null);
    }
}
