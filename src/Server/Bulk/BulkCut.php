<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server\Bulk;

use TacticMedia\ScimServer\MessageUrn;
use TacticMedia\ScimServer\Patch\PatchOperation;
use TacticMedia\ScimServer\Patch\PatchOperationType;
use TacticMedia\ScimServer\Patch\PatchTarget;
use TacticMedia\ScimServer\Patch\PatchTargetResolver;
use TacticMedia\ScimServer\Schema\Mutability;
use TacticMedia\ScimServer\Server\RegisteredResourceType;

/**
 * A POST of a bulkId cycle, created without its references to the POSTs that have not run, and the PATCH that adds
 * them once those POSTs ran.
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.7.1
 */
final readonly class BulkCut
{
    /**
     * @param BulkOperation                                       $post            without the removed attributes
     * @param list<string>                                        $deferredBulkIds in request order
     * @param list<array{op: string, path: string, value: mixed}> $additions       the operations of the completion PATCH
     */
    private function __construct(
        public BulkOperation $post,
        public array $deferredBulkIds,
        public RegisteredResourceType $registered,
        public array $additions,
    ) {
    }

    /**
     * @param list<string> $deferredBulkIds
     *
     * @return self|null null when a removed attribute is required, as the create would fail without it
     */
    public static function of(BulkOperation $post, array $deferredBulkIds, RegisteredResourceType $registered): ?self
    {
        [$without, $removed] = $post->withoutReferencesTo($deferredBulkIds, $registered->type);
        $targets = new PatchTargetResolver()->targets(new PatchOperation(PatchOperationType::Add, value: $removed), $registered->type);

        if (array_any($targets, static fn (array $target): bool => !self::isReadOnly($target[0]) && $target[0]->attribute->definition()->required)) {
            return null;
        }

        // The create drops readOnly values, so the PATCH does not add them.
        $additions = array_map(
            static fn (array $target): array => ['op' => 'add', 'path' => (string) $target[0]->attribute->path(), 'value' => $target[1]],
            array_values(array_filter($targets, static fn (array $target): bool => !self::isReadOnly($target[0]))),
        );

        return new self($without, $deferredBulkIds, $registered, $additions);
    }

    public function completion(): BulkOperation
    {
        return new BulkOperation($this->post->index, 'PATCH', $this->post->path, null, null, [
            'schemas' => [MessageUrn::PatchOp->value],
            'Operations' => $this->additions,
        ]);
    }

    private static function isReadOnly(PatchTarget $target): bool
    {
        return Mutability::ReadOnly === $target->attribute->definition()->mutability;
    }
}
