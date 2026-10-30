<?php

namespace App\Mcp;

use App\Models\Client;
use InvalidArgumentException;
use Throwable;

/**
 * A small MCP server (Model Context Protocol, Streamable HTTP transport answered with plain JSON) so an AI
 * assistant can read one client's POS data. Stateless: every request carries the client's key, no sessions
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
                'tools/list' => ['tools' => PosTools::definitions()],
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
            'serverInfo' => ['name' => 'quinos-pos', 'title' => 'Quinos POS data', 'version' => '1.0.0'],
            'instructions' => $this->instructions(),
        ];
    }

    /** Tool failures are tool results with isError, so the model sees the message and can correct its call. */
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        if (! is_string($name) || ! in_array($name, array_column(PosTools::definitions(), 'name'), true)) {
            throw new McpError('Unknown tool: '.(is_string($name) ? $name : '(none)'), -32602);
        }
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $data = (new PosTools($this->client))->call($name, $arguments);

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
        POS (point of sale) data for {$this->client->name}, timezone {$this->client->timezone}, read-only.
        - Start with list_stores to see the store codes and which business days have data. Days not synced yet return nothing, which is not the same as no sales.
        - Amounts are in the bill currency (IDR). Per bill: total = subtotal - discount + service_charge + tax1 + tax2 + rounding.
        - Voided bills (void_transaction "1") and voided lines (void_line "1") are not sales; the summary tools leave them out and void_report lists them.
        - Modifiers (department OTHER, category MODIFIER, price 0) are add-on notes to an item, not separate sales.
        - Prefer sales_summary and item_sales for analysis; use list_transactions / get_transaction for individual bills.
        TXT;
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
