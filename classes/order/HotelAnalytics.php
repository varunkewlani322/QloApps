<?php
/*
 * QloApps - Open Source Hotel Reservation & Management System
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade QloApps to newer
 * versions in the future.
 *
 * @author    Webkul <support@qloapps.com>
 * @copyright Since 2010 Webkul
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * HotelAnalyticsCore — Centralized Analytics Query Gateway.
 *
 * Replaces the N-query pattern in AdminStatsController with two gateway methods.
 * Faithfully replicates the legacy occupational math:
 *
 *   Legacy formula (getRoomsRevenueForDiscreteDates):
 *     daily_revenue = (hbd.total_price_tax_excl / o.conversion_rate) / DATEDIFF(date_to, date_from)
 *   Equivalent using the denormalized unit_price column (unit_price = total / nights):
 *     daily_revenue = hbd.unit_price_tax_excl / o.conversion_rate
 *
 * Two date strategies are used depending on context:
 *
 *   TRANSACTIONAL — aggregate scalars, filtered/grouped by hbd.date_add.
 *   OCCUPATIONAL  — datewise breakdowns. A booking spanning multiple nights
 *                   contributes its per-night value to EVERY day of its stay.
 *                   Implemented as a single SQL fetch of overlapping bookings
 *                   followed by a PHP while-loop to spread values day by day.
 *
 * Dynamic Join policy: additional tables are LEFT JOINed only when the metric
 * requires data that is not in the denormalized fact table.
 */
class HotelAnalyticsCore extends ObjectModel
{
    // -------------------------------------------------------------------------
    // ObjectModel definition — minimal anchor for the PS autoloader.
    // This class is a read-only query engine; no CRUD is performed.
    // -------------------------------------------------------------------------

    /** @var array */
    public static $definition = array(
        'table'   => 'htl_booking_detail',
        'primary' => 'id',
        'fields'  => array(),
    );

    // =========================================================================
    // METRIC CONSTANTS — Room Analytics
    // =========================================================================

    /**
     * Net revenue: SUM(hbd.total_price_tax_excl / o.conversion_rate).
     * Datewise: per-night rate spread across occupied nights.
     */
    const METRIC_TOTAL_REVENUE = 'total_revenue';

    /**
     * Gross revenue: SUM(hbd.total_price_tax_incl / o.conversion_rate).
     * Datewise: same occupational spreading as total_revenue.
     */
    const METRIC_TOTAL_SALES = 'total_sales';

    /**
     * Outstanding balance: SUM(hbd.total_price_tax_incl - hbd.total_paid_amount).
     * Transactional date (date_add). Requires JOIN qlo_orders for o.valid = 1.
     */
    const METRIC_TOTAL_DUE_AMOUNT = 'total_due_amount';

    /**
     * Refund value: SUM(hbd.total_price_tax_excl / o.conversion_rate) where is_refunded = 1.
     * Transactional (date_add).
     */
    const METRIC_REFUNDS = 'refunds';

    /**
     * Operating expense per occupied room-night.
     * Legacy formula:
     *   IF purchase_supplier_price != 0: od.purchase_supplier_price / o.conversion_rate
     *   ELSE: (od.original_product_price / o.conversion_rate) * CONF_AVERAGE_PRODUCT_MARGIN / 100
     * Datewise: spread across occupied nights. Requires JOIN order_detail + orders.
     */
    const METRIC_EXPENSES = 'expenses';

    /** Alias for METRIC_EXPENSES. */
    const METRIC_PURCHASES_COST = 'purchases_cost';

    /**
     * Occupied room-nights.
     * OCCUPATIONAL: a booking date_from→date_to contributes 1 to every day
     * it covers (checkout day excluded). No extra JOINs.
     */
    const METRIC_OCCUPIED_ROOMS = 'occupied_rooms';

    /** Arrivals: bookings whose date_from falls within the period. */
    const METRIC_ARRIVALS = 'arrivals';

    /** Departures: bookings whose date_to falls within the period. */
    const METRIC_DEPARTURES = 'departures';

    /** Cancellations: COUNT where is_cancelled = 1. Transactional. */
    const METRIC_CANCELLATIONS = 'cancellations';

    /** Average length of stay: AVG(DATEDIFF(date_to, date_from)). */
    const METRIC_ALOS = 'average_length_of_stay';

    /** Average days between booking creation and arrival. */
    const METRIC_AVG_LEAD_TIME = 'average_lead_time';

    /** Average total guests (adults + children) per booking. */
    const METRIC_AVG_GUESTS = 'average_guests_per_booking';

