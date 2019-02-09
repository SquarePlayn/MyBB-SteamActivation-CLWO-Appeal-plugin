<?php

//Deny direct initialization for extra security
if(!defined("IN_MYBB")) {
    die("You Cannot Access This File Directly. Please Make Sure IN_MYBB Is Defined.");
}

/** Pseudocode (one or two words might be outdated but still shows the general idea)
 *
 *  When any page requested: look at get requests
 *      If get request for appeal set:
 *          Yes-> Check if logged in and set up:
 *              No->Tell to log in with link to login page while remaining the appeal get var
 *              Yes-> check if banned:
 *                  No: Tell that they request an unban but are not banned with link to site (aka remove appeal get var)
 *                  Yes-> Check if running appeal:
 *                      Yes-> Send to the appeal
 *                      No-> Check time banned for long enough (through both the plugin-db and the blacklisted time):
 *                          No-> Tell them to wait
 *                          Yes-> Check if POST post-text values are present:
 *                              Yes-> Post unban appeal and redirect user to it
 *                              No-> Show unban appeal page
 *          No-> Check if request for handleappeal is set as well as a valid steamid:
 *              Yes-> Check if user actually has an appeal running:
 *                  No-> Tell that this appeal has already been dealt with.
 *                  Yes-> Check if user is logged in:
 *                      No-> Tell to log in with link to the site remaining the handleappeal get var
 *                      Yes-> Check if one of the given admins:
 *                          No-> Tell to STFU since they are not admin. Nice try
 *                          Yes-> Check if action GET is set:
 *                              No-> Show handle appeal page
 *                              Yes-> action is unban:
 *                                  Yes-> Unban (disable DB entry and add [Accepted] to title)
 *                                  No-> Try to safely get time values:
 *                                      Fail-> Tell that it failed
 *                                      Success-> Keep banned (put time in DB entry, disable DB entry
 *                                                ,add [Declined] to title) and tell admin that success
 *
 * ***Unban Appeal Page***
 * If proceeded to the unban appeal page, they can write an appeal explaining what happened and such. Basically, fill in a form.
 * When posted, it uses the forum bot to post to the forums, including useful info about the ban.
 * Posting also puts an entry in the DB with thread ID.
 *
 * ***Handle Appeal Page***
 *  If arrived at the handle appeal page, the admin will get to see a page where they can choose to accept or decline the appeal.
 *  Accepting will remove the entry from the DB and attach [Accepted] to the title of the post.
 *  Declining needs a value for years-months-days for the next appeal possibility.
 *      It will update the DB entry with the given next appeal possibility and add [Denied] to the title of the post.
 *
 * ***Settings***
 * - forum (forum) The forum in which the blacklist appeals will be posted
 * - admins(textfield) Steamids of the admins
 * - bantime (number) The maximal time before appealing is possible
 * - banratio (textfield) The ratio after which you can appeal
 * - mincharacters (number) The minimal length of an unban appeal
 * - curl (boolean) Use curl or not
 * - debug (boolean) Debug messages on/off
 *
 * ***DB Fields***
 * appealid: (Number) - Unique ID for every appeal
 * blacklistid (Number) - BlacklistID of the ban
 * active: (boolean) - If the appeal is still open
 * steamid: (Number) - SteamID64 of the appealer
 * time: (Number) - Unix timestamp of the unban appeal
 * threadid: (Number) - ID of the thread
 * postid: (Number) - ID of the post of the appealer
 * nextappeal: (Number) - Unix timestamp of availability to post next appeal
 * processedby: (Number) - SteamID64 of the admin that processed this appeal *
 *
 */

//Hooks
$plugins->add_hook("global_start", "steam_activation_blacklistappeal_global_start");

//Plugin information
function steam_activation_blacklistappeal_info() {
    steam_activation_blacklistappeal_f_debug("Primitive function: info()");

    return array(
        "name"  => "CLWO Blacklist appeals",
        "description"=> 'This plugin handles the blacklist appeals for the CLWO Community.<br/>Make sure the steam_activation plugin is active and banned users have the permission to view the unban appeal forum.<br/>They do not need post permission as the post will be forced, tho you can choose so turn on that they can reply to their own posts.',
        "website"        => "https://clwo.eu",
        "author"        => "Square Play'n",
        "authorsite"    => "http://squareplayn.com",
        "version"        => "1.0.1",
        "guid"             => "",
        "codename"      => "steam_activation_blacklistappeal",
        "compatibility" => "18*"
    );
}

//Return if plugin is installed
function steam_activation_blacklistappeal_is_installed() {
    steam_activation_blacklistappeal_f_debug("Primitive function: is_installed()");
    global $mybb;

    //Look for the debug setting
    return isset($mybb->settings["steam_activation_blacklistappeal_debug"]);
}

