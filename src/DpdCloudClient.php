<?php

declare(strict_types=1);

namespace VeryCodeCom\DpdDe;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VeryCodeCom\DpdDe\Dto\OrderItem;
use VeryCodeCom\DpdDe\Dto\OrderSettings;
use VeryCodeCom\DpdDe\Dto\OrderStatus;
use VeryCodeCom\DpdDe\Dto\ParcelShop;
use VeryCodeCom\DpdDe\Dto\ParcelShopQuery;
use VeryCodeCom\DpdDe\Dto\SetOrderResult;
use VeryCodeCom\DpdDe\Dto\TrackingResult;
use VeryCodeCom\DpdDe\Dto\ZipCodeRules;
use VeryCodeCom\DpdDe\Enum\OrderAction;
use VeryCodeCom\DpdDe\Enum\TransportMode;
use VeryCodeCom\DpdDe\Exception\DpdCloudApiException;
use VeryCodeCom\DpdDe\Exception\DpdCloudAuthException;
use VeryCodeCom\DpdDe\Exception\DpdCloudException;
use VeryCodeCom\DpdDe\Exception\DpdCloudRateLimitException;
use VeryCodeCom\DpdDe\Exception\DpdCloudResponseParseException;
use VeryCodeCom\DpdDe\Exception\DpdCloudTransportException;
use VeryCodeCom\DpdDe\Exception\DpdCloudValidationException;
use VeryCodeCom\DpdDe\Internal\Rest\RestRequestBuilder;
use VeryCodeCom\DpdDe\Internal\Rest\RestResponseParser;
use VeryCodeCom\DpdDe\Internal\Soap\ResponseParser;
use VeryCodeCom\DpdDe\Internal\Soap\SoapEnvelopeBuilder;
use VeryCodeCom\DpdDe\Internal\Validator\OrderValidator;
use VeryCodeCom\DpdDe\Transport\CurlTransport;
use VeryCodeCom\DpdDe\Transport\TransportInterface;
use VeryCodeCom\DpdDe\Transport\TransportRequest;

/**
 * PHP client for the DPD Cloud Service Webservice (SOAP + REST).
 *
 * Quick start:
 *
 *   $client = DpdCloudClient::sandbox('Partner Name', 'partner-token', 123456, 'user-token');
 *
 *   $result = $client->createShipment(new OrderItem(
 *       shipAddress: new Address(name: 'Max Mustermann', street: 'Musterstr.', houseNo: '1',
 *                                 zipCode: '12345', city: 'Berlin', country: 'DE'),
 *       parcelShopId: 0,
 *       parcel: new Parcel(ShipService::Classic, weightKg: 2.5, content: 'Books',
 *                          yourInternalId: 'ORDER-1', reference1: 'REF-1'),
 *   ));
 *
 *   echo $result->firstParcelNo();
 *   file_put_contents('label.pdf', $result->labelPdf);
 *
 * All five DPD Cloud Service methods are supported:
 *   createShipment() / createShipments() -> setOrder (startOrder)
 *   validate()                            -> setOrder (checkOrderData, pre-flight, no label issued)
 *   fetchParcelLifeCycle()                -> getParcelLifeCycle
 *   fetchOrderStatus()                    -> getOrderStatus
 *   findParcelShops()                     -> getParcelShopFinder
 *   fetchZipCodeRules()                   -> getZipCodeRules
 *
 * Transport: SOAP is used by default; REST can be selected via the $transportMode constructor
 * argument. getParcelLifeCycle() always uses SOAP regardless of $transportMode, since its deeply
 * nested UI-oriented response shape has no REST parser here.
 *
 * @see https://github.com/very-code-com/dpd-de-php
 */
final class DpdCloudClient
{
    private readonly SoapEnvelopeBuilder $soapBuilder;
    private readonly ResponseParser $soapParser;
    private readonly RestRequestBuilder $restBuilder;
    private readonly RestResponseParser $restParser;
    private readonly OrderValidator $validator;
    private readonly LoggerInterface $logger;
    private ?string $lastSystemInformation = null;

    public function __construct(
        private readonly DpdCloudConfig $config,
        private readonly TransportMode $transportMode = TransportMode::Soap,
        private readonly TransportInterface $transport = new CurlTransport(),
        ?LoggerInterface $logger = null,
    ) {
        $this->soapBuilder = new SoapEnvelopeBuilder($config);
        $this->soapParser = new ResponseParser();
        $this->restBuilder = new RestRequestBuilder($config);
        $this->restParser = new RestResponseParser();
        $this->validator = new OrderValidator();
        $this->logger = $logger ?? new NullLogger();
    }

