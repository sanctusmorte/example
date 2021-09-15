<?php

namespace app\controllers;

use app\services\SiteService;
use Yii;
use yii\web\{
    Controller,
    Response
};
use yii\bootstrap\ActiveForm;
use yii\base\Module;
use app\services\SettingService;
use app\models\{
    Setting
};

/**
 * Class SettingController
 */
class SettingController extends Controller
{
    private $settingService, $siteService;

    /**
     * SettingController constructor.
     * @param $id
     * @param Module $module
     * @param SettingService $settingService
     * @param SiteService $siteService
     * @param array $config
     */
    public function __construct($id, Module $module, SettingService $settingService, SiteService $siteService, $config = [])
    {
        $this->settingService = $settingService;
        $this->siteService = $siteService;

        parent::__construct($id, $module, $config);
    }

    /**
     * @return string
     * @throws \yii\base\Exception
     */
    public function actionIndex(): string
    {
        $clientId = $this->settingService->getSettingId();
        $setting = $this->settingService->getSetting($clientId);

        Yii::$app->session->set('clientId', $setting->client_id);

        list($dataProvider, $searchModel) = $this->siteService->getSearchModel($setting);

        return $this->render('index', [
            'setting' => $setting,
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel
        ]);
    }

    /**
     * @return array|Response
     * @throws \yii\base\Exception
     */
    public function actionSave()
    {
        $clientId = $this->settingService->getSettingId();

        $setting = $this->settingService->getSetting($clientId);

        if ($setting->load( Yii::$app->request->post()) && $setting->validate() && Yii::$app->request->post('submit')) {
            $this->settingService->save($setting);
            Yii::$app->session->set('clientId', $setting->client_id);
            return $this->redirect(['index']);
        }

        Yii::$app->response->format = Response::FORMAT_JSON;
        return ActiveForm::validate($setting);
    }


    /**
     * @return string
     */
    public function actionActivity(): string
    {
        $clientId = Yii::$app->request->post('clientId') ?? Yii::$app->session->get('clientId');
        $postActivity = Yii::$app->request->post('activity', '{}');

        if ($clientId && $postActivity) {
            $this->settingService->moduleActivity($clientId, $postActivity);
        }

        return \yii\helpers\Json::encode([
            'success' => true
        ]);
    }
}
