/**
 * MotionKit with GSAP — the Screen Options switch for the mark beside animated posts.
 * Saves the choice for this user the moment the box changes, then reloads the list so the row states are drawn without (or with) the mark; hiding it in place would leave the separator WordPress printed before it.
 */
(function () {
  const config = window.motionkitPostBadge || {};
  const toggle = document.getElementById("motionkit-post-badge-toggle");
  if (!toggle) return;

  toggle.addEventListener("change", () => {
    toggle.disabled = true;
    const body = new FormData();
    body.append("action", "motionkit_post_badge_visibility");
    body.append("nonce", config.nonce || "");
    body.append("hidden", toggle.checked ? "0" : "1");

    fetch(config.ajaxUrl || window.ajaxurl, { method: "POST", credentials: "same-origin", body })
      .then((res) => res.json())
      .then((res) => {
        if (res && res.success) {
          window.location.reload();
        } else {
          toggle.disabled = false;
          toggle.checked = !toggle.checked;
        }
      })
      .catch(() => {
        toggle.disabled = false;
        toggle.checked = !toggle.checked;
      });
  });
})();
