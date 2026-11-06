<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Repository;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\FilterResolver;
use TacticMedia\ScimServer\Filter\ValueComparator;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Query\SortOrder;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * $sortBy resolves through {@see FilterResolver::resolveSortBy()}. A multi-valued attribute sorts by its primary
 * value, else by its first value. A document without a value, or with a value that does not fit the attribute type,
 * sorts last when ascending and first when descending. Equal values keep their input order.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.2.3
 */
final readonly class DocumentSorter
{
    public function __construct(
        private ValueComparator $comparator = new ValueComparator(),
        private FilterResolver $resolver = new FilterResolver(),
    ) {
    }

    /**
     * The sort value of each document. Sorting by keyComparison() and then by input order is the order of
     * sortAcrossTypes() for documents of one type.
     *
     * @param list<array<string, mixed>> $documents in schema spelling
     *
     * @return list<int|float|string|bool|null> null for a document without a value; all null when the resource type
     *                                          does not define $sortBy
     *
     * @throws ScimException invalidValue for an attribute that is never returned
     */
    public function keys(array $documents, AttributePath $sortBy, ResourceType $type): array
    {
        $attribute = [] === $documents ? null : $this->resolver->resolveSortBy($sortBy, $type);
        $definition = $attribute?->definition();

        return array_map(fn (array $document): int|float|string|bool|null => $definition instanceof AttributeDefinition ? $this->sortKey($document, $attribute, $type, $definition) : null, $documents);
    }

    /**
     * Compares two values of keys() in $order; 0 for equal values.
     *
     * @return \Closure(int|float|string|bool|null, int|float|string|bool|null): int
     *
     * @throws ScimException invalidValue for an attribute that is never returned
     */
    public function keyComparison(AttributePath $sortBy, SortOrder $order, ResourceType $type): \Closure
    {
        $definition = $this->resolver->resolveSortBy($sortBy, $type)?->definition();
        $direction = SortOrder::Descending === $order ? -1 : 1;

        return fn (int|float|string|bool|null $left, int|float|string|bool|null $right): int => $definition instanceof AttributeDefinition ? $direction * $this->compare($left, $right, $definition) : 0;
    }

    /**
     * Values compare with the definition of the first resource type that defines $sortBy. A document of a type that
     * does not define $sortBy has no value.
     *
     * @param list<array{ResourceType, array<string, mixed>}> $entries documents in schema spelling, each with its type
     *
     * @return list<array{ResourceType, array<string, mixed>}> unchanged when no resource type defines $sortBy
     *
     * @throws ScimException invalidValue for an attribute that is never returned
     */
    public function sortAcrossTypes(array $entries, AttributePath $sortBy, SortOrder $order): array
    {
        $attributes = $this->attributes($entries, $sortBy);
        $definition = array_find($attributes, static fn (?ResolvedAttribute $attribute): bool => $attribute instanceof ResolvedAttribute)?->definition();

        if (!$definition instanceof AttributeDefinition) {
            return $entries;
        }

        $direction = SortOrder::Descending === $order ? -1 : 1;
        $rows = array_map(fn (array $entry): array => [$this->sortKey($entry[1], $attributes[spl_object_id($entry[0])], $entry[0], $definition), $entry], $entries);
        usort($rows, fn (array $left, array $right): int => $direction * $this->compare($left[0], $right[0], $definition));

        return array_column($rows, 1);
    }

    /**
     * @param list<array{ResourceType, array<string, mixed>}> $entries
     *
     * @return array<int, ResolvedAttribute|null> by the object id of the resource type
     */
    private function attributes(array $entries, AttributePath $sortBy): array
    {
        $attributes = [];

        foreach ($entries as [$type]) {
            if (!\array_key_exists(spl_object_id($type), $attributes)) {
                $attributes[spl_object_id($type)] = $this->resolver->resolveSortBy($sortBy, $type);
            }
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return int|float|string|bool|null null when the document has no value that fits $definition
     */
    private function sortKey(array $document, ?ResolvedAttribute $attribute, ResourceType $type, AttributeDefinition $definition): int|float|string|bool|null
    {
        if (!$attribute instanceof ResolvedAttribute) {
            return null;
        }

        $container = $attribute->schema->id === $type->schema->id ? $document : ($document[$attribute->schema->id] ?? null);
        $value = \is_array($container) ? ($container[$attribute->attribute->name] ?? null) : null;
        $value = $attribute->attribute->multiValued ? $this->primaryOrFirst($value) : $value;
        $value = $this->subAttributeValue($value, $attribute->subAttribute);

        return \is_scalar($value) && $this->fits($value, $definition) ? $value : null;
    }

    private function primaryOrFirst(mixed $values): mixed
    {
        if (!\is_array($values)) {
            return null;
        }

        return array_find($values, static fn (mixed $value): bool => \is_array($value) && true === ($value['primary'] ?? null)) ?? array_first($values);
    }

    private function subAttributeValue(mixed $value, ?AttributeDefinition $subAttribute): mixed
    {
        if (!$subAttribute instanceof AttributeDefinition) {
            return $value;
        }

        return \is_array($value) ? ($value[$subAttribute->name] ?? null) : null;
    }

    private function fits(mixed $value, AttributeDefinition $definition): bool
    {
        try {
            $this->comparator->compare($value, $value, $definition);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private function compare(mixed $left, mixed $right, AttributeDefinition $definition): int
    {
        if (null === $left || null === $right) {
            return (null === $left) <=> (null === $right);
        }

        return $this->comparator->compare($left, $right, $definition);
    }
}
