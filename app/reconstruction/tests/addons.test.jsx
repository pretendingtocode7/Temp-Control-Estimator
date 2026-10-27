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
import userEvent from "@testing-library/user-event";
import { PricingStep } from "../src/components/PricingStep.jsx";
import { createInitialState } from "../src/model.js";

afterEach(cleanup);
const maintenanceLabel = "New Residential System Maintenance Agreement ($0.00)";
const permitsLabel = "Permits & Inspections ($0.00)";
const permitParagraphs = [
  "Permit fees are not included in the project total.",
  "Temp Control will prepare and complete all required permit paperwork and submit the necessary documentation to the local township on the homeowner’s behalf.",
  "All work will be performed in accordance with local building codes and manufacturer specifications. Permit payment, pickup and scheduling of Inspections are the responsibility of the homeowner.",
];
function availableItems() {
  return {
    maintenance: {
      available: true,
      name: "New Residential System Maintenance Agreement",
      item_id: "maintenance",
      rate: 0,
      renewal_rate: 349,
      renewal_units: 1,
    },
    permits: {
      available: true,
      name: "Permits & Inspections",
      item_id: "permits",
      rate: 0,
    },
  };
}
function Harness({ api, onOptionsChange, onPricingChange }) {
  const [options, setOptions] = React.useState(createInitialState().options);
  return (
    <PricingStep
      api={api}
      systems={[]}
      options={options}
      pricing={{
        ...createInitialState().pricing,
        subtotal: 5000,
        equipment_cost: 3000,
        total: 5000,
      }}
      onPricingChange={onPricingChange}
      onOptionsChange={(changes) => {
        onOptionsChange(changes);
        setOptions((current) => ({ ...current, ...changes }));
      }}
    />
  );
}
function renderItems(api) {
  const onOptionsChange = vi.fn(),
    onPricingChange = vi.fn();
  render(
    <Harness
      api={api}
      onOptionsChange={onOptionsChange}
      onPricingChange={onPricingChange}
    />,
  );
  return { onOptionsChange, onPricingChange };
}