//Installation procedure for plugin
function steam_activation_blacklistappeal_install() {
    steam_activation_blacklistappeal_f_debug("Primitive function: install()");
    global $db;

    //Add the row in which we will put the steam id's
    steam_activation_blacklistappeal_f_debug("Adding steam_activation_blacklistappeal table to the mybb database");
    $db->write_query("CREATE TABLE IF NOT EXISTS `".$db->table_prefix."steam_activation_blacklistappeal` (
      `appealid` int(11) NOT NULL,
      `blacklistid` int(11) NOT NULL,
      `active` int(11) NOT NULL DEFAULT '0',
      `steamid` varchar(17) NOT NULL,
      `time` int(11) DEFAULT NULL,
      `threadid` int(11) DEFAULT NULL,
      `postid` int(11) DEFAULT NULL,
      `nextappeal` int(11) DEFAULT NULL,
      `processedby` varchar(17) DEFAULT NULL
      )");
    $db->write_query("ALTER TABLE `".$db->table_prefix."steam_activation_blacklistappeal` ADD PRIMARY KEY (`appealid`);");
    $db->write_query("ALTER TABLE `".$db->table_prefix."steam_activation_blacklistappeal` MODIFY `appealid` int(11) NOT NULL AUTO_INCREMENT;");


    /****** SETTINGS *******/

    //Setup settings group
    steam_activation_blacklistappeal_f_debug("Adding the settings group to table:settinggroups");
    $settings_group = array(
        "gid"    		=> "NULL",
        "name" 		 	=> "steam_activation_blacklistappeal",
        "title"      	=> "CLWO Blacklist appeal",
        "description"   => "Settings For the CLWO Blacklist Appeal plugin",
        "disporder"    	=> "1",
        "isdefault"  	=> "no",
    );
    $gid = $db->insert_query("settinggroups", $settings_group);

    //All the actual settings fields
    steam_activation_blacklistappeal_f_debug("Initializing all settings");

    $settings_array = array(

        "steam_activation_blacklistappeal_forum" => array(
            "title"			=> "Blacklist Appeal Forum",
            "description"	=> 'Select the forum the blacklist appeal posts should go into.',
            "optionscode"	=> "forumselectsingle ",
            "value"			=> '47'
        ),

        "steam_activation_blacklistappeal_admins" => array(
            "title"			=> "Steam admins",
            "description"	=> 'These are the SteamID64s of the admins that will be allowed to accept and decline the blacklist appeals. List each one after each other, with a ; in between.',
            "optionscode"	=> "textarea",
            "value"			=> '76561198008517033;76561198034364892;76561198053708748'
        ),

        "steam_activation_blacklistappeal_bantime" => array(
            "title"			=> "Minimum appeal time",
            "description"	=> 'Fill in the minimal time any user must wait before their first appeal in seconds. Will only be used if the time is less then the beneath ratio times the banlength',
            "optionscode"	=> "numeric",
            "value"			=> '10368000'
        ),

        "steam_activation_blacklistappeal_banratio" => array(
            "title"			=> "Minimum appeal ratio",
            "description"	=> 'Fill in the ratio any user must wait before their first appeal in seconds. Use a dot for the separator. Example: 0.5 means all blacklisted people will be able to post an unban after half of their blacklist time has expired.',
            "optionscode"	=> "text",
            "value"			=> '0.5'
        ),

        "steam_activation_blacklistappeal_mincharacters" => array(
            "title"			=> "Minimal amount of characters",
            "description"	=> 'Fill in the minimal amount of characters a user should fill in when appealing their blacklist ban.',
            "optionscode"	=> "numeric",
            "value"			=> '175'
        ),

        "steam_activation_blacklistappeal_curl" => array(
            "title"			=> "Use CURL to load the blacklist API",
            "description"	=> "If you do not have CURL installed, or want to not use CURL for any other reason, you can switch this to NO.",
            "optionscode"	=> "yesno",
            "value"			=> "0"

        ),

        "steam_activation_blacklistappeal_displaydeniedgifs" => array(
            "title"			=> "Display the denied gifs",
            "description"	=> "Choose if the access-denied gifs should be added or not.",
            "optionscode"	=> "yesno",
            "value"			=> "1"

        ),

        "steam_activation_blacklistappeal_deniedgifs" => array(
            "title"			=> "Denied gifs",
            "description"	=> "A ;-separated list of all acess-denied gifs",
            "optionscode"	=> "textarea",
            "value"			=> "https://i.giphy.com/IcNPu8F7ZzGOA.gif;https://i.giphy.com/4fNppUBK8Pv0s.gif;https://i.giphy.com/11QzYvIrd3cZpe.gif;https://i.giphy.com/cTO73FiW8U2mA.gif;https://media.giphy.com/media/BeYK3BQtGUvfy/giphy.gif;https://i.giphy.com/AfA6DEFEG8AQU.gif;https://i.giphy.com/MRXfquA4FBg7C.gif;https://i.giphy.com/TDOhmYKZAKr7O.gif;https://i.giphy.com/5XowmFR2jIKJi.gif;https://i.giphy.com/ydJOf0YQaK4tW.gif;https://i.giphy.com/SVdfx9BxP6X6.gif;https://i.giphy.com/vbehqwvns4g5a.gif;https://i.giphy.com/l3V0px8dfZmmfwize.gif;https://media.giphy.com/media/3orifeSGD9fkfc9qLu/giphy.gif;https://media.giphy.com/media/3oriffWTYKfrAVSyk0/giphy.gif;https://media.giphy.com/media/xT5LMFzhBoTY1KI4Bq/giphy.gif;https://i.giphy.com/vcKsGN1BI26gU.gif"
        ),

        "steam_activation_blacklistappeal_enable_slack" => array(
            "title"         => "Send messages to Slack",
            "description"   => "Choose whether you want alerts in Slack when a user makes a new appeal.",
            "optionscode"   => "yesno",
            "value"         => "1"

        ),

        "steam_activation_blacklistappeal_slack_hook" => array(
            "title"         => "Slack Hook URL",
            "description"   => 'Insert a Slack hook url here if you want messages to Slack', 
            "optionscode"   => "text",
            "value"         => ""
        ),

        "steam_activation_blacklistappeal_debug" => array(
            "title"         => "Debug messages",
            "description"   => "Turn on debug messages.<br>I hope you do not need this.",
            "optionscode"   => "yesno",
            "value"        	=> "0"
        )
    );

    //Add all the settings to the database
    steam_activation_blacklistappeal_f_debug("Looping through every setting");
    $disporder = 1;
    foreach($settings_array as $name => $setting) {
        steam_activation_blacklistappeal_f_debug("Adding setting ".$name." to table:settings");
        $setting["name"] = $name;
        $setting["gid"] = $gid;
        $setting["disporder"] = $disporder;
        $db->insert_query("settings", $setting);
        $disporder++;
    }

    //Update the settings file
    steam_activation_blacklistappeal_f_debug("Updating the settings pages");
    rebuild_settings();
}

