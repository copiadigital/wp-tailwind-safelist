<?php

namespace CopiaDigital\TailwindSafelist\Commands;

use Illuminate\Console\Command;

class WatchCommand extends Command
{
    protected $signature = 'tailwind:watch';

    protected $description = 'Watch for safelist changes and trigger Tailwind rebuilds automatically.';

    public function handle(): int
    {
        // Check if Node.js is available
        exec('node --version 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            $this->error('Node.js is not installed or not in PATH.');
            $this->line('Please install Node.js to use the build watcher.');
            return self::FAILURE;
        }

        $watcherPath = dirname(__DIR__, 2) . '/bin/build-watcher.cjs';

        if (!file_exists($watcherPath)) {
            $this->error('Build watcher script not found at: ' . $watcherPath);
            return self::FAILURE;
        }

        $this->info('Starting Tailwind build watcher...');
        $this->line('Press Ctrl+C to stop');
        $this->newLine();

        // Use passthru to forward all output and allow Ctrl+C to work
        passthru('node ' . escapeshellarg($watcherPath), $exitCode);

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }
}
