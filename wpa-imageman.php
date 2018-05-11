<?

	if(!defined('ABSPATH')) exit;

	global $wpdb;

//	check_admin_referer('filerenamereplace'); // user can come from /wp-admin/upload.php

	$err = FALSE;
	$iids = isset($_GET['iid']) ? $_GET['iid'] : NULL;
	if($iids!==null) {
		if(is_string($iids)) $iids = explode(chr(44), $iids);
		if(!is_array($iids)) $iids = [$iids];
		array_map('intval', $iids);
		$iids_cs = implode(chr(44), $iids);
		$iids_ds = implode(chr(45), $iids);
	}

	$updir = wp_upload_dir();

	if(!empty($_POST) && ($_POST['action']=='Save' || $_POST['action']=='Remove')) {
		$new_fileids   = isset($_POST['fileid'])   && is_array($_POST['fileid'])   ? array_map('intval',                    $_POST['fileid'])   : [];
		$new_postdates = isset($_POST['postdate']) && is_array($_POST['postdate']) ? array_map('self::sanitize_date_japan', $_POST['postdate']) : [];
		$new_filepaths = isset($_POST['filepath']) && is_array($_POST['filepath']) ? array_map('self::sanitize_file_path',  $_POST['filepath']) : [];
		$new_filenames = isset($_POST['filename']) && is_array($_POST['filename']) ? array_map('sanitize_file_name',        $_POST['filename']) : [];
		$new_files     = isset($_FILES['newfile']) && is_array($_FILES['newfile']) ? $_FILES['newfile'] : [];
	}

	// Here is where the action really happens, therefore is where we check for the nonce
	if(!empty($_POST) && $_POST['action']=='Save' && check_admin_referer('renamereplace-'.$iids_ds)) {
		$basedir = $updir['basedir'];

		$max = min(count($new_fileids), count($new_filepaths), count($new_filenames));
		for($itr=0; $itr<$max; $itr++) {
			$new_fileid   = $new_fileids[$itr];
			$new_postdate = $new_postdates[$itr];
			$new_filepath = $new_filepaths[$itr];
			$new_filename = $new_filenames[$itr];

			$post     = get_post($new_fileid);
			$postdate = $post->post_date_gmt;
			$metadata = wp_get_attachment_metadata($new_fileid, true);
			if(!empty($metadata)) {
				$old_filepath = dirname($metadata['file']);
				$old_filename = basename($metadata['file']);
			} else {
				$metadata = get_post_meta($new_fileid, '_wp_attached_file', true);
				$old_filepath = dirname($metadata);
				$old_filename = basename($metadata);
			}
			if(($old_filepath!==$new_filepath && $new_filepath!==null) || ($old_filename!==$new_filename && $new_filename!==null)) {
				$this->rename_media($new_fileid, $new_filepath, $new_filename, $err);
			}
			if($postdate!==$new_postdate && $new_postdate!==null) {
				$this->redate_media($new_fileid, $new_postdate, $err);
			}
		}

		$max = count($new_files['tmp_name']);
		for($itr=0; $itr<$max; $itr++) {
			$new_fileid   = intval($new_fileids[$itr]);
			if($new_files['error'][$itr]===0 && $new_files['size'][$itr]!==0) {
				$metadata = wp_get_attachment_metadata($new_fileid, true);
				$tmppath = $new_files['tmp_name'][$itr];
				$newpath = $basedir.SEP.$metadata['file'];
				if(unlink($newpath)) {
					move_uploaded_file($tmppath, $newpath);
					$tmpdir = dirname($metadata['file']);
					foreach($metadata['sizes'] as $name=>$values) {
						$tmppath = $basedir.SEP.$tmpdir.SEP.$values['file'];
						if(file_exists($tmppath)) unlink($tmppath);
					}
					$metadata = wp_generate_attachment_metadata($new_fileid, $newpath);
					wp_update_attachment_metadata($new_fileid, $metadata);
				} else {
					$err = 'No existe el fichero a sobreescribir';
				}
			}
		}

	}

	// Here is where the action really happens, therefore is where we check for the nonce
	if(!empty($_POST) && $_POST['action']=='Remove' && check_admin_referer('renamereplace-'.$iids_ds)) {
		$basedir = $updir['basedir'];

		$max = (count($new_fileids));
		for($itr=0; $itr<$max; $itr++) {
			$new_fileid = $new_fileids[$itr];
			$ids_rows = $wpdb->get_results("SELECT element_id FROM `wp_icl_translations` WHERE trid = (SELECT trid FROM wp_icl_translations WHERE element_id={$new_fileid} AND element_type='post_attachment')");
			foreach($ids_rows as $row) {
				$element_id = $row->element_id;
				if(wp_delete_attachment($element_id, true)===FALSE) echo '<p>Failed '.$element_id.'</p>';
			}
		//	wp_delete_post($new_fileid, true);
		}

		$iids = NULL;
	}

