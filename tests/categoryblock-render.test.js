/**
 * Simulates the EXACT CategoryBlock.vue rendering path for notes.
 *
 * Traces: addNoteLocally() → allNotes → categoryNotes → notes → mergedCategoryItems
 *
 * Run with: node tests/categoryblock-render.test.js
 */

import assert from 'node:assert';

// ── Simulate shallowRef behavior ─────────────────────────────────────────
function createShallowRef(initial) {
    let value = initial;
    const subscribers = [];
    return {
        get value() { return value; },
        set value(v) {
            value = v;
            subscribers.forEach(fn => fn(v));
        },
        subscribe(fn) { subscribers.push(fn); },
    };
}

// ── Simulate addNoteLocally() from useWorkspace.js ───────────────────────
function addNoteLocally(workspaceData, note) {
    if (!note?.uuid) return;
    if (!workspaceData.value) {
        workspaceData.value = {
            files: [], notes: [note], visits: [], shares: [], categories: [], stats: {},
        };
        return;
    }
    if (!workspaceData.value.notes) workspaceData.value.notes = [];
    const existingIndex = workspaceData.value.notes.findIndex(n => n.uuid === note.uuid);
    if (existingIndex === -1) {
        workspaceData.value.notes = [note, ...workspaceData.value.notes];
    }
    workspaceData.value = { ...workspaceData.value };
}

// ── Simulate refreshWorkspaceData() merge from useWorkspace.js ────────────
function refreshWorkspaceDataMerge(workspaceData, productionResponse) {
    const workspaceSnapshot = workspaceData.value
        ? JSON.parse(JSON.stringify(workspaceData.value))
        : null;

    const merged = { ...productionResponse };
    if (workspaceSnapshot) {
        // Merge notes
        const serverNoteUuids = new Set((merged.notes || []).map(n => n.uuid));
        const localNotes = (workspaceSnapshot.notes || []).filter(n => !serverNoteUuids.has(n.uuid));
        if (localNotes.length > 0) {
            merged.notes = [...localNotes, ...(merged.notes || [])];
        }
        // Merge files
        const serverFileUuids = new Set((merged.files || []).map(f => f.uuid));
        const localFiles = (workspaceSnapshot.files || []).filter(f => !serverFileUuids.has(f.uuid));
        if (localFiles.length > 0) {
            merged.files = [...localFiles, ...(merged.files || [])];
        }
        // Merge visits (uuid-based)
        const serverVisitUuids = new Set((merged.visits || []).map(v => v.uuid).filter(Boolean));
        const localVisits = (workspaceSnapshot.visits || []).filter(v => v.uuid && !serverVisitUuids.has(v.uuid));
        if (localVisits.length > 0) {
            merged.visits = [...localVisits, ...(merged.visits || [])];
        }
        // Categories/stats fallback
        if ((!merged.categories || merged.categories.length === 0) && workspaceSnapshot.categories) {
            merged.categories = workspaceSnapshot.categories;
        }
        if ((!merged.stats || Object.keys(merged.stats).length === 0) && workspaceSnapshot.stats) {
            merged.stats = workspaceSnapshot.stats;
        }
    }
    workspaceData.value = merged;
}

// ── Simulate CategoryBlock.vue categoryNotes computed ─────────────────────
function computeCategoryNotes(allNotes, serverNotes, initialLoadDone, categorySlug) {
    const localNotes = allNotes.filter(n => n.category === categorySlug);
    if (initialLoadDone && serverNotes.length > 0) {
        const serverUuids = new Set(serverNotes.map(n => n.uuid));
        const newLocalNotes = localNotes.filter(n => !serverUuids.has(n.uuid));
        return newLocalNotes.length > 0 ? [...newLocalNotes, ...serverNotes] : serverNotes;
    }
    return localNotes;
}

// ── Simulate CategoryBlock.vue mergedCategoryItems computed ──────────────
function computeMergedCategoryItems(categoryFiles, categoryNotes) {
    const fileItems = (categoryFiles || []).map(f => ({ ...f, type: 'file' }));
    const noteItems = (categoryNotes || []).map(n => ({
        ...n,
        type: 'note',
        title: (n.content || '').replace(/<[^>]*>/g, '').slice(0, 30) || 'ملاحظة نصية',
    }));
    return [...fileItems, ...noteItems].sort(
        (a, b) => new Date(b.created_at || Date.now()) - new Date(a.created_at || Date.now())
    );
}

