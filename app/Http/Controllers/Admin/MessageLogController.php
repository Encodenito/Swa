<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MessageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MessageLogController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('view-audit-log');

        $filters = $request->validate([
            'channel' => ['nullable', Rule::in(['mail', 'sms'])],
            'notification' => ['nullable', 'string', 'max:100'],
        ]);

        $messages = MessageLog::query()
            ->with(['user:id,name', 'reservation:id,reference_code'])
            ->when($filters['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->when($filters['notification'] ?? null, fn ($q, $n) => $q->where('notification', $n))
            ->latest('sent_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (MessageLog $m) => [
                'id' => $m->id,
                'channel' => $m->channel,
                'notification' => $m->notification,
                'recipient' => $m->recipient,
                'subject' => $m->subject,
                'sent_at' => $m->sent_at?->toIso8601String(),
                'user' => $m->user ? ['id' => $m->user->id, 'name' => $m->user->name] : null,
                'reference_code' => $m->reservation?->reference_code,
            ]);

        return Inertia::render('Admin/Messages', [
            'messages' => $messages,
            'filters' => ['channel' => $filters['channel'] ?? null, 'notification' => $filters['notification'] ?? null],
            'notifications' => MessageLog::query()->distinct()->orderBy('notification')->pluck('notification'),
        ]);
    }
}
