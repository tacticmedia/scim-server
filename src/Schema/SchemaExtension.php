<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Schema;

/** @see https://www.rfc-editor.org/rfc/rfc7643#section-6 */
final readonly class SchemaExtension
{
    public function __construct(
        public Schema $schema,
        public bool $required = false,
    ) {
    }
}
