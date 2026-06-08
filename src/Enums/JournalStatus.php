<?php

namespace ESolution\LaravelAccounting\Enums;

enum JournalStatus: string
{
    case DRAFT = 'draft';
    case POSTED = 'posted';
    case REVERSED = 'reversed';
}
