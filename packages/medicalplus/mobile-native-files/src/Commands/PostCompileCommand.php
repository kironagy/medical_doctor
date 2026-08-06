<?php

namespace MedicalPlus\NativeFiles\Commands;

use Illuminate\Console\Command;

/**
 * Allow cleartext HTTP to the loopback address the media server binds to.
 *
 * Android's network security config, which NativePHP copies out of its own
 * package on every build, whitelists cleartext for 127.0.0.1 and localhost
 * only. The media server deliberately binds 127.0.0.2 instead, because the
 * WebView's request router hijacks the literal host 127.0.0.1 and answers
 * from the embedded Laravel (see LocalMediaServer). Without this entry the
 * platform blocks the request before it is ever made — which is exactly why
 * playback worked in debug (android:debuggable bypasses the policy) and
 * silently failed in release.
 *
 * Runs as a post_compile hook so the edit is reapplied to the freshly copied
 * file on every build, instead of being hand-made inside nativephp/android
 * where it would be destroyed by the next one.
 */
class PostCompileCommand extends Command
{
    // PluginHookRunner passes every hook the same set of options, so they all
    // have to be declared here or Artisan::call() throws before the command
    // body ever runs ("The --platform option does not exist"), and the build
    // just logs the failure and carries on with an unpatched config.
    // --build-path is the authoritative android project path for this run;
    // base_path() is only the fallback.
    protected $signature = 'nativephp:native-files:post-compile
        {--platform= : Target platform for this build}
        {--build-path= : Path of the generated native project}
        {--plugin-path= : Path of this plugin}
        {--app-id= : Application id being built}
        {--config= : JSON build config}
        {--plugins= : JSON list of active plugins}';

    protected $description = 'Permit cleartext traffic to the loopback media server';

    private const LOOPBACK_HOST = '127.0.0.2';

    public function handle(): int
    {
        if (($this->option('platform') ?: 'android') !== 'android') {
            return self::SUCCESS;
        }

        $buildPath = $this->option('build-path') ?: base_path('nativephp/android');
        $path = rtrim($buildPath, '/') . '/app/src/main/res/xml/network_security_config.xml';

        if (!file_exists($path)) {
            $this->warn("[native-files] network_security_config.xml not found; skipping");

            return self::SUCCESS;
        }

        $xml = file_get_contents($path);

        if (str_contains($xml, self::LOOPBACK_HOST)) {
            return self::SUCCESS;
        }

        // Add the host to the existing cleartext-permitted domain-config
        // rather than appending a second block, so there is one place that
        // says which hosts may be reached over plain HTTP.
        $anchor = '<domain includeSubdomains="true">127.0.0.1</domain>';
        if (!str_contains($xml, $anchor)) {
            $this->warn('[native-files] unexpected network_security_config.xml layout; skipping');

            return self::SUCCESS;
        }

        $replacement = $anchor . "\n        "
            . '<domain includeSubdomains="true">' . self::LOOPBACK_HOST . '</domain>';

        file_put_contents($path, str_replace($anchor, $replacement, $xml));

        $this->info('[native-files] cleartext permitted for ' . self::LOOPBACK_HOST);

        return self::SUCCESS;
    }
}
