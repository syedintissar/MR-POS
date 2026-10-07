<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'company', 'contact_person', 'phone', 'whatsapp', 'email', 'address', 'ntn', 'notes', 'is_active',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'opening_balance' => 'decimal:2', 'balance' => 'decimal:2'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'default_supplier_id');
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('company', 'like', $like)
            ->orWhere('phone', 'like', $like)->orWhere('code', 'like', $like)->orWhere('contact_person', 'like', $like));
    }

    /** A supplier with any history can only be deactivated, not deleted. Later phases add purchases here. */
    public function hasHistory(): bool
    {
        return $this->products()->withTrashed()->exists();
    }
}
