<?php

namespace CopiaDigital\TailwindSafelist\Commands;

use CopiaDigital\TailwindSafelist\TailwindSafelist;
use Illuminate\Console\Command;

class UpdateDbCommand extends Command
{
    protected $signature = 'tailwind:update-db';

    protected $description = 'Creates the database table used for the Tailwind safelist.';

    public function handle(): int
    {
        $saved_version = (int) get_site_option('tailwind_safelist_db_version');

        if ($saved_version < 100) {
            if ($this->upgrade100()) {
                update_site_option('tailwind_safelist_db_version', 100);
                $this->info('Database table created successfully.');
                return self::SUCCESS;
            }

            $this->error('Failed to create database table.');
            return self::FAILURE;
        }

        $this->info('Database is already up to date.');
        return self::SUCCESS;
    }

    private function upgrade100(): bool
    {
        global $wpdb;

        $table_name = TailwindSafelist::getTableName();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE `{$table_name}` (
            class_id int NOT NULL primary key AUTO_INCREMENT,
            class_name varchar(191) NOT NULL,
            post_id bigint(20) UNSIGNED NOT NULL,
            INDEX idx_post_id (post_id),
            INDEX idx_class_name (class_name)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        return empty($wpdb->last_error);
    }
}