    // =========================================================================
    // METRIC CONSTANTS — Service Analytics
    // =========================================================================

    /** Net service revenue: SUM(spod.total_price_tax_excl / o.conversion_rate). */
    const SVC_METRIC_TOTAL_REVENUE = 'service_total_revenue';

    /** Gross service revenue: SUM((spod.unit_price_tax_incl * spod.quantity) / o.conversion_rate). */
    const SVC_METRIC_TOTAL_SALES = 'service_total_sales';

    /** Quantity sold: SUM(spod.quantity). */
    const SVC_METRIC_QUANTITY_SOLD = 'service_quantity_sold';

    // =========================================================================
    // PUBLIC GATEWAY — ROOM ANALYTICS
    // =========================================================================

    /**
     * Central gateway for all room-level analytics queries.
     *
     * Base table is always `qlo_htl_booking_detail` (hbd). Additional tables
     * are LEFT JOINed only when the metric strictly requires their data.
     *
     * @param string $metricType  One of the METRIC_* constants above.
     * @param string $dateFrom    Start date inclusive, format 'Y-m-d'.
     * @param string $dateTo      End date inclusive, format 'Y-m-d'.
     * @param array  $params {
     *     @type bool   $datewise_breakdown  true = day-keyed array, false = scalar. Default false.
     *     @type int    $id_hotel            Restrict to a single property.
     * }
     * @return float|int|array
     */
    public function getRoomAnalytics($metricType, $dateFrom, $dateTo, $params = array())
    {
        $dateFrom = pSQL($dateFrom);
        $dateTo   = pSQL($dateTo);
        $datewise = !empty($params['datewise_breakdown']);

        switch ($metricType) {
            case self::METRIC_TOTAL_REVENUE:
                return $this->_getRoomRevenue($dateFrom, $dateTo, $params, $datewise, false);

            case self::METRIC_TOTAL_SALES:
                return $this->_getRoomRevenue($dateFrom, $dateTo, $params, $datewise, true);

            case self::METRIC_TOTAL_DUE_AMOUNT:
                return $this->_getRoomDueAmount($dateFrom, $dateTo, $params, $datewise);

            case self::METRIC_REFUNDS:
                return $this->_getRoomRefunds($dateFrom, $dateTo, $params, $datewise);

            case self::METRIC_EXPENSES:
            case self::METRIC_PURCHASES_COST:
                return $this->_getRoomExpenses($dateFrom, $dateTo, $params, $datewise);

            case self::METRIC_OCCUPIED_ROOMS:
                return $this->_getRoomOccupancy($dateFrom, $dateTo, $params, $datewise);

            case self::METRIC_ARRIVALS:
                return $this->_getRoomArrivals($dateFrom, $dateTo, $params, $datewise);

            case self::METRIC_DEPARTURES:
                return $this->_getRoomDepartures($dateFrom, $dateTo, $params, $datewise);

            case self::METRIC_CANCELLATIONS:
                return $this->_getRoomCancellations($dateFrom, $dateTo, $params, $datewise);

            case self::METRIC_ALOS:
                return $this->_getRoomAlos($dateFrom, $dateTo, $params);

            case self::METRIC_AVG_LEAD_TIME:
                return $this->_getRoomAvgLeadTime($dateFrom, $dateTo, $params);

            case self::METRIC_AVG_GUESTS:
                return $this->_getRoomAvgGuests($dateFrom, $dateTo, $params);

            default:
                return $datewise ? array() : 0;
        }
    }

    // =========================================================================
    // PUBLIC GATEWAY — SERVICE ANALYTICS
    // =========================================================================

    /**
     * Central gateway for service-product analytics.
     *
     * Base table: `qlo_service_product_order_detail` (spod).
     * Follows the same dynamic join, status filtering, and datewise rules
     * as getRoomAnalytics().
     *
     * @param string $metricType  One of the SVC_METRIC_* constants.
     * @param string $dateFrom    'Y-m-d' inclusive.
     * @param string $dateTo      'Y-m-d' inclusive.
     * @param array  $params      Same options as getRoomAnalytics().
     * @return float|int|array
     */
    public function getServiceAnalytics($metricType, $dateFrom, $dateTo, $params = array())
    {
        $dateFrom = pSQL($dateFrom);
        $dateTo   = pSQL($dateTo);
        $datewise = !empty($params['datewise_breakdown']);

        switch ($metricType) {
            case self::SVC_METRIC_TOTAL_REVENUE:
                return $this->_getServiceRevenue($dateFrom, $dateTo, $params, $datewise, false);

            case self::SVC_METRIC_TOTAL_SALES:
                return $this->_getServiceRevenue($dateFrom, $dateTo, $params, $datewise, true);

            case self::SVC_METRIC_QUANTITY_SOLD:
                return $this->_getServiceQuantity($dateFrom, $dateTo, $params, $datewise);

            default:
                return $datewise ? array() : 0;
        }
    }

