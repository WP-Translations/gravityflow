<?php

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

class Gravity_Flow_Status {

	public static function display( $args ){

		wp_enqueue_script( 'gform_field_filter' );
		$defaults = array(
			'action_url' => admin_url( 'admin.php?page=gravityflow-status' ),
		);
		$args = array_merge( $defaults, $args );
		$table = new Gravity_Flow_Status_Table( $args );
		$action_url = $args['action_url'];
		?>
		<form id="gravityflow-status-filter" method="GET" action="<?php echo esc_url( $action_url ) ?>">
			<input type="hidden" name="page" value="gravityflow-status" />
			<?php
			$table->views();
			$table->filters();
			$table->prepare_items();
			$table->display();
			?>

		</form>
	<?php
	}
}


if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class Gravity_Flow_Status_Table extends WP_List_Table {
	public $pagination_args;

	/**
	 * URL of this page
	 *
	 * @var string
	 */
	public $base_url;

	/**
	 * Base url of the detail page
	 *
	 * @var string
	 */
	public $detail_base_url;

	/**
	 * Total number of entries
	 *
	 * @var int
	 */
	public $total_count;
	/**
	 * Total number of pending entries
	 *
	 * @var int
	 */
	public $pending_count;
	/**
	 * Total number of complete entries
	 *
	 * @var int
	 */
	public $complete_count;
	/**
	 * Total number of cancelled entries
	 *
	 * @var int
	 */
	public $cancelled_count;

	public $form_ids;

	public $constraint_filters = array();

	public $display_all;

	public $bulk_actions;

	/* @var Gravity_Flow_Step[] $steps $*/
	private $_steps;

	private $_filter_args;

	function __construct( $args = array() ) {
		$default_args = array(
			'singular' => __( 'entry', 'gravityflow' ),
			'plural'   => __( 'entries', 'gravityflow' ),
			'ajax'     => false,
			'base_url' => admin_url( 'admin.php?page=gravityflow-status' ),
			'detail_base_url' => admin_url( 'admin.php?page=gravityflow-inbox&view=entry' ),
			'constraint_filters' => array(),
			'screen' => 'gravityflow-status',
			'display_all' => GFAPI::current_user_can_any( 'gravityflow_status_view_all' ),
			'bulk_actions' => array( 'print' => esc_html__( 'Print', 'gravityflow' ) ),
		);

		$args = wp_parse_args( $args, $default_args );

		parent::__construct( $args );

		$this->base_url = $args['base_url'];
		$this->detail_base_url = $args['detail_base_url'];
		$this->constraint_filters = $args['constraint_filters'];
		$this->display_all = $args['display_all'];
		$this->bulk_actions = $args['bulk_actions'];
		$this->set_counts();
	}

	function no_items() {
		esc_html_e( "You haven't submitted any a workflow forms yet." );
	}

	public function get_views() {
		$current        = isset( $_GET['status'] ) ? $_GET['status'] : '';
		$total_count    = '&nbsp;<span class="count">(' . $this->total_count    . ')</span>';
		$complete_count = '&nbsp;<span class="count">(' . $this->complete_count . ')</span>';
		$pending_count  = '&nbsp;<span class="count">(' . $this->pending_count  . ')</span>';
		$cancelled_count  = '&nbsp;<span class="count">(' . $this->cancelled_count  . ')</span>';

		$views = array(
			'all'		=> sprintf( '<a href="%s"%s>%s</a>', remove_query_arg( array( 'status', 'paged' ) ), $current === 'all' || $current == '' ? ' class="current"' : '', esc_html__( 'All', 'gravityflow' ) . $total_count ),
			'pending'	=> sprintf( '<a href="%s"%s>%s</a>', add_query_arg( array( 'status' => 'pending', 'paged' => false ) ), $current === 'pending' ? ' class="current"' : '', esc_html__( 'Pending', 'gravityflow' ) . $pending_count ),
			'complete'	=> sprintf( '<a href="%s"%s>%s</a>', add_query_arg( array( 'status' => 'complete', 'paged' => false ) ), $current === 'complete' ? ' class="current"' : '', esc_html__( 'Complete', 'gravityflow' ) . $complete_count ),
			'cancelled'	=> sprintf( '<a href="%s"%s>%s</a>', add_query_arg( array( 'status' => 'cancelled', 'paged' => false ) ), $current === 'cancelled' ? ' class="current"' : '', esc_html__( 'Cancelled', 'gravityflow' ) . $cancelled_count ),
		);
		return $views;
	}

	function filters(){

		$start_date = isset( $_GET['start-date'] )  ? sanitize_text_field( $_GET['start-date'] ) : null;
		$end_date   = isset( $_GET['end-date'] )    ? sanitize_text_field( $_GET['end-date'] )   : null;
		$status     = isset( $_GET['status'] )      ? $_GET['status'] : '';
		$filter_form_id     = empty( $_GET['form-id'] ) ? '' : absint( $_GET['form-id'] );
		$filter_entry_id     = empty( $_GET['entry-id'] ) ? '' :  absint( $_GET['entry-id'] );

		$field_filters = null;

		$forms = GFAPI::get_forms();
		foreach ( $forms as $form ){
			$form_filters = GFCommon::get_field_filter_settings( $form );

			$empty_filter = array(
				'key'             => '',
				'text'            => esc_html__( 'Fields', 'gravityforms' ),
				'operators'       => array(),
			);
			array_unshift( $form_filters, $empty_filter );
			$field_filters[ $form['id'] ] = $form_filters;
		}
		$search_field_ids = rgget( 'f' );
		$search_field_id = ( $search_field_ids && is_array( $search_field_ids ) ) ? $search_field_ids[0] : '';
		$init_field_id   = $search_field_id;
		$search_operators = rgget( 'o' );
		$search_operator = ( $search_operators && is_array( $search_operators ) ) ? $search_operators[0] : false;
		$init_field_operator = empty( $search_operator ) ? 'contains' : $search_operator;
		$values = rgget( 'v' );
		$value = ( $values && is_array( $values ) ) ? $values[0] : 0;
		$init_filter_vars = array(
			'mode'    => 'off',
			'filters' => array(
				array(
					'field'    => $init_field_id,
					'operator' => $init_field_operator,
					'value'    => $value,
				),
			)
		);

		?>
		<div id="gravityflow-status-filters">

			<div id="gravityflow-status-date-filters">

				<input placeholder="ID" type="text" name="entry-id" id="entry-id" class="small-text"
				       value="<?php echo $filter_entry_id; ?>"/>
				<?php if ( ! isset( $this->constraint_filters['start_date'] ) ) : ?>
					<label for="start-date"><?php esc_html_e( 'Start:', 'gravityflow' ); ?></label>
					<input type="text" id="start-date" name="start-date" class="datepicker medium-text ymd_dash" value="<?php echo $start_date; ?>" placeholder="yyyy/mm/dd"/>
				<?php endif; ?>

				<?php if ( ! isset( $this->constraint_filters['start_date'] ) ) : ?>
					<label for="end-date"><?php esc_html_e( 'End:', 'gravityflow' ); ?></label>
					<input type="text" id="end-date" name="end-date" class="datepicker medium-text ymd_dash" value="<?php echo $end_date; ?>" placeholder="yyyy/mm/dd"/>
				<?php endif; ?>
				<?php if ( isset( $this->constraint_filters['form_id'] ) ) { ?>
					<input type="hidden" name="form-id" value="<?php echo esc_attr( $this->constraint_filters['form_id'] ); ?>">
				<?php } else { ?>
					<select id="gravityflow-form-select" name="form-id">
						<?php
						$selected = selected( '', $filter_form_id, false );
						printf( '<option value="" %s >%s</option>', $selected, esc_html__( 'Workflow Form', 'gravityflow' ) );
						$forms = GFAPI::get_forms();
						foreach ( $forms as $form ) {
							$form_id = absint( $form['id'] );
							$steps   = gravity_flow()->get_steps( $form_id );
							if ( ! empty( $steps ) ) {
								$selected = selected( $filter_form_id, $form_id, false );
								printf( '<option value="%d" %s>%s</option>', $form_id, $selected, esc_html( $form['title'] ) );
							}
						}
						?>
					</select>
					<div id="entry_filters" style="display:inline-block;"></div>
				<?php } ?>

				<input type="submit" class="button-secondary" value="<?php esc_html_e( 'Apply', 'gravityflow' ); ?>"/>

			<?php if ( ! empty( $status ) ) : ?>
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>"/>
			<?php endif; ?>
			<?php if ( ! empty( $start_date ) || ! empty( $end_date ) || ! empty( $filter_form_id ) | ! empty( $filter_entry_id ) ) : ?>
				<a href="<?php echo esc_url( $this->base_url ); ?>" class="button-secondary"><?php esc_html_e( 'Clear Filter', 'gravityflow' ); ?></a>
			<?php endif; ?>
			</div>
			<?php $this->search_box( esc_html__( 'Search', 'gravityflow' ), 'gravityflow-search' ); ?>
		</div>

		<script>
			(function($) {
				$( document ).ready( function(){
					var gformFieldFilters = <?php echo json_encode( $field_filters ) ?>,
						gformInitFilter = <?php echo json_encode( $init_filter_vars ) ?>;
					var $form_select = $('#gravityflow-form-select');
					var filterFormId = $form_select.val();
					var $entry_filters = $('#entry_filters');
					if( filterFormId ) {
						$entry_filters.gfFilterUI(gformFieldFilters[filterFormId], gformInitFilter, false);
						if ( $('.gform-filter-field').val() === '' ){
							$('.gform-filter-operator').hide();
							$('.gform-filter-value').hide();
						}
					}
					$form_select.change(function(){
						filterFormId = $form_select.val();
						if ( filterFormId ) {
							$entry_filters.gfFilterUI(gformFieldFilters[filterFormId], gformInitFilter, false);
							$('.gform-filter-field').val('');
							$('.gform-filter-operator').hide();
							$('.gform-filter-value').hide();
						} else {
							$entry_filters.html('');
						}

					})
					$('#entry_filters').on( 'change', '.gform-filter-field', function(){
						if ( $('.gform-filter-field').val() === '' ){
							$('.gform-filter-operator').hide();
							$('.gform-filter-value').hide();
						}
					});
				});

			})(jQuery);


		</script>

	<?php
	}

	function column_cb( $item ) {
		$feed_id = rgar( $item, 'id' );

		return sprintf(
			'<input type="checkbox" class="gravityflow-cb-step-id" name="step_ids[]" value="%s" />', esc_attr( $feed_id )
		);
	}

	function column_default( $item, $column_name ) {
		$url_entry = $this->detail_base_url . sprintf( '&id=%d&lid=%d', $item['form_id'], $item['id'] );
		$label = esc_html( rgar( $item, $column_name ) );

		$link = "<a href='{$url_entry}'>$label</a>";
		echo $link;
	}

	function column_workflow_final_status( $item ) {
		$url_entry = $this->detail_base_url . sprintf( '&id=%d&lid=%d', $item['form_id'], $item['id'] );
		$label = esc_html( rgar( $item, 'workflow_final_status' ) );

		$link = "<a href='{$url_entry}'>$label</a>";

		echo $link;

		$args = $this->get_filter_args();

		if ( empty( $item['workflow_step'] ) ){
			return;
		}

		if ( ! isset( $args['form-id'] ) ) {
			$duration     = time() - strtotime( $item['date_created'] );
			$duration_str = ' ' . $this->format_duration( $duration );
			echo $duration_str;
			return;
		}

		$step_id = $this->get_filter_step_id();

		if ( $step_id ) {
			return;
		}

		$steps = $this->get_steps( $item['form_id'] );
		if ( $steps ) {
			$pending = $rejected = $green = 0;
			$id = 'gravityflow-status-assignees-' . absint( $item['id'] );
			$m[] = '<ul id="' . $id .'" style="display:none;">';
			$step = gravity_flow()->get_step( $item['workflow_step'], $item );

			if ( $step ) {
				$step_type = $step->get_type();
				if ( ( $step_type == 'approval' && ! $step->unanimous_approval ) || $step->assignee_policy == 'any' ) {
					$duration     = time() - strtotime( $item['date_created'] );
					$duration_str = ' (' . $this->format_duration( $duration ) . ') ';
					echo $duration_str;
					return;
				}

				$assignees = $step->get_assignees();
				foreach ( $assignees as $assignee ) {
					$duration_str = '';
					$meta_key = sprintf( 'workflow_%s_%s', $assignee->get_type(), $assignee->get_id() );
					if ( $item[ $meta_key ] ) {
						if ( $item[ $meta_key ] == 'pending' ) {
							$pending ++;
							if ( $item[ $meta_key .'_timestamp' ] ) {
								$duration = time() - $item[ $meta_key .'_timestamp' ];
								$duration_str = ' (' . $this->format_duration( $duration ) . ') ';
							} else {
								$duration_str = '';
							}
						} elseif ( $item[ $meta_key ] == 'rejected' ) {
							$rejected ++;
						} else {
							$green ++;
						}
					}
					$m[]      = '<li>' . $assignee->get_display_name() . ': ' . $item[ $meta_key ] .  $duration_str . '</li>';

				}
			}
			$m[] = '</ul>';

			if ( $green == 0 && $rejected == 0 && $pending == 1 && $assignee ) {
				if ( $item[ $meta_key .'_timestamp' ] ) {
					$duration = time() - $item[ $meta_key .'_timestamp' ];
					$duration_str = ' (' . $this->format_duration( $duration ) . ') ';
					echo ': ' . $assignee->get_display_name() . $duration_str;
				}
			} else {
				$assignee_icons = array();
				for ( $i = 0; $i < $green; $i++ ) {
					$assignee_icons[] = "<i class='fa fa-male' style='color:green;'></i>";
				}
				for ( $i = 0; $i < $rejected; $i++ ) {
					$assignee_icons[] = "<i class='fa fa-male' style='color:red;'></i>";
				}
				for ( $i = 0; $i < $pending; $i++ ) {
					$assignee_icons[] = "<i class='fa fa-male' style='color:silver;'></i>";
				}
				echo sprintf( ":&nbsp;&nbsp;<span style='cursor:pointer;' onclick=\"jQuery('#$id').slideToggle()\">%s</span>", join( "\n", $assignee_icons ) );
				echo join( "\n", $m );
			}
		}

	}

	function column_created_by( $item ) {
		$url_entry = $this->detail_base_url . sprintf( '&id=%d&lid=%d', $item['form_id'], $item['id'] );

		$user_id = $item['created_by'];
		if ( $user_id ) {
			$user = get_user_by( 'id', $user_id );
			$display_name = $user->display_name;
		} else {
			$display_name = $item['ip'];
		}
		$label = esc_html( $display_name );
		$link = "<a href='{$url_entry}'>$label</a>";
		echo $link;
	}

	function column_form_id( $item ) {
		$url_entry = $this->detail_base_url . sprintf( '&id=%d&lid=%d', $item['form_id'], $item['id'] );

		$form_id = $item['form_id'];
		$form    = GFAPI::get_form( $form_id );

		$label = esc_html( $form['title'] );
		$link = "<a href='{$url_entry}'>$label</a>";
		echo $link;
	}

	function column_workflow_step( $item ) {
		$step_id = rgar( $item, 'workflow_step' );
		if ( $step_id > 0 ) {
			$step = gravity_flow()->get_step( $step_id );
			$url_entry = $this->detail_base_url . sprintf( '&id=%d&lid=%d', $item['form_id'], $item['id'] );

			$label = $step ? esc_html( $step->get_name() ) : '';
			$link = "<a href='{$url_entry}'>$label</a>";
			echo $link;
		} else {
			echo '<span class="gravityflow-empty">&nbsp;</span>';
		}
	}

	function column_date_created( $item ) {
		$url_entry = $this->detail_base_url . sprintf( '&id=%d&lid=%d', $item['form_id'], $item['id'] );

		$label = GFCommon::format_date( $item['date_created'] );
		$link = "<a href='{$url_entry}'>$label</a>";
		echo $link;
	}

	function get_bulk_actions() {
		$bulk_actions = $this->bulk_actions;
		return $bulk_actions;
	}

	function get_sortable_columns() {
		$sortable_columns = array(
			'id' => array('id', false),
			'created_by' => array('created_by', false),
			'workflow_final_status' => array('workflow_final_status', false),
			'date_created'  => array('date_created', false)
		);

		return $sortable_columns;
	}

	function get_columns() {

		$args = $this->get_filter_args();

		$columns['cb']         = esc_html__( 'Checkbox', 'gravityflow' );
		$columns['id']         = esc_html__( 'ID', 'gravityflow' );
		$columns['date_created']    = esc_html__( 'Date', 'gravityflow' );
		if ( ! isset( $args['form-id'] ) ) {
			$columns['form_id']         = esc_html__( 'Form', 'gravityflow' );
		}
		$columns['created_by']      = esc_html__( 'Submitter', 'gravityflow' );
		$columns['workflow_step']   = esc_html__( 'Step', 'gravityflow' );
		$columns['workflow_final_status'] = esc_html__( 'Status', 'gravityflow' );


		if ( $step_id = $this->get_filter_step_id() ) {
			unset( $columns['workflow_step'] );
			$step      = gravity_flow()->get_step( $step_id );
			$assignees = $step->get_assignees();
			foreach ( $assignees as $assignee ) {
				$meta_key             = sprintf( 'workflow_%s_%s', $assignee->get_type(), $assignee->get_id() );
				$columns[ $meta_key ] = $assignee->get_display_name();
			}
		}


		return $columns;
	}

	public function get_filter_step_id(){
		$step_id = false;
		$args = $this->get_filter_args();
		if ( isset( $args['form-id'] ) && isset( $args['field_filters'] ) ) {
			unset( $args['field_filters']['mode'] );
			$criteria     = array( 'key' => 'workflow_step' );
			$step_filters = wp_list_filter( $args['field_filters'], $criteria );
			$step_id      = count( $step_filters ) > 0 ? $step_filters[0]['value'] : false;
		}
		return $step_id;
	}

	public function get_filter_args(){

		if ( isset( $this->_filter_args ) ) {
			return $this->_filter_args;
		}

		$args = array();

		if ( isset( $this->constraint_filters['form_id'] ) ) {
			$args['form-id'] = absint( $this->constraint_filters['form_id'] );
		} elseif ( ! empty( $_GET['form-id'] ) ) {
			$args['form-id'] = absint( $_GET['form-id'] );
		}

		if ( ! empty( $args['form-id'] ) &&  rgget( 'f' ) !== '' ) {
			$form = GFAPI::get_form( absint( $args['form-id'] ) );
			$field_filters = $this->get_field_filters_from_request( $form );
			$args['field_filters'] = $field_filters;
		}

		if ( isset( $this->constraint_filters['start_date'] ) ) {
			$start_date = $this->constraint_filters['start_date'];
			$start_date_gmt = $this->prepare_start_date_gmt( $start_date );
			$args['start-date'] = $start_date_gmt;
		} elseif ( ! empty( $_GET['start-date'] ) ) {
			$start_date = urldecode( $_GET['start-date'] );
			$start_date = sanitize_text_field( $start_date );
			$start_date_gmt = $this->prepare_start_date_gmt( $start_date );
			$args['start-date'] = $start_date_gmt;
		}

		if ( isset( $this->constraint_filters['end_date'] ) ) {
			$end_date = $this->constraint_filters['end_date'];
			$end_date_gmt = $this->prepare_end_date_gmt( $end_date );
			$args['end-date'] = $end_date_gmt;
		} elseif ( ! empty( $_GET['end-date'] ) ) {
			$end_date = urldecode( $_GET['end-date'] );
			$end_date = sanitize_text_field( $end_date );
			$end_date_gmt = $this->prepare_end_date_gmt( $end_date );
			$args['end-date'] = $end_date_gmt;
		}

		$this->_filter_args = $args;
		return $args;
	}

	public function set_counts() {

		$args = $this->get_filter_args();
		$counts = $this->get_counts( $args );
		$this->pending_count  = $counts->pending;
		$this->complete_count = $counts->complete;
		$this->cancelled_count  = $counts->cancelled;
		foreach ( $counts as $count ) {
			$this->total_count += $count;
		}
	}

	function get_counts( $args ){

		if ( ! empty( $args['field_filters'] ) ) {
			if ( isset( $args['form-id'] ) ) {
				$form_ids = absint( $args['form-id'] );
			} else {
				$form_ids = $this->get_workflow_form_ids();
			}
			$results = new stdClass();
			$results->pending = 0;
			$results->complete = 0;
			$results->cancelled = 0;
			if ( empty( $form_ids ) ) {
				$this->items = array();
				return $results;
			}
			$base_search_criteria = $this->get_search_criteria();
			$pending_search_criteria = $base_search_criteria;
			$pending_search_criteria['field_filters'][] = array(
				'key' => 'workflow_final_status',
				'value' => 'pending',
			);
			$complete_search_criteria = $base_search_criteria;
			$complete_search_criteria['field_filters'][] = array(
				'key' => 'workflow_final_status',
				'operator' => 'not in',
				'value' => array( 'pending', 'cancelled' ),
			);

			$cancelled_search_criteria = $base_search_criteria;
			$cancelled_search_criteria['field_filters'][] = array(
				'key' => 'workflow_final_status',
				'value' => 'cancelled',
			);

			$results->pending   = GFAPI::count_entries( $form_ids, $pending_search_criteria );
			$results->complete  = GFAPI::count_entries( $form_ids, $complete_search_criteria );
			$results->cancelled = GFAPI::count_entries( $form_ids, $cancelled_search_criteria );

			return $results;
		}


		global $wpdb;

		if ( ! empty( $args['form-id'] ) ) {
			$form_clause = ' AND l.form_id=' . absint( $args['form-id'] );
		} else {
			$form_ids = $this->get_workflow_form_ids();

			if ( empty ( $form_ids ) ) {
				$results = new stdClass();
				$results->pending = 0;
				$results->complete = 0;
				$results->cancelled = 0;
				return $results;
			}
			$form_clause = ' AND l.form_id IN(' . join( ',', $form_ids ) . ')';
		}

		$start_clause = '';

		if ( ! empty( $args['start-date'] ) ) {
			$start_clause = $wpdb->prepare( ' AND l.date_created >= %s', $args['start-date'] );
		}

		$end_clause = '';

		if ( ! empty( $args['end-date'] ) ) {
			$end_clause = $wpdb->prepare( ' AND l.date_created <= %s', $args['end-date'] );
		}

		$user_id_clause = '';
		if ( ! $this->display_all ) {
			$user = wp_get_current_user();
			$user_id_clause = $wpdb->prepare( ' AND created_by=%d' , $user->ID );
		}

		$lead_table = GFFormsModel::get_lead_table_name();
		$meta_table = GFFormsModel::get_lead_meta_table_name();

		$sql =  "SELECT
		(SELECT count(distinct(l.id)) FROM $lead_table l INNER JOIN  $meta_table m ON l.id = m.lead_id WHERE l.status='active' AND meta_key='workflow_final_status' AND meta_value='pending' $form_clause $start_clause $end_clause $user_id_clause) as pending,
		(SELECT count(distinct(l.id)) FROM $lead_table l INNER JOIN  $meta_table m ON l.id = m.lead_id WHERE l.status='active' AND meta_key='workflow_final_status' AND meta_value NOT IN('pending', 'cancelled') $form_clause $start_clause $end_clause $user_id_clause) as complete,
		(SELECT count(distinct(l.id)) FROM $lead_table l INNER JOIN  $meta_table m ON l.id = m.lead_id WHERE l.status='active' AND meta_key='workflow_final_status' AND meta_value='cancelled' $form_clause $start_clause $end_clause $user_id_clause) as cancelled
		";
		$results = $wpdb->get_results( $sql );

		return $results[0];
	}

	function prepare_start_date_gmt( $start_date ){
		$start_date         = new DateTime( $start_date );
		$start_date_str     = $start_date->format( 'Y-m-d H:i:s' );
		$start_date_gmt = get_gmt_from_date( $start_date_str );
		return $start_date_gmt;
	}

	function prepare_end_date_gmt( $end_date ){
		$end_date         = new DateTime( $end_date );

		$end_datetime_str = $end_date->format( 'Y-m-d H:i:s' );
		$end_date_str     = $end_date->format( 'Y-m-d' );

		// extend end date till the end of the day unless a time was specified. 00:00:00 is ignored.
		if ( $end_datetime_str == $end_date_str . ' 00:00:00' ) {
			$end_date = $end_date->format( 'Y-m-d' ) . ' 23:59:59';
		} else {
			$end_date = $end_date->format( 'Y-m-d H:i:s' );
		}
		$end_date_gmt = get_gmt_from_date( $end_date );
		return $end_date_gmt;
	}

	function get_workflow_form_ids(){
		return gravity_flow()->get_workflow_form_ids();
	}

	protected function single_row_columns( $item ) {
		list( $columns, $hidden ) = $this->get_column_info();

		foreach ( $columns as $column_name => $column_display_name ) {
			$class = "class='$column_name column-$column_name'";

			$style = '';
			if ( in_array( $column_name, $hidden ) ){
				$style = ' style="display:none;"';
			}

			$data_label = ( ! empty( $column_display_name ) ) ? " data-label='$column_display_name'" : '';

			$attributes = "$class$style$data_label";

			if ( 'cb' == $column_name ) {
				echo '<th data-label="' . esc_html__( 'Select', 'gravityflow' ) . '" scope="row" class="check-column">';
				echo $this->column_cb( $item );
				echo '</th>';
			}
			elseif ( method_exists( $this, 'column_' . $column_name ) ) {
				echo "<td $attributes>";
				echo call_user_func( array( $this, 'column_' . $column_name ), $item );
				echo '</td>';
			}
			else {
				echo "<td $attributes>";
				echo $this->column_default( $item, $column_name );
				echo "</td>";
			}
		}
	}

	function prepare_items() {

		$filter_args = $this->get_filter_args();

		if ( isset( $filter_args['form-id'] ) ) {
			$form_ids = absint( $filter_args['form-id'] );
			$this->apply_entry_meta( $form_ids );
		} else {
			$form_ids = $this->get_workflow_form_ids();

			if ( empty( $form_ids ) ) {
				$this->items = array();
				return;
			}
		}

		$columns               = $this->get_columns();
		$hidden                = array();
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		$search_criteria = $this->get_search_criteria();

		$orderby = ( ! empty( $_GET['orderby'] ) ) ? $_GET['orderby'] : 'date_created';

		$order = ( ! empty( $_GET['order'] ) ) ? $_GET['order'] : 'desc';

		$user = get_current_user_id();
		if ( function_exists( 'get_current_screen' ) ) {
			$screen           = get_current_screen();
			if ( $screen ) {
				$option = $screen->get_option( 'per_page', 'option' );
			}

		}

		$per_page_setting = ! empty( $option ) ? get_user_meta( $user, $option, true ) : false;
		$per_page         = empty( $per_page_setting ) ? 20 : $per_page_setting;

		$page_size    = $per_page;
		$current_page = $this->get_pagenum();
		$offset       = $page_size * ($current_page - 1);

		$paging = array( 'page_size' => $page_size, 'offset' => $offset );

		$total_count = 0;

		$sorting = array( 'key' => $orderby, 'direction' => $order );

		$entries     = GFAPI::get_entries( $form_ids, $search_criteria, $sorting, $paging, $total_count );

		$this->pagination_args = array(
			'total_items' => $total_count,
			'per_page'    => $page_size,
		);

		$this->set_pagination_args( $this->pagination_args );

		$this->items = $entries;
	}

	public function get_search_criteria(){
		$filter_args = $this->get_filter_args();


		global $current_user;
		$search_criteria['status'] = 'active';

		if ( ! empty( $filter_args['start-date'] ) ) {
			$search_criteria['start_date'] = $filter_args['start-date'];
		}
		if ( ! empty( $filter_args['end-date'] ) ) {
			$search_criteria['end_date'] = $filter_args['end-date'] ;
		}

		if ( ! empty( $_GET['entry-id'] ) ) {
			$search_criteria['field_filters'][] = array(
				'key'   => 'id',
				'value' => absint( $_GET['entry-id'] ),
			);
		}

		if ( ! empty( $_GET['status'] ) ) {
			if ( $_GET['status'] == 'complete' ) {
				$search_criteria['field_filters'][] = array(
					'key'   => 'workflow_final_status',
					'operator' => 'not in',
					'value' => array( 'pending', 'cancelled' ),
				);
			} else {
				$search_criteria['field_filters'][] = array(
					'key'   => 'workflow_final_status',
					'value' => sanitize_text_field( $_GET['status'] ),
				);
			}
		}

		if ( ! $this->display_all ) {
			$search_criteria['field_filters'][] = array(
				'key'   => 'created_by',
				'value' => $current_user->ID,
			);
		}

		if ( ! empty( $filter_args['field_filters'] ) ) {
			$filters = ! empty( $search_criteria['field_filters'] ) ? $search_criteria['field_filters'] : array();
			$search_criteria['field_filters'] = array_merge( $filters, $filter_args['field_filters'] );
			$search_criteria['field_filters']['mode'] = 'all';
		}
		return $search_criteria;
	}

	public function get_field_filters_from_request( $form ) {
		$field_filters = array();
		$filter_fields = rgget( 'f' );
		if ( is_array( $filter_fields ) && $filter_fields[0] !== '' ) {
			$filter_operators = rgget( 'o' );
			$filter_values    = rgget( 'v' );
			for ( $i = 0; $i < count( $filter_fields ); $i ++ ) {
				$field_filter = array();
				$key          = $filter_fields[ $i ];
				if ( 'entry_id' == $key ) {
					$key = 'id';
				}
				$operator       = $filter_operators[ $i ];
				$val            = $filter_values[ $i ];
				$strpos_row_key = strpos( $key, '|' );
				if ( $strpos_row_key !== false ) { //multi-row likert
					$key_array = explode( '|', $key );
					$key       = $key_array[0];
					$val       = $key_array[1] . ':' . $val;
				}
				$field_filter['key']      = $key;

				$field = GFFormsModel::get_field( $form, $key );
				if ( $field ) {
					$input_type = GFFormsModel::get_input_type( $field );
					if ( $field->type == 'product' && in_array( $input_type, array( 'radio', 'select' ) ) ) {
						$operator = 'contains';
					}
				}

				$field_filter['operator'] = $operator;
				$field_filter['value']    = $val;
				$field_filters[]          = $field_filter;
			}
		}
		$field_filters['mode'] = rgget( 'mode' );

		return $field_filters;
	}

	public function get_steps($form_id){
		if ( ! isset( $this->_steps ) ) {
			$this->_steps = gravity_flow()->get_steps( $form_id );

		}
		return $this->_steps;
	}

	public function apply_entry_meta( $form_id ) {
		global $_entry_meta;

		$_entry_meta[ $form_id ] = apply_filters( 'gform_entry_meta', array(), $form_id );

		$steps = $this->get_steps( $form_id );

		$entry_meta = array();

		foreach ( $steps as $step ) {
			$assignees = $step->get_assignees();
			foreach ( $assignees as $assignee ) {
				$meta_key = sprintf( 'workflow_%s_%s', $assignee->get_type(), $assignee->get_id() );
				$entry_meta[ $meta_key ] = array(
					'label'             => __( 'Status:', 'gravityflow' ) . ' ' . $assignee->get_id(),
					'is_numeric'        => false,
					'is_default_column' => false,
				);
				$entry_meta[ $meta_key . '_timestamp' ] = array(
					'label'             => __( 'Status:', 'gravityflow' ) . ' ' . $assignee->get_id(),
					'is_numeric'        => false,
					'is_default_column' => false,
				);
			}
		}

		$_entry_meta[ $form_id ] = array_merge( $_entry_meta[ $form_id ], $entry_meta );
	}

	public function format_duration( $seconds ) {
		return gravity_flow()->format_duration( $seconds );
	}

} //class
