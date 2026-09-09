<?
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();
/** @var CBitrixComponent $this */
/** @var array $arParams */
/** @var array $arResult */
/** @var string $componentPath */
/** @var string $componentName */
/** @var string $componentTemplate */
/** @global CDatabase $DB */
global $DB;
/** @global CUser $USER */
global $USER;
/** @global CMain $APPLICATION */
global $APPLICATION;

/*************************************************************************
 * Processing of received parameters
 *************************************************************************/
if (!isset($arParams["CACHE_TIME"]))
    $arParams["CACHE_TIME"] = 36000000;

unset($arParams["IBLOCK_TYPE"]); //was used only for IBLOCK_ID setup with Editor
$arParams["IBLOCK_ID"] = intval($arParams["IBLOCK_ID"]);

if (!is_array($arParams["FIELD_CODE"]))
    $arParams["FIELD_CODE"] = array();
foreach ($arParams["FIELD_CODE"] as $k => $v)
    if ($v === "")
        unset($arParams["FIELD_CODE"][$k]);

if (!is_array($arParams["PROPERTY_CODE"]))
    $arParams["PROPERTY_CODE"] = array();
foreach ($arParams["PROPERTY_CODE"] as $k => $v)
    if ($v === "")
        unset($arParams["PROPERTY_CODE"][$k]);

$arParams["SAVE_IN_SESSION"] = $arParams["SAVE_IN_SESSION"] == "Y";

if ($arParams["FILTER_NAME"] == '' || !preg_match("/^[A-Za-z_][A-Za-z01-9_]*$/", $arParams["FILTER_NAME"]))
    $arParams["FILTER_NAME"] = "arrFilter";
$FILTER_NAME = $arParams["FILTER_NAME"];
$arParams["PREFILTER_NAME"] = (isset($arParams["PREFILTER_NAME"]) ? (string)$arParams["PREFILTER_NAME"] : '');
if (
    $arParams["PREFILTER_NAME"] == ''
    || !preg_match("/^[A-Za-z_][A-Za-z01-9_]*$/", $arParams["PREFILTER_NAME"])
) {
    $arParams["PREFILTER_NAME"] = "preFilter";
}
$PREFILTER_NAME = $arParams["PREFILTER_NAME"];

global ${$FILTER_NAME};
${$FILTER_NAME} = array();

global ${$PREFILTER_NAME};
$preFilter = ${$PREFILTER_NAME};
if (!is_array($preFilter))
    $preFilter = array();

if (!empty($preFilter))
    ${$FILTER_NAME} = array_merge($preFilter, ${$FILTER_NAME});

/*************************************************************************
 * Processing the  "Filter" and "Reset" button actions
 *************************************************************************/
$arDateFields = array(
    "ACTIVE_DATE" => array(
        "from" => "_ACTIVE_DATE_1",
        "to" => "_ACTIVE_DATE_2",
        "days_to_back" => "_ACTIVE_DATE_1_DAYS_TO_BACK",
        "filter_from" => ">=DATE_ACTIVE_FROM",
        "filter_to" => "<=DATE_ACTIVE_TO",
    ),
    "DATE_ACTIVE_FROM" => array(
        "from" => "_DATE_ACTIVE_FROM_1",
        "to" => "_DATE_ACTIVE_FROM_2",
        "days_to_back" => "_DATE_ACTIVE_FROM_1_DAYS_TO_BACK",
        "filter_from" => ">=DATE_ACTIVE_FROM",
        "filter_to" => "<=DATE_ACTIVE_FROM",
    ),
    "DATE_ACTIVE_TO" => array(
        "from" => "_DATE_ACTIVE_TO_1",
        "to" => "_DATE_ACTIVE_TO_2",
        "days_to_back" => "_DATE_ACTIVE_TO_1_DAYS_TO_BACK",
        "filter_from" => ">=DATE_ACTIVE_TO",
        "filter_to" => "<=DATE_ACTIVE_TO",
    ),
    "DATE_CREATE" => array(
        "from" => "_DATE_CREATE_1",
        "to" => "_DATE_CREATE_2",
        "days_to_back" => "_DATE_CREATE_1_DAYS_TO_BACK",
        "filter_from" => ">=DATE_CREATE",
        "filter_to" => "<=DATE_CREATE",
    ),
);

$arResult['arDateKey'] = $arDateFields;

/*Init filter values*/
$arrPFV = array();
$arrCFV = array();
$arrFFV = array();//Element fields value
$arrDFV = array();//Element date fields
$arrODFV = array();

