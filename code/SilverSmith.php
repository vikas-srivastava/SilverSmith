<?php


class SilverSmith {

    protected static $cli = false;


	protected static $project_dir = null;


	protected static $field_manifest = array ();


	protected static $node_manifest = array ();


	protected static $interface_manifest = array ();


	protected static $script_dir = null;


    protected static $yaml_path = null;



	// public static function set_project_dir($dir) {
	// 	self::$project_dir = $dir;
	// }


	// public static function set_script_dir($dir) {
	// 	self::$script_dir = $dir;
	// }


 //    public static function get_script_dir() {
 //        return self::$script_dir;
 //    }


 //    public static function get_project_dir() {
 //        return self::$project_dir;
 //    }


 //    public static function set_cli($bool = true) {
 //        self::$cli = true;
 //    }


 //    public static function is_cli() {
 //        return self::$cli;
 //    }




	protected static function get_subclasses($parentClassName) {
	    $classes = array();
	    foreach (get_declared_classes() as $className) {
	        if (is_subclass_of($className, $parentClassName))
	            $classes[] = $className;
	    }
	    return $classes;
	}
	


	public static function get_silversmith_version() {
    	return trim(@file_get_contents(self::$script_dir . "/../../version"));
	}


	public static function is_upgrade_available() {
    	$url    = "http://www.silversmithproject.com/check_version.php?v=" . self::get_silversmith_version();
    	$result = @file_get_contents($url);
    	return ($result == "upgrade");
	}


	public static function load_field_manifest() {
    	foreach (glob(self::$script_dir . '/code/lib/fields/*.yml') as $file) {
	        $yml = new BedrockYAML($file);
	        self::$field_manifest[basename($file, ".yml")] = $yml;
	        if ($yml->getAliases()) {
	            foreach ($yml->getAliases() as $alias) {
	                self::$field_manifest[(string) $alias] = $yml;
	            }
	        }
    	}
    	foreach (scandir(self::$script_dir . '/plugins') as $dir) {
        	if ($dir == "." || $dir == "..")
            	continue;
	        if (is_dir($dir)) {
	            foreach (glob(self::$script_dir . "/plugins/$dir/*.yml") as $file) {
	                $yml = new BedrockYAML($file);
	                if ($yml->getPluginType() == "field") {
	                    self::$field_manifest[basename($file, ".yml")] = $yml;
	                    if ($yml->getAliases()) {
	                        foreach ($yml->getAliases() as $alias) {
	                            self::$field_manifest[$alias] = $yml;
	                        }
	                    }
	                }
	            }
	        }
    	}
	}

	public static function load_class_manifest() {
	    foreach (SilverSmithProject::get_all_nodes() as $config) {
	        self::$node_manifest[$config->getKey()] = $config;
	    }
	}



	public static function load_interface_manifest()
	{
	    foreach (glob(self::$script_dir . '/code/lib/interfaces/*.yml') as $file) {
	        $yml = new BedrockYAML($file);
	        self::$interface_manifest[basename($file, ".yml")] = $yml;
	    }
	    foreach (scandir(self::$script_dir . '/plugins') as $dir) {
	        if ($dir == "." || $dir == "..")
	            continue;
	        if (is_dir($dir)) {
	            foreach (glob(self::$script_dir . "/plugins/$dir/*.yml") as $file) {
	                $yml = new BedrockYAML($file);
	                if ($yml->getPluginType() == "interface") {
	                    self::$interface_manifest[basename($file, ".yml")] = $yml;
	                }
	            }
	        }
	    }
	}

	public static function switch_to_project_root() {
	    while (dirname(getcwd()) != "/") {
	        foreach (scandir(".") as $file) {
	            if ($file == ".." || $file == ".")
	                continue;
	            if (is_dir($file)) {
	                if (file_exists($file . "/cli-script.php")) {
	                    return true;
	                }
	            }
	        }
	        chdir(dirname(getcwd()));
	    }
	    return false;
	}

	public static function rebuild_manifest() {
	    // exec("rm -" . TEMP_FOLDER);
	    // exec("mkdir " . TEMP_FOLDER);
	}



