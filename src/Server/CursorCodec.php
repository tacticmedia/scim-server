<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use Psr\Clock\ClockInterface;
use TacticMedia\ScimServer\Exception\ScimException;

/**
 * RFC 9865 cursors: a position, the query it belongs to and the issue time, encrypted and authenticated
 * (XSalsa20-Poly1305) and encoded as base64url without padding. A random nonce makes each cursor a new string.
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
     * @param int<0, max>|list<int|float|string|bool|null> $position an offset, or a repository position
     */
    public function encode(string $query, int|array $position): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = json_encode(['q' => $query, 't' => $this->now(), 'p' => $position], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);

        return sodium_bin2base64($nonce.sodium_crypto_secretbox($payload, $nonce, $this->key), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /**
     * @return int<0, max>|list<int|float|string|bool|null>
     *
     * @throws ScimException invalidCursor, or expiredCursor after the lifetime
     */
    public function decode(string $cursor, string $query): int|array
    {
        $payload = $this->open($cursor);
        $issuedAt = $payload['t'] ?? null;

        if (!\is_int($issuedAt) || $query !== ($payload['q'] ?? null)) {
            throw ScimException::invalidCursor();
        }

        if (null !== $this->lifetimeSeconds && $this->now() - $issuedAt > $this->lifetimeSeconds) {
            throw ScimException::expiredCursor();
        }

        return $this->position($payload['p'] ?? null);
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
     * @return int<0, max>|list<int|float|string|bool|null>
     */
    private function position(mixed $position): int|array
    {
        return match (true) {
            \is_int($position) && $position >= 0 => $position,
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
