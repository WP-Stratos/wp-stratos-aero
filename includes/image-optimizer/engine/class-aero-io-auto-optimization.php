<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Aero_IO_Auto_Optimization
{
    public $auto_opt_ids;
    public $log=false;

    public function __construct()
    {
        $this->auto_opt_ids=array();

        add_action( 'add_attachment',                  array( $this, 'add_auto_opt_id' ), 1000 );
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'update_auto_opt_id_status' ), 1000, 2 );
        add_filter( 'wp_update_attachment_metadata',   array( $this, 'auto_optimize' ), 1000, 2 );

        add_filter( 'aero_io_allowed_image_auto_optimization',   array( $this, 'allowed_image_auto_optimization' ), 10 );
    }

    public function allowed_image_auto_optimization()
    {
        $is_auto=Aero_IO_Options::get_option('aero_io_auto_optimize',false);

        if($is_auto)
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public function add_auto_opt_id($attachment_id)
    {
        $is_auto=apply_filters('aero_io_allowed_image_auto_optimization',false);

        if($is_auto)
        {
            $this->auto_opt_ids[$attachment_id]=0;
        }
    }

    public function update_auto_opt_id_status($metadata, $attachment_id)
    {
        if(isset( $this->auto_opt_ids[$attachment_id]))
        {
            if ( ! wp_attachment_is_image( $attachment_id ) )
            {
                unset($this->auto_opt_ids[$attachment_id]);
            }
            else
            {
                $this->WriteLog('Add attachment images id:'.$attachment_id,'notice');
                $this->auto_opt_ids[$attachment_id]=1;
            }
        }

        return $metadata;
    }

    public function auto_optimize($metadata, $attachment_id)
    {
        $is_auto=apply_filters('aero_io_allowed_image_auto_optimization',false);

        if($is_auto)
        {
            if(isset($this->auto_opt_ids[$attachment_id])&&$this->auto_opt_ids[$attachment_id])
            {
                $supported_mime_types = array(
                    "image/jpg",
                    "image/jpeg",
                    "image/png",
                    "image/webp",
                    "image/avif");

                $mime_type=get_post_mime_type($attachment_id);
                if(in_array($mime_type,$supported_mime_types))
                {
                    if($this->is_excludes($attachment_id))
                    {
                        $this->WriteLog('Exclude attachment images id:'.$attachment_id,'notice');
                        return $metadata;
                    }

                    set_time_limit(300);
                    delete_transient('aero_io_set_global_stats');
                    $ret=$this->do_optimize_image($attachment_id,$metadata);
                    if(isset($ret['meta']))
                    {
                        return $ret['meta'];
                    }
                }
            }
        }

        return $metadata;
    }

    public function is_excludes($attachment_id)
    {
        $exclude_regex_folder=Aero_IO_Options::get_excludes();

        if(Aero_IO_Image_Opt_Method::exclude_path($attachment_id,$exclude_regex_folder))
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public function WriteLog($log,$type)
    {
        if (is_a($this->log, 'Aero_IO_Log'))
        {
            $this->log->WriteLog($log,$type);
        }
        else
        {
            $this->log=new Aero_IO_Log();
            $this->log->OpenLogFile();
            $this->log->WriteLog($log,$type);
        }
    }

    public function do_optimize_image($attachment_id,$metadata)
    {
        $general_options=Aero_IO_Options::get_general_settings();
        $quality_options=Aero_IO_Options::get_quality_option();
        $options=array_merge($general_options,$quality_options);

        $this->log=new Aero_IO_Log();
        $this->log->CreateLogFile();

        $this->WriteLog('Start optimizing new media images id:'.$attachment_id,'notice');

        $file_path = get_attached_file( $attachment_id );
        if(empty($file_path))
        {
            Aero_IO_Image_Opt_Method::WriteLog($this->log,'Image:'.$attachment_id.' failed. Error: failed to get get_attached_file','notice');
            $error='Image:'.$attachment_id.' failed. Error: failed to get get_attached_file';
            Aero_IO_Image_Meta_V2::update_image_failed($attachment_id,$error);
            $ret['result']='success';
            $ret['meta']=$metadata;
            return $ret;
        }

        if(!file_exists($file_path))
        {
            Aero_IO_Image_Opt_Method::WriteLog($this->log,'Image:'.$attachment_id.' failed. Error: file not exists '.$file_path,'notice');

            $error='Image:'.$attachment_id.' failed. Error: file not exists '.$file_path;
            Aero_IO_Image_Meta_V2::update_image_failed($attachment_id,$error);
            $ret['result']='success';
            $ret['meta']=$metadata;
            return $ret;
        }

        Aero_IO_Image_Meta_V2::update_image_progressing($attachment_id);

        $image=new Aero_IO_Image($attachment_id,$options);

        $ret=$image->resize_ex($metadata);
        if($ret['result']=='success')
        {
            $metadata=$ret['meta'];
        }

        if($image->convert())
        {
            Aero_IO_Image_Meta_V2::update_image_meta_status($attachment_id,'optimized');
            do_action('aero_io_uploading_add_watermark',$attachment_id);
            do_action('aero_io_after_optimize_image',$attachment_id);
        }
        else
        {
            Aero_IO_Image_Meta_V2::update_image_meta_status($attachment_id,'failed');
        }

        Aero_IO_Image_Meta_V2::delete_image_progressing($attachment_id);

        $ret['result']='success';
        $ret['meta']=$metadata;
        return $ret;
    }
}