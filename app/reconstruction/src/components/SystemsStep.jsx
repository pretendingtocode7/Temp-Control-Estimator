/** Reconstructed frontend, with the subsequently authorized unified catalog selector. */
import * as React from "react";
import { formatMoney } from "../lib/utils.js";
import { EquipmentPicker } from "./EquipmentPicker.jsx";

export const EQUIPMENT_SLOTS = [
  { key: "package", label: "Package", category: "package" },
  { key: "condenser", label: "AC", category: "ac" },
  { key: "furnace", label: "Furnace", category: "furnace" },
  { key: "coil", label: "Coil", category: "coil" },
  { key: "air_handler", label: "Air Handler" },
  { key: "thermostat", label: "Thermostat" },
  { key: "humidifier", label: "Humidifier" },
  { key: "uv_purifier", label: "UV Purifier" },
  { key: "water_heater", label: "Water Heater" },
  { key: "filter", label: "Filter" },
  { key: "part", label: "Part" },
  { key: "permit", label: "Permit" },
];
const categorySlots = {
  package: "package",
  ac: "condenser",
  furnace: "furnace",
  coil: "coil",
};
function slotDetails(key) {
  return (
    EQUIPMENT_SLOTS.find((slot) => slot.key === key) || {
      key,
      label: key.replace(/_/g, " "),
    }
  );
}

