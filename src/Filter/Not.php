<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Filter;

final readonly class Not implements Filter
{
    public function __construct(
        public Filter $filter,
    ) {
    }
}
