<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'quinos_email', 'quinos_password', 'quinos_company_id',
        'timezone', 'shift_assign_by', 'active',
    ];

    protected $hidden = ['quinos_password', 'mobile_api_token_hash', 'mobile_api_token'];

    protected $casts = [
        // Encrypted with APP_KEY; never returned by the API.
        'quinos_password' => 'encrypted',
        'mobile_api_token' => 'encrypted',
        'active' => 'boolean',
        'last_login_at' => 'datetime',
        'master_synced_at' => 'datetime',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(ClientShift::class)->orderBy('position');
    }

    /** Makes a new mobile API key, replacing any old one. */
    public function issueMobileApiToken(): string
    {
        $token = bin2hex(random_bytes(20)); // 40 hex characters, the shape of the old quinoscloud tokens
        $this->setMobileApiToken($token);

        return $token;
    }

    /** Stores the key encrypted (so admins can see it again) and its hash (what requests are matched on); null removes it. */
    public function setMobileApiToken(?string $token): void
    {
        $this->forceFill(['mobile_api_token' => $token, 'mobile_api_token_hash' => $token === null ? null : hash('sha256', $token)])->save();
    }

    /** The URL the mobile app calls, with the client's store code when bills have been uploaded already. */
    public function mobileApiUrl(string $token): string
    {
        $store = PosTransaction::withoutGlobalScope('client')->where('client_id', $this->id)
            ->whereNotNull('store_code')->latest('date')->value('store_code') ?? '{STORE}';

        return url("/api/cloud2/transactions/getTransactionByDate/{$token}/{$store}/{date}");
    }

    /** The MCP server address for AI assistants, with the same key. */
    public function mcpUrl(string $token): string
    {
        return url("/api/mcp/{$token}");
    }

    public function hasQuinosCredentials(): bool
    {
        return filled($this->quinos_email) && filled($this->quinos_password);
    }
}
