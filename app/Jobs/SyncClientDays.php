<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\SyncDay;
use App\Sync\SyncRunner;
use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncClientDays implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A long first sync is fine; the worker is started with a matching timeout. */
    public int $timeout = 3600;

    /**
     * @param  list<string>  $dates  empty = only refresh master data
     */
    public function __construct(
        public readonly int $clientId,
        public readonly array $dates,
        public readonly bool $force = false,
        public readonly ?array $masterKinds = null,
        public readonly ?array $packages = null,
    ) {}

    /** One sync at a time per client: they share one Quinos session. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('quinos-sync-'.$this->clientId))->releaseAfter(30)->expireAfter(3900)];
    }

    // Waiting for the lock releases the job back to the queue, so allow that for a while.
    public function retryUntil(): DateTime
    {
        return now()->addHours(6)->toDateTime();
    }

    public function handle(SyncRunner $runner): void
    {
        $client = Client::find($this->clientId);

        if ($client === null || ! $client->active || ! $client->hasQuinosCredentials()) {
            $this->markFailed('This client is disabled or has no Quinos login.');

            return;
        }

        try {
            $runner->run($client, $this->dates, $this->force, $this->masterKinds, $this->packages);
        } catch (Throwable $e) {
            $this->fail($e);
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);

        $this->markFailed('The sync stopped unexpectedly. Try again.');
    }

    private function markFailed(string $message): void
    {
        SyncDay::where('client_id', $this->clientId)
            ->whereIn('date', $this->dates)
            ->when($this->packages !== null, fn ($query) => $query->whereIn('package', $this->packages))
            ->whereIn('status', [SyncDay::PENDING, SyncDay::RUNNING])
            ->update(['status' => SyncDay::FAILED, 'error' => $message, 'updated_at' => now()]);
    }
}
