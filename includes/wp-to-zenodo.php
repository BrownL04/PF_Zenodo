<?php

class WP_to_Zenodo {

	public static $ver = '1.0.0';

	public static function init() {
				static $instance;

				if ( ! is_a( $instance, 'WP_to_Zenodo' ) ) {
					$instance = new self();
				}

				return $instance;
		}

	private function __construct() {
		#Stuff
		pf_log('Start up WP_to_Zenodo');
		$this->setup('stage');
		$this->includes();
		add_action('transition_post_status', array($this, 'to_zenodo_on_publish'), 10, 3);
	}

	public function to_zenodo_on_publish( $new_status, $old_status, $post ){
		remove_action( 'transition_post_status', array($this, 'to_zenodo_on_publish') );
		if ( ( 'publish' == $new_status ) && pressforward('controller.metas')->get_post_pf_meta($post->ID, 'pf_zenodo_ready', true)){
			$post_object = get_post($post, ARRAY_A);
			$zenodo_object = new Zenodo_Submit_Object($post_object);
			$response = $this->inital_submit( $zenodo_object );
			//var_dump($response); die();
			if ( false !== $response ){
				$jats_response = $this->xml_submit($response);
			}
		}

		add_action('transition_post_status', array($this, 'to_zenodo_on_publish'), 10, 3);
	}

	public function enforce($type, $value){
		switch ($type) {
			case 'value':
				# code...
				break;

			default:
				# code...
				break;
		}
		//var_dump($type);
		//var_dump($value);
		return $value;
	}

	public function fill($id, $type){
		$filled = '';
		switch ($type) {
			case 'upload_type':
				$filled = 'publication';
				break;

			case 'publication_type':
				$filled = 'other';
				break;

			case 'publication_date':
				$filled = pressforward('controller.metas')->get_post_pf_meta($id, 'item_date', true);
				if (empty($filled)){
					$filled = date('c');
				}
				break;
			case 'title':
				$filled = get_the_title($id);
				break;
			case 'access_right':
				$filled = 'open';
				break;
			case 'license':
				$filled = 'cc-zero';
				break;
			case 'related_identifiers':
				// Always will be set, never will be filled.
				break;
			case 'references':
				$filler = $this->semicolon_split(pressforward('controller.metas')->get_post_pf_meta($id, 'pf_references', true));
				break;
			case 'communities':
				$filler = array(
					array('identifier' => 'arceli')
				);
				break;
			default:
				$filler = 'fill_'.$type;
				if (method_exists($this, $filler)){
					$filled = $this->$filler($id);
				} else {
					pf_log('No fill for '.$type);
					$filled = '';
				}
				break;
		}

		return $this->enforce($type, $filled);
	}

	public function semicolon_split($string){
		return explode(';', $string);
	}

	public function fill_creators($id){
		$filled = pressforward('controller.metas')->get_post_pf_meta($id, 'item_author', true);
		$authors = $this->semicolon_split($filled);
		$affiliation = $this->semicolon_split(pressforward('controller.metas')->get_post_pf_meta($id, 'pf_affiliations'));
		$c = 0;
		if (!empty($authors)){
			$creators = array();
			foreach ($authors as $author){
				if (!empty($affiliation) && !empty($affiliation[$c])){
					$creators[] = array('name' => $author, 'affiliation' => $affiliation[$c]);
				} else {
					$creators[] = array('name' => $author );
				}
				$c++;
			}
		} else {
			$creators = array( 'name' => $filled );
		}
		return $creators;

	}

	public function fill_keywords($id){
		$keywords = pressforward('controller.metas')->get_post_pf_meta($id, 'pf_keywords');
		return $this->semicolon_split($keywords);

	}

	public function fill_description($id){
		$filled = pressforward('controller.metas')->get_post_pf_meta($id, 'revertible_feed_text');
		if (empty($filled)){
			$post = get_post($id);
			$filled = wp_html_excerpt($post->post_content, 160);
		} else {
			$filled = wp_html_excerpt($filled, 160);
		}
		return $filled;
	}

	public function fill_journal_title($id){
		$filled = '';
		$filled = pressforward('controller.metas')->get_post_pf_meta($id, 'source_title');
		if (!empty($filled)){
			$filled .= '; ';
		}
		$filled .= 'Aggregated by '.get_bloginfo('name');
		return $filled;
	}

	private function includes(){
		require_once(dirname(__FILE__).'/PF-Modifications.php');
		// Start me up!
		add_action('pressforward_init', 'pf_modifications', 12, 0 );
	}

	public function setup($env = 'stage'){
		if ( 'stage' == $env ){
			$this->base_zenodo_url = 'https://sandbox.zenodo.org/api/';
			//Define in your WP config
			# @TODO User setting.
			$this->api_key = STAGE_ZENODO_KEY;
		} elseif ( 'prod' == $env ){
			$this->base_zenodo_url = 'https://zenodo.org/api/';
			//Define in your WP config
			# @TODO User setting.
			$this->api_key = STAGE_ZENODO_KEY;
		}
		$this->http_interface = ZS_JSON_Workers::init();
	}

	public function assemble_url($endpoint = 'deposit', $id_array = false){
		switch ($endpoint) {
			case 'deposit':
				return $this->base_zenodo_url.'deposit/depositions/?access_token='.$this->api_key;
				break;
			case 'publish':
				return $this->base_zenodo_url.'deposit/depositions/'.$id_array['id'].'/actions/publish?access_token='.$this->api_key;
				break;
			case 'files':
				return $this->base_zenodo_url.'deposit/depositions/'.$id_array['id'].'/files?access_token='.$this->api_key;
				break;
			default:
				# code...
				break;
		}
	}

	public function inital_submit( Zenodo_Submit_Object $submit_object ){
		$args = array(
			'metadata' => $submit_object

		);
		$url = $this->assemble_url('deposit');
		$post_result = $this->post($url, $args);
		if ( false !== $post_result ){
			add_post_meta($submit_object->ID, 'pf_zenodo_id', $post_result['id'], true);
			return array (
				'zenodo_id'		=>	$post_result['id'],
				'post_id' 		=>  $submit_object->ID
				//'doi'	=>	$post_result['doi'] // this doesn't happen until later, when we publish
			);
		} else {
			return false;
		}

	}

	public function xml_submit($id_array, $post){
		$url = $this->assemble_url('files');

		$file_data = wp_to_jats()->get_the_jats($id_array['post_id']);
	}

	public function image_submit($id_array, $image_atttachment_id){

		$src = wp_get_attachment_url( $image_atttachment_id );
		$file_data = wp_remote_get($src);
	}

	public function html_submit($id_array, $post){
		$url = $this->assemble_url('files');

		$file_data = '';
	}

	public function file_submit($args){
		$url = $this->assemble_url('files');
		//Content-Type: multipart/form-data

		return wp_remote_post($url, $args);
	}

	public function publish($id_array){
		$url = $this->assemble_url('publish');
		$response = $this->post($url, array());
		//Should return 202 to say accepted.
	}

	public function post($url, $args){
		$post = $this->http_interface->post($url, $args);
	}

	public function process_error_code($code){

	}

}

function wp_to_zenodo(){
	return WP_to_Zenodo::init();
}
add_action('pressforward_init', 'wp_to_zenodo', 11, 0);
