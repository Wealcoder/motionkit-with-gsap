// Releases the Connector: `npm run release -- 1.3.1` bumps every version string, builds, lints and zips the plugin, then after a confirm commits, tags v1.3.1, pushes and creates the GitHub release with the zip attached. `npm run release` releases the current version; `--zip-only` stops after the zip; `--yes` skips the confirm.
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline/promises';
import { fileURLToPath } from 'node:url';
import AdmZip from 'adm-zip';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SLUG = 'motionkit-with-gsap';
const NAME = 'MotionKit with GSAP';
const OUT_DIR = path.join(ROOT, 'dist');

const MAIN_FILE = path.join(ROOT, `${SLUG}.php`);
const PLUGIN_CLASS = path.join(ROOT, 'includes', 'Plugin.php');
const PACKAGE_JSON = path.join(ROOT, 'package.json');

/*
  Every place the version lives. WordPress compares the header against the store's version, so a mismatch
  ships a plugin that reports an update to itself forever.
    header      motionkit-with-gsap.php   " * Version: x.y.z"
    constant    motionkit-with-gsap.php   define( 'MOTIONKIT_CONNECTOR_VERSION', 'x.y.z' );
    class       includes/Plugin.php       public const VERSION = 'x.y.z';
    npm         package.json              "version": "x.y.z"
*/
const VERSION_SITES = [
  // Every pattern has exactly three groups (before, version, after) — with only two, String.replace hands the match offset to the callback as the third argument and it lands in the file.
  { name: 'plugin header', file: MAIN_FILE, re: /^(\s*\*\s*Version:\s*)(\S+)()/m },
  { name: 'MOTIONKIT_CONNECTOR_VERSION', file: MAIN_FILE, re: /(define\(\s*'MOTIONKIT_CONNECTOR_VERSION',\s*')([^']+)(')/ },
  { name: 'Plugin::VERSION', file: PLUGIN_CLASS, re: /(public const VERSION = ')([^']+)(')/ },
  { name: 'package.json', file: PACKAGE_JSON, re: /("version":\s*")([^"]+)(")/ },
];

// The engine is vendored from the editor repo and never built here, so a release without it plays nothing.
const REQUIRED_FILES = [
  'assets/build/engine/animationEngine.js',
  'assets/build/engine/customAnimation.js',
  'assets/build/engine/engine-build.json',
  'assets/build/modules/editor-bridge.js',
  'assets/build/lib/engine-wrapper.js',
  'assets/vendor/gsap/gsap.min.js',
  'includes/Setup/PluginUpdater.php',
];

const SEMVER = /^\d+\.\d+\.\d+$/;
const FLAGS = new Set(['--zip-only', '--yes']);

const args = process.argv.slice(2);
const ZIP_ONLY = args.includes('--zip-only');
const YES = args.includes('--yes');
const requested = args.find((arg) => !arg.startsWith('--'));

// Original file contents from a bump, so a failure or a declined confirm leaves the tree as it was found; cleared once the bump is committed.
let bumpedFrom = null;

function fail(message) {
  restoreVersions();
  console.error(`\n✖ ${message}\n`);
  process.exit(1);
}

function step(message) {
  console.log(`\n▸ ${message}`);
}

function indent(text) {
  return text.replace(/^/gm, '    ');
}

function git(...gitArgs) {
  const result = spawnSync('git', gitArgs, { cwd: ROOT, encoding: 'utf8' });
  return { ok: result.status === 0, out: (result.stdout || '').trimEnd(), err: (result.stderr || '').trim() };
}

function readVersions() {
  return VERSION_SITES.map((site) => {
    const match = fs.readFileSync(site.file, 'utf8').match(site.re);
    if (!match) fail(`Could not find the version in ${site.name} (${path.relative(ROOT, site.file)}).`);
    return { ...site, version: match[2] };
  });
}

function bumpVersions(next) {
  bumpedFrom = new Map();
  for (const site of VERSION_SITES) {
    if (!bumpedFrom.has(site.file)) bumpedFrom.set(site.file, fs.readFileSync(site.file, 'utf8'));
    const source = fs.readFileSync(site.file, 'utf8');
    fs.writeFileSync(site.file, source.replace(site.re, (_, before, _old, after) => `${before}${next}${after}`));
  }
}

function restoreVersions() {
  if (!bumpedFrom) return;
  for (const [file, source] of bumpedFrom) fs.writeFileSync(file, source);
  bumpedFrom = null;
  console.error('  version strings restored');
}

// Everything a publish needs is checked before a single file changes, so a refusal costs nothing to retry.
function preflight(version) {
  step('Checking git and GitHub');
  const branch = git('rev-parse', '--abbrev-ref', 'HEAD').out;
  if (!branch || branch === 'HEAD') fail('Not on a branch. Check out the branch to release from.');

  const dirty = git('status', '--porcelain').out;
  if (dirty) fail(`Uncommitted changes. Commit or stash them first so the tag matches the zip, or pass --zip-only:\n${indent(dirty)}`);

  if (!git('fetch', '--quiet', '--tags', 'origin').ok) fail('git fetch origin failed.');
  const upstream = git('rev-parse', '--abbrev-ref', '@{u}');
  if (!upstream.ok) fail(`${branch} has no upstream. Push it once with: git push -u origin ${branch}`);
  const behind = Number(git('rev-list', '--count', 'HEAD..@{u}').out);
  if (behind) fail(`${branch} is ${behind} commit(s) behind ${upstream.out}. Pull first.`);

  const tag = `v${version}`;
  if (git('rev-parse', '-q', '--verify', `refs/tags/${tag}`).ok) fail(`Tag ${tag} already exists.`);
  if (git('ls-remote', '--tags', 'origin', `refs/tags/${tag}`).out) fail(`Tag ${tag} already exists on origin.`);

  const gh = spawnSync('gh', ['auth', 'status'], { encoding: 'utf8' });
  if (gh.error || gh.status !== 0) fail('GitHub CLI is not ready. Install gh and run `gh auth login`, or pass --zip-only.');

  console.log(`  branch ${branch} → ${upstream.out}, tag ${tag} is free, gh signed in`);
  return { branch, tag };
}

// Minimal .distignore reader with gitignore semantics: a leading slash anchors to the root, a bare name matches at any depth, `*` stays inside one segment.
function loadIgnoreRules() {
  const file = path.join(ROOT, '.distignore');
  const lines = fs.existsSync(file) ? fs.readFileSync(file, 'utf8').split(/\r?\n/) : [];
  const patterns = lines.map((line) => line.trim()).filter((line) => line && !line.startsWith('#'));

  // dist/ holds the zips this script writes, and must never end up inside the next one.
  patterns.push('/dist');

  return patterns.map((pattern) => {
    const anchored = pattern.startsWith('/') || pattern.replace(/\/$/, '').includes('/');
    const body = pattern.replace(/^\//, '').replace(/\/$/, '');
    const source = body
      .split('*')
      .map((part) => part.replace(/[.+?^${}()|[\]\\]/g, '\\$&'))
      .join('[^/]*');
    return { anchored, re: new RegExp(`^${source}$`) };
  });
}

function isIgnored(relPath, rules) {
  const base = relPath.split('/').pop();
  return rules.some((rule) => (rule.anchored ? rule.re.test(relPath) : rule.re.test(base)));
}

function collectFiles(rules, dir = ROOT, rel = '') {
  const files = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const relPath = rel ? `${rel}/${entry.name}` : entry.name;
    if (isIgnored(relPath, rules)) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) files.push(...collectFiles(rules, full, relPath));
    else if (entry.isFile()) files.push(relPath);
  }
  return files;
}

// A syntax error in any PHP file takes down the customer's whole site on update, so every file is linted when a php binary is available.
function lintPhp(files) {
  // No shell: php is a real executable, and a Windows shell would split "Local Sites" in every file path into two arguments.
  const probe = spawnSync('php', ['-v'], { encoding: 'utf8' });
  if (probe.status !== 0) {
    if (!ZIP_ONLY) fail('php is not on PATH, and a release is not published without a PHP lint.');
    console.log('  php not on PATH — skipped (run `php -l` yourself before uploading).');
    return;
  }
  const phpFiles = files.filter((file) => file.endsWith('.php'));
  for (const file of phpFiles) {
    const result = spawnSync('php', ['-n', '-l', path.join(ROOT, file)], { encoding: 'utf8' });
    if (result.status !== 0) fail(`PHP syntax error in ${file}:\n${result.stdout}${result.stderr}`);
  }
  console.log(`  ${phpFiles.length} PHP files OK`);
}

async function confirm(question) {
  if (YES) return true;
  if (!process.stdin.isTTY) fail('No terminal to confirm in. Pass --yes to publish without asking.');
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
  const answer = await rl.question(`\n${question} [y/N] `);
  rl.close();
  return /^y(es)?$/i.test(answer.trim());
}

const unknown = args.filter((arg) => arg.startsWith('--') && !FLAGS.has(arg));
if (unknown.length) fail(`Unknown option ${unknown.join(', ')}. Use --zip-only or --yes.`);
if (requested && !SEMVER.test(requested)) fail(`"${requested}" is not a version like 1.3.1.`);

step('Checking versions');
const current = readVersions();
for (const site of current) console.log(`  ${site.name.padEnd(28)} ${site.version}`);
const currentDistinct = [...new Set(current.map((site) => site.version))];
if (!requested && currentDistinct.length !== 1) fail('The version strings disagree. Run `npm run release -- <version>` to set them all at once.');
const version = requested || currentDistinct[0];

const release = ZIP_ONLY ? null : preflight(version);

if (requested && !(currentDistinct.length === 1 && currentDistinct[0] === requested)) {
  step(`Setting every version to ${version}`);
  bumpVersions(version);
  const after = [...new Set(readVersions().map((site) => site.version))];
  if (after.length !== 1 || after[0] !== version) fail('The bump did not reach every version string.');
  console.log(`  bumped to ${version}`);
}

if (ZIP_ONLY) {
  const dirty = git('status', '--porcelain').out;
  if (dirty) console.log(`\n  uncommitted changes — the zip will include them:\n${indent(dirty)}`);
}

step('Building the bridge and engine wrapper');
// One command string rather than an args array: npm is npm.cmd on Windows and needs a shell, and Node deprecates passing an args array alongside shell.
const build = spawnSync('npm run build', { cwd: ROOT, stdio: 'inherit', shell: true });
if (build.status !== 0) fail('npm run build failed.');

step('Checking required files');
for (const file of REQUIRED_FILES) {
  if (!fs.existsSync(path.join(ROOT, file))) fail(`Missing ${file}. The engine is copied in by the editor repo's \`npm run copy:connector\`.`);
}
console.log(`  ${REQUIRED_FILES.length} required files present`);

// The build rewrites committed bundles under assets/build; a publish whose build changed them would tag source that does not match the zip. Line endings alone do not count: with core.autocrlf the checkout holds CRLF and webpack writes LF, which is the same file.
if (!ZIP_ONLY) {
  const versionFiles = new Set(VERSION_SITES.map((site) => path.relative(ROOT, site.file).replace(/\\/g, '/')));
  const lines = (out) => out.split('\n').filter(Boolean);
  const drift = [
    ...lines(git('diff', '--name-only').out).filter((file) => !versionFiles.has(file) && !git('diff', '--quiet', '--ignore-cr-at-eol', '--', file).ok),
    ...lines(git('ls-files', '--others', '--exclude-standard').out),
  ];
  if (drift.length) fail(`The build changed committed files. Commit them first, then release again:\n${indent(drift.join('\n'))}`);
}

step('Collecting files from .distignore');
const files = collectFiles(loadIgnoreRules());
console.log(`  ${files.length} files`);

step('Linting PHP');
lintPhp(files);

step('Writing the zip');
fs.mkdirSync(OUT_DIR, { recursive: true });
const zipPath = path.join(OUT_DIR, `${SLUG}-${version}.zip`);
const zip = new AdmZip();
// adm-zip writes forward-slash entry names, which a Linux host needs; a Windows-built zip with backslashes extracts as flat files named "includes\Plugin.php".
for (const file of files) zip.addFile(`${SLUG}/${file}`, fs.readFileSync(path.join(ROOT, file)));
zip.writeZip(zipPath);

const written = new AdmZip(zipPath).getEntries();
if (!written.some((entry) => entry.entryName === `${SLUG}/${SLUG}.php`)) fail('The zip has no main plugin file at the expected path.');
const sizeKb = (fs.statSync(zipPath).size / 1024).toFixed(0);
console.log(`  ${path.relative(ROOT, zipPath)}  (${written.length} files, ${sizeKb} KB, version ${version})`);

// The same zip under a fixed name rides along on the release, so GitHub's releases/latest/download/motionkit-with-gsap.zip always serves the newest version and the public download link never needs a hand update after a release.
const latestPath = path.join(OUT_DIR, `${SLUG}.zip`);
fs.copyFileSync(zipPath, latestPath);
console.log(`  ${path.relative(ROOT, latestPath)}  (same zip, fixed name for the download link)`);

const eddNext = `Next: upload it to Download 17 on my.motionkit.io, select it as the Update File, set Version Number to ${version} and fill in the changelog.`;

if (ZIP_ONLY) {
  // A zip-only run keeps its bump: the point of it is a zip of that version, and the files are left for the developer to commit or discard.
  bumpedFrom = null;
  console.log(`\n✔ Zip ready. Nothing was committed, tagged or pushed.\n\n${eddNext}\n`);
  process.exit(0);
}

const { branch, tag } = release;
const go = await confirm(`Commit the version bump, tag ${tag}, push ${branch} and the tag to origin, and create the GitHub release with ${path.basename(zipPath)}?`);
if (!go) {
  restoreVersions();
  console.log(`\nStopped. The zip is in dist/; nothing was committed, tagged or pushed.\n`);
  process.exit(0);
}

step('Committing and tagging');
const versionPaths = [...new Set(VERSION_SITES.map((site) => path.relative(ROOT, site.file)))];
// Asked of the version files alone: a version committed beforehand leaves nothing to commit, while the build's line-ending churn would still make the whole tree look dirty.
if (!git('diff', '--quiet', '--ignore-cr-at-eol', '--', ...versionPaths).ok) {
  const add = git('add', ...versionPaths);
  if (!add.ok) fail(`git add failed:\n${add.err}`);
  const commit = git('commit', '-m', `Chore (Release): Version ${version}`);
  if (!commit.ok) fail(`git commit failed:\n${commit.err}`);
  console.log(`  committed ${git('rev-parse', '--short', 'HEAD').out}`);
}
bumpedFrom = null;
const tagged = git('tag', '-a', tag, '-m', `${NAME} ${version}`);
if (!tagged.ok) fail(`git tag failed:\n${tagged.err}`);
console.log(`  tagged ${tag}`);

step('Pushing');
// Atomic, so the branch and the tag land together or neither does.
const push = git('push', '--atomic', 'origin', `HEAD:refs/heads/${branch}`, `refs/tags/${tag}`);
if (!push.ok) fail(`git push failed; the commit and tag are local only. Fix it, then: git push --atomic origin ${branch} ${tag}\n${push.err}`);
console.log(`  pushed ${branch} and ${tag}`);

step('Creating the GitHub release');
// No readme changelog here, so the notes are GitHub's list of what changed since the previous tag.
const gh = spawnSync('gh', ['release', 'create', tag, zipPath, latestPath, '--title', `${NAME} ${version}`, '--generate-notes', '--verify-tag'], { cwd: ROOT, encoding: 'utf8' });
if (gh.status !== 0) {
  console.error(`\n✖ The tag is pushed but the GitHub release was not created:\n${gh.stderr || gh.error}`);
  console.error(`  Retry: gh release create ${tag} "${zipPath}" "${latestPath}" --title "${NAME} ${version}" --generate-notes --verify-tag\n`);
  process.exit(1);
}
console.log(`  ${gh.stdout.trim()}`);
console.log(`\n✔ ${NAME} ${version} released.\n\n${eddNext}\n`);
