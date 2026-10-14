<?php
namespace App\Http\Controllers\Api\Runner;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SyncDay;
class SyncDaysController extends Controller
{
    /** Read-only: the days hosted already holds data for, so a local runner can show them as synced. */
    public function __invoke(int $id)
    {
        $client = Client::findOrFail($id);
        $days = SyncDay::where('client_id', $client->id)->where('status', SyncDay::DONE)->orderBy('date')
            ->get(['package', 'date', 'rows', 'partial', 'synced_at'])
            ->map(fn ($d) => [
                'package' => $d->package, 'date' => $d->date->toDateString(), 'rows' => $d->rows,
                'partial' => (bool) $d->partial, 'synced_at' => $d->synced_at?->toIso8601String(),
            ])->values();
        return response()->json(['days' => $days]);
    }
}
