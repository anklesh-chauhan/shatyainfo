<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('goods_receipt_note_lines')) {
            Schema::create('goods_receipt_note_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('goods_receipt_note_id')->constrained('goods_receipt_notes')->cascadeOnDelete();
                $table->foreignId('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->nullOnDelete();
                $table->foreignId('item_master_id')->constrained('item_masters')->cascadeOnDelete();
                $table->decimal('quantity_received', 15, 3);
                $table->decimal('unit_cost', 15, 4);
                $table->string('batch_number')->nullable();
                $table->text('remarks')->nullable();
                $table->blameable();
                $table->blameableSoftDeletes();
                $table->timestamps();

                $table->index(['goods_receipt_note_id', 'item_master_id'], 'grn_lines_grn_item_idx');
            });

            return;
        }

        Schema::table('goods_receipt_note_lines', function (Blueprint $table): void {
            if (! Schema::hasIndex('goods_receipt_note_lines', 'grn_lines_grn_item_idx')) {
                $table->index(['goods_receipt_note_id', 'item_master_id'], 'grn_lines_grn_item_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_note_lines');
    }
};
