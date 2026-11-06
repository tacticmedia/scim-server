<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Patch;

use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Path\AttributePath;

/**
 * PATH = attrPath / valuePath [subAttr]. For emails[type eq "work"].value the path is emails.value and the value
 * filter selects the values of emails.
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.5.2
 */
final readonly class PatchPath
{
    public function __construct(
        public AttributePath $path,
        public ?Filter $valueFilter = null,
    ) {
    }
}
