<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Aero_IO_Custom_Media_Lib
{
    public function __construct()
    {
        if(is_multisite())
            return;

        add_filter( 'manage_media_columns', array($this,'optimize_columns'));
        add_action( 'manage_media_custom_column', array($this, 'optimize_column_display'),10,2);
        add_action( 'attachment_submitbox_misc_actions',  array( $this,'submitbox') );
        add_filter( 'wp_prepare_attachment_for_js', array($this,'attachment_fields_to_edit'), 10, 2 );

        add_action('wp_ajax_aero_io_get_opt_single_image_progress', array($this, 'get_opt_single_image_progress'));

        //wp_prepare_attachment_for_js

        //add_action( 'restrict_manage_posts', array($this,'add_dropdown') );
        //add_action( 'pre_get_posts', array( $this, 'filter_posts' ) );
    }

    public function optimize_columns($defaults)
    {
        $defaults['aero'] = 'Aero Images';
        return $defaults;
    }

    public function optimize_column_display($column_name, $id )
    {
        if ( 'aero' === $column_name )
        {
            echo $this->optimize_action_columns( $id );
        }
    }

    public function optimize_action_columns($id)
    {
        $allowed_mime_types = Aero_IO_Image_Opt_Method::supported_mime_types_ex();

        if ( ! wp_attachment_is_image( $id ) || ! in_array( get_post_mime_type( $id ),$allowed_mime_types ) )
        {
            return __('Not support','aero');
        }

        $html=$this->output_item($id);
        return $html;
    }

    public function submitbox()
    {
        global $post;
        $id=$post->ID;

        $allowed_mime_types = Aero_IO_Image_Method::supported_mime_types_ex();

        if ( ! wp_attachment_is_image( $post->ID ) || ! in_array( get_post_mime_type( $post->ID ),$allowed_mime_types ) )
        {
            echo  esc_html__('Not support','aero');
            return;
        }

        if($this->is_skipped($id))
        {
            echo  esc_html__('Skipped','aero');
            return;
        }

        $html=$this->output_item_edit($id);
        echo $html;
    }

    public function attachment_fields_to_edit(array $response, \WP_Post $attachment)
    {
        $source_post_id = (string) isset( $_REQUEST['post_id'])?$_REQUEST['post_id']:'';
        if ( $source_post_id !== '0' )
        {
            return $response;
        }

        $allowed_mime_types = Aero_IO_Image_Method::supported_mime_types_ex();

        if ( ! wp_attachment_is_image( $attachment->ID ) || ! in_array( get_post_mime_type( $attachment->ID ),$allowed_mime_types ) )
        {
            return $response;
        }

        $html=$this->output_item_attachment($attachment->ID);
        $response['compat']['meta'] .=$html;

        return $response;
    }

    public function output_item($id)
    {
        if($this->is_skipped($id))
        {
            return __('Skipped','aero');
        }

        $html='<div class="cx-media-item" data-id="'.esc_attr($id).'">';
        $html.='<ul>';
        $html.=$this->output_item_detail($id);
        $html.=$this->output_item_action($id);
        $html.='</ul>';
        $html.='</div>';
        return $html;
    }

    public function output_item_edit($id)
    {
        if($this->is_skipped($id))
        {
            return __('Skipped','aero');
        }

        $html='<div class="misc-pub-section misc-pub-cx" data-id="'.esc_attr($id).'"><h4>' . esc_html__('Aero Images','aero') . '</h4>';
        $html.='<ul>';
        $html.=$this->output_item_detail($id);
        $html.=$this->output_item_action($id);
        $html.='</ul>';
        $html.='</div>';
        return $html;
    }

    public function output_item_attachment($id)
    {
        $html='<div class="cx-media-attachment" data-id="'.esc_attr($id).'"><h4>' . esc_html__('Aero Images','aero') . '</h4>';
        $html.='<ul>';
        $html.=$this->output_item_detail($id);
        $html.=$this->output_item_action($id);
        $html.='</ul>';
        $html.='</div>';
        return $html;
    }

    public function output_item_detail($id)
    {
        $html='';
        $og_size=Aero_IO_Image_Meta_V2::get_og_size($id);
        if(empty($og_size))
        {
            $file_path = get_attached_file( $id );
            if(file_exists($file_path))
                $og_size=filesize($file_path);
            else
                $og_size=0;
        }

        $html.='<li><span>Original : </span><strong>'.esc_html(size_format($og_size,2)).'</strong></li>';

        $webp_size=Aero_IO_Image_Meta_V2::get_webp_converted_size($id);
        if($webp_size>0&&$og_size>$webp_size)
        {
            $webp_percent = round(100 - ($webp_size / $og_size) * 100, 2);
        }
        else
        {
            $webp_percent=0;
        }

        $html.='<li><span>Webp : <strong>'.esc_html(size_format($webp_size,2)).'</strong> Saved : <strong>'.esc_html($webp_percent).'%</strong></span></li>';

        $avif_size=Aero_IO_Image_Meta_V2::get_avif_converted_size($id);

        if($avif_size>0&&$og_size>$avif_size)
        {
            $avif_percent = round(100 - ($avif_size / $og_size) * 100, 2);
        }
        else
        {
            $avif_percent=0;
        }

        $html.='<li><span>AVIF : <strong>'.esc_html(size_format($avif_size,2)).'</strong> Saved : <strong>'.esc_html($avif_percent).'%</strong></span></li>';

        $meta=Aero_IO_Image_Meta_V2::get_image_meta($id);
        if(isset($meta['size'])&&!empty($meta['size']))
        {
            $thumbnail_counts=count($meta['size']);
        }
        else
        {
            $thumbnail_counts=0;
        }
        $html.='<li><span>Thumbnails generated : </span><strong>'.esc_html($thumbnail_counts).'</strong></li>';

        return $html;
    }

    public function output_item_action($id)
    {
        $html='<li>';
        $html.='<select class="cx-media-selected">';
        $html.='<option value="">Select Action</option>';
        if(Aero_IO_Image_Meta_V2::is_image_optimized($id))
        {
            $html.='<option value="delete">Delete</option>';
        }
        else
        {
            $html.='<option value="convert">Convert</option>';
            if(Aero_IO_Image_Meta_V2::has_optimized_file($id))
            {
                $html.='<option value="delete">Delete</option>';
            }
        }

        $html.='</select>';
        if($this->is_image_progressing($id))
        {
            $html.='<a class="cx-media button-disabled button" data-id="'.esc_attr($id).'">Progressing</a>';
        }
        else
        {
            $html.='<a class="cx-media button" data-id="'.esc_attr($id).'">Apply</a>';
        }

        $html.='</li>';
        return $html;
    }

    public function is_skipped($image_id)
    {
        if(Aero_IO_Image_Meta_V2::get_image_meta_status($image_id)==='skip')
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public function is_image_progressing($post_id)
    {
        $progressing=Aero_IO_Image_Meta_V2::get_image_progressing($post_id);

        if(empty($progressing))
        {
            return false;
        }
        else
        {
            $current_time=time();
            if(($current_time-$progressing)>180)
            {
                return false;
            }
            else
            {
                return true;
            }
        }
    }

    public function get_opt_single_image_progress()
    {
        
        aero_io_ajax_check_security('aero-io-can-convert');

        if(!isset($_POST['ids'])||!is_string($_POST['ids']))
        {
            die();
        }

        $ids=sanitize_text_field($_POST['ids']);
        $ids=json_decode($ids,true);

        $running=false;

        if(isset($_POST['page']))
        {
            $page=sanitize_text_field($_POST['page']);
        }
        else
        {
            $page='media';
        }

        foreach ($ids as $id)
        {
            if(!Aero_IO_Image_Meta_V2::is_image_optimized($id))
            {
                if($this->is_image_progressing($id))
                {
                    $running=true;
                    break;
                }
            }
        }

        $ret['result']='success';
        if($running)
        {
            $ret['continue']=1;
            $ret['finished']=0;
        }
        else
        {
            $ret['continue']=0;
            $ret['finished']=1;
        }

        foreach ($ids as $id)
        {
            if($page=='edit')
            {
                $html=$this->output_item_edit($id);
            }
            else
            {
                $html=$this->output_item($id);
            }

            $ret[$id]['html']=$html;
        }

        echo wp_json_encode($ret);

        die();
    }
}