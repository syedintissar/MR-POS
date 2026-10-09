@php use App\Support\Money; @endphp
<x-layouts.app :title="$expense->expense_no">
    <div class="mx-auto max-w-3xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="font-mono text-xs text-slate-500">{{ $expense->expense_no }}</div>
                <h2 class="text-xl font-semibold text-slate-900">{{ Money::withSymbol($expense->amount) }} · {{ $expense->category->name }}</h2>
                <div class="mt-1 text-sm text-slate-500">{{ $expense->expense_date->format('d M Y') }} · {{ $expense->method->name }}@if($expense->payee) · to {{ $expense->payee }}@endif · entered by {{ $expense->creator?->name ?? 'system' }}</div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if($expense->isVoided())<span class="badge badge-red text-sm">Cancelled</span>@else<span class="badge badge-green text-sm">Recorded</span>@endif
                @can('expenses.edit')@if($expense->isEditable())<a href="{{ route('expenses.edit', $expense) }}" class="btn btn-secondary btn-sm">Correct (today only)</a>@endif @endcan
                @if($expense->attachment_path)<a href="{{ route('expenses.attachment', $expense) }}" target="_blank" class="btn btn-secondary btn-sm" data-no-loading>Open the bill / receipt</a>@endif
            </div>
        </div>

        <div class="card card-body"><div class="text-xs font-semibold uppercase tracking-wide text-slate-500">What it was for</div><p class="mt-1 whitespace-pre-line text-sm text-slate-800">{{ $expense->description }}</p></div>

        @if($expense->isVoided())
            <div class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">Cancelled by {{ $expense->voider?->name }} on {{ $expense->voided_at->format('d M Y, H:i') }}: “{{ $expense->void_reason }}”. It no longer counts in any total.</div>
        @else
            @can('expenses.void')
                <form method="POST" action="{{ route('expenses.void', $expense) }}" class="card card-body space-y-3" data-confirm="Cancel expense {{ $expense->expense_no }}?" data-confirm-label="Cancel expense" data-danger="1">
                    @csrf
                    <h3 class="card-title">Entered by mistake?</h3>
                    <p class="text-sm text-slate-600">Cancelling keeps the record but removes it from every total. Then enter the correct expense.</p>
                    <x-form.input name="reason" label="Reason for cancelling" required />
                    <div><button class="btn btn-danger">Cancel this expense</button></div>
                </form>
            @endcan
        @endif

        <a href="{{ route('expenses.index') }}" class="inline-block text-sm">← All expenses</a>
    </div>
</x-layouts.app>
