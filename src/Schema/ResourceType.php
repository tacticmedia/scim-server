<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Schema;

use TacticMedia\ScimServer\Path\AttributePath;

/** @see https://www.rfc-editor.org/rfc/rfc7643#section-6 */
final readonly class ResourceType
{
    /**
     * @param string                $endpoint         relative to the base URL, for example "/Users"
     * @param list<SchemaExtension> $schemaExtensions
     */
    public function __construct(
        public string $name,
        public string $endpoint,
        public Schema $schema,
        public array $schemaExtensions = [],
        public string $description = '',
    ) {
    }

    public function extension(string $schemaId): ?SchemaExtension
    {
        return array_find($this->schemaExtensions, static fn (SchemaExtension $extension): bool => 0 === strcasecmp($extension->schema->id, $schemaId));
    }

    /**
     * @return list<Schema>
     */
    public function schemas(): array
    {
        return [$this->schema, ...array_map(static fn (SchemaExtension $extension): Schema => $extension->schema, $this->schemaExtensions)];
    }

    /**
     * Resolves a path against the core schema with the common attributes, then against the extensions. An
     * unqualified name resolves to an extension attribute only when exactly one extension defines it.
     */
    public function resolve(AttributePath $path): ?ResolvedAttribute
    {
        if (null !== $path->schemaUrn) {
            $schema = array_find($this->schemas(), static fn (Schema $schema): bool => 0 === strcasecmp($schema->id, $path->schemaUrn));

            return null === $schema ? null : $this->resolveIn($schema, $path);
        }

        $resolved = $this->resolveIn($this->schema, $path);

        if ($resolved instanceof ResolvedAttribute) {
            return $resolved;
        }

        $candidates = array_values(array_filter(array_map(
            fn (SchemaExtension $extension): ?ResolvedAttribute => $this->resolveIn($extension->schema, $path),
            $this->schemaExtensions,
        )));

        return 1 === \count($candidates) ? $candidates[0] : null;
    }

    /**
     * Resolves a path of the "attributes" or "excludedAttributes" parameter. A whole extension schema URN gives every
     * attribute of that extension.
     *
     * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.9
     *
     * @return list<ResolvedAttribute>
     */
    public function resolveSelection(AttributePath $path): array
    {
        $extension = $this->extension((string) $path);

        if ($extension instanceof SchemaExtension) {
            return array_map(static fn (AttributeDefinition $attribute): ResolvedAttribute => new ResolvedAttribute($extension->schema, $attribute), $extension->schema->attributes);
        }

        $resolved = $this->resolve($path);

        return $resolved instanceof ResolvedAttribute ? [$resolved] : [];
    }

    /**
     * @return array{id: string, name: string, endpoint: string, description: string, schema: string, schemaExtensions: list<array{schema: string, required: bool}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->name,
            'name' => $this->name,
            'endpoint' => $this->endpoint,
            'description' => $this->description,
            'schema' => $this->schema->id,
            'schemaExtensions' => array_map(
                static fn (SchemaExtension $extension): array => ['schema' => $extension->schema->id, 'required' => $extension->required],
                $this->schemaExtensions,
            ),
        ];
    }

    private function resolveIn(Schema $schema, AttributePath $path): ?ResolvedAttribute
    {
        $attribute = $this->attributeIn($schema, $path->attribute);

        if (!$attribute instanceof AttributeDefinition) {
            return null;
        }

        if (null === $path->subAttribute) {
            return new ResolvedAttribute($schema, $attribute);
        }

        $subAttribute = $attribute->subAttribute($path->subAttribute);

        return $subAttribute instanceof AttributeDefinition ? new ResolvedAttribute($schema, $attribute, $subAttribute) : null;
    }

    private function attributeIn(Schema $schema, string $name): ?AttributeDefinition
    {
        $hasName = static fn (AttributeDefinition $attribute): bool => 0 === strcasecmp($attribute->name, $name);
        $common = $schema === $this->schema ? array_find(CoreSchemas::commonAttributes(), $hasName) : null;

        return $common ?? $schema->attribute($name);
    }
}
