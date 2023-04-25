<?php

/**
 * COmanage Registry CO Keycloak Provisioner Targets Controller
 *
 * Portions licensed to the University Corporation for Advanced Internet
 * Development, Inc. ("UCAID") under one or more contributor license agreements.
 * See the NOTICE file distributed with this work for additional information
 * regarding copyright ownership.
 *
 * UCAID licenses this file to you under the Apache License, Version 2.0
 * (the "License"); you may not use this file except in compliance with the
 * License. You may obtain a copy of the License at:
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * 
 * @link          http://www.internet2.edu/comanage COmanage Project
 * @package       registry
 * @since         COmanage Registry v3.1.x
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

App::uses("SPTController", "Controller");

class CoKeycloakProvisionerTargetsController extends SPTController
{
  // Class name, used by Cake
  public $name = "CoKeycloakProvisionerTargets";

  public $uses = array(
    'KeycloakProvisioner.CoKeycloakProvisionerTarget',
    'CoGroup',
  );

  /**
   * By default a new CSRF token is generated for each request, and each token can only be used once.
   * If a token is used twice, the request will be blackholed. Sometimes, this behaviour is not desirable,
   * as it can create issues with single page applications.
   */
  public $components = array(
    'RequestHandler',
    'Security' => array(
      'csrfUseOnce' => false,
      'csrfExpires' => '+10 minutes'
    )
  );

  /**
   * beforeFilter
   *
   * @return void
   */
  public function beforeFilter()
  {
    parent::beforeFilter();
    $this->Security->validatePost = false;
    $this->Security->enabled = true;
    $this->Security->csrfCheck = true;
    $this->Security->blackHoleCallback = 'reloadConfig';
  }

  /**
   * Ignore blackHoleCallback for Token expiration
   */
  public function reloadConfig($type)
  {
    // Handle all other requests
    $location = '/';
    if (!empty($this->request->params["pass"][0])) {
      $location = array(
        'plugin' => Inflector::underscore($this->plugin),
        'controller' => Inflector::pluralize(Inflector::underscore($this->modelClass)),
        'action' => 'edit',
        $this->request->params["pass"][0]
      );
      $this->log(__METHOD__ . 'location => ' . print_r($location, true), LOG_DEBUG);
    }
    $this->Flash->set(_txt('er.voms_provisioner.token.blackhauled'), array('key' => 'error'));
    return $this->redirect($location);
  }


  /**
   * beforeRender
   *
   * @return void
   */
  public function beforeRender()
  {
    $this->log(__METHOD__ . "::@", LOG_DEBUG);
    parent::beforeRender(); // TODO: Change the autogenerated stub
    $this->set('vv_identifier_type_list', KeycloakProvisionerIdentifierEnum::type);
    // Return the olist of persistent values
    $this->set('vv_persistent_list', array(true => 'true', false => 'false'));

    if (
      $this->request->is('get')
      && $this->action === 'edit' && !empty($this->request->data["CoKeycloakProvisionerTarget"]["api_client_secret"])
    ) {
      Configure::write('Security.useOpenSsl', true);
      $this->set('vv_api_client_secret', Security::decrypt(base64_decode($this->request->data["CoKeycloakProvisionerTarget"]["api_client_secret"]), Configure::read('Security.salt')));

      if (empty($this->request->data["CoProvisioningTarget"]["provision_co_group_id"])) {
        $this->set('vv_cou_name', '');
      } else {
        // Get and set the COU/VO name
        $args = array();
        $args['conditions']['CoGroup.id'] = $this->request->data["CoProvisioningTarget"]["provision_co_group_id"];
        $args['contain'][] = 'Cou';
        $keycloak_related_models = $this->CoGroup->find('first', $args);
        if (!empty($keycloak_related_models["Cou"])) {
          $this->set('vv_cou_name', $keycloak_related_models["Cou"]["name"]);
          $this->set('vv_cou_id', $keycloak_related_models["Cou"]["id"]);
        }
      }
    }
  }

  /**
   * @param null $data
   * @return int|mixed|null
   */
  public function parseCOID($data = null)
  {
    $this->log(__METHOD__ . "::@cc", LOG_DEBUG);
    if (!$this->requires_co) {
      // Controllers that don't require a CO generally can't imply one.
      return null;
    }
    $coid = null;
    if (!empty($this->request->params["named"]["co"])) {
      return $this->request->params["named"]["co"];
    }
    // Get the co_id form the parent table(co_provisioning_targets
    $args = array();
    $args['conditions']['CoKeycloakProvisionerTarget.id'] = $this->request->params["pass"][0];
    $args['contain'] = array('CoProvisioningTarget');
    $keycloak_provisioner_target = $this->CoKeycloakProvisionerTarget->find('first', $args);
    if (!empty($keycloak_provisioner_target["CoProvisioningTarget"]["co_id"])) {
      return $keycloak_provisioner_target["CoProvisioningTarget"]["co_id"];
    }

    return null;
  }

  /**
   * @return bool
   */
  public function verifyRequestedId()
  {
    $this->log(__METHOD__ . "::@vvv", LOG_DEBUG);
    if (
      !empty($this->cur_co)
      && !empty($this->request->params["pass"][0])
    ) {
      $args = array();
      $args['conditions']['CoKeycloakProvisionerTarget.id'] = $this->request->params["pass"][0];
      $args['contain'] = false;
      $keycloak_provisioner_target = $this->CoKeycloakProvisionerTarget->find('first', $args);
      if (!empty($keycloak_provisioner_target["CoKeycloakProvisionerTarget"])) {
        return true;
      }
    }
    return false;
  }

  /**
   * Authorization for this Controller, called by Auth component
   * - precondition: Session.Auth holds data used for authz decisions
   * - postcondition: $permissions set with calculated permissions
   *
   * @since  COmanage Registry v3.1.x
   * @return Array Permissions
   */

  function isAuthorized()
  {
    $roles = $this->Role->calculateCMRoles();

    // Construct the permission set for this user, which will also be passed to the view.
    $p = array();

    // Determine what operations this user can perform

    // Delete an existing CO Provisioning Target?
    $p['delete'] = ($roles['cmadmin'] || $roles['coadmin']);

    // Edit an existing CO Provisioning Target?
    $p['edit'] = ($roles['cmadmin'] || $roles['coadmin']);

    // View all existing CO Provisioning Targets?
    $p['index'] = ($roles['cmadmin'] || $roles['coadmin']);

    // View an existing CO Provisioning Target?
    $p['view'] = ($roles['cmadmin'] || $roles['coadmin']);

    $p['testconnection'] = true;
    $this->set('permissions', $p);
    return ($p[$this->action]);
  }
}