<x-layouts.guest title="Sign in">
    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
        @csrf
        <div>
            <label for="username" class="label">Username</label>
            <input id="username" name="username" type="text" value="{{ old('username') }}" required autofocus autocomplete="username"
                   class="input @error('username') input-error @enderror">
            @error('username')<p class="error-text">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="password" class="label">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" class="input">
            @error('password')<p class="error-text">{{ $message }}</p>@enderror
        </div>
        <button type="submit" class="btn btn-primary w-full" data-loading-text="Signing in…">Sign in</button>
    </form>
</x-layouts.guest>
