<?php

/**
 * Processwire module to restrict site editors to a single branch of the tree.
 * by Adrian Jones updated by UP-Solutions GmbH
 *
 * Copyright (C) 2021 by Adrian Jones
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 *
 */

class AdminRestrictBranchUp extends WireData implements Module, ConfigurableModule
{

    /**
     * Basic information about module
     */
    public static function getModuleInfo()
    {
        return array(
            'title' => 'Admin Restrict Branch updated by UP-Solutions Gmbh',
            'summary' => 'Restrict site editors to a single branch of the tree.',
            'author' => 'Adrian Jones ,UP-Solutions GmbH',
            'href' => 'https://processwire.com/talk/topic/11499-admin-restrict-branch/',
            'version' => '1',
            'autoload' => true,
            'singular' => true,
            'icon' => 'key',
            'requires' => 'ProcessWire>=2.5.14',
        );
    }


    /**
     * Data as used by the get/set functions
     *
     */
    protected $data = array();
    protected $restrictionEnabled = false;
    protected $branchRootParentId = '';
    protected $noMatch = false;


    /**
     * Default configuration for module
     *
     */
    static public function getDefaultData()
    {
        return array(
            "matchType" => 'disabled',
            "branchesParent" => null,
            "allOrNone" => 'all',
            "branchExclusions" => null,
            "restrictType" => 'editing_and_view',
            "restrictFromSearch" => null,
            "modifyBreadcrumbs" => null
        );
    }


    /**
     * Populate the default config data
     *
     */
    public function __construct()
    {
        foreach (self::getDefaultData() as $key => $value) {
            $this->$key = $value;
        }
    }


    /**
     * Initialize the module and setup hooks
     */
    public function init()
    {

        // early exit if match type not set yet or superuser or guest user (we don't need to restrict them further)
        if ($this->data['matchType'] == 'disabled' || $this->data['matchType'] == '' || $this->wire('user')->isSuperuser() || $this->wire('user')->isGuest()) return;

        // get the branch root parent page ID for the matched page
        $this->branchRootParentId = $this->getBranchRootParentId();

        // if the matched branch parent is home (this can actually also mean there was no match) and non-matching users
        // are allowed to view 'all' then exit because we don't need to do anything else with this module
        if ($this->branchRootParentId === 1 && $this->data['allOrNone'] == 'all') return;

        // modify $page->editable() and $page->addable() based on branch restrictions - works in admin and front-end (for FREDI, FEEL, etc)
        $this->wire()->addHookAfter('Page::editable', $this, 'hookPageEditable');
        $this->wire()->addHookAfter('Page::addable', $this, 'hookPageAddable');

        // restrict from search results, like for pages returned from autocomplete when inserting a link to a page in a CKEditor field
        if ($this->data['restrictFromSearch']) $this->wire()->addHookAfter('ProcessPageSearch::executeFor', $this, 'restrictFromSearch');

        $this->restrictionEnabled = true;
    }

    public function ready()
    {

        // exit if enabled not true from init()
        if (!$this->restrictionEnabled) return;

        // only set up page list restriction hook if in admin and restrict type allows it
        if ($this->wire('page')->template == 'admin' && $this->data['restrictType'] == 'editing_and_view') {
            if (!isset($_GET['id'])) {
                $this->wire()->addHookBefore('ProcessPageList::execute', $this, 'setBranchRoot', array('priority' => 2));
            } elseif (is_numeric($_GET['id'])) {
                $this->wire()->addHookBefore('ProcessPageList::execute', $this, 'resetId', array('priority' => 1));
            }
            // modify breadcrumbs to remove pages outside restricted branch
            if ($this->data['modifyBreadcrumbs']) $this->wire()->addHookAfter('Process::breadcrumb', $this, 'modifyBreadcrumb');

            // make Add New button respect branch restrictions
            $this->wire()->addHookAfter('ProcessPageAdd::executeNavJSON', $this, 'restrictPageAdd');
            $this->wire()->addHookBefore('ProcessPageList::executeNavJSON', $this, 'restrictPageList');

            // make sure results from Lister are limited to restricted branch
            $this->wire()->addHookAfter('ProcessPageLister::getSelector', $this, 'limitLister');
        }
    }

    protected function limitLister($event)
    {
        if ($this->wire('page')->process == 'ProcessUser') return;
        $event->return = $event->return . ', has_parent=' . $this->branchRootParentId;
    }

