<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Patch;

use TacticMedia\ScimServer\Filter\FilterParser;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Returned;
use TacticMedia\ScimServer\Schema\SchemaExtension;

/**
 * The PATCH request of an event, without the values that are never returned, such as "password". An operation whose
 * path names such an attribute keeps its path and has the value null. A value object loses the keys of such
 * attributes, and a complex value their sub-attributes. Keys that name no attribute stay.
 *
 * @internal
 */
final readonly class PatchRedactor
{
    public function __construct(
        private FilterParser $parser = new FilterParser(),
    ) {
    }

    /**
     * Call it after the operations applied, so that each path is valid.
     */
    public function redact(PatchRequest $patch, ResourceType $type): PatchRequest
    {
        return new PatchRequest(array_map(
            fn (PatchOperation $operation): PatchOperation => new PatchOperation($operation->type, $operation->path, $this->value($operation, $type)),
            $patch->operations,
        ));
    }

    private function value(PatchOperation $operation, ResourceType $type): mixed
    {
        $path = $operation->path;
        $extension = null === $path ? null : $type->extension($path);

        return match (true) {
            null === $path => $this->members($operation->value, $type, ''),
            $extension instanceof SchemaExtension => $this->members($operation->value, $type, $extension->schema->id.':'),
            default => $this->returned($operation->value, $type->resolve($this->parser->parsePatchPath($path)->path)),
        };
    }

    /**
     * @param string $urnPrefix "" for the top level, else the extension URN and ":"
     */
    private function members(mixed $value, ResourceType $type, string $urnPrefix): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $members = [];

        foreach ($value as $key => $member) {
            $members = [...$members, ...$this->member((string) $key, $member, $type, $urnPrefix)];
        }

        return $members;
    }

    /**
     * @return array<string, mixed> [] for an attribute that is never returned
     */
    private function member(string $key, mixed $value, ResourceType $type, string $urnPrefix): array
    {
        $extension = '' === $urnPrefix ? $type->extension($key) : null;

        if ($extension instanceof SchemaExtension) {
            return [$key => $this->members($value, $type, $extension->schema->id.':')];
        }

        $path = AttributePath::parse($urnPrefix.$key);
        $attribute = $path instanceof AttributePath ? $type->resolve($path) : null;

        return $this->isNever($attribute) ? [] : [$key => $this->complex($value, $attribute)];
    }

    private function returned(mixed $value, ?ResolvedAttribute $attribute): mixed
    {
        return $this->isNever($attribute) ? null : $this->complex($value, $attribute);
    }

    /**
     * A complex value, or a list of them, without the sub-attributes that are never returned.
     */
    private function complex(mixed $value, ?ResolvedAttribute $attribute): mixed
    {
        if (!\is_array($value) || !$attribute instanceof ResolvedAttribute || $attribute->subAttribute instanceof AttributeDefinition) {
            return $value;
        }

        $definition = $attribute->attribute;

        return array_is_list($value)
            ? array_map(fn (mixed $element): mixed => $this->subAttributes($element, $definition), $value)
            : $this->subAttributes($value, $definition);
    }

    private function subAttributes(mixed $element, AttributeDefinition $definition): mixed
    {
        return \is_array($element)
            ? array_filter($element, static fn (int|string $name): bool => Returned::Never !== $definition->subAttribute((string) $name)?->returned, \ARRAY_FILTER_USE_KEY)
            : $element;
    }

    private function isNever(?ResolvedAttribute $attribute): bool
    {
        return Returned::Never === $attribute?->attribute->returned || Returned::Never === $attribute?->definition()->returned;
    }
}