describe("optional project items", () => {
  it("starts unchecked, uses validated zero-dollar items and shows renewal/permit terms without charging them", async () => {
    const api = {
      getEquipmentAddons: vi.fn().mockResolvedValue(availableItems()),
    };
    const { onOptionsChange, onPricingChange } = renderItems(api);
    const maintenance = screen.getByRole("checkbox", {
      name: maintenanceLabel,
    });
    const permits = screen.getByRole("checkbox", { name: permitsLabel });
    await waitFor(() => expect(maintenance.disabled).toBe(false));
    expect(permits.disabled).toBe(false);
    expect(maintenance.checked).toBe(false);
    expect(permits.checked).toBe(false);
    expect(onOptionsChange).not.toHaveBeenCalled();
    expect(screen.queryByText(permitParagraphs[0])).toBeNull();
    fireEvent.click(maintenance);
    expect(onOptionsChange).toHaveBeenLastCalledWith({
      include_maintenance: true,
    });
    expect(screen.getByText("Renews at $349 for one unit.")).toBeTruthy();
    fireEvent.click(permits);
    expect(onOptionsChange).toHaveBeenLastCalledWith({ include_permits: true });
    for (const paragraph of permitParagraphs)
      expect(screen.getByText(paragraph)).toBeTruthy();
    expect(
      screen.getByRole("spinbutton", { name: /^Project total/ }).value,
    ).toBe("5000");
    expect(onPricingChange).not.toHaveBeenCalled();
    expect(api.getEquipmentAddons).toHaveBeenCalledTimes(1);
    fireEvent.click(permits);
    expect(onOptionsChange).toHaveBeenLastCalledWith({
      include_permits: false,
    });
    expect(screen.queryByText(permitParagraphs[0])).toBeNull();
  });

  it("disables unavailable items without silently selecting an option", async () => {
    const descriptors = availableItems();
    descriptors.maintenance.available = false;
    descriptors.permits.available = false;
    const { onOptionsChange } = renderItems({
      getEquipmentAddons: vi.fn().mockResolvedValue(descriptors),
    });
    await waitFor(() =>
      expect(screen.queryByText(/Loading optional/)).toBeNull(),
    );
    for (const name of [maintenanceLabel, permitsLabel]) {
      const checkbox = screen.getByRole("checkbox", { name });
      expect(checkbox.disabled).toBe(true);
      expect(checkbox.checked).toBe(false);
      await userEvent.setup().click(checkbox);
    }
    expect(onOptionsChange).not.toHaveBeenCalled();
  });

  it("shows an availability failure and retries before enabling optional choices", async () => {
    const api = {
      getEquipmentAddons: vi
        .fn()
        .mockRejectedValueOnce(new Error("Optional item lookup unavailable"))
        .mockResolvedValueOnce(availableItems()),
    };
    const { onOptionsChange } = renderItems(api);
    await screen.findByText("Optional item lookup unavailable");
    expect(
      screen.getByRole("checkbox", { name: maintenanceLabel }).disabled,
    ).toBe(true);
    expect(screen.getByRole("checkbox", { name: permitsLabel }).checked).toBe(
      false,
    );
    expect(onOptionsChange).not.toHaveBeenCalled();
    fireEvent.click(
      screen.getByRole("button", { name: "Retry optional items" }),
    );
    await waitFor(() =>
      expect(
        screen.getByRole("checkbox", { name: maintenanceLabel }).disabled,
      ).toBe(false),
    );
    expect(screen.queryByText("Optional item lookup unavailable")).toBeNull();
    expect(api.getEquipmentAddons).toHaveBeenCalledTimes(2);
    expect(
      screen.getByRole("checkbox", { name: maintenanceLabel }).checked,
    ).toBe(false);
  });

  it("lets a technician clear checked items during a new lookup or failure without enabling unavailable selections", async () => {
    const onOptionsChange = vi.fn();
    const onPricingChange = vi.fn();
    const api = {
      getEquipmentAddons: vi.fn().mockResolvedValue(availableItems()),
    };
    const view = render(
      <Harness
        api={api}
        onOptionsChange={onOptionsChange}
        onPricingChange={onPricingChange}
      />,
    );
    const maintenance = screen.getByRole("checkbox", {
      name: maintenanceLabel,
    });
    const permits = screen.getByRole("checkbox", { name: permitsLabel });
    await waitFor(() => expect(maintenance.disabled).toBe(false));
    await userEvent.setup().click(maintenance);
    await userEvent.setup().click(permits);
    expect(onOptionsChange).toHaveBeenCalledTimes(2);

    let rejectLookup;
    const unavailableApi = {
      getEquipmentAddons: vi.fn(
        () =>
          new Promise((resolve, reject) => {
            rejectLookup = reject;
          }),
      ),
    };
    view.rerender(
      <Harness
        api={unavailableApi}
        onOptionsChange={onOptionsChange}
        onPricingChange={onPricingChange}
      />,
    );
    await screen.findByText("Loading optional items…");
    expect(maintenance.checked).toBe(true);
    expect(permits.checked).toBe(true);
    expect(maintenance.disabled).toBe(false);
    expect(permits.disabled).toBe(false);
    expect(onOptionsChange).toHaveBeenCalledTimes(2);
    await userEvent.setup().click(maintenance);
    expect(onOptionsChange).toHaveBeenLastCalledWith({
      include_maintenance: false,
    });
    expect(maintenance.disabled).toBe(true);

    await act(async () =>
      rejectLookup(new Error("Optional lookup failed after selection")),
    );
    await screen.findByText("Optional lookup failed after selection");
    expect(permits.checked).toBe(true);
    expect(permits.disabled).toBe(false);
    expect(onOptionsChange).toHaveBeenCalledTimes(3);
    await userEvent.setup().click(permits);
    expect(onOptionsChange).toHaveBeenLastCalledWith({
      include_permits: false,
    });
    expect(permits.disabled).toBe(true);
    fireEvent.click(maintenance);
    fireEvent.click(permits);
    expect(onOptionsChange).toHaveBeenCalledTimes(4);
    expect(onPricingChange).not.toHaveBeenCalled();
  });

  it.each([{ rate: 349 }, { renewal_rate: 350 }, { renewal_units: 2 }])(
    "does not offer maintenance with unapproved amount/unit metadata %j",
    async (invalid) => {
      const descriptors = availableItems();
      Object.assign(descriptors.maintenance, invalid);
      const { onOptionsChange } = renderItems({
        getEquipmentAddons: vi.fn().mockResolvedValue(descriptors),
      });
      await waitFor(() =>
        expect(
          screen.getByRole("checkbox", { name: permitsLabel }).disabled,
        ).toBe(false),
      );
      const maintenance = screen.getByRole("checkbox", {
        name: maintenanceLabel,
      });
      expect(maintenance.disabled).toBe(true);
      await userEvent.setup().click(maintenance);
      expect(onOptionsChange).not.toHaveBeenCalled();
    },
  );
});
