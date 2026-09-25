<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatbotFaq extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['question', 'answer', 'keywords', 'active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    public function keywordList(): array
    {
        return array_values(array_filter(array_map(
            fn (string $k) => mb_strtolower(trim($k)),
            explode(',', (string) $this->keywords),
        )));
    }
}
