<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Models\UserLevelPolicy;
use App\Models\UserLevelUpgradeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
class UserLevelUpgradeReviewController extends Controller
{
 public function index(): InertiaResponse
 {
  return Inertia::render('Admin/UserLevelUpgradeRequests',['requests'=>UserLevelUpgradeRequest::query()->with(['user:id,name,email,user_level,user_type,kyc_status','reviewer:id,name'])->latest()->paginate(25)->through(fn($r)=>[
   'id'=>$r->id,'user_id'=>$r->user_id,'user_name'=>$r->user?->name,'user_email'=>$r->user?->email,'account_type'=>$r->user?->user_type,'kyc_status'=>$r->user?->kyc_status,'current_level'=>$r->current_level,'requested_level'=>$r->requested_level,'status'=>$r->status,'user_note'=>$r->user_note,'review_note'=>$r->review_note,'eligibility_snapshot'=>$r->eligibility_snapshot,'created_at'=>$r->created_at?->toISOString(),'reviewer_name'=>$r->reviewer?->name,'reviewed_at'=>$r->reviewed_at?->toISOString()
  ])]);
 }
 public function review(Request $request, UserLevelUpgradeRequest $upgradeRequest): RedirectResponse
 {
  $data=$request->validate(['decision'=>['required','in:approve,reject'],'review_note'=>['required','string','min:5','max:1000']]);
  $outcome=DB::transaction(function()use($request,$upgradeRequest,$data):array{
   $r=UserLevelUpgradeRequest::query()->whereKey($upgradeRequest->id)->lockForUpdate()->firstOrFail();
   if($r->status!=='pending')return ['error'=>'This request has already been reviewed.'];
   $user=$r->user()->lockForUpdate()->firstOrFail();
   if($user->user_level!==$r->current_level)return ['error'=>'The user level has changed since this request was submitted. Reject this stale request and ask the user to submit again.'];
   if($data['decision']==='approve'){
    $policy=UserLevelPolicy::query()->where('level',$r->requested_level)->lockForUpdate()->first();
    if(!$policy||!$policy->is_active)return ['error'=>'The requested tier policy is missing or disabled.'];
    $kyc=strtolower((string)($user->kyc_status??'unverified'))==='verified';
    if($policy->requires_verified_kyc&&!$kyc)return ['error'=>'The user does not currently satisfy the tier KYC requirement.'];
    $user->forceFill(['user_level'=>$r->requested_level])->save();
    $r->status='approved';
   }else{$r->status='rejected';}
   $r->forceFill(['review_note'=>trim($data['review_note']),'reviewed_by_user_id'=>$request->user()->id,'reviewed_at'=>now()])->save();
   return ['success'=>true,'status'=>$r->status];
  },attempts:3);
  if(isset($outcome['error']))return back()->withErrors(['review'=>$outcome['error']]);
  return back()->with('status','Upgrade request '.$outcome['status'].'. The decision has been recorded.');
 }
}