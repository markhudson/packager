<?php

require dirname(__FILE__) . "/helpers/yaml.php";
require dirname(__FILE__) . "/helpers/array.php";

Class Packager {
	
	public static function warn($message){
		$std_err = fopen('php://stderr', 'w');
		fwrite($std_err, $message);
		fclose($std_err);
	}

	private $packages = array();
	private $manifests = array();
	private $root = null;
	
	public function __construct($package_paths){
		foreach ((array)$package_paths as $package_path)
			$this->parse_manifest($package_path);
	}
	
	private function parse_manifest($package_path){
		$package_path = preg_replace('/\/$/', '', $package_path) . '/';
		$manifest = YAML::decode_file($package_path . 'package.yml');
		
		if (empty($manifest)) throw new Exception("package.yml not found in $package_path, or unable to parse manifest.");

		$package_name = $manifest['name'];
		
		if ($this->root == null) $this->root = $package_name;

		if (array_has($this->manifests, $package_name)) return;

		$manifest['path'] = $package_path;
		
		$this->manifests[$package_name] = $manifest;
		
		foreach ($manifest['sources'] as $i => $path){
			
			$path = $package_path . $path;
			
			// this is where we "hook" for possible other replacers.
			$source = $this->replace_build($package_path, file_get_contents($path));

			$descriptor = array();

			// get contents of first comment
			preg_match('/^\s*\/\*\s*(.*?)\s*\*\//s', $source, $matches);

			if (!empty($matches)){
				// get contents of YAML front matter
				preg_match('/^-{3}\s*$(.*?)^(?:-{3}|\.{3})\s*$/ms', $matches[1], $matches);

				if (!empty($matches)) $descriptor = YAML::decode($matches[1]);
			}

			// populate / convert to array requires and provides
			$requires = (array)(!empty($descriptor['requires']) ? $descriptor['requires'] : array());
			$provides = (array)(!empty($descriptor['provides']) ? $descriptor['provides'] : array());
			$file_name = !empty($descriptor['name']) ? $descriptor['name'] : basename($path, '.js');

			// "normalization" for requires. Fills up the default package name from requires, if not present.
			foreach ($requires as $i => $require)
				$requires[$i] = implode('/', $this->parse_name($package_name, $require));
			
			$license = array_get($descriptor, 'license');
			
			$this->packages[$package_name][$file_name] = array_merge($descriptor, array(
				'package' => $package_name,
				'requires' => $requires,
				'provides' => $provides,
				'source' => $source,
				'path' => $path,
				'package/name' => $package_name . '/' . $file_name,
				'license' => empty($license) ? array_get($manifest, 'license') : $license
			));

		}
	}
	
	public function add_package($package_path){
		$this->parse_manifest($package_path);
	}
	
	public function remove_package($package_name){
		unset($this->packages[$package_name]);
		unset($this->manifests[$package_name]);
	}
	
	// # private UTILITIES
	
	private function parse_name($default, $name){
		$exploded = explode('/', $name);
		$length = count($exploded);
		if ($length == 1) return array($default, $exploded[0]);
		if (empty($exploded[0])) return array($default, $exploded[1]);
		return array($exploded[0], $exploded[1]);
	}
	
	private function replace_build($package_path, $file){
		$ref = @file_get_contents($package_path . '.git/HEAD');
		if (empty($ref)) return $file;
		
		preg_match("@ref: ([\w\.-/]+)@", $ref, $matches);
		$ref = file_get_contents($package_path . ".git/" . $matches[1]);
		preg_match("@([\w\.-/]+)@", $ref, $matches);
		return str_replace("%build%", $matches[1], $file);
	}
	
	// # private HASHES
	
	private function component_to_hash($name){
		$pair = $this->parse_name($this->root, $name);
		$package = array_get($this->packages, $pair[0]);

		if (!empty($package)){
			$component = $pair[1];

			foreach ($package as $file => $data){
				foreach ($data['provides'] as $c){
					if ($c == $component) return $data;
				}
			}
		}
		
		return null;
	}
	
	private function file_to_hash($name){
		$pair = $this->parse_name($this->root, $name);
		$package = array_get($this->packages, $pair[0]);

		if (!empty($package)){
			$file_name = $pair[1];

			foreach ($package as $file => $data){
				if ($file == $file_name) return $data;
			}
		}
		
		return null;
	}
	
	public function file_exists($name){
		return $this->file_to_hash($name) ? true : false;
	}
	
	public function component_exists($name){
		return $this->component_to_hash($name) ? true : false;
	}
	
	public function validate(){
		$warnings = array();
		foreach ($this->packages as $name => $files){
			foreach ($files as $file){
				$file_requires = $file['requires'];
				foreach ($file_requires as $component){
					if (!$this->component_exists($component)){
						$warnings[] = array('component' => $component, 'file' => $file['package/name']);
						self::warn("WARNING: The component $component, required by " . $file['package/name'] . ", has not been provided." . "\n");
					}
				}
			}
		}
		
		return $warnings;
	}
	
	// # public BUILD
	
	public function build_from_files($files = null){
		if (empty($files)) return null;
		$included_files = ($files == '*') ? $this->get_all_files() : $this->complete_files($files);
		
		$included_sources = array();
		foreach ($included_files as $file) $included_sources[] = $this->get_file_source($file);
		
		return implode($included_sources, "\n\n");
	}
	
	public function build_from_components($components = null){
		return $this->build_from_files($this->components_to_files($components));
	}

	public function write_from_files($file_name, $files = null){
		$full = $this->build_from_files($files);
		file_put_contents($file_name, $full);
	}

	public function write_from_components($file_name, $components = null){
		return $this->write_from_files($file_name, $this->components_to_files($components));
	}
	
	// # public FILES

	public function get_all_files($of_package = null){
		$files = array();
		foreach ($this->packages as $name => $package){
			if ($of_package == null || $of_package == $name) foreach ($package as $file){
				$files[] = $file['package/name'];
			}
		}
		return $this->complete_files($files);
	}
	
	public function get_file_dependancies($file){
		$hash = $this->file_to_hash($file);
		if (empty($hash)) return array();
		return $this->components_to_files($hash['requires']);
	}
	
	public function complete_file($file){
		$files = $this->get_file_dependancies($file);
		$hash = $this->file_to_hash($file);
		if (empty($hash)) return array();
		array_include($files, $hash['package/name']);
		return $files;
	}
	
	public function complete_files($files){
		$ordered_files = array();
		foreach ($files as $file){
			$all_files = $this->complete_file($file);
			foreach ($all_files as $one_file) array_include($ordered_files, $one_file);
		}
		return $ordered_files;
	}
	
	// # public COMPONENTS
	
	public function component_to_file($component){
		return array_get($this->component_to_hash($component), 'package/name');
	}
	
	public function components_to_files($components){
		$files = array();
		foreach ($components as $component){
			$file_name = $this->component_to_file($component);
			if (!empty($file_name) && !in_array($file_name, $files)) $files[] = $file_name;
		}
		return $this->complete_files($files);
	}
	
	// # dynamic getter for PACKAGE properties and FILE properties
	
	public function __call($method, $arguments){
		if (strpos($method, 'get_file_') === 0){
			$file = array_get($arguments, 0);
			if (empty($file)) return null;
			$key = substr($method, 9);
			$hash = $this->file_to_hash($file);
			return array_get($hash, $key);
		}
		
		if (strpos($method, 'get_package_') === 0){
			$key = substr($method, 12);
			$package = array_get($arguments, 0);
			$package = array_get($this->manifests, (empty($package)) ? $this->root : $package);
			return array_get($package, $key);
		}
		
		return null;
	}
	
	public function get_packages(){
		return array_keys($this->packages);
	}
	
	// authors normalization
	
	public function get_package_authors($package = null){
		if (empty($package)) $package = $this->root;
		$package = array_get($this->manifests, $package);
		if (empty($package)) return array();
		return $this->normalize_authors(array_get($package, 'authors'), array_get($package, 'author'));
	}
	
	public function get_file_authors($file){
		$hash = $this->file_to_hash($file);
		if (empty($hash)) return array();
		return $this->normalize_authors(array_get($hash, 'authors'), array_get($hash, 'author'), $this->get_package_authors());
	}
	
	private function normalize_authors($authors = null, $author = null, $default = null){
		$use = empty($authors) ? $author : $authors;
		if (empty($use) && !empty($default)) return $default;
		if (is_array($use)) return $use;
		if (empty($use)) return array();
		return array($use);
	}
	
}
