<?php

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Client */
class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'quinos_email' => $this->quinos_email,
            // The password itself is never sent back.
            'has_quinos_password' => filled($this->quinos_password),
            'quinos_company_id' => $this->quinos_company_id,
            // Admin-only resource: the mobile API key and the app URL are shown so they can be copied again.
            'has_mobile_api_token' => filled($this->mobile_api_token_hash),
            'mobile_api_token' => $this->mobile_api_token,
            'mobile_api_url' => $this->mobile_api_token ? $this->mobileApiUrl($this->mobile_api_token) : null,
            'mcp_url' => $this->mobile_api_token ? $this->mcpUrl($this->mobile_api_token) : null,
            'timezone' => $this->timezone,
            'shift_assign_by' => $this->shift_assign_by,
            'active' => $this->active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
