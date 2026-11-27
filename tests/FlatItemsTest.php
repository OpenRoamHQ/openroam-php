<?php

namespace OpenRoam\Test;

use PHPUnit\Framework\TestCase;
use OpenRoam\ObjectSerializer;

// A product's own fields sit on the item and on the quote item, beside the common keys. Offers and fare options keep
// `details`. The payloads are the examples of the API description, shortened to one entry per list.
class FlatItemsTest extends TestCase
{
    private const PAYLOADS = [
        'airtimeSearch' => <<<'JSON'
{"items":[{"id":"operator_tq7vbm2xk4hdn6wzr3pyjc5fae","name":"Bakcell Azerbaijan","countryCode":"AZ","category":null,
"logoUrl":"https://assets.openroam.io/services/image.png","description":null,
"offers":[{"publicId":"airtime_raanuxyz6cxzie3w3my6c3r2wm","name":"Bakcell Azerbaijan 40 AZN",
"description":"Top-up for **Bakcell Azerbaijan** numbers.","value":{"type":"fixed","amount":40,"currency":"AZN"},
"price":{"amount":25.0,"currency":"USD"},"taxes":{"treatment":"notStated","amount":null,"lines":[]},
"requiredInputs":[{"name":"phoneNumber","type":"phone","required":true,
"description":"The recipient's mobile number in international format, starting with + or 00.",
"labelKey":"inputs.phoneNumber"}],"details":{"redemptionType":"directTopUp",
"readMore":"Credit arrives within minutes."}}]}],"pagination":{"currentCount":1,"totalCount":1,"skip":0,"limit":10,
"hasMore":false,"page":1,"totalPages":1},"phoneLookup":{"status":"detected","carrierName":"Bakcell LLC",
"countryCode":"AZ"}}
JSON,
        'airtimeQuote' => <<<'JSON'
{"currency":"USD","items":[{"publicId":"airtime_raanuxyz6cxzie3w3my6c3r2wm","quantity":1,
"name":"Bakcell Azerbaijan 40 AZN","value":{"amount":40,"currency":"AZN"},"price":{"amount":25.0,"currency":"USD"},
"taxes":{"treatment":"notStated","amount":null,"lines":[]},"estimatedDeliveredValue":{"amount":40,"currency":"AZN"}}],
"total":{"amount":25.0,"currency":"USD"}}
JSON,
        'esimSearch' => <<<'JSON'
{"items":[{"id":"destn_k3m7q2t5w4y6b2d4f6h3j5n7pq","name":"Europe","countryCode":null,"category":"regional",
"logoUrl":"https://cdn.openroam.test/esims/europe.png","description":null,
"offers":[{"publicId":"esim_k7m2q4t6v3x5z2b4d6f3h5jn2p","name":"Europe 3 GB 7 days","description":null,
"price":{"amount":25.0,"currency":"USD"},"taxes":{"treatment":"notStated","amount":null,"lines":[]},
"requiredInputs":[{"name":"isInstant","type":"boolean","required":false,
"description":"Defaults to true, which creates the eSIM when the order is fulfilled. False delivers an entitlement, and the eSIM is created later through provisioning.",
"labelKey":"inputs.isInstant"}],"details":{"validityDays":7,"data":{"amount":3072,"unit":"MB","isUnlimited":false,
"label":"3 GB"},"voice":null,"sms":null,"apn":{"apn":"internet","apnUsername":null,"apnPassword":null},
"properties":{"withKyc":false,"withTopUp":true,"withHotspot":true,"withDataRoaming":true,"withSubscription":false,
"withDestinationInstall":false,"withUsageCheck":true,"technology":"eSIM","activation":"first-use",
"charge":"one-time"},"restrictions":{"throttle":false,"throttleSpeed":null,"throttleUnit":null,
"throttleThreshold":null,"throttleThresholdUnit":null,"expireInDays":30},
"notes":["Data only. Calls and SMS are not included."],"plan":null}}],"destinationCode":"EU","region":"Europe",
"coverage":[{"countryCode":"DE","name":"Germany","networks":["4G"],"carriers":["Telekom"]}]}],
"pagination":{"currentCount":1,"totalCount":1,"skip":0,"limit":10,"hasMore":false,"page":1,"totalPages":1}}
JSON,
        'giftCardSearch' => <<<'JSON'
{"items":[{"id":"card_tzkc6b2xcqznidhkouwh2rz7wi","name":"Amazon","countryCode":"US","category":"shopping",
"logoUrl":"https://assets.openroam.io/services/image.png","description":"Redeem at amazon.com",
"offers":[{"publicId":"giftcard_p5udmgvqke2bcrwlk35drsqfqm","name":"Amazon 50 USD","description":null,
"value":{"type":"fixed","amount":50,"currency":"USD"},"price":{"amount":48.98,"currency":"USD"},
"taxes":{"treatment":"notStated","amount":null,"lines":[]},"requiredInputs":[{"name":"recipientEmail","type":"email",
"required":false,"description":"Sends the card to this email address as a gift.","labelKey":"inputs.recipientEmail",
"maxLength":254}]}],"isRedeemableGlobally":false,
"redemptionInstructions":"Go to amazon.com/redeem and enter the code."}],"pagination":{"currentCount":1,
"totalCount":1,"skip":0,"limit":10,"hasMore":false,"page":1,"totalPages":1}}
JSON,
        'utilitySearch' => <<<'JSON'
{"items":[{"id":"biller_3jx464dkw4ekdjgj4qxuib5wki","name":"Azeriqaz","countryCode":"AZ","category":"gas",
"logoUrl":null,"description":null,"offers":[{"publicId":"utility_7tq2mw5kxr3nbv6hzc4dpe2ysa","name":"Azeriqaz 40 AZN",
"description":null,"value":{"type":"fixed","amount":40,"currency":"AZN"},"price":{"amount":27.5,"currency":"USD"},
"taxes":{"treatment":"notStated","amount":null,"lines":[]},"requiredInputs":[{"name":"accountNumber","type":"text",
"required":true,"description":"The customer's account or meter number with the biller.",
"labelKey":"inputs.accountNumber","maxLength":128}]}],"serviceType":"postpaid","deliveryType":"directCredit"}],
"pagination":{"currentCount":1,"totalCount":1,"skip":0,"limit":10,"hasMore":false,"page":1,"totalPages":1}}
JSON,
        'utilityQuote' => <<<'JSON'
{"currency":"USD","items":[{"publicId":"utility_7tq2mw5kxr3nbv6hzc4dpe2ysa","quantity":1,"name":"Azeriqaz 40 AZN",
"value":{"amount":40,"currency":"AZN"},"price":{"amount":27.5,"currency":"USD"},"taxes":{"treatment":"notStated",
"amount":null,"lines":[]},"deliveryType":"directCredit"}],"total":{"amount":27.5,"currency":"USD"}}
JSON,
        'hotelSearch' => <<<'JSON'
{"items":[{"id":"hotel_q7m2k4t6v3x5z2b4d6f3h5jn2p","name":"Hotel Arts Barcelona","countryCode":"ES","category":null,
"logoUrl":"https://assets.openroam.io/services/image.png",
"description":"<p>A seafront tower in the Olympic Port.</p>","offers":[{"publicId":"rate_x5z2b4d6f3h5jn2pq7m2k4t6v3",
"name":"Deluxe King, Breakfast, Free cancellation","description":null,"price":{"amount":642.1,"currency":"USD"},
"taxes":{"treatment":"included","amount":{"amount":41.2,"currency":"USD"},"lines":[{"code":null,"codeSystem":null,
"name":"Tax or fee","kind":"unknown","payableAt":"prepaid","per":null,"jurisdiction":null,"amount":{"amount":38.0,
"currency":"EUR"},"approxAmount":{"amount":41.2,"currency":"USD"}}]},"details":{"expiresAt":"2026-12-01T10:30:00Z",
"rooms":[{"roomGroupKey":"4c8e3ae1d379f759bc372243","name":"Deluxe King","description":null,"beds":[],
"maxOccupancy":3,"sizeSquareMeters":null,"amenities":[],"imageUrls":[],"roomIndex":1,"adultsCount":2,
"childrenCount":1,"childrenAges":[7]}],"board":{"code":"BB","name":"Bed and breakfast","boardType":"breakfast"},
"terms":{"payment":{"type":"payNow","dueNow":{"amount":642.1,"currency":"USD"},"dueLater":null,"dueLaterAt":null},
"cancellation":{"type":"fullyRefundable","freeUntil":"2026-12-08T23:59:00Z",
"freeUntilLocalTime":"2026-12-09T00:59:00","timezone":"Europe/Madrid","schedule":[{"from":"2026-12-08T23:59:00Z",
"fromLocalTime":"2026-12-09T00:59:00","refund":{"amount":428.07,"currency":"USD"},"penalty":{"amount":214.03,
"currency":"USD"}}],"fee":null},"changes":{"allowed":true,"fee":null}},"priceIsBinding":true,
"nightlyPrices":[{"date":"2026-12-10","amount":214.03,"currency":"USD"}],"surcharges":[],"rateTags":[],"remarks":[],
"withTransfers":false,"rateRules":[],"amendabilityStatus":"yes","guestNameRules":{"minLength":1,"maxLength":50,
"pattern":"^(?=.*[a-zA-Z])[a-zA-Z\\s\\-'\\.]+$",
"allowedCharacters":"letters, spaces, hyphens, apostrophes and periods","distinctFullNames":false,
"guestNamesOptional":false},"minimumSellingPrice":null}}],"starRating":5,
"address":{"line1":"Carrer de la Marina 19-21","line2":null,"city":"Barcelona","stateProvince":null,
"postalCode":"08005","countryCode":"ES","countryName":null},"coordinates":{"latitude":41.386,"longitude":2.196},
"imageUrls":["https://assets.openroam.io/services/image.png"],"amenityGroups":[{"key":"PropertyAmenities",
"codes":["FreeWifi"]}],"contact":null,"displayGroup":null}],"pagination":{"currentCount":1,"limit":1,"hasMore":false},
"nextCursor":null,"availableFilters":{"amenities":[{"code":"FreeWifi","label":"Free Wi-Fi","count":1}],
"amenityGroups":[{"key":"PropertyAmenities","label":"Property amenities","codes":["FreeWifi"]}]}}
JSON,
        'catalogHotel' => <<<'JSON'
{"id":"hotel_q7m2k4t6v3x5z2b4d6f3h5jn2p","name":"Hotel Arts Barcelona","countryCode":"ES","category":null,
"logoUrl":"https://assets.openroam.io/services/image.png",
"description":"<p>A seafront tower in the Olympic Port.</p>","starRating":5,
"address":{"line1":"Carrer de la Marina 19-21","line2":null,"city":"Barcelona","stateProvince":null,
"postalCode":"08005","countryCode":"ES","countryName":null},"coordinates":{"latitude":41.386,"longitude":2.196},
"imageUrls":["https://assets.openroam.io/services/image.png"],"amenityGroups":[{"key":"PropertyAmenities",
"codes":["FreeWifi"]}],"contact":null,"displayGroup":null}
JSON,
        'hotelQuote' => <<<'JSON'
{"currency":"USD","items":[{"publicId":"rate_b4d6f3h5jn2pq7m2k4t6v3x5z2","quantity":1,
"name":"Hotel Arts Barcelona \u00b7 Deluxe King, Breakfast, Free cancellation","price":{"amount":648.9,
"currency":"USD"},"taxes":{"treatment":"included","amount":{"amount":41.64,"currency":"USD"},"lines":[{"code":null,
"codeSystem":null,"name":"Tax or fee","kind":"unknown","payableAt":"prepaid","per":null,"jurisdiction":null,
"amount":{"amount":38.0,"currency":"EUR"},"approxAmount":{"amount":41.64,"currency":"USD"}}]},
"expiresAt":"2026-12-01T10:42:00Z","availability":"confirmed","checkInDate":"2026-12-10","checkOutDate":"2026-12-13",
"requestedRooms":[{"adults":2,"children":1,"childrenAges":[7]}],"terms":{"payment":{"type":"payNow",
"dueNow":{"amount":648.9,"currency":"USD"},"dueLater":null,"dueLaterAt":null},
"cancellation":{"type":"fullyRefundable","freeUntil":"2026-12-08T23:59:00Z",
"freeUntilLocalTime":"2026-12-09T00:59:00","timezone":"Europe/Madrid","schedule":[{"from":"2026-12-08T23:59:00Z",
"fromLocalTime":"2026-12-09T00:59:00","refund":{"amount":432.6,"currency":"USD"},"penalty":{"amount":216.3,
"currency":"USD"}}],"fee":null},"changes":{"allowed":true,"fee":null}},
"rate":{"rooms":[{"roomGroupKey":"4c8e3ae1d379f759bc372243","name":"Deluxe King","description":null,"beds":[],
"maxOccupancy":3,"sizeSquareMeters":null,"amenities":[],"imageUrls":[],"roomIndex":1,"adultsCount":2,
"childrenCount":1,"childrenAges":[7]}],"board":{"code":"BB","name":"Bed and breakfast","boardType":"breakfast"},
"priceIsBinding":true,"nightlyPrices":[{"date":"2026-12-10","amount":216.3,"currency":"USD"}],"rateTags":[],
"remarks":[],"rateConditions":[],"withTransfers":false,"rateRules":[],"amendabilityStatus":"yes","surcharges":[],
"minimumSellingPrice":null}}],"total":{"amount":648.9,"currency":"USD"}}
JSON,
        'flightSearch' => <<<'JSON'
{"items":[{"id":"itin_dd6d0153af8985bfc97cc5707e58af90",
"name":"LHR \u21c4 JFK \u00b7 12\u201319 Dec \u00b7 British Airways","countryCode":"US","category":null,
"logoUrl":"https://assets.openroam.io/services/image.png","description":null,
"offers":[{"publicId":"flight_dwqbtkmavshyh6pll7ixbouz2o","name":"Economy Basic, Non-refundable","description":null,
"price":{"amount":1184.2,"currency":"USD"},"taxes":{"treatment":"included","amount":{"amount":204.2,"currency":"USD"},
"lines":[]},"details":{"expiresAt":"2026-12-01T10:30:00Z","cabinClass":"economy","fare":{"fareBasisCode":"OXZ0RO",
"brandName":"Basic","fareFamily":"Economy Basic","validatingCarrier":"BA"},"terms":{"payment":{"type":"payNow",
"dueNow":{"amount":1184.2,"currency":"USD"},"dueLater":null,"dueLaterAt":null},"cancellation":{"type":"nonRefundable",
"freeUntil":null,"freeUntilLocalTime":null,"timezone":"Europe/London","schedule":[],"fee":null},
"changes":{"allowed":true,"fee":null}},"includedBaggage":[{"type":"carryOn","quantity":1,"weightKg":null,
"description":null}],"passengerPricing":[{"passengerType":"adult","count":1,"pricePerPassenger":null,
"fareBasisCode":"OXZ0RO","bookingClass":"O"}],"offerPassengers":[{"passengerIndex":1,"type":"adult","age":null,
"fareType":null,"includedBaggage":[{"type":"carryOn","quantity":1,"weightKg":null,"description":null}],
"availableServices":[]}],"availableServices":[],"identityDocumentsRequired":true,
"supportedDocumentTypes":["passport"],"segments":[{"sliceIndex":1,"segmentIndex":1,"cabinClass":"economy",
"baggage":[{"type":"carryOn","quantity":1,"weightKg":null,"description":null}]}]}}],"owner":{"iataCode":"BA",
"name":"British Airways","logoUrl":"https://assets.openroam.io/services/image.png"},
"slices":[{"origin":{"iataCode":"LHR","icaoCode":"EGLL","name":"Heathrow Airport","city":"London",
"cityIataCode":"LON","countryCode":"GB","terminal":"5","timezone":"Europe/London"},"destination":{"iataCode":"JFK",
"icaoCode":"KJFK","name":"John F. Kennedy International Airport","city":"New York","cityIataCode":"NYC",
"countryCode":"US","terminal":"8","timezone":"America/New_York"},"departureAt":"2026-12-12T08:30:00Z",
"departureLocalTime":"2026-12-12T08:30:00","arrivalAt":"2026-12-12T16:20:00Z",
"arrivalLocalTime":"2026-12-12T11:20:00","durationMinutes":470,"stops":0,"segments":[{"origin":{"iataCode":"LHR",
"icaoCode":"EGLL","name":"Heathrow Airport","city":"London","cityIataCode":"LON","countryCode":"GB","terminal":"5",
"timezone":"Europe/London"},"destination":{"iataCode":"JFK","icaoCode":"KJFK",
"name":"John F. Kennedy International Airport","city":"New York","cityIataCode":"NYC","countryCode":"US",
"terminal":"8","timezone":"America/New_York"},"departureAt":"2026-12-12T08:30:00Z",
"departureLocalTime":"2026-12-12T08:30:00","arrivalAt":"2026-12-12T16:20:00Z",
"arrivalLocalTime":"2026-12-12T11:20:00","durationMinutes":470,"marketingCarrier":{"iataCode":"BA",
"name":"British Airways","logoUrl":"https://assets.openroam.io/services/image.png"},"marketingFlightNumber":"117",
"operatingCarrier":{"iataCode":"BA","name":"British Airways",
"logoUrl":"https://assets.openroam.io/services/image.png"},"operatingFlightNumber":"117","aircraft":{"iataCode":"77W",
"name":"Boeing 777-300ER","imageUrl":null},"stops":[],"isCodeshare":false}]}],"marketingCarriers":[{"iataCode":"BA",
"name":"British Airways","logoUrl":"https://assets.openroam.io/services/image.png"}],
"operatingCarriers":[{"iataCode":"BA","name":"British Airways",
"logoUrl":"https://assets.openroam.io/services/image.png"}],"totalDurationMinutes":885,"totalStops":0,
"totalEmissionsKg":1236}],"pagination":{"currentCount":1,"totalCount":1,"skip":0,"limit":10,"hasMore":false,"page":1,
"totalPages":1}}
JSON,
        'flightPlaces' => <<<'JSON'
{"items":[{"id":"LON","name":"London","countryCode":"GB","category":"city","logoUrl":null,"iataCode":"LON",
"cityName":"London","cityIataCode":"LON","timezone":"Europe/London","airports":[{"iataCode":"LGW",
"name":"Gatwick Airport","timezone":"Europe/London"}]}]}
JSON,
        'flightQuote' => <<<'JSON'
{"currency":"USD","items":[{"publicId":"flight_guis4oiryxjtxfalagepbfdcpk","quantity":1,
"name":"LHR \u21c4 JFK \u00b7 12\u201319 Dec \u00b7 Economy Basic, Non-refundable","price":{"amount":1184.2,
"currency":"USD"},"taxes":{"treatment":"included","amount":{"amount":204.2,"currency":"USD"},"lines":[{"code":"US",
"codeSystem":"iata","name":"Transportation tax","kind":"tax","payableAt":"prepaid","per":null,"jurisdiction":"US",
"amount":{"amount":47.0,"currency":"USD"},"approxAmount":null}]},"expiresAt":"2026-12-01T10:30:00Z",
"quotedAt":"2026-12-01T10:05:12Z","hasSeatMaps":true,"terms":{"payment":{"type":"payNow","dueNow":{"amount":1184.2,
"currency":"USD"},"dueLater":null,"dueLaterAt":null},"cancellation":{"type":"nonRefundable","freeUntil":null,
"freeUntilLocalTime":null,"timezone":"Europe/London","schedule":[],"fee":null},"changes":{"allowed":true,"fee":null}},
"includedBaggage":[{"type":"carryOn","quantity":1,"weightKg":null,"description":null}],
"availableServices":[{"id":"flight_4224ss6vukylqlb2iphmrx3u52","type":"baggage","price":{"amount":30.0,
"currency":"USD"},"passengerIndexes":[1],"segments":[{"sliceIndex":1,"segmentIndex":1}],"maxQuantity":2,
"baggage":{"type":"checked","maximumWeightKg":23,"maximumLengthCm":null,"maximumHeightCm":null,
"maximumDepthCm":null}}],"fareOptions":[{"publicId":"flight_fi6uxnnuusc37omsl7vrs6z6lt",
"name":"Economy Basic, Non-refundable","price":{"amount":1184.2,"currency":"USD"},"taxes":{"treatment":"included",
"amount":{"amount":204.2,"currency":"USD"},"lines":[{"code":"US","codeSystem":"iata","name":"Transportation tax",
"kind":"tax","payableAt":"prepaid","per":null,"jurisdiction":"US","amount":{"amount":47.0,"currency":"USD"},
"approxAmount":null}]},"details":{"expiresAt":"2026-12-01T10:30:00Z","cabinClass":"economy",
"fare":{"fareBasisCode":"OXZ0RO","brandName":"Basic","fareFamily":"Economy Basic","validatingCarrier":"BA"},
"terms":{"payment":{"type":"payNow","dueNow":{"amount":1184.2,"currency":"USD"},"dueLater":null,"dueLaterAt":null},
"cancellation":{"type":"nonRefundable","freeUntil":null,"freeUntilLocalTime":null,"timezone":"Europe/London",
"schedule":[],"fee":null},"changes":{"allowed":true,"fee":null}},"includedBaggage":[{"type":"carryOn","quantity":1,
"weightKg":null,"description":null}],"passengerPricing":[{"passengerType":"adult","count":1,"pricePerPassenger":null,
"fareBasisCode":"OXZ0RO","bookingClass":"O"}],"offerPassengers":[{"passengerIndex":1,"type":"adult","age":null,
"fareType":null,"includedBaggage":[{"type":"carryOn","quantity":1,"weightKg":null,"description":null}],
"availableServices":[{"id":"flight_4224ss6vukylqlb2iphmrx3u52","type":"baggage","price":{"amount":30.0,
"currency":"USD"},"passengerIndexes":[1],"segments":[{"sliceIndex":1,"segmentIndex":1}],"maxQuantity":2,
"baggage":{"type":"checked","maximumWeightKg":23,"maximumLengthCm":null,"maximumHeightCm":null,
"maximumDepthCm":null}}]}],"availableServices":[{"id":"flight_4224ss6vukylqlb2iphmrx3u52","type":"baggage",
"price":{"amount":30.0,"currency":"USD"},"passengerIndexes":[1],"segments":[{"sliceIndex":1,"segmentIndex":1}],
"maxQuantity":2,"baggage":{"type":"checked","maximumWeightKg":23,"maximumLengthCm":null,"maximumHeightCm":null,
"maximumDepthCm":null}}],"identityDocumentsRequired":true,"supportedDocumentTypes":["passport"],
"segments":[{"sliceIndex":1,"segmentIndex":1,"cabinClass":"economy","baggage":[{"type":"carryOn","quantity":1,
"weightKg":null,"description":null}]}]}}]}],"total":{"amount":1184.2,"currency":"USD"}}
JSON,
        'mixedQuote' => <<<'JSON'
{"currency":"USD","items":[{"productType":"airtime","publicId":"airtime_raanuxyz6cxzie3w3my6c3r2wm","quantity":1,
"name":"Bakcell Azerbaijan 40 AZN","value":{"amount":40,"currency":"AZN"},"price":{"amount":25.0,"currency":"USD"},
"taxes":{"treatment":"notStated","amount":null,"lines":[]},"estimatedDeliveredValue":{"amount":40,"currency":"AZN"}},
{"productType":"utility","publicId":"utility_7tq2mw5kxr3nbv6hzc4dpe2ysa","quantity":1,"name":"Azeriqaz 40 AZN",
"value":{"amount":40,"currency":"AZN"},"price":{"amount":27.5,"currency":"USD"},"taxes":{"treatment":"notStated",
"amount":null,"lines":[]},"deliveryType":"directCredit"},{"productType":"hotel",
"publicId":"rate_b4d6f3h5jn2pq7m2k4t6v3x5z2","quantity":1,
"name":"Hotel Arts Barcelona \u00b7 Deluxe King, Breakfast, Free cancellation","price":{"amount":648.9,
"currency":"USD"},"taxes":{"treatment":"included","amount":{"amount":41.64,"currency":"USD"},"lines":[{"code":null,
"codeSystem":null,"name":"Tax or fee","kind":"unknown","payableAt":"prepaid","per":null,"jurisdiction":null,
"amount":{"amount":38.0,"currency":"EUR"},"approxAmount":{"amount":41.64,"currency":"USD"}}]},
"expiresAt":"2026-12-01T10:42:00Z","availability":"confirmed","checkInDate":"2026-12-10","checkOutDate":"2026-12-13",
"requestedRooms":[{"adults":2,"children":1,"childrenAges":[7]}],"terms":{"payment":{"type":"payNow",
"dueNow":{"amount":648.9,"currency":"USD"},"dueLater":null,"dueLaterAt":null},
"cancellation":{"type":"fullyRefundable","freeUntil":"2026-12-08T23:59:00Z",
"freeUntilLocalTime":"2026-12-09T00:59:00","timezone":"Europe/Madrid","schedule":[{"from":"2026-12-08T23:59:00Z",
"fromLocalTime":"2026-12-09T00:59:00","refund":{"amount":432.6,"currency":"USD"},"penalty":{"amount":216.3,
"currency":"USD"}}],"fee":null},"changes":{"allowed":true,"fee":null}},
"rate":{"rooms":[{"roomGroupKey":"4c8e3ae1d379f759bc372243","name":"Deluxe King","description":null,"beds":[],
"maxOccupancy":3,"sizeSquareMeters":null,"amenities":[],"imageUrls":[],"roomIndex":1,"adultsCount":2,
"childrenCount":1,"childrenAges":[7]}],"board":{"code":"BB","name":"Bed and breakfast","boardType":"breakfast"},
"priceIsBinding":true,"nightlyPrices":[{"date":"2026-12-10","amount":216.3,"currency":"USD"}],"rateTags":[],
"remarks":[],"rateConditions":[],"withTransfers":false,"rateRules":[],"amendabilityStatus":"yes","surcharges":[],
"minimumSellingPrice":null}},{"productType":"flight","publicId":"flight_guis4oiryxjtxfalagepbfdcpk","quantity":1,
"name":"LHR \u21c4 JFK \u00b7 12\u201319 Dec \u00b7 Economy Basic, Non-refundable","price":{"amount":1184.2,
"currency":"USD"},"taxes":{"treatment":"included","amount":{"amount":204.2,"currency":"USD"},"lines":[{"code":"US",
"codeSystem":"iata","name":"Transportation tax","kind":"tax","payableAt":"prepaid","per":null,"jurisdiction":"US",
"amount":{"amount":47.0,"currency":"USD"},"approxAmount":null}]},"expiresAt":"2026-12-01T10:30:00Z",
"quotedAt":"2026-12-01T10:05:12Z","hasSeatMaps":true,"terms":{"payment":{"type":"payNow","dueNow":{"amount":1184.2,
"currency":"USD"},"dueLater":null,"dueLaterAt":null},"cancellation":{"type":"nonRefundable","freeUntil":null,
"freeUntilLocalTime":null,"timezone":"Europe/London","schedule":[],"fee":null},"changes":{"allowed":true,"fee":null}},
"includedBaggage":[{"type":"carryOn","quantity":1,"weightKg":null,"description":null}],
"availableServices":[{"id":"flight_4224ss6vukylqlb2iphmrx3u52","type":"baggage","price":{"amount":30.0,
"currency":"USD"},"passengerIndexes":[1],"segments":[{"sliceIndex":1,"segmentIndex":1}],"maxQuantity":2,
"baggage":{"type":"checked","maximumWeightKg":23,"maximumLengthCm":null,"maximumHeightCm":null,
"maximumDepthCm":null}}],"fareOptions":[{"publicId":"flight_fi6uxnnuusc37omsl7vrs6z6lt",
"name":"Economy Basic, Non-refundable","price":{"amount":1184.2,"currency":"USD"},"taxes":{"treatment":"included",
"amount":{"amount":204.2,"currency":"USD"},"lines":[{"code":"US","codeSystem":"iata","name":"Transportation tax",
"kind":"tax","payableAt":"prepaid","per":null,"jurisdiction":"US","amount":{"amount":47.0,"currency":"USD"},
"approxAmount":null}]},"details":{"expiresAt":"2026-12-01T10:30:00Z","cabinClass":"economy",
"fare":{"fareBasisCode":"OXZ0RO","brandName":"Basic","fareFamily":"Economy Basic","validatingCarrier":"BA"},
"terms":{"payment":{"type":"payNow","dueNow":{"amount":1184.2,"currency":"USD"},"dueLater":null,"dueLaterAt":null},
"cancellation":{"type":"nonRefundable","freeUntil":null,"freeUntilLocalTime":null,"timezone":"Europe/London",
"schedule":[],"fee":null},"changes":{"allowed":true,"fee":null}},"includedBaggage":[{"type":"carryOn","quantity":1,
"weightKg":null,"description":null}],"passengerPricing":[{"passengerType":"adult","count":1,"pricePerPassenger":null,
"fareBasisCode":"OXZ0RO","bookingClass":"O"}],"offerPassengers":[{"passengerIndex":1,"type":"adult","age":null,
"fareType":null,"includedBaggage":[{"type":"carryOn","quantity":1,"weightKg":null,"description":null}],
"availableServices":[{"id":"flight_4224ss6vukylqlb2iphmrx3u52","type":"baggage","price":{"amount":30.0,
"currency":"USD"},"passengerIndexes":[1],"segments":[{"sliceIndex":1,"segmentIndex":1}],"maxQuantity":2,
"baggage":{"type":"checked","maximumWeightKg":23,"maximumLengthCm":null,"maximumHeightCm":null,
"maximumDepthCm":null}}]}],"availableServices":[{"id":"flight_4224ss6vukylqlb2iphmrx3u52","type":"baggage",
"price":{"amount":30.0,"currency":"USD"},"passengerIndexes":[1],"segments":[{"sliceIndex":1,"segmentIndex":1}],
"maxQuantity":2,"baggage":{"type":"checked","maximumWeightKg":23,"maximumLengthCm":null,"maximumHeightCm":null,
"maximumDepthCm":null}}],"identityDocumentsRequired":true,"supportedDocumentTypes":["passport"],
"segments":[{"sliceIndex":1,"segmentIndex":1,"cabinClass":"economy","baggage":[{"type":"carryOn","quantity":1,
"weightKg":null,"description":null}]}]}}]}],"total":{"amount":1885.6,"currency":"USD"},
"expiresAt":"2026-12-01T10:30:00Z"}
JSON,
    ];

