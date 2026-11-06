<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;

/**
 * The URLs of resources under one base URL. A reference value with a "value" and without a "$ref" gets the URL of
 * the resource that "value" names. Its resource type is the one that "type" names when the referenceTypes of "$ref"
 * list it (Group members), else the only one in those referenceTypes (User groups, Enterprise manager).
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-2.3.7
 */
final readonly class ReferenceUrls
{
    /** @see https://www.rfc-editor.org/rfc/rfc7643#section-7 referenceTypes keywords that name no resource type */
    private const array NOT_RESOURCE_TYPES = ['external', 'uri'];

    /**
     * @param list<ResourceType> $resourceTypes the resource types that a "$ref" can name
     */
    public function __construct(
        private string $baseUrl,
        private array $resourceTypes = [],
    ) {
    }

    public function of(ResourceType $type, string $id): string
    {
        return $this->baseUrl.'/'.ltrim($type->endpoint, '/').'/'.rawurlencode($id);
    }

    /**
     * A "$ref" that the document has is kept.
     *
     * @param array<string, mixed> $document in schema spelling
     *
     * @return array<string, mixed>
     */
    public function fill(array $document, ResourceType $type): array
    {
        $document = array_replace($document, $this->filledAttributes($document, $type->schema));

        foreach ($type->schemaExtensions as $extension) {
            $container = $document[$extension->schema->id] ?? null;

            if (\is_array($container)) {
                $document[$extension->schema->id] = array_replace($container, $this->filledAttributes($container, $extension->schema));
            }
        }

        return $document;
    }

    /**
     * @param array<mixed> $container
     *
     * @return array<string, array<mixed>> the reference attributes with values, by name
     */
    private function filledAttributes(array $container, Schema $schema): array
    {
        $filled = [];

        foreach ($schema->attributes as $attribute) {
            $value = $container[$attribute->name] ?? null;

            if (\is_array($value) && $attribute->subAttribute('$ref') instanceof AttributeDefinition) {
                $filled[$attribute->name] = $this->filledAttribute($value, $attribute);
            }
        }

        return $filled;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private function filledAttribute(array $value, AttributeDefinition $attribute): array
    {
        return $attribute->multiValued
            ? array_map(fn (mixed $element): mixed => \is_array($element) ? $this->filledValue($element, $attribute) : $element, $value)
            : $this->filledValue($value, $attribute);
    }

    /**
     * @param array<mixed> $complex
     *
     * @return array<mixed>
     */
    private function filledValue(array $complex, AttributeDefinition $attribute): array
    {
        $id = $complex['value'] ?? null;

        if (null !== ($complex['$ref'] ?? null) || !\is_string($id) || '' === $id) {
            return $complex;
        }

        $target = $this->target($complex, $attribute);

        return $target instanceof ResourceType ? [...$complex, '$ref' => $this->of($target, $id)] : $complex;
    }

    /**
     * @param array<mixed> $complex
     */
    private function target(array $complex, AttributeDefinition $attribute): ?ResourceType
    {
        $referenceTypes = array_values(array_diff($attribute->subAttribute('$ref')->referenceTypes ?? [], self::NOT_RESOURCE_TYPES));
        $type = $complex['type'] ?? null;
        $names = \is_string($type) && array_any($referenceTypes, static fn (string $name): bool => 0 === strcasecmp($name, $type)) ? [$type] : $referenceTypes;

        return 1 === \count($names)
            ? array_find($this->resourceTypes, static fn (ResourceType $resourceType): bool => 0 === strcasecmp($resourceType->name, $names[0]))
            : null;
    }
}
