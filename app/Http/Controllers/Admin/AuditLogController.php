<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('view-audit-log');

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name,role')
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($filters['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        // Reservation subjects are shown by reference code; resolve them in one query.
        $codes = Reservation::query()
            ->whereIn('id', $logs->getCollection()->where('subject_type', 'reservation')->pluck('subject_id'))
            ->pluck('reference_code', 'id');

        return Inertia::render('Admin/AuditLog', [
            'logs' => $logs->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'subject_label' => $log->subject_type === 'reservation' ? ($codes[$log->subject_id] ?? null) : null,
                'before' => $log->before,
                'after' => $log->after,
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'ip' => $log->ip,
                'created_at' => $log->created_at?->toIso8601String(),
            ]),
            'filters' => array_merge(['action' => null, 'user_id' => null, 'from' => null, 'to' => null], $filters),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
