<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $price
 * @property int|string|null $available From withAvailable()
 * @property string|null $current_sale_price From withAvailable(): clearance price of the batch that sells next
 */
class Item extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'brand',
        'model',
        'sku',
        'description',
        'price',
        'image',
        'low_stock_threshold',
        'active',
        'superseded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'active' => 'boolean',
            'low_stock_threshold' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Adds `available` (sum of batch quantities) and `current_sale_price` (clearance price of the
     * oldest batch with stock) in the same query, so lists never run per-item queries.
     */
    public function scopeWithAvailable(Builder $query): Builder
    {
        return $query
            ->withSum('batches as available', 'quantity_remaining')
            ->addSelect(['current_sale_price' => StockBatch::query()
                ->select('sale_price')
                ->whereColumn('item_id', 'items.id')
                ->where('quantity_remaining', '>', 0)
                ->orderBy('received_at')
                ->orderBy('id')
                ->limit(1),
            ]);
    }

    /**
     * Items shown in the public catalog: active, and not a replaced model that has sold out.
     */
    public function scopeListed(Builder $query): Builder
    {
        return $query
            ->where('active', true)
            ->where(fn (Builder $q) => $q
                ->whereNull('superseded_by_id')
                ->orWhereHas('batches', fn (Builder $b) => $b->where('quantity_remaining', '>', 0)));
    }

    public function availableQuantity(): int
    {
        return (int) ($this->available ?? $this->batches()->sum('quantity_remaining'));
    }

    /**
     * Price of the next unit sold: the clearance price of the oldest batch with stock, else the item price.
     * Requires withAvailable() for lists; falls back to a query for a single item.
     */
    public function currentPrice(): string
    {
        if (! array_key_exists('current_sale_price', $this->attributes)) {
            $this->attributes['current_sale_price'] = $this->batches()
                ->where('quantity_remaining', '>', 0)
                ->orderBy('received_at')->orderBy('id')
                ->value('sale_price');
        }

        return $this->attributes['current_sale_price'] ?? $this->price;
    }

    public function isOnClearance(): bool
    {
        return $this->currentPrice() !== $this->price && $this->attributes['current_sale_price'] !== null;
    }

    public function imageUrl(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }
}
