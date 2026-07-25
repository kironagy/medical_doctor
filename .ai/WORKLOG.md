# WORKLOG

## 2026-07-25

### Fix: Note creation 500 error - column name and category fixes

1. **Bug: Wrong column names in resolvePatient()** - Changed 'first_name'/'last_name' to 'name' in all 3 NoteControllers to match the patients table schema.
2. **Bug: NULL category in NOT NULL column** - Changed '?? null' to '?? general' in Api/NoteController to match DB default.
3. **Bug: Missing sync_status migration on local SQLite** - Ran php artisan migrate --force.
4. **Deployed** via git push to remote, pulled on production.
5. **APK** - Built debug APK.
