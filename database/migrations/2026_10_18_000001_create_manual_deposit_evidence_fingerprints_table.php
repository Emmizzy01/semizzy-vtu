<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_deposit_evidence_fingerprints', function (Blueprint $table): void {
            $table->char('evidence_sha256', 64)->primary();
            $table->foreignId('first_manual_deposit_id')->nullable()->constrained('manual_deposits')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('manual_deposits')
            ->select(['id', 'evidence_sha256', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(500, function ($deposits): void {
                foreach ($deposits as $deposit) {
                    DB::table('manual_deposit_evidence_fingerprints')->insertOrIgnore([
                        'evidence_sha256' => $deposit->evidence_sha256,
                        'first_manual_deposit_id' => $deposit->id,
                        'created_at' => $deposit->created_at,
                        'updated_at' => $deposit->updated_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_deposit_evidence_fingerprints');
    }
};
