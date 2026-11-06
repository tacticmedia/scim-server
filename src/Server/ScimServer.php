<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Server\Bulk\BulkHandler;

/**
 * Answers SCIM 2.0 requests for the registered resource types. A {@see ScimException} becomes a SCIM error response;
 * other exceptions propagate.
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644
 */
final readonly class ScimServer
{
    private Router $router;

    private ResponseFactory $responses;

    private RootListHandler $rootLists;

    private ListHandler $lists;

    private ResourceHandler $resources;

    private DiscoveryHandler $discovery;

    private BulkHandler $bulk;

    private MeHandler $me;

    /**
     * @param list<RegisteredResourceType> $resourceTypes
     * @param MeResolver|null              $me            null: /Me answers 501
     * @param string|null                  $cursorKey     the secret that encrypts RFC 9865 cursors, the same in every
     *                                                    process; null: cursor pagination off
     * @param ClockInterface|null          $clock         reads the time for ServiceProviderConfig::$cursorTimeoutSeconds;
     *                                                    null: the system clock
     */
    public function __construct(
        private array $resourceTypes,
        ServiceProviderConfig $config = new ServiceProviderConfig(),
        ?EventDispatcherInterface $eventDispatcher = null,
        ?MeResolver $me = null,
        #[\SensitiveParameter] ?string $cursorKey = null,
        ?ClockInterface $clock = null,
    ) {
        $presenter = new ResourcePresenter($resourceTypes);
        $this->router = new Router($resourceTypes);
        $this->responses = new ResponseFactory();
        $paginator = new Paginator($this->responses, null === $cursorKey ? null : new CursorCodec($cursorKey, $config->cursorTimeoutSeconds, $clock));
        $this->lists = new ListHandler($presenter, $paginator, $config->maxResults);
        $this->resources = new ResourceHandler($presenter, $this->responses, $eventDispatcher);
        $this->discovery = new DiscoveryHandler($resourceTypes, $config, $this->responses, $paginator->cursorsEnabled());
        $this->rootLists = new RootListHandler($this->lists, $presenter, $paginator, $config->maxResults);
        $this->bulk = new BulkHandler($config, $this->router, $presenter, $this->responses);
        $this->me = new MeHandler($me, $resourceTypes, $presenter, $this->responses);
    }

    /**
     * @param string $basePath the URL path prefix of the SCIM API, for example "/scim/v2", or "" at the root
     */
    public function handle(Request $request, string $basePath): Response
    {
        try {
            return $this->dispatch($request, rtrim($basePath, '/'));
        } catch (ScimException $scimException) {
            return $this->responses->error($request, $scimException);
        }
    }

    private function dispatch(Request $request, string $basePath): Response
    {
        $route = $this->router->match($request->getPathInfo(), $basePath)
            ?? throw ScimException::notFound(\sprintf('The endpoint %s does not exist.', $request->getPathInfo()));
        $methods = $route->endpoint->methods();

        if (!\in_array($request->getMethod(), $methods, true)) {
            $allowed = implode(', ', $methods);

            return $this->responses->error($request, ScimException::methodNotAllowed(\sprintf('The method %s is not allowed. Allowed: %s.', $request->getMethod(), $allowed)), ['Allow' => $allowed]);
        }

        $baseUrl = $request->getSchemeAndHttpHost().$request->getBaseUrl().$basePath;
        // A HEAD response has the headers of GET; Response::prepare() drops the body.
        $method = $request->isMethod('HEAD') ? 'GET' : $request->getMethod();

        if ($route->resourceType instanceof RegisteredResourceType) {
            return $this->forResourceType($request, $method, $baseUrl, $route->resourceType, $route);
        }

        return match ($route->endpoint) {
            Endpoint::Root => $this->rootLists->query($request, $baseUrl, $this->resourceTypes),
            Endpoint::RootSearch => $this->rootLists->search($request, $baseUrl, $this->resourceTypes),
            Endpoint::Me => $this->me->handle($request, $method, $baseUrl, $this->forResourceType(...)),
            Endpoint::Bulk => $this->bulk->handle($request, $baseUrl, $this->forResourceType(...)),
            default => $this->discovery->handle($request, $baseUrl, $route),
        };
    }

    private function forResourceType(Request $request, string $method, string $baseUrl, RegisteredResourceType $registered, Route $route): Response
    {
        return match ([$route->endpoint, $method]) {
            [Endpoint::Collection, 'GET'] => $this->lists->query($request, $baseUrl, [$registered], false),
            [Endpoint::Collection, 'POST'] => $this->resources->create($request, $baseUrl, $registered),
            [Endpoint::CollectionSearch, 'POST'] => $this->lists->search($request, $baseUrl, [$registered], false),
            [Endpoint::Resource, 'GET'] => $this->resources->retrieve($request, $baseUrl, $registered, $route->id),
            [Endpoint::Resource, 'PUT'] => $this->resources->replace($request, $baseUrl, $registered, $route->id),
            [Endpoint::Resource, 'PATCH'] => $this->resources->patch($request, $baseUrl, $registered, $route->id),
            default => $this->resources->delete($request, $registered, $route->id),
        };
    }
}