	public static function rebuild_database() {
        SS_ClassLoader::instance()->getManifest()->regenerate();
        ob_start();
        $da = DatabaseAdmin::create();
        $da->handleRequest(new SS_HTTPRequest("POST","build",array('flush' => 'all')), DataModel::inst());
        $output = ob_get_contents();
        ob_end_clean();
        $database_result = array ();
        foreach(explode("\n",$output) as $line) {            
            if(preg_match('/Table ([A-Za-z0-9_:]+) created/', $line, $matches)) {
                $table_name = str_replace(":","",$matches[1]);
                if(substr($table_name, -9) == "_versions" || substr($table_name, -5) == "_Live") {
                    continue;
                }
                $database_result[$table_name] = array(
                    'created' => true,
                    'fields' => array()
                );
            }
            elseif(preg_match('/Field (.*) created as ([A-Za-z0-9_\(\)\"\',]+)/', $line, $matches)) {
                list($table_name, $field_name) = explode(".", $matches[1]);
                if(substr($table_name, -9) == "_versions" || substr($table_name, -5) == "_Live") {
                    continue;
                }
                $field_name = str_replace(":","", $field_name);
                $field_type = $matches[2];
                if(!isset($database_result[$table_name])) {
                    $database_result[$table_name] = array (
                        'created' => false,
                        'fields' => array()
                    );                    
                }
                $database_result[$table_name]['fields'][] = "$field_name ({$field_type})";
            }           

        }
        return $database_result;

	}



	public static function __callStatic($method, $args) { 
        $prefix = substr($method, 0, 4);
        $suffix = substr($method, 4);
        if($prefix == "set_") {
            return self::$$suffix = $args[0];
        }
        elseif($prefix == "get_") {
            return self::$$suffix;
        }

		$m = str_replace("-","_", $method);
		return call_user_func("SilverSmith::{$m}");
	}



