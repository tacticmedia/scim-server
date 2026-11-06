<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Repository;

use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\FilterEvaluator;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\CursorPage;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Query\ListResult;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * Keeps the documents of one resource type in memory, in insertion order. "meta.version" is W/"1" after create and
 * one more after each replace. Uniqueness is the server's concern.
 *
 * The cursor position is [sort value, insertion sequence]: values, not a reference to a document, so a change between
 * pages neither repeats nor skips a document that stays.
 */
final class InMemoryResourceRepository implements CursorPaginatedRepository
{
    /** @var array<array-key, array<string, mixed>> keyed by id */
    private array $documents = [];

    /** @var array<array-key, int> keyed by id: the order of equal sort values */
    private array $sequences = [];

    private int $nextSequence = 0;

    private readonly FilterEvaluator $evaluator;

    private readonly DocumentSorter $sorter;

    /**
     * @param iterable<array<string, mixed>> $documents canonical documents, stored as given
     *
     * @throws \InvalidArgumentException when a document has no "id" or repeats one
     */
    public function __construct(
        private readonly ResourceType $type,
        private readonly ClockInterface $clock,
        iterable $documents = [],
    ) {
        $this->evaluator = new FilterEvaluator();
        $this->sorter = new DocumentSorter();

        foreach ($documents as $document) {
            $id = $document['id'] ?? null;

            if (!\is_string($id) || '' === $id || isset($this->documents[$id])) {
                throw new \InvalidArgumentException('Every seeded document needs a unique, non-empty string "id".');
            }

            $this->store($id, $document);
        }
    }

    /**
     * Ignores the hints and returns the complete document.
     */
    #[\Override]
    public function get(string $id, array $attributes = [], array $excludedAttributes = []): ?array
    {
        return $this->documents[$id] ?? null;
    }

    #[\Override]
    public function list(ListQuery $query): ListResult
    {
        $rows = $this->ordered($query);

        return new ListResult(array_column(\array_slice($rows, $query->startIndex - 1, $query->count), 2), \count($rows));
    }

    /**
     * @throws ScimException invalidCursor for a position that is not [sort value, integer]
     */
    #[\Override]
    public function listAfter(ListQuery $query, ?array $after): CursorPage
    {
        $rows = $this->ordered($query);
        $following = null === $after ? $rows : $this->following($rows, $this->position($after), $query);
        $page = \array_slice($following, 0, $query->count);
        $last = array_last($page);

        return new CursorPage(array_column($page, 2), \count($rows), null !== $last && \count($following) > \count($page) ? [$last[0], $last[1]] : null);
    }

    #[\Override]
    public function create(array $document): array
    {
        $id = Uuid::v7()->toRfc4122();
        $now = $this->now();

        return $this->store($id, $this->withIdAndMeta($document, $id, ['created' => $now, 'lastModified' => $now, 'version' => 'W/"1"']));
    }

    #[\Override]
    public function replace(string $id, array $document, ?string $version = null): array
    {
        $meta = $this->storedMeta($id, $version);
        $now = $this->now();

        return $this->documents[$id] = $this->withIdAndMeta($document, $id, array_replace($meta, [
            'created' => $meta['created'] ?? $now,
            'lastModified' => $now,
            'version' => \sprintf('W/"%d"', $this->revision($meta) + 1),
        ]));
    }

    #[\Override]
    public function delete(string $id, ?string $version = null): void
    {
        $this->storedMeta($id, $version);
        unset($this->documents[$id], $this->sequences[$id]);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function store(string $id, array $document): array
    {
        $this->sequences[$id] = $this->nextSequence++;

        return $this->documents[$id] = $document;
    }

    /**
     * The order of list(): by sort value, then by insertion sequence.
     *
     * @return list<array{int|float|string|bool|null, int, array<string, mixed>}> [sort value, sequence, document]
     */
    private function ordered(ListQuery $query): array
    {
        $matches = $this->matching($query->filter);
        $documents = array_values($matches);
        $keys = $query->sortBy instanceof AttributePath ? $this->sorter->keys($documents, $query->sortBy, $this->type) : array_fill(0, \count($documents), null);
        $rows = array_map(fn (int|float|string|bool|null $key, int|string $id, array $document): array => [$key, $this->sequences[$id], $document], $keys, array_keys($matches), $documents);
        usort($rows, $this->comparison($query));

        return $rows;
    }

    /**
     * @param list<array{int|float|string|bool|null, int, array<string, mixed>}> $rows
     * @param array{int|float|string|bool|null, int}                             $position
     *
     * @return list<array{int|float|string|bool|null, int, array<string, mixed>}>
     */
    private function following(array $rows, array $position, ListQuery $query): array
    {
        $comparison = $this->comparison($query);

        return array_values(array_filter($rows, static fn (array $row): bool => $comparison($row, $position) > 0));
    }

    /**
     * @return \Closure(array{int|float|string|bool|null, int, 2?: mixed}, array{int|float|string|bool|null, int, 2?: mixed}): int
     */
    private function comparison(ListQuery $query): \Closure
    {
        $values = $query->sortBy instanceof AttributePath ? $this->sorter->keyComparison($query->sortBy, $query->sortOrder, $this->type) : static fn (): int => 0;

        return static fn (array $left, array $right): int => $values($left[0], $right[0]) ?: $left[1] <=> $right[1];
    }

    /**
     * @param list<int|float|string|bool|null> $after
     *
     * @return array{int|float|string|bool|null, int}
     */
    private function position(array $after): array
    {
        return 2 === \count($after) && \is_int($after[1]) ? [$after[0], $after[1]] : throw ScimException::invalidCursor();
    }

    /**
     * A required version also fails for a resource that does not exist (RFC 7232 section 3.1).
     *
     * @return array<mixed>
     *
     * @throws ScimException
     */
    private function storedMeta(string $id, ?string $version): array
    {
        $stored = $this->documents[$id] ?? null;
        $meta = \is_array($stored['meta'] ?? null) ? $stored['meta'] : [];

        if (null !== $version && $version !== ($meta['version'] ?? null)) {
            throw ScimException::preconditionFailed('Failed to update. Resource has changed on the server.');
        }

        return null === $stored ? throw $this->notFound($id) : $meta;
    }

    /**
     * @return array<array-key, array<string, mixed>> keyed by id
     */
    private function matching(?Filter $filter): array
    {
        if (!$filter instanceof Filter) {
            return $this->documents;
        }

        return array_filter($this->documents, fn (array $document): bool => $this->evaluator->matches($filter, $document, $this->type));
    }

    /**
     * The id and meta of the client are ignored (RFC 7643 section 3.1).
     *
     * @param array<string, mixed> $document
     * @param array<mixed>         $meta
     *
     * @return array<string, mixed>
     */
    private function withIdAndMeta(array $document, string $id, array $meta): array
    {
        unset($document['id'], $document['meta']);

        return [...array_intersect_key($document, ['schemas' => true]), 'id' => $id, 'meta' => $meta, ...$document];
    }

    /**
     * A seeded version that is not W/"<n>" counts as 0, so the next version still differs from it.
     *
     * @param array<mixed> $meta
     */
    private function revision(array $meta): int
    {
        $version = $meta['version'] ?? null;

        return \is_string($version) && 1 === preg_match('/\AW\/"(\d+)"\z/', $version, $matches) ? (int) $matches[1] : 0;
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sp');
    }

    private function notFound(string $id): ScimException
    {
        return ScimException::notFound(\sprintf('Resource %s not found.', $id));
    }
}
