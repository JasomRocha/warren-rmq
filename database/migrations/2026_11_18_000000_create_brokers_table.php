<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per RabbitMQ the user follows. The password is stored encrypted (see App\Models\Broker).
        Schema::create('brokers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->string('username');
            $table->text('password');
            $table->string('vhost')->default('/');
            $table->string('amqp_host')->nullable();
            $table->unsignedInteger('amqp_port')->default(5672);
            $table->boolean('amqp_ssl')->default(false);
            $table->boolean('tap_enabled')->default(true);
            $table->timestamps();
        });

        Schema::table('captured_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('broker_id')->nullable()->index();
        });

        // One sample of the broker's totals every ~30 s: the dashboard's history.
        Schema::create('metric_samples', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('broker_id');
            $table->unsignedInteger('ts'); // epoch seconds
            $table->unsignedInteger('ready');
            $table->unsignedInteger('unacked');
            $table->unsignedInteger('consumers');
            $table->float('rate_in');
            $table->float('rate_deliver');
            $table->float('rate_ack');
            $table->json('areas')->nullable(); // business area => waiting messages
            $table->index(['broker_id', 'ts']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_samples');
        Schema::table('captured_messages', fn (Blueprint $table) => $table->dropColumn('broker_id'));
        Schema::dropIfExists('brokers');
    }
};
