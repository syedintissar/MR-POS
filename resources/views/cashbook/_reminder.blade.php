{{-- "Yesterday's drawer has not been counted": a reminder only, selling is never stopped. Shown on the dashboard and on the till. --}}
@php
    $unclosed = auth()->user()->canAny(['cashbook.close_day', 'pos.access']) ? app(\App\Services\CashBook\MoneyAccountService::class)->unclosedDrawers() : [];
@endphp
@foreach($unclosed as $drawer)
    <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900" role="status">
        <span class="font-bold">!</span>
        <span class="flex-1">{{ $drawer['name'] }} has not been counted since {{ \Illuminate\Support\Carbon::parse($drawer['since'])->format('d M Y') }}.
            @cannot('cashbook.close_day')Please tell the manager.@endcannot</span>
        @can('cashbook.close_day')<a href="{{ route('cashbook.closings.create', ['account' => $drawer['id']]) }}" class="font-semibold underline">Close the day</a>@endcan
    </div>
@endforeach
