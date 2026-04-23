# AI State Tracker: Analytics Refactor

## Current State
Phase 2 COMPLETE. `classes/order/HotelAnalytics.php` has been created and the
`getRoomAnalytics` gateway (plus a full `getServiceAnalytics` skeleton) is implemented.
Moving to Phase 3 (service analytics validation) and Phase 4 (autoloader registration).

---

## Completed Work

### Pre-requisites (Phase 1)
- Executed SQL to add `unit_price_tax_incl` and `unit_price_tax_excl` to `qlo_htl_booking_detail`.
- Updated `HotelBookingDetail.php` ObjectModel `$definition` to include both new fields.
- Overrode `PaymentModule::validateOrder()` to copy unit prices from the cart line item.
- Overrode `AdminOrdersController` AJAX handlers to keep unit prices in sync on manual edits.

### Phase 2 — Core Class (just completed)

**File created:** `classes/order/HotelAnalytics.php`
**Class name:** `HotelAnalyticsCoreCore extends ObjectModel`

#### Architecture decisions

| Decision | Rationale |
|---|---|
| Extends `ObjectModel` | Hooks into PS autoloader; `$definition` points at `htl_booking_detail` as a no-CRUD anchor |
| `METRIC_*` constants | Prevents magic-string bugs when callers switch on metric type |
| Separate `_getRoomXxx()` helpers | Each metric is isolated — changing one does not break others |
| `_buildTransactionalDateFilter()` | Single place to change the `LEFT(date_add,10) BETWEEN` pattern |
| `_fillDateRange()` | Ensures chart arrays have a value for every day, even zero-booking days |

#### Metrics implemented inside `getRoomAnalytics()`

| Metric | Date Strategy | Extra JOINs |
|---|---|---|
| `total_revenue` | Transactional (`date_add`) | None |
| `total_sales` | Transactional | None |
| `total_due_amount` | Transactional | `LEFT JOIN qlo_orders o` (for `o.valid = 1`) |
| `refunds` | Transactional | None |
| `expenses` / `purchases_cost` | Transactional | `LEFT JOIN qlo_order_detail od` (for `purchase_supplier_price`) |
| `occupied_rooms` | **Occupational** (`date_from`→`date_to`) | None — PHP while-loop spreads rows across nights |
| `arrivals` | Transactional on `date_from` | None |
| `departures` | Transactional on `date_to` | None |
| `cancellations` | Transactional | None — `is_cancelled = 1` override |
| `average_length_of_stay` | Transactional | None — `AVG(DATEDIFF(date_to, date_from))` |
| `average_lead_time` | Transactional | None — `AVG(DATEDIFF(date_from, date_add))` |
| `average_guests_per_booking` | Transactional | None — `AVG(adults + children)` |

#### Metrics implemented inside `getServiceAnalytics()`

| Metric | Extra JOINs |
|---|---|
| `service_total_revenue` | `LEFT JOIN hbd` for date gate + status filter |
| `service_total_sales` | Same |
| `service_quantity_sold` | Same |

#### Key implementation notes
- `occupied_rooms` uses an **overlap fetch then PHP spread** pattern:
  SQL fetches all bookings where `date_from < dateTo+1` AND `date_to > dateFrom`,
  then a `while ($current <= $end)` loop counts active bookings per day.
  This correctly handles the hotel convention that the departure day (date_to) is
  NOT counted as an occupied night.
- All SQL inputs are sanitised via `pSQL()` at gateway entry; integer IDs are cast
  with `(int)`. No raw user input enters SQL strings.
- Table aliases follow `hbd` (htl_booking_detail) and `spod`
  (service_product_order_detail) throughout for readability.
- `$params['id_hotel']` optional filter is available on every metric via
  `_buildHotelFilter($params, $alias)`.

---

## Next Action

**Task 4 (Phase 4) — Register class in autoloader:**

1. Delete `cache/class_index.php` so PrestaShop regenerates its class map and picks
   up `HotelAnalyticsCoreCore`.
2. Create `override/classes/order/HotelAnalytics.php` containing:
   ```php
   <?php
   class HotelAnalyticsCore extends HotelAnalyticsCoreCore {}
   ```
   This lets all calling code use `new HotelAnalyticsCore()` without ever touching
   the core file — following PS override conventions.
3. Delete `cache/class_index.php` again after creating the override to force a
   fresh class index build.

**Then Task 5 — First migration pilot:**
Pick `statssales` as the first stats module to migrate. Replace its internal
`total_revenue` SQL with a call to `(new HotelAnalyticsCore())->getRoomAnalytics('total_revenue', $dateFrom, $dateTo)`.
Verify output matches legacy query on a real dataset.
