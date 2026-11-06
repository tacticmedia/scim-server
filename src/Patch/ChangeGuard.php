<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Patch;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Resource\ValueEquality;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;

/**
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.5.2
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-7
 */
final class ChangeGuard
{
    /**
     * A value equal to the stored one is no change. Thus a readOnly value equal to the stored one passes (the "id"
     * in an Okta group rename), and an immutable attribute without a stored value takes one (RFC 7644 section 3.5.2).
     *
     * @throws ScimException mutability
     */
    public static function check(ResolvedAttribute $attribute, mixed $stored, mixed $value): void
    {
        if (self::same($stored, $value)) {
            return;
        }

        $definition = $attribute->definition();
        $violation = match (true) {
            Mutability::ReadOnly === $definition->mutability => 'readOnly',
            Mutability::Immutable === $definition->mutability && null !== $stored => 'immutable',
            // RFC 7644 section 3.5.2.2
            null === $value && !$attribute->subAttribute instanceof AttributeDefinition && $definition->required => 'required',
            default => null,
        };

        if (null !== $violation) {
            throw ScimException::mutability(\sprintf('Attribute "%s" is %s.', $attribute->path(), $violation));
        }
    }

    public static function same(mixed $left, mixed $right): bool
    {
        return ValueEquality::same($left, $right);
    }
}
