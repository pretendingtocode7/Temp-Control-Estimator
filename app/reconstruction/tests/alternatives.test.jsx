import * as React from "react";
import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { SystemsStep } from "../src/components/SystemsStep.jsx";
import { createInitialState, draftReducer } from "../src/model.js";
import {
  selectedChoice,
  selectedEquipment,
  sumEquipment,
  sumSelectedSales,
} from "../src/lib/utils.js";

afterEach(cleanup);
const uuid = (index) =>
  `00000000-0000-4000-8000-${String(index).padStart(12, "0")}`;
function item(id, category = "package", overrides = {}) {
  return {
    item_id: id,
    selection_category: category,
    category_2: "Gold",
    name: `Fixture ${id}`,
    rate: 5000,
    purchase_cost: 3000,
    short_description: "Catalog scope",
    ...overrides,
  };
}
function choice(index, label, equipment) {
  const sales_price = Object.values(equipment).reduce(
    (sum, entry) => sum + entry.rate,
    0,
  );
  return {
    choice_id: uuid(index),
    label,
    equipment,
    sales_price,
    sales_price_manual: false,
  };
}
function physical(number, choices, selectedIndex = 0) {
  return {
    system_id: uuid(number + 100),
    system_number: number,
    system_label: `System ${number}`,
    choices,
    selected_choice_id: choices[selectedIndex].choice_id,
  };
}
function response(items) {
  return {
    items,
    total: items.length,
    page: 1,
    per_page: 50,
    has_more: false,
    facets: { category2: ["Gold"], btus: [], tons: [], efficiencies: [] },
  };
}
function catalogApi(items) {
  return {
    searchEquipmentSelector: vi.fn(async ({ category }) =>
      response(items.filter((entry) => entry.selection_category === category)),
    ),
    getEquipment: vi.fn(async (id) =>
      items.find((entry) => entry.item_id === id),
    ),
  };
}
function Harness({ initialSystems, api = {}, onState }) {
  const [draft, dispatch] = React.useReducer(
    draftReducer,
    initialSystems,
    (systems) =>
      draftReducer(
        { ...createInitialState(), step: "systems" },
        { type: "setSystems", systems },
      ),
  );
  React.useEffect(() => onState(draft.systems), [draft.systems, onState]);
  return (
    <SystemsStep
      api={api}
      systems={draft.systems}
      onChange={(systems) => dispatch({ type: "setSystems", systems })}
    />
  );
}
const latest = (spy) => spy.mock.lastCall[0];

