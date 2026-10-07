<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    protected $fillable = ['doc_type', 'label', 'prefix', 'next_number', 'padding', 'reset_yearly', 'current_year'];

    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
            'padding' => 'integer',
            'reset_yearly' => 'boolean',
            'current_year' => 'integer',
        ];
    }
}
