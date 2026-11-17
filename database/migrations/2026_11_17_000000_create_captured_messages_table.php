<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captured_messages', function (Blueprint $table) {
            $table->id();
            // Bumped on every change (insert or delivery), so clients can ask "what changed since N".
            $table->unsignedBigInteger('rev')->index();
            $table->unsignedBigInteger('ts'); // publish time, epoch milliseconds
            $table->string('origin', 16);     // "copy" (bound copy queue) or "trace" (firehose)
            $table->string('exchange');
            $table->string('routing_key', 1024);
            $table->string('instance')->nullable()->index();
            $table->string('job')->nullable();
            $table->string('uuid')->nullable();
            $table->integer('priority')->nullable();
            $table->unsignedInteger('size');
            $table->json('queues');
            $table->json('deliveries');
            $table->json('properties');
            $table->json('headers');
            $table->mediumText('body');
            $table->boolean('body_truncated')->default(false);
            $table->string('hash', 40)->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captured_messages');
    }
};