    private function read(string $model, string $name): object
    {
        return ObjectSerializer::deserialize(json_decode(self::PAYLOADS[$name]), '\OpenRoam\Model\\' . $model);
    }

    public function testAirtimeNumberLookupAndDeliveredValue(): void
    {
        $found = $this->read('AirtimeSearchResult', 'airtimeSearch');
        $this->assertSame('detected', $found->getPhoneLookup()->getStatus());
        $this->assertSame('Bakcell LLC', $found->getPhoneLookup()->getCarrierName());
        $this->assertSame('Credit arrives within minutes.', $found->getItems()[0]->getOffers()[0]->getDetails()->getReadMore());

        $delivered = $this->read('AirtimeQuote', 'airtimeQuote')->getItems()[0]->getEstimatedDeliveredValue();
        $this->assertEquals([40, 'AZN'], [$delivered->getAmount(), $delivered->getCurrency()]);
    }

    public function testEsimDestinationFieldsAreOnTheItem(): void
    {
        $destination = $this->read('EsimSearchResult', 'esimSearch')->getItems()[0];
        $this->assertSame('EU', $destination->getDestinationCode());
        $this->assertSame('Europe', $destination->getRegion());
        $this->assertSame('DE', $destination->getCoverage()[0]->getCountryCode());
        $this->assertSame(7, $destination->getOffers()[0]->getDetails()->getValidityDays());
    }