foreach ($arDateFields as $id => $arField) {
    $arField["from"] = array(
        "name" => $FILTER_NAME . $arField["from"],
        "value" => "",
    );
    $arField["to"] = array(
        "name" => $FILTER_NAME . $arField["to"],
        "value" => "",
    );
    $arField["days_to_back"] = array(
        "name" => $FILTER_NAME . $arField["days_to_back"],
        "value" => "",
    );
    $arrDFV[$id] = $arField;
}

/*Leave filter values empty*/
if ($_REQUEST["del_filter"] <> '') {
    foreach ($arrDFV as $id => $arField)
        $GLOBALS[$arField["days_to_back"]["name"]] = "";
} /*Read filter values from request*/
elseif ($_REQUEST["set_filter"] <> '') {
    if (isset($_REQUEST[$FILTER_NAME . "_pf"]))
        $arrPFV = $_REQUEST[$FILTER_NAME . "_pf"];
    if (isset($_REQUEST[$FILTER_NAME . "_cf"]))
        $arrCFV = $_REQUEST[$FILTER_NAME . "_cf"];
    if (isset($_REQUEST[$FILTER_NAME . "_ff"]))
        $arrFFV = $_REQUEST[$FILTER_NAME . "_ff"];

    $now = time();
    foreach ($arrDFV as $id => $arField) {
        $name = $arField["from"]["name"];
        if (isset($_REQUEST[$name]))
            $arrDFV[$id]["from"]["value"] = $_REQUEST[$name];

        $name = $arField["to"]["name"];
        if (isset($_REQUEST[$name]))
            $arrDFV[$id]["to"]["value"] = $_REQUEST[$name];

        $name = $arField["days_to_back"]["name"];
        if (isset($_REQUEST[$name])) {
            $value = $arrDFV[$id]["days_to_back"]["value"] = $_REQUEST[$name];
            if ($value <> '')
                $arrDFV[$id]["from"]["value"] = GetTime($now - 86400 * intval($value));
        }
    }

    foreach ($arrODFV as $id => $arField) {
        $name = $arField["from"]["name"];
        if (isset($_REQUEST[$name]))
            $arrODFV[$id]["from"]["value"] = $_REQUEST[$name];

        $name = $arField["to"]["name"];
        if (isset($_REQUEST[$name]))
            $arrODFV[$id]["to"]["value"] = $_REQUEST[$name];

        $name = $arField["days_to_back"]["name"];
        if (isset($_REQUEST[$name])) {
            $value = $arrODFV[$id]["days_to_back"]["value"] = $_REQUEST[$name];
            if ($value <> '')
                $arrODFV[$id]["from"]["value"] = GetTime($now - 86400 * intval($value));
        }
    }
} /*No action specified, so read from the session (if parameter is set)*/
elseif ($arParams["SAVE_IN_SESSION"]) {
    if (isset($_SESSION[$FILTER_NAME . "arrPFV"]))
        $arrPFV = $_SESSION[$FILTER_NAME . "arrPFV"];
    if (isset($_SESSION[$FILTER_NAME . "arrCFV"]))
        $arrCFV = $_SESSION[$FILTER_NAME . "arrCFV"];
    if (isset($_SESSION[$FILTER_NAME . "arrFFV"]))
        $arrFFV = $_SESSION[$FILTER_NAME . "arrFFV"];
    if (isset($_SESSION[$FILTER_NAME . "arrDFV"]) && is_array($_SESSION[$FILTER_NAME . "arrDFV"])) {
        foreach ($_SESSION[$FILTER_NAME . "arrDFV"] as $id => $arField) {
            $arrDFV[$id]["from"]["value"] = $arField["from"]["value"];
            $arrDFV[$id]["to"]["value"] = $arField["to"]["value"];
            $arrDFV[$id]["days_to_back"]["value"] = $arField["days_to_back"]["value"];
        }
    }
}

/*Save filter values to the session*/
if ($arParams["SAVE_IN_SESSION"]) {
    $_SESSION[$FILTER_NAME . "arrPFV"] = $arrPFV;
    $_SESSION[$FILTER_NAME . "arrCFV"] = $arrCFV;
    $_SESSION[$FILTER_NAME . "arrFFV"] = $arrFFV;
    $_SESSION[$FILTER_NAME . "arrDFV"] = $arrDFV;
}

if (!CModule::IncludeModule("iblock")) {
    ShowError(GetMessage("CC_BCF_MODULE_NOT_INSTALLED"));
    return 0;
}

