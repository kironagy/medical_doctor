@extends('mobile.layouts.app')

@section('title', $patient['name'] ?? 'Patient - MedicalPlus')

@section('content')
@include('mobile.layouts.nav', ['pageTitle' => $patient['name'] ?? 'Patient'])

<div class="page page-content" x-data="{ tab: 'visits' }">
    <div class="card">
        <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 12px;">
            <div>
                <h2 style="font-size: 20px; font-weight: 700;">{{ $patient['name'] ?? 'Unknown' }}</h2>
                <p style="font-size: 13px; color: #6b7280;">{{ $patient['code'] ?? 'No code' }}</p>
            </div>
            <div class="flex-row">
                <a href="{{ route('mobile.patients.edit', $patient['uuid']) }}" class="btn-icon" title="Edit">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#14b8a6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </a>
            </div>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 14px;">
            <div><span style="color: #6b7280;">Phone:</span> {{ $patient['phone'] ?? '-' }}</div>
            <div><span style="color: #6b7280;">Email:</span> {{ $patient['email'] ?? '-' }}</div>
            <div style="grid-column: span 2;"><span style="color: #6b7280;">Diagnosis:</span> {{ $patient['diagnosis'] ?? '-' }}</div>
            <div style="grid-column: span 2;"><span style="color: #6b7280;">Address:</span> {{ $patient['address'] ?? '-' }}</div>
        </div>
    </div>

    <div class="flex-row" style="margin-bottom: 16px;">
        <button class="btn btn-primary btn-sm flex-1" onclick="document.getElementById('addVisitForm').style.display='block'">+ Visit</button>
        <button class="btn btn-secondary btn-sm flex-1" onclick="document.getElementById('addNoteForm').style.display='block'">+ Note</button>
        <button class="btn btn-secondary btn-sm flex-1" onclick="document.getElementById('addFileForm').style.display='block'">+ File</button>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab" :class="{ active: tab === 'visits' }" @click="tab = 'visits'">Visits</button>
        <button class="tab" :class="{ active: tab === 'notes' }" @click="tab = 'notes'">Notes</button>
        <button class="tab" :class="{ active: tab === 'files' }" @click="tab = 'files'">Files</button>
    </div>

    <!-- Visits Tab -->
    <div x-show="tab === 'visits'" class="fade-in">
        @forelse($visits as $visit)
        <div class="card" style="padding: 14px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div>
                    <div style="font-weight: 500;">{{ $visit['visit_type'] ?? 'Visit' }}</div>
                    <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">{{ $visit['visit_date'] ?? 'No date' }}</div>
                </div>
                @if(!empty($visit['cost']))
                <span style="font-size: 14px; font-weight: 600; color: #14b8a6;">${{ number_format($visit['cost'], 2) }}</span>
                @endif
            </div>
            @if(!empty($visit['diagnosis']))
            <p style="font-size: 13px; color: #4b5563; margin-top: 8px;">{{ $visit['diagnosis'] }}</p>
            @endif
            @if(!empty($visit['prescription']))
            <p style="font-size: 13px; color: #4b5563; margin-top: 4px;"><strong>Rx:</strong> {{ $visit['prescription'] }}</p>
            @endif
            <form method="POST" action="{{ route('mobile.visits.destroy', [$patient['uuid'], $visit['uuid']]) }}" style="margin-top: 8px;">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this visit?')">Delete</button>
            </form>
        </div>
        @empty
        <div class="empty-state">No visits recorded</div>
        @endforelse
    </div>

    <!-- Notes Tab -->
    <div x-show="tab === 'notes'" class="fade-in">
        @forelse($notes as $note)
        <div class="card" style="padding: 14px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                <span style="font-size: 12px; color: #6b7280;">
                    {{ isset($note['created_at']) ? \Carbon\Carbon::parse($note['created_at'])->format('M d, Y') : '' }}
                </span>
                <span class="badge badge-gray">{{ $note['category'] ?? 'general' }}</span>
            </div>
            <p style="font-size: 14px; color: #374151; white-space: pre-wrap;">{{ $note['content'] ?? '' }}</p>
            <div style="font-size: 12px; color: #9ca3af; margin-top: 8px;">
                by {{ $note['author']['name'] ?? 'Unknown' }}
            </div>
        </div>
        @empty
        <div class="empty-state">No notes</div>
        @endforelse
    </div>

    <!-- Files Tab -->
    <div x-show="tab === 'files'" class="fade-in">
        @forelse($files as $file)
        <div class="list-item" style="cursor: default;">
            <div class="file-icon" style="background: #f3f4f6; margin-right: 12px;">
                @if(($file['type'] ?? '') === 'image' && !empty($file['thumbnail_url']))
                <img src="{{ $file['thumbnail_url'] }}" alt="" loading="lazy" style="width: 100%; height: 100%; object-fit: cover;">
                @else
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                @endif
            </div>
            <div class="list-item-content">
                <div class="list-item-title">{{ $file['title'] ?? $file['file_name'] ?? 'File' }}</div>
                <div class="list-item-subtitle">{{ $file['type'] ?? '' }} @if(!empty($file['size'])) · {{ round($file['size'] / 1024) }}KB @endif</div>
            </div>
            <div class="flex-row">
                <a href="{{ route('mobile.files.download', $file['uuid']) }}" class="btn-icon" title="Download">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#14b8a6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </a>
                <form method="POST" action="{{ route('mobile.files.destroy', $file['uuid']) }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn-icon" title="Delete" onclick="return confirm('Delete this file?')">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                    </button>
                </form>
            </div>
        </div>
        @empty
        <div class="empty-state">No files</div>
        @endforelse
    </div>
