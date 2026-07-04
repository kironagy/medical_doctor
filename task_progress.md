# Audit & Fix: Remove Build-Time .env Dependencies

## Files Using `env('NATIVEPHP_APP_ID')` (must use NetworkStatusService::isOnline() instead)

- [ ] 1. `app/Providers/RepositoryServiceProvider.php` - Hybrid vs Eloquent binding decision
- [ ] 2. `app/Providers/AppServiceProvider.php` - Auth provider switching
- [ ] 3. `app/Http/Controllers/AuthController.php` - Login method selection
- [ ] 4. `app/Http/Controllers/Api/ChunkUploadController.php` - Multiple API proxy gates
- [ ] 5. `app/Http/Controllers/Api/UploadController.php` - API proxy gate
- [ ] 6. `app/Http/Controllers/Api/FileAccessController.php` - Multiple API proxy gates
- [ ] 7. `app/Services/ApiProxy.php` - isEnabled() method
- [ ] 8. `routes/api.php` - Conditional route registration

## Files Using `env('MOBILE_API_URL')` Directly (must use config('app.mobile_api_url'))

- [ ] 9. `app/Jobs/SyncPendingOperationsJob.php` - Direct env() call

## Verification

- [ ] 10. Verify all changes compile and are consistent
