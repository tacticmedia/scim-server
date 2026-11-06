<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Event\ResourceCreated;
use TacticMedia\ScimServer\Event\ResourceDeleted;
use TacticMedia\ScimServer\Event\ResourceUpdated;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Patch\DeltaSplit;
use TacticMedia\ScimServer\Patch\PatchApplier;
use TacticMedia\ScimServer\Patch\PatchRedactor;
use TacticMedia\ScimServer\Patch\PatchRequest;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Resource\DocumentNormalizer;
use TacticMedia\ScimServer\Resource\DocumentProjector;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Returned;
use TacticMedia\ScimServer\Schema\Schema;

/**
 * Create, retrieve, replace, patch and delete one resource. Query parameters are read before the repository is
 * written, so that a bad parameter never fails a request that changed data.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.3
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.1
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.5
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.6
 */
final readonly class ResourceHandler
{
    private DeltaPatcher $deltas;

    public function __construct(
        private ResourcePresenter $presenter,
        private ResponseFactory $responses,
        private ?EventDispatcherInterface $events,
        private UniquenessChecker $uniqueness = new UniquenessChecker(),
        private DocumentNormalizer $normalizer = new DocumentNormalizer(),
        private PatchApplier $patches = new PatchApplier(),
        private DocumentProjector $projector = new DocumentProjector(),
        private PatchRedactor $redactor = new PatchRedactor(),
    ) {
        $this->deltas = new DeltaPatcher($events, $uniqueness, $normalizer, $patches, $projector, $redactor);
    }

    /**
     * @throws ScimException
     */
    public function create(Request $request, string $baseUrl, RegisteredResourceType $registered): Response
    {
        $projection = $this->projection($request);
        $body = RequestBody::object($request);
        $named = RequestedAttributes::inBody($body, $registered->type);
        $document = $this->normalizer->forCreate($body, $registered->type);
        $this->uniqueness->check($document, $registered);
        $created = $registered->repository->create($document);
        $this->events?->dispatch(new ResourceCreated($registered->type, $this->projector->returnable($created, $registered->type)));

        return $this->respond($request, $baseUrl, $registered, $created, $projection, $named, Response::HTTP_CREATED, [
            'Location' => $this->presenter->location($created, $registered->type, $baseUrl),
        ]);
    }

    /**
     * @throws ScimException
     */
    public function retrieve(Request $request, string $baseUrl, RegisteredResourceType $registered, string $id): Response
    {
        $projection = $this->projection($request);
        $document = $this->find($registered, $id, $projection->resolvedFor($registered->type));
        $etag = ETag::of($document);

        if ($this->ifMatchFails($request, $etag)) {
            throw ScimException::preconditionFailed('The resource does not match If-Match.');
        }

        if ($this->ifNoneMatchFails($request, $etag)) {
            return $this->responses->withoutBody(Response::HTTP_NOT_MODIFIED, ['ETag' => $etag]);
        }

        return $this->respond($request, $baseUrl, $registered, $document, $projection);
    }

    /**
     * @throws ScimException
     */
    public function replace(Request $request, string $baseUrl, RegisteredResourceType $registered, string $id): Response
    {
        $projection = $this->projection($request);
        $body = RequestBody::object($request);
        $named = RequestedAttributes::inBody($body, $registered->type);
        $existing = $this->findUnchanged($request, $registered, $id);
        $input = [...$body, ...$this->unreturnedValues($existing, $named, $registered->type)];
        $replaced = $this->store($request, $registered, $id, $existing, $this->normalizer->forReplace($input, $existing, $registered->type), null);

        return $this->respond($request, $baseUrl, $registered, $replaced, $projection, $named);
    }

    /**
     * 204, or 200 with the resource when the request has "attributes" or "excludedAttributes" (RFC 7644 section
     * 3.5.2 MUST). Microsoft Entra ID recommends 204 for groups, Okta accepts both.
     *
     * @throws ScimException
     */
    public function patch(Request $request, string $baseUrl, RegisteredResourceType $registered, string $id): Response
    {
        $parameters = new RequestParameters($request->query->all());
        $projection = RequestedProjection::from($parameters);
        $patch = PatchRequest::fromArray(RequestBody::object($request));
        $split = $this->deltas->split($patch, $registered);
        $document = $split instanceof DeltaSplit
            ? $this->patchByDelta($request, $registered, $id, $patch, $split)
            : $this->patchWhole($request, $registered, $id, $patch);

        if (!$parameters->has('attributes') && !$parameters->has('excludedAttributes')) {
            return $this->responses->withoutBody(Response::HTTP_NO_CONTENT, ['ETag' => ETag::of($document)]);
        }

        // The delta path document lacks the delta attributes, which the projection can return.
        $resource = $split instanceof DeltaSplit ? $this->find($registered, $id, $projection->resolvedFor($registered->type)) : $document;

        return $this->respond($request, $baseUrl, $registered, $resource, $projection, RequestedAttributes::inPatch($patch, $registered->type));
    }

    /**
     * @throws ScimException
     */
    public function delete(Request $request, RegisteredResourceType $registered, string $id): Response
    {
        $existing = $this->findUnchanged($request, $registered, $id);
        $registered->repository->delete($id, $this->requiredVersion($request, $existing));
        $this->events?->dispatch(new ResourceDeleted($registered->type, $this->projector->returnable($existing, $registered->type)));

        return $this->responses->withoutBody(Response::HTTP_NO_CONTENT);
    }

    /**
     * The stored values that GET never returns and the PUT body does not name, as PATCH keeps them too. A client that
     * builds the body from GET output, as Okta does, cannot send them back; RFC 7644 section 3.5.1 lets the server
     * clear only omitted readWrite values.
     *
     * @param array<string, mixed> $existing
     * @param list<AttributePath>  $named
     *
     * @return array<string, mixed> by URN-qualified path
     */
    private function unreturnedValues(array $existing, array $named, ResourceType $type): array
    {
        $namedPaths = array_map(static fn (AttributePath $path): string => (string) $type->resolve($path)?->path(), $named);
        $values = [];

        foreach ($type->schemas() as $schema) {
            $container = $schema === $type->schema ? $existing : $existing[$schema->id] ?? null;
            $values = [...$values, ...$this->unreturnedIn($schema, \is_array($container) ? $container : [], $namedPaths)];
        }

        return $values;
    }

    /**
     * @param array<mixed> $container
     * @param list<string> $namedPaths
     *
     * @return array<string, mixed>
     */
    private function unreturnedIn(Schema $schema, array $container, array $namedPaths): array
    {
        $values = [];

        foreach ($schema->attributes as $attribute) {
            $path = $schema->id.':'.$attribute->name;

            if (Returned::Never === $attribute->returned && null !== ($container[$attribute->name] ?? null) && !\in_array($path, $namedPaths, true)) {
                $values[$path] = $container[$attribute->name];
            }
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function patchWhole(Request $request, RegisteredResourceType $registered, string $id, PatchRequest $patch): array
    {
        $existing = $this->findUnchanged($request, $registered, $id);
        $patched = $this->patches->apply($existing, $patch, $registered->type);

        // RFC 7644 section 3.5.2.1: a PATCH that changes nothing keeps meta.lastModified, and thus the ETag.
        return $patched === $existing
            ? $existing
            : $this->store($request, $registered, $id, $existing, $this->normalizer->forReplace($patched, $existing, $registered->type), $patch);
    }

    /**
     * @return array<string, mixed> without the delta attributes
     */
    private function patchByDelta(Request $request, RegisteredResourceType $registered, string $id, PatchRequest $patch, DeltaSplit $split): array
    {
        $light = $this->deltas->versioned($this->find($registered, $id, new RequestedProjection(excludedAttributes: $split->deltaAttributes)), $registered);
        $this->unchanged($request, $light);

        return $this->deltas->patch($registered, $id, $light, $split, $patch, $this->requiredVersion($request, $light));
    }

    private function projection(Request $request): RequestedProjection
    {
        return RequestedProjection::from(new RequestParameters($request->query->all()));
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $document normalized
     *
     * @return array<string, mixed>
     */
    private function store(Request $request, RegisteredResourceType $registered, string $id, array $before, array $document, ?PatchRequest $patch): array
    {
        $this->uniqueness->check($document, $registered, $id);
        $after = $registered->repository->replace($id, $document, $this->requiredVersion($request, $before));
        $type = $registered->type;
        $this->events?->dispatch(new ResourceUpdated($type, $this->projector->returnable($before, $type), $this->projector->returnable($after, $type), $patch instanceof PatchRequest ? $this->redactor->redact($patch, $type) : null));

        return $after;
    }

    /**
     * @param array<string, mixed>  $document
     * @param list<AttributePath>   $requested
     * @param array<string, string> $headers
     */
    private function respond(
        Request $request,
        string $baseUrl,
        RegisteredResourceType $registered,
        array $document,
        RequestedProjection $projection,
        array $requested = [],
        int $status = Response::HTTP_OK,
        array $headers = [],
    ): Response {
        $resource = $this->presenter->present($document, $registered->type, $baseUrl, $projection, $requested);

        return $this->responses->json($request, $resource, $status, ['ETag' => ETag::of($document), ...$headers]);
    }

    /**
     * @return array<string, mixed>
     */
    private function find(RegisteredResourceType $registered, string $id, RequestedProjection $hints = new RequestedProjection()): array
    {
        return $registered->repository->get($id, $hints->attributes, $hints->excludedAttributes)
            ?? throw ScimException::notFound(\sprintf('Resource %s not found.', $id));
    }

    /**
     * If-Match, then If-None-Match on PUT, PATCH and DELETE (RFC 7232 section 6).
     *
     * @return array<string, mixed>
     */
    private function findUnchanged(Request $request, RegisteredResourceType $registered, string $id): array
    {
        return $this->unchanged($request, $this->find($registered, $id));
    }

    /**
     * @param array<string, mixed> $existing
     *
     * @return array<string, mixed>
     */
    private function unchanged(Request $request, array $existing): array
    {
        $etag = ETag::of($existing);

        if ($this->ifMatchFails($request, $etag)) {
            throw ScimException::preconditionFailed('Failed to update. Resource has changed on the server.');
        }

        if ($this->ifNoneMatchFails($request, $etag)) {
            throw ScimException::preconditionFailed('The resource matches If-None-Match.');
        }

        return $existing;
    }

    /**
     * The stored meta.version that an If-Match with an entity tag requires, so that the repository can write
     * atomically.
     *
     * @param array<string, mixed> $existing
     */
    private function requiredVersion(Request $request, array $existing): ?string
    {
        $ifMatch = $request->headers->get('If-Match');

        return null === $ifMatch || ETag::isAny($ifMatch) ? null : ETag::version($existing);
    }

    /**
     * @see https://www.rfc-editor.org/rfc/rfc7232#section-3.1
     */
    private function ifMatchFails(Request $request, string $etag): bool
    {
        $ifMatch = $request->headers->get('If-Match');

        return null !== $ifMatch && !ETag::matches($ifMatch, $etag);
    }

    /**
     * @see https://www.rfc-editor.org/rfc/rfc7232#section-3.2
     */
    private function ifNoneMatchFails(Request $request, string $etag): bool
    {
        $ifNoneMatch = $request->headers->get('If-None-Match');

        return null !== $ifNoneMatch && ETag::matches($ifNoneMatch, $etag);
    }
}
