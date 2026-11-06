<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Schema;

/** @see https://www.rfc-editor.org/rfc/rfc7643#section-7 */
enum Uniqueness: string
{
    case None = 'none';
    case Server = 'server';
    case Global = 'global';
}
