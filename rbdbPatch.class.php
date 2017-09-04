<?php

class RBDB_Patch {

	public $VALIDATIONS = [];
	public $RESTRICTIONS = [];
	public $PATCHES = [];
	public $PREPATCHES = [];
	public $POSTPATCHES = [];
	public $SOURCE = [];

	// ---------------------------------------------------------------------------------
	// basic construction --------------------------------------------------------------
	function __construct($patches = [], $source = []) {
		$this->setPatches($patches);
		$this->setSource($source);
	}

	public function setSource($source = '{}') {
		if (is_string($source)) {
			$source = json_decode($source);
			$this->SOURCE = $source;
		}
		return $this;
	}

	public function setPatches($patches = '{}') {
		if (is_string($patches)) {
			$patches = json_decode($patches);
			$this->PATCHES = $patches;
		}
		return $this;
	}

	// --------------------------------------------------------------------------------
	// restrictions and validations ---------------------------------------------------

	// restrict read/write/delete access on specified paths
	public function restrict($pattern = '//', $disallowed = '') {
		// $pattern: regular expression string to match a path
		// $disallowed: permissions string: r = read, w = write, d = delete (remove)
		// NOTE: restricting the removal of a path, doesn't restrict removing a parent of that path
		$this->RESTRICTIONS[] = (object)[
			'pattern' => $pattern,
			'ops' => str_split($disallowed)
		];
	}

	// set validators for values on specified paths
	public function validate($pattern = '//', $validator = '', $options = []) {
		/**
		 * Validators are custom functions run against target values.
		 *
		 * The function will be passed the value to test as the first argument.
		 * The second argument passed will be any options set at the time of instantiation.
		 *
		 * The function should return `true` if validation passes,
		 * or a string error message if validation fails.
		 *
		 * $pattern: regular expression string to match a path
		 * $validator: a function reference to run validation... if not a function, validation will fail
		 * $options: any options to send to the validator function

		 */
		$this->VALIDATIONS[] = (object)[
			'pattern' => $pattern,
			'validator' => $validator,
			'options' => $options
		];
	}

	// set pre-patch function to run prior to applying patch
	public function prepatch($pattern = '//', $func = '', $options = []) {
		/**
		 * prepatches are custom functions that run prior to each patch
		 *
		 * $pattern: regular expression string to match a path
		 * $validator: a function reference to run validation... if not a function, validation will fail
		 * $options: any options to send to the validator function

		 */
		$this->PREPATCHES[] = (object)[
			'pattern' => $pattern,
			'func' => $func,
			'options' => $options
		];
	}

	// set post-patch function to run after applying patch
	public function postpatch($pattern = '//', $func = '', $options = []) {
		/**
		 * postpatches are custom functions that run after each patch
		 *
		 * $pattern: regular expression string to match a path
		 * $validator: a function reference to run validation... if not a function, validation will fail
		 * $options: any options to send to the validator function

		 */
		$this->POSTPATCHES[] = (object)[
			'pattern' => $pattern,
			'func' => $func,
			'options' => $options
		];
	}
	// --------------------------------------------------------------------------------
	// package up a results object ----------------------------------------------------

	private function package($code = 200, $message = '', $results = null) {
		$output = (object)['code' => $code];
		if ($code >= 400) { $output->message = $message; }
		if ($code <  400) { $output->results = $results ?? $this->SOURCE; }
		return $output;
	}

	// --------------------------------------------------------------------------------
	// process patches ----------------------------------------------------------------

	public function process() {
		// patches has to be an array
		if (!is_array($this->PATCHES)) {
			return $this->package(400, 'Malformed patches array');
		}

		// there has to be at least one patch
		if (count($this->PATCHES)<1) {
			return $this->package(400, 'No patches found');
		}

		// loop over patches
		foreach ($this->PATCHES as $index => $patch) {

			// preflight this patch... checks for restrictions, validations, format, etc
			$pre = $this->preflight($index, $patch);
			if ($pre->code >= 400) {
				return $pre;
			}

			// run any prepatches
			foreach ($this->PREPATCHES as $item) {
				if (preg_match($item->pattern, $patch->path)) {
					$prepatch = false;
					if (function_exists($item->func)) {
						$prepatch = call_user_func($item->func, $patch, $this->SOURCE);

						// function should return an array of patches (can be empty)
						foreach ($prepatch as $pp) {

							// run the prepatch
							$result = $this->runPatch($index, $pp);

							// test for failure
							if ($result->code >= 400) {
								return $result;
							}
						}

					// prepatch function was requested but doesn't exist
					} else {
						return $this->package(500, 'Server error pre-patch');
					}
				}
			}

			// run the main patch
			$result = $this->runPatch($index, $patch);

			// test for failure
			if ($result->code >= 400) {
				return $result;
			}

			// run any postpatches
			foreach ($this->POSTPATCHES as $item) {
				if (preg_match($item->pattern, $patch->path)) {
					$postpatch = false;
					if (function_exists($item->func)) {
						$postpatch = call_user_func($item->func, $patch, $this->SOURCE);

						// function should return an array of patches (can be empty)
						foreach ($postpatch as $pp) {

							// run the postpatch
							$result = $this->runPatch($index, $pp);

							// test for failure
							if ($result->code >= 400) {
								return $result;
							}
						}

					// postpatch function was requested but doesn't exist
					} else {
						return $this->package(500, 'Server error post-patch');
					}
				}
			}

		}
		return $this->package(204);
	}


