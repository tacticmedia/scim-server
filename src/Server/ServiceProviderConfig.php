<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

/**
 * The server supports PATCH, bulk operations, filtering, sorting and ETags. Bulk operations can be turned off.
 *
 * @see https://www.rfc-editor.org/rfc/rfc7643#section-5
 * @see https://www.rfc-editor.org/rfc/rfc9865#section-4
 */
final readonly class ServiceProviderConfig
{
    /**
     * @param int<1, max>                $maxResults              the largest page that a list or search returns
     * @param list<AuthenticationScheme> $authenticationSchemes
     * @param int<1, max>                $bulkMaxOperations
     * @param int<1, max>                $bulkMaxPayloadSizeBytes
     * @param int<1, max>|null           $cursorTimeoutSeconds    the lifetime of a cursor; null: cursors do not expire
     */
    public function __construct(
        public ?string $documentationUri = null,
        public int $maxResults = 100,
        public bool $changePasswordSupported = false,
        public array $authenticationSchemes = [],
        public bool $bulkSupported = true,
        public int $bulkMaxOperations = 1000,
        public int $bulkMaxPayloadSizeBytes = 1048576,
        public ?int $cursorTimeoutSeconds = null,
    ) {
    }

    /**
     * Without "schemas" and "meta". Figure 10 of RFC 7643 omits "etag", section 5 requires it (erratum 9018).
     *
     * @param bool $cursorSupported the server has a cursor key
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $cursorSupported = false): array
    {
        $config = [
            'patch' => ['supported' => true],
            'bulk' => $this->bulkSupported
                ? ['supported' => true, 'maxOperations' => $this->bulkMaxOperations, 'maxPayloadSize' => $this->bulkMaxPayloadSizeBytes]
                : ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => $this->maxResults],
            'changePassword' => ['supported' => $this->changePasswordSupported],
            'sort' => ['supported' => true],
            'etag' => ['supported' => true],
            'authenticationSchemes' => array_map(static fn (AuthenticationScheme $scheme): array => $scheme->toArray(), $this->authenticationSchemes),
            'pagination' => [
                'cursor' => $cursorSupported,
                'index' => true,
                'defaultPaginationMethod' => 'index',
                'defaultPageSize' => $this->maxResults,
                'maxPageSize' => $this->maxResults,
                ...($cursorSupported && null !== $this->cursorTimeoutSeconds ? ['cursorTimeout' => $this->cursorTimeoutSeconds] : []),
            ],
        ];

        return null === $this->documentationUri ? $config : ['documentationUri' => $this->documentationUri, ...$config];
    }
}