    // =========================================================================
    // PRIVATE IMPLEMENTATIONS — ROOM METRICS
    // =========================================================================

    /**
     * Room revenue (net or gross).
     *
     * Aggregate (datewise=false):
     *   Transactional date filter (hbd.date_add). Sums total/conversion_rate.
     *   Dynamic JOINs: qlo_orders (for o.valid = 1 and conversion_rate).
     *
     * Datewise breakdown (datewise=true):
     *   OCCUPATIONAL — fetches all stays overlapping the window, then a PHP
     *   while-loop assigns the per-night value (unit_price / conversion_rate)
     *   to every day the room is occupied.
     *   This exactly replicates legacy getRoomsRevenueForDiscreteDates():
     *     (hbd.total_price_tax_excl / o.conversion_rate) / DATEDIFF(date_to, date_from)
     *   which equals:
     *     hbd.unit_price_tax_excl / o.conversion_rate   (our denormalized column)
     *
     * @param bool $includeTax  false = net (tax-excl), true = gross (tax-incl).
     */
    protected function _getRoomRevenue($dateFrom, $dateTo, $params, $datewise, $includeTax)
    {
        $totalCol = $includeTax ? 'hbd.`total_price_tax_incl`' : 'hbd.`total_price_tax_excl`';
        $unitCol  = $includeTax ? 'hbd.`unit_price_tax_incl`'  : 'hbd.`unit_price_tax_excl`';
        $where    = $this->_buildHotelFilter($params, 'hbd');

        if (!$datewise) {
            // Aggregate: filter by booking creation date, apply conversion_rate.
            $sql = 'SELECT IFNULL(SUM('.$totalCol.' / o.`conversion_rate`), 0) AS value
                    FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                    LEFT JOIN `'._DB_PREFIX_.'orders` o ON (o.`id_order` = hbd.`id_order`)
                    WHERE '.$this->_buildTransactionalDateFilter($dateFrom, $dateTo, 'hbd').'
                      AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0
                      AND o.`valid` = 1'
                      .$where;

            return (float)Db::getInstance()->getValue($sql);
        }

        // Datewise: single SQL fetch of all overlapping bookings, then PHP spreading.
        // The per-night value per booking = unit_price / conversion_rate.
        $sql = 'SELECT hbd.`date_from`, hbd.`date_to`,
                       '.$unitCol.' AS unit_price,
                       o.`conversion_rate`
                FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                LEFT JOIN `'._DB_PREFIX_.'orders` o ON (o.`id_order` = hbd.`id_order`)
                WHERE hbd.`date_from` < \''.pSQL($dateTo).' 23:59:59\'
                  AND hbd.`date_to` > \''.pSQL($dateFrom).'\'
                  AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0
                  AND o.`valid` = 1'
                  .$where;

        $bookings = Db::getInstance()->executeS($sql);
        return $this->_spreadOccupationalMetric($dateFrom, $dateTo, $bookings, 'unit_price', 'conversion_rate');
    }

    /**
     * Outstanding room balance: SUM(total_price_tax_incl - total_paid_amount).
     *
     * Always transactional (date_add). Not occupationally spread because this is a
     * point-in-time balance, not a per-night rate.
     * Dynamic JOINs: qlo_orders — for o.valid = 1 gate.
     */
    protected function _getRoomDueAmount($dateFrom, $dateTo, $params, $datewise)
    {
        $where = $this->_buildHotelFilter($params, 'hbd');

        if (!$datewise) {
            $sql = 'SELECT IFNULL(SUM(hbd.`total_price_tax_incl` - hbd.`total_paid_amount`), 0) AS value
                    FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                    LEFT JOIN `'._DB_PREFIX_.'orders` o ON (o.`id_order` = hbd.`id_order`)
                    WHERE '.$this->_buildTransactionalDateFilter($dateFrom, $dateTo, 'hbd').'
                      AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0
                      AND o.`valid` = 1'
                      .$where;

            return (float)Db::getInstance()->getValue($sql);
        }

        $sql = 'SELECT LEFT(hbd.`date_add`, 10) AS booking_date,
                       SUM(hbd.`total_price_tax_incl` - hbd.`total_paid_amount`) AS value
                FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                LEFT JOIN `'._DB_PREFIX_.'orders` o ON (o.`id_order` = hbd.`id_order`)
                WHERE '.$this->_buildTransactionalDateFilter($dateFrom, $dateTo, 'hbd').'
                  AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0
                  AND o.`valid` = 1'
                  .$where.'
                GROUP BY LEFT(hbd.`date_add`, 10)
                ORDER BY booking_date ASC';

        $rows = Db::getInstance()->executeS($sql);
        return $this->_fillDateRange($dateFrom, $dateTo, $rows, 'booking_date', 'value');
    }