    public static function build_code($params = array ()) {
        state("Validating project definition...");
        $validator = new SilverSmithSpec_Validator(self::$yaml_path);
        $errors    = $validator->getErrors();
        if (!empty($errors)) {
            say(error("Validation error!"));
            say("Please fix the following errors:");
            foreach ($errors as $e) {
                say(" -- " . $e);
            }
            say("Execution terminated by validation errors.");
            die();
        } else {
            state("OK\n");
        }
        $page_types         = 0;
        $page_types_created = 0;
        $page_types_updated = 0;
        $components         = 0;
        $components_created = 0;
        $components_updated = 0;
        $decorators         = array();
        line();
        say(cell("Status", 11, true, "grey", "on_white") . cell("File", 30, true, "grey", "on_white") . cell("Result", 50, true, "grey", "on_white"));
        foreach (SilverSmithProject::get_all_nodes() as $node) {
            if (!$node->isNew() && !$node->isSilverSmithed()) {
                say(cell("Omitted", 11, true, "white", "on_red") . cell("{$node->getKey()}.php", 30) . cell("Class has no code delimiters", 50));
                continue;
            }
            
            $class = $node->getKey();
            if ($node->getDecorator()) {
                $decorators[] = $node->getKey();
                $class .= "Decorator";
            }
            $type = $node->getContentType();
            if ($type == "PageType")
                $page_types++;
            elseif ($type == "Component")
                $components++;
            $new = $node->isNew();
            if (!$new) {
                $diff = $node->updateFile();
            } else {
                $node->createFile();
            }
            if ($new) {
                $new_fields     = 0;
                $new_components = 0;
                if ($node->getFields()) {
                    $new_fields = $node->getFields()->size();
                }
                if ($node->getComponents()) {
                    $new_components = $node->getComponents()->size();
                }
                say(cell("Created", 11, true, "white", "on_green") . cell("{$class}.php", 30) . cell("$new_fields fields and $new_components components.", 50));
                if ($type == "PageType")
                    $page_types_created++;
                elseif ($type == "Component")
                    $components_created++;
                
            } else {
                if (!$diff) {
                    say(cell("Unchanged", 11, true, "grey", "on_yellow") . cell("{$class}.php", 30) . cell("No modifications", 50));
                } else {
                    say(cell("Updated", 11, true, "white", "on_blue") . cell("{$class}.php", 30) . cell(sprintf("%d change(s), %d insertion(s), %d deletion(s)", $diff['changed'], $diff['added'], $diff['deleted']), 50));
                    if ($type == "PageType")
                        $page_types_updated++;
                    elseif ($type == "Component")
                        $components_updated++;
                    
                }
                
                
            }
            
        }
        if (!empty($decorators)) {
            line();
            $config = trim(file_get_contents(self::$project_dir."/_config.php"));
            if (substr($config, 0, -2) == "?>") {
                $config = substr_replace($config, "", 0, -2);
            }
            $added = 0;
            foreach ($decorators as $classname) {
                if (!Object::has_extension($classname, $classname . "Decorator")) {
                    say("Adding decorator to $classname", "green");
                    $config .= "\nObject::add_extension('$classname','{$classname}Decorator');";
                    $added++;
                }
            }
            if ($added > 0) {
                say("Added $added decorator declaration(s) to the _config.php file.", "green");
            }
            $fh = fopen(self::$project_dir."/_config.php", "w");
            fwrite($fh, $config);
            fclose($fh);
            
        }
        
        
        state("Rebuilding database...");
        $db = self::rebuild_database();
        say("done.");
        $tables_created = 0;
        $fields_created = 0;
        foreach($db as $table_name => $settings) {
            if($settings['created']) {
                $tables_created++;
            }
            $fields_created += sizeof($settings['fields']);
        }
        if($tables_created > 0) {
            say("$tables_created tables created","green","bold");
        }
        if($fields_created > 0) {
            say("$fields_created fields created","green","bold");
        }
        if(sizeof($db) > 0) {
            line();
        }
        foreach($db as $table_name => $settings) {
            if($settings['created']) {
                say($table_name, "green","bold");
            }
            else {
                say($table_name);
            }
            foreach($settings['fields'] as $field) {
                say("+ $field");
            }
        }
        state("Done");
        

        say("\n\n");
        say("Success! ", "green", "bold");
        say("\n\n");
        say(cell("", 50) . cell("Created", 10, true, "white", "on_green") . cell("Updated", 10, true, "white", "on_blue") . cell("Total", 10, true, "white", "on_red"));
        say(cell("Page types:", 50) . cell($page_types_created, 10) . cell($page_types_updated, 10) . cell($page_types, 10));
        
        
    }    
        
        
        
    
    
    
    
    
    
    
    
    
    
