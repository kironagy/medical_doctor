# Debug Guide
## Medical Plus Mobile Application

Version: 1.0

Status: Active

---

# Purpose

This document defines the debugging methodology for the Medical Plus project.

Debugging is an investigation process.

It is not guessing.

Every bug must be approached methodically.

---

# Golden Rule

Never modify code before understanding why the bug exists.

Fix the cause.

Never fix only the symptom.

---

# Investigation Process

Every bug investigation must follow these steps:

1. Reproduce the issue.
2. Observe the behavior.
3. Collect evidence.
4. Trace the execution flow.
5. Identify the root cause.
6. Explain the root cause.
7. Propose the smallest possible fix.
8. Verify the fix.
9. Check for regressions.

Never skip steps.

---

# Step 1 — Reproduce

Before changing code:

Determine:

- Can the bug be reproduced?
- Is it consistent?
- Is it intermittent?
- What are the exact steps?

Document:

Expected Behavior

Actual Behavior

Reproduction Steps

---

# Step 2 — Collect Evidence

Never assume.

Collect evidence such as:

- Laravel logs
- NativePHP logs
- Browser console
- Network requests
- API responses
- HTTP status codes
- Stack traces
- Database records

Evidence always comes before conclusions.

---

# Step 3 — Trace the Flow

Understand the complete execution path.

Example:

User

↓

Vue Component

↓

Axios

↓

Laravel Controller

↓

Service

↓

Repository

↓

Database

↓

Response

↓

Vue Update

Never modify code before understanding where the flow breaks.

---

# Step 4 — Find the Root Cause

Ask:

Where does the bug actually originate?

Examples:

Incorrect state

Missing API response

Race condition

Validation failure

Incorrect lifecycle event

Authentication problem

Timing issue

Never assume the first suspicious line is the cause.

---

# Step 5 — Explain the Root Cause

Before coding,

write a short explanation.

Example:

"The patient list does not update because the component never refreshes its state after receiving the API response."

Not:

"The list is broken."

Be specific.

---

# Step 6 — Implement the Smallest Fix

Only change what is necessary.

Avoid:

Large refactoring

Architectural redesign

Changing unrelated modules

Adding unnecessary abstractions

The smallest correct fix is preferred.

---

# Step 7 — Verify

After implementing the fix:

Verify:

Original bug

Expected behavior

Regression risks

Related functionality

Never assume success.

Verify it.

---

# Debugging Principles

Never guess.

Never patch blindly.

Never rewrite code without understanding it.

Never add logging everywhere hoping to find the issue.

Always investigate first.

---

# Evidence Hierarchy

Trust evidence in this order:

1. Runtime behavior
2. Logs
3. API responses
4. Database state
5. Existing source code
6. Assumptions

Assumptions are the weakest form of evidence.

---

# Logging

Temporary debugging logs are allowed during investigation.

Before completing the task:

Remove all temporary logs.

Production code should remain clean.

---

# Multiple Possible Causes

If multiple causes exist:

List them.

Rank them by probability.

Investigate the highest probability first.

Do not modify code until the investigation supports a conclusion.

---

# When the Root Cause is Unknown

Do not guess.

Instead state:

- What is known.
- What is unknown.
- What additional evidence is required.

Waiting for evidence is preferable to implementing the wrong fix.

---

# Large Bugs

For complex bugs:

Break the investigation into smaller questions.

Example:

Authentication

↓

API

↓

Response

↓

Frontend

↓

Rendering

↓

UI Update

Solve one layer at a time.

---

# AI Investigation Format

Before writing code,

always produce the following report.

## Investigation

Problem

Expected Behavior

Actual Behavior

Evidence

Execution Flow

Root Cause

Risk Assessment

Proposed Fix

Only after this report may implementation begin.

---

# Completion

A debugging task is complete only when:

The root cause has been identified.

The bug is fixed.

No regressions were introduced.

The solution is documented.

---

# Final Principle

The fastest way to fix a bug is not to write code.

The fastest way is to understand why the bug exists.

Once the cause is understood,

the solution usually becomes obvious.
