@php($editing = $user->exists)
<x-layouts.app :title="$editing ? 'Edit user' : 'New user'">
    <div class="mx-auto grid max-w-4xl gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <div class="card-header"><h2 class="card-title">{{ $editing ? $user->username : 'User details' }}</h2></div>
            <form method="POST" action="{{ $editing ? route('users.update', $user) : route('users.store') }}" class="card-body space-y-4">
                @csrf
                @if($editing) @method('PUT') @endif

                <x-form.input name="name" label="Full name" :value="$user->name" required />
                @unless($editing)
                    <x-form.input name="username" label="Username" required autocomplete="off" help="Used to log in. Letters, numbers, dot, - and _ only. Cannot be changed later." />
                @endunless
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-form.input name="email" label="Email (optional)" type="email" :value="$user->email" />
                    <x-form.input name="phone" label="Phone (optional)" :value="$user->phone" />
                </div>
                <x-form.select name="role" label="Role" :options="$roles->pluck('name', 'name')->all()" :value="$user->roleName()" required />
                @unless($editing)
                    <x-form.input name="password" label="Temporary password" type="password" required autocomplete="new-password"
                                  help="Tell the person this password. They must choose their own at first login." />
                @endunless
                <x-form.checkbox name="is_active" label="Account is active" :checked="$user->is_active" help="Disabled users cannot log in and are signed out immediately." />

                <div class="flex justify-end gap-2 pt-2">
                    <a href="{{ route('users.index') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">{{ $editing ? 'Save changes' : 'Create user' }}</button>
                </div>
            </form>
        </div>

        @if($editing)
            <div class="card self-start">
                <div class="card-header"><h2 class="card-title">Reset password</h2></div>
                <form method="POST" action="{{ route('users.reset-password', $user) }}" class="card-body space-y-3"
                      data-confirm="Reset the password for {{ $user->username }}? They will be signed out everywhere." data-confirm-label="Reset password" data-danger="1">
                    @csrf
                    <x-form.input name="password" label="New temporary password" type="password" required autocomplete="new-password" />
                    <p class="help">The person must choose a new password at next login.</p>
                    <button type="submit" class="btn btn-danger w-full">Reset password</button>
                </form>
            </div>
        @endif
    </div>
</x-layouts.app>
