<?php
    require_once __DIR__ . '/../../../vendor/autoload.php';

    // Настройки доступа из https://my.starline.ru/developer.
    $config = (new \Starline\Config())
        ->setLogin('login')           //логин пользователя.
        ->setPassword('password')  //пароль пользователя.
        ->setAppId('app id')         //идентификатор приложения.
        ->setSecret('secret key');   //ключ доступа к приложению.

    $starLine = new \Starline\Starline();
    $starLine->setConfig($config);

    // Пример получения кода приложения.

    try {
        $code = $starLine->fetchCode();
    } catch (Throwable $e) {
        die('Не удалось получить код приложения');
    }

    // Пример получения token ключа.
    try {
        $token = $starLine->fetchToken($code);
    } catch (Throwable $e) {
        die('Не удалось получить токен ключа');
    }

    // Авторизация пользователя.
    try {
        $user_token = $starLine->fetchUserToken($token);
    } catch (Throwable $e) {
        die('Не удалось произвести авторизацию');
    }

    // Пример получения SLNET token ключа.

    try {
        $slnetData = $starLine->fetchSLNETToken($user_token);
    } catch (Throwable $e) {
        die('Не удалось получить slnet токен');
    }

    if (empty($slnetData)) {
        die('Не удалось получить slnet токен');
    }
    [$slnet, $user_id] = $slnetData;

    // Пример получения существующих устройств пользователя.
    $devices = [];
    try {
        $devices = $starLine->fetchDevicesInfo($slnet, $user_token, $user_id);
    } catch (Throwable $e) {
        echo 'Не удалось получить slnet токен' . PHP_EOL;
    }
    echo '<pre>' . print_r($devices, true) . '</pre>';

    // Пример получения device_id, выберите нужное устройство из массива $devices['user_data']['devices']
    $device_id = $devices['user_data']['devices'][0]['device_id'] ?? '';

    // Пример выполнения запроса к устройству.
    $response = [];
    try {
        $response = $starLine->runQuery(
            $slnet,
            $device_id,
            [
                'type' => 'arm',// Тип команды: охрана устройства
                'arm'  => 1,    // Свойство: Включено
            ]
        );
    } catch (Throwable $e) {
        echo 'Не удалось выполнить команду' . PHP_EOL;
    }
    echo '<pre>'. print_r($response, true) . '</pre>';


