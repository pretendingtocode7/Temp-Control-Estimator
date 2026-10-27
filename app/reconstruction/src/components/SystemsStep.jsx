/** Reconstructed frontend, with the subsequently authorized unified catalog selector. */
import * as React from "react";
import {
  formatMoney,
  selectedChoice,
  selectedEquipment,
  sumEquipment,
  uuid,
} from "../lib/utils.js";
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
  const [editingChoices, setEditingChoices] = React.useState({});
  const keyFor = (system) =>
    system.system_id || `system-${system.system_number}`;
  const editingChoice = (system) =>
    Array.isArray(system.choices)
      ? system.choices.find(
          (choice) => choice.choice_id === editingChoices[keyFor(system)],
        ) ||
        selectedChoice(system) ||
        system.choices[0]
      : null;
  function updateSystem(systemIndex, changes) {
    onChange(
      systems.map((system, currentIndex) =>
        currentIndex === systemIndex ? { ...system, ...changes } : system,
      ),
    );
  }
  function updateChoice(systemIndex, choiceId, changes) {
    const system = systems[systemIndex];
    updateSystem(systemIndex, {
      choices: system.choices.map((choice) => {
        if (choice.choice_id !== choiceId) return choice;
        const next = { ...choice, ...changes };
        if (
          Object.prototype.hasOwnProperty.call(changes, "equipment") &&
          !next.sales_price_manual
        )
          next.sales_price = sumEquipment([{ equipment: next.equipment }]);
        return next;
      }),
    });
  }
  function updateEquipment(
    systemIndex,
    slotKey,
    item,
    choiceId = editingChoice(systems[systemIndex])?.choice_id,
  ) {
    const system = systems[systemIndex];
    const choice = system.choices?.find(
      (candidate) => candidate.choice_id === choiceId,
    );
    if (Array.isArray(system.choices) && !choice) return;
    const equipment = {
      ...(choice ? choice.equipment : system.equipment || {}),
    };
    if (item === null) delete equipment[slotKey];
    else equipment[slotKey] = item;
    if (choice) updateChoice(systemIndex, choiceId, { equipment });
    else updateSystem(systemIndex, { equipment });
  }
  function openPicker(systemIndex, category = "package") {
    const system = systems[systemIndex];
    setPicker({
      systemKey: keyFor(system),
      choiceId: editingChoice(system)?.choice_id || "legacy",
      category,
    });
  }
  function cloneEquipment(equipment) {
    return Object.fromEntries(
      Object.entries(equipment || {}).map(([key, item]) => [
        key,
        item ? { ...item } : item,
      ]),
    );
  }
  function addAlternative(systemIndex) {
    const system = systems[systemIndex];
    if (system.choices?.length >= 6) return;
    setPicker(null);
    const equipment = selectedEquipment(system);
    const base = Array.isArray(system.choices)
      ? system.choices
      : [
          {
            choice_id: uuid(),
            label:
              typeof equipment.package?.category_2 === "string" &&
              equipment.package.category_2.trim()
                ? equipment.package.category_2.slice(0, 60)
                : "Current selection",
            equipment: cloneEquipment(equipment),
            sales_price: sumEquipment([{ equipment }]),
            sales_price_manual: false,
          },
        ];
    const alternative = {
      choice_id: uuid(),
      label: `Option ${base.length + 1}`,
      equipment: cloneEquipment(equipment),
      sales_price: sumEquipment([{ equipment }]),
      sales_price_manual: false,
    };
    const { equipment: legacyEquipment, ...rest } = system;
    const next = {
      ...rest,
      system_id: system.system_id || uuid(),
      choices: [...base, alternative],
      selected_choice_id: Array.isArray(system.choices)
        ? system.selected_choice_id
        : base[0].choice_id,
    };
    setEditingChoices((current) => ({
      ...current,
      [keyFor(next)]: alternative.choice_id,
    }));
    onChange(
      systems.map((candidate, index) =>
        index === systemIndex ? next : candidate,
      ),
    );
  }
  function selectAlternative(systemIndex, choiceId) {
    const system = systems[systemIndex];
    setPicker(null);
    setEditingChoices((current) => ({
      ...current,
      [keyFor(system)]: editingChoice(system)?.choice_id,
    }));
    updateSystem(systemIndex, { selected_choice_id: choiceId });
  }
  function removeAlternative(systemIndex, choiceId) {
    const system = systems[systemIndex];
    if (system.choices.length <= 1) return;
    setPicker(null);
    const choices = system.choices.filter(
      (choice) => choice.choice_id !== choiceId,
    );
    const selectedId = choices.some(
      (choice) => choice.choice_id === system.selected_choice_id,
    )
      ? system.selected_choice_id
      : choices[0].choice_id;
    const editingId = editingChoice(system)?.choice_id;
    setEditingChoices((current) => ({
      ...current,
      [keyFor(system)]: choices.some((choice) => choice.choice_id === editingId)
        ? editingId
        : selectedId,
    }));
    updateSystem(systemIndex, { choices, selected_choice_id: selectedId });
  }
  function selectEquipment(item) {
    const slotKey = categorySlots[item.selection_category];
    const systemIndex = systems.findIndex(
      (candidate) => keyFor(candidate) === picker.systemKey,
    );
    const system = systems[systemIndex];
    if (!slotKey || !system) return;
    const choice = system.choices?.find(
      (candidate) => candidate.choice_id === picker.choiceId,
    );
    if (Array.isArray(system.choices) && !choice) return;
    const equipment = {
      ...(choice ? choice.equipment : system.equipment || {}),
    };
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
      delete equipment.furnace;
      delete equipment.condenser;
      delete equipment.coil;
    } else delete equipment.package;
    equipment[slotKey] = selected;
    if (choice) updateChoice(systemIndex, choice.choice_id, { equipment });
    else updateSystem(systemIndex, { equipment });
    setPicker(null);
  }
  function addSystem() {
    const systemNumber = systems.length + 1;
    onChange([
      ...systems,
      {
        system_id: uuid(),
        system_number: systemNumber,
        system_label: `System ${systemNumber}`,
        equipment: {},
      },
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
        const editing = editingChoice(system);
        const selectedItems = Object.entries(
          editing ? editing.equipment || {} : selectedEquipment(system),
        ).filter(([, item]) => item);
        return (
          <div className="tc-system" key={keyFor(system)}>
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
            {Array.isArray(system.choices) && (
              <div
                className="tc-alternatives"
                role="group"
                aria-label={`${system.system_label} alternatives`}
              >
                <p className="tc-field-help">
                  Choose one alternative for this physical system. Editing
                  another alternative does not select it.
                </p>
                {system.choices.map((choice, choiceIndex) => (
                  <div
                    className={`tc-alternative ${choice.choice_id === system.selected_choice_id ? "is-selected" : ""}`}
                    key={choice.choice_id}
                  >
                    <label className="tc-check">
                      <input
                        type="radio"
                        name={`selected-${keyFor(system)}`}
                        aria-label={`Select ${choice.label} for ${system.system_label}`}
                        checked={choice.choice_id === system.selected_choice_id}
                        onChange={() =>
                          selectAlternative(systemIndex, choice.choice_id)
                        }
                      />
                      <span>Selected for project total</span>
                    </label>
                    <label className="tc-field">
                      <span className="tc-field-label">Alternative label</span>
                      <input
                        className="tc-input"
                        type="text"
                        maxLength={60}
                        aria-label={`System ${systemIndex + 1} alternative ${choiceIndex + 1} label`}
                        value={choice.label}
                        onChange={(event) =>
                          updateChoice(systemIndex, choice.choice_id, {
                            label: event.target.value,
                          })
                        }
                      />
                    </label>
                    <label className="tc-field">
                      <span className="tc-field-label">
                        Customer sales price
                      </span>
                      <input
                        className="tc-input"
                        type="number"
                        min="0"
                        step="0.01"
                        aria-label={`System ${systemIndex + 1} alternative ${choiceIndex + 1} sales price`}
                        value={choice.sales_price ?? 0}
                        onChange={(event) =>
                          updateChoice(systemIndex, choice.choice_id, {
                            sales_price: parseFloat(event.target.value) || 0,
                            sales_price_manual: true,
                          })
                        }
                      />
                    </label>
                    <span className="tc-field-help">
                      {choice.sales_price_manual
                        ? "Manual sales price for this alternative."
                        : "Sales price follows this alternative’s selected equipment."}
                    </span>
                    <div className="tc-slot-actions">
                      <button
                        type="button"
                        className="tc-btn tc-btn-ghost tc-btn-sm"
                        aria-label={`Edit ${choice.label} equipment for ${system.system_label}`}
                        onClick={() => {
                          setPicker(null);
                          setEditingChoices((current) => ({
                            ...current,
                            [keyFor(system)]: choice.choice_id,
                          }));
                        }}
                      >
                        Edit equipment
                      </button>
                      <button
                        type="button"
                        className="tc-btn tc-btn-ghost tc-btn-sm tc-btn-danger"
                        aria-label={`Remove ${choice.label} alternative from ${system.system_label}`}
                        disabled={system.choices.length <= 1}
                        onClick={() =>
                          removeAlternative(systemIndex, choice.choice_id)
                        }
                      >
                        Remove alternative
                      </button>
                    </div>
                  </div>
                ))}
                {!selectedChoice(system) && (
                  <p className="tc-err" role="alert">
                    Select an alternative for this system.
                  </p>
                )}
                {editing && (
                  <p className="tc-editing-alternative" role="status">
                    Editing {editing.label}.{" "}
                    {editing.choice_id === system.selected_choice_id
                      ? "This alternative is selected for the project total."
                      : "This alternative is not selected for the project total."}
                  </p>
                )}
              </div>
            )}
            <button
              type="button"
              className="tc-btn tc-btn-secondary tc-btn-block"
              onClick={() => openPicker(systemIndex)}
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
                        Sales {formatMoney(item.rate || 0)}
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
                              openPicker(systemIndex, slot.category)
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
            <button
              type="button"
              className="tc-btn tc-btn-ghost"
              disabled={system.choices?.length >= 6}
              onClick={() => addAlternative(systemIndex)}
            >
              Add alternative
            </button>
            {system.choices?.length >= 6 && (
              <p className="tc-field-help">
                Up to six alternatives are available per physical system.
              </p>
            )}
          </div>
        );
      })}
      <button
        type="button"
        className="tc-btn tc-btn-ghost tc-btn-block"
        onClick={addSystem}
      >
        Add another system
      </button>
      {picker && (
        <EquipmentPicker
          key={`${picker.systemKey}:${picker.choiceId}`}
          api={api}
          initialCategory={picker.category}
          onClose={() => setPicker(null)}
          onPick={selectEquipment}
        />
      )}
    </section>
  );
}
