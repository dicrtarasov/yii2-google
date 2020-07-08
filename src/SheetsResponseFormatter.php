<?php
/**
 * @author Igor A Tarasov <develop@dicr.org>
 * @version 08.07.20 08:05:22
 */

declare(strict_types = 1);
namespace dicr\google;

use ArrayAccess;
use Google_Client;
use Google_Service_Sheets;
use Google_Service_Sheets_AppendCellsRequest;
use Google_Service_Sheets_BatchUpdateSpreadsheetRequest;
use Google_Service_Sheets_CellData;
use Google_Service_Sheets_CellFormat;
use Google_Service_Sheets_ExtendedValue;
use Google_Service_Sheets_Request;
use Google_Service_Sheets_RowData;
use Google_Service_Sheets_Sheet;
use Google_Service_Sheets_SheetProperties;
use Google_Service_Sheets_Spreadsheet;
use Google_Service_Sheets_SpreadsheetProperties;
use Traversable;
use Yii;
use yii\base\Arrayable;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\data\DataProviderInterface;
use yii\db\Query;
use yii\di\Instance;
use yii\web\Response;
use yii\web\ResponseFormatterInterface;
use function count;
use function is_array;
use function is_iterable;
use function is_object;

/**
 * Загружает данные в таблицы Google SpreadSheets и переадресовывает на адрес таблицы.
 *
 * Для работы необходимо задать документ $spreadSheet, либо сервис $service,
 * либо клиент $client.
 *
 * Для создания шапки таблицы а также для выбора порядка и названий
 * выгружаемых колонок данных, можно установить ассоциативный массив $fields,
 * в котором ключи - названия полей, значения - заголовки колонок.
 *
 * @property Google_Service_Sheets $service сервис SpreadSheets
 * @property Google_Service_Sheets_Spreadsheet $spreadSheet документ (таблица)
 *
 * @noinspection PhpUnused
 */
class SheetsResponseFormatter extends Component implements ResponseFormatterInterface
{
    /** @var int кол-во строк данных в одном запросе */
    public const ROWS_PER_REQUEST_DEFAULT = 1000;

    /** @var string название файла документа таблицы на диске */
    public $name;

    /** @var array|null ассоциативный массив колонок данных и их названий [field => Title] */
    public $fields;

    /** @var int кол-во строк таблицы отправляемых в одном запросе.
     *  С одной стороны, есть лимит памяти на буферизацию данных,
     *  с другой стороны лимит количества запросов в секунду Google.
     */
    public $rowsPerRequest = self::ROWS_PER_REQUEST_DEFAULT;

    /** @var Google_Client|null авторизованный клиент Google Api */
    public $client;

    /** @var array конфиг для \Google_Service_Sheets_CellFormat */
    public $cellFormatConfig = [
        'wrapStrategy' => 'WRAP',
        'hyperlinkDisplayType' => 'LINKED'
    ];

    /** @var int идентификатор листа таблицы в документе */
    public $sheetId = 1;

    /** @var Google_Service_Sheets|null */
    private $_service;

    /** @var Google_Service_Sheets_Spreadsheet документ */
    private $_spreadSheet;

    /** @var Google_Service_Sheets_RowData[] буфер срок данных для вывода */
    private $_rows = [];

    /**
     * @inheritDoc
     * @throws InvalidConfigException
     */
    public function init()
    {
        parent::init();

        // название документа таблицы
        $this->name = trim($this->name);
        if (empty($this->name)) {
            throw new InvalidConfigException('name');
        }

        // кол-во строк в одном запросе
        $this->rowsPerRequest = (int)$this->rowsPerRequest;
        if ($this->rowsPerRequest < 1) {
            throw new InvalidConfigException('rowsPerRequest');
        }

        // проверяем установку документа, сервиса или клиента
        if (isset($this->_spreadSheet)) {
            $this->_spreadSheet = Instance::ensure($this->_spreadSheet, Google_Service_Sheets_Spreadsheet::class);
        } elseif (isset($this->_service)) {
            $this->_service = Instance::ensure($this->_service, Google_Service_Sheets::class);
        } elseif (isset($this->client)) {
            $this->client = Instance::ensure($this->client, Google_Client::class);
        } else {
            throw new InvalidConfigException('client должен быть установлен, если не задан service или spreadSheet');
        }

        $this->sheetId = (int)$this->sheetId;
        if ($this->sheetId < 0) {
            throw new InvalidConfigException('sheetId');
        }
    }

