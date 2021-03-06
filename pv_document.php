<?php

/*
Plugin Name: Document
Plugin URI: http://www.vdvreede.net
Description: Adds document post type to wordpress for a document library.
Version: 0.9
Author: Paul Van de Vreede
Author URI: http://www.vdvreede.net
*/

add_action('init', 'pvd_register_document_type');
add_action('add_meta_boxes', 'pvd_add_document_meta_box');
add_action('save_post', 'pvd_save_document_data');
add_action('trash_post', 'pvd_delete_document_attachments');

add_filter('the_content', 'pvd_format_content');
add_filter('post_mime_types', 'pvd_add_mime_type_filter');
add_filter('posts_where', 'pvd_where_add_documents' );

add_filter('manage_edit-pv_document_columns', 'pvd_add_post_columns');
add_action('manage_posts_custom_column', 'pvd_display_post_columns');

add_action('admin_head', 'pvd_add_style_sheet');

/**
 * Function to create the document post type and its corresponding
 * categories.
 */
function pvd_register_document_type() {
    register_post_type('pv_document', array(
        'labels' => array(
            'name' => 'Documents',
            'singular_name' => _x('Document', 'post type singular name'),
            'add_new' => _x('Add New', 'document item'),
            'add_new_item' => __('Add New Document'),
            'edit_item' => __('Edit Document'),
            'new_item' => __('New Document'),
            'view_item' => __('View Document'),
            'search_items' => __('Search Documents'),
            'not_found' => __('Nothing found'),
            'not_found_in_trash' => __('Nothing found in Trash'),
            'parent_item_colon' => ''
        ),
        'public' => true,
        'taxonomies' => array('category', 'post_tag'),
        'menu_position' => 5
    ));
}

function pvd_add_style_sheet() {
    if (strlen(strstr($_SERVER['REQUEST_URI'], 'wp-admin/edit.php')) &&
        strlen(strstr($_SERVER['REQUEST_URI'], 'post_type=pv_document')))
        echo "<link rel='stylesheet' type='text/css' href='". plugins_url('style.css', __FILE__)."' />";
}

function pvd_add_document_meta_box() {
    add_meta_box('pv_document_items', 'Add Attachment', 'pvd_render_document_meta_box', 'pv_document');
}

function pvd_render_document_meta_box($post) {

    if ($post->post_type != 'pv_document')
            return;
    
    $current_attachment = pvd_get_post_attachments($post->ID);
    
    $attachments = pvd_get_post_attachments();
    
    if (count($attachments) > 0) {   
        // Use nonce for verification
        wp_nonce_field(plugin_basename(__FILE__), 'pv_document_noncename');
    
        $output = '<select name="pv_document_attachment">';
        
        // if there isnt a current attachment display normal list, otherwise highlight the current and give option to unlink.
        if (count($current_attachment) == 0) {
            $output .= '<option value="0">Select an attachment to link...</option>';
        } else {
            $output .= '<option value="0">Unlink current attachment</option>';
            $output .= '<option value="' . $current_attachment[0]->ID . '" selected>' . pvd_get_filename_from_guid($current_attachment[0]->guid) . '</option>';            
        }
        
        foreach ($attachments as $attachment) {
                $output .= '<option value="' . $attachment->ID . '">' . pvd_get_filename_from_guid($attachment->guid) . '</option>';          
        }
    
        $output .= '</select>';
    } else {
        $output = '<p>There are no documents currently in the media library to attach.</p>';       
    }
    $output .= '<p>This list only contains files that are unattached. If you cannot see your files, you will need to go to its currently attached document and unattach it first.</p>';
    $output .= '<p>To add new documents to the library <a href="/wp-admin/media-new.php">click here</a>.</p>';
    
    echo $output;
}

function pvd_get_filename_from_guid($guid) {
	return substr($guid, strrpos($guid, '/') + 1, strlen($guid) - 1);
}

function pvd_save_document_data($post_id) {

    // verify if this is an auto save routine. 
    // If it is our form has not been submitted, so we dont want to do anything
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return;

    if (!wp_verify_nonce($_POST['pv_document_noncename'], plugin_basename(__FILE__)))
        return;

    // only operate when its a document type
    if ($_POST['post_type'] != 'pv_document')
        return;

    if (!current_user_can('edit_post', $post_id))
        return;
    
    // make sure the document is being submitted, in case there arent any.
    if (!isset($_POST['pv_document_attachment']))
        return;
    
    $attachment_id = $_POST['pv_document_attachment'];
    

    // remove all attachments if there are any so that there are no double ups
    $posts = pvd_get_post_attachments($post_id);
    
    foreach ($posts as $post) {
        pvd_update_attachment_with_doc($post->ID, 0);    
    }
    
    // if 0 then the users wants to unlink all and not add any others, so exit
    if ($attachment_id == '0') 
        return;
    
    pvd_update_attachment_with_doc($attachment_id, $post_id);
}

