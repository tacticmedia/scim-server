<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Filter;

/** @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.2.2 */
enum ComparisonOperator: string
{
    case Equal = 'eq';
    case NotEqual = 'ne';
    case Contains = 'co';
    case StartsWith = 'sw';
    case EndsWith = 'ew';
    case GreaterThan = 'gt';
    case LessThan = 'lt';
    case GreaterThanOrEqual = 'ge';
    case LessThanOrEqual = 'le';

    public function isOrdering(): bool
    {
        return \in_array($this, [self::GreaterThan, self::LessThan, self::GreaterThanOrEqual, self::LessThanOrEqual], true);
    }

    public function isSubstring(): bool
    {
        return \in_array($this, [self::Contains, self::StartsWith, self::EndsWith], true);
    }
}
