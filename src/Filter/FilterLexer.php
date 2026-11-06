<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Filter;

use Doctrine\Common\Lexer\AbstractLexer;

/**
 * Token values are the raw input text. Whitespace separates tokens and is dropped.
 *
 * @extends AbstractLexer<FilterTokenType, string>
 */
final class FilterLexer extends AbstractLexer
{
    /**
     * @return list<string>
     */
    #[\Override]
    protected function getCatchablePatterns(): array
    {
        return array_map(static fn (FilterTokenType $type): string => $type->value, FilterTokenType::cases());
    }

    /**
     * @return list<string>
     */
    #[\Override]
    protected function getNonCatchablePatterns(): array
    {
        return ['\s+'];
    }

    #[\Override]
    protected function getType(string &$value): FilterTokenType
    {
        return array_find(
            FilterTokenType::cases(),
            static fn (FilterTokenType $type): bool => 1 === preg_match('/^(?:'.$type->value.')\z/iu', $value),
        ) ?? FilterTokenType::Unknown;
    }
}
