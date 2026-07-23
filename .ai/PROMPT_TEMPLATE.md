# AI Prompt Templates
## Medical Plus Mobile Application

Version: 1.0

Status: Active

---

# Purpose

This document provides standardized prompt templates for AI-assisted development.

These templates ensure that every AI session follows the project's architecture, roadmap, and engineering standards.

Every new AI conversation should begin with one of these templates.

---

# General Rules

Before starting any task:

Read:

- AI_CONSTITUTION.md
- PROJECT_CONTEXT.md
- ARCHITECTURE.md
- ROADMAP.md
- CODING_STANDARDS.md

Do not make assumptions.

Do not redesign the architecture.

Respect the active roadmap phase.

If the requested task conflicts with the roadmap,

explain the conflict before proposing code.

---

# Development Modes

The AI operates in one of two modes.

---

## Mode 1

# Analysis Mode (Default)

Purpose:

Understand the problem.

No code is allowed.

The AI must:

- Investigate
- Analyze
- Explain
- Identify risks
- Propose a solution

The AI must NOT write code.

Output Format:

Problem

Expected Behavior

Actual Behavior

Investigation

Root Cause

Proposed Solution

Risks

Wait for approval.

---

## Mode 2

# Implementation Mode

Only enter this mode after explicit approval.

Example:

Approved.

Implement the proposed solution.

Requirements:

Only implement the approved solution.

Do not introduce additional improvements.

Do not redesign unrelated code.

Keep changes minimal.

Output:

Files Changed

Implementation

Testing Performed

Remaining Risks

---

# Template — Bug Investigation

Task:

Investigate the following bug.

Do not write code.

Identify the root cause.

Explain the execution flow.

Collect evidence.

Propose the smallest production-ready solution.

Wait for approval before implementation.

---

# Template — Bug Fix

The investigation has already been approved.

Implement the proposed solution only.

Do not modify unrelated code.

Keep changes minimal.

Explain:

Files changed.

Testing performed.

Remaining risks.

---

# Template — New Feature

Implement the requested feature.

Before writing code:

Verify that it belongs to the active roadmap phase.

If not,

explain the conflict.

If yes,

implement only the requested scope.

Avoid feature creep.

---

# Template — Code Review

Review the provided implementation.

Check:

Architecture

Roadmap compliance

Security

Performance

Maintainability

Regression risks

Testing quality

Do not rewrite code.

Provide review comments only.

---

# Template — Architecture Review

Analyze the proposed architectural change.

Do not implement anything.

Explain:

Benefits

Risks

Alternatives

Roadmap impact

Long-term maintenance impact

Provide a recommendation.

---

# Template — Debug Session

Investigate the bug.

Trace the complete execution flow.

Follow DEBUG_GUIDE.md.

Never guess.

Never write code until the investigation is complete.

---

# Template — Performance Review

Analyze the implementation.

Identify real bottlenecks.

Avoid premature optimization.

Suggest improvements ranked by impact.

Do not rewrite working code.

---

# Template — Refactoring Review

Analyze whether refactoring is justified.

If existing code is stable,

recommend leaving it unchanged.

Refactoring requires technical justification.

---

# Template — Testing Review

Review the implementation using TESTING_GUIDE.md.

List:

Scenarios Tested

Missing Tests

Regression Risks

Recommendations

---

# Template — Documentation Update

Review whether documentation must be updated.

Check:

Architecture

Roadmap

Decisions

Coding Standards

Review Checklist

Update only affected documents.

---

# Session Rules

Every AI session should follow this workflow:

Read documentation

↓

Analyze

↓

Investigate

↓

Explain

↓

Wait for approval

↓

Implement

↓

Test

↓

Review

↓

Document

Never skip steps.

---

# Forbidden Behaviors

Never:

Guess

Rewrite working systems

Introduce future roadmap features

Add unnecessary abstractions

Implement unapproved architecture

Hide risks

Ignore previous decisions

---

# Final Principle

The AI is not expected to generate code as quickly as possible.

The AI is expected to make correct engineering decisions.

Correct decisions are more valuable than fast code generation.