if ($this->StartResultCache(false, ($arParams["CACHE_GROUPS"] === "N" ? false : $USER->GetGroups()))) {
    $arResultModules = array(
        'iblock' => true,
        'catalog' => false,
        'currency' => false,
    );

    $arResult["arrProp"] = array();
    $arResult["arrSection"] = array();

    // simple fields
    if (in_array("SECTION_ID", $arParams["FIELD_CODE"])) {
        $arResult["arrSection"][0] = GetMessage("CC_BCF_TOP_LEVEL");
        $rsSection = CIBlockSection::GetList(
            array("left_margin" => "asc"),
            array(
                "IBLOCK_ID" => $arParams["IBLOCK_ID"],
                "ACTIVE" => "Y",
            ),
            false,
            array("ID", "DEPTH_LEVEL", "NAME")
        );
        while ($arSection = $rsSection->Fetch()) {
            $arResult["arrSection"][$arSection["ID"]] = str_repeat(" . ", $arSection["DEPTH_LEVEL"]) . $arSection["NAME"];
        }
    }

    $ibSelect = array_merge(['ID', 'IBLOCK_ID'], $arParams["FIELD_CODE"]); // массив кодов, учавствующих в фильтре

    // properties
    $rsProp = CIBlockProperty::GetList(array("sort" => "asc", "name" => "asc"), array("ACTIVE" => "Y", "IBLOCK_ID" => $arParams["IBLOCK_ID"]));
    while ($arProp = $rsProp->Fetch()) {
        if (in_array($arProp["CODE"], $arParams["PROPERTY_CODE"]) && $arProp["PROPERTY_TYPE"] != "F") {
            //$ibSelect[] = 'PROPERTY_'.$arProp["CODE"];
            $arTemp = array(
                "CODE" => $arProp["CODE"],
                "NAME" => $arProp["NAME"],
                "PROPERTY_TYPE" => $arProp["PROPERTY_TYPE"],
                "USER_TYPE" => $arProp["USER_TYPE"],
                "LINK_IBLOCK_ID" => $arProp["LINK_IBLOCK_ID"],
                "MULTIPLE" => $arProp["MULTIPLE"],
            );
            if ($arProp["PROPERTY_TYPE"] == "L") {
                $arTemp['LIST_TYPE'] = $arProp['LIST_TYPE'];
                $arrEnum = array();
                $rsEnum = CIBlockProperty::GetPropertyEnum($arProp["ID"], ['SORT' => 'ASC', 'VALUE' => 'ASC', 'ID' => 'ASC']);
                while ($arEnum = $rsEnum->Fetch()) {
                    $arrEnum[$arEnum["ID"]] = $arEnum["VALUE"];
                }
                $arTemp["VALUE_LIST"] = $arrEnum;
            }
            $arResult["arrProp"][$arProp["ID"]] = $arTemp;
        }
    }

    $arResult['MODULES'] = $arResultModules;

    $this->EndResultCache();
}

$arResult["FORM_ACTION"] = isset($_SERVER['REQUEST_URI']) ? htmlspecialcharsbx($_SERVER['REQUEST_URI']) : "";
$arResult["FILTER_NAME"] = $FILTER_NAME;

/*************************************************************************
 * Adding the titles and input fields
 *************************************************************************/

$arResult["arrInputNames"] = array(); // array of the input field names; is being used in the function $APPLICATION->GetCurPageParam

// simple fields
$arResult["ITEMS"] = array();

