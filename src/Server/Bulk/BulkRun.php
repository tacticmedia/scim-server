<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server\Bulk;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Server\Endpoint;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\ResourcePresenter;
use TacticMedia\ScimServer\Server\Route;
use TacticMedia\ScimServer\Server\Router;

/**
 * Runs the operations of one bulk request. Each step settles the first completion of a cut POST whose deferred POSTs
 * have run, else runs the first operation in request order that waits for no POST (RFC 7644 section 3.7 permits the
 * reordering). When every operation waits, they wait on a cycle: the first POST of the cycle whose removed attributes
 * are not required is created without them and completed with a PATCH ({@see BulkCut}). When no POST of the cycle
 * qualifies, the first one fails with 409 (RFC 7644 section 3.7.1).
 *
 * @internal
 */
final class BulkRun
{
    private readonly BulkSchedule $schedule;

    /** @var array<string, string> the id of the created resource by bulkId */
    private array $createdIds = [];

    /** @var array<int, BulkCut> the created cut POSTs whose completion has not settled, by index, in request order */
    private array $completions = [];

    /** @var array<int, array<string, mixed>> by operation index */
    private array $results = [];

    private int $errors = 0;

    /**
     * @param \Closure(Request, string, string, RegisteredResourceType, Route): Response $dispatch
     */
    public function __construct(
        private readonly BulkRequest $bulk,
        private readonly string $baseUrl,
        private readonly \Closure $dispatch,
        private readonly Router $router,
        private readonly ResourcePresenter $presenter,
    ) {
        $this->schedule = new BulkSchedule($bulk);
    }

    /**
     * @return list<array<string, mixed>> the result of each operation that ran, in request order
     */
    public function run(): array
    {
        while ((!$this->schedule->isEmpty() || [] !== $this->completions) && !$this->stopped()) {
            $this->step();
        }

        // RFC 7644 section 3.7.3: the resource of a cut POST exists, so its result is reported after the stop too.
        foreach ($this->completions as $cut) {
            $this->interrupt($cut);
        }

        ksort($this->results);

        return array_values($this->results);
    }

    private function step(): void
    {
        $completion = array_find($this->completions, fn (BulkCut $cut): bool => !array_any($cut->deferredBulkIds, $this->schedule->isPending(...)));

        if ($completion instanceof BulkCut) {
            $this->settle($completion);

            return;
        }

        $ready = $this->schedule->ready();

        if ($ready instanceof BulkOperation) {
            $this->execute($ready);

            return;
        }

        $this->cut($this->schedule->cycle());
    }

    private function stopped(): bool
    {
        return null !== $this->bulk->failOnErrors && $this->errors >= $this->bulk->failOnErrors;
    }

    /**
     * @param list<BulkOperation> $cycle each waits for the next, the last for the first
     */
    private function cut(array $cycle): void
    {
        $candidates = $cycle;
        usort($candidates, static fn (BulkOperation $left, BulkOperation $right): int => $left->index <=> $right->index);

        foreach ($candidates as $post) {
            if ($this->tryCut($post)) {
                return;
            }
        }

        $this->failCycle($cycle, $candidates[0]);
    }

    /**
     * @return bool false when the POST holds a deferred reference in a required attribute
     */
    private function tryCut(BulkOperation $post): bool
    {
        try {
            [, $registered] = $post->route($this->router);
        } catch (ScimException) {
            // It fails at its route, as without the cycle.
            $this->execute($post);

            return true;
        }

        $cut = BulkCut::of($post, $this->schedule->waitsFor($post), $registered);

        if ($cut instanceof BulkCut) {
            $this->execute($cut->post, $cut->deferredBulkIds);
            $this->awaitCompletion($cut);
        }

        return $cut instanceof BulkCut;
    }

    /**
     * The result of the create stays until the completion settles.
     */
    private function awaitCompletion(BulkCut $cut): void
    {
        if ([] !== $cut->additions && isset($this->createdIds[(string) $cut->post->bulkId])) {
            $this->completions[$cut->post->index] = $cut;
            ksort($this->completions);
        }
    }

    /**
     * @param list<BulkOperation> $cycle
     */
    private function failCycle(array $cycle, BulkOperation $first): void
    {
        $start = (int) array_search($first, $cycle, true);
        $bulkIds = array_map(static fn (BulkOperation $post): string => (string) $post->bulkId, [...\array_slice($cycle, $start), ...\array_slice($cycle, 0, $start), $first]);
        $this->schedule->take($first);
        $this->fail($first, ScimException::conflict(\sprintf('Circular bulkId references (%s) cannot be resolved, because each POST of the cycle references it in a required attribute.', implode(' -> ', $bulkIds))), null);
    }

