<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Filter;

use Doctrine\Common\Lexer\Token;
use TacticMedia\ScimServer\Exception\ScimException;
use TacticMedia\ScimServer\Path\AttributePath;

/**
 * Recursive descent over RFC 7644 Figure 1 with errata 4670 (attribute expression > not > and > or), 4690 and 7322
 * (no nested value path) and 7319 ("not" before "(" with optional spaces).
 *
 * @internal
 *
 * @see https://www.rfc-editor.org/rfc/rfc7644#section-3.4.2.2
 */
final readonly class ExpressionParser
{
    /**
     * PHP frees a filter tree recursively in C, so a deep tree crashes the process. The length also bounds the
     * and/or chains, which nest without parentheses.
     */
    private const int MAX_LENGTH_BYTES = 8192;
    private const int MAX_DEPTH = 64;

    private function __construct(
        private FilterLexer $lexer,
    ) {
    }

    /**
     * @param AttributePath|null $parent the attribute of a value path: names in the input are its sub-attributes
     */
    public static function parse(string $input, ?AttributePath $parent = null): Filter
    {
        if (\strlen($input) > self::MAX_LENGTH_BYTES) {
            throw ScimException::invalidFilter(\sprintf('The filter is longer than %d bytes.', self::MAX_LENGTH_BYTES));
        }

        if (1 !== preg_match('//u', $input)) {
            throw ScimException::invalidFilter('The filter is not valid UTF-8.');
        }

        $lexer = new FilterLexer();
        $lexer->setInput($input);
        self::rejectDeepNesting($lexer);
        $lexer->moveNext();

        $parser = new self($lexer);
        $filter = $parser->parseDisjunction($parent);

        if ($lexer->lookahead instanceof Token) {
            throw $parser->unexpected("'and', 'or' or the end of the filter");
        }

        return $filter;
    }

    /**
     * {@see FilterLexer::moveNext()} resets the peek. A ")" or "]" that lowers the count below 0 is a parse error,
     * so the parse stops there, before a deeper level.
     */
    private static function rejectDeepNesting(FilterLexer $lexer): void
    {
        $depth = 0;

        while (($token = $lexer->peek()) instanceof Token) {
            $depth += self::depthChange($token->type);

            if ($depth > self::MAX_DEPTH) {
                throw ScimException::invalidFilter(\sprintf('The filter nests deeper than %d levels at offset %d.', self::MAX_DEPTH, $token->position));
            }
        }
    }

    private static function depthChange(?FilterTokenType $type): int
    {
        return match ($type) {
            FilterTokenType::OpenParenthesis, FilterTokenType::OpenBracket => 1,
            FilterTokenType::CloseParenthesis, FilterTokenType::CloseBracket => -1,
            default => 0,
        };
    }

    private function parseDisjunction(?AttributePath $parent): Filter
    {
        $filter = $this->parseConjunction($parent);

        while ($this->nextIsKeyword('or')) {
            $this->lexer->moveNext();
            $filter = new LogicalOr($filter, $this->parseConjunction($parent));
        }

        return $filter;
    }

    private function parseConjunction(?AttributePath $parent): Filter
    {
        $filter = $this->parseFactor($parent);

        while ($this->nextIsKeyword('and')) {
            $this->lexer->moveNext();
            $filter = new LogicalAnd($filter, $this->parseFactor($parent));
        }

        return $filter;
    }

    private function parseFactor(?AttributePath $parent): Filter
    {
        if ($this->nextIsKeyword('not')) {
            $this->lexer->moveNext();

            return new Not($this->parseGroup($parent));
        }

        if ($this->lexer->isNextToken(FilterTokenType::OpenParenthesis)) {
            return $this->parseGroup($parent);
        }

        return $this->parseAttributeExpression($parent);
    }

    private function parseGroup(?AttributePath $parent): Filter
    {
        $this->expect(FilterTokenType::OpenParenthesis, "'('");
        $filter = $this->parseDisjunction($parent);
        $this->expect(FilterTokenType::CloseParenthesis, "')'");

        return $filter;
    }

    /**
     * Microsoft Entra ID sends valuePath subAttr compareOp compValue, which RFC 7644 does not define. It becomes the
     * value path with the sub-attribute comparison added to its filter.
     */
    private function parseAttributeExpression(?AttributePath $parent): Filter
    {
        $path = $this->parseAttributePath($parent);

        if (!$this->lexer->isNextToken(FilterTokenType::OpenBracket)) {
            return $this->parseOperation($path);
        }

        $valuePath = $this->parseValuePath($path, $parent);

        if (!$this->lexer->isNextToken(FilterTokenType::SubAttribute)) {
            return $valuePath;
        }

        $subAttribute = substr($this->expect(FilterTokenType::SubAttribute, 'a sub-attribute')->value, 1);

        return new ValuePathFilter($path, new LogicalAnd($valuePath->filter, $this->parseOperation($path->withSubAttribute($subAttribute))));
    }

    private function parseValuePath(AttributePath $path, ?AttributePath $parent): ValuePathFilter
    {
        $bracket = $this->expect(FilterTokenType::OpenBracket, "'['");

        if ($parent instanceof AttributePath) {
            throw ScimException::invalidFilter(\sprintf("Value paths do not nest: found '[' after '%s' at offset %d.", $path, $bracket->position));
        }

        if (null !== $path->subAttribute) {
            throw ScimException::invalidFilter(\sprintf("A value path needs an attribute without a sub-attribute, found '%s[' at offset %d.", $path, $bracket->position));
        }

        $filter = $this->parseDisjunction($path);
        $this->expect(FilterTokenType::CloseBracket, "']'");

        return new ValuePathFilter($path, $filter);
    }

    private function parseAttributePath(?AttributePath $parent): AttributePath
    {
        $token = $this->expect(FilterTokenType::Name, 'an attribute path');
        $path = AttributePath::parse($token->value)
            ?? throw ScimException::invalidFilter(\sprintf("'%s' at offset %d is not a valid attribute path.", $token->value, $token->position));

        if (!$parent instanceof AttributePath) {
            return $path;
        }

        if (null !== $path->schemaUrn || null !== $path->subAttribute) {
            throw ScimException::invalidFilter(\sprintf("Expected a sub-attribute name of '%s' at offset %d, found '%s'.", $parent, $token->position, $token->value));
        }

        return $parent->withSubAttribute($path->attribute);
    }

    private function parseOperation(AttributePath $path): Filter
    {
        $token = $this->expect(FilterTokenType::Name, \sprintf("an attribute operator after '%s'", $path));
        $keyword = strtolower($token->value);

        if ('pr' === $keyword) {
            return new Present($path);
        }

        $operator = ComparisonOperator::tryFrom($keyword)
            ?? throw ScimException::invalidFilter(\sprintf("The operator '%s' at offset %d is not supported.", $token->value, $token->position));

        return new Comparison($path, $operator, $this->parseComparisonValue());
    }

    private function parseComparisonValue(): string|int|float|bool|null
    {
        $token = $this->lexer->lookahead;

        if (!$token instanceof Token || !$this->isJsonValue($token)) {
            throw $this->unexpected('a comparison value (a JSON string, number, true, false or null)');
        }

        $this->lexer->moveNext();

        try {
            $value = json_decode($token->value, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw ScimException::invalidFilter(\sprintf("'%s' at offset %d is not a valid JSON value.", $token->value, $token->position), $jsonException);
        }

        \assert(\is_scalar($value) || null === $value);

        return $value;
    }

    /**
     * @param Token<FilterTokenType, string> $token
     */
    private function isJsonValue(Token $token): bool
    {
        return $token->isA(FilterTokenType::String, FilterTokenType::Number) || \in_array($token->value, ['true', 'false', 'null'], true);
    }

    /**
     * @return Token<FilterTokenType, string>
     */
    private function expect(FilterTokenType $type, string $expected): Token
    {
        $token = $this->lexer->lookahead;

        if (!$token instanceof Token || $token->type !== $type) {
            throw $this->unexpected($expected);
        }

        $this->lexer->moveNext();

        return $token;
    }

    private function nextIsKeyword(string $keyword): bool
    {
        return $this->lexer->isNextToken(FilterTokenType::Name) && 0 === strcasecmp($this->lexer->lookahead->value, $keyword);
    }

    private function unexpected(string $expected): ScimException
    {
        $token = $this->lexer->lookahead;

        return ScimException::invalidFilter($token instanceof Token
            ? \sprintf("Expected %s at offset %d, found '%s'.", $expected, $token->position, $token->value)
            : \sprintf('Expected %s, found the end of the filter.', $expected));
    }
}
