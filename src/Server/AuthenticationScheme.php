<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

/**
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-5
 */
final readonly class AuthenticationScheme
{
    /**
     * @param string $type for example "oauthbearertoken" or "httpbasic"; RFC 7643 section 5 requires it, Figure 10
     *                     omits it (erratum 8011)
     */
    public function __construct(
        public string $type,
        public string $name,
        public string $description,
        public ?string $specUri = null,
        public ?string $documentationUri = null,
        public bool $primary = false,
    ) {
    }

    /**
     * @return array<string, string|true>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'name' => $this->name,
            'description' => $this->description,
            'specUri' => $this->specUri,
            'documentationUri' => $this->documentationUri,
            'primary' => $this->primary ?: null,
        ], static fn (string|bool|null $value): bool => null !== $value);
    }
}