	// --------------------------------------------------------------------------------
	// runPatch:  ------------------

	private function runPatch($parent = 0, $patch = []) {
		$mssgPrefix = 'Patch '.$parent.': ';

		// run the patch operations
		switch ($patch->op) {
			case 'add':
			case 'replace':
			case 'insert':
			case 'remove':
				$result = $this->traverse($patch->path, $this->SOURCE, [
					'mode'   => $patch->op ?? 'test',
					'value'  => $patch->value ?? null,
					'fill'   => $patch->fill ?? false,
					'silent' => $patch->silent ?? false
				]);

				// test for failure
				if ($result->code >= 400) {
					return $this->package($result->code, $mssgPrefix.'['.$patch->path.'] '.$result->message);
				}
				break;

			case 'copy':
			case 'move':
				// get source value
				$retrieved = $this->traverse($patch->from, $this->SOURCE, ['mode'=>'read']);

				// test for failure
				if ($retrieved->code >= 400) {
					return $this->package($retrieved->code, $mssgPrefix.'['.$patch->from.'] '.$retrieved->message);
				}

				// if moving...
				if ($patch->op==='move') {
					// remove source
					$removed = $this->traverse($patch->from, $this->SOURCE, ['mode'=>'remove']);

					// test for failure
					if ($removed->code >= 400) {
						return $this->package($removed->code, $mssgPrefix.'['.$patch->from.'] '.$removed->message);
					}
				}

				// set default mode
				$mode = $patch->mode ?? 'add';

				// write source to target
				$result = $this->traverse($patch->path, $this->SOURCE, [
					'mode'   => $mode,
					'value'  => $retrieved['value'],
					'fill'   => $fill,
					'silent' => $patch->silent
				]);

				// test for failure
				if ($result->code >= 400) {
					return $this->package($result->code, $mssgPrefix.'['.$patch->path.'] '.$result->message);
				}
				break;

			case 'test':
				// strict mode?
				$strict = $patch->strict ?? true;
				$strict = $strict ? true : false;

				// get source value
				$retrieved = $this->traverse($patch->path, $this->SOURCE, ['mode'=>'read']);

				// test for failure
				if ($retrieved->code >= 400) {
					return $this->package($retrieved->code, $mssgPrefix.'['.$patch->path.'] '.$retrieved->message);
				}

				// compare
				if ($strict) {
					if ($retrieved->results !== $patch->value) {
						return $this->package(424, 'Patch '.$index.': failed test [strict]');
					}
				} else {
					if ($retrieved->results != $patch->value) {
						return $this->package(424, 'Patch '.$index.': failed test [non-strict]');
					}
				}
				break;
		}

		return $this->package();
	}

	// --------------------------------------------------------------------------------
	// preflight: check patch format, resitrictions, and validations ------------------

