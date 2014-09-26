<?php
/**
*
* @package No Duplicate phpBB SEO
* @version $$
* @copyright (c) 2014 www.phpbb-seo.com
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

namespace phpbbseo\nodupe\event;

/**
* @ignore
*/
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
* Event listener
*/
class listener implements EventSubscriberInterface
{
	/** @var \phpbbseo\usu\core */
	protected $usu_core;

	/* @var \phpbb\user */
	protected $user;

	/* @var \phpbb\config\config */
	protected $config;

	/** @var \phpbb\auth\auth */
	protected $auth;

	/* @var \phpbb\content_visibility */
	protected $content_visibility;

	/**
	* Current $phpbb_root_path
	* @var string
	*/
	protected $phpbb_root_path;

	/**
	* Current $php_ext
	* @var string
	*/
	protected $php_ext;

	protected $posts_per_page = 1;

	protected $usu_rewrite = false;

	protected $topic_last_page = array();

	/* Limit in chars for the last post link text. */
	protected $char_limit = 25;

	/**
	* Constructor
	*
	* @param \phpbb\config\config		$config				Config object
	* @param \phpbb\auth\auth		$auth				Auth object
	* @param \phpbb\user			$user				User object
	* @param string				$phpbb_root_path		Path to the phpBB root
	* @param string				$php_ext			PHP file extension
	* @param \phpbbseo\usu\core		$usu_core			usu core object
	*/
	public function __construct(\phpbb\config\config $config, \phpbb\auth\auth $auth, \phpbb\user $user, $phpbb_root_path, $php_ext, \phpbbseo\usu\core $usu_core = null)
	{
		global $phpbb_container; // god save the hax

		$this->config = $config;

		$this->user = $user;
		$this->config = $config;
		$this->auth = $auth;
		$this->usu_core = $usu_core;
		$this->usu_rewrite = !empty($this->config['seo_usu_on']) && !empty($usu_core) && !empty($this->usu_core->seo_opt['sql_rewrite']) ? true: false;

		$this->content_visibility = $phpbb_container->get('content.visibility');
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;

		$this->posts_per_page = $this->config['posts_per_page'];
	}

	static public function getSubscribedEvents()
	{
		return array(
			'core.display_forums_modify_sql'		=> 'core_display_forums_modify_sql',
			'core.display_forums_modify_row'		=> 'core_display_forums_modify_row',
			'core.display_forums_modify_forum_rows'		=> 'core_display_forums_modify_forum_rows',
			'core.display_forums_modify_template_vars'	=> 'core_display_forums_modify_template_vars',
			'core.viewforum_modify_topicrow'		=> 'core_viewforum_modify_topicrow',
		);
	}

	public function core_display_forums_modify_sql($event)
	{
		$sql_ary = $event['sql_ary'];
		$sql_ary['SELECT'] .= ', t.topic_id, t.topic_title, t.topic_posts_approved, t.topic_posts_unapproved, t.topic_posts_softdeleted, t.topic_status, t.topic_type, t.topic_moved_id' . ($this->usu_rewrite && !empty($this->usu_core->seo_opt['sql_rewrite']) ? ', t.topic_url ' : ' ');
		$sql_ary['LEFT_JOIN'][] = array(
			'FROM'	=> array(TOPICS_TABLE => 't'),
			'ON'	=> "f.forum_last_post_id = t.topic_last_post_id"
		);

		$event['sql_ary'] = $sql_ary;
	}

	public function core_display_forums_modify_row($event)
	{
		$row = $event['row'];
		$forum_id = $row['forum_id'];

		if ($row['topic_status'] == ITEM_MOVED)
		{
			$row['topic_id'] = $row['topic_moved_id'];
		}

		if ($this->usu_rewrite) {
			$this->usu_core->set_url($row['forum_name'], $forum_id, 'forum');
		}

		// Replies
		$replies = $this->content_visibility->get_count('topic_posts', $row, $forum_id) - 1;

		if (($replies + 1) > $this->posts_per_page)
		{
			$this->topic_last_page[$row['topic_id']] = floor($replies / $this->posts_per_page) * $this->posts_per_page;
		}

		$event['row'] = $row;
	}

