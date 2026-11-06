<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\FilterResolver;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * /Me without a resolver: 501. With one, GET, HEAD, PUT, PATCH and DELETE act on the resource of the subject, and each
 * response after the lookup has a Location header with the URL of that resource. POST: 501.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.11
 */
final readonly class MeHandler
{
    /**
     * @param list<RegisteredResourceType> $resourceTypes
     */
    public function __construct(
        private ?MeResolver $resolver,
        private array $resourceTypes,
        private ResourcePresenter $presenter,
        private ResponseFactory $responses,
        private FilterResolver $filters = new FilterResolver(),
    ) {
    }

    /**
     * @param string                                                                     $method          HEAD as GET
     * @param \Closure(Request, string, string, RegisteredResourceType, Route): Response $forResourceType
     *
     * @throws ScimException
     */
    public function handle(Request $request, string $method, string $baseUrl, \Closure $forResourceType): Response
    {
        if (!$this->resolver instanceof MeResolver) {
            throw ScimException::notImplemented('The /Me endpoint is not supported.');
        }

        if ('POST' === $method) {
            throw ScimException::notImplemented('POST /Me is not supported.');
        }

        $subject = $this->resolver->resolve($request) ?? throw $this->noResource();
        $registered = $this->registered($subject);
        $id = $this->idOf($subject, $registered);

        try {
            $response = $forResourceType($request, $method, $baseUrl, $registered, new Route(Endpoint::Resource, $registered, $id));
        } catch (ScimException $scimException) {
            $response = $this->responses->error($request, $scimException);
        }

        $response->headers->set('Location', $this->presenter->location(['id' => $id], $registered->type, $baseUrl));

        return $response;
    }

    private function registered(MeSubject $subject): RegisteredResourceType
    {
        return array_find($this->resourceTypes, static fn (RegisteredResourceType $registered): bool => 0 === strcasecmp($registered->type->name, $subject->resourceType))
            ?? throw new \LogicException(\sprintf('The MeResolver names the resource type "%s", which is not registered.', $subject->resourceType));
    }

    /**
     * @throws ScimException
     */
    private function idOf(MeSubject $subject, RegisteredResourceType $registered): string
    {
        if (0 === strcasecmp($subject->attribute, 'id')) {
            return $subject->value;
        }

        $matches = $registered->repository->list(new ListQuery($this->filter($subject, $registered->type), count: 2))->resources;
        $id = $matches[0]['id'] ?? null;

        return match (true) {
            [] === $matches => throw $this->noResource(),
            \count($matches) > 1 => throw ScimException::conflict('The authenticated subject matches more than one resource.'),
            default => \is_string($id) ? $id : throw new \UnexpectedValueException('The repository returned a document without a string "id".'),
        };
    }

    /**
     * An AST, so that a value cannot inject filter syntax.
     */
    private function filter(MeSubject $subject, ResourceType $type): Filter
    {
        try {
            $path = AttributePath::parse($subject->attribute) ?? throw ScimException::invalidFilter('Not an attribute path.');

            return $this->filters->resolve(new Comparison($path, ComparisonOperator::Equal, $subject->value), $type);
        } catch (ScimException $scimException) {
            throw new \LogicException(\sprintf('The MeResolver names the attribute "%s", which the resource type %s cannot filter.', $subject->attribute, $type->name), $scimException->getCode(), previous: $scimException);
        }
    }

    private function noResource(): ScimException
    {
        return ScimException::notFound('The authenticated subject has no resource.');
    }
}
