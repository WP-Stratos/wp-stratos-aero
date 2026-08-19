<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Aero_IO_Custom_ImgOptim_Task
{
    public $task;
    public $log=false;

    public function __construct()
    {
        $this->get_task();
        Aero_IO_Custom_Image_Meta::check_custom_table();
    }

    public function update_task()
    {
        Aero_IO_Options::update_option('aero_io_custom_image_opt_task',$this->task);
        $this->get_task();
    }

    public function get_task()
    {
        $this->task=Aero_IO_Options::get_option('aero_io_custom_image_opt_task',array());
    }

    public function init_task($force=false)
    {
        $this->task=array();

        $this->task['log']=uniqid('aero-io-');
        $this->log=new Aero_IO_Log();
        $this->log->CreateLogFile();

        $this->init_options();
        $this->task['options']['force']=$force;

        $this->task['images']=$this->get_need_optimize_images();

        if(empty($this->task['images']))
        {
            $ret['result']='failed';
            $ret['error']=__('No unoptimized images found.','aero');
            $this->update_task();
            return $ret;
        }

        $this->task['status']='init';
        $this->task['last_update_time']=time();
        $this->task['retry']=0;

        $this->task['opt_images']=0;
        $this->task['failed_images']=0;

        $this->task['error']='';
        $this->update_task();

        $ret['result']='success';
        $ret["test"]=$this->task;
        return $ret;
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

    public function init_options()
    {
        $general_options=Aero_IO_Options::get_general_settings();
        $quality_options=Aero_IO_Options::get_quality_option();
        $this->task['options']=array_merge($general_options,$quality_options);

    }

    public function get_need_optimize_images()
    {
        $images=Aero_IO_Options::get_option("aero_io_need_optimized_custom_images",array());
        $need_optimize_images=array();
        $index=0;
        foreach ($images as $path)
        {
            $image['id']=$index;
            $image['finished']=1;
            $image['path']=$path;
            $image['force']=0;
            $status=Aero_IO_Custom_Image_Meta::get_image_status($path);

            if($this->task['options']['convert_to_webp'])
            {
                $image['convert_webp_status']=$status['convert_webp_status'];
                $image['finished']=0;
            }

            if($this->task['options']['convert_to_avif'])
            {
                $image['convert_avif_status']=$status['convert_avif_status'];
                $image['finished']=0;
            }

            if($image['finished']==0)
            {
                $need_optimize_images[$index]=$image;
                $index++;
            }
        }
        return $need_optimize_images;
    }

    public function get_task_status()
    {
        if(empty($this->task))
        {
            $ret['result']='failed';
            $ret['error']=__('All image(s) optimized successfully.','aero');
            return $ret;
        }

        if($this->task['status']=='error')
        {
            $ret['result']='failed';
            $ret['error']=$this->task['error'];
        }
        else if($this->task['status']=='completed')
        {
            $ret['result']='success';
            $ret['status']='completed';
        }
        else if($this->task['status']=='finished')
        {
            $ret['result']='success';
            $ret['status']='finished';
        }
        else if($this->task['status']=='timeout')
        {
            $ret['result']='success';
            $ret['status']='completed';
        }
        else if($this->task['status']=='init')
        {
            $ret['result']='success';
            $ret['status']='completed';
        }
        else
        {
            $ret['result']='success';
            $ret['status']='running';
        }
        return $ret;
    }

    public function do_optimize_image()
    {
        $this->task['status']='running';
        $this->task['last_update_time']=time();
        $this->update_task();

        if(empty($this->task)||!isset($this->task['images']))
        {
            $ret['result']='success';
            return $ret;
        }

        $image_id=false;
        $need_reset=false;
        foreach ($this->task['images'] as $image)
        {
            if($image['finished']==0)
            {
                $image_id=$image['id'];
                if($this->task['options']['force']&&$image['force']==0)
                {
                    $need_reset=true;
                }
                break;
            }
        }

        if($image_id===false)
        {
            $ret['result']='success';

            $this->task['status']='finished';
            $this->task['last_update_time']=time();
            $this->update_task();
            return $ret;
        }

        if($need_reset)
        {
            $ret=$this->reset_optimize_image($image_id);
            if($ret['result']=='success')
            {
                $this->task['images'][$image_id]['force']=1;
                $this->update_task();
            }
        }

        $this->WriteLog('Start optimizing custom images id:'.$image_id,'notice');
        $ret=$this->optimize_image($image_id);

        if($ret['result']=='success')
        {
            $this->WriteLog('Optimizing custom image id:'.$image_id.' succeeded.','notice');
            $this->task['images'][$image_id]['finished']=1;
            $this->task['status']='completed';
            $this->task['last_update_time']=time();
            $this->task['retry']=0;
            $this->update_task();

            $ret['result']='success';
            $ret['test']=$this->task;
            return $ret;
        }
        else
        {
            $this->WriteLog('Optimizing images failed. Error:'.$ret['error'],'error');

            $this->task['status']='error';
            $this->task['error']=$ret['error'];
            $this->task['last_update_time']=time();
            $this->update_task();
            return $ret;
        }
    }

    public function reset_optimize_image($image_id)
    {
        $filename=$this->get_image_path($image_id);
        Aero_IO_Custom_Image_Meta::generate_custom_image_meta($filename,$this->task['options']);

        $ret['result']='success';
        return $ret;
    }

    public function optimize_image($image_id)
    {
        if (is_a($this->log, 'Aero_IO_Log'))
        {
            //
        }
        else
        {
            $this->log=new Aero_IO_Log();
            $this->log->OpenLogFile();
        }

        $has_error=false;
        $filename=$this->get_image_path($image_id);
        $this->get_custom_image_meta($filename);

        if(Aero_IO_Image_Opt_Method::custom_convert_to_webp($filename,$this->task['options'],$this->log)===false)
        {
            $has_error=true;
        }

        if(Aero_IO_Image_Opt_Method::custom_convert_to_avif($filename,$this->task['options'],$this->log)===false)
        {
            $has_error=true;
        }

        if($has_error)
        {
            $this->task['failed_images']++;
            $this->update_task();        }
        else
        {
            $this->task['opt_images']++;
            $this->update_task();
        }


        $ret['result']='success';
        return $ret;
    }

    public function get_image_path($image_id)
    {
        $image=$this->task['images'][$image_id];
        return Aero_IO_Image_Method::transfer_path($image['path']);
    }

    public function get_custom_image_meta($filename)
    {
        $filename=Aero_IO_Image_Method::transfer_path($filename);
        $meta=Aero_IO_Custom_Image_Meta::get_custom_image_meta($filename);
        if(empty($meta))
        {
            $meta=Aero_IO_Custom_Image_Meta::generate_custom_image_meta($filename,$this->task['options']);
        }

        return $meta;
    }

    public function get_task_progress()
    {
        $this->get_task();

        if(empty($this->task))
        {
            $ret['result']='failed';
            $ret['error']=__('All image(s) optimized successfully.','aero');
            $ret['percent']=0;
            $ret['timeout']=0;
            $ret['log']=__('All image(s) optimized successfully.','aero');
            return $ret;
        }

        if(isset($this->task['images']))
        {
            $ret['total_images']=sizeof($this->task['images']);
        }
        else
        {
            $ret['total_images']=0;
        }

        $ret['optimized_images']=0;
        if(isset($this->task['images']))
        {
            foreach ($this->task['images'] as $image)
            {
                if($image['finished'])
                {
                    $ret['optimized_images']++;
                }
            }
        }

        $percent= intval(($ret['optimized_images']/$ret['total_images'])*100);

        $ret['log']=sprintf(
            '%1$d images found | Processed:%2$d/%3$d (%4$d%%)',
        $ret['total_images'],$ret['optimized_images'],$ret['total_images'],$percent);

        if(isset($this->task['status']))
        {
            if($this->task['status']=='error')
            {
                $ret['result']='failed';
                $ret['error']=$this->task['error'];
                $ret['timeout']=0;
                $ret['percent']= intval(($ret['optimized_images']/$ret['total_images'])*100);
                $ret['log']=$this->task['error'];
            }
            else if($this->task['status']=='finished')
            {
                $ret['result']='success';
                $ret['continue']=0;
                $ret['finished']=1;
                $ret['timeout']=0;
                $ret['percent']= 100;
                $ret['log']=__('Finish Optimizing images.','aero');

                $dismiss=Aero_IO_Options::get_option('aero_io_rating_dismiss',false);
                if($dismiss===false)
                {
                    $ret['show_review']=1;
                }
                else if($dismiss==0)
                {
                    $ret['show_review']=0;
                }
                else if($dismiss<time())
                {
                    $ret['show_review']=1;
                }
                else
                {
                    $ret['show_review']=0;
                }

                if($ret['show_review']==1)
                {
                    if (Aero_IO_Options::get_option('aero_io_show_review', false) === false)
                    {
                        Aero_IO_Options::update_option('aero_io_show_review', time());
                    }
                    delete_transient('aero_io_set_global_stats');
                    $size=Aero_IO_Image_Method::get_opt_folder_size();
                    $ret['opt_size']=size_format($size,2);
                }
            }
            else if($this->task['status']=='completed')
            {
                $ret['result']='success';
                $ret['continue']=0;
                $ret['finished']=0;
                $ret['timeout']=0;
                $ret['percent']= intval(($ret['optimized_images']/$ret['total_images'])*100);
            }
            else
            {
                if(isset($this->task['last_update_time']))
                {
                    if(time()-$this->task['last_update_time']>180)
                    {
                        $this->task['last_update_time']=time();
                        $this->task['retry']++;
                        $this->task['status']='timeout';
                        $this->update_task();
                        if($this->task['retry']<3)
                        {
                            $ret['timeout']=1;
                        }
                        else
                        {
                            $ret['timeout']=0;
                            $this->task=array();
                            $this->update_task();
                        }

                        $ret['result']='failed';
                        $ret['error']=__('Task timed out','aero');
                        $ret['percent']=0;
                        $ret['retry']=$this->task['retry'];
                        $ret['log']='task time out';
                    }
                    else
                    {
                        $ret['continue']=1;
                        $ret['finished']=0;
                        $ret['timeout']=0;
                        $ret['running_time']=time()-$this->task['last_update_time'];
                        $ret['result']='success';
                        $ret['percent']= intval(($ret['optimized_images']/$ret['total_images'])*100);
                    }
                }
                else
                {
                    $ret['result']='failed';
                    $ret['error']='Not start task';
                    $ret['timeout']=0;
                    $ret['percent']=0;
                    $ret['log']='Not start task';
                }
            }
        }
        else
        {
            $ret['result']='failed';
            $ret['error']='Not start task';
            $ret['timeout']=0;
            $ret['percent']=0;
            $ret['log']='Not start task';
        }

        return $ret;
    }
}