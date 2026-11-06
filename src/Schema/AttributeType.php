<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Schema;

/** @see https://www.rfc-editor.org/rfc/rfc7643#section-2.3 */
enum AttributeType: string
{
    case String = 'string';
    case Boolean = 'boolean';
    case Decimal = 'decimal';
    case Integer = 'integer';
    case DateTime = 'dateTime';
    case Binary = 'binary';
    case Reference = 'reference';
    case Complex = 'complex';
}
