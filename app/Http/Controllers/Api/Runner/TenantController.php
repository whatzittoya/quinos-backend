<?php
namespace App\Http\Controllers\Api\Runner;
use App\Http\Controllers\Controller;
use App\Models\Client;
class TenantController extends Controller
{
    public function __invoke()
    {
        $tenants = Client::where('active', true)->with('shifts')->orderBy('id')->get()->map(fn ($client) => self::config($client));
        return response()->json(['protocol' => 1, 'tenants' => $tenants, 'sync' => [
            'packages' => config('sync.packages'), 'lookback_days' => config('sync.lookback_days'),
            'master_ttl_hours' => config('sync.master_ttl_hours'),
        ]])->header('Cache-Control', 'no-store, private');
    }

    public static function config(Client $client): array
    {
        return [
            'id' => $client->id, 'name' => $client->name, 'timezone' => $client->timezone,
            'quinos_email' => $client->quinos_email, 'quinos_password' => $client->quinos_password,
            'quinos_company_id' => $client->quinos_company_id, 'shift_assign_by' => $client->shift_assign_by,
            'shifts' => $client->shifts->map->only(['label', 'starts_at', 'ends_at', 'position'])->all(),
            'has_mobile_api_token' => filled($client->mobile_api_token_hash),
            'mobile_api_token' => $client->mobile_api_token,
            'mobile_api_url' => $client->mobile_api_token ? $client->mobileApiUrl($client->mobile_api_token) : null,
        ];
    }
}
