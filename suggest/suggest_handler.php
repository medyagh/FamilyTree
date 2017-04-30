<?php

/**
 * This package will be used to handle the suggest system. It will hold
 * all the fields that can be edited and all the types of suggsestion
 * which this system can entertain
 * @author Piyush
 */
/**
 * This array will hold all types of suggestions
 */
$suggests = array();

require_once 'suggest_storage.php';

class suggest_handler {

    public function __construct() {
        
    }
    /** This function prepares the template to display the data to ths user. 
     * The input $detail here is raw extract of suggest table, where all the
     * suggestion is stored. It prepares the template and data according to it
     * 
     * 
     * @global \user $user
     * @global vanshavali $vanshavali
     * @global \template $template
     * @param array $detail raw extract of suggest table
     * @return string|boolean if all goes fine then parsed that is to shown else false
     */
    public function getviewname($detail, $approved = false) {

        global $user, $template;
        
        //Find the structure of the suggest
        $struct = $this->find_structure($detail['typesuggest']);
        $suggestion = new suggest($detail['id']);
        
        //Get the percent of approval
        $percentArray = $suggestion->checkpercent(); 
        
        //Assign the percent to template
        $finalarray['suggestid'] = $detail['id'];
        $finalarray['yespercent'] = $percentArray[0];
        $finalarray['nopercent'] = $percentArray[1];
        $finalarray['dontknowpercent'] = $percentArray[2];

        //Now do check here if we have the structure
        //because if not then the program will crash
        //Collect the data needed
        //Here is the needed data
        //from , to , old_value, newvalue, sod

        $finalarray['suggested_by'] = vanshavali::getmember($detail['suggested_by']);
        $finalarray['suggested_to'] = vanshavali::getmember($detail['suggested_to']);
        $finalarray['oldvalue'] = is_null($detail['old_value']) ? "" : $detail['old_value'];

        //Now check if new value is a json..
        $decoded = json_decode($detail['new_value'], TRUE);
        if (!is_null($decoded)) {
            if (isset($decoded[NAME])) {
                $finalarray['newvalue'] = $decoded[NAME];
            } else {
                $finalarray['newvalue'] = $decoded;
            }

            //Now check if gender is there or not
            if (isset($decoded[GENDER])) {
                $finalarray['sod'] = $decoded[GENDER];
            } else { // if not then assign the gender of the to member as it is being modified
                $finalarray['sod'] = $finalarray['suggested_to']->gender();
            }
        } else {
            //This is going to happen when we have suggestion which has no old value or new value
            //SO better be ready for that
            //We already have old_value so prepare new value
            $finalarray['newvalue'] = $detail['new_value'];

            //and sod
            $finalarray['sod'] = $finalarray['suggested_to']->gender();
        }


        //Check if we have all the data that needs to be passed
        $error = false;
        foreach ($struct->parameter as $value) {
            if (!isset($finalarray[$value])) {
                $error = TRUE;
                echo "we broke at $value";
                break;
            }
        }

        //Check if only to show approved data
        if ($approved)
        {
            $template->assign("approvedonly", true);

            //Get the result of the suggest and show it
            $suggestResult = $this->getSuggestResult($detail['id']);

            $suggestResultText = array(0 => "Rejected", 1=> "Approved", 2=> "Don't Know", 3 => "Pending");

            

            //Assign the result of the suggest in the template
            $template->assign("suggestionResult", $suggestResultText[$suggestResult]);
            
        }

        //get the template content, We haven't passed any data into it. So check here
        if ($error) {
            trigger_error("Not enough parameters to show the suggestion: $detail[1]", E_USER_ERROR);
            return false;
        } else {
            $template->assign($finalarray);
            $result = $template->fetch($struct->tpl);

            return $result;
        }
    }

