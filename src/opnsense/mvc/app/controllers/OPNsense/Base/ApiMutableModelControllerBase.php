<?php

/*
 * Copyright (C) 2016 IT-assistans Sverige AB
 * Copyright (C) 2016 Deciso B.V.
 * Copyright (C) 2018 Fabian Franz
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace OPNsense\Base;

use OPNsense\Core\ACL;
use OPNsense\Core\Config;

/**
 * Class ApiMutableModelControllerBase, inherit this class to implement
 * an API that exposes a model with get and set actions.
 * You need to implement a method to create new blank model
 * objecs (newModelObject) as well as a method to return
 * the name of the model.
 * @package OPNsense\Base
 */
abstract class ApiMutableModelControllerBase extends ApiControllerBase
{
    /**
     * @var string this implementations internal model name to use (in set/get output)
     */
    protected static $internalModelName = null;

    /**
     * @var string model class name to use
     */
    protected static $internalModelClass = null;

    /**
     * @var bool use safe delete, search for references before allowing deletion
     */
    protected static $internalModelUseSafeDelete = false;

    /**
     * @var null|BaseModel model object to work on
     */
    private $modelHandle = null;

    /**
     * Validate on initialization
     * @throws \Exception when not bound to a model class or a set/get reference is missing
     */
    public function initialize()
    {
        parent::initialize();
        if (empty(static::$internalModelClass)) {
            throw new \Exception('cannot instantiate without internalModelClass defined.');
        }
        if (empty(static::$internalModelName)) {
            throw new \Exception('cannot instantiate without internalModelName defined.');
        }
    }

    /**
     * Check if item can be safely deleted if $internalModelUseSafeDelete is enabled.
     * Throws a user exception when the $uuid seems to be used in some other config section.
     * @param $uuid string uuid to check
     * @throws UserException containing additional information
     */
    private function checkAndThrowSafeDelete($uuid)
    {
        if (static::$internalModelUseSafeDelete) {
            $configObj = Config::getInstance()->object();
            $usages = array();
            // find uuid's in our config.xml
            foreach ($configObj->xpath("//text()[.='{$uuid}']") as $node) {
                $referring_node = $node->xpath("..")[0];
                if (!empty($referring_node->attributes()['uuid'])) {
                    // this looks like a model node, try to find module name (first tag with version attribute)
                    $item_path = array($referring_node->getName());
                    $item_uuid = $referring_node->attributes()['uuid'];
                    $parent_node = $referring_node;
                    do {
                        $parent_node = $parent_node->xpath("..");
                        $parent_node = $parent_node != null ? $parent_node[0] : null;
                        if ($parent_node != null) {
                            $item_path[] = $parent_node->getName();
                        }
                    } while ($parent_node != null && !isset($parent_node->attributes()['version']));
                    if ($parent_node != null) {
                        // construct usage info and add to usages if this looks like a model
                        $item_path = array_reverse($item_path);
                        $item_description = "";
                        foreach (["description", "descr", "name"] as $key) {
                            if (!empty($referring_node->$key)) {
                                $item_description = (string)$referring_node->$key;
                                break;
                            }
                        }
                        $usages[] = array(
                            "reference" =>  implode(".", $item_path) . "." . $item_uuid,
                            "module" => $item_path[0],
                            "description" => $item_description
                        );
                    }
                }
            }
            if (!empty($usages)) {
                // render exception message
                $message = "";
                foreach ($usages as $usage) {
                    $message .= sprintf(
                        gettext("%s - %s {%s}"),
                        $usage['module'],
                        $usage['description'],
                        $usage['reference']
                    ) . "\n";
                }
                throw new UserException($message, gettext("Item in use by"));
            }
        }
    }

    /**
     * Retrieve model settings
     * @return array settings
     * @throws \ReflectionException when not bound to a valid model
     */
    public function getAction()
    {
        // define list of configurable settings
        $result = array();
        if ($this->request->isGet()) {
            $result[static::$internalModelName] = $this->getModelNodes();
        }
        return $result;
    }

