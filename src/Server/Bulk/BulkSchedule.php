<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server\Bulk;

/**
 * The operations of one bulk request that have not run, and the POST operations that each one waits for.
 *
 * @internal
 */
final class BulkSchedule
{
    /** @var array<int, BulkOperation> by index, in request order */
    private array $pending = [];

    /** @var array<int, list<string>> the referenced bulkIds by operation index */
    private array $references = [];

    /** @var array<string, int> the operation index by bulkId */
    private array $posts = [];

    /** @var array<int, list<int>> the indexes of the referenced POST operations by operation index, in request order */
    private array $targets = [];

    public function __construct(BulkRequest $bulk)
    {
        foreach ($bulk->operations as $operation) {
            $this->pending[$operation->index] = $operation;
            $this->references[$operation->index] = $operation->references();

            if (null !== $operation->bulkId) {
                $this->posts[$operation->bulkId] = $operation->index;
            }
        }

        foreach ($this->references as $index => $bulkIds) {
            $this->targets[$index] = array_values(array_intersect_key($this->posts, array_flip($bulkIds)));
        }
    }

    public function isEmpty(): bool
    {
        return [] === $this->pending;
    }

    public function take(BulkOperation $operation): void
    {
        unset($this->pending[$operation->index]);
    }

    public function defines(string $bulkId): bool
    {
        return isset($this->posts[$bulkId]);
    }

    public function isPending(string $bulkId): bool
    {
        return isset($this->posts[$bulkId], $this->pending[$this->posts[$bulkId]]);
    }

    /**
     * The bulkIds that the operation as sent references.
     *
     * @return list<string>
     */
    public function references(BulkOperation $operation): array
    {
        return $this->references[$operation->index];
    }

    /**
     * The bulkIds of the pending POST operations that $operation references, in request order.
     *
     * @return list<string>
     */
    public function waitsFor(BulkOperation $operation): array
    {
        return array_map(fn (int $post): string => (string) $this->pending[$post]->bulkId, $this->pendingTargets($operation->index));
    }

    /**
     * The first pending operation in request order that waits for no POST.
     */
    public function ready(): ?BulkOperation
    {
        return array_find($this->pending, fn (BulkOperation $operation): bool => [] === $this->pendingTargets($operation->index));
    }

    /**
     * Walks from the first pending operation to the first POST that it waits for, and on. Only when no operation is
     * ready: then each pending operation waits for a pending POST, so the walk closes a cycle.
     *
     * @return list<BulkOperation> the POST operations of the cycle; each waits for the next, the last for the first
     */
    public function cycle(): array
    {
        $walk = [];
        $index = array_key_first($this->pending) ?? throw new \LogicException('No bulk operation is pending.');

        while (!\in_array($index, $walk, true)) {
            $walk[] = $index;
            $index = $this->pendingTargets($index)[0] ?? throw new \LogicException('A pending bulk operation is ready.');
        }

        return array_map(fn (int $post): BulkOperation => $this->pending[$post], \array_slice($walk, (int) array_search($index, $walk, true)));
    }

    /**
     * @return list<int>
     */
    private function pendingTargets(int $index): array
    {
        return array_values(array_filter($this->targets[$index], fn (int $post): bool => isset($this->pending[$post])));
    }
}
