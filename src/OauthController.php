<?php
/**
 * @author Igor A Tarasov <develop@dicr.org>
 * @version 08.07.20 08:07:54
 */

declare(strict_types = 1);
namespace dicr\google;

use app\modules\google\GoogleModule;
use Yii;
use yii\helpers\Url;
use yii\web\Controller;
use yii\web\Response;
use function is_array;

/**
 * Oauth 2.0 client по-умолчанию.
 *
 * Сохраняет токен в сессии и делает переадресацию обратно.
 * Если необходимо другие действия, то необходимо клиенту установить другой
 * адрес обработчика через return_uri.
 *
 * @property GoogleModule $module
 * @noinspection PhpUnused
 */
class OauthController extends Controller
{
    /**
     * Возвращает URL обработчика кода авторизации при возврате из Google.
     *
     * @return string
     */
    public static function redirectUri()
    {
        return Url::to(['callback'], true);
    }

    /**
     * Авторизует пользователя токеном из сессии.
     *
     * @param string|array $returnUrl URL для возврата после авторизации
     * @param string|array $callbackUrl URL для обработки кода доступа от Google
     * @return Response
     */
    public function actionIndex($returnUrl = null, $callbackUrl = null)
    {
        // адрес откуда пришли на авторизацию
        if (empty($returnUrl)) {
            $returnUrl = Yii::$app->request->referrer;
        }

        if (is_array($returnUrl)) {
            $returnUrl = Url::to($returnUrl, true);
        }

        // адрес для редиректа
        if (empty($callbackUrl)) {
            $callbackUrl = $this->getDefaultRedirectUri();
        }

        if (is_array($callbackUrl)) {
            $callbackUrl = Url::to($callbackUrl, true);
        }

        // получаем клиент Api с токеном по-умолчанию из сессии
        $client = $this->module->api->client;

        // если токен свежий, то сразу возвращаемся
        if (! $client->isAccessTokenExpired()) {
            return $this->redirect($returnUrl, 303);
        }

        // настраиваем адрес обработчика кода ответа
        $client->setRedirectUri($callbackUrl);

        // попробуем обновить токен через refresh_token, если имеется
        if (! empty($client->getRefreshToken())) {
            $token = $client->fetchAccessTokenWithRefreshToken();
            if (! empty($token)) {
                // сохраняем токен в сессии
                $this->module->api->sessionToken = $token;

                // возвращаемся обратно
                return $this->redirect($returnUrl, 303);
            }
        }

        // сохраняем адрес возврата
        Yii::$app->user->returnUrl = $returnUrl;

        // отправляем пользователя на страницу авторизации
        return $this->redirect($client->createAuthUrl(), 303);
    }

    /**
     * Обработчик redirect_uri после авторизации клиента.
     *
     * Сохраняет токен в сессии и возвращается по сохраненному заранее:
     * \Yii::$app->user->returnUrl
     *
     * @param string $code auth code
     * @param string $error error message when access denied
     * @return string|Response
     */
    public function actionReturn($code = null, $error = null)
    {
        if (! empty($code)) {
            // создаем клиент
            $client = $this->module->api->client;

            // не понятно для чего здесь повторять редирект URI
            $client->setRedirectUri($this->getDefaultRedirectUri());

            // получаем токен по коду авторизации
            $token = $client->fetchAccessTokenWithAuthCode($code);
            if (! empty($token)) {
                // сохраняем токен в сессии
                $this->module->api->setSessionToken($token);

                // возвращаемся обратно
                return $this->redirect(Yii::$app->user->returnUrl, 303);
            }
        }

        return $this->renderContent('В доступе отказано: ' . ($error ?: 'причина неизвестна'));
    }
}
