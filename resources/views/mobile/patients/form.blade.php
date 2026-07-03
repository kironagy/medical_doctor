@extends('mobile.layouts.app')

@section('title', $pageTitle . ' - MedicalPlus')

@section('content')
@include('mobile.layouts.nav')

<div class="page page-content">
    <div class="card">
        <form method="POST" action="{{ $isEdit ? route('mobile.patients.update', $patient['uuid']) : route('mobile.patients.store') }}">
            @csrf
            @if($isEdit) @method('PUT') @endif

            <div class="input-group">
                <label class="input-label">Name *</label>
                <input type="text" name="name" class="input" value="{{ old('name', $patient['name'] ?? '') }}" required>
                @error('name') <div style="color: #ef4444; font-size: 12px; margin-top: 4px;">{{ $message }}</div> @enderror
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="input-group">
                    <label class="input-label">Code</label>
                    <input type="text" name="code" class="input" value="{{ old('code', $patient['code'] ?? '') }}">
                </div>
                <div class="input-group">
                    <label class="input-label">Phone</label>
                    <input type="text" name="phone" class="input" value="{{ old('phone', $patient['phone'] ?? '') }}">
                </div>
            </div>

            <div class="input-group">
                <label class="input-label">Email</label>
                <input type="email" name="email" class="input" value="{{ old('email', $patient['email'] ?? '') }}">
            </div>

            <div class="input-group">
                <label class="input-label">Address</label>
                <textarea name="address" class="input" rows="2">{{ old('address', $patient['address'] ?? '') }}</textarea>
            </div>

            <div class="input-group">
                <label class="input-label">Diagnosis</label>
                <textarea name="diagnosis" class="input" rows="2">{{ old('diagnosis', $patient['diagnosis'] ?? '') }}</textarea>
            </div>

            <div class="flex-row gap-3" style="margin-top: 20px;">
                <button type="submit" class="btn btn-primary flex-1">{{ $isEdit ? 'Update Patient' : 'Save Patient' }}</button>
                <a href="{{ route('mobile.patients.index') }}" class="btn btn-secondary flex-1" style="text-align: center;">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
