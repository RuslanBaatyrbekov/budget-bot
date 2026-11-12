<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            // null = limit for all expenses of the month
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->timestamps();
            $table->unique(['wallet_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
