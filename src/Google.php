<?php
/*
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 05.11.20 02:38:04
 */

declare(strict_types = 1);
namespace dicr\google;

use Google\Client;
use Google\Exception;
use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\caching\TagDependency;

use function is_string;

/**
 * Модуль для создания и конфигурации клиента Google.
 *
 * Авторизация работает в разных режимах:
 * - Web OAUTH - запрашивает доступ к данным пользователя и работает через токен.
 * - Service Account - работает от собственного сервисного аккаунта Google. Для доступа к данным, пользователь должен
 *   расшарить доступ этому аккаунту.
 *
 * @property-read Client $client клиент Google API
 *
 * @link https://github.com/googleapis/google-api-php-client PHP-клиент с примерами и документацией
 * @link https://cloud.google.com/docs/authentication документация по авторизации Google
 * @link https://console.developers.google.com регистрация приложения и управление ключами доступа
 */
class Google extends Component
{
    /**
     * @var array конфиг по-умолчанию для создания Google клиента.
     *
     * Парамеры инициализации \Client.
     *
     * Пример:
     * [
     *  'access_type' => 'offline',
     *  'prompt' => 'select_account consent',
     *  'include_granted_scopes' => true
     * ]
     *
     * @see Client::__construct()
     * @link https://github.com/googleapis/google-api-php-client
     */
    public $clientConfig = [];

    /**
     * @var string|array данные авторизации
     * Может быть путь к файлу auth.json, скачанному с Google Developer Console при создании приложения,
     * либо массив парамеров из файла json
     *
     * Пример:
     * @app/config/auth.json
     *
     *  либо:
     *  [
     *    'type' => 'service_account',
     *    'project_id' => 'XXXXX',
     *    'private_key_id': 'XXXXX',
     *    'private_key': 'XXXXXXX',
     *    'client_email': 'xxx@xxx.iam.gserviceaccount.com,
     *    'client_id': 'XXXXXX',
     *    'auth_uri': 'https://accounts.google.com/o/oauth2/auth',
     *    'token_uri': "https://oauth2.googleapis.com/token',
     *    'auth_provider_x509_cert_url': 'https://www.googleapis.com/oauth2/v1/certs',
     *    'client_x509_cert_url': "https://www.googleapis.com/robot/v1/metadata/x509/xxx%40xxx.gserviceaccount.com"
     * ]
     *
     * @see Client::setAuthConfig($config)
     */
    public $authConfig;

    /**
     * @var string[] запрашиваемые разрешения приложения.
     *
     * Пример:
     * [
     *      \Google_Service_Sheets::SPREADSHEETS,
     *      \Google_Service_Sheets::DRIVE
     * ]
     *
     * @see Client::setScopes()
     */
    public $scopes = [];

    /** @var callable function(array $token, GoogleModule $module) обработчик авторизации вызывается для сохранения токена */
    public $oathHandler;

    /**
     * @inheritDoc
     * @throws InvalidConfigException
     */
    public function init() : void
    {
        $this->clientConfig = $this->clientConfig ?: [];

        // default Application Name
        if (! isset($this->clientConfig['application_name'])) {
            $this->clientConfig['application_name'] = Yii::$app->name;
        }

        // алиас authConfig
        if (is_string($this->authConfig)) {
            $this->authConfig = Yii::getAlias($this->authConfig);
        }

        // проверяем authConfig
        if (empty($this->authConfig)) {
            throw new InvalidConfigException('authConfig');
        }

        $this->scopes = (array)($this->scopes ?: []);
    }

    /**
     * Создает и настраивает клиента API Google.
     *
     * @param array $config дополнительные парамеры клиента, перезаписывающие парамеры конфига по-умолчанию.
     * Конфиг клиента это парамеры объекта Google\Client, а также параметр scopes.
     *
     * @return Client
     * @throws Exception
     */
    public function getClient(array $config = []) : Client
    {
        // создаем клиента
        $client = new Client(array_merge($this->clientConfig, $config));

        // устанавливаем параметры авторизации
        if (! empty($this->authConfig)) {
            $client->setAuthConfig($this->authConfig);
        }

        // устанавливаем scopes
        if (! empty($this->scopes)) {
            $client->setScopes($this->scopes);
        }

        return $client;
    }

    /**
     * Установить/получить токен, хранимый в кеше модуля.
     *
     * ```php
     * [
     *    'access_token' => 'XXXXXXX',
     *    'expires_in' => 3600
     *    'scope' => 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/spreadsheets',
     *    'token_type' => 'Bearer'
     *    'created' => 1567627251,
     *    'refresh_token' => 'XXXXXXXX'
     * ]
     * ```
     *
     * @param ?array $token если не null, то сохранить
     * @return ?array данные токена
     */
    public function moduleToken(?array $token = null) : ?array
    {
        $key = [__CLASS__, $this->clientConfig, $this->authConfig];

        if (! empty($token)) {
            Yii::$app->cache->set($key, $token, $token['expires_in'] ?? null, new TagDependency([
                'tags' => [self::class]
            ]));
        } else {
            $token = Yii::$app->cache->get($key);
        }

        return $token ?: null;
    }

    /**
     * Получить/установить токен сессии пользователя.
     *
     * ```php
     * [
     *    'access_token' => 'XXXXXXX',
     *    'expires_in' => 3600
     *    'scope' => 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/spreadsheets',
     *    'token_type' => 'Bearer'
     *    'created' => 1567627251,
     *    'refresh_token' => 'XXXXXXXX'
     * ]
     * ```
     *
     * @param ?array $token если не null, то сохранить
     * @return array данные токена
     */
    public static function sessionToken(?array $token = null) : ?array
    {
        $data = Yii::$app->session->get(__CLASS__, []);

        if ($token !== null) {
            $data['token'] = $token;
            Yii::$app->session->set(__CLASS__, $data);
        } else {
            $token = $data['token'] ?? null;
        }

        return $token ?: null;
    }
}
