import {
  __name
} from "./chunk-TVQASTIY.js";

// ui/components/Hint.jsx
function Hint({ label, children, align = "start", trigger = null }) {
  const id = window.wp.element.useId();
  const hasTextTrigger = typeof trigger === "string" && trigger.length > 0;
  return /* @__PURE__ */ wp.element.createElement("span", { className: `configops-hint configops-hint--${align}` }, /* @__PURE__ */ wp.
  element.createElement(
    "button",
    {
      className: `configops-hint-trigger ${hasTextTrigger ? "is-text" : "is-icon"}`,
      type: "button",
      "aria-label": label,
      "aria-describedby": id
    },
    hasTextTrigger ? trigger : /* @__PURE__ */ wp.element.createElement("span", { "aria-hidden": "true" }, "i")
  ), /* @__PURE__ */ wp.element.createElement("span", { id, className: "configops-tooltip", role: "tooltip" }, children));
}
__name(Hint, "Hint");

export {
  Hint
};
