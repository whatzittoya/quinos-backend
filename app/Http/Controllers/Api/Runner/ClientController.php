<?php
namespace App\Http\Controllers\Api\Runner;
use App\Actions\CreateDefaultShifts;
use App\Http\Controllers\Api\Admin\ClientController as AdminClientController;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** Lets the local app create and edit clients and their mobile API key; the data lives here, the local app keeps a copy. */
class ClientController extends Controller
{
    public function store(Request $request, CreateDefaultShifts $defaults)
    {
        $data = $request->validate(AdminClientController::rules());
        $client = Client::create(Arr::except($data, 'mobile_api_token'));
        AdminClientController::setMobileToken($client, $data['mobile_api_token'] ?? null);
        $defaults($client);
        return response()->json(['tenant' => TenantController::config($client->fresh())], 201);
    }
    public function update(Request $request, int $id)
    {
        $client = Client::findOrFail($id);
        $data = $request->validate(AdminClientController::rules($client));
        if (blank($data['quinos_password'] ?? null)) unset($data['quinos_password']); // empty keeps the stored one
        $client->update(Arr::except($data, 'mobile_api_token'));
        AdminClientController::setMobileToken($client, $data['mobile_api_token'] ?? null);
        return ['tenant' => TenantController::config($client->fresh())];
    }
    public function generateMobileToken(int $id)
    {
        $client = Client::findOrFail($id);
        $token = $client->issueMobileApiToken();
        return ['token' => $token, 'url' => $client->mobileApiUrl($token), 'mcp_url' => $client->mcpUrl($token), 'tenant' => TenantController::config($client)];
    }
    public function removeMobileToken(int $id)
    {
        $client = Client::findOrFail($id);
        $client->setMobileApiToken(null);
        return ['tenant' => TenantController::config($client)];
    }
}
