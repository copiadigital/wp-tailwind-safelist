<?php

namespace CopiaDigital\TailwindSafelist\Commands;

use CopiaDigital\TailwindSafelist\CssGenerator;
use CopiaDigital\TailwindSafelist\Scanner;
use CopiaDigital\TailwindSafelist\TailwindSafelist;
use Illuminate\Console\Command;

class BuildCommand extends Command
{
    protected $signature = 'tailwind:build';

    protected $description = 'Generate dynamic.css from the current safelist using the PHP CSS generator.';

    public function handle(): int
    {
        $classes = $this->loadClasses();

        if (empty($classes)) {
            $this->warn('No classes found in safelist. Run `wp acorn tailwind:scan` first.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Generating CSS for %d classes...', count($classes)));

        $generator = new CssGenerator(TailwindSafelist::getCssConfig());
        $css = $generator->generate($classes);

        $outputPath = TailwindSafelist::getCssOutputPath();
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        if (file_put_contents($outputPath, $css) === false) {
            $this->error("Failed to write CSS to: $outputPath");
            return self::FAILURE;
        }

        $this->info("CSS written to: $outputPath");
        return self::SUCCESS;
    }

    /**
     * Load the latest scanned classes from the safelist DB table.
     */
    private function loadClasses(): array
    {
        global $wpdb;

        $table = TailwindSafelist::getTableName();
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return [];
        }

        return (array) $wpdb->get_col("SELECT DISTINCT class_name FROM $table");
    }
}
