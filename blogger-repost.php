<?php
/*
Plugin Name: Crosspost to Blogger
Plugin URI: http://www.bbqiguana.com/
Description: Re-posts published posts to anything that implements the Blogger API (such as <a href="http://www.blogger.com/">Blogger</a> itself or <a href="http://www.drupal.org/">Drupal</a>) with directions back to the original Wordpress blog for updates and comments.  Also edits and deletes according to WordPress' actions.	BAC is based on <a href="http://ryanlee.org/software/wp/croissanga/">Croissanga</a> and the <a href="http://www.dentedreality.com.au/bloggerapi/class/">bloggerapi class</a> (made available by Beau Lebens).
Version: 0.5
Author: Randy Hunt
Author URI: http://www.bbqiguana.com/
*/

require_once 'Zend/Loader.php';

class Blogger
{
	public $blogID;
	public $gdClient;
	public $blogs;
	
	public function __construct ($email, $password)
	{
		$incpath = get_include_path();
		set_include_path(dirname(__FILE__));

		Zend_Loader::loadClass('Zend_Gdata');
		Zend_Loader::loadClass('Zend_Gdata_Query');
		Zend_Loader::loadClass('Zend_Gdata_Feed');
		Zend_Loader::loadClass('Zend_Gdata_ClientLogin');

		//set_include_path($incpath);

		$client = Zend_Gdata_ClientLogin::getHttpClient($email, $password, 'blogger');
		$this->gdClient = new Zend_Gdata($client);
		$this->getBlogList();
	}
	
	public function getBlogList ()
	{
		$uri = 'http://www.blogger.com/feeds/default/blogs';
		$query = new Zend_Gdata_Query($uri);
		$feed = $this->gdClient->getFeed($query);
 
		$results = array();
		foreach($feed->entries as $entry)
		{
			$idtxt = explode('-', $entry->id->text);
			$key = $idtxt[2];
			$val = $entry->title->text;
			$results[ $key ] = $val;
		}
		reset($results);
		if(0 < count($results))
			$this->blogID = key($results); 
		$this->blogs = $results;
	}
	
	public function createPost ($title, $content, $date, $isDraft = false)
	{
		$entry = $this->gdClient->newEntry();

		$entry->title = $this->gdClient->newTitle(trim($title));
		$entry->content = $this->gdClient->newContent(trim($content));
		$entry->content->setType('text');
		$entry->published = $this->gdClient->newPublished(trim($date));
		$uri = "http://www.blogger.com/feeds/" . $this->blogID . "/posts/default";
		if ($isDraft)
		{
			$control = $this->gdClient->newControl();
			$draft = $this->gdClient->newDraft('yes');
			$control->setDraft($draft);
			$entry->control = $control;
		}
		$createdPost = $this->gdClient->insertEntry($entry, $uri);
		$idText = explode('-', $createdPost->id->text);
		$postID = $idText[2];
		return $postID;
	}
	
	public function editPost ($post_id, $title, $content, $date, $isDraft)
	{
		$query = new Zend_Gdata_Query('http://www.blogger.com/feeds/' . $this->blogID . '/posts/default/' . $postID);
		$postToUpdate = $this->gdClient->getEntry($query);
		$postToUpdate->title->text = $this->gdClient->newTitle(trim($title));
		$postToUpdate->content->text = $this->gdClient->newContent(trim($content));
		if ($isDraft) {
			$draft = $this->gdClient->newDraft('yes');
		} else {
			$draft = $this->gdClient->newDraft('no');
		$postToUpdate->published->text = $date;
		}
		$control = $this->gdClient->newControl();
		$control->setDraft($draft);
		$postToUpdate->control = $control;
		$updatedPost = $postToUpdate->save();
		return $updatedPost;
	}
	
	public function deletePost ($post_id)
	{
		$uri = 'http://www.blogger.com/feeds/' . $this->blogID . '/posts/default/' . $post_id;
		$this->gdClient->delete($uri);
	}
}

function BloggerCrossposter_publish ($post_id) {
	$post = get_post($post_id);
	$blogger = new Blogger('yearlyglot@gmail.com', 'fluentYearly1');

	$title = $post->post_title;
	if ('' == $post->password)
		$content = BloggerCrossposter_truncate($post->post_content, ' ', 500);
	else
		$content = 'This post is password protected.';
	
	$blog_title = 'Fluent Every Year';
	$content .= '... <a href="'.$post->permalink.'">continue reading at ' . $blog_title . '</a>';
	$date = str_replace(' ', 'T', $post->post_date_gmt);

	$id = BloggerCrossposter_postid($post_id);
	if (0 == $id)
	{
		$id = $blogger->createPost($title, $content, $date, false);
		add_post_meta($post_id, 'blogger_postid', $id);
	}
	else 
		$blogger->edit($id, $title, $content, $date, false);
	return $id;
}

function BloggerCrossposter_delete ($post_id) {
	$blogger = new Blogger('yearlyglot@gmail.com', 'fluentYearly1');
	$id = BloggerCrossposter_postid($post_id);
	if (0 != $id) {
		$blogger->deletePost($id);
		delete_post_meta($post_id, 'blogger_postid', $id);
	}
}

function BloggerCrossposter_truncate ($text, $token, $limit) {
	$text = strip_tags($text);
	if (strlen($text) < $limit) return $text;
	if (false !== ($breakpoint = strpos($text, $token, $limit))) {
		if($breakpoint < strlen($text) - 1) {
			$text = substr($text, 0, $breakpoint);
		}
	}
	return $text;
}

function BloggerCrossposter_postid ($post_id) {
	$id = get_post_meta($post_id, 'blogger_postid', true);
	if ($id == '') return 0;
	else return $id;
}

add_action('publish_post', 'BloggerCrossposter_publish', 9);
//add_action('edit_post', 'BloggerCrossposter_publish');
add_action('delete_post', 'BloggerCrossposter_delete');

?>