    /**
     * Override this to customize what part of the model gets exposed
     * @return array
     * @throws \ReflectionException
     */
    protected function getModelNodes()
    {
        return $this->getModel()->getNodes();
    }

    /**
     * Get (or create) model object
     * @return null|BaseModel
     * @throws \ReflectionException
     */
    protected function getModel()
    {
        if ($this->modelHandle == null) {
            $this->modelHandle = (new \ReflectionClass(static::$internalModelClass))->newInstance();
        }

        return $this->modelHandle;
    }

    /**
     * Validate and save model after update or insertion.
     * Use the reference node and tag to rename validation output for a specific node to a new offset, which makes
     * it easier to reference specific uuids without having to use them in the frontend descriptions.
     * @param string $node reference node, to use as relative offset
     * @param string $prefix prefix to use when $node is provided (defaults to static::$internalModelName)
     * @return array result / validation output
     * @throws \Phalcon\Filter\Validation\Exception on validation issues
     * @throws \ReflectionException when binding to the model class fails
     * @throws UserException when denied write access
     */
    protected function validateAndSave($node = null, $prefix = null)
    {
        $result = $this->validate($node, $prefix);
        if (empty($result['result'])) {
            $result = $this->save();
            if ($node !== null) {
                $attrs = $node->getAttributes();
                if (!empty($attrs['uuid'])) {
                    $result['uuid'] = $attrs['uuid'];
                }
            }
        }
        return $result;
    }

    /**
     * Validate this model
     * @param $node reference node, to use as relative offset
     * @param $prefix prefix to use when $node is provided (defaults to static::$internalModelName)
     * @return array result / validation output
     * @throws \ReflectionException when binding to the model class fails
     */
    protected function validate($node = null, $prefix = null)
    {
        $result = array("result" => "");
        $resultPrefix = empty($prefix) ? static::$internalModelName : $prefix;
        // perform validation
        $valMsgs = $this->getModel()->performValidation();
        foreach ($valMsgs as $field => $msg) {
            if (!array_key_exists("validations", $result)) {
                $result["validations"] = array();
                $result["result"] = "failed";
            }
            // replace absolute path to attribute for relative one at uuid.
            if ($node != null) {
                $fieldnm = str_replace($node->__reference, $resultPrefix, $msg->getField());
            } else {
                $fieldnm = $resultPrefix . "." . $msg->getField();
            }
            $msgText = $msg->getMessage();
            if (empty($result["validations"][$fieldnm])) {
                $result["validations"][$fieldnm] = $msgText;
            } elseif (!is_array($result["validations"][$fieldnm])) {
                // multiple validations, switch to array type output
                $result["validations"][$fieldnm] = array($result["validations"][$fieldnm]);
                if (!in_array($msgText, $result["validations"][$fieldnm])) {
                    $result["validations"][$fieldnm][] = $msgText;
                }
            } elseif (!in_array($msgText, $result["validations"][$fieldnm])) {
                $result["validations"][$fieldnm][] = $msgText;
            }
        }
        return $result;
    }

    /**
     * Save model after update or insertion, validate() first to avoid raising exceptions
     * @return array result / validation output
     * @throws \Phalcon\Filter\Validation\Exception on validation issues
     * @throws \ReflectionException when binding to the model class fails
     * @throws \OPNsense\Base\UserException when denied write access
     */
    protected function save()
    {
        if (!(new ACL())->hasPrivilege($this->getUserName(), 'user-config-readonly')) {
            $this->getModel()->serializeToConfig();
            Config::getInstance()->save();
            return array("result" => "saved");
        } else {
            // XXX remove user-config-readonly in some future release
            throw new UserException(
                sprintf("User %s denied for write access (user-config-readonly set)", $this->getUserName())
            );
        }
    }

    /**
     * Hook to be overridden if the controller is to take an action when
     * setAction is called. This hook is called after a model has been
     * constructed and validated but before it serialized to the configuration
     * and written to disk
     * @return string error message on error, or null/void on success
     */
    protected function setActionHook()
    {
    }