foreach ($arParams["FIELD_CODE"] as $field_code) {
    $field_res = "";
    $field_type = "";
    $field_names = "";
    $field_values = "";
    $field_list = array();
    $arResult["arrInputNames"][$FILTER_NAME . "_ff"] = true;
    $name = $FILTER_NAME . "_ff[" . $field_code . "]";
    $value = $arrFFV[$field_code];
    switch ($field_code) {
        case "ID":
        case "CODE":
        case "ACTIVE":
        case "XML_ID":
        case "NAME":
        case "PREVIEW_TEXT":
        case "DETAIL_TEXT":
        case "IBLOCK_TYPE_ID":
        case "IBLOCK_ID":
        case "IBLOCK_CODE":
        case "IBLOCK_NAME":
        case "IBLOCK_EXTERNAL_ID":
        case "SEARCHABLE_CONTENT":
        case "TAGS":
            if (!is_array($value)) {
                $field_res = '<input type="text" name="' . $name . '" size="' . $arParams["TEXT_WIDTH"] . '" value="' . htmlspecialcharsbx($value) . '" />';
                if ($value <> '')
                    ${$FILTER_NAME}["?" . $field_code] = $value;

                $field_type = 'INPUT';
            }
            break;
        case "SORT":
        case "SHOW_COUNTER":
            $name_left = $FILTER_NAME . "_ff[" . $field_code . "][LEFT]";
            if (is_array($value) && isset($value["LEFT"]))
                $value_left = $value["LEFT"];
            else
                $value_left = "";
            $field_res = '<input type="text" name="' . $name_left . '" size="' . $arParams["NUMBER_WIDTH"] . '" value="' . htmlspecialcharsbx($value_left) . '" />&nbsp;' . GetMessage("CC_BCF_TILL") . '&nbsp;';

            if ($value_left <> '')
                ${$FILTER_NAME}[">=" . $field_code] = intval($value_left);

            $name_right = $FILTER_NAME . "_ff[" . $field_code . "][RIGHT]";
            if (is_array($value) && isset($value["RIGHT"]))
                $value_right = $value["RIGHT"];
            else
                $value_right = "";
            $field_res .= '<input type="text" name="' . $name_right . '" size="' . $arParams["NUMBER_WIDTH"] . '" value="' . htmlspecialcharsbx($value_right) . '" />';

            if ($value_right <> '')
                ${$FILTER_NAME}["<=" . $field_code] = intval($value_right);

            $field_type = 'RANGE';
            $field_names = array($name_left, $name_right);
            $field_values = array($value_left, $value_right);
            break;
        case "SECTION_ID":
            $arrRef = array("reference" => array_values($arResult["arrSection"]), "reference_id" => array_keys($arResult["arrSection"]));
            $field_res = SelectBoxFromArray($name, $arrRef, $value, " ", "");

            if (!is_array($value) && $value != "NOT_REF" && $value <> '')
                ${$FILTER_NAME}[$field_code] = intval($value);

            $_name = $FILTER_NAME . "_ff[INCLUDE_SUBSECTIONS]";
            $_value = $arrFFV["INCLUDE_SUBSECTIONS"];
            $field_res .= "<br>" . InputType("checkbox", $_name, "Y", $_value, false, "", "") . "&nbsp;" . GetMessage("CC_BCF_INCLUDE_SUBSECTIONS");

            if (isset(${$FILTER_NAME}[$field_code]) && $_value == "Y")
                ${$FILTER_NAME}["INCLUDE_SUBSECTIONS"] = "Y";

            $field_type = 'SELECT';
            $field_list = $arResult["arrSection"];
            break;
        case "ACTIVE_DATE":
        case "DATE_ACTIVE_FROM":
        case "DATE_ACTIVE_TO":
        case "DATE_CREATE":
            $field_res = true;
            // Запоминаем значения из запроса, чтобы они не пропадали в интерфейсе
            if (is_array($value) && !empty($value)) {
                if ($value['start'] <> '') {
                    ${$FILTER_NAME}[">=" . $field_code] = date('d.m.Y 00:00:00', strtotime($value['start']));
                }
                if ($value['end'] <> '') {
                    ${$FILTER_NAME}["<=" . $field_code] = date('d.m.Y 23:59:59', strtotime($value['end']));
                }
            }
            break;
    }

    if ($field_res) {
        $arResult["ITEMS"][$field_code] = [
            "LABEL" => htmlspecialcharsbx(GetMessage("IBLOCK_FIELD_" . $field_code)),
            "INPUT_NAME" => $name,
            "INPUT_VALUE" => $value,
            "LIST" => is_array($value) ? $value : [] // ДУБЛИРУЕМ СЮДА ДЛЯ СТАРОГО ШАБЛОНА
        ];

        if ($field_code == 'DATE_CREATE') {
            $arResult["ITEMS"][$field_code]['LABEL'] = 'Дата формирования записи';
            $arResult["ITEMS"][$field_code]['USER_TYPE'] = 'DateTime';

            // Если пользователь еще ничего не ввел, но есть preFilter (за 30 дней) — показываем дефолтные даты
            if (empty($value['start']) && empty($value['end']) && !empty($preFilter)) {
                foreach ($preFilter as $kpreF => $preF) {
                    if (str_contains($kpreF, 'DATE_CREATE')) {
                        if (str_contains($kpreF, '>')) {
                            $dateStart = date('d.m.Y', strtotime($preF));
                            $arResult["ITEMS"][$field_code]['INPUT_VALUE']['start'] = $dateStart;
                            $arResult["ITEMS"][$field_code]['LIST']['start'] = $dateStart; // для шаблона
                        }
                        if (str_contains($kpreF, '<')) {
                            $dateEnd = date('d.m.Y', strtotime($preF));
                            $arResult["ITEMS"][$field_code]['INPUT_VALUE']['end'] = $dateEnd;
                            $arResult["ITEMS"][$field_code]['LIST']['end'] = $dateEnd; // для шаблона
                        }
                    }
                }
            }
        }
    }

}
// массив всех значений свойств типа привязка к элементу и разделу
$arIBlocks = [];

