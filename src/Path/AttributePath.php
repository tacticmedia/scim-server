<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Path;

/**
 * attrPath = [URI ":"] ATTRNAME *1subAttr.
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.10
 */
final readonly class AttributePath implements \Stringable
{
    /**
     * `$ref` matches in any case only with the modifier i.
     */
    public const string NAME = '(?:[A-Za-z][A-Za-z0-9_-]*|\$ref)';

    public function __construct(
        public ?string $schemaUrn,
        public string $attribute,
        public ?string $subAttribute = null,
    ) {
    }

    /**
     * Returns null when the path does not match the grammar. A URN prefix ends at the last colon, because
     * attribute names never contain one.
     */
    public static function parse(string $path): ?self
    {
        $schemaUrn = null;

        if (0 === stripos($path, 'urn:')) {
            $lastColon = (int) strrpos($path, ':');
            $schemaUrn = substr($path, 0, $lastColon);
            $path = substr($path, $lastColon + 1);
        }

        if (1 !== preg_match('/^('.self::NAME.')(?:\.('.self::NAME.'))?$/iD', $path, $matches)) {
            return null;
        }

        return new self($schemaUrn, $matches[1], $matches[2] ?? null);
    }

    public function parent(): self
    {
        return new self($this->schemaUrn, $this->attribute);
    }

    public function withSubAttribute(?string $subAttribute): self
    {
        return new self($this->schemaUrn, $this->attribute, $subAttribute);
    }

    public function equals(self $other): bool
    {
        return 0 === strcasecmp((string) $this, (string) $other);
    }

    #[\Override]
    public function __toString(): string
    {
        return (null === $this->schemaUrn ? '' : $this->schemaUrn.':').$this->attribute.(null === $this->subAttribute ? '' : '.'.$this->subAttribute);
    }
}
