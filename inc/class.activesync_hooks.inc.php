<?php
/**
 * EGroupware: eSync - ActiveSync protocol based on Z-Push: hooks: eg. preferences
 *
 * @link http://www.egroupware.org
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package esync
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @version $Id: class.activesync_hooks.inc.php 34909 2011-05-07 16:51:57Z ralfbecker $
 */

/**
 * eSync hooks: eg. preferences
 */
class activesync_hooks
{
	/**
	 * Show E-Push preferences link in preferences
	 *
	 * @param string|array $args
	 */
	public static function menus($args)
	{
		$appname = 'activesync';
		$location = is_array($args) ? $args['location'] : $args;

		if ($location == 'preferences')
		{
			$file = array(
				'Preferences'     => egw::link('/index.php','menuaction=preferences.uisettings.index&appname='.$appname),
			);
			if ($location == 'preferences')
			{
				display_section($appname,$file);
			}
			else
			{
				display_sidebox($appname,lang('Preferences'),$file);
			}
		}
	}

	/**
	 * Populates $settings for the preferences
	 *
	 * Settings are gathered from all plugins and grouped in sections for each application/plugin.
	 *
	 * @param array|string $hook_data
	 * @return array
	 */
	static function settings($hook_data)
	{
		$settings = array();

		set_include_path(EGW_SERVER_ROOT.'/activesync' . PATH_SEPARATOR . get_include_path());
		require_once EGW_INCLUDE_ROOT.'/activesync/backend/egw.php';
		$backend = new BackendEGW();

		$last_app = '';
		foreach($backend->settings($hook_data) as $name => $data)
		{
			// check if we are on a different app --> create a section header
			list($app) = explode('-',$name);
			if ($last_app !== $app && isset($GLOBALS['egw_info']['apps'][$app]))
			{
				$settings[] = array(
					'type'  => 'section',
					'title' => $app,
				);
				$last_app = $app;
			}
			$settings[$name] = $data;
		}
		return $settings;
	}
}