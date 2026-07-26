/**
 * Standalone test for refreshWorkspaceData() merge logic.
 *
 * Reproduces the EXACT merge function from useWorkspace.js
 * (lines 265-345 as of the fix commit) with mocked axios.
 *
 * Run with: node tests/refreshWorkspaceData.test.js
 */

import assert from 'node:assert';

// ── Exact merge logic from useWorkspace.js (post-fix) ────────────────────
function mergeWorkspaceData(productionResponse, workspaceSnapshot) {
    const merged = { ...productionResponse };
    if (workspaceSnapshot) {
        const serverNoteUuids = new Set((merged.notes || []).map(n => n.uuid));
        const localNotes = (workspaceSnapshot.notes || []).filter(
            n => !serverNoteUuids.has(n.uuid)
        );
        if (localNotes.length > 0) {
            merged.notes = [...localNotes, ...(merged.notes || [])];
        }
        const serverFileUuids = new Set((merged.files || []).map(f => f.uuid));
        const localFiles = (workspaceSnapshot.files || []).filter(
            f => !serverFileUuids.has(f.uuid)
        );
        if (localFiles.length > 0) {
            merged.files = [...localFiles, ...(merged.files || [])];
        }
const serverVisitUuids = new Set((merged.visits || []).map(v => v.uuid).filter(Boolean));
const localVisits = (workspaceSnapshot.visits || []).filter(
	v => v.uuid && !serverVisitUuids.has(v.uuid)
);
        if (localVisits.length > 0) {
            merged.visits = [...localVisits, ...(merged.visits || [])];
        }
        // Fallback to snapshot when production response is empty/missing
        if ((!merged.categories || merged.categories.length === 0) && workspaceSnapshot.categories) {
            merged.categories = workspaceSnapshot.categories;
        }
        if ((!merged.stats || Object.keys(merged.stats).length === 0) && workspaceSnapshot.stats) {
            merged.stats = workspaceSnapshot.stats;
        }
    }
    return merged;
}

// ── Simulated refreshWorkspaceData using the merge ──────────────────────
function simulateRefreshWorkspaceData(snapshot, productionResponse) {
    return mergeWorkspaceData(productionResponse, snapshot);
}

// ── Test Data ────────────────────────────────────────────────────────────

const pendingNote = {
    uuid: 'local-note-abc',
    patient_id: 5,
    author_id: 2,
    category: 'medical_history',
    content: 'Patient reported headache',
    sync_status: 'pending_create',
    created_at: '2026-07-26T02:39:06Z',
    updated_at: '2026-07-26T02:39:06Z',
};

const serverNote = {
    uuid: 'server-note-xyz',
    patient_id: 5,
    author_id: 2,
    category: 'general',
    content: 'Old note from production',
    sync_status: 'synced',
    created_at: '2026-07-25T10:00:00Z',
    updated_at: '2026-07-25T10:00:00Z',
};

const initialWorkspace = {
    notes: [pendingNote],
    files: [],
    visits: [],
    shares: [],
    categories: [
        { slug: 'medical_history', name: 'Medical History' },
        { slug: 'notes', name: 'Notes' },
    ],
    stats: { total_files: 0, files_count: 0 },
};

const productionResponse = {
    notes: [serverNote],
    files: [serverNote /* files array, same shape reference in test */],
    visits: [],
    shares: [],
    categories: [
        { slug: 'medical_history', name: 'Medical History' },
        { slug: 'notes', name: 'Notes' },
    ],
    stats: { total_files: 1, files_count: 1 },
};

// Fix productionResponse.files to be actual file objects
productionResponse.files = [
    { uuid: 'server-file-1', title: 'existing-file.pdf', sync_status: 'synced' },
];

const snapshot = JSON.parse(JSON.stringify(initialWorkspace));

// ── Tests ────────────────────────────────────────────────────────────────

let passed = 0;
let failed = 0;

function test(name, fn) {
    try {
        fn();
        passed++;
        console.log(`  ✓ ${name}`);
    } catch (e) {
        failed++;
        console.error(`  ✗ ${name}`);
        console.error(`    ${e.message}`);
    }
}

console.log('\n=== refreshWorkspaceData() Merge Logic Tests ===\n');

// TEST 1: Pending note is preserved after merge
test('pending_create note survives production fetch', () => {
    const result = simulateRefreshWorkspaceData(snapshot, productionResponse);
    const noteUuids = result.notes.map(n => n.uuid);
    assert.ok(
        noteUuids.includes('local-note-abc'),
        `Expected local note 'local-note-abc' in merged notes. Got: ${noteUuids.join(', ')}`
    );
});

