<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A cart set aside at the counter. Not a document: no number, no stock reserved, deleted when resumed or discarded. */
class HeldSale extends Model
{
    protected $fillable = ['user_id', 'customer_id', 'label', 'cart'];

    protected function casts(): array
    {
        return ['cart' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
