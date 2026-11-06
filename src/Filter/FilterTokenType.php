<?php

declare(strict_types=1);

namespace TacticMedia\ScimServer\Filter;

/**
 * The value of each case is its token pattern. {@see FilterLexer} tries them in case order, so Unknown, which
 * matches any run of other characters, is last.
 *
 * Keywords, operators and the literals true, false and null are Name tokens: their meaning depends on where they
 * occur, so the parser decides.
 */
enum FilterTokenType: string
{
    case String = '"(?:[^"\\\\]|\\\\.)*"';
    case Number = '-?\d+(?:\.\d+)?(?:e[+-]?\d+)?';
    case Name = '[a-z$][a-z0-9_.:$-]*';
    case SubAttribute = '\.[a-z$][a-z0-9_$-]*';
    case OpenParenthesis = '\(';
    case CloseParenthesis = '\)';
    case OpenBracket = '\[';
    case CloseBracket = '\]';
    case Unknown = '[^\s()\[\]]+';
}
