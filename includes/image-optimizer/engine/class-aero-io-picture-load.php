<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Aero_IO_Picture_Load
{
    public function __construct()
    {
        if ( is_multisite() )
        {
            return;
        }

        $options=Aero_IO_Options::get_option('aero_io_general_settings',array());
        $image_load=isset($options['image_load'])?$options['image_load']:'htaccess';
        if ($image_load == "htaccess"||$image_load == "compat_htaccess")
        {
            return;
        }

        if ( is_admin() || is_customize_preview() )
        {
            return;
        }

        if ( defined( 'DOING_AJAX' ) && DOING_AJAX )
        {
            return;
        }

        if ( defined( 'DOING_CRON' ) && DOING_CRON )
        {
            return;
        }

        add_action( 'template_redirect', [ $this, 'start_content_process' ], 1000 );
        add_action( 'wp_enqueue_scripts', array( $this, 'overrides_main_style' ), 9999 );
    }

    public function overrides_main_style()
    {
        $current_active_theme = get_stylesheet();
        if($current_active_theme === 'Divi')
        {
            $style = '';
            $logo_height = esc_attr( et_get_option( 'logo_height', '54' ) );
            $style      .= "
				picture#logo {
					display: inherit;
				}
				picture#logo source, picture#logo img {
					width: auto;
					max-height: {$logo_height}%;
					vertical-align: middle;
				}
				@media (min-width: 981px) {
					.et_vertical_nav #main-header picture#logo source,
					.et_vertical_nav #main-header picture#logo img {
						margin-bottom: 28px;
					}
				}
			";

            if ( ! empty( $style ) ) {
                wp_add_inline_style( 'divi-style', $style );
            }
        }
    }

    public function start_content_process()
    {
        ob_start( [ $this, 'replace_picture_tag' ] );
    }
    
    public function replace_picture_tag($content)
    {
        $images = $this->get_images( $content );

        if ( ! empty( $images ) )
        {
            foreach ( $images as $image )
            {
                $tag     = $this->build_picture_tag( $image );
                $content = str_replace( $image['tag'], $tag, $content );
            }
        }

        // Aero: lets the loader augment CSS background images (inline
        // styles and <style> blocks) with image-set() sources — the
        // <picture> pass above only covers <img> tags.
        $content = apply_filters( 'aero_io_picture_content', $content );

        return $content;
    }

    protected function get_images( $content )
    {
        if ( preg_match( '/(?=<body).*<\/body>/is', $content, $body ) )
        {
            $content = $body[0];
        }

        $content = preg_replace( '/<!--(.*)-->/Uis', '', $content );

        $content = preg_replace('#<noscript(.*?)>(.*?)</noscript>#is', '', $content);

        if ( ! preg_match_all( '/<img\s.*>/isU', $content, $matches ) )
        {
            return array();
        }

        $images = array_map(array($this, 'process_image'), $matches[0] );
        $images = array_filter( $images );

        if ( ! $images || ! is_array( $images ) )
        {
            return array();
        }

        return $images;
    }

    public function process_image($image)
    {
        $attributes=$this->get_attribute($image);

        $src=$this->get_src($attributes);

        $srcset=$this->get_srcset($attributes);

        $style=$this->get_style($attributes);

        if ( empty($src)&&empty($srcset) )
        {
            return false;
        }

        $data=array(
            'tag'              => $image,
            'attributes'       => $attributes,
            'src'              =>  $src,
            'srcset'           => $srcset,
            'style'            => $style,
        );

        return $data;
    }

    protected function build_picture_tag( $image )
    {
        $to_remove = array(
            'alt'              => '',
            'height'           => '',
            'width'            => '',
            'data-lazy-src'    => '',
            'data-src'         => '',
            'src'              => '',
            'data-lazy-srcset' => '',
            'data-srcset'      => '',
            'srcset'           => '',
            'data-lazy-sizes'  => '',
            'data-sizes'       => '',
            'sizes'            => '',
            'style'            => '',
        );

        $attributes = array_diff_key( $image['attributes'], $to_remove );

        $output = '<picture' . $this->picture_build_attributes( $attributes ) . ">";
        $output .= $this->build_source_tag_ex( $image );

        $output .= $this->build_img_tag( $image );
        $output .= "</picture>";

        return $output;
    }

    protected function picture_build_attributes( $attributes )
    {
        if ( ! $attributes || ! is_array( $attributes ) )
        {
            return '';
        }

        $out = '';

        foreach ( $attributes as $attribute => $value )
        {
            $out .= ' ' . $attribute . '="' . esc_attr( $value ) . '"';
        }

        return $out;
    }

    protected function build_attributes( $attributes )
    {
        if ( ! $attributes || ! is_array( $attributes ) )
        {
            return '';
        }

        $out = '';

        foreach ( $attributes as $attribute => $value )
        {
            $out .= ' ' . $attribute . '="' . esc_attr( $value ) . '"';
        }

        return $out;
    }

    protected function build_source_attributes( $image, $image_type )
    {
        $mime_type = '';
        $url = '';

        switch ( $image_type ) {
            case 'webp':
                $mime_type = 'image/webp';
                $url = 'webp_url';
                break;
            case 'avif':
                $mime_type = 'image/avif';
                $url = 'avif_url';
                break;
        }

        $srcset_source = ! empty( $image['srcset']['srcset_attr'] ) ? $image['srcset']['srcset_attr'] : $image['src']['src_attr'] . 'set';

        $attributes    = [
            'type'         => $mime_type,
            $srcset_source => array(),
        ];

        /*
        if ( ! empty( $image['srcset'] ) )
        {
            foreach ( $image['srcset'] as $srcset )
            {
                if ( empty( $srcset[ $url ] ) )
                {
                    continue;
                }

                $attributes[ $srcset_source ][] = $srcset[ $url ] . ' ' . $srcset['descriptor'];
            }
        }*/

        if ( ! empty( $image['srcset']['srcs'] ) )
        {
            foreach ( $image['srcset']['srcs'] as $srcset )
            {
                if (! empty( $srcset[$url] ) )
                {
                    $attributes[ $srcset_source ][] = $srcset[$url] . ' ' . $srcset['descriptor'];
                }

            }
        }
        else if ( ! empty( $image['srcset'] ) )
        {
            foreach ( $image['srcset'] as $srcset )
            {
                if ( empty( $srcset[ $url ] ) )
                {
                    continue;
                }

                $attributes[ $srcset_source ][] = $srcset[ $url ] . ' ' . $srcset['descriptor'];
            }
        }


        if ( empty( $attributes[ $srcset_source ] ) && empty( $image['src'][ $url ] ) ) {
            return [];
        }


        if ( empty( $attributes[ $srcset_source ] ) ) {
            $attributes[ $srcset_source ][] = $image['src'][ $url ];
        }

        $attributes[ $srcset_source ] = implode( ', ', $attributes[ $srcset_source ] );

        foreach ( [ 'data-lazy-srcset', 'data-srcset', 'srcset' ] as $srcset_attr ) {
            if ( ! empty( $image['attributes'][ $srcset_attr ] ) && $srcset_attr !== $srcset_source ) {
                $attributes[ $srcset_attr ] = $image['attributes'][ $srcset_attr ];
            }
        }

        if ( 'srcset' !== $srcset_source && empty( $attributes['srcset'] ) && ! empty( $image['attributes']['src'] ) ) {
            // Lazyload: the "src" attr should contain a placeholder (a data image or a blank.gif ).
            $attributes['srcset'] = $image['attributes']['src'];
        }

        foreach ( [ 'data-lazy-sizes', 'data-sizes', 'sizes' ] as $sizes_attr ) {
            if ( ! empty( $image['attributes'][ $sizes_attr ] ) ) {
                $attributes[ $sizes_attr ] = $image['attributes'][ $sizes_attr ];
            }
        }

        return $attributes;
    }

    protected function build_source_tag_ex( $image )
    {
        $source = '';

        foreach ( [ 'avif', 'webp' ] as $image_type )
        {
            $attributes = $this->build_source_attributes( $image, $image_type );

            if ( empty( $attributes ) ) {
                continue;
            }

            $source .= '<source' . $this->build_attributes( $attributes ) . "/>";
        }

        return $source;
    }

    protected function build_img_tag( $image )
    {
        /*$to_remove = array(
            'class'  => '',
            'id'     => '',
            'style'  => '',
            'title'  => '',
        );*/
        $to_remove = array(
            'id'     => '',
            'title'  => '',
        );

        $attributes = array_diff_key( $image['attributes'], $to_remove );

        return '<img' . $this->build_attributes( $attributes ) . "/>";
    }

    public function get_attribute($image)
    {
        if (function_exists("mb_convert_encoding"))
        {
            $image = mb_encode_numericentity($image, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
            //$image = mb_convert_encoding($image, 'HTML-ENTITIES', 'UTF-8');
        }

        if (class_exists('DOMDocument'))
        {
            $dom = new \DOMDocument();
            @$dom->loadHTML($image);
            $image = $dom->getElementsByTagName('img')->item(0);
            $attributes = array();

            /* This can happen with mismatches, or extremely malformed HTML.
            In customer case, a javascript that did  for (i<imgDefer) --- </script> */
            if (! is_object($image))
                return false;

            foreach ($image->attributes as $attr)
            {
                $attributes[$attr->nodeName] = $attr->nodeValue;
            }
            return $attributes;
        }
        else
        {
            $atts_pattern = '/(?<name>[^\s"\']+)\s*=\s*(["\'])\s*(?<value>.*?)\s*\2/s';

            if ( ! preg_match_all( $atts_pattern, $image, $tmp_attributes, PREG_SET_ORDER ) )
            {
                return false;
            }

            $attributes = array();

            foreach ( $tmp_attributes as $attribute )
            {
                $attributes[ $attribute['name'] ] = $attribute['value'];
            }

            return $attributes;
        }

    }

    public function get_src($attributes)
    {
        $src_source = false;
        $data_tag=array( 'data-lazy-src','data-src', 'src');

        foreach ( $data_tag as $src_attr )
        {
            if ( ! empty( $attributes[ $src_attr ] ) )
            {
                $src_source = $src_attr;
                break;
            }
        }

        if ( ! $src_source )
        {
            // No src attribute.
            return false;
        }

        $extensions = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'webp'          => 'image/webp',
            'avif'          => 'image/avif',
        );
        $extensions = array_keys( $extensions );
        $extensions = implode( '|', $extensions );

        if ( ! preg_match( '@^(?<src>(?:(?:https?:)?//|/).+\.(?<extension>' . $extensions . '))(?<query>\?.*)?$@i', $attributes[ $src_source ], $src ) )
        {
            // Not a supported image format.
            return false;
        }

        $url  =  $src['src'];
        $path_exist=false;
        $exist_ret=$this->webp_exist($url);
        if($exist_ret['result']=='success')
        {
            $path_exist=true;
            $ret['webp_exist']=true;
            $ret['webp_path']=$exist_ret['path'];

            $exist_ret['url'] .= ! empty( $src['query'] ) ? $src['query'] : '';
            $ret['webp_url']=$exist_ret['url'];
        }


        $exist_ret=$this->avif_exist($url);
        if($exist_ret['result']=='success')
        {
            $path_exist=true;
            $ret['avif_exist']=true;
            $ret['avif_path']=$exist_ret['path'];

            $exist_ret['url'] .= ! empty( $src['query'] ) ? $src['query'] : '';
            $ret['avif_url']=$exist_ret['url'];
        }

        if(!$path_exist)
        {
            return false;
        }
        $ret['src']=$attributes[ $src_source ];
        $ret['src_attr']=$src_source;


        return $ret;
    }

    public function webp_exist($url)
    {
        $upload=wp_upload_dir();
        if ( stripos( $url, $upload['baseurl'] ) === 0 )
        {
            $path = str_replace($upload['baseurl'],$upload['basedir'],$url);

            $upload_root=Aero_IO_Image_Method::transfer_path($upload['basedir']);
            $attachment_dir=$path;
            $attachment_dir=Aero_IO_Image_Method::transfer_path($attachment_dir);
            $sub_dir=str_replace($upload_root,'',$attachment_dir);
            $sub_dir=untrailingslashit($sub_dir);
            $aero_io_path=WP_CONTENT_DIR."/aero-nextgen/uploads".$sub_dir.'.webp';

            $path = str_replace($upload['baseurl'],'',$url);
            $url  = content_url().'/aero-nextgen/uploads'.$path.'.webp';;
        }
        else
        {
            $aero_io_path='';
            $url='';
        }

        if(file_exists($aero_io_path))
        {
            $ret['result']='success';
            $ret['url']=$url;
            $ret['path']=$aero_io_path;
            return $ret;
        }
        else
        {
            $ret['result']='failed';
            return $ret;
        }
    }

    public function avif_exist($url)
    {
        $upload=wp_upload_dir();
        if ( stripos( $url, $upload['baseurl'] ) === 0 )
        {
            $path = str_replace($upload['baseurl'],$upload['basedir'],$url);

            $upload_root=Aero_IO_Image_Method::transfer_path($upload['basedir']);
            $attachment_dir=$path;
            $attachment_dir=Aero_IO_Image_Method::transfer_path($attachment_dir);
            $sub_dir=str_replace($upload_root,'',$attachment_dir);
            $sub_dir=untrailingslashit($sub_dir);
            $aero_io_path=WP_CONTENT_DIR."/aero-nextgen/uploads".$sub_dir.'.avif';

            $path = str_replace($upload['baseurl'],'',$url);
            $url  = content_url().'/aero-nextgen/uploads'.$path.'.avif';
        }
        else
        {
            $aero_io_path='';
            $url='';
        }

        if(file_exists($aero_io_path))
        {
            $ret['result']='success';
            $ret['url']=$url;
            $ret['path']=$aero_io_path;
            return $ret;
        }
        else
        {
            $ret['result']='failed';
            return $ret;
        }
    }

    public function get_srcset($attributes)
    {
        $srcset_source = false;
        $upload=wp_upload_dir();
        $data_tag=array('data-lazy-srcset', 'data-srcset', 'srcset');

        $extensions = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            //'gif'          => 'image/gif',
        );
        $extensions = array_keys( $extensions );
        $extensions = implode( '|', $extensions );

        foreach ( $data_tag as $srcset_attr )
        {
            if ( ! empty( $attributes[ $srcset_attr ] ) )
            {
                $srcset_source = $srcset_attr;
                break;
            }
        }

        $ret['srcset_attr']=$srcset_source;
        $ret['srcs']=array();
        if ( $srcset_source )
        {
            $srcset = explode( ',', $attributes[ $srcset_source ] );

            foreach ( $srcset as $srcs )
            {
                $srcs = preg_split( '/\s+/', trim( $srcs ) );

                if ( count( $srcs ) > 2 )
                {
                    $descriptor = array_pop( $srcs );
                    $srcs       = array(implode( ' ', $srcs ), $descriptor);
                }

                if ( empty( $srcs[1] ) )
                {
                    $srcs[1] = '1x';
                }

                if ( ! preg_match( '@^(?<src>(?:https?:)?//.+\.(?<extension>' . $extensions . '))(?<query>\?.*)?$@i', $srcs[0], $src ) )
                {
                    continue;
                }

                $url  =  $src['src'];
                $path_exist=false;
                $exist_ret=$this->webp_exist($url);
                if($exist_ret['result']=='success')
                {
                    $path_exist=true;
                    $ret['webp_exist']=true;
                    $ret['webp_path']=$exist_ret['path'];

                    $exist_ret['url'] .= ! empty( $src['query'] ) ? $src['query'] : '';
                    $ret['webp_url']=$exist_ret['url'];

                    $tmp_srcset=array(
                        'url'         => $srcs[0],
                        'descriptor'  => $srcs[1],
                        'webp_exists'=>$path_exist
                    );

                    $tmp_srcset['webp_path']=$ret['webp_path'];
                    $tmp_srcset['webp_url']=$ret['webp_url'];

                    $ret['srcs'][]=$tmp_srcset;
                }

                $exist_ret=$this->avif_exist($url);
                if($exist_ret['result']=='success')
                {
                    $path_exist=true;
                    $ret['avif_exist']=true;
                    $ret['avif_path']=$exist_ret['path'];

                    $exist_ret['url'] .= ! empty( $src['query'] ) ? $src['query'] : '';
                    $ret['avif_url']=$exist_ret['url'];

                    $tmp_srcset=array(
                        'url'         => $srcs[0],
                        'descriptor'  => $srcs[1],
                        'webp_exists'=>$path_exist
                    );

                    $tmp_srcset['avif_path']=$ret['avif_path'];
                    $tmp_srcset['avif_url']=$ret['avif_url'];

                    $ret['srcs'][]=$tmp_srcset;
                }

                /*
                if($path_exist)
                {
                    $tmp_srcset=array(
                        'url'         => $srcs[0],
                        'descriptor'  => $srcs[1],
                        'webp_exists'=>$path_exist
                    );

                    $tmp_srcset['webp_path']=$ret['webp_path'];
                    $tmp_srcset['webp_url']=$ret['webp_url'];

                    $ret['srcs'][]=$tmp_srcset;
                }
                else
                {
                    //$path_exist=false;
                    continue;
                }*/
            }
        }
        else
        {
            return false;
        }

        if(empty($ret['srcs']))
            return false;

        return $ret;
    }

    public function get_style($attributes)
    {
        $style_attr='style';
        if ( empty( $attributes[ $style_attr ] ) )
        {
            // No style.
            return false;
        }

        return $attributes[ $style_attr ];
    }
}