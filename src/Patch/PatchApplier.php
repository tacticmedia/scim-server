<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Patch;

use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Resource\DocumentNormalizer;
use TacticMedia\ScimServer\Schema\ResolvedAttribute;
use TacticMedia\ScimServer\Schema\ResourceType;

/**
 * Applies PATCH operations to a stored document in schema spelling. The caller passes the result to
 * {@see DocumentNormalizer::forReplace()} with the stored document for the type, required, readOnly and immutable
 * checks of the whole document.
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.5.2
 */
final readonly class PatchApplier
{
    private PatchTargetResolver $targets;

    private ValueEditor $values;

    public function __construct()
    {
        $this->targets = new PatchTargetResolver();
        $this->values = new ValueEditor();
    }

    /**
     * Operations apply in order, each to the result of the one before. Any error aborts the whole request. When no
     * operation changes a value, the result is identical to $document.
     *
     * @param array<string, mixed> $document the stored document
     *
     * @return array<string, mixed>
     *
     * @throws ScimException invalidPath, invalidFilter, invalidValue, invalidSyntax, mutability or noTarget, with a
     *                       detail that starts with "Operations[<index>]: "
     */
    public function apply(array $document, PatchRequest $request, ResourceType $type): array
    {
        return $this->applyOperations($document, $request->operations, $type);
    }

    /**
     * {@see apply()} for some operations of a request.
     *
     * @internal
     *
     * @param array<string, mixed>       $document
     * @param array<int, PatchOperation> $operations by index in the request
     *
     * @return array<string, mixed>
     */
    public function applyOperations(array $document, array $operations, ResourceType $type): array
    {
        foreach ($operations as $index => $operation) {
            $document = $this->applyOperation($document, $operation, $index, $type);
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function applyOperation(array $document, PatchOperation $operation, int $index, ResourceType $type): array
    {
        try {
            foreach ($this->targets->targets($operation, $type) as [$target, $value]) {
                $document = $this->applyTo($document, $operation->type, $target, $value, $type);
            }
        } catch (ScimException $scimException) {
            throw new ScimException($scimException->status, \sprintf('Operations[%d]: %s', $index, $scimException->detail), $scimException->scimType, $scimException);
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function applyTo(array $document, PatchOperationType $operation, PatchTarget $target, mixed $value, ResourceType $type): array
    {
        $attribute = $target->whole();
        $stored = $this->read($document, $attribute, $type);
        $changed = PrimaryValue::demoteOthers($stored, $this->values->apply($operation, $stored, $target, $value), $attribute->attribute);
        ChangeGuard::check($attribute, $stored, $changed);

        return ChangeGuard::same($stored, $changed) ? $document : $this->write($document, $attribute, $changed, $type);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function read(array $document, ResolvedAttribute $attribute, ResourceType $type): mixed
    {
        $container = $attribute->schema->id === $type->schema->id ? $document : ($document[$attribute->schema->id] ?? null);

        return \is_array($container) ? ($container[$attribute->attribute->name] ?? null) : null;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function write(array $document, ResolvedAttribute $attribute, mixed $value, ResourceType $type): array
    {
        $schemaId = $attribute->schema->id;

        if ($schemaId === $type->schema->id) {
            return $this->with($document, $attribute->attribute->name, $value);
        }

        $container = $this->with(\is_array($document[$schemaId] ?? null) ? $document[$schemaId] : [], $attribute->attribute->name, $value);

        return $this->with($document, $schemaId, [] === $container ? null : $container);
    }

    /**
     * @template TKey of array-key
     *
     * @param array<TKey, mixed> $values
     * @param TKey               $key
     *
     * @return array<TKey, mixed>
     */
    private function with(array $values, int|string $key, mixed $value): array
    {
        $values[$key] = $value;

        return array_filter($values, static fn (mixed $entry): bool => null !== $entry);
    }
}