    /**
     * Возвращает сервис SpreadSheets.
     *
     * @return Google_Service_Sheets
     */
    public function getService()
    {
        if (! isset($this->_service)) {
            $this->_service = new Google_Service_Sheets($this->client);
        }

        return $this->_service;
    }

    /**
     * Устанавливает сервис SpreadSheets.
     *
     * @param Google_Service_Sheets_Spreadsheet $service
     */
    public function setService(Google_Service_Sheets_Spreadsheet $service)
    {
        $this->_service = $service;
    }

    /**
     * Возвращает документ.
     *
     * @param array $config
     * @return Google_Service_Sheets_Spreadsheet
     */
    public function getSpreadSheet(array $config = [])
    {
        if (! isset($this->_spreadSheet)) {
            // создаем по-умолчанию
            $spreadsheet = new Google_Service_Sheets_Spreadsheet(array_merge([
                'properties' => new Google_Service_Sheets_SpreadsheetProperties([
                    'title' => $this->name,
                    'locale' => Yii::$app->language,
                    'timeZone' => Yii::$app->timeZone,
                    'defaultFormat' => new Google_Service_Sheets_CellFormat($this->cellFormatConfig),
                ]),
                'sheets' => [
                    new Google_Service_Sheets_Sheet([
                        'properties' => new Google_Service_Sheets_SheetProperties([
                            'sheetId' => $this->sheetId
                        ])
                    ])
                ]
            ], $config));

            $this->_spreadSheet = $this->service->spreadsheets->create($spreadsheet);
        }

        return $this->_spreadSheet;
    }

    /**
     * Устанавливает документ.
     *
     * @param Google_Service_Sheets_Spreadsheet $spreadSheet
     */
    public function setSpreadSheet(Google_Service_Sheets_Spreadsheet $spreadSheet)
    {
        $this->_spreadSheet = $spreadSheet;
    }

    /**
     * Конвертирует входящие данные в Traversable
     *
     * @param array|object|Traversable|Arrayable|Query|DataProviderInterface $data
     * @return array|Traversable
     * @throws Exception
     */
    protected static function convertData($data)
    {
        if (empty($data)) {
            return [];
        }

        if (is_iterable($data)) {
            return $data;
        }

        if ($data instanceof Arrayable) {
            return $data->toArray();
        }

        if ($data instanceof Query) {
            return $data->each();
        }

        if ($data instanceof DataProviderInterface) {
            return $data->getModels();
        }

        if (is_object($data)) {
            return (array)$data;
        }

        throw new Exception('неизвестный тип в response->data');
    }

    /**
     * Конвертирует строку входящих данных в массив значений.
     *
     * @param array|object|Traversable|ArrayAccess|Arrayable|Model $row - данные строки
     * @return array|ArrayAccess|Traversable массив значений
     * @throws Exception
     */
    protected function convertRow($row)
    {
        if (empty($row)) {
            return [];
        }

        if (is_iterable($row) || ($row instanceof ArrayAccess)) {
            return $row;
        }

        if ($row instanceof Arrayable) {
            return $row->toArray();
        }

        if ($row instanceof Model) {
            return $row->attributes;
        }

        if (is_object($row)) {
            return (array)$row;
        }

        throw new Exception('unknown row format');
    }

