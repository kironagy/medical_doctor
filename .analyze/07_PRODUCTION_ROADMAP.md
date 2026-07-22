# Production Roadmap

## Executive Strategy

This roadmap is ordered by **impact on user experience and data integrity**. Each phase builds on the previous one. Do not skip phases.

---

## PHASE 1: STOP THE BLEEDING (Week 1)

**Goal**: Eliminate the random behavior. Make the app predictable.

### Day 1-2: Fix Data Visibility
| Task | Effort | Owner |
|------|--------|-------|
| T001: Fix 10-patient pagination → 100 | 30 min | Backend |
| T002: Remove 50-file slice, implement category lazy-loading | 4h | Backend + Frontend |
| T010: Fix sidebar PTR page reset | 15 min | Frontend |

### Day 2-3: Fix Offline Behavior
| Task | Effort | Owner |
|------|--------|-------|
| T003: SyncMiddleware saves locally before offline queuing | 3h | Backend |
| T004: Fix note/visit offline fallback (dead code) | 1h | Frontend |
| T007: Cap tracking Set sizes | 30 min | Frontend |

### Day 3-5: Fix Sync Integrity
| Task | Effort | Owner |
|------|--------|-------|
| T005: Eliminate duplicate sync enqueue (Observer vs HybridRepo) | 3h | Backend |
| T008: Create PatientObserver | 1h | Backend |
| T009: Cascade delete for patients | 2h | Backend |
| T011: Consolidate FullSyncService and SyncManager | 6h | Backend |

### Day 5: Validate
- Manual test: Create patient → appears immediately
- Manual test: Open patient with 100 files → all files visible
- Manual test: Offline create → persists in UI → syncs when online
- Manual test: Delete patient → files/notes also deleted on server

### Phase 1 Exit Criteria
- ✅ Patients appear immediately after creation
- ✅ All files visible (no 50 limit)
- ✅ Offline operations persist in UI
- ✅ No duplicate sync queue items
- ✅ Cascade delete works

---

## PHASE 2: STATE & ARCHITECTURE STABILITY (Week 2)

**Goal**: Fix the reactivity and data consistency issues.

### Day 1-2: Fix Reactive State
| Task | Effort | Owner |
|------|--------|-------|
| T014: Fix shallowRef → deep ref for workspaceData | 4h | Frontend |
| T006: Implement optimistic updates with rollback | 4h | Frontend |

### Day 2-3: Strengthen Sync
| Task | Effort | Owner |
|------|--------|-------|
| T013: Reduce lock TTL and add heartbeat | 2h | Backend |
| T012: Reduce network cache TTL | 30 min | Backend |
| T015: Soft-delete unreturned records after pull | 3h | Backend |

### Day 3-4: Performance
| Task | Effort | Owner |
|------|--------|-------|
| T017: Reduce periodic sync to 5 min | 15 min | Frontend |
| T016: Add scheduled queue cleanup | 30 min | Backend |

### Day 4-5: Testing & Bug Bash
| Task | Effort | Owner |
|------|--------|-------|
| Write integration tests for Create → Sync → Verify | 4h | QA |
| Write integration tests for Offline → Online → Sync | 4h | QA |
| Bug bash: test all reported scenarios | 4h | All |

### Phase 2 Exit Criteria
- ✅ Reactivity works reliably (no stale data in UI)
- ✅ Lock doesn't freeze sync for 5 minutes
- ✅ Sync is efficient (only changed records)
- ✅ Queue table doesn't grow unbounded
- ✅ Automated tests pass for critical workflows

---

## PHASE 3: OFFLINE-FIRST ARCHITECTURE (Week 3-4)

**Goal**: True offline-first where SQLite is the primary data source.

### Week 3: Architecture Transformation
| Task | Effort | Owner |
|------|--------|-------|
| Redesign data access: Vue reads from SQLite via local API | 5 days | Full stack |
| Move sync logic: Background sync as sole sync mechanism | 3 days | Backend |
| Remove HybridRepository complexity | 1 day | Backend |
| Implement proper conflict UI (user chooses on conflict) | 2 days | Frontend |

