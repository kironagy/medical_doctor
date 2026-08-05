{{-- ⚠️ TEMPORARY DIAGNOSTIC VIEW — DELETE AFTER PHASE 0 ⚠️ --}}
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Transport test — {{ $file->file_name }}</title>
  <style>
    body { font: 13px/1.5 -apple-system, system-ui, sans-serif; margin: 0; padding: 12px; background: #111; color: #eee; }
    h1 { font-size: 15px; margin: 0 0 4px; word-break: break-all; }
    a { color: #6cf; }
    .meta { color: #999; font-size: 11px; word-break: break-all; margin-bottom: 12px; }
    .card { border: 1px solid #333; border-radius: 8px; padding: 10px; margin-bottom: 10px; }
    .card h2 { font-size: 13px; margin: 0 0 6px; }
    .verdict { font-weight: 700; }
    .pending { color: #cc0; }
    .ok { color: #4c4; }
    .fail { color: #f55; }
    img, video { max-width: 100%; max-height: 220px; display: block; background: #000; }
    pre { background: #000; padding: 8px; border-radius: 6px; font-size: 11px; overflow-x: auto; white-space: pre-wrap; word-break: break-all; }
  </style>
</head>
<body>
  <h1>{{ $file->file_name }}</h1>
  <div class="meta">
    <a href="/_native/diag">&larr; back</a><br>
    uuid={{ $file->uuid }} · source={{ $source }}<br>
    mime={{ $file->mime_type }} · db_size={{ $file->size }} · disk_size={{ $realSize ?? '—' }}<br>
    abs={{ $abs ?? '(not on disk)' }}
  </div>

  <div id="cards"></div>

  <div class="card">
    <h2>Summary (also written to laravel.log)</h2>
    <pre id="summary">running…</pre>
  </div>

<script>
const FILE = {
  uuid: @json($file->uuid),
  mime: @json($file->mime_type ?: ''),
  name: @json($file->file_name),
  size: @json($realSize),
  source: @json($source),
};
const STATIC_URL = @json($staticUrl);
const isVideo = FILE.mime.startsWith('video/');
const isImage = FILE.mime.startsWith('image/');

const results = {};
const cards = document.getElementById('cards');

function makeCard(mode) {
  const el = document.createElement('div');
  el.className = 'card';
  el.innerHTML = `<h2>${mode}</h2><div class="verdict pending" id="v-${mode}">pending…</div><div id="m-${mode}"></div>`;
  cards.appendChild(el);
  return el;
}

function verdict(mode, state, detail) {
  results[mode] = { state, detail };
  const el = document.getElementById('v-' + mode);
  if (el) {
    el.className = 'verdict ' + (state === 'ok' ? 'ok' : 'fail');
    el.textContent = state.toUpperCase() + (detail ? ' — ' + detail : '');
  }
  render();
}

function render() {
  document.getElementById('summary').textContent = JSON.stringify({ file: FILE, results }, null, 2);
}

// A transport "works" only if the media element actually decodes the bytes:
// naturalWidth for images, loadeddata for video. A 200 OK proves nothing here —
// that is exactly the failure mode we are chasing.
function testMedia(mode, url) {
  makeCard(mode);
  const host = document.getElementById('m-' + mode);
  const t0 = performance.now();
  let settled = false;
  const done = (state, detail) => {
    if (settled) return;
    settled = true;
    verdict(mode, state, detail + ` (${Math.round(performance.now() - t0)}ms)`);
  };

  if (isVideo) {
    const v = document.createElement('video');
    v.controls = true;
    v.preload = 'metadata';
    v.playsInline = true;
    v.src = url;
    v.onloadeddata = () => done('ok', `decoded ${v.videoWidth}x${v.videoHeight}, dur=${Math.round(v.duration)}s`);
    v.onerror = () => done('fail', 'video error code=' + (v.error && v.error.code));
    host.appendChild(v);
  } else {
    const img = new Image();
    img.onload = () => done(img.naturalWidth > 0 ? 'ok' : 'fail', `decoded ${img.naturalWidth}x${img.naturalHeight}`);
    img.onerror = () => done('fail', 'img error event');
    img.src = url;
    host.appendChild(img);
  }

  setTimeout(() => done('fail', 'timeout 20s — no load/error event'), 20000);
}

// Separately: does the HTTP layer even deliver the bytes? This distinguishes
// "server never sent the body" from "WebView got the body but wouldn't render".
async function probeBytes(mode, url) {
  try {
    const res = await fetch(url, { headers: { Range: 'bytes=0-1023' } });
    const buf = await res.arrayBuffer();
    return { status: res.status, contentLength: res.headers.get('content-length'), contentRange: res.headers.get('content-range'), received: buf.byteLength };
  } catch (e) {
    return { error: String(e) };
  }
}

async function run() {
  testMedia('stream', `/_native/diag/${FILE.uuid}/stream`);
  testMedia('binary', `/_native/diag/${FILE.uuid}/binary`);

  if (STATIC_URL) {
    testMedia('static', STATIC_URL);
  } else {
    makeCard('static');
    verdict('static', 'fail', 'could not publish a static copy');
  }

  makeCard('base64');
  try {
    const res = await fetch(`/_native/diag/${FILE.uuid}/base64`);
    const json = await res.json();
    const url = `data:${json.mime};base64,${json.data}`;
    const host = document.getElementById('m-base64');
    if (isVideo) {
      const v = document.createElement('video');
      v.controls = true; v.playsInline = true; v.src = url;
      v.onloadeddata = () => verdict('base64', 'ok', `decoded ${v.videoWidth}x${v.videoHeight}`);
      v.onerror = () => verdict('base64', 'fail', 'video error code=' + (v.error && v.error.code));
      host.appendChild(v);
    } else {
      const img = new Image();
      img.onload = () => verdict('base64', img.naturalWidth > 0 ? 'ok' : 'fail', `decoded ${img.naturalWidth}x${img.naturalHeight}`);
      img.onerror = () => verdict('base64', 'fail', 'img error event');
      img.src = url;
      host.appendChild(img);
    }
    setTimeout(() => { if (!results.base64) verdict('base64', 'fail', 'timeout 20s'); }, 20000);
  } catch (e) {
    verdict('base64', 'fail', 'fetch failed: ' + e);
  }

  results.probes = {
    stream: await probeBytes('stream', `/_native/diag/${FILE.uuid}/stream`),
    binary: await probeBytes('binary', `/_native/diag/${FILE.uuid}/binary`),
    static: STATIC_URL ? await probeBytes('static', STATIC_URL) : null,
  };
  render();

  // Give the media elements time to settle, then ship everything to the log.
  setTimeout(async () => {
    try {
      await fetch('/_native/diag/report', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ file: FILE, results, ua: navigator.userAgent }),
      });
      const el = document.getElementById('summary');
      el.textContent = '✅ reported to laravel.log\n\n' + el.textContent;
    } catch (e) {
      console.warn('report failed', e);
    }
  }, 22000);
}

window.addEventListener('error', (e) => console.warn('[DIAG] window error', e.message));
run();
</script>
</body>
</html>
