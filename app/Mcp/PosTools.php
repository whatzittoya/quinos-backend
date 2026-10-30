<?php

namespace App\Mcp;

use App\Models\Client;
use App\Models\PosTransaction;
use App\Models\SyncDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Read-only MCP tools over one client's POS bills (the `pos` package the local runner uploads).
 * Amounts are numbers in the bill currency (IDR); bills are read one at a time so long ranges stay within memory.
 */
class PosTools
{
    private const MAX_SUMMARY_DAYS = 92;

    private const MAX_LIST_DAYS = 7;

    public function __construct(private readonly Client $client) {}

    /** Tool definitions for tools/list. */
    public static function definitions(): array
    {
        $range = [
            'from' => ['type' => 'string', 'format' => 'date', 'description' => 'First business day, YYYY-MM-DD.'],
            'to' => ['type' => 'string', 'format' => 'date', 'description' => 'Last business day, YYYY-MM-DD (inclusive). Defaults to `from`.'],
            'store' => ['type' => 'string', 'description' => 'Store code from list_stores, e.g. ZALI.ULUWATU. Omit for all stores.'],
        ];
        $readOnly = ['readOnlyHint' => true, 'openWorldHint' => false];

        return [
            [
                'name' => 'list_stores',
                'title' => 'Stores and data coverage',
                'description' => 'Lists the stores (outlets) with data, the first and last business day available, and the number of bills. Call this first to learn which store codes and dates exist.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'sales_summary',
                'title' => 'Sales summary',
                'description' => 'Totals for a date range (max '.self::MAX_SUMMARY_DAYS.' days): bills, pax, subtotal, discount, service charge, tax, rounding, total, average per bill and per pax. '
                    .'Voided bills are excluded from the totals and counted separately. Group by day, weekday, hour, shift, sales_type or payment (payment groups by payment method amounts).',
                'inputSchema' => ['type' => 'object', 'required' => ['from'], 'properties' => $range + [
                    'group_by' => ['type' => 'string', 'enum' => ['none', 'day', 'weekday', 'hour', 'shift', 'sales_type', 'payment', 'store'], 'default' => 'day'],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'item_sales',
                'title' => 'Item sales',
                'description' => 'Quantity sold and net revenue (quantity x unit price - line discount) per item, category or department for a date range (max '.self::MAX_SUMMARY_DAYS.' days). '
                    .'Voided lines and voided bills are excluded. Modifiers (department OTHER, category MODIFIER, price 0) are left out unless include_modifiers is true.',
                'inputSchema' => ['type' => 'object', 'required' => ['from'], 'properties' => $range + [
                    'group_by' => ['type' => 'string', 'enum' => ['item', 'category', 'department'], 'default' => 'item'],
                    'sort' => ['type' => 'string', 'enum' => ['revenue', 'quantity'], 'default' => 'revenue'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 50],
                    'include_modifiers' => ['type' => 'boolean', 'default' => false],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'list_transactions',
                'title' => 'List bills',
                'description' => 'Bills (headers) for a date range of at most '.self::MAX_LIST_DAYS.' days, ordered by close time, paged with limit and offset. '
                    .'Set include_lines to also get item and payment lines. Use get_transaction for one bill.',
                'inputSchema' => ['type' => 'object', 'required' => ['from'], 'properties' => $range + [
                    'include_lines' => ['type' => 'boolean', 'default' => false],
                    'include_voided' => ['type' => 'boolean', 'default' => true],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'get_transaction',
                'title' => 'One bill',
                'description' => 'One bill with all its lines (items, modifiers, voided lines, payments), by invoice number, e.g. INV-000053.',
                'inputSchema' => ['type' => 'object', 'required' => ['invoice_no'], 'properties' => [
                    'invoice_no' => ['type' => 'string'],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'void_report',
                'title' => 'Voids',
                'description' => 'Voided bills and voided lines with their reasons, staff and amounts for a date range (max '.self::MAX_SUMMARY_DAYS.' days).',
                'inputSchema' => ['type' => 'object', 'required' => ['from'], 'properties' => $range],
                'annotations' => $readOnly,
            ],
        ];
    }

    public function call(string $name, array $args): array
    {
        return match ($name) {
            'list_stores' => $this->listStores(),
            'sales_summary' => $this->salesSummary($args),
            'item_sales' => $this->itemSales($args),
            'list_transactions' => $this->listTransactions($args),
            'get_transaction' => $this->getTransaction($args),
            'void_report' => $this->voidReport($args),
            default => throw new InvalidArgumentException("Unknown tool: {$name}"),
        };
    }

    private function listStores(): array
    {
        $stores = $this->query()
            ->selectRaw('store_code, min(date) as first_date, max(date) as last_date, count(*) as bills, count(distinct date) as days')
            ->groupBy('store_code')->orderBy('store_code')->get()
            ->map(fn ($s) => ['store' => $s->store_code, 'first_date' => (string) $s->first_date, 'last_date' => (string) $s->last_date,
                'days_with_bills' => (int) $s->days, 'bills' => (int) $s->bills])->all();

        $synced = SyncDay::where('client_id', $this->client->id)->where('package', 'pos')->where('status', SyncDay::DONE);

        return [
            'client' => $this->client->name,
            'timezone' => $this->client->timezone,
            'stores' => $stores,
            'days_synced' => ['first' => $this->dateString($synced->clone()->min('date')), 'last' => $this->dateString($synced->clone()->max('date')),
                'count' => $synced->clone()->count(), 'last_synced_at' => $synced->clone()->max('synced_at')],
            'note' => $stores === [] ? 'No bills have been uploaded for this client yet.' : null,
        ];
    }

    private function salesSummary(array $args): array
    {
        [$from, $to] = $this->range($args, self::MAX_SUMMARY_DAYS);
        $groupBy = $args['group_by'] ?? 'day';
        $groups = [];
        $voided = ['bills' => 0, 'total' => 0.0];

        foreach ($this->bills($from, $to, $args['store'] ?? null) as $bill) {
            $t = $bill->transaction;
            if (($t['void_transaction'] ?? '0') === '1') {
                $voided['bills']++;
                $voided['total'] += (float) $t['total'];

                continue;
            }

            if ($groupBy === 'payment') {
                foreach ($bill->transaction_lines as $line) {
                    if (filled($line['payment_code'] ?? null)) {
                        $g = &$groups[$line['payment_code']];
                        $g['payments'] = ($g['payments'] ?? 0) + 1;
                        $g['amount'] = ($g['amount'] ?? 0) + (float) $line['amount'];
                        $g['type'] = $line['line_code'];
                        unset($g);
                    }
                }

                continue;
            }

            $key = match ($groupBy) {
                'none' => 'all',
                'day' => (string) $bill->date->toDateString(),
                'weekday' => $bill->date->format('l'),
                'hour' => substr((string) ($t['open_time'] ?? ''), 11, 2).':00',
                'shift' => $t['shift_name'] ?? 'unknown',
                'sales_type' => $t['sales_type'] ?? 'unknown',
                'store' => $t['store_code'] ?? 'unknown',
                default => throw new InvalidArgumentException('group_by must be one of none, day, weekday, hour, shift, sales_type, payment, store.'),
            };
            $g = &$groups[$key];
            foreach (['bills' => 1, 'pax' => (int) $t['pax'], 'subtotal' => (float) $t['subtotal'], 'discount' => (float) $t['discount'],
                'service_charge' => (float) $t['service_charge'], 'tax' => (float) $t['tax1'] + (float) $t['tax2'],
                'rounding' => (float) $t['rounding'], 'total' => (float) $t['total']] as $metric => $value) {
                $g[$metric] = ($g[$metric] ?? 0) + $value;
            }
            unset($g);
        }

        if ($groupBy !== 'payment') {
            foreach ($groups as &$g) {
                $g['avg_per_bill'] = $g['bills'] ? round($g['total'] / $g['bills'], 2) : 0;
                $g['avg_per_pax'] = $g['pax'] ? round($g['total'] / $g['pax'], 2) : null;
            }
            unset($g);
        }
        ksort($groups);

        return ['from' => $from, 'to' => $to, 'store' => $args['store'] ?? 'all', 'group_by' => $groupBy,
            'groups' => $this->rows($groups, $groupBy), 'voided_bills' => $voided];
    }

    private function itemSales(array $args): array
    {
        [$from, $to] = $this->range($args, self::MAX_SUMMARY_DAYS);
        $groupBy = $args['group_by'] ?? 'item';
        $sort = ($args['sort'] ?? 'revenue') === 'quantity' ? 'quantity' : 'revenue';
        $limit = max(1, min(500, (int) ($args['limit'] ?? 50)));
        $modifiers = (bool) ($args['include_modifiers'] ?? false);
        $groups = [];

        foreach ($this->bills($from, $to, $args['store'] ?? null) as $bill) {
            if (($bill->transaction['void_transaction'] ?? '0') === '1') {
                continue;
            }
            foreach ($bill->transaction_lines as $line) {
                if (filled($line['payment_code'] ?? null) || ($line['void_line'] ?? '0') === '1') {
                    continue;
                }
                $isModifier = ($line['department_code'] ?? '') === 'OTHER' && ($line['category_code'] ?? '') === 'MODIFIER';
                if ($isModifier && ! $modifiers) {
                    continue;
                }
                $key = match ($groupBy) {
                    'category' => $line['category_code'] ?? 'unknown',
                    'department' => $line['department_code'] ?? 'unknown',
                    default => ($line['item_code'] ?? '').'|'.($line['description'] ?? ''),
                };
                $g = &$groups[$key];
                $g['quantity'] = ($g['quantity'] ?? 0) + (float) $line['quantity'];
                $g['revenue'] = ($g['revenue'] ?? 0) + (float) $line['quantity'] * (float) $line['unit_price'] - (float) $line['discount'];
                $g['bills'][$bill->id] = true;
                if ($groupBy === 'item') {
                    $g += ['item_code' => $line['item_code'], 'name' => $line['description'], 'category' => $line['category_code'], 'department' => $line['department_code']];
                }
                unset($g);
            }
        }

        $rows = [];
        foreach ($groups as $key => $g) {
            $rows[] = ($groupBy === 'item' ? array_intersect_key($g, array_flip(['item_code', 'name', 'category', 'department'])) : [$groupBy => $key])
                + ['quantity' => round($g['quantity'], 4), 'revenue' => round($g['revenue'], 2), 'bills' => count($g['bills'])];
        }
        usort($rows, fn ($a, $b) => $b[$sort] <=> $a[$sort]);

        return ['from' => $from, 'to' => $to, 'store' => $args['store'] ?? 'all', 'group_by' => $groupBy, 'sort' => $sort,
            'total_rows' => count($rows), 'rows' => array_slice($rows, 0, $limit)];
    }

    private function listTransactions(array $args): array
    {
        [$from, $to] = $this->range($args, self::MAX_LIST_DAYS);
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $query = $this->query()->whereBetween('date', [$from, $to])->orderBy('close_time')->orderBy('quinos_id');
        if (filled($args['store'] ?? null)) {
            $query->where('store_code', strtoupper($args['store']));
        }
        if (! ($args['include_voided'] ?? true)) {
            $query->where('transaction', 'not like', '%"void_transaction":"1"%');
        }
        $total = $query->clone()->count();
        $bills = $query->skip($offset)->take($limit)->get()->map(fn (PosTransaction $b) => ($args['include_lines'] ?? false)
            ? ['Transaction' => $b->transaction, 'TransactionLine' => $b->transaction_lines]
            : $b->transaction)->all();

        return ['from' => $from, 'to' => $to, 'total' => $total, 'offset' => $offset, 'returned' => count($bills),
            'next_offset' => $offset + count($bills) < $total ? $offset + count($bills) : null, 'bills' => $bills];
    }

    private function getTransaction(array $args): array
    {
        $invoice = trim((string) ($args['invoice_no'] ?? ''));
        if ($invoice === '') {
            throw new InvalidArgumentException('invoice_no is required.');
        }
        $bill = $this->query()->where('invoice_no', $invoice)->latest('date')->first();

        return $bill
            ? ['Transaction' => $bill->transaction, 'TransactionLine' => $bill->transaction_lines]
            : ['found' => false, 'message' => "No bill {$invoice} has been uploaded."];
    }

    private function voidReport(array $args): array
    {
        [$from, $to] = $this->range($args, self::MAX_SUMMARY_DAYS);
        $bills = [];
        $lines = [];

        foreach ($this->bills($from, $to, $args['store'] ?? null) as $bill) {
            $t = $bill->transaction;
            if (($t['void_transaction'] ?? '0') === '1') {
                $bills[] = ['date' => $bill->date->toDateString(), 'invoice_no' => $t['transaction_id'], 'sales_type' => $t['sales_type'],
                    'total' => (float) $t['total'], 'reason' => $t['void_reason'], 'cashier' => $t['cashier_code'], 'close_time' => $t['close_time']];

                continue;
            }
            foreach ($bill->transaction_lines as $line) {
                if (($line['void_line'] ?? '0') === '1' && blank($line['payment_code'] ?? null)) {
                    $lines[] = ['date' => $bill->date->toDateString(), 'invoice_no' => $t['transaction_id'], 'item_code' => $line['item_code'],
                        'name' => $line['description'], 'quantity' => (float) $line['quantity'], 'amount' => (float) $line['quantity'] * (float) $line['unit_price'],
                        'reason' => $line['void_reason'], 'staff' => $line['staff_code'], 'time' => $line['order_time']];
                }
            }
        }

        return ['from' => $from, 'to' => $to, 'store' => $args['store'] ?? 'all',
            'voided_bills' => ['count' => count($bills), 'total' => array_sum(array_column($bills, 'total')), 'bills' => $bills],
            'voided_lines' => ['count' => count($lines), 'amount' => array_sum(array_column($lines, 'amount')), 'lines' => $lines]];
    }

    private function query(): Builder
    {
        return PosTransaction::withoutGlobalScope('client')->where('client_id', $this->client->id);
    }

    /** Streams the bills of a range so long ranges do not load everything into memory. */
    private function bills(string $from, string $to, ?string $store): \Generator
    {
        $query = $this->query()->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('close_time');
        if (filled($store)) {
            $query->where('store_code', strtoupper($store));
        }

        yield from $query->cursor();
    }

    /** @return array{0: string, 1: string} */
    private function range(array $args, int $maxDays): array
    {
        $from = $this->date($args['from'] ?? null, 'from');
        $to = isset($args['to']) && $args['to'] !== '' ? $this->date($args['to'], 'to') : $from;
        if ($to < $from) {
            throw new InvalidArgumentException('to must not be before from.');
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > $maxDays) {
            throw new InvalidArgumentException("The range can be at most {$maxDays} days; split it into smaller ranges.");
        }

        return [$from, $to];
    }

    private function date(mixed $value, string $field): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new InvalidArgumentException("{$field} must be a date like 2026-10-25.");
        }

        return $value;
    }

    private function dateString(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }

    private function rows(array $groups, string $groupBy): array
    {
        $rows = [];
        foreach ($groups as $key => $values) {
            $rows[] = ($groupBy === 'none' ? [] : [$groupBy => (string) $key]) + array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $values);
        }

        return $rows;
    }
}
