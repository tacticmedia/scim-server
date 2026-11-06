<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Path\AttributePath;

/**
 * The query parameters of a request or the members of a SearchRequest. Names match without regard to case, and ""
 * counts as absent.
 *
 * @internal
 */
final readonly class RequestParameters
{
    /** @var array<array-key, mixed> */
    private array $values;

    /**
     * @param array<array-key, mixed> $values
     */
    public function __construct(array $values)
    {
        $this->values = array_change_key_case($values);
    }

    public function has(string $name): bool
    {
        return null !== $this->value($name);
    }

    /**
     * @throws ScimException invalidValue
     */
    public function string(string $name): ?string
    {
        $value = $this->value($name);

        return null === $value || \is_string($value) ? $value : throw $this->invalid($name, 'a string');
    }

    /**
     * @throws ScimException invalidValue
     */
    public function integer(string $name): ?int
    {
        $value = $this->value($name);

        return match (true) {
            null === $value, \is_int($value) => $value,
            \is_string($value) && 1 === preg_match('/\A\s*[+-]?\d+\s*\z/', $value) => (int) $value,
            default => throw $this->invalid($name, 'an integer'),
        };
    }

    /**
     * RFC 9865 "cursor". Unlike the other parameters, "" is a value: it asks for the first page.
     *
     * @throws ScimException invalidCursor
     */
    public function cursor(): ?string
    {
        $value = $this->values['cursor'] ?? null;

        return null === $value || \is_string($value) ? $value : throw ScimException::invalidCursor();
    }

    /**
     * A comma-separated string or a list of strings. Items that are not attribute paths are left out.
     *
     * @return list<AttributePath>
     *
     * @throws ScimException invalidValue
     */
    public function paths(string $name): array
    {
        $value = $this->value($name);
        $paths = match (true) {
            null === $value => [],
            \is_string($value) => explode(',', $value),
            \is_array($value) => $value,
            default => throw $this->invalid($name, 'a comma-separated string or a list of strings'),
        };

        return array_values(array_filter(array_map(
            static fn (mixed $path): ?AttributePath => \is_string($path) ? AttributePath::parse(trim($path)) : null,
            $paths,
        )));
    }

    private function value(string $name): mixed
    {
        $value = $this->values[strtolower($name)] ?? null;

        return '' === $value ? null : $value;
    }

    private function invalid(string $name, string $expected): ScimException
    {
        return ScimException::invalidValue(\sprintf('The parameter "%s" must be %s.', $name, $expected));
    }
}