export function SystemsStep({ api, systems, onChange }) {
  const [picker, setPicker] = React.useState(null);
  function updateSystem(systemIndex, changes) {
    onChange(
      systems.map((system, currentIndex) =>
        currentIndex === systemIndex ? { ...system, ...changes } : system,
      ),
    );
  }
  function updateEquipment(systemIndex, slotKey, item) {
    const equipment = { ...(systems[systemIndex].equipment || {}) };
    if (item === null) delete equipment[slotKey];
    else equipment[slotKey] = item;
    updateSystem(systemIndex, { equipment });
  }
  function selectEquipment(item) {
    const slotKey = categorySlots[item.selection_category];
    const system = systems[picker.systemIdx];
    if (!slotKey || !system) return;
    const equipment = { ...(system.equipment || {}) };
    const previous = equipment[slotKey];
    const sameItem = previous?.item_id === item.item_id;
    const selected = {
      ...item,
      description:
        sameItem &&
        Object.prototype.hasOwnProperty.call(previous, "description")
          ? previous.description
          : item.short_description || item.description || "",
    };
    if (sameItem && Object.prototype.hasOwnProperty.call(previous, "notes"))
      selected.notes = previous.notes;
    if (slotKey === "package") {
      // A package contributes its parent price once, never its components again.
      delete equipment.furnace;
      delete equipment.condenser;
      delete equipment.coil;
    } else delete equipment.package;
    equipment[slotKey] = selected;
    updateSystem(picker.systemIdx, { equipment });
    setPicker(null);
  }
  function addSystem() {
    const systemNumber = systems.length + 1;
    const systemLabel =
      [
        "Second Floor System",
        "Third Floor System",
        "Basement System",
        "Additional System",
      ][systemNumber - 2] || `System ${systemNumber}`;
    onChange([
      ...systems,
      { system_number: systemNumber, system_label: systemLabel, equipment: {} },
    ]);
  }
  function removeSystem(systemIndex) {
    if (systems.length === 1) return;
    setPicker(null);
    onChange(
      systems
        .filter((system, currentIndex) => currentIndex !== systemIndex)
        .map((system, currentIndex) => ({
          ...system,
          system_number: currentIndex + 1,
        })),
    );
  }
  return (
    <section className="tc-step">
      <h2 className="tc-step-title">Equipment to install</h2>
      <p className="tc-step-help">
        Add a system for each independent HVAC zone. Most homes have one system;
        multi-story jobs have two or three.
      </p>
      {systems.map((system, systemIndex) => {
        const selectedItems = Object.entries(system.equipment || {}).filter(
          ([, item]) => item,
        );
        return (
          <div className="tc-system" key={systemIndex}>
            <header className="tc-system-head">
              <input
                type="text"
                className="tc-input tc-input-inline"
                value={system.system_label}
                onChange={(event) =>
                  updateSystem(systemIndex, {
                    system_label: event.target.value,
                  })
                }
                aria-label={`System ${systemIndex + 1} label`}
                maxLength={60}
              />
              {systems.length > 1 && (
                <button
                  type="button"
                  className="tc-btn tc-btn-ghost tc-btn-sm"
                  onClick={() => removeSystem(systemIndex)}
                >
                  Remove
                </button>
              )}
            </header>
            <button
              type="button"
              className="tc-btn tc-btn-secondary tc-btn-block"
              onClick={() =>
                setPicker({ systemIdx: systemIndex, category: "package" })
              }
            >
              Select equipment
            </button>
            <p className="tc-field-help tc-system-selection-help">
              Choose one Package or separate AC, Furnace and Coil items. A
              package replaces those components and is priced once. Existing
              accessories are kept.
            </p>
            {selectedItems.length === 0 && (
              <p className="tc-muted tc-system-empty">
                No equipment selected yet.
              </p>
            )}
            <ul className="tc-slots">
              {selectedItems.map(([slotKey, item]) => {
                const slot = slotDetails(slotKey);
                return (
                  <li className="tc-slot is-filled" key={slotKey}>
                    <div className="tc-slot-head">
                      <span className="tc-slot-label">{slot.label}</span>
                      <span className="tc-slot-price">
                        {formatMoney(item.rate || 0)}
                      </span>
                    </div>
                    <div className="tc-slot-body">
                      <div className="tc-slot-picked">
                        <div className="tc-slot-model">
                          {item.name ||
                            `${item.brand || ""} ${item.model || ""}`.trim()}
                        </div>
                        {item.sku && (
                          <div className="tc-slot-desc">SKU {item.sku}</div>
                        )}
                      </div>
                      <div className="tc-slot-actions">
                        {slot.category && (
                          <button
                            type="button"
                            className="tc-btn tc-btn-ghost tc-btn-sm"
                            onClick={() =>
                              setPicker({
                                systemIdx: systemIndex,
                                category: slot.category,
                              })
                            }
                          >
                            Change
                          </button>
                        )}
                        <button
                          type="button"
                          className="tc-btn tc-btn-ghost tc-btn-sm tc-btn-danger"
                          onClick={() =>
                            updateEquipment(systemIndex, slotKey, null)
                          }
                        >
                          Remove
                        </button>
                      </div>
                    </div>
                    <label className="tc-field tc-item-description">
                      <span className="tc-field-label">
                        Edit item description
                      </span>
                      <textarea
                        className="tc-input tc-textarea"
                        rows="3"
                        maxLength="2000"
                        aria-label={`Edit ${slot.label} estimate description`}
                        value={
                          Object.prototype.hasOwnProperty.call(
                            item,
                            "description",
                          )
                            ? item.description
                            : item.short_description || ""
                        }
                        onChange={(event) =>
                          updateEquipment(systemIndex, slotKey, {
                            ...item,
                            description: event.target.value,
                          })
                        }
                      />
                      <span className="tc-field-help">
                        This wording appears on the customer estimate. Editing
                        it does not change the Books item.
                      </span>
                    </label>
                    {slotKey === "permit" && (
                      <label className="tc-field tc-permit-price">
                        <span className="tc-field-label">Permit price</span>
                        <input
                          type="number"
                          inputMode="decimal"
                          className="tc-input"
                          min="0"
                          max="100000"
                          step="0.01"
                          value={item.rate ?? ""}
                          onChange={(event) =>
                            updateEquipment(systemIndex, slotKey, {
                              ...item,
                              rate: parseFloat(event.target.value) || 0,
                            })
                          }
                        />
                        <span className="tc-field-help">
                          Enter the permit amount for this municipality and
                          project.
                        </span>
                      </label>
                    )}
                  </li>
                );
              })}
            </ul>
          </div>
        );
      })}
      <button
        type="button"
        className="tc-btn tc-btn-ghost tc-btn-block"
        onClick={addSystem}
      >
        + Add another system
      </button>
      {picker && (
        <EquipmentPicker
          api={api}
          initialCategory={picker.category}
          onClose={() => setPicker(null)}
          onPick={selectEquipment}
        />
      )}
    </section>
  );
}