	private function preflight($parent = 0, $patch) {
		$mssgPrefix = 'Patch '.$parent.': ';

		// make sure path object is an object
		if (!is_object($patch)) { return $this->package(400, $mssgPrefix.'Malformed patch object'); }

		// make sure that an operation is set
		if (!isset($patch->op)) { return $this->package(400, $mssgPrefix.'Missing required property [op]'); }

		// double check escape character
		// dangling slashes (including '/'), should be disallowed
		// array reference check. colons in path must be followed by an integer with no leading zero or a single dash
		$escapeCheck = '/~[^0-2]/';
		$objectCheck = '/((^(?!\/|\:).+)|\/$)/';
		$arrayCheck = '/\:(?!(([0-9]{1}|[1-9]{1}[0-9]+)|(\-{1}|\*{1}|\+{1}))(\:|\/|$))/';

		// verify path property
		if (!isset($patch->path)) { return $this->package(400, $mssgPrefix.'Missing required property [path]'); }
		if (preg_match($escapeCheck, $patch->path)) { return $this->package(400, $mssgPrefix.'Invalid path ['.$patch->path.']'); }
		if (preg_match($arrayCheck,  $patch->path)) { return $this->package(400, $mssgPrefix.'Invalid path ['.$patch->path.']'); }
		if (preg_match($objectCheck, $patch->path)) { return $this->package(400, $mssgPrefix.'Invalid path ['.$patch->path.']'); }

		// test required properties per operation
		switch ($patch->op) {
			case 'add':
			case 'replace':
			case 'insert':
			case 'test':
				if (!isset($patch->value)) { return $this->package(400, $mssgPrefix.'Missing required property [value]'); }
				break;
			case 'copy':
			case 'move':
				if (!isset($patch->from)) { return $this->package(400, $mssgPrefix.'Missing required property [from]'); }
				if (preg_match($escapeCheck, $patch->from)) { return $this->package(400, $mssgPrefix.'Invalid from ['.$patch->from.']'); }
				if (preg_match($arrayCheck,  $patch->from)) { return $this->package(400, $mssgPrefix.'Invalid from ['.$patch->from.']'); }
				if (preg_match($objectCheck, $patch->from)) { return $this->package(400, $mssgPrefix.'Invalid from ['.$patch->from.']'); }
				break;
			case 'remove':
				break;
			default:
				return $this->package(422, $mssgPrefix.'Unsupported operation');
		}

		// check path restrictions
		foreach ($this->RESTRICTIONS as $r) {

			// Apply restrictions to path variable
			if (isset($patch->path)) {
				if ( preg_match($r->pattern, $patch->path) ) {
					switch ($patch->op) {
						case 'add':
						case 'copy':
						case 'move':
						case 'replace':
							if (in_array('w', $r->ops)) { return $this->package(403, $mssgPrefix.'Writing to ['.$patch->path.'] not allowed'); }
							break;
						case 'remove':
							if (in_array('d', $r->ops)) { return $this->package(403, $mssgPrefix.'Removing ['.$patch->path.'] not allowed'); }
							break;
						case 'test':
							if (in_array('r', $r->ops)) { return $this->package(403, $mssgPrefix.'Reading ['.$patch->path.'] not allowed'); }
							break;
					}
				}
			}

			// Apply restrictions to 'from' variable
			if (isset($patch->from)) {
				if ( preg_match($r->pattern, $patch->from) ) {
					switch ($patch->op) {
						case 'move':
							if (in_array('d', $r->ops)) { return $this->package(403, $mssgPrefix.'Removing ['.$patch->from.'] not allowed'); }
							// don't break... move needs both checks
						case 'copy':
							if (in_array('r', $r->ops)) { return $this->package(403, $mssgPrefix.'Reading ['.$patch->from.'] not allowed'); }
							break;
					}
				}
			}
		}

		// validation checks
		foreach ($this->VALIDATIONS as $v) {
			if (isset($patch->path)) {
				if ( preg_match($v->pattern, $patch->path) ) {

					// make sure validator function has been set
					if (!function_exists($v->validator)) {
						return $this->package(422, $mssgPrefix.'['.$patch->from.'] validator error');
					}

					// run validators
					$validated = true;
					switch ($patch->op) {
						case 'add':
						case 'replace':
							// validate supplied value
							$validated = call_user_func($v->validator, $patch->value, $v->options);
							break;

						case 'copy':
						case 'move':
							// read source value
							$retrieved = $this->traverse($patch->from, $this->SOURCE, ['mode'=>'read']);

							// couldn't retrieve source value
							if ($retrieved->code >= 400) {
								return $this->package($retrieved->code, $mssgPrefix.'['.$patch->from.'] '.$retrieved->message);
							}

							// validate source value
							$validated = call_user_func($v->validator, $retrieved->results, $v->options);
							break;
					}

					// validation passed
					if ($validated===true) {
						// keep on truckin'!

					// validation didn't pass
					} else if (gettype($validated)==='string') {
						return $this->package(403, $mssgPrefix.'['.$patch->path.'] failed validation: '.$validated);

					// validator returned unexpected data
					} else {
						return $this->package(422, $mssgPrefix.'['.$patch->from.'] validator error');
					}
				}
			}
		}

		// for 'replace', or 'test' make sure target value exists
		if (($patch->op==='replace' && (!$patch->silent ?? false)) || $patch->op==='test') {
			$target = $this->traverse($patch->path, $this->SOURCE, ['mode'=>'read']);

			// coudn't retrieve source value
			if ($target->code >= 400) {
				return $this->package($target->code, $mssgPrefix.'['.$patch->path.'] '.$target->message);
			}
		}

		// create recursive "sub patches" for objects/arrays to preflight
		// (to keep people from bypassing restrictions and validations)
		// this will also verify that the source value for a copy or move is present
		// NOTE: restricting the removal of a path, doesn't restrict removing a parent of that path
		switch ($patch->op) {
			case 'add':
			case 'replace':
				// set test value
				$testValue = $patch->value;
				break;

			case 'copy':
			case 'move':
				// read source value
				$retrieved = $this->traverse($patch->from, $this->SOURCE, ['mode'=>'read']);

				// couldn't retrieve source value
				if ($retrieved->code >= 400) {
					return $this->package($retrieved->code, $mssgPrefix.'['.$patch->from.'] '.$retrieved->message);
				}

				// set test value
				$testValue = $retrieved->results;
				break;

			default:
				$testValue = false;
		}

		// recursively perform sub-patch tests on arrays and objects
		// because the object/array is part of the "single" value being set
		// the patch operation here should _always_ be: add
		// NOTE: these sub patches will NOT be run, only preflighted
		if (is_object($testValue) || is_array($testValue)) {
			$separator = is_array($testValue) ? ':' : '/';

			// loop over indexes/keys... keep track of child number $i
			foreach ($testValue as $newKey => $newValue) {
				// create sub patch
				$newPatch = (object)[
					'op'=>'add',
					'path'=> $patch->path.$separator.$newKey,
					'value'=> $newValue
				];

				// run sub patch
				$pre = $this->preflight($parent.$separator.$newKey, $newPatch);

				// sub patch failed
				if ($pre->code >= 400) { return $pre; }
			}
		}

		// passed all tests
		return $this->package();
	}