    function getSuggestResult($id)
    {
        global $db;

        $suggest = new suggest($id);
        $percentArray = $suggest->checkpercent();
        $userAction = $suggest->getUserAction();

        //Check which percent is up
        if ($percentArray[0] > 50)
        {
            return array(1, $userAction); // It was approved
        }
        else if ($percentArray[1] > 50)
        {
            return array(0, $userAction); // It was rejected
        }
        else if ($percentArray[2] > 50)
        {
            return array(2, $userAction); //People Didn't knew about it
        }
        else
        {
            return 3;
        }
    }

    
    /**
     * This function shows all the suggestion on which the user has to give his
     * approval and has not given any as of now. It directly echos the template
     * rather than returning the suggestion
     * @global \db $db
     * @global \user $user
     */
    public function getsuggestions() {
        global $db, $user;

        //Make the query
        $query = $db->query("select * from suggested_info where approved=0 and id not in 
            (select suggest_id from suggest_approved where user_id=" . $user->user['id'] . ")");

        //Now prepare the data to be shown
        while ($row = $db->fetch($query)) {
            echo $this->getviewname($row);
        }
    }

    public function getApprovedSuggestions()
    {
        global $user, $db;

        //Query for the approved suggestion by this user
        $query = $db->query("select * from suggested_info where id in (select suggest_id from suggest_approved where user_id = ". $user->user['id'] . ")");
        
        while($row = $db->fetch($query))
        {
            echo $this->getviewname($row, true);
        }
    }

    /**
     * This method is to be used to register a new suggest type
     * @param type $name The name of the suggest
     * @param type $tpl The tpl to be used while showing user the suggest
     * @param type $parameters Any parameters required by the suggest
     * @return boolean Return true if successfully registered
     */
    public function register_handler($name, $tpl, $parameter, $type) {
        global $suggests;
        if (empty($name) || empty($tpl) || empty($parameter) || empty($type)) {
// Here raise a serious error and working will be interrupted if
// the given suggest is not registered
            trigger_error("$name suggest not registered correctly. Please check", E_USER_ERROR);
            return false;
        }
// Store all the information of the suggest
        $suggests[] = new suggest_storage($name, $tpl, $parameter, $type);
    }
/**
 * This is a global function which is called when we need to any suggestion
 * is to be added in the database. It checks the type of suggestion
 * and adds the suggestion accordingly in the database.
 * 
 * @global \db $db
 * @global \user $user
 * @param string $name This is the type of the suggestion we are adding eg ADDMEMBER etc
 * @param int $to This is new value which is to be updated and varies
 * according to the type of the suggestion. Default value of this is NULL for add/del type of suggestion
 * @param array|null $new_value ID of the member to which the suggestion is to be applied
 * @return boolean
 */
    public function add_suggest($name, $to, $new_value = NULL) {
        global $db, $user;

        //To return at the end
        $success = true;
        // First find the parameters and structure of the given suggest
        $suggest_structure = $this->find_structure($name);

        if (!$suggest_structure) {
            trigger_error("Wrong Suggestion Name Passed. Please check.", E_USER_ERROR);
        }

        //The suggest structure is not simple in this case we have three types of suggest
        // ie add/remove/modify. Find out the type of the suggest
        $suggesttype = $suggest_structure->type;

        //Now use switch to do execution according to the type
        switch ($suggesttype) {
            case ADD:
            case DEL:
                //Now in this case we don't have any old value or new value
                //So the newvalue and the old value field remains empty in this case
                //We don't have to find any old value. So lets implement
                //As we have composite value while adding and removing a member i.e. name and gender
                //we put it in an array for it to be passed on.
                

                $new_value = json_encode($new_value);
                if (!$db->query("insert into suggested_info (typesuggest, new_value, old_value, suggested_by, suggested_to, ts) values('$name', '$new_value', null, " . $user->user['id'] . ", $to, " . time() . ")")) {
                    $success = false;
                }
                break;
            case MODIFY:
                //Now in this case there always will be a new value and a old value. So nothing is empty
                //So lets find the old value
                $query = $db->fetch($db->query("select $name from member where id=$to"));

                $old_value = $query[$name]; // And we have the old value now lets add the suggest
                //But first lets check if the old value and the new value are same
                if ($old_value == $new_value) {
                    return true;
                }
                if (!$db->query("insert into suggested_info (typesuggest, new_value, old_value, suggested_by, suggested_to, ts) values('$name', '$new_value', '$old_value', " . $user->user['id'] . ", $to, " . time() . ")")) {
                    $success = false;
                }
                break;
        }

        // If all goes well return return new ID just made
        if ($success)
        {
            return $db->last_id();
        }
    }

    /**
     * 
     * @param string $name
     * @return boolean|suggest_storage
     */
    public function find_structure($name) {
        global $suggests;
        $found_key = NULL;
        foreach ($suggests as $key => $value) {
            if ($value->name == $name) {
                $found_key = $key;
                break;
            }
        }
        if (!is_null($found_key)) {
            return $suggests[$found_key];
        } else {
            return false;
        }
    }

}