//Uninstall procedure for plugin
function steam_activation_blacklistappeal_uninstall() {
    steam_activation_blacklistappeal_f_debug("Primitive function: uninstall()");
    global $db;

    $db->drop_table("steam_activation_blacklistappeal");

    //Clean up settings
    steam_activation_blacklistappeal_f_debug("Deleting the settings of this plugin from tables:settings&settinggroups");
    $db->delete_query("settings", "name LIKE ('steam_activation_blacklistappeal_%')");
    $db->delete_query("settinggroups", "name='steam_activation_blacklistappeal'");

    steam_activation_blacklistappeal_f_debug("Updating the settings pages");
    rebuild_settings();
}

//Activation procedure for plugin
function steam_activation_blacklistappeal_activate() {
    steam_activation_blacklistappeal_f_debug("Primitive function: activate()");
}

//Deactivation procedure for plugin
function steam_activation_blacklistappeal_deactivate() {
    steam_activation_blacklistappeal_f_debug("Primitive function: deactivate()");
}

/********* Hooks *********************/

//Global_start hook
function steam_activation_blacklistappeal_global_start() {
    steam_activation_blacklistappeal_f_debug("Hook: Global_start()");
    global $mybb;

    //Check if debug values are on
    if($mybb->settings["steam_activation_blacklistappeal_debug"]) {
        error_reporting(E_ALL); ini_set('display_errors', 'On');
    }

    steam_activation_blacklistappeal_f_run();
}

/****** Plugin specific functions *********/

