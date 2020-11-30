# Google API Integration for Yii

## Настройка компонента

```php
'components' => [
    'google' => [
        'class' => dicr\google\Google::class,
        'clientConfig' => [
            'client_id' => 'XXXXXXX.apps.googleusercontent.com',
            'access_type' => 'offline',
            'prompt' => 'select_account consent',
            'client_secret' => 'XXXXXXXX', // для простой авторизации
            'credentials' => 'xxx', // см. Client::setAuthConfig
            'scopes' => [           // см. Client::setScopes
                Google_Service_Sheets::SPREADSHEETS,
                Google_Service_Sheets::DRIVE,
                Google_Service_Sheets::DRIVE_FILE
            ],
            'include_granted_scopes' => true
        ]
    ]
];
```
        
## Проверка валидности текущего токена

```php
use Google\Client;
use yii\helpers\Url;

/** @var dicr\google\Google $google */
$google = Yii::$app->get('google');

/** @var Client $client */
$client = $google->client;

// попробуем обновить токен через refresh_token, если имеется
if ($client->isAccessTokenExpired() && !empty($client->getRefreshToken())) {
    $token = $client->fetchAccessTokenWithRefreshToken();
    if (!empty($token)) {
        // сохраняем токен в сессии
        $this->module->api->sessionToken = $token;
    }
}
    
// если обновить не получилось, тогда переходим на страницу авторизации
if ($client->isAccessTokenExpired()) {
    // сохраняем адрес возврата
    Yii::$app->user->returnUrl = Url::current();

    // настраиваем адрес обработчика кода ответа
    $client->setRedirectUri(Url::to(['my-module/google-callback'], true));
        
    // отправляем пользователя на страницу авторизации
    return $this->redirect($client->createAuthUrl(), 303);
}
```
