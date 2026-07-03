<div class="topbar">
    <div style="display: flex; align-items: center;">
        @if(!request()->routeIs('mobile.dashboard'))
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('mobile.dashboard') }}" class="topbar-back">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 19l-7-7 7-7"/></svg>
        </a>
        @endif
        <span class="topbar-title">{{ $pageTitle ?? 'MedicalPlus' }}</span>
    </div>
    <a href="{{ route('mobile.profile') }}" class="avatar" style="background: #ccfbf1; color: #14b8a6; width: 36px; height: 36px; font-size: 14px;">
        {{ strtoupper(substr($user['name'] ?? 'M', 0, 1)) }}
    </a>
</div>

<nav class="nav-bottom">
    <div class="nav-inner">
        <a href="{{ route('mobile.dashboard') }}" class="nav-item {{ request()->routeIs('mobile.dashboard') ? 'active' : '' }}">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Home
        </a>
        <a href="{{ route('mobile.patients.index') }}" class="nav-item {{ request()->routeIs('mobile.patients.*') ? 'active' : '' }}">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            Patients
        </a>
        <a href="{{ route('mobile.patients.create') }}" class="nav-item nav-add">
            <div class="nav-add-circle">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"><path d="M12 4v16m8-8H4"/></svg>
            </div>
            <span style="color: #14b8a6; margin-top: 4px;">Add</span>
        </a>
        <a href="{{ route('mobile.search') }}" class="nav-item {{ request()->routeIs('mobile.search') ? 'active' : '' }}">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            Search
        </a>
        <a href="{{ route('mobile.profile') }}" class="nav-item {{ request()->routeIs('mobile.profile') ? 'active' : '' }}">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Profile
        </a>
    </div>
</nav>