	public function core_display_forums_modify_forum_rows($event)
	{
		$forum_rows = $event['forum_rows'];
		$row = $event['row'];
		$parent_id = $event['parent_id'];

		$forum_rows[$parent_id]['topic_id'] = $row['topic_id'];
		$forum_rows[$parent_id]['topic_title'] = $row['topic_title'];
		$forum_rows[$parent_id]['topic_type'] = $row['topic_type'];
		$forum_rows[$parent_id]['forum_password'] = $row['forum_password'];
		$forum_rows[$parent_id]['topic_url'] = isset($row['topic_url']) ? $row['topic_url'] : '';

		$event['forum_rows'] = $forum_rows;
		$event['row'] = $row;
	}

	public function core_display_forums_modify_template_vars($event)
	{
		$row = $event['row'];

		if ($row['forum_last_post_id'])
		{
			$last_post_subject = $row['forum_last_post_subject'];
			$last_post_time = $this->user->format_date($row['forum_last_post_time']);

			if (!$row['forum_password'] && $this->auth->acl_get('f_read', $row['forum_id_last_post']))
			{
				if ($this->usu_rewrite) {
					$this->usu_core->prepare_iurl($row, 'topic', $row['topic_type'] == POST_GLOBAL ? $this->usu_core->seo_static['global_announce'] : $this->usu_core->seo_url['forum'][$row['forum_id_last_post']]);
				} else {
					$row['topic_title'] = censor_text($row['topic_title']);
				}
				$topic_title = $row['topic_title'];

				// Limit topic text link to $this->char_limit, without breacking words
				$topic_text_lilnk = $this->char_limit > 0 && ( ( $length = utf8_strlen($topic_title) ) > $this->char_limit ) ? ( utf8_strlen($fragment = utf8_substr($topic_title, 0, $this->char_limit + 1 - 4)) < $length + 1 ? preg_replace('`\s*\S*$`', '', $fragment) . ' ...' : $topic_title ) : $topic_title;

				$forum_row = $event['forum_row'];
				$forum_row['LAST_POST_SUBJECT'] = $topic_title;
				$forum_row['LAST_POST_SUBJECT_TRUNCATED'] = $topic_text_lilnk;

				$_start = @intval($this->topic_last_page[$row['topic_id']]);
				$_start = $_start ? "&amp;start=$_start" : '';
				$forum_row['U_LAST_POST'] = append_sid("{$this->phpbb_root_path}viewtopic.$this->php_ext", 'f=' . $row['forum_id_last_post'] . '&amp;t=' . $row['topic_id'] . $_start) . '#p' . $row['forum_last_post_id'];

				$event['forum_row'] = $forum_row;
			}
		}
	}

	public function core_viewforum_modify_topicrow($event)
	{
		// Unfortunately, we do not have direct access to $topic_forum_id here
		global $topic_forum_id; // god save the hax

		$row = $event['row'];
		$topic_row = $event['topic_row'];
		$replies = $topic_row['REPLIES'];
		$topic_id = $topic_row['TOPIC_ID'];

		if (($replies + 1) > $this->posts_per_page)
		{
			$this->topic_last_page[$topic_id] = floor($replies / $this->posts_per_page) * $this->posts_per_page;
		}

		if (!empty($this->usu_core))
		{
			$this->usu_core->prepare_topic_url($row, $topic_forum_id);
		}

		$topic_row['U_LAST_POST'] = append_sid("{$this->phpbb_root_path}viewtopic.$this->php_ext", 'f=' . $topic_forum_id . '&amp;t=' . $topic_id . '&amp;start=' . @intval($this->topic_last_page[$topic_id])) . '#p' . $row['topic_last_post_id'];

		$event['topic_row'] = $topic_row;
	}
}
