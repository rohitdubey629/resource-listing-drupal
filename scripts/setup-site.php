<?php

/**
 * @file
 * Site setup for the Resource listing (LD-448): the listing config
 * (vocabulary, content type, fields, view, term layout), theme, blocks,
 * landing page type with Layout Builder, terms with /listing/* aliases,
 * sample media and Resource nodes, and the Home page (front page, alias
 * /listing) with the view block in its layout.
 *
 * Run on a fresh "standard" install (see README.md):
 *   lando drush php:script scripts/setup-site.php
 *
 * Safe to run again: everything is looked up by name/alias before creating.
 */

use Drupal\block\Entity\Block;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\Entity\ConfigDependencyManager;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\file\Entity\File;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\views\Entity\View;

$etm = \Drupal::entityTypeManager();
$uuid = \Drupal::service('uuid');
$log = static fn(string $msg) => print "- $msg\n";
// Field blocks are derived from bundles + field definitions: clear all three.
$clear_block_cache = static function (): void {
  \Drupal::service('entity_type.bundle.info')->clearCachedBundles();
  \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  \Drupal::service('plugin.manager.block')->clearCachedDefinitions();
};

// 0. Modules + the listing config from config/resource-listing/ (exported
// from the Spectrum site), created in dependency order like a module's
// config/install. Existing items are left as they are.
\Drupal::service('module_installer')->install(['layout_builder', 'media', 'media_library', 'path', 'views_ui']);
$config_manager = \Drupal::service('config.manager');
$data = [];
foreach (glob(dirname(__DIR__) . '/config/resource-listing/*.yml') as $file) {
  $data[basename($file, '.yml')] = Yaml::decode(file_get_contents($file));
}
$created = [];
foreach ((new ConfigDependencyManager())->setData($data)->sortAll() as $name) {
  if (!\Drupal::configFactory()->get($name)->isNew()) {
    continue;
  }
  $entity_type_id = $config_manager->getEntityTypeIdByName($name);
  if ($entity_type_id === 'entity_view_display') {
    // Layout Builder field blocks of bundles created in this run.
    $clear_block_cache();
  }
  $etm->getStorage($entity_type_id)->createFromStorageRecord($data[$name])->save();
  $created[] = $name;
}
$log('Config created: ' . ($created ? implode(', ', $created) : 'none (already there)'));

// 1. Theme: enable and make default; Claro stays the admin theme.
\Drupal::service('theme_installer')->install(['resource_theme']);
\Drupal::configFactory()->getEditable('system.theme')->set('default', 'resource_theme')->save();
$log('Theme resource_theme is the default theme.');

// 2. Blocks for the new theme (replace the ones copied from Olivero).
foreach ($etm->getStorage('block')->loadByProperties(['theme' => 'resource_theme']) as $block) {
  $block->delete();
}
$blocks = [
  ['branding', 'system_branding_block', 'header', ['use_site_logo' => FALSE, 'use_site_name' => TRUE, 'use_site_slogan' => FALSE]],
  ['main_menu', 'system_menu_block:main', 'primary_menu', ['level' => 1, 'depth' => 1, 'expand_all_items' => FALSE]],
  ['messages', 'system_messages_block', 'highlighted', []],
  ['local_tasks', 'local_tasks_block', 'highlighted', ['primary' => TRUE, 'secondary' => TRUE]],
  ['page_title', 'page_title_block', 'content', []],
  ['content', 'system_main_block', 'content', []],
  ['powered', 'system_powered_by_block', 'footer', []],
];
foreach ($blocks as $weight => [$id, $plugin, $region, $settings]) {
  Block::create([
    'id' => "resource_theme_$id",
    'theme' => 'resource_theme',
    'region' => $region,
    'plugin' => $plugin,
    'weight' => $weight,
    'settings' => ['label_display' => '0'] + $settings,
  ])->save();
}
$log('Blocks placed: branding, main menu, messages, tabs, page title, content, footer.');

// 3. "Landing page" content type with Layout Builder (per-page layouts).
if (!NodeType::load('landing_page')) {
  NodeType::create([
    'type' => 'landing_page',
    'name' => 'Landing page',
    'description' => 'Page built with Layout Builder.',
    'display_submitted' => FALSE,
  ])->save();
  // The new type's field blocks (e.g. "links") exist only after this.
  $clear_block_cache();
}
$display = EntityViewDisplay::load('node.landing_page.default') ?: EntityViewDisplay::create([
  'targetEntityType' => 'node',
  'bundle' => 'landing_page',
  'mode' => 'default',
  'status' => TRUE,
]);
$display->enableLayoutBuilder()->setOverridable()->save();
$log('Content type landing_page: Layout Builder on, per-page layouts allowed.');

// 4. Core "Taxonomy term" view off, so term pages render their Layout
// Builder layout (with the listing) instead of the default term listing.
if ($view = View::load('taxonomy_term')) {
  $view->disable()->save();
  $log('View taxonomy_term disabled.');
}

// 5. View tweaks to match the design: "1 – 6 of 6" and a full pager.
$view = View::load('resource_listing');
$default = &$view->getDisplay('default');
$default['display_options']['footer']['result']['content'] = '@start – @end of @total';
$default['display_options']['pager'] = [
  'type' => 'full',
  'options' => ['items_per_page' => 9, 'offset' => 0],
];
$view->save();
$log('View resource_listing: summary text and full pager.');

