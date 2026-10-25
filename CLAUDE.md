# MotionKit Connector — Claude Instructions

The WordPress adapter for MotionKit. It is an **adapter, not an engine owner**: it
knows what a WordPress page is, what `motionkitData` means, and when scripts must
run — and it knows nothing about how an animation is built.

The visual editor is a separate repo (`motionkit-editor`, `editor.motionkit.io`).
**The animation engine's source lives there**, at `src/lib/motionkit-engine/`.

---

## Rule 1 — This repo does not build the engine

The engine is **vendored in** as a built artifact. One direction only:

```
motionkit-editor                                  motionkit-connector
  src/lib/motionkit-engine/    ──build──►  assets/build/engine/
        (the only source)       copy-to-     assets/build/presets/{preloader,pageTransition,mostPopular}/
                                connector    assets/build/engine/engine-build.json   ← provenance stamp
```

- Built and copied by the **editor's** `npm run build` (or `npm run copy:connector`).
  The editor's `.env` needs `MOTIONKIT_CONNECTOR_PATH` pointing at this repo, or it
  ships a connector with no engine.
- `npm run verify:engine-sync` **in the editor** compares the two sides' `sourceHash`.
  Run it before packaging a release; a mismatch means these bundles are stale.
- There is no `copy-to-editor` script any more. Copying back is what let the two trees
  drift.

**Never edit anything under `assets/build/engine/` or `assets/build/presets/`.** Those
are generated. A fix to animation behaviour belongs in the editor's engine source, then
gets copied here — see the precedent commits named "Replay the connector's … into the
engine source".

`assets/build/` is gitignored. It is a local/release artifact, not repo content.

## Rule 2 — What this repo *does* build

`npm run build` (wp-scripts/webpack) emits exactly two things:

| Entry | Output | Why it is compiled here, not vendored |
|---|---|---|
| `src/modules/editor-bridge.js` | `assets/build/modules/editor-bridge.js` | The postMessage + REST bridge. WordPress-specific by definition. |
| `src/lib/engine-wrapper.js` | `assets/build/lib/engine-wrapper.js` | The host wrapper. Reading `motionkitData` is this plugin's job. |

`webpack.config.js` drops wp-scripts' `CleanWebpackPlugin` and uses
`output.clean` with `keep: /^(engine|presets)[\\/]/` instead. That guard is
load-bearing: `CleanWebpackPlugin` wiped all of `assets/build`, so every connector
build deleted the vendored engine and shipped a broken plugin. Keep whole folders,
not a filename prefix — the stamp lives at `engine/engine-build.json` and a
prefix pattern missed it.

After a rename under `src/`, **run the build** — a stale `assets/build/modules/…`
tree keeps the old path alive and the PHP `file_exists` check silently stops
enqueueing the bridge.

## Rule 3 — The engine is reached only through its public API

`engine-wrapper.js` calls `window.MotionKitEngine`, installed by
`assets/build/engine/animationEngine.js`. That object is frozen and carries
`apiVersion`, `run`, `reset`, `registeredPresets`.

Every decision about what the data *means* stays behind that call: which device
bucket wins, which records are playable, how a per-device bag flattens onto `vars`.
**Do not reimplement any of it in the wrapper or in PHP.** A wrapper that re-derived
those rules would drift from the editor, and the same animation would behave
differently in the two places.

If the engine is missing, the wrapper warns and stands down — it never guesses.

## Rule 4 — Script order on the frontend is a contract

Registered in `includes/Frontend/Frontend.php`:

| Hook | Priority | What |
|---|---|---|
| `wp_head` | 0 | `PageTransition::print_head_early_cover()` |
| `wp_head` | 0 | `print_preloader_cover()` |
| `wp_head` | 1 | `print_gsap_preload()` |
| `wp_enqueue_scripts` | 2 | `enqueue_page_transition()` |
| `wp_enqueue_scripts` | 5 | `enqueue_preloader()` |
| `wp_enqueue_scripts` | 50 | `maybe_init_scroll_smoother()` |
| `wp_enqueue_scripts` | 60 | `enqueue_frontend_scripts()` |

Two consequences worth knowing before changing a number:

- The transition runs **before** the preloader and consumes the arrival key from
  `sessionStorage`, so by the time the preloader looks, the key is gone. The
  preloader detects an arrival via the durable `window.__motionkitTransitionArrival`
  marker instead. Reorder these and the preloader stacks a second overlay on every
  transition arrival.
- `print_head_early_cover()` prints an `!important` rule keyed on
  `html.motionkit-pt-arriving`. **A CSS animation can never outrank `!important`**, so
  the cover cannot be undone by the transition's own tween — the engine removes the
  class, and the PHP arms a 4s timer as a net. Without both, a dead bundle leaves the
  page covered and unclickable.

## Rule 5 — Shared conventions with the editor repo

- **Comments: one comment = one `//` line**, however long. Never a stack of `//`
  continuation lines. Use `/* */` for diagrams, tables and shape sketches. Applies to
  `.js`; PHP is not yet converted.
- **Commit messages: one short line, `Type (Scope): Description`.** No trailers.
- **Never edit, commit, or push `main` directly.** Work on a feature branch.
- Comments state the non-obvious *reason*, not what the code already says.

## Rule 6 — The OAuth callback params are namespaced

