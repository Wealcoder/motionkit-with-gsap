const defaultConfig = require("@wordpress/scripts/config/webpack.config");
const { getWebpackEntryPoints } = require("@wordpress/scripts/utils/config");
const path = require("path");

// This repo no longer builds the animation engine. The engine's source lives in the editor repo at `src/lib/motionkit-engine/`, and its `scripts/copy-to-connector.mjs` drops the built bundles into `assets/build/engine/` as vendored artifacts — so the only entry left here is `editor-bridge.js`.
// One source tree used to emit two different builds (instrumented for the editor, stripped for the plugin), managed by the `__MOTIONKIT_DEVTOOLS__` DefinePlugin flag; when the flag was read the wrong way the editor's DevTools silently found nothing to drive. The editor builds both variants now, so the flag, the DefinePlugin, and the copy-to-editor hook are all gone.

// wp-scripts' CleanWebpackPlugin wipes all of `assets/build`, so leaving it in place meant every connector build deleted the vendored engine and shipped a broken plugin. Webpack's own `output.clean` with a keep pattern is used instead: webpack still clears its own stale output under `modules/` but never touches what the editor vendors in.
// Whole folders rather than a filename prefix — the previous pattern matched only `frontend*`, so it missed `engine-build.json` and every build deleted the one file that can reveal a stale vendored engine.
const KEEP_VENDORED_ENGINE = /^(engine|presets)[\\/]/;

module.exports = {
  ...defaultConfig,
  plugins: (defaultConfig.plugins || []).filter(
    (plugin) => plugin.constructor.name !== "CleanWebpackPlugin",
  ),
  entry: () => {
    return {
      ...getWebpackEntryPoints(),
      "modules/editor-bridge": "./src/modules/editor-bridge.js",
      // The host wrapper. Compiled here, not vendored, because knowing what `motionkitData` means is this plugin's job; it calls the engine through `window.MotionKitEngine`.
      "lib/engine-wrapper": "./src/lib/engine-wrapper.js",
      // The MotionKit mark beside animated posts in the admin post lists, and its Screen Options switch (Admin\PostListBadge).
      "admin/post-badge": ["./src/modules/admin/post-badge.js", "./src/css/admin-post-badge.css"],
    };
  },
  output: {
    path: path.resolve(__dirname, "assets/build"),
    filename: "[name].js",
    clean: { keep: (asset) => KEEP_VENDORED_ENGINE.test(asset) },
  },
};
