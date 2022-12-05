<?
require_once $_SERVER['DOCUMENT_ROOT'] . '/local/lib/bizproc/BizProcTools.php';

class BizProcToolsOvertime
{
    /**
     *  метод перебирает сотрудников в заявке
     *  находит сотрудников из производственных отделов, для каждого из них создает элемент списка для первого этапа согласования,
     *  и для каждого из них запускает БП 1 этапа согласования
     * @param int $applicationId - id заявки
     * @param string $stepTwoStartTime - дата и время начала второго этапа
     * @return array
     *  метод возвращает массив, в котором находятся строка с производственнвми сотрудниками, строка с непроизводственными сотрудниками и id руководителя автора заявки
     */
    public static function runApproval($applicationId, $stepTwoStartTime) {
        $arProductionEmployees = []; // производственные сотрудники
        $arAdministrativeEmployees = []; // непроизводственные сотрудники

        // заявка
        $arApplication = BizProcTools::getElementWithProperties($applicationId, OVERTIME_APPLICATIONS_IBLOCK_ID);

        // получить "дерево" отделов (с руководителями). Массив не вложенный, просто отсортирован так, что подчиненные отделы идут сразу после родительского
        $arFilter = array('IBLOCK_ID' => DEPARTMENTS_IBLOCK_ID, 'ACTIVE' => 'Y');
        $arSelect = array('ID', 'NAME', 'IBLOCK_SECTION_ID', 'UF_HEAD');
        $arDepartmentsTree = BizProcTools::getSectionTreeList($arFilter, $arSelect);

        // перебрать дерево разделов и получить id всех руководителей
        $arManagersIds = [];
        foreach ($arDepartmentsTree as $arSection) {
            if ($arSection['UF_HEAD'] && !in_array($arSection['UF_HEAD'], $arManagersIds)) {
                $arManagersIds[] = $arSection['UF_HEAD'];
            }
        }

        // перебрать дерево отделов и найти руководителя для тех отделов, где их нет. Тут не нужна рекурсивная функция
        // так как отделы в "дереве" отсортированы так, что подчиненные отделы идут сразу после родительского, поэтому
        // при переборе отдела, у его родительского отдела начальник гарантированно уже найден
        foreach ($arDepartmentsTree as $key => $arDepartment) {
            $arParentDepartment = $arDepartmentsTree[$arDepartment['IBLOCK_SECTION_ID']];
            // проверка, является ли подразделение производственным
            if (in_array($arDepartment['ID'], PRODUCTION_DEPARTMENTS) || $arParentDepartment['IS_IT_PRODUCTION_DEPARTMENT'] == true) {
                $arDepartment['IS_IT_PRODUCTION_DEPARTMENT'] = true;
            }
            if (!$arDepartment['UF_HEAD']) {
                // поиск начальника
                $managerId = $arParentDepartment['UF_HEAD'];
                $arDepartment['UF_HEAD'] = $managerId;
            }
            $arDepartmentsTree[$key] = $arDepartment;
        }

        // получить согласуемых сотрудников и всех начальников всех отделов
        $arUsers = [];
        $arEmployeesIds = $arApplication['EMPLOYEES']['VALUE'];
        $arUsersIds = array_unique(array_merge($arManagersIds, $arEmployeesIds, [$arApplication['CREATED_BY']]));
        $arUsersIdsStr = implode(' | ', $arUsersIds);
        $arFilter = array('ID' => $arUsersIdsStr);
        $arGetListParameters = array(
            'FIELDS' => array('ID', 'NAME', 'LAST_NAME'),
            'SELECT' => array('UF_DEPARTMENT')
        );
        $rsUsers = CUser::GetList(($by="NAME"), ($order="asc"), $arFilter, $arGetListParameters);
        while ($arUser = $rsUsers->Fetch()) {
            $arDepartment = $arDepartmentsTree[$arUser['UF_DEPARTMENT'][0]];
            // поиск сотрудников из производственных подразделений
            if ($arDepartment['IS_IT_PRODUCTION_DEPARTMENT'] == true) {
                $arUser['FROM_PRODUCTION_DEPARTMENT'] = true;
            }
            // поиск начальника
            if ($arDepartment['UF_HEAD'] != $arUser['ID']) { // если сотрудник не является начальником в своем отделе
                $arUser['BOSS_ID'] = $arDepartment['UF_HEAD'];
            } else {
                $arParentDepartment = $arDepartmentsTree[$arDepartment['IBLOCK_SECTION_ID']];
                $arUser['BOSS_ID'] = $arParentDepartment['UF_HEAD'];
            }
            $arUser['BOSS_ID'] = $GLOBALS["USER"]->GetID(); // для тестирования
            $arUsers[$arUser['ID']] = $arUser;
        }

        // период согласования в секундах
        // получение разницы между временем начала 2 этапа и текущим временем
        $stepOneEndTime = MakeTimeStamp($stepTwoStartTime);
        $stepOnePeriod = $stepOneEndTime - time();

        // перебрать сотрудников, для каждого создать элемент списка
        // если есть сотрудники из производственных подразделений, для каждого запустить бизнес процесс индивидуального согласования
        foreach ($arApplication['EMPLOYEES']['VALUE'] as $key => $employeeId) {
            $arUser = $arUsers[$employeeId];

            if ($arUser['FROM_PRODUCTION_DEPARTMENT']) {
                $arProductionEmployees[] = $arUser['ID']; // производственные сотрудники
            } else {
                $arAdministrativeEmployees[] = $arUser['ID']; // непроизводственные сотрудники
            }

            $time =  $arApplication['EMPLOYEES']['DESCRIPTION'][$key];
            $arTime = explode('-', $time);
            $arUser['TIME_FROM'] = $arTime[0];
            $arUser['TIME_TO'] = $arTime[1];

            $arUsers[$employeeId] = $arUser; // обновить пользователя, так как теперь у него есть время переработки

            // создать элемент списка
            $elementId = '';
            if ($arUser['FROM_PRODUCTION_DEPARTMENT'] === true) {
                $elementId = self::createElementForOneEmployee($arUser, $arApplication);
            }

            // запуск БП для сотрудников производственных отделов
            if ($elementId && $stepOnePeriod > 10) { // если период более 10 секунд
                $arParams = array('approvalPeriod' => $stepOnePeriod);
                BizProcTools::runBizProc(OVERTIME_ONE_EMPLOYEE_APPROVAL_BIS_PROC_ID, $arParams, $elementId);
            }
        }

        // сообщение инициатору
        $messageToAuthor = 'Вами создана заявка на сверхурочную работу. № заявки ' . $arApplication['ID'] . '.';
        $messageToAuthor .= ' Проект: ' . $arApplication['PROJECT']['VALUE'] . '.';
        $messageToAuthor .= ' Дата работы: ' . ConvertDateTime($arApplication['OVERTIME_DATE']['VALUE'], "d.m.Y") . '.';
        $messageToAuthor .= ' Вид переработки: ' . $arApplication['OVERTIME_TYPE']['VALUE'] . '.';
        $messageToAuthor .= ' До начала переработки менее 24 часов: ' . ($arApplication['IS_QUICKLY']['VALUE'] ? 'Да' : 'Нет') . '.';
        $messageToAuthor .= ' Обоснование: ' . $arApplication['REASON']['VALUE'] . '.';
        if ($arProductionEmployees) {
            $messageToAuthor .= ' Производственные работники:';
            foreach ($arProductionEmployees as $userId) {
                $arUser = $arUsers[$userId];
                $messageToAuthor .= ' ' . $arUser['NAME'] . ' ' . $arUser['LAST_NAME']  . ' с ' . $arUser['TIME_FROM'] . ':00 по ' . $arUser['TIME_TO'] . ':00;';
            }
        }
        if ($arAdministrativeEmployees) {
            $messageToAuthor .= ' Административные работники:';
            foreach ($arAdministrativeEmployees as $userId) {
                $arUser = $arUsers[$userId];
                $messageToAuthor .= ' ' . $arUser['NAME'] . ' ' . $arUser['LAST_NAME']  . ' с ' . $arUser['TIME_FROM'] . ':00 по ' . $arUser['TIME_TO'] . ':00;';
            }
        }
        if ($arProductionEmployees && $arAdministrativeEmployees) {
            $messageToAuthor .= ' Ваша заявка была разделена на две части, так как в ней есть производственные и непроизводственные сотрудники.';
        }

        return array(
            'productionEmployees' => $arProductionEmployees ? implode('|', $arProductionEmployees) : '',
            'administrativeEmployees' => $arAdministrativeEmployees ? implode('|', $arAdministrativeEmployees) : '',
            'authorBossId' => 'user_' . $arUsers[$arApplication['CREATED_BY']]['BOSS_ID'],
            'messageToAuthor' => $messageToAuthor,
        );
    }

