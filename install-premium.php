<?php

// Run once with: php plugins/yggdrasil-api/install-premium.php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;

if (! Schema::hasColumn('pending_mojang_bind', 'mojang_uuid')) {
    Schema::table('pending_mojang_bind', function ($table) {
        $table->string('mojang_uuid', 32)->nullable()->index();
    });
}
if (! Schema::hasTable('premium_binding_sessions')) {
    Schema::create('premium_binding_sessions', function ($table) {
        $table->string('session_hash', 64)->primary();
        $table->dateTime('expires_at')->index();
    });
}
if (! Schema::hasTable('premium_binding_proofs')) {
    Schema::create('premium_binding_proofs', function ($table) {
        $table->string('code_hash', 64)->primary();
        $table->string('mojang_uuid', 32);
        $table->dateTime('expires_at')->index();
    });
}
if (! Schema::hasTable('premium_binding_codes')) {
    Schema::create('premium_binding_codes', function ($table) {
        $table->string('mojang_uuid', 32)->primary();
        $table->string('code_hash', 64);
        $table->unsignedInteger('attempts')->default(0);
        $table->dateTime('expires_at')->index();
    });
}
if (! Schema::hasTable('premium_binding_metadata')) {
    Schema::create('premium_binding_metadata', function ($table) {
        $table->integer('user_id')->primary();
        $table->string('mojang_uuid', 32);
        $table->dateTime('verified_at');
    });
}
echo "Premium binding schema ready; existing bindings unchanged.\n";
