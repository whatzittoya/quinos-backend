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

    protected $hidden = ['quinos_password', 'mobile_api_token_hash'];

    protected $casts = [
        // Encrypted with APP_KEY; never returned by the API.
        'quinos_password' => 'encrypted',
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

    /** Makes a new mobile API key (replacing any old one). Only its hash is stored, so this is the one chance to show it. */
    public function issueMobileApiToken(): string
    {
        $token = bin2hex(random_bytes(20)); // 40 hex characters, the shape of the old quinoscloud tokens
        $this->forceFill(['mobile_api_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    /** The URL the mobile app calls, with the client's store code when bills have been uploaded already. */
    public function mobileApiUrl(string $token): string
    {
        $store = PosTransaction::withoutGlobalScope('client')->where('client_id', $this->id)
            ->whereNotNull('store_code')->latest('date')->value('store_code') ?? '{STORE}';

        return url("/api/cloud2/transactions/getTransactionByDate/{$token}/{$store}/{date}");
    }

    public function hasQuinosCredentials(): bool
    {
        return filled($this->quinos_email) && filled($this->quinos_password);
    }
}
