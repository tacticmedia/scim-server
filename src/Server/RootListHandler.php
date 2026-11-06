<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Repository\DocumentSorter;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * GET / and POST /.search. With sortBy, the first startIndex + count - 1 sorted matches of each resource type are
 * merged and sorted again, so the order spans all types (RFC 7644 section 3.4.2.3). Without sortBy,
 * {@see ListHandler} pages through the types in registration order. A cursor holds an offset.
 *
 * shortcut: a deep page loads startIndex + count - 1 resources per type, also by cursor; merge positions of each
 * type if that gets too slow.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.2.1
 */
final readonly class RootListHandler
{
    /**
     * @param int<1, max> $maxResults
     */
    public function __construct(
        private ListHandler $lists,
        private ResourcePresenter $presenter,
        private Paginator $paginator,
        private int $maxResults,
        private FilterParser $parser = new FilterParser(),
        private ListQueryFactory $queries = new ListQueryFactory(),
        private DocumentSorter $sorter = new DocumentSorter(),
    ) {
    }

    /**
     * @param list<RegisteredResourceType> $resourceTypes
     *
     * @throws ScimException
     */
    public function query(Request $request, string $baseUrl, array $resourceTypes): Response
    {
        return $this->respond($request, $baseUrl, $resourceTypes, new RequestParameters($request->query->all()));
    }

    /**
     * @param list<RegisteredResourceType> $resourceTypes
     *
     * @throws ScimException
     */
    public function search(Request $request, string $baseUrl, array $resourceTypes): Response
    {
        return $this->respond($request, $baseUrl, $resourceTypes, new RequestParameters(RequestBody::object($request)));
    }

    /**
     * @param list<RegisteredResourceType> $resourceTypes
     */
    private function respond(Request $request, string $baseUrl, array $resourceTypes, RequestParameters $parameters): Response
    {
        $list = ListParameters::from($parameters, $this->maxResults, $this->paginator->cursorsEnabled());

        return $list->sortBy instanceof AttributePath
            ? $this->sorted($request, $baseUrl, $resourceTypes, $list, $list->sortBy)
            : $this->lists->respond($request, $baseUrl, $resourceTypes, true, $list);
    }

    /**
     * @param list<RegisteredResourceType> $resourceTypes
     */
    private function sorted(Request $request, string $baseUrl, array $resourceTypes, ListParameters $list, AttributePath $sortBy): Response
    {
        return $this->paginator->respond($request, $list, $resourceTypes, true, fn (int $startIndex): array => $this->sortedPage($baseUrl, $resourceTypes, $list, $sortBy, $startIndex));
    }

    /**
     * @param list<RegisteredResourceType> $resourceTypes
     * @param int<1, max>                  $startIndex
     *
     * @return array{list<array<string, mixed>>, int}
     */
    private function sortedPage(string $baseUrl, array $resourceTypes, ListParameters $list, AttributePath $sortBy, int $startIndex): array
    {
        [$entries, $totalResults] = $this->firstMatches($list, $resourceTypes, $baseUrl, $startIndex);
        $page = \array_slice($this->sorter->sortAcrossTypes($entries, $sortBy, $list->sortOrder), $startIndex - 1, $list->count);

        return [array_map(fn (array $entry): array => $this->presenter->present($entry[1], $entry[0], $baseUrl, $list->projection), $page), $totalResults];
    }

    /**
     * The merge sorts by what the client gets: the server values, such as meta.resourceType, are set first.
     *
     * @param list<RegisteredResourceType> $resourceTypes
     * @param int<1, max>                  $startIndex
     *
     * @return array{list<array{ResourceType, array<string, mixed>}>, int}
     */
    private function firstMatches(ListParameters $list, array $resourceTypes, string $baseUrl, int $startIndex): array
    {
        $filter = null === $list->filter ? null : $this->parser->parse($list->filter);
        // min() keeps the sum an integer for a startIndex near PHP_INT_MAX; max() states the range for PHPStan.
        $window = 0 === $list->count ? 0 : max(0, min($startIndex - 1, \PHP_INT_MAX - $list->count) + $list->count);
        $entries = [];
        $totalResults = 0;

        foreach ($resourceTypes as $registered) {
            $query = $this->queries->create($list, $filter, $registered->type, true, 1, $window);

            if ($query instanceof ListQuery) {
                // No projection hints: the merge reads the sortBy value of every document.
                $result = $registered->repository->list(new ListQuery($query->filter, $query->sortBy, $query->sortOrder, $query->startIndex, $query->count));
                $entries = [...$entries, ...array_map(
                    fn (array $document): array => [$registered->type, $this->presenter->withServerValues($document, $registered->type, $baseUrl)],
                    \array_slice($result->resources, 0, $window),
                )];
                $totalResults += $result->totalResults;
            }
        }

        return [$entries, $totalResults];
    }
}