// TEST 2: Pending note appears BEFORE server notes in merged array
test('local pending note is prepended (appears before server notes)', () => {
    const result = simulateRefreshWorkspaceData(snapshot, productionResponse);
    assert.strictEqual(result.notes.length, 2, `Expected 2 notes, got ${result.notes.length}`);
    assert.strictEqual(
        result.notes[0].uuid,
        'local-note-abc',
        `Expected local note first. Got: ${result.notes[0].uuid}`
    );
    assert.strictEqual(
        result.notes[1].uuid,
        'server-note-xyz',
        `Expected server note second. Got: ${result.notes[1].uuid}`
    );
});

// TEST 3: No duplicate notes if production already has the note
test('no duplicate if note exists on production', () => {
    const prodWithLocalNote = {
        ...productionResponse,
        notes: [pendingNote, serverNote], // production now includes our pending note (synced)
    };
    const result = simulateRefreshWorkspaceData(snapshot, prodWithLocalNote);
    const noteCount = result.notes.filter(n => n.uuid === 'local-note-abc').length;
    assert.strictEqual(
        noteCount, 1,
        `Expected exactly 1 instance of local note. Got: ${noteCount}`
    );
});

// TEST 4: Server notes are preserved
test('server notes are preserved in merge', () => {
    const result = simulateRefreshWorkspaceData(snapshot, productionResponse);
    const noteUuids = result.notes.map(n => n.uuid);
    assert.ok(
        noteUuids.includes('server-note-xyz'),
        `Expected server note 'server-note-xyz' in merged notes. Got: ${noteUuids.join(', ')}`
    );
});

// TEST 5: Non-note entity types (files) also preserved
test('local files survive production fetch', () => {
    const snapWithFile = {
        ...snapshot,
        files: [{ uuid: 'local-file-1', title: 'local upload', sync_status: 'pending_upload' }],
    };
    const prodWithoutFile = { ...productionResponse, files: [] };
    const result = simulateRefreshWorkspaceData(snapWithFile, prodWithoutFile);
    assert.ok(
        result.files.some(f => f.uuid === 'local-file-1'),
        `Expected local file 'local-file-1' preserved. Got: ${result.files.map(f => f.uuid).join(', ')}`
    );
});

// TEST 6: Categories are preserved from snapshot if production lacks them
test('categories preserved from snapshot when production has none', () => {
    const prodNoCats = { ...productionResponse, categories: [] };
    const result = simulateRefreshWorkspaceData(snapshot, prodNoCats);
    assert.ok(
        result.categories && result.categories.length > 0,
        `Expected categories preserved from snapshot. Got: ${JSON.stringify(result.categories)}`
    );
});

// TEST 7: Stats are preserved from snapshot if production lacks them
test('stats preserved from snapshot when production has none', () => {
    const prodNoStats = { ...productionResponse, stats: null };
    const result = simulateRefreshWorkspaceData(snapshot, prodNoStats);
    assert.ok(
        result.stats && typeof result.stats.total_files === 'number',
        `Expected stats preserved from snapshot. Got: ${JSON.stringify(result.stats)}`
    );
});

// TEST 8: Empty snapshot (null) — production response used as-is
test('null snapshot: production response used directly', () => {
    const result = simulateRefreshWorkspaceData(null, productionResponse);
    assert.strictEqual(
        result.notes.length,
        1,
        `Expected 1 server note with null snapshot. Got: ${result.notes.length}`
    );
    assert.strictEqual(
        result.notes[0].uuid,
        'server-note-xyz',
        'Server note should be first when no snapshot'
    );
});

// TEST 9: Empty workspace after refresh — no crash
test('empty arrays handled gracefully', () => {
    const emptyProd = { notes: [], files: [], visits: [], shares: [], categories: [], stats: {} };
    const result = simulateRefreshWorkspaceData(snapshot, emptyProd);
    assert.ok(Array.isArray(result.notes), 'notes should still be array');
    assert.ok(Array.isArray(result.files), 'files should still be array');
    assert.ok(Array.isArray(result.visits), 'visits should still be array');
});

// TEST 10: Deep clone — snapshot NOT mutated by merge
test('snapshot is not mutated by merge (deep clone)', () => {
    const snapshotClone = JSON.parse(JSON.stringify(snapshot));
    simulateRefreshWorkspaceData(snapshotClone, productionResponse);
    // After merge, snapshot's notes should still be [pendingNote] only
    assert.strictEqual(
        snapshotClone.notes.length, 1,
        'Snapshot should not be mutated by merge'
    );
    assert.strictEqual(
        snapshotClone.notes[0].uuid, 'local-note-abc',
        'Snapshot first note should still be local-note-abc'
    );
});

// ── Results ──────────────────────────────────────────────────────────────

console.log(`\n=== Results: ${passed} passed, ${failed} failed ===\n`);
if (failed > 0) {
    process.exit(1);
}
