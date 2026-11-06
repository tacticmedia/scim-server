<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Patch;

use TacticMedia\ScimServer\Filter\ValueCoercion;
use TacticMedia\ScimServer\Filter\ValueComparator;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;

use function Symfony\Component\String\u;

/**
 * Values of a multi-valued attribute as strings that are identical exactly when {@see ValueComparator::equals()} is
 * true. Unlike PHP, an integer above 2^53 does not equal the nearest float.
 *
 * @internal
 */
final readonly class CanonicalValue
{
    public function __construct(private AttributeDefinition $definition)
    {
    }

    /**
     * @return array<string, string|null>|null the sub-values in name order, keyed by schema spelling, null for a
     *                                         sub-value that equals nothing; a simple value has the name ""; null for
     *                                         a complex value that is not an object
     */
    public function of(mixed $value): ?array
    {
        if (AttributeType::Complex !== $this->definition->type) {
            return ['' => self::scalar($value, $this->definition)];
        }

        if (!\is_array($value)) {
            return null;
        }

        $canonical = [];

        foreach ($value as $name => $subValue) {
            $subAttribute = $this->definition->subAttribute((string) $name);

            if (null !== $subValue && $subAttribute?->name === $name) {
                $canonical[$name] = self::scalar($subValue, $subAttribute);
            }
        }

        ksort($canonical);

        return $canonical;
    }

    public static function scalar(mixed $value, AttributeDefinition $definition): ?string
    {
        return match ($definition->type) {
            AttributeType::String, AttributeType::Reference => self::string($value, $definition->caseExact),
            AttributeType::Boolean => self::boolean(ValueCoercion::toBoolean($value)),
            AttributeType::Integer, AttributeType::Decimal => self::number($value),
            AttributeType::DateTime => ValueCoercion::toInstant($value)?->format('U.u'),
            AttributeType::Binary => \is_string($value) ? $value : null,
            AttributeType::Complex => null,
        };
    }

    private static function string(mixed $value, bool $caseExact): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $string = u($value)->normalize();

        return ($caseExact ? $string : $string->folded())->toString();
    }

    private static function boolean(?bool $value): ?string
    {
        return match ($value) {
            true => 'true',
            false => 'false',
            null => null,
        };
    }

    private static function number(mixed $value): ?string
    {
        return match (true) {
            \is_int($value) => (string) $value,
            \is_float($value) && floor($value) === $value && abs($value) < 2 ** 63 => (string) (int) $value,
            \is_float($value) => var_export($value, true),
            default => null,
        };
    }
}
