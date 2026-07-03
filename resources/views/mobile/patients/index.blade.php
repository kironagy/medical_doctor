@extends('mobile.layouts.app')

@section('title', 'Patients - MedicalPlus')

@section('content')
@include('mobile.layouts.nav', ['pageTitle' => $pageTitle ?? 'Patients'])

<div class="page page-content">
    <form method="GET" action="{{ route('mobile.patients.index') }}" class="search-bar">
        <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="text" name="search" class="input" placeholder="Search patients..." value="{{ $search }}" onchange="this.form.submit()">
    </form>

    @forelse($patients as $patient)
    <a href="{{ route('mobile.patients.show', $patient['uuid']) }}" class="list-item">
        <div class="list-item-content">
            <div class="list-item-title">{{ $patient['name'] ?? 'Unknown' }}</div>
            <div class="list-item-subtitle">{{ $patient['code'] ?? $patient['phone'] ?? 'No contact' }}</div>
            @if(!empty($patient['diagnosis']))
            <div class="list-item-subtitle" style="color: #9ca3af;">{{ $patient['diagnosis'] }}</div>
            @endif
        </div>
        <svg class="list-item-arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>
    </a>
    @empty
    <div class="empty-state">No patients found</div>
    @endforelse

    @if($lastPage > 1)
    <div class="flex-row" style="justify-content: center; margin-top: 16px;">
        @if($currentPage > 1)
        <a href="{{ route('mobile.patients.index', ['page' => $currentPage - 1, 'search' => $search]) }}" class="btn btn-secondary btn-sm">Previous</a>
        @endif
        <span style="padding: 6px 12px; font-size: 13px; color: #6b7280;">Page {{ $currentPage }} of {{ $lastPage }}</span>
        @if($currentPage < $lastPage)
        <a href="{{ route('mobile.patients.index', ['page' => $currentPage + 1, 'search' => $search]) }}" class="btn btn-secondary btn-sm">Next</a>
        @endif
    </div>
    @endif
</div>
@endsection