    public function testGiftCardAndUtilityFieldsAreBesideTheCommonKeys(): void
    {
        $card = $this->read('GiftCardSearchResult', 'giftCardSearch')->getItems()[0];
        $this->assertFalse($card->getIsRedeemableGlobally());
        $this->assertSame('Go to amazon.com/redeem and enter the code.', $card->getRedemptionInstructions());

        $biller = $this->read('UtilitySearchResult', 'utilitySearch')->getItems()[0];
        $this->assertSame(['postpaid', 'directCredit'], [$biller->getServiceType(), $biller->getDeliveryType()]);
        $this->assertSame('directCredit', $this->read('UtilityQuote', 'utilityQuote')->getItems()[0]->getDeliveryType());
    }

    public function testHotelFactsAndQuotedStay(): void
    {
        $found = $this->read('HotelSearchResult', 'hotelSearch');
        $hotel = $found->getItems()[0];
        $this->assertEquals(5, $hotel->getStarRating());
        $this->assertSame('Barcelona', $hotel->getAddress()->getCity());
        $this->assertEquals(41.386, $hotel->getCoordinates()->getLatitude());
        $this->assertCount(1, $hotel->getImageUrls());
        $this->assertSame(['FreeWifi'], $hotel->getAmenityGroups()[0]->getCodes());
        $this->assertSame('BB', $hotel->getOffers()[0]->getDetails()->getBoard()->getCode());
        $this->assertSame('FreeWifi', $found->getAvailableFilters()->getAmenities()[0]->getCode());

        $catalog = $this->read('CatalogHotel', 'catalogHotel');
        $this->assertEquals(5, $catalog->getStarRating());
        $this->assertSame('08005', $catalog->getAddress()->getPostalCode());

        $stay = $this->read('HotelQuote', 'hotelQuote')->getItems()[0];
        $this->assertSame('confirmed', $stay->getAvailability());
        $this->assertSame('2026-12-01T10:42:00+00:00', $stay->getExpiresAt()->format(DATE_ATOM));
        $this->assertSame('2026-12-10', $stay->getCheckInDate()->format('Y-m-d'));
        $this->assertSame('2026-12-13', $stay->getCheckOutDate()->format('Y-m-d'));
        $this->assertSame([7], $stay->getRequestedRooms()[0]->getChildrenAges());
        $this->assertSame('payNow', $stay->getTerms()->getPayment()->getType());
        $this->assertSame('BB', $stay->getRate()->getBoard()->getCode());
    }