    /**
     * Update model settings
     * @return array status / validation errors
     * @throws \Phalcon\Filter\Validation\Exception on validation issues
     * @throws \ReflectionException when binding to the model class fails
     * @throws UserException when denied write access
     */
    public function setAction()
    {
        $result = array("result" => "failed");
        if ($this->request->isPost()) {
            // load model and update with provided data
            $mdl = $this->getModel();
            $mdl->setNodes($this->request->getPost(static::$internalModelName));
            $result = $this->validate();
            if (empty($result['result'])) {
                $hookErrorMessage = $this->setActionHook();
                if (!empty($hookErrorMessage)) {
                    $result['error'] = $hookErrorMessage;
                } else {
                    return $this->save();
                }
            }
        }
        return $result;
    }

    /**
     * This is a super API for Bootgrid which will do all the things.
     *
     * Instead of having many copies of the same functions over and over, this
     * function replaces all of them, and it requires only setting a couple of
     * variables and adjusting the conditional statements to add or remove
     * grids.
     *
     * API endpoint:
     *
     *   `/api/dnscryptproxy/settings/grid`
     *
     * Parameters for this function are passed in via POST/GET request in the URL like so:
     * ```
     * |-------API Endpoint (Here)-----|api|----$target---|--------------$uuid----------------|
     * api/dnscryptproxy/settings/grid/get/servers.server/9d606689-19e0-48a7-84b2-9173525255d8
     * ```
     * This handles all of the bootgrid API calls, and keeps everything in
     * a single function. Everything is controled via the `$target` and
     * pre-defined variables which include the config path, and the
     * key name for the edit dialog.
     *
     * A note on the edit dialog, the `$key_name` must match the prefix of
     * the IDs of the fields defined in the form data for that dialog.
     *
     * Example:
     * ```
     *  <field>
     *     <id>server.enabled</id>
     *     <label>Enabled</label>
     *     <type>checkbox</type>
     *     <help>This will enable or disable the server stamp.</help>
     *  </field>
     * ```
     *
     * For the case above, the `$key_name` must be: "server"
     *
     * This correlates to the config path:
     *
     * `//OPNsense/dnscrypt-proxy/servers/server`
     *
     * `servers` is the ArrayField that these bootgrid functions are designed
     *           for.
     *
     * `server`  is the final node in the config path, and are
     *           entries in the ArrayField.
     *
     * The `$key_name`, the final node in the path, and the field ids in the form
     * XML must match. The field <id> is important because when `mapDataToFormUI()`
     * runs to populate the fields with data, the scope is just the dialog
     * box (which includes the fields). It will try to match ids with the
     * data it receives, and it splits up the ids at the period, using the
     * first element as its `key_name` for matching. This is also how the main
     * form works, and why all of those ids are prefixed with the model name.
     *
     * So get/set API calls return a JSON with a key named 'server', and the
     * data gets sent to fields which have a dotted prefix of the same name.
     * This links these elements together, though they are not directly
     * linked, only merely aligned together.
     *
     * Upon saving (using `setBase()`) it sends the POST data specified
     * in the function call wholesale, that array has to overlay perfectly
     * on the model.
     *
     * @param string       $action The desired action to take for the API call.
     * @param string       $target The desired pre-defined target for the API.
     * @param string       $uuid   The UUID of the target object.
     * @return array Array to be consumed by bootgrid.
     */
    public function bootgridAction($action, $target, $uuid = null)
    {
        if (in_array($action, array(
                'search',
                'get',
                'set',
                'add',
                'del',
                'toggle',
            ))
        ) { // Check that we only operate on valid actions.
            //if (array_key_exists($target, $this->valid_grid_targets)) {  // Only operate on valid targets.
                $tmp = explode('.', $target);  // Split target on dots, have to use a temp var here.
                $key_name = end($tmp);         // Get the last node from the path, and this will be our $key_name.

                // Create a Settings class object to use for configd_name.
                $settings = new Settings();

                switch (true) {
                    case ($action === 'search' && isset($this->valid_grid_targets[$target])):
                        // Take care of special mode searches first.
                        //if (isset($this->valid_grid_targets[$target]['mode'])) {
                        //    if ($this->valid_grid_targets[$target]['mode'] == 'configd_cmd') {
                        //        return $this->bootgridConfigd(
                        //            $settings->configd_name . ' ' . $target,
                        //            $this->valid_grid_targets[$target]['columns']
                        //        );
                        //    }
                        //} elseif (isset($target)) { // All other searches, check $target is set.
                            return $this->searchBase($target, $this->valid_grid_targets[$target]['columns']);
                        //}
                        // no break
                    case ($action === 'get' && isset($key_name) && isset($target)):
                        return $this->getBase($key_name, $target, $uuid);
                    case ($action === 'add' && isset($key_name) && isset($target)):
                        return $this->addBase($key_name, $target);
                    case ($action === 'del' && isset($target) && isset($uuid)):
                        return $this->delBase($target, $uuid);
                    case ($action === 'set' && isset($key_name) && isset($target) && isset($uuid)):
                        return $this->setBase($key_name, $target, $uuid);
                    case ($action === 'toggle' && isset($target) && isset($uuid)):
                        return $this->toggleBase($target, $uuid);
                    default:
                        // If we get here it's probably a bug in this function.
                        $result['message'] =
                            'Some parameters were missing for action "' . $action . '" on target "' . $target . '"';
                }
            //} else {
            //    $result['message'] = 'Unsupported target ' . $target;
            //}
        } else {
            $result['message'] = 'Action "' . $action . '" not found.';
        }
        // Since we've gotten here, no valid options were presented,
        // we need to return a valid array for the bootgrid to consume though.
        $result['rows'] = array();
        $result['rowCount'] = 0;
        $result['total'] = 0;
        $result['current'] = 1;
        $result['status'] = 'failed';

        return $result;
    }

