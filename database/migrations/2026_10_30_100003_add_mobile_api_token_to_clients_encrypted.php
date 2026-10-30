<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The key itself, encrypted with APP_KEY (model cast) like the Quinos password, so admins can look it up
        // again. Requests are still matched on mobile_api_token_hash.
        Schema::table('clients', function (Blueprint $table) {
            $table->text('mobile_api_token')->nullable()->after('mobile_api_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('mobile_api_token');
        });
    }
};