foreach ($arResult["arrProp"] as $prop_id => $arProp) {
    $res = "";
    $name = "";
    $value = "";
    $type = "";
    $names = "";
    $values = "";
    $list = array();
    $arResult["arrInputNames"][$FILTER_NAME . "_pf"] = true;
    switch ($arProp["PROPERTY_TYPE"]) {
        case "L":
            $name = $FILTER_NAME . "_pf[" . $arProp["CODE"] . "]";
            $value = $arrPFV[$arProp["CODE"]];

            if ('C' == $arProp['LIST_TYPE']) {
                $arListRadio = array();
                if ('Y' == $arProp['MULTIPLE']) {
                    $type = "CHECKBOX";
                    $list = $arProp["VALUE_LIST"];
                    $arListValue = (is_array($value) ? $value : array($value));
                    foreach ($arProp["VALUE_LIST"] as $key => $val) {
                        $arListRadio[] = '<input type="checkbox" name="' . $name . '[]" value="' . htmlspecialcharsbx($key) . '"' . (in_array($key, $arListValue) ? ' checked' : '') . '> ' . htmlspecialcharsex($val);
                    }
                } else {
                    $type = "RADIO";
                    $list[""] = GetMessage("CC_BCF_ALL");
                    $arListRadio[] = '<input type="radio" name="' . $name . '" value=""' . ($key == $value ? ' checked' : '') . '> ' . GetMessage("CC_BCF_ALL");
                    foreach ($arProp["VALUE_LIST"] as $key => $val) {
                        $arListRadio[] = '<input type="radio" name="' . $name . '" value="' . htmlspecialcharsbx($key) . '"' . ($key == $value ? ' checked' : '') . '> ' . htmlspecialcharsex($val);
                        $list[$key] = $val;
                    }
                }
                $res .= implode('<br>', $arListRadio);
            } else {
                $type = 'SELECT';
                if ($arProp["MULTIPLE"] == "Y")
                    $res .= '<select multiple name="' . $name . '[]" size="' . $arParams["LIST_HEIGHT"] . '">';
                else
                    $res .= '<select name="' . $name . '">';
                $res .= '<option value="">' . GetMessage("CC_BCF_ALL") . '</option>';
                $list[""] = GetMessage("CC_BCF_ALL");
                foreach ($arProp["VALUE_LIST"] as $key => $val) {
                    $res .= '<option';

                    if (($arProp["MULTIPLE"] == "Y") && is_array($value)) {
                        if (in_array($key, $value))
                            $res .= ' selected';
                    } else {
                        if ($key == $value)
                            $res .= ' selected';
                    }

                    $res .= ' value="' . htmlspecialcharsbx($key) . '">' . htmlspecialcharsbx($val) . '</option>';
                    $list[$key] = $val;
                }
                $res .= '</select>';
            }

            if ($arProp["MULTIPLE"] == "Y") {
                if (is_array($value) && count($value) > 0)
                    ${$FILTER_NAME}["PROPERTY_" . $arProp["CODE"]] = $value;
            } else {
                if (!is_array($value) && $value <> '')
                    ${$FILTER_NAME}["PROPERTY_" . $arProp["CODE"]] = $value;
            }
            break;
        case "N":
            $value = $arrPFV[$arProp["CODE"]];
            if ($arProp['USER_TYPE'] == 'UserID') {
                $name = $FILTER_NAME . "_pf[" . $arProp["CODE"] . "]";
                $res = true;
                if (!is_array($value) && $value <> '')
                    ${$FILTER_NAME}["PROPERTY_" . $arProp["CODE"]] = $value;
                break;
            }
            $name_left = $FILTER_NAME . "_pf[" . $arProp["CODE"] . "][LEFT]";
            if (is_array($value) && isset($value["LEFT"]))
                $value_left = $value["LEFT"];
            else
                $value_left = "";
            $res .= '<input type="text" name="' . $name_left . '" size="' . $arParams["NUMBER_WIDTH"] . '" value="' . htmlspecialcharsbx($value_left) . '" />&nbsp;' . GetMessage("CC_BCF_TILL") . '&nbsp;';

            if ($value_left <> '')
                ${$FILTER_NAME}[">=PROPERTY_" . $arProp["CODE"]] = doubleval($value_left);

            $name_right = $FILTER_NAME . "_pf[" . $arProp["CODE"] . "][RIGHT]";
            if (is_array($value) && isset($value["RIGHT"]))
                $value_right = $value["RIGHT"];
            else
                $value_right = "";
            $res .= '<input type="text" name="' . $name_right . '" size="' . $arParams["NUMBER_WIDTH"] . '" value="' . htmlspecialcharsbx($value_right) . '" />';

            if ($value_right <> '')
                ${$FILTER_NAME}["<=PROPERTY_" . $arProp["CODE"]] = doubleval($value_right);

            $type = 'RANGE';
            $names = array($name_left, $name_right);
            $values = array($value_left, $value_right);
            break;
        case "S":
            if ($arProp['USER_TYPE'] == 'DateTime' || $arProp['USER_TYPE'] == 'Date' || $arProp["CODE"] == 'BANK_DATE') {
                $name = $FILTER_NAME . "_pf[" . $arProp["CODE"] . "]";
                $value = $arrPFV[$arProp["CODE"]];
                $res = true;
                if (is_array($value) && !empty($value)) {
                    $date_format = ($arProp['USER_TYPE'] == 'DateTime') ? 'Y-m-d 00:00:00' : 'Y-m-d';
                    if ($value['start'] <> '') {
                        ${$FILTER_NAME}[">=PROPERTY_" . $arProp["CODE"]] = date($date_format, strtotime($value['start']));
                    }
                    if ($value['end'] <> '') {
                        ${$FILTER_NAME}["<=PROPERTY_" . $arProp["CODE"]] = date($date_format, strtotime($value['end']));
                    }
                }
                $type = 'INPUT';
                break;
            }
        case "E":
            if ($arProp['LINK_IBLOCK_ID'] > 0 && empty($arIBlocks[$arProp['LINK_IBLOCK_ID']]['E'])) {
                $rsEl = CIBlockElement::GetList([], ['IBLOCK_ID' => $arProp['LINK_IBLOCK_ID']], false, false, ['ID', 'NAME']);
                while ($arEl = $rsEl->Fetch()) {
                    $arIBlocks[$arProp['LINK_IBLOCK_ID']]['E'][$arEl['ID']] = $arEl['NAME'];
                }
            }
        case "G":
            if ($arProp['LINK_IBLOCK_ID'] > 0 && empty($arIBlocks[$arProp['LINK_IBLOCK_ID']]['G'])) {
                $rsSect = CIBlockSection::GetList([], ['IBLOCK_ID' => $arProp['LINK_IBLOCK_ID'], ''], false, ['ID', 'NAME']);
                while ($arSect = $rsSect->Fetch()) {
                    $arIBlocks[$arProp['LINK_IBLOCK_ID']]['G'][$arSect['ID']] = $arSect['NAME'];
                }
            }
            $name = $FILTER_NAME . "_pf[" . $arProp["CODE"] . "]";
            $value = $arrPFV[$arProp["CODE"]];
            if (!is_array($value)) {
                $res .= '<input type="text" name="' . $name . '" size="' . $arParams["TEXT_WIDTH"] . '" value="' . htmlspecialcharsbx($value) . '" />';

                if ($value <> '')
                    ${$FILTER_NAME}["PROPERTY_" . $arProp["CODE"]] = $value;
            }
            $type = 'INPUT';
            break;
    }
    if ($res) {
        // делаем статус заказа множественным
        if ($arProp['MULTIPLE'] == 'Y') {
            $name .= '[]';
        }
        $arResult["ITEMS"]["PROPERTY_" . $arProp["CODE"]] = [
            "ID" => $prop_id,
            "CODE" => $arProp["CODE"],
            "USER_TYPE" => $arProp["USER_TYPE"],
            "LABEL" => htmlspecialcharsbx($arProp["NAME"]),
            "INPUT_NAME" => $name,
            "INPUT_VALUE" => $value,
            "LIST" => [],
            "MULTIPLE" => $arProp["MULTIPLE"]
        ];
    }
}
// массив с мин. и макс. значениями дат свойств типа DateTime/Date
$prop_date = [];
// массив с мин. и макс. значениями дат поля типа даты
$field_date = [];

