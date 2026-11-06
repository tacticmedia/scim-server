<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Query;

/** @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.2.3 */
enum SortOrder: string
{
    case Ascending = 'ascending';
    case Descending = 'descending';
}