function pvd_delete_document_attachments($post_id) {
    // remove all attachments if there are any so that there are no double ups
    $posts = pvd_get_post_attachments($post_id);
    
    foreach ($posts as $post) {
        pvd_update_attachment_with_doc($post->ID, 0);    
    }
}

function pvd_update_attachment_with_doc($attachment_id, $doc_id) {
    $attachment = array();
    $attachment['ID'] = $attachment_id;
    $attachment['post_parent'] = $doc_id;
    wp_update_post($attachment); 
}

function pvd_format_content($content) {
    global $post;

    if ($post->post_type != 'pv_document')
        return $content;

    $attachments = pvd_get_post_attachments($post->ID);
    
    $content .= '<div class="pv_document_items">';
    
    if (count($attachments) < 1) {      
        $content .= '<p class="pv_document_no_items">There are no documents to download.</p>';
        return $content;
    }

    foreach ($attachments as $attachment) {
        // get the filename to work out the file size.
        $file_path = '/' . get_post_meta($attachment->ID, '_wp_attached_file', true);      
        $middle_path = get_option('upload_path');
        $full_filename = ABSPATH . $middle_path . $file_path;         
        $byte_size = filesize($full_filename);
        $mb_size = ($byte_size / 1024) / 1024;
        
        
        $content .= '<div class="pv_document_item">';
        $content .= '<img src="' . pvd_get_attachment_icon_url( $attachment->post_mime_type ) . '" />';
        $content .= '<p>' . basename($file_path) . '<br />';
        $content .= '<a href="' . wp_get_attachment_url($attachment->ID) . '">Download</a><br />';
        $content .= 'File size: ' . number_format($mb_size, 2) . 'MB</p>';
        $content .= '</div>';
    }
    
    $content .= '</div>';
    
    return $content;
}

function pvd_add_post_columns($posts_columns) {
    
    $posts_columns['pvd_attachment'] = 'Attachment';
    
    return $posts_columns;
}

function pvd_display_post_columns($column_name) {
    global $post;
    
    if ($column_name == 'pvd_attachment') {
        
        $attachments = pvd_get_post_attachments($post->ID);
        
        if (count($attachments) > 0) {
        
        foreach ($attachments as $attachment) {
            echo pvd_get_filename_from_guid($attachment->guid);
            echo '<br />';
        }
        
        } else {
            echo 'None';
        }
        
    }
}

function pvd_get_attachment_icon_url( $mime_type ) {
    
    switch ( $mime_type ) {
        
         case 'application/vnd.ms-excel':
         case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
         case 'application/vnd.openxmlformats-officedocument.spreadsheetml.template':
             $image_filename = 'xls.png';
             break;
         
         case 'application/msword':
         case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
             $image_filename = 'doc.png';
             break;
         
         case 'application/vnd.ms-powerpoint':
         case 'application/vnd.openxmlformats-officedocument.presentationml.presentation':
             $image_filename = 'ppt.png';
             break;
         
         case 'application/pdf':
             $image_filename = 'pdf.png';
             break;
         
         case 'application/zip':
             $image_filename = 'zip.png';
             break;
             
         default:
             $image_filename = 'txt.png';
             break;
        
    }
    
    return plugins_url( '/images/' . $image_filename , __FILE__ );
    
}

function pvd_add_mime_type_filter($post_mime_types) {
    $post_mime_types['application/pdf'] = array('PDF', 'Manage PDF', 'PDF (%s)');
    
    $post_mime_types['application/vnd.openxmlformats-officedocument.wordprocessingml.document'] = array('Word', 'Manage Word', 'Word (%s)');
    $post_mime_types['application/msword'] = array('Word', 'Manage Word', 'Word (%s)');
    
    $post_mime_types['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'] = array('Spreadsheet', 'Manage Spreadsheet', 'Spreadsheets (%s)');
    // TODO: Add more mime types for other doc types eg, Word, spreadsheet

    return $post_mime_types;
}

function pvd_where_add_documents( $where ) {
    global $wpdb;
    
    $where = str_replace("AND " . $wpdb->posts . ".post_type = 'post'", "AND " . $wpdb->posts . ".post_type IN ('pv_document', 'post')", $where);
    
    return $where;
}

function pvd_get_post_attachments($post_id = null) {
    $args = array(
        'post_type' => 'attachment',
        'numberposts' => -1,
        'post_status' => null
    );

    if ($post_id != null)
        $args['post_parent'] = $post_id;
    else {
	   $args['post_parent'] = 0;
	   $args['orderby'] = 'title';
	   $args['order'] = 'ASC';
    }

    return get_posts($args);
}