// =========================================================================
// БЫСТРОЕ И БЕЗОПАСНОЕ НАПОЛНЕНИЕ ВСЕХ СПИСКОВ ВЫБОРА
// =========================================================================

// 1. Быстрое заполнение Статусов заказа (ORDER_STATUS)
if (isset($arResult["ITEMS"]["PROPERTY_ORDER_STATUS"])) {
    // Включаем множественный выбор для визуального отображения в шаблоне
    $arResult["ITEMS"]["PROPERTY_ORDER_STATUS"]["MULTIPLE"] = "Y";

    // ДОБАВЛЯЕМ КВАДРАТНЫЕ СКОБКИ К ИМЕНИ ПОЛЯ ДЛЯ ПРАВИЛЬНОЙ ОТПРАВКИ МАССИВА
    $arResult["ITEMS"]["PROPERTY_ORDER_STATUS"]["INPUT_NAME"] .= '[]';

    // Если в урле пришел массив выбранных статусов, фиксируем его
    $value = $arrPFV["ORDER_STATUS"];
    $arResult["ITEMS"]["PROPERTY_ORDER_STATUS"]["INPUT_VALUE"] = $value;

    // Наполняем список статусов
    $propertyEnums = CIBlockPropertyEnum::GetList(
        array("SORT" => "ASC", "VALUE" => "ASC"),
        array("IBLOCK_ID" => $arParams["IBLOCK_ID"], "CODE" => "ORDER_STATUS")
    );
    while ($enumFields = $propertyEnums->GetNext()) {
        $arResult["ITEMS"]["PROPERTY_ORDER_STATUS"]['LIST'][$enumFields["ID"]] = $enumFields["VALUE"];
    }

    // Правильно передаем массив выбранных статусов в финальный SQL-фильтр Битрикса
    if (is_array($value) && !empty($value)) {
        ${$FILTER_NAME}["PROPERTY_ORDER_STATUS"] = $value;
    }
}

