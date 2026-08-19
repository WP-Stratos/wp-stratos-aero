<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Aero_IO_Image_Meta_V2
{
    const TABLE_SLUG = 'aero_io_images_meta';
    const DB_VERSION = 1;

    private static $ready = false;

    public static function table_name()
    {
        global $wpdb;
        return $wpdb->base_prefix . self::TABLE_SLUG;
    }

    private static function get_blog_id()
    {
        if (is_multisite())
        {
            return (int)get_current_blog_id();
        }
        return 1;
    }

    public static function ensure_table()
    {
        if (self::$ready)
        {
            return;
        }

        global $wpdb;
        $table_name =self::table_name();

        $exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $table_name)
        );

        if ($exists !== $table_name)
        {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$table_name} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                attachment_id BIGINT(20) UNSIGNED NOT NULL,

                mime_type VARCHAR(40) NOT NULL DEFAULT '',

                status VARCHAR(20) NOT NULL DEFAULT '',
                webp_converted TINYINT(1) NOT NULL DEFAULT 0,
                avif_converted TINYINT(1) NOT NULL DEFAULT 0,

                og_file_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                webp_converted_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                avif_converted_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                
                sum_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                webp_sum_converted_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                avif_sum_converted_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                
                resize_status TINYINT(1) NOT NULL DEFAULT 0,

                details LONGTEXT NULL,

                updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
                created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
                data_version SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,

                PRIMARY KEY  (id),
                UNIQUE KEY uniq_blog_attachment (blog_id, attachment_id),
                KEY idx_mime_type (mime_type),
                KEY idx_status (status),
                KEY idx_webp (webp_converted),
                KEY idx_avif (avif_converted),
                KEY idx_updated_at (updated_at)
            ) {$charset_collate};";

            dbDelta($sql);
        }

        self::$ready = true;
    }

    private static function get_row_id($image_id)
    {
        self::ensure_table();
        global $wpdb;
        $table = self::table_name();
        $blog_id = self::get_blog_id();
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE blog_id=%d AND attachment_id=%d LIMIT 1", $blog_id, $image_id)
        );
    }

    private static function get_row($image_id)
    {
        self::ensure_table();

        global $wpdb;
        $table = self::table_name();
        $blog_id = self::get_blog_id();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE blog_id=%d AND attachment_id=%d LIMIT 1",
                $blog_id,
                $image_id
            ),
            ARRAY_A
        );

        return $row ? $row : false;
    }

    private static function row_to_meta($row)
    {
        $meta = array();

        if (!empty($row['details']))
        {
            $decoded = json_decode($row['details'], true);
            if (is_array($decoded))
            {
                $meta = $decoded;
            }
        }

        $meta['status'] = isset($row['status']) ? $row['status'] : '';
        $meta['webp_converted'] = isset($row['webp_converted']) ? (int)$row['webp_converted'] : 0;
        $meta['avif_converted'] = isset($row['avif_converted']) ? (int)$row['avif_converted'] : 0;

        $meta['og_file_size'] = isset($row['og_file_size']) ? (int)$row['og_file_size'] : 0;
        $meta['webp_converted_size'] = isset($row['webp_converted_size']) ? (int)$row['webp_converted_size'] : 0;
        $meta['avif_converted_size'] = isset($row['avif_converted_size']) ? (int)$row['avif_converted_size'] : 0;

        $meta['sum_size'] = isset($row['sum_size']) ? (int)$row['sum_size'] : 0;
        $meta['webp_sum_converted_size'] = isset($row['webp_sum_converted_size']) ? (int)$row['webp_sum_converted_size'] : 0;
        $meta['avif_sum_converted_size'] = isset($row['avif_sum_converted_size']) ? (int)$row['avif_sum_converted_size'] : 0;


        $meta['resize_status'] = isset($row['resize_status']) ? (int)$row['resize_status'] : 0;

        $meta['mime_type'] = isset($row['mime_type']) ? $row['mime_type'] : '';

        return $meta;
    }

    private static function upgrade_row($image_id, $meta)
    {
        self::ensure_table();

        global $wpdb;
        $table = self::table_name();
        $blog_id = self::get_blog_id();
        $post = get_post($image_id);
        $mime_type = '';
        if ($post && $post->post_type === 'attachment')
        {
            $mime_type = (string)$post->post_mime_type;
        }

        $details = self::fix_meta($meta);

        $now = current_time('mysql');

        $data = array(
            'blog_id' => (int)$blog_id,
            'attachment_id' => (int)$image_id,

            'mime_type' => $mime_type,

            'status' => isset($meta['status']) ? (string)$meta['status'] : '',
            'webp_converted' => !empty($meta['webp_converted']) ? 1 : 0,
            'avif_converted' => !empty($meta['avif_converted']) ? 1 : 0,

            'og_file_size' => isset($meta['og_file_size']) ? (int)$meta['og_file_size'] : 0,
            'webp_converted_size' => isset($meta['webp_converted_size']) ? (int)$meta['webp_converted_size'] : 0,
            'avif_converted_size' => isset($meta['avif_converted_size']) ? (int)$meta['avif_converted_size'] : 0,

            'sum_size' =>  0,
            'webp_sum_converted_size' =>  0,
            'avif_sum_converted_size' =>  0,


            'resize_status' => isset($meta['resize_status']) ? (int)$meta['resize_status'] : 0,

            'details' => wp_json_encode($details),
            'updated_at' => $now,
        );

        $row_id = self::get_row_id($image_id);

        if ($row_id > 0)
        {
            $wpdb->update($table, $data, array('id' => $row_id));
        } else {
            $data['created_at'] = $now;
            $wpdb->insert($table, $data);
        }

        return true;
    }

    private static function upsert_row($image_id, $meta)
    {
        self::ensure_table();

        global $wpdb;
        $table = self::table_name();

        $now = current_time('mysql');
        $blog_id = self::get_blog_id();
        $data = array(
            'blog_id' => (int)$blog_id,
            'attachment_id' => (int)$image_id,
            'mime_type' =>  isset($meta['mime_type']) ? (string)$meta['mime_type'] : '',
            'status' => isset($meta['status']) ? (string)$meta['status'] : '',
            'webp_converted' => !empty($meta['webp_converted']) ? 1 : 0,
            'avif_converted' => !empty($meta['avif_converted']) ? 1 : 0,

            'og_file_size' => isset($meta['og_file_size']) ? (int)$meta['og_file_size'] : 0,
            'webp_converted_size' => isset($meta['webp_converted_size']) ? (int)$meta['webp_converted_size'] : 0,
            'avif_converted_size' => isset($meta['avif_converted_size']) ? (int)$meta['avif_converted_size'] : 0,

            'sum_size' => isset($meta['sum_size']) ? (int)$meta['sum_size'] : 0,
            'webp_sum_converted_size' => isset($meta['webp_sum_converted_size']) ? (int)$meta['webp_sum_converted_size'] : 0,
            'avif_sum_converted_size' => isset($meta['avif_sum_converted_size']) ? (int)$meta['avif_sum_converted_size'] : 0,

            'resize_status' => isset($meta['resize_status']) ? (int)$meta['resize_status'] : 0,

            'details' => wp_json_encode($meta),
            'updated_at' => $now,
        );

        $row_id = self::get_row_id($image_id);

        if ($row_id > 0)
        {
            $result = $wpdb->update($table, $data, array('id' => $row_id));
        } else {
            $data['created_at'] = $now;
            $result = $wpdb->insert($table, $data);
        }

        return ($result !== false);
    }

    public static function fix_meta($old_meta)
    {
        unset($old_meta['quality']);
        unset($old_meta['compressed']);
        unset($old_meta['compressed_size']);

        return $old_meta;
    }

    private static function delete_legacy_meta($image_id)
    {
        delete_post_meta($image_id,'aero_io_image_meta');
        delete_post_meta($image_id,'aero_io_image_progressing');
        delete_post_meta($image_id,'aero_io_image_meta_status');

        delete_post_meta($image_id,'aero_io_image_meta_webp_converted');
        delete_post_meta($image_id,'aero_io_image_meta_avif_converted');
        delete_post_meta($image_id,'aero_io_image_meta_compressed');

        delete_post_meta($image_id,'aero_io_image_meta_og_file_size');
        delete_post_meta($image_id,'aero_io_image_meta_webp_converted_size');
        delete_post_meta($image_id,'aero_io_image_meta_avif_converted_size');
        delete_post_meta($image_id,'aero_io_image_meta_compressed_size');
    }

    public static function upgrade_image_meta($image_id)
    {
        self::ensure_table();

        if (self::get_row_id($image_id) > 0)
        {
            return true;
        }

        $meta = get_post_meta($image_id, 'aero_io_image_meta', true);
        if (!is_array($meta) || empty($meta))
        {
            return false;
        }

        if(self::upgrade_row($image_id, $meta))
        {
            self::delete_legacy_meta($image_id);

            return true;
        }
        else
        {
            return false;
        }
    }

    public static function has_image_meta($image_id)
    {
        self::ensure_table();
        if (self::get_row_id($image_id) > 0)
        {
            return true;
        }

        if(empty(get_post_meta( $image_id, 'aero_io_image_meta', true )))
        {
            return false;
        }
        else
        {
            self::upgrade_image_meta($image_id);
            return true;
        }
    }

    public static function get_image_meta($image_id)
    {
        self::ensure_table();

        $row = self::get_row($image_id);
        if ($row)
        {
            return self::row_to_meta($row);
        }

        $legacy = get_post_meta($image_id, 'aero_io_image_meta', true);
        if (empty($legacy))
        {
            return $legacy;
        }

        // 3) upgrade
        self::upgrade_image_meta($image_id);

        // 4) read again
        $row = self::get_row($image_id);
        if ($row)
        {
            return self::row_to_meta($row);
        }

        return $legacy;
    }

    public static function get_image_meta_value($image_id,$key)
    {
        $meta = self::get_image_meta($image_id);

        if (empty($meta) || !is_array($meta))
        {
            return false;
        }

        if (isset($meta[$key]))
        {
            return $meta[$key];
        }

        return false;
    }

    public static function generate_images_meta($image_id, $options=array())
    {
        self::ensure_table();

        $file_path = get_attached_file($image_id);
        $image_optimize_meta = array();

        delete_post_meta($image_id, 'aero_io_image_meta_webp_converted');
        delete_post_meta($image_id, 'aero_io_image_meta_avif_converted');
        delete_post_meta($image_id, 'aero_io_image_meta_compressed');
        delete_post_meta($image_id, 'aero_io_image_meta_og_file_size');
        delete_post_meta($image_id, 'aero_io_image_meta_webp_converted_size');
        delete_post_meta($image_id, 'aero_io_image_meta_avif_converted_size');
        delete_post_meta($image_id, 'aero_io_image_meta_compressed_size');
        delete_post_meta($image_id, 'aero_io_image_meta_status');
        delete_post_meta($image_id, 'aero_io_image_meta');

        $post = get_post($image_id);
        $image_optimize_meta['mime_type'] = '';
        if ($post && $post->post_type === 'attachment')
        {
            $image_optimize_meta['mime_type'] = (string)$post->post_mime_type;
        }

        $image_optimize_meta['status'] = 'unoptimized';
        $image_optimize_meta['webp_converted'] = 0;
        $image_optimize_meta['avif_converted'] = 0;
        // $image_optimize_meta['compressed'] = 0;

        $image_optimize_meta['resize_status'] = 0;
        $image_optimize_meta['compress_status'] = 0;

        // $image_optimize_meta['quality'] = $options['quality'];

        $image_optimize_meta['og_file_size'] = file_exists($file_path) ? (int)filesize($file_path) : 0;

        $image_optimize_meta['webp_converted_size'] = 0;
        $image_optimize_meta['avif_converted_size'] = 0;

        $image_optimize_meta['sum_size'] = 0;
        $image_optimize_meta['webp_sum_converted_size'] = 0;
        $image_optimize_meta['avif_sum_converted_size'] = 0;

        $meta = wp_get_attachment_metadata($image_id, true);

        // og
        $image_optimize_meta['size']['og'] = array(
            'convert_webp_status' => 0,
            'convert_avif_status' => 0,
            'status' => 'unoptimized',
            'error' => '',
            'file' => get_post_meta($image_id, '_wp_attached_file', true),
        );

        // thumbnails
        if (!empty($meta['sizes']) && is_array($meta['sizes']))
        {
            foreach ($meta['sizes'] as $size_key => $size_data)
            {
                $image_optimize_meta['size'][$size_key] = array(
                    'convert_webp_status' => 0,
                    'convert_avif_status' => 0,
                    'file_size'=>0,
                    'webp_converted_size'=>0,
                    'avif_converted_size'=>0,
                    'status' => 'unoptimized',
                    'error' => '',
                    'file' => isset($size_data['file']) ? $size_data['file'] : '',
                );
            }
        }

        self::upsert_row($image_id, $image_optimize_meta);

        return $image_optimize_meta;
    }

    public static function update_images_meta_value($image_id, $key, $value)
    {
        $image_optimize_meta = self::get_image_meta($image_id);
        if (empty($image_optimize_meta) || !is_array($image_optimize_meta)) {
            $image_optimize_meta = array();
        }

        $image_optimize_meta[$key] = $value;

        self::update_images_meta($image_id, $image_optimize_meta);
    }

    public static function update_image_meta_size($image_id, $size_key, $size_meta)
    {
        $image_optimize_meta = self::get_image_meta($image_id);
        if (empty($image_optimize_meta) || !is_array($image_optimize_meta)) {
            $image_optimize_meta = array();
        }

        if (!isset($image_optimize_meta['size']) || !is_array($image_optimize_meta['size'])) {
            $image_optimize_meta['size'] = array();
        }

        $image_optimize_meta['size'][$size_key] = $size_meta;

        self::update_images_meta($image_id, $image_optimize_meta);
    }

    private static function update_row($image_id, $meta)
    {
        self::ensure_table();

        global $wpdb;
        $table = self::table_name();

        $row_id = self::get_row_id($image_id);
        if ($row_id <= 0)
        {
            return false;
        }

        $now = current_time('mysql');

        $data = array(
            'mime_type' => isset($meta['mime_type']) ? (string)$meta['mime_type'] : '',
            'status' => isset($meta['status']) ? (string)$meta['status'] : '',
            'webp_converted' => !empty($meta['webp_converted']) ? 1 : 0,
            'avif_converted' => !empty($meta['avif_converted']) ? 1 : 0,

            'og_file_size' => isset($meta['og_file_size']) ? (int)$meta['og_file_size'] : 0,
            'webp_converted_size' => isset($meta['webp_converted_size']) ? (int)$meta['webp_converted_size'] : 0,
            'avif_converted_size' => isset($meta['avif_converted_size']) ? (int)$meta['avif_converted_size'] : 0,

            'sum_size' => isset($meta['sum_size']) ? (int)$meta['sum_size'] : 0,
            'webp_sum_converted_size' => isset($meta['webp_sum_converted_size']) ? (int)$meta['webp_sum_converted_size'] : 0,
            'avif_sum_converted_size' => isset($meta['avif_sum_converted_size']) ? (int)$meta['avif_sum_converted_size'] : 0,

            'resize_status' => isset($meta['resize_status']) ? (int)$meta['resize_status'] : 0,

            'details' => wp_json_encode($meta),
            'updated_at' => $now,
        );

        $result = $wpdb->update($table, $data, array('id' => $row_id));

        return ($result !== false);
    }

    public static function update_images_meta($image_id, $meta)
    {
        self::ensure_table();

        $current = self::get_image_meta($image_id);
        if (empty($current) || !is_array($current))
        {
            return false;
        }

        if (is_array($meta))
        {
            $meta = array_replace_recursive($current, $meta);
        } else {
            $meta = $current;
        }

        if (!isset($meta['mime_type']) || $meta['mime_type'] === '')
        {
            $post = get_post($image_id);
            if ($post && $post->post_type === 'attachment') {
                $meta['mime_type'] = (string)$post->post_mime_type;
            }
        }

        return self::update_row($image_id, $meta);
    }

    public static function delete_image_meta($image_id)
    {
        self::ensure_table();

        global $wpdb;
        $table = self::table_name();
        $blog_id = self::get_blog_id();
        $result = $wpdb->delete(
            $table,
            array(
                'blog_id' => (int)$blog_id,
                'attachment_id' => (int)$image_id
            ),
            array('%d', '%d')
        );

        self::delete_legacy_meta($image_id);

        return ($result !== false);
    }

    public static function delete_all_image_meta()
    {
        self::ensure_table();

        global $wpdb;
        $table = self::table_name();

        $result = $wpdb->query("TRUNCATE TABLE {$table}");
        return ($result !== false);
    }

    private static function get_row_value($image_id, $column)
    {
        self::ensure_table();

        $allowed = array(
            'status',
            'mime_type',
            'webp_converted',
            'avif_converted',
            'og_file_size',
            'webp_converted_size',
            'avif_converted_size',
            'og_file_size',
            'webp_converted_size',
            'avif_converted_size',

            'resize_status',
            'updated_at',
            'created_at',
            'data_version',
        );

        if (!in_array($column, $allowed, true)) {
            return null;
        }

        global $wpdb;
        $table = self::table_name();

        $sql = "SELECT {$column} FROM {$table} WHERE blog_id=%d AND attachment_id=%d LIMIT 1";
        $blog_id = self::get_blog_id();
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $value = $wpdb->get_var($wpdb->prepare($sql, $blog_id, $image_id));

        return $value;
    }

    public static function get_image_meta_status($image_id)
    {
        $status = self::get_row_value($image_id, 'status');
        if (!is_null($status) && $status !== '')
        {
            return (string)$status;
        }

        $meta = self::get_image_meta($image_id);
        if (is_array($meta) && isset($meta['status']))
        {
            return (string)$meta['status'];
        }

        return '';
    }

    public static function update_image_meta_status($image_id, $status)
    {
        if(!self::has_image_meta($image_id))
        {
            self::generate_images_meta($image_id);
        }

        $meta['status'] = $status;

        return self::update_images_meta($image_id, $meta);
    }

    public static function get_image_meta_webp_converted($image_id)
    {
        $val = self::get_row_value($image_id, 'webp_converted');
        if (!is_null($val)) {
            return (int)$val;
        }

        // fallback -> trigger upgrade if legacy exists
        $meta = self::get_image_meta($image_id);
        if (!is_array($meta)) {
            return 0;
        }

        $val = self::get_row_value($image_id, 'webp_converted');
        if (!is_null($val)) {
            return (int)$val;
        }

        return isset($meta['webp_converted']) ? (int)$meta['webp_converted'] : 0;
    }

    public static function get_image_meta_avif_converted($image_id)
    {
        $val = self::get_row_value($image_id, 'avif_converted');
        if (!is_null($val)) {
            return (int)$val;
        }

        $meta = self::get_image_meta($image_id);
        if (!is_array($meta)) {
            return 0;
        }

        $val = self::get_row_value($image_id, 'avif_converted');
        if (!is_null($val)) {
            return (int)$val;
        }

        return isset($meta['avif_converted']) ? (int)$meta['avif_converted'] : 0;
    }

    public static function get_og_size($image_id)
    {
        $val = self::get_row_value($image_id, 'og_file_size');
        if (!is_null($val)) {
            return (int)$val;
        }

        $meta = self::get_image_meta($image_id);
        if (!is_array($meta)) {
            return 0;
        }

        $val = self::get_row_value($image_id, 'og_file_size');
        if (!is_null($val)) {
            return (int)$val;
        }

        return isset($meta['og_file_size']) ? (int)$meta['og_file_size'] : 0;
    }

    public static function get_webp_converted_size($image_id)
    {
        $val = self::get_row_value($image_id, 'webp_converted_size');
        if (!is_null($val)) {
            return (int)$val;
        }

        $meta = self::get_image_meta($image_id);
        if (!is_array($meta)) {
            return 0;
        }

        $val = self::get_row_value($image_id, 'webp_converted_size');
        if (!is_null($val)) {
            return (int)$val;
        }

        return isset($meta['webp_converted_size']) ? (int)$meta['webp_converted_size'] : 0;
    }

    public static function get_avif_converted_size($image_id)
    {
        $val = self::get_row_value($image_id, 'avif_converted_size');
        if (!is_null($val)) {
            return (int)$val;
        }

        $meta = self::get_image_meta($image_id);
        if (!is_array($meta)) {
            return 0;
        }

        $val = self::get_row_value($image_id, 'avif_converted_size');
        if (!is_null($val)) {
            return (int)$val;
        }

        return isset($meta['avif_converted_size']) ? (int)$meta['avif_converted_size'] : 0;
    }

    public static function update_webp_image_converted($image_id)
    {
        $meta = self::get_image_meta($image_id);
        $meta['webp_converted']=1;

        $size=$meta['size'];

        $meta['webp_converted_size'] = 0;

        $meta['sum_size'] = 0;
        $meta['webp_sum_converted_size'] =  0;

        foreach ($size as $size_key=>$size_meta)
        {
            if($size_meta['convert_webp_status']==0)
            {
                continue;
            }

            if($size_key=="og")
            {
                $meta['webp_converted_size'] = $size_meta['webp_converted_size'];
            }

            $meta['sum_size'] += $size_meta['file_size'];
            $meta['webp_sum_converted_size'] += $size_meta['webp_converted_size'];
        }

        return self::update_images_meta($image_id, $meta);
    }

    public static function update_image_meta_webp_converted($image_id, $converted)
    {
        $meta = self::get_image_meta($image_id);
        if (empty($meta) || !is_array($meta)) {
            return false;
        }

        $meta['webp_converted'] = !empty($converted) ? 1 : 0;

        return self::update_images_meta($image_id, $meta);
    }

    public static function update_avif_image_converted($image_id)
    {
        $meta = self::get_image_meta($image_id);
        $meta['avif_converted']=1;

        $size=$meta['size'];

        $meta['avif_converted_size'] = 0;

        $meta['sum_size'] = 0;
        $meta['avif_sum_converted_size'] =  0;

        foreach ($size as $size_key=>$size_meta)
        {
            if($size_meta['convert_avif_status']==0)
            {
                continue;
            }

            if($size_key=="og")
            {
                $meta['avif_converted_size'] = $size_meta['avif_converted_size'];
            }

            $meta['sum_size'] += $size_meta['file_size'];
            $meta['avif_sum_converted_size'] += $size_meta['avif_converted_size'];
        }

        return self::update_images_meta($image_id, $meta);
    }

    public static function update_image_meta_avif_converted($image_id, $converted)
    {
        $meta = self::get_image_meta($image_id);
        if (empty($meta) || !is_array($meta)) {
            return false;
        }

        $meta['avif_converted'] = !empty($converted) ? 1 : 0;

        return self::update_images_meta($image_id, $meta);
    }

    public static function update_webp_converted_size($image_id, $convert_size)
    {
        $meta = self::get_image_meta($image_id);
        if (empty($meta) || !is_array($meta)) {
            return false;
        }

        $meta['webp_converted_size'] = max(0, (int)$convert_size);

        return self::update_images_meta($image_id, $meta);
    }

    public static function update_avif_converted_size($image_id, $convert_size)
    {
        $meta = self::get_image_meta($image_id);
        if (empty($meta) || !is_array($meta)) {
            return false;
        }

        $meta['avif_converted_size'] = max(0, (int)$convert_size);

        return self::update_images_meta($image_id, $meta);
    }

    public static function update_og_size($image_id, $size)
    {
        $meta = self::get_image_meta($image_id);
        if (empty($meta) || !is_array($meta)) {
            return false;
        }

        $meta['og_file_size'] = max(0, (int)$size);

        return self::update_images_meta($image_id, $meta);
    }

    public static function is_image_optimized($image_id)
    {
        if(self::get_image_meta_status($image_id)==='optimized')
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public static function has_optimized_file($image_id)
    {
        $webp_converted= self::get_image_meta_webp_converted($image_id);
        if($webp_converted)
        {
            return true;
        }

        $avif_converted= self::get_image_meta_avif_converted($image_id);

        if($avif_converted)
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public static function is_webp_image($image_id)
    {
        $file_path = get_attached_file($image_id);
        $type=pathinfo($file_path, PATHINFO_EXTENSION);

        if($type=='webp')
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public static function is_avif_image($image_id)
    {
        $file_path = get_attached_file($image_id);
        $type=pathinfo($file_path, PATHINFO_EXTENSION);

        if($type=='avif')
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public static function update_image_failed($image_id, $error)
    {
        $meta = self::get_image_meta($image_id);
        if (empty($meta) || !is_array($meta)) {
            return false;
        }

        $meta['status'] = 'failed';

        if (!isset($meta['size']) || !is_array($meta['size'])) {
            $meta['size'] = array();
        }

        if (!isset($meta['size']['og']) || !is_array($meta['size']['og'])) {
            $meta['size']['og'] = array();
        }

        $meta['size']['og']['status'] = 'failed';
        $meta['size']['og']['error'] = (string)$error;

        return self::update_images_meta($image_id, $meta);
    }

    public static function get_global_stats_ex()
    {
        self::ensure_table();

        $update = get_transient('aero_io_set_global_stats');
        $stats  = Aero_IO_Options::get_option('aero_io_global_stats_ex', array());

        if (empty($stats) || empty($update) || !isset($stats['converted_percent']))
        {
            $stats = array();
            $stats['converted_percent'] = 0;

            $images_count = Aero_IO_Image_Method::get_max_image_count();

            global $wpdb;
            $table = self::table_name();

            $blog_id = self::get_blog_id();

            $new_count = (int)$wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE blog_id=%d AND status=%s",
                    $blog_id,
                    'optimized'
                )
            );

            $old_only_count = (int)$wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT pm.post_id)
         FROM {$wpdb->postmeta} pm
         WHERE pm.meta_key=%s AND pm.meta_value=%s
         AND NOT EXISTS (
            SELECT 1 FROM {$table} t
            WHERE t.blog_id=%d AND t.attachment_id=pm.post_id
         )",
                    'aero_io_image_meta_status',
                    'optimized',
                    $blog_id
                )
            );

            $converted_images = $new_count + $old_only_count;
            if ($converted_images > $images_count)
            {
                $converted_images = $images_count;
            }

            if ($images_count > 0)
            {
                $stats['converted_percent'] = ($converted_images / $images_count) * 100;
                $stats['converted_percent'] = round($stats['converted_percent'], 2);
            }
            else
            {
                $stats['converted_percent'] = 0;
            }

            delete_transient('aero_io_set_global_stats');
            Aero_IO_Options::update_option('aero_io_global_stats_ex', $stats);

            return $stats;
        }
        else
        {
            return $stats;
        }
    }

    public static function get_image_progressing($image_id)
    {
        return get_post_meta( $image_id, 'aero_io_image_progressing', true );
    }

    public static function update_image_progressing($image_id)
    {
        update_post_meta($image_id,'aero_io_image_progressing',time());
    }

    public static function delete_image_progressing($image_id)
    {
        delete_post_meta($image_id,'aero_io_image_progressing');
    }

    public static function clear_image_meta_status()
    {
        self::ensure_table();

        global $wpdb;

        $table = self::table_name();
        $blog_id = self::get_blog_id();

        return $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
             SET status = %s,
                 updated_at = %s
             WHERE blog_id = %d
               AND status = %s",
                'unoptimized',
                current_time('mysql'),
                $blog_id,
                'skip'
            )
        );
    }
}