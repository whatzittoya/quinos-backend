<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\SyncDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = '521141a46f6322697b7f7dbe05f8a565ff53b487';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::factory()->create(['timezone' => 'Asia/Makassar']);
        $this->client->setMobileApiToken(self::KEY);

        // PO.1 (PASAR, 2 lines) received on 2 receipts billed on 1 unpaid invoice; PO.2 (FISHMONGER) received, invoice paid.
        $po1 = $this->doc(PurchaseOrder::class, 1, 'PO.1', '2026-10-01', 'PASAR', 'Partial', ['pr_no' => null]);
        $this->lines($po1, [['RICE', 'Rice 5kg', 'DRY', 10, 70000, 6], ['OIL', 'Cooking oil', 'DRY', 5, 30000, 5]]);
        $po2 = $this->doc(PurchaseOrder::class, 2, 'PO.2', '2026-10-08', 'Fishmonger Bali', 'Closed', ['closed' => true]);
        $this->lines($po2, [['FISH', 'Snapper', 'SEAFOOD', 4, 120000, 4]]);

        $gr1 = $this->doc(GoodsReceipt::class, 11, 'GR.1', '2026-10-02', 'PASAR', 'Closed', ['purchase_order_quinos_id' => 1, 'purchase_invoice_quinos_id' => 21, 'po_no' => 'PO.1']);
        $this->lines($gr1, [['RICE', 'Rice 5kg', 'DRY', 6, 70000], ['OIL', 'Cooking oil', 'DRY', 5, 30000]]);
        $gr2 = $this->doc(GoodsReceipt::class, 12, 'GR.2', '2026-10-09', 'PASAR', 'Closed', ['purchase_order_quinos_id' => 1, 'purchase_invoice_quinos_id' => 21, 'po_no' => 'PO.1']);
        $this->lines($gr2, [['RICE', 'Rice 5kg', 'DRY', 2, 75000]]);
        $gr3 = $this->doc(GoodsReceipt::class, 13, 'GR.3', '2026-10-09', 'Fishmonger Bali', 'Closed', ['purchase_order_quinos_id' => 2, 'purchase_invoice_quinos_id' => 22, 'po_no' => 'PO.2']);
        $this->lines($gr3, [['FISH', 'Snapper', 'SEAFOOD', 4, 120000]]);

        $this->doc(PurchaseInvoice::class, 21, 'PI.1', '2026-10-09', 'PASAR', 'Open', ['balance' => 720000, 'receipt_ids' => [11, 12]], 720000);
        $this->doc(PurchaseInvoice::class, 22, 'PI.2', '2026-10-09', 'Fishmonger Bali', 'Paid', ['balance' => 0, 'receipt_ids' => [13]], 480000);

        foreach (['2026-10-01', '2026-10-02'] as $day) {
            SyncDay::create(['client_id' => $this->client->id, 'package' => 'purchase', 'date' => $day, 'status' => SyncDay::DONE]);
        }
    }

    private function doc(string $model, int $id, string $no, string $date, string $supplier, string $status, array $extra = [], float $total = 0)
    {
        return $model::create(['client_id' => $this->client->id, 'quinos_id' => $id, 'no' => $no, 'date' => $date, 'contact_name' => $supplier,
            'warehouse_name' => 'Main', 'status' => $status, 'closed' => false, 'subtotal' => $total, 'tax_amount' => 0, 'total' => $total] + $extra);
    }

    private function lines($doc, array $lines): void
    {
        foreach ($lines as $line) {
            [$code, $name, $category, $qty, $price] = $line;
            $doc->lines()->create(['client_id' => $this->client->id, 'code' => $code, 'name' => $name, 'category' => $category, 'uom' => 'pcs',
                'quantity' => $qty, 'unit_price' => $price, 'subtotal' => $qty * $price, 'tax_rate' => 0, 'tax_amount' => 0]
                + ($doc instanceof PurchaseOrder ? ['received' => $line[5] ?? 0] : []));
        }
    }

    private function tool(string $name, array $arguments = []): array
    {
        $result = $this->postJson('/api/mcp/'.self::KEY, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments]])->assertOk()->json('result');
        $this->assertFalse($result['isError'], $result['content'][0]['text']);

        return json_decode($result['content'][0]['text'], true);
    }

    public function test_purchase_summary_by_supplier_received_and_ordered(): void
    {
        $received = $this->tool('purchase_summary', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertSame([
            ['supplier' => 'PASAR', 'documents' => 2, 'lines' => 3, 'subtotal' => 720000.0, 'tax' => 0.0, 'total' => 720000.0],
            ['supplier' => 'Fishmonger Bali', 'documents' => 1, 'lines' => 1, 'subtotal' => 480000.0, 'tax' => 0.0, 'total' => 480000.0],
        ], $received['rows']);
        $this->assertSame(1200000.0, $received['grand_total']);
        $this->assertSame(['days_in_range' => 31, 'days_synced' => 2, 'complete' => false], $received['coverage']);

        $ordered = $this->tool('purchase_summary', ['from' => '2026-10-01', 'to' => '2026-10-31', 'basis' => 'ordered', 'group_by' => 'item']);
        $this->assertSame(['RICE', 'FISH', 'OIL'], array_column($ordered['rows'], 'item_code')); // by ordered value: 700k, 480k, 150k
        $this->assertSame(10.0, $ordered['rows'][0]['quantity']);

        $weeks = $this->tool('purchase_summary', ['from' => '2026-10-01', 'to' => '2026-10-31', 'group_by' => 'week']);
        $this->assertSame(['2026-09-28', '2026-10-05'], array_column($weeks['rows'], 'week'));

        $this->assertSame(['PASAR'], array_column($this->tool('purchase_summary', ['from' => '2026-10-01', 'to' => '2026-10-31', 'supplier' => 'pas'])['rows'], 'supplier'));
    }

    public function test_purchase_orders_with_receipts_and_invoices(): void
    {
        $list = $this->tool('list_purchase_orders', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertSame(['PO.1', 'PO.2'], array_column($list['purchase_orders'], 'no'));
        $this->assertSame([0.7333, 720000.0], [$list['purchase_orders'][0]['received_ratio'], $list['purchase_orders'][0]['unpaid_balance']]);
        $this->assertSame(['PO.2'], array_column($this->tool('list_purchase_orders', ['from' => '2026-10-01', 'to' => '2026-10-31', 'status' => 'Closed'])['purchase_orders'], 'no'));

        $po = $this->tool('get_purchase_order', ['po_no' => 'PO.1']);
        $this->assertSame(['GR.1', 'GR.2'], array_column($po['goods_receipts'], 'no'));
        $this->assertSame([['no' => 'PI.1', 'date' => '2026-10-09', 'total' => 720000.0, 'balance' => 720000.0]], $po['invoices']);
        $this->assertCount(2, $po['lines']);
        $this->assertFalse($this->tool('get_purchase_order', ['po_no' => 'PO.404'])['found']);
    }

    public function test_item_prices_and_payables(): void
    {
        $rice = $this->tool('item_purchase_prices', ['item' => 'rice'])['items'][0];
        $this->assertSame(['RICE', 2, 8.0, 70000.0, 75000.0, 75000.0, 71250.0, ['PASAR']], [$rice['item_code'], $rice['purchases'], $rice['quantity'],
            $rice['min_price'], $rice['max_price'], $rice['latest_price'], $rice['weighted_avg_price'], $rice['suppliers']]);
        $this->assertSame(0, $this->tool('item_purchase_prices', ['item' => 'caviar'])['matches']);

        $payables = $this->tool('payables', ['as_of' => '2026-10-19']);
        $this->assertSame(720000.0, $payables['total_balance']);
        $this->assertSame([['supplier' => 'PASAR', 'invoices' => 1, 'balance' => 720000.0, 'oldest_days' => 10]], $payables['by_supplier']);
    }

    public function test_other_clients_purchases_are_invisible(): void
    {
        $other = Client::factory()->create();
        $other->setMobileApiToken(str_repeat('c', 40));
        $result = $this->postJson('/api/mcp/'.str_repeat('c', 40), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'purchase_summary', 'arguments' => ['from' => '2026-10-01', 'to' => '2026-10-31']]])->json('result.content.0.text');

        $this->assertSame([], json_decode($result, true)['rows']);
    }
}
