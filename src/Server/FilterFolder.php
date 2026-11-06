<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\Filter;
use TacticMedia\ScimServer\Filter\FilterEvaluator;
use TacticMedia\ScimServer\Filter\FilterResolver;
use TacticMedia\ScimServer\Filter\LogicalAnd;
use TacticMedia\ScimServer\Filter\LogicalOr;
use TacticMedia\ScimServer\Filter\Not;
use TacticMedia\ScimServer\Filter\Present;
use TacticMedia\ScimServer\Filter\ValuePathFilter;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * Replaces the parts of a parsed filter that the resource type alone decides with true or false:
 *
 * - a comparison on meta.resourceType, which the server sets and repositories do not store (RFC 7644 3.4.2.1), also
 *   inside a value path on meta;
 * - in a query across resource types, an attribute that the type does not define: it has no value (RFC 7644
 *   3.4.2.1), so it matches as {@see FilterEvaluator} matches an unassigned attribute.
 *
 * Every other leaf is resolved first, so an invalid one fails also where the type decides the result.
 *
 * @internal
 */
final readonly class FilterFolder
{
    public function __construct(
        private FilterResolver $resolver = new FilterResolver(),
        private FilterEvaluator $evaluator = new FilterEvaluator(),
    ) {
    }

    /**
     * @return Filter|bool the unresolved rest of the filter, or the result when the type decides it
     *
     * @throws ScimException invalidFilter
     */
    public function fold(Filter $filter, ResourceType $type, bool $acrossTypes): Filter|bool
    {
        return match (true) {
            $filter instanceof LogicalAnd => $this->and($this->fold($filter->left, $type, $acrossTypes), $this->fold($filter->right, $type, $acrossTypes)),
            $filter instanceof LogicalOr => $this->or($this->fold($filter->left, $type, $acrossTypes), $this->fold($filter->right, $type, $acrossTypes)),
            $filter instanceof Not => $this->not($this->fold($filter->filter, $type, $acrossTypes)),
            $filter instanceof Comparison, $filter instanceof Present, $filter instanceof ValuePathFilter => $this->foldLeaf($filter, $type, $acrossTypes),
            default => $filter,
        };
    }

    private function and(Filter|bool $left, Filter|bool $right): Filter|bool
    {
        return match (true) {
            false === $left, false === $right => false,
            true === $left => $right,
            true === $right => $left,
            default => new LogicalAnd($left, $right),
        };
    }

    private function or(Filter|bool $left, Filter|bool $right): Filter|bool
    {
        return match (true) {
            true === $left, true === $right => true,
            false === $left => $right,
            false === $right => $left,
            default => new LogicalOr($left, $right),
        };
    }

    private function not(Filter|bool $filter): Filter|bool
    {
        return \is_bool($filter) ? !$filter : new Not($filter);
    }

    private function foldLeaf(Comparison|Present|ValuePathFilter $leaf, ResourceType $type, bool $acrossTypes): Filter|bool
    {
        $attribute = $type->resolve($leaf->path);

        if (!$attribute instanceof ResolvedAttribute && $acrossTypes) {
            return $this->evaluator->matches($leaf, [], $type);
        }

        $resolved = $this->resolver->resolve($leaf, $type);

        return match ((string) $attribute?->path()) {
            $type->schema->id.':meta.resourceType' => $this->evaluator->matches($resolved, ['meta' => ['resourceType' => $type->name]], $type),
            $type->schema->id.':meta' => $leaf instanceof ValuePathFilter ? $this->foldMetaValuePath($leaf, $type, $acrossTypes) : $leaf,
            default => $leaf,
        };
    }

    /**
     * meta is singular and every resource has it, so meta[filter] matches as its filter does, whose paths are absolute.
     */
    private function foldMetaValuePath(ValuePathFilter $leaf, ResourceType $type, bool $acrossTypes): Filter|bool
    {
        $filter = $this->fold($leaf->filter, $type, $acrossTypes);

        return \is_bool($filter) ? $filter : new ValuePathFilter($leaf->path, $filter);
    }
}
