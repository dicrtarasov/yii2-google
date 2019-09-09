<?php
namespace dicr\google;

use yii\base\Component;

/**
 * Компонент для настройки и создания клиента Google API.
 *
 * Авторизация работает в разных режимах:
 * - Web OAUTH - запрашивает доступ к данным пользователя и работает через токен.
 * - Service Account - работает от собственного сервисного аккаунта Google. Для доступа к данным, пользователь должен
 *   расшарить доступ этому аккаунту.
 *
 * @property-read \Google_Client $client клиент Google API
 *
 * @link https://github.com/googleapis/google-api-php-client PHP-клиент с примерами и документацией
 * @link https://cloud.google.com/docs/authentication документация по авторизации Google
 * @link https://console.developers.google.com регистрация приложения и управление ключами доступа
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class GoogleApi extends Component
{
    /**
     * @var array конфиг по-умолчанию для создания клиента.
     *
     * Парамеры инициализации \Google_Client.
     *
     * Пример:
     * [
     *  'access_type' => 'offline',
     *  'prompt' => 'select_account consent',
     *  'include_granted_scopes' => true
     * ]
     *
     *  @see \Google_Client::__construct()
     *  @link https://github.com/googleapis/google-api-php-client
     */
    public $clientConfig = [];

    /**
     * @var string|array данные авторизации
     * Может быть путь к файлу auth.json, скачанному с Google Developer Console при создании приложения,
     * либо массив парамеров из файла json
     *
     * Прмер:
     *  @app/config/auth.json
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
     * @see \Google_Client::setAuthConfig($config)
     */
    public $authConfig;

    /**
     * @var string[] запрашиваемые разрешения приложения.
     *
     * Пример:
     * [
     *      \Google_Service_Sheets::SPREADSHEETS,
     *      \Google_Service_Sheets::DRIVE,
     *      \Google_Service_Sheets::DRIVE_FILE
     * ]
     *
     * @see \Google_client::setScopes($scopes)
     */
    public $scopes = [];

    /**
     * {@inheritDoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        // client config
        $this->clientConfig = (array)($this->clientConfig ?: []);

        // default Application Name
        if (!isset($this->clientConfig['application_name'])) {
            $this->clientConfig['application_name'] = \Yii::$app->name;
        }

        // разворачиваем алиас в authConfig
        if (is_string($this->authConfig)) {
            $this->authConfig = \Yii::getAlias($this->authConfig, true);
        }

        $this->scopes = (array)($this->scopes ?: []);
    }

    /**
     * Создает и настраивает клиента API Google.
     *
     * @param array $config дополнительные парамеры клиента, перезаписывающие парамеры конфига по-умлчанию.
     * Конфиг клиента это парамеры объекта \Google_Client, а также параметр scopes
     *
     * @return \Google_Client
     */
    public function getClient(array $config = [])
    {
        // объединяем с парамерами по-умолчанию
        if (!empty($this->clientConfig)) {
            $config = array_merge($this->clientConfig, $config);
        }

        // создаем клиента
        $client = new \Google_Client($config);

        // устанавливаем параметры авторизации
        if (!empty($this->authConfig)) {
            $client->setAuthConfig($this->authConfig);
        }

        // устанавливаем scopes
        if (!empty($this->scopes)) {
            $client->setScopes($this->scopes);
        }

        // получаем токен из сессии пользователя
        $token = static::getSessionToken();
        if (!empty($token)) {
            // устанавливаем токен доступа
            $client->setAccessToken($token);

            // если срок токена истек
            if ($client->isAccessTokenExpired()) {
                // получаем токен обновления
                $refreshToken = $client->getRefreshToken();
                if (!empty($refreshToken)) {
                    // пытаемся получить новый токен
                    $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);
                    // сохраняем новый в сессии пользователя
                    if (!empty($token)) {
                        static::setSessionToken($token);
                    }
                }
            }
        }

        return $client;
    }

    /**
     * Возвращает токены доступа сохраненные в сессии пользователя.
     *
     * @return array данные токена, полученные от Google и сохраненны в сессии
     * [
     *    'access_token' => 'XXXXXXX',
     *    'expires_in' => 3600
     *    'scope' => 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/spreadsheets',
     *    'token_type' => 'Bearer'
     *    'created' => 1567627251,
     *    'refresh_token' => 'XXXXXXXX'
     * ]
     */
    public static function getSessionToken()
    {
        $data = \Yii::$app->session->get(static::class, []);
        $token = $data['token'] ?? null;

        // если в токене ошибка, то удаляем его
        if (!empty($token) && !empty($token['error'])) {
            $token = null;
        }

        return $token;
    }

    /**
     * Сохраняет в сессии токены пользователя.
     *
     * @param array $token данные токена, полученные от Google (набор данных)
     */
    public static function setSessionToken(array $token = null)
    {
        // сбрасываем некорректный токен
        if (!empty($token) && !empty($token['error'])) {
            $token = null;
        }

        // получаем данные сессии
        $data = \Yii::$app->session->get(static::class, []);
        if (!empty($token)) {
            $data['token'] = $token;
        } else {
            unset($data['token']);
        }

        \Yii::$app->session->set(static::class, $data);
    }
}
