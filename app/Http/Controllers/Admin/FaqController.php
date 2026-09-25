<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FaqRequest;
use App\Http\Requests\Chatbot\AskRequest;
use App\Models\ChatbotFaq;
use App\Services\ChatbotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class FaqController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', ChatbotFaq::class);

        return Inertia::render('Admin/Faqs', [
            'faqs' => ChatbotFaq::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'question', 'answer', 'keywords', 'active', 'sort_order']),
            'placeholders' => ['{downpayment_percent}', '{pickup_days}', '{forfeit_refund_percent}', '{pending_minutes}', '{max_qty_per_item}', '{max_unpaid_reservations}'],
            'test' => session('faq_test'),
        ]);
    }

    public function store(FaqRequest $request): RedirectResponse
    {
        Gate::authorize('create', ChatbotFaq::class);

        ChatbotFaq::query()->create($request->validated() + ['active' => $request->boolean('active')]);

        return back(303)->with('success', 'FAQ added.');
    }

    public function update(FaqRequest $request, ChatbotFaq $faq): RedirectResponse
    {
        Gate::authorize('update', $faq);

        $faq->update($request->validated() + ['active' => $request->boolean('active')]);

        return back(303)->with('success', 'FAQ saved.');
    }

    public function destroy(ChatbotFaq $faq): RedirectResponse
    {
        Gate::authorize('delete', $faq);

        $faq->delete();

        return back(303)->with('success', 'FAQ deleted.');
    }

    /**
     * "Test a question": shows how every FAQ scores, so admins can tune keywords.
     */
    public function test(AskRequest $request, ChatbotService $bot): RedirectResponse
    {
        Gate::authorize('viewAny', ChatbotFaq::class);

        $question = $request->validated('question');

        return back(303)->with('faq_test', [
            'question' => $question,
            'result' => $bot->answer($question),
            'threshold' => ChatbotService::THRESHOLD,
            'scores' => $bot->rank($question)->take(5)->map(fn ($r) => ['id' => $r['faq']->id, 'question' => $r['faq']->question, 'score' => $r['score']])->values(),
        ]);
    }
}
