<?php

namespace Gorobmen\Ads\Services;

use Bitrix\Main\Application;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Loader;
use Gorobmen\Feeds\ObjStatisticTable;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CianHand
{
    /**
    * Основной метод обработки загрузки файла
    * @return array
    */
    public static function load(): array
    {
        $tmp_file = $_FILES['cianfile'] ?? null;

        if (empty($tmp_file)) {
            return ['error' => 'Файл cianfile не передан'];
        }

        if ($tmp_file['error'] !== 0) {
            return ['error' => 'Ошибка загрузки файла на сервер (код ошибки: ' . $tmp_file['error'] . ')'];
        }

        $docRoot = Application::getDocumentRoot();
        $path = '/upload/statistic/cian_hand.xlsx';
        $cian_hand_update = '/upload/statistic/cian_hand_update.log';
        $cian_hand_add = '/upload/statistic/cian_hand_add.log';
        $cian_hand_error = '/upload/statistic/cian_hand_error.log';

        // Удаляем старые лог-файлы
        foreach ([$cian_hand_update, $cian_hand_add, $cian_hand_error] as $logFile) {
            if (file_exists($docRoot . $logFile)) {
                @unlink($docRoot . $logFile);
            }
        }

        if (!move_uploaded_file($tmp_file['tmp_name'], $docRoot . $path)) {
            return ['error' => 'Ошибка перемещения файла'];
        }

        // Подключаем PhpSpreadsheet через автозагрузку Composer
        if (file_exists($docRoot . '/local/vendor/autoload.php')) {
            require_once($docRoot . '/local/vendor/autoload.php');
        }

        $arData = [];

        try {
            $spreadsheet = IOFactory::load($docRoot . $path);
            $worksheet = $spreadsheet->getActiveSheet();
            $worksheet->getStyle('A2:A10000')->getNumberFormat()->setFormatCode('dd.mm.yyyy');
            $arExcel = $worksheet->toArray(null, false, true, true);
        } catch (\Exception $e) {
            @unlink($docRoot . $path);
            return ['error' => 'Ошибка парсинга Excel: ' . $e->getMessage()];
        }

        if (empty($arExcel)) {
            @unlink($docRoot . $path);
            return ['warning' => 'Нет объектов для изменения/добавления'];
        }

        foreach ($arExcel as $data) {
            if ($data['A'] == null) break;
            if ((int)$data['B'] == 0) continue;

            $arData[$data['B']][$data['D']][] = [
                'date'   => $data['A'],
                'obj_id' => $data['B'],
                'price'  => $data['C'],
                'tarif'  => $data['D'],
            ];
        }

        @unlink($docRoot . $path);

        if (empty($arData)) {
            return ['warning' => 'Нет объектов для изменения/добавления'];
        }

        if (!Loader::includeModule('gorobmen.feeds')) {
            return ['error' => 'Не удалось подключить модуль gorobmen.feeds'];
        }

        $arDataClean = self::clean($arData);
        $arData = $arDataClean['data'];

        $arObjIDs = array_keys($arData);
        $arObjStatistics = [];

        $rsTable = ObjStatisticTable::GetList([
            'filter' => ['OBJ_ID' => $arObjIDs],
            'select' => ['ID', 'OBJ_ID', 'OBJ_CIAN*']
        ]);
        while ($arTable = $rsTable->fetch()) {
            $arObjStatistics[$arTable['OBJ_ID']] = $arTable;
        }

        $arLogUpdate = [];
        $arLogAdd = [];
        $arLogError = !empty($arDataClean['error']) ? $arDataClean['error'] : [];

        foreach ($arData as $objID => $arObjExcel) {
            if (!empty($arObjStatistics[$objID])) {
                $upd = self::cianUpdate($arObjStatistics[$objID], $arObjExcel);
                if (!empty($upd['log'])) {
                    $arLogUpdate[$objID] = $upd['log'];
                }
                if (!empty($upd['error'])) {
                    $arLogError[$objID] = $upd['error'];
                }
            } else {
                $add = self::cianAdd($objID, $arObjExcel);
                if (!empty($add['log'])) {
                    $arLogAdd[$objID] = $add['log'];
                }
                if (!empty($add['error'])) {
                    $arLogError[$objID] = $add['error'];
                }
            }
        }

        $res = [
            'success' => 'Добавлено объектов: ' . count($arLogAdd) . '. Обновлено объектов ' . count($arLogUpdate) . '. Ошибок ' . count($arLogError)
        ];

        $timeFormat = date('H:i');
        if (!empty($arLogUpdate)) {
            Debug::writeToFile($arLogUpdate, 'Objects ' . $timeFormat, $cian_hand_update);
            $res['logUpdate'] = $cian_hand_update;
        }
        if (!empty($arLogAdd)) {
            Debug::writeToFile($arLogAdd, 'Objects ' . $timeFormat, $cian_hand_add);
            $res['logAdd'] = $cian_hand_add;
        }
        if (!empty($arLogError)) {
            Debug::writeToFile($arLogError, 'Objects ' . $timeFormat, $cian_hand_error);
            $res['logError'] = $cian_hand_error;
        }

        return $res;
    }

    /** Очищаем данные из Excel от неверных значений */
    protected static function clean(array $arData = []): array
    {
        $arOut = ['data' => [], 'error' => []];
        foreach ($arData as $objid => $ar) {
            $tmp = [];
            foreach ($ar as $tarif => $arr) {
                foreach ($arr as $key => $val) {
                    if (empty($tmp[$tarif])) {
                        $tmp[$tarif][$key] = $val;
                    } else {
                        $k = array_search($val['date'], array_column($tmp[$tarif], 'date'));
                        if ($k !== false) {
                            $arOut['error'][$objid][$tarif][] = 'Одинаковая дата ' . $val['date'];
                            if ($val['price'] > $tmp[$tarif][$k]['price']) {
                                $arOut['error'][$objid][$tarif][] = 'Замена стоимости на большую ' . $val['price'];
                                $tmp[$tarif][$k] = $val;
                            }
                        } else {
                            $tmp[$tarif][$key] = $val;
                        }
                    }
                }
            }
            if (!empty($tmp)) {
                $arOut['data'][$objid] = $tmp;
            }
        }
        return $arOut;
    }

    /** Обновляем значения Циан */
    protected static function cianUpdate(array $arObjTable = [], array $arObjExcel = []): array
    {
        $objID = $arObjTable['ID'];

        $arFields = [];
        $arLog = [];

        // 1. Собираем аукционы по датам
        $arAuction = [];
        foreach ($arObjExcel as $tarif => $auct) {
            if ($tarif == 'OBJ_CIAN_TOP_A') {
                foreach ($auct as $auc) {
                    $arAuction[$auc['date']] = $auc;
                }
            }
        }

        // 2. Обрабатываем основные тарифы
        foreach ($arObjExcel as $tarif => $arObj) {
            if ($tarif == 'OBJ_CIAN_TOP_A') continue;

            // Инициализируем текущие значения тарифа из БД
            $arVal = (!empty($arObjTable[$tarif])) ? explode(',', $arObjTable[$tarif]) : [];

            foreach ($arObj as $obj) {
                $change = false;

                // Если в массиве $arVal уже есть данные (были в БД или добавлены на предыдущем шаге цикла)
                if (!empty($arVal)) {
                    foreach ($arVal as $kval => $val) {
                        $arV = explode(':', $val);
                        if ($arV[0] == $obj['date']) {
                            $change = true;

                            // Обновляем стоимость тарифа, если новая больше
                            if ($arV[1] < $obj['price']) {
                                $arLog[$tarif][] = 'Обновление стоимости тарифа за ' . $obj['date'] . ' на большую ' . $obj['price'] . ' (старая ' . $arV[1] . ')';
                                $arV[1] = $obj['price'];
                                $arVal[$kval] = implode(':', $arV);
                            }

                            // Если это TOP, проверяем и обновляем аукцион
                            if ($tarif == 'OBJ_CIAN_TOP') {
                                if (!empty($arAuction[$obj['date']])) {
                                    if ($arV[3] < $arAuction[$obj['date']]['price']) {
                                        $arLog[$tarif][] = 'Обновление стоимости аукциона за ' . $obj['date'] . ' на большую ' . $arAuction[$obj['date']]['price'] . ' (старая ' . $arV[3] . ')';
                                        $arV[3] = $arAuction[$obj['date']]['price'];
                                        $arVal[$kval] = implode(':', $arV);
                                    }
                                    unset($arAuction[$obj['date']]); // Удаляем, так как применили
                                }
                            }
                            break; // Дата найдена и обработана, выходим из внутреннего перебора
                        }
                    }
                }

                // Если даты еще не было в массиве
                if (!$change) {
                    if ($tarif == 'OBJ_CIAN_TOP' && !empty($arAuction[$obj['date']])) {
                        $arr = [$obj['date'], $obj['price'], '0', $arAuction[$obj['date']]['price']];
                        unset($arAuction[$obj['date']]);
                    } else {
                        $arr = [$obj['date'], $obj['price'], '0', '0'];
                    }
                    $arVal[] = implode(':', $arr);
                    $arLog[$tarif][] = 'Добавлена новая дата ' . $obj['date'] . ' со стоимостью ' . $obj['price'];
                }
            }
            $arFields[$tarif] = implode(",", $arVal);
        }

        // 3. Если остались нераспределенные аукционы, а в БД/потоке есть тариф OBJ_CIAN_TOP
        $cianTopValue = $arFields['OBJ_CIAN_TOP'] ?? $arObjTable['OBJ_CIAN_TOP'] ?? '';

        if (!empty($arAuction) && !empty($cianTopValue)) {
            $arVal = explode(',', $cianTopValue);
            foreach ($arVal as $kval => $val) {
                $arV = explode(':', $val);
                if (!empty($arAuction[$arV[0]])) {
                    $obj = $arAuction[$arV[0]];
                    if ($arV[3] < $obj['price']) {
                        $arLog['OBJ_CIAN_TOP'][] = 'Обновление стоимости аукциона за ' . $obj['date'] . ' на большую ' . $obj['price'] . ' (старая ' . $arV[3] . ')';
                        $arV[3] = $obj['price'];
                        $arVal[$kval] = implode(':', $arV);
                    }
                    unset($arAuction[$arV[0]]);
                }
            }
            $arFields['OBJ_CIAN_TOP'] = implode(',', $arVal);
        }

        // 4. Обновляем данные в БД Bitrix ORM
        if (!empty($arFields)) {
            ObjStatisticTable::update($objID, $arFields);
        }

        return ['log' => $arLog, 'fields' => $arFields];
    }


    /** Добавляем объект со значениями Циан из Excel */
    protected static function cianAdd($objID = 0, array $arObjExcel = []): array
    {
        $arLog = [];
        $objID = (int)$objID;

        if ($objID > 0) {
            // 1. Обязательно подключаем модуль инфоблоков
            if (!\Bitrix\Main\Loader::includeModule('iblock')) {
                return ['error' => 'Модуль iblock не подключен'];
            }

            $arFields = ['OBJ_ID' => $objID];

            // Безопасное получение создателя объекта
            $dbElement = \CIBlockElement::GetList([], ['IBLOCK_ID' => 21, 'ID' => $objID], false, false, ['CREATED_BY']);
            if ($arElement = $dbElement->Fetch()) {
                $arFields['OBJ_RIELTOR'] = $arElement['CREATED_BY'];
            }

            if (empty($arFields['OBJ_RIELTOR'])) {
                return ['error' => 'Не найден объект с ID ' . $objID . ' в базе данных или у него нет создателя'];
            }

            // Получаем данные риелтора
            $arRealtor = \CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => 26, 'PROPERTY_BIZ_REALTOR' => $arFields['OBJ_RIELTOR']],
                false,
                false,
                ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'PROPERTY_BIZ_MANAGER']
            )->Fetch();

            // Проверяем привязку менеджера (учитываем возможные варианты ключей Битрикса)
            $managerValue = $arRealtor['PROPERTY_BIZ_MANAGER_VALUE'] ?? $arRealtor['PROPERTY_BIZ_MANAGER'] ?? null;

            if (!empty($managerValue)) {
                $arFields['OBJ_RSB'] = $arFields['OBJ_RIELTOR'];
            } else {
                $sectionID = $arRealtor['IBLOCK_SECTION_ID'] ?? 0;
                $arManager = \CIBlockElement::GetList(
                    [],
                    ['IBLOCK_ID' => 26, 'IBLOCK_SECTION_ID' => $sectionID, 'PROPERTY_BIZ_MANAGER' => 558],
                    false,
                    false,
                    ['ID', 'IBLOCK_ID', 'PROPERTY_BIZ_REALTOR']
                )->Fetch();

                $arFields['OBJ_RSB'] = $arManager['PROPERTY_BIZ_REALTOR_VALUE'] ?? $arManager['PROPERTY_BIZ_REALTOR'] ?? null;
            }

            // 2. Собираем аукционы
            $arAuction = [];
            foreach ($arObjExcel as $tarif => $auct) {
                if ($tarif == 'OBJ_CIAN_TOP_A') {
                    foreach ($auct as $auc) {
                        $arAuction[$auc['date']] = $auc;
                    }
                }
            }

            // 3. Формируем строки тарифов
            foreach ($arObjExcel as $tarif => $arObj) {
                if ($tarif !== 'OBJ_CIAN_TOP_A') {
                    foreach ($arObj as $obj) {
                        if ($obj['tarif'] == 'OBJ_CIAN_TOP' && !empty($arAuction[$obj['date']])) {
                            $val = $obj['date'] . ':' . $obj['price'] . ':0:' . $arAuction[$obj['date']]['price'];
                        } else {
                            $val = $obj['date'] . ':' . $obj['price'] . ':0:0';
                        }

                        if (!empty($arFields[$obj['tarif']])) {
                            // Дополнительно страхуемся от дублирования идентичных записей в рамках одной строки
                            if (strpos($arFields[$obj['tarif']], $val) === false) {
                                $arFields[$obj['tarif']] .= ',' . $val;
                            }
                        } else {
                            $arFields[$obj['tarif']] = $val;
                        }
                    }
                }
            }

            // 4. Добавляем запись в таблицу статистики
            try {
                $result = ObjStatisticTable::add($arFields);
                if ($result->isSuccess()) {
                    $arLog[] = ['Добавлена новая запись: ' => $arFields];
                } else {
                    return ['error' => 'Ошибка добавления в ObjStatisticTable: ' . implode(', ', $result->getErrorMessages())];
                }
            } catch (\Exception $e) {
                return ['error' => 'Исключение при добавлении в БД: ' . $e->getMessage()];
            }
        }

        return ['log' => $arLog];
    }

}
