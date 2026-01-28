<?php

namespace CopiaDigital\TailwindSafelist\Commands;

use Illuminate\Console\Command;

class BuildCommand extends Command
{
    protected $signature = 'tailwind:build
                            {--dev : Run development build instead of production}';

    protected $description = 'Execute yarn build using the standalone binary for the current OS.';

    public function handle(): int
    {
        $os = $this->detectOS();
        $vendorDir = dirname(__DIR__, 2);
        $themeDir = get_stylesheet_directory();

        $this->info(sprintf('Detected OS: %s', $os));

        if ($os === 'unknown') {
            $this->error('Unable to detect OS. Supported platforms: Linux, macOS');
            return self::FAILURE;
        }

        $yarnBinary = $vendorDir . '/yarn-' . $os;

        if (!file_exists($yarnBinary)) {
            $this->error(sprintf('Yarn binary not found at: %s', $yarnBinary));
            return self::FAILURE;
        }

        if (!is_executable($yarnBinary)) {
            $this->line('Making yarn binary executable...');
            @chmod($yarnBinary, 0755);
        }

        $buildCommand = $this->option('dev') ? 'dev' : 'build';
        $this->info(sprintf('Running yarn %s in %s...', $buildCommand, $themeDir));

        // Fix permissions before build to avoid EACCES errors
        $buildDir = $themeDir . '/public/build';
        $this->fixBuildPermissions($buildDir);

        // Execute yarn build
        $command = sprintf(
            'cd %s && %s %s 2>&1',
            escapeshellarg($themeDir),
            escapeshellarg($yarnBinary),
            $buildCommand
        );

        $this->line('');
        passthru($command, $returnCode);
        $this->line('');

        if ($returnCode !== 0) {
            $this->error(sprintf('Yarn %s failed with exit code %d', $buildCommand, $returnCode));
            return self::FAILURE;
        }

        // Fix permissions after build so subsequent builds can overwrite
        $this->fixBuildPermissions($buildDir);

        $this->info(sprintf('Yarn %s completed successfully!', $buildCommand));
        return self::SUCCESS;
    }

    /**
     * Fix permissions on the build directory to avoid EACCES errors.
     *
     * When builds run from different contexts (PHP container, node container, host),
     * files may be owned by different users. This makes the build directory
     * world-writable since it only contains compiled assets (not sensitive data).
     */
    private function fixBuildPermissions(string $buildDir): void
    {
        if (!is_dir($buildDir)) {
            return;
        }

        // Make build directory and all contents world-writable
        // This is safe because public/build only contains compiled CSS/JS assets
        $command = sprintf('chmod -R a+w %s 2>/dev/null', escapeshellarg($buildDir));
        @exec($command);
    }

    /**
     * Detect the operating system.
     *
     * @return string 'linux', 'macos', or 'unknown'
     */
    private function detectOS(): string
    {
        $os = strtolower(PHP_OS_FAMILY);

        if ($os === 'darwin') {
            return 'macos';
        }

        if ($os === 'linux') {
            return 'linux';
        }

        // Fallback check using PHP_OS for older PHP versions
        $phpOs = strtolower(PHP_OS);
        if (str_contains($phpOs, 'darwin')) {
            return 'macos';
        }
        if (str_contains($phpOs, 'linux') || str_contains($phpOs, 'ubuntu')) {
            return 'linux';
        }

        return 'unknown';
    }
}
