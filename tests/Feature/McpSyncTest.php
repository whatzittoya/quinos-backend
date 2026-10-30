<?php

namespace Tests\Feature;

use App\Jobs\SyncClientDays;
use App\Models\Client;
use App\Models\SyncDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class McpSyncTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'syncsyncsyncsyncsyncsyncsyncsyncsyncsync';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-30 10:00:00');
        Queue::fake();
        $this->client = Client::factory()->create(['timezone' => 'Asia/Makassar']);
        $this->client->setMobileApiToken(self::KEY);
    }

    private function rpc(string $name, array $arguments): array
    {
        return $this->postJson('/api/mcp/'.self::KEY, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments]])->assertOk()->json('result');
    }

    private function tool(string $name, array $arguments): array
    {
        $result = $this->rpc($name, $arguments);
        $this->assertFalse($result['isError'], $result['content'][0]['text']);

        return json_decode($result['content'][0]['text'], true);
    }

    public function test_sync_tools_are_listed(): void
    {
        $names = array_column($this->postJson('/api/mcp/'.self::KEY, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'), 'name');

        $this->assertContains('sync_days', $names);
        $this->assertContains('sync_status', $names);
    }

    public function test_sync_days_queues_pos_and_purchase_for_the_range(): void
    {
        $result = $this->tool('sync_days', ['from' => '2026-10-01', 'to' => '2026-10-03', 'packages' => ['purchase', 'pos']]);

        $this->assertSame(['pos', 'purchase'], $result['packages']);
        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03'], $result['queued']);
        Queue::assertPushed(SyncClientDays::class, fn ($job) => $job->clientId === $this->client->id
            && $job->dates === ['2026-10-01', '2026-10-02', '2026-10-03'] && $job->packages === ['purchase', 'pos']);
        $this->assertSame(6, SyncDay::where('client_id', $this->client->id)->where('status', SyncDay::PENDING)->count());

        // Asking again while they wait does not queue them twice.
        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03'], $this->tool('sync_days', ['from' => '2026-10-01', 'to' => '2026-10-03', 'packages' => ['pos', 'purchase']])['already_waiting']);
        Queue::assertPushed(SyncClientDays::class, 1);
    }

    public function test_sync_days_refuses_bad_requests(): void
    {
        foreach ([
            ['from' => '2026-09-01', 'to' => '2026-10-15'],          // more than 31 days
            ['from' => '2026-10-30', 'to' => '2026-10-31'],          // tomorrow in Bali
            ['from' => '2026-10-01', 'packages' => ['payroll']],
        ] as $args) {
            $this->assertTrue($this->rpc('sync_days', $args)['isError'], json_encode($args));
        }
        Queue::assertNothingPushed();

        $this->client->forceFill(['quinos_password' => null])->save();
        $this->assertStringContainsString('no Quinos login', $this->rpc('sync_days', ['from' => '2026-10-01'])['content'][0]['text']);
    }

    public function test_sync_status_reports_each_day_and_package(): void
    {
        SyncDay::create(['client_id' => $this->client->id, 'package' => 'pos', 'date' => '2026-10-01', 'status' => SyncDay::DONE, 'rows' => 40, 'partial' => false, 'synced_at' => now()]);
        SyncDay::create(['client_id' => $this->client->id, 'package' => 'pos', 'date' => '2026-10-02', 'status' => SyncDay::FAILED, 'error' => 'Quinos login failed.']);

        $status = $this->tool('sync_status', ['from' => '2026-10-01', 'to' => '2026-10-03', 'packages' => ['pos']]);

        $this->assertSame(['done' => 1, 'failed' => 1, 'not_synced' => 1], $status['totals']);
        $this->assertSame([40, false], [$status['days'][0]['documents'], $status['days'][0]['day_was_open']]);
        $this->assertSame('Quinos login failed.', $status['days'][1]['error']);
        $this->assertSame(['date' => '2026-10-03', 'package' => 'pos', 'status' => 'not_synced'], $status['days'][2]);
    }
}
