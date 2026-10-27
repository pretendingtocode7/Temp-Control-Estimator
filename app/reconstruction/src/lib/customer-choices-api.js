/** Public customer access is separate from staff cookies, nonces and retry rules. */
const PUBLIC_TIMEOUT_MS = 30_000;
const KNOWN_CODES = [
  "unavailable",
  "changed",
  "stale_revision",
  "bad_selection",
  "in_flight",
  "rate_limited",
  "provider",
  "pending",
];
const MESSAGES = {
  unavailable:
    "This link is unavailable or has expired. Please contact Temp Control for a new link.",
  changed: "This estimate has changed. Refresh it before choosing again.",
  stale_revision:
    "This estimate was updated elsewhere. Refresh it before choosing again.",
  bad_selection: "Choose one available option for every system.",
  in_flight:
    "An update is already in progress. Check its status before making another change.",
  rate_limited: "Please wait before checking this estimate again.",
  provider: "The estimate is temporarily unavailable. Please try again.",
  pending: "The update is still being checked.",
  network: "The connection ended before the result could be confirmed.",
  timeout: "The result could not be confirmed before the connection timed out.",
};

export class CustomerChoicesError extends Error {
  constructor(code = "unavailable", status = 0) {
    super(MESSAGES[code] || MESSAGES.unavailable);
    this.name = "CustomerChoicesError";
    this.code = code;
    this.status = status;
  }
}

export function readCustomerChoiceToken(fragment = window.location.hash) {
  const params = new URLSearchParams(String(fragment || "").replace(/^#/, ""));
  const tokens = params.getAll("token");
  return tokens.length === 1 && /^[a-f0-9]{32}\.[a-f0-9]{64}$/i.test(tokens[0])
    ? tokens[0]
    : "";
}

export function safeCustomerChoicesLink(value) {
  try {
    const url = new URL(value, window.location.origin);
    const versions = url.searchParams.getAll("ver");
    if (
      url.origin !== window.location.origin ||
      url.username ||
      url.password ||
      url.searchParams.getAll("tc_estimate_choices").length !== 1 ||
      url.searchParams.get("tc_estimate_choices") !== "1" ||
      versions.length > 1 ||
      (versions.length === 1 &&
        !/^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$/.test(versions[0])) ||
      [...url.searchParams.keys()].some(
        (key) => key !== "tc_estimate_choices" && key !== "ver",
      ) ||
      !readCustomerChoiceToken(url.hash)
    )
      return "";
    return url.href;
  } catch {
    return "";
  }
}

export function safeBooksReviewUrl(value) {
  try {
    const url = new URL(value);
    const domains = ["com", "eu", "in", "com.au", "jp", "ca", "com.cn", "sa"];
    const hosts = domains.flatMap((domain) => [
      `books.zoho.${domain}`,
      `securebooks.zoho.${domain}`,
    ]);
    let path = url.pathname;
    let fragment = url.hash.replace(/^#/, "");
    for (let pass = 0; pass < 2; pass += 1) {
      path = decodeURIComponent(path);
      fragment = decodeURIComponent(fragment);
    }
    if (
      url.protocol !== "https:" ||
      url.username ||
      url.password ||
      (url.port && url.port !== "443") ||
      !hosts.includes(url.hostname.toLowerCase()) ||
      /(^|\/)(app|admin)(\/|$|\?)/i.test(path) ||
      /(^|\/)(app|admin)(\/|$|\?)/i.test(fragment)
    )
      return "";
    return url.href;
  } catch {
    return "";
  }
}

export function createCustomerChoicesApi({ restUrl }) {
  let base;
  try {
    const url = new URL(restUrl, window.location.origin);
    if (
      !restUrl ||
      url.origin !== window.location.origin ||
      url.username ||
      url.password ||
      url.search ||
      url.hash
    )
      throw new Error();
    base = url.href.replace(/\/+$/, "");
  } catch {
    throw new CustomerChoicesError("unavailable");
  }

  async function post(path, body) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), PUBLIC_TIMEOUT_MS);
    try {
      const response = await fetch(base + path, {
        method: "POST",
        credentials: "omit",
        mode: "same-origin",
        cache: "no-store",
        redirect: "error",
        referrerPolicy: "no-referrer",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
        },
        body: JSON.stringify(body),
        signal: controller.signal,
      });
      const text = await response.text();
      if (text.length > 1_048_576)
        throw new CustomerChoicesError("unavailable", response.status);
      let parsed;
      try {
        parsed = JSON.parse(text);
      } catch {
        throw new CustomerChoicesError("unavailable", response.status);
      }
      if (!response.ok || parsed?.ok === false) {
        const rawCode = parsed?.error?.code || parsed?.code;
        const code =
          typeof rawCode === "string"
            ? KNOWN_CODES.find(
                (known) =>
                  rawCode === known ||
                  rawCode === `tc_estimate_choices_${known}`,
              )
            : null;
        throw new CustomerChoicesError(
          code || (response.status >= 500 ? "provider" : "unavailable"),
          response.status,
        );
      }
      return parsed?.ok === true ? parsed.data : parsed;
    } catch (error) {
      if (error instanceof CustomerChoicesError) throw error;
      // Do not retain raw provider messages, response bodies or network errors:
      // any of them could echo a submitted bearer credential.
      throw new CustomerChoicesError(
        controller.signal.aborted ? "timeout" : "network",
      );
    } finally {
      clearTimeout(timer);
    }
  }
  return {
    view(token) {
      return post("/customer-choices/view", { token });
    },
    apply({ token, revision, request_id, selections }) {
      return post("/customer-choices/apply", {
        token,
        revision,
        request_id,
        selections: selections.map(({ system_number, choice_id }) => ({
          system_number,
          choice_id,
        })),
      });
    },
  };
}
