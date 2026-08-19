<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Aero_IO_Rewrite_Checker
{
    public $og_test_file;
    public $og_test_webp_file;
    public $test_file;
    public $test_webp_file;

    public function __construct()
    {
        $this->og_test_file='/engine/assets/aero_io_test.png';
        $this->og_test_webp_file='/engine/assets/aero_io_test.png.webp';
        $this->test_file='aero_io_test.png';
        $this->test_webp_file='aero_io_test.png.webp';
    }

    public function test()
    {
        $upload_dir = wp_upload_dir();
        $path = $upload_dir['basedir'] . '/' . $this->test_file;
        if (!file_exists($path)) {
            $og_path = AERO_IO_DIR . $this->og_test_file;
            copy($og_path, $path);
        }

        $path2 = WP_CONTENT_DIR . '/aero-nextgen/uploads/' . $this->test_webp_file;

        if (!file_exists($path2)) {
            $og_path = AERO_IO_DIR . $this->og_test_webp_file;
            copy($og_path, $path2);
        }

        $og_size = filesize($path);
        $test_size = $this->get_test_size();
        if ($test_size === false)
        {
            return false;
        }
        else if($test_size===401)
        {
            return true;
        }


        if ($og_size > $test_size)
        {
            return true;
        }
        else
        {
            if($this->is_cf_cached())
            {
                return true;
            }
            return false;
        }
    }

    public function is_cf_cached()
    {
        $upload_dir   = wp_upload_dir();
        $url=$upload_dir['baseurl'].'/'. $this->test_file;
        $headers['Accept']='image/webp,image/*';
        foreach ( wp_get_nocache_headers() as $header_key => $header_value )
        {
            $headers[$header_key] =$header_value;
        }
        $args['headers']= $headers;
        $response=wp_remote_request($url,$args);
        if(!is_wp_error($response))
        {
            if(isset($response['headers']['cf-cache-status']))
            {
                if($response['headers']['cf-cache-status']=='HIT')
                {
                    return true;
                }
            }

            return false;
        }
        else
        {
            return false;
        }
    }

    public function test_ex()
    {
        $upload_dir = wp_upload_dir();
        $path = $upload_dir['basedir'] . '/' . $this->test_file;
        if (!file_exists($path)) {
            $og_path = AERO_IO_DIR . $this->og_test_file;
            copy($og_path, $path);
        }

        $path2 = WP_CONTENT_DIR . '/aero-nextgen/uploads/' . $this->test_webp_file;

        if (!file_exists($path2)) {
            $og_path = AERO_IO_DIR . $this->og_test_webp_file;
            copy($og_path, $path2);
        }

        $og_size = filesize($path);
        $ret = $this->get_test_size_ex();
        if ($ret['result'] === 'success')
        {
            $test_size=$ret['size'];
            if ($og_size > $test_size)
            {
                $ret['result']='success';
                return $ret;
            }
            else
            {
                if($this->is_cf_cached())
                {
                    $ret['result']='failed';
                    $ret['error']='image cached by cloudflare';
                    return $ret;
                }
                else
                {
                    $ret['result']='failed';
                    $ret['error']='htaccess rewrite not work';
                    return $ret;
                }
            }
        }
        else
        {
            return $ret;
        }
    }

    public function get_test_size()
    {
        $upload_dir   = wp_upload_dir();
        $url=$upload_dir['baseurl'].'/'. $this->test_file;
        return $this->get_remote_url($url);
    }

    public function get_test_size_ex()
    {
        $upload_dir   = wp_upload_dir();
        $url=$upload_dir['baseurl'].'/'. $this->test_file;
        $headers['Accept']='image/webp,image/*';
        foreach ( wp_get_nocache_headers() as $header_key => $header_value )
        {
            $headers[$header_key] =$header_value;
        }
        $args['headers']= $headers;
        $response=wp_remote_request($url,$args);
        if(!is_wp_error($response))
        {
            if($response['response']['code']==200)
            {
                $ret['result']='success';
                $ret['size']=strlen($response['body']);
                return $ret;
            }
            else
            {
                $ret['result']='failed';
                $ret['error']=$response['response']['message'];
                return $ret;
            }
        }
        else
        {
            $ret['result']='failed';
            $ret['error'] =$response->get_error_message() ;
            return $ret;
        }
    }

    public function get_remote_url($url)
    {
        $headers['Accept']='image/webp,image/*';
        foreach ( wp_get_nocache_headers() as $header_key => $header_value )
        {
            $headers[$header_key] =$header_value;
        }
        $args['headers']= $headers;
        $response=wp_remote_request($url,$args);
        if(!is_wp_error($response))
        {
            if($response['response']['code']==200)
            {
                return strlen($response['body']);
            }
            else if($response['response']['code']==401)
            {
                return 401;
            }
        }
        else
        {
            return false;
        }

        return false;
    }

    public function is_apache()
    {
        $server =  strtolower( sanitize_text_field($_SERVER['SERVER_SOFTWARE']));
        if ( strpos( $server, 'apache' ) !== false )
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public function is_nginx()
    {
        $server =  strtolower( sanitize_text_field($_SERVER['SERVER_SOFTWARE']));
        if ( strpos( $server, 'nginx' ) !== false )
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public function is_litespeed()
    {
        $server =  strtolower( sanitize_text_field($_SERVER['SERVER_SOFTWARE']));
        if ( strpos( $server, 'litespeed' ) !== false )
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public function is_active_cache()
    {
        $current = get_option( 'active_plugins', array() );

        $cache='wp-cloudflare-page-cache/wp-cloudflare-super-page-cache.php';

        if (($key = array_search($cache, $current)) !== false)
        {
            return true;
        }
        else
        {
            return false;
        }
    }
}