import * as React from "react";
import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { CustomerStep } from "../src/components/CustomerStep.jsx";
import { EquipmentPicker } from "../src/components/EquipmentPicker.jsx";
import { SystemsStep } from "../src/components/SystemsStep.jsx";
import { PricingStep } from "../src/components/PricingStep.jsx";
import { sumEquipment } from "../src/lib/utils.js";
import { draftReducer } from "../src/model.js";

afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

describe("customer selection", () => {
  it("waits for two characters and the debounce, then selects the returned customer", async () => {
    vi.useFakeTimers();
    const customer = {
      id: "20000000001",
      name: "Fixture Residence",
      billing_address: { street: "7 Example St", city: "Example City" },
    };
    const api = { searchCustomers: vi.fn().mockResolvedValue([customer]) };
    const onSelect = vi.fn();
    render(<CustomerStep api={api} customer={null} onSelect={onSelect} />);
    const search = screen.getByRole("searchbox");

    fireEvent.change(search, { target: { value: " A " } });
    await act(() => vi.advanceTimersByTimeAsync(300));
    expect(api.searchCustomers).not.toHaveBeenCalled();

    fireEvent.change(search, { target: { value: " Fixture " } });
    await act(() => vi.advanceTimersByTimeAsync(299));
    expect(api.searchCustomers).not.toHaveBeenCalled();
    await act(() => vi.advanceTimersByTimeAsync(1));
    expect(api.searchCustomers).toHaveBeenCalledExactlyOnceWith("Fixture", 20);
    fireEvent.click(screen.getByRole("button", { name: /Fixture Residence/ }));
    expect(onSelect).toHaveBeenCalledWith(customer);
  });
});

function equipmentResponse(items, overrides = {}) {
  return {
    items,
    total: items.length,
    page: 1,
    per_page: 50,
    has_more: false,
    facets: { btus: [80000, 100000], tons: [1.5, 2], efficiencies: [85, 95] },
    ...overrides,
  };
}
function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return { promise, resolve, reject };
}
function packageItem(overrides = {}) {
  return {
    item_id: "10000000001",
    selection_category: "package",
    name: "Fixture 1.5 ton 100000 BTU Package",
    is_composite: true,
    tons: 1.5,
    btu_input: 100000,
    efficiency_percent: 95,
    purchase_cost: 3000,
    short_description: "Complete catalog package scope",
    rate: 5000,
    ...overrides,
  };
}
function detailLookup(...items) {
  return vi.fn(async (id) => {
    const item = items.find((candidate) => candidate.item_id === id);
    if (!item) throw new Error("Unexpected fixture item detail request");
    return item;
  });
}
function renderPicker(api, props = {}) {
  const onPick = vi.fn(),
    onClose = vi.fn();
  const view = render(
    <EquipmentPicker
      api={api}
      initialCategory="package"
      onPick={onPick}
      onClose={onClose}
      {...props}
    />,
  );
  return { ...view, onPick, onClose };
}

