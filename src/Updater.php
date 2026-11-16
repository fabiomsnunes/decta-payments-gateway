<?php

namespace WooCommercePMG;

class Updater
{
    public $plugin_id;
    public $plugin_name;
    public $plugin_description;
    public $plugin_version;
    public $info_file_path;


    /**
     * static method which return url with file of current plugin stable version
     * @return string
     */
    public static function get_url()
    {
        return isset($_ENV['PLUGIN_UPDATE_CHECKER_URL']) ? $_ENV['PLUGIN_UPDATE_CHECKER_URL'] : '';
    }


    /**
     * init default configuration
     */
    public function __construct()
    {
        $this->plugin_id = isset($_ENV['PLUGIN_ID']) ? $_ENV['PLUGIN_ID'] : '';
        $this->plugin_name = isset($_ENV['PLUGIN_NAME']) ? $_ENV['PLUGIN_NAME'] : '';
        $this->plugin_description = isset($_ENV['PLUGIN_DESCRIPTION']) ? $_ENV['PLUGIN_DESCRIPTION'] : '';
        $this->plugin_version = isset($_ENV['PAYMENTS_MODULE_VERSION']) ? $_ENV['PAYMENTS_MODULE_VERSION'] : '';
        $this->info_file_path = plugin_dir_path(__DIR__).'/readme.txt';
    }


    /**
     * method check updates for our plugin and put it to the $transient array
     * @param $transient - WP array with data about plugins updates
     * @return $transient
     */
    public function get_last_update($transient)
    {
        if ($transient === false) {
            return false;
        }
        // disable because it will enabled/tested in rev.4.0.0
        return $this->add_default_moc_no_updates($transient);

        $data = $this->download_version_file();
        if ($data === false
            || !is_array($data)
            || !$this->check_is_new_version($data)) {
            return $this->add_default_moc_no_updates($transient);
        }

        $item = (object) array(
            'id'            => 'woocommerce-pmg/woocommerce-pmg.php',
            'slug'          => $data['PLUGIN_ID'],
            'plugin'        => 'woocommerce-pmg/woocommerce-pmg.php',
            'new_version'   => $data['PAYMENTS_MODULE_VERSION'],
            'package'       => $data['PAYMENTS_PACKAGE'],
            'icons'         => array(),
            'banners'       => array(),
            'banners_rtl'   => array(),
            'tested'        => '',
            'requires_php'  => '',
            'compatibility' => new \stdClass(),
        );

        $transient->response['woocommerce-pmg/woocommerce-pmg.php'] = $item;
        return $transient;
    }


    /**
     * private method which get info from url about current stable version and parse data
     * @return false|mixed
     */
    private function download_version_file()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, static::get_url());
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_HTTP200ALIASES, (array)400);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-type: application/json',
        ));

        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return false;
        }

        return json_decode($response, true);
    }


    /**
     * method check info which we got from URL and compare with current version
     * @param $data
     * @return bool
     */
    private function check_is_new_version($data)
    {
        if (!isset($data['PLUGIN_ID'])
            ||
            $data['PLUGIN_ID'] != $this->plugin_id) {
            return false;
        }

        if (!isset($data['PAYMENTS_MODULE_VERSION'])
            ||
            version_compare($data['PAYMENTS_MODULE_VERSION'], $this->plugin_version, '<=')) {
            return false;
        }
        return true;
    }


    /**
     * method which generate default MOC data if we don`t have updates
     * @param $transient
     * @return mixed
     */
    private function add_default_moc_no_updates($transient)
    {
        $item = (object) array(
            'id'            => WC_PMG_PLUGIN_DIR.'/woocommerce-pmg.php',
            'slug'          => $this->plugin_id,
            'plugin'        => WC_PMG_PLUGIN_DIR.'/woocommerce-pmg.php',
            'new_version'   => $this->plugin_version,
            'icons'         => array(),
            'banners'       => array(),
            'banners_rtl'   => array(),
            'tested'        => '',
            'requires_php'  => '',
            'compatibility' => new \stdClass(),
        );

        $transient->no_update[WC_PMG_PLUGIN_DIR.'/woocommerce-pmg.php'] = $item;
        return $transient;
    }


    /**
     * method which return information about plugin
     * @param $res
     * @param $action
     * @param $args
     * @return \stdClass|false
     */
    public function get_info($res, $action, $args)
    {
        if ('plugin_information' !== $action) {
            return $res;
        }

        if ($this->plugin_id !== $args->slug) {
            return $res;
        }

        $res = new \stdClass();
        $res->name = $this->plugin_name;
        $res->slug = $this->plugin_id;
        $res->version = $this->plugin_version;
        $res->requires = isset($_ENV['WORDPRESS_VERSION_MIN']) ? $_ENV['WORDPRESS_VERSION_MIN'] : '';
        ;
        $res->author = isset($_ENV['AUTHOR']) ? $_ENV['AUTHOR'] : '';
        ;
        //        $res->download_link = isset( $_ENV['PLUGIN_PACKAGE_URL'] )?$_ENV['PLUGIN_PACKAGE_URL']:'';;
        $res->requires_php = isset($_ENV['PHP_VERSION_MIN']) ? $_ENV['PHP_VERSION_MIN'] : '';
        $res->sections = array(
            'description' => isset($_ENV['PLUGIN_DESCRIPTION']) ? $_ENV['PLUGIN_DESCRIPTION'] : '',
            'installation' => $this->decorate_readme_content()
        );
        return $res;
    }


    /**
     * method parse data from readme.txt and convert to HTML
     * @return string
     */
    private function decorate_readme_content()
    {
        if (!file_exists($this->info_file_path)) {
            return false;
        }
        $content = file_get_contents($this->info_file_path);
        return str_replace("\n", "<br/>", $content);
    }
}
