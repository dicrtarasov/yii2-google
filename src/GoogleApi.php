<?php
namespace dicr\google;

use yii\base\Component;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;

/**
 * Компонент для настройки и создания клиента Google API.
 *
 * @property-read \Google_Client $client клиент Google API
 * @property array $sessionToken токен доступа в сессии пользователя.
 *
 * @link https://developers.google.com/identity платформа Google Identity
 * @link https://github.com/googleapis/google-api-php-client PHP-клиент
 * @link https://console.developers.google.com регистрация приложения и управление ключами доступа
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
class GoogleApi extends Component
{
    /**
     * @var array конфиг по-умолчанию для создания клиента.
     * Парамеры инициализации \Google_Client.
     *
     * Дополнительно добавлен отдельно параметр 'scopes', кторый при создании клиента
     * инициализируется отдельно от конфига.
     *
     * Пример:
     * [
     *  'client_id' => 'XXXXXXXXXXXX.apps.googleusercontent.com',
     *  'client_secret' => 'XXXXXXXXXXXX',
     *  'access_type' => 'offline',
     *  'prompt' => 'select_account consent',
     *  'include_granted_scopes' => true,
     *  'scopes' => [
     *      \Google_Service_Sheets::SPREADSHEETS,
     *      \Google_Service_Sheets::DRIVE,
     *      \Google_Service_Sheets::DRIVE_FILE
     *  ]
     *
     *  @see \Google_Client
     *  @link https://github.com/googleapis/google-api-php-client
     */
    public $clientConfig = [];

    /**
     * {@inheritDoc}
     * @see \yii\base\BaseObject::init()
     */
    public function init()
    {
        $this->clientConfig = (array)($this->clientConfig ?: []);

        // default Application Name
        if (!isset($this->clientConfig['application_name'])) {
            $this->clientConfig['application_name'] = \Yii::$app->name;
        }

        if (!isset($this->clientConfig['redirect_uri'])) {
            $this->clientConfig['redirect_uri'] = Url::to(['/google/oauth/return'], true);
        }
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
    public function getSessionToken()
    {
        $data = \Yii::$app->session->get(__CLASS__, []);
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
    public function setSessionToken(array $token = null)
    {
        // сбрасываем некорректный токен
        if (!empty($token) && !empty($token['error'])) {
            $token = null;
        }

        // получаем данные сессии
        $data = \Yii::$app->session->get(__CLASS__, []);
        if (!empty($token)) {
            $data['token'] = $token;
        } else {
            unset($data['token']);
        }

        \Yii::$app->session->set(__CLASS__, $data);
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
        // получаем конечный вариант конфига
        $config = array_merge($this->clientConfig, $config);

        // выделяем scopes из конфига
        $scopes = ArrayHelper::remove($config, 'scopes');

        // создаем клиент
        $client = new \Google_Client($config);

        // устанавливаем scopes
        if (!empty($scopes)) {
            $client->setScopes($scopes);
        }

        // получаем токен из сессии пользователя
        $token = $this->getSessionToken();
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
                        $this->setSessionToken($token);
                    }
                }
            }
        }

        return $client;
    }
}
