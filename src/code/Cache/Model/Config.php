<?php

/**
 * Custom config model that leverages a redis lock to prevent config generation
 * DDoS, typically a problem when you have many store views and a high amount
 * of visitors.
 *
 * Also depends on a local copy of Mage_Core_Model_App put in app/code/local.
 * The reason for this local override is that the app class _initModules()
 * needs locking as well, otherwise everything arbitrary will break. Quite
 * annoying that this is the way it's made, but hey, we have no say.
 *
 * @author jonathan@madepeople.se
 */
class Made_Cache_Model_Config extends Mage_Core_Model_Config
{

    /**
     * Reinitialize configuration. Make sure we don't end up here in case the
     * config is already being regenerated
     *
     * @param   array $options
     * @return  Mage_Core_Model_Config
     */
    public function reinit($options = array())
    {
        $backend = Mage::app()->getCacheInstance()
            ->getFrontend()
            ->getBackend();

        $options = $this->getLockingOptions();

        if ($backend->acquireLock($options['lock_name'], $options['token'], $options['lock_timeout'])) {
            $this->_allowCacheForInit = false;
            $this->_useCache = false;
            $options['lock_acquired'] = true;
            $this->init($options);
            $backend->releaseLock($options['lock_name'], $options['token']);
        }

        return $this;
    }

    /**
     * Initialization of core configuration
     *
     * @return Mage_Core_Model_Config
     */
    public function init($options=array())
    {
        $this->setCacheChecksum(null);
        $this->_cacheLoadedSections = array();
        $this->setOptions($options);
        $this->loadBase();

        $cacheLoad = $this->loadModulesCache();
        if ($cacheLoad) {
            return $this;
        }

        $lockSpun = false;
        if (empty($options['lock_acquired'])) {
            $backend = Mage::app()->getCacheInstance()
                ->getFrontend()
                ->getBackend();

            $options = $this->getLockingOptions();

            // Spin lock
            while (!$backend->acquireLock($options['lock_name'], $options['token'], $options['lock_timeout'])) {
                $lockSpun = true;
                usleep($options['spin_timeout']);
            }
        }

        if ($lockSpun) {
            // The cache might have been generated while we waited for the lock
            $cacheLoad = $this->loadModulesCache();
            if ($cacheLoad) {
                $backend->releaseLock($options['lock_name'], $options['token']);
                return $this;
            }
        }

        // Check cache
        $this->loadModules();
        $this->loadDb();
        $this->saveCache();

        if (empty($options['lock_acquired'])) {
            $backend->releaseLock($options['lock_name'], $options['token']);
        }

        return $this;
    }

    /**
     * Parameters used for locking the config cache generation
     *
     * @return array
     */
    public function getLockingOptions()
    {
        $options = array(
            'lock_name' => 'lock.config_init',
            'lock_timeout' => 30, // In seconds
            'spin_timeout' => 100000 // 100 ms, uses usleep()
        );
        $options['token'] = md5($options['lock_name']);
        return $options;
    }

}
