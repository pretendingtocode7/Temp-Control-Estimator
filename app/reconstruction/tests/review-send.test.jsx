import * as React from "react";
import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
vi.mock("../src/lib/pdf.js", () => ({ createProposalAttachment: vi.fn() }));
import { createProposalAttachment } from "../src/lib/pdf.js";
import { ApiError } from "../src/lib/api.js";
import { BooksEditor, ProposalEditor } from "../src/components/Editors.jsx";
import { ReviewStep } from "../src/components/ReviewStep.jsx";
import {
  MAX_SEND_POLLS,
  POLL_INTERVAL_MS,
  SuccessStep,
} from "../src/components/SuccessStep.jsx";

const initialBooks = () => ({
  line_items: [
    {
      key: "system_1_furnace_0",
      item_id: "100000000000001",
      name: "Fixture furnace",
      rate: 3200,
      description: "Original furnace scope",
    },
    {
      key: "system_1_permit_0",
      item_id: "100000000000002",
      name: "Municipal permit",
      rate: 300,
      description: "Original permit scope",
    },
  ],
  notes: "Customer approval notes",
  terms: "Original terms",
});
const proposalHtml = "<h1>Fixture proposal</h1><p>Approved scope</p>";
const attachment = {
  filename: "Temp-Control-Proposal-Fixture.pdf",
  data: "JVBERi0xLjQK",
  size: 9,
  html_b64: "fixture-utf8-base64",
};
const result = {
  estimate_id: "900000000000001",
  estimate_number: "EST-TEST",
  estimate_status: "draft",
  estimate_url: "https://books.example.test/estimate",
  deal_id: "900000000000002",
  deal_url: "https://crm.example.test/deal",
  total: 3500,
  books_email_sent: false,
};
function makeApi() {
  return {
    preview: vi
      .fn()
      .mockResolvedValue({ html: proposalHtml, books_preview: initialBooks() }),
    updateEstimate: vi
      .fn()
      .mockResolvedValue({ updated: true, message: "Books draft updated." }),
    sendEstimate: vi
      .fn()
      .mockResolvedValue({ sent: true, message: "Synthetic email complete." }),
    sendStatus: vi.fn(),
  };
}
function reviewProps(overrides = {}) {
  return {
    api: makeApi(),
    payload: { template_id: 1, systems: [] },
    draft: {
      customer: { name: "Fixture Customer" },
      template: { name: "Full replacement" },
      systems: [{}],
      pricing: { total: 3500 },
      idempotency_key: "tc-test-key",
      preview_html: "",
      books_edits: null,
    },
    submitState: { status: "idle", error: null },
    onSubmit: vi.fn(),
    onRetryNewKey: vi.fn(),
    onPreview: vi.fn(),
    onBooksChange: vi.fn(),
    ...overrides,
  };
}
function SuccessHarness({
  api,
  html = proposalHtml,
  generated = result,
  onBooksChange = () => {},
}) {
  const [booksEdits, setBooksEdits] = React.useState(initialBooks);
  const [polishedHtml, setPolishedHtml] = React.useState(html);
  return (
    <SuccessStep
      api={api}
      result={generated}
      proposalHtml={polishedHtml}
      booksEdits={booksEdits}
      customerName="Fixture Customer"
      onProposalChange={setPolishedHtml}
      onBooksChange={(value) => {
        onBooksChange(value);
        setBooksEdits(value);
      }}
      onStartOver={vi.fn()}
    />
  );
}
async function send() {
  await act(async () => {
    fireEvent.click(
      screen.getByRole("button", {
        name: /^(Send polished proposal to customer|Save changes & send polished proposal)$/,
      }),
    );
  });
}
async function tick(milliseconds) {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(milliseconds);
  });
}

