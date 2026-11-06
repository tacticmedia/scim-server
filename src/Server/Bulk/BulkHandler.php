<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server\Bulk;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\MessageUrn;
use TacticMedia\ScimServer\Server\RegisteredResourceType;
use TacticMedia\ScimServer\Server\RequestBody;
use TacticMedia\ScimServer\Server\ResourcePresenter;
use TacticMedia\ScimServer\Server\ResponseFactory;
use TacticMedia\ScimServer\Server\Route;
use TacticMedia\ScimServer\Server\Router;
use TacticMedia\ScimServer\Server\ServiceProviderConfig;

/**
 * POST /Bulk. Each operation runs as the single request with the same method, path and body would run.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.7
 */
final readonly class BulkHandler
{
    public function __construct(
        private ServiceProviderConfig $config,
        private Router $router,
        private ResourcePresenter $presenter,
        private ResponseFactory $responses,
    ) {
    }

    /**
     * @param \Closure(Request, string, string, RegisteredResourceType, Route): Response $dispatch request, method, base URL, resource type and route of a single request
     *
     * @throws ScimException 501 when bulk is turned off, 413 above a limit (RFC 7644 section 3.7.4), invalidSyntax
     */
    public function handle(Request $request, string $baseUrl, \Closure $dispatch): Response
    {
        if (!$this->config->bulkSupported) {
            throw ScimException::notImplemented('Bulk operations are not supported.');
        }

        $this->assertPayloadSize($request);
        $bulk = BulkRequest::fromArray(RequestBody::object($request), $this->config->bulkMaxOperations);

        return $this->responses->json($request, [
            'schemas' => [MessageUrn::BulkResponse->value],
            'Operations' => new BulkRun($bulk, $baseUrl, $dispatch, $this->router, $this->presenter)->run(),
        ]);
    }

    /**
     * Content-Length first, so that an oversized body is not read.
     */
    private function assertPayloadSize(Request $request): void
    {
        $maxBytes = $this->config->bulkMaxPayloadSizeBytes;

        if ((int) $request->headers->get('Content-Length') > $maxBytes || \strlen($request->getContent()) > $maxBytes) {
            throw ScimException::payloadTooLarge(\sprintf('The size of the bulk operation exceeds the maxPayloadSize (%d).', $maxBytes));
        }
    }
}
