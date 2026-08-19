<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Aero_IO_Image
{
    public $image_id;
    public $options;
    public $log;
    public function __construct($image_id,$options=array())
    {
        $this->image_id=$image_id;
        $this->options=$options;

        $this->log = Aero_IO_Log_Ex::get_instance();

        if(!Aero_IO_Image_Meta_V2::has_image_meta($this->image_id))
        {
            Aero_IO_Image_Meta_V2::generate_images_meta($this->image_id, $this->options);
        }
    }

    public function resize()
    {
        if(Aero_IO_Image_Meta_V2::get_image_meta_value($this->image_id,'resize_status')==0)
        {
            if(Aero_IO_Image_Opt_Method::resize($this->image_id,$this->options,$this->log))
            {
                Aero_IO_Image_Meta_V2::update_images_meta_value($this->image_id,'resize_status',1);
            }
        }
    }

    public function resize_ex($metadata)
    {
        if(Aero_IO_Image_Meta_V2::get_image_meta_value($this->image_id,'resize_status')==0)
        {
            $ret=Aero_IO_Image_Opt_Method::resize_ex($this->image_id,$this->options,$metadata,$this->log);
            if($ret['result']=='success')
            {
                Aero_IO_Image_Meta_V2::update_images_meta_value($this->image_id,'resize_status',1);
                return $ret;
            }
        }

        $ret['result']='success';
        $ret['meta']=$metadata;
        return $ret;
    }

    public function convert()
    {
        $has_error=false;

        if($this->convert_to_webp()===false)
        {
            $has_error=true;
        }

        if($this->convert_to_avif()===false)
        {
            $has_error=true;
        }

        return !$has_error;
    }

    public function convert_to_webp()
    {
        if(Aero_IO_Image_Method::is_exclude_webp($this->image_id,$this->options))
        {
            return true;
        }
        if(!$this->need_convert_to_webp())
        {
            return true;
        }

        if(Aero_IO_Image_Meta_V2::get_image_meta_webp_converted($this->image_id)==1)
        {
            return true;
        }

        $success=true;
        $has_convert=true;

        $size =Aero_IO_Image_Meta_V2::get_image_meta_value($this->image_id,'size');
        $uploads=wp_get_upload_dir();
        $file_path = get_attached_file( $this->image_id );
        $type=pathinfo($file_path, PATHINFO_EXTENSION);

        foreach ($size as $size_key=>$size_meta)
        {
            if($size_meta['convert_webp_status']==1||$this->skip_size($size_key))
            {
                continue;
            }

            if($size_key=="og")
            {
                $filename= $file_path;
            }
            else if(Aero_IO_Image_Opt_Method::is_elementor_thumbs($size_key))
            {
                $filename = $uploads['basedir'].'/'.$size_meta['file'];
            }
            else
            {
                $filename= path_join( dirname( $file_path ), $size_meta['file'] );
            }

            Aero_IO_Image_Opt_Method::WriteLog($this->log,'Start WebP conversion '.basename($filename),'notice');

            if(!file_exists($filename))
            {
                Aero_IO_Image_Opt_Method::WriteLog($this->log,'File '.basename($filename).' not exist,so skip convert.','notice');
                $size_meta['convert_webp_status']=1;
                $size_meta['status']='optimized';
                Aero_IO_Image_Meta_V2::update_image_meta_size($this->image_id,$size_key,$size_meta);
                continue;
            }

            $nextgen_path=Aero_IO_Image_Opt_Method::get_output_path($filename);
            if($type=="webp")
            {
                $des_filename=$nextgen_path;
            }
            else
            {
                $des_filename=$nextgen_path.'.webp';
            }

            $ret=$this->_convert_webp($filename,$des_filename);

            if($ret['result']=='success')
            {
                $webp_converted_size=filesize($des_filename);
                $file_size=filesize($filename);
                if($this->options['auto_remove_larger_format']&&$webp_converted_size>$file_size)
                {
                    @wp_delete_file($des_filename);
                    Aero_IO_Image_Opt_Method::WriteLog($this->log,'WebP size larger than original size, deleting file '.basename($des_filename).'.','notice');
                    $size_meta['webp_converted_size']=$file_size;
                }
                else
                {
                    $size_meta['webp_converted_size']=$webp_converted_size;
                }
                $size_meta['file_size']=$file_size;
                $size_meta['convert_webp_status']=1;
                $size_meta['status']='optimized';
                Aero_IO_Image_Meta_V2::update_image_meta_size($this->image_id,$size_key,$size_meta);
            }
            else
            {
                $size_meta['convert_webp_status']=0;
                $size_meta['status']='failed';
                $size_meta['error']=$ret['error'];
                Aero_IO_Image_Meta_V2::update_image_meta_size($this->image_id,$size_key,$size_meta);

                $success=false;
                $has_convert=false;
            }
        }

        if($has_convert)
        {
            Aero_IO_Image_Meta_V2::update_webp_image_converted($this->image_id);
            //Aero_IO_Image_Meta_V2::update_image_meta_webp_converted($this->image_id,1);
        }

        return $success;
    }

    public function convert_to_avif()
    {
        if(Aero_IO_Image_Method::is_exclude_avif($this->image_id,$this->options))
        {
            return true;
        }

        if(!$this->need_convert_to_avif())
        {
            return true;
        }

        if(Aero_IO_Image_Meta_V2::get_image_meta_avif_converted($this->image_id)==1)
        {
            return true;
        }

        $success=true;
        $has_convert=true;

        $size =Aero_IO_Image_Meta_V2::get_image_meta_value($this->image_id,'size');
        $uploads=wp_get_upload_dir();
        $file_path = get_attached_file( $this->image_id );
        $type=pathinfo($file_path, PATHINFO_EXTENSION);

        foreach ($size as $size_key=>$size_meta)
        {
            if($size_meta['convert_avif_status']==1||$this->skip_size($size_key))
            {
                continue;
            }

            if($size_key=="og")
            {
                $filename= $file_path;
            }
            else if(Aero_IO_Image_Opt_Method::is_elementor_thumbs($size_key))
            {
                $filename = $uploads['basedir'].'/'.$size_meta['file'];
            }
            else
            {
                $filename= path_join( dirname( $file_path ), $size_meta['file'] );
            }

            $nextgen_path=Aero_IO_Image_Opt_Method::get_output_path($filename);
            if($type=="avif")
            {
                $des_filename=$nextgen_path;
            }
            else
            {
                $des_filename=$nextgen_path.'.avif';
            }

            Aero_IO_Image_Opt_Method::WriteLog($this->log,'Start AVIF conversion:'.basename($filename),'notice');

            if(!file_exists($filename))
            {
                Aero_IO_Image_Opt_Method::WriteLog($this->log,'File '.basename($filename).' not exist,so skip convert.','notice');
                $size_meta['convert_avif_status']=1;
                $size_meta['status']='optimized';
                Aero_IO_Image_Meta_V2::update_image_meta_size($this->image_id,$size_key,$size_meta);

                continue;
            }

            $ret=$this->_convert_avif($filename,$des_filename);

            if($ret['result']=='success')
            {
                $avif_converted_size=filesize($des_filename);
                $file_size=filesize($filename);
                if($this->options['auto_remove_larger_format']&&$avif_converted_size>$file_size)
                {
                    @wp_delete_file($des_filename);
                    Aero_IO_Image_Opt_Method::WriteLog($this->log,'AVIF size larger than original size, deleting file '.basename($des_filename).'.','notice');
                    $size_meta['avif_converted_size']=$file_size;
                }
                else
                {
                    $size_meta['avif_converted_size']=$avif_converted_size;
                }
                $size_meta['file_size']=$file_size;
                $size_meta['convert_avif_status']=1;
                $size_meta['status']='optimized';
                Aero_IO_Image_Meta_V2::update_image_meta_size($this->image_id,$size_key,$size_meta);
            }
            else
            {
                $size_meta['convert_avif_status']=0;
                $size_meta['status']='failed';
                $size_meta['error']=$ret['error'];
                Aero_IO_Image_Meta_V2::update_image_meta_size($this->image_id,$size_key,$size_meta);

                $success=false;
                $has_convert=false;
            }
        }

        if($has_convert)
        {
            //Aero_IO_Image_Meta_V2::update_image_meta_avif_converted($this->image_id,1);
            Aero_IO_Image_Meta_V2::update_avif_image_converted($this->image_id);
        }

        return $success;
    }

    public function _convert_webp($filename,$des_filename)
    {
        if($this->options['converter_method']=='gd')
        {
            $ret=Aero_IO_Image_Opt_Method::convert_webp_gd_ex($filename,$des_filename,$this->options);
        }
        else if($this->options['converter_method']=='imagick')
        {
            $ret=Aero_IO_Image_Opt_Method::convert_webp_imagick_ex($filename,$des_filename,$this->options);
        }
        else
        {
            $ret=Aero_IO_Image_Opt_Method::convert_webp_gd_ex($filename,$des_filename,$this->options);
        }

        if($ret['result']=='success')
        {
            Aero_IO_Image_Opt_Method::WriteLog($this->log,'Converting '.basename($filename).' to WebP succeeded.','notice');
        }
        else
        {
            Aero_IO_Image_Opt_Method::WriteLog($this->log,'Converting '.basename($filename).' to WebP failed. Error:'.$ret['error'],'notice');
        }
        return $ret;
    }

    public function _convert_avif($filename,$des_filename)
    {
        if($this->options['converter_method']=='gd')
        {
            $ret=Aero_IO_Image_Opt_Method::convert_avif_gd_ex($filename,$des_filename,$this->options);
        }
        else if($this->options['converter_method']=='imagick')
        {
            $ret=Aero_IO_Image_Opt_Method::convert_avif_imagick_ex($filename,$des_filename,$this->options);
        }
        else
        {
            $ret=Aero_IO_Image_Opt_Method::convert_avif_gd_ex($filename,$des_filename,$this->options);
        }

        if($ret['result']=='success')
        {
            Aero_IO_Image_Opt_Method::WriteLog($this->log,'Converting '.basename($filename).' to AVIF succeeded.','notice');
        }
        else
        {
            Aero_IO_Image_Opt_Method::WriteLog($this->log,'Converting '.basename($filename).' to AVIF failed. Error:'.$ret['error'],'notice');
        }

        return $ret;
    }

    public function need_compress()
    {
        $file_path = get_attached_file($this->image_id);
        $type=pathinfo($file_path, PATHINFO_EXTENSION);

        if($type=='webp')
        {
            return $this->options['compressed_webp'];
        }
        else if($type=='avif')
        {
            return $this->options['compressed_avif'];
        }

        return false;
    }

    public function skip_size($size_key)
    {
        if(isset($this->options['skip_size'])&&isset($this->options['skip_size'][$size_key]))
        {
            return $this->options['skip_size'][$size_key];
        }

        return false;
    }

    public function need_convert_to_webp_ex()
    {
        $file_path = get_attached_file($this->image_id);
        $type=pathinfo($file_path, PATHINFO_EXTENSION);

        if($type=='webp'||$type=='avif')
        {
            return false;
        }

        return $this->options['convert_to_webp'];
    }

    public function need_convert_to_avif_ex()
    {
        $file_path = get_attached_file($this->image_id);
        $type=pathinfo($file_path, PATHINFO_EXTENSION);

        if($type=='avif')
        {
            return false;
        }

        return $this->options['convert_to_avif'];
    }

    public function need_convert_to_webp()
    {
        $file_path = get_attached_file($this->image_id);
        $type=pathinfo($file_path, PATHINFO_EXTENSION);

        if($type=='avif')
        {
            return false;
        }

        return $this->options['convert_to_webp'];
    }

    public function need_convert_to_avif()
    {
        return $this->options['convert_to_avif'];
    }
}