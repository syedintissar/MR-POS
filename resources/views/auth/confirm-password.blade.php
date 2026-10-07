<x-layouts.app title="Confirm your password">
    <div class="mx-auto max-w-md">
        <div class="card">
            <div class="card-body">
                <p class="mb-4 text-sm text-slate-600">This is a sensitive action. Please type your password again to continue. You will not be asked again for the next 10 minutes.</p>
                <form method="POST" action="{{ route('password.confirm') }}" class="space-y-4">
                    @csrf
                    <x-form.input name="password" label="Your password" type="password" required autofocus autocomplete="current-password" />
                    <div class="flex justify-end gap-2">
                        <a href="{{ url()->previous() }}" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-layouts.app>
