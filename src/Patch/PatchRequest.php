<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Patch;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\MessageUrn;

/** @see https://www.rfc-editor.org/rfc/rfc7644#section-3.5.2 */
final readonly class PatchRequest
{
    /**
     * @param list<PatchOperation> $operations
     */
    public function __construct(
        public array $operations,
    ) {
    }

    /**
     * Member names match in any case.
     *
     * @param array<mixed> $body
     *
     * @throws ScimException invalidSyntax; invalidValue for an "add" or "replace" without "value"; noTarget for a
     *                       "remove" without "path" (RFC 7644 section 3.5.2.2)
     */
    public static function fromArray(array $body): self
    {
        self::assertPatchOpSchema(self::member($body, 'schemas'));
        $operations = self::member($body, 'Operations');

        if (!\is_array($operations) || [] === $operations || !array_is_list($operations)) {
            throw ScimException::invalidSyntax('"Operations" must be a non-empty list of operations.');
        }

        return new self(array_map(self::operation(...), $operations, array_keys($operations)));
    }

    private static function assertPatchOpSchema(mixed $schemas): void
    {
        $isPatchOp = static fn (mixed $schema): bool => \is_string($schema) && 0 === strcasecmp($schema, MessageUrn::PatchOp->value);

        if (!\is_array($schemas) || !array_any($schemas, $isPatchOp)) {
            throw ScimException::invalidSyntax(\sprintf('"schemas" must contain "%s".', MessageUrn::PatchOp->value));
        }
    }

    private static function operation(mixed $operation, int $index): PatchOperation
    {
        if (!\is_array($operation) || array_is_list($operation)) {
            throw ScimException::invalidSyntax(\sprintf('Operations[%d] must be an object.', $index));
        }

        $type = self::type(self::member($operation, 'op'), $index);
        $path = self::path(self::member($operation, 'path'), $index);
        $valueKey = self::key($operation, 'value');
        self::assertComplete($type, $path, null !== $valueKey, $index);

        return new PatchOperation($type, $path, null === $valueKey ? null : $operation[$valueKey]);
    }

    private static function type(mixed $name, int $index): PatchOperationType
    {
        return (\is_string($name) ? PatchOperationType::tryFromName($name) : null)
            ?? throw ScimException::invalidSyntax(\sprintf('Operations[%d]: "op" must be "add", "remove" or "replace".', $index));
    }

    private static function path(mixed $path, int $index): ?string
    {
        return null === $path || \is_string($path)
            ? $path
            : throw ScimException::invalidSyntax(\sprintf('Operations[%d]: "path" must be a string.', $index));
    }

    private static function assertComplete(PatchOperationType $type, ?string $path, bool $hasValue, int $index): void
    {
        if (PatchOperationType::Remove === $type && null === $path) {
            throw ScimException::noTarget(\sprintf('Operations[%d]: "remove" needs a "path".', $index));
        }

        if (PatchOperationType::Remove !== $type && !$hasValue) {
            throw ScimException::invalidValue(\sprintf('Operations[%d]: "%s" needs a "value".', $index, $type->value));
        }
    }

    /**
     * @param array<mixed> $object
     */
    private static function member(array $object, string $name): mixed
    {
        $key = self::key($object, $name);

        return null === $key ? null : $object[$key];
    }

    /**
     * @param array<mixed> $object
     */
    private static function key(array $object, string $name): int|string|null
    {
        return array_find_key($object, static fn (mixed $value, int|string $key): bool => 0 === strcasecmp((string) $key, $name));
    }
}