The connect callback lands on `admin.php?page=motionkit-connect`, and a bare
`code` + `state` pair on a wp-admin URL is **not ours alone**. Other plugins
treat any admin request carrying that pair as their own OAuth callback. CrawlWP
SEO (`mihdan-index-now`) is the one that bit us: its
`YandexWebmaster::get_api_token()` runs on `admin_init` at priority 10 —
registered *before* its own `is_enabled()` early-return, so merely activating
the plugin arms it — and does

```php
if (isset($_GET['code'], $_GET['state']) && check_admin_referer('yandex_oauth_nonce', 'state') && ...)
```

`check_admin_referer()` does not return false, it `wp_die()`s. Our `state` is
`bin2hex(random_bytes(32))`, never a WP nonce, so every connect attempt died on
**"The link you followed has expired."** before `handle_callback()` ran.

Two defences, both in `includes/Auth/OAuthHandler.php`. Keep both — they fail
independently:

1. **`handle_callback` is hooked at `admin_init` priority 1**, not the default
   10. Priority beats plugin load order, so we consume the params and `exit` on
   the redirect before any later listener sees them.
2. **The params are namespaced**: the callback is answered with
   `motionkit_code` / `motionkit_state`, which no other plugin sniffs.

### The dialect handshake

`get_authorize_url()` sends **both** `state` and `motionkit_state` (same token).
The editor echoes back in whichever dialect it was asked in — namespaced when
`motionkit_state` was present, bare otherwise. `read_callback_param()` reads
namespaced first and falls back to bare.

That makes the two repos deployable in **either order**, which matters because
customers update the plugin on their own schedule:

| Connector | Editor | Callback | Works |
|---|---|---|---|
| new | new | `motionkit_code` + `motionkit_state` | ✅ no collision |
| new | old | `code` + `state` | ✅ via the bare fallback + priority 1 |
| old | new | `code` + `state` | ✅ editor answers in the old dialect |

**Do not drop the bare `state` from the authorize URL** until every shipped
connector sends `motionkit_state` — an editor that predates the rename reads
`state` only, and would refuse the request as "Missing required parameters".

The editor side is `src/pages/connect/AuthorizePage.jsx` (reads the dialect,
writes the callback) and `src/pages/connect/CallbackPage.jsx` (diagnostic
fallback) in the `motionkit-editor` repo. The server never sees these names —
`/connect/authorize` and `/connect/token` carry `state` and `code` in a JSON
**body**, so no server change was needed.

## Key files

| Path | Responsibility |
|---|---|
| `motionkit-connector.php` | Plugin header. The shipping name is **`MotionKit Connector`** — never leave a branch-build name in it. |
| `includes/Frontend/Frontend.php` | Script loading, localized `motionkitData`, no-cache headers, hook priorities |
| `includes/Frontend/PageTransition.php` | Early cover + its self-expiring timer |
| `includes/RestApi/RestApi.php` | `/save` dispatcher and `/pages` search |
| `includes/Common/AnimationBuilderPageType.php` | Storage routing (post_meta / term_meta / option) |
| `includes/Common/configs/animation-builder-assets.php` | Per-preset enqueue table pointing at `assets/build/presets/` |
| `src/lib/engine-wrapper.js` | Host wrapper — the only caller of `window.MotionKitEngine` |
| `src/modules/editor-bridge.js` | postMessage + REST bridge to the editor |
| `uninstall.php` | Data removal on delete. wp.org expects an uninstall to remove what the plugin created — if anything here is commented out for test sites, it must be restored before shipping. |

## Releasing

`npm run release -- 1.3.1` is the whole release. It refuses up front when the tree is dirty, the branch is behind origin, the tag exists or `gh` is not signed in. It then sets the version in all four places (header, `MOTIONKIT_CONNECTOR_VERSION`, `Plugin::VERSION`, `package.json`), runs `npm run build`, checks the vendored engine is present, refuses if the build changed a committed file (line endings alone do not count), lints every PHP file and writes `dist/motionkit-with-gsap-1.3.1.zip` plus a copy named `dist/motionkit-with-gsap.zip`. After a confirm it commits `Chore (Release): Version 1.3.1`, tags `v1.3.1`, pushes the branch and tag atomically, and creates the GitHub release with both zips and GitHub's generated notes.

- `npm run release` releases the version already in the files; `--zip-only` stops after the zip; `--yes` skips the confirm.
- A failure or a "no" before the commit puts the version strings back.
- The repo is the public `Wealcoder/motionkit-with-gsap` (since 2026-10-25; `Wealcoder/motionkit-connector` is the old home and keeps the older releases). The fixed-name zip on every release is what makes `https://github.com/Wealcoder/motionkit-with-gsap/releases/latest/download/motionkit-with-gsap.zip` always serve the newest version, so a public download button can point there and never needs a hand update.
- Customers update from my.motionkit.io, not GitHub: the zip still goes to Download 17 by hand, as the script's last line says.
- There is no release workflow: the script is the only thing that creates releases, so a tag push never builds a second zip in CI.
- The readme changelog and `languages/motionkit-with-gsap.pot` are not touched by the script. Add the changelog entry and regenerate the template (`wp i18n make-pot . languages/motionkit-with-gsap.pot --domain=motionkit-with-gsap --skip-js --exclude=dist,src,assets,scripts,node_modules`) in the commit before releasing.
