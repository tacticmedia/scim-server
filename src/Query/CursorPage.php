<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Query;

/**
 * @see https://www.rfc-editor.org/rfc/rfc9865#section-2
 */
final readonly class CursorPage
{
    /**
     * @param list<array<string, mixed>>            $resources    at most ListQuery::$count documents
     * @param int<0, max>|null                      $totalResults null when unknown: the response omits it
     * @param list<int|float|string|bool|null>|null $next         the position after the last resource; null when no resource follows
     */
    public function __construct(
        public array $resources,
        public ?int $totalResults,
        public ?array $next,
    ) {
    }
}