    protected function modifyBreadcrumb($event)
    {
        $href = $event->arguments[0];
        if (strpos($href, '=') !== false) {
            $string_parts = explode("=", $href);
            $pid = $string_parts[1];
            if (!$this->onAllowedBranches($this->wire('pages')->get((int)$pid))) {
                $this->wire('breadcrumbs')->pop();
                // add a "Pages" breadcrumb so it is easy to get back to the main PageList view
                if ($this->wire('breadcrumbs')->count() == 0) {
                    $this->wire('breadcrumbs')->add(new Breadcrumb($this->wire('config')->urls->admin . 'page/', 'Pages'));
                }
            }
        }
    }

    protected function restrictPageAdd($event)
    {
        $responseArray = is_array($event->return) ? $event->return : json_decode($event->return, true);
        $matches = $responseArray['list'];
        $filteredMatches = array_filter($matches, fn($m) => is_array($m));
        $filtered = array_filter($matches, fn($m) => !is_array($m));
        $i = 0;
        foreach ($filteredMatches as $match) {
            if (isset($match['parent_id']) && $match['parent_id'] == 0) {
                $allowedPage = $this->wire('pages')->findOne("has_parent=" . $this->branchRootParentId . ",template=" . $match['template_id']);
                if ($allowedPage) {
                    $filteredMatches[$i]['parent_id'] = $allowedPage->parent->id;
                    $filteredMatches[$i]['url'] = $match['url'] . '&parent_id=' . $filteredMatches[$i]['parent_id'];
                }
            }
            $i++;
        }

        $responseArray['list'] = array_merge($filtered, $filteredMatches);
        $event->return = is_array($event->return) ? $responseArray : json_encode($responseArray);
    }

    protected function restrictPageList($event)
    {
        if (!$this->wire('input')->get->parent_id) $this->wire('input')->get->parent_id = $this->branchRootParentId;
    }

    protected function restrictFromSearch($event)
    {
        $response = $event->return;
        $responseArray = json_decode($response, true);
        $matches = $responseArray['matches'];
        $i = 0;
        foreach ($matches as $match) {
            if (!$this->onAllowedBranches($this->wire('pages')->get($match['id']))) {
                unset($matches[$i]);
            }
            $i++;
        }
        $responseArray['matches'] = $matches;
        $event->return = json_encode($responseArray);
    }

    // in case the user tries to manually pass an id in the url to access another branch
    protected function resetId($event)
    {

        $access = $this->accessCheck();
        if ($access != 'all') {
            $this->error($access);
            $event->replace = true;
            $event->return = false;
        }

        if (isset($_GET['id']) && !$this->onAllowedBranches($this->wire('pages')->get((int)$_GET['id']))) {
            // get the restricted branch root
            $_GET['id'] = $this->branchRootParentId;
            $this->wire('input')->get->id = $_GET['id'];
        }
    }


    // set pagelist root to the correct parent based on the "How to match user to branch settings"
    protected function setBranchRoot($event)
    {

        $access = $this->accessCheck();
        if ($access != 'all') {
            $this->error($access);
            $event->replace = true;
            $event->return = false;
        }

        // set parent page of branch
        $_GET['id'] = $this->branchRootParentId;
        $this->wire('input')->get->id = $_GET['id'];

        // if pagelist_open cookie is set
        // then need this to prevent doubling of page branch
        if (isset($this->wire('input')->cookie->pagelist_open) && $this->branchRootParentId !== 1) {
            $this->wire('input')->cookie->pagelist_open = str_replace('"1-0",', '', $this->wire('input')->cookie->pagelist_open);
        }

        // if open get variable is set and it matches the defined parent,
        // then need this to prevent doubling of page branch
        if (isset($_GET['open']) && $_GET['open'] == $this->branchRootParentId) $this->wire('input')->get->open = null;
    }

    private function accessCheck()
    {
        if (($this->data['allOrNone'] == 'none' && $this->noMatch === true) || $this->branchRootParentId === false) {
            return __("You don't have permission to view this branch of the page tree.");
        } else {
            return 'all';
        }
    }

    protected function ___getBranchRootParentId()
    {
        $p = $this->wire('user')->branch_parent;

        // if no match, default to the homepage: id = 1, but set noMatch variable
        // so it can be used in conjunction with the allOrNone setting to determine what they have access to
        if (isset($p) && $p->id) {
            return $p->id;
        } else {
            $this->noMatch = true;
            return 1;
        }
    }



