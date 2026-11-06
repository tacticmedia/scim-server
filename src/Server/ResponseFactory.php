<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\AcceptHeaderItem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\MessageUrn;

/**
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.8
 */
final readonly class ResponseFactory
{
    /** Error details echo request input, which can hold invalid UTF-8. */
    private const int JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public function json(Request $request, array $body, int $status = Response::HTTP_OK, array $headers = []): Response
    {
        return new Response(json_encode($body, self::JSON_FLAGS), $status, ['Content-Type' => $this->contentType($request), ...$headers]);
    }

    /**
     * "Resources" is [] when the page is empty, as Microsoft Entra ID and Okta expect.
     *
     * @param list<array<string, mixed>> $resources
     *
     * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.2
     */
    public function list(Request $request, array $resources, int $totalResults, int $startIndex = 1): Response
    {
        return $this->json($request, [
            'schemas' => [MessageUrn::ListResponse->value],
            'totalResults' => $totalResults,
            'itemsPerPage' => \count($resources),
            'startIndex' => $startIndex,
            'Resources' => $resources,
        ]);
    }

    /**
     * A ListResponse of cursor pagination: no "startIndex", "totalResults" only when known, "nextCursor" while a
     * resource follows.
     *
     * @param list<array<string, mixed>> $resources
     *
     * @see https://www.rfc-editor.org/rfc/rfc9865#section-2
     */
    public function cursorList(Request $request, array $resources, ?int $totalResults, ?string $nextCursor): Response
    {
        return $this->json($request, array_filter([
            'schemas' => [MessageUrn::ListResponse->value],
            'totalResults' => $totalResults,
            'itemsPerPage' => \count($resources),
            'nextCursor' => $nextCursor,
            'Resources' => $resources,
        ], static fn (mixed $value): bool => null !== $value));
    }

    /**
     * @param array<string, string> $headers
     */
    public function error(Request $request, ScimException $exception, array $headers = []): Response
    {
        return $this->json($request, $exception->toArray(), $exception->status, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withoutBody(int $status, array $headers = []): Response
    {
        return new Response('', $status, $headers);
    }

    /**
     * A client that accepts application/json but not application/scim+json gets application/json.
     */
    private function contentType(Request $request): string
    {
        $mediaTypes = array_map(
            static fn (AcceptHeaderItem $item): string => strtolower($item->getValue()),
            AcceptHeader::fromString($request->headers->get('Accept'))->all(),
        );

        return \in_array('application/json', $mediaTypes, true) && !\in_array('application/scim+json', $mediaTypes, true)
            ? 'application/json'
            : 'application/scim+json';
    }
}
