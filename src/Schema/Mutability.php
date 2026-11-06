<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Schema;

/** @see https://www.rfc-editor.org/rfc/rfc7643#section-7 */
enum Mutability: string
{
    case ReadOnly = 'readOnly';
    case ReadWrite = 'readWrite';
    case Immutable = 'immutable';
    case WriteOnly = 'writeOnly';
}
