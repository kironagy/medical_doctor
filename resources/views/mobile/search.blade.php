@extends('mobile.layouts.app')

@section('title', 'Search - MedicalPlus')

@section('content')
@include('mobile.layouts.nav', ['pageTitle' => 'Search'])

<div class="page page-content">
    <form method="GET" action="{{ route('mobile.search') }}" class="search-bar">
        <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="text" name="q" class="input" placeholder="Search patients, files..." value="{{ $query }}" onchange="this.form.submit()" autofocus>
    </form>

    @if(isset($error))
    <div class="alert alert-error">{{ $error }}</div>
    @endif

    @if($query && strlen($query) >= 2)
        @forelse($results as $result)
        <a href="{{ ($result['type'] ?? '') === 'patient' ? route('mobile.patients.show', $result['id']) : '#' }}" class="list-item">
            <div style="width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px; flex-shrink: 0; background: {{ ($result['type'] ?? '') === 'patient' ? '#ccfbf1' : ($result['type'] === 'file' ? '#dbeafe' : '#f3e8ff') }};">
                @if(($result['type'] ?? '') === 'patient')
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#14b8a6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                @elseif(($result['type'] ?? '') === 'file')
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                @else
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                @endif
            </div>
            <div class="list-item-content">
                <div class="list-item-title">{{ $result['title'] ?? '' }}</div>
                <div class="list-item-subtitle">{{ $result['subtitle'] ?? '' }}</div>
            </div>
        </a>
        @empty
        <div class="empty-state">No results found for "{{ $query }}"</div>
        @endforelse
    @elseif($query)
    <div class="empty-state">Enter at least 2 characters to search</div>
    @else
    <div class="empty-state">Search patients, files, and doctors</div>
    @endif
</div>
@endsection
