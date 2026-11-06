<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Patch\PatchOperation;
use TacticMedia\ScimServer\Patch\PatchRequest;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\SchemaExtension;

/**
 * The attributes that a request body or PATCH operations name. A response returns those with "returned" "request".
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-7
 */
final class RequestedAttributes
{
    /**
     * @param array<array-key, mixed> $body a resource, or the value of a PATCH operation without a path
     *
     * @return list<AttributePath>
     */
    public static function inBody(array $body, ResourceType $type): array
    {
        return array_merge(...array_map(
            static fn (int|string $key): array => self::pathsOf((string) $key, $body[$key], $type),
            array_keys($body),
        ));
    }

    /**
     * Call it after {@see \TacticMedia\ScimServer\Patch\PatchApplier::apply()} accepted the paths.
     *
     * @return list<AttributePath>
     */
    public static function inPatch(PatchRequest $patch, ResourceType $type): array
    {
        return array_merge(...array_map(static fn (PatchOperation $operation): array => self::inOperation($operation, $type), $patch->operations));
    }

    /**
     * @return list<AttributePath>
     */
    private static function inOperation(PatchOperation $operation, ResourceType $type): array
    {
        if (null === $operation->path) {
            return \is_array($operation->value) ? self::inBody($operation->value, $type) : [];
        }

        if ($type->extension($operation->path) instanceof SchemaExtension) {
            return self::inBody([$operation->path => $operation->value], $type);
        }

        return [new FilterParser()->parsePatchPath($operation->path)->path];
    }

    /**
     * @return list<AttributePath>
     */
    private static function pathsOf(string $key, mixed $value, ResourceType $type): array
    {
        if ($type->extension($key) instanceof SchemaExtension && \is_array($value)) {
            return array_values(array_filter(array_map(
                static fn (int|string $name): ?AttributePath => AttributePath::parse($key.':'.$name),
                array_keys($value),
            )));
        }

        $path = AttributePath::parse($key);

        return $path instanceof AttributePath ? [$path] : [];
    }
}
