<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PosTransaction;
use App\Runner\Payload;
use Illuminate\Http\JsonResponse;

/**
 * The mobile app's transaction API, in the old quinoscloud getTransactionByDate format:
 * [{Transaction: {...}, TransactionLine: [...]}] for one store and day.
 *
 * Serves only what the local runner uploaded (the `pos` package); this app never syncs it itself.
 */
class MobileTransactionController extends Controller
{
    public function __invoke(string $token, string $store, string $date): JsonResponse
    {
        $client = Client::where('active', true)->where('mobile_api_token_hash', hash('sha256', $token))->first();
        abort_if($client === null, 401, 'Invalid token.');

        $transactions = PosTransaction::where('client_id', $client->id)
            ->where('date', $date)
            ->where('store_code', strtoupper($store))
            ->orderBy('close_time')
            ->orderBy('quinos_id')
            ->get()
            ->map(fn (PosTransaction $t) => [
                'Transaction' => $this->ordered(Payload::POS_TRANSACTION, $t->transaction),
                'TransactionLine' => array_map(fn (array $line) => $this->ordered(Payload::POS_LINE, $line), $t->transaction_lines),
            ]);

        return response()->json($transactions)->header('Cache-Control', 'no-store, private');
    }

    /** All keys in the old API's order, whatever order they were stored in. */
    private function ordered(array $keys, array $values): array
    {
        return array_merge(array_fill_keys($keys, null), array_intersect_key($values, array_flip($keys)));
    }
}
