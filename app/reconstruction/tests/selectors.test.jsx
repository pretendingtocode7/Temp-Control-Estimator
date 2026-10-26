import * as React from "react";
import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
} from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { CustomerStep } from "../src/components/CustomerStep.jsx";
import { EquipmentPicker } from "../src/components/EquipmentPicker.jsx";
import { SystemsStep } from "../src/components/SystemsStep.jsx";
import { PricingStep } from "../src/components/PricingStep.jsx";

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

describe("equipment picker", () => {
  it("requests the selected type once and filters the returned items locally", async () => {
    const first = {
      item_id: "10000000001",
      name: "Fixture Furnace One",
      brand: "Fixture Brand",
      js_part_number: "JS-ONE",
      short_description: "First scope",
      rate: 3200,
    };
    const second = {
      item_id: "10000000002",
      name: "Fixture Furnace Two",
      mfg_part_number: "MFG-TWO",
      short_description: "Second scope",
      rate: 4100,
    };
    const api = { searchEquipment: vi.fn().mockResolvedValue([first, second]) };
    const onPick = vi.fn();
    const onClose = vi.fn();
    render(
      <EquipmentPicker
        api={api}
        type="furnace"
        label="Furnace"
        onPick={onPick}
        onClose={onClose}
      />,
    );
    await screen.findByText(first.name);
    expect(api.searchEquipment).toHaveBeenCalledExactlyOnceWith({
      type: "furnace",
      limit: 100,
    });

    fireEvent.change(screen.getByRole("searchbox"), {
      target: { value: "mfg-two" },
    });
    expect(screen.queryByText(first.name)).toBeNull();
    fireEvent.click(
      screen.getByRole("button", { name: /Fixture Furnace Two/ }),
    );
    expect(onPick).toHaveBeenCalledWith(second);
    expect(api.searchEquipment).toHaveBeenCalledTimes(1);

    fireEvent.change(screen.getByRole("searchbox"), {
      target: { value: "js-one" },
    });
    expect(screen.getByText(first.name)).toBeTruthy();
    expect(screen.queryByText(second.name)).toBeNull();
    fireEvent.change(screen.getByRole("searchbox"), {
      target: { value: "no matching package" },
    });
    expect(
      screen.getByText(
        "No active Package-tagged items match. Check the Package tag in Zoho Books.",
      ),
    ).toBeTruthy();
    fireEvent.keyDown(document, { key: "Escape" });
    expect(onClose).toHaveBeenCalledTimes(1);
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
  it("uses the coil API type and initializes its editable description", async () => {
    const coil = {
      item_id: "10000000003",
      name: "Fixture Coil",
      short_description: "Catalog coil scope",
      description: "Other provider wording",
      rate: 600,
    };
    const api = { searchEquipment: vi.fn().mockResolvedValue([coil]) };
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
    fireEvent.click(
      screen.getByRole("button", { name: "+ Add evaporator coil" }),
    );
    await screen.findByText(coil.name);
    expect(api.searchEquipment).toHaveBeenCalledWith({
      type: "evaporator_coil",
      limit: 100,
    });
    fireEvent.click(screen.getByRole("button", { name: /Fixture Coil/ }));
    expect(
      screen.getByRole("textbox", {
        name: "Edit Evaporator Coil estimate description",
      }).value,
    ).toBe("Catalog coil scope");
    expect(onChange.mock.lastCall[0][0].equipment.coil).toEqual({
      ...coil,
      description: "Catalog coil scope",
    });
    expect(coil.description).toBe("Other provider wording");
  });

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

function PricingHarness({ onPricingChange, onOptionsChange }) {
  const [pricing, setPricing] = React.useState({
    subtotal: 100,
    total: 150,
    deposit_percent: 35,
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
      onPricingChange={(changes) => {
        onPricingChange(changes);
        setPricing((current) => ({ ...current, ...changes }));
      }}
      onOptionsChange={(changes) => {
        onOptionsChange(changes);
        setOptions((current) => ({ ...current, ...changes }));
      }}
    />
  );
}

describe("manual pricing", () => {
  it("keeps project total independent until Use subtotal is pressed", () => {
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
      name: /Total project cost/,
    });
    fireEvent.change(subtotal, { target: { value: "175.25" } });
    expect(onPricingChange).toHaveBeenLastCalledWith({ subtotal: 175.25 });
    expect(total.value).toBe("150");
    fireEvent.click(screen.getByRole("button", { name: "Use subtotal" }));
    expect(onPricingChange).toHaveBeenLastCalledWith({ total: 175.25 });
    expect(total.value).toBe("175.25");
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
      screen.getByRole("spinbutton", { name: /Total project cost/ }).value,
    ).toBe("150");
    expect(onPricingChange).not.toHaveBeenCalled();
  });
});
