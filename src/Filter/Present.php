<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Filter;

use TacticMedia\ScimServer\Path\AttributePath;

final readonly class Present implements Filter
{
    public function __construct(
        public AttributePath $path,
    ) {
    }
}
