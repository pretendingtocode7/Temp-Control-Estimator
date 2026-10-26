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
  findPageSliceHeight,
  safeFilename,
  waitForImages,
} from "../src/lib/pdf.js";

function pixelCanvas({
  width = 2,
  height = 2500,
  bands = [],
  channel = 245,
  alpha = 255,
} = {}) {
  const getImageData = vi.fn((_x, start, readWidth, readHeight) => {
    const data = new Uint8ClampedArray(readWidth * readHeight * 4);
    for (let row = 0; row < readHeight; row += 1) {
      const blank = bands.some(
        ([first, end]) => start + row >= first && start + row < end,
      );
      for (let column = 0; column < readWidth; column += 1) {
        const pixel = (row * readWidth + column) * 4;
        data[pixel] = data[pixel + 1] = data[pixel + 2] = blank ? channel : 20;
        data[pixel + 3] = blank ? alpha : 255;
      }
    }
    return { data };
  });
  return {
    width,
    height,
    getContext: vi.fn(() => ({ getImageData })),
    getImageData,
  };
}

describe("whitespace-aware PDF page cuts", () => {
  it("prefers a paragraph band over a smaller later gap and reads only the final twelve percent", () => {
    const canvas = pixelCanvas({
      bands: [
        [800, 850],
        [900, 912],
        [970, 974],
      ],
    });
    expect(findPageSliceHeight(canvas, 0, 1000)).toBe(906);
    expect(canvas.getImageData).toHaveBeenCalledExactlyOnceWith(0, 880, 2, 120);
  });

  it("chooses the nearest paragraph band when the nominal boundary still crosses content", () => {
    const canvas = pixelCanvas({
      bands: [
        [900, 912],
        [970, 986],
      ],
    });
    expect(findPageSliceHeight(canvas, 0, 1000)).toBe(978);
  });

  it("keeps the nominal page cut when it already falls within a qualifying whitespace band", () => {
    const canvas = pixelCanvas({
      bands: [
        [900, 912],
        [984, 1000],
      ],
    });
    expect(findPageSliceHeight(canvas, 0, 1000)).toBe(1000);
  });

  it("falls back to the latest two-pixel gap when there is no paragraph gap and ignores one-pixel gaps", () => {
    const canvas = pixelCanvas({
      bands: [
        [900, 901],
        [940, 944],
        [990, 992],
        [998, 999],
      ],
    });
    expect(findPageSliceHeight(canvas, 0, 1000)).toBe(991);
  });

  it.each([
    [245, 255, 910],
    [244, 255, 1000],
    [0, 0, 910],
  ])(
    "classifies RGB %i alpha %i using the explicit near-white/transparent thresholds",
    (channel, alpha, expected) => {
      const canvas = pixelCanvas({ bands: [[900, 920]], channel, alpha });
      expect(findPageSliceHeight(canvas, 0, 1000)).toBe(expected);
    },
  );

  it("keeps a fixed positive slice when the inspected tail has no suitable band", () => {
    const canvas = pixelCanvas({ bands: [[100, 300]] });
    expect(findPageSliceHeight(canvas, 200, 1000)).toBe(1000);
    expect(canvas.getImageData).toHaveBeenCalledExactlyOnceWith(
      0,
      1080,
      2,
      120,
    );
  });

  it("leaves the final partial or exactly fitting page unchanged without reading pixels", () => {
    const canvas = pixelCanvas({ height: 1500, bands: [[1400, 1450]] });
    expect(findPageSliceHeight(canvas, 1000, 1000)).toBe(500);
    expect(findPageSliceHeight(canvas, 500, 1000)).toBe(1000);
    expect(canvas.getContext).not.toHaveBeenCalled();
    expect(canvas.getImageData).not.toHaveBeenCalled();
  });

  it.each([
    ["missing context method", undefined],
    ["missing context", () => null],
    ["missing pixel method", () => ({})],
    [
      "context exception",
      () => {
        throw new Error("Context unavailable");
      },
    ],
    [
      "pixel exception",
      () => ({
        getImageData() {
          throw new Error("Tainted canvas");
        },
      }),
    ],
    ["invalid pixel result", () => ({ getImageData: () => null })],
    [
      "short pixel buffer",
      () => ({ getImageData: () => ({ data: new Uint8ClampedArray(1) }) }),
    ],
  ])("uses fixed page height for %s", (_label, getContext) => {
    const canvas = { width: 2, height: 2500, getContext };
    expect(findPageSliceHeight(canvas, 0, 1000)).toBe(1000);
  });
});

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

  it("renders contiguous whitespace-aware slices from one capture without dropping or duplicating source rows", async () => {
    const canvas = pixelCanvas({
      width: 816,
      height: 2200,
      bands: [
        [1000, 1014],
        [2020, 2032],
      ],
    });
    mocks.rasterize.mockResolvedValue(canvas);
    await createProposalAttachment({
      html: "<p>Long fixture proposal</p>",
      customerName: "Fixture",
    });
    expect(mocks.rasterize).toHaveBeenCalledTimes(1);
    expect(mocks.pdfConstructor).toHaveBeenCalledWith(
      expect.objectContaining({
        unit: "pt",
        format: "letter",
        orientation: "portrait",
      }),
    );
    expect(drawImage).toHaveBeenCalledTimes(3);
    const slices = drawImage.mock.calls.map((args) => ({
      offset: args[2],
      height: args[4],
    }));
    expect(slices).toEqual([
      { offset: 0, height: 1007 },
      { offset: 1007, height: 1019 },
      { offset: 2026, height: 174 },
    ]);
    let nextRow = 0;
    for (const [index, args] of drawImage.mock.calls.entries()) {
      expect(args[0]).toBe(canvas);
      expect(args[2]).toBe(nextRow);
      expect(args[4]).toBeGreaterThan(0);
      expect(args[4]).toBeLessThanOrEqual(1080);
      expect(args.slice(5)).toEqual([0, 0, 816, args[4]]);
      nextRow += args[4];
      const image = mocks.addImage.mock.calls[index];
      expect(image.slice(0, 5)).toEqual([
        "data:image/jpeg;base64,fixture",
        "JPEG",
        28,
        28,
        556,
      ]);
      expect(image[5]).toBeCloseTo((args[4] * 556) / 816);
    }
    expect(nextRow).toBe(canvas.height);
    expect(mocks.addPage).toHaveBeenCalledTimes(2);
    expect(canvas.getImageData).toHaveBeenCalledTimes(2);
    for (const [index, read] of canvas.getImageData.mock.calls.entries()) {
      expect(read[1]).toBeGreaterThanOrEqual(
        slices[index].offset + Math.floor(1080 * 0.88),
      );
      expect(read[1] + read[3]).toBe(slices[index].offset + 1080);
    }
    expect(HTMLCanvasElement.prototype.toDataURL).toHaveBeenCalledWith(
      "image/jpeg",
      0.8,
    );
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
