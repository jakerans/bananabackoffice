<x-layouts::auth :title="__('Accept invitation')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Welcome to :app', ['app' => config('app.name')])" :description="__('Choose a password to finish setting up your account')" />

        <form method="POST" action="{{ route('invitation.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Token -->
            <input type="hidden" name="token" value="{{ $token }}">

            <!-- Email Address -->
            <flux:input
                name="email"
                :value="old('email', $email)"
                :label="__('Email')"
                type="email"
                required
                autocomplete="email"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autofocus
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="accept-invitation-button">
                    {{ __('Set password') }}
                </flux:button>
            </div>
        </form>
    </div>
</x-layouts::auth>