	// --------------------------------------------------------------------------------
	// traverse: read/write/delete to/from object -------------------------------------

	public function traverse($path = '', &$SOURCE = [], $options = []) {
		/**
		 * NOTE: paths should have already been checked for proper
		 * structure outside of this function, thus this function will
		 * not do any path error checking.
		 *
		 * NOTE: traversal expects source to be mixed objects & arrays,
		 * i.e., json_decode(string, FALSE);
		 *
		 * OPTIONS
		 * 'mode' => STR what mode to traverse in?
		 *    'read' => just return the value (won't back reference)
		 *        success => ['success'=>true, 'value'=>{read value}]
		 *        failure => ['success'=>false, 'message'=>'reason for failure']
		 *
		 *    'replace' => write the value to the path (fails if path doesn't exist)
		 *        'value' => ??? the value to write
		 *        'silent'=> T/F error if value exists?
		 *        success => ['success'=>true]
		 *        failure => ['success'=>false, 'message'=>'reason for failure']
		 *
		 *    'insert' => write the value to the path (fails if the path does exist)
		 *        'value' => ??? the value to write/create
		 *		  'silent'=> T/F error if value exists?
		 *        'fill'  => ??? a value to fill an array with up to the target index; default null
		 *        success => ['success'=>true]
		 *        failure => ['success'=>false, 'message'=>'reason for failure']
		 *
		 *    'add' => write the value to the path (create the path it doesn't exist)
		 *        'value' => ??? the value to write/create
		 *        'fill'  => ??? a value to fill an array with up to the target index; default null
		 *        success => ['success'=>true]
		 *        failure => ['success'=>false, 'message'=>'reason for failure']
		 *
		 *    'remove' => remove the value from the source object
		 *        success => ['success'=>true]
		 *        failure => ['success'=>false, 'message'=>'reason for failure']
		 *
		 */

		$mode = $options['mode'] ?? 'read';
		$value = $options['value'] ?? null;
		$fill = $options['fill'] ?? null;
		$silent = $options['silent'] ?? false;

		// test fill value
		if (!in_array(json_encode($fill), ['""','[]','{}','0','1','true','false','null'], true)) {
			return $this->package(422, 'invalid fill value');
		}

		// prep for traversal
		$src = &$SOURCE;
		$segments = preg_split('/([\/\:])/', $path, null, PREG_SPLIT_DELIM_CAPTURE);
		array_shift($segments); // <--- bleed off first empty value

		// loop over segements... limit the number of children possible
		$tries = 0;
		$limit = 100;
		while($tries<$limit && count($segments)>0) {

			// OBJECTS //////////////////////////////////////////////////////
			if (array_shift($segments)==='/') {

				// object not set
				if (!is_object($src)) {

					// path set to something other than an object
					if (isset($src)) {
						return $this->package(422, 'path mismatch');
					}

					// nothing set at all...
					switch ($mode) {
						case 'read':
							return $this->package(404, 'not found');

						case 'replace':
							if ($silent) {
								return $this->package();
							} else {
								return $this->package(404, 'not found');
							}

						case 'remove':
							return $this->package();

						case 'add':
						case 'insert':
							$src = (object)[];
							break;
					}
				}

				// get the object property name
				$next = str_replace(['~0', '~1', '~2'], ['~', '/', ':'], array_shift($segments));

				// object property not set
				if (!isset($src->$next)) {
					switch ($mode) {
						case 'read':
							return $this->package(404, 'not found');

						case 'replace':
							if ($silent) {
								return $this->package();
							} else {
								return $this->package(404, 'not found');
							}

						case 'remove':
							return $this->package();

						case 'add':
						case 'insert':
							// this is the final target for the value, set it and forget it!
							if (count($segments)<1) {
								$src->$next = $value;
								return $this->package();
							// create next segment in path
							} else {
								$src->$next = ($segments[0]===':') ? (array)[] : (object)[];
							}
							break;
					}

				// object property is set
				} else {

					// this is the ultimate target
					if (count($segments)<1) {
						switch ($mode) {
							case 'remove':
								unset($src->$next);
								return $this->package();

							// insert fails if ultimate target already exists
							case 'insert':
								if ($silent) {
									return $this->package();
								} else {
									return $this->package(422, 'already exists');
								}
						}
					}
				}

				// set to child for next loop
				$src = &$src->$next;


			// ARRAYS //////////////////////////////////////////////////////
			} else {
				// clear unshiftFlag for this loop
				$unshiftFlag = false;

				// array not set
				if (!is_array($src)) {

					// path set to something other than an array
					if (isset($src)) {
						return $this->package(422, 'path mismatch');
					}

					// nothing set at all...
					switch ($mode) {
						case 'read':
							return $this->package(404, 'not found');

						case 'replace':
							if ($silent) {
								return $this->package();
							} else {
								return $this->package(404, 'not found');
							}

						case 'remove':
							return $this->package();

						case 'add':
						case 'insert':
							$src = (array)[];
							break;
					}
				}

				// get the array index number
				$index = array_shift($segments);
				switch ($index) {
					// last item in an array ... all operations
					case '-':
						$index = count($src) - 1;
						break;

					// array push ... add/insert only
					case '+':
						if ($mode!=='add' && $mode!=='insert') {
							return $this->package(422, "invalid use of :+");
						}
						$index = count($src);
						break;

					// array unshift ... add/insert only
					case '*':
						if ($mode!=='add' && $mode!=='insert') {
							return $this->package(422, "invalid use of :*");
						}
						// add item to beginning of array now using fill
						// this might be overwritten, or it might not
						array_unshift($src, $fill);
						$index = 0;
						$unshiftFlag = true;

					default:
						(integer)$index;
				}



				// fill array up to specified index if necessary
				if (($mode==='add' || $mode==='insert') && $index!=='^') {
					if (count($src) < $index) {
						for ($i=count($src); $i<$index; $i++) {
					 		$src[$i] = $fill;
						}
					}
				}

				// this index is not set
				if (!isset($src[$index])) {
					switch ($mode) {
						case 'read':
							return $this->package(404, 'not found');

						case 'replace':
							if ($silent) {
								return $this->package();
							} else {
								return $this->package(404, 'not found');
							}

						case 'remove':
							return $this->package();

						case 'add':
						case 'insert':
							// this is the final target for the value, set it and forget it!
							if (count($segments)<1) {
								$src[$index] = $value;
								return $this->package();
							// create next segment in path
							} else {
								$src[$index] = ($segments[0]===':') ? (array)[] : (object)[];
							}
							break;
					}

				// array index is set
				} else {

					// this is the ultimate target
					if (count($segments)<1) {
						switch ($mode) {
							case 'remove':
								array_splice($src, $index, 1);
								return $this->package();

							// insert fails if ultimate target already exists
							case 'insert':
								if (!$unshiftFlag) {
									if ($silent) {
										return $this->package();
									} else {
										return $this->package(422, 'already exists');
									}
								}
						}
					}
				}

				// set to child for next loop
				$src = &$src[$index];

			}

			$tries++;
		}

		// TAKE APPROPRIATE ACTION AND RETURN
		switch ($mode) {
			case 'read':
				return $this->package(200, '', $src);

			case 'replace':
			case 'add':
			case 'insert':
				$src = $value;
				return $this->package(200, '', $src);
		}

		// something went terribly, horribly wrong
		return $this->package(400, "Unknown error");
	}

}

?>
