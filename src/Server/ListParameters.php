<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\SortOrder;

/**
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.2
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.3
 * @see https://www.rfc-editor.org/rfc/rfc9865#section-2
 */
final readonly class ListParameters
{
    /**
     * @param int<1, max> $startIndex
     * @param int<0, max> $count
     * @param string|null $cursor     null: index pagination; "": the first cursor page
     */
    private function __construct(
        public ?string $filter,
        public ?AttributePath $sortBy,
        public SortOrder $sortOrder,
        public int $startIndex,
        public int $count,
        public RequestedProjection $projection,
        public ?string $cursor,
    ) {
    }

    /**
     * startIndex below 1 means 1 and a negative count means 0 (RFC 7644 Table 6). An absent count, or one above
     * $maxResults, means $maxResults.
     *
     * With $cursors, a "cursor" selects cursor pagination, except an empty one next to a startIndex. Without, "cursor"
     * is ignored.
     *
     * @param int<1, max> $maxResults
     *
     * @throws ScimException invalidValue, invalidCursor, or invalidCount for a count in cursor pagination
     */
    public static function from(RequestParameters $parameters, int $maxResults, bool $cursors = false): self
    {
        $cursor = $cursors ? $parameters->cursor() : null;
        $cursor = '' === $cursor && $parameters->has('startIndex') ? null : $cursor;

        return new self(
            $parameters->string('filter'),
            self::sortBy($parameters->string('sortBy')),
            self::sortOrder($parameters->string('sortOrder')),
            null === $cursor ? max(1, $parameters->integer('startIndex') ?? 1) : 1,
            min($maxResults, max(0, self::count($parameters, null !== $cursor) ?? $maxResults)),
            RequestedProjection::from($parameters),
            $cursor,
        );
    }

    private static function count(RequestParameters $parameters, bool $cursor): ?int
    {
        try {
            return $parameters->integer('count');
        } catch (ScimException $scimException) {
            throw $cursor ? ScimException::invalidCount($scimException->detail) : $scimException;
        }
    }

    private static function sortBy(?string $sortBy): ?AttributePath
    {
        if (null === $sortBy) {
            return null;
        }

        return AttributePath::parse($sortBy) ?? throw ScimException::invalidValue(\sprintf('"%s" is not a valid sortBy attribute path.', $sortBy));
    }

    private static function sortOrder(?string $sortOrder): SortOrder
    {
        if (null === $sortOrder) {
            return SortOrder::Ascending;
        }

        return SortOrder::tryFrom(strtolower($sortOrder)) ?? throw ScimException::invalidValue(\sprintf('The sortOrder "%s" is not "ascending" or "descending".', $sortOrder));
    }
}