    /**
     *  создать элемент списка для согласования одного сотрудника элемент списка.
     * @param array $arUser - согласуемый сотрудник
     * @param array $arApplication - данные заявки
     * @return string возвращает id созданного элемента
     */
    public static function createElementForOneEmployee($arUser, $arApplication)
    {
        // имя согласуемого сотрудника
        $employeeName = $arUser['NAME'] . ' ' . $arUser['LAST_NAME'];

        // описание задачи для руководителя
        $message = '';
        if ($arApplication['IS_QUICKLY']['VALUE']) {
            $message .= '[B]Срочная заявка[/B]' . PHP_EOL;
        }
        $message .= 'Пожалуйста выразите свое согласие на сверхурочную работу для сотрудника [url=/company/personal/user/' . $arUser['ID'] . '/]' . $employeeName . '[/url]' . PHP_EOL;
        $message .= ' Проект: ' . $arApplication['PROJECT']['VALUE'] . PHP_EOL;
        $message .= 'Поступила заявка на сверхурочную работу для этого сотрудника на ' . ConvertDateTime($arApplication['OVERTIME_DATE']['VALUE'], "d.m.Y");
        $message .= ' с ' . $arUser['TIME_FROM'] . ':00 по ' . $arUser['TIME_TO'] . ':00';
        $period = intval($arUser['TIME_TO']) - intval($arUser['TIME_FROM']);
        $message .= ' (' . strval($period) . ' ' . self::getHours(strval($period)) . ')' . PHP_EOL; // период переработки
        $message .= 'Обоснование: ' . $arApplication['REASON']['VALUE'];

        $arFields = array(
            'PROPERTY_VALUES' => array(
                'APPLICATION_ID' => $arApplication['ID'], // id заявки,
                'EMPLOYEE_ID' => $arUser['ID'],
                'BOSS_ID' => $arUser['BOSS_ID'],
                'STATUS' => 'Новое',
                'TIME_FROM' => $arUser['TIME_FROM'],
                'TIME_TO' => $arUser['TIME_TO'],
            )
        );

        $arFields['IBLOCK_ID'] = OVERTIME_ONE_EMPLOYEE_APPROVAL_IBLOCK_ID;
        $arFields['CREATED_BY'] = "user_".$GLOBALS["USER"]->GetID();
        $arFields['NAME'] = date('d.m.Y') . ' ' . $employeeName;
        $arFields['PREVIEW_TEXT'] = $message;

        $documentId = BizProcTools::addListElement($arFields);
        return $documentId;
    }

