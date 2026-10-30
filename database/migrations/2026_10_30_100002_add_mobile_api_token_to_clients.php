<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Token in the mobile API URL (as in the old quinoscloud API). Only its SHA-256 is stored.
        Schema::table('clients', function (Blueprint $table) {
            $table->string('mobile_api_token_hash', 64)->nullable()->unique()->after('quinos_company_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['mobile_api_token_hash']);
            $table->dropColumn('mobile_api_token_hash');
        });
    }
};