    /**
     * Model search wrapper
     * @param string $path path to search, relative to this model
     * @param array $fields fieldnames to fetch in result
     * @param string|null $defaultSort default sort field name
     * @param null|function $filter_funct additional filter callable
     * @param int $sort_flags sorting behavior
     * @return array
     * @throws \ReflectionException when binding to the model class fails
     */
    public function searchBase($path, $fields, $defaultSort = null, $filter_funct = null, $sort_flags = SORT_NATURAL)
    {
        $this->sessionClose();
        $element = $this->getModel();
        foreach (explode('.', $path) as $step) {
            $element = $element->{$step};
        }
        $grid = new UIModelGrid($element);
        return $grid->fetchBindRequest(
            $this->request,
            $fields,
            $defaultSort,
            $filter_funct,
            $sort_flags
        );
    }

    /**
     * Model get wrapper, fetches an array item and returns it's contents
     * @param string $key_name result root key
     * @param string $path path to fetch, relative to our model
     * @param null|string $uuid node key
     * @return array
     * @throws \ReflectionException when binding to the model class fails
     */
    public function getBase($key_name, $path, $uuid = null)
    {
        $mdl = $this->getModel();
        if ($uuid != null) {
            $node = $mdl->getNodeByReference($path . '.' . $uuid);
            if ($node != null) {
                // return node
                return array($key_name => $node->getNodes());
            }
        } else {
            foreach (explode('.', $path) as $step) {
                $mdl = $mdl->{$step};
            }
            $node = $mdl->Add();
            return array($key_name => $node->getNodes());
        }
        return array();
    }

    /**
     * Model add wrapper, adds a new item to an array field using a specified post variable
     * @param string $post_field root key to retrieve item content from
     * @param string $path relative model path
     * @param array|null $overlay properties to overlay when available (call setNodes)
     * @return array
     * @throws \Phalcon\Filter\Validation\Exception on validation issues
     * @throws \ReflectionException when binding to the model class fails
     * @throws UserException when denied write access
     */
    public function addBase($post_field, $path, $overlay = null)
    {
        $result = array("result" => "failed");
        if ($this->request->isPost() && $this->request->hasPost($post_field)) {
            $mdl = $this->getModel();
            $tmp = $mdl;
            foreach (explode('.', $path) as $step) {
                $tmp = $tmp->{$step};
            }
            $node = $tmp->Add();
            $node->setNodes($this->request->getPost($post_field));
            if (is_array($overlay)) {
                $node->setNodes($overlay);
            }
            $result = $this->validate($node, $post_field);

            if (empty($result['validations'])) {
                // save config if validated correctly
                $this->save();
                $result = array(
                    "result" => "saved",
                    "uuid" => $node->getAttribute('uuid')
                );
            } else {
                $result["result"] = "failed";
            }
        }
        return $result;
    }

