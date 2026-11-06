<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Filter;

/**
 * Reads boolean and dateTime values in the forms that clients send.
 */
final class ValueCoercion
{
    /**
     * xsd:dateTime with a four-digit year. PHP rejects an invalid date or time, for example 2011-02-30 or 24:00:00.
     *
     * @see https://www.rfc-editor.org/rfc/rfc7643#section-2.3.5
     */
    private const string XSD_DATE_TIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?\z/';

    /**
     * Microsoft Entra ID sends booleans as the strings "True" and "False".
     */
    public static function toBoolean(mixed $value): ?bool
    {
        return match (true) {
            \is_bool($value) => $value,
            \is_string($value) && 0 === strcasecmp($value, 'true') => true,
            \is_string($value) && 0 === strcasecmp($value, 'false') => false,
            default => null,
        };
    }

    /**
     * A value without a time zone is UTC.
     */
    public static function toInstant(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || 1 !== preg_match(self::XSD_DATE_TIME, $value)) {
            return null;
        }

        try {
            $instant = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\DateMalformedStringException) {
            return null;
        }

        return false === \DateTimeImmutable::getLastErrors() ? $instant : null;
    }
}
