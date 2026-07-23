# AI Constitution
## Medical Plus Mobile Application

Version: 1.0

Status: Active

This document defines the permanent engineering principles for the Medical Plus Mobile project.

These rules are mandatory.

They override implementation preferences, optimization ideas, and AI-generated architectural suggestions.

Failure to follow these rules will lead to architectural inconsistency and project instability.

---

# 1. Core Mission

Your responsibility is to improve the existing project.

Your responsibility is NOT to redesign the project.

Preserve stability first.

Implement features second.

---

# 2. Single Source of Truth

The existing production architecture is the source of truth.

Never replace working architecture unless explicitly instructed.

Never assume that a different architecture is automatically better.

---

# 3. Simplicity First

Prefer the simplest production-ready solution.

Avoid:

- Overengineering
- Premature optimization
- Unnecessary abstractions
- Unused services
- Generic frameworks
- Enterprise patterns without clear value

Every additional layer increases maintenance cost.

---

# 4. Respect the Roadmap

The roadmap is mandatory.

Only implement the currently active phase.

Future phases are forbidden.

Example:

If the current phase is Phase 2,

do NOT implement:

- SQLite
- Offline CRUD
- Synchronization
- Background jobs
- Pending queue
- Conflict resolution

Even if they seem useful.

---

# 5. Never Jump Ahead

Never introduce infrastructure for future features.

Do not create code "for later."

Do not prepare architecture that is not currently required.

Future work will be implemented when its phase begins.

---

# 6. Preserve Working Code

Working code has priority.

Never rewrite working modules without a technical reason.

Bug fixes should be localized.

Avoid large refactoring unless explicitly requested.

---

# 7. Root Cause Before Code

Never guess.

Always investigate.

Before modifying code:

1. Understand the problem.
2. Identify the root cause.
3. Explain the root cause.
4. Explain the solution.
5. Only then modify code.

Never fix symptoms.

Fix causes.

---

# 8. Evidence-Based Development

Assumptions are forbidden.

Base every conclusion on evidence such as:

- Existing source code
- Runtime logs
- Stack traces
- API responses
- Browser console
- NativePHP logs

If evidence is missing,

say that evidence is required.

Do not invent explanations.

---

# 9. Minimal Changes

Every implementation should modify the smallest amount of code necessary.

Avoid touching unrelated files.

Avoid changing APIs unless required.

Avoid changing interfaces without reason.

---

# 10. Backward Compatibility

Existing behavior should continue to work.

Do not introduce breaking changes unless explicitly approved.

---

# 11. Production First

Every implementation must be production-ready.

Temporary code is forbidden.

Avoid:

TODO comments

Debug-only logic

Experimental implementations

Dead code

Unused helpers

Unused dependencies

---

# 12. Performance

Optimize only when necessary.

Never sacrifice readability for micro-optimizations.

Prefer:

Readable code

Maintainable code

Predictable behavior

Only optimize confirmed bottlenecks.

---

# 13. Architecture Ownership

You are maintaining an existing architecture.

You are not designing a new system.

Respect:

Directory structure

Coding style

Existing conventions

Project philosophy

---

# 14. Communication

Before implementation:

Explain:

- What is wrong
- Why it happens
- What will change
- Risks
- Expected result

Never start coding immediately.

---

# 15. If Requirements Conflict

If the user's request conflicts with:

Architecture

Roadmap

Current phase

Existing production behavior

Stop.

Explain the conflict first.

Do not silently implement it.

---

# 16. AI Behavior

The AI must:

Read the project documentation first.

Never ignore previous architectural decisions.

Never recommend technologies outside the roadmap.

Never redesign completed modules.

Never create unnecessary complexity.

---

# 17. Completion Criteria

A task is considered complete only if:

The requested feature works.

No existing functionality is broken.

Code follows project standards.

No unnecessary complexity was introduced.

The implementation matches the active roadmap phase.

---

# 18. AI Work Log

Every AI assistant is required to maintain the project work log.

Before marking any task as completed, the AI MUST append a new entry to:

.ai/WORKLOG.md

The entry must include:

- Date
- Time
- AI Model
- Task
- Status
- Files Changed
- Changes Made
- Reason
- Related Issue (if applicable)
- Risks
- Testing Performed
- Documentation Updated

Rules:

- Never overwrite previous entries.
- Never delete history.
- Always append new entries at the end of the file.
- Every completed implementation must have a corresponding work log entry.
- If no code was changed, record the investigation and explain why no implementation was made.
- If a task is abandoned, cancelled, or rejected, record the reason.
- The work log is part of the project's documentation and is considered mandatory.

The task is not considered complete until the work log has been updated.

# Final Principle

This project values:

Stability over speed.

Clarity over cleverness.

Simplicity over complexity.

Incremental improvement over large rewrites.

Every change should make the project easier to maintain—not harder.
