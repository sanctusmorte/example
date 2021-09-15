<?php
namespace app\helpers;

use app\modules\komtet\models\Invoice;
use Yii;
use yii\base\InvalidConfigException;
use yii\db\ActiveRecord;
use yii\web\{
    NotFoundHttpException,
    ServerErrorHttpException,
    BadRequestHttpException
};
use app\modules\komtet\models\Setting;

class KomtetHelper
{
    /** Получение массива данных для создания задачи на фиксализацию в Комтет
     *  https://kassa.komtet.ru/integration/api#tag/Fiskalizaciya-platezhej%2Fpaths%2F~1kassa.komtet.ru~1api~1shop~1v1~1queues~1%7Bid%7D~1task%2Fpost
     *
     * @param array $paymentCreateData - массив с данными, которыый присылает retailCRM на коллбек интеграционного модуля payment/create
     * https://docs.retailcrm.ru/Developers/API/APIVersions/APIv5#callback_post--integrationModule_integrations__payment__actions__create_
     * @return mixed
     */

    public static function getDataForCreateNewFiscaliztaionTask(array $paymentCreateData)
    {
        $data = [
            'intent' => 'sell', // приход (используется при продаже товара или услуги) - указываем что создается ноавая фиксализация
            'external_id' => '', // Номер операции - наверное подразумевается paymentId который нам дает retailCRM
            'sno' => 0, // Система налогообложения.
            'user' => '', // Email или телефон
            'positions' => [], // список позиций в чеке (items)
            'payments' => [ // данные о платеже
                0 => [
                    'sum' => 0, // Общая сумма по чеку - должна быть такой же как и сумма стоимости каждого товара в positions
                    'type' => 'card' // вид оплаты
                ]
            ],
            'callback_url' => 'https://komtet-kassa-roznica.imb-service.ru/komtet/call'
        ];


        $errorMsg = null;

        if (isset($paymentCreateData['invoiceUuid'])) {
            $data['external_id'] = $paymentCreateData['invoiceUuid'];
        } else {
            $errorMsg = 'Не передан "external_id" (номер операции в вашей системе). В RetailCRM это "invoiceUuid"';
        }

        if (isset($paymentCreateData['items']) && count($paymentCreateData['items']) > 0) {
            foreach ($paymentCreateData['items'] as $orderItem) {
                $newPosition = [
                    'name' => $orderItem['name'],
                    'price' => $orderItem['price'],
                    'quantity' => $orderItem['quantity'],
                    'total' => $orderItem['price'] * $orderItem['quantity'],
                    'vat' => KomtetHelper::getVatInfo($orderItem)
                ];
                $data['positions'][] = $newPosition;
            }
        } else {
            $errorMsg = 'Не передан "positions" (товары в заказе, возможно их нет). В RetailCRM это "items"';
            return $errorMsg;
        }

        if (isset($paymentCreateData['amount'])) {
            $data['payments'][0]['sum'] = $paymentCreateData['amount'];
        } else {
            $errorMsg = 'Не передан "payments[0]/sum" (сумма платежа). В RetailCRM это "amount"';
        }

        if ($errorMsg !== null) {
            return [
                'success' => false,
                'errorMsg' => $errorMsg
            ];
        } else {
            return $data;
        }
    }

    /** Получение массива данных для отмены фиксализации
     *  https://kassa.komtet.ru/integration/api#tag/Fiskalizaciya-platezhej%2Fpaths%2F~1kassa.komtet.ru~1api~1shop~1v1~1queues~1%7Bid%7D~1task%2Fpost
     * В данном случае Комтет не имеет метода для отмены фиксализации
     * Проводим операцию как sellCorrection - коррекция прихода
     * Выставляем общую сумму оплаты и стоимость каждого товара как 0
     *
     * @param $existInvoice
     * @param $existKassa
     * https://docs.retailcrm.ru/Developers/API/APIVersions/APIv5#callback_post--integrationModule_integrations__payment__actions__create_
     * @return mixed
     */