describe("unified equipment picker", () => {
  it.each([3200, null])(
    "hydrates only the selected item before picking, preserving explicit detail cost %j",
    async (purchase_cost) => {
      const item = packageItem({ purchase_cost: null });
      const other = packageItem({
        item_id: "other",
        name: "Another catalog package",
        purchase_cost: null,
      });
      const pending = deferred();
      const api = {
        searchEquipmentSelector: vi
          .fn()
          .mockResolvedValue(equipmentResponse([item, other])),
        getEquipment: vi.fn(() => pending.promise),
      };
      const { onPick } = renderPicker(api);
      const button = await screen.findByRole("button", {
        name: new RegExp(item.name),
      });
      expect(api.getEquipment).not.toHaveBeenCalled();
      fireEvent.click(button);
      expect(api.getEquipment).toHaveBeenCalledExactlyOnceWith(item.item_id);
      expect(screen.getByText("Loading item cost…")).toBeTruthy();
      expect(onPick).not.toHaveBeenCalled();
      fireEvent.click(button);
      expect(api.getEquipment).toHaveBeenCalledTimes(1);
      const detail = {
        ...item,
        purchase_cost,
        purchase_cost_source: purchase_cost === null ? null : "purchase_rate",
      };
      await act(async () => pending.resolve(detail));
      await waitFor(() =>
        expect(onPick).toHaveBeenCalledExactlyOnceWith(detail),
      );
      expect(api.getEquipment).toHaveBeenCalledTimes(1);
    },
  );

  it.each([
    { item_id: "different" },
    { selection_category: "ac" },
    { rate: 4999 },
  ])(
    "rejects detail identity mismatch %j and can retry the selected item",
    async (mismatch) => {
      const item = packageItem();
      const api = {
        searchEquipmentSelector: vi
          .fn()
          .mockResolvedValue(equipmentResponse([item])),
        getEquipment: vi
          .fn()
          .mockResolvedValueOnce({ ...item, ...mismatch })
          .mockResolvedValueOnce(item),
      };
      const { onPick } = renderPicker(api);
      fireEvent.click(
        await screen.findByRole("button", { name: new RegExp(item.name) }),
      );
      const retry = await screen.findByRole("button", {
        name: "Retry item details",
      });
      expect(screen.getByRole("alert")).toBeTruthy();
      expect(onPick).not.toHaveBeenCalled();
      fireEvent.click(retry);
      await waitFor(() => expect(onPick).toHaveBeenCalledExactlyOnceWith(item));
      expect(api.getEquipment.mock.calls).toEqual([
        [item.item_id],
        [item.item_id],
      ]);
    },
  );

  it("keeps detail request errors visible without selecting and retries one item detail request", async () => {
    const item = packageItem();
    const api = {
      searchEquipmentSelector: vi
        .fn()
        .mockResolvedValue(equipmentResponse([item])),
      getEquipment: vi
        .fn()
        .mockRejectedValueOnce(new Error("Item detail service unavailable"))
        .mockResolvedValueOnce(item),
    };
    const { onPick } = renderPicker(api);
    fireEvent.click(
      await screen.findByRole("button", { name: new RegExp(item.name) }),
    );
    await screen.findByText("Item detail service unavailable");
    expect(onPick).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "Retry item details" }));
    await waitFor(() => expect(onPick).toHaveBeenCalledExactlyOnceWith(item));
    expect(api.getEquipment).toHaveBeenCalledTimes(2);
  });

  it.each(["filter", "category", "unmount"])(
    "discards late selected-item detail after %s invalidates the selection",
    async (change) => {
      const item = packageItem({ purchase_cost: null });
      const pending = deferred();
      const api = {
        searchEquipmentSelector: vi
          .fn()
          .mockResolvedValue(equipmentResponse([item])),
        getEquipment: vi.fn(() => pending.promise),
      };
      const { onPick, unmount } = renderPicker(api);
      fireEvent.click(
        await screen.findByRole("button", { name: new RegExp(item.name) }),
      );
      expect(api.getEquipment).toHaveBeenCalledExactlyOnceWith(item.item_id);
      if (change === "filter")
        fireEvent.change(
          screen.getByRole("searchbox", { name: "Search equipment" }),
          { target: { value: "changed" } },
        );
      else if (change === "category")
        fireEvent.click(
          screen.getByRole("button", { name: "AC", exact: true }),
        );
      else unmount();
      if (change === "category")
        await act(async () =>
          pending.reject(new Error("Late item detail failure")),
        );
      else
        await act(async () =>
          pending.resolve({ ...item, purchase_cost: 3000 }),
        );
      expect(onPick).not.toHaveBeenCalled();
      expect(screen.queryByText("Late item detail failure")).toBeNull();
    },
  );

  it("coalesces rapid typing until 200ms after the final change and cancels pending work on unmount", async () => {
    vi.useFakeTimers();
    const api = {
      searchEquipmentSelector: vi.fn().mockResolvedValue(equipmentResponse([])),
    };
    const { unmount } = render(
      <EquipmentPicker
        api={api}
        initialCategory="package"
        onPick={vi.fn()}
        onClose={vi.fn()}
      />,
    );
    await act(() => vi.advanceTimersByTimeAsync(199));
    expect(api.searchEquipmentSelector).not.toHaveBeenCalled();
    const search = screen.getByRole("searchbox", { name: "Search equipment" });
    fireEvent.change(search, { target: { value: "f" } });
    await act(() => vi.advanceTimersByTimeAsync(50));
    fireEvent.change(search, { target: { value: "fu" } });
    await act(() => vi.advanceTimersByTimeAsync(50));
    fireEvent.change(search, { target: { value: "furnace" } });
    await act(() => vi.advanceTimersByTimeAsync(199));
    expect(api.searchEquipmentSelector).not.toHaveBeenCalled();
    await act(() => vi.advanceTimersByTimeAsync(1));
    expect(api.searchEquipmentSelector).toHaveBeenCalledExactlyOnceWith({
      category: "package",
      q: "furnace",
      btu: "",
      tons: "",
      efficiency: "",
      page: 1,
      limit: 50,
    });
    fireEvent.change(search, { target: { value: "cancelled after unmount" } });
    unmount();
    await act(() => vi.advanceTimersByTimeAsync(500));
    expect(api.searchEquipmentSelector).toHaveBeenCalledTimes(1);
  });

  it("cannot select an item returned under a different category", async () => {
    const wrongCategory = packageItem({
      item_id: "wrong-ac",
      name: "Unexpected AC result",
      selection_category: "ac",
      is_composite: false,
    });
    const valid = packageItem({
      item_id: "valid-package",
      name: "Valid package result",
    });
    const api = {
      searchEquipmentSelector: vi
        .fn()
        .mockResolvedValue(equipmentResponse([wrongCategory, valid])),
    };
    const { onPick } = renderPicker(api);
    const wrongButton = await screen.findByRole("button", {
      name: /Unexpected AC result/,
    });
    api.getEquipment = detailLookup(valid);
    expect(wrongButton.disabled).toBe(true);
    fireEvent.click(wrongButton);
    expect(onPick).not.toHaveBeenCalled();
    expect(api.getEquipment).not.toHaveBeenCalled();
    fireEvent.click(
      screen.getByRole("button", { name: /Valid package result/ }),
    );
    await waitFor(() => expect(onPick).toHaveBeenCalledExactlyOnceWith(valid));
  });

  it("uses one category picker, preserves parent package capacities, and selects the returned item unchanged", async () => {
    const item = packageItem({
      afue: 99,
      component_items: [{ tons: 2, btu_input: 80000, efficiency_percent: 80 }],
    });
    const api = {
      searchEquipmentSelector: vi.fn().mockResolvedValue(
        equipmentResponse([item], {
          facets: { btus: [100000], tons: [1.5], efficiencies: [95] },
        }),
      ),
    };
    const { onPick, onClose } = renderPicker(api);
    await screen.findByText(item.name);
    for (const name of ["Package", "AC", "Furnace", "Coil"])
      expect(screen.getByRole("button", { name, exact: true })).toBeTruthy();
    expect(
      screen
        .getByRole("button", { name: "Package", exact: true })
        .getAttribute("aria-pressed"),
    ).toBe("true");
    expect(api.searchEquipmentSelector).toHaveBeenCalledExactlyOnceWith({
      category: "package",
      q: "",
      btu: "",
      tons: "",
      efficiency: "",
      page: 1,
      limit: 50,
    });
    const tons = screen.getByRole("combobox", { name: "Tonnage" });
    expect(
      within(tons)
        .getAllByRole("option")
        .map((option) => option.value),
    ).toEqual(["", "1.5"]);
    expect(
      within(screen.getByRole("combobox", { name: "BTU" }))
        .getAllByRole("option")
        .map((option) => option.value),
    ).toEqual(["", "100000"]);
    expect(
      within(screen.getByRole("combobox", { name: "Efficiency (%)" }))
        .getAllByRole("option")
        .map((option) => option.value),
    ).toEqual(["", "95"]);
    api.getEquipment = detailLookup(item);
    fireEvent.click(
      screen.getByRole("button", { name: new RegExp(item.name) }),
    );
    await waitFor(() => expect(onPick).toHaveBeenCalledExactlyOnceWith(item));
    expect(api.getEquipment).toHaveBeenCalledExactlyOnceWith(item.item_id);
    fireEvent.keyDown(document, { key: "Escape" });
    expect(onClose).toHaveBeenCalledTimes(1);
    await act(async () => {});
    expect(api.searchEquipmentSelector).toHaveBeenCalledTimes(1);
  });

  it("sends combined category/search/capacity filters to the server and finds a match beyond the first 100 catalog items", async () => {
    const catalog = Array.from({ length: 151 }, (_, index) =>
      packageItem({
        item_id: String(10000000100 + index),
        name: `Fixture package ${index}`,
        tons: 2,
        btu_input: 80000,
        efficiency_percent: 85,
      }),
    );
    catalog[150] = packageItem({
      item_id: "10000000999",
      name: "Distant catalog match",
      tons: 1.5,
      btu_input: 100000,
    });
    const api = {
      searchEquipmentSelector: vi.fn(
        async ({ category, q, btu, tons, efficiency, page, limit }) => {
          const matches = catalog.filter(
            (item) =>
              item.selection_category === category &&
              (!q || item.name.toLowerCase().includes(q.toLowerCase())) &&
              (!btu || item.btu_input === Number(btu)) &&
              (!tons || item.tons === Number(tons)) &&
              (!efficiency || item.efficiency_percent === Number(efficiency)),
          );
          return equipmentResponse(
            matches.slice((page - 1) * limit, page * limit),
            {
              total: matches.length,
              page,
              has_more: page * limit < matches.length,
            },
          );
        },
      ),
    };
    const { onPick } = renderPicker(api);
    await screen.findByText("Fixture package 0");
    expect(screen.queryByText("Distant catalog match")).toBeNull();
    fireEvent.change(
      screen.getByRole("searchbox", { name: "Search equipment" }),
      { target: { value: "Distant" } },
    );
    await screen.findByText("Distant catalog match");
    fireEvent.change(screen.getByRole("combobox", { name: "BTU" }), {
      target: { value: "100000" },
    });
    await screen.findByText("Distant catalog match");
    fireEvent.change(screen.getByRole("combobox", { name: "Tonnage" }), {
      target: { value: "1.5" },
    });
    await screen.findByText("Distant catalog match");
    fireEvent.change(screen.getByRole("combobox", { name: "Efficiency (%)" }), {
      target: { value: "95" },
    });
    await screen.findByText("Distant catalog match");
    expect(api.searchEquipmentSelector).toHaveBeenLastCalledWith({
      category: "package",
      q: "Distant",
      btu: "100000",
      tons: "1.5",
      efficiency: "95",
      page: 1,
      limit: 50,
    });
    api.getEquipment = detailLookup(catalog[150]);
    fireEvent.click(
      screen.getByRole("button", { name: /Distant catalog match/ }),
    );
    await waitFor(() => expect(onPick).toHaveBeenCalledWith(catalog[150]));
  });

  it("pages server results, resets to page one after filtering, and clears capacity/efficiency filters on category change", async () => {
    const api = {
      searchEquipmentSelector: vi.fn(async ({ category, q, btu, tons, page }) =>
        equipmentResponse(
          [
            packageItem({
              item_id: String(page),
              name: `${category} page ${page}`,
              selection_category: category,
            }),
          ],
          { page, total: 120, has_more: page < 3 },
        ),
      ),
    };
    renderPicker(api);
    await screen.findByText("package page 1");
    expect(screen.getByRole("button", { name: /^Previous/ }).disabled).toBe(
      true,
    );
    fireEvent.click(screen.getByRole("button", { name: /^Next/ }));
    await screen.findByText("package page 2");
    expect(api.searchEquipmentSelector.mock.lastCall[0].page).toBe(2);
    fireEvent.click(screen.getByRole("button", { name: /^Next/ }));
    await screen.findByText("package page 3");
    expect(screen.getByRole("button", { name: /^Next/ }).disabled).toBe(true);
    expect(screen.getByText(/120 matching/)).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: /^Previous/ }));
    await screen.findByText("package page 2");
    fireEvent.change(screen.getByRole("combobox", { name: "BTU" }), {
      target: { value: "100000" },
    });
    await screen.findByText("package page 1");
    fireEvent.change(screen.getByRole("combobox", { name: "Tonnage" }), {
      target: { value: "1.5" },
    });
    await waitFor(() =>
      expect(api.searchEquipmentSelector.mock.lastCall[0].tons).toBe("1.5"),
    );
    fireEvent.change(screen.getByRole("combobox", { name: "Efficiency (%)" }), {
      target: { value: "95" },
    });
    await waitFor(() =>
      expect(api.searchEquipmentSelector.mock.lastCall[0].efficiency).toBe(
        "95",
      ),
    );
    fireEvent.click(screen.getByRole("button", { name: "AC", exact: true }));
    await screen.findByText("ac page 1");
    expect(screen.getByRole("combobox", { name: "BTU" }).value).toBe("");
    expect(screen.getByRole("combobox", { name: "Tonnage" }).value).toBe("");
    expect(screen.getByRole("combobox", { name: "Efficiency (%)" }).value).toBe(
      "",
    );
    expect(api.searchEquipmentSelector).toHaveBeenLastCalledWith({
      category: "ac",
      q: "",
      btu: "",
      tons: "",
      efficiency: "",
      page: 1,
      limit: 50,
    });
  });

  it("clears old choices immediately on filter change and ignores a late success after a newer response", async () => {
    const old = packageItem({ name: "Old visible package" });
    const late = deferred(),
      current = deferred();
    const api = {
      searchEquipmentSelector: vi
        .fn()
        .mockResolvedValueOnce(equipmentResponse([old]))
        .mockImplementationOnce(() => late.promise)
        .mockImplementationOnce(() => current.promise),
    };
    const { onPick } = renderPicker(api);
    const oldButton = await screen.findByRole("button", {
      name: /Old visible package/,
    });
    fireEvent.change(
      screen.getByRole("searchbox", { name: "Search equipment" }),
      { target: { value: "A" } },
    );
    expect(screen.queryByText(old.name)).toBeNull();
    fireEvent.click(oldButton);
    expect(onPick).not.toHaveBeenCalled();
    await waitFor(() =>
      expect(api.searchEquipmentSelector).toHaveBeenCalledTimes(2),
    );
    fireEvent.change(
      screen.getByRole("searchbox", { name: "Search equipment" }),
      { target: { value: "B" } },
    );
    await waitFor(() =>
      expect(api.searchEquipmentSelector).toHaveBeenCalledTimes(3),
    );
    const newer = packageItem({
      item_id: "10000000002",
      name: "Current B package",
    });
    await act(async () => current.resolve(equipmentResponse([newer])));
    await screen.findByText(newer.name);
    await act(async () =>
      late.resolve(
        equipmentResponse([packageItem({ name: "Late A package" })]),
      ),
    );
    expect(screen.queryByText("Late A package")).toBeNull();
    expect(screen.getByText(newer.name)).toBeTruthy();
    api.getEquipment = detailLookup(newer);
    fireEvent.click(screen.getByRole("button", { name: /Current B package/ }));
    await waitFor(() => expect(onPick).toHaveBeenCalledExactlyOnceWith(newer));
  });

  it.each(["BTU", "Tonnage", "Efficiency (%)", "category"])(
    "clears selectable results immediately when %s changes",
    async (control) => {
      const first = packageItem({ name: "Previously selectable package" });
      const pending = deferred();
      const api = {
        searchEquipmentSelector: vi
          .fn()
          .mockResolvedValueOnce(equipmentResponse([first]))
          .mockImplementationOnce(() => pending.promise),
      };
      const { onPick } = renderPicker(api);
      const oldButton = await screen.findByRole("button", {
        name: /Previously selectable package/,
      });
      if (control === "category")
        fireEvent.click(
          screen.getByRole("button", { name: "Coil", exact: true }),
        );
      else
        fireEvent.change(screen.getByRole("combobox", { name: control }), {
          target: {
            value:
              control === "BTU"
                ? "100000"
                : control === "Tonnage"
                  ? "1.5"
                  : "95",
          },
        });
      expect(screen.queryByText(first.name)).toBeNull();
      fireEvent.click(oldButton);
      expect(onPick).not.toHaveBeenCalled();
      await waitFor(() =>
        expect(api.searchEquipmentSelector).toHaveBeenCalledTimes(2),
      );
      await act(async () => pending.resolve(equipmentResponse([])));
    },
  );

  it("ignores late errors after a category change and exposes only the current request's failure", async () => {
    const late = deferred(),
      current = deferred();
    const api = {
      searchEquipmentSelector: vi
        .fn()
        .mockImplementationOnce(() => late.promise)
        .mockImplementationOnce(() => current.promise)
        .mockRejectedValueOnce(new Error("Current furnace failure")),
    };
    renderPicker(api);
    await waitFor(() =>
      expect(api.searchEquipmentSelector).toHaveBeenCalledTimes(1),
    );
    fireEvent.click(screen.getByRole("button", { name: "AC", exact: true }));
    await waitFor(() =>
      expect(api.searchEquipmentSelector).toHaveBeenCalledTimes(2),
    );
    const ac = packageItem({ selection_category: "ac", name: "Current AC" });
    await act(async () => current.resolve(equipmentResponse([ac])));
    await screen.findByText(ac.name);
    await act(async () => late.reject(new Error("Late package failure")));
    expect(screen.queryByText("Late package failure")).toBeNull();
    expect(screen.getByText(ac.name)).toBeTruthy();
    fireEvent.click(
      screen.getByRole("button", { name: "Furnace", exact: true }),
    );
    expect(screen.queryByText(ac.name)).toBeNull();
    await screen.findByText("Current furnace failure");
  });

  it("shows an honest empty state and All-only filters when the category has no capacity ratings", async () => {
    const api = {
      searchEquipmentSelector: vi.fn().mockResolvedValue(
        equipmentResponse([], {
          facets: { btus: [], tons: [], efficiencies: [] },
        }),
      ),
    };
    renderPicker(api, { initialCategory: "coil" });
    await screen.findByText(/No .*match/i);
    expect(
      screen
        .getByRole("button", { name: "Coil", exact: true })
        .getAttribute("aria-pressed"),
    ).toBe("true");
    expect(screen.getByText("No BTU ratings available")).toBeTruthy();
    expect(screen.getByText("No tonnage ratings available")).toBeTruthy();
    for (const name of ["BTU", "Tonnage", "Efficiency (%)"])
      expect(
        within(screen.getByRole("combobox", { name }))
          .getAllByRole("option")
          .map((option) => option.value),
      ).toEqual([""]);
    expect(screen.queryByRole("button", { name: /Unnamed part/ })).toBeNull();
  });

  it("does not invent efficiency options from sales item names, SEER, AFUE or component ratings", async () => {
    const item = packageItem({
      name: "Unrated package advertised as 96 percent",
      efficiency_percent: null,
      seer: 18,
      afue: 96,
      component_items: [{ efficiency_percent: 95 }],
    });
    const api = {
      searchEquipmentSelector: vi.fn().mockResolvedValue(
        equipmentResponse([item], {
          facets: { btus: [100000], tons: [1.5], efficiencies: [] },
        }),
      ),
    };
    renderPicker(api);
    await screen.findByText(item.name);
    const efficiency = screen.getByRole("combobox", { name: "Efficiency (%)" });
    expect(
      within(efficiency)
        .getAllByRole("option")
        .map((option) => option.value),
    ).toEqual([""]);
    expect(
      within(efficiency).getByRole("option", { name: "All efficiencies" }),
    ).toBeTruthy();
  });
});

