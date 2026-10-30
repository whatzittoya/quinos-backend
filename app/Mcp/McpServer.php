<?php

namespace App\Mcp;

use App\Models\Client;
use InvalidArgumentException;
use Throwable;

/**
 * A small MCP server (Model Context Protocol, Streamable HTTP transport answered with plain JSON) so an AI
 * assistant can read one client's POS sales and purchasing data, and pull missing days from Quinos. Stateless: every request carries the client's key, no sessions
 * or server-sent events, which suits shared hosting.
 */
class McpServer
{
    /** Newest first; a client asking for one of these gets it back, otherwise the newest. */
    private const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    public function __construct(private readonly Client $client) {}

    /** Handles one JSON-RPC message. Returns the response, or null for notifications and responses (nothing to answer). */
    public function handle(mixed $message): ?array
    {
        if (! is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
            return $this->error(null, -32600, 'Invalid Request');
        }
        if (! isset($message['method'])) {
            return null; // a response to something we never send; ignore
        }
        $id = $message['id'] ?? null;
        $isNotification = ! array_key_exists('id', $message);
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        try {
            $result = match ($message['method']) {
                'initialize' => $this->initialize($params),
                'ping' => new \stdClass,
                'tools/list' => ['tools' => array_merge(PosTools::definitions(), PurchaseTools::definitions(), SyncTools::definitions())],
                'tools/call' => $this->callTool($params),
                default => $isNotification ? null : throw new McpError('Method not found: '.$message['method'], -32601),
            };
        } catch (McpError $e) {
            return $isNotification ? null : $this->error($id, $e->getCode(), $e->getMessage());
        }

        return $isNotification ? null : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'quinos-pos', 'title' => 'Quinos POS sales and purchasing', 'version' => '1.2.0'],
            'instructions' => $this->instructions(),
        ];
    }

    /** Tool failures are tool results with isError, so the model sees the message and can correct its call. */
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        $tools = match (true) {
            ! is_string($name) => null,
            in_array($name, array_column(PosTools::definitions(), 'name'), true) => new PosTools($this->client),
            in_array($name, array_column(PurchaseTools::definitions(), 'name'), true) => new PurchaseTools($this->client),
            in_array($name, array_column(SyncTools::definitions(), 'name'), true) => new SyncTools($this->client),
            default => null,
        };
        if ($tools === null) {
            throw new McpError('Unknown tool: '.(is_string($name) ? $name : '(none)'), -32602);
        }
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $data = $tools->call($name, $arguments);

            return ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)]], 'isError' => false];
        } catch (InvalidArgumentException $e) {
            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        } catch (Throwable $e) {
            report($e);

            return ['content' => [['type' => 'text', 'text' => 'The tool failed on the server. Try a smaller date range or try again later.']], 'isError' => true];
        }
    }

    private function instructions(): string
    {
        return <<<TXT
        POS sales and purchasing data for {$this->client->name}, timezone {$this->client->timezone}.

        Sales:
        - Start with list_stores to see the store codes and which business days have data. Days not synced yet return nothing, which is not the same as no sales.
        - Amounts are in the bill currency (IDR). Per bill: total = subtotal - discount + service_charge + tax1 + tax2 + rounding.
        - Voided bills (void_transaction "1"), FOC bills (void_transaction "2": compliment, wastage, staff meals) and voided lines (void_line "1") are not sales; the summary tools leave them out and void_report lists them.
        - Modifiers (department OTHER, category MODIFIER, price 0) are add-on notes to an item, not separate sales.
        - Prefer sales_summary and item_sales for analysis; use list_transactions / get_transaction for individual bills.

        Purchasing:
        - Purchase orders are what was ordered, goods receipts what actually arrived, purchase invoices what is owed (balance = still unpaid).
        - purchase_summary (basis received or ordered) for spend by supplier, month, category or item; it reports which days are synced.
        - item_purchase_prices for price history and supplier comparison; payables for unpaid invoices; get_purchase_order to follow one order through receipts and invoices.
        - Food cost questions combine both: purchases (received) against sales of the same period.

        Missing days:
        - If the days you need are not synced (list_stores for sales, coverage in purchase_summary), pull them with sync_days (package pos for the sales tools, purchase for the purchase tools), wait about a minute, check sync_status, then query again.
        - Only pull days the question needs; at most 31 days per call.
        TXT;
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
