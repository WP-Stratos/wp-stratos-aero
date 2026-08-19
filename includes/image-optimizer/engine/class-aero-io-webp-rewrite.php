<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Aero_IO_Webp_Rewrite
{
    public function __construct()
    {

    }

    public function create_rewrite_rules()
    {
        $this->create_wp_content_rules();
        $this->create_uploads_rules();
    }

    public function create_rewrite_rules_ex()
    {
        $this->create_wp_content_rules_ex();
        $this->create_uploads_rules_ex();
    }
    public function transfer_path($path)
    {
        return preg_replace( '/(\/|\\\\)/', '/', rtrim( $path, '\/' ) );
    }

    public function get_wordpress_root()
    {
        if( defined('WP_CONTENT_DIR'))
        {
            $path=dirname(WP_CONTENT_DIR);
        }
        else
        {
            $path=ABSPATH;
        }

        return $this->transfer_path($path);
    }

    public function create_wp_content_rules()
    {
        $document_root=$this->transfer_path($_SERVER['DOCUMENT_ROOT']);
        $document_root_real=$this->transfer_path(realpath( $_SERVER['DOCUMENT_ROOT'] ));
        $wordpress_root=$this->get_wordpress_root();

        $root_path   = trim( str_replace( $document_root_real ?: '', '', $wordpress_root ?: '' ), '\/' );
        $root_suffix = str_replace( '//', '/', sprintf( '/%s/', $root_path ) );

        //$document_root2='%{DOCUMENT_ROOT}'.str_replace($document_root,'',$wordpress_root);
        $document_root2=( $document_root !== $document_root_real ) ? ( $wordpress_root . '/' ) : ( '%{DOCUMENT_ROOT}' . $root_suffix );

        $document_root3=str_replace($document_root,'',$wordpress_root);

        $upload_root  = wp_parse_url( content_url() );
        $upload_root  = $upload_root['path'].'/aero-nextgen';
        $upload_root2=str_replace($document_root3,'',$upload_root);

        $path=WP_CONTENT_DIR;
        $htaccess_file=$path . '/.htaccess';

        $line[]='<IfModule mod_rewrite.c>';
        $line[]='RewriteEngine On';
        $line[]='RewriteOptions Inherit';
        $line[]='RewriteCond %{QUERY_STRING} original$';

        $line[]='RewriteRule . - [L]';

        $line[]='RewriteCond %{HTTP_ACCEPT} image/avif';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.avif -f';
            $line[]='RewriteRule (.+)\.avif '.$upload_root.'/$1.avif [NC,T=image/avif,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.avif -f';
            $line[]='RewriteRule (.+)\.avif '.$upload_root.'/$1.avif [NC,T=image/avif,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.avif -f';
            $line[]='RewriteRule (.+)\.avif '.$upload_root.'/$1.avif [NC,T=image/avif,L]';
        }

        $line[]='RewriteCond %{HTTP_ACCEPT} image/avif';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.webp.avif -f';
            $line[]='RewriteRule (.+)\.webp '.$upload_root.'/$1.webp.avif [NC,T=image/avif,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.webp.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.webp.avif -f';
            $line[]='RewriteRule (.+)\.webp '.$upload_root.'/$1.webp.avif [NC,T=image/avif,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.webp.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.webp.avif -f';
            $line[]='RewriteRule (.+)\.webp '.$upload_root.'/$1.webp.avif [NC,T=image/avif,L]';
        }

        $line[]='RewriteCond %{HTTP_ACCEPT} image/avif';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.jpg.avif -f';
            $line[]='RewriteRule (.+)\.jpg$ '.$upload_root.'/$1.jpg.avif [NC,T=image/avif,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.jpg.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.jpg.avif -f';
            $line[]='RewriteRule (.+)\.jpg$ '.$upload_root.'/$1.jpg.avif [NC,T=image/avif,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.jpg.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.jpg.avif -f';
            $line[]='RewriteRule (.+)\.jpg$ '.$upload_root.'/$1.jpg.avif [NC,T=image/avif,L]';
        }

        $line[]='RewriteCond %{HTTP_ACCEPT} image/avif';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.png.avif -f';
            $line[]='RewriteRule (.+)\.png$ '.$upload_root.'/$1.png.avif [NC,T=image/avif,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.png.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.png.avif -f';
            $line[]='RewriteRule (.+)\.png$ '.$upload_root.'/$1.png.avif [NC,T=image/avif,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.png.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.png.avif -f';
            $line[]='RewriteRule (.+)\.png$ '.$upload_root.'/$1.png.avif [NC,T=image/avif,L]';
        }


        $line[]='RewriteCond %{HTTP_ACCEPT} image/avif';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.jpeg.avif -f';
            $line[]='RewriteRule (.+)\.jpeg$ '.$upload_root.'/$1.jpeg.avif [NC,T=image/avif,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.jpeg.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.jpeg.avif -f';
            $line[]='RewriteRule (.+)\.jpeg$ '.$upload_root.'/$1.jpeg.avif [NC,T=image/avif,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.jpeg.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.jpeg.avif -f';
            $line[]='RewriteRule (.+)\.jpeg$ '.$upload_root.'/$1.jpeg.avif [NC,T=image/avif,L]';
        }


        $line[]='RewriteCond %{HTTP_ACCEPT} image/webp';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.webp -f';
            $line[]='RewriteRule (.+)\.webp '.$upload_root.'/$1.webp [NC,T=image/webp,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.webp -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.webp -f';
            $line[]='RewriteRule (.+)\.webp '.$upload_root.'/$1.webp [NC,T=image/webp,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.webp -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.webp -f';
            $line[]='RewriteRule (.+)\.webp '.$upload_root.'/$1.webp [NC,T=image/webp,L]';
        }


        $line[]='RewriteCond %{HTTP_ACCEPT} image/webp';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.jpg.webp -f';
            $line[]='RewriteRule (.+)\.jpg$ '.$upload_root.'/$1.jpg.webp [NC,T=image/webp,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.jpg.webp -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.jpg.webp -f';
            $line[]='RewriteRule (.+)\.jpg$ '.$upload_root.'/$1.jpg.webp [NC,T=image/webp,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.jpg.webp -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.jpg.webp -f';
            $line[]='RewriteRule (.+)\.jpg$ '.$upload_root.'/$1.jpg.webp [NC,T=image/webp,L]';
        }

        $line[]='RewriteCond %{HTTP_ACCEPT} image/webp';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.png.webp -f';
            $line[]='RewriteRule (.+)\.png$ '.$upload_root.'/$1.png.webp [NC,T=image/webp,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.png.webp -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.png.webp -f';
            $line[]='RewriteRule (.+)\.png$ '.$upload_root.'/$1.png.webp [NC,T=image/webp,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.png.webp -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.png.webp -f';
            $line[]='RewriteRule (.+)\.png$ '.$upload_root.'/$1.png.webp [NC,T=image/webp,L]';
        }

        $line[]='RewriteCond %{HTTP_ACCEPT} image/webp';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.jpeg.webp -f';
            $line[]='RewriteRule (.+)\.jpeg$ '.$upload_root.'/$1.jpeg.webp [NC,T=image/webp,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.jpeg.webp -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.jpeg.webp -f';
            $line[]='RewriteRule (.+)\.jpeg$ '.$upload_root.'/$1.jpeg.webp [NC,T=image/webp,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.jpeg.webp -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.jpeg.webp -f';
            $line[]='RewriteRule (.+)\.jpeg$ '.$upload_root.'/$1.jpeg.webp [NC,T=image/webp,L]';
        }

        $options=Aero_IO_Options::get_option('aero_io_general_settings',array());
        $disable_cache_control=isset($options['disable_cache_control'])?$options['disable_cache_control']:false;
        if(!$disable_cache_control)
        {
            $line[]='</IfModule>';
            $line[]='<IfModule mod_headers.c>';
            $line[]='<FilesMatch "(?i)\.(jpg|png|webp|jpeg)(\.(webp|avif))?$">';
            $line[]='Header always set Cache-Control "private"';
            $line[]='Header append Vary "Accept"';
            $line[]='</FilesMatch>';
            $line[]='</IfModule>';
        }

        insert_with_markers($htaccess_file,'Aero Images',$line);
    }

    public function create_uploads_rules()
    {
        $document_root=$this->transfer_path($_SERVER['DOCUMENT_ROOT']);
        $document_root_real=$this->transfer_path(realpath( $_SERVER['DOCUMENT_ROOT'] ));

        $wordpress_root=$this->get_wordpress_root();
        $root_path   = trim( str_replace( $document_root_real ?: '', '', $wordpress_root ?: '' ), '\/' );
        $root_suffix = str_replace( '//', '/', sprintf( '/%s/', $root_path ) );

        //$document_root2='%{DOCUMENT_ROOT}'.str_replace($document_root,'',$wordpress_root);
        $document_root2=( $document_root !== $document_root_real ) ? ( $wordpress_root . '/' ) : ( '%{DOCUMENT_ROOT}' . $root_suffix );

        $document_root3=str_replace($document_root,'',$wordpress_root);

        $upload_dir = wp_upload_dir();
        $path=$upload_dir['basedir'];

        $upload_root  = wp_parse_url( content_url() );
        $upload_root  = $upload_root['path'].'/aero-nextgen/uploads';
        $upload_root2=str_replace($document_root3,'',$upload_root);

        $htaccess_file=$path . '/.htaccess';

        $line[]='<IfModule mod_rewrite.c>';
        $line[]='RewriteEngine On';
        $line[]='RewriteOptions Inherit';
        $line[]='RewriteCond %{QUERY_STRING} original$';
        $line[]='RewriteRule . - [L]';

        $line[]='RewriteCond %{HTTP_ACCEPT} image/avif';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.avif -f';
            $line[]='RewriteRule (.+)\.avif '.$upload_root.'/$1.avif [NC,T=image/avif,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.avif -f';
            $line[]='RewriteRule (.+)\.avif '.$upload_root.'/$1.avif [NC,T=image/avif,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.avif -f';
            $line[]='RewriteRule (.+)\.avif '.$upload_root.'/$1.avif [NC,T=image/avif,L]';
        }

        $line[]='RewriteCond %{HTTP_ACCEPT} image/avif';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.webp.avif -f';
            $line[]='RewriteRule (.+)\.webp '.$upload_root.'/$1.webp.avif [NC,T=image/avif,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.webp.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.webp.avif -f';
            $line[]='RewriteRule (.+)\.webp '.$upload_root.'/$1.webp.avif [NC,T=image/avif,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.webp.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.webp.avif -f';
            $line[]='RewriteRule (.+)\.webp '.$upload_root.'/$1.webp.avif [NC,T=image/avif,L]';
        }

        $line[]='RewriteCond %{HTTP_ACCEPT} image/avif';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.jpg.avif -f';
            $line[]='RewriteRule (.+)\.jpg$ '.$upload_root.'/$1.jpg.avif [NC,T=image/avif,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.jpg.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.jpg.avif -f';
            $line[]='RewriteRule (.+)\.jpg$ '.$upload_root.'/$1.jpg.avif [NC,T=image/avif,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.jpg.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.jpg.avif -f';
            $line[]='RewriteRule (.+)\.jpg$ '.$upload_root.'/$1.jpg.avif [NC,T=image/avif,L]';
        }

        $line[]='RewriteCond %{HTTP_ACCEPT} image/avif';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.png.avif -f';
            $line[]='RewriteRule (.+)\.png$ '.$upload_root.'/$1.png.avif [NC,T=image/avif,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.png.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.png.avif -f';
            $line[]='RewriteRule (.+)\.png$ '.$upload_root.'/$1.png.avif [NC,T=image/avif,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.png.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.png.avif -f';
            $line[]='RewriteRule (.+)\.png$ '.$upload_root.'/$1.png.avif [NC,T=image/avif,L]';
        }


        $line[]='RewriteCond %{HTTP_ACCEPT} image/avif';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.jpeg.avif -f';
            $line[]='RewriteRule (.+)\.jpeg$ '.$upload_root.'/$1.jpeg.avif [NC,T=image/avif,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.jpeg.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.jpeg.avif -f';
            $line[]='RewriteRule (.+)\.jpeg$ '.$upload_root.'/$1.jpeg.avif [NC,T=image/avif,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.jpeg.avif -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.jpeg.avif -f';
            $line[]='RewriteRule (.+)\.jpeg$ '.$upload_root.'/$1.jpeg.avif [NC,T=image/avif,L]';
        }


        $line[]='RewriteCond %{HTTP_ACCEPT} image/webp';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.webp -f';
            $line[]='RewriteRule (.+)\.webp '.$upload_root.'/$1.webp [NC,T=image/webp,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.webp -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.webp -f';
            $line[]='RewriteRule (.+)\.webp '.$upload_root.'/$1.webp [NC,T=image/webp,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.webp -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.webp -f';
            $line[]='RewriteRule (.+)\.webp '.$upload_root.'/$1.webp [NC,T=image/webp,L]';
        }


        $line[]='RewriteCond %{HTTP_ACCEPT} image/webp';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.jpg.webp -f';
            $line[]='RewriteRule (.+)\.jpg$ '.$upload_root.'/$1.jpg.webp [NC,T=image/webp,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.jpg.webp -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.jpg.webp -f';
            $line[]='RewriteRule (.+)\.jpg$ '.$upload_root.'/$1.jpg.webp [NC,T=image/webp,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.jpg.webp -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.jpg.webp -f';
            $line[]='RewriteRule (.+)\.jpg$ '.$upload_root.'/$1.jpg.webp [NC,T=image/webp,L]';
        }

        $line[]='RewriteCond %{HTTP_ACCEPT} image/webp';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.png.webp -f';
            $line[]='RewriteRule (.+)\.png$ '.$upload_root.'/$1.png.webp [NC,T=image/webp,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.png.webp -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.png.webp -f';
            $line[]='RewriteRule (.+)\.png$ '.$upload_root.'/$1.png.webp [NC,T=image/webp,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.png.webp -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.png.webp -f';
            $line[]='RewriteRule (.+)\.png$ '.$upload_root.'/$1.png.webp [NC,T=image/webp,L]';
        }

        $line[]='RewriteCond %{HTTP_ACCEPT} image/webp';

        if($document_root2=='%{DOCUMENT_ROOT}')
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.jpeg.webp -f';
            $line[]='RewriteRule (.+)\.jpeg$ '.$upload_root.'/$1.jpeg.webp [NC,T=image/webp,L]';
        }
        elseif ( strpos( $document_root, '%{DOCUMENT_ROOT}' ) !== false )
        {
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root.'/$1.jpeg.webp -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.jpeg.webp -f';
            $line[]='RewriteRule (.+)\.jpeg$ '.$upload_root.'/$1.jpeg.webp [NC,T=image/webp,L]';
        }
        else
        {
            $line[]='RewriteCond '.untrailingslashit($document_root2).$upload_root2.'/$1.jpeg.webp -f [OR]';
            $line[]='RewriteCond %{DOCUMENT_ROOT}'.$upload_root2.'/$1.jpeg.webp -f';
            $line[]='RewriteRule (.+)\.jpeg$ '.$upload_root.'/$1.jpeg.webp [NC,T=image/webp,L]';
        }

        $line[]='</IfModule>';
        insert_with_markers($htaccess_file,'Aero Images',$line);
    }

    public function remove_rewrite_rule()
    {
        $path=WP_CONTENT_DIR;
        $htaccess_file=$path . '/.htaccess';
        insert_with_markers($htaccess_file,'Aero Images','');

        $upload_dir = wp_upload_dir();
        $path=$upload_dir['basedir'];
        $htaccess_file=$path . '/.htaccess';
        insert_with_markers($htaccess_file,'Aero Images','');
    }

    public function create_wp_content_rules_ex()
    {
        $doc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
        $abs_path = rtrim(str_replace('\\', '/', ABSPATH), '/');
        $prefix = '';
        if (strpos($abs_path, $doc_root) === 0) {
            $subdir = substr($abs_path, strlen($doc_root));
            $prefix = rtrim($subdir, '/');
        }
        $prefix = $prefix ? '/' . ltrim($prefix, '/') : '';
        $disable_cache_control = isset($options['disable_cache_control']) ? $options['disable_cache_control'] : false;

        $lines = [];
        $lines[] = '<IfModule mod_rewrite.c>';
        $lines[] = 'RewriteEngine On';
        $lines[] = 'RewriteCond %{QUERY_STRING} original$';
        $lines[] = 'RewriteRule . - [L]';
        $lines[] = '';

        // AVIF
        $lines[] = '# AVIF';

        $lines[] = 'RewriteCond %{DOCUMENT_ROOT}' . $prefix . '/wp-content/aero-nextgen/$1.$2 -f';
        $lines[] = 'RewriteRule ^(?:.*wp-content/)?(.+)\.(avif)$ ' . $prefix . '/wp-content/aero-nextgen/$1.$2 [T=image/avif,L]';
        $lines[] = '';

        // WebP
        $lines[] = '# WebP';

        $lines[] = 'RewriteCond %{DOCUMENT_ROOT}' . $prefix . '/wp-content/aero-nextgen/$1.$2 -f';
        $lines[] = 'RewriteRule ^(?:.*wp-content/)?(.+)\.(webp)$ ' . $prefix . '/wp-content/aero-nextgen/$1.$2 [T=image/webp,L]';
        $lines[] = '';

        // JPG/PNG → AVIF
        $lines[] = '# JPG/PNG → AVIF';
        $lines[] = 'RewriteCond %{HTTP_ACCEPT} image/avif';

        $lines[] = 'RewriteCond %{DOCUMENT_ROOT}' . $prefix . '/wp-content/aero-nextgen/$1.$2.avif -f';
        $lines[] = 'RewriteRule ^(?:.*wp-content/)?(.+)\.(jpe?g|png)$ ' . $prefix . '/wp-content/aero-nextgen/$1.$2.avif [T=image/avif,L]';
        $lines[] = '';

        // JPG/PNG → WebP
        $lines[] = '# JPG/PNG → WebP';
        $lines[] = 'RewriteCond %{HTTP_ACCEPT} image/webp';

        $lines[] = 'RewriteCond %{DOCUMENT_ROOT}' . $prefix . '/wp-content/aero-nextgen/$1.$2.webp -f';
        $lines[] = 'RewriteRule ^(?:.*wp-content/)?(.+)\.(jpe?g|png)$ ' . $prefix . '/wp-content/aero-nextgen/$1.$2.webp [T=image/webp,L]';
        $lines[] = '';

        $lines[] = '</IfModule>';

        if (!$disable_cache_control) {
            $lines[] = '<IfModule mod_headers.c>';
            $lines[] = '<FilesMatch "(?i)\.(jpg|png|webp|jpeg)(\.(webp|avif))?$">';
            $lines[] = 'Header always set Cache-Control "private"';
            $lines[] = 'Header append Vary "Accept"';
            $lines[] = '</FilesMatch>';
            $lines[] = '</IfModule>';
        }

        $target_path = WP_CONTENT_DIR . '/.htaccess';
        insert_with_markers($target_path, 'Aero Images', $lines);
    }

    public function create_uploads_rules_ex()
    {
        $doc_root = rtrim(str_replace(['\\', '/'], '/', $_SERVER['DOCUMENT_ROOT']), '/');
        $abs_path = rtrim(str_replace(['\\', '/'], '/', ABSPATH), '/');
        $prefix = '';
        if (strpos($abs_path, $doc_root) === 0) {
            $subdir = substr($abs_path, strlen($doc_root));
            $prefix = rtrim($subdir, '/');
        }
        $prefix = $prefix ? '/' . ltrim($prefix, '/') : '';

        $upload_dir = wp_get_upload_dir();
        $upload_basedir = rtrim(str_replace(['\\', '/'], '/', $upload_dir['basedir']), '/');
        $wp_content_dir = rtrim(str_replace(['\\', '/'], '/', WP_CONTENT_DIR), '/');
        $upload_relative_path = ltrim(str_replace($wp_content_dir, '', $upload_basedir), '/');
        $upload_prefix = $prefix . '/wp-content/aero-nextgen/' . $upload_relative_path;
        $upload_folder = trim(str_replace($abs_path, '', $upload_basedir), '/');

        $filename_match = '(.+)';
        $upload_rule_base = '^(?:.*' . preg_quote($upload_folder, '/') . '/)?' . $filename_match . '\.(%s)$';

        $lines = [];
        $lines[] = '<IfModule mod_rewrite.c>';
        $lines[] = 'RewriteEngine On';
        $lines[] = 'RewriteCond %{QUERY_STRING} original$';
        $lines[] = 'RewriteRule . - [L]';
        $lines[] = '';

        //
        $lines[] = '# AVIF';

        $lines[] = 'RewriteCond %{DOCUMENT_ROOT}' . $prefix . '/wp-content/aero-nextgen/' . $upload_relative_path . '/$1.$2 -f';
        $lines[] = 'RewriteRule ' . sprintf($upload_rule_base, 'avif') . ' ' . $upload_prefix . '/$1.avif [T=image/avif,L]';
        $lines[] = '';

        //
        $lines[] = '# WebP';

        $lines[] = 'RewriteCond %{DOCUMENT_ROOT}' . $prefix . '/wp-content/aero-nextgen/' . $upload_relative_path . '/$1.$2 -f';
        $lines[] = 'RewriteRule ' . sprintf($upload_rule_base, 'webp') . ' ' . $upload_prefix . '/$1.webp [T=image/webp,L]';
        $lines[] = '';

        // JPG/PNG → AVIF
        $lines[] = '# JPG/PNG → AVIF';
        $lines[] = 'RewriteCond %{HTTP_ACCEPT} image/avif';

        $lines[] = 'RewriteCond %{DOCUMENT_ROOT}' . $prefix . '/wp-content/aero-nextgen/' . $upload_relative_path . '/$1.$2.avif -f';
        $lines[] = 'RewriteRule ' . sprintf($upload_rule_base, 'jpe?g|png') . ' ' . $upload_prefix . '/$1.$2.avif [T=image/avif,L]';
        $lines[] = '';

        // JPG/PNG → WebP
        $lines[] = '# JPG/PNG → WebP';
        $lines[] = 'RewriteCond %{HTTP_ACCEPT} image/webp';

        $lines[] = 'RewriteCond %{DOCUMENT_ROOT}' . $prefix . '/wp-content/aero-nextgen/' . $upload_relative_path . '/$1.$2.webp -f';
        $lines[] = 'RewriteRule ' . sprintf($upload_rule_base, 'jpe?g|png') . ' ' . $upload_prefix . '/$1.$2.webp [T=image/webp,L]';
        $lines[] = '';

        $lines[] = '</IfModule>';

        $target_path = $upload_basedir . '/.htaccess';
        insert_with_markers($target_path, 'Aero Images', $lines);
    }
}