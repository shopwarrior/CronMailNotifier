<?php

class Shopware_Plugins_Backend_CronMailNotifier_Bootstrap extends Shopware_Components_Plugin_Bootstrap
{
    const EMAIL_KEY_NOTIFICATION = 'sOrderStatistic';

    /**
     * Helper for availability capabilities
     * @return array
     */
    public function getCapabilities(){
        return array(
            'install' => true,
            'update' => true,
            'enable' => true,
            'secureUninstall' => true
        );
    }

    /**
     * Returns the meta information about the plugin.
     *
     * @return array
     * @throws Exception
     */
    public function getInfo()
    {
        return array(
            'version' => $this->getVersion(),
            'author' => 'Best Shopware Newbie',
            'supplier' => 'Best Shopware Newbie',
            'label' => $this->getLabel(),
            'copyright' => 'Copyright &copy; '.date('Y').', Best Shopware Newbie',
            'description'    =>  file_get_contents( __DIR__ . '/description.html' ),
            'support' => 'info@BestShopwareNewbie',
            'link' => 'http://BestShopwareNewbie/'
        );
    }

    /**
     * Returns the version of plugin as string.
     *
     * @return string
     */
    public function getVersion() {
        return '0.0.1';
    }

    /**
     * Returns the plugin name for backend
     *
     * @return string
     */
    public function getLabel() {
        return 'Cron Mail Notifier';
    }

    /**
     * Standard plugin install method to register all required components.
     * @throws \Exception
     * @return bool success
     */
    public function install(){
//        Check min shopware version - if need
        if (!$this->assertMinimumVersion('4.3.3')) {
            throw new Exception('This plugin requires Shopware 4.3.3 or a later version.');
        }

        try{
            $this->subscribeEvents();
            $this->createConfig();
            $this->registerCronJobs();
            $this->createNotifyTemplate();
        } catch(Exception $e){
            return array(
                'success'   =>  false,
                'message'   =>  $e->getMessage(),
                'invalidateCache'   =>  $this->getInvalidateCacheArray()
            );
        }

        return array(
            'success'   =>  true,
            'message'   =>  'Plugin was sucessfully installed',
            'invalidateCache'   =>  $this->getInvalidateCacheArray()
        );
    }

    /**
     * On uninstall remove attributes and re-generate customer-attribute models
     * @return bool
     */
    public function uninstall(){
        try{
            $this->unRegisterCronJobs();
            $this->removeNotifyTemplate();
        }catch(Exception $e){
            return array(
                'success'   =>  false,
                'message'   =>  $e->getMessage(),
                'invalidateCache'   =>  $this->getInvalidateCacheArray()
            );
        }

        return array(
            'success'   =>  true,
            'message'   =>  'Plugin was sucessfully uninstalled',
            'invalidateCache'   =>  $this->getInvalidateCacheArray()
        );
    }

    /*
     * Add subscription for b-e events
     */
    public function onStartFrontDispatch(){
        $container = Shopware()->Container();

        $subscribers = array(
            new \ShopwarePlugins\CronMailNotifier\Subscriber\Cronjob($this, $container),
        );

        foreach($subscribers as $subscriber)
            $this->get('events')->addSubscriber( $subscriber );
    }

    /**
     * Register Plugin namespace in autoloader
     */
    public function afterInit()
    {
        $this->Application()->Loader()->registerNamespace(
            'ShopwarePlugins\CronMailNotifier',
            $this->Path()
        );
    }

    private function subscribeEvents(){
        $this->subscribeEvent('Enlight_Controller_Front_StartDispatch','onStartFrontDispatch');
    }

    private function createConfig(){
        $store = $preDefinedStatuses = array();

        $statuses = \Shopware()->Db()->fetchAll("
            SELECT `id`, `name`, `description`
            FROM `s_core_states`
            WHERE `group`='state'
            ORDER BY `position` ASC");

        foreach($statuses as $status){
            if($status['name']=='completely_delivered') $preDefinedStatuses[] = $status['id'];
            $store[] = array(
                $status['id'], $status['description'] . (isset($status['name'])? ' (' . $status['name'] . ')': '')
            );
        }

        $this->Form()->setElement('select', 'status', array(
            'label' => 'Order Status',
            'store' =>  $store,
            'value' =>  $preDefinedStatuses,
            'multiSelect' => true,
            'scope' => \Shopware\Models\Config\Element::SCOPE_SHOP,
            'description' => 'Mail will be count all orders with such statuses.'
        ));

        $store = $preDefinedGroups = array();

        $groups = \Shopware()->Db()->fetchAll("SELECT `id`, `groupkey`, `description`
        FROM s_core_customergroups
        ORDER BY `groupkey` ASC");

        foreach($groups as $group){
            $preDefinedGroups[] = $group['groupkey'];
            $store[] = array(
                $group['groupkey'], $group['description']
            );
        }

        $this->Form()->setElement('select', 'groupKeys', array(
            'label' => 'Customer Group',
            'store' =>  $store,
            'value' =>  $preDefinedGroups,
            'multiSelect' => true,
            'scope' => \Shopware\Models\Config\Element::SCOPE_SHOP,
            'description' => 'Mail will be count all orders only from these customer group.'
        ));

        $this->Form()->setElement('text', 'operator',
            array(
                'label' => 'EMail',
                'value' => Shopware()->Config()->Mail,
                'scope' =>  \Shopware\Models\Config\Element::SCOPE_SHOP,
                'description' => 'Cron mails will be send to this email.'
            )
        );

        $this->Form()->setElement('select', 'period', array(
            'label' => 'Period',
            'store' => array(
                array(1,'1 day'),
                array(7,'7 days'),
                array(14,'14 days'),
            ),
            'value' => 7,
            'scope' => \Shopware\Models\Config\Element::SCOPE_SHOP,
            'description' => 'Count order for N days.'
        ));

        return true;
    }

    private function registerCronJobs()
    {
        try{
            $this->createCronJob(
                'Cron Mail Notifier',
                'CronMailNotifier',
                604800
            );
        } catch(\Exception $e){ }
    }

    private function createNotifyTemplate(){
        try{
            Shopware()->Db()->insert('s_core_config_mails', array(
                'name' => Shopware_Plugins_Backend_CronMailNotifier_Bootstrap::EMAIL_KEY_NOTIFICATION,
                'frommail'=>'{config name=mail}',
                'fromname'=>'{config name=shopName}',
                'subject'=>'Count of orders by `CronMailNotifier` for {config name=shopName}',
                'content'=>
                    'Hello,'."\n\n".'Total {$sCount} orders for the last {$sPeriod} days.'."\n\n".'Team {config name=shopName} {config name=address}',
                'contentHTML'=>
                    'Hello,<br/><br/>

Total {$sCount} orders for the last {$sPeriod} days.<br/><br/>
Team {config name=shopName}<br/>{config name=address}',
                'ishtml'=>1,
                'mailtype'=>1,
            ));
        } catch(Exception $e){}
    }

    private function unRegisterCronJobs()
    {
        $sql = "DELETE FROM s_crontab WHERE pluginID = ?";
        Shopware()->Db()->query($sql, array($this->getId()));
    }

    private function removeNotifyTemplate()
    {
        try{
            Shopware()->Db()->exec( 'DELETE FROM `s_core_config_mails` WHERE `name`="'.Shopware_Plugins_Backend_CronMailNotifier_Bootstrap::EMAIL_KEY_NOTIFICATION.'"' );
        } catch(Exception $e){}
    }

    /**
     * Helper for cache array
     * @return array
     */
    private function getInvalidateCacheArray(){
        return array(
            'config', 'backend', 'proxy'
        );
    }
}