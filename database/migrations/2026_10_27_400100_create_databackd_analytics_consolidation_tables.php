<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AFV MVP: consolidation groups, member entities and rules
return new class extends Migration {

    public function up()
    {
        Schema::create('fin_consolidation_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
            $table->string('name');
            $table->string('base_currency', 3)->default('EUR');
            $table->enum('method', ['full', 'proportional'])->default('full');
            $table->boolean('eliminate_intercompany')->default(true);
            $table->boolean('minority_interest')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('fin_consolidation_group_entities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
            $table->unsignedBigInteger('group_id');
            $table->foreign('group_id')->references('id')->on('fin_consolidation_groups')->onDelete('cascade');
            $table->unsignedBigInteger('entity_id');
            $table->foreign('entity_id')->references('id')->on('fin_entities')->onDelete('cascade');
            $table->decimal('ownership_pct', 5, 2)->nullable();
            $table->decimal('fx_rate', 15, 6)->nullable();
            $table->boolean('include')->default(true);
            $table->timestamps();

            $table->unique(['group_id', 'entity_id']);
        });

        Schema::create('fin_consolidation_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade')->onUpdate('cascade');
            $table->unsignedBigInteger('group_id');
            $table->foreign('group_id')->references('id')->on('fin_consolidation_groups')->onDelete('cascade');
            $table->enum('type', ['eliminate_counterparty', 'eliminate_account_pair', 'reclassify', 'exclude_account']);
            $table->json('params')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('fin_consolidation_rules');
        Schema::dropIfExists('fin_consolidation_group_entities');
        Schema::dropIfExists('fin_consolidation_groups');
    }

};