beforeEach(() => {
  createProposalAttachment.mockReset().mockResolvedValue(attachment);
});
afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe("separate polished and Books editors", () => {
  it("preserves an edit node on unchanged HTML, reports HTML edits and pastes plain text", () => {
    const onChange = vi.fn();
    const execute = vi.fn();
    Object.defineProperty(document, "execCommand", {
      configurable: true,
      value: execute,
    });
    const { rerender } = render(
      <ProposalEditor html="<p>Original</p>" onChange={onChange} />,
    );
    const editor = screen.getByRole("textbox", {
      name: "Edit polished proposal",
    });
    editor.innerHTML = "<p>Edited technician scope</p>";
    const editNode = editor.firstChild;
    fireEvent.input(editor);
    expect(onChange).toHaveBeenCalledWith("<p>Edited technician scope</p>");
    rerender(
      <ProposalEditor
        html="<p>Edited technician scope</p>"
        onChange={onChange}
      />,
    );
    expect(editor.firstChild).toBe(editNode);
    fireEvent.paste(editor, {
      clipboardData: {
        getData: vi.fn().mockReturnValue("Plain pasted <scope>"),
      },
    });
    expect(execute).toHaveBeenCalledWith(
      "insertText",
      false,
      "Plain pasted <scope>",
    );
    rerender(
      <ProposalEditor
        html="<p>Reset preview</p>"
        onChange={onChange}
        disabled
      />,
    );
    expect(editor.innerHTML).toBe("<p>Reset preview</p>");
    expect(editor.getAttribute("contenteditable")).toBe("false");
  });

  it("changes Books text only, preserving row order, IDs, keys and rates", () => {
    const books = initialBooks(),
      onChange = vi.fn();
    render(<BooksEditor value={books} onChange={onChange} />);
    const descriptions = screen.getAllByRole("textbox", {
      name: "Books item description",
    });
    fireEvent.change(descriptions[1], {
      target: { value: "Edited permit scope" },
    });
    const next = onChange.mock.calls[0][0];
    expect(next.line_items[0]).toBe(books.line_items[0]);
    expect(next.line_items[1]).toEqual({
      ...books.line_items[1],
      description: "Edited permit scope",
    });
    expect(books.line_items[1].description).toBe("Original permit scope");
    expect(descriptions[0].maxLength).toBe(2000);
    expect(
      screen.getByRole("textbox", { name: "Books customer notes" }).maxLength,
    ).toBe(5000);
    fireEvent.change(
      screen.getByRole("textbox", { name: "Books terms and conditions" }),
      { target: { value: "" } },
    );
    expect(onChange.mock.calls[1][0]).toEqual({ ...books, terms: "" });
  });
});

describe("review preview and generation retry controls", () => {
  it("initializes the two preview versions but never creates or sends a draft", async () => {
    const props = reviewProps();
    render(<ReviewStep {...props} />);
    await waitFor(() =>
      expect(props.onPreview).toHaveBeenCalledWith(proposalHtml),
    );
    expect(props.api.preview).toHaveBeenCalledWith(props.payload);
    expect(props.onBooksChange).toHaveBeenCalledWith(initialBooks());
    expect(props.onSubmit).not.toHaveBeenCalled();
    expect(props.api.sendEstimate).not.toHaveBeenCalled();
  });

  it("preserves technician edits on preview refresh while reset uses the server HTML", async () => {
    const props = reviewProps();
    props.draft = {
      ...props.draft,
      preview_html: "<p>Technician edited</p>",
      books_edits: initialBooks(),
    };
    const { rerender } = render(<ReviewStep {...props} />);
    await screen.findByRole("button", { name: "Reset polished version" });
    expect(props.onPreview).not.toHaveBeenCalled();
    expect(props.onBooksChange).not.toHaveBeenCalled();
    fireEvent.click(
      screen.getByRole("button", { name: "Reset polished version" }),
    );
    expect(props.onPreview).toHaveBeenCalledWith(proposalHtml);
    props.onPreview.mockClear();
    rerender(
      <ReviewStep {...props} payload={{ ...props.payload, template_id: 2 }} />,
    );
    await waitFor(() => expect(props.api.preview).toHaveBeenCalledTimes(2));
    expect(props.onPreview).not.toHaveBeenCalled();
    expect(props.onBooksChange).not.toHaveBeenCalled();
  });

  it.each([
    ["tc_estimate_in_flight", "Wait and replay", "onSubmit"],
    ["tc_estimate_prior_error", "Try again with a new key", "onRetryNewKey"],
    ["synthetic_error", "Retry", "onSubmit"],
  ])("keeps explicit retry semantics for %s", async (code, label, handler) => {
    const props = reviewProps({
      submitState: {
        status: "error",
        error: new ApiError(code, "Synthetic generation error", 409, null),
      },
    });
    render(<ReviewStep {...props} />);
    fireEvent.click(screen.getByRole("button", { name: label }));
    expect(props[handler]).toHaveBeenCalledOnce();
    await waitFor(() => expect(props.api.preview).toHaveBeenCalledOnce());
  });
});

