<?php

declare(strict_types=1);

namespace App\Services\Gateway\Cryptomus;

final class Payment
{
    private RequestBuilder $requestBuilder;
    private string $version = 'v1';

    public function __construct(string $paymentKey, string $merchantUuid)
    {
        $this->requestBuilder = new RequestBuilder($paymentKey, $merchantUuid);
    }

    /**
     * @param array $parameters Additional parameters
     *
     * @return bool|mixed
     *
     * @throws RequestBuilderException
     */
    public function services(array $parameters = [])
    {
        return $this->requestBuilder->sendRequest($this->version . '/payment/services', $parameters);
    }
    public function create(array $data)
    {
        return $this->requestBuilder->sendRequest($this->version . '/payment', $data);
    }

    public function info($data = [])
    {
        return $this->requestBuilder->sendRequest($this->version . '/payment/info', $data);
    }

    /**
     * @param string|int $page Pagination cursor
     * @param array $parameters Additional parameters
     *
     * @return bool|mixed
     *
     * @throws RequestBuilderException
     */
    public function history($page = 1, array $parameters = [])
    {
        $data = array_merge($parameters, ['cursor' => strval($page)]);
        return $this->requestBuilder->sendRequest($this->version . '/payment/list', $data);
    }

    /**
     * @param array $parameters Additional parameters
     *
     * @return bool|mixed
     *
     * @throws RequestBuilderException
     */
    public function balance(array $parameters = [])
    {
        return $this->requestBuilder->sendRequest($this->version . '/balance', $parameters);
    }
    public function reSendNotifications(array $data)
    {
        return $this->requestBuilder->sendRequest($this->version . '/payment/resend', $data);
    }

    public function createWallet(array $data)
    {
        return $this->requestBuilder->sendRequest($this->version . '/wallet', $data);
    }
}
