<?php

namespace App\Enums;

enum CrawlStatus: string
{
    case Pending = 'pending';
    case Ok = 'ok';
    case Failed = 'failed';
}
