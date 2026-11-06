<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Query;

use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Path\AttributePath;

/**
 * The server resolves every path against the schema before a repository gets the query: each path has its schema
 * URN and the schema spelling. A complex attribute without a sub-attribute in a comparison becomes its "value"
 * sub-attribute.
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.2
 */
final readonly class ListQuery
{
    /**
     * @param int<1, max>         $startIndex
     * @param int<0, max>         $count
     * @param list<AttributePath> $attributes         a hint: a repository can skip what the response omits
     * @param list<AttributePath> $excludedAttributes a hint: a repository can skip what the response omits
     */
    public function __construct(
        public ?Filter $filter = null,
        public ?AttributePath $sortBy = null,
        public SortOrder $sortOrder = SortOrder::Ascending,
        public int $startIndex = 1,
        public int $count = 100,
        public array $attributes = [],
        public array $excludedAttributes = [],
    ) {
    }
}
