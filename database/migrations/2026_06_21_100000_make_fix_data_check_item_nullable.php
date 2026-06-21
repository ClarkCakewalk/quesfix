<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 資料修改模式的修訂不綁定特定檢核條件，故 check_item_id 需可為 null。
     */
    public function up(): void
    {
        Schema::table('fix_data', function (Blueprint $table) {
            $table->dropForeign(['check_item_id']);
            $table->foreignId('check_item_id')->nullable()->change();
            $table->foreign('check_item_id')->references('id')->on('check_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fix_data', function (Blueprint $table) {
            $table->dropForeign(['check_item_id']);
            $table->foreignId('check_item_id')->nullable(false)->change();
            $table->foreign('check_item_id')->references('id')->on('check_items')->cascadeOnDelete();
        });
    }
};
