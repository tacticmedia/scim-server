<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Psr15;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use TacticMedia\ScimServer\Server\ScimServer;

/**
 * Needs the suggested packages psr/http-message, psr/http-server-handler and symfony/psr-http-message-bridge.
 */
final readonly class ScimRequestHandler implements RequestHandlerInterface
{
    /**
     * @param string $basePath see {@see ScimServer::handle()}
     */
    public function __construct(
        private ScimServer $server,
        private string $basePath,
        private HttpMessageFactoryInterface $psrHttpFactory,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $symfonyRequest = new HttpFoundationFactory()->createRequest($request);
        $symfonyResponse = $this->server->handle($symfonyRequest, $this->basePath)->prepare($symfonyRequest);

        return $this->psrHttpFactory->createResponse($symfonyResponse);
    }
}
