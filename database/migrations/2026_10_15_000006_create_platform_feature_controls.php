<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_feature_controls', function (Blueprint $table): void {
            $table->id();
            $table->string('feature_key', 100)->unique();
            $table->string('label', 120);
            $table->string('category', 60);
            $table->string('owner', 80);
            $table->string('description', 500);
            $table->json('dependencies')->nullable();
            $table->boolean('default_enabled')->default(false);
            $table->boolean('is_enabled')->default(false);
            $table->boolean('is_core')->default(false);
            $table->boolean('is_implemented')->default(false);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['category', 'is_enabled']);
        });
        $rows = [
            ['core.identity','Identity & access','Core','Platform core','Shared customer identity and server-side authorization.',[],true,true,true,true],
            ['core.wallet_ledger','Wallet & ledger','Core','Finance','Wallet balances, immutable ledger posting and idempotency foundation.',[],true,true,true,true],
            ['core.audit','Audit trail','Core','Compliance','Append-only security, permission and financial change audit records.',[],true,true,true,true],
            ['service.airtime_data','Airtime & data','Everyday services','VTU operations','Supported airtime/data catalogue and wallet-funded purchase workflow.',['core.identity','core.wallet_ledger','core.audit'],true,true,false,true],
            ['service.manual_deposit','Manual bank deposits','Everyday services','Finance operations','Evidence submission, duplicate detection, staff review and one-time wallet credit.',['core.identity','core.wallet_ledger','core.audit'],true,true,false,true],
            ['service.electricity','Electricity bills','Everyday services','Provider operations','Provider-backed electricity biller catalogue and reconciliation.',['core.wallet_ledger','core.audit'],false,false,false,false],
            ['service.tv','Cable TV','Everyday services','Provider operations','Authorized TV subscription catalogue and fulfilment.',['core.wallet_ledger','core.audit'],false,false,false,false],
            ['service.internet','Internet bills','Everyday services','Provider operations','Authorized broadband and internet-provider bill payments.',['core.wallet_ledger','core.audit'],false,false,false,false],
            ['service.remita','Remita payments','Payments','Finance operations','Authorized RRR verification, settlement and reconciliation workflows.',['core.wallet_ledger','core.audit'],false,false,false,false],
            ['service.marketplace','Unified Marketplace','Commerce','Marketplace operations','One marketplace with 19 categories, listings, orders, moderation and dispute controls.',['core.identity','core.wallet_ledger','core.audit'],false,false,false,false],
            ['service.smm','Social media marketing','Business tools','Provider operations','Provider-backed SMM orders, safe holds, requery and refund/release controls.',['service.marketplace','core.wallet_ledger','core.audit'],false,false,false,false],
            ['service.education','Education & exam services','Education','Education operations','Approved learning resources and authorized exam/institution workflows.',['core.identity','core.audit'],false,false,false,false],
            ['service.reseller','Reseller & agent tools','Business tools','Business operations','Mini-stores, pricing tiers, subagents, commissions and reports.',['core.identity','core.wallet_ledger','core.audit'],false,false,false,false],
            ['service.developer_api','Developer APIs','Business tools','Developer platform','Scoped credentials, quotas, usage reporting and API audit.',['core.identity','core.audit'],false,false,false,false],
            ['service.savings','Savings & group contributions','Savings & engagement','Finance & compliance','Savings and Ajo/Esusu; requires separate legal, operational and risk gates.',['core.identity','core.wallet_ledger','core.audit'],false,false,false,false],
            ['service.rewards','Rewards & promotions','Savings & engagement','Growth operations','Reward ledgers, eligibility, campaign controls and reversal handling.',['core.audit'],false,false,false,false],
            ['service.websites','Websites & domains','Business tools','Platform operations','Website builder, reseller storefronts and domain workflows.',['core.identity','core.audit'],false,false,false,false],
            ['service.escrow','Escrow & transaction protection','Regulated / partner gated','Risk & compliance','Eligible marketplace escrow only after approved legal and partner controls.',['service.marketplace','core.wallet_ledger','core.audit'],false,false,false,false],
        ];
        $now = now();
        DB::table('platform_feature_controls')->insert(array_map(fn (array $row): array => [
            'feature_key'=>$row[0], 'label'=>$row[1], 'category'=>$row[2], 'owner'=>$row[3], 'description'=>$row[4],
            'dependencies'=>json_encode($row[5], JSON_THROW_ON_ERROR), 'default_enabled'=>$row[6], 'is_enabled'=>$row[7],
            'is_core'=>$row[8], 'is_implemented'=>$row[9], 'created_at'=>$now, 'updated_at'=>$now,
        ], $rows));
    }
    public function down(): void { Schema::dropIfExists('platform_feature_controls'); }
};
