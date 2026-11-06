<?php

namespace ESolution\LaravelAccounting\Tests\Feature\Concerns;

use Illuminate\Database\Eloquent\Model;

class ReportingTenant extends Model
{
    protected $connection = 'rp_registry';

    protected $table = 'tenants';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