</div>

<!-- Add Visit Modal -->
<div id="addVisitForm" style="display: none;" class="modal-overlay" onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-content">
        <div class="modal-title">Add Visit</div>
        <form method="POST" action="{{ route('mobile.visits.store', $patient['uuid']) }}">
            @csrf
            <div class="input-group">
                <label class="input-label">Visit Type *</label>
                <input type="text" name="visit_type" class="input" required>
            </div>
            <div class="input-group">
                <label class="input-label">Reason</label>
                <input type="text" name="reason" class="input">
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div class="input-group">
                    <label class="input-label">Date</label>
                    <input type="date" name="visit_date" class="input">
                </div>
                <div class="input-group">
                    <label class="input-label">Time</label>
                    <input type="time" name="visit_time" class="input">
                </div>
            </div>
            <div class="input-group">
                <label class="input-label">Diagnosis</label>
                <textarea name="diagnosis" class="input" rows="2"></textarea>
            </div>
            <div class="input-group">
                <label class="input-label">Prescription</label>
                <textarea name="prescription" class="input" rows="2"></textarea>
            </div>
            <div class="input-group">
                <label class="input-label">Cost</label>
                <input type="number" name="cost" class="input" step="0.01" min="0">
            </div>
            <div class="flex-row gap-3">
                <button type="submit" class="btn btn-primary flex-1">Save Visit</button>
                <button type="button" class="btn btn-secondary flex-1" onclick="document.getElementById('addVisitForm').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Note Modal -->
<div id="addNoteForm" style="display: none;" class="modal-overlay" onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-content">
        <div class="modal-title">Add Note</div>
        <form method="POST" action="{{ route('mobile.notes.store', $patient['uuid']) }}">
            @csrf
            <div class="input-group">
                <label class="input-label">Category</label>
                <input type="text" name="category" class="input" placeholder="general">
            </div>
            <div class="input-group">
                <label class="input-label">Content *</label>
                <textarea name="content" class="input" rows="4" required></textarea>
            </div>
            <div class="flex-row gap-3">
                <button type="submit" class="btn btn-primary flex-1">Save Note</button>
                <button type="button" class="btn btn-secondary flex-1" onclick="document.getElementById('addNoteForm').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Add File Modal -->
<div id="addFileForm" style="display: none;" class="modal-overlay" onclick="if(event.target===this)this.style.display='none'">
    <div class="modal-content">
        <div class="modal-title">Upload File</div>
        <form method="POST" action="{{ route('mobile.files.store', $patient['uuid']) }}" enctype="multipart/form-data">
            @csrf
            <div class="input-group">
                <label class="input-label">File *</label>
                <input type="file" name="file" class="input" required>
            </div>
            <div class="input-group">
                <label class="input-label">Title</label>
                <input type="text" name="title" class="input">
            </div>
            <div class="input-group">
                <label class="input-label">Category</label>
                <input type="text" name="category" class="input">
            </div>
            <div class="flex-row gap-3">
                <button type="submit" class="btn btn-primary flex-1">Upload</button>
                <button type="button" class="btn btn-secondary flex-1" onclick="document.getElementById('addFileForm').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endsection