    // -----------------------------------------------------------------
    // Named constructors
    // -----------------------------------------------------------------

    public static function sandbox(string $partnerName, string $partnerToken, int $userId, string $userToken): self
    {
        return new self(DpdCloudConfig::sandbox($partnerName, $partnerToken, $userId, $userToken));
    }

    public static function production(string $partnerName, string $partnerToken, int $userId, string $userToken): self
    {
        return new self(DpdCloudConfig::production($partnerName, $partnerToken, $userId, $userToken));
    }

    // -----------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------

    /**
     * DPD's `SystemInformation` from the most recent response, or null when the last call carried
     * none. DPD uses this free-text field for service announcements (planned maintenance, upcoming
     * API changes); it is also logged at `notice` level whenever it is non-empty.
     */
    public function lastSystemInformation(): ?string
    {
        return $this->lastSystemInformation;
    }

    /**
     * Pre-flight validation: run local field/weight checks WITHOUT making any network call.
     *
     * @param list<OrderItem> $items
     * @return list<string> Validation error messages; empty array = locally valid.
     */
    public function validateLocally(array $items): array
    {
        return $this->validator->validate($items);
    }

    /**
     * Create a single shipment (DPD method: setOrder, OrderAction=startOrder).
     *
     * @throws DpdCloudValidationException if local validation fails.
     * @throws DpdCloudAuthException        if DPD rejects Partner/User credentials.
     * @throws DpdCloudApiException         for other DPD API errors.
     * @throws DpdCloudTransportException   on network / HTTP errors.
     * @throws DpdCloudResponseParseException if DPD returns malformed data.
     */
    public function createShipment(OrderItem $item, ?OrderSettings $settings = null): SetOrderResult
    {
        return $this->createShipments([$item], $settings);
    }

    /**
     * Create up to 30 shipments in a single call (DPD method: setOrder, OrderAction=startOrder).
     *
     * Note: DPD Cloud Service does not support multi-parcel shipments (MPS); each physical
     * package needs its own {@see OrderItem} with its own ship address.
     *
     * @param list<OrderItem> $items 1-30 items.
     *
     * @throws DpdCloudValidationException if local validation fails.
     * @throws DpdCloudAuthException        if DPD rejects Partner/User credentials.
     * @throws DpdCloudApiException         for other DPD API errors.
     * @throws DpdCloudTransportException   on network / HTTP errors.
     * @throws DpdCloudResponseParseException if DPD returns malformed data.
     */
    public function createShipments(array $items, ?OrderSettings $settings = null): SetOrderResult
    {
        $errors = $this->validateLocally($items);
        if ($errors !== []) {
            $this->fail(new DpdCloudValidationException($errors));
        }

        $settings ??= OrderSettings::default();

        $this->logger->info('DPD Cloud: creating shipment(s)', ['count' => count($items)]);

        return $this->setOrder($items, $settings, OrderAction::StartOrder);
    }

    /**
     * Server-side dry run: ask DPD to validate the order data without creating a real shipment
     * or consuming a parcel number (DPD method: setOrder, OrderAction=checkOrderData).
     *
     * @param list<OrderItem> $items
     *
     * @throws DpdCloudValidationException if local validation fails.
     * @throws DpdCloudAuthException        if DPD rejects Partner/User credentials.
     * @throws DpdCloudApiException         if DPD reports the order data as invalid.
     * @throws DpdCloudTransportException   on network / HTTP errors.
     * @throws DpdCloudResponseParseException if DPD returns malformed data.
     */
    public function checkOrderData(array $items, ?OrderSettings $settings = null): void
    {
        $errors = $this->validateLocally($items);
        if ($errors !== []) {
            $this->fail(new DpdCloudValidationException($errors));
        }

        $settings ??= OrderSettings::default();

        $this->setOrder($items, $settings, OrderAction::CheckOrderData);
    }

    /**
     * Fetch tracking data using the older, UI-rendering-oriented "Parcel Life Cycle Service 2.0"
     * (DPD method: getParcelLifeCycle). Always uses SOAP (see class docblock).
     *
     * @throws DpdCloudApiException         for DPD API errors.
     * @throws DpdCloudTransportException   on network / HTTP errors.
     * @throws DpdCloudResponseParseException if DPD returns malformed data.
     */
    public function fetchParcelLifeCycle(string $parcelNo): TrackingResult
    {
        $this->logger->debug('DPD Cloud: fetching parcel life cycle', ['parcelNo' => $parcelNo]);

        $envelope = $this->soapBuilder->buildGetParcelLifeCycle($parcelNo);
        $xpath = $this->callSoap('getParcelLifeCycle', $envelope, 'getParcelLifeCycleResult');

        return $this->soapParser->trackingResult($xpath);
    }

