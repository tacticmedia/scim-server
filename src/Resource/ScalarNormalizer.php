<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Resource;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\ValueCoercion;
use TacticMedia\ScimServer\Schema\AttributeType;

/**
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-2.3
 */
final class ScalarNormalizer
{
    private const string XSD_TIMEZONE = '/(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))\z/';

    /** base64_decode() in strict mode accepts whitespace, which RFC 4648 section 4 does not. */
    private const string BASE64 = '/\A[A-Za-z0-9+\/]*={0,2}\z/';

    /**
     * @throws ScimException invalidValue
     */
    public function normalize(mixed $value, AttributeType $type, string $path): string|bool|int|float
    {
        return match ($type) {
            AttributeType::String, AttributeType::Reference => $this->string($value),
            AttributeType::Boolean => ValueCoercion::toBoolean($value),
            AttributeType::Integer => $this->integer($value),
            AttributeType::Decimal => $this->decimal($value),
            AttributeType::DateTime => $this->dateTime($value),
            AttributeType::Binary => $this->binary($value),
            AttributeType::Complex => null,
        } ?? throw ScimException::invalidValue(\sprintf('Attribute "%s" must be %s.', $path, $this->expectation($type)));
    }

    private function string(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    private function integer(mixed $value): ?int
    {
        return \is_int($value) ? $value : null;
    }

    private function decimal(mixed $value): ?float
    {
        return \is_int($value) || (\is_float($value) && is_finite($value)) ? (float) $value : null;
    }

    /**
     * The grammar of {@see ValueCoercion::toInstant()}, so that a filter and a sort can compare every stored value.
     */
    private function dateTime(mixed $value): ?string
    {
        return \is_string($value) && ValueCoercion::toInstant($value) instanceof \DateTimeImmutable && 1 === preg_match(self::XSD_TIMEZONE, $value) ? $value : null;
    }

    private function binary(mixed $value): ?string
    {
        return \is_string($value) && 1 === preg_match(self::BASE64, $value) && false !== base64_decode($value, true) ? $value : null;
    }

    private function expectation(AttributeType $type): string
    {
        return match ($type) {
            AttributeType::String => 'a string',
            AttributeType::Boolean => 'a boolean',
            AttributeType::Decimal => 'a number',
            AttributeType::Integer => 'an integer',
            AttributeType::DateTime => 'an xsd:dateTime with a timezone',
            AttributeType::Binary => 'base64 encoded',
            AttributeType::Reference => 'a reference string',
            AttributeType::Complex => 'a complex object',
        };
    }
}
