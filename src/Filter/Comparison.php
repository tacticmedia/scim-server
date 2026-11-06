<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Filter;

use TacticMedia\ScimServer\Path\AttributePath;

final readonly class Comparison implements Filter
{
    public function __construct(
        public AttributePath $path,
        public ComparisonOperator $operator,
        public string|int|float|bool|null $value,
    ) {
    }
}
