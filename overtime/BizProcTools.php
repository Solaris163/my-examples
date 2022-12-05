<?

class BizProcTools
{
    // метод принимает строку с кодом сотрудника из бизнес процесса и возвращает id его руководителя
    public static function getBossOfUser($strUserCode)
    {
        $userId = preg_replace("/[^0-9]/", '', $strUserCode);
        $bossId = '';
        if (intval($userId) > 0) {
            CModule::IncludeModule("intranet");
            $dbUser = CUser::GetList(($by="id"), ($order="asc"), array("ID_EQUAL_EXACT"=>$userId), array("SELECT" => array("UF_*")));
            $arUser = $dbUser->GetNext();
            file_put_contents($_SERVER['DOCUMENT_ROOT']."/test123.txt",PHP_EOL."-----------".date("d-m-Y H:i:s")." переменная arUser ID".PHP_EOL. print_r($arUser['ID'],1), FILE_APPEND);
            $arManagers = CIntranetUtils::GetDepartmentManager($arUser["UF_DEPARTMENT"], $arUser["ID"], true);
            foreach ($arManagers as $key => $value) {
                $bossId = $value['ID'];
                break;
            }
        }
        return $bossId;
    }

    // запуск бизнес процесса для элемента списка
    public static function runBizProc($bizProcTemplateId, $arParams, $elementId)
    {
        if (CModule::IncludeModule('bizproc')) {
            $arErrorsTmp = array();
            CBPDocument::StartWorkflow(
                $bizProcTemplateId,
                array("lists", "Bitrix\Lists\BizprocDocumentLists", $elementId),
                $arParams,
                $arErrorsTmp
            );
            if ($arErrorsTmp) {
                //die('Не удалось запустить бизнес процесс');
            }
        } else {
            //die('Не удалось запустить бизнес процесс');
        }
    }

    // создать элемента списка
    public static function addListElement($arFields)
    {
        $documentId = CBPVirtualDocument::CreateDocument(
            0,
            $arFields
        );
        return $documentId;
    }

    // обновить элемента списка
    public static function updateIBlockElement($elementId, $arFields)
    {
        CModule::IncludeModule("iblock");
        $el = new CIBlockElement;
        $resUpdate = $el->Update($elementId, $arFields);
        return $resUpdate;
    }

    // установить свойство элемента списка
    public static function setElementPropertyValuesEx($elementId, $iblockId, $arProp)
    {
        CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, $arProp);
    }

    // получить элементы списка
    public static function getElementsFromIBlock($arSelect, $arFilter, $arOrder = array())
    {
        CModule::IncludeModule("iblock");
        $arItems = [];
        $res = CIBlockElement::GetList($arOrder, $arFilter, false, Array(), $arSelect);
        while($ob = $res->Fetch()){
            $arItems[] = $ob;
        }
        return $arItems;
    }

    /**
     *  метод получает элемент со свойствами
     *  метод можно использовать, если нужно получить элемент у которого есть множественное свойство
     * @param int $elementId - id элемента
     * @param int $iblockId - массив фильтра, если нужно получить не все пользовательские свойства элемента
     * @param array $arPropsFilter - массив фильтра для метода GetProperties(), если нужно получить не все пользовательские свойства элемента
     * @return array
     */
    public static function getElementWithProperties($elementId, $iblockId, $arPropsFilter = [])
    {
        CModule::IncludeModule('iblock');
        $db = CIBlockElement::GetList(Array(), Array("ID" => $elementId, "IBLOCK_ID" => $iblockId));
        $ob = $db->GetNextElement();
        $arFields = $ob->GetFields();
        $arProps = $ob->GetProperties(false, $arPropsFilter);
        $arItem = array_merge($arFields, $arProps);
        return $arItem;
    }

    /**
     * метод возвращает дерево разделов, в котором нижестоящие разделы идут после вышестоящего
     * @param array $arFilter
     * @param array $arSelect
     * @return array
     */
    public static function getSectionTreeList($arFilter, $arSelect)
    {
        CModule::IncludeModule('iblock');
        $arSections = [];
        $rsSection = CIBlockSection::GetTreeList($arFilter, $arSelect);
        while($arSection = $rsSection->Fetch()) {
            $arSections[$arSection['ID']] =$arSection;
        }
        return $arSections;
    }

    /**
     * генератор документов .docx
     * @param string $templatePath - путь к шаблону документа от корня сайта начиная со слеша
     * @param array $arFields - массив полей документа, которые необходимо заменить
     * @param string $newFileName - имя для нового файла
     * @param string $uploadDirName - название папки, в которой будет сохранен документ (эта папка будет создана в папке /upload/)
     * @return string
     */
    public static function generateDocument($templatePath, $arFields, $newFileName, $uploadDirName) {

        if (!$templatePath || !$arFields || !$newFileName || !$uploadDirName) return'';

        CModule::includeModule("documentgenerator");

        $file = new Bitrix\Main\IO\File($_SERVER['DOCUMENT_ROOT'] . $templatePath); // файл шаблона
        $body = new Bitrix\DocumentGenerator\Body\Docx($file->getContents());
        $body->normalizeContent();
        $body->setValues($arFields);
        $result = $body->process();

        if ($result->isSuccess()) {
            $content = $body->getContent();
            $dirPath = '/upload/' . $uploadDirName;
            $newFileName .= '.docx';
            $filePath = $dirPath . '/' . $newFileName;
            mkdir($_SERVER['DOCUMENT_ROOT'] . $dirPath, 0777, true);
            if (file_put_contents($_SERVER['DOCUMENT_ROOT'] . $filePath, $content)) {
                return $filePath;
            }
        }
        return '';
    }

    // загрузить временные файлы во множественное свойство элемента списка и удалить их
    public static function loadFilesToIBlock($arFilePaths, $elementId, $propertyCode, $deleteFiles = false) {
        $arValue = [];
        foreach ($arFilePaths as $filePath) {
            $arFile = CFile::MakeFileArray($_SERVER["DOCUMENT_ROOT"].$filePath);
            if (is_array($arFile)) {
                $arValue[] = $arFile;
            }
        }
        if ($arValue) {
            $result = CIBlockElement::SetPropertyValueCode($elementId, $propertyCode, $arValue);
            if ($result && $deleteFiles) {
                // удалить временные файлы
                foreach ($arFilePaths as $filePath) {
                    unlink($_SERVER["DOCUMENT_ROOT"].$filePath);
                }
            }
        }
    }
}

