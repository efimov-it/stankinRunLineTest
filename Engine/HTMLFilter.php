<?php
	class HTMLFilter{
		
		private $tags = [];
		private $classes = [];
		private $del_tags = true;

		public function __construct() {
			// $json_file = json_decode(file_get_contents('tags.json'));
			$json_file = json_decode(file_get_contents(_SYSTEM_CORE_ROOT_ . DIRECTORY_SEPARATOR . 'tags.json'));

			$attributes_false = $json_file->attributes;
			$attributes_false[] = false;
				
			$whitelist = [
				"div" => $json_file->attributes, 
				"img" => $attributes_false, 
				"h1" => $json_file->attributes, 
				"h2" => $json_file->attributes, 
				"h3" => $json_file->attributes, 
				"h4" => $json_file->attributes, 
				"h5" => $json_file->attributes, 
				"h6" => $json_file->attributes, 
				"span" => $json_file->attributes, 
				"a" => $json_file->attributes, 
				"p" => $json_file->attributes, 
				"button" => $json_file->attributes, 
				"ul" => $json_file->attributes, 
				"li" => $json_file->attributes, 
				"ol" => $json_file->attributes, 
				"dl" => $json_file->attributes, 
				"dd" => $json_file->attributes, 
				"dt" => $json_file->attributes, 
				"pre" => $json_file->attributes, 
				"code" => $json_file->attributes, 
				"var" => $json_file->attributes, 
				"kbd" => $json_file->attributes, 
				"samp" => $json_file->attributes, 
				"caption" => $json_file->attributes, 
				"small" => $json_file->attributes, 
				"del" => $json_file->attributes, 
				"s" => $json_file->attributes, 
				"mark" => $json_file->attributes, 
				"ins" => $json_file->attributes, 
				"u" => $json_file->attributes, 
				"strong" => $json_file->attributes, 
				"em" => $json_file->attributes, 
				"blockquote" => $json_file->attributes, 
				"footer" => $json_file->attributes, 
				"table" => $json_file->attributes, 
				"form" => $json_file->attributes, 
				"legend" => $json_file->attributes, 
				"fieldset" => $json_file->attributes, 
				"input" => $attributes_false, 
				"textarea" => $json_file->attributes, 
				"label" => $json_file->attributes, 
				"select" => $json_file->attributes, 
				"option" => $json_file->attributes, 
				"address" => $json_file->attributes, 
				"br" => $attributes_false, 
				"abbr" => $json_file->attributes, 
				"details" => $json_file->attributes, 
				"summary" => $json_file->attributes, 
				"figure" => $json_file->attributes, 
				"picture" => $json_file->attributes, 
				"source" => $json_file->attributes, 
				"thead" => $json_file->attributes, 
				"tr" => $json_file->attributes, 
				"tbody" => $json_file->attributes, 
				"td" => $json_file->attributes, 
				"th" => $json_file->attributes, 
				"figcaption" => $json_file->attributes, 
				"hr" => $json_file->attributes, 
				"nav" => $json_file->attributes, 
				"i" => $json_file->attributes, 
				"video" => $json_file->attributes, 
				"audio" => $json_file->attributes
			];

			$this->classes = $json_file->classes;
			$this->set_tags($whitelist);
		}
		
		private function set_tags($arr=array()){
			if(!is_array($arr)) $arr=array();
			foreach($arr as $key=>$value){
				for($i=0; $i<count($value); $i++){
					$arr[$key][$i] = strtolower($arr[$key][$i]);
				}
			}
			$this->tags = array_change_key_case($arr);
		}
				
		public function filter($html_code){
			
			$open_tags_stack = array();
			$code = FALSE;
			$error = [];
		
			$seg = array();
			while(preg_match('/<[^<>]+>/siu', $html_code, $matches, PREG_OFFSET_CAPTURE)){
				if($matches[0][1]) $seg[] = array('seg_type'=>'text', 'value'=>substr($html_code, 0, $matches[0][1]));	
				$seg[] = array('seg_type'=>'tag', 'value'=>$matches[0][0], 'action'=>'');
				$html_code = substr($html_code, $matches[0][1]+strlen($matches[0][0]));
			}
			if($html_code != '') $seg[] = array('seg_type'=>'text', 'value'=>$html_code);
			
			for($i=0; $i<count($seg); $i++){
			
				if($seg[$i]['seg_type'] == 'text') {
					$seg[$i]['value'] = htmlentities($seg[$i]['value'], ENT_QUOTES, 'UTF-8');
					$seg[$i]['action'] = 'add';	
					
					if($seg[$i - 1]['seg_type'] == 'tag') {
						if(!array_key_exists($seg[$i - 1]['tag_name'], $this->tags)){
							if($this->del_tags) 
								$seg[$i]['action'] = 'del';
						}
						if($seg[$i - 1]['action'] == 'del' && $seg[$i - 1]['tag_name'] == 'text' && $seg[$i]['tag_name'] == 'text') {
							$seg[$i]['action'] = 'del';	
							$error[] = [
								'type' => $seg[$i]['seg_type'],
								'name' => $seg[$i]['tag_name'],
								'message' => 'Неверный тег',
							];
						}
					}
				}

			
				else if($seg[$i]['seg_type'] == 'tag'){
				
					preg_match('#^<\s*(/)?\s*([a-z0-9]+)(.*?)>$#siu', $seg[$i]['value'], $matches);
					$matches[1] ? $seg[$i]['tag_type']='close' : $seg[$i]['tag_type']='open';
					$seg[$i]['tag_name'] = strtolower($matches[2]);
					
					if($seg[$i]['tag_type'] == 'open') {
						
						if(!array_key_exists($seg[$i]['tag_name'], $this->tags)){
							if($this->del_tags) {
								$seg[$i]['action'] = 'del';
								$error[] = [
									'type' => $seg[$i]['seg_type'],
									'name' => $seg[$i]['tag_name'],
									'message' => 'Неверный тег',
								];
							}	
							else {$seg[$i]['seg_type'] = 'text';
								$i--;
								continue;
							}	
						}
					
						else {
							preg_match_all('#([a-z|-]+)\s*=\s*([\'\"])\s*(.*?)\s*\2#siu', $matches[3], $attr_m, PREG_SET_ORDER);
							
							$attr = array();
							foreach($attr_m as $arr) {
								$attr_access = false;
								if(in_array(strtolower($arr[1]), $this->tags[$seg[$i]['tag_name']])) 
									$attr_access = true;
								else if(strpos(strtolower($arr[1]), 'data-') !== false)
									$attr_access = true;
								else {
									$error[] = [
										'type' => 'attribute',
										'name' => $arr[1],
										'message' => 'Неверный аттрибут',
									];
								}
                                
								if($attr_access) {
							        if($arr[1] == 'class') {
										$classes = explode(' ', $arr[3]);
										$arr[3] = '';
										foreach($classes as $class) {
											if(in_array($class, $this->classes))
												$arr[3] .= $class . ' ';
											else  {
												$error[] = [
													'type' => 'class',
													'name' => $class,
													'message' => 'Неверный класс',
												];
											}
										}
									}
									$attr[strtolower($arr[1])] = htmlentities($arr[3], ENT_QUOTES, 'UTF-8');
								}
							}
							$seg[$i]['attr'] = $attr;
							
							if($seg[$i]['tag_name'] == 'code') $code = TRUE;
							
							if(!count($this->tags[$seg[$i]['tag_name']]) || ($this->tags[$seg[$i]['tag_name']][count($this->tags[$seg[$i]['tag_name']])-1] != FALSE)) array_push($open_tags_stack, $seg[$i]['tag_name']);
						}
					}
					
					
					else {
						
						if(array_key_exists($seg[$i]['tag_name'], $this->tags) && (!count($this->tags[$seg[$i]['tag_name']]) || ($this->tags[$seg[$i]['tag_name']][count($this->tags[$seg[$i]['tag_name']])-1] != FALSE))){
							
							if($seg[$i]['tag_name'] == 'code') $code = FALSE;
							
							if((count($open_tags_stack) == 0) || (!in_array($seg[$i]['tag_name'], $open_tags_stack))) {
								if($this->del_tags) {
									$seg[$i]['action'] = 'del';
									$error[] = [
										'type' => $seg[$i]['seg_type'],
										'name' => $seg[$i]['tag_name'],
										'message' => 'Неверный тег',
									];
								}	
								else {$seg[$i]['seg_type'] = 'text';
									$i--;
									continue;
								}
							}
							
							else {
			
								$tn = array_pop($open_tags_stack);
								if($seg[$i]['tag_name'] != $tn){
									array_splice($seg, $i, 0, array(array('seg_type'=>'tag', 'tag_type'=>'close', 'tag_name'=>$tn, 'action'=>'add')));	
								}	
							}
								
						}

						else {
							if($this->del_tags) {
								$seg[$i]['action'] = 'del';
								$error[] = [
									'type' => $seg[$i]['seg_type'],
									'name' => $seg[$i]['tag_name'],
									'message' => 'Неверный тег',
								];
							}	
							else {$seg[$i]['seg_type'] = 'text';
								$i--;
								continue;
							}
						}
					}
				}
			} 
											   								   
			foreach(array_reverse($open_tags_stack) as $value) {
				array_push($seg, array('seg_type'=>'tag', 'tag_type'=>'close', 'tag_name'=>$value, 'action'=>'add'));
			}
			
			$filtered_HTML = '';
			foreach($seg as $segment) {
				if(($segment['seg_type'] == 'text') && ($segment['action'] == 'add')) $filtered_HTML .= $segment['value'];
				
				elseif(($segment['seg_type'] == 'tag') && ($segment['action'] != 'del')) {
					if($segment['tag_type'] == 'open') {
						$filtered_HTML .= '<'.$segment['tag_name'];
						if(is_array($segment['attr'])){
							foreach($segment['attr'] as $attr_key=>$attr_val){
								$filtered_HTML .= ' '.$attr_key.'="'.$attr_val.'"';	
							}
						}
						if (count($this->tags[$segment['tag_name']]) && ($this->tags[$segment['tag_name']][count($this->tags[$segment['tag_name']])-1] == FALSE)) $filtered_HTML .= " /";
						$filtered_HTML .= '>';
					}
					elseif($segment['tag_type'] == 'close'){
						$filtered_HTML .= '</'.$segment['tag_name'].'>';
					}
				}
			}
			
			if($error == []) {
				return [
					'success' => true,
					'data'	=> $filtered_HTML,
					'error' => '',
				];
			}
			else return [
				'success' => false,
				'data'	=> $filtered_HTML,
				'error' => $error,
			];
		}			
	};