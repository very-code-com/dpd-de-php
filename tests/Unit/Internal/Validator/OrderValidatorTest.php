<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe\Tests\Unit\Internal\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VeryCodeCom\DpdDe\Dto\Address;
use VeryCodeCom\DpdDe\Dto\Cod;
use VeryCodeCom\DpdDe\Dto\OrderItem;
use VeryCodeCom\DpdDe\Dto\Parcel;
use VeryCodeCom\DpdDe\Enum\PaymentType;
use VeryCodeCom\DpdDe\Enum\ShipService;
use VeryCodeCom\DpdDe\Internal\Validator\OrderValidator;

final class OrderValidatorTest extends TestCase
{
    private OrderValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new OrderValidator();
    }

    private function validAddress(): Address
    {
        return new Address(
            name: 'Max Mustermann',
            street: 'Musterstr.',
            houseNo: '1',
            country: 'DE',
            zipCode: '12345',
            city: 'Berlin',
        );
    }

    /** A parcel with every DPD-mandatory field filled in; override individual fields per test. */
    private function validParcel(
        ShipService $shipService = ShipService::Classic,
        float $weightKg = 2.5,
        ?string $content = 'Books',
        ?string $yourInternalId = 'ORDER-1',
        ?string $reference1 = 'REF-1',
        ?string $reference2 = null,
        ?Cod $cod = null,
    ): Parcel {
        return new Parcel(
            shipService: $shipService,
            weightKg: $weightKg,
            content: $content,
            yourInternalId: $yourInternalId,
            reference1: $reference1,
            reference2: $reference2,
            cod: $cod,
        );
    }

    private function validItem(?Parcel $parcel = null, ?Address $address = null): OrderItem
    {
        return new OrderItem(
            shipAddress: $address ?? $this->validAddress(),
            parcelShopId: 0,
            parcel: $parcel ?? $this->validParcel(),
        );
    }

    // -- overall list constraints --------------------------------------------------

    public function testEmptyItemListIsInvalid(): void
    {
        $errors = $this->validator->validate([]);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('At least one OrderItem', $errors[0]);
    }

    public function testValidSingleItemProducesNoErrors(): void
    {
        self::assertSame([], $this->validator->validate([$this->validItem()]));
    }

    public function testMoreThan30ItemsIsInvalid(): void
    {
        $items = array_fill(0, 31, $this->validItem());
        $errors = $this->validator->validate($items);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('at most 30', implode(' ', $errors));
    }

    public function testExactly30ItemsIsValid(): void
    {
        $items = array_fill(0, 30, $this->validItem());
        self::assertSame([], $this->validator->validate($items));
    }

    // -- weight --------------------------------------------------

    public function testWeightZeroIsValid(): void
    {
        $item = $this->validItem($this->validParcel(weightKg: 0.0));
        self::assertSame([], $this->validator->validate([$item]));
    }

    public function testWeightMaxIsValid(): void
    {
        $item = $this->validItem($this->validParcel(weightKg: 31.5));
        self::assertSame([], $this->validator->validate([$item]));
    }

    public function testWeightAboveMaxIsInvalid(): void
    {
        $item = $this->validItem($this->validParcel(weightKg: 31.6));
        $errors = $this->validator->validate([$item]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('Weight must be between 0 and 31.5', $errors[0]);
    }

    public function testNegativeWeightIsInvalid(): void
    {
        $item = $this->validItem($this->validParcel(weightKg: -1.0));
        $errors = $this->validator->validate([$item]);
        self::assertNotEmpty($errors);
    }

    // -- reference field lengths --------------------------------------------------

    public function testYourInternalIdAt35CharsIsValid(): void
    {
        $item = $this->validItem($this->validParcel(weightKg: 1.0, yourInternalId: str_repeat('a', 35)));
        self::assertSame([], $this->validator->validate([$item]));
    }

    public function testYourInternalIdOver35CharsIsInvalid(): void
    {
        $item = $this->validItem($this->validParcel(weightKg: 1.0, yourInternalId: str_repeat('a', 36)));
        $errors = $this->validator->validate([$item]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('YourInternalID', implode(' ', $errors));
    }

    public function testReference1Over35CharsIsInvalid(): void
    {
        $item = $this->validItem($this->validParcel(weightKg: 1.0, reference1: str_repeat('a', 36)));
        $errors = $this->validator->validate([$item]);
        self::assertStringContainsString('Reference1', implode(' ', $errors));
    }

    public function testContentOver35CharsIsInvalid(): void
    {
        $item = $this->validItem($this->validParcel(weightKg: 1.0, content: str_repeat('a', 36)));
        $errors = $this->validator->validate([$item]);
        self::assertStringContainsString('Content', implode(' ', $errors));
    }

    // -- fields DPD treats as mandatory despite minOccurs="0" --------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mandatoryParcelFieldProvider(): iterable
    {
        yield 'Content' => ['content', 'CLOUD_API_ORDER_CONTENT'];
        yield 'YourInternalID' => ['yourInternalId', 'CLOUD_API_ORDER_INTERNALID'];
        yield 'Reference1' => ['reference1', 'CLOUD_API_ORDER_REFERENCE1'];
    }

    #[DataProvider('mandatoryParcelFieldProvider')]
    public function testMandatoryParcelFieldIsRequired(string $field, string $dpdErrorCode): void
    {
        $item = $this->validItem($this->validParcel(...[$field => null]));
        $errors = $this->validator->validate([$item]);

        self::assertCount(1, $errors);
        self::assertStringContainsString($dpdErrorCode, $errors[0]);
    }

    #[DataProvider('mandatoryParcelFieldProvider')]
    public function testMandatoryParcelFieldRejectsWhitespaceOnly(string $field, string $dpdErrorCode): void
    {
        $item = $this->validItem($this->validParcel(...[$field => '   ']));
        $errors = $this->validator->validate([$item]);

        self::assertCount(1, $errors);
        self::assertStringContainsString($dpdErrorCode, $errors[0]);
    }

    public function testReference2StaysOptional(): void
    {
        self::assertSame([], $this->validator->validate([$this->validItem($this->validParcel(reference2: null))]));
    }

    // -- length limits are counted in characters, not bytes --------------------------------------------------

    public function testMultibyteContentAtLimitIsValid(): void
    {
        // 35 characters, 70 bytes in UTF-8, a byte-based check would reject this.
        $item = $this->validItem($this->validParcel(content: str_repeat('ä', 35)));
        self::assertSame([], $this->validator->validate([$item]));
    }

    public function testMultibyteContentOverLimitIsInvalid(): void
    {
        $item = $this->validItem($this->validParcel(content: str_repeat('ä', 36)));
        $errors = $this->validator->validate([$item]);

        self::assertStringContainsString('got 36', implode(' ', $errors));
    }

    public function testMultibyteStreetAtLimitIsValid(): void
    {
        $address = new Address(
            name: 'Max Mustermann',
            street: str_repeat('ö', 50),
            houseNo: '1',
            country: 'DE',
            zipCode: '12345',
            city: 'Berlin',
        );

        self::assertSame([], $this->validator->validate([$this->validItem(address: $address)]));
    }

    // -- COD purpose --------------------------------------------------

    public function testCodPurposeAt14CharsIsValid(): void
    {
        $item = $this->validItem($this->validParcel(
            shipService: ShipService::ClassicCod,
            weightKg: 1.0,
            cod: new Cod(amount: 10.0, payment: PaymentType::Cash, purpose: str_repeat('a', 14)),
        ));
        self::assertSame([], $this->validator->validate([$item]));
    }

    public function testCodPurposeOver14CharsIsInvalid(): void
    {
        $item = $this->validItem($this->validParcel(
            shipService: ShipService::ClassicCod,
            weightKg: 1.0,
            cod: new Cod(amount: 10.0, payment: PaymentType::Cash, purpose: str_repeat('a', 15)),
        ));
        $errors = $this->validator->validate([$item]);
        self::assertStringContainsString('COD.Purpose', implode(' ', $errors));
    }

    // -- address: company / name --------------------------------------------------

    public function testAddressWithOnlyCompanyIsValid(): void
    {
        $item = $this->validItem(address: new Address(
            company: 'Very Code GmbH',
            street: 'Musterstr.',
            houseNo: '1',
            country: 'DE',
            zipCode: '12345',
            city: 'Berlin',
        ));
        self::assertSame([], $this->validator->validate([$item]));
    }

    public function testAddressWithoutCompanyOrNameIsInvalid(): void
    {
        $item = $this->validItem(address: new Address(
            street: 'Musterstr.',
            houseNo: '1',
            country: 'DE',
            zipCode: '12345',
            city: 'Berlin',
        ));
        $errors = $this->validator->validate([$item]);
        self::assertStringContainsString('must provide either Company or Name', implode(' ', $errors));
    }

    public function testAddressCompanyTooShortIsInvalid(): void
    {
        $item = $this->validItem(address: new Address(
            company: 'A',
            street: 'Musterstr.',
            houseNo: '1',
            country: 'DE',
            zipCode: '12345',
            city: 'Berlin',
        ));
        $errors = $this->validator->validate([$item]);
        self::assertStringContainsString('ShipAddress.Company', implode(' ', $errors));
    }

    public function testFirstNameLastNameCombinedIsValidated(): void
    {
        $item = $this->validItem(address: new Address(
            firstName: 'Max',
            lastName: 'M',
            street: 'Musterstr.',
            houseNo: '1',
            country: 'DE',
            zipCode: '12345',
            city: 'Berlin',
        ));
        self::assertSame([], $this->validator->validate([$item]));
    }

    // -- address: street / houseNo / city --------------------------------------------------

    public function testMissingStreetIsInvalid(): void
    {
        $item = $this->validItem(address: new Address(
            name: 'Max Mustermann',
            houseNo: '1',
            country: 'DE',
            zipCode: '12345',
            city: 'Berlin',
        ));
        $errors = $this->validator->validate([$item]);
        self::assertStringContainsString('ShipAddress.Street is required', implode(' ', $errors));
    }

    public function testHouseNoOver8CharsIsInvalid(): void
    {
        $item = $this->validItem(address: new Address(
            name: 'Max Mustermann',
            street: 'Musterstr.',
            houseNo: str_repeat('1', 9),
            country: 'DE',
            zipCode: '12345',
            city: 'Berlin',
        ));
        $errors = $this->validator->validate([$item]);
        self::assertStringContainsString('ShipAddress.HouseNo', implode(' ', $errors));
    }

    public function testMissingZipCodeIsInvalid(): void
    {
        $item = $this->validItem(address: new Address(
            name: 'Max Mustermann',
            street: 'Musterstr.',
            houseNo: '1',
            country: 'DE',
            city: 'Berlin',
        ));
        $errors = $this->validator->validate([$item]);
        self::assertStringContainsString('ShipAddress.ZipCode is required', implode(' ', $errors));
    }

    public function testMissingCountryIsInvalid(): void
    {
        $item = $this->validItem(address: new Address(
            name: 'Max Mustermann',
            street: 'Musterstr.',
            houseNo: '1',
            zipCode: '12345',
            city: 'Berlin',
        ));
        $errors = $this->validator->validate([$item]);
        self::assertStringContainsString('ShipAddress.Country is required', implode(' ', $errors));
    }

    // -- phone / state --------------------------------------------------

    public function testPhoneTooShortIsInvalid(): void
    {
        $item = $this->validItem(address: new Address(
            name: 'Max Mustermann',
            street: 'Musterstr.',
            houseNo: '1',
            country: 'DE',
            zipCode: '12345',
            city: 'Berlin',
            phone: '123',
        ));
        $errors = $this->validator->validate([$item]);
        self::assertStringContainsString('ShipAddress.Phone', implode(' ', $errors));
    }

    public function testValidPhoneIsAccepted(): void
    {
        $item = $this->validItem(address: new Address(
            name: 'Max Mustermann',
            street: 'Musterstr.',
            houseNo: '1',
            country: 'DE',
            zipCode: '12345',
            city: 'Berlin',
            phone: '+49 30 1234567',
        ));
        self::assertSame([], $this->validator->validate([$item]));
    }

    public function testStateNotTwoCharsIsInvalid(): void
    {
        $item = $this->validItem(address: new Address(
            name: 'Max Mustermann',
            street: 'Main St',
            houseNo: '1',
            country: 'USA',
            zipCode: '10001',
            city: 'New York',
            state: 'NEW',
        ));
        $errors = $this->validator->validate([$item]);
        self::assertStringContainsString('exactly 2 characters', implode(' ', $errors));
    }

    public function testStateTwoCharsIsValidForUsa(): void
    {
        $item = $this->validItem(address: new Address(
            name: 'Max Mustermann',
            street: 'Main St',
            houseNo: '1',
            country: 'USA',
            zipCode: '10001',
            city: 'New York',
            state: 'NY',
        ));
        self::assertSame([], $this->validator->validate([$item]));
    }

    // -- state is mandatory for USA/Canada, forbidden elsewhere --------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function stateCountryProvider(): iterable
    {
        yield 'Alpha-2' => ['US'];
        yield 'Alpha-3' => ['USA'];
        yield 'numeric' => ['840'];
        yield 'name' => ['United States'];
        yield 'punctuated' => ['U.S.A.'];
        yield 'Canada Alpha-3' => ['CAN'];
    }

    #[DataProvider('stateCountryProvider')]
    public function testStateIsMandatoryForUsaAndCanada(string $country): void
    {
        $item = $this->validItem(address: new Address(
            name: 'Max Mustermann',
            street: 'Main St',
            houseNo: '1',
            country: $country,
            zipCode: '10001',
            city: 'New York',
        ));
        $errors = $this->validator->validate([$item]);

        self::assertStringContainsString('State is mandatory for USA and Canada', implode(' ', $errors));
    }

    public function testStateIsRejectedForOtherCountries(): void
    {
        $item = $this->validItem(address: new Address(
            name: 'Max Mustermann',
            street: 'Musterstr.',
            houseNo: '1',
            country: 'DE',
            zipCode: '12345',
            city: 'Berlin',
            state: 'BE',
        ));
        $errors = $this->validator->validate([$item]);

        self::assertStringContainsString('must only be set for USA and Canada', implode(' ', $errors));
    }

    // -- product rules --------------------------------------------------

    /** @return iterable<string, array{ShipService}> */
    public static function predictServiceProvider(): iterable
    {
        yield 'Classic_Predict' => [ShipService::ClassicPredict];
        yield 'Classic_COD_Predict' => [ShipService::ClassicCodPredict];
    }

    #[DataProvider('predictServiceProvider')]
    public function testPredictRequiresMailOrPhone(ShipService $service): void
    {
        $cod = $service->isCod() ? new Cod(amount: 10.0, payment: PaymentType::Cash) : null;
        $item = $this->validItem($this->validParcel(shipService: $service, cod: $cod));
        $errors = $this->validator->validate([$item]);

        self::assertStringContainsString('CLOUD_ADDRESS_NEEDMAILORSMS', implode(' ', $errors));
    }

    public function testPredictWithMailIsValid(): void
    {
        $item = $this->validItem(
            $this->validParcel(shipService: ShipService::ClassicPredict),
            new Address(
                name: 'Max Mustermann',
                street: 'Musterstr.',
                houseNo: '1',
                country: 'DE',
                zipCode: '12345',
                city: 'Berlin',
                mail: 'max@example.com',
            ),
        );

        self::assertSame([], $this->validator->validate([$item]));
    }

    public function testClassicReturnRequiresPhone(): void
    {
        $item = $this->validItem($this->validParcel(shipService: ShipService::ClassicReturn));
        $errors = $this->validator->validate([$item]);

        self::assertStringContainsString('Classic_Return requires a phone number', implode(' ', $errors));
    }

    public function testClassicReturnCannotBeBatched(): void
    {
        $withPhone = new Address(
            name: 'Max Mustermann',
            street: 'Musterstr.',
            houseNo: '1',
            country: 'DE',
            zipCode: '12345',
            city: 'Berlin',
            phone: '+49 30 1234567',
        );
        $items = [
            $this->validItem($this->validParcel(shipService: ShipService::ClassicReturn), $withPhone),
            $this->validItem(),
        ];
        $errors = $this->validator->validate($items);

        self::assertStringContainsString('CLOUD_API_ORDER_CLASSICRETURN_NOBULKPRINT', implode(' ', $errors));
    }

    public function testSingleClassicReturnIsValid(): void
    {
        $item = $this->validItem(
            $this->validParcel(shipService: ShipService::ClassicReturn),
            new Address(
                name: 'Max Mustermann',
                street: 'Musterstr.',
                houseNo: '1',
                country: 'DE',
                zipCode: '12345',
                city: 'Berlin',
                phone: '+49 30 1234567',
            ),
        );

        self::assertSame([], $this->validator->validate([$item]));
    }

    public function testShopDeliveryRequiresParcelShopId(): void
    {
        $item = new OrderItem(
            shipAddress: $this->validAddress(),
            parcelShopId: 0,
            parcel: $this->validParcel(shipService: ShipService::ShopDelivery),
        );
        $errors = $this->validator->validate([$item]);

        self::assertStringContainsString('CLOUD_API_ORDER_PARCELSHOP', implode(' ', $errors));
    }

    public function testShopDeliveryWithParcelShopIdIsValid(): void
    {
        $item = new OrderItem(
            shipAddress: $this->validAddress(),
            parcelShopId: 260538,
            parcel: $this->validParcel(shipService: ShipService::ShopDelivery),
        );

        self::assertSame([], $this->validator->validate([$item]));
    }

    public function testDomesticExpressOutsideGermanyIsInvalid(): void
    {
        $item = $this->validItem(
            $this->validParcel(shipService: ShipService::Express12),
            new Address(
                name: 'Max Mustermann',
                street: 'Hauptstrasse',
                houseNo: '1',
                country: 'AT',
                zipCode: '1010',
                city: 'Wien',
            ),
        );
        $errors = $this->validator->validate([$item]);

        self::assertStringContainsString('CLOUD_API_ORDER_EXPRESS_DEU_COUNTRY', implode(' ', $errors));
    }

    public function testDomesticExpressInsideGermanyIsValid(): void
    {
        $item = $this->validItem($this->validParcel(shipService: ShipService::Express12));
        self::assertSame([], $this->validator->validate([$item]));
    }

    public function testExpressInternationalOutsideGermanyIsValid(): void
    {
        $item = $this->validItem(
            $this->validParcel(shipService: ShipService::ExpressInternational),
            new Address(
                name: 'Max Mustermann',
                street: 'Hauptstrasse',
                houseNo: '1',
                country: 'AT',
                zipCode: '1010',
                city: 'Wien',
            ),
        );

        self::assertSame([], $this->validator->validate([$item]));
    }

    // -- COD amounts --------------------------------------------------

    public function testCodProductWithoutCodDataIsInvalid(): void
    {
        $item = $this->validItem($this->validParcel(shipService: ShipService::ClassicCod));
        $errors = $this->validator->validate([$item]);

        self::assertStringContainsString('requires COD data', implode(' ', $errors));
    }

    public function testCodDataOnNonCodProductIsInvalid(): void
    {
        $item = $this->validItem($this->validParcel(cod: new Cod(amount: 10.0, payment: PaymentType::Cash)));
        $errors = $this->validator->validate([$item]);

        self::assertStringContainsString('not a cash-on-delivery product', implode(' ', $errors));
    }

    /** @return iterable<string, array{float, PaymentType, bool}> */
    public static function codAmountProvider(): iterable
    {
        yield 'below minimum' => [0.5, PaymentType::Cash, false];
        yield 'at minimum' => [1.0, PaymentType::Cash, true];
        yield 'cash at limit' => [2500.0, PaymentType::Cash, true];
        yield 'cash above limit' => [2500.01, PaymentType::Cash, false];
        yield 'cheque above cash limit' => [4000.0, PaymentType::Cheque, true];
        yield 'cheque at maximum' => [5000.0, PaymentType::Cheque, true];
        yield 'above maximum' => [5000.01, PaymentType::Cheque, false];
    }

    #[DataProvider('codAmountProvider')]
    public function testCodAmountLimits(float $amount, PaymentType $payment, bool $expectedValid): void
    {
        $item = $this->validItem($this->validParcel(
            shipService: ShipService::ClassicCod,
            cod: new Cod(amount: $amount, payment: $payment),
        ));
        $errors = $this->validator->validate([$item]);

        self::assertSame($expectedValid, $errors === [], implode(' ', $errors));
    }

    // -- contact detail formats --------------------------------------------------

    public function testInvalidMailIsRejected(): void
    {
        $item = $this->validItem(address: new Address(
            name: 'Max Mustermann',
            street: 'Musterstr.',
            houseNo: '1',
            country: 'DE',
            zipCode: '12345',
            city: 'Berlin',
            mail: 'not-an-email',
        ));
        $errors = $this->validator->validate([$item]);

        self::assertStringContainsString('CLOUD_ADDRESS_MAIL', implode(' ', $errors));
    }

    public function testPhoneWithLettersIsRejected(): void
    {
        $item = $this->validItem(address: new Address(
            name: 'Max Mustermann',
            street: 'Musterstr.',
            houseNo: '1',
            country: 'DE',
            zipCode: '12345',
            city: 'Berlin',
            phone: '030-CALL-DPD',
        ));
        $errors = $this->validator->validate([$item]);

        self::assertStringContainsString('CLOUD_ADDRESS_PHONE', implode(' ', $errors));
    }

    public function testGenderWithoutNameIsRejected(): void
    {
        $item = $this->validItem(address: new Address(
            company: 'Very Code GmbH',
            street: 'Musterstr.',
            houseNo: '1',
            country: 'DE',
            zipCode: '12345',
            city: 'Berlin',
            gender: 'male',
        ));
        $errors = $this->validator->validate([$item]);

        self::assertStringContainsString('CLOUD_ADDRESS_GENDER', implode(' ', $errors));
    }

    public function testSalutationTooLongIsRejected(): void
    {
        $item = $this->validItem(address: new Address(
            salutation: str_repeat('a', 11),
            name: 'Max Mustermann',
            street: 'Musterstr.',
            houseNo: '1',
            country: 'DE',
            zipCode: '12345',
            city: 'Berlin',
        ));
        $errors = $this->validator->validate([$item]);

        self::assertStringContainsString('ShipAddress.Salutation', implode(' ', $errors));
    }

    // -- multiple items report per-item indices --------------------------------------------------

    public function testMultipleItemsReportCorrectIndex(): void
    {
        $items = [
            $this->validItem(),
            $this->validItem($this->validParcel(weightKg: 999.0)),
        ];
        $errors = $this->validator->validate($items);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('OrderItem[1]', implode(' ', $errors));
    }
}
