<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Resource;

use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;

/**
 * Applies the "returned" characteristic, "attributes" and "excludedAttributes" to a document in schema spelling.
 * "schemas" is kept unchanged. Keys that the resource type does not define and extension keys that become empty are
 * removed.
 *
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-7
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.2.5
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.9
 */
final readonly class DocumentProjector
{
    /**
     * @param array<string, mixed> $document
     * @param list<AttributePath>  $attributes         an empty list returns the default set
     * @param list<AttributePath>  $excludedAttributes
     * @param list<AttributePath>  $requested          the attributes the client sent in a POST, PUT or PATCH
     *
     * @return array<string, mixed>
     */
    public function project(array $document, ResourceType $type, array $attributes = [], array $excludedAttributes = [], array $requested = []): array
    {
        $projection = new Projection(
            AttributeSelection::of($attributes, $type),
            AttributeSelection::of($excludedAttributes, $type),
            AttributeSelection::of($requested, $type),
        );

        return $this->projectEach($document, fn (string $key, mixed $value): mixed => $this->projectKey($key, $value, $type, $projection));
    }

    /**
     * The document without the values that are never returned; "returned: request" values stay.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public function returnable(array $document, ResourceType $type): array
    {
        return $this->project($document, $type, requested: array_map(static fn (ResolvedAttribute $attribute): AttributePath => $attribute->path(), ResourceAttributes::all($type)));
    }

    private function projectKey(string $key, mixed $value, ResourceType $type, Projection $projection): mixed
    {
        if ('schemas' === $key) {
            return $value;
        }

        $extension = $type->extension($key)?->schema;

        if (!$extension instanceof Schema) {
            return $this->projectAttribute(new AttributePath($type->schema->id, $key), $value, $type, $projection);
        }

        return $this->projectExtension($value, $extension->id, $type, $projection);
    }

    /**
     * @return non-empty-array<mixed>|null
     */
    private function projectExtension(mixed $container, string $schemaId, ResourceType $type, Projection $projection): ?array
    {
        if (!\is_array($container)) {
            return null;
        }

        $projected = $this->projectEach($container, fn (string $name, mixed $value): mixed => $this->projectAttribute(new AttributePath($schemaId, $name), $value, $type, $projection));

        return [] === $projected ? null : $projected;
    }

    private function projectAttribute(AttributePath $path, mixed $value, ResourceType $type, Projection $projection): mixed
    {
        $attribute = $type->resolve($path);

        return $attribute instanceof ResolvedAttribute ? $projection->project($value, $attribute) : null;
    }

    /**
     * @template TKey of array-key
     *
     * @param array<TKey, mixed>             $values
     * @param \Closure(string, mixed): mixed $projectOne returns null to leave the key out
     *
     * @return array<TKey, mixed>
     */
    private function projectEach(array $values, \Closure $projectOne): array
    {
        $projected = [];

        foreach ($values as $key => $value) {
            $projected[$key] = $projectOne((string) $key, $value);
        }

        return array_filter($projected, static fn (mixed $value): bool => null !== $value);
    }
}
