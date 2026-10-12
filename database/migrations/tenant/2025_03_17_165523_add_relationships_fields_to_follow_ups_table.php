<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->foreignId('follow_up_media_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('follow_up_result_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('follow_up_status_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('follow_up_priority_id')->nullable()->constrained()->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropForeign(['follow_up_media_id']);
            $table->dropForeign(['follow_up_result_id']);
            $table->dropForeign(['follow_up_status_id']);
            $table->dropForeign(['follow_up_priority_id']);

            $table->dropColumn([
                'follow_up_media_id',
                'follow_up_result_id',
                'follow_up_status_id',
                'follow_up_priority_id',
            ]);
        });
    }
};
