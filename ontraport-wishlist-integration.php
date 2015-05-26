<?php
/*
Plugin Name: ONTRAPORT to Wishlist Member Integration
Plugin URI: http://www.itmooti.com
Description: Plugin to integrate ONTRAPORT with the Wishlist Member plugin by creating users in Wordpress based on tags being added or removed in ONTRAPORT relating to the Membership Levels.
Version: 1.7
Author: ITMOOTI
Author URI: http://www.itmooti.com
*/

class ontraportWishlistHelper {
	
	private $url;
	private $plugin_links;
	private $License=array(
		"authenticated" => false,
		"message" => ""
	);
	public function __construct(){
		$isSecure = false;
		if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
			$isSecure = true;
		}
		elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' || !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on') {
			$isSecure = true;
		}
		$this->url=($isSecure ? 'http' : 'http')."://app.itmooti.com/wp-plugins/oap-utm/api.php";
		$request= "plugin_links";
		$postargs = "plugin=ontraport-wishlist-helper&request=".urlencode($request);
		$session = curl_init($this->url);
		curl_setopt ($session, CURLOPT_POST, true);
		curl_setopt ($session, CURLOPT_POSTFIELDS, $postargs);
		curl_setopt($session, CURLOPT_HEADER, false);
		curl_setopt($session, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($session, CURLOPT_CONNECTTIMEOUT ,3); 
		curl_setopt($session, CURLOPT_TIMEOUT, 3);
		$response = json_decode(curl_exec($session));
		curl_close($session);
		if(isset($response->status) && $response->status=="success"){
			$this->plugin_links=$response->message;
		}
		else{
			$this->plugin_links=(object)array("support_link"=>"", "license_link"=>"");
		}
		add_action( 'admin_notices', array( $this, 'show_license_info' ) );
		add_action('admin_menu', array($this, 'plugin_admin_add_page'));
		add_action('admin_init', array($this, 'plugin_admin_init'));
		if(isset($_POST["ontraportWishlistHelper_license_key"]))
			add_option("ontraportWishlistHelper_license_key", $_POST["ontraportWishlistHelper_license_key"]) or update_option("ontraportWishlistHelper_license_key", $_POST["ontraportWishlistHelper_license_key"]);
		$license_key=get_option('ontraportWishlistHelper_license_key', "");
		if(!empty($license_key)){
			$request= "verify";
			$postargs = "plugin=ontraport-wishlist-helper&domain=".urlencode($_SERVER['HTTP_HOST'])."&license_key=".urlencode($license_key)."&request=".urlencode($request);
			$session = curl_init($this->url);
			curl_setopt ($session, CURLOPT_POST, true);
			curl_setopt ($session, CURLOPT_POSTFIELDS, $postargs);
			curl_setopt($session, CURLOPT_HEADER, false);
			curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($session, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($session, CURLOPT_CONNECTTIMEOUT ,3); 
			curl_setopt($session, CURLOPT_TIMEOUT, 3);
			$response = json_decode(curl_exec($session));
			curl_close($session);
			if(isset($response->status) && $response->status=="success"){
				if(isset($response->message))
					add_option("ontraport-wishlist-helper_message", $response->message) or update_option("ontraport-wishlist-helper_message", $response->message);
				$this->License["authenticated"]=true;
				//Modified by IT Mooti - 14Mar15, WishlistMember class will not exist until plugins are loaded
				add_action('plugins_loaded', array($this,'init_wl_functions'),10);
				/*
				if( class_exists('WishListMember')){
					add_shortcode( 'user_load', array($this, 'pp_load_user_session') );
					add_action('plugins_loaded', array($this, 'oa_wl_calls',10));
				}
				*/
			}
			else{
				$this->License["authenticated"]=false;
				if(isset($response->message))
					$this->License["message"]=$response->message;
				else
					$this->License["message"]="Error in license key verification. Try again later.";
			}
		}
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array($this, 'itmooti_plugin_action_link'));
		add_filter( 'plugin_row_meta', array($this, 'itmooti_plugin_meta_link'), 10, 2);
	}
	function init_wl_functions()
	{
		
		if(class_exists('WishListMember'))
		{
			$this->pp_load_user_session();
			$this->oa_wl_calls();
			//$this->create_wl_login_date_field('WL_HELPER');
			add_action('wp_login', array($this,'wl_on_login'),5);
		}
	}
	function wl_on_login($login)
	{
		$user=get_user_by('login',$login);
		$email=$user->user_email;
		$section_arr = get_option('wlhelper_oap_wlpwdsection');
		$oap_section=$section_arr['text_string'];
		$this->create_wl_login_date_field($oap_section);
		
		$opid =  $this->oap_get_id($email);
		/*
		echo $opid;
		exit();
		*/
		$this->update_last_login_date($opid,$oap_section);
		$this->add_login_tag($opid);
		
	}
	function add_login_tag($opid)
	{
		$domain_name =  preg_replace('/^www\./','',$_SERVER['SERVER_NAME']);
		$this->request('cdata','add_tag', 
			"<contact id='{$opid}'>
				<tag>WL > ".$domain_name." - Active</tag>
			</contact>"
		);
		
	}
	function update_last_login_date($opid,$oap_section)
	{
		$this->request('cdata','update', 
			"<contact id='{$opid}'>
				<Group_Tag name='{$oap_section}'>
				<field name='WL Last Login Date'>".time()."</field>
				</Group_Tag>
			</contact>"
		);
		
	}
	function create_wl_login_date_field($oap_section)
	{
		//$status = wp_mail("spectrainfo@gmail.com","Creating Date Field:","Date passed:".$mstr);
		$mresult=$this->request('cdata','edit_section', 
			'<data>
			<Group_Tag name="'.$oap_section.'">
			<field name="WL Last Login Date" type="fulldate"/>
			</Group_Tag>
			</data>'
		);
		
	}
	function itmooti_plugin_action_link( $links ) {
		return array_merge(
			array(
				'settings' => '<a href="options-general.php?page=ontraport-wishlist-helper">Settings</a>',
				'support_link' => '<a href="'.$this->plugin_links->support_link.'" target="_blank">Support</a>'
			),
			$links
		);
	}
	function itmooti_plugin_meta_link( $links, $file ) {
		$plugin = plugin_basename(__FILE__);
		if ( $file == $plugin ) {
			return array_merge(
				$links,
				array(
					'settings' => '<a href="options-general.php?page=ontraport-wishlist-helper">Settings</a>',
					'support_link' => '<a href="'.$this->plugin_links->support_link.'" target="_blank">Support</a>'
				)
			);
		}
		return $links;
	}
	
	public function show_license_info(){
		$license_key=get_option('ontraportWishlistHelper_license_key', "");
		if(empty($license_key)){
			echo '<div class="updated">
        		<p><strong>ONTRAPORT to Wishlist Member Integration:</strong> How do I get License Key?<br />Please visit this URL <a href="'.$this->plugin_links->license_link.'" target="_blank">'.$this->plugin_links->license_link.'</a> to get a License Key .</p>
	    	</div>';
		}
		$message=get_option("ontraport-wishlist-helper_message", "");
		if($message!=""){
			echo '<div class="error">
        		<p><strong>ONTRAPORT to Wishlist Member Integration:</strong> '.$message.'</p>
	    	</div>';
		}
	}
	
	public function request($req, $type, $data) {  
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
	
	public function getwlkey($id) {
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
			$id =  $this->oap_get_id($email);
			if($id != 0 && !empty($pass))
				$this->request('cdata','update', 
					"<contact id='{$id}'>
						<Group_Tag name='{$oap_section}'>
						<field name='{$oap_field}'>{$pass}</field>
						</Group_Tag>
					</contact>"
				);
		}		
	}
	
	function oap_get_id($email) {
		$contact = $this->request('cdata','search', 
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
			$contact = $this->request('cdata','search', 
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
					$this->pp_save_password($source['email'], $password);
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
					$this->pp_save_password($source['email'], $password);
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
						$this->pp_save_password($source['email'], $correctedpass);
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
					if($userid > 0) $this->pp_save_password($source['email'], $password);
				} else {
					if(!empty($source['pass']) && strlen($source['pass']) >= 8) {
						wp_set_password( $source['pass'], $userid );
						$this->pp_save_password($source['email'], $source['pass']);
					} else {
						$correctedpass = wp_generate_password(8, false);
						wp_set_password( $correctedpass, $userid );
						$this->pp_save_password($source['email'], $correctedpass);
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
	
			//exit;
		}
	}
		
	function plugin_admin_add_page() {
		add_options_page('Wishlist Helper Settings', 'Wishlist Helper Settings', 'manage_options', 'ontraport-wishlist-helper', array($this, 'wlhelper_options_page'));
	}
	
	function wlhelper_options_page(){
		?>
		<div>
			<h2>Wishlist Helper Settings</h2>
			<form method="post" action="options.php">
				<h3>Plugin Credentials</h3>
                Provide Plugin Credentials below:
                <?php $license_key=get_option('ontraportWishlistHelper_license_key', "");?>
                <table class="form-table">
                	<tr>
                    	<th scope="row">License Key</th>
                        <td><input type="text" name="ontraportWishlistHelper_license_key" id="ontraportWishlistHelper_license_key" value="<?php echo $license_key?>" /></td>
                   	</tr>
              	</table>
				<?php
				echo $this->License["message"];
				settings_fields('wlhelper_options');
				if($this->License["authenticated"]){
					do_settings_sections('wlhelpersettings');
				}
				if(!empty($license_key)){
					
				}
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
	
	function plugin_admin_init(){
		register_setting( 'wlhelper_options', 'wlhelper_oap_id', array($this, 'plugin_options_validate'));
		register_setting( 'wlhelper_options', 'wlhelper_oap_key', array($this, 'plugin_options_validate1'));
		register_setting( 'wlhelper_options', 'wlhelper_oap_wlpwdsection', array($this, 'plugin_options_validate2'));
		register_setting( 'wlhelper_options', 'wlhelper_oap_wlpwd', array($this, 'plugin_options_validate2'));
		add_settings_section('wlhelper_main', 'Ontraport Settings', array($this, 'plugin_section_text'), 'wlhelpersettings');
		add_settings_field('oap_id', 'Ontraport App ID', array($this, 'oapid_setting_string'), 'wlhelpersettings', 'wlhelper_main');
		add_settings_field('oap_key', 'Ontraport Key', array($this, 'oapkey_setting_string'), 'wlhelpersettings', 'wlhelper_main');
		add_settings_field('oap_password_section', 'Ontraport Password Field Section', array($this, 'oappwdsection_setting_string'), 'wlhelpersettings', 'wlhelper_main');
		add_settings_field('oap_password_field', 'Ontraport Password Field', array($this, 'oappwd_setting_string'), 'wlhelpersettings', 'wlhelper_main');
	}
	
	function plugin_section_text(){
		echo '<p></p>';
	}
	
	function oapid_setting_string(){
		$options = get_option('wlhelper_oap_id');
		if(isset($options["text_string"]))
			$value=$options["text_string"];
		else
			$value="";
		echo "<input id='oap_id' name='wlhelper_oap_id[text_string]' size='40' type='text' value='{$value}' />";
	}
	
	function oapkey_setting_string(){
		$options = get_option('wlhelper_oap_key');
		if(isset($options["text_string"]))
			$value=$options["text_string"];
		else
			$value="";
		echo "<input id='oap_password_field' name='wlhelper_oap_key[text_string]' size='40' type='text' value='{$value}' />";
	}
	
	function oappwdsection_setting_string(){
		$options = get_option('wlhelper_oap_wlpwdsection');
		if(isset($options["text_string"]))
			$value=$options["text_string"];
		else
			$value="";
		echo "<input id='oap_key' name='wlhelper_oap_wlpwdsection[text_string]' size='40' type='text' value='{$value}' />";
	}
	
	function oappwd_setting_string(){
		$options = get_option('wlhelper_oap_wlpwd');
		if(isset($options["text_string"]))
			$value=$options["text_string"];
		else
			$value="";
		echo "<input id='oap_key' name='wlhelper_oap_wlpwd[text_string]' size='40' type='text' value='{$value}' />";
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
}
$ontraportWishlistHelper=new ontraportWishlistHelper();