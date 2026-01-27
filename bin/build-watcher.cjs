#!/usr/bin/env node

/**
 * Build watcher for wp-tailwind-safelist
 * Watches for trigger file changes and runs yarn build
 */

const fs = require('fs');
const { spawn } = require('child_process');
const path = require('path');

const TRIGGER_FILE = path.join(__dirname, '.tailwind-build-trigger');
let isBuilding = false;
let lastModified = 0;

// Create trigger file if it doesn't exist
if (!fs.existsSync(TRIGGER_FILE)) {
    fs.writeFileSync(TRIGGER_FILE, '0');
}

console.log('Build watcher started');
console.log(`Watching: ${TRIGGER_FILE}`);
console.log('Press Ctrl+C to stop\n');

// Watch for file changes
fs.watch(TRIGGER_FILE, (eventType) => {
    if (eventType !== 'change' || isBuilding) return;

    // Debounce - check if file was actually modified
    try {
        const stats = fs.statSync(TRIGGER_FILE);
        const currentModified = stats.mtimeMs;

        if (currentModified === lastModified) return;
        lastModified = currentModified;
    } catch (e) {
        return;
    }

    console.log('\n==========================================');
    console.log(`Build triggered at ${new Date().toLocaleString()}`);
    console.log('==========================================\n');

    isBuilding = true;

    const build = spawn('yarn', ['build'], {
        cwd: __dirname,
        stdio: 'inherit',
        shell: true
    });

    build.on('close', (code) => {
        isBuilding = false;
        if (code === 0) {
            console.log('\nBuild complete!\n');
        } else {
            console.log(`\nBuild failed with code ${code}\n`);
        }
    });

    build.on('error', (err) => {
        isBuilding = false;
        console.error('Build error:', err.message);
    });
});

// Keep process alive
process.on('SIGINT', () => {
    console.log('\nWatcher stopped');
    process.exit(0);
});
