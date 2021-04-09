<?php
/*
 * @copyright 2019-2021 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license MIT
 * @version 09.04.21 06:57:40
 */

declare(strict_types = 1);
namespace dicr\google;

use Google\Client;
use Yii;
use yii\base\Component;
use yii\caching\TagDependency;
use yii\helpers\Url;

/**
 * Модуль для создания и конфигурации клиента Google.
 *
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
 * @link https://developers.google.com/identity/protocols/oauth2/web-server авторизация OAuth-клиента
 * @link https://console.cloud.google.com/ настройка web-приложения
 */
class Google extends Component
{
    /**
     * @var array конфиг по-умолчанию для создания Google клиента.
     *
     * Парамеры инициализации Google\Client.
     *
     * Пример:
     * [
     *  // все параметры могут быть заданы в скачанном JSON-файле
     *  'credentials' => 'xxx', // путь к файлу параметров авторизации либо массив параметров (setAuthConfig)
     *
     *   // либо параметры приложения устанавливаеются вручную
     *  'client_id' => 'xxx',
     *  'client_secret' => 'xxx',
     *
     *  // запрошенные права приложения
     *  'scopes' => [
     *      Google_Service_Oauth2::USERINFO_EMAIL,
     *      Google_Service_Oauth2::USERINFO_PROFILE
     *  ]
     *
     *  // для получения refreshToken для обновления без пользователя
     *  'access_type' => 'offline',
     *  'prompt' => 'select_account consent',
     *  'include_granted_scopes' => true,
     *  'redirect_uri' => ['/oauth/google'], // обрабатывается отдельно, конвертируется в полный адрес
     * ]
     * ```
     * @see Client::__construct()
     * @link https://github.com/googleapis/google-api-php-client
     */
    public $clientConfig = [];

    /**
     * @inheritDoc
     */
    public function init(): void
    {
        parent::init();

        $this->clientConfig = $this->clientConfig ?: [];

        // default Application Name
        if (! isset($this->clientConfig['application_name'])) {
            $this->clientConfig['application_name'] = Yii::$app->name;
        }
    }

    /**
     * Создает и настраивает клиента API Google.
     *
     * @param array $config дополнительные парамеры клиента, перезаписывающие парамеры конфига по-умолчанию.
     * Конфиг клиента это парамеры объекта Google\Client, а также параметр scopes.
     *
     * @return Client
     */
    public function getClient(array $config = []): Client
    {
        // создаем клиент
        $client = new Client(array_merge($this->clientConfig, $config));

        // так как redirect_uri в конфиге перезаписывается файлом credentials,
        // то устанавливаем его дополнительно после создания клиента
        if (! empty($this->redirectUri)) {
            $client->setRedirectUri(Url::to($this->redirectUri, true));
        } elseif (! empty($config['redirect_uri'])) {
            $client->setRedirectUri(Url::to($config['redirect_uri'], true));
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
    public function moduleToken(?array $token = null): ?array
    {
        $key = [__CLASS__, $this->clientConfig];

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
    public static function sessionToken(?array $token = null): ?array
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
