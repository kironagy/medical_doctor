{{-- ⚠️ TEMPORARY DIAGNOSTIC VIEW — DELETE AFTER PHASE 0 ⚠️ --}}
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Media transport diagnostic</title>
  <style>
    body { font: 13px/1.5 -apple-system, system-ui, sans-serif; margin: 0; padding: 12px; background: #111; color: #eee; }
    h1 { font-size: 16px; margin: 0 0 12px; }
    a { color: #6cf; }
    .row { border: 1px solid #333; border-radius: 8px; padding: 10px; margin-bottom: 8px; }
    .row.bad { border-color: #833; }
    .meta { color: #999; font-size: 11px; word-break: break-all; }
    .tag { display: inline-block; padding: 1px 6px; border-radius: 4px; background: #234; font-size: 11px; margin-right: 4px; }
    .tag.missing { background: #533; }
  </style>
</head>
<body>
  <h1>Media transport diagnostic — {{ count($rows) }} newest files</h1>
  <p class="meta">Tap a file to run all four transports against it.</p>

  @forelse ($rows as $r)
    <div class="row {{ $r['on_disk'] ? '' : 'bad' }}">
      <div>
        <span class="tag {{ $r['on_disk'] ? '' : 'missing' }}">{{ $r['source'] }}</span>
        <span class="tag">{{ $r['mime_type'] ?: '?' }}</span>
        <span class="tag">{{ $r['sync_status'] ?: '?' }}</span>
      </div>
      <div><a href="/_native/diag/{{ $r['uuid'] }}">{{ $r['file_name'] ?: '(no name)' }}</a></div>
      <div class="meta">
        uuid={{ $r['uuid'] }}<br>
        remote_uuid={{ $r['remote_uuid'] ?: '—' }}<br>
        file_path={{ $r['file_path'] !== '' ? $r['file_path'] : '(empty)' }}<br>
        db_size={{ $r['size'] }} · disk_size={{ $r['real_size'] ?? '—' }}
      </div>
    </div>
  @empty
    <p>No patient_files rows found in the local database.</p>
  @endforelse
</body>
</html>
