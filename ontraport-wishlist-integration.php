<?php
/*
Plugin Name: ONTRAPORT to Wishlist Member Integration
Plugin URI: http://www.itmooti.com
Description: Plugin to integrate ONTRAPORT with the Wishlist Member plugin by creating users in Wordpress based on tags being added or removed in ONTRAPORT relating to the Membership Levels.
Version: 1.0
Author: ITMOOTI
Author URI: http://www.itmooti.com
*/


//include 'PapApi.class.php';

class Oap {
	
	
	public static function request($req, $type, $data) {  
		$appid_arr = get_option('wlhelper_oap_id');
		$key_arr=get_option('wlhelper_oap_key');
		$appid=$appid_arr['text_string'];
		$key=$key_arr['text_string'];
		$postargs = "appid=".$appid."&key=".$key."&reqType=".$type."&data=".$data;
		$request = "https://api.moon-ray.com/{$req}.php";
		
		$session = curl_init($request);
		curl_setopt ($session, CURLOPT_POST, true);
		curl_setopt ($session, CURLOPT_POSTFIELDS, $postargs);
		curl_setopt ($session, CURLOPT_HEADER, false);
		curl_setopt ($session, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($session);
		curl_close($session);
		
		$result = simplexml_load_string($response);
		
		return $result;		
	}
	

}

function getwlkey($id) {
	$convert2 = array_keys(Oap::$convert);

	$init = 1376635530;
	foreach($convert2 as $c) {
		if($c == trim($id)) break;
		$init++;
	}

	return $init;
}





function pp_save_password($email, $pass) {
		global $current_user;
		$section_arr = get_option('wlhelper_oap_wlpwdsection');
		$field_arr=get_option('wlhelper_oap_wlpwd');
		$oap_section=$section_arr['text_string'];
		$oap_field=$field_arr['text_string'];
		
		if(!empty($email)) {
			$id =  oap_get_id($email);

			if($id != 0 && !empty($pass)) 
				Oap::request('cdata','update', 
							"<contact id='{$id}'>
								<Group_Tag name='{$oap_section}'>
								<field name='{$oap_field}'>{$pass}</field>
								</Group_Tag>
							</contact>"
						);
		}		
}

function oap_get_id($email) {
		$contact = Oap::request('cdata','search', 
			"<search>
				<equation>
					<field>E-Mail</field>
					<op>e</op>
					<value>{$email}</value>
				</equation>
			</search>"
		);
		
		try {
			$id = (int) $contact->contact->attributes()->id;
		} catch(Exception $e) {
			$id = 0;
		}
		return $id;
}



// Load user session
function pp_load_user_session() {
	
	global $current_user;
	get_currentuserinfo();
	
	$email = $current_user->user_email;
  
	if(!empty($email)) {
		$contact = Oap::request('cdata','search', 
			"<search>
				<equation>
					<field>E-mail</field>
					<op>e</op>
					<value>{$email}</value>
				</equation>
			</search>"
		);
		
		$_SESSION['user_ses'] = $contact->contact;
	}		
	
	return "";
}

add_shortcode( 'user_load', 'pp_load_user_session' );

///////// EVERYTHING BELOW IS FOR OA <-> WL integration ///////////////////////

function oa_wl_calls() {
	$source = $_GET;	

	if(isset($_GET['oa_wl_call'])) {
		$_wlmapi = new WLMAPI();
		$action = $_GET['oa_wl_call'];

		if($action == 'addlevel') {  //email, pass, fname, lname, level
			$userid = email_exists( $source['email'] );
			if(!$userid){
				$password = (!empty($source['pass']) && strlen($source['pass']) >= 8) ? $source['pass'] : wp_generate_password(8, false);

				$userid = (int) $_wlmapi->AddUser( $source['email'], $source['email'], $password, $source['fname'], $source['lname']);
				pp_save_password($source['email'], $password);
			} 
			
			if($userid != 0) {
				$_wlmapi->AddUserLevels( $userid, array($source['level']));
			}	
		}

		if($action == 'remlevel') {  //email, pass, fname, lname, level
			$userid = email_exists( $source['email'] );
			if(!$userid) {
				$password = (!empty($source['pass']) && strlen($source['pass']) >= 8) ? $source['pass'] : wp_generate_password(8, false);

				$userid = (int) $_wlmapi->AddUser( $source['email'], $source['email'], $password, $source['fname'], $source['lname']);
				pp_save_password($source['email'], $password);
			} 
			
			if($userid != 0) {
				$_wlmapi->DeleteUserLevels( $userid, array($source['level']));
			}	
		}

		if($action == 'changepass') {
			$userid = (int) email_exists( $source['email'] );

			if($userid) {
				if(!empty($source['pass']) && strlen($source['pass']) >= 6)
					wp_set_password( $source['pass'], $userid );
				else {
					$correctedpass = wp_generate_password(8, false);
					wp_set_password( $correctedpass, $userid );
					pp_save_password($source['email'], $correctedpass);
				} 

			}
		}

		if($action == 'wlimport') {
                        
 
			$source = $_GET;

			$tags = $_POST['tags'];
			$email = $_GET['email'];

			$levs = array();

			$wlmLevels = WLMAPI::GetLevels();

			foreach($wlmLevels as $k => $mem) {
				if(stripos($tags,'WL > '.$mem['name']) !== false && !(stripos($tags,('WL > '.$mem['name'] . "PAYF")) !== false))
					$levs[] = $k;
			}

			//if(count($levs) > 0) {
				$userid = email_exists( $source['email'] );

				if(!$userid) {
					$password = (!empty($source['pass']) && strlen($source['pass']) >= 8) ? $source['pass'] : wp_generate_password(8, false);

					$userid = (int) $_wlmapi->AddUser( $source['email'], $source['email'], $password, $source['fname'], $source['lname']);
					if($userid > 0) pp_save_password($source['email'], $password);
				} else {
					if(!empty($source['pass']) && strlen($source['pass']) >= 8) {
						wp_set_password( $source['pass'], $userid );
						pp_save_password($source['email'], $source['pass']);
					} else {
						$correctedpass = wp_generate_password(8, false);
						wp_set_password( $correctedpass, $userid );
						pp_save_password($source['email'], $correctedpass);
					} 		
				}

				if($userid > 0) $_wlmapi->AddUserLevels( $userid, $levs);
			//}
		}

		if($action == 'wledit') {
			$source = $_GET;

			$userid = email_exists( $source['email'] );
			if($userid) {
				wp_update_user( array ( 'ID' 			=> $userid, 
										'first_name' 	=> $source['fname'], 
										'last_name' 	=> $source['lname'], 
										'display_name' 	=> $source['fname'],
										'nickname' 		=> $source['fname']
									)) ;
			} 
		}

		if($action == 'wlremove') {
			$source = $_GET;

			$tags = $_POST['tags'];
			$email = $_GET['email'];

			$levs = array();

			$wlmLevels = WLMAPI::GetLevels();

			foreach($wlmLevels as $k => $mem) {
				if(stripos($tags,'WL > '.$mem['name']) == false || stripos($tags,('WL > '.$mem['name'] . "PAYF")) !== false)
					$levs[] = $k;
			}

			if(count($levs) > 0) {
				$userid = email_exists( $source['email'] );
				if($userid > 0) $_wlmapi->DeleteUserLevels( $userid, $levs);
			}
		}

		if($action == 'payfcheck') {
			$source = $_GET;

			$tags = $_POST['tags'];
			$email = $_GET['email'];

			$init = 1376635530;
			$levs = array();
			$wlmLevels = WLMAPI::GetLevels();
			//print_r($wlmLevels);
			foreach($wlmLevels as $k => $mem) {
				if(stripos($tags,('WL > '.$mem['name']." PAYF")) !== false)
					$levs[] = $mem['ID'];
				//$init++;
				//echo $mem['ID'].'<br>';
			}

			if(count($levs) > 0) {
				$userid = email_exists( $source['email'] );
				if($userid > 0) $_wlmapi->DeleteUserLevels( $userid, $levs);
			}
		}

		if($action == 'payfcheck2') {
			$source = $_GET;

			$tags = $_POST['tags'];
			$email = $_GET['email'];

			$levs = array();
			$wlmLevels = WLMAPI::GetLevels();
			foreach($wlmLevels as $k => $mem) {
				if(stripos($tags,'WL > '.$mem['name']) !== false && !(stripos($tags,('WL > '.$mem['name'] . " PAYF")) !== false))
					$levs[] = $mem['ID'];
			}

			if(count($levs) > 0) {
				$userid = email_exists( $source['email'] );
				if($userid > 0) $_wlmapi->AddUserLevels( $userid, $levs);
			}
		}

		exit;
	}
}

add_action('plugins_loaded','oa_wl_calls',10);

add_action('admin_menu', 'plugin_admin_add_page');
function plugin_admin_add_page() {
add_options_page('Custom Plugin Page', 'Wishlist Helper Settings', 'manage_options', 'plugin', 'wlhelper_options_page');
}

function wlhelper_options_page()
{
	?>
	<div>
		<h2>Wishlist Helper Settings</h2>
		<form action="options.php" method="post">
		<?php settings_fields('wlhelper_options'); ?>
		<?php do_settings_sections('wlhelpersettings'); ?>
 		<input name="Submit" type="submit" value="<?php esc_attr_e('Save Changes'); ?>" />
		</form>
	</div>
	<?php
}
add_action('admin_init', 'plugin_admin_init');
function plugin_admin_init()
{
	register_setting( 'wlhelper_options', 'wlhelper_oap_id', 'plugin_options_validate');
	register_setting( 'wlhelper_options', 'wlhelper_oap_key', 'plugin_options_validate1');
	register_setting( 'wlhelper_options', 'wlhelper_oap_wlpwdsection', 'plugin_options_validate2');
	register_setting( 'wlhelper_options', 'wlhelper_oap_wlpwd', 'plugin_options_validate2');
	add_settings_section('wlhelper_main', 'Ontraport Settings', 'plugin_section_text', 'wlhelpersettings');
	add_settings_field('oap_id', 'Ontraport App ID', 'oapid_setting_string', 'wlhelpersettings', 'wlhelper_main');
	add_settings_field('oap_key', 'Ontraport Key', 'oapkey_setting_string', 'wlhelpersettings', 'wlhelper_main');
	add_settings_field('oap_password_section', 'Ontraport Password Field Section', 'oappwdsection_setting_string', 'wlhelpersettings', 'wlhelper_main');
	add_settings_field('oap_password_field', 'Ontraport Password Field', 'oappwd_setting_string', 'wlhelpersettings', 'wlhelper_main');
}

function plugin_section_text()
{
	echo '<p></p>';
}
function oapid_setting_string()
{
	$options = get_option('wlhelper_oap_id');
	echo "<input id='oap_id' name='wlhelper_oap_id[text_string]' size='40' type='text' value='{$options['text_string']}' />";
}
function oapkey_setting_string()
{
	$options = get_option('wlhelper_oap_key');
	echo "<input id='oap_password_field' name='wlhelper_oap_key[text_string]' size='40' type='text' value='{$options['text_string']}' />";
}
function oappwdsection_setting_string()
{
	$options = get_option('wlhelper_oap_wlpwdsection');
	echo "<input id='oap_key' name='wlhelper_oap_wlpwdsection[text_string]' size='40' type='text' value='{$options['text_string']}' />";
}
function oappwd_setting_string()
{
	$options = get_option('wlhelper_oap_wlpwd');
	echo "<input id='oap_key' name='wlhelper_oap_wlpwd[text_string]' size='40' type='text' value='{$options['text_string']}' />";
}
function plugin_options_validate($input) {
	return $input;
}
function plugin_options_validate1($input) {
	return $input;
}
function plugin_options_validate2($input) {
	return $input;
}