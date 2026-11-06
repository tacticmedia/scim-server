<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Server;

/**
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.2
 */
enum Endpoint
{
    case Root;
    case RootSearch;
    case ServiceProviderConfig;
    case ResourceTypes;
    case ResourceType;
    case Schemas;
    case Schema;
    case Me;
    case Bulk;
    case Collection;
    case CollectionSearch;
    case Resource;

    public static function named(string $segment): ?self
    {
        return match (strtolower($segment)) {
            '.search' => self::RootSearch,
            'serviceproviderconfig' => self::ServiceProviderConfig,
            'resourcetypes' => self::ResourceTypes,
            'schemas' => self::Schemas,
            'me' => self::Me,
            'bulk' => self::Bulk,
            default => null,
        };
    }

    /**
     * HEAD is allowed wherever GET is (RFC 9110 section 9.3.2).
     *
     * @return non-empty-list<string>
     */
    public function methods(): array
    {
        return match ($this) {
            self::RootSearch, self::CollectionSearch, self::Bulk => ['POST'],
            self::Collection => ['GET', 'HEAD', 'POST'],
            self::Resource => ['GET', 'HEAD', 'PUT', 'PATCH', 'DELETE'],
            self::Me => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'],
            default => ['GET', 'HEAD'],
        };
    }
}
