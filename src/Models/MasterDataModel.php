<?php

namespace ESolution\LaravelAccounting\Models;

abstract class MasterDataModel extends AccountingModel
{
    protected bool $usesSharedMasterConnection = true;
}
