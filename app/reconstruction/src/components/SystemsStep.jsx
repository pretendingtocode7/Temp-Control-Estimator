/** Reconstructed from the saved v0.4.8 deployment bundle; not recovered original source. */
import * as React from "react";
import { cx, formatMoney } from "../lib/utils.js";
import { EquipmentPicker } from "./EquipmentPicker.jsx";
export const EQUIPMENT_SLOTS = [
  {
    key: "furnace",
    label: "Furnace",
    type: "furnace",
  },
  {
    key: "condenser",
    label: "Condenser",
    type: "condenser",
  },
  {
    key: "coil",
    label: "Evaporator Coil",
    type: "evaporator_coil",
  },
  {
    key: "air_handler",
    label: "Air Handler",
    type: "air_handler",
  },
  {
    key: "thermostat",
    label: "Thermostat",
    type: "thermostat",
  },
  {
    key: "humidifier",
    label: "Humidifier",
    type: "humidifier",
  },
  {
    key: "uv_purifier",
    label: "UV Purifier",
    type: "uv_purifier",
  },
  {
    key: "water_heater",
    label: "Water Heater",
    type: "water_heater",
  },
  {
    key: "filter",
    label: "Filter",
    type: "filter",
  },
  {
    key: "part",
    label: "Part",
    type: "part",
  },
  {
    key: "permit",
    label: "Permit",
    type: "permit",
  },
];
export function defaultSlotsForTemplate(template) {
  const templateType = template?.template_type || "";
  return templateType === "ac_only"
    ? ["condenser", "coil", "thermostat"]
    : templateType === "furnace_only"
      ? ["furnace", "thermostat"]
      : templateType === "maintenance" || templateType === "service_repair"
        ? ["other"]
        : ["furnace", "condenser", "coil", "thermostat"];
}
export function SystemsStep({ api, systems, template, onChange }) {
  const [picker, setPicker] = React.useState(null),
    defaultSlots = defaultSlotsForTemplate(template),
    updateSystem = (systemIndex, changes) => {
      const updatedSystems = systems.map((system, currentIndex) =>
        currentIndex === systemIndex
          ? {
              ...system,
              ...changes,
            }
          : system,
      );
      onChange(updatedSystems);
    },
    updateEquipment = (systemIndex, slotKey, item) => {
      const equipment = {
        ...(systems[systemIndex].equipment || {}),
      };
      if (item === null) delete equipment[slotKey];
      else equipment[slotKey] = item;
      updateSystem(systemIndex, {
        equipment,
      });
    },
    addSystem = () => {
      const systemNumber = systems.length + 1,
        systemLabel =
          [
            "Second Floor System",
            "Third Floor System",
            "Basement System",
            "Additional System",
          ][systemNumber - 2] || `System ${systemNumber}`;
      onChange([
        ...systems,
        {
          system_number: systemNumber,
          system_label: systemLabel,
          equipment: {},
        },
      ]);
    },
    removeSystem = (systemIndex) => {
      if (systems.length === 1) return;
      const remainingSystems = systems
        .filter((system, currentIndex) => currentIndex !== systemIndex)
        .map((system, systemIndex) => ({
          ...system,
          system_number: systemIndex + 1,
        }));
      onChange(remainingSystems);
    };
  return (
    <section className="tc-step">
      <h2 className="tc-step-title">{"Equipment to install"}</h2>
      <p className="tc-step-help">
        {
          "Add a system for each independent HVAC zone. Most homes have one system; multi-story jobs have two or three."
        }
      </p>
      {systems.map((system, systemIndex) => {
        // Keep selected accessories visible even when they are not template defaults.
        const visibleSlots = EQUIPMENT_SLOTS.filter(
            (slot) =>
              defaultSlots.includes(slot.key) || system.equipment?.[slot.key],
          ),
          accessorySlots = EQUIPMENT_SLOTS.filter(
            (slot) =>
              !visibleSlots.find((visibleSlot) => visibleSlot.key === slot.key),
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
                  {"Remove"}
                </button>
              )}
            </header>
            <ul className="tc-slots">
              {visibleSlots.map((slot) => {
                const item = system.equipment?.[slot.key];
                return (
                  <li
                    className={cx("tc-slot", item && "is-filled")}
                    key={slot.key}
                  >
                    <div className="tc-slot-head">
                      <span className="tc-slot-label">{slot.label}</span>
                      {item && (
                        <span className="tc-slot-price">
                          {formatMoney(item.rate || 0)}
                        </span>
                      )}
                    </div>
                    {item ? (
                      <React.Fragment>
                        <div className="tc-slot-body">
                          <div className="tc-slot-picked">
                            <div className="tc-slot-model">
                              {item.name ||
                                `${item.brand || ""} ${item.model || ""}`.trim()}
                            </div>
                            {item.sku && (
                              <div className="tc-slot-desc">
                                {"SKU "}
                                {item.sku}
                              </div>
                            )}
                          </div>
                          <div className="tc-slot-actions">
                            <button
                              type="button"
                              className="tc-btn tc-btn-ghost tc-btn-sm"
                              onClick={() =>
                                setPicker({
                                  systemIdx: systemIndex,
                                  slotKey: slot.key,
                                  type: slot.type,
                                  label: slot.label,
                                })
                              }
                            >
                              {"Change"}
                            </button>
                            <button
                              type="button"
                              className="tc-btn tc-btn-ghost tc-btn-sm tc-btn-danger"
                              onClick={() =>
                                updateEquipment(systemIndex, slot.key, null)
                              }
                            >
                              {"Remove"}
                            </button>
                          </div>
                        </div>
                        <label className="tc-field tc-item-description">
                          <span className="tc-field-label">
                            {"Edit item description"}
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
                              updateEquipment(systemIndex, slot.key, {
                                ...item,
                                description: event.target.value,
                              })
                            }
                          />
                          <span className="tc-field-help">
                            {
                              "This wording appears on the customer estimate. Editing it does not change the Books item."
                            }
                          </span>
                        </label>
                        {slot.key === "permit" && (
                          <label className="tc-field tc-permit-price">
                            <span className="tc-field-label">
                              {"Permit price"}
                            </span>
                            <input
                              type="number"
                              inputMode="decimal"
                              className="tc-input"
                              min="0"
                              max="100000"
                              step="0.01"
                              value={item.rate ?? ""}
                              onChange={(event) =>
                                updateEquipment(systemIndex, slot.key, {
                                  ...item,
                                  rate: parseFloat(event.target.value) || 0,
                                })
                              }
                            />
                            <span className="tc-field-help">
                              {
                                "Enter the permit amount for this municipality and project."
                              }
                            </span>
                          </label>
                        )}
                      </React.Fragment>
                    ) : (
                      <button
                        type="button"
                        className="tc-slot-empty"
                        onClick={() =>
                          setPicker({
                            systemIdx: systemIndex,
                            slotKey: slot.key,
                            type: slot.type,
                            label: slot.label,
                          })
                        }
                      >
                        {"+ Add "}
                        {slot.label.toLowerCase()}
                      </button>
                    )}
                  </li>
                );
              })}
            </ul>
            {accessorySlots.length > 0 && (
              <details className="tc-accessory">
                <summary>{"Add accessory"}</summary>
                <div className="tc-accessory-list">
                  {accessorySlots.map((slot) => (
                    <button
                      type="button"
                      className="tc-btn tc-btn-ghost tc-btn-sm"
                      onClick={() =>
                        setPicker({
                          systemIdx: systemIndex,
                          slotKey: slot.key,
                          type: slot.type,
                          label: slot.label,
                        })
                      }
                      key={slot.key}
                    >
                      {slot.label}
                    </button>
                  ))}
                </div>
              </details>
            )}
          </div>
        );
      })}
      <button
        type="button"
        className="tc-btn tc-btn-ghost tc-btn-block"
        onClick={addSystem}
      >
        {"+ Add another system"}
      </button>
      {picker && (
        <EquipmentPicker
          api={api}
          type={picker.type}
          label={picker.label}
          onClose={() => setPicker(null)}
          onPick={(item) => {
            updateEquipment(picker.systemIdx, picker.slotKey, {
              ...item,
              description: item.short_description || item.description || "",
            });
            setPicker(null);
          }}
        />
      )}
    </section>
  );
}
