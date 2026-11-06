<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Resource;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\SchemaExtension;

/**
 * Turns a request body into a document in schema spelling: core attributes at the top level, extension attributes
 * under the extension schema URN, "schemas" computed from the values and the required extensions. Unknown attributes
 * and schema URNs are ignored, readOnly attributes are dropped, and null, [] and {} mean unassigned.
 *
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-2
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.3
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.5.1
 */
final readonly class DocumentNormalizer
{
    private InputReader $reader;

    private ValueNormalizer $values;

    private ReplacementMerger $merger;

    private RequiredAttributeValidator $required;

    public function __construct()
    {
        $this->reader = new InputReader();
        $this->values = new ValueNormalizer();
        $this->merger = new ReplacementMerger($this->values);
        $this->required = new RequiredAttributeValidator();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     *
     * @throws ScimException invalidSyntax or invalidValue
     */
    public function forCreate(array $input, ResourceType $type): array
    {
        $values = $this->normalizeAll($this->reader->read($input, $type), $type);
        $this->required->validate($values, $type);

        return $this->assemble($values, $type);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing the stored document; its readOnly and immutable values win
     *
     * @return array<string, mixed>
     *
     * @throws ScimException invalidSyntax, invalidValue or mutability
     */
    public function forReplace(array $input, array $existing, ResourceType $type): array
    {
        $values = $this->merger->merge($this->normalizeAll($this->reader->read($input, $type), $type), $existing, $type);
        $this->required->validate($values, $type);

        return $this->assemble($values, $type);
    }

    /**
     * Normalizes a PATCH value for an attribute or a sub-attribute. A multi-valued target accepts one element or a
     * list. Mutability and required sub-attributes are the caller's concern.
     *
     * @return mixed null when the value is unassigned, a non-empty list for a multi-valued target
     *
     * @throws ScimException invalidSyntax or invalidValue
     */
    public function normalizeValue(mixed $value, ResolvedAttribute $attribute): mixed
    {
        $definition = $attribute->definition();

        if ($definition->multiValued && (!\is_array($value) || !array_is_list($value))) {
            $value = [$value];
        }

        return $this->values->normalize($value, $definition, (string) $attribute->path());
    }

    /**
     * @param array<string, array<string, mixed>> $raw
     *
     * @return array<string, array<string, mixed>>
     */
    private function normalizeAll(array $raw, ResourceType $type): array
    {
        $values = [];

        foreach (ResourceAttributes::all($type) as $attribute) {
            $schemaId = $attribute->schema->id;
            $name = $attribute->attribute->name;
            $value = Mutability::ReadOnly === $attribute->attribute->mutability
                ? null
                : $this->values->normalize($raw[$schemaId][$name] ?? null, $attribute->attribute, ResourceAttributes::label($attribute, $type));

            if (null !== $value) {
                $values[$schemaId][$name] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, array<string, mixed>> $values in {@see ResourceAttributes::all()} order
     *
     * @return array<string, mixed>
     */
    private function assemble(array $values, ResourceType $type): array
    {
        $listed = array_filter($type->schemaExtensions, static fn (SchemaExtension $extension): bool => $extension->required || isset($values[$extension->schema->id]));
        $schemas = [$type->schema->id, ...array_map(static fn (SchemaExtension $extension): string => $extension->schema->id, $listed)];

        return ['schemas' => $schemas] + ($values[$type->schema->id] ?? []) + array_diff_key($values, [$type->schema->id => true]);
    }
}
