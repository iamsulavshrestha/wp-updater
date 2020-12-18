<?php

namespace Sushre\Updater;

/**
 * Plugin Name:       Sushre Plugin Updater
 * Plugin URI:        https://github.com/iamsulavshrestha/wp-updater
 * Description:       Handle the update of self and other plugins.
 * Version:           0.0.3
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Sulav Shrestha
 * Author URI:        https://sulav.name.np
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sushre-updater
 * Domain Path:       /languages
 */

class Updater {
  protected $file;
  protected $plugin;
  protected $basename;
  protected $active;
  private $username;
  private $repository;
  private $authorize_token;
  private $github_reponse;

   public function __construct($file){
    $this->file = $file;
    add_action('admin_init', array($this, 'set_plugin_properties'));
    return $this;
   }

  public function initialize(){
    add_filter('pre_set_site_transient_update_plugins', array($this, 'modify_transient'), 10, 1);
    add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
    add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
  }

  public function setUsername($username){
    $this->username = $username;
  }

  public function setRepository($repository){
    $this->repository = $repository;
  }

  public function authorize($token){
    $this->authorize_token = $token;
  }

  public function set_plugin_properties(){
    $this->plugin = get_plugin_data($this->file);
    $this->basename = plugin_basename($this->file);
    $this->active = is_plugin_active($this->basename);
  }

  private function getRepositoryInfo(){
    if (is_null( $this->github_reponse )){
      $request_uri = sprintf( 'https://api.github.com/repos/%s/%s/releases', $this->username, $this->repository);
      if($this->authorize_token){
        $request_uri = add_query_arg('access_token', $this->authorize_token, $request_uri);
      }

      $response = json_decode(wp_remote_retrieve_body( wp_remote_get( $request_uri )), true);

      if( is_array( $response )){
        $response = current($response);
      } 

      if($this->authorize_token){
        $response['zipball_url'] = add_query_arg('access_token', $this->authorize_token, $response['zipball_url']);
      }

      $this->github_reponse = $response;
    }
  
  }

  public function modify_transient( $transient){
    if(property_exists( $transient, 'checked')){
      if($checked = $transient->checked){
        $this->getRepositoryInfo();
        $out_of_date = version_compare($this->github_reponse['tag_name'], $checked[$this->basename]. 'gt');
        if($out_of_date){
          $new_files = $this->github_reponse['zipball_url'];
          $slug = current(explode('/', $this->basename));
          $plugin = array(
            'url' => $this->plugin["PluginURI"],
            'slug' => $slug,
            'package' => $new_files,
            'new_version' => $this->github_reponse['tag_name']
          );
          $transient->response[ $this->basename] = (object) $plugin;
        }
      }
    }
    
    return $transient;
  
  }

  public function plugin_popup( $result, $action, $args){
    if(!empty($args->slug)){
      if($args->slug == current(explode('/', $this->basename))){
        $this->getRepositoryInfo();

        $plugin = array(
          'name' => $this->plugin["Name"],
          'slug' => $this->basename,
          'version' => $this->github_reponse['tag_name'],
          'author' => $this->plugin["AuthorName"],
          'author_profile' => $this->plugin["PluginURI"],
          'last_updated' => $this->github_reponse['published_at'],
          'homepage' => $this->plugin["PluginURI"],
          'short_description' => $this->plugin["Description"],
          'sections' => array(
            'Description' => $this->plugin["Description"],
            'Updates' => $this->github_reponse['body'],
          ),
          'download_link' => $this->github_reponse['zipball_url']
        );
        return (object) $plugin;
      }
    }
    return $result;
  
  }

  public function after_install($response, $hook_extra, $result){
    global $wp_filesystem;

    $install_directory = plugin_dir_path( $this->file );
    $wp_filesystem->move($result['destination'], $install_directory);

    $result['destination'] = $install_directory;

    if($this->active){
      activate_plugin($this->basename);
    }
    return $result;  
  }

}


$update = new Updater(__FILE__); 
$update->setUsername('iamsulavshrestha');
$update->setRepository('wp-updater');
$update->initialize();
