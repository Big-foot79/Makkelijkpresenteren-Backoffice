<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TaskStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Dismissed = 'dismissed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In behandeling',
            self::Done => 'Afgerond',
            self::Dismissed => 'Genegeerd',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::InProgress => 'info',
            self::Done => 'success',
            self::Dismissed => 'gray',
        };
    }

    /** @return list<self> */
    public static function active(): array
    {
        return [self::Open, self::InProgress];
    }
}
