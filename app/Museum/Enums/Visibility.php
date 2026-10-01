<?php

namespace App\Museum\Enums;

enum Visibility: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Hidden = 'hidden';
    case Restricted = 'restricted';
}
