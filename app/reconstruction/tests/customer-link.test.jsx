import * as React from "react";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
vi.mock("../src/lib/pdf.js", () => ({ createProposalAttachment: vi.fn() }));
import { createProposalAttachment } from "../src/lib/pdf.js";
import { SuccessStep } from "../src/components/SuccessStep.jsx";
import { createApi } from "../src/lib/api.js";

const token = `${"1".repeat(32)}.${"2".repeat(64)}`;
const link = `${window.location.origin}/?tc_estimate_choices=1#token=${token}`;
const estimateId = "10000000001";
afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.clearAllMocks();
});
function renderSuccess(api, hasChoices = true) {
  return render(
    <SuccessStep
      api={api}
      hasChoices={hasChoices}
      result={{
        estimate_id: estimateId,
        estimate_status: "draft",
        total: 5000,
      }}
      proposalHtml="<p>Fixture proposal</p>"
      booksEdits={{ line_items: [], notes: "", terms: "" }}
      customerName="Fixture"
      onProposalChange={vi.fn()}
      onBooksChange={vi.fn()}
      onStartOver={vi.fn()}
    />,
  );
}
describe("staff customer selection links", () => {
  it("leaves legacy quotes unchanged and never requests a link on render", () => {
    const api = { createCustomerChoiceLink: vi.fn(), sendEstimate: vi.fn() };
    renderSuccess(api, false);
    expect(
      screen.queryByRole("button", { name: "Create customer selection link" }),
    ).toBeNull();
    expect(api.createCustomerChoiceLink).not.toHaveBeenCalled();
    expect(api.sendEstimate).not.toHaveBeenCalled();
  });
  it("creates, copies, opens and disables a link only on explicit staff actions without sending an email", async () => {
    const api = {
      createCustomerChoiceLink: vi
        .fn()
        .mockResolvedValue({ available: true, url: link, revision: 1 }),
      revokeCustomerChoiceLink: vi.fn().mockResolvedValue({ revoked: true }),
      sendEstimate: vi.fn(),
    };
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal("navigator", { clipboard: { writeText } });
    renderSuccess(api);
    expect(api.createCustomerChoiceLink).not.toHaveBeenCalled();
    fireEvent.click(
      screen.getByRole("button", { name: "Create customer selection link" }),
    );
    const input = await screen.findByRole("textbox", {
      name: "Customer selection link",
    });
    expect(api.createCustomerChoiceLink).toHaveBeenCalledExactlyOnceWith(
      estimateId,
    );
    expect(input.value).toBe(link);
    expect(input.readOnly).toBe(true);
    const open = screen.getByRole("link", { name: "Open package choices" });
    expect(open.href).toBe(link);
    expect(open.getAttribute("rel")).toBe("noopener noreferrer");
    expect(open.getAttribute("referrerpolicy")).toBe("no-referrer");
    fireEvent.click(
      screen.getByRole("button", { name: "Copy customer selection link" }),
    );
    await screen.findByText("Customer selection link copied.");
    expect(writeText).toHaveBeenCalledExactlyOnceWith(link);
    fireEvent.click(
      screen.getByRole("button", { name: "Disable customer selection link" }),
    );
    await screen.findByText("Customer selection link disabled.");
    expect(api.revokeCustomerChoiceLink).toHaveBeenCalledExactlyOnceWith(
      estimateId,
    );
    expect(
      screen.queryByRole("textbox", { name: "Customer selection link" }),
    ).toBeNull();
    expect(
      screen.queryByRole("link", { name: "Open package choices" }),
    ).toBeNull();
    expect(
      screen.getByRole("button", { name: "Create customer selection link" }),
    ).toBeTruthy();
    expect(api.sendEstimate).not.toHaveBeenCalled();
    expect(createProposalAttachment).not.toHaveBeenCalled();
  });
  it("explains unavailable quotes without inventing a link", async () => {
    const api = {
      createCustomerChoiceLink: vi.fn().mockResolvedValue({ available: false }),
      sendEstimate: vi.fn(),
    };
    renderSuccess(api);
    fireEvent.click(
      screen.getByRole("button", { name: "Create customer selection link" }),
    );
    await screen.findByText(
      "A customer selection link is not available for this estimate.",
    );
    expect(
      screen.queryByRole("link", { name: "Open package choices" }),
    ).toBeNull();
    expect(api.sendEstimate).not.toHaveBeenCalled();
  });
  it("preserves a versioned customer link in staff copy and open controls", async () => {
    const versionedLink = link.replace("#token=", "&ver=0.4.14#token=");
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal("navigator", { clipboard: { writeText } });
    const api = {
      createCustomerChoiceLink: vi
        .fn()
        .mockResolvedValue({ available: true, url: versionedLink }),
      sendEstimate: vi.fn(),
    };
    renderSuccess(api);
    fireEvent.click(
      screen.getByRole("button", { name: "Create customer selection link" }),
    );
    const input = await screen.findByRole("textbox", {
      name: "Customer selection link",
    });
    expect(input.value).toBe(versionedLink);
    expect(
      screen.getByRole("link", { name: "Open package choices" }).href,
    ).toBe(versionedLink);
    fireEvent.click(
      screen.getByRole("button", { name: "Copy customer selection link" }),
    );
    await screen.findByText("Customer selection link copied.");
    expect(writeText).toHaveBeenCalledExactlyOnceWith(versionedLink);
    expect(api.sendEstimate).not.toHaveBeenCalled();
  });
  it.each([
    "javascript:alert(1)",
    `https://other.example/?tc_estimate_choices=1#token=${token}`,
    `${window.location.origin}/?tc_estimate_choices=1&token=${token}`,
  ])("rejects an unsafe link response %s", async (url) => {
    renderSuccess({
      createCustomerChoiceLink: vi
        .fn()
        .mockResolvedValue({ available: true, url }),
    });
    fireEvent.click(
      screen.getByRole("button", { name: "Create customer selection link" }),
    );
    await screen.findByText(
      "The customer selection link could not be created. Try again.",
    );
    expect(
      screen.queryByRole("link", { name: "Open package choices" }),
    ).toBeNull();
  });
  it("keeps failed revocation uncertain and never echoes raw error or token text", async () => {
    const api = {
      createCustomerChoiceLink: vi
        .fn()
        .mockResolvedValue({ available: true, url: link }),
      revokeCustomerChoiceLink: vi
        .fn()
        .mockRejectedValue(new Error(`provider echoed ${token}`)),
    };
    renderSuccess(api);
    fireEvent.click(
      screen.getByRole("button", { name: "Create customer selection link" }),
    );
    await screen.findByRole("textbox", { name: "Customer selection link" });
    fireEvent.click(
      screen.getByRole("button", { name: "Disable customer selection link" }),
    );
    const message = await screen.findByText(
      "We could not confirm that the link was disabled. Try disabling it again.",
    );
    expect(message.textContent).not.toContain(token);
    expect(screen.queryByText(/provider echoed/)).toBeNull();
    expect(
      screen.getByRole("button", { name: "Disable customer selection link" }),
    ).toBeTruthy();
  });
});

