<?php
/**
*
* @package No Duplicate phpBB SEO
* @version $$
* @copyright (c) 2014 www.phpbb-seo.com
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace phpbbseo\nodupe\migrations;

class release_2_0_0_b1 extends \phpbb\db\migration\migration
{
	const SQL_INDEX_NAME = 'topic_lpid';

	public function effectively_installed()
	{
		if (!empty($this->config['seo_no_dupe_on']))
		{
			$indexes = $this->db_tools->sql_list_index(TOPICS_TABLE);
			return in_array(self::SQL_INDEX_NAME, $indexes);
		}

		return false;
	}

	static public function depends_on()
	{
		global $config; // god save the hax

		if (!empty($config['seo_usu_on']))
		{
			// this hax is required because it's not enough if files
			// are present on the server, the usu ext must be actually installed
			return array('\phpbbseo\usu\migrations\release_2_0_0_b1');
		}

		// probably the wierdest message transport ever :O
		return array('You must install/activate the USU first from phpBB SEO');
	}

	public function update_schema()
	{
		return array(
			'add_index'	=> array(
				TOPICS_TABLE	=> array(
					self::SQL_INDEX_NAME	=> array(
						'topic_last_post_id',
					),
				),
			),
		);
	}

	public function revert_schema()
	{
		return array(
			'drop_keys'	=> array(
				TOPICS_TABLE	=> array(
					self::SQL_INDEX_NAME,
				),
			),
		);
	}

	public function update_data()
	{
		return array(
			array('config.add', array('seo_no_dupe_on', 1)),
		);
	}
}
