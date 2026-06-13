<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 問卷別（專案）
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique()->comment('專案代號（英數）');
            $table->string('name')->comment('專案名稱');
            // 報表設定（週數變數、訪員代號變數）於 ques_vars 建立後補上外鍵
            $table->unsignedBigInteger('week_var_id')->nullable()->comment('週數變數（訪員錯誤報表）');
            $table->unsignedBigInteger('interviewer_var_id')->nullable()->comment('訪員代號變數');
            $table->timestamps();
        });

        // 專案成員與角色
        Schema::create('ques_authes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ques_id')->constrained('questions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('role')->comment('1=專案管理者, 2=檢核人員');
            $table->timestamps();
            $table->unique(['ques_id', 'user_id']);
        });

        // 題目清單
        Schema::create('ques_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ques_id')->constrained('questions')->cascadeOnDelete();
            $table->string('name')->comment('題目名稱（題號）');
            $table->string('label', 1024)->comment('題目標籤');
            $table->unsignedInteger('sort_order')->default(0)->comment('問卷題序');
            $table->timestamps();
            $table->unique(['ques_id', 'name']);
        });

        // 選項群組（數值標籤代號）
        Schema::create('option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ques_id')->constrained('questions')->cascadeOnDelete();
            $table->string('name', 128)->comment('標籤代號');
            $table->string('description')->nullable();
            $table->timestamps();
            $table->unique(['ques_id', 'name']);
        });

        // 選項內容（數值標籤）
        Schema::create('ques_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('option_group_id')->constrained('option_groups')->cascadeOnDelete();
            $table->string('value', 128)->comment('數值');
            $table->string('label', 1024)->comment('數值說明');
            $table->timestamps();
            $table->unique(['option_group_id', 'value']);
        });

        // 變數清單
        Schema::create('ques_vars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ques_id')->constrained('questions')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('ques_items')->cascadeOnDelete();
            $table->string('variable', 128)->comment('變數名稱（區分大小寫）');
            $table->string('label', 1024)->comment('變數標籤');
            $table->foreignId('option_group_id')->nullable()->constrained('option_groups')->nullOnDelete();
            $table->unsignedTinyInteger('var_type')->default(2)->comment('1=選項, 2=數值, 3=文字');
            $table->timestamps();
            $table->unique(['ques_id', 'variable']);
        });

        // MySQL 預設定序不分大小寫，但變數名稱須區分（Sex 與 sex 不同）
        DB::statement('ALTER TABLE ques_vars MODIFY variable VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');

        Schema::table('questions', function (Blueprint $table) {
            $table->foreign('week_var_id')->references('id')->on('ques_vars')->nullOnDelete();
            $table->foreign('interviewer_var_id')->references('id')->on('ques_vars')->nullOnDelete();
        });

        // 訪員錯誤報表的「訪問相關訊息」變數（多個、有順序）
        Schema::create('ques_report_vars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ques_id')->constrained('questions')->cascadeOnDelete();
            $table->foreignId('var_id')->constrained('ques_vars')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['ques_id', 'var_id']);
        });

        // 樣本索引與鎖定
        Schema::create('samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ques_id')->constrained('questions')->cascadeOnDelete();
            $table->string('sample_id', 64)->comment('樣本編號');
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('lock_expires_at')->nullable()->comment('心跳續鎖，逾期視為未鎖');
            $table->timestamps();
            $table->unique(['ques_id', 'sample_id']);
        });

        // 原始數據（長格式：一樣本一變數一值）
        Schema::create('origin_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ques_id')->constrained('questions')->cascadeOnDelete();
            $table->string('sample_id', 64);
            $table->foreignId('var_id')->constrained('ques_vars')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['ques_id', 'sample_id', 'var_id'], 'origin_data_ques_sample_var_unique');
        });

        // 檢核清單
        Schema::create('check_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ques_id')->constrained('questions')->cascadeOnDelete();
            $table->string('item_name', 128)->comment('條件代號');
            $table->text('description')->comment('條件敘述');
            $table->text('logic')->comment('Stata 語法檢核邏輯');
            $table->timestamps();
            $table->unique(['ques_id', 'item_name']);
        });

        // 檢核條件關聯題目
        Schema::create('check_item_vars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_item_id')->constrained('check_items')->cascadeOnDelete();
            $table->foreignId('ques_item_id')->constrained('ques_items')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['check_item_id', 'ques_item_id']);
        });

        // 數據修正紀錄（append-only）
        Schema::create('fix_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_id')->constrained('origin_data')->cascadeOnDelete();
            $table->text('value');
            $table->string('note', 1024)->nullable();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('check_item_id')->constrained('check_items')->cascadeOnDelete();
            $table->timestamps();
            $table->index(['data_id', 'created_at']);
        });

        // 檢核結果
        Schema::create('check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ques_id')->constrained('questions')->cascadeOnDelete();
            $table->string('sample_id', 64);
            $table->foreignId('check_item_id')->constrained('check_items')->cascadeOnDelete();
            $table->unsignedTinyInteger('error')->nullable()
                ->comment('null=未處理, 0=接受, 1=錯誤且算錯, 2=錯誤不算錯, 3=重新確認');
            $table->boolean('re_survey')->default(false)->comment('是否補問');
            $table->text('re_survey_note')->nullable()->comment('補問說明');
            $table->text('error_note')->nullable()->comment('訪員說明（給訪員的錯誤提示）');
            $table->text('note')->nullable()->comment('內部註記');
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('resolved_at')->nullable()->comment('修正後條件不再觸發');
            $table->timestamps();
            $table->unique(['ques_id', 'sample_id', 'check_item_id'], 'check_results_ques_sample_item_unique');
            $table->index(['ques_id', 'check_item_id', 'error']);
        });

        // 題目關聯影音（截圖/錄音，每樣本每題）
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ques_id')->constrained('questions')->cascadeOnDelete();
            $table->string('sample_id', 64);
            $table->foreignId('item_id')->constrained('ques_items')->cascadeOnDelete();
            $table->unsignedTinyInteger('type')->comment('1=截圖, 2=錄音');
            $table->string('path', 1024);
            $table->unsignedInteger('sort_order')->default(0)->comment('連續播放順序');
            $table->timestamps();
            $table->index(['ques_id', 'sample_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropForeign(['week_var_id']);
            $table->dropForeign(['interviewer_var_id']);
        });

        foreach (['media', 'check_results', 'fix_data', 'check_item_vars', 'check_items',
            'origin_data', 'samples', 'ques_report_vars', 'ques_vars', 'ques_options',
            'option_groups', 'ques_items', 'ques_authes', 'questions'] as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};