    /**
     * Value of refunded bookings.
     *
     * Transactional (date_add). Status filter INVERTED: is_refunded = 1.
     * Dynamic JOINs: qlo_orders — for conversion_rate.
     */
    protected function _getRoomRefunds($dateFrom, $dateTo, $params, $datewise)
    {
        $where = $this->_buildHotelFilter($params, 'hbd');

        if (!$datewise) {
            $sql = 'SELECT IFNULL(SUM(hbd.`total_price_tax_excl` / o.`conversion_rate`), 0) AS value
                    FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                    LEFT JOIN `'._DB_PREFIX_.'orders` o ON (o.`id_order` = hbd.`id_order`)
                    WHERE '.$this->_buildTransactionalDateFilter($dateFrom, $dateTo, 'hbd').'
                      AND hbd.`is_refunded` = 1'
                      .$where;

            return (float)Db::getInstance()->getValue($sql);
        }

        $sql = 'SELECT LEFT(hbd.`date_add`, 10) AS booking_date,
                       SUM(hbd.`total_price_tax_excl` / o.`conversion_rate`) AS value
                FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                LEFT JOIN `'._DB_PREFIX_.'orders` o ON (o.`id_order` = hbd.`id_order`)
                WHERE '.$this->_buildTransactionalDateFilter($dateFrom, $dateTo, 'hbd').'
                  AND hbd.`is_refunded` = 1'
                  .$where.'
                GROUP BY LEFT(hbd.`date_add`, 10)
                ORDER BY booking_date ASC';

        $rows = Db::getInstance()->executeS($sql);
        return $this->_fillDateRange($dateFrom, $dateTo, $rows, 'booking_date', 'value');
    }

    /**
     * Operating expenses per occupied room-night.
     *
     * Replicates legacy getOperatingExpensesForDiscreteDates() rooms SQL:
     *   CASE
     *     WHEN purchase_supplier_price != 0:
     *       od.purchase_supplier_price / o.conversion_rate          (per-night cost)
     *     ELSE:
     *       (od.original_product_price / o.conversion_rate) * MARGIN / 100
     *   END
     *
     * In QloApps, od.purchase_supplier_price is the per-night supplier cost
     * (because od.product_quantity = numNights). No further division needed.
     *
     * Aggregate: transactional (date_add).
     * Datewise: OCCUPATIONAL spreading via PHP loop (same as revenue).
     * Dynamic JOINs: qlo_order_detail (purchase_supplier_price, original_product_price)
     *               + qlo_orders (conversion_rate, valid).
     */
    protected function _getRoomExpenses($dateFrom, $dateTo, $params, $datewise)
    {
        $margin = (int)Configuration::get('CONF_AVERAGE_PRODUCT_MARGIN');
        $where  = $this->_buildHotelFilter($params, 'hbd');

        // The CASE expression replicated directly from the legacy function.
        $caseExpr = 'CASE
                         WHEN od.`purchase_supplier_price` <> \'0.000000\'
                         THEN od.`purchase_supplier_price` / o.`conversion_rate`
                         ELSE (od.`original_product_price` / o.`conversion_rate`) * '.$margin.' / 100
                     END';

        if (!$datewise) {
            $sql = 'SELECT IFNULL(SUM('.$caseExpr.'), 0) AS value
                    FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                    LEFT JOIN `'._DB_PREFIX_.'order_detail` od ON (od.`id_order_detail` = hbd.`id_order_detail`)
                    LEFT JOIN `'._DB_PREFIX_.'orders` o ON (o.`id_order` = hbd.`id_order`)
                    WHERE '.$this->_buildTransactionalDateFilter($dateFrom, $dateTo, 'hbd').'
                      AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0
                      AND o.`valid` = 1'
                      .$where;

            return (float)Db::getInstance()->getValue($sql);
        }

        // Datewise: fetch per-night cost for every overlapping booking.
        // od.purchase_supplier_price = per-night cost, so it is spread by occupancy, not divided.
        $sql = 'SELECT hbd.`date_from`, hbd.`date_to`,
                       '.$caseExpr.' AS daily_cost
                FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                LEFT JOIN `'._DB_PREFIX_.'order_detail` od ON (od.`id_order_detail` = hbd.`id_order_detail`)
                LEFT JOIN `'._DB_PREFIX_.'orders` o ON (o.`id_order` = hbd.`id_order`)
                WHERE hbd.`date_from` < \''.pSQL($dateTo).' 23:59:59\'
                  AND hbd.`date_to` > \''.pSQL($dateFrom).'\'
                  AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0
                  AND o.`valid` = 1'
                  .$where;

        $bookings = Db::getInstance()->executeS($sql);
        return $this->_spreadOccupationalMetric($dateFrom, $dateTo, $bookings, 'daily_cost', null);
    }

