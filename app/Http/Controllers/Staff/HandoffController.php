<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ReasonRequest;
use App\Models\ChatHandoff;
use App\Services\ChatHandoffService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class HandoffController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ChatHandoff::class);

        $status = $request->validate(['status' => ['nullable', Rule::in(['open', 'claimed', 'closed'])]])['status'] ?? 'open';

        $handoffs = ChatHandoff::query()
            ->with(['claimer:id,name', 'user:id,name,email'])
            ->where('status', $status)
            ->when($status === 'closed', fn ($q) => $q->latest('closed_at'), fn ($q) => $q->oldest())
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ChatHandoff $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'contact' => $h->contact,
                'message' => $h->message,
                'transcript' => $h->transcript ?? [],
                'status' => $h->status,
                'customer' => $h->user ? ['id' => $h->user->id, 'name' => $h->user->name, 'email' => $h->user->email] : null,
                'claimed_by' => $h->claimer?->name,
                'mine' => $h->claimed_by === $request->user()->id,
                'claimed_at' => $h->claimed_at?->toIso8601String(),
                'closed_at' => $h->closed_at?->toIso8601String(),
                'reply_note' => $h->reply_note,
                'created_at' => $h->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Staff/Handoffs', [
            'status' => $status,
            'handoffs' => $handoffs,
            'counts' => ChatHandoff::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function claim(Request $request, ChatHandoff $handoff, ChatHandoffService $service): RedirectResponse
    {
        Gate::authorize('claim', $handoff);

        $service->claim($handoff, $request->user());

        return back(303)->with('success', 'You are handling this chat. Contact the customer, then close it with a note.');
    }

    public function close(ReasonRequest $request, ChatHandoff $handoff, ChatHandoffService $service): RedirectResponse
    {
        Gate::authorize('close', $handoff);

        $service->close($handoff, $request->validated('reason'), $request->user());

        return back(303)->with('success', 'Chat closed.');
    }
}
