<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    public const COLOURS = ['neutral', 'blue', 'green', 'amber', 'red', 'violet'];

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'slug', 'colour'];

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('created_by', 'created_at');
    }
}
