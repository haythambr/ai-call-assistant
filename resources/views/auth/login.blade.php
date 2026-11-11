@extends('layouts.auth')

@section('title', 'Sign in')

@section('content')
    <h1 class="auth-title">Welcome back</h1>
    <p class="auth-lede">Sign in to see today’s calls and campaigns.</p>

    @if ($demo)
        <button type="button" class="demo-card" data-demo-email="{{ $demo['email'] }}" data-demo-password="{{ $demo['password'] }}">
            <x-wave seed="demo" :bars="12" />
            <span>
                <strong>Try the demo account</strong>
                <small>Sample contacts and calls, no sign-up needed</small>
            </span>
            <x-icon name="arrow" />
        </button>
        <div class="or"><span>or sign in with your account</span></div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="form-stack" novalidate>
        @csrf
        <label class="field">
            <span>Email</span>
            <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus @error('email') class="is-invalid" aria-describedby="email-error" @enderror>
            @error('email')<em class="field-error" id="email-error">{{ $message }}</em>@enderror
        </label>
        <label class="field">
            <span>Password</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <label class="check">
            <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
            <span>Keep me signed in on this device</span>
        </label>
        <button type="submit" class="btn-call btn-full btn-lg">
            <x-icon name="phone" />
            <span>Sign in</span>
        </button>
    </form>

    <p class="auth-switch">New here? <a href="{{ route('register') }}">Create an account</a></p>
@endsection
