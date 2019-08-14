<?php

class Picqer_PostNL_Model_Sales_Order_Api extends Mage_Sales_Model_Order_Api
{

    /**
     * API call returns PostNL shipping info generated by TIG_PostNL
     *
     * @param $orderIncrementId
     *
     * @return array
     */
    public function picqerPostNL($orderIncrementId)
    {
        $helper = Mage::helper('picqer_postnl');
        if (! $helper->isExtensionActive()) {
            return [];
        }

        if (! $helper->isTIGPostNlExtensionInstalled()) {
            return [];
        }

        // Get the order and the PakjeGemakAddress (if set)
        $order = $this->_initOrder($orderIncrementId);
        $postNLHelper = Mage::helper('postnl');

        try {
            $pakjeGemakAddress = $postNLHelper->getPakjeGemakAddressForOrder($order);
            $tigPostNlOrder = Mage::getModel('postnl_core/order')->loadByOrder($order);

            // Get the store time zone and change times to match the correct time zone
            $storeTimezone = Mage::getStoreConfig(Mage_Core_Model_Locale::XML_PATH_DEFAULT_TIMEZONE, $tigPostNlOrder->getStoreId());
            $storeTimezone = new DateTimeZone($storeTimezone);

            if ($tigPostNlOrder->hasExpectedDeliveryTimeEnd()) {
                $expectedDeliveryTimeEnd = $this->toCorrectTimeZone($tigPostNlOrder->getExpectedDeliveryTimeEnd(), $storeTimezone)->format('H:i');
            } else {
                $expectedDeliveryTimeEnd = null;
            }

            // Add TIG_PostNL information to API
            $result = [
                'confirmDate'               => $this->toCorrectTimeZone($tigPostNlOrder->getConfirmDate(), $storeTimezone)->format('Y-m-d H:i:s'),
                'isActive'                  => $tigPostNlOrder->getIsActive(),
                'shipmentCosts'             => $tigPostNlOrder->getShipmentCosts(),
                'productCode'               => $tigPostNlOrder->getProductCode(),
                'isPakjeGemak'              => $tigPostNlOrder->getIsPakjeGemak(),
                'isCanceled'                => $tigPostNlOrder->getIsCanceled(),
                'deliveryDate'              => $this->toCorrectTimeZone($tigPostNlOrder->getDeliveryDate(), $storeTimezone)->format('Y-m-d H:i:s'),
                'type'                      => $tigPostNlOrder->getType(),
                'mobilePhoneNumber'         => $tigPostNlOrder->getMobilePhoneNumber(),
                'isPakketautomaat'          => $tigPostNlOrder->getIsPakketautomaat(),
                'options'                   => $tigPostNlOrder->getUnserializedOptions(),
                'expectedDeliveryTimeStart' => $this->toCorrectTimeZone($tigPostNlOrder->getExpectedDeliveryTimeStart(), $storeTimezone)->format('H:i'),
                'expectedDeliveryTimeEnd'   => $expectedDeliveryTimeEnd,
                'pakjeGemakAddress'         => empty($pakjeGemakAddress) ? null : $this->_getAttributes($pakjeGemakAddress),
                'isBrievenbuspakje'         => $this->useBuspakje($order, $tigPostNlOrder),
            ];

            return $result;
        } catch (\Exception $e) {
            return [];
        }
    }


    /**
     * Change the time zone of a date and time
     *
     * @param              $value
     * @param DateTimeZone $dateTimeZone
     *
     * @return DateTime
     */
    private function toCorrectTimeZone($value, DateTimeZone $dateTimeZone)
    {
        $dateTime = new DateTime($value);
        $dateTime->setTimezone($dateTimeZone);

        return $dateTime;
    }

    /**
     * Determine if this is a cash on delivery order
     * @param $order
     * @return bool
     */
    private function isCod($order)
    {
        $codPaymentMethods = Mage::helper('postnl/payment')->getCodPaymentMethods();
        $paymentMethod = $order->getPayment()->getMethod();
        if (in_array($paymentMethod, $codPaymentMethods)) {
            return true;
        }

        return false;
    }

    /**
     * Are we allowed to use Buspakje by preferences from Magento
     * @return mixed
     */
    private function canUseBuspakje()
    {
        return Mage::helper('postnl')->canUseBuspakje();
    }

    /**
     * Does the order fit as a Buspakje
     * @param $order
     * @return mixed
     */
    private function fitsAsBuspakje($order)
    {
        $orderItems = $order->getItemsCollection();
        return Mage::helper('postnl')->fitsAsBuspakje($orderItems, true);
    }

    /**
     * Is this a Dutch shipment
     * @param $order
     * @return bool
     */
    private function isDutchShipment($order)
    {
        return $order->getShippingAddress()->getCountry() == 'NL';
    }


    /**
     * Calculate if this should be a buspakje shipment
     * @param $order
     * @param $tigOrder
     * @return bool
     */
    private function useBuspakje($order, $tigOrder)
    {
        return $this->isDutchShipment($order)
            && ! $this->isCod($order)
            && ! $tigOrder->getIsPakjeGemak()
            && ! $tigOrder->getIsPakketautomaat()
            && $this->fitsAsBuspakje($order)
            && $this->canUseBuspakje();
    }
}