    public static function getDataForCancelExistFiscaliztaionTask($existInvoice, $existKassa)
    {
        $data = [
            'intent' => 'sellCorrection', // приход (используется при продаже товара или услуги) - указываем что создается ноавая фиксализация
            'external_id' => $existInvoice->komtet_invoice_id,
            'sno' => 0, // Система налогообложения
            'printer_number' => $existKassa->printer_number,
            'correction' => [
                'type' => 'self',
                'date' => date('Y/m/d', time()),
                'document' => '',
                'description' => 'Отмена оплаты',

            ],
            'vat' => 'no',
            'payment_sum' => 0,
            'payment_type' => 'card',

        ];


        $errorMsg = null;

        if ($errorMsg !== null) {
            return [
                'success' => false,
                'errorMsg' => $errorMsg
            ];
        } else {
            return $data;
        }
    }

    /**
     * @param $orderItem
     * @return string
     */
    private static function getVatInfo($orderItem)
    {
        $vat = 'no';

        if (isset($orderItem['vat'])) {
            if (in_array($orderItem['vat'], ['none', null, false])) {
                $vat = 'no';
            } else {
                switch ($orderItem['vat']) {
                    case 'vat20':
                        $vat = '20';
                        break;
                    case 'vat10':
                        $vat = '10';
                        break;
                    case 'vat110':
                        $vat = '110';
                        break;
                    case 'vat120':
                        $vat = '120';
                        break;
                    default:
                        $vat = '0';
                }
            }
        }
        return $vat;
    }

    /** Получение массива данных для возврата фиксализации
     *  https://kassa.komtet.ru/integration/api#tag/Fiskalizaciya-platezhej%2Fpaths%2F~1kassa.komtet.ru~1api~1shop~1v1~1queues~1%7Bid%7D~1task%2Fpost
     * @param $existInvoice
     * https://docs.retailcrm.ru/Developers/API/APIVersions/APIv5#callback_post--integrationModule_integrations__payment__actions__create_
     * @return mixed
     */

    public static function getDataForRefundExistFiscaliztaionTask($existInvoice, $orderData)
    {

        $data = [
            'intent' => 'sellReturn', // приход (используется при продаже товара или услуги) - указываем что создается ноавая фиксализация
            'external_id' => $existInvoice->komtet_invoice_id,
            'sno' => 0, // Система налогообложения
            'payments' => [ // данные о платеже
                0 => [
                    'sum' => 0, // Общая сумма по чеку - должна быть такой же как и сумма стоимости каждого товара в positions
                    'type' => 'card' // вид оплаты
                ]
            ],
        ];

        $errorMsg = null;

        if (isset($orderData['summ'])) {
            $data['payments'][0]['sum'] = $orderData['summ'];
        } else {
            $errorMsg = 'Не передан "payments[0]/sum" (сумма платежа). В RetailCRM это "amount"';
        }

        if (isset($orderData['items']) && count($orderData['items']) > 0) {
            foreach ($orderData['items'] as $orderItem) {
                $newPosition = [
                    'name' => '',
                    'price' => $orderItem['initialPrice'],
                    'quantity' => $orderItem['quantity'],
                    'total' => $orderItem['initialPrice'] * $orderItem['quantity'],
                    'vat' => KomtetHelper::getVatInfo($orderItem)
                ];
                if (isset($orderItem['offer']['displayName'])) {
                    $newPosition['name'] = $orderItem['offer']['displayName'];
                }
                $data['positions'][] = $newPosition;
            }
        } else {
            $errorMsg = 'Не передан "positions" (товары в заказе, возможно их нет). В RetailCRM это "items"';
            return $errorMsg;
        }

        if ($errorMsg !== null) {
            return [
                'success' => false,
                'errorMsg' => $errorMsg
            ];
        } else {
            return $data;
        }
    }

    /** Получение аутентификационных данных для запроса на API komtet
     * @param $setting
     * @return array
     */
    public static function getAuthDataForKomtetApi($existKassa)
    {
        return [
            'shop_id' => $existKassa->komtet_shop_id,
            'secret_key' => $existKassa->komtet_secret_key,
        ];
    }

    /**
     * Формирование ФИО
     * @param array $orderRetailData - данные заказа
     * @return string
     */

    public static function getClientName(array $orderRetailData)
    {
        $clientName = '';

        if (isset($orderRetailData['lastName']) && $orderRetailData['lastName']) $clientName .= $orderRetailData['lastName'] . ' ';
        if (isset($orderRetailData['firstName']) && $orderRetailData['firstName']) $clientName .= $orderRetailData['firstName'] . ' ';
        if (isset($orderRetailData['patronymic']) && $orderRetailData['patronymic']) $clientName .= $orderRetailData['patronymic'];

        if ($clientName) return $clientName;
        else return 'Имя не указано';
        // throw new ServerErrorHttpException("Name is not specified in the order");
    }