    /**
     * Fetch tracking data using the newer, structured "Parcel Life Cycle Service 3.1"
     * (DPD method: getOrderStatus). Provide `deliveryZipCode` to receive full (non-anonymised)
     * tracking data, per DPD's privacy rules.
     *
     * @throws DpdCloudApiException         for DPD API errors.
     * @throws DpdCloudTransportException   on network / HTTP errors.
     * @throws DpdCloudResponseParseException if DPD returns malformed data.
     */
    public function fetchOrderStatus(string $parcelNo, ?string $deliveryZipCode = null): OrderStatus
    {
        $this->logger->debug('DPD Cloud: fetching order status', ['parcelNo' => $parcelNo]);

        if ($this->transportMode === TransportMode::Rest) {
            $request = $this->restBuilder->buildGetOrderStatus($parcelNo, $deliveryZipCode);
            $data = $this->callRest('getOrderStatus', $request);

            return $this->restParser->orderStatus($data);
        }

        $envelope = $this->soapBuilder->buildGetOrderStatus($parcelNo, $deliveryZipCode);
        $xpath = $this->callSoap('getOrderStatus', $envelope, 'getOrderStatusResult');

        return $this->soapParser->orderStatus($xpath);
    }

    /**
     * Search for DPD ParcelShop pickup points (DPD method: getParcelShopFinder).
     *
     * @return list<ParcelShop>
     *
     * @throws DpdCloudApiException         for DPD API errors.
     * @throws DpdCloudTransportException   on network / HTTP errors.
     * @throws DpdCloudResponseParseException if DPD returns malformed data.
     */
    public function findParcelShops(ParcelShopQuery $query): array
    {
        $this->logger->debug('DPD Cloud: finding parcel shops', ['searchMode' => $query->searchMode->value]);

        if ($this->transportMode === TransportMode::Rest) {
            $request = $this->restBuilder->buildGetParcelShopFinder($query);
            $data = $this->callRest('getParcelShopFinder', $request);

            return $this->restParser->parcelShops($data);
        }

        $envelope = $this->soapBuilder->buildGetParcelShopFinder($query);
        $xpath = $this->callSoap('getParcelShopFinder', $envelope, 'getParcelShopFinderResult');

        return $this->soapParser->parcelShops($xpath);
    }

    /**
     * Fetch pickup rules (no-pickup days, cut-off times, depot) for the Cloud User account's own
     * pickup address (DPD method: getZipCodeRules; no extra parameters needed).
     *
     * @throws DpdCloudApiException         for DPD API errors.
     * @throws DpdCloudTransportException   on network / HTTP errors.
     * @throws DpdCloudResponseParseException if DPD returns malformed data.
     */
    public function fetchZipCodeRules(): ZipCodeRules
    {
        $this->logger->debug('DPD Cloud: fetching zip code rules');

        if ($this->transportMode === TransportMode::Rest) {
            $request = $this->restBuilder->buildGetZipCodeRules();
            $data = $this->callRest('getZipCodeRules', $request);

            return $this->restParser->zipCodeRules($data);
        }

        $envelope = $this->soapBuilder->buildGetZipCodeRules();
        $xpath = $this->callSoap('getZipCodeRules', $envelope, 'getZipCodeRulesResult');

        return $this->soapParser->zipCodeRules($xpath);
    }

    // -----------------------------------------------------------------
    // Internal orchestration
    // -----------------------------------------------------------------

    /** @param list<OrderItem> $items */
    private function setOrder(array $items, OrderSettings $settings, OrderAction $action): SetOrderResult
    {
        if ($this->transportMode === TransportMode::Rest) {
            $request = $this->restBuilder->buildSetOrder($items, $settings, $action);
            $data = $this->callRest('setOrder', $request);

            return $this->restParser->setOrderResult($data);
        }

        $envelope = $this->soapBuilder->buildSetOrder($items, $settings, $action);
        $xpath = $this->callSoap('setOrder', $envelope, 'setOrderResult');

        return $this->soapParser->setOrderResult($xpath);
    }

