<?php

namespace OpenRoam\Test;

use OpenRoam\Model\OfferFixedPrice;
use OpenRoam\Model\OfferFixedValue;
use OpenRoam\Model\OfferRangePrice;
use OpenRoam\Model\OfferRangeValue;
use OpenRoam\Model\OrderFulfillmentUpdatedWebhookItemEsim;
use OpenRoam\Model\PartnerOrderItemEsim;
use OpenRoam\ObjectSerializer;
use PHPUnit\Framework\TestCase;

// Offers carry price and value unions; order and webhook items carry a union chosen by productType. Each must be read as its
// own variant, with the data intact.
class UnionsTest extends TestCase
{
    private function read(string $json, string $class)
    {
        return ObjectSerializer::deserialize(json_decode($json), $class);
    }

    public function testAFixedPriceAndValueAreReadAsTheFixedVariants(): void
    {
        $offer = $this->read('{"publicId":"airtime_abc","name":"MTN 1000 NGN","value":{"type":"fixed","amount":1000,"currency":"NGN"},"price":{"amount":0.68,"currency":"USD"}}', '\OpenRoam\Model\AirtimeOffer');
        $this->assertInstanceOf(OfferFixedValue::class, $offer->getValue());
        $this->assertInstanceOf(OfferFixedPrice::class, $offer->getPrice());
        $this->assertEquals(0.68, $offer->getPrice()->getAmount());
    }

    public function testARangePriceAndValueAreReadAsTheRangeVariants(): void
    {
        $offer = $this->read('{"publicId":"airtime_abd","name":"MTN range","value":{"type":"range","min":50,"max":50000,"step":1,"currency":"NGN"},"price":{"min":0.04,"max":33.5,"currency":"USD"}}', '\OpenRoam\Model\AirtimeOffer');
        $this->assertInstanceOf(OfferRangeValue::class, $offer->getValue());
        $this->assertInstanceOf(OfferRangePrice::class, $offer->getPrice());
    }

    public function testAFlightFareOfferPriceIsReadToo(): void
    {
        $offer = $this->read('{"publicId":"flight_abc","name":"Economy","price":{"amount":120.5,"currency":"USD"}}', '\OpenRoam\Model\FlightFareOfferApi');
        $this->assertInstanceOf(OfferFixedPrice::class, $offer->getPrice());
    }

    public function testAnOrderItemIsReadAsItsProductVariant(): void
    {
        $item = $this->read('{"id":"11111111-1111-1111-1111-111111111111","productType":"esim"}', '\OpenRoam\Model\PartnerOrderItem');
        $this->assertInstanceOf(PartnerOrderItemEsim::class, $item);
    }

    public function testAnEsimWebhookItemKeepsItsDeliveryDetails(): void
    {
        $item = $this->read('{"id":"11111111-1111-1111-1111-111111111111","productType":"esim","fulfillment":{"status":"fulfilled","result":{"details":{"iccid":"8900000000000000001","mode":"instant"}}}}', '\OpenRoam\Model\OrderFulfillmentUpdatedWebhookItem');
        $this->assertInstanceOf(OrderFulfillmentUpdatedWebhookItemEsim::class, $item);
        $this->assertSame('8900000000000000001', $item->getFulfillment()->getResult()->getDetails()->getIccid());
    }
}