//Main function that does all the shit
function steam_activation_blacklistappeal_f_run(){
    steam_activation_blacklistappeal_f_debug("Funcion: run()");
    steam_activation_blacklistappeal_f_debug("<b>When any page requested:</b>");

    global $mybb;

    if(isset($mybb->input["action"]) && $mybb->input["action"] === "login") {
        steam_activation_blacklistappeal_f_debug("GET action=login, I'm out!");
        return;
    }

    steam_activation_blacklistappeal_f_debug("<b>If get request for appeal set:</b>");
    if(isset($mybb->input["appeal"])){
        steam_activation_blacklistappeal_f_debug("<b>Yes->Check if logged in and set up:</b>");
        if(isset($mybb->user["steam_activation_steamid"])) {
            steam_activation_blacklistappeal_f_debug("<b>Yes-> check if banned:</b>");
            $steamid = $mybb->user["steam_activation_steamid"];
            if (steam_activation_blacklistappeal_f_checkbanned($steamid)) {
                steam_activation_blacklistappeal_f_debug("<b>Yes-> Check if running appeal:</b>");
                $blacklistid = steam_activation_blacklistappeal_f_get_blacklistid($steamid);
                if (steam_activation_blacklistappeal_f_checkrunningappeal($blacklistid)) {
                    steam_activation_blacklistappeal_f_debug("<b>Yes-> Redirect to the appeal</b>");
                    steam_activation_blacklistappeal_f_redirect($mybb->settings["bburl"]."/showthread.php?tid=".steam_activation_blacklistappeal_f_get_appealinfo_latest($blacklistid)["threadid"]);
                } else {
                    steam_activation_blacklistappeal_f_debug("<b>No-> Check time banned for long enough (through both the plugin-db and the blacklisted time):</b>");
                    if (steam_activation_blacklistappeal_f_checkappealtime($blacklistid, $steamid)) {
                        steam_activation_blacklistappeal_f_debug("<b>Yes-> Check if POST post-text values are present:</b>");
                        if (isset($mybb->input["post"])) {
                            steam_activation_blacklistappeal_f_debug("<b>Yes-> Post unban appeal and redirect user to it</b>");
                            steam_activation_blacklistappeal_f_postappeal($steamid, $blacklistid);
                        } else {
                            steam_activation_blacklistappeal_f_debug("<b>No-> Show unban appeal page</b>");
                            steam_activation_blacklistappeal_f_appealpage($steamid, $blacklistid);
                        }
                    } else {
                        steam_activation_blacklistappeal_f_debug("<b>No-> Tell them to wait</b>");
                        steam_activation_blacklistappeal_f_showpage("You are not allowed to post an unban appeal yet. <br/>You can post an unban appeal after ".date("F j, Y, g:i a", steam_activation_blacklistappeal_f_get_appealtime($blacklistid, $steamid)).".");
                    }
                }
            } else {
                steam_activation_blacklistappeal_f_debug("<b>No-> Tell that they request an unban but are not banned with link to site (aka remove appeal get var)</b>");
                steam_activation_blacklistappeal_f_showerrorpage("You requested to post a blacklist unban appeal, but you are not blacklisted.<br>Click <a href='".$mybb->settings['bburl']."'>here</a> to get back to the forums.");
            }
        } else {
            steam_activation_blacklistappeal_f_debug("<b>No-> Tell to log in with link to login page while remaining the appeal get var</b>");
            steam_activation_blacklistappeal_f_showpage("You first need to login to a forum account. You can do so <a href='".$mybb->settings['bburl']."/member.php?action=login&appeal"."'>here</a>");
        }
    } else {
        steam_activation_blacklistappeal_f_debug("<b>No-> Check if request for handleappeal is set as well as a valid blacklistID:</b>");
        if (isset($mybb->input["handleappeal"]) && isset($mybb->input["blacklistid"]) && is_numeric($mybb->input["blacklistid"])) {
            $blacklistid = $mybb->input["blacklistid"];
            steam_activation_blacklistappeal_f_debug("<b>Yes-> Check if user actually has an appeal running:</b>");
            if (steam_activation_blacklistappeal_f_checkrunningappeal($blacklistid)) {
                steam_activation_blacklistappeal_f_debug("<b>Yes-> Check if user is logged in:</b>");
                if (isset($mybb->user["steam_activation_steamid"])) {
                    $adminid = $mybb->user["steam_activation_steamid"];
                    steam_activation_blacklistappeal_f_debug("<b>Yes-> Check if one of the given admins:</b>");
                    if (steam_activation_blacklistappeal_f_checkisadmin($adminid)) {
                        steam_activation_blacklistappeal_f_debug("<b>Yes-> Check if action GET is set:</b>");
                        if (isset($mybb->input["action"])) {
                            steam_activation_blacklistappeal_f_debug("<b>Yes-> action is unban:</b>");
                            if ($mybb->input["action"] === "unban") {
                                steam_activation_blacklistappeal_f_debug("<b>Yes-> Unban (disable DB entry and add [Accepted] to title)</b>");
                                steam_activation_blacklistappeal_f_unban($blacklistid, $adminid);
                            } else if($mybb->input["action"] === "keepbanned") {
                                steam_activation_blacklistappeal_f_debug("<b>No-> Try to safely get time values:</b>");
                                if (isset($mybb->input["months"]) && isset($mybb->input["days"]) && is_numeric($mybb->input["months"]) && is_numeric($mybb->input["days"])) {
                                    steam_activation_blacklistappeal_f_debug("<b>Success-> Keep banned (put time in DB entry, disable DB entry</b>");
                                    steam_activation_blacklistappeal_f_debug("<b>,add [Declined] to title) and tell admin that success</b>");
                                    steam_activation_blacklistappeal_f_keepbanned($blacklistid, $adminid);
                                } else {
                                    steam_activation_blacklistappeal_f_debug("<b>Fail-> Tell that it failed</b>");
                                    steam_activation_blacklistappeal_f_showpage("Error: The months and days values could not correctly be gotten. Go back and try again.");
                                }
                            }
                        } else {
                            steam_activation_blacklistappeal_f_debug("<b>No-> Show handle appeal page</b>");
                            steam_activation_blacklistappeal_f_handleappealpage($blacklistid);
                        }
                    } else {
                        steam_activation_blacklistappeal_f_debug("<b>No-> Tell to STFU since they are not admin. Nice try</b>");
                        steam_activation_blacklistappeal_f_accessdeny();
                    }
                } else {
                    steam_activation_blacklistappeal_f_debug("<b>No-> Tell to log in with link to the site remaining the handleappeal get var</b>");
                    steam_activation_blacklistappeal_f_showpage("You first need to login to a forum account. You can do so <a href='".$mybb->settings['bburl']."/member.php?action=login&appeal"."'>here</a>");
                }
            } else {
                steam_activation_blacklistappeal_f_debug("<b>No-> Tell that this appeal has already been dealt with.</b>");
                steam_activation_blacklistappeal_f_showpage("This appeal has already been dealt with.");
            }
        }
    }
}

//Check if a user is currently blacklisted
function steam_activation_blacklistappeal_f_checkbanned($steamid){
    $blacklistinfo = steam_activation_blacklistappeal_f_get_blacklistinfo($steamid);
    return !empty($blacklistinfo);
}

//Check if a user currently has a running blacklist appeal
function steam_activation_blacklistappeal_f_checkrunningappeal($blacklistid){
    $appealinfo = steam_activation_blacklistappeal_f_get_appealinfo_latest($blacklistid);
    return isset($appealinfo) && $appealinfo["active"] === "1";
}