    public function build_templates($params = array ()) {
        $theme_dir = isset($params['theme']) ? "themes/" . $params['theme'] : false;
        $force     = isset($params['force']);
        $specificTemplates = isset($params['list']) ? explode(',',$params['list']) : false;
        if (!$theme_dir) {
            if (self::$project_dir == project()) {
                if (SSViewer::current_theme()) {
                    $theme_dir = "themes/" . SSViewer::current_theme();
                } else {
                    $theme_dir = project();
                }
            } else {
                $theme_dir = self::$project_dir;
            }
        }
        say("Using theme directory $theme_dir");
        if (!file_exists($theme_dir)) {
            fail("The theme directory $theme_dir does not exist");
        }
        if (!is_dir($theme_dir . "/templates"))
            mkdir($theme_dir . "/templates");
        $layout_dir = "$theme_dir/templates/Layout";
        if (!is_dir($layout_dir))
            mkdir($layout_dir);
        
        $source = "Page.ss";
        if (isset($params['source'])) {
            $source = $params['source'];
        }
        if (!file_exists("$layout_dir/$source")) {
            fail("Source template $layout_dir/$source does not exist.");
        }        
        if (!file_exists(self::$yaml_path)) {
            fail("File ".self::$yaml_path." does not exist.");
        }
        
        SilverSmithProject::load(self::$yaml_path);
        $created = 0;
        say(cell("Status", 11, true, "grey", "on_white") . cell("File", 30, true, "grey", "on_white") . cell("Result", 40, true, "grey", "on_white"));
        line();
        foreach (SilverSmithProject::get_page_types() as $node) {
            if ($node->getKey() == "SiteConfig")
                continue;
            if($specificTemplates && !in_array($node->getKey(),$specificTemplates)) {
                continue;
            }
            if (!file_exists("$layout_dir/{$node->getKey()}.ss") || $force) {
                $stock = file_get_contents("$layout_dir/$source");
                $fh    = fopen("$layout_dir/{$node->getKey()}.ss", "w");
                $created++;
                $notes = "Copied from $source";
                if (isset($params['autofill'])) {
                    if (!preg_match('/\$Content[^A-Za-z0-9_]/', $stock)) {
                        say(cell("Skipped", 2, "white", "on_red") . cell($node->getKey() . ".ss", 30) . cell("Varible \$Content is not in the template.", 40));
                        continue;
                    }
                    $notes .= " [Auto-filled]";
                    $template = new BedrockTemplate(file_get_contents(self::$script_dir . "/code/lib/structures/AutoFill.bedrock"));
                    $template->bind($node);
                    $autofill = $template->render();
                    $filled   = preg_replace('/\$Content([^A-Za-z0-9_])/', "\$Content\n\n{$autofill}\n\n$1", $stock);
                    fwrite($fh, $filled);
                } else {
                    fwrite($fh, $stock);
                }
                fclose($fh);
                say(cell("Created", 10, true, "white", "on_green") . cell($node->getKey() . ".ss", 30) . cell($notes, 40));
            } else {
                say(cell("Bypassed", 11, true, "grey", "on_yellow") . cell($node->getKey() . ".ss", 30) . cell("File exists. Use --force to override", 40));
            }
        }
        line();
        say("$created templates created.");
        
    }
    
    

    public static function add_sample_assets() {
        say("Adding sample assets");
        $sample_path = self::$script_dir . "/code/lib/sample-assets";
        $folder      = Folder::find_or_make("silversmith-samples");
        exec("cp {$sample_path}/*.* {$folder->getFullPath()}");
        say("Syncing database");
        $folder->syncChildren();
        say("Done.");
                
    }
    
    
    