?>
<h1>File Replace / Rename</h1>
<p>From this page you can face two goals:</p>
<ul>
	<li><strong>File Replace</strong> Sometimes you have an image used in several spots (slider, gallery, feature image, ...) and if you remove it and upload it again, as it gets a new ID, you will need to select the new one all around but you could forget a place. To avoid it, use this plugin.</li>
	<li><strong>File Rename</strong> Sometimes we upload a file as it comes from the mobile camera and later we realize that the file doesn´t have a ver SEO-Friendly name. Here you can rename the file.</li>
</ul>
<p>None of both functionalities will breake any link where the image is pointed by its ID <b>but</b> this action doesn´t do a <i>Search and Replace</i> along the post entries.</p>
<?
	if($err!==FALSE) { echo "<p>ERROR: {$err}</p>"; }
?>
<?
	if($iids===NULL) {
		?><form action="tools.php" method="GET">
			<input type="hidden" name="page" value="filerenamereplace" />
			<input type="text" name="iid" value="" placeholder="ID imagen" />
			<input type="submit" value="Cargar" />
		</form><script>
		window.addEventListener('load', function () { document.querySelector('[name=iid]').focus(); });
		</script><?
	} else {
		?><form action="tools.php?page=filerenamereplace&iid=<?=urlencode($iids_cs) ?>" method="POST" enctype="multipart/form-data"><?
		wp_nonce_field( 'renamereplace-'.$iids_ds );
		foreach($iids as $idx=>$iid) {
			$post     = get_post($iid);
			if($post===NULL) {
				continue;
			} elseif($idx===0 && count($iids)!==1) {
				?><input class="button button-primary" type="submit" name="action" value="Save" /><?
				if(count($iids)===1) {
					?> <input class="button button-secondary" type="submit" name="action" value="Remove" /> <?
				}
			}
			$postdate = $post->post_date_gmt;
			$metadata = wp_get_attachment_metadata($iid, true);
			if(!empty($metadata)) {
			//	var_dump($metadata);
				$fileurl  = $updir['baseurl'].SEP.$metadata['file'];
				$filepath = dirname($metadata['file']);
				$filename = basename($metadata['file']);
			} else {
				$metadata = get_post_meta($iid, '_wp_attached_file', true);
				$fileurl  = $updir['baseurl'].SEP.$metadata;
				$filepath = dirname($metadata);
				$filename = basename($metadata);
			}
			?><div style="margin: 15px 20px 15px 0px; padding: 10px; background: #FFFFFF;">
				<table style="width: 100%;"><tr>
					<td style="vertical-align: top;">
						<input type="hidden" name="fileid[]"   value="<?=$iid ?>" />
						<table>
						<tr>
							<td>Date modify:</td>
							<td><input type="text"   name="postdate[]" value="<?=$postdate ?>" placeholder="File date" size="20" /></td>
						</tr><tr>
							<td>Move to other folder:</td>
							<td><input type="text"   name="filepath[]" value="<?=$filepath ?>" placeholder="Folder"    size="20" /></td>
						</tr><tr>
							<td>Rename the file:</td>
							<td><input type="text"   name="filename[]" value="<?=$filename ?>" placeholder="Filename"  size="65" /></td>
						</tr><tr>
							<td>Replace the file:</td>
							<td><input type="file"   name="newfile[]"  placeholder="Change file" /></td>
						</tr>
						</table>
						<? // var_dump($post); var_dump($metadata); ?>
					</td>
					<td style="width: 150px; height: 150px; vertical-align: top;"><?
						if(is_array($metadata) && !empty($metadata['file'])) {
							?><img src="<?=$updir['baseurl'].SEP.$metadata['file'] ?>" style="max-width: 150px; max-height: 150px;" /><?
							?><br/><?=$postdate ?><?
							?><br/><?=$metadata['width'] ?> &times; <?=$metadata['height'] ?>px<?
							?><br/><?=round(filesize($updir['basedir'].SEP.$metadata['file'])/1024) ?> Kb<?
						} elseif($post->post_mime_type==='image/svg+xml') {
							?><img src="<?=$updir['baseurl'].SEP.$metadata ?>" style="max-width: 150px; max-height: 150px;" /><?
							?><br/><?=$postdate ?><?
							?><br/><?=round(filesize($updir['basedir'].SEP.$metadata)/1024) ?> Kb<?
						} else {
							?><a href="<?=$fileurl ?>" target="_blank">ver documento</a><?
							?><br/><?=round(filesize($updir['basedir'].SEP.$metadata['file'])/1024) ?> Kb<?
						}
					?></td>
				</tr></table>
			</div><?
		}
		if($post!==NULL) {
			?><input class="button button-primary" type="submit" name="action" value="Save" /><?
			if(count($iids)===1) {
				?> <input class="button button-secondary" type="submit" name="action" value="Remove" /> <?
			}
		}
		?></form><?
	}

?>