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
    // The reference slices the captured canvas directly, including at text rows.
    const sliceHeight = Math.max(
      1,
      Math.floor((contentHeight * canvas.width) / contentWidth),
    );
    for (
      let offset = 0, page = 0;
      offset < canvas.height;
      offset += sliceHeight, page += 1
    ) {
      if (page > 0) pdf.addPage();
      const height = Math.min(sliceHeight, canvas.height - offset);
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
