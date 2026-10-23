import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const mocks = vi.hoisted(() => ({
  rasterize: vi.fn(),
  pdfConstructor: vi.fn(),
  addPage: vi.fn(),
  addImage: vi.fn(),
  output: vi.fn(),
}));
vi.mock("html2canvas", () => ({ default: mocks.rasterize }));
vi.mock("jspdf", () => ({
  jsPDF: vi.fn(function (options) {
    mocks.pdfConstructor(options);
    this.internal = { pageSize: { getWidth: () => 612, getHeight: () => 792 } };
    this.addPage = mocks.addPage;
    this.addImage = mocks.addImage;
    this.output = mocks.output;
  }),
}));
import {
  createProposalAttachment,
  safeFilename,
  waitForImages,
} from "../src/lib/pdf.js";

describe("v0.4.8 polished PDF contract", () => {
  let drawImage;
  beforeEach(() => {
    vi.clearAllMocks();
    drawImage = vi.fn();
    vi.spyOn(HTMLCanvasElement.prototype, "getContext").mockReturnValue({
      fillRect: vi.fn(),
      drawImage,
    });
    vi.spyOn(HTMLCanvasElement.prototype, "toDataURL").mockReturnValue(
      "data:image/jpeg;base64,fixture",
    );
    mocks.rasterize.mockResolvedValue({ width: 816, height: 2200 });
    mocks.output.mockReturnValue("data:application/pdf;base64,JVBERi0xLjQK");
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = "";
  });

  it("uses safe customer filenames without claiming recovered source", () => {
    expect(safeFilename("  Résidence / José & Co.  ")).toBe(
      "Residence-Jose-Co",
    );
    expect(safeFilename("💡/")).toBe("Customer");
    expect(safeFilename(null)).toBe("Customer");
  });

  it("does not build an attachment without a preview", async () => {
    await expect(
      createProposalAttachment({ html: "", customerName: "Fixture" }),
    ).resolves.toBeNull();
    expect(mocks.rasterize).not.toHaveBeenCalled();
  });

  it("preserves reference dimensions, raster settings, page slicing and UTF-8 HTML", async () => {
    const html = "<h1>Résumé – Fixture 客户</h1><p>Approved scope</p>";
    const attachment = await createProposalAttachment({
      html,
      customerName: "José Fixture",
    });
    const [source, options] = mocks.rasterize.mock.calls[0];
    expect(source.innerHTML).toBe(html);
    expect(source.className).toBe("tc-pdf-source");
    expect(source.getAttribute("aria-hidden")).toBe("true");
    expect(source.style).toMatchObject({
      width: "816px",
      padding: "52px",
      left: "-12000px",
      fontSize: "15px",
      lineHeight: "1.45",
    });
    expect(options).toEqual({
      scale: 1.35,
      backgroundColor: "#ffffff",
      useCORS: true,
      logging: false,
      windowWidth: 920,
    });
    expect(mocks.pdfConstructor).toHaveBeenCalledWith({
      unit: "pt",
      format: "letter",
      orientation: "portrait",
      compress: true,
      putOnlyUsedFonts: true,
      precision: 2,
    });
    expect(mocks.addPage).toHaveBeenCalledTimes(2);
    expect(mocks.addImage).toHaveBeenCalledTimes(3);
    expect(drawImage.mock.calls.map((args) => [args[2], args[4]])).toEqual([
      [0, 1080],
      [1080, 1080],
      [2160, 40],
    ]);
    for (const args of mocks.addImage.mock.calls) {
      expect(args.slice(0, 5)).toEqual([
        "data:image/jpeg;base64,fixture",
        "JPEG",
        28,
        28,
        556,
      ]);
      expect(args.slice(6)).toEqual([undefined, "FAST"]);
    }
    expect(HTMLCanvasElement.prototype.toDataURL).toHaveBeenCalledWith(
      "image/jpeg",
      0.8,
    );
    expect(attachment).toEqual({
      filename: "Temp-Control-Proposal-Jose-Fixture.pdf",
      data: "JVBERi0xLjQK",
      size: 9,
      html_b64: btoa(unescape(encodeURIComponent(html))),
    });
    expect(document.querySelector(".tc-pdf-source")).toBeNull();
  });

  it("removes the offscreen source on capture failure", async () => {
    mocks.rasterize.mockRejectedValue(new Error("Synthetic capture failure"));
    await expect(
      createProposalAttachment({ html: "<p>Fixture</p>" }),
    ).rejects.toThrow("Synthetic capture failure");
    expect(document.querySelector(".tc-pdf-source")).toBeNull();
    expect(mocks.output).not.toHaveBeenCalled();
  });

  it("rejects over 4 MiB and still cleans up", async () => {
    mocks.output.mockReturnValue(
      "data:application/pdf;base64," +
        "A".repeat(Math.floor((4 * 1024 * 1024 * 4) / 3) + 1),
    );
    await expect(
      createProposalAttachment({ html: "<p>Fixture</p>" }),
    ).rejects.toThrow("larger than 4 MB");
    expect(document.querySelector(".tc-pdf-source")).toBeNull();
  });

  it("waits for load, error or a three-second image timeout", async () => {
    vi.useFakeTimers();
    const root = document.createElement("div");
    root.innerHTML = "<img><img><img><img>";
    const images = [...root.querySelectorAll("img")];
    images.forEach((image, index) =>
      Object.defineProperty(image, "complete", { value: index === 0 }),
    );
    const complete = vi.fn();
    const waiting = waitForImages(root).then(complete);
    images[1].dispatchEvent(new Event("load"));
    images[2].dispatchEvent(new Event("error"));
    await vi.advanceTimersByTimeAsync(2999);
    expect(complete).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    await waiting;
    expect(complete).toHaveBeenCalledOnce();
  });
});
