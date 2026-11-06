<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Resource;

use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\Returned;

/**
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-7
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.9
 */
final readonly class Projection
{
    public function __construct(
        private AttributeSelection $attributes,
        private AttributeSelection $excludedAttributes,
        private AttributeSelection $requested,
    ) {
    }

    /**
     * @return mixed null when nothing of the value is returned
     */
    public function project(mixed $value, ResolvedAttribute $attribute): mixed
    {
        if (!$this->returns($attribute)) {
            return null;
        }

        if (AttributeType::Complex !== $attribute->attribute->type) {
            return $value;
        }

        return $attribute->attribute->multiValued && \is_array($value)
            ? $this->projectList($value, $attribute)
            : $this->projectComplex($value, $attribute);
    }

    /**
     * @param array<mixed> $elements
     *
     * @return non-empty-list<mixed>|null
     */
    private function projectList(array $elements, ResolvedAttribute $attribute): ?array
    {
        $projected = array_values(array_filter(
            array_map(fn (mixed $element): mixed => $this->projectComplex($element, $attribute), $elements),
            static fn (mixed $element): bool => null !== $element,
        ));

        return [] === $projected ? null : $projected;
    }

    private function projectComplex(mixed $value, ResolvedAttribute $attribute): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $projected = [];

        foreach ($value as $name => $subValue) {
            $subAttribute = $attribute->attribute->subAttribute((string) $name);

            if ($subAttribute instanceof AttributeDefinition && $this->returns(new ResolvedAttribute($attribute->schema, $attribute->attribute, $subAttribute))) {
                $projected[$name] = $subValue;
            }
        }

        return [] === $projected ? null : $projected;
    }

    private function returns(ResolvedAttribute $attribute): bool
    {
        return match ($this->returned($attribute)) {
            Returned::Never => false,
            Returned::Always => true,
            Returned::Default => !$this->excludedAttributes->names($attribute) && ($this->attributes->isEmpty() || $this->attributes->selects($attribute)),
            Returned::Request => !$this->excludedAttributes->names($attribute) && ($this->attributes->selects($attribute) || $this->requested->selects($attribute)),
        };
    }

    /**
     * A default sub-attribute of an always-returned attribute is always returned.
     */
    private function returned(ResolvedAttribute $attribute): Returned
    {
        $returned = $attribute->definition()->returned;

        return Returned::Default === $returned && Returned::Always === $attribute->attribute->returned ? Returned::Always : $returned;
    }
}
