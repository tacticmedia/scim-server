<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Patch;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Filter\FilterResolver;
use TacticMedia\ScimServer\Filter\ValuePathFilter;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\SchemaExtension;

/**
 * @internal
 */
final readonly class PatchTargetResolver
{
    private FilterParser $parser;

    private FilterResolver $filters;

    public function __construct()
    {
        $this->parser = new FilterParser();
        $this->filters = new FilterResolver();
    }

    /**
     * A path-less "add" or "replace" gives one target per key of the value object (Microsoft Entra ID, Okta).
     *
     * @return list<array{PatchTarget, mixed}> each target with its value
     *
     * @throws ScimException noTarget, invalidPath, invalidFilter or invalidValue
     */
    public function targets(PatchOperation $operation, ResourceType $type): array
    {
        $path = $operation->path;

        if (null === $path && PatchOperationType::Remove === $operation->type) {
            throw ScimException::noTarget('A "remove" operation needs a "path".');
        }

        if (null === $path) {
            return $this->keyTargets($operation->value, $type);
        }

        // AttributePath::parse() splits a bare schema URN at its last colon, so the URN is matched first.
        $extension = $type->extension($path);

        return $extension instanceof SchemaExtension
            ? $this->extensionTargets($operation, $extension, $type)
            : [[$this->resolve($path, $type), $operation->value]];
    }

    /**
     * "remove" targets every attribute of the extension. "add" and "replace" target the keys of the value, as without
     * a path (Keycloak).
     *
     * @return list<array{PatchTarget, mixed}>
     */
    private function extensionTargets(PatchOperation $operation, SchemaExtension $extension, ResourceType $type): array
    {
        if (PatchOperationType::Remove !== $operation->type) {
            return $this->keyTargets([$extension->schema->id => $operation->value], $type);
        }

        return array_map(
            static fn (AttributeDefinition $attribute): array => [new PatchTarget(new ResolvedAttribute($extension->schema, $attribute)), null],
            $extension->schema->attributes,
        );
    }

    private function resolve(string $path, ResourceType $type): PatchTarget
    {
        $patchPath = $this->parser->parsePatchPath($path);
        $attribute = $type->resolve($patchPath->path)
            ?? throw ScimException::invalidPath(\sprintf("The attribute '%s' is not defined for the resource type %s.", $patchPath->path, $type->name));
        $valueFilter = $patchPath->valueFilter;

        if (!$valueFilter instanceof Filter) {
            return new PatchTarget($attribute);
        }

        if (!$attribute->attribute->multiValued) {
            throw ScimException::invalidPath(\sprintf("A value filter selects values of a multi-valued attribute, and '%s' is not one.", $attribute->path()->parent()));
        }

        return new PatchTarget($attribute, $this->filters->resolveValuePath(new ValuePathFilter($patchPath->path->parent(), $valueFilter), $type)->filter);
    }

    /**
     * Keys that do not resolve are ignored, as in a POST body.
     *
     * @return list<array{PatchTarget, mixed}>
     */
    private function keyTargets(mixed $value, ResourceType $type, string $urnPrefix = ''): array
    {
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw ScimException::invalidValue('The value must be an object of attributes.');
        }

        $targets = [];

        foreach ($value as $key => $keyValue) {
            $targets = [...$targets, ...$this->keyTarget((string) $key, $keyValue, $type, $urnPrefix)];
        }

        return $targets;
    }

    /**
     * @return list<array{PatchTarget, mixed}>
     */
    private function keyTarget(string $key, mixed $value, ResourceType $type, string $urnPrefix): array
    {
        $extension = '' === $urnPrefix ? $type->extension($key) : null;

        if ($extension instanceof SchemaExtension) {
            return $this->keyTargets($value, $type, $extension->schema->id.':');
        }

        $path = AttributePath::parse($urnPrefix.$key);
        $attribute = $path instanceof AttributePath ? $type->resolve($path) : null;

        return $attribute instanceof ResolvedAttribute ? [[new PatchTarget($attribute), $value]] : [];
    }
}
