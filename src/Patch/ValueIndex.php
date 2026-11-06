<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Patch;

use TacticMedia\ScimServer\Filter\ValueComparator;
use TacticMedia\ScimServer\Schema\AttributeDefinition;

/**
 * The values of a multi-valued attribute, with hash lookups of the values that contain an entry.
 *
 * A value contains an entry when every sub-attribute of the entry equals that of the value, compared as
 * {@see ValueComparator::equals()} compares them. An entry with "value" also matches a value that does not have its
 * other sub-attributes, except "primary": the server derives "$ref", and a stored member often lacks "type". A
 * simple value contains an equal entry.
 *
 * Values are grouped by the names of their sub-attributes. In a group, an entry compares the sub-attributes that it
 * shares with the group, so a lookup is one hash per group.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.5.2.1
 */
final class ValueIndex
{
    private readonly CanonicalValue $canonical;

    /** @var array<string, true> */
    private readonly array $identifier;

    /** @var array<string, true> the sub-attributes that an entry with an identifier always compares */
    private readonly array $identity;

    /** @var array<int, mixed> */
    private array $values = [];

    /** @var array<int, array<string, string|null>> */
    private array $forms = [];

    /** @var array<string, array<int, true>> indexes by group: the sub-attribute names joined by "," */
    private array $groups = [];

    /** @var array<string, array<string, array<string, array<int, true>>>> ascending indexes by group, shared names and projection */
    private array $buckets = [];

    /**
     * @param list<mixed> $values
     */
    public function __construct(AttributeDefinition $definition, array $values)
    {
        $this->canonical = new CanonicalValue($definition);
        $identifier = $definition->subAttribute('value')?->name;
        $this->identifier = null === $identifier ? [] : [$identifier => true];
        $this->identity = array_fill_keys(array_filter([$identifier, $definition->subAttribute('primary')?->name], \is_string(...)), true);

        foreach ($values as $value) {
            $this->append($value);
        }
    }

    /**
     * @return list<mixed>
     */
    public function values(): array
    {
        return array_values($this->values);
    }

    public function append(mixed $value): void
    {
        $this->values[] = $value;
        $this->index(array_key_last($this->values));
    }

    /**
     * The index of the first value that contains $entry, null when no value does. A removal does not change the
     * indexes of the other values.
     */
    public function first(mixed $entry): ?int
    {
        $firsts = array_map(array_key_first(...), $this->matching($entry));

        return [] === $firsts ? null : min($firsts);
    }

    /**
     * Sets a sub-attribute of the complex value at $index.
     */
    public function set(int $index, string $name, mixed $subValue): void
    {
        $value = $this->values[$index];
        \assert(\is_array($value));
        $value[$name] = $subValue;
        $this->unindex($index);
        $this->values[$index] = $value;
        $this->index($index);
    }

    public function remove(mixed $entry): void
    {
        foreach ($this->matching($entry) as $bucket) {
            foreach (array_keys($bucket) as $index) {
                $this->unindex($index);
                unset($this->values[$index]);
            }
        }
    }

    /**
     * @return list<non-empty-array<int, true>> per group, the values that contain $entry
     */
    private function matching(mixed $entry): array
    {
        $probe = $this->canonical->of($entry);

        if (null === $probe) {
            return [];
        }

        $required = [] === array_intersect_key($probe, $this->identifier) ? $probe : array_intersect_key($probe, $this->identity);

        return array_values(array_filter(array_map(fn (string $group): array => $this->bucket($group, $probe, $required), array_keys($this->groups))));
    }

    /**
     * @param array<string, string|null> $probe
     * @param array<string, string|null> $required the part of $probe that a containing value must have
     *
     * @return array<int, true>
     */
    private function bucket(string $group, array $probe, array $required): array
    {
        $shared = array_intersect_key($probe, array_flip(explode(',', $group)));
        $projection = [] === array_diff_key($required, $shared) ? $this->projection($shared) : null;

        return null === $projection ? [] : $this->projections($group, implode(',', array_keys($shared)))[$projection] ?? [];
    }

    /**
     * @return array<string, array<int, true>>
     */
    private function projections(string $group, string $shared): array
    {
        if (!isset($this->buckets[$group][$shared])) {
            $this->buckets[$group][$shared] = [];
            $members = $this->groups[$group];
            ksort($members);

            foreach (array_keys($members) as $index) {
                $this->insert($group, $shared, $index);
            }
        }

        return $this->buckets[$group][$shared];
    }

    private function index(int $index): void
    {
        $form = $this->canonical->of($this->values[$index]);

        if (null === $form) {
            return;
        }

        $group = implode(',', array_keys($form));
        $this->forms[$index] = $form;
        $this->groups[$group][$index] = true;

        foreach (array_keys($this->buckets[$group] ?? []) as $shared) {
            $this->insert($group, (string) $shared, $index);
        }
    }

    private function insert(string $group, string $shared, int $index): void
    {
        $projection = $this->projectionOf($index, $shared);

        if (null === $projection) {
            return;
        }

        $last = array_key_last($this->buckets[$group][$shared][$projection] ?? []);
        $this->buckets[$group][$shared][$projection][$index] = true;

        if ($index < ($last ?? -1)) {
            ksort($this->buckets[$group][$shared][$projection]);
        }
    }

    private function unindex(int $index): void
    {
        $group = implode(',', array_keys($this->forms[$index]));
        unset($this->groups[$group][$index]);

        foreach (array_keys($this->buckets[$group] ?? []) as $shared) {
            unset($this->buckets[$group][$shared][(string) $this->projectionOf($index, (string) $shared)][$index]);
        }

        unset($this->forms[$index]);
    }

    private function projectionOf(int $index, string $shared): ?string
    {
        return $this->projection(array_intersect_key($this->forms[$index], array_flip(explode(',', $shared))));
    }

    /**
     * @param array<string, string|null> $canonical
     */
    private function projection(array $canonical): ?string
    {
        return \in_array(null, $canonical, true) ? null : serialize($canonical);
    }
}
