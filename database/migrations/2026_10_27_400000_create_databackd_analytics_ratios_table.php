<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AFV MVP: financial ratios (system and custom formulas)
return new class extends Migration {

    public function up()
    {
        Schema::create('fin_ratios', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
            $table->string('code', 60);
            $table->string('name');
            $table->enum('group', ['liquidity', 'profitability', 'leverage', 'efficiency', 'custom'])->default('custom');
            $table->text('formula');
            $table->enum('format', ['ratio', 'percent', 'days', 'currency'])->default('ratio');
            $table->decimal('target_min', 15, 4)->nullable();
            $table->decimal('target_max', 15, 4)->nullable();
            $table->boolean('higher_is_better')->default(true);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('fin_ratios');
    }

};
