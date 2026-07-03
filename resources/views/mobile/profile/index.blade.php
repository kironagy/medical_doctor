@extends('mobile.layouts.app')

@section('title', 'Profile - MedicalPlus')

@section('content')
@include('mobile.layouts.nav', ['pageTitle' => 'Profile'])

<div class="page page-content">
    <div class="card text-center" style="padding: 24px;">
        <div class="avatar avatar-lg" style="background: #14b8a6; color: #fff; width: 64px; height: 64px; font-size: 24px; margin: 0 auto 12px;">
            {{ strtoupper(substr($user['name'] ?? 'M', 0, 1)) }}
        </div>
        <h2 style="font-size: 18px; font-weight: 700;">{{ $user['name'] ?? 'User' }}</h2>
        <p style="font-size: 14px; color: #6b7280;">{{ $user['email'] ?? '' }}</p>
        <p style="font-size: 12px; color: #9ca3af; margin-top: 4px;">{{ $user['role'] ?? '' }}</p>
    </div>

    <div x-data="{ tab: 'edit' }">
        <div class="tabs">
            <button class="tab" :class="{ active: tab === 'edit' }" @click="tab = 'edit'">Edit Profile</button>
            <button class="tab" :class="{ active: tab === 'password' }" @click="tab = 'password'">Password</button>
        </div>

        <div x-show="tab === 'edit'" class="fade-in">
            <div class="card">
                <form method="POST" action="{{ route('mobile.profile.update') }}">
                    @csrf @method('PUT')
                    <div class="input-group">
                        <label class="input-label">Name</label>
                        <input type="text" name="name" class="input" value="{{ old('name', $user['name'] ?? '') }}">
                    </div>
                    <div class="input-group">
                        <label class="input-label">Email</label>
                        <input type="email" name="email" class="input" value="{{ old('email', $user['email'] ?? '') }}">
                    </div>
                    <div class="input-group">
                        <label class="input-label">Phone</label>
                        <input type="text" name="phone" class="input" value="{{ old('phone', $user['phone'] ?? '') }}">
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Update Profile</button>
                </form>
            </div>
        </div>

        <div x-show="tab === 'password'" class="fade-in" style="display: none;">
            <div class="card">
                <form method="POST" action="{{ route('mobile.profile.password') }}">
                    @csrf @method('PUT')
                    <div class="input-group">
                        <label class="input-label">Current Password</label>
                        <input type="password" name="current_password" class="input" required>
                    </div>
                    <div class="input-group">
                        <label class="input-label">New Password</label>
                        <input type="password" name="new_password" class="input" required minlength="8">
                    </div>
                    <div class="input-group">
                        <label class="input-label">Confirm New Password</label>
                        <input type="password" name="new_password_confirmation" class="input" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Update Password</button>
                </form>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 16px;">
        <form method="POST" action="{{ route('mobile.logout') }}">
            @csrf
            <button type="submit" class="btn btn-danger btn-block">Sign Out</button>
        </form>
    </div>
</div>
@endsection
