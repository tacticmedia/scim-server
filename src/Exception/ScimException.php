<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Exception;

use TacticMedia\ScimServer\MessageUrn;

/** @see https://www.rfc-editor.org/rfc/rfc7644#section-3.12 */
final class ScimException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $detail,
        public readonly ?ScimErrorType $scimType = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($detail, $status, $previous);
    }

    public static function invalidFilter(string $detail, ?\Throwable $previous = null): self
    {
        return new self(400, $detail, ScimErrorType::InvalidFilter, $previous);
    }

    public static function tooMany(string $detail): self
    {
        return new self(400, $detail, ScimErrorType::TooMany);
    }

    public static function uniqueness(string $detail, ?\Throwable $previous = null): self
    {
        return new self(409, $detail, ScimErrorType::Uniqueness, $previous);
    }

    public static function mutability(string $detail): self
    {
        return new self(400, $detail, ScimErrorType::Mutability);
    }

    public static function invalidSyntax(string $detail, ?\Throwable $previous = null): self
    {
        return new self(400, $detail, ScimErrorType::InvalidSyntax, $previous);
    }

    public static function invalidPath(string $detail): self
    {
        return new self(400, $detail, ScimErrorType::InvalidPath);
    }

    public static function noTarget(string $detail): self
    {
        return new self(400, $detail, ScimErrorType::NoTarget);
    }

    public static function invalidValue(string $detail): self
    {
        return new self(400, $detail, ScimErrorType::InvalidValue);
    }

    public static function invalidVersion(string $detail): self
    {
        return new self(400, $detail, ScimErrorType::InvalidVersion);
    }

    /**
     * One detail for every cause, so that the response reveals nothing about the cursor (RFC 9865 section 5.2).
     */
    public static function invalidCursor(): self
    {
        return new self(400, 'The cursor is not valid for this query.', ScimErrorType::InvalidCursor);
    }

    public static function expiredCursor(): self
    {
        return new self(400, 'The cursor has expired. Request the first page again.', ScimErrorType::ExpiredCursor);
    }

    public static function invalidCount(string $detail): self
    {
        return new self(400, $detail, ScimErrorType::InvalidCount);
    }

    public static function notFound(string $detail): self
    {
        return new self(404, $detail);
    }

    public static function methodNotAllowed(string $detail): self
    {
        return new self(405, $detail);
    }

    public static function conflict(string $detail): self
    {
        return new self(409, $detail);
    }

    public static function preconditionFailed(string $detail): self
    {
        return new self(412, $detail);
    }

    public static function payloadTooLarge(string $detail): self
    {
        return new self(413, $detail);
    }

    public static function notImplemented(string $detail): self
    {
        return new self(501, $detail);
    }

    /**
     * @return array{schemas: list<string>, status: string, scimType?: string, detail: string}
     */
    public function toArray(): array
    {
        $error = ['schemas' => [MessageUrn::Error->value], 'status' => (string) $this->status];

        if ($this->scimType instanceof ScimErrorType) {
            $error['scimType'] = $this->scimType->value;
        }

        return $error + ['detail' => $this->detail];
    }
}
