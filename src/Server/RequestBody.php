<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use TacticMedia\ScimServer\Exception\ScimException;

/**
 * Accepts application/scim+json, application/json (quirk 34) and a body without Content-Type, each with any
 * parameters.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.8
 */
final class RequestBody
{
    private const array MEDIA_TYPES = ['', 'application/scim+json', 'application/json'];

    /**
     * @return array<string, mixed>
     *
     * @throws ScimException 415 for another media type, invalidSyntax when the body is not a JSON object
     */
    public static function object(Request $request): array
    {
        $mediaType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));

        if (!\in_array($mediaType, self::MEDIA_TYPES, true)) {
            throw new ScimException(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, \sprintf('The media type "%s" is not supported. Send application/scim+json.', $mediaType));
        }

        $content = $request->getContent();

        try {
            $body = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw ScimException::invalidSyntax(\sprintf('The request body is not valid JSON: %s.', $jsonException->getMessage()), $jsonException);
        }

        // json_decode() gives [] for both {} and [].
        if (!\is_array($body) || !str_starts_with(ltrim($content), '{')) {
            throw ScimException::invalidSyntax('The request body must be a JSON object.');
        }

        // PHP turns numeric keys into int; no attribute name is numeric.
        return array_filter($body, \is_string(...), \ARRAY_FILTER_USE_KEY);
    }
}
