<?php

namespace hugenet\Gateway\Sadad;

use SoapClient;
use DateTime;
use hugenet\Gateway\PortAbstract;
use hugenet\Gateway\PortInterface;
class Sadad extends PortAbstract implements PortInterface
{
    /**
     * Url of sadad gateway web service
     *
     * @var string
     */
    protected $serverUrl = 'https://sadad.shaparak.ir/vpg/api/v0/Request/PaymentRequest';

    /**
     * Address of gate for redirect
     *
     * @var string
     */
    protected $gateUrl = 'https://sadad.shaparak.ir/VPG/Purchase?Token=';

    /**
     * Address of server for verify payment
     *
     * @var string
     */
    protected $serverVerifyUrl = 'https://sadad.shaparak.ir/VPG/api/v0/Advice/Verify';

    /**
     * {@inheritdoc}
     */



    /**
     * {@inheritdoc}
     */
    public function set($amount)
    {
        $this->amount = intval($amount);

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

    function setCallback($url)
    {
        $this->callbackUrl = $url;
        return $this->redirect();
    }

    /**
     * Gets callback url
     * @return string
     */
    function getCallback()
    {
        if (!$this->callbackUrl)
            $this->callbackUrl = config('gateway.sadad.callback-url');

        return $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        return redirect($this->gateUrl.$this->refId());
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
     * @throws SadadException
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();

        try {
            $ch = curl_init();

            $date = new DateTime;

            $fields = array(
                'TerminalId' => config('gateway.sadad.terminalId'),
                'MerchantId' => config('gateway.sadad.merchant'),
                'Amount' => $this->amount,
                'SignData' =>  $this->encryptPKCS7(config('gateway.sadad.terminalId').";".$this->transactionId().";".$this->amount, config('gateway.sadad.transactionKey')),
                'LocalDateTime' => $date->format('Y-m-d H:i:s'),
                'OrderId' => $this->transactionId(),
                'ReturnUrl' => $this->buildQuery($this->getCallback(), array('transaction_id' => $this->transactionId())),
                'UserId' => auth()->user()->id
            );
            $data = json_encode($fields);

            curl_setopt($ch, CURLOPT_URL, $this->serverUrl);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($data)));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            curl_close($ch);

            $response = json_decode($response, true);
            if (is_array($response) && isset($response['ResCode']) && $response['ResCode'] == 0) {

                $this->refId = $response['Token'];
                $this->transactionSetRefId();

                return true;
            }

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw $e;
        }


        $this->transactionFailed();
        $this->newLog(@$response['ResCode'], @SadadException::$errors[@$response['ResCode']]);
        throw SadadException::sendPayRequestError(@$response['ResCode']);
    }

    /**
     * Check user payment
     *
     * @return bool
     *
     * @throws SadadException
     */
    protected function userPayment()
    {
        $this->refId = @$_POST['token'];
        $resCode = @$_POST['ResCode'];

        if ($resCode == '0') {
            return true;
        }

        $this->transactionFailed();
        $this->newLog($resCode, @SadadException::$userPaymentErrors[$resCode]);
        throw SadadException::userPaymentError($resCode);
    }

    /**
     * Verify user payment from bank server
     *
     * @throws SadadException
     */
    protected function verifyPayment()
    {
        try {
            $ch = curl_init();

            $date = new DateTime;

            $fields = array(
                'Token' => $this->refId(),
                'SignData' =>  $this->encryptPKCS7($this->refId(),config('gateway.sadad.transactionKey')),
            );
            $data = json_encode($fields);

            curl_setopt($ch, CURLOPT_URL, $this->serverVerifyUrl);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($data)));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            curl_close($ch);

            $response = json_decode($response, true);
            if (is_array($response) && isset($response['ResCode']) && $response['ResCode'] == 0) {

                $this->refId = $response['RetrivalRefNo'];
                $this->trackingCode = $response['SystemTraceNo'];
                $this->transactionSetRefId();
                $this->transactionSucceed();
                $this->newLog($response['ResCode'], "عملیات با موفقیت انجام شد");
                return true;
            }

        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('Error', $e->getMessage());
            throw $e;
        }

        $this->transactionFailed();
        $this->newLog(@$response['ResCode'], @SadadException::$veridyPaymentErrors[@$response['ResCode']]);
        throw SadadException::verifyPaymentError(@$response['ResCode']);
    }

    /**
     * Create sign data based on (Tripledes(ECB,PKCS7)) algorithm
     *
     * @param string data
     * @param sring key
     *
     * @return string
     */
    private function encryptPKCS7($data, $key)
    {
        $key = base64_decode($key);
        $ciphertext = OpenSSL_encrypt($data, "DES-EDE3", $key, OPENSSL_RAW_DATA);
        return base64_encode($ciphertext);
    }
    protected function buildQuery($url, array $query)
    {
        $query = http_build_query($query);

        $questionMark = strpos($url, '?');
        if (!$questionMark)
            return "$url?$query";
        else {
            return substr($url, 0, $questionMark + 1).$query."&".substr($url, $questionMark + 1);
        }
    }
}