    /**
     * Check if this page, or any ancestor pages, are the defined branch root parent or in the excluded branches
     *
     * From Netcarver
     */
    private function onAllowedBranches($page)
    {
        $page_on_my_branch = $this->branchCheck($page);
        if (!$page_on_my_branch) {
            $parents = $page->parents();

            while (!$page_on_my_branch && count($parents)) {
                $p = $parents->pop();
                $page_on_my_branch = $this->branchCheck($p);
            }
        }
        return $page_on_my_branch;
    }

    private function branchCheck($page)
    {
        $access = $this->accessCheck();
        $repeaterParent = $this->wire('pages')->get("path=" . $this->wire('config')->urls->admin . "repeaters/");
        array_push($this->data['branchExclusions'], $repeaterParent->id);
        if (in_array($page->id, $this->data['branchExclusions'])) {
            return true;
        } elseif ($access != 'all') {
            return false;
        } else {
            return $page->id == $this->branchRootParentId ? true : false;
        }
    }

    /**
     * Page::editable hook
     *
     */
    protected function hookPageEditable($event)
    {
        // in case there is already a defined exclusion for this user's role for this page
        if (!$event->return) return;

        if ($this->wire('user')->hasPermission('page-edit')) {
            $event->return = $this->onAllowedBranches($event->object);
        } else {
            $event->return = false;
        }
    }

    /**
     * Page::addable hook
     *
     */
    protected function hookPageAddable($event)
    {
        // in case there is already a defined exclusion for this user's role for this page
        if (!$event->return) return;

        if ($this->wire('user')->hasPermission('page-add')) {
            $event->return = $this->onAllowedBranches($event->object);
        } else {
            $event->return = false;
        }
    }



    /**
     * Return an InputfieldsWrapper of Inputfields used to configure the class
     *
     * @param array $data Array of config values indexed by field name
     * @return InputfieldsWrapper
     *
     */
    public function getModuleConfigInputfields(array $data)
    {

        $data = array_merge(self::getDefaultData(), $data);

        $wrapper = new InputfieldWrapper();

        $f = $this->wire('modules')->get("InputfieldRadios");
        $f->attr('name', 'matchType');
        $f->label = __('How to match user to branch', __FILE__);
        $f->description = "• " . __("'User Specified Branch Parent' is specifically set on each user's profile page using the 'Branch parent to restrict access to' field.", __FILE__) . PHP_EOL . "• " . __("'Role Specified Branch Parent' is specifically set on each role page using the 'Branch parent to restrict access to' field.", __FILE__) . PHP_EOL . "• " . __("'Role Name' limits users to the branch whose parent page name matches the name of one of their roles, eg. 'branch-one' role will be restricted to the 'Branch One' branch.", __FILE__) . PHP_EOL . "• " . __("'Custom PHP Code' allows you to build up a page name based on user fields.", __FILE__);
        $f->notes = __('WARNING!! If you use the "Role Name" or the "Custom PHP code (returning a page name or path)", you must be sure to select "No Access" for the "If no match, give all access or no access", because an editor could change the name of the branch parent resulting in no match, and therefore gain full access.', __FILE__);
        $f->required = true;
        $f->addOption('disabled', __('Disabled', __FILE__));
        $f->addOption('specified_parent', __('User Specified Branch Parent', __FILE__));
        $f->value = $data['matchType'];
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldPageListSelect");
        $f->attr('name', 'branchesParent');
        $f->label = __('Parent to restrict Role Name and Custom PHP code matches to.', __FILE__);
        $f->description = __('The Role Name option matches a page name (not a path), so this option limits matching to branches under the selected parent. This can also be used for the Custom PHP code option if you choose to return a name, rather than a path. Note: a path is recommended.', __FILE__);
        $f->notes = __('This setting is optional but recommended if trying to match a page name (rather than a path) - if no page is chosen, the selector will search the entire page tree for matching page names. Note that it is checked using "has_parent" so the selected page can be further up the tree than a direct parent.', __FILE__);
        $f->showIf = "matchType!='specified_parent'";
        $f->value = $data['branchesParent'];
        $wrapper->add($f);


        $f = $this->wire('modules')->get("InputfieldRadios");
        $f->attr('name', 'allOrNone');
        $f->label = __('If no match, give all access or no access?', __FILE__);
        $f->description = __("If the user doesn't match based on the defined rule, do you want the user to have access to the entire page tree or have no access?", __FILE__);
        $f->notes = __('WARNING!! If you use the "Role Name" or the "Custom PHP code (returning a page name or path)", you must be sure to select "No Access", because an editor could change the name of the branch parent resulting in no match, and therefore gain full access.', __FILE__);
        $f->required = true;
        $f->addOption('all', __('Entire Page Tree', __FILE__));
        $f->addOption('none', __('No Access', __FILE__));
        $f->value = $data['allOrNone'];
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldRadios");
        $f->attr('name', 'restrictType');
        $f->label = __('Restrict editing and list viewing, or just editing.', __FILE__);
        $f->description = __("With 'Editing Only' selected, users will still see all branches in the page tree, but their editing access will still be restricted.", __FILE__);
        $f->required = true;
        $f->addOption('editing_and_view', __('Editing and List Viewing', __FILE__));
        $f->addOption('editing_only', __('Editing Only', __FILE__));
        $f->value = $data['restrictType'];
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldPageListSelectMultiple");
        $f->attr('name', 'branchExclusions');
        $f->label = __('Branch edit exclusions', __FILE__);
        $f->description = __("Selected branches will be excluded from branch edit restrictions. They still won't show in the page list, but they will remain editable, which is useful for external PageTable branches etc.", __FILE__);
        $f->value = $data['branchExclusions'];
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldCheckbox");
        $f->attr('name', 'restrictFromSearch');
        $f->label = __('Restrict from search results', __FILE__);
        $f->description = __('Check this to prevent search results in the admin from finding pages outside of the restricted branch. This is primarily for the autocomplete response when inserting a link to a URL from a CKEditor field.', __FILE__);
        $f->attr('checked', $data['restrictFromSearch'] == '1' ? 'checked' : '');
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldCheckbox");
        $f->attr('name', 'modifyBreadcrumbs');
        $f->label = __('Modify Breadcrumbs', __FILE__);
        $f->description = __('Check this to remove pages from the breadcrumbs that are outside of the restricted branch.', __FILE__);
        $f->attr('checked', $data['modifyBreadcrumbs'] == '1' ? 'checked' : '');
        $wrapper->add($f);

        return $wrapper;
    }


