<?php

namespace App\Museum\Enums;

enum License: string
{
    case PublicDomain = 'public_domain';
    case Cc0 = 'cc0';
    case CcBy = 'cc_by';
    case CcBySa = 'cc_by_sa';
    case CcByNc = 'cc_by_nc';
    case CcByNcSa = 'cc_by_nc_sa';
    case CcByNd = 'cc_by_nd';
    case CcByNcNd = 'cc_by_nc_nd';
    case Odbl = 'odbl';
    case PermissionGranted = 'permission_granted';
    case Restricted = 'restricted';
    case Unknown = 'unknown';

    /** Whether files under this license may be served to the public. */
    public function allowsPublicDisplay(): bool
    {
        return ! in_array($this, [self::Restricted, self::Unknown], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PublicDomain => 'Public Domain',
            self::Cc0 => 'CC0',
            self::CcBy => 'CC BY',
            self::CcBySa => 'CC BY-SA',
            self::CcByNc => 'CC BY-NC',
            self::CcByNcSa => 'CC BY-NC-SA',
            self::CcByNd => 'CC BY-ND',
            self::CcByNcNd => 'CC BY-NC-ND',
            self::Odbl => 'ODbL',
            self::PermissionGranted => 'با اجازه صاحب اثر',
            self::Restricted => 'محدود',
            self::Unknown => 'نامعلوم',
        };
    }
}
