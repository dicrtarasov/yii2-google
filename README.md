=== Google API Integration for Yii

1) Настраиваем компонент GoogleApi
````php
'api' => [
    'class' => GoogleApi::class,
    'clientConfig' => [
        'client_id' => 'XXXXXXX.apps.googleusercontent.com',
        'client_secret' => 'XXXXXXXX',
        'access_type' => 'offline',
        'prompt' => 'select_account consent',
        'include_granted_scopes' => true,
        'scopes' => [
            Google_Service_Sheets::SPREADSHEETS,
            Google_Service_Sheets::DRIVE,
            Google_Service_Sheets::DRIVE_FILE
        ]
    ]
]
````
        
2) Проверка валидности текущего токена

```php
if ($client->isAccessTokenExpired()) {
    // попробуем обновить токен через refresh_token, если имеется
    if (!empty($client->getRefreshToken())) {
        $token = $client->fetchAccessTokenWithRefreshToken();
        if (!empty($token)) {
            // сохраняем токен в сессии
            $this->module->api->sessionToken = $token;
        }
    }
}
    
// если обновить не получилось, тогда переходим на страницу авторизации
if ($client->isAccessTokenExpired()) {
    // сохраняем адрес возврата
    Yii::$app->user->returnUrl = $returnUrl;

    // настраиваем адрес обработчика кода ответа
    $client->setRedirectUri($callbackUrl);
        
    // отправляем пользователя на страницу авторизации
    return $this->redirect($client->createAuthUrl(), 303);
}
```
