<?php

/**
 * @file
 * Content type that displays facet information for search
 */

$plugin = array(
  'title' => t('Search Facets'),
  'description' => t('The list of options available for a particular type of search facet'),
  'required context' => new ctools_context_required(t('Keywords'), 'string'),
  'category' => t('Facet API'),
  'content_types' => 'facet',
  'render last' => TRUE,
  'render callback' => 'panopoly_search_facet_content_type_render',
  'edit form' => 'panopoly_search_facet_content_type_edit_form',
);

function panopoly_search_facet_content_type_edit_form($form, &$form_state) {

  // Determine what deltas Facet API Supports
  $map = facetapi_get_delta_map();
  $deltas = array();
  $realm_name = 'block';
  foreach (facetapi_get_searcher_info() as $searcher => $info) {
    foreach (facetapi_get_delta_map_queue($searcher, $realm_name) as $facet_name) {
      if ($facet = facetapi_facet_load($facet_name, $searcher)) {
        if ($string = facetapi_build_delta($searcher, $realm_name, $facet_name)) {
          if ($delta = array_search($string, $map)) {
            switch($searcher) {
              case 'search_api@database_node_index':
                $deltas[$delta] = 'DB: ' . $facet_name;
                break;
              case 'search_api@node_index':
                $deltas[$delta] = 'Solr: ' . $facet_name;
                break;
              default:
                $deltas[$delta] = 'Unknown: ' . $facet_name;
                break;
            }
          }
        }
      }
    }
  }

  // Build the configuration form
  $conf = $form_state['conf'];
  $form['delta'] = array(
    '#type' => 'select',
    '#title' => t('Facet Type'),
    '#default_value' => !empty($conf['delta']) ? $conf['delta'] : '',
    '#options' => $deltas,
  );
  return $form;
}

function panopoly_search_facet_content_type_edit_form_submit($form, &$form_state) {
  $form_state['conf']['delta'] = $form_state['values']['delta'];
}

function panopoly_search_facet_content_type_render($subtype, $conf, $args, $context) {

  // Assemble some important information
  $builds = &drupal_static(__FUNCTION__, array());
  $parsed = &drupal_static('facetapi_parsed_deltas', array());
  $delta = $conf['delta'];
  facetapi_check_block_visibility($delta); /* this is needed to call to build the parsed information */
  list($searcher, $realm_name, $facet_name) = $parsed[$delta];

  // Builds and caches the entire realm per searcher / realm combination.
  $group = $searcher . ':' . $realm_name;
  if (!isset($builds[$group])) {
    $builds[$group] = facetapi_build_realm($searcher, $realm_name);
  }

  // Returns the individual pane
  if (isset($builds[$group][$facet_name])) {
    $variables = array('title' => $builds[$group][$facet_name]['#title'], 'facet' => $builds[$group][$facet_name]);
    $pane = new stdClass();
    $pane->title = theme('facetapi_title', $variables);
    $pane->content = $builds[$group][$facet_name];
    return $pane;
  }
}