    /** @throws DpdCloudTransportException|DpdCloudAuthException|DpdCloudApiException|DpdCloudResponseParseException */
    private function callSoap(string $operation, string $envelope, string $resultElement): \DOMXPath
    {
        $request = new TransportRequest(
            url: $this->config->getSoapEndpoint(),
            method: 'POST',
            body: $envelope,
            headers: [
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction' => '"https://cloud.dpd.com/' . $operation . '"',
            ],
            timeout: $this->config->timeout,
            connectTimeout: $this->config->connectTimeout,
            verifySsl: $this->config->verifySsl(),
        );

        $response = $this->transport->send($request);

        if (!$response->isSuccess()) {
            $this->fail(
                new DpdCloudTransportException("DPD Cloud Service returned HTTP {$response->statusCode} for {$operation}."),
                $response->body,
            );
        }

        $xpath = $this->soapParser->parse($response->body, $operation);

        $this->captureSystemInformation($this->soapParser->systemInformation($xpath, $resultElement), $operation);

        if (!$this->soapParser->isSuccess($xpath, $resultElement)) {
            $this->handleApiError($operation, $this->soapParser->errorDataList($xpath, $resultElement), $response->body);
        }

        return $xpath;
    }

    /**
     * @throws DpdCloudTransportException|DpdCloudAuthException|DpdCloudApiException|DpdCloudResponseParseException
     * @return array<string, mixed>
     */
    private function callRest(string $operation, TransportRequest $request): array
    {
        $response = $this->transport->send($request);

        if (!$response->isSuccess() && $response->statusCode >= 500) {
            $this->fail(
                new DpdCloudTransportException("DPD Cloud Service returned HTTP {$response->statusCode} for {$operation}."),
                $response->body,
            );
        }

        $data = $this->restParser->decode($response->body, $operation);

        $this->captureSystemInformation($this->restParser->systemInformation($data), $operation);

        if (!$this->restParser->isSuccess($data)) {
            $this->handleApiError($operation, $this->restParser->errorDataList($data), $response->body);
        }

        return $data;
    }

    /**
     * DPD returns a free-text `SystemInformation` on every response (service announcements,
     * maintenance windows). Keep the latest one available to the caller and surface it in the log.
     */
    private function captureSystemInformation(string $systemInformation, string $operation): void
    {
        $systemInformation = trim($systemInformation);
        $this->lastSystemInformation = $systemInformation !== '' ? $systemInformation : null;

        if ($this->lastSystemInformation !== null) {
            $this->logger->notice('DPD Cloud: system information', [
                'operation' => $operation,
                'message' => $this->lastSystemInformation,
            ]);
        }
    }

    /** @param list<\VeryCodeCom\DpdDe\Dto\ErrorData> $errors */
    private function handleApiError(string $operation, array $errors, string $rawResponse): never
    {
        $this->logger->error("DPD Cloud: {$operation} failed", [
            'errors' => array_map(static fn ($e) => $e->toArray(), $errors),
        ]);

        $authCodes = ['CLOUD_API_PARTNERCREDENTIALS', 'CLOUD_API_USERCREDENTIALS', 'CLOUD_API_NOLOGIN', 'CLOUD_API_NOUSERACCESS'];

        foreach ($errors as $e) {
            // Throttling, not bad credentials, surfaced as its own type so callers can back off.
            if ($e->errorCode === 'CLOUD_API_USERCALLLIMIT') {
                $this->fail(
                    new DpdCloudRateLimitException(
                        "DPD Cloud Service call limit reached [{$e->errorCode}]: {$e->errorMsgLong}",
                        $e->errorCode,
                    ),
                    $rawResponse,
                );
            }

            if (in_array($e->errorCode, $authCodes, true)) {
                $this->fail(
                    new DpdCloudAuthException(
                        "DPD Cloud Service authentication failed [{$e->errorCode}]: {$e->errorMsgLong}",
                        $e->errorCode,
                    ),
                    $rawResponse,
                );
            }
        }

        $detail = implode('; ', array_filter(array_map(
            static fn ($e) => $e->errorMsgLong !== '' ? $e->errorMsgLong : $e->errorMsgShort,
            $errors,
        )));

        $this->fail(
            new DpdCloudApiException(
                trim("DPD Cloud Service {$operation} failed: {$detail}") ?: "DPD Cloud Service {$operation} failed without a detailed error message.",
                array_map(static fn ($e) => $e->toArray(), $errors),
            ),
            $rawResponse,
        );
    }

    /**
     * Attach the raw DPD response to an exception and, when debugging is enabled, log a full
     * debug report (message + raw response + stack trace) before throwing. Centralises error
     * surfacing for all API methods.
     */
    private function fail(DpdCloudException $exception, ?string $rawResponse = null): never
    {
        if ($rawResponse !== null) {
            $exception->withRawResponse($rawResponse);
        }

        if ($this->config->debug) {
            $this->logger->error($exception->getDebugReport());
        }

        throw $exception;
    }
}
