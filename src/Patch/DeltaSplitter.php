<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Patch;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Repository\DeltaRepository;
use TacticMedia\ScimServer\Repository\ValuesStep;
use TacticMedia\ScimServer\Resource\DocumentNormalizer;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\SchemaExtension;

/**
 * Splits a PATCH request into the operations on other attributes and the steps on the delta attributes of a
 * {@see DeltaRepository}. A step has the effect that {@see PatchApplier} gives the operation: "add", "replace" and
 * "remove" of the whole attribute, and "remove" of attr[value eq "<string>"].
 *
 * @internal
 */
final readonly class DeltaSplitter
{
    private PatchTargetResolver $targets;

    private DocumentNormalizer $normalizer;

    public function __construct()
    {
        $this->targets = new PatchTargetResolver();
        $this->normalizer = new DocumentNormalizer();
    }

    /**
     * @param list<AttributePath> $deltaAttributes
     *
     * @return DeltaSplit|null null when there is no delta attribute, an operation on one is not a step, or an
     *                         operation fails; {@see PatchApplier} then applies the request and reports the error
     *
     * @throws \LogicException for a delta attribute that cannot take steps
     */
    public function split(PatchRequest $request, ResourceType $type, array $deltaAttributes): ?DeltaSplit
    {
        if ([] === $deltaAttributes) {
            return null;
        }

        $attributes = array_map(fn (AttributePath $path): AttributePath => $this->deltaAttribute($path, $type), $deltaAttributes);

        try {
            return $this->splitOperations($request, $type, $attributes);
        } catch (ScimException) {
            return null;
        }
    }

    /**
     * @param list<AttributePath> $attributes
     */
    private function splitOperations(PatchRequest $request, ResourceType $type, array $attributes): ?DeltaSplit
    {
        $operations = [];
        $steps = [];

        foreach ($request->operations as $index => $operation) {
            $targets = $this->targets->targets($operation, $type);

            if (!array_any($targets, static fn (array $target): bool => array_any($attributes, $target[0]->whole()->path()->equals(...)))) {
                $operations[$index] = $operation;

                continue;
            }

            $operationSteps = $this->steps($operation, $targets, $type);

            if (null === $operationSteps) {
                return null;
            }

            $steps = [...$steps, ...$operationSteps];
        }

        return new DeltaSplit($operations, $steps, $attributes);
    }

    /**
     * A path-less operation and a path of an extension URN can name several attributes.
     *
     * @param list<array{PatchTarget, mixed}> $targets
     *
     * @return list<ValuesStep>|null null when the operation is not a step
     */
    private function steps(PatchOperation $operation, array $targets, ResourceType $type): ?array
    {
        if (null === $operation->path || $type->extension($operation->path) instanceof SchemaExtension) {
            return null;
        }

        [$target, $value] = $targets[0];

        return $target->isWholeList() ? $this->wholeListSteps($operation->type, $target, $value) : $this->filterSteps($operation->type, $target);
    }

    /**
     * As {@see ValueEditor}: "remove" without a value and a null or "" value of "replace" unassign, "add" of null or ""
     * adds nothing, "remove" with a value list removes the listed values.
     *
     * @return list<ValuesStep>|null
     */
    private function wholeListSteps(PatchOperationType $type, PatchTarget $target, mixed $value): ?array
    {
        $path = $target->whole()->path();

        if (PatchOperationType::Remove === $type && null === $value) {
            return [new ValuesStep($path, PatchOperationType::Replace, [])];
        }

        $values = $this->identified($this->normalizer->normalizeValue($value, $target->attribute));

        if (null === $values) {
            return null;
        }

        return [] === $values && PatchOperationType::Replace !== $type ? [] : [new ValuesStep($path, $type, $values)];
    }

    /**
     * @return list<array<string, mixed>>|null null when a value has no string "value"
     */
    private function identified(mixed $values): ?array
    {
        $identified = [];

        foreach (\is_array($values) ? $values : [] as $value) {
            if (!\is_array($value) || !\is_string($value['value'] ?? null)) {
                return null;
            }

            $identified[] = array_filter($value, \is_string(...), \ARRAY_FILTER_USE_KEY);
        }

        return $identified;
    }

    /**
     * @return list<ValuesStep>|null
     */
    private function filterSteps(PatchOperationType $type, PatchTarget $target): ?array
    {
        $path = $target->whole()->path();
        $filter = $target->valueFilter;

        if (PatchOperationType::Remove === $type
            && !$target->attribute->subAttribute instanceof AttributeDefinition
            && $filter instanceof Comparison
            && ComparisonOperator::Equal === $filter->operator
            && \is_string($filter->value)
            && $filter->path->equals($path->withSubAttribute('value'))) {
            return [new ValuesStep($path, $type, [['value' => $filter->value]])];
        }

        return null;
    }

    private function deltaAttribute(AttributePath $path, ResourceType $type): AttributePath
    {
        $attribute = $type->resolve($path);

        return $attribute instanceof ResolvedAttribute && !$attribute->subAttribute instanceof AttributeDefinition && $this->takesSteps($attribute->attribute)
            ? $attribute->path()
            : throw new \LogicException(\sprintf('The DeltaRepository of the resource type %s names "%s", which is not a multi-valued, complex, readWrite and optional attribute with a "value" and without a "primary" sub-attribute.', $type->name, $path));
    }

    private function takesSteps(AttributeDefinition $definition): bool
    {
        return $definition->multiValued
            && AttributeType::Complex === $definition->type
            && Mutability::ReadWrite === $definition->mutability
            && !$definition->required
            && $definition->subAttribute('value') instanceof AttributeDefinition
            && !$definition->subAttribute('primary') instanceof AttributeDefinition;
    }
}