function SystemsHarness({
  initialSystems,
  api,
  onChange,
  template = { template_type: "full_replacement" },
}) {
  const [systems, setSystems] = React.useState(initialSystems);
  return (
    <SystemsStep
      api={api}
      systems={systems}
      template={template}
      onChange={(next) => {
        onChange(next);
        setSystems(next);
      }}
    />
  );
}

describe("system slots and item edits", () => {
  it.each(["", "Edited package scope"])(
    "preserves description %j and project notes when the same package is selected again",
    async (description) => {
      const returned = packageItem({
        notes: "Provider notes",
        description: "Provider description",
      });
      const previous = {
        ...returned,
        description,
        notes: "Keep project-specific notes",
      };
      const permit = {
        item_id: "permit",
        name: "Municipal Permit",
        rate: 125,
        description: "Keep permit scope",
      };
      const api = {
        searchEquipmentSelector: vi
          .fn()
          .mockResolvedValue(equipmentResponse([returned])),
      };
      const onChange = vi.fn();
      render(
        <SystemsHarness
          api={api}
          onChange={onChange}
          initialSystems={[
            {
              system_number: 1,
              system_label: "Main System",
              equipment: { package: previous, permit },
            },
          ]}
        />,
      );
      fireEvent.click(screen.getByRole("button", { name: /^Change/ }));
      const dialog = await screen.findByRole("dialog", {
        name: "Select equipment",
      });
      const pick = await within(dialog).findByRole("button", {
        name: new RegExp(returned.name),
      });
      api.getEquipment = detailLookup(returned);
      fireEvent.click(pick);
      await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
      const equipment = onChange.mock.lastCall[0][0].equipment;
      expect(equipment.package.description).toBe(description);
      expect(equipment.package.notes).toBe("Keep project-specific notes");
      expect(equipment.permit).toBe(permit);
      expect(previous.description).toBe(description);
      expect(
        screen.getByRole("textbox", {
          name: "Edit Package estimate description",
        }).value,
      ).toBe(description);
    },
  );

  it("opens a selected main item's category for Change and retains system label editing", async () => {
    const original = packageItem({
      item_id: "old-furnace",
      name: "Original furnace",
      selection_category: "furnace",
      rate: 1200,
    });
    const replacement = packageItem({
      item_id: "new-furnace",
      name: "Replacement furnace",
      selection_category: "furnace",
      short_description: "Replacement wording",
      rate: 1500,
    });
    const api = {
      searchEquipmentSelector: vi
        .fn()
        .mockResolvedValue(equipmentResponse([replacement])),
    };
    const onChange = vi.fn();
    render(
      <SystemsHarness
        api={api}
        onChange={onChange}
        initialSystems={[
          {
            system_number: 1,
            system_label: "Main System",
            equipment: { furnace: original },
          },
        ]}
      />,
    );
    fireEvent.change(screen.getByRole("textbox", { name: "System 1 label" }), {
      target: { value: "Upstairs system" },
    });
    expect(onChange.mock.lastCall[0][0].system_label).toBe("Upstairs system");
    fireEvent.click(screen.getByRole("button", { name: /^Change/ }));
    await screen.findByText(replacement.name);
    expect(api.searchEquipmentSelector).toHaveBeenCalledExactlyOnceWith({
      category: "furnace",
      q: "",
      btu: "",
      tons: "",
      efficiency: "",
      page: 1,
      limit: 50,
    });
    api.getEquipment = detailLookup(replacement);
    fireEvent.click(
      screen.getByRole("button", { name: /Replacement furnace/ }),
    );
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
    expect(onChange.mock.lastCall[0][0].equipment.furnace.item_id).toBe(
      "new-furnace",
    );
    expect(onChange.mock.lastCall[0][0].system_label).toBe("Upstairs system");
  });

  it("opens one selection tool at Package and selects a coil with its catalog description", async () => {
    const coil = packageItem({
      item_id: "10000000003",
      selection_category: "coil",
      name: "Fixture Coil",
      short_description: "Catalog coil scope",
      description: "Other provider wording",
      is_composite: false,
      rate: 600,
    });
    const api = {
      searchEquipmentSelector: vi.fn(async ({ category }) =>
        equipmentResponse(category === "coil" ? [coil] : []),
      ),
    };
    const onChange = vi.fn();
    render(
      <SystemsHarness
        api={api}
        onChange={onChange}
        initialSystems={[
          { system_number: 1, system_label: "Main System", equipment: {} },
        ]}
      />,
    );
    expect(
      screen.getAllByRole("button", { name: "Select equipment", exact: true }),
    ).toHaveLength(1);
    expect(
      screen.queryByRole("button", {
        name: /^\+ Add (furnace|condenser|evaporator)/i,
      }),
    ).toBeNull();
    fireEvent.click(
      screen.getByRole("button", { name: "Select equipment", exact: true }),
    );
    await waitFor(() =>
      expect(api.searchEquipmentSelector).toHaveBeenCalledWith({
        category: "package",
        q: "",
        btu: "",
        tons: "",
        efficiency: "",
        page: 1,
        limit: 50,
      }),
    );
    fireEvent.click(screen.getByRole("button", { name: "Coil", exact: true }));
    await screen.findByText(coil.name);
    api.getEquipment = detailLookup(coil);
    fireEvent.click(screen.getByRole("button", { name: /Fixture Coil/ }));
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
    expect(
      screen.getByRole("textbox", { name: "Edit Coil estimate description" })
        .value,
    ).toBe("Catalog coil scope");
    expect(onChange.mock.lastCall[0][0].equipment.coil).toEqual({
      ...coil,
      description: "Catalog coil scope",
    });
    expect(coil.description).toBe("Other provider wording");
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("replaces separate main components with one priced package while preserving selected legacy accessories", async () => {
    const selectedPackage = packageItem();
    const permit = {
      item_id: "permit",
      name: "Municipal Permit",
      rate: 125,
      description: "Preserved permit wording",
    };
    const thermostat = {
      item_id: "thermostat",
      name: "Existing thermostat",
      rate: 100,
      description: "Preserved thermostat wording",
    };
    const initial = [
      {
        system_number: 1,
        system_label: "Main System",
        equipment: {
          furnace: { item_id: "furnace", name: "Old furnace", rate: 3200 },
          condenser: { item_id: "ac", name: "Old AC", rate: 2000 },
          coil: { item_id: "coil", name: "Old coil", rate: 600 },
          permit,
          thermostat,
        },
      },
    ];
    const api = {
      searchEquipmentSelector: vi
        .fn()
        .mockResolvedValue(equipmentResponse([selectedPackage])),
    };
    const onChange = vi.fn();
    render(
      <SystemsHarness api={api} onChange={onChange} initialSystems={initial} />,
    );
    fireEvent.click(
      screen.getByRole("button", { name: "Select equipment", exact: true }),
    );
    await screen.findByText(selectedPackage.name);
    api.getEquipment = detailLookup(selectedPackage);
    fireEvent.click(
      screen.getByRole("button", { name: new RegExp(selectedPackage.name) }),
    );
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
    const updated = onChange.mock.lastCall[0];
    expect(Object.keys(updated[0].equipment).sort()).toEqual([
      "package",
      "permit",
      "thermostat",
    ]);
    expect(updated[0].equipment.package.item_id).toBe(selectedPackage.item_id);
    expect(updated[0].equipment.permit).toBe(permit);
    expect(updated[0].equipment.thermostat).toBe(thermostat);
    expect(sumEquipment(updated)).toBe(5225);
    expect(initial[0].equipment.furnace.item_id).toBe("furnace");
    expect(
      screen.getByRole("textbox", { name: "Edit Permit estimate description" })
        .value,
    ).toBe(permit.description);
    expect(
      screen.getByRole("textbox", {
        name: "Edit Thermostat estimate description",
      }).value,
    ).toBe(thermostat.description);
    expect(screen.getByText(/package.*replac/i)).toBeTruthy();
  });

  it.each([
    ["AC", "ac", "condenser"],
    ["Furnace", "furnace", "furnace"],
    ["Coil", "coil", "coil"],
  ])(
    "selecting %s removes a package but preserves the other component slots and accessories",
    async (label, category, slot) => {
      const selected = packageItem({
        item_id: "replacement",
        name: `Replacement ${label}`,
        selection_category: category,
        is_composite: false,
        short_description: "New selected wording",
        rate: 400,
      });
      const equipment = {
        package: packageItem({ description: "Old package wording" }),
        furnace: { item_id: "furnace", name: "Existing furnace", rate: 100 },
        condenser: { item_id: "ac", name: "Existing AC", rate: 200 },
        coil: { item_id: "coil", name: "Existing coil", rate: 300 },
        permit: {
          item_id: "permit",
          name: "Permit",
          rate: 125,
          description: "Existing permit wording",
        },
      };
      const api = {
        searchEquipmentSelector: vi.fn(async ({ category: requested }) =>
          equipmentResponse(requested === category ? [selected] : []),
        ),
      };
      const onChange = vi.fn();
      render(
        <SystemsHarness
          api={api}
          onChange={onChange}
          initialSystems={[
            { system_number: 1, system_label: "Main System", equipment },
          ]}
        />,
      );
      fireEvent.click(
        screen.getByRole("button", { name: "Select equipment", exact: true }),
      );
      fireEvent.click(screen.getByRole("button", { name: label, exact: true }));
      await screen.findByText(selected.name);
      api.getEquipment = detailLookup(selected);
      fireEvent.click(
        screen.getByRole("button", { name: new RegExp(selected.name) }),
      );
      await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
      const updated = onChange.mock.lastCall[0][0].equipment;
      expect(updated).not.toHaveProperty("package");
      expect(updated[slot]).toEqual({
        ...selected,
        description: "New selected wording",
      });
      for (const key of ["furnace", "condenser", "coil", "permit"].filter(
        (key) => key !== slot,
      ))
        expect(updated[key]).toBe(equipment[key]);
      expect(equipment.package.description).toBe("Old package wording");
    },
  );

  it("edits only the targeted system, keeps blank descriptions, and exposes rate editing only for permits", () => {
    const furnace = {
      item_id: "10000000001",
      name: "Fixture Furnace",
      short_description: "Catalog wording",
      rate: 3200,
    };
    const permit = {
      item_id: "10000000004",
      name: "Municipal Permit",
      short_description: "Municipal scope",
      rate: 125,
    };
    const original = [
      {
        system_number: 1,
        system_label: "First System",
        equipment: { furnace },
      },
      {
        system_number: 2,
        system_label: "Second System",
        equipment: {
          furnace: { ...furnace, description: "Second scope" },
          permit,
        },
      },
    ];
    const onChange = vi.fn();
    render(
      <SystemsHarness api={{}} onChange={onChange} initialSystems={original} />,
    );
    const descriptions = screen.getAllByRole("textbox", {
      name: "Edit Furnace estimate description",
    });
    fireEvent.change(descriptions[1], { target: { value: "" } });
    let updated = onChange.mock.lastCall[0];
    expect(updated[0]).toBe(original[0]);
    expect(updated[1].equipment.furnace.description).toBe("");
    expect(
      screen.getAllByRole("textbox", {
        name: "Edit Furnace estimate description",
      })[1].value,
    ).toBe("");
    expect(original[1].equipment.furnace.description).toBe("Second scope");

    const price = screen.getByRole("spinbutton", { name: /^Permit price/ });
    expect(price.getAttribute("min")).toBe("0");
    expect(price.getAttribute("max")).toBe("100000");
    expect(screen.getAllByRole("spinbutton")).toHaveLength(1);
    fireEvent.change(price, { target: { value: "375.25" } });
    updated = onChange.mock.lastCall[0];
    expect(updated[1].equipment.permit.rate).toBe(375.25);
    expect(updated[1].equipment.furnace.rate).toBe(3200);
    expect(permit.rate).toBe(125);
  });

  it("shows selected accessories and reindexes only the surviving system after removal", () => {
    const systems = [
      {
        system_number: 1,
        system_label: "First System",
        equipment: {
          furnace: {
            item_id: "10000000001",
            name: "First Furnace",
            rate: 3200,
          },
        },
      },
      {
        system_number: 2,
        system_label: "Second System",
        equipment: {
          permit: {
            item_id: "10000000004",
            name: "Municipal Permit",
            rate: 125,
          },
        },
      },
    ];
    const onChange = vi.fn();
    const { container } = render(
      <SystemsHarness api={{}} onChange={onChange} initialSystems={systems} />,
    );
    expect(screen.getByText("Municipal Permit")).toBeTruthy();
    const firstSystem = container.querySelectorAll(".tc-system")[0];
    fireEvent.click(firstSystem.querySelector("header button"));
    expect(onChange.mock.lastCall[0]).toEqual([
      { ...systems[1], system_number: 1 },
    ]);
    expect(screen.getByRole("textbox", { name: "System 1 label" }).value).toBe(
      "Second System",
    );
    fireEvent.click(
      screen.getByRole("button", { name: "+ Add another system" }),
    );
    expect(onChange.mock.lastCall[0][1]).toEqual({
      system_number: 2,
      system_label: "Second Floor System",
      equipment: {},
    });
    expect(screen.getAllByText("Permit").length).toBeGreaterThan(0);
  });
});

function PricingHarness({
  onPricingChange,
  onOptionsChange,
  initialPricing = {},
  missingCostCount = 0,
}) {
  const [pricing, setPricing] = React.useState({
    subtotal: 100,
    equipment_cost: 60,
    equipment_cost_manual: false,
    total: 150,
    total_manual: true,
    deposit_percent: 35,
    ...initialPricing,
  });
  const [options, setOptions] = React.useState({
    rebates: [
      { name: "First rebate", amount: 10 },
      { name: "Second rebate", amount: 20 },
    ],
  });
  return (
    <PricingStep
      pricing={pricing}
      options={options}
      missingCostCount={missingCostCount}
      onPricingChange={(changes) => {
        onPricingChange(changes);
        setPricing(
          (current) =>
            draftReducer(
              { pricing: current },
              { type: "setPricing", pricing: changes },
            ).pricing,
        );
      }}
      onOptionsChange={(changes) => {
        onOptionsChange(changes);
        setOptions((current) => ({ ...current, ...changes }));
      }}
    />
  );
}

describe("manual pricing", () => {
  it("keeps unknown purchase cost blank, warns honestly and accepts an explicit zero manual cost without changing sales price", () => {
    const onPricingChange = vi.fn();
    render(
      <PricingHarness
        onPricingChange={onPricingChange}
        onOptionsChange={vi.fn()}
        initialPricing={{ equipment_cost: null }}
        missingCostCount={2}
      />,
    );
    const cost = screen.getByRole("spinbutton", {
      name: /^Equipment subtotal \(cost\)/,
    });
    const total = screen.getByRole("spinbutton", { name: /^Project total/ });
    expect(cost.value).toBe("");
    expect(total.value).toBe("150");
    expect(screen.getByRole("status").textContent).toMatch(
      /Purchase cost is missing for 2 selected items.*Enter the equipment cost manually\./,
    );
    expect(screen.getByRole("status").textContent).not.toContain(
      "manual override",
    );
    fireEvent.change(cost, { target: { value: "0" } });
    expect(onPricingChange).toHaveBeenLastCalledWith({ equipment_cost: 0 });
    expect(cost.value).toBe("0");
    expect(total.value).toBe("150");
    expect(screen.getByRole("status").textContent).toContain(
      "The amount above is a manual override.",
    );
  });

  it("keeps equipment cost independent and explicitly restores equipment sales pricing", () => {
    const onPricingChange = vi.fn();
    render(
      <PricingHarness
        onPricingChange={onPricingChange}
        onOptionsChange={vi.fn()}
      />,
    );
    const subtotal = screen.getByRole("spinbutton", {
      name: /^Equipment subtotal/,
    });
    const total = screen.getByRole("spinbutton", {
      name: /^Project total/,
    });
    fireEvent.change(subtotal, { target: { value: "175.25" } });
    expect(onPricingChange).toHaveBeenLastCalledWith({
      equipment_cost: 175.25,
    });
    expect(total.value).toBe("150");
    fireEvent.click(
      screen.getByRole("button", { name: "Use equipment sales price" }),
    );
    expect(onPricingChange).toHaveBeenLastCalledWith({
      total: 100,
      total_manual: false,
    });
    expect(total.value).toBe("100");
  });

  it("updates and removes only the targeted rebate without deducting it from project total", () => {
    const onPricingChange = vi.fn();
    const onOptionsChange = vi.fn();
    render(
      <PricingHarness
        onPricingChange={onPricingChange}
        onOptionsChange={onOptionsChange}
      />,
    );
    fireEvent.change(screen.getAllByPlaceholderText("Amount")[1], {
      target: { value: "30" },
    });
    expect(onOptionsChange).toHaveBeenLastCalledWith({
      rebates: [
        { name: "First rebate", amount: 10 },
        { name: "Second rebate", amount: 30 },
      ],
    });
    fireEvent.click(
      screen.getAllByRole("button", { name: "Remove rebate" })[0],
    );
    expect(onOptionsChange).toHaveBeenLastCalledWith({
      rebates: [{ name: "Second rebate", amount: 30 }],
    });
    expect(
      screen.getByRole("spinbutton", { name: /^Project total/ }).value,
    ).toBe("150");
    expect(onPricingChange).not.toHaveBeenCalled();
  });
});
