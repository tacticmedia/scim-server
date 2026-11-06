<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Query\CursorPage;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Repository\CursorPaginatedRepository;

/**
 * GET of an endpoint and POST of its ".search". A query across resource types pages through the matches of each type
 * in registration order, so a page can start in one type and end in another. RootListHandler handles a root query
 * with sortBy. A cursor on an endpoint uses the position paging of a {@see CursorPaginatedRepository}; a cursor across
 * resource types holds an offset.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.2
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.3
 */
final readonly class ListHandler
{
    /**
     * @param int<1, max> $maxResults
     */
    public function __construct(
        private ResourcePresenter $presenter,
        private Paginator $paginator,
        private int $maxResults,
        private FilterParser $parser = new FilterParser(),
        private ListQueryFactory $queries = new ListQueryFactory(),
    ) {
    }

    /**
     * @param list<RegisteredResourceType> $resourceTypes
     *
     * @throws ScimException
     */
    public function query(Request $request, string $baseUrl, array $resourceTypes, bool $acrossTypes): Response
    {
        return $this->respond($request, $baseUrl, $resourceTypes, $acrossTypes, ListParameters::from(new RequestParameters($request->query->all()), $this->maxResults, $this->paginator->cursorsEnabled()));
    }

    /**
     * The "schemas" of the SearchRequest are not checked.
     *
     * @param list<RegisteredResourceType> $resourceTypes
     *
     * @throws ScimException
     */
    public function search(Request $request, string $baseUrl, array $resourceTypes, bool $acrossTypes): Response
    {
        return $this->respond($request, $baseUrl, $resourceTypes, $acrossTypes, ListParameters::from(new RequestParameters(RequestBody::object($request)), $this->maxResults, $this->paginator->cursorsEnabled()));
    }

    /**
     * @param list<RegisteredResourceType> $resourceTypes
     *
     * @throws ScimException
     */
    public function respond(Request $request, string $baseUrl, array $resourceTypes, bool $acrossTypes, ListParameters $list): Response
    {
        $filter = null === $list->filter ? null : $this->parser->parse($list->filter);

        return $this->paginator->respond(
            $request,
            $list,
            $resourceTypes,
            $acrossTypes,
            fn (int $startIndex): array => $this->collect($list, $filter, $resourceTypes, $acrossTypes, $baseUrl, $startIndex),
            $acrossTypes ? null : fn ($after): ?CursorPage => $this->listAfter($list, $filter, $resourceTypes, $baseUrl, $after),
        );
    }

    /**
     * @param list<RegisteredResourceType> $resourceTypes
     * @param int<1, max>                  $startIndex
     *
     * @return array{list<array<string, mixed>>, int}
     */
    private function collect(ListParameters $list, ?Filter $filter, array $resourceTypes, bool $acrossTypes, string $baseUrl, int $startIndex): array
    {
        $resources = [];
        $totalResults = 0;

        foreach ($resourceTypes as $registered) {
            $query = $this->queries->create($list, $filter, $registered->type, $acrossTypes, max(1, $startIndex - $totalResults), max(0, $list->count - \count($resources)));

            if (!$query instanceof ListQuery) {
                continue;
            }

            $result = $registered->repository->list($query);
            $resources = [...$resources, ...$this->present($result->resources, $query, $registered, $baseUrl, $list)];
            $totalResults += $result->totalResults;
        }

        return [$resources, $totalResults];
    }

    /**
     * The page of the one resource type after $after, or null when its repository does not page by position.
     *
     * @param list<RegisteredResourceType>          $resourceTypes
     * @param list<int|float|string|bool|null>|null $after
     */
    private function listAfter(ListParameters $list, ?Filter $filter, array $resourceTypes, string $baseUrl, ?array $after): ?CursorPage
    {
        $registered = $resourceTypes[0] ?? null;

        if (!$registered?->repository instanceof CursorPaginatedRepository) {
            return null;
        }

        $query = $this->queries->create($list, $filter, $registered->type, false, 1, $list->count);

        if (!$query instanceof ListQuery) {
            return new CursorPage([], 0, null);
        }

        $page = $registered->repository->listAfter($query, $after);

        return $page instanceof CursorPage ? new CursorPage($this->present($page->resources, $query, $registered, $baseUrl, $list), $page->totalResults, $page->next) : null;
    }

    /**
     * A repository page longer than its count is cut.
     *
     * @param list<array<string, mixed>> $documents
     *
     * @return list<array<string, mixed>>
     */
    private function present(array $documents, ListQuery $query, RegisteredResourceType $registered, string $baseUrl, ListParameters $list): array
    {
        return array_map(
            fn (array $document): array => $this->presenter->present($document, $registered->type, $baseUrl, $list->projection),
            \array_slice($documents, 0, $query->count),
        );
    }
}
