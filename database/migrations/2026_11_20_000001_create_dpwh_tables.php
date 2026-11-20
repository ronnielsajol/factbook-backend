<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dpwh_contracts', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('year', 12);
            $t->string('region', 120);
            $t->string('type', 160);
            $t->string('status', 100);
            $t->longText('description');
            $t->longText('contractor')->nullable();
            $t->bigInteger('budget_cents')->nullable();
            $t->integer('components');
            $t->integer('mapped');
            $t->longText('provinces');
            $t->index(['year', 'region']);
            $t->index('region');
            $t->index(['type', 'status']);
            $t->index(['budget_cents', 'id']);
        });
        Schema::create('dpwh_details', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->longText('profile');
        });
        Schema::create('dpwh_points', function (Blueprint $t) {
            $t->bigInteger('point_id')->primary();
            $t->string('id', 40)->index();
            $t->text('component')->nullable();
            $t->double('longitude');
            $t->double('latitude');
            $t->index(['longitude', 'latitude']);
        });
        Schema::create('dpwh_contract_cities', function (Blueprint $t) {
            $t->bigInteger('sequence')->primary();
            $t->string('contract_id', 40);
            $t->string('city_id', 20);
            $t->longText('evidence');
            $t->index(['city_id', 'contract_id']);
            $t->index(['contract_id', 'sequence']);
        });
        Schema::create('dpwh_contract_provinces', function (Blueprint $t) {
            $t->string('contract_id', 40);
            $t->string('province', 150);
            $t->primary(['province', 'contract_id']);
        });
        Schema::create('dpwh_search_terms', function (Blueprint $t) {
            $t->bigInteger('term_id')->primary();
            $t->binary('term', 768)->index();
        });
        Schema::create('dpwh_search_memberships', function (Blueprint $t) {
            $t->bigInteger('term_id');
            $t->string('contract_id', 40);
            $t->primary(['term_id', 'contract_id']);
            $t->index('contract_id');
        });
        Schema::create('dpwh_import_progress', function (Blueprint $t) {
            $t->string('stage', 60)->primary();
            $t->bigInteger('cursor')->default(0);
            $t->string('source_hash', 64);
            $t->boolean('complete')->default(false);
        });
    }
    public function down(): void
    {
        foreach (['dpwh_import_progress', 'dpwh_search_memberships', 'dpwh_search_terms',
            'dpwh_contract_provinces', 'dpwh_contract_cities', 'dpwh_points',
            'dpwh_details', 'dpwh_contracts'] as $table) Schema::dropIfExists($table);
    }
};

