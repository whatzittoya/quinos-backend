<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mcp\McpServer;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * MCP endpoint for AI assistants: /api/mcp/{key}, or /api/mcp with "Authorization: Bearer {key}".
 * The key is the client's mobile API key, so the assistant sees only that client's data.
 */
class McpController extends Controller
{
    public function __invoke(Request $request, ?string $token = null): Response
    {
        // No server-sent event stream and no sessions: only POST is supported.
        if (! $request->isMethod('post')) {
            return response('', 405, ['Allow' => 'POST']);
        }

        $token ??= $request->bearerToken();
        $client = filled($token) ? Client::where('active', true)->where('mobile_api_token_hash', hash('sha256', $token))->first() : null;
        if ($client === null) {
            return $this->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Invalid or missing API key.']], 401);
        }

        $message = json_decode($request->getContent(), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 400);
        }

        $server = new McpServer($client);
        // Older clients may batch messages in an array.
        $response = is_array($message) && array_is_list($message) && $message !== []
            ? array_values(array_filter(array_map(fn ($m) => $server->handle($m), $message)))
            : $server->handle($message);

        // Notifications and responses get 202 with no body.
        return $response === null || $response === [] ? response('', 202) : $this->json($response);
    }

    private function json(array $body, int $status = 200): Response
    {
        return response(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), $status,
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
