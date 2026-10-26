/** Reconstructed from the saved v0.4.8 deployment bundle; not recovered original source. */
import html2canvas from "html2canvas";
import { jsPDF } from "jspdf";

export function safeFilename(customerName) {
  return (
    String(customerName || "Customer")
      .normalize("NFKD")
      .replace(/[^a-zA-Z0-9 _-]/g, "")
      .trim()
      .replace(/\s+/g, "-") || "Customer"
  );
}

export function waitForImages(element) {
  return Promise.all(
    Array.from(element.querySelectorAll("img")).map(
      (image) =>
        new Promise((resolve) => {
          if (image.complete) {
            resolve();
            return;
          }
          const finish = () => resolve();
          image.addEventListener("load", finish, { once: true });
          image.addEventListener("error", finish, { once: true });
          window.setTimeout(finish, 3000);
        }),
    ),
  );
}

/** Find a nearby blank row band without scanning or recapturing the whole page. */
export function findPageSliceHeight(canvas, offset, maxHeight) {
  const remaining = Math.floor(canvas.height - offset);
  if (!Number.isFinite(remaining) || remaining <= 0) return 0;
  const limit = Math.floor(maxHeight);
  const fixed = Math.min(
    remaining,
    Number.isFinite(limit) && limit > 0 ? limit : 1,
  );
  if (fixed === remaining) return fixed;

  const width = Math.floor(canvas.width);
  if (!Number.isFinite(width) || width <= 0) return fixed;
  const scanHeight = Math.max(1, Math.floor(fixed * 0.12));
  const scanStart = offset + fixed - scanHeight;
  try {
    const context = canvas.getContext?.("2d");
    const pixels = context?.getImageData?.(
      0,
      scanStart,
      width,
      scanHeight,
    )?.data;
    if (!pixels || pixels.length < width * scanHeight * 4) return fixed;

    let bandStart = -1;
    let paragraphCut = 0;
    let smallCut = 0;
    const finishBand = (end) => {
      if (bandStart < 0) return;
      const length = end - bandStart;
      // An existing cut already inside the gap needs no earlier movement.
      const cut =
        end === scanHeight
          ? fixed
          : fixed - scanHeight + Math.floor((bandStart + end) / 2);
      if (length >= 10) paragraphCut = cut;
      else if (length >= 2) smallCut = cut;
      bandStart = -1;
    };
    for (let row = 0; row < scanHeight; row += 1) {
      let blank = true;
      const rowEnd = (row + 1) * width * 4;
      for (let pixel = row * width * 4; pixel < rowEnd; pixel += 4) {
        if (
          pixels[pixel + 3] !== 0 &&
          (pixels[pixel] < 245 ||
            pixels[pixel + 1] < 245 ||
            pixels[pixel + 2] < 245)
        ) {
          blank = false;
          break;
        }
      }
      if (blank && bandStart < 0) bandStart = row;
      else if (!blank) finishBand(row);
    }
    finishBand(scanHeight);
    return Math.max(1, Math.min(fixed, paragraphCut || smallCut || fixed));
  } catch {
    // Tainted canvases and unavailable pixel access keep the fixed safe cut.
    return fixed;
  }
}

export async function createProposalAttachment({ html, customerName }) {
  if (!html) return null;
  const source = document.createElement("div");
  source.className = "tc-pdf-source";
  source.setAttribute("aria-hidden", "true");
  source.innerHTML = html;
  Object.assign(source.style, {
    position: "fixed",
    left: "-12000px",
    top: "0",
    width: "816px",
    boxSizing: "border-box",
    padding: "52px",
    background: "#fff",
    color: "#1f2933",
    fontFamily: "Arial, Helvetica, sans-serif",
    fontSize: "15px",
    lineHeight: "1.45",
  });
  document.body.appendChild(source);

  try {
    await waitForImages(source);
    const canvas = await html2canvas(source, {
      scale: 1.35,
      backgroundColor: "#ffffff",
      useCORS: true,
      logging: false,
      windowWidth: 920,
    });
    if (
      !Number.isFinite(canvas.width) ||
      canvas.width <= 0 ||
      !Number.isFinite(canvas.height) ||
      canvas.height <= 0
    )
      throw new Error(
        "The polished proposal could not be rendered. Try again.",
      );
    const pdf = new jsPDF({
      unit: "pt",
      format: "letter",
      orientation: "portrait",
      compress: true,
      putOnlyUsedFonts: true,
      precision: 2,
    });
    const margin = 28;
    const pageWidth = pdf.internal.pageSize.getWidth();
    const pageHeight = pdf.internal.pageSize.getHeight();
    const contentWidth = pageWidth - margin * 2;
    const contentHeight = pageHeight - margin * 2;
    // Keep one capture and the original page geometry, moving cuts only into
    // nearby whitespace so a footer or text line can continue on the next page.
    const sliceHeight = Math.max(
      1,
      Math.floor((contentHeight * canvas.width) / contentWidth),
    );
    for (let offset = 0, page = 0; offset < canvas.height; page += 1) {
      if (page > 0) pdf.addPage();
      const height = findPageSliceHeight(canvas, offset, sliceHeight);
      const slice = document.createElement("canvas");
      slice.width = canvas.width;
      slice.height = height;
      const context = slice.getContext("2d");
      context.fillStyle = "#ffffff";
      context.fillRect(0, 0, slice.width, slice.height);
      context.drawImage(
        canvas,
        0,
        offset,
        canvas.width,
        height,
        0,
        0,
        canvas.width,
        height,
      );
      pdf.addImage(
        slice.toDataURL("image/jpeg", 0.8),
        "JPEG",
        margin,
        margin,
        contentWidth,
        (height * contentWidth) / canvas.width,
        undefined,
        "FAST",
      );
      offset += height;
    }
    const dataUri = pdf.output("datauristring");
    const data = dataUri.slice(dataUri.indexOf(",") + 1);
    const size = Math.ceil((data.length * 3) / 4);
    if (size > 4 * 1024 * 1024) {
      throw new Error(
        "The polished proposal is still larger than 4 MB. Remove oversized pasted images and try again.",
      );
    }
    return {
      filename: `Temp-Control-Proposal-${safeFilename(customerName)}.pdf`,
      data,
      size,
      html_b64: btoa(unescape(encodeURIComponent(html))),
    };
  } finally {
    source.remove();
  }
}
