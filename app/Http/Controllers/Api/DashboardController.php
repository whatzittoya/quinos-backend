<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\UsesCurrentClient;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Item;
use App\Models\SalesType;
use App\Models\SyncDay;
use Carbon\CarbonImmutable;

/** What the dashboard shows: how current each report's data is, and the size of each master list. */
class DashboardController extends Controller
{
    use UsesCurrentClient;

    private const REPORTS = ['purchase' => 'Purchase report', 'sales' => 'Sales report'];

    /** route kind => [label, model] (same kinds as the Master pages) */
    private const MASTER = [
        'items' => ['Items', Item::class],
        'departments' => ['Departments', Department::class],
        'categories' => ['Categories', Category::class],
        'sales-types' => ['Sales types', SalesType::class],
        'contacts' => ['Contacts', Contact::class],
    ];

    public function __invoke(): array
    {
        $client = $this->currentClient();
        $today = CarbonImmutable::now($client->timezone)->startOfDay();

        $reports = [];

        foreach (self::REPORTS as $package => $label) {
            $done = SyncDay::where('package', $package)->where('status', SyncDay::DONE)->where('date', '<=', $today->toDateString());
            $latest = (clone $done)->max('date');
            $pulled = (clone $done)->max('synced_at');

            $reports[] = [
                'key' => $package,
                'label' => $label,
                'latest_date' => $latest ? CarbonImmutable::parse($latest)->toDateString() : null,
                // 0 = up to today, N = the newest synced day is N days before today
                'days_behind' => $latest ? (int) CarbonImmutable::parse($latest, $client->timezone)->startOfDay()->diffInDays($today) : null,
                'last_synced_at' => $pulled ? CarbonImmutable::parse($pulled)->toIso8601String() : null,
            ];
        }

        $master = [];

        foreach (self::MASTER as $kind => [$label, $model]) {
            $latest = $model::query()->max('updated_at');

            $master[] = [
                'key' => $kind,
                'label' => $label,
                'count' => $model::query()->count(),
                'last_synced_at' => $latest ? CarbonImmutable::parse($latest)->toIso8601String() : null,
            ];
        }

        return ['today' => $today->toDateString(), 'reports' => $reports, 'master' => $master];
    }
}