//Unban procedure
function steam_activation_blacklistappeal_f_unban($blacklistid, $adminid){
    global $mybb, $db;

    $appealinfo = steam_activation_blacklistappeal_f_get_appealinfo_latest($blacklistid);

    //Add [Accepted] and unsticky the post
    steam_activation_blacklistappeal_f_handlepost($appealinfo["postid"], $appealinfo["threadid"], "Accepted");

    //Deactivate the appeal
    $newappealinfo = array(
        "active" => "0",
        "nextappeal" => $appealinfo["time"],
        "processedby" => $adminid
    );
    $db->update_query("steam_activation_blacklistappeal", $newappealinfo, "appealid=".$appealinfo["appealid"]);

    steam_activation_blacklistappeal_f_showpage("The appeal #".$appealinfo["appealid"]." of steam id <a href='http://steamcommunity.com/profiles/".$appealinfo["steamid"]."'>".$appealinfo["steamid"]."</a> has succesfully been Accepted.<br/><br/> Click <a href='".$mybb->settings["bburl"]."/showthread.php?tid=".$appealinfo["threadid"]."'>here</a> to return to the thread,<br/> or click <a href='https://clwo.eu/jailbreak/admin/blacklist.php'>here</a> to go to the blacklist manage page.");
}

//Check if the user is allowed to make a new appeal
function steam_activation_blacklistappeal_f_checkappealtime($blacklistid, $steamid){
    return time() > steam_activation_blacklistappeal_f_get_appealtime($blacklistid, $steamid);
}

//Get the time at which a user is allowed to post an appeal
function steam_activation_blacklistappeal_f_get_appealtime($blacklistid, $steamid){
    global $mybb;

    $appealinfo = steam_activation_blacklistappeal_f_get_appealinfo_latest($blacklistid);

    if(isset($appealinfo)){
        //User has had appeal before
        steam_activation_blacklistappeal_f_debug("This user has appealed before");
        return $appealinfo["nextappeal"];
    } else {
        steam_activation_blacklistappeal_f_debug("This user has not appealed before");
        $blacklistinfo = steam_activation_blacklistappeal_f_get_blacklistinfo($steamid);
        $start = strtotime($blacklistinfo["BanDateTime"]);
        $maxtime = $start + $mybb->settings["steam_activation_blacklistappeal_bantime"];
        if(!$blacklistinfo["Perm"]) {
            steam_activation_blacklistappeal_f_debug("Not a permaban");
            $end = strtotime($blacklistinfo["Expires"]);
            $length = $end - $start;
            return min($maxtime,$start + $length * $mybb->settings["steam_activation_blacklistappeal_banratio"]);
        } else {
            steam_activation_blacklistappeal_f_debug("Permaban");
            return $maxtime;
        }
    }
}

//Check if the user is an admin allowed to accept and decline blacklist appeals
function steam_activation_blacklistappeal_f_checkisadmin($steamid){
    global $mybb;

    $admins = explode(";",$mybb->settings["steam_activation_blacklistappeal_admins"]);
    foreach($admins as $admin){
        if($steamid == intval($admin)){
            return true;
        }
    }
    return false;
}

//Show the appeal page
function steam_activation_blacklistappeal_f_appealpage($steamid, $blacklistid){
    global $mybb;

    $pagetext = "";
    $pagetext .= "Congratulations, at this point in time you are allowed to post an unban request.<br/>
        Please fill in the underneath form. Make sure to include in your post what you think you were banned for and why you should get an unban.<br/>
        Admins will then look at your appeal and come to a decision. This might take a couple of days.<br/>
        When you press POST, your blacklist unban appeal will be automatically posted to the forums.<br/>
        <br/>
        You can put [b] and [/b] around text that you want to appear bold. Other Mybb tags also work.<br/>
        <br/>
        <b>The following information will automatically be sent along with your unban appeal:</b><br/>";

    $userinfo = steam_activation_blacklistappeal_f_get_userinfo($steamid, $blacklistid);
    $pagetext .= "<div align='center'><style>th {text-align: right;}</style><table>";
    foreach ($userinfo as $item => $value){
        $value = str_replace("[url=", "<a href='", $value);
        $value = str_replace("[/url]", "</a>", $value);
        $value = str_replace("]", "'>", $value);
        $pagetext .= "<tr><th>".$item.":</th><td>".$value."</td></tr>";
    }
    $pagetext .= "</table></div><br/>";

    $pagetext .= "
        <br/>
        <form action='?appeal' method='post'>
            <textarea name='post' rows='15' cols='150' minlength='".$mybb->settings["steam_activation_blacklistappeal_mincharacters"]."' required></textarea><br/>
            <br/>
            <input type='submit' value='POST'/> 
        </form>";

   steam_activation_blacklistappeal_f_showpage($pagetext);
}

//Show the access deny page
function steam_activation_blacklistappeal_f_accessdeny(){
    global $mybb;

    $page = "Yeah nice try. You are not an admin that is allowed to accept or decline blacklist appeals.<br/>";
    if($mybb->settings["steam_activation_blacklistappeal_displaydeniedgifs"]){
        $gifs = explode(";", $mybb->settings["steam_activation_blacklistappeal_deniedgifs"]);
        $gif = $gifs[array_rand($gifs)];
        $page .= "<img src='".$gif."'/>";
    }

    steam_activation_blacklistappeal_f_showpage($page);
}

