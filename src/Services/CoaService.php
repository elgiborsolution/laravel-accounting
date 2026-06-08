<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Models\Account;
use ESolution\LaravelAccounting\Models\AccountCategory;

class CoaService
{
    public function createAccount(array $data)
    {
        return Account::create($data);
    }

    public function getTree()
    {
        return AccountCategory::with(['accounts' => function ($query) {
            $query->whereNull('parent_id')->with('children');
        }])->get();
    }

    public function activateAccount($id)
    {
        return Account::where('id', $id)->update(['is_active' => true]);
    }

    public function deactivateAccount($id)
    {
        return Account::where('id', $id)->update(['is_active' => false]);
    }
}