    /**
     * Count of occupied room-nights per day.
     *
     * OCCUPATIONAL: a booking contributes 1 to every day it covers.
     * Checkout day (date_to) is NOT counted (hotel industry convention).
     * Dynamic JOINs: NONE.
     */
    protected function _getRoomOccupancy($dateFrom, $dateTo, $params, $datewise)
    {
        $where = $this->_buildHotelFilter($params, 'hbd');

        $sql = 'SELECT hbd.`date_from`, hbd.`date_to`
                FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                WHERE hbd.`date_from` < \''.pSQL($dateTo).' 23:59:59\'
                  AND hbd.`date_to` > \''.pSQL($dateFrom).'\'
                  AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0'
                  .$where;

        $bookings = Db::getInstance()->executeS($sql);

        if (empty($bookings)) {
            return $datewise ? $this->_buildZeroArray($dateFrom, $dateTo) : 0;
        }

        $parsed = array();
        foreach ($bookings as $b) {
            $parsed[] = array(
                'from' => new DateTime(substr($b['date_from'], 0, 10)),
                'to'   => new DateTime(substr($b['date_to'], 0, 10)),
            );
        }

        $current = new DateTime($dateFrom);
        $end     = new DateTime($dateTo);
        $result  = array();
        $total   = 0;

        while ($current <= $end) {
            $dayStr = $current->format('Y-m-d');
            $count  = 0;
            foreach ($parsed as $b) {
                if ($b['from'] <= $current && $b['to'] > $current) {
                    $count++;
                }
            }
            $result[$dayStr] = $count;
            $total          += $count;
            $current->modify('+1 day');
        }

        return $datewise ? $result : $total;
    }

    /**
     * Count of arrivals: bookings whose date_from falls within the period.
     * Transactional on hbd.date_from. Dynamic JOINs: NONE.
     */
    protected function _getRoomArrivals($dateFrom, $dateTo, $params, $datewise)
    {
        $where = $this->_buildHotelFilter($params, 'hbd');

        if (!$datewise) {
            $sql = 'SELECT COUNT(hbd.`id`) AS value
                    FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                    WHERE LEFT(hbd.`date_from`, 10) BETWEEN \''.$dateFrom.'\' AND \''.$dateTo.'\'
                      AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0'
                      .$where;

            return (int)Db::getInstance()->getValue($sql);
        }

        $sql = 'SELECT LEFT(hbd.`date_from`, 10) AS booking_date, COUNT(hbd.`id`) AS value
                FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                WHERE LEFT(hbd.`date_from`, 10) BETWEEN \''.$dateFrom.'\' AND \''.$dateTo.'\'
                  AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0'
                  .$where.'
                GROUP BY LEFT(hbd.`date_from`, 10)
                ORDER BY booking_date ASC';

        $rows = Db::getInstance()->executeS($sql);
        return $this->_fillDateRange($dateFrom, $dateTo, $rows, 'booking_date', 'value', 0);
    }

    /**
     * Count of departures: bookings whose date_to falls within the period.
     * Transactional on hbd.date_to. Dynamic JOINs: NONE.
     */
    protected function _getRoomDepartures($dateFrom, $dateTo, $params, $datewise)
    {
        $where = $this->_buildHotelFilter($params, 'hbd');

        if (!$datewise) {
            $sql = 'SELECT COUNT(hbd.`id`) AS value
                    FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                    WHERE LEFT(hbd.`date_to`, 10) BETWEEN \''.$dateFrom.'\' AND \''.$dateTo.'\'
                      AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0'
                      .$where;

            return (int)Db::getInstance()->getValue($sql);
        }

        $sql = 'SELECT LEFT(hbd.`date_to`, 10) AS booking_date, COUNT(hbd.`id`) AS value
                FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                WHERE LEFT(hbd.`date_to`, 10) BETWEEN \''.$dateFrom.'\' AND \''.$dateTo.'\'
                  AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0'
                  .$where.'
                GROUP BY LEFT(hbd.`date_to`, 10)
                ORDER BY booking_date ASC';

        $rows = Db::getInstance()->executeS($sql);
        return $this->_fillDateRange($dateFrom, $dateTo, $rows, 'booking_date', 'value', 0);
    }