//Show the handle appeal page
function steam_activation_blacklistappeal_f_handleappealpage($blacklistid){
    $appealinfo = steam_activation_blacklistappeal_f_get_appealinfo_latest($blacklistid);
    $page = "
        Welcome to the unban page. You are about to deny the blacklist appeal of <a href='http://steamcommunity.com/profiles/".$appealinfo["steamid"]."'>".$appealinfo["steamid"]."</a>.<br/>
        Choose the minimal amount of time the user needs to wait before appealing again.<br/>
        Fill in two zero's to make it permanent if you really want to get rid of the fucker.<br/>
        <br/>
        <form action='?handleappeal&blacklistid=".$blacklistid."&action=keepbanned' method='post'>
            Months: <input type='number' name='months' min='0' required /><br/> 
            Days: <input type='number' name='days' min='0' required /><br/>
            <input type='submit' value='Send' /><br/>
        </form>
        ";
    steam_activation_blacklistappeal_f_showpage($page);
}

//Post unban appeal and redirect user to it
function steam_activation_blacklistappeal_f_postappeal($steamid, $blacklistid){
    global $mybb, $db;

    $time = time();
    $userinfo = steam_activation_blacklistappeal_f_get_userinfo($steamid, $blacklistid);

    $message = "[size=x-large]Info[/size]\n";
    foreach ($userinfo as $item => $value){
        $message .= "[b]".$item.": [/b]".$value."\n";
    }
    $message .= "\n";
    $message .= "[size=x-large]Unban appeal[/size]\n";
    $message .= $db->escape_string($mybb->input["post"])."\n";
    $message .= "\n";
    $message .= "[size=x-large]Accept / Decline[/size]\n";
    $message .= "Admins can use the following links to [url=".$mybb->settings["bburl"]."?handleappeal&blacklistid=".$blacklistid."&action=unban]accept[/url] or [url=".$mybb->settings["bburl"]."?handleappeal&blacklistid=".$blacklistid."]decline[/url] this blacklist appeal.\n";
    //$message .= "[url=".$mybb->settings["bburl"]."?handleappeal&blacklistid=".$blacklistid."&action=unban][img]http://imgur.com/OO8dvyK.png[/img][/url]\n";
    //$message .= "[url=".$mybb->settings["bburl"]."?handleappeal&blacklistid=".$blacklistid."][img]http://imgur.com/V3Zcjzl.png[/img][/url]\n";


    $fid = $mybb->settings["steam_activation_blacklistappeal_forum"];
    $uid = $mybb->user["uid"];
    $username = $mybb->user["username"];
    $subject = "Blacklist appeal of ".$username;
    $dateline = $time;
    $includesig = "0";

    //Make post
    $postinfo = array(
        "fid" => $fid,
        "uid" => $uid,
        "subject" => $subject,
        "dateline" => $dateline,
        "message" => $message,
        "includesig" => $includesig,
        "visible" => "1"
    );
    $pid = $db->insert_query("posts", $postinfo);

    //Make thread with link to post
    $threadinfo = array(
        "fid" => $fid,
        "subject" => $subject,
        "uid" => $uid,
        "username" => $username,
        "dateline" => $time,
        "firstpost" => $pid,
        "lastpost" => $time,
        "lastposter" => $username,
        "lastposteruid" => $uid,
        "sticky" => "1",
        "notes" => "",
        "visible" => "1"
    );
    $tid = $db->insert_query("threads", $threadinfo);

    //Add to post a link to thread
    $db->update_query("posts", array("tid"=>$tid), "pid=".$pid);

    //Update the forum info
    $currentforuminfo = $db->fetch_array($db->simple_select("forums", "fid", $fid));
    $updatedforuminfo = array(
        "threads" => $currentforuminfo["threads"]+1,
        "posts" => $currentforuminfo["posts"]+1,
        "lastpost" => $time,
        "lastposter" => $username,
        "lastposteruid" => $uid,
        "lastposttid" => $tid,
        "lastpostsubject" => $subject
    );
    $db->update_query("forums", $updatedforuminfo, 'fid='.$fid);

    steam_activation_blacklistappeal_f_debug("Pid: ".$pid.", Tid: ".$tid);
    steam_activation_blacklistappeal_f_debug("Thread and all stuff should be made right now");

    //Add stuff to the blacklistappeal DB Table
    $appealinfo = array(
        "blacklistid" => $blacklistid,
        "active" => "1",
        "steamid" => $steamid,
        "time" => $time,
        "threadid" => $tid,
        "postid" => $pid
    );
    $db->insert_query("steam_activation_blacklistappeal", $appealinfo);

    //Send hook to Slack
    if ($mybb->settings["steam_activation_blacklistappeal_enable_slack"]) {
        steam_activation_blacklistappeal_f_slack($tid, $username);
    }

    steam_activation_blacklistappeal_f_redirect($mybb->settings["bburl"]."/showthread.php?tid=".$tid);
}

//Send a message in Slack
function steam_activation_blacklistappeal_f_slack($tid, $username){
    global $mybb;

    $service_url = $mybb->settings["steam_activation_blacklistappeal_slack_hook"];

    $data = array("text" => $username ." made a new blacklist appeal https://clwo.eu/thread-".$tid.".html ");
    $data_string = json_encode($data);

    $curl = curl_init($service_url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string))
    );
    curl_setopt($curl, CURLOPT_TIMEOUT, 5);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);

    //execute post
    $result = curl_exec($curl);
    //close connection
    curl_close($curl);

}


