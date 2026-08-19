<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Aero_IO_Stats_Manager
{
    const META_KEY = 'aero_io_image_meta';
    const OPTION_KEY = 'aero_io_global_stats';
    const PROGRESS_KEY = 'aero_io_stats_progress';
    const TRANSIENT_KEY = 'aero_io_set_global_stats';
    const BATCH_SIZE = 1000;
    const MAX_DURATION = 25; // seconds

    public static function init()
    {
        add_action('wp_ajax_aero_io_start_stats', [__CLASS__, 'ajax_start']);
        add_action('wp_ajax_aero_io_get_stats', [__CLASS__, 'ajax_get']);
        add_action('wp_ajax_aero_io_continue_stats', [__CLASS__, 'ajax_continue']);
    }

    public static function ajax_start()
    {
        
        aero_io_ajax_check_security('aero-io-can-convert');
        $cached = get_transient(self::TRANSIENT_KEY);

        if ($cached)
        {
            $data=get_option(self::OPTION_KEY);
            if (empty($data) && is_array($cached))
            {
                // Heal stats computed before the option write existed.
                update_option(self::OPTION_KEY, $cached, false);
                $data = $cached;
            }
            if (!empty($data))
            {
                wp_send_json_success([
                    'message' => 'Cached data available',
                    'status' => 'cached',
                    'cached' => $data,
                ]);
            }
            // Empty payload behind a fresh transient: fall through and
            // recompute from the meta table.
        }

        delete_option(self::OPTION_KEY);
        delete_option(self::PROGRESS_KEY);
        delete_transient(self::TRANSIENT_KEY);

        update_option(self::PROGRESS_KEY, [
            'offset' => 0,
            '_legacy_offset'=>0,
            'accumulated' => [],
            'state' => 'wait',
        ]);

        wp_send_json_success(['status' => 'started']);
    }

    public static function ajax_continue()
    {
        
        aero_io_ajax_check_security('aero-io-can-convert');

        $progress = get_option(self::PROGRESS_KEY);
        if (!$progress || $progress['state'] !== 'wait') {
            wp_send_json_error('Task not ready or already running.');
        }

        $progress['state'] = 'running';
        update_option(self::PROGRESS_KEY, $progress);

        //self::process_batch(self::BATCH_SIZE, $progress['offset'], $progress['accumulated']);
        self::process_batch(self::BATCH_SIZE, $progress['offset'], $progress['_legacy_offset'],$progress['accumulated']);

        wp_send_json_success(['status' => 'processing']);
    }

    public static function ajax_get()
    {
        
        aero_io_ajax_check_security('aero-io-can-convert');

        $progress = get_option(self::PROGRESS_KEY);
        if ($progress) {
            if ($progress['state'] === 'running') {
                wp_send_json_success(['status' => 'executing']);
            }
            if ($progress['state'] === 'wait') {
                wp_send_json_success(['status' => 'in_progress']);
            }
        }

        $final = get_option(self::OPTION_KEY);
        if (empty($final))
        {
            $t = get_transient(self::TRANSIENT_KEY);
            if (is_array($t) && !empty($t))
            {
                update_option(self::OPTION_KEY, $t, false);
                $final = $t;
            }
        }
        if ($final) wp_send_json_success(array_merge(['status' => 'done'], $final));

        wp_send_json_success(['status' => 'not_started']);
    }

    public static function process_batch($batch_size, $offset,$legacy_offset, $accumulated)
    {
        global $wpdb;

        $start_time = time();

        $data = wp_parse_args($accumulated, [
            'converted_webp' => 0,
            'converted_avif' => 0,
            'total_count' => 0,
            'original_total_webp' => 0,
            'original_total_avif' => 0,
            'webp_total' => 0,
            'avif_total' => 0,
        ]);
        Aero_IO_Image_Meta_V2::ensure_table();
        $meta_table = Aero_IO_Image_Meta_V2::table_name();
        while (true)
        {
            $results = $wpdb->get_results($wpdb->prepare("
    SELECT m.attachment_id,
           m.status,
           m.og_file_size,
           m.webp_converted,
           m.avif_converted,
           m.webp_converted_size,
           m.avif_converted_size
    FROM {$meta_table} m
    INNER JOIN {$wpdb->posts} p
        ON m.attachment_id = p.ID
    WHERE p.post_type = 'attachment'
    ORDER BY m.id ASC
    LIMIT %d OFFSET %d
", $batch_size, $offset), ARRAY_A);

            $count_this_batch = count($results);
            if ($count_this_batch === 0) break;

            foreach ($results as $row)
            {
                $status = $row['status'] ?? '';
                if ($status === 'skip')
                {
                    continue;
                }

                $original = (int)($row['og_file_size'] ?? 0);

                if (!empty($row['webp_converted']))
                {
                    $webp = (int)($row['webp_converted_size'] ?? 0);
                    $data['converted_webp']++;
                    if ($original > 0 && $webp > 0 && $webp <= $original)
                    {
                        $data['original_total_webp'] += $original;
                        $data['webp_total'] += $webp;
                    }
                }

                if (!empty($row['avif_converted']))
                {
                    $avif = (int)($row['avif_converted_size'] ?? 0);
                    $data['converted_avif']++;
                    if ($original > 0 && $avif > 0 && $avif <= $original)
                    {
                        $data['original_total_avif'] += $original;
                        $data['avif_total'] += $avif;
                    }
                }

                $data['total_count']++;
            }

            $offset += $batch_size;
            if ((time() - $start_time) >= self::MAX_DURATION)
            {
                update_option(self::PROGRESS_KEY, [
                    'offset' => $offset,
                    '_legacy_offset'=> $legacy_offset,
                    'accumulated' => $data,
                    'state' => 'wait',
                ]);
                return;
            }
        }

        while (true)
        {
            $legacy_results = $wpdb->get_results($wpdb->prepare("
            SELECT pm.post_id, pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p
                ON pm.post_id = p.ID
            LEFT JOIN {$meta_table} m
                ON m.attachment_id = pm.post_id
            WHERE pm.meta_key = %s
              AND p.post_type = 'attachment'
              AND m.attachment_id IS NULL
            ORDER BY pm.post_id ASC
            LIMIT %d OFFSET %d
        ", self::META_KEY, $batch_size, $legacy_offset), ARRAY_A);

            $count_this_batch = count($legacy_results);
            if ($count_this_batch === 0) {
                break;
            }

            foreach ($legacy_results as $row)
            {
                $image_id = (int)$row['post_id'];
                $meta = maybe_unserialize($row['meta_value']);
                if (!is_array($meta)) {
                    continue;
                }

                Aero_IO_Image_Meta_V2::upgrade_image_meta($image_id);

                $status = $meta['status'] ?? '';
                if ($status === 'skip')
                {
                    continue;
                }

                $original = isset($meta['og_file_size']) ? (int)$meta['og_file_size'] : 0;

                if (!empty($meta['webp_converted']) && (int)$meta['webp_converted'] === 1)
                {
                    $webp = isset($meta['webp_converted_size']) ? (int)$meta['webp_converted_size'] : 0;
                    $data['converted_webp']++;
                    if ($original > 0 && $webp > 0 && $webp <= $original)
                    {
                        $data['original_total_webp'] += $original;
                        $data['webp_total'] += $webp;
                    }
                }

                if (!empty($meta['avif_converted']) && (int)$meta['avif_converted'] === 1)
                {
                    $avif = isset($meta['avif_converted_size']) ? (int)$meta['avif_converted_size'] : 0;
                    $data['converted_avif']++;
                    if ($original > 0 && $avif > 0 && $avif <= $original)
                    {
                        $data['original_total_avif'] += $original;
                        $data['avif_total'] += $avif;
                    }
                }

                $data['total_count']++;
            }

            $legacy_offset += $batch_size;

            if ((time() - $start_time) >= self::MAX_DURATION)
            {
                update_option(self::PROGRESS_KEY, [
                    'offset' => $offset,
                    '_legacy_offset' =>$legacy_offset,
                    'accumulated' => $data,
                    'state' => 'wait',
                    'phase' => 'legacy',
                ]);
                return;
            }
        }


        Aero_IO_Options::delete_option(self::PROGRESS_KEY);
        delete_option(self::PROGRESS_KEY);

        $webp_saved = $data['original_total_webp'] - $data['webp_total'];
        $avif_saved = $data['original_total_avif'] - $data['avif_total'];

        $percent_webp = $data['original_total_webp'] > 0 ? ($webp_saved / $data['original_total_webp']) * 100 : 0;
        $percent_avif = $data['original_total_avif'] > 0 ? ($avif_saved / $data['original_total_avif']) * 100 : 0;

        $max_webp_count=self::get_max_webp_image_count();
        $max_avif_count=self::get_max_avif_image_count();
        $conversion_webp = $max_webp_count > 0 ? ($data['converted_webp'] / $max_webp_count) * 100 : 0;
        $conversion_avif = $max_avif_count > 0 ? ($data['converted_avif'] / $max_avif_count) * 100 : 0;

        $final = [
            'conversion_webp_percent' => round($conversion_webp, 2),
            'conversion_avif_percent' => round($conversion_avif, 2),
            'space_saved_webp_percent' => round($percent_webp, 2),
            'space_saved_avif_percent' => round($percent_avif, 2),
            'converted_webp' => $data['converted_webp'],
            'converted_avif' => $data['converted_avif'],
            'total_count' => $data['total_count'],
            'saved_webp_size' => $webp_saved,
            'saved_avif_size' => $avif_saved,
            'webp_total' => $data['webp_total'],
            'avif_total' => $data['avif_total'],
            'webp_total_fomat' => size_format($data['webp_total'],2),
            'avif_total_fomat' => size_format($data['avif_total'],2),
            'original_total_webp' => $data['original_total_webp'],
            'original_total_avif' => $data['original_total_avif'],
            'calculated_at' => time(),
        ];

        // Persist the aggregate to the option BOTH readers consume
        // (ajax_start's cached branch and ajax_get's done branch). The
        // upstream engine only stored it in the freshness transient, so
        // every reader served the never-written option: permanent zeros.
        update_option(self::OPTION_KEY, $final, false);
        set_transient(self::TRANSIENT_KEY, $final, MINUTE_IN_SECONDS * 10);
    }

    public static function get_max_webp_image_count()
    {
        global $wpdb;

        $supported_mime_types = array(
            "image/jpg",
            "image/jpeg",
            "image/png",
            "image/webp",);

        //$supported_mime_types=apply_filters('aero_io_supported_mime_types',$supported_mime_types);

        $result=$wpdb->get_results( $wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type IN (%s,%s,%s,%s) ",$supported_mime_types),ARRAY_N);
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
            "image/webp",
            "image/avif");

        //$supported_mime_types=apply_filters('aero_io_supported_mime_types',$supported_mime_types);

        $result=$wpdb->get_results($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type IN (%s,%s,%s,%s,%s) ",$supported_mime_types),ARRAY_N);
        if($result && sizeof($result)>0)
        {
            return $result[0][0];
        }
        else
        {
            return 0;
        }
    }
}