    public function ___install()
    {

        // create branch_parent field on user template
        if (!$this->wire('fields')->branch_parent) {
            $f = new Field();
            $f->type = "FieldtypePage";
            $f->derefAsPage = 2;
            $f->allowUnpub = 1;
            $f->inputfield = "InputfieldPageListSelect";
            $f->name = "branch_parent";
            $f->label = "Branch parent to restrict access to";
            $f->description = "This is used by the Admin Restrict Branch module to limit this user to only see the branch starting with this parent when viewing the page list in the admin. It also restricts editing access to just this branch.";
            $f->notes = __("This only works if this Admin Restrict Branch module config option is set to 'Specified Branch Parent'.", __FILE__);
            $f->collapsed = Inputfield::collapsedBlank;
            $f->save();

            foreach ($this->wire('config')->userTemplateIDs as $userTemplateId) {
                $userTemplate = $this->wire('templates')->get($userTemplateId);
                $userTemplate->fields->add($f);
                $userTemplate->fields->save();
            }

            $roleTemplate = $this->wire('templates')->get('role');
            $roleTemplate->fields->add($f);
            $roleTemplate->fields->save();
        }
    }


    public function ___uninstall()
    {

        // remove branch_parent field
        if ($this->wire('fields')->branch_parent) {

            $f = $this->wire('fields')->branch_parent;

            foreach ($this->wire('config')->userTemplateIDs as $userTemplateId) {
                $userTemplate = $this->wire('templates')->get($userTemplateId);
                $userTemplate->fields->remove($f);
                $userTemplate->fields->save();
            }

            $roleTemplate = $this->wire('templates')->get('role');
            $roleTemplate->fields->remove($f);
            $roleTemplate->fields->save();

            $this->wire('fields')->delete($f);
        }
    }


    public function ___upgrade($fromVersion, $toVersion)
    {
        $roleTemplate = $this->wire('templates')->get('role');
        $branchParentField = $this->wire('fields')->get('branch_parent');
        if (!$roleTemplate->hasField($branchParentField)) {
            $roleTemplate->fields->add($branchParentField);
            $roleTemplate->fields->save();
        }
    }
}
