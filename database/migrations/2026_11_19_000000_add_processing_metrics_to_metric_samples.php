<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metric_samples', function (Blueprint $table) {
            $table->unsignedInteger('queues')->default(0); // working queues in the sample
            $table->float('rate_redeliver')->default(0);
            $table->float('capacity')->nullable(); // consumer capacity (0..1), weighted by consumers; null when the broker does not report it
        });
    }

    public function down(): void
    {
        Schema::table('metric_samples', function (Blueprint $table) {
            $table->dropColumn(['queues', 'rate_redeliver', 'capacity']);
        });
    }
};
