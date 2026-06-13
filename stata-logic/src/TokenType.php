<?php

declare(strict_types=1);

namespace Quesfix\StataLogic;

enum TokenType
{
    case Number;
    case String;
    case Ident;
    case Missing;
    case LParen;
    case RParen;
    case Comma;
    case Plus;
    case Minus;
    case Star;
    case Slash;
    case Caret;
    case Not;
    case And;
    case Or;
    case Eq;
    case Ne;
    case Gt;
    case Lt;
    case Ge;
    case Le;
    case Question;
    case Eof;
}
