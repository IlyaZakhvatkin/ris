<?php

namespace Gorobmen\Payment\Services;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use CIBlockElement;
use Gorobmen\Payment\Orders;
use Gorobmen\Payment\SbpSber;

class CheckSbp {
    /** Чистая логика проверки СБП, доступная и для Крона, и для Контроллера */
    public static function get(): array
    {
        Loader::includeModule('iblock');

        $out = [];
        $ib_order = Option::get('gorobmen.payment', 'payment_ib_order');

        $arEnumStatus = [];
        $rsStatus = \CIBlockPropertyEnum::GetList([], ['IBLOCK_ID' => $ib_order, 'CODE' => 'ORDER_STATUS']);
        while ($arStatus = $rsStatus->Fetch()) {
            $arEnumStatus[$arStatus['XML_ID']] = $arStatus;
        }

        $arSBP = [];
        $arErrorSBP = [];
        $arOrderSBP = [];
        $arPaid = [];
        $arErrorPaid = []; // Исправили баг: инициализируем массив, чтобы не было Warning
        $arNotPaid = [];

        $rs = CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $ib_order,
                '!PROPERTY_ORDER_STATUS' => $arEnumStatus['order_status_pay']['ID'],
                '!PROPERTY_ORDER_SBP_SBER' => false
            ],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'PROPERTY_ORDER_SBP_SBER', 'PROPERTY_ORDER_RSB']
        );

        while ($ar = $rs->Fetch()) {
            $arSBP[] = $ar['ID'];
            if (!empty($ar['PROPERTY_ORDER_SBP_SBER_VALUE'])) {
                $arSber = json_decode($ar['PROPERTY_ORDER_SBP_SBER_VALUE'], true);
                if (!empty($arSber) && (int)$arSber['error_code'] > 0) {
                    $arErrorSBP[] = $ar['ID'];
                }
                if (!empty($arSber['order_id']) && $arSber['order_state'] !== 'PAID') {
                    $arOrderSBP[] = [
                        'ID' => $ar['ID'],
                        'order_id' => $arSber['order_id'],
                        'order_state' => $arSber['order_state'],
                        'order_rsb' => $ar['PROPERTY_ORDER_RSB_VALUE']
                    ];
                }
            }
        }

        if (empty($arOrderSBP)) {
            return ['status' => 'ok', 'message' => 'Нет не оплаченных через СБП заказов!'];
        }

        Loader::includeModule('gorobmen.payment');
        $classSber = new SbpSber();

        foreach ($arOrderSBP as $sbp) {
            $status = $classSber->getStatus($sbp['order_id'], $sbp['ID']);
            if ($sbp['order_state'] != $status['order_state'] && !empty($status['order_id'])) {
                $arUpdProp = ['ORDER_SBP_SBER' => json_encode($status)];

                if ($status['order_state'] == "PAID") {
                    $arUpdProp['ORDER_STATUS'] = $arEnumStatus['order_status_pay']['ID'];
                    $post = [
                        'orderID' => $sbp['ID'],
                        'rsb' => $sbp['order_rsb'],
                        'method' => 'sbp-sber',
                        'dest' => 'СБП Сбер ' . $status['order_operation_params'][0]['response_desc'],
                        'sum' => $status['order_operation_params'][0]['operation_sum'] / 100,
                        'date' => date('d.m.Y H:i:s', strtotime($status['order_operation_params'][0]['operation_date_time'])),
                        'fiz' => 1
                    ];
                    $classOrder = new Orders($sbp['ID']);
                    $classOrder->addPaymentRSB($post);
                    $arPaid[] = $sbp['ID'];
                } else {
                    $arErrorPaid[] = $sbp['ID'];
                }
                CIBlockElement::SetPropertyValuesEx($sbp['ID'], $ib_order, $arUpdProp);
            } else {
                $arNotPaid[] = $sbp['ID'];
            }
            sleep(0.5);
        }

        $out[] = 'Всего не оплаченных заказов СБП: ' . count($arOrderSBP) . '.';
        if (!empty($arPaid)) $out[] = 'Оплачены при проверке: ' . implode(',', $arPaid);
        if (!empty($arNotPaid)) $out[] = 'Не оплачены при проверке: ' . implode(',', $arNotPaid);

        return ['status' => 'ok', 'result' => $out];
    }
}
