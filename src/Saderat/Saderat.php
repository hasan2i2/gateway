<?php

namespace hugenet\Gateway\Saderat;

use Illuminate\Support\Facades\Input;
use DateTime;
use hugenet\Gateway\Enum;
use SoapClient;
use hugenet\Gateway\PortAbstract;
use hugenet\Gateway\PortInterface;

class Saderat extends PortAbstract implements PortInterface
{
    /**
     * Address of main SOAP server
     *
     * @var string
     */
    protected $serverUrl = "https://mabna.shaparak.ir/TokenService?wsdl";

    /**
     * Address of verify SOAP server
     *
     * @var string
     */
    protected $verifyUrl = "https://mabna.shaparak.ir/TransactionReference/TransactionReference?wsdl";

    /**
     * Public key
     *
     * @var mixed
     */
    private $publicKey = null;

    /**
     * Private key
     *
     * @var mixed
     */
    private $privateKey = null;


    /**
     * {@inheritdoc}
     */
    public function set($amount)
    {
        $this->amount = $amount;

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

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        $token = $this->refId;
        return view('gateway::saderat-redirector')->with([
            'token' => $token
        ]);
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
     * Send pay request to server
     *
     * @return void
     *
     * @throws MellatException
     */
    protected function sendPayRequest()
    {
        $dateTime = new DateTime();

        $this->newTransaction();
        $this->setKeys();

        $fields = array(
            "Token_param" => array(
                "AMOUNT" => $this->getEncryptedAmount(),
                "CRN" => $this->getEncryptedTrancactionId(),
                "MID" => $this->getEncryptedMerchantId(),
                "REFERALADRESS" => $this->getEncryptedCallbackUrl(),
                "SIGNATURE" => $this->createSignature(),
                "TID" => $this->getEncryptedTerminalId()
            )
        );

        try {
            // Disable SSL
            $soap = new SoapClient($this->serverUrl,
                array("stream_context" => stream_context_create(
                    array(
                        'ssl' => array(
                            'verify_peer'       => false,
                            'verify_peer_name'  => false,
                        )
                    )
                ),
                    'soap' => array(
                        'attempts' => 2 // Attempts if soap connection is fail
                    )

                ));
            $response = $soap->reservation($fields);

        } catch(\SoapFault $e) {
            $this->transactionFailed();
            $this->newLog('SoapFault', $e->getMessage());
            throw $e;
        }

        if ($response->return->result != 0) {
            $this->transactionFailed();
            $this->newLog($response->return->result, SaderatException::getError($response->return->result));
            throw new SaderatException($response->return->result);
        }

        $this->refId = $response->return->token;
        $this->transactionSetRefId($this->transactionId);

        $result = openssl_verify($response->return->token, base64_decode($response->return->signature), $this->publicKey);

        if ($result != 1) {
            $this->transactionFailed();
            $this->newLog('Signature', SaderatException::getError('gateway-faild-signature-verify'));
            throw new SaderatException('gateway-faild-signature-verify');
        }
    }

    /**
     * Generate public and private keys
     *
     * @return void
     */
    protected function setKeys()
    {
        $pub_key = file_get_contents(config('gateway.saderat.public-key'));
        $pub_key = '-----BEGIN PUBLIC KEY-----' . PHP_EOL . $pub_key. PHP_EOL ."-----END PUBLIC KEY-----";
        $this->publicKey = openssl_pkey_get_public($pub_key);

        $pri_key = file_get_contents(config('gateway.saderat.private-key'));
        $pri_key = "-----BEGIN PRIVATE KEY-----" . PHP_EOL . $pri_key. PHP_EOL ."-----END PRIVATE KEY-----";


        $this->privateKey = openssl_pkey_get_private($pri_key);
    }

    /**
     * Encrypt amount
     *
     * @return string
     */
    protected function getEncryptedAmount()
    {
        openssl_public_encrypt($this->amount,$crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    /**
     * Encrypt transaction id as CRN
     *
     * @return string
     */
    protected function getEncryptedTrancactionId()
    {
        openssl_public_encrypt($this->transactionId(), $crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    /**
     * Encrypt merchant id
     *
     * @return string
     */
    protected function getEncryptedMerchantId()
    {
        openssl_public_encrypt(config('gateway.saderat.merchant-id'), $crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    /**
     * Encrypt termianl id
     *
     * @return string
     */
    protected function getEncryptedTerminalId()
    {
        openssl_public_encrypt(config('gateway.saderat.terminal-id'), $crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    /**
     * Encrypt callback url
     *
     * @return string
     */
    protected function getEncryptedCallbackUrl()
    {
        $callBackUrl = $this->getCallback();
        openssl_public_encrypt($callBackUrl, $crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    /**
     * Encrypt tracking code
     *
     * @return string
     */
    protected function getEncryptedTrackingCode()
    {
        openssl_public_encrypt($this->trackingCode(), $crypted, $this->publicKey);
        return base64_encode($crypted);
    }

    /**
     * Create and encrypt signature
     *
     * @return string
     */
    protected function createSignature()
    {
        $data = $this->amount.$this->transactionId().config('gateway.saderat.merchant-id').
            $this->getCallback().
            config('gateway.saderat.terminal-id');

        openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA1);
        return base64_encode($signature);
    }

    /**
     * Create and encrypt verify signature
     *
     * @return string
     */
    protected function createVerifySignature()
    {
        $data = config('gateway.saderat.merchant-id').$this->trackingCode().$this->transactionId();

        openssl_sign($data, $signature, $this->privateKey, OPENSSL_ALGO_SHA1);
        return base64_encode($signature);
    }

    /**
     * Check user payment
     *
     * @return void
     */
    protected function userPayment()
    {
        if (empty($_POST) || Input::get('RESCODE') != '00') {
            $this->transactionFailed();
            $this->newLog(Input::get('RESCODE'), SaderatException::getError(Input::get('RESCODE')));
            throw new SaderatException(Input::get('RESCODE'));
        }
    }

    /**
     * Verify user payment from bank server
     *
     * @return void
     */
    protected function verifyPayment()
    {
        $this->setKeys();

        $this->trackingCode = Input::get('TRN');

        $fields = array(
            "SaleConf_req" => array(
                "CRN" => $this->getEncryptedTrancactionId(),
                "MID" => $this->getEncryptedMerchantId(),
                "TRN" => $this->getEncryptedTrackingCode(),
                "SIGNATURE" => $this->createVerifySignature()
            )
        );

        try {
            $soap = new SoapClient($this->verifyUrl,
                array("stream_context" => stream_context_create(
                    array(
                        'ssl' => array(
                            'verify_peer'       => false,
                            'verify_peer_name'  => false,
                        )
                    )
                ),
                    'soap' => array(
                        'attempts' => 2 // Attempts if soap connection is fail
                    )

                ));
            $response = $soap->sendConfirmation($fields);

        } catch(\SoapFault $e) {
            $this->transactionFailed();
            $this->newLog('SoapFault', $e->getMessage());
            throw $e;
        }

        if (empty($_POST) || Input::get('RESCODE') != '00') {
            $this->transactionFailed();
            $this->newLog(Input::get('RESCODE'), SaderatException::getError(@Input::get('RESCODE')));
            throw new SaderatException(Input::get('RESCODE'));
        }
        if ($response->return->RESCODE != '00') {
            $this->transactionFailed();
            $this->newLog($response->return->RESCODE, SaderatException::getError($response->return->RESCODE));
            throw new SaderatException($response->return->RESCODE);
        }

        $data = $response->return->RESCODE.$response->return->REPETETIVE.$response->return->AMOUNT.
            $response->return->DATE.$response->return->TIME.$response->return->TRN.$response->return->STAN;

        $result = openssl_verify($data, base64_decode($response->return->SIGNATURE), $this->publicKey);

        if ($result != 1) {
            $this->transactionFailed();
            $this->newLog('Signature', SaderatException::getError('gateway-faild-signature-verify'));
            throw new SaderatException('gateway-faild-signature-verify');
        }

        $this->transactionSucceed();
        $this->newLog('00', Enum::TRANSACTION_SUCCEED_TEXT);
        return true;
    }

    /**
     * Sets callback url
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
        if (!$this->callbackUrl) {
            $this->callbackUrl = config('gateway.saderat.callback-url');
        }
        return $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);
    }
}
