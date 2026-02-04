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

        $yarnBinary = $this->ensureExecutable($yarnBinary, $os);

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
     * Ensure the yarn binary is executable.
     *
     * Composer strips executable permissions from package files. This method
     * tries to restore them in-place, and if that fails (e.g., different file
     * owner in Docker), copies the binary to a temp location where PHP owns it.
     */
    private function ensureExecutable(string $yarnBinary, string $os): string
    {
        if (is_executable($yarnBinary)) {
            return $yarnBinary;
        }

        $this->line('Making yarn binary executable...');

        // Try chmod in-place (PHP native)
        @chmod($yarnBinary, 0755);
        if (is_executable($yarnBinary)) {
            return $yarnBinary;
        }

        // Try chmod in-place (shell)
        @exec(sprintf('chmod +x %s 2>/dev/null', escapeshellarg($yarnBinary)));
        if (is_executable($yarnBinary)) {
            return $yarnBinary;
        }

        // Last resort: copy to temp directory where PHP owns the file
        $this->line('Copying yarn binary to temp directory...');
        $tempBinary = sys_get_temp_dir() . '/yarn-' . $os;
        @copy($yarnBinary, $tempBinary);
        @chmod($tempBinary, 0755);

        if (is_executable($tempBinary)) {
            return $tempBinary;
        }

        $this->error('Failed to make yarn binary executable. Try running: chmod +x ' . $yarnBinary);
        return $yarnBinary;
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
