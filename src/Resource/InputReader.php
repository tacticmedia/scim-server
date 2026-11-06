<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Resource;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;

/**
 * Groups the attributes of a request body by schema in schema spelling. Unknown keys and "schemas" are left out.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-3.3
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.10
 */
final class InputReader
{
    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, array<string, mixed>> raw values keyed by schema id, then attribute name
     *
     * @throws ScimException invalidSyntax for an attribute given twice
     */
    public function read(array $input, ResourceType $type): array
    {
        $values = [];

        foreach ($input as $key => $value) {
            foreach ($this->entries((string) $key, $value, $type) as [$attribute, $attributeValue]) {
                $schemaId = $attribute->schema->id;
                $name = $attribute->attribute->name;

                if (\array_key_exists($name, $values[$schemaId] ?? [])) {
                    throw ScimException::invalidSyntax(\sprintf('Attribute "%s" appears more than once.', ResourceAttributes::label($attribute, $type)));
                }

                $values[$schemaId][$name] = $attributeValue;
            }
        }

        return $values;
    }

    /**
     * @return list<array{ResolvedAttribute, mixed}>
     */
    private function entries(string $key, mixed $value, ResourceType $type): array
    {
        $extension = $type->extension($key)?->schema;

        if ($extension instanceof Schema) {
            return $this->containerEntries($extension, $value);
        }

        $path = AttributePath::parse($key);
        $attribute = $path instanceof AttributePath && null === $path->subAttribute ? $type->resolve($path) : null;

        return $attribute instanceof ResolvedAttribute ? [[$attribute, $value]] : [];
    }

    /**
     * @return list<array{ResolvedAttribute, mixed}>
     */
    private function containerEntries(Schema $extension, mixed $value): array
    {
        $container = $value ?? [];

        if (!\is_array($container) || ([] !== $container && array_is_list($container))) {
            throw ScimException::invalidValue(\sprintf('Attribute "%s" must be a complex object.', $extension->id));
        }

        $entries = [];

        foreach ($container as $name => $attributeValue) {
            $attribute = $extension->attribute((string) $name);

            if ($attribute instanceof AttributeDefinition) {
                $entries[] = [new ResolvedAttribute($extension, $attribute), $attributeValue];
            }
        }

        return $entries;
    }
}