    // метод принимает id заявки на сверхурочную работу
    // метод возвращает таблицу с результатами согласований производственных сотрудников из первого этапа согласования
    // также метод обновляет поле заявки с id производственных сортрудников, удаляя из него тех, кого отклонили на 1 этапе
    public static function getMessageForStepTwo($applicationId)
    {
        // получить элементы с результатами согласований
        $arSelect = Array("ID", "PROPERTY_TIME_FROM", "PROPERTY_TIME_TO", "PROPERTY_APPLICATION_ID", "PROPERTY_EMPLOYEE_ID", "PROPERTY_WHO_APPROVED",
            "PROPERTY_BOSS_ID", "PROPERTY_STATUS", "PROPERTY_COMMENT");
        $arFilter = Array(
            "IBLOCK_ID" => OVERTIME_ONE_EMPLOYEE_APPROVAL_IBLOCK_ID,
            "PROPERTY_APPLICATION_ID" => $applicationId,
        );

        // сортировка, сначала согласованные, затем без ответа, затем отклоненные (поле сортировка меняется внутри бизнес процесса)
        $arOrder = array('SORT' => 'ASC');

        $arApprovals = BizProcTools::getElementsFromIBlock($arSelect, $arFilter, $arOrder);

        if (!$arApprovals) {
            return '';
        }

        // обновить поле заявки с id производственных сортрудников после 1 этапа
        $arApproved = [];
        $needUpdate = false; // обновить только, если есть отклоненные
        foreach ($arApprovals as $arItem) {
            if ($arItem['PROPERTY_STATUS_VALUE'] == 'Отклонено') {
                $needUpdate = true;
            } else {
                $arApproved[] = $arItem['PROPERTY_EMPLOYEE_ID_VALUE'];
            }
        }
        if ($needUpdate) {
            $arProp = array('PRODUCTION_EMPLOYEES' => implode('|', $arApproved));
            BizProcTools::setElementPropertyValuesEx($applicationId, OVERTIME_APPLICATIONS_IBLOCK_ID, $arProp);
        }

        // заявка
        $arApplication = BizProcTools::getElementWithProperties($applicationId, OVERTIME_APPLICATIONS_IBLOCK_ID);
        // автор заявки
        $authorId = $arApplication['CREATED_BY'];

        // получить имена сотрудников
        $arUsersIds = self::getApprovalUsersIds($arApprovals);
        $arUsersIds = array_merge($arUsersIds, [$authorId]);
        $arUsers = self::getUsers($arUsersIds);

        $message = 'Пожалуйста выразите свое согласие с заявкой на сверхурочную работу. ' . PHP_EOL;
        $message .= 'Инициатор заявки: [url=/company/personal/user/' . $authorId . '/]' . $arUsers[$authorId]['NAME'] . ' ' . $arUsers[$authorId]['LAST_NAME'] . '[/url]' . PHP_EOL;;
        $message .= 'Проект: ' . $arApplication['PROJECT']['VALUE'] . PHP_EOL;
        $message .= 'Дата работы: ' . ConvertDateTime($arApplication['OVERTIME_DATE']['VALUE'], "d.m.Y") . PHP_EOL . PHP_EOL;
        $message .= 'Заявленные сотрудники: ' . PHP_EOL;
        $i = 0;
        foreach ($arApprovals as $arItem) {
            $i++;
            $employeeId = $arItem['PROPERTY_EMPLOYEE_ID_VALUE'];
            $stepOneResult = 'Ошибка получения результата согласования';
            if ($arItem['PROPERTY_STATUS_VALUE'] == 'Согласовано') {
                $stepOneResult = 'На 1 этапе согласован';
            } elseif ($arItem['PROPERTY_STATUS_VALUE'] == 'Отклонено') {
                $stepOneResult = 'На 1 этапе отклонен';
            } elseif ($arItem['PROPERTY_STATUS_VALUE'] == 'Новое') {
                $stepOneResult = 'На 1 этапе не утверждался';
            }

            $message .= '[B]' . $i . '.[/B] '; // порядковый номер
            $message .= '[url=/company/personal/user/' . $employeeId . '/]' . $arUsers[$employeeId]['NAME'] . ' ' . $arUsers[$employeeId]['LAST_NAME'] . '[/url]'; // ссылка на сотрудника
            $message .= ' С ' . $arItem['PROPERTY_TIME_FROM_VALUE'] . ':00 по ' . $arItem['PROPERTY_TIME_TO_VALUE'] . ':00'; // время переработки
            $period = intval($arItem['PROPERTY_TIME_TO_VALUE']) - intval($arItem['PROPERTY_TIME_FROM_VALUE']);
            $message .= ' (' . strval($period) . ' ' . self::getHours(strval($period)) . ')'; // период переработки
            $message .= ' [B]' . $stepOneResult . '[/B]'; // результат первого этапа
            if ($whoApprovedId = $arItem['PROPERTY_WHO_APPROVED_VALUE']) {
                $message .= ' Кто согласовывал: [url=/company/personal/user/' . $whoApprovedId . '/]' . $arUsers[$whoApprovedId]['NAME'] . ' ' . $arUsers[$whoApprovedId]['LAST_NAME'] . '[/url]';
            }
            $message .= PHP_EOL;
            if ($arItem['PROPERTY_STATUS_VALUE'] === 'Отклонено') {
                $message .= '[B]Комментарий: ' . $arItem['PROPERTY_COMMENT_VALUE'] . '[/B]' . PHP_EOL;
            }
            $message .= PHP_EOL;
        }

        return $message;
    }