// ── Test Data ─────────────────────────────────────────────────────────────

const workspaceData = createShallowRef({
    files: [],
    notes: [],
    visits: [],
    shares: [],
    categories: [
        { slug: 'medical_history', name: 'Medical History' },
        { slug: 'notes', name: 'Notes' },
    ],
    stats: {},
});

const productionWorkspace = {
    notes: [
        {
            uuid: 'server-note-old',
            patient_id: 5,
            category: 'medical_history',
            content: 'Old note from production',
            sync_status: 'synced',
            created_at: '2026-07-25T10:00:00Z',
        },
    ],
    files: [],
    visits: [],
    shares: [],
    categories: [
        { slug: 'medical_history', name: 'Medical History' },
        { slug: 'notes', name: 'Notes' },
    ],
    stats: {},
};

const categorySlug = 'medical_history';
const serverNotes = [...productionWorkspace.notes];
const serverFiles = productionWorkspace.files;
const initialLoadDone = true;

// ── Test Runner ───────────────────────────────────────────────────────────

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

console.log('\n=== CategoryBlock Rendering Path Tests ===\n');

// TEST 1: Full lifecycle — create note → addNoteLocally → should be visible
test('note visible in category after addNoteLocally (no refresh)', () => {
    const pendingNote = {
        uuid: 'new-note-123',
        patient_id: 5,
        category: 'medical_history',
        content: 'New patient note',
        sync_status: 'pending_create',
        created_at: '2026-07-26T02:39:06Z',
    };

    // Step 1: User opens patient — workspace loaded from production
    workspaceData.value = JSON.parse(JSON.stringify(productionWorkspace));

    // Step 2: User creates a note → addNoteLocally adds it
    addNoteLocally(workspaceData, pendingNote);

    // Step 3: CategoryBlock's categoryNotes computed
    const allNotes = workspaceData.value?.notes || [];
    const categoryNotes = computeCategoryNotes(allNotes, serverNotes, initialLoadDone, categorySlug);

    // Step 4: mergedCategoryItems
    const items = computeMergedCategoryItems(serverFiles, categoryNotes);

    const noteCards = items.filter(i => i.type === 'note');
    assert.ok(
        noteCards.some(n => n.uuid === 'new-note-123'),
        `Expected new note in rendered items. Got: ${noteCards.map(n => n.uuid).join(', ')}`
    );
});

// TEST 2: Note survives refreshWorkspaceData() merge
test('note survives refreshWorkspaceData after production fetch', () => {
    // Setup: workspace with pending note
    workspaceData.value = {
        ...productionWorkspace,
        notes: [
            productionWorkspace.notes[0],
            {
                uuid: 'new-note-456',
                patient_id: 5,
                category: 'medical_history',
                content: 'Note that will survive refresh',
                sync_status: 'pending_create',
                created_at: '2026-07-26T02:39:06Z',
            },
        ],
    };

    // Simulate refreshWorkspaceData: production response WITHOUT the pending note
    const productionResponse = {
        notes: [...productionWorkspace.notes],
        files: [],
        visits: [],
        shares: [],
        categories: productionWorkspace.categories,
        stats: {},
    };

    refreshWorkspaceDataMerge(workspaceData, productionResponse);

    // After merge, check CategoryBlock rendering
    const allNotes = workspaceData.value?.notes || [];
    const categoryNotes = computeCategoryNotes(allNotes, serverNotes, initialLoadDone, categorySlug);
    const items = computeMergedCategoryItems(serverFiles, categoryNotes);
    const noteCards = items.filter(i => i.type === 'note');

    assert.ok(
        noteCards.some(n => n.uuid === 'new-note-456'),
        `Expected pending note after refresh merge. Got: ${noteCards.map(n => n.uuid).join(', ')}`
    );
});