    public function testFlightItineraryPlaceAndQuoteItemFields(): void
    {
        $itinerary = $this->read('FlightSearchResult', 'flightSearch')->getItems()[0];
        $this->assertSame('BA', $itinerary->getOwner()->getIataCode());
        $this->assertSame('117', $itinerary->getSlices()[0]->getSegments()[0]->getMarketingFlightNumber());
        $this->assertSame('BA', $itinerary->getMarketingCarriers()[0]->getIataCode());
        $this->assertSame('BA', $itinerary->getOperatingCarriers()[0]->getIataCode());
        $this->assertEquals(
            [885, 0, 1236],
            [$itinerary->getTotalDurationMinutes(), $itinerary->getTotalStops(), $itinerary->getTotalEmissionsKg()]
        );
        $this->assertSame('economy', $itinerary->getOffers()[0]->getDetails()->getCabinClass());

        $place = $this->read('FlightPlaceList', 'flightPlaces')->getItems()[0];
        $this->assertSame(
            ['LON', 'London', 'LON', 'Europe/London'],
            [$place->getIataCode(), $place->getCityName(), $place->getCityIataCode(), $place->getTimezone()]
        );
        $this->assertSame('LGW', $place->getAirports()[0]->getIataCode());

        $fare = $this->read('FlightQuote', 'flightQuote')->getItems()[0];
        $this->assertSame('2026-12-01T10:30:00+00:00', $fare->getExpiresAt()->format(DATE_ATOM));
        $this->assertSame('2026-12-01T10:05:12+00:00', $fare->getQuotedAt()->format(DATE_ATOM));
        $this->assertTrue($fare->getHasSeatMaps());
        $this->assertSame('nonRefundable', $fare->getTerms()->getCancellation()->getType());
        $this->assertSame('carryOn', $fare->getIncludedBaggage()[0]->getType());
        $this->assertSame(2, $fare->getAvailableServices()[0]->getMaxQuantity());
        $this->assertSame('economy', $fare->getFareOptions()[0]->getDetails()->getCabinClass());
    }

    public function testMixedQuoteVariantsCarryTheSameFields(): void
    {
        [$airtime, $utility, $hotel, $flight] = array_map(
            static fn ($item) => method_exists($item, 'getActualInstance') ? $item->getActualInstance() : $item,
            $this->read('MixedQuote', 'mixedQuote')->getItems()
        );
        $this->assertEquals(40, $airtime->getEstimatedDeliveredValue()->getAmount());
        $this->assertSame('directCredit', $utility->getDeliveryType());
        $this->assertSame('confirmed', $hotel->getAvailability());
        $this->assertSame('BB', $hotel->getRate()->getBoard()->getCode());
        $this->assertTrue($flight->getHasSeatMaps());
        $this->assertSame('economy', $flight->getFareOptions()[0]->getDetails()->getCabinClass());
    }
}