    /**
     * Count of cancellations. Status filter INVERTED: is_cancelled = 1.
     * Transactional (date_add). Dynamic JOINs: NONE.
     */
    protected function _getRoomCancellations($dateFrom, $dateTo, $params, $datewise)
    {
        $where = $this->_buildHotelFilter($params, 'hbd');

        if (!$datewise) {
            $sql = 'SELECT COUNT(hbd.`id`) AS value
                    FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                    WHERE '.$this->_buildTransactionalDateFilter($dateFrom, $dateTo, 'hbd').'
                      AND hbd.`is_cancelled` = 1'
                      .$where;

            return (int)Db::getInstance()->getValue($sql);
        }

        $sql = 'SELECT LEFT(hbd.`date_add`, 10) AS booking_date, COUNT(hbd.`id`) AS value
                FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                WHERE '.$this->_buildTransactionalDateFilter($dateFrom, $dateTo, 'hbd').'
                  AND hbd.`is_cancelled` = 1'
                  .$where.'
                GROUP BY LEFT(hbd.`date_add`, 10)
                ORDER BY booking_date ASC';

        $rows = Db::getInstance()->executeS($sql);
        return $this->_fillDateRange($dateFrom, $dateTo, $rows, 'booking_date', 'value', 0);
    }

    /**
     * Average length of stay: AVG(DATEDIFF(date_to, date_from)).
     * Returns scalar only — an ALOS trend is not meaningful day-by-day.
     */
    protected function _getRoomAlos($dateFrom, $dateTo, $params)
    {
        $where = $this->_buildHotelFilter($params, 'hbd');
        $sql = 'SELECT AVG(DATEDIFF(hbd.`date_to`, hbd.`date_from`)) AS value
                FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                WHERE '.$this->_buildTransactionalDateFilter($dateFrom, $dateTo, 'hbd').'
                  AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0'
                  .$where;

        return (float)Db::getInstance()->getValue($sql);
    }

    /**
     * Average number of days between booking creation (date_add) and arrival (date_from).
     */
    protected function _getRoomAvgLeadTime($dateFrom, $dateTo, $params)
    {
        $where = $this->_buildHotelFilter($params, 'hbd');
        $sql = 'SELECT AVG(DATEDIFF(hbd.`date_from`, LEFT(hbd.`date_add`, 10))) AS value
                FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                WHERE '.$this->_buildTransactionalDateFilter($dateFrom, $dateTo, 'hbd').'
                  AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0'
                  .$where;

        return (float)Db::getInstance()->getValue($sql);
    }

    /**
     * Average guests (adults + children) per booking.
     */
    protected function _getRoomAvgGuests($dateFrom, $dateTo, $params)
    {
        $where = $this->_buildHotelFilter($params, 'hbd');
        $sql = 'SELECT AVG(hbd.`adults` + hbd.`children`) AS value
                FROM `'._DB_PREFIX_.'htl_booking_detail` hbd
                WHERE '.$this->_buildTransactionalDateFilter($dateFrom, $dateTo, 'hbd').'
                  AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0'
                  .$where;

        return (float)Db::getInstance()->getValue($sql);
    }

    // =========================================================================
    // PRIVATE IMPLEMENTATIONS — SERVICE METRICS
    // =========================================================================

