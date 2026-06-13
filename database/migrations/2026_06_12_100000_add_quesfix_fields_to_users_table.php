<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->unique()->after('id');
            $table->unsignedTinyInteger('gender')->default(0)->after('name')->comment('0=未指定, 1=男, 2=女');
            $table->string('unit')->nullable()->after('gender')->comment('服務單位');
            $table->unsignedTinyInteger('role')->default(2)->after('unit')->comment('1=系統管理者, 2=一般使用者');
            $table->unsignedSmallInteger('failed_attempts')->default(0)->after('remember_token');
            $table->timestamp('locked_at')->nullable()->after('failed_attempts')->comment('連續 5 次登入失敗鎖定');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'gender', 'unit', 'role', 'failed_attempts', 'locked_at']);
        });
    }
};
