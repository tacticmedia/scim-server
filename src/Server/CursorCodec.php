<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use Psr\Clock\ClockInterface;
use TacticMedia\ScimServer\Exception\ScimException;

/**
 * RFC 9865 cursors: an offset, an optional repository position, the query it belongs to and the issue time, encrypted
 * and authenticated (XSalsa20-Poly1305) and encoded as base64url without padding. A random nonce makes each cursor a
 * new string.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc9865#section-5.2
 */
final readonly class CursorCodec
{
    private const int MAX_CURSOR_LENGTH = 4096;

    private string $key;

    /**
     * @param int<1, max>|null $lifetimeSeconds null: cursors do not expire
     */
    public function __construct(
        #[\SensitiveParameter] string $key,
        private ?int $lifetimeSeconds = null,
        private ?ClockInterface $clock = null,
    ) {
        $this->key = hash_hkdf('sha256', $key, \SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'tacticmedia/scim-server cursor');
    }

    /**
     * A position that makes the cursor longer than decode() accepts is left out, so the cursor holds only the offset.
     *
     * @param int<0, max>                           $offset   the number of resources before the next page
     * @param list<int|float|string|bool|null>|null $position a repository position; null: page by offset
     */
    public function encode(string $query, int $offset, ?array $position = null): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = json_encode(['q' => $query, 't' => $this->now(), 'o' => $offset, 'p' => $position], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $cursor = sodium_bin2base64($nonce.sodium_crypto_secretbox($payload, $nonce, $this->key), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        return null !== $position && \strlen($cursor) > self::MAX_CURSOR_LENGTH ? $this->encode($query, $offset) : $cursor;
    }

    /**
     * @return array{int<0, max>, list<int|float|string|bool|null>|null} the offset and the repository position
     *
     * @throws ScimException invalidCursor, or expiredCursor after the lifetime
     */
    public function decode(string $cursor, string $query): array
    {
        $payload = $this->open($cursor);
        $issuedAt = $payload['t'] ?? null;

        if (!\is_int($issuedAt) || $query !== ($payload['q'] ?? null)) {
            throw ScimException::invalidCursor();
        }

        if (null !== $this->lifetimeSeconds && $this->now() - $issuedAt > $this->lifetimeSeconds) {
            throw ScimException::expiredCursor();
        }

        return [$this->offset($payload['o'] ?? null), $this->position($payload['p'] ?? null)];
    }

    /**
     * @return array<mixed>
     */
    private function open(string $cursor): array
    {
        try {
            $sealed = \strlen($cursor) <= self::MAX_CURSOR_LENGTH ? sodium_base642bin($cursor, \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING) : '';
            $json = sodium_crypto_secretbox_open(substr($sealed, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($sealed, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key);
            $payload = false === $json ? null : json_decode($json, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\SodiumException|\JsonException) {
            $payload = null;
        }

        return \is_array($payload) ? $payload : throw ScimException::invalidCursor();
    }

    /**
     * @return int<0, max>
     */
    private function offset(mixed $offset): int
    {
        return \is_int($offset) && $offset >= 0 ? $offset : throw ScimException::invalidCursor();
    }

    /**
     * @return list<int|float|string|bool|null>|null
     */
    private function position(mixed $position): ?array
    {
        return match (true) {
            null === $position => null,
            \is_array($position) && array_is_list($position) => array_map($this->scalar(...), $position),
            default => throw ScimException::invalidCursor(),
        };
    }

    private function scalar(mixed $value): int|float|string|bool|null
    {
        return null === $value || \is_scalar($value) ? $value : throw ScimException::invalidCursor();
    }

    private function now(): int
    {
        return ($this->clock?->now() ?? new \DateTimeImmutable())->getTimestamp();
    }
}