    /**
     * Формирование адреса
     * @param array $orderRetailData - данные заказа
     * @return string
     */

    public static function getClientAddress(array $orderRetailData)
    {
        $clientAddress = '';

        if (isset($orderRetailData['delivery']['address']['region']) && $orderRetailData['delivery']['address']['region']) {
			$clientAddress .= $orderRetailData['delivery']['address']['region'].', ';
		}

		if (isset($orderRetailData['delivery']['address']['city']) && $orderRetailData['delivery']['address']['city']) {
			$clientAddress .= $orderRetailData['delivery']['address']['city'].', ';
		}

		if (isset($orderRetailData['delivery']['address']['text']) && $orderRetailData['delivery']['address']['text']) {
			$clientAddress .= $orderRetailData['delivery']['address']['text'].', ';
        }

        if ($clientAddress) {
            $clientAddress = substr($clientAddress, 0, -2);
            return $clientAddress;
        } else {
            return 'Адрес не указан';
        }
    }

    /**
     * Получения номера телефона
     * @param array $orderRetailData - данные заказа
     * @return string
     */

    public static function getClientPhone(array $orderRetailData)
    {
        if (isset($orderRetailData['phone']) && $orderRetailData['phone']) {
            return str_replace([' ', '_', '-', '(', ')'], '', $orderRetailData['phone']);
        } else {
            // throw new ServerErrorHttpException("Phone is not specified in the order");
            return '79999999999';
        }
    }

    /**
     * Получения статуса
     * @param array $orderRetailData - данные заказа
     * @param object $setting
     * @return string
     */

    public static function getStatus(array $orderRetailData, Setting $setting)
    {
        if ($orderStatusesMatching = $setting->orderStatusesMatching) {
            if ($statusData = \yii\helpers\Json::decode($orderStatusesMatching->data, true)) {
                return $statusData[$orderRetailData['status']] ?? 'new';
            }
        }
        return 'new';
    }

    /**
     * Оплачен ли заказ
     * @param array $orderRetailData - данные заказа
     * @return array
     */
    static function isPaid(array $orderRetailData): array
    {
        if (isset($orderRetailData['payments'])) {
            $prepayment = 0;

            foreach ($orderRetailData['payments'] as $payment) {
                if (isset($payment['status']) && $payment['status'] == 'paid') {
                    $prepayment += $payment['amount'];
                }
            }

            return [
                ($orderRetailData['totalSumm'] == $prepayment) ? true : false,
                $prepayment
            ];
        }

        return [
            false,
            0
        ];
    }

    /**
     * Дата и время доставки (с).
     * Дата и время доставки (до).
     * @param array $orderRetailData - данные заказа
     * @return array
     */
    public static function getDeliveryDateTime(array $orderRetailData, Setting $setting): array
    {
        $date_start = false;
        $date_end = false;

        if (isset($orderRetailData['delivery']['date']) && $orderRetailData['delivery']['date'] != '') {
			$date = $orderRetailData['delivery']['date'];
        } else {
            $date = Yii::$app->formatter->asDate(time(), 'php:Y-m-d');
        }

        if (isset($orderRetailData['delivery']['time']['from']) && $orderRetailData['delivery']['time']['from'] != '') {
			$date_start = $date . ' ' . $orderRetailData['delivery']['time']['from'];
        }

        if (isset($orderRetailData['delivery']['time']['to']) && $orderRetailData['delivery']['time']['to'] != '') {
			$date_end = $date . ' ' . $orderRetailData['delivery']['time']['to'];
        }

        if (!$date_start && !$date_end && $setting->default_start_time && $setting->default_end_time) {
            if ($setting->default_date) {
                $date = self::getDate($setting->default_date);
            } else {
                $date = date('Y-m-d');
                $date = date('Y-m-d');
            }

            $date_start = $date . ' ' . $setting->default_start_time;
            $date_end = $date . ' ' . $setting->default_end_time;
        }

        if (!$date_start && !$date_end) {
            if (isset($orderRetailData['delivery']['date']) && $orderRetailData['delivery']['date'] != '') {
                $date = $orderRetailData['delivery']['date'];
            } else {
                $date = Yii::$app->formatter->asDate(time(), 'php:Y-m-d');
            }

            $date_start = $date . ' 00:00';
            $date_end = $date . ' 23:59';
        }

        return [
            $date_start,
            $date_end
        ];
    }

