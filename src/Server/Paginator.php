<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Query\CursorPage;
use TacticMedia\ScimServer\Repository\CursorPaginatedRepository;

/**
 * Answers a list or search by index or by cursor, as {@see ListParameters::$cursor} selects. A cursor holds an offset,
 * a repository position ({@see CursorPaginatedRepository}) unless the position is too long for a cursor, and the query
 * that it belongs to: the resource types, filter, sortBy and sortOrder. A cursor without a position pages by offset.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc9865
 */
final readonly class Paginator
{
    public function __construct(
        private ResponseFactory $responses,
        private ?CursorCodec $cursors = null,
    ) {
    }

    public function cursorsEnabled(): bool
    {
        return $this->cursors instanceof CursorCodec;
    }

    /**
     * @param list<RegisteredResourceType>                                              $resourceTypes
     * @param \Closure(int<1, max>): array{list<array<string, mixed>>, int}             $page          the page at a startIndex, and totalResults
     * @param (\Closure(list<int|float|string|bool|null>|null): (CursorPage|null))|null $positioned    the page after a repository position; null, or a null result: offset paging
     *
     * @throws ScimException
     */
    public function respond(Request $request, ListParameters $list, array $resourceTypes, bool $acrossTypes, \Closure $page, ?\Closure $positioned = null): Response
    {
        if (null === $list->cursor || !$this->cursors instanceof CursorCodec) {
            [$resources, $totalResults] = $page($list->startIndex);

            return $this->responses->list($request, $resources, $totalResults, $list->startIndex);
        }

        $query = hash('xxh128', serialize([array_map(static fn (RegisteredResourceType $registered): string => $registered->type->name, $resourceTypes), $acrossTypes, $list->filter, (string) $list->sortBy, $list->sortOrder]));
        [$offset, $position] = '' === $list->cursor ? [0, null] : $this->cursors->decode($list->cursor, $query);

        return '' === $list->cursor || null !== $position
            ? $this->byPosition($request, $this->cursors, $query, $page, $offset, $this->positioned($positioned, $position))
            : $this->byOffset($request, $this->cursors, $query, $page, $offset);
    }

    /**
     * @param \Closure(int<1, max>): array{list<array<string, mixed>>, int} $page
     * @param int<0, max>                                                   $offset
     */
    private function byOffset(Request $request, CursorCodec $cursors, string $query, \Closure $page, int $offset): Response
    {
        [$resources, $totalResults] = $page($offset + 1);
        $end = $offset + \count($resources);

        return $this->responses->cursorList($request, $resources, $totalResults, [] !== $resources && $end < $totalResults ? $cursors->encode($query, $end) : null);
    }

    /**
     * @param \Closure(int<1, max>): array{list<array<string, mixed>>, int} $page
     * @param int<0, max>                                                   $offset the number of resources before the page
     */
    private function byPosition(Request $request, CursorCodec $cursors, string $query, \Closure $page, int $offset, ?CursorPage $positioned): Response
    {
        if (!$positioned instanceof CursorPage) {
            return $this->byOffset($request, $cursors, $query, $page, $offset);
        }

        $next = null === $positioned->next ? null : $cursors->encode($query, $offset + \count($positioned->resources), $positioned->next);

        return $this->responses->cursorList($request, $positioned->resources, $positioned->totalResults, $next);
    }

    /**
     * @param (\Closure(list<int|float|string|bool|null>|null): (CursorPage|null))|null $positioned
     * @param list<int|float|string|bool|null>|null                                     $position   null: the first page
     *
     * @throws ScimException invalidCursor when a position gets no page
     */
    private function positioned(?\Closure $positioned, ?array $position): ?CursorPage
    {
        $page = $positioned instanceof \Closure ? $positioned($position) : null;

        return null === $position || $page instanceof CursorPage ? $page : throw ScimException::invalidCursor();
    }
}