describe("alternatives inside each physical system", () => {
  it("converts legacy equipment using its actual Category2, clones the selected option and keeps editing separate from selection", () => {
    const selectedPackage = item("original", "package", {
      description: "Original proposal wording",
      notes: "Keep project notes",
    });
    const permit = item("permit", "permit", {
      rate: 125,
      purchase_cost: 0,
      description: "Municipal scope",
    });
    const original = {
      system_number: 1,
      system_label: "Main System",
      equipment: { package: selectedPackage, permit },
    };
    const onState = vi.fn();
    render(<Harness initialSystems={[original]} onState={onState} />);
    fireEvent.click(
      screen.getByRole("button", { name: "Add alternative", exact: true }),
    );
    let system = latest(onState)[0];
    expect(system).not.toHaveProperty("equipment");
    expect(system.system_id).toMatch(/^[0-9a-f-]{36}$/i);
    expect(system.choices.map((entry) => entry.label)).toEqual([
      "Gold",
      "Option 2",
    ]);
    expect(new Set(system.choices.map((entry) => entry.choice_id)).size).toBe(
      2,
    );
    expect(system.selected_choice_id).toBe(system.choices[0].choice_id);
    expect(
      screen.getByRole("radio", { name: "Select Gold for Main System" })
        .checked,
    ).toBe(true);
    expect(
      screen.getByRole("radio", { name: "Select Option 2 for Main System" })
        .checked,
    ).toBe(false);
    expect(sumEquipment(latest(onState))).toBe(5125);
    expect(system.choices[1].equipment).toEqual(system.choices[0].equipment);
    expect(
      screen
        .getByRole("textbox", { name: "System 1 alternative 1 label" })
        .getAttribute("maxLength"),
    ).toBe("60");
    fireEvent.change(
      screen.getByRole("textbox", {
        name: "Edit Package estimate description",
      }),
      { target: { value: "Second alternative scope" } },
    );
    system = latest(onState)[0];
    expect(system.choices[1].equipment.package.description).toBe(
      "Second alternative scope",
    );
    expect(system.choices[0].equipment.package.description).toBe(
      "Original proposal wording",
    );
    expect(system.choices[1].equipment.package.notes).toBe(
      "Keep project notes",
    );
    expect(system.choices[1].equipment.permit.description).toBe(
      "Municipal scope",
    );
    fireEvent.change(
      screen.getByRole("spinbutton", {
        name: "System 1 alternative 2 sales price",
      }),
      { target: { value: "6500" } },
    );
    expect(latest(onState)[0].choices[1]).toMatchObject({
      sales_price: 6500,
      sales_price_manual: true,
    });
    expect(sumSelectedSales(latest(onState))).toBe(5125);
    fireEvent.click(
      screen.getByRole("button", {
        name: "Edit Gold equipment for Main System",
      }),
    );
    expect(
      screen.getByRole("textbox", { name: "Edit Package estimate description" })
        .value,
    ).toBe("Original proposal wording");
    fireEvent.click(
      screen.getByRole("radio", { name: "Select Option 2 for Main System" }),
    );
    expect(sumSelectedSales(latest(onState))).toBe(6500);
    expect(selectedEquipment(latest(onState)[0]).package.description).toBe(
      "Second alternative scope",
    );
    expect(
      screen.getByRole("textbox", { name: "Edit Package estimate description" })
        .value,
    ).toBe("Original proposal wording");
    expect(original.equipment.package.description).toBe(
      "Original proposal wording",
    );
  });

  it("uses Current selection for an uncategorized legacy selection", () => {
    const onState = vi.fn();
    render(
      <Harness
        initialSystems={[
          {
            system_number: 1,
            system_label: "Main System",
            equipment: {
              furnace: item("legacy-furnace", "furnace", { category_2: null }),
            },
          },
        ]}
        onState={onState}
      />,
    );
    fireEvent.click(
      screen.getByRole("button", { name: "Add alternative", exact: true }),
    );
    expect(latest(onState)[0].choices[0].label).toBe("Current selection");
  });

  it("keeps selected alternatives independent across physical systems", () => {
    const first = physical(
      1,
      [
        choice(1, "Silver", {
          package: item("one-silver", "package", { rate: 4000 }),
        }),
        choice(2, "Gold", {
          package: item("one-gold", "package", { rate: 6000 }),
        }),
      ],
      1,
    );
    const second = physical(2, [
      choice(3, "Silver", {
        package: item("two-silver", "package", { rate: 3000 }),
      }),
      choice(4, "Gold", {
        package: item("two-gold", "package", { rate: 5000 }),
      }),
    ]);
    const onState = vi.fn();
    render(<Harness initialSystems={[first, second]} onState={onState} />);
    expect(
      screen.getByRole("radio", { name: "Select Gold for System 1" }).checked,
    ).toBe(true);
    expect(
      screen.getByRole("radio", { name: "Select Silver for System 2" }).checked,
    ).toBe(true);
    expect(sumSelectedSales(latest(onState))).toBe(9000);
    fireEvent.click(
      screen.getByRole("radio", { name: "Select Silver for System 1" }),
    );
    expect(latest(onState)[1].selected_choice_id).toBe(
      second.selected_choice_id,
    );
    expect(sumSelectedSales(latest(onState))).toBe(7000);
  });

  it("clears conflicting equipment only inside the edited alternative and preserves its notes and accessories", async () => {
    const selected = choice(1, "Gold", {
      package: item("selected-package", "package", {
        description: "Chosen proposal scope",
      }),
    });
    const permit = item("permit", "permit", {
      rate: 125,
      purchase_cost: 0,
      description: "Keep permit wording",
    });
    const other = choice(2, "Silver", {
      furnace: item("old-furnace", "furnace"),
      condenser: item("old-ac", "ac"),
      coil: item("old-coil", "coil"),
      permit,
    });
    const replacement = item("replacement-package");
    const coil = item("replacement-coil", "coil", {
      rate: 600,
      purchase_cost: 300,
    });
    const onState = vi.fn();
    render(
      <Harness
        initialSystems={[physical(1, [selected, other])]}
        api={catalogApi([replacement, coil])}
        onState={onState}
      />,
    );
    fireEvent.click(
      screen.getByRole("button", {
        name: "Edit Silver equipment for System 1",
      }),
    );
    fireEvent.click(
      screen.getByRole("button", { name: "Select equipment", exact: true }),
    );
    fireEvent.click(
      await screen.findByRole("button", {
        name: /Fixture replacement-package/,
      }),
    );
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
    let system = latest(onState)[0];
    expect(Object.keys(system.choices[1].equipment).sort()).toEqual([
      "package",
      "permit",
    ]);
    expect(system.choices[1].equipment.permit.description).toBe(
      "Keep permit wording",
    );
    expect(system.choices[0].equipment).toEqual(selected.equipment);
    expect(selectedEquipment(system)).toEqual(selected.equipment);
    fireEvent.click(
      screen.getByRole("button", { name: "Select equipment", exact: true }),
    );
    fireEvent.click(screen.getByRole("button", { name: "Coil", exact: true }));
    fireEvent.click(
      await screen.findByRole("button", { name: /Fixture replacement-coil/ }),
    );
    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
    system = latest(onState)[0];
    expect(Object.keys(system.choices[1].equipment).sort()).toEqual([
      "coil",
      "permit",
    ]);
    expect(system.choices[0].equipment.package.item_id).toBe(
      "selected-package",
    );
    expect(system.selected_choice_id).toBe(selected.choice_id);
  });

  it("limits each system to six alternatives and preserves surviving IDs after removing a selected alternative", () => {
    const onState = vi.fn();
    render(
      <Harness
        initialSystems={[
          {
            system_number: 1,
            system_label: "Main System",
            equipment: { package: item("original") },
          },
        ]}
        onState={onState}
      />,
    );
    for (let count = 0; count < 5; count += 1)
      fireEvent.click(
        screen.getByRole("button", { name: "Add alternative", exact: true }),
      );
    const add = screen.getByRole("button", {
      name: "Add alternative",
      exact: true,
    });
    let system = latest(onState)[0];
    expect(system.choices).toHaveLength(6);
    expect(new Set(system.choices.map((entry) => entry.choice_id)).size).toBe(
      6,
    );
    expect(
      system.choices.every((entry) => /^[0-9a-f-]{36}$/i.test(entry.choice_id)),
    ).toBe(true);
    expect(add.disabled).toBe(true);
    const systemId = system.system_id;
    const ids = system.choices.map((entry) => entry.choice_id);
    const removedId = system.choices[1].choice_id;
    fireEvent.click(
      screen.getByRole("radio", { name: "Select Option 2 for Main System" }),
    );
    fireEvent.click(
      screen.getByRole("button", {
        name: "Remove Option 2 alternative from Main System",
      }),
    );
    system = latest(onState)[0];
    expect(system.system_id).toBe(systemId);
    expect(system.choices.map((entry) => entry.choice_id)).toEqual(
      ids.filter((id) => id !== removedId),
    );
    expect(
      system.choices.some(
        (entry) => entry.choice_id === system.selected_choice_id,
      ),
    ).toBe(true);
    expect(system.selected_choice_id).not.toBe(removedId);
    fireEvent.click(
      screen.getByRole("button", { name: "Add alternative", exact: true }),
    );
    system = latest(onState)[0];
    expect(system.choices).toHaveLength(6);
    expect(system.choices.map((entry) => entry.choice_id)).not.toContain(
      removedId,
    );
    expect(new Set(system.choices.map((entry) => entry.choice_id)).size).toBe(
      6,
    );
  });

  it("discards a pending item-detail selection when editing switches to another alternative", async () => {
    const first = choice(1, "Gold", {
      package: item("first", "package", { description: "First scope" }),
    });
    const second = choice(2, "Silver", {
      package: item("second", "package", { description: "Second scope" }),
    });
    const replacement = item("late-replacement", "package", {
      purchase_cost: null,
    });
    let resolveDetail;
    const api = catalogApi([replacement]);
    api.getEquipment = vi.fn(
      () =>
        new Promise((resolve) => {
          resolveDetail = resolve;
        }),
    );
    const onState = vi.fn();
    render(
      <Harness
        initialSystems={[physical(1, [first, second])]}
        api={api}
        onState={onState}
      />,
    );
    fireEvent.click(
      screen.getByRole("button", { name: "Select equipment", exact: true }),
    );
    fireEvent.click(
      await screen.findByRole("button", { name: /Fixture late-replacement/ }),
    );
    expect(api.getEquipment).toHaveBeenCalledExactlyOnceWith(
      replacement.item_id,
    );
    fireEvent.click(
      screen.getByRole("button", {
        name: "Edit Silver equipment for System 1",
      }),
    );
    expect(screen.queryByRole("dialog")).toBeNull();
    await act(async () =>
      resolveDetail({ ...replacement, purchase_cost: 3200 }),
    );
    const system = latest(onState)[0];
    expect(system.choices[0].equipment.package.item_id).toBe("first");
    expect(system.choices[1].equipment.package.item_id).toBe("second");
    expect(system.selected_choice_id).toBe(first.choice_id);
    expect(
      screen.getByRole("textbox", { name: "Edit Package estimate description" })
        .value,
    ).toBe("Second scope");
  });
});
