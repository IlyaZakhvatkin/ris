<?php
namespace Gorobmen\Payment\Services;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Gorobmen\Payment\Main;
use Gorobmen\Payment\Orders;
use Gorobmen\Payment\PaymentTable;
use Gorobmen\Reestr\Kontur;

class OrderAutoCreate
{
    public static function getOrder(array $post, array $req)
    {
        global $USER;
        if (php_sapi_name() == 'cli' && empty($_SERVER['REMOTE_ADDR'])) {
            $USER->Authorize(1);
        }

        Loader::includeModule('gorobmen.payment');
        Loader::includeModule('gorobmen.reestr');

        $iblock_order_id = Option::get('gorobmen.payment', 'payment_ib_order');
        $iblock_tarif_id = Option::get('gorobmen.payment', 'payment_ib_tarif');
        $iblock_service_id = Option::get('gorobmen.payment', 'payment_ib_service');
        $ib_biz = Option::get('gorobmen.payment', 'payment_ib_biz', 26);
        $log_type = 'orders';

        // оформляем заказы из админки
        $manualRSBBiz = [];
        if (!empty($post['manual']) && !empty($post['rsb'])) {
            $manualRSBBiz = $post['rsb'];
        }

        // статус заказа Оплачен/Не оплачен
        $arOrderStatus = [];
        $rsEnumStatus = \CIBlockPropertyEnum::GetList(["DEF" => "DESC", "SORT" => "ASC"], ["IBLOCK_ID" => $iblock_order_id, "CODE" => "ORDER_STATUS"]);
        while ($arEnumStatus = $rsEnumStatus->GetNext()) {
            $arOrderStatus[$arEnumStatus['XML_ID']] = $arEnumStatus['ID'];
        }

        // тип заказа Админ/Авто
        $arEnumOrderType = [];
        $rsEnumOT = \CIBlockPropertyEnum::GetList(["DEF" => "DESC", "SORT" => "ASC"], ["IBLOCK_ID" => $iblock_order_id, "CODE" => "ORDER_TYPE"]);
        while ($arEnumOT = $rsEnumOT->GetNext()) {
            $arEnumOrderType[$arEnumOT['XML_ID']] = $arEnumOT['ID'];
        }

        // выбираем все тарифы
        $arTarifs = [];
        $rsTar = \CIBlockElement::GetList([], ['IBLOCK_ID' => $iblock_tarif_id, 'ACTIVE' => 'Y'], false, false,
            ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'PROPERTY_TARIF_PRICE']);
        while ($arTar = $rsTar->Fetch()) {
            $arTarifs[$arTar['CODE']] = $arTar;
        }

