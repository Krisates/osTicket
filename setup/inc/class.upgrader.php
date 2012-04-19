<?php
/*********************************************************************
    class.upgrader.php

    osTicket Upgrader

    Peter Rotich <peter@osticket.com>
    Copyright (c)  2006-2012 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/

require_once INC_DIR.'class.setup.php';

class Upgrader extends SetupWizard {

    var $prefix;
    var $sqldir;
    var $signature;

    function Upgrader($signature, $prefix, $sqldir) {

        $this->signature = $signature;
        $this->shash = substr($signature, 0, 8);
        $this->prefix = $prefix;
        $this->sqldir = $sqldir;
        $this->errors = array();

        //Init persistent state of upgrade.
        $this->state = &$_SESSION['ost_upgrader'][$this->getShash()]['state'];

        //Init the task Manager.
        if(!isset($_SESSION['ost_upgrader'][$this->getShash()]))
            $_SESSION['ost_upgrader'][$this->getShash()]['tasks']=array();

        //Tasks to perform - saved on the session.
        $this->tasks = &$_SESSION['ost_upgrader'][$this->getShash()]['tasks'];

        $this->migrater = DatabaseMigrater($this->sqldir);
    }

    function getStops() {
        return array('7be60a84' => 'migrateAttachments2DB');
    }

    function onError($error) {
        $this->setError($error);
        $this->setState('aborted');
    }

    function isUpgradable() {
        return (!$this->isAborted() && $this->getNextPatch());
    }

    function isAborted() {
        return !strcasecmp($this->getState(), 'aborted');
    }

    function getSchemaSignature() {
        return $this->signature;
    }

    function getShash() {
        return $this->shash;
    }

    function getTablePrefix() {
        return $this->prefix;
    }

    function getSQLDir() {
        return $this->sqldir;
    }

    function getState() {
        return $this->state;
    }

    function setState($state) {
        $this->state = $state;
    }

    function getPatches() {
        return $this->migrater->getRollup($this->getStops());
    }

    function getNextPatch() {
        $p = $this->getPatches();
        return (count($p)) ? $p[0] : false;
    }

    function getNextVersion() {
        if(!$patch=$this->getNextPatch())
            return '(Latest)';

        $info = $this->readPatchInfo($patch);
        return $info['version'];
    }

    function readPatchInfo($patch) {
        $info = array();
        if (preg_match('/\*(.*)\*/', file_get_contents($patch), $matches)) {
            if (preg_match('/@([\w\d_-]+)\s+(.*)$/', $matches[0], $matches2))
                foreach ($matches2 as $match)
                    $info[$match[0]] = $match[1];
        }
        if (!isset($info['version']))
            $info['version'] = substr(basename($patch), 9, 8);
        return $info;
    }

    function getNextAction() {

        $action='Upgrade osTicket to '.$this->getVersion();
        if($this->getNumPendingTasks() && ($task=$this->getNextTask())) {
            $action = $task['desc'];
            if($task['status']) //Progress report... 
                $action.=' ('.$task['status'].')';
        } elseif($this->isUpgradable() && ($nextversion = $this->getNextVersion())) {
            $action = "Upgrade to $nextversion";
        }

        return $action;
    }

    function getNextStepInfo() {

        if(($patches=$this->getPatches()))
            return $patches[0];
        
        if(($hooks=$this->getScriptedHooks()))
            return $hooks[0]['desc'];

        return null;
    }

    function getNumPendingTasks() {

        return count($this->getPendingTasks());
    }

    function getPendingTasks() {

        $pending=array();
        if(($tasks=$this->getTasks())) {
            foreach($tasks as $k => $task) {
                if(!$task['done'])
                    $pending[$k] = $task;
            }  
        }
        
        return $pending;
    }

    function getTasks() {
       return $this->tasks;
    }

    function getNextTask() {

        if(!($tasks=$this->getPendingTasks()))
            return null;

        return current($tasks);
    }

    function removeTask($tId) {

        if(isset($this->tasks[$tId]))
            unset($this->tasks[$tId]);

        return (!$this->tasks[$tId]);
    }

    function setTaskStatus($tId, $status) {
        if(isset($this->tasks[$tId]))
            $this->tasks[$tId]['status'] = $status;
    }

    function doTasks() {

        if(!($tasks=$this->getPendingTasks()))
            return true; //Nothing to do.

        foreach($tasks as $k => $task) {
            if(call_user_func(array($this, $task['func']), $k)===0) {
                $this->tasks[$k]['done'] = true;
            } else { //Task has pending items to process.
                break;
            }
        }

        return (!$this->getPendingTasks());
    }
    
    function upgrade() {

        if($this->getPendingTasks() || !($patches=$this->getPatches()))
            return false;

        foreach ($patches as $patch)
            if (!$this->load_sql_file($patch, $this->getTablePrefix()))
                return false;

        //TODO: Log the upgrade

        //Load up post install tasks.
        $shash = substr(basename($patch), 9, 8); 
        $phash = substr(basename($patch), 0, 17); 
        
        $tasks=array();
        $tasks[] = array('func' => 'sometask',
                         'desc' => 'Some Task.... blah');
        switch($phash) { //Add  patch specific scripted tasks.
            case 'xxxx': //V1.6 ST- 1.7 *
                $tasks[] = array('func' => 'migrateAttachments2DB',
                                 'desc' => 'Migrating attachments to database, it might take a while depending on the number of files.');
                break;
        }
        
        $tasks[] = array('func' => 'cleanup',
                         'desc' => 'Post-upgrade cleanup!');
        
        
        
        //Load up tasks - NOTE: writing directly to the session - back to the future majic.
        $_SESSION['ost_upgrader'][$shash]['tasks'] = $tasks;
        $_SESSION['ost_upgrader'][$shash]['state'] = 'upgrade';

        //clear previous patch info - 
        unset($_SESSION['ost_upgrader'][$this->getSHash()]);

        return true;
    }

    /************* TASKS **********************/
    function sometask($tId) {
        
        $this->setTaskStatus($tId, 'Doing... '.time(). ' #'.$_SESSION['sometask']);

        sleep(2);
        $_SESSION['sometask']+=1;
        if($_SESSION['sometask']<4)
            return 22;

        $_SESSION['sometask']=0;

        return 0;  //Change to 1 for testing...
    }

    function cleanup($tId=0) {

        $file=$this->getSQLDir().$this->getSchemaSignature().'-cleanup.sql';
        if(!file_exists($file)) //No cleanup script.
            return 0;

        //We have a cleanup script  ::XXX: Don't abort on error? 
        if($this->load_sql_file($file, $this->getTablePrefix(), false, true))
            return 0;

        //XXX: ???
        return false;
    }
    

    function migrateAttachments2DB($tId=0) {
        echo "Process attachments here - $tId";
        $att_migrater = new AttachmentMigrater();
        $att_migrater->start_migration();
        # XXX: Loop here (with help of task manager)
        $att_migrater->do_batch();
        return 0;
    }
}
?>
