<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Aero_IO_Image_Scanner
{
    private static $has_sellvia = null;

    public static function check_sellvia_environment()
    {
        if ( self::$has_sellvia === null )
        {
            if ( !function_exists('get_plugins') )
            {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $plugin_path = 'sellvia-platform/sellvia-platform.php';

            $installed_plugins = get_plugins();
            $is_installed = isset( $installed_plugins[ $plugin_path ] );
            if($is_installed)
            {
                self::$has_sellvia=true;
            }
            else
            {
                self::$has_sellvia=false;
            }
        }
        return self::$has_sellvia;
    }

    //bulk
    public static function scan_unoptimized_images($force,$start_row)
    {
        $convert_to_webp=Aero_IO_Image_Method::get_convert_to_webp();
        $convert_to_avif=Aero_IO_Image_Method::get_convert_to_avif();

        //$exclude_regex_folder=Aero_IO_Pro_Options::get_excludes();

        $time_start=time();
        $max_timeout_limit=21;
        $finished=true;
        $options = Aero_IO_Options::get_option(
            'aero_io_general_settings',
            array()
        );

        $page = isset($options['scan_images_page'])
            ? $options['scan_images_page']
            : 500;

        $max_count=10000;

        $count = 0;
        $last_id = (int) $start_row;
        $processed = 0;

        while (true)
        {
            $result = self::scan_unoptimized_image_by_cursor($page, $last_id, $convert_to_webp, $convert_to_avif, $force);

            if (empty($result['last_id'])) {
                $finished = true;
                break;
            }

            $last_id = $result['last_id'];
            $processed += $result['count'];

            $time_spend = time() - $time_start;
            if ($time_spend > $max_timeout_limit || $processed > $max_count) {
                $finished = false;
                break;
            }
        }

        $need_optimize_images = self::get_need_optimize_images_count($force);

        $ret['result']   = 'success';
        $ret['finished'] = $finished;
        $ret['offset']   = $last_id;
        //$max_image_count=Aero_IO_Image_Method::get_max_image_count();
        global $wpdb;
        $max_image_id = (int) $wpdb->get_var("
    SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type='attachment'
");
        $progress = min(100, round(($last_id / $max_image_id) * 100, 2));
        $ret['progress'] = sprintf('Scanning up to ID %1$d of %2$d, Found %3$d (%4$s%%)',
            $last_id, $max_image_id, $need_optimize_images, $progress
        );

        return $ret;
    }

    //scan
    public static function scan_unoptimized_images_v2($force, $start_row)
    {
        $convert_to_webp=Aero_IO_Image_Method::get_convert_to_webp();
        $convert_to_avif=Aero_IO_Image_Method::get_convert_to_avif();

        $time_start=time();
        $max_timeout_limit=15;
        $finished=true;
        $options = Aero_IO_Options::get_option(
            'aero_io_general_settings',
            array()
        );

        $page = isset($options['scan_images_page'])
            ? $options['scan_images_page']
            : 500;

        $max_count=10000;

        $count = 0;
        $last_id = (int) $start_row;
        $processed = 0;

        while (true)
        {
            $result = self::scan_unoptimized_image_by_cursor_v2(
                $page,
                $last_id,
                $convert_to_webp,
                $convert_to_avif,
                $force
            );

            if (empty($result['last_id'])) {
                $finished = true;
                break;
            }

            $last_id = $result['last_id'];
            $processed += $result['count'];

            $time_spend = time() - $time_start;
            if ($time_spend > $max_timeout_limit || $processed > $max_count) {
                $finished = false;
                break;
            }
        }

        // percent
        global $wpdb;
        $total_attachments   = self::get_total_attachments_cached();

        $scanned_attachments = self::get_scanned_attachments($last_id);

        $percent = $total_attachments > 0
            ? min(100, round(($scanned_attachments / $total_attachments) * 100, 2))
            : 100;

        // need optimize images
        $need_optimize_images = self::get_need_optimize_images_count($force);

        $progress_text = sprintf(
            '%1$d / %2$d scanned',
            $scanned_attachments,
            $total_attachments
        );

        $ret = [
            'result'           => 'success',
            'finished'         => $finished,
            'offset'           => $last_id,
            'found'            => $need_optimize_images,
            'progress_text'    => $progress_text,
            'progress_percent' => $percent,
            'last_id'          => $last_id
        ];

        return $ret;
    }

    private static function get_total_attachments_cached()
    {
        global $wpdb;

        $mime_types = array(
            'image/jpg',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/avif'
        );

        $placeholders = implode(',', array_fill(0, count($mime_types), '%s'));

        $query = "
        SELECT COUNT(1)
        FROM {$wpdb->posts}
        WHERE post_type='attachment'
        AND post_mime_type IN ($placeholders)
    ";

        $query = $wpdb->prepare($query, $mime_types);

        return (int) $wpdb->get_var($query);
    }

    public static function scan_unoptimized_image_by_cursor($limit, $last_id, $convert_to_webp, $convert_to_avif, $force)
    {
        if (!$convert_to_webp && !$convert_to_avif)
        {
            return ['count' => 0, 'last_id' => 0];
        }

        global $wpdb;

        $mime_types = ["image/jpg", "image/jpeg", "image/png", "image/webp", "image/avif"];
        $placeholders = implode(',', array_fill(0, count($mime_types), '%s'));

        $subquery = "
        SELECT ID,post_mime_type
        FROM {$wpdb->posts}
        WHERE post_type = 'attachment'
          AND post_mime_type IN ($placeholders)
          AND ID > %d
        ORDER BY ID ASC
        LIMIT %d
    ";
        $args = array_merge($mime_types, [$last_id, $limit]);

        if ($force) {
            $status_filter = "1=1";
        } else {
            $status_filter = "(pm.status IS NULL OR pm.status NOT IN ('pending', 'skip'))";
        }

        $meta_table=Aero_IO_Image_Meta_V2::table_name();
        $outer_query = "
        SELECT p.ID,p.post_mime_type,pm.attachment_id
        FROM ($subquery) p
        LEFT JOIN {$meta_table} pm
          ON p.ID = pm.attachment_id
        WHERE $status_filter
    ";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $query = $wpdb->prepare($outer_query, $args);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->get_results($query, OBJECT_K);

        if (empty($result)) {
            return ['count' => 0, 'last_id' => 0];
        }

        $processed = 0;
        $max_id = 0;
        $exclude = Aero_IO_Options::get_excludes();

        foreach ($result as $image)
        {
            $image_id = (int) $image->ID;
            $max_id = max($max_id, $image_id);
            $processed++;

            $meta=Aero_IO_Image_Meta_V2::get_image_meta($image_id);

            if (self::should_skip($image_id))
            {
                Aero_IO_Image_Meta_V2::update_image_meta_status($image_id, 'skip');
                continue;
            }

            if(isset($meta['status'])&&$meta['status']=='optimized')
            {
                continue;
            }

            $type=$image->post_mime_type;
            $file_path = get_post_meta($image_id, '_wp_attached_file', true);

            if (empty($file_path))
            {
                continue;
            }

            if (self::should_exclude_by_path($file_path, $exclude))
            {
                Aero_IO_Image_Meta_V2::update_image_meta_status(
                    $image_id,
                    'skip'
                );

                continue;
            }

            $need_opt = false;

            if ($convert_to_webp)
            {
                if ($type != 'image/avif')
                {
                    if( !isset($meta['webp_converted']) ||$meta['webp_converted']==0)
                    {
                        $need_opt = true;
                    }
                }
            }

            if ($convert_to_avif)
            {
                if( !isset($meta['avif_converted']) ||$meta['avif_converted']==0)
                {
                    $need_opt = true;
                }
            }

            if ($need_opt)
            {
                Aero_IO_Image_Meta_V2::update_image_meta_status($image_id, 'pending');
            }
        }

        return [
            'count' => $processed,
            'last_id' => $max_id,
        ];
    }

    public static function should_skip($image_id)
    {
        if ( ! self::check_sellvia_environment() )
        {
            return false;
        }

        $is_sellvia = get_post_meta($image_id, '_wp_sellvia_attached_file', true);
        if ($is_sellvia === '1')
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public static function get_need_optimize_images_count($force)
    {
        global $wpdb;

        $meta_table = Aero_IO_Image_Meta_V2::table_name();

        if ($force) {
            $statuses = ['pending', 'optimized', 'failed'];
        } else {
            $statuses = ['pending', 'failed'];
        }

        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        return (int) $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(attachment_id)
        FROM {$meta_table}
        WHERE status IN ($placeholders)
    ", $statuses));
    }

    public static function get_need_optimize_images_by_cursor($last_id = 0, $limit = 200, $force = false)
    {
        global $wpdb;

        $meta_table=Aero_IO_Image_Meta_V2::table_name();

        if ($force)
        {
            return $wpdb->get_col($wpdb->prepare("
        SELECT pm.attachment_id 
        FROM {$meta_table} pm
        WHERE pm.status IN ('pending', 'optimized', 'failed')
          AND pm.attachment_id  > %d
        ORDER BY pm.attachment_id  ASC
        LIMIT %d
    ",  $last_id, $limit));
        }
        else
        {
            return $wpdb->get_col($wpdb->prepare("
        SELECT pm.attachment_id 
        FROM {$meta_table} pm
        WHERE pm.status IN ('pending', 'failed')
          AND pm.attachment_id  > %d
        ORDER BY pm.attachment_id  ASC
        LIMIT %d
    ",  $last_id, $limit));
        }
    }

    private static function get_scanned_attachments($last_id)
    {
        global $wpdb;

        $mime_types = array(
            'image/jpg',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/avif'
        );

        $placeholders = implode(',', array_fill(0, count($mime_types), '%s'));

        $query = "
        SELECT COUNT(1)
        FROM {$wpdb->posts}
        WHERE post_type='attachment'
        AND post_mime_type IN ($placeholders)
        AND ID <= %d
    ";

        $args = array_merge($mime_types, array($last_id));

        $query = $wpdb->prepare($query, $args);

        return (int) $wpdb->get_var($query);
    }

    public static function scan_unoptimized_image_by_cursor_v2($limit, $last_id, $convert_to_webp, $convert_to_avif, $force)
    {
        if (!$convert_to_webp && !$convert_to_avif)
        {
            return array(
                'count'   => 0,
                'last_id' => 0
            );
        }

        global $wpdb;

        $limit   = max(1, (int)$limit);
        $last_id = max(0, (int)$last_id);

        $mime_types = array(
            'image/jpg',
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/avif'
        );

        $placeholders = implode(',', array_fill(0, count($mime_types), '%s'));

        if ($force)
        {
            $status_filter = "1=1";
        }
        else
        {
            $status_filter = "(pm.status IS NULL OR pm.status NOT IN ('pending', 'skip'))";
        }

        $meta_table = Aero_IO_Image_Meta_V2::table_name();

        $sql = "
        SELECT
            p.ID,
            p.post_mime_type,
            pm.status,
            pm.webp_converted,
            pm.avif_converted,
            af.meta_value AS attached_file
        FROM {$wpdb->posts} p
        LEFT JOIN {$meta_table} pm
            ON p.ID = pm.attachment_id
        LEFT JOIN {$wpdb->postmeta} af
            ON af.post_id = p.ID
            AND af.meta_key = '_wp_attached_file'
        WHERE p.post_type = 'attachment'
            AND p.post_mime_type IN ($placeholders)
            AND p.ID > %d
            AND {$status_filter}
        ORDER BY p.ID ASC
        LIMIT %d
    ";

        $args = array_merge(
            $mime_types,
            array(
                $last_id,
                $limit
            )
        );

        $query = $wpdb->prepare($sql, $args);

        $result = $wpdb->get_results($query);

        if (empty($result))
        {
            return array(
                'count'   => 0,
                'last_id' => 0
            );
        }

        $processed = 0;
        $max_id = 0;
        $exclude = Aero_IO_Options::get_excludes();

        foreach ($result as $image)
        {
            $image_id = (int)$image->ID;

            $max_id = max($max_id, $image_id);
            $processed++;

            $status = isset($image->status)
                ? (string)$image->status
                : '';

            $webp_converted = isset($image->webp_converted)
                ? (int)$image->webp_converted
                : 0;

            $avif_converted = isset($image->avif_converted)
                ? (int)$image->avif_converted
                : 0;


            if (self::should_skip($image_id))
            {
                Aero_IO_Image_Meta_V2::update_image_meta_status(
                    $image_id,
                    'skip'
                );

                continue;
            }


            if ($status === 'optimized')
            {
                continue;
            }


            $file_path = isset($image->attached_file)
                ? (string)$image->attached_file
                : '';

            if (empty($file_path))
            {
                continue;
            }

            if (self::should_exclude_by_path($file_path, $exclude))
            {
                Aero_IO_Image_Meta_V2::update_image_meta_status(
                    $image_id,
                    'skip'
                );

                continue;
            }

            $type = (string)$image->post_mime_type;

            $need_opt = false;


            if ($convert_to_webp && $type !== 'image/avif' && $webp_converted === 0)
            {
                $need_opt = true;
            }


            if ($convert_to_avif && $avif_converted === 0)
            {
                $need_opt = true;
            }


            if ($need_opt)
            {
                Aero_IO_Image_Meta_V2::update_image_meta_status(
                    $image_id,
                    'pending'
                );
            }
        }


        return array(
            'count'   => $processed,
            'last_id' => $max_id,
        );
    }

    public static function get_attached_file_by_meta_value($file)
    {
        if (empty($file))
        {
            return false;
        }

        if (
            0 !== strpos($file, '/') &&
            !preg_match('|^.:\\\\|', $file) &&
            !preg_match('|^[a-zA-Z]:/|', $file)
        )
        {
            $uploads = wp_get_upload_dir();

            if (false === $uploads['error'])
            {
                $file = $uploads['basedir'] . '/' . ltrim($file, '/');
            }
        }

        return $file;
    }

    public static function should_exclude_by_path($relative_file, $exclude)
    {
        if (empty($relative_file) || empty($exclude))
        {
            return false;
        }

        $file_path = self::get_attached_file_by_meta_value($relative_file);

        if (empty($file_path))
        {
            return false;
        }

        return Aero_IO_Image_Method::exclude_path_ex(
            $file_path,
            $exclude
        );
    }
}
