@extends('layouts.auth')

@section('title', 'Create an account')

@section('content')
    <h1 class="auth-title">Set up your AI front desk</h1>
    <p class="auth-lede">It takes a minute. You can try everything in demo mode before connecting a phone line.</p>

    <form method="POST" action="{{ route('register') }}" class="form-stack" novalidate>
        @csrf
        <div class="field-row">
            <label class="field">
                <span>Your name</span>
                <input type="text" name="name" value="{{ old('name') }}" autocomplete="name" required autofocus @error('name') class="is-invalid" @enderror>
                @error('name')<em class="field-error">{{ $message }}</em>@enderror
            </label>
            <label class="field">
                <span>Business name</span>
                <input type="text" name="business_name" value="{{ old('business_name') }}" autocomplete="organization" placeholder="Bright Smile Dental" required @error('business_name') class="is-invalid" @enderror>
                @error('business_name')<em class="field-error">{{ $message }}</em>@enderror
            </label>
        </div>
        <label class="field">
            <span>Work email</span>
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required @error('email') class="is-invalid" @enderror>
            @error('email')<em class="field-error">{{ $message }}</em>@enderror
        </label>
        <div class="field-row">
            <label class="field">
                <span>Password</span>
                <input type="password" name="password" autocomplete="new-password" required minlength="8" @error('password') class="is-invalid" @enderror>
                @error('password')<em class="field-error">{{ $message }}</em>@enderror
            </label>
            <label class="field">
                <span>Repeat password</span>
                <input type="password" name="password_confirmation" autocomplete="new-password" required>
            </label>
        </div>
        <p class="field-hint">The AI introduces itself with your business name on every call.</p>
        <button type="submit" class="btn-call btn-full btn-lg">
            <x-icon name="spark" />
            <span>Create account</span>
        </button>
    </form>

    <p class="auth-switch">Already have an account? <a href="{{ route('login') }}">Sign in</a></p>
@endsection
