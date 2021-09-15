<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "setting".
 *
 * @property int $id
 * @property string $client_id
 * @property string $retail_api_url
 * @property string $retail_api_key
 * @property int|null $is_active
 * @property int|null $is_freeze
 * @property int|null $created_at
 * @property int|null $updated_at
 */
class Setting extends \yii\db\ActiveRecord
{
    const NEEDED_CREDENTIALS = [
        '/api/integration-modules/{code}',
        '/api/integration-modules/{code}/edit',
        '/api/orders/create',
        '/api/orders/{externalId}/edit',
        '/api/reference/order-methods',
        '/api/reference/order-methods/{code}/edit',
        '/api/reference/sites',
    ];

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return '{{%setting}}';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['client_id', 'retail_api_url', 'retail_api_key'], 'required'],
            [['is_active', 'is_freeze', 'created_at', 'updated_at'], 'integer'],
            [['client_id', 'retail_api_url', 'retail_api_key'], 'string', 'max' => 255],
            [['client_id'], 'unique'],
            [['retail_api_url'], 'unique'],
            ['retail_api_url', 'validateApiUrl'],
            ['retail_api_key', 'validateApiKey'],
        ];
    }

    /**
     * Валидация аккаунта RetailCRM
     */
    public function validateApiUrl()
    {
        if (substr($this->retail_api_url, -1) == '/') {
            $this->retail_api_url = mb_substr($this->retail_api_url, 0, -1);
        }

        if (self::find()->where(['retail_api_url' => $this->retail_api_url])->andWhere(['!=', 'client_id', $this->client_id])->one()) {
            $this->addError('retail_api_url',  'Данный аккаунт уже зарегистрирован в системе.');
        }
    }

    /**
     * Валидация ключа доступа
     */
    public function validateApiKey()
    {
        if ($this->retail_api_url && $this->retail_api_key) {

            $credentials = Yii::$app->retail->credentials([
                'retailApiUrl' => $this->retail_api_url,
                'retailApiKey' => $this->retail_api_key
            ]);

            if ($credentials !== null) {
                if (isset($credentials['credentials'])) {
                    foreach (self::NEEDED_CREDENTIALS as $NEEDED_CREDENTIAL) {
                        if (array_search($NEEDED_CREDENTIAL, $credentials['credentials']) === false) {
                            $this->addError('retail_api_key',  'Недоступен метод '.$NEEDED_CREDENTIAL.'');
                        }
                    }
                } else {
                    $this->addError('retail_api_key',  'Выберите как минимум 1 разрешенный метод в настройках API-ключа.');
                }
            } else {
                $this->addError('retail_api_key',  'Некорректно указана ссылка на RetailCRM или ключ доступа к api.');
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'client_id' => 'Client ID',
            'retail_api_url' => 'Ссылка на RetailCRM вида: https://YOUR-DOMAIN.retailcrm.ru',
            'retail_api_key' => 'API-ключ RetailCRM',
            'is_active' => 'Is Active',
            'is_freeze' => 'Is Freeze',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }


    /**
     * Gets query for [[Kassas]].
     *
     * @return \yii\db\ActiveQuery
     */
    public function getKassas()
    {
        return $this->hasMany(Kassa::className(), ['setting_id' => 'id']);
    }
}
