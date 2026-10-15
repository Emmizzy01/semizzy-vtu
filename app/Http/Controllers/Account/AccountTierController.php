<?php
namespace App\Http\Controllers\Account;
use App\Http\Controllers\Controller;
use App\Models\UserLevelPolicy;
use App\Models\UserLevelUpgradeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
class AccountTierController extends Controller {
 private const LEVELS=['level_1','level_2','level_3','level_4'];
 public function index(Request $request): InertiaResponse {
  $user=$request->user(); $current=in_array($user->user_level,self::LEVELS,true)?$user->user_level:'level_1'; $idx=array_search($current,self::LEVELS,true);
  $policies=UserLevelPolicy::query()->whereIn('level',self::LEVELS)->orderBy('level')->get()->keyBy('level'); $kyc=strtolower((string)($user->kyc_status??'unverified'))==='verified';
  $pending=UserLevelUpgradeRequest::query()->where('user_id',$user->id)->where('status','pending')->latest()->first();
  $tiers=collect(self::LEVELS)->map(function(string $level,int $i)use($policies,$idx,$kyc):array{
   $p=$policies->get($level); $configured=$p!==null; $req=[];
   if(!$configured)$req[]='This tier policy has not been configured by an administrator.';
   if($configured&&!$p->is_active&&$i!==$idx)$req[]='This tier is currently unavailable.';
   if($configured&&$p->requires_verified_kyc&&!$kyc)$req[]='Complete and verify your KYC.';
   return ['level'=>$level,'label'=>$p?->label?:strtoupper(str_replace('_',' ',$level)),'configured'=>$configured,'active'=>$configured&&(bool)$p->is_active,'current'=>$i===$idx,'wallet_balance_limit_minor'=>$p?->wallet_balance_limit_minor,'max_single_transaction_minor'=>$p?->max_single_transaction_minor,'daily_transaction_limit_minor'=>$p?->daily_transaction_limit_minor,'requires_verified_kyc'=>(bool)($p?->requires_verified_kyc??false),'requirements'=>$req];
  })->values();
  return Inertia::render('Account/Tiers',['currentLevel'=>$current,'accountType'=>$user->user_type?:'customer','kycStatus'=>$user->kyc_status?:'unverified','tiers'=>$tiers,'nextLevel'=>$idx<3?self::LEVELS[$idx+1]:null,'kycVerified'=>$kyc,'pendingRequest'=>$pending?['id'=>$pending->id,'requested_level'=>$pending->requested_level,'status'=>$pending->status,'created_at'=>$pending->created_at?->toISOString()]:null]);
 }
 public function requestUpgrade(Request $request): RedirectResponse {
  $data=$request->validate(['note'=>['nullable','string','max:1000']]); $user=$request->user();
  $result=DB::transaction(function()use($user,$data):array{
   $locked=$user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail(); $current=in_array($locked->user_level,self::LEVELS,true)?$locked->user_level:'level_1'; $idx=array_search($current,self::LEVELS,true);
   if($idx>=3)return ['error'=>'Your account is already at the highest configured level.'];
   if(UserLevelUpgradeRequest::query()->where('user_id',$locked->id)->where('status','pending')->exists())return ['error'=>'You already have an upgrade request awaiting review.'];
   $next=self::LEVELS[$idx+1]; $policy=UserLevelPolicy::query()->where('level',$next)->first();
   if(!$policy||!$policy->is_active)return ['error'=>'The next tier is not currently available. Please contact support.'];
   $kyc=strtolower((string)($locked->kyc_status??'unverified'))==='verified';
   if($policy->requires_verified_kyc&&!$kyc)return ['error'=>'Complete KYC verification before requesting this tier.'];
   UserLevelUpgradeRequest::query()->create(['user_id'=>$locked->id,'current_level'=>$current,'requested_level'=>$next,'status'=>'pending','eligibility_snapshot'=>['kyc_verified'=>$kyc,'target_policy_active'=>(bool)$policy->is_active,'requires_verified_kyc'=>(bool)$policy->requires_verified_kyc],'user_note'=>isset($data['note'])?trim($data['note']):null]);
   return ['success'=>true];
  },attempts:3);
  if(isset($result['error']))return back()->withErrors(['upgrade'=>$result['error']]);
  return back()->with('status','Upgrade request submitted for review. Your account level will not change until an authorised review is completed.');
 }
}