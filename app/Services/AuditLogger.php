<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Records who did what to which record, with before/after values. Call it inside the same
 * transaction as the change, so a rolled-back change leaves no log entry.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(string $action, Model $subject, ?array $before = null, ?array $after = null, ?User $by = null): AuditLog
    {
        $log = new AuditLog;
        $log->forceFill([
            'user_id' => ($by ?? Auth::user())?->getKey(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => app()->runningInConsole() ? null : Request::ip(),
            'created_at' => now(),
        ])->save();

        return $log;
    }
}
