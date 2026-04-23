# AI State Tracker: Analytics Refactor

## Current State
**SYSTEM RESET COMPLETE.** Three root-cause bugs identified and fixed across all three files.
The gateway class now faithfully replicates the legacy occupational math.
Next action: autoloader registration, then pilot migration of statssales.

---

## Completed Work

### Phase 1 — Pre-requisites (done before this session)
- Added `unit_price_tax_incl` / `unit_price_tax_excl` columns to `qlo_htl_booking_detail`.
- Updated `HotelBookingDetail.php` ObjectModel definition.

### Phase 2 — System Reset (this session)

---

#### Bug 1 Fixed: `classes/PaymentModule.php` — Unit Price Corruption

**Root cause:** The override was calling `HotelRoomTypeFeaturePricing::getRoomTypeTotalPrice()`
(a re-fetch of prices) and then dividing by `numDays`. This re-fetch does not account for all
the cart rules, group discounts, and rounding that PS already applied when it created the
`order_detail` row inside the base `validateOrder()` call. The result was hbd rows whose
`unit_price_tax_excl` did not match what was actually charged.

**Fix:**  After `getPsOrderDetailIdByIdProduct()`, we now load `new OrderDetail($id_order_detail)`
and copy its `unit_price_tax_excl / unit_price_tax_incl` directly into hbd.

Why this is correct: In QloApps, PS creates `order_detail` with
`product_quantity = numNights` and `unit_price = per-night rate`, so no further
division is needed. The PS engine already did the math correctly.

```php
// New code in validateOrder() override
$objNativeOrderDetail = new OrderDetail((int)$id_order_detail);
if (Validate::isLoadedObject($objNativeOrderDetail)) {
    $objBookingDetail->unit_price_tax_excl = (float)$objNativeOrderDetail->unit_price_tax_excl;
    $objBookingDetail->unit_price_tax_incl = (float)$objNativeOrderDetail->unit_price_tax_incl;
} else {
    // defensive fallback with old division logic ...
}
```

---

#### Bug 2 Fixed: `controllers/admin/AdminOrdersController.php` — Three locations

**2a. `ajaxProcessAddProductOnOrder` (line ~5737):**
Same root cause as Bug 1. Replaced `total_price / numDays` division with
`new OrderDetail($booking->id_order_detail)->unit_price_tax_excl`.

**2b. Room edit path (after line ~5994):**
The room-edit AJAX handler updated `order_detail` (correctly setting
`unit_price = total / quantity`) but never synced those values back to hbd.
Added after `$res &= $order_detail->update()`:
```php
$obj_booking_detail->unit_price_tax_excl = (float)$order_detail->unit_price_tax_excl;
$obj_booking_detail->unit_price_tax_incl = (float)$order_detail->unit_price_tax_incl;
$res &= $obj_booking_detail->update();
```

**2c. `ajaxProcessEditProductOnOrder` (service edit, lines ~6362-6382):**
An entire block was incorrectly updating hbd's `unit_price_tax_excl` by dividing
`hbd.total_price_tax_excl / numDays` whenever a SERVICE product was edited.
This is wrong: editing a service does not change the room's nightly rate.
Block removed; replaced with a single clarifying comment.

---

#### Bug 3 Fixed: `classes/order/HotelAnalytics.php` — Wrong Date Strategy for Financials

**Root cause:** The first version grouped financial metrics by `date_add` (transactional).
The entire QloApps dashboard uses OCCUPATIONAL math for daily breakdowns:
```
daily_revenue = (hbd.total_price_tax_excl / o.conversion_rate) / DATEDIFF(date_to, date_from)
```
which equals:
```
daily_revenue = hbd.unit_price_tax_excl / o.conversion_rate
```
A booking covering 5 nights contributes its per-night rate to each of those 5 days,
not just to its creation date.

**Fix — New architecture:**

| Scenario | Strategy |
|---|---|
| `datewise_breakdown = false` (aggregate) | Transactional: `SUM(total / o.conversion_rate)` filtered by `LEFT(date_add,10) BETWEEN` |
| `datewise_breakdown = true` (chart data) | Occupational: single SQL fetch of all overlapping stays, then PHP while-loop spreading |

**New central engine:** `_spreadOccupationalMetric()` — reusable for all financial metrics.
It pre-computes `daily_value = valueKey / conversionRate` per booking once, then walks
the date window assigning the sum of active bookings per day.

**Metrics and their formula sources (matching legacy exactly):**

| Metric | Legacy source | Daily value |
|---|---|---|
| `total_revenue` | `getRoomsRevenueForDiscreteDates` | `hbd.unit_price_tax_excl / o.conversion_rate` |
| `total_sales` | Same, tax-incl column | `hbd.unit_price_tax_incl / o.conversion_rate` |
| `expenses` | `getOperatingExpensesForDiscreteDates` | `od.purchase_supplier_price / o.conversion_rate` (or margin fallback) |
| `service_total_revenue` | `getServicesRevenueForDiscreteDates` | `(spod.total_price_tax_excl / o.conversion_rate) / DATEDIFF` |
| `occupied_rooms` | `getTotalRoomsForDiscreteDates` equivalent | Count of active bookings per day |
| `total_due_amount` | dashactivity KPI | Transactional SUM — not spread |

**Conversion rate logic:**
- Revenue / expenses: divide by `o.conversion_rate` (as in every legacy query).
- `total_due_amount`: no conversion_rate division — legacy dashactivity compares
  `total_paid_tax_incl - total_paid_real` in the same currency.

---

## Data Flow Summary

```
Order placed
  └─ PS base validateOrder() creates order_detail
       unit_price_tax_excl = per-night rate   (PS native)
       product_quantity    = num_nights        (PS native)
  └─ Override: HotelBookingDetail row created
       unit_price_tax_excl ← order_detail.unit_price_tax_excl  ✓ (fixed)
       total_price_tax_excl = total stay price

Room edited (admin)
  └─ order_detail updated: unit_price = total / quantity
  └─ hbd.unit_price_tax_excl ← order_detail.unit_price_tax_excl  ✓ (fixed)

HotelAnalyticsCore.getRoomAnalytics('total_revenue', from, to, ['datewise_breakdown'=>true])
  └─ SQL: fetch all bookings overlapping [from,to] with unit_price + conversion_rate
  └─ PHP loop: for each day, SUM(unit_price / conversion_rate) for active bookings
  └─ Returns: ['2024-01-01' => 450.00, '2024-01-02' => 620.00, ...]
```

---

## Next Action

**Task 4 — Autoloader registration:**
1. Delete `cache/class_index.php` to force PS to regenerate the class map.
2. Confirm `HotelAnalyticsCore` is resolvable: `new HotelAnalyticsCore()` in a test admin action.
   (No override stub needed — the class is directly named `HotelAnalyticsCore` in the core file.)

**Task 5 — Pilot migration:**
Replace `AdminStatsController::getRoomsRevenueForDiscreteDates()` calls with:
```php
$analytics = new HotelAnalyticsCore();
$revenueByDate = $analytics->getRoomAnalytics(
    HotelAnalyticsCore::METRIC_TOTAL_REVENUE,
    $dateFrom,
    $dateTo,
    array('datewise_breakdown' => true, 'id_hotel' => $idHotel)
);
```
Spot-check output against the legacy function on a known dataset before fully replacing.
