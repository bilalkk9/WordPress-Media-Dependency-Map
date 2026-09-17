<?php
use Bilal\MediaDependencyMap\Adapters\Elementor;
use Bilal\MediaDependencyMap\Matching\Resolver;
use Bilal\MediaDependencyMap\Persistence\Attachment_Repository;

if ( 'api-local.local' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { throw new RuntimeException( 'Restricted to api-local.local.' ); }
if ( ! Elementor::available() ) { throw new RuntimeException( 'Install a supported Elementor version for these fixtures.' ); }
class MDM_Elementor_Fixture_Widget extends \Elementor\Widget_Base {
 public function get_name() { return 'mdm-fixture'; }
 public function get_title() { return 'MDM fixture'; }
 protected function register_controls() {
  $this->start_controls_section( 'content', array( 'label' => 'Fixture' ) );
  $this->add_control( 'image', array( 'type' => 'media' ) );
  $this->add_responsive_control( 'hero', array( 'type' => 'media' ) );
  $this->add_control( 'gallery', array( 'type' => 'gallery' ) );
  $this->add_control( 'link', array( 'type' => 'url' ) );
  $this->add_control( 'icon', array( 'type' => 'icons' ) );
  $this->add_control( 'unrelated_number', array( 'type' => 'number' ) );
  $repeater = new \Elementor\Repeater();
  $repeater->add_control( 'slide', array( 'type' => 'media' ) );
  $this->add_control( 'slides', array( 'type' => 'repeater', 'fields' => $repeater->get_controls() ) );
  $this->add_control( 'dynamic_image', array( 'type' => 'media', 'dynamic' => array( 'active' => true ) ) );
  $this->end_controls_section();
 }
}
$plugin = \Elementor\Plugin::instance();
$plugin->widgets_manager->register( new MDM_Elementor_Fixture_Widget() );
$assert = static function ( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } };
global $wpdb;
$before_user = get_current_user_id();
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( $admins[0] );
$image = wp_insert_attachment( array( 'post_title' => 'MDM Elementor fixture', 'post_mime_type' => 'image/png' ) );
$path = '2099/mdm-elementor-' . wp_generate_uuid4() . '.png';
update_post_meta( $image, '_wp_attached_file', $path );
$url = wp_get_upload_dir()['baseurl'] . '/' . $path;
$post = wp_insert_post( array( 'post_type' => 'elementor_library', 'post_title' => 'MDM Elementor template fixture', 'post_status' => 'draft' ) );
update_post_meta( $post, '_elementor_edit_mode', 'builder' );
update_post_meta( $post, '_elementor_template_type', 'section' );
$media = array( 'id' => $image, 'url' => $url );
$settings = array( 'image' => $media, 'hero_tablet' => $media, 'gallery' => array( $media ), 'link' => array( 'url' => $url ), 'icon' => array( 'library' => 'svg', 'value' => $media ), 'unrelated_number' => $image, 'slides' => array( array( '_id' => 'row1', 'slide' => $media ) ), '__dynamic__' => array( 'dynamic_image' => '[elementor-tag id="fixture"]' ) );
$data = array( array( 'id' => 'container1', 'elType' => 'container', 'settings' => array(), 'elements' => array( array( 'id' => 'widget1', 'elType' => 'widget', 'widgetType' => 'mdm-fixture', 'settings' => $settings, 'elements' => array() ) ) ) );
$scanner = new Elementor( new Resolver( new Attachment_Repository( $wpdb ) ) );
try {
 update_post_meta( $post, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
 $before = get_post_meta( $post, '_elementor_data', true );
 $refs = array_map( static function( $ref ) { return $ref->to_array(); }, $scanner->scan( $post ) );
 $assert( 12 === count( $refs ), 'Expected eleven stored ID/URL references and one unresolved dynamic reference; got ' . count( $refs ) . ': ' . implode( ', ', array_column( $refs, 'data_path' ) ) );
 $paths = implode( '\n', array_column( $refs, 'data_path' ) );
 foreach ( array( 'widget1:mdm-fixture', 'hero_tablet/id', 'slides/0/slide/id', 'icon/value/id', 'dynamic_image/dynamic' ) as $fragment ) { $assert( false !== strpos( $paths, $fragment ), 'Missing path: ' . $fragment ); }
 $assert( false === strpos( $paths, 'unrelated_number' ), 'Numeric non-media setting was indexed.' );
 $assert( 1 === count( array_filter( $refs, static function( $ref ) { return 'unresolved' === $ref['confidence']; } ) ), 'Dynamic control was not unresolved.' );
 $assert( $before === get_post_meta( $post, '_elementor_data', true ), 'Scanner mutated document data.' );
 $assert( 12 === count( $scanner->scan( $post ) ), 'Repeated scans accumulated references.' );
 update_post_meta( $post, '_elementor_data', '{invalid' );
 $failed = false; try { $scanner->scan( $post ); } catch ( RuntimeException $e ) { $failed = true; }
 $assert( $failed, 'Malformed JSON silently returned an empty successful scan.' );
 update_post_meta( $post, '_elementor_data', str_repeat( ' ', 2097153 ) );
 $failed = false; try { $scanner->scan( $post ); } catch ( RuntimeException $e ) { $failed = true; }
 $assert( $failed, 'Oversized input did not fail closed.' );
 $data[0]['elements'][0]['widgetType'] = 'missing-mdm-widget';
 update_post_meta( $post, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
 $plugin->documents->get( $post, false );
 $failed = false; try { $scanner->scan( $post ); } catch ( RuntimeException $e ) { $failed = true; }
 $assert( $failed, 'Missing widget schema silently passed.' );
 WP_CLI::success( 'Elementor: nested templates, registered media/gallery/repeater/URL/SVG/responsive controls, dynamic values, numeric exclusion, repeat scans and failure safeguards passed.' );
} finally {
 wp_delete_post( $post, true ); wp_delete_attachment( $image, true );
 $plugin->widgets_manager->unregister( 'mdm-fixture' );
 wp_set_current_user( $before_user );
}
