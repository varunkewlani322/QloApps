<?php
/**
 * 2007-2017 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2017 PrestaShop SA
 *  @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

/**
 * @since 1.5
 */
class HTMLTemplateRegistrationFormCore extends HTMLTemplate
{
    public $order;
    public $available_in_your_account = false;

    /**
     * @param Order $objOrder
     * @param $smarty
     */
    public function __construct(Order $objOrder, $smarty)
    {
        $this->order = $objOrder;
        $this->smarty = $smarty;
        $this->date = Tools::displayDate($objOrder->date_add);
        $this->title = $objOrder->getUniqReference();
        $this->shop = new Shop((int)$this->order->id_shop);
    }

    /**
     * Returns the template's HTML header
     *
     * @return string HTML header
     */
    public function getHeader()
    {
        $this->assignCommonHeaderData();
        $this->smarty->assign(array('header' => self::l('Registration Form')));

        return $this->smarty->fetch($this->getTemplate('header'));
    }

    /**
     * Returns the template's HTML content
     *
     * @return string HTML content
     */
    public function getContent()
    {
        $objCustomer = new Customer((int)$this->order->id_customer);
        $objHotelBookingDetail = new HotelBookingDetail();
        $hotelBookingDetails = $objHotelBookingDetail->getBookingDataByOrderId($this->order->id);

        $data = array(
            'order' => $this->order,
            'registration_form' => array(
                'guest' => $this->getGuestData($objCustomer),
                'hotel' => $this->getHotelData($hotelBookingDetails),
                'stay' => $this->getStayData($hotelBookingDetails),
            ),
        );

        $this->smarty->assign($data);
        $this->smarty->assign(array(
            'style_tab' => $this->smarty->fetch($this->getTemplate('invoice.style-tab')),
        ));

        return $this->smarty->fetch($this->getTemplate('registration-form'));
    }

    /**
     * Returns the template filename when using bulk rendering
     *
     * @return string filename
     */
    public function getBulkFilename()
    {
        return 'registration-forms.pdf';
    }

    /**
     * Returns the template filename
     *
     * @return string filename
     */
    public function getFilename()
    {
        return 'registration-form-'.$this->order->reference.'.pdf';
    }

    /**
     * @param Customer $objCustomer
     *
     * @return array
     */
    protected function getGuestData(Customer $objCustomer)
    {
        $guestFullName = trim($objCustomer->firstname.' '.$objCustomer->lastname);
        $guestEmail = $objCustomer->email;
        $guestPhone = '';
        $guestAddress = '';

        if ($this->order->id_address_invoice
            && Validate::isLoadedObject($objGuestAddress = new Address((int)$this->order->id_address_invoice))
        ) {
            if ($objGuestAddress->firstname || $objGuestAddress->lastname) {
                $guestFullName = trim($objGuestAddress->firstname.' '.$objGuestAddress->lastname);
            }

            if ($objGuestAddress->phone_mobile) {
                $guestPhone = $objGuestAddress->phone_mobile;
            } elseif ($objGuestAddress->phone) {
                $guestPhone = $objGuestAddress->phone;
            }

            $guestAddress = AddressFormat::generateAddress($objGuestAddress, array(), '<br />', ' ');
        }

        if ($id_order_customer_guest_detail = OrderCustomerGuestDetail::isCustomerGuestBooking($this->order->id)) {
            if (Validate::isLoadedObject(
                $objOrderCustomerGuestDetail = new OrderCustomerGuestDetail((int)$id_order_customer_guest_detail)
            )) {
                $guestFullName = trim($objOrderCustomerGuestDetail->firstname.' '.$objOrderCustomerGuestDetail->lastname);
                $guestEmail = $objOrderCustomerGuestDetail->email;
                $guestPhone = $objOrderCustomerGuestDetail->phone;
            }
        }

        return array(
            'full_name' => $guestFullName,
            'email' => $guestEmail,
            'mobile' => $guestPhone,
            'address' => $guestAddress,
        );
    }