    /**
     * Создает ячейку таблицы.
     *
     * @param string $data
     * @return Google_Service_Sheets_CellData
     */
    protected function createCell(string $data)
    {
        return new Google_Service_Sheets_CellData([
            'userEnteredValue' => new Google_Service_Sheets_ExtendedValue([
                'stringValue' => $data
            ])
        ]);
    }

    /**
     * Создает строку таблицы.
     *
     * @param $data
     * @return Google_Service_Sheets_RowData
     * @throws Exception
     */
    protected function createRow($data)
    {
        $data = $this->convertRow($data);
        $cells = [];

        if (! empty($this->fields)) { // если заданы заголовки, то выбираем только заданные поля в заданной последовательности
            // проверяем доступность прямой выборки индекса из массива
            if (! is_array($data) && ! ($data instanceof ArrayAccess)) {
                throw new Exception('для использования списка полей fields необходимо чтобы элемент данных был либо array, либо типа ArrayAccess');
            }

            foreach (array_keys($this->fields) as $field) {
                $cells[] = $this->createCell((string)($data[$field] ?? ''));
            }
        } else { // обходим все поля
            // проверяем что данные доступны для обхода
            if (! is_array($data) && ! ($data instanceof Traversable)) {
                throw new Exception('элемент данных должен быть либо array, либо типа Traversable');
            }

            // обходим тип Traversable
            foreach ($data as $val) {
                $cells[] = $this->createCell((string)$val);
            }
        }

        return new Google_Service_Sheets_RowData([
            'values' => $cells
        ]);
    }

    /**
     * Отправляет строку таблицы.
     *
     * Строка добавляется в буфер и при достижении размера буфера [[rowsPerRequest]],
     * либо если $row == null отправляется запрос на добавление срок в таблицу.
     *
     * @param Google_Service_Sheets_RowData|null $row строка таблицы или null для отправки остатка буфера
     */
    protected function sendRow(Google_Service_Sheets_RowData $row = null)
    {
        // инициализация массива
        if (empty($this->_rows)) {
            $this->_rows = [];
        }

        // добавляем строку в буфер
        if (isset($row)) {
            $this->_rows[] = $row;
        }

        // отправка запросов при переполнении буфера или сбросе
        if (! empty($this->_rows) && (! isset($row) || count($this->_rows) >= $this->rowsPerRequest)) {
            // создаем запрос на добавление строк в таблицу
            $batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
                'requests' => [
                    new Google_Service_Sheets_Request([
                        'appendCells' => new Google_Service_Sheets_AppendCellsRequest([
                            'sheetId' => $this->sheetId,
                            'rows' => $this->_rows,
                            'fields' => '*'
                        ])
                    ])
                ]
            ]);

            // отправляем запрос
            $this->service->spreadsheets->batchUpdate($this->spreadSheet->spreadsheetId, $batchUpdateRequest);

            // очищаем буфер строк
            $this->_rows = [];
        }
    }

    /**
     * {@inheritDoc}
     *
     * Форматирует данные из $response->data путем выгрузки таблицы в Google SpreadSheets и редиректа на ее адрес.
     *
     * Данные в $response->data должны иметь тип:
     * array|object|\Traversable|Arrayable|Query|DataProviderInterface
     *
     * Данные в каждой строке $response->data должны иметь тип:
     * array|object|\Traversable|\ArrayAccess|Arrayable|Model
     *
     * @throws Exception
     */
    public function format($response)
    {
        /** @var Response $response */

        // конвертируем входные данные
        $data = self::convertData($response->data);

        // очищаем данные ответа
        $response->data = null;

        // отправляем строку заголовка
        if (! empty($this->fields)) {
            $this->sendRow($this->createRow($this->fields));
        }

        // отправляем данные в таблицы
        foreach ($data as $rowData) {
            $this->sendRow($this->createRow($rowData));
        }

        // отправляем остатки строк в буфере
        $this->sendRow();

        // возвращаем редирект на адрес созданного документа
        return $response->redirect($this->spreadSheet->spreadsheetUrl, 303);
    }
}
