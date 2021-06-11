<?php

namespace hugenet\Gateway\Asanpardakht;

use hugenet\Gateway\Exceptions\BankException;

class IDPayException extends BankException
{

    public static $errors = array(
        1 => 'پرداخت انجام نشده است',
        2 => 'پرداخت ناموفق بوده است',
        3 => 'خطا رخ داده است',
        11 => "کاربر مسدود شده است.",
        12 => "API Key یافت نشد.",
        13 => "درخواست شما از {ip} ارسال شده است. این IP با IP های ثبت شده در وب سرویس همخوانی ندارد.",
        14 => "وب سرویس تایید نشده است.",
        21 => "حساب بانکی متصل به وب سرویس تایید نشده است.",
        22 => "وب سریس یافت نشد.",
        23 => "اعتبار سنجی وب سرویس ناموفق بود.",
        24 => "حساب بانکی مرتبط با این وب سرویس غیر فعال شده است.",
        31 => "کد تراکنش id نباید خالی باشد.",
        32 => "شماره سفارش order_id نباید خالی باشد.",
        33 => "مبلغ amount نباید خالی باشد.",
        34 => "مبلغ amount باید بیشتر از {min-amount} ریال باشد.",
        35 => "مبلغ amount باید کمتر از {max-amount} ریال باشد.",
        36 => "مبلغ بیشتر از حد مجاز است.",
        37 => "آدرس بازگشت callback نباید خالی باشد.",
        38 => "درخواست شما از آدرس {domain} ارسال شده است. دامنه آدرس بازگشت callback با آدرس ثبت شده در وب سرویس همخوانی ندارد.",
        51 => "تراکنش ایجاد نشد.",
        52 => "استعلام نتیجه ای نداشت.",
        100 => 'پرداخت تایید شده است'
    );

    public function __construct($errorRef)
    {
        $this->errorRef = $errorRef;

        $message = self::getMessageByCode($this->errorRef);

        parent::__construct($message . ' (' . $this->errorRef . ')', intval($this->errorRef));
    }


    public static function getMessageByCode($code)
    {

        $message = "";
        if (isset(self::$errors[$code]))
            $message = self::$errors[$code];
        else if (is_numeric($code) && isset(self::$errors[intval($code)]))
            $message = self::$errors[intval($code)];

        return $message;
    }


}
