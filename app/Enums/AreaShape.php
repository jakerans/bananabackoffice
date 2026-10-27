<?php

namespace App\Enums;

/**
 * How an estimate's area is measured (docs/slice-31-estimating-plan.md, step
 * 31b). The same width, length and height give different amounts by shape.
 */
enum AreaShape: string
{
    /** Four walls around a floor: the length around is twice the width and length, the wall area that times the height. */
    case Room = 'room';

    /** One flat surface, such as a side of a house or a fence: the length is its length, the wall area its length times its height. */
    case Surface = 'surface';

    public function label(): string
    {
        return match ($this) {
            self::Room => __('Room'),
            self::Surface => __('Surface'),
        };
    }
}
