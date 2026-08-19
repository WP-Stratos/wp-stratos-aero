<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Aero_IO_Log
{
    public $log_file;
    public $log_file_handle;

    public function __construct()
    {
        $this->log_file_handle=false;
    }

    public function fopen($filename,$mode)
    {
        return @fopen($filename,$mode);
    }

    public function fwrite($handle,$text)
    {
        if($handle)
        {
            return @fwrite($handle,$text );
        }
        else
        {
            return false;
        }
    }

    public function CreateLogFile($file_name='')
    {
        if(empty($file_name))
        {
            $offset=get_option('gmt_offset');
            $localtime = time() + $offset * 60 * 60;
            $file_name='aero_io_'.gmdate('Ymd',$localtime).'_log.txt';
        }

        $this->log_file=$this->GetSaveLogFolder().$file_name;
        $this->log_file_handle = $this->fopen($this->log_file, 'a');
        if($this->log_file_handle===false)
        {
            return false;
        }
        $text="====================================================\n";
        $time =gmdate("Y-m-d H:i:s",time());
        $text.='open log file: '.$time."\n";
        $this->fwrite($this->log_file_handle,$text);
        return $this->log_file;
    }

    public function OpenLogFile($file_name='')
    {
        if(empty($file_name))
        {
            $offset=get_option('gmt_offset');
            $localtime = time() + $offset * 60 * 60;
            $file_name='aero_io_'.gmdate('Ymd',$localtime).'_log.txt';
        }

        $this->log_file=$this->GetSaveLogFolder().$file_name;

        $this->log_file_handle = $this->fopen($this->log_file, 'a');

        return $this->log_file;
    }

    public function WriteLog($log,$type)
    {
        if ($this->log_file_handle)
        {
            $time =gmdate("Y-m-d H:i:s",time());
            $text='['.$time.']'.'['.$type.']'.$log."\n";
            $this->fwrite($this->log_file_handle,$text );
        }
    }

    public function GetlastLog()
    {
        if(empty($file_name))
        {
            $offset=get_option('gmt_offset');
            $localtime = time() + $offset * 60 * 60;
            $file_name='aero_io_'.gmdate('Ymd',$localtime).'_log.txt';
        }

        $this->log_file=$this->GetSaveLogFolder().$file_name;
        $file = file($this->log_file);
        $text='';
        for ($i = max(0, count($file)-1); $i < count($file); $i++)
        {
            $text.= $file[$i] . "\n";
        }
        return $text;
    }

    public function GetSaveLogFolder()
    {
        $path=WP_CONTENT_DIR.'/aero-images/'.'log';

        if(!is_dir($path))
        {
            wp_mkdir_p($path);
            $this->fopen($path.DIRECTORY_SEPARATOR.'index.html', 'x');
            $tempfile=$this->fopen($path.DIRECTORY_SEPARATOR.'.htaccess', 'x');
            if($tempfile)
            {
                $text="deny from all";
                $this->fwrite($tempfile,$text );
            }
        }

        return $path.'/';
    }

    public function get_logs()
    {
        $dir=$this->GetSaveLogFolder();

        $files = scandir($dir, SCANDIR_SORT_DESCENDING);

        $log_files=array();

        $regex='#^aero_io.*_log.txt#';
        foreach ($files as $filename)
        {
            if(preg_match($regex,$filename))
            {
                $log_files[] = $dir.DIRECTORY_SEPARATOR.$filename;
            }

            if(sizeof($log_files)>=5)
            {
                break;
            }
        }

        return $log_files;
    }
}

class Aero_IO_Log_Ex
{
    private static $instance = null;

    public $log_file = '';
    public $log_file_handle = false;

    private function __construct() {}

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function fopen($filename,$mode)
    {
        return @fopen($filename,$mode);
    }

    public function fwrite($handle,$text)
    {
        if($handle)
        {
            return @fwrite($handle,$text );
        }
        else
        {
            return false;
        }
    }

    public function CreateLogFile($file_name = '')
    {
        if ($this->log_file_handle && !empty($this->log_file)) {
            return $this->log_file;
        }

        if (empty($file_name)) {
            $offset = get_option('gmt_offset');
            $localtime = time() + $offset * 3600;
            $file_name = 'aero_io_' . gmdate('Ymd', $localtime) . '_log.txt';
        }

        $this->log_file = $this->GetSaveLogFolder() . $file_name;
        $this->log_file_handle = $this->fopen($this->log_file, 'a');

        if ($this->log_file_handle === false) {
            return false;
        }

        $this->write_open_header();
        return $this->log_file;
    }