describe("staff link API boundary", () => {
  it("uses the staff nonce and scoped estimate ID for explicit create/revoke calls", async () => {
    const fetch = vi.fn().mockImplementation(
      async () =>
        new Response(JSON.stringify({ ok: true, data: { revoked: true } }), {
          status: 200,
        }),
    );
    vi.stubGlobal("fetch", fetch);
    const api = createApi({
      restUrl: `${window.location.origin}/wp-json/tc-estimate/v1`,
      nonce: "synthetic-staff",
    });
    await api.createCustomerChoiceLink(estimateId);
    await api.revokeCustomerChoiceLink(estimateId);
    expect(fetch.mock.calls.map(([url]) => new URL(url).pathname)).toEqual([
      "/wp-json/tc-estimate/v1/customer-choices/link",
      "/wp-json/tc-estimate/v1/customer-choices/revoke",
    ]);
    for (const [, options] of fetch.mock.calls) {
      expect(options.method).toBe("POST");
      expect(options.credentials).toBe("same-origin");
      expect(options.headers["X-WP-Nonce"]).toBe("synthetic-staff");
      expect(JSON.parse(options.body)).toEqual({ estimate_id: estimateId });
    }
  });
  it("does not automatically retry failed link creation", async () => {
    const fetch = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          ok: false,
          error: { code: "unavailable", message: "Lookup unavailable" },
        }),
        { status: 503 },
      ),
    );
    vi.stubGlobal("fetch", fetch);
    const api = createApi({
      restUrl: `${window.location.origin}/wp-json/tc-estimate/v1`,
      nonce: "synthetic-staff",
    });
    await expect(api.createCustomerChoiceLink(estimateId)).rejects.toThrow();
    expect(fetch).toHaveBeenCalledTimes(1);
  });
});
