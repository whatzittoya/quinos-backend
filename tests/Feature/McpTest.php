<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PosTransaction;
use App\Runner\Payload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = '521141a46f6322697b7f7dbe05f8a565ff53b487';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::factory()->create(['name' => 'Zali Uluwatu']);
        $this->client->setMobileApiToken(self::KEY);

        // 1 Oct: a lunch dine-in bill (2 items + modifier + voided line, split payment) and a voided bill. 2 Oct: a dinner Gojek bill.
        $this->bill(1, '2026-10-01', 'INV-1', ['sales_type' => 'DINE IN', 'shift_name' => 'Lunch', 'pax' => '2', 'open_time' => '2026-10-01 12:00:00',
            'subtotal' => '160000', 'discount' => '10000', 'service_charge' => '10500', 'tax1' => '16050', 'total' => '176550'], [
            $this->item('F01', 'NASI GORENG', 'FOOD', 'RICE', '2', '60000', '10000'),
            $this->item('B01', 'ICED TEA', 'BEVERAGE', 'TEA', '1', '40000'),
            $this->item('M01', 'EXTRA SPICY', 'OTHER', 'MODIFIER', '1', '0'),
            $this->item('F02', 'PICKLES', 'FOOD', 'SIDE', '-1', '15000') + ['void_line' => '1', 'void_reason' => 'CANCEL ORDER'],
            $this->payment('CASH', 'CASH', '100000'), $this->payment('BNI', 'CARD', '76550'),
        ]);
        $this->bill(2, '2026-10-01', 'INV-2', ['void_transaction' => '1', 'void_reason' => 'WRONG CLOSED', 'total' => '50000', 'subtotal' => '50000'], []);
        $this->bill(4, '2026-10-01', 'INV-4', ['void_transaction' => '2', 'name' => 'STAFF MEAL', 'total' => '40000', 'subtotal' => '40000'], [
            $this->item('B01', 'ICED TEA', 'BEVERAGE', 'TEA', '1', '40000'), $this->payment('COMPLIMENT', 'OTHER', '40000'),
        ]);
        $this->bill(3, '2026-10-02', 'INV-3', ['sales_type' => 'GOJEK', 'shift_name' => 'Dinner', 'pax' => '1', 'open_time' => '2026-10-02 19:00:00',
            'subtotal' => '60000', 'total' => '60000'], [
            $this->item('F01', 'NASI GORENG', 'FOOD', 'RICE', '1', '60000'), $this->payment('GOJEK', 'OTHER', '60000'),
        ]);
    }

    private function bill(int $id, string $date, string $invoice, array $header, array $lines): void
    {
        $transaction = array_merge(array_fill_keys(Payload::POS_TRANSACTION, null), [
            'id' => (string) $id, 'store_code' => 'ZALI.ULUWATU', 'transaction_id' => $invoice, 'date' => $date, 'pax' => '0',
            'close_time' => $date.' 13:00:00', 'void_transaction' => '0', 'discount' => '0', 'service_charge' => '0', 'tax1' => '0', 'tax2' => '0', 'rounding' => '0',
        ], $header);
        PosTransaction::create(['client_id' => $this->client->id, 'quinos_id' => $id, 'date' => $date, 'invoice_no' => $invoice, 'store_code' => 'ZALI.ULUWATU',
            'close_time' => $transaction['close_time'], 'transaction' => $transaction,
            'transaction_lines' => array_map(fn ($l) => array_merge(array_fill_keys(Payload::POS_LINE, null), ['void_line' => '0'], $l), $lines)]);
    }

    private function item(string $code, string $name, string $dept, string $cat, string $qty, string $price, string $discount = '0'): array
    {
        return ['item_code' => $code, 'description' => $name, 'department_code' => $dept, 'category_code' => $cat, 'quantity' => $qty,
            'unit_price' => $price, 'discount' => $discount, 'staff_code' => 'ANA', 'order_time' => '2026-10-01 12:05:00'];
    }

    private function payment(string $code, string $type, string $amount): array
    {
        return ['payment_code' => $code, 'line_code' => $type, 'description' => $code, 'quantity' => '1', 'amount' => $amount];
    }

    private function rpc(string $method, array $params = [], ?string $key = self::KEY): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/mcp/'.$key, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    private function tool(string $name, array $arguments = []): array
    {
        $result = $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments])->assertOk()->json('result');
        $this->assertFalse($result['isError'], $result['content'][0]['text']);

        return json_decode($result['content'][0]['text'], true);
    }

    public function test_handshake_and_tool_list(): void
    {
        $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']])
            ->assertOk()->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'quinos-pos')->assertJsonPath('result.capabilities.tools.listChanged', false);
        $this->rpc('initialize', ['protocolVersion' => '1999-01-01'])->assertJsonPath('result.protocolVersion', '2025-11-25');

        $this->postJson('/api/mcp/'.self::KEY, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202)->assertNoContent(202);
        $this->rpc('ping')->assertOk()->assertJsonPath('result', []);

        $tools = $this->rpc('tools/list')->assertOk()->json('result.tools');
        $this->assertSame(['list_stores', 'sales_summary', 'item_sales', 'list_transactions', 'get_transaction', 'void_report',
            'purchase_summary', 'list_purchase_orders', 'get_purchase_order', 'item_purchase_prices', 'payables'], array_column($tools, 'name'));
        $this->assertTrue($tools[0]['annotations']['readOnlyHint']);
        $this->rpc('nope')->assertJsonPath('error.code', -32601);
    }

    public function test_key_in_header_works_and_bad_keys_and_other_methods_are_refused(): void
    {
        $this->withToken(self::KEY)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertOk();
        $this->withoutToken()->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertUnauthorized();
        $this->rpc('ping', [], str_repeat('a', 40))->assertUnauthorized();
        $this->get('/api/mcp/'.self::KEY)->assertStatus(405)->assertHeader('Allow', 'POST');

        $this->client->update(['active' => false]);
        $this->rpc('ping')->assertUnauthorized();
    }

    public function test_admin_sees_the_mcp_link(): void
    {
        $admin = \App\Models\User::factory()->admin()->create();
        $this->actingAs($admin)->getJson('/api/admin/clients')->assertJsonPath('data.0.mcp_url', url('/api/mcp/'.self::KEY));
    }

    public function test_list_stores_shows_coverage(): void
    {
        $data = $this->tool('list_stores');

        $this->assertSame([['store' => 'ZALI.ULUWATU', 'first_date' => '2026-10-01', 'last_date' => '2026-10-02', 'days_with_bills' => 2, 'bills' => 4]], $data['stores']);
        $this->assertSame('Zali Uluwatu', $data['client']);
    }

    public function test_sales_summary_excludes_voided_bills_and_groups(): void
    {
        $byDay = $this->tool('sales_summary', ['from' => '2026-10-01', 'to' => '2026-10-02']);
        $this->assertSame(['day' => '2026-10-01', 'bills' => 1, 'pax' => 2, 'subtotal' => 160000.0, 'discount' => 10000.0, 'service_charge' => 10500.0,
            'tax' => 16050.0, 'rounding' => 0.0, 'total' => 176550.0, 'avg_per_bill' => 176550.0, 'avg_per_pax' => 88275.0], $byDay['groups'][0]);
        $this->assertSame(['bills' => 1, 'total' => 50000.0], $byDay['voided_bills']);
        $this->assertSame(['bills' => 1, 'total' => 40000.0], $byDay['foc_bills']); // free bills are not revenue

        $byType = $this->tool('sales_summary', ['from' => '2026-10-01', 'to' => '2026-10-02', 'group_by' => 'sales_type']);
        $this->assertSame(['DINE IN', 'GOJEK'], array_column($byType['groups'], 'sales_type'));

        $byPayment = $this->tool('sales_summary', ['from' => '2026-10-01', 'to' => '2026-10-02', 'group_by' => 'payment']);
        $this->assertSame([['payment' => 'BNI', 'payments' => 1, 'amount' => 76550.0, 'type' => 'CARD'], ['payment' => 'CASH', 'payments' => 1, 'amount' => 100000.0, 'type' => 'CASH'],
            ['payment' => 'GOJEK', 'payments' => 1, 'amount' => 60000.0, 'type' => 'OTHER']], $byPayment['groups']);
    }

    public function test_item_sales_leaves_out_voids_and_modifiers(): void
    {
        $items = $this->tool('item_sales', ['from' => '2026-10-01', 'to' => '2026-10-02']);
        $this->assertSame([
            ['item_code' => 'F01', 'name' => 'NASI GORENG', 'category' => 'RICE', 'department' => 'FOOD', 'quantity' => 3.0, 'revenue' => 170000.0, 'bills' => 2],
            ['item_code' => 'B01', 'name' => 'ICED TEA', 'category' => 'TEA', 'department' => 'BEVERAGE', 'quantity' => 1.0, 'revenue' => 40000.0, 'bills' => 1],
        ], $items['rows']);

        $departments = $this->tool('item_sales', ['from' => '2026-10-01', 'to' => '2026-10-02', 'group_by' => 'department', 'include_modifiers' => true]);
        $this->assertSame(['FOOD', 'BEVERAGE', 'OTHER'], array_column($departments['rows'], 'department'));
    }

    public function test_bills_and_voids(): void
    {
        $list = $this->tool('list_transactions', ['from' => '2026-10-01', 'limit' => 1]);
        $this->assertSame([3, 1, 1], [$list['total'], $list['returned'], $list['next_offset']]); // INV-1, the voided INV-2 and the FOC INV-4
        $this->assertSame(Payload::POS_TRANSACTION, array_keys($list['bills'][0]));
        $this->assertSame(1, $this->tool('list_transactions', ['from' => '2026-10-01', 'include_voided' => false])['total']); // no void, no FOC

        $bill = $this->tool('get_transaction', ['invoice_no' => 'INV-1']);
        $this->assertCount(6, $bill['TransactionLine']);
        $this->assertFalse($this->tool('get_transaction', ['invoice_no' => 'INV-404'])['found']);

        $voids = $this->tool('void_report', ['from' => '2026-10-01', 'to' => '2026-10-02']);
        $this->assertSame('WRONG CLOSED', $voids['voided_bills']['bills'][0]['reason']);
        $this->assertSame([1, 40000.0, ['COMPLIMENT']], [$voids['foc_bills']['count'], $voids['foc_bills']['total'], $voids['foc_bills']['bills'][0]['paid_with']]);
        $this->assertSame(['PICKLES', 'CANCEL ORDER', -15000.0], [$voids['voided_lines']['lines'][0]['name'], $voids['voided_lines']['lines'][0]['reason'], $voids['voided_lines']['amount']]);
    }

    public function test_bad_arguments_come_back_as_tool_errors_and_other_clients_data_is_invisible(): void
    {
        $result = $this->rpc('tools/call', ['name' => 'sales_summary', 'arguments' => ['from' => '2026-01-01', 'to' => '2026-12-31']])->json('result');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('at most 92 days', $result['content'][0]['text']);
        $this->rpc('tools/call', ['name' => 'drop_tables'])->assertJsonPath('error.code', -32602);

        $other = Client::factory()->create();
        $other->setMobileApiToken(str_repeat('c', 40));
        $response = $this->rpc('tools/call', ['name' => 'list_stores'], str_repeat('c', 40))->json('result.content.0.text');
        $this->assertSame([], json_decode($response, true)['stores']);
    }
}
