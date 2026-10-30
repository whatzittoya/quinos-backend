<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PosTransaction;
use App\Models\RunnerToken;
use App\Models\SyncDay;
use App\Models\User;
use App\Runner\Payload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '521141a46f6322697b7f7dbe05f8a565ff53b487';

    private function document(int $id, string $invoice, string $closeTime, string $store = 'ZALI.ULUWATU'): array
    {
        $transaction = array_fill_keys(Payload::POS_TRANSACTION, null);
        $transaction = array_merge($transaction, ['id' => (string) $id, 'store_code' => $store, 'transaction_id' => $invoice,
            'date' => '2026-10-01', 'close_time' => $closeTime, 'total' => '141240', 'customer_code' => '', 'void_transaction' => '0']);
        $line = array_merge(array_fill_keys(Payload::POS_LINE, null), ['id' => $id.'000', 'transaction_id' => (string) $id,
            'line_code' => 'CASH', 'payment_code' => 'CASH', 'description' => 'CASH', 'quantity' => '1', 'amount' => '141240', 'remark' => '']);

        return ['quinos_id' => $id, 'date' => '2026-10-01', 'invoice_no' => $invoice, 'store_code' => $store,
            'close_time' => $closeTime, 'transaction' => $transaction, 'transaction_lines' => [$line]];
    }

    private function import(Client $client, array $documents): void
    {
        $admin = User::factory()->admin()->create();
        $secret = Str::random(64);
        RunnerToken::create(['name' => 'runner', 'token_hash' => hash('sha256', $secret), 'created_by' => $admin->id]);
        $id = (string) Str::uuid();
        $headers = ['Authorization' => 'Bearer '.$secret];

        $this->withHeaders($headers)->postJson('/api/runner/v1/imports', [
            'id' => $id, 'client_id' => $client->id, 'package' => 'pos', 'dates' => ['2026-10-01'],
            'source_synced_at' => now()->toIso8601String(), 'chunk_count' => 1,
        ])->assertOk();
        $this->withHeaders($headers)->putJson('/api/runner/v1/imports/'.$id.'/chunks/0', ['tables' => ['pos_transactions' => $documents]])->assertOk();
        $this->withHeaders($headers)->postJson('/api/runner/v1/imports/'.$id.'/commit')->assertOk()->assertJsonPath('status', 'success');
    }

    private function clientWithToken(): Client
    {
        $client = Client::factory()->create();
        $client->forceFill(['mobile_api_token_hash' => hash('sha256', self::TOKEN)])->save();

        return $client;
    }

    public function test_runner_import_is_served_in_the_old_format(): void
    {
        $client = $this->clientWithToken();
        $this->import($client, [
            $this->document(10748700, 'INV-000093', '2026-10-01 23:00:00'),
            $this->document(10748660, 'INV-000053', '2026-10-01 13:00:00'),
            $this->document(10748800, 'INV-000094', '2026-10-01 14:00:00', 'OTHER.OUTLET'),
        ]);

        $response = $this->getJson('/api/cloud2/transactions/getTransactionByDate/'.self::TOKEN.'/zali.uluwatu/2026-10-01')->assertOk();

        $this->assertSame(['INV-000053', 'INV-000093'], array_column(array_column($response->json(), 'Transaction'), 'transaction_id'));
        $first = $response->json()[0];
        $this->assertSame(['Transaction', 'TransactionLine'], array_keys($first));
        $this->assertSame(Payload::POS_TRANSACTION, array_keys($first['Transaction']));
        $this->assertSame(Payload::POS_LINE, array_keys($first['TransactionLine'][0]));
        $this->assertSame('', $first['Transaction']['customer_code']);
        $this->assertNull($first['Transaction']['customer_name']);
        $this->assertSame('10748660', $first['TransactionLine'][0]['transaction_id']);
        $this->assertSame('done', SyncDay::where('client_id', $client->id)->where('package', 'pos')->value('status'));
    }

    public function test_a_new_import_of_a_day_removes_bills_that_are_gone(): void
    {
        $client = $this->clientWithToken();
        $this->import($client, [$this->document(1, 'INV-1', '2026-10-01 10:00:00'), $this->document(2, 'INV-2', '2026-10-01 11:00:00')]);
        $this->import($client, [$this->document(2, 'INV-2', '2026-10-01 11:00:00')]);

        $this->assertSame(['INV-2'], PosTransaction::pluck('invoice_no')->all());
    }

    public function test_documents_in_the_wrong_shape_are_rejected(): void
    {
        $client = $this->clientWithToken();
        $admin = User::factory()->admin()->create();
        $secret = Str::random(64);
        RunnerToken::create(['name' => 'runner', 'token_hash' => hash('sha256', $secret), 'created_by' => $admin->id]);
        $id = (string) Str::uuid();
        $this->withToken($secret)->postJson('/api/runner/v1/imports', [
            'id' => $id, 'client_id' => $client->id, 'package' => 'pos', 'dates' => ['2026-10-01'],
            'source_synced_at' => now()->toIso8601String(), 'chunk_count' => 1,
        ])->assertOk();

        $missingKey = $this->document(1, 'INV-1', '2026-10-01 10:00:00');
        unset($missingKey['transaction']['total']);
        $extraKey = $this->document(2, 'INV-2', '2026-10-01 10:00:00');
        $extraKey['transaction_lines'][0]['foc'] = '1';
        $notString = $this->document(3, 'INV-3', '2026-10-01 10:00:00');
        $notString['transaction']['total'] = 141240;

        foreach ([$missingKey, $extraKey, $notString] as $bad) {
            $this->withToken($secret)->putJson('/api/runner/v1/imports/'.$id.'/chunks/0', ['tables' => ['pos_transactions' => [$bad]]])->assertUnprocessable();
        }
    }

    public function test_wrong_token_or_inactive_client_is_refused_and_unknown_store_is_empty(): void
    {
        $client = $this->clientWithToken();
        $this->import($client, [$this->document(1, 'INV-1', '2026-10-01 10:00:00')]);

        $this->getJson('/api/cloud2/transactions/getTransactionByDate/'.self::TOKEN.'/NOPE.STORE/2026-10-01')->assertOk()->assertExactJson([]);
        $this->getJson('/api/cloud2/transactions/getTransactionByDate/'.str_repeat('a', 40).'/ZALI.ULUWATU/2026-10-01')->assertUnauthorized();

        $client->update(['active' => false]);
        $this->getJson('/api/cloud2/transactions/getTransactionByDate/'.self::TOKEN.'/ZALI.ULUWATU/2026-10-01')->assertUnauthorized();
    }

    public function test_admin_generates_a_key_shown_once_and_can_remove_it(): void
    {
        $admin = User::factory()->admin()->create();
        $client = $this->clientWithToken();
        $this->import($client, [$this->document(1, 'INV-1', '2026-10-01 10:00:00')]);

        $response = $this->actingAs($admin)->postJson('/api/admin/clients/'.$client->id.'/mobile-api-token')->assertOk();
        $token = $response->json('token');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $token);
        $this->assertStringEndsWith("/getTransactionByDate/{$token}/ZALI.ULUWATU/{date}", $response->json('url'));
        $this->getJson("/api/cloud2/transactions/getTransactionByDate/{$token}/ZALI.ULUWATU/2026-10-01")->assertOk()->assertJsonCount(1);
        $this->getJson('/api/cloud2/transactions/getTransactionByDate/'.self::TOKEN.'/ZALI.ULUWATU/2026-10-01')->assertUnauthorized(); // old key replaced

        $this->actingAs($admin)->deleteJson('/api/admin/clients/'.$client->id.'/mobile-api-token')->assertOk()->assertJsonPath('data.has_mobile_api_token', false);
        $this->getJson("/api/cloud2/transactions/getTransactionByDate/{$token}/ZALI.ULUWATU/2026-10-01")->assertUnauthorized();

        $user = User::factory()->forClient($client)->create();
        $this->actingAs($user)->postJson('/api/admin/clients/'.$client->id.'/mobile-api-token')->assertForbidden();
    }

    public function test_runner_creates_and_edits_clients_and_issues_their_key(): void
    {
        $admin = User::factory()->admin()->create();
        $secret = Str::random(64);
        RunnerToken::create(['name' => 'runner', 'token_hash' => hash('sha256', $secret), 'created_by' => $admin->id]);

        $created = $this->withToken($secret)->postJson('/api/runner/v1/tenants', [
            'name' => 'Pescado Sanur', 'quinos_email' => 'pescado@example.com', 'quinos_password' => 'secret', 'timezone' => 'Asia/Makassar',
        ])->assertCreated()->assertJsonPath('tenant.name', 'Pescado Sanur')->assertJsonPath('tenant.has_mobile_api_token', false);
        $id = $created->json('tenant.id');
        $this->assertNotEmpty($created->json('tenant.shifts')); // default shifts, as when an admin creates one

        $this->withToken($secret)->putJson("/api/runner/v1/tenants/{$id}", ['name' => 'Pescado', 'quinos_password' => ''])
            ->assertOk()->assertJsonPath('tenant.name', 'Pescado')->assertJsonPath('tenant.quinos_password', 'secret');

        $key = $this->withToken($secret)->postJson("/api/runner/v1/tenants/{$id}/mobile-api-token")->assertOk()
            ->assertJsonPath('tenant.has_mobile_api_token', true)->json('token');
        $this->assertSame(hash('sha256', $key), Client::find($id)->mobile_api_token_hash);
        $this->getJson("/api/cloud2/transactions/getTransactionByDate/{$key}/PESCADO.SANUR/2026-10-01")->assertOk()->assertExactJson([]);

        $this->withToken($secret)->deleteJson("/api/runner/v1/tenants/{$id}/mobile-api-token")->assertOk()->assertJsonPath('tenant.has_mobile_api_token', false);
        $this->withoutToken()->postJson('/api/runner/v1/tenants', ['name' => 'X'])->assertUnauthorized(); // runner token required
    }

    public function test_admin_sets_the_token_which_is_stored_hashed_and_never_returned(): void
    {
        $admin = User::factory()->admin()->create();
        $client = Client::factory()->create();

        $this->actingAs($admin)->putJson('/api/admin/clients/'.$client->id, ['name' => $client->name, 'mobile_api_token' => self::TOKEN])
            ->assertOk()->assertJsonPath('data.has_mobile_api_token', true)->assertJsonMissingPath('data.mobile_api_token_hash');
        $this->assertSame(hash('sha256', self::TOKEN), $client->fresh()->mobile_api_token_hash);

        // Blank keeps it; another client cannot take the same token.
        $this->actingAs($admin)->putJson('/api/admin/clients/'.$client->id, ['name' => $client->name, 'mobile_api_token' => ''])->assertOk();
        $this->assertSame(hash('sha256', self::TOKEN), $client->fresh()->mobile_api_token_hash);
        $this->actingAs($admin)->postJson('/api/admin/clients', ['name' => 'Other', 'mobile_api_token' => self::TOKEN])
            ->assertUnprocessable()->assertJsonValidationErrors('mobile_api_token');
        $this->assertSame(1, Client::count());
    }
}