    /**
     * @param string $default_date
     * @return string
     * @throws InvalidConfigException
     */
    private static function getDate(string $default_date): string
    {
        switch ($default_date) {
            case 'tomorrow':
                $strtotime = strtotime("+1 day");
                $date = Yii::$app->formatter->asDate($strtotime, 'php:Y-m-d');
                break;
            case 'day_after_tomorrow':
                $strtotime = strtotime("+2 day");
                $date = Yii::$app->formatter->asDate($strtotime, 'php:Y-m-d');
                break;
            default:
                $date = Yii::$app->formatter->asDate(time(), 'php:Y-m-d');
                break;
        }
        return $date;
    }

    /**
     * Получения массива товаров
     *
     * @param array $orderRetailData - данные заказа
     * @return array
     */
    public static function getItems(array $orderRetailData): array
    {
        $data = [];

        if (isset($orderRetailData['items']) && $orderRetailData['items']) {
            foreach($orderRetailData['items'] as $item) {
                if (isset($item['discountTotal']) && $item['discountTotal']) {
                    $price = $item['initialPrice'] - $item['discountTotal'];
                } else {
                    $price = $item['initialPrice'];
                }

                $data[] = [
                    'external_id' => $item['offer']['externalId'] ?? 0,
					'type'	=> 'product',
					'name'	=> $item['offer']['displayName'],
					'price'	=>  (float)$price,
					'quantity'	=> $item['quantity'],
					'total'	=> (float)$price * $item['quantity'],
					'vat'	=> self::getVatRate($item['vatRate'] ?? null),
                ];
            }
        }

        $deliveryCost = (float)$orderRetailData['delivery']['cost'] ?? 0;
		$data[] = [
			'external_id' => 'delivery_'.$orderRetailData['id'],
			'type'	=> 'service',
			'name'	=> 'Стоимость доставки',
			'price'	=> (float)$deliveryCost,
			'quantity'	=> 1,
			'total'	=> (float)$deliveryCost,
			'vat'	=> 'no',
        ];

        return $data;
    }

    /**
     * @param array $orderRetailData
     * @param Setting $setting
     * @return int|null
     */
    public static function getCourierId(array $orderRetailData, Setting $setting): ?int
    {
        if ($couriersData = \yii\helpers\Json::decode($setting->couriersMatching->data, true) ?? null) {
            if (isset($orderRetailData['delivery']['data']['courierId'])) {

                foreach ($couriersData as $courierData) {
                    if ($courierData['retail_courier'] == $orderRetailData['delivery']['data']['courierId']) {
                        return (int)$courierData['komtet_courier'];
                    }
                }
            }
        }
        return null;
    }

    /**
     * @param array $orderRetailData
     * @param Setting $setting
     * @return string
     */
    public static function getPaymentType(array $orderRetailData, Setting $setting): string
    {
        if ($paymentData = \yii\helpers\Json::decode($setting->paymentTypesMatching->data, true) ?? null) {
            if ($payment = array_shift($orderRetailData['payments'])) {
                return $paymentData[$payment['type']] ?? 'cash';
            }
        }
        return 'cash';
    }

    /**
     * getVatRate
     * @param string
     * @return string
     */
    private static function getVatRate($vatRate = null): string
    {
        if ($vatRate && $vatRate !== 'none') {
            return (string)(int)$vatRate;
        }
        return 'no';
    }

    /**
     * Получение комментария + (добавление номера заказа)
     *
     * @param array $orderRetailData
     * @return string
     */
    public static function getDescription(array $orderRetailData): string
    {
        $comment = '';

        if (isset($orderRetailData['number']) && $orderRetailData['number']) {
            $comment .= 'Номер заказа - ' . $orderRetailData['number'];
        }

        if (isset($orderRetailData['customerComment']) && $orderRetailData['customerComment']) {
            $comment .= ' ' . $orderRetailData['customerComment'];
        }

        return $comment;
    }
}
