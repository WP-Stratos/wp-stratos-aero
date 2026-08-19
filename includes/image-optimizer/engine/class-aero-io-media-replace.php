<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Aero_IO_Media_Replace
{
    public $options;
    public $sizes;
    public $current_file_path;

    public function replace($attachment_id,$replace_path,$options)
    {
        $this->init_options($options);
        if (! wp_attachment_is_image($attachment_id))
        {
            $ret['result']="failed";
            $ret['error']="Attachment is not an image";

            return $ret;
        }

        $ret = [];

        if (!file_exists($replace_path))
        {
            $ret['result']="failed";
            $ret['error']="Replace image file not found";

            return $ret;
        }

        $original_file_path = wp_get_original_image_path( $attachment_id );

        if (!file_exists($original_file_path))
        {
            $ret['result']="failed";
            $ret['error']="Original image file not found";
            return $ret;
        }

        $old_attachment_meta = wp_get_attachment_metadata($attachment_id);
        if (! is_array($old_attachment_meta))
        {
            $old_attachment_meta = [];
        }

        $ret = $this->replace_original_image($original_file_path, $replace_path);
        if ($ret['result']=='failed')
        {
            return $ret;
        }

        $ret = $this->build_new_size_metadata($old_attachment_meta);
        if ($ret['result']=='failed')
        {
            return $ret;
        }

        $new_size_meta=$ret['new_size_meta'];

        $this->cleanup_old_derivatives($original_file_path,$old_attachment_meta);

        $ret = $this->generate_and_update_metadata($attachment_id, $original_file_path ,$new_size_meta);
        if ($ret['result']=='failed')
        {
            return $ret;
        }

        $new_attachment_meta = wp_get_attachment_metadata($attachment_id);
        if ( !empty( $new_attachment_meta['original_image'] ) )
        {
            $pathinfo = pathinfo($original_file_path);
            $scaled = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '-scaled.' . ($pathinfo['extension'] ?? '');
            if (!empty($pathinfo['extension']) && file_exists($scaled))
            {
                update_attached_file($attachment_id, $scaled);
            }
        }
        else
        {
            update_attached_file($attachment_id, $original_file_path);
        }

        wp_delete_file($replace_path);
        $ret['result']='success';
        return $ret;
    }

    public function init_options($options)
    {
        $this->options = wp_parse_args($options, [
            'thumbnail_generation' => 'default_fill',
            'backup' => false,
        ]);
    }

    protected function rename( $source, $destination )
    {

        if ( ! file_exists( $source ) ) {
            return false;
        }

        global $wp_filesystem;

        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ( empty( $wp_filesystem ) ) {
            return false;
        }

        return $wp_filesystem->move( $source, $destination, true );
    }

    protected function is_writable( $dir ) {

        global $wp_filesystem;

        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        if ( empty( $wp_filesystem ) ) {
            return false;
        }

        return $wp_filesystem->is_writable( $dir );
    }

    protected function replace_original_image($original_file_path, $replace_path)
    {
        $ret = ['result' => 'failed'];

        $dir = dirname($original_file_path);
        if (!is_dir($dir) || !$this->is_writable($dir))
        {
            $ret['error'] = 'Original directory not writable';
            return $ret;
        }
        if (!file_exists($original_file_path)) {
            $ret['error'] = 'Original image file not found before swap';
            return $ret;
        }
        if (!file_exists($replace_path)) {
            $ret['error'] = 'Replace image file not found';
            return $ret;
        }

        // 1) move replace file into same directory first (atomic rename needs same FS)
        $new_temp = trailingslashit($dir) . basename($original_file_path) . '.__new_' . wp_generate_password(6, false, false);

        if (!$this->rename($replace_path, $new_temp))
        {
            if (!@copy($replace_path, $new_temp))
            {
                $ret['error'] = 'Failed to move replace file into target directory';
                return $ret;
            }
            @wp_delete_file($replace_path);
        }

        // 2) old -> bak
        $bak = trailingslashit($dir) . basename($original_file_path) . '.__bak_' . gmdate('Ymd_His');
        if (!$this->rename($original_file_path, $bak))
        {
            @wp_delete_file($new_temp);
            $ret['error'] = 'Failed to create bak file (rename old -> bak failed)';
            return $ret;
        }

        // 3) new_temp -> old
        if (!$this->rename($new_temp, $original_file_path))
        {
            // rollback immediately
            $this->rename($bak, $original_file_path);
            @wp_delete_file($new_temp);
            $ret['error'] = 'Failed to replace original file (rename new -> old failed)';
            return $ret;
        }

        @wp_delete_file($bak);

        $ret['result'] = 'success';
        return $ret;
    }

    protected function build_new_size_metadata($old_attachment_meta)
    {
        $ret = ['result' => 'failed'];

        $thumbnail_generation = (string) $this->options['thumbnail_generation'];

        // 1) Collect "current registered sizes"
        $registered = function_exists('wp_get_registered_image_subsizes')
            ? wp_get_registered_image_subsizes()
            : [];

        $plan_new = [];
        foreach ($registered as $name => $cfg)
        {
            $w = isset($cfg['width']) ? (int) $cfg['width'] : 0;
            $h = isset($cfg['height']) ? (int) $cfg['height'] : 0;

            // drop sizes with no constraint
            if ($w <= 0 && $h <= 0)
            {
                continue;
            }

            $plan_new[$name] = [
                'width' => max(0, $w),
                'height' => max(0, $h),
                'crop' => isset($cfg['crop']) ? (bool) $cfg['crop'] : false
            ];
        }

        // 2) Collect "old sizes" from attachment metadata
        $plan_old = [];
        if (isset($old_attachment_meta['sizes']) && is_array($old_attachment_meta['sizes']))
        {
            foreach ($old_attachment_meta['sizes'] as $name => $data)
            {
                if (!is_array($data))
                {
                    continue;
                }

                $w = isset($data['width']) ? (int) $data['width'] : 0;
                $h = isset($data['height']) ? (int) $data['height'] : 0;

                if ($w <= 0 || $h <= 0)
                {
                    continue;
                }

                // Old meta does not store crop; we'll decide later by strategy.
                $plan_old[$name] = [
                    'width' => $w,
                    'height' => $h,
                    'crop' => true
                ];
            }
        }

        // 3) Build final plan by mode
        if ($thumbnail_generation === 'match_original')
        {
            $plan = $plan_old;
        }
        //elseif ($thumbnail_generation === 'force_old')
        //{
        //    $plan = $plan_old;
        //}
        elseif ($thumbnail_generation === 'default_fill')
        {
            $plan = $plan_new;
            foreach ($plan_old as $name => $cfg)
            {
                if (isset($plan[$name]))
                {
                    $w = $plan[$name]['width'];
                    $h = $plan[$name]['height'];
                    if ($w != $cfg['width']||$h != $cfg['height'])
                    {
                        $plan[$name] = $cfg;
                    }
                }
                else
                {
                    $plan[$name] = $cfg;
                }
            }
        }
        else
        {
            $ret['error'] = 'Invalid mode';
            return $ret;
        }

        //Final validation
        foreach ($plan as $name => $cfg)
        {
            $w = $cfg['width'];
            $h = $cfg['height'];
            if ($w == 0 || $h == 0)
            {
                $plan[$name]['crop']=false;
            }
        }

        $ret['result'] = 'success';
        $ret['new_size_meta'] = $plan;

        return $ret;
    }

    protected function generate_and_update_metadata($attachment_id, $original_file_path, $new_size_meta)
    {
        $ret = ['result' => 'failed'];

        if (!file_exists($original_file_path))
        {
            $ret['error'] = 'Original image file not found';
            return $ret;
        }

        $this->sizes=$new_size_meta;
        $this->current_file_path=$original_file_path;

        add_filter( 'intermediate_image_sizes_advanced', array( $this, 'regen_thumbnails_sizes' ), 10, 2 );
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) )
        {
            include(ABSPATH . 'wp-admin/includes/image.php');
        }
        $new_metadata = wp_generate_attachment_metadata( $attachment_id, $original_file_path );

        remove_filter( 'intermediate_image_sizes_advanced', array( $this, 'regen_thumbnails_sizes' ), 10 );

        wp_update_attachment_metadata( $attachment_id, $new_metadata );

        $ret['result']='success';
        return $ret;
    }

    public function regen_thumbnails_sizes($sizes, $fullsize_metadata)
    {
        if (!is_array($this->sizes) || empty($this->sizes))
        {
            return $sizes;
        }

        $full_w = isset($fullsize_metadata['width']) ? (int)$fullsize_metadata['width'] : 0;
        $full_h = isset($fullsize_metadata['height']) ? (int)$fullsize_metadata['height'] : 0;

        if ($full_w <= 0 || $full_h <= 0)
        {
            // Can't reason; fallback to WP sizes
            return $sizes;
        }

        $new_sizes = [];

        foreach ( $this->sizes as $size => $size_data )
        {
            if (!is_array($size_data)) {
                continue;
            }

            $tw = isset($size_data['width']) ? (int)$size_data['width'] : 0;
            $th = isset($size_data['height']) ? (int)$size_data['height'] : 0;
            $crop = !empty($size_data['crop']);

            // invalid
            if ($tw <= 0 && $th <= 0) {
                continue;
            }

            // if any side is 0, force non-crop (consistent with your build step)
            if ($tw === 0 || $th === 0) {
                $crop = false;
            }

            // Use WP core helper to decide if thumbnail can be generated without upscaling / invalid dims
            $dims = image_resize_dimensions(
                $full_w,
                $full_h,
                ($tw > 0 ? $tw : null),
                ($th > 0 ? $th : null),
                $crop
            );

            if (!$dims)
            {
                // skip this size (missing)
                continue;
            }

            $new_sizes[$size] = [
                'width'  => ($tw > 0 ? $tw : null),
                'height' => ($th > 0 ? $th : null),
                'crop'   => $crop,
            ];
        }

        return $new_sizes;
    }

    protected function cleanup_old_derivatives($original_file_path,$old_attachment_meta)
    {
        $ret = ['result' => 'success'];

        $dir = trailingslashit(dirname($original_file_path));

        // 1) Delete sizes from old meta
        if (isset($old_attachment_meta['sizes']) && is_array($old_attachment_meta['sizes']))
        {
            foreach ($old_attachment_meta['sizes'] as $size_name => $data)
            {
                if (!is_array($data) || empty($data['file']))
                {
                    continue;
                }
                $file = $dir . wp_basename($data['file']);
                if (file_exists($file))
                {
                    @wp_delete_file($file);
                }
            }
        }

        if ( !empty( $old_attachment_meta['original_image'] ) )
        {
            $pathinfo = pathinfo($original_file_path);
            $scaled = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '-scaled.' . ($pathinfo['extension'] ?? '');
            if (!empty($pathinfo['extension']) && file_exists($scaled))
            {
                @wp_delete_file($scaled);
            }
        }

        return $ret;
    }

}