<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Client;
use App\Support\CurrentClient;

trait UsesCurrentClient
{
    /** The client this request is about; admins must have picked one. */
    protected function currentClient(): Client
    {
        $id = app(CurrentClient::class)->id();

        abort_if($id === null, 422, 'Select a client first.');

        return Client::findOrFail($id);
    }
}
