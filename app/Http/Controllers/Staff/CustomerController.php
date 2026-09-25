<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ReservationPresenter;
use App\Http\Requests\Crm\StoreFollowUpRequest;
use App\Http\Requests\Crm\StoreNoteRequest;
use App\Http\Requests\Crm\StoreTagRequest;
use App\Models\CustomerNote;
use App\Models\FollowUp;
use App\Models\MessageLog;
use App\Models\Reservation;
use App\Models\Tag;
use App\Models\User;
use App\Services\CrmService;
use App\Services\CustomerSegments;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Light CRM (spec Phase 3). Staff-only; customers never see notes, tags or follow-ups about them.
 */
class CustomerController extends Controller
{
    public function index(Request $request, CustomerSegments $segments): Response
    {
        Gate::authorize('viewCustomers', User::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'tag' => ['nullable', 'string', 'max:60'],
            'segment' => ['nullable', Rule::in(array_keys(CustomerSegments::SEGMENTS))],
        ]);
        $search = trim($filters['q'] ?? '');

        $customers = $segments->apply($segments->baseQuery(), $filters['segment'] ?? '')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', like_pattern($search))
                ->orWhere('email', 'like', like_pattern($search))
                ->orWhere('phone', 'like', like_pattern($search))))
            ->when($filters['tag'] ?? null, fn ($q, $slug) => $q->whereHas('tags', fn ($t) => $t->where('slug', $slug)))
            ->with('tags:id,name,slug,colour')
            ->withCount([
                'reservations',
                'reservations as completed_count' => fn ($r) => $r->where('status', ReservationStatus::Completed),
                'followUps as open_follow_ups_count' => fn ($f) => $f->whereNull('completed_at'),
            ])
            ->withMax('reservations as last_reservation_at', 'created_at')
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (User $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'phone' => $c->phone,
                'active' => $c->deactivated_at === null,
                'tags' => $c->tags->map->only(['name', 'slug', 'colour'])->values(),
                'reservations_count' => $c->reservations_count,
                'completed_count' => $c->completed_count,
                'open_follow_ups_count' => $c->open_follow_ups_count,
                'last_reservation_at' => $c->last_reservation_at ? (string) $c->last_reservation_at : null,
            ]);

        return Inertia::render('Staff/Customers/Index', [
            'customers' => $customers,
            'filters' => ['q' => $search, 'tag' => $filters['tag'] ?? null, 'segment' => $filters['segment'] ?? null],
            'segments' => collect(CustomerSegments::SEGMENTS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'tags' => Tag::query()->orderBy('name')->get(['name', 'slug', 'colour']),
        ]);
    }

    public function show(Request $request, User $customer): Response
    {
        Gate::authorize('viewCustomer', $customer);

        $reservations = $customer->reservations()->with('lines')->latest()->limit(50)->get();

        $spent = Reservation::query()
            ->where('user_id', $customer->id)
            ->where('status', ReservationStatus::Completed)
            ->pluck('total')
            ->all();

        return Inertia::render('Staff/Customers/Show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'verified' => $customer->email_verified_at !== null,
                'active' => $customer->deactivated_at === null,
                'created_at' => $customer->created_at?->toIso8601String(),
                'tags' => $customer->tags()->orderBy('name')->get(['tags.id', 'name', 'slug', 'colour'])->map->only(['id', 'name', 'slug', 'colour']),
            ],
            'stats' => [
                'reservations' => $reservations->count(),
                'completed' => $reservations->where('status', ReservationStatus::Completed)->count(),
                'cancelled' => $reservations->where('status', ReservationStatus::Cancelled)->count(),
                'forfeited' => $reservations->where('status', ReservationStatus::Forfeited)->count(),
                'spent' => Money::add(...$spent),
            ],
            'reservations' => $reservations->map(fn (Reservation $r) => ReservationPresenter::summary($r))->values(),
            'notes' => $customer->customerNotes()->with('author:id,name')->limit(100)->get()->map(fn (CustomerNote $n) => [
                'id' => $n->id,
                'body' => $n->body,
                'author' => $n->author?->name,
                'created_at' => $n->created_at?->toIso8601String(),
                'can_delete' => $request->user()->can('delete', $n),
            ]),
            'followUps' => $customer->followUps()->with(['author:id,name', 'assignee:id,name', 'reservation:id,reference_code'])
                ->orderByRaw('completed_at IS NOT NULL')->orderBy('due_on')->limit(50)->get()
                ->map(fn (FollowUp $f) => $this->followUp($f)),
            'messages' => MessageLog::query()->where('user_id', $customer->id)->latest('sent_at')->limit(30)->get()
                ->map(fn (MessageLog $m) => $m->only(['id', 'channel', 'notification', 'recipient', 'subject']) + ['sent_at' => $m->sent_at?->toIso8601String()]),
            'allTags' => Tag::query()->orderBy('name')->get(['id', 'name', 'slug', 'colour']),
            'staff' => User::query()->whereIn('role', ['staff', 'admin'])->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name']),
            'can' => ['createTag' => $request->user()->can('create', Tag::class)],
        ]);
    }

    public function storeNote(StoreNoteRequest $request, User $customer, CrmService $crm): RedirectResponse
    {
        Gate::authorize('viewCustomer', $customer);
        Gate::authorize('create', CustomerNote::class);

        $crm->addNote($customer, $request->validated('body'), $request->user());

        return back(303)->with('success', 'Note added.');
    }

    public function destroyNote(Request $request, CustomerNote $note, CrmService $crm): RedirectResponse
    {
        Gate::authorize('delete', $note);

        $crm->deleteNote($note, $request->user());

        return back(303)->with('success', 'Note removed.');
    }

    public function attachTag(Request $request, User $customer, Tag $tag, CrmService $crm): RedirectResponse
    {
        Gate::authorize('viewCustomer', $customer);
        Gate::authorize('attach', Tag::class);

        $crm->attachTag($customer, $tag, $request->user());

        return back(303);
    }

    public function detachTag(Request $request, User $customer, Tag $tag, CrmService $crm): RedirectResponse
    {
        Gate::authorize('viewCustomer', $customer);
        Gate::authorize('attach', Tag::class);

        $crm->detachTag($customer, $tag, $request->user());

        return back(303);
    }

    public function storeTag(StoreTagRequest $request, CrmService $crm): RedirectResponse
    {
        Gate::authorize('create', Tag::class);

        $tag = $crm->createTag($request->validated('name'), $request->validated('colour'), $request->user());

        return back(303)->with('success', "Tag \"{$tag->name}\" created.");
    }

    public function storeFollowUp(StoreFollowUpRequest $request, User $customer, CrmService $crm): RedirectResponse
    {
        Gate::authorize('viewCustomer', $customer);
        Gate::authorize('create', FollowUp::class);

        $crm->addFollowUp($customer, $request->validated(), $request->user());

        return back(303)->with('success', 'Follow-up added.');
    }

    /**
     * @return array<string, mixed>
     */
    public static function followUp(FollowUp $f): array
    {
        return [
            'id' => $f->id,
            'due_on' => $f->due_on->toDateString(),
            'note' => $f->note,
            'overdue' => $f->completed_at === null && $f->due_on->isBefore(today()),
            'done' => $f->completed_at !== null,
            'completed_at' => $f->completed_at?->toIso8601String(),
            'author' => $f->author?->name,
            'assignee' => $f->assignee?->name,
            'reservation' => $f->reservation?->reference_code,
            'customer' => $f->relationLoaded('customer') ? ['id' => $f->user_id, 'name' => $f->customer?->name] : null,
        ];
    }
}
