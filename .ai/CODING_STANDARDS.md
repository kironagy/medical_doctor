# Coding Standards
## Medical Plus Mobile Application

Version: 1.0

Status: Active

---

# Purpose

This document defines the coding standards for the Medical Plus project.

Every implementation must follow these standards.

Consistency is more important than personal preference.

---

# General Principles

Write code for humans first.

Code is read far more often than it is written.

Prioritize:

- Readability
- Simplicity
- Maintainability
- Predictability

Avoid clever code.

---

# Simplicity

Always choose the simplest production-ready implementation.

Do not introduce new abstractions unless they solve a real problem.

Avoid creating layers that are not currently needed.

---

# Existing Code

Respect the existing project style.

Match:

- Naming conventions
- File organization
- Coding patterns

Do not rewrite existing code only for stylistic reasons.

---

# Scope

Every change should solve one problem.

Avoid mixing:

- Bug fixes
- Refactoring
- Feature development

in the same implementation whenever possible.

---

# File Modifications

Modify the smallest number of files necessary.

Avoid touching unrelated code.

Large changes increase regression risk.

---

# Naming

Names should clearly describe purpose.

Good:

PatientController

PatientService

UploadFileAction

Bad:

Manager

Helper

Util

DataProcessor

Avoid vague names.

---

# Functions

Functions should have one responsibility.

Prefer small functions over large ones.

Avoid deeply nested logic.

Return early when appropriate.

---

# Classes

Each class should have a clear responsibility.

Avoid "God Classes."

Avoid classes that handle unrelated concerns.

---

# Comments

Code should explain itself.

Only write comments when they provide context that the code cannot.

Do not explain obvious code.

Avoid outdated comments.

---

# Dead Code

Dead code is forbidden.

Remove:

Unused methods

Unused imports

Unused variables

Commented-out implementations

Experimental code

---

# Error Handling

Handle expected errors gracefully.

Never hide exceptions.

Avoid empty catch blocks.

Error messages should help identify the problem.

---

# Logging

Logs should be meaningful.

Avoid excessive logging.

Never leave temporary debug logs in production code.

---

# Performance

Optimize only proven bottlenecks.

Never sacrifice readability for tiny optimizations.

Measure before optimizing.

---

# Dependencies

Avoid adding new dependencies unless absolutely necessary.

Before introducing a package, ask:

Can the existing framework already solve this?

---

# Security

Never trust client input.

Validation belongs on the backend.

Avoid exposing sensitive information.

Do not bypass authentication.

Do not disable authorization checks.

---

# API

Do not change API contracts without approval.

Avoid breaking existing clients.

Maintain backward compatibility whenever possible.

---

# Frontend

Vue components should focus on:

Rendering

User interaction

Presentation

Business rules should remain on the backend whenever possible.

---

# Mobile

NativePHP code should remain focused on:

WebView lifecycle

Native integrations

Platform-specific behavior

Business logic should not move into the mobile layer.

---

# Database

The backend database is the source of truth.

Avoid duplicate storage.

Avoid unnecessary data duplication.

---

# Testing

Every implementation should be testable.

Code should be deterministic.

Avoid hidden side effects.

---

# Pull Request Expectations

Every implementation should clearly explain:

Problem

Root cause

Solution

Files changed

Risks

Testing performed

---

# AI Expectations

Before writing code:

Understand the request.

Read the relevant files.

Understand existing implementation.

Prefer extending existing code over replacing it.

Do not invent architecture.

Do not assume missing behavior.

Ask for clarification if required.

---

# Quality Checklist

Before considering a task complete, verify:

✓ Code is readable.

✓ Existing behavior is preserved.

✓ No unnecessary files were modified.

✓ No dead code exists.

✓ No debug code remains.

✓ No unnecessary abstractions were introduced.

✓ The implementation follows the current roadmap.

✓ The solution is production-ready.

---

# Final Principle

Good code is not the most clever code.

Good code is the code that another developer can understand, maintain, and safely extend months later.
