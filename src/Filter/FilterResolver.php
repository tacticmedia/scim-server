<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Filter;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Path\AttributePath;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Returned;

/**
 * Rewrites every path to its schema URN and schema spelling and rejects what the schema does not allow. A
 * comparison or pr on a complex attribute without a sub-attribute targets its "value" sub-attribute, so that
 * manager eq "x" works (Entra). A boolean comparison value "True" or "False" becomes a JSON boolean.
 *
 * A filter or sortBy on an attribute that is never returned, or on one of its sub-attributes, is rejected: a match
 * or an order reveals a part of the value. RFC 7643 section 7 makes such filters optional.
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.2.2
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-7
 */
final readonly class FilterResolver
{
    /**
     * @throws ScimException invalidFilter
     */
    public function resolve(Filter $filter, ResourceType $type): Filter
    {
        return $this->resolveNode($filter, $type, null);
    }

    /**
     * @throws ScimException invalidFilter
     */
    public function resolveComparison(Comparison $comparison, ResourceType $type): Comparison
    {
        return $this->comparison($comparison, $type, null);
    }

    /**
     * @throws ScimException invalidFilter
     */
    public function resolveValuePath(ValuePathFilter $filter, ResourceType $type): ValuePathFilter
    {
        return $this->valuePath($filter, $type, null);
    }

    /**
     * A complex attribute without a sub-attribute sorts by its "value" sub-attribute, as a filter compares it.
     *
     * @return ResolvedAttribute|null null when the resource type does not define $sortBy
     *
     * @throws ScimException invalidValue
     */
    public function resolveSortBy(AttributePath $sortBy, ResourceType $type): ?ResolvedAttribute
    {
        $resolved = $type->resolve($sortBy);

        if (!$resolved instanceof ResolvedAttribute) {
            return null;
        }

        $resolved = $this->withImplicitValue($resolved);

        if ($this->isNeverReturned($resolved)) {
            throw ScimException::invalidValue(\sprintf('The sortBy attribute "%s" is never returned and cannot be used to sort.', $resolved->path()));
        }

        return $resolved;
    }

    private function resolveNode(Filter $filter, ResourceType $type, ?ResolvedAttribute $parent): Filter
    {
        return match (true) {
            $filter instanceof LogicalAnd => new LogicalAnd($this->resolveNode($filter->left, $type, $parent), $this->resolveNode($filter->right, $type, $parent)),
            $filter instanceof LogicalOr => new LogicalOr($this->resolveNode($filter->left, $type, $parent), $this->resolveNode($filter->right, $type, $parent)),
            $filter instanceof Not => new Not($this->resolveNode($filter->filter, $type, $parent)),
            $filter instanceof ValuePathFilter => $this->valuePath($filter, $type, $parent),
            $filter instanceof Present => new Present($this->resolvePath($filter->path, $type, $parent)->path()),
            $filter instanceof Comparison => $this->comparison($filter, $type, $parent),
            default => throw ScimException::invalidFilter(\sprintf('The filter node %s is not supported.', $filter::class)),
        };
    }

    private function valuePath(ValuePathFilter $filter, ResourceType $type, ?ResolvedAttribute $parent): ValuePathFilter
    {
        if ($parent instanceof ResolvedAttribute) {
            throw ScimException::invalidFilter(\sprintf("Value paths do not nest: '%s[...]' is inside '%s[...]'.", $filter->path, $parent->path()));
        }

        $attribute = $this->resolveAttribute($filter->path, $type);

        if (AttributeType::Complex !== $attribute->attribute->type || $attribute->subAttribute instanceof AttributeDefinition) {
            throw ScimException::invalidFilter(\sprintf("'%s[...]' needs a complex attribute, and '%s' is not one.", $filter->path, $attribute->path()));
        }

        return new ValuePathFilter($attribute->path(), $this->resolveNode($filter->filter, $type, $attribute));
    }

    private function comparison(Comparison $comparison, ResourceType $type, ?ResolvedAttribute $parent): Comparison
    {
        $resolved = $this->resolvePath($comparison->path, $type, $parent);
        $definition = $resolved->definition();
        $path = $resolved->path();

        if (AttributeType::Complex === $definition->type) {
            throw ScimException::invalidFilter(\sprintf("'%s' is complex. Compare one of its sub-attributes.", $path));
        }

        if (!$this->operatorApplies($comparison->operator, $definition->type)) {
            throw ScimException::invalidFilter(\sprintf("The operator '%s' does not apply to the %s attribute '%s'.", $comparison->operator->value, $definition->type->value, $path));
        }

        return new Comparison($path, $comparison->operator, $this->fittingValue($comparison, $definition, $path));
    }

    private function resolvePath(AttributePath $path, ResourceType $type, ?ResolvedAttribute $parent): ResolvedAttribute
    {
        $resolved = $this->resolveAttribute($path, $type);

        if ($parent instanceof ResolvedAttribute && (!$resolved->subAttribute instanceof AttributeDefinition || !$resolved->path()->parent()->equals($parent->path()))) {
            throw ScimException::invalidFilter(\sprintf("'%s' is not a sub-attribute of '%s'.", $path, $parent->path()));
        }

        $resolved = $this->withImplicitValue($resolved);

        if ($this->isNeverReturned($resolved)) {
            throw ScimException::invalidFilter(\sprintf("The attribute '%s' is never returned and cannot be used in a filter.", $resolved->path()));
        }

        return $resolved;
    }

    private function resolveAttribute(AttributePath $path, ResourceType $type): ResolvedAttribute
    {
        return $type->resolve($path)
            ?? throw ScimException::invalidFilter(\sprintf("The attribute '%s' is not defined for the resource type %s.", $path, $type->name));
    }

    private function withImplicitValue(ResolvedAttribute $resolved): ResolvedAttribute
    {
        $attribute = $resolved->attribute;

        if ($resolved->subAttribute instanceof AttributeDefinition || AttributeType::Complex !== $attribute->type) {
            return $resolved;
        }

        $value = $attribute->subAttribute('value');

        return $value instanceof AttributeDefinition ? new ResolvedAttribute($resolved->schema, $attribute, $value) : $resolved;
    }

    private function isNeverReturned(ResolvedAttribute $resolved): bool
    {
        return array_any(
            [$resolved->attribute, $resolved->subAttribute],
            static fn (?AttributeDefinition $definition): bool => Returned::Never === $definition?->returned || Mutability::WriteOnly === $definition?->mutability,
        );
    }

    /**
     * RFC 7644 Table 3 forbids gt, ge, lt and le on boolean and binary. RFC 7644 does not define co, sw and ew for
     * the other types that are not strings.
     */
    private function operatorApplies(ComparisonOperator $operator, AttributeType $type): bool
    {
        return match (true) {
            $operator->isOrdering() => !\in_array($type, [AttributeType::Boolean, AttributeType::Binary], true),
            $operator->isSubstring() => \in_array($type, [AttributeType::String, AttributeType::Reference], true),
            default => true,
        };
    }

    private function fittingValue(Comparison $comparison, AttributeDefinition $definition, AttributePath $path): string|int|float|bool|null
    {
        $value = $comparison->value;

        if (null === $value) {
            return \in_array($comparison->operator, [ComparisonOperator::Equal, ComparisonOperator::NotEqual], true)
                ? null
                : throw ScimException::invalidFilter(\sprintf("The operator '%s' does not accept null.", $comparison->operator->value));
        }

        return $this->coerce($value, $definition->type)
            ?? throw ScimException::invalidFilter(\sprintf("The value %s does not fit the %s attribute '%s'.", var_export($value, true), $definition->type->value, $path));
    }

    private function coerce(string|int|float|bool $value, AttributeType $type): string|int|float|bool|null
    {
        if (AttributeType::Boolean === $type) {
            return ValueCoercion::toBoolean($value);
        }

        $fits = match ($type) {
            AttributeType::Integer => \is_int($value),
            AttributeType::Decimal => \is_int($value) || \is_float($value),
            AttributeType::DateTime => ValueCoercion::toInstant($value) instanceof \DateTimeImmutable,
            default => \is_string($value),
        };

        return $fits ? $value : null;
    }
}
