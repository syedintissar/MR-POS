<x-layouts.app title="Change password">
    <div class="mx-auto max-w-md">
        @if($forced)
            <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                You must choose a new password before you can continue.
            </div>
        @endif
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
                    @csrf @method('PUT')
                    <x-form.input name="current_password" label="Current password" type="password" required autocomplete="current-password" />
                    <x-form.input name="password" label="New password" type="password" required autocomplete="new-password"
                                  :help="'At least '.max(6, app(\App\Services\SettingsService::class)->int('security.password_min_length')).' characters.'" />
                    <x-form.input name="password_confirmation" label="Repeat new password" type="password" required autocomplete="new-password" />
                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-primary">Change password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-layouts.app>
