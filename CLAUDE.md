# Medical Plus Development Rules

## Code Search

Always use Serena before reading files.

Priority:

1. find_symbol
2. find_referencing_symbols
3. get_symbols_overview
4. search_for_pattern

Never read entire files unless absolutely necessary.

Only read the implementation of the symbol being modified.

---

## Documentation

Always use Context7 for:

- Laravel
- Vue
- NativePHP Mobile
- Capacitor
- Android
- SQLite
- Inertia
- PHP

Never rely on outdated memory if Context7 has documentation.

---

## Editing

Prefer symbol editing instead of file editing.

Avoid rewriting complete files.

Modify only affected methods.

---

## Performance

Minimize token usage.

Avoid loading unrelated files.

Avoid duplicate analysis.

Reuse previous findings.

---

## Architecture

Respect Offline First architecture.

Never introduce automatic synchronization.

Synchronization must happen ONLY when the user presses:

Settings
→ Sync Data
→ Sync Now

Never trigger sync automatically.

---

## Database

SQLite is the source of truth while offline.

Never overwrite pending local changes.

Never delete unsynced records.

Always use transactions.

---

## Code Quality

No TODO

No FIXME

No placeholder implementation.

No fake data.

No mock services.

No duplicated code.

Keep architecture modular.

---

## Before finishing

Always run

composer dump-autoload

php artisan optimize:clear

php artisan test

If Android project changed:

Build Release APK

Install using ADB

Verify logs using adb logcat