    public static function seed_content($params = array ()) {
        global $database;
        if (!isset($params[2])) {
            fail("Usage: silversmith seed-content <class name>");
        }
        $className = $params[2];
        if (!class_exists($className)) {
            fail("Class $className does not exist!");
        }
        $parentField  = (!isset($params['parent-field'])) ? "ParentID" : $params['parent-field'];
        $parent       = (!isset($params['parent'])) ? false : $params['parent'];
        $count        = (!isset($params['count'])) ? 10 : (int) $params['count'];
        $seedingLevel = (!isset($params['seeding-level'])) ? 3 : $params['seeding-level'];
        $verbose        = isset($params['verbose']);
        $site_tree    = is_subclass_of($className, "SiteTree");
        if (!$site_tree && $seedingLevel < 2) {
            fail("For non SiteTree objects, a seeding level of at least 2 is required.");
        }
        if ($parent) {
            if (is_numeric($parent)) {
                $parentObj = DataList::create("SiteTree")->byId((int) $parent)->first();
                if (!$parentObj) {
                    fail("Page #{$parent} could not be found.");
                }
            } else {
                $parentObj = SiteTree::get_by_link($parent);
                if (!$parentObj) {
                    $parentObj = DataList::create("SiteTree")->where("Title = '" . trim($parent) . "'")->first();
                }
                if (!$parentObj) {
                    fail("Page '$parent' could not be found.");
                }
            }
        }
        $sample = Folder::find_or_make("silversmith-samples");
        if (!$sample->hasChildren()) {
            $answer = ask("This project does not have sample assets installed, which can be useful for content seeding. Do you want to install them now? (y/n)");
            if (strtolower(trim($answer)) == "y") {
                exec("silversmith add-sample-assets");
            }
        }
        for ($i = 0; $i < $count; $i++) {
            $p = new $className();
            if ($site_tree) {
                $p->Title   = SilverSmithUtil::get_lipsum_words(rand(2, 5));
                $p->Content = SilverSmithUtil::get_default_content($p->obj('Content'), $seedingLevel);
                state("New {$className} created...");
                $p->Status = "Published";
            }
            if ($parent) {
                $p->$parentField = $parentObj->ID;
            }
            
            state("Seeding...");
            
            $p->write();
            state("Adding content...");
            SilverSmithUtil::add_default_content($p, $seedingLevel);        
            $p->write();
            if ($site_tree) {
                $p->publish("Stage", "Live");
            }
            state("Done.\n");
            
            if ($verbose) {
                say("Debug output:");
                $fields = array_keys(DataObject::custom_database_fields($className));
                foreach(array_merge($p->has_many(), $p->many_many()) as $relation => $class) {
                    $fields[] = $relation;
                    if($p->$relation()->exists()) {
                        $p->$relation = implode(',',$p->$relation()->column('ID'));
                    }
                    else {
                        $p->$relation = "(none)";
                    }                    
                }
                if($site_tree) {
                    $fields = array_merge(array('Title'), $fields);
                }                
                foreach ($fields as $field) {
                    say("{$field}: {$p->$field}");
                }
            }
        }
        
        
        
    }
    
    
    
    
    
    
    public static function build_fixtures($params = array ()) {
        
        ClassInfo::reset_db_cache();
        $fixtures_file = isset($params['file']) ? $params['file'] : "{self::$project_dir}/_fixtures.txt";
        if (!file_exists($fixtures_file)) {
            fail("The file $fixtures_file doesn't exist.");
        }
        $code             = file_get_contents($fixtures_file);
        $architectureData = array();
        $lines            = explode("\n", $code);
        if (empty($lines)) {
            fail("The files $fixtures_file is empty.");
        }
        $sample = Folder::find_or_make("silversmith-samples");
        if (!$sample->hasChildren()) {
            $answer = ask("This project does not have sample assets installed, which can be useful for content seeding. Do you want to install them now? (y/n)");
            if (strtolower(trim($answer)) == "y") {
                exec("silversmith add-sample-assets");
            }
        }
        
        $answer = ask("This process will completely empty and repopulate your site tree. Are you sure you want to continue? (y/n)");
        if (strtolower($answer) != "y")
            die();
        say("Parsing architecture file...");
        foreach ($lines as $line) {
            if (empty($line))
                continue;
            $level = 0;
            $count = 1;
            $class = "Page";
            $title = $line;
            preg_match('/^[ ]+[^ ]/', $line, $matches);
            if ($matches) {
                $level = strlen(substr(reset($matches), 0, -1));
            }
            if (stristr($line, ">")) {
                list($title, $class) = explode(" > ", $line);
                $class = SilverSmithUtil::proper_form($class);
            }
            preg_match('/\*[0-9]+/', $title, $m);
            if ($m) {
                $match = reset($m);
                $count = (int) trim(str_replace("*", "", $match));
                $title = str_replace($match, "", $title);
            }
            $architectureData[] = array(
                'title' => trim($title),
                'level' => $level,
                'class' => trim($class),
                'count' => $count,
                'new' => !class_exists($class) || !in_array($class, ClassInfo::getValidSubclasses("SiteTree"))
            );
        }
        
        
        // Clean the slate
        say("Deleting current site tree");
        DB::query("DELETE FROM SiteTree");
        DB::query("DELETE FROM SiteTree_Live");
        DB::query("DELETE FROM SiteTree_versions");
        say("Done.");
        
        // Update the DB with any new page types
        $new = array();
        say("Checking architecture file for new page types...");
        foreach ($architectureData as $arr) {
            if ($arr['new']) {
                $new[] = $arr['class'];
                SilverSmithProject::get_configuration()->addNode($arr['class'], "PageTypes");
                SilverSmithProject::get_node($arr['class'])->createFile();
                say(success("Created " . $arr['class']));
            }
        }
        if (!empty($new)) {
            state("Rebuilding database to support " . sizeof($new) . " new page types...");
            $result = rebuildDatabase();
            rebuildManifest();
            state("Done\n");
        }
        
        
        
        $previousParentIDs = array(
            '0' => '0'
        );
        $previousLevel     = 0;
        $seeding           = isset($params['seeding-level']) ? $params['seeding-level'] : 1;
        $total             = 0;
        foreach ($architectureData as $arr) {
            $parentID     = 0;
            $currentLevel = $arr['level'];
            $title        = $arr['title'];
            $class        = $arr['class'];
            $count        = $arr['count'];
            $indent       = "";
            while (strlen($indent) < $currentLevel * 2)
                $indent .= " ";
            if ($currentLevel > 0) {
                $parentID = $previousParentIDs[$currentLevel - 2];
            }
            
            for ($i = 0; $i < $count; $i++) {
                $p = new $class();
                if (strtolower($title) == "_auto_") {
                    $p->Title = SilverSmithUtil::get_lipsum_words(rand(2, 5));
                } else {
                    $p->Title = $title;
                }
                state($indent . $p->Title, "green", "bold");
                state(" [{$class}] created...");
                if ($seeding > 0) {
                    state("Seeding...");
                    $p->Content = SilverSmithUtil::get_default_content($p->obj('Content'), $seeding);
                }
                $p->Status   = "Published";
                $p->ParentID = $parentID;
                $p->write();
                if ($seeding > 1) {
                    SilverSmithUtil::add_default_content($p, $seeding);
                }
                $p->write();
                $p->publish("Stage", "Live");
                state("Done.\n");
                $total++;
                $previousParentIDs[$currentLevel] = $p->ID;
            }
        }
        $errorPages = DataList::create("ErrorPage");
        if ($errorPages->count()) {
            state("Fixing error pages...");
            $max = DB::query("SELECT MAX(Sort) FROM SiteTree")->value();
            foreach ($errorPages as $e) {
                $max++;
                $e->Sort = $max;
                $e->write();
                $e->publish("Stage", "Live");
            }
            ("Done\n");
        }
        rebuildManifest();
        say(success("Success!"));
        state("Important", "red");
        state(": You must ");
        state("restart your browser", null, null, "bold");
        state(" to clear your session in order to view the architecture changes.\n");
        
    }
    
    
    public static function uninstall($params = array ()) {
        $response = (isset($params['force'])) ? "y" : ask("Are you sure you want to uninstall SilverSmith? (y/n)");
        if (strtolower($response) == "y") {
            exec("sudo rm -rf self::$script_dir");
            exec("sudo rm /usr/local/bin/silversmith3");
        }
    }
    
    
    