// 6. Terms with /listing/* aliases.
$terms = [];
foreach (['Sales Enablement', 'CRM', 'Inbound Strategy', 'Website'] as $weight => $name) {
  $found = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'resource_category', 'name' => $name]);
  $term = $found ? reset($found) : Term::create(['vid' => 'resource_category', 'name' => $name, 'weight' => $weight]);
  $term->set('path', ['alias' => '/listing/' . strtolower(str_replace(' ', '-', $name)), 'pathauto' => 0]);
  $term->save();
  $terms[$name] = $term;
}
$log('Terms: ' . implode(', ', array_map(fn($t) => $t->label() . ' (' . $t->toUrl()->toString() . ')', $terms)));

// 7. Sample images (generated, so no downloads are needed) as media.
$make_image = static function (string $name, array $from, array $to): string {
  $w = 1200;
  $h = 640;
  $img = imagecreatetruecolor($w, $h);
  for ($y = 0; $y < $h; $y++) {
    $t = $y / $h;
    $c = imagecolorallocate($img, (int) ($from[0] + ($to[0] - $from[0]) * $t), (int) ($from[1] + ($to[1] - $from[1]) * $t), (int) ($from[2] + ($to[2] - $from[2]) * $t));
    imageline($img, 0, $y, $w, $y, $c);
  }
  // A simple "laptop with a bar chart" shape.
  $white = imagecolorallocatealpha($img, 255, 255, 255, 20);
  $dark = imagecolorallocate($img, 40, 48, 64);
  $bar = imagecolorallocate($img, 31, 79, 191);
  imagefilledrectangle($img, 360, 150, 840, 450, $dark);
  imagefilledrectangle($img, 375, 165, 825, 435, $white);
  imagefilledrectangle($img, 300, 450, 900, 480, $dark);
  foreach ([90, 150, 120, 200, 170, 230] as $i => $bh) {
    imagefilledrectangle($img, 420 + $i * 65, 420 - $bh, 455 + $i * 65, 420, $bar);
  }
  $dir = 'public://resources';
  \Drupal::service('file_system')->prepareDirectory($dir, 1);
  $uri = "$dir/$name.jpg";
  imagejpeg($img, \Drupal::service('file_system')->realpath('public://') . "/resources/$name.jpg", 85);
  return $uri;
};

// 8. Resource nodes, as in the design (created last-to-first, so "Newest
// first" shows them in the design's order).
$resources = [
  ['Your website should be your top salesperson', 'Website', 'Explore how your site can work 24/7 to educate, qualify, and convert your ideal prospects.', [[220, 226, 236], [168, 178, 196]]],
  ['Understanding your pipeline with reports', 'CRM', 'Learn how to build custom reports that reveal gaps and opportunities in your sales pipeline.', [[236, 240, 246], [190, 200, 214]]],
  ['Empower your sales team with automation', 'Sales Enablement', 'See how a B2B company used inbound strategy to turn traffic into qualified leads and sales.', [[244, 238, 228], [214, 196, 170]]],
  ['From leads to customers with Inbound', 'Inbound Strategy', 'See how a B2B company used inbound strategy to turn traffic into qualified leads and sales.', [[240, 232, 220], [200, 172, 140]]],
  ['How smart CRMs boost business growth', 'CRM', 'Learn how a connected CRM can align your teams and unlock revenue opportunities faster.', [[232, 238, 230], [176, 196, 170]]],
  ['Empower your sales team with automation', 'Sales Enablement', 'Discover how automation can help sales reps close more deals by focusing on what matters.', [[236, 236, 236], [180, 170, 156]]],
];
$node_storage = $etm->getStorage('node');
$time = \Drupal::time()->getRequestTime() - 3600;
foreach ($resources as $i => [$title, $category, $summary, $colors]) {
  $existing = $node_storage->loadByProperties(['type' => 'resource', 'title' => $title, 'field_resource_summary' => $summary]);
  if ($existing) {
    continue;
  }
  $uri = $make_image('resource-' . ($i + 1), $colors[0], $colors[1]);
  $file = File::create(['uri' => $uri, 'status' => 1]);
  $file->save();
  $media = Media::create([
    'bundle' => 'image',
    'name' => $title,
    'field_media_image' => ['target_id' => $file->id(), 'alt' => "Illustration: $title"],
    'status' => 1,
  ]);
  $media->save();
  Node::create([
    'type' => 'resource',
    'title' => $title,
    'field_resource_category' => $terms[$category]->id(),
    'field_resource_summary' => $summary,
    'field_resource_image' => $media->id(),
    'created' => $time + $i * 60,
    'status' => 1,
    'uid' => 1,
  ])->save();
}
$log('Resource nodes: ' . count($node_storage->loadByProperties(['type' => 'resource'])));

// 9. Home page at /listing: a Landing page whose layout holds the view
// block, set as the front page. So "/" and "/listing" show the listing
// (no term → all results) and editors get the top bar there (title,
// Published, Edit, ⋮ → Layout).
$path = \Drupal::service('path_alias.manager')->getPathByAlias('/listing');
$home = preg_match('#^/node/(\d+)$#', $path, $m) ? Node::load($m[1]) : NULL;
if (!$home) {
  $home = Node::create([
    'type' => 'landing_page',
    'title' => 'Home',
    'path' => ['alias' => '/listing'],
    'status' => 1,
    'uid' => 1,
  ]);
}
$section = new Section('layout_onecol');
$section->appendComponent(new SectionComponent($uuid->generate(), 'content', [
  'id' => 'views_block:resource_listing-block_1',
  'label' => '',
  'label_display' => '0',
  'provider' => 'views',
  'views_label' => '',
  'items_per_page' => 'none',
]));
$home->set('layout_builder__layout', [$section]);
$home->save();
\Drupal::configFactory()->getEditable('system.site')->set('page.front', '/node/' . $home->id())->save();
$log('Front page: Home (node ' . $home->id() . ', /listing) with the Resource listing block in its layout.');

drupal_flush_all_caches();
$log('Caches cleared. Done.');