### Week 4: Polish
| Task | Effort | Owner |
|------|--------|-------|
| T020: Implement incremental sync | 2 days | Backend |
| T019: Migrate mobile controllers to repository pattern | 2 days | Backend |
| Add sync progress indicators to UI | 1 day | Frontend |
| End-to-end testing across all workflows | 2 days | QA |

### Phase 3 Exit Criteria
- ✅ Vue reads from local SQLite (not HTTP API)
- ✅ HTTP API only used for background sync
- ✅ App works identically online and offline
- ✅ Incremental sync reduces bandwidth
- ✅ Conflict resolution visible to user

---

## PHASE 4: PRODUCTION HARDENING (Week 5-6)

**Goal**: Performance, monitoring, and edge case handling.

### Week 5: Performance & Monitoring
| Task | Effort | Owner |
|------|--------|-------|
| Add performance monitoring (query times, sync durations) | 2 days | Backend |
| Implement lazy loading for files by category | 1 day | Frontend |
| Add image/video lazy loading | 1 day | Frontend |
| Profile SQLite query performance | 1 day | Backend |

### Week 6: Edge Cases & Polish
| Task | Effort | Owner |
|------|--------|-------|
| Handle token refresh failures gracefully | 1 day | Backend |
| Add queue health dashboard | 1 day | Backend |
| T018: Queue priority escalation | 1 day | Backend |
| T021: Transactional code generation | 1 day | Backend |
| Document sync architecture | 1 day | Documentation |

### Phase 4 Exit Criteria
- ✅ Performance benchmarks met (patient data loads in <500ms)
- ✅ Sync completes in <30 seconds for typical practice
- ✅ All error states handled gracefully
- ✅ Documentation complete

---

## PHASE 5: FUTURE (Post-Production)

### Medium-term Improvements
| Feature | Value | Effort |
|---------|-------|--------|
| WebSocket real-time sync | Instant updates | 2 weeks |
| Service Worker API caching | True PWA | 1 week |
| Server-side push notifications | Alert on new data | 1 week |
| Telehealth/video visit integration | New feature | 4 weeks |

### Never Rewrite
The following should NOT be rewritten:
1. Pull-to-refresh component — solid implementation
2. Error boundary and crash logging — comprehensive
3. Conflict resolver logic (concept) — LWW is practical for medical records
4. Sync queue dependency ordering — patients before child records
5. Token refresh with credential storage — proven pattern
6. Doctor isolation through global scopes — correct concept

---

## RISK REGISTER

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| Phase 1 fixes break existing behavior | Medium | High | Comprehensive testing per phase |
| Data loss during sync consolidation | Low | Critical | Backup SQLite before migration |
| Performance regression from lazy loading | Low | Medium | Benchmark before/after |
| Token refresh loop causes lockout | Low | High | Add max retry limit |
| Observers missed in cascade delete | Medium | High | Full test coverage for cascades |

---

## DEPLOYMENT CHECKLIST

### Pre-Production
- [ ] All Phase 1 tasks complete
- [ ] Integration tests pass for all CRUD workflows
- [ ] Offline → Online → Sync verified on Android device
- [ ] Performance benchmarks meet targets
- [ ] Queue cleanup scheduled
- [ ] Error monitoring configured

### Rollout
- [ ] Deploy backend first (no breaking API changes)
- [ ] Deploy frontend (compatible with old API)
- [ ] Monitor sync queue for failed items
- [ ] Watch for 401 errors (token refresh issues)
- [ ] Verify patient count consistency between mobile and web

### Post-Deployment (48h)
- [ ] Check error logs for new patterns
- [ ] Verify sync queue processes without stuck items
- [ ] Confirm no duplicate records created
- [ ] Test with 3+ concurrent users