    // метод принимает id заявки на сверхурочныую работу
    // метод возвращает описание задачи для 3 этапа согласования
    public static function getMessageForStepThree($applicationId)
    {
        // получить элементы с результатами согласований
        $arSelect = Array("ID", "PROPERTY_TIME_FROM", "PROPERTY_TIME_TO", "PROPERTY_APPLICATION_ID", "PROPERTY_EMPLOYEE_ID", "PROPERTY_WHO_APPROVED", "PROPERTY_STATUS", "PROPERTY_COMMENT");
        $arFilter = Array(
            "IBLOCK_ID" => OVERTIME_ONE_EMPLOYEE_APPROVAL_IBLOCK_ID,
            "PROPERTY_APPLICATION_ID" => $applicationId,
            "!PROPERTY_STATUS" => 'Отклонено',
        );

        $arApprovals = BizProcTools::getElementsFromIBlock($arSelect, $arFilter);

        if (!$arApprovals) {
            return '';
        }

        // заявка
        $arApplication = BizProcTools::getElementWithProperties($applicationId, OVERTIME_APPLICATIONS_IBLOCK_ID);

        // автор заявки
        $authorId = $arApplication['CREATED_BY'];
        // кто согласовал 2 этап
        $whoApprovedStepTwoId = $arApplication['WHO_APPROVED_STEP_TWO']['VALUE'];

        // получить имена сотрудников
        $arUsersIds = self::getApprovalUsersIds($arApprovals);
        $arUsersIds = array_merge($arUsersIds, [$authorId, $whoApprovedStepTwoId]);
        $arUsers = self::getUsers($arUsersIds);

        $userApprovedStepTwo = $arUsers[$whoApprovedStepTwoId];

        $message = 'Пожалуйста выразите свое согласие с заявкой на сверхурочную работу. ' . PHP_EOL;
        $message .= 'Инициатор заявки: [url=/company/personal/user/' . $authorId . '/]' . $arUsers[$authorId]['NAME'] . ' ' . $arUsers[$authorId]['LAST_NAME'] . '[/url]' . PHP_EOL;
        $message .= 'Проект: ' . $arApplication['PROJECT']['VALUE'] . PHP_EOL;
        $message .= 'Дата работы: ' . ConvertDateTime($arApplication['OVERTIME_DATE']['VALUE'], "d.m.Y") . PHP_EOL;
        $message .= 'Сотрудник, согласовавший 2 этап: ';
        $message .= '[url=/company/personal/user/' . $userApprovedStepTwo['ID'] . '/]' . $userApprovedStepTwo['NAME'] . ' ' . $userApprovedStepTwo['LAST_NAME'] . '[/url]' . PHP_EOL;
        $message .= 'Заявленные сотрудники: ' . PHP_EOL;
        $i = 0;
        foreach ($arApprovals as $arItem) {
            $i++;
            $employeeId = $arItem['PROPERTY_EMPLOYEE_ID_VALUE'];

            $message .= '[B]' . $i . '.[/B] '; // порядковый номер
            $message .= '[url=/company/personal/user/' . $employeeId . '/]' . $arUsers[$employeeId]['NAME'] . ' ' . $arUsers[$employeeId]['LAST_NAME'] . '[/url]'; // ссылка на сотрудника
            $message .= ' С ' . $arItem['PROPERTY_TIME_FROM_VALUE'] . ':00 по ' . $arItem['PROPERTY_TIME_TO_VALUE'] . ':00'; // время переработки
            $period = intval($arItem['PROPERTY_TIME_TO_VALUE']) - intval($arItem['PROPERTY_TIME_FROM_VALUE']);
            $message .= ' (' . strval($period) . ' ' . self::getHours(strval($period)) . ')'; //период переработки
            $message .= PHP_EOL;
        }

        return $message;
    }

    // метод принимает id заявки на сверхурочную работу
    // метод возвращает описание для этапа согласования административных сотрудников
    public static function getMessageForAdminStep($applicationId)
    {
        // заявка
        $arApplication = BizProcTools::getElementWithProperties($applicationId, OVERTIME_APPLICATIONS_IBLOCK_ID);
        // время переработок заявленных сотрудников
        $arEmployeesTimes = [];
        foreach ($arApplication['EMPLOYEES']['VALUE'] as $key => $employeeId) {
            $arTime = [];
            $strTime = $arApplication['EMPLOYEES']['DESCRIPTION'][$key];
            $time = explode('-', $strTime);
            $arTime['FROM'] = $time[0];
            $arTime['TO'] = $time[1];
            $arEmployeesTimes[$employeeId] = $arTime;
        }

        // получить имена сотрудников
        $arEmployeeIds = explode('|', $arApplication['ADMINISTRATIVE_EMPLOYEES']['VALUE']);
        $authorId = $arApplication['CREATED_BY'];
        $arUsersIds = array_merge($arEmployeeIds, [$authorId]);
        $arUsers = self::getUsers($arUsersIds);

        $message = 'Пожалуйста выразите свое согласие с заявкой на сверхурочную работу. ' . PHP_EOL;
        $message .= 'Инициатор заявки: [url=/company/personal/user/' . $authorId . '/]' . $arUsers[$authorId]['NAME'] . ' ' . $arUsers[$authorId]['LAST_NAME'] . '[/url]' . PHP_EOL;;
        $message .= 'Проект: ' . $arApplication['PROJECT']['VALUE'] . PHP_EOL;
        $message .= 'Дата работы: ' . ConvertDateTime($arApplication['OVERTIME_DATE']['VALUE'], "d.m.Y") . PHP_EOL;
        $message .= 'Заявленные сотрудники: ' . PHP_EOL;
        $i = 0;
        foreach ($arEmployeeIds as $employeeId) {
            $i++;
            $message .= '[B]' . $i . '.[/B] '; // порядковый номер
            $message .= '[url=/company/personal/user/' . $employeeId . '/]' . $arUsers[$employeeId]['NAME'] . ' ' . $arUsers[$employeeId]['LAST_NAME'] . '[/url]'; // ссылка на сотрудника
            $message .= ' С ' . $arEmployeesTimes[$employeeId]['FROM'] . ':00 по ' . $arEmployeesTimes[$employeeId]['TO'] . ':00'; // время переработки
            $period = intval($arEmployeesTimes[$employeeId]['TO']) - intval($arEmployeesTimes[$employeeId]['FROM']);
            $message .= ' (' . strval($period) . ' ' . self::getHours(strval($period)) . ')'; //период переработки
            $message .= PHP_EOL;
        }

        return $message;
    }

