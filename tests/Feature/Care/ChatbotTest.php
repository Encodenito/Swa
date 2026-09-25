<?php

namespace Tests\Feature\Care;

use App\Enums\SettingKey;
use App\Models\ChatbotFaq;
use App\Models\ChatHandoff;
use App\Models\User;
use App\Services\ChatHandoffService;
use App\Services\SettingsService;
use Database\Seeders\ChatbotFaqSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChatbotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([SettingsSeeder::class, ChatbotFaqSeeder::class]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function questions(): array
    {
        return [
            'english cancel' => ['Can I cancel my reservation?', 'Can I cancel? Will I get a refund?'],
            'refund' => ['will i get my money back', 'Can I cancel? Will I get a refund?'],
            'taglish downpayment' => ['magkano po ang downpayment?', 'How much is the downpayment?'],
            'gcash' => ['pwede ba gcash?', 'How can I pay?'],
            'delivery' => ['Do you deliver to Cebu?', 'Do you deliver?'],
            'missed pickup' => ['what happens if i miss the deadline', 'What if I miss my pickup deadline?'],
            'id' => ['do i need an ID to claim', 'What do I need to bring at pickup?'],
        ];
    }

    #[DataProvider('questions')]
    public function test_it_answers_common_questions(string $question, string $expected): void
    {
        $this->postJson(route('chatbot.ask'), ['question' => $question])
            ->assertOk()
            ->assertJson(['matched' => true, 'question' => $expected, 'handoff' => false]);
    }

    public function test_unknown_questions_offer_a_person(): void
    {
        $this->postJson(route('chatbot.ask'), ['question' => 'What is the capital of France?'])
            ->assertOk()
            ->assertJson(['matched' => false, 'answer' => null, 'handoff' => true]);
    }

    public function test_answers_use_the_live_settings(): void
    {
        app(SettingsService::class)->set(SettingKey::PickupDays, 5);

        $answer = $this->postJson(route('chatbot.ask'), ['question' => 'when do I need to pick up'])->json('answer');

        $this->assertStringContainsString('within 5 days', $answer);
        $this->assertStringNotContainsString('{pickup_days}', $answer);
    }

    public function test_inactive_faqs_are_ignored(): void
    {
        ChatbotFaq::query()->where('question', 'Do you deliver?')->update(['active' => false]);

        $this->postJson(route('chatbot.ask'), ['question' => 'Do you deliver?'])->assertJson(['question' => null]);
    }

    public function test_answers_come_back_as_plain_text(): void
    {
        ChatbotFaq::query()->create(['question' => 'Promo?', 'answer' => '<script>alert(1)</script> No promo.', 'keywords' => 'promo', 'active' => true, 'sort_order' => 1]);

        $response = $this->postJson(route('chatbot.ask'), ['question' => 'any promo']);

        // JSON carries the text as-is; the widget renders it with text interpolation, never v-html.
        $response->assertHeader('content-type', 'application/json');
        $this->assertSame('<script>alert(1)</script> No promo.', $response->json('answer'));
    }

    public function test_questions_are_validated_and_throttled(): void
    {
        $this->postJson(route('chatbot.ask'), ['question' => str_repeat('a', 301)])->assertStatus(422);

        for ($i = 0; $i < 20; $i++) {
            $this->postJson(route('chatbot.ask'), ['question' => 'hello there']);
        }

        $this->postJson(route('chatbot.ask'), ['question' => 'hello there'])->assertStatus(429);
    }

    public function test_handoff_takes_the_user_from_the_session_not_the_request(): void
    {
        $victim = User::factory()->create();
        $visitor = User::factory()->create();

        $this->actingAs($visitor)->postJson(route('chatbot.handoff'), [
            'name' => 'Ana', 'contact' => '0917 123 4567', 'message' => 'Is the Acer laptop still available?',
            'user_id' => $victim->id,
            'transcript' => [['from' => 'user', 'text' => 'hi'], ['from' => 'bot', 'text' => 'hello']],
        ])->assertCreated();

        $handoff = ChatHandoff::sole();
        $this->assertSame($visitor->id, $handoff->user_id);
        $this->assertSame('09171234567', $handoff->contact);
        $this->assertCount(2, $handoff->transcript);
    }

    public function test_handoff_needs_a_reachable_contact_and_is_throttled(): void
    {
        $this->postJson(route('chatbot.handoff'), ['name' => 'A', 'contact' => 'not a contact', 'message' => 'hi there'])
            ->assertStatus(422)->assertJsonValidationErrors('contact');

        // The rejected attempt above counts too: 3 per 10 minutes in total.
        for ($i = 0; $i < 2; $i++) {
            $this->postJson(route('chatbot.handoff'), ['name' => 'A', 'contact' => 'a@example.com', 'message' => 'hi there'])->assertCreated();
        }

        $this->postJson(route('chatbot.handoff'), ['name' => 'A', 'contact' => 'a@example.com', 'message' => 'hi there'])->assertStatus(429);
    }

    public function test_exactly_one_staff_member_claims_a_handoff(): void
    {
        $handoff = app(ChatHandoffService::class)->create('Ana', 'a@example.com', 'Help', [], null);
        [$first, $second] = User::factory()->staff()->count(2)->create();

        $this->actingAs($first)->post(route('staff.handoffs.claim', $handoff))->assertSessionHas('success');
        $this->actingAs($second)->postJson(route('staff.handoffs.claim', $handoff))->assertStatus(409);

        // Only the claimer (or an admin) closes it.
        $this->actingAs($second)->postJson(route('staff.handoffs.close', $handoff), ['reason' => 'Called her'])->assertStatus(409);
        $this->actingAs($first)->post(route('staff.handoffs.close', $handoff), ['reason' => 'Called her back'])->assertSessionHas('success');

        $handoff->refresh();
        $this->assertSame('closed', $handoff->status);
        $this->assertSame($first->id, $handoff->claimed_by);
        $this->assertSame('Called her back', $handoff->reply_note);
    }

    public function test_only_admins_edit_the_faq_and_customers_cannot_see_handoffs(): void
    {
        $faq = ChatbotFaq::query()->first();
        $data = ['question' => 'Q?', 'answer' => 'A.', 'keywords' => 'q', 'active' => true, 'sort_order' => 1];

        $this->actingAs(User::factory()->staff()->create())->put(route('admin.faqs.update', $faq), $data)->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('staff.handoffs.index'))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())->put(route('admin.faqs.update', $faq), $data)->assertSessionHas('success');
        $this->assertSame('Q?', $faq->fresh()->question);
    }
}
