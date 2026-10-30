<?php

namespace App\Mcp;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\SyncDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Read-only MCP tools over one client's purchasing (the `purchase` package): purchase orders (what was ordered),
 * goods receipts (what arrived) and purchase invoices (what is owed, `balance` = amount still unpaid).
 * Links: goods receipt -> purchase_order_quinos_id and purchase_invoice_quinos_id.
 */
class PurchaseTools
{
    private const MAX_DAYS = 366;

    public function __construct(private readonly Client $client) {}

    public static function definitions(): array
    {
        $range = [
            'from' => ['type' => 'string', 'format' => 'date', 'description' => 'First document date, YYYY-MM-DD.'],
            'to' => ['type' => 'string', 'format' => 'date', 'description' => 'Last document date, YYYY-MM-DD (inclusive). Defaults to `from`.'],
            'supplier' => ['type' => 'string', 'description' => 'Only suppliers whose name contains this text (case-insensitive).'],
        ];
        $readOnly = ['readOnlyHint' => true, 'openWorldHint' => false];

        return [
            [
                'name' => 'purchase_summary',
                'title' => 'Purchase summary',
                'description' => 'Purchase spend for a date range (max '.self::MAX_DAYS.' days): documents, quantity, subtotal, tax and total, grouped by supplier, month, week, category, item or warehouse. '
                    .'basis "received" counts goods receipts (what actually arrived), "ordered" counts purchase orders. Also says which days have purchase data.',
                'inputSchema' => ['type' => 'object', 'required' => ['from'], 'properties' => $range + [
                    'group_by' => ['type' => 'string', 'enum' => ['none', 'supplier', 'month', 'week', 'category', 'item', 'warehouse'], 'default' => 'supplier'],
                    'basis' => ['type' => 'string', 'enum' => ['received', 'ordered'], 'default' => 'received'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'list_purchase_orders',
                'title' => 'Purchase orders',
                'description' => 'Purchase orders in a date range with supplier, status (Open, Partial, Closed), totals, how much was received and linked invoices with their unpaid balance. '
                    .'Set include_lines for the ordered items. Paged with limit and offset.',
                'inputSchema' => ['type' => 'object', 'required' => ['from'], 'properties' => $range + [
                    'status' => ['type' => 'string', 'enum' => ['Open', 'Partial', 'Closed']],
                    'include_lines' => ['type' => 'boolean', 'default' => false],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'get_purchase_order',
                'title' => 'One purchase order',
                'description' => 'One purchase order by number (e.g. PO.10005) with its lines, its goods receipts (and their lines) and the invoices they were billed on.',
                'inputSchema' => ['type' => 'object', 'required' => ['po_no'], 'properties' => ['po_no' => ['type' => 'string']]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'item_purchase_prices',
                'title' => 'Item purchase prices',
                'description' => 'Price history of purchased items from goods receipts: each purchase with date, supplier, quantity and unit price, plus min, max, average and latest price per item. '
                    .'Find items by code or by name text. Useful to spot price changes and compare suppliers.',
                'inputSchema' => ['type' => 'object', 'required' => ['item'], 'properties' => [
                    'item' => ['type' => 'string', 'description' => 'Item code, or text contained in the item name.'],
                    'from' => ['type' => 'string', 'format' => 'date'],
                    'to' => ['type' => 'string', 'format' => 'date'],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'payables',
                'title' => 'Unpaid purchase invoices',
                'description' => 'Purchase invoices that still have a balance to pay, with age in days, grouped by supplier with totals. as_of defaults to today.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'supplier' => $range['supplier'],
                    'as_of' => ['type' => 'string', 'format' => 'date'],
                ]],
                'annotations' => $readOnly,
            ],
        ];
    }

    public function call(string $name, array $args): array
    {
        return match ($name) {
            'purchase_summary' => $this->summary($args),
            'list_purchase_orders' => $this->listOrders($args),
            'get_purchase_order' => $this->getOrder($args),
            'item_purchase_prices' => $this->itemPrices($args),
            'payables' => $this->payables($args),
            default => throw new InvalidArgumentException("Unknown tool: {$name}"),
        };
    }

    private function summary(array $args): array
    {
        [$from, $to] = $this->range($args);
        $groupBy = $args['group_by'] ?? 'supplier';
        $basis = ($args['basis'] ?? 'received') === 'ordered' ? 'ordered' : 'received';
        $limit = max(1, min(500, (int) ($args['limit'] ?? 100)));
        $model = $basis === 'ordered' ? PurchaseOrder::class : GoodsReceipt::class;
        $groups = [];

        $documents = $this->scoped($model)->with('lines')->whereBetween('date', [$from, $to])->orderBy('date');
        $this->supplierFilter($documents, $args['supplier'] ?? null);

        // lazy(), not cursor(): cursor() ignores eager loading.
        foreach ($documents->lazy(200) as $doc) {
            foreach ($doc->lines as $line) {
                $key = match ($groupBy) {
                    'none' => 'all',
                    'supplier' => $doc->contact_name ?? 'unknown',
                    'month' => $doc->date->format('Y-m'),
                    'week' => $doc->date->copy()->startOfWeek()->toDateString(),
                    'category' => $line->category ?? 'unknown',
                    'item' => ($line->code ?? '').'|'.$line->name,
                    'warehouse' => $doc->warehouse_name ?? 'unknown',
                    default => throw new InvalidArgumentException('group_by must be one of none, supplier, month, week, category, item, warehouse.'),
                };
                $g = &$groups[$key];
                $g['documents'][$doc->id] = true;
                $g['lines'] = ($g['lines'] ?? 0) + 1;
                $g['quantity'] = ($g['quantity'] ?? 0) + (float) $line->quantity;
                $g['subtotal'] = ($g['subtotal'] ?? 0) + (float) $line->subtotal;
                $g['tax'] = ($g['tax'] ?? 0) + (float) $line->tax_amount;
                if ($groupBy === 'item') {
                    $g += ['item_code' => $line->code, 'name' => $line->name, 'category' => $line->category, 'uom' => $line->uom];
                }
                unset($g);
            }
        }

        $rows = [];
        foreach ($groups as $key => $g) {
            $label = match ($groupBy) {
                'none' => [],
                'item' => array_intersect_key($g, array_flip(['item_code', 'name', 'category', 'uom'])),
                default => [$groupBy => (string) $key],
            };
            $row = $label + ['documents' => count($g['documents']), 'lines' => $g['lines'], 'subtotal' => round($g['subtotal'], 2),
                'tax' => round($g['tax'], 2), 'total' => round($g['subtotal'] + $g['tax'], 2)];
            if (in_array($groupBy, ['item', 'category'], true)) {
                $row['quantity'] = round($g['quantity'], 4);
            }
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => in_array($groupBy, ['month', 'week'], true) ? strcmp($a[$groupBy], $b[$groupBy]) : $b['total'] <=> $a['total']);

        return ['from' => $from, 'to' => $to, 'basis' => $basis, 'group_by' => $groupBy, 'total_rows' => count($rows),
            'grand_total' => round(array_sum(array_column($rows, 'total')), 2), 'rows' => array_slice($rows, 0, $limit),
            'coverage' => $this->coverage($from, $to)];
    }

    private function listOrders(array $args): array
    {
        [$from, $to] = $this->range($args);
        $limit = max(1, min(200, (int) ($args['limit'] ?? 50)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $query = $this->scoped(PurchaseOrder::class)->with('lines')->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('no');
        $this->supplierFilter($query, $args['supplier'] ?? null);
        if (filled($args['status'] ?? null)) {
            $query->where('status', $args['status']);
        }
        $total = $query->clone()->count();
        $orders = $query->skip($offset)->take($limit)->get();
        $invoices = $this->invoicesFor($orders->pluck('quinos_id')->all());

        $rows = $orders->map(fn (PurchaseOrder $o) => $this->order($o, $invoices[$o->quinos_id] ?? [], (bool) ($args['include_lines'] ?? false)))->all();

        return ['from' => $from, 'to' => $to, 'total' => $total, 'offset' => $offset, 'returned' => count($rows),
            'next_offset' => $offset + count($rows) < $total ? $offset + count($rows) : null, 'purchase_orders' => $rows];
    }

    private function getOrder(array $args): array
    {
        $no = trim((string) ($args['po_no'] ?? ''));
        if ($no === '') {
            throw new InvalidArgumentException('po_no is required.');
        }
        $order = $this->scoped(PurchaseOrder::class)->with('lines')->where('no', $no)->first();
        if (! $order) {
            return ['found' => false, 'message' => "No purchase order {$no} has been synced."];
        }
        $receipts = $this->scoped(GoodsReceipt::class)->with('lines')->where('purchase_order_quinos_id', $order->quinos_id)->orderBy('date')->get();

        return $this->order($order, $this->invoicesFor([$order->quinos_id])[$order->quinos_id] ?? [], true) + [
            'goods_receipts' => $receipts->map(fn (GoodsReceipt $gr) => [
                'no' => $gr->no, 'date' => $gr->date->toDateString(), 'status' => $gr->status, 'total' => (float) $gr->total,
                'invoice_quinos_id' => $gr->purchase_invoice_quinos_id,
                'lines' => $gr->lines->map(fn ($l) => $this->line($l))->all(),
            ])->all(),
        ];
    }

    private function itemPrices(array $args): array
    {
        $search = trim((string) ($args['item'] ?? ''));
        if ($search === '') {
            throw new InvalidArgumentException('item is required: an item code or part of its name.');
        }
        $query = $this->scoped(GoodsReceipt::class)->with(['lines' => fn ($q) => $q->where(fn ($q) => $q->where('code', $search)
            ->orWhereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%']))])->orderBy('date');
        if (filled($args['from'] ?? null)) {
            [$from, $to] = $this->range($args);
            $query->whereBetween('date', [$from, $to]);
        }

        $items = [];
        // lazy(), not cursor(): cursor() would drop the line filter and load every line.
        foreach ($query->lazy(200) as $gr) {
            foreach ($gr->lines as $line) {
                $items[($line->code ?? '').'|'.$line->name][] = ['date' => $gr->date->toDateString(), 'supplier' => $gr->contact_name, 'receipt_no' => $gr->no,
                    'quantity' => (float) $line->quantity, 'uom' => $line->uom, 'unit_price' => (float) $line->unit_price];
            }
        }

        $result = [];
        foreach ($items as $key => $purchases) {
            [$code, $name] = explode('|', $key, 2);
            $prices = array_column($purchases, 'unit_price');
            $quantity = array_sum(array_column($purchases, 'quantity'));
            $result[] = ['item_code' => $code ?: null, 'name' => $name, 'purchases' => count($purchases), 'quantity' => $quantity,
                'min_price' => min($prices), 'max_price' => max($prices), 'latest_price' => end($prices),
                'weighted_avg_price' => $quantity ? round(array_sum(array_map(fn ($p) => $p['quantity'] * $p['unit_price'], $purchases)) / $quantity, 2) : null,
                'suppliers' => array_values(array_unique(array_filter(array_column($purchases, 'supplier')))),
                'history' => array_slice($purchases, -100)];
        }

        return ['item' => $search, 'matches' => count($result), 'items' => array_slice($result, 0, 20),
            'note' => $result === [] ? 'No goods receipt lines match. Try part of the name, or check purchase_summary with group_by item.' : null];
    }

    private function payables(array $args): array
    {
        $asOf = filled($args['as_of'] ?? null) ? $this->date($args['as_of'], 'as_of') : CarbonImmutable::now($this->client->timezone)->toDateString();
        $query = $this->scoped(PurchaseInvoice::class)->where('balance', '>', 0)->where('date', '<=', $asOf)->orderBy('date');
        $this->supplierFilter($query, $args['supplier'] ?? null);

        $invoices = $query->get()->map(fn (PurchaseInvoice $i) => [
            'no' => $i->no, 'ref_no' => $i->ref_no, 'date' => $i->date->toDateString(), 'supplier' => $i->contact_name,
            'total' => (float) $i->total, 'balance' => (float) $i->balance, 'age_days' => (int) $i->date->diffInDays(CarbonImmutable::parse($asOf)),
        ]);
        $bySupplier = $invoices->groupBy('supplier')->map(fn ($rows, $supplier) => ['supplier' => $supplier, 'invoices' => $rows->count(),
            'balance' => round($rows->sum('balance'), 2), 'oldest_days' => $rows->max('age_days')])->sortByDesc('balance')->values();

        return ['as_of' => $asOf, 'invoices_count' => $invoices->count(), 'total_balance' => round($invoices->sum('balance'), 2),
            'by_supplier' => $bySupplier->all(), 'invoices' => $invoices->all()];
    }

    private function order(PurchaseOrder $o, array $invoices, bool $withLines): array
    {
        $ordered = (float) $o->lines->sum('quantity');
        $row = [
            'no' => $o->no, 'pr_no' => $o->pr_no, 'date' => $o->date->toDateString(), 'delivery_date' => $o->delivery_date?->toDateString(),
            'supplier' => $o->contact_name, 'warehouse' => $o->warehouse_name, 'status' => $o->status, 'closed' => (bool) $o->closed,
            'subtotal' => (float) $o->subtotal, 'tax' => (float) $o->tax_amount, 'total' => (float) $o->total,
            'received_ratio' => $ordered > 0 ? round((float) $o->lines->sum('received') / $ordered, 4) : null,
            'invoices' => $invoices, 'unpaid_balance' => round(array_sum(array_column($invoices, 'balance')), 2),
        ];
        if ($withLines) {
            $row['lines'] = $o->lines->map(fn ($l) => $this->line($l) + ['received' => (float) $l->received])->all();
        }

        return $row;
    }

    private function line($l): array
    {
        return ['item_code' => $l->code, 'name' => $l->name, 'category' => $l->category, 'uom' => $l->uom, 'quantity' => (float) $l->quantity,
            'unit_price' => (float) $l->unit_price, 'subtotal' => (float) $l->subtotal, 'tax_code' => $l->tax_code, 'tax' => (float) $l->tax_amount];
    }

    /** @return array<int, list<array>> invoices per purchase order quinos id, through its goods receipts */
    private function invoicesFor(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $receipts = $this->scoped(GoodsReceipt::class)->whereIn('purchase_order_quinos_id', $orderIds)->whereNotNull('purchase_invoice_quinos_id')
            ->get(['purchase_order_quinos_id', 'purchase_invoice_quinos_id']);
        $invoices = $this->scoped(PurchaseInvoice::class)->whereIn('quinos_id', $receipts->pluck('purchase_invoice_quinos_id')->unique())->get()->keyBy('quinos_id');

        $out = [];
        foreach ($receipts->groupBy('purchase_order_quinos_id') as $orderId => $grs) {
            foreach ($grs->pluck('purchase_invoice_quinos_id')->unique() as $invoiceId) {
                if ($i = $invoices[$invoiceId] ?? null) {
                    $out[$orderId][] = ['no' => $i->no, 'date' => $i->date->toDateString(), 'total' => (float) $i->total, 'balance' => (float) $i->balance];
                }
            }
        }

        return $out;
    }

    /** Which days of the range have purchase data, so an empty answer is not mistaken for no purchases. */
    private function coverage(string $from, string $to): array
    {
        $days = SyncDay::where('client_id', $this->client->id)->where('package', 'purchase')->where('status', SyncDay::DONE)->whereBetween('date', [$from, $to]);
        $count = $days->count();
        $total = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;

        return ['days_in_range' => $total, 'days_synced' => $count, 'complete' => $count >= $total];
    }

    private function scoped(string $model): Builder
    {
        return $model::withoutGlobalScope('client')->where('client_id', $this->client->id);
    }

    private function supplierFilter(Builder $query, ?string $supplier): void
    {
        if (filled($supplier)) {
            $query->whereRaw('lower(contact_name) like ?', ['%'.mb_strtolower(trim($supplier)).'%']);
        }
    }

    /** @return array{0: string, 1: string} */
    private function range(array $args): array
    {
        $from = $this->date($args['from'] ?? null, 'from');
        $to = filled($args['to'] ?? null) ? $this->date($args['to'], 'to') : $from;
        if ($to < $from) {
            throw new InvalidArgumentException('to must not be before from.');
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('The range can be at most '.self::MAX_DAYS.' days; split it into smaller ranges.');
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
}