    // метод принимает id заявки на сверхурочную работу
    // метод возвращает сообщение автору заявки после 3 этапа, если решение положительное
    public static function getMessageForAuthor($applicationId)
    {
        // получить элементы с результатами согласований
        $arSelect = Array("ID", "PROPERTY_APPLICATION_ID", "PROPERTY_EMPLOYEE_ID", "PROPERTY_WHO_APPROVED", "PROPERTY_STATUS", "PROPERTY_COMMENT");
        $arFilter = Array(
            "IBLOCK_ID" => OVERTIME_ONE_EMPLOYEE_APPROVAL_IBLOCK_ID,
            "PROPERTY_APPLICATION_ID" => $applicationId,
        );

        $arApprovals = BizProcTools::getElementsFromIBlock($arSelect, $arFilter);

        // получить имена сотрудников
        $arUsersIds = self::getApprovalUsersIds($arApprovals);
        $arUsers = self::getUsers($arUsersIds);

        $arApprovedUsers = []; // согласованные
        $arRejectedUsers = []; // отклоненные
        foreach ($arApprovals as $arItem) {
            if ($arItem['PROPERTY_STATUS_VALUE'] == 'Отклонено') {
                $arRejectedUsers[] = $arUsers[$arItem['PROPERTY_EMPLOYEE_ID_VALUE']];
            } else {
                $arApprovedUsers[] = $arUsers[$arItem['PROPERTY_EMPLOYEE_ID_VALUE']];
            }
        }

        // заявка
        $arApplication = BizProcTools::getElementWithProperties($applicationId, OVERTIME_APPLICATIONS_IBLOCK_ID);

        $message = 'Закончено согласование производственных сотрудников по вашей заявке № ' . $arApplication['ID'] . ' на сверхурочную работу по проекту ' . $arApplication['PROJECT']['VALUE'];
        $message .= ' на ' . ConvertDateTime($arApplication['OVERTIME_DATE']['VALUE'], "d.m.Y") . PHP_EOL;
        if ($arApprovedUsers) {
            $message .= ' Согласованные сотрудники:';
            foreach ($arApprovedUsers as $arUser) {
                $message .= ' ' . $arUser['NAME'] . ' ' . $arUser['LAST_NAME'] . ';';
            }
            $message .= PHP_EOL;
        }
        if ($arRejectedUsers) {
            $message .= ' Отклоненные сотрудники:';
            foreach ($arRejectedUsers as $arUser) {
                $message .= ' ' . $arUser['NAME'] . ' ' . $arUser['LAST_NAME'] . ';';
            }
            $message .= PHP_EOL;
        }

        return $message;
    }

    // метод принимает id заявки на сверхурочную работу
    // метод возвращает сообщение автору заявки после согласования административных сотрудников
    public static function getMessageAfterAdminStep($applicationId)
    {
        // заявка
        $arApplication = BizProcTools::getElementWithProperties($applicationId, OVERTIME_APPLICATIONS_IBLOCK_ID);

        // получить имена сотрудников
        $arEmployeeIds = explode('|', $arApplication['ADMINISTRATIVE_EMPLOYEES']['VALUE']);
        $arUsers = self::getUsers($arEmployeeIds);

        $message = 'Закончено согласование непроизводственных сотрудников по вашей заявке № ' . $arApplication['ID'];
        $message .= ' на сверхурочную работу по проекту ' . $arApplication['PROJECT']['VALUE'] . ' на ' . ConvertDateTime($arApplication['OVERTIME_DATE']['VALUE'], "d.m.Y") . PHP_EOL;
        $message .= 'Согласованные сотрудники: ' . PHP_EOL;
        foreach ($arEmployeeIds as $employeeId) {
            $message .= '[url=/company/personal/user/' . $employeeId . '/]' . $arUsers[$employeeId]['NAME'] . ' ' . $arUsers[$employeeId]['LAST_NAME'] . '[/url]' . PHP_EOL;
        }

        return $message;
    }