    public function OpenLogFile($file_name = '')
    {
        if (empty($file_name)) {
            $offset = get_option('gmt_offset');
            $localtime = time() + $offset * 3600;
            $file_name = 'aero_io_' . gmdate('Ymd', $localtime) . '_log.txt';
        }

        $this->log_file = $this->GetSaveLogFolder() . $file_name;
        $this->log_file_handle = $this->fopen($this->log_file, 'a');

        return $this->log_file;
    }

    public function use_log_file($file_name)
    {
        $this->log_file = $this->GetSaveLogFolder() . $file_name;

        if (!$this->log_file_handle) {
            $this->log_file_handle = $this->fopen($this->log_file, 'a');
            $this->write_open_header();
        }
        return $this->log_file;
    }

    public function WriteLog($log, $type)
    {
        if (!$this->ensure_handle_open()) {
            return;
        }

        $time = gmdate("Y-m-d H:i:s", time());
        $text = '[' . $time . '][' . $type . '] ' . $log . "\n";

        $this->fwrite($this->log_file_handle, $text);
    }

    public function GetlastLog()
    {
        if (empty($this->log_file)) {
            return '';
        }

        if (!file_exists($this->log_file)) {
            return '';
        }

        $file = file($this->log_file);
        $text = $file[count($file) - 1] ?? '';

        return $text;
    }

    public function get_log_content($offset)
    {
        $file = $this->log_file;
        if (!file_exists($file)) {
            return [
                'result'  => 'success',
                'offset'  => 0,
                'content' => ''
            ];
        }

        $fp = $this->fopen($file, 'r');
        if (!$fp) {
            return [
                'result'  => 'success',
                'offset'  => 0,
                'content' => ''
            ];
        }

        if ($offset > 0) {
            fseek($fp, $offset);
        }

        $content = '';
        while (!feof($fp)) {
            $content .= fgets($fp);
        }

        $new_offset = ftell($fp);
        fclose($fp);

        return [
            'result'  => 'success',
            'offset'  => $new_offset,
            'content' => $content
        ];
    }

    public function GetSaveLogFolder()
    {
        $path = WP_CONTENT_DIR . '/aero-images/log';

        if (!is_dir($path)) {
            wp_mkdir_p($path);
            @file_put_contents($path . '/index.html', '');
            @file_put_contents($path . '/.htaccess', "deny from all");
        }

        return $path . '/';
    }

    private function ensure_handle_open()
    {
        if ($this->log_file_handle)
        {
            return true;
        }

        if (!empty($this->log_file))
        {
            $this->log_file_handle = $this->fopen($this->log_file, 'a');
            return (bool)$this->log_file_handle;
        }

        $this->OpenLogFile();
        return (bool)$this->log_file_handle;
    }

    private function write_open_header()
    {
        $text  = "====================================================\n";
        $time  = gmdate("Y-m-d H:i:s", time());
        $text .= "open log file: {$time}\n";
        $this->fwrite($this->log_file_handle, $text);
    }

    public function get_logs()
    {
        $dir = $this->GetSaveLogFolder();
        $files = scandir($dir, SCANDIR_SORT_DESCENDING);

        $log_files = [];
        $regex = '#^aero_io.*_log.txt#';

        foreach ($files as $filename) {
            if (preg_match($regex, $filename)) {
                $log_files[] = $dir . '/' . $filename;
            }
            if (count($log_files) >= 5) {
                break;
            }
        }
        return $log_files;
    }
}


