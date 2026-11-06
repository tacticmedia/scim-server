<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Schema;

/** @see https://www.rfc-editor.org/rfc/rfc7643#section-7 */
enum Returned: string
{
    case Always = 'always';
    case Never = 'never';
    case Default = 'default';
    case Request = 'request';
}
