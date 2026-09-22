<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Historical filename retained for installations that already recorded it. */
    public function up(): void
    {
        // Domain accounts are created by create_usuarios_table; HTTP sessions use files.
    }

    public function down(): void
    {
        // Never delete legacy users/sessions that may contain data from an earlier installation.
    }
};
