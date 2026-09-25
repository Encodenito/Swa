<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chatbot visitor asking for a person. Status changes only through ChatHandoffService.
 */
class ChatHandoff extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'transcript' => 'array',
            'claimed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }
}