// TEST 3: CatBlock note visible when initialLoadDone=true but serverNotes is empty
test('note visible when category has no server notes yet', () => {
    workspaceData.value = {
        notes: [{
            uuid: 'only-note-789',
            patient_id: 5,
            category: 'medical_history',
            content: 'Only note in empty category',
            sync_status: 'pending_create',
            created_at: '2026-07-26T02:39:06Z',
        }],
        files: [], visits: [], shares: [], categories: [], stats: {},
    };

    const emptyServerNotes = [];
    const allNotes = workspaceData.value?.notes || [];
    const categoryNotes = computeCategoryNotes(allNotes, emptyServerNotes, true, categorySlug);
    const items = computeMergedCategoryItems([], categoryNotes);

    assert.ok(
        items.some(i => i.type === 'note' && i.uuid === 'only-note-789'),
        `Expected note in empty category. Got: ${items.map(i => i.uuid || 'no-uuid').join(', ')}`
    );
});

// TEST 4: CategoryBlock merge doesn't duplicate notes already on server
test('no duplicate when server already has the note', () => {
    const syncedNote = {
        uuid: 'synced-note-abc',
        patient_id: 5,
        category: 'medical_history',
        content: 'Already synced',
        sync_status: 'synced',
        created_at: '2026-07-25T10:00:00Z',
    };

    workspaceData.value = {
        ...productionWorkspace,
        notes: [syncedNote], // Already in workspaceData
    };

    const serverUuids = new Set(serverNotes.map(n => n.uuid));
    serverUuids.add(syncedNote.uuid); // Server now has it too

    const allNotes = workspaceData.value?.notes || [];
    const categoryNotes = computeCategoryNotes(allNotes, serverNotes, initialLoadDone, categorySlug);
    const noteCards = categoryNotes.filter(n => n.type !== 'file');

    const dupeCount = noteCards.filter(n => n.uuid === 'synced-note-abc').length;
    assert.strictEqual(dupeCount, 1, `Expected 1 instance, got ${dupeCount}`);
});

// TEST 5: Fresh patient (no workspaceData loaded yet) — note visible immediately
test('note visible before workspaceData is loaded (addNoteLocally fallback)', () => {
    // Simulate: workspaceData is null (patient just opened, no data yet)
    const emptyWorkspace = createShallowRef(null);

    const pendingNote = {
        uuid: 'fresh-note-111',
        patient_id: 5,
        category: 'medical_history',
        content: 'Note before data loads',
        sync_status: 'pending_create',
        created_at: '2026-07-26T02:39:06Z',
    };

    // addNoteLocally with null workspaceData — creates new workspace
    addNoteLocally(emptyWorkspace, pendingNote);

    const allNotes = emptyWorkspace.value?.notes || [];
    const categoryNotes = computeCategoryNotes(allNotes, [], false, categorySlug);
    const items = computeMergedCategoryItems([], categoryNotes);

    assert.ok(
        items.some(i => i.type === 'note' && i.uuid === 'fresh-note-111'),
        `Expected note in freshly-created workspace. Got: ${items.map(i => i.uuid || 'no-uuid').join(', ')}`
    );
});

// TEST 6: CategoryBlock merge respects category slug
test('note only appears in its own category', () => {
    const physioNote = {
        uuid: 'physio-note',
        patient_id: 5,
        category: 'physiotherapy',
        content: 'Physio note',
        sync_status: 'pending_create',
        created_at: '2026-07-26T02:39:06Z',
    };

    workspaceData.value = {
        notes: [physioNote],
        files: [], visits: [], shares: [], categories: [], stats: {},
    };

    const result = computeCategoryNotes(workspaceData.value.notes, [], true, 'medical_history');
    assert.strictEqual(
        result.length, 0,
        `Expected 0 notes in medical_history category. Got: ${result.length} (${result.map(n => n.category).join(',')})`
    );

    const physioResult = computeCategoryNotes(workspaceData.value.notes, [], true, 'physiotherapy');
    assert.ok(
        physioResult.some(n => n.uuid === 'physio-note'),
        `Expected physio note in physiotherapy category. Got: ${physioResult.map(n => n.uuid).join(', ')}`
    );
});

// ── Results ───────────────────────────────────────────────────────────────

console.log(`\n=== Results: ${passed} passed, ${failed} failed ===\n`);
if (failed > 0) process.exit(1);