    public static function init($params = array ()) {  
        $no_assets = isset($params['no-assets']);
        if (!file_exists(self::$project_dir . "/_project.yml")) {
            if (isset($params['example'])) {
                $contents = file_get_contents(self::$script_dir . "/code/lib/_project.yml");
            } else {
                $contents = "PageTypes: {}\n\nComponents: {}\n";
            }
            $fh = fopen(self::$project_dir . "/_project.yml", "w");
            fwrite($fh, $contents);
            fclose($fh);
            say(success("Created _project.yml"));
        } else {
            say("File _project.yml already exists.");
        }
        
        if (!file_exists(self::$project_dir . "/_fixtures.txt")) {
            $fh = fopen(self::$project_dir . "/_fixtures.txt", "w");
            if (isset($params['example'])) {
                fwrite($fh, file_get_contents(self::$script_dir . "/code/lib/_fixtures.txt"));
            }
            fclose($fh);
            say(success("Created _fixtures.txt"));
        } else {
            say("File _fixtures.txt already exists.");
        }
        
        if (!$no_assets) {
            exec("silversmith add-sample-assets");
        }
    }
    


    public static function init_module($params = array ()) {
        if (!isset($params[2]) || empty($params[2])) {
            fail("Please specify a module name.");
        }
        $d = SilverSmithUtil::proper_form($params[2]);
        if (is_dir($d)) {
            fail("Module $d already exists.");
        }
        mkdir($d);
        say($d);
        mkdir("$d/code");
        say("$d/code");
        
        mkdir("$d/css");
        say("$d/css");
        
        mkdir("$d/javascript");
        say("$d/javascript");
        
        mkdir("$d/templates");
        say("$d/templates");
        
        mkdir("$d/templates/Layout");
        say("$d/templates/Layout");
        
        mkdir("$d/templates/Includes");
        say("$d/templates/Includes");
        
        $fh = fopen("$d/_config.php", "w");
        fwrite($fh, "<?php\n");
        fclose($fh);
        say("$d/_config.php");
    }
    
    
    public static function upgrade($params = array ()) {
        if (self::is_upgrade_available()) {
            $response = ask("An upgrade is available. Install now? (y/n)");
            if (strtolower($response) == "y") {
                exec("silversmith uninstall --force");
                exec("curl http://www.silversmithproject.com/install | sh");
            } else
                die();
        } else {
            say("SilverSmith is up to date.");
        }
    }

    
    public static function version() {
        say("Version ID: " . self::get_silversmith_version());
        die();
    }
    
    
    public static function spec($params = array ()) {
        self::load_interface_manifest();
        
        say("PageTypes:");
        say("  YourPageType:");
        say("    Fields:");
        say("      Tagline:");
        say("");
        $required = (array) SilverSmithSpec::get("Field.RequiredNodes");
        foreach (SilverSmithSpec::get("Field.BaseNodes") as $key => $node) {
            $r = in_array($key, $required) ? ", required" : "";
            $v = "";
            if ($vals = $node->getPossibleValues()) {
                $v = " (" . implode(',', $vals) . ")";
            }
            
            say("        ## {$node->getDescription()} [{$node->getDataType()}{$r}{$v}]");
            say("        {$key}: {$node->getExample()}");
            say("");
        }
        say("    ## Components related to and managed in the context of this page");
        say("    Components:");
        say("      Testimonial:");
        say("");
        $required = (array) SilverSmithSpec::get("Component.RequiredNodes");
        foreach (SilverSmithSpec::get("Component.AvailableNodes") as $key => $node) {
            if (in_array($key, array(
                'Fields',
                'Components',
                'Interface'
            )))
                continue;
            $r = in_array($key, $required) ? ", required" : "";
            $v = "";
            if ($vals = $node->getPossibleValues()) {
                $v = " (" . implode(',', $vals) . ")";
            }
            say("        ## {$node->getDescription()} [{$node->getDataType()}{$r}{$v}]");
            say("        {$key}: {$node->getExample()}");
            say("");
        }
        say("        ## Define the interface used to manage this component in the context of the page");
        say("        Interface:");
        say("");
        $required = (array) SilverSmithSpec::get("Interface.RequiredNodes");
        foreach (SilverSmithSpec::get("Interface.BaseNodes") as $key => $node) {
            $r = in_array($key, $required) ? ", required" : "";
            $v = "";
            if ($vals = $node->getPossibleValues()) {
                $v = " (" . implode(',', $vals) . ")";
            }
            say("          ## {$node->getDescription()} [{$node->getDataType()}{$r}{$v}]");
            say("          {$key}: {$node->getExample()}");
            say("");
        }
        say("        ## Component fields can be defined in the same block as its parent page");
        say("        Fields:");
        say("          Author:");
        say("");
        $required = (array) SilverSmithSpec::get("Field.RequiredNodes");
        foreach (SilverSmithSpec::get("Field.BaseNodes") as $key => $node) {
            if (in_array($key, array(
                'Tab',
                'Before'
            )))
                continue;
            $r = in_array($key, $required) ? ", required" : "";
            $v = "";
            if ($vals = $node->getPossibleValues()) {
                $v = " (" . implode(',', $vals) . ")";
            }
            
            say("            ## {$node->getDescription()} [{$node->getDataType()}{$r}{$v}]");
            say("            {$key}: {$node->getExample()}");
            say("");
        }
        $required = (array) SilverSmithSpec::get("PageType.RequiredNodes");
        foreach (SilverSmithSpec::get("PageType.AvailableNodes") as $key => $node) {
            if (in_array($key, array(
                'Fields',
                'Components'
            )))
                continue;
            $r = in_array($key, $required) ? ", required" : "";
            $v = "";
            if ($vals = $node->getPossibleValues()) {
                $v = " (" . implode(',', $vals) . ")";
            }
            say("    ## {$node->getDescription()} [{$node->getDataType()}{$r}{$v}]");
            say("    {$key}: {$node->getExample()}");
            say("");
        }
        
        say("  ## Standalone components that are not necessarily related to or managed on a specific page type.");
        say("Components:");
        say("  Client:");
        say("");
        $required = (array) SilverSmithSpec::get("Component.RequiredNodes");
        foreach (SilverSmithSpec::get("Component.AvailableNodes") as $key => $node) {
            if (in_array($key, array(
                'Fields',
                'Components',
                'Interface',
                'Type',
                'Tab'
            )))
                continue;
            $r = in_array($key, $required) ? ", required" : "";
            $v = "";
            if ($vals = $node->getPossibleValues()) {
                $v = " (" . implode(',', $vals) . ")";
            }
            say("    ## {$node->getDescription()} [{$node->getDataType()}{$r}{$v}]");
            say("    {$key}: {$node->getExample()}");
            say("");
        }
        
    }
    
    

