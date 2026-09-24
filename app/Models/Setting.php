<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read settings through App\Services\SettingsService (cached), not this model directly.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
    ];
}
