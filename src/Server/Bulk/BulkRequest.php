<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server\Bulk;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\MessageUrn;

/**
 * The BulkRequest message. A malformed message or operation fails the whole request; what the single request of an
 * operation checks ("data", the path) fails only that operation.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.7
 */
final readonly class BulkRequest
{
    private const array METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param list<BulkOperation> $operations
     * @param int<1, max>|null    $failOnErrors
     */
    private function __construct(
        public array $operations,
        public ?int $failOnErrors,
    ) {
    }

    /**
     * Member names match in any case.
     *
     * @param array<mixed> $body
     * @param int<1, max>  $maxOperations
     *
     * @throws ScimException invalidSyntax, or 413 for more than $maxOperations operations (RFC 7644 section 3.7.4)
     */
    public static function fromArray(array $body, int $maxOperations): self
    {
        self::assertBulkRequestSchema(self::member($body, 'schemas'));
        $operations = self::member($body, 'Operations');

        if (!\is_array($operations) || [] === $operations || !array_is_list($operations)) {
            throw ScimException::invalidSyntax('"Operations" must be a non-empty list of operations.');
        }

        if (\count($operations) > $maxOperations) {
            throw ScimException::payloadTooLarge(\sprintf('The number of operations exceeds the maxOperations (%d).', $maxOperations));
        }

        $parsed = array_map(self::operation(...), $operations, array_keys($operations));
        self::assertUniqueBulkIds($parsed);

        return new self($parsed, self::failOnErrors(self::member($body, 'failOnErrors')));
    }

    private static function assertBulkRequestSchema(mixed $schemas): void
    {
        $isBulkRequest = static fn (mixed $schema): bool => \is_string($schema) && 0 === strcasecmp($schema, MessageUrn::BulkRequest->value);

        if (!\is_array($schemas) || !array_any($schemas, $isBulkRequest)) {
            throw ScimException::invalidSyntax(\sprintf('"schemas" must contain "%s".', MessageUrn::BulkRequest->value));
        }
    }

    /**
     * @return int<1, max>|null
     */
    private static function failOnErrors(mixed $failOnErrors): ?int
    {
        return null === $failOnErrors || (\is_int($failOnErrors) && $failOnErrors > 0)
            ? $failOnErrors
            : throw ScimException::invalidSyntax('"failOnErrors" must be a positive integer.');
    }

    private static function operation(mixed $operation, int $index): BulkOperation
    {
        if (!\is_array($operation) || array_is_list($operation)) {
            throw ScimException::invalidSyntax(\sprintf('Operations[%d] must be an object.', $index));
        }

        $method = self::method(self::member($operation, 'method'), $index);

        return new BulkOperation(
            $index,
            $method,
            self::nonEmptyString(self::member($operation, 'path'), \sprintf('Operations[%d]: "path" must be a non-empty string.', $index)),
            'POST' === $method ? self::nonEmptyString(self::member($operation, 'bulkId'), \sprintf('Operations[%d]: a POST operation needs a "bulkId" that is a non-empty string.', $index)) : null,
            self::version(self::member($operation, 'version'), $index),
            self::member($operation, 'data'),
        );
    }

    private static function method(mixed $method, int $index): string
    {
        $method = \is_string($method) ? strtoupper($method) : null;

        return \in_array($method, self::METHODS, true)
            ? $method
            : throw ScimException::invalidSyntax(\sprintf('Operations[%d]: "method" must be "POST", "PUT", "PATCH" or "DELETE".', $index));
    }

    private static function nonEmptyString(mixed $value, string $detail): string
    {
        return \is_string($value) && '' !== $value ? $value : throw ScimException::invalidSyntax($detail);
    }

    private static function version(mixed $version, int $index): ?string
    {
        return null === $version || \is_string($version)
            ? $version
            : throw ScimException::invalidSyntax(\sprintf('Operations[%d]: "version" must be a string.', $index));
    }

    /**
     * @param list<BulkOperation> $operations
     */
    private static function assertUniqueBulkIds(array $operations): void
    {
        $bulkIds = array_filter(array_map(static fn (BulkOperation $operation): ?string => $operation->bulkId, $operations), \is_string(...));
        $duplicate = array_find_key(array_count_values($bulkIds), static fn (int $count): bool => $count > 1);

        if (null !== $duplicate) {
            throw ScimException::invalidSyntax(\sprintf('The bulkId "%s" is not unique.', $duplicate));
        }
    }

    /**
     * The first member whose name matches in any case.
     *
     * @param array<mixed> $object
     */
    private static function member(array $object, string $name): mixed
    {
        $key = array_find_key($object, static fn (mixed $value, int|string $key): bool => 0 === strcasecmp((string) $key, $name));

        return null === $key ? null : $object[$key];
    }
}
