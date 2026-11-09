<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use Psr\EventDispatcher\EventDispatcherInterface;
use TacticMedia\ScimServer\Event\ResourcePatched;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Patch\DeltaSplit;
use TacticMedia\ScimServer\Patch\DeltaSplitter;
use TacticMedia\ScimServer\Patch\PatchApplier;
use TacticMedia\ScimServer\Patch\PatchRedactor;
use TacticMedia\ScimServer\Patch\PatchRequest;
use TacticMedia\ScimServer\Repository\DeltaRepository;
use TacticMedia\ScimServer\Repository\PatchResult;
use TacticMedia\ScimServer\Repository\ResourcePatch;
use TacticMedia\ScimServer\Repository\ValuesChange;
use TacticMedia\ScimServer\Resource\DocumentNormalizer;
use TacticMedia\ScimServer\Resource\DocumentProjector;

/**
 * PATCH through a {@see DeltaRepository}: the operations on other attributes apply to the document without the delta
 * attributes, and the repository applies the steps.
 *
 * @internal
 */
final readonly class DeltaPatcher
{
    public function __construct(
        private ?EventDispatcherInterface $events,
        private UniquenessChecker $uniqueness,
        private DocumentNormalizer $normalizer,
        private PatchApplier $patches,
        private DocumentProjector $projector,
        private PatchRedactor $redactor,
        private DeltaSplitter $splitter = new DeltaSplitter(),
    ) {
    }

    /**
     * @return DeltaSplit|null null when the PATCH takes the path of {@see DeltaRepository::replace()}
     */
    public function split(PatchRequest $patch, RegisteredResourceType $registered): ?DeltaSplit
    {
        $repository = $registered->repository;

        return $repository instanceof DeltaRepository ? $this->splitter->split($patch, $registered->type, $repository) : null;
    }

    /**
     * @param array<string, mixed> $light   the stored document without the delta attributes
     * @param string|null          $version the "meta.version" that the resource must still have
     *
     * @return array<string, mixed> the document without the delta attributes after the PATCH
     *
     * @throws ScimException
     */
    public function patch(RegisteredResourceType $registered, string $id, array $light, DeltaSplit $split, PatchRequest $patch, ?string $version): array
    {
        $patched = $this->patches->applyOperations($light, $split->operations, $registered->type);
        $document = $patched === $light ? null : $this->normalizer->forReplace($patched, $light, $registered->type);

        // RFC 7644 section 3.5.2.1: a PATCH that changes nothing keeps meta.lastModified, and thus the ETag.
        if (null === $document && [] === $split->steps) {
            return $light;
        }

        if (null !== $document) {
            $this->uniqueness->check($document, $registered, $id);
        }

        $result = $split->repository->patch($id, new ResourcePatch($document, $split->steps, $version));
        $after = $this->versioned($result->document, $registered);
        $this->dispatch($registered, $light, $result, $patch, null !== $document);

        return $after;
    }

    /**
     * Without "meta.version", the server would compute the ETag from a document without the delta attributes.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public function versioned(array $document, RegisteredResourceType $registered): array
    {
        return null === ETag::version($document)
            ? throw new \LogicException(\sprintf('The DeltaRepository of the resource type %s returned a document without "meta.version".', $registered->type->name)) : $document;
    }

    /**
     * @param array<string, mixed> $light
     */
    private function dispatch(RegisteredResourceType $registered, array $light, PatchResult $result, PatchRequest $patch, bool $documentChanged): void
    {
        $changes = array_values(array_filter($result->changes, static fn (ValuesChange $change): bool => [] !== $change->added || [] !== $change->removed));

        if ($documentChanged || [] !== $changes) {
            $type = $registered->type;
            $this->events?->dispatch(new ResourcePatched($type, $this->projector->returnable($light, $type), $this->projector->returnable($result->document, $type), $changes, $this->redactor->redact($patch, $type)));
        }
    }
}
