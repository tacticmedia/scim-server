<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Filter;

use TacticMedia\ScimServer\Path\AttributePath;

/**
 * attr[filter]: the inner filter must match one value of the multi-valued or complex attribute. The paths of the
 * inner filter are absolute: emails[type eq "work"] holds the path emails.type.
 */
final readonly class ValuePathFilter implements Filter
{
    public function __construct(
        public AttributePath $path,
        public Filter $filter,
    ) {
    }
}
