<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

if (!Schema::hasTable('polls')) {
    Schema::create('polls', function (Blueprint $table) {
        $table->id();
        $table->string('channel_id')->index();
        $table->string('question', 300);
        $table->string('created_by', 64);
        $table->string('created_by_id');
        $table->timestamp('closes_at')->nullable();
        $table->timestamp('closed_at')->nullable();
        $table->timestamp('created_at')->nullable();
    });
}

if (!Schema::hasTable('poll_options')) {
    Schema::create('poll_options', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('poll_id');
        $table->string('label', 100);
        $table->unsignedTinyInteger('position')->default(0);
        $table->foreign('poll_id')->references('id')->on('polls')->cascadeOnDelete();
    });
}

if (!Schema::hasTable('poll_votes')) {
    Schema::create('poll_votes', function (Blueprint $table) {
        $table->unsignedBigInteger('poll_id');
        $table->string('voter_id');
        $table->unsignedBigInteger('option_id');
        $table->timestamp('voted_at')->nullable();
        $table->primary(['poll_id', 'voter_id']);
        $table->foreign('poll_id')->references('id')->on('polls')->cascadeOnDelete();
        $table->foreign('option_id')->references('id')->on('poll_options')->cascadeOnDelete();
    });
}