        // выбираем платные услуги
        $arServices = [];
        $rsServ = \CIBlockElement::GetList([], ['IBLOCK_ID' => $iblock_service_id, 'ACTIVE' => 'Y'], false, false,
            ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'PROPERTY_SERVICE_PRICE', 'PROPERTY_SERVICE_PRICE_GOR', 'PROPERTY_SERVICE_CODE_PROP']);
        while ($arServ = $rsServ->Fetch()) {
            $arServices[] = $arServ;
        }

        // выбираем все активные РСБ из бизнес-единиц, сортируем РСБ первым
        $arBiz = [];
        $arRSBID = [];
        $arBizFilter = ['IBLOCK_ID' => $ib_biz, 'ACTIVE' => 'Y'];

        // ограничиваем выбор РСБ
        if (!empty($manualRSBBiz))
            $arBizFilter['IBLOCK_SECTION_ID'] = $manualRSBBiz;
        if (!empty($req['action']) && $req['action'] == 'update' && !empty($req['sectionID'])) {
            $arBizFilter['IBLOCK_SECTION_ID'] = $req['sectionID'];
        }
        $rsBZ = \CIBlockElement::GetList(['IBLOCK_SECTION_ID' => 'ASC', 'PROPERTY_BIZ_MANAGER' => 'DESC'],
            $arBizFilter, false, false, ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID',
                'PROPERTY_BIZ_REALTOR', 'PROPERTY_BIZ_MANAGER']);

        while ($obBZ = $rsBZ->GetNextElement()) {
            $arFields = $obBZ->GetFields();
            $arProps = $obBZ->GetProperties();
            $arBiz[$arFields['IBLOCK_SECTION_ID']][] = array_merge($arFields, $arProps);
            if ($arFields['PROPERTY_BIZ_MANAGER_VALUE'] == 'Y') {
                $arRSBID[] = $arFields['PROPERTY_BIZ_REALTOR_VALUE'];
            }
        }

        // получаем статус и фио рсб
        $arUserStatus = [];
        $rsEnumUS = \CUserFieldEnum::GetList([], ["USER_FIELD_NAME" => "UF_USER_STATUS"]);
        while ($arEnumUS = $rsEnumUS->GetNext()) {
            $arUserStatus[$arEnumUS['ID']] = $arEnumUS['XML_ID'];
        }
        $statusRSB = [];
        $fioRSB = [];
        $by = 'ID';
        $ord = 'ASC';
        $rsUs = \CUser::GetList($by, $ord, ['ID' => implode("|", $arRSBID)],
            ['FIELDS' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME'], 'SELECT' => ['UF_USER_STATUS']]);
        while ($arUS = $rsUs->Fetch()) {
            $statusRSB[$arUS['ID']] = $arUserStatus[$arUS['UF_USER_STATUS']];
            $fioRSB[$arUS['ID']] = $arUS['LAST_NAME'] . ' ' . $arUS['NAME'] . ' ' . $arUS['SECOND_NAME'];
        }

        // определяем затраты на рекламу за предыдущий месяц для всех РСБ
        $date_order_start = date("d.m.Y", strtotime("first day of previous month"));
        $date_order_end = date("d.m.Y", strtotime("last day of previous month"));
        $arPriceReclam = Main::getPriceReclam($arRSBID, '', $date_order_start, $date_order_end);

        $order = new \CIBlockElement;

        /** @var array $arPayServices коды свойств платных услуг */
        $arPayServices = ['BIZ_WINNER_7', 'BIZ_SMART_AGENT', 'BIZ_LOGER', 'BIZ_PROVERKI'];

        // получаем лицензии и кол-во агентов у бизнес-единиц
        $arBizTarif = [];
        $arFilterTB = ['IBLOCK_ID' => 26];
        if (!empty($manualRSBBiz))
            $arFilterTB['ID'] = $manualRSBBiz;
        if (!empty($req['action']) && $req['action'] == 'update' && !empty($req['sectionID'])) {
            $arFilterTB['ID'] = $req['sectionID'];
        }
        $rsTB = \CIBlockSection::GetList([], $arFilterTB, false, ['ID', 'UF_BIZ_TARIF', 'UF_COUNT_AGENT']);
        while ($arTB = $rsTB->Fetch()) {
            $arBizTarif[$arTB['ID']] = $arTB;
        }

        $arNewIds = [];
        $arRsbOrderErrors = [];

        foreach ($arBiz as $sect => $ar) {
            $rsbID = false;

            // определяем ид риэлтора РСБ
            foreach ($ar as $bz) {
                if ($bz['PROPERTY_BIZ_MANAGER_VALUE'] == 'Y') {
                    $rsbID = $bz['PROPERTY_BIZ_REALTOR_VALUE'];
                }
            }

            if (!empty($rsbID)) {
                $arNewOrder = [
                    "MODIFIED_BY" => 1,
                    "IBLOCK_ID" => $iblock_order_id,
                    "ACTIVE" => "N",
                ];

                $arNewOrderProps = [];
                if (!empty($sect)) $arNewOrderProps['ORDER_BIZ'] = (int)$sect;
                $arNewOrderProps['ORDER_TYPE'] = $arEnumOrderType['order_type_auto'];
                $arNewOrderProps['ORDER_STATUS'] = $arOrderStatus['order_status_notpay'];
                $arNewOrderProps['ORDER_TARIF_END'] = date("d.m.Y", strtotime("first day of next month"));
                $arNewOrderProps['ORDER_PRICE'] = 0;
                $arNewOrderProps['ORDER_SERVICE'] = [];
                $arNewOrderProps['ORDER_TARIF'] = [];
                $arNewOrderProps['ORDER_JSON'] = '';
                $countServices = [];
                $order_json = ['proc-tarif' => 0];

                //проверяем/получаем типы партнера и затраты за предыдущий месяц
                if ($statusRSB[$rsbID] == 'group') {
                    $arTypePartners = Main::checkTypePartnerGroup($ib_biz, $sect);
                }
                if ($statusRSB[$rsbID] == 'individ') {
                    $arTypePartners = Main::checkTypePartnerIndivid($ib_biz, $sect);
                }

                $order_price_reclam = ($arPriceReclam[$rsbID]['order'] > 0) ? $arPriceReclam[$rsbID]['order'] : 0;
                $arNewOrderProps['ORDER_PRICE_RECLAM'] = $order_price_reclam;
                $arNewOrderProps['ORDER_PRICE'] += $order_price_reclam;
                $keyServ = array_search('svg--reklama', array_column($arServices, 'CODE'));
                $arNewOrderProps['ORDER_SERVICE'][] = $arServices[$keyServ]['ID'];

                $order_json['service']['svg--reklama'] = ['name' => $arServices[$keyServ]['NAME'], 'count' => 0, 'price' => $order_price_reclam];

                $arNewOrder['NAME'] = 'Заказ ' . $fioRSB[$rsbID];
                $arNewOrderProps['ORDER_RSB'] = $rsbID;

                // платные услуги
                $tmp_order_serv = [];
                foreach ($ar as $biz) {
                    foreach ($arPayServices as $code_serv) {
                        if ($biz[$code_serv]['VALUE_XML_ID'] == 'yes') {
                            // Аренда локера и Проверки у агентов не используется
                            if ($biz['PROPERTY_BIZ_MANAGER_VALUE'] != 'Y'
                                && ($code_serv == 'BIZ_LOGER' || $code_serv == 'BIZ_PROVERKI')) {
                                continue;
                            }

                            $countServices[$code_serv]++;

                            $keyServ = array_search($code_serv, array_column($arServices, 'PROPERTY_SERVICE_CODE_PROP_VALUE'));

                            $tmp_order_serv[$arServices[$keyServ]['CODE']]['name'] = $arServices[$keyServ]['NAME'];
                            $tmp_order_serv[$arServices[$keyServ]['CODE']]['count']++;

                            $sprice = $arServices[$keyServ]['PROPERTY_SERVICE_PRICE_VALUE'];
                            // стоимость для партнер горобмен
                            if ($arTypePartners['type_partner'] == 'partner_gorobmen')
                                $sprice = $arServices[$keyServ]['PROPERTY_SERVICE_PRICE_GOR_VALUE'];
                            if (!in_array($arServices[$keyServ]['ID'], $arNewOrderProps['ORDER_SERVICE']))
                                $arNewOrderProps['ORDER_SERVICE'][] = $arServices[$keyServ]['ID'];
                            $arNewOrderProps['ORDER_PRICE'] += $sprice;
                            $tmp_order_serv[$arServices[$keyServ]['CODE']]['price'] = $sprice;
                        }
                    }
                }

                // Обработанные запросы к Контур за прошлый месяц
                $arCountKontur = Kontur::getCountKontur($rsbID);
                if (!empty($arCountKontur)) {
                    $arTypePartners['type_partner'] = 'partner_gorobmen';
                    foreach ($arCountKontur as $k => $count) {
                        if ($count > 0) {
                            $keyServ = array_search($k, array_column($arServices, 'CODE'));
                            $tmp_order_serv[$arServices[$keyServ]['CODE']]['name'] = $arServices[$keyServ]['NAME'];
                            $tmp_order_serv[$arServices[$keyServ]['CODE']]['count'] = $count;
                            $tmp_order_serv[$arServices[$keyServ]['CODE']]['price'] = $arServices[$keyServ]['PROPERTY_SERVICE_PRICE_GOR_VALUE'];;

                            $sprice = $arServices[$keyServ]['PROPERTY_SERVICE_PRICE_VALUE'];
                            // стоимость для партнер горобмен
                            if ($arTypePartners['type_partner'] == 'partner_gorobmen')
                                $sprice = $arServices[$keyServ]['PROPERTY_SERVICE_PRICE_GOR_VALUE'];
                            if (!in_array($arServices[$keyServ]['ID'], $arNewOrderProps['ORDER_SERVICE']))
                                $arNewOrderProps['ORDER_SERVICE'][] = $arServices[$keyServ]['ID'];
                            $arNewOrderProps['ORDER_PRICE'] += $sprice * $count;
                        }
                    }
                }

                if (!empty($tmp_order_serv)) {
                    foreach ($tmp_order_serv as $key_serv => $tmp_serv) {
                        $order_json['service'][$key_serv] = $tmp_serv;
                    }
                }

                $arNewOrderProps['ORDER_SERVICE_COUNT'] = (!empty($countServices)) ? json_encode($countServices) : '';

                // проверяем пени РСБ за предыдущий месяц
                $fine_order_id = (!empty($req['orderID'])) ? $req['orderID'] : 0;
                $rsbFine = Orders::checkFineRSB($rsbID, $fine_order_id);
                if ($rsbFine['price'] > 0) {
                    // стоимость пени за предыдущий месяц и услугу Пени
                    $arNewOrderProps['ORDER_PRICE_PENI'] = $rsbFine['price'];
                    $keyServFine = array_search('svg--peni', array_column($arServices, 'CODE'));
                    $arNewOrderProps['ORDER_SERVICE'][] = $arServices[$keyServFine]['ID'];
                    $order_json['service']['svg--peni'] = [
                        'name' => $arServices[$keyServFine]['NAME'],
                        'count' => '',
                        'price' => $rsbFine['price']
                    ];
                }

                // добавляем тарифы в заказ
                $order_tarifs = [];
                $arTarifAll = [];
                $arPropTarif = $arBizTarif[$sect]; // тариф и кол-во агентов из свойства бизнес-единицы
                $allTarifRSB = 0; // общее кол-во тарифов РСБ равного или старше первого числа текущего месяца
                $curTarifRSB = []; // тариф РСБ к оплате
                $allTarifRealtor = 0; // общее кол-во тарифов риэлторов равного или старше первого числа текущего месяца
                $curTarifRealtor = 0; // кол-во тарифов риэлторов к оплате
                $curDate = date('d.m.Y', strtotime('first day of this month'));
                $rsTrf = PaymentTable::getList([
                    'filter' => ['RSB_ID' => $rsbID],
                    'select' => ['ID', 'ORDER_ID', 'TARIF', 'COUNT', 'DATE_END']
                ]);
                while ($arTrf = $rsTrf->Fetch()) {
                    $arTarifAll[] = $arTrf;
                }
                if (!empty($arTarifAll)) {
                    foreach ($arTarifAll as $tarif) {
                        if (strtotime($tarif['DATE_END']) >= strtotime($curDate)) {
                            if ($tarif['TARIF'] == 'TARIF_REALTOR')
                                $allTarifRealtor++; // считаем общее кол-во тарифов агентов
                            else
                                $allTarifRSB++;
                        }
                        if ($tarif['DATE_END'] == $curDate) { // если дата окончания первое число текущего месяца
                            if ($tarif['TARIF'] == 'TARIF_REALTOR') {
                                $curTarifRealtor++; // считаем кол-во тарифов агентов на оплату
                            } else {
                                $curTarifRSB = $tarif;
                            }
                        }
                    }
                }

                // добавляем тариф РСБ
                if (count($curTarifRSB) > 0) { //если есть тариф на оплату
                    if (empty($arPropTarif['UF_BIZ_TARIF'])) { // если не заполнено свойство лицензия рсб, то заполняем текущим тарифом
                        $bs = new \CIBlockSection();
                        $bs->Update($sect, ['UF_BIZ_TARIF' => $arTarifs[$curTarifRSB['TARIF']]['ID']]);
                    } else { // сравниваем тариф с тарифом бизнес-единицы, если разные, то заменяем
                        if ($arTarifs[$curTarifRSB['TARIF']]['ID'] !== $arPropTarif['UF_BIZ_TARIF']) {
                            foreach ($arTarifs as $index => $arTarif) {
                                if ($arTarif['ID'] == $arPropTarif['UF_BIZ_TARIF']) {
                                    $curTarifRSB['TARIF'] = $index;
                                }
                            }
                        }
                    }
                    $order_tarifs[] = $curTarifRSB;
                } // если вообще нет тарифа после 1 числа текущего месяца (не оплачен в прошлом месяце), то выставляется тариф из свойства
                elseif ($allTarifRSB == 0 && !empty($arPropTarif['UF_BIZ_TARIF'])) {
                    foreach ($arTarifs as $index => $arTarif) {
                        if ($arTarif['ID'] == $arPropTarif['UF_BIZ_TARIF']) {
                            $order_tarifs[] = ['TARIF' => $index, 'COUNT' => 1];
                        }
                    }
                }

                // добавляем тарифы Агентов
                $diffAgent = $arPropTarif['UF_COUNT_AGENT'] - $allTarifRealtor;
                if ($diffAgent > 0) { // если нужно добавить агентов
                    for ($i = 0; $i < $diffAgent; $i++) {
                        $order_tarifs[] = ['TARIF' => 'TARIF_REALTOR', 'COUNT' => 1];
                    }
                    for ($i = 0; $i < $curTarifRealtor; $i++) {
                        $order_tarifs[] = ['TARIF' => 'TARIF_REALTOR', 'COUNT' => 1];
                    }
                } elseif ($diffAgent == 0) {
                    for ($i = 0; $i < $curTarifRealtor; $i++) {
                        $order_tarifs[] = ['TARIF' => 'TARIF_REALTOR', 'COUNT' => 1];
                    }
                } else {
                    $diff = $allTarifRealtor - abs($diffAgent) - $curTarifRealtor;
                    if ($diff > 0) {
                        for ($i = 0; $i < $diff; $i++) {
                            $order_tarifs[] = ['TARIF' => 'TARIF_REALTOR', 'COUNT' => 1];
                        }
                    }
                }

                // период оплаты тарифов
                $tmp_order_tarif = [];
                if (!empty($order_tarifs)) {
                    foreach ($order_tarifs as $orderTarif) {
                        if (is_array($arNewOrderProps['ORDER_TARIF']) && !in_array($arTarifs[$orderTarif['TARIF']]['ID'], $arNewOrderProps['ORDER_TARIF']))
                            $arNewOrderProps['ORDER_TARIF'][] = $arTarifs[$orderTarif['TARIF']]['ID'];
                        $arNewOrderProps['ORDER_PRICE'] += $arTarifs[$orderTarif['TARIF']]['PROPERTY_TARIF_PRICE_VALUE'] * $orderTarif['COUNT'];
                        $tmp_order_tarif[$orderTarif['TARIF']]['name'] = $arTarifs[$orderTarif['TARIF']]['NAME'];
                        $tmp_order_tarif[$orderTarif['TARIF']]['price'] = $arTarifs[$orderTarif['TARIF']]['PROPERTY_TARIF_PRICE_VALUE'];
                        if ($orderTarif['TARIF'] == 'TARIF_REALTOR') {
                            $arNewOrderProps['ORDER_NUM_AGENT'] += 1;
                            $tmp_order_tarif[$orderTarif['TARIF']]['count']++;
                        } else {
                            $tmp_order_tarif[$orderTarif['TARIF']]['count'] = 1;
                        }
                    }
                }
                if (!empty($tmp_order_tarif)) {
                    foreach ($tmp_order_tarif as $key_tarif => $tmp_tarif) {
                        $order_json['tarif'][$key_tarif] = $tmp_tarif;
                    }
                }

                // общая информация о заказе
                if (!empty($order_json)) {
                    $order_json['tarif-month'] = 1;
                    $order_json['order-price-rub'] = $arNewOrderProps['ORDER_PRICE'];
                    $arNewOrderProps['ORDER_JSON'] = json_encode($order_json);
                }
                $arNewOrder['PROPERTY_VALUES'] = $arNewOrderProps;

                // очищаем свойства типа список
                if (empty($arNewOrderProps['ORDER_TARIF']))
                    $arNewOrderProps['ORDER_TARIF'] = ["VALUE" => "", "DESCRIPTION" => ""];
                if (empty($arNewOrderProps['ORDER_SERVICE']))
                    $arNewOrderProps['ORDER_SERVICE'] = ["VALUE" => "", "DESCRIPTION" => ""];

                if (!empty($req['action']) && $req['action'] == 'update' && !empty($req['orderID'])) {
                    $new_order_id = \CIBlockElement::SetPropertyValuesEx($req['orderID'], $iblock_order_id, $arNewOrderProps);
                    $log_name = ' Обновлен авто заказ с ID ' . $req['orderID'];
                } else {
                    $new_order_id = $order->Add($arNewOrder);
                    $log_name = ' Добавлен авто заказ с ID ' . $new_order_id;
                }

                // записываем в лог
                if (!empty($order_json)) {
                    foreach ($order_json as $ijson => $json) {
                        if ($ijson == 'service' && !empty($json)) {
                            foreach ($json as $is => $serv) {
                                if (!empty($order_json['service'][$is]['name']))
                                    unset($order_json['service'][$is]['name']);
                            }
                        }
                        if ($ijson == 'tarif' && !empty($json)) {
                            foreach ($json as $it => $tarif) {
                                if (!empty($order_json['tarif'][$it]['name']))
                                    unset($order_json['tarif'][$it]['name']);
                            }
                        }
                    }
                    $arNewOrderProps['ORDER_JSON'] = json_encode($order_json);
                }
                paymentLog(implode(',', $arNewOrderProps), $log_name, $log_type);

                if ($new_order_id) {
                    $arNewIds[] = $new_order_id;
                } else {
                    $arRsbOrderErrors[$rsbID] = $order->LAST_ERROR;
                }
            }
        }

        return [ 'orders' => $arNewIds, 'errors' => $arRsbOrderErrors ];
    }
}
