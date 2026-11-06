<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Resource;

use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\CoreSchemas;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * @internal
 */
final class ResourceAttributes
{
    /**
     * The common attributes, the core attributes, then the attributes of each extension in resource type order. A
     * common attribute replaces a core attribute with the same name, as in {@see ResourceType::resolve()}.
     *
     * @see https://www.rfc-editor.org/rfc/rfc7643#section-3.1
     *
     * @return list<ResolvedAttribute>
     */
    public static function all(ResourceType $type): array
    {
        $common = CoreSchemas::commonAttributes();
        $isCommon = static fn (AttributeDefinition $attribute): bool => array_any($common, static fn (AttributeDefinition $commonAttribute): bool => 0 === strcasecmp($commonAttribute->name, $attribute->name));
        $core = array_filter($type->schema->attributes, static fn (AttributeDefinition $attribute): bool => !$isCommon($attribute));
        $attributes = array_map(static fn (AttributeDefinition $attribute): ResolvedAttribute => new ResolvedAttribute($type->schema, $attribute), [...$common, ...$core]);

        foreach ($type->schemaExtensions as $extension) {
            foreach ($extension->schema->attributes as $attribute) {
                $attributes[] = new ResolvedAttribute($extension->schema, $attribute);
            }
        }

        return $attributes;
    }

    /**
     * The path as a document spells it: unqualified for a core attribute, URN-qualified for an extension attribute.
     */
    public static function label(ResolvedAttribute $attribute, ResourceType $type): string
    {
        $path = $attribute->path();

        return $attribute->schema->id === $type->schema->id
            ? (string) new AttributePath(null, $path->attribute, $path->subAttribute)
            : (string) $path;
    }
}
