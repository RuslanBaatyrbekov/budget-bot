<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('store')->nullable()->after('description');
            $table->uuid('batch_id')->nullable()->after('store');
            $table->index(['wallet_id', 'type', 'purchased_at']);
            $table->index('batch_id');
        });

        // Move store / batch_id out of the JSON metadata into real columns.
        DB::table('transactions')
            ->whereNotNull('ai_metadata')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $meta = json_decode($row->ai_metadata ?? '', true);

                    if (! is_array($meta)) {
                        continue;
                    }

                    DB::table('transactions')->where('id', $row->id)->update([
                        'store' => $meta['store'] ?? $meta['store_name'] ?? null,
                        'batch_id' => $meta['batch_id'] ?? null,
                    ]);
                }
            });

        // Every record gets a batch id: buttons and /undo work with batches.
        DB::table('transactions')->whereNull('batch_id')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('transactions')->where('id', $row->id)->update(['batch_id' => (string) Str::uuid()]);
            }
        });

        DB::table('transactions')->whereNull('purchased_at')->update([
            'purchased_at' => DB::raw('transaction_date'),
        ]);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['wallet_id', 'type', 'purchased_at']);
            $table->dropIndex(['batch_id']);
            $table->dropColumn(['store', 'batch_id']);
        });
    }
};
