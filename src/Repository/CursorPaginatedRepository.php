<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Repository;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Query\CursorPage;
use TacticMedia\ScimServer\Query\ListQuery;

/**
 * A repository that pages by position (keyset) for RFC 9865 cursors. Without it, the server puts an offset in the
 * cursor and calls {@see ResourceRepository::list()}.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9865
 */
interface CursorPaginatedRepository extends ResourceRepository
{
    /**
     * Same filter and order as list(); $query->startIndex is 1. The server encrypts the position into the cursor, so
     * its content is the repository's own: sort values and a unique tie-break, or the token of an upstream API.
     *
     * @param list<int|float|string|bool|null>|null $after a CursorPage::$next of this repository for the same query; null: the first page
     *
     * @return CursorPage|null null: no position paging for this query, the server pages by offset
     *
     * @throws ScimException 400 invalidCursor for a position that the repository cannot use, 400 expiredCursor for an
     *                       upstream cursor that expired
     */
    public function listAfter(ListQuery $query, ?array $after): ?CursorPage;
}
