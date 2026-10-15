<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class UserLevelUpgradeRequest extends Model {
 protected $fillable=['user_id','current_level','requested_level','status','eligibility_snapshot','user_note','review_note','reviewed_by_user_id','reviewed_at'];
 protected function casts(): array { return ['eligibility_snapshot'=>'array','reviewed_at'=>'datetime']; }
 public function user(): BelongsTo { return $this->belongsTo(User::class); }
 public function reviewer(): BelongsTo { return $this->belongsTo(User::class,'reviewed_by_user_id'); }
}