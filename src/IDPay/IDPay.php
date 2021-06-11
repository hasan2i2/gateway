<?php

namespace hugenet\Gateway\IDPay;

use hugenet\Gateway\Asanpardakht\IDPayException;
use hugenet\Gateway\Enum;
use hugenet\Gateway\PortAbstract;
use hugenet\Gateway\PortInterface;

class IDPay extends PortAbstract implements PortInterface
{
    /**
     * Address of main CURL server
     *
     * @var string
     */
    protected $serverUrl = 'https://api.idpay.ir/v1/payment';

    /**
     * Address of CURL server for verify payment
     *
     * @var string
     */
    protected $serverVerifyUrl = 'https://api.idpay.ir/v1/payment/inquiry';
    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://api.idpay.ir/v1/payment';


    protected $factorNumber;

    /**
     * {@inheritdoc}
     */
    public function set($amount)
    {
        $this->amount = $amount;
        return $this;
    }

    /**
     * تعیین شماره فاکتور (اختیاری)
     *
     * @param $factorNumber
     *
     * @return $this
     */
    public function setFactorNumber($factorNumber)
    {
        $this->factorNumber = $factorNumber;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function ready()
    {
        $this->sendPayRequest();
        return $this;
    }

    private function setGateUrl($url)
    {
        $this->gateUrl = $url;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        return redirect()->to($this->gateUrl);
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify($transaction);
        $this->userPayment();
        $this->verifyPayment();
        return $this;
    }

    /**
     * Sets callback url
     *
     * @param $url
     */
    function setCallback($url)
    {
        $this->callbackUrl = $url;
        return $this;
    }

    /**
     * Gets callback url
     * @return string
     */
    function getCallback()
    {
        if (!$this->callbackUrl)
            $this->callbackUrl = $this->config->get('gateway.payir.callback-url');
        return ($this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]));

    }

    /**
     * Send pay request to server
     *
     * @return void
     *
     * @throws IDPayException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();
        //dd($this->getCallback());
        $fields = [
            'X-API-KEY' => $this->config->get('gateway.idpay.api'),
            'X-SANDBOX' => $this->config->get('gateway.idpay.sandbox'),
            'amount' => $this->amount,
            'callback' => $this->getCallback(),
            'phone' => $this->config->get('gateway.idpay.phone')
        ];

        if (isset($this->factorNumber))
            $fields['order_id'] = $this->factorNumber;

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->serverUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json',
            'X-SANDBOX: true',
            'X-API-KEY: 6a7f99eb-7c20-4412-a972-6dfb7cd253a4'
        ));
        $response = curl_exec($ch);
        $response = json_decode($response, true);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpcode == '201') {
            if (isset($response['id']) && isset($response['link'])) {
                $this->refId = $response['id'];
                $this->transactionSetRefId();
                $this->setGateUrl($response['link']);
                return true;
            }
        } else {
            $this->transactionFailed();
            dd($response);
            $this->newLog($response['error_code'], IDPayException::$errors[$response['error_code']]);
            throw new IDPayException($response['error_code']);
        }
    }

    /**
     * Check user payment with GET data
     *
     * @return bool
     *
     * @throws IDPayException
     */
    protected function userPayment()
    {
        $status = request()->post('status');
        $transId = request()->post('order_id');
        $this->cardNumber = request()->post('card_no');
        $message = "";
        if (is_numeric($status) && $status == 100) {
            $this->trackingCode = $transId;
            return true;
        }
        $this->transactionFailed();
        $this->newLog($status, $message);
        throw new IDPayException($status);
    }

    /**
     * Verify user payment from zarinpal server
     *
     * @return bool
     *
     * @throws IDPayException
     */
    protected function verifyPayment()
    {
        $fields = [
            'X-API-KEY' => $this->config->get('gateway.payir.api'),
            'X-SANDBOX' => $this->config->get('gateway.idpay.sandbox'),
            'id' => $this->refId(),
            'order_id'=>$this->trackingCode
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->serverVerifyUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type:application/json',
            'X-SANDBOX: true',
            'X-API-KEY: 6a7f99eb-7c20-4412-a972-6dfb7cd253a4'
        ));

        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $response = json_decode($response, true);
        curl_close($ch);

        if ($response['status'] == 100) {
            $this->transactionSucceed();
            $this->newLog(100, Enum::TRANSACTION_SUCCEED_TEXT);
            return true;
        }

        $this->transactionFailed();
        $this->newLog($response['error_code'], IDPayException::$errors[$response['error_code']]);
        throw new IDPayException($response['error_code']);
    }
}
