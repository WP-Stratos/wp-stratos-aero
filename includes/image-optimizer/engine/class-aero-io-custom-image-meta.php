<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Aero_IO_Custom_Image_Meta
{
    public static function check_custom_table()
    {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $table_name = $wpdb->prefix . "aero_io_files_opt_meta";
        if($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name)
        {
            $sql = "CREATE TABLE $table_name (
                id int NOT NULL AUTO_INCREMENT,
                path text NOT NULL,
                type text NOT NULL,
                path_hash CHAR(32),        
                convert_webp_status int,
                convert_avif_status int,
                error text,
                quality text NOT NULL,       
			    og_size int(10) unsigned,
			    convert_webp_size int(10) unsigned,
			    convert_avif_size int(10) unsigned,
                meta text,
                PRIMARY KEY (id),
			    UNIQUE KEY path_hash (path_hash)
                );";
            //reference to upgrade.php file
            dbDelta( $sql );
        }
    }

    public static function get_custom_image_meta($filename)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "aero_io_files_opt_meta";
        $path_hash= md5( $filename );
        $results = $wpdb->get_results( $wpdb->prepare("SELECT * FROM $table_name WHERE path_hash=%s",$path_hash), ARRAY_A );

        if(empty($results))
        {
            return false;
        }
        else
        {
            return $results[0];
        }
    }

    public static function generate_custom_image_meta($filename,$options)
    {
        $image_optimize_meta['convert_webp_status']=0;
        $image_optimize_meta['convert_avif_status']=0;
        $image_optimize_meta['error']="";

        $image_optimize_meta['quality']=$options['quality'];

        $image_optimize_meta['og_size']=filesize($filename);
        $image_optimize_meta['convert_webp_size']=0;
        $image_optimize_meta['convert_avif_size']=0;

        $image_optimize_meta['path'] = $filename;
        $type=pathinfo($filename, PATHINFO_EXTENSION);
        $image_optimize_meta['type'] =$type;
        $image_optimize_meta['path_hash'] = md5( $filename );
        $image_optimize_meta['meta']='';

        global $wpdb;
        $path_hash= md5( $filename );
        $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}aero_io_files_opt_meta WHERE path_hash =%s",$path_hash),ARRAY_A);
        if (empty($results))
        {
            $wpdb->insert($wpdb->prefix.'aero_io_files_opt_meta',$image_optimize_meta);
        }
        else
        {
            $where['path']=$filename;
            $wpdb->update($wpdb->prefix.'aero_io_files_opt_meta',$image_optimize_meta,$where);
        }

        return $image_optimize_meta;
    }

    public static function update_custom_image_meta($filename,$image_optimize_meta)
    {
        global $wpdb;
        $path_hash= md5( $filename );
        $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}aero_io_files_opt_meta WHERE path_hash =%s",$path_hash),ARRAY_A);
        unset($image_optimize_meta['id']);
        unset($image_optimize_meta['path_hash']);
        if (empty($results))
        {
            $wpdb->insert($wpdb->prefix.'aero_io_files_opt_meta',$image_optimize_meta);
        }
        else
        {
            $where['path_hash']=$path_hash;
            $wpdb->update($wpdb->prefix.'aero_io_files_opt_meta',$image_optimize_meta,$where);
        }
    }

    public static function get_image_status($path)
    {
        $status['convert_webp_status']=Aero_IO_Custom_Image_Meta::is_convert_webp($path);
        $status['convert_avif_status']=Aero_IO_Custom_Image_Meta::is_convert_avif($path);

        return $status;
    }

    public static function is_convert_webp($path)
    {
        global $wpdb;
        $table=$wpdb->prefix.'aero_io_files_opt_meta';
        $path_hash= md5( $path );
        $result=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE `path_hash`=%s AND `convert_webp_status`=1",$path_hash),ARRAY_N);
        if(!empty($result) && sizeof($result)>0)
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public static function is_convert_avif($path)
    {
        global $wpdb;
        $table=$wpdb->prefix.'aero_io_files_opt_meta';
        $path_hash= md5( $path );
        $result=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE `path_hash`=%s AND `convert_avif_status`=1",$path_hash),ARRAY_N);
        if(!empty($result) && sizeof($result)>0)
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public static function get_convert_webp_size($path)
    {
        global $wpdb;
        $table=$wpdb->prefix.'aero_io_files_opt_meta';
        $path_hash= md5( $path );
        $result=$wpdb->get_results($wpdb->prepare("SELECT `convert_webp_size` FROM $table WHERE `path_hash`=%s",$path_hash),ARRAY_N);
        if(!empty($result) && sizeof($result)>0)
        {
            return $result[0][0];
        }
        else
        {
            return 0;
        }
    }

    public static function get_convert_avif_size($path)
    {
        global $wpdb;
        $table=$wpdb->prefix.'aero_io_files_opt_meta';
        $path_hash= md5( $path );
        $result=$wpdb->get_results( $wpdb->prepare("SELECT `convert_avif_size` FROM $table WHERE `path_hash`=%s",$path_hash),ARRAY_N);
        if(!empty($result) && sizeof($result)>0)
        {
            return $result[0][0];
        }
        else
        {
            return 0;
        }
    }
}