#!/usr/bin/env node

/**
 * Reliable Build Watcher for wp-tailwind-safelist
 * Watches the trigger file and runs `yarn build` when it changes
 *
 * Usage:
 *   node ./vendor/copiadigital/wp-tailwind-safelist/bin/build-watcher.cjs
 *
 * Run this from your theme directory where package.json is located.
 */

const fs = require('fs');
const { spawn } = require('child_process');
const path = require('path');
const os = require('os');

// Theme directory is the current working directory
const THEME_DIR = process.cwd();
const TRIGGER_FILE = path.join(THEME_DIR, '.tailwind-build-trigger');
const VENDOR_DIR = path.join(THEME_DIR, 'vendor', 'copiadigital', 'wp-tailwind-safelist');

let isBuilding = false;

/**
 * Detect OS and return the appropriate yarn binary path
 */
function getYarnBinary() {
    const platform = os.platform();
    let binaryName;

    if (platform === 'darwin') {
        binaryName = 'yarn-macos';
    } else if (platform === 'linux') {
        binaryName = 'yarn-linux';
    } else {
        // Fallback to regular yarn command
        return 'yarn';
    }

    const binaryPath = path.join(VENDOR_DIR, binaryName);

    if (fs.existsSync(binaryPath)) {
        // Make executable if needed
        try {
            fs.chmodSync(binaryPath, 0o755);
        } catch (e) {
            // Ignore chmod errors
        }
        return binaryPath;
    }

    // Fallback to regular yarn command
    return 'yarn';
}

// Verify we're in a theme directory
if (!fs.existsSync(path.join(THEME_DIR, 'package.json'))) {
    console.error('Error: No package.json found in current directory.');
    console.error('Please run this command from your theme directory.');
    process.exit(1);
}

// Ensure trigger file exists
if (!fs.existsSync(TRIGGER_FILE)) {
    fs.writeFileSync(TRIGGER_FILE, '0');
}

const yarnBinary = getYarnBinary();

console.log('Tailwind Build Watcher started');
console.log(`Theme directory: ${THEME_DIR}`);
console.log(`Yarn binary: ${yarnBinary}`);
console.log(`Platform: ${os.platform()}`);
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

    const build = spawn(yarnBinary, ['build'], {
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
