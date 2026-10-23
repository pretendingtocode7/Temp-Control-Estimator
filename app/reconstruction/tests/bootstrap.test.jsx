import React from "react";
import { beforeEach, afterEach, describe, it, expect, vi } from "vitest";
const mocks = vi.hoisted(() => ({ render: vi.fn(), createRoot: vi.fn() }));
vi.mock("react-dom/client", () => ({ createRoot: mocks.createRoot }));
vi.mock("../src/App.jsx", () => ({ App: () => null }));
let main;
beforeEach(async () => {
  document.body.innerHTML = "";
  vi.spyOn(document, "readyState", "get").mockReturnValue("complete");
  mocks.createRoot.mockReturnValue({ render: mocks.render });
  main = await import("../src/main.jsx");
  mocks.createRoot.mockClear();
  mocks.render.mockClear();
});
afterEach(() => {
  document.body.innerHTML = "";
  vi.unstubAllGlobals();
});
function root(boot) {
  const element = document.createElement("div");
  element.id = "tc-estimate-builder-root";
  element.dataset.boot = typeof boot === "string" ? boot : JSON.stringify(boot);
  document.body.appendChild(element);
  return element;
}
describe("WordPress bootstrap compatibility", () => {
  it("does nothing on unrelated pages", () => {
    main.mount();
    expect(mocks.createRoot).not.toHaveBeenCalled();
  });
  it("shows the same invalid JSON error without interpolating raw input", () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    const element = root("{<script>");
    main.mount();
    expect(element.textContent).toBe(
      "Estimate Builder failed to start: invalid boot data.",
    );
    expect(element.querySelector("script")).toBeNull();
    expect(mocks.createRoot).not.toHaveBeenCalled();
  });
  it.each([{ nonce: "synthetic" }, { restUrl: "/wp-json/tc-estimate/v1" }])(
    "requires both REST URL and nonce",
    (boot) => {
      const element = root(boot);
      main.mount();
      expect(element.textContent).toBe(
        "Estimate Builder not configured. Reload the page.",
      );
      expect(mocks.createRoot).not.toHaveBeenCalled();
    },
  );
  it("passes boot values unchanged through StrictMode with an empty brand fallback", () => {
    const element = root({
      restUrl: "/wp-json/tc-estimate/v1",
      ajaxUrl: "/wp-admin/admin-ajax.php",
      nonce: "synthetic",
    });
    vi.stubGlobal("isSecureContext", false);
    main.mount();
    expect(mocks.createRoot).toHaveBeenCalledWith(element);
    const rendered = mocks.render.mock.calls[0][0];
    expect(rendered.type).toBe(React.StrictMode);
    expect(rendered.props.children.props).toEqual({
      restUrl: "/wp-json/tc-estimate/v1",
      ajaxUrl: "/wp-admin/admin-ajax.php",
      nonce: "synthetic",
      brand: {},
    });
  });
  it("derives the worker path from the marked bundle and waits for load, with root scope", async () => {
    document.body.innerHTML =
      '<script data-tc-estimate-bundle src="/custom/plugin/app/dist/estimate-builder.js?ver=synthetic"></script>';
    const registration = { addEventListener: vi.fn() },
      register = vi.fn().mockResolvedValue(registration);
    vi.stubGlobal("navigator", { serviceWorker: { register } });
    const event = vi.spyOn(window, "addEventListener");
    main.registerServiceWorker();
    expect(register).not.toHaveBeenCalled();
    const load = event.mock.calls.find(([name]) => name === "load")[1];
    await load();
    expect(register).toHaveBeenCalledWith(
      "/custom/plugin/app/dist/service-worker.js",
      { scope: "/" },
    );
  });
  it("keeps the fallback worker URL and registration failures non-fatal", async () => {
    const register = vi
      .fn()
      .mockRejectedValue(new Error("Synthetic header unavailable"));
    vi.stubGlobal("navigator", { serviceWorker: { register } });
    const warning = vi.spyOn(console, "warn").mockImplementation(() => {});
    const event = vi.spyOn(window, "addEventListener");
    main.registerServiceWorker();
    const load = event.mock.calls.find(([name]) => name === "load")[1];
    await load();
    await Promise.resolve();
    expect(register).toHaveBeenCalledWith(
      "/wp-content/plugins/temp-control-estimate-builder/app/dist/service-worker.js",
      { scope: "/" },
    );
    expect(warning).toHaveBeenCalledWith(
      "[tc-estimate] service worker registration skipped:",
      "Synthetic header unavailable",
    );
  });
});
