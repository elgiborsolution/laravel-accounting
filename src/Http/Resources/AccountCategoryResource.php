<?php

namespace ESolution\LaravelAccounting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'category_code' => $this->category_code,
            'category_name' => $this->category_name,
            'type' => $this->type,
            'parent_id' => $this->parent_id,
            'sequence_no' => $this->sequence_no,
            'is_active' => (bool) $this->status,
        ];

        if ($this->relationLoaded('children')) {
            $data['children'] = self::collection($this->children);
        }

        if ($this->relationLoaded('accounts')) {
            $data['accounts'] = $this->accounts;
        }

        return $data;
    }
}
