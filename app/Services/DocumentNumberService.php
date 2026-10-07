<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free document numbers (INV-000123, JOB-000045 ...).
 *
 * Must be called inside the posting transaction: the sequence row is locked until commit,
 * so a rolled-back document returns its number instead of leaving a hole.
 */
class DocumentNumberService
{
    public function next(string $docType): string
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('DocumentNumberService::next() must be called inside a database transaction.');
        }

        /** @var DocumentSequence $seq */
        $seq = DocumentSequence::query()->where('doc_type', $docType)->lockForUpdate()->firstOrFail();

        $year = (int) now()->format('Y');
        if ($seq->reset_yearly && $seq->current_year !== $year) {
            $seq->current_year = $year;
            $seq->next_number = 1;
        }

        $number = $seq->next_number;
        $seq->next_number = $number + 1;
        $seq->save();

        $middle = $seq->reset_yearly ? now()->format('y').'-' : '';

        return $seq->prefix.$middle.str_pad((string) $number, $seq->padding, '0', STR_PAD_LEFT);
    }
}
