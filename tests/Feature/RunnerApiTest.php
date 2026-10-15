<?php
namespace Tests\Feature;
use App\Models\Client;
use App\Models\RunnerToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
class RunnerApiTest extends TestCase
{
    use RefreshDatabase;
    public function test_admin_can_create_and_revoke_a_runner_token(): void
    {
        $admin = User::factory()->admin()->create();
        $create = $this->actingAs($admin)->postJson('/api/admin/runner-tokens', ['name' => 'Windows'])->assertCreated();
        $secret = $create->json('secret');
        $id = $create->json('data.id');
        $this->assertSame(64, strlen($secret));
        $this->assertNotSame($secret, RunnerToken::find($id)->token_hash);
        $this->withHeader('Authorization', 'Bearer '.$secret)->getJson('/api/runner/v1/tenants')->assertOk();
        $this->actingAs($admin)->postJson('/api/admin/runner-tokens/'.$id.'/revoke')->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$secret)->getJson('/api/runner/v1/tenants')->assertUnauthorized();
    }

    public function test_master_import_is_transactional_and_idempotent(): void
    {
        $admin = User::factory()->admin()->create();
        $client = Client::factory()->create();
        $secret = Str::random(64);
        RunnerToken::create(['name' => 'runner', 'token_hash' => hash('sha256', $secret), 'created_by' => $admin->id]);
        $id = (string) Str::uuid();
        $headers = ['Authorization' => 'Bearer '.$secret];
        $this->withHeaders($headers)->postJson('/api/runner/v1/imports', [
            'id' => $id, 'client_id' => $client->id, 'package' => 'master', 'dates' => [],
            'source_synced_at' => now()->toIso8601String(), 'chunk_count' => 1,
        ])->assertOk();
        $this->withHeaders($headers)->putJson('/api/runner/v1/imports/'.$id.'/chunks/0', ['tables' => [
            'departments' => [['quinos_id' => 10, 'name' => 'FOOD']],
        ]])->assertOk();
        $this->withHeaders($headers)->postJson('/api/runner/v1/imports/'.$id.'/commit')->assertOk()->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('departments', ['client_id' => $client->id, 'quinos_id' => 10]);
        $this->withHeaders($headers)->postJson('/api/runner/v1/imports/'.$id.'/commit')->assertOk()->assertJsonPath('already_applied', true);
    }

    public function test_runner_tenant_config_contains_credentials_only_for_runner_token(): void
    {
        $admin = User::factory()->admin()->create();
        $client = Client::factory()->create(['quinos_email' => 'q@example.com', 'quinos_password' => 'secret']);
        $secret = Str::random(64);
        RunnerToken::create(['name' => 'runner', 'token_hash' => hash('sha256', $secret), 'created_by' => $admin->id]);
        $this->actingAs($admin)->getJson('/api/admin/clients')->assertJsonMissingPath('data.0.quinos_password');
        $this->withHeader('Authorization', 'Bearer '.$secret)->getJson('/api/runner/v1/tenants')
            ->assertOk()->assertJsonPath('tenants.0.id', $client->id)->assertJsonPath('tenants.0.quinos_email', 'q@example.com')->assertJsonPath('tenants.0.quinos_password', 'secret');
    }

    public function test_runner_can_read_which_days_hosted_has_synced(): void
    {
        $admin = User::factory()->admin()->create();
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        $secret = Str::random(64);
        RunnerToken::create(['name' => 'runner', 'token_hash' => hash('sha256', $secret), 'created_by' => $admin->id]);
        \App\Models\SyncDay::create(['client_id' => $client->id, 'package' => 'sales', 'date' => '2026-09-29', 'status' => 'done', 'rows' => 12, 'synced_at' => now()]);
        \App\Models\SyncDay::create(['client_id' => $client->id, 'package' => 'sales', 'date' => '2026-09-30', 'status' => 'failed']);
        \App\Models\SyncDay::create(['client_id' => $other->id, 'package' => 'sales', 'date' => '2026-09-28', 'status' => 'done']);
        $this->getJson('/api/runner/v1/tenants/'.$client->id.'/sync-days')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$secret)->getJson('/api/runner/v1/tenants/'.$client->id.'/sync-days')
            ->assertOk()->assertJsonCount(1, 'days')->assertJsonPath('days.0.date', '2026-09-29')->assertJsonPath('days.0.rows', 12);
    }

    public function test_import_commits_when_the_database_returns_numbers_as_strings(): void
    {
        // Production MySQL hands integers back as strings; commit compared the chunk count with ===.
        $admin = User::factory()->admin()->create();
        $client = Client::factory()->create();
        $secret = Str::random(64);
        RunnerToken::create(['name' => 'runner', 'token_hash' => hash('sha256', $secret), 'created_by' => $admin->id]);
        $id = (string) Str::uuid();
        $headers = ['Authorization' => 'Bearer '.$secret];
        $date = now($client->timezone)->toDateString();
        $this->withHeaders($headers)->postJson('/api/runner/v1/imports', [
            'id' => $id, 'client_id' => $client->id, 'package' => 'sales', 'dates' => [$date],
            'source_synced_at' => now()->subMinute()->toIso8601String(), 'chunk_count' => 1,
        ])->assertOk();
        $this->withHeaders($headers)->putJson('/api/runner/v1/imports/'.$id.'/chunks/0', ['tables' => ['sales_transactions' => []]])->assertOk();

        $import = \App\Models\RunnerImport::findOrFail($id);
        $import->setRawAttributes(array_merge($import->getAttributes(), ['chunk_count' => '1']), true);
        $this->assertSame(1, $import->chunk_count);

        \Illuminate\Support\Facades\DB::table('runner_imports')->where('id', $id)->update(['chunk_count' => 1]);
        $this->withHeaders($headers)->postJson('/api/runner/v1/imports/'.$id.'/commit')->assertOk()->assertJsonPath('status', 'success');
    }
}
