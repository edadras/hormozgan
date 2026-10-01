<?php

namespace App\Museum\Enums;

enum ValueType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Year = 'year';
    case Date = 'date';
    case Boolean = 'boolean';
    case Entity = 'entity';
    case Geo = 'geo';
    case Json = 'json';
    case Unknown = 'unknown';
}
