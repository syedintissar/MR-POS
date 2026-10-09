@php use App\Support\Money; @endphp
<x-layouts.app title="Service types">
    <div class="mb-4 flex items-center justify-between gap-3">
        <p class="text-sm text-slate-600">The kinds of work you offer. A suggested charge is added to a new job when the box is ticked at intake. A type that has been used can be switched off, not deleted.</p>
        <a href="{{ route('service.types.create') }}" class="btn btn-primary">Add a service type</a>
    </div>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Name</th><th class="text-right">Suggested charge</th><th class="text-right">Jobs</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @foreach($types as $t)
                <tr class="{{ $t->is_active ? '' : 'text-slate-400' }}">
                    <td class="font-medium">{{ $t->name }}</td>
                    <td class="text-right">{{ $t->default_charge !== null ? Money::withSymbol($t->default_charge) : '—' }}</td>
                    <td class="text-right">{{ $t->jobs_count }}</td>
                    <td>@if($t->is_active)<span class="badge badge-green">on</span>@else<span class="badge badge-gray">off</span>@endif</td>
                    <td class="space-x-1 text-right">
                        <a href="{{ route('service.types.edit', $t) }}" class="btn btn-secondary btn-sm">Edit</a>
                        <form method="POST" action="{{ route('service.types.toggle', $t) }}" class="inline">@csrf<button class="btn btn-secondary btn-sm">{{ $t->is_active ? 'Switch off' : 'Switch on' }}</button></form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</x-layouts.app>
