<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\FilterResolver;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * Builds the query for one resource type with every path resolved, as {@see ListQuery} requires.
 *
 * @internal
 */
final readonly class ListQueryFactory
{
    public function __construct(
        private FilterFolder $folder = new FilterFolder(),
        private FilterResolver $resolver = new FilterResolver(),
    ) {
    }

    /**
     * @param Filter|null $filter      parsed, not resolved
     * @param bool        $acrossTypes a query of the server root: what the type does not define has no value
     * @param int<1, max> $startIndex
     * @param int<0, max> $count
     *
     * @return ListQuery|null null when no resource of the type can match
     *
     * @throws ScimException invalidFilter, or invalidValue for an unknown sortBy
     */
    public function create(ListParameters $parameters, ?Filter $filter, ResourceType $type, bool $acrossTypes, int $startIndex, int $count): ?ListQuery
    {
        $folded = $filter instanceof Filter ? $this->folder->fold($filter, $type, $acrossTypes) : true;
        $sortBy = $this->sortBy($parameters->sortBy, $type, $acrossTypes);

        if (false === $folded) {
            return null;
        }

        $projection = $parameters->projection->resolvedFor($type);

        return new ListQuery(
            true === $folded ? null : $this->resolver->resolve($folded, $type),
            $sortBy,
            $parameters->sortOrder,
            $startIndex,
            $count,
            $projection->attributes,
            $projection->excludedAttributes,
        );
    }

    /**
     * RFC 7644 section 3.4.2.3 does not say what an unknown sortBy means; its report recommends invalidValue. Across
     * resource types, a type that does not define it stays unsorted.
     */
    private function sortBy(?AttributePath $sortBy, ResourceType $type, bool $acrossTypes): ?AttributePath
    {
        if (!$sortBy instanceof AttributePath) {
            return null;
        }

        $resolved = $this->resolver->resolveSortBy($sortBy, $type)?->path();

        return $resolved instanceof AttributePath || $acrossTypes
            ? $resolved
            : throw ScimException::invalidValue(\sprintf('The sortBy attribute "%s" is not defined for the resource type %s.', $sortBy, $type->name));
    }
}
