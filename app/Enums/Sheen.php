<?php

namespace App\Enums;

/**
 * The finish of a paint, least shiny first. A short fixed list (decision 141,
 * question 6); manufacturer, color and product are typed in.
 */
enum Sheen: string
{
    case Flat = 'flat';
    case Matte = 'matte';
    case Eggshell = 'eggshell';
    case Satin = 'satin';
    case SemiGloss = 'semi_gloss';
    case Gloss = 'gloss';

    public function label(): string
    {
        return match ($this) {
            self::Flat => 'Flat',
            self::Matte => 'Matte',
            self::Eggshell => 'Eggshell',
            self::Satin => 'Satin',
            self::SemiGloss => 'Semi-gloss',
            self::Gloss => 'Gloss',
        };
    }
}
