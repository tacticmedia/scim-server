<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Exception;

/**
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.12
 * @see https://www.rfc-editor.org/rfc/rfc9865#section-2.1
 */
enum ScimErrorType: string
{
    case InvalidFilter = 'invalidFilter';
    case TooMany = 'tooMany';
    case Uniqueness = 'uniqueness';
    case Mutability = 'mutability';
    case InvalidSyntax = 'invalidSyntax';
    case InvalidPath = 'invalidPath';
    case NoTarget = 'noTarget';
    case InvalidValue = 'invalidValue';
    case InvalidVersion = 'invalidVers';
    case Sensitive = 'sensitive';
    case InvalidCursor = 'invalidCursor';
    case ExpiredCursor = 'expiredCursor';
    case InvalidCount = 'invalidCount';
}
