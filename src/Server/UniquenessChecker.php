<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Filter\Comparison;
use TacticMedia\ScimServer\Filter\ComparisonOperator;
use TacticMedia\ScimServer\Query\ListQuery;
use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServer\Schema\AttributeDefinition;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;
use TacticMedia\ScimServer\Schema\Schema;
use TacticMedia\ScimServer\Schema\Uniqueness;

/**
 * Checks the single-valued attributes with uniqueness "server" or "global" against the repository of the resource
 * type, with the case rules of the "eq" filter operator. "id" is the repository's concern.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-2.2
 */
final readonly class UniquenessChecker
{
    /**
     * @param array<string, mixed> $document normalized, in schema spelling
     * @param string|null          $ownId    the id of the resource that $document replaces
     *
     * @throws ScimException uniqueness, 409
     */
    public function check(array $document, RegisteredResourceType $registered, ?string $ownId = null): void
    {
        foreach ($this->uniqueAttributes($registered->type) as $attribute) {
            if ($this->isTaken($attribute, $this->valueOf($document, $attribute, $registered->type), $registered->repository, $ownId)) {
                throw ScimException::uniqueness(\sprintf('The value of attribute "%s" is already in use.', $this->label($attribute, $registered->type)));
            }
        }
    }

    /**
     * @return list<ResolvedAttribute>
     */
    private function uniqueAttributes(ResourceType $type): array
    {
        return array_merge(...array_map(
            static fn (Schema $schema): array => array_map(
                static fn (AttributeDefinition $attribute): ResolvedAttribute => new ResolvedAttribute($schema, $attribute),
                array_values(array_filter($schema->attributes, self::mustBeUnique(...))),
            ),
            $type->schemas(),
        ));
    }

    private static function mustBeUnique(AttributeDefinition $attribute): bool
    {
        return Uniqueness::None !== $attribute->uniqueness && !$attribute->multiValued && 0 !== strcasecmp($attribute->name, 'id');
    }

    /**
     * @param array<string, mixed> $document
     */
    private function valueOf(array $document, ResolvedAttribute $attribute, ResourceType $type): mixed
    {
        $container = $attribute->schema === $type->schema ? $document : $document[$attribute->schema->id] ?? null;

        return \is_array($container) ? $container[$attribute->attribute->name] ?? null : null;
    }

    private function isTaken(ResolvedAttribute $attribute, mixed $value, ResourceRepository $repository, ?string $ownId): bool
    {
        if (!\is_scalar($value)) {
            return false;
        }

        $matches = $repository->list(new ListQuery(new Comparison($attribute->path(), ComparisonOperator::Equal, $value), count: 2))->resources;

        return array_any($matches, static fn (array $match): bool => ($match['id'] ?? null) !== $ownId);
    }

    private function label(ResolvedAttribute $attribute, ResourceType $type): string
    {
        return $attribute->schema === $type->schema ? $attribute->attribute->name : (string) $attribute->path();
    }
}