// 2. Быстрое заполнение Типов заказа (ORDER_TYPE)
if (isset($arResult["ITEMS"]["PROPERTY_ORDER_TYPE"])) {
    $propertyEnums = CIBlockPropertyEnum::GetList(
        array("SORT" => "ASC", "VALUE" => "ASC"),
        array("IBLOCK_ID" => $arParams["IBLOCK_ID"], "CODE" => "ORDER_TYPE")
    );
    while ($enumFields = $propertyEnums->GetNext()) {
        $arResult["ITEMS"]["PROPERTY_ORDER_TYPE"]['LIST'][$enumFields["ID"]] = $enumFields["VALUE"];
    }
}

// 3. ВОЗВРАЩАЕМ СПИСОК ПОЛЬЗОВАТЕЛЕЙ (ORDER_RSB)
if (isset($arResult["ITEMS"]["PROPERTY_ORDER_RSB"])) {
    // Если вы узнали ID группы менеджеров, можно раскомментировать строку ниже и вписать ID
    $arUserFilter = array(
        "ACTIVE" => "Y",
        "GROUPS_ID" => array(5)
    );

    // Добавили SECOND_NAME в массив FIELDS, чтобы Битрикс вытащил отчество из базы данных
    $rsUsers = CUser::GetList(($by = "last_name"), ($order = "asc"), $arUserFilter, array("FIELDS" => array("ID", "NAME", "LAST_NAME", "SECOND_NAME")));
    while ($arUser = $rsUsers->Fetch()) {
        // Собираем полное ФИО: Фамилия Имя Отчество
        $fullName = trim($arUser['LAST_NAME'] . ' ' . $arUser['NAME'] . ' ' . $arUser['SECOND_NAME']);

        if (!empty($fullName)) {
            // Переводим всю строку в верхний регистр (БОЛЬШИЕ БУКВЫ) с поддержкой русского языка
            $fullName = mb_strtoupper($fullName, 'UTF-8');
            $arResult["ITEMS"]["PROPERTY_ORDER_RSB"]['LIST'][$arUser['ID']] = $fullName;
        } else {
            $arResult["ITEMS"]["PROPERTY_ORDER_RSB"]['LIST'][$arUser['ID']] = "[ID: " . $arUser['ID'] . "]";
        }
    }
}

// 4. ВОЗВРАЩАЕМ СПИСОК БИЗНЕС-ЕДИНИЦ (ORDER_BIZ)
$arBizFieldStructure = [
    "ID" => 'ORDER_BIZ',
    "CODE" => 'ORDER_BIZ',
    "USER_TYPE" => '',
    "LABEL" => 'Бизнес-единицы',
    "INPUT_NAME" => 'ordersFilter_pf[ORDER_BIZ]',
    "INPUT_VALUE" => $arrPFV['ORDER_BIZ'] ?? '',
    "MULTIPLE" => 'N',
    "LIST" => []
];

// 1. Моментально собираем ВСЕ папки из инфоблока №26 (включая неактивную Ааль Е.А.)
$rsSectBiz = CIBlockSection::GetList(
    ['NAME' => 'ASC'],
    ['IBLOCK_ID' => 26, 'GLOBAL_ACTIVE' => 'All'],
    false,
    ['ID', 'NAME']
);
while ($arSectBiz = $rsSectBiz->Fetch()) {
    $arBizFieldStructure['LIST'][$arSectBiz['ID']] = $arSectBiz['NAME'];
}

