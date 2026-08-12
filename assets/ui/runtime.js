import {
  __name,
  configureStore
} from "./chunks/chunk-TVQASTIY.js";

// ui/runtime.js
var bootstrapNode = document.getElementById("configops-bootstrap");
var roots = /* @__PURE__ */ new Map();
var renderFailure = /* @__PURE__ */ __name((element) => {
  element.removeAttribute("aria-busy");
  element.innerHTML = `<p class="configops-island-error" role="alert">${window.wp.i18n.__("This ConfigOps instrument cou\
ld not be loaded. Reload the page to try again.", "configops")}</p>`;
}, "renderFailure");
var mount = /* @__PURE__ */ __name(async (id, importer) => {
  const element = document.getElementById(id);
  if (!element || roots.has(id)) {
    return;
  }
  try {
    const { default: Component } = await importer();
    const root = window.wp.element.createRoot(element);
    roots.set(id, root);
    root.render(window.wp.element.createElement(Component));
    element.removeAttribute("aria-busy");
  } catch (error) {
    window.console.error("ConfigOps island failed to mount.", error);
    renderFailure(element);
  }
}, "mount");
var onIdle = /* @__PURE__ */ __name((callback) => {
  if ("requestIdleCallback" in window) {
    window.requestIdleCallback(callback, { timeout: 800 });
  } else {
    window.setTimeout(callback, 1);
  }
}, "onIdle");
var whenVisible = /* @__PURE__ */ __name((element, callback) => {
  if (!element || !("IntersectionObserver" in window)) {
    callback();
    return;
  }
  const observer = new IntersectionObserver((entries) => {
    if (entries.some((entry) => entry.isIntersecting)) {
      observer.disconnect();
      callback();
    }
  }, { rootMargin: "240px" });
  observer.observe(element);
}, "whenVisible");
try {
  configureStore(JSON.parse(bootstrapNode?.textContent || "{}"));
  mount("configops-support-island", () => import("./chunks/SupportMatrix-TLSOSZ2H.js"));
  mount("configops-capture-island", () => import("./chunks/CaptureControls-J7HBNVQT.js"));
  onIdle(() => mount("configops-sessions-island", () => import("./chunks/Sessions-QMLYVJN5.js")));
  whenVisible(
    document.getElementById("configops-review-island"),
    () => mount("configops-review-island", () => import("./chunks/ReviewLedger-N35SVLOP.js"))
  );
} catch (error) {
  window.console.error("ConfigOps bootstrap could not be parsed.", error);
  for (const id of ["configops-support-island", "configops-capture-island", "configops-sessions-island", "configops-revi\
ew-island"]) {
    const element = document.getElementById(id);
    if (element) {
      renderFailure(element);
    }
  }
}
