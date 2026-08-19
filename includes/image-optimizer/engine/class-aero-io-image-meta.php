<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Aero_IO_Image_Meta
{
    public static function has_image_meta($image_id)
    {
        if(empty(get_post_meta( $image_id, 'aero_io_image_meta', true )))
        {
            return false;
        }
        else
        {
            return true;
        }
    }

    public static function get_image_meta($image_id)
    {
        return get_post_meta( $image_id, 'aero_io_image_meta', true );
    }

    public static function get_image_meta_value($image_id,$key)
    {
        $meta=get_post_meta( $image_id, 'aero_io_image_meta', true );
        if(empty($meta))
        {
            return false;
        }
        else
        {
            if(isset($meta[$key]))
            {
                return $meta[$key];
            }
            else
            {
                return false;
            }
        }
    }

    public static function update_images_meta_value($image_id,$key,$value)
    {
        $image_optimize_meta =self::get_image_meta($image_id);
        $image_optimize_meta[$key]=$value;
        self::update_images_meta($image_id,$image_optimize_meta);
    }

    public static function update_image_meta_size($image_id,$size_key,$size_meta)
    {
        $image_optimize_meta =self::get_image_meta($image_id);
        $image_optimize_meta['size'][$size_key]=$size_meta;
        self::update_images_meta($image_id,$image_optimize_meta);
    }

    public static function update_images_meta($image_id,$meta)
    {
        update_post_meta($image_id,'aero_io_image_meta',$meta);
    }

    public static function generate_images_meta($image_id,$options)
    {
        $file_path = get_attached_file( $image_id );
        $image_optimize_meta=array();

        delete_post_meta($image_id,'aero_io_image_meta_webp_converted');
        delete_post_meta($image_id,'aero_io_image_meta_avif_converted');
        delete_post_meta($image_id,'aero_io_image_meta_compressed');

        delete_post_meta($image_id,'aero_io_image_meta_og_file_size');
        delete_post_meta($image_id,'aero_io_image_meta_webp_converted_size');
        delete_post_meta($image_id,'aero_io_image_meta_avif_converted_size');
        delete_post_meta($image_id,'aero_io_image_meta_compressed_size');

        update_post_meta($image_id,'aero_io_image_meta_status','pending');
        //update_post_meta($image_id,'aero_io_image_meta_webp_converted',0);
        //update_post_meta($image_id,'aero_io_image_meta_avif_converted',0);
        //update_post_meta($image_id,'aero_io_image_meta_compressed',0);

        $image_optimize_meta['status']='pending';
        $image_optimize_meta['webp_converted']=0;
        $image_optimize_meta['avif_converted']=0;
        $image_optimize_meta['compressed']=0;

        $image_optimize_meta['resize_status']=0;
        $image_optimize_meta['compress_status']=0;
        $image_optimize_meta['quality']=$options['quality'];

        $image_optimize_meta['og_file_size']=filesize($file_path);

        $image_optimize_meta['webp_converted_size']=0;
        $image_optimize_meta['avif_converted_size']=0;
        $image_optimize_meta['compressed_size']=0;

        $meta = wp_get_attachment_metadata( $image_id, true );
        if(empty($meta['sizes']))
        {
            $image_optimize_meta['size']['og']['compress_status']=0;
            $image_optimize_meta['size']['og']['convert_webp_status']=0;
            $image_optimize_meta['size']['og']['convert_avif_status']=0;
            $image_optimize_meta['size']['og']['status']="pending";
            $image_optimize_meta['size']['og']['error']="";
            $image_optimize_meta['size']['og']['file'] = get_post_meta( $image_id, '_wp_attached_file', true );
        }
        else
        {
            $image_optimize_meta['size']['og']['compress_status']=0;
            $image_optimize_meta['size']['og']['convert_webp_status']=0;
            $image_optimize_meta['size']['og']['convert_avif_status']=0;
            $image_optimize_meta['size']['og']['status']="pending";
            $image_optimize_meta['size']['og']['error']="";
            $image_optimize_meta['size']['og']['file']= get_post_meta( $image_id, '_wp_attached_file', true );

            foreach ( $meta['sizes'] as $size_key => $size_data )
            {
                $image_optimize_meta['size'][$size_key]['compress_status']=0;
                $image_optimize_meta['size'][$size_key]['convert_webp_status']=0;
                $image_optimize_meta['size'][$size_key]['convert_avif_status']=0;
                $image_optimize_meta['size'][$size_key]['status']="pending";
                $image_optimize_meta['size'][$size_key]['error']="";

                $image_optimize_meta['size'][$size_key]['file']=$size_data['file'];
            }
        }

        //update_post_meta($image_id,'aero_io_image_meta_og_file_size',$og_size);
        //update_post_meta($image_id,'aero_io_image_meta_webp_converted_size',0);
        //update_post_meta($image_id,'aero_io_image_meta_avif_converted_size',0);
        //update_post_meta($image_id,'aero_io_image_meta_compressed_size',0);
        update_post_meta($image_id,'aero_io_image_meta',$image_optimize_meta);

        return $image_optimize_meta;
    }

    public static function delete_image_mete($image_id)
    {
        delete_post_meta($image_id,'aero_io_image_meta');
        delete_post_meta($image_id,'aero_io_image_progressing');
        delete_post_meta($image_id,'aero_io_image_meta_status');
        //old
        delete_post_meta($image_id,'aero_io_image_meta_webp_converted');
        delete_post_meta($image_id,'aero_io_image_meta_avif_converted');
        delete_post_meta($image_id,'aero_io_image_meta_og_file_size');
        delete_post_meta($image_id,'aero_io_image_meta_webp_converted_size');
        delete_post_meta($image_id,'aero_io_image_meta_avif_converted_size');

    }

    public static function get_image_progressing($image_id)
    {
        return get_post_meta( $image_id, 'aero_io_image_progressing', true );
    }

    public static function update_image_progressing($image_id)
    {
        update_post_meta($image_id,'aero_io_image_progressing',time());
    }

    public static function delete_image_progressing($image_id)
    {
        delete_post_meta($image_id,'aero_io_image_progressing');
    }

    public static function get_image_meta_status($image_id)
    {
        return get_post_meta( $image_id, 'aero_io_image_meta_status', true );
    }

    public static function update_image_meta_status($image_id,$status)
    {
        if(self::has_image_meta($image_id))
        {
            $meta=get_post_meta( $image_id, 'aero_io_image_meta', true );
            $meta['status']=$status;
            update_post_meta($image_id,'aero_io_image_meta',$meta);
        }

        update_post_meta($image_id,'aero_io_image_meta_status',$status);
    }

    public static function get_image_meta_webp_converted($image_id)
    {
        $meta=get_post_meta( $image_id, 'aero_io_image_meta', true );

        if(isset($meta['webp_converted']))
        {
            return $meta['webp_converted'];
        }
        else
        {
            $converted=get_post_meta( $image_id, 'aero_io_image_meta_webp_converted', true );
            if(empty($converted))
            {
                return 0;
            }
            else
            {
                $meta['webp_converted']=$converted;
                update_post_meta($image_id,'aero_io_image_meta',$meta);
                delete_post_meta($image_id,'aero_io_image_meta_webp_converted');
                return $converted;
            }
        }
    }

    public static function get_image_meta_avif_converted($image_id)
    {
        $meta=get_post_meta( $image_id, 'aero_io_image_meta', true );

        if(isset($meta['avif_converted']))
        {
            return $meta['avif_converted'];
        }
        else
        {
            $converted=get_post_meta( $image_id, 'aero_io_image_meta_avif_converted', true );
            if(empty($converted))
            {
                return 0;
            }
            else
            {
                $meta['avif_converted']=$converted;
                update_post_meta($image_id,'aero_io_image_meta',$meta);
                delete_post_meta($image_id,'aero_io_image_meta_avif_converted');
                return $converted;
            }
        }
    }

    public static function get_image_meta_compressed($image_id)
    {
        $meta=get_post_meta( $image_id, 'aero_io_image_meta', true );

        if(isset($meta['compressed']))
        {
            return $meta['compressed'];
        }
        else
        {
            $compressed=get_post_meta( $image_id, 'aero_io_image_meta_compressed', true );
            if(empty($compressed))
            {
                return 0;
            }
            else
            {
                $meta['compressed']=$compressed;
                update_post_meta($image_id,'aero_io_image_meta',$meta);
                delete_post_meta($image_id,'aero_io_image_meta_compressed');
                return $compressed;
            }
        }
    }

    public static function update_image_meta_webp_converted($image_id,$converted)
    {
        $meta=get_post_meta( $image_id, 'aero_io_image_meta', true );
        $meta['webp_converted']=$converted;
        update_post_meta($image_id,'aero_io_image_meta',$meta);
    }

    public static function update_image_meta_avif_converted($image_id,$converted)
    {
        $meta=get_post_meta( $image_id, 'aero_io_image_meta', true );
        $meta['avif_converted']=$converted;
        update_post_meta($image_id,'aero_io_image_meta',$meta);
    }

    public static function update_image_meta_compressed($image_id,$compressed)
    {
        $meta=get_post_meta( $image_id, 'aero_io_image_meta', true );
        $meta['compressed']=$compressed;
        update_post_meta($image_id,'aero_io_image_meta',$meta);
    }

    public static function update_webp_converted_size($image_id,$convert_size)
    {
        $meta=get_post_meta( $image_id, 'aero_io_image_meta', true );
        $meta['webp_converted_size']=$convert_size;
        update_post_meta($image_id,'aero_io_image_meta',$meta);
    }

    public static function update_avif_converted_size($image_id,$convert_size)
    {
        $meta=get_post_meta( $image_id, 'aero_io_image_meta', true );
        $meta['avif_converted_size']=$convert_size;
        update_post_meta($image_id,'aero_io_image_meta',$meta);
    }

    public static function update_compressed_size($image_id,$compressed_size)
    {
        $meta=get_post_meta( $image_id, 'aero_io_image_meta', true );
        $meta['compressed_size']=$compressed_size;
        update_post_meta($image_id,'aero_io_image_meta',$meta);
    }

    public static function update_og_size($image_id,$size)
    {
        $meta=get_post_meta( $image_id, 'aero_io_image_meta', true );
        $meta['og_file_size']=$size;
        update_post_meta($image_id,'aero_io_image_meta',$meta);
    }

    public static function get_og_size($image_id)
    {
        $meta=get_post_meta( $image_id, 'aero_io_image_meta', true );
        if(isset($meta['og_file_size']))
        {
            return $meta['og_file_size'];
        }
        else
        {
            $og_size=get_post_meta( $image_id, 'aero_io_image_meta_og_file_size', true );
            if(empty($og_size))
            {
                return 0;
            }
            else
            {
                $meta['og_file_size']=$og_size;
                update_post_meta($image_id,'aero_io_image_meta',$meta);
                delete_post_meta($image_id,'aero_io_image_meta_og_file_size');
                return $og_size;
            }
        }
    }

    public static function get_webp_converted_size($image_id)
    {
        $meta=get_post_meta( $image_id, 'aero_io_image_meta', true );
        if(isset($meta['webp_converted_size']))
        {
            return $meta['webp_converted_size'];
        }
        else
        {
            $convert_size=get_post_meta( $image_id, 'aero_io_image_meta_webp_converted_size', true );
            if(empty($convert_size))
            {
                return 0;
            }
            else
            {
                $meta['webp_converted_size']=$convert_size;
                update_post_meta($image_id,'aero_io_image_meta',$meta);
                delete_post_meta($image_id,'aero_io_image_meta_webp_converted_size');
                return $convert_size;
            }
        }
    }

    public static function get_avif_converted_size($image_id)
    {
        $meta=get_post_meta( $image_id, 'aero_io_image_meta', true );
        if(isset($meta['avif_converted_size']))
        {
            return $meta['avif_converted_size'];
        }
        else
        {
            $convert_size=get_post_meta( $image_id, 'aero_io_image_meta_avif_converted_size', true );
            if(empty($convert_size))
            {
                return 0;
            }
            else
            {
                $meta['avif_converted_size']=$convert_size;
                update_post_meta($image_id,'aero_io_image_meta',$meta);
                delete_post_meta($image_id,'aero_io_image_meta_avif_converted_size');
                return $convert_size;
            }
        }
    }

    public static function get_compressed_size($image_id)
    {
        $meta=get_post_meta( $image_id, 'aero_io_image_meta', true );
        if(isset($meta['compressed_size']))
        {
            return $meta['compressed_size'];
        }
        else
        {
            $convert_size=get_post_meta( $image_id, 'aero_io_image_meta_compressed_size', true );
            if(empty($convert_size))
            {
                return 0;
            }
            else
            {
                $meta['compressed_size']=$convert_size;
                update_post_meta($image_id,'aero_io_image_meta',$meta);
                delete_post_meta($image_id,'aero_io_image_meta_compressed_size');
                return $convert_size;
            }
        }
    }

    public static function get_global_stats()
    {
        $update=get_transient( 'aero_io_set_global_stats' );
        $stats=Aero_IO_Options::get_option('aero_io_global_stats',array());
        if(empty($stats)||empty($update)||!isset($stat['webp_converted_percent']))
        {
            $stats=array();
            $stats['webp_converted_percent']=0;
            $stats['avif_converted_percent']=0;

            $webp_images_count=Aero_IO_Image_Method::get_max_webp_image_count();
            $avif_images_count=Aero_IO_Image_Method::get_max_avif_image_count();

            $stats['avif_converted']=0;
            $stats['webp_converted']=0;

            $stats['webp_total']=0;
            $stats['avif_total']=0;

            $stats['webp_saved']=0;
            $stats['avif_saved']=0;

            global $wpdb;
            $result=$wpdb->get_results( "SELECT DISTINCT `post_id` FROM $wpdb->postmeta WHERE `meta_key`=\"aero_io_image_meta_status\" AND `meta_value`=\"optimized\" ",ARRAY_N);
            if($result && sizeof($result)>0)
            {
                foreach ($result as $item)
                {
                    $image_id=$item[0];
                    wp_cache_delete( $image_id, 'post_meta' );
                    $og_size=self::get_og_size($image_id);
                    if($og_size>0)
                    {
                        if(self::get_image_meta_webp_converted($image_id))
                        {
                            $stats['webp_converted']++;
                            $webp_size=self::get_webp_converted_size($image_id);
                            $stats['webp_total']+=$og_size;
                            $stats['webp_saved']+=$webp_size;
                        }

                        if(self::get_image_meta_avif_converted($image_id))
                        {
                            $stats['avif_converted']++;
                            $avif_size=self::get_avif_converted_size($image_id);
                            $stats['avif_total']+=$og_size;
                            $stats['avif_saved']+=$avif_size;
                        }
                    }
                }
            }

            //
            if($webp_images_count>0)
            {
                $stats['webp_converted_percent'] = ( $stats['webp_converted'] / $webp_images_count ) * 100;
                $stats['webp_converted_percent'] = round( $stats['webp_converted_percent'], 2 );
            }
            else
            {
                $stats['webp_converted_percent']=0;
            }

            if($avif_images_count>0)
            {
                $stats['avif_converted_percent'] = ( $stats['avif_converted'] / $avif_images_count ) * 100;
                $stats['avif_converted_percent'] = round( $stats['avif_converted_percent'], 2);
            }

            if($stats['webp_total']>$stats['webp_saved'])
            {
                $saved=$stats['webp_total']-$stats['webp_saved'];
                $stats['webp_saved_percent'] = ( $saved / $stats['webp_total'] ) * 100;
                $stats['webp_saved_percent'] = round( $stats['webp_saved_percent'], 2 );
            }
            else
            {
                $stats['webp_saved_percent']=0;
            }

            if($stats['avif_total']>$stats['avif_saved'])
            {
                $saved=$stats['avif_total']-$stats['avif_saved'];

                $stats['avif_saved_percent'] = ($saved / $stats['avif_total'] ) * 100;
                $stats['avif_saved_percent'] = round( $stats['avif_saved_percent'], 2 );
            }
            else
            {
                $stats['avif_saved_percent'] = 0;
            }

            delete_transient('aero_io_set_global_stats');
            Aero_IO_Options::update_option('aero_io_global_stats',$stats);
            return $stats;
        }
        else
        {
            return $stats;
        }
    }

    public static function get_global_stats_ex()
    {
        $update=get_transient( 'aero_io_set_global_stats' );
        $stats=Aero_IO_Options::get_option('aero_io_global_stats_ex',array());
        if(empty($stats)||empty($update)||!isset($stat['converted_percent']))
        {
            $stats=array();
            $stats['converted_percent']=0;

            //$webp_images_count=Aero_IO_Image_Method::get_max_webp_image_count();
            $images_count=Aero_IO_Image_Method::get_max_image_count();

            global $wpdb;
            $result=$wpdb->get_results( "SELECT DISTINCT `post_id` FROM $wpdb->postmeta WHERE `meta_key`=\"aero_io_image_meta_status\" AND `meta_value`=\"optimized\" ",ARRAY_N);
            if($result && sizeof($result)>0)
            {
                $converted_images=sizeof($result);
            }
            else
            {
                $converted_images=0;
            }

            //
            if($images_count>0)
            {
                $stats['converted_percent'] = ($converted_images / $images_count ) * 100;
                $stats['converted_percent'] = round( $stats['converted_percent'], 2 );
            }
            else
            {
                $stats['converted_percent']=0;
            }

            delete_transient('aero_io_set_global_stats');
            Aero_IO_Options::update_option('aero_io_global_stats_ex',$stats);
            return $stats;
        }
        else
        {
            return $stats;
        }
    }

    public static function get_failed_images_count()
    {
        global $wpdb;
        $result=$wpdb->get_results( "SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key='aero_io_image_meta_status' AND meta_value = 'failed'",ARRAY_N);
        if($result && sizeof($result)>0)
        {
            $count = $result[0][0];
            if(is_null($count))
            {
                return 0;
            }
            else
            {
                return $count;
            }
        }

        return 0;
    }

    public static function is_image_optimized($image_id)
    {
        if(self::get_image_meta_status($image_id)==='optimized')
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public static function is_webp_image($image_id)
    {
        $file_path = get_attached_file($image_id);
        $type=pathinfo($file_path, PATHINFO_EXTENSION);

        if($type=='webp')
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public static function is_avif_image($image_id)
    {
        $file_path = get_attached_file($image_id);
        $type=pathinfo($file_path, PATHINFO_EXTENSION);

        if($type=='avif')
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public static function update_image_failed($image_id,$error)
    {
        $image_optimize_meta=get_post_meta( $image_id, 'aero_io_image_meta', true );

        $image_optimize_meta['status']='failed';
        $image_optimize_meta['size']['og']['status']='failed';
        $image_optimize_meta['size']['og']['error']=$error;

        self::update_image_meta_status($image_id,'failed');
        self::update_images_meta($image_id,$image_optimize_meta);
    }
}