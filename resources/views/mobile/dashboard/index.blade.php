@extends('mobile.layouts.app')

@section('title', 'Dashboard - MedicalPlus')

@section('content')
@include('mobile.layouts.nav')

<div class="page page-content">
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-value">{{ number_format($stats['total_patients'] ?? 0) }}</div>
            <div class="stat-label">Patients</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{{ number_format($stats['recent_files'] ?? 0) }}</div>
            <div class="stat-label">Files</div>
        </div>
        @if(($stats['total_doctors'] ?? 0) > 0)
        <div class="stat-card">
            <div class="stat-value">{{ $stats['total_doctors'] }}</div>
            <div class="stat-label">Doctors</div>
        </div>
        @endif
        @if(($stats['active_doctors'] ?? 0) > 0)
        <div class="stat-card">
            <div class="stat-value">{{ $stats['active_doctors'] }}</div>
            <div class="stat-label">Active</div>
        </div>
        @endif
    </div>

    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
        <h2 style="font-size: 16px; font-weight: 600;">Recent Patients</h2>
        <a href="{{ route('mobile.patients.index') }}" style="font-size: 13px; color: #14b8a6; font-weight: 500;">View All</a>
    </div>

    @forelse($recentPatients as $patient)
    <a href="{{ route('mobile.patients.show', $patient['uuid'] ?? $patient['uuid']) }}" class="list-item">
        <div class="list-item-content">
            <div class="list-item-title">{{ $patient['name'] ?? 'Unknown' }}</div>
            <div class="list-item-subtitle">{{ $patient['code'] ?? $patient['phone'] ?? 'No code' }}</div>
        </div>
        <svg class="list-item-arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>
    </a>
    @empty
    <div class="empty-state">No patients yet</div>
    @endforelse
</div>
@endsection
