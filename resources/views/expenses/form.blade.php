@php($editing = $expense->exists)
<x-layouts.app :title="$editing ? 'Correct '.$expense->expense_no : 'Record an expense'">
    <div class="mx-auto max-w-3xl">
        <form method="POST" action="{{ $editing ? route('expenses.update', $expense) : route('expenses.store') }}" enctype="multipart/form-data" class="card">
            @csrf
            @if($editing) @method('PUT') @endif
            <div class="card-header"><h2 class="card-title">{{ $editing ? $expense->expense_no.' · can be corrected today only' : 'Expense details' }}</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-2">
                <div><label class="label" for="expense_category_id">Category <span class="text-red-500">*</span></label>
                    <select id="expense_category_id" name="expense_category_id" class="input" required>
                        <option value="">Choose…</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected((int) old('expense_category_id', $expense->expense_category_id) === $c->id)>{{ $c->name }}</option>@endforeach
                    </select>@error('expense_category_id')<p class="error-text">{{ $message }}</p>@enderror</div>
                <x-form.input name="expense_date" label="Date" type="date" :value="$expense->expense_date?->toDateString()" :max="now()->toDateString()" required />
                <x-form.input name="amount" label="Amount (Rs.)" type="number" step="0.01" min="0.01" :value="$expense->amount" required />
                <div><label class="label" for="payment_method_id">Paid by <span class="text-red-500">*</span></label>
                    <select id="payment_method_id" name="payment_method_id" class="input" required>@foreach($methods as $m)<option value="{{ $m->id }}" @selected((int) old('payment_method_id', $expense->payment_method_id) === $m->id)>{{ $m->name }}</option>@endforeach</select></div>
                <x-form.input name="payee" label="Paid to" :value="$expense->payee" class="sm:col-span-2" help="Who received the money (a person, a company, the electricity board)" />
                <x-form.textarea name="description" label="What it was for" :value="$expense->description" rows="3" required class="sm:col-span-2" />
                <div class="sm:col-span-2"><label class="label" for="attachment">Bill or receipt (jpg, png or pdf, up to 5 MB)</label>
                    <input id="attachment" type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf" class="input">
                    @if($editing && $expense->attachment_path)<p class="help">Already attached: {{ $expense->attachment_name }}. Choosing a file replaces it.</p>@endif
                    @error('attachment')<p class="error-text">{{ $message }}</p>@enderror</div>
            </div>
            <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-3">
                <a href="{{ $editing ? route('expenses.show', $expense) : route('expenses.index') }}" class="btn btn-secondary">Cancel</a>
                <button class="btn btn-primary" data-loading-text="Saving…">{{ $editing ? 'Save the correction' : 'Record the expense' }}</button>
            </div>
        </form>
    </div>
</x-layouts.app>
