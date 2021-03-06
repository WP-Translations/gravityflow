<?php
/**
 * Gravity Flow Step Feed Pipedrive
 *
 *
 * @package     GravityFlow
 * @subpackage  Classes/Gravity_Flow_Step_Feed_Pipedrive
 * @copyright   Copyright (c) 2016-2017, Steven Henty S.L.
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.5.1-dev
 */

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

class Gravity_Flow_Step_Feed_Pipedrive extends Gravity_Flow_Step_Feed_Add_On {
	public $_step_type = 'pipedrive';

	protected $_class_name = 'WPGravityFormsToPipeDriveCRM';
	protected $_slug = 'gf2pdcrm';
	protected $_addon_instance = null;

	public function get_label() {
		return 'Pipedrive';
	}

	/**
	 * Retrieve an instance of the add-on associated with this step.
	 *
	 * @return GFFeedAddOn
	 */
	public function get_add_on_instance() {
		if ( ! is_object( $this->_add_on_instance ) ) {
			$add_on = new WPGravityFormsToPipeDriveCRM();
			$add_on->wpgf2pdcrm_load_addon();
			$this->_add_on_instance = $add_on->_wpgf2pdcrm_addon_OBJECT;
		}

		return $this->_add_on_instance;
	}
}

Gravity_Flow_Steps::register( new Gravity_Flow_Step_Feed_Pipedrive() );