    // получить массив сотрудников
    private static function getUsers($arUsersIds) {
        $strUsersIds = implode('|', $arUsersIds);
        $arFilter = array('ID' => $strUsersIds);
        $arParams = array('FIELDS' => array('ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'WORK_POSITION'), "SELECT" => array("UF_*"));
        $rsUsers = CUser::GetList(($by="id"), ($order="asc"), $arFilter, $arParams);
        while ($arUser = $rsUsers->GetNext()) {
            $arUsers[$arUser['ID']] = $arUser;
        }
        return $arUsers;
    }

    // получить массив id согласуемых сотрудников, id руководителей и id тех, кто согласовал
    private static function getApprovalUsersIds($arApprovals, $authorId = '') {
        $arUsersIds = [];
        if ($authorId) {
            $arUsersIds[] = $authorId;
        }
        foreach ($arApprovals as $arItem) {
            if (!in_array($arItem['PROPERTY_EMPLOYEE_ID_VALUE'], $arUsersIds)) {
                $arUsersIds[] = $arItem['PROPERTY_EMPLOYEE_ID_VALUE'];
            }
            if ($arItem['PROPERTY_BOSS_ID_VALUE'] && !in_array($arItem['PROPERTY_BOSS_ID_VALUE'], $arUsersIds)) {
                $arUsersIds[] = $arItem['PROPERTY_BOSS_ID_VALUE'];
            }
            if ($arItem['PROPERTY_WHO_APPROVED_VALUE'] && !in_array($arItem['PROPERTY_WHO_APPROVED_VALUE'], $arUsersIds)) {
                $arUsersIds[] = $arItem['PROPERTY_WHO_APPROVED_VALUE']; // кто согласовал (на случай если руководитель делегировал согласование кому то)
            }
        }
        return $arUsersIds;
    }


    // сгенерировать уведомления для сотрудников
    // $employeesType - тип сотрудников (производственные или административные)
    public static function generateNotifications($applicationId, $employeesType = 'production') {

        // заявка
        $arApplication = BizProcTools::getElementWithProperties($applicationId, OVERTIME_APPLICATIONS_IBLOCK_ID);
        // время переработок заявленных сотрудников
        $arEmployeesTimes = [];
        foreach ($arApplication['EMPLOYEES']['VALUE'] as $key => $employeeId) {
            $arTime = [];
            $strTime = $arApplication['EMPLOYEES']['DESCRIPTION'][$key];
            $time = explode('-', $strTime);
            $arTime['FROM'] = $time[0];
            $arTime['TO'] = $time[1];
            $arEmployeesTimes[$employeeId] = $arTime;
        }

        // получить имена сотрудников
        if ($employeesType == 'production') {
            $propertyCode = 'PRODUCTION_EMPLOYEES';
        } else {
            $propertyCode = 'ADMINISTRATIVE_EMPLOYEES';
        }
        $arEmployeeIds = explode('|', $arApplication[$propertyCode]['VALUE']);
        $arUsers = self::getUsers($arEmployeeIds);

        // получить отделы
        $arDepartments = [];
        $rsSections = CIBlockSection::GetList(array(), array('IBLOCK_ID' => DEPARTMENTS_IBLOCK_ID, 'ACTIVE' => 'Y'), false, array('ID', 'NAME'));
        while ($arSect = $rsSections->Fetch()) {
            $arDepartments[$arSect['ID']] = $arSect;
        }

        // сгенерировать уведомление для каждого сотрудника
        $arNotifications = [];
        foreach ($arEmployeeIds as $employeeId) {
            $arUser = $arUsers[$employeeId];
            $userDepartment = $arDepartments[$arUser['UF_DEPARTMENT'][0]]['NAME'];

            // создать документ Word
            $arFields = array(
                'employeeName' => $arUser['LAST_NAME'] . ' ' . $arUser['NAME'] . ' ' . $arUser['SECOND_NAME'],
                //'employeeShortName' => mb_substr($arUser['NAME'], 0, 1)  . '. ' .  mb_substr($arUser['SECOND_NAME'], 0, 1) . '. ' . $arUser['LAST_NAME'],
                'position' => $arUser['WORK_POSITION'],
                'department' => $userDepartment,
                //'reason' => $arApplication['REASON']['VALUE'],
                //'timeFrom' => $arEmployeesTimes[$employeeId]['FROM'] . ':00',
                //'timeTo' => $arEmployeesTimes[$employeeId]['TO'] . ':00',
                //'currentDate' => date('d.m.Y'),
                //'overtimeDate' => ConvertDateTime($arApplication['OVERTIME_DATE']['VALUE'], "d.m.Y"),
            );
            $fileName = $arUser['LAST_NAME'] . mb_substr($arUser['NAME'], 0, 1) . mb_substr($arUser['SECOND_NAME'], 0, 1);

            if ($arApplication['OVERTIME_TYPE']['VALUE_XML_ID'] == 'weekday') {
                $templatePath = '/upload/overtimeInWeekday.docx'; // в будни
            } else {
                $templatePath = '/upload/overtimeInWeekend.docx'; // в выходной
            }

            $filePath = BizProcTools::generateDocument($templatePath, $arFields, $fileName, 'temp');
            if ($filePath) {
                $arNotifications[] = $filePath;
            }
        }

        // сохранить сгенерированные документы в заявке
        if ($employeesType == 'production') {
            $propertyCode = 'NOTIFICATIONS_PROD';
        } else {
            $propertyCode = 'NOTIFICATIONS_ADMIN';
        }
        if ($arNotifications) {
            BizProcTools::loadFilesToIBlock($arNotifications, $applicationId, $propertyCode, true);
        }
    }

    // сгенерировать ссылки на документы
    // $employeesType - тип сотрудников (производственные или административные)
    public static function getDocumentsLinks($arApplication, $employeesType = 'production') {
        $strLinks = '';
        if ($employeesType == 'production') {
            $propertyCode = 'NOTIFICATIONS_PROD';
        } else {
            $propertyCode = 'NOTIFICATIONS_ADMIN';
        }
        foreach ($arApplication[$propertyCode]['VALUE'] as $documentId) {
            $arFile = CFile::GetFileArray($documentId);
            $src =$arFile['SRC'];
            $name = $arFile['ORIGINAL_NAME'];
            $link = '[url=' . $src . ']' . $name . '[/url]';
            $strLinks .= $link . PHP_EOL;
        }
        return $strLinks;
    }

    // получить название и описание задачи для отдела персонала при согласовании административных сотрудников
    public static function getTaskToHRForAdminEmployees($applicationId) {
        // заявка
        $arApplication = BizProcTools::getElementWithProperties($applicationId, OVERTIME_APPLICATIONS_IBLOCK_ID);
        // время переработок заявленных сотрудников
        $arEmployeesTimes = [];
        foreach ($arApplication['EMPLOYEES']['VALUE'] as $key => $employeeId) {
            $arTime = [];
            $strTime = $arApplication['EMPLOYEES']['DESCRIPTION'][$key];
            $time = explode('-', $strTime);
            $arTime['FROM'] = $time[0];
            $arTime['TO'] = $time[1];
            $arEmployeesTimes[$employeeId] = $arTime;
        }

        // получить имена сотрудников
        $arEmployeeIds = explode('|', $arApplication['ADMINISTRATIVE_EMPLOYEES']['VALUE']);
        $authorId = $arApplication['CREATED_BY'];
        $arUsersIds = array_merge($arEmployeeIds, [$authorId]);
        $arUsers = self::getUsers($arUsersIds);

        $message = 'Согласована сверхурочная работа для непроизводственных сотрудников по заявке № ' . $arApplication['ID'] . PHP_EOL;
        $message .= 'Инициатор заявки: [url=/company/personal/user/' . $authorId . '/]' . $arUsers[$authorId]['NAME'] . ' ' . $arUsers[$authorId]['LAST_NAME'] . '[/url]' . PHP_EOL;;
        $message .= 'Дата работы: ' . ConvertDateTime($arApplication['OVERTIME_DATE']['VALUE'], "d.m.Y") . PHP_EOL;
        $message .= 'Проект: ' . $arApplication['PROJECT']['VALUE'] . PHP_EOL;
        $message .= 'Вид сверхурочных работ: ' . $arApplication['OVERTIME_TYPE']['VALUE'] . PHP_EOL;
        $message .= 'Обоснование: ' . $arApplication['REASON']['VALUE'] . PHP_EOL;
        $message .= 'Сотрудники:' . PHP_EOL;

        $taskTitle = 'Сверхурочная работа (';

        $i = 0;
        foreach ($arEmployeeIds as $employeeId) {
            $i++;
            $arUser = $arUsers[$employeeId];
            $userName = $arUser['LAST_NAME'] . ' ' . $arUser['NAME'] . ' ' . $arUser['SECOND_NAME'];
            $userLink = '[url=/company/personal/user/' . $employeeId . '/]' . $userName . '[/url]';
            $message .= $userLink . ' с ' . $arEmployeesTimes[$employeeId]['FROM'] . ':00 по ' . $arEmployeesTimes[$employeeId]['TO'] . ':00'; // время переработки
            $period = intval($arEmployeesTimes[$employeeId]['TO']) - intval($arEmployeesTimes[$employeeId]['FROM']);
            $message .= ' (' . strval($period) . ' ' . self::getHours(strval($period)) . ')'; //период переработки
            $message .= PHP_EOL;

            $taskTitle .= $i == 1 ? '' : ', ';
            $taskTitle .= $arUser['LAST_NAME'];
        }

        $taskTitle .= ')';

        // кто и когда утвердил этап согласования административных сотрудников
        $message .= PHP_EOL . 'Весь список утвержден сотрудником: ';
        $whoApprovedAdminStepId = $arApplication['WHO_APPROVED_ADMIN_EMPLOYEES']['VALUE'];
        $whoApprovedAdminStepName = $arUsers[$whoApprovedAdminStepId]['NAME'] . ' ' . $arUsers[$whoApprovedAdminStepId]['LAST_NAME'];
        $whoApprovedAdminStepLink = '[url=/company/personal/user/' . $whoApprovedAdminStepId . '/]' . $whoApprovedAdminStepName . '[/url]';
        $message .= $whoApprovedAdminStepLink . ' ' . $arApplication['STEP_TWO_APPROVAL_TIME']['VALUE'] . PHP_EOL;

        $links = self::getDocumentsLinks($arApplication, 'administrative'); // ссылки на вордовские уведомления для сотрудников
        $message .= 'Уведомления:' . PHP_EOL;
        $message .= $links;

        return ['TITLE' => $taskTitle, 'DESCRIPTION' => $message];
    }

    // получить название и описание задачи для отдела персонала при согласовании производственных сотрудников
    public static function getTaskToHRForProdEmployees($applicationId) {
        // заявка
        $arApplication = BizProcTools::getElementWithProperties($applicationId, OVERTIME_APPLICATIONS_IBLOCK_ID);

        // получить элементы с результатами согласований
        $arSelect = Array("ID", "PROPERTY_TIME_FROM", "PROPERTY_TIME_TO", "PROPERTY_APPLICATION_ID", "PROPERTY_EMPLOYEE_ID", "PROPERTY_WHO_APPROVED", "PROPERTY_STATUS", "PROPERTY_COMMENT");
        $arFilter = Array(
            "IBLOCK_ID" => OVERTIME_ONE_EMPLOYEE_APPROVAL_IBLOCK_ID,
            "PROPERTY_APPLICATION_ID" => $applicationId,
            "!PROPERTY_STATUS" => 'Отклонено',
        );

        $arApprovals = BizProcTools::getElementsFromIBlock($arSelect, $arFilter);

        // автор заявки
        $authorId = $arApplication['CREATED_BY'];

        // получить имена сотрудников
        $arUsersIds = self::getApprovalUsersIds($arApprovals);
        $arUsersIds = array_merge($arUsersIds, [$authorId, $arApplication['WHO_APPROVED_STEP_TWO'], $arApplication['WHO_APPROVED_STEP_THREE']]);
        $arUsers = self::getUsers($arUsersIds);

        $message = 'Согласована сверхурочная работа для производственных сотрудников по заявке № ' . $arApplication['ID'] . PHP_EOL;
        $message .= 'Инициатор заявки: [url=/company/personal/user/' . $authorId . '/]' . $arUsers[$authorId]['NAME'] . ' ' . $arUsers[$authorId]['LAST_NAME'] . '[/url]' . PHP_EOL;;
        $message .= 'Дата работы: ' . ConvertDateTime($arApplication['OVERTIME_DATE']['VALUE'], "d.m.Y") . PHP_EOL;
        $message .= 'Проект: ' . $arApplication['PROJECT']['VALUE'] . PHP_EOL;
        $message .= 'Вид сверхурочных работ: ' . $arApplication['OVERTIME_TYPE']['VALUE'] . PHP_EOL;
        $message .= 'Обоснование: ' . $arApplication['REASON']['VALUE'] . PHP_EOL;
        $message .= 'Сотрудники:' . PHP_EOL;

        $taskTitle = 'Сверхурочная работа (';

        $i = 0;
        foreach ($arApprovals as $arItem) {
            $i++;
            // сотрудник
            $userId = $arItem['PROPERTY_EMPLOYEE_ID_VALUE'];
            $userName = $arUsers[$userId]['LAST_NAME'] . ' ' . $arUsers[$userId]['NAME'] . ' ' . $arUsers[$userId]['SECOND_NAME'];
            $userLink = '[url=/company/personal/user/' . $userId . '/]' . $userName . '[/url]';
            $message .= $i . ' ' . $userLink . ' с ' . $arItem['PROPERTY_TIME_FROM_VALUE'] . ':00 по ' . $arItem['PROPERTY_TIME_TO_VALUE'] . ':00'; // время переработки
            $period = intval($arItem['PROPERTY_TIME_TO_VALUE']) - intval($arItem['PROPERTY_TIME_FROM_VALUE']);
            $message .= ' (' . strval($period) . ' ' . self::getHours(strval($period)) . ')'; //период переработки
            $message .= ' Утвержден на первом этапе сотрудником: ';

            // кто и когда утвердил 1 этап если сотрудник утвержден на 1 этапе
            if ($arItem['PROPERTY_STATUS_VALUE'] == 'Согласовано') {
                // кто утвердил
                $whoApprovedStepOneId = $arItem['PROPERTY_WHO_APPROVED_VALUE'];
                $whoApprovedStepOneName = $arUsers[$whoApprovedStepOneId]['NAME'] . ' ' . $arUsers[$whoApprovedStepOneId]['LAST_NAME'];
                $whoApprovedStepOneLink = '[url=/company/personal/user/' . $whoApprovedStepOneId . '/]' . $whoApprovedStepOneName . '[/url]';
                $message .= $whoApprovedStepOneLink . ' ' . $arItem['PROPERTY_APPROVED_DATE_VALUE'] . PHP_EOL;
            } else {
                $message .= 'Не утверждался' . PHP_EOL;
            }

            $taskTitle .= $i == 1 ? '' : ', ';
            $taskTitle .= $arUsers[$userId]['LAST_NAME'];
        }

        $taskTitle .= ')';

        // кто и когда утвердил 2 этап
        $message .= PHP_EOL . 'Весь список утвержден на втором этапе сотрудником: ';
        $whoApprovedStepTwoId = $arApplication['WHO_APPROVED_STEP_TWO']['VALUE'];
        $whoApprovedStepTwoName = $arUsers[$whoApprovedStepTwoId]['NAME'] . ' ' . $arUsers[$whoApprovedStepTwoId]['LAST_NAME'];
        $whoApprovedStepTwoLink = '[url=/company/personal/user/' . $whoApprovedStepTwoId . '/]' . $whoApprovedStepTwoName . '[/url]';
        $message .= $whoApprovedStepTwoLink . ' ' . $arApplication['STEP_TWO_APPROVAL_TIME']['VALUE'] . PHP_EOL;

        // кто и когда утвердил 3 этап
        $message .= 'Весь список утвержден на третьем этапе сотрудником: ';
        $whoApprovedStepThreeId = $arApplication['WHO_APPROVED_STEP_TWO']['VALUE'];
        $whoApprovedStepThreeName = $arUsers[$whoApprovedStepThreeId]['NAME'] . ' ' . $arUsers[$whoApprovedStepThreeId]['LAST_NAME'];
        $whoApprovedStepThreeLink = '[url=/company/personal/user/' . $whoApprovedStepThreeId . '/]' . $whoApprovedStepThreeName . '[/url]';
        $message .= $whoApprovedStepThreeLink . ' ' . $arApplication['STEP_THREE_APPROVAL_TIME']['VALUE'] . PHP_EOL;

        $links = self::getDocumentsLinks($arApplication); // ссылки на вордовские уведомления для сотрудников
        $message .= 'Уведомления:' . PHP_EOL;
        $message .= $links;

        return ['TITLE' => $taskTitle, 'DESCRIPTION' => $message];
    }

    public static function getHours($value) {
        $arValues = array(
            'час' => array('1', '21'),
            'часа' => array('2', '3', '4', '22', '23', '24'),
            'часов' => array('5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20'),
        );
        foreach ($arValues as $key => $subArray) {
            if (in_array($value, $subArray)) return $key;
        }
        return 'часов';
    }
}

