<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

/**
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.14
 */
final class ETag
{
    /**
     * meta.version of the document, or a weak ETag computed from the document when the repository sets none.
     *
     * @param array<string, mixed> $document
     */
    public static function of(array $document): string
    {
        return self::version($document) ?? \sprintf('W/"%s"', hash('xxh128', serialize($document)));
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return non-empty-string|null
     */
    public static function version(array $document): ?string
    {
        $meta = $document['meta'] ?? null;
        $version = \is_array($meta) ? $meta['version'] ?? null : null;

        return \is_string($version) && '' !== $version ? $version : null;
    }

    /**
     * Weak comparison for If-Match too: SCIM sends weak ETags there (RFC 7644 section 3.14), which a strong
     * comparison never matches. "*" matches any ETag.
     */
    public static function matches(string $header, string $etag): bool
    {
        $opaqueTag = self::opaqueTag($etag);

        return array_any(self::candidates($header), static fn (string $candidate): bool => '*' === $candidate || self::opaqueTag($candidate) === $opaqueTag);
    }

    public static function isAny(string $header): bool
    {
        return \in_array('*', self::candidates($header), true);
    }

    /**
     * @return list<string>
     */
    private static function candidates(string $header): array
    {
        preg_match_all('/\*|(?:W\/)?"[^"]*"|[^\s,]+/', $header, $candidates);

        return $candidates[0];
    }

    private static function opaqueTag(string $etag): string
    {
        return 1 === preg_match('/\A(?:W\/)?"(.*)"\z/s', $etag, $matches) ? $matches[1] : $etag;
    }
}
