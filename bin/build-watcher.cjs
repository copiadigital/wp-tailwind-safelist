#!/usr/bin/env node

/**
 * Reliable Build Watcher for wp-tailwind-safelist
 * Watches the trigger file and runs `yarn build` when it changes
 */

const fs = require('fs');
const { spawn } = require('child_process');
const path = require('path');

// Path to the trigger file (relative to this file)
const TRIGGER_FILE = path.join(__dirname, '../../../../.tailwind-build-trigger');
const THEME_DIR = path.resolve(TRIGGER_FILE, '..'); // For yarn build

let isBuilding = false;

// Ensure trigger file exists
if (!fs.existsSync(TRIGGER_FILE)) {
    fs.writeFileSync(TRIGGER_FILE, '0');
}

console.log('Tailwind Build Watcher started');
console.log(`Watching: ${TRIGGER_FILE}`);
console.log('Press Ctrl+C to stop\n');

/**
 * Build runner
 */
function runBuild() {
    if (isBuilding) return;
    isBuilding = true;

    console.log('\n==========================================');
    console.log(`Build triggered at ${new Date().toLocaleString()}`);
    console.log('==========================================\n');

    const build = spawn('yarn', ['build'], {
        cwd: THEME_DIR,
        stdio: 'inherit',
        shell: true,
    });

    build.on('close', (code) => {
        isBuilding = false;
        if (code === 0) {
            console.log('\n✅ Build complete!\n');
        } else {
            console.log(`\n❌ Build failed with code ${code}\n`);
        }
    });

    build.on('error', (err) => {
        isBuilding = false;
        console.error('Build error:', err.message);
    });
}

/**
 * Watch trigger file using fs.watchFile (polling)
 * This is much more reliable than fs.watch
 */
fs.watchFile(TRIGGER_FILE, { interval: 200 }, (curr, prev) => {
    if (curr.mtimeMs !== prev.mtimeMs) {
        runBuild();
    }
});

// Keep process alive
process.on('SIGINT', () => {
    console.log('\nWatcher stopped');
    process.exit(0);
});
