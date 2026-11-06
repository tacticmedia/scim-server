<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

use TacticMedia\ScimServer\Repository\ResourceRepository;
use TacticMedia\ScimServer\Schema\ResourceType;

final readonly class RegisteredResourceType
{
    public function __construct(
        public ResourceType $type,
        public ResourceRepository $repository,
    ) {
    }
}