    /**
     * @param array $hotelBookingDetails
     *
     * @return array
     */
    protected function getHotelData($hotelBookingDetails)
    {
        $hotelName = '';
        $hotelAddress = '';
        $hotelEmail = '';
        $hotelPhone = '';
        $checkInTime = '';
        $checkOutTime = '';

        if (!empty($hotelBookingDetails)) {
            $hotelBookingDetail = reset($hotelBookingDetails);
            $hotelName = $hotelBookingDetail['hotel_name'];
            $hotelEmail = $hotelBookingDetail['email'];
            $hotelPhone = $hotelBookingDetail['phone'];
            $checkInTime = $this->formatHotelTime($hotelBookingDetail['check_in_time']);
            $checkOutTime = $this->formatHotelTime($hotelBookingDetail['check_out_time']);
        }

        if ($idHotel = HotelBookingDetail::getIdHotelByIdOrder($this->order->id)) {
            $objHotelBranchInformation = new HotelBranchInformation((int)$idHotel, (int)$this->order->id_lang);
            if (Validate::isLoadedObject($objHotelBranchInformation)) {
                $hotelName = $objHotelBranchInformation->hotel_name;
                $hotelEmail = $objHotelBranchInformation->email;
                $hotelPhone = $objHotelBranchInformation->phone;
                $checkInTime = $this->formatHotelTime($objHotelBranchInformation->check_in);
                $checkOutTime = $this->formatHotelTime($objHotelBranchInformation->check_out);

                if ($idHotelAddress = $objHotelBranchInformation->getHotelIdAddress()) {
                    if (Validate::isLoadedObject($objHotelAddress = new Address((int)$idHotelAddress))) {
                        $objHotelAddress->firstname = $hotelName;
                        $objHotelAddress->lastname = '';
                        $hotelAddress = AddressFormat::generateAddress($objHotelAddress, array(), '<br />', ' ');
                    }
                }
            }
        }

        if (!$hotelAddress && !empty($hotelBookingDetails)) {
            $hotelBookingDetail = reset($hotelBookingDetails);
            $hotelAddressParts = array(
                $hotelBookingDetail['hotel_name'],
                $hotelBookingDetail['city'],
                $hotelBookingDetail['state'],
                $hotelBookingDetail['country'],
                $hotelBookingDetail['zipcode'],
            );
            $hotelAddress = implode(', ', array_filter($hotelAddressParts));
        }

        return array(
            'name' => $hotelName,
            'address' => $hotelAddress,
            'email' => $hotelEmail,
            'phone' => $hotelPhone,
            'check_in_time' => $checkInTime,
            'check_out_time' => $checkOutTime,
        );
    }

    /**
     * @param array $hotelBookingDetails
     *
     * @return array
     */
    protected function getStayData($hotelBookingDetails)
    {
        $arrivalDate = '';
        $departureDate = '';
        $roomNumbers = array();
        $roomTypes = array();

        if (!empty($hotelBookingDetails)) {
            foreach ($hotelBookingDetails as $hotelBookingDetail) {
                if (!$arrivalDate || strtotime($hotelBookingDetail['date_from']) < strtotime($arrivalDate)) {
                    $arrivalDate = $hotelBookingDetail['date_from'];
                }

                if (!$departureDate || strtotime($hotelBookingDetail['date_to']) > strtotime($departureDate)) {
                    $departureDate = $hotelBookingDetail['date_to'];
                }

                if (!empty($hotelBookingDetail['room_num'])) {
                    $roomNumbers[] = $hotelBookingDetail['room_num'];
                }

                if (!empty($hotelBookingDetail['room_type_name'])) {
                    $roomTypes[] = $hotelBookingDetail['room_type_name'];
                }
            }
        }

        return array(
            'arrival_date' => $arrivalDate ? Tools::displayDate($arrivalDate) : '',
            'departure_date' => $departureDate ? Tools::displayDate($departureDate) : '',
            'room_number' => implode(', ', array_unique($roomNumbers)),
            'room_type' => implode(', ', array_unique($roomTypes)),
        );
    }

    /**
     * @param string $hotelTime
     *
     * @return string
     */
    protected function formatHotelTime($hotelTime)
    {
        if (!$hotelTime || $hotelTime == '00:00:00') {
            return '';
        }

        return date('h:i a', strtotime($hotelTime));
    }
}
