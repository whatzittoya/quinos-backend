<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\CreateDefaultShifts;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index()
    {
        return ClientResource::collection(Client::orderBy('name')->get());
    }

    public function store(Request $request, CreateDefaultShifts $defaults): JsonResponse
    {
        $data = $this->validated($request);
        $client = Client::create(Arr::except($data, 'mobile_api_token'));
        self::setMobileToken($client, $data['mobile_api_token'] ?? null);
        $defaults($client);

        return (new ClientResource($client))->response()->setStatusCode(201);
    }

    public function show(Client $client): ClientResource
    {
        return new ClientResource($client);
    }

    public function update(Request $request, Client $client): ClientResource
    {
        $data = $this->validated($request, updating: true, client: $client);

        // An empty password field means "keep the stored one".
        if (blank($data['quinos_password'] ?? null)) {
            unset($data['quinos_password']);
        }

        $client->update(Arr::except($data, 'mobile_api_token'));
        self::setMobileToken($client, $data['mobile_api_token'] ?? null);

        return new ClientResource($client);
    }

    public function destroy(Client $client): JsonResponse
    {
        $client->delete();

        return response()->json(['ok' => true]);
    }

    /** Makes a new mobile API key (replacing any old one) and returns it once, with the app URL. */
    public function generateMobileToken(Client $client): JsonResponse
    {
        $token = $client->issueMobileApiToken();

        return response()->json(['token' => $token, 'url' => $client->mobileApiUrl($token), 'data' => new ClientResource($client)]);
    }

    public function removeMobileToken(Client $client): ClientResource
    {
        $client->forceFill(['mobile_api_token_hash' => null])->save();

        return new ClientResource($client);
    }

    public static function setMobileToken(Client $client, ?string $token): void
    {
        if (filled($token)) {
            $client->forceFill(['mobile_api_token_hash' => hash('sha256', $token)])->save();
        }
    }

    private function validated(Request $request, bool $updating = false, ?Client $client = null): array
    {
        return $request->validate(self::rules($client));
    }

    /** Also used by the runner API, which lets the local app create and edit clients. */
    public static function rules(?Client $client = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'quinos_email' => ['nullable', 'email', 'max:255'],
            'quinos_password' => ['nullable', 'string', 'max:255'],
            'quinos_company_id' => ['nullable', 'string', 'max:255'],
            // Blank keeps the stored token. Letters and digits only: it is part of the mobile API URL.
            'mobile_api_token' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{20,128}$/', function ($attribute, $value, $fail) use ($client) {
                if (Client::where('mobile_api_token_hash', hash('sha256', $value))->when($client, fn ($q) => $q->whereKeyNot($client->id))->exists()) {
                    $fail('Another client already uses this mobile API token.');
                }
            }],
            'timezone' => ['sometimes', 'timezone:all'],
            'shift_assign_by' => ['sometimes', Rule::in(['open_time', 'close_time'])],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