    public static function help($params = array ()) {
        say(cell("Command", 20, true, "grey", "on_white") . cell("Description", 50, true, "grey", "on_white") . cell("Options", 50, true, "grey", "on_white"));
        foreach ($allowed_actions as $a) {
            $options = array();
            foreach ($a->getOptions() as $o) {
                $options[] = "[" . $o->get('arg') . "] ";
                foreach ($o->get('description') as $line) {
                    $options[] = $line;
                }
                $options[] = "";
            }
            array_pop($options);
            $descriptions = $a->getDescription();
            $source       = (sizeof($options) > sizeof($descriptions)) ? $options : $descriptions;
            
            for ($i = 0; $i < sizeof($source); $i++) {
                $cmd = ($i == 0) ? $a->getKey() : "";
                state(cell($cmd, 20, false));
                $desc = isset($descriptions[$i]) ? $descriptions[$i] : "";
                $opt  = isset($options[$i]) ? $options[$i] : "";
                state(cell($desc, 50, false));
                state(cell($opt, 50, false));
                state("\n");
            }
            say(cell("", 20) . cell("", 50) . cell("", 50));
        }
        
    }
        
        
}	












/**
 * Helper functions
 *-----------------------------------------------*/

function say($text, $foreground = null, $background = null, $style = null) {
    SilverSmithPrompt::say($text, $background, $foreground, $style);
}


function state($text, $foreground = null, $background = null, $style = null) {
    SilverSmithPrompt::write($text, $background, $foreground, $style);
}

function ask($msg) {
    say($msg);
    return trim(fgets(STDIN));
}


function fail($text, $foreground = "white", $background = "on_red", $style = null) {
    SilverSmithPrompt::say($text, $background, $foreground, $style);
    die();
}

function line() {
    say("----------------------------------------------------------------------------------------------");
}


function success($msg) {
    state(" $msg ", "white", "on_green");
}


function warn($msg) {
    state(" $msg ", "grey", "on_yellow");
}


function info($msg) {
    state(" $msg ", "white", "on_blue");
}


function error($msg) {
    state(" $msg ", "white", "on_red");
}


function cell($text, $width = 30, $underline = true, $foreground = null, $background = null) {
    ob_start();
    if ($width < strlen($text)) {
        $text = substr($text, 0, $width - 3);
    }
    $cell = " {$text}";
    while (strlen($cell) < $width)
        $cell .= " ";
    $u = $underline ? "underline" : null;
    state($cell, $foreground, $background, $u);
    state("|");
    
    $ret = ob_get_contents();
    ob_end_clean();
    return $ret;
    
}
