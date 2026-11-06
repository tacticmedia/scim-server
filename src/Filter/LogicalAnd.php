<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Filter;

final readonly class LogicalAnd implements Filter
{
    public function __construct(
        public Filter $left,
        public Filter $right,
    ) {
    }
}