    /**
     * Service revenue (net or gross).
     *
     * Replicates legacy getServicesRevenueForDiscreteDates():
     *   SUM((spod.total_price_tax_excl / o.conversion_rate) / DATEDIFF(hbd.date_to, hbd.date_from))
     *
     * Datewise: OCCUPATIONAL spreading. Revenue per night per service booking =
     *   (spod.total_price_tax_excl / o.conversion_rate) / DATEDIFF.
     *
     * Dynamic JOINs: hbd (for dates, status, hotel filter) + orders (conversion_rate, valid).
     *
     * @param bool $includeTax
     */
    protected function _getServiceRevenue($dateFrom, $dateTo, $params, $datewise, $includeTax)
    {
        // Tax-excl uses the stored total. Tax-incl reconstructs from unit_price * qty.
        $totalExpr = $includeTax
            ? '(spod.`unit_price_tax_incl` * spod.`quantity`)'
            : 'spod.`total_price_tax_excl`';

        $where = $this->_buildHotelFilter($params, 'hbd');

        if (!$datewise) {
            $sql = 'SELECT IFNULL(SUM('.$totalExpr.' / o.`conversion_rate`), 0) AS value
                    FROM `'._DB_PREFIX_.'service_product_order_detail` spod
                    LEFT JOIN `'._DB_PREFIX_.'htl_booking_detail` hbd ON (hbd.`id` = spod.`id_htl_booking_detail`)
                    LEFT JOIN `'._DB_PREFIX_.'orders` o ON (o.`id_order` = hbd.`id_order`)
                    WHERE '.$this->_buildTransactionalDateFilter($dateFrom, $dateTo, 'hbd').'
                      AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0
                      AND o.`valid` = 1'
                      .$where;

            return (float)Db::getInstance()->getValue($sql);
        }

        // Datewise occupational: fetch all service rows for overlapping bookings,
        // compute the per-night value = total / DATEDIFF.
        $sql = 'SELECT hbd.`date_from`, hbd.`date_to`,
                       ('.$totalExpr.' / o.`conversion_rate`) / DATEDIFF(hbd.`date_to`, hbd.`date_from`) AS daily_value
                FROM `'._DB_PREFIX_.'service_product_order_detail` spod
                LEFT JOIN `'._DB_PREFIX_.'htl_booking_detail` hbd ON (hbd.`id` = spod.`id_htl_booking_detail`)
                LEFT JOIN `'._DB_PREFIX_.'orders` o ON (o.`id_order` = hbd.`id_order`)
                WHERE hbd.`date_from` < \''.pSQL($dateTo).' 23:59:59\'
                  AND hbd.`date_to` > \''.pSQL($dateFrom).'\'
                  AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0
                  AND o.`valid` = 1
                  AND DATEDIFF(hbd.`date_to`, hbd.`date_from`) > 0'
                  .$where;

        $bookings = Db::getInstance()->executeS($sql);
        return $this->_spreadOccupationalMetric($dateFrom, $dateTo, $bookings, 'daily_value', null);
    }

    /**
     * Total service items sold: SUM(spod.quantity).
     * Transactional (hbd.date_add). Dynamic JOINs: hbd.
     */
    protected function _getServiceQuantity($dateFrom, $dateTo, $params, $datewise)
    {
        $where = $this->_buildHotelFilter($params, 'hbd');

        if (!$datewise) {
            $sql = 'SELECT SUM(spod.`quantity`) AS value
                    FROM `'._DB_PREFIX_.'service_product_order_detail` spod
                    LEFT JOIN `'._DB_PREFIX_.'htl_booking_detail` hbd ON (hbd.`id` = spod.`id_htl_booking_detail`)
                    WHERE '.$this->_buildTransactionalDateFilter($dateFrom, $dateTo, 'hbd').'
                      AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0'
                      .$where;

            return (int)Db::getInstance()->getValue($sql);
        }

        $sql = 'SELECT LEFT(hbd.`date_add`, 10) AS booking_date, SUM(spod.`quantity`) AS value
                FROM `'._DB_PREFIX_.'service_product_order_detail` spod
                LEFT JOIN `'._DB_PREFIX_.'htl_booking_detail` hbd ON (hbd.`id` = spod.`id_htl_booking_detail`)
                WHERE '.$this->_buildTransactionalDateFilter($dateFrom, $dateTo, 'hbd').'
                  AND hbd.`is_refunded` = 0 AND hbd.`is_cancelled` = 0'
                  .$where.'
                GROUP BY LEFT(hbd.`date_add`, 10)
                ORDER BY booking_date ASC';

        $rows = Db::getInstance()->executeS($sql);
        return $this->_fillDateRange($dateFrom, $dateTo, $rows, 'booking_date', 'value', 0);
    }

    // =========================================================================
    // PRIVATE UTILITY METHODS
    // =========================================================================

