<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Patch;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Resource\DocumentNormalizer;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\AttributeType;

/**
 * Computes the new value of a whole attribute from its stored value and one operation on a target in it.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.5.2
 */
final readonly class ValueEditor
{
    private DocumentNormalizer $normalizer;

    private ComplexValueEditor $complex;

    public function __construct()
    {
        $this->normalizer = new DocumentNormalizer();
        $this->complex = new ComplexValueEditor();
    }

    /**
     * @return mixed null when the attribute becomes unassigned
     *
     * @throws ScimException invalidSyntax, invalidValue, mutability or noTarget
     */
    public function apply(PatchOperationType $operation, mixed $stored, PatchTarget $target, mixed $value): mixed
    {
        // RFC 7643 section 2.5: null and "" unassign. Microsoft Entra ID clears a manager with "".
        $value = '' === $value && PatchOperationType::Remove !== $operation ? null : $value;

        if ($target->isWholeList()) {
            return $this->applyToList($operation, $stored, $target, $value);
        }

        $value = PatchOperationType::Remove === $operation ? null : $value;
        $attribute = $target->attribute->attribute;

        return match (true) {
            $attribute->multiValued => $this->complex->applyToSelected($stored, $target, $value, PatchOperationType::Replace === $operation),
            AttributeType::Complex === $attribute->type => $this->complex->applyToValue($stored, $target, $value),
            default => $this->normalizer->normalizeValue($value, $target->attribute),
        };
    }

    /**
     * add appends values that are not there yet (RFC 7644 section 3.5.2.1), so that a member add sent again changes
     * nothing. replace replaces the list. remove with a value removes only the listed values (Microsoft Entra ID,
     * Keycloak). A value is there or listed as {@see ValueIndex} defines it.
     */
    private function applyToList(PatchOperationType $operation, mixed $stored, PatchTarget $target, mixed $value): mixed
    {
        $values = $this->normalizer->normalizeValue($value, $target->attribute);
        $definition = $target->attribute->attribute;

        return match ($operation) {
            PatchOperationType::Add => $this->append($this->listOf($stored), $this->listOf($values), $definition),
            PatchOperationType::Replace => $values,
            PatchOperationType::Remove => null === $value ? null : $this->without($this->listOf($stored), $this->listOf($values), $definition),
        };
    }

    /**
     * @param list<mixed> $stored
     * @param list<mixed> $added
     *
     * @return list<mixed>|null
     */
    private function append(array $stored, array $added, AttributeDefinition $definition): ?array
    {
        $values = new ValueIndex($definition, $stored);

        foreach ($added as $element) {
            if (null === $values->first($element) && !$this->setPrimary($values, $element, $definition)) {
                $values->append($element);
            }
        }

        return $this->nullIfEmpty($values->values());
    }

    /**
     * An added value that differs from a stored one only in "primary" sets the "primary" of the stored one.
     *
     * @return bool false when no stored value matches
     */
    private function setPrimary(ValueIndex $values, mixed $element, AttributeDefinition $definition): bool
    {
        $primary = $definition->subAttribute('primary')?->name;

        if (null === $primary || !\is_array($element) || !\array_key_exists($primary, $element)) {
            return false;
        }

        $others = array_diff_key($element, [$primary => true]);
        $index = [] === $others ? null : $values->first($others);

        if (null !== $index) {
            $values->set($index, $primary, $element[$primary]);
        }

        return null !== $index;
    }

    /**
     * @param list<mixed> $stored
     * @param list<mixed> $removed
     *
     * @return list<mixed>|null
     */
    private function without(array $stored, array $removed, AttributeDefinition $definition): ?array
    {
        $values = new ValueIndex($definition, $stored);

        foreach ($removed as $entry) {
            $values->remove($entry);
        }

        return $this->nullIfEmpty($values->values());
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<mixed>|null
     */
    private function nullIfEmpty(array $values): ?array
    {
        return [] === $values ? null : $values;
    }

    /**
     * @return list<mixed>
     */
    private function listOf(mixed $value): array
    {
        return \is_array($value) ? array_values($value) : [];
    }
}
