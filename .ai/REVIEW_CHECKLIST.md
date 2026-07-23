# Review Checklist
## Medical Plus Mobile Application

Version: 1.0

Status: Active

---

# Purpose

Every implementation must be reviewed before it is considered complete.

A task is not finished simply because the code compiles.

The implementation must satisfy this checklist.

---

# Rule

Never mark a task as completed until every applicable item below has been verified.

---

# 1. Requirements Review

□ Did I fully understand the requested task?

□ Did I avoid implementing features that were not requested?

□ Did I stay within the current roadmap phase?

□ Did I avoid future-phase functionality?

---

# 2. Root Cause Review

If this task fixes a bug:

□ Did I identify the actual root cause?

□ Did I verify the cause with evidence?

□ Did I avoid fixing only the symptom?

□ Can I clearly explain why the bug happened?

---

# 3. Architecture Review

□ Does the implementation respect the current architecture?

□ Did I avoid redesigning existing systems?

□ Did I preserve existing project structure?

□ Did I avoid introducing unnecessary abstractions?

---

# 4. Code Quality

□ Is the code easy to read?

□ Is the solution simple?

□ Are function names meaningful?

□ Are class responsibilities clear?

□ Is duplicated logic avoided?

□ Is dead code removed?

□ Are unnecessary comments avoided?

---

# 5. Scope Review

□ Did I modify only the required files?

□ Did I avoid unrelated refactoring?

□ Did I avoid changing existing APIs unnecessarily?

□ Did I preserve backward compatibility?

---

# 6. Error Handling

□ Are expected errors handled correctly?

□ Are exceptions meaningful?

□ Did I avoid hiding errors?

□ Are temporary debug statements removed?

---

# 7. Performance

□ Did I avoid unnecessary database queries?

□ Did I avoid unnecessary network requests?

□ Did I avoid unnecessary rendering?

□ Did I avoid premature optimization?

---

# 8. Security

□ Is user input validated?

□ Are authentication rules respected?

□ Are authorization rules preserved?

□ Is sensitive information protected?

---

# 9. Mobile Review

If NativePHP code was modified:

□ Does the WebView still behave correctly?

□ Are lifecycle events handled properly?

□ Does the implementation respect the current roadmap?

□ Did I avoid introducing offline infrastructure?

---

# 10. Frontend Review

If Vue code was modified:

□ Does the UI update correctly?

□ Are loading states handled?

□ Are error states handled?

□ Does the component remain simple?

---

# 11. Backend Review

If Laravel code was modified:

□ Is validation correct?

□ Is business logic in the correct layer?

□ Are controllers lightweight?

□ Are responses consistent?

---

# 12. Database Review

□ Were unnecessary schema changes avoided?

□ Is the database still the single source of truth?

□ Was duplicate storage avoided?

---

# 13. Testing Review

□ Was the implementation tested?

□ Was the happy path verified?

□ Were edge cases considered?

□ Were regression risks checked?

□ Does existing functionality still work?

---

# 14. Documentation Review

□ Does this change require updating documentation?

□ Does DECISIONS.md need a new entry?

□ Does ROADMAP.md need updating?

□ Does ARCHITECTURE.md need updating?

---

# 15. Final Self Review

Before marking the task complete:

Can I explain:

- What changed?
- Why it changed?
- What files changed?
- What risks remain?
- How it was tested?

If not,

the implementation is not complete.

---

# AI Completion Format

Every completed task should end with a summary similar to:

## Summary

Problem

Root Cause

Solution

Files Changed

Risks

Testing Performed

Remaining Work

---

# Completion Rule

A task is complete only when:

The requested feature works.

Existing functionality remains stable.

The implementation follows project standards.

The roadmap has been respected.

The code is production-ready.

Documentation has been updated when necessary.

---

# Final Principle

Working code is not enough.

Correct,

maintainable,

well-tested,

production-ready code

is the definition of completed work.