//Procedure to keep someone banned
function steam_activation_blacklistappeal_f_keepbanned($blacklistid, $adminid){
    global $mybb, $db;

    //Get the appeal info
    $appealinfo = steam_activation_blacklistappeal_f_get_appealinfo_latest($blacklistid);

    //Add [Declined] and unsticky the post
    steam_activation_blacklistappeal_f_handlepost($appealinfo["postid"], $appealinfo["threadid"],"Declined");

    //Calculate when the next appeal may be made
    $months = $mybb->input["months"];
    $days = $mybb->input["days"];
    if($months === "0" && $days === "0"){
        steam_activation_blacklistappeal_f_debug("Detected months and days 0, so perma. Taking year 2065");
        $nextappeal = 3000000000;
    } else {
        steam_activation_blacklistappeal_f_debug("Not permanent. Processing that shit");
        $secsperday = 86400;
        $dayspermonth = 30;
        $totalsecs = ($dayspermonth * $months + $days) * $secsperday;
        steam_activation_f_debug("Next appeal in: " . $months . " months, " . $days . " days after the previous, which makes for " . $totalsecs . " seconds.");
        $nextappeal = $appealinfo["time"]+$totalsecs;
    }

    //Deactivate the appeal
    $newappealinfo = array(
        "active" => "0",
        "nextappeal" => $nextappeal,
        "processedby" => $adminid
    );
    $db->update_query("steam_activation_blacklistappeal", $newappealinfo, "appealid=".$appealinfo["appealid"]);

    steam_activation_blacklistappeal_f_showpage("The appeal #".$appealinfo["appealid"]." of steam id <a href='http://steamcommunity.com/profiles/".$appealinfo["steamid"]."'>".$appealinfo["steamid"]."</a> has succesfully been Denied.<br/>He can post an unban appeal again on ".date("F j, Y, g:i a",$nextappeal).".<br/><br/> Click <a href='".$mybb->settings["bburl"]."/showthread.php?tid=".$appealinfo["threadid"]."'>here</a> to return to the thread.");
}

//Add a tag to the post and unsticky the thread
function steam_activation_blacklistappeal_f_handlepost($pid, $tid, $text){
    global $mybb, $db;

    //Update the title of the post and thread and unsticky the thread
    $postinfo = $db->fetch_array($db->simple_select("posts", "subject", "pid=".$pid));
    $subject = $postinfo["subject"];
    $subject = "[".$text."] ".$subject;
    $db->update_query("posts", array("subject"=>$subject), "pid=".$pid);
    $db->update_query("threads", array("subject"=>$subject, "sticky"=>"0"), "tid=".$tid);

    //Check if forum also needs title update, if so do so
    $foruminfo = $db->fetch_array($db->simple_select("forums", "*", "fid=".$mybb->settings["steam_activation_blacklistappeal_forum"]));
    if($foruminfo["lastposttid"] === $tid){
        $db->update_query("forums", array("lastpostsubject"=>$subject), "fid=".$mybb->settings['steam_activation_blacklistappeal_forum']);
    }
}

//Get blacklist info for a steamid
function steam_activation_blacklistappeal_f_get_blacklistinfo($steamid){
    global $mybb, $blacklistinfo, $blacklististeamid;

    if(isset($blacklistinfo) && $blacklististeamid === $steamid){
        steam_activation_blacklistappeal_f_debug("Blacklist data already pulled, returning it.");
        return $blacklistinfo;
    } else {

        $apilink = "https://clwo.eu/jailbreak/api/v2/blacklist.php?cSteamID64=" . $steamid;

        if ($mybb->settings["steam_activation_blacklistappeal_curl"]) {
            steam_activation_blacklistappeal_f_debug("Using CURL to get blacklist info");
            $curler = curl_init();
            curl_setopt($curler, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curler, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curler, CURLOPT_URL, $apilink);
            $content = curl_exec($curler);
            curl_close($curler);
        } else {
            steam_activation_blacklistappeal_f_debug("NOT using CURL to get blacklist info, but doing it the non-curl way");
            $content = file_get_contents($apilink);
        }

        $blacklistinfo = json_decode($content, true);

        if ($blacklistinfo["status"] == 200) {
            //API Online
            steam_activation_blacklistappeal_f_debug("Blacklist API online");

            steam_activation_blacklistappeal_f_debug("Results: " . $blacklistinfo["results"]);

            if ($blacklistinfo["results"] > 0) {
                steam_activation_blacklistappeal_f_debug("Blacklisted");
                $blacklistinfo = reset($blacklistinfo["data"]);
            } else {
                //Not blacklisted
                steam_activation_blacklistappeal_f_debug("Not blacklisted");
                //Returning empty array since no problem found
                $blacklistinfo = array();
            }
            $blacklististeamid = $steamid;
            return $blacklistinfo;
        } else {
            echo("<span style='color: red;'><b>Error: The blacklist api is offline</b></span>");
            steam_activation_blacklistappeal_f_debug("<b>BLACKLIST-API OFFLINE!!!</b>");
            return array();
        }
    }
}