    /**
     * Model delete wrapper, removes an item specified by path and uuid
     * @param string $path relative model path
     * @param null|string $uuid node key
     * @return array
     * @throws \Phalcon\Filter\Validation\Exception on validation issues
     * @throws \ReflectionException when binding to the model class fails
     * @throws UserException when denied write access
     */
    public function delBase($path, $uuid)
    {
        $result = array("result" => "failed");

        if ($this->request->isPost()) {
            $this->checkAndThrowSafeDelete($uuid);
            Config::getInstance()->lock();
            $mdl = $this->getModel();
            if ($uuid != null) {
                $tmp = $mdl;
                foreach (explode('.', $path) as $step) {
                    $tmp = $tmp->{$step};
                }
                if ($tmp->del($uuid)) {
                    $this->save();
                    $result['result'] = 'deleted';
                } else {
                    $result['result'] = 'not found';
                }
            }
        }
        return $result;
    }

    /**
     * Model setter wrapper, sets the contents of an array item using this requests post variable and path settings
     * @param string $post_field root key to retrieve item content from
     * @param string $path relative model path
     * @param string $uuid node key
     * @param array|null $overlay properties to overlay when available (call setNodes)
     * @return array
     * @throws \Phalcon\Filter\Validation\Exception on validation issues
     * @throws \ReflectionException when binding to the model class fails
     * @throws UserException when denied write access
     */
    public function setBase($post_field, $path, $uuid, $overlay = null)
    {
        if ($this->request->isPost() && $this->request->hasPost($post_field)) {
            $mdl = $this->getModel();
            if ($uuid != null) {
                $node = $mdl->getNodeByReference($path . '.' . $uuid);
                if ($node != null) {
                    $node->setNodes($this->request->getPost($post_field));
                    if (is_array($overlay)) {
                        $node->setNodes($overlay);
                    }
                    $result = $this->validate($node, $post_field);
                    if (empty($result['validations'])) {
                        // save config if validated correctly
                        $this->save();
                        $result = array("result" => "saved");
                    } else {
                        $result["result"] = "failed";
                    }
                    return $result;
                }
            }
        }
        return array("result" => "failed");
    }

    /**
     * Generic toggle function, assumes our model item has an enabled boolean type field.
     * @param string $path relative model path
     * @param string $uuid node key
     * @param string $enabled desired state enabled(1)/disabled(0), leave empty for toggle
     * @return array
     * @throws \Phalcon\Filter\Validation\Exception on validation issues
     * @throws \ReflectionException when binding to the model class fails
     * @throws UserException when denied write access
     */
    public function toggleBase($path, $uuid, $enabled = null)
    {
        $result = array("result" => "failed");
        if ($this->request->isPost()) {
            $mdl = $this->getModel();
            if ($uuid != null) {
                $node = $mdl->getNodeByReference($path . '.' . $uuid);
                if ($node != null) {
                    $result['changed'] = true;
                    if ($enabled == "0" || $enabled == "1") {
                        $result['result'] = !empty($enabled) ? "Enabled" : "Disabled";
                        $result['changed'] = (string)$node->enabled !== (string)$enabled;
                        $node->enabled = (string)$enabled;
                    } elseif ($enabled !== null) {
                        // failed
                        $result['changed'] = false;
                    } elseif ((string)$node->enabled == "1") {
                        $result['result'] = "Disabled";
                        $node->enabled = "0";
                    } else {
                        $result['result'] = "Enabled";
                        $node->enabled = "1";
                    }
                    // if item has toggled, serialize to config and save
                    if ($result['changed']) {
                        $this->save();
                    }
                }
            }
        }
        return $result;
    }
}