    /**
     * @param list<string> $deferredBulkIds the references that the request of a cut POST leaves out
     */
    private function execute(BulkOperation $operation, array $deferredBulkIds = []): void
    {
        $this->schedule->take($operation);
        // RFC 7644 section 3.7.3: only a failed POST has no location.
        $location = 'POST' === $operation->method ? null : $this->baseUrl.'/'.ltrim($operation->path, '/');

        try {
            [$route, $registered] = $operation->route($this->router);
            $location = 'POST' === $operation->method ? null : $this->presenter->location(['id' => $route->id], $registered->type, $this->baseUrl);
            $response = ($this->dispatch)($this->request($operation, $deferredBulkIds), $operation->method, $this->baseUrl, $registered, $route);
            $this->succeed($operation, $response, $location ?? $response->headers->get('Location'));
        } catch (ScimException $scimException) {
            $this->fail($operation, $scimException, $location);
        }
    }

    /**
     * @param list<string> $deferredBulkIds
     *
     * @throws ScimException when a referenced POST failed or no POST defines the bulkId
     */
    private function request(BulkOperation $operation, array $deferredBulkIds): Request
    {
        foreach (array_diff($this->schedule->references($operation), $deferredBulkIds) as $bulkId) {
            $this->assertCreated($bulkId);
        }

        return $operation->toRequest($this->createdIds);
    }

    private function assertCreated(string $bulkId): void
    {
        if (!$this->schedule->defines($bulkId)) {
            throw ScimException::invalidValue(\sprintf('No POST operation of the request has the bulkId "%s".', $bulkId));
        }

        if (!isset($this->createdIds[$bulkId])) {
            throw ScimException::conflict(\sprintf('The POST operation with the bulkId "%s" failed.', $bulkId));
        }
    }

    private function settle(BulkCut $cut): void
    {
        unset($this->completions[$cut->post->index]);
        $route = new Route(Endpoint::Resource, $cut->registered, $this->createdIds[(string) $cut->post->bulkId]);

        try {
            $response = $this->complete($cut, $route);
            $this->record($cut->post, Response::HTTP_CREATED, $this->locationOf($cut), $response->headers->get('ETag'));
        } catch (ScimException $scimException) {
            $this->fail($cut->post, $scimException, $this->locationOf($cut));
        }
    }

    /**
     * @throws ScimException 409 when a deferred POST failed, else the error of the PATCH
     */
    private function complete(BulkCut $cut, Route $route): Response
    {
        $failed = $this->failedPost($cut);

        if (null !== $failed) {
            throw $this->uncompleted($failed);
        }

        try {
            return ($this->dispatch)($cut->completion()->toRequest($this->createdIds), 'PATCH', $this->baseUrl, $cut->registered, $route);
        } catch (ScimException $scimException) {
            throw new ScimException($scimException->status, 'The resource was created, but its circular bulkId references were not added: '.$scimException->detail, $scimException->scimType, $scimException);
        }
    }

    /**
     * After failOnErrors stopped the request. The result does not count as an error.
     */
    private function interrupt(BulkCut $cut): void
    {
        $this->record($cut->post, Response::HTTP_CONFLICT, $this->locationOf($cut), null, $this->uncompleted($this->failedPost($cut))->toArray());
    }

    /**
     * The first deferred POST in request order that ran and failed.
     */
    private function failedPost(BulkCut $cut): ?string
    {
        return array_find($cut->deferredBulkIds, fn (string $bulkId): bool => !$this->schedule->isPending($bulkId) && !isset($this->createdIds[$bulkId]));
    }

    /**
     * @param string|null $failedBulkId null when failOnErrors stopped the request
     */
    private function uncompleted(?string $failedBulkId): ScimException
    {
        $reason = null === $failedBulkId ? 'failOnErrors stopped the bulk request' : \sprintf('the POST operation with the bulkId "%s" failed', $failedBulkId);

        return ScimException::conflict(\sprintf('The resource was created without its circular bulkId references, because %s.', $reason));
    }

    private function locationOf(BulkCut $cut): string
    {
        return $this->presenter->location(['id' => $this->createdIds[(string) $cut->post->bulkId]], $cut->registered->type, $this->baseUrl);
    }

    private function succeed(BulkOperation $operation, Response $response, ?string $location): void
    {
        if (null !== $operation->bulkId) {
            $this->createdIds[$operation->bulkId] = $this->createdId($response);
        }

        $this->record($operation, $response->getStatusCode(), $location, $response->headers->get('ETag'));
    }

    private function fail(BulkOperation $operation, ScimException $exception, ?string $location): void
    {
        ++$this->errors;
        $this->record($operation, $exception->status, $location, null, $exception->toArray());
    }

    /**
     * Keys in the order of the RFC 7644 section 3.7.3 examples. "status" is a string, as there (erratum 7916).
     *
     * @param array<string, mixed>|null $error
     */
    private function record(BulkOperation $operation, int $status, ?string $location, ?string $version, ?array $error = null): void
    {
        $this->results[$operation->index] = array_filter([
            'location' => $location,
            'method' => $operation->method,
            'bulkId' => $operation->bulkId,
            'version' => $version,
            'status' => (string) $status,
            'response' => $error,
        ], static fn (mixed $value): bool => null !== $value);
    }

    private function createdId(Response $response): string
    {
        $resource = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $id = \is_array($resource) ? $resource['id'] ?? null : null;

        return \is_string($id) ? $id : throw new \UnexpectedValueException('The created resource has no string "id".');
    }
}
