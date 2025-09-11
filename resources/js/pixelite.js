const pixelite = (() => {
  "use strict";

  let pixelTraceId = null;
  let pageLoadTime = null;
  let sessionExpired = false;
  let heartbeatInterval = null;
  let totalVisibleTime = 0;
  let visibilityStartTime = null;
  let isCurrentlyVisible = false;
  const HEARTBEAT_INTERVAL_MS = 20000; // 20s
  const SESSION_TIMEOUT_MS = 21600000; // 6 hours

  // -------------------- Visibility helpers --------------------
  function getVisibilityProps() {
    if ("hidden" in document) return { hidden: "hidden", visibilityChange: "visibilitychange" };
    if ("msHidden" in document) return { hidden: "msHidden", visibilityChange: "msvisibilitychange" };
    if ("webkitHidden" in document) return { hidden: "webkitHidden", visibilityChange: "webkitvisibilitychange" };
    return {};
  }

  function isDocumentVisible() {
    const { hidden } = getVisibilityProps();
    return hidden ? !document[hidden] : true;
  }

  function updateVisibilityTime() {
    if (isCurrentlyVisible && visibilityStartTime) {
      totalVisibleTime += Date.now() - visibilityStartTime;
      visibilityStartTime = null;
    }
  }

  function getTotalVisibleTimeSeconds() {
    let time = totalVisibleTime;
    if (isCurrentlyVisible && visibilityStartTime) {
      time += Date.now() - visibilityStartTime;
    }
    return Math.floor(time / 1000);
  }

  function isSessionExpired() {
    if (sessionExpired) return true;
    if (pageLoadTime && Date.now() - pageLoadTime >= SESSION_TIMEOUT_MS) {
      sessionExpired = true;
      stopHeartbeat();
      return true;
    }
    return false;
  }

  // -------------------- Heartbeat --------------------
  async function sendHeartbeat() {
    if (!pixelTraceId || isSessionExpired()) return;

    const visitData = {
      total_time: getTotalVisibleTimeSeconds(),
      screen_width: window.screen.width,
      screen_height: window.screen.height,
      viewport_width: window.innerWidth,
      viewport_height: window.innerHeight,
      color_depth: window.screen.colorDepth,
      pixel_ratio: window.devicePixelRatio || 1,
      timezone_offset: new Date().getTimezoneOffset(),
    };

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]');
      if (!csrfToken) throw new Error("CSRF token not found");

      await fetch(`/pixelite/${pixelTraceId}/update`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
          "X-CSRF-TOKEN": csrfToken.content,
        },
        credentials: "same-origin",
        body: JSON.stringify(visitData),
        keepalive: true,
      });
    } catch (err) {
      if (err.name !== "NetworkError" && err.name !== "TypeError") {
        console.warn("Pixelite heartbeat error:", err);
      }
    }
  }

  function startHeartbeat() {
    if (!heartbeatInterval) heartbeatInterval = setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS);
  }

  function stopHeartbeat() {
    if (heartbeatInterval) {
      clearInterval(heartbeatInterval);
      heartbeatInterval = null;
    }
  }

  // -------------------- Cleanup --------------------
  let eventListeners = [];
  function cleanup() {
    updateVisibilityTime();
    stopHeartbeat();
    eventListeners.forEach(([event, handler, target]) => (target || document).removeEventListener(event, handler));
    eventListeners = [];
    pixelTraceId = null;
    pageLoadTime = null;
    sessionExpired = false;
    totalVisibleTime = 0;
    visibilityStartTime = null;
    isCurrentlyVisible = false;
  }

  // -------------------- Init --------------------
  function init(traceId) {
    if (!traceId) return;
    cleanup();

    pixelTraceId = traceId;
    pageLoadTime = Date.now();
    isCurrentlyVisible = isDocumentVisible();
    visibilityStartTime = isCurrentlyVisible ? Date.now() : null;

    // -------------------- Visibility change --------------------
    const { visibilityChange } = getVisibilityProps();
    if (visibilityChange) {
      const handleVisibilityChange = () => {
        const currentlyVisible = isDocumentVisible();

        if (currentlyVisible) {
          visibilityStartTime = Date.now();
          isCurrentlyVisible = true;
          startHeartbeat();
        } else {
          updateVisibilityTime();
          isCurrentlyVisible = false;
          stopHeartbeat();
          sendHeartbeat(); // report immediately
        }
      };
      document.addEventListener(visibilityChange, handleVisibilityChange);
      eventListeners.push([visibilityChange, handleVisibilityChange, document]);
    }

    // -------------------- Unload handlers --------------------
    const handleUnload = () => {
      updateVisibilityTime();
      sendHeartbeat();
    };
    window.addEventListener("beforeunload", handleUnload);
    window.addEventListener("pagehide", handleUnload);
    eventListeners.push(["beforeunload", handleUnload, window], ["pagehide", handleUnload, window]);

    // Start heartbeat if initially visible
    if (isCurrentlyVisible) startHeartbeat();

    console.log("Pixelite initialized", { traceId, initiallyVisible: isCurrentlyVisible });
  }

  return { init, cleanup, isVisible: () => isCurrentlyVisible, getTotalTime: getTotalVisibleTimeSeconds };
})();
