<?php

namespace app\services;

use app\models\Kassa;
use app\models\KassaSearch;
use app\models\Setting;
use app\models\Site;
use app\models\SiteSearch;
use Yii;
use yii\db\ActiveRecord;
use yii\web\NotFoundHttpException;

/**
 * Class SiteService
 */
class SiteService
{
    public function getSearchModel($setting): array
    {
        $searchModel = new SiteSearch();
        $dataProvider = $searchModel->search($setting);

        return [
            $dataProvider,
            $searchModel
        ];
    }

    /**
     * @param int $siteId
     * @return ActiveRecord|array|null
     * @throws NotFoundHttpException
     */
    public function getSiteById(int $siteId)
    {
        if ($site = Site::find()->where(['id' => $siteId])->one()) return $site;

        throw new NotFoundHttpException("Account #$siteId not found");
    }

    /**
     * Получение списка магазинов для настроек модуля
     * @param $setting
     * @return array
     */
    public function getStoresBySetting($setting): array
    {
        $needStores = [];

        $existSites = Yii::$app->retail->sitesList(['retailApiUrl' => $setting->retail_api_url, 'retailApiKey' => $setting->retail_api_key]);
        $kassaStores = Site::find()->where(['setting_id' => $setting->id])->all();

        if ($kassaStores !== null and $existSites !== false and count($existSites) > 0) {
            foreach ($kassaStores as $kassaStore) {
                if (isset($existSites[$kassaStore->crm_store_code])) {
                    $needStores[] = [
                        'code' => $existSites[$kassaStore->crm_store_code]['code'],
                        'name' => $existSites[$kassaStore->crm_store_code]['name'],
                        'active' => true,
                    ];
                }
            }
        }

        if (count($needStores) === 0) {
            $needStores[] = [
                'code' => array_shift($existSites)['code'],
                'name' => array_shift($existSites)['name'],
                'active' => true,
            ];
        }

        return $needStores;
    }

    /**
     * @param Site $site
     * @return bool
     * @throws \Throwable
     */
    public function delete(Site $site): bool
    {
        $transaction = Yii::$app->db->beginTransaction();
        try {
            $site->delete();
            $transaction->commit();

            Yii::$app->getSession()->setFlash("success", "Касса удалена.");

            return true;
        } catch(\Exception $th) {
            $transaction->rollBack();

            Yii::error($th->getMessage(), "Ошибка удаления кассы. $site->id");
            Yii::$app->getSession()->setFlash("error", "Ошибка удаления кассы.");

            return false;
        }
    }

    /**
     * @param Setting $setting
     * @param string $siteCode
     * @return string
     */
    public function getStoreNameByCode(Setting $setting, string $siteCode): string
    {
        $needStores = [];
        $siteName = '';

        $storesList = Yii::$app->retail->sitesList(['retailApiUrl' => $setting->retail_api_url, 'retailApiKey' => $setting->retail_api_key]);
        if ($storesList !== false) {
            foreach ($storesList as $item) {
                $needStores[$item['code']] = $item['name'];
            }
        }

        if (isset($needStores[$siteCode])) {
            $siteName = $needStores[$siteCode];
        }

        return $siteName;
    }

    /**
     * @param $site
     * @return bool
     */
    public function save($site): bool
    {
        try {
            $site->updated_at = time();
            $site->save();

            Yii::$app->getSession()->setFlash("success", "Настройки аккаунта сохранены.");

            return true;
        } catch(\Exception $th) {

            Yii::$app->getSession()->setFlash("error", "Ошибка сохранения аккаунта.");
            Yii::error($th, 'wazzup_telegram_log');
            return false;
        }
    }
}