if ( ! class_exists( 'WP_List_Table' ) )
{
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class Aero_IO_Log_List extends WP_List_Table
{
    public $page_num;
    public $log_list;

    public function __construct( $args = array() )
    {
        parent::__construct(
            array(
                'plural' => 'log',
                'screen' => 'log'
            )
        );
    }



    public function get_columns()
    {
        $columns = array();
        $columns['cx_date'] = __('Data and Time','aero');
        $columns['cx_log_file_name'] =__( 'Log File Name', 'aero'  );
        $columns['cx_size'] = __( 'Log Size', 'aero'  );
        $columns['cx_log_actions'] = __( 'Actions', 'aero'  );


        return $columns;
    }

    public function set_log_list($log_list, $page_num=1)
    {
        $this->log_list=$log_list;
        $this->page_num=$page_num;
    }

    public function get_pagenum()
    {
        if($this->page_num=='first')
        {
            $this->page_num=1;
        }
        else if($this->page_num=='last')
        {
            $this->page_num=$this->_pagination_args['total_pages'];
        }
        $pagenum = $this->page_num ? $this->page_num : 0;

        if ( isset( $this->_pagination_args['total_pages'] ) && $pagenum > $this->_pagination_args['total_pages'] )
        {
            $pagenum = $this->_pagination_args['total_pages'];
        }

        return max( 1, $pagenum );
    }

    public function prepare_items()
    {
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = array();
        $this->_column_headers = array($columns, $hidden, $sortable);

        $total_items =sizeof($this->log_list);

        $this->set_pagination_args(
            array(
                'total_items' => $total_items,
                'per_page'    => 30,
            )
        );
    }

    public function has_items()
    {
        return !empty($this->log_list);
    }

    public function _column_cx_date( $log )
    {
        echo '<td class="tablelistcolumn">'.esc_html($log['date']).'</td>';
    }

    public function _column_cx_log_file_name( $log )
    {
        echo '<td class="tablelistcolumn"><span>'.esc_html($log['file_name']).'</span></td>';
    }

    public function _column_cx_size( $log )
    {
        echo '<td class="tablelistcolumn"><span>'.esc_html(size_format($log['size'],2)).'</span></td>';
    }

    public function _column_cx_log_actions( $log )
    {
        ?>
        <td class="manage-column" data-id="<?php echo esc_attr($log['file_name'])?>">
            <span><a style="cursor: pointer" class="cs-log-detail"><?php esc_html_e('Details','aero')?></a></span><span> | </span>
            <span><a style="cursor: pointer" class="cs-log-download"><?php esc_html_e('Download','aero')?></a></span><span> | </span>
            <span><a style="cursor: pointer" class="cs-log-delete"><?php esc_html_e('Delete','aero')?></a></span>
        </td>
        <?php
    }

    public function display_rows()
    {
        $this->_display_rows( $this->log_list );
    }

    private function _display_rows($log_list)
    {
        $page=$this->get_pagenum();

        $page_log_list=array();
        $count=0;
        while ( $count<$page )
        {
            $page_log_list = array_splice( $log_list, 0, 30);
            $count++;
        }
        foreach ( $page_log_list as $log)
        {
            $this->single_row($log);
        }
    }

    public function single_row($log)
    {
        ?>
        <tr style="display: table-row;">
            <?php $this->single_row_columns( $log ); ?>
        </tr>
        <?php
    }

    protected function display_tablenav( $which )
    {
        $css_type = '';
        if ( 'top' === $which ) {
            wp_nonce_field( 'bulk-' . $this->_args['plural'] );
            $css_type = 'padding:0 0 1em 0;';
        }
        else if( 'bottom' === $which ) {
            $css_type = 'display: none;';
        }

        $total_pages     = $this->_pagination_args['total_pages'];
        if ( $total_pages >1)
        {
            ?>
            <div class="tablenav <?php echo esc_attr( $which ); ?>" style="<?php echo esc_attr($css_type); ?>">
                <div class="alignleft actions bulkactions">
                    <span><input type="date" id="cx_log_start_date"></span>
                    <span><input type="time" id="cx_log_start_time"></span>
                    <span>to</span>
                    <span><input type="date" id="cx_log_end_date"></span>
                    <span><input type="time" id="cx_log_end_time"></span>
                    <span><input type="submit" id="cx_log_search_by_date" class="button action" value="Apply"></span>
                </div>
                <?php
                $this->extra_tablenav( $which );
                $this->pagination( $which );
                ?>

                <br class="clear" />
            </div>
            <?php
        }
    }

    public function display()
    {
        $this->display_tablenav( 'top' );

        $this->screen->render_screen_reader_content( 'heading_list' );

        $total_pages     = $this->_pagination_args['total_pages'];
        if ( $total_pages <= 1)
        {
            ?>
            <div class="tablenav top" style="padding:0 0 1em 0;">
                <div class="alignleft actions bulkactions">
                    <span><input type="date" id="cx_log_start_date"></span>
                    <span><input type="time" id="cx_log_start_time"></span>
                    <span>to</span>
                    <span><input type="date" id="cx_log_end_date"></span>
                    <span><input type="time" id="cx_log_end_time"></span>
                    <span><input type="submit" id="cx_log_search_by_date" class="button action" value="Apply"></span>
                </div>
            </div>
            <?php
        }

        ?>
        <table class="wp-list-table <?php echo esc_attr(implode( ' ', $this->get_table_classes() )); ?>">
            <thead>
            <tr class="cx-table-header">
                <?php $this->print_column_headers(); ?>
            </tr>
            </thead>

            <tbody id="the-list">
            <?php $this->display_rows_or_placeholder(); ?>
            </tbody>

            <tfoot>
            <tr class="cx-table-footer">
                <?php $this->print_column_headers(); ?>
            </tr>
            </tfoot>

        </table>
        <?php
        $this->display_tablenav( 'bottom' );
    }

    public function print_column_headers( $with_id = true )
    {
        list( $columns, $hidden, $sortable, $primary ) = $this->get_column_info();

        if(isset($_SERVER['HTTP_HOST']))
            $HTTP_HOST=sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
        else
            $HTTP_HOST="";

        if(isset($_SERVER['REQUEST_URI']))
            $REQUEST_URI=sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
        else
            $REQUEST_URI="";

        $current_url = set_url_scheme( 'http://' .$HTTP_HOST . $REQUEST_URI );
        $current_url = remove_query_arg( 'paged', $current_url );

        // When users click on a column header to sort by other columns.
        if ( isset( $_GET['orderby'] ) ) {
            $current_orderby = $_GET['orderby'];
            // In the initial view there's no orderby parameter.
        } else {
            $current_orderby = '';
        }

        // Not in the initial view and descending order.
        if ( isset( $_GET['order'] ) && 'desc' === $_GET['order'] ) {
            $current_order = 'desc';
        } else {
            // The initial view is not always 'asc', we'll take care of this below.
            $current_order = 'asc';
        }

        if ( ! empty( $columns['cb'] ) ) {
            static $cb_counter = 1;
            $columns['cb']     = '<input id="cb-select-all-' . $cb_counter . '" type="checkbox" />
			<label for="cb-select-all-' . $cb_counter . '">' .
                '<span class="screen-reader-text">' .
                'Select All' .
                '</span>' .
                '</label>';
            ++$cb_counter;
        }

        foreach ( $columns as $column_key => $column_display_name )
        {
            $class          = array( 'manage-column', "column-$column_key" );
            $aria_sort_attr = '';
            $abbr_attr      = '';
            $order_text     = '';

            if ( in_array( $column_key, $hidden, true ) ) {
                $class[] = 'hidden';
            }

            if ( 'cb' === $column_key ) {
                $class[] = 'check-column';
            } elseif ( in_array( $column_key, array( 'posts', 'comments', 'links' ), true ) ) {
                $class[] = 'num';
            }

            if ( isset( $sortable[ $column_key ] ) ) {
                $orderby       = isset( $sortable[ $column_key ][0] ) ? $sortable[ $column_key ][0] : '';
                $desc_first    = isset( $sortable[ $column_key ][1] ) ? $sortable[ $column_key ][1] : false;
                $abbr          = isset( $sortable[ $column_key ][2] ) ? $sortable[ $column_key ][2] : '';
                $orderby_text  = isset( $sortable[ $column_key ][3] ) ? $sortable[ $column_key ][3] : '';
                $initial_order = isset( $sortable[ $column_key ][4] ) ? $sortable[ $column_key ][4] : '';

                /*
                 * We're in the initial view and there's no $_GET['orderby'] then check if the
                 * initial sorting information is set in the sortable columns and use that.
                 */
                if ( '' === $current_orderby && $initial_order ) {
                    // Use the initially sorted column $orderby as current orderby.
                    $current_orderby = $orderby;
                    // Use the initially sorted column asc/desc order as initial order.
                    $current_order = $initial_order;
                }

                /*
                 * True in the initial view when an initial orderby is set via get_sortable_columns()
                 * and true in the sorted views when the actual $_GET['orderby'] is equal to $orderby.
                 */
                if ( $current_orderby === $orderby ) {
                    // The sorted column. The `aria-sort` attribute must be set only on the sorted column.
                    if ( 'asc' === $current_order ) {
                        $order          = 'desc';
                        $aria_sort_attr = ' aria-sort="ascending"';
                    } else {
                        $order          = 'asc';
                        $aria_sort_attr = ' aria-sort="descending"';
                    }

                    $class[] = 'sorted';
                    $class[] = $current_order;
                } else {
                    // The other sortable columns.
                    $order = strtolower( $desc_first );

                    if ( ! in_array( $order, array( 'desc', 'asc' ), true ) ) {
                        $order = $desc_first ? 'desc' : 'asc';
                    }

                    $class[] = 'sortable';
                    $class[] = 'desc' === $order ? 'asc' : 'desc';

                    $asc_text = 'Sort ascending.' ;
                    $desc_text  = 'Sort descending.';
                    $order_text = 'asc' === $order ? $asc_text : $desc_text;
                }

                if ( '' !== $order_text ) {
                    $order_text = ' <span class="screen-reader-text">' . $order_text . '</span>';
                }

                // Print an 'abbr' attribute if a value is provided via get_sortable_columns().
                $abbr_attr = $abbr ? ' abbr="' . esc_attr( $abbr ) . '"' : '';

                $column_display_name = sprintf(
                    '<a href="%1$s">' .
                    '<span>%2$s</span>' .
                    '<span class="sorting-indicators">' .
                    '<span class="sorting-indicator asc" aria-hidden="true"></span>' .
                    '<span class="sorting-indicator desc" aria-hidden="true"></span>' .
                    '</span>' .
                    '%3$s' .
                    '</a>',
                    esc_url( add_query_arg( compact( 'orderby', 'order' ), $current_url ) ),
                    $column_display_name,
                    $order_text
                );
            }

            $tag   = ( 'cb' === $column_key ) ? 'td' : 'th';
            $scope = ( 'th' === $tag ) ? 'scope="col"' : '';
            $id    = $with_id ? "id='$column_key'" : '';

            if ( ! empty( $class ) ) {
                $class = "class='" . implode( ' ', $class ) . "'";
            }

            echo wp_kses_post("<$tag $scope $id $class $aria_sort_attr $abbr_attr>$column_display_name</$tag>");
        }
    }

    protected function get_table_classes() {
        $mode = get_user_setting( 'posts_list_mode', 'list' );

        $mode_class = esc_attr( 'table-view-' . $mode );

        return array( 'cx-table','widefat', 'striped', $this->_args['plural'] );
    }
}

class Aero_IO_Log_List_V2 extends WP_List_Table
{
    public $page_num;
    public $log_list;

    public function __construct( $args = array() )
    {
        parent::__construct(
            array(
                'plural' => 'log',
                'screen' => 'log'
            )
        );
    }



    public function get_columns()
    {
        $columns = array();
        $columns['cx_date'] = __('Date & Time','aero');
        $columns['cx_log_file_name'] =__( 'Log File Name', 'aero'  );
        $columns['cx_size'] = __( 'Size', 'aero'  );
        $columns['cx_log_actions'] = __( 'Actions', 'aero'  );

        return $columns;
    }

    public function set_log_list($log_list, $page_num=1)
    {
        $this->log_list=$log_list;
        $this->page_num=$page_num;
    }

    public function get_pagenum()
    {
        if($this->page_num=='first')
        {
            $this->page_num=1;
        }
        else if($this->page_num=='last')
        {
            $this->page_num=$this->_pagination_args['total_pages'];
        }
        $pagenum = $this->page_num ? $this->page_num : 0;

        if ( isset( $this->_pagination_args['total_pages'] ) && $pagenum > $this->_pagination_args['total_pages'] )
        {
            $pagenum = $this->_pagination_args['total_pages'];
        }

        return max( 1, $pagenum );
    }

    public function prepare_items()
    {
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = array();
        $this->_column_headers = array($columns, $hidden, $sortable);

        $total_items =sizeof($this->log_list);

        $this->set_pagination_args(
            array(
                'total_items' => $total_items,
                'per_page'    => 30,
            )
        );
    }

    public function has_items()
    {
        return !empty($this->log_list);
    }

    public function _column_cx_date( $log )
    {
        echo '<td class="aero-io-cell  tablelistcolumn">'.esc_html($log['date']).'</td>';
    }

    public function _column_cx_log_file_name( $log )
    {
        echo '<td class="aero-io-cell  tablelistcolumn"><span>'.esc_html($log['file_name']).'</span></td>';
    }

    public function _column_cx_size( $log )
    {
        echo '<td class="aero-io-cell  tablelistcolumn"><span>'.esc_html(size_format($log['size'],2)).'</span></td>';
    }

    public function _column_cx_log_actions( $log )
    {
        ?>
        <td class="aero-io-cell  manage-column " data-id="<?php echo esc_attr($log['file_name'])?>">
            <span><a style="cursor: pointer" class=" cs-log-detail"><?php esc_html_e('Details','aero')?></a></span>
            <span><a style="cursor: pointer" class=" cs-log-download"><?php esc_html_e('Download','aero')?></a></span>
            <span><a style="cursor: pointer" class=" cs-log-delete"><?php esc_html_e('Delete','aero')?></a></span>
        </td>
        <?php
    }

    public function display_rows()
    {
        $this->_display_rows( $this->log_list );
    }

    private function _display_rows($log_list)
    {
        $page=$this->get_pagenum();

        $page_log_list=array();
        $count=0;
        while ( $count<$page )
        {
            $page_log_list = array_splice( $log_list, 0, 30);
            $count++;
        }
        foreach ( $page_log_list as $log)
        {
            $this->single_row($log);
        }
    }

    public function single_row($log)
    {
        ?>
        <tr style="display: table-row;">
            <?php $this->single_row_columns( $log ); ?>
        </tr>
        <?php
    }

    protected function display_tablenav( $which )
    {
        $css_type = '';
        if ( 'top' === $which ) {
            wp_nonce_field( 'bulk-' . $this->_args['plural'] );
            $css_type = 'padding:0 0 1em 0;';
        }
        else if( 'bottom' === $which ) {
            $css_type = 'display: none;';
        }

        $total_pages     = $this->_pagination_args['total_pages'];
        if ( $total_pages >1)
        {
            ?>
            <div class="tablenav <?php echo esc_attr( $which ); ?>" style="<?php echo esc_attr($css_type); ?>">
                <div class="alignleft actions bulkactions">
                    <span><input type="date" id="cx_log_start_date"></span>
                    <span><input type="time" id="cx_log_start_time"></span>
                    <span>to</span>
                    <span><input type="date" id="cx_log_end_date"></span>
                    <span><input type="time" id="cx_log_end_time"></span>
                    <span><input type="submit" id="cx_log_search_by_date" class="button action" value="Apply"></span>
                </div>
                <?php
                $this->extra_tablenav( $which );
                $this->pagination( $which );
                ?>

                <br class="clear" />
            </div>
            <?php
        }
    }

    public function pagination( $which )
    {
        if ( empty( $this->_pagination_args ) ) {
            return;
        }

        $total_items     = isset( $this->_pagination_args['total_items'] ) ? (int) $this->_pagination_args['total_items'] : 0;
        $total_pages     = isset( $this->_pagination_args['total_pages'] ) ? (int) $this->_pagination_args['total_pages'] : 0;
        $infinite_scroll = false;

        if ( isset( $this->_pagination_args['infinite_scroll'] ) ) {
            $infinite_scroll = $this->_pagination_args['infinite_scroll'];
        }

        if ( 'top' === $which && $total_pages > 1 ) {
            $this->screen->render_screen_reader_content( 'heading_pagination' );
        }

        $output = '<span class="displaying-num">' . sprintf(
                _n( '%s item', '%s items', $total_items ),
                number_format_i18n( $total_items )
            ) . '</span>';

        $current = (int) $this->get_pagenum();

        if ( $current < 1 ) {
            $current = 1;
        }

        if ( $total_pages > 0 && $current > $total_pages ) {
            $current = $total_pages;
        }

        $page_links = array();

        $total_pages_before = '<span class="paging-input">';
        $total_pages_after  = '</span></span>';

        $disable_first = false;
        $disable_last  = false;
        $disable_prev  = false;
        $disable_next  = false;

        if ( 1 === $current ) {
            $disable_first = true;
            $disable_prev  = true;
        }

        if ( $total_pages === $current ) {
            $disable_last = true;
            $disable_next = true;
        }

        /*
         * First page.
         * Existing JS does not read value from .first-page.
         * It directly sends page = 'first'.
         */
        if ( $disable_first ) {
            $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>';
        } else {
            $page_links[] = sprintf(
                "<a class='first-page button' href='javascript:void(0);' value='1'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                esc_html__( 'First page' ),
                '&laquo;'
            );
        }

        /*
         * Previous page.
         * Existing JS reads value and then uses page - 1.
         * Therefore value must be the current page, not the target page.
         */
        if ( $disable_prev ) {
            $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>';
        } else {
            $page_links[] = sprintf(
                "<a class='prev-page button' href='javascript:void(0);' value='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                esc_attr( $current ),
                esc_html__( 'Previous page' ),
                '&lsaquo;'
            );
        }

        if ( 'bottom' === $which ) {
            $html_current_page  = $current;
            $total_pages_before = '<span class="screen-reader-text">' . esc_html__( 'Current Page' ) . '</span><span id="table-paging" class="paging-input"><span class="tablenav-paging-text">';
        } else {
            $html_current_page = sprintf(
                "%s<input class='current-page' id='current-page-selector' type='text' name='paged' value='%s' size='%d' aria-describedby='table-paging' /><span class='tablenav-paging-text'>",
                '<label for="current-page-selector" class="screen-reader-text">' . esc_html__( 'Current Page' ) . '</label>',
                esc_attr( $current ),
                strlen( (string) $total_pages )
            );
        }

        $html_total_pages = sprintf(
            "<span class='total-pages'>%s</span>",
            number_format_i18n( $total_pages )
        );

        $page_links[] = $total_pages_before . sprintf(
                _x( '%1$s of %2$s', 'paging' ),
                $html_current_page,
                $html_total_pages
            ) . $total_pages_after;

        /*
         * Next page.
         * Existing JS reads value and then uses page + 1.
         * Therefore value must be the current page, not the target page.
         */
        if ( $disable_next ) {
            $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>';
        } else {
            $page_links[] = sprintf(
                "<a class='next-page button' href='javascript:void(0);' value='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                esc_attr( $current ),
                esc_html__( 'Next page' ),
                '&rsaquo;'
            );
        }

        /*
         * Last page.
         * Existing JS does not read value from .last-page.
         * It directly sends page = 'last'.
         */
        if ( $disable_last ) {
            $page_links[] = '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>';
        } else {
            $page_links[] = sprintf(
                "<a class='last-page button' href='javascript:void(0);' value='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
                esc_attr( $total_pages ),
                esc_html__( 'Last page' ),
                '&raquo;'
            );
        }

        $pagination_links_class = 'pagination-links';

        if ( ! empty( $infinite_scroll ) ) {
            $pagination_links_class .= ' hide-if-js';
        }

        $output .= "\n<span class='" . esc_attr( $pagination_links_class ) . "'>" . implode( "\n", $page_links ) . '</span>';

        if ( $total_pages ) {
            $page_class = $total_pages < 2 ? ' one-page' : '';
        } else {
            $page_class = ' no-pages';
        }

        $this->_pagination = "<div class='tablenav-pages" . esc_attr( $page_class ) . "'>" . $output . '</div>';

        echo $this->_pagination;
    }

    public function display()
    {
        $this->display_tablenav( 'top' );

        $this->screen->render_screen_reader_content( 'heading_list' );

        $total_pages     = $this->_pagination_args['total_pages'];
        if ( $total_pages <= 1)
        {
            ?>
            <div class="tablenav top" style="padding:0 0 1em 0;">
                <div class="alignleft actions bulkactions">
                    <span><input type="date" id="cx_log_start_date"></span>
                    <span><input type="time" id="cx_log_start_time"></span>
                    <span>to</span>
                    <span><input type="date" id="cx_log_end_date"></span>
                    <span><input type="time" id="cx_log_end_time"></span>
                    <span><input type="submit" id="cx_log_search_by_date" class="button action" value="Apply"></span>
                </div>
            </div>
            <?php
        }

        ?>
        <table class="wp-list-table <?php echo esc_attr(implode( ' ', $this->get_table_classes() )); ?>">
            <thead>
            <tr class="cx-table-header">
                <?php $this->print_column_headers(); ?>
            </tr>
            </thead>

            <tbody id="the-list">
            <?php $this->display_rows_or_placeholder(); ?>
            </tbody>

            <tfoot>
            <tr class="cx-table-footer">
                <?php $this->print_column_headers(); ?>
            </tr>
            </tfoot>

        </table>
        <?php
        $this->display_tablenav( 'bottom' );
    }

    public function print_column_headers( $with_id = true )
    {
        list( $columns, $hidden, $sortable, $primary ) = $this->get_column_info();

        $current_url = set_url_scheme( 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
        $current_url = remove_query_arg( 'paged', $current_url );

        // When users click on a column header to sort by other columns.
        if ( isset( $_GET['orderby'] ) ) {
            $current_orderby = $_GET['orderby'];
            // In the initial view there's no orderby parameter.
        } else {
            $current_orderby = '';
        }

        // Not in the initial view and descending order.
        if ( isset( $_GET['order'] ) && 'desc' === $_GET['order'] ) {
            $current_order = 'desc';
        } else {
            // The initial view is not always 'asc', we'll take care of this below.
            $current_order = 'asc';
        }

        if ( ! empty( $columns['cb'] ) ) {
            static $cb_counter = 1;
            $columns['cb']     = '<input id="cb-select-all-' . $cb_counter . '" type="checkbox" />
			<label for="cb-select-all-' . $cb_counter . '">' .
                '<span class="screen-reader-text">' .
                /* translators: Hidden accessibility text. */
                'Select All' .
                '</span>' .
                '</label>';
            ++$cb_counter;
        }

        foreach ( $columns as $column_key => $column_display_name )
        {
            $class          = array( 'manage-column', "column-$column_key" );
            $aria_sort_attr = '';
            $abbr_attr      = '';
            $order_text     = '';

            if ( in_array( $column_key, $hidden, true ) ) {
                $class[] = 'hidden';
            }

            if ( 'cb' === $column_key ) {
                $class[] = 'check-column';
            } elseif ( in_array( $column_key, array( 'posts', 'comments', 'links' ), true ) ) {
                $class[] = 'num';
            }

            if ( isset( $sortable[ $column_key ] ) ) {
                $orderby       = isset( $sortable[ $column_key ][0] ) ? $sortable[ $column_key ][0] : '';
                $desc_first    = isset( $sortable[ $column_key ][1] ) ? $sortable[ $column_key ][1] : false;
                $abbr          = isset( $sortable[ $column_key ][2] ) ? $sortable[ $column_key ][2] : '';
                $orderby_text  = isset( $sortable[ $column_key ][3] ) ? $sortable[ $column_key ][3] : '';
                $initial_order = isset( $sortable[ $column_key ][4] ) ? $sortable[ $column_key ][4] : '';

                /*
                 * We're in the initial view and there's no $_GET['orderby'] then check if the
                 * initial sorting information is set in the sortable columns and use that.
                 */
                if ( '' === $current_orderby && $initial_order ) {
                    // Use the initially sorted column $orderby as current orderby.
                    $current_orderby = $orderby;
                    // Use the initially sorted column asc/desc order as initial order.
                    $current_order = $initial_order;
                }

                /*
                 * True in the initial view when an initial orderby is set via get_sortable_columns()
                 * and true in the sorted views when the actual $_GET['orderby'] is equal to $orderby.
                 */
                if ( $current_orderby === $orderby ) {
                    // The sorted column. The `aria-sort` attribute must be set only on the sorted column.
                    if ( 'asc' === $current_order ) {
                        $order          = 'desc';
                        $aria_sort_attr = ' aria-sort="ascending"';
                    } else {
                        $order          = 'asc';
                        $aria_sort_attr = ' aria-sort="descending"';
                    }

                    $class[] = 'sorted';
                    $class[] = $current_order;
                } else {
                    // The other sortable columns.
                    $order = strtolower( $desc_first );

                    if ( ! in_array( $order, array( 'desc', 'asc' ), true ) ) {
                        $order = $desc_first ? 'desc' : 'asc';
                    }

                    $class[] = 'sortable';
                    $class[] = 'desc' === $order ? 'asc' : 'desc';

                    /* translators: Hidden accessibility text. */
                    $asc_text = 'Sort ascending.';
                    /* translators: Hidden accessibility text. */
                    $desc_text  = 'Sort descending.';
                    $order_text = 'asc' === $order ? $asc_text : $desc_text;
                }

                if ( '' !== $order_text ) {
                    $order_text = ' <span class="screen-reader-text">' . $order_text . '</span>';
                }

                // Print an 'abbr' attribute if a value is provided via get_sortable_columns().
                $abbr_attr = $abbr ? ' abbr="' . esc_attr( $abbr ) . '"' : '';

                $column_display_name = sprintf(
                    '<a href="%1$s">' .
                    '<span>%2$s</span>' .
                    '<span class="sorting-indicators">' .
                    '<span class="sorting-indicator asc" aria-hidden="true"></span>' .
                    '<span class="sorting-indicator desc" aria-hidden="true"></span>' .
                    '</span>' .
                    '%3$s' .
                    '</a>',
                    esc_url( add_query_arg( compact( 'orderby', 'order' ), $current_url ) ),
                    $column_display_name,
                    $order_text
                );
            }

            $tag   = ( 'cb' === $column_key ) ? 'td' : 'th';
            $scope = ( 'th' === $tag ) ? 'scope="col"' : '';
            $id    = $with_id ? "id='$column_key'" : '';

            $class[] = 'aero-io-cell';
            $class[] = '';

            if ( ! empty( $class ) ) {
                $class = "class='" . implode( ' ', $class ) . "'";
            }

            echo wp_kses_post("<$tag $scope $id $class $aria_sort_attr $abbr_attr>$column_display_name</$tag>");
        }
    }

    protected function get_table_classes() {
        $mode = get_user_setting( 'posts_list_mode', 'list' );

        $mode_class = esc_attr( 'table-view-' . $mode );

        return array( 'cx-table','widefat', 'striped', $this->_args['plural'] );
    }
}