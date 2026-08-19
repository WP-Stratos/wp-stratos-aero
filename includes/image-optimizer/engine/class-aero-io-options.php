<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Aero_IO_Options
{
    public static function get_option($option_name,$default_value=false)
    {
        if(is_multisite())
        {
            return get_blog_option(get_main_site_id(),$option_name,$default_value);
        }
        else
        {
            return get_option($option_name,$default_value);
        }
    }

    public static function update_option($option_name,$option_value)
    {
        if(is_multisite())
        {
            return update_blog_option(get_main_site_id(),$option_name,$option_value);
        }
        else
        {
            return update_option($option_name,$option_value,false);
        }
    }

    public static function delete_option($option_name)
    {
        if(is_multisite())
        {
            return delete_blog_option(get_main_site_id(),$option_name);
        }
        else
        {
            return delete_option($option_name);
        }
    }

    public static function get_general_settings()
    {
        $options=self::get_option('aero_io_general_settings',array());

        $general_options=array();
        $general_options['remove_exif']=isset($options['remove_exif'])?$options['remove_exif']:false;
        $general_options['auto_remove_larger_format']=isset($options['auto_remove_larger_format'])?$options['auto_remove_larger_format']:true;
        $general_options['converter_images_pre_request']=isset($options['converter_images_pre_request'])?$options['converter_images_pre_request']:5;
        $general_options['convert_to_webp']=Aero_IO_Image_Method::get_convert_to_webp();
        $general_options['convert_to_avif']=Aero_IO_Image_Method::get_convert_to_avif();
        $general_options['compressed_webp']=Aero_IO_Image_Method::get_compress_to_webp();
        $general_options['compressed_avif']=Aero_IO_Image_Method::get_compress_to_avif();
        $general_options['converter_method']=Aero_IO_Image_Method::get_converter_method();

        if(isset($options['resize']))
        {
            $general_options['resize_enable']= isset($options['resize']['enable'])?$options['resize']['enable']:true;
            $general_options['resize_width']=isset( $options['resize']['width'])? $options['resize']['width']:2560;
            $general_options['resize_height']=isset( $options['resize']['height'])? $options['resize']['height']:2560;
        }
        else
        {
            $general_options['resize_enable']= true;
            $general_options['resize_width']=2560;
            $general_options['resize_height']=2560;
        }

        $general_options['skip_size']=isset($options['skip_size'])?$options['skip_size']:array();
        $general_options['exclude_png']=isset($options['exclude_png'])?$options['exclude_png']:false;
        $general_options['exclude_png_webp']=isset($options['exclude_png_webp'])?$options['exclude_png_webp']:false;
        $general_options['exclude_jpg_avif']=isset($options['exclude_jpg_avif'])?$options['exclude_jpg_avif']:false;
        $general_options['exclude_jpg_webp']=isset($options['exclude_jpg_webp'])?$options['exclude_jpg_webp']:false;

        return $general_options;
    }

    public static function get_quality_option()
    {
        $quality_options=self::get_option('aero_io_quality',array());

        $quality_options['quality']=isset($quality_options['quality'])?$quality_options['quality']:'lossy';
        if($quality_options['quality']=="custom")
        {
            $quality_options['quality_webp']=isset($quality_options['quality_webp'])?$quality_options['quality_webp']: 80;
            $quality_options['quality_avif']=isset($quality_options['quality_avif'])?$quality_options['quality_avif']: 60;
        }

        return $quality_options;
    }

    public static function set_default_compress_server()
    {
        if( function_exists( 'gd_info' ) )
        {
            self::update_option('aero_io_converter_method','gd');
            return 'gd';
        }
        else if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) )
        {
            self::update_option('aero_io_converter_method','imagick');
            return 'imagick';
        }
        else
        {
            return false;
        }
    }

    public static function set_default_output_format_webp()
    {
        $converter_method=self::get_option('aero_io_converter_method',false);

        if(empty($converter_method))
        {
            $converter_method=self::set_default_compress_server();
        }

        if($converter_method=="gd")
        {
            if(Aero_IO_Image_Opt_Method::is_support_gd_webp())
            {
                self::update_option('aero_io_output_format_webp',1);
                return 1;
            }
            else
            {
                self::update_option('aero_io_output_format_webp',0);
                return 0;
            }
        }
        else if($converter_method=="imagick")
        {
            if(Aero_IO_Image_Opt_Method::is_support_imagick_webp())
            {
                self::update_option('aero_io_output_format_webp',1);
                return 1;
            }
            else
            {
                self::update_option('aero_io_output_format_webp',0);
                return 0;
            }
        }
        else
        {
            return false;
        }
    }

    public static function set_default_output_format_avif()
    {
        $converter_method=self::get_option('aero_io_converter_method',false);

        if(empty($converter_method))
        {
            $converter_method=self::set_default_compress_server();
        }

        if($converter_method=="gd")
        {
            if(Aero_IO_Image_Opt_Method::is_support_gd_avif())
            {
                self::update_option('aero_io_output_format_avif',1);
                return 1;
            }
            else
            {
                self::update_option('aero_io_output_format_avif',0);
                return 0;
            }
        }
        else if($converter_method=="imagick")
        {
            if(Aero_IO_Image_Opt_Method::is_support_imagick_avif())
            {
                if(Aero_IO_Image_Opt_Method::check_imagick_avif())
                {
                    self::update_option('aero_io_output_format_avif',1);
                    return 1;
                }
                else
                {
                    self::update_option('aero_io_output_format_avif',0);
                    return 0;
                }
            }
            else
            {
                self::update_option('aero_io_output_format_avif',0);
                return 0;
            }
        }
        else
        {
            return false;
        }
    }

    public static function get_excludes()
    {
        $excludes=self::get_option('aero_io_media_excludes',array());
        $exclude_regex_folder=array();
        if(!empty($excludes))
        {
            foreach ($excludes as $item)
            {
                $exclude_regex_folder[]='#'.preg_quote(Aero_IO_Image_Method::transfer_path($item), '/').'#';
            }
        }

        return $exclude_regex_folder;
    }

    public static function get_compress_quality($type,$options)
    {
        if($options['quality']=="custom")
        {
            if($type=='webp')
            {
                $quality=isset($options['quality_webp'])?$options['quality_webp']: 80;
            }
            else
            {
                $quality=isset($options['quality_avif'])?$options['quality_avif']: 60;
            }
        }
        else if($options['quality']=='lossless')
        {
            $quality=99;
        }
        else if($options['quality']=='lossy_minus')
        {
            $quality=90;
        }
        else if($options['quality']=='lossy')
        {
            $quality=80;
        }
        else if($options['quality']=='lossy_plus')
        {
            $quality=70;
        }
        else if($options['quality']=='lossy_super')
        {
            $quality=60;
        }
        else
        {
            $quality=80;
        }

        return $quality;
    }

    public static function get_webp_quality($options)
    {
        if(!isset($options['quality']))
        {
            return 80;
        }

        if($options['quality']=="custom")
        {
            $quality=isset($options['quality_webp'])?$options['quality_webp']: 80;
        }
        else if($options['quality']=='lossless')
        {
            $quality=99;
        }
        else if($options['quality']=='lossy_minus')
        {
            $quality=90;
        }
        else if($options['quality']=='lossy')
        {
            $quality=80;
        }
        else if($options['quality']=='lossy_plus')
        {
            $quality=70;
        }
        else if($options['quality']=='lossy_super')
        {
            $quality=60;
        }
        else
        {
            $quality=80;
        }

        return $quality;
    }

    public static function get_avif_quality($options)
    {
        if(!isset($options['quality']))
        {
            return 60;
        }

        if($options['quality']=="custom")
        {
            $quality=isset($options['quality_avif'])?$options['quality_avif']: 60;
        }
        else if($options['quality']=='lossless')
        {
            $quality=80;
        }
        else if($options['quality']=='lossy_minus')
        {
            $quality=75;
        }
        else if($options['quality']=='lossy')
        {
            $quality=60;
        }
        else if($options['quality']=='lossy_plus')
        {
            $quality=50;
        }
        else if($options['quality']=='lossy_super')
        {
            $quality=40;
        }
        else
        {
            $quality=60;
        }

        return $quality;
    }

    public static function get_default_advanced_woocommerce_quality_option()
    {
        $list= [
            // WooCommerce
            'wc_product_featured_images'   => ['enable'=>false,'webp' => 90, 'avif' => 65],
            'wc_product_gallery_images'    => ['enable'=>false,'webp' => 80, 'avif' => 55],
            'wc_variation_images'          => ['enable'=>false,'webp' => 80, 'avif' => 55],
            'wc_product_category_images'   => ['enable'=>false,'webp' => 75, 'avif' => 50],
        ];

        return $list;
    }

    public static function get_default_advanced_quality_option()
    {
        $list= [
            // core
            'site_logo'            => ['enable'=>false,'webp' => 90, 'avif' => 80],
            'header_background'    => ['enable'=>false,'webp' => 85, 'avif' => 60],
            'featured_post'        => ['enable'=>false,'webp' => 88, 'avif' => 62],
            'featured_page'        => ['enable'=>false,'webp' => 88, 'avif' => 62],
            'sidebar_widget'       => ['enable'=>false,'webp' => 75, 'avif' => 50],
            'acf_image_fields'      => ['enable'=>false,'webp' => 80, 'avif' => 55],
        ];

        return $list;
    }

    public static function get_interface_version()
    {
        return "v2";
        /*
        $options = self::get_option('aero_io_general_settings', array());
        if(empty($options))
        {
            return "v2";
        }

        return isset($options['interface_version']) ? $options['interface_version'] : 'v2';*/
    }
}