//Get all relevant info for a user
function steam_activation_blacklistappeal_f_get_userinfo($steamid, $blacklistid){
    global $mybb, $db;

    $blacklistinfo = steam_activation_blacklistappeal_f_get_blacklistinfo($steamid);
    $appealinfo = steam_activation_blacklistappeal_f_get_appealinfo($blacklistid);
    $amountofappeals = steam_activation_blacklistappeal_f_get_appeal_amount($blacklistid);
    $appealinfo_total = steam_activation_blacklistappeal_f_get_appeal_total($steamid);
    $amountofappeals_total = $db->num_rows($appealinfo_total);


    if($blacklistinfo["Perm"] === "1"){
        $blacklistinfo["Expires"] = "never";
    }


    $info = array(
        "Forum id" => "[url=".$mybb->settings["bburl"]."/member.php?action=profile&uid=".$mybb->user["uid"]."]".$mybb->user["uid"]."[/url]",
        "Steam id" => "[url=http://steamcommunity.com/profiles/".$steamid."]".$steamid."[/url]",
        "Account id" => $blacklistinfo["AccountID"],
        "Blacklist id" => "[url=https://clwo.eu/jailbreak/admin/view-blacklist.php?AccountID=".$blacklistinfo["AccountID"]."]".$blacklistid."[/url]",
        "Sourcebans" => "[url=http://clwo.eu/sourcebans/index.php?p=banlist&searchText=".$blacklistinfo["cSteamID2"]."]link[/url]",
        "Profile" => "[url=https://clwo.eu/jailbreak/profile.php?AccountID=".$blacklistinfo["AccountID"]."]link[/url]",
        "Banned on" => $blacklistinfo["BanDateTime"],
        "Expires" => $blacklistinfo["Expires"],
        "Ban reason" => $blacklistinfo["Reason"]
    );

    if($amountofappeals_total == 0){
        $info["Previous appeals"] = "none";
    } else {
        if($amountofappeals == 0){
            $info["Appeals for this blacklist"] = "none";
        } else {
            for($i=$amountofappeals; $i>0; $i--){
                $thisinfo = $db->fetch_array($appealinfo);
                $info["Appeal for this blacklist #".$i] = "[url=".$mybb->settings["bburl"]."/showthread.php?tid=".$thisinfo["threadid"]."]link[/url]";
            }
        }
        for($i=$amountofappeals_total; $i>0; $i--){
            $thisinfo = $db->fetch_array($appealinfo_total);
            if($thisinfo["blacklistid"] !== $blacklistid){
                $info["Other appeal #".$i] = "[url=".$mybb->settings["bburl"]."/showthread.php?tid=".$thisinfo["threadid"]."]link[/url]";
            }
        }
    }

    return $info;
}

//Get appeal info for a blacklistid
function steam_activation_blacklistappeal_f_get_appealinfo($blacklistid){
    global $db;

    return $db->simple_select("steam_activation_blacklistappeal", "*", "blacklistid=".$blacklistid, array("order_by"=>"appealid", "order_dir"=>"DESC"));
}

//Get appeal info for only the latest appeal for a blacklistid
function steam_activation_blacklistappeal_f_get_appealinfo_latest($blacklistid){
    global $db;
    return $db->fetch_array(steam_activation_blacklistappeal_f_get_appealinfo($blacklistid));
}

//Get the amount of appeals for a steamid
function steam_activation_blacklistappeal_f_get_appeal_total($steamid){
    global $db;
    return $db->simple_select("steam_activation_blacklistappeal", "*", "steamid=".$steamid, array("order_by"=>"appealid", "order_dir"=>"DESC"));
}

//Get the amont of appeals for blacklistid
function steam_activation_blacklistappeal_f_get_appeal_amount($blacklistappeal){
    global $db;

    return $db->num_rows(steam_activation_blacklistappeal_f_get_appealinfo($blacklistappeal));
}


//Get the blacklist id of a steamid
function steam_activation_blacklistappeal_f_get_blacklistid($steamid){
    $blacklistinfo = steam_activation_blacklistappeal_f_get_blacklistinfo($steamid);
    return $blacklistinfo["BlacklistID"];
}

//Show a page with an error
function steam_activation_blacklistappeal_f_showerrorpage($error){
    steam_activation_blacklistappeal_f_showpage($error);
}

//Show a page
function steam_activation_blacklistappeal_f_showpage($pagetext){
    ?>
        <html>
        <body style="text-align: center">
            <h1>Blacklist appeal page</h1>
            <br/>
            <?php echo($pagetext); ?><br/>
        </body>
        </html>
    <?php
    steam_activation_blacklistappeal_f_die();
}


/** Other **/

//Handle debug messages
function steam_activation_blacklistappeal_f_debug($message) {
    global $mybb;

    if(isset($mybb->settings["steam_activation_blacklistappeal_debug"])) {
        if ($mybb->settings["steam_activation_blacklistappeal_debug"]) {
            echo("<b>[Appeals][" . time()%100 . "]</b> " . $message . "<br>");
        }
    }
}

//Redirect the user to a page
function steam_activation_blacklistappeal_f_redirect($url){
    header("Location: ".$url);
    echo("You are being redirected to <a href='".$url."'>".$url."</a>. If it does not load within a few seconds, please click the link.");
    steam_activation_blacklistappeal_f_die();
}

//Kill the remainder of the page
function steam_activation_blacklistappeal_f_die() {
    steam_activation_blacklistappeal_f_debug("Function: die()");

    steam_activation_blacklistappeal_f_debug("I am dying, most likely to prevent the usual mybb page from showing");
    die;
}

?>
