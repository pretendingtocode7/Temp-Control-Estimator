# Deluge functions

## `tc_generate_estimate`

Invoked by `Estimate_Generator::generate()` via `POST /crm/v7/functions/tc_generate_estimate/actions/execute?auth_type=oauth`.
The request body is form-encoded with one `arguments` field containing JSON:
`{"payload":"<JSON string>"}`.

### Install

1. Zoho CRM → **Setup → Developer Space → Functions → Standalone Functions → + New Function**.
2. Function Name: `tc_generate_estimate`. Display Name: `TC — Generate Estimate`.
3. Category: `Integration`.
4. Return Type: `String`.
5. Arguments: `payload` (String, required).
6. Paste the body of `generate_estimate.deluge` between the auto-generated `string tc_generate_estimate(string payload) {` and the closing `}`. The file here wraps the body in a `try/catch` so any Deluge-side exception surfaces as a shaped JSON error instead of an uncaught 500.
7. **Enable REST API**. This produces the execute URL.
8. Create a **Zoho Books connection** named exactly `zbooks` (CRM → Setup → Developer Space → Connections). Scopes required: `ZohoBooks.estimates.CREATE`, `ZohoBooks.estimates.UPDATE`, `ZohoBooks.items.READ`.

### Required CRM customizations

Before first invocation, these custom fields must exist on the CRM `Deals` module:

| API name | Type | Purpose |
|---|---|---|
| `Books_Estimate_ID` | Single Line (32) | The Books estimate ID this Deal was created for. |
| `Books_Estimate_Number` | Single Line (32) | Human-readable estimate number for CRM views. |
| `Idempotency_Key` | Single Line (64) | The key the PHP layer generated — lets the audit log reconcile with CRM. |
| `Financing_Requested` | Checkbox | Set from the builder's Financing toggle. |
| `Financing_Term_Months` | Number | Term in months when requested. |
| `Quoted_Equipment` | Subform | See columns below. |

`Quoted_Equipment` subform columns: `Slot` (Text), `System_Number` (Number), `Brand` (Text), `Model` (Text), `Zoho_Item_ID` (Text).

Books custom fields are optional. The function does not write them during estimate creation
because Books rejects `api_name` inside the create-estimate `custom_fields` array. The CRM
Deal and WordPress audit log are the source of truth for the estimate/deal link.

### Rollback semantics

If the CRM Deal insert fails after the Books estimate was created, the function calls
Books `/estimates/{id}/status/void` with the `zbooks` connection to void the orphaned
estimate, then returns `ok:false` with the original CRM error plus a rollback note.

### Return shape

Always a JSON-encoded string (Deluge `toString()` on a map). Consumers on the PHP side
`json_decode` it and branch on `ok`.

Success:
```json
{
  "ok": true,
  "estimate_id": "1234567890",
  "estimate_number": "EST-000042",
  "estimate_url": "https://books.zoho.com/app/#/estimates/1234567890",
  "deal_id": "9876543210",
  "deal_url": "https://crm.zoho.com/crm/tab/Potentials/9876543210",
  "subtotal": 18300.00,
  "total": 18300.00
}
```

Failure:
```json
{ "ok": false, "error_code": "tc_deluge_crm_failed", "message": "..." }
```

### Test invocation from CRM

Once installed, test from Developer Space → Functions → **Execute** with a payload like:

```json
{
  "meta": {"plugin_version":"0.2.0","idempotency_key":"test-0001","template_id":1,"template_version":1,"template_name":"Full Replacement — Coleman","template_type":"full_replacement","wp_user_id":1,"wp_user_display":"Jessica","generated_at":"2026-04-23 15:00:00"},
  "books": {"organization_id":"60000000000","customer_id":"30000000000","customer_name":"Test Customer","reference_number":"TC-1","date":"2026-04-23","expiry_date":"2026-05-23","line_items":[{"item_id":"100000001","name":"Coleman TG9S","description":"Main — furnace","rate":3200,"quantity":1}],"notes":"test","template_body":"<p>test</p>","billing_address":{"address":"1 Main","city":"Edison","state":"NJ","zip":"08817","country":"U.S.A."},"subtotal":3200,"total":3200,"deposit_percent":35},
  "crm": {"account_id":"20000000000","deal_name":"Test — Full Replacement","stage":"Proposal Sent","closing_date":"2026-05-23","amount":3200,"description":"Test","quoted_equipment":[{"Slot":"furnace","System_Number":1,"Brand":"Coleman","Model":"TG9S","Zoho_Item_ID":"100000001"}],"financing_requested":false,"financing_term":0}
}
```

Substitute real Zoho IDs for the zeroed-out examples before running.
