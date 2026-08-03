import React, { useState } from 'react';
import EquipmentPicker from './EquipmentPicker.jsx';
import { formatMoney, cls } from '../lib/format.js';

// The slots the UI offers per system. Order matters — this is also the display order.
const ALL_SLOTS = [
  { key: 'furnace', label: 'Furnace', type: 'furnace' },
  { key: 'condenser', label: 'Condenser', type: 'condenser' },
  { key: 'coil', label: 'Evaporator Coil', type: 'evaporator_coil' },
  { key: 'air_handler', label: 'Air Handler', type: 'air_handler' },
  { key: 'thermostat', label: 'Thermostat', type: 'thermostat' },
  { key: 'humidifier', label: 'Humidifier', type: 'humidifier' },
  { key: 'uv_purifier', label: 'UV Purifier', type: 'uv_purifier' },
  { key: 'water_heater', label: 'Water Heater', type: 'water_heater' },
  { key: 'filter', label: 'Filter', type: 'filter' },
  { key: 'part', label: 'Part', type: 'part' },
];

/**
 * Determine which slots to show per template. Full-replacement shows furnace/condenser/coil
 * by default; AC-only skips furnace; etc. Users can always add accessory slots below.
 */
function slotsForTemplate(template) {
  const t = template?.template_type || '';
  if (t === 'ac_only') return ['condenser', 'coil', 'thermostat'];
  if (t === 'furnace_only') return ['furnace', 'thermostat'];
  if (t === 'maintenance' || t === 'service_repair') return ['other'];
  return ['furnace', 'condenser', 'coil', 'thermostat']; // default
}

export default function SystemsStep({ api, systems, template, onChange }) {
  const [pickerFor, setPickerFor] = useState(null); // { systemIdx, slotKey, type, label }

  const defaultSlots = slotsForTemplate(template);

  const updateSystem = (idx, patch) => {
    const next = systems.map((s, i) => (i === idx ? { ...s, ...patch } : s));
    onChange(next);
  };

  const updateEquipment = (idx, slotKey, item) => {
    const system = systems[idx];
    const equipment = { ...(system.equipment || {}) };
    if (item === null) {
      delete equipment[slotKey];
    } else {
      equipment[slotKey] = item;
    }
    updateSystem(idx, { equipment });
  };

  const addSystem = () => {
    const nextNum = systems.length + 1;
    const labels = ['Second Floor System', 'Third Floor System', 'Basement System', 'Additional System'];
    const label = labels[nextNum - 2] || `System ${nextNum}`;
    onChange([
      ...systems,
      { system_number: nextNum, system_label: label, equipment: {} },
    ]);
  };

  const removeSystem = (idx) => {
    if (systems.length === 1) return;
    const next = systems
      .filter((_, i) => i !== idx)
      .map((s, i) => ({ ...s, system_number: i + 1 }));
    onChange(next);
  };

  return (
    <section className="tc-step">
      <h2 className="tc-step-title">Equipment to install</h2>
      <p className="tc-step-help">
        Add a system for each independent HVAC zone. Most homes have one system; multi-story
        jobs have two or three.
      </p>

      {systems.map((sys, idx) => {
        const visibleSlots = ALL_SLOTS.filter(
          (s) => defaultSlots.includes(s.key) || sys.equipment?.[s.key]
        );
        const canAddAccessory = ALL_SLOTS.filter(
          (s) => !visibleSlots.find((v) => v.key === s.key)
        );

        return (
          <div key={idx} className="tc-system">
            <header className="tc-system-head">
              <input
                type="text"
                className="tc-input tc-input-inline"
                value={sys.system_label}
                onChange={(e) => updateSystem(idx, { system_label: e.target.value })}
                aria-label={`System ${idx + 1} label`}
                maxLength={60}
              />
              {systems.length > 1 && (
                <button
                  type="button"
                  className="tc-btn tc-btn-ghost tc-btn-sm"
                  onClick={() => removeSystem(idx)}
                >
                  Remove
                </button>
              )}
            </header>

            <ul className="tc-slots">
              {visibleSlots.map((slot) => {
                const picked = sys.equipment?.[slot.key];
                return (
                  <li key={slot.key} className={cls('tc-slot', picked && 'is-filled')}>
                    <div className="tc-slot-head">
                      <span className="tc-slot-label">{slot.label}</span>
                      {picked && (
                        <span className="tc-slot-price">{formatMoney(picked.rate || 0)}</span>
                      )}
                    </div>
                    {picked ? (
                      <>
                        <div className="tc-slot-body">
                          <div className="tc-slot-picked">
                            <div className="tc-slot-model">
                              {picked.name || `${picked.brand || ''} ${picked.model || ''}`.trim()}
                            </div>
                            {picked.sku && <div className="tc-slot-desc">SKU {picked.sku}</div>}
                          </div>
                          <div className="tc-slot-actions">
                            <button
                              type="button"
                              className="tc-btn tc-btn-ghost tc-btn-sm"
                              onClick={() =>
                                setPickerFor({
                                  systemIdx: idx,
                                  slotKey: slot.key,
                                  type: slot.type,
                                  label: slot.label,
                                })
                              }
                            >
                              Change
                            </button>
                            <button
                              type="button"
                              className="tc-btn tc-btn-ghost tc-btn-sm tc-btn-danger"
                              onClick={() => updateEquipment(idx, slot.key, null)}
                            >
                              Remove
                            </button>
                          </div>
                        </div>
                        <label className="tc-field tc-item-description">
                          <span className="tc-field-label">Estimate description</span>
                          <textarea
                            className="tc-textarea"
                            rows="3"
                            maxLength="2000"
                            value={
                              Object.prototype.hasOwnProperty.call(picked, 'description')
                                ? picked.description
                                : picked.short_description || ''
                            }
                            onChange={(e) =>
                              updateEquipment(idx, slot.key, {
                                ...picked,
                                description: e.target.value,
                              })
                            }
                          />
                          <span className="tc-field-help">
                            This wording appears on the customer estimate. Editing it does not change the Books item.
                          </span>
                        </label>
                      </>
                    ) : (
                      <button
                        type="button"
                        className="tc-slot-empty"
                        onClick={() =>
                          setPickerFor({
                            systemIdx: idx,
                            slotKey: slot.key,
                            type: slot.type,
                            label: slot.label,
                          })
                        }
                      >
                        + Add {slot.label.toLowerCase()}
                      </button>
                    )}
                  </li>
                );
              })}
            </ul>

            {canAddAccessory.length > 0 && (
              <details className="tc-accessory">
                <summary>Add accessory</summary>
                <div className="tc-accessory-list">
                  {canAddAccessory.map((slot) => (
                    <button
                      key={slot.key}
                      type="button"
                      className="tc-btn tc-btn-ghost tc-btn-sm"
                      onClick={() =>
                        setPickerFor({
                          systemIdx: idx,
                          slotKey: slot.key,
                          type: slot.type,
                          label: slot.label,
                        })
                      }
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

      <button type="button" className="tc-btn tc-btn-ghost tc-btn-block" onClick={addSystem}>
        + Add another system
      </button>

      {pickerFor && (
        <EquipmentPicker
          api={api}
          type={pickerFor.type}
          label={pickerFor.label}
          onClose={() => setPickerFor(null)}
          onPick={(item) => {
            updateEquipment(pickerFor.systemIdx, pickerFor.slotKey, item);
            setPickerFor(null);
          }}
        />
      )}
    </section>
  );
}
