<?php
/**
 * @file
 *
 * Contains the controller class for the Fieldable Panel Pane entity.
 */

/**
 * Entity controller class.
 */
class PanelsPaneController extends DrupalDefaultEntityController {
  public $entity;

  public function attachLoad(&$queried_entities, $revision_id = FALSE) {
    parent::attachLoad($queried_entities, $revision_id);

    // We need to go through and unserialize our serialized fields.
    foreach ($queried_entities as $entity) {
      foreach (array('view_access', 'edit_access') as $key) {
        if (is_string($entity->$key)) {
          $entity->$key = unserialize($entity->$key);
        }
      }
    }
  }

  public function buildQuery($ids, $conditions = array(), $revision_id = FALSE) {
    // Add an alias to this query to ensure that we can tell if this is
    // the current revision or not.
    $query = parent::buildQuery($ids, $conditions, $revision_id);
    $query->addField('base', 'vid', 'current_vid');

    return $query;
  }

  public function access($op, $entity = NULL, $account = NULL) {
    if ($op !== 'create' && !$entity) {
      return FALSE;
    }

    // The administer permission is a blanket override.
    if (user_access('administer fieldable panels panes')) {
      return TRUE;
    }

    switch ($op) {
      case 'create':
        return user_access('create fieldable ' . $entity);

      case 'view':
        ctools_include('context');
        return ctools_access($entity->view_access, fieldable_panels_panes_get_base_context($entity));

      case 'update':
        ctools_include('context');
        return user_access('edit fieldable ' . $entity->bundle) && ctools_access($entity->edit_access, fieldable_panels_panes_get_base_context($entity));

      case 'delete':
        ctools_include('context');
        return user_access('delete fieldable ' . $entity->bundle) && ctools_access($entity->edit_access, fieldable_panels_panes_get_base_context($entity));

    }

    return FALSE;
  }

  public function save($entity) {
    $entity = (object) $entity;
     // Determine if we will be inserting a new entity.
    $entity->is_new = !(isset($entity->fpid) && is_numeric($entity->fpid));

    $transaction = db_transaction();

    // Set the timestamp fields.
    if (empty($entity->created)) {
      $entity->created = REQUEST_TIME;
    }

    // Only change revision timestamp if it doesn't exist.
    if (empty($entity->timestamp)) {
      $entity->timestamp = REQUEST_TIME;
    }

    $entity->changed = REQUEST_TIME;

    field_attach_presave('fieldable_panels_pane', $entity);
    module_invoke_all('entity_presave', $entity, 'fieldable_panels_pane');

    // When saving a new entity revision, unset any existing $entity->vid
    // to ensure a new revision will actually be created and store the old
    // revision ID in a separate property for entity hook implementations.
    if (!$entity->is_new && !empty($entity->revision) && $entity->vid) {
      $entity->old_vid = $entity->vid;
      unset($entity->vid);
      $entity->timestamp = REQUEST_TIME;
    }

    module_invoke_all('entity_presave', $entity, 'fieldable_panels_pane');

    try {
      if (!$entity->is_new) {
        // Since we already have an fpid, write the revision to ensure the
        // vid is the most up to date, then write the record.
        $this->saveRevision($entity);
        drupal_write_record('fieldable_panels_panes', $entity, 'fpid');

        field_attach_update('fieldable_panels_pane', $entity);
        module_invoke_all('entity_update', $entity, 'fieldable_panels_pane');

      }
      else {
        // If this is new, write the record first so we have an fpid,
        // then save the revision so that we have a vid. This means we
        // then have to write the vid again.
        drupal_write_record('fieldable_panels_panes', $entity);
        $this->saveRevision($entity);
        db_update('fieldable_panels_panes')
          ->fields(array('vid' => $entity->vid))
          ->condition('fpid', $entity->fpid)
          ->execute();

        field_attach_insert('fieldable_panels_pane', $entity);
        module_invoke_all('entity_insert', $entity, 'fieldable_panels_pane');
      }

      return $entity;
    }
    catch (Exception $e) {
      $transaction->rollback('fieldable_panels_panes');
      watchdog_exception('fieldable_panels_panes', $e);
    }

    return FALSE;
  }

  /**
   * Saves an entity revision with the uid of the current user.
   *
   * @param $entity
   *   The fully loaded entity object.
   * @param $uid
   *   The user's uid for the current revision.
   * @param $update
   *   TRUE or FALSE indicating whether or not the existing revision should be
   *     updated instead of a new one created.
   */
  function saveRevision($entity, $uid = NULL) {
    if (!isset($uid)) {
      $uid = $GLOBALS['user']->uid;
    }

    $entity->uid = $uid;
    // Update the existing revision if specified.
    if (!empty($entity->vid)) {
      drupal_write_record('fieldable_panels_panes_revision', $entity, 'vid');
    }
    else {
      // Otherwise insert a new revision. This will automatically update $entity
      // to include the vid.
      drupal_write_record('fieldable_panels_panes_revision', $entity);
    }
  }

  public function view($entity, $view_mode = 'full', $langcode = NULL) {
    // attach our fields and prepare the pane for rendering
    field_attach_prepare_view('fieldable_panels_pane', array($entity->fpid => $entity), $view_mode, $langcode);
    entity_prepare_view('fieldable_panels_pane', array($entity->fpid => $entity), $langcode);
    $entity->content = field_attach_view('fieldable_panels_pane', $entity, $view_mode, $langcode);
    $entity->content += array(
      '#fieldable_panels_pane' => $entity,
      '#theme' => 'fieldable_panels_pane',
      '#element' => $entity,
      '#view_mode' => $view_mode,
      '#language' => $langcode,
    );

    $entity_type = 'fieldable_panels_pane';
    drupal_alter(array('fieldable_panels_pane_view', 'entity_view'), $entity->content, $entity_type);

    return $entity->content;
  }

  public function delete($fpids) {
    $transaction = db_transaction();
    if (!empty($fpids)) {
      $entities = fieldable_panels_panes_load_multiple($fpids, array());

      try {
        foreach ($entities as $fpid => $entity) {
          // Call the entity-specific callback (if any):
          module_invoke_all('entity_delete', $entity, 'fieldable_panels_pane');
          field_attach_delete('fieldable_panels_pane', $entity);
        }

        // Delete after calling hooks so that they can query entity tables as needed.
        db_delete('fieldable_panels_panes')
          ->condition('fpid', $fpids, 'IN')
          ->execute();

        db_delete('fieldable_panels_panes_revision')
          ->condition('fpid', $fpids, 'IN')
          ->execute();
      }
      catch (Exception $e) {
        $transaction->rollback();
        watchdog_exception('fieldable_panels_pane', $e);
        throw $e;
      }

      // Clear the page and block and entity_load_multiple caches.
      entity_get_controller('fieldable_panels_pane')->resetCache();
    }
  }

  public function create($values) {
    $entity = (object) array(
      'bundle' => $values['bundle'],
      'language' => LANGUAGE_NONE,
      'is_new' => TRUE,
    );

    // Ensure basic fields are defined.
    $values += array(
      'bundle' => 'fieldable_panels_pane',
      'title' => '',
      'link' => '',
      'path' => '',
      'reusable' => FALSE,
      'admin_title' => '',
      'admin_description' => '',
      'category' => '',
      'vid' => '',
      'current_vid' => '',
    );

    // Apply the given values.
    foreach ($values as $key => $value) {
      $entity->$key = $value;
    }

    return $entity;
  }

}