describe("explicit draft save, PDF and send controls", () => {
  it("does not send on mount and uses safe preview links", () => {
    const api = makeApi();
    render(<SuccessHarness api={api} />);
    expect(api.updateEstimate).not.toHaveBeenCalled();
    expect(createProposalAttachment).not.toHaveBeenCalled();
    expect(api.sendEstimate).not.toHaveBeenCalled();
    for (const link of screen.getAllByRole("link")) {
      expect(link.target).toBe("_blank");
      expect(link.rel).toBe("noopener noreferrer");
    }
  });

  it("sends the polished attachment only after an explicit click", async () => {
    const api = makeApi();
    render(<SuccessHarness api={api} />);
    await send();
    expect(createProposalAttachment).toHaveBeenCalledWith({
      html: proposalHtml,
      customerName: "Fixture Customer",
    });
    expect(api.sendEstimate).toHaveBeenCalledWith(
      result.estimate_id,
      attachment,
    );
    expect(api.updateEstimate).not.toHaveBeenCalled();
    expect(api.sendStatus).not.toHaveBeenCalled();
    expect(screen.getByText("Synthetic email complete.")).toBeTruthy();
    expect(
      screen.queryByRole("button", {
        name: "Send polished proposal to customer",
      }),
    ).toBeNull();
  });

  it("saves dirty Books text before building the PDF and sending", async () => {
    const api = makeApi(),
      order = [];
    api.updateEstimate.mockImplementation(async () => {
      order.push("save");
      return { updated: true };
    });
    createProposalAttachment.mockImplementation(async () => {
      order.push("pdf");
      return attachment;
    });
    api.sendEstimate.mockImplementation(async () => {
      order.push("send");
      return { sent: true };
    });
    render(<SuccessHarness api={api} />);
    fireEvent.click(screen.getByRole("tab", { name: "Books version" }));
    fireEvent.change(
      screen.getAllByRole("textbox", { name: "Books item description" })[0],
      { target: { value: "Edited before send" } },
    );
    await send();
    expect(order).toEqual(["save", "pdf", "send"]);
    expect(api.updateEstimate.mock.calls[0][1].line_items[0].description).toBe(
      "Edited before send",
    );
    expect(api.updateEstimate.mock.calls[0][1].line_items[0].rate).toBe(3200);
  });

  it("does not build/send if saving dirty Books edits fails", async () => {
    const api = makeApi();
    api.updateEstimate.mockRejectedValue(
      new Error("Synthetic draft update failed"),
    );
    render(<SuccessHarness api={api} />);
    fireEvent.click(screen.getByRole("tab", { name: "Books version" }));
    fireEvent.change(
      screen.getAllByRole("textbox", { name: "Books item description" })[0],
      { target: { value: "Edited scope" } },
    );
    await send();
    expect(createProposalAttachment).not.toHaveBeenCalled();
    expect(api.sendEstimate).not.toHaveBeenCalled();
    expect(
      screen.getAllByText("Synthetic draft update failed").length,
    ).toBeGreaterThan(0);
  });

  it("treats a queue response as pending work and polls every two seconds", async () => {
    vi.useFakeTimers();
    const api = makeApi();
    api.sendEstimate.mockResolvedValue({ job_id: "job-fixture", queued: true });
    api.sendStatus
      .mockResolvedValueOnce({ job_id: "job-fixture", status: "processing" })
      .mockResolvedValueOnce({
        job_id: "job-fixture",
        status: "sent",
        sent: true,
      });
    render(<SuccessHarness api={api} />);
    await send();
    expect(
      screen.getByRole("button", { name: "Sending in background…" }).disabled,
    ).toBe(true);
    expect(api.sendStatus).not.toHaveBeenCalled();
    await tick(POLL_INTERVAL_MS - 1);
    expect(api.sendStatus).not.toHaveBeenCalled();
    await tick(1);
    expect(api.sendStatus).toHaveBeenCalledTimes(1);
    await tick(POLL_INTERVAL_MS);
    expect(api.sendStatus).toHaveBeenCalledTimes(2);
    expect(api.sendEstimate).toHaveBeenCalledOnce();
    expect(screen.getByText("Estimate emailed to the customer.")).toBeTruthy();
  });

  it("prevents resending when a known job loses its status connection", async () => {
    vi.useFakeTimers();
    const api = makeApi();
    api.sendEstimate.mockResolvedValue({ job_id: "job-fixture", queued: true });
    api.sendStatus.mockRejectedValue(new Error("Synthetic status outage"));
    render(<SuccessHarness api={api} />);
    await send();
    await tick(POLL_INTERVAL_MS);
    expect(screen.getByText(/lost its status connection/)).toBeTruthy();
    expect(
      screen.queryByRole("button", { name: /send polished proposal/i }),
    ).toBeNull();
    await tick(POLL_INTERVAL_MS * 3);
    expect(api.sendStatus).toHaveBeenCalledOnce();
    expect(api.sendEstimate).toHaveBeenCalledOnce();
  });

  it("allows a user-driven retry after an explicit terminal job failure", async () => {
    vi.useFakeTimers();
    const api = makeApi();
    api.sendEstimate
      .mockResolvedValueOnce({ job_id: "job-fixture" })
      .mockResolvedValueOnce({ sent: true });
    api.sendStatus.mockResolvedValue({
      job_id: "job-fixture",
      status: "error",
      message: "Synthetic terminal failure",
    });
    render(<SuccessHarness api={api} />);
    await send();
    await tick(POLL_INTERVAL_MS);
    expect(screen.getByText("Synthetic terminal failure")).toBeTruthy();
    expect(api.sendEstimate).toHaveBeenCalledOnce();
    expect(
      screen.getByRole("button", { name: "Send polished proposal to customer" })
        .disabled,
    ).toBe(false);
    await send();
    expect(api.sendEstimate).toHaveBeenCalledTimes(2);
  });

  it("bounds polling to sixty attempts without making another send", async () => {
    vi.useFakeTimers();
    const api = makeApi();
    api.sendEstimate.mockResolvedValue({ job_id: "job-fixture" });
    api.sendStatus.mockResolvedValue({
      job_id: "job-fixture",
      status: "processing",
    });
    render(<SuccessHarness api={api} />);
    await send();
    await tick(POLL_INTERVAL_MS * MAX_SEND_POLLS);
    expect(api.sendStatus).toHaveBeenCalledTimes(MAX_SEND_POLLS);
    expect(api.sendEstimate).toHaveBeenCalledOnce();
    expect(screen.getByText(/still processing in the background/)).toBeTruthy();
    expect(
      screen.queryByRole("button", { name: /send polished proposal/i }),
    ).toBeNull();
  });

  it("reports missing proposal or job IDs without automatic retries", async () => {
    const api = makeApi();
    const { unmount } = render(<SuccessHarness api={api} html="" />);
    await send();
    expect(
      screen.getByText(/polished proposal preview is unavailable/),
    ).toBeTruthy();
    expect(api.sendEstimate).not.toHaveBeenCalled();
    unmount();
    api.sendEstimate.mockResolvedValue({ queued: true });
    render(<SuccessHarness api={api} />);
    await send();
    expect(
      screen.getByText("WordPress did not return an email job ID."),
    ).toBeTruthy();
    expect(api.sendEstimate).toHaveBeenCalledOnce();
    expect(api.sendStatus).not.toHaveBeenCalled();
  });
});
