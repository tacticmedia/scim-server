<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Query;

final readonly class ListResult
{
    /**
     * @param list<array<string, mixed>> $resources    one page of documents
     * @param int<0, max>                $totalResults the number of matches on all pages
     */
    public function __construct(
        public array $resources,
        public int $totalResults,
    ) {
    }
}
