import { afterEach, describe, expect, it, vi } from "vitest";
import { createApi } from "../src/lib/api.js";

afterEach(() => vi.unstubAllGlobals());

function response(data) {
  return new Response(JSON.stringify({ ok: true, data }), { status: 200 });
}

describe("shared equipment selector API", () => {
  it("keeps unknown cost and efficiency nullable and omits an unselected efficiency filter", async () => {
    const envelope = {
      items: [
        {
          item_id: "ac",
          selection_category: "ac",
          rate: 5000,
          purchase_cost: null,
          efficiency_percent: null,
        },
      ],
      total: 1,
      page: 1,
      per_page: 50,
      has_more: false,
      facets: { btus: [], tons: [], efficiencies: [] },
    };
    const fetch = vi.fn().mockResolvedValue(response(envelope));
    vi.stubGlobal("fetch", fetch);
    const api = createApi({
      restUrl: "https://fixture.test/wp-json/tc-estimate/v1",
      nonce: "synthetic",
    });
    await expect(
      api.searchEquipmentSelector({ category: "ac", efficiency: "" }),
    ).resolves.toEqual(envelope);
    expect(new URL(fetch.mock.calls[0][0]).searchParams.has("efficiency")).toBe(
      false,
    );
  });

  it("sends combined server filters and pagination without truncating the response envelope", async () => {
    const envelope = {
      items: [
        {
          item_id: "10000000001",
          selection_category: "package",
          efficiency_percent: 95,
          purchase_cost: 3000,
          rate: 5000,
        },
      ],
      total: 123,
      page: 2,
      per_page: 50,
      has_more: true,
      facets: { btus: [80000, 100000], tons: [1.5, 2], efficiencies: [85, 95] },
    };
    const fetch = vi.fn().mockResolvedValue(response(envelope));
    vi.stubGlobal("fetch", fetch);
    const api = createApi({
      restUrl: "https://fixture.test/wp-json/tc-estimate/v1",
      nonce: "synthetic",
    });
    await expect(
      api.searchEquipmentSelector({
        category: "package",
        q: "AC + furnace",
        btu: 100000,
        tons: 1.5,
        efficiency: 95,
        page: 2,
      }),
    ).resolves.toEqual(envelope);
    const url = new URL(fetch.mock.calls[0][0]);
    expect(url.pathname).toBe("/wp-json/tc-estimate/v1/equipment");
    expect(Object.fromEntries(url.searchParams)).toEqual({
      selector: "1",
      category: "package",
      q: "AC + furnace",
      btu: "100000",
      tons: "1.5",
      efficiency: "95",
      page: "2",
      limit: "50",
    });
    expect(fetch.mock.calls[0][1].method).toBe("GET");
  });

  it("waits for catalog warm-up but makes its own filtered request even with limit one", async () => {
    let resolveWarmup;
    const fetch = vi
      .fn()
      .mockImplementationOnce(
        () =>
          new Promise((resolve) => {
            resolveWarmup = resolve;
          }),
      )
      .mockResolvedValueOnce(
        response({
          items: [],
          total: 0,
          page: 1,
          per_page: 1,
          has_more: false,
          facets: { btus: [], tons: [], efficiencies: [] },
        }),
      );
    vi.stubGlobal("fetch", fetch);
    const api = createApi({
      restUrl: "https://fixture.test/wp-json/tc-estimate/v1",
      nonce: "synthetic",
    });
    const warmup = api.searchEquipment({ limit: 1 });
    const selector = api.searchEquipmentSelector({
      category: "coil",
      tons: 2,
      limit: 1,
    });
    expect(fetch).toHaveBeenCalledTimes(1);
    resolveWarmup(response([]));
    await warmup;
    const result = await selector;
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(new URL(fetch.mock.calls[1][0]).searchParams.get("selector")).toBe(
      "1",
    );
    expect(new URL(fetch.mock.calls[1][0]).searchParams.get("category")).toBe(
      "coil",
    );
    expect(result.items).toEqual([]);
  });
});
