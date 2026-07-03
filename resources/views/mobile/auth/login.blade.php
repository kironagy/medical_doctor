@extends('mobile.layouts.app')

@section('title', 'Login - MedicalPlus')

@section('content')
<div class="page" style="min-height: 100vh; display: flex; align-items: center; justify-content: center;">
    <div style="width: 100%; max-width: 360px;">
        <div class="text-center mb-8">
            <div style="width: 64px; height: 64px; background: #14b8a6; border-radius: 18px; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
            </div>
            <h1 style="font-size: 24px; font-weight: 700; color: #111827;">MedicalPlus</h1>
            <p style="color: #6b7280; font-size: 14px; margin-top: 4px;">Sign in to your account</p>
        </div>

        <form method="POST" action="{{ route('mobile.login') }}">
            @csrf
            <div class="input-group">
                <label class="input-label">Email</label>
                <input type="email" name="email" class="input" value="{{ old('email') }}" placeholder="doctor@clinic.com" required autofocus>
            </div>
            <div class="input-group">
                <label class="input-label">Password</label>
                <input type="password" name="password" class="input" placeholder="Enter your password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block" style="padding: 14px;">Sign In</button>
        </form>
    </div>
</div>
@endsection