// Записываем данные в оба ключа для абсолютной совместимости с версткой
$arResult["ITEMS"]["PROPERTY_ORDER_BIZ"] = $arBizFieldStructure;
$arResult["ITEMS"]["ORDER_BIZ"] = $arBizFieldStructure;


// 2. Если менеджер выбрал конкретную бизнес-единицу в селекте
if (!empty($arrPFV['ORDER_BIZ'])) {
    $rsbID = [];

    // Находим риелторов внутри выбранного раздела (даже если у папки активность = "Нет")
    $arBizFilter = [
        'IBLOCK_ID' => 26,
        'SECTION_ID' => $arrPFV['ORDER_BIZ'],
        'INCLUDE_SUBSECTIONS' => 'Y',
        'SECTION_GLOBAL_ACTIVE' => 'N'
    ];

    $rsBiz = CIBlockElement::GetList([], $arBizFilter, false, false, ['PROPERTY_BIZ_REALTOR']);
    while ($arBiz = $rsBiz->Fetch()) {
        if (!empty($arBiz['PROPERTY_BIZ_REALTOR_VALUE'])) {
            $rsbID[] = $arBiz['PROPERTY_BIZ_REALTOR_VALUE'];
        }
    }

    // Передаем массив ID найденных сотрудников в финальный SQL-запрос заказов
    if (!empty($rsbID)) {
        ${$FILTER_NAME}["PROPERTY_ORDER_RSB"] = $rsbID;
    } else {
        // Если папка пустая, ставим 0 результатов, чтобы не вывалились чужие заказы
        ${$FILTER_NAME}["PROPERTY_ORDER_RSB"] = 0;
    }
}


if (
    !empty($arParams["PAGER_PARAMS_NAME"])
    && preg_match("/^[A-Za-z_][A-Za-z01-9_]*$/", $arParams["PAGER_PARAMS_NAME"])
) {
    if (!is_array($GLOBALS[$arParams["PAGER_PARAMS_NAME"]]))
        $GLOBALS[$arParams["PAGER_PARAMS_NAME"]] = array();

    foreach ($arResult["ITEMS"] as $arItem) {
        if (isset($arItem["INPUT_NAMES"]) && is_array($arItem["INPUT_NAMES"])) {
            foreach ($arItem["INPUT_NAMES"] as $i => $name) {
                $value = $arItem["~INPUT_VALUES"][$i];
                if ($value <> '') {
                    $GLOBALS[$arParams["PAGER_PARAMS_NAME"]][$name] = $value;
                }
            }
        } elseif (isset($arItem["INPUT_NAME"]) && is_array($arItem["~INPUT_VALUE"])) {
            foreach ($arItem["~INPUT_VALUE"] as $value) {
                if ($value <> '') {
                    $GLOBALS[$arParams["PAGER_PARAMS_NAME"]][$arItem["INPUT_NAME"]][] = $value;
                }
            }
        } elseif (isset($arItem["INPUT_NAME"]) && $arItem["~INPUT_VALUE"] <> '') {
            $GLOBALS[$arParams["PAGER_PARAMS_NAME"]][$arItem["INPUT_NAME"]] = $arItem["~INPUT_VALUE"];
        }
    }

    if ($_REQUEST["del_filter"] <> '') {
        //$GLOBALS[$arParams["PAGER_PARAMS_NAME"]]["del_filter"] = $_REQUEST["del_filter"];
    } elseif ($_REQUEST["set_filter"] <> '') {
        $GLOBALS[$arParams["PAGER_PARAMS_NAME"]]["set_filter"] = $_REQUEST["set_filter"];
    }
}

$arResult["arrInputNames"]["set_filter"] = true;
$arResult["arrInputNames"]["del_filter"] = true;

$arSkip = array(
    "AUTH_FORM" => true,
    "TYPE" => true,
    "USER_LOGIN" => true,
    "USER_CHECKWORD" => true,
    "USER_PASSWORD" => true,
    "USER_CONFIRM_PASSWORD" => true,
    "USER_EMAIL" => true,
    "captcha_word" => true,
    "captcha_sid" => true,
    "login" => true,
    "Login" => true,
    "backurl" => true,
);

foreach (array_merge($_GET, $_POST) as $key => $value) {
    if (
        !isset($arResult["arrInputNames"][$key])
        && !isset($arSkip[$key])
    ) {
        $arResult["ITEMS"]["HIDDEN_" . htmlspecialcharsEx($key)] = array(
            "HIDDEN" => true,
            "INPUT" => '<input type="hidden" name="' . htmlspecialcharsbx($key) . '" value="' . htmlspecialcharsbx($value) . '" />',
        );
    }
}

$this->IncludeComponentTemplate();
