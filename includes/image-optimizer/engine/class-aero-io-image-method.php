<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Aero_IO_Image_Method
{
    public static function get_max_image_count()
    {
        global $wpdb;

        $supported_mime_types = array(
            "image/jpg",
            "image/jpeg",
            "image/png",
            "image/webp",
            "image/avif");

        $args  = $supported_mime_types;
        $result=$wpdb->get_results($wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type IN (%s,%s,%s,%s,%s)", $args ),ARRAY_N);

        if($result && sizeof($result)>0)
        {
            return $result[0][0];
        }
        else
        {
            return 0;
        }
    }

    public static function get_convert_to_webp()
    {
        $converter_method=Aero_IO_Options::get_option('aero_io_converter_method',false);
        if(empty($converter_method))
        {
            $converter_method=Aero_IO_Options::set_default_compress_server();
        }

        $convert_to_webp=Aero_IO_Options::get_option('aero_io_output_format_webp','not init');
        if($convert_to_webp=='not init')
        {
            $convert_to_webp=Aero_IO_Options::set_default_output_format_webp();
        }

        if($converter_method=='gd')
        {
            if($convert_to_webp&&self::is_support_gd_webp())
            {
                return true;
            }
            else
            {
                return false;
            }
        }
        else
        {
            if($convert_to_webp&&self::is_support_imagick_webp())
            {
                return true;
            }
            else
            {
                return false;
            }
        }
    }

    public static function get_convert_to_avif()
    {
        $converter_method=Aero_IO_Options::get_option('aero_io_converter_method',false);
        if(empty($converter_method))
        {
            $converter_method=Aero_IO_Options::set_default_compress_server();
        }

        $convert_to_avif=Aero_IO_Options::get_option('aero_io_output_format_avif','not init');
        if($convert_to_avif=='not init')
        {
            $convert_to_avif=Aero_IO_Options::set_default_output_format_avif();
        }

        if($converter_method=='gd')
        {
            if($convert_to_avif&&self::is_support_gd_avif())
            {
                return true;
            }
            else
            {
                return false;
            }
        }
        else
        {
            if($convert_to_avif&&self::is_support_imagick_avif())
            {
                return true;
            }
            else
            {
                return false;
            }
        }
    }

    public static function get_compress_to_webp()
    {
        $converter_method=Aero_IO_Options::get_option('aero_io_converter_method',false);
        if(empty($converter_method))
        {
            $converter_method=Aero_IO_Options::set_default_compress_server();
        }

        $convert_to_webp=Aero_IO_Options::get_option('aero_io_output_format_webp','not init');
        if($convert_to_webp=='not init')
        {
            $convert_to_webp=Aero_IO_Options::set_default_output_format_webp();
        }

        if($converter_method=='gd')
        {
            if($convert_to_webp&&self::is_support_gd_webp())
            {
                return true;
            }
            else
            {
                return false;
            }
        }
        else
        {
            if($convert_to_webp&&self::is_support_imagick_webp())
            {
                return true;
            }
            else
            {
                return false;
            }
        }
    }

    public static function get_compress_to_avif()
    {
        $converter_method=Aero_IO_Options::get_option('aero_io_converter_method',false);
        if(empty($converter_method))
        {
            $converter_method=Aero_IO_Options::set_default_compress_server();
        }

        $convert_to_avif=Aero_IO_Options::get_option('aero_io_output_format_avif','not init');
        if($convert_to_avif=='not init')
        {
            $convert_to_avif=Aero_IO_Options::set_default_output_format_avif();
        }

        if($converter_method=='gd')
        {
            if($convert_to_avif&&self::is_support_gd_avif())
            {
                return true;
            }
            else
            {
                return false;
            }
        }
        else
        {
            if($convert_to_avif&&self::is_support_imagick_avif())
            {
                return true;
            }
            else
            {
                return false;
            }
        }
    }

    public static function get_converter_method()
    {
        $converter_method=Aero_IO_Options::get_option('aero_io_converter_method',false);
        if(empty($converter_method))
        {
            $converter_method=Aero_IO_Options::set_default_compress_server();
        }

        return $converter_method;
    }

    public static function get_max_webp_image_count()
    {
        global $wpdb;

        $supported_mime_types = array(
            "image/jpg",
            "image/jpeg",
            "image/png");

        //$supported_mime_types=apply_filters('aero_io_supported_mime_types',$supported_mime_types);

        $result=$wpdb->get_results( $wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type IN (%s,%s,%s) ",$supported_mime_types),ARRAY_N);
        if($result && sizeof($result)>0)
        {
            return $result[0][0];
        }
        else
        {
            return 0;
        }
    }

    public static function get_max_avif_image_count()
    {
        global $wpdb;

        $supported_mime_types = array(
            "image/jpg",
            "image/jpeg",
            "image/png",
            "image/webp");

        //$supported_mime_types=apply_filters('aero_io_supported_mime_types',$supported_mime_types);

        $result=$wpdb->get_results($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type IN (%s,%s,%s,%s) ",$supported_mime_types),ARRAY_N);
        if($result && sizeof($result)>0)
        {
            return $result[0][0];
        }
        else
        {
            return 0;
        }
    }

    public static function is_support_webp()
    {
        if( function_exists( 'gd_info' ) && function_exists( 'imagewebp' )  )
        {
            return true;
        }

        if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) )
        {
            if( \Imagick::queryformats( 'WEBP' ))
            {
                return true;
            }
        }

        return false;
    }

    public static function is_support_avif()
    {
        if( function_exists( 'gd_info' ) )
        {
            $info=gd_info();
            if(isset($info["AVIF Support"])&&$info["AVIF Support"])
            {
                return true;
            }
        }

        if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) )
        {
            if( \Imagick::queryformats( 'AVIF' ))
            {
                return true;
            }
        }

        return false;
    }

    public static function is_support_gd()
    {
        if( function_exists( 'gd_info' ) )
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public static function is_support_gd_webp()
    {
        if( function_exists( 'gd_info' ) && function_exists( 'imagewebp' )  )
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public static function is_support_gd_avif()
    {
        if( function_exists( 'gd_info' ) && function_exists( 'imageavif' ))
        {
            $info=gd_info();
            if(isset($info["AVIF Support"])&&$info["AVIF Support"])
            {
                return true;
            }
        }

        return false;
    }

    public static function is_support_imagick()
    {
        if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) )
        {
            if( \Imagick::queryformats( 'WEBP' ))
            {
                return true;
            }
        }

        return false;
    }

    public static function is_support_imagick_webp()
    {
        if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) )
        {
            if( \Imagick::queryformats( 'WEBP' ))
            {
                return true;
            }
        }

        return false;
    }

    public static function is_support_imagick_avif()
    {
        if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) )
        {
            if( \Imagick::queryformats( 'AVIF' ))
            {
                return true;
            }
        }

        return false;
    }

    public static function is_exclude_avif($image_id,$options)
    {
        $file_path = get_attached_file( $image_id );
        $type=pathinfo($file_path, PATHINFO_EXTENSION);

        if($options['exclude_png']&&$type== 'png')
        {
            return true;
        }
        else if($options['exclude_jpg_avif']&&$type== 'jpg')
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public static function is_exclude_webp($image_id,$options)
    {
        $file_path = get_attached_file( $image_id );
        $type=pathinfo($file_path, PATHINFO_EXTENSION);

        if($options['exclude_png_webp']&&$type== 'png')
        {
            return true;
        }
        else if($options['exclude_jpg_webp']&&$type== 'jpg')
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public static function transfer_path($path)
    {
        $path = str_replace('\\','/',$path);
        $values = explode('/',$path);
        return implode(DIRECTORY_SEPARATOR,$values);
    }

    public static function need_convert_to_webp($post_id,$exclude_regex_folder)
    {
        if(!Aero_IO_Image_Meta_V2::get_image_meta_webp_converted($post_id))
        {
            if(!Aero_IO_Image_Method::exclude_path($post_id,$exclude_regex_folder))
            {
                return true;
            }
            else
            {
                return false;
            }
        }
        else
        {
            return false;
        }
    }

    public static function need_convert_to_avif($post_id,$exclude_regex_folder)
    {
        if(!Aero_IO_Image_Meta_V2::get_image_meta_avif_converted($post_id))
        {
            if(!Aero_IO_Image_Method::exclude_path($post_id,$exclude_regex_folder))
            {
                return true;
            }
            else
            {
                return false;
            }
        }
        else
        {
            return false;
        }
    }

    public static function exclude_path($post_id,$exclude_regex_folder)
    {
        if(empty($exclude_regex_folder))
        {
            return false;
        }

        $file_path = get_attached_file( $post_id );
        $file_path = Aero_IO_Image_Method::transfer_path($file_path);
        if (Aero_IO_Image_Method::regex_match($exclude_regex_folder, $file_path))
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public static function regex_match($regex_array,$string)
    {
        if(empty($regex_array))
        {
            return false;
        }

        foreach ($regex_array as $regex)
        {
            if(preg_match($regex,$string))
            {
                return true;
            }
        }

        return false;
    }

    public static function get_opt_folder_size()
    {
        try
        {
            $aero_io_path=WP_CONTENT_DIR."/aero-nextgen/uploads";
            $bytestotal = 0;
            $path = realpath($aero_io_path);
            if($path!==false && $path!='' && file_exists($path))
            {
                foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $object){
                    $bytestotal += $object->getSize();
                }
            }
            return $bytestotal;
        }
        catch (Exception $e)
        {
            return 0;
        }
    }

    public static function supported_mime_types_ex()
    {
        $supported_mime_types = array(
            "image/jpg",
            "image/jpeg",
            "image/png",
            "image/webp",
            "image/avif");

        $supported_mime_types=apply_filters('aero_io_supported_mime_types',$supported_mime_types);

        return $supported_mime_types;
    }

    public static function delete_image($image_id)
    {
        $files=array();
        $file_path = get_attached_file( $image_id );
        $meta = wp_get_attachment_metadata( $image_id, true );

        if ( ! empty( $meta['sizes'] ) )
        {
            foreach ( $meta['sizes'] as $size_key => $size_data )
            {
                $filename= path_join( dirname( $file_path ), $size_data['file'] );
                $files[$size_key] =$filename;
            }

            if(!in_array($file_path,$files))
            {
                $files['og']=$file_path;
            }
        }
        else
        {
            $files['og']=$file_path;
        }

        foreach ($files as $size_key=>$file)
        {
            $file=self::get_output_path($file);
            $webp_file =$file.'.webp';
            $avif_file =$file.'.avif';
            if(file_exists($webp_file))
                @wp_delete_file($webp_file);

            if(file_exists($avif_file))
                @wp_delete_file($avif_file);

            if(file_exists($file))
                @wp_delete_file($file);

        }

        delete_transient('aero_io_set_global_stats');
        Aero_IO_Image_Meta_V2::delete_image_meta($image_id);
    }

    public static function get_output_path($og_path)
    {
        $aero_io_path=WP_CONTENT_DIR."/aero-nextgen/uploads";

        if(is_multisite())
        {
            $tmp_id=get_current_blog_id();
            $main_site_id = get_main_site_id();
            switch_to_blog($main_site_id);
            $upload_dir = wp_get_upload_dir();
            switch_to_blog($tmp_id);
        }
        else
        {
            $upload_dir = wp_get_upload_dir();
        }

        $upload_root=self::transfer_path($upload_dir['basedir']);
        $attachment_dir=dirname($og_path);
        $attachment_dir=self::transfer_path($attachment_dir);
        $sub_dir=str_replace($upload_root,'',$attachment_dir);
        $sub_dir=untrailingslashit($sub_dir);
        $sub_dir=ltrim( $sub_dir, '/\\' );
        $real_path=$aero_io_path.'/'.$sub_dir;

        if(!file_exists($real_path))
        {
            wp_mkdir_p($real_path);
        }

        return $real_path.'/'.basename($og_path);
    }

    //exclude_path_ex
    public static function exclude_path_ex($file_path,$exclude_regex_folder)
    {
        if(empty($exclude_regex_folder))
        {
            return false;
        }

        $file_path = Aero_IO_Image_Method::transfer_path($file_path);
        if (Aero_IO_Image_Method::regex_match($exclude_regex_folder, $file_path))
        {
            return true;
        }
        else
        {
            return false;
        }
    }
}