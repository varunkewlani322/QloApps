<?php
/**
* NOTICE OF LICENSE
*
* This source file is subject to the Open Software License version 3.0
* that is bundled with this package in the file LICENSE.md
* It is also available through the world-wide-web at this URL:
* https://opensource.org/license/osl-3-0-php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to support@qloapps.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade this module to a newer
* versions in the future. If you wish to customize this module for your needs
* please refer to https://store.webkul.com/customisation-guidelines for more information.
*
* @author Webkul IN
* @copyright Since 2010 Webkul
* @license https://opensource.org/license/osl-3-0-php Open Software License version 3.0
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

class DashPerformance extends Module
{
    public function __construct()
    {
        $this->name = 'dashperformance';
        $this->tab = 'dashboard';
        $this->version = '1.0.3';
        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => '1.6');
        $this->author = 'Webkul';
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('Dashboard Performance');
        $this->description = $this->l('Adds a block with a graphical representation of performance of your website.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        $this->allow_push = true;
    }

    public function install()
    {
        return (parent::install()
            && $this->registerHook('dashboardZoneTwo')
            && $this->registerHook('dashboardData')
            && $this->registerHook('actionAdminControllerSetMedia')
        );
    }

    public function hookActionAdminControllerSetMedia()
    {
        if (get_class($this->context->controller) == 'AdminDashboardController') {
            $this->context->controller->addCSS($this->_path.'views/css/'.$this->name.'.css');
        }
    }

    public function hookDashboardZoneTwo($params)
    {
        return $this->display(__FILE__, 'dashboard_zone_two.tpl');
    }

    public function hookDashboardData($params)
    {
        $data = array();
        if (Configuration::get('PS_DASHBOARD_SIMULATION')) {
            // ddd(Configuration::get('PS_DASHBOARD_SIMULATION'));
            // $objHotelAnalytics = new HotelAnalytics();
            // ddd($data['dp_direct_revenue_ratio'] = $objHotelAnalytics->getRoomAnalytics('total_sales', $params['date_from'],$params['date_to'],$params['id_hotel']));

            $data['dp_average_daily_rate'] = Tools::displayPrice(sprintf('%0.2f', rand(100000, 1000000) / 100));
            $data['dp_total_revenue_per_available_room'] = Tools::displayPrice(sprintf('%0.2f', rand(pow(10, 5), pow(10, 6)) / 100));
            $data['dp_average_occupancy_rate'] = sprintf('%0.2f', rand(5000, 10000) / 100).'%';
            $data['dp_revenue_per_available_room'] = Tools::displayPrice(sprintf('%0.2f', rand(pow(10, 5), pow(10, 6)) / 100));
            $data['dp_gross_operating_profit_par'] = Tools::displayPrice(sprintf('%0.2f', rand(pow(10, 6), pow(10, 7)) / 100));
            $data['dp_average_length_of_stay'] = sprintf('%0.2f', rand(1, 500) / 100);
            $data['dp_direct_revenue_ratio'] = sprintf('%0.2f', rand(4000, 8000) / 100).'%';
            $data['dp_cancellation_rate'] = sprintf('%0.2f', rand(1, 1000) / 100).'%';
        } else {
            // ddd(23);
            $data['dp_average_daily_rate'] = Tools::displayPrice(AdminStatsController::getAverageDailyRate(
                $params['date_from'],
                $params['date_to'],
                $params['id_hotel']
            ));

            $data['dp_direct_revenue_ratio'] = sprintf('%0.2f', AdminStatsController::getDirectRevenueRatio(
                $params['date_from'],
                $params['date_to'],
                $params['id_hotel']
            )).'%';

            // $objHotelAnalytics = new HotelAnalytics();
            // ($roomRev = $objHotelAnalytics->getRoomAnalytics('total_revenue', $params['date_from'],$params['date_to'],$params['id_hotel']));
            // ($serviceRev = $objHotelAnalytics->getServiceAnalytics('service_total_revenue', $params['date_from'],$params['date_to'],$params['id_hotel']));
            // $totalRevenue = (float)$roomRev + (float)$serviceRev;
            // $totalPhysicalRooms = AdminStatsController::getTotalRooms($params['id_hotel'], true); 
    
            // // Calculate the number of days in the period
            // $days = (strtotime($params['date_to']) - strtotime($params['date_from'])) / (60 * 60 * 24);
            // $days = $days == 0 ? 1 : $days; // Prevent division by zero for same-day queries
            
            // $totalAvailableRoomNights = $totalPhysicalRooms * $days;

            // // 3. Calculate TRevPAR
            // // ddd($days);
            // if ($totalAvailableRoomNights > 0) {
            //     ddd($totalRevenue / $totalAvailableRoomNights);
            // }
            $analytics = new HotelAnalyticsCore();
            ddd($revenueByDate = $analytics->getRoomAnalytics(
                HotelAnalyticsCore::METRIC_TOTAL_REVENUE,
                $params['date_from'],
                $params['date_to'],
                array('datewise_breakdown' => false, 'id_hotel' => $params['idHotel'])
            ));



            $data['dp_average_occupancy_rate'] = sprintf('%0.2f', AdminStatsController::getAverageOccupancyRate(
                $params['date_from'],
                $params['date_to'],
                $params['id_hotel']
            )).'%';

            $data['dp_cancellation_rate'] = sprintf('%0.2f', AdminStatsController::getCancellationRate(
                $params['date_from'],
                $params['date_to'],
                $params['id_hotel']
            )).'%';

            $data['dp_revenue_per_available_room'] = Tools::displayPrice(AdminStatsController::getRevenuePerAvailableRoom(
                $params['date_from'],
                $params['date_to'],
                $params['id_hotel']
            ));

            $data['dp_total_revenue_per_available_room'] = Tools::displayPrice(AdminStatsController::getTotalRevenuePerAvailableRoom(
                $params['date_from'],
                $params['date_to'],
                $params['id_hotel']
            ));
            // ddd($data['dp_total_revenue_per_available_room']);

            $data['dp_gross_operating_profit_par'] = Tools::displayPrice(AdminStatsController::getGrossOperatingProfitPerAvailableRoom(
                $params['date_from'],
                $params['date_to'],
                $params['id_hotel']
            ));

            $data['dp_average_length_of_stay'] = sprintf('%0.2f', AdminStatsController::getAverageLengthOfStay(
                $params['date_from'],
                $params['date_to'],
                $params['id_hotel']
            ));
        }

        return array('data_value' => $data);
    }
}
