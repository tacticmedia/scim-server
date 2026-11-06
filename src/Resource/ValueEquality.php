<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Resource;

/**
 * @internal to the library: {@see ReplacementMerger} and {@see \TacticMedia\ScimServer\Patch\ChangeGuard} use it
 */
final class ValueEquality
{
    /**
     * Exact comparison that ignores the key order of objects.
     */
    public static function same(mixed $left, mixed $right): bool
    {
        return self::canonical($left) === self::canonical($right);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
