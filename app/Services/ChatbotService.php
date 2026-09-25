<?php

namespace App\Services;

use App\Enums\SettingKey;
use App\Models\ChatbotFaq;
use Illuminate\Support\Collection;

/**
 * FAQ chatbot by keyword scoring (spec Phase 3: "cheap"). No external service, no AI, no state on
 * the server. Answers are plain text; placeholders like {pickup_days} use the live settings.
 */
class ChatbotService
{
    /** Minimum score for a confident answer. One keyword hit (3) is enough. */
    public const THRESHOLD = 3;

    private const KEYWORD_WEIGHT = 3;

    private const WORD_WEIGHT = 1;

    private const PHRASE_BONUS = 2;

    /** English and common Tagalog/Taglish filler words that carry no meaning for matching. */
    private const STOP_WORDS = [
        'a', 'an', 'the', 'is', 'are', 'am', 'do', 'does', 'did', 'can', 'could', 'i', 'me', 'my', 'you', 'your', 'we', 'our',
        'it', 'to', 'of', 'in', 'on', 'for', 'and', 'or', 'if', 'what', 'how', 'when', 'where', 'why', 'which', 'there', 'be',
        'with', 'this', 'that', 'please', 'po', 'ba', 'ng', 'na', 'sa', 'ang', 'mga', 'ko', 'ako', 'kayo', 'nyo', 'ninyo', 'lang',
        'pa', 'din', 'rin', 'yung', 'yun', 'naman', 'pwede', 'puwede', 'paano', 'ano', 'kung', 'may', 'meron', 'hi', 'hello',
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return array{matched: bool, answer: string|null, question: string|null, related: list<string>, handoff: bool}
     */
    public function answer(string $question): array
    {
        $ranked = $this->rank($question);
        $best = $ranked->first();

        if (! $best || $best['score'] < self::THRESHOLD) {
            return [
                'matched' => false,
                'answer' => null,
                'question' => null,
                'related' => $ranked->where('score', '>', 0)->take(2)->pluck('faq.question')->values()->all(),
                'handoff' => true,
            ];
        }

        return [
            'matched' => true,
            'answer' => $this->render($best['faq']->answer),
            'question' => $best['faq']->question,
            'related' => $ranked->skip(1)->where('score', '>=', self::THRESHOLD)->take(2)->pluck('faq.question')->values()->all(),
            'handoff' => false,
        ];
    }

    /**
     * Every active FAQ with its score, best first (ties keep the admin's sort order).
     *
     * @return Collection<int, array{faq: ChatbotFaq, score: int}>
     */
    public function rank(string $question): Collection
    {
        $normalised = $this->normalise($question);
        $words = $this->words($normalised);

        return ChatbotFaq::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ChatbotFaq $faq) => ['faq' => $faq, 'score' => $this->score($faq, $normalised, $words)])
            ->sortByDesc('score')
            ->values();
    }

    /**
     * @param  list<string>  $words
     */
    private function score(ChatbotFaq $faq, string $normalised, array $words): int
    {
        $score = 0;
        $padded = " {$normalised} ";

        foreach ($faq->keywordList() as $keyword) {
            $keyword = $this->normalise($keyword);

            if ($keyword === '') {
                continue;
            }

            // Multi-word keywords match as phrases; single words match whole words or a prefix
            // (so "refund" also matches "refunded", "refunds").
            if (str_contains($keyword, ' ')) {
                if (str_contains($padded, " {$keyword} ")) {
                    $score += self::KEYWORD_WEIGHT + self::PHRASE_BONUS;
                }
            } elseif (collect($words)->contains(fn (string $w) => $w === $keyword || (strlen($keyword) >= 4 && str_starts_with($w, $keyword)))) {
                $score += self::KEYWORD_WEIGHT;
            }
        }

        $faqWords = $this->words($this->normalise($faq->question));
        $score += count(array_intersect(array_unique($words), $faqWords)) * self::WORD_WEIGHT;

        return $score;
    }

    private function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * @return list<string>
     */
    private function words(string $normalised): array
    {
        if ($normalised === '') {
            return [];
        }

        return array_values(array_filter(
            explode(' ', $normalised),
            fn (string $w) => $w !== '' && ! in_array($w, self::STOP_WORDS, true),
        ));
    }

    /**
     * Fill {placeholders} with live settings. Output is plain text; the widget never renders HTML.
     */
    public function render(string $answer): string
    {
        $values = [];

        foreach (SettingKey::cases() as $key) {
            $values['{'.$key->value.'}'] = (string) $this->settings->int($key);
        }

        return strtr($answer, $values);
    }
}
