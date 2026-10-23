<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 第三方身份的安全边界：同一驱动 + 登录方式 + openid 只能绑定一个本站用户。
     * 旧记录的空 type 统一为 ''，确保普通单方式驱动同样受唯一约束保护。
     */
    public function up(): void
    {
        DB::table('oauth')->whereNull('type')->update(['type' => '']);
        Schema::table('oauth', function (Blueprint $table) {
            $table->string('type', 32)->nullable(false)->default('')
                ->comment('三方登录方式（聚合驱动用：qq/wx/baidu 等）')->change();
        });
        Schema::table('oauth', function (Blueprint $table) {
            $table->unique(['driver_id', 'type', 'openid'], 'oauth_driver_type_openid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('oauth', function (Blueprint $table) {
            $table->dropUnique('oauth_driver_type_openid_unique');
        });
        Schema::table('oauth', function (Blueprint $table) {
            $table->string('type', 32)->nullable()->default(null)
                ->comment('三方登录方式（聚合驱动用：qq/wx/baidu 等）')->change();
        });
    }
};
