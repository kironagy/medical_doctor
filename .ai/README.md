# Medical Plus AI Documentation

Welcome to the AI documentation for the Medical Plus Mobile project.

This directory contains the complete architectural documentation, engineering rules, development philosophy, and project roadmap that every AI assistant and developer must follow before making any change.

---

# Purpose

These documents exist to ensure that every code change:

- Respects the existing architecture.
- Avoids unnecessary complexity.
- Does not introduce unfinished features.
- Maintains production stability.
- Follows the current development phase.

The documentation inside this directory is considered the single source of truth for AI-assisted development.

---

# Before Writing Any Code

Every AI assistant MUST read the following documents in this order:

1. AI_CONSTITUTION.md
2. PROJECT_CONTEXT.md
3. ARCHITECTURE.md
4. ROADMAP.md
5. CODING_STANDARDS.md

Only after understanding these documents may implementation begin.

---

# Current Development Philosophy

The project follows a strict incremental development model.

Large architectural changes are forbidden.

Features are implemented one phase at a time.

Every phase must become completely stable before the next phase begins.

The goal is stability before functionality.

---

# Documentation Index

## AI_CONSTITUTION.md

Defines the permanent rules that must never be violated.

---

## PROJECT_CONTEXT.md

Explains what the project is, why previous architecture failed, and what the current objectives are.

---

## ARCHITECTURE.md

Describes the current production architecture.

Includes the data flow between the Mobile App, Vue, Laravel API, and MySQL.

---

## ROADMAP.md

Defines every project phase.

Specifies which phase is currently active.

Future phases must never be implemented early.

---

## CODING_STANDARDS.md

Defines implementation standards.

Naming conventions.

Code style.

Performance expectations.

Production rules.

---

## REVIEW_CHECKLIST.md

Checklist that must be completed before considering any task finished.

---

## DEBUG_GUIDE.md

Defines the debugging methodology.

Never guess.

Always investigate.

Always identify the root cause before changing code.

---

## TESTING_GUIDE.md

Defines how every feature must be tested before completion.

---

## PROMPT_TEMPLATE.md

Reusable prompt templates for AI-assisted development.

---

## DECISIONS.md

Historical log of architectural decisions.

Every major engineering decision must be documented here.

---

# Rules for AI Assistants

Every AI assistant working on this project must:

- Read this documentation first.
- Respect the current roadmap.
- Avoid redesigning working systems.
- Avoid introducing future features.
- Prefer simple production-ready solutions.
- Explain architectural risks before implementation.
- Never make assumptions without evidence.

---

# Rules for Developers

Developers should treat these documents as part of the project's source code.

If the architecture changes, the documentation must be updated first or alongside the implementation.

Documentation is not optional.

---

# Principle

Stable software is built through small, well-tested, incremental improvements.

Not through large rewrites.

This documentation exists to protect the project from unnecessary complexity.
