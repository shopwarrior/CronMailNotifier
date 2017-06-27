<?php

namespace ShopwarePlugins\CronMailNotifier\Subscriber;

use Shopware\Components\DependencyInjection\Container as Container;
use Shopware_Plugins_Backend_CronMailNotifier_Bootstrap as Bootstrap;

class Cronjob implements \Enlight\Event\SubscriberInterface
{
    /**
     * @var null|Bootstrap
     */
    private $bootstrap = null;

    /**
     * @var Container
     */
    protected $container = null;

    function __construct(Container $container) {
        $this->container = $container;
    }

    public static function getSubscribedEvents() {
        return [
            'Shopware_CronJob_CronMailNotifier' => 'onRunCronMailNotifier'
        ];
    }

    /**
     * @return bool
     */
    public function onRunCronMailNotifier()
    {
        $config = Shopware()->Plugins()->Backend()->CronMailNotifier()->Config();
        if ($config instanceof \Enlight_Config) {
            $config = $config->toArray();
        } else {
            $config = (array) $config;
        }
        $status = $config['status'];
        $groupKeys = $config['groupKeys'];
        $operatorEmail = $config['operator'];
        $period = $config['period']? $config['period']: 1000;

        $sql = 'SELECT count(o.id) as sCount
            FROM `s_order` as `o`
            INNER JOIN `s_user` as u ON u.`id`=o.userID
            AND (
                o.`ordertime` between (NOW() - INTERVAL '.$period.' day)
                AND NOW()
            )
            AND `status` IN ('.implode(',',$status).')';
        if(count($groupKeys)){
            $sql .= ' AND u.`customergroup` IN ("'.implode('","',$groupKeys).'")';
        }
        $sCount = Shopware()->Db()->fetchOne( $sql );

        try{
            $context = array();
            $context['sPeriod'] = $period;
            $context['sCount'] = $sCount;

            $mail = Shopware()->TemplateMail()->createMail(
                \Shopware_Plugins_Backend_CronMailNotifier_Bootstrap::EMAIL_KEY_NOTIFICATION,
                $context
            );
            $mail->addTo( $operatorEmail );
            $mail->send();
        }catch(\Exception $e){
            return $e->getMessage();
        }

        return true;
    }
}
?>