<?php

// Fragment of database/migrations/2026_10_28_100000_create_databackd_gaps_tables.php (shared with the other modules).
// Needed by the margins report (Gaps/Margins): the cost of a product. Not a standalone migration - merge into your own
// migration or run an equivalent ALTER. Kept in "_extract" so that `php artisan migrate` does not pick it up.

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// up()
if (!Schema::hasColumn('products', 'cost_price')) {
    Schema::table('products', function (Blueprint $table) {
        $table->decimal('cost_price', 15, 2)->nullable()->after('price');
    });
}

// down()
if (Schema::hasColumn('products', 'cost_price')) {
    Schema::table('products', function (Blueprint $table) {
        $table->dropColumn('cost_price');
    });
}
