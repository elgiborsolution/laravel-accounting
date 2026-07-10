<?php

namespace ESolution\LaravelAccounting\Services;

use ESolution\LaravelAccounting\Models\Account;

class CoaService
{
    public function __construct(protected AccountCategoryTreeService $treeService) {}

    public function createAccount(array $data)
    {
        unset($data['parent_id'], $data['level']);

        return Account::create($data);
    }

    public function getTree()
    {
        return $this->treeService->getTree();
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
