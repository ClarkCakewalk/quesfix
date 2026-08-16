<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 覆核模式（規劃書 4.1.4 / 4.2.2）：覆核說明與覆核者記錄。
     */
    public function up(): void
    {
        Schema::table('check_results', function (Blueprint $table) {
            $table->text('re_check_note')->nullable()->after('note')->comment('覆核說明');
            $table->foreignId('re_checked_by')->nullable()->after('checked_at')
                ->constrained('users')->nullOnDelete()->comment('覆核者');
            $table->timestamp('re_checked_at')->nullable()->after('re_checked_by');
        });
    }

    public function down(): void
    {
        Schema::table('check_results', function (Blueprint $table) {
            $table->dropForeign(['re_checked_by']);
            $table->dropColumn(['re_check_note', 're_checked_by', 're_checked_at']);
        });
    }
};
