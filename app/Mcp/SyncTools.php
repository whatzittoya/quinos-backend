<?php

namespace App\Mcp;

use App\Models\Client;
use App\Models\SyncDay;
use App\Sync\SyncDispatcher;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Lets the assistant pull missing days from Quinos itself: sync_days starts a background sync on the hosting
 * (the same one as the Sync page), sync_status shows how each day stands.
 */
class SyncTools
{
    private const MAX_DAYS = 31;

    /** pos = the bills the sales tools read, purchase = purchasing, sales = the sales report data. */
    private const PACKAGES = ['pos', 'purchase', 'sales'];

    public function __construct(private readonly Client $client) {}

    public static function definitions(): array
    {
        $range = [
            'from' => ['type' => 'string', 'format' => 'date', 'description' => 'First day, YYYY-MM-DD.'],
            'to' => ['type' => 'string', 'format' => 'date', 'description' => 'Last day, YYYY-MM-DD (inclusive). Defaults to `from`.'],
            'packages' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::PACKAGES], 'uniqueItems' => true,
                'description' => 'pos = POS bills (sales tools), purchase = purchasing (purchase tools), sales = sales report data. Defaults to all.'],
        ];

        return [
            [
                'name' => 'sync_days',
                'title' => 'Pull days from Quinos',
                'description' => 'Pull days from Quinos into this server, for days that are missing or out of date (list_stores and purchase_summary coverage show which). '
                    .'At most '.self::MAX_DAYS.' days per call, up to today. Runs in the background, usually under a minute per 10 days: call sync_status to see when it is done, then query again.',
                'inputSchema' => ['type' => 'object', 'properties' => $range, 'required' => ['from']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true],
            ],
            [
                'name' => 'sync_status',
                'title' => 'Sync status',
                'description' => 'For each day of the range and package: pending (waiting), running, done (with the number of documents and whether the day was still open when synced) or failed (with the error).',
                'inputSchema' => ['type' => 'object', 'properties' => $range, 'required' => ['from']],
                'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
            ],
        ];
    }

    public function call(string $name, array $args): array
    {
        return match ($name) {
            'sync_days' => $this->syncDays($args),
            'sync_status' => $this->status($args),
        };
    }

    private function syncDays(array $args): array
    {
        if (! $this->client->hasQuinosCredentials()) {
            throw new InvalidArgumentException('This client has no Quinos login on the server, so it cannot pull data. Ask an admin to set it up.');
        }
        [$dates, $packages] = $this->request($args);
        if ($dates[count($dates) - 1] > CarbonImmutable::now($this->client->timezone)->toDateString()) {
            throw new InvalidArgumentException('Days after today cannot be synced.');
        }

        $result = app(SyncDispatcher::class)->days($this->client, $dates, false, $packages);

        return [
            'packages' => $packages,
            'queued' => $result['queued'],
            'already_waiting' => $result['skipped'],
            'next' => $result['queued'] === []
                ? 'These days are already being synced; check sync_status.'
                : 'Syncing in the background. Check sync_status in about a minute, then query again.',
        ];
    }

    private function status(array $args): array
    {
        [$dates, $packages] = $this->request($args);
        $known = SyncDay::where('client_id', $this->client->id)
            ->whereIn('package', $packages)
            ->whereBetween('date', [$dates[0], $dates[count($dates) - 1]])
            ->get()
            ->keyBy(fn (SyncDay $d) => $d->package.'|'.$d->date->toDateString());

        $days = [];
        $totals = [];
        foreach ($dates as $date) {
            foreach ($packages as $package) {
                $day = $known[$package.'|'.$date] ?? null;
                $status = $day?->status ?? 'not_synced';
                $totals[$status] = ($totals[$status] ?? 0) + 1;
                $days[] = array_filter([
                    'date' => $date, 'package' => $package, 'status' => $status,
                    'documents' => $day?->status === SyncDay::DONE ? (int) $day->rows : null,
                    'day_was_open' => $day?->status === SyncDay::DONE ? (bool) $day->partial : null,
                    'synced_at' => $day?->synced_at?->toIso8601String(),
                    'error' => $day?->error,
                ], fn ($v) => $v !== null);
            }
        }

        return ['totals' => $totals, 'days' => $days];
    }

    /** @return array{0: list<string>, 1: list<string>} the days of the range and the packages */
    private function request(array $args): array
    {
        $from = $this->date($args['from'] ?? null, 'from');
        $to = filled($args['to'] ?? null) ? $this->date($args['to'], 'to') : $from;
        if ($to < $from) {
            throw new InvalidArgumentException('to must not be before from.');
        }
        $count = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        if ($count > self::MAX_DAYS) {
            throw new InvalidArgumentException('The range can be at most '.self::MAX_DAYS.' days; split it into smaller ranges.');
        }

        $packages = $args['packages'] ?? self::PACKAGES;
        if (! is_array($packages) || $packages === [] || array_diff($packages, self::PACKAGES) !== []) {
            throw new InvalidArgumentException('packages must be a list of: '.implode(', ', self::PACKAGES).'.');
        }

        $dates = [];
        for ($i = 0; $i < $count; $i++) {
            $dates[] = CarbonImmutable::parse($from)->addDays($i)->toDateString();
        }

        return [$dates, array_values(array_intersect(self::PACKAGES, $packages))];
    }

    private function date(mixed $value, string $field): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new InvalidArgumentException("{$field} must be a date like 2026-10-25.");
        }

        return $value;
    }
}
