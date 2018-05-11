<?php

	/**
	 * Plugin Name: FileRenameReplace
	 * Plugin URI: http://wordpress.org/plugins/file-metadata-modifier/
	 * Description: It lets you renaming or replacing files without breaking links.
	 * Author: Albin Soft
	 * Version: 1.0
	 * Author URI: https://www.albinsoft.es/
	 **/

	if(!defined('ABSPATH')) exit;

class FileRenameReplace {

	var $menu_id = null;

	public function __construct() {
		$this->capability = apply_filters('filerenamereplace_cap', 'manage_options');

		add_action( 'admin_menu',                             array($this, 'add_admin_menu' ) );
		add_action( 'admin_head-upload.php',                  array($this, 'js_add_bulk_actions') );
		add_filter( 'media_row_actions',                      array($this, 'media_row_action'   ), 10, 2 );
		add_action( 'admin_action_bulk_filerenamereplace',    array($this, 'media_bulk_action'  ) );
		add_action( 'attachment_submitbox_misc_actions',      array($this, 'media_edit_page'    ), 99 );
	}

	public function add_admin_menu() {
		$this->menu_id = add_management_page( 'Replace/Rename', 'Replace/Rename', 'edit_posts', 'filerenamereplace',  array($this, 'render_ui') );
	}

	public function js_add_bulk_actions() {
		?><script type="text/javascript">
			jQuery(document).ready(function($){
				$('select[name^="action"] option:last-child').before('<option value="bulk_filerenamereplace">Replace/Rename</option>');
			});
		</script><?php
	}

	public function media_row_action($actions, $post) {
	//	$url = wp_nonce_url(admin_url('tools.php?page=filerenamereplace&iid='.$post->ID), 'filerenamereplace');
		$url = admin_url('tools.php?page=filerenamereplace&iid='.$post->ID);
		$actions['filerenamereplace'] = '<a href="' . esc_url( $url ) . '" title="Replace/Rename">Replace/Rename</a>';
		return $actions;
	}

	public function media_bulk_action() {
		if( empty($_REQUEST['action']) || ('bulk_filerenamereplace'!=$_REQUEST['action']) ) return;
		if( empty($_REQUEST['media']) || !is_array($_REQUEST['media']) ) return;

		check_admin_referer( 'bulk-media' );

		$iid = implode(chr(44), array_map('intval', $_REQUEST['media']));
	//	$url = add_query_arg('_wpnonce', wp_create_nonce('filerenamereplace'), admin_url('admin.php?page=filerenamereplace&iid='.$iid) );
		$url = admin_url('admin.php?page=filerenamereplace&iid='.$iid);
		wp_redirect($url);
		exit();
	}

	public function media_edit_page() {
		global $post;
		if('image/'!=substr($post->post_mime_type, 0, 6 )) return; // || !current_user_can($this->capability)

		echo '<div class="misc-pub-section misc-pub-file-meta-modifier">';
		echo '<a href="admin.php?page=filerenamereplace&iid='.$post->ID.'" class="button-secondary button-large" title="Replace/Rename">Replace/Rename</a>';
		echo '</div>';
	}

	public function render_ui() {
		include('wpa-imageman.php');
	}

	static public function rename_media($iid, $new_filepath, $new_filename, &$err = FALSE) {
		global $wpdb;
		$updir    = wp_upload_dir();
		$basedir  = $updir['basedir'];
		$attafile = get_post_meta($iid, '_wp_attached_file', true);
		$metadata = wp_get_attachment_metadata($iid, true);
		$old_filepath = dirname($attafile);
		$old_filename = basename($attafile);
		$oldpath = $basedir.SEP.$old_filepath.SEP;
		$newpath = $basedir.SEP.$new_filepath.SEP;
		$ok      = file_exists($newpath) || mkdir($newpath, 0755, true);
		if($ok) {
			$old_files = array( 'original' => $old_filename );
			$new_files = array( 'original' => $new_filename );
			if(is_array($metadata) && !empty($metadata['sizes'])) {
				if(!empty($metadata['file']))
					$metadata['file'] = $new_filepath.SEP.$new_filename;
				$old_base  = self::get_filename($old_filename);
				$new_base  = self::get_filename($new_filename);
				foreach($metadata['sizes'] as $name=>$values) {
					$old_files[$name] = $values['file'];
					$new_files[$name] = str_replace($old_base, $new_base, $values['file']);
					$metadata['sizes'][$name]['file'] = $new_files[$name];
				}
			}
			$attafile = $new_filepath.SEP.$new_filename;
			foreach($old_files as $name=>$file) {
				if(!file_exists($oldpath.$old_files[$name])) {
					if($err!==FALSE) $err = 'No se pudo encontró el fichero de origen: '.$oldpath.$old_files[$name];
				} else if(!rename($oldpath.$old_files[$name], $newpath.$new_files[$name])) {
					if($err!==FALSE) $err = 'No se pudo mover el fichero: '.$oldpath.$old_files[$name].' » '.$newpath.$new_files[$name];
				}
			}
			if($err===FALSE) {
				$oldfile = $old_filepath.SEP.$old_filename;
				$newfile = $new_filepath.SEP.$new_filename;
				$sql = "
					UPDATE wp_postmeta SET meta_value='{$newfile}'
					WHERE meta_key='_wp_attached_file' AND meta_value='{$oldfile}'
				";
				if(is_array($metadata) && !empty($metadata['file']) && !empty($metadata['sizes'])) {
					if(wp_update_attachment_metadata($iid, $metadata)) {
						$wpdb->query($sql);
						wp_cache_delete($iid, 'post_meta');
					}
				} else {
					$wpdb->query($sql);
					wp_cache_delete($iid, 'post_meta');
				}
			}
		} else {
			if($err!==FALSE) $err = 'No se pudo crear el directorio destino: '.$newpath;
		}
		return $err===FALSE;
	}

	static public function redate_media($aid, $new_postdate, &$err = FALSE) {
		$err = wp_update_post([ 'ID'=>$aid, 'post_date_gmt'=>$new_postdate ], true);
		if(is_numeric($err) && $err===$aid) $err = FALSE;
	}

	static public function get_filename($path) {
		return pathinfo($path, PATHINFO_FILENAME);
	}


	static function sanitize_date_japan($text) {
		if(1===preg_match('|^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$|', $text))
			 return $text;
		return null;
	}


	static function sanitize_file_path($text) {
		if(1===preg_match('|^([\w\-]+/?)+$|', $text))
			 return $text;
		return null;
	}
}

add_action( 'init', 'FileRenameReplace' );

function FileRenameReplace() {
	new FileRenameReplace();
}


?>