    /**
     * Core occupational spreading engine.
     *
     * Accepts a result set where each row has date_from, date_to, and a pre-computed
     * per-night value column. Walks every day in the requested window and sums the
     * value from all bookings active on that day.
     *
     * When $conversionRateKey is provided, the per-night value is $valueKey / $row[$conversionRateKey].
     * When null, the per-night value is taken directly from $valueKey (pre-divided in SQL).
     *
     * @param string      $dateFrom
     * @param string      $dateTo
     * @param array       $bookings         Rows from executeS().
     * @param string      $valueKey         Column name for the per-night metric value.
     * @param string|null $conversionRateKey Column name for the conversion rate, or null.
     * @return array  'Y-m-d' => float
     */
    protected function _spreadOccupationalMetric($dateFrom, $dateTo, $bookings, $valueKey, $conversionRateKey)
    {
        if (empty($bookings)) {
            return $this->_buildZeroArray($dateFrom, $dateTo);
        }

        // Pre-parse booking date boundaries and pre-compute the daily value once,
        // avoiding repeated object creation and division inside the inner loop.
        $parsed = array();
        foreach ($bookings as $b) {
            $rate = ($conversionRateKey !== null && !empty($b[$conversionRateKey]))
                ? (float)$b[$conversionRateKey]
                : 1.0;
            $parsed[] = array(
                'from'  => new DateTime(substr($b['date_from'], 0, 10)),
                'to'    => new DateTime(substr($b['date_to'], 0, 10)),
                'daily' => (float)$b[$valueKey] / $rate,
            );
        }

        $current = new DateTime($dateFrom);
        $end     = new DateTime($dateTo);
        $result  = array();

        while ($current <= $end) {
            $dayStr = $current->format('Y-m-d');
            $sum    = 0.0;
            foreach ($parsed as $b) {
                // Active if: date_from <= current day AND date_to > current day.
                if ($b['from'] <= $current && $b['to'] > $current) {
                    $sum += $b['daily'];
                }
            }
            $result[$dayStr] = $sum;
            $current->modify('+1 day');
        }

        return $result;
    }

    /**
     * Builds an associative array keyed 'Y-m-d' => 0.0 for the full date range.
     * Used when a query returns no rows but we still need a complete keyed array.
     *
     * @param string $dateFrom
     * @param string $dateTo
     * @return array
     */
    protected function _buildZeroArray($dateFrom, $dateTo)
    {
        $current = new DateTime($dateFrom);
        $end     = new DateTime($dateTo);
        $result  = array();
        while ($current <= $end) {
            $result[$current->format('Y-m-d')] = 0.0;
            $current->modify('+1 day');
        }
        return $result;
    }

    /**
     * Fills a complete date range with query results, inserting $defaultValue for
     * days missing from the result set.
     *
     * @param string    $dateFrom
     * @param string    $dateTo
     * @param array     $rows         Rows from executeS().
     * @param string    $dateKey      Column holding the 'Y-m-d' date in each row.
     * @param string    $valueKey     Column holding the numeric value.
     * @param float|int $defaultValue Value to assign for days with no data. Default 0.0.
     * @return array  'Y-m-d' => float|int
     */
    protected function _fillDateRange($dateFrom, $dateTo, $rows, $dateKey, $valueKey, $defaultValue = 0.0)
    {
        $indexed = array();
        foreach ($rows as $row) {
            $indexed[$row[$dateKey]] = $row[$valueKey];
        }

        $current = new DateTime($dateFrom);
        $end     = new DateTime($dateTo);
        $result  = array();

        while ($current <= $end) {
            $dayStr          = $current->format('Y-m-d');
            $result[$dayStr] = isset($indexed[$dayStr]) ? (float)$indexed[$dayStr] : $defaultValue;
            $current->modify('+1 day');
        }

        return $result;
    }

    /**
     * Builds an optional SQL AND fragment to restrict results to one hotel.
     *
     * @param array  $params
     * @param string $alias  Table alias for the id_hotel column.
     * @return string  '' or ' AND `alias`.`id_hotel` = N'
     */
    protected function _buildHotelFilter($params, $alias = 'hbd')
    {
        if (!empty($params['id_hotel'])) {
            return ' AND `'.bqSQL($alias).'`.`id_hotel` = '.(int)$params['id_hotel'];
        }
        return '';
    }

    /**
     * Builds the transactional date WHERE fragment.
     * Filters on LEFT(date_add, 10) so that datetime values compare cleanly
     * against 'Y-m-d' strings without type casting.
     *
     * @param string $dateFrom  Already pSQL-sanitised.
     * @param string $dateTo    Already pSQL-sanitised.
     * @param string $alias
     * @return string
     */
    protected function _buildTransactionalDateFilter($dateFrom, $dateTo, $alias = 'hbd')
    {
        return 'LEFT(`'.bqSQL($alias).'`.`date_add`, 10) BETWEEN \''.$dateFrom.'\' AND \''.$dateTo.'\'';
    }
}
